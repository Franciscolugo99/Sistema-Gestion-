<?php
// public/promo_form.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('editar_promos');

$sqlProd = "
  SELECT id, codigo, nombre
  FROM productos
  WHERE activo = 1
  ORDER BY nombre ASC
";
$productos = $pdo->query($sqlProd)->fetchAll(PDO::FETCH_ASSOC);

$idPromo = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $idPromo > 0;

$templateTipo = strtoupper(trim((string)($_GET['tipo'] ?? 'N_PAGA_M')));
if (!in_array($templateTipo, ['N_PAGA_M', 'NTH_PCT'], true)) {
  $templateTipo = 'N_PAGA_M';
}

$promo = [
  'id' => null,
  'nombre' => '',
  'tipo' => $templateTipo,
  'producto_id' => 0,
  'n' => $templateTipo === 'NTH_PCT' ? 2 : 3,
  'm' => $templateTipo === 'NTH_PCT' ? 0 : 2,
  'porcentaje' => 50.0,
  'fecha_inicio' => null,
  'fecha_fin' => null,
  'activo' => 1,
];

if ($editing) {
  $sql = "
    SELECT
      p.*,
      pp.producto_id,
      pp.n,
      pp.m,
      pp.porcentaje
    FROM promos p
    LEFT JOIN promo_productos pp ON pp.promo_id = p.id
    WHERE p.id = :id
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':id' => $idPromo]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    $promo = array_merge($promo, $row);
  } else {
    $editing = false;
    $idPromo = 0;
  }
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $errores[] = 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.';
  }

  $postId = (int)($_POST['id'] ?? 0);
  $editing = $postId > 0;
  $idPromo = $postId;

  $promo['nombre'] = trim((string)($_POST['nombre'] ?? ''));
  $promo['tipo'] = (string)($_POST['tipo'] ?? 'N_PAGA_M');
  $promo['producto_id'] = (int)($_POST['producto_id'] ?? 0);
  $promo['activo'] = isset($_POST['activo']) ? 1 : 0;
  $promo['fecha_inicio'] = ($_POST['fecha_inicio'] ?? '') !== '' ? (string)$_POST['fecha_inicio'] : null;
  $promo['fecha_fin'] = ($_POST['fecha_fin'] ?? '') !== '' ? (string)$_POST['fecha_fin'] : null;

  if ($promo['tipo'] === 'N_PAGA_M') {
    $promo['n'] = (int)($_POST['nxm_n'] ?? 0);
    $promo['m'] = (int)($_POST['nxm_m'] ?? 0);
    $promo['porcentaje'] = 0.0;
  } elseif ($promo['tipo'] === 'NTH_PCT') {
    $promo['n'] = (int)($_POST['nth_n'] ?? 0);
    $promo['m'] = 0;
    $promo['porcentaje'] = (float)($_POST['porcentaje'] ?? 0);
  } else {
    $errores[] = 'Tipo de promocion invalido.';
  }

  if ($promo['nombre'] === '') {
    $errores[] = 'El nombre es obligatorio.';
  }

  if ($promo['producto_id'] <= 0) {
    $errores[] = 'Debes elegir un producto.';
  }

  if ($promo['fecha_inicio'] !== null && validDateYmd($promo['fecha_inicio']) === null) {
    $errores[] = 'Fecha de inicio invalida.';
  }
  if ($promo['fecha_fin'] !== null && validDateYmd($promo['fecha_fin']) === null) {
    $errores[] = 'Fecha de fin invalida.';
  }
  if ($promo['fecha_inicio'] !== null && $promo['fecha_fin'] !== null && $promo['fecha_inicio'] > $promo['fecha_fin']) {
    $errores[] = 'La fecha desde no puede ser mayor que la fecha hasta.';
  }

  if ($promo['tipo'] === 'N_PAGA_M') {
    if ($promo['n'] <= 1 || $promo['m'] <= 0) {
      $errores[] = 'En NxM, N debe ser mayor que 1 y M mayor que 0.';
    }
    if ($promo['m'] >= $promo['n']) {
      $errores[] = 'En NxM, M debe ser menor que N.';
    }
  } elseif ($promo['tipo'] === 'NTH_PCT') {
    if ($promo['n'] < 2) {
      $errores[] = 'En porcentaje por unidad, la unidad debe ser al menos la numero 2.';
    }
    if ($promo['porcentaje'] <= 0 || $promo['porcentaje'] > 100) {
      $errores[] = 'El porcentaje debe estar entre 1 y 100.';
    }
  }

  if (!$errores) {
    try {
      $pdo->beginTransaction();

      if ($editing) {
        $sqlPromo = "
          UPDATE promos
          SET nombre = :nombre,
              tipo = :tipo,
              fecha_inicio = :fecha_inicio,
              fecha_fin = :fecha_fin,
              activo = :activo
          WHERE id = :id
        ";
        $paramsPromo = [
          ':nombre' => $promo['nombre'],
          ':tipo' => $promo['tipo'],
          ':fecha_inicio' => $promo['fecha_inicio'],
          ':fecha_fin' => $promo['fecha_fin'],
          ':activo' => $promo['activo'],
          ':id' => $idPromo,
        ];
      } else {
        $sqlPromo = "
          INSERT INTO promos (nombre, tipo, fecha_inicio, fecha_fin, activo)
          VALUES (:nombre, :tipo, :fecha_inicio, :fecha_fin, :activo)
        ";
        $paramsPromo = [
          ':nombre' => $promo['nombre'],
          ':tipo' => $promo['tipo'],
          ':fecha_inicio' => $promo['fecha_inicio'],
          ':fecha_fin' => $promo['fecha_fin'],
          ':activo' => $promo['activo'],
        ];
      }

      $stmtPromo = $pdo->prepare($sqlPromo);
      $stmtPromo->execute($paramsPromo);

      $promoId = $editing ? $idPromo : (int)$pdo->lastInsertId();

      $pdo->prepare('DELETE FROM promo_productos WHERE promo_id = :pid')->execute([':pid' => $promoId]);

      $sqlPP = "
        INSERT INTO promo_productos (promo_id, producto_id, n, m, porcentaje)
        VALUES (:promo_id, :producto_id, :n, :m, :porcentaje)
      ";
      $stmtPP = $pdo->prepare($sqlPP);
      $stmtPP->execute([
        ':promo_id' => $promoId,
        ':producto_id' => $promo['producto_id'],
        ':n' => $promo['n'],
        ':m' => $promo['tipo'] === 'N_PAGA_M' ? $promo['m'] : null,
        ':porcentaje' => $promo['tipo'] === 'NTH_PCT' ? $promo['porcentaje'] : null,
      ]);

      $pdo->commit();

      require_once __DIR__ . '/promos_logic.php';
      if (function_exists('invalidarCachePromos')) {
        invalidarCachePromos();
      }

      header('Location: promos.php');
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $errores[] = 'Error al guardar la promocion.';
    }
  }
}

