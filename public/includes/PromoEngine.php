<?php
declare(strict_types=1);

/**
 * PromoEngine - Motor único de promociones (FLUS)
 * Soporta:
 *  - N_PAGA_M (NxM) -> solo unidades enteras (no pesables)
 *  - NTH_PCT (% en la N° / cada N) -> unidades o pesables
 *  - COMBO_FIJO -> apilable, reparte descuento proporcional, soporta productos repetidos en carrito
 *
 * Recomendación: este archivo debe ser el ÚNICO lugar donde exista la clase PromoEngine.
 */
final class PromoEngine
{
    private PDO $pdo;

    private const EPS = 0.00001;
    private const CACHE_KEY = 'flus_promos_activas_v3';
    private const CACHE_TTL = 300; // 5 min

    // Cache en memoria (por request)
    private ?array $memCache = null;
    private int $memCacheExp = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Obtiene promos activas (APCu si existe, sino cache en memoria por request). */
    public function obtenerPromosActivas(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->cacheGet();
            if (is_array($cached)) {
                return $cached;
            }
        }

        $hoy = date('Y-m-d');

        $simples = $this->fetchPromosSimples($hoy);
        $combos  = $this->fetchCombosFijos($hoy);

        $result = [
            'simples' => $simples,
            'combos'  => $combos,
        ];

