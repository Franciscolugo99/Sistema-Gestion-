<?php
// public/caja_historial.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('ver_historial_caja');

require_once __DIR__ . '/lib/terminal.php';
require_once __DIR__ . '/../src/venta_anulaciones_lib.php';

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

function dur_label(int $minutes): string {
  if ($minutes < 0) $minutes = 0;
  $d = intdiv($minutes, 1440);
  $h = intdiv($minutes % 1440, 60);
  $m = $minutes % 60;

  if ($d > 0) return sprintf('%dd %dh', $d, $h);
  if ($h > 0) return sprintf('%dh %02dm', $h, $m);
  return sprintf('%dm', $m);
}

function url_with_query(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null || $v === '') {
      unset($q[$k]);
    } else {
      $q[$k] = $v;
    }
  }
  $base = basename((string)parse_url($_SERVER['REQUEST_URI'] ?? 'caja_historial.php', PHP_URL_PATH));
  $qs = http_build_query($q);
  return $qs ? ($base . '?' . $qs) : $base;
}


// Bind seguro de parámetros (evita HY093 por params sobrantes, y no reusa placeholders)
function flus_bind_params(PDOStatement $st, array $params): void {
  foreach ($params as $k => $v) {
    if (is_int($v)) {
      $st->bindValue($k, $v, PDO::PARAM_INT);
    } elseif (is_float($v)) {
      $st->bindValue($k, (string)$v, PDO::PARAM_STR);
    } elseif ($v === null) {
      $st->bindValue($k, null, PDO::PARAM_NULL);
    } else {
      $st->bindValue($k, (string)$v, PDO::PARAM_STR);
    }
  }
}


// Nota: evitamos usar placeholders repetidos (p.ej. :long_min varias veces)
// porque con PDO + MySQL/MariaDB (emulación OFF) dispara HY093.
// Para filtros opcionales bind-eamos SOLO los params que agregamos al WHERE.

/* --------------------------------------------------------
   Constantes UI
-------------------------------------------------------- */
$LONG_MIN      = 12 * 60;  // 12h
$VERY_LONG_MIN = 24 * 60;  // 24h

$hasTransferCol = function_exists('flus_column_exists')
  ? flus_column_exists($pdo, 'caja_sesiones', 'total_transferencia')
  : true;
$movTableExists = function_exists('flus_table_exists') ? flus_table_exists($pdo, 'caja_movimientos') : false;
$hasMovCcCol = $movTableExists && function_exists('flus_column_exists')
  ? flus_column_exists($pdo, 'caja_movimientos', 'cc_movimiento_id')
  : false;
$hasVentasMontoCcCol = function_exists('flus_column_exists')
  ? flus_column_exists($pdo, 'ventas', 'monto_cc')
  : false;

$transferExpr = $hasTransferCol ? 'COALESCE(cs.total_transferencia,0)' : '0';
$mediosExpr = "(COALESCE(cs.total_efectivo,0)+COALESCE(cs.total_mp,0)+COALESCE(cs.total_debito,0)+COALESCE(cs.total_credito,0)+{$transferExpr})";
$ventasCcExpr = '0';
if ($hasVentasMontoCcCol) {
  $ventasCcAnulJoin = flus_venta_anulaciones_totales_join_sql($pdo, 'vcc', 'vcc_vaa');
  $ventasCcAnuladoExpr = $ventasCcAnulJoin !== '' ? 'COALESCE(vcc_vaa.monto_anulado_total,0)' : '0';
  $ventasCcVigenteExpr = flus_venta_cc_vigente_expr_sql(
    'COALESCE(vcc.monto_cc,0)',
    'COALESCE(vcc.total,0)',
    $ventasCcAnuladoExpr
  );
  $ventasCcExpr = "(
    SELECT COALESCE(SUM({$ventasCcVigenteExpr}),0)
    FROM ventas vcc
    {$ventasCcAnulJoin}
    WHERE vcc.caja_id = cs.id
      AND (vcc.estado IS NULL OR UPPER(vcc.estado) <> 'ANULADA')
  )";
}
$cobrosCcExpr = $hasMovCcCol ? "(
  SELECT COALESCE(SUM(CASE
    WHEN UPPER(cmcc.tipo) = 'INGRESO' THEN cmcc.monto
    WHEN UPPER(cmcc.tipo) = 'EGRESO' THEN -cmcc.monto
    ELSE 0
  END),0)
  FROM caja_movimientos cmcc
  WHERE cmcc.caja_id = cs.id
    AND COALESCE(cmcc.cc_movimiento_id,0) > 0
)" : '0';
$mediosBaseExpr = "(COALESCE(cs.total_ventas,0)-{$ventasCcExpr}+{$cobrosCcExpr})";

$condDif    = "ABS(COALESCE(cs.diferencia,0)) > 0.00001";
$condMedios = "ABS({$mediosBaseExpr} - {$mediosExpr}) > 0.009";
$endExpr    = "IF(cs.fecha_cierre IS NULL OR cs.fecha_cierre = '' OR cs.fecha_cierre = '0000-00-00 00:00:00', NOW(), cs.fecha_cierre)";
$condLong   = "TIMESTAMPDIFF(MINUTE, cs.fecha_apertura, {$endExpr}) >= " . (int)$LONG_MIN;

/* --------------------------------------------------------
   Permisos extra (solo para auditoría)
-------------------------------------------------------- */
$canAudit = user_has_permission('ver_auditoria') || user_has_permission('administrar_config');

$auditFlagPath = FLUS_ROOT . '/storage/audit.disabled';
$auditDisabled = is_file($auditFlagPath);

