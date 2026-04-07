<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/facturacion_lib.php';

function flus_facturacion_panel_default_stats(): array
{
    return [
        'total_docs' => 0,
        'emitidas' => 0,
        'anuladas' => 0,
        'total_emitido' => 0.0,
        'ticket_promedio' => 0.0,
        'cae_real' => 0,
        'tipo_a' => 0,
        'tipo_b' => 0,
        'sin_cae_real' => 0,
        'cae_por_vencer' => 0,
    ];
}

function flus_facturacion_panel_default_incidencias(): array
{
    return [
        'pendientes' => 0,
        'transitorios' => 0,
        'post_arca' => 0,
        'rechazadas' => 0,
        'recuperadas' => 0,
    ];
}

function flus_facturacion_panel_like_param(string $value): string
{
    return '%' . addcslashes($value, "\\%_") . '%';
}

function flus_facturacion_panel_resolve_mode(PDO $pdo): array
{
    $modoFacturacion = 'demo';
    $configFact = flus_facturacion_config_activa($pdo);
    if ($configFact) {
        $modoFacturacion = flus_facturacion_modo_actual($configFact);
    }

    return [
        'modo_facturacion' => $modoFacturacion,
        'modo_facturacion_label' => flus_facturacion_modo_label($modoFacturacion),
    ];
}

function flus_facturacion_panel_resolve_schema(PDO $pdo): array
{
    if (!flus_table_exists($pdo, 'facturas')) {
        return [
            'facturas_exists' => false,
            'fecha_col' => null,
            'estado_col' => false,
            'cliente_id_col' => false,
            'venta_id_col' => false,
            'tipo_col' => false,
            'numero_col' => false,
            'punto_venta_col' => false,
            'cae_col' => false,
            'cae_vto_col' => false,
            'modo_col' => false,
            'estado_fiscal_col' => false,
            'join_clientes' => false,
            'fecha_expr' => 'NULL',
            'tipo_expr' => "''",
            'punto_venta_expr' => 'NULL',
            'numero_expr' => 'NULL',
            'total_expr' => '0',
            'estado_expr' => "'EMITIDA'",
            'venta_id_expr' => 'NULL',
            'cliente_id_expr' => 'NULL',
            'cae_expr' => 'NULL',
            'cae_vto_expr' => 'NULL',
            'modo_expr' => 'NULL',
            'estado_fiscal_expr' => "'NO_APLICA'",
            'cliente_nombre_expr' => 'NULL',
            'cliente_cuit_expr' => 'NULL',
            'cae_vto_sql' => 'NULL',
        ];
    }

    $fechaCol = flus_first_existing_column($pdo, 'facturas', ['creado_en', 'fecha']);
    $estadoCol = flus_column_exists($pdo, 'facturas', 'estado');
    $clienteIdCol = flus_column_exists($pdo, 'facturas', 'cliente_id');
    $ventaIdCol = flus_column_exists($pdo, 'facturas', 'venta_id');
    $tipoCol = flus_column_exists($pdo, 'facturas', 'tipo');
    $numeroCol = flus_column_exists($pdo, 'facturas', 'numero');
    $puntoVentaCol = flus_column_exists($pdo, 'facturas', 'punto_venta');
    $caeCol = flus_column_exists($pdo, 'facturas', 'cae');
    $caeVtoCol = flus_column_exists($pdo, 'facturas', 'cae_vto');
    $modoCol = flus_column_exists($pdo, 'facturas', 'modo');
    $estadoFiscalCol = flus_column_exists($pdo, 'facturas', 'estado_fiscal');
    $joinClientes = $clienteIdCol && flus_table_exists($pdo, 'clientes');

    return [
        'facturas_exists' => true,
        'fecha_col' => $fechaCol,
        'estado_col' => $estadoCol,
        'cliente_id_col' => $clienteIdCol,
        'venta_id_col' => $ventaIdCol,
        'tipo_col' => $tipoCol,
        'numero_col' => $numeroCol,
        'punto_venta_col' => $puntoVentaCol,
        'cae_col' => $caeCol,
        'cae_vto_col' => $caeVtoCol,
        'modo_col' => $modoCol,
        'estado_fiscal_col' => $estadoFiscalCol,
        'join_clientes' => $joinClientes,
        'fecha_expr' => $fechaCol ? 'f.`' . $fechaCol . '`' : 'NULL',
        'tipo_expr' => $tipoCol ? 'f.`tipo`' : "''",
        'punto_venta_expr' => $puntoVentaCol ? 'f.`punto_venta`' : 'NULL',
        'numero_expr' => $numeroCol ? 'f.`numero`' : 'NULL',
        'total_expr' => flus_column_exists($pdo, 'facturas', 'total') ? 'f.`total`' : '0',
        'estado_expr' => $estadoCol ? 'f.`estado`' : "'EMITIDA'",
        'venta_id_expr' => $ventaIdCol ? 'f.`venta_id`' : 'NULL',
        'cliente_id_expr' => $clienteIdCol ? 'f.`cliente_id`' : 'NULL',
        'cae_expr' => $caeCol ? 'f.`cae`' : 'NULL',
        'cae_vto_expr' => $caeVtoCol ? 'f.`cae_vto`' : 'NULL',
        'modo_expr' => $modoCol ? 'f.`modo`' : 'NULL',
        'estado_fiscal_expr' => $estadoFiscalCol ? "COALESCE(f.`estado_fiscal`, 'NO_APLICA')" : "'NO_APLICA'",
        'cliente_nombre_expr' => $joinClientes
            ? (flus_column_exists($pdo, 'clientes', 'nombre') ? 'c.`nombre`' : 'CONCAT("Cliente #", c.id)')
            : 'NULL',
        'cliente_cuit_expr' => $joinClientes && flus_column_exists($pdo, 'clientes', 'cuit') ? 'c.`cuit`' : 'NULL',
        'cae_vto_sql' => $caeVtoCol
            ? "CASE
                  WHEN CHAR_LENGTH(TRIM(f.`cae_vto`)) = 8 THEN STR_TO_DATE(f.`cae_vto`, '%Y%m%d')
                  ELSE STR_TO_DATE(f.`cae_vto`, '%Y-%m-%d')
               END"
            : 'NULL',
    ];
}

