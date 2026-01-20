<?php
// public/clientes.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ClienteController.php';

require_login();
require_permission('ver_clientes');

$pdo = getPDO();
$controller = new ClienteController($pdo);
$canEditClientes = function_exists('user_has_permission') && user_has_permission('editar_clientes');

/* ========== URL helper ========== */
function urlWithCli(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return 'clientes.php' . (empty($q) ? '' : '?' . http_build_query($q));
}

$savedFlag = (string)($_GET['saved'] ?? '');
$errores = [];

/* ========== EXPORTAR CSV ========== */
if (($_GET['export'] ?? '') === 'csv' && $canEditClientes) {
    $controller->exportarCSV($_GET);
    exit;
}

/* ========== TOGGLE ACTIVO ========== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['accion'] ?? '') === 'toggle_activo') {
    if (!$canEditClientes) {
        http_response_code(403);
        die('No tenés permisos.');
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        header('Location: ' . urlWithCli(['saved' => 'csrf']));
        exit;
    }

    $valor = (int)($_POST['valor'] ?? 0);
    $success = $controller->toggleActivo($_POST);

    header('Location: ' . urlWithCli([
        'saved' => $success ? ($valor ? 'activated' : 'deactivated') : 'error',
        'page'  => $_GET['page'] ?? 1
    ]));
    exit;
}

/* ========== CREAR / EDITAR ========== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && empty($_POST['accion'])) {
    if (!$canEditClientes) {
        http_response_code(403);
        die('No tenés permisos.');
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errores[] = 'Token inválido (CSRF).';
    }

    // Prevenir doble submit (doble click / reintentos del navegador)
    // Nota: en PHP la sesión suele bloquear por request, así que esto funciona bien incluso con doble click rápido.
    $submitToken = (string)($_POST['submit_token'] ?? '');
    $lastToken   = (string)($_SESSION['last_submit_token'] ?? '');

    if ($submitToken !== '' && hash_equals($lastToken, $submitToken)) {
        header('Location: ' . urlWithCli(['saved' => 'duplicate']));
        exit;
    }

    $errores = array_merge($errores, $controller->validateForm($_POST));

    if (empty($errores)) {
        // Guardamos el token REAL enviado, así el segundo submit idéntico se detecta.
        // (Antes se guardaba un random nuevo y no bloqueaba duplicados.)
        $_SESSION['last_submit_token'] = $submitToken !== '' ? $submitToken : bin2hex(random_bytes(16));

        $id = (isset($_POST['id']) && $_POST['id'] !== '') ? (int)$_POST['id'] : null;

        if ($id) {
            $success = $controller->update($id, $_POST);
            $flag = $success ? 'updated' : 'error';
        } else {
            $success = $controller->create($_POST);
            $flag = $success ? 'created' : 'error';
        }

        header('Location: ' . urlWithCli([
            'saved'  => $flag,
            'editar' => null,
            'new'    => null
        ]));
        exit;
    }
}

/* ========== CARGAR CLIENTE PARA EDICIÓN ========== */
$editCliente = null;
$editId = (int)($_GET['editar'] ?? 0);

if ($editId > 0) {
    if (!$canEditClientes) {
        http_response_code(403);
        die('No tenés permisos.');
    }
    $editCliente = $controller->getById($editId);
}

/* ========== FILTROS Y LISTADO ========== */
$q = trim((string)($_GET['q'] ?? ''));
if (strlen($q) > 120) $q = substr($q, 0, 120);

