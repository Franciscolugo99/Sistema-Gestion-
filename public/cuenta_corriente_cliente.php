<?php
// public/cuenta_corriente_cliente.php
// FLUS - Estado de cuenta de un cliente
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/CuentaCorrienteController.php';

require_login();
require_permission('ver_cuenta_corriente');

$pdo = getPDO();
$cc = new CuentaCorrienteController($pdo);

// Obtener cliente
$clienteId = sanitize_int($_GET['id'] ?? 0);
if ($clienteId <= 0) {
    redirect('cuenta_corriente.php');
}

$cliente = $cc->getClienteCC($clienteId);
if (!$cliente) {
    redirect('cuenta_corriente.php');
}

// Permisos
$canRegistrarPago = user_has_permission('registrar_pago_cc');
$canAjustar = user_has_permission('ajustar_cc');
$canAnular = user_has_permission('anular_movimiento_cc');
$canRecalcular = user_has_permission('recalcular_saldo_cc');

// Filtros de movimientos
$filtros = [
    'page' => (int)($_GET['page'] ?? 1),
    'per_page' => 30,
    'tipo' => $_GET['tipo'] ?? '',
    'desde' => $_GET['desde'] ?? '',
    'hasta' => $_GET['hasta'] ?? '',
    'incluir_anulados' => isset($_GET['incluir_anulados']),
];

$resultado = $cc->getMovimientos($clienteId, $filtros);
$movimientos = $resultado['movimientos'];
$totalPages = $resultado['pages'];


// Mapear relaciones reversa <-> original para navegación en UI
$reversaByOriginal = [];
$originalByReversa = [];
foreach ($movimientos as $m) {
    $t = $m['tipo'] ?? '';
    if ($t === 'REVERSA' && !empty($m['reversa_de_id'])) {
        $origId = (int)$m['reversa_de_id'];
        $revId  = (int)$m['id'];
        $reversaByOriginal[$origId] = $revId;
        $originalByReversa[$revId] = $origId;
    }
}


// Datos del cliente
$saldo = (float)$cliente['cc_saldo'];
$limite = (float)$cliente['cc_limite'];
$disponible = (float)$cliente['cc_disponible'];

// URL helper
function urlEstado(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return 'cuenta_corriente_cliente.php' . (empty($q) ? '' : '?' . http_build_query($q));
}

// Estado
$estadoCC = match(true) {
    $saldo > $limite => 'EXCEDIDO',
    $saldo > 0 && $cliente['cc_fecha_ultimo_pago'] && 
        (time() - strtotime($cliente['cc_fecha_ultimo_pago'])) > (30 * 86400) => 'MOROSO',
    $saldo > 0 => 'CON_DEUDA',
    default => 'AL_DIA'
};

// Header
$pageTitle = 'Estado de Cuenta: ' . h($cliente['nombre']) . ' - FLUS';
$currentSection = 'cuenta_corriente';
$extraCss = ['assets/css/cuenta_corriente.css?v=1', 'assets/css/cuenta_corriente_cliente.css?v=1'];
$extraJs = ['assets/js/cuenta_corriente.js?v=1'];
$bodyClass = 'cuenta-corriente-page cc-cliente-page';

require __DIR__ . '/partials/header.php';
?>

