<?php
// public/proveedores.php - Módulo de Proveedores FLUS v1.0
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('ver_proveedores');

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
        $st = $pdo->query("SHOW COLUMNS FROM proveedores");
        $cols = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'Field');
    }
    return $cols;
}

function hasColumn(PDO $pdo, string $column): bool {
    return in_array($column, getProveedorColumns($pdo), true);
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

function updateProveedor(PDO $pdo, int $id, array $data): bool {
    $cols = getProveedorColumns($pdo);
    
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
    $st = $pdo->prepare("UPDATE proveedores SET $setSql WHERE id = :id");
    
    return $st->execute($values);
}

function toggleProveedorActivo(PDO $pdo, int $id, int $valor): bool {
    $st = $pdo->prepare("UPDATE proveedores SET activo = ? WHERE id = ?");
    return $st->execute([$valor, $id]);
}

function getProveedorStats(PDO $pdo, int $id): array {
    // Productos vinculados
    $stProd = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE proveedor_id = ?");
    $stProd->execute([$id]);
    $productos = (int)$stProd->fetchColumn();
    
    // Compras
    $stCompras = $pdo->prepare("
        SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as monto
        FROM compras WHERE proveedor_id = ?
    ");
    $stCompras->execute([$id]);
    $compras = $stCompras->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'monto' => 0];
    
    return [
        'productos' => $productos,
        'compras_count' => (int)$compras['total'],
        'compras_monto' => (float)$compras['monto'],
    ];
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
            $success = updateProveedor($pdo, $id, $_POST);
            $flag = $success ? 'updated' : 'error';
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

if ($editId > 0) {
    if (!$canEdit) {
        flus_abort(403, 'No tenés permisos.');
    }
    $editProveedor = getProveedorById($pdo, $editId);
    if ($editProveedor) {
        $editStats = getProveedorStats($pdo, $editId);
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
$stList = $pdo->prepare("
    SELECT p.*, 
           (SELECT COUNT(*) FROM productos WHERE proveedor_id = p.id) as productos_count,
           (SELECT COUNT(*) FROM compras WHERE proveedor_id = p.id) as compras_count
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
        <header class="page-header">
            <div>
                <h1 class="page-title">🏭 Proveedores</h1>
                <p class="page-sub">Gestión de proveedores para compras e inventario.</p>
            </div>

            <div class="page-actions">
                <?php if ($canEdit): ?>
                    <a class="btn btn-secondary" href="<?= h(urlWithProv(['export' => 'csv'])) ?>" title="Exportar a CSV">
                        📥 Exportar
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
                    <div class="stat-item">
                        <span class="stat-value"><?= (int)$editStats['compras_count'] ?></span>
                        <span class="stat-label">Compras</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?= money_ar($editStats['compras_monto']) ?></span>
                        <span class="stat-label">Total comprado</span>
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="post" class="prov-form" id="provForm">
                <?= csrf_field() ?>
                <input type="hidden" name="submit_token" value="<?= bin2hex(random_bytes(8)) ?>">

                <?php if (!empty($editProveedor)): ?>
                    <input type="hidden" name="id" value="<?= (int)$editProveedor['id'] ?>">
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

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php if (!empty($savedFlag)): ?>
    <?php
    $toastMsg = match($savedFlag) {
        'created' => 'Proveedor creado correctamente.',
        'updated' => 'Proveedor actualizado correctamente.',
        'activated' => 'Proveedor activado.',
        'deactivated' => 'Proveedor desactivado.',
        'csrf' => 'Acción bloqueada: token inválido.',
        'duplicate' => 'Ya guardaste este formulario.',
        'error' => 'Ocurrió un error.',
        default => 'Listo.'
    };
    ?>
    <script>
        if (window.showToast) {
            window.showToast(<?= json_encode($toastMsg, JSON_UNESCAPED_UNICODE) ?>);
        }
    </script>
<?php endif; ?>