function flus_facturacion_panel_build_plan(PDO $pdo, array $filters): array
{
    $schema = flus_facturacion_panel_resolve_schema($pdo);
    $plan = [
        'schema' => $schema,
        'filters' => $filters,
        'avisos' => [],
        'tipos_disponibles' => [],
        'where_sql' => 'WHERE 1=1',
        'params' => [],
        'join_sql' => '',
        'order_sql' => 'f.id DESC',
    ];

    if (!$schema['facturas_exists']) {
        $plan['avisos'][] = 'La tabla de facturas no existe todavia. Aplica la migracion de facturacion para ver el historial.';
        return $plan;
    }

    if ($schema['tipo_col']) {
        $stTipos = $pdo->query("SELECT DISTINCT UPPER(TRIM(tipo)) AS tipo FROM facturas WHERE tipo IS NOT NULL AND TRIM(tipo) <> '' ORDER BY tipo");
        $plan['tipos_disponibles'] = $stTipos
            ? array_values(array_filter(array_map(static fn(array $row): string => trim((string)($row['tipo'] ?? '')), $stTipos->fetchAll(PDO::FETCH_ASSOC) ?: [])))
            : [];
    }

    $where = ['1=1'];
    $params = [];

    if ($filters['desde'] !== '' && $schema['fecha_col']) {
        $where[] = $schema['fecha_expr'] . ' >= :desde';
        $params[':desde'] = $filters['desde'] . ' 00:00:00';
    }
    if ($filters['hasta'] !== '' && $schema['fecha_col']) {
        $where[] = $schema['fecha_expr'] . ' <= :hasta';
        $params[':hasta'] = $filters['hasta'] . ' 23:59:59';
    }
    if (($filters['desde'] !== '' || $filters['hasta'] !== '') && !$schema['fecha_col']) {
        $plan['avisos'][] = 'Esta instalacion no tiene una fecha de factura estandar, por eso el filtro por fecha no se pudo aplicar.';
    }

    if ($filters['estado'] !== '' && in_array($filters['estado'], ['EMITIDA', 'ANULADA'], true) && $schema['estado_col']) {
        $where[] = 'f.`estado` = :estado';
        $params[':estado'] = $filters['estado'];
    }

    if ($filters['estado_fiscal'] !== '' && $schema['estado_fiscal_col']) {
        $allowedEstadosFiscales = ['NO_APLICA', 'PENDIENTE_ENVIO', 'ERROR_TRANSITORIO', 'ERROR_POST_ARCA', 'AUTORIZADA', 'RECUPERADA', 'RECHAZADA'];
        if (in_array($filters['estado_fiscal'], $allowedEstadosFiscales, true)) {
            $where[] = "COALESCE(f.`estado_fiscal`, 'NO_APLICA') = :estado_fiscal";
            $params[':estado_fiscal'] = $filters['estado_fiscal'];
        } else {
            $plan['filters']['estado_fiscal'] = '';
        }
    }

    if ($filters['tipo'] !== '' && $schema['tipo_col']) {
        if (in_array($filters['tipo'], $plan['tipos_disponibles'], true)) {
            $where[] = 'UPPER(TRIM(f.`tipo`)) = :tipo';
            $params[':tipo'] = $filters['tipo'];
        } else {
            $plan['filters']['tipo'] = '';
        }
    }

    if ($filters['cliente_id'] > 0 && $schema['cliente_id_col']) {
        $where[] = 'f.`cliente_id` = :cliente_id';
        $params[':cliente_id'] = $filters['cliente_id'];
    }

    if ($filters['venta_id'] > 0 && $schema['venta_id_col']) {
        $where[] = 'f.`venta_id` = :venta_id';
        $params[':venta_id'] = $filters['venta_id'];
    } elseif ($filters['venta_id'] > 0) {
        $plan['avisos'][] = 'Esta instalacion no permite filtrar por venta porque la tabla facturas no tiene venta_id.';
    }

    if ($filters['search'] !== '') {
        $searchWhere = [];
        $params[':search_like'] = flus_facturacion_panel_like_param($filters['search']);

        if ($schema['join_clientes']) {
            $searchWhere[] = $schema['cliente_nombre_expr'] . " LIKE :search_like ESCAPE '\\\\'";
            if ($schema['cliente_cuit_expr'] !== 'NULL') {
                $searchWhere[] = $schema['cliente_cuit_expr'] . " LIKE :search_like ESCAPE '\\\\'";
            }
        }
        if ($schema['cae_col']) {
            $searchWhere[] = "f.`cae` LIKE :search_like ESCAPE '\\\\'";
        }
        if ($schema['tipo_col']) {
            $searchWhere[] = "f.`tipo` LIKE :search_like ESCAPE '\\\\'";
        }
        if ($schema['numero_col'] && ctype_digit($filters['search'])) {
            $searchWhere[] = 'f.`numero` = :search_numero';
            $params[':search_numero'] = (int)$filters['search'];
        }
        if ($schema['venta_id_col'] && ctype_digit($filters['search'])) {
            $searchWhere[] = 'f.`venta_id` = :search_venta_id';
            $params[':search_venta_id'] = (int)$filters['search'];
        }
        if ($schema['punto_venta_col'] && $schema['numero_col'] && preg_match('/^\s*(\d{1,4})\D+(\d{1,8})\s*$/', $filters['search'], $m) === 1) {
            $searchWhere[] = '(f.`punto_venta` = :search_pv AND f.`numero` = :search_comp_num)';
            $params[':search_pv'] = (int)$m[1];
            $params[':search_comp_num'] = (int)$m[2];
        }

        if ($searchWhere !== []) {
            $where[] = '(' . implode(' OR ', $searchWhere) . ')';
        }
    }

    $plan['where_sql'] = 'WHERE ' . implode(' AND ', $where);
    $plan['params'] = $params;
    $plan['join_sql'] = $schema['join_clientes'] ? 'LEFT JOIN clientes c ON c.id = f.cliente_id' : '';
    $plan['order_sql'] = $schema['fecha_col'] ? $schema['fecha_expr'] . ' DESC, f.id DESC' : 'f.id DESC';

    return $plan;
}

