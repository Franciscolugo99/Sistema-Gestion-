<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');

$roleId = (int)($_GET['id'] ?? 0);
if ($roleId <= 0) {
    http_response_code(400);
    flus_abort(400, 'ID de rol invalido.');
}

$stmt = $pdo->prepare('SELECT id, nombre, slug FROM roles WHERE id = ? LIMIT 1');
$stmt->execute([$roleId]);
$role = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$role) {
    http_response_code(404);
    flus_abort(404, 'Rol no encontrado.');
}

$isReservedAdminRole = flus_is_reserved_admin_role_id($pdo, $roleId);

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($isReservedAdminRole) {
        $_SESSION['flash_error'] = 'El rol base de la cuenta admin de resguardo no permite editar sus permisos.';
        header('Location: rol_permisos.php?id=' . $roleId);
        exit;
    }

    $csrf = (string)($_POST['csrf_token'] ?? null);
    if (!csrf_verify($csrf)) {
        $_SESSION['flash_error'] = 'CSRF invalido. Recarga la pagina e intenta de nuevo.';
        header('Location: rol_permisos.php?id=' . $roleId);
        exit;
    }

    $selected = $_POST['perms'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }

    $permIds = array_values(array_unique(array_filter(array_map('intval', $selected), static fn(int $v): bool => $v > 0)));

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM role_permission WHERE role_id = ?')->execute([$roleId]);

        if ($permIds !== []) {
            $ins = $pdo->prepare('INSERT INTO role_permission (role_id, permission_id) VALUES (?, ?)');
            foreach ($permIds as $permissionId) {
                $ins->execute([$roleId, $permissionId]);
            }
        }

        $pdo->commit();
        $_SESSION['flash_success'] = 'Permisos actualizados correctamente.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = 'Error al guardar permisos: ' . $e->getMessage();
    }

    header('Location: rol_permisos.php?id=' . $roleId);
    exit;
}

$st = $pdo->prepare(
    'SELECT p.id, p.nombre, p.slug, (rp.permission_id IS NOT NULL) AS enabled
     FROM permissions p
     LEFT JOIN role_permission rp ON rp.permission_id = p.id AND rp.role_id = :rid
     ORDER BY p.slug ASC'
);
$st->execute(['rid' => $roleId]);
$perms = $st->fetchAll(PDO::FETCH_ASSOC);

$stmtUsers = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = ? AND activo = 1');
$stmtUsers->execute([$roleId]);
$usuariosActivos = (int)$stmtUsers->fetchColumn();

$categoryConfig = [
    'caja' => ['name' => 'Caja y ventas', 'icon' => 'CV', 'desc' => 'Venta diaria, caja y tickets.', 'tone' => 'green'],
    'clientes' => ['name' => 'Clientes y CC', 'icon' => 'CC', 'desc' => 'Clientes, fiado, pagos y credito.', 'tone' => 'pink'],
    'catalogo' => ['name' => 'Catalogo y precios', 'icon' => 'CP', 'desc' => 'Productos, precios y promos.', 'tone' => 'cyan'],
    'inventario' => ['name' => 'Inventario y compras', 'icon' => 'IN', 'desc' => 'Stock, compras, conteo y reposicion.', 'tone' => 'orange'],
    'facturacion' => ['name' => 'Facturacion', 'icon' => 'FC', 'desc' => 'Comprobantes fiscales y emision.', 'tone' => 'blue'],
    'reportes' => ['name' => 'Reportes y control', 'icon' => 'RP', 'desc' => 'Dashboard, historicos y analisis.', 'tone' => 'violet'],
    'admin' => ['name' => 'Administracion y soporte', 'icon' => 'AD', 'desc' => 'Usuarios, config, backups y tecnico.', 'tone' => 'slate'],
    'general' => ['name' => 'General', 'icon' => 'GN', 'desc' => 'Catalogo base.', 'tone' => 'slate'],
];

$levelConfig = [
    'consulta' => ['label' => 'Consulta', 'class' => 'info'],
    'operativo' => ['label' => 'Operativo', 'class' => 'success'],
    'sensible' => ['label' => 'Sensible', 'class' => 'warning'],
    'admin' => ['label' => 'Admin', 'class' => 'danger'],
];

