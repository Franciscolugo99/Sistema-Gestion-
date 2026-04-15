<?php
// public/tesoreria_movimientos.php
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
        $payload = $_POST;
        $payload['request_uid'] = $payload['request_uid'] ?? bin2hex(random_bytes(16));
        $res = flus_tesoreria_registrar_movimiento($pdo, $payload);
        if (($res['success'] ?? false) === true) {
            header('Location: tesoreria_movimientos.php?ok=' . urlencode('Movimiento registrado.'));
            exit;
        }
        $error = (string)($res['error'] ?? 'No se pudo registrar el movimiento.');
    }
}

if (isset($_GET['ok'])) {
    $ok = trim((string)$_GET['ok']);
}

$cuentas = flus_tesoreria_cuentas($pdo);
$categorias = flus_tesoreria_categorias($pdo, null);
$panel = flus_tesoreria_movimientos($pdo, $_GET);
$filters = $panel['filters'] ?? [];
$rows = $panel['rows'] ?? [];
$stats = $panel['stats'] ?? ['ingresos' => 0, 'egresos' => 0, 'transferencias' => 0];
$page = (int)($filters['page'] ?? 1);
$totalPages = (int)($panel['total_pages'] ?? 1);
$totalRows = (int)($panel['total_rows'] ?? 0);
$fromRow = (int)($panel['from_row'] ?? 0);
$toRow = (int)($panel['to_row'] ?? 0);

