<?php
// src/VentasController.php - VERSIÓN MEJORADA
declare(strict_types=1);

final class VentasController extends BaseController
{
  // ============================================
  // MEJORA 1: Agregar búsqueda de cliente
  // ============================================
  public function index(): void
  {
    $this->requirePermission('ver_reportes');

    if (isset($_GET['clear'])) {
      $this->redirect('ventas.php');
    }

    // FILTROS EXISTENTES
    // A3: lista canónica unificada con public/ventas.php (TRANSFERENCIA/MODO/QR añadidos).
    // SIN_ESPECIFICAR se mantiene para compatibilidad con registros legacy.
    $allowedMedios  = ['EFECTIVO', 'MP', 'DEBITO', 'CREDITO', 'TRANSFERENCIA', 'MODO', 'QR', 'SIN_ESPECIFICAR'];
    $allowedEstados = ['', 'EMITIDA', 'ANULADA'];

    $medio  = strtoupper(trim((string)($_GET['medio'] ?? '')));
    $estado = strtoupper(trim((string)($_GET['estado'] ?? '')));
    if (!in_array($estado, $allowedEstados, true)) $estado = '';

    $desde    = validDateYmd($_GET['desde'] ?? null);
    $hasta    = validDateYmd($_GET['hasta'] ?? null);
    $venta_id = trim((string)($_GET['venta_id'] ?? ''));

    // ⭐ NUEVO: Filtro por cliente
    $cliente_buscar = trim((string)($_GET['cliente'] ?? ''));
    
    // ⭐ NUEVO: Filtro por vendedor/cajero
    $vendedor_id = trim((string)($_GET['vendedor_id'] ?? ''));

    $min_total_raw = (string)($_GET['min_total'] ?? '');
    $max_total_raw = (string)($_GET['max_total'] ?? '');
    $min_total = ($min_total_raw !== '') ? parse_money_ar($min_total_raw) : null;
    $max_total = ($max_total_raw !== '') ? parse_money_ar($max_total_raw) : null;

    if ($min_total !== null && $max_total !== null && $min_total > $max_total) {
      [$min_total, $max_total] = [$max_total, $min_total];
    }

    $perPage = (int)($_GET['per_page'] ?? 20);
    if (!in_array($perPage, [20, 50, 100], true)) $perPage = 20;

    $page = max(1, (int)($_GET['page'] ?? 1));

    // ⭐ NUEVO: Formato de exportación
    $exportFormat = (string)($_GET['export'] ?? '');
    if (!in_array($exportFormat, ['', 'csv', 'pdf', 'excel'], true)) {
      $exportFormat = '';
    }

    // WHERE dinámico
    $whereParts = ['1=1'];
    $params = [];
    $joins = [];

    if ($medio && in_array($medio, $allowedMedios, true)) {
      $whereParts[] = 'v.medio_pago = :medio';
      $params[':medio'] = $medio;
    }

    if ($estado !== '') {
      $whereParts[] = 'v.estado = :estado';
      $params[':estado'] = $estado;
    }

    if ($desde) {
      $whereParts[] = 'v.fecha >= :desde';
      $params[':desde'] = $desde . ' 00:00:00';
    }

    if ($hasta) {
      $whereParts[] = 'v.fecha <= :hasta';
      $params[':hasta'] = $hasta . ' 23:59:59';
    }

    if ($venta_id !== '' && ctype_digit($venta_id)) {
      $whereParts[] = 'v.id = :venta_id';
      $params[':venta_id'] = (int)$venta_id;
    }

    // ⭐ NUEVO: Búsqueda de cliente
    if ($cliente_buscar !== '') {
      $joins[] = 'LEFT JOIN clientes c ON c.id = v.cliente_id';
      $whereParts[] = '(c.nombre LIKE :cliente OR c.documento LIKE :cliente OR c.email LIKE :cliente)';
      $params[':cliente'] = '%' . $cliente_buscar . '%';
    }

    // ⭐ NUEVO: Filtro por vendedor
    if ($vendedor_id !== '' && ctype_digit($vendedor_id)) {
      $whereParts[] = 'v.usuario_id = :vendedor_id';
      $params[':vendedor_id'] = (int)$vendedor_id;
    }

    if ($min_total !== null) {
      $whereParts[] = 'v.total >= :min_total';
      $params[':min_total'] = (float)$min_total;
    }

    if ($max_total !== null) {
      $whereParts[] = 'v.total <= :max_total';
      $params[':max_total'] = (float)$max_total;
    }

    $joinsSql = implode(' ', array_unique($joins));
    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

    // EXPORT
    if ($exportFormat === 'csv') {
      $this->exportCsv($whereSql, $params, $joinsSql);
    } elseif ($exportFormat === 'pdf') {
      $this->exportPdf($whereSql, $params, $joinsSql);
    } elseif ($exportFormat === 'excel') {
      $this->exportExcel($whereSql, $params, $joinsSql);
    }

    // STATS con comparativa período anterior
    $stats = $this->getStats($whereSql, $params, $joinsSql);
    $statsComparativa = null;
    
    if ($desde && $hasta) {
      $statsComparativa = $this->getStatsComparativa($desde, $hasta, $whereSql, $params, $joinsSql);
    }

    // ⭐ NUEVO: Top productos vendidos en el período
    $topProductos = $this->getTopProductos($whereSql, $params, $joinsSql, 5);

    $totalRows  = (int)$stats['cnt'];
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    $offset  = ($page - 1) * $perPage;
    $fromRow = $totalRows ? $offset + 1 : 0;
    $toRow   = min($offset + $perPage, $totalRows);

    // LISTADO
    $ventas = $this->getVentas($whereSql, $params, $joinsSql, $perPage, $offset);

    // ⭐ NUEVO: Lista de vendedores para el filtro
    $vendedores = $this->getVendedores();

    // Promos activas
    $promosActivas = (int)$this->pdo->query("
      SELECT COUNT(*) FROM promos
      WHERE activo = 1
        AND (fecha_inicio IS NULL OR fecha_inicio <= CURDATE())
        AND (fecha_fin    IS NULL OR fecha_fin    >= CURDATE())
    ")->fetchColumn();

    $pageTitle      = 'Ventas';
    $currentSection = 'ventas';
    $extraCss       = ['assets/css/ventas.css'];
    $extraJs        = ['assets/js/ventas.js', 'assets/js/ventas-charts.js'];

    $this->render('ventas', compact(
      'allowedMedios','allowedEstados',
      'medio','estado','desde','hasta','venta_id',
      'cliente_buscar','vendedor_id','vendedores',
      'min_total_raw','max_total_raw','perPage','page',
      'stats','statsComparativa','topProductos',
      'totalRows','totalPages','offset','fromRow','toRow',
      'ventas','promosActivas',
      'pageTitle','currentSection','extraCss','extraJs'
    ));
  }

  // ============================================
  // MEJORA 2: Estadísticas comparativas
  // ============================================
  private function getStatsComparativa(
    string $desde,
    string $hasta,
    string $whereSql,
    array $params,
    string $joinsSql
  ): array {
    $desde_dt = new DateTime($desde);
    $hasta_dt = new DateTime($hasta);

    // A2 FIX: período previo sin solape.
    // Antes: se restaba $diff a ambos extremos → $hasta_prev solapaba con $desde actual.
    // Corrección: rango inclusivo de longitud $len = $diff + 1 días.
    //   hasta_prev = desde - 1 día   (toca exactamente el día anterior al período actual)
    //   desde_prev = desde - $len días
    //
    // Ejemplo: actual 2026-03-01 → 2026-03-07 ($diff=6, $len=7)
    //   previo: 2026-02-22 → 2026-02-28  ← sin solapamiento
    $diff = $desde_dt->diff($hasta_dt)->days;  // días entre extremos (sin incluir ambos)
    $len  = $diff + 1;                          // largo inclusivo del período actual

    $hasta_prev_dt = (clone $desde_dt)->sub(new DateInterval('P1D'));
    $desde_prev_dt = (clone $desde_dt)->sub(new DateInterval("P{$len}D"));

    $desde_prev = $desde_prev_dt->format('Y-m-d');
    $hasta_prev = $hasta_prev_dt->format('Y-m-d');

    // Reemplazar parámetros de fecha
    $paramsPrev = $params;
    $paramsPrev[':desde'] = $desde_prev . ' 00:00:00';
    $paramsPrev[':hasta'] = $hasta_prev . ' 23:59:59';

    $sql = "
      SELECT 
        COUNT(*) AS cnt,
        COALESCE(SUM(v.total), 0) AS sum_total,
        COALESCE(AVG(v.total), 0) AS avg_total
      FROM ventas v
      {$joinsSql}
      {$whereSql}
    ";

    $st = $this->pdo->prepare($sql);
    $this->bindParams($st, $paramsPrev);
    $st->execute();

    $prev = $st->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'sum_total' => 0, 'avg_total' => 0];

    return [
      'periodo_anterior' => [
        'desde' => $desde_prev,
        'hasta' => $hasta_prev,
        'ventas' => (int)$prev['cnt'],
        'total' => (float)$prev['sum_total'],
        'promedio' => (float)$prev['avg_total'],
      ],
    ];
  }

