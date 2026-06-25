<?php
// public/tesoreria_cuentas.php
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
        $res = flus_tesoreria_save_cuenta($pdo, $_POST);
        if (($res['success'] ?? false) === true) {
            header('Location: tesoreria_cuentas.php?ok=' . urlencode('Cuenta guardada.'));
            exit;
        }
        $error = (string)($res['error'] ?? 'No se pudo guardar la cuenta.');
    }
}

if (isset($_GET['ok'])) {
    $ok = trim((string)$_GET['ok']);
}

$editId = (int)($_GET['edit_id'] ?? 0);
$editCuenta = $editId > 0 ? flus_tesoreria_find_cuenta($pdo, $editId) : null;
$cuentas = flus_tesoreria_cuentas($pdo, true);
$tablesReady = flus_tesoreria_tables_ready($pdo);

$pageTitle = 'Cuentas de tesoreria - FLUS';
$currentSection = 'tesoreria';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => 'tesoreria.php'],
    ['label' => 'Cuentas', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/tesoreria.css?v=4'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page tesoreria-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-copy">
          <span class="module-eyebrow">Fondos y cuentas</span>
          <h1 class="page-title module-title">Cuentas financieras</h1>
          <p class="page-sub module-subtitle">Define donde esta el dinero: caja, banco, billetera o fondo fijo.</p>
        </div>
      </div>
      <div class="promo-actions-top module-header-actions">
        <a href="tesoreria.php" class="v-btn v-btn--outline">Resumen</a>
        <a href="tesoreria_movimientos.php" class="v-btn v-btn--primary">+ Movimiento</a>
      </div>
    </header>

    <?php if (!$tablesReady): ?>
      <div class="alert alert-error">Faltan aplicar las migraciones de tesoreria.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

    <?php if ($canManage): ?>
      <form method="post" class="filters tesoreria-entry-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)($editCuenta['id'] ?? 0) ?>">
        <div class="tesoreria-form-head">
          <div class="tesoreria-form-title">
            <span><?= $editCuenta ? 'Edicion' : 'Alta guiada' ?></span>
            <strong><?= $editCuenta ? 'Actualizar cuenta' : 'Nueva cuenta financiera' ?></strong>
            <small>Definí dónde está el dinero y con qué saldo arranca el control operativo.</small>
          </div>
          <span class="tesoreria-form-pill">El saldo visible se calcula por movimientos</span>
        </div>
        <div class="tesoreria-form-grid">
          <label class="tesoreria-field tesoreria-field--large">
            <span>Nombre</span>
            <input name="nombre" maxlength="120" required value="<?= h((string)($editCuenta['nombre'] ?? '')) ?>" placeholder="Ej: Banco Galicia">
          </label>
          <label class="tesoreria-field tesoreria-field--short">
            <span>Tipo</span>
            <select name="tipo">
              <?php foreach (flus_tesoreria_tipo_cuenta_options() as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= (string)($editCuenta['tipo'] ?? 'OTRO') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tesoreria-field tesoreria-field--medium">
            <span>Sucursal</span>
            <input name="sucursal_nombre" maxlength="120" value="<?= h((string)($editCuenta['sucursal_nombre'] ?? '')) ?>" placeholder="General o nombre de sucursal">
          </label>
          <label class="tesoreria-field tesoreria-field--short tesoreria-field--amount">
            <span>Saldo inicial</span>
            <input name="saldo_inicial" inputmode="decimal" value="<?= h(number_format((float)($editCuenta['saldo_inicial'] ?? 0), 2, ',', '.')) ?>" placeholder="40.000,00">
          </label>
          <label class="tesoreria-field tesoreria-field--short">
            <span>Estado</span>
            <select name="estado">
              <option value="ACTIVA" <?= (string)($editCuenta['estado'] ?? 'ACTIVA') === 'ACTIVA' ? 'selected' : '' ?>>Activa</option>
              <option value="INACTIVA" <?= (string)($editCuenta['estado'] ?? '') === 'INACTIVA' ? 'selected' : '' ?>>Inactiva</option>
            </select>
          </label>
          <div class="tesoreria-guide">
            <strong>Uso recomendado</strong>
            <span>Caja, banco, billetera o fondo fijo. Si aplica a todo el negocio, dejá sucursal como General.</span>
          </div>
          <label class="tesoreria-field tesoreria-field--large">
            <span>Observaciones</span>
            <textarea name="observaciones" maxlength="255" placeholder="Detalle interno opcional"><?= h((string)($editCuenta['observaciones'] ?? '')) ?></textarea>
          </label>
          <div class="tesoreria-form-actions">
            <p class="tesoreria-muted-note">Importes: podes usar 40000, 40.000 o 40.000,00.</p>
            <button class="btn btn-primary" type="submit"><?= $editCuenta ? 'Guardar cambios' : 'Crear cuenta' ?></button>
            <?php if ($editCuenta): ?><a class="btn btn-secondary" href="tesoreria_cuentas.php">Cancelar</a><?php endif; ?>
          </div>
        </div>
      </form>
    <?php endif; ?>

    <div class="table-wrapper">
      <table class="mov-table fact-table">
        <thead>
          <tr>
            <th>Cuenta</th>
            <th>Tipo</th>
            <th>Sucursal</th>
            <th class="t-right">Saldo inicial</th>
            <th class="t-right">Saldo visible</th>
            <th>Estado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($cuentas === []): ?>
            <tr><td colspan="7" class="muted">Todavia no hay cuentas financieras.</td></tr>
          <?php else: ?>
            <?php foreach ($cuentas as $cuenta): ?>
              <tr>
                <td><strong><?= h((string)$cuenta['nombre']) ?></strong></td>
                <td><?= h((string)$cuenta['tipo']) ?></td>
                <td><?= h((string)($cuenta['sucursal_nombre'] ?? 'General')) ?></td>
                <td class="t-right"><?= money_ar((float)$cuenta['saldo_inicial']) ?></td>
                <td class="t-right"><strong><?= money_ar((float)($cuenta['saldo_actual'] ?? 0)) ?></strong></td>
                <td>
                  <span class="tesoreria-status <?= strtoupper((string)$cuenta['estado']) === 'ACTIVA' ? 'tesoreria-status--ok' : '' ?>">
                    <?= h((string)$cuenta['estado']) ?>
                  </span>
                </td>
                <td>
                  <?php if ($canManage): ?>
                    <a class="btn-mini" href="tesoreria_cuentas.php?edit_id=<?= (int)$cuenta['id'] ?>">Editar</a>
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
