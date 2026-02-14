<?php
// public/partials/nav.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../lib/terminal.php';
require_once __DIR__ . '/../caja_lib.php';

$user = $user ?? (function_exists('current_user') ? current_user() : []);
$pdo  = $pdo  ?? (function_exists('getPDO') ? getPDO() : null);

/**
 * Chequeo de permisos:
 * 1) user_has_permission() si existe
 * 2) fallback a $_SESSION['permissions']
 */
$can = function (string $perm) use ($user): bool {
  if (function_exists('user_has_permission')) {
    return user_has_permission($perm);
  }
  $perms = function_exists('session_permissions') ? session_permissions() : ($_SESSION['permissions'] ?? ($user['permissions'] ?? []));
  if (!is_array($perms)) $perms = [];
  return in_array($perm, $perms, true);
};

// Sección actual
$currentSection = $currentSection ?? '';
if ($currentSection === '') {
  $file = basename((string)($_SERVER['PHP_SELF'] ?? ''));

  $map = [
    'index.php'               => 'inicio',
    'dashboard.php'           => 'dashboard',
    'caja.php'                => 'caja',
    'productos.php'           => 'productos',
    'stock.php'               => 'stock',
    'inventario_analisis.php' => 'inventario_analisis',
    'movimientos.php'         => 'movimientos',
    'ventas.php'              => 'ventas',
    'venta_detalle.php'       => 'ventas',
    'compras.php'             => 'compras',
    'proveedores.php'         => 'proveedores',
    'caja_historial.php'      => 'caja_historial',
    'caja_sesion_detalle.php' => 'caja_historial',
    'caja_sesion_print.php'   => 'caja_historial',
    'promos.php'              => 'promos',
    'promo_form.php'          => 'promos',
    'promo_combo_form.php'    => 'promos',
    'clientes.php'            => 'clientes',

    // ✅ NUEVO: Cuenta Corriente
    // Ajustá estos nombres si tus archivos se llaman distinto
    'cuenta_corriente.php'          => 'cuenta_corriente',
    'cuenta_corriente_cliente.php'  => 'cuenta_corriente',
    'cuenta_corriente_print.php'    => 'cuenta_corriente',

    'facturacion.php'         => 'facturacion',
    'factura_nueva.php'       => 'facturacion',
    'factura_ver.php'         => 'facturacion',
    'factura_emitir.php'      => 'facturacion',
    'configuracion.php'       => 'configuracion',
    'usuarios.php'            => 'usuarios',
    'usuario_nuevo.php'       => 'usuarios',
    'usuario_editar.php'      => 'usuarios',
    'auditoria.php'           => 'auditoria',
    'backups.php'             => 'backups',
    'roles.php'               => 'roles',
    'rol_permisos.php'        => 'roles',
    'terminales.php'          => 'configuracion',

    // Nuevos módulos P1/P2
    'diagnostico.php'         => 'diagnostico',
    'inventario_fisico.php'   => 'inventario_fisico',
    'precios_historial.php'   => 'precios_historial',
    'reposicion.php'          => 'reposicion',
  ];

  if (isset($map[$file])) $currentSection = $map[$file];
}

// Caja abierta
$cajaAbierta = false;
$cajaId = 0;
try {
  if ($pdo instanceof PDO) {
    $terminalId  = current_terminal_id();
    $cajaRow     = ($terminalId > 0) ? caja_get_abierta($pdo, $terminalId) : null;
    $cajaAbierta = ($cajaRow !== null);
    $cajaId = (int)($cajaRow['id'] ?? 0);
  }
} catch (Throwable $e) {
  $cajaAbierta = false;
}

// Permisos
$canDashboard   = $can('ver_reportes');
$canCaja        = $can('realizar_ventas');
$canProductos   = $can('editar_productos') || $can('ver_productos');
$canStock       = $can('editar_stock')     || $can('ver_stock');

// ✅ NUEVO: permiso Inventario (ajustable)
// - Por defecto: si puede ver productos o stock, puede ver Inventario
$canInventario  = $canProductos || $canStock;