$stateConfig = [
    'ok' => ['label' => 'En uso', 'class' => 'ok'],
    'partial' => ['label' => 'Impacto especial', 'class' => 'warning'],
    'limited' => ['label' => 'Sin pantalla propia', 'class' => 'muted'],
    'legacy' => ['label' => 'Legado', 'class' => 'muted'],
];

$permissionMeta = [
    'realizar_ventas' => ['category' => 'caja', 'summary' => 'Usa Caja y registra ventas.', 'impact' => 'Abre caja.php.', 'level' => 'operativo'],
    'cerrar_caja' => ['category' => 'caja', 'summary' => 'Cierra turno y declara saldo.', 'impact' => 'Abre caja_cerrar.php.', 'level' => 'sensible'],
    'abrir_caja' => ['category' => 'caja', 'summary' => 'Permite abrir una caja en la terminal actual.', 'impact' => 'Sirve para la apertura; vender sigue yendo por realizar_ventas.', 'level' => 'operativo'],
    'caja_modificar_precio' => ['category' => 'caja', 'summary' => 'Permite tocar precios al cobrar.', 'impact' => 'Cambia importes en mostrador.', 'level' => 'sensible'],
    'anular_venta' => ['category' => 'caja', 'summary' => 'Permite anular ventas.', 'impact' => 'Afecta ventas, stock y CC.', 'level' => 'admin'],
    'anular_items_venta' => ['category' => 'caja', 'summary' => 'Permite devoluciones parciales por ítems.', 'impact' => 'Reversa stock y puede ajustar cuenta corriente.', 'level' => 'sensible'],
    'ver_clientes' => ['category' => 'clientes', 'summary' => 'Consulta padron de clientes.', 'impact' => 'Abre clientes.php.', 'level' => 'consulta'],
    'editar_clientes' => ['category' => 'clientes', 'summary' => 'Edita datos de clientes.', 'impact' => 'Permite alta y edicion.', 'level' => 'sensible'],
    'ver_cuenta_corriente' => ['category' => 'clientes', 'summary' => 'Consulta saldos y movimientos.', 'impact' => 'Abre cuenta corriente.', 'level' => 'consulta'],
    'registrar_cargo_cc' => ['category' => 'clientes', 'summary' => 'Vende fiado.', 'impact' => 'Genera cargos en CC.', 'level' => 'sensible'],
    'registrar_pago_cc' => ['category' => 'clientes', 'summary' => 'Registra pagos de CC.', 'impact' => 'Permite cobrar deuda.', 'level' => 'sensible'],
    'ajustar_cc' => ['category' => 'clientes', 'summary' => 'Ajusta saldos manualmente.', 'impact' => 'Modifica deuda sin venta real.', 'level' => 'admin'],
    'habilitar_cc' => ['category' => 'clientes', 'summary' => 'Habilita o corta credito.', 'impact' => 'Cambia politica comercial.', 'level' => 'admin'],
    'vender_excedido_cc' => ['category' => 'clientes', 'summary' => 'Vende por arriba del limite.', 'impact' => 'Levanta bloqueo de credito.', 'level' => 'admin'],
    'anular_movimiento_cc' => ['category' => 'clientes', 'summary' => 'Anula movimientos de CC.', 'impact' => 'Reversa pagos o cargos.', 'level' => 'admin'],
    'recalcular_saldo_cc' => ['category' => 'clientes', 'summary' => 'Recalcula saldos.', 'impact' => 'Accion de soporte.', 'level' => 'admin'],
    'editar_productos' => ['category' => 'catalogo', 'summary' => 'Edita catalogo y precios.', 'impact' => 'Abre productos y precios.', 'level' => 'sensible'],
    'ver_productos' => ['category' => 'catalogo', 'summary' => 'Consulta el catalogo sin editar.', 'impact' => 'Abre productos_consulta.php.', 'level' => 'consulta'],
    'editar_promos' => ['category' => 'catalogo', 'summary' => 'Edita promociones.', 'impact' => 'Abre promos y combos.', 'level' => 'sensible'],
    'ver_costos' => ['category' => 'catalogo', 'summary' => 'Ve costos y margenes.', 'impact' => 'Expone informacion sensible.', 'level' => 'sensible'],
    'editar_stock' => ['category' => 'inventario', 'summary' => 'Opera stock, compras y conteo.', 'impact' => 'Abre stock, compras y conteo.', 'level' => 'sensible'],
    'ver_stock' => ['category' => 'inventario', 'summary' => 'Consulta stock sin editar.', 'impact' => 'Abre stock_consulta.php y ya no habilita Analisis.', 'level' => 'consulta'],
    'gestionar_stock' => ['category' => 'inventario', 'summary' => 'Acciones avanzadas de stock.', 'impact' => 'Mas amplio que operar stock diario.', 'level' => 'admin'],
    'ver_movimientos' => ['category' => 'inventario', 'summary' => 'Consulta kardex.', 'impact' => 'Abre movimientos.php.', 'level' => 'consulta'],
    'ver_proveedores' => ['category' => 'inventario', 'summary' => 'Consulta proveedores.', 'impact' => 'Abre proveedores.', 'level' => 'consulta'],
    'editar_proveedores' => ['category' => 'inventario', 'summary' => 'Edita proveedores.', 'impact' => 'Alta y edicion de proveedores.', 'level' => 'sensible'],
    'ver_facturacion' => ['category' => 'facturacion', 'summary' => 'Consulta comprobantes.', 'impact' => 'Abre facturacion.', 'level' => 'consulta'],
    'emitir_factura' => ['category' => 'facturacion', 'summary' => 'Emite comprobantes.', 'impact' => 'Abre emision fiscal.', 'level' => 'sensible'],
    'ver_reportes' => ['category' => 'reportes', 'summary' => 'Ve dashboard, ventas e informes.', 'impact' => 'Tambien abre analisis de inventario.', 'level' => 'sensible'],
    'ver_historial_caja' => ['category' => 'reportes', 'summary' => 'Consulta cierres historicos.', 'impact' => 'Abre historial de caja.', 'level' => 'sensible'],
    'ver_auditoria' => ['category' => 'reportes', 'summary' => 'Consulta auditoria.', 'impact' => 'Expone actividad sensible.', 'level' => 'admin'],
    'administrar_usuarios' => ['category' => 'admin', 'summary' => 'Gestiona usuarios y roles.', 'impact' => 'Abre usuarios y este panel.', 'level' => 'admin'],
    'administrar_config' => ['category' => 'admin', 'summary' => 'Cambia configuracion general.', 'impact' => 'Abre config, licencia y terminales.', 'level' => 'admin'],
    'gestionar_backups' => ['category' => 'admin', 'summary' => 'Gestiona backups.', 'impact' => 'Permite restaurar base.', 'level' => 'admin'],
    'ver_diagnostico' => ['category' => 'admin', 'summary' => 'Usa diagnostico.', 'impact' => 'Abre chequeos tecnicos.', 'level' => 'admin'],
];

