<?php
// public/partials/nav.php
declare(strict_types=1);

// ✅ Cargar config primero (timezone, sesiones, helpers, PDO, etc.)
require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/terminal.php';
require_once __DIR__ . '/../caja_lib.php';

// ✅ Asegurar sesión segura (si la tenés en config.php)
if (function_exists('startSecureSession')) {
  startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Usuario actual
$user = current_user();

// PDO siempre disponible
$pdo = getPDO();

// Helper seguro: si no existe user_has_permission, fallamos cerrado (ocultamos links)
function can(string $perm): bool {
  return function_exists('user_has_permission') && user_has_permission($perm);
}

// Sección actual (si la página no setea $currentSection)
$currentSection = $currentSection ?? '';
if ($currentSection === '') {
  $file = basename((string)($_SERVER['PHP_SELF'] ?? ''));

  $map = [
    'index.php'              => 'inicio',
    'dashboard.php'          => 'dashboard',
    'caja.php'               => 'caja',
    'productos.php'          => 'productos',
    'stock.php'              => 'stock',
    'movimientos.php'        => 'movimientos',
    'ventas.php'             => 'ventas',
    'venta_detalle.php'      => 'ventas',

    'compras.php'            => 'compras',

    // ✅ Unificado: caja_historial (en vez de historial_caja)
    'caja_historial.php'     => 'caja_historial',
    'caja_sesion_detalle.php'=> 'caja_historial',
    'caja_sesion_print.php'  => 'caja_historial',

    'promos.php'             => 'promos',
    'promo_form.php'         => 'promos',
    'promo_combo_form.php'   => 'promos',

    'clientes.php'           => 'clientes',

    'facturacion.php'        => 'facturacion',
    'factura_nueva.php'      => 'facturacion',
    'factura_ver.php'        => 'facturacion',
    'factura_emitir.php'     => 'facturacion',

    'configuracion.php'      => 'configuracion',
    'usuarios.php'           => 'usuarios',
    'usuario_nuevo.php'      => 'usuarios',
    'usuario_editar.php'     => 'usuarios',

    'auditoria.php'          => 'auditoria',
    'backups.php'            => 'backups',

    'roles.php'              => 'roles',
    'rol_permisos.php'       => 'roles',
  ];

  if (isset($map[$file])) $currentSection = $map[$file];
}

// Caja abierta desde DB (si falla DB, no rompas el nav)
try {
  $terminalId  = current_terminal_id();
  $cajaRow     = ($terminalId > 0) ? caja_get_abierta($pdo, $terminalId) : null;
  $cajaAbierta = ($cajaRow !== null);
} catch (Throwable $e) {
  $cajaAbierta = false;
}

// Permisos (centralizados)
$canDashboard   = can('ver_reportes');
$canCaja        = can('realizar_ventas');

$canProductos   = can('editar_productos') || can('ver_productos'); // si creás ver_productos
$canStock       = can('editar_stock') || can('ver_stock');         // si creás ver_stock
$canMovimientos = can('ver_movimientos');

// ✅ Ventas: reportes o ventas operativas
$canVentas      = can('ver_reportes') || can('realizar_ventas');

$canCompras     = can('ver_compras') || can('editar_productos');    // fallback actual

// ✅ Historial caja: permiso real
$canHistCaja    = can('ver_historial_caja');

// Promos: ideal tener editar_promos. Si no existe, fallback a editar_productos
$canPromos      = can('editar_promos') || can('editar_productos');

// ✅ Clientes (no mezclar con admin_config por defecto)
$canClientes    = can('ver_clientes') || can('editar_clientes');

// ✅ Facturación (no mezclar con ver_reportes)
$canFacturacion = can('facturacion') || can('administrar_config');

// Admin menu (tuerca)
$showAdminMenu =
  can('administrar_config') ||
  can('administrar_usuarios') ||
  can('ver_auditoria') ||
  can('gestionar_backups') ||
  can('administrar_roles');

$adminActive = in_array($currentSection, ['configuracion','usuarios','auditoria','backups','roles'], true);
?>

<nav class="nav-container">
  <div class="nav-left">
    <!-- Toast global -->
    <div id="toast" class="toast"></div>

    <a href="index.php" class="nav-pill <?= $currentSection === 'inicio' ? 'active' : '' ?>">
      <span class="dot"></span>
      Inicio
    </a>

    <?php if ($canDashboard): ?>
      <a href="dashboard.php" class="nav-pill <?= $currentSection === 'dashboard' ? 'active' : '' ?>">
        Dashboard
      </a>
    <?php endif; ?>

    <?php if ($canCaja): ?>
      <a href="caja.php" class="nav-pill <?= $currentSection === 'caja' ? 'active' : '' ?>">
        Caja
      </a>
    <?php endif; ?>

    <?php if ($canProductos): ?>
      <a href="productos.php" class="nav-pill <?= $currentSection === 'productos' ? 'active' : '' ?>">
        Productos
      </a>
    <?php endif; ?>

    <?php if ($canStock): ?>
      <a href="stock.php" class="nav-pill <?= $currentSection === 'stock' ? 'active' : '' ?>">
        Stock
      </a>
    <?php endif; ?>

    <?php if ($canMovimientos): ?>
      <a href="movimientos.php" class="nav-pill <?= $currentSection === 'movimientos' ? 'active' : '' ?>">
        Movimientos
      </a>
    <?php endif; ?>

    <?php if ($canVentas): ?>
      <a href="ventas.php" class="nav-pill <?= $currentSection === 'ventas' ? 'active' : '' ?>">
        Ventas
      </a>
    <?php endif; ?>

    <?php if ($canCompras): ?>
      <a href="compras.php" class="nav-pill <?= $currentSection === 'compras' ? 'active' : '' ?>">
        Compras
      </a>
    <?php endif; ?>

    <?php if ($canHistCaja): ?>
      <a href="caja_historial.php" class="nav-pill <?= $currentSection === 'caja_historial' ? 'active' : '' ?>">
        Historial caja
      </a>
    <?php endif; ?>

    <?php if ($canPromos): ?>
      <a href="promos.php" class="nav-pill <?= $currentSection === 'promos' ? 'active' : '' ?>">
        Promociones
      </a>
    <?php endif; ?>

    <?php if ($canClientes): ?>
      <a href="clientes.php" class="nav-pill <?= $currentSection === 'clientes' ? 'active' : '' ?>">
        Clientes
      </a>
    <?php endif; ?>

    <?php if ($canFacturacion): ?>
      <a href="facturacion.php" class="nav-pill <?= $currentSection === 'facturacion' ? 'active' : '' ?>">
        Facturación
      </a>
    <?php endif; ?>
  </div>

  <div class="nav-right">

    <?php if ($showAdminMenu): ?>
      <div class="nav-menu" id="adminMenu">
        <button
          type="button"
          class="nav-icon nav-menu-btn <?= $adminActive ? 'active' : '' ?>"
          aria-haspopup="menu"
          aria-expanded="false"
          title="Ajustes"
        >⚙️</button>

        <div class="nav-menu-pop" role="menu" aria-label="Ajustes">
          <?php if (can('administrar_usuarios')): ?>
            <a role="menuitem" href="usuarios.php">👤 Usuarios</a>
          <?php endif; ?>

          <?php if (can('administrar_roles') || can('administrar_usuarios')): ?>
            <a role="menuitem" href="roles.php">🧩 Roles y permisos</a>
          <?php endif; ?>

          <?php if (can('administrar_config')): ?>
            <a role="menuitem" href="configuracion.php">🛠 Configuración</a>
            <a role="menuitem" href="terminales.php">🧾 Terminales / Cajas</a>
          <?php endif; ?>

          <?php if (can('ver_auditoria')): ?>
            <a role="menuitem" href="auditoria.php">🕵️ Auditoría</a>
          <?php endif; ?>

          <?php if (can('gestionar_backups')): ?>
            <a role="menuitem" href="backups.php">💾 Backups</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="theme-switch">
      <input type="checkbox" id="toggleTheme">
      <label for="toggleTheme" class="theme-toggle">
        <span class="toggle-track">
          <span class="toggle-icon toggle-icon--sun">☀</span>
          <span class="toggle-icon toggle-icon--moon">🌙</span>
        </span>
        <span class="toggle-thumb"></span>
      </label>
    </div>

    <div class="badge-mode <?= $cajaAbierta ? 'is-open' : 'is-closed' ?>">
      <span class="badge-dot"></span>
      <?= $cajaAbierta ? 'Caja abierta' : 'Caja cerrada' ?>
    </div>

    <div class="nav-user">
      <?= h((string)($user['username'] ?? '')) ?>
      <a href="logout.php" class="logout-link">Salir</a>
    </div>
  </div>
</nav>
