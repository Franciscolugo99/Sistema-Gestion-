<?php
// public/caja_historial.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('ver_historial_caja');
/* --------------------------------------------------------
   FUNCIONES AUXILIARES
-------------------------------------------------------- */

/* --------------------------------------------------------
   FILTROS Y PAGINACIÓN
-------------------------------------------------------- */
$filtro_usuario = sanitize_int($_GET['usuario'] ?? 0);
$filtro_estado  = trim((string)($_GET['estado'] ?? ''));
$filtro_desde   = validDateYmd($_GET['desde'] ?? null);
$filtro_hasta   = validDateYmd($_GET['hasta'] ?? null);

$page     = max(1, sanitize_int($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$error_msg = null;
$filas = [];
$total_sesiones = 0;

$whereClause = '';
$params = [];

/* --------------------------------------------------------
   CONSTRUIR QUERY CON FILTROS + COUNT
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

  $whereClause = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

  $sqlCount = "SELECT COUNT(*) FROM caja_sesiones cs {$whereClause}";
  $stCount = $pdo->prepare($sqlCount);
  $stCount->execute($params);
  $total_sesiones = (int)$stCount->fetchColumn();
} catch (PDOException $e) {
  error_log("Error COUNT caja_historial: " . $e->getMessage());
  $error_msg = "Error al cargar el historial de caja";
  $total_sesiones = 0;
}

/* --------------------------------------------------------
   CALCULAR PAGINACIÓN
-------------------------------------------------------- */
$total_pages = $total_sesiones > 0 ? (int)ceil($total_sesiones / $per_page) : 1;
$total_pages = max(1, $total_pages);

$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

/* --------------------------------------------------------
   QUERY PRINCIPAL
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
    ORDER BY username
  ");
  $usuarios = $stUsers ? ($stUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (PDOException $e) {
  error_log("Error listando usuarios: " . $e->getMessage());
}


/* --------------------------------------------------------
   Header global
-------------------------------------------------------- */
$pageTitle      = 'Historial de caja - FLUS';
$currentSection = 'caja_historial';
$extraCss       = ['assets/css/caja_historial.css?v=3'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel hist-panel">
  <div class="hist-head">
    <div>
      <h1 class="hist-title">Historial de caja</h1>
      <p class="hist-sub">Últimas sesiones de caja (aperturas y cierres).</p>
    </div>
  </div>

  <?php if ($error_msg): ?>
    <div class="alert alert-error"><?= h($error_msg) ?></div>
  <?php endif; ?>

  <!-- ========== FILTROS ========== -->
  <form method="get" class="hist-filters">
    <div class="filter-row">
      <div class="filter-group">
        <label for="usuario">Usuario:</label>
        <select name="usuario" id="usuario">
          <option value="0">Todos</option>
          <?php foreach ($usuarios as $u): ?>
            <?php $uid = (int)($u['id'] ?? 0); ?>
            <option value="<?= $uid ?>" <?= $filtro_usuario === $uid ? 'selected' : '' ?>>
              <?= h((string)($u['username'] ?? '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-group">
        <label for="estado">Estado:</label>
        <select name="estado" id="estado">
          <option value="" <?= $filtro_estado === '' ? 'selected' : '' ?>>Todos</option>
          <option value="abierta" <?= $filtro_estado === 'abierta' ? 'selected' : '' ?>>Abierta</option>
          <option value="cerrada" <?= $filtro_estado === 'cerrada' ? 'selected' : '' ?>>Cerrada</option>
        </select>
      </div>

      <div class="filter-group">
        <label for="desde">Desde:</label>
        <input type="date" name="desde" id="desde" value="<?= h($filtro_desde ?? '') ?>">
      </div>

      <div class="filter-group">
        <label for="hasta">Hasta:</label>
        <input type="date" name="hasta" id="hasta" value="<?= h($filtro_hasta ?? '') ?>">
      </div>

      <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <a href="caja_historial.php" class="btn btn-secondary">Limpiar</a>
      </div>
    </div>
  </form>

  <!-- ========== RESUMEN ========== -->
  <div class="hist-summary">
    <strong>Total sesiones:</strong> <?= (int)$total_sesiones ?>
    <?php if ($filtro_usuario || $filtro_estado || $filtro_desde || $filtro_hasta): ?>
      <span class="badge badge-info">Filtros activos</span>
    <?php endif; ?>
  </div>

  <!-- ========== TABLA ========== -->
  <div class="hist-table-wrapper" role="region" aria-label="Historial de caja" tabindex="0">
    <table class="hist-table">
      <thead>
        <tr>
          <th class="t-left">#</th>
          <th class="t-left">Usuario</th>
          <th class="t-left">Apertura</th>
          <th class="t-left">Cierre</th>
          <th class="t-right">Saldo inicial</th>
          <th class="t-right">Total sistema</th>
          <th class="t-right">Declarado</th>
          <th class="t-right">Diferencia</th>
          <th class="t-right">Efectivo</th>
          <th class="t-right">MP</th>
          <th class="t-right">Débito</th>
          <th class="t-right">Crédito</th>
          <th class="t-right">Productos</th>
          <th class="t-right">Anulaciones</th>
          <th class="t-center col-actions">Acciones</th>
        </tr>
      </thead>

      <tbody>
        <?php if (!$filas): ?>
          <tr>
            <td colspan="15" class="t-center hist-empty">
              No hay sesiones para mostrar.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($filas as $r): ?>
            <?php
              $id        = (int)($r['id'] ?? 0);
              $username  = (string)($r['username'] ?? '—');

              $apertura  = (string)($r['fecha_apertura'] ?? '');
              $cierre    = (string)($r['fecha_cierre'] ?? '');

              $dif       = (float)($r['diferencia'] ?? 0);
              $difClass  = $dif > 0.00001 ? 'pill pill-pos' : ($dif < -0.00001 ? 'pill pill-neg' : 'pill pill-zero');

              $isOpen    = ($cierre === '' || $cierre === '0000-00-00 00:00:00' || $cierre === null);
            ?>

            <tr class="<?= $isOpen ? 'row-open' : '' ?>">
              <td class="mono"><?= $id ?></td>
              <td><?= h($username) ?></td>

              <td class="mono nowrap"><?= h(format_datetime_ar($apertura)) ?></td>

              <td class="mono nowrap">
                <?php if ($isOpen): ?>
                  <span class="pill pill-open">Abierta</span>
                <?php else: ?>
                  <?= h(format_datetime_ar($cierre)) ?>
                <?php endif; ?>
              </td>

              <td class="t-right"><?= money_ar($r['saldo_inicial'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['saldo_sistema'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['saldo_declarado'] ?? 0) ?></td>

              <td class="t-right">
                <span class="<?= h($difClass) ?>"><?= money_ar($dif) ?></span>
              </td>

              <td class="t-right"><?= money_ar($r['total_efectivo'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['total_mp'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['total_debito'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['total_credito'] ?? 0) ?></td>

              <td class="t-right"><?= (int)($r['total_productos'] ?? 0) ?></td>
              <td class="t-right"><?= (int)($r['total_anulaciones'] ?? 0) ?></td>

              <td class="t-center col-actions">
                <div class="actions">
                  <a href="caja_sesion_detalle.php?id=<?= $id ?>" class="btn-icon" title="Ver detalle" aria-label="Ver detalle">👁️</a>
                  <a href="caja_sesion_print.php?id=<?= $id ?>" class="btn-icon" title="Imprimir" target="_blank" aria-label="Imprimir">🖨️</a>
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

      <span class="page-info">
        Página <?= (int)$page ?> de <?= (int)$total_pages ?>
      </span>

      <?php if ($page < $total_pages): ?>
        <a href="<?= h(url_with(['page' => $page + 1])) ?>" class="btn btn-sm">Siguiente →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
