<?php
declare(strict_types=1);
// public/api/index.php
require_once __DIR__ . '/../lib/root.php';

// API JSON: nunca romper por warnings/HTML
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = __DIR__ . '/../../src/config.php';
if (!is_file($cfg)) {
  if (ob_get_length()) ob_clean();
  http_response_code(503);
  echo json_encode(['ok'=>false,'error'=>'CONFIG_MISSING','hint'=>'Abrí /install.php para configurar FLUS.'],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
  );
  exit;
}

require_once $cfg;
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/terminal.php';      // ✅ terminal_* + require_terminal_lock_json()
require_once __DIR__ . '/../caja_lib.php';
require_once __DIR__ . '/../promos_logic.php';      // obtenerPromosActivas()

function json_ok(array $data = [], int $code = 200): void {
  if (ob_get_length()) ob_clean();
  http_response_code($code);
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

function json_fail(string $msg, int $code = 400, array $extra = []): void {
  if (ob_get_length()) ob_clean();
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

// ✅ Si hay fatal/parse, devolvemos JSON (evita "NO JSON" en front)
set_exception_handler(function (Throwable $e): void {
  if (ob_get_length()) ob_clean();
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
  }
  $code = ($e instanceof PDOException) ? 503 : 500;
  http_response_code($code);
  echo json_encode([
    'ok' => false,
    'error' => ($e instanceof PDOException) ? 'DB_DOWN' : 'SERVER_ERROR'
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
});

register_shutdown_function(function (): void {
  $e = error_get_last();
  if (!$e) return;

  $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array((int)$e['type'], $fatal, true)) return;

  if (ob_get_length()) ob_clean();
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
  }
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'FATAL',
    'detail' => $e['message'] ?? 'Error fatal',
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});



function parse_num($v): float {
  if (is_int($v) || is_float($v)) return (float)$v;
  $s = trim((string)$v);
  if ($s === '') return 0.0;
  // AR: 1.234,56 -> 1234.56
  $s = str_replace('.', '', $s);
  $s = str_replace(',', '.', $s);
  return is_numeric($s) ? (float)$s : 0.0;
}

function norm_medio_pago(string $m): string {
  $m = strtoupper(trim($m));
  if ($m === 'EFECTIVO') return 'EFECTIVO';
  if ($m === 'MP' || str_contains($m, 'MERCADO')) return 'MP';
  if ($m === 'DEBITO' || str_contains($m, 'DEB')) return 'DEBITO';
  if ($m === 'CREDITO' || str_contains($m, 'CRED')) return 'CREDITO';
  return 'EFECTIVO';
}

// ✅ MEJORADO: Cargar todas las columnas de una tabla en una query
function get_table_columns(PDO $pdo, string $table): array {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];

  $st = $pdo->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
  ");
  $st->execute([$table]);
  $cache[$table] = $st->fetchAll(PDO::FETCH_COLUMN);
  return $cache[$table];
}

function has_col(PDO $pdo, string $table, string $col): bool {
  $cols = get_table_columns($pdo, $table);
  return in_array($col, $cols, true);
}

function insert_dynamic(PDO $pdo, string $table, array $data): int {
  $availableCols = get_table_columns($pdo, $table);

  $cols = [];
  $ph   = [];
  $params = [];

  foreach ($data as $col => $val) {
    if (!in_array($col, $availableCols, true)) continue;
    $cols[] = $col;
    $ph[] = ':' . $col;
    $params[':' . $col] = $val;
  }

  if (!$cols) {
    throw new RuntimeException("No hay columnas compatibles para insertar en {$table}");
  }

  $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (int)$pdo->lastInsertId();
}

function update_caja_venta_totales(PDO $pdo, int $cajaId, float $importe, float $productos): void {
  if ($cajaId <= 0) return;

  $sets   = [];
  $params = [':id' => $cajaId];

  if (has_col($pdo, 'caja_sesiones', 'total_ventas')) {
    $sets[] = "total_ventas = COALESCE(total_ventas,0) + :imp";
    $params[':imp'] = $importe;
  }

  if (has_col($pdo, 'caja_sesiones', 'cant_ventas')) {
    $sets[] = "cant_ventas = COALESCE(cant_ventas,0) + 1";
  } elseif (has_col($pdo, 'caja_sesiones', 'cantidad_ventas')) {
    $sets[] = "cantidad_ventas = COALESCE(cantidad_ventas,0) + 1";
  }

  if (has_col($pdo, 'caja_sesiones', 'total_productos')) {
    $sets[] = "total_productos = COALESCE(total_productos,0) + :tp";
    $params[':tp'] = $productos;
  }

  if (!$sets) return;

  $sql = "UPDATE caja_sesiones SET " . implode(", ", $sets) . " WHERE id = :id";
  $st = $pdo->prepare($sql);
  $st->execute($params);
}

