<?php
// public/productos.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('editar_productos');
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo       = getPDO();
$msg       = "";
$savedFlag = $_GET['saved'] ?? null;

/* ================================
   RUTA PARA GUARDAR IMÁGENES
================================ */
$uploadDirFs  = __DIR__ . '/img/productos/';  // ruta física
$uploadDirUrl = 'img/productos/';             // ruta pública

if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0775, true);
}

/* ================================
   Helpers numéricos (soporta coma decimal)
================================ */
function parse_decimal(?string $s, ?float $default = null): ?float {
    if ($s === null) return $default;
    $s = trim($s);
    if ($s === '') return $default;

    $s = str_replace(' ', '', $s);

    // Si trae coma, asumimos formato AR: 1.234,56
    if (strpos($s, ',') !== false) {
        $s = str_replace('.', '', $s);   // miles
        $s = str_replace(',', '.', $s);  // decimal
    } else {
        // Si NO trae coma, asumimos punto decimal: 1234.56
        $s = str_replace(',', '', $s);   // por las dudas
    }

    if (!is_numeric($s)) return $default;
    return (float)$s;
}

/* ================================
   CSRF token para links GET (activar/desactivar)
================================ */
$csrfQ = csrf_token();

/* ================================
   ACTIVAR / DESACTIVAR (GET + CSRF) → REDIRECT + TOAST
================================ */
if (isset($_GET['eliminar']) || isset($_GET['activar'])) {

    $isEliminar = isset($_GET['eliminar']);
    $id = (int)($_GET['eliminar'] ?? $_GET['activar'] ?? 0);

    // armamos retorno conservando filtros/paginación
    $qs = $_GET;
    unset(
        $qs['eliminar'], $qs['activar'], $qs['csrf_token'],
        $qs['ajaxList'], $qs['editar'], $qs['ajax'], $qs['saved'],
        $qs['toast'], $qs['toast_msg']
    );

    if ($id <= 0) {
        $qs['toast'] = 'error';
        $qs['toast_msg'] = 'ID inválido.';
        header('Location: productos.php?' . http_build_query($qs));
        exit;
    }

    if (!csrf_verify($_GET['csrf_token'] ?? null)) {
        $qs['toast'] = 'error';
        $qs['toast_msg'] = 'Token inválido. Recargá y probá de nuevo.';
        header('Location: productos.php?' . http_build_query($qs));
        exit;
    }

    if ($isEliminar) {
        $pdo->prepare("UPDATE productos SET activo = 0 WHERE id = ?")->execute([$id]);
        $qs['toast'] = 'deactivated';
    } else {
        $pdo->prepare("UPDATE productos SET activo = 1 WHERE id = ?")->execute([$id]);
        $qs['toast'] = 'activated';
    }

    header('Location: productos.php?' . http_build_query($qs));
    exit;
}

