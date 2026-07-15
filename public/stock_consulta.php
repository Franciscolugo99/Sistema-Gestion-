<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_any_permission(['ver_stock', 'editar_stock']);

$pdo = getPDO();
$canEdit = function_exists('user_has_permission') && user_has_permission('editar_stock');

$pageTitle = 'Stock - Consulta';
$currentSection = 'stock';
$bodyClass = trim(($bodyClass ?? '') . ' page-consulta-stock');
$extraCss = array_merge($extraCss ?? [], ['assets/css/stock_consulta.css?v=2']);

$q = trim((string)($_GET['q'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? ''));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$limit = in_array($limit, [20, 50, 100], true) ? $limit : 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(codigo LIKE :q_codigo OR nombre LIKE :q_nombre OR categoria LIKE :q_categoria OR proveedor LIKE :q_proveedor)';
    $like = '%' . $q . '%';
    $params[':q_codigo'] = $like;
    $params[':q_nombre'] = $like;
    $params[':q_categoria'] = $like;
    $params[':q_proveedor'] = $like;
}
if ($estado === 'sin') {
    $where[] = 'activo = 1 AND stock <= 0';
} elseif ($estado === 'bajo') {
    $where[] = 'activo = 1 AND stock > 0 AND stock <= stock_minimo';
} elseif ($estado === 'ok') {
    $where[] = 'activo = 1 AND stock > stock_minimo';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM productos {$whereSql}");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$sql = "
    SELECT id, codigo, nombre, categoria, proveedor, stock, stock_minimo, activo
    FROM productos
    {$whereSql}
    ORDER BY
        CASE
            WHEN activo = 0 THEN 3
            WHEN stock <= 0 THEN 1
            WHEN stock <= stock_minimo THEN 2
            ELSE 4
        END,
        nombre ASC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$queryParams = $_GET;
$estadoLabels = [
    '' => 'Todos',
    'sin' => 'Sin stock',
    'bajo' => 'Bajo minimo',
    'ok' => 'OK',
];
$estadoActualLabel = $estadoLabels[$estado] ?? 'Todos';
$hasActiveFilters = $q !== '' || $estado !== '' || $limit !== 20;
$resultCopy = $totalRows === 1 ? '1 producto encontrado' : number_format($totalRows, 0, ',', '.') . ' productos encontrados';

function stock_consulta_estado(array $producto): array
{
    $activo = (int)($producto['activo'] ?? 1) === 1;
    $stock = (float)($producto['stock'] ?? 0);
    $minimo = (float)($producto['stock_minimo'] ?? 0);

    if (!$activo) {
        return ['Inactivo', 'status-chip--off'];
    }
    if ($stock <= 0) {
        return ['Sin stock', 'status-chip--out'];
    }
    if ($stock <= $minimo) {
        return ['Bajo minimo', 'status-chip--low'];
    }
    return ['OK', 'status-chip--ok'];
}

require __DIR__ . '/partials/header.php';
?>

<div class="stock-consulta-shell">
    <section class="panel stock-consulta-panel">
        <header class="module-header stock-consulta-header">
            <div class="module-header-main">
                <div class="module-header-hero">
                    <span class="module-header-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                            <path d="M4 7h16"></path>
                            <path d="M6 7v11a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"></path>
                            <path d="M9 7V5a3 3 0 0 1 6 0v2"></path>
                            <path d="M9 13h6"></path>
                            <path d="M9 16h4"></path>
                        </svg>
                    </span>
                    <div class="module-header-copy">
                        <span class="page-eyebrow module-eyebrow">Inventario de consulta</span>
                        <h1 class="page-title module-title">Consulta de stock</h1>
                        <p class="page-sub module-subtitle">Revisa existencias sin habilitar ajustes, compras ni cambios de productos.</p>
                    </div>
                </div>
            </div>
            <div class="module-header-actions stock-consulta-actions">
                <span class="stock-consulta-mode"><?= $canEdit ? 'Edicion disponible' : 'Solo lectura' ?></span>
                <?php if ($canEdit): ?>
                    <a href="stock.php" class="btn btn-primary">Gestionar stock</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="stock-consulta-summary" aria-label="Resumen de consulta">
            <div>
                <span>Resultados</span>
                <strong><?= h($resultCopy) ?></strong>
            </div>
            <div>
                <span>Estado</span>
                <strong><?= h($estadoActualLabel) ?></strong>
            </div>
            <div>
                <span>Vista</span>
                <strong><?= (int)$limit ?> por pagina</strong>
            </div>
        </div>

        <form method="get" class="stock-consulta-toolbar">
            <div class="stock-consulta-field stock-consulta-field--search">
                <label for="q">Buscar producto</label>
                <input class="input" type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Codigo, nombre, categoria o proveedor" autocomplete="off">
            </div>
            <div class="stock-consulta-field">
                <label for="estado">Estado</label>
                <select id="estado" name="estado" class="input">
                    <?php foreach ($estadoLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $estado === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="stock-consulta-field">
                <label for="limit">Mostrar</label>
                <select id="limit" name="limit" class="input">
                    <?php foreach ([20, 50, 100] as $item): ?>
                        <option value="<?= $item ?>" <?= $limit === $item ? 'selected' : '' ?>><?= $item ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="stock-consulta-toolbar-actions">
                <button type="submit" class="btn btn-primary">Aplicar</button>
                <?php if ($hasActiveFilters): ?>
                    <a href="stock_consulta.php" class="btn btn-secondary">Limpiar</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($hasActiveFilters): ?>
            <div class="stock-consulta-filters" aria-label="Filtros activos">
                <?php if ($q !== ''): ?>
                    <span>Busqueda: <?= h($q) ?></span>
                <?php endif; ?>
                <?php if ($estado !== ''): ?>
                    <span>Estado: <?= h($estadoActualLabel) ?></span>
                <?php endif; ?>
                <?php if ($limit !== 20): ?>
                    <span><?= (int)$limit ?> por pagina</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="table-wrapper stock-consulta-table-wrapper">
            <table class="table stock-consulta-table">
                <thead>
                    <tr>
                        <th>Codigo</th>
                        <th>Producto</th>
                        <th>Categoria</th>
                        <th>Proveedor</th>
                        <th class="t-right">Stock</th>
                        <th class="t-right">Minimo</th>
                        <th class="center">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($productos === []): ?>
                        <tr>
                            <td colspan="7" class="stock-consulta-empty">
                                <strong>No hay productos para esos filtros.</strong>
                                <span>Proba con otro codigo, nombre o estado de stock.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productos as $producto): ?>
                            <?php [$estadoLabel, $estadoClass] = stock_consulta_estado($producto); ?>
                            <tr>
                                <td><code><?= h((string)($producto['codigo'] ?? '-')) ?></code></td>
                                <td><strong><?= h((string)($producto['nombre'] ?? '-')) ?></strong></td>
                                <td><?= h((string)($producto['categoria'] ?? '-')) ?></td>
                                <td><?= h((string)($producto['proveedor'] ?? '-')) ?></td>
                                <td class="t-right"><?= number_format((float)($producto['stock'] ?? 0), 2, ',', '.') ?></td>
                                <td class="t-right"><?= number_format((float)($producto['stock_minimo'] ?? 0), 2, ',', '.') ?></td>
                                <td class="center"><span class="status-chip <?= h($estadoClass) ?>"><?= h($estadoLabel) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        $fromRow = $totalRows > 0 ? $offset + 1 : 0;
        $toRow = min($totalRows, $offset + $limit);
        ?>
        <?= render_pagination($page, $totalPages, $queryParams, true, $totalRows, $fromRow, $toRow) ?>
    </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
