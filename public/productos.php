<?php
// public/productos.php - VERSIÓN OPTIMIZADA (2026) ✅
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once FLUS_ROOT . '/src/productos_helpers.php';
require_login();
require_permission('editar_productos');

$pdo = getPDO();
$msg = "";


/* ================================
   PROVEEDORES (integración v3.2.2)
================================ */

/**
 * Evita INFORMATION_SCHEMA (algunas instalaciones tienen permisos limitados).
 */
function flus_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function flus_has_table(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function flus_norm_name(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    return $s;
}

/**
 * Busca proveedor por id (si existe). Devuelve [id, nombre] o null.
 */
function flus_get_proveedor_by_id(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT id, nombre FROM proveedores WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ? ['id' => (int)$row['id'], 'nombre' => (string)$row['nombre']] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Busca por nombre (case-insensitive) o crea. Devuelve id.
 */
function flus_get_or_create_proveedor(PDO $pdo, string $nombre): int {
    $nombre = flus_norm_name($nombre);
    if ($nombre === '') return 0;

    // Buscar por nombre (case-insensitive)
    try {
        $st = $pdo->prepare("SELECT id, nombre FROM proveedores WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1");
        $st->execute([$nombre]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['id'] > 0) {
            return (int)$row['id'];
        }
    } catch (Throwable $e) {
        // seguir
    }

    // Crear
    try {
        $st = $pdo->prepare("INSERT INTO proveedores (nombre, activo) VALUES (?, 1)");
        $st->execute([$nombre]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}




$FLUS_HAS_PROVEEDORES = flus_has_table($pdo, 'proveedores');
$FLUS_PRODUCTOS_HAS_PROVEEDOR_ID = flus_has_column($pdo, 'productos', 'proveedor_id');

// Lista de proveedores para autocomplete.
// Nota: en algunas instalaciones hay permisos limitados sobre information_schema;
// por eso intentamos la query directa aunque el helper de schema falle.
$proveedoresList = [];
$__provQueryOk = false;
try {
    $st = $pdo->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");
    $proveedoresList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $__provQueryOk = true;
} catch (Throwable $e) {
    $proveedoresList = [];
}
// Si el helper falló por permisos (information_schema) pero la query funcionó, consideramos la tabla existente
if (!$FLUS_HAS_PROVEEDORES && $__provQueryOk) {
    $FLUS_HAS_PROVEEDORES = true;
}

/* ================================
   CONSTANTES
================================ */
const IVA_PERMITIDOS = [0.0, 10.5, 21.0];
const UNIDADES_PERMITIDAS = ['UNIDAD', 'KG', 'G', 'LT', 'ML'];
const UNIDADES_PESABLES = ['KG', 'G', 'LT', 'ML'];
const IMG_MAX_SIZE = 3 * 1024 * 1024; // 3MB
const IMG_EXTENSIONES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const EXPORT_LIMIT = 10000;

/* ================================
   RUTA PARA GUARDAR IMÁGENES
================================ */
$uploadDirFs  = __DIR__ . '/img/productos/';
$uploadDirUrl = 'img/productos/';

if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0775, true);
}

/* ================================
   HELPERS
================================ */
function productos_status_tag(array $p): string {
    return match (flus_calcular_estado_producto($p)) {
        'inactivo' => '<span class="tag tag-inactivo">Inactivo</span>',
        'sin' => '<span class="tag tag-sin">Sin stock</span>',
        'bajo' => '<span class="tag tag-bajo">Stock bajo</span>',
        default => '<span class="tag tag-ok">OK</span>',
    };
}

function calcular_estado_producto(array $p): string {
    return flus_calcular_estado_producto($p);
}

function productos_clean_qs(array $qs): array {
    foreach ([
        'toggle','action','csrf_token',
        'ajaxList','ajaxTbody','ajax','editar',
        'saved','toast','toast_msg','clearForm',
    ] as $k) {
        unset($qs[$k]);
    }
    return $qs;
}

function productos_search_order_sql(string $sort, string $dir, string $buscar): array {
    $buscar = trim($buscar);
    if ($buscar === '') {
        return [
            'sql' => "ORDER BY activo DESC, {$sort} {$dir}",
            'params' => [],
        ];
    }

    return [
        'sql' => "ORDER BY activo DESC,
            CASE
                WHEN codigo = ? THEN 0
                WHEN codigo LIKE ? THEN 1
                WHEN nombre LIKE ? THEN 2
                WHEN categoria LIKE ? THEN 3
                WHEN marca LIKE ? THEN 4
                WHEN proveedor LIKE ? THEN 5
                ELSE 6
            END,
            {$sort} {$dir}",
        'params' => [
            $buscar,
            $buscar . '%',
            '%' . $buscar . '%',
            '%' . $buscar . '%',
            '%' . $buscar . '%',
            '%' . $buscar . '%',
        ],
    ];
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
        if ($id <= 0) continue; // Saltar productos inválidos
        
        $esPesable = (int)($p['es_pesable'] ?? 0);
        $codigo = $p['codigo'] ?? '';
        $nombre = $p['nombre'] ?? '(Sin nombre)';
        $precio = (float)($p['precio'] ?? 0);
        $stock = (float)($p['stock'] ?? 0);

        $thumbUrl = '';
        if (!empty($p['imagen'])) {
            $thumbUrl = $uploadDirUrl . (string)$p['imagen'];
        }

        $tag = productos_status_tag($p);
        $estadoStr = calcular_estado_producto($p);
        ?>
        <tr class="producto-row" 
            data-id="<?= $id ?>"
            data-codigo="<?= h($codigo) ?>"
            data-nombre="<?= h($nombre) ?>"
            data-precio="<?= $precio ?>"
            data-stock="<?= $stock ?>"
            data-estado="<?= $estadoStr ?>"
            data-activo="<?= (int)($p['activo'] ?? 0) ?>">
            <td class="center">
                <?php if ($thumbUrl): ?>
                    <img src="<?= h($thumbUrl) ?>" alt="img" class="prod-thumb">
                <?php else: ?>
                    <span class="prod-thumb-placeholder">📦</span>
                <?php endif; ?>
            </td>

            <td><code><?= h($codigo) ?: '—' ?></code></td>
            <td class="td-nombre">
                <div class="td-nombre-wrap">
                <strong><?= h($nombre) ?></strong>
                <?php if ($esPesable): ?>
                    <span class="badge-pesable">Pesable</span>
                <?php endif; ?>
                </div>
            </td>

            <td class="right">$<?= number_format($precio, 2, ',', '.') ?></td>
            <td class="right td-stock"><?= h(format_stock_con_unidad($p, 'stock', 3)) ?></td>

            <td class="center td-estado"><?= $tag ?></td>

            <td class="center">
                <div class="acciones">
<button
                    type="button"
                    class="btn-line btn-edit"
                    onclick="ProductosManager.openEdit(<?= $id ?>)"
                >Editar</button>

                <button
                    type="button"
                    class="btn-line btn-copy"
                    onclick="ProductosManager.copyProduct(<?= $id ?>)"
                >Copiar</button>

                <button
                    type="button"
                    class="btn-line btn-toggle"
                    onclick="ProductosManager.confirmToggle(<?= $id ?>, '<?= ((int)($p['activo'] ?? 0) === 1) ? 'desactivar' : 'activar' ?>')"
                >
                    <?= ((int)($p['activo'] ?? 0) === 1) ? 'Desactivar' : 'Activar' ?>
                </button>
                </div>
            </td>

            <td class="center">
                <button
                    type="button"
                    class="btn-expand"
                    onclick="ProductosManager.toggleDetail(<?= $id ?>)"
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
                        <span class="detail-value detail-stock-minimo"><?= h(format_stock_con_unidad($p, 'stock_minimo', 3)) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">IVA</span>
                        <span class="detail-value"><?= ($p['iva'] !== null) ? h((string)$p['iva']) . '%' : '—' ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Unidad</span>
                        <span class="detail-value detail-unidad"><?= h(flus_producto_unidad_descripcion((string)($p['unidad_venta'] ?? 'UNIDAD'), $esPesable === 1)) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Pesable</span>
                        <span class="detail-value"><?= $esPesable ? 'Sí' : 'No' ?></span>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    return (string)ob_get_clean();
}

/* ================================
   ENDPOINT: AUTOCOMPLETE (seguro)
================================ */
if (isset($_GET['autocomplete'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $field = (string)($_GET['autocomplete'] ?? '');
    
    // Switch explícito en lugar de interpolación
    switch ($field) {
        case 'categoria':
            $sql = "SELECT DISTINCT categoria as value FROM productos WHERE categoria IS NOT NULL AND categoria != '' AND activo = 1 ORDER BY categoria ASC LIMIT 100";
            break;
        case 'marca':
            $sql = "SELECT DISTINCT marca as value FROM productos WHERE marca IS NOT NULL AND marca != '' AND activo = 1 ORDER BY marca ASC LIMIT 100";
            break;
        case 'proveedor':
            $sql = "SELECT DISTINCT proveedor as value FROM productos WHERE proveedor IS NOT NULL AND proveedor != '' AND activo = 1 ORDER BY proveedor ASC LIMIT 100";
            break;
        default:
            echo json_encode([], JSON_UNESCAPED_UNICODE);
            exit;
    }
    
    $stmt = $pdo->query($sql);
    $values = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    echo json_encode($values, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================================
   ENDPOINT: ESTADÍSTICAS (optimizado)
================================ */
if (isset($_GET['stats'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stats = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) AS activos,
                SUM(CASE WHEN activo = 1 AND stock <= 0 THEN 1 ELSE 0 END) AS sin_stock,
                SUM(CASE WHEN activo = 1 AND stock > 0 AND stock <= stock_minimo THEN 1 ELSE 0 END) AS stock_bajo
            FROM productos
        ")->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'total' => (int)($stats['total'] ?? 0),
            'activos' => (int)($stats['activos'] ?? 0),
            'sin_stock' => (int)($stats['sin_stock'] ?? 0),
            'stock_bajo' => (int)($stats['stock_bajo'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
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
        SELECT id FROM productos
        WHERE codigo = ? AND (? IS NULL OR id <> ?)
        LIMIT 1
    ");
    $stmt->execute([$codigo, $id, $id]);

    $exists = $stmt->fetchColumn() !== false;
    echo json_encode(['exists' => $exists, 'codigo' => $codigo], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================================
   ENDPOINT: EXPORT CSV (servidor)
================================ */
if (isset($_GET['exportCSV'])) {
    // Construir filtros
    $buscarExp = trim((string)($_GET['q'] ?? ''));
    $estadoExp = (string)($_GET['estado'] ?? '');
    $stockFilterExp = (string)($_GET['stock_filter'] ?? ''); // ✅

    $whereExp = [];
    $paramsExp = [];

    if ($buscarExp !== '') {
        $likeExp = '%' . $buscarExp . '%';
        $whereExp[] = '(codigo LIKE ? OR nombre LIKE ? OR categoria LIKE ? OR marca LIKE ? OR proveedor LIKE ?)';
        array_push($paramsExp, $likeExp, $likeExp, $likeExp, $likeExp, $likeExp);
    }

    // ✅ stock_filter (KPIs) tiene prioridad lógica sobre estado
    if ($stockFilterExp === 'sin') {
        $whereExp[] = 'activo = 1';
        $whereExp[] = 'stock <= 0';
        $estadoExp = ''; // evita contradicciones
    } elseif ($stockFilterExp === 'bajo') {
        $whereExp[] = 'activo = 1';
        $whereExp[] = 'stock > 0';
        $whereExp[] = 'stock <= stock_minimo';
        $estadoExp = '';
    }

    if ($estadoExp === 'activos') {
        $whereExp[] = 'activo = 1';
    } elseif ($estadoExp === 'inactivos') {
        $whereExp[] = 'activo = 0';
    }

    $whereSqlExp = $whereExp ? 'WHERE ' . implode(' AND ', $whereExp) : '';

    $sqlExp = "
        SELECT codigo, nombre, categoria, marca, proveedor, precio, costo, stock, stock_minimo, activo, es_pesable, unidad_venta
        FROM productos
        {$whereSqlExp}
        ORDER BY nombre ASC
        LIMIT " . EXPORT_LIMIT;

    $stmtExp = $pdo->prepare($sqlExp);
    $stmtExp->execute($paramsExp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="productos_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 para Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // Header
    fputcsv($out, ['Código', 'Nombre', 'Categoría', 'Marca', 'Proveedor', 'Precio', 'Costo', 'Stock', 'Stock Mín.', 'Estado', 'Pesable', 'Unidad'], ';');

    while ($row = $stmtExp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['codigo'] ?? '',
            $row['nombre'] ?? '',
            $row['categoria'] ?? '',
            $row['marca'] ?? '',
            $row['proveedor'] ?? '',
            number_format((float)($row['precio'] ?? 0), 2, ',', ''),
            $row['costo'] ? number_format((float)$row['costo'], 2, ',', '') : '',
            (float)($row['stock'] ?? 0),
            (float)($row['stock_minimo'] ?? 0),
            (int)($row['activo'] ?? 0) ? 'Activo' : 'Inactivo',
            (int)($row['es_pesable'] ?? 0) ? 'Sí' : 'No',
            $row['unidad_venta'] ?? 'UNIDAD',
        ], ';');
    }

    fclose($out);
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
    // ✅ incluir stock_filter para no perderlo en redirects y return_qs
    $allowed = ['q','estado','stock_filter','limit','sort','dir','page'];
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
   TOGGLE ESTADO (AJAX o GET)
================================ */
if (isset($_GET['toggle'])) {
    $qs = productos_clean_qs($_GET);
    $qs['toast'] = 'error';
    $qs['toast_msg'] = 'La activación por enlace ya no está permitida. Usá el botón de la tabla.';
    header('Location: productos.php?' . http_build_query($qs));
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'toggle') {
    header('Content-Type: application/json; charset=utf-8');

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['toggle_action'] ?? '');

    if ($id <= 0 || !in_array($action, ['activate', 'deactivate'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parámetros inválidos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newValue = ($action === 'activate') ? 1 : 0;
    $pdo->prepare("UPDATE productos SET activo = ? WHERE id = ?")->execute([$newValue, $id]);

    $stmt = $pdo->prepare("SELECT * FROM productos WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => $action === 'activate' ? 'Producto activado' : 'Producto desactivado',
        'data' => [
            'id' => $id,
            'activo' => $newValue,
            'estado' => $producto ? calcular_estado_producto($producto) : ($newValue ? 'ok' : 'inactivo'),
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
/* ================================
   ALTA / EDICIÓN (POST + CSRF)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $isAjax = !empty($_POST['ajax']);
    
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $msg = "Token CSRF inválido. Recargá la página e intentá de nuevo.";
        if ($isAjax) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $id = (isset($_POST['id']) && $_POST['id'] !== '') ? (int)$_POST['id'] : null;

        $codigo    = trim((string)($_POST['codigo'] ?? ''));
        $nombre    = trim((string)($_POST['nombre'] ?? ''));
        $categoria = trim((string)($_POST['categoria'] ?? ''));
        $marca     = trim((string)($_POST['marca'] ?? ''));
        $proveedor = flus_norm_name((string)($_POST['proveedor'] ?? ''));
        $proveedorIdInput = (int)($_POST['proveedor_id'] ?? 0);

        // Integración Proveedores: preferir ID si viene de autocomplete.
        $proveedorId = 0;
        if ($FLUS_HAS_PROVEEDORES && $FLUS_PRODUCTOS_HAS_PROVEEDOR_ID) {
            if ($proveedorIdInput > 0) {
                $pr = flus_get_proveedor_by_id($pdo, $proveedorIdInput);
                if ($pr) {
                    $proveedorId = (int)$pr['id'];
                    $proveedor = flus_norm_name((string)$pr['nombre']);
                }
            }

            if ($proveedorId <= 0 && $proveedor !== '') {
                $proveedorId = flus_get_or_create_proveedor($pdo, $proveedor);
                $pr2 = flus_get_proveedor_by_id($pdo, $proveedorId);
                if ($pr2) {
                    $proveedor = flus_norm_name((string)$pr2['nombre']);
                }
            }

            // Permitir producto sin proveedor
            if ($proveedor === '') $proveedorId = 0;
        } else {
            // Compatibilidad: instalaciones sin proveedor_id
            $proveedorId = 0;
        }

        $ivaRaw = (string)($_POST['iva'] ?? '');
        $iva    = ($ivaRaw === '') ? null : (float)$ivaRaw;

        $precio = (float)(parse_decimal((string)($_POST['precio'] ?? ''), 0.0) ?? 0.0);
        $costo  = parse_decimal(isset($_POST['costo']) ? (string)$_POST['costo'] : null, null);

        // Stock con conversión de unidad
        $stockInput       = (float)(parse_decimal(isset($_POST['stock']) ? (string)$_POST['stock'] : null, 0.0) ?? 0.0);
        $stockMinInput    = (float)(parse_decimal(isset($_POST['stock_minimo']) ? (string)$_POST['stock_minimo'] : null, 0.0) ?? 0.0);
        $stockUnidadInput = strtoupper(trim((string)($_POST['stock_unidad'] ?? '')));
        if ($stockUnidadInput === '' || $stockUnidadInput === 'UNIDAD') $stockUnidadInput = null;

        $activo = isset($_POST['activo']) ? 1 : 0;

        $esPesable   = isset($_POST['es_pesable']) ? 1 : 0;
        $unidadVenta = trim((string)($_POST['unidad_venta'] ?? 'UNIDAD'));
        if ($unidadVenta === '') $unidadVenta = 'UNIDAD';

        // Convertir stock de la unidad del usuario a la unidad interna de FLUS
        $stock = $stockInput;
        $stockMinimo = $stockMinInput;
        
        if ($esPesable === 1 && $stockUnidadInput !== null) {
            // Primero convertir a base (gramos o mililitros)
            $stockBase = 0;
            $stockMinBase = 0;
            
            if ($stockUnidadInput === 'KG') {
                $stockBase = (int)round($stockInput * 1000);
                $stockMinBase = (int)round($stockMinInput * 1000);
            } elseif ($stockUnidadInput === 'G') {
                $stockBase = (int)round($stockInput);
                $stockMinBase = (int)round($stockMinInput);
            } elseif ($stockUnidadInput === 'LT') {
                $stockBase = (int)round($stockInput * 1000);
                $stockMinBase = (int)round($stockMinInput * 1000);
            } elseif ($stockUnidadInput === 'ML') {
                $stockBase = (int)round($stockInput);
                $stockMinBase = (int)round($stockMinInput);
            }
            
            // Ahora convertir de base a la unidad interna de FLUS (según unidad_venta)
            if ($unidadVenta === 'KG' || $unidadVenta === 'LT') {
                // FLUS guarda en KG o LT directamente
                $stock = $stockBase / 1000;
                $stockMinimo = $stockMinBase / 1000;
            } elseif ($unidadVenta === 'G' || $unidadVenta === 'ML') {
                // FLUS guarda en unidades de 100g o 100ml
                $stock = $stockBase / 100;
                $stockMinimo = $stockMinBase / 100;
            }
        }

        // Sanitizar valores
        if ($precio < 0) $precio = 0;
        if ($stock < 0) $stock = 0;
        if ($stockMinimo < 0) $stockMinimo = 0;

        if ($iva !== null && !in_array((float)$iva, IVA_PERMITIDOS, true)) {
            $iva = null;
        }

        if (!in_array($unidadVenta, UNIDADES_PERMITIDAS, true)) {
            $unidadVenta = 'UNIDAD';
        }

        // Validación coherencia pesables
        if ($esPesable === 1 && $unidadVenta === 'UNIDAD') {
            $msg = "Error: Producto pesable debe tener unidad de peso o volumen (KG, G, LT, ML).";
        }

        if ($msg === '' && $esPesable === 0 && in_array($unidadVenta, UNIDADES_PESABLES, true)) {
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

        // Validar código duplicado
        if ($msg === '') {
            $stDup = $pdo->prepare("SELECT id FROM productos WHERE codigo = ? AND (? IS NULL OR id <> ?) LIMIT 1");
            $stDup->execute([$codigo, $id, $id]);
            if ($stDup->fetchColumn()) {
                $msg = "Ya existe un producto con ese código.";
            }
        }

        // Procesar imagen
        if (
            $msg === '' &&
            !empty($_FILES['imagen']['name']) &&
            (int)($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        ) {
            $tmpName  = (string)($_FILES['imagen']['tmp_name'] ?? '');
            $origName = (string)($_FILES['imagen']['name'] ?? '');
            $size     = (int)($_FILES['imagen']['size'] ?? 0);

            if ($size > IMG_MAX_SIZE) {
                $msg = "La imagen es muy pesada (máx 3MB).";
            } else {
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                // Validación más robusta de imagen
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($tmpName);
                $mimePermitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

                if (!in_array($mimeType, $mimePermitidos, true)) {
                    $msg = "El archivo subido no es una imagen válida.";
                } elseif (!in_array($ext, IMG_EXTENSIONES, true)) {
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
            $provIdForDb = ($proveedorId > 0) ? $proveedorId : null;

            if ($id) {
                if ($FLUS_PRODUCTOS_HAS_PROVEEDOR_ID) {
                    $stmt = $pdo->prepare("
                        UPDATE productos SET
                            codigo = ?, nombre = ?, categoria = ?, marca = ?, proveedor = ?, proveedor_id = ?, iva = ?,
                            precio = ?, costo = ?, stock = ?, stock_minimo = ?,
                            es_pesable = ?, unidad_venta = ?,
                            activo = ?, imagen = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $codigo, $nombre, $categoria, $marca, $proveedor, $provIdForDb, $iva,
                        $precio, $costo, $stock, $stockMinimo,
                        $esPesable, $unidadVenta,
                        $activo, $imagenNombre, $id
                    ]);
                } else {
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
                }

                $savedId = $id;
                $savedAction = 'updated';
            } else {
                $stockInicial = $stock;

                if ($FLUS_PRODUCTOS_HAS_PROVEEDOR_ID) {
                    $stmt = $pdo->prepare("
                        INSERT INTO productos
                            (codigo, nombre, categoria, marca, proveedor, proveedor_id, iva,
                             precio, costo, stock, stock_minimo, stock_inicial,
                             es_pesable, unidad_venta,
                             activo, imagen)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $codigo, $nombre, $categoria, $marca, $proveedor, $provIdForDb, $iva,
                        $precio, $costo, $stock, $stockMinimo, $stockInicial,
                        $esPesable, $unidadVenta,
                        $activo, $imagenNombre
                    ]);
                } else {
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
                }

                $savedId = (int)$pdo->lastInsertId();
                $savedAction = 'created';
            }

            if ($isAjax) {
                // Obtener producto actualizado
                $stmtGet = $pdo->prepare("SELECT * FROM productos WHERE id = ? LIMIT 1");
                $stmtGet->execute([$savedId]);
                $productoGuardado = $stmtGet->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'message' => $savedAction === 'created' ? 'Producto creado' : 'Producto actualizado',
                    'action' => $savedAction,
                    'data' => [
                        'id' => $savedId,
                        'codigo' => $productoGuardado['codigo'],
                        'nombre' => $productoGuardado['nombre'],
                        'precio' => (float)$productoGuardado['precio'],
                        'stock' => (float)$productoGuardado['stock'],
                        'stock_minimo' => (float)$productoGuardado['stock_minimo'],
                        'stock_formatted' => format_stock_con_unidad($productoGuardado, 'stock', 3),
                        'stock_minimo_formatted' => format_stock_con_unidad($productoGuardado, 'stock_minimo', 3),
                        'unidad_venta' => (string)($productoGuardado['unidad_venta'] ?? 'UNIDAD'),
                        'unidad_venta_label' => flus_producto_unidad_descripcion((string)($productoGuardado['unidad_venta'] ?? 'UNIDAD'), (int)($productoGuardado['es_pesable'] ?? 0) === 1),
                        'activo' => (int)$productoGuardado['activo'],
                        'estado' => calcular_estado_producto($productoGuardado),
                        'es_pesable' => (int)$productoGuardado['es_pesable'],
                        'imagen' => $productoGuardado['imagen'] ? $uploadDirUrl . $productoGuardado['imagen'] : null,
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $params = productos_return_params_from_post();
            unset($params['editar'], $params['ajax']);
            $params['saved'] = $savedAction;
            if ($savedAction === 'created') $params['clearForm'] = '1';
            header("Location: productos.php?" . http_build_query($params));
            exit;
        } else {
            if ($isAjax) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
}

/* ================================
   OBTENER PRODUCTO PARA EDICIÓN
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
    
    if (!$editProducto) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // No enviar datos sensibles si no es necesario
    $safeData = [
        'id' => $editProducto['id'],
        'codigo' => $editProducto['codigo'],
        'nombre' => $editProducto['nombre'],
        'categoria' => $editProducto['categoria'],
        'marca' => $editProducto['marca'],
        'proveedor' => $editProducto['proveedor'],
        'proveedor_id' => (int)($editProducto['proveedor_id'] ?? 0),
        'iva' => $editProducto['iva'],
        'precio' => $editProducto['precio'],
        'costo' => $editProducto['costo'],
        'stock' => flus_producto_stock_input_value($editProducto['stock'] ?? 0, (string)($editProducto['unidad_venta'] ?? 'UNIDAD')),
        'stock_minimo' => flus_producto_stock_input_value($editProducto['stock_minimo'] ?? 0, (string)($editProducto['unidad_venta'] ?? 'UNIDAD')),
        'es_pesable' => $editProducto['es_pesable'],
        'unidad_venta' => $editProducto['unidad_venta'],
        'activo' => $editProducto['activo'],
        'imagen' => $editProducto['imagen'],
    ];
    
    echo json_encode($safeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ================================
   FILTROS + PAGINACIÓN
================================ */
$buscar = trim((string)($_GET['q'] ?? ''));
$estado = (string)($_GET['estado'] ?? '');
$stockFilter = (string)($_GET['stock_filter'] ?? ''); // 'sin' o 'bajo'
$pesableFilter = (string)($_GET['pesable'] ?? '');
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

if ($pesableFilter === 'si') {
    $where[] = 'es_pesable = 1';
} elseif ($pesableFilter === 'no') {
    $where[] = 'es_pesable = 0';
}

// Filtros de stock desde KPIs
if ($stockFilter === 'sin') {
    $where[] = 'activo = 1';
    $where[] = 'stock <= 0';
} elseif ($stockFilter === 'bajo') {
    $where[] = 'activo = 1';
    $where[] = 'stock > 0';
    $where[] = 'stock <= stock_minimo';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$orderSpec = productos_search_order_sql($sort, $dir, $buscar);
$orderSql = $orderSpec['sql'];
$orderParams = $orderSpec['params'];
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
    $p2 = (string)($_GET['pesable'] ?? '');

    if ($q2 !== '') {
        $like2 = '%' . $q2 . '%';
        $where2[] = '(codigo LIKE ? OR nombre LIKE ? OR categoria LIKE ? OR marca LIKE ? OR proveedor LIKE ?)';
        array_push($params2, $like2, $like2, $like2, $like2, $like2);
    }

    if ($e2 === 'activos') $where2[] = 'activo = 1';
    if ($e2 === 'inactivos') $where2[] = 'activo = 0';
    if ($p2 === 'si') $where2[] = 'es_pesable = 1';
    if ($p2 === 'no') $where2[] = 'es_pesable = 0';

    $whereSql2 = $where2 ? 'WHERE ' . implode(' AND ', $where2) : '';
    $orderSpec2 = productos_search_order_sql($sortAjax, $dirAjax, $q2);
    $orderSql2 = $orderSpec2['sql'];
    $orderParams2 = $orderSpec2['params'];

    $sql2 = "
        SELECT *
        FROM productos
        {$whereSql2}
        {$orderSql2}
        LIMIT 200
    ";
    $st2 = $pdo->prepare($sql2);
    $st2->execute(array_merge($params2, $orderParams2));
    $productos2 = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

foreach (array_merge($params, $orderParams) as $i => $v) {
    $stmt->bindValue($i + 1, $v);
}
$baseBindCount = count($params) + count($orderParams);
$stmt->bindValue($baseBindCount + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue($baseBindCount + 2, $offset,  PDO::PARAM_INT);

$stmt->execute();
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];



$stmt->execute();
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];



/* ================================
   AJAX LIST (JSON) - para búsqueda/paginación sin recargar
   Responde: {success:true, tbody_html, pagination_html, meta:{...}}
================================ */
if (isset($_GET['ajaxList'])) {
    header('Content-Type: application/json; charset=utf-8');

    // tbody
    $tbodyHtml = productos_render_tbody($productos, $uploadDirUrl, $csrfQ, $_GET);

    // paginación (mismo HTML que la vista)
    $paginationHtml = '';
    if ($totalFiltrados > 0 && $totalPages > 1) {
        ob_start();
        ?>
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
        <?php
        $paginationHtml = (string)ob_get_clean();
    }

    echo json_encode([
        'success' => true,
        'tbody_html' => $tbodyHtml,
        'pagination_html' => $paginationHtml,
        'meta' => [
            'q' => $buscar,
            'estado' => $estado,
            'stock_filter' => $stockFilter,
            'pesable' => $pesableFilter,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $perPage,
            'total' => $totalFiltrados,
            'total_pages' => $totalPages,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
/* ================================
   VISTA
================================ */
$pageTitle      = 'Productos';
$currentSection = 'productos';

$ver = '20260115_02';
$extraCss = ["assets/css/productos.css?v={$ver}"];
$extraJs  = ["assets/js/productos.js?v={$ver}"];

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-wrap productos-page">

    <div class="panel">
        <?php
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

        <!-- Quick Stats -->
        <div class="quick-stats" id="quickStats">
            <div class="stat-item">
                <div class="stat-value" id="statTotal">—</div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-item">
                <div class="stat-value" id="statActivos">—</div>
                <div class="stat-label">Activos</div>
            </div>
            <div class="stat-item">
                <div class="stat-value" id="statSinStock">—</div>
                <div class="stat-label">Sin Stock</div>
            </div>
            <div class="stat-item">
                <div class="stat-value" id="statBajoStock">—</div>
                <div class="stat-label">Stock Bajo</div>
            </div>
        </div>

        <div id="productFormBlock" class="product-form-block<?= $showForm ? '' : ' is-collapsed' ?>">
            <form method="post" class="productos-form" id="mainProductForm" enctype="multipart/form-data" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
                <input type="hidden" name="id" value="<?= $editProducto ? (int)$editProducto['id'] : '' ?>">

                <div class="pf-grid">
                    <div class="pf-field">
                        <label class="is-required">Código</label>
                        <input name="codigo" value="<?= h($editProducto['codigo'] ?? '') ?>" required>
                        <span class="field-status"></span>
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
                        <!-- Proveedores (datos precargados para autocomplete) -->
                        <select id="proveedoresData" style="display:none;">
                            <option value="">--</option>
                            <?php foreach ($proveedoresList as $pr): ?>
                                <option value="<?= (int)$pr['id'] ?>"><?= h((string)$pr['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>

                    </div>

                    <div class="pf-field pf-field-wide">
                        <label>Proveedor</label>

                        <div class="search-wrapper prov-autocomplete">
                            <input id="proveedorBuscar" name="proveedor" autocomplete="off"
                                   placeholder="Buscar proveedor…"
                                   value="<?= h($editProducto['proveedor'] ?? '') ?>">
                            <div id="proveedorSuggestions" class="suggestions-box"></div>
                        </div>

                        <input type="hidden" name="proveedor_id" id="proveedorId"
                               value="<?= (int)($editProducto['proveedor_id'] ?? 0) ?>">

                        <div class="field-help">Escribí y elegí (Enter). Si no existe, se creará al guardar.</div>
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
                        <div class="stock-unit-row">
                            <input type="number" name="stock" step="1" min="0" value="<?= h($editProducto['stock'] ?? '0') ?>">
                            <select name="stock_unidad" class="stock-unit-select js-stock-unit-select" disabled>
                                <option value="UNIDAD">UNID</option>
                                <option value="KG">KG</option>
                                <option value="G">G</option>
                                <option value="LT">LT</option>
                                <option value="ML">ML</option>
                            </select>
                        </div>
                        <div class="field-help js-stock-unit-help" style="margin-top:6px;"></div>
                    </div>

                    <div class="pf-field">
                        <label>Stock mínimo</label>
                        <div class="stock-unit-row">
                            <input type="number" name="stock_minimo" step="1" min="0" value="<?= h($editProducto['stock_minimo'] ?? '0') ?>">
                            <select name="stock_min_unidad" class="stock-unit-select js-stock-unit-select-min" disabled>
                                <option value="UNIDAD">UNID</option>
                                <option value="KG">KG</option>
                                <option value="G">G</option>
                                <option value="LT">LT</option>
                                <option value="ML">ML</option>
                            </select>
                        </div>
                    </div>

                    <!-- Pesables -->
                    <div class="pf-field pf-field-pesable">
                        <div class="pf-label-top">Producto pesable</div>
                        <div class="pf-pesable-row">
                            <label class="edit-switch">
                                <input type="checkbox" name="es_pesable" value="1" id="esPesableMain" <?= $esPesableForm ? 'checked' : '' ?>>
                                <span class="edit-switch-slider"><span class="edit-switch-thumb"></span></span>
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
                        <span class="edit-switch-slider"><span class="edit-switch-thumb"></span></span>
                        <span class="edit-switch-text">Activo</span>
                    </label>
                </div>

                <div class="pf-actions">
                    <button class="btn btn-primary" type="submit" id="btnSubmitMain">
                        <span class="btn-text"><?= $esModoEdicion ? 'Actualizar' : 'Guardar' ?></span>
                        <span class="btn-loading" style="display:none;">Guardando...</span>
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

                <select name="pesable" id="pesableSelect">
                    <option value="">Pesables y no pesables</option>
                    <option value="si" <?= $pesableFilter === 'si' ? 'selected' : '' ?>>Solo pesables</option>
                    <option value="no" <?= $pesableFilter === 'no' ? 'selected' : '' ?>>Solo no pesables</option>
                </select>

                <select name="limit" id="limitSelect">
                    <option value="20"  <?= $perPage === 20  ? 'selected' : '' ?>>20</option>
                    <option value="50"  <?= $perPage === 50  ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                </select>
                <input type="hidden" name="sort" id="sortInput" value="<?= h($sort) ?>">
                <input type="hidden" name="dir"  id="dirInput"  value="<?= h($dir) ?>">
                <input type="hidden" name="page" id="pageInput" value="<?= (int)$page ?>">

                <!-- CLAVE para KPIs clickeables -->
                <input type="hidden" name="stock_filter" id="stockFilterInput" value="<?= h($stockFilter) ?>">
                <button class="btn btn-filter" type="submit">Aplicar</button>

                <button type="button" id="btnExportCSV" class="btn-export" title="Exportar todos los productos filtrados">
                    Exportar CSV
                </button>

                <button type="button" id="btnRefresh" class="btn btn-secondary" title="Actualizar lista">
                    ↻
                </button>

                <?php if ($buscar !== '' || $estado !== '' || $stockFilter !== '' || $pesableFilter !== ''): ?>
                    <a href="productos.php" class="btn btn-secondary">Limpiar</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Filtros activos (chips con ×) - se renderiza por JS -->
        <div id="filtrosActivos" class="filtros-activos" style="display:none;"></div>

        <div id="paginationContainerTop">
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

        <div class="table-wrapper" id="tableWrapper">
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
                            <button type="button" class="btn-expand-all" onclick="ProductosManager.toggleAllDetails()" title="Expandir todos" aria-label="Expandir detalles de todos los productos">⊕</button>
                        </th>
                    </tr>
                </thead>

                <tbody id="productosTbody">
                    <?= productos_render_tbody($productos, $uploadDirUrl, $csrfQ, $_GET) ?>
                </tbody>
            </table>
        </div>
         <div id="paginationContainer">
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

</div>

<!-- OVERLAY EDITAR -->
<div id="editOverlay" class="edit-overlay">
    <div id="editPanel" class="edit-panel">
        <div class="edit-panel-head">
            <h2>Editar producto</h2>
            <button class="close-edit" type="button" onclick="ProductosManager.closeEdit(event)">✕</button>
        </div>

        <div class="edit-loading" id="editLoading">
            <div class="loading-spinner"></div>
            <p>Cargando producto...</p>
        </div>

        <form id="editForm" method="post" action="productos.php" class="edit-form" enctype="multipart/form-data" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
            <input type="hidden" name="id">

            <div class="edit-grid">
                <div class="edit-field">
                    <label class="is-required">Código</label>
                    <input name="codigo">
                    <span class="field-status"></span>
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

                    <div class="search-wrapper prov-autocomplete">
                        <input id="proveedorBuscarEdit" name="proveedor" autocomplete="off" placeholder="Buscar proveedor…">
                        <div id="proveedorSuggestionsEdit" class="suggestions-box"></div>
                    </div>

                    <input type="hidden" name="proveedor_id" id="proveedorIdEdit" value="0">
                    <div class="field-help">Escribí y elegí (Enter). Si no existe, se creará al guardar.</div>
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
                    <div class="stock-unit-row">
                        <input name="stock" type="number" step="1" min="0">
                        <select name="stock_unidad" class="stock-unit-select js-stock-unit-select" disabled>
                            <option value="UNIDAD">UNID</option>
                            <option value="KG">KG</option>
                            <option value="G">G</option>
                            <option value="LT">LT</option>
                            <option value="ML">ML</option>
                        </select>
                    </div>
                    <div class="edit-help js-stock-unit-help" style="margin-top:6px;"></div>
                </div>

                <div class="edit-field">
                    <label>Stock mínimo</label>
                    <div class="stock-unit-row">
                        <input name="stock_minimo" type="number" step="1" min="0">
                        <select name="stock_min_unidad" class="stock-unit-select js-stock-unit-select-min" disabled>
                            <option value="UNIDAD">UNID</option>
                            <option value="KG">KG</option>
                            <option value="G">G</option>
                            <option value="LT">LT</option>
                            <option value="ML">ML</option>
                        </select>
                    </div>
                </div>

                <div class="edit-field edit-field-pesable">
                    <div class="edit-label-top">Producto pesable</div>
                    <div class="edit-pesable-row">
                        <label class="edit-switch">
                            <input type="checkbox" name="es_pesable" value="1" id="esPesableEdit">
                            <span class="edit-switch-slider"><span class="edit-switch-thumb"></span></span>
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
                            <span class="edit-switch-slider"><span class="edit-switch-thumb"></span></span>
                            <span class="edit-switch-text">Activo</span>
                        </label>
                    </div>
                    <div class="edit-status-help">Los productos inactivos no aparecen en Caja ni en búsquedas.</div>
                </div>
            </div>

            <div class="edit-actions">
                <button class="btn btn-primary" type="submit" id="btnSubmitEdit">
                    <span class="btn-text">Guardar cambios</span>
                    <span class="btn-loading" style="display:none;">Guardando...</span>
                </button>
                <button type="button" class="btn btn-secondary" onclick="ProductosManager.closeEdit()">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CONFIRMACIÓN -->
<div id="confirmModal" class="confirm-overlay">
    <div class="confirm-dialog">
        <h3 id="confirmTitle">Confirmar acción</h3>
        <p id="confirmText">¿Estás seguro?</p>

        <div class="confirm-actions">
            <button type="button" class="btn btn-secondary" onclick="ProductosManager.closeConfirm()">Cancelar</button>
            <button type="button" class="btn btn-danger" id="confirmAccept">Sí, continuar</button>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer" class="toast-container"></div>

<!-- Keyboard hints -->
<div class="keyboard-hints" id="keyboardHints">
    <div class="keyboard-hints-item">
        <kbd>Ctrl</kbd> + <kbd>K</kbd> = Buscar
    </div>
    <div class="keyboard-hints-item">
        <kbd>Ctrl</kbd> + <kbd>N</kbd> = Nuevo
    </div>
    <div class="keyboard-hints-item">
        <kbd>Esc</kbd> = Cerrar
    </div>
</div>

<?php
$inlineJs = '';

// Limpiar parámetros de URL
if (!empty($_GET['saved']) || !empty($_GET['toast'])) {
    $toastMsg = '';
    $toastType = 'success';
    
    if ($_GET['saved'] === 'created') $toastMsg = 'Producto creado correctamente.';
    elseif ($_GET['saved'] === 'updated') $toastMsg = 'Producto actualizado correctamente.';
    elseif ($_GET['toast'] === 'activated') $toastMsg = 'Producto activado.';
    elseif ($_GET['toast'] === 'deactivated') $toastMsg = 'Producto desactivado.';
    elseif ($_GET['toast'] === 'error') {
        $toastMsg = $_GET['toast_msg'] ?? 'Ocurrió un error.';
        $toastType = 'error';
    }
    
    if ($toastMsg) {
        $inlineJs .= "ProductosManager.showToast(" . json_encode($toastMsg) . ", '" . $toastType . "');";
    }
    
    $inlineJs .= <<<JS
    (() => {
        const url = new URL(window.location.href);
        ['saved','toast','toast_msg','clearForm','editar','ajax'].forEach(k => url.searchParams.delete(k));
        window.history.replaceState({}, "", url.pathname + (url.searchParams.toString() ? "?" + url.searchParams.toString() : ""));
    })();
    JS;
}

// Mostrar hints de teclado brevemente
$inlineJs .= <<<JS
setTimeout(() => {
    const hints = document.getElementById('keyboardHints');
    if (hints) {
        hints.classList.add('show');
        setTimeout(() => hints.classList.remove('show'), 4000);
    }
}, 800);
JS;

if ($inlineJs) {
    echo "<script>document.addEventListener('DOMContentLoaded', () => { {$inlineJs} });</script>";
}

require_once __DIR__ . '/partials/footer.php';









