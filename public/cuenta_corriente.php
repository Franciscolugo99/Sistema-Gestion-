<?php
// public/cuenta_corriente.php
// FLUS - Dashboard de Cuentas Corrientes (Fiado)
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/CuentaCorrienteController.php';

require_login();
require_permission('ver_cuenta_corriente');

$pdo = getPDO();
$cc = new CuentaCorrienteController($pdo);
$canViewClientes = function_exists('user_has_permission') && user_has_permission('ver_clientes');

// Permisos
$canRegistrarPago = user_has_permission('registrar_pago_cc');
$canAjustar = user_has_permission('ajustar_cc');

// Filtros
$filtros = [
    'q' => trim($_GET['q'] ?? ''),
    'estado' => $_GET['estado'] ?? '',
    'orden' => $_GET['orden'] ?? 'saldo_desc',
    'page' => (int)($_GET['page'] ?? 1),
    'per_page' => 25,
];

// Obtener datos
$kpis = $cc->getKPIs();
$resultado = $cc->listarClientesCC($filtros);
$clientes = $resultado['clientes'];
$totalPages = $resultado['pages'];
$currentPage = $resultado['page'];

// URL helper
function urlCC(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return 'cuenta_corriente.php' . (empty($q) ? '' : '?' . http_build_query($q));
}

// Header
$pageTitle = 'Cuentas Corrientes - FLUS';
$currentSection = 'cuenta_corriente';
$breadcrumbs = [
    ['label' => 'Clientes', 'url' => 'clientes.php'],
    ['label' => 'Cuenta corriente', 'url' => null],
];
$extraCss = ['assets/css/cuenta_corriente.css?v=1'];
$extraJs = ['assets/js/cuenta_corriente.js?v=1'];
$bodyClass = 'cuenta-corriente-page';

require __DIR__ . '/partials/header.php';
?>