$moduleRules = [
    ['label' => 'Caja', 'tone' => 'green', 'any' => ['realizar_ventas', 'abrir_caja']],
    ['label' => 'Cierre de caja', 'tone' => 'green', 'any' => ['cerrar_caja']],
    ['label' => 'Clientes', 'tone' => 'pink', 'any' => ['ver_clientes', 'editar_clientes']],
    ['label' => 'Cuenta corriente', 'tone' => 'pink', 'any' => ['ver_cuenta_corriente']],
    ['label' => 'Facturacion', 'tone' => 'blue', 'any' => ['ver_facturacion', 'emitir_factura']],
    ['label' => 'Compras', 'tone' => 'orange', 'any' => ['editar_stock']],
    ['label' => 'Stock', 'tone' => 'orange', 'any' => ['editar_stock', 'ver_stock']],
    ['label' => 'Conteo fisico', 'tone' => 'orange', 'any' => ['editar_stock']],
    ['label' => 'Reposicion', 'tone' => 'orange', 'any' => ['ver_reportes', 'editar_stock']],
    ['label' => 'Analisis de inventario', 'tone' => 'orange', 'any' => ['ver_reportes', 'editar_stock']],
    ['label' => 'Dashboard', 'tone' => 'blue', 'any' => ['ver_reportes']],
    ['label' => 'Ventas reportadas', 'tone' => 'blue', 'any' => ['ver_reportes']],
    ['label' => 'Productos', 'tone' => 'cyan', 'any' => ['editar_productos', 'ver_productos']],
    ['label' => 'Precios', 'tone' => 'cyan', 'any' => ['editar_productos']],
    ['label' => 'Promociones', 'tone' => 'cyan', 'any' => ['editar_promos']],
    ['label' => 'Movimientos', 'tone' => 'violet', 'any' => ['ver_movimientos']],
    ['label' => 'Proveedores', 'tone' => 'violet', 'any' => ['ver_proveedores', 'editar_proveedores']],
    ['label' => 'Historial de caja', 'tone' => 'slate', 'any' => ['ver_historial_caja']],
    ['label' => 'Administracion', 'tone' => 'slate', 'any' => ['administrar_usuarios', 'administrar_config', 'ver_auditoria', 'gestionar_backups', 'ver_diagnostico']],
];

