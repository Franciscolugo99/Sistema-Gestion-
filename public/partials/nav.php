<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../lib/terminal.php';
require_once __DIR__ . '/../caja_lib.php';

$user = $user ?? (function_exists('current_user') ? current_user() : []);
$pdo = $pdo ?? (function_exists('getPDO') ? getPDO() : null);
$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$groupCurrentLabel = static function (string $fallback, array $links, string $section): string {
    foreach ($links as $link) {
        if (($link['section'] ?? '') === $section) {
            return (string)($link['label'] ?? $fallback);
        }
    }
    return $fallback;
};

$can = function (string $perm) use ($user): bool {
    if (function_exists('user_has_permission')) {
        return user_has_permission($perm);
    }

    $perms = function_exists('session_permissions')
        ? session_permissions()
        : ($_SESSION['permissions'] ?? ($user['permissions'] ?? []));

    if (!is_array($perms)) {
        $perms = [];
    }

    return in_array($perm, $perms, true);
};

$currentSection = $currentSection ?? '';
if ($currentSection === '') {
    $file = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    $map = [
        'index.php' => 'inicio',
        'dashboard.php' => 'dashboard',
        'caja.php' => 'caja',
        'productos.php' => 'productos',
        'stock.php' => 'stock',
        'inventario_analisis.php' => 'inventario_analisis',
        'inventario_fisico.php' => 'inventario_fisico',
        'reposicion.php' => 'reposicion',
        'movimientos.php' => 'movimientos',
        'ventas.php' => 'ventas',
        'venta_detalle.php' => 'ventas',
        'compras.php' => 'compras',
        'proveedores.php' => 'proveedores',
        'caja_historial.php' => 'caja_historial',
        'caja_sesion_detalle.php' => 'caja_historial',
        'caja_sesion_print.php' => 'caja_historial',
        'promos.php' => 'promos',
        'promo_form.php' => 'promos',
        'promo_combo_form.php' => 'promos',
        'clientes.php' => 'clientes',
        'cuenta_corriente.php' => 'cuenta_corriente',
        'cuenta_corriente_cliente.php' => 'cuenta_corriente',
        'cuenta_corriente_print.php' => 'cuenta_corriente',
        'facturacion.php' => 'facturacion',
        'factura_nueva.php' => 'facturacion',
        'factura_manual.php' => 'facturacion',
        'factura_ver.php' => 'facturacion',
        'factura_emitir.php' => 'facturacion',
        'configuracion.php' => 'configuracion',
        'licencia.php' => 'configuracion',
        'usuarios.php' => 'usuarios',
        'usuario_nuevo.php' => 'usuarios',
        'usuario_editar.php' => 'usuarios',
        'auditoria.php' => 'auditoria',
        'backups.php' => 'backups',
        'roles.php' => 'roles',
        'rol_permisos.php' => 'roles',
        'terminales.php' => 'configuracion',
        'diagnostico.php' => 'diagnostico',
        'tecnico.php' => 'tecnico',
        'precios_historial.php' => 'precios_historial',
    ];

    if (isset($map[$file])) {
        $currentSection = $map[$file];
    }
}

$cajaAbierta = false;
$cajaId = 0;
try {
    if ($pdo instanceof PDO) {
        $terminalId = current_terminal_id();
        $cajaRow = $terminalId > 0 ? caja_get_abierta($pdo, $terminalId) : null;
        $cajaAbierta = $cajaRow !== null;
        $cajaId = (int)($cajaRow['id'] ?? 0);
    }
} catch (Throwable $e) {
    $cajaAbierta = false;
}

$canDashboard = $can('ver_reportes');
$canCaja = $can('realizar_ventas');
$canProductos = $can('editar_productos');
$canStock = $can('editar_stock');
$canInventario = $can('editar_stock') || $can('ver_stock') || $can('stock');
$canMovimientos = $can('ver_movimientos');
$canVentas = $can('ver_reportes');
$canCompras = $can('editar_stock');
$canProveedores = $can('ver_proveedores') || $can('editar_proveedores');
$canHistCaja = $can('ver_historial_caja');
$canPromos = $can('editar_promos');
$canClientes = $can('ver_clientes') || $can('editar_clientes');
$canFacturacion = $can('ver_facturacion') || $can('emitir_factura') || $can('administrar_config');

$facturacionHabilitada = true;
if ($pdo instanceof PDO && function_exists('config_get')) {
    $facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
}
$canFacturacion = $canFacturacion && $facturacionHabilitada;

