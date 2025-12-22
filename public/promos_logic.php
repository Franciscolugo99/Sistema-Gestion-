<?php
// public/promos_logic.php
declare(strict_types=1);
require_once __DIR__ . '/lib/helpers.php';

/* ==========================================================
   CONFIG / CONSTANTES
========================================================== */
const PROMO_EPS = 0.00001;

/* ==========================================================
   OBTENER PROMOS ACTIVAS
========================================================== */
function obtenerPromosActivas(PDO $pdo): array {
  $hoy = date('Y-m-d');

  // PROMOS SIMPLES
  $sqlSimples = "
    SELECT 
      p.id AS promo_id,
      p.nombre,
      p.tipo,
      pp.producto_id,
      pp.n,
      pp.m,
      pp.porcentaje
    FROM promos p
    JOIN promo_productos pp ON pp.promo_id = p.id
    WHERE p.activo = 1
      AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= :hoy1)
      AND (p.fecha_fin     IS NULL OR p.fecha_fin     >= :hoy2)

  ";
  $st1 = $pdo->prepare($sqlSimples);
  $st1->execute([':hoy1' => $hoy, ':hoy2' => $hoy]);
  $simples = $st1->fetchAll(PDO::FETCH_ASSOC);

  // COMBO FIJO
  $sqlCombos = "
    SELECT 
      p.id AS promo_id,
      p.nombre,
      p.tipo,
      p.precio_combo,
      pci.producto_id,
      pci.cantidad_requerida
    FROM promos p
    JOIN promo_combo_items pci ON pci.promo_id = p.id
    WHERE p.activo = 1
      AND p.tipo = 'COMBO_FIJO'
      AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= :hoy1)
      AND (p.fecha_fin     IS NULL OR p.fecha_fin     >= :hoy2)

  ";
  $st2 = $pdo->prepare($sqlCombos);
  $st2->execute([':hoy1' => $hoy, ':hoy2' => $hoy]);
  $combosRaw = $st2->fetchAll(PDO::FETCH_ASSOC);

  // AGRUPAR COMBOS
  $combos = [];
  foreach ($combosRaw as $c) {
    $id = (int)$c['promo_id'];

    if (!isset($combos[$id])) {
      $combos[$id] = [
        'promo_id'     => $id,
        'id'           => $id, // alias compat
        'nombre'       => (string)$c['nombre'],
        'tipo'         => 'COMBO_FIJO',
        'precio_combo' => (float)$c['precio_combo'],
        'items'        => []
      ];
    }

    $combos[$id]['items'][] = [
      'producto_id' => (int)$c['producto_id'],
      'cantidad'    => (float)$c['cantidad_requerida'],
    ];
  }

  return [
    'simples' => $simples,
    'combos'  => array_values($combos),
  ];
}

/* ==========================================================
   HELPERS NUM
========================================================== */
function _round2(float $n): float { return round($n, 2); }
function _clamp0(float $n): float { return ($n < 0) ? 0.0 : $n; }
function _is_int_like(float $n): bool { return abs($n - floor($n)) < PROMO_EPS; }

/**
 * Normaliza id de producto del item (te soporta id o producto_id)
 */
function _item_pid(array $item): int {
  if (isset($item['id'])) return (int)$item['id'];
  if (isset($item['producto_id'])) return (int)$item['producto_id'];
  return 0;
}

/**
 * Inicializa campos del item para promo.
 * Deja listo para grabar en DB después:
 * - precio_unit_original, precio_unit_final, descuento_monto, subtotal
 */
function _promo_init_item(array &$item): void {
  $cant = (float)($item['cantidad'] ?? 0);
  $pu   = (float)($item['precio_unitario'] ?? 0);

  $base = $pu * $cant;

  $item['base_subtotal'] = $base;     // base sin promos
  $item['descuento']     = 0.0;       // acumulado
  $item['subtotal']      = $base;     // se recalcula al final

  if (!array_key_exists('promo', $item)) {
    $item['promo'] = null;            // string para UI
  }

  if (!isset($item['promos_aplicadas']) || !is_array($item['promos_aplicadas'])) {
    $item['promos_aplicadas'] = [];
  }

  // para DB / ticket
  $item['precio_unit_original'] = $pu;
  $item['precio_unit_final']    = $pu;
  $item['descuento_monto']      = 0.0;
}

/**
 * Agrega etiqueta al string promo + detalle estructurado
 */
function _promo_add_label(array &$item, string $label, array $detalle = []): void {
  $prev = trim((string)($item['promo'] ?? ''));
  $item['promo'] = ($prev === '') ? $label : ($prev . ' | ' . $label);

  $item['promos_aplicadas'][] = array_merge(['label' => $label], $detalle);
}

/**
 * Finaliza el item: redondeos + campos para DB
 */