function flus_facturacion_panel_read(PDO $pdo, array $filters): array
{
    $mode = flus_facturacion_panel_resolve_mode($pdo);
    $plan = flus_facturacion_panel_build_plan($pdo, $filters);
    $stats = flus_facturacion_panel_default_stats();
    $incidencias = flus_facturacion_panel_default_incidencias();
    $facturas = [];
    $totalRows = 0;
    $totalPages = 1;
    $fromRow = 0;
    $toRow = 0;

    if ($plan['schema']['facturas_exists']) {
        $sqlCount = "
            SELECT COUNT(*)
            FROM facturas f
            {$plan['join_sql']}
            {$plan['where_sql']}
        ";
        $stCount = $pdo->prepare($sqlCount);
        $stCount->execute($plan['params']);
        $totalRows = (int)$stCount->fetchColumn();

        $perPage = max(1, (int)$plan['filters']['per_page']);
        $page = max(1, (int)$plan['filters']['page']);
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $fromRow = $totalRows > 0 ? $offset + 1 : 0;
        $toRow = min($offset + $perPage, $totalRows);
        $plan['filters']['page'] = $page;

        $schema = $plan['schema'];
        $sqlStats = "
            SELECT
                COUNT(*) AS total_docs,
                SUM(CASE WHEN {$schema['estado_expr']} = 'ANULADA' THEN 1 ELSE 0 END) AS anuladas,
                SUM(CASE WHEN {$schema['estado_expr']} = 'ANULADA' THEN 0 ELSE 1 END) AS emitidas,
                COALESCE(SUM(CASE WHEN {$schema['estado_expr']} = 'ANULADA' THEN 0 ELSE {$schema['total_expr']} END), 0) AS total_emitido,
                AVG(CASE WHEN {$schema['estado_expr']} = 'ANULADA' THEN NULL ELSE {$schema['total_expr']} END) AS ticket_promedio,
                " . ($schema['cae_col']
                    ? "SUM(CASE WHEN f.`cae` IS NOT NULL AND TRIM(f.`cae`) <> '' AND f.`cae` NOT LIKE 'DEMO%' THEN 1 ELSE 0 END)"
                    : '0') . " AS cae_real,
                " . ($schema['tipo_col']
                    ? "SUM(CASE WHEN UPPER(TRIM(f.`tipo`)) LIKE '%A' THEN 1 ELSE 0 END)"
                    : '0') . " AS tipo_a,
                " . ($schema['tipo_col']
                    ? "SUM(CASE WHEN UPPER(TRIM(f.`tipo`)) LIKE '%B' THEN 1 ELSE 0 END)"
                    : '0') . " AS tipo_b,
                " . (($schema['modo_col'] && $schema['cae_col'])
                    ? "SUM(CASE WHEN COALESCE(f.`modo`, 'demo') <> 'demo' AND (f.`cae` IS NULL OR TRIM(f.`cae`) = '' OR f.`cae` LIKE 'DEMO%') THEN 1 ELSE 0 END)"
                    : '0') . " AS sin_cae_real,
                " . ($schema['cae_col'] && $schema['cae_vto_col']
                    ? "SUM(CASE
                            WHEN {$schema['estado_expr']} <> 'ANULADA'
                             AND f.`cae` IS NOT NULL
                             AND TRIM(f.`cae`) <> ''
                             AND f.`cae` NOT LIKE 'DEMO%'
                             AND {$schema['cae_vto_sql']} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)
                            THEN 1 ELSE 0 END)"
                    : '0') . " AS cae_por_vencer
            FROM facturas f
            {$plan['join_sql']}
            {$plan['where_sql']}
        ";
        $stStats = $pdo->prepare($sqlStats);
        $stStats->execute($plan['params']);
        $statsRow = $stStats->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats = [
            'total_docs' => (int)($statsRow['total_docs'] ?? 0),
            'emitidas' => (int)($statsRow['emitidas'] ?? 0),
            'anuladas' => (int)($statsRow['anuladas'] ?? 0),
            'total_emitido' => (float)($statsRow['total_emitido'] ?? 0),
            'ticket_promedio' => (float)($statsRow['ticket_promedio'] ?? 0),
            'cae_real' => (int)($statsRow['cae_real'] ?? 0),
            'tipo_a' => (int)($statsRow['tipo_a'] ?? 0),
            'tipo_b' => (int)($statsRow['tipo_b'] ?? 0),
            'sin_cae_real' => (int)($statsRow['sin_cae_real'] ?? 0),
            'cae_por_vencer' => (int)($statsRow['cae_por_vencer'] ?? 0),
        ];

        if ($schema['estado_fiscal_col']) {
            $sqlIncidencias = "
                SELECT
                    SUM(CASE WHEN COALESCE(f.`estado_fiscal`, 'NO_APLICA') = 'PENDIENTE_ENVIO' THEN 1 ELSE 0 END) AS pendientes,
                    SUM(CASE WHEN COALESCE(f.`estado_fiscal`, 'NO_APLICA') = 'ERROR_TRANSITORIO' THEN 1 ELSE 0 END) AS transitorios,
                    SUM(CASE WHEN COALESCE(f.`estado_fiscal`, 'NO_APLICA') = 'ERROR_POST_ARCA' THEN 1 ELSE 0 END) AS post_arca,
                    SUM(CASE WHEN COALESCE(f.`estado_fiscal`, 'NO_APLICA') = 'RECHAZADA' THEN 1 ELSE 0 END) AS rechazadas,
                    SUM(CASE WHEN COALESCE(f.`estado_fiscal`, 'NO_APLICA') = 'RECUPERADA' THEN 1 ELSE 0 END) AS recuperadas
                FROM facturas f
                {$plan['join_sql']}
                {$plan['where_sql']}
            ";
            $stIncidencias = $pdo->prepare($sqlIncidencias);
            $stIncidencias->execute($plan['params']);
            $incidenciasRow = $stIncidencias->fetch(PDO::FETCH_ASSOC) ?: [];
            $incidencias = [
                'pendientes' => (int)($incidenciasRow['pendientes'] ?? 0),
                'transitorios' => (int)($incidenciasRow['transitorios'] ?? 0),
                'post_arca' => (int)($incidenciasRow['post_arca'] ?? 0),
                'rechazadas' => (int)($incidenciasRow['rechazadas'] ?? 0),
                'recuperadas' => (int)($incidenciasRow['recuperadas'] ?? 0),
            ];
        }

        $sqlList = "
            SELECT
                f.id,
                {$schema['fecha_expr']} AS fecha,
                {$schema['tipo_expr']} AS tipo,
                {$schema['punto_venta_expr']} AS punto_venta,
                {$schema['numero_expr']} AS numero,
                {$schema['total_expr']} AS total,
                {$schema['estado_expr']} AS estado,
                {$schema['estado_fiscal_expr']} AS estado_fiscal,
                {$schema['cliente_id_expr']} AS cliente_id,
                {$schema['cliente_nombre_expr']} AS cliente_nombre,
                {$schema['cliente_cuit_expr']} AS cliente_cuit,
                {$schema['venta_id_expr']} AS venta_id,
                {$schema['cae_expr']} AS cae,
                {$schema['cae_vto_expr']} AS cae_vto,
                {$schema['modo_expr']} AS modo
            FROM facturas f
            {$plan['join_sql']}
            {$plan['where_sql']}
            ORDER BY {$plan['order_sql']}
            LIMIT :limit OFFSET :offset
        ";
        $st = $pdo->prepare($sqlList);
        foreach ($plan['params'] as $key => $value) {
            $st->bindValue($key, $value);
        }
        $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        $facturas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return [
        'modo_facturacion' => $mode['modo_facturacion'],
        'modo_facturacion_label' => $mode['modo_facturacion_label'],
        'tipos_disponibles' => $plan['tipos_disponibles'],
        'avisos' => $plan['avisos'],
        'facturas' => $facturas,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages,
        'from_row' => $fromRow,
        'to_row' => $toRow,
        'stats' => $stats,
        'incidencias' => $incidencias,
        'filters' => $plan['filters'],
        'plan' => $plan,
    ];
}

