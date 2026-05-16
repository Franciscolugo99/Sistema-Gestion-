<?php
// src/cobranzas_lib.php

declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/facturacion_manual_lib.php';

$flusApiHelpersPath = __DIR__ . '/api_helpers.php';
if (file_exists($flusApiHelpersPath)) {
    require_once $flusApiHelpersPath;
}

function flus_cobranzas_tables_ready(PDO $pdo): bool
{
    return flus_table_exists($pdo, 'cobranzas') && flus_table_exists($pdo, 'cobranza_aplicaciones');
}

function flus_cobranzas_receipts_ready(PDO $pdo): bool
{
    return flus_cobranzas_tables_ready($pdo)
        && flus_facturacion_documentos_table_ready($pdo)
        && flus_table_exists($pdo, 'recibo_aplicaciones')
        && flus_column_exists($pdo, 'cobranzas', 'recibo_documento_id');
}

function flus_cobranzas_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $schema = flus_current_db($pdo);
    if ($schema === '' || !flus_table_exists($pdo, $table, $schema)) {
        throw new RuntimeException("La tabla {$table} no existe.");
    }

    $colsSet = flus_columns_set($pdo, $schema, $table);
    $cols = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $col => $value) {
        $col = (string)$col;
        if (!isset($colsSet[$col])) {
            continue;
        }
        $cols[] = "`{$col}`";
        $placeholders[] = ':' . $col;
        $params[':' . $col] = $value;
    }

    if ($cols === []) {
        throw new RuntimeException("No hay columnas compatibles para insertar en {$table}.");
    }

    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', $cols),
        implode(', ', $placeholders)
    );

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int)$pdo->lastInsertId();
}

function flus_cobranzas_build_sale_external_key(int $ventaId, int $linea, string $medioPago, float $monto): string
{
    return implode(':', [
        'VENTA',
        max(0, $ventaId),
        'LINEA',
        max(1, $linea),
        strtoupper(trim($medioPago)),
        number_format(round($monto, 2), 2, '.', ''),
    ]);
}

function flus_cobranzas_build_invoice_external_key(int $facturaId, string $requestUid): string
{
    $requestUid = trim($requestUid);
    if ($requestUid === '') {
        $requestUid = bin2hex(random_bytes(16));
    }

    return 'FACTURA:' . max(0, $facturaId) . ':' . substr(hash('sha256', $requestUid), 0, 40);
}

function flus_cobranzas_build_cc_external_key(int $ccMovimientoId): string
{
    return 'CCPAGO:' . max(0, $ccMovimientoId);
}

function flus_cobranzas_build_application_key(int $cobranzaId, string $tipoAplicacion, int $refId): string
{
    return implode(':', [
        'COB',
        max(0, $cobranzaId),
        strtoupper(trim($tipoAplicacion)),
        max(0, $refId),
    ]);
}

function flus_cobranzas_build_receipt_request_uid(int $cobranzaId): string
{
    return sprintf('00000000-0000-4000-8000-%012d', max(0, $cobranzaId));
}

function flus_cobranzas_build_receipt_application_key(int $reciboDocumentoId, string $tipoAplicacion, int $refId): string
{
    return implode(':', [
        'REC',
        max(0, $reciboDocumentoId),
        strtoupper(trim($tipoAplicacion)),
        max(0, $refId),
    ]);
}

