<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
if (!function_exists('insert_dynamic')) { @require_once FLUS_ROOT . '/src/api_helpers.php'; }
if (!function_exists('getPDO')) { require_once __DIR__ . '/../../../src/db_helpers.php'; }
require_once FLUS_ROOT . '/src/venta_anulaciones_lib.php';
require_once FLUS_ROOT . '/src/cloud_sync_lib.php';

$pdo = $pdo ?? (function_exists('getPDO') ? getPDO() : null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'PDO no disponible']);
    exit;
}
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

$__ok = function (array $payload = []): void {
    if (function_exists('json_ok')) {
        json_ok($payload);
        return;
    }

    echo json_encode(['ok' => true] + $payload);
    exit;
};

$__fail = function (string $msg, int $code = 400, array $extra = []): void {
    if (function_exists('json_fail')) {
        json_fail($msg, $code, $extra);
        return;
    }

    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    exit;
};

$raw = file_get_contents('php://input') ?: '';
$json = [];
if ($raw !== '') {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) {
        $json = $tmp;
    }
}

$in = array_merge($_GET, $_POST, $json);
$ventaId = (int)($in['venta_id'] ?? $in['id'] ?? 0);
$motivo = trim((string)($in['motivo'] ?? ''));
$itemsIn = $in['items'] ?? [];

if (function_exists('user_has_permission') && !user_has_permission('anular_items_venta')) {
    $__fail('Sin permiso', 403);
}

if ($ventaId <= 0) {
    $__fail('ID de venta inválido', 400);
}
if (!is_array($itemsIn) || $itemsIn === []) {
    $__fail('Se requiere al menos un ítem para anular', 422);
}
if (!flus_venta_anulaciones_habilitadas($pdo)) {
    $__fail('Falta aplicar la migración de anulaciones parciales', 503);
}

$userId = null;
if (function_exists('current_user')) {
    $u = current_user();
    if (is_array($u) && isset($u['id'])) {
        $userId = (int)$u['id'] ?: null;
    }
}
if ($userId === null && isset($_SESSION['user']['id'])) {
    $userId = (int)$_SESSION['user']['id'] ?: null;
}

$itemsRequest = [];
foreach ($itemsIn as $it) {
    if (!is_array($it)) {
        continue;
    }

    $itemId = (int)($it['item_id'] ?? $it['id'] ?? 0);
    $cantidad = round((float)($it['cantidad'] ?? 0), 3);
    if ($itemId > 0 && $cantidad > 0) {
        $itemsRequest[$itemId] = $cantidad;
    }
}

if ($itemsRequest === []) {
    $__fail('No se recibieron ítems válidos', 422);
}

