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

function validDateYmd(?string $s): ?string {
  if (!$s) return null;
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

function fmt_dt(?string $s): string {
  if (!$s) return '';
  try {
    $d = new DateTime($s);
    return $d->format('d/m/Y H:i:s');
  } catch (Throwable $e) {
    return $s;
  }
}

function pretty_json(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $j = json_decode($s, true);
  if (json_last_error() === JSON_ERROR_NONE) {
    return (string)json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  }
  return $s;
}

/** Devuelve array (si es JSON válido) o null */
function meta_decode($meta): ?array {
  if ($meta === null || $meta === '') return null;
  if (!is_string($meta)) return null;
  $j = json_decode($meta, true);
  return (json_last_error() === JSON_ERROR_NONE && is_array($j)) ? $j : null;
}

/** Resumen corto tipo "importe: 1200 | medio_pago: EFECTIVO ..." */
function meta_summary(?array $m, int $maxLen = 120): string {
  if (!$m) return '';
  $keysPriority = ['msg','motivo','importe','total','medio_pago','descuento','caja_id','venta_id','producto_id','stock','cantidad'];

  $pairs = [];
  foreach ($keysPriority as $k) {
    if (array_key_exists($k, $m)) {
      $v = $m[$k];
      if (is_array($v) || is_object($v)) continue;
      $pairs[] = $k . ': ' . (string)$v;
    }
  }

  if (!$pairs) {
    $i = 0;
    foreach ($m as $k => $v) {
      if (is_array($v) || is_object($v)) continue;
      $pairs[] = $k . ': ' . (string)$v;
      $i++;
      if ($i >= 3) break;
    }
  }

  $s = implode(' | ', $pairs);
  if (mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen) . '…';
  return $s;
}

// --------------------
// Params
// --------------------
$accion  = trim((string)($_GET['accion'] ?? ''));
$entidad = trim((string)($_GET['entidad'] ?? ''));
$module  = trim((string)($_GET['module'] ?? ''));

$desde   = validDateYmd(trim((string)($_GET['desde'] ?? '')));
$hasta   = validDateYmd(trim((string)($_GET['hasta'] ?? '')));
$q       = trim((string)($_GET['q'] ?? ''));

$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20,50,100], true)) $perPage = 50;

$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

// --------------------
// WHERE
// --------------------
$where  = ["1=1"];
$params = [];

if ($accion !== '')  { $where[] = "a.action = :accion";   $params[':accion'] = $accion; }
if ($entidad !== '') { $where[] = "a.entity = :entidad";  $params[':entidad'] = $entidad; }
if ($module !== '')  { $where[] = "a.module = :module";   $params[':module'] = $module; }

if ($desde !== null) { $where[] = "a.created_at >= :desde"; $params[':desde'] = $desde . " 00:00:00"; }
if ($hasta !== null) { $where[] = "a.created_at <= :hasta"; $params[':hasta'] = $hasta . " 23:59:59"; }

if ($q !== '') {
  if (ctype_digit($q)) {
    $where[] = "(
      a.entity_id = :qid
      OR a.action LIKE :q
      OR a.module LIKE :q
      OR a.entity LIKE :q
      OR a.meta LIKE :q
      OR a.request_id LIKE :q
      OR a.ip LIKE :q
      OR a.user_agent LIKE :q
    )";
    $params[':qid'] = (int)$q;
  } else {
    $where[] = "(
      a.action LIKE :q
      OR a.module LIKE :q
      OR a.entity LIKE :q
      OR CAST(a.entity_id AS CHAR) LIKE :q
      OR a.meta LIKE :q
      OR a.request_id LIKE :q
      OR a.ip LIKE :q
      OR a.user_agent LIKE :q
    )";
  }
  $params[':q'] = "%$q%";
}

$whereSql = "WHERE " . implode(" AND ", $where);

// --------------------
// Query
// --------------------
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

