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

$pageTitle = 'Obligaciones de tesoreria - FLUS';
$currentSection = 'tesoreria';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => 'tesoreria.php'],
    ['label' => 'Obligaciones', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/tesoreria.css?v=3'];

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

    <?php if ($canManage): ?>
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
            <th>Categoria</th>
            <th>Sucursal</th>
            <th class="t-right">Importe</th>
            <th class="t-right">Pagado</th>
            <th>Estado</th>
            <th>Pago</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($obligaciones === []): ?>
            <tr><td colspan="8" class="muted">No hay obligaciones para esta vista.</td></tr>
          <?php else: ?>
            <?php foreach ($obligaciones as $ob): ?>
              <?php
                $estado = (string)($ob['estado_efectivo'] ?? $ob['estado'] ?? 'PENDIENTE');
                $estadoClass = $estado === 'PAGADO' ? 'tesoreria-status--ok' : ($estado === 'VENCIDO' ? 'tesoreria-status--danger' : ($estado === 'PARCIAL' ? 'tesoreria-status--warn' : ''));
                $saldo = max(0.0, (float)$ob['importe_estimado'] - (float)$ob['importe_pagado']);
              ?>
              <tr>
                <td class="mono"><?= h(date('d/m/Y', strtotime((string)$ob['fecha_vencimiento']))) ?></td>
                <td><strong><?= h((string)$ob['descripcion']) ?></strong><div class="fact-cell-sub"><?= h((string)($ob['observaciones'] ?? '')) ?></div></td>
                <td><?= h((string)($ob['categoria_nombre'] ?? '')) ?></td>
                <td><?= h((string)($ob['sucursal_nombre'] ?? 'General')) ?></td>
                <td class="t-right"><?= money_ar((float)$ob['importe_estimado']) ?></td>
                <td class="t-right"><?= money_ar((float)$ob['importe_pagado']) ?></td>
                <td><span class="tesoreria-status <?= h($estadoClass) ?>"><?= h($estado) ?></span></td>
                <td>
                  <?php if ($canManage && !in_array($estado, ['PAGADO', 'CANCELADO'], true)): ?>
                    <form method="post" class="tesoreria-inline-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="pagar">
                      <input type="hidden" name="obligacion_id" value="<?= (int)$ob['id'] ?>">
                      <input type="hidden" name="request_uid" value="<?= h(bin2hex(random_bytes(16))) ?>">
                      <label>
                        <span>Cuenta</span>
                        <select name="cuenta_origen_id" required>
                          <option value="">Elegir</option>
                          <?php foreach ($cuentas as $cuenta): ?>
                            <option value="<?= (int)$cuenta['id'] ?>" <?= (int)($ob['cuenta_sugerida_id'] ?? 0) === (int)$cuenta['id'] ? 'selected' : '' ?>><?= h((string)$cuenta['nombre']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>
                        <span>Monto</span>
                        <input name="importe" inputmode="decimal" value="<?= h(number_format($saldo, 2, ',', '.')) ?>">
                      </label>
                      <button class="btn-mini btn-mini--primary" type="submit">Pagar</button>
                    </form>
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

<?php require __DIR__ . '/partials/footer.php'; ?>
