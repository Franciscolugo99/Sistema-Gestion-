<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_any_permission(['ver_stock', 'editar_stock']);

$pdo = getPDO();
$canEdit = function_exists('user_has_permission') && user_has_permission('editar_stock');

$pageTitle = 'Stock - Consulta';
$currentSection = 'stock';
$bodyClass = 'page-consulta-stock';

$q = trim((string)($_GET['q'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? ''));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$limit = in_array($limit, [20, 50, 100], true) ? $limit : 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(codigo LIKE :q OR nombre LIKE :q OR categoria LIKE :q OR proveedor LIKE :q)';
    $params[':q'] = '%' . $q . '%';
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

$extraHead = <<<'HTML'
<style>
.consulta-shell{display:grid;gap:16px}.consulta-panel{padding:24px;border:1px solid var(--panel-border,rgba(148,163,184,.2));border-radius:24px;background:var(--panel,#fff);box-shadow:var(--panel-shadow,0 18px 40px rgba(15,23,42,.12))}.consulta-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}.consulta-copy h1{margin:0 0 6px}.consulta-copy p{margin:0;color:var(--muted,#64748b)}.consulta-toolbar{display:grid;grid-template-columns:2fr 1fr auto auto;gap:12px;align-items:end}.consulta-toolbar .input,.consulta-toolbar select{width:100%}.consulta-table-wrapper{margin-top:16px}.status-chip{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:700}.status-chip--ok{background:rgba(34,197,94,.12);color:#15803d}.status-chip--low{background:rgba(245,158,11,.13);color:#b45309}.status-chip--out{background:rgba(239,68,68,.12);color:#dc2626}.status-chip--off{background:rgba(148,163,184,.12);color:#475569}.consulta-empty{padding:32px 16px;text-align:center;color:var(--muted,#64748b)}@media (max-width:900px){.consulta-toolbar{grid-template-columns:1fr}.consulta-header{align-items:stretch}}
</style>
HTML;

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

<div class="consulta-shell">
    <section class="consulta-panel">
        <div class="consulta-header">
            <div class="consulta-copy">
                <h1>Stock de productos</h1>
                <p>Vista de consulta para revisar existencias sin entrar a ajustes, compras ni analisis.</p>
            </div>
            <?php if ($canEdit): ?>
                <a href="stock.php" class="btn btn-primary">Gestionar stock</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="consulta-panel">
        <form method="get" class="consulta-toolbar">
            <div>
                <label for="q">Buscar</label>
                <input class="input" id="q" name="q" value="<?= h($q) ?>" placeholder="Codigo, nombre, categoria o proveedor">
            </div>
            <div>
                <label for="estado">Estado</label>
                <select id="estado" name="estado" class="input">
                    <option value="">Todos</option>
                    <option value="sin" <?= $estado === 'sin' ? 'selected' : '' ?>>Sin stock</option>
                    <option value="bajo" <?= $estado === 'bajo' ? 'selected' : '' ?>>Bajo minimo</option>
                    <option value="ok" <?= $estado === 'ok' ? 'selected' : '' ?>>OK</option>
                </select>
            </div>
            <div>
                <label for="limit">Mostrar</label>
                <select id="limit" name="limit" class="input">
                    <?php foreach ([20, 50, 100] as $item): ?>
                        <option value="<?= $item ?>" <?= $limit === $item ? 'selected' : '' ?>><?= $item ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Aplicar</button>
        </form>

        <div class="table-wrapper consulta-table-wrapper">
            <table class="table">
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
                        <tr><td colspan="7" class="consulta-empty">No hay productos para esos filtros.</td></tr>
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
