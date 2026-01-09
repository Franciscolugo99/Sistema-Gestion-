<?php
// public/productos.php - VERSIÓN CORREGIDA (2026) ✅
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('editar_productos');

$pdo = getPDO();
$msg = "";

/* ================================
   RUTA PARA GUARDAR IMÁGENES
================================ */
$uploadDirFs  = __DIR__ . '/img/productos/';
$uploadDirUrl = 'img/productos/';

if (!is_dir($uploadDirFs)) {
  @mkdir($uploadDirFs, 0775, true);
}

/* ================================
   HELPERS (rows HTML)
================================ */
function productos_status_tag(array $p): string {
  $stockVal = (float)($p['stock'] ?? 0);
  $stockMin = (float)($p['stock_minimo'] ?? 0);

  if (!(int)($p['activo'] ?? 0)) {
    return '<span class="tag tag-inactivo">Inactivo</span>';
  }
  if ($stockVal <= 0) {
    return '<span class="tag tag-sin">Sin stock</span>';
  }
  if ($stockVal <= $stockMin) {
    return '<span class="tag tag-bajo">Stock bajo</span>';
  }
  return '<span class="tag tag-ok">OK</span>';
}

function productos_clean_qs(array $qs): array {
  // params internos que NO deben viajar en links
  foreach ([
    'toggle','action','csrf_token',
    'ajaxList','ajaxTbody','ajax','editar', // ✅ editar también se limpia
    'saved','toast','toast_msg','clearForm',
  ] as $k) {
    unset($qs[$k]);
  }
  return $qs;
}

function productos_render_tbody(array $productos, string $uploadDirUrl, string $csrfQ, array $currentGet): string {
  ob_start();

  if (empty($productos)) {
    ?>
    <tr>
      <td colspan="8" class="empty-cell">No se encontraron productos con los filtros actuales.</td>
    </tr>
    <?php
    return (string)ob_get_clean();
  }

  foreach ($productos as $p) {
    $id = (int)($p['id'] ?? 0);

    $qsToggle = productos_clean_qs($currentGet);
    $qsToggle['csrf_token'] = $csrfQ;
    $qsToggle['toggle'] = $id;
    $qsToggle['action'] = ((int)($p['activo'] ?? 0) === 1) ? 'deactivate' : 'activate';

    $toggleHref = 'productos.php?' . http_build_query($qsToggle);

    $thumbUrl = '';
    if (!empty($p['imagen'])) {
      $thumbUrl = $uploadDirUrl . (string)$p['imagen'];
    }

    $tag = productos_status_tag($p);
    ?>
    <tr class="producto-row" data-id="<?= $id ?>">
      <td class="center">
        <?php if ($thumbUrl): ?>
          <img src="<?= h($thumbUrl) ?>" alt="img" class="prod-thumb">
        <?php else: ?>
          <span class="prod-thumb-placeholder">📦</span>
        <?php endif; ?>
      </td>

      <td><?= h($p['codigo'] ?? '') ?></td>
      <td><strong><?= h($p['nombre'] ?? '') ?></strong></td>

      <td class="right">$<?= number_format((float)($p['precio'] ?? 0), 2, ',', '.') ?></td>
      <td class="right"><?= h(format_cantidad($p, 'stock', 3)) ?></td>

      <td class="center"><?= $tag ?></td>

      <td class="center acciones">
        <a
          href="javascript:void(0)"
          class="btn-line btn-edit"
          onclick="openEditPanel(<?= $id ?>); return false;"
        >Editar</a>

        <a
          href="javascript:void(0)"
          class="btn-line btn-copy"
          data-copy-id="<?= $id ?>"
        >Copiar</a>

        <a
          href="<?= h($toggleHref) ?>"
          class="btn-line btn-toggle js-product-toggle"
          data-action="<?= ((int)($p['activo'] ?? 0) === 1) ? 'desactivar' : 'activar' ?>"
        >
          <?= ((int)($p['activo'] ?? 0) === 1) ? 'Desactivar' : 'Activar' ?>
        </a>
      </td>

      <td class="center">
        <button
          type="button"
          class="btn-expand"
          onclick="toggleDetailRow(<?= $id ?>)"
          aria-label="Ver detalles"
        >⊕</button>
      </td>
    </tr>

    <tr class="producto-detail-row" id="detail-<?= $id ?>">
      <td colspan="8">
        <div class="producto-detail-content">
          <div class="detail-item">
            <span class="detail-label">Categoría</span>
            <span class="detail-value"><?= h($p['categoria'] ?? '—') ?></span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Marca</span>
            <span class="detail-value"><?= h($p['marca'] ?? '—') ?></span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Proveedor</span>
            <span class="detail-value"><?= h($p['proveedor'] ?? '—') ?></span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Costo</span>
            <span class="detail-value">
              <?= !empty($p['costo']) ? '$' . number_format((float)$p['costo'], 2, ',', '.') : '—' ?>
            </span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Stock Mínimo</span>
            <span class="detail-value"><?= h(format_cantidad($p, 'stock_minimo', 3)) ?></span>
          </div>
          <div class="detail-item">
            <span class="detail-label">IVA</span>
            <span class="detail-value"><?= ($p['iva'] !== null) ? h((string)$p['iva']) . '%' : '—' ?></span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Unidad</span>
            <span class="detail-value"><?= h($p['unidad_venta'] ?? 'UNIDAD') ?></span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Pesable</span>
            <span class="detail-value"><?= (int)($p['es_pesable'] ?? 0) ? 'Sí' : 'No' ?></span>
          </div>
        </div>
      </td>
    </tr>
    <?php
  }

  return (string)ob_get_clean();
}

