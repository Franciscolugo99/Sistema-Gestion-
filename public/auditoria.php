<?php
// public/auditoria.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_auditoria');

$pdo = getPDO();

if (!function_exists('h2')) {
  function h2($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

if (!function_exists('validDateYmd')) {
function validDateYmd(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
  }
}

function audit_table_columns(PDO $pdo): array {
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
    return is_array($cols) ? array_map('strval', $cols) : [];
  } catch (Throwable $e) {
    return [];
  }
}

function audit_has_column(array $columns, string $column): bool {
  return in_array($column, $columns, true);
}

function audit_fmt_dt(?string $s): string {
  if (!$s) return '';
  try {
    $d = new DateTime($s);
    return $d->format('d/m/Y H:i:s');
  } catch (Throwable $e) {
    return $s;
  }
}

function audit_pretty_json(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $j = json_decode($s, true);
  if (json_last_error() === JSON_ERROR_NONE) {
    return (string)json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  }
  return $s;
}

function audit_meta_decode($meta): ?array {
  if ($meta === null || $meta === '') return null;
  if (!is_string($meta)) return null;
  $j = json_decode($meta, true);
  return (json_last_error() === JSON_ERROR_NONE && is_array($j)) ? $j : null;
}

function audit_meta_summary(?array $m, int $maxLen = 140): string {
  if (!$m) return '';
  $keysPriority = [
    'msg', 'motivo', 'importe', 'total', 'medio_pago', 'descuento',
    'caja_id', 'venta_id', 'producto_id', 'precio_anterior', 'precio_nuevo',
    'stock', 'cantidad', 'target_user_name', 'locked_terminal_name',
  ];

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
      if (++$i >= 3) break;
    }
  }

  $s = implode(' | ', $pairs);
  if (mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen) . '…';
  return $s;
}

function audit_action_label(string $action): string {
  $map = [
    'PRODUCTO_PRECIO_CHANGE' => 'Cambio de precio',
    'INVENTARIO_SESSION_CREATE' => 'Inventario iniciado',
    'INVENTARIO_SESSION_CLOSE' => 'Inventario cerrado',
    'INVENTARIO_COUNT' => 'Conteo de inventario',
    'INVENTARIO_ADJUST' => 'Ajuste por inventario',
    'SESSION_REVOKE' => 'Forzó salida',
    'TERMINAL_FORCE_RELEASE' => 'Liberó terminal',
    'BACKUP_CREATE' => 'Backup creado',
    'BACKUP_RESTORE' => 'Backup restaurado',
    'BACKUP_DELETE' => 'Backup eliminado',
    'DIAGNOSTIC_EXPORT' => 'Paquete de diagnóstico',
    'venta_anulada' => 'Venta anulada',
    'venta_creada' => 'Venta creada',
  ];

  if (isset($map[$action])) return $map[$action];
  $label = str_replace(['_', '-'], ' ', strtolower($action));
  return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
}

function audit_entity_label(string $entity): string {
  $map = [
    'PRODUCTO' => 'Producto',
    'INVENTARIO' => 'Inventario',
    'VENTA' => 'Venta',
    'ventas' => 'Venta',
    'USER' => 'Usuario',
    'TERMINAL' => 'Terminal',
    'BACKUP' => 'Backup',
    'SYSTEM' => 'Sistema',
    'STOCK' => 'Stock',
    'CAJA' => 'Caja',
    'PROMO' => 'Promoción',
    'CUENTA_CORRIENTE' => 'Cuenta corriente',
  ];
  return $map[$entity] ?? $entity;
}

function audit_badge_class(string $action, string $entity): string {
  $haystack = strtoupper($action . ' ' . $entity);
  if (str_contains($haystack, 'ANUL') || str_contains($haystack, 'VOID') || str_contains($haystack, 'DELETE') || str_contains($haystack, 'RESTORE') || str_contains($haystack, 'REVOKE') || str_contains($haystack, 'FORCE')) {
    return 'danger';
  }
  if (str_contains($haystack, 'PRECIO') || str_contains($haystack, 'AJUST') || str_contains($haystack, 'CONFIG') || str_contains($haystack, 'PERMISSION')) {
    return 'warning';
  }
  if (str_contains($haystack, 'CREATE') || str_contains($haystack, 'CLOSE') || str_contains($haystack, 'COUNT')) {
    return 'ok';
  }
  return 'info';
}