        $this->cacheSet($result);
        return $result;
    }

    /** Invalida cache (llamar después de crear/editar/eliminar promos). */
    public function invalidarCache(): void
    {
        $this->memCache = null;
        $this->memCacheExp = 0;

        if (function_exists('apcu_delete')) {
            @apcu_delete(self::CACHE_KEY);
        }
    }

    /**
     * Aplica promos al carrito.
     * @param array $items items del carrito
     * @param array|null $promos promos activas (si null, las busca)
     */
    public function aplicarPromosACarrito(array $items, ?array $promos = null): array
    {
        $promos ??= $this->obtenerPromosActivas(false);

        $simples = $promos['simples'] ?? [];
        $combos  = $promos['combos']  ?? [];

        // Indexar promos simples por producto_id
        $promosPorProducto = [];
        foreach ($simples as $p) {
            $pid = (int)($p['producto_id'] ?? 0);
            if ($pid > 0) $promosPorProducto[$pid][] = $p;
        }

        // 1) Init + mejor promo simple por item
        foreach ($items as &$item) {
            $this->initItem($item);

            $pid = $this->getItemProductId($item);
            if ($pid > 0 && isset($promosPorProducto[$pid])) {
                $this->aplicarMejorPromoSimple($item, $promosPorProducto[$pid]);
            }

            $this->finalizeItem($item);
        }
        unset($item);

        // 2) Combos fijos (apilables)
        if (!empty($combos) && !empty($items)) {
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
       DB FETCH
    ========================================================== */

    private function fetchPromosSimples(string $hoy): array
    {
        $sql = "
            SELECT
                p.id   AS promo_id,
                p.nombre,
                p.tipo,
                pp.producto_id,
                pp.n,
                pp.m,
                pp.porcentaje,
                pr.codigo AS producto_codigo,
                pr.nombre AS producto_nombre,
                pr.precio AS producto_precio,
                pr.stock AS producto_stock,
                pr.unidad_venta,
                pr.es_pesable
            FROM promos p
            INNER JOIN promo_productos pp ON pp.promo_id = p.id
            INNER JOIN productos pr       ON pr.id = pp.producto_id
            WHERE p.activo = 1
              AND pr.activo = 1
              AND p.tipo IN ('N_PAGA_M', 'NTH_PCT')
              AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= :hoy1)
              AND (p.fecha_fin    IS NULL OR p.fecha_fin    >= :hoy2)
            ORDER BY p.id ASC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':hoy1' => $hoy, ':hoy2' => $hoy]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchCombosFijos(string $hoy): array
    {
        // IMPORTANTE: si tu columna no se llama cantidad_requerida, acá lo ajustamos.
        $sql = "
            SELECT
                p.id AS promo_id,
                p.nombre,
                p.tipo,
                p.precio_combo,
                pci.producto_id,
                pci.cantidad_requerida AS cantidad_requerida,
                pr.codigo AS producto_codigo,
                pr.nombre AS producto_nombre,
                pr.precio AS producto_precio,
                pr.stock AS producto_stock,
                pr.unidad_venta,
                pr.es_pesable
            FROM promos p
            INNER JOIN promo_combo_items pci ON pci.promo_id = p.id
            INNER JOIN productos pr ON pr.id = pci.producto_id
            WHERE p.activo = 1
              AND p.tipo = 'COMBO_FIJO'
              AND pr.activo = 1
              AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= :hoy1)
              AND (p.fecha_fin    IS NULL OR p.fecha_fin    >= :hoy2)
            ORDER BY p.id ASC, pci.id ASC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':hoy1' => $hoy, ':hoy2' => $hoy]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $combos = [];
        foreach ($rows as $r) {
            $id = (int)$r['promo_id'];
            if (!isset($combos[$id])) {
                $combos[$id] = [
                    'promo_id'     => $id,
                    'id'           => $id, // alias compat
                    'nombre'       => (string)($r['nombre'] ?? ''),
                    'tipo'         => 'COMBO_FIJO',
                    'precio_combo' => (float)($r['precio_combo'] ?? 0),
                    'items'        => [],
                ];
            }
            $combos[$id]['items'][] = [
                'producto_id'  => (int)($r['producto_id'] ?? 0),
                'codigo'       => (string)($r['producto_codigo'] ?? ''),
                'nombre'       => (string)($r['producto_nombre'] ?? ''),
                'precio'       => (float)($r['producto_precio'] ?? 0),
                'stock'        => (float)($r['producto_stock'] ?? 0),
                'unidad_venta' => (string)($r['unidad_venta'] ?? 'UNIDAD'),
                'cantidad'     => (float)($r['cantidad_requerida'] ?? 0),
                'es_pesable'   => (bool)($r['es_pesable'] ?? false),
            ];
        }

        return array_values($combos);
    }

    /* ==========================================================
       ITEM HELPERS
    ========================================================== */

    private function initItem(array &$item): void
    {
        $cant = (float)($item['cantidad'] ?? 0);
        $pu   = $this->getItemUnitPrice($item);
        $base = $pu * $cant;

        $item['base_subtotal'] = $base;

        // Normalizamos campos esperados
        $item['descuento']            = (float)($item['descuento'] ?? 0.0);
        $item['subtotal']             = (float)($item['subtotal'] ?? $base);
        $item['promo']                = (string)($item['promo'] ?? '');
        $item['promos_aplicadas']     = is_array($item['promos_aplicadas'] ?? null) ? $item['promos_aplicadas'] : [];
        $item['precio_unit_original'] = (float)($item['precio_unit_original'] ?? $pu);
        $item['precio_unit_final']    = (float)($item['precio_unit_final'] ?? $pu);
        $item['descuento_monto']      = (float)($item['descuento_monto'] ?? 0.0);

        // Reseteo “limpio” por cálculo (clave para no arrastrar basura)
        $item['descuento']        = 0.0;
        $item['subtotal']         = $base;
        $item['descuento_monto']  = 0.0;
        $item['precio_unit_final']= $pu;
        $item['promo']            = '';
        $item['promos_aplicadas'] = [];
    }

    private function finalizeItem(array &$item): void
    {
        $base = (float)($item['base_subtotal'] ?? 0);
        $desc = (float)($item['descuento'] ?? 0);
        $cant = (float)($item['cantidad'] ?? 0);

        $desc = $this->round2($this->clamp0($desc));
        $sub  = $this->round2($this->clamp0($base - $desc));

        $item['descuento']       = $desc;
        $item['subtotal']        = $sub;
        $item['descuento_monto'] = $desc;

        $puFinal = ($cant > self::EPS) ? ($sub / $cant) : 0.0;
        $item['precio_unit_final'] = $this->round2($this->clamp0($puFinal));
    }

    private function getItemProductId(array $item): int
    {
        // preferimos producto_id; id queda como fallback por compat
        return (int)($item['producto_id'] ?? $item['id'] ?? 0);
    }

    private function getItemUnitPrice(array $item): float
    {
        return (float)($item['precio_unitario'] ?? $item['precio_unit'] ?? $item['precio'] ?? 0);
    }

    private function isPesableItem(array $item): bool
    {
        return (bool)($item['es_pesable'] ?? $item['esPesable'] ?? false);
    }

    private function round2(float $n): float { return round($n, 2); }
    private function clamp0(float $n): float { return ($n < 0) ? 0.0 : $n; }
    private function isIntLike(float $n): bool { return abs($n - floor($n)) < self::EPS; }

    /* ==========================================================
       PROMOS SIMPLES (mejor descuento)
    ========================================================== */

    private function aplicarMejorPromoSimple(array &$item, array $promosDeProducto): void
    {
        $cant      = (float)($item['cantidad'] ?? 0);
        $pu        = $this->getItemUnitPrice($item);
        $esPesable = $this->isPesableItem($item);

        $bestDesc = 0.0;
        $bestTipo = '';
        $bestExtra = [];

        foreach ($promosDeProducto as $p) {
            $tipo   = (string)($p['tipo'] ?? '');
            $promoId= (int)($p['promo_id'] ?? 0);
            $nombre = (string)($p['nombre'] ?? '');

            if ($tipo === 'N_PAGA_M') {
                $n = (int)($p['n'] ?? 0);
                $m = (int)($p['m'] ?? 0);

                if ($esPesable) continue;

                [, $desc] = $this->calcNxM($cant, $pu, $n, $m);
                if ($desc > $bestDesc + self::EPS) {
                    $bestDesc = $desc;
                    $bestTipo = 'N_PAGA_M';
                    $bestExtra = ['n'=>$n,'m'=>$m,'nombre'=>$nombre,'promo_id'=>$promoId];
                }
            }

            if ($tipo === 'NTH_PCT') {
                $n   = (int)($p['n'] ?? 0);
                $pct = (float)($p['porcentaje'] ?? 0);

                [, $desc] = $this->calcNthPct($cant, $pu, $n, $pct, $esPesable);
                if ($desc > $bestDesc + self::EPS) {
                    $bestDesc = $desc;
                    $bestTipo = 'NTH_PCT';
                    $bestExtra = ['n'=>$n,'porcentaje'=>$pct,'nombre'=>$nombre,'promo_id'=>$promoId];
                }
            }
        }

        if ($bestDesc <= self::EPS) return;

        $item['descuento'] += $bestDesc;

        $label = ($bestTipo === 'N_PAGA_M') ? 'NxM' : '% N°';

        $item['promo'] = ($item['promo'] !== '')
            ? ($item['promo'] . ' | ' . $label)
            : $label;

        $item['promos_aplicadas'][] = array_merge(
            ['label'=>$label,'tipo'=>$bestTipo,'descuento'=>$bestDesc],
            $bestExtra
        );
    }

    private function calcNxM(float $cantidad, float $precioUnit, int $n, int $m): array
    {
        if ($cantidad < $n || $n <= 0 || $m <= 0 || $m >= $n) return [0.0, 0.0];
        if (!$this->isIntLike($cantidad)) return [0.0, 0.0];

        $cant = (int)round($cantidad);
        $packs = intdiv($cant, $n);
        $resto = $cant % $n;
        $unidadesPagas = ($packs * $m) + $resto;

        $normal = $cant * $precioUnit;
        $promo  = $unidadesPagas * $precioUnit;
        $desc   = $this->clamp0($normal - $promo);

        return [$promo, $desc];
    }

    private function calcNthPct(float $cantidad, float $precioUnit, int $n, float $pct, bool $esPesable): array
    {
        if ($cantidad < $n || $n <= 0 || $pct <= 0 || $pct > 100) return [0.0, 0.0];

        if ($esPesable) {
            $descUnidades = floor($cantidad / $n);
        } else {
            if (!$this->isIntLike($cantidad)) return [0.0, 0.0];
            $cant = (int)round($cantidad);
            $descUnidades = intdiv($cant, $n);
        }

        $desc = $descUnidades * ($precioUnit * ($pct / 100.0));
        $desc = $this->clamp0($desc);

        $normal = $cantidad * $precioUnit;
        $promo  = $this->clamp0($normal - $desc);

        return [$promo, $desc];
    }

    /* ==========================================================
       COMBOS FIJOS
    ========================================================== */

    private function aplicarCombosFijos(array $items, array $combos): array
    {
        // Index: producto_id => [idx1, idx2, ...] (soporta productos repetidos en carrito)
        $lineasPorProducto = [];
        foreach ($items as $idx => $it) {
            $pid = $this->getItemProductId($it);
            if ($pid <= 0) continue;

            $lineasPorProducto[$pid][] = $idx;
            $items[$idx]['_resto_combo'] = (float)($it['cantidad'] ?? 0);

            $items[$idx]['promo'] = (string)($items[$idx]['promo'] ?? '');
            $items[$idx]['descuento'] = (float)($items[$idx]['descuento'] ?? 0.0);
            $items[$idx]['promos_aplicadas'] = is_array($items[$idx]['promos_aplicadas'] ?? null) ? $items[$idx]['promos_aplicadas'] : [];
        }

        foreach ($combos as $combo) {
            if (($combo['tipo'] ?? '') !== 'COMBO_FIJO') continue;

            $comboItems  = $combo['items'] ?? [];
            $precioCombo = (float)($combo['precio_combo'] ?? 0);
            if (empty($comboItems) || $precioCombo <= 0) continue;

            $comboId     = (int)($combo['promo_id'] ?? $combo['id'] ?? 0);
            $comboNombre = (string)($combo['nombre'] ?? 'Combo');

            $tienePesables = false;
            foreach ($comboItems as $ci) {
                if (!empty($ci['es_pesable'])) { $tienePesables = true; break; }
            }

            // Regla conservadora: combos con pesables => 1 aplicación (evita líos de redondeo)
            $maxCombos = $tienePesables ? 1 : PHP_INT_MAX;

            // Calcular max combos por disponibilidad total (sumando líneas)
            foreach ($comboItems as $ci) {
                $pidReq  = (int)($ci['producto_id'] ?? 0);
                $cantReq = (float)($ci['cantidad'] ?? 0);
                if ($pidReq <= 0 || $cantReq <= 0) { $maxCombos = 0; break; }
                if (!isset($lineasPorProducto[$pidReq])) { $maxCombos = 0; break; }

                $dispTotal = 0.0;
                foreach ($lineasPorProducto[$pidReq] as $idxLine) {
                    $dispTotal += (float)($items[$idxLine]['_resto_combo'] ?? 0);
                }

                $posibles = (int)floor(($dispTotal + self::EPS) / $cantReq);
                $maxCombos = min($maxCombos, $posibles);
            }

            if ($maxCombos <= 0) continue;

            // Aplicar combos iterativamente
            for ($k = 0; $k < $maxCombos; $k++) {

                // 1) Construir allocations sin consumir
                $allocations = []; // [ ['idx'=>int,'qty'=>float,'part'=>float], ... ]
                $precioNormal = 0.0;
                $ok = true;

                foreach ($comboItems as $ci) {
                    $pidReq  = (int)$ci['producto_id'];
                    $cantReq = (float)$ci['cantidad'];

                    $need = $cantReq;
                    foreach ($lineasPorProducto[$pidReq] as $idxLine) {
                        $disp = (float)($items[$idxLine]['_resto_combo'] ?? 0);
                        if ($disp <= self::EPS) continue;

                        $take = min($disp, $need);
                        $pu   = $this->getItemUnitPrice($items[$idxLine]);
                        $part = $pu * $take;

                        $allocations[] = ['idx'=>$idxLine,'qty'=>$take,'part'=>$part,'pid'=>$pidReq];

                        $precioNormal += $part;
                        $need -= $take;

                        if ($need <= self::EPS) break;
                    }

                    if ($need > self::EPS) { $ok = false; break; }
                }

                if (!$ok) break;
                if ($precioNormal <= 0) break;
                if ($precioCombo >= $precioNormal - self::EPS) break;

                // 2) Aplicar descuento proporcional
                $descuentoCombo = $precioNormal - $precioCombo;

                foreach ($allocations as $al) {
                    $idxLine = (int)$al['idx'];
                    $part    = (float)$al['part'];
                    $prop    = ($precioNormal > 0) ? ($part / $precioNormal) : 0.0;
                    $desc    = $this->clamp0($descuentoCombo * $prop);

                    $items[$idxLine]['descuento'] += $desc;

                    $label = "Combo: {$comboNombre}";
                    $items[$idxLine]['promo'] = ($items[$idxLine]['promo'] !== '')
                        ? ($items[$idxLine]['promo'] . ' | ' . $label)
                        : $label;

                    $items[$idxLine]['promos_aplicadas'][] = [
                        'label'     => $label,
                        'tipo'      => 'COMBO_FIJO',
                        'promo_id'  => $comboId,
                        'nombre'    => $comboNombre,
                        'descuento' => $desc,
                    ];
                }

                // 3) Consumir cantidades
                foreach ($allocations as $al) {
                    $idxLine = (int)$al['idx'];
                    $qty     = (float)$al['qty'];
                    $items[$idxLine]['_resto_combo'] = (float)($items[$idxLine]['_resto_combo'] ?? 0) - $qty;
                }
            }
        }

        // Limpiar interno
        foreach ($items as &$it) unset($it['_resto_combo']);
        unset($it);

        return $items;
    }

    /* ==========================================================
       CACHE
    ========================================================== */

    private function cacheGet(): ?array
    {
        // APCu (multi-request)
        if (function_exists('apcu_fetch')) {
            $data = apcu_fetch(self::CACHE_KEY, $ok);
            if ($ok && is_array($data)) return $data;
        }

        // Mem cache (por request)
        if ($this->memCache !== null && time() < $this->memCacheExp) {
            return $this->memCache;
        }

        return null;
    }

    private function cacheSet(array $data): void
    {
        // Mem cache (por request)
        $this->memCache = $data;
        $this->memCacheExp = time() + self::CACHE_TTL;

        // APCu (si existe)
        if (function_exists('apcu_store')) {
            @apcu_store(self::CACHE_KEY, $data, self::CACHE_TTL);
        }
    }
}
