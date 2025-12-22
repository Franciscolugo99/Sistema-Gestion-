<?php
// public/promo_form.php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/helpers.php';

require_login();
require_permission('editar_productos');
$pdo  = getPDO();
$user = current_user();

// ----------------------
// Cargar productos
// ----------------------
$sqlProd = "
  SELECT id, codigo, nombre
  FROM productos
  WHERE activo = 1
  ORDER BY nombre ASC
";
$productos = $pdo->query($sqlProd)->fetchAll(PDO::FETCH_ASSOC);

// ----------------------
// Modo edición
// (acepta id por GET; en POST usamos hidden id)
// ----------------------
$idPromo = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $idPromo > 0;

$promo = [
  'id'           => null,
  'nombre'       => '',
  'tipo'         => 'N_PAGA_M',
  'producto_id'  => 0,
  'n'            => 3,
  'm'            => 2,
  'porcentaje'   => 50.0,
  'fecha_inicio' => null,
  'fecha_fin'    => null,
  'activo'       => 1,
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

// ----------------------
// Procesar POST
// ----------------------
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // CSRF
  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $errores[] = 'Token CSRF inválido. Recargá la página e intentá de nuevo.';
  }

  // ID por POST (evita que te cambien la URL)
  $postId   = (int)($_POST['id'] ?? 0);
  $editing  = $postId > 0;
  $idPromo  = $postId;

  $promo['nombre']       = trim((string)($_POST['nombre'] ?? ''));
  $promo['tipo']         = (string)($_POST['tipo'] ?? 'N_PAGA_M');
  $promo['producto_id']  = (int)($_POST['producto_id'] ?? 0);
  $promo['activo']       = isset($_POST['activo']) ? 1 : 0;

  $promo['fecha_inicio'] = ($_POST['fecha_inicio'] ?? '') !== '' ? (string)$_POST['fecha_inicio'] : null;
  $promo['fecha_fin']    = ($_POST['fecha_fin'] ?? '') !== '' ? (string)$_POST['fecha_fin'] : null;

  // Parámetros según tipo (✅ nombres distintos para no pisarse)
  if ($promo['tipo'] === 'N_PAGA_M') {
    $promo['n'] = (int)($_POST['nxm_n'] ?? 0);
    $promo['m'] = (int)($_POST['nxm_m'] ?? 0);
    $promo['porcentaje'] = 0.0;
  } elseif ($promo['tipo'] === 'NTH_PCT') {
    $promo['n'] = (int)($_POST['nth_n'] ?? 0);
    $promo['m'] = 0;
    $promo['porcentaje'] = (float)($_POST['porcentaje'] ?? 0);
  } else {
    $errores[] = 'Tipo de promo inválido.';
  }

  // ----------------------
  // Validaciones
  // ----------------------
  if ($promo['nombre'] === '') {
    $errores[] = 'El nombre es obligatorio.';
  }

  if ($promo['producto_id'] <= 0) {
    $errores[] = 'Debés elegir un producto.';
  }

  // fechas (si vienen)
  if ($promo['fecha_inicio'] !== null && validDateYmd($promo['fecha_inicio']) === null) {
    $errores[] = 'Fecha de inicio inválida.';
  }
  if ($promo['fecha_fin'] !== null && validDateYmd($promo['fecha_fin']) === null) {
    $errores[] = 'Fecha de fin inválida.';
  }
  if ($promo['fecha_inicio'] !== null && $promo['fecha_fin'] !== null) {
    if ($promo['fecha_inicio'] > $promo['fecha_fin']) {
      $errores[] = 'La fecha "Desde" no puede ser mayor que "Hasta".';
    }
  }

  if ($promo['tipo'] === 'N_PAGA_M') {
    if ($promo['n'] <= 1 || $promo['m'] <= 0) {
      $errores[] = 'En NxM, N debe ser > 1 y M > 0.';
    }
    if ($promo['m'] >= $promo['n']) {
      $errores[] = 'En NxM, M debe ser menor que N (ej: 3x2).';
    }
  } elseif ($promo['tipo'] === 'NTH_PCT') {
    if ($promo['n'] < 2) {
      $errores[] = 'En "% a la N°", N debe ser al menos 2.';
    }
    if ($promo['porcentaje'] <= 0 || $promo['porcentaje'] > 100) {
      $errores[] = 'El porcentaje debe estar entre 1 y 100.';
    }
  }

  // ----------------------
  // Guardar
  // ----------------------
  if (!$errores) {
    try {
      $pdo->beginTransaction();

      if ($editing) {
        $sqlPromo = "
          UPDATE promos
          SET nombre       = :nombre,
              tipo         = :tipo,
              fecha_inicio = :fecha_inicio,
              fecha_fin    = :fecha_fin,
              activo       = :activo
          WHERE id = :id
        ";
        $paramsPromo = [
          ':nombre'       => $promo['nombre'],
          ':tipo'         => $promo['tipo'],
          ':fecha_inicio' => $promo['fecha_inicio'],
          ':fecha_fin'    => $promo['fecha_fin'],
          ':activo'       => $promo['activo'],
          ':id'           => $idPromo,
        ];
      } else {
        $sqlPromo = "
          INSERT INTO promos (nombre, tipo, fecha_inicio, fecha_fin, activo)
          VALUES (:nombre, :tipo, :fecha_inicio, :fecha_fin, :activo)
        ";
        $paramsPromo = [
          ':nombre'       => $promo['nombre'],
          ':tipo'         => $promo['tipo'],
          ':fecha_inicio' => $promo['fecha_inicio'],
          ':fecha_fin'    => $promo['fecha_fin'],
          ':activo'       => $promo['activo'],
        ];
      }

      $stmtPromo = $pdo->prepare($sqlPromo);
      $stmtPromo->execute($paramsPromo);

      $promoId = $editing ? $idPromo : (int)$pdo->lastInsertId();

      // Limpiar vínculo anterior
      $pdo->prepare("DELETE FROM promo_productos WHERE promo_id = :pid")
          ->execute([':pid' => $promoId]);

      // Insert vínculo nuevo
      $sqlPP = "
        INSERT INTO promo_productos (promo_id, producto_id, n, m, porcentaje)
        VALUES (:promo_id, :producto_id, :n, :m, :porcentaje)
      ";
      $stmtPP = $pdo->prepare($sqlPP);
      $stmtPP->execute([
        ':promo_id'    => $promoId,
        ':producto_id' => $promo['producto_id'],
        ':n'           => $promo['n'],
        ':m'           => ($promo['tipo'] === 'N_PAGA_M') ? $promo['m'] : null,
        ':porcentaje'  => ($promo['tipo'] === 'NTH_PCT') ? $promo['porcentaje'] : null,
      ]);

      $pdo->commit();

      header('Location: promos.php');
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errores[] = 'Error al guardar la promo.';
      // (si querés debug en dev) $errores[] = $e->getMessage();
    }
  }
}

