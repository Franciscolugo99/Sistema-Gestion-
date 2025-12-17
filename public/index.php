<?php
// public/index.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pageTitle      = 'Inicio - Sistema Kiosco (FLUS)';
$currentSection = 'inicio';
$bodyClass      = 'page-index';
$extraCss       = ['assets/css/index.css'];

/* =========================================================
   Helpers locales
========================================================= */
function is_private_or_local_ip(string $ip): bool {
  if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;

  // Devuelve FALSE si es privada/reservada (por flags NO_PRIV/NO_RES)
  return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function is_local_host(string $host): bool {
  $host = strtolower(trim($host));
  if ($host === '' ) return false;
  if ($host === 'localhost' || $host === '127.0.0.1') return true;
  if (str_ends_with($host, '.local')) return true;
  return false;
}

/* =========================================================
   Módulos del inicio
   - perm: si lo seteás, se filtra por user_has_permission()
========================================================= */
$modules = [
  [
    'href'       => 'caja.php',
    'title'      => 'Caja',
    'desc'       => 'Punto de venta. Escaneo de códigos, armado de ticket, cobro y cálculo de vuelto.',
    'tag'        => 'F2 · Módulo principal',
    'tag_class'  => 'tag-green',
    'card_class' => 'index-card index-card-primary',
    'perm'       => 'realizar_ventas',
  ],
  [
    'href'       => 'productos.php',
    'title'      => 'Productos',
    'desc'       => 'Alta y edición de productos, precios, categorías y stock mínimo.',
    'tag'        => 'ABM de artículos',
    'tag_class'  => 'tag-green',
    'card_class' => 'index-card',
    'perm'       => 'editar_productos',
  ],
  [
    'href'       => 'stock.php',
    'title'      => 'Stock',
    'desc'       => 'Stock actual, alertas de stock bajo y sin stock. Buscador por código o nombre.',
    'tag'        => 'Control de inventario',
    'tag_class'  => 'tag-orange',
    'card_class' => 'index-card',
    // 'perm'    => 'ver_stock', // (si en tu DB existe, lo activás)
  ],
  [
    'href'       => 'movimientos.php',
    'title'      => 'Movimientos',
    'desc'       => 'Historial de movimientos de stock: ventas, compras y ajustes.',
    'tag'        => 'Kardex de stock',
    'tag_class'  => 'tag-purple',
    'card_class' => 'index-card',
    'perm'       => 'ver_movimientos',
  ],
  [
    'href'       => 'ventas.php',
    'title'      => 'Ventas',
    'desc'       => 'Listado de tickets, totales por período y acceso al detalle de cada venta.',
    'tag'        => 'Reportes de caja',
    'tag_class'  => 'tag-pink',
    'card_class' => 'index-card',
    'perm'       => 'ver_reportes',
  ],
  [
    'href'       => 'caja_historial.php',
    'title'      => 'Historial de caja',
    'desc'       => 'Aperturas y cierres, saldos iniciales, declarados y diferencias por turno.',
    'tag'        => 'Control de cierres',
    'tag_class'  => 'tag-blue',
    'card_class' => 'index-card',
    'perm'       => 'ver_historial_caja',
  ],
];

/* =========================================================
   Filtrar por permisos (si el módulo define 'perm')
========================================================= */
$modules = array_values(array_filter($modules, function(array $m): bool {
  if (empty($m['perm'])) return true;
  return function_exists('user_has_permission') ? user_has_permission((string)$m['perm']) : false;
}));

/* =========================================================
   Detección simple local/remoto
========================================================= */
$host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
$ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');

$isLocal = is_local_host($host) || is_private_or_local_ip($ip);

$statusLabel    = $isLocal ? 'Servidor local activo' : 'Servidor remoto';
$statusDotClass = $isLocal ? 'status-dot status-dot-ok' : 'status-dot status-dot-remote';

require __DIR__ . '/partials/header.php';
?>

<div class="index-panel">

  <header class="index-header">
    <div class="index-header-left">
      <div class="logo-header">
        <img src="img/logo1.png" alt="Logo Sistema" class="logo-sistema">
      </div>
      <div>
        <h1 class="index-title">SISTEMA GESTIÓN</h1>
        <div class="index-subtitle">Panel principal · elegí un módulo para trabajar</div>
      </div>
    </div>

    <div class="status-pill">
      <span class="<?= h($statusDotClass) ?>"></span>
      <?= h($statusLabel) ?>
    </div>
  </header>

  <div class="index-grid">
    <?php foreach ($modules as $mod): ?>
      <a class="<?= h((string)$mod['card_class']) ?>" href="<?= h((string)$mod['href']) ?>">
        <div class="card-title"><?= h((string)$mod['title']) ?></div>
        <div class="card-desc"><?= h((string)$mod['desc']) ?></div>
        <div class="card-tag <?= h((string)$mod['tag_class']) ?>"><?= h((string)$mod['tag']) ?></div>
      </a>
    <?php endforeach; ?>

    <?php if (!$modules): ?>
      <div class="empty-cell" style="grid-column:1/-1;">
        No tenés módulos disponibles con tus permisos.
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
