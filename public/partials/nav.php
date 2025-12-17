<?php
// public/partials/nav.php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../caja_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$user = current_user();

// Asegurar PDO (ruta correcta desde /public/partials -> /src/config.php)
if (!isset($pdo) || !($pdo instanceof PDO)) {
  require_once __DIR__ . '/../../src/config.php';
  $pdo = getPDO();
}

// ✅ Si auth.php no expone user_has_permission, mejor fallar explícito
if (!function_exists('user_has_permission')) {
  http_response_code(500);
  die('Falta la función user_has_permission() (revisá auth.php).');
}

// Sección actual (si la página no setea $currentSection)
$currentSection = $currentSection ?? '';
if ($currentSection === '') {
  $file = basename($_SERVER['PHP_SELF']);
  $map = [
    'index.php'            => 'inicio',
    'dashboard.php'        => 'dashboard',
    'caja.php'             => 'caja',
    'productos.php'        => 'productos',
    'stock.php'            => 'stock',
    'movimientos.php'      => 'movimientos',
    'ventas.php'           => 'ventas',
    'compras.php'          => 'compras',          // ✅ NUEVO
    'usuarios.php'         => 'usuarios',
    'usuario_nuevo.php'    => 'usuarios',
    'usuario_editar.php'   => 'usuarios',
    'caja_historial.php'   => 'historial_caja',
    'promos.php'           => 'promos',
    'promo_form.php'       => 'promos',
    'promo_combo_form.php' => 'promos',
    'clientes.php'         => 'clientes',
    'facturacion.php'      => 'facturacion',
    'configuracion.php'    => 'configuracion',

    'venta_detalle.php'    => 'ventas',
    'factura_nueva.php'    => 'facturacion',
    'factura_ver.php'      => 'facturacion',
    'auditoria.php'        => 'auditoria',
    'backups.php'          => 'backups',
  ];
  if (isset($map[$file])) $currentSection = $map[$file];
}

// Caja abierta desde DB
$cajaRow     = caja_get_abierta($pdo);
$cajaAbierta = ($cajaRow !== null);

// Menú Admin (tuerca)
$showAdminMenu = user_has_permission('administrar_config')
  || user_has_permission('administrar_usuarios')
  || user_has_permission('ver_auditoria')
  || user_has_permission('gestionar_backups');

$adminActive = in_array($currentSection, ['configuracion','usuarios','auditoria','backups'], true);

// Permiso para Compras (si todavía no lo creaste, fallback a editar_productos)
$canCompras = user_has_permission('ver_compras') || user_has_permission('editar_productos');
?>

<nav class="nav-container">
  <div class="nav-left">
    <!-- Toast global -->
    <div id="toast" class="toast"></div>

    <a href="index.php" class="nav-pill <?= $currentSection === 'inicio' ? 'active' : '' ?>">
      <span class="dot"></span>
      Inicio
    </a>

    <?php if (user_has_permission('ver_reportes')): ?>
      <a href="dashboard.php" class="nav-pill <?= $currentSection === 'dashboard' ? 'active' : '' ?>">
        Dashboard
      </a>
    <?php endif; ?>

    <?php if (user_has_permission('realizar_ventas')): ?>
      <a href="caja.php" class="nav-pill <?= $currentSection === 'caja' ? 'active' : '' ?>">
        Caja
      </a>
    <?php endif; ?>

    <?php if (user_has_permission('editar_productos')): ?>
      <a href="productos.php" class="nav-pill <?= $currentSection === 'productos' ? 'active' : '' ?>">
        Productos
      </a>
    <?php endif; ?>

    <a href="stock.php" class="nav-pill <?= $currentSection === 'stock' ? 'active' : '' ?>">
      Stock
    </a>

    <?php if (user_has_permission('ver_movimientos')): ?>
      <a href="movimientos.php" class="nav-pill <?= $currentSection === 'movimientos' ? 'active' : '' ?>">
        Movimientos
      </a>
    <?php endif; ?>

    <?php if (user_has_permission('ver_reportes')): ?>
      <a href="ventas.php" class="nav-pill <?= $currentSection === 'ventas' ? 'active' : '' ?>">
        Ventas
      </a>
    <?php endif; ?>

    <?php if ($canCompras): ?>
      <a href="compras.php" class="nav-pill <?= $currentSection === 'compras' ? 'active' : '' ?>">
        Compras
      </a>
    <?php endif; ?>

    <?php if (user_has_permission('ver_historial_caja')): ?>
      <a href="caja_historial.php" class="nav-pill <?= $currentSection === 'historial_caja' ? 'active' : '' ?>">
        Historial caja
      </a>
    <?php endif; ?>

    <a href="promos.php" class="nav-pill <?= $currentSection === 'promos' ? 'active' : '' ?>">
      Promociones
    </a>

    <a href="clientes.php" class="nav-pill <?= $currentSection === 'clientes' ? 'active' : '' ?>">
      Clientes
    </a>

    <a href="facturacion.php" class="nav-pill <?= $currentSection === 'facturacion' ? 'active' : '' ?>">
      Facturación
    </a>
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
          <?php if (user_has_permission('administrar_usuarios')): ?>
            <a role="menuitem" href="usuarios.php">👤 Usuarios</a>
          <?php endif; ?>

          <?php if (user_has_permission('administrar_config')): ?>
            <a role="menuitem" href="configuracion.php">🛠 Configuración</a>
          <?php endif; ?>

          <?php if (user_has_permission('ver_auditoria')): ?>
            <a role="menuitem" href="auditoria.php">🕵️ Auditoría</a>
          <?php endif; ?>

          <?php if (user_has_permission('gestionar_backups')): ?>
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
      <?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
      <a href="logout.php" class="logout-link">Salir</a>
    </div>
  </div>
</nav>
