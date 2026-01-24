<?php
// public/caja_historial.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('ver_historial_caja');

/* --------------------------------------------------------
   Helpers locales (chicos y seguros)
-------------------------------------------------------- */
function is_open_dt($dt): bool {
  if ($dt === null) return true;
  $s = trim((string)$dt);
  return ($s === '' || $s === '0000-00-00 00:00:00');
}

function normalize_estado(?string $v): string {
  $v = trim((string)$v);
  return in_array($v, ['abierta', 'cerrada'], true) ? $v : '';
}

function get_bool_get(string $key): bool {
  $v = $_GET[$key] ?? null;
  return ($v === '1' || $v === 'true' || $v === 'on');
}

/* --------------------------------------------------------
   FILTROS Y PAGINACIÓN
-------------------------------------------------------- */
$filtro_usuario = sanitize_int($_GET['usuario'] ?? 0);
$filtro_estado  = normalize_estado($_GET['estado'] ?? '');
$filtro_desde   = validDateYmd($_GET['desde'] ?? null);
$filtro_hasta   = validDateYmd($_GET['hasta'] ?? null);
$solo_dif       = get_bool_get('dif');

$page     = max(1, sanitize_int($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$error_msg = null;
$filas = [];
$total_sesiones = 0;
$stats = [
  'ventas_sum' => 0.0,
  'dif_sum' => 0.0,
  'abiertas_sum' => 0,
  'anul_sum' => 0,
  'prod_sum' => 0,
];

$whereClause = '';
$params = [];

/* --------------------------------------------------------
   CONSTRUIR WHERE + COUNT + STATS
-------------------------------------------------------- */
try {
  $where  = [];
  $params = [];

  if ($filtro_usuario > 0) {
    $where[] = "cs.user_id = :user_id";
    $params[':user_id'] = $filtro_usuario;
  }

  if ($filtro_estado === 'abierta') {
    $where[] = "(cs.fecha_cierre IS NULL OR cs.fecha_cierre = '' OR cs.fecha_cierre = '0000-00-00 00:00:00')";
  } elseif ($filtro_estado === 'cerrada') {
    $where[] = "(cs.fecha_cierre IS NOT NULL AND cs.fecha_cierre <> '' AND cs.fecha_cierre <> '0000-00-00 00:00:00')";
  }

  if ($filtro_desde) {
    $where[] = "DATE(cs.fecha_apertura) >= :desde";
    $params[':desde'] = $filtro_desde;
  }

  if ($filtro_hasta) {
    $where[] = "DATE(cs.fecha_apertura) <= :hasta";
    $params[':hasta'] = $filtro_hasta;
  }

  if ($solo_dif) {
    $where[] = "ABS(COALESCE(cs.diferencia,0)) > 0.00001";
  }

  $whereClause = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

  // COUNT
  $sqlCount = "SELECT COUNT(*) FROM caja_sesiones cs {$whereClause}";
  $stCount = $pdo->prepare($sqlCount);
  $stCount->execute($params);
  $total_sesiones = (int)$stCount->fetchColumn();

  // STATS (sobre el mismo set filtrado)
  $sqlStats = "
    SELECT
      COALESCE(SUM(cs.total_ventas),0)        AS ventas_sum,
      COALESCE(SUM(cs.diferencia),0)          AS dif_sum,
      COALESCE(SUM(cs.total_anulaciones),0)   AS anul_sum,
      COALESCE(SUM(cs.total_productos),0)     AS prod_sum,
      COALESCE(SUM(
        CASE
          WHEN (cs.fecha_cierre IS NULL OR cs.fecha_cierre = '' OR cs.fecha_cierre = '0000-00-00 00:00:00')
          THEN 1 ELSE 0
        END
      ),0) AS abiertas_sum
    FROM caja_sesiones cs
    {$whereClause}
  ";
  $stStats = $pdo->prepare($sqlStats);
  $stStats->execute($params);
  $rowStats = $stStats->fetch(PDO::FETCH_ASSOC) ?: [];

  $stats['ventas_sum']   = (float)($rowStats['ventas_sum'] ?? 0);
  $stats['dif_sum']      = (float)($rowStats['dif_sum'] ?? 0);
  $stats['anul_sum']     = (int)($rowStats['anul_sum'] ?? 0);
  $stats['prod_sum']     = (int)($rowStats['prod_sum'] ?? 0);
  $stats['abiertas_sum'] = (int)($rowStats['abiertas_sum'] ?? 0);

} catch (PDOException $e) {
  error_log("Error COUNT/STATS caja_historial: " . $e->getMessage());
  $error_msg = "Error al cargar el historial de caja";
  $total_sesiones = 0;
}

/* --------------------------------------------------------
   CALCULAR PAGINACIÓN
-------------------------------------------------------- */
$total_pages = $total_sesiones > 0 ? (int)ceil($total_sesiones / $per_page) : 1;
$total_pages = max(1, $total_pages);

$page   = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

/* --------------------------------------------------------
   QUERY PRINCIPAL (página actual)
-------------------------------------------------------- */
try {
  $sql = "
    SELECT
      cs.id,
      cs.fecha_apertura,
      cs.fecha_cierre,
      cs.saldo_inicial,
      cs.saldo_sistema,
      cs.saldo_declarado,
      cs.diferencia,
      cs.total_ventas,
      cs.total_efectivo,
      cs.total_mp,
      cs.total_debito,
      cs.total_credito,
      cs.total_productos,
      cs.total_anulaciones,
      u.username,
      cs.user_id
    FROM caja_sesiones cs
    LEFT JOIN users u ON u.id = cs.user_id
    {$whereClause}
    ORDER BY cs.id DESC
    LIMIT :limit OFFSET :offset
  ";

  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  $st->bindValue(':limit', $per_page, PDO::PARAM_INT);
  $st->bindValue(':offset', $offset, PDO::PARAM_INT);
  $st->execute();

  $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  error_log("Error QUERY caja_historial: " . $e->getMessage());
  $error_msg = $error_msg ?: "Error al cargar el historial de caja";
  $filas = [];
}

/* --------------------------------------------------------
   LISTAR USUARIOS PARA FILTRO (TODOS)
-------------------------------------------------------- */
$usuarios = [];
try {
  $stUsers = $pdo->query("
    SELECT id, username
    FROM users
    ORDER BY username ASC
  ");
  $usuarios = $stUsers->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  error_log("Error listando usuarios: " . $e->getMessage());
}

/* --------------------------------------------------------
   Header global
-------------------------------------------------------- */
$pageTitle      = 'Historial de caja - FLUS';
$currentSection = 'caja_historial';
$extraCss       = ['assets/css/caja_historial.css'];
$extraJs        = ['assets/js/caja_historial.js'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel hist-panel">
  <div class="hist-head">
    <div>
      <h1 class="hist-title">Historial de caja</h1>
      <p class="hist-sub">Revisión rápida de aperturas/cierres (lo detallado está en el detalle de sesión).</p>
    </div>
  </div>

  <?php if ($error_msg): ?>
    <div class="alert alert-error"><?= h($error_msg) ?></div>
  <?php endif; ?>

  <!-- ========== RESUMEN ========== -->
  <div class="hist-kpis">
    <div class="hkpi">
      <div class="hkpi-label">Sesiones</div>
      <div class="hkpi-value mono"><?= (int)$total_sesiones ?></div>
      <div class="hkpi-sub">según filtros</div>
    </div>
    <div class="hkpi">
      <div class="hkpi-label">Abiertas</div>
      <div class="hkpi-value mono"><?= (int)$stats['abiertas_sum'] ?></div>
      <div class="hkpi-sub">en el rango</div>
    </div>
    <div class="hkpi">
      <div class="hkpi-label">Ventas</div>
      <div class="hkpi-value"><?= money_ar((float)$stats['ventas_sum']) ?></div>
      <div class="hkpi-sub">total rango</div>
    </div>
    <div class="hkpi">
      <div class="hkpi-label">Diferencia</div>
      <?php
        $difSum = (float)$stats['dif_sum'];
        $difSumClass = $difSum > 0.00001 ? 'pill pill-pos' : ($difSum < -0.00001 ? 'pill pill-neg' : 'pill pill-zero');
      ?>
      <div class="hkpi-value"><span class="<?= h($difSumClass) ?>"><?= money_ar($difSum) ?></span></div>
      <div class="hkpi-sub">acumulada</div>
    </div>
  </div>

  <!-- ========== FILTROS ========== -->
  <form method="get" class="hist-filters">
    <div class="filter-row">
      <label class="field">
        <span class="label">Usuario</span>
        <select name="usuario">
          <option value="0">Todos</option>
          <?php foreach ($usuarios as $u): ?>
            <?php $uid = (int)($u['id'] ?? 0); ?>
            <option value="<?= $uid ?>" <?= $uid === $filtro_usuario ? 'selected' : '' ?>>
              <?= h((string)($u['username'] ?? '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field">
        <span class="label">Estado</span>
        <select name="estado">
          <option value="" <?= $filtro_estado === '' ? 'selected' : '' ?>>Todos</option>
          <option value="abierta" <?= $filtro_estado === 'abierta' ? 'selected' : '' ?>>Abiertas</option>
          <option value="cerrada" <?= $filtro_estado === 'cerrada' ? 'selected' : '' ?>>Cerradas</option>
        </select>
      </label>

      <label class="field">
        <span class="label">Desde</span>
        <input type="date" name="desde" value="<?= h((string)$filtro_desde) ?>">
      </label>

      <label class="field">
        <span class="label">Hasta</span>
        <input type="date" name="hasta" value="<?= h((string)$filtro_hasta) ?>">
      </label>

      <div class="field field-inline">
        <span class="label">Solo con diferencias</span>
        <label class="toggle">
          <input type="checkbox" name="dif" value="1" <?= $solo_dif ? 'checked' : '' ?>>
          <span>Mostrar solo sesiones con diferencia ≠ 0</span>
        </label>
      </div>

      <div class="filter-actions">
        <button class="btn">Aplicar</button>
        <a class="btn btn-secondary btn-sm" href="caja_historial.php">Limpiar</a>
      </div>
    </div>
  </form>

  <div class="hist-summary">
    <strong>Mostrando:</strong> <?= count($filas) ?> de <?= (int)$total_sesiones ?>
    <?php if ($filtro_usuario || $filtro_estado || $filtro_desde || $filtro_hasta || $solo_dif): ?>
      <span class="badge badge-info">Filtros activos</span>
    <?php endif; ?>
  </div>

  <!-- ========== TABLA ========== -->
  <div class="table-wrapper hist-table-wrapper" role="region" aria-label="Historial de caja" tabindex="0">
    <table class="hist-table">
      <thead>
        <tr>
          <th class="t-left">#</th>
          <th class="t-left">Usuario</th>
          <th class="t-left">Apertura</th>
          <th class="t-left">Cierre</th>
          <th class="t-right">Ventas</th>
          <th class="t-right">Diferencia</th>
          <th class="t-center col-actions">Acciones</th>
        </tr>
      </thead>

      <tbody>
        <?php if (!$filas): ?>
          <tr>
            <td colspan="7" class="t-center hist-empty">No hay sesiones para mostrar.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($filas as $r): ?>
            <?php
              $id       = (int)($r['id'] ?? 0);
              $username = (string)($r['username'] ?? '—');

              $apertura_raw = $r['fecha_apertura'] ?? '';
              $cierre_raw   = $r['fecha_cierre'] ?? null;

              $isOpen   = is_open_dt($cierre_raw);

              $ventas   = (float)($r['total_ventas'] ?? 0);

              $dif      = (float)($r['diferencia'] ?? 0);
              $difClass = $dif > 0.00001 ? 'pill pill-pos' : ($dif < -0.00001 ? 'pill pill-neg' : 'pill pill-zero');
            ?>

            <tr class="<?= $isOpen ? 'row-open' : '' ?>">
              <td class="mono"><?= $id ?></td>
              <td><?= h($username) ?></td>

              <td class="mono nowrap"><?= h(format_datetime_ar((string)$apertura_raw)) ?></td>

              <td class="mono nowrap">
                <?php if ($isOpen): ?>
                  <span class="pill pill-open">Abierta</span>
                <?php else: ?>
                  <?= h(format_datetime_ar((string)$cierre_raw)) ?>
                <?php endif; ?>
              </td>

              <td class="t-right"><?= money_ar($ventas) ?></td>

              <td class="t-right">
                <span class="<?= h($difClass) ?>"><?= money_ar($dif) ?></span>
              </td>

              <td class="t-center col-actions">
                <div class="actions">
                  <button type="button"
                          class="btn-icon js-toggle-details"
                          title="Mostrar/Ocultar resumen"
                          aria-label="Mostrar/Ocultar resumen"
                          aria-expanded="false"
                          data-id="<?= $id ?>">▾</button>

                  <a href="caja_sesion_detalle.php?id=<?= $id ?>" class="btn-icon" title="Ver detalle" aria-label="Ver detalle">👁️</a>
                  <a href="caja_sesion_print.php?id=<?= $id ?>" class="btn-icon" title="Imprimir" target="_blank" aria-label="Imprimir">🖨️</a>
                </div>
              </td>
            </tr>

            <tr class="row-details" data-details="<?= $id ?>" hidden>
              <td colspan="7">
                <div class="details-grid">
                  <div class="detail-block">
                    <div class="detail-title">Saldos</div>
                    <div class="detail-row"><span>Inicial</span><strong><?= money_ar((float)($r['saldo_inicial'] ?? 0)) ?></strong></div>
                    <div class="detail-row"><span>Sistema</span><strong><?= money_ar((float)($r['saldo_sistema'] ?? 0)) ?></strong></div>
                    <div class="detail-row"><span>Declarado</span><strong><?= money_ar((float)($r['saldo_declarado'] ?? 0)) ?></strong></div>
                  </div>

                  <div class="detail-block">
                    <div class="detail-title">Pagos</div>
                    <div class="detail-row"><span>Efectivo</span><strong><?= money_ar((float)($r['total_efectivo'] ?? 0)) ?></strong></div>
                    <div class="detail-row"><span>MP</span><strong><?= money_ar((float)($r['total_mp'] ?? 0)) ?></strong></div>
                    <div class="detail-row"><span>Débito</span><strong><?= money_ar((float)($r['total_debito'] ?? 0)) ?></strong></div>
                    <div class="detail-row"><span>Crédito</span><strong><?= money_ar((float)($r['total_credito'] ?? 0)) ?></strong></div>
                  </div>

                  <div class="detail-block">
                    <div class="detail-title">Otros</div>
                    <div class="detail-row"><span>Productos</span><strong><?= (int)($r['total_productos'] ?? 0) ?></strong></div>
                    <div class="detail-row"><span>Anulaciones</span><strong><?= (int)($r['total_anulaciones'] ?? 0) ?></strong></div>
                    <div class="detail-row"><span>Usuario ID</span><strong class="mono"><?= (int)($r['user_id'] ?? 0) ?></strong></div>
                  </div>
                </div>
              </td>
            </tr>

          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ========== PAGINACIÓN ========== -->
  <?php if ($total_pages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?= h(url_with(['page' => $page - 1])) ?>" class="btn btn-sm">← Anterior</a>
      <?php endif; ?>

      <span class="page-info">Página <?= (int)$page ?> de <?= (int)$total_pages ?></span>

      <?php if ($page < $total_pages): ?>
        <a href="<?= h(url_with(['page' => $page + 1])) ?>" class="btn btn-sm">Siguiente →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
