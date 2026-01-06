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

/* =========================================================
   URL helper
========================================================= */
function urlWithCli(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return 'clientes.php' . (empty($q) ? '' : '?' . http_build_query($q));
}

/* =========================================================
   Flags / mensajes
========================================================= */
$savedFlag = (string)($_GET['saved'] ?? '');
$errores   = [];

/* =========================================================
   ACCIÓN: activar / desactivar
========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['accion'] ?? '') === 'toggle_activo') {
    if (!$canEditClientes) {
        http_response_code(403);
        die('No tenés permisos para modificar clientes.');
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        header('Location: ' . urlWithCli(['saved' => 'csrf']));
        exit;
    }

    $valor = (int)($_POST['valor'] ?? 0);
    $success = $controller->toggleActivo($_POST);

    if ($success) {
        header('Location: ' . urlWithCli([
            'saved' => ($valor ? 'activated' : 'deactivated'),
            'page'  => $_GET['page'] ?? 1
        ]));
        exit;
    }

    header('Location: ' . urlWithCli(['saved' => 'error']));
    exit;
}

/* =========================================================
   ALTA / EDICIÓN (POST)
========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && empty($_POST['accion'])) {
    if (!$canEditClientes) {
        http_response_code(403);
        die('No tenés permisos para modificar clientes.');
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errores[] = 'Token inválido (CSRF). Recargá la página e intentá de nuevo.';
    }

    // ✅ Prevenir doble submit
    $submitToken = (string)($_POST['submit_token'] ?? '');
    $lastToken   = (string)($_SESSION['last_submit_token'] ?? '');
    
    if ($submitToken !== '' && $submitToken === $lastToken) {
        header('Location: ' . urlWithCli(['saved' => 'duplicate']));
        exit;
    }

    $errores = array_merge($errores, $controller->validateForm($_POST));

    if (empty($errores)) {
        // Generar nuevo token
        $newToken = bin2hex(random_bytes(16));
        $_SESSION['last_submit_token'] = $newToken;

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

/* =========================================================
   Cargar cliente para edición
========================================================= */
$editCliente = null;
$editId = (int)($_GET['editar'] ?? 0);

if ($editId > 0) {
    if (!$canEditClientes) {
        http_response_code(403);
        die('No tenés permisos para editar clientes.');
    }
    $editCliente = $controller->getById($editId);
}

/* =========================================================
   Filtros y listado
========================================================= */
$q       = trim((string)($_GET['q'] ?? ''));
if (strlen($q) > 120) $q = substr($q, 0, 120);

