<?php
declare(strict_types=1);

if (function_exists('flus_session_start')) {
    flus_session_start();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../lib/terminal.php';
require_once __DIR__ . '/../caja_lib.php';

$user = $user ?? (function_exists('current_user') ? current_user() : []);
$pdo  = $pdo  ?? (function_exists('getPDO') ? getPDO() : null);
$esc  = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$groupCurrentLabel = static function (string $fallback, array $links, string $section): string {
    foreach ($links as $link) {
        if (($link['section'] ?? '') === $section) {
            return (string)($link['label'] ?? $fallback);
        }
    }
    return $fallback;
};

$can = function (string $perm) use ($user): bool {
    if (function_exists('user_has_permission')) return user_has_permission($perm);
    $perms = function_exists('session_permissions')
        ? session_permissions()
        : ($_SESSION['permissions'] ?? ($user['permissions'] ?? []));
    if (!is_array($perms)) $perms = [];
    return in_array($perm, $perms, true);
};

/* ═══════════════════════════════════════════
   SECCIÓN ACTIVA
═══════════════════════════════════════════ */
$currentSection = $currentSection ?? '';
$currentFile = basename((string)($_SERVER['PHP_SELF'] ?? ''));
if ($currentSection === '') {
    $file = $currentFile;
    $map  = [
        'index.php'                    => 'inicio',
        'dashboard.php'                => 'dashboard',
        'caja.php'                     => 'caja',
        'caja_cerrar.php'              => 'caja',
        'caja_movimientos.php'         => 'caja',
        'productos.php'                => 'productos',
        'productos_consulta.php'       => 'productos',
        'stock.php'                    => 'stock',
        'inventario_analisis.php'      => 'inventario_analisis',
        'inventario_fisico.php'        => 'inventario_fisico',
        'reposicion.php'               => 'reposicion',
        'movimientos.php'              => 'movimientos',
        'ventas.php'                   => 'ventas',
        'venta_detalle.php'            => 'ventas',
        'compras.php'                  => 'compras',
        'compra_detalle.php'           => 'compras',
        'proveedores.php'              => 'proveedores',
        'caja_historial.php'           => 'caja_historial',
        'caja_sesion_detalle.php'      => 'caja_historial',
        'caja_sesion_export.php'       => 'caja_historial',
        'caja_sesion_print.php'        => 'caja_historial',
        'promos.php'                   => 'promos',
        'promo_form.php'               => 'promos',
        'promo_combo_form.php'         => 'promos',
        'promo_builder.php'            => 'promos',
        'clientes.php'                 => 'clientes',
        'proveedores.php'              => 'proveedores',
        'cuenta_corriente.php'         => 'cuenta_corriente',
        'cuenta_corriente_cliente.php' => 'cuenta_corriente',
        'cuenta_corriente_print.php'   => 'cuenta_corriente',
        'facturacion.php'              => 'facturacion',
        'facturacion_recovery.php'     => 'facturacion',
        'facturacion_config.php'       => 'facturacion',
        'facturacion_nc.php'           => 'facturacion',
        'facturacion_nc_emitir.php'    => 'facturacion',
        'facturacion_nc_recovery.php'  => 'facturacion',
        'factura_nueva.php'            => 'facturacion',
        'factura_manual.php'           => 'facturacion',
        'documentos_comerciales.php'   => 'facturacion',
        'documento_comercial.php'      => 'facturacion',
        'factura_ver.php'              => 'facturacion',
        'factura_pdf.php'              => 'facturacion',
        'factura_emitir.php'           => 'facturacion',
        'configuracion.php'            => 'configuracion',
        'licencia.php'                 => 'configuracion',
        'usuarios.php'                 => 'usuarios',
        'usuario_nuevo.php'            => 'usuarios',
        'usuario_editar.php'           => 'usuarios',
        'auditoria.php'                => 'auditoria',
        'backups.php'                  => 'backups',
        'roles.php'                    => 'roles',
        'rol_permisos.php'             => 'roles',
        'terminales.php'               => 'configuracion',
        'diagnostico.php'              => 'diagnostico',
        'tecnico.php'                  => 'tecnico',
        'stock_consulta.php'           => 'stock',
        'ticket.php'                   => 'ventas',
        'precios_historial.php'        => 'precios_historial',
    ];
    if (isset($map[$file])) $currentSection = $map[$file];
}

/* ═══════════════════════════════════════════
   BREADCRUMB
   Cada página puede declarar antes del header:
     $breadcrumb = [
       ['label' => 'Ventas',    'url' => 'ventas.php'],
       ['label' => 'Venta #42', 'url' => ''],   // último sin url
     ];
   Si la página no declara nada, se arma un fallback automático:
     Inicio > módulo actual
   (solo cuando se puede detectar la sección).
═══════════════════════════════════════════ */
$rawBreadcrumb = $breadcrumbs ?? ($breadcrumb ?? []);   // compat: plural o singular
$breadcrumb = function_exists('flus_normalize_breadcrumbs')
    ? flus_normalize_breadcrumbs($rawBreadcrumb)
    : (is_array($rawBreadcrumb) ? $rawBreadcrumb : []);

/* ═══════════════════════════════════════════
   ESTADO DE CAJA
═══════════════════════════════════════════ */
$cajaAbierta = false;
$cajaId      = 0;
try {
    if ($pdo instanceof PDO) {
        $terminalId  = current_terminal_id();
        $cajaRow     = $terminalId > 0 ? caja_get_abierta($pdo, $terminalId) : null;
        $cajaAbierta = $cajaRow !== null;
        $cajaId      = (int)($cajaRow['id'] ?? 0);
    }
} catch (Throwable $e) {
    $cajaAbierta = false;
}

/* ═══════════════════════════════════════════
   ALERTAS  — caché en sesión (TTL 2 min)
   Las queries solo corren si el caché expiró.
   Fallback silencioso ante cualquier error.
═══════════════════════════════════════════ */
$navAlerts     = [];
$navAlertTotal = 0;

$_alertCacheKey = '_flus_nav_alerts';
$_alertTtl      = 120; // segundos

$_cached   = $_SESSION[$_alertCacheKey] ?? null;
$_cacheOk  = is_array($_cached)
    && isset($_cached['ts'])
    && (time() - (int)$_cached['ts']) < $_alertTtl;

if ($_cacheOk) {
    $navAlerts     = $_cached['alerts']      ?? [];
    $navAlertTotal = $_cached['total']       ?? 0;
} else {
    try {
        if ($pdo instanceof PDO) {

            // 1) Stock crítico
            $st  = $pdo->query(
                "SELECT COUNT(*) FROM productos
                 WHERE activo = 1
                   AND stock_minimo IS NOT NULL AND stock_minimo > 0
                   AND stock <= stock_minimo"
            );
            $cnt = $st ? (int)$st->fetchColumn() : 0;
            if ($cnt > 0) {
                $navAlerts[]    = ['label' => 'Stock crítico',    'url' => 'reposicion.php',        'count' => $cnt];
                $navAlertTotal += $cnt;
            }

            // 2) Clientes morosos
            $canCheckMorosos =
                function_exists('flus_table_exists')
                && function_exists('flus_column_exists')
                && flus_table_exists($pdo, 'clientes')
                && flus_column_exists($pdo, 'clientes', 'cc_saldo')
                && flus_column_exists($pdo, 'clientes', 'cc_habilitado')
                && flus_column_exists($pdo, 'clientes', 'cc_fecha_ultimo_pago');

            if ($canCheckMorosos) {
                $stMor = $pdo->query(
                    "SELECT COUNT(*) FROM clientes
                     WHERE cc_habilitado = 1 AND cc_saldo > 0
                       AND cc_fecha_ultimo_pago < DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
                );
                $mor = $stMor ? (int)$stMor->fetchColumn() : 0;
                if ($mor > 0) {
                    $navAlerts[]    = ['label' => 'Clientes morosos', 'url' => 'cuenta_corriente.php', 'count' => $mor];
                    $navAlertTotal += $mor;
                }
            }

            // 3) Caja sin cerrar de días anteriores
            $stCj   = $pdo->query(
                "SELECT COUNT(*) FROM caja_sesiones
                 WHERE estado = 'abierta' AND DATE(apertura) < CURDATE()"
            );
            $cjOld  = $stCj ? (int)$stCj->fetchColumn() : 0;
            if ($cjOld > 0) {
                $navAlerts[]    = ['label' => 'Caja sin cerrar', 'url' => 'caja_historial.php', 'count' => $cjOld];
                $navAlertTotal += $cjOld;
            }
        }
    } catch (Throwable $e) {
        $navAlerts     = [];
        $navAlertTotal = 0;
    }

    // Guardar en sesión
    $_SESSION[$_alertCacheKey] = [
        'ts'     => time(),
        'alerts' => $navAlerts,
        'total'  => $navAlertTotal,
    ];
}

/* Helper para invalidar el caché manualmente desde cualquier módulo:
   $_SESSION['_flus_nav_alerts'] = null;
   (Ej: después de recibir una compra o actualizar stock)
*/

/* ═══════════════════════════════════════════
   PERMISOS
═══════════════════════════════════════════ */
$canDashboard        = $can('ver_reportes');
$canCaja             = $can('realizar_ventas');
$canProductos        = $can('editar_productos') || $can('ver_productos');
$canProductosEdit    = $can('editar_productos');
$canStock            = $can('editar_stock') || $can('ver_stock');
$canStockEdit        = $can('editar_stock');
$canInventario       = $can('editar_stock') || $can('ver_reportes');
$canMovimientos      = $can('ver_movimientos');
$canVentas           = $can('ver_reportes');
$canCompras          = $can('editar_stock');
$canProveedores      = $can('ver_proveedores') || $can('editar_proveedores');
$canHistCaja         = $can('ver_historial_caja');
$canPromos           = $can('editar_promos');
$canClientes         = $can('ver_clientes') || $can('editar_clientes');
$canCuentaCorriente  = $can('ver_cuenta_corriente') && $canClientes;
$canInventarioFisico = $can('editar_stock');
$canPreciosHistorial = $can('editar_productos');
$canReposicion       = $can('ver_reportes') || $can('editar_stock');
$canDiagnostico      = function_exists('user_can_access_diagnostics')
    ? user_can_access_diagnostics()
    : ($can('ver_diagnostico') || $can('gestionar_backups'));
$canTecnico          = function_exists('user_can_access_technical_panel')
    ? user_can_access_technical_panel()
    : ($can('administrar_config') || $can('gestionar_backups'));

$facturacionHabilitada = true;
if ($pdo instanceof PDO && function_exists('config_get')) {
    $facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
}
$canFacturacion = ($can('ver_facturacion') || $can('emitir_factura') || $can('administrar_config'))
                  && $facturacionHabilitada;

$showAdminMenu =
    $can('administrar_config') || $can('administrar_usuarios') ||
    $can('ver_auditoria')      || $can('gestionar_backups')    ||
    $canDiagnostico            || $canTecnico;

/* ═══════════════════════════════════════════
   GRUPOS DE NAVEGACIÓN
═══════════════════════════════════════════ */
$primaryLinks = [];
if ($canDashboard) $primaryLinks[] = ['href' => 'dashboard.php', 'section' => 'dashboard', 'label' => 'Panel',   'shortcut' => 'alt+p'];
if ($canVentas)    $primaryLinks[] = ['href' => 'ventas.php',    'section' => 'ventas',    'label' => 'Ventas',  'shortcut' => 'alt+n'];
if ($canCompras)   $primaryLinks[] = ['href' => 'compras.php',   'section' => 'compras',   'label' => 'Compras', 'shortcut' => 'alt+c'];

// Catálogo
$catalogLinks = [];
if ($canProductos)        $catalogLinks[] = ['href' => $canProductosEdit ? 'productos.php' : 'productos_consulta.php', 'section' => 'productos', 'label' => 'Productos', 'shortcut' => 'alt+k'];
if ($canPreciosHistorial) $catalogLinks[] = ['href' => 'precios_historial.php','section' => 'precios_historial','label' => 'Precios',     'shortcut' => ''];
if ($canPromos)           $catalogLinks[] = ['href' => 'promos.php',           'section' => 'promos',           'label' => 'Promociones', 'shortcut' => ''];

// Inventario
$inventoryLinks = [];
if ($canStock)            $inventoryLinks[] = ['href' => $canStockEdit ? 'stock.php' : 'stock_consulta.php', 'section' => 'stock', 'label' => 'Stock', 'shortcut' => 'alt+s'];
if ($canInventario)       $inventoryLinks[] = ['href' => 'inventario_analisis.php','section' => 'inventario_analisis','label' => "An\u{00E1}lisis",      'shortcut' => ''];
if ($canInventarioFisico) $inventoryLinks[] = ['href' => 'inventario_fisico.php',  'section' => 'inventario_fisico',  'label' => "Conteo f\u{00ED}sico", 'shortcut' => ''];
if ($canReposicion)       $inventoryLinks[] = ['href' => 'reposicion.php',         'section' => 'reposicion',         'label' => "Reposici\u{00F3}n",    'shortcut' => ''];
if ($canMovimientos)      $inventoryLinks[] = ['href' => 'movimientos.php',        'section' => 'movimientos',        'label' => 'Movimientos',    'shortcut' => ''];

// Clientes
$clientLinks = [];
if ($canClientes)        $clientLinks[] = ['href' => 'clientes.php',         'section' => 'clientes',         'label' => 'Clientes',         'shortcut' => ''];
if ($canCuentaCorriente) $clientLinks[] = ['href' => 'cuenta_corriente.php', 'section' => 'cuenta_corriente', 'label' => 'Cuenta corriente', 'shortcut' => ''];
if ($canProveedores)     $clientLinks[] = ['href' => 'proveedores.php',      'section' => 'proveedores',      'label' => 'Proveedores',      'shortcut' => ''];

// Facturacion
$facturacionLinks = [];
if ($canFacturacion) {
    $facturacionLinks[] = ['href' => 'facturacion.php',            'section' => 'facturacion', 'label' => 'Panel fiscal',     'shortcut' => '', 'active_files' => ['facturacion.php', 'factura_nueva.php', 'factura_emitir.php', 'factura_ver.php', 'factura_pdf.php']];
    if ($can('emitir_factura')) {
        $facturacionLinks[] = ['href' => 'factura_manual.php',     'section' => 'facturacion', 'label' => 'Factura manual',   'shortcut' => '', 'active_files' => ['factura_manual.php']];
    }
    $facturacionLinks[] = ['href' => 'documentos_comerciales.php', 'section' => 'facturacion', 'label' => 'Documentos',       'shortcut' => '', 'active_files' => ['documentos_comerciales.php', 'documento_comercial.php']];
    $facturacionLinks[] = ['href' => 'facturacion_nc.php',         'section' => 'facturacion', 'label' => 'Notas de credito', 'shortcut' => '', 'active_files' => ['facturacion_nc.php', 'facturacion_nc_emitir.php', 'facturacion_nc_recovery.php']];
    $facturacionLinks[] = ['href' => 'facturacion_recovery.php',   'section' => 'facturacion', 'label' => 'Incidencias',      'shortcut' => '', 'active_files' => ['facturacion_recovery.php']];
    if ($can('administrar_config')) {
        $facturacionLinks[] = ['href' => 'facturacion_config.php', 'section' => 'facturacion', 'label' => 'Configuracion',    'shortcut' => '', 'active_files' => ['facturacion_config.php']];
    }
}

// Admin
$adminLinks = [];
if ($can('administrar_usuarios')) $adminLinks[] = ['href' => 'usuarios.php',     'label' => 'Usuarios'];
if ($can('administrar_usuarios')) $adminLinks[] = ['href' => 'roles.php',        'label' => 'Roles y permisos'];
if ($canHistCaja)                 $adminLinks[] = ['href' => 'caja_historial.php','label' => 'Historial caja'];
if ($can('administrar_config'))   $adminLinks[] = ['href' => 'configuracion.php','label' => "Configuraci\u{00F3}n"];
if ($can('administrar_config'))   $adminLinks[] = ['href' => 'licencia.php',     'label' => 'Licencia'];
if ($can('administrar_config'))   $adminLinks[] = ['href' => 'terminales.php',   'label' => 'Terminales'];
if ($can('ver_auditoria'))        $adminLinks[] = ['href' => 'auditoria.php',    'label' => "Auditor\u{00ED}a"];
if ($can('gestionar_backups'))    $adminLinks[] = ['href' => 'backups.php',      'label' => 'Backups'];
if ($canDiagnostico)              $adminLinks[] = ['href' => 'diagnostico.php',  'label' => "Diagn\u{00F3}stico"];
if ($canTecnico)                  $adminLinks[] = ['href' => 'tecnico.php',      'label' => "T\u{00E9}cnico"];

$catalogSections   = ['productos', 'precios_historial', 'promos'];
$inventorySections = ['stock', 'inventario_analisis', 'inventario_fisico', 'reposicion', 'movimientos'];
$clientSections    = ['clientes', 'cuenta_corriente', 'proveedores'];
$facturacionSections = ['facturacion'];
$adminSections     = ['configuracion', 'usuarios', 'auditoria', 'backups', 'roles', 'diagnostico', 'tecnico', 'caja_historial'];

$catalogActive   = in_array($currentSection, $catalogSections,   true);
$inventoryActive = in_array($currentSection, $inventorySections, true);
$clientActive    = in_array($currentSection, $clientSections,    true);
$facturacionActive = in_array($currentSection, $facturacionSections, true);
$adminActive     = in_array($currentSection, $adminSections,     true);

$catalogLabel   = $groupCurrentLabel("Cat\u{00E1}logo",   $catalogLinks,   $currentSection);
$inventoryLabel = $groupCurrentLabel('Inventario', $inventoryLinks, $currentSection);
$clientLabel    = $groupCurrentLabel('Clientes',   $clientLinks,    $currentSection);
$facturacionLabel = 'Facturacion';

/* ═══════════════════════════════════════════
   AVATAR  — iniciales + rol legible
═══════════════════════════════════════════ */
$userName   = (string)($user['nombre']    ?? $user['username'] ?? 'Usuario');
$userRole   = (string)($user['role_slug'] ?? '');
$roleLabels = [
    'admin'         => 'Admin',
    'administrador' => 'Admin',
    'cajero'        => 'Cajero',
    'vendedor'      => 'Vendedor',
    'supervisor'    => 'Supervisor',
    'contador'      => 'Contador',
    'soporte'       => 'Soporte',
];
$roleDisplay = $roleLabels[strtolower($userRole)] ?? ucfirst($userRole);

$initials = '';
foreach (explode(' ', $userName) as $word) {
    if ($word !== '') $initials .= strtoupper(mb_substr($word, 0, 1));
    if (mb_strlen($initials) >= 2) break;
}
if ($initials === '') $initials = '?';

/* ═══════════════════════════════════════════
   MAPA DE SHORTCUTS  (para data-attrs en JS)
   Formato: 'alt+X'
   El JS los lee y registra el listener global.
═══════════════════════════════════════════ */
$shortcuts = [
    'alt+v' => 'caja.php',          // Vender
    'alt+p' => 'dashboard.php',     // Panel
    'alt+n' => 'ventas.php',        // veNtas
    'alt+c' => 'compras.php',       // Compras
    'alt+k' => 'productos.php',     // catálogo (K)
    'alt+s' => 'stock.php',         // Stock
];
// Serializado para data-shortcuts en el nav
$shortcutsJson = json_encode($shortcuts, JSON_UNESCAPED_UNICODE);

$navGroups = [];
if ($catalogLinks !== []) {
    $navGroups[] = [
        'active'     => $catalogActive,
        'buttonText' => $catalogLabel,
        'links'      => $catalogLinks,
        'menuLabel'  => "Cat\u{00E1}logo",
        'title'      => "Cat\u{00E1}logo de productos",
    ];
}
if ($inventoryLinks !== []) {
    $navGroups[] = [
        'active'     => $inventoryActive,
        'buttonText' => $inventoryLabel,
        'links'      => $inventoryLinks,
        'menuLabel'  => 'Inventario',
        'title'      => "Gesti\u{00F3}n de inventario",
    ];
}
if ($clientLinks !== []) {
    $navGroups[] = [
        'active'     => $clientActive,
        'buttonText' => $clientLabel,
        'links'      => $clientLinks,
        'menuLabel'  => 'Clientes',
        'title'      => 'Clientes, cuenta corriente y proveedores',
    ];
}
if ($facturacionLinks !== []) {
    $navGroups[] = [
        'active'     => $facturacionActive,
        'buttonText' => $facturacionLabel,
        'links'      => $facturacionLinks,
        'menuLabel'  => 'Facturacion',
        'title'      => 'Panel fiscal, documentos y herramientas de facturacion',
    ];
}

$buildClassName = static function (string ...$classes): string {
    $filtered = array_values(array_filter($classes, static fn(string $class): bool => $class !== ''));
    return implode(' ', $filtered);
};

$renderShortcutAttributes = static function (?string $shortcut, ?string $title = null) use ($esc): string {
    if (!is_string($shortcut) || $shortcut === '') {
        return '';
    }

    $attributes = ' data-shortcut="' . $esc($shortcut) . '"';
    if (is_string($title) && $title !== '') {
        $attributes .= ' title="' . $esc($title . ' (' . strtoupper($shortcut) . ')') . '"';
    }

    return $attributes;
};

$renderPrimaryLink = static function (array $link) use ($buildClassName, $currentSection, $esc, $renderShortcutAttributes): string {
    $isActive = ($currentSection === ($link['section'] ?? ''));
    $shortcut = (string)($link['shortcut'] ?? '');
    $title    = (string)($link['label'] ?? '');

    ob_start();
    ?>
    <a href="<?= $esc((string)$link['href']) ?>"
       class="<?= $esc($buildClassName('nav-pill', $isActive ? 'active' : '')) ?>"
       aria-current="<?= $isActive ? 'page' : 'false' ?>"<?= $renderShortcutAttributes($shortcut, $title) ?>>
        <?= $esc((string)$link['label']) ?>
    </a>
    <?php
    return trim((string)ob_get_clean());
};

$renderGroupLink = static function (array $link) use ($currentSection, $currentFile, $esc, $renderShortcutAttributes): string {
    $activeFiles = $link['active_files'] ?? [];
    $isActive = is_array($activeFiles) && $activeFiles !== []
        ? in_array($currentFile, array_map('strval', $activeFiles), true)
        : ($currentSection === ($link['section'] ?? ''));
    $shortcut = (string)($link['shortcut'] ?? '');

    ob_start();
    ?>
    <a role="menuitem"
       href="<?= $esc((string)$link['href']) ?>"
       class="<?= $isActive ? 'active' : '' ?>"<?= $renderShortcutAttributes($shortcut) ?>>
        <?= $esc((string)$link['label']) ?>
        <?php if ($shortcut !== ''): ?>
            <span class="nav-shortcut-hint"><?= $esc(strtoupper($shortcut)) ?></span>
        <?php endif; ?>
    </a>
    <?php
    return trim((string)ob_get_clean());
};

$renderNavGroup = static function (array $group) use ($buildClassName, $esc, $renderGroupLink): string {
    ob_start();
    ?>
    <div class="nav-dropdown nav-group js-nav-dropdown">
        <button type="button"
                class="<?= $esc($buildClassName('nav-pill', 'nav-group-btn', !empty($group['active']) ? 'active' : '')) ?>"
                aria-haspopup="true"
                aria-expanded="false"
                title="<?= $esc((string)$group['title']) ?>">
            <?= $esc((string)$group['buttonText']) ?>
            <span class="nav-caret" aria-hidden="true">&#9662;</span>
        </button>
        <div class="nav-dropdown-menu nav-group-menu" role="menu" aria-label="<?= $esc((string)$group['menuLabel']) ?>">
            <?php foreach ($group['links'] as $link): ?>
                <?= $renderGroupLink($link) ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return trim((string)ob_get_clean());
};

$renderCajaLink = static function () use ($cajaAbierta, $currentSection): string {
    $isActive = ($currentSection === 'caja');

    ob_start();
    ?>
    <a href="caja.php"
       class="nav-vender <?= $isActive ? 'active' : '' ?>"
       data-shortcut="alt+v"
       aria-label="<?= $cajaAbierta ? 'Ir a caja' : 'Abrir caja' ?>"
       title="Ir a caja (ALT+V)">
        <span class="nav-vender-icon" aria-hidden="true">+</span>
        <span class="nav-vender-label">Vender</span>
        <?php if (!$cajaAbierta): ?>
            <span class="nav-vender-sub">sin caja</span>
        <?php endif; ?>
    </a>
    <?php
    return trim((string)ob_get_clean());
};

$renderAlertsMenu = static function () use ($esc, $navAlertTotal, $navAlerts): string {
    ob_start();
    ?>
    <div class="nav-dropdown js-nav-dropdown" id="navBell">
        <button type="button"
                class="nav-icon nav-bell-btn"
                aria-haspopup="true"
                aria-expanded="false"
                aria-label="<?= $navAlertTotal ?> alertas activas"
                title="Alertas del sistema">
            <span class="nav-bell-icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
            </span>
            <span class="nav-bell-badge" aria-hidden="true">
                <?= $navAlertTotal > 9 ? '9+' : $navAlertTotal ?>
            </span>
        </button>
        <div class="nav-dropdown-menu nav-bell-menu" role="menu" aria-label="Alertas">
            <div class="nav-bell-header">Alertas activas</div>
            <?php foreach ($navAlerts as $alert): ?>
                <a role="menuitem" href="<?= $esc((string)$alert['url']) ?>" class="nav-bell-item">
                    <span class="nav-bell-item-count"><?= (int)$alert['count'] ?></span>
                    <span class="nav-bell-item-label"><?= $esc((string)$alert['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return trim((string)ob_get_clean());
};

$renderAdminMenu = static function () use ($adminActive, $adminLinks, $buildClassName, $esc): string {
    ob_start();
    ?>
    <div class="nav-dropdown js-nav-dropdown" id="adminMenu">
        <button type="button"
                class="<?= $esc($buildClassName('nav-icon', 'nav-dropdown-btn', $adminActive ? 'active' : '')) ?>"
                aria-haspopup="true"
                aria-expanded="false"
                aria-label="Men&uacute; de administraci&oacute;n"
                title="Administraci&oacute;n">
            <span aria-hidden="true">&#9881;</span>
        </button>
        <div class="nav-dropdown-menu" role="menu" aria-label="Administraci&oacute;n">
            <?php foreach ($adminLinks as $link): ?>
                <a role="menuitem" href="<?= $esc((string)$link['href']) ?>" tabindex="0">
                    <?= $esc((string)$link['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return trim((string)ob_get_clean());
};

$renderUserBlock = static function () use ($esc, $initials, $roleDisplay, $userName, $cajaAbierta): string {
    ob_start();
    ?>
    <div class="nav-user-block">
        <div class="nav-avatar" aria-hidden="true"><?= $esc($initials) ?></div>
        <div class="nav-user-info">
            <span class="nav-username" title="<?= $esc($userName) ?>">
                <?= $esc($userName) ?>
            </span>
            <?php if ($roleDisplay !== ''): ?>
                <span class="nav-user-role"><?= $esc($roleDisplay) ?></span>
            <?php endif; ?>
        </div>
        <a href="logout.php"
           class="logout-btn"
           data-logout-link="1"
           data-caja-open="<?= $cajaAbierta ? '1' : '0' ?>"
           aria-label="Cerrar sesi&oacute;n">
            <span class="logout-text">Salir</span>
        </a>
    </div>
    <?php
    return trim((string)ob_get_clean());
};

$renderBreadcrumb = static function (array $items) use ($esc): string {
    ob_start();
    ?>
    <nav class="nav-breadcrumb" aria-label="Ubicaci&oacute;n actual">
        <ol class="breadcrumb-list">
            <li class="breadcrumb-item">
                <a href="index.php" class="breadcrumb-home" aria-label="Inicio">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </a>
            </li>
            <?php foreach ($items as $i => $crumb):
                $isLast = ($i === count($items) - 1);
            ?>
                <li class="breadcrumb-sep" aria-hidden="true">&rsaquo;</li>
                <li class="breadcrumb-item <?= $isLast ? 'breadcrumb-current' : '' ?>">
                    <?php if (!$isLast && !empty($crumb['url'])): ?>
                        <a href="<?= $esc((string)$crumb['url']) ?>"><?= $esc((string)$crumb['label']) ?></a>
                    <?php else: ?>
                        <span aria-current="page"><?= $esc((string)$crumb['label']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <?php
    return trim((string)ob_get_clean());
};

$sectionBreadcrumbLabels = [
    'inicio'              => 'Inicio',
    'dashboard'           => 'Panel',
    'caja'                => 'Caja',
    'ventas'              => 'Ventas',
    'compras'             => 'Compras',
    'productos'           => 'Productos',
    'precios_historial'   => 'Precios',
    'promos'              => 'Promociones',
    'stock'               => 'Stock',
    'inventario_analisis' => "An\u{00E1}lisis",
    'inventario_fisico'   => "Conteo f\u{00ED}sico",
    'reposicion'          => "Reposici\u{00F3}n",
    'movimientos'         => 'Movimientos',
    'clientes'            => 'Clientes',
    'cuenta_corriente'    => 'Cuenta corriente',
    'proveedores'         => 'Proveedores',
    'facturacion'         => "Facturaci\u{00F3}n",
    'configuracion'       => "Configuraci\u{00F3}n",
    'usuarios'            => 'Usuarios',
    'roles'               => 'Roles y permisos',
    'auditoria'           => "Auditor\u{00ED}a",
    'backups'             => 'Backups',
    'diagnostico'         => "Diagn\u{00F3}stico",
    'tecnico'             => "T\u{00E9}cnico",
    'caja_historial'      => 'Historial caja',
];

$promoIsEditing = (int)($_GET['id'] ?? $_POST['id'] ?? 0) > 0;
$autoBreadcrumbsByFile = [
    'caja_movimientos.php' => [
        ['label' => 'Caja', 'url' => 'caja.php'],
        ['label' => 'Movimiento de caja', 'url' => null],
    ],
    'promo_builder.php' => [
        ['label' => 'Promociones', 'url' => 'promos.php'],
        ['label' => 'Nueva promoción', 'url' => null],
    ],
    'promo_form.php' => [
        ['label' => 'Promociones', 'url' => 'promos.php'],
        ['label' => $promoIsEditing ? 'Editar promoción' : 'Nueva promoción', 'url' => null],
    ],
    'promo_combo_form.php' => [
        ['label' => 'Promociones', 'url' => 'promos.php'],
        ['label' => $promoIsEditing ? 'Editar promoción' : 'Nueva promoción', 'url' => null],
    ],
    'usuario_nuevo.php' => [
        ['label' => 'Usuarios', 'url' => 'usuarios.php'],
        ['label' => 'Nuevo usuario', 'url' => null],
    ],
    'usuario_editar.php' => [
        ['label' => 'Usuarios', 'url' => 'usuarios.php'],
        ['label' => 'Editar usuario', 'url' => null],
    ],
    'rol_permisos.php' => [
        ['label' => 'Roles y permisos', 'url' => 'roles.php'],
        ['label' => 'Editar rol', 'url' => null],
    ],
    'cliente_detalle.php' => [
        ['label' => 'Clientes', 'url' => 'clientes.php'],
        ['label' => 'Detalle del cliente', 'url' => null],
    ],
    'cuenta_corriente_cliente.php' => [
        ['label' => 'Cuenta corriente', 'url' => 'cuenta_corriente.php'],
        ['label' => 'Detalle del cliente', 'url' => null],
    ],
    'venta_detalle.php' => [
        ['label' => 'Ventas', 'url' => 'ventas.php'],
        ['label' => 'Detalle de venta', 'url' => null],
    ],
    'factura_ver.php' => [
        ['label' => 'Facturación', 'url' => 'facturacion.php'],
        ['label' => 'Comprobante', 'url' => null],
    ],
    'factura_manual.php' => [
        ['label' => 'Facturación', 'url' => 'facturacion.php'],
        ['label' => 'Factura manual', 'url' => null],
    ],
    'documentos_comerciales.php' => [
        ['label' => 'Facturación', 'url' => 'facturacion.php'],
        ['label' => 'Documentos comerciales', 'url' => null],
    ],
    'documento_comercial.php' => [
        ['label' => 'Facturación', 'url' => 'facturacion.php'],
        ['label' => 'Documentos comerciales', 'url' => 'documentos_comerciales.php'],
        ['label' => 'Documento', 'url' => null],
    ],
    'facturacion_config.php' => [
        ['label' => 'Facturación', 'url' => 'facturacion.php'],
        ['label' => 'Configuración', 'url' => null],
    ],
    'facturacion_nc.php' => [
        ['label' => 'Facturación', 'url' => 'facturacion.php'],
        ['label' => 'Notas de crédito', 'url' => null],
    ],
];

if ($breadcrumb === [] && isset($autoBreadcrumbsByFile[$currentFile])) {
    $breadcrumb = $autoBreadcrumbsByFile[$currentFile];
}

if ($breadcrumb === [] && $currentSection !== '' && $currentSection !== 'inicio') {
    $fallbackLabel = trim((string)($sectionBreadcrumbLabels[$currentSection] ?? ''));
    if ($fallbackLabel === '') {
        $fallbackLabel = ucwords(str_replace('_', ' ', $currentSection));
    }
    if ($fallbackLabel !== '') {
        $breadcrumb = [
            ['label' => $fallbackLabel, 'url' => null],
        ];
    }
}

$primaryNavHtml = array_map($renderPrimaryLink, $primaryLinks);
$groupNavHtml   = array_map($renderNavGroup, $navGroups);
$cajaNavHtml    = $canCaja ? $renderCajaLink() : '';
$alertsNavHtml  = $navAlertTotal > 0 ? $renderAlertsMenu() : '';
$adminNavHtml   = $showAdminMenu ? $renderAdminMenu() : '';
$userBlockHtml  = $renderUserBlock();
$breadcrumbHtml = !empty($breadcrumb) ? $renderBreadcrumb($breadcrumb) : '';
?>

<nav class="nav-container"
     role="navigation"
     aria-label="Navegaci&oacute;n principal"
     data-shortcuts='<?= $esc($shortcutsJson ?: "{}") ?>'
     data-caja-open="<?= $cajaAbierta ? '1' : '0' ?>">
    <a href="index.php" class="nav-brand" aria-label="Inicio">
        <span class="nav-logo">FLUS</span>
    </a>

    <button type="button"
            class="nav-hamburger"
            id="navHamburger"
            aria-label="Men&uacute; de navegaci&oacute;n"
            aria-expanded="false"
            aria-controls="navMenu">
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
    </button>

    <div class="nav-menu-wrapper" id="navMenu">
        <div class="nav-left">
            <?= implode("\n", $primaryNavHtml) ?>
            <?= $cajaNavHtml ?>
            <?= implode("\n", $groupNavHtml) ?>

        </div><!-- /.nav-left -->

        <div class="nav-right">
            <?= $alertsNavHtml ?>

            <button type="button"
                    class="nav-icon nav-shortcuts-btn"
                    id="navShortcutHelpBtn"
                    aria-label="Ver atajos del teclado"
                    title="Atajos del teclado (?)">
                <span aria-hidden="true">?</span>
            </button>

            <div class="theme-switch">
                <input type="checkbox" id="toggleTheme" aria-label="Alternar tema oscuro o claro">
                <label for="toggleTheme" class="theme-toggle">
                    <span class="toggle-track">
                        <span class="toggle-icon toggle-icon--sun" aria-hidden="true">&#9728;</span>
                        <span class="toggle-icon toggle-icon--moon" aria-hidden="true">&#9789;</span>
                    </span>
                    <span class="toggle-thumb"></span>
                </label>
            </div>

            <?= $adminNavHtml ?>
            <?= $userBlockHtml ?>
        </div><!-- /.nav-right -->
    </div><!-- /.nav-menu-wrapper -->
</nav>

<?= $breadcrumbHtml ?>