$promoTemplateLabel = match ($templateTipo) {
  'NTH_PCT' => 'Promo % en unidad',
  default => 'Promo NxM',
};

$breadcrumb = $editing
  ? [
      ['label' => 'Promociones', 'url' => 'promos.php'],
      ['label' => 'Editar promoción', 'url' => null],
    ]
  : [
      ['label' => 'Promociones', 'url' => 'promos.php'],
      ['label' => 'Plantillas', 'url' => 'promo_builder.php'],
      ['label' => $promoTemplateLabel, 'url' => null],
    ];

$pageTitle = ($editing ? 'Editar promo' : 'Nueva promo') . ' - Promociones';
$currentSection = 'promos';
$extraCss = [
  'assets/css/promos.css?v=6',
  'assets/css/promo_combo_fijo.css?v=3',
  'assets/css/promo_builder.css',
];
$extraJs = [
  'assets/js/promo_form.js?v=2',
];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap page-wrap-promos">
  <section class="promo-builder-shell promo-form-page">
    <header class="page-header with-back promo-builder-header">
      <div class="page-header-left">
        <a href="promos.php" class="link-back">&larr; Volver a promociones</a>
        <h1 class="page-title"><?= $editing ? 'Editar promo' : 'Nueva promo' ?></h1>
        <p class="page-sub">Configura una promocion para un producto y define como se aplica en caja.</p>
      </div>

      <div class="page-header-right">
        <?php if ($editing): ?>
          <span class="badge badge-pill badge-purple">Promo</span>
        <?php else: ?>
          <span class="badge badge-pill badge-outline">Nueva</span>
        <?php endif; ?>
      </div>
    </header>

    <div class="promo-form-layout">
      <?php if ($errores): ?>
        <div class="alert alert-error">
          <ul>
            <?php foreach ($errores as $err): ?>
              <li><?= h($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <section class="promo-builder-section promo-builder-section--compact promo-builder-note">
        <div class="promo-builder-note-card">
          <span class="promo-builder-kicker">Aplicacion automatica en caja</span>
          <p class="promo-builder-note-copy">La promocion se detecta al cargar productos y se registra con la venta.</p>
        </div>
      </section>

      <form method="post" class="promo-form combo-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$idPromo ?>">

        <section class="promo-builder-section promo-form-section">
          <div class="promo-builder-section-head">
            <span class="promo-builder-kicker">Datos principales</span>
            <h2 class="promo-builder-section-title">Configuracion de la promocion</h2>
          </div>

          <div class="form-grid-2">
            <div class="field">
              <label for="nombre" class="field-label">Nombre de la promocion</label>
              <input type="text" class="field-input" id="nombre" name="nombre" required value="<?= h((string)$promo['nombre']) ?>" placeholder="Ej: Oferta Coca 500 ml">
            </div>

            <div class="field">
              <label for="tipo" class="field-label">Tipo de promocion</label>
              <select id="tipo" name="tipo" class="field-input field-select" required>
                <option value="N_PAGA_M" <?= (string)$promo['tipo'] === 'N_PAGA_M' ? 'selected' : '' ?>>NxM (3x2, 4x3, 2x1)</option>
                <option value="NTH_PCT" <?= (string)$promo['tipo'] === 'NTH_PCT' ? 'selected' : '' ?>>% en una unidad puntual</option>
              </select>
            </div>
          </div>

          <div class="field mt-2">
            <label for="producto_id" class="field-label">Producto</label>
            <select id="producto_id" name="producto_id" class="field-input field-select" required>
              <option value="">-- Elegir producto --</option>
              <?php foreach ($productos as $pr): ?>
                <option value="<?= (int)$pr['id'] ?>" <?= (int)$promo['producto_id'] === (int)$pr['id'] ? 'selected' : '' ?>>
                  [<?= h((string)$pr['codigo']) ?>] <?= h((string)$pr['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </section>

        <section class="promo-builder-section promo-form-section">
          <div class="promo-builder-section-head">
            <span class="promo-builder-kicker">Condicion</span>
            <h2 class="promo-builder-section-title">Como se aplica</h2>
          </div>

          <div id="block-nxm" class="promo-param-block" style="<?= (string)$promo['tipo'] !== 'N_PAGA_M' ? 'display:none;' : '' ?>">
            <div class="form-grid-2">
              <div class="field">
                <label for="nxm_n" class="field-label">Cantidad que lleva</label>
                <input type="number" class="field-input field-input-sm" id="nxm_n" name="nxm_n" min="2" value="<?= (int)$promo['n'] ?>">
              </div>
              <div class="field">
                <label for="nxm_m" class="field-label">Cantidad que paga</label>
                <input type="number" class="field-input field-input-sm" id="nxm_m" name="nxm_m" min="1" value="<?= (int)$promo['m'] ?>">
              </div>
            </div>
            <p class="field-hint">Ejemplo: si lleva 3 unidades, paga 2.</p>
          </div>

          <div id="block-nth" class="promo-param-block" style="<?= (string)$promo['tipo'] !== 'NTH_PCT' ? 'display:none;' : '' ?>">
            <div class="form-grid-2">
              <div class="field">
                <label for="nth_n" class="field-label">Unidad con descuento</label>
                <input type="number" class="field-input field-input-sm" id="nth_n" name="nth_n" min="2" value="<?= (int)$promo['n'] ?>">
              </div>
              <div class="field">
                <label for="porcentaje" class="field-label">Descuento</label>
                <input type="number" class="field-input field-input-sm" id="porcentaje" name="porcentaje" min="1" max="100" step="0.1" value="<?= (float)$promo['porcentaje'] ?>">
              </div>
            </div>
            <p class="field-hint">Ejemplo: 50% en la segunda unidad.</p>
          </div>

          <div id="preview-promo" class="promo-live-preview" aria-live="polite"></div>
        </section>

        <section class="promo-builder-section promo-form-section">
          <div class="promo-builder-section-head">
            <span class="promo-builder-kicker">Vigencia</span>
            <h2 class="promo-builder-section-title">Fechas y estado</h2>
          </div>

          <div class="promo-form-meta-grid">
            <div class="promo-param-block">
              <div class="form-grid-2">
                <div class="field">
                  <label for="fecha_inicio" class="field-label">Desde</label>
                  <input type="date" class="field-input" id="fecha_inicio" name="fecha_inicio" value="<?= !empty($promo['fecha_inicio']) ? h(substr((string)$promo['fecha_inicio'], 0, 10)) : '' ?>">
                </div>
                <div class="field">
                  <label for="fecha_fin" class="field-label">Hasta</label>
                  <input type="date" class="field-input" id="fecha_fin" name="fecha_fin" value="<?= !empty($promo['fecha_fin']) ? h(substr((string)$promo['fecha_fin'], 0, 10)) : '' ?>">
                </div>
              </div>
              <p class="field-hint">Si dejas ambas fechas vacias, la promocion queda sin limite.</p>
            </div>

            <div class="field field-switch">
              <div class="field-label-top">Estado</div>
              <div class="field-switch-row">
                <label class="edit-switch">
                  <input type="checkbox" name="activo" value="1" <?= (int)$promo['activo'] === 1 ? 'checked' : '' ?>>
                  <span class="edit-switch-slider"></span>
                </label>

                <div class="field-switch-text">
                  <div class="field-switch-title">Promocion activa</div>
                  <p class="field-hint">Puedes desactivarla sin eliminarla.</p>
                </div>
              </div>
            </div>
          </div>
        </section>

        <div class="form-footer mt-3">
          <a href="promos.php" class="btn btn-light">Cancelar</a>
          <button type="submit" class="btn btn-primary">Guardar promocion</button>
        </div>
      </form>
    </div>
  </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