$auditTableExistsRaw = function_exists('flus_table_exists') ? flus_table_exists($pdo, 'caja_auditoria') : false;
// "Activa" = tabla existe y NO está desactivada por flag (no borra datos)
$auditTableExists = $auditTableExistsRaw && !$auditDisabled;

/* --------------------------------------------------------
   POST: setup auditoría / guardar auditoría
-------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['_action'] ?? '');
  $token  = (string)($_POST['csrf_token'] ?? '');
  $return = (string)($_SERVER['REQUEST_URI'] ?? 'caja_historial.php');

  // Evitar redirects raros
  if (str_contains($return, '://')) $return = 'caja_historial.php';

  if (!function_exists('csrf_verify') || !csrf_verify($token)) {
    $_SESSION['flash_error'] = 'Token inválido. Recargá la página e intentá de nuevo.';
    header('Location: ' . $return);
    exit;
  }

  if ($action === 'audit_setup') {
    if (!$canAudit) {
      $_SESSION['flash_error'] = 'No tenés permisos para habilitar auditoría.';
      header('Location: ' . $return);
      exit;
    }

    if (!$auditTableExistsRaw) {
      $_SESSION['flash_error'] = 'Falta la tabla caja_auditoria. Aplicá primero la migración migrations/007_support_modules_schema.sql.';
      header('Location: ' . $return);
      exit;
    }

    try {
      if (is_file($auditFlagPath)) { @unlink($auditFlagPath); }
      $auditDisabled = false;
      $auditTableExists = true;
      $_SESSION['flash_ok'] = 'Auditoría habilitada.';
    } catch (Throwable $e) {
      $_SESSION['flash_error'] = 'No se pudo habilitar auditoría: ' . $e->getMessage();
    }

    header('Location: ' . $return);
    exit;
  }


  if ($action === 'audit_disable' || $action === 'audit_enable') {
    if (!$canAudit) {
      $_SESSION['flash_error'] = 'No tenés permisos para administrar auditoría.';
      header('Location: ' . $return);
      exit;
    }

    // storage/ puede no existir en algunas instalaciones
    $storageDir = dirname($auditFlagPath);
    if (!is_dir($storageDir)) {
      @mkdir($storageDir, 0775, true);
    }

    if ($action === 'audit_disable') {
      try {
        if (@file_put_contents($auditFlagPath, 'disabled ' . date('c')) === false) {
          throw new RuntimeException('No se pudo escribir el flag en storage/.');
        }
        $_SESSION['flash_ok'] = 'Auditoría desactivada (no se borraron datos).';
      } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'No se pudo desactivar auditoría: ' . $e->getMessage();
      }
    } else { // audit_enable
      try {
        if (is_file($auditFlagPath) && !@unlink($auditFlagPath)) {
          throw new RuntimeException('No se pudo eliminar el flag de storage/.');
        }
        $_SESSION['flash_ok'] = 'Auditoría reactivada.';
      } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'No se pudo reactivar auditoría: ' . $e->getMessage();
      }
    }

    header('Location: ' . $return);
    exit;
  }

  if ($action === 'audit_save') {
    if (!$canAudit) {
      $_SESSION['flash_error'] = 'No tenés permisos para auditar.';
      header('Location: ' . $return);
      exit;
    }

    if (!$auditTableExists) {
      $_SESSION['flash_error'] = 'Auditoría no está habilitada (falta tabla caja_auditoria).';
      header('Location: ' . $return);
      exit;
    }

    $cajaId  = (int)($_POST['caja_id'] ?? 0);
    $status  = strtoupper(trim((string)($_POST['status'] ?? '')));
    $nota    = trim((string)($_POST['nota'] ?? ''));

    $allowed = ['PENDIENTE', 'REVISADA', 'OBSERVADA'];
    if (!in_array($status, $allowed, true)) $status = 'PENDIENTE';
    if (mb_strlen($nota) > 3000) $nota = mb_substr($nota, 0, 3000);

    if ($cajaId <= 0) {
      $_SESSION['flash_error'] = 'Caja inválida.';
      header('Location: ' . $return);
      exit;
    }

    $uid = (int)(current_user()['id'] ?? 0);

    try {
      $st = $pdo->prepare("INSERT INTO caja_auditoria (caja_id, status, nota, audited_by, audited_at)
        VALUES (:id, :st, :nota, :uid, NOW())
        ON DUPLICATE KEY UPDATE
          status = VALUES(status),
          nota = VALUES(nota),
          audited_by = VALUES(audited_by),
          audited_at = VALUES(audited_at)
      ");
      $st->execute([
        ':id' => $cajaId,
        ':st' => $status,
        ':nota' => ($nota !== '' ? $nota : null),
        ':uid' => ($uid > 0 ? $uid : null),
      ]);
      $_SESSION['flash_ok'] = 'Auditoría guardada.';
    } catch (Throwable $e) {
      $_SESSION['flash_error'] = 'No se pudo guardar auditoría: ' . $e->getMessage();
    }

    header('Location: ' . $return . '#caja-' . $cajaId);
    exit;
  }
}

/* --------------------------------------------------------
   GET: filtros
-------------------------------------------------------- */
$f_usuario  = sanitize_int($_GET['usuario'] ?? 0);
$f_terminal = sanitize_int($_GET['terminal'] ?? 0);
$f_estado   = normalize_estado($_GET['estado'] ?? '');
$f_desde    = validDateYmd($_GET['desde'] ?? null);
$f_hasta    = validDateYmd($_GET['hasta'] ?? null);

$f_dif      = get_bool_get('dif');
$f_medios   = get_bool_get('medios');
$f_anom     = get_bool_get('anom');