foreach ($perms as &$perm) {
    $meta = $permissionMeta[$perm['slug']] ?? [];
    $perm['category'] = $meta['category'] ?? 'general';
    $perm['summary'] = $meta['summary'] ?? 'Permiso disponible en el catalogo actual.';
    $perm['impact'] = $meta['impact'] ?? 'Revisa el modulo asociado para validar el alcance.';
    $perm['level'] = $meta['level'] ?? 'consulta';
    $perm['state'] = $meta['state'] ?? 'ok';
    $perm['note'] = $meta['note'] ?? '';
}
unset($perm);

$grouped = [];
$enabledSlugs = [];
$countByLevel = ['consulta' => 0, 'operativo' => 0, 'sensible' => 0, 'admin' => 0];

foreach ($perms as $perm) {
    $grouped[$perm['category']][] = $perm;
    if ((int)$perm['enabled'] === 1) {
        $enabledSlugs[] = (string)$perm['slug'];
        $countByLevel[$perm['level']]++;
    }
}

$orderedGroups = [];
foreach (array_keys($categoryConfig) as $categoryKey) {
    if (!isset($grouped[$categoryKey])) {
        continue;
    }
    usort($grouped[$categoryKey], static function (array $a, array $b): int {
        $order = ['operativo' => 1, 'consulta' => 2, 'sensible' => 3, 'admin' => 4];
        $ao = $order[$a['level']] ?? 99;
        $bo = $order[$b['level']] ?? 99;
        return $ao === $bo ? strcmp((string)$a['nombre'], (string)$b['nombre']) : ($ao <=> $bo);
    });
    $orderedGroups[$categoryKey] = $grouped[$categoryKey];
}

$totalPermisos = count($perms);
$permisosActivos = count($enabledSlugs);
$porcentaje = $totalPermisos > 0 ? (int)round(($permisosActivos / $totalPermisos) * 100) : 0;

$initialPreview = [];
foreach ($moduleRules as $rule) {
    foreach ($rule['any'] as $requiredSlug) {
        if (in_array($requiredSlug, $enabledSlugs, true)) {
            $initialPreview[] = $rule;
            break;
        }
    }
}

$pageTitle = 'Permisos: ' . $role['nombre'];
$currentSection = 'roles';
$extraCss = ['assets/css/roles.css?v=3.3'];
$extraJs = ['assets/js/rol_permisos.js?v=3.2'];