function audit_distinct_values(PDO $pdo, string $column, array $columns, int $limit = 120): array {
  $allowed = ['action', 'entity', 'module'];
  if (!in_array($column, $allowed, true)) return [];
  if (!audit_has_column($columns, $column)) return [];

  $sql = "
    SELECT {$column} AS value, COUNT(*) AS total
    FROM audit_log
    WHERE {$column} IS NOT NULL AND {$column} <> ''
    GROUP BY {$column}
    ORDER BY total DESC, {$column} ASC
    LIMIT {$limit}
  ";

  try {
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function audit_url(array $overrides = []): string {
  $params = $_GET;
  unset($params['export']);
  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
    } else {
      $params[$key] = $value;
    }
  }
  $params['page'] = $params['page'] ?? 1;
  $query = http_build_query($params);
  return 'auditoria.php' . ($query ? '?' . $query : '');
}

function audit_is_chip_active(array $criteria): bool {
  foreach ($criteria as $key => $value) {
    if ((string)($_GET[$key] ?? '') !== (string)$value) return false;
  }
  return true;
}

// --------------------
// Params
// --------------------
$auditColumns = audit_table_columns($pdo);
$hasEntityId = audit_has_column($auditColumns, 'entity_id');
$hasModule = audit_has_column($auditColumns, 'module');
$hasMeta = audit_has_column($auditColumns, 'meta');
$hasMetaJson = audit_has_column($auditColumns, 'meta_json');
$hasRequestId = audit_has_column($auditColumns, 'request_id');
$hasIp = audit_has_column($auditColumns, 'ip');
$hasUserAgent = audit_has_column($auditColumns, 'user_agent');
$hasBeforeJson = audit_has_column($auditColumns, 'before_json');
$hasAfterJson = audit_has_column($auditColumns, 'after_json');

$accion  = trim((string)($_GET['accion'] ?? ''));
$entidad = trim((string)($_GET['entidad'] ?? ''));
$module  = $hasModule ? trim((string)($_GET['module'] ?? '')) : '';

$desde   = validDateYmd(trim((string)($_GET['desde'] ?? '')));
$hasta   = validDateYmd(trim((string)($_GET['hasta'] ?? '')));
$q       = trim((string)($_GET['q'] ?? ''));

$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 50;

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
  $qParts = [];
  $qLike = "%$q%";
  $addLike = static function (string $expr) use (&$qParts, &$params, $qLike): void {
    $param = ':q' . count($qParts);
    $qParts[] = "{$expr} LIKE {$param}";
    $params[$param] = $qLike;
  };

  if (ctype_digit($q)) {
    if ($hasEntityId) {
      $qParts[] = "a.entity_id = :qid";
      $params[':qid'] = (int)$q;
    }
  } elseif ($hasEntityId) {
    $addLike('CAST(a.entity_id AS CHAR)');
  }

  $addLike('a.action');
  $addLike('a.entity');
  if ($hasModule) $addLike('a.module');
  if ($hasMeta) $addLike('a.meta');
  if ($hasMetaJson) $addLike('a.meta_json');
  if ($hasRequestId) $addLike('a.request_id');
  if ($hasIp) $addLike('a.ip');
  if ($hasUserAgent) $addLike('a.user_agent');

  $where[] = "(" . implode(" OR ", $qParts) . ")";
}

$whereSql = "WHERE " . implode(" AND ", $where);
$selectModule = $hasModule ? 'a.module' : "''";
$selectEntityId = $hasEntityId ? 'a.entity_id' : 'NULL';
$selectRequestId = $hasRequestId ? 'a.request_id' : "''";
$selectBeforeJson = $hasBeforeJson ? 'a.before_json' : 'NULL';
$selectAfterJson = $hasAfterJson ? 'a.after_json' : 'NULL';
$selectMeta = $hasMeta ? 'a.meta' : ($hasMetaJson ? 'a.meta_json' : "''");