<div class="panel cc-panel">
  
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- HEADER -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <div class="cc-header page-header module-header">
    <div class="cc-header-left page-header-main module-header-main">
      <div class="module-header-hero">
        <span class="module-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
            <line x1="1" y1="10" x2="23" y2="10"/>
          </svg>
        </span>
        <div class="module-header-copy">
          <span class="module-eyebrow">Credito y cobranza</span>
          <h1 class="page-title">Cuentas Corrientes</h1>
      <p class="cc-subtitle">Gestión de fiado y deudas de clientes</p>
        </div>
      </div>
    </div>
    <div class="cc-header-actions module-header-actions">
      <?php if ($canRegistrarPago): ?>
      <button type="button" class="btn btn-primary" id="btnNuevoPago" data-action="nuevo-pago">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
        </svg>
        Registrar Pago
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- KPIs -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <div class="cc-kpis">
    <div class="kpi-card kpi-primary">
      <div class="kpi-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
        </svg>
      </div>
      <div class="kpi-content">
        <span class="kpi-value"><?= money_ar($kpis['total_deuda']) ?></span>
        <span class="kpi-label">Total Deuda</span>
      </div>
    </div>
    
    <div class="kpi-card">
      <div class="kpi-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
      </div>
      <div class="kpi-content">
        <span class="kpi-value"><?= (int)$kpis['total_clientes_cc'] ?></span>
        <span class="kpi-label">Clientes con CC</span>
      </div>
    </div>
    
    <div class="kpi-card kpi-warning">
      <div class="kpi-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>
      <div class="kpi-content">
        <span class="kpi-value"><?= (int)$kpis['morosos'] ?></span>
        <span class="kpi-label">Morosos (+30 días)</span>
      </div>
    </div>
    
    <div class="kpi-card kpi-danger">
      <div class="kpi-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
      </div>
      <div class="kpi-content">
        <span class="kpi-value"><?= (int)$kpis['excedidos'] ?></span>
        <span class="kpi-label">Excedidos de límite</span>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- FILTROS -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <div class="cc-filters">
    <form method="get" class="filters-form" id="formFiltros" data-cc-filters>
      <div class="filters-left">
        <div class="search-box">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" name="q" placeholder="Buscar cliente..." 
                 value="<?= h($filtros['q']) ?>" class="search-input">
        </div>
        
        <select name="estado" class="filter-select" data-cc-autosubmit>
          <option value="">Todos los estados</option>
          <option value="con_deuda" <?= $filtros['estado'] === 'con_deuda' ? 'selected' : '' ?>>Con deuda</option>
          <option value="al_dia" <?= $filtros['estado'] === 'al_dia' ? 'selected' : '' ?>>Al día</option>
          <option value="morosos" <?= $filtros['estado'] === 'morosos' ? 'selected' : '' ?>>Morosos (+30 días)</option>
          <option value="excedidos" <?= $filtros['estado'] === 'excedidos' ? 'selected' : '' ?>>Excedidos</option>
        </select>
        
        <select name="orden" class="filter-select" data-cc-autosubmit>
          <option value="saldo_desc" <?= $filtros['orden'] === 'saldo_desc' ? 'selected' : '' ?>>Mayor deuda primero</option>
          <option value="saldo_asc" <?= $filtros['orden'] === 'saldo_asc' ? 'selected' : '' ?>>Menor deuda primero</option>
          <option value="nombre" <?= $filtros['orden'] === 'nombre' ? 'selected' : '' ?>>Nombre A-Z</option>
          <option value="ultimo_pago" <?= $filtros['orden'] === 'ultimo_pago' ? 'selected' : '' ?>>Más tiempo sin pagar</option>
        </select>
      </div>
      
      <div class="filters-right">
        <?php if ($filtros['q'] || $filtros['estado']): ?>
        <a href="cuenta_corriente.php" class="btn btn-ghost btn-sm">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
          Limpiar filtros
        </a>
        <?php endif; ?>
        
        <span class="results-count"><?= $resultado['total'] ?> cliente(s)</span>
      </div>
    </form>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- TABLA DE CLIENTES -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <?php if (empty($clientes)): ?>
    <div class="empty-state">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <h3>No hay clientes con cuenta corriente</h3>
      <p>Habilitá la cuenta corriente desde el módulo de Clientes</p>
      <a href="clientes.php" class="btn btn-primary">Ir a Clientes</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="cc-table">
        <thead>
          <tr>
            <th>Cliente</th>
            <th class="t-right">Saldo</th>
            <th class="t-right">Límite</th>
            <th class="t-right">Disponible</th>
            <th class="t-center">Último Pago</th>
            <th class="t-center">Estado</th>
            <th class="t-center col-actions">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clientes as $cli): ?>
            <?php
              $saldo = (float)$cli['cc_saldo'];
              $limite = (float)$cli['cc_limite'];
              $disponible = (float)$cli['cc_disponible'];
              $estado = $cli['estado_cc'];
              $diasSinPago = (int)($cli['dias_sin_pago'] ?? 0);
              
              $rowClass = match($estado) {
                'EXCEDIDO' => 'row-danger',
                'MOROSO' => 'row-warning',
                default => ''
              };
              
              $estadoBadge = match($estado) {
                'EXCEDIDO' => '<span class="badge badge-danger">Excedido</span>',
                'MOROSO' => '<span class="badge badge-warning">Moroso</span>',
                'CON_DEUDA' => '<span class="badge badge-info">Con deuda</span>',
                'AL_DIA' => '<span class="badge badge-success">Al día</span>',
                default => ''
              };
            ?>
            <tr class="<?= $rowClass ?>" data-cliente-id="<?= (int)$cli['id'] ?>">
              <td>
                <div class="cliente-cell">
                  <strong class="cliente-nombre"><?= h($cli['nombre']) ?></strong>
                  <?php if (!empty($cli['telefono'])): ?>
                    <span class="cliente-tel"><?= h($cli['telefono']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="t-right mono saldo-cell <?= $saldo > 0 ? 'text-danger' : '' ?>">
                <?= money_ar($saldo) ?>
              </td>
              <td class="t-right mono"><?= money_ar($limite) ?></td>
              <td class="t-right mono <?= $disponible < 0 ? 'text-danger' : 'text-success' ?>">
                <?= money_ar($disponible) ?>
              </td>
              <td class="t-center">
                <?php if ($cli['cc_fecha_ultimo_pago']): ?>
                  <span class="fecha-pago <?= $diasSinPago > 30 ? 'text-warning' : '' ?>">
                    <?= date('d/m/Y', strtotime($cli['cc_fecha_ultimo_pago'])) ?>
                    <?php if ($diasSinPago > 0): ?>
                      <small class="dias-sin-pago">(<?= $diasSinPago ?>d)</small>
                    <?php endif; ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">Sin pagos</span>
                <?php endif; ?>
              </td>
              <td class="t-center"><?= $estadoBadge ?></td>
              <td class="t-center">
                <div class="row-actions">
                  <?php if ($canViewClientes): ?>
                  <a href="cliente_detalle.php?id=<?= (int)$cli['id'] ?>"
                     class="btn-icon" title="Ver ficha del cliente">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                  </a>
                  <?php endif; ?>
                  <a href="cuenta_corriente_cliente.php?id=<?= (int)$cli['id'] ?>" 
                     class="btn-icon" title="Ver estado de cuenta">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                  </a>
                  <?php if ($canRegistrarPago && $saldo > 0): ?>
                  <button type="button" class="btn-icon btn-icon-success" 
                          data-action="pago-rapido" 
                          data-cliente-id="<?= (int)$cli['id'] ?>"
                          data-cliente-nombre="<?= h($cli['nombre']) ?>"
                          data-saldo="<?= $saldo ?>"
                          title="Registrar pago">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <div class="pagination-info">
        Página <?= $currentPage ?> de <?= $totalPages ?>
      </div>
      <div class="pagination-btns">
        <?php if ($currentPage > 1): ?>
          <a href="<?= urlCC(['page' => $currentPage - 1]) ?>" class="pg-btn">←</a>
        <?php endif; ?>
        
        <?php
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);
        
        if ($start > 1): ?>
          <a href="<?= urlCC(['page' => 1]) ?>" class="pg-btn">1</a>
          <?php if ($start > 2): ?><span class="pg-ellipsis">...</span><?php endif; ?>
        <?php endif;
        
        for ($i = $start; $i <= $end; $i++): ?>
          <a href="<?= urlCC(['page' => $i]) ?>" 
             class="pg-btn <?= $i === $currentPage ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor;
        
        if ($end < $totalPages): ?>
          <?php if ($end < $totalPages - 1): ?><span class="pg-ellipsis">...</span><?php endif; ?>
          <a href="<?= urlCC(['page' => $totalPages]) ?>" class="pg-btn"><?= $totalPages ?></a>
        <?php endif; ?>
        
        <?php if ($currentPage < $totalPages): ?>
          <a href="<?= urlCC(['page' => $currentPage + 1]) ?>" class="pg-btn">→</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- DRAWER: REGISTRAR PAGO -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<?php if ($canRegistrarPago): ?>
