<?php
// src/inventario_fisico.php - FLUS Inventario Físico v2.0
declare(strict_types=1);

/**
 * FLUS Inventario Físico v2.0
 * - Sesiones de conteo con filtro por categoría
 * - Registro de conteos por producto
 * - Resumen de diferencias vs stock del sistema
 * - Aplicación de ajustes (movimientos_stock + actualización de productos.stock)
 *
 * @version 2.0.0
 */

if (defined('FLUS_INVENTARIO_FISICO_LOADED')) return;
define('FLUS_INVENTARIO_FISICO_LOADED', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
$auditPath = __DIR__ . '/audit_events.php';
if (is_file($auditPath)) require_once $auditPath;

// ═══════════════════════════════════════════════════════════════════════════════
// TABLAS
// ═══════════════════════════════════════════════════════════════════════════════

function inventario_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function inventario_column_exists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

function inventario_schema_requirements(): array {
    return [
        'inventario_sesiones' => [
            'id',
            'nombre',
            'descripcion',
            'categoria_id',
            'categoria_nombre',
            'estado',
            'created_by',
            'created_at',
            'closed_by',
            'closed_at',
            'cierre_motivo',
            'applied_by',
            'applied_at',
        ],
        'inventario_conteos' => [
            'id',
            'sesion_id',
            'producto_id',
            'cantidad',
            'stock_sistema_snapshot',
            'ubicacion',
            'notas',
            'created_by',
            'created_at',
        ],
    ];
}

function inventario_ensure_tables(?string &$errMsg = null): bool {
    $errMsg = null;

    try {
        $pdo = getPDO();

        foreach (inventario_schema_requirements() as $table => $columns) {
            if (!inventario_table_exists($pdo, $table)) {
                $errMsg = "Falta la tabla {$table}. Ejecuta migrations/007_support_modules_schema.sql y migrations/025_inventario_fisico_schema.sql.";
                return false;
            }

            foreach ($columns as $column) {
                if (!inventario_column_exists($pdo, $table, $column)) {
                    $errMsg = "Falta la columna {$table}.{$column}. Ejecuta migrations/025_inventario_fisico_schema.sql.";
                    return false;
                }
            }
        }

        return true;
    } catch (Throwable $e) {
        $errMsg = $e->getMessage();
        flus_log_error('inventario_ensure_tables failed', ['error' => $e->getMessage()]);
        return false;
    }
}

function inventario_require_schema(): void {
    $errMsg = null;
    if (inventario_ensure_tables($errMsg)) {
        return;
    }

    throw new RuntimeException($errMsg ?: 'Inventario fisico sin esquema compatible.');
}


function inventario_productos_usa_categoria_id(PDO $pdo): bool {
    return inventario_column_exists($pdo, 'productos', 'categoria_id');
}

function inventario_productos_usa_categoria_texto(PDO $pdo): bool {
    return inventario_column_exists($pdo, 'productos', 'categoria');
}

function inventario_normalize_categoria_nombre(?string $value): ?string {
    $value = trim((string)$value);
    return $value !== '' ? $value : null;
}

function inventario_session_category_filters(?array $sesion): array {
    $categoriaId = (int)($sesion['categoria_id'] ?? 0);
    $categoriaNombre = inventario_normalize_categoria_nombre($sesion['categoria_nombre'] ?? null);

    return [
        'categoria_id' => $categoriaId > 0 ? $categoriaId : null,
        'categoria_nombre' => $categoriaNombre,
    ];
}

function inventario_get_categorias_disponibles(): array {
    try {
        $pdo = getPDO();
        $categorias = [];

        if (inventario_table_exists($pdo, 'categorias')) {
            $st = $pdo->query("SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre");
            foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $nombre = inventario_normalize_categoria_nombre($row['nombre'] ?? null);
                if ($nombre === null) {
                    continue;
                }

                $categorias[] = [
                    'token' => 'id:' . (int)$row['id'],
                    'id' => (int)$row['id'],
                    'nombre' => $nombre,
                    'modo' => 'id',
                ];
            }

            if ($categorias) {
                return $categorias;
            }
        }

        if (inventario_productos_usa_categoria_texto($pdo)) {
            $st = $pdo->query("
                SELECT DISTINCT categoria
                FROM productos
                WHERE activo = 1
                  AND categoria IS NOT NULL
                  AND TRIM(categoria) <> ''
                ORDER BY categoria
            ");

            foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $nombreRaw) {
                $nombre = inventario_normalize_categoria_nombre((string)$nombreRaw);
                if ($nombre === null) {
                    continue;
                }

                $categorias[] = [
                    'token' => 'text:' . $nombre,
                    'id' => null,
                    'nombre' => $nombre,
                    'modo' => 'text',
                ];
            }
        }

        return $categorias;
    } catch (Throwable $e) {
        flus_log_error('inventario_get_categorias_disponibles failed', ['error' => $e->getMessage()]);
        return [];
    }
}

