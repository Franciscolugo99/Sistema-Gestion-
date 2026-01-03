<?php
// public/includes/PromoEngine.php
declare(strict_types=1);

/**
 * Motor de promociones optimizado con caché APCu
 * Soporta: NxM, Nth%, Combos fijos, productos pesables
 */
final class PromoEngine
{
    private PDO $pdo;
    
    private const CACHE_KEY = 'flus_promos_activas_v2';
    private const CACHE_TTL = 300; // 5 min
    private const EPS = 0.00001;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ==========================================================
       API PÚBLICA
    ========================================================== */

    /**
     * Obtiene promos activas (con caché APCu si disponible)
     * 
     * @param bool $forceRefresh Forzar recarga desde DB
     * @return array ['simples' => [...], 'combos' => [...]]
     */
    public function obtenerPromosActivas(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->cacheGet();
            if (is_array($cached)) {
                return $cached;
            }
        }

        $hoy = date('Y-m-d');

        // ✅ PROMOS SIMPLES (NxM y Nth%) - Query optimizado
        $sqlSimples = "
            SELECT
                p.id         AS promo_id,
                p.nombre     AS promo_nombre,
                p.tipo       AS promo_tipo,
                pp.producto_id,
                pp.n,
                pp.m,
                pp.porcentaje,
                pr.es_pesable,
                pr.codigo    AS prod_codigo,
                pr.nombre    AS prod_nombre
            FROM promos p
            INNER JOIN promo_productos pp ON pp.promo_id = p.id
            INNER JOIN productos pr       ON pr.id = pp.producto_id
            WHERE p.activo = 1
              AND pr.activo = 1
              AND p.tipo IN ('N_PAGA_M', 'NTH_PCT')
              AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= :hoy1)
              AND (p.fecha_fin    IS NULL OR p.fecha_fin    >= :hoy2)
            ORDER BY p.id ASC, pr.nombre ASC
        ";
        
        $st1 = $this->pdo->prepare($sqlSimples);
        $st1->execute([':hoy1' => $hoy, ':hoy2' => $hoy]);
        $simples = $st1->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // ✅ COMBOS FIJOS - Query optimizado
