<?php
// src/VentasController.php
declare(strict_types=1);

final class VentasController extends BaseController
{
  public function index(): void
  {
    $this->requirePermission('ver_reportes');

    // LIMPIAR
    if (isset($_GET['clear'])) {
      $this->redirect('ventas.php');
    }

    // FILTROS
    $allowedMedios  = ['EFECTIVO', 'MP', 'DEBITO', 'CREDITO', 'SIN_ESPECIFICAR'];
    $allowedEstados = ['', 'EMITIDA', 'ANULADA'];

    $medio  = strtoupper(trim((string)($_GET['medio'] ?? '')));
    $estado = strtoupper(trim((string)($_GET['estado'] ?? '')));
    if (!in_array($estado, $allowedEstados, true)) $estado = '';

    $desde    = validDateYmd($_GET['desde'] ?? null);
    $hasta    = validDateYmd($_GET['hasta'] ?? null);
    $venta_id = trim((string)($_GET['venta_id'] ?? ''));

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

    $export = ((string)($_GET['export'] ?? '') === 'csv');

    // WHERE dinámico
    $whereParts = ['1=1'];
    $params = [];

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

    if ($min_total !== null) {
      $whereParts[] = 'v.total >= :min_total';
      $params[':min_total'] = (float)$min_total;
    }

    if ($max_total !== null) {
      $whereParts[] = 'v.total <= :max_total';
      $params[':max_total'] = (float)$max_total;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

    // EXPORT CSV (sale antes de renderizar)
    if ($export) {
      $this->exportCsv($whereSql, $params);
    }

    // STATS
    $stats = $this->getStats($whereSql, $params);

    $totalRows  = (int)$stats['cnt'];
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    $offset  = ($page - 1) * $perPage;
    $fromRow = $totalRows ? $offset + 1 : 0;
    $toRow   = min($offset + $perPage, $totalRows);

    // LISTADO
    $ventas = $this->getVentas($whereSql, $params, $perPage, $offset);

    // Promos activas (banner)
    $promosActivas = (int)$this->pdo->query("
      SELECT COUNT(*) FROM promos
      WHERE activo = 1
        AND (fecha_inicio IS NULL OR fecha_inicio <= CURDATE())
        AND (fecha_fin    IS NULL OR fecha_fin    >= CURDATE())
    ")->fetchColumn();

    // Variables usadas por header/partials actuales
    $pageTitle      = 'Ventas';
    $currentSection = 'ventas';
    $extraCss       = ['assets/css/ventas.css'];

    $this->render('ventas', compact(
      'allowedMedios','allowedEstados',
      'medio','estado','desde','hasta','venta_id',
      'min_total_raw','max_total_raw','perPage','page',
      'stats','totalRows','totalPages','offset','fromRow','toRow',
      'ventas','promosActivas',
      'pageTitle','currentSection','extraCss'
    ));
  }

  private function bindParams(PDOStatement $st, array $params): void
  {
    foreach ($params as $k => $v) {
      $st->bindValue($k, $v);
    }
  }

  private function exportCsv(string $whereSql, array $params): never
  {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ventas_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','fecha','medio_pago','estado','total','monto_pagado','vuelto','items'], ';');

    $sqlCsv = "
      SELECT v.id, v.fecha, v.medio_pago, v.estado, v.total, v.monto_pagado, v.vuelto,
             (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count
      FROM ventas v
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
        $r['medio_pago'],
        $r['estado'],
        number_format((float)$r['total'],        2, '.', ''),
        number_format((float)$r['monto_pagado'], 2, '.', ''),
        number_format((float)$r['vuelto'],       2, '.', ''),
        $r['items_count'],
      ], ';');
    }

    fclose($out);
    exit;
  }

  private function getStats(string $whereSql, array $params): array
  {
    $sqlStats = "
      SELECT COUNT(*) AS cnt,
             COALESCE(SUM(v.total),0)        AS sum_total,
             COALESCE(SUM(v.monto_pagado),0) AS sum_pagado,
             COALESCE(AVG(v.total),0)        AS avg_total
      FROM ventas v
      {$whereSql}
    ";

    $st = $this->pdo->prepare($sqlStats);
    $this->bindParams($st, $params);
    $st->execute();

    return $st->fetch(PDO::FETCH_ASSOC) ?: [
      'cnt'        => 0,
      'sum_total'  => 0,
      'sum_pagado' => 0,
      'avg_total'  => 0,
    ];
  }

  private function getVentas(string $whereSql, array $params, int $limit, int $offset): array
  {
    $sqlList = "
      SELECT v.id, v.fecha, v.medio_pago, v.estado, v.total, v.monto_pagado, v.vuelto,
             (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count,
             (SELECT f.estado FROM facturas f WHERE f.venta_id = v.id ORDER BY f.id DESC LIMIT 1) AS factura_estado
      FROM ventas v
      {$whereSql}
      ORDER BY v.fecha DESC, v.id DESC
      LIMIT :limit OFFSET :offset
    ";

    $st = $this->pdo->prepare($sqlList);
    $this->bindParams($st, $params);
    $st->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