// ----------------------
// VIEW
// ----------------------
$pageTitle      = ($editing ? 'Editar promo' : 'Nueva promo') . ' - Promociones';
$currentSection = 'promos';

$extraCss = [
  'assets/css/promo_combo_fijo.css',
];

$extraJs  = [
  'assets/js/promo_form.js',
];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap page-wrap-promos">
  <header class="page-header with-back">
    <div class="page-header-left">
      <a href="promos.php" class="link-back">← Volver</a>
      <h1 class="page-title"><?= $editing ? 'Editar promo' : 'Nueva promo' ?></h1>
      <p class="page-sub">Configurá las condiciones con las que se aplicará automáticamente en caja.</p>
    </div>

    <div class="page-header-right">
      <?php if ($editing): ?>
        <span class="badge badge-pill badge-purple">Promo</span>
      <?php else: ?>
        <span class="badge badge-pill badge-outline">Nueva</span>
      <?php endif; ?>
    </div>
  </header>

  <div class="panel panel-promos-form">

    <?php if ($errores): ?>
      <div class="alert alert-error">
        <ul>
          <?php foreach ($errores as $err): ?>
            <li><?= h($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="promo-form combo-form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$idPromo ?>">

      <div class="form-grid-2">
        <div class="field">
          <label for="nombre" class="field-label">Nombre de la promo</label>
          <input type="text" class="field-input" id="nombre" name="nombre" required value="<?= h((string)$promo['nombre']) ?>">
        </div>

        <div class="field">
          <label for="tipo" class="field-label">Tipo de promo</label>
          <select id="tipo" name="tipo" class="field-input field-select" required>
            <option value="N_PAGA_M" <?= ((string)$promo['tipo'] === 'N_PAGA_M') ? 'selected' : '' ?>>NxM (3x2, 4x3, 2x1, …)</option>
            <option value="NTH_PCT"  <?= ((string)$promo['tipo'] === 'NTH_PCT')  ? 'selected' : '' ?>>% en la N° unidad (50% en la 2°, …)</option>
          </select>
        </div>
      </div>

      <div class="field">
        <label for="producto_id" class="field-label">Producto</label>
        <select id="producto_id" name="producto_id" class="field-input field-select" required>
          <option value="">-- Elegir --</option>
          <?php foreach ($productos as $pr): ?>
            <option value="<?= (int)$pr['id'] ?>" <?= ((int)$promo['producto_id'] === (int)$pr['id']) ? 'selected' : '' ?>>
              [<?= h((string)$pr['codigo']) ?>] <?= h((string)$pr['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <hr class="divider mt-3 mb-3">

      <div class="form-grid-2">
        <!-- Parámetros -->
        <div class="field">
          <div class="field-label field-label-top">Parámetros según tipo</div>

          <!-- NxM -->
          <div id="block-nxm" class="promo-param-block">
            <div class="form-grid-2">
              <div class="field">
                <label for="n" class="field-label">N (llevás)</label>
                <input type="number" class="field-input field-input-sm" id="n" name="nxm_n" min="2" value="<?= (int)$promo['n'] ?>">
              </div>
              <div class="field">
                <label for="m" class="field-label">M (pagás)</label>
                <input type="number" class="field-input field-input-sm" id="m" name="nxm_m" min="1" value="<?= (int)$promo['m'] ?>">
              </div>
            </div>
            <p class="field-hint">Ejemplo: 3x2 → N=3, M=2.</p>
          </div>

          <!-- % a la N° -->
          <div id="block-nth" class="promo-param-block">
            <div class="form-grid-2">
              <div class="field">
                <label for="n_nth" class="field-label">N° de unidad</label>
                <input type="number" class="field-input field-input-sm" id="n_nth" name="nth_n" min="2" value="<?= (int)$promo['n'] ?>">
              </div>
              <div class="field">
                <label for="porcentaje" class="field-label">% de descuento</label>
                <input type="number" class="field-input field-input-sm" id="porcentaje" name="porcentaje" min="1" max="100" step="0.1" value="<?= (float)$promo['porcentaje'] ?>">
              </div>
            </div>
            <p class="field-hint">Ejemplo: 50% en la 2° unidad → N=2, % = 50.</p>
          </div>
        </div>

        <!-- Vigencia + estado -->
        <div>
          <div class="field">
            <div class="field-label field-label-top">Vigencia</div>
            <div class="form-grid-2">
              <div class="field">
                <label for="fecha_inicio" class="field-label">Desde</label>
                <input type="date" class="field-input" id="fecha_inicio" name="fecha_inicio"
                       value="<?= !empty($promo['fecha_inicio']) ? h(substr((string)$promo['fecha_inicio'], 0, 10)) : '' ?>">
              </div>
              <div class="field">
                <label for="fecha_fin" class="field-label">Hasta</label>
                <input type="date" class="field-input" id="fecha_fin" name="fecha_fin"
                       value="<?= !empty($promo['fecha_fin']) ? h(substr((string)$promo['fecha_fin'], 0, 10)) : '' ?>">
              </div>
            </div>
            <p class="field-hint">Dejá en blanco para que sea sin fecha límite.</p>
          </div>

          <div class="field field-switch">
            <div class="field-label-top">Estado de la promo</div>

            <div class="field-switch-row">
              <label class="edit-switch">
                <input type="checkbox" name="activo" value="1" <?= ((int)$promo['activo'] === 1) ? 'checked' : '' ?>>
                <span class="edit-switch-slider"></span>
              </label>

              <div class="field-switch-text">
                <div class="field-switch-title">Promo activa</div>
                <p class="field-hint">Podés desactivarla sin eliminarla para conservar el historial.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="form-footer mt-3">
        <a href="promos.php" class="btn btn-light">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar promo</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
