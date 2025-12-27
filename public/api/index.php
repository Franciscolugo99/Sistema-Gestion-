<?php
// public/api/index.php
declare(strict_types=1);

// API JSON: nunca romper por warnings/HTML
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../caja_lib.php';
require_once __DIR__ . '/../promos_logic.php'; // obtenerPromosActivas()

function json_ok(array $data = [], int $code = 200): void {
  if (ob_get_length()) ob_clean();
  http_response_code($code);
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function json_fail(string $msg, int $code = 400, array $extra = []): void {
  if (ob_get_length()) ob_clean();
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

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

function has_col(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k = $table . '.' . $col;
  if (array_key_exists($k, $cache)) return (bool)$cache[$k];

  $st = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table, $col]);
  $ok = (bool)$st->fetchColumn();
  $cache[$k] = $ok;
  return $ok;
}

function insert_dynamic(PDO $pdo, string $table, array $data): int {
  $cols = [];
  $ph   = [];
  $params = [];
  foreach ($data as $col => $val) {
    if (!has_col($pdo, $table, $col)) continue;
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

function update_caja_sum(PDO $pdo, int $cajaId, string $medio, float $importe, float $productos): void {
  if ($cajaId <= 0) return;
  if (!has_col($pdo, 'caja_sesiones', 'id')) return;

  // Campos opcionales
  $sets = [];
  $params = [':id' => $cajaId];

  if (has_col($pdo, 'caja_sesiones', 'total_ventas')) {
    // total_ventas es CONTADOR (ver tu anular_venta que resta 1)
    $sets[] = "total_ventas = COALESCE(total_ventas,0) + 1";
  }
  if (has_col($pdo, 'caja_sesiones', 'total_productos')) {
    $sets[] = "total_productos = COALESCE(total_productos,0) + :tp";
    $params[':tp'] = $productos;
  }

  $campoMP = null;
  $medio = strtoupper($medio);
  if ($medio === 'MP') $campoMP = 'total_mp';
  elseif ($medio === 'DEBITO') $campoMP = 'total_debito';
  elseif ($medio === 'CREDITO') $campoMP = 'total_credito';
  else $campoMP = 'total_efectivo';

  if ($campoMP && has_col($pdo, 'caja_sesiones', $campoMP)) {
    $sets[] = "{$campoMP} = COALESCE({$campoMP},0) + :imp";
    $params[':imp'] = $importe;
  }

  if (!$sets) return;

  $sql = "UPDATE caja_sesiones SET " . implode(", ", $sets) . " WHERE id = :id";
  $st = $pdo->prepare($sql);
  $st->execute($params);
}

/**
 * Promos servidor (mismo criterio que tu caja.js):
 * - Simples por producto: N_PAGA_M / NTH_PCT calculados sobre PRECIO LISTA (DB)
 * - Si hay promo simple, ignora descuento manual del precio (como tu front hoy)
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

  // simples por producto
  $simplesByPid = [];
  foreach (($promos['simples'] ?? []) as $p) {
    if (!is_array($p)) continue;
    $pid = (int)($p['producto_id'] ?? 0);
    if ($pid <= 0) continue;
    $simplesByPid[$pid] = $p;
  }

  // combos
  $combos = [];
  foreach (($promos['combos'] ?? []) as $c) {
    if (!is_array($c)) continue;
    $combos[] = $c;
  }

  // 1) aplicar promo simple por item
  foreach ($items as &$it) {
    $pid   = (int)$it['producto_id'];
    $cant  = (float)$it['cantidad'];
    $lista = (float)$it['precio_lista'];
    $precioActual = (float)$it['precio_actual'];

    $bruto = $cant * $lista;
    $neto  = $cant * $precioActual;

    $promo = $simplesByPid[$pid] ?? null;

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
              'promo_id'        => (int)($promo['id'] ?? 0),
              'promo_tipo'      => 'N_PAGA_M',
              'promo_nombre'    => (string)($promo['nombre'] ?? 'Promo'),
              'descripcion'     => "Promo {$n}x{$m}",
              'descuento_monto' => round($descuentoPromo, 2),
              'meta' => ['producto_id'=>$pid,'n'=>$n,'m'=>$m,'packs'=>$packs,'resto'=>$resto],
            ]);
          }

        } elseif ($tipo === 'NTH_PCT' && $pct > 0 && $cant >= $n) {
          $uDesc = (int)floor($cant / $n);
          $desc  = ($uDesc * $lista * $pct) / 100.0;

          $neto = ($cant * $lista) - $desc;

          if ($desc > 0.00001) {
            $addPromo([
              'promo_id'        => (int)($promo['id'] ?? 0),
              'promo_tipo'      => 'NTH_PCT',
              'promo_nombre'    => (string)($promo['nombre'] ?? 'Promo'),
              'descripcion'     => "{$pct}% a la N°{$n}",
              'descuento_monto' => round($desc, 2),
              'meta' => ['producto_id'=>$pid,'n'=>$n,'porcentaje'=>$pct,'u_desc'=>$uDesc],
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

  // 2) combos: descuento proporcional
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
      $maxCombos = min($maxCombos, (int)floor($tiene / $q));

      $sumaLista += ((float)$items[$itKey]['precio_lista']) * $q;
    }

    if ($maxCombos <= 0 || $maxCombos === PHP_INT_MAX) continue;
    if ($sumaLista <= 0) continue;

    $descUnit = $sumaLista - $precioCombo;
    if ($descUnit <= 0) continue;

    $descTotalCombo = $descUnit * $maxCombos;

    $addPromo([
      'promo_id'        => (int)($combo['id'] ?? 0),
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

// ------------------ ROUTER ------------------
$action = (string)($_GET['action'] ?? '');
if ($action !== 'registrar_venta') json_fail('Acción inválida', 404);

require_login_json();

$body = read_json_body();

// CSRF: header o body
$csrf = (string)($body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!csrf_check($csrf)) json_fail('CSRF inválido o ausente', 403);

$pdo = getPDO();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

$itemsIn = $body['items'] ?? null;
if (!is_array($itemsIn) || !$itemsIn) json_fail('Ticket vacío', 422);

$medio = norm_medio_pago((string)($body['medio_pago'] ?? 'EFECTIVO'));
$montoPagado = parse_num($body['monto_pagado'] ?? 0);

// permiso para cambiar precio
$puedeCambiarPrecio = function_exists('user_has_permission') && user_has_permission('caja_modificar_precio');

// agrupar items por producto
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

// caja abierta
$caja = caja_get_abierta($pdo);
$cajaId = (int)($caja['id'] ?? 0);
if ($cajaId <= 0) json_fail('No hay caja abierta', 409);

try {
  $pdo->beginTransaction();

  // promos activas
  $promos = obtenerPromosActivas($pdo);

  // lock productos + armar items con precio lista / precio actual
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

    $stock = (float)$p['stock'];
    if ($stock > 0 && $cant > $stock + 1e-9) {
      throw new RuntimeException("Stock insuficiente para {$p['nombre']} (stock: {$stock}, solicitado: {$cant})");
    }

    $precioLista = (float)$p['precio'];
    $precioActual = $precioLista;

    // permitir precio manual si tiene permiso
    if ($puedeCambiarPrecio) {
      $pr = (float)$it['precio_req'];
      if ($pr > 0) $precioActual = $pr;
      if ($precioActual < 0) throw new RuntimeException("Precio inválido para {$p['nombre']}");
    }

    $totalProductos += $cant;

    $srvItems[] = [
      'producto_id'   => $pid,
      'cantidad'      => $cant,
      'precio_lista'  => $precioLista,
      'precio_actual' => $precioActual,
      'nombre'        => (string)$p['nombre'],
    ];
  }

  // calcular total con promos/combos (NETO)
  $calc = calcular_totales_con_promos($srvItems, $promos);
  $srvItems = $calc['items'];
  $totalBruto = (float)$calc['total_bruto'];
  $totalNeto  = (float)$calc['total_neto'];
  $descTotal  = (float)$calc['descuento_total'];

  // pago: si no es efectivo, forzar pagado = total
  if ($medio !== 'EFECTIVO') {
    $montoPagado = $totalNeto;
  } else {
    if ($montoPagado + 1e-6 < $totalNeto) {
      throw new RuntimeException('Pago insuficiente');
    }
  }
  $vuelto = ($medio === 'EFECTIVO') ? round(max(0.0, $montoPagado - $totalNeto), 2) : 0.0;

  // INSERT ventas (adaptativo: si no existe user_id, no lo usa)
  $ventaId = insert_dynamic($pdo, 'ventas', [
    'user_id'         => ($userId > 0 ? $userId : null), // si tu tabla no lo tiene, se ignora
    'caja_id'         => $cajaId,
    'total'           => $totalNeto,
    'total_bruto'     => $totalBruto,    // si existe
    'descuento_total' => $descTotal,     // si existe
    'medio_pago'      => $medio,
    'monto_pagado'    => $montoPagado,
    'vuelto'          => $vuelto,
    'estado'          => 'EMITIDA',      // si existe
  ]);

  // INSERT items + stock + movimientos (todo adaptativo)
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

    // bajar stock (si tu sistema permite stock negativo, ajustalo)
    $st = $pdo->prepare("UPDATE productos SET stock = stock - :c WHERE id = :id");
    $st->execute([':c' => $cant, ':id' => $pid]);

    // movimiento stock
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
// -----------------------------------------
// Guardar promos aplicadas (auditoría/ticket pro)
// -----------------------------------------
$promosAplicadas = $calc['promos_aplicadas'] ?? [];
if (is_array($promosAplicadas) && count($promosAplicadas) > 0) {

  $sumVP = 0.0;

  foreach ($promosAplicadas as $p) {
    if (!is_array($p)) continue;

    $promoId  = isset($p['promo_id']) ? (int)$p['promo_id'] : null;
    $tipo     = trim((string)($p['promo_tipo'] ?? ''));
    $nombre   = trim((string)($p['promo_nombre'] ?? ''));
    $desc     = trim((string)($p['descripcion'] ?? ''));
    $monto    = (float)($p['descuento_monto'] ?? 0);
    $meta     = $p['meta'] ?? null;

    if ($tipo === '' || $nombre === '' || $monto <= 0) continue;

    $monto = round($monto, 2);
    $sumVP += $monto;

    insert_dynamic($pdo, 'venta_promos', [
      'venta_id'        => $ventaId,
      'promo_id'        => ($promoId && $promoId > 0) ? $promoId : null,
      'promo_tipo'      => mb_substr($tipo, 0, 20),
      'promo_nombre'    => mb_substr($nombre, 0, 120),
      'descripcion'     => ($desc !== '') ? mb_substr($desc, 0, 255) : null,
      'descuento_monto' => $monto,
      'meta'            => ($meta === null) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
    ]);
  }

  // (Opcional) sanity check: no rompe la venta, solo deja listo si querés auditar
  // if (abs(round($descTotal,2) - round($sumVP,2)) > 0.01) { ... log audit ... }
}

  // actualizar caja_sesiones (contador + importes)
  update_caja_sum($pdo, $cajaId, $medio, $totalNeto, $totalProductos);

  $pdo->commit();

  json_ok([
    'venta_id'        => $ventaId,
    'total'           => $totalNeto,
    'total_bruto'     => $totalBruto,
    'descuento_total' => $descTotal,
    'medio_pago'      => $medio,
    'monto_pagado'    => $montoPagado,
    'vuelto'          => $vuelto,
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_fail($e->getMessage(), 500);
}