$canMovimientos = $can('ver_movimientos');
$canVentas      = $can('ver_reportes')     || $can('realizar_ventas');
$canCompras     = $can('editar_stock')     || $can('ver_costos') || $can('editar_productos');
$canProveedores = $can('ver_proveedores')  || $can('editar_proveedores');
$canHistCaja    = $can('ver_historial_caja');
$canPromos      = $can('editar_promos');
$canClientes    = $can('ver_clientes')     || $can('editar_clientes');
$canFacturacion = $can('ver_facturacion')  || $can('emitir_factura') || $can('administrar_config');

// ✅ NUEVO: Cuenta Corriente (elegí el criterio que querés)
// Opción simple (recomendada): visible si puede ver clientes y cuenta corriente
$canCuentaCorriente = $can('ver_cuenta_corriente') && $canClientes;

// Si querés que aparezca también si tiene permisos operativos, podés usar esto en lugar de lo anterior:
// $canCuentaCorriente = $can('ver_cuenta_corriente') || $can('registrar_pago_cc') || $can('registrar_cargo_cc');

// Nuevos módulos P1/P2
$canInventarioFisico = $can('editar_stock');
$canPreciosHistorial = $can('editar_productos');
$canReposicion       = $can('ver_reportes') || $can('ver_stock') || $can('editar_stock');
$canDiagnostico      = $can('gestionar_backups');

$showAdminMenu =
  $can('administrar_config') ||
  $can('administrar_usuarios') ||
  $can('ver_auditoria') ||
  $can('gestionar_backups') ||
  $canDiagnostico;

$adminActive = in_array($currentSection, ['configuracion','usuarios','auditoria','backups','roles','diagnostico'], true);

?>