function _promo_finalize_item(array &$item): void {
  $base = (float)($item['base_subtotal'] ?? 0);
  $desc = (float)($item['descuento'] ?? 0);
  $cant = (float)($item['cantidad'] ?? 0);

  $desc = _round2(_clamp0($desc));
  $sub  = _round2(_clamp0($base - $desc));

  $item['descuento'] = $desc;
  $item['subtotal']  = $sub;

  $item['descuento_monto'] = $desc;

  $puFinal = 0.0;
  if ($cant > PROMO_EPS) {
    $puFinal = $sub / $cant;
  }
  $item['precio_unit_final'] = _round2(_clamp0($puFinal));
}

/* ==========================================================
   PROMO NxM (N paga M)
========================================================== */
function _calc_nxm(float $cantidad, float $precioUnit, int $n, int $m): array {
  if ($cantidad < $n || $n <= 0 || $m <= 0) return [0.0, 0.0];

  // solo unidades enteras
  if (!_is_int_like($cantidad)) return [0.0, 0.0];

  $cant = (int)round($cantidad);
  $packs = intdiv($cant, $n);
  $unidadesPagas = ($packs * $m) + ($cant % $n);

  $normal = $cant * $precioUnit;
  $promo  = $unidadesPagas * $precioUnit;

  $desc = _clamp0($normal - $promo);
  return [$promo, $desc];
}

/* ==========================================================
   PROMO NTH %  (ej: 20% en la 3ra)
========================================================== */
function _calc_nth_pct(float $cantidad, float $precioUnit, int $n, float $pct): array {
  if ($cantidad < $n || $n <= 0 || $pct <= 0) return [0.0, 0.0];

  // solo unidades enteras
  if (!_is_int_like($cantidad)) return [0.0, 0.0];

  $cant = (int)round($cantidad);
  $descUnidades = intdiv($cant, $n);

  $normal = $cant * $precioUnit;
  $desc   = $descUnidades * ($precioUnit * ($pct / 100.0));
  $desc   = _clamp0($desc);

  $promo = _clamp0($normal - $desc);
  return [$promo, $desc];
}

/**
 * Aplica la MEJOR promo simple para el producto (si hubiera más de una)
 */
function aplicarMejorPromoSimple(array &$item, array $promosDeProducto): void {
  $cant = (float)($item['cantidad'] ?? 0);
  $pu   = (float)($item['precio_unitario'] ?? 0);

  $best = [
    'desc' => 0.0,
    'tipo' => '',
    'promo'=> null,
    'extra'=> [],
  ];

  foreach ($promosDeProducto as $p) {
    $tipo = (string)($p['tipo'] ?? '');
    $nombre = (string)($p['nombre'] ?? '');
    $promoId = (int)($p['promo_id'] ?? 0);

    if ($tipo === 'N_PAGA_M') {
      $n = (int)($p['n'] ?? 0);
      $m = (int)($p['m'] ?? 0);
      [, $desc] = _calc_nxm($cant, $pu, $n, $m);

      if ($desc > $best['desc'] + PROMO_EPS) {
        $best = [
          'desc' => $desc,
          'tipo' => 'N_PAGA_M',
          'promo'=> $p,
          'extra'=> ['n'=>$n,'m'=>$m,'nombre'=>$nombre,'promo_id'=>$promoId],
        ];
      }
    }

    if ($tipo === 'NTH_PCT') {
      $n = (int)($p['n'] ?? 0);
      $pct = (float)($p['porcentaje'] ?? 0);
      [, $desc] = _calc_nth_pct($cant, $pu, $n, $pct);

      if ($desc > $best['desc'] + PROMO_EPS) {
        $best = [
          'desc' => $desc,
          'tipo' => 'NTH_PCT',
          'promo'=> $p,
          'extra'=> ['n'=>$n,'porcentaje'=>$pct,'nombre'=>$nombre,'promo_id'=>$promoId],
        ];
      }
    }
  }

  if ($best['desc'] <= PROMO_EPS) return;

  $item['descuento'] += $best['desc'];

  if ($best['tipo'] === 'N_PAGA_M') {
    _promo_add_label($item, 'NxM', [
      'tipo'      => 'N_PAGA_M',
      'promo_id'  => $best['extra']['promo_id'] ?? null,
      'nombre'    => $best['extra']['nombre'] ?? '',
      'n'         => $best['extra']['n'] ?? null,
      'm'         => $best['extra']['m'] ?? null,
      'descuento' => $best['desc'],
    ]);
  } else {
    _promo_add_label($item, '% N°', [
      'tipo'       => 'NTH_PCT',
      'promo_id'   => $best['extra']['promo_id'] ?? null,
      'nombre'     => $best['extra']['nombre'] ?? '',
      'n'          => $best['extra']['n'] ?? null,
      'porcentaje' => $best['extra']['porcentaje'] ?? null,
      'descuento'  => $best['desc'],
    ]);
  }
}

