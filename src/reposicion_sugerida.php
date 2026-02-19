<?php
// src/reposicion_sugerida.php
declare(strict_types=1);

/**
 * FLUS — Reposición sugerida
 *
 * Objetivo:
 *  - Detectar productos con riesgo de quiebre (SIN_STOCK / BAJO_MINIMO / REORDEN)
 *  - Generar una "lista de compras" agrupada por proveedor
 *  - Permitir configurar stock_minimo / punto_reorden / stock_maximo por producto
 *
 * Compatibilidad:
 *  - Algunas instalaciones guardan config en columnas de `productos`.
 *  - Otras (o migraciones parciales) usan la tabla auxiliar `producto_reposicion`.
 *  - Este módulo lee SIEMPRE un "valor efectivo" por COALESCE entre ambos orígenes.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/db_helpers.php';

// ─────────────────────────────────────────────────────────────────────────────
// Tabla auxiliar
// ─────────────────────────────────────────────────────────────────────────────

function reposicion_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS producto_reposicion (
        producto_id INT NOT NULL,
        stock_minimo DECIMAL(12,3) NULL,
        stock_maximo DECIMAL(12,3) NULL,
        punto_reorden DECIMAL(12,3) NULL,
        proveedor_id INT NULL,
        dias_reposicion INT NULL DEFAULT 7,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (producto_id),
        KEY idx_proveedor (proveedor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers de esquema + expresiones SQL
// ─────────────────────────────────────────────────────────────────────────────

function _repo_schema(PDO $pdo): array {
    static $cache = [];
    $id = spl_object_id($pdo);
    if (isset($cache[$id])) return $cache[$id];

    // Asegurar tabla auxiliar para que JOINs no rompan
    reposicion_ensure_tables($pdo);

    $cache[$id] = [
        'p_min'  => has_column($pdo, 'productos', 'stock_minimo'),
        'p_max'  => has_column($pdo, 'productos', 'stock_maximo'),
        'p_reo'  => has_column($pdo, 'productos', 'punto_reorden'),
        'p_prov' => has_column($pdo, 'productos', 'proveedor_id'),
    ];

    return $cache[$id];
}

/**
 * Valor efectivo de stock_minimo (NULL si no está definido).
 * Regla: 0 / NULL se considera "no configurado".
 */
function _repo_expr_min(PDO $pdo): string {
    $s = _repo_schema($pdo);
    $parts = [];
    if ($s['p_min']) $parts[] = 'NULLIF(p.stock_minimo,0)';
    $parts[] = 'NULLIF(r.stock_minimo,0)';
    return 'COALESCE(' . implode(', ', $parts) . ')';
}

/**
 * Valor efectivo de punto_reorden (NULL si no está definido).
 */
function _repo_expr_reorden(PDO $pdo): string {
    $s = _repo_schema($pdo);
    $parts = [];
    if ($s['p_reo']) $parts[] = 'NULLIF(p.punto_reorden,0)';
    $parts[] = 'NULLIF(r.punto_reorden,0)';
    return 'COALESCE(' . implode(', ', $parts) . ')';
}

/**
 * Valor efectivo de stock_maximo (NULL si no está definido).
 */
function _repo_expr_max(PDO $pdo): string {
    $s = _repo_schema($pdo);
    $parts = [];
    if ($s['p_max']) $parts[] = 'NULLIF(p.stock_maximo,0)';
    $parts[] = 'NULLIF(r.stock_maximo,0)';
    return 'COALESCE(' . implode(', ', $parts) . ')';
}

/**
 * Proveedor efectivo (prioriza el seteo del aux si existe).
 */
function _repo_expr_proveedor(PDO $pdo): string {
    $s = _repo_schema($pdo);
    // 0 se considera "no configurado".
    if ($s['p_prov']) return 'COALESCE(NULLIF(r.proveedor_id,0), NULLIF(p.proveedor_id,0))';
    return 'NULLIF(r.proveedor_id,0)';
}

function _repo_estado_expr(PDO $pdo): string {
    $min = _repo_expr_min($pdo);
    $reo = _repo_expr_reorden($pdo);

    // REORDEN sólo si punto_reorden está definido y no está por debajo del mínimo.
    return "CASE
        WHEN p.stock <= 0 THEN 'SIN_STOCK'
        WHEN ($min IS NOT NULL AND p.stock > 0 AND p.stock < $min) THEN 'BAJO_MINIMO'
        WHEN ($reo IS NOT NULL AND p.stock > 0 AND p.stock < $reo AND ($min IS NULL OR p.stock >= $min)) THEN 'REORDEN'
        ELSE 'NORMAL'
    END";
}

