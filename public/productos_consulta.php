<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_any_permission(['ver_productos', 'editar_productos']);

$pdo = getPDO();
$canEdit = function_exists('user_has_permission') && user_has_permission('editar_productos');

$pageTitle = 'Productos - Consulta';
$currentSection = 'productos';
$bodyClass = 'page-consulta-catalogo';

$q = trim((string)($_GET['q'] ?? ''));
$categoria = trim((string)($_GET['categoria'] ?? ''));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$limit = in_array($limit, [20, 50, 100], true) ? $limit : 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(codigo LIKE :q OR nombre LIKE :q OR categoria LIKE :q OR marca LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($categoria !== '') {
    $where[] = 'categoria = :categoria';
    $params[':categoria'] = $categoria;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$categorias = $pdo->query("SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN) ?: [];

$countSql = "SELECT COUNT(*) FROM productos {$whereSql}";
$countStmt = $pdo->prepare($countSql);
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
    SELECT id, codigo, nombre, categoria, marca, precio, activo
    FROM productos
    {$whereSql}
    ORDER BY activo DESC, nombre ASC
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
.consulta-shell{display:grid;gap:16px}.consulta-panel{padding:24px;border:1px solid var(--panel-border,rgba(148,163,184,.2));border-radius:24px;background:var(--panel,#fff);box-shadow:var(--panel-shadow,0 18px 40px rgba(15,23,42,.12))}.consulta-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}.consulta-copy h1{margin:0 0 6px}.consulta-copy p{margin:0;color:var(--muted,#64748b)}.consulta-toolbar{display:grid;grid-template-columns:2fr 1fr auto auto;gap:12px;align-items:end}.consulta-toolbar .input,.consulta-toolbar select{width:100%}.status-chip{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:700}.status-chip--ok{background:rgba(34,197,94,.12);color:#15803d}.status-chip--off{background:rgba(148,163,184,.12);color:#475569}.consulta-empty{padding:32px 16px;text-align:center;color:var(--muted,#64748b)}@media (max-width:900px){.consulta-toolbar{grid-template-columns:1fr}.consulta-header{align-items:stretch}}
</style>
HTML;

require __DIR__ . '/partials/header.php';
?>

<div class="consulta-shell">
    <section class="consulta-panel">
        <div class="consulta-header">
            <div class="consulta-copy">
                <h1>Catalogo de productos</h1>
                <p>Vista de consulta para roles que necesitan ver el catalogo sin entrar al ABM.</p>
            </div>
            <?php if ($canEdit): ?>
                <a href="productos.php" class="btn btn-primary">Editar catalogo</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="consulta-panel">
        <form method="get" class="consulta-toolbar">
            <div>
                <label for="q">Buscar</label>
                <input class="input" id="q" name="q" value="<?= h($q) ?>" placeholder="Codigo, nombre, marca o categoria">
            </div>
            <div>
                <label for="categoria">Categoria</label>
                <select id="categoria" name="categoria" class="input">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $item): ?>
                        <option value="<?= h((string)$item) ?>" <?= $categoria === (string)$item ? 'selected' : '' ?>><?= h((string)$item) ?></option>
                    <?php endforeach; ?>
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

        <div class="table-wrapper" style="margin-top:16px;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Codigo</th>
                        <th>Producto</th>
                        <th>Categoria</th>
                        <th>Marca</th>
                        <th class="t-right">Precio</th>
                        <th class="center">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($productos === []): ?>
                        <tr><td colspan="6" class="consulta-empty">No hay productos para esos filtros.</td></tr>
                    <?php else: ?>
                        <?php foreach ($productos as $producto): ?>
                            <tr>
                                <td><code><?= h((string)($producto['codigo'] ?? '-')) ?></code></td>
                                <td><strong><?= h((string)($producto['nombre'] ?? '-')) ?></strong></td>
                                <td><?= h((string)($producto['categoria'] ?? '-')) ?></td>
                                <td><?= h((string)($producto['marca'] ?? '-')) ?></td>
                                <td class="t-right"><?= function_exists('money_ar') ? money_ar((float)($producto['precio'] ?? 0)) : '$' . number_format((float)($producto['precio'] ?? 0), 2, ',', '.') ?></td>
                                <td class="center">
                                    <span class="status-chip <?= ((int)($producto['activo'] ?? 1) === 1) ? 'status-chip--ok' : 'status-chip--off' ?>">
                                        <?= ((int)($producto['activo'] ?? 1) === 1) ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
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