$estado  = (string)($_GET['estado'] ?? '');
$tipo    = (string)($_GET['tipo'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

$listData = $controller->getList([
    'q'        => $q,
    'estado'   => $estado,
    'tipo'     => $tipo,
    'per_page' => $perPage,
    'page'     => $page,
]);

$clientes   = $listData['clientes'];
$totalRows  = $listData['totalRows'];
$totalPages = $listData['totalPages'];
$page       = $listData['currentPage'];

$condIvaOptions = ClienteController::getCondIvaOptions();
$tipoOptions = ClienteController::getTipoClienteOptions();
$zonasReparto = $controller->getZonasReparto();

/* ========== DRAWER OPEN? ========== */
$isNew = ((string)($_GET['new'] ?? '') === '1');
$drawerOpen = $canEditClientes && ($isNew || !empty($editCliente) || !empty($errores));

/* ========== HEADER ========== */
$pageTitle = 'Clientes';
$currentSection = 'clientes';
$extraCss = ['assets/css/clientes.css',];
$extraJs = ['assets/js/clientes.js'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap clientes-page">

    <div class="panel cli-panel">
        <header class="page-header">
            <div>
                <h1 class="page-title">Clientes</h1>
                <p class="page-sub">Gestión de clientes para facturación y ventas.</p>
            </div>

            <div class="page-actions">
                <?php if ($canEditClientes): ?>
                    <a class="btn btn-secondary" href="<?= h(urlWithCli(['export' => 'csv'])) ?>" title="Exportar a CSV">
                        📥 Exportar
                    </a>
                    <a class="btn btn-primary" href="<?= h(urlWithCli(['new' => 1, 'editar' => null])) ?>">
                        + Nuevo cliente
                    </a>
                <?php else: ?>
                    <span class="tag tag-muted">Solo lectura</span>
                <?php endif; ?>
            </div>
        </header>
    </div>

    <div class="panel cli-list-panel">
        <h2 class="sub-title-page">Listado</h2>

        <form method="get" class="filters">
            <div class="filters-left">
                <input type="search" name="q" placeholder="Buscar por nombre, CUIT, email..." 
                       value="<?= h($q) ?>" class="search-input">
                
                <select name="tipo" onchange="this.form.submit()">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($tipoOptions as $val => $label): ?>
                        <option value="<?= h($val) ?>" <?= $tipo === $val ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="estado" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <option value="activos" <?= $estado === 'activos' ? 'selected' : '' ?>>Activos</option>
                    <option value="inactivos" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                </select>
            </div>

            <div class="filters-right">
                <select name="per_page" onchange="this.form.submit()">
                    <option value="20" <?= $perPage === 20 ? 'selected' : '' ?>>20 por página</option>
                    <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50 por página</option>
                    <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100 por página</option>
                </select>
            </div>
        </form>

        <div class="table-wrap">
            <table class="table cli-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>CUIT</th>
                        <th>Tipo</th>
                        <th>Cond. IVA</th>
                        <th>Contacto</th>
                        <th>Desc.</th>
                        <th>Estado</th>
                        <th class="center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$clientes): ?>
                        <tr>
                            <td colspan="8" class="empty-cell">No se encontraron clientes.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clientes as $c): ?>
                            <?php
                            $cond = (string)($c['cond_iva'] ?? '');
                            $condLabel = $condIvaOptions[$cond] ?? $cond;
                            $tipoLabel = $tipoOptions[$c['tipo_cliente'] ?? 'MINORISTA'] ?? 'Minorista';
                            $descuento = (float)($c['descuento_porcentaje'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= h($c['nombre'] ?? '') ?></strong>
                                    <?php if (!empty($c['zona_reparto'])): ?>
                                        <div class="muted small">📍 <?= h($c['zona_reparto']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="mono"><?= h($c['cuit'] ?? '—') ?></td>
                                <td>
                                    <span class="tag tag-<?= strtolower($c['tipo_cliente'] ?? 'minorista') ?>">
                                        <?= h($tipoLabel) ?>
                                    </span>
                                </td>
                                <td><?= h($condLabel) ?></td>
                                <td>
                                    <?php if (!empty($c['email'])): ?><div><?= h($c['email']) ?></div><?php endif; ?>
                                    <?php if (!empty($c['telefono'])): ?><div class="muted"><?= h($c['telefono']) ?></div><?php endif; ?>
                                </td>
                                <td class="center">
                                    <?php if ($descuento > 0): ?>
                                        <span class="tag tag-descuento"><?= number_format($descuento, 0) ?>%</span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)($c['activo'] ?? 0) === 1): ?>
                                        <span class="tag tag-ok">Activo</span>
                                    <?php else: ?>
                                        <span class="tag tag-inactivo">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="center">
                                    <?php if ($canEditClientes): ?>
                                        <a class="btn-mini" href="<?= h(urlWithCli(['editar' => (int)$c['id'], 'new' => null])) ?>">Editar</a>

                                        <?php if ((int)($c['activo'] ?? 0) === 1): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('¿Desactivar?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="accion" value="toggle_activo">
                                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                                <input type="hidden" name="valor" value="0">
                                                <button type="submit" class="btn-mini btn-mini-ghost">Desactivar</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('¿Activar?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="accion" value="toggle_activo">
                                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                                <input type="hidden" name="valor" value="1">
                                                <button type="submit" class="btn-mini btn-mini-ok">Activar</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <a class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= $page <= 1 ? '#' : h(urlWithCli(['page' => $page - 1])) ?>">←</a>

                <div class="pager-mid">Página <?= (int)$page ?> / <?= (int)$totalPages ?></div>

                <a class="pager-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                   href="<?= $page >= $totalPages ? '#' : h(urlWithCli(['page' => $page + 1])) ?>">→</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEditClientes): ?>
    <div id="cliDrawerOverlay" class="drawer-overlay<?= $drawerOpen ? ' is-open' : '' ?>"></div>

    <aside id="cliDrawer" class="drawer<?= $drawerOpen ? ' is-open' : '' ?>" aria-label="Cliente" aria-hidden="<?= $drawerOpen ? 'false' : 'true' ?>">
        <div class="drawer-header">
            <h3 class="drawer-title"><?= !empty($editCliente) ? 'Editar cliente' : 'Nuevo cliente' ?></h3>
            <button class="drawer-close" id="cliDrawerClose" type="button" title="Cerrar">✕</button>
        </div>

        <div class="drawer-body">
            <form method="post" class="clientes-form" id="cliForm">
                <?= csrf_field() ?>
                <input type="hidden" name="submit_token" value="<?= bin2hex(random_bytes(8)) ?>">

                <?php if (!empty($editCliente)): ?>
                    <input type="hidden" name="id" value="<?= (int)$editCliente['id'] ?>">
                <?php endif; ?>

                <div class="cli-grid">
                    <!-- NOMBRE -->
                    <div class="cli-field cli-field-wide">
                        <label>Nombre / razón social <span class="required">*</span></label>
                        <input name="nombre" required
                               value="<?= h($editCliente['nombre'] ?? ($_POST['nombre'] ?? '')) ?>">
                    </div>

                    <!-- CUIT -->
                    <div class="cli-field">
                        <label>
                            CUIT / CUIL 
                            <span class="helper-text" title="Formato: XX-XXXXXXXX-X">ℹ️</span>
                        </label>
                        <input name="cuit" 
                               placeholder="20-12345678-9" 
                               maxlength="13"
                               data-cuit-input
                               value="<?= h($editCliente['cuit'] ?? ($_POST['cuit'] ?? '')) ?>">
                        <small id="cuitError" class="field-error"></small>
                    </div>

                    <!-- CONDICIÓN IVA -->
                    <div class="cli-field">
                        <label>Condición IVA</label>
                        <select name="cond_iva" id="condIvaSelect">
                            <?php
                            $condActual = (string)($editCliente['cond_iva'] ?? ($_POST['cond_iva'] ?? ''));
                            foreach ($condIvaOptions as $val => $label):
                                ?>
                                <option value="<?= h($val) ?>" <?= ($condActual === $val) ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- TIPO DE CLIENTE -->
                    <div class="cli-field">
                        <label>Tipo de cliente</label>
                        <select name="tipo_cliente">
                            <?php
                            $tipoActual = (string)($editCliente['tipo_cliente'] ?? ($_POST['tipo_cliente'] ?? 'MINORISTA'));
                            foreach ($tipoOptions as $val => $label):
                                ?>
                                <option value="<?= h($val) ?>" <?= ($tipoActual === $val) ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- DESCUENTO -->
                    <div class="cli-field">
                        <label>Descuento permanente (%)</label>
                        <input type="number" 
                               name="descuento_porcentaje" 
                               min="0" 
                               max="100" 
                               step="0.01"
                               placeholder="0.00"
                               value="<?= h($editCliente['descuento_porcentaje'] ?? ($_POST['descuento_porcentaje'] ?? '0.00')) ?>">
                        <small class="field-help">Se aplicará automáticamente en ventas</small>
                    </div>

                    <!-- EMAIL -->
                    <div class="cli-field">
                        <label>Email</label>
                        <input type="email" name="email" value="<?= h($editCliente['email'] ?? ($_POST['email'] ?? '')) ?>">
                    </div>

                    <!-- TELÉFONO -->
                    <div class="cli-field">
                        <label>Teléfono</label>
                        <input name="telefono" value="<?= h($editCliente['telefono'] ?? ($_POST['telefono'] ?? '')) ?>">
                    </div>

                    <!-- DIRECCIÓN -->
                    <div class="cli-field cli-field-wide">
                        <label>Dirección</label>
                        <input name="direccion" value="<?= h($editCliente['direccion'] ?? ($_POST['direccion'] ?? '')) ?>">
                    </div>
                    
                    <!-- ZONA DE REPARTO -->
                    <?php if (!empty($zonasReparto)): ?>
                    <div class="cli-field">
                        <label>Zona de reparto</label>
                        <select name="zona_reparto">
                            <option value="">Sin zona</option>
                            <?php
                            $zonaActual = (string)($editCliente['zona_reparto'] ?? ($_POST['zona_reparto'] ?? ''));
                            foreach ($zonasReparto as $zona):
                                $costoEnvio = (float)($zona['costo_envio'] ?? 0);
                                $tiempo = (int)($zona['tiempo_estimado_min'] ?? 0);
                                $label = $zona['nombre'];
                                if ($costoEnvio > 0) $label .= " (+$" . number_format($costoEnvio, 0) . ")";
                                if ($tiempo > 0) $label .= " - {$tiempo}min";
                                ?>
                                <option value="<?= h($zona['codigo']) ?>" <?= ($zonaActual === $zona['codigo']) ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <!-- NOTAS -->
                    <div class="cli-field cli-field-wide">
                        <label>Notas internas</label>
                        <textarea name="notas" rows="3" placeholder="Preferencias, horarios de entrega, etc."><?= h($editCliente['notas'] ?? ($_POST['notas'] ?? '')) ?></textarea>
                    </div>

                    <!-- ESTADO ACTIVO -->
                    <div class="cli-field cli-field-status">
                        <label class="cli-status-label">Estado del cliente</label>
                        <label class="edit-switch">
                            <?php $activoForm = $editCliente['activo'] ?? ($_POST['activo'] ?? 1); ?>
                            <input type="checkbox" name="activo" <?= ((int)$activoForm) ? 'checked' : '' ?>>
                            <span class="edit-switch-slider"></span>
                            <span class="edit-switch-text">Activo</span>
                        </label>
                    </div>
                </div>

                <div class="cli-actions">
                    <button type="submit" class="btn btn-primary" id="cliSubmitBtn">Guardar cliente</button>
                    <button type="button" class="btn btn-secondary" id="cliCancelBtn">Cancelar</button>
                </div>

                <?php if (!empty($errores)): ?>
                    <div class="cli-form-errors">
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
        'created' => 'Cliente creado correctamente.',
        'updated' => 'Cliente actualizado correctamente.',
        'activated' => 'Cliente activado.',
        'deactivated' => 'Cliente desactivado.',
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