function _repo_order_expr(PDO $pdo): string {
    $min = _repo_expr_min($pdo);
    $reo = _repo_expr_reorden($pdo);

    return "CASE
        WHEN p.stock <= 0 THEN 1
        WHEN ($min IS NOT NULL AND p.stock > 0 AND p.stock < $min) THEN 2
        WHEN ($reo IS NOT NULL AND p.stock > 0 AND p.stock < $reo AND ($min IS NULL OR p.stock >= $min)) THEN 3
        ELSE 4
    END";
}

function _repo_cond_stock_bajo(PDO $pdo): string {
    $min = _repo_expr_min($pdo);
    $reo = _repo_expr_reorden($pdo);

    return "(
        p.stock <= 0
        OR ($min IS NOT NULL AND p.stock < $min)
        OR ($reo IS NOT NULL AND p.stock < $reo)
    )";
}

/**
 * Max sugerido efectivo:
 *  - stock_maximo definido: usarlo
 *  - si hay mínimo: mínimo * 3
 *  - si no hay config y está sin stock: sugerir 1
 *  - sino: 10
 */
function _repo_expr_max_sugerido(PDO $pdo): string {
    $max = _repo_expr_max($pdo);
    $min = _repo_expr_min($pdo);

    $fallback = "(CASE
        WHEN $min IS NOT NULL THEN ($min * 3)
        WHEN p.stock <= 0 THEN 1
        ELSE 10
    END)";

    return "COALESCE($max, $fallback)";
}