function inventario_parse_categoria_token(?string $token): array {
    $token = trim((string)$token);
    if ($token === '') {
        return ['categoria_id' => null, 'categoria_nombre' => null];
    }

    if (str_starts_with($token, 'id:')) {
        $categoriaId = (int)substr($token, 3);
        return [
            'categoria_id' => $categoriaId > 0 ? $categoriaId : null,
            'categoria_nombre' => null,
        ];
    }

    if (str_starts_with($token, 'text:')) {
        return [
            'categoria_id' => null,
            'categoria_nombre' => inventario_normalize_categoria_nombre(substr($token, 5)),
        ];
    }

    return ['categoria_id' => null, 'categoria_nombre' => inventario_normalize_categoria_nombre($token)];
}

function inventario_contar_productos_sesion(?array $sesion): int {
    try {
        $pdo = getPDO();
        $filters = inventario_session_category_filters($sesion);
        $where = ["activo = 1"];
        $params = [];

        if ($filters['categoria_id'] !== null && inventario_productos_usa_categoria_id($pdo)) {
            $where[] = 'categoria_id = ?';
            $params[] = $filters['categoria_id'];
        } elseif ($filters['categoria_nombre'] !== null && inventario_productos_usa_categoria_texto($pdo)) {
            $where[] = 'categoria = ?';
            $params[] = $filters['categoria_nombre'];
        }

        $sql = 'SELECT COUNT(*) FROM productos WHERE ' . implode(' AND ', $where);
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        flus_log_error('inventario_contar_productos_sesion failed', ['error' => $e->getMessage()]);
        return 0;
    }
}

function inventario_audit_safe(string $eventConst, string $entityConst, int $entityId, array $data, ?int $userId = null): void {
    try {
        if (!function_exists('audit_event')) return;
        if (!class_exists('AuditEvents') || !class_exists('AuditEntities')) return;

        $eventKey  = 'AuditEvents::' . $eventConst;
        $entityKey = 'AuditEntities::' . $entityConst;

        if (!defined($eventKey) || !defined($entityKey)) return;

        $event  = constant($eventKey);
        $entity = constant($entityKey);

        audit_event($event, $entity, $entityId, $data, $userId);
    } catch (Throwable $e) {
        // Loguear pero NO interrumpir el flujo principal
        flus_log_error('inventario_audit_safe failed', [
            'error' => $e->getMessage(),
            'event' => $eventConst,
            'entity' => $entityConst,
            'entity_id' => $entityId,
        ]);
    }
}



// ═══════════════════════════════════════════════════════════════════════════════
// SESIONES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Listar sesiones (últimas primero)
 */