// --------------------
// UI
// --------------------
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
        <label>Módulo</label>
        <input type="text" name="module" value="<?= h2($module) ?>" placeholder="CAJA / VENTAS / STOCK">
      </div>
      <div class="field">
        <label>Entidad</label>
        <input type="text" name="entidad" value="<?= h2($entidad) ?>" placeholder="ventas">
      </div>
      <div class="field">
        <label>Desde</label>
        <input type="date" name="desde" value="<?= h2($desde ?? '') ?>">
      </div>
      <div class="field">
        <label>Hasta</label>
        <input type="date" name="hasta" value="<?= h2($hasta ?? '') ?>">
      </div>
      <div class="field">
        <label>Buscar</label>
        <input type="text" name="q" value="<?= h2($q) ?>" placeholder="acción / módulo / entidad / id / meta...">
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
          <th>Módulo</th>
          <th>Acción</th>
          <th>Entidad</th>
          <th>ID</th>
          <th>Detalle</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="muted">Sin resultados</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $metaRaw   = (string)($r['meta'] ?? '');
              $metaArr   = meta_decode($metaRaw);
              $metaShort = meta_summary($metaArr);
              $metaPretty = $metaArr ? json_encode($metaArr, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : pretty_json($metaRaw);

              $beforePretty = pretty_json((string)($r['before_json'] ?? ''));
              $afterPretty  = pretty_json((string)($r['after_json'] ?? ''));

              $idVal  = $r['entity_id'] ?? null;
              $idShow = ($idVal === null || $idVal === '' || (int)$idVal === 0) ? '—' : (string)(int)$idVal;

              $ip  = (string)($r['ip'] ?? '');
              $ua  = (string)($r['user_agent'] ?? '');
              $rid = (string)($r['request_id'] ?? '');
            ?>
            <tr>
              <td><?= h2(fmt_dt($r['created_at'] ?? '')) ?></td>
              <td><?= h2($r['username'] ?? '-') ?></td>
              <td><code><?= h2($r['module'] ?? '') ?></code></td>
              <td><code><?= h2($r['action'] ?? '') ?></code></td>
              <td><?= h2($r['entity'] ?? '') ?></td>
              <td><?= h2($idShow) ?></td>

              <td class="meta">
                <details class="meta-details">
                  <summary><?= h2($metaShort !== '' ? $metaShort : 'ver detalle') ?></summary>

                  <?php if ($ip || $rid || $ua): ?>
                    <div class="meta-kv">
                      <?php if ($ip): ?><div><b>IP:</b> <?= h2($ip) ?></div><?php endif; ?>
                      <?php if ($rid): ?><div><b>RID:</b> <?= h2($rid) ?></div><?php endif; ?>
                      <?php if ($ua): ?><div><b>UA:</b> <?= h2($ua) ?></div><?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <?php if (trim($metaPretty) !== ''): ?>
                    <div class="meta-block">
                      <div class="meta-title">META</div>
                      <pre class="meta-pre"><?= h2($metaPretty) ?></pre>
                    </div>
                  <?php endif; ?>

                  <?php if ($beforePretty !== '' || $afterPretty !== ''): ?>
                    <div class="meta-block">
                      <div class="meta-title">ANTES</div>
                      <pre class="meta-pre"><?= h2($beforePretty ?: '—') ?></pre>
                    </div>
                    <div class="meta-block">
                      <div class="meta-title">DESPUÉS</div>
                      <pre class="meta-pre"><?= h2($afterPretty ?: '—') ?></pre>
                    </div>
                  <?php endif; ?>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php
    $totalPages = (int)ceil($totalRows / $perPage);
    if ($totalPages < 1) $totalPages = 1;

    $base = $_GET;
    $base['per_page'] = $perPage;
  ?>
  <div class="pager">
    <span class="muted">Página <?= $page ?> / <?= $totalPages ?> — <?= $totalRows ?> eventos</span>
    <div class="pager-links">
      <?php
        $prev = max(1, $page - 1);
        $next = min($totalPages, $page + 1);

        $base['page'] = $prev;
        $prevUrl = 'auditoria.php?' . http_build_query($base);

        $base['page'] = $next;
        $nextUrl = 'auditoria.php?' . http_build_query($base);
      ?>
      <a class="btn btn-sm btn-secondary <?= $page<=1?'is-disabled':'' ?>" href="<?= h2($prevUrl) ?>">«</a>

      <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        for ($p=$start; $p<=$end; $p++):
          $base['page'] = $p;
          $url = 'auditoria.php?' . http_build_query($base);
      ?>
        <a class="btn btn-sm <?= $p===$page?'btn-primary':'btn-secondary' ?>" href="<?= h2($url) ?>"><?= $p ?></a>
      <?php endfor; ?>

      <a class="btn btn-sm btn-secondary <?= $page>=$totalPages?'is-disabled':'' ?>" href="<?= h2($nextUrl) ?>">»</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
