<?php
/**
 * inventario_api.php
 * API REST para Análisis de Inventario v2.0
 */

declare(strict_types=1);

/* Bootstrap */
$bootstrap = null;
foreach ([__DIR__ . '/../bootstrap.php', __DIR__ . '/../../bootstrap.php'] as $p) {
    if (is_file($p)) { $bootstrap = $p; break; }
}
if (!$bootstrap) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Bootstrap no encontrado']);
    exit;
}
require_once $bootstrap;
require_once __DIR__ . '/../includes/InventarioAnalisis.php';

/* Auth helpers */
foreach ([__DIR__ . '/../auth.php', __DIR__ . '/../../auth.php', __DIR__ . '/../includes/auth.php'] as $ap) {
    if (is_file($ap)) { require_once $ap; break; }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* Helpers */
if (!function_exists('json_ok')) {
    function json_ok(array $data = []): void {
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    function json_fail(string $error, int $code = 400): void {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('flus__has_perm')) {
    function flus__has_perm(string $perm): bool {
        if (function_exists('user_has_permission')) {
            try { return (bool) user_has_permission($perm); } catch (Throwable $e) {}
        }
        $perms = $_SESSION['permissions'] ?? ($_SESSION['permisos'] ?? []);
        if (is_array($perms) && in_array($perm, $perms, true)) return true;
        if (function_exists('tienePermiso')) {
            try { return (bool) tienePermiso($perm); } catch (Throwable $e) { return false; }
        }
        return false;
    }
}

$canStock = flus__has_perm('editar_stock') || flus__has_perm('ver_stock') || flus__has_perm('stock');
if (!$canStock) {
    json_fail('Acceso denegado', 403);
}

if (!isset($pdo)) {
    json_fail('$pdo no disponible', 500);
}

$analisis = new InventarioAnalisis($pdo);
$action = (string)($_GET['action'] ?? '');

try {
    switch ($action) {
        case 'resumen':
            json_ok(['resumen' => $analisis->getResumenGeneral()]);
            break;

        case 'dashboard':
            json_ok($analisis->getDashboardRapido());
            break;

        case 'top_inversion':
            $limit = min(500, max(1, (int)($_GET['limit'] ?? 25)));
            $filtros = [
                'categoria' => $_GET['categoria'] ?? '',
                'proveedor_id' => $_GET['proveedor_id'] ?? null,
                'busqueda' => $_GET['q'] ?? '',
            ];
            json_ok([
                'productos' => $analisis->getTopInversion($limit, $filtros),
                'total' => $analisis->contarProductosConCosto($filtros)
            ]);
            break;

        case 'parados':
            $dias = max(1, (int)($_GET['dias'] ?? 30));
            $limit = min(500, max(1, (int)($_GET['limit'] ?? 25)));
            json_ok([
                'productos' => $analisis->getProductosParados($dias, $limit),
                'total' => $analisis->contarProductosParados($dias)
            ]);
            break;

        case 'rotacion':
            $dias = max(1, (int)($_GET['dias'] ?? 30));
            $limit = min(500, max(1, (int)($_GET['limit'] ?? 25)));
            $orden = $_GET['orden'] ?? 'vendidos';
            json_ok(['productos' => $analisis->getRotacion($dias, $limit, $orden)]);
            break;

        case 'stock_bajo':
            json_ok(['productos' => $analisis->getStockBajo()]);
            break;

        case 'proximos_agotarse':
            $dias = max(1, (int)($_GET['dias'] ?? 7));
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
            json_ok(['productos' => $analisis->getProximosAgotarse($dias, $limit)]);
            break;

        case 'categorias':
            json_ok(['categorias' => $analisis->getInversionPorCategoria()]);
            break;

        case 'proveedores':
            json_ok(['proveedores' => $analisis->getInversionPorProveedor()]);
            break;

        case 'abc':
            $dias = max(7, (int)($_GET['dias'] ?? 30));
            json_ok($analisis->getAnalisisABC($dias));
            break;

        case 'top_vendidos':
            $dias = max(1, (int)($_GET['dias'] ?? 30));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            json_ok(['productos' => $analisis->getTopVendidos($dias, $limit)]);
            break;

        case 'tendencia':
            $dias = max(7, min(90, (int)($_GET['dias'] ?? 30)));
            json_ok(['tendencia' => $analisis->getTendenciaVentas($dias)]);
            break;

        case 'exportar_csv':
            exportarCSV($analisis);
            break;

        case 'exportar_excel':
            exportarExcel($analisis);
            break;

        default:
            json_fail('Acción no válida: ' . $action, 400);
    }
} catch (PDOException $e) {
    error_log("inventario_api PDO error: " . $e->getMessage());
    json_fail('Error de base de datos', 500);
} catch (Throwable $e) {
    error_log("inventario_api error: " . $e->getMessage());
    json_fail('Error interno', 500);
}

/**
 * Exportar a CSV
 */
function exportarCSV(InventarioAnalisis $analisis): void
{
    $data = $analisis->getExportacionCompleta();
    $filename = 'inventario_' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM

    // Resumen
    fputcsv($out, ['=== RESUMEN DE INVENTARIO ===']);
    fputcsv($out, ['Fecha', date('d/m/Y H:i')]);
    fputcsv($out, ['Capital Invertido', number_format($data['resumen']['inversion_total'], 2, ',', '.')]);
    fputcsv($out, ['Valor de Venta', number_format($data['resumen']['valor_venta_potencial'], 2, ',', '.')]);
    fputcsv($out, ['Margen Teórico', number_format($data['resumen']['margen_teorico'], 2, ',', '.')]);
    fputcsv($out, ['Total Productos', $data['resumen']['total_productos']]);
    fputcsv($out, ['Unidades', number_format($data['resumen']['total_unidades'], 0, ',', '.')]);
    fputcsv($out, []);

    // Inversión
    fputcsv($out, ['=== CAPITAL INVERTIDO POR PRODUCTO ===']);
    fputcsv($out, ['Código', 'Producto', 'Categoría', 'Stock', 'Costo', 'Precio', 'Invertido', 'Valor Venta', 'Margen %']);
    foreach ($data['inversion'] as $p) {
        fputcsv($out, [
            $p['codigo'], $p['nombre'], $p['categoria'] ?? '-',
            $p['stock'], $p['costo'], $p['precio'],
            $p['capital_invertido'], $p['valor_venta'],
            $p['margen_pct'] !== null ? $p['margen_pct'] . '%' : '-'
        ]);
    }
    fputcsv($out, []);

    // Stock bajo
    if (!empty($data['stock_bajo'])) {
        fputcsv($out, ['=== STOCK BAJO MÍNIMO ===']);
        fputcsv($out, ['Código', 'Producto', 'Stock', 'Mínimo', 'Faltante', 'Proveedor']);
        foreach ($data['stock_bajo'] as $p) {
            fputcsv($out, [
                $p['codigo'], $p['nombre'], $p['stock'],
                $p['stock_minimo'], $p['faltante'], $p['proveedor'] ?? '-'
            ]);
        }
        fputcsv($out, []);
    }

    // Parados
    if (!empty($data['parados'])) {
        fputcsv($out, ['=== PRODUCTOS SIN VENTA (30+ DÍAS) ===']);
        fputcsv($out, ['Código', 'Producto', 'Stock', 'Capital Parado', 'Última Venta', 'Días']);
        foreach ($data['parados'] as $p) {
            fputcsv($out, [
                $p['codigo'], $p['nombre'], $p['stock'],
                $p['capital_parado'], $p['ultima_venta'], $p['dias_sin_venta']
            ]);
        }
    }

    fclose($out);
    exit;
}

/**
 * Exportar a Excel (HTML table que Excel abre bien)
 */
function exportarExcel(InventarioAnalisis $analisis): void
{
    $data = $analisis->getExportacionCompleta();
    $filename = 'inventario_' . date('Y-m-d_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    echo '<html><head><meta charset="UTF-8"></head><body>';
    
    // Resumen
    echo '<h2>RESUMEN DE INVENTARIO - ' . date('d/m/Y H:i') . '</h2>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><td><b>Capital Invertido</b></td><td>$' . number_format($data['resumen']['inversion_total'], 0, ',', '.') . '</td></tr>';
    echo '<tr><td><b>Valor de Venta</b></td><td>$' . number_format($data['resumen']['valor_venta_potencial'], 0, ',', '.') . '</td></tr>';
    echo '<tr><td><b>Margen Teórico</b></td><td>$' . number_format($data['resumen']['margen_teorico'], 0, ',', '.') . '</td></tr>';
    echo '<tr><td><b>Total Productos</b></td><td>' . $data['resumen']['total_productos'] . '</td></tr>';
    echo '<tr><td><b>Unidades en Stock</b></td><td>' . number_format($data['resumen']['total_unidades'], 0, ',', '.') . '</td></tr>';
    echo '</table><br><br>';

    // Inversión
    echo '<h3>CAPITAL INVERTIDO POR PRODUCTO (' . count($data['inversion']) . ')</h3>';
    echo '<table border="1" cellpadding="4">';
    echo '<tr style="background:#f0f0f0"><th>Código</th><th>Producto</th><th>Categoría</th><th>Stock</th><th>Costo</th><th>Precio</th><th>Invertido</th><th>Valor Venta</th><th>Margen %</th></tr>';
    $totalInv = 0; $totalVal = 0;
    foreach ($data['inversion'] as $p) {
        $totalInv += $p['capital_invertido'];
        $totalVal += $p['valor_venta'];
        echo '<tr>';
        echo '<td>' . htmlspecialchars($p['codigo']) . '</td>';
        echo '<td>' . htmlspecialchars($p['nombre']) . '</td>';
        echo '<td>' . htmlspecialchars($p['categoria'] ?? '-') . '</td>';
        echo '<td style="text-align:right">' . number_format($p['stock'], 0, ',', '.') . '</td>';
        echo '<td style="text-align:right">$' . number_format($p['costo'], 0, ',', '.') . '</td>';
        echo '<td style="text-align:right">$' . number_format($p['precio'], 0, ',', '.') . '</td>';
        echo '<td style="text-align:right;font-weight:bold">$' . number_format($p['capital_invertido'], 0, ',', '.') . '</td>';
        echo '<td style="text-align:right">$' . number_format($p['valor_venta'], 0, ',', '.') . '</td>';
        echo '<td style="text-align:center">' . ($p['margen_pct'] !== null ? number_format($p['margen_pct'], 1) . '%' : '-') . '</td>';
        echo '</tr>';
    }
    echo '<tr style="background:#e0e0e0;font-weight:bold">';
    echo '<td colspan="6" style="text-align:right">TOTALES:</td>';
    echo '<td style="text-align:right">$' . number_format($totalInv, 0, ',', '.') . '</td>';
    echo '<td style="text-align:right">$' . number_format($totalVal, 0, ',', '.') . '</td>';
    echo '<td></td></tr>';
    echo '</table><br><br>';

    // Stock bajo
    if (!empty($data['stock_bajo'])) {
        echo '<h3>STOCK BAJO MÍNIMO (' . count($data['stock_bajo']) . ')</h3>';
        echo '<table border="1" cellpadding="4">';
        echo '<tr style="background:#ffcccc"><th>Código</th><th>Producto</th><th>Stock</th><th>Mínimo</th><th>Faltante</th><th>Proveedor</th></tr>';
        foreach ($data['stock_bajo'] as $p) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($p['codigo']) . '</td>';
            echo '<td>' . htmlspecialchars($p['nombre']) . '</td>';
            echo '<td style="text-align:right;color:red">' . number_format($p['stock'], 0, ',', '.') . '</td>';
            echo '<td style="text-align:right">' . number_format($p['stock_minimo'], 0, ',', '.') . '</td>';
            echo '<td style="text-align:right;font-weight:bold;color:red">-' . number_format($p['faltante'], 0, ',', '.') . '</td>';
            echo '<td>' . htmlspecialchars($p['proveedor'] ?? '-') . '</td>';
            echo '</tr>';
        }
        echo '</table><br><br>';
    }

    // Productos parados
    if (!empty($data['parados'])) {
        $totalParado = array_sum(array_column($data['parados'], 'capital_parado'));
        echo '<h3>PRODUCTOS SIN VENTA 30+ DÍAS (' . count($data['parados']) . ') - Capital: $' . number_format($totalParado, 0, ',', '.') . '</h3>';
        echo '<table border="1" cellpadding="4">';
        echo '<tr style="background:#fff3cd"><th>Código</th><th>Producto</th><th>Stock</th><th>Capital Parado</th><th>Última Venta</th><th>Días</th></tr>';
        foreach ($data['parados'] as $p) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($p['codigo']) . '</td>';
            echo '<td>' . htmlspecialchars($p['nombre']) . '</td>';
            echo '<td style="text-align:right">' . number_format($p['stock'], 0, ',', '.') . '</td>';
            echo '<td style="text-align:right;color:#b45309;font-weight:bold">$' . number_format($p['capital_parado'], 0, ',', '.') . '</td>';
            echo '<td style="text-align:center">' . ($p['ultima_venta'] === 'Nunca' ? 'Nunca' : date('d/m/Y', strtotime($p['ultima_venta']))) . '</td>';
            echo '<td style="text-align:center;font-weight:bold">' . $p['dias_sin_venta'] . 'd</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    echo '</body></html>';
    exit;
}