/* ================================
   ENDPOINT AUTOCOMPLETE
================================ */
if (isset($_GET['autocomplete'])) {
  $field = (string)($_GET['autocomplete'] ?? '');
  $allowed = ['categoria', 'marca', 'proveedor'];

  if (!in_array($field, $allowed, true)) {
    http_response_code(400);
    exit;
  }

  $stmt = $pdo->prepare("
    SELECT DISTINCT {$field} as value
    FROM productos
    WHERE {$field} IS NOT NULL AND {$field} != '' AND activo = 1
    ORDER BY {$field} ASC
    LIMIT 100
  ");
  $stmt->execute();

  $values = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($values, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ================================
   ENDPOINT: ESTADÍSTICAS
================================ */
if (isset($_GET['stats'])) {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $stats = [
      'total' => (int)$pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn(),
      'activos' => (int)$pdo->query("SELECT COUNT(*) FROM productos WHERE activo = 1")->fetchColumn(),
      'sin_stock' => (int)$pdo->query("SELECT COUNT(*) FROM productos WHERE activo = 1 AND stock <= 0")->fetchColumn(),
      'stock_bajo' => (int)$pdo->query("SELECT COUNT(*) FROM productos WHERE activo = 1 AND stock > 0 AND stock <= stock_minimo")->fetchColumn(),
    ];
    echo json_encode($stats, JSON_UNESCAPED_UNICODE);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al obtener estadísticas'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/* ================================
   ENDPOINT: VALIDAR CÓDIGO
================================ */
if (isset($_GET['checkCodigo'])) {
  header('Content-Type: application/json; charset=utf-8');

  $codigo = trim((string)($_GET['checkCodigo'] ?? ''));
  $id = (isset($_GET['id']) && $_GET['id'] !== '') ? (int)$_GET['id'] : null;

  if ($codigo === '') {
    echo json_encode(['exists' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $stmt = $pdo->prepare("
    SELECT id
    FROM productos
    WHERE codigo = ?
      AND (? IS NULL OR id <> ?)
    LIMIT 1
  ");
  $stmt->execute([$codigo, $id, $id]);

  $exists = $stmt->fetchColumn() !== false;

  echo json_encode(['exists' => $exists, 'codigo' => $codigo], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ================================
   ENDPOINT: BÚSQUEDA RÁPIDA (Copiar)
================================ */
if (isset($_GET['buscarProducto'])) {
  header('Content-Type: application/json; charset=utf-8');

  $q = trim((string)($_GET['q'] ?? ''));
  if ($q === '') {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $like = '%' . $q . '%';
  $stmt = $pdo->prepare("
    SELECT id, codigo, nombre, categoria, marca, precio
    FROM productos
    WHERE (codigo LIKE ? OR nombre LIKE ?)
      AND activo = 1
    ORDER BY nombre ASC
    LIMIT 20
  ");
  $stmt->execute([$like, $like]);

  $productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  echo json_encode($productos, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ================================
   CSRF token para links GET
================================ */
$csrfQ = csrf_token();

/* ================================
   Preservar filtros en redirects POST
================================ */
function productos_filters_from(array $src): array {
  $allowed = ['q','estado','limit','sort','dir','page'];
  $out = [];
  foreach ($allowed as $k) {
    if (array_key_exists($k, $src)) {
      $v = $src[$k];
      if (is_scalar($v)) {
        $v = (string)$v;
        if ($v !== '') $out[$k] = $v;
      }
    }
  }
  return $out;
}

function productos_return_params_from_post(): array {
  $raw = $_POST['return_qs'] ?? '';
  if (!is_string($raw) || $raw === '') return [];
  $raw = ltrim($raw, '?');
  parse_str($raw, $parsed);
  return is_array($parsed) ? productos_filters_from($parsed) : [];
}

$returnQs = http_build_query(productos_filters_from($_GET));

/* ================================
   TOGGLE ESTADO (GET + CSRF)
================================ */
if (isset($_GET['toggle'])) {
  $id     = (int)($_GET['toggle'] ?? 0);
  $action = (string)($_GET['action'] ?? '');

  $qs = $_GET;
  $qs = productos_clean_qs($qs);

  if ($id <= 0 || !in_array($action, ['activate', 'deactivate'], true)) {
    $qs['toast'] = 'error';
    $qs['toast_msg'] = 'Acción inválida.';
    header('Location: productos.php?' . http_build_query($qs));
    exit;
  }

  if (!csrf_verify($_GET['csrf_token'] ?? null)) {
    $qs['toast'] = 'error';
    $qs['toast_msg'] = 'Token inválido. Recargá y probá de nuevo.';
    header('Location: productos.php?' . http_build_query($qs));
    exit;
  }

  $newValue = ($action === 'activate') ? 1 : 0;
  $pdo->prepare("UPDATE productos SET activo = ? WHERE id = ?")->execute([$newValue, $id]);

  $qs['toast'] = $action === 'activate' ? 'activated' : 'deactivated';
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
    $id = (isset($_POST['id']) && $_POST['id'] !== '') ? (int)$_POST['id'] : null;

    $codigo    = trim((string)($_POST['codigo'] ?? ''));
    $nombre    = trim((string)($_POST['nombre'] ?? ''));
    $categoria = trim((string)($_POST['categoria'] ?? ''));
    $marca     = trim((string)($_POST['marca'] ?? ''));
    $proveedor = trim((string)($_POST['proveedor'] ?? ''));

    $ivaRaw = (string)($_POST['iva'] ?? '');
    $iva    = ($ivaRaw === '') ? null : (float)$ivaRaw;

    $precio = (float)(parse_decimal((string)($_POST['precio'] ?? ''), 0.0) ?? 0.0);
    $costo  = parse_decimal(isset($_POST['costo']) ? (string)$_POST['costo'] : null, null);

    $stock       = (float)(parse_decimal(isset($_POST['stock']) ? (string)$_POST['stock'] : null, 0.0) ?? 0.0);
    $stockMinimo = (float)(parse_decimal(isset($_POST['stock_minimo']) ? (string)$_POST['stock_minimo'] : null, 0.0) ?? 0.0);

    $activo = isset($_POST['activo']) ? 1 : 0;

    $esPesable   = isset($_POST['es_pesable']) ? 1 : 0;
    $unidadVenta = trim((string)($_POST['unidad_venta'] ?? 'UNIDAD'));
    if ($unidadVenta === '') $unidadVenta = 'UNIDAD';

    if ($precio < 0) $precio = 0;
    if ($stock < 0) $stock = 0;
    if ($stockMinimo < 0) $stockMinimo = 0;

    $ivaPermitidos = [0.0, 10.5, 21.0];
    if ($iva !== null && !in_array((float)$iva, $ivaPermitidos, true)) {
      $iva = null;
    }

    $unidadesPermitidas = ['UNIDAD', 'KG', 'G', 'LT', 'ML'];
    if (!in_array($unidadVenta, $unidadesPermitidas, true)) {
      $unidadVenta = 'UNIDAD';
    }

    /* ================================
      VALIDACIÓN COHERENCIA PESABLES
    ================================ */
    $unidadesPesables = ['KG', 'G', 'LT', 'ML'];

    if ($esPesable === 1 && $unidadVenta === 'UNIDAD') {
      $msg = "Error: Producto pesable debe tener unidad de peso o volumen (KG, G, LT, ML).";
    }

    if ($msg === '' && $esPesable === 0 && in_array($unidadVenta, $unidadesPesables, true)) {
      $msg = "Error: Producto con unidad de peso/volumen debe estar marcado como pesable.";
    }

    if ($msg === '' && $esPesable === 1 && $precio <= 0) {
      $msg = "Error: Producto pesable debe tener precio mayor a $0.";
    }

    $imagenNombre   = null;
    $imagenAnterior = null;

    if ($id) {
      $stImg = $pdo->prepare("SELECT imagen FROM productos WHERE id = ? LIMIT 1");
      $stImg->execute([$id]);
      $imagenAnterior = $stImg->fetchColumn() ?: null;
      $imagenNombre   = $imagenAnterior;
    }

    if ($codigo === '' || $nombre === '' || $precio <= 0) {
      $msg = "Código, nombre y precio son obligatorios (precio > 0).";
    }

    if ($msg === '') {
      $stDup = $pdo->prepare("SELECT id FROM productos WHERE codigo = ? AND (? IS NULL OR id <> ?) LIMIT 1");
      $stDup->execute([$codigo, $id, $id]);
      if ($stDup->fetchColumn()) {
        $msg = "Ya existe un producto con ese código.";
      }
    }

    if (
      $msg === '' &&
      !empty($_FILES['imagen']['name']) &&
      (int)($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
    ) {
      $tmpName  = (string)($_FILES['imagen']['tmp_name'] ?? '');
      $origName = (string)($_FILES['imagen']['name'] ?? '');
      $size     = (int)($_FILES['imagen']['size'] ?? 0);

      if ($size > 3 * 1024 * 1024) {
        $msg = "La imagen es muy pesada (máx 3MB).";
      } else {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $extPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        $isImg = @getimagesize($tmpName);

        if (!$isImg) {
          $msg = "El archivo subido no es una imagen válida.";
        } elseif (!in_array($ext, $extPermitidas, true)) {
          $msg = "Formato de imagen no permitido (jpg, jpeg, png, webp, gif).";
        } else {
          $safeName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

          if (move_uploaded_file($tmpName, $uploadDirFs . $safeName)) {
            $imagenNombre = $safeName;

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

    if ($msg === '') {
      if ($id) {
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

        $params = productos_return_params_from_post();
        
        // ✅ FIX: NO incluir 'editar' en el redirect
        unset($params['editar']);
        unset($params['ajax']);
        
        $params['saved'] = 'updated';
        header("Location: productos.php?" . http_build_query($params));
        exit;
      } else {
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

        $params = productos_return_params_from_post();
        
        // ✅ FIX: NO incluir 'editar' en el redirect
        unset($params['editar']);
        unset($params['ajax']);
        
        $params['saved'] = 'created';
        $params['clearForm'] = '1';
        header("Location: productos.php?" . http_build_query($params));
        exit;
      }
    }
  }
}

/* ================================
   OBTENER PRODUCTO PARA EDICIÓN
   ✅ FIX: Limpiar URL después de cargar
================================ */
$editProducto = null;
$esModoEdicion = false;

if (isset($_GET['editar'])) {
  $id = (int)($_GET['editar'] ?? 0);
  if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM productos WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $editProducto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    
    if ($editProducto) {
      $esModoEdicion = true;
    }
  }
}

$esPesableForm   = 0;
$unidadVentaForm = 'UNIDAD';
if (!empty($editProducto)) {
  $esPesableForm   = (int)($editProducto['es_pesable'] ?? 0);
  $unidadVentaForm = (string)($editProducto['unidad_venta'] ?? 'UNIDAD');
}

/* Respuesta AJAX panel editar */
if (isset($_GET['editar']) && isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($editProducto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/* ================================
   FILTROS + PAGINACIÓN (Normal)
================================ */
$buscar = trim((string)($_GET['q'] ?? ''));
$estado = (string)($_GET['estado'] ?? '');

$perPageOptions = [20, 50, 100];
$perPage = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if (!in_array($perPage, $perPageOptions, true) || $perPage <= 0) $perPage = 50;

$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

$validSortColumns = ['codigo', 'nombre', 'categoria', 'marca', 'proveedor', 'iva', 'precio', 'stock'];
$sort = (string)($_GET['sort'] ?? 'nombre');
if (!in_array($sort, $validSortColumns, true)) $sort = 'nombre';

$dirParam = strtolower((string)($_GET['dir'] ?? 'asc'));
$dir      = ($dirParam === 'desc') ? 'DESC' : 'ASC';

$where  = [];
$params = [];

if ($buscar !== '') {
  $like    = '%' . $buscar . '%';
  $where[] = '(codigo LIKE ? OR nombre LIKE ? OR categoria LIKE ? OR marca LIKE ? OR proveedor LIKE ?)';
  array_push($params, $like, $like, $like, $like, $like);
}

if ($estado === 'activos') {
  $where[] = 'activo = 1';
} elseif ($estado === 'inactivos') {
  $where[] = 'activo = 0';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$orderSql = "ORDER BY activo DESC, {$sort} {$dir}";

/* ================================
   AJAX TBODY
================================ */
if (isset($_GET['ajaxTbody'])) {
  $validSort = ['nombre','codigo','precio','stock','categoria','marca','proveedor'];
  $sortAjax  = (string)($_GET['sort'] ?? 'nombre');
  $dirAjax   = strtoupper((string)($_GET['dir'] ?? 'ASC'));

  if (!in_array($sortAjax, $validSort, true)) $sortAjax = 'nombre';
  if (!in_array($dirAjax, ['ASC','DESC'], true)) $dirAjax = 'ASC';

  $where2  = [];
  $params2 = [];

  $q2 = trim((string)($_GET['q'] ?? ''));
  $e2 = (string)($_GET['estado'] ?? '');

  if ($q2 !== '') {
    $like2 = '%' . $q2 . '%';
    $where2[] = '(codigo LIKE ? OR nombre LIKE ? OR categoria LIKE ? OR marca LIKE ? OR proveedor LIKE ?)';
    array_push($params2, $like2, $like2, $like2, $like2, $like2);
  }

  if ($e2 === 'activos') $where2[] = 'activo = 1';
  if ($e2 === 'inactivos') $where2[] = 'activo = 0';

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

  header('Content-Type: text/html; charset=utf-8');
  echo productos_render_tbody($productos2, $uploadDirUrl, $csrfQ, $_GET);
  exit;
}

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

/* Listado (paginado) */
$sql = "
  SELECT *
  FROM productos
  {$whereSql}
  {$orderSql}
  LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);

foreach ($params as $i => $v) {
  $stmt->bindValue($i + 1, $v);
}
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);

$stmt->execute();
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ================================
   VISTA
================================ */
$pageTitle      = 'Productos';
$currentSection = 'productos';

$ver = '20260108_01'; // ✅ bump version
$extraCss = ["assets/css/productos.css?v={$ver}"];
$extraJs  = ["assets/js/productos.js?v={$ver}"];

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-wrap productos-page">

  <div class="panel">
    <?php
      // ✅ FIX: Mostrar formulario solo si estamos editando o hubo error
      $showForm = $esModoEdicion;
      if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($msg)) $showForm = true;
    ?>

    <div class="productos-header">
      <div class="productos-header-left">
        <h1 class="page-title">Productos</h1>
        <p class="page-sub">Gestión de productos del sistema</p>
      </div>

      <button type="button"
        class="btn btn-primary btn-new-product"
        id="toggleFormBtn"
        data-toggle-product-form="1"
        aria-controls="productFormBlock"
        aria-expanded="<?= $showForm ? 'true' : 'false' ?>">
        <span class="label"><?= $esModoEdicion ? 'Editar producto' : 'Agregar producto' ?></span>
      </button>
    </div>

    <div id="productFormBlock" class="product-form-block<?= $showForm ? '' : ' is-collapsed' ?>">
      <form method="post" class="productos-form" enctype="multipart/form-data" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">

        <?php if (!empty($editProducto)): ?>
          <input type="hidden" name="id" value="<?= (int)$editProducto['id'] ?>">
        <?php endif; ?>

        <div class="pf-grid">
          <div class="pf-field">
            <label class="is-required">Código</label>
            <input name="codigo" value="<?= h($editProducto['codigo'] ?? '') ?>" required>
          </div>

          <div class="pf-field pf-field-wide">
            <label class="is-required">Nombre</label>
            <input name="nombre" value="<?= h($editProducto['nombre'] ?? '') ?>" required>
          </div>

          <div class="pf-field">
            <label>Categoría</label>
            <input name="categoria" list="categorias-list" autocomplete="off" value="<?= h($editProducto['categoria'] ?? '') ?>">
            <datalist id="categorias-list"></datalist>
          </div>

          <div class="pf-field">
            <label>Marca</label>
            <input name="marca" list="marcas-list" autocomplete="off" value="<?= h($editProducto['marca'] ?? '') ?>">
            <datalist id="marcas-list"></datalist>
          </div>

          <div class="pf-field pf-field-wide">
            <label>Proveedor</label>
            <input name="proveedor" list="proveedores-list" autocomplete="off" value="<?= h($editProducto['proveedor'] ?? '') ?>">
            <datalist id="proveedores-list"></datalist>
          </div>

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
            <label class="is-required">Precio</label>
            <input type="number" step="0.01" min="0.01" name="precio" value="<?= h($editProducto['precio'] ?? '0') ?>" required>
          </div>

          <div class="pf-field">
            <label>Costo</label>
            <input type="number" step="0.01" min="0" name="costo" value="<?= h($editProducto['costo'] ?? '') ?>">
          </div>

          <div class="pf-field">
            <label>Stock</label>
            <input type="number" name="stock" step="0.001" min="0" value="<?= h($editProducto['stock'] ?? '0') ?>">
          </div>

          <div class="pf-field">
            <label>Stock mínimo</label>
            <input type="number" name="stock_minimo" step="0.001" min="0" value="<?= h($editProducto['stock_minimo'] ?? '0') ?>">
          </div>

          <!-- Sistema compacto de productos pesables -->
          <div class="pf-field pf-field-pesable">
            <div class="pf-label-top">Producto pesable</div>
            <div class="pf-pesable-row">
              <label class="edit-switch">
                <input type="checkbox" name="es_pesable" value="1" id="esPesableMain" <?= $esPesableForm ? 'checked' : '' ?>>
                <span class="edit-switch-slider"></span>
              </label>
              <div class="pf-pesable-text">
                <div class="pf-pesable-title">Venta por peso / volumen</div>
                <p class="pf-help-text">Ej: carnicería, fiambres, frutas por kilo.</p>
              </div>
            </div>
          </div>

          <div class="pf-field pf-field-wide pesable-units-container" id="pesableOptionsMain" <?= $esPesableForm ? '' : 'style="display:none;"' ?>>
            <div class="units-compact-grid">
              <label class="unit-compact-card">
                <input type="radio" name="unidad_venta_visual" value="KG" <?= ($esPesableForm && $unidadVentaForm === 'KG') ? 'checked' : '' ?>>
                <span class="unit-compact-content">
                  <span class="unit-compact-icon">🍖</span>
                  <span class="unit-compact-label">1 KG</span>
                </span>
              </label>

              <label class="unit-compact-card">
                <input type="radio" name="unidad_venta_visual" value="G" <?= ($esPesableForm && $unidadVentaForm === 'G') ? 'checked' : '' ?>>
                <span class="unit-compact-content">
                  <span class="unit-compact-icon">🥩</span>
                  <span class="unit-compact-label">100 G</span>
                </span>
              </label>

              <label class="unit-compact-card">
                <input type="radio" name="unidad_venta_visual" value="LT" <?= ($esPesableForm && $unidadVentaForm === 'LT') ? 'checked' : '' ?>>
                <span class="unit-compact-content">
                  <span class="unit-compact-icon">🥛</span>
                  <span class="unit-compact-label">1 Litro</span>
                </span>
              </label>

              <label class="unit-compact-card">
                <input type="radio" name="unidad_venta_visual" value="ML" <?= ($esPesableForm && $unidadVentaForm === 'ML') ? 'checked' : '' ?>>
                <span class="unit-compact-content">
                  <span class="unit-compact-icon">🧃</span>
                  <span class="unit-compact-label">100 ML</span>
                </span>
              </label>
            </div>

            <div class="units-compact-preview" id="pesablePreviewMain">
              <span class="preview-compact-label">Vista previa:</span>
              <span class="preview-compact-value">—</span>
            </div>

            <input type="hidden" name="unidad_venta" id="unidad_venta_real_main" value="<?= h($unidadVentaForm) ?>">
          </div>

          <div class="pf-field pf-field-wide">
            <label>Imagen (opcional)</label>
            <div class="file-input">
              <input type="file" name="imagen" id="imagen" accept="image/*" class="file-input-hidden">
              <label for="imagen" class="file-btn"><span>Seleccionar archivo</span></label>
              <span id="fileName" class="file-name">Ningún archivo seleccionado</span>
            </div>
          </div>
        </div>

        <div class="pf-status-row">
          <div class="pf-status-info">
            <span class="pf-status-label">Estado del producto</span>
            <p class="pf-status-help">Los productos inactivos no aparecen en Caja ni en búsquedas.</p>
          </div>

          <label class="edit-switch">
            <input type="checkbox" name="activo" <?= (!isset($editProducto) || (int)($editProducto['activo'] ?? 1) === 1) ? 'checked' : '' ?>>
            <span class="edit-switch-slider"></span>
            <span class="edit-switch-text">Activo</span>
          </label>
        </div>

        <div class="pf-actions">
          <button class="btn btn-primary" type="submit">
            <?= $esModoEdicion ? 'Actualizar' : 'Guardar' ?>
          </button>
          <button class="btn btn-secondary" type="button" id="btnClearForm" data-clear-form="1" title="Limpiar formulario">
            Limpiar
          </button>

          <?php if (!empty($editProducto)): ?>
            <a class="btn btn-secondary" href="productos.php<?= $returnQs ? '?' . h($returnQs) : '' ?>">Cancelar</a>
          <?php endif; ?>
        </div>

        <?php if (!empty($msg)): ?>
          <div class="msg msg-visible msg-info" style="margin-top:12px;">
            <?= h($msg) ?>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="panel">
    <h2 class="sub-title-page">Listado</h2>

    <form method="get" class="filters" id="filtersForm">
      <div class="filters-left">
        <div class="search-wrapper">
          <input
            type="text"
            id="searchInput"
            name="q"
            placeholder="Buscar (Ctrl+K)"
            value="<?= h($buscar) ?>"
          >
        </div>
      </div>

      <div class="filters-right">
        <select name="estado" id="estadoSelect">
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

        <button type="button" id="btnExportCSV" class="btn-export" title="Exportar a Excel (CSV)">
          Exportar
        </button>

        <?php if ($buscar !== '' || $estado !== ''): ?>
          <a href="productos.php" class="btn btn-secondary">Limpiar</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="table-wrapper">
      <table class="productos-table" data-sort="<?= h($sort) ?>" data-dir="<?= h($dir) ?>">
        <thead>
          <tr>
            <th class="center col-thumb">Img</th>
            <th data-sort="codigo" class="<?= $sort === 'codigo' ? 'sorted-' . strtolower($dir) : '' ?>">Código</th>
            <th data-sort="nombre" class="<?= $sort === 'nombre' ? 'sorted-' . strtolower($dir) : '' ?>">Nombre</th>
            <th class="right <?= $sort === 'precio' ? 'sorted-' . strtolower($dir) : '' ?>" data-sort="precio">Precio</th>
            <th class="right <?= $sort === 'stock' ? 'sorted-' . strtolower($dir) : '' ?>" data-sort="stock">Stock</th>
            <th class="center">Estado</th>
            <th class="center">Acciones</th>
            <th class="center col-expand">
              <button type="button" class="btn-expand-all" title="Expandir todos" aria-label="Expandir detalles de todos los productos">⊕</button>
            </th>
          </tr>
        </thead>

        <tbody id="productosTbody">
          <?= productos_render_tbody($productos, $uploadDirUrl, $csrfQ, $_GET) ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalFiltrados > 0 && $totalPages > 1): ?>
      <div class="pagination">
        <div class="pagination-info">
          Mostrando <?= $totalFiltrados ? ($offset + 1) : 0 ?> – <?= min($offset + $perPage, $totalFiltrados) ?>
          de <?= $totalFiltrados ?> productos
        </div>

        <div class="pagination-pages">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php
              $paramsUrl         = $_GET;
              $paramsUrl['page'] = $i;
              $paramsUrl = productos_clean_qs($paramsUrl);
              $paramsUrl['sort'] = $sort;
              $paramsUrl['dir']  = $dir;
              $url = 'productos.php?' . http_build_query($paramsUrl);
            ?>
            <a href="<?= h($url) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>">
              <?= $i ?>
            </a>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- OVERLAY EDITAR -->
<div id="editOverlay" class="edit-overlay">
  <div id="editPanel" class="edit-panel">
    <div class="edit-panel-head">
      <h2>Editar producto</h2>
      <button class="close-edit" type="button" onclick="closeEditPanel()">✕</button>
    </div>

    <form id="editForm" method="post" action="productos.php" class="edit-form" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
      <input type="hidden" name="id">

      <div class="edit-grid">
        <div class="edit-field">
          <label class="is-required">Código</label>
          <input name="codigo">
        </div>

        <div class="edit-field">
          <label class="is-required">Nombre</label>
          <input name="nombre">
        </div>

        <div class="edit-field">
          <label>Categoría</label>
          <input name="categoria" list="categorias-list-edit" autocomplete="off">
          <datalist id="categorias-list-edit"></datalist>
        </div>

        <div class="edit-field">
          <label>Marca</label>
          <input name="marca" list="marcas-list-edit" autocomplete="off">
          <datalist id="marcas-list-edit"></datalist>
        </div>

        <div class="edit-field">
          <label>Proveedor</label>
          <input name="proveedor" list="proveedores-list-edit" autocomplete="off">
          <datalist id="proveedores-list-edit"></datalist>
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
          <label class="is-required">Precio</label>
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
              <input type="checkbox" name="es_pesable" value="1" id="esPesableEdit">
              <span class="edit-switch-slider"></span>
            </label>
            <div class="edit-pesable-text">
              <div class="edit-pesable-title">Venta por peso / volumen</div>
              <div class="edit-help">Ej: carnicería, fiambres, frutas por kilo.</div>
            </div>
          </div>
        </div>

        <div class="edit-field edit-field-full pesable-units-container" id="pesableOptionsEdit" style="display:none;">
          <div class="units-compact-grid">
            <label class="unit-compact-card">
              <input type="radio" name="unidad_venta_visual_edit" value="KG">
              <span class="unit-compact-content">
                <span class="unit-compact-icon">🍖</span>
                <span class="unit-compact-label">1 KG</span>
              </span>
            </label>

            <label class="unit-compact-card">
              <input type="radio" name="unidad_venta_visual_edit" value="G">
              <span class="unit-compact-content">
                <span class="unit-compact-icon">🥩</span>
                <span class="unit-compact-label">100 G</span>
              </span>
            </label>

            <label class="unit-compact-card">
              <input type="radio" name="unidad_venta_visual_edit" value="LT">
              <span class="unit-compact-content">
                <span class="unit-compact-icon">🥛</span>
                <span class="unit-compact-label">1 Litro</span>
              </span>
            </label>

            <label class="unit-compact-card">
              <input type="radio" name="unidad_venta_visual_edit" value="ML">
              <span class="unit-compact-content">
                <span class="unit-compact-icon">🧃</span>
                <span class="unit-compact-label">100 ML</span>
              </span>
            </label>
          </div>

          <div class="units-compact-preview" id="pesablePreviewEdit">
            <span class="preview-compact-label">Vista previa:</span>
            <span class="preview-compact-value">—</span>
          </div>

          <input type="hidden" name="unidad_venta" id="unidad_venta_real_edit">
        </div>

        <div class="edit-field edit-field-full">
          <label>Imagen (opcional)</label>
          <input type="file" name="imagen" accept="image/*">
          <div class="edit-help" style="margin-top:6px;">Si subís una nueva imagen, reemplaza la anterior.</div>
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
          <div class="edit-status-help">Los productos inactivos no aparecen en Caja ni en búsquedas.</div>
        </div>
      </div>

      <div class="edit-actions">
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
        <button type="button" class="btn btn-secondary" onclick="closeEditPanel()">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL CONFIRMACIÓN -->
<div id="confirmToggle" class="confirm-overlay">
  <div class="confirm-dialog">
    <h3 id="confirmTitle">Cambiar estado</h3>
    <p id="confirmText">¿Confirmar acción?</p>

    <div class="confirm-actions">
      <button type="button" class="btn btn-secondary" id="confirmCancel">Cancelar</button>
      <button type="button" class="btn btn-danger" id="confirmAccept">Sí, continuar</button>
    </div>
  </div>
</div>

<?php
$inlineJs = $inlineJs ?? '';

// ✅ FIX: Limpiar parámetro 'editar' de la URL si cargamos en modo edición
if ($esModoEdicion) {
  $inlineJs .= <<<JS
  (function() {
    const url = new URL(window.location.href);
    if (url.searchParams.has('editar')) {
      url.searchParams.delete('editar');
      url.searchParams.delete('ajax');
      window.history.replaceState({}, '', url.toString());
    }
  })();
  JS;
}

if (!empty($_GET['saved'])) {
  $msgToast = ($_GET['saved'] === 'created')
    ? 'Producto creado correctamente.'
    : 'Producto actualizado correctamente.';
  $inlineJs .= "window.showToast && window.showToast(" . json_encode($msgToast) . ");";
}

if (!empty($_GET['toast'])) {
  $t  = (string)$_GET['toast'];
  $tm = (string)($_GET['toast_msg'] ?? '');

  if ($t === 'activated')    $inlineJs .= "window.showToast && window.showToast('Producto activado.');";
  if ($t === 'deactivated')  $inlineJs .= "window.showToast && window.showToast('Producto desactivado.');";
  if ($t === 'error')        $inlineJs .= "window.showToast && window.showToast(" . json_encode($tm ?: 'Ocurrió un error.') . ", 'error');";
}

// Limpiar parámetros de URL
$inlineJs .= <<<JS
(() => {
  const url = new URL(window.location.href);
  ['saved','toast','toast_msg','clearForm'].forEach(k => url.searchParams.delete(k));
  window.history.replaceState({}, "", url.pathname + (url.searchParams.toString() ? "?" + url.searchParams.toString() : ""));
})();
JS;

?>

<div class="keyboard-hints" id="keyboardHints">
  <div class="keyboard-hints-item">
    <kbd class="keyboard-hints-key">Ctrl</kbd> +
    <kbd class="keyboard-hints-key">K</kbd> = Buscar
  </div>
  <div class="keyboard-hints-item">
    <kbd class="keyboard-hints-key">Ctrl</kbd> +
    <kbd class="keyboard-hints-key">N</kbd> = Nuevo
  </div>
  <div class="keyboard-hints-item">
    <kbd class="keyboard-hints-key">Esc</kbd> = Cerrar
  </div>
</div>

<script>
setTimeout(() => {
  const hints = document.getElementById('keyboardHints');
  if (hints) {
    hints.classList.add('show');
    setTimeout(() => hints.classList.remove('show'), 5000);
  }
}, 1000);
</script>

<?php
require_once __DIR__ . '/partials/footer.php';