$estado  = (string)($_GET['estado'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

$listData = $controller->getList([
    'q'        => $q,
    'estado'   => $estado,
    'per_page' => $perPage,
    'page'     => $page,
]);

$clientes   = $listData['clientes'];
$totalRows  = $listData['totalRows'];
$totalPages = $listData['totalPages'];
$page       = $listData['currentPage'];

$condIvaOptions = ClienteController::getCondIvaOptions();

/* =========================================================
   Drawer open?
========================================================= */
$isNew = ((string)($_GET['new'] ?? '') === '1');
$drawerOpen = $canEditClientes && ($isNew || !empty($editCliente) || !empty($errores));

/* =========================================================
   Header
========================================================= */
$pageTitle      = 'Clientes';
$currentSection = 'clientes';
$extraCss       = ['assets/css/clientes.css'];
$extraJs        = ['assets/js/clientes.js'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap clientes-page">

    <div class="panel cli-panel">
        <header class="page-header">
            <div>
                <h1 class="page-title">Clientes</h1>
                <p class="page-sub">ABM de clientes para facturación y referencias en ventas.</p>
            </div>

            <?php if ($canEditClientes): ?>
                <a class="btn btn-primary" href="<?= h(urlWithCli(['new' => 1, 'editar' => null])) ?>">
                    + Nuevo cliente
                </a>
            <?php else: ?>
                <span class="tag tag-muted">Solo lectura</span>
            <?php endif; ?>
        </header>
    </div>

    <div class="panel cli-list-panel">
        <h2 class="sub-title-page">Listado</h2>

        <form method="get" class="filters">
            <div class="filters-left">
                <input type="text" name="q" placeholder="Buscar por nombre, CUIT o email..." value="<?= h($q) ?>">
            </div>

            <div class="filters-right">
                <select name="estado">
                    <option value="">Todos</option>
                    <option value="activos"   <?= $estado === 'activos' ? 'selected' : '' ?>>Solo activos</option>
                    <option value="inactivos" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Solo inactivos</option>
                </select>

                <select name="per_page">
                    <?php foreach ([20, 50, 100] as $n): ?>
                        <option value="<?= (int)$n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= (int)$n ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="hidden" name="page" value="1">
                <button class="btn btn-filter" type="submit">Aplicar</button>

                <?php if ($q !== '' || $estado !== '' || $perPage !== 50): ?>
                    <a href="clientes.php" class="btn btn-secondary">Limpiar</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="table-wrapper">
            <table class="mov-table clientes-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>CUIT / CUIL</th>
                        <th>Cond. IVA</th>
                        <th>Contacto</th>
                        <th>Estado</th>
                        <th class="center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$clientes): ?>
                        <tr>
                            <td colspan="6" class="empty-cell">No se encontraron clientes con los filtros actuales.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clientes as $c): ?>
                            <?php
                            $cond = (string)($c['cond_iva'] ?? '');
                            $condLabel = $condIvaOptions[$cond] ?? $cond;
                            ?>
                            <tr>
                                <td><?= h($c['nombre'] ?? '') ?></td>
                                <td><?= h($c['cuit'] ?? '') ?></td>
                                <td><?= h($condLabel) ?></td>
                                <td>
                                    <?php if (!empty($c['email'])): ?><div><?= h($c['email']) ?></div><?php endif; ?>
                                    <?php if (!empty($c['telefono'])): ?><div class="muted"><?= h($c['telefono']) ?></div><?php endif; ?>
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
                                            <form method="post" style="display:inline" onsubmit="return confirm('¿Desactivar este cliente?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="accion" value="toggle_activo">
                                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                                <input type="hidden" name="valor" value="0">
                                                <button type="submit" class="btn-mini btn-mini-ghost">Desactivar</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('¿Activar este cliente?');">
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
    <!-- Overlay -->
    <div id="cliDrawerOverlay" class="drawer-overlay<?= $drawerOpen ? ' is-open' : '' ?>"></div>

    <!-- Drawer -->
    <aside id="cliDrawer" class="drawer<?= $drawerOpen ? ' is-open' : '' ?>" aria-label="Cliente" aria-hidden="<?= $drawerOpen ? 'false' : 'true' ?>">
        <div class="drawer-header">
            <h3 class="drawer-title"><?= !empty($editCliente) ? 'Editar cliente' : 'Nuevo cliente' ?></h3>
            <button class="drawer-close" id="cliDrawerClose" type="button" title="Cerrar">✕</button>
        </div>

        <div class="drawer-body">
            <form method="post" class="clientes-form" id="cliForm">
                <?= csrf_field() ?>
                
                <!-- ✅ Token anti-doble submit -->
                <input type="hidden" name="submit_token" value="<?= bin2hex(random_bytes(8)) ?>">

                <?php if (!empty($editCliente)): ?>
                    <input type="hidden" name="id" value="<?= (int)$editCliente['id'] ?>">
                <?php endif; ?>

                <div class="cli-grid">
                    <div class="cli-field cli-field-wide">
                        <label>Nombre / razón social</label>
                        <input name="nombre" required
                               value="<?= h($editCliente['nombre'] ?? ($_POST['nombre'] ?? '')) ?>">
                    </div>

                    <div class="cli-field">
                        <label>CUIT / CUIL</label>
                        <input name="cuit" maxlength="20" value="<?= h($editCliente['cuit'] ?? ($_POST['cuit'] ?? '')) ?>">
                    </div>

                    <div class="cli-field">
                        <label>Condición IVA</label>
                        <select name="cond_iva">
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

                    <div class="cli-field cli-field-wide">
                        <label>Dirección</label>
                        <input name="direccion" value="<?= h($editCliente['direccion'] ?? ($_POST['direccion'] ?? '')) ?>">
                    </div>

                    <div class="cli-field">
                        <label>Email</label>
                        <input type="email" name="email" value="<?= h($editCliente['email'] ?? ($_POST['email'] ?? '')) ?>">
                    </div>

                    <div class="cli-field">
                        <label>Teléfono</label>
                        <input name="telefono" value="<?= h($editCliente['telefono'] ?? ($_POST['telefono'] ?? '')) ?>">
                    </div>

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
    $toastMsg = 'Listo.';
    if ($savedFlag === 'created')         $toastMsg = 'Cliente creado correctamente.';
    elseif ($savedFlag === 'updated')     $toastMsg = 'Cliente actualizado correctamente.';
    elseif ($savedFlag === 'activated')   $toastMsg = 'Cliente activado.';
    elseif ($savedFlag === 'deactivated') $toastMsg = 'Cliente desactivado.';
    elseif ($savedFlag === 'csrf')        $toastMsg = 'Acción bloqueada: token inválido. Recargá e intentá de nuevo.';
    elseif ($savedFlag === 'duplicate')   $toastMsg = 'Ya guardaste este formulario. No se puede enviar dos veces.';
    elseif ($savedFlag === 'error')       $toastMsg = 'Ocurrió un error. Intentá de nuevo.';
    ?>
    <script>
        if (window.showToast) {
            window.showToast(<?= json_encode($toastMsg, JSON_UNESCAPED_UNICODE) ?>);
        }
    </script>
<?php endif; ?>