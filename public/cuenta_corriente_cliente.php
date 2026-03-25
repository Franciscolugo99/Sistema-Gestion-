<?php
// public/cuenta_corriente_cliente.php
// FLUS - Estado de cuenta de un cliente
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/CuentaCorrienteController.php';
require_once __DIR__ . '/../src/cobranzas_lib.php';

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


// Jump a un movimiento (original/reversa) incluso si está en otra página o anulado.
// NOTA: el fragment (#mov-ID) no viaja al servidor, por eso usamos focus_mov y redirigimos.
$focusMov = isset($_GET['focus_mov']) ? (int)$_GET['focus_mov'] : 0;
if ($focusMov > 0) {
    $stFocus = $pdo->prepare("SELECT id, created_at FROM cuenta_corriente_movimientos WHERE id = ? AND cliente_id = ? LIMIT 1");
    $stFocus->execute([$focusMov, $clienteId]);
    $rowFocus = $stFocus->fetch(PDO::FETCH_ASSOC);
    if ($rowFocus) {
        $perPage = (int)($filtros['per_page'] ?? 30);

        // Cantidad de movimientos "más nuevos" (orden DESC por created_at, id) para calcular página
        $stRank = $pdo->prepare("
            SELECT COUNT(*)
            FROM cuenta_corriente_movimientos m
            WHERE m.cliente_id = ?
              AND (m.created_at > ? OR (m.created_at = ? AND m.id > ?))
        ");
        $stRank->execute([$clienteId, $rowFocus['created_at'], $rowFocus['created_at'], (int)$rowFocus['id']]);
        $rank = (int)$stRank->fetchColumn();
        $pageFocus = intdiv($rank, $perPage) + 1;

        // Redirigir a una vista que garantice que el movimiento exista en el listado
        $q = $_GET;
        $q['page'] = $pageFocus;
        $q['per_page'] = $perPage;
        $q['incluir_anulados'] = 1;
        unset($q['tipo'], $q['desde'], $q['hasta'], $q['focus_mov']);
        $url = 'cuenta_corriente_cliente.php' . (empty($q) ? '' : '?' . http_build_query($q)) . '#mov-' . $focusMov;
        header('Location: ' . $url);
        exit;
    }
}

$resultado = $cc->getMovimientos($clienteId, $filtros);
$movimientos = $resultado['movimientos'];

// ── Recibos: enriquecer filas PAGO con cobranza_id, recibo_documento_id y tipo_aplicacion ──
// La tabla cuenta_corriente_movimientos no tiene estos campos; el vínculo está en cobranzas.cc_movimiento_id
$cobranzaByMovId = [];
if (!empty($movimientos) && flus_cobranzas_tables_ready($pdo)) {
    $pagoMovIds = [];
    foreach ($movimientos as $_m) {
        if (($_m['tipo'] ?? '') === 'PAGO') {
            $_mid = (int)($_m['id'] ?? 0);
            if ($_mid > 0) $pagoMovIds[] = $_mid;
        }
    }
    if ($pagoMovIds !== []) {
        $ph = implode(',', array_fill(0, count($pagoMovIds), '?'));
        $selectRecibo = flus_column_exists($pdo, 'cobranzas', 'recibo_documento_id')
            ? 'c.recibo_documento_id'
            : '0 AS recibo_documento_id';
        try {
            $stCob = $pdo->prepare("
                SELECT c.cc_movimiento_id, c.id AS cobranza_id, {$selectRecibo}
                FROM cobranzas c
                WHERE c.cc_movimiento_id IN ({$ph})
            ");
            $stCob->execute($pagoMovIds);
            foreach ($stCob->fetchAll(PDO::FETCH_ASSOC) as $_row) {
                $cobranzaByMovId[(int)$_row['cc_movimiento_id']] = $_row;
            }
        } catch (Throwable $_e) { /* no fatal */ }

        // Agregar tipo_aplicacion desde recibo_aplicaciones si la tabla existe
        if ($cobranzaByMovId !== [] && flus_table_exists($pdo, 'recibo_aplicaciones')) {
            $cobIds = array_values(array_map(fn($_r) => (int)$_r['cobranza_id'], $cobranzaByMovId));
            $ph2 = implode(',', array_fill(0, count($cobIds), '?'));
            try {
                $stApl = $pdo->prepare("
                    SELECT ra.cobranza_id, ra.tipo_aplicacion, ra.factura_id, ra.documento_id
                    FROM recibo_aplicaciones ra
                    WHERE ra.cobranza_id IN ({$ph2})
                    ORDER BY ra.id DESC
                ");
                $stApl->execute($cobIds);
                $_aplByCobId = [];
                foreach ($stApl->fetchAll(PDO::FETCH_ASSOC) as $_apl) {
                    $cid = (int)$_apl['cobranza_id'];
                    if (!isset($_aplByCobId[$cid])) $_aplByCobId[$cid] = $_apl;
                }
                foreach ($cobranzaByMovId as &$_cr) {
                    $cid = (int)($_cr['cobranza_id'] ?? 0);
                    if ($cid > 0 && isset($_aplByCobId[$cid])) {
                        $_cr['tipo_aplicacion'] = $_aplByCobId[$cid]['tipo_aplicacion'];
                        $_cr['factura_id']      = $_aplByCobId[$cid]['factura_id'];
                        $_cr['documento_id']    = $_aplByCobId[$cid]['documento_id'];
                    }
                }
                unset($_cr);
            } catch (Throwable $_e) { /* no fatal */ }
        }
    }
}
unset($pagoMovIds, $ph, $ph2, $stCob, $stApl, $_m, $_mid, $_row, $_apl, $_cr, $_aplByCobId, $_e, $cobIds, $selectRecibo);

// IDs presentes en la página actual (para decidir entre salto local o focus_mov)
$idsOnPage = [];
foreach ($movimientos as $m0) { $idsOnPage[(int)($m0['id'] ?? 0)] = true; }

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
$breadcrumbs = [
    ['label' => 'Clientes', 'url' => 'clientes.php'],
    ['label' => 'Cuenta corriente', 'url' => 'cuenta_corriente.php'],
    ['label' => (string)$cliente['nombre'], 'url' => null],
];
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
      <?php if ($filtros['tipo'] || $filtros['desde'] || $filtros['hasta']): ?>
        <p><a href="<?= urlEstado(['tipo' => null, 'desde' => null, 'hasta' => null, 'page' => null, 'incluir_anulados' => null]) ?>">Limpiar filtros</a> para ver todos los movimientos.</p>
      <?php endif; ?>
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
                  $hrefOrig = isset($idsOnPage[$origId]) ? '#mov-' . $origId : urlEstado(['focus_mov' => $origId]);
                  $concepto .= ' <a class="rel-link" href="' . h($hrefOrig) . '" title="Ir al movimiento original">↩ Original #' . $origId . '</a>';
              } elseif ($esAnulado && isset($reversaByOriginal[$movId])) {
                  $revId = (int)$reversaByOriginal[$movId];
                  $hrefRev = isset($idsOnPage[$revId]) ? '#mov-' . $revId : urlEstado(['focus_mov' => $revId]);
                  $concepto .= ' <a class="rel-link" href="' . h($hrefRev) . '" title="Ir a la reversa asociada">↪ Reversa #' . $revId . '</a>';
              }

              // ── Recibo: badge de tipo + link para movimientos PAGO ──────────────
              if ($tipo === 'PAGO' && isset($cobranzaByMovId[$movId])) {
                  $_cob = $cobranzaByMovId[$movId];
                  $_reciboDocId  = (int)($_cob['recibo_documento_id'] ?? 0);
                  $_tipoApl      = trim((string)($_cob['tipo_aplicacion'] ?? ''));
                  $_tipoAplMeta  = match($_tipoApl) {
                      'SALDO_CC'  => ['Saldo CC',  'badge-info'],
                      'FACTURA'   => ['Factura',   'badge-warning'],
                      'DOCUMENTO' => ['Documento', 'badge-secondary'],
                      default     => ($_tipoApl !== '' ? [$_tipoApl, 'badge-secondary'] : null),
                  };
                  if ($_tipoAplMeta !== null) {
                      $concepto .= ' <span class="badge ' . $_tipoAplMeta[1] . '" title="Tipo de aplicación del pago">' . h($_tipoAplMeta[0]) . '</span>';
                  }
                  if ($_reciboDocId > 0) {
                      $concepto .= ' <span class="rel-link" title="Documento comercial RECIBO generado por la cobranza">Recibo doc #' . $_reciboDocId . '</span>';
                  } elseif ($_tipoApl === '') {
                      $concepto .= ' <span class="text-muted" style="font-size:.82em">(sin recibo)</span>';
                  }
                  if ((int)($_cob['factura_id'] ?? 0) > 0) {
                      $concepto .= ' <a class="rel-link" href="factura_ver.php?id=' . (int)$_cob['factura_id'] . '" title="Ver factura asociada">Factura #' . (int)$_cob['factura_id'] . '</a>';
                  } elseif ((int)($_cob['documento_id'] ?? 0) > 0) {
                      $concepto .= ' <span class="text-muted" style="font-size:.82em">Doc #' . (int)$_cob['documento_id'] . '</span>';
                  }
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
