<?php
// public/promos_logic.php v2.0
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* ==========================================================
   CONSTANTS
========================================================== */
const PROMO_EPS = 0.00001;
const PROMO_CACHE_TTL = 300; // 5 minutos

/* ==========================================================
   CLASE PRINCIPAL: PromoEngine
========================================================== */
class PromoEngine {
    private PDO $pdo;
    private ?array $promosCache = null;
    private ?int $cacheTTL = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Obtiene promos activas con caché (mejora performance)
     */
    public function obtenerPromosActivas(bool $forceRefresh = false): array {
        if (!$forceRefresh && $this->promosCache !== null) {
            return $this->promosCache;
        }

        $hoy = date('Y-m-d');

        // PROMOS SIMPLES (optimizado con un solo JOIN)
        $sqlSimples = "
            SELECT 
                p.id AS promo_id,
                p.nombre,
                p.tipo,
                pp.producto_id,
                pp.n,
                pp.m,
                pp.porcentaje,
                pr.es_pesable
            FROM promos p
            JOIN promo_productos pp ON pp.promo_id = p.id
            JOIN productos pr ON pr.id = pp.producto_id
            WHERE p.activo = 1
              AND pr.activo = 1  -- ✅ No aplicar promos a productos inactivos
              AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= :hoy1)
              AND (p.fecha_fin     IS NULL OR p.fecha_fin     >= :hoy2)
        ";
        
        $st1 = $this->pdo->prepare($sqlSimples);
        $st1->execute([':hoy1' => $hoy, ':hoy2' => $hoy]);
        $simples = $st1->fetchAll(PDO::FETCH_ASSOC);

