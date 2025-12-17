<?php
// public/factura_ver.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

/* =========================
   FALLBACK HELPERS (si helpers.php no los trae)
========================= */
if (!function_exists('h')) {
  function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('money')) {
  function money($n): string {
    return '$ ' . number_format((float)$n, 2, ',', '.');
  }
}

if (!function_exists('format_qty')) {
  function format_qty($n): string {
    $v = (float)$n;
    if (abs($v - round($v)) < 0.0000001) return number_format($v, 0, ',', '.');
    $s = number_format($v, 3, ',', '.');
    $s = rtrim($s, '0');
    $s = rtrim($s, ',');
    return $s;
  }
}

/* =========================
   1) ID factura
========================= */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  die("ID de factura inválido");
}

/* =========================
   2) Leer factura + venta + cliente (cliente puede ser null)
========================= */
$sql = "
  SELECT
    f.*,
    v.fecha AS venta_fecha,
    v.total AS venta_total,
    c.nombre    AS cliente_nombre,
    c.cuit      AS cliente_cuit,
    c.cond_iva  AS cliente_cond_iva,
    c.direccion AS cliente_direccion
  FROM facturas f
  JOIN ventas v ON v.id = f.venta_id
  LEFT JOIN clientes c ON c.id = f.cliente_id
  WHERE f.id = ?
  LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$factura) {
  http_response_code(404);
  die("Factura no encontrada");
}

/* =========================
   3) Items de la venta
========================= */
$sqlItems = "
  SELECT vi.*, p.codigo, p.nombre
  FROM venta_items vi
  JOIN productos p ON p.id = vi.producto_id
  WHERE vi.venta_id = ?
  ORDER BY vi.id ASC
";
$stmtItems = $pdo->prepare($sqlItems);
$stmtItems->execute([(int)$factura['venta_id']]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   4) Config empresa (hardcode por ahora)
========================= */
$empresaNombre    = "Mi Kiosco Demo";
$empresaCUIT      = "20-00000000-0";
$empresaIVA       = "Responsable Inscripto";
$empresaDireccion = "Av. Siempre Viva 742";
$empresaIIBB      = "CM 000-000000-0";
$empresaInicio    = "01/01/2020";

/* AFIP placeholders */
$cae    = $factura['cae'] ?? "00000000000000";
$caeVto = $factura['cae_vencimiento'] ?? "00/00/0000";

/* Cliente fallback */
$clienteNombre    = $factura['cliente_nombre'] ?: 'Consumidor Final';
$clienteCuit      = $factura['cliente_cuit'] ?: '-';
$clienteCondIva   = $factura['cliente_cond_iva'] ?: 'Consumidor Final';
$clienteDireccion = $factura['cliente_direccion'] ?: '-';

/* Fecha factura (si no existe creado_en, usa fecha venta) */
$fechaRaw = $factura['creado_en'] ?? $factura['venta_fecha'] ?? '';
$fechaFmt = $fechaRaw ? date('d/m/Y H:i:s', strtotime((string)$fechaRaw)) : '-';

/* Letra/tipo */
$tipo = (string)($factura['tipo'] ?? '');
$letra = strtoupper(substr($tipo, 0, 1));
if (!in_array($letra, ['A','B','C','M','E'], true)) $letra = 'X';