// --------------------
// Export CSV filtrado
// --------------------
if ((string)($_GET['export'] ?? '') === 'csv') {
  $sqlExport = "
    SELECT a.created_at,
           COALESCE(u.username, 'Sistema') AS username,
           {$selectModule} AS module,
           a.action,
           a.entity,
           {$selectEntityId} AS entity_id,
           " . ($hasIp ? 'a.ip' : "''") . " AS ip,
           {$selectRequestId} AS request_id,
           {$selectMeta} AS meta
    FROM audit_log a
    LEFT JOIN users u ON u.id = a.user_id
    $whereSql
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT 5000
  ";
  $stExport = $pdo->prepare($sqlExport);
  foreach ($params as $k => $v) $stExport->bindValue($k, $v);
  $stExport->execute();

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="flus_auditoria_' . date('Ymd_His') . '.csv"');
  header('Cache-Control: no-store');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['fecha', 'usuario', 'modulo', 'accion', 'accion_legible', 'entidad', 'id', 'ip', 'request_id', 'detalle']);
  while ($row = $stExport->fetch(PDO::FETCH_ASSOC)) {
    $metaArr = audit_meta_decode((string)($row['meta'] ?? ''));
    fputcsv($out, [
      (string)($row['created_at'] ?? ''),
      (string)($row['username'] ?? ''),
      (string)($row['module'] ?? ''),
      (string)($row['action'] ?? ''),
      audit_action_label((string)($row['action'] ?? '')),
      (string)($row['entity'] ?? ''),
      (string)($row['entity_id'] ?? ''),
      (string)($row['ip'] ?? ''),
      (string)($row['request_id'] ?? ''),
      $metaArr ? audit_meta_summary($metaArr, 240) : '',
    ]);
  }
  fclose($out);
  exit;
}

// --------------------
// Datos
// --------------------
$stCount = $pdo->prepare("SELECT COUNT(*) FROM audit_log a $whereSql");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();

$sql = "
  SELECT a.*,
         {$selectModule} AS module,
         {$selectEntityId} AS entity_id,
         {$selectRequestId} AS request_id,
         {$selectBeforeJson} AS before_json,
         {$selectAfterJson} AS after_json,
         {$selectMeta} AS meta,
         u.username
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

$summary = [
  'total' => 0,
  'today' => 0,
  'seven_days' => 0,
  'critical' => 0,
  'last_at' => null,
];

