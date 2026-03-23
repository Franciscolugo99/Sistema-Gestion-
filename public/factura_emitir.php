<?php
// public/factura_emitir.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_permission('emitir_factura');

$facturacionHabilitada = flus_facturacion_habilitada($pdo);
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $ventaId = isset($_GET['venta_id']) ? (int)$_GET['venta_id'] : 0;
    header('Location: ' . ($ventaId > 0 ? 'factura_nueva.php?venta_id=' . $ventaId : 'ventas.php'));
    exit;
}

$ventaId = isset($_POST['venta_id']) ? (int)$_POST['venta_id'] : 0;
if ($ventaId <= 0) {
    header('Location: ventas.php');
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    header('Location: factura_nueva.php?venta_id=' . $ventaId . '&fact_error=' . urlencode('Sesion vencida (CSRF). Actualiza la pagina e intenta de nuevo.'));
    exit;
}

$clienteIdRaw = isset($_POST['cliente_id']) ? trim((string)$_POST['cliente_id']) : '';
if ($clienteIdRaw === '') {
    header('Location: factura_nueva.php?venta_id=' . $ventaId);
    exit;
}

$clienteId = ctype_digit($clienteIdRaw) ? (int)$clienteIdRaw : -1;
if ($clienteId < 0) {
    header('Location: factura_nueva.php?venta_id=' . $ventaId . '&fact_error=' . urlencode('Cliente invalido.'));
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

    $facturaExistenteId = flus_facturacion_factura_existente_id($pdo, $ventaId);
    if ($facturaExistenteId !== null) {
        header('Location: venta_detalle.php?id=' . $ventaId . '&fact_ok=1');
        exit;
    }

    if ($clienteId > 0 && !flus_facturacion_cliente_activo($pdo, $clienteId)) {
        throw new RuntimeException('Cliente invalido o no disponible.');
    }

    crearFacturaDesdeVenta($ventaId, $clienteId);
    header('Location: venta_detalle.php?id=' . $ventaId . '&fact_ok=1');
    exit;
} catch (Throwable $e) {
    $facturaExistenteId = flus_facturacion_factura_existente_id($pdo, $ventaId);
    if ($facturaExistenteId !== null) {
        header('Location: venta_detalle.php?id=' . $ventaId . '&fact_ok=1');
        exit;
    }

    header('Location: factura_nueva.php?venta_id=' . $ventaId . '&fact_error=' . urlencode($e->getMessage()));
    exit;
}
