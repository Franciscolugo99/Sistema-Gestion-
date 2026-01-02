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

function update_caja_sum(PDO $pdo, int $cajaId, string $medio, float $importe, float $productos): void {
  if ($cajaId <= 0) return;
  if (!has_col($pdo, 'caja_sesiones', 'id')) return;

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

  $campoMP = 'total_efectivo';
  $m = strtoupper($medio);
  if ($m === 'MP') $campoMP = 'total_mp';
  elseif ($m === 'DEBITO') $campoMP = 'total_debito';
  elseif ($m === 'CREDITO') $campoMP = 'total_credito';

  if ($campoMP && has_col($pdo, 'caja_sesiones', $campoMP)) {
    $sets[] = "{$campoMP} = COALESCE({$campoMP},0) + :imp_mp";
    $params[':imp_mp'] = $importe;
  }

  if (!$sets) return;

  $sql = "UPDATE caja_sesiones SET " . implode(", ", $sets) . " WHERE id = :id";
  $st = $pdo->prepare($sql);
  $st->execute($params);
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

  // default a monto
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

  // monto
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

    // ✅ AHORA SÍ aplica promos a pesables (eliminamos la restricción)
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
          // ✅ FUNCIONA CON PESABLES: 25% a la 3° unidad
          // Ejemplo: 3 KG con N=3, pct=25 → descuento del 25% sobre 1 KG
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
      
      // ✅ Tolerancia de 0.01 para pesables (evita errores con 0.999 kg)
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
// ✅ Evitar netos negativos por distribución de combos (casos extremos)
foreach ($items as &$itFix) {
  if ((float)($itFix['neto'] ?? 0) < 0) $itFix['neto'] = 0.0;

  $itFix['neto'] = round((float)($itFix['neto'] ?? 0), 2);
  $itFix['bruto'] = round((float)($itFix['bruto'] ?? 0), 2);

  // Recalcular descuento consistente
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
    'total_neto'  => $totalNeto,              // neto después promos+combos (sin desc_global)
    'descuento_total' => $descTotal,          // descuento promos+combos (sin desc_global)
    'promos_aplicadas' => array_values($promosAplicadas),
  ];
}

// ------------------ ROUTER ------------------
$action = (string)($_GET['action'] ?? '');
if ($action !== 'registrar_venta') json_fail('Acción inválida', 404);

require_login_json();
require_terminal_lock_json();
if (function_exists('user_has_permission') && !user_has_permission('realizar_ventas')) {
  json_fail('No autorizado', 403);
}

$body = read_json_body();

// CSRF: header o body
$csrf = (string)($body['csrf'] ?? ($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));

if (!csrf_check($csrf)) json_fail('CSRF inválido o ausente', 403);

$pdo = getPDO();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

$itemsIn = $body['items'] ?? null;
if (!is_array($itemsIn) || !$itemsIn) json_fail('Ticket vacío', 422);

$medio = norm_medio_pago((string)($body['medio_pago'] ?? 'EFECTIVO'));
$montoPagado = parse_num($body['monto_pagado'] ?? 0);

$descGlobalReq = parse_desc_global($body['desc_global'] ?? null);

$puedeCambiarPrecio = function_exists('user_has_permission') && user_has_permission('caja_modificar_precio');

// ✅ si no tiene permiso, no se permite desc_global aunque lo manden
if (!$puedeCambiarPrecio) {
  $descGlobalReq = null;
}


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
$terminalId = (int)($_SESSION['terminal_id'] ?? current_terminal_id());
$caja = caja_get_abierta($pdo, $terminalId);
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

$stock = (float)($p['stock'] ?? 0);
$eps = $esPesable ? 0.0005 : 0.0; // ~ 3 decimales
if ($cant > $stock + $eps) {
  throw new RuntimeException("Stock insuficiente para {$p['nombre']} (disponible: {$stock}, solicitado: {$cant})");
}



    $precioLista = (float)$p['precio'];
    $precioActual = $precioLista;

    // permitir precio manual si tiene permiso
  if ($puedeCambiarPrecio) {
  $pr = (float)$it['precio_req'];
  if ($pr > 0) $precioActual = $pr;

  if ($precioActual <= 0) {
    throw new RuntimeException("Precio inválido para {$p['nombre']}");
  }
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

  // calcular total con promos/combos (NETO sin desc_global)
  $calc = calcular_totales_con_promos($srvItems, $promos);
  $srvItems = $calc['items'];

  $totalBruto = (float)$calc['total_bruto'];
  $totalNetoSinGlobal  = (float)$calc['total_neto'];
  $descTotalSinGlobal  = (float)$calc['descuento_total'];

  // ✅ aplicar descuento global al final
  $descGlobalMonto = calc_desc_global($totalNetoSinGlobal, $descGlobalReq);
  $totalNetoFinal  = round(max(0.0, $totalNetoSinGlobal - $descGlobalMonto), 2);
  $descTotalFinal  = round($descTotalSinGlobal + $descGlobalMonto, 2);

  // meter DESC_GLOBAL en venta_promos para ticket/auditoría
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

  // pago: si no es efectivo, forzar pagado = total FINAL
  if ($medio !== 'EFECTIVO') {
    $montoPagado = $totalNetoFinal;
  } else {
    if ($montoPagado + 1e-6 < $totalNetoFinal) {
      throw new RuntimeException('Pago insuficiente');
    }
  }
  $vuelto = ($medio === 'EFECTIVO') ? round(max(0.0, $montoPagado - $totalNetoFinal), 2) : 0.0;

  // INSERT ventas
  $ventaId = insert_dynamic($pdo, 'ventas', [
    'user_id'         => ($userId > 0 ? $userId : null),
    'caja_id'         => $cajaId,
    'total'           => $totalNetoFinal,      // ✅ total final (con desc_global)
    'total_bruto'     => $totalBruto,
    'descuento_total' => $descTotalFinal,      // ✅ promos/combos + desc_global
    'medio_pago'      => $medio,
    'monto_pagado'    => $montoPagado,
    'vuelto'          => $vuelto,
    'estado'          => 'EMITIDA',
  ]);

  // INSERT items + stock + movimientos
  foreach ($srvItems as $it) {
    $pid  = (int)$it['producto_id'];
    $cant = (float)$it['cantidad'];
    $neto = (float)$it['neto'];          // neto item con promos/combos (sin desc_global)
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

  // Guardar promos aplicadas (incluye DESC_GLOBAL)
  $promosAplicadas = $calc['promos_aplicadas'] ?? [];
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
        'promo_tipo'      => mb_substr($tipo, 0, 20),
        'promo_nombre'    => mb_substr($nombre, 0, 120),
        'descripcion'     => ($descTxt !== '') ? mb_substr($descTxt, 0, 255) : null,
        'descuento_monto' => $monto,
        'meta'            => ($meta === null) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
      ]);
    }
  }

  // actualizar caja_sesiones (con total FINAL)
  update_caja_sum($pdo, $cajaId, $medio, $totalNetoFinal, $totalProductos);

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
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  
  // ✅ Log mejorado para debugging
  error_log("Error en registrar_venta: " . $e->getMessage() . " | User: {$userId} | Caja: {$cajaId}");
  
  json_fail($e->getMessage(), 500);
}