$per_page = (int)($_GET['limit'] ?? ($_GET['per_page'] ?? ($_GET['pp'] ?? 25)));
if (!in_array($per_page, [10, 25, 50, 100], true)) $per_page = 25;
$page   = max(1, sanitize_int($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$params = [];
$where  = [];

if ($f_usuario > 0) {
  $where[] = 'cs.user_id = :uid';
  $params[':uid'] = $f_usuario;
}

if ($f_terminal > 0) {
  $where[] = 'cs.terminal_id = :tid';
  $params[':tid'] = $f_terminal;
}

if ($f_estado === 'abierta') {
  $where[] = "(cs.fecha_cierre IS NULL OR cs.fecha_cierre = '' OR cs.fecha_cierre = '0000-00-00 00:00:00')";
} elseif ($f_estado === 'cerrada') {
  $where[] = "(cs.fecha_cierre IS NOT NULL AND cs.fecha_cierre <> '' AND cs.fecha_cierre <> '0000-00-00 00:00:00')";
}

if ($f_desde) {
  $where[] = 'cs.fecha_apertura >= :desde_dt';
  $params[':desde_dt'] = $f_desde . ' 00:00:00';
}

if ($f_hasta) {
  $where[] = 'cs.fecha_apertura <= :hasta_dt';
  $params[':hasta_dt'] = $f_hasta . ' 23:59:59';
}

if ($f_dif) {
  $where[] = $condDif;
}

if ($f_medios) {
  $where[] = $condMedios;
}

if ($f_anom) {
  $where[] = '(' . $condDif . ' OR ' . $condMedios . ' OR ' . $condLong . ')';
}

$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* --------------------------------------------------------
   Catálogos para filtros
-------------------------------------------------------- */
$usuarios = [];
try {
  $usuarios = $pdo->query("SELECT id, username, nombre FROM users WHERE activo=1 ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $usuarios = [];
}

$terminales = terminal_list($pdo);
$terminalMap = [];
foreach ($terminales as $t) {
  $terminalMap[(int)($t['id'] ?? 0)] = (string)($t['nombre'] ?? ('Caja #' . (int)($t['id'] ?? 0)));
}

/* --------------------------------------------------------
   COUNT + STATS + LIST
-------------------------------------------------------- */
$error_msg = null;
$filas = [];
$total_sesiones = 0;

$stats = [
  'ventas_sum' => 0.0,
  'dif_sum' => 0.0,
  'abiertas' => 0,
  'anul_sum' => 0,
  'prod_sum' => 0,
  'dif_count' => 0,
  'medios_count' => 0,
  'largas_count' => 0,
  'anom_count' => 0,
];

try {
  // COUNT
  $stCount = $pdo->prepare("SELECT COUNT(*) FROM caja_sesiones cs {$whereClause}");
  flus_bind_params($stCount, $params);
  $stCount->execute();
  $total_sesiones = (int)$stCount->fetchColumn();

  // Clamp page dentro de rango (consistente con otros módulos)
  $tmpTotalPages = (int)ceil(max(1, $total_sesiones) / max(1, $per_page));
  if ($page > $tmpTotalPages) {
    $page = $tmpTotalPages;
    $offset = ($page - 1) * $per_page;
  }


  // STATS
  $sqlStats = "
    SELECT
      COALESCE(SUM(cs.total_ventas),0)      AS ventas_sum,
      COALESCE(SUM(cs.diferencia),0)        AS dif_sum,
      COALESCE(SUM(cs.total_anulaciones),0) AS anul_sum,
      COALESCE(SUM(cs.total_productos),0)   AS prod_sum,
      COALESCE(SUM(CASE WHEN (cs.fecha_cierre IS NULL OR cs.fecha_cierre = '' OR cs.fecha_cierre = '0000-00-00 00:00:00') THEN 1 ELSE 0 END),0) AS abiertas,

      COALESCE(SUM(CASE WHEN {$condDif} THEN 1 ELSE 0 END),0) AS dif_count,
      COALESCE(SUM(CASE WHEN {$condMedios} THEN 1 ELSE 0 END),0) AS medios_count,
      COALESCE(SUM(CASE WHEN {$condLong} THEN 1 ELSE 0 END),0) AS largas_count,
      COALESCE(SUM(CASE WHEN ({$condDif} OR {$condMedios} OR {$condLong}) THEN 1 ELSE 0 END),0) AS anom_count

    FROM caja_sesiones cs
    {$whereClause}
  ";

  $stStats = $pdo->prepare($sqlStats);
  flus_bind_params($stStats, $params);
  $stStats->execute();
  $rowStats = $stStats->fetch(PDO::FETCH_ASSOC) ?: [];

  $stats['ventas_sum']   = (float)($rowStats['ventas_sum'] ?? 0);
  $stats['dif_sum']      = (float)($rowStats['dif_sum'] ?? 0);
  $stats['anul_sum']     = (int)($rowStats['anul_sum'] ?? 0);
  $stats['prod_sum']     = (int)($rowStats['prod_sum'] ?? 0);
  $stats['abiertas']     = (int)($rowStats['abiertas'] ?? 0);
  $stats['dif_count']    = (int)($rowStats['dif_count'] ?? 0);
  $stats['medios_count'] = (int)($rowStats['medios_count'] ?? 0);
  $stats['largas_count'] = (int)($rowStats['largas_count'] ?? 0);
  $stats['anom_count']   = (int)($rowStats['anom_count'] ?? 0);

  // LIST
  $joinMov  = '';
  $selMov   = '0 AS mov_ingresos, 0 AS mov_egresos';
  if ($movTableExists) {
    $joinMov = "
      LEFT JOIN (
        SELECT
          caja_id,
          COALESCE(SUM(CASE WHEN UPPER(tipo)='INGRESO' THEN monto ELSE 0 END),0) AS mov_ingresos,
          COALESCE(SUM(CASE WHEN UPPER(tipo)='EGRESO'  THEN monto ELSE 0 END),0) AS mov_egresos
        FROM caja_movimientos
        GROUP BY caja_id
      ) mov ON mov.caja_id = cs.id
    ";
    $selMov = 'COALESCE(mov.mov_ingresos,0) AS mov_ingresos, COALESCE(mov.mov_egresos,0) AS mov_egresos';
  }

  $joinAudit = '';
  $selAudit  = "NULL AS audit_status, NULL AS audit_nota, NULL AS audit_by, NULL AS audit_at, NULL AS audit_by_username";
  if ($auditTableExists) {
    $joinAudit = "
      LEFT JOIN caja_auditoria ca ON ca.caja_id = cs.id
      LEFT JOIN users au ON au.id = ca.audited_by
    ";
    $selAudit = "
      ca.status AS audit_status,
      ca.nota AS audit_nota,
      ca.audited_by AS audit_by,
      ca.audited_at AS audit_at,
      au.username AS audit_by_username
    ";
  }

  $sql = "
    SELECT
      cs.id,
      cs.terminal_id,
      cs.user_id,
      u.username,
      u.nombre,

      cs.fecha_apertura,
      cs.fecha_cierre,
      TIMESTAMPDIFF(MINUTE, cs.fecha_apertura, {$endExpr}) AS dur_min,

      cs.saldo_inicial,
      cs.saldo_sistema,
      cs.saldo_declarado,
      cs.diferencia,

      cs.total_ventas,
      (
        SELECT COUNT(*)
        FROM ventas vcnt
        WHERE vcnt.caja_id = cs.id
          AND (vcnt.estado IS NULL OR UPPER(vcnt.estado) NOT LIKE '%ANUL%')
      ) AS ventas_count,
      cs.total_efectivo,
      cs.total_mp,
      cs.total_debito,
      cs.total_credito,
      {$transferExpr} AS total_transferencia,
      cs.total_anulaciones,
      cs.total_productos,
      cs.notas,
      {$ventasCcExpr} AS ventas_cc,
      {$cobrosCcExpr} AS cobros_cc,
      {$mediosBaseExpr} AS medios_base,
      {$mediosExpr} AS medios_sum,
      ({$mediosBaseExpr} - {$mediosExpr}) AS medios_diff,

      {$selMov},
      {$selAudit}

    FROM caja_sesiones cs
    LEFT JOIN users u ON u.id = cs.user_id
    {$joinMov}
    {$joinAudit}
    {$whereClause}
    ORDER BY cs.id DESC
    LIMIT :lim OFFSET :off
  ";

  $st = $pdo->prepare($sql);

  flus_bind_params($st, $params);
  $st->bindValue(':lim', (int)$per_page, PDO::PARAM_INT);
  $st->bindValue(':off', (int)$offset, PDO::PARAM_INT);
  $st->execute();

  $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Throwable $e) {
  $error_msg = $e->getMessage();
}

/* --------------------------------------------------------
   Export CSV
-------------------------------------------------------- */
if (($error_msg === null) && ((string)($_GET['export'] ?? '') === 'csv')) {
  $maxRows = 10000;

  // Repetimos query sin LIMIT (con un tope)
  try {
    $joinMov  = '';
    $selMov   = '0 AS mov_ingresos, 0 AS mov_egresos';
    if ($movTableExists) {
      $joinMov = "
        LEFT JOIN (
          SELECT
            caja_id,
            COALESCE(SUM(CASE WHEN UPPER(tipo)='INGRESO' THEN monto ELSE 0 END),0) AS mov_ingresos,
            COALESCE(SUM(CASE WHEN UPPER(tipo)='EGRESO'  THEN monto ELSE 0 END),0) AS mov_egresos
          FROM caja_movimientos
          GROUP BY caja_id
        ) mov ON mov.caja_id = cs.id
      ";
      $selMov = 'COALESCE(mov.mov_ingresos,0) AS mov_ingresos, COALESCE(mov.mov_egresos,0) AS mov_egresos';
    }

    $joinAudit = '';
    $selAudit  = "NULL AS audit_status, NULL AS audit_nota, NULL AS audit_by, NULL AS audit_at";
    if ($auditTableExists) {
      $joinAudit = "LEFT JOIN caja_auditoria ca ON ca.caja_id = cs.id";
      $selAudit  = "ca.status AS audit_status, ca.nota AS audit_nota, ca.audited_by AS audit_by, ca.audited_at AS audit_at";
    }

    $sqlExport = "
      SELECT
        cs.id,
        cs.terminal_id,
        u.username,
        cs.fecha_apertura,
        cs.fecha_cierre,
        TIMESTAMPDIFF(MINUTE, cs.fecha_apertura, {$endExpr}) AS dur_min,

        cs.total_ventas,
        cs.total_efectivo,
        cs.total_mp,
        cs.total_debito,
        cs.total_credito,
        {$transferExpr} AS total_transferencia,
        {$ventasCcExpr} AS ventas_cc,
        {$cobrosCcExpr} AS cobros_cc,
        {$mediosBaseExpr} AS medios_base,
        ({$mediosBaseExpr} - {$mediosExpr}) AS medios_diff,

        cs.saldo_inicial,
        cs.saldo_sistema,
        cs.saldo_declarado,
        cs.diferencia,

        {$selMov},
        {$selAudit}

      FROM caja_sesiones cs
      LEFT JOIN users u ON u.id = cs.user_id
      {$joinMov}
      {$joinAudit}
      {$whereClause}
      ORDER BY cs.id DESC
      LIMIT {$maxRows}
    ";

    $stE = $pdo->prepare($sqlExport);

    flus_bind_params($stE, $params);
    $stE->execute();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="historial_caja.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');

    fputcsv($out, [
      'id','terminal_id','terminal','usuario','apertura','cierre','dur_min',
      'total_ventas','total_efectivo','total_mp','total_debito','total_credito','total_transferencia',
      'ventas_cc','cobros_cc','medios_base','medios_diff',
      'saldo_inicial','saldo_sistema','saldo_declarado','diferencia',
      'mov_ingresos','mov_egresos',
      'audit_status','audit_by','audit_at','audit_nota'
    ]);

    while ($r = $stE->fetch(PDO::FETCH_ASSOC)) {
      $tid = (int)($r['terminal_id'] ?? 0);
      $tname = $terminalMap[$tid] ?? ('Caja #' . $tid);

      fputcsv($out, [
        (int)($r['id'] ?? 0),
        $tid,
        $tname,
        (string)($r['username'] ?? ''),
        (string)($r['fecha_apertura'] ?? ''),
        (string)($r['fecha_cierre'] ?? ''),
        (int)($r['dur_min'] ?? 0),

        (float)($r['total_ventas'] ?? 0),
        (float)($r['total_efectivo'] ?? 0),
        (float)($r['total_mp'] ?? 0),
        (float)($r['total_debito'] ?? 0),
        (float)($r['total_credito'] ?? 0),
        (float)($r['total_transferencia'] ?? 0),
        (float)($r['ventas_cc'] ?? 0),
        (float)($r['cobros_cc'] ?? 0),
        (float)($r['medios_base'] ?? 0),
        (float)($r['medios_diff'] ?? 0),

        (float)($r['saldo_inicial'] ?? 0),
        $r['saldo_sistema'] !== null ? (float)$r['saldo_sistema'] : null,
        $r['saldo_declarado'] !== null ? (float)$r['saldo_declarado'] : null,
        (float)($r['diferencia'] ?? 0),

        (float)($r['mov_ingresos'] ?? 0),
        (float)($r['mov_egresos'] ?? 0),

        (string)($r['audit_status'] ?? ''),
        $r['audit_by'] !== null ? (int)$r['audit_by'] : null,
        (string)($r['audit_at'] ?? ''),
        (string)($r['audit_nota'] ?? ''),
      ]);
    }

    fclose($out);
    exit;

  } catch (Throwable $e) {
    // caemos al HTML con error
    $error_msg = $e->getMessage();
  }
}

/* --------------------------------------------------------
   Flash messages
-------------------------------------------------------- */
$flashOk = (string)($_SESSION['flash_ok'] ?? '');
$flashErr = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

/* --------------------------------------------------------
   Render
-------------------------------------------------------- */
$pageTitle = 'Control de Turnos';
$currentSection = 'caja_historial';
$extraCss = ['assets/css/caja_historial.css'];
$extraJs  = ['assets/js/caja_historial.js'];
$bodyClass = 'caja-historial-page';

require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';

$startIdx = ($total_sesiones > 0) ? ($offset + 1) : 0;
$endIdx   = min($offset + $per_page, $total_sesiones);
$totalPages = (int)ceil(max(1, $total_sesiones) / $per_page);

$activeBadges = [];
if ($f_usuario > 0) $activeBadges[] = 'Usuario';
if ($f_terminal > 0) $activeBadges[] = 'Terminal';
if ($f_estado !== '') $activeBadges[] = 'Estado';
if ($f_desde) $activeBadges[] = 'Desde';
if ($f_hasta) $activeBadges[] = 'Hasta';
if ($f_dif) $activeBadges[] = 'Solo diferencias';
if ($f_medios) $activeBadges[] = 'Inconsistencia medios';
if ($f_anom) $activeBadges[] = 'Solo anomalías';

function pill_class_for_amount(float $v): string {
  if (abs($v) < 0.00001) return 'pill pill-zero';
  return ($v > 0) ? 'pill pill-pos' : 'pill pill-neg';
}

?>

<div class="page-wrap caja-historial-page">
  <div class="panel">

    <header class="page-header module-header">
      <div class="page-header-main module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M5 4h14v16H5z"/>
              <path d="M9 8h6"/>
              <path d="M9 12h6"/>
              <path d="M9 16h4"/>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="page-eyebrow module-eyebrow">Operacion de caja</span>
            <h1 class="page-title">Control de Turnos</h1>
        <p class="page-sub">Revisá turnos, diferencias, conciliación de efectivo y anomalías detectadas.</p>
          </div>
        </div>
      </div>

      <div class="page-actions module-header-actions">
        <?php if ($auditTableExistsRaw): ?>
          <?php if ($auditDisabled): ?>
            <span class="tag tag-bajo" title="Desactivada por flag en storage/ (no se borraron datos)">Auditoría desactivada</span>
            <?php if ($canAudit): ?>
              <form method="post" action="<?= h(url_with_query([])) ?>" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="audit_enable">
                <button class="btn btn-secondary" type="submit">Reactivar</button>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <span class="tag tag-ok">Auditoría activa</span>
            <?php if ($canAudit): ?>
              <form method="post" action="<?= h(url_with_query([])) ?>" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="audit_disable">
                <button class="btn btn-secondary" type="submit">Desactivar</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>

        <?php elseif ($canAudit): ?>
          <form method="post" action="<?= h(url_with_query([])) ?>" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="audit_setup">
            <button class="btn btn-primary" type="submit">Habilitar auditoría</button>
          </form>
        <?php else: ?>
          <span class="tag tag-inactivo" title="Requiere permiso ver_auditoria o administrar_config">Solo admins</span>
        <?php endif; ?>

        <a class="btn btn-secondary" href="<?= h(url_with_query(['export' => 'csv', 'page' => 1])) ?>">Exportar CSV</a>
      </div>
    </header>

    <?php if ($flashOk): ?>
      <div class="alert alert-success">✅ <?= h($flashOk) ?></div>
    <?php endif; ?>

    <?php if ($flashErr): ?>
      <div class="alert alert-error">⚠️ <?= h($flashErr) ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
      <div class="alert alert-error">Error: <?= h($error_msg) ?></div>
    <?php endif; ?>

    <section class="caja-historial-shell">
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-label">Sesiones</div>
        <div class="stat-value"><?= number_format($total_sesiones, 0, ',', '.') ?></div>
        <div class="stat-sub">En el filtro actual</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Abiertas</div>
        <div class="stat-value"><?= number_format((int)$stats['abiertas'], 0, ',', '.') ?></div>
        <div class="stat-sub">Turnos sin cierre</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Ventas</div>
        <div class="stat-value"><?= money_ar($stats['ventas_sum']) ?></div>
        <div class="stat-sub">Suma total ventas</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Diferencia</div>
        <div class="stat-value"><span class="<?= h(pill_class_for_amount((float)$stats['dif_sum'])) ?>"><?= money_ar((float)$stats['dif_sum']) ?></span></div>
        <div class="stat-sub">Suma de diferencias</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Anomalías</div>
        <div class="stat-value"><?= number_format((int)$stats['anom_count'], 0, ',', '.') ?></div>
        <div class="stat-sub">Dif / medios / turnos largos</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Medios</div>
        <div class="stat-value"><?= number_format((int)$stats['medios_count'], 0, ',', '.') ?></div>
        <div class="stat-sub">Total ≠ suma medios</div>
      </div>
    </div>

    <form method="get" class="filters">
      <div class="filters-left">
        <select name="usuario" aria-label="Usuario">
          <option value="0">Todos los usuarios</option>
          <?php foreach ($usuarios as $u):
            $uid = (int)($u['id'] ?? 0);
            $label = (string)($u['username'] ?? ('#' . $uid));
            $label2 = trim((string)($u['nombre'] ?? ''));
            if ($label2 !== '') $label .= ' — ' . $label2;
          ?>
            <option value="<?= $uid ?>" <?= $uid === $f_usuario ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="terminal" aria-label="Terminal">
          <option value="0">Todas las terminales</option>
          <?php foreach ($terminales as $t):
            $tid = (int)($t['id'] ?? 0);
            $tname = (string)($t['nombre'] ?? ('Caja #' . $tid));
            $activo = (int)($t['activo'] ?? 0);
            if ($activo !== 1) $tname .= ' (inactiva)';
          ?>
            <option value="<?= $tid ?>" <?= $tid === $f_terminal ? 'selected' : '' ?>><?= h($tname) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="estado" aria-label="Estado">
          <option value="" <?= $f_estado === '' ? 'selected' : '' ?>>Todas</option>
          <option value="abierta" <?= $f_estado === 'abierta' ? 'selected' : '' ?>>Abiertas</option>
          <option value="cerrada" <?= $f_estado === 'cerrada' ? 'selected' : '' ?>>Cerradas</option>
        </select>

        <input type="date" name="desde" value="<?= h($f_desde ?? '') ?>" aria-label="Desde">
        <input type="date" name="hasta" value="<?= h($f_hasta ?? '') ?>" aria-label="Hasta">

        <select name="limit" aria-label="Filas">
          <?php foreach ([10,25,50,100] as $pp): ?>
            <option value="<?= $pp ?>" <?= $pp === $per_page ? 'selected' : '' ?>><?= $pp ?>/pág</option>
          <?php endforeach; ?>
        </select>

        <label class="chk">
          <input type="checkbox" name="dif" value="1" <?= $f_dif ? 'checked' : '' ?>>
          <span>Solo con diferencias</span>
        </label>

        <label class="chk">
          <input type="checkbox" name="medios" value="1" <?= $f_medios ? 'checked' : '' ?>>
          <span>Inconsistencia de medios</span>
        </label>

        <label class="chk">
          <input type="checkbox" name="anom" value="1" <?= $f_anom ? 'checked' : '' ?>>
          <span>Solo anomalías</span>
        </label>
      </div>

      <div class="filters-right">
        <button class="btn-primary" type="submit">Aplicar</button>
        <a class="btn-secondary" href="caja_historial.php">Limpiar</a>
      </div>
    </form>

    <?php if (!empty($activeBadges)): ?>
      <div class="hist-meta">
        <span class="badge badge-info">Filtros activos</span>
        <?php foreach ($activeBadges as $b): ?>
          <span class="badge"><?= h($b) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?= render_pagination($page, $totalPages, $_GET, true, $total_sesiones, $startIdx, $endIdx, ['export']) ?>

    <div class="table-wrapper">
      <table class="hist-table">
        <thead>
          <tr>
            <th class="t-left">#</th>
            <th class="t-left">Terminal</th>
            <th class="t-left">Cajero</th>
            <th class="t-left">Turno</th>
            <th class="t-center">Duración</th>
            <th class="t-center">Tickets</th>
            <th class="t-right">Ventas</th>
            <th class="t-right medio-col">Efectivo</th>
            <th class="t-right medio-col">MP</th>
            <th class="t-right medio-col">Debito</th>
            <th class="t-right medio-col">Credito</th>
            <th class="t-right medio-col">Transf.</th>
            <th class="t-right">Dif.</th>
            <th class="t-left">Flags</th>
            <th class="t-center col-actions">Acciones</th>
          </tr>
        </thead>
        <tbody>

        <?php if (!$filas): ?>
          <tr>
            <td colspan="15" class="empty-cell">No hay sesiones para los filtros seleccionados.</td>
          </tr>
        <?php else: ?>

          <?php foreach ($filas as $r):
            $id = (int)($r['id'] ?? 0);
            $tid = (int)($r['terminal_id'] ?? 0);
            $tname = $terminalMap[$tid] ?? ('Caja #' . $tid);

            $u = (string)($r['username'] ?? '');
            $un = trim((string)($r['nombre'] ?? ''));
            $userLabel = $u !== '' ? $u : ('#' . (int)($r['user_id'] ?? 0));
            if ($un !== '') $userLabel .= ' — ' . $un;

            $ap = (string)($r['fecha_apertura'] ?? '');
            $ci = (string)($r['fecha_cierre'] ?? '');
            $isOpen = is_open_dt($ci);

            $durMin = (int)($r['dur_min'] ?? 0);
            $durTxt = dur_label($durMin);

            $ventas = (float)($r['total_ventas'] ?? 0);
            $ventasCount = (int)($r['ventas_count'] ?? 0);
            $dif = (float)($r['diferencia'] ?? 0);
            $medioEfectivo = (float)($r['total_efectivo'] ?? 0);
            $medioMp = (float)($r['total_mp'] ?? 0);
            $medioDebito = (float)($r['total_debito'] ?? 0);
            $medioCredito = (float)($r['total_credito'] ?? 0);
            $medioTransferencia = (float)($r['total_transferencia'] ?? 0);

            $ventasCc    = (float)($r['ventas_cc'] ?? 0);
            $cobrosCc    = (float)($r['cobros_cc'] ?? 0);
            $mediosBase  = (float)($r['medios_base'] ?? 0);
            $mediosSum  = (float)($r['medios_sum'] ?? 0);
            $mediosDiff = (float)($r['medios_diff'] ?? 0);

            $movIng = (float)($r['mov_ingresos'] ?? 0);
            $movEgr = (float)($r['mov_egresos'] ?? 0);
            $movNet = $movIng - $movEgr;

            $saldoInicial  = (float)($r['saldo_inicial'] ?? 0);
            $efectivoTotal = $medioEfectivo;
            $efectivoEsperadoCalc = $saldoInicial + $efectivoTotal + $movIng - $movEgr;

            $saldoSistema = $r['saldo_sistema'];
            $saldoDeclarado = $r['saldo_declarado'];

            $flags = [];
            if (abs($dif) > 0.009) $flags[] = ['Dif', 'flag-danger'];
            if (abs($mediosDiff) > 0.009) $flags[] = ['Medios', 'flag-warn'];
            if ($durMin >= $LONG_MIN) $flags[] = [$durMin >= $VERY_LONG_MIN ? 'Muy largo' : 'Largo', 'flag-warn'];
            if ($isOpen) $flags[] = ['Abierta', 'flag-open'];

            $auditStatus = strtoupper(trim((string)($r['audit_status'] ?? '')));
            if ($auditStatus === '') $auditStatus = 'PENDIENTE';
            if ($auditStatus === 'OBSERVADA') $flags[] = ['Obs.', 'flag-danger'];
          ?>

          <tr id="caja-<?= $id ?>" class="<?= $isOpen ? 'row-open' : '' ?>">
            <td class="mono">#<?= $id ?></td>
            <td><?= h($tname) ?></td>
            <td><?= h($userLabel) ?></td>
            <td>
              <div class="turno">
                <div><strong><?= h(format_datetime_ar($ap)) ?></strong></div>
                <div class="muted">
                  <?= $isOpen ? '<span class="pill pill-open">Sin cierre</span>' : h(format_datetime_ar($ci)) ?>
                </div>
              </div>
            </td>
            <td class="t-center"><span class="mono"><?= h($durTxt) ?></span></td>
            <td class="t-center"><span class="mono"><?= number_format($ventasCount, 0, ',', '.') ?></span></td>
            <td class="t-right"><span class="mono"><?= money_ar($ventas) ?></span></td>
            <td class="t-right medio-col"><span class="mono"><?= money_ar($medioEfectivo) ?></span></td>
            <td class="t-right medio-col"><span class="mono"><?= money_ar($medioMp) ?></span></td>
            <td class="t-right medio-col"><span class="mono"><?= money_ar($medioDebito) ?></span></td>
            <td class="t-right medio-col"><span class="mono"><?= money_ar($medioCredito) ?></span></td>
            <td class="t-right medio-col"><span class="mono"><?= money_ar($medioTransferencia) ?></span></td>
            <td class="t-right"><span class="<?= h(pill_class_for_amount($dif)) ?>"><?= money_ar($dif) ?></span></td>
            <td>
              <?php if (!$flags): ?>
                <span class="flag">OK</span>
              <?php else: ?>
                <?php foreach ($flags as $f): ?>
                  <span class="flag <?= h($f[1]) ?>"><?= h($f[0]) ?></span>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
            <td class="t-center">
              <div class="row-actions">
                <a class="btn-mini btn-mini-ghost" href="caja_sesion_detalle.php?id=<?= $id ?>">Detalle</a>
                <a class="btn-mini btn-mini-ghost" href="caja_sesion_print.php?id=<?= $id ?>" target="_blank" rel="noopener">Imprimir</a>
                <button class="btn-mini btn-mini-ghost js-toggle-details" type="button" data-id="<?= $id ?>" aria-expanded="false">Más ▾</button>
              </div>
            </td>
          </tr>

          <tr class="row-details" data-details="<?= $id ?>" hidden>
            <td colspan="15">
              <div class="details-grid">

                <div class="detail-block">
                  <div class="detail-title">Saldos</div>
                  <div class="detail-row"><span>Inicial</span><strong><?= money_ar($saldoInicial) ?></strong></div>
                  <div class="detail-row"><span>Sistema</span><strong><?= $saldoSistema !== null ? money_ar((float)$saldoSistema) : '<span class="muted">(no guardado)</span>' ?></strong></div>
                  <div class="detail-row"><span>Declarado</span><strong><?= $saldoDeclarado !== null ? money_ar((float)$saldoDeclarado) : '<span class="muted">—</span>' ?></strong></div>
                  <div class="detail-row"><span>Diferencia</span><strong><?= money_ar($dif) ?></strong></div>
                </div>

                <div class="detail-block">
                  <div class="detail-title">Medios de pago</div>
                  <div class="detail-row"><span>Efectivo</span><strong><?= money_ar((float)($r['total_efectivo'] ?? 0)) ?></strong></div>
                  <div class="detail-row"><span>MercadoPago</span><strong><?= money_ar((float)($r['total_mp'] ?? 0)) ?></strong></div>
                  <div class="detail-row"><span>Débito</span><strong><?= money_ar((float)($r['total_debito'] ?? 0)) ?></strong></div>
                  <div class="detail-row"><span>Crédito</span><strong><?= money_ar((float)($r['total_credito'] ?? 0)) ?></strong></div>
                  <div class="detail-row"><span>Transferencia</span><strong><?= money_ar((float)($r['total_transferencia'] ?? 0)) ?></strong></div>
                  <div class="detail-row"><span>Suma medios</span><strong><?= money_ar($mediosSum) ?></strong></div>
                  <div class="detail-row"><span>Total ventas</span><strong><?= money_ar($ventas) ?></strong></div>
                  <div class="detail-row"><span>Ventas a CC</span><strong><?= money_ar($ventasCc) ?></strong></div>
                  <div class="detail-row"><span>Cobros CC</span><strong><?= money_ar($cobrosCc) ?></strong></div>
                  <div class="detail-row"><span>Base medios</span><strong><?= money_ar($mediosBase) ?></strong></div>
                  <div class="detail-row"><span>Diff medios</span><strong><?= money_ar($mediosDiff) ?></strong></div>
                </div>

                <div class="detail-block">
                  <div class="detail-title">Movimientos (efectivo)</div>
                  <?php if (!$movTableExists): ?>
                    <div class="muted">Tabla <span class="mono">caja_movimientos</span> no disponible.</div>
                  <?php else: ?>
                    <div class="detail-row"><span>Ingresos</span><strong><?= money_ar($movIng) ?></strong></div>
                    <div class="detail-row"><span>Egresos</span><strong><?= money_ar($movEgr) ?></strong></div>
                    <div class="detail-row"><span>Neto</span><strong><?= money_ar($movNet) ?></strong></div>
                    <div class="detail-row"><span>Efectivo esperado</span><strong><?= money_ar($efectivoEsperadoCalc) ?></strong></div>
                  <?php endif; ?>
                </div>

                <div class="detail-block">
                  <div class="detail-title">Resumen del turno</div>
                  <div class="detail-row"><span>Tickets</span><strong><?= number_format($ventasCount, 0, ',', '.') ?></strong></div>
                  <div class="detail-row"><span>Productos</span><strong><?= (int)($r['total_productos'] ?? 0) ?></strong></div>
                  <div class="detail-row"><span>Anulaciones</span><strong><?= (int)($r['total_anulaciones'] ?? 0) ?></strong></div>
                  <div class="detail-row"><span>Duración</span><strong><?= h($durTxt) ?></strong></div>
                  <div class="detail-row"><span>Notas</span><strong><?= ($r['notas'] ?? '') !== '' ? h((string)$r['notas']) : '<span class="muted">—</span>' ?></strong></div>
                </div>

                <div class="detail-block">
                  <div class="detail-title">Auditoría</div>

                  <?php if (!$auditTableExists): ?>
                    <div class="muted">
                      Auditoría deshabilitada. <?php if ($canAudit): ?>Usá “Habilitar auditoría” arriba.<?php endif; ?>
                    </div>
                  <?php else: ?>
                    <?php
                      $auditBy = trim((string)($r['audit_by_username'] ?? ''));
                      $auditAt = trim((string)($r['audit_at'] ?? ''));
                      $auditNota = trim((string)($r['audit_nota'] ?? ''));
                    ?>

                    <div class="detail-row"><span>Estado</span><strong><?= h($auditStatus) ?></strong></div>
                    <div class="detail-row"><span>Por</span><strong><?= $auditBy !== '' ? h($auditBy) : '<span class="muted">—</span>' ?></strong></div>
                    <div class="detail-row"><span>Fecha</span><strong><?= $auditAt !== '' ? h(format_datetime_ar($auditAt)) : '<span class="muted">—</span>' ?></strong></div>
                    <div class="detail-row"><span>Nota</span><strong><?= $auditNota !== '' ? h($auditNota) : '<span class="muted">—</span>' ?></strong></div>

                    <?php if ($canAudit): ?>
                      <form method="post" action="<?= h(url_with_query([])) ?>" class="audit-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="audit_save">
                        <input type="hidden" name="caja_id" value="<?= $id ?>">

                        <div class="audit-grid">
                          <div>
                            <label class="audit-label">Estado</label>
                            <select name="status">
                              <?php foreach (['PENDIENTE','REVISADA','OBSERVADA'] as $st): ?>
                                <option value="<?= $st ?>" <?= $st === $auditStatus ? 'selected' : '' ?>><?= $st ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="audit-wide">
                            <label class="audit-label">Nota</label>
                            <textarea name="nota" rows="3" placeholder="Observaciones (opcional)"><?= h($auditNota) ?></textarea>
                          </div>
                        </div>

                        <button class="btn-primary" type="submit">Guardar</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>

                </div>

              </div>
            </td>
          </tr>

          <?php endforeach; ?>

        <?php endif; ?>

        </tbody>
      </table>
    </div>

    <?= render_pagination($page, $totalPages, $_GET, false, 0, 0, 0, ['export']) ?>
    </section>


  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
