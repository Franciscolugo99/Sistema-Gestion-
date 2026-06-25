<?php
// public/tesoreria_obligaciones.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/tesoreria_lib.php';

require_login();
require_any_permission(['ver_tesoreria', 'gestionar_tesoreria']);

$error = '';
$ok = '';
$canManage = function_exists('user_has_permission') && user_has_permission('gestionar_tesoreria');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('gestionar_tesoreria');
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'CSRF invalido. Recarga e intenta de nuevo.';
    } else {
        $action = trim((string)($_POST['action'] ?? 'crear'));
        if ($action === 'pagar') {
            $res = flus_tesoreria_pagar_obligacion($pdo, (int)($_POST['obligacion_id'] ?? 0), $_POST);
            $message = 'Obligacion pagada.';
        } else {
            $res = flus_tesoreria_save_obligacion($pdo, $_POST);
            $message = 'Obligacion creada.';
        }
        if (($res['success'] ?? false) === true) {
            header('Location: tesoreria_obligaciones.php?ok=' . urlencode($message));
            exit;
        }
        $error = (string)($res['error'] ?? 'No se pudo completar la operacion.');
    }
}

if (isset($_GET['ok'])) {
    $ok = trim((string)$_GET['ok']);
}

$cuentas = flus_tesoreria_cuentas($pdo);
$categorias = flus_tesoreria_categorias($pdo, 'EGRESO');
$obligaciones = flus_tesoreria_obligaciones($pdo, $_GET);
$estadoFiltro = strtoupper(trim((string)($_GET['estado'] ?? '')));
$proveedorFiltro = max(0, (int)($_GET['proveedor_id'] ?? 0));
$compraFiltro = max(0, (int)($_GET['compra_id'] ?? 0));
$payId = max(0, (int)($_GET['pay_id'] ?? 0));
$obligacionPago = null;
$obligacionesStats = [
    'pendientes' => 0,
    'vencidas' => 0,
    'saldo_total' => 0.0,
    'proximas' => 0,
];
$today = date('Y-m-d');
$limitSoon = date('Y-m-d', strtotime('+30 days'));
foreach ($obligaciones as $ob) {
    $estado = (string)($ob['estado_efectivo'] ?? $ob['estado'] ?? 'PENDIENTE');
    $saldo = max(0.0, (float)($ob['importe_estimado'] ?? 0) - (float)($ob['importe_pagado'] ?? 0));
    if (!in_array($estado, ['PAGADO', 'CANCELADO'], true)) {
        $obligacionesStats['pendientes']++;
        $obligacionesStats['saldo_total'] += $saldo;
    }
    if ($estado === 'VENCIDO') {
        $obligacionesStats['vencidas']++;
    }
    $vto = (string)($ob['fecha_vencimiento'] ?? '');
    if ($vto >= $today && $vto <= $limitSoon && !in_array($estado, ['PAGADO', 'CANCELADO'], true)) {
        $obligacionesStats['proximas']++;
    }
    if ($payId > 0 && (int)($ob['id'] ?? 0) === $payId && !in_array($estado, ['PAGADO', 'CANCELADO'], true)) {
        $obligacionPago = $ob;
        $obligacionPago['saldo_calculado'] = $saldo;
        $obligacionPago['estado_efectivo'] = $estado;
    }
}
$clearPayParams = $_GET;
unset($clearPayParams['pay_id']);
$clearPayUrl = 'tesoreria_obligaciones.php' . ($clearPayParams ? '?' . http_build_query($clearPayParams) : '');

