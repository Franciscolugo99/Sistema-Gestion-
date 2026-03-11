<?php
// public/factura_ver.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/facturacion_manual_lib.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura']);

$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
  header('Location: index.php');
  exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  flus_abort(400, 'ID de factura invalido');
}

$sql = '
  SELECT
    f.*,
    v.fecha AS venta_fecha,
    v.total AS venta_total,
    c.nombre AS cliente_nombre,
    c.cuit AS cliente_cuit,
    c.cond_iva AS cliente_cond_iva,
    c.direccion AS cliente_direccion
  FROM facturas f
  JOIN ventas v ON v.id = f.venta_id
  LEFT JOIN clientes c ON c.id = f.cliente_id
  WHERE f.id = ?
  LIMIT 1
';
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$factura) {
  http_response_code(404);
  flus_abort(404, 'Factura no encontrada');
}

$sqlItems = '
  SELECT vi.*, p.codigo, p.nombre
  FROM venta_items vi
  JOIN productos p ON p.id = vi.producto_id
  WHERE vi.venta_id = ?
  ORDER BY vi.id ASC
';
$stmtItems = $pdo->prepare($sqlItems);
$stmtItems->execute([(int)$factura['venta_id']]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($items === []) {
  $items = flus_facturacion_manual_items_fetch($pdo, (int)$factura['venta_id']);
}

$configEmpresa = null;
try {
  if (flus_table_exists($pdo, 'config_facturacion')) {
    $orderCfg = flus_column_exists($pdo, 'config_facturacion', 'id') ? ' ORDER BY id DESC' : '';

    if (flus_column_exists($pdo, 'config_facturacion', 'activo')) {
      $stCfg = $pdo->query('SELECT * FROM config_facturacion WHERE activo = 1' . $orderCfg . ' LIMIT 1');
      $configEmpresa = $stCfg ? ($stCfg->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    }

    if ($configEmpresa === null) {
      $stCfg = $pdo->query('SELECT * FROM config_facturacion' . $orderCfg . ' LIMIT 1');
      $configEmpresa = $stCfg ? ($stCfg->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    }
  }
} catch (Throwable $e) {
  $configEmpresa = null;
}

$mapCondIva = [
  'RI' => 'Responsable Inscripto',
  'MT' => 'Monotributo',
  'EX' => 'Exento',
  'CF' => 'Consumidor Final',
];

$empresaNombre = trim((string)($configEmpresa['razon_social'] ?? '')) ?: 'Mi Kiosco Demo';
$empresaCUIT = trim((string)($configEmpresa['cuit'] ?? '')) ?: '20-00000000-0';
$empresaCondRaw = strtoupper(trim((string)($configEmpresa['cond_iva'] ?? 'RI')));
$empresaIVA = $mapCondIva[$empresaCondRaw] ?? 'Responsable Inscripto';
$empresaDireccion = trim((string)($configEmpresa['domicilio'] ?? '')) ?: 'Av. Siempre Viva 742';
$empresaIIBB = 'CM 000-000000-0';
$empresaInicio = '01/01/2020';

$cae = (string)($factura['cae'] ?? '00000000000000');
$caeVtoRaw = (string)($factura['cae_vto'] ?? ($factura['cae_vencimiento'] ?? ''));
if ($caeVtoRaw !== '') {
  if (preg_match('/^\d{8}$/', $caeVtoRaw) === 1) {
    $dtCae = DateTime::createFromFormat('Ymd', $caeVtoRaw);
    $caeVto = $dtCae ? $dtCae->format('d/m/Y') : $caeVtoRaw;
  } else {
    $tsCae = strtotime($caeVtoRaw);
    $caeVto = $tsCae !== false ? date('d/m/Y', $tsCae) : $caeVtoRaw;
  }
} else {
  $caeVto = '00/00/0000';
}
$modoFactura = flus_facturacion_normalizar_modo((string)($factura['modo'] ?? 'demo'));
if ($modoFactura === 'produccion' && flus_facturacion_arca_env_actual() === 'homo' && !str_starts_with($cae, 'DEMO')) {
  $modoFactura = 'homologacion';
}
$modoFacturaLabel = flus_facturacion_modo_label($modoFactura);
$esDemo = $modoFactura === 'demo' || str_starts_with($cae, 'DEMO');
$estadoComprobante = $esDemo
  ? 'Documento generado en modo demo'
  : ($modoFactura === 'homologacion' ? 'Comprobante autorizado en homologacion' : 'Comprobante electronico autorizado');
$footerModo = $esDemo
  ? 'CAE de demostracion'
  : ($modoFactura === 'homologacion' ? 'Datos autorizados por ARCA en homologacion' : 'Datos fiscales informados por AFIP/ARCA');

$clienteNombre = $factura['cliente_nombre'] ?: 'Consumidor Final';
$clienteCuit = $factura['cliente_cuit'] ?: '-';
$clienteCondIva = $factura['cliente_cond_iva'] ?: 'Consumidor Final';
$clienteDireccion = $factura['cliente_direccion'] ?: '-';

$fechaRaw = $factura['creado_en'] ?? $factura['venta_fecha'] ?? '';
$fechaFmt = $fechaRaw ? date('d/m/Y H:i:s', strtotime((string)$fechaRaw)) : '-';

$tipo = (string)($factura['tipo'] ?? '');
$letra = strtoupper(substr($tipo, 0, 1));
if (!in_array($letra, ['A', 'B', 'C', 'M', 'E'], true)) {
  $letra = 'X';
}

$pageTitle = 'Factura ' . h($tipo) . ' ' . sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']);
$currentSection = 'facturacion';
$extraCss = ['assets/css/factura.css?v=2'];
$bodyClass = 'factura-view';

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap factura-page">
  <div class="factura-shell">
    <div class="factura-topbar no-print">
      <a href="facturacion.php" class="link-back-print">Volver a facturacion</a>
      <button class="btn btn-primary btn-print" onclick="window.print()">Imprimir</button>
    </div>

    <header class="factura-header">
      <div class="factura-col empresa">
        <div class="factura-logo">
          <span class="factura-logo-mark">FLUS</span>
        </div>
        <div class="factura-empresa-datos">
          <div class="empresa-nombre"><?= h($empresaNombre) ?></div>
          <div><?= h($empresaDireccion) ?></div>
          <div>CUIT: <?= h($empresaCUIT) ?></div>
          <div>Condicion frente al IVA: <?= h($empresaIVA) ?></div>
          <div>Ing. Brutos: <?= h($empresaIIBB) ?></div>
          <div>Inicio de actividades: <?= h($empresaInicio) ?></div>
        </div>
      </div>

      <div class="factura-col letra">
        <div class="letra-cuadro">
          <div class="letra-tipo"><?= h($letra) ?></div>
        </div>
        <div class="letra-texto">
          FACTURA<br>
          <span class="letra-clase"><?= h($estadoComprobante) ?></span>
        </div>
      </div>

      <div class="factura-col datos">
        <table class="tabla-datos-factura">
          <tr><th>Tipo</th><td><?= h($tipo) ?></td></tr>
          <tr><th>Punto de venta</th><td><?= sprintf('%04d', (int)$factura['punto_venta']) ?></td></tr>
          <tr><th>Numero</th><td><?= sprintf('%08d', (int)$factura['numero']) ?></td></tr>
          <tr><th>Fecha</th><td><?= h($fechaFmt) ?></td></tr>
          <tr><th>Venta</th><td>#<?= (int)$factura['venta_id'] ?></td></tr>
          <tr><th>Estado</th><td><?= h((string)($factura['estado'] ?? 'EMITIDA')) ?></td></tr>
          <tr><th>Modo</th><td><?= h($modoFacturaLabel) ?></td></tr>
        </table>
      </div>
    </header>

    <section class="factura-bloque cliente-section">
      <div class="bloque-titulo">Cliente</div>
      <div class="cliente-grid">
        <div>
          <div class="label">Razon social</div>
          <div class="value"><?= h($clienteNombre) ?></div>
        </div>
        <div>
          <div class="label">CUIT</div>
          <div class="value"><?= h($clienteCuit) ?></div>
        </div>
        <div>
          <div class="label">Condicion IVA</div>
          <div class="value"><?= h($clienteCondIva) ?></div>
        </div>
        <div class="cliente-full">
          <div class="label">Domicilio</div>
          <div class="value"><?= h($clienteDireccion) ?></div>
        </div>
      </div>
    </section>

    <section class="factura-bloque importes-section">
      <div class="bloque-titulo">Importes</div>
      <div class="importes-grid">
        <div>
          <div class="label">Total factura</div>
          <div class="value value-strong"><?= money($factura['total'] ?? 0) ?></div>
        </div>
        <div>
          <div class="label">Total venta</div>
          <div class="value"><?= money($factura['venta_total'] ?? 0) ?></div>
        </div>
      </div>
    </section>

    <section class="factura-bloque detalle-section">
      <div class="bloque-titulo">Detalle</div>

      <table class="tabla-detalle">
        <thead>
          <tr>
            <th style="width:14%;">Codigo</th>
            <th>Descripcion</th>
            <th style="width:10%;">Cant.</th>
            <th style="width:14%;">Precio</th>
            <th style="width:14%;">Subtotal</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($items): ?>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= h((string)($it['codigo'] ?? '')) ?></td>
              <td><?= h((string)($it['nombre'] ?? '')) ?></td>
              <td class="num"><?= h(format_qty($it['cantidad'] ?? 0)) ?></td>
              <td class="num"><?= money($it['precio'] ?? 0) ?></td>
              <td class="num"><?= money($it['subtotal'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="5" class="empty-cell">No hay items asociados a esta venta.</td>
          </tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="4" class="num">Total</th>
            <th class="num"><?= money($factura['total'] ?? 0) ?></th>
          </tr>
        </tfoot>
      </table>
    </section>

    <footer class="factura-footer">
      <div class="footer-left">
        <div class="footer-text">
          Comprobante generado desde <strong>FLUS</strong> - Sistema de gestion.
        </div>
      </div>
      <div class="footer-right">
        <div class="footer-row"><span>CAE:</span> <strong><?= h($cae) ?></strong></div>
        <div class="footer-row"><span>Vto. CAE:</span> <strong><?= h($caeVto) ?></strong></div>
        <div class="footer-note"><?= h($footerModo) ?></div>
      </div>
    </footer>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>