  // ============================================
  // MEJORA 3: Top productos
  // ============================================
  private function getTopProductos(
    string $whereSql,
    array $params,
    string $joinsSql,
    int $limit = 5
  ): array {
    $sql = "
      SELECT 
        p.codigo,
        p.nombre,
        SUM(vi.cantidad) AS unidades_vendidas,
        SUM(vi.subtotal) AS total_vendido,
        COUNT(DISTINCT v.id) AS num_ventas
      FROM venta_items vi
      JOIN productos p ON p.id = vi.producto_id
      JOIN ventas v ON v.id = vi.venta_id
      {$joinsSql}
      {$whereSql}
      GROUP BY vi.producto_id
      ORDER BY total_vendido DESC
      LIMIT :limit
    ";

    $st = $this->pdo->prepare($sql);
    $this->bindParams($st, $params);
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->execute();

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // ============================================
  // MEJORA 4: Lista de vendedores
  // ============================================
  private function getVendedores(): array
  {
    $sql = "
      SELECT DISTINCT u.id, u.username, u.nombre
      FROM users u
      JOIN ventas v ON v.usuario_id = u.id
      WHERE v.fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      ORDER BY u.nombre ASC
    ";

    return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // ============================================
  // MEJORA 5: Export PDF
  // A1 FIX: stub vacío reemplazado por 501 explícito hasta implementar librería.
  // No enviar headers de Content-Type PDF antes del 501 para evitar que el browser
  // intente abrir un PDF vacío/corrupto.
  // ============================================
  private function exportPdf(string $whereSql, array $params, string $joinsSql): never
  {
    http_response_code(501);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok'    => false,
      'error' => 'Export PDF no implementado. Usá export=csv por ahora.',
      'code'  => 'NOT_IMPLEMENTED',
    ]);
    exit;
  }