/* ==========================================================
   CÁLCULO PRINCIPAL
========================================================== */
function aplicarPromosACarrito(array $items, array $promos): array {
  $simples = $promos['simples'] ?? [];
  $combos  = $promos['combos']  ?? [];

  // indexar promos simples por producto_id (pueden ser varias)
  $promosPorProducto = [];
  foreach ($simples as $p) {
    if (!isset($p['producto_id'])) continue;
    $pid = (int)$p['producto_id'];
    if ($pid <= 0) continue;
    $promosPorProducto[$pid][] = $p;
  }

  // 1) init + aplicar mejor promo simple por item
  foreach ($items as &$item) {
    _promo_init_item($item);

    $pid = _item_pid($item);
    if ($pid > 0 && isset($promosPorProducto[$pid])) {
      aplicarMejorPromoSimple($item, $promosPorProducto[$pid]);
    }

    _promo_finalize_item($item);
  }
  unset($item);

  // 2) combos fijos (se apilan como descuento adicional)
  if (!empty($combos)) {
    $items = aplicarCombosFijosACarrito($items, $combos);

    // volver a finalizar (porque combos suman descuento)
    foreach ($items as &$it) {
      _promo_finalize_item($it);
    }
    unset($it);
  }

  return $items;
}

/* ==========================================================
   COMBOS FIJOS (COMBO_FIJO)
========================================================== */
function aplicarCombosFijosACarrito(array $items, array $combos): array {
  if (empty($combos) || empty($items)) return $items;

  // index por producto + resto
  $indexPorProducto = [];
  foreach ($items as $idx => $it) {
    $pid = _item_pid($it);
    if ($pid <= 0) continue;
    $indexPorProducto[$pid] = $idx;
    $items[$idx]['_resto_combo'] = (float)($it['cantidad'] ?? 0);

    if (!isset($items[$idx]['promos_aplicadas']) || !is_array($items[$idx]['promos_aplicadas'])) {
      $items[$idx]['promos_aplicadas'] = [];
    }
    if (!array_key_exists('promo', $items[$idx])) $items[$idx]['promo'] = null;
    if (!isset($items[$idx]['descuento'])) $items[$idx]['descuento'] = 0.0;
    if (!isset($items[$idx]['base_subtotal'])) {
      // por las dudas si entrara “crudo”
      $items[$idx]['base_subtotal'] = (float)($items[$idx]['precio_unitario'] ?? 0) * (float)($items[$idx]['cantidad'] ?? 0);
    }
  }

  foreach ($combos as $combo) {
    if (($combo['tipo'] ?? '') !== 'COMBO_FIJO') continue;

    $comboItems  = $combo['items'] ?? [];
    $precioCombo = (float)($combo['precio_combo'] ?? 0);

    if (empty($comboItems) || $precioCombo <= 0) continue;

    $comboId = (int)($combo['promo_id'] ?? ($combo['id'] ?? 0));
    $comboNombre = (string)($combo['nombre'] ?? 'Combo');

    // max combos armables
    $maxCombos = PHP_INT_MAX;

    foreach ($comboItems as $ci) {
      $pidReq  = (int)($ci['producto_id'] ?? 0);
      $cantReq = (float)($ci['cantidad'] ?? 0);

      if ($pidReq <= 0 || $cantReq <= 0 || !isset($indexPorProducto[$pidReq])) {
        $maxCombos = 0;
        break;
      }

      $idxCarrito = $indexPorProducto[$pidReq];
      $cantDisp   = (float)($items[$idxCarrito]['_resto_combo'] ?? 0);

      $maxCombos = min($maxCombos, (int)floor($cantDisp / $cantReq));
    }

    if ($maxCombos <= 0) continue;

    for ($k = 0; $k < $maxCombos; $k++) {

      // precio normal de 1 combo
      $precioNormal = 0.0;
      foreach ($comboItems as $ci) {
        $pidReq  = (int)$ci['producto_id'];
        $cantReq = (float)$ci['cantidad'];
        $idxCart = $indexPorProducto[$pidReq];
        $precioU = (float)$items[$idxCart]['precio_unitario'];
        $precioNormal += $precioU * $cantReq;
      }

      if ($precioNormal <= 0) break;
      if ($precioCombo >= $precioNormal) break;

      $descuentoCombo = $precioNormal - $precioCombo;

      // repartir proporcionalmente
      foreach ($comboItems as $ci) {
        $pidReq  = (int)$ci['producto_id'];
        $cantReq = (float)$ci['cantidad'];
        $idxCart = $indexPorProducto[$pidReq];
        $precioU = (float)$items[$idxCart]['precio_unitario'];

        $parteNormal = $precioU * $cantReq;
        $prop = ($precioNormal > 0) ? ($parteNormal / $precioNormal) : 0.0;

        $descItem = $descuentoCombo * $prop;
        $descItem = _clamp0($descItem);

        $items[$idxCart]['descuento'] += $descItem;

        _promo_add_label($items[$idxCart], "Combo fijo: {$comboNombre}", [
          'tipo'      => 'COMBO_FIJO',
          'promo_id'  => $comboId,
          'nombre'    => $comboNombre,
          'descuento' => $descItem,
        ]);

        // consumir
        $items[$idxCart]['_resto_combo'] -= $cantReq;
      }
    }
  }

  // limpiar interno
  foreach ($items as &$it) {
    unset($it['_resto_combo']);
  }
  unset($it);

  return $items;
}