$canCuentaCorriente = $can('ver_cuenta_corriente') && $canClientes;
$canInventarioFisico = $can('editar_stock');
$canPreciosHistorial = $can('editar_productos');
$canReposicion = $can('ver_reportes') || $can('ver_stock') || $can('editar_stock');
$canDiagnostico = function_exists('user_can_access_diagnostics')
    ? user_can_access_diagnostics()
    : ($can('ver_diagnostico') || $can('gestionar_backups'));
$canTecnico = function_exists('user_can_access_technical_panel')
    ? user_can_access_technical_panel()
    : ($can('administrar_config') || $can('gestionar_backups'));

$showAdminMenu =
    $can('administrar_config') ||
    $can('administrar_usuarios') ||
    $can('ver_auditoria') ||
    $can('gestionar_backups') ||
    $canDiagnostico ||
    $canTecnico;

$adminActive = in_array($currentSection, ['configuracion', 'usuarios', 'auditoria', 'backups', 'roles', 'diagnostico', 'tecnico'], true);
$commercialSections = ['productos', 'precios_historial', 'promos', 'clientes', 'proveedores'];
$inventorySections = ['stock', 'inventario_analisis', 'inventario_fisico', 'reposicion', 'movimientos'];
$fiscalSections = ['cuenta_corriente', 'facturacion'];

$commercialLinks = [];
if ($canProductos) $commercialLinks[] = ['href' => 'productos.php', 'section' => 'productos', 'label' => 'Productos'];
if ($canPreciosHistorial) $commercialLinks[] = ['href' => 'precios_historial.php', 'section' => 'precios_historial', 'label' => 'Precios'];
if ($canPromos) $commercialLinks[] = ['href' => 'promos.php', 'section' => 'promos', 'label' => 'Promociones'];
if ($canClientes) $commercialLinks[] = ['href' => 'clientes.php', 'section' => 'clientes', 'label' => 'Clientes'];
if ($canProveedores) $commercialLinks[] = ['href' => 'proveedores.php', 'section' => 'proveedores', 'label' => 'Proveedores'];

$inventoryLinks = [];
if ($canStock) $inventoryLinks[] = ['href' => 'stock.php', 'section' => 'stock', 'label' => 'Stock'];
if ($canInventario) $inventoryLinks[] = ['href' => 'inventario_analisis.php', 'section' => 'inventario_analisis', 'label' => 'Analisis'];
if ($canInventarioFisico) $inventoryLinks[] = ['href' => 'inventario_fisico.php', 'section' => 'inventario_fisico', 'label' => 'Conteo fisico'];
if ($canReposicion) $inventoryLinks[] = ['href' => 'reposicion.php', 'section' => 'reposicion', 'label' => 'Reposicion'];
if ($canMovimientos) $inventoryLinks[] = ['href' => 'movimientos.php', 'section' => 'movimientos', 'label' => 'Movimientos'];

$fiscalLinks = [];
if ($canCuentaCorriente) $fiscalLinks[] = ['href' => 'cuenta_corriente.php', 'section' => 'cuenta_corriente', 'label' => 'Cuenta corriente'];
if ($canFacturacion) $fiscalLinks[] = ['href' => 'facturacion.php', 'section' => 'facturacion', 'label' => 'Facturacion'];

$commercialActive = in_array($currentSection, $commercialSections, true);
$inventoryActive = in_array($currentSection, $inventorySections, true);
$fiscalActive = in_array($currentSection, $fiscalSections, true);
$commercialLabel = $groupCurrentLabel('Comercial', $commercialLinks, $currentSection);
$inventoryLabel = $groupCurrentLabel('Inventario', $inventoryLinks, $currentSection);
$fiscalLabel = $groupCurrentLabel('Fiscal', $fiscalLinks, $currentSection);

$primaryLinks = [];
if ($canDashboard) $primaryLinks[] = ['href' => 'dashboard.php', 'section' => 'dashboard', 'label' => 'Panel'];
if ($canCaja) $primaryLinks[] = ['href' => 'caja.php', 'section' => 'caja', 'label' => 'Caja'];
if ($canVentas) $primaryLinks[] = ['href' => 'ventas.php', 'section' => 'ventas', 'label' => 'Ventas'];
if ($canCompras) $primaryLinks[] = ['href' => 'compras.php', 'section' => 'compras', 'label' => 'Compras'];
if ($canHistCaja) $primaryLinks[] = ['href' => 'caja_historial.php', 'section' => 'caja_historial', 'label' => 'Historial caja'];