// ✅ FIX: delta por medio nunca puede quedar negativo
function update_caja_medio_delta(PDO $pdo, int $cajaId, string $medio, float $delta): void {
  if ($cajaId <= 0) return;

  $campo = 'total_efectivo';
  $m = strtoupper(trim($medio));
  if ($m === 'MP') $campo = 'total_mp';
  elseif ($m === 'DEBITO') $campo = 'total_debito';
  elseif ($m === 'CREDITO') $campo = 'total_credito';

  if (!has_col($pdo, 'caja_sesiones', $campo)) return;

  $sql = "UPDATE caja_sesiones
          SET {$campo} = GREATEST(COALESCE({$campo},0) + :d, 0)
          WHERE id = :id";
  $st = $pdo->prepare($sql);
  $st->execute([':d' => $delta, ':id' => $cajaId]);
}

// ------------------ DESCUENTO GLOBAL ------------------
function parse_desc_global($raw): ?array {
  if (!is_array($raw)) return null;

  $tipo = strtolower(trim((string)($raw['tipo'] ?? '')));
  $valor = parse_num($raw['valor'] ?? 0);

  if ($valor <= 0) return null;

  if ($tipo === 'porcentaje') {
    if ($valor > 100) $valor = 100;
    return ['tipo' => 'porcentaje', 'valor' => round($valor, 2)];
  }

  return ['tipo' => 'monto', 'valor' => round($valor, 2)];
}

function calc_desc_global(float $netoAntes, ?array $desc): float {
  if (!$desc) return 0.0;
  $tipo = $desc['tipo'] ?? '';
  $valor = (float)($desc['valor'] ?? 0);

  if ($valor <= 0 || $netoAntes <= 0) return 0.0;

  if ($tipo === 'porcentaje') {
    $m = ($netoAntes * $valor) / 100.0;
    return round(min($netoAntes, $m), 2);
  }

  return round(min($netoAntes, $valor), 2);
}

/**
 * Promos servidor:
 * - Simples por producto: N_PAGA_M / NTH_PCT calculados sobre PRECIO LISTA (DB)
 * - Si hay promo simple, ignora precio manual (se pisa por promo) => consistente con tu front actual
 * - Combos: descuenta (suma_lista - precio_combo) y se distribuye proporcionalmente en los items del combo
 */
