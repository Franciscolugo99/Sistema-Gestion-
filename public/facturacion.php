<?php
// public/facturacion.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

/* =========================================================
   FALLBACK HELPERS (si helpers.php no los trae)
========================================================= */
if (!function_exists('h')) {
  function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

/** Fecha Y-m-d válida o '' */
function validDateYmdStr(string $s): string {
  if ($s === '') return '';
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return ($d && $d->format('Y-m-d') === $s) ? $s : '';
}

function urlWithFact(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  return 'facturacion.php' . (empty($q) ? '' : '?' . http_build_query($q));
}

/* =========================================================
   PARÁMETROS / FILTROS
========================================================= */
$desdeRaw  = (string)($_GET['desde'] ?? '');
$hastaRaw  = (string)($_GET['hasta'] ?? '');
$estado    = (string)($_GET['estado'] ?? '');
$clienteId = (int)($_GET['cliente_id'] ?? 0);

$desde = validDateYmdStr($desdeRaw);
$hasta = validDateYmdStr($hastaRaw);

// si vienen invertidas, las doy vuelta
if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
  $tmp = $desde; $desde = $hasta; $hasta = $tmp;
}

$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 50;

$page = max(1, (int)($_GET['page'] ?? 1));

// agregá más estados si los usás luego (EJ: 'ERROR')
$allowedEstados = ['EMITIDA', 'ANULADA'];

/* =========================================================
   WHERE (usa f.creado_en)
========================================================= */
$where  = ['1=1'];
$params = [];

if ($desde !== '') {
  $where[] = 'f.creado_en >= :desde';
  $params[':desde'] = $desde . ' 00:00:00';
}

if ($hasta !== '') {
  $where[] = 'f.creado_en <= :hasta';
  $params[':hasta'] = $hasta . ' 23:59:59';
}

if ($estado !== '' && in_array($estado, $allowedEstados, true)) {
  $where[] = 'f.estado = :estado';
  $params[':estado'] = $estado;
}

if ($clienteId > 0) {
  $where[] = 'f.cliente_id = :cliente_id';
  $params[':cliente_id'] = $clienteId;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* =========================================================
   TOTAL / PAGINACIÓN
========================================================= */
$sqlCount = "
  SELECT COUNT(*)
  FROM facturas f
  LEFT JOIN clientes c ON c.id = f.cliente_id
  {$whereSql}
";

$st = $pdo->prepare($sqlCount);
$st->execute($params);
$totalRows = (int)$st->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/* =========================================================
   LISTADO FACTURAS
========================================================= */
$sqlList = "
  SELECT
    f.id,
    f.creado_en AS fecha,
    f.tipo,
    f.punto_venta,
    f.numero,
    f.total,
    f.estado,
    c.nombre AS cliente_nombre,
    c.cuit   AS cliente_cuit,
    f.venta_id
  FROM facturas f
  LEFT JOIN clientes c ON c.id = f.cliente_id
  {$whereSql}
  ORDER BY f.creado_en DESC, f.id DESC
  LIMIT :limit OFFSET :offset
";

$st = $pdo->prepare($sqlList);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset,  PDO::PARAM_INT);

$st->execute();
$facturas = $st->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   CLIENTES PARA FILTRO
========================================================= */
$clientes = $pdo->query("
  SELECT id, nombre, cuit
  FROM clientes
  ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   HEADER GLOBAL
========================================================= */
$pageTitle      = "Facturación";
$currentSection = "facturacion";
$extraCss       = ["assets/css/facturacion.css?v=1"];

require __DIR__ . "/partials/header.php";
?>

<div class="page-wrap facturacion-page">
  <div class="panel fact-panel">

    <header class="page-header">
      <div>
        <h1 class="page-title">Facturación</h1>
        <p class="page-sub">Lista de facturas emitidas a partir de las ventas de caja.</p>
      </div>

      <div class="promo-actions-top">
        <a href="#!" class="v-btn v-btn--primary" style="opacity:.6;pointer-events:none;">
          + Nueva factura (próximamente)
        </a>
      </div>
    </header>

    <!-- FILTROS -->
    <form method="get" class="filters fact-filters">
      <div class="filters-left">
        <select name="cliente_id">
          <option value="">Todos los clientes</option>
          <?php foreach ($clientes as $cli): ?>
            <option value="<?= (int)$cli['id'] ?>" <?= $clienteId === (int)$cli['id'] ? 'selected' : '' ?>>
              <?= h($cli['nombre']) ?><?= $cli['cuit'] ? ' (' . h($cli['cuit']) . ')' : '' ?>
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
          <?php foreach ([20,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>

        <button class="btn btn-filter" type="submit">Aplicar</button>
        <a href="facturacion.php" class="btn btn-secondary">Limpiar</a>
      </div>
    </form>

    <!-- TABLA -->
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
        <?php if (!$facturas): ?>
          <tr>
            <td colspan="7" class="empty-cell">No se encontraron facturas con los filtros actuales.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($facturas as $f): ?>
            <?php
              $clienteNombre = $f['cliente_nombre'] ?: 'Consumidor Final';
              $clienteCuit   = $f['cliente_cuit'] ?: '';
            ?>
            <tr>
              <td class="mono"><?= h((string)$f['fecha']) ?></td>

              <td>
                <?= h((string)$f['tipo']) ?>
                <?php if ($f['numero'] !== null): ?>
                  <?= sprintf('%04d-%08d', (int)$f['punto_venta'], (int)$f['numero']) ?>
                <?php else: ?>
                  (sin número)
                <?php endif; ?>
              </td>

              <td>
                <?= h($clienteNombre) ?>
                <?php if ($clienteCuit): ?>
                  <span class="muted">(<?= h($clienteCuit) ?>)</span>
                <?php endif; ?>
              </td>

              <td class="t-right">$<?= number_format((float)$f['total'], 2, ',', '.') ?></td>
              <td><?= h((string)$f['estado']) ?></td>

              <td>
                <a href="venta_detalle.php?id=<?= (int)$f['venta_id'] ?>">#<?= (int)$f['venta_id'] ?></a>
              </td>

              <td>
                <a href="factura_ver.php?id=<?= (int)$f['id'] ?>" class="btn-mini">Ver / imprimir</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- PAGINACIÓN -->
    <?php if ($totalPages > 1): ?>
      <div class="pager">
        <a class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>"
           href="<?= $page <= 1 ? '#' : h(urlWithFact(['page' => $page - 1])) ?>">
          ←
        </a>

        <div class="pager-mid">Página <?= (int)$page ?> / <?= (int)$totalPages ?></div>

        <a class="pager-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
           href="<?= $page >= $totalPages ? '#' : h(urlWithFact(['page' => $page + 1])) ?>">
          →
        </a>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php require __DIR__ . "/partials/footer.php"; ?>