try {
    $pdo->beginTransaction();

    $stVenta = $pdo->prepare('SELECT * FROM ventas WHERE id = ? FOR UPDATE');
    $stVenta->execute([$ventaId]);
    $venta = $stVenta->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        $pdo->rollBack();
        $__fail('Venta no encontrada', 404);
    }

    if (function_exists('flus_sale_is_annulled') && flus_sale_is_annulled($venta)) {
        $pdo->rollBack();
        $__fail('La venta ya está anulada', 409);
    }

    if ((int)($venta['facturada'] ?? 0) === 1) {
        $pdo->rollBack();
        $__fail('La venta está facturada. La anulación parcial fiscal se implementará con Nota de Crédito.', 422);
    }

    $ventaItems = flus_venta_items_cargar($pdo, $ventaId);
    if ($ventaItems === []) {
        $pdo->rollBack();
        $__fail('La venta no tiene ítems registrados', 422);
    }

    $yaAnulado = flus_venta_items_anulados_map($pdo, $ventaId);
    $restantes = flus_venta_items_restantes($ventaItems, $yaAnulado);

    $itemsValidados = [];
    $montoAnulado = 0.0;
    $errores = [];

    foreach ($itemsRequest as $itemId => $cantidadSolicitada) {
        if (!isset($ventaItems[$itemId])) {
            $errores[] = "Ítem #{$itemId} no pertenece a la venta";
            continue;
        }

        $item = $ventaItems[$itemId];
        $cantidadDisponible = round((float)($restantes[$itemId]['cantidad_restante'] ?? 0), 3);

        if ($cantidadDisponible <= 0) {
            $errores[] = "Ítem #{$itemId} ya no tiene cantidad disponible";
            continue;
        }

        if ($cantidadSolicitada > $cantidadDisponible + 0.0009) {
            $errores[] = "Ítem #{$itemId}: solicitado {$cantidadSolicitada}, disponible {$cantidadDisponible}";
            continue;
        }

        $cantidadOriginal = (float)($item['cantidad'] ?? 0);
        $precioUnitario = (float)($item['precio_unit_final'] ?? $item['precio'] ?? 0);
        if ($precioUnitario <= 0 && $cantidadOriginal > 0) {
            $precioUnitario = round((float)($item['subtotal'] ?? 0) / $cantidadOriginal, 2);
        }

        $subtotalSnapshot = round((float)($item['subtotal'] ?? 0), 2);
        $subtotalAnulado = round($precioUnitario * $cantidadSolicitada, 2);
        $montoAnulado += $subtotalAnulado;

        $itemsValidados[$itemId] = [
            'item' => $item,
            'cantidad' => $cantidadSolicitada,
            'precio_unitario_snapshot' => $precioUnitario,
            'descuento_monto_snapshot' => round((float)($item['descuento_monto'] ?? 0), 2),
            'iva_porcentaje_snapshot' => 0.0,
            'subtotal_snapshot' => $subtotalSnapshot,
            'subtotal_anulado' => $subtotalAnulado,
        ];
    }

    if ($errores !== []) {
        $pdo->rollBack();
        $__fail(implode('; ', $errores), 422);
    }

    $todoAnulado = true;
    foreach ($restantes as $itemId => $row) {
        $cantidadRestante = round((float)$row['cantidad_restante'] - (float)($itemsValidados[$itemId]['cantidad'] ?? 0), 3);
        if ($cantidadRestante > 0.0009) {
            $todoAnulado = false;
            break;
        }
    }

    $tipoAnulacion = $todoAnulado ? 'TOTAL' : 'PARCIAL';
    $estadoVenta = $todoAnulado ? 'ANULADA' : 'PARCIALMENTE_ANULADA';
    $montoAnulado = round($montoAnulado, 2);

    $anulacionId = insert_dynamic($pdo, 'venta_anulaciones', [
        'venta_id' => $ventaId,
        'tipo' => $tipoAnulacion,
        'estado' => 'CONFIRMADA',
        'motivo' => $motivo !== '' ? mb_substr($motivo, 0, 255) : null,
        'monto_bruto' => $montoAnulado,
        'monto_neto' => $montoAnulado,
        'monto_iva' => 0,
        'monto_total' => $montoAnulado,
        'anulado_por' => $userId,
        'anulado_en' => date('Y-m-d H:i:s'),
    ]);

    foreach ($itemsValidados as $itemId => $row) {
        $item = $row['item'];
        insert_dynamic($pdo, 'venta_anulacion_items', [
            'anulacion_id' => $anulacionId,
            'venta_item_id' => $itemId,
            'producto_id' => (int)($item['producto_id'] ?? 0),
            'cantidad_anulada' => $row['cantidad'],
            'precio_unitario_snapshot' => $row['precio_unitario_snapshot'],
            'descuento_monto_snapshot' => $row['descuento_monto_snapshot'],
            'iva_porcentaje_snapshot' => $row['iva_porcentaje_snapshot'],
            'subtotal_snapshot' => $row['subtotal_snapshot'],
            'subtotal_anulado' => $row['subtotal_anulado'],
        ]);
    }

    $comentario = $tipoAnulacion === 'TOTAL'
        ? "Anulación total por ítems venta #{$ventaId}"
        : "Anulación parcial venta #{$ventaId}";
    if ($motivo !== '') {
        $comentario .= ': ' . mb_substr($motivo, 0, 180);
    }

    flus_venta_stock_reponer_items($pdo, $itemsValidados, $ventaId, $userId, $comentario);

    $ccReversa = null;
    $ccTotalOriginal = flus_venta_cc_total_original($pdo, $ventaId);
    $ventaTotal = round((float)($venta['total'] ?? 0), 2);
    if ($ccTotalOriginal > 0 && $ventaTotal > 0 && $montoAnulado > 0) {
        $montoCCReversa = round($ccTotalOriginal * ($montoAnulado / $ventaTotal), 2);
        if ($montoCCReversa > 0) {
            $ccReversa = flus_venta_cc_revertir_monto($pdo, $venta, $ventaId, $montoCCReversa, $userId, $comentario);
        }
    }

    $sets = ['estado = :estado'];
    $params = [
        ':estado' => $estadoVenta,
        ':id' => $ventaId,
    ];

    if ($estadoVenta === 'ANULADA') {
        $sets[] = 'anulado_en = NOW()';
        if ($userId !== null && flus_column_exists($pdo, 'ventas', 'anulado_por')) {
            $sets[] = 'anulado_por = :anulado_por';
            $params[':anulado_por'] = $userId;
        }
        if ($motivo !== '' && flus_column_exists($pdo, 'ventas', 'anulado_motivo')) {
            $sets[] = 'anulado_motivo = :anulado_motivo';
            $params[':anulado_motivo'] = mb_substr($motivo, 0, 255);
        }
    }

    $pdo->prepare('UPDATE ventas SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

    $pdo->commit();

    flus_cloud_sync_enqueue_sale_annulment($pdo, [
        'venta_id' => $ventaId,
        'anulacion_id' => $anulacionId,
        'tipo' => $tipoAnulacion,
        'estado_nuevo' => $estadoVenta,
        'monto_anulado' => $montoAnulado,
        'user_id' => (int)($userId ?? 0),
        'cajero_nombre' => flus_cloud_sync_user_name($pdo, (int)($userId ?? 0)),
        'motivo' => $motivo,
        'items' => array_map(static function (array $row): array {
            $item = $row['item'] ?? [];
            return [
                'producto_id' => (int)($item['producto_id'] ?? 0),
                'cantidad' => (float)($row['cantidad'] ?? 0),
                'subtotal' => round((float)($row['subtotal_anulado'] ?? 0), 2),
            ];
        }, array_values($itemsValidados)),
    ]);

    $payload = [
        'venta_id' => $ventaId,
        'anulacion_id' => $anulacionId,
        'tipo' => $tipoAnulacion,
        'estado_nuevo' => $estadoVenta,
        'monto_anulado' => $montoAnulado,
        'items_anulados' => count($itemsValidados),
    ];
    if ($ccReversa !== null) {
        $payload['cc_reversa'] = $ccReversa;
    }

    $__ok($payload);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('anular_items_venta: ' . $e->getMessage());
    $__fail('No se pudo anular items de la venta.', 500, ['error_code' => 'DB_ERROR']);
}