function calcular_totales_con_promos(array $items, array $promos): array {
  $promosAplicadas = [];

  $addPromo = function(array $row) use (&$promosAplicadas) {
    $promoId = (int)($row['promo_id'] ?? 0);
    $tipo    = (string)($row['promo_tipo'] ?? '');
    $key     = $promoId . '|' . $tipo;

    $monto = (float)($row['descuento_monto'] ?? 0);
    if ($monto <= 0) return;

    if (!isset($promosAplicadas[$key])) {
      $row['descuento_monto'] = round($monto, 2);
      $promosAplicadas[$key] = $row;
      return;
    }

    $promosAplicadas[$key]['descuento_monto'] =
      round(((float)$promosAplicadas[$key]['descuento_monto'] + $monto), 2);

    if (isset($row['meta']) && is_array($row['meta'])) {
      $promosAplicadas[$key]['meta'] = $promosAplicadas[$key]['meta'] ?? [];
      if (is_array($promosAplicadas[$key]['meta'])) {
        $promosAplicadas[$key]['meta'] = array_merge($promosAplicadas[$key]['meta'], $row['meta']);
      }
    }
  };

  $simplesByPid = [];
  foreach (($promos['simples'] ?? []) as $p) {
    if (!is_array($p)) continue;
    $pid = (int)($p['producto_id'] ?? 0);
    if ($pid <= 0) continue;
    $simplesByPid[$pid] = $p;
  }

  $combos = [];
  foreach (($promos['combos'] ?? []) as $c) {
    if (!is_array($c)) continue;
    $combos[] = $c;
  }

  // 1) promo simple por item
  foreach ($items as &$it) {
    $pid   = (int)$it['producto_id'];
    $cant  = (float)$it['cantidad'];
    $lista = (float)$it['precio_lista'];
    $precioActual = (float)$it['precio_actual'];

    $bruto = $cant * $lista;
    $neto  = $cant * $precioActual;

    $promo = $simplesByPid[$pid] ?? null;

    // ✅ aplica promos a pesables
    if ($promo) {
      $tipo = (string)($promo['tipo'] ?? '');
      $n    = (int)($promo['n'] ?? 0);
      $m    = isset($promo['m']) ? (int)$promo['m'] : 0;
      $pct  = isset($promo['porcentaje']) ? (float)$promo['porcentaje'] : 0.0;

      if ($n > 0) {
        if ($tipo === 'N_PAGA_M' && $m > 0 && $cant >= $n) {
          $packs = (int)floor($cant / $n);
          $resto = $cant - ($packs * $n);
          $pagar = ($packs * $m) + $resto;

          $neto = $pagar * $lista;
          $descuentoPromo = $bruto - $neto;

          if ($descuentoPromo > 0.00001) {
            $addPromo([
              'promo_id' => (int)($promo['promo_id'] ?? $promo['id'] ?? 0),
              'promo_tipo'      => 'N_PAGA_M',
              'promo_nombre'    => (string)($promo['nombre'] ?? 'Promo'),
              'descripcion'     => "Promo {$n}x{$m}",
              'descuento_monto' => round($descuentoPromo, 2),
              'meta' => [
                'producto_id' => $pid,
                'n' => $n,
                'm' => $m,
                'packs' => $packs,
                'resto' => $resto,
                'es_pesable' => !empty($it['es_pesable'])
              ],
            ]);
          }
        } elseif ($tipo === 'NTH_PCT' && $pct > 0 && $cant >= $n) {
          $uDesc = (int)floor($cant / $n);
          $desc  = ($uDesc * $lista * $pct) / 100.0;

          $neto = ($cant * $lista) - $desc;

          if ($desc > 0.00001) {
            $addPromo([
              'promo_id' => (int)($promo['promo_id'] ?? $promo['id'] ?? 0),
              'promo_tipo'      => 'NTH_PCT',
              'promo_nombre'    => (string)($promo['nombre'] ?? 'Promo'),
              'descripcion'     => "{$pct}% a la N°{$n}",
              'descuento_monto' => round($desc, 2),
              'meta' => [
                'producto_id' => $pid,
                'n' => $n,
                'porcentaje' => $pct,
                'u_desc' => $uDesc,
                'es_pesable' => !empty($it['es_pesable'])
              ],
            ]);
          }
        }
      }
    }

    $it['bruto'] = round($bruto, 2);
    $it['neto']  = round($neto, 2);
    $it['descuento'] = round($it['bruto'] - $it['neto'], 2);
  }
  unset($it);

  // 2) combos: descuento proporcional (✅ con tolerancia para pesables)
  foreach ($combos as $combo) {
    $precioCombo = (float)($combo['precio_combo'] ?? 0);
    $itemsReq    = $combo['items'] ?? [];
    if ($precioCombo <= 0 || !is_array($itemsReq) || !$itemsReq) continue;

    $maxCombos = PHP_INT_MAX;
    $sumaLista = 0.0;

    foreach ($itemsReq as $req) {
      $pid = (int)($req['producto_id'] ?? 0);
      $q   = (float)($req['cantidad'] ?? 0);
      if ($pid <= 0 || $q <= 0) { $maxCombos = 0; break; }

      $itKey = null;
      foreach ($items as $k => $it2) {
        if ((int)$it2['producto_id'] === $pid) { $itKey = $k; break; }
      }
      if ($itKey === null) { $maxCombos = 0; break; }

      $tiene = (float)$items[$itKey]['cantidad'];

      $esPesable = !empty($items[$itKey]['es_pesable']);
      $tolerance = $esPesable ? 0.01 : 0;
      $maxCombos = min($maxCombos, (int)floor(($tiene + $tolerance) / $q));

      $sumaLista += ((float)$items[$itKey]['precio_lista']) * $q;
    }

    if ($maxCombos <= 0 || $maxCombos === PHP_INT_MAX) continue;
    if ($sumaLista <= 0) continue;

    $descUnit = $sumaLista - $precioCombo;
    if ($descUnit <= 0) continue;

    $descTotalCombo = $descUnit * $maxCombos;

    $addPromo([
      'promo_id' => (int)($combo['promo_id'] ?? $combo['id'] ?? 0),
      'promo_tipo'      => 'COMBO_FIJO',
      'promo_nombre'    => (string)($combo['nombre'] ?? 'Combo'),
      'descripcion'     => "Combo fijo x{$maxCombos}",
      'descuento_monto' => round($descTotalCombo, 2),
      'meta' => ['combos'=>$maxCombos,'precio_combo'=>$precioCombo,'items'=>$itemsReq],
    ]);

    foreach ($itemsReq as $req) {
      $pid = (int)$req['producto_id'];
      $q   = (float)$req['cantidad'];

      $itKey = null;
      foreach ($items as $k => $it2) {
        if ((int)$it2['producto_id'] === $pid) { $itKey = $k; break; }
      }
      if ($itKey === null) continue;

      $base  = ((float)$items[$itKey]['precio_lista']) * $q;
      $share = $base / $sumaLista;
      $alloc = $descTotalCombo * $share;

      $items[$itKey]['neto'] = round(((float)$items[$itKey]['neto']) - $alloc, 2);
      $items[$itKey]['descuento'] = round(((float)$items[$itKey]['descuento']) + $alloc, 2);
    }
  }

  // ✅ Evitar netos negativos
  foreach ($items as &$itFix) {
    if ((float)($itFix['neto'] ?? 0) < 0) $itFix['neto'] = 0.0;
    $itFix['neto'] = round((float)($itFix['neto'] ?? 0), 2);
    $itFix['bruto'] = round((float)($itFix['bruto'] ?? 0), 2);
    $itFix['descuento'] = round(max(0.0, (float)$itFix['bruto'] - (float)$itFix['neto']), 2);
  }
  unset($itFix);

  $totalBruto = 0.0;
  $totalNeto  = 0.0;

  foreach ($items as $it3) {
    $totalBruto += (float)$it3['bruto'];
    $totalNeto  += (float)$it3['neto'];
  }

  $totalBruto = round($totalBruto, 2);
  $totalNeto  = round(max(0.0, $totalNeto), 2);
  $descTotal  = round(max(0.0, $totalBruto - $totalNeto), 2);

  return [
    'items' => $items,
    'total_bruto' => $totalBruto,
    'total_neto'  => $totalNeto,
    'descuento_total' => $descTotal,
    'promos_aplicadas' => array_values($promosAplicadas),
  ];
}

