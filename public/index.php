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

function has_any_permission(array $permissions): bool {
  foreach ($permissions as $permission) {
    if (function_exists('user_has_permission') && user_has_permission((string)$permission)) {
      return true;
    }
  }

  return false;
}

$canProductosView = has_any_permission(['editar_productos', 'ver_productos']);
$canProductosEdit = function_exists('user_has_permission') && user_has_permission('editar_productos');
$productosHref = $canProductosEdit ? 'productos.php' : 'productos_consulta.php';

$canStockView = has_any_permission(['editar_stock', 'ver_stock']);
$canStockEdit = function_exists('user_has_permission') && user_has_permission('editar_stock');
$stockHref = $canStockEdit ? 'stock.php' : 'stock_consulta.php';

$canInventarioAnalisis = has_any_permission(['ver_reportes', 'editar_stock']);
$canReposicion = has_any_permission(['ver_reportes', 'editar_stock']);

function index_icon_svg(string $icon): string {
  $svgOpen = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
  $svgClose = '</svg>';

  switch ($icon) {
    case 'cart':
      return $svgOpen . '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h8.7a2 2 0 0 0 1.9-1.4L23 7H7"/>' . $svgClose;
    case 'cash':
      return $svgOpen . '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>' . $svgClose;
    case 'bag':
      return $svgOpen . '<path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/>' . $svgClose;
    case 'chart':
      return $svgOpen . '<path d="M4 19V9"/><path d="M10 19V5"/><path d="M16 19v-7"/><path d="M22 19v-11"/>' . $svgClose;
    case 'box':
      return $svgOpen . '<path d="m12 3 8 4.5-8 4.5-8-4.5L12 3Z"/><path d="M4 7.5V16.5L12 21l8-4.5V7.5"/><path d="M12 12v9"/>' . $svgClose;
    case 'layers':
      return $svgOpen . '<path d="m12 4 8 4-8 4-8-4 8-4Z"/><path d="m4 12 8 4 8-4"/><path d="m4 16 8 4 8-4"/>' . $svgClose;
    case 'tag':
      return $svgOpen . '<path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="9" cy="9" r="1.2"/>' . $svgClose;
    case 'archive':
      return $svgOpen . '<rect x="3" y="5" width="18" height="4" rx="1"/><path d="M5 9h14v9a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V9Z"/><path d="M10 13h4"/>' . $svgClose;
    case 'clipboard':
      return $svgOpen . '<rect x="6" y="4" width="12" height="16" rx="2"/><path d="M9 4.5h6v3H9z"/><path d="M9 11h6"/><path d="M9 15h6"/>' . $svgClose;
    case 'scan':
      return $svgOpen . '<path d="M4 7V5a2 2 0 0 1 2-2h2"/><path d="M20 7V5a2 2 0 0 0-2-2h-2"/><path d="M4 17v2a2 2 0 0 0 2 2h2"/><path d="M20 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 12h10"/>' . $svgClose;
    case 'refresh':
      return $svgOpen . '<path d="M20 7v5h-5"/><path d="M4 17v-5h5"/><path d="M6.5 9A7 7 0 0 1 18 7"/><path d="M17.5 15A7 7 0 0 1 6 17"/>' . $svgClose;
    case 'swap':
      return $svgOpen . '<path d="M17 3h4v4"/><path d="M21 3l-7 7"/><path d="M7 21H3v-4"/><path d="M3 21l7-7"/>' . $svgClose;
    case 'users':
      return $svgOpen . '<path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="3"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 4.1a3 3 0 0 1 0 5.8"/>' . $svgClose;
    case 'calculator':
      return $svgOpen . '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8"/><path d="M8 11h2"/><path d="M12 11h2"/><path d="M16 11h0"/><path d="M8 15h2"/><path d="M12 15h2"/><path d="M16 15h0"/>' . $svgClose;
    case 'receipt':
      return $svgOpen . '<path d="M7 3h10v18l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5-2 1.5V5a2 2 0 0 1 2-2Z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/>' . $svgClose;
    case 'settings':
      return $svgOpen . '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.2a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.2a1.7 1.7 0 0 0 1 1.5h.1a1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.2a1.7 1.7 0 0 0-1.5 1Z"/>' . $svgClose;
    default:
      return $svgOpen . '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>' . $svgClose;
  }
}

