<?php
/**
 * inventario_api.php
 * API REST para Análisis de Inventario v2.0
 */

declare(strict_types=1);

// Contexto API: evita HTML y normaliza errores
define('FLUS_API_CONTEXT', true);

/* Bootstrap */
require_once __DIR__ . '/../bootstrap.php';
require_once FLUS_ROOT . '/src/api_helpers.php';
setup_api_error_handlers();
require_once __DIR__ . '/../includes/InventarioAnalisis.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!function_exists('flus__has_perm')) {
    function flus__has_perm(string $perm): bool {
        if (function_exists('user_has_permission')) {
            try { return (bool) user_has_permission($perm); } catch (Throwable $e) {}
        }
        $perms = function_exists('session_permissions') ? session_permissions() : ($_SESSION['permissions'] ?? ($_SESSION['permisos'] ?? []));
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
                'categoria'   => $_GET['categoria'] ?? '',
                'proveedor_id'=> $_GET['proveedor_id'] ?? null,
                'busqueda'    => $_GET['q'] ?? '',
                // C1 FIX: propagar margen_min al método getTopInversion()
                // y a contarProductosConCosto() para que el total paginado coincida.
                'margen_min'  => $_GET['margen_min'] ?? '',
            ];
            json_ok([
                'productos' => $analisis->getTopInversion($limit, $filtros),
                'total' => $analisis->contarProductosConCosto($filtros)
            ]);
            break;

        case 'parados':
            $dias = max(1, (int)($_GET['dias'] ?? 30));
            $limit = min(500, max(1, (int)($_GET['limit'] ?? 25)));
            $filtros = [
                'categoria'   => $_GET['categoria'] ?? '',
                'proveedor_id'=> $_GET['proveedor_id'] ?? null,
                'busqueda'    => $_GET['q'] ?? '',
            ];
            json_ok([
                'productos' => $analisis->getProductosParados($dias, $limit, $filtros),
                'total' => $analisis->contarProductosParados($dias, $filtros)
            ]);
            break;

        case 'rotacion':
            $dias = max(1, (int)($_GET['dias'] ?? 30));
            $limit = min(500, max(1, (int)($_GET['limit'] ?? 25)));
            $orden = $_GET['orden'] ?? 'vendidos';
            $filtros = [
                'categoria'   => $_GET['categoria'] ?? '',
                'proveedor_id'=> $_GET['proveedor_id'] ?? null,
                'busqueda'    => $_GET['q'] ?? '',
            ];
            json_ok(['productos' => $analisis->getRotacion($dias, $limit, $orden, $filtros)]);
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
            exportarExcelFiltrado($analisis);
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

