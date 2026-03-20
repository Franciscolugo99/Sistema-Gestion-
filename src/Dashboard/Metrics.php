<?php
declare(strict_types=1);

if (!function_exists('dashboard_compute_sales_metrics')) {
  function dashboard_compute_sales_metrics(array $ctx): array {
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $movimientosRango = 0;
    $ventasRango = 0;
    $facturacionRango = 0.0;
    $unidadesVendidasRango = 0.0;

    $hasMovimientos = (bool)($ctx['hasMovimientos'] ?? false);
    $msFechaCol = $ctx['msFechaCol'] ?? null;
    $msTipoCol = $ctx['msTipoCol'] ?? null;
    $msCantCol = $ctx['msCantCol'] ?? null;

    $hasVentas = (bool)($ctx['hasVentas'] ?? false);
    $ventasFechaCol = $ctx['ventasFechaCol'] ?? null;
    $hasVentaItems = (bool)($ctx['hasVentaItems'] ?? false);
    $viVentaIdCol = $ctx['viVentaIdCol'] ?? null;
    $viProdIdCol = $ctx['viProdIdCol'] ?? null;
    $viQtyCol = $ctx['viQtyCol'] ?? null;
    $viLineCol = $ctx['viLineCol'] ?? null;
    $hasProductos = (bool)($ctx['hasProductos'] ?? false);
    $prodCatCol = $ctx['prodCatCol'] ?? null;

    $categoriaFiltro = $ctx['categoriaFiltro'] ?? null;
    $catCondP = (string)($ctx['catCondP'] ?? '');
    $fromStart = (string)($ctx['fromStart'] ?? '');
    $toEnd = (string)($ctx['toEnd'] ?? '');
    $ventasDateSQL = (string)($ctx['ventasDateSQL'] ?? 'fecha');
    $ventasEmitidaCond = (string)($ctx['ventasEmitidaCond'] ?? '');
    $ventasTotalCol = $ctx['ventasTotalCol'] ?? null;
    $ventasTotalSQL = (string)($ctx['ventasTotalSQL'] ?? 'total');

    if ($hasMovimientos && $msFechaCol) {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE `{$msFechaCol}` >= ? AND `{$msFechaCol}` < ?");
      $stmt->execute([$fromStart, $toEnd]);
      $movimientosRango = (int)$stmt->fetchColumn();
    }

    if ($hasVentas && $ventasFechaCol) {
      if ($categoriaFiltro && $hasVentaItems && $viVentaIdCol && $hasProductos && $prodCatCol) {
        $stmt = $pdo->prepare("
          SELECT COUNT(DISTINCT v.id)
          FROM ventas v
          JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            {$catCondP}
        ");
        $stmt->execute([$fromStart, $toEnd]);
        $ventasRango = (int)$stmt->fetchColumn();

        if ($ventasTotalCol && $viLineCol) {
          $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(vi.`{$viLineCol}`),0)
            FROM venta_items vi
            JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
            JOIN productos p ON p.id = vi.`{$viProdIdCol}`
            WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
              {$catCondP}
          ");
          $stmt->execute([$fromStart, $toEnd]);
          $facturacionRango = (float)$stmt->fetchColumn();
        }
      } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
        $stmt->execute([$fromStart, $toEnd]);
        $ventasRango = (int)$stmt->fetchColumn();

        if ($ventasTotalCol) {
          $stmt = $pdo->prepare("SELECT COALESCE(SUM({$ventasTotalSQL}),0) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
          $stmt->execute([$fromStart, $toEnd]);
          $facturacionRango = (float)$stmt->fetchColumn();
        }
      }
    }

    if ($hasVentas && $hasVentaItems && $viVentaIdCol && $viQtyCol && $ventasFechaCol) {
      if ($categoriaFiltro && $hasProductos && $prodCatCol) {
        $stmt = $pdo->prepare("
          SELECT COALESCE(SUM(vi.`{$viQtyCol}`),0)
          FROM venta_items vi
          JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            {$catCondP}
        ");
        $stmt->execute([$fromStart, $toEnd]);
      } else {
        $stmt = $pdo->prepare("
          SELECT COALESCE(SUM(vi.`{$viQtyCol}`),0)
          FROM venta_items vi
          JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
        ");
        $stmt->execute([$fromStart, $toEnd]);
      }
      $unidadesVendidasRango = (float)$stmt->fetchColumn();
    } elseif ($hasMovimientos && $msFechaCol && $msTipoCol && $msCantCol) {
      $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(`{$msCantCol}`),0)
        FROM movimientos_stock
        WHERE `{$msTipoCol}`='VENTA' AND `{$msFechaCol}` >= ? AND `{$msFechaCol}` < ?
      ");
      $stmt->execute([$fromStart, $toEnd]);
      $unidadesVendidasRango = (float)$stmt->fetchColumn();
    }

    $ticketPromedio = ($ventasRango > 0) ? ($facturacionRango / $ventasRango) : 0.0;

    return [
      'movimientosRango' => $movimientosRango,
      'ventasRango' => $ventasRango,
      'facturacionRango' => $facturacionRango,
      'unidadesVendidasRango' => $unidadesVendidasRango,
      'ticketPromedio' => $ticketPromedio,
    ];
  }
}

if (!function_exists('dashboard_compute_period_comparison')) {
  function dashboard_compute_period_comparison(array $ctx): array {
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $from = (string)($ctx['from'] ?? '');
    $diffDays = (int)($ctx['diffDays'] ?? 0);
    $hasVentas = (bool)($ctx['hasVentas'] ?? false);
    $ventasFechaCol = $ctx['ventasFechaCol'] ?? null;
    $categoriaFiltro = $ctx['categoriaFiltro'] ?? null;
    $hasVentaItems = (bool)($ctx['hasVentaItems'] ?? false);
    $viVentaIdCol = $ctx['viVentaIdCol'] ?? null;
    $viProdIdCol = $ctx['viProdIdCol'] ?? null;
    $hasProductos = (bool)($ctx['hasProductos'] ?? false);
    $prodCatCol = $ctx['prodCatCol'] ?? null;
    $catCondP = (string)($ctx['catCondP'] ?? '');
    $ventasDateSQL = (string)($ctx['ventasDateSQL'] ?? 'fecha');
    $ventasEmitidaCond = (string)($ctx['ventasEmitidaCond'] ?? '');
    $ventasTotalCol = $ctx['ventasTotalCol'] ?? null;
    $ventasTotalSQL = (string)($ctx['ventasTotalSQL'] ?? 'total');
    $viLineCol = $ctx['viLineCol'] ?? null;
    $ventasRango = (float)($ctx['ventasRango'] ?? 0.0);
    $facturacionRango = (float)($ctx['facturacionRango'] ?? 0.0);
    $ticketPromedio = (float)($ctx['ticketPromedio'] ?? 0.0);

    $rangeDays = $diffDays + 1;
    $prevToDT = (new DateTime($from))->modify('-1 day');
    $prevFromDT = (clone $prevToDT)->modify('-' . ($rangeDays - 1) . ' days');
    $prevFrom = $prevFromDT->format('Y-m-d');
    $prevTo = $prevToDT->format('Y-m-d');
    $prevFromStart = $prevFrom . ' 00:00:00';
    $prevToEnd = (new DateTime($prevTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $ventasPrev = 0;
    $facturacionPrev = 0.0;

    if ($hasVentas && $ventasFechaCol) {
      if ($categoriaFiltro && $hasVentaItems && $viVentaIdCol && $hasProductos && $prodCatCol) {
        $stmt = $pdo->prepare("
          SELECT COUNT(DISTINCT v.id)
          FROM ventas v
          JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            {$catCondP}
        ");
        $stmt->execute([$prevFromStart, $prevToEnd]);
        $ventasPrev = (int)$stmt->fetchColumn();

        if ($ventasTotalCol && $viLineCol) {
          $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(vi.`{$viLineCol}`),0)
            FROM venta_items vi
            JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
            JOIN productos p ON p.id = vi.`{$viProdIdCol}`
            WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
              {$catCondP}
          ");
          $stmt->execute([$prevFromStart, $prevToEnd]);
          $facturacionPrev = (float)$stmt->fetchColumn();
        }
      } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
        $stmt->execute([$prevFromStart, $prevToEnd]);
        $ventasPrev = (int)$stmt->fetchColumn();

        if ($ventasTotalCol) {
          $stmt = $pdo->prepare("SELECT COALESCE(SUM({$ventasTotalSQL}),0) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
          $stmt->execute([$prevFromStart, $prevToEnd]);
          $facturacionPrev = (float)$stmt->fetchColumn();
        }
      }
    }

    $ticketPrev = ($ventasPrev > 0) ? ($facturacionPrev / $ventasPrev) : 0.0;

    return [
      'rangeDays' => $rangeDays,
      'prevFrom' => $prevFrom,
      'prevTo' => $prevTo,
      'prevFromStart' => $prevFromStart,
      'prevToEnd' => $prevToEnd,
      'ventasPrev' => $ventasPrev,
      'facturacionPrev' => $facturacionPrev,
      'ticketPrev' => $ticketPrev,
      'ventasDelta' => kpiDeltaBadge($ventasRango, (float)$ventasPrev),
      'factDelta' => kpiDeltaBadge($facturacionRango, $facturacionPrev),
      'ticketDelta' => kpiDeltaBadge($ticketPromedio, $ticketPrev),
    ];
  }
}

if (!function_exists('dashboard_compute_profitability')) {
  function dashboard_compute_profitability(array $ctx): array {
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $totalVentas = (float)($ctx['facturacionRango'] ?? 0.0);
    $totalCostos = 0.0;
    $gananciaBruta = 0.0;
    $margenPorcentaje = 0.0;
    $productosRentables = [];

    $hasVentas = (bool)($ctx['hasVentas'] ?? false);
    $hasVentaItems = (bool)($ctx['hasVentaItems'] ?? false);
    $hasProductos = (bool)($ctx['hasProductos'] ?? false);
    $ventasFechaCol = $ctx['ventasFechaCol'] ?? null;
    $ventasTotalCol = $ctx['ventasTotalCol'] ?? null;
    $viVentaIdCol = $ctx['viVentaIdCol'] ?? null;
    $viProdIdCol = $ctx['viProdIdCol'] ?? null;
    $viQtyCol = $ctx['viQtyCol'] ?? null;
    $prodCostoCol = $ctx['prodCostoCol'] ?? null;
    $prodNombreCol = $ctx['prodNombreCol'] ?? null;
    $fromStart = (string)($ctx['fromStart'] ?? '');
    $toEnd = (string)($ctx['toEnd'] ?? '');
    $ventasDateSQL = (string)($ctx['ventasDateSQL'] ?? 'fecha');
    $ventasEmitidaCond = (string)($ctx['ventasEmitidaCond'] ?? '');
    $catCondP = (string)($ctx['catCondP'] ?? '');
    $ventasTotalSQL = (string)($ctx['ventasTotalSQL'] ?? 'total');
    $lineExprVi = $ctx['lineExprVi'] ?? null;
    $lineExprVi2 = $ctx['lineExprVi2'] ?? null;

    $canRentabilidad = $hasVentas && $hasVentaItems && $hasProductos
      && $ventasFechaCol && $ventasTotalCol
      && $viVentaIdCol && $viProdIdCol && $viQtyCol
      && $prodCostoCol;

    if ($canRentabilidad) {
      $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(vi.`{$viQtyCol}` * p.`{$prodCostoCol}`), 0)
        FROM venta_items vi
        JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
        JOIN productos p ON p.id = vi.`{$viProdIdCol}`
        WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
          AND p.`{$prodCostoCol}` IS NOT NULL
          {$catCondP}
      ");
      $stmt->execute([$fromStart, $toEnd]);
      $totalCostos = (float)$stmt->fetchColumn();

      $gananciaBruta = $totalVentas - $totalCostos;
      $margenPorcentaje = ($totalVentas > 0) ? (($gananciaBruta / $totalVentas) * 100) : 0.0;

      if ($lineExprVi && $lineExprVi2 && $prodNombreCol) {
        $sqlRentables = "
          SELECT
            p.`{$prodNombreCol}` AS nombre,
            SUM(vi.`{$viQtyCol}`) AS unidades,
            COALESCE(SUM(v.{$ventasTotalSQL} * ({$lineExprVi} / NULLIF(vt.subtotal_total,0))), 0) AS ventas,
            COALESCE(SUM(vi.`{$viQtyCol}` * p.`{$prodCostoCol}`), 0) AS costos,
            COALESCE(SUM((v.{$ventasTotalSQL} * ({$lineExprVi} / NULLIF(vt.subtotal_total,0))) - (vi.`{$viQtyCol}` * p.`{$prodCostoCol}`)), 0) AS ganancia
          FROM venta_items vi
          JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          JOIN (
            SELECT vi2.`{$viVentaIdCol}` AS venta_id, SUM({$lineExprVi2}) AS subtotal_total
            FROM venta_items vi2
            JOIN ventas v2 ON v2.id = vi2.`{$viVentaIdCol}`
            WHERE v2.{$ventasDateSQL} >= ? AND v2.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            GROUP BY vi2.`{$viVentaIdCol}`
          ) vt ON vt.venta_id = vi.`{$viVentaIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            AND p.`{$prodCostoCol}` IS NOT NULL
            AND vt.subtotal_total > 0
            {$catCondP}
          GROUP BY p.id, p.`{$prodNombreCol}`
          ORDER BY ganancia DESC
          LIMIT 5
        ";
        $stmt = $pdo->prepare($sqlRentables);
        $stmt->execute([$fromStart, $toEnd, $fromStart, $toEnd]);
        $productosRentables = $stmt->fetchAll(PDO::FETCH_ASSOC);
      }
    }

    return [
      'totalVentas' => $totalVentas,
      'totalCostos' => $totalCostos,
      'gananciaBruta' => $gananciaBruta,
      'margenPorcentaje' => $margenPorcentaje,
      'productosRentables' => $productosRentables,
    ];
  }
}

if (!function_exists('dashboard_compute_payment_methods')) {
  function dashboard_compute_payment_methods(array $ctx): array {
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $metodosPago = [];

    $hasVentas = (bool)($ctx['hasVentas'] ?? false);
    $ventasFechaCol = $ctx['ventasFechaCol'] ?? null;
    $ventasEstadoCol = $ctx['ventasEstadoCol'] ?? null;
    $ventasMedioCol = $ctx['ventasMedioCol'] ?? null;
    $ventasTotalCol = $ctx['ventasTotalCol'] ?? null;
    $ventasTotalSQL = (string)($ctx['ventasTotalSQL'] ?? 'total');
    $categoriaFiltro = $ctx['categoriaFiltro'] ?? null;
    $esSinCategoria = (bool)($ctx['esSinCategoria'] ?? false);
    $prodCatCol = $ctx['prodCatCol'] ?? null;
    $hasVentaItems = (bool)($ctx['hasVentaItems'] ?? false);
    $viVentaIdCol = $ctx['viVentaIdCol'] ?? null;
    $viProdIdCol = $ctx['viProdIdCol'] ?? null;
    $hasProductos = (bool)($ctx['hasProductos'] ?? false);
    $fromStart = (string)($ctx['fromStart'] ?? '');
    $toEnd = (string)($ctx['toEnd'] ?? '');
    $ventasDateSQL = (string)($ctx['ventasDateSQL'] ?? 'fecha');
    $ventasEmitidaCond = (string)($ctx['ventasEmitidaCond'] ?? '');

    $hasVentaPagos = flus_table_exists($pdo, 'venta_pagos');
    $ventasVueltoCol = $hasVentas ? flus_first_existing_column($pdo, 'ventas', ['vuelto','cambio']) : null;

    if ($hasVentaPagos && $hasVentas && $ventasFechaCol && $ventasEstadoCol) {
      $vpVentaId = flus_first_existing_column($pdo, 'venta_pagos', ['venta_id']);
      $vpMedio = flus_first_existing_column($pdo, 'venta_pagos', ['medio_pago','metodo_pago']);
      $vpMonto = flus_first_existing_column($pdo, 'venta_pagos', ['monto','importe']);

      if ($vpVentaId && $vpMedio && $vpMonto) {
        $vueltoExpr = $ventasVueltoCol ? "COALESCE(MAX(v.`{$ventasVueltoCol}`),0)" : "0";
        $catSubqueryWhere = '';

        if ($categoriaFiltro && $prodCatCol) {
          if ($esSinCategoria) {
            $catSubqueryWhere = "WHERE (p.`{$prodCatCol}` IS NULL OR TRIM(p.`{$prodCatCol}`) = '')";
          } else {
            $catSubqueryWhere = "WHERE p.`{$prodCatCol}` = " . $pdo->quote((string)$categoriaFiltro);
          }
        }

        if ($categoriaFiltro && $hasVentaItems && $viVentaIdCol && $hasProductos && $prodCatCol) {
          $sql = "
            SELECT
              x.medio_pago,
              COUNT(DISTINCT x.venta_id) AS cantidad,
              COALESCE(SUM(x.monto_net),0) AS monto,
              COALESCE(AVG(x.monto_net),0) AS ticket_promedio
            FROM (
              SELECT
                vp.`{$vpVentaId}` AS venta_id,
                vp.`{$vpMedio}` AS medio_pago,
                CASE
                  WHEN vp.`{$vpMedio}`='EFECTIVO'
                    THEN GREATEST(SUM(vp.`{$vpMonto}`) - {$vueltoExpr}, 0)
                  ELSE SUM(vp.`{$vpMonto}`)
                END AS monto_net
              FROM venta_pagos vp
              JOIN ventas v ON v.id = vp.`{$vpVentaId}`
              WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
                AND v.id IN (
                  SELECT DISTINCT vi.`{$viVentaIdCol}`
                  FROM venta_items vi
                  JOIN productos p ON p.id = vi.`{$viProdIdCol}`
                  {$catSubqueryWhere}
                )
              GROUP BY vp.`{$vpVentaId}`, vp.`{$vpMedio}`
            ) x
            GROUP BY x.medio_pago
            ORDER BY monto DESC
          ";
          $stmt = $pdo->prepare($sql);
          $stmt->execute([$fromStart, $toEnd]);
        } else {
          $sql = "
            SELECT
              x.medio_pago,
              COUNT(DISTINCT x.venta_id) AS cantidad,
              COALESCE(SUM(x.monto_net),0) AS monto,
              COALESCE(AVG(x.monto_net),0) AS ticket_promedio
            FROM (
              SELECT
                vp.`{$vpVentaId}` AS venta_id,
                vp.`{$vpMedio}` AS medio_pago,
                CASE
                  WHEN vp.`{$vpMedio}`='EFECTIVO'
                    THEN GREATEST(SUM(vp.`{$vpMonto}`) - {$vueltoExpr}, 0)
                  ELSE SUM(vp.`{$vpMonto}`)
                END AS monto_net
              FROM venta_pagos vp
              JOIN ventas v ON v.id = vp.`{$vpVentaId}`
              WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
              GROUP BY vp.`{$vpVentaId}`, vp.`{$vpMedio}`
            ) x
            GROUP BY x.medio_pago
            ORDER BY monto DESC
          ";
          $stmt = $pdo->prepare($sql);
          $stmt->execute([$fromStart, $toEnd]);
        }

        $metodosPago = $stmt->fetchAll(PDO::FETCH_ASSOC);
      }
    } elseif ($hasVentas && $ventasFechaCol && $ventasTotalCol && $ventasMedioCol) {
      $catSubqueryWhere = '';
      if ($categoriaFiltro && $prodCatCol) {
        if ($esSinCategoria) {
          $catSubqueryWhere = "WHERE (p.`{$prodCatCol}` IS NULL OR TRIM(p.`{$prodCatCol}`) = '')";
        } else {
          $catSubqueryWhere = "WHERE p.`{$prodCatCol}` = " . $pdo->quote((string)$categoriaFiltro);
        }
      }

      if ($categoriaFiltro && $hasVentaItems && $viVentaIdCol && $hasProductos && $prodCatCol) {
        $stmt = $pdo->prepare("
          SELECT
            v.`{$ventasMedioCol}` AS medio_pago,
            COUNT(DISTINCT v.id) AS cantidad,
            COALESCE(SUM(v.{$ventasTotalSQL}),0) AS monto,
            COALESCE(AVG(v.{$ventasTotalSQL}),0) AS ticket_promedio
          FROM ventas v
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            AND v.id IN (
              SELECT DISTINCT vi.`{$viVentaIdCol}`
              FROM venta_items vi
              JOIN productos p ON p.id = vi.`{$viProdIdCol}`
              {$catSubqueryWhere}
            )
          GROUP BY v.`{$ventasMedioCol}`
          ORDER BY monto DESC
        ");
        $stmt->execute([$fromStart, $toEnd]);
      } else {
        $stmt = $pdo->prepare("
          SELECT
            `{$ventasMedioCol}` AS medio_pago,
            COUNT(*) AS cantidad,
            COALESCE(SUM({$ventasTotalSQL}),0) AS monto,
            COALESCE(AVG({$ventasTotalSQL}),0) AS ticket_promedio
          FROM ventas
          WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
          GROUP BY `{$ventasMedioCol}`
          ORDER BY monto DESC
        ");
        $stmt->execute([$fromStart, $toEnd]);
      }

      $metodosPago = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
      'metodosPago' => $metodosPago,
    ];
  }
}

if (!function_exists('dashboard_compute_promotions')) {
  function dashboard_compute_promotions(array $ctx): array {
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $promociones = [];
    $totalDescuentosPromos = 0.0;

    $hasVentaPromos = (bool)($ctx['hasVentaPromos'] ?? false);
    $hasVentas = (bool)($ctx['hasVentas'] ?? false);
    $ventasFechaCol = $ctx['ventasFechaCol'] ?? null;
    $ventasEstadoCol = $ctx['ventasEstadoCol'] ?? null;
    $fromStart = (string)($ctx['fromStart'] ?? '');
    $toEnd = (string)($ctx['toEnd'] ?? '');
    $ventasDateSQL = (string)($ctx['ventasDateSQL'] ?? 'fecha');
    $ventasEmitidaCond = (string)($ctx['ventasEmitidaCond'] ?? '');

    if ($hasVentaPromos && $hasVentas && $ventasFechaCol && $ventasEstadoCol) {
      $vpVentaId = flus_first_existing_column($pdo, 'venta_promos', ['venta_id']);
      $vpNombre = flus_first_existing_column($pdo, 'venta_promos', ['promo_nombre','nombre']);
      $vpTipo = flus_first_existing_column($pdo, 'venta_promos', ['promo_tipo','tipo']);
      $vpDesc = flus_first_existing_column($pdo, 'venta_promos', ['descuento_monto','descuento','monto_descuento']);

      if ($vpVentaId && $vpNombre && $vpTipo && $vpDesc) {
        $stmt = $pdo->prepare("
          SELECT
            vp.`{$vpNombre}` AS promo_nombre,
            vp.`{$vpTipo}` AS promo_tipo,
            COUNT(*) AS veces_aplicada,
            COALESCE(SUM(vp.`{$vpDesc}`),0) AS descuento_total
          FROM venta_promos vp
          JOIN ventas v ON v.id = vp.`{$vpVentaId}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
          GROUP BY vp.`{$vpNombre}`, vp.`{$vpTipo}`
          ORDER BY descuento_total DESC
          LIMIT 5
        ");
        $stmt->execute([$fromStart, $toEnd]);
        $promociones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtTotal = $pdo->prepare("
          SELECT COALESCE(SUM(vp.`{$vpDesc}`),0)
          FROM venta_promos vp
          JOIN ventas v ON v.id = vp.`{$vpVentaId}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
        ");
        $stmtTotal->execute([$fromStart, $toEnd]);
        $totalDescuentosPromos = (float)$stmtTotal->fetchColumn();
      }
    }

    return [
      'promociones' => $promociones,
      'totalDescuentosPromos' => $totalDescuentosPromos,
    ];
  }
}

if (!function_exists('dashboard_compute_categories')) {
  function dashboard_compute_categories(array $ctx): array {
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $categorias = [];

    $hasVentas = (bool)($ctx['hasVentas'] ?? false);
    $hasVentaItems = (bool)($ctx['hasVentaItems'] ?? false);
    $hasProductos = (bool)($ctx['hasProductos'] ?? false);
    $ventasFechaCol = $ctx['ventasFechaCol'] ?? null;
    $ventasTotalCol = $ctx['ventasTotalCol'] ?? null;
    $viVentaIdCol = $ctx['viVentaIdCol'] ?? null;
    $viProdIdCol = $ctx['viProdIdCol'] ?? null;
    $viQtyCol = $ctx['viQtyCol'] ?? null;
    $prodCatCol = $ctx['prodCatCol'] ?? null;
    $viLineCol = $ctx['viLineCol'] ?? null;
    $viPriceCol = $ctx['viPriceCol'] ?? null;
    $lineExprVi = $ctx['lineExprVi'] ?? null;
    $lineExprVi2 = $ctx['lineExprVi2'] ?? null;
    $fromStart = (string)($ctx['fromStart'] ?? '');
    $toEnd = (string)($ctx['toEnd'] ?? '');
    $ventasDateSQL = (string)($ctx['ventasDateSQL'] ?? 'fecha');
    $ventasEmitidaCond = (string)($ctx['ventasEmitidaCond'] ?? '');
    $ventasTotalSQL = (string)($ctx['ventasTotalSQL'] ?? 'total');

    if ($hasVentas && $hasVentaItems && $hasProductos && $ventasFechaCol && $ventasTotalCol && $viVentaIdCol && $viProdIdCol && $viQtyCol) {
      $catCol = $prodCatCol ?: null;
      $catSelect = $catCol
        ? "COALESCE(NULLIF(TRIM(p.`{$catCol}`), ''), 'Sin categoria')"
        : "'Sin categoria'";

      if ($lineExprVi && $lineExprVi2) {
        $sqlCategorias = "
          SELECT
            {$catSelect} AS categoria,
            SUM(vi.`{$viQtyCol}`) AS unidades,
            COALESCE(SUM(v.{$ventasTotalSQL} * ({$lineExprVi} / NULLIF(vt.subtotal_total,0))), 0) AS ventas,
            COUNT(DISTINCT vi.`{$viVentaIdCol}`) AS num_ventas
          FROM venta_items vi
          JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          JOIN (
            SELECT vi2.`{$viVentaIdCol}` AS venta_id, SUM({$lineExprVi2}) AS subtotal_total
            FROM venta_items vi2
            JOIN ventas v2 ON v2.id = vi2.`{$viVentaIdCol}`
            WHERE v2.{$ventasDateSQL} >= ? AND v2.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            GROUP BY vi2.`{$viVentaIdCol}`
          ) vt ON vt.venta_id = vi.`{$viVentaIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            AND vt.subtotal_total > 0
          GROUP BY categoria
          ORDER BY ventas DESC
        ";
        $stmt = $pdo->prepare($sqlCategorias);
        $stmt->execute([$fromStart, $toEnd, $fromStart, $toEnd]);
        $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
      } else {
        $fallbackLine = $viLineCol ? "vi.`{$viLineCol}`" : (($viQtyCol && $viPriceCol) ? "(vi.`{$viQtyCol}` * vi.`{$viPriceCol}`)" : "0");
        $sqlCategorias = "
          SELECT
            {$catSelect} AS categoria,
            SUM(vi.`{$viQtyCol}`) AS unidades,
            COALESCE(SUM({$fallbackLine}),0) AS ventas,
            COUNT(DISTINCT vi.`{$viVentaIdCol}`) AS num_ventas
          FROM venta_items vi
          JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
          GROUP BY categoria
          ORDER BY ventas DESC
        ";
        $stmt = $pdo->prepare($sqlCategorias);
        $stmt->execute([$fromStart, $toEnd]);
        $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
      }
    }

    return [
      'categorias' => $categorias,
    ];
  }
}

if (!function_exists('dashboard_compute_cancellations')) {
  function dashboard_compute_cancellations(array $ctx): array {
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $ventasAnuladas = 0;
    $montoAnulado = 0.0;
    $tasaAnulacion = 0.0;

    $hasVentas = (bool)($ctx['hasVentas'] ?? false);
    $ventasFechaCol = $ctx['ventasFechaCol'] ?? null;
    $ventasEstadoCol = $ctx['ventasEstadoCol'] ?? null;
    $categoriaFiltro = $ctx['categoriaFiltro'] ?? null;
    $hasVentaItems = (bool)($ctx['hasVentaItems'] ?? false);
    $viVentaIdCol = $ctx['viVentaIdCol'] ?? null;
    $viProdIdCol = $ctx['viProdIdCol'] ?? null;
    $hasProductos = (bool)($ctx['hasProductos'] ?? false);
    $prodCatCol = $ctx['prodCatCol'] ?? null;
    $fromStart = (string)($ctx['fromStart'] ?? '');
    $toEnd = (string)($ctx['toEnd'] ?? '');
    $ventasDateSQL = (string)($ctx['ventasDateSQL'] ?? 'fecha');
    $ventasAnuladaCond = (string)($ctx['ventasAnuladaCond'] ?? ' AND 1=0 ');
    $catCondP = (string)($ctx['catCondP'] ?? '');
    $ventasTotalCol = $ctx['ventasTotalCol'] ?? null;
    $ventasTotalSQL = (string)($ctx['ventasTotalSQL'] ?? 'total');
    $ventasRango = (int)($ctx['ventasRango'] ?? 0);

    if ($hasVentas && $ventasFechaCol && $ventasEstadoCol) {
      if ($categoriaFiltro && $hasVentaItems && $viVentaIdCol && $hasProductos && $prodCatCol) {
        $stmt = $pdo->prepare("
          SELECT COUNT(DISTINCT v.id)
          FROM ventas v
          JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasAnuladaCond}
            {$catCondP}
        ");
        $stmt->execute([$fromStart, $toEnd]);
        $ventasAnuladas = (int)$stmt->fetchColumn();

        if ($ventasTotalCol) {
          $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(t.total_venta), 0)
            FROM (
              SELECT DISTINCT v.id, v.{$ventasTotalSQL} AS total_venta
              FROM ventas v
              JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
              JOIN productos p ON p.id = vi.`{$viProdIdCol}`
              WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasAnuladaCond}
                {$catCondP}
            ) t
          ");
          $stmt->execute([$fromStart, $toEnd]);
          $montoAnulado = (float)$stmt->fetchColumn();
        }
      } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasAnuladaCond}");
        $stmt->execute([$fromStart, $toEnd]);
        $ventasAnuladas = (int)$stmt->fetchColumn();

        if ($ventasTotalCol) {
          $stmt = $pdo->prepare("SELECT COALESCE(SUM({$ventasTotalSQL}),0) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasAnuladaCond}");
          $stmt->execute([$fromStart, $toEnd]);
          $montoAnulado = (float)$stmt->fetchColumn();
        }
      }

      $totalVentasConAnuladas = $ventasRango + $ventasAnuladas;
      $tasaAnulacion = ($totalVentasConAnuladas > 0)
        ? (($ventasAnuladas / $totalVentasConAnuladas) * 100)
        : 0.0;
    }

    return [
      'ventasAnuladas' => $ventasAnuladas,
      'montoAnulado' => $montoAnulado,
      'tasaAnulacion' => $tasaAnulacion,
    ];
  }
}