        // COMBOS (optimizado)
        $sqlCombos = "
            SELECT 
                p.id AS promo_id,
                p.nombre,
                p.tipo,
                p.precio_combo,
                pci.producto_id,
                pci.cantidad AS cantidad_requerida,  -- ✅ Usar la columna correcta
                pr.es_pesable,
                pr.activo AS producto_activo
            FROM promos p
            JOIN promo_combo_items pci ON pci.promo_id = p.id
            JOIN productos pr ON pr.id = pci.producto_id
            WHERE p.activo = 1
              AND p.tipo = 'COMBO_FIJO'
              AND pr.activo = 1  -- ✅ Filtrar productos inactivos
              AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= :hoy1)
              AND (p.fecha_fin     IS NULL OR p.fecha_fin     >= :hoy2)
        ";
        
        $st2 = $this->pdo->prepare($sqlCombos);
        $st2->execute([':hoy1' => $hoy, ':hoy2' => $hoy]);
        $combosRaw = $st2->fetchAll(PDO::FETCH_ASSOC);

        // AGRUPAR COMBOS
        $combos = [];
        foreach ($combosRaw as $c) {
            $id = (int)$c['promo_id'];

            if (!isset($combos[$id])) {
                $combos[$id] = [
                    'promo_id'     => $id,
                    'id'           => $id,
                    'nombre'       => $c['nombre'],
                    'tipo'         => 'COMBO_FIJO',
                    'precio_combo' => (float)$c['precio_combo'],
                    'items'        => []
                ];
            }

            $combos[$id]['items'][] = [
                'producto_id' => (int)$c['producto_id'],
                'cantidad'    => (float)$c['cantidad_requerida'],
                'es_pesable'  => (bool)$c['es_pesable'],
            ];
        }

        $result = [
            'simples' => $simples,
            'combos'  => array_values($combos),
        ];

        // Cache por 5 minutos
        $this->promosCache = $result;
        $this->cacheTTL = time() + PROMO_CACHE_TTL;

        return $result;
    }

    /**
     * Aplica promos al carrito completo
     */
    public function aplicarPromosACarrito(array $items, ?array $promos = null): array {
        if ($promos === null) {
            $promos = $this->obtenerPromosActivas();
        }

        $simples = $promos['simples'] ?? [];
        $combos  = $promos['combos']  ?? [];

        // Indexar promos simples por producto_id
        $promosPorProducto = [];
        foreach ($simples as $p) {
            $pid = (int)($p['producto_id'] ?? 0);
            if ($pid > 0) {
                $promosPorProducto[$pid][] = $p;
            }
        }

        // 1) Init + aplicar mejor promo simple por item
        foreach ($items as &$item) {
            $this->initItem($item);

            $pid = $this->getItemProductId($item);
            if ($pid > 0 && isset($promosPorProducto[$pid])) {
                $this->aplicarMejorPromoSimple($item, $promosPorProducto[$pid]);
            }

            $this->finalizeItem($item);
        }
        unset($item);

        // 2) Combos fijos (se apilan)
        if (!empty($combos)) {
            $items = $this->aplicarCombosFijos($items, $combos);

            // Re-finalizar
            foreach ($items as &$it) {
                $this->finalizeItem($it);
            }
            unset($it);
        }

        return $items;
    }

    /* ==========================================================
       HELPERS PRIVADOS
    ========================================================== */

    private function initItem(array &$item): void {
        $cant = (float)($item['cantidad'] ?? 0);
        $pu   = (float)($item['precio_unitario'] ?? 0);
        $base = $pu * $cant;

        $item['base_subtotal']        = $base;
        $item['descuento']            = 0.0;
        $item['subtotal']             = $base;
        $item['promo']                = null;
        $item['promos_aplicadas']     = [];
        $item['precio_unit_original'] = $pu;
        $item['precio_unit_final']    = $pu;
        $item['descuento_monto']      = 0.0;
    }

    private function finalizeItem(array &$item): void {
        $base = (float)($item['base_subtotal'] ?? 0);
        $desc = (float)($item['descuento'] ?? 0);
        $cant = (float)($item['cantidad'] ?? 0);

        $desc = $this->round2($this->clamp0($desc));
        $sub  = $this->round2($this->clamp0($base - $desc));

        $item['descuento']       = $desc;
        $item['subtotal']        = $sub;
        $item['descuento_monto'] = $desc;

        $puFinal = ($cant > PROMO_EPS) ? ($sub / $cant) : 0.0;
        $item['precio_unit_final'] = $this->round2($this->clamp0($puFinal));
    }

    private function getItemProductId(array $item): int {
        return (int)($item['id'] ?? $item['producto_id'] ?? 0);
    }

    private function round2(float $n): float {
        return round($n, 2);
    }

    private function clamp0(float $n): float {
        return ($n < 0) ? 0.0 : $n;
    }

    private function isIntLike(float $n): bool {
        return abs($n - floor($n)) < PROMO_EPS;
    }

    /**
     * Aplica la MEJOR promo simple (puede ser NxM o NTH_PCT)
     */
    private function aplicarMejorPromoSimple(array &$item, array $promosDeProducto): void {
        $cant = (float)($item['cantidad'] ?? 0);
        $pu   = (float)($item['precio_unitario'] ?? 0);
        $esPesable = (bool)($item['es_pesable'] ?? false);

        $best = [
            'desc'  => 0.0,
            'tipo'  => '',
            'promo' => null,
            'extra' => [],
        ];

        foreach ($promosDeProducto as $p) {
            $tipo = $p['tipo'] ?? '';
            $promoId = (int)($p['promo_id'] ?? 0);
            $nombre = $p['nombre'] ?? '';

            if ($tipo === 'N_PAGA_M') {
                $n = (int)($p['n'] ?? 0);
                $m = (int)($p['m'] ?? 0);

                // ✅ Si es pesable, no aplica NxM (solo unidades enteras)
                if ($esPesable) continue;

                [, $desc] = $this->calcNxM($cant, $pu, $n, $m);

                if ($desc > $best['desc'] + PROMO_EPS) {
                    $best = [
                        'desc'  => $desc,
                        'tipo'  => 'N_PAGA_M',
                        'promo' => $p,
                        'extra' => [
                            'n'        => $n,
                            'm'        => $m,
                            'nombre'   => $nombre,
                            'promo_id' => $promoId
                        ],
                    ];
                }
            }

            if ($tipo === 'NTH_PCT') {
                $n = (int)($p['n'] ?? 0);
                $pct = (float)($p['porcentaje'] ?? 0);

                // ✅ NTH_PCT puede aplicar a pesables (ej: cada 3kg, 20% desc)
                [, $desc] = $this->calcNthPct($cant, $pu, $n, $pct, $esPesable);

                if ($desc > $best['desc'] + PROMO_EPS) {
                    $best = [
                        'desc'  => $desc,
                        'tipo'  => 'NTH_PCT',
                        'promo' => $p,
                        'extra' => [
                            'n'          => $n,
                            'porcentaje' => $pct,
                            'nombre'     => $nombre,
                            'promo_id'   => $promoId
                        ],
                    ];
                }
            }
        }

        if ($best['desc'] <= PROMO_EPS) return;

        $item['descuento'] += $best['desc'];

        $label = ($best['tipo'] === 'N_PAGA_M') ? 'NxM' : '% N°';
        
        $item['promo'] = ($item['promo'] ?? '') !== '' 
            ? $item['promo'] . ' | ' . $label
            : $label;

        $item['promos_aplicadas'][] = array_merge(
            ['label' => $label, 'tipo' => $best['tipo'], 'descuento' => $best['desc']],
            $best['extra']
        );
    }

    /**
     * NxM: Llevás N, pagás M (solo unidades enteras)
     */
    private function calcNxM(float $cantidad, float $precioUnit, int $n, int $m): array {
        if ($cantidad < $n || $n <= 0 || $m <= 0 || $m >= $n) {
            return [0.0, 0.0];
        }

        if (!$this->isIntLike($cantidad)) {
            return [0.0, 0.0];
        }

        $cant = (int)round($cantidad);
        $packs = intdiv($cant, $n);
        $resto = $cant % $n;
        $unidadesPagas = ($packs * $m) + $resto;

        $normal = $cant * $precioUnit;
        $promo  = $unidadesPagas * $precioUnit;
        $desc   = $this->clamp0($normal - $promo);

        return [$promo, $desc];
    }

    /**
     * NTH_PCT: X% de descuento en la N° unidad/kg
     */
    private function calcNthPct(float $cantidad, float $precioUnit, int $n, float $pct, bool $esPesable): array {
        if ($cantidad < $n || $n <= 0 || $pct <= 0 || $pct > 100) {
            return [0.0, 0.0];
        }

        if ($esPesable) {
            // Para pesables: cada N kg, descuento de pct%
            $descUnidades = floor($cantidad / $n);
        } else {
            // Para unidades: debe ser cantidad entera
            if (!$this->isIntLike($cantidad)) {
                return [0.0, 0.0];
            }
            $cant = (int)round($cantidad);
            $descUnidades = intdiv($cant, $n);
        }

        $desc = $descUnidades * ($precioUnit * ($pct / 100.0));
        $desc = $this->clamp0($desc);

        $normal = $cantidad * $precioUnit;
        $promo  = $this->clamp0($normal - $desc);

        return [$promo, $desc];
    }

    /**
     * Aplica combos fijos (puede aplicarse múltiples veces)
     */
    private function aplicarCombosFijos(array $items, array $combos): array {
        if (empty($combos) || empty($items)) return $items;

        // Index por producto + resto disponible
        $indexPorProducto = [];
        foreach ($items as $idx => $it) {
            $pid = $this->getItemProductId($it);
            if ($pid <= 0) continue;

            $indexPorProducto[$pid] = $idx;
            $items[$idx]['_resto_combo'] = (float)($it['cantidad'] ?? 0);

            if (!isset($items[$idx]['promos_aplicadas'])) {
                $items[$idx]['promos_aplicadas'] = [];
            }
            if (!isset($items[$idx]['promo'])) {
                $items[$idx]['promo'] = null;
            }
            if (!isset($items[$idx]['descuento'])) {
                $items[$idx]['descuento'] = 0.0;
            }
        }

        foreach ($combos as $combo) {
            if (($combo['tipo'] ?? '') !== 'COMBO_FIJO') continue;

            $comboItems  = $combo['items'] ?? [];
            $precioCombo = (float)($combo['precio_combo'] ?? 0);

            if (empty($comboItems) || $precioCombo <= 0) continue;

            $comboId     = (int)($combo['promo_id'] ?? $combo['id'] ?? 0);
            $comboNombre = $combo['nombre'] ?? 'Combo';

            // Verificar si hay productos pesables en el combo
            $tienePesables = false;
            foreach ($comboItems as $ci) {
                if (!empty($ci['es_pesable'])) {
                    $tienePesables = true;
                    break;
                }
            }

            // ✅ Si hay pesables, solo permitir 1 combo (no múltiples)
            $maxCombos = $tienePesables ? 1 : PHP_INT_MAX;

            // Calcular cuántos combos se pueden armar
            foreach ($comboItems as $ci) {
                $pidReq  = (int)($ci['producto_id'] ?? 0);
                $cantReq = (float)($ci['cantidad'] ?? 0);

                if ($pidReq <= 0 || $cantReq <= 0) {
                    $maxCombos = 0;
                    break;
                }

                if (!isset($indexPorProducto[$pidReq])) {
                    $maxCombos = 0;
                    break;
                }

                $idxCarrito = $indexPorProducto[$pidReq];
                $cantDisp   = (float)($items[$idxCarrito]['_resto_combo'] ?? 0);

                $posibles = (int)floor($cantDisp / $cantReq);
                $maxCombos = min($maxCombos, $posibles);
            }

            if ($maxCombos <= 0) continue;

            // Aplicar combos
            for ($k = 0; $k < $maxCombos; $k++) {
                // Calcular precio normal de 1 combo
                $precioNormal = 0.0;
                foreach ($comboItems as $ci) {
                    $pidReq  = (int)$ci['producto_id'];
                    $cantReq = (float)$ci['cantidad'];
                    $idxCart = $indexPorProducto[$pidReq];
                    $precioU = (float)$items[$idxCart]['precio_unitario'];

                    $precioNormal += $precioU * $cantReq;
                }

                if ($precioNormal <= 0 || $precioCombo >= $precioNormal) break;

                $descuentoCombo = $precioNormal - $precioCombo;

                // Repartir descuento proporcionalmente
                foreach ($comboItems as $ci) {
                    $pidReq  = (int)$ci['producto_id'];
                    $cantReq = (float)$ci['cantidad'];
                    $idxCart = $indexPorProducto[$pidReq];
                    $precioU = (float)$items[$idxCart]['precio_unitario'];

                    $parteNormal = $precioU * $cantReq;
                    $prop = ($precioNormal > 0) ? ($parteNormal / $precioNormal) : 0.0;
                    $descItem = $this->clamp0($descuentoCombo * $prop);

                    $items[$idxCart]['descuento'] += $descItem;

                    $label = "Combo: {$comboNombre}";
                    $prevPromo = $items[$idxCart]['promo'] ?? '';
                    $items[$idxCart]['promo'] = ($prevPromo !== '') 
                        ? ($prevPromo . ' | ' . $label)
                        : $label;

                    $items[$idxCart]['promos_aplicadas'][] = [
                        'label'     => $label,
                        'tipo'      => 'COMBO_FIJO',
                        'promo_id'  => $comboId,
                        'nombre'    => $comboNombre,
                        'descuento' => $descItem,
                    ];

                    // Consumir cantidad
                    $items[$idxCart]['_resto_combo'] -= $cantReq;
                }
            }
        }

        // Limpiar interno
        foreach ($items as &$it) {
            unset($it['_resto_combo']);
        }
        unset($it);

        return $items;
    }
}

/* ==========================================================
   FUNCIÓN LEGACY (para compatibilidad con código existente)
========================================================== */
function obtenerPromosActivas(PDO $pdo): array {
    static $engine = null;
    if ($engine === null) {
        $engine = new PromoEngine($pdo);
    }
    return $engine->obtenerPromosActivas();
}

function aplicarPromosACarrito(array $items, array $promos): array {
    global $pdo;
    static $engine = null;
    if ($engine === null) {
        $engine = new PromoEngine($pdo);
    }
    return $engine->aplicarPromosACarrito($items, $promos);
}