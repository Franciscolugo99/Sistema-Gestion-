<?php
// public/reposicion.php — Reposición Sugerida FLUS
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

// ── Permiso mínimo: ver stock o reportes ──────────────────────────────────────
if (!user_has_permission('ver_reportes') && !user_has_permission('ver_stock') && !user_has_permission('editar_stock')) {
    http_response_code(403);
    echo 'No tenés permisos para acceder a esta sección.';
    exit;
}

require_once __DIR__ . '/../src/reposicion_sugerida.php';
require_once __DIR__ . '/../src/db_helpers.php';

$pdo = getPDO();
reposicion_ensure_tables($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle      = 'Reposición Sugerida - FLUS';
$currentSection = 'reposicion';

// ── Assets ────────────────────────────────────────────────────────────────────
$extraCss = [
    'assets/css/reposicion.css',
    'assets/css/reposicion_mejoras.css',   // ← Quick Order + filtros + badges
];
$extraJs = [
    'assets/js/reposicion.js',
];

$info  = null;
$error = null;

// ── Vista activa ──────────────────────────────────────────────────────────────
$vista = (string)($_GET['v'] ?? 'alertas'); // alertas | lista | config

// ── Si config y sin permiso: redirigir a alertas ──────────────────────────────
if ($vista === 'config' && !user_has_permission('editar_stock')) {
    header('Location: ?v=alertas');
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
// Importante: permitir proveedor=0 ("Sin proveedor"). En PHP, empty('0') => true.
$proveedorRaw = $_GET['proveedor'] ?? null; // string|null
$proveedorIdFiltro = null;
if ($proveedorRaw !== null && $proveedorRaw !== '') {
    $proveedorIdFiltro = (int)$proveedorRaw; // puede ser 0
}

$filtros = [
    'proveedor_id' => $proveedorIdFiltro,
    'estado'       => !empty($_GET['estado']) ? (string)$_GET['estado'] : null,
    'buscar'       => !empty($_GET['q'])      ? trim((string)$_GET['q']) : null,
];

// ── Acciones POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Token CSRF inválido.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');

        // ── Guardar configuración ──────────────────────────────────────────────
        if ($accion === 'guardar_config' && user_has_permission('editar_stock')) {
            $productoId  = (int)  ($_POST['producto_id']   ?? 0);
            $stockMin    = (float)($_POST['stock_minimo']  ?? 0);
            $stockMax    = (float)($_POST['stock_maximo']  ?? 0);
            $puntoReorden= (float)($_POST['punto_reorden'] ?? 0);
            // Proveedor predeterminado (opcional). 0 => sin proveedor
            $provDefId   = isset($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;

            if ($productoId > 0) {
                if (reposicion_set_config($productoId, $stockMin, $stockMax, $puntoReorden, $provDefId)) {
                    $info = 'Configuración guardada.';
                } else {
                    $error = 'Error al guardar configuración.';
                }
            }

        // ── Exportar CSV ───────────────────────────────────────────────────────
        } elseif ($accion === 'exportar_csv') {
            $provRaw = (string)($_POST['proveedor_id'] ?? '');
            $proveedorId = null;
            if ($provRaw !== '') {
                $proveedorId = (int)$provRaw; // puede ser 0 ("Sin proveedor")
            }
            $csv = reposicion_exportar_csv($proveedorId);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="reposicion_' . date('Y-m-d') . '.csv"');
            echo $csv;
            exit;

        // ── Generar orden de compra BORRADOR ──────────────────────────────────
        } elseif ($accion === 'generar_orden_compra' && user_has_permission('editar_stock')) {
            try {
                $proveedorId = (int)($_POST['proveedor_id'] ?? 0);
                $productos   = json_decode((string)($_POST['productos'] ?? '[]'), true);

                if (!$proveedorId) {
                    throw new RuntimeException('Proveedor no especificado.');
                }
                if (!is_array($productos) || empty($productos)) {
                    throw new RuntimeException('No se recibieron productos.');
                }

                // ── Validar que el proveedor existe y está activo ──────────────
                $stProv = $pdo->prepare("SELECT id, nombre FROM proveedores WHERE id = ? AND activo = 1");
                $stProv->execute([$proveedorId]);
                $provRow = $stProv->fetch(PDO::FETCH_ASSOC);
                if (!$provRow) {
                    throw new RuntimeException("Proveedor ID {$proveedorId} no existe o no está activo.");
                }
                $provNombre = (string)($provRow['nombre'] ?? '');

                // ── Filtrar líneas vacías del cliente (solo id + cantidad) ────
                // SECURITY: el costo enviado por el cliente se DESCARTA.
                // Lo único que usamos del JSON es: id (int) y cantidad (float > 0).
                $lineasCliente = [];
                foreach ($productos as $prod) {
                    $pid      = (int)  ($prod['id']       ?? 0);
                    $cantidad = (float)($prod['cantidad'] ?? 0);
                    if ($pid > 0 && $cantidad > 0) {
                        $lineasCliente[$pid] = $cantidad; // dedup por id, última cantidad gana
                    }
                }

                if (empty($lineasCliente)) {
                    throw new RuntimeException('No se recibieron productos con cantidad mayor a 0.');
                }

                // ── Cargar costo real desde la BD (una sola query) ────────────
                $pids        = array_keys($lineasCliente);
                $placeholders = implode(',', array_fill(0, count($pids), '?'));
                $stProd = $pdo->prepare("
                    SELECT id, costo, nombre
                    FROM productos
                    WHERE id IN ($placeholders) AND activo = 1
                ");
                $stProd->execute($pids);
                $dbProductos = $stProd->fetchAll(PDO::FETCH_ASSOC);
                $dbMap = array_column($dbProductos, null, 'id'); // [id => row]

                // ── Validar que todos los IDs pedidos existen ─────────────────
                foreach ($pids as $pid) {
                    if (!isset($dbMap[$pid])) {
                        throw new RuntimeException("Producto ID {$pid} no existe o no está activo.");
                    }
                }

                // ── Armar lista definitiva con costo de BD ────────────────────
                $productosValidos = [];
                foreach ($lineasCliente as $pid => $cantidad) {
                    $productosValidos[] = [
                        'id'       => $pid,
                        'cantidad' => $cantidad,
                        'costo'    => (float)($dbMap[$pid]['costo'] ?? 0), // ← BD, no cliente
                    ];
                }

                // ── Calcular total con costos de BD ───────────────────────────
                $totalCompra = array_reduce($productosValidos, fn($s, $p) => $s + ($p['cantidad'] * $p['costo']), 0.0);

                // ── Detectar columnas opcionales en compras ────────────────────
                $HAS_TOTAL_NETO  = has_column($pdo, 'compras', 'total_neto');
                $HAS_TOTAL_IVA   = has_column($pdo, 'compras', 'total_iva');
                $HAS_TOTAL_BRUTO = has_column($pdo, 'compras', 'total_bruto');

                // ── Detectar columnas opcionales en compra_items ───────────────
                $HAS_ITEM_PRECIO   = has_column($pdo, 'compra_items', 'precio_unitario');
                $HAS_ITEM_DESCUENTO= has_column($pdo, 'compra_items', 'descuento');
                $HAS_ITEM_COMMENT  = has_column($pdo, 'compra_items', 'comentario');

                $pdo->beginTransaction();

                // ── INSERT compras ─────────────────────────────────────────────
                $cols   = ['fecha', 'proveedor_id', 'tipo_comp', 'nro_comp', 'obs', 'total', 'estado'];
                $vals   = [':fecha', ':proveedor_id', ':tipo_comp', ':nro_comp', ':obs', ':total', ':estado'];
                $params = [
                    ':fecha'        => date('Y-m-d'),
                    ':proveedor_id' => $proveedorId,
                    ':tipo_comp'    => 'REPO',
                    ':nro_comp'     => 'AUTO-' . date('YmdHis'),
                    ':obs'          => 'Generada desde Reposición Sugerida',
                    ':total'        => $totalCompra,
                    ':estado'       => 'BORRADOR',
                ];

                if ($HAS_TOTAL_NETO)  { $cols[] = 'total_neto';  $vals[] = ':total_neto';  $params[':total_neto']  = $totalCompra; }
                if ($HAS_TOTAL_IVA)   { $cols[] = 'total_iva';   $vals[] = ':total_iva';   $params[':total_iva']   = 0; }
                if ($HAS_TOTAL_BRUTO) { $cols[] = 'total_bruto'; $vals[] = ':total_bruto'; $params[':total_bruto'] = $totalCompra; }

                $sqlCompra = "INSERT INTO compras (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                $pdo->prepare($sqlCompra)->execute($params);
                $compraId = (int)$pdo->lastInsertId();

                // ── INSERT compra_items ────────────────────────────────────────
                $itemCols = ['compra_id', 'producto_id', 'cantidad', 'costo_unitario', 'subtotal'];
                $itemVals = [':compra_id', ':producto_id', ':cantidad', ':costo_unitario', ':subtotal'];
                if ($HAS_ITEM_PRECIO)    { $itemCols[] = 'precio_unitario'; $itemVals[] = ':precio_unitario'; }
                if ($HAS_ITEM_DESCUENTO) { $itemCols[] = 'descuento';       $itemVals[] = ':descuento'; }
                if ($HAS_ITEM_COMMENT)   { $itemCols[] = 'comentario';      $itemVals[] = ':comentario'; }

                $sqlItem  = "INSERT INTO compra_items (" . implode(', ', $itemCols) . ") VALUES (" . implode(', ', $itemVals) . ")";
                $stmtItem = $pdo->prepare($sqlItem);

                foreach ($productosValidos as $prod) {
                    $itemParams = [
                        ':compra_id'       => $compraId,
                        ':producto_id'     => $prod['id'],
                        ':cantidad'        => $prod['cantidad'],
                        ':costo_unitario'  => $prod['costo'],
                        ':subtotal'        => $prod['cantidad'] * $prod['costo'],
                    ];
                    if ($HAS_ITEM_PRECIO)    $itemParams[':precio_unitario'] = $prod['costo'];
                    if ($HAS_ITEM_DESCUENTO) $itemParams[':descuento']       = 0;
                    if ($HAS_ITEM_COMMENT)   $itemParams[':comentario']      = 'Reposición sugerida';

                    $stmtItem->execute($itemParams);
                }

                // ── (Opcional) Asignar proveedor a los productos seleccionados ─
                // Solo si el usuario pidió asignar y tiene permiso de configuración.
                $asignarProveedor = ((int)($_POST['asignar_proveedor'] ?? 0) === 1);
                if ($asignarProveedor && user_has_permission('editar_stock')) {
                    $hasProvTxt = has_column($pdo, 'productos', 'proveedor');
                    $pidsUpd    = array_keys($lineasCliente);
                    $phUpd      = implode(',', array_fill(0, count($pidsUpd), '?'));

                    if ($hasProvTxt) {
                        $sqlUpd = "UPDATE productos
                                  SET proveedor_id = ?, proveedor = ?
                                  WHERE id IN ($phUpd)
                                    AND (proveedor_id IS NULL OR proveedor_id = 0)";
                        $paramsUpd = array_merge([$proveedorId, $provNombre], $pidsUpd);
                    } else {
                        $sqlUpd = "UPDATE productos
                                  SET proveedor_id = ?
                                  WHERE id IN ($phUpd)
                                    AND (proveedor_id IS NULL OR proveedor_id = 0)";
                        $paramsUpd = array_merge([$proveedorId], $pidsUpd);
                    }

                    $pdo->prepare($sqlUpd)->execute($paramsUpd);
                }

                $pdo->commit();

                // ── Redirigir a compras para editar el borrador ────────────────
                header("Location: compras.php?editar={$compraId}&saved=repo");
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error al generar orden de compra: ' . $e->getMessage();
            }
        }
    }
}

// ── Cargar datos ──────────────────────────────────────────────────────────────
$conteoEstados = reposicion_conteo_estados();

$stockBajo         = [];
$listaPorProveedor = [];
$productos         = [];

if ($vista === 'alertas') {
    $stockBajo = reposicion_get_stock_bajo(300, $filtros);
}

if ($vista === 'lista') {
    $listaPorProveedor = reposicion_lista_por_proveedor($filtros);
}

if ($vista === 'config' && user_has_permission('editar_stock')) {
    $buscar = trim((string)($_GET['q'] ?? ''));

    // ── Config efectiva (productos + fallback producto_reposicion) ─────────────
    $hasMinCol  = has_column($pdo, "productos", "stock_minimo");
    $hasMaxCol  = has_column($pdo, "productos", "stock_maximo");
    $hasReoCol  = has_column($pdo, "productos", "punto_reorden");
    $hasProvCol = has_column($pdo, "productos", "proveedor_id");

    $exprMin  = $hasMinCol  ? "NULLIF(p.stock_minimo,0)"  : "NULL";
    $exprMax  = $hasMaxCol  ? "NULLIF(p.stock_maximo,0)"  : "NULL";
    $exprReo  = $hasReoCol  ? "NULLIF(p.punto_reorden,0)" : "NULL";
    // 0 se considera "no configurado"
    $exprProv = $hasProvCol ? "COALESCE(NULLIF(r.proveedor_id,0), NULLIF(p.proveedor_id,0))" : "NULLIF(r.proveedor_id,0)";

    $query  = "
            SELECT
                p.id, p.codigo, p.nombre, p.stock, p.costo,
                COALESCE($exprMin, NULLIF(r.stock_minimo,0), 0)   AS stock_minimo,
                COALESCE($exprMax, NULLIF(r.stock_maximo,0), 0)   AS stock_maximo,
                COALESCE($exprReo, NULLIF(r.punto_reorden,0), 0)  AS punto_reorden,
                COALESCE($exprProv, 0)                           AS proveedor_id,
                COALESCE(pv.nombre, 'Sin proveedor')         AS proveedor_nombre
            FROM productos p
            LEFT JOIN producto_reposicion r ON p.id = r.producto_id
            LEFT JOIN proveedores pv ON pv.id = $exprProv
            WHERE p.activo = 1
        ";


    $params = [];
    if ($buscar) {
        $query   .= " AND (p.codigo LIKE :q OR p.nombre LIKE :q)";
        $params['q'] = "%$buscar%";
    }
    $query .= " ORDER BY p.nombre LIMIT 100";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Proveedores para filtro ───────────────────────────────────────────────────
$proveedores = $pdo
    ->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre")
    ->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/partials/header.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h1>Reposición Sugerida</h1>
            <p class="panel-subtitle">Alertas de stock bajo y lista de compras</p>
        </div>

        <?php if ($vista === 'lista'): ?>
        <form method="post" class="repo-inline-form">
            <input type="hidden" name="csrf_token"   value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion"        value="exportar_csv">
            <!-- Mantener string (incluye '0' para Sin proveedor) -->
            <input type="hidden" name="proveedor_id"  value="<?= h((string)($proveedorRaw ?? '')) ?>">
            <button type="submit" class="btn btn-primary">
                <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Exportar CSV
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($info): ?>
    <div class="alert alert-ok">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
        <?= h($info) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?= h($error) ?>
    </div>
    <?php endif; ?>

    <!-- ── Tabs ──────────────────────────────────────────────────────────── -->
    <div class="repo-tabs">
        <a href="?v=alertas" class="repo-tab <?= $vista === 'alertas' ? 'active' : '' ?>">
            <svg class="repo-tab-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            Alertas
            <?php $totalAlertas = $conteoEstados['sin_stock'] + $conteoEstados['bajo_minimo']; ?>
            <?php if ($totalAlertas > 0): ?>
                <span class="badge danger"><?= $totalAlertas ?></span>
            <?php endif; ?>
        </a>

        <a href="?v=lista" class="repo-tab <?= $vista === 'lista' ? 'active' : '' ?>">
            <svg class="repo-tab-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            Lista de Compras
            <?php if ($conteoEstados['reorden'] > 0): ?>
                <span class="badge warning"><?= $conteoEstados['reorden'] ?></span>
            <?php endif; ?>
        </a>

        <?php if (user_has_permission('editar_stock')): ?>
        <a href="?v=config" class="repo-tab <?= $vista === 'config' ? 'active' : '' ?>">
            <svg class="repo-tab-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3"/>
                <path d="M12 1v6m0 6v6m5.66-13.66l-4.24 4.24m-2.83 2.83l-4.24 4.24M23 12h-6m-6 0H1m18.66 5.66l-4.24-4.24m-2.83-2.83l-4.24-4.24"/>
            </svg>
            Configuración
        </a>
        <?php endif; ?>
    </div>

    <!-- ── Filtros (Alertas + Lista) ─────────────────────────────────────── -->
    <?php if ($vista === 'alertas' || $vista === 'lista'): ?>
    <div class="repo-filters">
        <form method="get" class="repo-filter-form">
            <input type="hidden" name="v" value="<?= h($vista) ?>">

            <div class="repo-filter-group">
                <label for="filter-proveedor" class="repo-filter-label">Proveedor:</label>
                <select id="filter-proveedor" name="proveedor" class="form-control repo-filter-select">
                    <option value="">Todos los proveedores</option>
                    <option value="0" <?= ($filtros['proveedor_id'] === 0) ? 'selected' : '' ?>>Sin proveedor</option>
                    <?php foreach ($proveedores as $prov): ?>
                    <option value="<?= (int)$prov['id'] ?>" <?= $filtros['proveedor_id'] === (int)$prov['id'] ? 'selected' : '' ?>>
                        <?= h($prov['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($vista === 'alertas'): ?>
            <div class="repo-filter-group">
                <label for="filter-estado" class="repo-filter-label">Estado:</label>
                <select id="filter-estado" name="estado" class="form-control repo-filter-select">
                    <option value="">Todos los estados</option>
                    <option value="SIN_STOCK"   <?= $filtros['estado'] === 'SIN_STOCK'   ? 'selected' : '' ?>>Sin stock</option>
                    <option value="BAJO_MINIMO" <?= $filtros['estado'] === 'BAJO_MINIMO' ? 'selected' : '' ?>>Bajo mínimo</option>
                    <option value="REORDEN"     <?= $filtros['estado'] === 'REORDEN'     ? 'selected' : '' ?>>Punto de reorden</option>
                </select>
            </div>
            <?php endif; ?>

            <div class="repo-filter-group repo-filter-search">
                <label for="filter-buscar" class="repo-filter-label">Buscar:</label>
                <input
                    type="text"
                    id="filter-buscar"
                    name="q"
                    value="<?= h($filtros['buscar'] ?? '') ?>"
                    class="form-control repo-filter-input"
                    placeholder="Código o nombre..."
                >
            </div>

            <div class="repo-filter-actions">
                <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
                <a href="?v=<?= h($vista) ?>" class="btn btn-sm btn-ghost">Limpiar</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── Stats (solo alertas) ──────────────────────────────────────────── -->
    <?php if ($vista === 'alertas'): ?>
    <div class="repo-stats">
        <div class="repo-stat repo-stat--danger">
            <div class="repo-stat-value"><?= $conteoEstados['sin_stock'] ?></div>
            <div class="repo-stat-label">Sin stock</div>
        </div>
        <div class="repo-stat repo-stat--warning">
            <div class="repo-stat-value"><?= $conteoEstados['bajo_minimo'] ?></div>
            <div class="repo-stat-label">Bajo mínimo</div>
        </div>
        <div class="repo-stat repo-stat--info">
            <div class="repo-stat-value"><?= $conteoEstados['reorden'] ?></div>
            <div class="repo-stat-label">Reorden</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════════
         VISTA ALERTAS
         ════════════════════════════════════════════════════════════════ -->
    <?php if ($vista === 'alertas'): ?>

        <?php if (empty($stockBajo)): ?>
        <div class="repo-empty repo-empty--lg">
            <svg class="repo-empty-icon" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <?php
                $hayFiltros = ($filtros['proveedor_id'] !== null) || !empty($filtros['estado']) || !empty($filtros['buscar']);
            ?>
            <?php if ($hayFiltros): ?>
                <p class="repo-empty-text">No hay alertas con los filtros aplicados</p>
                <p class="repo-empty-hint">Probá limpiar filtros o revisar la configuración de mínimos.</p>
                <div style="margin-top:.75rem">
                    <a class="btn btn-sm btn-ghost" href="?v=alertas">Ver todos</a>
                </div>
            <?php else: ?>
                <p class="repo-empty-text">No hay alertas de stock bajo</p>
                <p class="repo-empty-hint">¡Todo está en orden! <?php if (user_has_permission('editar_stock')): ?>Podés configurar <strong>stock mínimo</strong> desde <strong>Configuración</strong> o <strong>Productos</strong> para recibir alertas.<?php else: ?>Pedí a un administrador que configure <strong>stock mínimo</strong> en <strong>Productos</strong> para recibir alertas.<?php endif; ?></p>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <?php foreach ($stockBajo as $producto):
            $isSinStock = $producto['estado'] === 'SIN_STOCK';
            $isBajo     = $producto['estado'] === 'BAJO_MINIMO';
            $iconClass  = $isSinStock ? 'sin-stock' : ($isBajo ? 'bajo-minimo' : 'reorden');
        ?>
        <div class="alert-card">
            <div class="alert-icon <?= $iconClass ?>">
                <?php if ($isSinStock): ?>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                <?php else: ?>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <?php endif; ?>
            </div>

            <div class="alert-info">
                <h4><?= h($producto['codigo']) ?> — <?= h($producto['nombre']) ?></h4>
                <p>
                    <?= h((string)($producto['proveedor_nombre'] ?? 'Sin proveedor')) ?>
                    &bull; Mínimo: <?= number_format((float)($producto['stock_minimo'] ?? 0), 0, ',', '.') ?>
                    <?php if (!empty($producto['punto_reorden'])): ?>
                        &bull; Reorden: <?= number_format((float)$producto['punto_reorden'], 0, ',', '.') ?>
                    <?php endif; ?>
                </p>
            </div>

            <div class="alert-stock">
                <div class="alert-stock-value <?= $isSinStock ? 'danger' : 'warning' ?>">
                    <?= number_format((float)$producto['stock'], 0, ',', '.') ?>
                </div>
                <div class="alert-stock-label">Stock Actual</div>
            </div>

            <div class="alert-action">
                <div class="sugerido">
                    Pedir: <?= number_format((float)($producto['cantidad_sugerida'] ?? 0), 0, ',', '.') ?>
                </div>
                <?php if (!empty($producto['costo']) && $producto['costo'] > 0): ?>
                <div class="repo-cost-muted">
                    ~$<?= number_format((float)$producto['cantidad_sugerida'] * (float)$producto['costo'], 2, ',', '.') ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>


    <!-- ════════════════════════════════════════════════════════════════════
         VISTA LISTA DE COMPRAS
         ════════════════════════════════════════════════════════════════ -->
    <?php elseif ($vista === 'lista'): ?>

        <?php if (empty($listaPorProveedor)): ?>
        <div class="repo-empty repo-empty--lg">
            <svg class="repo-empty-icon" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            <?php
                $hayFiltros = ($filtros['proveedor_id'] !== null) || !empty($filtros['buscar']);
            ?>
            <?php if ($hayFiltros): ?>
                <p class="repo-empty-text">No hay productos para los filtros seleccionados</p>
                <p class="repo-empty-hint">Probá cambiar el proveedor, buscar otro producto o ver todos.</p>
                <div style="margin-top:.75rem">
                    <a class="btn btn-sm btn-ghost" href="?v=lista">Ver todos</a>
                </div>
            <?php else: ?>
                <p class="repo-empty-text">No hay productos que necesiten reposición</p>
                <p class="repo-empty-hint"><?php if (user_has_permission('editar_stock')): ?>Configurá los niveles de stock mínimo desde <strong>Configuración</strong> (en esta pantalla) o desde <strong>Productos</strong>.<?php else: ?>Configurá los niveles de stock mínimo desde <strong>Productos</strong>.<?php endif; ?></p>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <?php foreach ($listaPorProveedor as $grupo):
            $items       = $grupo['items'] ?? [];
            $provNombre  = (string)($grupo['proveedor'] ?? 'Sin proveedor');
            $provId      = (int)($grupo['proveedor_id'] ?? 0);
            $totalItems  = (int)($grupo['total_items'] ?? count($items));
            $costoTotal  = (float)($grupo['costo_total'] ?? 0);

            // Contar críticos: SIN_STOCK o BAJO_MINIMO
            $criticosCount = 0;
            foreach ($items as $it) {
                if (in_array($it['estado'] ?? '', ['SIN_STOCK', 'BAJO_MINIMO'], true)) {
                    $criticosCount++;
                }
            }
        ?>
        <div class="proveedor-section" data-proveedor-id="<?= $provId ?>">

            <div class="proveedor-header">
                <div class="proveedor-name">
                    <input type="checkbox" class="repo-check-all" id="check-all-<?= $provId ?>">
                    <label for="check-all-<?= $provId ?>"><?= h($provNombre) ?></label>
                    <?php if ($criticosCount > 0): ?>
                        <span class="badge badge-danger"><?= $criticosCount ?> críticos</span>
                    <?php endif; ?>
                </div>
                <div class="proveedor-stats">
                    <span><strong><?= $totalItems ?></strong> productos</span>
                    <span class="proveedor-total">
                        Estimado: <strong>$<span class="total-proveedor">0,00</span></strong>
                    </span>
                </div>
            </div>

            <!-- Barra de acciones (Quick Order) -->
            <div class="proveedor-actions">
                <button type="button"
                        class="btn btn-sm btn-ghost btn-select-criticos"
                        data-proveedor="<?= $provId ?>">
                    Seleccionar críticos (<?= $criticosCount ?>)
                </button>

                <?php if ($provId <= 0): ?>
                    <div class="repo-proveedor-destino-wrap">
                        <label class="repo-proveedor-destino-label">Proveedor destino:</label>
                        <select class="form-control repo-proveedor-destino">
                            <option value="">Seleccionar…</option>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= (int)$prov['id'] ?>"><?= h($prov['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="repo-check-inline" style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem">
                            <input type="checkbox" class="repo-asignar-proveedor" checked>
                            <span>Asignar este proveedor a los productos seleccionados</span>
                        </label>
                        <small class="repo-muted">Este grupo no tiene proveedor asignado. Podés elegir un proveedor solo para esta compra (destildá) o asignarlo para futuras reposiciones (tildá).</small>
                    </div>
                <?php endif; ?>
                <?php if (user_has_permission('editar_stock')): ?>
                <button type="button"
                        class="btn btn-sm btn-primary btn-generar-orden"
                        data-proveedor="<?= $provId ?>"
                        disabled>
                    <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="12" y1="18" x2="12" y2="12"/>
                        <line x1="9" y1="15" x2="15" y2="15"/>
                    </svg>
                    Generar orden de compra
                </button>
                <?php endif; ?>
            </div>

            <div class="proveedor-table">
                <table class="table repo-table">
                    <thead>
                        <tr>
                            <th width="40"></th>
                            <th>Código</th>
                            <th>Producto</th>
                            <th class="t-right">Stock</th>
                            <th class="t-right">Mínimo</th>
                            <th class="t-right" width="155">Cantidad</th>
                            <th class="t-right">Costo Unit.</th>
                            <th class="t-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item):
                        $stock      = (float)($item['stock']             ?? 0);
                        $min        = (float)($item['stock_minimo']      ?? 0);
                        $cant       = (float)($item['cantidad_sugerida'] ?? 0);
                        $costo      = (float)($item['costo']             ?? 0);
                        $estado     = (string)($item['estado']           ?? 'NORMAL');
                        $isCritico  = in_array($estado, ['SIN_STOCK', 'BAJO_MINIMO'], true);
                    ?>
                    <tr class="repo-item <?= $isCritico ? 'repo-item--critico' : '' ?>"
                        data-producto-id="<?= (int)$item['id'] ?>"
                        data-costo="<?= $costo ?>"
                        data-cantidad-sugerida="<?= $cant ?>">
                        <td>
                            <input type="checkbox"
                                   class="repo-check-item"
                                   data-producto="<?= (int)$item['id'] ?>">
                        </td>
                        <td><code><?= h((string)($item['codigo'] ?? '')) ?></code></td>
                        <td>
                            <?= h((string)($item['nombre'] ?? '')) ?>
                            <?php if ($estado === 'SIN_STOCK'): ?>
                                <span class="badge badge-danger" style="margin-left:.4rem">Sin stock</span>
                            <?php elseif ($estado === 'BAJO_MINIMO'): ?>
                                <span class="badge badge-warning" style="margin-left:.4rem">Bajo mínimo</span>
                            <?php elseif ($estado === 'REORDEN'): ?>
                                <span class="badge badge-info" style="margin-left:.4rem">Reorden</span>
                            <?php endif; ?>
                        </td>
                        <td class="t-right">
                            <span class="<?= $stock <= 0 ? 'text-danger' : '' ?>">
                                <?= number_format($stock, 0, ',', '.') ?>
                            </span>
                        </td>
                        <td class="t-right"><?= number_format($min, 0, ',', '.') ?></td>
                        <td class="t-right">
                            <div class="repo-quantity-control">
                                <button type="button" class="qty-btn qty-minus" data-producto="<?= (int)$item['id'] ?>">−</button>
                                <input  type="number"
                                        class="qty-input"
                                        data-producto="<?= (int)$item['id'] ?>"
                                        value="<?= $cant ?>"
                                        min="0"
                                        step="1">
                                <button type="button" class="qty-btn qty-plus"  data-producto="<?= (int)$item['id'] ?>">+</button>
                            </div>
                        </td>
                        <td class="t-right">$<?= number_format($costo, 2, ',', '.') ?></td>
                        <td class="t-right">
                            <strong class="subtotal-item">$<?= number_format($cant * $costo, 2, ',', '.') ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Total Global -->
        <div class="repo-global-total">
            <div class="repo-global-total-label">Total Global Seleccionado:</div>
            <div class="repo-global-total-value">$<span id="total-global">0,00</span></div>
        </div>

        <?php endif; ?>


    <!-- ════════════════════════════════════════════════════════════════════
         VISTA CONFIGURACIÓN (requiere editar_stock)
         ════════════════════════════════════════════════════════════════ -->
    <?php elseif ($vista === 'config'): ?>

        <div class="repo-searchbar">
            <form method="get">
                <input type="hidden" name="v" value="config">
                <div class="repo-search-row">
                    <input
                        type="text"
                        name="q"
                        value="<?= h($_GET['q'] ?? '') ?>"
                        class="form-control repo-search-input"
                        placeholder="Buscar por código o nombre..."
                        autocomplete="off"
                    >
                    <button type="submit" class="btn btn-ghost">Buscar</button>
                </div>
            </form>
        </div>

        <?php if (empty($productos)): ?>
        <div class="repo-empty repo-empty--md">
            <svg class="repo-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <p class="repo-empty-text">
                <?= !empty($_GET['q']) ? 'No se encontraron productos con ese criterio.' : 'Ingresá un término de búsqueda para configurar productos.' ?>
            </p>
        </div>
        <?php else: ?>

        <?php foreach ($productos as $p): ?>
        <div class="config-card">
            <div class="config-header">
                <div class="config-product">
                    <h4><?= h($p['codigo']) ?> — <?= h($p['nombre']) ?></h4>
                    <p>
                        Stock actual: <strong><?= number_format((float)$p['stock'], 0, ',', '.') ?></strong>
                        &bull; <?= h($p['proveedor_nombre']) ?>
                    </p>
                </div>
            </div>

            <form method="post" class="config-form">
                <input type="hidden" name="csrf_token"  value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion"       value="guardar_config">
                <input type="hidden" name="producto_id"  value="<?= (int)$p['id'] ?>">

                <div class="form-group">
                    <label>Stock Mínimo</label>
                    <input type="number" name="stock_minimo"
                           value="<?= (float)($p['stock_minimo'] ?? 0) ?>"
                           min="0" step="1" class="form-control">
                </div>
                <div class="form-group">
                    <label>Punto de Reorden</label>
                    <input type="number" name="punto_reorden"
                           value="<?= (float)($p['punto_reorden'] ?? 0) ?>"
                           min="0" step="1" class="form-control">
                </div>
                <div class="form-group">
                    <label>Stock Máximo</label>
                    <input type="number" name="stock_maximo"
                           value="<?= (float)($p['stock_maximo'] ?? 0) ?>"
                           min="0" step="1" class="form-control">
                </div>

                <div class="form-group">
                    <label>Proveedor</label>
                    <select name="proveedor_id" class="form-control">
                        <option value="0">Sin proveedor</option>
                        <?php foreach ($proveedores as $prov): ?>
                            <option value="<?= (int)$prov['id'] ?>" <?= ((int)($p['proveedor_id'] ?? 0) === (int)$prov['id']) ? 'selected' : '' ?>>
                                <?= h($prov['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="repo-muted">Se usa para agrupar la lista y generar órdenes de compra. Si tu tabla productos no tiene proveedor_id, se guarda en producto_reposicion.</small>
                </div>
                <div class="form-group repo-form-actions">
                    <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>
</div><!-- /.panel -->

<!-- Form oculto para generar orden de compra -->
<form id="form-generar-orden" method="post" style="display:none">
    <input type="hidden" name="csrf_token"   value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="accion"        value="generar_orden_compra">
    <input type="hidden" name="proveedor_id"  id="input-proveedor-id">
    <input type="hidden" name="productos"     id="input-productos">
    <input type="hidden" name="asignar_proveedor" id="input-asignar-proveedor" value="0">
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
