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

$clienteResult = flus_facturacion_resolver_cliente_desde_input($pdo, $_POST, [
    'mensaje_vacio' => 'Tienes que seleccionar un cliente, Consumidor Final o consultar un CUIT/CUIL.',
    'mensaje_invalido' => 'Cliente invalido o no disponible.',
    'mensaje_lookup_confirmacion' => 'Confirma que quieres emitir con los datos consultados en ARCA. Si no los vas a usar, descartalos y sigue con el cliente seleccionado.',
]);
$errores = array_values(array_filter(array_map('strval', (array)($clienteResult['errors'] ?? []))));
if ($errores !== []) {
    header('Location: factura_nueva.php?venta_id=' . $ventaId . '&fact_error=' . urlencode($errores[0]));
    exit;
}

try {
    $config = flus_facturacion_config_activa($pdo);
    flus_facturacion_assert_preflight_emision($pdo, $config);

    $opciones = [];
    if (is_array($clienteResult['resolved_cliente'] ?? null)) {
        $opciones['resolved_cliente'] = $clienteResult['resolved_cliente'];
    }

    crearFacturaDesdeVenta($ventaId, (int)($clienteResult['cliente_id'] ?? 0), $opciones);
    header('Location: venta_detalle.php?id=' . $ventaId . '&fact_ok=1');
    exit;
} catch (Throwable $e) {
    error_log('factura_emitir: ' . $e->getMessage());
    $facturaExistenteId = flus_facturacion_factura_existente_id($pdo, $ventaId);
    if ($facturaExistenteId !== null) {
        $stFactura = $pdo->prepare('SELECT id, cae, estado_fiscal FROM facturas WHERE id = ? LIMIT 1');
        $stFactura->execute([$facturaExistenteId]);
        $facturaExistente = $stFactura->fetch(PDO::FETCH_ASSOC) ?: null;

        if (is_array($facturaExistente) && flus_facturacion_factura_emitida_ok($facturaExistente)) {
            header('Location: venta_detalle.php?id=' . $ventaId . '&fact_ok=1');
            exit;
        }

        header('Location: factura_ver.php?id=' . $facturaExistenteId);
        exit;
    }

    $errorUsuario = flus_facturacion_mensaje_operativo_seguro($e->getMessage(), 'No se pudo emitir la factura. Revisa los datos e intenta nuevamente.');
    header('Location: factura_nueva.php?venta_id=' . $ventaId . '&fact_error=' . urlencode($errorUsuario));
    exit;
}