  // ============================================
  // MEJORA 6: Export Excel
  // A1 FIX: ídem exportPdf — 501 hasta implementar PhpSpreadsheet.
  // ============================================
  private function exportExcel(string $whereSql, array $params, string $joinsSql): never
  {
    http_response_code(501);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok'    => false,
      'error' => 'Export Excel no implementado. Usá export=csv por ahora.',
      'code'  => 'NOT_IMPLEMENTED',
    ]);
    exit;
  }

  // Métodos existentes...
  private function bindParams(PDOStatement $st, array $params): void
  {
    foreach ($params as $k => $v) {
      $st->bindValue($k, $v);
    }
  }

  private function exportCsv(string $whereSql, array $params, string $joinsSql): never
  {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ventas_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    
    // ⭐ MEJORADO: Más columnas en CSV
    fputcsv($out, [
      'id','fecha','cliente','medio_pago','estado','total',
      'monto_pagado','vuelto','items','vendedor'
    ], ';');

    $sqlCsv = "
      SELECT 
        v.id, v.fecha, 
        COALESCE(c.nombre, 'CONSUMIDOR FINAL') AS cliente,
        v.medio_pago, v.estado, v.total, v.monto_pagado, v.vuelto,
        (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count,
        COALESCE(u.username, '-') AS vendedor
      FROM ventas v
      {$joinsSql}
      LEFT JOIN clientes c ON c.id = v.cliente_id
      LEFT JOIN users u ON u.id = v.usuario_id
      {$whereSql}
      ORDER BY v.fecha DESC, v.id DESC
    ";

    $st = $this->pdo->prepare($sqlCsv);
    $this->bindParams($st, $params);
    $st->execute();

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      fputcsv($out, [
        $r['id'],
        $r['fecha'],
        $r['cliente'],
        $r['medio_pago'],
        $r['estado'],
        number_format((float)$r['total'], 2, '.', ''),
        number_format((float)$r['monto_pagado'], 2, '.', ''),
        number_format((float)$r['vuelto'], 2, '.', ''),
        $r['items_count'],
        $r['vendedor'],
      ], ';');
    }

    fclose($out);
    exit;
  }

  private function getStats(string $whereSql, array $params, string $joinsSql): array
  {
    $sqlStats = "
      SELECT 
        COUNT(*) AS cnt,
        COALESCE(SUM(v.total), 0) AS sum_total,
        COALESCE(SUM(v.monto_pagado), 0) AS sum_pagado,
        COALESCE(AVG(v.total), 0) AS avg_total,
        -- ⭐ NUEVO: Más métricas
        COUNT(DISTINCT v.cliente_id) AS clientes_unicos,
        COUNT(DISTINCT DATE(v.fecha)) AS dias_con_ventas
      FROM ventas v
      {$joinsSql}
      {$whereSql}
    ";

    $st = $this->pdo->prepare($sqlStats);
    $this->bindParams($st, $params);
    $st->execute();

    return $st->fetch(PDO::FETCH_ASSOC) ?: [
      'cnt' => 0,
      'sum_total' => 0,
      'sum_pagado' => 0,
      'avg_total' => 0,
      'clientes_unicos' => 0,
      'dias_con_ventas' => 0,
    ];
  }

  private function getVentas(
    string $whereSql,
    array $params,
    string $joinsSql,
    int $limit,
    int $offset
  ): array {
    $sqlList = "
      SELECT 
        v.id, v.fecha, v.medio_pago, v.estado, v.total, v.monto_pagado, v.vuelto,
        (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count,
        (SELECT f.estado FROM facturas f WHERE f.venta_id = v.id ORDER BY f.id DESC LIMIT 1) AS factura_estado,
        -- ⭐ NUEVO: Info del cliente y vendedor
        COALESCE(c.nombre, 'CONSUMIDOR FINAL') AS cliente_nombre,
        c.documento AS cliente_doc,
        u.username AS vendedor
      FROM ventas v
      {$joinsSql}
      LEFT JOIN clientes c ON c.id = v.cliente_id
      LEFT JOIN users u ON u.id = v.usuario_id
      {$whereSql}
      ORDER BY v.fecha DESC, v.id DESC
      LIMIT :limit OFFSET :offset
    ";

    $st = $this->pdo->prepare($sqlList);
    $this->bindParams($st, $params);
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}