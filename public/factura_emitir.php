<?php
// public/factura_emitir.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_permission('emitir_factura');

$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}

function factura_emitir_buscar_existente(PDO $pdo, int $ventaId): ?int
{
    if (!flus_table_exists($pdo, 'facturas') || !flus_column_exists($pdo, 'facturas', 'venta_id')) {
        return null;
    }

    $st = $pdo->prepare('SELECT id FROM facturas WHERE venta_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$ventaId]);
    $facturaId = $st->fetchColumn();

    return $facturaId !== false ? (int)$facturaId : null;
}

function factura_emitir_cliente_valido(PDO $pdo, int $clienteId): bool
{
    if (!flus_table_exists($pdo, 'clientes')) {
        return false;
    }

    $sql = 'SELECT id FROM clientes WHERE id = ?';
    if (flus_column_exists($pdo, 'clientes', 'activo')) {
        $sql .= ' AND activo = 1';
    }
    $sql .= ' LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$clienteId]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
}

$ventaId = isset($_GET['venta_id']) ? (int)$_GET['venta_id'] : 0;
if ($ventaId <= 0) {
    header('Location: ventas.php');
    exit;
}

$clienteIdRaw = isset($_GET['cliente_id']) ? trim((string)$_GET['cliente_id']) : '';
if ($clienteIdRaw === '') {
    header('Location: factura_nueva.php?venta_id=' . $ventaId);
    exit;
}

$clienteId = ctype_digit($clienteIdRaw) ? (int)$clienteIdRaw : -1;
if ($clienteId < 0) {
    header('Location: venta_detalle.php?id=' . $ventaId . '&fact_error=' . urlencode('Cliente invalido.'));
    exit;
}

try {
    if (!flus_table_exists($pdo, 'ventas')) {
        throw new RuntimeException('La tabla ventas no existe.');
    }

    $stVenta = $pdo->prepare('SELECT id FROM ventas WHERE id = ? LIMIT 1');
    $stVenta->execute([$ventaId]);
    if (!$stVenta->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Venta inexistente.');
    }

    $facturaExistenteId = factura_emitir_buscar_existente($pdo, $ventaId);
    if ($facturaExistenteId !== null) {
        header('Location: venta_detalle.php?id=' . $ventaId . '&fact_ok=1');
        exit;
    }

    if ($clienteId > 0 && !factura_emitir_cliente_valido($pdo, $clienteId)) {
        throw new RuntimeException('Cliente invalido o no disponible.');
    }

    crearFacturaDesdeVenta($ventaId, $clienteId);
    header('Location: venta_detalle.php?id=' . $ventaId . '&fact_ok=1');
    exit;
} catch (Throwable $e) {
    $facturaExistenteId = factura_emitir_buscar_existente($pdo, $ventaId);
    if ($facturaExistenteId !== null) {
        header('Location: venta_detalle.php?id=' . $ventaId . '&fact_ok=1');
        exit;
    }

    header('Location: venta_detalle.php?id=' . $ventaId . '&fact_error=' . urlencode($e->getMessage()));
    exit;
}