function exportarExcelFiltrado(InventarioAnalisis $analisis): void
{
    $tab = (string)($_GET['tab'] ?? 'resumen');
    if (!in_array($tab, ['resumen', 'inversion', 'rotacion', 'costos', 'parados', 'alertas', 'ventas'], true)) {
        $tab = 'resumen';
    }

    $limitTop = min(500, max(10, (int)($_GET['limit_top'] ?? 25)));
    $limitParados = min(500, max(10, (int)($_GET['limit_parados'] ?? 25)));
    $limitRotacion = min(500, max(10, (int)($_GET['limit_rotacion'] ?? 25)));
    $limitCostos = min(500, max(10, (int)($_GET['limit_costos'] ?? 50)));
    $diasParados = max(7, (int)($_GET['dias_parados'] ?? 30));
    $ordenRotacion = (string)($_GET['orden'] ?? 'vendidos');
    if (!in_array($ordenRotacion, ['vendidos', 'dias_rest', 'stock', 'nombre'], true)) {
        $ordenRotacion = 'vendidos';
    }

    $filtros = [
        'categoria' => trim((string)($_GET['categoria'] ?? '')),
        'proveedor_id' => (int)($_GET['proveedor_id'] ?? 0) ?: null,
        'busqueda' => trim((string)($_GET['q'] ?? '')),
    ];

    $resumen = $analisis->getResumenGeneral();
    $fmtMoney = static fn($value): string => '$' . number_format((float)$value, 0, ',', '.');
    $fmtQty = static fn($value, $esPesable = 0): string => number_format((float)$value, ((int)$esPesable === 1) ? 2 : 0, ',', '.');

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventario_' . $tab . '_' . date('Y-m-d_His') . '.xls"');
    header('Cache-Control: no-cache');

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<h2>INVENTARIO - ' . strtoupper(htmlspecialchars($tab, ENT_QUOTES, 'UTF-8')) . ' - ' . date('d/m/Y H:i') . '</h2>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><td><b>Capital Invertido</b></td><td>' . $fmtMoney($resumen['inversion_total']) . '</td></tr>';
    echo '<tr><td><b>Valor de Venta</b></td><td>' . $fmtMoney($resumen['valor_venta_potencial']) . '</td></tr>';
    echo '<tr><td><b>Margen Teorico</b></td><td>' . $fmtMoney($resumen['margen_teorico']) . '</td></tr>';
    echo '<tr><td><b>Total Productos</b></td><td>' . (int)$resumen['total_productos'] . '</td></tr>';
    echo '<tr><td><b>Unidades en Stock</b></td><td>' . number_format((float)$resumen['total_unidades'], 0, ',', '.') . '</td></tr>';
    echo '</table><br><br>';

    switch ($tab) {
        case 'inversion':
            $rows = $analisis->getTopInversion($limitTop, $filtros);
            echo '<h3>CAPITAL INVERTIDO POR PRODUCTO (' . count($rows) . ')</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#f0f0f0"><th>Codigo</th><th>Producto</th><th>Categoria</th><th>Stock</th><th>Costo</th><th>Precio</th><th>Invertido</th><th>Valor Venta</th><th>Margen %</th></tr>';
            foreach ($rows as $p) {
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td>' . htmlspecialchars((string)($p['categoria'] ?? '-')) . '</td><td style="text-align:right">' . $fmtQty($p['stock'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">' . $fmtMoney($p['costo'] ?? 0) . '</td><td style="text-align:right">' . $fmtMoney($p['precio'] ?? 0) . '</td><td style="text-align:right;font-weight:bold">' . $fmtMoney($p['capital_invertido'] ?? 0) . '</td><td style="text-align:right">' . $fmtMoney($p['valor_venta'] ?? 0) . '</td><td style="text-align:center">' . (($p['margen_pct'] ?? null) !== null ? number_format((float)$p['margen_pct'], 1, ',', '.') . '%' : '-') . '</td></tr>';
            }
            echo '</table>';
            break;

        case 'rotacion':
            $rows = $analisis->getRotacion(30, $limitRotacion, $ordenRotacion, $filtros);
            echo '<h3>ROTACION DE PRODUCTOS (' . count($rows) . ')</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#eef6ff"><th>Codigo</th><th>Producto</th><th>Categoria</th><th>Stock</th><th>Vendidos 30d</th><th>Ingresos 30d</th><th>Prom. Diario</th><th>Dias Stock</th><th>ABC</th></tr>';
            foreach ($rows as $p) {
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td>' . htmlspecialchars((string)($p['categoria'] ?? '-')) . '</td><td style="text-align:right">' . $fmtQty($p['stock'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">' . $fmtQty($p['vendidos_30d'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">' . $fmtMoney($p['ingresos_30d'] ?? 0) . '</td><td style="text-align:right">' . number_format((float)($p['promedio_diario'] ?? 0), 2, ',', '.') . '</td><td style="text-align:center">' . (($p['dias_stock_restante'] ?? 999) >= 999 ? '∞' : number_format((float)$p['dias_stock_restante'], 0, ',', '.') . 'd') . '</td><td style="text-align:center">' . htmlspecialchars((string)($p['clasificacion_abc'] ?? 'C')) . '</td></tr>';
            }
            echo '</table>';
            break;

        case 'costos':
            $rows = $analisis->getProductosSinCosto($limitCostos, $filtros);
            echo '<h3>PRODUCTOS SIN COSTO (' . count($rows) . ')</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#fff7ed"><th>Codigo</th><th>Producto</th><th>Categoria</th><th>Proveedor</th><th>Stock</th><th>Precio</th></tr>';
            foreach ($rows as $p) {
                echo '<tr><td>' . htmlspecialchars((string)($p['codigo'] ?? '-')) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td>' . htmlspecialchars((string)($p['categoria'] ?? '-')) . '</td><td>' . htmlspecialchars((string)($p['proveedor'] ?? '-')) . '</td><td style="text-align:right">' . $fmtQty($p['stock'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">' . $fmtMoney($p['precio'] ?? 0) . '</td></tr>';
            }
            echo '</table>';
            break;

        case 'parados':
            $rows = $analisis->getProductosParados($diasParados, $limitParados, $filtros);
            echo '<h3>PRODUCTOS SIN VENTA (' . count($rows) . ')</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#fff3cd"><th>Codigo</th><th>Producto</th><th>Categoria</th><th>Stock</th><th>Capital Parado</th><th>Ultima Venta</th><th>Dias</th></tr>';
            foreach ($rows as $p) {
                $ultima = (string)($p['ultima_venta'] ?? '');
                $ultimaFmt = $ultima === '' || $ultima === 'Nunca' ? 'Nunca' : date('d/m/Y', strtotime($ultima));
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td>' . htmlspecialchars((string)($p['categoria'] ?? '-')) . '</td><td style="text-align:right">' . $fmtQty($p['stock'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">' . $fmtMoney($p['capital_parado'] ?? 0) . '</td><td style="text-align:center">' . $ultimaFmt . '</td><td style="text-align:center">' . (int)($p['dias_sin_venta'] ?? 0) . 'd</td></tr>';
            }
            echo '</table>';
            break;

        case 'alertas':
            $stockBajo = $analisis->getStockBajo();
            $proximos = $analisis->getProximosAgotarse(7, 20);
            echo '<h3>STOCK BAJO MINIMO (' . count($stockBajo) . ')</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#ffcccc"><th>Codigo</th><th>Producto</th><th>Proveedor</th><th>Actual</th><th>Minimo</th><th>Faltante</th></tr>';
            foreach ($stockBajo as $p) {
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td>' . htmlspecialchars((string)($p['proveedor'] ?? '-')) . '</td><td style="text-align:right">' . $fmtQty($p['stock'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">' . $fmtQty($p['stock_minimo'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">-' . $fmtQty($p['faltante'] ?? 0, $p['es_pesable'] ?? 0) . '</td></tr>';
            }
            echo '</table><br><br>';
            echo '<h3>SE AGOTAN EN 7 DIAS (' . count($proximos) . ')</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#fff3cd"><th>Codigo</th><th>Producto</th><th>Stock</th><th>Prom/Dia</th><th>Dias Rest.</th><th>Reponer</th></tr>';
            foreach ($proximos as $p) {
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td style="text-align:right">' . $fmtQty($p['stock'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">' . number_format((float)($p['promedio_diario'] ?? 0), 1, ',', '.') . '</td><td style="text-align:center">' . number_format((float)($p['dias_restantes'] ?? 0), 0, ',', '.') . 'd</td><td style="text-align:right">' . number_format((float)($p['cantidad_reponer'] ?? 0), 0, ',', '.') . '</td></tr>';
            }
            echo '</table>';
            break;

        case 'ventas':
            $rows = $analisis->getTopVendidos(30, 20);
            $tendencia = $analisis->getTendenciaVentas(30);
            echo '<h3>MAS VENDIDOS (' . count($rows) . ')</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#ecfdf5"><th>Codigo</th><th>Producto</th><th>Categoria</th><th>Vendidos</th><th>Ingresos</th><th>Veces</th></tr>';
            foreach ($rows as $p) {
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td>' . htmlspecialchars((string)($p['categoria'] ?? '-')) . '</td><td style="text-align:right">' . number_format((float)($p['unidades_vendidas'] ?? 0), 0, ',', '.') . '</td><td style="text-align:right">' . $fmtMoney($p['ingresos'] ?? 0) . '</td><td style="text-align:center">' . (int)($p['veces_vendido'] ?? 0) . '</td></tr>';
            }
            echo '</table><br><br>';
            echo '<h3>TENDENCIA DE VENTAS</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#eef2ff"><th>Fecha</th><th>Ventas</th><th>Unidades</th><th>Total</th></tr>';
            foreach ($tendencia as $row) {
                echo '<tr><td>' . htmlspecialchars((string)($row['fecha'] ?? '')) . '</td><td style="text-align:center">' . (int)($row['cantidad_ventas'] ?? 0) . '</td><td style="text-align:right">' . number_format((float)($row['unidades'] ?? 0), 0, ',', '.') . '</td><td style="text-align:right">' . $fmtMoney($row['total'] ?? 0) . '</td></tr>';
            }
            echo '</table>';
            break;

        case 'resumen':
        default:
            $topInversion = $analisis->getTopInversion(min($limitTop, 10), $filtros);
            $parados = $analisis->getProductosParados($diasParados, min($limitParados, 8), $filtros);
            $stockBajo = $analisis->getStockBajo(8);
            $topVendidos = $analisis->getTopVendidos(30, 5);

            echo '<h3>TOP INVERSION</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#f0f0f0"><th>Codigo</th><th>Producto</th><th>Stock</th><th>Invertido</th><th>Margen %</th></tr>';
            foreach ($topInversion as $p) {
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td style="text-align:right">' . $fmtQty($p['stock'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">' . $fmtMoney($p['capital_invertido'] ?? 0) . '</td><td style="text-align:center">' . (($p['margen_pct'] ?? null) !== null ? number_format((float)$p['margen_pct'], 1, ',', '.') . '%' : '-') . '</td></tr>';
            }
            echo '</table><br><br>';

            echo '<h3>PRODUCTOS PARADOS</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#fff3cd"><th>Codigo</th><th>Producto</th><th>Capital</th><th>Dias</th></tr>';
            foreach ($parados as $p) {
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td style="text-align:right">' . $fmtMoney($p['capital_parado'] ?? 0) . '</td><td style="text-align:center">' . (int)($p['dias_sin_venta'] ?? 0) . 'd</td></tr>';
            }
            echo '</table><br><br>';

            echo '<h3>STOCK BAJO</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#ffcccc"><th>Codigo</th><th>Producto</th><th>Actual</th><th>Faltante</th></tr>';
            foreach ($stockBajo as $p) {
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td style="text-align:right">' . $fmtQty($p['stock'] ?? 0, $p['es_pesable'] ?? 0) . '</td><td style="text-align:right">-' . $fmtQty($p['faltante'] ?? 0, $p['es_pesable'] ?? 0) . '</td></tr>';
            }
            echo '</table><br><br>';

            echo '<h3>MAS VENDIDOS</h3>';
            echo '<table border="1" cellpadding="4">';
            echo '<tr style="background:#ecfdf5"><th>Codigo</th><th>Producto</th><th>Vendidos</th><th>Ingresos</th></tr>';
            foreach ($topVendidos as $p) {
                echo '<tr><td>' . htmlspecialchars((string)$p['codigo']) . '</td><td>' . htmlspecialchars((string)$p['nombre']) . '</td><td style="text-align:right">' . number_format((float)($p['unidades_vendidas'] ?? 0), 0, ',', '.') . '</td><td style="text-align:right">' . $fmtMoney($p['ingresos'] ?? 0) . '</td></tr>';
            }
            echo '</table>';
            break;
    }

    echo '</body></html>';
    exit;
}