function flus_cobranzas_find_by_external_key(PDO $pdo, string $externalKey): ?array
{
    $externalKey = trim($externalKey);
    if ($externalKey === '' || !flus_cobranzas_tables_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM cobranzas WHERE external_key = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$externalKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_find_by_id(PDO $pdo, int $cobranzaId): ?array
{
    if ($cobranzaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM cobranzas WHERE id = ? LIMIT 1');
    $st->execute([$cobranzaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_find_by_cc_movimiento_id(PDO $pdo, int $ccMovimientoId): ?array
{
    if ($ccMovimientoId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM cobranzas WHERE cc_movimiento_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$ccMovimientoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_find_receipt_application_by_cobranza(PDO $pdo, int $cobranzaId): ?array
{
    if ($cobranzaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE cobranza_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$cobranzaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_float_eq(float $a, float $b, float $delta = 0.009): bool
{
    return abs($a - $b) <= $delta;
}

function flus_cobranzas_factura_es_cobrable(array $factura): bool
{
    $naturaleza = strtoupper(trim((string)($factura['naturaleza'] ?? '')));
    $tipo = strtoupper(trim((string)($factura['tipo'] ?? '')));

    return $naturaleza !== 'NC' && !str_starts_with($tipo, 'NC');
}

function flus_cobranzas_notas_credito_para_factura(PDO $pdo, int $facturaId): array
{
    if ($facturaId <= 0
        || !flus_table_exists($pdo, 'facturas')
        || !flus_column_exists($pdo, 'facturas', 'factura_asociada_id')) {
        return ['count' => 0, 'total' => 0.0];
    }

    $where = ['factura_asociada_id = ?'];
    if (flus_column_exists($pdo, 'facturas', 'naturaleza')) {
        $where[] = "UPPER(COALESCE(naturaleza, '')) = 'NC'";
    } elseif (flus_column_exists($pdo, 'facturas', 'tipo')) {
        $where[] = "UPPER(COALESCE(tipo, '')) LIKE 'NC%'";
    }
    if (flus_column_exists($pdo, 'facturas', 'estado')) {
        $where[] = "UPPER(COALESCE(estado, 'EMITIDA')) NOT IN ('ANULADA', 'CANCELADA')";
    }

    $totalExpr = flus_column_exists($pdo, 'facturas', 'total') ? 'ABS(COALESCE(total, 0))' : '0';
    $st = $pdo->prepare('
        SELECT COUNT(*) AS count, COALESCE(SUM(' . $totalExpr . '), 0) AS total
        FROM facturas
        WHERE ' . implode(' AND ', $where)
    );
    $st->execute([$facturaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'count' => (int)($row['count'] ?? 0),
        'total' => round(max(0.0, (float)($row['total'] ?? 0)), 2),
    ];
}

function flus_cobranzas_resumen_para_factura(PDO $pdo, array $factura): array
{
    $facturaId = (int)($factura['id'] ?? 0);
    $documentoId = (int)($factura['documento_id'] ?? 0);
    $totalOriginal = round(abs((float)($factura['total'] ?? $factura['venta_total'] ?? 0)), 2);
    $notasCredito = flus_cobranzas_notas_credito_para_factura($pdo, $facturaId);
    $totalNc = round(min($totalOriginal, (float)($notasCredito['total'] ?? 0)), 2);
    $total = round(max(0.0, $totalOriginal - $totalNc), 2);
    $cobrable = $facturaId > 0 && $totalOriginal > 0 && flus_cobranzas_factura_es_cobrable($factura);

    $base = [
        'total' => $total,
        'total_original' => $totalOriginal,
        'total_nc' => $totalNc,
        'nc_count' => (int)($notasCredito['count'] ?? 0),
        'cobrado' => 0.0,
        'saldo' => $total,
        'estado' => $cobrable ? ($total <= 0.009 ? 'COMPENSADA' : 'SIN_COBRAR') : 'NO_APLICA',
        'label' => $cobrable ? ($total <= 0.009 ? 'Compensada por NC' : 'Sin cobrar') : 'No aplica',
        'cobrable' => $cobrable,
        'receipts_ready' => flus_cobranzas_receipts_ready($pdo),
        'recibos_count' => 0,
    ];

    if (!$base['receipts_ready']) {
        $base['estado'] = 'SIN_TABLAS';
        $base['label'] = 'Pendiente de esquema';
        return $base;
    }

    if (!$cobrable) {
        return $base;
    }

    $recibos = flus_cobranzas_fetch_receipts_by_factura($pdo, $facturaId, $documentoId > 0 ? $documentoId : null);
    $cobrado = 0.0;
    foreach ($recibos as $recibo) {
        $estadoCobranza = strtoupper(trim((string)($recibo['cobranza_estado'] ?? 'ACTIVA')));
        $estadoRecibo = strtoupper(trim((string)($recibo['recibo_estado'] ?? 'EMITIDO')));
        if (in_array($estadoCobranza, ['ANULADA', 'CANCELADA'], true) || in_array($estadoRecibo, ['ANULADO', 'CANCELADO'], true)) {
            continue;
        }
        $cobrado += max(0.0, round((float)($recibo['monto_aplicado'] ?? 0), 2));
    }

    $cobrado = round($cobrado, 2);
    $saldo = round(max(0.0, $total - $cobrado), 2);
    $estado = 'SIN_COBRAR';
    $label = 'Sin cobrar';

    if ($total <= 0.009 && $totalNc > 0.009) {
        $estado = 'COMPENSADA';
        $label = 'Compensada por NC';
        $saldo = 0.0;
    } elseif ($saldo <= 0.009) {
        $estado = 'COBRADA';
        $label = 'Cobrada';
        $saldo = 0.0;
    } elseif ($cobrado > 0.009) {
        $estado = 'PARCIAL';
        $label = 'Cobro parcial';
    }

    return array_merge($base, [
        'cobrado' => $cobrado,
        'saldo' => $saldo,
        'estado' => $estado,
        'label' => $label,
        'recibos_count' => count($recibos),
    ]);
}

function flus_cobranzas_panel_like_param(string $value): string
{
    return '%' . addcslashes($value, "\\%_") . '%';
}

function flus_cobranzas_panel_normalize_filters(array $filters): array
{
    $validDate = static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
    };

    $desde = $validDate((string)($filters['desde'] ?? ''));
    $hasta = $validDate((string)($filters['hasta'] ?? ''));
    if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
        [$desde, $hasta] = [$hasta, $desde];
    }

    $estadoCobro = strtoupper(trim((string)($filters['estado_cobro'] ?? 'PENDIENTE')));
    if (!in_array($estadoCobro, ['PENDIENTE', 'SIN_COBRAR', 'PARCIAL', 'COBRADA', 'COMPENSADA', 'TODAS'], true)) {
        $estadoCobro = 'PENDIENTE';
    }

    $perPage = (int)($filters['per_page'] ?? 50);
    if (!in_array($perPage, [20, 50, 100], true)) {
        $perPage = 50;
    }

    return [
        'desde' => $desde,
        'hasta' => $hasta,
        'estado_cobro' => $estadoCobro,
        'search' => trim((string)($filters['search'] ?? $filters['q'] ?? '')),
        'cliente_id' => max(0, (int)($filters['cliente_id'] ?? 0)),
        'per_page' => $perPage,
        'page' => max(1, (int)($filters['page'] ?? 1)),
    ];
}

function flus_cobranzas_panel_estado_desde_montos(float $total, float $cobrado, float $creditado = 0.0): array
{
    $total = round(max(0.0, $total), 2);
    $cobrado = round(max(0.0, $cobrado), 2);
    $creditado = round(min($total, max(0.0, $creditado)), 2);
    $neto = round(max(0.0, $total - $creditado), 2);
    $saldo = round(max(0.0, $neto - $cobrado), 2);

    if ($neto <= 0.009 && $creditado > 0.009) {
        return ['estado' => 'COMPENSADA', 'label' => 'Compensada por NC', 'saldo' => 0.0, 'neto' => 0.0];
    }
    if ($saldo <= 0.009 && $neto > 0) {
        return ['estado' => 'COBRADA', 'label' => 'Cobrada', 'saldo' => 0.0, 'neto' => $neto];
    }
    if ($cobrado > 0.009) {
        return ['estado' => 'PARCIAL', 'label' => 'Cobro parcial', 'saldo' => $saldo, 'neto' => $neto];
    }

    return ['estado' => 'SIN_COBRAR', 'label' => 'Sin cobrar', 'saldo' => $saldo, 'neto' => $neto];
}

function flus_cobranzas_panel_read(PDO $pdo, array $filters): array
{
    $filters = flus_cobranzas_panel_normalize_filters($filters);
    $avisos = [];
    $rows = [];
    $stats = [
        'total_facturado' => 0.0,
        'total_nc' => 0.0,
        'total_neto' => 0.0,
        'total_cobrado' => 0.0,
        'total_saldo' => 0.0,
        'sin_cobrar' => 0,
        'parciales' => 0,
        'cobradas' => 0,
        'compensadas' => 0,
        'pendientes' => 0,
    ];

    if (!flus_table_exists($pdo, 'facturas')) {
        return [
            'filters' => $filters,
            'avisos' => ['La tabla facturas no existe todavia.'],
            'rows' => [],
            'stats' => $stats,
            'total_rows' => 0,
            'total_pages' => 1,
            'from_row' => 0,
            'to_row' => 0,
            'receipts_ready' => flus_cobranzas_receipts_ready($pdo),
        ];
    }

    $fechaCol = flus_first_existing_column($pdo, 'facturas', ['creado_en', 'fecha']);
    $fechaExpr = $fechaCol !== null ? 'f.`' . $fechaCol . '`' : 'NULL';
    $hasClientes = flus_table_exists($pdo, 'clientes') && flus_column_exists($pdo, 'facturas', 'cliente_id');
    $hasDocumentoId = flus_column_exists($pdo, 'facturas', 'documento_id');
    $hasNaturaleza = flus_column_exists($pdo, 'facturas', 'naturaleza');
    $hasTipo = flus_column_exists($pdo, 'facturas', 'tipo');
    $hasEstado = flus_column_exists($pdo, 'facturas', 'estado');
    $receiptsReady = flus_cobranzas_receipts_ready($pdo);

    if (!$receiptsReady) {
        $avisos[] = 'Falta aplicar el esquema de cobranzas y recibos para calcular saldos.';
    }

    $where = ['1=1'];
    $params = [];
    if ($hasNaturaleza) {
        $where[] = "UPPER(COALESCE(f.`naturaleza`, '')) <> 'NC'";
    }
    if ($hasTipo) {
        $where[] = "UPPER(COALESCE(f.`tipo`, '')) NOT LIKE 'NC%'";
    }
    if ($hasEstado) {
        $where[] = "UPPER(COALESCE(f.`estado`, 'EMITIDA')) <> 'ANULADA'";
    }
    if ($filters['desde'] !== '' && $fechaCol !== null) {
        $where[] = $fechaExpr . ' >= :desde';
        $params[':desde'] = $filters['desde'] . ' 00:00:00';
    }
    if ($filters['hasta'] !== '' && $fechaCol !== null) {
        $where[] = $fechaExpr . ' <= :hasta';
        $params[':hasta'] = $filters['hasta'] . ' 23:59:59';
    }
    if (($filters['desde'] !== '' || $filters['hasta'] !== '') && $fechaCol === null) {
        $avisos[] = 'Esta instalacion no tiene una fecha de factura estandar; no se aplico el filtro por fecha.';
    }
    if ($filters['cliente_id'] > 0 && flus_column_exists($pdo, 'facturas', 'cliente_id')) {
        $where[] = 'f.`cliente_id` = :cliente_id';
        $params[':cliente_id'] = $filters['cliente_id'];
    }
    if ($filters['search'] !== '') {
        $searchWhere = [];
        $searchLike = flus_cobranzas_panel_like_param($filters['search']);
        if ($hasClientes) {
            $searchWhere[] = "c.`nombre` LIKE :search_cliente_nombre ESCAPE '\\\\'";
            $params[':search_cliente_nombre'] = $searchLike;
            if (flus_column_exists($pdo, 'clientes', 'cuit')) {
                $searchWhere[] = "c.`cuit` LIKE :search_cliente_cuit ESCAPE '\\\\'";
                $params[':search_cliente_cuit'] = $searchLike;
            }
        }
        if (flus_column_exists($pdo, 'facturas', 'cae')) {
            $searchWhere[] = "f.`cae` LIKE :search_cae ESCAPE '\\\\'";
            $params[':search_cae'] = $searchLike;
        }
        if ($hasTipo) {
            $searchWhere[] = "f.`tipo` LIKE :search_tipo ESCAPE '\\\\'";
            $params[':search_tipo'] = $searchLike;
        }
        if (flus_column_exists($pdo, 'facturas', 'numero') && ctype_digit($filters['search'])) {
            $searchWhere[] = 'f.`numero` = :search_numero';
            $params[':search_numero'] = (int)$filters['search'];
        }
        if (flus_column_exists($pdo, 'facturas', 'venta_id') && ctype_digit($filters['search'])) {
            $searchWhere[] = 'f.`venta_id` = :search_venta_id';
            $params[':search_venta_id'] = (int)$filters['search'];
        }
        if (flus_column_exists($pdo, 'facturas', 'punto_venta')
            && flus_column_exists($pdo, 'facturas', 'numero')
            && preg_match('/^\s*(\d{1,4})\D+(\d{1,8})\s*$/', $filters['search'], $m) === 1) {
            $searchWhere[] = '(f.`punto_venta` = :search_pv AND f.`numero` = :search_comp_num)';
            $params[':search_pv'] = (int)$m[1];
            $params[':search_comp_num'] = (int)$m[2];
        }
        if ($searchWhere !== []) {
            $where[] = '(' . implode(' OR ', $searchWhere) . ')';
        }
    }

    $joinClientes = $hasClientes ? 'LEFT JOIN clientes c ON c.id = f.cliente_id' : '';
    $clienteNombreExpr = $hasClientes ? 'c.`nombre`' : 'NULL';
    $clienteCuitExpr = $hasClientes && flus_column_exists($pdo, 'clientes', 'cuit') ? 'c.`cuit`' : 'NULL';
    $documentoExpr = $hasDocumentoId ? 'f.`documento_id`' : 'NULL';
    $joinRecibos = '';
    $cobradoExpr = '0';
    $recibosCountExpr = '0';
    $ncTotalExpr = '0';
    $ncCountExpr = '0';
    if ($receiptsReady) {
        $docFallback = $hasDocumentoId
            ? " OR ((ra.factura_id IS NULL OR ra.factura_id = 0) AND ra.documento_id = f.`documento_id`)"
            : '';
        $joinRecibos = "
            LEFT JOIN recibo_aplicaciones ra ON (ra.factura_id = f.id{$docFallback})
            LEFT JOIN cobranzas cb ON cb.id = ra.cobranza_id
            LEFT JOIN documentos_comerciales rd ON rd.id = ra.recibo_documento_id
        ";
        $activeReceipt = "COALESCE(UPPER(cb.estado), 'ACTIVA') NOT IN ('ANULADA', 'CANCELADA')
            AND COALESCE(UPPER(rd.estado), 'EMITIDO') NOT IN ('ANULADO', 'CANCELADO')";
        $cobradoExpr = "COALESCE(SUM(CASE WHEN {$activeReceipt} THEN ra.monto ELSE 0 END), 0)";
        $recibosCountExpr = "COUNT(DISTINCT CASE WHEN {$activeReceipt} THEN ra.recibo_documento_id ELSE NULL END)";
    }
    if (flus_column_exists($pdo, 'facturas', 'factura_asociada_id')) {
        $ncWhere = ['fnc.`factura_asociada_id` = f.id'];
        if ($hasNaturaleza) {
            $ncWhere[] = "UPPER(COALESCE(fnc.`naturaleza`, '')) = 'NC'";
        } elseif ($hasTipo) {
            $ncWhere[] = "UPPER(COALESCE(fnc.`tipo`, '')) LIKE 'NC%'";
        }
        if ($hasEstado) {
            $ncWhere[] = "UPPER(COALESCE(fnc.`estado`, 'EMITIDA')) NOT IN ('ANULADA', 'CANCELADA')";
        }
        $ncTotalCol = flus_column_exists($pdo, 'facturas', 'total') ? 'ABS(COALESCE(fnc.`total`, 0))' : '0';
        $ncTotalExpr = "(SELECT COALESCE(SUM({$ncTotalCol}), 0) FROM facturas fnc WHERE " . implode(' AND ', $ncWhere) . ')';
        $ncCountExpr = "(SELECT COUNT(*) FROM facturas fnc WHERE " . implode(' AND ', $ncWhere) . ')';
    }

    $sql = "
        SELECT
            f.id,
            {$fechaExpr} AS fecha,
            " . (flus_column_exists($pdo, 'facturas', 'tipo') ? 'f.`tipo`' : "''") . " AS tipo,
            " . (flus_column_exists($pdo, 'facturas', 'punto_venta') ? 'f.`punto_venta`' : 'NULL') . " AS punto_venta,
            " . (flus_column_exists($pdo, 'facturas', 'numero') ? 'f.`numero`' : 'NULL') . " AS numero,
            " . (flus_column_exists($pdo, 'facturas', 'total') ? 'f.`total`' : '0') . " AS total,
            " . (flus_column_exists($pdo, 'facturas', 'estado') ? 'f.`estado`' : "'EMITIDA'") . " AS estado,
            " . (flus_column_exists($pdo, 'facturas', 'estado_fiscal') ? "COALESCE(f.`estado_fiscal`, 'NO_APLICA')" : "'NO_APLICA'") . " AS estado_fiscal,
            " . (flus_column_exists($pdo, 'facturas', 'cliente_id') ? 'f.`cliente_id`' : 'NULL') . " AS cliente_id,
            {$clienteNombreExpr} AS cliente_nombre,
            {$clienteCuitExpr} AS cliente_cuit,
            " . (flus_column_exists($pdo, 'facturas', 'venta_id') ? 'f.`venta_id`' : 'NULL') . " AS venta_id,
            " . (flus_column_exists($pdo, 'facturas', 'cae') ? 'f.`cae`' : 'NULL') . " AS cae,
            {$documentoExpr} AS documento_id,
            {$ncTotalExpr} AS total_nc,
            {$ncCountExpr} AS nc_count,
            {$cobradoExpr} AS cobrado,
            {$recibosCountExpr} AS recibos_count
        FROM facturas f
        {$joinClientes}
        {$joinRecibos}
        WHERE " . implode(' AND ', $where) . "
        GROUP BY f.id
        ORDER BY " . ($fechaCol !== null ? "{$fechaExpr} DESC, f.id DESC" : 'f.id DESC') . "
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $allRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($allRows as $row) {
        $total = round(abs((float)($row['total'] ?? 0)), 2);
        $totalNc = round(min($total, max(0.0, (float)($row['total_nc'] ?? 0))), 2);
        $cobrado = round(max(0.0, (float)($row['cobrado'] ?? 0)), 2);
        $estado = flus_cobranzas_panel_estado_desde_montos($total, $cobrado, $totalNc);
        $row['total'] = $total;
        $row['total_nc'] = $totalNc;
        $row['total_neto'] = round(max(0.0, (float)($estado['neto'] ?? ($total - $totalNc))), 2);
        $row['cobrado'] = $cobrado;
        $row['saldo'] = $estado['saldo'];
        $row['estado_cobro'] = $estado['estado'];
        $row['estado_cobro_label'] = $estado['label'];
        $row['nc_count'] = (int)($row['nc_count'] ?? 0);
        $row['recibos_count'] = (int)($row['recibos_count'] ?? 0);

        $estadoFiltro = $filters['estado_cobro'];
        $matchesEstado = $estadoFiltro === 'TODAS'
            || $estadoFiltro === $row['estado_cobro']
            || ($estadoFiltro === 'PENDIENTE' && in_array($row['estado_cobro'], ['SIN_COBRAR', 'PARCIAL'], true));
        if ($matchesEstado) {
            $stats['total_facturado'] += $total;
            $stats['total_nc'] += $totalNc;
            $stats['total_neto'] += (float)$row['total_neto'];
            $stats['total_cobrado'] += min($cobrado, (float)$row['total_neto']);
            $stats['total_saldo'] += (float)$row['saldo'];
            if ($row['estado_cobro'] === 'COBRADA') {
                $stats['cobradas']++;
            } elseif ($row['estado_cobro'] === 'COMPENSADA') {
                $stats['compensadas']++;
            } elseif ($row['estado_cobro'] === 'PARCIAL') {
                $stats['parciales']++;
                $stats['pendientes']++;
            } else {
                $stats['sin_cobrar']++;
                $stats['pendientes']++;
            }
            $rows[] = $row;
        }
    }

    foreach (['total_facturado', 'total_nc', 'total_neto', 'total_cobrado', 'total_saldo'] as $key) {
        $stats[$key] = round((float)$stats[$key], 2);
    }

    $totalRows = count($rows);
    $perPage = max(1, (int)$filters['per_page']);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($filters['page'] > $totalPages) {
        $filters['page'] = $totalPages;
    }
    $offset = ($filters['page'] - 1) * $perPage;
    $pagedRows = array_slice($rows, $offset, $perPage);

    return [
        'filters' => $filters,
        'avisos' => $avisos,
        'rows' => $pagedRows,
        'stats' => $stats,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages,
        'from_row' => $totalRows > 0 ? $offset + 1 : 0,
        'to_row' => min($offset + $perPage, $totalRows),
        'receipts_ready' => $receiptsReady,
    ];
}

function flus_cobranzas_find_application_by_key(PDO $pdo, string $applicationKey): ?array
{
    $applicationKey = trim($applicationKey);
    if ($applicationKey === '' || !flus_cobranzas_tables_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM cobranza_aplicaciones WHERE application_key = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$applicationKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_find_receipt_application_by_key(PDO $pdo, string $applicationKey): ?array
{
    $applicationKey = trim($applicationKey);
    if ($applicationKey === '' || !flus_cobranzas_receipts_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE application_key = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$applicationKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_create(PDO $pdo, array $payload): int
{
    if (!flus_cobranzas_tables_ready($pdo)) {
        return 0;
    }

    $externalKey = trim((string)($payload['external_key'] ?? ''));
    if ($externalKey !== '') {
        $existing = flus_cobranzas_find_by_external_key($pdo, $externalKey);
        if (is_array($existing)) {
            return (int)($existing['id'] ?? 0);
        }
    }

    $timestamp = date('Y-m-d H:i:s');
    return flus_cobranzas_insert_dynamic($pdo, 'cobranzas', [
        'external_key' => $externalKey !== '' ? $externalKey : null,
        'origen' => strtoupper(trim((string)($payload['origen'] ?? 'GENERAL'))) ?: 'GENERAL',
        'estado' => strtoupper(trim((string)($payload['estado'] ?? 'ACTIVA'))) ?: 'ACTIVA',
        'venta_id' => (int)($payload['venta_id'] ?? 0) > 0 ? (int)$payload['venta_id'] : null,
        'cliente_id' => (int)($payload['cliente_id'] ?? 0) > 0 ? (int)$payload['cliente_id'] : null,
        'cc_movimiento_id' => (int)($payload['cc_movimiento_id'] ?? 0) > 0 ? (int)$payload['cc_movimiento_id'] : null,
        'caja_id' => (int)($payload['caja_id'] ?? 0) > 0 ? (int)$payload['caja_id'] : null,
        'caja_movimiento_id' => (int)($payload['caja_movimiento_id'] ?? 0) > 0 ? (int)$payload['caja_movimiento_id'] : null,
        'medio_pago' => trim((string)($payload['medio_pago'] ?? '')),
        'importe_total' => round((float)($payload['importe_total'] ?? 0), 2),
        'referencia' => trim((string)($payload['referencia'] ?? '')) ?: null,
        'observaciones' => trim((string)($payload['observaciones'] ?? '')) ?: null,
        'created_by' => (int)($payload['created_by'] ?? 0) > 0 ? (int)$payload['created_by'] : null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}

function flus_cobranzas_create_application(PDO $pdo, int $cobranzaId, array $payload): int
{
    if ($cobranzaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return 0;
    }

    $applicationKey = trim((string)($payload['application_key'] ?? ''));
    if ($applicationKey !== '') {
        $existing = flus_cobranzas_find_application_by_key($pdo, $applicationKey);
        if (is_array($existing)) {
            return (int)($existing['id'] ?? 0);
        }
    }

    return flus_cobranzas_insert_dynamic($pdo, 'cobranza_aplicaciones', [
        'cobranza_id' => $cobranzaId,
        'application_key' => $applicationKey !== '' ? $applicationKey : null,
        'tipo_aplicacion' => strtoupper(trim((string)($payload['tipo_aplicacion'] ?? 'GENERAL'))) ?: 'GENERAL',
        'venta_id' => (int)($payload['venta_id'] ?? 0) > 0 ? (int)$payload['venta_id'] : null,
        'documento_id' => (int)($payload['documento_id'] ?? 0) > 0 ? (int)$payload['documento_id'] : null,
        'factura_id' => (int)($payload['factura_id'] ?? 0) > 0 ? (int)$payload['factura_id'] : null,
        'cc_movimiento_id' => (int)($payload['cc_movimiento_id'] ?? 0) > 0 ? (int)$payload['cc_movimiento_id'] : null,
        'monto' => round((float)($payload['monto'] ?? 0), 2),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function flus_cobranzas_receipt_sync_document(PDO $pdo, int $cobranzaId): int
{
    if ($cobranzaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return 0;
    }

    $cobranza = flus_cobranzas_find_by_id($pdo, $cobranzaId);
    if (!is_array($cobranza)) {
        return 0;
    }

    $linkedDocumentoId = (int)($cobranza['recibo_documento_id'] ?? 0);
    if ($linkedDocumentoId > 0) {
        $documento = flus_facturacion_documento_buscar($pdo, $linkedDocumentoId);
        if (is_array($documento) && (int)($documento['id'] ?? 0) === $linkedDocumentoId) {
            return $linkedDocumentoId;
        }
    }

    $requestUid = flus_cobranzas_build_receipt_request_uid($cobranzaId);
    $documento = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
    $timestamp = date('Y-m-d H:i:s');

    if (!is_array($documento)) {
        $nota = trim((string)($cobranza['observaciones'] ?? ''));
        $referencia = trim((string)($cobranza['referencia'] ?? ''));
        if ($nota === '') {
            $nota = 'Recibo de cobranza';
        }
        if ($referencia !== '') {
            $nota .= ' · Ref: ' . $referencia;
        }

        $linkedDocumentoId = flus_cobranzas_insert_dynamic($pdo, 'documentos_comerciales', [
            'request_uid' => $requestUid,
            'tipo_documento' => 'RECIBO',
            'origen' => 'COBRANZA',
            'estado' => 'EMITIDO',
            'cliente_id' => (int)($cobranza['cliente_id'] ?? 0) > 0 ? (int)$cobranza['cliente_id'] : null,
            'venta_id' => (int)($cobranza['venta_id'] ?? 0) > 0 ? (int)$cobranza['venta_id'] : null,
            'nota' => $nota,
            'medio_pago' => trim((string)($cobranza['medio_pago'] ?? '')) ?: null,
            'total' => round((float)($cobranza['importe_total'] ?? 0), 2),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    } else {
        $linkedDocumentoId = (int)($documento['id'] ?? 0);
    }

    if ($linkedDocumentoId > 0) {
        $st = $pdo->prepare('UPDATE cobranzas SET recibo_documento_id = ?, updated_at = ? WHERE id = ?');
        $st->execute([$linkedDocumentoId, $timestamp, $cobranzaId]);
    }

    return $linkedDocumentoId;
}

function flus_cobranzas_receipt_create_application(PDO $pdo, int $reciboDocumentoId, int $cobranzaId, array $payload): int
{
    if ($reciboDocumentoId <= 0 || $cobranzaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return 0;
    }

    $tipoAplicacion = strtoupper(trim((string)($payload['tipo_aplicacion'] ?? 'SALDO_CC'))) ?: 'SALDO_CC';
    $clienteId = (int)($payload['cliente_id'] ?? 0) > 0 ? (int)$payload['cliente_id'] : null;
    $ccMovimientoId = (int)($payload['cc_movimiento_id'] ?? 0) > 0 ? (int)$payload['cc_movimiento_id'] : null;
    $documentoId = (int)($payload['documento_id'] ?? 0) > 0 ? (int)$payload['documento_id'] : null;
    $facturaId = (int)($payload['factura_id'] ?? 0) > 0 ? (int)$payload['factura_id'] : null;
    $monto = round((float)($payload['monto'] ?? 0), 2);

    $existingByCobranza = flus_cobranzas_find_receipt_application_by_cobranza($pdo, $cobranzaId);
    if (is_array($existingByCobranza)) {
        $sameTarget = (int)($existingByCobranza['recibo_documento_id'] ?? 0) === $reciboDocumentoId
            && strtoupper(trim((string)($existingByCobranza['tipo_aplicacion'] ?? ''))) === $tipoAplicacion
            && (int)($existingByCobranza['cliente_id'] ?? 0) === (int)($clienteId ?? 0)
            && (int)($existingByCobranza['cc_movimiento_id'] ?? 0) === (int)($ccMovimientoId ?? 0)
            && (int)($existingByCobranza['documento_id'] ?? 0) === (int)($documentoId ?? 0)
            && (int)($existingByCobranza['factura_id'] ?? 0) === (int)($facturaId ?? 0)
            && flus_cobranzas_float_eq((float)($existingByCobranza['monto'] ?? 0), $monto);

        if ($sameTarget) {
            return (int)($existingByCobranza['id'] ?? 0);
        }

        throw new RuntimeException('La cobranza ya tiene una aplicacion de recibo distinta. Fase 4 no soporta multiples aplicaciones por cobranza.');
    }

    $refId = 0;
    if ($facturaId !== null) {
        $refId = $facturaId;
    } elseif ($documentoId !== null) {
        $refId = $documentoId;
    } elseif ($ccMovimientoId !== null) {
        $refId = $ccMovimientoId;
    } else {
        $refId = $cobranzaId;
    }

    $applicationKey = trim((string)($payload['application_key'] ?? ''));
    if ($applicationKey === '') {
        $applicationKey = flus_cobranzas_build_receipt_application_key($reciboDocumentoId, $tipoAplicacion, $refId);
    }

    $existing = flus_cobranzas_find_receipt_application_by_key($pdo, $applicationKey);
    if (is_array($existing)) {
        if ((int)($existing['cobranza_id'] ?? 0) !== $cobranzaId) {
            throw new RuntimeException('La clave de aplicacion del recibo ya existe para otra cobranza.');
        }
        return (int)($existing['id'] ?? 0);
    }

    return flus_cobranzas_insert_dynamic($pdo, 'recibo_aplicaciones', [
        'recibo_documento_id' => $reciboDocumentoId,
        'cobranza_id' => $cobranzaId,
        'application_key' => $applicationKey,
        'tipo_aplicacion' => $tipoAplicacion,
        'cliente_id' => $clienteId,
        'cc_movimiento_id' => $ccMovimientoId,
        'documento_id' => $documentoId,
        'factura_id' => $facturaId,
        'monto' => $monto,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function flus_cobranzas_resolve_receipt_target(PDO $pdo, int $clienteId, ?int $facturaId = null, ?int $documentoId = null): array
{
    $clienteId = max(0, $clienteId);
    $facturaId = $facturaId !== null ? max(0, $facturaId) : 0;
    $documentoId = $documentoId !== null ? max(0, $documentoId) : 0;

    $documento = null;
    if ($documentoId > 0) {
        if (!flus_facturacion_documentos_table_ready($pdo)) {
            return ['ok' => false, 'error' => 'Las tablas documentales no existen para aplicar el recibo.'];
        }

        $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
        if (!is_array($documento)) {
            return ['ok' => false, 'error' => 'El documento comercial indicado no existe.'];
        }

        if (strtoupper(trim((string)($documento['tipo_documento'] ?? ''))) === 'RECIBO') {
            return ['ok' => false, 'error' => 'No se puede aplicar un recibo sobre otro recibo.'];
        }

        $documentoClienteId = (int)($documento['cliente_id'] ?? 0);
        if ($clienteId > 0 && $documentoClienteId > 0 && $documentoClienteId !== $clienteId) {
            return ['ok' => false, 'error' => 'El documento comercial indicado no pertenece al cliente seleccionado.'];
        }
    }

    if ($facturaId > 0) {
        if (!flus_table_exists($pdo, 'facturas')) {
            return ['ok' => false, 'error' => 'La tabla facturas no existe para aplicar el recibo.'];
        }

        $st = $pdo->prepare('SELECT * FROM facturas WHERE id = ? LIMIT 1');
        $st->execute([$facturaId]);
        $factura = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!is_array($factura)) {
            return ['ok' => false, 'error' => 'La factura indicada no existe.'];
        }

        $facturaClienteId = (int)($factura['cliente_id'] ?? 0);
        if ($clienteId > 0 && $facturaClienteId > 0 && $facturaClienteId !== $clienteId) {
            return ['ok' => false, 'error' => 'La factura indicada no pertenece al cliente seleccionado.'];
        }

        $facturaDocumentoId = (int)($factura['documento_id'] ?? 0);
        if ($documentoId > 0) {
            if ($facturaDocumentoId <= 0) {
                return ['ok' => false, 'error' => 'La factura indicada no esta vinculada al documento comercial recibido.'];
            }
            if ($facturaDocumentoId !== $documentoId) {
                return ['ok' => false, 'error' => 'La factura indicada no coincide con el documento comercial recibido.'];
            }
        }

        if ($facturaDocumentoId > 0 && !is_array($documento)) {
            $documento = flus_facturacion_documento_buscar($pdo, $facturaDocumentoId);
            if (!is_array($documento)) {
                return ['ok' => false, 'error' => 'La factura apunta a un documento comercial inexistente.'];
            }
            if (strtoupper(trim((string)($documento['tipo_documento'] ?? ''))) === 'RECIBO') {
                return ['ok' => false, 'error' => 'La factura apunta a un recibo, lo cual es incoherente para esta aplicacion.'];
            }
            $documentoClienteId = (int)($documento['cliente_id'] ?? 0);
            $clienteValidacion = $facturaClienteId > 0 ? $facturaClienteId : $clienteId;
            if ($clienteValidacion > 0 && $documentoClienteId > 0 && $documentoClienteId !== $clienteValidacion) {
                return ['ok' => false, 'error' => 'La factura y el documento comercial pertenecen a clientes distintos.'];
            }
        }

        return [
            'ok' => true,
            'tipo_aplicacion' => 'FACTURA',
            'cliente_id' => $facturaClienteId > 0 ? $facturaClienteId : ($documento['cliente_id'] ?? ($clienteId > 0 ? $clienteId : null)),
            'factura_id' => $facturaId,
            'documento_id' => $facturaDocumentoId > 0 ? $facturaDocumentoId : null,
        ];
    }

    if ($documentoId > 0) {
        return [
            'ok' => true,
            'tipo_aplicacion' => 'DOCUMENTO',
            'cliente_id' => (int)($documento['cliente_id'] ?? 0) > 0 ? (int)$documento['cliente_id'] : ($clienteId > 0 ? $clienteId : null),
            'factura_id' => null,
            'documento_id' => $documentoId,
        ];
    }

    return [
        'ok' => true,
        'tipo_aplicacion' => 'SALDO_CC',
        'cliente_id' => $clienteId > 0 ? $clienteId : null,
        'factura_id' => null,
        'documento_id' => null,
    ];
}

function flus_cobranzas_attach_receipt_to_cobranza(PDO $pdo, int $cobranzaId, array $payload = []): array
{
    if ($cobranzaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return [
            'cobranza_id' => $cobranzaId,
            'recibo_documento_id' => 0,
            'recibo_aplicacion_id' => 0,
            'tipo_aplicacion' => null,
        ];
    }

    $cobranza = flus_cobranzas_find_by_id($pdo, $cobranzaId);
    if (!is_array($cobranza)) {
        return [
            'cobranza_id' => $cobranzaId,
            'recibo_documento_id' => 0,
            'recibo_aplicacion_id' => 0,
            'tipo_aplicacion' => null,
        ];
    }

    $target = flus_cobranzas_resolve_receipt_target(
        $pdo,
        (int)($payload['cliente_id'] ?? ($cobranza['cliente_id'] ?? 0)),
        isset($payload['factura_id']) ? (int)$payload['factura_id'] : null,
        isset($payload['documento_id']) ? (int)$payload['documento_id'] : null
    );

    if (($target['ok'] ?? false) !== true) {
        throw new RuntimeException((string)($target['error'] ?? 'No se pudo resolver la aplicacion del recibo.'));
    }

    $cobranzaTotal = round((float)($cobranza['importe_total'] ?? 0), 2);
    $montoAplicado = round((float)($payload['monto'] ?? $cobranzaTotal), 2);
    if ($montoAplicado <= 0) {
        throw new RuntimeException('El monto aplicado del recibo debe ser mayor a cero.');
    }
    if ($cobranzaTotal > 0 && $montoAplicado - $cobranzaTotal > 0.009) {
        throw new RuntimeException('El monto aplicado del recibo no puede superar el importe total de la cobranza.');
    }

    $reciboDocumentoId = flus_cobranzas_receipt_sync_document($pdo, $cobranzaId);
    if ($reciboDocumentoId <= 0) {
        return [
            'cobranza_id' => $cobranzaId,
            'recibo_documento_id' => 0,
            'recibo_aplicacion_id' => 0,
            'tipo_aplicacion' => null,
        ];
    }

    $reciboAplicacionId = flus_cobranzas_receipt_create_application($pdo, $reciboDocumentoId, $cobranzaId, [
        'tipo_aplicacion' => (string)($target['tipo_aplicacion'] ?? 'SALDO_CC'),
        'cliente_id' => (int)($target['cliente_id'] ?? ($cobranza['cliente_id'] ?? 0)) > 0 ? (int)($target['cliente_id'] ?? ($cobranza['cliente_id'] ?? 0)) : null,
        'cc_movimiento_id' => (int)($payload['cc_movimiento_id'] ?? ($cobranza['cc_movimiento_id'] ?? 0)) > 0 ? (int)($payload['cc_movimiento_id'] ?? ($cobranza['cc_movimiento_id'] ?? 0)) : null,
        'documento_id' => (int)($target['documento_id'] ?? 0) > 0 ? (int)$target['documento_id'] : null,
        'factura_id' => (int)($target['factura_id'] ?? 0) > 0 ? (int)$target['factura_id'] : null,
        'monto' => $montoAplicado,
    ]);

    return [
        'cobranza_id' => $cobranzaId,
        'recibo_documento_id' => $reciboDocumentoId,
        'recibo_aplicacion_id' => $reciboAplicacionId,
        'tipo_aplicacion' => (string)($target['tipo_aplicacion'] ?? 'SALDO_CC'),
        'factura_id' => (int)($target['factura_id'] ?? 0) > 0 ? (int)$target['factura_id'] : null,
        'documento_id' => (int)($target['documento_id'] ?? 0) > 0 ? (int)$target['documento_id'] : null,
        'monto_aplicado' => $montoAplicado,
    ];
}

function flus_cobranzas_register_sale_payment(PDO $pdo, array $payload): int
{
    if (!flus_cobranzas_tables_ready($pdo)) {
        return 0;
    }

    $ventaId = (int)($payload['venta_id'] ?? 0);
    $linea = max(1, (int)($payload['linea'] ?? 1));
    $medioPago = trim((string)($payload['medio_pago'] ?? ''));
    $monto = round((float)($payload['monto'] ?? 0), 2);

    if ($ventaId <= 0 || $medioPago === '' || $monto <= 0) {
        return 0;
    }

    $externalKey = trim((string)($payload['external_key'] ?? ''));
    if ($externalKey === '') {
        $externalKey = flus_cobranzas_build_sale_external_key($ventaId, $linea, $medioPago, $monto);
    }

    $cobranzaId = flus_cobranzas_create($pdo, [
        'external_key' => $externalKey,
        'origen' => 'VENTA',
        'estado' => 'ACTIVA',
        'venta_id' => $ventaId,
        'cliente_id' => (int)($payload['cliente_id'] ?? 0) > 0 ? (int)$payload['cliente_id'] : null,
        'caja_id' => (int)($payload['caja_id'] ?? 0) > 0 ? (int)$payload['caja_id'] : null,
        'medio_pago' => strtoupper($medioPago),
        'importe_total' => $monto,
        'referencia' => trim((string)($payload['referencia'] ?? '')) ?: null,
        'observaciones' => trim((string)($payload['observaciones'] ?? '')) ?: null,
        'created_by' => (int)($payload['created_by'] ?? 0) > 0 ? (int)$payload['created_by'] : null,
    ]);

    if ($cobranzaId <= 0) {
        return 0;
    }

    flus_cobranzas_create_application($pdo, $cobranzaId, [
        'application_key' => flus_cobranzas_build_application_key($cobranzaId, 'VENTA', $ventaId),
        'tipo_aplicacion' => 'VENTA',
        'venta_id' => $ventaId,
        'documento_id' => (int)($payload['documento_id'] ?? 0) > 0 ? (int)$payload['documento_id'] : null,
        'factura_id' => (int)($payload['factura_id'] ?? 0) > 0 ? (int)$payload['factura_id'] : null,
        'monto' => $monto,
    ]);

    return $cobranzaId;
}

function flus_cobranzas_caja_total_column(string $medioPago): ?string
{
    return match (strtoupper(trim($medioPago))) {
        'EFECTIVO' => 'total_efectivo',
        'MP', 'MERCADOPAGO', 'MODO', 'QR' => 'total_mp',
        'DEBITO' => 'total_debito',
        'CREDITO' => 'total_credito',
        'TRANSFERENCIA', 'TRANSFER' => 'total_transferencia',
        default => null,
    };
}

function flus_cobranzas_register_invoice_caja_movimiento(PDO $pdo, array $payload): ?int
{
    $cajaId = (int)($payload['caja_id'] ?? 0);
    $monto = round((float)($payload['monto'] ?? 0), 2);
    $medioPago = strtoupper(trim((string)($payload['medio_pago'] ?? '')));
    if ($cajaId <= 0 || $monto <= 0 || $medioPago === '' || !flus_table_exists($pdo, 'caja_movimientos')) {
        return null;
    }

    $facturaId = max(0, (int)($payload['factura_id'] ?? 0));
    $clienteNombre = trim((string)($payload['cliente_nombre'] ?? ''));
    $referencia = trim((string)($payload['referencia'] ?? ''));
    $usuarioNombre = trim((string)($payload['usuario_nombre'] ?? ''));
    if ($usuarioNombre === '') {
        $usuarioId = (int)($payload['created_by'] ?? 0);
        $usuarioNombre = $usuarioId > 0 ? ('user#' . $usuarioId) : null;
    }

    $concepto = 'Cobro factura';
    if ($facturaId > 0) {
        $concepto .= ' #' . $facturaId;
    }
    if ($clienteNombre !== '') {
        $concepto .= ' - ' . mb_substr($clienteNombre, 0, 80);
    }
    if ($referencia !== '') {
        $concepto .= ' Ref: ' . mb_substr($referencia, 0, 40);
    }

    $cajaMovId = flus_cobranzas_insert_dynamic($pdo, 'caja_movimientos', [
        'caja_id' => $cajaId,
        'tipo' => 'ingreso',
        'medio_pago' => $medioPago,
        'concepto' => mb_substr($concepto, 0, 255),
        'monto' => $monto,
        'usuario_registro' => $usuarioNombre !== null ? mb_substr($usuarioNombre, 0, 100) : null,
    ]);

    $totalColumn = flus_cobranzas_caja_total_column($medioPago);
    if ($cajaMovId > 0 && $totalColumn !== null && flus_column_exists($pdo, 'caja_sesiones', $totalColumn)) {
        $stTotal = $pdo->prepare("UPDATE caja_sesiones SET {$totalColumn} = COALESCE({$totalColumn}, 0) + ? WHERE id = ?");
        $stTotal->execute([$monto, $cajaId]);
    }

    return $cajaMovId > 0 ? $cajaMovId : null;
}

function flus_cobranzas_register_invoice_payment(PDO $pdo, array $payload): array
{
    if (!flus_cobranzas_receipts_ready($pdo)) {
        return ['success' => false, 'error' => 'Las tablas de cobranzas y recibos no estan listas.'];
    }

    $facturaId = (int)($payload['factura_id'] ?? 0);
    $monto = round((float)($payload['monto'] ?? 0), 2);
    $medioPago = strtoupper(trim((string)($payload['medio_pago'] ?? '')));
    $requestUid = trim((string)($payload['request_uid'] ?? ''));
    $externalKey = trim((string)($payload['external_key'] ?? ''));

    if ($facturaId <= 0) {
        return ['success' => false, 'error' => 'Factura invalida.'];
    }
    if ($monto <= 0) {
        return ['success' => false, 'error' => 'El monto debe ser mayor a cero.'];
    }
    if ($medioPago === '') {
        return ['success' => false, 'error' => 'Medio de pago requerido.'];
    }
    if ($externalKey === '') {
        $externalKey = flus_cobranzas_build_invoice_external_key($facturaId, $requestUid);
    }

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stFactura = $pdo->prepare('SELECT * FROM facturas WHERE id = ? LIMIT 1');
        $stFactura->execute([$facturaId]);
        $factura = $stFactura->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!is_array($factura)) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Factura no encontrada.'];
        }

        $facturaClienteId = (int)($factura['cliente_id'] ?? 0);
        $clienteId = (int)($payload['cliente_id'] ?? 0);
        if ($clienteId > 0 && $facturaClienteId > 0 && $clienteId !== $facturaClienteId) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'La factura no pertenece al cliente indicado.'];
        }
        $clienteId = $facturaClienteId > 0 ? $facturaClienteId : ($clienteId > 0 ? $clienteId : null);
        $documentoId = (int)($factura['documento_id'] ?? 0);
        $ventaId = (int)($factura['venta_id'] ?? 0);

        if (!flus_cobranzas_factura_es_cobrable($factura)) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Las notas de credito no se cobran desde esta accion.'];
        }

        $existing = flus_cobranzas_find_by_external_key($pdo, $externalKey);
        if (is_array($existing)) {
            $cobranzaId = (int)($existing['id'] ?? 0);
            $importeExistente = round((float)($existing['importe_total'] ?? 0), 2);
            $medioExistente = strtoupper(trim((string)($existing['medio_pago'] ?? '')));
            if (!flus_cobranzas_float_eq($importeExistente, $monto)) {
                throw new RuntimeException('Ese identificador de cobro ya fue usado con otro importe.');
            }
            if ($medioExistente !== '' && $medioExistente !== $medioPago) {
                throw new RuntimeException('Ese identificador de cobro ya fue usado con otro medio de pago.');
            }

            flus_cobranzas_create_application($pdo, $cobranzaId, [
                'application_key' => flus_cobranzas_build_application_key($cobranzaId, 'FACTURA', $facturaId),
                'tipo_aplicacion' => 'FACTURA',
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'documento_id' => $documentoId > 0 ? $documentoId : null,
                'factura_id' => $facturaId,
                'monto' => $monto,
            ]);
            $reciboData = flus_cobranzas_attach_receipt_to_cobranza($pdo, $cobranzaId, [
                'cliente_id' => $clienteId,
                'factura_id' => $facturaId,
                'documento_id' => $documentoId > 0 ? $documentoId : null,
                'monto' => $monto,
            ]);
            $resumen = flus_cobranzas_resumen_para_factura($pdo, $factura);
            if ($ownTransaction) {
                $pdo->commit();
            }
            return array_merge(['success' => true, 'reused' => true, 'cobranza_id' => $cobranzaId], $reciboData, ['resumen' => $resumen]);
        }

        $resumen = flus_cobranzas_resumen_para_factura($pdo, $factura);
        $saldo = round((float)($resumen['saldo'] ?? 0), 2);
        if ($saldo <= 0.009) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'La factura ya figura como cobrada.'];
        }
        if ($monto - $saldo > 0.009) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'El monto no puede superar el saldo pendiente.'];
        }

        $cajaMovId = null;
        if (!empty($payload['registrar_caja_mov'])) {
            $cajaMovId = flus_cobranzas_register_invoice_caja_movimiento($pdo, array_merge($payload, [
                'factura_id' => $facturaId,
                'cliente_nombre' => trim((string)($factura['cliente_nombre'] ?? $factura['razon_social'] ?? '')),
                'monto' => $monto,
                'medio_pago' => $medioPago,
            ]));
        }

        $cobranzaId = flus_cobranzas_create($pdo, [
            'external_key' => $externalKey,
            'origen' => 'FACTURA',
            'estado' => 'ACTIVA',
            'venta_id' => $ventaId > 0 ? $ventaId : null,
            'cliente_id' => $clienteId,
            'caja_id' => (int)($payload['caja_id'] ?? 0) > 0 ? (int)$payload['caja_id'] : null,
            'caja_movimiento_id' => $cajaMovId,
            'medio_pago' => $medioPago,
            'importe_total' => $monto,
            'referencia' => trim((string)($payload['referencia'] ?? '')) ?: null,
            'observaciones' => trim((string)($payload['observaciones'] ?? '')) ?: 'Cobro de factura',
            'created_by' => (int)($payload['created_by'] ?? 0) > 0 ? (int)$payload['created_by'] : null,
        ]);

        flus_cobranzas_create_application($pdo, $cobranzaId, [
            'application_key' => flus_cobranzas_build_application_key($cobranzaId, 'FACTURA', $facturaId),
            'tipo_aplicacion' => 'FACTURA',
            'venta_id' => $ventaId > 0 ? $ventaId : null,
            'documento_id' => $documentoId > 0 ? $documentoId : null,
            'factura_id' => $facturaId,
            'monto' => $monto,
        ]);

        $reciboData = flus_cobranzas_attach_receipt_to_cobranza($pdo, $cobranzaId, [
            'cliente_id' => $clienteId,
            'factura_id' => $facturaId,
            'documento_id' => $documentoId > 0 ? $documentoId : null,
            'monto' => $monto,
        ]);

        $resumen = flus_cobranzas_resumen_para_factura($pdo, $factura);
        if ($ownTransaction) {
            $pdo->commit();
        }

        return array_merge([
            'success' => true,
            'reused' => false,
            'cobranza_id' => $cobranzaId,
            'caja_movimiento_id' => $cajaMovId,
        ], $reciboData, ['resumen' => $resumen]);
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('flus_cobranzas_register_invoice_payment ERROR: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function flus_cobranzas_register_cc_payment(PDO $pdo, array $payload): int
{
    if (!flus_cobranzas_tables_ready($pdo)) {
        return 0;
    }

    $ccMovimientoId = (int)($payload['cc_movimiento_id'] ?? 0);
    $clienteId = (int)($payload['cliente_id'] ?? 0);
    $medioPago = trim((string)($payload['medio_pago'] ?? ''));
    $monto = round((float)($payload['monto'] ?? 0), 2);

    if ($ccMovimientoId <= 0 || $clienteId <= 0 || $medioPago === '' || $monto <= 0) {
        return 0;
    }

    $externalKey = trim((string)($payload['external_key'] ?? ''));
    if ($externalKey === '') {
        $externalKey = flus_cobranzas_build_cc_external_key($ccMovimientoId);
    }

    $cobranzaId = flus_cobranzas_create($pdo, [
        'external_key' => $externalKey,
        'origen' => 'CC_PAGO',
        'estado' => 'ACTIVA',
        'cliente_id' => $clienteId,
        'cc_movimiento_id' => $ccMovimientoId,
        'caja_id' => (int)($payload['caja_id'] ?? 0) > 0 ? (int)$payload['caja_id'] : null,
        'caja_movimiento_id' => (int)($payload['caja_movimiento_id'] ?? 0) > 0 ? (int)$payload['caja_movimiento_id'] : null,
        'medio_pago' => strtoupper($medioPago),
        'importe_total' => $monto,
        'referencia' => trim((string)($payload['referencia'] ?? '')) ?: null,
        'observaciones' => trim((string)($payload['observaciones'] ?? '')) ?: null,
        'created_by' => (int)($payload['created_by'] ?? 0) > 0 ? (int)$payload['created_by'] : null,
    ]);

    if ($cobranzaId <= 0) {
        return 0;
    }

    flus_cobranzas_create_application($pdo, $cobranzaId, [
        'application_key' => flus_cobranzas_build_application_key($cobranzaId, 'CC_MOVIMIENTO', $ccMovimientoId),
        'tipo_aplicacion' => 'CC_MOVIMIENTO',
        'cc_movimiento_id' => $ccMovimientoId,
        'monto' => $monto,
    ]);

    return $cobranzaId;
}

function flus_cobranzas_fetch_applications_by_venta(PDO $pdo, int $ventaId): array
{
    if ($ventaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return [];
    }

    $st = $pdo->prepare('SELECT * FROM cobranza_aplicaciones WHERE venta_id = ? ORDER BY id ASC');
    $st->execute([$ventaId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function flus_cobranzas_link_factura_from_sale(PDO $pdo, int $ventaId, int $facturaId, ?int $documentoId = null): void
{
    if ($ventaId <= 0 || $facturaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return;
    }

    foreach (flus_cobranzas_fetch_applications_by_venta($pdo, $ventaId) as $row) {
        $applicationId = (int)($row['id'] ?? 0);
        if ($applicationId <= 0) {
            continue;
        }

        $currentFacturaId = (int)($row['factura_id'] ?? 0);
        if ($currentFacturaId > 0 && $currentFacturaId !== $facturaId) {
            continue;
        }

        $set = ['factura_id = ?'];
        $params = [$facturaId];
        if ($documentoId !== null && $documentoId > 0) {
            $set[] = 'documento_id = ?';
            $params[] = $documentoId;
        }
        $params[] = $applicationId;

        $st = $pdo->prepare('UPDATE cobranza_aplicaciones SET ' . implode(', ', $set) . ' WHERE id = ?');
        $st->execute($params);
    }
}

function flus_cobranzas_link_receipt_factura_from_documento(PDO $pdo, int $documentoId, int $facturaId): void
{
    if ($documentoId <= 0 || $facturaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return;
    }

    $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE documento_id = ? ORDER BY id ASC');
    $st->execute([$documentoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $applicationId = (int)($row['id'] ?? 0);
        if ($applicationId <= 0) {
            continue;
        }

        $currentFacturaId = (int)($row['factura_id'] ?? 0);
        if ($currentFacturaId > 0 && $currentFacturaId !== $facturaId) {
            continue;
        }

        $stUpd = $pdo->prepare('UPDATE recibo_aplicaciones SET factura_id = ? WHERE id = ?');
        $stUpd->execute([$facturaId, $applicationId]);
    }
}

function flus_cobranzas_fetch_by_factura(PDO $pdo, int $facturaId): array
{
    if ($facturaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return [];
    }

    $st = $pdo->prepare('SELECT * FROM cobranza_aplicaciones WHERE factura_id = ? ORDER BY id ASC');
    $st->execute([$facturaId]);
    $apps = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($apps) || $apps === []) {
        return [];
    }

    $rows = [];
    foreach ($apps as $app) {
        $cobranzaId = (int)($app['cobranza_id'] ?? 0);
        if ($cobranzaId <= 0) {
            continue;
        }
        $stCob = $pdo->prepare('SELECT * FROM cobranzas WHERE id = ? LIMIT 1');
        $stCob->execute([$cobranzaId]);
        $cobranza = $stCob->fetch(PDO::FETCH_ASSOC);
        if (!is_array($cobranza)) {
            continue;
        }
        $rows[] = [
            'cobranza_id' => $cobranzaId,
            'origen' => (string)($cobranza['origen'] ?? ''),
            'medio_pago' => (string)($cobranza['medio_pago'] ?? ''),
            'importe_total' => round((float)($cobranza['importe_total'] ?? 0), 2),
            'monto_aplicado' => round((float)($app['monto'] ?? 0), 2),
            'venta_id' => (int)($app['venta_id'] ?? 0),
            'documento_id' => (int)($app['documento_id'] ?? 0),
            'factura_id' => (int)($app['factura_id'] ?? 0),
            'cc_movimiento_id' => (int)($app['cc_movimiento_id'] ?? 0),
        ];
    }

    return $rows;
}

function flus_cobranzas_fetch_receipts_by_factura(PDO $pdo, int $facturaId, ?int $documentoId = null): array
{
    if (!flus_cobranzas_receipts_ready($pdo)) {
        return [];
    }

    $apps = [];
    $seen = [];

    if ($facturaId > 0) {
        $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE factura_id = ? ORDER BY id ASC');
        $st->execute([$facturaId]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $appId = (int)($row['id'] ?? 0);
            if ($appId <= 0 || isset($seen[$appId])) {
                continue;
            }
            $seen[$appId] = true;
            $apps[] = $row;
        }
    }

    if ($documentoId !== null && $documentoId > 0) {
        $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE documento_id = ? ORDER BY id ASC');
        $st->execute([$documentoId]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $appId = (int)($row['id'] ?? 0);
            if ($appId <= 0 || isset($seen[$appId])) {
                continue;
            }
            $seen[$appId] = true;
            $apps[] = $row;
        }
    }

    if ($apps === []) {
        return [];
    }

    $rows = [];
    foreach ($apps as $app) {
        $cobranzaId = (int)($app['cobranza_id'] ?? 0);
        $reciboDocumentoId = (int)($app['recibo_documento_id'] ?? 0);
        if ($cobranzaId <= 0 || $reciboDocumentoId <= 0) {
            continue;
        }

        $cobranza = flus_cobranzas_find_by_id($pdo, $cobranzaId);
        $recibo = flus_facturacion_documento_buscar($pdo, $reciboDocumentoId);
        if (!is_array($cobranza) || !is_array($recibo)) {
            continue;
        }

        $rows[] = [
            'recibo_aplicacion_id' => (int)($app['id'] ?? 0),
            'recibo_documento_id' => $reciboDocumentoId,
            'cobranza_id' => $cobranzaId,
            'cliente_id' => (int)($app['cliente_id'] ?? ($cobranza['cliente_id'] ?? 0)),
            'tipo_aplicacion' => (string)($app['tipo_aplicacion'] ?? ''),
            'monto_aplicado' => round((float)($app['monto'] ?? 0), 2),
            'factura_id' => (int)($app['factura_id'] ?? 0),
            'documento_id' => (int)($app['documento_id'] ?? 0),
            'cc_movimiento_id' => (int)($app['cc_movimiento_id'] ?? 0),
            'caja_id' => (int)($cobranza['caja_id'] ?? 0),
            'caja_movimiento_id' => (int)($cobranza['caja_movimiento_id'] ?? 0),
            'medio_pago' => (string)($cobranza['medio_pago'] ?? ''),
            'importe_total' => round((float)($cobranza['importe_total'] ?? 0), 2),
            'recibo_total' => round((float)($recibo['total'] ?? 0), 2),
            'cobranza_estado' => (string)($cobranza['estado'] ?? ''),
            'recibo_estado' => (string)($recibo['estado'] ?? ''),
            'referencia' => (string)($cobranza['referencia'] ?? ''),
            'observaciones' => (string)($cobranza['observaciones'] ?? ''),
            'created_at' => (string)($recibo['created_at'] ?? ($cobranza['created_at'] ?? '')),
            'nota' => (string)($recibo['nota'] ?? ''),
        ];
    }

    usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));

    return $rows;
}
