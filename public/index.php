<?php
// public/index.php - FLUS v3.2 (2026)
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$pageTitle      = 'Inicio - Sistema Kiosco (FLUS)';
$currentSection = 'inicio';
$bodyClass      = 'page-index';
$extraCss       = ['assets/css/index.css'];

/* =========================================================
   Helpers locales
========================================================= */
if (!function_exists('h')) {
  function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
  }
}

function is_private_or_local_ip(string $ip): bool {
  if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
  return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function is_local_host(string $host): bool {
  $host = strtolower(trim($host));
  if ($host === '') return false;
  if ($host === 'localhost' || $host === '127.0.0.1') return true;
  if (function_exists('str_ends_with') && str_ends_with($host, '.local')) return true;
  return false;
}

/* =========================================================
   Módulos del inicio (filtrables por permiso)
========================================================= */
$modules = [
  // === OPERACIONES ===
  [
    'href'       => 'caja.php',
    'icon'       => '🛒',
    'title'      => 'Caja',
    'desc'       => 'Punto de venta. Escaneo de códigos, cobro y cálculo de vuelto.',
    'tag'        => 'F2 · Módulo principal',
    'color'      => 'green',
    'primary'    => true,
    'perm'       => 'realizar_ventas',
  ],
  [
    'href'       => 'dashboard.php',
    'icon'       => '📊',
    'title'      => 'Dashboard',
    'desc'       => 'Panel de control con métricas y análisis del negocio.',
    'tag'        => 'Análisis',
    'color'      => 'blue',
    'perm'       => 'ver_reportes',
  ],
  [
    'href'       => 'caja_historial.php',
    'icon'       => '📑',
    'title'      => 'Historial de Caja',
    'desc'       => 'Aperturas, cierres, saldos y diferencias por turno.',
    'tag'        => 'Control de cierres',
    'color'      => 'blue',
    'perm'       => 'ver_historial_caja',
  ],

  // === CATÁLOGO ===
  [
    'href'       => 'productos.php',
    'icon'       => '📦',
    'title'      => 'Productos',
    'desc'       => 'Alta y edición de productos, precios, categorías y stock mínimo.',
    'tag'        => 'ABM de artículos',
    'color'      => 'cyan',
    'perm'       => 'editar_productos',
  ],
  [
    'href'       => 'precios_historial.php',
    'icon'       => '💲',
    'title'      => 'Precios',
    'desc'       => 'Historial de cambios de precios y actualizaciones.',
    'tag'        => 'Gestión de precios',
    'color'      => 'cyan',
    'perm'       => 'editar_productos',
  ],
  [
    'href'       => 'promos.php',
    'icon'       => '🎁',
    'title'      => 'Promociones',
    'desc'       => 'Ofertas, descuentos y combos promocionales.',
    'tag'        => 'Marketing',
    'color'      => 'pink',
    'perm'       => 'editar_promos',
  ],

  // === INVENTARIO ===
  [
    'href'       => 'stock.php',
    'icon'       => '📋',
    'title'      => 'Stock',
    'desc'       => 'Stock actual, alertas de stock bajo y sin stock.',
    'tag'        => 'Control de inventario',
    'color'      => 'orange',
    'perm'       => 'editar_stock',
  ],
  [
    'href'       => 'inventario_analisis.php',
    'icon'       => '📈',
    'title'      => 'Inventario',
    'desc'       => 'Análisis ABC, rotación, valorización y productos ancla.',
    'tag'        => 'Análisis avanzado',
    'color'      => 'orange',
    'perm'       => 'ver_reportes',
  ],
  [
    'href'       => 'inventario_fisico.php',
    'icon'       => '📝',
    'title'      => 'Conteo Físico',
    'desc'       => 'Inventario físico, conteos y ajustes de stock.',
    'tag'        => 'Operativo',
    'color'      => 'orange',
    'perm'       => 'editar_stock',
  ],
  [
    'href'       => 'reposicion.php',
    'icon'       => '🔄',
    'title'      => 'Reposición',
    'desc'       => 'Sugerencias de compra basadas en stock y rotación.',
    'tag'        => 'Planificación',
    'color'      => 'orange',
    'perm'       => 'ver_reportes',
  ],
  [
    'href'       => 'movimientos.php',
    'icon'       => '↔️',
    'title'      => 'Movimientos',
    'desc'       => 'Historial de movimientos: ventas, compras y ajustes.',
    'tag'        => 'Kardex de stock',
    'color'      => 'purple',
    'perm'       => 'ver_movimientos',
  ],

  // === COMPRAS ===
  [
    'href'       => 'compras.php',
    'icon'       => '🛍️',
    'title'      => 'Compras',
    'desc'       => 'Registro de compras a proveedores e ingreso de mercadería.',
    'tag'        => 'Ingresos',
    'color'      => 'purple',
    'perm'       => 'editar_stock',
  ],
  [
    'href'       => 'proveedores.php',
    'icon'       => '🏭',
    'title'      => 'Proveedores',
    'desc'       => 'ABM de proveedores y datos de contacto.',
    'tag'        => 'Directorio',
    'color'      => 'purple',
    'perm'       => 'ver_proveedores',
  ],

  // === CLIENTES ===
  [
    'href'       => 'clientes.php',
    'icon'       => '👥',
    'title'      => 'Clientes',
    'desc'       => 'Base de clientes, datos y historial de compras.',
    'tag'        => 'CRM',
    'color'      => 'pink',
    'perm'       => 'ver_clientes',
  ],
  [
    'href'       => 'cuenta_corriente.php',
    'icon'       => '🧮',
    'title'      => 'Cuenta Corriente',
    'desc'       => 'Gestión de fiado, saldos y pagos de clientes.',
    'tag'        => 'Créditos',
    'color'      => 'pink',
    'perm'       => 'ver_cuenta_corriente',
  ],

  // === REPORTES Y FACTURACIÓN ===
  [
    'href'       => 'ventas.php',
    'icon'       => '💰',
    'title'      => 'Ventas',
    'desc'       => 'Listado de tickets, totales por período y detalle.',
    'tag'        => 'Reportes de caja',
    'color'      => 'green',
    'perm'       => 'ver_reportes',
  ],
  [
    'href'       => 'facturacion.php',
    'icon'       => '🧾',
    'title'      => 'Facturación',
    'desc'       => 'Emisión de comprobantes fiscales AFIP/ARCA.',
    'tag'        => 'Fiscal',
    'color'      => 'blue',
    'perm'       => 'ver_facturacion',
  ],
];

/* =========================================================
   Filtrar módulos por permisos
========================================================= */
$modules = array_values(array_filter($modules, static function(array $m): bool {
  if (empty($m['perm'])) return true;
  return function_exists('user_has_permission') ? user_has_permission((string)$m['perm']) : false;
}));

/* =========================================================
   Filtrar módulos opcionales (facturación, etc.)
========================================================= */
$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
  $modules = array_values(array_filter($modules, static function(array $m): bool {
    return ($m['href'] ?? '') !== 'facturacion.php';
  }));
}