function flus_facturacion_panel_export_rows(PDO $pdo, array $plan): array
{
    $schema = $plan['schema'] ?? [];
    if (!(bool)($schema['facturas_exists'] ?? false)) {
        return [];
    }

    $sqlExport = "
        SELECT
            {$schema['fecha_expr']} AS fecha,
            {$schema['tipo_expr']} AS tipo,
            {$schema['punto_venta_expr']} AS punto_venta,
            {$schema['numero_expr']} AS numero,
            {$schema['cliente_nombre_expr']} AS cliente_nombre,
            {$schema['cliente_cuit_expr']} AS cliente_cuit,
            {$schema['total_expr']} AS total,
            {$schema['estado_expr']} AS estado,
            {$schema['estado_fiscal_expr']} AS estado_fiscal,
            {$schema['venta_id_expr']} AS venta_id,
            {$schema['cae_expr']} AS cae,
            {$schema['cae_vto_expr']} AS cae_vto,
            {$schema['modo_expr']} AS modo
        FROM facturas f
        {$plan['join_sql']}
        {$plan['where_sql']}
        ORDER BY {$plan['order_sql']}
        LIMIT " . flus_export_limit();

    $stExport = $pdo->prepare($sqlExport);
    $stExport->execute($plan['params'] ?? []);

    return $stExport->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
