<?php
// public/auditoria.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('ver_auditoria');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

function h2($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$accion = trim((string)($_GET['accion'] ?? ''));
$entidad = trim((string)($_GET['entidad'] ?? ''));
$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20,50,100], true)) $perPage = 50;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

$where = ["1=1"];
$params = [];

if ($accion !== '') { $where[] = "a.action = :accion"; $params[':accion'] = $accion; }
if ($entidad !== '') { $where[] = "a.entity = :entidad"; $params[':entidad'] = $entidad; }
if ($desde !== '') { $where[] = "a.created_at >= :desde"; $params[':desde'] = $desde . " 00:00:00"; }
if ($hasta !== '') { $where[] = "a.created_at <= :hasta"; $params[':hasta'] = $hasta . " 23:59:59"; }
if ($q !== '') {
  $where[] = "(a.action LIKE :q OR a.entity LIKE :q OR CAST(a.entity_id AS CHAR) LIKE :q)";
  $params[':q'] = "%$q%";
}

$whereSql = "WHERE " . implode(" AND ", $where);

$stCount = $pdo->prepare("SELECT COUNT(*) FROM audit_log a $whereSql");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();

$sql = "
  SELECT a.*, u.username
  FROM audit_log a
  LEFT JOIN users u ON u.id = a.user_id
  $whereSql
  ORDER BY a.created_at DESC, a.id DESC
  LIMIT :limit OFFSET :offset
";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':limit', $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = "Auditoría - FLUS";
$currentSection = "auditoria";
$extraCss = ['assets/css/auditoria.css'];
require __DIR__ . '/partials/header.php';

?>
<div class="panel auditoria-panel">
  <div class="panel-head">
    <h1>Auditoría</h1>
    <p class="muted">Registro de acciones (ventas, anulaciones, backups, etc.).</p>
  </div>

  <form method="get" class="auditoria-filters">
    <div class="grid">
      <div class="field">
        <label>Acción</label>
        <input type="text" name="accion" value="<?= h2($accion) ?>" placeholder="venta_anulada">
      </div>
      <div class="field">
        <label>Entidad</label>
        <input type="text" name="entidad" value="<?= h2($entidad) ?>" placeholder="ventas">
      </div>
      <div class="field">
        <label>Desde</label>
        <input type="date" name="desde" value="<?= h2($desde) ?>">
      </div>
      <div class="field">
        <label>Hasta</label>
        <input type="date" name="hasta" value="<?= h2($hasta) ?>">
      </div>
      <div class="field">
        <label>Buscar</label>
        <input type="text" name="q" value="<?= h2($q) ?>" placeholder="texto...">
      </div>
      <div class="field">
        <label>Por página</label>
        <select name="per_page">
          <?php foreach ([20,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="actions">
      <button class="btn btn-primary" type="submit">Filtrar</button>
      <a class="btn btn-secondary" href="auditoria.php">Limpiar</a>
    </div>
  </form>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Usuario</th>
          <th>Acción</th>
          <th>Entidad</th>
          <th>ID</th>
          <th>Meta</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="muted">Sin resultados</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= h2($r['created_at'] ?? '') ?></td>
              <td><?= h2($r['username'] ?? '-') ?></td>
              <td><code><?= h2($r['action'] ?? '') ?></code></td>
              <td><?= h2($r['entity'] ?? '') ?></td>
              <td><?= (int)($r['entity_id'] ?? 0) ?></td>
              <td class="meta"><?= h2((string)($r['meta'] ?? ($r['meta_json'] ?? ''))) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php
  $totalPages = (int)ceil($totalRows / $perPage);
  if ($totalPages < 1) $totalPages = 1;
  ?>
  <div class="pager">
    <span class="muted">Página <?= $page ?> / <?= $totalPages ?> — <?= $totalRows ?> eventos</span>
    <div class="pager-links">
      <?php
        $base = $_GET;
        for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++):
          $base['page']=$p;
          $url = 'auditoria.php?' . http_build_query($base);
      ?>
        <a class="btn btn-sm <?= $p===$page?'btn-primary':'btn-secondary' ?>" href="<?= h2($url) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