<nav class="nav-container" role="navigation" aria-label="Navegación principal">

  <!-- Logo / Marca (opcional) -->
  <a href="index.php" class="nav-brand" aria-label="Inicio">
    <span class="nav-logo">FLUS</span>
  </a>

  <!-- Botón hamburguesa (mobile) -->
  <button type="button"
          class="nav-hamburger"
          id="navHamburger"
          aria-label="Menú de navegación"
          aria-expanded="false"
          aria-controls="navMenu">
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
  </button>

  <!-- Menú principal -->
  <div class="nav-menu-wrapper" id="navMenu">
    <div class="nav-left">

      <?php if ($canDashboard): ?>
        <a href="dashboard.php"
           class="nav-pill <?= $currentSection === 'dashboard' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'dashboard' ? 'page' : 'false' ?>">
          📊 Panel de control
        </a>
      <?php endif; ?>

      <?php if ($canCaja): ?>
        <a href="caja.php"
           class="nav-pill <?= $currentSection === 'caja' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'caja' ? 'page' : 'false' ?>">
          🛒 Caja
        </a>
      <?php endif; ?>

      <?php if ($canProductos): ?>
        <a href="productos.php"
           class="nav-pill <?= $currentSection === 'productos' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'productos' ? 'page' : 'false' ?>">
          📦 Productos
        </a>
      <?php endif; ?>

      <!-- Historial de Precios -->
      <?php if ($canPreciosHistorial): ?>
        <a href="precios_historial.php"
           class="nav-pill <?= $currentSection === 'precios_historial' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'precios_historial' ? 'page' : 'false' ?>">
          💲 Precios
        </a>
      <?php endif; ?>

      <?php if ($canStock): ?>
        <a href="stock.php"
           class="nav-pill <?= $currentSection === 'stock' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'stock' ? 'page' : 'false' ?>">
          📋 Stock
        </a>
      <?php endif; ?>

      <!-- ✅ NUEVO: Inventario -->
      <?php if ($canInventario): ?>
        <a href="inventario_analisis.php"
           class="nav-pill <?= $currentSection === 'inventario_analisis' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'inventario_analisis' ? 'page' : 'false' ?>">
          📦 Inventario
        </a>
      <?php endif; ?>

      <!-- Inventario Físico (conteo) -->
      <?php if ($canInventarioFisico): ?>
        <a href="inventario_fisico.php"
           class="nav-pill <?= $currentSection === 'inventario_fisico' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'inventario_fisico' ? 'page' : 'false' ?>">
          📝 Conteo Físico
        </a>
      <?php endif; ?>

      <!-- Reposición Sugerida -->
      <?php if ($canReposicion): ?>
        <a href="reposicion.php"
           class="nav-pill <?= $currentSection === 'reposicion' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'reposicion' ? 'page' : 'false' ?>">
          📦 Reposición
        </a>
      <?php endif; ?>

      <?php if ($canMovimientos): ?>
        <a href="movimientos.php"
           class="nav-pill <?= $currentSection === 'movimientos' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'movimientos' ? 'page' : 'false' ?>">
          🔄 Movimientos
        </a>
      <?php endif; ?>

      <?php if ($canVentas): ?>
        <a href="ventas.php"
           class="nav-pill <?= $currentSection === 'ventas' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'ventas' ? 'page' : 'false' ?>">
          💰 Ventas
        </a>
      <?php endif; ?>

      <?php if ($canCompras): ?>
        <a href="compras.php"
           class="nav-pill <?= $currentSection === 'compras' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'compras' ? 'page' : 'false' ?>">
          🛍️ Compras
        </a>
      <?php endif; ?>

      <?php if ($canProveedores): ?>
        <a href="proveedores.php"
           class="nav-pill <?= $currentSection === 'proveedores' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'proveedores' ? 'page' : 'false' ?>">
          🏭 Proveedores
        </a>
      <?php endif; ?>

      <?php if ($canHistCaja): ?>
        <a href="caja_historial.php"
           class="nav-pill <?= $currentSection === 'caja_historial' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'caja_historial' ? 'page' : 'false' ?>">
          📜 Historial
        </a>
      <?php endif; ?>

      <?php if ($canPromos): ?>
        <a href="promos.php"
           class="nav-pill <?= $currentSection === 'promos' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'promos' ? 'page' : 'false' ?>">
          🎁 Promos
        </a>
      <?php endif; ?>

      <?php if ($canClientes): ?>
        <a href="clientes.php"
           class="nav-pill <?= $currentSection === 'clientes' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'clientes' ? 'page' : 'false' ?>">
          👥 Clientes
        </a>
      <?php endif; ?>

      <!-- ✅ NUEVO: Cuenta Corriente -->
      <?php if ($canCuentaCorriente): ?>
        <a href="cuenta_corriente.php"
           class="nav-pill <?= $currentSection === 'cuenta_corriente' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'cuenta_corriente' ? 'page' : 'false' ?>">
          🧮 Cuenta Corriente
        </a>
      <?php endif; ?>

      <?php if ($canFacturacion): ?>
        <a href="facturacion.php"
           class="nav-pill <?= $currentSection === 'facturacion' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'facturacion' ? 'page' : 'false' ?>">
          🧾 Facturación
        </a>
      <?php endif; ?>
    </div>

    <div class="nav-right">

      <!-- Badge caja -->
      <?php if ($canCaja): ?>
        <div class="badge-mode <?= $cajaAbierta ? 'is-open' : 'is-closed' ?>"
             title="<?= $cajaAbierta ? "Caja #{$cajaId} abierta" : 'Caja cerrada' ?>">
          <span class="badge-dot"></span>
          <span class="badge-text">
            <?= $cajaAbierta ? 'Caja abierta' : 'Caja cerrada' ?>
          </span>
        </div>
      <?php endif; ?>

      <!-- Theme toggle -->
      <div class="theme-switch">
        <input type="checkbox" id="toggleTheme" aria-label="Alternar tema oscuro/claro">
        <label for="toggleTheme" class="theme-toggle">
          <span class="toggle-track">
            <span class="toggle-icon toggle-icon--sun" aria-hidden="true">☀</span>
            <span class="toggle-icon toggle-icon--moon" aria-hidden="true">🌙</span>
          </span>
          <span class="toggle-thumb"></span>
        </label>
      </div>

      <!-- Menú admin -->
      <?php if ($showAdminMenu): ?>
        <div class="nav-dropdown" id="adminMenu">
          <button type="button"
                  class="nav-icon nav-dropdown-btn <?= $adminActive ? 'active' : '' ?>"
                  aria-haspopup="true"
                  aria-expanded="false"
                  aria-label="Menú de administración"
                  title="Ajustes">
            ⚙️
          </button>

          <div class="nav-dropdown-menu" role="menu" aria-label="Administración">
            <?php if ($can('administrar_usuarios')): ?>
              <a role="menuitem" href="usuarios.php" tabindex="0">
                <span class="menu-icon">👤</span> Usuarios
              </a>
            <?php endif; ?>

            <?php if ($can('administrar_usuarios')): ?>
              <a role="menuitem" href="roles.php" tabindex="0">
                <span class="menu-icon">🔐</span> Roles y permisos
              </a>
            <?php endif; ?>

            <?php if ($can('administrar_config')): ?>
              <a role="menuitem" href="configuracion.php" tabindex="0">
                <span class="menu-icon">🛠</span> Configuración
              </a>
              <a role="menuitem" href="licencia.php" tabindex="0">
                <span class="menu-icon">🔑</span> Licencia
              </a>
              <a role="menuitem" href="terminales.php" tabindex="0">
                <span class="menu-icon">🧾</span> Terminales
              </a>
            <?php endif; ?>

            <?php if ($can('ver_auditoria')): ?>
              <a role="menuitem" href="auditoria.php" tabindex="0">
                <span class="menu-icon">🕵️</span> Auditoría
              </a>
            <?php endif; ?>

            <?php if ($can('gestionar_backups')): ?>
              <a role="menuitem" href="backups.php" tabindex="0">
                <span class="menu-icon">💾</span> Backups
              </a>
            <?php endif; ?>

            <?php if ($canDiagnostico): ?>
              <a role="menuitem" href="diagnostico.php" tabindex="0">
                <span class="menu-icon">🔧</span> Diagnóstico
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Usuario -->
      <div class="nav-user">
        <span class="nav-username" title="Usuario actual">
          <?= h($user['username'] ?? 'Usuario') ?>
        </span>
        <a href="logout.php" class="logout-btn" aria-label="Cerrar sesión">
          <span class="logout-icon">🚪</span>
          <span class="logout-text">Salir</span>
        </a>
      </div>
    </div>
  </div>