/* --------------------------------------------------------
   HELPERS API (permisos + CSRF)
-------------------------------------------------------- */
function require_perm_json(string $slug): void {
  if (function_exists('user_has_permission') && !user_has_permission($slug)) {
    json_fail('No autorizado', 403);
  }
}

function require_any_perm_json(array $slugs): void {
  if (!function_exists('user_has_permission')) return;
  foreach ($slugs as $s) {
    if (user_has_permission((string)$s)) return;
  }
  json_fail('No autorizado', 403);
}

function csrf_from_request(array $body): string {
  $h = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if ($h === '' && isset($_SERVER['HTTP_X_CSRF'])) $h = (string)$_SERVER['HTTP_X_CSRF'];
  return (string)($body['csrf'] ?? ($body['csrf_token'] ?? $h));
}

function require_csrf_json(array $body): void {
  $t = csrf_from_request($body);
  if (!csrf_check($t !== '' ? $t : null)) json_fail('CSRF inválido o ausente', 403);
}

function invalidate_promos_cache(PDO $pdo): void {
  $GLOBALS['pdo'] = $pdo;
  if (function_exists('invalidarCachePromos')) {
    invalidarCachePromos();
  }
}

// ------------------ ROUTER ------------------
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body   = read_request_body();


$action = (string)($_GET['action'] ?? ($body['action'] ?? ''));
/* ================================
   FLUS: action file dispatch
   Permite agregar endpoints en public/api/actions/{action}.php
   sin ensuciar el switch principal.
================================ */
$__actionFile = __DIR__ . '/actions/' . $action . '.php';
if (is_file($__actionFile)) {
  
  //  Asegurar PDO antes de ejecutar el action
  if (!isset($pdo) && function_exists('getPDO')) {
    $pdo = getPDO();
  }

require $__actionFile;
  exit;
}