require __DIR__ . '/partials/header.php';
?>
<div class="roles-page permisos-page">
    <div class="roles-panel">
        <header class="page-header">
            <div class="page-header-left">
                <nav class="breadcrumb">
                    <a href="roles.php" class="breadcrumb-link">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        Roles
                    </a>
                    <svg class="breadcrumb-sep" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    <span class="breadcrumb-current">Permisos</span>
                </nav>
                <h1 class="page-title">Permisos del rol</h1>
                <div class="page-meta">
                    <span class="role-badge"><strong><?= h((string)$role['nombre']) ?></strong><code><?= h((string)$role['slug']) ?></code></span>
                    <?php if ($usuariosActivos > 0): ?>
                        <span class="users-badge"><?= $usuariosActivos ?> usuario<?= $usuariosActivos !== 1 ? 's' : '' ?> activo<?= $usuariosActivos !== 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
                <p class="permisos-intro">
                    Esta vista ordena los permisos por impacto real en FLUS y marca cuales son solo de consulta, cuales operan el negocio y cuales son sensibles.
                </p>
            </div>
            <div class="page-actions"><a href="roles.php" class="btn btn-ghost">Volver</a></div>
        </header>

        <?php if ($flashSuccess): ?><div class="alert alert-success"><?= h((string)$flashSuccess) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="alert alert-error"><?= h((string)$flashError) ?></div><?php endif; ?>
        <?php if ($usuariosActivos > 0): ?><div class="alert alert-warning">Los cambios impactan de inmediato en <?= $usuariosActivos ?> usuario<?= $usuariosActivos !== 1 ? 's' : '' ?> con este rol.</div><?php endif; ?>
        <?php if ($isReservedAdminRole): ?><div class="alert alert-warning">Este es el rol base de la cuenta admin de resguardo. Se puede revisar, pero no modificar sus permisos desde esta pantalla.</div><?php endif; ?>

        <section class="permisos-top-grid">
            <article class="perm-summary-card">
                <span class="perm-summary-kicker">Resumen</span>
                <div class="perm-summary-number" id="totalSelected"><?= $permisosActivos ?></div>
                <p class="perm-summary-copy">permisos activos sobre <?= $totalPermisos ?></p>
                <span class="perm-summary-percent" id="porcentajeText"><?= $porcentaje ?>% del total</span>
            </article>
            <article class="perm-summary-card">
                <span class="perm-summary-kicker">Niveles activos</span>
                <div class="perm-level-breakdown">
                    <?php foreach ($countByLevel as $level => $count): $levelMeta = $levelConfig[$level]; ?>
                        <span class="risk-chip risk-chip--<?= h((string)$levelMeta['class']) ?>" data-level-count="<?= h((string)$level) ?>"><?= h((string)$levelMeta['label']) ?>: <strong><?= $count ?></strong></span>
                    <?php endforeach; ?>
                </div>
                <p class="perm-summary-note">Consulta y operativo suelen ser seguros. Sensible y admin requieren revision extra.</p>
            </article>
            <article class="perm-summary-card perm-summary-card--wide">
                <span class="perm-summary-kicker">Vista previa de accesos</span>
                <p class="perm-summary-note">Lo que este rol va a ver habilitado en navegacion o modulos clave.</p>
                <div class="access-preview" id="accessPreview">
                    <?php if ($initialPreview === []): ?>
                        <span class="preview-empty">Este rol no habilita ningun modulo visible todavia.</span>
                    <?php else: ?>
                        <?php foreach ($initialPreview as $rule): ?>
                            <span class="preview-chip preview-chip--<?= h((string)$rule['tone']) ?>"><?= h((string)$rule['label']) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>
        </section>

        <section class="permisos-toolbar">
            <div class="toolbar-left">
                <div class="search-wrap">
                    <svg class="search-icon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input type="text" id="permisosSearch" class="search-input" placeholder="Buscar por permiso, modulo o impacto...">
                </div>
            </div>
            <div class="toolbar-right">
                <button type="button" class="btn btn-ghost btn-sm" onclick="filterLevel('all')">Todos</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="filterLevel('operativo')">Operativos</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="filterLevel('consulta')">Consulta</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="filterLevel('sensible')">Sensibles</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="filterLevel('admin')">Admin</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="expandAll()">Abrir</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="collapseAll()">Cerrar</button>
            </div>
        </section>

        <form method="post" id="permisosForm">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div class="live-notes" id="livePermissionNotes"></div>
            <div class="permisos-container">
                <?php foreach ($orderedGroups as $categoryKey => $permisosCategoria): ?>
                    <?php
                    $catConfig = $categoryConfig[$categoryKey] ?? $categoryConfig['general'];
                    $catChecked = array_reduce($permisosCategoria, static fn(int $carry, array $perm): int => $carry + (int)$perm['enabled'], 0);
                    ?>
                    <section class="permisos-category permisos-category--<?= h((string)$catConfig['tone']) ?>" data-categoria="<?= h((string)$categoryKey) ?>">
                        <header class="category-header" onclick="toggleCategory(this)">
                            <div class="category-info">
                                <span class="category-icon category-icon--<?= h((string)$catConfig['tone']) ?>"><?= h((string)$catConfig['icon']) ?></span>
                                <div class="category-text">
                                    <h3 class="category-name"><?= h((string)$catConfig['name']) ?></h3>
                                    <p class="category-desc"><?= h((string)$catConfig['desc']) ?></p>
                                </div>
                            </div>
                            <div class="category-meta">
                                <span class="category-count"><span class="count-selected"><?= $catChecked ?></span> / <?= count($permisosCategoria) ?></span>
                                <svg class="category-arrow" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                            </div>
                        </header>

                        <div class="category-body">
                            <div class="permisos-grid">
                                <?php foreach ($permisosCategoria as $perm): ?>
                                    <?php
                                    $levelMeta = $levelConfig[$perm['level']] ?? $levelConfig['consulta'];
                                    $stateMeta = $stateConfig[$perm['state']] ?? $stateConfig['ok'];
                                    $searchBlob = strtolower(implode(' ', [
                                        (string)$perm['nombre'],
                                        (string)$perm['slug'],
                                        (string)$perm['summary'],
                                        (string)$perm['impact'],
                                        (string)$catConfig['name'],
                                    ]));
                                    ?>
                                            <label class="permiso-item <?= (int)$perm['enabled'] === 1 ? 'is-active' : '' ?><?= $isReservedAdminRole ? ' is-readonly' : '' ?>" data-permiso="<?= h($searchBlob) ?>" data-level="<?= h((string)$perm['level']) ?>" data-slug="<?= h((string)$perm['slug']) ?>">
                                                <input type="checkbox" name="perms[]" value="<?= (int)$perm['id'] ?>" class="permiso-check" data-slug="<?= h((string)$perm['slug']) ?>" data-level="<?= h((string)$perm['level']) ?>" <?= (int)$perm['enabled'] === 1 ? 'checked' : '' ?> <?= $isReservedAdminRole ? 'disabled' : '' ?>>
                                        <span class="permiso-indicator">
                                            <svg class="check-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                        </span>
                                        <span class="permiso-text">
                                            <span class="permiso-head">
                                                <span class="permiso-nombre"><?= h((string)$perm['nombre']) ?></span>
                                                <span class="risk-chip risk-chip--<?= h((string)$levelMeta['class']) ?>"><?= h((string)$levelMeta['label']) ?></span>
                                            </span>
                                            <span class="permiso-summary"><?= h((string)$perm['summary']) ?></span>
                                            <span class="permiso-impact"><?= h((string)$perm['impact']) ?></span>
                                            <span class="permiso-meta-row">
                                                <code class="permiso-slug"><?= h((string)$perm['slug']) ?></code>
                                                <?php if (($perm['state'] ?? 'ok') !== 'ok'): ?>
                                                    <span class="state-chip state-chip--<?= h((string)$stateMeta['class']) ?>"><?= h((string)$stateMeta['label']) ?></span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if (($perm['note'] ?? '') !== ''): ?>
                                                <span class="permiso-note"><?= h((string)$perm['note']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>

                <?php if ($orderedGroups === []): ?>
                    <div class="empty-state">
                        <div class="empty-icon">RL</div>
                        <h3>No hay permisos disponibles</h3>
                        <p>No se encontraron permisos configurados en el sistema.</p>
                    </div>
                <?php endif; ?>
            </div>
            <footer class="permisos-footer">
                <div class="footer-info">
                    <span class="footer-stat"><span id="footerSelectedLabel"><?= $permisosActivos ?></span> permisos seleccionados</span>
                    <span class="footer-divider">|</span>
                    <span class="footer-stat">Revisa especialmente los permisos marcados como <strong>Sensible</strong> o <strong>Admin</strong>.</span>
                </div>
                <div class="footer-actions">
                    <a href="roles.php" class="btn btn-ghost">Cancelar</a>
                    <?php if ($isReservedAdminRole): ?>
                        <button type="button" class="btn btn-ghost" disabled>Permisos bloqueados</button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary">Guardar permisos</button>
                    <?php endif; ?>
                </div>
            </footer>
        </form>
    </div>
</div>

<script id="rolePermissionPreviewRules" type="application/json"><?= json_encode($moduleRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<?php require __DIR__ . '/partials/footer.php'; ?>
