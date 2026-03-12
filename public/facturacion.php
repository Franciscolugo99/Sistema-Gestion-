<?php
// public/facturacion.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura']);

$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}

function validDateYmdStr(string $value): string
{
    if ($value === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
}

function urlWithFact(array $overrides = []): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return 'facturacion.php' . ($query === [] ? '' : '?' . http_build_query($query));
}


$desdeRaw = (string)($_GET['desde'] ?? '');
$hastaRaw = (string)($_GET['hasta'] ?? '');
$estado = trim((string)($_GET['estado'] ?? ''));
$clienteId = (int)($_GET['cliente_id'] ?? 0);
$ventaIdFiltro = (int)($_GET['venta_id'] ?? 0);
$desde = validDateYmdStr($desdeRaw);
$hasta = validDateYmdStr($hastaRaw);

if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) {
    $perPage = 50;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedEstados = ['EMITIDA', 'ANULADA'];

$facturas = [];
$clientes = flus_facturacion_clientes_disponibles($pdo);
$avisos = [];
$totalRows = 0;
$totalPages = 1;

$modoFacturacion = 'demo';
$configFact = flus_facturacion_config_activa($pdo);
if ($configFact) {
    $modoFacturacion = flus_facturacion_modo_actual($configFact);
}
$modoFacturacionLabel = flus_facturacion_modo_label($modoFacturacion);

if (!flus_table_exists($pdo, 'facturas')) {
    $avisos[] = 'La tabla de facturas no existe todavia. Aplica la migracion de facturacion para ver el historial.';
} else {
    $fechaCol = flus_first_existing_column($pdo, 'facturas', ['creado_en', 'fecha']);
    $estadoCol = flus_column_exists($pdo, 'facturas', 'estado');
    $clienteIdCol = flus_column_exists($pdo, 'facturas', 'cliente_id');
    $ventaIdCol = flus_column_exists($pdo, 'facturas', 'venta_id');
    $joinClientes = $clienteIdCol && flus_table_exists($pdo, 'clientes');

    $fechaExpr = $fechaCol ? 'f.`' . $fechaCol . '`' : 'NULL';
    $tipoExpr = flus_column_exists($pdo, 'facturas', 'tipo') ? 'f.`tipo`' : "''";
    $puntoVentaExpr = flus_column_exists($pdo, 'facturas', 'punto_venta') ? 'f.`punto_venta`' : 'NULL';
    $numeroExpr = flus_column_exists($pdo, 'facturas', 'numero') ? 'f.`numero`' : 'NULL';
    $totalExpr = flus_column_exists($pdo, 'facturas', 'total') ? 'f.`total`' : '0';
    $estadoExpr = $estadoCol ? 'f.`estado`' : "'EMITIDA'";
    $ventaIdExpr = $ventaIdCol ? 'f.`venta_id`' : 'NULL';
    $clienteNombreExpr = $joinClientes
        ? (flus_column_exists($pdo, 'clientes', 'nombre') ? 'c.`nombre`' : 'CONCAT("Cliente #", c.id)')
        : 'NULL';
    $clienteCuitExpr = $joinClientes && flus_column_exists($pdo, 'clientes', 'cuit') ? 'c.`cuit`' : 'NULL';

    $where = ['1=1'];
    $params = [];

    if ($desde !== '' && $fechaCol) {
        $where[] = $fechaExpr . ' >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== '' && $fechaCol) {
        $where[] = $fechaExpr . ' <= :hasta';
        $params[':hasta'] = $hasta . ' 23:59:59';
    }
    if (($desde !== '' || $hasta !== '') && !$fechaCol) {
        $avisos[] = 'Esta instalacion no tiene una fecha de factura estandar, por eso el filtro por fecha no se pudo aplicar.';
    }

    if ($estado !== '' && in_array($estado, $allowedEstados, true) && $estadoCol) {
        $where[] = 'f.`estado` = :estado';
        $params[':estado'] = $estado;
    }

    if ($clienteId > 0 && $clienteIdCol) {
        $where[] = 'f.`cliente_id` = :cliente_id';
        $params[':cliente_id'] = $clienteId;
    }

    if ($ventaIdFiltro > 0 && $ventaIdCol) {
        $where[] = 'f.`venta_id` = :venta_id';
        $params[':venta_id'] = $ventaIdFiltro;
    } elseif ($ventaIdFiltro > 0) {
        $avisos[] = 'Esta instalacion no permite filtrar por venta porque la tabla facturas no tiene venta_id.';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $joinSql = $joinClientes ? 'LEFT JOIN clientes c ON c.id = f.cliente_id' : '';
    $orderSql = $fechaCol ? $fechaExpr . ' DESC, f.id DESC' : 'f.id DESC';

    $sqlCount = "
        SELECT COUNT(*)
        FROM facturas f
        {$joinSql}
        {$whereSql}
    ";
    $stCount = $pdo->prepare($sqlCount);
    $stCount->execute($params);
    $totalRows = (int)$stCount->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sqlList = "
        SELECT
            f.id,
            {$fechaExpr} AS fecha,
            {$tipoExpr} AS tipo,
            {$puntoVentaExpr} AS punto_venta,
            {$numeroExpr} AS numero,
            {$totalExpr} AS total,
            {$estadoExpr} AS estado,
            {$clienteNombreExpr} AS cliente_nombre,
            {$clienteCuitExpr} AS cliente_cuit,
            {$ventaIdExpr} AS venta_id
        FROM facturas f
        {$joinSql}
        {$whereSql}
        ORDER BY {$orderSql}
        LIMIT :limit OFFSET :offset
    ";

    $st = $pdo->prepare($sqlList);
    foreach ($params as $key => $value) {
        $st->bindValue($key, $value);
    }
    $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();
    $facturas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Facturacion';
$currentSection = 'facturacion';
$extraCss = ['assets/css/facturacion.css?v=8'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page">
  <div class="panel fact-panel">
    <header class="page-header">
      <div>
        <h1 class="page-title">Facturacion</h1>
        <p class="page-sub">
          Lista de facturas emitidas a partir de las ventas de caja.
          <?php if ($modoFacturacion === 'demo'): ?>
            <span class="modo-badge modo-demo" title="Las facturas generadas no se envian a ARCA">Modo demo</span>
          <?php elseif ($modoFacturacion === 'homologacion'): ?>
            <span class="modo-badge modo-homo" title="Conectado a ARCA testing">Homologacion</span>
          <?php else: ?>
            <span class="modo-badge modo-prod" title="Conectado a AFIP/ARCA produccion">Produccion</span>
          <?php endif; ?>
        </p>
      </div>

      <div class="promo-actions-top">
        <?php if (function_exists('user_has_permission') && user_has_permission('administrar_config')): ?>
          <a href="facturacion_config.php" class="v-btn v-btn--outline" title="Configuracion de facturacion">
            Configuracion
          </a>
        <?php endif; ?>
        <a href="factura_manual.php" class="v-btn v-btn--primary">
          + Factura manual
        </a>
      </div>
    </header>

    <?php foreach ($avisos as $aviso): ?>
      <div class="alert alert-error" style="margin-bottom:12px;"><?= h($aviso) ?></div>
    <?php endforeach; ?>

    <form method="get" class="filters fact-filters">
      <div class="filters-left">
        <input type="number" name="venta_id" min="1" step="1" value="<?= $ventaIdFiltro > 0 ? (int)$ventaIdFiltro : '' ?>" placeholder="Venta #">

        <select name="cliente_id">
          <option value="">Todos los clientes</option>
          <?php foreach ($clientes as $cli): ?>
            <option value="<?= (int)$cli['id'] ?>" <?= $clienteId === (int)$cli['id'] ? 'selected' : '' ?>>
              <?= h((string)($cli['nombre'] ?? 'Cliente')) ?><?= !empty($cli['cuit']) ? ' (' . h((string)$cli['cuit']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="estado">
          <option value="">Todos los estados</option>
          <option value="EMITIDA" <?= $estado === 'EMITIDA' ? 'selected' : '' ?>>Emitidas</option>
          <option value="ANULADA" <?= $estado === 'ANULADA' ? 'selected' : '' ?>>Anuladas</option>
        </select>
      </div>

      <div class="filters-right">
        <input type="date" name="desde" value="<?= h($desde) ?>">
        <input type="date" name="hasta" value="<?= h($hasta) ?>">

        <select name="per_page">
          <?php foreach ([20, 50, 100] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>

        <button class="btn btn-filter" type="submit">Aplicar</button>
        <a href="facturacion.php" class="btn btn-secondary">Limpiar</a>
      </div>
    </form>

    <div class="table-wrapper">
      <table class="mov-table fact-table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Comprobante</th>
            <th>Cliente</th>
            <th class="t-right">Total</th>
            <th>Estado</th>
            <th>Venta</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($facturas === []): ?>
          <tr>
            <td colspan="7" class="empty-cell">No se encontraron facturas con los filtros actuales.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($facturas as $factura): ?>
            <?php
              $clienteNombre = trim((string)($factura['cliente_nombre'] ?? '')) ?: 'Consumidor Final';
              $clienteCuit = trim((string)($factura['cliente_cuit'] ?? ''));
              $numero = $factura['numero'] !== null ? (int)$factura['numero'] : null;
              $puntoVenta = $factura['punto_venta'] !== null ? (int)$factura['punto_venta'] : null;
              $fechaLista = trim((string)($factura['fecha'] ?? ''));
              $fechaTs = $fechaLista !== '' ? strtotime($fechaLista) : false;
              $fechaMostrar = $fechaTs !== false ? date('d/m/Y H:i', $fechaTs) : ($fechaLista !== '' ? $fechaLista : '-');
            ?>
            <tr>
              <td class="mono"><?= h($fechaMostrar) ?></td>
              <td>
                <?= h((string)($factura['tipo'] ?? 'Factura')) ?>
                <?php if ($numero !== null && $puntoVenta !== null): ?>
                  <?= sprintf('%04d-%08d', $puntoVenta, $numero) ?>
                <?php elseif ($numero !== null): ?>
                  #<?= $numero ?>
                <?php else: ?>
                  (sin numero)
                <?php endif; ?>
              </td>
              <td>
                <?= h($clienteNombre) ?>
                <?php if ($clienteCuit !== ''): ?>
                  <span class="muted">(<?= h($clienteCuit) ?>)</span>
                <?php endif; ?>
              </td>
              <td class="t-right">$<?= number_format((float)($factura['total'] ?? 0), 2, ',', '.') ?></td>
              <td><?= h((string)($factura['estado'] ?? 'EMITIDA')) ?></td>
              <td>
                <?php if (!empty($factura['venta_id'])): ?>
                  <a href="venta_detalle.php?id=<?= (int)$factura['venta_id'] ?>">#<?= (int)$factura['venta_id'] ?></a>
                <?php else: ?>
                  -
                <?php endif; ?>
              </td>
              <td>
                <a href="factura_ver.php?id=<?= (int)$factura['id'] ?>" class="btn-mini">Ver / imprimir</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pager">
        <a class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : h(urlWithFact(['page' => $page - 1])) ?>">
          Anterior
        </a>
        <div class="pager-mid">Pagina <?= (int)$page ?> / <?= (int)$totalPages ?></div>
        <a class="pager-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : h(urlWithFact(['page' => $page + 1])) ?>">
          Siguiente
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