if (!function_exists('dashboard_compute_visual_datasets')) {
  function dashboard_compute_visual_datasets(array $ctx): array {
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $ventasLabels = [];
    $ventasData = [];
    $topProductosLabels = [];
    $topProductosData = [];
    $ventasPorHora = [];
    $ventasPorDiaSemana = [];

    $hasVentas = (bool)($ctx['hasVentas'] ?? false);
    $ventasFechaCol = $ctx['ventasFechaCol'] ?? null;
    $ventasTotalCol = $ctx['ventasTotalCol'] ?? null;
    $categoriaFiltro = $ctx['categoriaFiltro'] ?? null;
    $hasVentaItems = (bool)($ctx['hasVentaItems'] ?? false);
    $viVentaIdCol = $ctx['viVentaIdCol'] ?? null;
    $viProdIdCol = $ctx['viProdIdCol'] ?? null;
    $viQtyCol = $ctx['viQtyCol'] ?? null;
    $hasProductos = (bool)($ctx['hasProductos'] ?? false);
    $prodCatCol = $ctx['prodCatCol'] ?? null;
    $catCondP = (string)($ctx['catCondP'] ?? '');
    $fromStart = (string)($ctx['fromStart'] ?? '');
    $toEnd = (string)($ctx['toEnd'] ?? '');
    $ventasDateSQL = (string)($ctx['ventasDateSQL'] ?? 'fecha');
    $ventasEmitidaCond = (string)($ctx['ventasEmitidaCond'] ?? '');
    $fromDT = $ctx['fromDT'] ?? null;
    $toDT = $ctx['toDT'] ?? null;
    $ventasTotalSQL = (string)($ctx['ventasTotalSQL'] ?? 'total');
    $prodNombreCol = $ctx['prodNombreCol'] ?? null;

    if ($hasVentas && $ventasFechaCol) {
      if ($categoriaFiltro && $hasVentaItems && $viVentaIdCol && $hasProductos && $prodCatCol) {
        $stmt = $pdo->prepare("
          SELECT DATE(v.{$ventasDateSQL}) AS dia, COUNT(DISTINCT v.id) AS total
          FROM ventas v
          JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            {$catCondP}
          GROUP BY DATE(v.{$ventasDateSQL})
          ORDER BY dia
        ");
        $stmt->execute([$fromStart, $toEnd]);
      } else {
        $stmt = $pdo->prepare("
          SELECT DATE({$ventasDateSQL}) AS dia, COUNT(*) AS total
          FROM ventas
          WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
          GROUP BY DATE({$ventasDateSQL})
          ORDER BY dia
        ");
        $stmt->execute([$fromStart, $toEnd]);
      }

      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $ventasMap = [];
      foreach ($rows as $r) {
        $ventasMap[(string)$r['dia']] = (int)($r['total'] ?? 0);
      }

      if ($fromDT instanceof DateTimeInterface && $toDT instanceof DateTimeInterface) {
        $periodo = new DatePeriod($fromDT, new DateInterval('P1D'), (clone $toDT)->modify('+1 day'));
        foreach ($periodo as $d) {
          $dia = $d->format('Y-m-d');
          $ventasLabels[] = $dia;
          $ventasData[] = $ventasMap[$dia] ?? 0;
        }
      }
    }

    if ($hasVentas && $hasVentaItems && $hasProductos && $ventasFechaCol && $viVentaIdCol && $viProdIdCol && $viQtyCol && $prodNombreCol) {
      $stmt = $pdo->prepare("
        SELECT p.`{$prodNombreCol}` AS nombre, SUM(vi.`{$viQtyCol}`) AS total
        FROM venta_items vi
        JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
        JOIN productos p ON p.id = vi.`{$viProdIdCol}`
        WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
          {$catCondP}
        GROUP BY p.id, p.`{$prodNombreCol}`
        ORDER BY total DESC
        LIMIT 5
      ");
      $stmt->execute([$fromStart, $toEnd]);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $topProductosLabels[] = (string)($row['nombre'] ?? '');
        $topProductosData[] = (float)($row['total'] ?? 0);
      }
    }

    if ($hasVentas && $ventasFechaCol && $ventasTotalCol) {
      if ($categoriaFiltro && $hasVentaItems && $viVentaIdCol && $hasProductos && $prodCatCol) {
        $stmt = $pdo->prepare("
          SELECT t.hora,
                 COUNT(*) AS cantidad,
                 COALESCE(SUM(t.total_venta), 0) AS monto
          FROM (
            SELECT DISTINCT v.id,
                   HOUR(v.{$ventasDateSQL}) AS hora,
                   v.{$ventasTotalSQL} AS total_venta
            FROM ventas v
            JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
            JOIN productos p ON p.id = vi.`{$viProdIdCol}`
            WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
              {$catCondP}
          ) t
          GROUP BY t.hora
          ORDER BY hora
        ");
        $stmt->execute([$fromStart, $toEnd]);
        $ventasPorHora = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
          SELECT t.dia_num,
                 COUNT(*) AS cantidad,
                 COALESCE(SUM(t.total_venta), 0) AS monto
          FROM (
            SELECT DISTINCT v.id,
                   DAYOFWEEK(v.{$ventasDateSQL}) AS dia_num,
                   v.{$ventasTotalSQL} AS total_venta
            FROM ventas v
            JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
            JOIN productos p ON p.id = vi.`{$viProdIdCol}`
            WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
              {$catCondP}
          ) t
          GROUP BY t.dia_num
          ORDER BY dia_num
        ");
        $stmt->execute([$fromStart, $toEnd]);
        $ventasPorDiaSemana = $stmt->fetchAll(PDO::FETCH_ASSOC);
      } else {
        $stmt = $pdo->prepare("
          SELECT HOUR({$ventasDateSQL}) AS hora,
                 COUNT(*) AS cantidad,
                 COALESCE(SUM({$ventasTotalSQL}),0) AS monto
          FROM ventas
          WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
          GROUP BY HOUR({$ventasDateSQL})
          ORDER BY hora
        ");
        $stmt->execute([$fromStart, $toEnd]);
        $ventasPorHora = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
          SELECT DAYOFWEEK({$ventasDateSQL}) AS dia_num,
                 COUNT(*) AS cantidad,
                 COALESCE(SUM({$ventasTotalSQL}),0) AS monto
          FROM ventas
          WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
          GROUP BY DAYOFWEEK({$ventasDateSQL})
          ORDER BY dia_num
        ");
        $stmt->execute([$fromStart, $toEnd]);
        $ventasPorDiaSemana = $stmt->fetchAll(PDO::FETCH_ASSOC);
      }

      $diasSemana = ['', 'Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
      foreach ($ventasPorDiaSemana as &$dia) {
        $dia['dia_nombre'] = $diasSemana[(int)($dia['dia_num'] ?? 0)] ?? 'N/A';
      }
      unset($dia);
    }

    return [
      'ventasLabels' => $ventasLabels,
      'ventasData' => $ventasData,
      'topProductosLabels' => $topProductosLabels,
      'topProductosData' => $topProductosData,
      'ventasPorHora' => $ventasPorHora,
      'ventasPorDiaSemana' => $ventasPorDiaSemana,
    ];
  }
}

if (!function_exists('dashboard_compute_sparklines')) {
  function dashboard_compute_sparklines(array $ctx): array {
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $sparklineVentas = [];
    $sparklineFacturacion = [];

    $hasVentas = (bool)($ctx['hasVentas'] ?? false);
    $ventasFechaCol = $ctx['ventasFechaCol'] ?? null;
    $categoriaFiltro = $ctx['categoriaFiltro'] ?? null;
    $hasVentaItems = (bool)($ctx['hasVentaItems'] ?? false);
    $viVentaIdCol = $ctx['viVentaIdCol'] ?? null;
    $viProdIdCol = $ctx['viProdIdCol'] ?? null;
    $hasProductos = (bool)($ctx['hasProductos'] ?? false);
    $prodCatCol = $ctx['prodCatCol'] ?? null;
    $catCondP = (string)($ctx['catCondP'] ?? '');
    $ventasTotalCol = $ctx['ventasTotalCol'] ?? null;
    $viLineCol = $ctx['viLineCol'] ?? null;
    $sparklineStart = (string)($ctx['sparklineStart'] ?? '');
    $sparklineEnd = (string)($ctx['sparklineEnd'] ?? '');
    $ventasDateSQL = (string)($ctx['ventasDateSQL'] ?? 'fecha');
    $ventasEmitidaCond = (string)($ctx['ventasEmitidaCond'] ?? '');
    $ventasTotalSQL = (string)($ctx['ventasTotalSQL'] ?? 'total');
    $sparkFromDT = $ctx['sparkFromDT'] ?? null;
    $sparkToDT = $ctx['sparkToDT'] ?? null;

    if ($hasVentas && $ventasFechaCol) {
      if ($categoriaFiltro && $hasVentaItems && $viVentaIdCol && $hasProductos && $prodCatCol) {
        $stmt = $pdo->prepare("
          SELECT DATE(v.{$ventasDateSQL}) as dia, COUNT(DISTINCT v.id) as total
          FROM ventas v
          JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
            {$catCondP}
          GROUP BY DATE(v.{$ventasDateSQL})
          ORDER BY dia
        ");
        $stmt->execute([$sparklineStart, $sparklineEnd]);
        $mapVentas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
          $mapVentas[(string)$r['dia']] = (int)($r['total'] ?? 0);
        }

        $mapFact = [];
        if ($ventasTotalCol && $viLineCol) {
          $stmt = $pdo->prepare("
            SELECT DATE(v.{$ventasDateSQL}) as dia, COALESCE(SUM(vi.`{$viLineCol}`),0) as monto
            FROM ventas v
            JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
            JOIN productos p ON p.id = vi.`{$viProdIdCol}`
            WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
              {$catCondP}
            GROUP BY DATE(v.{$ventasDateSQL})
            ORDER BY dia
          ");
          $stmt->execute([$sparklineStart, $sparklineEnd]);
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapFact[(string)$r['dia']] = (float)($r['monto'] ?? 0);
          }
        }
      } else {
        $stmt = $pdo->prepare("
          SELECT DATE({$ventasDateSQL}) as dia, COUNT(*) as total
          FROM ventas
          WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
          GROUP BY DATE({$ventasDateSQL})
          ORDER BY dia
        ");
        $stmt->execute([$sparklineStart, $sparklineEnd]);
        $mapVentas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
          $mapVentas[(string)$r['dia']] = (int)($r['total'] ?? 0);
        }

        $mapFact = [];
        if ($ventasTotalCol) {
          $stmt = $pdo->prepare("
            SELECT DATE({$ventasDateSQL}) as dia, COALESCE(SUM({$ventasTotalSQL}),0) as monto
            FROM ventas
            WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
            GROUP BY DATE({$ventasDateSQL})
            ORDER BY dia
          ");
          $stmt->execute([$sparklineStart, $sparklineEnd]);
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapFact[(string)$r['dia']] = (float)($r['monto'] ?? 0);
          }
        }
      }

      if ($sparkFromDT instanceof DateTimeInterface && $sparkToDT instanceof DateTimeInterface) {
        $periodoSpark = new DatePeriod($sparkFromDT, new DateInterval('P1D'), (clone $sparkToDT)->modify('+1 day'));
        foreach ($periodoSpark as $d) {
          $k = $d->format('Y-m-d');
          $sparklineVentas[] = $mapVentas[$k] ?? 0;
          $sparklineFacturacion[] = $mapFact[$k] ?? 0.0;
        }
      }
    }

    return [
      'sparklineVentas' => $sparklineVentas,
      'sparklineFacturacion' => $sparklineFacturacion,
    ];
  }
}
