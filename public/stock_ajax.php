<?php
// public/stock_ajax.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$productosHelpers = FLUS_ROOT . '/src/productos_helpers.php';
if (is_file($productosHelpers)) {
    require_once $productosHelpers;
}
require_login();
require_permission('editar_stock');

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();

/* ============================
   CONSTANTES DE TIPOS DE AJUSTE
============================ */
const TIPOS_AJUSTE = [
    'entrada'    => ['label' => 'Entrada',     'mov' => 'AJUSTE_POSITIVO', 'signo' => +1],
    'salida'     => ['label' => 'Salida',      'mov' => 'AJUSTE_NEGATIVO', 'signo' => -1, 'motivo_default' => 'Salida manual'],
    'ajuste_pos' => ['label' => 'Ajuste (+)',  'mov' => 'AJUSTE_POSITIVO', 'signo' => +1],
    'ajuste_neg' => ['label' => 'Ajuste (-)',  'mov' => 'AJUSTE_NEGATIVO', 'signo' => -1],
    'perdida'    => ['label' => 'Perdida',     'mov' => 'AJUSTE_NEGATIVO', 'signo' => -1, 'motivo_default' => 'Perdida/Rotura/Vencimiento'],
];

const MOTIVO_MAX_LENGTH = 255;

/* ============================
   FUNCIONES HELPER (locales - usan 'success' en vez de 'ok')
============================ */
function stock_json_ok(array $data = []): void {
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function stock_json_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function stock_normalize_text(?string $value): string {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    if (stripos($text, 'Rotura/Vencimiento') !== false) {
        return 'Perdida/Rotura/Vencimiento';
    }

    if (stripos($text, 'Perd') !== false && stripos($text, 'rdida') !== false) {
        return 'Perdida';
    }

    return $text;
}

/**
 * Calcula el estado del stock
 */
function calcular_estado_stock(float $stock, float $stock_minimo, bool $activo): string {
    if (!$activo) return 'inactivo';
    if ($stock <= 0) return 'sin';
    if ($stock <= $stock_minimo) return 'bajo';
    return 'ok';
}

/**
 * Calcula el porcentaje de la barra de stock
 */
function calcular_stock_pct(float $stock, float $stock_minimo): float {
    if ($stock_minimo <= 0) {
        return $stock > 0 ? 100 : 0;
    }
    return min(100, ($stock / $stock_minimo) * 100);
}

/* ============================
   MAIN
============================ */
try {
    // Solo POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        stock_json_fail('Metodo no permitido', 405);
    }

    // Verificar CSRF
    if (!function_exists('csrf_verify') || !csrf_verify($_POST['csrf_token'] ?? null)) {
        stock_json_fail('CSRF invalido. Recarga la pagina y proba de nuevo.', 403);
    }

    $action = trim((string)($_POST['action'] ?? ''));
    
    if (!in_array($action, ['ajustar', 'historial'], true)) {
        stock_json_fail('Accion no valida', 400);
    }

    // Parsear datos
    $producto_id = (int)($_POST['producto_id'] ?? 0);

    if ($action === 'historial') {
        if ($producto_id <= 0) {
            throw new Exception('ID de producto invalido');
        }

        $stmtHist = $pdo->prepare("
            SELECT fecha, tipo, cantidad, comentario
            FROM movimientos_stock
            WHERE producto_id = ?
            ORDER BY fecha DESC, id DESC
            LIMIT 5
        " );
        $stmtHist->execute([$producto_id]);
        $rows = $stmtHist->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'fecha' => (string)($row['fecha'] ?? ''),
                'tipo' => (string)($row['tipo'] ?? ''),
                'cantidad' => (string)($row['cantidad'] ?? ''),
                'comentario' => stock_normalize_text((string)($row['comentario'] ?? '')), 
            ];
        }

        stock_json_ok([
            'data' => [
                'items' => $items,
            ],
        ]);
    }
    $tipo        = trim((string)($_POST['tipo'] ?? ''));
    $cantidad    = (float)($_POST['cantidad'] ?? 0);
    $motivo      = trim((string)($_POST['motivo'] ?? ''));

    // Validaciones
    if ($producto_id <= 0) {
        throw new Exception('ID de producto invalido');
    }

    if (!isset(TIPOS_AJUSTE[$tipo])) {
        throw new Exception('Tipo de ajuste invalido');
    }

    if ($cantidad <= 0) {
        throw new Exception('La cantidad debe ser mayor a 0');
    }

    // Validar longitud de motivo
    if (mb_strlen($motivo) > MOTIVO_MAX_LENGTH) {
        throw new Exception('El motivo no puede superar los ' . MOTIVO_MAX_LENGTH . ' caracteres');
    }

    // Obtener configuracion del tipo
    $tipoConfig = TIPOS_AJUSTE[$tipo];
    $tipo_mov = $tipoConfig['mov'];
    $cambio = $tipoConfig['signo'] * $cantidad;

    // Motivo por defecto si aplica
    if ($motivo === '' && isset($tipoConfig['motivo_default'])) {
        $motivo = $tipoConfig['motivo_default'];
    }

    // Iniciar transaccion
    $pdo->beginTransaction();

    // Lock producto para evitar race conditions
    $stmt = $pdo->prepare("
        SELECT id, nombre, stock, stock_minimo, es_pesable, activo, unidad_venta 
        FROM productos 
        WHERE id = ? 
        FOR UPDATE
    ");
    $stmt->execute([$producto_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$p) {
        throw new Exception('Producto no encontrado');
    }

    $stockActual = (float)$p['stock'];
    $stockMinimo = (float)$p['stock_minimo'];
    $activo = (bool)$p['activo'];
    $esPesable = function_exists('is_pesable_row') ? is_pesable_row($p) : (bool)($p['es_pesable'] ?? false);
    $unidadVenta = strtoupper(trim((string)($p['unidad_venta'] ?? 'UNIDAD')));
    $unidadLabel = function_exists('flus_producto_unidad_descripcion')
        ? flus_producto_unidad_descripcion($unidadVenta, $esPesable)
        : ($esPesable ? 'Pesable' : 'Unidad');

    // Validar enteros para no pesables y para productos por 100 g / 100 ml.
    if ((!$esPesable || in_array($unidadVenta, ['G', 'ML'], true)) && !is_int($cantidad) && floor($cantidad) != $cantidad) {
        throw new Exception($esPesable
            ? 'Para productos por 100 g o 100 ml, la cantidad debe ser un numero entero'
            : 'Para productos por unidad, la cantidad debe ser un numero entero');
    }

    // Calcular nuevo stock
    $nuevoStock = $stockActual + $cambio;
    
    if ($nuevoStock < 0) {
        throw new Exception('El stock no puede quedar negativo. Stock actual: ' . format_stock_con_unidad($p, 'stock'));
    }

    // Actualizar stock
    $upd = $pdo->prepare("UPDATE productos SET stock = ? WHERE id = ?");
    $upd->execute([$nuevoStock, $producto_id]);

    // Insertar movimiento
    $ins = $pdo->prepare("
        INSERT INTO movimientos_stock
          (venta_id, fecha, producto_id, tipo, cantidad, referencia_venta_id, referencia_compra_id, comentario)
        VALUES
          (NULL, NOW(), ?, ?, ?, NULL, NULL, ?)
    ");
    $ins->execute([$producto_id, $tipo_mov, $cantidad, $motivo]);

    $pdo->commit();

    // Calcular nuevos valores para respuesta enriquecida
    $estadoNuevo = calcular_estado_stock($nuevoStock, $stockMinimo, $activo);
    $stockPct = calcular_stock_pct($nuevoStock, $stockMinimo);

    // Respuesta enriquecida
    stock_json_ok([
        'message' => 'Stock actualizado correctamente',
        'data' => [
            'producto_id'     => $producto_id,
            'producto_nombre' => (string)$p['nombre'],
            'stock_anterior'  => format_stock_con_unidad($p, 'stock'),
            'stock_nuevo'     => format_stock_con_unidad(array_merge($p, ['stock' => $nuevoStock]), 'stock'),
            'stock_nuevo_raw' => $nuevoStock,
            'stock_minimo'    => format_stock_con_unidad($p, 'stock_minimo'),
            'stock_minimo_raw'=> $stockMinimo,
            'cambio'          => format_stock_con_unidad(array_merge($p, ['cambio_abs' => abs($cambio)]), 'cambio_abs'),
            'estado_nuevo'    => $estadoNuevo,
            'stock_pct'       => round($stockPct, 1),
            'es_pesable'      => $esPesable,
            'activo'          => $activo,
            'unidad_venta'    => $unidadVenta,
            'unidad_label'    => $unidadLabel,
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    stock_json_fail($e->getMessage(), 400);
}
