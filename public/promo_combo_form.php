<?php
// public/promo_combo_form.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('editar_promos');

$errores = [];

$sqlProd = "
  SELECT id, codigo, nombre, precio
  FROM productos
  WHERE activo = 1
  ORDER BY nombre
";
$productos = $pdo->query($sqlProd)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$prodMap = [];
foreach ($productos as $p) {
  $prodMap[(int)$p['id']] = $p;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$promo = null;
$items = [];

if ($id > 0) {
  $stmt = $pdo->prepare('SELECT * FROM promos WHERE id = :id LIMIT 1');
  $stmt->execute([':id' => $id]);
  $promo = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$promo) {
    http_response_code(404);
    flus_abort(404, 'Promo no encontrada.');
  }
  if ((string)$promo['tipo'] !== 'COMBO_FIJO') {
    http_response_code(400);
    flus_abort(400, 'Esta promo no es de tipo combo fijo.');
  }

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

$nombre = (string)($promo['nombre'] ?? '');
$precioCombo = (string)($promo['precio_combo'] ?? '');
$fechaInicio = $promo['fecha_inicio'] ?? null;
$fechaFin = $promo['fecha_fin'] ?? null;
$activo = isset($promo['activo']) ? ((int)$promo['activo'] === 1) : true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $errores[] = 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.';
  }

  $id = (int)($_POST['id'] ?? 0);
  $nombre = trim((string)($_POST['nombre'] ?? ''));
  $precioCombo = parse_money_ar($_POST['precio_combo'] ?? 0);
  $fechaInicio = ($_POST['fecha_inicio'] ?? '') !== '' ? (string)$_POST['fecha_inicio'] : null;
  $fechaFin = ($_POST['fecha_fin'] ?? '') !== '' ? (string)$_POST['fecha_fin'] : null;
  $activo = isset($_POST['activo']) ? 1 : 0;

  $productoIds = $_POST['item_producto_id'] ?? [];
  $cantidades = $_POST['item_cantidad'] ?? [];

  if ($nombre === '') {
    $errores[] = 'El nombre es obligatorio.';
  }
  if ($precioCombo <= 0) {
    $errores[] = 'El precio del combo debe ser mayor que 0.';
  }

  if ($fechaInicio !== null && validDateYmd($fechaInicio) === null) {
    $errores[] = 'Fecha de inicio invalida.';
  }
  if ($fechaFin !== null && validDateYmd($fechaFin) === null) {
    $errores[] = 'Fecha de fin invalida.';
  }
  if ($fechaInicio !== null && $fechaFin !== null && $fechaInicio > $fechaFin) {
    $errores[] = 'La fecha desde no puede ser mayor que la fecha hasta.';
  }

  $agg = [];
  if (is_array($productoIds) && is_array($cantidades)) {
    foreach ($productoIds as $idx => $pidRaw) {
      $pid = (int)$pidRaw;
      $cant = (float)($cantidades[$idx] ?? 0);

      if ($pid <= 0 || $cant <= 0) {
        continue;
      }

      if (!isset($prodMap[$pid])) {
        $errores[] = 'Hay un producto invalido o inactivo en el combo.';
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
    $errores[] = 'El combo debe tener al menos un producto con cantidad.';
  }

  if (!$errores) {
    try {
      $pdo->beginTransaction();

      if ($id > 0) {
        $stT = $pdo->prepare('SELECT tipo FROM promos WHERE id = ? LIMIT 1');
        $stT->execute([$id]);
        $tipoDb = (string)($stT->fetchColumn() ?: '');
        if ($tipoDb === '') {
          throw new RuntimeException('Promo no encontrada.');
        }
        if ($tipoDb !== 'COMBO_FIJO') {
          throw new RuntimeException('No se permite convertir a combo desde aqui.');
        }

        $sql = "
          UPDATE promos
          SET nombre = :nombre,
              tipo = 'COMBO_FIJO',
              precio_combo = :precio_combo,
              fecha_inicio = :fi,
              fecha_fin = :ff,
              activo = :activo
          WHERE id = :id
        ";
        $pdo->prepare($sql)->execute([
          ':nombre' => $nombre,
          ':precio_combo' => $precioCombo,
          ':fi' => $fechaInicio,
          ':ff' => $fechaFin,
          ':activo' => $activo,
          ':id' => $id,
        ]);
      } else {
        $sql = "
          INSERT INTO promos (nombre, tipo, precio_combo, fecha_inicio, fecha_fin, activo)
          VALUES (:nombre, 'COMBO_FIJO', :precio_combo, :fi, :ff, :activo)
        ";
        $pdo->prepare($sql)->execute([
          ':nombre' => $nombre,
          ':precio_combo' => $precioCombo,
          ':fi' => $fechaInicio,
          ':ff' => $fechaFin,
          ':activo' => $activo,
        ]);
        $id = (int)$pdo->lastInsertId();
      }

      $pdo->prepare('DELETE FROM promo_productos WHERE promo_id = ?')->execute([$id]);
      $pdo->prepare('DELETE FROM promo_combo_items WHERE promo_id = ?')->execute([$id]);

      $stmtIns = $pdo->prepare('
        INSERT INTO promo_combo_items (promo_id, producto_id, cantidad_requerida)
        VALUES (:promo_id, :producto_id, :cantidad)
      ');

      foreach ($items as $it) {
        $stmtIns->execute([
          ':promo_id' => $id,
          ':producto_id' => (int)$it['producto_id'],
          ':cantidad' => (float)$it['cantidad'],
        ]);
      }

      $pdo->commit();

      require_once __DIR__ . '/promos_logic.php';
      if (function_exists('invalidarCachePromos')) {
        invalidarCachePromos();
      }

      header('Location: promos.php?msg=combo_ok');
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $errores[] = 'Error al guardar el combo.';
    }
  }
}

$pageTitle = ($id > 0 ? 'Editar combo fijo' : 'Nuevo combo fijo') . ' - Promociones';
$currentSection = 'promos';
$extraCss = [
  'assets/css/promos.css?v=6',
  'assets/css/promo_combo_fijo.css?v=3',
  'assets/css/promo_builder.css',
];
$extraJs = ['assets/js/promo_combo_form.js?v=3'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap page-wrap-promos">
  <section class="promo-builder-shell promo-form-page">
    <header class="page-header with-back promo-builder-header">
      <div class="page-header-left">
        <a href="promos.php" class="link-back">&larr; Volver a promociones</a>
        <h1 class="page-title"><?= $id > 0 ? 'Editar combo fijo' : 'Nuevo combo fijo' ?></h1>
        <p class="page-sub">Configura un combo de varios productos con un precio final fijo en caja.</p>
      </div>

      <div class="page-header-right">
        <?php if ($id > 0): ?>
          <span class="badge badge-pill badge-purple">Combo</span>
        <?php else: ?>
          <span class="badge badge-pill badge-outline">Nuevo</span>
        <?php endif; ?>
      </div>
    </header>

    <div class="promo-form-layout">
      <?php if ($errores): ?>
        <div class="alert alert-error">
          <ul>
            <?php foreach ($errores as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <section class="promo-builder-section promo-builder-section--compact promo-builder-note">
        <div class="promo-builder-note-card">
          <span class="promo-builder-kicker">Aplicacion automatica en caja</span>
          <p class="promo-builder-note-copy">El combo se detecta al cargar los productos y se registra con la venta.</p>
        </div>
      </section>

      <form method="post" class="promo-form combo-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <section class="promo-builder-section promo-form-section">
          <div class="promo-builder-section-head">
            <span class="promo-builder-kicker">Datos principales</span>
            <h2 class="promo-builder-section-title">Configuracion del combo</h2>
          </div>

          <div class="form-grid-2">
            <div class="field">
              <label for="nombre" class="field-label">Nombre del combo</label>
              <input type="text" id="nombre" name="nombre" class="field-input" placeholder="Ej: Coca + Alfajor" value="<?= h($nombre) ?>" required>
              <p class="field-hint">Se usa en configuracion y reportes.</p>
            </div>

            <div class="field">
              <label for="precio_combo" class="field-label">Precio final del combo</label>
              <div class="field-input-with-prefix">
                <span class="prefix">$</span>
                <input type="text" inputmode="decimal" id="precio_combo" name="precio_combo" value="<?= h((string)$precioCombo) ?>" required>
              </div>
              <p class="field-hint">Es el total que se cobra cuando el combo queda completo.</p>
            </div>
          </div>

          <div class="combo-preview" id="combo-preview">Agrega productos para ver el ahorro estimado.</div>
        </section>

        <section class="promo-builder-section promo-form-section">
          <div class="combo-items-header">
            <div>
              <span class="promo-builder-kicker">Productos</span>
              <h2 class="promo-builder-section-title">Contenido del combo</h2>
              <p class="page-sub">Agrega los productos y la cantidad necesaria de cada uno.</p>
            </div>
            <button type="button" class="btn btn-outline" id="btn-add-item">+ Agregar producto</button>
          </div>

          <div class="table-wrapper table-wrapper--combo promo-form-table-shell mt-1">
            <table id="tabla-items-combo">
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
                            <option value="<?= (int)$p['id'] ?>" data-precio="<?= (float)($p['precio'] ?? 0) ?>" <?= (int)$p['id'] === (int)$it['producto_id'] ? 'selected' : '' ?>>
                              [<?= h((string)$p['codigo']) ?>] <?= h((string)$p['nombre']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td class="right">
                        <input type="number" name="item_cantidad[]" class="field-input field-input-sm right" step="0.001" min="0.001" value="<?= h((string)$it['cantidad']) ?>" required>
                      </td>
                      <td class="center">
                        <button type="button" class="btn-remove-item">Quitar</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td>
                      <select name="item_producto_id[]" class="field-input field-select" required>
                        <option value="">-- Elegir producto --</option>
                        <?php foreach ($productos as $p): ?>
                          <option value="<?= (int)$p['id'] ?>" data-precio="<?= (float)($p['precio'] ?? 0) ?>">
                            [<?= h((string)$p['codigo']) ?>] <?= h((string)$p['nombre']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td class="right">
                      <input type="number" name="item_cantidad[]" class="field-input field-input-sm right" step="0.001" min="0.001" value="1" required>
                    </td>
                    <td class="center">
                      <button type="button" class="btn-remove-item">Quitar</button>
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
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
                  <input type="date" id="fecha_inicio" name="fecha_inicio" class="field-input" value="<?= h($fechaInicio ? substr((string)$fechaInicio, 0, 10) : '') ?>">
                </div>

                <div class="field">
                  <label for="fecha_fin" class="field-label">Hasta</label>
                  <input type="date" id="fecha_fin" name="fecha_fin" class="field-input" value="<?= h($fechaFin ? substr((string)$fechaFin, 0, 10) : '') ?>">
                </div>
              </div>
              <p class="field-hint">Si dejas ambas fechas vacias, el combo queda sin limite.</p>
            </div>

            <div class="field field-switch">
              <div class="field-label-top">Estado</div>
              <div class="field-switch-row">
                <label class="edit-switch">
                  <input type="checkbox" name="activo" value="1" <?= $activo ? 'checked' : '' ?>>
                  <span class="edit-switch-slider"></span>
                </label>

                <div class="field-switch-text">
                  <div class="field-switch-title">Combo activo</div>
                  <p class="field-hint">Puedes desactivarlo sin eliminarlo.</p>
                </div>
              </div>
            </div>
          </div>
        </section>

        <div class="form-footer mt-3">
          <a href="promos.php" class="btn btn-light">Cancelar</a>
          <button type="submit" class="btn btn-primary">Guardar combo</button>
        </div>
      </form>
    </div>
  </section>
</div>

<div class="form-toast" id="formToast"></div>

<?php require __DIR__ . '/partials/footer.php'; ?>
