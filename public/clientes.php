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

// Detectar columnas disponibles
$hasCC = $controller->hasColumnCC();
$hasTipo = $controller->hasColumn('tipo_cliente');
$hasDescuento = $controller->hasColumn('descuento_porcentaje');
$hasZona = $controller->hasColumn('zona_reparto');
$hasNotas = $controller->hasColumn('notas');

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
        flus_abort(403, 'No tenés permisos.');
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
        flus_abort(403, 'No tenés permisos.');
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errores[] = 'Token inválido (CSRF).';
    }

    // Prevenir doble submit
    $submitToken = (string)($_POST['submit_token'] ?? '');
    $lastToken   = (string)($_SESSION['last_submit_token'] ?? '');

    if ($submitToken !== '' && hash_equals($lastToken, $submitToken)) {
        header('Location: ' . urlWithCli(['saved' => 'duplicate']));
        exit;
    }

    $errores = array_merge($errores, $controller->validateForm($_POST));

    if (empty($errores)) {
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
        flus_abort(403, 'No tenés permisos.');
    }
    $editCliente = $controller->getById($editId);
}

/* ========== FILTROS Y LISTADO ========== */
$q = trim((string)($_GET['q'] ?? ''));
if (strlen($q) > 120) $q = substr($q, 0, 120);

$estado   = (string)($_GET['estado'] ?? '');
$tipo     = (string)($_GET['tipo'] ?? '');
$estadoCC = (string)($_GET['estado_cc'] ?? '');
$perPage  = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

$listData = $controller->getList([
    'q'         => $q,
    'estado'    => $estado,
    'tipo'      => $tipo,
    'estado_cc' => $estadoCC,
    'per_page'  => $perPage,
    'page'      => $page,
]);

$clientes   = $listData['clientes'];
$totalRows  = $listData['totalRows'];
$totalPages = $listData['totalPages'];
$page       = $listData['currentPage'];

$condIvaOptions = ClienteController::getCondIvaOptions();
$tipoOptions = ClienteController::getTipoClienteOptions();
$zonasReparto = $hasZona ? $controller->getZonasReparto() : [];

// Estadísticas CC
$statsCC = $hasCC ? $controller->getEstadisticasCC() : null;

/* ========== DRAWER OPEN? ========== */
$isNew = ((string)($_GET['new'] ?? '') === '1');
$drawerOpen = $canEditClientes && ($isNew || !empty($editCliente) || !empty($errores));