try {
  $summary['total'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
  $summary['today'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= CURDATE()")->fetchColumn();
  $summary['seven_days'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
  $summary['critical'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM audit_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND (
        action LIKE '%ANUL%' OR action LIKE '%VOID%' OR action LIKE '%DELETE%' OR action LIKE '%RESTORE%'
        OR action LIKE '%REVOKE%' OR action LIKE '%FORCE%' OR action LIKE '%PERMISSION%'
      )
  ")->fetchColumn();
  $summary['last_at'] = $pdo->query("SELECT MAX(created_at) FROM audit_log")->fetchColumn() ?: null;
} catch (Throwable $e) {
  // La pantalla sigue funcionando aunque falle el resumen.
}

$actionOptions = audit_distinct_values($pdo, 'action', $auditColumns);
$entityOptions = audit_distinct_values($pdo, 'entity', $auditColumns);
$moduleOptions = audit_distinct_values($pdo, 'module', $auditColumns);

$quickFilters = [
  ['label' => 'Todo', 'criteria' => [], 'href' => 'auditoria.php'],
  ['label' => 'Anulaciones', 'criteria' => ['q' => 'anul'], 'href' => audit_url(['q' => 'anul', 'accion' => null, 'entidad' => null, 'module' => null, 'page' => 1])],
  ['label' => 'Precios', 'criteria' => ['accion' => 'PRODUCTO_PRECIO_CHANGE'], 'href' => audit_url(['accion' => 'PRODUCTO_PRECIO_CHANGE', 'q' => null, 'page' => 1])],
  ['label' => 'Inventario', 'criteria' => ['entidad' => 'INVENTARIO'], 'href' => audit_url(['entidad' => 'INVENTARIO', 'q' => null, 'page' => 1])],
  ['label' => 'Sesiones', 'criteria' => ['q' => 'SESSION'], 'href' => audit_url(['q' => 'SESSION', 'accion' => null, 'entidad' => null, 'module' => null, 'page' => 1])],
  ['label' => 'Backups', 'criteria' => ['entidad' => 'BACKUP'], 'href' => audit_url(['entidad' => 'BACKUP', 'q' => null, 'page' => 1])],
];

// --------------------
// UI
// --------------------
$pageTitle = "Auditoría - FLUS";
$currentSection = "auditoria";
$extraCss = ['assets/css/auditoria.css'];
require __DIR__ . '/partials/header.php';
?>
<div class="panel auditoria-panel">
  <header class="panel-head page-header module-header">
    <div class="page-header-main module-header-main">
      <div class="module-header-hero">
        <span class="module-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <path d="M9 3h6"/>
            <path d="M10 8h4"/>
            <rect x="5" y="5" width="14" height="16" rx="2"/>
            <path d="m9 14 2 2 4-4"/>
          </svg>
        </span>
        <div class="module-header-copy">
          <span class="module-eyebrow">Trazabilidad interna</span>
          <h1 class="page-title">Auditoría</h1>
          <p class="page-sub">Control de acciones sensibles, anulaciones, cambios de precio, inventario y soporte.</p>
        </div>
      </div>
    </div>
    <div class="module-header-actions">
      <a class="btn btn-secondary" href="<?= h2(audit_url(['export' => 'csv', 'page' => null])) ?>">Exportar CSV</a>
    </div>
  </header>

  <section class="audit-summary" aria-label="Resumen de auditoría">
    <article class="audit-summary-card">
      <span class="audit-summary-label">Eventos totales</span>
      <strong><?= number_format((int)$summary['total'], 0, ',', '.') ?></strong>
    </article>
    <article class="audit-summary-card">
      <span class="audit-summary-label">Hoy</span>
      <strong><?= number_format((int)$summary['today'], 0, ',', '.') ?></strong>
    </article>
    <article class="audit-summary-card">
      <span class="audit-summary-label">Últimos 7 días</span>
      <strong><?= number_format((int)$summary['seven_days'], 0, ',', '.') ?></strong>
    </article>
    <article class="audit-summary-card audit-summary-card--attention">
      <span class="audit-summary-label">Sensibles 30 días</span>
      <strong><?= number_format((int)$summary['critical'], 0, ',', '.') ?></strong>
    </article>
  </section>

  <nav class="audit-quick-filters" aria-label="Filtros rápidos">
    <?php foreach ($quickFilters as $chip): ?>
      <?php
        $isAll = empty($chip['criteria']);
        $active = $isAll
          ? ($accion === '' && $entidad === '' && $module === '' && $q === '' && $desde === null && $hasta === null)
          : audit_is_chip_active($chip['criteria']);
      ?>
      <a class="audit-chip <?= $active ? 'is-active' : '' ?>" href="<?= h2((string)$chip['href']) ?>">
        <?= h2((string)$chip['label']) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <form method="get" class="auditoria-filters">
    <div class="grid">
      <div class="field">
        <label for="auditAccion">Acción</label>
        <select id="auditAccion" name="accion">
          <option value="">Todas</option>
          <?php foreach ($actionOptions as $option): ?>
            <?php $value = (string)($option['value'] ?? ''); ?>
            <option value="<?= h2($value) ?>" <?= $accion === $value ? 'selected' : '' ?>>
              <?= h2(audit_action_label($value)) ?> (<?= (int)($option['total'] ?? 0) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="auditModule">Módulo</label>
        <select id="auditModule" name="module">
          <option value="">Todos</option>
          <?php foreach ($moduleOptions as $option): ?>
            <?php $value = (string)($option['value'] ?? ''); ?>
            <option value="<?= h2($value) ?>" <?= $module === $value ? 'selected' : '' ?>>
              <?= h2($value) ?> (<?= (int)($option['total'] ?? 0) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="auditEntidad">Entidad</label>
        <select id="auditEntidad" name="entidad">
          <option value="">Todas</option>
          <?php foreach ($entityOptions as $option): ?>
            <?php $value = (string)($option['value'] ?? ''); ?>
            <option value="<?= h2($value) ?>" <?= $entidad === $value ? 'selected' : '' ?>>
              <?= h2(audit_entity_label($value)) ?> (<?= (int)($option['total'] ?? 0) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="auditDesde">Desde</label>
        <input id="auditDesde" type="date" name="desde" value="<?= h2($desde ?? '') ?>">
      </div>
      <div class="field">
        <label for="auditHasta">Hasta</label>
        <input id="auditHasta" type="date" name="hasta" value="<?= h2($hasta ?? '') ?>">
      </div>
      <div class="field field--wide">
        <label for="auditBuscar">Buscar</label>
        <input id="auditBuscar" type="text" name="q" value="<?= h2($q) ?>" placeholder="Usuario, venta, terminal, request, IP o texto del detalle">
      </div>
      <div class="field">
        <label for="auditPerPage">Mostrar</label>
        <select id="auditPerPage" name="per_page">
          <?php foreach ([20, 50, 100] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> por página</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="actions">
      <button class="btn btn-primary" type="submit">Aplicar filtros</button>
      <a class="btn btn-secondary" href="auditoria.php">Limpiar</a>
    </div>
  </form>

  <div class="audit-result-head">
    <div>
      <strong><?= number_format($totalRows, 0, ',', '.') ?> eventos</strong>
      <span class="muted">según los filtros actuales</span>
    </div>
    <?php if ($summary['last_at']): ?>
      <span class="muted">Último evento: <?= h2(audit_fmt_dt((string)$summary['last_at'])) ?></span>
    <?php endif; ?>
  </div>

  <div class="table-wrap">
    <table class="table audit-table">
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
          <tr><td colspan="7" class="muted">Sin resultados para los filtros elegidos.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $action = (string)($r['action'] ?? '');
              $entity = (string)($r['entity'] ?? '');
              $metaRaw   = (string)($r['meta'] ?? '');
              $metaArr   = audit_meta_decode($metaRaw);
              $metaShort = audit_meta_summary($metaArr);
              $metaPretty = $metaArr ? json_encode($metaArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : audit_pretty_json($metaRaw);

              $beforePretty = audit_pretty_json((string)($r['before_json'] ?? ''));
              $afterPretty  = audit_pretty_json((string)($r['after_json'] ?? ''));

              $idVal  = $r['entity_id'] ?? null;
              $idShow = ($idVal === null || $idVal === '' || (int)$idVal === 0) ? '—' : (string)(int)$idVal;

              $ip  = (string)($r['ip'] ?? '');
              $ua  = (string)($r['user_agent'] ?? '');
              $rid = (string)($r['request_id'] ?? '');
              $badgeClass = audit_badge_class($action, $entity);
            ?>
            <tr>
              <td><?= h2(audit_fmt_dt($r['created_at'] ?? '')) ?></td>
              <td><?= h2($r['username'] ?? 'Sistema') ?></td>
              <td><span class="audit-module"><?= h2((string)($r['module'] ?? '')) ?></span></td>
              <td>
                <span class="audit-badge audit-badge--<?= h2($badgeClass) ?>"><?= h2(audit_action_label($action)) ?></span>
                <code class="audit-code"><?= h2($action) ?></code>
              </td>
              <td><?= h2(audit_entity_label($entity)) ?></td>
              <td><?= h2($idShow) ?></td>

              <td class="meta">
                <details class="meta-details">
                  <summary><?= h2($metaShort !== '' ? $metaShort : 'Ver detalle técnico') ?></summary>

                  <?php if ($ip || $rid || $ua): ?>
                    <div class="meta-kv">
                      <?php if ($ip): ?><div><b>IP:</b> <?= h2($ip) ?></div><?php endif; ?>
                      <?php if ($rid): ?><div><b>Request:</b> <?= h2($rid) ?></div><?php endif; ?>
                      <?php if ($ua): ?><div><b>Navegador:</b> <?= h2($ua) ?></div><?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <?php if (trim($metaPretty) !== ''): ?>
                    <div class="meta-block">
                      <div class="meta-title">Detalle</div>
                      <pre class="meta-pre"><?= h2($metaPretty) ?></pre>
                    </div>
                  <?php endif; ?>

                  <?php if ($beforePretty !== '' || $afterPretty !== ''): ?>
                    <div class="meta-block">
                      <div class="meta-title">Antes</div>
                      <pre class="meta-pre"><?= h2($beforePretty ?: '—') ?></pre>
                    </div>
                    <div class="meta-block">
                      <div class="meta-title">Después</div>
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
    unset($base['export']);
    $base['per_page'] = $perPage;
  ?>
  <div class="pager">
    <span class="muted">Página <?= (int)$page ?> de <?= (int)$totalPages ?></span>
    <div class="pager-links">
      <?php
        $prev = max(1, $page - 1);
        $next = min($totalPages, $page + 1);

        $base['page'] = $prev;
        $prevUrl = 'auditoria.php?' . http_build_query($base);

        $base['page'] = $next;
        $nextUrl = 'auditoria.php?' . http_build_query($base);
      ?>
      <a class="btn btn-sm btn-secondary <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= h2($prevUrl) ?>">‹</a>

      <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        for ($p = $start; $p <= $end; $p++):
          $base['page'] = $p;
          $url = 'auditoria.php?' . http_build_query($base);
      ?>
        <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>" href="<?= h2($url) ?>"><?= (int)$p ?></a>
      <?php endfor; ?>

      <a class="btn btn-sm btn-secondary <?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= h2($nextUrl) ?>">›</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