$sqlCombos = "
            SELECT 
                p.id AS promo_id,
                p.nombre,
                p.tipo,
                p.precio_combo,
                pci.producto_id,
                pci.cantidad,  -- ✅ Usar cantidad
                pr.es_pesable,
                pr.activo AS producto_activo
            FROM promos p
            JOIN promo_combo_items pci ON pci.promo_id = p.id
            JOIN productos pr ON pr.id = pci.producto_id
            WHERE p.activo = 1
              AND p.tipo = 'COMBO_FIJO'
              AND pr.activo = 1
              AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= :hoy1)
              AND (p.fecha_fin     IS NULL OR p.fecha_fin     >= :hoy2)
            ORDER BY p.id ASC, pr.nombre ASC
        ";
        
        $st2 = $this->pdo->prepare($sqlCombos);
        $st2->execute([':hoy1' => $hoy, ':hoy2' => $hoy]);
        $combosRaw = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Agrupar combos por promo_id
        $combos = [];
        foreach ($combosRaw as $c) {
            $id = (int)$c['promo_id'];

            if (!isset($combos[$id])) {
                $combos[$id]['items'][] = [
                'producto_id' => (int)$c['producto_id'],
                'cantidad'    => (float)$c['cantidad'],  // ✅ Usar cantidad
                'es_pesable'  => (bool)$c['es_pesable'],
            ];
            }

            $combos[$id]['items'][] = [
                'producto_id' => (int)$c['producto_id'],
                'cantidad'    => (float)$c['cantidad_requerida'],
                'es_pesable'  => (bool)$c['es_pesable'],
                'prod_codigo' => (string)$c['prod_codigo'],
                'prod_nombre' => (string)$c['prod_nombre'],
                'prod_precio' => (float)($c['prod_precio'] ?? 0),
            ];
        }

        $result = [
            'simples' => $simples,
            'combos'  => array_values($combos),
        ];

        $this->cacheSet($result);
        return $result;
    }

    /**
     * Aplica promos al carrito completo
     * 
     * @param array $items Carrito de items
     * @param array|null $promos Promos activas (null = auto-fetch)
     * @return array Items con promos aplicadas
     */
    public function aplicarPromosACarrito(array $items, ?array $promos = null): array
    {
        $promos ??= $this->obtenerPromosActivas(false);

        $simples = $promos['simples'] ?? [];
        $combos  = $promos['combos']  ?? [];

        // Indexar promos simples por producto_id (permite múltiples promos x producto)
        $promosPorProducto = [];
        foreach ($simples as $p) {
            $pid = (int)($p['producto_id'] ?? 0);
            if ($pid > 0) {
                $promosPorProducto[$pid][] = $p;
            }
        }

        // PASO 1: Inicializar items + aplicar mejor promo simple
        foreach ($items as &$item) {
            $this->initItem($item);

            $pid = $this->getItemProductId($item);
            if ($pid > 0 && isset($promosPorProducto[$pid])) {
                $this->aplicarMejorPromoSimple($item, $promosPorProducto[$pid]);
            }

            $this->finalizeItem($item);
        }
        unset($item);

        // PASO 2: Aplicar combos fijos (se apilan sobre promos simples)
        if (!empty($combos)) {
            $items = $this->aplicarCombosFijos($items, $combos);

            // Re-finalizar después de combos
            foreach ($items as &$it) {
                $this->finalizeItem($it);
            }
            unset($it);
        }

        return $items;
    }

    /**
     * Invalida caché de promos (llamar después de crear/editar/eliminar)
     */
    public function invalidarCache(): void
    {
        if (function_exists('apcu_delete') && ini_get('apc.enabled')) {
            apcu_delete(self::CACHE_KEY);
        }
    }

    /* ==========================================================
       HELPERS PRIVADOS: Gestión de items
    ========================================================== */

    private function initItem(array &$item): void
    {
        $cant = (float)($item['cantidad'] ?? 0);
        $pu   = (float)($item['precio_unitario'] ?? $item['precio'] ?? 0);
        $base = $pu * $cant;

        $item['base_subtotal']        = $base;
        $item['descuento']            = 0.0;
        $item['subtotal']             = $base;
        $item['promo']                = $item['promo'] ?? null;
        $item['promos_aplicadas']     = $item['promos_aplicadas'] ?? [];
        $item['precio_unit_original'] = $pu;
        $item['precio_unit_final']    = $pu;
        $item['descuento_monto']      = 0.0;
        $item['precio_unitario']      = $pu; // Normalizar
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
        return (int)($item['producto_id'] ?? $item['id'] ?? 0);
    }

    /* ==========================================================
       HELPERS PRIVADOS: Math
    ========================================================== */

    private function round2(float $n): float
    {
        return round($n, 2);
    }

    private function clamp0(float $n): float
    {
        return ($n < 0) ? 0.0 : $n;
    }

    private function isIntLike(float $n): bool
    {
        return abs($n - floor($n)) < self::EPS;
    }

    /* ==========================================================
       LÓGICA: Promos simples (NxM y Nth%)
    ========================================================== */

    private function aplicarMejorPromoSimple(array &$item, array $promosDeProducto): void
    {
        $cant      = (float)($item['cantidad'] ?? 0);
        $pu        = (float)($item['precio_unitario'] ?? 0);
        $esPesable = (bool)($item['es_pesable'] ?? false);

        $bestDesc = 0.0;
        $bestInfo = null;

        foreach ($promosDeProducto as $p) {
            $tipo     = (string)($p['promo_tipo'] ?? $p['tipo'] ?? '');
            $promoId  = (int)($p['promo_id'] ?? 0);
            $nombre   = (string)($p['promo_nombre'] ?? $p['nombre'] ?? '');

            if ($tipo === 'N_PAGA_M') {
                // NxM solo aplica a productos NO pesables
                if ($esPesable) continue;

                $n = (int)($p['n'] ?? 0);
                $m = (int)($p['m'] ?? 0);
                $desc = $this->calcNxMDesc($cant, $pu, $n, $m);

                if ($desc > $bestDesc + self::EPS) {
                    $bestDesc = $desc;
                    $bestInfo = [
                        'label'     => 'NxM',
                        'tipo'      => 'N_PAGA_M',
                        'promo_id'  => $promoId,
                        'nombre'    => $nombre,
                        'n'         => $n,
                        'm'         => $m,
                        'descuento' => $desc,
                    ];
                }
            }

            if ($tipo === 'NTH_PCT') {
                $n   = (int)($p['n'] ?? 0);
                $pct = (float)($p['porcentaje'] ?? 0);
                $desc = $this->calcNthPctDesc($cant, $pu, $n, $pct, $esPesable);

                if ($desc > $bestDesc + self::EPS) {
                    $bestDesc = $desc;
                    $bestInfo = [
                        'label'      => '% N°',
                        'tipo'       => 'NTH_PCT',
                        'promo_id'   => $promoId,
                        'nombre'     => $nombre,
                        'n'          => $n,
                        'porcentaje' => $pct,
                        'descuento'  => $desc,
                    ];
                }
            }
        }

        if ($bestDesc <= self::EPS || !$bestInfo) return;

        // Aplicar mejor promo
        $item['descuento'] += $bestDesc;

        $prev = (string)($item['promo'] ?? '');
        $item['promo'] = ($prev !== '') 
            ? ($prev . ' | ' . $bestInfo['label']) 
            : $bestInfo['label'];

        $item['promos_aplicadas'][] = $bestInfo;
    }

    /**
     * Calcula descuento NxM (ej: 3x2, 4x3)
     * Solo unidades enteras
     */
    private function calcNxMDesc(float $cantidad, float $precioUnit, int $n, int $m): float
    {
        if ($cantidad < $n || $n <= 0 || $m <= 0 || $m >= $n) {
            return 0.0;
        }

        if (!$this->isIntLike($cantidad)) {
            return 0.0;
        }

        $cant  = (int)round($cantidad);
        $packs = intdiv($cant, $n);
        $resto = $cant % $n;
        $pagas = ($packs * $m) + $resto;

        $normal = $cant * $precioUnit;
        $promo  = $pagas * $precioUnit;

        return $this->clamp0($normal - $promo);
    }

    /**
     * Calcula descuento Nth% (ej: 20% en la 3ra)
     * Soporta pesables (ej: cada 3kg)
     */
    private function calcNthPctDesc(float $cantidad, float $precioUnit, int $n, float $pct, bool $esPesable): float
    {
        if ($cantidad < $n || $n <= 0 || $pct <= 0 || $pct > 100) {
            return 0.0;
        }

        if ($esPesable) {
            // Para pesables: cada N kg/lt, 1 descuento
            $descUnidades = (int)floor($cantidad / $n);
        } else {
            // Para unidades: debe ser entero
            if (!$this->isIntLike($cantidad)) {
                return 0.0;
            }
            $cant = (int)round($cantidad);
            $descUnidades = intdiv($cant, $n);
        }

        $desc = $descUnidades * ($precioUnit * ($pct / 100.0));
        return $this->clamp0($desc);
    }

    /* ==========================================================
       LÓGICA: Combos fijos
    ========================================================== */

    private function aplicarCombosFijos(array $items, array $combos): array
    {
        if (empty($items) || empty($combos)) return $items;

        // Indexar items por producto_id
        $indexPorProducto = [];
        foreach ($items as $idx => $it) {
            $pid = $this->getItemProductId($it);
            if ($pid <= 0) continue;

            $indexPorProducto[$pid] = $idx;
            $items[$idx]['_resto_combo']     = (float)($it['cantidad'] ?? 0);
            $items[$idx]['promos_aplicadas'] = $items[$idx]['promos_aplicadas'] ?? [];
            $items[$idx]['promo']            = $items[$idx]['promo'] ?? null;
            $items[$idx]['descuento']        = (float)($items[$idx]['descuento'] ?? 0);
        }

        foreach ($combos as $combo) {
            if (($combo['tipo'] ?? '') !== 'COMBO_FIJO') continue;

            $comboItems  = $combo['items'] ?? [];
            $precioCombo = (float)($combo['precio_combo'] ?? 0);

            if (empty($comboItems) || $precioCombo <= 0) continue;

            $comboId     = (int)($combo['promo_id'] ?? $combo['id'] ?? 0);
            $comboNombre = (string)($combo['nombre'] ?? 'Combo');

            // ✅ Verificar si tiene pesables (limitar a 1 combo)
            $tienePesables = false;
            foreach ($comboItems as $ci) {
                if (!empty($ci['es_pesable'])) {
                    $tienePesables = true;
                    break;
                }
            }

            $maxCombos = $tienePesables ? 1 : PHP_INT_MAX;

            // Calcular cuántos combos se pueden armar
            foreach ($comboItems as $ci) {
                $pidReq  = (int)($ci['producto_id'] ?? 0);
                $cantReq = (float)($ci['cantidad'] ?? 0);

                if ($pidReq <= 0 || $cantReq <= 0 || !isset($indexPorProducto[$pidReq])) {
                    $maxCombos = 0;
                    break;
                }

                $idxCar = $indexPorProducto[$pidReq];
                $disp   = (float)($items[$idxCar]['_resto_combo'] ?? 0);

                $posibles = (int)floor($disp / $cantReq);
                $maxCombos = min($maxCombos, $posibles);
            }

            if ($maxCombos <= 0) continue;

            // Aplicar combos (1 o más veces)
            for ($k = 0; $k < $maxCombos; $k++) {
                // Precio normal de 1 combo
                $precioNormal = 0.0;
                foreach ($comboItems as $ci) {
                    $pidReq  = (int)$ci['producto_id'];
                    $cantReq = (float)$ci['cantidad'];
                    $idxCar  = $indexPorProducto[$pidReq];
                    $precioU = (float)($items[$idxCar]['precio_unitario'] ?? 0);

                    $precioNormal += $precioU * $cantReq;
                }

                // Si no hay ahorro, no aplicar
                if ($precioNormal <= 0 || $precioCombo >= $precioNormal) break;

                $descCombo = $precioNormal - $precioCombo;

                // Repartir descuento proporcionalmente entre items del combo
                foreach ($comboItems as $ci) {
                    $pidReq  = (int)$ci['producto_id'];
                    $cantReq = (float)$ci['cantidad'];
                    $idxCar  = $indexPorProducto[$pidReq];
                    $precioU = (float)($items[$idxCar]['precio_unitario'] ?? 0);

                    $parte = $precioU * $cantReq;
                    $prop  = ($precioNormal > 0) ? ($parte / $precioNormal) : 0.0;
                    $descItem = $this->clamp0($descCombo * $prop);

                    $items[$idxCar]['descuento'] += $descItem;

                    $label = "Combo: {$comboNombre}";
                    $prev  = (string)($items[$idxCar]['promo'] ?? '');
                    $items[$idxCar]['promo'] = ($prev !== '') 
                        ? ($prev . ' | ' . $label) 
                        : $label;

                    $items[$idxCar]['promos_aplicadas'][] = [
                        'label'     => $label,
                        'tipo'      => 'COMBO_FIJO',
                        'promo_id'  => $comboId,
                        'nombre'    => $comboNombre,
                        'descuento' => $descItem,
                    ];

                    // Consumir cantidad
                    $items[$idxCar]['_resto_combo'] -= $cantReq;
                }
            }
        }

        // Limpiar campo temporal
        foreach ($items as &$it) {
            unset($it['_resto_combo']);
        }
        unset($it);

        return $items;
    }

    /* ==========================================================
       CACHE (APCu si disponible)
    ========================================================== */

    private function cacheGet(): mixed
    {
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $ok = false;
            $v = apcu_fetch(self::CACHE_KEY, $ok);
            return $ok ? $v : null;
        }
        return null;
    }

    private function cacheSet(array $data): void
    {
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            apcu_store(self::CACHE_KEY, $data, self::CACHE_TTL);
        }
    }
}