<div class="drawer-overlay" id="drawerOverlay"></div>
<div class="drawer" id="drawerPago">
  <div class="drawer-header">
    <h2>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
      </svg>
      Registrar Pago
    </h2>
    <button type="button" class="drawer-close" id="btnCerrarDrawer">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>
  
  <form id="formPago" class="drawer-body">
    <?= csrf_field() ?>
    <input type="hidden" name="cliente_id" id="pagoClienteId">
    
    <div class="drawer-section">
      <label class="drawer-label">Cliente</label>
      <div class="cliente-selected" id="clienteSelected">
        <span class="cliente-nombre" id="pagoClienteNombre">Seleccionar cliente...</span>
        <span class="cliente-saldo" id="pagoClienteSaldo"></span>
      </div>
      
      <!-- Buscador de cliente (si se abre sin cliente preseleccionado) -->
      <div class="cliente-search" id="clienteSearch">
        <input type="text" id="buscarClienteInput" placeholder="Buscar cliente por nombre..." 
               class="form-input" autocomplete="off">
        <div class="cliente-search-results" id="clienteSearchResults"></div>
      </div>
    </div>
    
    <div class="drawer-section">
      <label class="drawer-label" for="pagoMonto">Monto a pagar</label>
      <div class="monto-input-wrap">
        <span class="monto-prefix">$</span>
        <input type="text" id="pagoMonto" name="monto" class="form-input monto-input" 
               placeholder="0,00" required autocomplete="off">
        <button type="button" class="btn btn-sm btn-ghost" id="btnPagarTodo">Todo</button>
      </div>
    </div>
    
    <div class="drawer-section">
      <label class="drawer-label">Medio de pago</label>
      <div class="medio-pago-grid">
        <label class="medio-pago-option">
          <input type="radio" name="medio_pago" value="EFECTIVO" checked>
          <span class="medio-pago-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/>
            </svg>
            Efectivo
          </span>
        </label>
        <label class="medio-pago-option">
          <input type="radio" name="medio_pago" value="TRANSFERENCIA">
          <span class="medio-pago-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/>
              <path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>
            </svg>
            Transferencia
          </span>
        </label>
        <label class="medio-pago-option">
          <input type="radio" name="medio_pago" value="MP">
          <span class="medio-pago-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
            </svg>
            Mercado Pago
          </span>
        </label>
        <label class="medio-pago-option">
          <input type="radio" name="medio_pago" value="DEBITO">
          <span class="medio-pago-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
            </svg>
            Débito
          </span>
        </label>

        <label class="medio-pago-option">
          <input type="radio" name="medio_pago" value="CREDITO">
          <span class="medio-pago-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
              <line x1="6" y1="16" x2="10" y2="16"/>
            </svg>
            Crédito
          </span>
        </label>

        <label class="medio-pago-option">
          <input type="radio" name="medio_pago" value="MODO">
          <span class="medio-pago-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 2v20"/><path d="M2 12h20"/>
            </svg>
            MODO
          </span>
        </label>

        <label class="medio-pago-option">
          <input type="radio" name="medio_pago" value="QR">
          <span class="medio-pago-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
              <path d="M14 14h3v3h-3z"/><path d="M20 14v7h-7"/>
            </svg>
            QR
          </span>
        </label>
      </div>
    </div>

    <div class="drawer-section">
      <label class="drawer-label" for="pagoReferencia">Referencia (opcional)</label>
      <input type="text" id="pagoReferencia" name="referencia" class="form-input" 
             placeholder="Nro. recibo, comprobante, etc.">
    </div>
    
    <div class="drawer-section">
      <label class="drawer-label" for="pagoConcepto">Observaciones (opcional)</label>
      <textarea id="pagoConcepto" name="concepto" class="form-input" rows="2"
                placeholder="Notas adicionales..."></textarea>
    </div>
  </form>
  
  <div class="drawer-footer">
    <button type="button" class="btn btn-ghost" id="btnCancelarPago">Cancelar</button>
    <button type="submit" form="formPago" class="btn btn-primary" id="btnConfirmarPago">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
      Registrar Pago
    </button>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