</nav>

<script>
(() => {
  'use strict';
// ============================================================================
// NAVBAR INTERACTIVA
// ============================================================================

// Menú hamburguesa (mobile)
// Menú hamburguesa (mobile)
const hamburger = document.getElementById('navHamburger');
const navMenu = document.getElementById('navMenu');

let __scrollY = 0;

function lockBodyScroll() {
  // iOS-safe
  __scrollY = window.scrollY || 0;
  document.documentElement.classList.add('nav-open');

  document.body.style.position = 'fixed';
  document.body.style.top = `-${__scrollY}px`;
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

  window.scrollTo(0, __scrollY);
}

function setNavOpen(open) {
  hamburger.setAttribute('aria-expanded', open ? 'true' : 'false');
  navMenu.classList.toggle('open', open);
  hamburger.classList.toggle('active', open);

  if (open) lockBodyScroll();
  else unlockBodyScroll();
}

if (hamburger && navMenu) {
  hamburger.addEventListener('click', () => {
    const isOpen = hamburger.getAttribute('aria-expanded') === 'true';
    setNavOpen(!isOpen);
  });

  // Cerrar al hacer clic fuera
  document.addEventListener('click', (e) => {
    const isOpen = hamburger.getAttribute('aria-expanded') === 'true';
    if (!isOpen) return;
    if (!hamburger.contains(e.target) && !navMenu.contains(e.target)) {
      setNavOpen(false);
    }
  });

  // Cerrar con ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') setNavOpen(false);
  });

  // Opcional: cerrar al tocar un link del menú (cuando navegás)
  navMenu.addEventListener('click', (e) => {
    const a = e.target.closest('a.nav-pill');
    if (a) setNavOpen(false);
  });
}
// ============================================================================
// DROPDOWN ADMIN (TUERCA)
// ============================================================================
const adminWrap = document.getElementById('adminMenu');
if (adminWrap) {
  const btn = adminWrap.querySelector('.nav-dropdown-btn');
  const menu = adminWrap.querySelector('.nav-dropdown-menu');

  const setAdminOpen = (open) => {
    if (!btn || !menu) return;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    menu.classList.toggle('open', open);
  };

  if (btn && menu) {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const isOpen = btn.getAttribute('aria-expanded') === 'true';
      setAdminOpen(!isOpen);
    });

    // click afuera => cerrar
    document.addEventListener('click', (e) => {
      const isOpen = btn.getAttribute('aria-expanded') === 'true';
      if (!isOpen) return;
      if (!adminWrap.contains(e.target)) setAdminOpen(false);
    });

    // ESC => cerrar
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') setAdminOpen(false);
    });
  }
}

})();
</script>