/* ================================
   ALTA / EDICIÓN (POST + CSRF)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $msg = "Token CSRF inválido. Recargá la página e intentá de nuevo.";
    } else {

        $id          = (isset($_POST['id']) && $_POST['id'] !== '') ? (int)$_POST['id'] : null;

        $codigo      = trim((string)($_POST['codigo'] ?? ''));
        $nombre      = trim((string)($_POST['nombre'] ?? ''));
        $categoria   = trim((string)($_POST['categoria'] ?? ''));
        $marca       = trim((string)($_POST['marca'] ?? ''));
        $proveedor   = trim((string)($_POST['proveedor'] ?? ''));

        $ivaRaw      = (string)($_POST['iva'] ?? '');
        $iva         = ($ivaRaw === '') ? null : (float)$ivaRaw;

        $precio      = (float)(parse_decimal((string)($_POST['precio'] ?? ''), 0.0) ?? 0.0);
        $costo       = parse_decimal(isset($_POST['costo']) ? (string)$_POST['costo'] : null, null);

        $stock       = (float)(parse_decimal(isset($_POST['stock']) ? (string)$_POST['stock'] : null, 0.0) ?? 0.0);
        $stockMinimo = (float)(parse_decimal(isset($_POST['stock_minimo']) ? (string)$_POST['stock_minimo'] : null, 0.0) ?? 0.0);

        $activo      = isset($_POST['activo']) ? 1 : 0;

        // Pesable + unidad de venta
        $esPesable   = isset($_POST['es_pesable']) ? 1 : 0;
        $unidadVenta = trim((string)($_POST['unidad_venta'] ?? 'UNIDAD'));
        if ($unidadVenta === '') $unidadVenta = 'UNIDAD';

        // Normalizaciones
        if ($precio < 0) $precio = 0;
        if ($stock < 0) $stock = 0;
        if ($stockMinimo < 0) $stockMinimo = 0;

        // IVA permitido
        $ivaPermitidos = [0.0, 10.5, 21.0];
        if ($iva !== null && !in_array((float)$iva, $ivaPermitidos, true)) {
            $iva = null;
        }

        // Unidad permitida
        $unidadesPermitidas = ['UNIDAD', 'KG', 'G', 'LT', 'ML'];
        if (!in_array($unidadVenta, $unidadesPermitidas, true)) {
            $unidadVenta = 'UNIDAD';
        }

        // Imagen: por defecto conserva la anterior (en edición)
        $imagenNombre   = null;
        $imagenAnterior = null;

        if ($id) {
            $stImg = $pdo->prepare("SELECT imagen FROM productos WHERE id = ? LIMIT 1");
            $stImg->execute([$id]);
            $imagenAnterior = $stImg->fetchColumn() ?: null;
            $imagenNombre   = $imagenAnterior;
        }

        // Validación obligatorios
        if ($codigo === '' || $nombre === '' || $precio <= 0) {
            $msg = "Código, nombre y precio son obligatorios (precio > 0).";
        }

        // Código único
        if ($msg === '') {
            $stDup = $pdo->prepare("SELECT id FROM productos WHERE codigo = ? AND (? IS NULL OR id <> ?) LIMIT 1");
            $stDup->execute([$codigo, $id, $id]);
            if ($stDup->fetchColumn()) {
                $msg = "Ya existe un producto con ese código.";
            }
        }

        // Upload imagen (si viene algo)
        if ($msg === '' && !empty($_FILES['imagen']['name']) && (int)($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpName  = (string)($_FILES['imagen']['tmp_name'] ?? '');
            $origName = (string)($_FILES['imagen']['name'] ?? '');
            $size     = (int)($_FILES['imagen']['size'] ?? 0);

            // límite 3MB
            if ($size > 3 * 1024 * 1024) {
                $msg = "La imagen es muy pesada (máx 3MB).";
            } else {
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $extPermitidas = ['jpg','jpeg','png','webp','gif'];

                $isImg = @getimagesize($tmpName);

                if (!$isImg) {
                    $msg = "El archivo subido no es una imagen válida.";
                } elseif (!in_array($ext, $extPermitidas, true)) {
                    $msg = "Formato de imagen no permitido (jpg, jpeg, png, webp, gif).";
                } else {
                    $safeName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

                    if (move_uploaded_file($tmpName, $uploadDirFs . $safeName)) {
                        $imagenNombre = $safeName;

                        // borrar anterior si se reemplazó
                        if ($imagenAnterior && $imagenAnterior !== $imagenNombre) {
                            $oldPath = $uploadDirFs . $imagenAnterior;
                            if (is_file($oldPath)) @unlink($oldPath);
                        }
                    } else {
                        $msg = "No se pudo guardar la imagen.";
                    }
                }
            }
        }

        // Guardar
        if ($msg === '') {
            if ($id) {
                // UPDATE
                $stmt = $pdo->prepare("
                    UPDATE productos SET
                      codigo = ?, nombre = ?, categoria = ?, marca = ?, proveedor = ?, iva = ?,
                      precio = ?, costo = ?, stock = ?, stock_minimo = ?,
                      es_pesable = ?, unidad_venta = ?,
                      activo = ?, imagen = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $codigo, $nombre, $categoria, $marca, $proveedor, $iva,
                    $precio, $costo, $stock, $stockMinimo,
                    $esPesable, $unidadVenta,
                    $activo, $imagenNombre, $id
                ]);

                header("Location: productos.php?saved=updated");
                exit;
            } else {
                // INSERT
                $stockInicial = $stock;

                $stmt = $pdo->prepare("
                    INSERT INTO productos
                      (codigo, nombre, categoria, marca, proveedor, iva,
                       precio, costo, stock, stock_minimo, stock_inicial,
                       es_pesable, unidad_venta,
                       activo, imagen)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $codigo, $nombre, $categoria, $marca, $proveedor, $iva,
                    $precio, $costo, $stock, $stockMinimo, $stockInicial,
                    $esPesable, $unidadVenta,
                    $activo, $imagenNombre
                ]);

                header("Location: productos.php?saved=created");
                exit;
            }
        }
    }
}

/* ================================
   OBTENER PRODUCTO PARA EDICIÓN
================================ */
$editProducto = null;
if (isset($_GET['editar'])) {
    $id   = (int)$_GET['editar'];
    $stmt = $pdo->prepare("SELECT * FROM productos WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $editProducto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Valores para el form (pesable/unidad) */
$esPesableForm   = 0;
$unidadVentaForm = 'UNIDAD';
if (!empty($editProducto)) {
    $esPesableForm   = (int)($editProducto['es_pesable'] ?? 0);
    $unidadVentaForm = (string)($editProducto['unidad_venta'] ?? 'UNIDAD');
}

/* Respuesta AJAX para el panel lateral */
if (isset($_GET['editar']) && isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($editProducto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ================================
   FILTROS + PAGINACIÓN LISTADO
================================ */
$buscar = trim((string)($_GET['q'] ?? ''));
$estado = (string)($_GET['estado'] ?? '');

$perPageOptions = [20, 50, 100];

$perPage = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if (!in_array($perPage, $perPageOptions, true) || $perPage <= 0) {
    $perPage = 50;
}

$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

$where  = [];
$params = [];

/* Ordenamiento seguro */
$validSortColumns = [
    'codigo', 'nombre', 'categoria', 'marca', 'proveedor', 'iva', 'precio', 'stock'
];

$sort = (string)($_GET['sort'] ?? 'nombre');
if (!in_array($sort, $validSortColumns, true)) {
    $sort = 'nombre';
}

$dirParam = strtolower((string)($_GET['dir'] ?? 'asc'));
$dir      = ($dirParam === 'desc') ? 'DESC' : 'ASC';

$orderSql = "ORDER BY activo DESC, {$sort} {$dir}";

/* Filtro texto */
if ($buscar !== '') {
    $like    = '%' . $buscar . '%';
    $where[] = '(codigo LIKE ? OR nombre LIKE ? OR categoria LIKE ? OR marca LIKE ? OR proveedor LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}

/* Filtro estado */
if ($estado === 'activos') {
    $where[] = 'activo = 1';
} elseif ($estado === 'inactivos') {
    $where[] = 'activo = 0';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* Total filtrado */
$sqlCount = "SELECT COUNT(*) FROM productos {$whereSql}";
$stmt     = $pdo->prepare($sqlCount);
$stmt->execute($params);
$totalFiltrados = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalFiltrados / $perPage));
if ($page > $totalPages) {
    $page   = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* Listado página actual */
$sql = "
    SELECT *
    FROM productos
    {$whereSql}
    {$orderSql}
    LIMIT ? OFFSET ?
";

$stmt = $pdo->prepare($sql);

// params del WHERE (posicionales)
foreach ($params as $i => $v) {
    $stmt->bindValue($i + 1, $v);
}

// limit/offset al final
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);

$stmt->execute();
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* AJAX – devolver listado filtrado en JSON (con orden) */
if (isset($_GET['ajaxList'])) {

    $buscar = trim((string)($_GET['q'] ?? ''));
    $estado = (string)($_GET['estado'] ?? '');

    $validSort = ['nombre', 'codigo', 'precio', 'stock'];
    $sortAjax  = (string)($_GET['sort'] ?? 'nombre');
    $dirAjax   = strtoupper((string)($_GET['dir'] ?? 'ASC'));

    if (!in_array($sortAjax, $validSort, true)) {
        $sortAjax = 'nombre';
    }
    if (!in_array($dirAjax, ['ASC', 'DESC'], true)) {
        $dirAjax = 'ASC';
    }

    $where2  = [];
    $params2 = [];

    if ($buscar !== '') {
        $like     = '%' . $buscar . '%';
        $where2[] = '(codigo LIKE ? OR nombre LIKE ? OR categoria LIKE ? OR marca LIKE ? OR proveedor LIKE ?)';
        array_push($params2, $like, $like, $like, $like, $like);
    }

    if ($estado === 'activos') {
        $where2[] = 'activo = 1';
    } elseif ($estado === 'inactivos') {
        $where2[] = 'activo = 0';
    }

    $whereSql2 = $where2 ? 'WHERE ' . implode(' AND ', $where2) : '';

    $sql2 = "
        SELECT *
        FROM productos
        {$whereSql2}
        ORDER BY activo DESC, {$sortAjax} {$dirAjax}
        LIMIT 200
    ";
    $st2 = $pdo->prepare($sql2);
    $st2->execute($params2);
    $productos2 = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($productos2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ================================
   VISTA
================================ */
$pageTitle      = 'Productos';
$currentSection = 'productos';

$extraCss = ['assets/css/productos.css'];
$extraJs  = ['assets/js/productos.js'];

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-wrap productos-page">

  <!-- PANEL FORMULARIO ALTA / EDICIÓN -->
  <div class="panel">
    <?php
      $showForm = !empty($editProducto);
      if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($msg)) {
        $showForm = true; // mostrar form si hubo error al guardar
      }
    ?>

    <div class="productos-header">
      <div class="productos-header-left">
        <h1 class="page-title">Productos</h1>
        <p class="page-sub">Gestión de productos del sistema</p>
      </div>

      <button
        type="button"
        class="btn btn-primary btn-new-product"
        id="toggleFormBtn"
      >
        Agregar producto
      </button>
    </div>

    <!-- bloque plegable -->
    <div
      id="productFormBlock"
      class="product-form-block<?= $showForm ? '' : ' is-collapsed' ?>"
    >
      <form method="post" class="productos-form" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <?php if (!empty($editProducto)): ?>
          <input type="hidden" name="id" value="<?= (int)$editProducto['id'] ?>">
        <?php endif; ?>

        <div class="pf-grid">
          <!-- Fila 1 -->
          <div class="pf-field">
            <label>Código</label>
            <input
              name="codigo"
              value="<?= h($editProducto['codigo'] ?? '') ?>"
              required
            >
          </div>

          <div class="pf-field pf-field-wide">
            <label>Nombre</label>
            <input
              name="nombre"
              value="<?= h($editProducto['nombre'] ?? '') ?>"
              required
            >
          </div>

          <!-- Fila 2 -->
          <div class="pf-field">
            <label>Categoría</label>
            <input
              name="categoria"
              value="<?= h($editProducto['categoria'] ?? '') ?>"
            >
          </div>

          <div class="pf-field">
            <label>Marca</label>
            <input
              name="marca"
              value="<?= h($editProducto['marca'] ?? '') ?>"
            >
          </div>

          <div class="pf-field pf-field-wide">
            <label>Proveedor</label>
            <input
              name="proveedor"
              value="<?= h($editProducto['proveedor'] ?? '') ?>"
            >
          </div>

          <!-- Fila 3 -->
          <div class="pf-field">
            <label>IVA</label>
            <select name="iva">
              <?php
                $ivaActual = isset($editProducto['iva']) ? (float)$editProducto['iva'] : null;
                $selIva = function(float $valor, ?float $actual): string {
                  return ($actual !== null && abs($actual - $valor) < 0.001) ? 'selected' : '';
                };
              ?>
              <option value="">Sin IVA</option>
              <option value="0"    <?= $selIva(0.0,  $ivaActual) ?>>0%</option>
              <option value="10.5" <?= $selIva(10.5, $ivaActual) ?>>10,5%</option>
              <option value="21"   <?= $selIva(21.0, $ivaActual) ?>>21%</option>
            </select>
          </div>

          <div class="pf-field">
            <label>Precio</label>
            <input
              type="number"
              step="0.01"
              min="0.01"
              name="precio"
              value="<?= h($editProducto['precio'] ?? '0') ?>"
              required
            >
          </div>

          <div class="pf-field">
            <label>Costo</label>
            <input
              type="number"
              step="0.01"
              min="0"
              name="costo"
              value="<?= h($editProducto['costo'] ?? '') ?>"
            >
          </div>

          <!-- Fila 4 -->
          <div class="pf-field">
            <label>Stock</label>
            <input
              type="number"
              name="stock"
              step="0.001"
              min="0"
              value="<?= h($editProducto['stock'] ?? '0') ?>"
            >
          </div>

          <div class="pf-field">
            <label>Stock mínimo</label>
            <input
              type="number"
              name="stock_minimo"
              step="0.001"
              min="0"
              value="<?= h($editProducto['stock_minimo'] ?? '0') ?>"
            >
          </div>

          <div class="pf-field pf-field-pesable">
            <div class="pf-label-top">Producto pesable</div>

            <div class="pf-pesable-row">
              <label class="edit-switch">
                <input
                  type="checkbox"
                  name="es_pesable"
                  value="1"
                  <?= $esPesableForm ? 'checked' : '' ?>
                >
                <span class="edit-switch-slider"></span>
              </label>

              <div class="pf-pesable-text">
                <div class="pf-pesable-title">Venta por peso / volumen</div>
                <p class="pf-help-text">
                  Ej: carnicería, fiambres, frutas por kilo, etc.
                  Si está desactivado, se vende por unidad.
                </p>
              </div>
            </div>
          </div>

          <div class="pf-field">
            <label for="unidad_venta">Unidad de venta</label>
            <select name="unidad_venta" id="unidad_venta">
              <?php
                $unidades = ['UNIDAD', 'KG', 'G', 'LT', 'ML'];
                foreach ($unidades as $u):
              ?>
                <option value="<?= h($u) ?>" <?= ($unidadVentaForm === $u) ? 'selected' : '' ?>>
                  <?= h($u) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Fila 5: imagen -->
          <div class="pf-field pf-field-wide">
            <label>Imagen (opcional)</label>

            <div class="file-input">
              <input
                type="file"
                name="imagen"
                id="imagen"
                accept="image/*"
                class="file-input-hidden"
              >

              <label for="imagen" class="file-btn">
                <span>Seleccionar archivo</span>
              </label>

              <span id="fileName" class="file-name">
                Ningún archivo seleccionado
              </span>
            </div>
          </div>
        </div> <!-- /.pf-grid -->

        <!-- ESTADO DEL PRODUCTO -->
        <div class="pf-status-row">
          <div class="pf-status-info">
            <span class="pf-status-label">Estado del producto</span>
            <p class="pf-status-help">
              Los productos inactivos no aparecen en Caja ni en búsquedas de ventas.
            </p>
          </div>

          <label class="edit-switch">
            <input
              type="checkbox"
              name="activo"
              <?= (!isset($editProducto) || (int)($editProducto['activo'] ?? 1) === 1) ? 'checked' : '' ?>
            >
            <span class="edit-switch-slider"></span>
            <span class="edit-switch-text">Activo</span>
          </label>
        </div>

        <div class="pf-actions">
          <button class="btn btn-primary" type="submit">Guardar</button>

          <?php if (!empty($editProducto)): ?>
            <a class="btn btn-secondary" href="productos.php">Cancelar</a>
          <?php endif; ?>
        </div>

        <?php if (!empty($msg)): ?>
          <div class="msg msg-visible msg-info" style="margin-top:12px;">
            <?= h($msg) ?>
          </div>
        <?php endif; ?>
      </form>
    </div><!-- /#productFormBlock -->
  </div><!-- /.panel formulario -->

  <!-- PANEL LISTADO -->
  <div class="panel">
    <h2 class="sub-title-page">Listado</h2>

    <!-- FILTROS LISTADO -->
    <form method="get" class="filters">
      <div class="filters-left">
        <input
          type="text"
          id="searchInput"
          name="q"
          placeholder="Buscar por código, nombre, marca, proveedor..."
          value="<?= h($buscar) ?>"
        >
      </div>

      <div class="filters-right">
        <select name="estado">
          <option value="">Todos</option>
          <option value="activos"   <?= $estado === 'activos'   ? 'selected' : '' ?>>Solo activos</option>
          <option value="inactivos" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Solo inactivos</option>
        </select>

        <select name="limit">
          <option value="20"  <?= $perPage === 20  ? 'selected' : '' ?>>20</option>
          <option value="50"  <?= $perPage === 50  ? 'selected' : '' ?>>50</option>
          <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
        </select>

        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir"  value="<?= h($dir) ?>">
        <input type="hidden" name="page" value="1">

        <button class="btn btn-filter" type="submit">Aplicar</button>

        <?php if ($buscar !== '' || $estado !== ''): ?>
          <a href="productos.php" class="btn btn-secondary">Limpiar</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- TABLA -->
    <div class="table-wrapper">
      <table
        class="productos-table"
        data-sort="<?= h($sort) ?>"
        data-dir="<?= h($dir) ?>"
      >
        <thead>
          <tr>
            <th class="center col-thumb">Img</th>

            <th data-sort="codigo" class="<?= $sort === 'codigo' ? 'sorted-' . strtolower($dir) : '' ?>">
              Código
            </th>

            <th data-sort="nombre" class="<?= $sort === 'nombre' ? 'sorted-' . strtolower($dir) : '' ?>">
              Nombre
            </th>

            <th>Categoría</th>
            <th>Marca</th>
            <th>Proveedor</th>
            <th>IVA</th>

            <th class="right <?= $sort === 'precio' ? 'sorted-' . strtolower($dir) : '' ?>" data-sort="precio">
              Precio
            </th>

            <th class="right <?= $sort === 'stock' ? 'sorted-' . strtolower($dir) : '' ?>" data-sort="stock">
              Stock
            </th>

            <th class="center">Estado</th>
            <th class="center">Acciones</th>
          </tr>
        </thead>

        <tbody>
          <?php foreach ($productos as $p): ?>
            <?php
              $stockVal = (float)($p['stock'] ?? 0);
              $stockMin = (float)($p['stock_minimo'] ?? 0);

              // ✅ href toggle conservando filtros/paginación + csrf
              $qsToggle = $_GET;
              unset(
                $qsToggle['ajaxList'], $qsToggle['editar'], $qsToggle['ajax'],
                $qsToggle['csrf_token'], $qsToggle['eliminar'], $qsToggle['activar'],
                $qsToggle['saved'], $qsToggle['toast'], $qsToggle['toast_msg']
              );
              $qsToggle['csrf_token'] = $csrfQ;

              if ((int)($p['activo'] ?? 0) === 1) {
                $qsToggle['eliminar'] = (int)($p['id'] ?? 0);
              } else {
                $qsToggle['activar'] = (int)($p['id'] ?? 0);
              }
              $toggleHref = 'productos.php?' . http_build_query($qsToggle);

              if (!(int)($p['activo'] ?? 0)) {
                $tag = '<span class="tag tag-inactivo">Inactivo</span>';
              } elseif ($stockVal <= 0) {
                $tag = '<span class="tag tag-sin">Sin stock</span>';
              } elseif ($stockVal <= $stockMin) {
                $tag = '<span class="tag tag-bajo">Stock bajo</span>';
              } else {
                $tag = '<span class="tag tag-ok">OK</span>';
              }

              $thumbUrl = '';
              if (!empty($p['imagen'])) {
                $thumbUrl = $uploadDirUrl . (string)$p['imagen'];
              }
            ?>
            <tr>
              <td class="center">
                <?php if ($thumbUrl): ?>
                  <img
                    src="<?= h($thumbUrl) ?>"
                    alt="img"
                    class="prod-thumb"
                  >
                <?php else: ?>
                  <span class="prod-thumb-placeholder">—</span>
                <?php endif; ?>
              </td>

              <td><?= h($p['codigo'] ?? '') ?></td>
              <td><?= h($p['nombre'] ?? '') ?></td>
              <td><?= h($p['categoria'] ?? '') ?></td>
              <td><?= h($p['marca'] ?? '') ?></td>
              <td><?= h($p['proveedor'] ?? '') ?></td>
              <td><?= ($p['iva'] !== null && $p['iva'] !== '') ? h((string)$p['iva']) . '%' : '' ?></td>

              <td class="right">$<?= number_format((float)($p['precio'] ?? 0), 2, ',', '.') ?></td>

              <!-- Unificado con helpers.php -->
              <td class="right"><?= h(format_cantidad($p, 'stock', 3)) ?></td>

              <td class="center"><?= $tag ?></td>

              <td class="center">
                <a
                  href="javascript:void(0)"
                  class="btn-line btn-edit"
                  onclick="openEditPanel(<?= (int)($p['id'] ?? 0) ?>); return false;"
                >
                  Editar
                </a>

                <a
                  href="<?= h($toggleHref) ?>"
                  class="btn-line btn-toggle js-product-toggle"
                  data-action="<?= ((int)($p['activo'] ?? 0) === 1) ? 'desactivar' : 'activar' ?>"
                >
                  <?= ((int)($p['activo'] ?? 0) === 1) ? 'Desactivar' : 'Activar' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($productos)): ?>
            <tr>
              <td colspan="11" class="empty-cell">
                No se encontraron productos con los filtros actuales.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- PAGINACIÓN -->
    <?php if ($totalFiltrados > 0 && $totalPages > 1): ?>
      <div class="pagination">
        <div class="pagination-info">
          Mostrando
          <?= $totalFiltrados ? ($offset + 1) : 0 ?>
          –
          <?= min($offset + $perPage, $totalFiltrados) ?>
          de
          <?= $totalFiltrados ?>
          productos
        </div>

        <div class="pagination-pages">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php
              $paramsUrl         = $_GET;
              $paramsUrl['page'] = $i;
              unset(
                $paramsUrl['eliminar'],
                $paramsUrl['activar'],
                $paramsUrl['editar'],
                $paramsUrl['ajaxList'],
                $paramsUrl['csrf_token'],
                $paramsUrl['saved'],
                $paramsUrl['toast'],
                $paramsUrl['toast_msg']
              );
              $paramsUrl['sort'] = $sort;
              $paramsUrl['dir']  = $dir;
              $url = 'productos.php?' . http_build_query($paramsUrl);
            ?>
            <a
              href="<?= h($url) ?>"
              class="page-btn <?= $i === $page ? 'active' : '' ?>"
            >
              <?= $i ?>
            </a>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  </div><!-- /.panel listado -->

</div><!-- /.page-wrap -->

<!-- OVERLAY Y PANEL LATERAL (EDICIÓN) -->
<div id="editOverlay" class="edit-overlay">
  <div id="editPanel" class="edit-panel">
    <div class="edit-panel-head">
      <h2>Editar producto</h2>
      <button
        class="close-edit"
        type="button"
        onclick="closeEditPanel()"
      >
        ✕
      </button>
    </div>

    <form
      id="editForm"
      method="post"
      action="productos.php"
      class="edit-form"
      enctype="multipart/form-data"
    >
      <?= csrf_field() ?>
      <input type="hidden" name="id">

      <div class="edit-grid">
        <div class="edit-field">
          <label>Código</label>
          <input name="codigo">
        </div>

        <div class="edit-field">
          <label>Nombre</label>
          <input name="nombre">
        </div>

        <div class="edit-field">
          <label>Categoría</label>
          <input name="categoria">
        </div>

        <div class="edit-field">
          <label>Marca</label>
          <input name="marca">
        </div>

        <div class="edit-field">
          <label>Proveedor</label>
          <input name="proveedor">
        </div>

        <div class="edit-field">
          <label>IVA</label>
          <select name="iva">
            <option value="">Sin IVA</option>
            <option value="0">0%</option>
            <option value="10.5">10,5%</option>
            <option value="21">21%</option>
          </select>
        </div>

        <div class="edit-field">
          <label>Precio</label>
          <input name="precio" type="number" step="0.01" min="0.01">
        </div>

        <div class="edit-field">
          <label>Costo</label>
          <input name="costo" type="number" step="0.01" min="0">
        </div>

        <div class="edit-field">
          <label>Stock</label>
          <input name="stock" type="number" step="0.001" min="0">
        </div>

        <div class="edit-field">
          <label>Stock mínimo</label>
          <input name="stock_minimo" type="number" step="0.001" min="0">
        </div>

        <div class="edit-field edit-field-pesable">
          <div class="edit-label-top">Producto pesable</div>

          <div class="edit-pesable-row">
            <label class="edit-switch">
              <input type="checkbox" name="es_pesable" value="1">
              <span class="edit-switch-slider"></span>
            </label>

            <div class="edit-pesable-text">
              <div class="edit-pesable-title">
                Venta por peso / volumen
              </div>
              <div class="edit-help">
                Ej: carnicería, fiambres, frutas por kilo, etc.
                Si está desactivado, se vende por unidad.
              </div>
            </div>
          </div>
        </div>

        <div class="edit-field">
          <label>Unidad de venta</label>
          <select name="unidad_venta">
            <option value="UNIDAD">UNIDAD</option>
            <option value="KG">KG</option>
            <option value="G">G</option>
            <option value="LT">LT</option>
            <option value="ML">ML</option>
          </select>
        </div>

        <div class="edit-field edit-field-full">
          <label>Imagen (opcional)</label>
          <input type="file" name="imagen" accept="image/*">
          <div class="edit-help" style="margin-top:6px;">
            Si subís una nueva imagen, reemplaza la anterior.
          </div>
        </div>

        <div class="edit-status-row edit-field-full">
          <span class="edit-status-label">Estado del producto</span>

          <div class="edit-status-switch">
            <label class="edit-switch">
              <input type="checkbox" name="activo">
              <span class="edit-switch-slider"></span>
              <span class="edit-switch-text">Activo</span>
            </label>
          </div>

          <div class="edit-status-help">
            Los productos inactivos no aparecen en Caja ni en búsquedas de ventas.
          </div>
        </div>
      </div>

      <div class="edit-actions">
        <button class="btn btn-primary" type="submit">
          Guardar cambios
        </button>
        <button
          type="button"
          class="btn btn-secondary"
          onclick="closeEditPanel()"
        >
          Cancelar
        </button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL CONFIRMACIÓN ACTIVAR/DESACTIVAR PRODUCTO -->
<div id="confirmToggle" class="confirm-overlay">
  <div class="confirm-dialog">
    <h3 id="confirmTitle">Cambiar estado</h3>
    <p id="confirmText">
      ¿Desactivar producto? No aparecerá en Caja ni en búsquedas de ventas.
    </p>

    <div class="confirm-actions">
      <button
        type="button"
        class="btn btn-secondary"
        id="confirmCancel"
      >
        Cancelar
      </button>

      <button
        type="button"
        class="btn btn-danger"
        id="confirmAccept"
      >
        Sí, continuar
      </button>
    </div>
  </div>
</div>

<?php if ($savedFlag): ?>
  <script>
    if (window.showToast) {
      window.showToast(
        <?= json_encode(
          $savedFlag === 'created'
            ? 'Producto creado correctamente.'
            : 'Producto actualizado correctamente.'
        ) ?>
      );
    }
  </script>
<?php endif; ?>

<?php
  $toast    = (string)($_GET['toast'] ?? '');
  $toastMsg = (string)($_GET['toast_msg'] ?? '');
?>
<?php if ($toast): ?>
  <script>
    if (window.showToast) {
      const t  = <?= json_encode($toast) ?>;
      const cm = <?= json_encode($toastMsg) ?>;

      const map = {
        activated:   "Producto activado.",
        deactivated: "Producto desactivado.",
        error:       cm || "Ocurrió un error."
      };

      window.showToast(map[t] || "Acción realizada.");
    }
  </script>
<?php endif; ?>
<?php
$inlineJs = $inlineJs ?? '';

// Toast por saved=created/updated
if (!empty($_GET['saved'])) {
  $msgToast = ($_GET['saved'] === 'created')
    ? 'Producto creado correctamente.'
    : 'Producto actualizado correctamente.';
  $inlineJs .= "window.showToast && window.showToast(" . json_encode($msgToast) . ");";
}

// Toast por activar/desactivar (toast=activated/deactivated/error)
if (!empty($_GET['toast'])) {
  $t = (string)$_GET['toast'];
  $toastMsg = (string)($_GET['toast_msg'] ?? '');

  if ($t === 'activated')   $inlineJs .= "window.showToast && window.showToast('Producto activado.');";
  if ($t === 'deactivated') $inlineJs .= "window.showToast && window.showToast('Producto desactivado.');";
  if ($t === 'error')       $inlineJs .= "window.showToast && window.showToast(" . json_encode($toastMsg ?: 'Ocurrió un error.') . ");";
}
?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
