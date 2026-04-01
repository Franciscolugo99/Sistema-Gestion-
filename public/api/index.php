<?php
declare(strict_types=1);
// public/api/index.php
// v2.2.0 - Refactorizado con helpers centralizados

// ? Indicar que estamos en contexto API (para que bootstrap no devuelva HTML)
define('FLUS_API_CONTEXT', true);

require_once __DIR__ . '/../lib/root.php';


// ? Modo mantenimiento: bloquear API mientras se restaura la DB
$maintenanceFlag = FLUS_ROOT . '/storage/maintenance.flag';
if (is_file($maintenanceFlag)) {
  if (ob_get_length()) ob_clean();
  http_response_code(503);
  echo json_encode(['ok'=>false,'error'=>'MAINTENANCE','hint'=>'Sistema en mantenimiento. Reintentá luego.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}


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
require_once __DIR__ . '/../../src/api_helpers.php';  // ? Helpers centralizados
require_once __DIR__ . '/../../src/cobranzas_lib.php';
require_once __DIR__ . '/../../src/venta_api_lib.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/terminal.php';
require_once __DIR__ . '/../caja_lib.php';
require_once __DIR__ . '/../promos_logic.php';
require_once __DIR__ . '/../includes/CuentaCorrienteController.php'; // ? CC

// ? Configurar handlers de error para API
setup_api_error_handlers();

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

// ? FIX: delta por medio nunca puede quedar negativo
function update_caja_medio_delta(PDO $pdo, int $cajaId, string $medio, float $delta): void {
  if ($cajaId <= 0) return;

  $m = strtoupper(trim($medio));
  
  // Mapeo estricto: cada medio a su columna correspondiente
  // CRÍTICO: TRANSFERENCIA NO debe ir a total_efectivo
  switch ($m) {
    case 'EFECTIVO':
      $campo = 'total_efectivo';
      break;
    case 'MP':
    case 'MERCADOPAGO':
      $campo = 'total_mp';
      break;
    // BUG-06 FIX: MODO y QR no tienen columna propia en caja_sesiones.
    // Se acumulan en total_mp (pagos digitales). Si en el futuro se agrega
    // total_modo/total_qr, solo cambiar el mapeo aquí.
    case 'MODO':
    case 'QR':
      $campo = 'total_mp';
      break;
    case 'DEBITO':
      $campo = 'total_debito';
      break;
    case 'CREDITO':
      $campo = 'total_credito';
      break;
    case 'TRANSFERENCIA':
    case 'TRANSFER':
      $campo = 'total_transferencia';
      break;
    case 'CC':
      // Cuenta Corriente NO suma a ningún total de caja
      // (la plata entra cuando se cobra, no cuando se vende)
      return;
    default:
      // Medio no soportado - loggear pero no romper
      error_log("update_caja_medio_delta: Medio de pago no soportado '{$medio}'");
      return;
  }

  // Verificar que la columna existe (compatibilidad instalaciones viejas)
  if (!has_col($pdo, 'caja_sesiones', $campo)) {
    error_log("update_caja_medio_delta: Columna {$campo} no existe en caja_sesiones");
    return;
  }

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

    // ? aplica promos a pesables
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

  // 2) combos: descuento proporcional (? con tolerancia para pesables)
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

  // ? Evitar netos negativos
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
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') return;

  $t = csrf_from_request($body);

  // CSRF obligatorio para requests con sesión (POST/PUT/PATCH/DELETE)
  if ($t === '' || !function_exists('csrf_verify') || !csrf_verify($t)) {
    json_fail('CSRF', 419, ['hint' => 'Token CSRF inválido o ausente. Recargá la página e intentá de nuevo.']);
  }
}

function invalidate_promos_cache(PDO $pdo): void {
  $GLOBALS['pdo'] = $pdo;
  if (function_exists('invalidarCachePromos')) {
    invalidarCachePromos();
  }
}

function flus_action_guard_policies(): array {
  return [
    'anular_items_venta' => [
      'methods' => ['POST'],
      'permissions' => ['anular_items_venta'],
      'csrf' => true,
    ],
    'anular_venta' => [
      'methods' => ['POST'],
      'permissions' => ['anular_venta'],
      'csrf' => true,
    ],
    'buscar_clientes_cc' => [
      'methods' => ['GET'],
      'any_permissions' => ['registrar_cargo_cc', 'registrar_pago_cc', 'ver_cuenta_corriente'],
    ],
    'buscar_producto' => [
      'methods' => ['GET'],
      'permissions' => ['realizar_ventas'],
    ],
    'buscar_productos' => [
      'methods' => ['GET'],
      'any_permissions' => ['realizar_ventas', 'emitir_factura'],
    ],
    'calcular_carrito' => [
      'methods' => ['POST'],
      'permissions' => ['realizar_ventas'],
      'csrf' => true,
    ],
    'cliente_consultar_cuit' => [
      'methods' => ['GET'],
    ],
    'listar_promos_activas' => [
      'methods' => ['GET'],
      'permissions' => ['realizar_ventas'],
    ],
    'terminal_list' => [
      'methods' => ['GET'],
    ],
    'terminal_select' => [
      'methods' => ['POST'],
      'csrf' => true,
    ],
    'terminal_switch' => [
      'methods' => ['POST'],
      'csrf' => true,
    ],
    'terminal_heartbeat' => [
      'methods' => ['POST'],
      'csrf' => true,
    ],
    'session_heartbeat' => [
      'methods' => ['POST'],
      'csrf' => true,
    ],
    'promo_actualizar' => [
      'methods' => ['POST'],
      'permissions' => ['editar_promos'],
      'csrf' => true,
    ],
    'promo_eliminar' => [
      'methods' => ['POST'],
      'permissions' => ['editar_promos'],
      'csrf' => true,
    ],
    'promo_obtener' => [
      'methods' => ['GET'],
      'permissions' => ['editar_promos'],
    ],
    'promo_productos' => [
      'methods' => ['GET'],
      'permissions' => ['editar_promos'],
    ],
    'registrar_venta' => [
      'methods' => ['POST'],
      'permissions' => ['realizar_ventas'],
      'csrf' => true,
    ],
    'verificar_cc' => [
      'methods' => ['GET', 'POST'],
      'any_permissions' => ['registrar_cargo_cc', 'registrar_pago_cc', 'ver_cuenta_corriente'],
    ],
  ];
}

function flus_enforce_action_guard(string $action, array $body): void {
  $policies = flus_action_guard_policies();
  $policy = $policies[$action] ?? [];

  require_login_json();

  if (!empty($policy['methods'])) {
    require_method_json($policy['methods']);
  }

  if (!empty($policy['permissions']) && is_array($policy['permissions'])) {
    foreach ($policy['permissions'] as $permission) {
      require_perm_json((string)$permission);
    }
  }

  if (!empty($policy['any_permissions']) && is_array($policy['any_permissions'])) {
    require_any_perm_json($policy['any_permissions']);
  }

  if (!empty($policy['csrf'])) {
    require_csrf_json($body);
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
   
   ? SEGURIDAD: Validar $action para evitar path traversal
================================ */

// Sanitización: solo permitir caracteres alfanuméricos y guión bajo
if ($action !== '' && !preg_match('/^[a-z0-9_]+$/i', $action)) {
  json_fail('Acción inválida', 400);
}

$__actionFile = __DIR__ . '/actions/' . $action . '.php';
if ($action !== '' && is_file($__actionFile)) {
  flus_enforce_action_guard($action, $body);

  // ? Seguridad por defecto para endpoints actions/*
  // - Permitimos sin login SOLO endpoints explícitos de diagnóstico/compat.


    // Permisos puntuales (evita exponer catálogo desde afuera)

    // CSRF para cualquier acción state-changing

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
          if (function_exists('flus_table_exists')) {
            $ok = (bool)flus_table_exists($pdo, $t);
          } elseif (function_exists('has_table')) {
            $ok = (bool)has_table($pdo, $t);
          }
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



    default:
      json_fail('Acción inválida', 404);
  }

} catch (Throwable $e) {
  json_fail($e->getMessage(), 500);
}
