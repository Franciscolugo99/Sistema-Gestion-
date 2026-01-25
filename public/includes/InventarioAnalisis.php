<?php
/**
 * InventarioAnalisis.php
 * Clase para análisis y métricas de inventario - VERSIÓN MEJORADA
 * 
 * @version 2.0.0
 * @package FLUS
 */

declare(strict_types=1);

class InventarioAnalisis
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Obtiene el resumen general de inversión
     */
    public function getResumenGeneral(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_productos,
                COALESCE(SUM(stock), 0) as total_unidades,
                COALESCE(SUM(stock * COALESCE(costo, 0)), 0) as inversion_total,
                COALESCE(SUM(stock * precio), 0) as valor_venta_potencial,
                COALESCE(SUM(stock * precio), 0) - COALESCE(SUM(stock * COALESCE(costo, 0)), 0) as margen_teorico,
                SUM(CASE WHEN (costo IS NULL OR costo = 0) AND stock > 0 THEN 1 ELSE 0 END) as productos_sin_costo,
                SUM(CASE WHEN stock <= stock_minimo AND stock_minimo > 0 THEN 1 ELSE 0 END) as productos_stock_bajo,
                SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as productos_agotados
            FROM productos 
            WHERE activo = 1
        ";

        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // Calcular ventas del mes
        $ventasMes = $this->getVentasPeriodo(30);

        return [
            'total_productos' => (int)($result['total_productos'] ?? 0),
            'total_unidades' => (float)($result['total_unidades'] ?? 0),
            'inversion_total' => (float)($result['inversion_total'] ?? 0),
            'valor_venta_potencial' => (float)($result['valor_venta_potencial'] ?? 0),
            'margen_teorico' => (float)($result['margen_teorico'] ?? 0),
            'productos_sin_costo' => (int)($result['productos_sin_costo'] ?? 0),
            'productos_stock_bajo' => (int)($result['productos_stock_bajo'] ?? 0),
            'productos_agotados' => (int)($result['productos_agotados'] ?? 0),
            'ventas_mes' => $ventasMes,
        ];
    }

    /**
     * Obtiene ventas totales de un período
     */
    public function getVentasPeriodo(int $dias = 30): array
    {
        $sql = "
            SELECT 
                COUNT(DISTINCT v.id) as cantidad_ventas,
                COALESCE(SUM(v.total), 0) as total_vendido,
                COALESCE(SUM(vi.cantidad), 0) as unidades_vendidas
            FROM ventas v
            LEFT JOIN venta_items vi ON vi.venta_id = v.id
            WHERE v.estado = 'EMITIDA'
              AND v.fecha >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
        ";
        
        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'cantidad_ventas' => (int)($result['cantidad_ventas'] ?? 0),
            'total_vendido' => (float)($result['total_vendido'] ?? 0),
            'unidades_vendidas' => (float)($result['unidades_vendidas'] ?? 0),
        ];
    }

    /**
     * Obtiene los productos con mayor capital invertido CON FILTROS
     */
    public function getTopInversion(int $limit = 25, array $filtros = []): array
    {
        $where = "p.activo = 1 AND p.stock > 0 AND p.costo IS NOT NULL AND p.costo > 0";
        $params = [];

        // Filtro por categoría
        if (!empty($filtros['categoria'])) {
            $where .= " AND p.categoria = :categoria";
            $params[':categoria'] = $filtros['categoria'];
        }

        // Filtro por proveedor
        if (!empty($filtros['proveedor_id'])) {
            $where .= " AND p.proveedor_id = :proveedor_id";
            $params[':proveedor_id'] = (int)$filtros['proveedor_id'];
        }

        // Filtro por búsqueda
        if (!empty($filtros['busqueda'])) {
            $where .= " AND (p.nombre LIKE :busq OR p.codigo LIKE :busq2)";
            $params[':busq'] = '%' . $filtros['busqueda'] . '%';
            $params[':busq2'] = '%' . $filtros['busqueda'] . '%';
        }

        // Filtro por margen mínimo
        if (isset($filtros['margen_min']) && $filtros['margen_min'] !== '') {
            $where .= " AND ((p.precio - p.costo) / p.costo * 100) >= :margen_min";
            $params[':margen_min'] = (float)$filtros['margen_min'];
        }

        $limitSql = $limit > 0 ? "LIMIT {$limit}" : "";

        $sql = "
            SELECT 
                p.id, 
                p.codigo, 
                p.nombre,
                p.categoria,
                p.stock, 
                p.costo, 
                p.precio,
                p.stock_minimo,
                p.es_pesable,
                (p.stock * p.costo) as capital_invertido,
                (p.stock * p.precio) as valor_venta,
                ROUND(((p.precio - p.costo) / p.costo) * 100, 2) as margen_pct,
                COALESCE(pv.nombre, '-') as proveedor
            FROM productos p
            LEFT JOIN proveedores pv ON pv.id = p.proveedor_id
            WHERE {$where}
            ORDER BY capital_invertido DESC
            {$limitSql}
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cuenta total de productos con costo (para paginación)
     */
    public function contarProductosConCosto(array $filtros = []): int
    {
        $where = "activo = 1 AND stock > 0 AND costo IS NOT NULL AND costo > 0";
        $params = [];

        if (!empty($filtros['categoria'])) {
            $where .= " AND categoria = :categoria";
            $params[':categoria'] = $filtros['categoria'];
        }
        if (!empty($filtros['proveedor_id'])) {
            $where .= " AND proveedor_id = :proveedor_id";
            $params[':proveedor_id'] = (int)$filtros['proveedor_id'];
        }
        if (!empty($filtros['busqueda'])) {
            $where .= " AND (nombre LIKE :busq OR codigo LIKE :busq2)";
            $params[':busq'] = '%' . $filtros['busqueda'] . '%';
            $params[':busq2'] = '%' . $filtros['busqueda'] . '%';
        }

        $sql = "SELECT COUNT(*) FROM productos WHERE {$where}";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        
        return (int)$stmt->fetchColumn();
    }


/**
 * Cuenta total de productos SIN costo (para paginación)
 * - Se consideran "sin costo" cuando (costo IS NULL OR costo <= 0) y stock > 0
 */
public function contarProductosSinCosto(array $filtros = []): int
{
    $where = "activo = 1 AND stock > 0 AND (costo IS NULL OR costo <= 0)";
    $params = [];

    if (!empty($filtros['categoria'])) {
        $where .= " AND categoria = :categoria";
        $params[':categoria'] = $filtros['categoria'];
    }
    if (!empty($filtros['proveedor_id'])) {
        $where .= " AND proveedor_id = :proveedor_id";
        $params[':proveedor_id'] = (int)$filtros['proveedor_id'];
    }
    if (!empty($filtros['busqueda'])) {
        $where .= " AND (nombre LIKE :busq OR codigo LIKE :busq2)";
        $params[':busq'] = '%' . $filtros['busqueda'] . '%';
        $params[':busq2'] = '%' . $filtros['busqueda'] . '%';
    }

    $sql = "SELECT COUNT(*) FROM productos WHERE {$where}";
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->execute();

    return (int)$stmt->fetchColumn();
}

/**
 * Lista productos SIN costo para cargarlo rápido desde Inventario -> Costos
 */
public function getProductosSinCosto(int $limit = 50, array $filtros = []): array
{
    $where = "p.activo = 1 AND p.stock > 0 AND (p.costo IS NULL OR p.costo <= 0)";
    $params = [];

    if (!empty($filtros['categoria'])) {
        $where .= " AND p.categoria = :categoria";
        $params[':categoria'] = $filtros['categoria'];
    }
    if (!empty($filtros['proveedor_id'])) {
        $where .= " AND p.proveedor_id = :proveedor_id";
        $params[':proveedor_id'] = (int)$filtros['proveedor_id'];
    }
    if (!empty($filtros['busqueda'])) {
        $where .= " AND (p.nombre LIKE :busq OR p.codigo LIKE :busq2)";
        $params[':busq'] = '%' . $filtros['busqueda'] . '%';
        $params[':busq2'] = '%' . $filtros['busqueda'] . '%';
    }

    $limitSql = $limit > 0 ? "LIMIT {$limit}" : "";

    $sql = "
        SELECT
            p.id,
            p.codigo,
            p.nombre,
            p.categoria,
            p.stock,
            p.stock_minimo,
            p.costo,
            p.precio,
            p.es_pesable,
            p.unidad_venta,
            COALESCE(pv.nombre, '-') as proveedor
        FROM productos p
        LEFT JOIN proveedores pv ON pv.id = p.proveedor_id
        WHERE {$where}
        ORDER BY (p.stock * p.precio) DESC, p.nombre ASC
        {$limitSql}
    ";

    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Actualiza el costo de un producto (acción rápida desde el tab Costos)
 */
public function actualizarCostoProducto(int $productoId, float $costo): bool
{
    if ($productoId <= 0) return false;

    $stmt = $this->pdo->prepare("UPDATE productos SET costo = :costo WHERE id = :id");
    $stmt->execute([
        ':costo' => $costo,
        ':id' => $productoId,
    ]);

    return $stmt->rowCount() > 0;
}


    /**
     * Obtiene productos "parados" (sin venta en X días) CON FILTROS
     */
    public function getProductosParados(int $dias = 30, int $limit = 25, array $filtros = []): array
    {
        $whereExtra = "";
        $params = [];

        if (!empty($filtros['categoria'])) {
            $whereExtra .= " AND p.categoria = :categoria";
            $params[':categoria'] = $filtros['categoria'];
        }
        if (!empty($filtros['busqueda'])) {
            $whereExtra .= " AND (p.nombre LIKE :busq OR p.codigo LIKE :busq2)";
            $params[':busq'] = '%' . $filtros['busqueda'] . '%';
            $params[':busq2'] = '%' . $filtros['busqueda'] . '%';
        }

        $limitSql = $limit > 0 ? "LIMIT {$limit}" : "";

        $sql = "
            SELECT 
                p.id, 
                p.codigo, 
                p.nombre,
                p.categoria, 
                p.stock, 
                p.precio, 
                p.costo,
                p.es_pesable,
                (p.stock * COALESCE(p.costo, p.precio * 0.6)) as capital_parado,
                (
                    SELECT MAX(v.fecha) 
                    FROM venta_items vi 
                    INNER JOIN ventas v ON v.id = vi.venta_id AND v.estado = 'EMITIDA'
                    WHERE vi.producto_id = p.id
                ) as ultima_venta,
                DATEDIFF(NOW(), COALESCE(
                    (SELECT MAX(v.fecha) 
                     FROM venta_items vi 
                     INNER JOIN ventas v ON v.id = vi.venta_id AND v.estado = 'EMITIDA'
                     WHERE vi.producto_id = p.id),
                    p.fecha_alta
                )) as dias_sin_venta
            FROM productos p
            WHERE p.activo = 1 AND p.stock > 0 {$whereExtra}
            HAVING ultima_venta IS NULL 
               OR ultima_venta < DATE_SUB(NOW(), INTERVAL {$dias} DAY)
            ORDER BY capital_parado DESC
            {$limitSql}
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as &$row) {
            $row['ultima_venta'] = $row['ultima_venta'] ?? 'Nunca';
        }

        return $results;
    }

    /**
     * Cuenta productos parados
     */
    public function contarProductosParados(int $dias = 30): int
    {
        $sql = "
            SELECT COUNT(*) FROM (
                SELECT p.id,
                    (SELECT MAX(v.fecha) 
                     FROM venta_items vi 
                     INNER JOIN ventas v ON v.id = vi.venta_id AND v.estado = 'EMITIDA'
                     WHERE vi.producto_id = p.id) as ultima_venta
                FROM productos p
                WHERE p.activo = 1 AND p.stock > 0
                HAVING ultima_venta IS NULL 
                   OR ultima_venta < DATE_SUB(NOW(), INTERVAL {$dias} DAY)
            ) as parados
        ";
        
        return (int)$this->pdo->query($sql)->fetchColumn();
    }

    /**
     * Obtiene la rotación de productos (últimos X días) - MEJORADO
     */
    public function getRotacion(int $dias = 30, int $limit = 25, string $orden = 'vendidos'): array
    {
        $diasInt = max(1, $dias);
        
        $orderBy = match($orden) {
            'dias_rest' => 'dias_stock_restante ASC',
            'stock' => 'p.stock DESC',
            'nombre' => 'p.nombre ASC',
            default => 'vendidos_30d DESC'
        };

        $limitSql = $limit > 0 ? "LIMIT {$limit}" : "";

        $sql = "
            SELECT
                p.id,
                p.codigo,
                p.nombre,
                p.categoria,
                p.stock,
                p.stock_minimo,
                p.precio,
                p.es_pesable,
                COALESCE(vx.vendidos, 0) as vendidos_30d,
                COALESCE(vx.ingresos, 0) as ingresos_30d,
                ROUND(COALESCE(vx.vendidos, 0) / {$diasInt}, 2) as promedio_diario,
                CASE
                    WHEN COALESCE(vx.vendidos, 0) > 0
                    THEN ROUND(p.stock / (COALESCE(vx.vendidos, 0) / {$diasInt}), 1)
                    ELSE 999
                END as dias_stock_restante,
                CASE
                    WHEN COALESCE(vx.vendidos, 0) >= (p.stock * 2) THEN 'A'
                    WHEN COALESCE(vx.vendidos, 0) >= p.stock THEN 'B'
                    ELSE 'C'
                END as clasificacion_abc
            FROM productos p
            LEFT JOIN (
                SELECT
                    vi.producto_id,
                    SUM(vi.cantidad) as vendidos,
                    SUM(vi.subtotal) as ingresos
                FROM venta_items vi
                INNER JOIN ventas v ON v.id = vi.venta_id
                WHERE v.estado = 'EMITIDA'
                  AND v.fecha >= DATE_SUB(NOW(), INTERVAL {$diasInt} DAY)
                GROUP BY vi.producto_id
            ) vx ON vx.producto_id = p.id
            WHERE p.activo = 1 AND p.stock > 0
            ORDER BY {$orderBy}
            {$limitSql}
        ";

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Análisis ABC de productos (por ventas)
     */
    public function getAnalisisABC(int $dias = 30): array
    {
        $sql = "
            SELECT 
                p.id,
                p.codigo,
                p.nombre,
                p.categoria,
                p.stock,
                p.precio,
                p.costo,
                COALESCE(vx.vendidos, 0) as unidades_vendidas,
                COALESCE(vx.ingresos, 0) as ingresos,
                COALESCE(vx.margen, 0) as margen_generado
            FROM productos p
            LEFT JOIN (
                SELECT
                    vi.producto_id,
                    SUM(vi.cantidad) as vendidos,
                    SUM(vi.subtotal) as ingresos,
                    SUM(vi.subtotal - (vi.cantidad * COALESCE(pr.costo, 0))) as margen
                FROM venta_items vi
                INNER JOIN ventas v ON v.id = vi.venta_id AND v.estado = 'EMITIDA'
                LEFT JOIN productos pr ON pr.id = vi.producto_id
                WHERE v.fecha >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
                GROUP BY vi.producto_id
            ) vx ON vx.producto_id = p.id
            WHERE p.activo = 1
            ORDER BY ingresos DESC
        ";

        $stmt = $this->pdo->query($sql);
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcular totales
        $totalIngresos = array_sum(array_column($productos, 'ingresos'));
        
        // Clasificar ABC
        $acumulado = 0;
        foreach ($productos as &$prod) {
            $porcentaje = $totalIngresos > 0 ? ($prod['ingresos'] / $totalIngresos) * 100 : 0;
            $acumulado += $porcentaje;
            
            if ($acumulado <= 80) {
                $prod['clasificacion'] = 'A';
            } elseif ($acumulado <= 95) {
                $prod['clasificacion'] = 'B';
            } else {
                $prod['clasificacion'] = 'C';
            }
            $prod['porcentaje_ingresos'] = round($porcentaje, 2);
            $prod['acumulado'] = round($acumulado, 2);
        }

        return [
            'productos' => $productos,
            'total_ingresos' => $totalIngresos,
            'resumen' => [
                'A' => count(array_filter($productos, fn($p) => $p['clasificacion'] === 'A')),
                'B' => count(array_filter($productos, fn($p) => $p['clasificacion'] === 'B')),
                'C' => count(array_filter($productos, fn($p) => $p['clasificacion'] === 'C')),
            ]
        ];
    }

    /**
     * Productos próximos a agotarse (proyección basada en ventas)
     */
    public function getProximosAgotarse(int $diasAlerta = 7, int $limit = 20): array
    {
        $sql = "
            SELECT 
                p.id, 
                p.codigo, 
                p.nombre,
                p.categoria, 
                p.stock,
                p.stock_minimo,
                p.precio,
                p.costo,
                p.es_pesable,
                COALESCE(vx.vendidos, 0) as vendidos_30d,
                ROUND(COALESCE(vx.vendidos, 0) / 30, 2) as promedio_diario,
                CASE 
                    WHEN COALESCE(vx.vendidos, 0) > 0 
                    THEN ROUND(p.stock / (COALESCE(vx.vendidos, 0) / 30), 1)
                    ELSE 999
                END as dias_restantes,
                CASE 
                    WHEN COALESCE(vx.vendidos, 0) > 0 
                    THEN ROUND((p.stock_minimo + (COALESCE(vx.vendidos, 0) / 30 * 7)) - p.stock, 1)
                    ELSE 0
                END as cantidad_reponer
            FROM productos p
            LEFT JOIN (
                SELECT 
                    vi.producto_id,
                    SUM(vi.cantidad) as vendidos
                FROM venta_items vi
                INNER JOIN ventas v ON v.id = vi.venta_id AND v.estado = 'EMITIDA'
                WHERE v.fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY vi.producto_id
            ) vx ON vx.producto_id = p.id
            WHERE p.activo = 1 
              AND p.stock > 0
            HAVING dias_restantes <= {$diasAlerta} AND dias_restantes > 0
            ORDER BY dias_restantes ASC
            LIMIT {$limit}
        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene productos con stock bajo mínimo
     */
    public function getStockBajo(int $limit = 0): array
    {
        $limitSql = $limit > 0 ? "LIMIT {$limit}" : "";
        
        $sql = "
            SELECT 
                p.id, 
                p.codigo, 
                p.nombre,
                p.categoria, 
                p.stock,
                p.es_pesable, 
                p.stock_minimo,
                (p.stock_minimo - p.stock) as faltante,
                p.costo, 
                p.precio,
                COALESCE(pv.nombre, '-') as proveedor
            FROM productos p
            LEFT JOIN proveedores pv ON pv.id = p.proveedor_id
            WHERE p.activo = 1 
              AND p.stock <= p.stock_minimo 
              AND p.stock_minimo > 0
            ORDER BY faltante DESC
            {$limitSql}
        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene la inversión agrupada por categoría
     */
    public function getInversionPorCategoria(): array
    {
        $sql = "
            SELECT 
                CASE 
                    WHEN categoria IS NULL OR TRIM(categoria) = '' THEN 'Sin categoría'
                    ELSE categoria 
                END as categoria,
                COUNT(*) as productos,
                COALESCE(SUM(stock), 0) as unidades,
                COALESCE(SUM(stock * COALESCE(costo, 0)), 0) as inversion,
                COALESCE(SUM(stock * precio), 0) as valor_venta
            FROM productos
            WHERE activo = 1 AND stock > 0
            GROUP BY 
                CASE 
                    WHEN categoria IS NULL OR TRIM(categoria) = '' THEN 'Sin categoría'
                    ELSE categoria 
                END
            ORDER BY inversion DESC
        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene el valor del inventario por proveedor
     */
    public function getInversionPorProveedor(): array
    {
        $sql = "
            SELECT 
                COALESCE(pv.nombre, 'Sin proveedor') as proveedor,
                pv.id as proveedor_id,
                COUNT(p.id) as productos,
                COALESCE(SUM(p.stock), 0) as unidades,
                COALESCE(SUM(p.stock * COALESCE(p.costo, 0)), 0) as inversion,
                COALESCE(SUM(p.stock * p.precio), 0) as valor_venta
            FROM productos p
            LEFT JOIN proveedores pv ON pv.id = p.proveedor_id
            WHERE p.activo = 1 AND p.stock > 0
            GROUP BY pv.id, pv.nombre
            ORDER BY inversion DESC
        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene lista de categorías para filtros
     */
    public function getCategorias(): array
    {
        $sql = "
            SELECT DISTINCT 
                CASE 
                    WHEN categoria IS NULL OR TRIM(categoria) = '' THEN 'Sin categoría'
                    ELSE categoria 
                END as categoria
            FROM productos
            WHERE activo = 1
            ORDER BY categoria
        ";
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Obtiene lista de proveedores para filtros
     */
    public function getProveedores(): array
    {
        $sql = "
            SELECT id, nombre
            FROM proveedores
            WHERE activo = 1
            ORDER BY nombre
        ";
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tendencia de ventas por día (últimos X días)
     */
    public function getTendenciaVentas(int $dias = 30): array
    {
        $sql = "
            SELECT 
                DATE(v.fecha) as fecha,
                COUNT(DISTINCT v.id) as cantidad_ventas,
                COALESCE(SUM(v.total), 0) as total_vendido,
                COALESCE(SUM(vi.cantidad), 0) as unidades
            FROM ventas v
            LEFT JOIN venta_items vi ON vi.venta_id = v.id
            WHERE v.estado = 'EMITIDA'
              AND v.fecha >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
            GROUP BY DATE(v.fecha)
            ORDER BY fecha ASC
        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Productos más vendidos del período
     */
    public function getTopVendidos(int $dias = 30, int $limit = 10): array
    {
        $sql = "
            SELECT 
                p.id,
                p.codigo,
                p.nombre,
                p.categoria,
                p.precio,
                p.stock,
                SUM(vi.cantidad) as unidades_vendidas,
                SUM(vi.subtotal) as ingresos,
                COUNT(DISTINCT vi.venta_id) as veces_vendido
            FROM venta_items vi
            INNER JOIN ventas v ON v.id = vi.venta_id AND v.estado = 'EMITIDA'
            INNER JOIN productos p ON p.id = vi.producto_id
            WHERE v.fecha >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
            GROUP BY p.id
            ORDER BY unidades_vendidas DESC
            LIMIT {$limit}
        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Resumen para dashboard rápido
     */
    public function getDashboardRapido(): array
    {
        return [
            'resumen' => $this->getResumenGeneral(),
            'stock_bajo' => count($this->getStockBajo()),
            'proximos_agotarse' => count($this->getProximosAgotarse(7, 100)),
            'parados_30d' => $this->contarProductosParados(30),
            'top_vendidos' => $this->getTopVendidos(7, 5),
        ];
    }

    /**
     * Exportar todos los datos para Excel/CSV
     */
    public function getExportacionCompleta(): array
    {
        return [
            'resumen' => $this->getResumenGeneral(),
            'inversion' => $this->getTopInversion(0), // Todos
            'stock_bajo' => $this->getStockBajo(),
            'parados' => $this->getProductosParados(30, 0),
            'rotacion' => $this->getRotacion(30, 0),
            'categorias' => $this->getInversionPorCategoria(),
            'proveedores' => $this->getInversionPorProveedor(),
        ];
    }
}
