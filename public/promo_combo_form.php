<?php
// public/promo_combo_form.php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/helpers.php';

require_login();
$pdo  = getPDO();
$user = current_user();

$errores = [];

// --------------------------------------------------
// Cargar productos para el <select> (solo activos)
// --------------------------------------------------
$sqlProd = "
  SELECT id, codigo, nombre
  FROM productos
  WHERE activo = 1
  ORDER BY nombre
";
$productos = $pdo->query($sqlProd)->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Mapa rápido para validar producto_id (solo activos)
$prodMap = [];
foreach ($productos as $p) {
  $prodMap[(int)$p['id']] = $p;
}

// --------------------------------------------------
// Modo edición (GET id)
// --------------------------------------------------
$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$promo = null;
$items = []; // items del combo

if ($id > 0) {
  $stmt = $pdo->prepare("SELECT * FROM promos WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);
  $promo = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$promo) {
    http_response_code(404);
    die("Promo no encontrada.");
  }
  if ((string)$promo['tipo'] !== 'COMBO_FIJO') {
    http_response_code(400);
    die("Esta promo no es de tipo combo fijo.");
  }

  // Cargar items del combo (columna correcta: cantidad_requerida)
  $sqlItems = "
    SELECT
      pci.producto_id,
      pci.cantidad_requerida AS cantidad,
      pr.codigo,
      pr.nombre
    FROM promo_combo_items pci
    JOIN productos pr ON pr.id = pci.producto_id
    WHERE pci.promo_id = :id
    ORDER BY pr.nombre
  ";
  $stmtI = $pdo->prepare($sqlItems);
  $stmtI->execute([':id' => $id]);
  $items = $stmtI->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// --------------------------------------------------
// Defaults para el form
// --------------------------------------------------
$nombre       = (string)($promo['nombre'] ?? '');
$precioCombo  = (string)($promo['precio_combo'] ?? '');
$fechaInicio  = $promo['fecha_inicio'] ?? null;
$fechaFin     = $promo['fecha_fin'] ?? null;
$activo       = isset($promo['activo']) ? ((int)$promo['activo'] === 1) : true;

// --------------------------------------------------
// POST: guardar combo fijo
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // CSRF
  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $errores[] = 'Token CSRF inválido. Recargá la página e intentá de nuevo.';
  }

  // ID por POST (evita que te manipulen la URL)
  $id          = (int)($_POST['id'] ?? 0);
  $nombre      = trim((string)($_POST['nombre'] ?? ''));
  $precioCombo = parse_money_ar($_POST['precio_combo'] ?? 0);
  $fechaInicio = ($_POST['fecha_inicio'] ?? '') !== '' ? (string)$_POST['fecha_inicio'] : null;
  $fechaFin    = ($_POST['fecha_fin'] ?? '') !== '' ? (string)$_POST['fecha_fin'] : null;
  $activo      = isset($_POST['activo']) ? 1 : 0;

  // Items
  $productoIds = $_POST['item_producto_id'] ?? [];
  $cantidades  = $_POST['item_cantidad']    ?? [];

  // ---- Validaciones ----
  if ($nombre === '') {
    $errores[] = "El nombre es obligatorio.";
  }
  if ($precioCombo <= 0) {
    $errores[] = "El precio del combo debe ser mayor que 0.";
  }

  // Fechas opcionales
  if ($fechaInicio !== null && validDateYmd($fechaInicio) === null) {
    $errores[] = "Fecha inicio inválida.";
  }
  if ($fechaFin !== null && validDateYmd($fechaFin) === null) {
    $errores[] = "Fecha fin inválida.";
  }
  if ($fechaInicio !== null && $fechaFin !== null && $fechaInicio > $fechaFin) {
    $errores[] = 'La fecha "Desde" no puede ser mayor que "Hasta".';
  }

  // Armar items limpios y AGRUPAR repetidos (producto_id => cantidad)
  $agg = [];
  if (is_array($productoIds) && is_array($cantidades)) {
    foreach ($productoIds as $idx => $pidRaw) {
      $pid  = (int)$pidRaw;
      $cant = (float)($cantidades[$idx] ?? 0);

      if ($pid <= 0) continue;
      if ($cant <= 0) continue;

      // validar producto activo
      if (!isset($prodMap[$pid])) {
        $errores[] = "Hay un producto inválido o inactivo en el combo.";
        continue;
      }

      $agg[$pid] = ($agg[$pid] ?? 0) + $cant;
    }
  }

  $items = [];
  foreach ($agg as $pid => $cant) {
    if ($cant > 0) {
      $items[] = ['producto_id' => (int)$pid, 'cantidad' => (float)$cant];
    }
  }

  if (count($items) === 0) {
    $errores[] = "El combo debe tener al menos un producto con cantidad.";
  }

  // Guardar
  if (!$errores) {
    try {
      $pdo->beginTransaction();

      if ($id > 0) {
        // Validar que exista y sea COMBO_FIJO (doble check)
        $stT = $pdo->prepare("SELECT tipo FROM promos WHERE id = ? LIMIT 1");
        $stT->execute([$id]);
        $tipoDb = (string)($stT->fetchColumn() ?: '');
        if ($tipoDb === '') {
          throw new RuntimeException("Promo no encontrada.");
        }
        if ($tipoDb !== 'COMBO_FIJO') {
          throw new RuntimeException("No se permite convertir a combo desde acá.");
        }

        $sql = "
          UPDATE promos
          SET nombre       = :nombre,
              tipo         = 'COMBO_FIJO',
              precio_combo = :precio_combo,
              fecha_inicio = :fi,
              fecha_fin    = :ff,
              activo       = :activo
          WHERE id = :id
        ";
        $pdo->prepare($sql)->execute([
          ':nombre'       => $nombre,
          ':precio_combo' => $precioCombo,
          ':fi'           => $fechaInicio,
          ':ff'           => $fechaFin,
          ':activo'       => $activo,
          ':id'           => $id,
        ]);
      } else {
        $sql = "
          INSERT INTO promos (nombre, tipo, precio_combo, fecha_inicio, fecha_fin, activo)
          VALUES (:nombre, 'COMBO_FIJO', :precio_combo, :fi, :ff, :activo)
        ";
        $pdo->prepare($sql)->execute([
          ':nombre'       => $nombre,
          ':precio_combo' => $precioCombo,
          ':fi'           => $fechaInicio,
          ':ff'           => $fechaFin,
          ':activo'       => $activo,
        ]);
        $id = (int)$pdo->lastInsertId();
      }

      // Limpiezas por consistencia
      $pdo->prepare("DELETE FROM promo_productos WHERE promo_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM promo_combo_items WHERE promo_id = ?")->execute([$id]);

      // Insert items (columna correcta: cantidad_requerida)
      $stmtIns = $pdo->prepare("
        INSERT INTO promo_combo_items (promo_id, producto_id, cantidad_requerida)
        VALUES (:promo_id, :producto_id, :cantidad)
      ");

      foreach ($items as $it) {
        $stmtIns->execute([
          ':promo_id'    => $id,
          ':producto_id' => (int)$it['producto_id'],
          ':cantidad'    => (float)$it['cantidad'],
        ]);
      }

      $pdo->commit();

      header('Location: promos.php?msg=combo_ok');
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errores[] = "Error al guardar el combo.";
      // si querés debug en dev:
      // $errores[] = $e->getMessage();
    }
  }
}