$pageTitle = 'Movimientos de tesoreria - FLUS';
$currentSection = 'tesoreria';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => 'tesoreria.php'],
    ['label' => 'Movimientos', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/tesoreria.css?v=3'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page tesoreria-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-copy">
          <span class="module-eyebrow">Entradas, salidas y transferencias</span>
          <h1 class="page-title module-title">Movimientos</h1>
          <p class="page-sub module-subtitle">Registra dinero real sin mezclarlo con venta, factura o deuda de cliente.</p>
        </div>
      </div>
      <div class="promo-actions-top module-header-actions">
        <a href="tesoreria.php" class="v-btn v-btn--outline">Resumen</a>
        <a href="tesoreria_reportes.php" class="v-btn v-btn--outline">Reportes</a>
      </div>
    </header>

    <?php if ($error !== ''): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

    <section class="fact-kpi-grid" aria-label="Resumen de movimientos">
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Ingresos</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['ingresos']) ?></strong>
        <span class="fact-kpi-card__help">Periodo filtrado</span>
      </article>
      <article class="fact-kpi-card fact-kpi-card--accent">
        <span class="fact-kpi-card__label">Egresos</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['egresos']) ?></strong>
        <span class="fact-kpi-card__help">Gastos y pagos</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Transferencias</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['transferencias']) ?></strong>
        <span class="fact-kpi-card__help">No inflan el total</span>
      </article>
    </section>

    <?php if ($canManage): ?>
      <form method="post" class="filters tesoreria-entry-form">
        <?= csrf_field() ?>
        <input type="hidden" name="request_uid" value="<?= h(bin2hex(random_bytes(16))) ?>">
        <div class="tesoreria-form-head">
          <div class="tesoreria-form-title">
            <span>Alta guiada</span>
            <strong>Nuevo movimiento</strong>
            <small>Elegí el tipo, indicá desde dónde sale o entra el dinero y dejá una referencia operativa.</small>
          </div>
          <span class="tesoreria-form-pill">No impacta en venta ni factura</span>
        </div>
        <div class="tesoreria-form-grid">
          <label class="tesoreria-field tesoreria-field--short">
            <span>Tipo</span>
            <select name="tipo">
              <option value="EGRESO">Egreso</option>
              <option value="INGRESO">Ingreso</option>
              <option value="TRANSFERENCIA">Transferencia</option>
            </select>
          </label>
          <label class="tesoreria-field">
            <span>Cuenta origen</span>
            <select name="cuenta_origen_id">
              <option value="">No aplica</option>
              <?php foreach ($cuentas as $cuenta): ?>
                <option value="<?= (int)$cuenta['id'] ?>"><?= h((string)$cuenta['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tesoreria-field">
            <span>Cuenta destino</span>
            <select name="cuenta_destino_id">
              <option value="">No aplica</option>
              <?php foreach ($cuentas as $cuenta): ?>
                <option value="<?= (int)$cuenta['id'] ?>"><?= h((string)$cuenta['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tesoreria-field">
            <span>Categoria</span>
            <select name="categoria_id">
              <option value="">No aplica en transferencias</option>
              <?php foreach ($categorias as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= h((string)$cat['nombre']) ?> (<?= h((string)$cat['tipo']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tesoreria-field tesoreria-field--short">
            <span>Fecha</span>
            <input type="date" name="fecha" value="<?= h(date('Y-m-d')) ?>">
          </label>
          <label class="tesoreria-field tesoreria-field--short tesoreria-field--amount">
            <span>Importe</span>
            <input name="importe" inputmode="decimal" required placeholder="40.000,00">
          </label>
          <label class="tesoreria-field tesoreria-field--medium">
            <span>Concepto</span>
            <input name="concepto" maxlength="180" required placeholder="Ej: Alquiler local centro">
          </label>
          <label class="tesoreria-field tesoreria-field--short">
            <span>Sucursal</span>
            <input name="sucursal_nombre" maxlength="120" placeholder="General o sucursal">
          </label>
          <label class="tesoreria-field tesoreria-field--medium">
            <span>Referencia</span>
            <input name="referencia" maxlength="120" placeholder="Comprobante o nota">
          </label>
          <div class="tesoreria-guide">
            <strong>Regla rápida</strong>
            <span>Egreso: usá cuenta origen. Ingreso: usá cuenta destino. Transferencia: usá origen y destino, sin categoría.</span>
          </div>
          <label class="tesoreria-field tesoreria-field--large">
            <span>Observaciones</span>
            <textarea name="observaciones" maxlength="255"></textarea>
          </label>
          <div class="tesoreria-form-actions">
            <p class="tesoreria-muted-note">Importes: 40000, 40.000 o 40.000,00.</p>
            <button class="btn btn-primary" type="submit">Registrar movimiento</button>
          </div>
        </div>
      </form>
    <?php endif; ?>

    <form method="get" class="filters fact-filters">
      <div class="filters-left">
        <input type="text" name="q" value="<?= h((string)($filters['q'] ?? '')) ?>" placeholder="Buscar concepto o referencia">
        <select name="tipo">
          <option value="">Todos los tipos</option>
          <?php foreach (flus_tesoreria_tipo_movimiento_options() as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= (string)($filters['tipo'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="cuenta_id">
          <option value="">Todas las cuentas</option>
          <?php foreach ($cuentas as $cuenta): ?>
            <option value="<?= (int)$cuenta['id'] ?>" <?= (int)($filters['cuenta_id'] ?? 0) === (int)$cuenta['id'] ? 'selected' : '' ?>><?= h((string)$cuenta['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="categoria_id">
          <option value="">Todas las categorias</option>
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= (int)($filters['categoria_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= h((string)$cat['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filters-right">
        <input type="date" name="desde" value="<?= h((string)($filters['desde'] ?? '')) ?>">
        <input type="date" name="hasta" value="<?= h((string)($filters['hasta'] ?? '')) ?>">
        <button class="btn btn-filter" type="submit">Aplicar</button>
        <a href="tesoreria_movimientos.php" class="btn btn-secondary">Limpiar</a>
      </div>
    </form>

    <?= render_pagination($page, $totalPages, $_GET, true, $totalRows, $fromRow, $toRow) ?>

    <div class="table-wrapper">
      <table class="mov-table fact-table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Tipo</th>
            <th>Concepto</th>
            <th>Cuenta</th>
            <th>Categoria</th>
            <th>Sucursal</th>
            <th class="t-right">Importe</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr><td colspan="7" class="muted">No hay movimientos para esta vista.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $tipo = (string)$row['tipo'];
                $fechaTs = strtotime((string)$row['fecha']);
                $cuentaLabel = $tipo === 'TRANSFERENCIA'
                    ? (string)($row['cuenta_origen_nombre'] ?? '-') . ' -> ' . (string)($row['cuenta_destino_nombre'] ?? '-')
                    : ($tipo === 'INGRESO' ? (string)($row['cuenta_destino_nombre'] ?? '-') : (string)($row['cuenta_origen_nombre'] ?? '-'));
              ?>
              <tr>
                <td class="mono"><?= h($fechaTs ? date('d/m/Y', $fechaTs) : (string)$row['fecha']) ?></td>
                <td><span class="tesoreria-status <?= $tipo === 'EGRESO' ? 'tesoreria-status--warn' : ($tipo === 'INGRESO' ? 'tesoreria-status--ok' : '') ?>"><?= h($tipo) ?></span></td>
                <td><strong><?= h((string)$row['concepto']) ?></strong><div class="fact-cell-sub"><?= h((string)($row['referencia'] ?? '')) ?></div></td>
                <td><?= h($cuentaLabel) ?></td>
                <td><?= h((string)($row['categoria_nombre'] ?? '')) ?></td>
                <td><?= h((string)($row['sucursal_nombre'] ?? 'General')) ?></td>
                <td class="t-right"><strong><?= money_ar((float)$row['importe']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?= render_pagination($page, $totalPages, $_GET, false, $totalRows, $fromRow, $toRow) ?>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
