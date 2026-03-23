<?php
// public/facturacion_nc_emitir.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/Fiscal/bootstrap.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: facturacion_nc.php');
    exit;
}

$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}

$facturaId = max(0, (int)($_POST['factura_id'] ?? 0));
$ventaId = max(0, (int)($_POST['venta_id'] ?? 0));
$modoOperacion = strtoupper(trim((string)($_POST['modo_operacion'] ?? '')));
$motivo = trim((string)($_POST['motivo'] ?? ''));
$user = current_user();
$usuarioId = (int)($user['id'] ?? 0);

$redirectBase = 'facturacion_nc.php' . ($facturaId > 0 ? ('?factura_id=' . $facturaId) : '');
$redirectWith = static function (string $key, string $message) use ($facturaId): string {
    $query = ['factura_id' => $facturaId, $key => $message];
    return 'facturacion_nc.php?' . http_build_query($query);
};

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    header('Location: ' . $redirectWith('nc_error', 'Sesión vencida (CSRF). Recarga la página e intenta nuevamente.'));
    exit;
}

if ($facturaId <= 0 || $ventaId <= 0) {
    header('Location: ' . $redirectWith('nc_error', 'La factura seleccionada no tiene una venta vinculada válida para gestionar NC desde FLUS.'));
    exit;
}

try {
    if (!flus_table_exists($pdo, 'facturas')) {
        throw new RuntimeException('La tabla facturas no existe.');
    }

    $st = $pdo->prepare('SELECT * FROM facturas WHERE id = ? LIMIT 1');
    $st->execute([$facturaId]);
    $factura = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$factura) {
        throw new RuntimeException('Factura origen no encontrada.');
    }

    $naturaleza = strtoupper(trim((string)($factura['naturaleza'] ?? 'FACTURA')));
    if ($naturaleza !== 'FACTURA') {
        throw new RuntimeException('Solo se pueden gestionar NC sobre facturas origen.');
    }

    $configFact = flus_facturacion_config_activa($pdo);
    $modo = flus_facturacion_modo_actual($configFact ?? []);

    $repo = new PdoFacturaFiscalRepository($pdo);
    $notaSvc = new ArcaNotaCreditoService($pdo, $repo);
    $coordinator = new DbAnulacionFiscalCoordinator($pdo, $repo, $notaSvc);

    if ($modoOperacion === 'TOTAL') {
        $out = $coordinator->procesarTotal($ventaId, $usuarioId, $motivo, ['modo' => $modo]);
        header('Location: ' . $redirectWith('nc_ok', $out->message ?? 'NC total emitida correctamente.'));
        exit;
    }

    if ($modoOperacion === 'PARTIAL') {
        $itemsRaw = (array)($_POST['items'] ?? []);
        $items = [];
        foreach ($itemsRaw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemId = (int)($row['item_id'] ?? 0);
            $cantidad = round((float)($row['cantidad'] ?? 0), 3);
            if ($itemId <= 0 || $cantidad <= 0) {
                continue;
            }
            $items[] = [
                'item_id' => $itemId,
                'cantidad' => $cantidad,
            ];
        }

        if ($items === []) {
            throw new RuntimeException('Debes indicar al menos un item con cantidad mayor a cero para emitir la NC parcial.');
        }

        $out = $coordinator->procesarParcial($ventaId, $items, $usuarioId, $motivo, ['modo' => $modo]);
        header('Location: ' . $redirectWith('nc_ok', $out->message ?? 'NC parcial emitida correctamente.'));
        exit;
    }

    throw new RuntimeException('Modo de operación inválido.');
} catch (Throwable $e) {
    header('Location: ' . $redirectWith('nc_error', $e->getMessage()));
    exit;
}
