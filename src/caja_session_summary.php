<?php
declare(strict_types=1);

require_once __DIR__ . '/venta_anulaciones_lib.php';

if (!function_exists('flus_caja_sesion_medios_sum')) {
    function flus_caja_sesion_medios_sum(array $sesion): float
    {
        return (float)($sesion['total_efectivo'] ?? 0)
            + (float)($sesion['total_mp'] ?? 0)
            + (float)($sesion['total_debito'] ?? 0)
            + (float)($sesion['total_credito'] ?? 0)
            + (float)($sesion['total_transferencia'] ?? 0);
    }
}

if (!function_exists('flus_caja_sesion_ventas_cc_total')) {
    function flus_caja_sesion_ventas_cc_total(PDO $pdo, int $cajaId): float
    {
        if (
            $cajaId <= 0
            || !flus_table_exists($pdo, 'ventas')
            || !flus_column_exists($pdo, 'ventas', 'monto_cc')
        ) {
            return 0.0;
        }

        $anulacionesJoin = flus_venta_anulaciones_totales_join_sql($pdo, 'v', 'vaa');
        $montoAnuladoExpr = $anulacionesJoin !== '' ? 'COALESCE(vaa.monto_anulado_total,0)' : '0';
        $montoCcExpr = flus_venta_cc_vigente_expr_sql(
            'COALESCE(v.monto_cc,0)',
            'COALESCE(v.total,0)',
            $montoAnuladoExpr
        );

        $st = $pdo->prepare("
            SELECT COALESCE(SUM({$montoCcExpr}),0)
            FROM ventas v
            {$anulacionesJoin}
            WHERE v.caja_id = ?
              AND (v.estado IS NULL OR UPPER(v.estado) <> 'ANULADA')
        ");
        $st->execute([$cajaId]);

        return (float)($st->fetchColumn() ?: 0);
    }
}

if (!function_exists('flus_caja_sesion_cobros_cc_total')) {
    function flus_caja_sesion_cobros_cc_total(PDO $pdo, int $cajaId): float
    {
        if (
            $cajaId <= 0
            || !flus_table_exists($pdo, 'caja_movimientos')
            || !flus_column_exists($pdo, 'caja_movimientos', 'cc_movimiento_id')
        ) {
            return 0.0;
        }

        $st = $pdo->prepare("
            SELECT COALESCE(SUM(CASE
                WHEN UPPER(tipo) = 'INGRESO' THEN monto
                WHEN UPPER(tipo) = 'EGRESO' THEN -monto
                ELSE 0
            END),0)
            FROM caja_movimientos
            WHERE caja_id = ?
              AND COALESCE(cc_movimiento_id,0) > 0
        ");
        $st->execute([$cajaId]);

        return (float)($st->fetchColumn() ?: 0);
    }
}

if (!function_exists('flus_caja_sesion_medios_resumen')) {
    function flus_caja_sesion_medios_resumen(PDO $pdo, int $cajaId, array $sesion): array
    {
        $totalVentas = (float)($sesion['total_ventas'] ?? 0);
        $ventasCc = flus_caja_sesion_ventas_cc_total($pdo, $cajaId);
        $cobrosCc = flus_caja_sesion_cobros_cc_total($pdo, $cajaId);
        $sumaMedios = flus_caja_sesion_medios_sum($sesion);
        $baseMedios = $totalVentas - $ventasCc + $cobrosCc;

        return [
            'total_ventas' => $totalVentas,
            'ventas_cc' => $ventasCc,
            'cobros_cc' => $cobrosCc,
            'base_medios' => $baseMedios,
            'suma_medios' => $sumaMedios,
            'diff_medios' => $baseMedios - $sumaMedios,
        ];
    }
}

if (!function_exists('flus_caja_sesion_pago_label')) {
    function flus_caja_sesion_pago_label(array $venta): string
    {
        $label = strtoupper(trim((string)($venta['pagos_label'] ?? '')));
        if ($label !== '') {
            return $label;
        }

        $medio = strtoupper(trim((string)($venta['medio_pago'] ?? '')));
        return $medio !== '' ? $medio : 'SIN ESPECIFICAR';
    }
}