// Compat: usado por public/reposicion.php
function _repo_has_col(string $col): bool {
    try {
        $pdo = getPDO();
        return has_column($pdo, 'productos', $col);
    } catch (Throwable $e) {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CRUD Configuración
// ─────────────────────────────────────────────────────────────────────────────

function reposicion_set_config(
    int $productoId,
    ?float $stockMinimo = null,
    ?float $stockMaximo = null,
    ?float $puntoReorden = null,
    ?int $proveedorPredeterminadoId = null
): bool {
    try {
        $pdo = getPDO();
        reposicion_ensure_tables($pdo);
        $s = _repo_schema($pdo);

        // Normalizar: <=0 => NULL
        $norm = static function (?float $v): ?float {
            if ($v === null) return null;
            return ($v > 0) ? $v : null;
        };
        $stockMinimo  = $norm($stockMinimo);
        $stockMaximo  = $norm($stockMaximo);
        $puntoReorden = $norm($puntoReorden);

        // 1) Actualizar productos si existen columnas
        if ($s['p_min'] || $s['p_max'] || $s['p_reo'] || $s['p_prov']) {
            $updates = [];
            $params  = [':id' => $productoId];

            if ($s['p_min']) { $updates[] = 'stock_minimo = :min'; $params[':min'] = ($stockMinimo ?? 0); }
            if ($s['p_max']) { $updates[] = 'stock_maximo = :max'; $params[':max'] = $stockMaximo; }
            if ($s['p_reo']) { $updates[] = 'punto_reorden = :reo'; $params[':reo'] = $puntoReorden; }
            if ($proveedorPredeterminadoId !== null && $s['p_prov']) {
                $updates[] = 'proveedor_id = :prov';
                $params[':prov'] = $proveedorPredeterminadoId;
            }

            if (!empty($updates)) {
                $sql = 'UPDATE productos SET ' . implode(', ', $updates) . ' WHERE id = :id';
                $st = $pdo->prepare($sql);
                $st->execute($params);
            }
        }

        // 2) Upsert tabla auxiliar (compat)
        $st2 = $pdo->prepare("INSERT INTO producto_reposicion
            (producto_id, stock_minimo, stock_maximo, punto_reorden, proveedor_id)
            VALUES (:pid, :min, :max, :reo, :prov)
            ON DUPLICATE KEY UPDATE
                stock_minimo = VALUES(stock_minimo),
                stock_maximo = VALUES(stock_maximo),
                punto_reorden = VALUES(punto_reorden),
                -- permitir setear/limpiar proveedor explícitamente
                proveedor_id = VALUES(proveedor_id)
        ");
        $st2->execute([
            ':pid'  => $productoId,
            ':min'  => $stockMinimo,
            ':max'  => $stockMaximo,
            ':reo'  => $puntoReorden,
            ':prov' => $proveedorPredeterminadoId,
        ]);

        return true;
    } catch (Throwable $e) {
        flus_log_error('reposicion_set_config failed', ['error' => $e->getMessage()]);
        return false;
    }
}

function reposicion_get_config(int $productoId): array {
    try {
        $pdo = getPDO();
        reposicion_ensure_tables($pdo);
        $s = _repo_schema($pdo);

        $selectMin  = $s['p_min']  ? 'p.stock_minimo'  : 'NULL';
        $selectMax  = $s['p_max']  ? 'p.stock_maximo'  : 'NULL';
        $selectReo  = $s['p_reo']  ? 'p.punto_reorden' : 'NULL';
        $selectProv = $s['p_prov'] ? 'p.proveedor_id'  : 'NULL';

        $st = $pdo->prepare("SELECT
                COALESCE(NULLIF($selectMin,0), NULLIF(r.stock_minimo,0), 0) AS stock_minimo,
                COALESCE(NULLIF($selectMax,0), NULLIF(r.stock_maximo,0), 0) AS stock_maximo,
                COALESCE(NULLIF($selectReo,0), NULLIF(r.punto_reorden,0), 0) AS punto_reorden,
                COALESCE(NULLIF(r.proveedor_id,0), $selectProv) AS proveedor_id
            FROM productos p
            LEFT JOIN producto_reposicion r ON r.producto_id = p.id
            WHERE p.id = ?
            LIMIT 1");
        $st->execute([$productoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (Throwable $e) {
        flus_log_error('reposicion_get_config failed', ['error' => $e->getMessage()]);
        return [];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Queries principales
// ─────────────────────────────────────────────────────────────────────────────

function reposicion_get_stock_bajo(int $limit = 200, array $filtros = []): array {
    try {
        $pdo = getPDO();
        reposicion_ensure_tables($pdo);

        $min        = _repo_expr_min($pdo);
        $reo        = _repo_expr_reorden($pdo);
        $maxS       = _repo_expr_max_sugerido($pdo);
        $estadoExpr = _repo_estado_expr($pdo);
        $orderExpr  = _repo_order_expr($pdo);
        $provExpr   = _repo_expr_proveedor($pdo);

        $where  = ['p.activo = 1', _repo_cond_stock_bajo($pdo)];
        $params = [];

        // Filtro proveedor:
        //  - null => todos
        //  - 0    => sin proveedor
        //  - >0   => proveedor específico
        if (array_key_exists('proveedor_id', $filtros) && $filtros['proveedor_id'] !== null) {
            $provId = (int)$filtros['proveedor_id'];
            if ($provId === 0) {
                $where[] = "$provExpr IS NULL";
            } else {
                $where[] = "$provExpr = :prov";
                $params[':prov'] = $provId;
            }
        }

        if (!empty($filtros['estado'])) {
            $where[] = "$estadoExpr = :estado";
            $params[':estado'] = (string)$filtros['estado'];
        }

        if (!empty($filtros['buscar'])) {
            $where[] = '(p.codigo LIKE :q OR p.nombre LIKE :q)';
            $params[':q'] = '%' . trim((string)$filtros['buscar']) . '%';
        }

        $sql = "SELECT
                p.id,
                p.codigo,
                p.nombre,
                p.stock,
                p.costo,
                COALESCE($min, 0) AS stock_minimo,
                COALESCE($reo, 0) AS punto_reorden,
                $estadoExpr AS estado,
                GREATEST(0, ($maxS - p.stock)) AS cantidad_sugerida,
                COALESCE(pv.nombre, 'Sin proveedor') AS proveedor_nombre,
                COALESCE(pv.id, 0) AS proveedor_id
            FROM productos p
            LEFT JOIN producto_reposicion r ON r.producto_id = p.id
            LEFT JOIN proveedores pv ON pv.id = $provExpr
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $orderExpr ASC, p.stock ASC, p.nombre ASC
            LIMIT " . (int)$limit;

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        flus_log_error('reposicion_get_stock_bajo failed', ['error' => $e->getMessage()]);
        return [];
    }
}

function reposicion_conteo_estados(): array {
    try {
        $pdo = getPDO();
        reposicion_ensure_tables($pdo);

        $min = _repo_expr_min($pdo);
        $reo = _repo_expr_reorden($pdo);

        $sql = "SELECT
                SUM(CASE WHEN p.stock <= 0 THEN 1 ELSE 0 END) AS sin_stock,
                SUM(CASE WHEN p.stock > 0 AND ($min IS NOT NULL) AND p.stock < $min THEN 1 ELSE 0 END) AS bajo_minimo,
                SUM(CASE WHEN p.stock > 0 AND ($reo IS NOT NULL) AND p.stock < $reo AND ($min IS NULL OR p.stock >= $min) THEN 1 ELSE 0 END) AS reorden,
                COUNT(*) AS total
            FROM productos p
            LEFT JOIN producto_reposicion r ON r.producto_id = p.id
            WHERE p.activo = 1";

        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) $row = [];

        return [
            'sin_stock'   => (int)($row['sin_stock'] ?? 0),
            'bajo_minimo' => (int)($row['bajo_minimo'] ?? 0),
            'reorden'     => (int)($row['reorden'] ?? 0),
            'total'       => (int)($row['total'] ?? 0),
        ];
    } catch (Throwable $e) {
        flus_log_error('reposicion_conteo_estados failed', ['error' => $e->getMessage()]);
        return ['sin_stock' => 0, 'bajo_minimo' => 0, 'reorden' => 0, 'total' => 0];
    }
}

/**
 * Lista de compras. Si $proveedorId es null: devuelve todos.
 */
function reposicion_generar_lista(?int $proveedorId = null, int $limit = 1500): array {
    try {
        $pdo = getPDO();
        reposicion_ensure_tables($pdo);

        $min        = _repo_expr_min($pdo);
        $reo        = _repo_expr_reorden($pdo);
        $maxS       = _repo_expr_max_sugerido($pdo);
        $estadoExpr = _repo_estado_expr($pdo);
        $orderExpr  = _repo_order_expr($pdo);
        $provExpr   = _repo_expr_proveedor($pdo);

        $where  = ['p.activo = 1', _repo_cond_stock_bajo($pdo)];
        $params = [];

        if ($proveedorId !== null) {
            if ($proveedorId === 0) {
                $where[] = "$provExpr IS NULL";
            } elseif ($proveedorId > 0) {
                $where[] = "$provExpr = :prov";
                $params[':prov'] = $proveedorId;
            }
        }

        $sql = "SELECT
                p.id,
                p.codigo,
                p.nombre,
                p.stock,
                p.costo,
                COALESCE($min, 0) AS stock_minimo,
                COALESCE($reo, 0) AS punto_reorden,
                $estadoExpr AS estado,
                GREATEST(0, ($maxS - p.stock)) AS cantidad_sugerida,
                COALESCE(pv.nombre, 'Sin proveedor') AS proveedor_nombre,
                COALESCE(pv.id, 0) AS proveedor_id
            FROM productos p
            LEFT JOIN producto_reposicion r ON r.producto_id = p.id
            LEFT JOIN proveedores pv ON pv.id = $provExpr
            WHERE " . implode(' AND ', $where) . "
            ORDER BY proveedor_nombre ASC, $orderExpr ASC, p.stock ASC, p.nombre ASC
            LIMIT " . (int)$limit;

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        flus_log_error('reposicion_generar_lista failed', ['error' => $e->getMessage()]);
        return [];
    }
}

function reposicion_lista_por_proveedor(array $filtros = []): array {
    $proveedorId = null;
    if (array_key_exists('proveedor_id', $filtros) && $filtros['proveedor_id'] !== null) {
        $proveedorId = (int)$filtros['proveedor_id']; // puede ser 0
    }
    $items = reposicion_generar_lista($proveedorId);

    // búsqueda (en lista)
    if (!empty($filtros['buscar'])) {
        $q = mb_strtolower(trim((string)$filtros['buscar']));
        $items = array_values(array_filter($items, static function (array $it) use ($q): bool {
            $codigo = mb_strtolower((string)($it['codigo'] ?? ''));
            $nombre = mb_strtolower((string)($it['nombre'] ?? ''));
            return $q === '' || str_contains($codigo, $q) || str_contains($nombre, $q);
        }));
    }

    $out = [];
    foreach ($items as $it) {
        $pid = (int)($it['proveedor_id'] ?? 0);
        if (!isset($out[$pid])) {
            $out[$pid] = [
                'proveedor_id' => $pid,
                'proveedor'    => (string)($it['proveedor_nombre'] ?? 'Sin proveedor'),
                'items'        => [],
                'total_items'  => 0,
                'costo_total'  => 0.0,
            ];
        }

        $out[$pid]['items'][] = $it;
        $out[$pid]['total_items']++;
        $out[$pid]['costo_total'] += (float)($it['cantidad_sugerida'] ?? 0) * (float)($it['costo'] ?? 0);
    }

    $out = array_values($out);
    usort($out, static fn(array $a, array $b): int => strcasecmp((string)$a['proveedor'], (string)$b['proveedor']));

    return $out;
}

function reposicion_exportar_csv(?int $proveedorId = null): string {
    $rows = reposicion_generar_lista($proveedorId);

    $fh = fopen('php://temp', 'r+');
    if (!$fh) return '';

    fputcsv($fh, ['Proveedor', 'Código', 'Producto', 'Stock', 'Min', 'Reorden', 'Cantidad Sugerida', 'Costo Unit', 'Subtotal']);

    foreach ($rows as $r) {
        $cant  = (float)($r['cantidad_sugerida'] ?? 0);
        $costo = (float)($r['costo'] ?? 0);

        fputcsv($fh, [
            (string)($r['proveedor_nombre'] ?? ''),
            (string)($r['codigo'] ?? ''),
            (string)($r['nombre'] ?? ''),
            (float)($r['stock'] ?? 0),
            (float)($r['stock_minimo'] ?? 0),
            (float)($r['punto_reorden'] ?? 0),
            $cant,
            $costo,
            $cant * $costo,
        ]);
    }

    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv ?: '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Heurísticas (opcionales)
// ─────────────────────────────────────────────────────────────────────────────

function reposicion_cantidad_optima(int $productoId, int $dias = 30, int $leadTimeDias = 7): float {
    try {
        $pdo = getPDO();
        if (!has_table($pdo, 'venta_items') || !has_table($pdo, 'ventas')) return 0.0;

        $st = $pdo->prepare("SELECT COALESCE(SUM(vi.cantidad),0) AS vendidas
            FROM venta_items vi
            INNER JOIN ventas v ON v.id = vi.venta_id
            WHERE vi.producto_id = ? AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
        $st->execute([$productoId, $dias]);
        $vendidas = (float)$st->fetchColumn();

        $promDiario = $dias > 0 ? ($vendidas / $dias) : 0.0;
        $objetivo = $promDiario * max(1, $leadTimeDias);
        return max(0.0, $objetivo);
    } catch (Throwable $e) {
        return 0.0;
    }
}

function reposicion_sugerir_por_ventas(int $dias = 30, int $leadTimeDias = 7, int $limit = 100): array {
    try {
        $pdo = getPDO();
        if (!has_table($pdo, 'venta_items') || !has_table($pdo, 'ventas')) return [];

        $min = _repo_expr_min($pdo);
        $reo = _repo_expr_reorden($pdo);
        $provExpr = _repo_expr_proveedor($pdo);

        $sql = "SELECT
                p.id, p.codigo, p.nombre, p.stock, p.costo,
                COALESCE($min, 0) AS stock_minimo,
                COALESCE($reo, 0) AS punto_reorden,
                COALESCE(pv.nombre, 'Sin proveedor') AS proveedor_nombre,
                COALESCE(pv.id, 0) AS proveedor_id,
                COALESCE(SUM(vi.cantidad),0) AS vendidas
            FROM productos p
            LEFT JOIN producto_reposicion r ON r.producto_id = p.id
            LEFT JOIN proveedores pv ON pv.id = $provExpr
            LEFT JOIN venta_items vi ON vi.producto_id = p.id
            LEFT JOIN ventas v ON v.id = vi.venta_id AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
            WHERE p.activo = 1
            GROUP BY p.id
            ORDER BY vendidas DESC
            LIMIT " . (int)$limit;

        $st = $pdo->prepare($sql);
        $st->execute([':dias' => $dias]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        foreach ($rows as &$rRow) {
            $vendidas = (float)($rRow['vendidas'] ?? 0);
            $promDiario = $dias > 0 ? ($vendidas / $dias) : 0.0;
            $objetivo = $promDiario * max(1, $leadTimeDias);
            $stock = (float)($rRow['stock'] ?? 0);
            $rRow['cantidad_sugerida'] = max(0.0, $objetivo - $stock);
        }
        unset($rRow);

        return $rows;
    } catch (Throwable $e) {
        flus_log_error('reposicion_sugerir_por_ventas failed', ['error' => $e->getMessage()]);
        return [];
    }
}