/* =========================================================
   Detección local/remoto
========================================================= */
$host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
$ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal = is_local_host($host) || is_private_or_local_ip($ip);

$statusLabel    = $isLocal ? 'Servidor local activo' : 'Servidor remoto';
$statusDotClass = $isLocal ? 'status-dot status-dot-ok' : 'status-dot status-dot-remote';

require_once __DIR__ . '/partials/header.php';
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
    <?php foreach ($modules as $mod): 
      $isPrimary = !empty($mod['primary']);
      $cardClass = $isPrimary ? 'index-card index-card-primary' : 'index-card';
      $colorClass = 'tag-' . ($mod['color'] ?? 'blue');
    ?>
      <a class="<?= h($cardClass) ?>" href="<?= h((string)$mod['href']) ?>">
        <div class="card-icon"><?= $mod['icon'] ?? '📄' ?></div>
        <div class="card-title"><?= h((string)$mod['title']) ?></div>
        <div class="card-desc"><?= h((string)$mod['desc']) ?></div>
        <div class="card-tag <?= h($colorClass) ?>"><?= h((string)$mod['tag']) ?></div>
      </a>
    <?php endforeach; ?>

    <?php if (!$modules): ?>
      <div class="empty-cell">
        No tenés módulos disponibles con tus permisos actuales.
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/partials/footer.php';