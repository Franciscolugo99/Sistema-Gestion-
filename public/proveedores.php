<?php
// public/proveedores.php - Módulo de Proveedores FLUS v1.0
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once FLUS_ROOT . '/src/db_helpers.php';
require_once FLUS_ROOT . '/src/logger.php';

require_login();
require_any_permission(['ver_proveedores','editar_proveedores']);

$pdo = getPDO();
$canEdit = function_exists('user_has_permission') && user_has_permission('editar_proveedores');

/* ========== URL helper ========== */
function urlWithProv(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return 'proveedores.php' . (empty($q) ? '' : '?' . http_build_query($q));
}

$savedFlag = (string)($_GET['saved'] ?? '');
$errores = [];

/* ========== HELPERS ========== */
function validateProveedorForm(array $data): array {
    $errors = [];
    
    $nombre = trim((string)($data['nombre'] ?? ''));
    if ($nombre === '') {
        $errors[] = 'El nombre del proveedor es obligatorio.';
    } elseif (strlen($nombre) > 120) {
        $errors[] = 'El nombre no puede superar 120 caracteres.';
    }
    
    $cuit = trim((string)($data['cuit'] ?? ''));
    if ($cuit !== '' && !preg_match('/^\d{2}-?\d{8}-?\d{1}$/', $cuit)) {
        $errors[] = 'El CUIT tiene un formato inválido.';
    }
    
    $email = trim((string)($data['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El email no es válido.';
    }
    
    return $errors;
}

function getProveedorById(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM proveedores WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Obtiene las columnas disponibles en la tabla proveedores
 * Esto permite compatibilidad antes y después de la migración
 */
function getProveedorColumns(PDO $pdo): array {
    static $cols = null;
    if ($cols === null) {
        $cols = function_exists('flus_table_columns')
            ? flus_table_columns($pdo, 'proveedores')
            : [];
    }
    return $cols;
}

function hasTableColumn(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    return $cache[$key] = flus_column_exists($pdo, $table, $column);
}

function normProveedorName(string $value): string {
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    return $value;
}

function buildProveedorProductoMatchSql(string $aliasProducto, string $aliasProveedor, bool $includeLegacy): string {
    $conditions = ["$aliasProducto.proveedor_id = $aliasProveedor.id"];
    if ($includeLegacy) {
        $conditions[] = "(
            ($aliasProducto.proveedor_id IS NULL OR $aliasProducto.proveedor_id = 0)
            AND TRIM(LOWER(COALESCE($aliasProducto.proveedor, ''))) = TRIM(LOWER($aliasProveedor.nombre))
        )";
    }
    return implode(' OR ', $conditions);
}

function relinkProveedorProducts(PDO $pdo, int $proveedorId, string ...$legacyNames): array {
    $proveedor = getProveedorById($pdo, $proveedorId);
    if (!$proveedor) {
        return ['linked' => 0, 'legacy' => 0, 'warnings' => ['Proveedor no encontrado.']];
    }

    $currentName = normProveedorName((string)($proveedor['nombre'] ?? ''));
    if ($currentName === '') {
        return ['linked' => 0, 'legacy' => 0, 'warnings' => ['El proveedor no tiene nombre normalizable.']];
    }

    $linked = 0;
    $legacy = 0;
    $warnings = [];
    $errors = [];

    try {
        $st = $pdo->prepare("UPDATE productos SET proveedor = :new_name WHERE proveedor_id = :proveedor_id");
        $st->execute([
            ':new_name' => $currentName,
            ':proveedor_id' => $proveedorId,
        ]);
        $linked += $st->rowCount();
    } catch (Throwable $e) {
        $warnings[] = 'No se pudo sincronizar por proveedor_id.';
        flus_log_warn('relink_proveedor proveedor_id fallback', [
            'proveedor_id' => $proveedorId,
            'error' => $e->getMessage(),
        ]);
    }

    $names = [$currentName];
    foreach ($legacyNames as $legacyName) {
        $legacyName = normProveedorName($legacyName);
        if ($legacyName !== '') {
            $names[] = $legacyName;
        }
    }
    $names = array_values(array_unique($names));

    foreach ($names as $legacyName) {
        try {
            $st = $pdo->prepare("
                UPDATE productos
                SET proveedor = :new_name, proveedor_id = :proveedor_id
                WHERE (proveedor_id IS NULL OR proveedor_id = 0)
                  AND TRIM(LOWER(COALESCE(proveedor, ''))) = TRIM(LOWER(:legacy_name))
            ");
            $st->execute([
                ':new_name' => $currentName,
                ':proveedor_id' => $proveedorId,
                ':legacy_name' => $legacyName,
            ]);
            $legacy += $st->rowCount();
            if ($st->rowCount() > 0) {
                $warnings[] = 'Se uso fallback legacy sin proveedor_id.';
            }
            continue;
        } catch (Throwable $e) {
            flus_log_warn('relink_proveedor legacy fallback', [
                'proveedor_id' => $proveedorId,
                'legacy_name' => $legacyName,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $st = $pdo->prepare("
                UPDATE productos
                SET proveedor = :new_name
                WHERE TRIM(LOWER(COALESCE(proveedor, ''))) = TRIM(LOWER(:legacy_name))
            ");
            $st->execute([
                ':new_name' => $currentName,
                ':legacy_name' => $legacyName,
            ]);
            $legacy += $st->rowCount();
        } catch (Throwable $e) {
            $errors[] = 'Fallo la re-vinculacion legacy para "' . $legacyName . '".';
            flus_log_error('relink_proveedor failed', [
                'proveedor_id' => $proveedorId,
                'legacy_name' => $legacyName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return [
        'linked' => $linked,
        'legacy' => $legacy,
        'warnings' => array_values(array_unique($warnings)),
        'errors' => array_values(array_unique($errors)),
    ];
}

function syncProveedorProducts(PDO $pdo, int $proveedorId, string $oldName, string $newName): void {
    if ($proveedorId <= 0 || normProveedorName($newName) === '') {
        return;
    }
    $result = relinkProveedorProducts($pdo, $proveedorId, $oldName, $newName);
    if (($result['errors'] ?? []) !== []) {
        throw new RuntimeException(implode(' ', (array)$result['errors']));
    }
}

function createProveedor(PDO $pdo, array $data): int {
    $cols = getProveedorColumns($pdo);
    
    // Columnas base (siempre existen)
    $fields = ['nombre', 'cuit', 'telefono', 'email', 'direccion', 'activo'];
    $values = [
        ':nombre' => trim((string)($data['nombre'] ?? '')),
        ':cuit' => trim((string)($data['cuit'] ?? '')) ?: null,
        ':telefono' => trim((string)($data['telefono'] ?? '')) ?: null,
        ':email' => trim((string)($data['email'] ?? '')) ?: null,
        ':direccion' => trim((string)($data['direccion'] ?? '')) ?: null,
        ':activo' => isset($data['activo']) ? 1 : 0,
    ];
    
    // Columnas opcionales (pueden no existir antes de migración)
    $optionalCols = [
        'razon_social' => trim((string)($data['razon_social'] ?? '')) ?: null,
        'contacto_nombre' => trim((string)($data['contacto_nombre'] ?? '')) ?: null,
        'whatsapp' => trim((string)($data['whatsapp'] ?? '')) ?: null,
        'ciudad' => trim((string)($data['ciudad'] ?? '')) ?: null,
        'provincia' => trim((string)($data['provincia'] ?? '')) ?: null,
        'dias_pago' => (int)($data['dias_pago'] ?? 0),
        'descuento_habitual' => (float)($data['descuento_habitual'] ?? 0),
        'notas' => trim((string)($data['notas'] ?? '')) ?: null,
    ];
    
    foreach ($optionalCols as $col => $val) {
        if (in_array($col, $cols, true)) {
            $fields[] = $col;
            $values[':' . $col] = $val;
        }
    }
    
    $fieldList = implode(', ', $fields);
    $placeholders = implode(', ', array_map(fn($f) => ':' . $f, $fields));
    
    $st = $pdo->prepare("INSERT INTO proveedores ($fieldList) VALUES ($placeholders)");
    $st->execute($values);
    
    return (int)$pdo->lastInsertId();
}

function updateProveedor(PDO $pdo, int $id, array $data): array {
    $cols = getProveedorColumns($pdo);
    $before = getProveedorById($pdo, $id);
    if (!$before) {
        return ['ok' => false, 'linked' => 0, 'legacy' => 0, 'warnings' => ['Proveedor no encontrado.']];
    }
    
    // Campos base
    $sets = ['nombre = :nombre', 'cuit = :cuit', 'telefono = :telefono', 'email = :email', 'direccion = :direccion', 'activo = :activo'];
    $values = [
        ':id' => $id,
        ':nombre' => trim((string)($data['nombre'] ?? '')),
        ':cuit' => trim((string)($data['cuit'] ?? '')) ?: null,
        ':telefono' => trim((string)($data['telefono'] ?? '')) ?: null,
        ':email' => trim((string)($data['email'] ?? '')) ?: null,
        ':direccion' => trim((string)($data['direccion'] ?? '')) ?: null,
        ':activo' => isset($data['activo']) ? 1 : 0,
    ];
    
    // Campos opcionales
    $optionalCols = [
        'razon_social' => trim((string)($data['razon_social'] ?? '')) ?: null,
        'contacto_nombre' => trim((string)($data['contacto_nombre'] ?? '')) ?: null,
        'whatsapp' => trim((string)($data['whatsapp'] ?? '')) ?: null,
        'ciudad' => trim((string)($data['ciudad'] ?? '')) ?: null,
        'provincia' => trim((string)($data['provincia'] ?? '')) ?: null,
        'dias_pago' => (int)($data['dias_pago'] ?? 0),
        'descuento_habitual' => (float)($data['descuento_habitual'] ?? 0),
        'notas' => trim((string)($data['notas'] ?? '')) ?: null,
    ];
    
    foreach ($optionalCols as $col => $val) {
        if (in_array($col, $cols, true)) {
            $sets[] = "$col = :$col";
            $values[':' . $col] = $val;
        }
    }
    
    $setSql = implode(', ', $sets);
    $ownsTx = !$pdo->inTransaction();

    try {
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        $st = $pdo->prepare("UPDATE proveedores SET $setSql WHERE id = :id");
        if (!$st->execute($values)) {
            throw new RuntimeException('No se pudo actualizar el proveedor.');
        }

        $result = relinkProveedorProducts(
            $pdo,
            $id,
            trim((string)($data['nombre_original'] ?? (string)($before['nombre'] ?? ''))),
            trim((string)($data['nombre'] ?? ''))
        );
        if (($result['errors'] ?? []) !== []) {
            throw new RuntimeException(implode(' ', (array)$result['errors']));
        }

        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return ['ok' => true, 'linked' => (int)($result['linked'] ?? 0), 'legacy' => (int)($result['legacy'] ?? 0), 'warnings' => []];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flus_log_error('proveedor update failed', ['proveedor_id' => $id, 'error' => $e->getMessage()]);
        return ['ok' => false, 'linked' => 0, 'legacy' => 0, 'warnings' => [$e->getMessage()]];
    }
}

function toggleProveedorActivo(PDO $pdo, int $id, int $valor): bool {
    $st = $pdo->prepare("UPDATE proveedores SET activo = ? WHERE id = ?");
    return $st->execute([$valor, $id]);
}

function getProveedorStats(PDO $pdo, int $id): array {
    $proveedor = getProveedorById($pdo, $id);
    $hasProductoProveedor = hasTableColumn($pdo, 'productos', 'proveedor');
    $hasProductoProveedorId = hasTableColumn($pdo, 'productos', 'proveedor_id');

    $productos = 0;
    $legacy = 0;
    if ($proveedor && $hasProductoProveedorId) {
        $sqlProductos = "
            SELECT COUNT(*) FROM productos
            WHERE proveedor_id = :id
        ";
        if ($hasProductoProveedor) {
            $sqlProductos = "
                SELECT COUNT(*) FROM productos
                WHERE proveedor_id = :id
                   OR (
                        (proveedor_id IS NULL OR proveedor_id = 0)
                        AND TRIM(LOWER(COALESCE(proveedor, ''))) = TRIM(LOWER(:nombre))
                   )
            ";
        }
        $stProd = $pdo->prepare($sqlProductos);
        $params = [':id' => $id];
        if ($hasProductoProveedor) {
            $params[':nombre'] = (string)$proveedor['nombre'];
        }
        $stProd->execute($params);
        $productos = (int)$stProd->fetchColumn();
    } elseif ($proveedor && $hasProductoProveedor) {
        $stProd = $pdo->prepare("
            SELECT COUNT(*) FROM productos
            WHERE TRIM(LOWER(COALESCE(proveedor, ''))) = TRIM(LOWER(:nombre))
        ");
        $stProd->execute([':nombre' => (string)$proveedor['nombre']]);
        $productos = (int)$stProd->fetchColumn();
    }

    if ($proveedor && $hasProductoProveedor) {
        if ($hasProductoProveedorId) {
            $stLegacy = $pdo->prepare("
                SELECT COUNT(*) FROM productos
                WHERE (proveedor_id IS NULL OR proveedor_id = 0)
                  AND TRIM(LOWER(COALESCE(proveedor, ''))) = TRIM(LOWER(:nombre))
            ");
            $stLegacy->execute([':nombre' => (string)$proveedor['nombre']]);
            $legacy = (int)$stLegacy->fetchColumn();
        } else {
            $legacy = $productos;
        }
    }
    
    // Compras
    $stCompras = $pdo->prepare("
        SELECT COUNT(*) as total,
               COALESCE(SUM(total), 0) as monto,
               MAX(fecha) as ultima_fecha
        FROM compras WHERE proveedor_id = ?
    ");
    $stCompras->execute([$id]);
    $compras = $stCompras->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'monto' => 0, 'ultima_fecha' => null];

    $ultimaCompra = null;
    if ((int)$compras['total'] > 0) {
        $stUlt = $pdo->prepare("
            SELECT id, fecha, estado, total,
                   COALESCE(tipo_comp, '') AS tipo_comp,
                   COALESCE(nro_comp, '') AS nro_comp
            FROM compras
            WHERE proveedor_id = ?
            ORDER BY fecha DESC, id DESC
            LIMIT 1
        ");
        $stUlt->execute([$id]);
        $ultimaCompra = $stUlt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    return [
        'productos' => $productos,
        'productos_legacy' => $legacy,
        'compras_count' => (int)$compras['total'],
        'compras_monto' => (float)$compras['monto'],
        'ultima_compra_fecha' => $compras['ultima_fecha'] ?? null,
        'ultima_compra' => $ultimaCompra,
    ];
}

function getProveedorDashboardStats(PDO $pdo): array {
    $stats = [
        'total' => 0,
        'activos' => 0,
        'con_compras_30d' => 0,
        'sin_compras_90d' => 0,
        'con_legacy' => 0,
    ];

    try {
        $stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM proveedores")->fetchColumn();
        $stats['activos'] = (int)$pdo->query("SELECT COUNT(*) FROM proveedores WHERE activo = 1")->fetchColumn();
        $stats['con_compras_30d'] = (int)$pdo->query("SELECT COUNT(DISTINCT proveedor_id) FROM compras WHERE proveedor_id IS NOT NULL AND fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
        $stats['sin_compras_90d'] = (int)$pdo->query("
            SELECT COUNT(*) FROM proveedores p
            WHERE NOT EXISTS (
                SELECT 1 FROM compras c
                WHERE c.proveedor_id = p.id
                  AND c.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            )
        ")->fetchColumn();

        if (hasTableColumn($pdo, 'productos', 'proveedor')) {
            $sqlLegacy = hasTableColumn($pdo, 'productos', 'proveedor_id')
                ? "
                    SELECT COUNT(DISTINCT p.id)
                    FROM proveedores p
                    JOIN productos pr
                      ON (pr.proveedor_id IS NULL OR pr.proveedor_id = 0)
                     AND TRIM(LOWER(COALESCE(pr.proveedor, ''))) = TRIM(LOWER(p.nombre))
                "
                : "
                    SELECT COUNT(DISTINCT p.id)
                    FROM proveedores p
                    JOIN productos pr
                      ON TRIM(LOWER(COALESCE(pr.proveedor, ''))) = TRIM(LOWER(p.nombre))
                ";
            $stats['con_legacy'] = (int)$pdo->query($sqlLegacy)->fetchColumn();
        }
    } catch (Throwable $e) {
        // devolver defaults si la instalaci?n es limitada
    }

    return $stats;
}

function getProveedorRecentCompras(PDO $pdo, int $id, int $limit = 8): array {
    if ($id <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 20));
    $sql = "
        SELECT c.id, c.fecha, c.estado, c.total,
               COALESCE(c.tipo_comp, '') AS tipo_comp,
               COALESCE(c.nro_comp, '') AS nro_comp
        FROM compras c
        WHERE c.proveedor_id = :id
        ORDER BY c.fecha DESC, c.id DESC
        LIMIT " . $limit;
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getProveedorProductosResumen(PDO $pdo, int $id, int $limit = 10): array {
    if ($id <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 20));
    $matchSql = buildProveedorProductoMatchSql('pr', 'pv', hasTableColumn($pdo, 'productos', 'proveedor'));
    $sql = "
        SELECT pr.id, pr.codigo, pr.nombre, pr.costo, pr.stock, pr.activo, pr.es_pesable, pr.unidad_venta,
               (
                   SELECT MAX(c.fecha)
                   FROM compra_items ci
                   JOIN compras c ON c.id = ci.compra_id
                   WHERE ci.producto_id = pr.id
                     AND c.proveedor_id = pv.id
               ) AS ultima_compra_fecha
        FROM productos pr
        JOIN proveedores pv ON pv.id = :id
        WHERE " . $matchSql . "
        ORDER BY COALESCE(ultima_compra_fecha, pr.fecha_modificacion, pr.fecha_alta) DESC, pr.nombre ASC
        LIMIT " . $limit;
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getProveedorProductosComprados(PDO $pdo, int $id, int $limit = 10): array {
    if ($id <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 250));
    $sql = "
        SELECT pr.id, pr.codigo, pr.nombre, pr.costo, pr.stock, pr.activo, pr.es_pesable, pr.unidad_venta,
               MAX(c.fecha) AS ultima_compra_fecha,
               COUNT(DISTINCT c.id) AS compras_count,
               MAX(ci.costo_unitario) AS ultimo_costo_compra
        FROM compra_items ci
        JOIN compras c ON c.id = ci.compra_id
        JOIN productos pr ON pr.id = ci.producto_id
        WHERE c.proveedor_id = :id
        GROUP BY pr.id, pr.codigo, pr.nombre, pr.costo, pr.stock, pr.activo, pr.es_pesable, pr.unidad_venta
        ORDER BY MAX(c.fecha) DESC, pr.nombre ASC
        LIMIT " . $limit;
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


function relinkAllProveedorProducts(PDO $pdo): array {
    $summary = [
        'proveedores' => 0,
        'linked' => 0,
        'legacy' => 0,
    ];

    $st = $pdo->query("SELECT id, nombre FROM proveedores ORDER BY nombre ASC");
    $proveedores = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($proveedores as $proveedor) {
        $summary['proveedores']++;
        $result = relinkProveedorProducts($pdo, (int)$proveedor['id'], (string)($proveedor['nombre'] ?? ''));
        $summary['linked'] += (int)($result['linked'] ?? 0);
        $summary['legacy'] += (int)($result['legacy'] ?? 0);
    }

    return $summary;
}

/* ========== EXPORTAR CSV ========== */
if (($_GET['export'] ?? '') === 'csv' && $canEdit) {
    $cols = getProveedorColumns($pdo);
    
    // Construir SELECT dinámico
    $selectCols = ['nombre', 'cuit', 'telefono', 'email', 'direccion', 'activo'];
    $optionalExportCols = ['razon_social', 'contacto_nombre', 'whatsapp', 'ciudad', 'provincia', 'dias_pago', 'descuento_habitual'];
    
    foreach ($optionalExportCols as $col) {
        if (in_array($col, $cols, true)) {
            $selectCols[] = $col;
        }
    }
    
    $selectSql = implode(', ', $selectCols);
    $st = $pdo->query("SELECT $selectSql FROM proveedores ORDER BY nombre ASC");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="proveedores_' . date('Ymd') . '.csv"');
    
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    
    // Headers dinámicos
    $headers = ['Nombre', 'CUIT', 'Teléfono', 'Email', 'Dirección', 'Activo'];
    $headerMap = [
        'razon_social' => 'Razón Social',
        'contacto_nombre' => 'Contacto',
        'whatsapp' => 'WhatsApp',
        'ciudad' => 'Ciudad',
        'provincia' => 'Provincia',
        'dias_pago' => 'Días Pago',
        'descuento_habitual' => 'Descuento %',
    ];
    foreach ($optionalExportCols as $col) {
        if (in_array($col, $cols, true)) {
            $headers[] = $headerMap[$col] ?? $col;
        }
    }
    fputcsv($out, $headers);
    
    foreach ($rows as $r) {
        $row = [
            $r['nombre'] ?? '',
            $r['cuit'] ?? '',
            $r['telefono'] ?? '',
            $r['email'] ?? '',
            $r['direccion'] ?? '',
            ($r['activo'] ?? 0) ? 'Sí' : 'No',
        ];
        foreach ($optionalExportCols as $col) {
            if (in_array($col, $cols, true)) {
                $row[] = $r[$col] ?? '';
            }
        }
        fputcsv($out, $row);
    }
    
    fclose($out);
    exit;
}

/* ========== RE-VINCULAR TODOS LOS PROVEEDORES ========== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['accion'] ?? '') === 'relink_all_productos') {
    if (!$canEdit) {
        flus_abort(403, 'No tenes permisos.');
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        header('Location: ' . urlWithProv(['saved' => 'csrf']));
        exit;
    }

    $summary = relinkAllProveedorProducts($pdo);
    $_SESSION['prov_relink_all_summary'] = $summary;
    $saved = (($summary['linked'] + $summary['legacy']) > 0) ? 'relinked_all' : 'relinked_all_none';
    header('Location: ' . urlWithProv(['saved' => $saved]));
    exit;
}

/* ========== RE-VINCULAR PRODUCTOS ========== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['accion'] ?? '') === 'relink_productos') {
    if (!$canEdit) {
        flus_abort(403, 'No ten?s permisos.');
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        header('Location: ' . urlWithProv(['saved' => 'csrf']));
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $legacyName = trim((string)($_POST['legacy_name'] ?? ''));
    if ($id > 0) {
        $result = relinkProveedorProducts($pdo, $id, $legacyName);
        $saved = (($result['linked'] + $result['legacy']) > 0) ? 'relinked' : 'relinked_none';
        header('Location: ' . urlWithProv([
            'saved' => $saved,
            'editar' => $id,
        ]));
    } else {
        header('Location: ' . urlWithProv(['saved' => 'error']));
    }
    exit;
}

/* ========== TOGGLE ACTIVO ========== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['accion'] ?? '') === 'toggle_activo') {
    if (!$canEdit) {
        flus_abort(403, 'No tenés permisos.');
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        header('Location: ' . urlWithProv(['saved' => 'csrf']));
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $valor = (int)($_POST['valor'] ?? 0);
    
    if ($id > 0) {
        $success = toggleProveedorActivo($pdo, $id, $valor);
        header('Location: ' . urlWithProv([
            'saved' => $success ? ($valor ? 'activated' : 'deactivated') : 'error',
            'page' => $_GET['page'] ?? 1
        ]));
    } else {
        header('Location: ' . urlWithProv(['saved' => 'error']));
    }
    exit;
}

/* ========== CREAR / EDITAR ========== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && empty($_POST['accion'])) {
    if (!$canEdit) {
        flus_abort(403, 'No tenés permisos.');
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errores[] = 'Token inválido (CSRF).';
    }

    // Prevenir doble submit
    $submitToken = (string)($_POST['submit_token'] ?? '');
    $lastToken = (string)($_SESSION['last_submit_token_prov'] ?? '');

    if ($submitToken !== '' && hash_equals($lastToken, $submitToken)) {
        header('Location: ' . urlWithProv(['saved' => 'duplicate']));
        exit;
    }

    $errores = array_merge($errores, validateProveedorForm($_POST));

    if (empty($errores)) {
        $_SESSION['last_submit_token_prov'] = $submitToken !== '' ? $submitToken : bin2hex(random_bytes(16));

        $id = (isset($_POST['id']) && $_POST['id'] !== '') ? (int)$_POST['id'] : null;

        if ($id) {
            $result = updateProveedor($pdo, $id, $_POST);
            if (($result['ok'] ?? false) && (($result['linked'] ?? 0) > 0 || ($result['legacy'] ?? 0) > 0)) {
                $_SESSION['prov_update_summary'] = $result;
            } elseif (($result['warnings'] ?? []) !== []) {
                $_SESSION['prov_update_summary'] = $result;
            }
            $flag = ($result['ok'] ?? false) ? 'updated' : 'error';
        } else {
            $newId = createProveedor($pdo, $_POST);
            $flag = $newId > 0 ? 'created' : 'error';
        }

        header('Location: ' . urlWithProv([
            'saved' => $flag,
            'editar' => null,
            'new' => null
        ]));
        exit;
    }
}

/* ========== CARGAR PROVEEDOR PARA EDICIÓN ========== */
$editProveedor = null;
$editId = (int)($_GET['editar'] ?? 0);
$editStats = null;
$editCompras = [];
$editProductosResumen = [];
$editProductosComprados = [];

if ($editId > 0) {
    if (!$canEdit) {
        flus_abort(403, 'No tenés permisos.');
    }
    $editProveedor = getProveedorById($pdo, $editId);
    if ($editProveedor) {
        $editStats = getProveedorStats($pdo, $editId);
        $editCompras = getProveedorRecentCompras($pdo, $editId, 100);
        $editProductosResumen = getProveedorProductosResumen($pdo, $editId, 200);
        $editProductosComprados = getProveedorProductosComprados($pdo, $editId, 200);
    }
}

/* ========== FILTROS Y LISTADO ========== */
$q = trim((string)($_GET['q'] ?? ''));
if (strlen($q) > 120) $q = substr($q, 0, 120);

$estado = (string)($_GET['estado'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

// Obtener columnas disponibles para formulario
$availableCols = getProveedorColumns($pdo);
$dashboardStats = getProveedorDashboardStats($pdo);

// Construir query
$where = [];
$params = [];

if ($q !== '') {
    // Construir búsqueda dinámica según columnas disponibles
    $searchFields = ['nombre', 'cuit', 'email'];
    if (in_array('contacto_nombre', $availableCols, true)) {
        $searchFields[] = 'contacto_nombre';
    }
    $searchSql = implode(' LIKE :q OR ', $searchFields) . ' LIKE :q';
    $where[] = "($searchSql)";
    $params[':q'] = '%' . $q . '%';
}

if ($estado === 'activo') {
    $where[] = "activo = 1";
} elseif ($estado === 'inactivo') {
    $where[] = "activo = 0";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$stCount = $pdo->prepare("SELECT COUNT(*) FROM proveedores $whereSql");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch proveedores
$productosJoinSql = buildProveedorProductoMatchSql('prd', 'p', hasTableColumn($pdo, 'productos', 'proveedor'));
$legacyProductosSql = hasTableColumn($pdo, 'productos', 'proveedor')
    ? "(SELECT COUNT(*) FROM productos prd WHERE (prd.proveedor_id IS NULL OR prd.proveedor_id = 0) AND TRIM(LOWER(COALESCE(prd.proveedor, ''))) = TRIM(LOWER(p.nombre)))"
    : "0";
$stList = $pdo->prepare("
    SELECT p.*, 
           (SELECT COUNT(*) FROM productos prd WHERE $productosJoinSql) as productos_count,
           $legacyProductosSql as productos_legacy_count,
           (SELECT COUNT(*) FROM compras WHERE proveedor_id = p.id) as compras_count,
           (SELECT MAX(c.fecha) FROM compras c WHERE c.proveedor_id = p.id) as ultima_compra_fecha,
           (SELECT c2.total FROM compras c2 WHERE c2.proveedor_id = p.id ORDER BY c2.fecha DESC, c2.id DESC LIMIT 1) as ultima_compra_total
    FROM proveedores p
    $whereSql
    ORDER BY p.nombre ASC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $k => $v) {
    $stList->bindValue($k, $v);
}
$stList->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stList->bindValue(':offset', $offset, PDO::PARAM_INT);
$stList->execute();
$proveedores = $stList->fetchAll(PDO::FETCH_ASSOC);

/* ========== DRAWER OPEN? ========== */
$isNew = ((string)($_GET['new'] ?? '') === '1');
$drawerOpen = $canEdit && ($isNew || !empty($editProveedor) || !empty($errores));

/* ========== HEADER ========== */
$pageTitle = 'Proveedores';
$currentSection = 'proveedores';
$extraCss = ['assets/css/proveedores.css'];
$extraJs = ['assets/js/proveedores.js'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap proveedores-page">

    <div class="panel prov-panel">
        <header class="page-header module-header">
            <div class="module-header-main">
                <div class="module-header-hero">
                    <span class="module-header-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                            <path d="M3 7h11v8H3z"/>
                            <path d="M14 10h3l4 4v1h-7z"/>
                            <circle cx="7.5" cy="18" r="1.5"/>
                            <circle cx="18" cy="18" r="1.5"/>
                        </svg>
                    </span>
                    <div class="module-header-copy">
                        <span class="module-eyebrow">Abastecimiento externo</span>
                        <h1 class="page-title module-title">Proveedores</h1>
                        <p class="page-sub module-subtitle">Gestion de proveedores para compras e inventario.</p>
                    </div>
                </div>
            </div>

            <div class="page-actions module-header-actions">
                <?php if ($canEdit): ?>
                    <form method="post" class="inline-form relink-all-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="relink_all_productos">
                        <button type="submit" class="btn btn-secondary">Re-vincular todo</button>
                    </form>
                    <a class="btn btn-secondary" href="<?= h(urlWithProv(['export' => 'csv'])) ?>" title="Exportar a CSV">
                        Exportar
                    </a>
                    <a class="btn btn-primary" href="<?= h(urlWithProv(['new' => 1, 'editar' => null])) ?>">
                        + Nuevo proveedor
                    </a>
                <?php else: ?>
                    <span class="tag tag-muted">Solo lectura</span>
                <?php endif; ?>
            </div>
        </header>
    </div>

    <div class="panel prov-list-panel">
        <div class="prov-overview-grid">
            <article class="prov-overview-card">
                <span class="prov-overview-label">Proveedores activos</span>
                <strong class="prov-overview-value"><?= (int)($dashboardStats['activos'] ?? 0) ?></strong>
                <small class="prov-overview-help">de <?= (int)($dashboardStats['total'] ?? 0) ?> totales</small>
            </article>
            <article class="prov-overview-card">
                <span class="prov-overview-label">Con compras 30 dias</span>
                <strong class="prov-overview-value"><?= (int)($dashboardStats['con_compras_30d'] ?? 0) ?></strong>
                <small class="prov-overview-help">actividad reciente</small>
            </article>
            <article class="prov-overview-card">
                <span class="prov-overview-label">Sin compras 90 dias</span>
                <strong class="prov-overview-value"><?= (int)($dashboardStats['sin_compras_90d'] ?? 0) ?></strong>
                <small class="prov-overview-help">para revisar relacion</small>
            </article>
            <article class="prov-overview-card <?= ((int)($dashboardStats['con_legacy'] ?? 0) > 0) ? 'is-warning' : '' ?>">
                <span class="prov-overview-label">Con productos legacy</span>
                <strong class="prov-overview-value"><?= (int)($dashboardStats['con_legacy'] ?? 0) ?></strong>
                <small class="prov-overview-help">pendientes de re-vincular</small>
            </article>
        </div>

        <h2 class="sub-title-page">Listado</h2>

        <form method="get" class="filters">
            <div class="filters-left">
                <input type="search" name="q" placeholder="Buscar por nombre, CUIT, email, contacto..." 
                       value="<?= h($q) ?>" class="search-input">
                
                <select name="estado" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <option value="activo" <?= $estado === 'activo' ? 'selected' : '' ?>>Activos</option>
                    <option value="inactivo" <?= $estado === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
                </select>
                
                <button type="submit" class="btn btn-secondary">Buscar</button>
                
                <?php if ($q !== '' || $estado !== ''): ?>
                    <a href="proveedores.php" class="btn btn-ghost">Limpiar</a>
                <?php endif; ?>
            </div>
            
            <div class="filters-right">
                <span class="results-count"><?= number_format($totalRows) ?> proveedor<?= $totalRows !== 1 ? 'es' : '' ?></span>
            </div>
        </form>

        <?php if (empty($proveedores)): ?>
            <div class="empty-state">
                <div class="empty-icon">🏭</div>
                <p>No hay proveedores<?= ($q || $estado) ? ' con esos filtros' : '' ?>.</p>
                <?php if ($canEdit && !$q && !$estado): ?>
                    <a href="<?= h(urlWithProv(['new' => 1])) ?>" class="btn btn-primary">+ Crear primer proveedor</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="prov-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>CUIT</th>
                            <th>Contacto</th>
                            <th>Teléfono</th>
                            <th class="center">Productos</th>
                            <th class="center">Compras</th>
                            <th>Ultima compra</th>
                            <th class="center">Estado</th>
                            <?php if ($canEdit): ?>
                                <th class="center">Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proveedores as $p): ?>
                            <tr class="<?= $p['activo'] ? '' : 'row-inactive' ?>">
                                <td class="col-nombre">
                                    <strong><?= h($p['nombre']) ?></strong>
                                    <?php if (!empty($p['razon_social'])): ?>
                                        <small class="razon-social"><?= h($p['razon_social']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-cuit mono"><?= h($p['cuit'] ?? '—') ?></td>
                                <td class="col-contacto">
                                    <?= h($p['contacto_nombre'] ?? '—') ?>
                                    <?php if (!empty($p['email'])): ?>
                                        <small class="email-small"><?= h($p['email']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-telefono">
                                    <?php if (!empty($p['telefono'])): ?>
                                        <span><?= h($p['telefono']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($p['whatsapp'])): ?>
                                        <a href="https://wa.me/<?= h(preg_replace('/[^0-9]/', '', $p['whatsapp'])) ?>" 
                                           target="_blank" class="wa-link" title="WhatsApp">💬</a>
                                    <?php endif; ?>
                                </td>
                                <td class="center">
                                    <?php if ($p['productos_count'] > 0): ?>
                                        <span class="badge badge-info"><?= (int)$p['productos_count'] ?></span>
                                        <?php if ((int)($p['productos_legacy_count'] ?? 0) > 0): ?>
                                            <small class="email-small">+<?= (int)$p['productos_legacy_count'] ?> sin vincular</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="center">
                                    <?php if ($p['compras_count'] > 0): ?>
                                        <span class="badge badge-success"><?= (int)$p['compras_count'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-ultima-compra">
                                    <?php if (!empty($p['ultima_compra_fecha'])): ?>
                                        <strong><?= h(date('d/m/Y', strtotime((string)$p['ultima_compra_fecha']))) ?></strong>
                                        <small class="email-small"><?= money_ar((float)($p['ultima_compra_total'] ?? 0)) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Sin compras</span>
                                    <?php endif; ?>
                                </td>
                                <td class="center">
                                    <?php if ($p['activo']): ?>
                                        <span class="status-badge active">Activo</span>
                                    <?php else: ?>
                                        <span class="status-badge inactive">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canEdit): ?>
                                    <td class="col-actions center">
                                        <a href="<?= h(urlWithProv(['editar' => $p['id']])) ?>" 
                                           class="btn-icon" title="Editar">✏️</a>
                                        
                                        <form method="post" class="inline-form toggle-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="accion" value="toggle_activo">
                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                            <input type="hidden" name="valor" value="<?= $p['activo'] ? '0' : '1' ?>">
                                            <button type="submit" class="btn-icon" 
                                                    title="<?= $p['activo'] ? 'Desactivar' : 'Activar' ?>">
                                                <?= $p['activo'] ? '🚫' : '✅' ?>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Paginación -->
        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <a class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= $page <= 1 ? '#' : h(urlWithProv(['page' => $page - 1])) ?>">← Anterior</a>

                <div class="pager-mid">Página <?= (int)$page ?> de <?= (int)$totalPages ?></div>

                <a class="pager-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                   href="<?= $page >= $totalPages ? '#' : h(urlWithProv(['page' => $page + 1])) ?>">Siguiente →</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit): ?>
    <div id="provDrawerOverlay" class="drawer-overlay<?= $drawerOpen ? ' is-open' : '' ?>"></div>

    <aside id="provDrawer" class="drawer<?= $drawerOpen ? ' is-open' : '' ?>" aria-label="Proveedor" aria-hidden="<?= $drawerOpen ? 'false' : 'true' ?>">
        <div class="drawer-header">
            <h3 class="drawer-title"><?= !empty($editProveedor) ? 'Editar proveedor' : 'Nuevo proveedor' ?></h3>
            <button class="drawer-close" id="provDrawerClose" type="button" title="Cerrar">✕</button>
        </div>

        <div class="drawer-body">
            <?php if ($editStats): ?>
                <div class="edit-stats">
                    <div class="stat-item">
                        <span class="stat-value"><?= (int)$editStats['productos'] ?></span>
                        <span class="stat-label">Productos</span>
                    </div>
                    <?php if ((int)($editStats['productos_legacy'] ?? 0) > 0): ?>
                    <div class="stat-item">
                        <span class="stat-value"><?= (int)$editStats['productos_legacy'] ?></span>
                        <span class="stat-label">Sin vincular</span>
                    </div>
                    <?php endif; ?>
                    <div class="stat-item">
                        <span class="stat-value"><?= (int)$editStats['compras_count'] ?></span>
                        <span class="stat-label">Compras</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?= money_ar($editStats['compras_monto']) ?></span>
                        <span class="stat-label">Total comprado</span>
                    </div>
                </div>

                <div class="prov-insight-grid">
                    <article class="prov-insight-card">
                        <span class="prov-insight-label">Ultima compra</span>
                        <strong class="prov-insight-value"><?= !empty($editStats['ultima_compra_fecha']) ? h(date('d/m/Y H:i', strtotime((string)$editStats['ultima_compra_fecha']))) : 'Sin compras' ?></strong>
                        <small class="prov-insight-help"><?php if (!empty($editStats['ultima_compra']['tipo_comp']) || !empty($editStats['ultima_compra']['nro_comp'])): ?><?= h(trim(((string)($editStats['ultima_compra']['tipo_comp'] ?? '')) . ' ' . ((string)($editStats['ultima_compra']['nro_comp'] ?? '')))) ?><?php else: ?>Revision rapida del proveedor<?php endif; ?></small>
                    </article>
                    <article class="prov-insight-card">
                        <span class="prov-insight-label">Monto Ultima compra</span>
                        <strong class="prov-insight-value"><?php if (!empty($editStats['ultima_compra'])): ?><?= money_ar((float)($editStats['ultima_compra']['total'] ?? 0)) ?><?php else: ?>?<?php endif; ?></strong>
                        <small class="prov-insight-help"><?php if (!empty($editStats['ultima_compra']['estado'])): ?>Estado: <?= h((string)$editStats['ultima_compra']['estado']) ?><?php else: ?>Sin historial cargado<?php endif; ?></small>
                    </article>
                </div>
                <form method="post" class="inline-form" style="margin:12px 0 18px 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="relink_productos">
                    <input type="hidden" name="id" value="<?= (int)$editProveedor['id'] ?>">
                    <input type="hidden" name="legacy_name" value="<?= h($editProveedor['nombre'] ?? '') ?>">
                    <button type="submit" class="btn btn-secondary">Re-vincular productos</button>
                </form>
            <?php endif; ?>

            <?php if (!empty($editProveedor)): ?>
                <section class="prov-detail-section">
                    <div class="prov-detail-header">
                        <h4 class="section-title">Ultimas compras</h4>
                        <span class="prov-detail-badge"><?= count($editCompras) ?></span>
                    </div>
                    <?php if (!empty($editCompras)): ?>
                        <div class="prov-mini-list">
                            <?php foreach (array_slice($editCompras, 0, 3) as $compra): ?>
                                <article class="prov-mini-item">
                                    <div>
                                        <strong><?= !empty($compra['tipo_comp']) || !empty($compra['nro_comp']) ? h(trim(((string)$compra['tipo_comp']) . ' ' . ((string)$compra['nro_comp']))) : 'Compra #' . (int)$compra['id'] ?></strong>
                                        <small><?= h(date('d/m/Y H:i', strtotime((string)$compra['fecha']))) ?></small>
                                    </div>
                                    <div class="prov-mini-meta">
                                        <span class="status-badge <?= strtoupper((string)($compra['estado'] ?? '')) === 'CONFIRMADA' ? 'active' : 'inactive' ?>"><?= h((string)($compra['estado'] ?? '')) ?></span>
                                        <strong><?= money_ar((float)($compra['total'] ?? 0)) ?></strong>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($editCompras) > 3): ?>
                            <button type="button" class="btn btn-ghost prov-open-compras-modal" data-open-compras-modal>Ver historial completo</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="prov-empty-note">Todavia no hay compras registradas para este proveedor.</p>
                    <?php endif; ?>
                </section>

                <section class="prov-detail-section">
                    <div class="prov-detail-header">
                        <h4 class="section-title">Productos vinculados</h4>
                        <span class="prov-detail-badge"><?= count($editProductosResumen) ?></span>
                    </div>
                    <?php if (!empty($editProductosResumen)): ?>
                        <div class="prov-mini-list" data-product-preview>
                            <?php foreach (array_slice($editProductosResumen, 0, 3) as $productoProv): ?>
                                <article class="prov-mini-item prov-mini-item-product" data-product-item>
                                    <div>
                                        <strong><?= h((string)($productoProv['codigo'] ?? '-')) ?> - <?= h((string)($productoProv['nombre'] ?? '')) ?></strong>
                                        <small>
                                            Costo <?= money_ar((float)($productoProv['costo'] ?? 0)) ?>
                                            - Stock <?= h(format_stock_con_unidad($productoProv, 'stock', 3)) ?>
                                        </small>
                                    </div>
                                    <div class="prov-mini-meta">
                                        <span class="status-badge <?= ((int)($productoProv['activo'] ?? 0) === 1) ? 'active' : 'inactive' ?>"><?= ((int)($productoProv['activo'] ?? 0) === 1) ? 'Activo' : 'Inactivo' ?></span>
                                        <small><?= !empty($productoProv['ultima_compra_fecha']) ? 'Ult. compra ' . h(date('d/m/Y', strtotime((string)$productoProv['ultima_compra_fecha']))) : 'Sin compra asociada' ?></small>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($editProductosResumen) > 3): ?>
                            <button type="button" class="btn btn-ghost prov-open-products-modal" data-open-products-modal>Ver listado completo</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="prov-empty-note">No hay productos vinculados como proveedor principal.</p>
                    <?php endif; ?>
                </section>
                </section>

                <section class="prov-detail-section">
                    <div class="prov-detail-header">
                        <h4 class="section-title">Productos comprados a este proveedor</h4>
                        <span class="prov-detail-badge"><?= count($editProductosComprados) ?></span>
                    </div>
                    <?php if (!empty($editProductosComprados)): ?>
                        <div class="prov-mini-list">
                            <?php foreach (array_slice($editProductosComprados, 0, 3) as $productoCompra): ?>
                                <article class="prov-mini-item prov-mini-item-product">
                                    <div>
                                        <strong><?= h((string)($productoCompra['codigo'] ?? '-')) ?> - <?= h((string)($productoCompra['nombre'] ?? '')) ?></strong>
                                        <small>
                                            Ult. costo <?= money_ar((float)($productoCompra['ultimo_costo_compra'] ?? $productoCompra['costo'] ?? 0)) ?>
                                            - Stock <?= h(format_stock_con_unidad($productoCompra, 'stock', 3)) ?>
                                        </small>
                                    </div>
                                    <div class="prov-mini-meta">
                                        <span class="status-badge <?= ((int)($productoCompra['activo'] ?? 0) === 1) ? 'active' : 'inactive' ?>"><?= ((int)($productoCompra['activo'] ?? 0) === 1) ? 'Activo' : 'Inactivo' ?></span>
                                        <small>
                                            <?= (int)($productoCompra['compras_count'] ?? 0) ?> compra<?= ((int)($productoCompra['compras_count'] ?? 0) !== 1) ? 's' : '' ?>
                                            <?= !empty($productoCompra['ultima_compra_fecha']) ? '- Ult. compra ' . h(date('d/m/Y', strtotime((string)$productoCompra['ultima_compra_fecha']))) : '' ?>
                                        </small>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($editProductosComprados) > 3): ?>
                            <button type="button" class="btn btn-ghost prov-open-purchased-products-modal" data-open-purchased-products-modal>Ver listado completo</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="prov-empty-note">Todavia no hay productos comprados a este proveedor.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            
            <form method="post" class="prov-form" id="provForm">
                <?= csrf_field() ?>
                <input type="hidden" name="submit_token" value="<?= bin2hex(random_bytes(8)) ?>">

                <?php if (!empty($editProveedor)): ?>
                    <input type="hidden" name="id" value="<?= (int)$editProveedor['id'] ?>">
                    <input type="hidden" name="nombre_original" value="<?= h($editProveedor['nombre'] ?? '') ?>">
                <?php endif; ?>

                <div class="form-section">
                    <h4 class="section-title">Datos principales</h4>
                    
                    <div class="prov-grid">
                        <!-- NOMBRE -->
                        <div class="prov-field prov-field-wide">
                            <label>Nombre <span class="required">*</span></label>
                            <input name="nombre" required maxlength="120"
                                   value="<?= h($editProveedor['nombre'] ?? ($_POST['nombre'] ?? '')) ?>"
                                   placeholder="Ej: Distribuidora Norte">
                        </div>

                        <?php if (in_array('razon_social', $availableCols, true)): ?>
                        <!-- RAZÓN SOCIAL -->
                        <div class="prov-field prov-field-wide">
                            <label>Razón social</label>
                            <input name="razon_social" maxlength="150"
                                   value="<?= h($editProveedor['razon_social'] ?? ($_POST['razon_social'] ?? '')) ?>"
                                   placeholder="Nombre legal completo">
                        </div>
                        <?php endif; ?>

                        <!-- CUIT -->
                        <div class="prov-field">
                            <label>CUIT</label>
                            <input name="cuit" maxlength="13" placeholder="20-12345678-9"
                                   value="<?= h($editProveedor['cuit'] ?? ($_POST['cuit'] ?? '')) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4 class="section-title">Contacto</h4>
                    
                    <div class="prov-grid">
                        <?php if (in_array('contacto_nombre', $availableCols, true)): ?>
                        <!-- CONTACTO NOMBRE -->
                        <div class="prov-field">
                            <label>Persona de contacto</label>
                            <input name="contacto_nombre" maxlength="100"
                                   value="<?= h($editProveedor['contacto_nombre'] ?? ($_POST['contacto_nombre'] ?? '')) ?>"
                                   placeholder="Juan Pérez">
                        </div>
                        <?php endif; ?>

                        <!-- TELÉFONO -->
                        <div class="prov-field">
                            <label>Teléfono</label>
                            <input name="telefono" maxlength="30"
                                   value="<?= h($editProveedor['telefono'] ?? ($_POST['telefono'] ?? '')) ?>"
                                   placeholder="261-4567890">
                        </div>

                        <!-- EMAIL -->
                        <div class="prov-field">
                            <label>Email</label>
                            <input type="email" name="email" maxlength="120"
                                   value="<?= h($editProveedor['email'] ?? ($_POST['email'] ?? '')) ?>"
                                   placeholder="ventas@proveedor.com">
                        </div>

                        <?php if (in_array('whatsapp', $availableCols, true)): ?>
                        <!-- WHATSAPP -->
                        <div class="prov-field">
                            <label>WhatsApp</label>
                            <input name="whatsapp" maxlength="20"
                                   value="<?= h($editProveedor['whatsapp'] ?? ($_POST['whatsapp'] ?? '')) ?>"
                                   placeholder="5492614567890">
                            <small class="field-help">Código país sin + (ej: 549261...)</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-section">
                    <h4 class="section-title">Dirección</h4>
                    
                    <div class="prov-grid">
                        <!-- DIRECCIÓN -->
                        <div class="prov-field prov-field-wide">
                            <label>Dirección</label>
                            <input name="direccion" maxlength="200"
                                   value="<?= h($editProveedor['direccion'] ?? ($_POST['direccion'] ?? '')) ?>"
                                   placeholder="Av. San Martín 1234">
                        </div>

                        <?php if (in_array('ciudad', $availableCols, true)): ?>
                        <!-- CIUDAD -->
                        <div class="prov-field">
                            <label>Ciudad</label>
                            <input name="ciudad" maxlength="100"
                                   value="<?= h($editProveedor['ciudad'] ?? ($_POST['ciudad'] ?? '')) ?>"
                                   placeholder="Mendoza">
                        </div>
                        <?php endif; ?>

                        <?php if (in_array('provincia', $availableCols, true)): ?>
                        <!-- PROVINCIA -->
                        <div class="prov-field">
                            <label>Provincia</label>
                            <input name="provincia" maxlength="100"
                                   value="<?= h($editProveedor['provincia'] ?? ($_POST['provincia'] ?? '')) ?>"
                                   placeholder="Mendoza">
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (in_array('dias_pago', $availableCols, true) || in_array('descuento_habitual', $availableCols, true)): ?>
                <div class="form-section">
                    <h4 class="section-title">Condiciones comerciales</h4>
                    
                    <div class="prov-grid">
                        <?php if (in_array('dias_pago', $availableCols, true)): ?>
                        <!-- DÍAS DE PAGO -->
                        <div class="prov-field">
                            <label>Días de pago</label>
                            <select name="dias_pago">
                                <?php 
                                $diasActual = (int)($editProveedor['dias_pago'] ?? ($_POST['dias_pago'] ?? 0));
                                $diasOpciones = [0 => 'Contado', 7 => '7 días', 15 => '15 días', 30 => '30 días', 45 => '45 días', 60 => '60 días', 90 => '90 días'];
                                foreach ($diasOpciones as $val => $label):
                                ?>
                                    <option value="<?= $val ?>" <?= $diasActual === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <?php if (in_array('descuento_habitual', $availableCols, true)): ?>
                        <!-- DESCUENTO HABITUAL -->
                        <div class="prov-field">
                            <label>Descuento habitual (%)</label>
                            <input type="number" name="descuento_habitual" min="0" max="100" step="0.01"
                                   value="<?= h($editProveedor['descuento_habitual'] ?? ($_POST['descuento_habitual'] ?? '0')) ?>"
                                   placeholder="0.00">
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (in_array('notas', $availableCols, true)): ?>
                <div class="form-section">
                    <h4 class="section-title">Notas</h4>
                    
                    <div class="prov-grid">
                        <!-- NOTAS -->
                        <div class="prov-field prov-field-wide">
                            <textarea name="notas" rows="3" placeholder="Horarios de atención, condiciones especiales, etc."><?= h($editProveedor['notas'] ?? ($_POST['notas'] ?? '')) ?></textarea>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ESTADO -->
                <div class="prov-field prov-field-status">
                    <label class="prov-status-label">Estado</label>
                    <label class="edit-switch">
                        <?php $activoForm = $editProveedor['activo'] ?? ($_POST['activo'] ?? 1); ?>
                        <input type="checkbox" name="activo" <?= ((int)$activoForm) ? 'checked' : '' ?>>
                        <span class="edit-switch-slider"></span>
                        <span class="edit-switch-text">Activo</span>
                    </label>
                </div>

                <div class="prov-actions">
                    <button type="submit" class="btn btn-primary" id="provSubmitBtn">Guardar proveedor</button>
                    <button type="button" class="btn btn-secondary" id="provCancelBtn">Cancelar</button>
                </div>

                <?php if (!empty($errores)): ?>
                    <div class="prov-form-errors">
                        <ul>
                            <?php foreach ($errores as $e): ?>
                                <li><?= h($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </aside>
<?php endif; ?>


<?php if ($canEdit && !empty($editProveedor) && !empty($editCompras)): ?>
    <div id="provComprasModalOverlay" class="prov-compras-modal-overlay" hidden></div>
    <div id="provComprasModal" class="prov-compras-modal" role="dialog" aria-modal="true" aria-hidden="true" hidden>
        <div class="prov-compras-modal-card">
            <div class="prov-compras-modal-header">
                <div>
                    <h3>Historial de compras del proveedor</h3>
                    <p><?= h($editProveedor['nombre'] ?? '') ?> - <?= count($editCompras) ?> compra<?= count($editCompras) !== 1 ? 's' : '' ?></p>
                </div>
                <button type="button" class="prov-compras-modal-close" data-close-compras-modal aria-label="Cerrar">&times;</button>
            </div>
            <div class="prov-compras-modal-body">
                <div class="prov-compras-modal-list">
                    <?php foreach ($editCompras as $compra): ?>
                        <article class="prov-compras-modal-item">
                            <div>
                                <strong><?= !empty($compra['tipo_comp']) || !empty($compra['nro_comp']) ? h(trim(((string)$compra['tipo_comp']) . ' ' . ((string)$compra['nro_comp']))) : 'Compra #' . (int)$compra['id'] ?></strong>
                                <small><?= h(date('d/m/Y H:i', strtotime((string)$compra['fecha']))) ?></small>
                            </div>
                            <div class="prov-mini-meta">
                                <span class="status-badge <?= strtoupper((string)($compra['estado'] ?? '')) === 'CONFIRMADA' ? 'active' : 'inactive' ?>"><?= h((string)($compra['estado'] ?? '')) ?></span>
                                <strong><?= money_ar((float)($compra['total'] ?? 0)) ?></strong>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($canEdit && !empty($editProveedor) && !empty($editProductosResumen)): ?>
    <div id="provProductsModalOverlay" class="prov-products-modal-overlay" hidden></div>
    <div id="provProductsModal" class="prov-products-modal" role="dialog" aria-modal="true" aria-hidden="true" hidden>
        <div class="prov-products-modal-card">
            <div class="prov-products-modal-header">
                <div>
                    <h3>Todos los productos del proveedor</h3>
                    <p><?= h($editProveedor['nombre'] ?? '') ?> - <?= count($editProductosResumen) ?> producto<?= count($editProductosResumen) !== 1 ? 's' : '' ?></p>
                </div>
                <button type="button" class="prov-products-modal-close" data-close-products-modal aria-label="Cerrar">&times;</button>
            </div>
            <div class="prov-products-modal-body">
                <div class="prov-products-modal-list">
                    <?php foreach ($editProductosResumen as $productoProv): ?>
                        <article class="prov-products-modal-item">
                            <div>
                                <strong><?= h((string)($productoProv['codigo'] ?? '-')) ?> - <?= h((string)($productoProv['nombre'] ?? '')) ?></strong>
                                <small>
                                    Costo <?= money_ar((float)($productoProv['costo'] ?? 0)) ?>
                                    - Stock <?= h(format_stock_con_unidad($productoProv, 'stock', 3)) ?>
                                </small>
                            </div>
                            <div class="prov-mini-meta">
                                <span class="status-badge <?= ((int)($productoProv['activo'] ?? 0) === 1) ? 'active' : 'inactive' ?>"><?= ((int)($productoProv['activo'] ?? 0) === 1) ? 'Activo' : 'Inactivo' ?></span>
                                <small><?= !empty($productoProv['ultima_compra_fecha']) ? 'Ult. compra ' . h(date('d/m/Y', strtotime((string)$productoProv['ultima_compra_fecha']))) : 'Sin compra asociada' ?></small>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>


<?php if ($canEdit && !empty($editProveedor) && !empty($editProductosComprados)): ?>
    <div id="provPurchasedProductsModalOverlay" class="prov-products-modal-overlay" hidden></div>
    <div id="provPurchasedProductsModal" class="prov-products-modal" role="dialog" aria-modal="true" aria-hidden="true" hidden>
        <div class="prov-products-modal-card">
            <div class="prov-products-modal-header">
                <div>
                    <h3>Productos comprados a este proveedor</h3>
                    <p><?= h($editProveedor['nombre'] ?? '') ?> - <?= count($editProductosComprados) ?> producto<?= count($editProductosComprados) !== 1 ? 's' : '' ?></p>
                </div>
                <button type="button" class="prov-products-modal-close" data-close-purchased-products-modal aria-label="Cerrar">&times;</button>
            </div>
            <div class="prov-products-modal-body">
                <div class="prov-products-modal-list">
                    <?php foreach ($editProductosComprados as $productoCompra): ?>
                        <article class="prov-products-modal-item">
                            <div>
                                <strong><?= h((string)($productoCompra['codigo'] ?? '-')) ?> - <?= h((string)($productoCompra['nombre'] ?? '')) ?></strong>
                                <small>
                                    Ult. costo <?= money_ar((float)($productoCompra['ultimo_costo_compra'] ?? $productoCompra['costo'] ?? 0)) ?>
                                    - Stock <?= h(format_stock_con_unidad($productoCompra, 'stock', 3)) ?>
                                </small>
                            </div>
                            <div class="prov-mini-meta">
                                <span class="status-badge <?= ((int)($productoCompra['activo'] ?? 0) === 1) ? 'active' : 'inactive' ?>"><?= ((int)($productoCompra['activo'] ?? 0) === 1) ? 'Activo' : 'Inactivo' ?></span>
                                <small>
                                    <?= (int)($productoCompra['compras_count'] ?? 0) ?> compra<?= ((int)($productoCompra['compras_count'] ?? 0) !== 1) ? 's' : '' ?>
                                    <?= !empty($productoCompra['ultima_compra_fecha']) ? '- Ult. compra ' . h(date('d/m/Y', strtotime((string)$productoCompra['ultima_compra_fecha']))) : '' ?>
                                </small>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php if (!empty($savedFlag)): ?>
    <?php
    $relinkAllSummary = $_SESSION['prov_relink_all_summary'] ?? null;
    $updateSummary = $_SESSION['prov_update_summary'] ?? null;
    unset($_SESSION['prov_relink_all_summary']);
    unset($_SESSION['prov_update_summary']);
    $toastMsg = match($savedFlag) {
        'created' => 'Proveedor creado correctamente.',
        'updated' => 'Proveedor actualizado correctamente.',
        'activated' => 'Proveedor activado.',
        'deactivated' => 'Proveedor desactivado.',
        'relinked' => 'Productos re-vinculados correctamente.',
        'relinked_none' => 'No habia productos para re-vincular con ese nombre.',
        'relinked_all' => 'Re-vinculacion global completada.',
        'relinked_all_none' => 'No se encontraron productos pendientes para re-vincular.',
        'csrf' => 'Accion bloqueada: token invalido.',
        'duplicate' => 'Ya guardaste este formulario.',
        'error' => 'Ocurrio un error.',
        default => 'Listo.'
    };
    if (($savedFlag === 'relinked_all' || $savedFlag === 'relinked_all_none') && is_array($relinkAllSummary)) {
        $toastMsg .= ' Proveedores: ' . (int)($relinkAllSummary['proveedores'] ?? 0)
            . ' | vinculados: ' . (int)($relinkAllSummary['linked'] ?? 0)
            . ' | legacy: ' . (int)($relinkAllSummary['legacy'] ?? 0);
    }
    if (($savedFlag === 'updated' || $savedFlag === 'error') && is_array($updateSummary)) {
        $linked = (int)($updateSummary['linked'] ?? 0);
        $legacy = (int)($updateSummary['legacy'] ?? 0);
        if ($linked > 0 || $legacy > 0) {
            $toastMsg .= ' Vinculados: ' . $linked . ' | legacy: ' . $legacy;
        }
        if (($updateSummary['warnings'] ?? []) !== []) {
            $toastMsg .= ' ' . implode(' ', array_map('strval', (array)$updateSummary['warnings']));
        }
    }
    ?>
    <script>
        if (window.showToast) {
            window.showToast(<?= json_encode($toastMsg, JSON_UNESCAPED_UNICODE) ?>);
        }
    </script>
<?php endif; ?>