$pageTitle = 'Obligaciones de tesoreria - FLUS';
$currentSection = 'tesoreria';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => 'tesoreria.php'],
    ['label' => 'Obligaciones', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/tesoreria.css?v=4'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page tesoreria-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-copy">
          <span class="module-eyebrow">Vencimientos y pagos pendientes</span>
          <h1 class="page-title module-title">Obligaciones</h1>
          <p class="page-sub module-subtitle">Carga alquileres, impuestos, servicios y otros compromisos antes de pagarlos.</p>
        </div>
      </div>
      <div class="promo-actions-top module-header-actions">
        <a href="tesoreria.php" class="v-btn v-btn--outline">Resumen</a>
        <a href="tesoreria_reportes.php" class="v-btn v-btn--outline">Reportes</a>
      </div>
    </header>

    <?php if ($error !== ''): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

    <section class="tesoreria-obligaciones-summary" aria-label="Resumen de obligaciones">
      <article class="tesoreria-obligation-stat">
        <span>Pendientes</span>
        <strong><?= number_format($obligacionesStats['pendientes']) ?></strong>
        <small><?= money_ar((float)$obligacionesStats['saldo_total']) ?> por pagar</small>
      </article>
      <article class="tesoreria-obligation-stat <?= $obligacionesStats['vencidas'] > 0 ? 'is-danger' : '' ?>">
        <span>Vencidas</span>
        <strong><?= number_format($obligacionesStats['vencidas']) ?></strong>
        <small><?= $obligacionesStats['vencidas'] > 0 ? 'Requieren atencion' : 'Sin atrasos' ?></small>
      </article>
      <article class="tesoreria-obligation-stat <?= $obligacionesStats['proximas'] > 0 ? 'is-warn' : '' ?>">
        <span>Proximas</span>
        <strong><?= number_format($obligacionesStats['proximas']) ?></strong>
        <small>Vencen dentro de 30 dias</small>
      </article>
    </section>

    <?php if ($canManage): ?>
      <details class="tesoreria-create">
        <summary>
          <span>Nueva obligacion</span>
          <strong>Cargar un vencimiento</strong>
        </summary>
      <form method="post" class="filters tesoreria-entry-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="crear">
        <div class="tesoreria-form-head">
          <div class="tesoreria-form-title">
            <span>Alta guiada</span>
            <strong>Nueva obligacion</strong>
            <small>Registrá vencimientos antes de pagarlos y después cancelalos generando el egreso real.</small>
          </div>
          <span class="tesoreria-form-pill">Pendiente hasta registrar pago</span>
        </div>
        <div class="tesoreria-form-grid">
          <label class="tesoreria-field tesoreria-field--large">
            <span>Descripcion</span>
            <input name="descripcion" maxlength="180" required placeholder="Ej: Alquiler local centro">
          </label>
          <label class="tesoreria-field">
            <span>Categoria</span>
            <select name="categoria_id" required>
              <option value="">Seleccionar</option>
              <?php foreach ($categorias as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= h((string)$cat['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tesoreria-field tesoreria-field--short">
            <span>Vencimiento</span>
            <input type="date" name="fecha_vencimiento" value="<?= h(date('Y-m-d')) ?>" required>
          </label>
          <label class="tesoreria-field tesoreria-field--short tesoreria-field--amount">
            <span>Importe estimado</span>
            <input name="importe_estimado" inputmode="decimal" required placeholder="40.000,00">
          </label>
          <label class="tesoreria-field">
            <span>Cuenta sugerida</span>
            <select name="cuenta_sugerida_id">
              <option value="">Sin sugerencia</option>
              <?php foreach ($cuentas as $cuenta): ?>
                <option value="<?= (int)$cuenta['id'] ?>"><?= h((string)$cuenta['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tesoreria-field tesoreria-field--short">
            <span>Sucursal</span>
            <input name="sucursal_nombre" maxlength="120" placeholder="General o sucursal">
          </label>
          <div class="tesoreria-guide">
            <strong>Cuando pagues</strong>
            <span>FLUS crea un egreso vinculado y actualiza el estado de la obligacion para evitar doble carga.</span>
          </div>
          <label class="tesoreria-field tesoreria-field--large">
            <span>Observaciones</span>
            <textarea name="observaciones" maxlength="255"></textarea>
          </label>
          <div class="tesoreria-form-actions">
            <p class="tesoreria-muted-note">Importes: podes usar 40000, 40.000 o 40.000,00.</p>
            <button class="btn btn-primary" type="submit">Crear obligacion</button>
          </div>
        </div>
      </form>
      </details>
    <?php endif; ?>

    <?php if ($canManage && $obligacionPago !== null): ?>
      <?php $saldoPago = (float)($obligacionPago['saldo_calculado'] ?? 0); ?>
      <section class="tesoreria-payment-panel" aria-label="Registrar pago de obligacion">
        <div class="tesoreria-payment-panel__copy">
          <span>Pago seleccionado</span>
          <strong><?= h((string)($obligacionPago['descripcion'] ?? 'Obligacion')) ?></strong>
          <small>
            Vence <?= h(date('d/m/Y', strtotime((string)($obligacionPago['fecha_vencimiento'] ?? 'now')))) ?>,
            saldo <?= h(money_ar($saldoPago)) ?>
          </small>
        </div>
        <form method="post" class="tesoreria-inline-form tesoreria-payment-form js-tes-pay-form" data-saldo="<?= h(number_format($saldoPago, 2, '.', '')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="pagar">
          <input type="hidden" name="obligacion_id" value="<?= (int)$obligacionPago['id'] ?>">
          <input type="hidden" name="request_uid" value="<?= h(bin2hex(random_bytes(16))) ?>">
          <label>
            <span>Cuenta</span>
            <select name="cuenta_origen_id" required>
              <option value="">Elegir</option>
              <?php foreach ($cuentas as $cuenta): ?>
                <option value="<?= (int)$cuenta['id'] ?>" <?= (int)($obligacionPago['cuenta_sugerida_id'] ?? 0) === (int)$cuenta['id'] ? 'selected' : '' ?>><?= h((string)$cuenta['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Pagar monto</span>
            <input name="importe" class="js-tes-pay-amount" inputmode="decimal" value="<?= h(number_format($saldoPago, 2, ',', '.')) ?>" data-saldo="<?= h(number_format($saldoPago, 2, '.', '')) ?>">
            <small class="tesoreria-pay-hint" aria-live="polite">Saldo <?= h(money_ar($saldoPago)) ?></small>
          </label>
          <div class="tesoreria-pay-actions">
            <button class="btn-mini" type="button" data-pay-total="<?= h(number_format($saldoPago, 2, ',', '.')) ?>">Total</button>
            <button class="btn-mini btn-mini--primary" type="submit">Registrar pago</button>
            <a class="btn-mini" href="<?= h($clearPayUrl) ?>">Cancelar</a>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <form method="get" class="filters fact-filters">
      <div class="filters-left">
        <select name="estado">
          <option value="">Todos los estados</option>
          <?php foreach (['PENDIENTE' => 'Pendientes', 'VENCIDO' => 'Vencidas', 'PARCIAL' => 'Parciales', 'PAGADO' => 'Pagadas', 'CANCELADO' => 'Canceladas'] as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $estadoFiltro === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="categoria_id">
          <option value="">Todas las categorias</option>
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= (int)($_GET['categoria_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= h((string)$cat['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($proveedorFiltro > 0): ?><input type="hidden" name="proveedor_id" value="<?= (int)$proveedorFiltro ?>"><?php endif; ?>
        <?php if ($compraFiltro > 0): ?><input type="hidden" name="compra_id" value="<?= (int)$compraFiltro ?>"><?php endif; ?>
        <input name="sucursal_nombre" value="<?= h((string)($_GET['sucursal_nombre'] ?? '')) ?>" placeholder="Sucursal">
      </div>
      <div class="filters-right">
        <button class="btn btn-filter" type="submit">Aplicar</button>
        <a href="tesoreria_obligaciones.php" class="btn btn-secondary">Limpiar</a>
      </div>
    </form>

    <div class="table-wrapper">
      <table class="mov-table fact-table">
        <thead>
          <tr>
            <th>Vence</th>
            <th>Obligacion</th>
            <th>Origen</th>
            <th>Categoria</th>
            <th>Sucursal</th>
            <th class="t-right">Importe</th>
            <th class="t-right">Pagado</th>
            <th class="t-right">Saldo</th>
            <th>Estado</th>
            <th>Pago</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($obligaciones === []): ?>
            <tr><td colspan="10" class="muted">No hay obligaciones para esta vista.</td></tr>
          <?php else: ?>
            <?php foreach ($obligaciones as $ob): ?>
              <?php
                $estado = (string)($ob['estado_efectivo'] ?? $ob['estado'] ?? 'PENDIENTE');
                $estadoClass = $estado === 'PAGADO' ? 'tesoreria-status--ok' : ($estado === 'VENCIDO' ? 'tesoreria-status--danger' : ($estado === 'PARCIAL' ? 'tesoreria-status--warn' : ''));
                $saldo = max(0.0, (float)$ob['importe_estimado'] - (float)$ob['importe_pagado']);
                $payParams = $_GET;
                $payParams['pay_id'] = (int)$ob['id'];
                $payUrl = 'tesoreria_obligaciones.php?' . http_build_query($payParams);
              ?>
              <tr>
                <td class="mono"><?= h(date('d/m/Y', strtotime((string)$ob['fecha_vencimiento']))) ?></td>
                <td><strong><?= h((string)$ob['descripcion']) ?></strong><div class="fact-cell-sub"><?= h((string)($ob['observaciones'] ?? '')) ?></div></td>
                <td>
                  <?php if (!empty($ob['proveedor_nombre'])): ?>
                    <strong><?= h((string)$ob['proveedor_nombre']) ?></strong>
                    <div class="fact-cell-sub">
                      <?php if ((int)($ob['compra_id'] ?? 0) > 0): ?>
                        <a href="compra_detalle.php?id=<?= (int)$ob['compra_id'] ?>">Compra #<?= (int)$ob['compra_id'] ?></a>
                      <?php endif; ?>
                      <?= h((string)($ob['compra_nro_comp'] ?? '')) ?>
                    </div>
                  <?php else: ?>
                    <span class="muted">Manual</span>
                  <?php endif; ?>
                </td>
                <td><?= h((string)($ob['categoria_nombre'] ?? '')) ?></td>
                <td><?= h((string)($ob['sucursal_nombre'] ?? 'General')) ?></td>
                <td class="t-right"><?= money_ar((float)$ob['importe_estimado']) ?></td>
                <td class="t-right"><?= money_ar((float)$ob['importe_pagado']) ?></td>
                <td class="t-right"><strong><?= money_ar($saldo) ?></strong></td>
                <td><span class="tesoreria-status <?= h($estadoClass) ?>"><?= h($estado) ?></span></td>
                <td>
                  <?php if ($canManage && !in_array($estado, ['PAGADO', 'CANCELADO'], true)): ?>
                    <a class="btn-mini btn-mini--primary" href="<?= h($payUrl) ?>">Pagar</a>
                  <?php elseif ($estado === 'PAGADO'): ?>
                    <span class="muted">Pagada</span>
                  <?php else: ?>
                    <span class="muted">Solo lectura</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const parseAmount = (value) => {
    const raw = String(value || '').trim().replace(/\s/g, '');
    if (!raw) return 0;
    const hasComma = raw.includes(',');
    const normalized = hasComma ? raw.replace(/\./g, '').replace(',', '.') : raw.replace(/,/g, '');
    const n = Number(normalized);
    return Number.isFinite(n) ? n : NaN;
  };
  document.querySelectorAll('.js-tes-pay-form').forEach((form) => {
    const input = form.querySelector('.js-tes-pay-amount');
    const totalBtn = form.querySelector('[data-pay-total]');
    const hint = form.querySelector('.tesoreria-pay-hint');
    const defaultHint = hint?.textContent || '';
    const saldo = Number(form.dataset.saldo || input?.dataset.saldo || 0);

    const clearAmountError = () => {
      input?.classList.remove('is-invalid');
      input?.removeAttribute('aria-invalid');
      if (hint) {
        hint.textContent = defaultHint;
        hint.classList.remove('is-error');
      }
    };

    input?.addEventListener('input', clearAmountError);
    totalBtn?.addEventListener('click', () => {
      if (input) input.value = totalBtn.dataset.payTotal || '';
      clearAmountError();
    });
    form.addEventListener('submit', (ev) => {
      const amount = parseAmount(input?.value);
      if (!Number.isFinite(amount) || amount <= 0 || amount > saldo + 0.009) {
        ev.preventDefault();
        input?.focus();
        input?.classList.add('is-invalid');
        input?.setAttribute('aria-invalid', 'true');
        if (hint) {
          hint.textContent = 'Ingresá un monto mayor a cero y no superior al saldo.';
          hint.classList.add('is-error');
        }
        window.Notif?.error?.('El pago debe ser mayor a cero y no superar el saldo pendiente.');
      } else {
        clearAmountError();
      }
    });
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