/* =========================
   5) Header global
========================= */
$pageTitle      = "Factura " . h($tipo) . " " . sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']);
$currentSection = "facturacion";
$extraCss       = ['assets/css/factura.css?v=1'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap factura-page">
  <div class="factura-shell">

    <!-- Barra arriba -->
    <div class="factura-topbar no-print">
      <a href="facturacion.php" class="link-back-print">← Volver a facturación</a>
      <button class="btn btn-primary btn-print" onclick="window.print()">Imprimir</button>
    </div>

    <!-- CABECERA PRINCIPAL -->
    <header class="factura-header">

      <!-- Empresa -->
      <div class="factura-col empresa">
        <div class="factura-logo">
          <span class="factura-logo-mark">FLUS</span>
        </div>
        <div class="factura-empresa-datos">
          <div class="empresa-nombre"><?= h($empresaNombre) ?></div>
          <div><?= h($empresaDireccion) ?></div>
          <div>CUIT: <?= h($empresaCUIT) ?></div>
          <div>Condición frente al IVA: <?= h($empresaIVA) ?></div>
          <div>Ing. Brutos: <?= h($empresaIIBB) ?></div>
          <div>Inicio de actividades: <?= h($empresaInicio) ?></div>
        </div>
      </div>

      <!-- Letra -->
      <div class="factura-col letra">
        <div class="letra-cuadro">
          <div class="letra-tipo"><?= h($letra) ?></div>
        </div>
        <div class="letra-texto">
          FACTURA<br>
          <span class="letra-clase">Documento no válido como comprobante fiscal (demo)</span>
        </div>
      </div>

      <!-- Datos factura -->
      <div class="factura-col datos">
        <table class="tabla-datos-factura">
          <tr><th>Tipo</th><td><?= h($tipo) ?></td></tr>
          <tr><th>Punto de venta</th><td><?= sprintf('%04d', (int)$factura['punto_venta']) ?></td></tr>
          <tr><th>Número</th><td><?= sprintf('%08d', (int)$factura['numero']) ?></td></tr>
          <tr><th>Fecha</th><td><?= h($fechaFmt) ?></td></tr>
          <tr><th>Venta</th><td>#<?= (int)$factura['venta_id'] ?></td></tr>
          <tr><th>Estado</th><td><?= h($factura['estado'] ?? 'EMITIDA') ?></td></tr>
        </table>
      </div>

    </header>

    <!-- CLIENTE -->
    <section class="factura-bloque cliente-section">
      <div class="bloque-titulo">Cliente</div>
      <div class="cliente-grid">
        <div>
          <div class="label">Razón social</div>
          <div class="value"><?= h($clienteNombre) ?></div>
        </div>
        <div>
          <div class="label">CUIT</div>
          <div class="value"><?= h($clienteCuit) ?></div>
        </div>
        <div>
          <div class="label">Condición IVA</div>
          <div class="value"><?= h($clienteCondIva) ?></div>
        </div>
        <div class="cliente-full">
          <div class="label">Domicilio</div>
          <div class="value"><?= h($clienteDireccion) ?></div>
        </div>
      </div>
    </section>

    <!-- IMPORTES -->
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

    <!-- DETALLE -->
    <section class="factura-bloque detalle-section">
      <div class="bloque-titulo">Detalle</div>

      <table class="tabla-detalle">
        <thead>
          <tr>
            <th style="width:14%;">Código</th>
            <th>Descripción</th>
            <th style="width:10%;">Cant.</th>
            <th style="width:14%;">Precio</th>
            <th style="width:14%;">Subtotal</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($items): ?>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= h($it['codigo'] ?? '') ?></td>
              <td><?= h($it['nombre'] ?? '') ?></td>
              <td class="num"><?= h(format_qty($it['cantidad'] ?? 0)) ?></td>
              <td class="num"><?= money($it['precio'] ?? 0) ?></td>
              <td class="num"><?= money($it['subtotal'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="5" class="empty-cell">No hay ítems asociados a esta venta.</td>
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

    <!-- PIE AFIP / CAE -->
    <footer class="factura-footer">
      <div class="footer-left">
        <div class="footer-text">
          Comprobante generado desde <strong>FLUS</strong> – Sistema de gestión.
        </div>
      </div>
      <div class="footer-right">
        <div class="footer-row"><span>CAE:</span> <strong><?= h($cae) ?></strong></div>
        <div class="footer-row"><span>Vto. CAE:</span> <strong><?= h($caeVto) ?></strong></div>
        <div class="footer-note">(Campos reservados para futura integración AFIP)</div>
      </div>
    </footer>

  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
