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
  $perms = $_SESSION['permissions'] ?? ($user['permissions'] ?? []);
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
    'movimientos.php'         => 'movimientos',
    'ventas.php'              => 'ventas',
    'venta_detalle.php'       => 'ventas',
    'compras.php'             => 'compras',
    'caja_historial.php'      => 'caja_historial',
    'caja_sesion_detalle.php' => 'caja_historial',
    'caja_sesion_print.php'   => 'caja_historial',
    'promos.php'              => 'promos',
    'promo_form.php'          => 'promos',
    'promo_combo_form.php'    => 'promos',
    'clientes.php'            => 'clientes',
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
$canMovimientos = $can('ver_movimientos');
$canVentas      = $can('ver_reportes')     || $can('realizar_ventas');
$canCompras     = $can('editar_stock')     || $can('ver_costos') || $can('editar_productos');
$canHistCaja    = $can('ver_historial_caja');
$canPromos      = $can('editar_promos');
$canClientes    = $can('ver_clientes')     || $can('editar_clientes');
$canFacturacion = $can('ver_facturacion')  || $can('emitir_factura') || $can('administrar_config');

$showAdminMenu =
  $can('administrar_config') ||
  $can('administrar_usuarios') ||
  $can('ver_auditoria') ||
  $can('gestionar_backups');

$adminActive = in_array($currentSection, ['configuracion','usuarios','auditoria','backups','roles'], true);

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
          📊 Dashboard
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

      <?php if ($canStock): ?>
        <a href="stock.php" 
           class="nav-pill <?= $currentSection === 'stock' ? 'active' : '' ?>"
           aria-current="<?= $currentSection === 'stock' ? 'page' : 'false' ?>">
          📋 Stock
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
// ============================================================================
// NAVBAR INTERACTIVA
// ============================================================================

// Menú hamburguesa (mobile)
const hamburger = document.getElementById('navHamburger');
const navMenu = document.getElementById('navMenu');

if (hamburger && navMenu) {
  hamburger.addEventListener('click', () => {
    const isOpen = hamburger.getAttribute('aria-expanded') === 'true';
    hamburger.setAttribute('aria-expanded', !isOpen);
    navMenu.classList.toggle('open');
    hamburger.classList.toggle('active');
  });

  // Cerrar al hacer clic fuera
  document.addEventListener('click', (e) => {
    if (!hamburger.contains(e.target) && !navMenu.contains(e.target)) {
      hamburger.setAttribute('aria-expanded', 'false');
      navMenu.classList.remove('open');
      hamburger.classList.remove('active');
    }
  });
}

// Menú dropdown admin
const adminMenuBtn = document.querySelector('#adminMenu .nav-dropdown-btn');
const adminMenuPop = document.querySelector('#adminMenu .nav-dropdown-menu');

if (adminMenuBtn && adminMenuPop) {
  adminMenuBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = adminMenuBtn.getAttribute('aria-expanded') === 'true';
    adminMenuBtn.setAttribute('aria-expanded', !isOpen);
    adminMenuPop.classList.toggle('open');
  });

  // Cerrar al hacer clic fuera
  document.addEventListener('click', (e) => {
    if (!adminMenuBtn.contains(e.target) && !adminMenuPop.contains(e.target)) {
      adminMenuBtn.setAttribute('aria-expanded', 'false');
      adminMenuPop.classList.remove('open');
    }
  });

  // Cerrar con ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && adminMenuPop.classList.contains('open')) {
      adminMenuBtn.setAttribute('aria-expanded', 'false');
      adminMenuPop.classList.remove('open');
      adminMenuBtn.focus();
    }
  });
}

// Theme toggle
const themeToggle = document.getElementById('toggleTheme');
const THEME_KEY = 'kiosco-theme';

function applyTheme(isDark) {
  document.documentElement.classList.toggle('dark', isDark);
  localStorage.setItem(THEME_KEY, isDark ? 'dark' : 'light');
}

// Cargar tema guardado
const savedTheme = localStorage.getItem(THEME_KEY);
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
const isDark = savedTheme ? savedTheme === 'dark' : prefersDark;

if (themeToggle) {
  themeToggle.checked = isDark;
  applyTheme(isDark);

  themeToggle.addEventListener('change', (e) => {
    applyTheme(e.target.checked);
  });
}

// Listener para cambios del sistema
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
  if (!localStorage.getItem(THEME_KEY)) {
    applyTheme(e.matches);
    if (themeToggle) themeToggle.checked = e.matches;
  }
});
</script>