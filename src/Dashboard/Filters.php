<?php
declare(strict_types=1);

if (!function_exists('dashboard_resolve_filters')) {
  function dashboard_resolve_filters(array $query, int $maxDays = 365): array {
    $today = (new DateTime('today'))->format('Y-m-d');
    $defaultFrom = (new DateTime('today'))->modify('-29 days')->format('Y-m-d');
    $defaultTo = $today;

    $from = validDateYmd($query['from'] ?? null) ?? $defaultFrom;
    $to = validDateYmd($query['to'] ?? null) ?? $defaultTo;
    $categoriaFiltro = isset($query['categoria']) && $query['categoria'] !== '' ? trim((string)$query['categoria']) : null;

    $horaDesde = isset($query['hora_desde']) && $query['hora_desde'] !== '' ? trim((string)$query['hora_desde']) : null;
    $horaHasta = isset($query['hora_hasta']) && $query['hora_hasta'] !== '' ? trim((string)$query['hora_hasta']) : null;
    $topProductosMetric = isset($query['top_metric']) ? trim((string)$query['top_metric']) : 'unidades';
    $topProductosLimit = isset($query['top_limit']) ? (int)$query['top_limit'] : 10;

    if (!in_array($topProductosMetric, ['unidades', 'ventas', 'ganancia'], true)) {
      $topProductosMetric = 'unidades';
    }
    if (!in_array($topProductosLimit, [5, 10, 15, 20], true)) {
      $topProductosLimit = 10;
    }

    if ($horaDesde && !preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $horaDesde)) {
      $horaDesde = null;
    }
    if ($horaHasta && !preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $horaHasta)) {
      $horaHasta = null;
    }

    if ($from > $to) {
      [$from, $to] = [$to, $from];
    }

    $horaDesdeSql = $horaDesde ?: null;
    $horaHastaSql = $horaHasta ?: null;

    $toastMessage = '';
    $toastFrom = '';
    $toastTo = '';

    $fromDT = new DateTime($from);
    $toDT = new DateTime($to);
    $diffDays = (int)$fromDT->diff($toDT)->format('%a');

    if ($diffDays > ($maxDays - 1)) {
      $fromDT = (clone $toDT)->modify('-' . ($maxDays - 1) . ' days');
      $from = $fromDT->format('Y-m-d');
      $toastMessage = "Rango maximo: {$maxDays} dias. Ajustado automaticamente.";
      $toastFrom = $from;
      $toastTo = $to;
      $diffDays = (int)$fromDT->diff($toDT)->format('%a');
    }

    $fromStart = $from . ' 00:00:00';
    $toEnd = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    if ($horaDesdeSql) {
      $fromStart = $from . ' ' . $horaDesdeSql . ':00';
    }
    if ($horaHastaSql) {
      $toEnd = (new DateTime($to . ' ' . $horaHastaSql . ':00'))->modify('+1 minute')->format('Y-m-d H:i:s');
    }

    return [
      'today' => $today,
      'from' => $from,
      'to' => $to,
      'fromDT' => $fromDT,
      'toDT' => $toDT,
      'diffDays' => $diffDays,
      'fromStart' => $fromStart,
      'toEnd' => $toEnd,
      'categoriaFiltro' => $categoriaFiltro,
      'horaDesde' => $horaDesde,
      'horaHasta' => $horaHasta,
      'horaDesdeSql' => $horaDesdeSql,
      'horaHastaSql' => $horaHastaSql,
      'topProductosMetric' => $topProductosMetric,
      'topProductosLimit' => $topProductosLimit,
      'toastMessage' => $toastMessage,
      'toastFrom' => $toastFrom,
      'toastTo' => $toastTo,
    ];
  }
}
