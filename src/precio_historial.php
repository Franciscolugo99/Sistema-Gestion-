<?php
// src/precio_historial.php
declare(strict_types=1);

/**
 * FLUS Historial de Precios y Costos
 * Registro de cambios de precios con herramientas masivas
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/audit_events.php';

// ============================================
// REGISTRO DE CAMBIOS DE PRECIOS
// ============================================

/**
 * Registrar cambio de precio
 */
function precio_registrar_cambio(
    int $productoId,
    float $precioAnterior,
    float $precioNuevo,
    string $tipo = 'VENTA', // VENTA, COSTO
    ?string $motivo = null,
    ?int $userId = null,
    ?PDO $pdoOverride = null
): ?int {
    try {
        $pdo = $pdoOverride ?? getPDO();
        
        // Asegurar que existe la tabla
        precio_ensure_tables($pdo);
        
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        $diferencia = round($precioNuevo - $precioAnterior, 2);
        $diferenciaPct = $precioAnterior > 0 
            ? round((($precioNuevo - $precioAnterior) / $precioAnterior) * 100, 2) 
            : null;
        
        $stmt = $pdo->prepare("
            INSERT INTO producto_precios_hist 
            (producto_id, tipo, precio_anterior, precio_nuevo, diferencia, diferencia_pct, 
             motivo, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $productoId,
            $tipo,
            $precioAnterior,
            $precioNuevo,
            $diferencia,
            $diferenciaPct,
            $motivo,
            $userId,
        ]);
        
        $histId = (int)$pdo->lastInsertId();
        
        // Auditoría
        audit_precio_change($productoId, $precioAnterior, $precioNuevo, $motivo, $pdo);
        
        return $histId;
        
    } catch (Throwable $e) {
        flus_log_error('precio_registrar_cambio failed', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Actualizar precio de producto y registrar historial
 */
function precio_actualizar(
    int $productoId,
    float $precioNuevo,
    string $tipo = 'VENTA',
    ?string $motivo = null
): bool {
    try {
        $pdo = getPDO();
        
        // Obtener precio actual
        $campo = $tipo === 'COSTO' ? 'costo' : 'precio';
        $stmt = $pdo->prepare("SELECT {$campo} FROM productos WHERE id = ?");
        $stmt->execute([$productoId]);
        $precioAnterior = (float)$stmt->fetchColumn();
        
        // Si no hay cambio, no hacer nada
        if (abs($precioAnterior - $precioNuevo) < 0.001) {
            return true;
        }
        
        $pdo->beginTransaction();
        
        // Actualizar producto
        $stmt = $pdo->prepare("UPDATE productos SET {$campo} = ? WHERE id = ?");
        $stmt->execute([$precioNuevo, $productoId]);
        
        // Registrar historial
        precio_registrar_cambio($productoId, $precioAnterior, $precioNuevo, $tipo, $motivo);
        
        $pdo->commit();
        
        return true;
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flus_log_error('precio_actualizar failed', ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Obtener historial de precios de un producto
 */
function precio_get_historial(int $productoId, ?string $tipo = null, int $limit = 50): array {
    try {
        $pdo = getPDO();
        
        $sql = "
            SELECT h.*, u.nombre as user_nombre
            FROM producto_precios_hist h
            LEFT JOIN users u ON h.user_id = u.id
            WHERE h.producto_id = ?
        ";
        
        $params = [$productoId];
        
        if ($tipo) {
            $sql .= " AND h.tipo = ?";
            $params[] = $tipo;
        }
        
        $sql .= " ORDER BY h.created_at DESC LIMIT " . (int)$limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Obtener resumen de cambios de precios en un período
 */
function precio_resumen_cambios(string $desde, string $hasta, ?string $tipo = null): array {
    try {
        $pdo = getPDO();
        
        $sql = "
            SELECT 
                h.producto_id,
                p.codigo,
                p.nombre,
                COUNT(*) as total_cambios,
                MIN(h.precio_anterior) as precio_min,
                MAX(h.precio_nuevo) as precio_max,
                SUM(h.diferencia) as diferencia_acumulada,
                AVG(h.diferencia_pct) as diferencia_pct_promedio
            FROM producto_precios_hist h
            JOIN productos p ON h.producto_id = p.id
            WHERE h.created_at BETWEEN ? AND ?
        ";
        
        $params = [$desde, $hasta];
        
        if ($tipo) {
            $sql .= " AND h.tipo = ?";
            $params[] = $tipo;
        }
        
        $sql .= "
            GROUP BY h.producto_id, p.codigo, p.nombre
            ORDER BY total_cambios DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Throwable $e) {
        return [];
    }
}

// ============================================
// HERRAMIENTAS MASIVAS
// ============================================

/**
 * Aplicar porcentaje de ajuste a múltiples productos
 */
function precio_ajuste_masivo_porcentaje(
    array $productoIds,
    float $porcentaje,
    string $tipo = 'VENTA',
    string $redondeo = 'NINGUNO', // NINGUNO, ENTERO, 5, 10, 50, 100
    ?string $motivo = null
): array {
    $result = [
        'success' => true,
        'actualizados' => 0,
        'errores' => [],
    ];
    
    try {
        $pdo = getPDO();
        $campo = $tipo === 'COSTO' ? 'costo' : 'precio';
        $userId = $_SESSION['user_id'] ?? null;
        
        $pdo->beginTransaction();
        
        foreach ($productoIds as $productoId) {
            $productoId = (int)$productoId;
            
            // Obtener precio actual
            $stmt = $pdo->prepare("SELECT {$campo}, nombre FROM productos WHERE id = ?");
            $stmt->execute([$productoId]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$producto) {
                $result['errores'][] = "Producto #{$productoId} no encontrado";
                continue;
            }
            
            $precioAnterior = (float)$producto[$campo];
            $precioNuevo = $precioAnterior * (1 + ($porcentaje / 100));
            
            // Aplicar redondeo
            $precioNuevo = precio_aplicar_redondeo($precioNuevo, $redondeo);
            
            // Actualizar
            $stmt = $pdo->prepare("UPDATE productos SET {$campo} = ? WHERE id = ?");
            $stmt->execute([$precioNuevo, $productoId]);
            
            // Registrar historial
            precio_registrar_cambio(
                $productoId, 
                $precioAnterior, 
                $precioNuevo, 
                $tipo, 
                $motivo ?? "Ajuste masivo {$porcentaje}%"
            );
            
            $result['actualizados']++;
        }
        
        $pdo->commit();
        
        flus_log_info('Precio ajuste masivo aplicado', [
            'productos' => count($productoIds),
            'porcentaje' => $porcentaje,
            'actualizados' => $result['actualizados'],
        ]);
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['success'] = false;
        $result['errores'][] = $e->getMessage();
    }
    
    return $result;
}

/**
 * Aplicar margen sobre costo
 */
function precio_aplicar_margen(
    array $productoIds,
    float $margen,
    string $redondeo = 'NINGUNO',
    ?string $motivo = null
): array {
    $result = [
        'success' => true,
        'actualizados' => 0,
        'errores' => [],
    ];
    
    try {
        $pdo = getPDO();
        $userId = $_SESSION['user_id'] ?? null;
        
        $pdo->beginTransaction();
        
        foreach ($productoIds as $productoId) {
            $productoId = (int)$productoId;
            
            // Obtener costo y precio actual
            $stmt = $pdo->prepare("SELECT costo, precio, nombre FROM productos WHERE id = ?");
            $stmt->execute([$productoId]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$producto) {
                $result['errores'][] = "Producto #{$productoId} no encontrado";
                continue;
            }
            
            $costo = (float)$producto['costo'];
            $precioAnterior = (float)$producto['precio'];
            
            if ($costo <= 0) {
                $result['errores'][] = "Producto {$producto['nombre']}: sin costo definido";
                continue;
            }
            
            $precioNuevo = $costo * (1 + ($margen / 100));
            $precioNuevo = precio_aplicar_redondeo($precioNuevo, $redondeo);
            
            // Actualizar
            $stmt = $pdo->prepare("UPDATE productos SET precio = ? WHERE id = ?");
            $stmt->execute([$precioNuevo, $productoId]);
            
            // Registrar historial
            precio_registrar_cambio(
                $productoId, 
                $precioAnterior, 
                $precioNuevo, 
                'VENTA', 
                $motivo ?? "Margen sobre costo {$margen}%"
            );
            
            $result['actualizados']++;
        }
        
        $pdo->commit();
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['success'] = false;
        $result['errores'][] = $e->getMessage();
    }
    
    return $result;
}

/**
 * Actualizar precios desde lista (CSV/array)
 */
function precio_actualizar_desde_lista(array $lista, string $tipo = 'VENTA', ?string $motivo = null): array {
    $result = [
        'success' => true,
        'actualizados' => 0,
        'no_encontrados' => 0,
        'sin_cambio' => 0,
        'errores' => [],
    ];
    
    try {
        $pdo = getPDO();
        
        $pdo->beginTransaction();
        
        foreach ($lista as $item) {
            // Puede venir como [codigo => precio] o ['codigo' => x, 'precio' => y]
            if (is_array($item)) {
                $codigo = $item['codigo'] ?? $item[0] ?? null;
                $precio = $item['precio'] ?? $item[1] ?? null;
            } else {
                continue;
            }
            
            if (!$codigo || $precio === null) continue;
            
            $precio = (float)str_replace(['.', ','], ['', '.'], (string)$precio);
            
            // Buscar producto
            $stmt = $pdo->prepare("SELECT id, precio, costo FROM productos WHERE codigo = ?");
            $stmt->execute([$codigo]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$producto) {
                $result['no_encontrados']++;
                continue;
            }
            
            $campo = $tipo === 'COSTO' ? 'costo' : 'precio';
            $precioAnterior = (float)$producto[$campo];
            
            if (abs($precioAnterior - $precio) < 0.001) {
                $result['sin_cambio']++;
                continue;
            }
            
            // Actualizar
            $stmt = $pdo->prepare("UPDATE productos SET {$campo} = ? WHERE id = ?");
            $stmt->execute([$precio, $producto['id']]);
            
            // Registrar historial
            precio_registrar_cambio(
                (int)$producto['id'], 
                $precioAnterior, 
                $precio, 
                $tipo, 
                $motivo ?? "Actualización desde lista"
            );
            
            $result['actualizados']++;
        }
        
        $pdo->commit();
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['success'] = false;
        $result['errores'][] = $e->getMessage();
    }
    
    return $result;
}

/**
 * Aplicar redondeo al precio
 */
function precio_aplicar_redondeo(float $precio, string $redondeo): float {
    switch ($redondeo) {
        case 'ENTERO':
            return round($precio);
        case '5':
            return round($precio / 5) * 5;
        case '10':
            return round($precio / 10) * 10;
        case '50':
            return round($precio / 50) * 50;
        case '100':
            return round($precio / 100) * 100;
        case '990': // Precio psicológico
            return floor($precio / 1000) * 1000 + 990;
        default:
            return round($precio, 2);
    }
}

// ============================================
// ANÁLISIS DE PRECIOS
// ============================================

/**
 * Obtener productos con margen bajo
 */
function precio_productos_margen_bajo(float $margenMinimo = 20, int $limit = 100): array {
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->prepare("
            SELECT 
                p.id, p.codigo, p.nombre, p.precio, p.costo, p.stock,
                CASE 
                    WHEN p.costo > 0 THEN ROUND(((p.precio - p.costo) / p.costo) * 100, 2)
                    ELSE NULL
                END as margen_pct,
                ROUND(p.precio - p.costo, 2) as margen_abs
            FROM productos p
            WHERE p.activo = 1 
              AND p.costo > 0 
              AND ((p.precio - p.costo) / p.costo) * 100 < ?
            ORDER BY margen_pct ASC
            LIMIT ?
        ");
        $stmt->execute([$margenMinimo, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Obtener estadísticas de márgenes
 */
function precio_estadisticas_margenes(): array {
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_productos,
                COUNT(CASE WHEN costo > 0 THEN 1 END) as con_costo,
                AVG(CASE WHEN costo > 0 THEN ((precio - costo) / costo) * 100 END) as margen_promedio,
                MIN(CASE WHEN costo > 0 THEN ((precio - costo) / costo) * 100 END) as margen_minimo,
                MAX(CASE WHEN costo > 0 THEN ((precio - costo) / costo) * 100 END) as margen_maximo,
                COUNT(CASE WHEN costo > 0 AND ((precio - costo) / costo) * 100 < 0 THEN 1 END) as con_perdida,
                COUNT(CASE WHEN costo > 0 AND ((precio - costo) / costo) * 100 < 20 THEN 1 END) as margen_bajo_20
            FROM productos
            WHERE activo = 1
        ");
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_productos' => (int)$stats['total_productos'],
            'con_costo_definido' => (int)$stats['con_costo'],
            'margen_promedio' => round((float)$stats['margen_promedio'], 2),
            'margen_minimo' => round((float)$stats['margen_minimo'], 2),
            'margen_maximo' => round((float)$stats['margen_maximo'], 2),
            'productos_con_perdida' => (int)$stats['con_perdida'],
            'productos_margen_bajo' => (int)$stats['margen_bajo_20'],
        ];
        
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

// ============================================
// TABLAS
// ============================================

/**
 * Validar que existe la tabla versionada de historial
 */
function precio_ensure_tables(PDO $pdo): void {
    $exists = function_exists('flus_table_exists')
        ? (bool)flus_table_exists($pdo, 'producto_precios_hist')
        : false;

    if (!$exists) {
        throw new RuntimeException(
            'Falta la tabla producto_precios_hist. Aplica primero la migracion migrations/007_support_modules_schema.sql.'
        );
    }
}
