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

$tiposAjuste = flus_stock_tipos_ajuste();

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

function stock_adjust_request_id(): string {
    $requestId = trim((string)($_POST['ajuste_request_id'] ?? ''));
    if ($requestId === '') {
        return '';
    }
    return preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $requestId) ? $requestId : '';
}

function stock_adjust_idempotency_ready(PDO $pdo): bool {
    if (!function_exists('flus_column_exists') || !flus_column_exists($pdo, 'movimientos_stock', 'request_uid')) {
        return false;
    }

    $st = $pdo->query("\n        SELECT INDEX_NAME\n        FROM information_schema.STATISTICS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = 'movimientos_stock'\n          AND NON_UNIQUE = 0\n        GROUP BY INDEX_NAME\n        HAVING COUNT(*) = 1\n           AND SUM(CASE WHEN COLUMN_NAME = 'request_uid' THEN 1 ELSE 0 END) = 1\n        LIMIT 1\n    ");
    return $st ? (bool)$st->fetchColumn() : false;
}

function stock_adjust_find_by_request_uid(PDO $pdo, string $requestId): ?array {
    if ($requestId === '') return null;
    $st = $pdo->prepare("\n        SELECT ms.id, ms.producto_id, ms.cantidad, ms.stock_anterior, ms.stock_nuevo,\n               p.nombre, p.stock_minimo, p.es_pesable, p.activo, p.unidad_venta\n        FROM movimientos_stock ms\n        JOIN productos p ON p.id = ms.producto_id\n        WHERE ms.request_uid = ?\n        LIMIT 1\n    ");
    $st->execute([$requestId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function stock_adjust_existing_response(array $row): array {
    $stockAnterior = (float)($row['stock_anterior'] ?? 0);
    $stockNuevo = (float)($row['stock_nuevo'] ?? $stockAnterior);
    $stockMinimo = (float)($row['stock_minimo'] ?? 0);
    $activo = (bool)($row['activo'] ?? false);
    $esPesable = function_exists('is_pesable_row') ? is_pesable_row($row) : (bool)($row['es_pesable'] ?? false);
    $unidadVenta = strtoupper(trim((string)($row['unidad_venta'] ?? 'UNIDAD')));
    $unidadLabel = function_exists('flus_producto_unidad_descripcion')
        ? flus_producto_unidad_descripcion($unidadVenta, $esPesable)
        : ($esPesable ? 'Pesable' : 'Unidad');

    return [
        'message' => 'Stock actualizado correctamente',
        'idempotent' => true,
        'data' => [
            'producto_id' => (int)($row['producto_id'] ?? 0),
            'producto_nombre' => (string)($row['nombre'] ?? ''),
            'stock_anterior' => format_stock_con_unidad(array_merge($row, ['stock' => $stockAnterior]), 'stock'),
            'stock_nuevo' => format_stock_con_unidad(array_merge($row, ['stock' => $stockNuevo]), 'stock'),
            'stock_nuevo_raw' => $stockNuevo,
            'stock_minimo' => format_stock_con_unidad($row, 'stock_minimo'),
            'stock_minimo_raw' => $stockMinimo,
            'cambio' => format_stock_con_unidad(array_merge($row, ['cambio_abs' => abs((float)($row['cantidad'] ?? 0))]), 'cambio_abs'),
            'estado_nuevo' => calcular_estado_stock($stockNuevo, $stockMinimo, $activo),
            'stock_pct' => round(calcular_stock_pct($stockNuevo, $stockMinimo), 1),
            'es_pesable' => $esPesable,
            'activo' => $activo,
            'unidad_venta' => $unidadVenta,
            'unidad_label' => $unidadLabel,
        ],
    ];
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
            SELECT fecha, tipo, cantidad, comentario, stock_anterior, stock_nuevo
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
                'stock_anterior' => $row['stock_anterior'] !== null ? (string)$row['stock_anterior'] : '',
                'stock_nuevo' => $row['stock_nuevo'] !== null ? (string)$row['stock_nuevo'] : '',
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
    $requestId   = stock_adjust_request_id();

    if ($requestId === '') {
        stock_json_fail('Identificador de solicitud invalido. Recarga la pagina y proba de nuevo.', 422);
    }
    if (!stock_adjust_idempotency_ready($pdo)) {
        stock_json_fail('Falta aplicar la migracion de idempotencia de stock.', 409);
    }
    $existingAdjustment = stock_adjust_find_by_request_uid($pdo, $requestId);
    if ($existingAdjustment !== null) {
        stock_json_ok(stock_adjust_existing_response($existingAdjustment));
    }

    // Validaciones
    if ($producto_id <= 0) {
        throw new Exception('ID de producto invalido');
    }

if (!isset($tiposAjuste[$tipo])) {
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
$tipoConfig = $tiposAjuste[$tipo];
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

    // Insertar movimiento
    $ins = $pdo->prepare("
        INSERT INTO movimientos_stock
          (venta_id, fecha, producto_id, tipo, cantidad, referencia_venta_id, referencia_compra_id, comentario, request_uid)
        VALUES
          (NULL, NOW(), ?, ?, ?, NULL, NULL, ?, ?)
    ");
    $ins->execute([$producto_id, $tipo_mov, $cantidad, $motivo, $requestId]);

    // Actualizar stock despues del movimiento para que el trigger guarde stock_anterior/stock_nuevo correctos.
    $upd = $pdo->prepare("UPDATE productos SET stock = ? WHERE id = ?");
    $upd->execute([$nuevoStock, $producto_id]);

    $pdo->commit();

    // Calcular nuevos valores para respuesta enriquecida
    $estadoNuevo = calcular_estado_stock($nuevoStock, $stockMinimo, $activo);
    $stockPct = calcular_stock_pct($nuevoStock, $stockMinimo);

    // Respuesta enriquecida
    $responsePayload = [
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
    ];
    stock_json_ok($responsePayload);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ((int)($e->errorInfo[1] ?? 0) === 1062 && isset($requestId) && $requestId !== '') {
        $existingAdjustment = stock_adjust_find_by_request_uid($pdo, $requestId);
        if ($existingAdjustment !== null) {
            stock_json_ok(stock_adjust_existing_response($existingAdjustment));
        }
    }
    error_log('stock_ajax error: ' . $e->getMessage());
    stock_json_fail('No se pudo actualizar el stock.', 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    stock_json_fail($e->getMessage(), 400);
}