// -------------------- VIEW --------------------
$pageTitle      = ($id > 0 ? 'Editar combo fijo' : 'Nuevo combo fijo') . ' - Promociones';
$currentSection = 'promos';
$extraCss       = ['assets/css/promo_combo_fijo.css'];
$extraJs        = ['assets/js/promo_combo_form.js'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap page-wrap-promos">
  <header class="page-header with-back">
    <div class="page-header-left">
      <a href="promos.php" class="link-back">← Volver</a>
      <h1 class="page-title"><?= $id > 0 ? 'Editar combo fijo' : 'Nuevo combo fijo' ?></h1>
      <p class="page-sub">
        Definí combos de varios productos con un <strong>precio fijo</strong> que se aplica automáticamente en caja.
      </p>
    </div>

    <div class="page-header-right">
      <?php if ($id > 0): ?>
        <span class="badge badge-pill badge-purple">Combo fijo</span>
      <?php else: ?>
        <span class="badge badge-pill badge-outline">Nuevo</span>
      <?php endif; ?>
    </div>
  </header>

  <div class="panel panel-promos-form">
    <?php if ($errores): ?>
      <div class="alert alert-error">
        <ul>
          <?php foreach ($errores as $e): ?>
            <li><?= h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="promo-form combo-form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">

      <!-- DATOS PRINCIPALES -->
      <div class="form-grid-2">
        <div class="field">
          <label for="nombre" class="field-label">Nombre del combo</label>
          <input
            type="text"
            id="nombre"
            name="nombre"
            class="field-input"
            placeholder="Ej: Coca + Alfajor"
            value="<?= h($nombre) ?>"
            required
          >
          <p class="field-hint">Este nombre se usa solo en reportes / configuración, no en el ticket.</p>
        </div>

        <div class="field">
          <label for="precio_combo" class="field-label">Precio combo en caja</label>
          <div class="field-input-with-prefix">
            <span class="prefix">$</span>
            <input
              type="text"
              inputmode="decimal"
              id="precio_combo"
              name="precio_combo"
              class="field-input"
              value="<?= h((string)$precioCombo) ?>"
              required
            >
          </div>
          <p class="field-hint">Es el total que se va a cobrar cuando el combo se detecta completo.</p>
        </div>
      </div>

      <!-- VIGENCIA / ESTADO -->
      <div class="form-grid-3 mt-2">
        <div class="field">
          <label for="fecha_inicio" class="field-label">Vigencia desde</label>
          <input
            type="date"
            id="fecha_inicio"
            name="fecha_inicio"
            class="field-input"
            value="<?= h($fechaInicio ? substr((string)$fechaInicio, 0, 10) : '') ?>"
          >
        </div>

        <div class="field">
          <label for="fecha_fin" class="field-label">Vigencia hasta</label>
          <input
            type="date"
            id="fecha_fin"
            name="fecha_fin"
            class="field-input"
            value="<?= h($fechaFin ? substr((string)$fechaFin, 0, 10) : '') ?>"
          >
        </div>

        <div class="field field-switch">
          <div class="field-label-top">Estado de la promo</div>

          <div class="field-switch-row">
            <label class="edit-switch">
              <input type="checkbox" name="activo" value="1" <?= $activo ? 'checked' : '' ?>>
              <span class="edit-switch-slider"></span>
            </label>

            <div class="field-switch-text">
              <div class="field-switch-title">Promo activa</div>
              <p class="field-hint">Podés desactivarla sin eliminarla para conservar el historial.</p>
            </div>
          </div>
        </div>
      </div>

      <hr class="divider mt-3 mb-3">

      <!-- ITEMS DEL COMBO -->
      <div class="combo-items-header">
        <div>
          <h2 class="sub-title-page">Productos del combo</h2>
          <p class="page-sub">Agregá los productos que incluye el combo y la cantidad de cada uno.</p>
        </div>
        <button type="button" class="btn btn-outline" id="btn-add-item">+ Agregar producto</button>
      </div>

      <div class="table-wrapper mt-1">
        <table class="table table-compact" id="tabla-items-combo">
          <thead>
            <tr>
              <th style="width: 55%;">Producto</th>
              <th style="width: 20%;" class="right">Cantidad</th>
              <th style="width: 15%;" class="center">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($items): ?>
            <?php foreach ($items as $it): ?>
              <tr>
                <td>
                  <select name="item_producto_id[]" class="field-input field-select" required>
                    <option value="">-- Elegir producto --</option>
                    <?php foreach ($productos as $p): ?>
                      <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$it['producto_id']) ? 'selected' : '' ?>>
                        [<?= h((string)$p['codigo']) ?>] <?= h((string)$p['nombre']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td class="right">
                  <input
                    type="number"
                    name="item_cantidad[]"
                    class="field-input field-input-sm right"
                    step="0.001"
                    min="0.001"
                    value="<?= h((string)$it['cantidad']) ?>"
                    required
                  >
                </td>
                <td class="center">
                  <button type="button" class="btn btn-xs btn-danger btn-remove-item">Quitar</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td>
                <select name="item_producto_id[]" class="field-input field-select" required>
                  <option value="">-- Elegir producto --</option>
                  <?php foreach ($productos as $p): ?>
                    <option value="<?= (int)$p['id'] ?>">
                      [<?= h((string)$p['codigo']) ?>] <?= h((string)$p['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="right">
                <input
                  type="number"
                  name="item_cantidad[]"
                  class="field-input field-input-sm right"
                  step="0.001"
                  min="0.001"
                  value="1"
                  required
                >
              </td>
              <td class="center">
                <button type="button" class="btn btn-xs btn-danger btn-remove-item">Quitar</button>
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="form-footer mt-3">
        <a href="promos.php" class="btn btn-light">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar combo</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