function inventario_session_list(int $limit = 50): array {
    try {
        $pdo = getPDO();
        inventario_require_schema();

        // Algunas instalaciones no tienen tabla `categorias` (o la tabla se llama distinto).
        // No debemos romper el listado de sesiones por un LEFT JOIN opcional.
        $hasCategorias = inventario_table_exists($pdo, 'categorias');
        $selectCategoria = $hasCategorias
            ? "COALESCE(NULLIF(TRIM(s.categoria_nombre), ''), c.nombre) AS categoria_nombre"
            : "NULLIF(TRIM(s.categoria_nombre), '') AS categoria_nombre";
        $joinCategoria   = $hasCategorias ? 'LEFT JOIN categorias c ON c.id = s.categoria_id' : '';

        $limit = max(1, min($limit, 200));
        $st = $pdo->prepare("
            SELECT 
                s.*,
                {$selectCategoria},
                (SELECT COUNT(DISTINCT ic.producto_id) FROM inventario_conteos ic WHERE ic.sesion_id = s.id) AS productos_contados
            FROM inventario_sesiones s
            {$joinCategoria}
            ORDER BY s.created_at DESC
            LIMIT {$limit}
        ");
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        flus_log_error('inventario_session_list failed', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Crear nueva sesión de inventario
 * 
 * @param string $nombre Nombre de la sesión
 * @param string|null $descripcion Descripción opcional
 * @param int|null $userId ID del usuario que crea
 * @param int|null $categoriaId Si se especifica, limita el inventario a esta categoría
 * @return int|null ID de la sesión creada o null si falla
 */
function inventario_session_create(
    string $nombre, 
    ?string $descripcion = null, 
    ?int $userId = null,
    ?int $categoriaId = null,
    ?string &$errMsg = null,
    ?string $categoriaNombre = null
): ?int {
    $errMsg = null;

    try {
        $pdo = getPDO();
        inventario_require_schema();

        $nombre = trim($nombre);
        if ($nombre === '') return null;
        $categoriaNombre = inventario_normalize_categoria_nombre($categoriaNombre);

        if ($categoriaNombre === null && $categoriaId > 0 && inventario_table_exists($pdo, 'categorias')) {
            $stCat = $pdo->prepare("SELECT nombre FROM categorias WHERE id = ?");
            $stCat->execute([$categoriaId]);
            $categoriaNombre = inventario_normalize_categoria_nombre((string)$stCat->fetchColumn());
        }

        $st = $pdo->prepare("
            INSERT INTO inventario_sesiones (nombre, descripcion, categoria_id, categoria_nombre, estado, created_by, created_at)
            VALUES (?, ?, ?, ?, 'ABIERTA', ?, NOW())
        ");
        $st->execute([
            $nombre, 
            $descripcion, 
            $categoriaId > 0 ? $categoriaId : null, 
            $categoriaNombre,
            $userId
        ]);

        $id = (int)$pdo->lastInsertId();

        // Verificación dura: evitamos redirigir con IDs fantasma
        if ($id <= 0) return null;
        $chk = $pdo->prepare("SELECT id FROM inventario_sesiones WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) return null;

        inventario_audit_safe('INVENTARIO_SESSION_CREATE', 'INVENTARIO', $id, [
            'nombre' => $nombre,
            'categoria_id' => $categoriaId,
            'categoria_nombre' => $categoriaNombre,
        ], $userId);

        return $id;
    } catch (Throwable $e) {
        $errMsg = $e->getMessage();
        flus_log_error('inventario_session_create failed', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Obtener sesión por ID con resumen
 */
function inventario_session_get(int $sessionId): ?array {
    try {
        $pdo = getPDO();
        inventario_require_schema();

        // `categorias` puede no existir en algunas instalaciones.
        $hasCategorias = inventario_table_exists($pdo, 'categorias');
        $selectCategoria = $hasCategorias
            ? "COALESCE(NULLIF(TRIM(s.categoria_nombre), ''), c.nombre) AS categoria_nombre"
            : "NULLIF(TRIM(s.categoria_nombre), '') AS categoria_nombre";
        $joinCategoria   = $hasCategorias ? 'LEFT JOIN categorias c ON c.id = s.categoria_id' : '';

        $st = $pdo->prepare("
            SELECT s.*, {$selectCategoria}
            FROM inventario_sesiones s
            {$joinCategoria}
            WHERE s.id = ?
        ");
        $st->execute([$sessionId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return null;

        // Adjuntar resumen rápido
        $s['resumen'] = inventario_get_resumen_diferencias($sessionId);
        return $s;
    } catch (Throwable $e) {
        flus_log_error('inventario_session_get failed', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Cerrar sesión (bloquea nuevos conteos)
 */
function inventario_session_close(int $sessionId, ?string $motivo = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        inventario_require_schema();

        if ($sessionId <= 0) return false;

        $st = $pdo->prepare("SELECT estado FROM inventario_sesiones WHERE id = ?");
        $st->execute([$sessionId]);
        $estado = (string)$st->fetchColumn();
        if ($estado !== 'ABIERTA') return false;

        $motivo = $motivo ? trim($motivo) : null;

        $st = $pdo->prepare("
            UPDATE inventario_sesiones
            SET estado='CERRADA', closed_at=NOW(), closed_by=?, cierre_motivo=?
            WHERE id=? AND estado='ABIERTA'
        ");
        $st->execute([$userId, $motivo, $sessionId]);

        $ok = $st->rowCount() > 0;
        if ($ok) {
            inventario_audit_safe('INVENTARIO_SESSION_CLOSE', 'INVENTARIO', $sessionId, [
                'motivo' => $motivo,
            ], $userId);
        }
        return $ok;
    } catch (Throwable $e) {
        flus_log_error('inventario_session_close failed', ['error' => $e->getMessage()]);
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// CONTEOS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Registrar conteo de un producto
 */
function inventario_registrar_conteo(
    int $sessionId,
    int $productoId,
    float $cantidad,
    ?string $ubicacion = null,
    ?string $notas = null,
    ?int $userId = null,
    ?string &$errMsg = null
): ?int {
    $errMsg = null;

    try {
        $pdo = getPDO();
        inventario_require_schema();

        if ($sessionId <= 0 || $productoId <= 0) {
            $errMsg = 'Sesión o producto inválido.';
            return null;
        }

        // Verificar que la sesión esté abierta
        $st = $pdo->prepare("SELECT estado, categoria_id, categoria_nombre FROM inventario_sesiones WHERE id = ?");
        $st->execute([$sessionId]);
        $sesion = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$sesion) {
            $errMsg = 'La sesión no existe.';
            return null;
        }
        if ($sesion['estado'] !== 'ABIERTA') {
            $errMsg = 'La sesión ya no está abierta para nuevos conteos.';
            return null;
        }

        $sessionFilters = inventario_session_category_filters($sesion);
        $productColumns = ['stock'];
        if (inventario_productos_usa_categoria_id($pdo)) {
            $productColumns[] = 'categoria_id';
        }
        if (inventario_productos_usa_categoria_texto($pdo)) {
            $productColumns[] = 'categoria';
        }

        $st = $pdo->prepare('SELECT ' . implode(', ', array_unique($productColumns)) . ' FROM productos WHERE id = ?');
        $st->execute([$productoId]);
        $producto = $st->fetch(PDO::FETCH_ASSOC);
        if (!$producto) {
            $errMsg = 'El producto seleccionado no existe.';
            return null;
        }

        if ($sessionFilters['categoria_id'] !== null && inventario_productos_usa_categoria_id($pdo)) {
            $prodCatId = (int)($producto['categoria_id'] ?? 0);
            if ($prodCatId !== (int)$sessionFilters['categoria_id']) {
                flus_log_error('inventario_registrar_conteo: producto no pertenece a la categoría de la sesión', [
                    'producto_id' => $productoId,
                    'sesion_categoria' => $sessionFilters['categoria_id'],
                    'producto_categoria' => $prodCatId,
                ]);
                $errMsg = 'El producto no pertenece a la categoría de esta sesión.';
                return null;
            }
        } elseif ($sessionFilters['categoria_nombre'] !== null && inventario_productos_usa_categoria_texto($pdo)) {
            $prodCategoria = inventario_normalize_categoria_nombre($producto['categoria'] ?? null);
            if (strcasecmp((string)$prodCategoria, (string)$sessionFilters['categoria_nombre']) !== 0) {
                flus_log_error('inventario_registrar_conteo: producto no pertenece a la categoría textual de la sesión', [
                    'producto_id' => $productoId,
                    'sesion_categoria_nombre' => $sessionFilters['categoria_nombre'],
                    'producto_categoria' => $prodCategoria,
                ]);
                $errMsg = 'El producto no pertenece a la categoría de esta sesión.';
                return null;
            }
        }

        $cantidad = round((float)$cantidad, 3);
        $ubicacion = $ubicacion !== null ? trim($ubicacion) : null;
        $notas = $notas !== null ? trim($notas) : null;
        $stockSistemaSnapshot = round((float)($producto['stock'] ?? 0), 3);

        $st = $pdo->prepare("
            INSERT INTO inventario_conteos (sesion_id, producto_id, cantidad, stock_sistema_snapshot, ubicacion, notas, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $st->execute([$sessionId, $productoId, $cantidad, $stockSistemaSnapshot, $ubicacion, $notas, $userId]);
        $id = (int)$pdo->lastInsertId();

        // Verificación dura: confirmamos que el conteo quedó guardado
        if ($id <= 0) {
            $errMsg = 'No se pudo guardar el conteo.';
            return null;
        }
        $chk = $pdo->prepare("SELECT id FROM inventario_conteos WHERE id = ? AND sesion_id = ?");
        $chk->execute([$id, $sessionId]);
        if (!$chk->fetchColumn()) {
            $errMsg = 'El conteo no quedó persistido correctamente.';
            return null;
        }

        inventario_audit_safe('INVENTARIO_COUNT', 'INVENTARIO', $sessionId, [
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'stock_sistema_snapshot' => $stockSistemaSnapshot,
            'ubicacion' => $ubicacion,
        ], $userId);

        return $id;
    } catch (Throwable $e) {
        $errMsg = $e->getMessage();
        flus_log_error('inventario_registrar_conteo failed', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Obtener conteos con info del producto + diferencia
 *
 * @param int $sessionId ID de la sesión
 * @param bool $soloConDiferencia Si true, filtra donde diferencia != 0
 * @return array Lista de conteos
 */
function inventario_get_conteos(int $sessionId, bool $soloConDiferencia = false): array {
    try {
        $pdo = getPDO();
        inventario_require_schema();

        if ($sessionId <= 0) return [];

        // Tomar el ÚLTIMO conteo por producto (por created_at, id)
        $sql = "
            SELECT
                c.producto_id,
                p.codigo,
                p.nombre,
                p.costo,
                c.cantidad AS cantidad_contada,
                COALESCE(c.stock_sistema_snapshot, p.stock) AS cantidad_sistema,
                p.stock AS cantidad_sistema_actual,
                (c.cantidad - COALESCE(c.stock_sistema_snapshot, p.stock)) AS diferencia,
                c.ubicacion,
                c.notas,
                c.created_at AS fecha_conteo
            FROM inventario_conteos c
            JOIN (
                SELECT producto_id, MAX(id) AS max_id
                FROM inventario_conteos
                WHERE sesion_id = ?
                GROUP BY producto_id
            ) last ON last.producto_id = c.producto_id AND last.max_id = c.id
            JOIN productos p ON p.id = c.producto_id
            WHERE c.sesion_id = ?
        ";

        if ($soloConDiferencia) {
            $sql .= " AND ABS(c.cantidad - COALESCE(c.stock_sistema_snapshot, p.stock)) > 0.001";
        }

        $sql .= " ORDER BY p.nombre ASC";

        $st = $pdo->prepare($sql);
        $st->execute([$sessionId, $sessionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Normalizar números
        foreach ($rows as &$r) {
            $r['producto_id'] = (int)$r['producto_id'];
            $r['cantidad_contada'] = (float)$r['cantidad_contada'];
            $r['cantidad_sistema'] = (float)$r['cantidad_sistema'];
            $r['cantidad_sistema_actual'] = (float)($r['cantidad_sistema_actual'] ?? $r['cantidad_sistema']);
            $r['diferencia'] = (float)$r['diferencia'];
            $r['costo'] = $r['costo'] !== null ? (float)$r['costo'] : null;
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        flus_log_error('inventario_get_conteos failed', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Obtener resumen de diferencias de una sesión
 */
function inventario_get_resumen_diferencias(int $sessionId): array {
    $base = [
        'productos_contados' => 0,
        'productos_con_diferencia' => 0,
        'productos_faltantes' => 0,
        'productos_sobrantes' => 0,
        'valor_diferencia' => 0.0,
    ];

    try {
        $rows = inventario_get_conteos($sessionId, false);
        if (!$rows) return $base;

        $base['productos_contados'] = count($rows);

        $valor = 0.0;
        foreach ($rows as $r) {
            $dif = (float)$r['diferencia'];
            if (abs($dif) < 0.001) continue;

            $base['productos_con_diferencia']++;
            if ($dif < 0) $base['productos_faltantes']++;
            if ($dif > 0) $base['productos_sobrantes']++;

            $costo = $r['costo'] !== null ? (float)$r['costo'] : 0.0;
            $valor += ($dif * $costo);
        }

        $base['valor_diferencia'] = round($valor, 2);
        return $base;

    } catch (Throwable $e) {
        flus_log_error('inventario_get_resumen_diferencias failed', ['error' => $e->getMessage()]);
        return $base;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// BÚSQUEDA Y APLICACIÓN
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Buscar productos para autocompletar (código/nombre)
 * 
 * @param string $q Término de búsqueda
 * @param int $limit Máximo de resultados
 * @param int|null $categoriaId Filtrar por categoría (opcional)
 * @return array Lista de productos
 */
function inventario_buscar_producto(string $q, int $limit = 12, ?int $categoriaId = null, ?string $categoriaNombre = null): array {
    try {
        $pdo = getPDO();
        $q = trim($q);
        if ($q === '') return [];

        $limit = max(1, min($limit, 50));
        $like = '%' . $q . '%';

        $params = [$like, $like];
        $categoriaCond = [];
        if ($categoriaId > 0 && inventario_productos_usa_categoria_id($pdo)) {
            $categoriaCond[] = 'categoria_id = ?';
            $params[] = $categoriaId;
        }

        $categoriaNombre = inventario_normalize_categoria_nombre($categoriaNombre);
        if ($categoriaNombre !== null && inventario_productos_usa_categoria_texto($pdo)) {
            $categoriaCond[] = 'categoria = ?';
            $params[] = $categoriaNombre;
        }

        $params[] = $q;
        $categoriaSql = $categoriaCond ? (' AND ' . implode(' AND ', $categoriaCond)) : '';

        $st = $pdo->prepare("
            SELECT id, codigo, nombre, stock, costo
            FROM productos
            WHERE activo = 1
              AND (codigo LIKE ? OR nombre LIKE ?)
              {$categoriaSql}
            ORDER BY
              CASE WHEN codigo = ? THEN 0 ELSE 1 END,
              nombre ASC
            LIMIT {$limit}
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['stock'] = (float)$r['stock'];
            $r['costo'] = $r['costo'] !== null ? (float)$r['costo'] : null;
        }
        unset($r);
        
        return $rows;
    } catch (Throwable $e) {
        flus_log_error('inventario_buscar_producto failed', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Aplicar ajustes de stock según el último conteo por producto.
 * 
 * @param int $sessionId ID de la sesión
 * @param int|null $userId ID del usuario que aplica
 * @param string|null &$errMsg Mensaje de error (por referencia)
 * @return array|null Array con 'ajustes_realizados' o null si falla
 */
function inventario_aplicar_ajustes(int $sessionId, ?int $userId = null, ?string &$errMsg = null): ?array {
    $errMsg = null;

    try {
        $pdo = getPDO();
        inventario_require_schema();

        // Chequear estado
        $st = $pdo->prepare("SELECT estado FROM inventario_sesiones WHERE id = ?");
        $st->execute([$sessionId]);
        $estado = (string)$st->fetchColumn();
        if ($estado !== 'CERRADA') {
            $errMsg = 'La sesión debe estar CERRADA para aplicar ajustes.';
            return null;
        }

        $conteos = inventario_get_conteos($sessionId, false);
        if (!$conteos) {
            $errMsg = 'No hay conteos para aplicar.';
            return null;
        }

        $pdo->beginTransaction();

        $ajustes = 0;
        $detalles = [];
        
        foreach ($conteos as $c) {
            $productoId = (int)$c['producto_id'];
            $contado = (float)$c['cantidad_contada'];
            $stockBaseSesion = (float)($c['cantidad_sistema'] ?? 0);
            $delta = round($contado - $stockBaseSesion, 3);

            if (abs($delta) < 0.001) {
                continue;
            }

            // Releer stock actual para evitar race condition
            $st = $pdo->prepare("SELECT stock, nombre FROM productos WHERE id = ? FOR UPDATE");
            $st->execute([$productoId]);
            $prod = $st->fetch(PDO::FETCH_ASSOC);
            if (!$prod) continue;

            $stockActual = (float)$prod['stock'];
            $nuevoStock = round($stockActual + $delta, 3);
            if ($nuevoStock < -0.001) {
                throw new RuntimeException('No se puede aplicar la sesión porque el producto "' . ($prod['nombre'] ?? ('#' . $productoId)) . '" tuvo movimientos posteriores incompatibles con el conteo.');
            }
            if ($nuevoStock < 0) {
                $nuevoStock = 0.0;
            }

            $tipo = $delta > 0 ? 'AJUSTE_POSITIVO' : 'AJUSTE_NEGATIVO';
            $cantidadMov = round(abs($delta), 3);

            // Insert movimiento de stock
            $st = $pdo->prepare("
                INSERT INTO movimientos_stock
                (venta_id, fecha, producto_id, tipo, cantidad, referencia_venta_id, referencia_compra_id, comentario, usuario_id)
                VALUES (NULL, NOW(), ?, ?, ?, NULL, NULL, ?, ?)
            ");
            $coment = "Inventario físico (sesión #{$sessionId})";
            $st->execute([$productoId, $tipo, $cantidadMov, $coment, $userId]);

            // Ajustar sobre el stock actual usando la diferencia detectada al contar.
            $st = $pdo->prepare("UPDATE productos SET stock = ?, fecha_modificacion = NOW() WHERE id = ?");
            $st->execute([$nuevoStock, $productoId]);

            $detalles[] = [
                'producto_id' => $productoId,
                'nombre' => $prod['nombre'],
                'stock_sistema_sesion' => $stockBaseSesion,
                'stock_anterior' => $stockActual,
                'stock_nuevo' => $nuevoStock,
                'diferencia' => $delta,
            ];
            
            $ajustes++;
        }

        // Marcar sesión como aplicada
        $st = $pdo->prepare("
            UPDATE inventario_sesiones
            SET estado='APLICADA', applied_at=NOW(), applied_by=?
            WHERE id=? AND estado='CERRADA'
        ");
        $st->execute([$userId, $sessionId]);

        $pdo->commit();

        inventario_audit_safe('INVENTARIO_ADJUST', 'INVENTARIO', $sessionId, [
            'ajustes_realizados' => $ajustes,
            'detalles' => $detalles,
        ], $userId);

        return [
            'ajustes_realizados' => $ajustes,
            'detalles' => $detalles,
        ];

    } catch (Throwable $e) {
        try {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $ignore) {}
        
        $errMsg = $e->getMessage();
        flus_log_error('inventario_aplicar_ajustes failed', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Obtener historial de conteos de un producto en una sesión
 * (Útil para ver cuántas veces se recontó)
 */
function inventario_get_historial_conteos_producto(int $sessionId, int $productoId): array {
    try {
        $pdo = getPDO();
        
        $st = $pdo->prepare("
            SELECT 
                c.id,
                c.cantidad,
                c.ubicacion,
                c.notas,
                c.created_at,
                u.nombre AS usuario
            FROM inventario_conteos c
            LEFT JOIN usuarios u ON u.id = c.created_by
            WHERE c.sesion_id = ? AND c.producto_id = ?
            ORDER BY c.created_at DESC
        ");
        $st->execute([$sessionId, $productoId]);
        
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        flus_log_error('inventario_get_historial_conteos_producto failed', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Obtener estadísticas generales del módulo de inventario
 */
function inventario_get_estadisticas(): array {
    try {
        $pdo = getPDO();
        inventario_require_schema();
        
        $stats = [
            'total_sesiones' => 0,
            'sesiones_abiertas' => 0,
            'sesiones_cerradas' => 0,
            'sesiones_aplicadas' => 0,
            'total_conteos' => 0,
            'ultima_sesion' => null,
        ];
        
        // Contar sesiones por estado
        $st = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'ABIERTA' THEN 1 ELSE 0 END) as abiertas,
                SUM(CASE WHEN estado = 'CERRADA' THEN 1 ELSE 0 END) as cerradas,
                SUM(CASE WHEN estado = 'APLICADA' THEN 1 ELSE 0 END) as aplicadas
            FROM inventario_sesiones
        ");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $stats['total_sesiones'] = (int)$row['total'];
            $stats['sesiones_abiertas'] = (int)$row['abiertas'];
            $stats['sesiones_cerradas'] = (int)$row['cerradas'];
            $stats['sesiones_aplicadas'] = (int)$row['aplicadas'];
        }
        
        // Total conteos
        $stats['total_conteos'] = (int)$pdo->query("SELECT COUNT(*) FROM inventario_conteos")->fetchColumn();
        
        // Última sesión
        $st = $pdo->query("SELECT id, nombre, estado, created_at FROM inventario_sesiones ORDER BY created_at DESC LIMIT 1");
        $stats['ultima_sesion'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        
        return $stats;
    } catch (Throwable $e) {
        flus_log_error('inventario_get_estadisticas failed', ['error' => $e->getMessage()]);
        return [];
    }
}