$adminLinks = [];
if ($can('administrar_usuarios')) $adminLinks[] = ['href' => 'usuarios.php', 'label' => 'Usuarios'];
if ($can('administrar_usuarios')) $adminLinks[] = ['href' => 'roles.php', 'label' => 'Roles y permisos'];
if ($can('administrar_config')) $adminLinks[] = ['href' => 'configuracion.php', 'label' => 'Configuracion'];
if ($can('administrar_config')) $adminLinks[] = ['href' => 'licencia.php', 'label' => 'Licencia'];
if ($can('administrar_config')) $adminLinks[] = ['href' => 'terminales.php', 'label' => 'Terminales'];
if ($can('ver_auditoria')) $adminLinks[] = ['href' => 'auditoria.php', 'label' => 'Auditoria'];
if ($can('gestionar_backups')) $adminLinks[] = ['href' => 'backups.php', 'label' => 'Backups'];
if ($canDiagnostico) $adminLinks[] = ['href' => 'diagnostico.php', 'label' => 'Diagnostico'];
if ($canTecnico) $adminLinks[] = ['href' => 'tecnico.php', 'label' => 'Tecnico'];
?>

<nav class="nav-container" role="navigation" aria-label="Navegacion principal">
    <a href="index.php" class="nav-brand" aria-label="Inicio">
        <span class="nav-logo">FLUS</span>
    </a>

    <button type="button"
            class="nav-hamburger"
            id="navHamburger"
            aria-label="Menu de navegacion"
            aria-expanded="false"
            aria-controls="navMenu">
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
    </button>

    <div class="nav-menu-wrapper" id="navMenu">
        <div class="nav-left">
            <?php foreach ($primaryLinks as $link): ?>
                <a href="<?= $esc($link['href']) ?>"
                   class="nav-pill <?= $currentSection === $link['section'] ? 'active' : '' ?>"
                   aria-current="<?= $currentSection === $link['section'] ? 'page' : 'false' ?>">
                    <?= $esc($link['label']) ?>
                </a>
            <?php endforeach; ?>

            <?php if ($commercialLinks !== []): ?>
                <div class="nav-dropdown nav-group js-nav-dropdown">
                    <button type="button"
                            class="nav-pill nav-group-btn <?= $commercialActive ? 'active' : '' ?>"
                            aria-haspopup="true"
                            aria-expanded="false"
                            title="Grupo Comercial">
                        <?= $esc($commercialLabel) ?>
                        <span class="nav-caret" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="nav-dropdown-menu nav-group-menu" role="menu" aria-label="Comercial">
                        <?php foreach ($commercialLinks as $link): ?>
                            <a role="menuitem"
                               href="<?= $esc($link['href']) ?>"
                               class="<?= $currentSection === $link['section'] ? 'active' : '' ?>">
                                <?= $esc($link['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($inventoryLinks !== []): ?>
                <div class="nav-dropdown nav-group js-nav-dropdown">
                    <button type="button"
                            class="nav-pill nav-group-btn <?= $inventoryActive ? 'active' : '' ?>"
                            aria-haspopup="true"
                            aria-expanded="false"
                            title="Grupo Inventario">
                        <?= $esc($inventoryLabel) ?>
                        <span class="nav-caret" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="nav-dropdown-menu nav-group-menu" role="menu" aria-label="Inventario">
                        <?php foreach ($inventoryLinks as $link): ?>
                            <a role="menuitem"
                               href="<?= $esc($link['href']) ?>"
                               class="<?= $currentSection === $link['section'] ? 'active' : '' ?>">
                                <?= $esc($link['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($fiscalLinks !== []): ?>
                <div class="nav-dropdown nav-group js-nav-dropdown">
                    <button type="button"
                            class="nav-pill nav-group-btn <?= $fiscalActive ? 'active' : '' ?>"
                            aria-haspopup="true"
                            aria-expanded="false"
                            title="Grupo Fiscal">
                        <?= $esc($fiscalLabel) ?>
                        <span class="nav-caret" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="nav-dropdown-menu nav-group-menu" role="menu" aria-label="Fiscal">
                        <?php foreach ($fiscalLinks as $link): ?>
                            <a role="menuitem"
                               href="<?= $esc($link['href']) ?>"
                               class="<?= $currentSection === $link['section'] ? 'active' : '' ?>">
                                <?= $esc($link['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="nav-right">
            <?php if ($canCaja): ?>
                <div class="badge-mode <?= $cajaAbierta ? 'is-open' : 'is-closed' ?>"
                     title="<?= $esc($cajaAbierta ? "Caja #{$cajaId} abierta" : 'Caja cerrada') ?>">
                    <span class="badge-dot"></span>
                    <span class="badge-text"><?= $cajaAbierta ? 'Caja abierta' : 'Caja cerrada' ?></span>
                </div>
            <?php endif; ?>

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

            <?php if ($showAdminMenu): ?>
                <div class="nav-dropdown js-nav-dropdown" id="adminMenu">
                    <button type="button"
                            class="nav-icon nav-dropdown-btn <?= $adminActive ? 'active' : '' ?>"
                            aria-haspopup="true"
                            aria-expanded="false"
                            aria-label="Menu de administracion"
                            title="Ajustes">
                        <span aria-hidden="true">&#9881;</span>
                    </button>

                    <div class="nav-dropdown-menu" role="menu" aria-label="Administracion">
                        <?php foreach ($adminLinks as $link): ?>
                            <a role="menuitem" href="<?= $esc($link['href']) ?>" tabindex="0">
                                <?= $esc($link['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="nav-user">
                <span class="nav-username" title="Usuario actual">
                    <?= $esc((string)($user['username'] ?? 'Usuario')) ?>
                </span>
                <a href="logout.php" class="logout-btn" aria-label="Cerrar sesion">
                    <span class="logout-text">Salir</span>
                </a>
            </div>
        </div>
    </div>
</nav>

<script>
(() => {
  'use strict';

  const hamburger = document.getElementById('navHamburger');
  const navMenu = document.getElementById('navMenu');
  const dropdowns = Array.from(document.querySelectorAll('.js-nav-dropdown'));
  let scrollY = 0;

  function lockBodyScroll() {
    scrollY = window.scrollY || 0;
    document.documentElement.classList.add('nav-open');
    document.body.style.position = 'fixed';
    document.body.style.top = `-${scrollY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
  }

  function unlockBodyScroll() {
    document.documentElement.classList.remove('nav-open');
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    window.scrollTo(0, scrollY);
  }

  function setNavOpen(open) {
    if (!hamburger || !navMenu) return;
    hamburger.setAttribute('aria-expanded', open ? 'true' : 'false');
    hamburger.classList.toggle('active', open);
    navMenu.classList.toggle('open', open);
    if (open) lockBodyScroll();
    else unlockBodyScroll();
  }

  function setDropdownOpen(wrapper, open) {
    const button = wrapper.querySelector('.nav-dropdown-btn, .nav-group-btn');
    const menu = wrapper.querySelector('.nav-dropdown-menu');
    if (!button || !menu) return;
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    menu.classList.toggle('open', open);
    wrapper.classList.toggle('open', open);
  }

  function closeOtherDropdowns(current) {
    dropdowns.forEach((wrapper) => {
      if (wrapper !== current) setDropdownOpen(wrapper, false);
    });
  }

  if (hamburger && navMenu) {
    hamburger.addEventListener('click', () => {
      const isOpen = hamburger.getAttribute('aria-expanded') === 'true';
      setNavOpen(!isOpen);
    });

    navMenu.addEventListener('click', (event) => {
      const link = event.target.closest('a.nav-pill, .nav-dropdown-menu a');
      if (link) setNavOpen(false);
    });
  }

  dropdowns.forEach((wrapper) => {
    const button = wrapper.querySelector('.nav-dropdown-btn, .nav-group-btn');
    if (!button) return;

    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const isOpen = button.getAttribute('aria-expanded') === 'true';
      closeOtherDropdowns(wrapper);
      setDropdownOpen(wrapper, !isOpen);
    });
  });

  document.addEventListener('click', (event) => {
    const navOpen = hamburger && hamburger.getAttribute('aria-expanded') === 'true';
    if (navOpen && hamburger && navMenu && !hamburger.contains(event.target) && !navMenu.contains(event.target)) {
      setNavOpen(false);
    }

    dropdowns.forEach((wrapper) => {
      if (!wrapper.contains(event.target)) setDropdownOpen(wrapper, false);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setNavOpen(false);
      dropdowns.forEach((wrapper) => setDropdownOpen(wrapper, false));
    }
  });
})();
</script>
