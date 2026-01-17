<?php
// public/stock_ajax.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
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
    'ajuste_neg' => ['label' => 'Ajuste (−)',  'mov' => 'AJUSTE_NEGATIVO', 'signo' => -1],
    'perdida'    => ['label' => 'Pérdida',     'mov' => 'AJUSTE_NEGATIVO', 'signo' => -1, 'motivo_default' => 'Pérdida/Rotura/Vencimiento'],
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
        stock_json_fail('Método no permitido', 405);
    }

    // Verificar CSRF
    if (!function_exists('csrf_verify') || !csrf_verify($_POST['csrf_token'] ?? null)) {
        stock_json_fail('CSRF inválido. Recargá la página y probá de nuevo.', 403);
    }

    $action = trim((string)($_POST['action'] ?? ''));
    
    if ($action !== 'ajustar') {
        stock_json_fail('Acción no válida', 400);
    }

    // Parsear datos
    $producto_id = (int)($_POST['producto_id'] ?? 0);
    $tipo        = trim((string)($_POST['tipo'] ?? ''));
    $cantidad    = (float)($_POST['cantidad'] ?? 0);
    $motivo      = trim((string)($_POST['motivo'] ?? ''));

    // Validaciones
    if ($producto_id <= 0) {
        throw new Exception('ID de producto inválido');
    }

    if (!isset(TIPOS_AJUSTE[$tipo])) {
        throw new Exception('Tipo de ajuste inválido');
    }

    if ($cantidad <= 0) {
        throw new Exception('La cantidad debe ser mayor a 0');
    }

    // Validar longitud de motivo
    if (mb_strlen($motivo) > MOTIVO_MAX_LENGTH) {
        throw new Exception('El motivo no puede superar los ' . MOTIVO_MAX_LENGTH . ' caracteres');
    }

    // Obtener configuración del tipo
    $tipoConfig = TIPOS_AJUSTE[$tipo];
    $tipo_mov = $tipoConfig['mov'];
    $cambio = $tipoConfig['signo'] * $cantidad;

    // Motivo por defecto si aplica
    if ($motivo === '' && isset($tipoConfig['motivo_default'])) {
        $motivo = $tipoConfig['motivo_default'];
    }

    // Iniciar transacción
    $pdo->beginTransaction();

    // Lock producto para evitar race conditions
    $stmt = $pdo->prepare("
        SELECT id, nombre, stock, stock_minimo, es_pesable, activo 
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

    // Validar enteros para no pesables
    if (!$esPesable && !is_int($cantidad) && floor($cantidad) != $cantidad) {
        throw new Exception('Para productos por unidad, la cantidad debe ser un número entero');
    }

    // Calcular nuevo stock
    $nuevoStock = $stockActual + $cambio;
    
    if ($nuevoStock < 0) {
        throw new Exception('El stock no puede quedar negativo. Stock actual: ' . 
            (function_exists('format_qty') ? format_qty($stockActual, $esPesable) : $stockActual));
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
            'stock_anterior'  => function_exists('format_qty') ? format_qty($stockActual, $esPesable) : number_format($stockActual, $esPesable ? 3 : 0),
            'stock_nuevo'     => function_exists('format_qty') ? format_qty($nuevoStock, $esPesable) : number_format($nuevoStock, $esPesable ? 3 : 0),
            'stock_nuevo_raw' => $nuevoStock,
            'stock_minimo'    => function_exists('format_qty') ? format_qty($stockMinimo, $esPesable) : number_format($stockMinimo, $esPesable ? 3 : 0),
            'stock_minimo_raw'=> $stockMinimo,
            'cambio'          => function_exists('format_qty') ? format_qty($cambio, $esPesable) : number_format($cambio, $esPesable ? 3 : 0),
            'estado_nuevo'    => $estadoNuevo,
            'stock_pct'       => round($stockPct, 1),
            'es_pesable'      => $esPesable,
            'activo'          => $activo,
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    stock_json_fail($e->getMessage(), 400);
}