<div class="panel cc-panel">
  
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- HEADER -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <div class="cc-header">
    <div class="cc-header-left">
      <a href="cuenta_corriente.php" class="back-link">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
        </svg>
        Volver
      </a>
      <h1 class="cc-title">Estado de Cuenta</h1>
    </div>
    <div class="cc-header-actions">
      <a href="cuenta_corriente_print.php?id=<?= $clienteId ?>" class="btn btn-secondary" target="_blank">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
          <rect x="6" y="14" width="12" height="8"/>
        </svg>
        Imprimir
      </a>
      <?php if ($canRegistrarPago && $saldo > 0): ?>
      <button type="button" class="btn btn-primary" 
              data-action="pago-rapido"
              data-cliente-id="<?= $clienteId ?>"
              data-cliente-nombre="<?= h($cliente['nombre']) ?>"
              data-saldo="<?= $saldo ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
        </svg>
        Registrar Pago
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- INFO DEL CLIENTE -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <div class="cliente-info-card">
    <div class="cliente-info-main">
      <div class="cliente-avatar">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
      </div>
      <div class="cliente-datos">
        <h2 class="cliente-nombre"><?= h($cliente['nombre']) ?></h2>
        <div class="cliente-contacto">
          <?php if ($cliente['cuit']): ?>
            <span>CUIT: <?= h($cliente['cuit']) ?></span>
          <?php endif; ?>
          <?php if ($cliente['telefono']): ?>
            <span>Tel: <?= h($cliente['telefono']) ?></span>
          <?php endif; ?>
          <?php if ($cliente['email']): ?>
            <span><?= h($cliente['email']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      
      <?php
        $badgeClass = match($estadoCC) {
            'EXCEDIDO' => 'badge-danger',
            'MOROSO' => 'badge-warning',
            'CON_DEUDA' => 'badge-info',
            default => 'badge-success'
        };
        $badgeText = match($estadoCC) {
            'EXCEDIDO' => 'Excedido',
            'MOROSO' => 'Moroso',
            'CON_DEUDA' => 'Con deuda',
            default => 'Al día'
        };
      ?>
      <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
    </div>
    
    <div class="cliente-saldos">
      <div class="saldo-item saldo-actual">
        <span class="saldo-label">Saldo Actual</span>
        <span class="saldo-value <?= $saldo > 0 ? 'text-danger' : '' ?>"><?= money_ar($saldo) ?></span>
      </div>
      <div class="saldo-item">
        <span class="saldo-label">Límite</span>
        <span class="saldo-value"><?= money_ar($limite) ?></span>
      </div>
      <div class="saldo-item">
        <span class="saldo-label">Disponible</span>
        <span class="saldo-value <?= $disponible < 0 ? 'text-danger' : 'text-success' ?>">
          <?= money_ar($disponible) ?>
        </span>
      </div>
      <?php if ($cliente['cc_fecha_ultimo_pago']): ?>
      <div class="saldo-item">
        <span class="saldo-label">Último Pago</span>
        <span class="saldo-value"><?= date('d/m/Y', strtotime($cliente['cc_fecha_ultimo_pago'])) ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- FILTROS DE MOVIMIENTOS -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <div class="movimientos-header">
    <h3>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
      </svg>
      Movimientos
    </h3>
    
    <form method="get" class="filtros-movimientos">
      <input type="hidden" name="id" value="<?= $clienteId ?>">
      
      <select name="tipo" class="filter-select" onchange="this.form.submit()">
        <option value="">Todos los tipos</option>
        <option value="CARGO" <?= $filtros['tipo'] === 'CARGO' ? 'selected' : '' ?>>Cargos</option>
        <option value="PAGO" <?= $filtros['tipo'] === 'PAGO' ? 'selected' : '' ?>>Pagos</option>
        <option value="AJUSTE_POS" <?= $filtros['tipo'] === 'AJUSTE_POS' ? 'selected' : '' ?>>Ajustes (+)</option>
        <option value="AJUSTE_NEG" <?= $filtros['tipo'] === 'AJUSTE_NEG' ? 'selected' : '' ?>>Ajustes (-)</option>
        <option value="REVERSA" <?= $filtros['tipo'] === 'REVERSA' ? 'selected' : '' ?>>Reversas</option>
      </select>
      
      <input type="date" name="desde" value="<?= h($filtros['desde']) ?>" 
             class="filter-input" placeholder="Desde" onchange="this.form.submit()">
      <input type="date" name="hasta" value="<?= h($filtros['hasta']) ?>" 
             class="filter-input" placeholder="Hasta" onchange="this.form.submit()">
      
      <label class="chk">
        <input type="checkbox" name="incluir_anulados" <?= $filtros['incluir_anulados'] ? 'checked' : '' ?>
               onchange="this.form.submit()">
        <span>Incluir anulados</span>
      </label>
      
      <?php if ($filtros['tipo'] || $filtros['desde'] || $filtros['hasta']): ?>
      <a href="<?= urlEstado(['tipo' => null, 'desde' => null, 'hasta' => null, 'page' => null, 'incluir_anulados' => null]) ?>" 
         class="btn btn-ghost btn-sm">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- TABLA DE MOVIMIENTOS -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <?php if (empty($movimientos)): ?>
    <div class="empty-state">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
      </svg>
      <h3>Sin movimientos</h3>
      <p>No hay movimientos registrados con los filtros seleccionados.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="movimientos-table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Tipo</th>
            <th>Concepto</th>
            <th class="t-right">Debe</th>
            <th class="t-right">Haber</th>
            <th class="t-right">Saldo</th>
            <th>Usuario</th>
            <?php if ($canAnular): ?>
            <th class="t-center">Acc.</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($movimientos as $mov): ?>
            <?php
              $tipo = $mov['tipo'];
              $estado = $mov['estado'];
              $monto = (float)$mov['monto'];
              $saldoPost = (float)$mov['saldo_posterior'];
              $esAnulado = $estado === 'ANULADO';
              
              // Determinar si es debe (aumenta deuda) o haber (reduce deuda)
              $esDebe = in_array($tipo, ['CARGO', 'AJUSTE_POS']);
              if ($tipo === 'REVERSA') {
                  // La reversa hace lo contrario del original
                  // Simplificación: si saldo_posterior < saldo_anterior, fue haber
                  $esDebe = $saldoPost > (float)$mov['saldo_anterior'];
              }
              
              $tipoBadge = match($tipo) {
                  'CARGO' => '<span class="badge badge-danger">Cargo</span>',
                  'PAGO' => '<span class="badge badge-success">Pago</span>',
                  'AJUSTE_POS' => '<span class="badge badge-warning">Ajuste +</span>',
                  'AJUSTE_NEG' => '<span class="badge badge-info">Ajuste -</span>',
                  'REVERSA' => '<span class="badge badge-secondary">Reversa</span>',
                  default => '<span class="badge">' . h($tipo) . '</span>'
              };
              
              $concepto = $mov['concepto'] ?? '-';
              if ($mov['venta_id']) {
                  $concepto = '<a href="venta_detalle.php?id=' . (int)$mov['venta_id'] . '">Venta #' . $mov['venta_id'] . '</a>';
                  if ($mov['concepto'] && $mov['concepto'] !== "Venta #{$mov['venta_id']}") {
                      $concepto .= ' - ' . h($mov['concepto']);
                  }
              }
              if ($mov['referencia']) {
                  $concepto .= ' <small class="text-muted">(Ref: ' . h($mov['referencia']) . ')</small>';
              }

              // Link de navegación entre movimiento original y su reversa (si aplica)
              $movId = (int)$mov['id'];
              if ($tipo === 'REVERSA' && !empty($mov['reversa_de_id'])) {
                  $origId = (int)$mov['reversa_de_id'];
                  $concepto .= ' <a class="rel-link" href="#mov-' . $origId . '" title="Ir al movimiento original">↩ Original #' . $origId . '</a>';
              } elseif ($esAnulado && isset($reversaByOriginal[$movId])) {
                  $revId = (int)$reversaByOriginal[$movId];
                  $concepto .= ' <a class="rel-link" href="#mov-' . $revId . '" title="Ir a la reversa asociada">↪ Reversa #' . $revId . '</a>';
              }

              $rowClass = $esAnulado ? 'row-anulado' : '';
            ?>
            <tr id="mov-<?= (int)$mov['id'] ?>" class="<?= $rowClass ?>">
              <td class="mono nowrap">
                <?= date('d/m/Y H:i', strtotime($mov['created_at'])) ?>
              </td>
              <td>
                <?= $tipoBadge ?>
                <?php if ($esAnulado): ?>
                  <span class="badge badge-anulado">Anulado</span>
                <?php endif; ?>
              </td>
              <td><?= $concepto ?></td>
              <td class="t-right mono text-danger">
                <?= $esDebe ? money_ar($monto) : '' ?>
              </td>
              <td class="t-right mono text-success">
                <?= !$esDebe ? money_ar($monto) : '' ?>
              </td>
              <td class="t-right mono font-bold">
                <?= money_ar($saldoPost) ?>
              </td>
              <td class="text-muted">
                <?= h($mov['usuario_nombre'] ?? '-') ?>
              </td>
              <?php if ($canAnular): ?>
              <td class="t-center">
                <?php if (!$esAnulado && $tipo !== 'REVERSA'): ?>
                <button type="button" class="btn-icon btn-icon-danger btn-sm"
                        data-action="reversar"
                        data-movimiento-id="<?= (int)$mov['id'] ?>"
                        data-tipo="<?= h($tipo) ?>"
                        data-monto="<?= $monto ?>"
                        title="Anular/Reversar">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                  </svg>
                </button>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <div class="pagination-info">
        Página <?= $filtros['page'] ?> de <?= $totalPages ?>
      </div>
      <div class="pagination-btns">
        <?php if ($filtros['page'] > 1): ?>
          <a href="<?= urlEstado(['page' => $filtros['page'] - 1]) ?>" class="pg-btn">←</a>
        <?php endif; ?>
        
        <?php for ($i = max(1, $filtros['page'] - 2); $i <= min($totalPages, $filtros['page'] + 2); $i++): ?>
          <a href="<?= urlEstado(['page' => $i]) ?>" 
             class="pg-btn <?= $i === $filtros['page'] ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        
        <?php if ($filtros['page'] < $totalPages): ?>
          <a href="<?= urlEstado(['page' => $filtros['page'] + 1]) ?>" class="pg-btn">→</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  <?php endif; ?>
  
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- ACCIONES ADICIONALES -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <div class="cc-acciones-extra">
    <?php if ($canAjustar): ?>
    <button type="button" class="btn btn-secondary btn-sm" id="btnAjuste">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
      </svg>
      Registrar Ajuste
    </button>
    <?php endif; ?>
    
    <?php if ($canRecalcular): ?>
    <button type="button" class="btn btn-ghost btn-sm" id="btnRecalcular"
            data-cliente-id="<?= $clienteId ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
      </svg>
      Recalcular Saldo
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- DRAWER PAGO (reutilizado) -->
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
    <input type="hidden" name="cliente_id" id="pagoClienteId" value="<?= $clienteId ?>">
    
    <div class="drawer-section">
      <label class="drawer-label">Cliente</label>
      <div class="cliente-selected" id="clienteSelected" style="display:flex">
        <span class="cliente-nombre" id="pagoClienteNombre"><?= h($cliente['nombre']) ?></span>
        <span class="cliente-saldo" id="pagoClienteSaldo">Saldo: <?= money_ar($saldo) ?></span>
      </div>
      <div class="cliente-search" id="clienteSearch"></div>
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

<!-- ═══════════════════════════════════════════════════════════════════════
     DRAWER: REGISTRAR AJUSTE
═══════════════════════════════════════════════════════════════════════ -->
<?php if ($canAjustar): ?>
<div id="drawerOverlayAjuste" class="drawer-overlay"></div>
<div id="drawerAjuste" class="drawer drawer-right">
  <div class="drawer-header">
    <h3>Registrar Ajuste</h3>
    <button type="button" class="btn-close" id="btnCerrarDrawerAjuste" aria-label="Cerrar">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>
  
  <form id="formAjuste" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
    
    <div class="drawer-section">
      <div class="cliente-mini-info">
        <strong><?= h($cliente['nombre']) ?></strong>
        <span class="text-muted">Saldo actual: <?= money_ar($saldo) ?></span>
      </div>
    </div>
    
    <div class="drawer-section">
      <label class="drawer-label">Tipo de ajuste</label>
      <div class="ajuste-tipo-grid">
        <label class="ajuste-tipo-option">
          <input type="radio" name="tipo_ajuste" value="positivo" checked>
          <span class="ajuste-tipo-label ajuste-positivo">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Aumenta deuda
            <small>Ej: cargo extra, interés</small>
          </span>
        </label>
        <label class="ajuste-tipo-option">
          <input type="radio" name="tipo_ajuste" value="negativo">
          <span class="ajuste-tipo-label ajuste-negativo">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Reduce deuda
            <small>Ej: bonificación, descuento</small>
          </span>
        </label>
      </div>
    </div>
    
    <div class="drawer-section">
      <label class="drawer-label" for="ajusteMonto">Monto *</label>
      <div class="input-group">
        <span class="input-prefix">$</span>
        <input type="text" id="ajusteMonto" name="monto" class="form-input form-input-lg" 
               placeholder="0,00" inputmode="decimal" required>
      </div>
    </div>
    
    <div class="drawer-section">
      <label class="drawer-label" for="ajusteConcepto">Concepto * <span class="text-danger">(obligatorio)</span></label>
      <textarea id="ajusteConcepto" name="concepto" class="form-input" rows="2" 
                placeholder="Describir el motivo del ajuste..." required></textarea>
    </div>
    
    <div class="drawer-section">
      <label class="drawer-label" for="ajusteReferencia">Referencia (opcional)</label>
      <input type="text" id="ajusteReferencia" name="referencia" class="form-input" 
             placeholder="Nro. documento, autorización, etc.">
    </div>
  </form>
  
  <div class="drawer-footer">
    <button type="button" class="btn btn-ghost" id="btnCancelarAjuste">Cancelar</button>
    <button type="submit" form="formAjuste" class="btn btn-warning" id="btnConfirmarAjuste">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
      </svg>
      Registrar Ajuste
    </button>
  </div>
</div>
<?php endif; ?>

<script>
// Pasar el saldo actual al JS
window.currentSaldo = <?= $saldo ?>;
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