/* =========================================================
   Módulos del inicio (filtrables por permiso)
========================================================= */
$modules = [
  [
    'href'       => 'caja.php',
    'icon'       => 'cart',
    'title'      => 'Caja',
    'desc'       => 'Venta rápida, escaneo, cobro y cálculo de vuelto.',
    'tag'        => 'F2 · Módulo principal',
    'color'      => 'green',
    'primary'    => true,
    'perm'       => 'realizar_ventas',
  ],
  [
    'href'       => 'ventas.php',
    'icon'       => 'cash',
    'title'      => 'Ventas',
    'desc'       => 'Tickets, totales por período y detalle de ventas.',
    'tag'        => 'Reportes de caja',
    'color'      => 'green',
    'perm'       => 'ver_reportes',
  ],
  [
    'href'       => 'compras.php',
    'icon'       => 'bag',
    'title'      => 'Compras',
    'desc'       => 'Ingreso de mercadería y registro de compras a proveedores.',
    'tag'        => 'Ingresos',
    'color'      => 'purple',
    'perm'       => 'editar_stock',
  ],
  [
    'href'       => 'dashboard.php',
    'icon'       => 'chart',
    'title'      => 'Dashboard',
    'desc'       => 'Vista general del negocio con métricas y tendencia.',
    'tag'        => 'Análisis',
    'color'      => 'blue',
    'perm'       => 'ver_reportes',
  ],
  [
    'href'       => $productosHref,
    'icon'       => 'box',
    'title'      => 'Productos',
    'desc'       => 'Alta, edición y organización del catálogo.',
    'tag'        => 'ABM de artículos',
    'color'      => 'cyan',
    'visible'    => $canProductosView,
  ],
  [
    'href'       => $stockHref,
    'icon'       => 'archive',
    'title'      => 'Stock',
    'desc'       => 'Control actual, faltantes y alertas de inventario.',
    'tag'        => 'Control de inventario',
    'color'      => 'orange',
    'visible'    => $canStockView,
  ],
  [
    'href'       => 'clientes.php',
    'icon'       => 'users',
    'title'      => 'Clientes',
    'desc'       => 'Base de clientes, datos clave e historial de compras.',
    'tag'        => 'CRM',
    'color'      => 'pink',
    'perm'       => 'ver_clientes',
  ],
  [
    'href'       => 'cuenta_corriente.php',
    'icon'       => 'calculator',
    'title'      => 'Cuenta Corriente',
    'desc'       => 'Fiado, saldos y pagos de clientes en un solo lugar.',
    'tag'        => 'Créditos',
    'color'      => 'pink',
    'perm'       => 'ver_cuenta_corriente',
  ],
  [
    'href'       => 'facturacion.php',
    'icon'       => 'receipt',
    'title'      => 'Facturación',
    'desc'       => 'Comprobantes fiscales AFIP/ARCA y emisión diaria.',
    'tag'        => 'Fiscal',
    'color'      => 'blue',
    'perm'       => 'ver_facturacion',
  ],
  [
    'href'       => 'caja_historial.php',
    'icon'       => 'clipboard',
    'title'      => 'Historial de Caja',
    'desc'       => 'Aperturas, cierres, saldos y diferencias por turno.',
    'tag'        => 'Control de cierres',
    'color'      => 'blue',
    'perm'       => 'ver_historial_caja',
  ],
  [
    'href'       => 'precios_historial.php',
    'icon'       => 'tag',
    'title'      => 'Precios',
    'desc'       => 'Cambios de precios e historial de actualizaciones.',
    'tag'        => 'Gestión de precios',
    'color'      => 'cyan',
    'perm'       => 'editar_productos',
  ],
  [
    'href'       => 'promos.php',
    'icon'       => 'layers',
    'title'      => 'Promociones',
    'desc'       => 'Ofertas, descuentos y combos para impulsar la venta.',
    'tag'        => 'Marketing',
    'color'      => 'pink',
    'perm'       => 'editar_promos',
  ],
  [
    'href'       => 'inventario_analisis.php',
    'icon'       => 'chart',
    'title'      => 'Inventario',
    'desc'       => 'ABC, rotación, valorización y lectura de stock.',
    'tag'        => 'Análisis avanzado',
    'color'      => 'orange',
    'visible'    => $canInventarioAnalisis,
  ],
  [
    'href'       => 'inventario_fisico.php',
    'icon'       => 'scan',
    'title'      => 'Conteo Físico',
    'desc'       => 'Conteos, ajustes y validación del stock real.',
    'tag'        => 'Operativo',
    'color'      => 'orange',
    'perm'       => 'editar_stock',
  ],
  [
    'href'       => 'reposicion.php',
    'icon'       => 'refresh',
    'title'      => 'Reposición',
    'desc'       => 'Sugerencias de compra según stock y rotación.',
    'tag'        => 'Planificación',
    'color'      => 'orange',
    'visible'    => $canReposicion,
  ],
  [
    'href'       => 'movimientos.php',
    'icon'       => 'swap',
    'title'      => 'Movimientos',
    'desc'       => 'Ventas, compras y ajustes en el kardex de stock.',
    'tag'        => 'Kardex de stock',
    'color'      => 'purple',
    'perm'       => 'ver_movimientos',
  ],
  [
    'href'       => 'proveedores.php',
    'icon'       => 'bag',
    'title'      => 'Proveedores',
    'desc'       => 'Directorio y datos base de proveedores y contacto.',
    'tag'        => 'Directorio',
    'color'      => 'purple',
    'perm'       => 'ver_proveedores',
  ],
];

