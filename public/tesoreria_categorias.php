<?php
// public/tesoreria_categorias.php
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
        $res = flus_tesoreria_save_categoria($pdo, $_POST);
        if (($res['success'] ?? false) === true) {
            header('Location: tesoreria_categorias.php?ok=' . urlencode('Categoria guardada.'));
            exit;
        }
        $error = (string)($res['error'] ?? 'No se pudo guardar la categoria.');
    }
}

if (isset($_GET['ok'])) {
    $ok = trim((string)$_GET['ok']);
}

$editId = (int)($_GET['edit_id'] ?? 0);
$editCategoria = $editId > 0 ? flus_tesoreria_find_categoria($pdo, $editId) : null;
$categorias = flus_tesoreria_categorias($pdo, null, true);

$pageTitle = 'Categorias de tesoreria - FLUS';
$currentSection = 'tesoreria';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => 'tesoreria.php'],
    ['label' => 'Categorias', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/tesoreria.css?v=3'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page tesoreria-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-copy">
          <span class="module-eyebrow">Clasificacion simple</span>
          <h1 class="page-title module-title">Categorias</h1>
          <p class="page-sub module-subtitle">Agrupa ingresos y egresos sin plan contable.</p>
        </div>
      </div>
      <div class="promo-actions-top module-header-actions">
        <a href="tesoreria.php" class="v-btn v-btn--outline">Resumen</a>
        <a href="tesoreria_movimientos.php" class="v-btn v-btn--primary">Movimientos</a>
      </div>
    </header>

    <?php if ($error !== ''): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

    <?php if ($canManage): ?>
      <form method="post" class="filters tesoreria-entry-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)($editCategoria['id'] ?? 0) ?>">
        <div class="tesoreria-form-head">
          <div class="tesoreria-form-title">
            <span><?= $editCategoria ? 'Edicion' : 'Alta guiada' ?></span>
            <strong><?= $editCategoria ? 'Actualizar categoria' : 'Nueva categoria' ?></strong>
            <small>Usala para ordenar gastos e ingresos en reportes sin convertir FLUS en contabilidad pesada.</small>
          </div>
          <span class="tesoreria-form-pill">Clasificacion operativa</span>
        </div>
        <div class="tesoreria-form-grid">
          <label class="tesoreria-field tesoreria-field--large">
            <span>Nombre</span>
            <input name="nombre" maxlength="120" required value="<?= h((string)($editCategoria['nombre'] ?? '')) ?>" placeholder="Ej: Comisiones bancarias">
          </label>
          <label class="tesoreria-field tesoreria-field--short">
            <span>Tipo</span>
            <select name="tipo">
              <?php foreach (['EGRESO' => 'Egreso', 'INGRESO' => 'Ingreso', 'AMBOS' => 'Ambos'] as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= (string)($editCategoria['tipo'] ?? 'EGRESO') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tesoreria-field tesoreria-field--short">
            <span>Orden</span>
            <input type="number" name="orden" value="<?= (int)($editCategoria['orden'] ?? 100) ?>">
          </label>
          <label class="tesoreria-field tesoreria-field--short">
            <span>Estado</span>
            <select name="estado">
              <option value="ACTIVA" <?= (string)($editCategoria['estado'] ?? 'ACTIVA') === 'ACTIVA' ? 'selected' : '' ?>>Activa</option>
              <option value="INACTIVA" <?= (string)($editCategoria['estado'] ?? '') === 'INACTIVA' ? 'selected' : '' ?>>Inactiva</option>
            </select>
          </label>
          <div class="tesoreria-guide">
            <strong>Tipo de categoria</strong>
            <span>Egreso para gastos. Ingreso para entradas no comerciales. Ambos solo cuando realmente sirva en los dos lados.</span>
          </div>
          <label class="tesoreria-field tesoreria-field--large">
            <span>Observaciones</span>
            <textarea name="observaciones" maxlength="255"><?= h((string)($editCategoria['observaciones'] ?? '')) ?></textarea>
          </label>
          <div class="tesoreria-form-actions">
            <p class="tesoreria-muted-note">El orden solo acomoda la lista; no cambia los saldos.</p>
            <button class="btn btn-primary" type="submit"><?= $editCategoria ? 'Guardar cambios' : 'Crear categoria' ?></button>
            <?php if ($editCategoria): ?><a class="btn btn-secondary" href="tesoreria_categorias.php">Cancelar</a><?php endif; ?>
          </div>
        </div>
      </form>
    <?php endif; ?>

    <div class="table-wrapper">
      <table class="mov-table fact-table">
        <thead>
          <tr>
            <th>Categoria</th>
            <th>Tipo</th>
            <th>Orden</th>
            <th>Estado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categorias as $cat): ?>
            <tr>
              <td><strong><?= h((string)$cat['nombre']) ?></strong><div class="fact-cell-sub"><?= h((string)$cat['slug']) ?></div></td>
              <td><?= h((string)$cat['tipo']) ?></td>
              <td><?= (int)$cat['orden'] ?></td>
              <td><span class="tesoreria-status <?= (string)$cat['estado'] === 'ACTIVA' ? 'tesoreria-status--ok' : '' ?>"><?= h((string)$cat['estado']) ?></span></td>
              <td>
                <?php if ($canManage): ?>
                  <a class="btn-mini" href="tesoreria_categorias.php?edit_id=<?= (int)$cat['id'] ?>">Editar</a>
                <?php else: ?>
                  <span class="muted">Solo lectura</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