function read_request_body(): array {
  // POST (FormData / x-www-form-urlencoded)
  if (!empty($_POST) && is_array($_POST)) {
    $body = $_POST;

    // si vienen JSON en strings (items/pagos/desc_global), decodificarlos
    foreach (['items','pagos','desc_global'] as $k) {
      if (isset($body[$k]) && is_string($body[$k])) {
        $tmp = json_decode($body[$k], true);
        if (is_array($tmp)) $body[$k] = $tmp;
      }
    }
    return $body;
  }

  // JSON
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

if ($action === '') json_fail('Acción requerida', 404);

try {

  switch ($action) {

    
    case 'health': {
      require_login_json();
      // No requiere CSRF (GET) - solo diagnóstico
      try {
        $pdo = getPDO();
        $pdo->query('SELECT 1');
      } catch (Throwable $e) {
        json_fail('DB_DOWN', 503);
      }

      $tables = ['users','productos','ventas','venta_items','terminales','terminal_locks'];
      $present = [];
      foreach ($tables as $t) {
        $ok = false;
        try {
          $st = $pdo->prepare('SHOW TABLES LIKE :t');
          $st->execute([':t' => $t]);
          $ok = (bool)$st->fetchColumn();
        } catch (Throwable $e) {
          $ok = false;
        }
        $present[$t] = $ok;
      }

      json_ok([
        'db_ok' => true,
        'tables' => $present,
        'time' => date('c'),
        'php' => PHP_VERSION,
      ]);
    }

case 'buscar_producto': {
      require_login_json();
      require_perm_json('realizar_ventas');
      if ($method !== 'GET') json_fail('Método no permitido', 405);

      $codigo = trim((string)($_GET['codigo'] ?? ''));
      if ($codigo === '') json_fail('Código vacío', 422);

      $pdo = getPDO();
      $stmt = $pdo->prepare("
        SELECT id, codigo, nombre, precio, stock, activo, es_pesable, unidad_venta
        FROM productos
        WHERE codigo = :cod
        LIMIT 1
      ");
      $stmt->execute([':cod' => $codigo]);
      $p = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$p || (int)$p['activo'] !== 1) {
        json_fail('Producto no encontrado o inactivo', 404);
      }

      $p['precio']       = (float)$p['precio'];
      $p['stock']        = (float)$p['stock'];
      $p['es_pesable']   = ((int)$p['es_pesable'] === 1);
      $p['unidad_venta'] = $p['unidad_venta'] ?: 'UNIDAD';

      json_ok(['producto' => $p]);
    }

    case 'listar_promos_activas': {
      require_login_json();
      require_perm_json('realizar_ventas');
      if ($method !== 'GET') json_fail('Método no permitido', 405);

      $pdo = getPDO();

      try {
        $promos = obtenerPromosActivas($pdo);
      } catch (Throwable $e) {
        error_log("listar_promos_activas fallo: " . $e->getMessage());
        $promos = ['simples' => [], 'combos' => []];
      }

      json_ok([
        'simples' => $promos['simples'] ?? [],
        'combos'  => $promos['combos']  ?? [],
      ]);
    }

        /* =========================================================
       TERMINALES (selección/lock)
    ========================================================= */
    case 'terminal_list': {
      require_login_json();
      if ($method !== 'GET') json_fail('Método no permitido', 405);

      $pdo = getPDO();
      $terminales = terminal_list($pdo);
      $currentTid = terminal_current_id($pdo);
      if ($currentTid > 0) $_SESSION['terminal_id'] = $currentTid;

      json_ok([
        'terminales' => $terminales,
        'current' => $currentTid,
        // compat front
        'terminals' => $terminales,
        'current_terminal_id' => $currentTid
      ]);
    }

    case 'terminal_select': {
      require_login_json();
      require_csrf_json($body);
      if ($method !== 'POST') json_fail('Método no permitido', 405);

      $pdo = getPDO();
      $ttl = 90;

      terminal_locks_gc($pdo, $ttl);

      $requestedTerminalId = (int)($body['terminal_id'] ?? 0);

      $currentTid = terminal_current_id($pdo);
      if ($currentTid > 0) $_SESSION['terminal_id'] = $currentTid;

      // ✅ si no mandan terminal_id -> devolvemos lista
      if ($requestedTerminalId <= 0) {
        $terminales = terminal_list($pdo);
        json_ok([
          'terminales' => $terminales,
          'current' => $currentTid,
          'terminals' => $terminales,
          'current_terminal_id' => $currentTid
        ]);
      }

      $tNew = terminal_get($pdo, $requestedTerminalId);
      if (!$tNew || (int)($tNew['activo'] ?? 0) !== 1) {
        json_fail('Terminal inválida', 400);
      }

      $user = current_user();
      $uid  = (int)($user['id'] ?? 0);
      $sid  = session_id();

      // ✅ Si hay caja abierta en la terminal actual, NO permitimos CAMBIAR a otra
      if ($currentTid > 0 && $requestedTerminalId !== $currentTid) {
        $open = caja_get_abierta($pdo, $currentTid);
        if (is_array($open) && !empty($open['id'])) {
          json_fail('CAJA_ABIERTA', 409);
        }
      }

      // ✅ Si estamos cambiando de terminal, liberamos el lock anterior de este usuario (best effort)
      if ($currentTid > 0 && $requestedTerminalId !== $currentTid && $uid > 0) {
        terminal_lock_release($pdo, $currentTid, $uid);
      }

      // ✅ Adquirir/renovar lock para la terminal pedida (incluye el caso "misma terminal")
      $res = terminal_lock_acquire($pdo, $requestedTerminalId, $uid, $sid, $ttl);
      if (!($res['ok'] ?? false)) {
        $err  = (string)($res['error'] ?? 'LOCK_FAIL');
        $code = ($err === 'DB_ERROR' || $err === 'DB_DOWN' || $err === 'NO_LOCK_TABLE' || $err === 'LOCK_SCHEMA') ? 503 : 409;
        json_fail($err, $code, ['detail' => $res]);
      }

      terminal_set_cookie($requestedTerminalId);
      $_SESSION['terminal_id'] = $requestedTerminalId;

      json_ok([
        'terminal_id' => $requestedTerminalId,
        'terminal_nombre' => (string)($tNew['nombre'] ?? ('Caja #' . $requestedTerminalId)),
      ]);
    }

    case 'terminal_switch': {
      require_login_json();
      require_csrf_json($body);
      if ($method !== 'POST') json_fail('Método no permitido', 405);

      $pdo = getPDO();
      $user = current_user();
      $uid  = (int)($user['id'] ?? 0);

      $newTid = (int)($body['terminal_id'] ?? 0);
      if ($newTid <= 0) json_fail('Terminal inválida', 400);

      $oldTid = terminal_current_id($pdo);
      if ($oldTid > 0) $_SESSION['terminal_id'] = $oldTid;

      if ($oldTid > 0 && $oldTid !== $newTid) {
        terminal_lock_release($pdo, $oldTid, $uid);
      }

      terminal_set_cookie($newTid);
      $_SESSION['terminal_id'] = $newTid;

      json_ok();
    }

    case 'terminal_heartbeat': {
      require_login_json();
      require_csrf_json($body);
      if ($method !== 'POST') json_fail('Método no permitido', 405);

      $pdo = getPDO();
      $user = current_user();
      $uid  = (int)($user['id'] ?? 0);
      $sid  = session_id();
      $ttl  = 90;

      $tid = (int)($_SESSION['terminal_id'] ?? 0);
      if ($tid <= 0) {
        $tid = terminal_current_id($pdo); // cookie fallback
      }

      if ($tid <= 0) {
        json_fail('NO_TERMINAL', 409);
      }

      $_SESSION['terminal_id'] = $tid;

      terminal_locks_gc($pdo, $ttl);

      $res = terminal_lock_heartbeat($pdo, $tid, $uid, $sid, $ttl);

      if (!($res['ok'] ?? false)) {

        // ✅ Auto-recovery: si perdimos el lock por sesión/renovación,
        // intentamos re-adquirirlo (si está libre o sigue siendo nuestro).
        $err = (string)($res['error'] ?? '');
        if ($err === 'LOCK_NOT_OWNED' || $err === 'LOCK_LOST') {

          $try = terminal_lock_acquire($pdo, $tid, $uid, $sid, $ttl);

          if (($try['ok'] ?? false) === true) {
            json_ok(['reacquired' => true]);
          }

          json_fail((string)($try['error'] ?? $err), 409, ['detail' => $try]);
        }

        json_fail($err !== '' ? $err : 'LOCK_FAIL', 409, ['detail' => $res]);
      }

      json_ok();
    }


    /* =========================================================
       VENTA (registrar_venta con split payments + promos + desc global)
    ========================================================= */
    case 'registrar_venta': {
      require_login_json();
      require_terminal_lock_json();
      if (function_exists('user_has_permission') && !user_has_permission('realizar_ventas')) {
        json_fail('No autorizado', 403);
      }

      $csrf = (string)($body['csrf'] ?? ($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
      if (!csrf_check($csrf)) json_fail('CSRF inválido o ausente', 403);

      $pdo = getPDO();
      $user = current_user();
      $userId = (int)($user['id'] ?? 0);

      $itemsIn = $body['items'] ?? null;
        if (is_string($itemsIn)) {
          $itemsIn = json_decode($itemsIn, true);
        }

        $descGlobalRaw = $body['desc_global'] ?? null;
        if (is_string($descGlobalRaw)) {
          $descGlobalRaw = json_decode($descGlobalRaw, true);
        }
        $descGlobalReq = parse_desc_global($descGlobalRaw);

        $pagosIn = $body['pagos'] ?? null;
        if (is_string($pagosIn)) {
          $pagosIn = json_decode($pagosIn, true);
        }

      if (!is_array($itemsIn) || !$itemsIn) json_fail('Ticket vacío', 422);

  

      $puedeCambiarPrecio = function_exists('user_has_permission') && user_has_permission('caja_modificar_precio');
      if (!$puedeCambiarPrecio) $descGlobalReq = null;

      // Agrupar por producto
      $agg = [];
      foreach ($itemsIn as $it) {
        if (!is_array($it)) continue;
        $pid = (int)($it['id'] ?? $it['producto_id'] ?? 0);
        if ($pid <= 0) continue;

        $cant = parse_num($it['cantidad'] ?? 0);
        if ($cant <= 0) continue;

        $precioReq = isset($it['precio']) ? parse_num($it['precio']) : 0.0;

        if (!isset($agg[$pid])) {
          $agg[$pid] = ['producto_id' => $pid, 'cantidad' => 0.0, 'precio_req' => $precioReq];
        }
        $agg[$pid]['cantidad'] += $cant;
        if ($precioReq > 0) $agg[$pid]['precio_req'] = $precioReq;
      }

      $items = array_values($agg);
      if (!$items) json_fail('Items inválidos', 422);

      $terminalId = (int)($_SESSION['terminal_id'] ?? current_terminal_id());
      $caja = caja_get_abierta($pdo, $terminalId);
      $cajaId = (int)($caja['id'] ?? 0);
      if ($cajaId <= 0) json_fail('No hay caja abierta', 409);

      try {
        $pdo->beginTransaction();

        $promos = obtenerPromosActivas($pdo);

        $stmtP = $pdo->prepare("
          SELECT id, nombre, precio, stock, activo, es_pesable
          FROM productos
          WHERE id = :id
          FOR UPDATE
        ");

        $srvItems = [];
        $totalProductos = 0.0;

        foreach ($items as $it) {
          $pid = (int)$it['producto_id'];
          $cant = (float)$it['cantidad'];

          $stmtP->execute([':id' => $pid]);
          $p = $stmtP->fetch(PDO::FETCH_ASSOC);

          if (!$p) throw new RuntimeException("Producto #{$pid} no existe");
          if ((int)$p['activo'] !== 1) throw new RuntimeException("Producto inactivo: {$p['nombre']}");

          $esPesable = ((int)($p['es_pesable'] ?? 0) === 1);
          if (!$esPesable) {
            if (abs($cant - round($cant)) > 0.00001) {
              throw new RuntimeException("Cantidad inválida para {$p['nombre']} (no es pesable)");
            }
            $cant = (float)(int)round($cant);
          }

          $stock = (float)($p['stock'] ?? 0);
          $eps = $esPesable ? 0.0005 : 0.0;
          if ($cant > $stock + $eps) {
            throw new RuntimeException("Stock insuficiente para {$p['nombre']} (disponible: {$stock}, solicitado: {$cant})");
          }

          $precioLista = (float)$p['precio'];
          $precioActual = $precioLista;

          if ($puedeCambiarPrecio) {
            $pr = (float)$it['precio_req'];
            if ($pr > 0) $precioActual = $pr;
            if ($precioActual <= 0) throw new RuntimeException("Precio inválido para {$p['nombre']}");
          }

          $totalProductos += $cant;

          $srvItems[] = [
            'producto_id'   => $pid,
            'cantidad'      => $cant,
            'precio_lista'  => $precioLista,
            'precio_actual' => $precioActual,
            'nombre'        => (string)$p['nombre'],
            'es_pesable'    => $esPesable ? 1 : 0,
          ];
        }

        $calc = calcular_totales_con_promos($srvItems, $promos);
        $srvItems = $calc['items'];

        $totalBruto = (float)$calc['total_bruto'];
        $totalNetoSinGlobal  = (float)$calc['total_neto'];
        $descTotalSinGlobal  = (float)$calc['descuento_total'];

        $descGlobalMonto = calc_desc_global($totalNetoSinGlobal, $descGlobalReq);
        $totalNetoFinal  = round(max(0.0, $totalNetoSinGlobal - $descGlobalMonto), 2);
        $descTotalFinal  = round($descTotalSinGlobal + $descGlobalMonto, 2);

        if ($descGlobalMonto > 0.00001) {
          $tipo = $descGlobalReq['tipo'] ?? 'monto';
          $val  = (float)($descGlobalReq['valor'] ?? 0);

          $calc['promos_aplicadas'][] = [
            'promo_id'        => 0,
            'promo_tipo'      => 'DESC_GLOBAL',
            'promo_nombre'    => 'Descuento total',
            'descripcion'     => ($tipo === 'porcentaje') ? ($val . '%') : ('-$' . number_format($val, 2, ',', '.')),
            'descuento_monto' => round($descGlobalMonto, 2),
            'meta'            => ['tipo' => $tipo, 'valor' => $val, 'aplicado_por_user_id' => $userId],
          ];
        }

        // ===============================
        // SPLIT PAYMENTS (pagos[])
        // ===============================
        $pagosValidos = [];

        if (is_array($pagosIn) && count($pagosIn) > 0) {
          foreach ($pagosIn as $p) {
            if (!is_array($p)) continue;
            $pm = norm_medio_pago((string)($p['medio'] ?? 'EFECTIVO'));
            $mx = parse_num($p['monto'] ?? 0);
            if ($mx <= 0) continue;
            $pagosValidos[] = ['medio' => $pm, 'monto' => round($mx, 2)];
          }
        }

        // fallback legacy si no vino pagos[]
        if (!$pagosValidos) {
          $medioLegacy = norm_medio_pago((string)($body['medio_pago'] ?? 'EFECTIVO'));
          $montoLegacy = parse_num($body['monto_pagado'] ?? 0);
          if ($montoLegacy <= 0) $montoLegacy = $totalNetoFinal;
          $pagosValidos[] = ['medio' => $medioLegacy, 'monto' => round($montoLegacy, 2)];
        }

        $totalPagado = 0.0;
        $tieneEfectivo = false;
        foreach ($pagosValidos as $pg) {
          $totalPagado += (float)$pg['monto'];
          if ($pg['medio'] === 'EFECTIVO') $tieneEfectivo = true;
        }
        $totalPagado = round($totalPagado, 2);

        if ($totalPagado + 1e-6 < $totalNetoFinal) {
          throw new RuntimeException('Pago insuficiente');
        }

        // Si NO hay efectivo, no permitimos sobrepago (no hay "vuelto" real)
        if (!$tieneEfectivo && $totalPagado > $totalNetoFinal + 0.01) {
          throw new RuntimeException('Sobrepago sin efectivo (no se puede dar vuelto)');
        }

        $vuelto = $tieneEfectivo ? round(max(0.0, $totalPagado - $totalNetoFinal), 2) : 0.0;

        // Medio principal por compatibilidad (el de mayor monto)
        usort($pagosValidos, fn($a,$b) => $b['monto'] <=> $a['monto']);
        $medio = (string)$pagosValidos[0]['medio'];
        $montoPagado = $totalPagado;

        $ventaId = insert_dynamic($pdo, 'ventas', [
          'user_id'         => ($userId > 0 ? $userId : null),
          'caja_id'         => $cajaId,
          'total'           => $totalNetoFinal,
          'total_bruto'     => $totalBruto,
          'descuento_total' => $descTotalFinal,
          'medio_pago'      => $medio,
          'monto_pagado'    => $montoPagado,
          'vuelto'          => $vuelto,
          'estado'          => 'EMITIDA',
        ]);

        // Guardar pagos múltiples (si existe tabla)
        if (
          has_col($pdo, 'venta_pagos', 'venta_id') &&
          has_col($pdo, 'venta_pagos', 'medio_pago') &&
          has_col($pdo, 'venta_pagos', 'monto')
        ) {
          $stPago = $pdo->prepare("INSERT INTO venta_pagos (venta_id, medio_pago, monto) VALUES (?, ?, ?)");
          foreach ($pagosValidos as $pg) {
            $stPago->execute([$ventaId, $pg['medio'], $pg['monto']]);
          }
        }

        foreach ($srvItems as $it) {
          $pid  = (int)$it['producto_id'];
          $cant = (float)$it['cantidad'];
          $neto = (float)$it['neto'];
          $lista = (float)$it['precio_lista'];
          $desc  = (float)$it['descuento'];

          $precioUnitFinal = ($cant > 0) ? round($neto / $cant, 2) : 0.0;

          insert_dynamic($pdo, 'venta_items', [
            'venta_id'            => $ventaId,
            'producto_id'         => $pid,
            'cantidad'            => $cant,
            'precio'              => $precioUnitFinal,
            'subtotal'            => $neto,
            'precio_unit_original'=> $lista,
            'descuento_monto'     => $desc,
            'precio_unit_final'   => $precioUnitFinal,
          ]);

          $st = $pdo->prepare("UPDATE productos SET stock = stock - :c WHERE id = :id");
          $st->execute([':c' => $cant, ':id' => $pid]);

          insert_dynamic($pdo, 'movimientos_stock', [
            'producto_id'         => $pid,
            'tipo'                => 'VENTA',
            'cantidad'            => $cant,
            'venta_id'            => $ventaId,
            'referencia_venta_id' => $ventaId,
            'comentario'          => null,
            'fecha'               => date('Y-m-d H:i:s'),
          ]);
        }

        // Guardar promos aplicadas (si existe tabla)
        $promosAplicadas = $calc['promos_aplicadas'] ?? [];

        $cut = function(string $s, int $n): string {
          return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
        };

        if (is_array($promosAplicadas) && count($promosAplicadas) > 0) {
          foreach ($promosAplicadas as $p) {
            if (!is_array($p)) continue;

            $promoId  = isset($p['promo_id']) ? (int)$p['promo_id'] : null;
            $tipo     = trim((string)($p['promo_tipo'] ?? ''));
            $nombre   = trim((string)($p['promo_nombre'] ?? ''));
            $descTxt  = trim((string)($p['descripcion'] ?? ''));
            $monto    = (float)($p['descuento_monto'] ?? 0);
            $meta     = $p['meta'] ?? null;

            if ($tipo === '' || $nombre === '' || $monto <= 0) continue;

            $monto = round($monto, 2);

            insert_dynamic($pdo, 'venta_promos', [
              'venta_id'        => $ventaId,
              'promo_id'        => ($promoId && $promoId > 0) ? $promoId : null,
              'promo_tipo'      => $cut($tipo, 20),
              'promo_nombre'    => $cut($nombre, 120),
              'descripcion'     => ($descTxt !== '') ? $cut($descTxt, 255) : null,
              'descuento_monto' => $monto,
              'meta'            => ($meta === null) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
          }
        }

        // Totales generales de la venta (una sola vez)
        update_caja_venta_totales($pdo, $cajaId, $totalNetoFinal, $totalProductos);

        // Totales por medio (split)
        foreach ($pagosValidos as $pg) {
          update_caja_medio_delta($pdo, $cajaId, $pg['medio'], (float)$pg['monto']);
        }

        // Vuelto sale de efectivo
        if ($vuelto > 0.00001) {
          update_caja_medio_delta($pdo, $cajaId, 'EFECTIVO', -$vuelto);
        }

        $pdo->commit();

        json_ok([
          'venta_id'        => $ventaId,
          'total'           => $totalNetoFinal,
          'total_bruto'     => $totalBruto,
          'descuento_total' => $descTotalFinal,
          'medio_pago'      => $medio,
          'monto_pagado'    => $montoPagado,
          'vuelto'          => $vuelto,
          'desc_global_monto' => $descGlobalMonto,
          'pagos' => $pagosValidos,
          'total_pagado' => $montoPagado,
        ]);

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error en registrar_venta: " . $e->getMessage() . " | User: {$userId} | Caja: {$cajaId}");
        json_fail($e->getMessage(), 500);
      }
    }

    default:
      json_fail('Acción inválida', 404);
  }

} catch (Throwable $e) {
  json_fail($e->getMessage(), 500);
}