/* ========== HEADER ========== */
$pageTitle = 'Clientes';
$currentSection = 'clientes';
$extraCss = ['assets/css/clientes.css'];
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

    <?php if ($hasCC && $statsCC): ?>
    <div class="cli-cc-stats">
        <div class="cc-stat-card">
            <div class="cc-stat-value"><?= (int)$statsCC['total_con_cc'] ?></div>
            <div class="cc-stat-label">Con cuenta corriente</div>
        </div>
        <div class="cc-stat-card">
            <div class="cc-stat-value cc-stat-money">$<?= number_format((float)$statsCC['total_deuda'], 0, ',', '.') ?></div>
            <div class="cc-stat-label">Deuda total</div>
        </div>
        <div class="cc-stat-card">
            <div class="cc-stat-value"><?= (int)$statsCC['clientes_con_deuda'] ?></div>
            <div class="cc-stat-label">Con deuda</div>
        </div>
        <div class="cc-stat-card cc-stat-warning">
            <div class="cc-stat-value"><?= (int)$statsCC['clientes_excedidos'] ?></div>
            <div class="cc-stat-label">Excedidos</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel cli-list-panel">
        <h2 class="sub-title-page">Listado</h2>

        <form method="get" class="filters">
            <div class="filters-left">
                <input type="search" name="q" placeholder="Buscar por nombre, CUIT, email..." 
                       value="<?= h($q) ?>" class="search-input">
                
                <?php if ($hasTipo): ?>
                <select name="tipo" onchange="this.form.submit()">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($tipoOptions as $val => $label): ?>
                        <option value="<?= h($val) ?>" <?= $tipo === $val ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                
                <select name="estado" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <option value="activos" <?= $estado === 'activos' ? 'selected' : '' ?>>Activos</option>
                    <option value="inactivos" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                </select>
                
                <?php if ($hasCC): ?>
                <select name="estado_cc" onchange="this.form.submit()">
                    <option value="">Todas las CC</option>
                    <option value="cc_activa" <?= $estadoCC === 'cc_activa' ? 'selected' : '' ?>>Con CC activa</option>
                    <option value="cc_con_deuda" <?= $estadoCC === 'cc_con_deuda' ? 'selected' : '' ?>>Con deuda</option>
                    <option value="cc_excedido" <?= $estadoCC === 'cc_excedido' ? 'selected' : '' ?>>Excedidos</option>
                    <option value="cc_al_dia" <?= $estadoCC === 'cc_al_dia' ? 'selected' : '' ?>>Al día</option>
                    <option value="sin_cc" <?= $estadoCC === 'sin_cc' ? 'selected' : '' ?>>Sin CC</option>
                </select>
                <?php endif; ?>
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
                        <?php if ($hasTipo): ?><th>Tipo</th><?php endif; ?>
                        <th>Cond. IVA</th>
                        <th>Contacto</th>
                        <?php if ($hasDescuento): ?><th>Desc.</th><?php endif; ?>
                        <?php if ($hasCC): ?><th>Cuenta Cte.</th><?php endif; ?>
                        <th>Estado</th>
                        <th class="center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$clientes): ?>
                        <tr>
                            <td colspan="<?= 5 + ($hasTipo?1:0) + ($hasDescuento?1:0) + ($hasCC?1:0) ?>" class="empty-cell">
                                No se encontraron clientes.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clientes as $c): ?>
                            <?php
                            $cond = (string)($c['cond_iva'] ?? '');
                            $condLabel = $condIvaOptions[$cond] ?? $cond;
                            $tipoLabel = $tipoOptions[$c['tipo_cliente'] ?? 'MINORISTA'] ?? 'Minorista';
                            $descuento = (float)($c['descuento_porcentaje'] ?? 0);
                            
                            // Datos CC
                            $ccHab = (int)($c['cc_habilitado'] ?? 0) === 1;
                            $ccSaldo = (float)($c['cc_saldo'] ?? 0);
                            $ccLimite = (float)($c['cc_limite'] ?? 0);
                            $ccExcedido = $ccHab && $ccSaldo > $ccLimite;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= h($c['nombre'] ?? '') ?></strong>
                                    <?php if ($hasZona && !empty($c['zona_reparto'])): ?>
                                        <div class="muted small">📍 <?= h($c['zona_reparto']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="mono"><?= h($c['cuit'] ?? '—') ?></td>
                                <?php if ($hasTipo): ?>
                                <td>
                                    <span class="tag tag-<?= strtolower($c['tipo_cliente'] ?? 'minorista') ?>">
                                        <?= h($tipoLabel) ?>
                                    </span>
                                </td>
                                <?php endif; ?>
                                <td><?= h($condLabel) ?></td>
                                <td>
                                    <?php if (!empty($c['email'])): ?><div><?= h($c['email']) ?></div><?php endif; ?>
                                    <?php if (!empty($c['telefono'])): ?><div class="muted"><?= h($c['telefono']) ?></div><?php endif; ?>
                                </td>
                                <?php if ($hasDescuento): ?>
                                <td class="center">
                                    <?php if ($descuento > 0): ?>
                                        <span class="tag tag-descuento"><?= number_format($descuento, 0) ?>%</span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <?php if ($hasCC): ?>
                                <td>
                                    <?php if ($ccHab): ?>
                                        <div class="cc-cell <?= $ccExcedido ? 'cc-excedido' : ($ccSaldo > 0 ? 'cc-deuda' : 'cc-ok') ?>">
                                            <span class="cc-saldo">$<?= number_format($ccSaldo, 0, ',', '.') ?></span>
                                            <span class="cc-limite">/ $<?= number_format($ccLimite, 0, ',', '.') ?></span>
                                        </div>
                                        <?php if ($ccExcedido): ?>
                                            <span class="tag tag-danger tag-sm">Excedido</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
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
                                        
                                        <?php if ($hasCC && $ccHab): ?>
                                            <a class="btn-mini btn-mini-cc" href="cuenta_corriente_cliente.php?id=<?= (int)$c['id'] ?>" title="Ver cuenta corriente">💳</a>
                                        <?php endif; ?>

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
                        <label>CUIT / CUIL</label>
                        <input name="cuit" 
                               placeholder="20-12345678-9" 
                               maxlength="13"
                               data-cuit-input
                               value="<?= h($editCliente['cuit'] ?? ($_POST['cuit'] ?? '')) ?>">
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
                    
                    <?php if ($hasTipo): ?>
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
                    <?php endif; ?>
                    
                    <?php if ($hasDescuento): ?>
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
                    </div>
                    <?php endif; ?>

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
                    
                    <?php if ($hasZona && !empty($zonasReparto)): ?>
                    <!-- ZONA DE REPARTO -->
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
                    
                    <?php if ($hasNotas): ?>
                    <!-- NOTAS -->
                    <div class="cli-field cli-field-wide">
                        <label>Notas internas</label>
                        <textarea name="notas" rows="3" placeholder="Preferencias, horarios de entrega, etc."><?= h($editCliente['notas'] ?? ($_POST['notas'] ?? '')) ?></textarea>
                    </div>
                    <?php endif; ?>

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
                
                <?php if ($hasCC): ?>
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- SECCIÓN CUENTA CORRIENTE -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="cli-cc-section">
                    <div class="cli-cc-header">
                        <h4>💳 Cuenta Corriente (Fiado)</h4>
                        <label class="edit-switch">
                            <?php $ccHabForm = $editCliente['cc_habilitado'] ?? ($_POST['cc_habilitado'] ?? 0); ?>
                            <input type="checkbox" name="cc_habilitado" id="ccHabilitadoCheck" <?= ((int)$ccHabForm) ? 'checked' : '' ?>>
                            <span class="edit-switch-slider"></span>
                            <span class="edit-switch-text">Habilitada</span>
                        </label>
                    </div>
                    
                    <div id="ccConfigPanel" class="cli-cc-config" style="<?= ((int)$ccHabForm) ? '' : 'display:none' ?>">
                        <div class="cli-cc-fields">
                            <div class="cli-field">
                                <label>Límite de crédito</label>
                                <div class="input-with-prefix">
                                    <span class="input-prefix">$</span>
                                    <input type="number" 
                                           name="cc_limite" 
                                           id="ccLimiteInput"
                                           min="0" 
                                           max="99999999"
                                           step="0.01"
                                           placeholder="0.00"
                                           value="<?= h($editCliente['cc_limite'] ?? ($_POST['cc_limite'] ?? '0.00')) ?>">
                                </div>
                                <small class="field-help">Monto máximo que puede adeudar el cliente</small>
                            </div>
                            
                            <?php if (!empty($editCliente['id']) && (int)($editCliente['cc_habilitado'] ?? 0) === 1): ?>
                            <div class="cli-cc-info">
                                <?php 
                                $ccSaldo = (float)($editCliente['cc_saldo'] ?? 0);
                                $ccLimite = (float)($editCliente['cc_limite'] ?? 0);
                                $ccDisponible = $ccLimite - $ccSaldo;
                                $ccExcedido = $ccSaldo > $ccLimite;
                                ?>
                                <div class="cc-info-row">
                                    <span class="cc-info-label">Saldo actual:</span>
                                    <span class="cc-info-value <?= $ccSaldo > 0 ? 'cc-value-deuda' : '' ?>">
                                        $<?= number_format($ccSaldo, 2, ',', '.') ?>
                                    </span>
                                </div>
                                <div class="cc-info-row">
                                    <span class="cc-info-label">Disponible:</span>
                                    <span class="cc-info-value <?= $ccExcedido ? 'cc-value-excedido' : '' ?>">
                                        $<?= number_format($ccDisponible, 2, ',', '.') ?>
                                    </span>
                                </div>
                                <?php if (!empty($editCliente['cc_fecha_ultimo_pago'])): ?>
                                <div class="cc-info-row">
                                    <span class="cc-info-label">Último pago:</span>
                                    <span class="cc-info-value"><?= date('d/m/Y', strtotime($editCliente['cc_fecha_ultimo_pago'])) ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="cc-actions">
                                    <a href="cuenta_corriente_cliente.php?id=<?= (int)$editCliente['id'] ?>" class="btn btn-sm btn-secondary">
                                        Ver movimientos →
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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

<script>
// Toggle del panel de cuenta corriente
document.addEventListener('DOMContentLoaded', function() {
    const ccCheck = document.getElementById('ccHabilitadoCheck');
    const ccPanel = document.getElementById('ccConfigPanel');
    const ccLimite = document.getElementById('ccLimiteInput');
    
    if (ccCheck && ccPanel) {
        ccCheck.addEventListener('change', function() {
            ccPanel.style.display = this.checked ? '' : 'none';
            if (this.checked && ccLimite && (ccLimite.value === '0.00' || ccLimite.value === '0')) {
                ccLimite.focus();
            }
        });
    }
});
</script>
