<?php
// public/documentos_comerciales.php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura']);

$clientes = flus_facturacion_clientes_disponibles($pdo);
$tiposPermitidos = ['PRESUPUESTO', 'REMITO'];
$tipo = strtoupper(trim((string)($_GET['tipo'] ?? '')));
if ($tipo !== '' && !in_array($tipo, $tiposPermitidos, true)) {
    $tipo = '';
}
$estado = strtoupper(trim((string)($_GET['estado'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));
$clienteId = (int)($_GET['cliente_id'] ?? 0);
$ventaId = (int)($_GET['venta_id'] ?? 0);

function documentos_comerciales_resumen_operativo(array $row, bool $ventaRequeridaParaFacturar = false): array
{
    $tipo = strtoupper(trim((string)($row['tipo_documento'] ?? '')));
    $estado = strtoupper(trim((string)($row['estado'] ?? 'PENDIENTE')));
    $clienteId = (int)($row['cliente_id'] ?? 0);
    $ventaId = (int)($row['venta_id'] ?? 0);
    $facturaId = (int)($row['factura_id'] ?? 0);

    if ($facturaId > 0 || $estado === 'FACTURADO') {
        return ['estado' => 'Facturado', 'siguiente' => 'Ver factura'];
    }
    if ($ventaId > 0 || $estado === 'CONVERTIDO_VENTA') {
        return ['estado' => 'Venta vinculada', 'siguiente' => 'Ver venta'];
    }
    if ($clienteId <= 0) {
        return ['estado' => 'Borrador', 'siguiente' => 'Completar cliente'];
    }
    if ($tipo === 'PRESUPUESTO' && $estado === 'REMITADO') {
        return ['estado' => 'Remito generado', 'siguiente' => 'Ver remito / continuar operación'];
    }
    if ($tipo === 'PRESUPUESTO') {
        return ['estado' => 'Listo para operar', 'siguiente' => 'Generar remito o venta'];
    }
    if ($tipo === 'REMITO') {
        if ($ventaRequeridaParaFacturar && $ventaId <= 0) {
            return ['estado' => 'Listo para cierre', 'siguiente' => 'Generar o vincular venta'];
        }
        return ['estado' => 'Listo para cierre', 'siguiente' => 'Emitir factura o vincular venta'];
    }

    return ['estado' => 'Documental', 'siguiente' => 'Ver detalle'];
}

$pageTitle = 'Documentos comerciales';
$currentSection = 'facturacion';
$breadcrumbs = [
    ['label' => 'Facturación', 'url' => 'facturacion.php'],
    ['label' => 'Documentos comerciales', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=21'];
require __DIR__ . '/partials/header.php';

$rows = [];
$errores = [];
$ventaRequeridaParaFacturar = flus_facturacion_facturas_require_venta($pdo);

if (!flus_facturacion_documentos_table_ready($pdo)) {
    $errores[] = 'Faltan las tablas documentales. Aplica primero la migracion 017 para usar presupuestos y remitos.';
} else {
    $joinClientes = flus_table_exists($pdo, 'clientes');
    $joinFacturas = flus_table_exists($pdo, 'facturas') && flus_column_exists($pdo, 'facturas', 'documento_id');
    $joinOrigen = flus_column_exists($pdo, 'documentos_comerciales', 'documento_origen_id');

    $where = ["d.tipo_documento IN ('PRESUPUESTO','REMITO')"];
    $params = [];

    if ($tipo !== '') {
        $where[] = 'd.tipo_documento = :tipo';
        $params[':tipo'] = $tipo;
    }
    if ($estado !== '') {
        $where[] = 'UPPER(TRIM(d.estado)) = :estado';
        $params[':estado'] = $estado;
    }
    if ($clienteId > 0) {
        $where[] = 'd.cliente_id = :cliente_id';
        $params[':cliente_id'] = $clienteId;
    }
    if ($ventaId > 0) {
        $where[] = 'd.venta_id = :venta_id';
        $params[':venta_id'] = $ventaId;
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, "\\%_") . '%';
        $search = ['CAST(d.id AS CHAR) LIKE :q_doc_id', "d.nota LIKE :q_nota ESCAPE '\\\\'"];
        $params[':q_doc_id'] = $like;
        $params[':q_nota'] = $like;
        if ($joinClientes) {
            $search[] = "c.nombre LIKE :q_cliente_nombre ESCAPE '\\\\'";
            $params[':q_cliente_nombre'] = $like;
            if (flus_column_exists($pdo, 'clientes', 'cuit')) {
                $search[] = "c.cuit LIKE :q_cliente_cuit ESCAPE '\\\\'";
                $params[':q_cliente_cuit'] = $like;
            }
        }
        $where[] = '(' . implode(' OR ', $search) . ')';
    }

    $sql = '
        SELECT
            d.*,
            ' . ($joinClientes ? 'c.nombre AS cliente_nombre, c.cuit AS cliente_cuit,' : 'NULL AS cliente_nombre, NULL AS cliente_cuit,') . '
            ' . ($joinFacturas ? 'f.id AS factura_id,' : 'NULL AS factura_id,') . '
            ' . ($joinOrigen ? 'o.id AS origen_id, o.tipo_documento AS origen_tipo' : 'NULL AS origen_id, NULL AS origen_tipo') . '
        FROM documentos_comerciales d
        ' . ($joinClientes ? 'LEFT JOIN clientes c ON c.id = d.cliente_id' : '') . '
        ' . ($joinFacturas ? 'LEFT JOIN facturas f ON f.documento_id = d.id' : '') . '
        ' . ($joinOrigen ? 'LEFT JOIN documentos_comerciales o ON o.id = d.documento_origen_id' : '') . '
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY d.id DESC
        LIMIT 150';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$docStats = [
    'total' => count($rows),
    'presupuestos' => 0,
    'remitos' => 0,
    'borradores' => 0,
    'listos' => 0,
];
foreach ($rows as $row) {
    $tipoDocStat = strtoupper(trim((string)($row['tipo_documento'] ?? '')));
    if ($tipoDocStat === 'PRESUPUESTO') {
        $docStats['presupuestos']++;
    } elseif ($tipoDocStat === 'REMITO') {
        $docStats['remitos']++;
    }

    $operativoStat = documentos_comerciales_resumen_operativo($row, $ventaRequeridaParaFacturar);
    $estadoStat = strtolower((string)($operativoStat['estado'] ?? ''));
    if ($estadoStat === 'borrador') {
        $docStats['borradores']++;
    }
    if (str_contains($estadoStat, 'listo')) {
        $docStats['listos']++;
    }
}
?>

<div class="page-wrap facturacion-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M6 4h9l3 3v13H6z"/>
              <path d="M15 4v4h4"/>
              <path d="M9 12h7"/>
              <path d="M9 16h5"/>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="module-eyebrow">Capa documental</span>
            <h1 class="page-title module-title">Documentos comerciales</h1>
            <p class="page-sub module-subtitle">Presupuestos, remitos y documentos vinculados antes del cierre fiscal o comercial.</p>
          </div>
        </div>
      </div>
      <div class="promo-actions-top module-header-actions">
        <a href="facturacion.php" class="v-btn v-btn--outline">Volver a facturación</a>
        <a href="documento_comercial.php?tipo=PRESUPUESTO" class="v-btn v-btn--outline">+ Presupuesto</a>
        <a href="documento_comercial.php?tipo=REMITO" class="v-btn v-btn--primary">+ Remito</a>
      </div>
    </header>

    <section class="fact-doc-command" aria-label="Resumen documental">
      <article>
        <span>Vista actual</span>
        <strong><?= (int)$docStats['total'] ?></strong>
        <small>documentos encontrados</small>
      </article>
      <article>
        <span>Presupuestos</span>
        <strong><?= (int)$docStats['presupuestos'] ?></strong>
        <small>propuestas o pedidos armados</small>
      </article>
      <article>
        <span>Remitos</span>
        <strong><?= (int)$docStats['remitos'] ?></strong>
        <small>entregas con trazabilidad</small>
      </article>
      <article>
        <span>Listos para operar</span>
        <strong><?= (int)$docStats['listos'] ?></strong>
        <small><?= (int)$docStats['borradores'] ?> borradores requieren cliente</small>
      </article>
    </section>

    <section class="fact-doc-flow" aria-label="Flujo documental">
      <div class="fact-doc-flow__step">
        <span>1</span>
        <strong>Presupuesto</strong>
        <small>Cotiza o deja armado el pedido.</small>
      </div>
      <div class="fact-doc-flow__step">
        <span>2</span>
        <strong>Remito</strong>
        <small>Documenta entrega y trazabilidad comercial.</small>
      </div>
      <div class="fact-doc-flow__step">
        <span>3</span>
        <strong>Venta o factura</strong>
        <small>Cierra la operacion segun configuracion.</small>
      </div>
    </section>

    <section class="fact-doc-guide" aria-label="Guia rapida de documentos comerciales">
      <article class="fact-doc-guide__card">
        <span class="fact-doc-guide__eyebrow">Donde verlos</span>
        <h3>Presupuestos y remitos viven aca</h3>
        <p>En este modulo ves, buscas y reabris los presupuestos y remitos ya creados. La factura fiscal sigue viendose en Facturacion.</p>
      </article>
      <article class="fact-doc-guide__card">
        <span class="fact-doc-guide__eyebrow">Presupuesto</span>
        <h3>Ordena la propuesta</h3>
        <p>Sirve para cotizar o dejar armado un pedido. No impacta caja ni stock por si solo.</p>
      </article>
      <article class="fact-doc-guide__card">
        <span class="fact-doc-guide__eyebrow">Remito</span>
        <h3>Documenta la entrega</h3>
        <p>El remito deja trazabilidad comercial. Segun la instalacion, despues puede requerir una venta valida antes de facturar.</p>
      </article>
      <article class="fact-doc-guide__card">
        <span class="fact-doc-guide__eyebrow">Items</span>
        <h3>Pueden ser manuales</h3>
        <p>El codigo es opcional. Presupuesto y remito aceptan items cargados a mano aunque no exista un producto dado de alta.</p>
      </article>
    </section>

    <?php foreach ($errores as $error): ?>
      <div class="alert alert-error fact-doc-alert"><?= h($error) ?></div>
    <?php endforeach; ?>

    <form method="get" class="filters fact-filters fact-doc-filters">
      <div class="filters-left">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por ID, cliente o nota">
        <input type="number" name="venta_id" min="1" step="1" value="<?= $ventaId > 0 ? (int)$ventaId : '' ?>" placeholder="Venta #">

        <select name="cliente_id">
          <option value="">Todos los clientes</option>
          <?php foreach ($clientes as $cli): ?>
            <option value="<?= (int)$cli['id'] ?>" <?= $clienteId === (int)$cli['id'] ? 'selected' : '' ?>>
              <?= h((string)($cli['nombre'] ?? 'Cliente')) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="tipo">
          <option value="">Todos los tipos</option>
          <?php foreach ($tiposPermitidos as $tipoDoc): ?>
            <option value="<?= h($tipoDoc) ?>" <?= $tipo === $tipoDoc ? 'selected' : '' ?>><?= h($tipoDoc) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="estado">
          <option value="">Todos los estados</option>
          <?php foreach (['PENDIENTE', 'EMITIDO', 'REMITADO', 'CONVERTIDO_VENTA', 'FACTURADO', 'ANULADO', 'CANCELADO'] as $estadoOpt): ?>
            <option value="<?= h($estadoOpt) ?>" <?= $estado === $estadoOpt ? 'selected' : '' ?>><?= h($estadoOpt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filters-right">
        <button class="btn btn-filter" type="submit">Aplicar</button>
        <a href="documentos_comerciales.php" class="btn btn-secondary">Limpiar</a>
      </div>
    </form>

    <?php if ($rows === []): ?>
      <section class="fact-empty-state">
        <div class="fact-empty-state__icon">D</div>
        <h3>No hay documentos para esta vista</h3>
        <p>Genera un presupuesto o un remito para empezar a dejar trazabilidad comercial.</p>
        <div class="fact-empty-state__actions">
          <a href="documento_comercial.php?tipo=PRESUPUESTO" class="btn btn-secondary">Nuevo presupuesto</a>
          <a href="documento_comercial.php?tipo=REMITO" class="btn btn-primary">Nuevo remito</a>
        </div>
      </section>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="mov-table fact-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Documento</th>
              <th>Cliente</th>
              <th class="t-right">Total</th>
              <th>Estado / siguiente</th>
              <th>Venta</th>
              <th>Origen / factura</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $createdAt = trim((string)($row['created_at'] ?? ''));
              $fechaTs = $createdAt !== '' ? strtotime($createdAt) : false;
              $fechaLabel = $fechaTs !== false ? date('d/m/Y H:i', $fechaTs) : ($createdAt !== '' ? $createdAt : '-');
              $clienteNombre = trim((string)($row['cliente_nombre'] ?? '')) ?: 'Sin cliente';
              $clienteCuit = trim((string)($row['cliente_cuit'] ?? ''));
              $ventaLinked = (int)($row['venta_id'] ?? 0);
              $facturaLinked = (int)($row['factura_id'] ?? 0);
              $origenId = (int)($row['origen_id'] ?? 0);
              $origenTipo = trim((string)($row['origen_tipo'] ?? ''));
              $operativo = documentos_comerciales_resumen_operativo($row, $ventaRequeridaParaFacturar);
            ?>
            <tr>
              <td class="mono">
                <div><?= h($fechaLabel) ?></div>
                <div class="fact-cell-sub">ID #<?= (int)$row['id'] ?></div>
              </td>
              <td>
                <div class="fact-doc-title"><?= h((string)($row['tipo_documento'] ?? 'DOCUMENTO')) ?></div>
                <div class="fact-cell-sub"><?= h((string)($row['nota'] ?? 'Sin nota')) ?></div>
                <div class="fact-cell-sub"><?= strtoupper(trim((string)($row['tipo_documento'] ?? ''))) === 'REMITO' ? 'Documento de entrega / trazabilidad' : 'Documento de propuesta / borrador comercial' ?></div>
              </td>
              <td>
                <div class="fact-doc-title"><?= h($clienteNombre) ?></div>
                <div class="fact-cell-sub"><?= (int)($row['cliente_id'] ?? 0) > 0 ? ($clienteCuit !== '' ? h($clienteCuit) : 'Cliente sin CUIT/documento') : 'Borrador sin cliente' ?></div>
              </td>
              <td class="t-right"><div class="fact-doc-title"><?= money_ar((float)($row['total'] ?? 0)) ?></div></td>
              <td>
                <span class="fact-status-badge <?= in_array(strtoupper(trim((string)($row['estado'] ?? ''))), ['ANULADO', 'CANCELADO'], true) ? 'fact-status-badge--danger' : 'fact-status-badge--ok' ?>">
                  <?= h((string)($row['estado'] ?? 'PENDIENTE')) ?>
                </span>
                <div class="fact-cell-sub"><?= h((string)($operativo['estado'] ?? 'Documental')) ?></div>
                <div class="fact-cell-sub"><?= h((string)($operativo['siguiente'] ?? 'Ver detalle')) ?></div>
              </td>
              <td>
                <?php if ($ventaLinked > 0): ?>
                  <a href="venta_detalle.php?id=<?= $ventaLinked ?>" class="fact-link-inline">Venta #<?= $ventaLinked ?></a>
                  <div class="fact-cell-sub">La venta es la que impacta stock/caja.</div>
                <?php else: ?>
                  <span class="fact-cell-sub">Sin venta</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($origenId > 0): ?>
                  <div><a href="documento_comercial.php?id=<?= $origenId ?>" class="fact-link-inline"><?= h($origenTipo !== '' ? $origenTipo : 'Origen') ?> #<?= $origenId ?></a></div>
                <?php endif; ?>
                <?php if ($facturaLinked > 0): ?>
                  <div><a href="factura_ver.php?id=<?= $facturaLinked ?>" class="fact-link-inline">Factura #<?= $facturaLinked ?></a></div>
                <?php elseif ($origenId <= 0): ?>
                  <span class="fact-cell-sub">Sin factura</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="fact-row-actions">
                  <a href="documento_comercial.php?id=<?= (int)$row['id'] ?>" class="btn-mini">Abrir</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