$adminHref = null;
if (function_exists('user_has_permission') && user_has_permission('administrar_usuarios')) {
  $adminHref = 'usuarios.php';
} elseif (function_exists('user_has_permission') && user_has_permission('administrar_config')) {
  $adminHref = 'configuracion.php';
} elseif (function_exists('user_can_access_diagnostics') && user_can_access_diagnostics()) {
  $adminHref = 'diagnostico.php';
} elseif (function_exists('user_can_access_technical_panel') && user_can_access_technical_panel()) {
  $adminHref = 'tecnico.php';
} elseif (has_any_permission(['gestionar_backups', 'ver_auditoria'])) {
  $adminHref = function_exists('user_has_permission') && user_has_permission('gestionar_backups')
    ? 'backups.php'
    : 'roles.php';
}

if ($adminHref !== null) {
  $modules[] = [
    'href'       => $adminHref,
    'icon'       => 'settings',
    'title'      => 'Administración',
    'desc'       => 'Usuarios, configuración y accesos técnicos del sistema.',
    'tag'        => 'Sistema',
    'color'      => 'blue',
    'featured'   => true,
  ];
}

/* =========================================================
   Filtrar módulos por permisos
========================================================= */
$modules = array_values(array_filter($modules, static function(array $m): bool {
  if (array_key_exists('visible', $m)) {
    return (bool)$m['visible'];
  }
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
      <div class="index-copy">
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
      $classes = ['index-card'];
      if (!empty($mod['primary'])) $classes[] = 'index-card-primary';
      if (!empty($mod['featured'])) $classes[] = 'index-card-featured';
      $cardClass = implode(' ', $classes);
      $colorClass = 'tag-' . ($mod['color'] ?? 'blue');
    ?>
      <a class="<?= h($cardClass) ?>" href="<?= h((string)$mod['href']) ?>">
        <div class="card-icon"><?= index_icon_svg((string)($mod['icon'] ?? 'grid')) ?></div>
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
