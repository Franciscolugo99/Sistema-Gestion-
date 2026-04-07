<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

if (!function_exists('flus_venta_anulaciones_habilitadas')) {
    function flus_venta_anulaciones_habilitadas(PDO $pdo): bool
    {
        return flus_table_exists($pdo, 'venta_anulaciones') && flus_table_exists($pdo, 'venta_anulacion_items');
    }
}

if (!function_exists('flus_venta_anulaciones_confirmadas_where_sql')) {
    function flus_venta_anulaciones_confirmadas_where_sql(PDO $pdo, string $alias = 'va'): string
    {
        $where = ['1=1'];

        if (flus_column_exists($pdo, 'venta_anulaciones', 'estado')) {
            $permitidos = ["{$alias}.estado = 'CONFIRMADA'"];

            if (
                flus_column_exists($pdo, 'venta_anulaciones', 'requiere_nc')
                && flus_column_exists($pdo, 'venta_anulaciones', 'estado_fiscal')
            ) {
                $permitidos[] = "(COALESCE({$alias}.requiere_nc, 0) = 1 AND COALESCE({$alias}.estado_fiscal, 'NO_APLICA') IN ('APROBADA_PENDIENTE_APLICACION', 'APLICADA', 'ERROR_POST_ARCA'))";
            }

            $where[] = '(' . implode(' OR ', $permitidos) . ')';
        }

        return implode(' AND ', $where);
    }
}

if (!function_exists('flus_venta_anulaciones_totales_join_sql')) {
    function flus_venta_anulaciones_totales_join_sql(PDO $pdo, string $ventaAlias = 'v', string $joinAlias = 'vaa'): string
    {
        if (!flus_table_exists($pdo, 'venta_anulaciones')) {
            return '';
        }

        $where = flus_venta_anulaciones_confirmadas_where_sql($pdo, 'va');

        return "LEFT JOIN (
            SELECT va.venta_id,
                   COALESCE(SUM(va.monto_total), 0) AS monto_anulado_total,
                   COUNT(*) AS anulaciones_count,
                   MAX(va.anulado_en) AS ultima_anulacion_en
            FROM venta_anulaciones va
            WHERE {$where}
            GROUP BY va.venta_id
        ) {$joinAlias} ON {$joinAlias}.venta_id = {$ventaAlias}.id";
    }
}

if (!function_exists('flus_venta_importe_vigente_expr_sql')) {
    function flus_venta_importe_vigente_expr_sql(string $ventaTotalExpr = 'v.total', string $montoAnuladoExpr = 'COALESCE(vaa.monto_anulado_total, 0)'): string
    {
        return "GREATEST(({$ventaTotalExpr}) - ({$montoAnuladoExpr}), 0)";
    }
}

if (!function_exists('flus_venta_ratio_vigente_expr_sql')) {
    function flus_venta_ratio_vigente_expr_sql(string $ventaTotalExpr = 'v.total', string $montoAnuladoExpr = 'COALESCE(vaa.monto_anulado_total, 0)'): string
    {
        $importeExpr = flus_venta_importe_vigente_expr_sql($ventaTotalExpr, $montoAnuladoExpr);

        return "(CASE WHEN COALESCE(({$ventaTotalExpr}), 0) > 0 THEN {$importeExpr} / ({$ventaTotalExpr}) ELSE 0 END)";
    }
}

if (!function_exists('flus_venta_cc_vigente_expr_sql')) {
    function flus_venta_cc_vigente_expr_sql(string $montoCcExpr = 'COALESCE(v.monto_cc, 0)', string $ventaTotalExpr = 'v.total', string $montoAnuladoExpr = 'COALESCE(vaa.monto_anulado_total, 0)'): string
    {
        $ratioExpr = flus_venta_ratio_vigente_expr_sql($ventaTotalExpr, $montoAnuladoExpr);

        return "GREATEST(({$montoCcExpr}) * {$ratioExpr}, 0)";
    }
}

if (!function_exists('flus_venta_items_anulados_join_sql')) {
    function flus_venta_items_anulados_join_sql(PDO $pdo, string $ventaItemAlias = 'vi', string $joinAlias = 'vaix'): string
    {
        if (!flus_venta_anulaciones_habilitadas($pdo)) {
            return '';
        }

        $where = flus_venta_anulaciones_confirmadas_where_sql($pdo, 'va');

        return "LEFT JOIN (
            SELECT vai.venta_item_id,
                   COALESCE(SUM(vai.cantidad_anulada), 0) AS cantidad_anulada_total
            FROM venta_anulacion_items vai
            JOIN venta_anulaciones va ON va.id = vai.anulacion_id
            WHERE {$where}
            GROUP BY vai.venta_item_id
        ) {$joinAlias} ON {$joinAlias}.venta_item_id = {$ventaItemAlias}.id";
    }
}

if (!function_exists('flus_venta_cantidad_vigente_expr_sql')) {
    function flus_venta_cantidad_vigente_expr_sql(string $cantidadExpr = 'vi.cantidad', string $cantidadAnuladaExpr = 'COALESCE(vaix.cantidad_anulada_total, 0)'): string
    {
        return "GREATEST(({$cantidadExpr}) - ({$cantidadAnuladaExpr}), 0)";
    }
}

if (!function_exists('flus_venta_item_ratio_vigente_expr_sql')) {
    function flus_venta_item_ratio_vigente_expr_sql(string $cantidadExpr = 'vi.cantidad', string $cantidadAnuladaExpr = 'COALESCE(vaix.cantidad_anulada_total, 0)'): string
    {
        $cantidadVigenteExpr = flus_venta_cantidad_vigente_expr_sql($cantidadExpr, $cantidadAnuladaExpr);

        return "(CASE WHEN COALESCE(({$cantidadExpr}), 0) > 0 THEN {$cantidadVigenteExpr} / ({$cantidadExpr}) ELSE 0 END)";
    }
}

if (!function_exists('flus_venta_item_subtotal_vigente_expr_sql')) {
    function flus_venta_item_subtotal_vigente_expr_sql(string $subtotalExpr = 'vi.subtotal', string $cantidadExpr = 'vi.cantidad', string $cantidadAnuladaExpr = 'COALESCE(vaix.cantidad_anulada_total, 0)'): string
    {
        $ratioExpr = flus_venta_item_ratio_vigente_expr_sql($cantidadExpr, $cantidadAnuladaExpr);

        return "({$subtotalExpr}) * {$ratioExpr}";
    }
}

if (!function_exists('flus_venta_items_cargar')) {
    function flus_venta_items_cargar(PDO $pdo, int $ventaId): array
    {
        if ($ventaId <= 0 || !flus_table_exists($pdo, 'venta_items')) {
            return [];
        }

        $st = $pdo->prepare('SELECT * FROM venta_items WHERE venta_id = ? ORDER BY id ASC');
        $st->execute([$ventaId]);

        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[(int)$row['id']] = $row;
        }

        return $items;
    }
}

if (!function_exists('flus_venta_items_anulados_map')) {
    function flus_venta_items_anulados_map(PDO $pdo, int $ventaId): array
    {
        if ($ventaId <= 0 || !flus_venta_anulaciones_habilitadas($pdo)) {
            return [];
        }

        $where = ['va.venta_id = ?'];

        if (flus_column_exists($pdo, 'venta_anulaciones', 'estado')) {
            $permitidos = ["va.estado = 'CONFIRMADA'"];

            if (
                flus_column_exists($pdo, 'venta_anulaciones', 'requiere_nc')
                && flus_column_exists($pdo, 'venta_anulaciones', 'estado_fiscal')
            ) {
                $permitidos[] = "(COALESCE(va.requiere_nc, 0) = 1 AND COALESCE(va.estado_fiscal, 'NO_APLICA') IN ('APROBADA_PENDIENTE_APLICACION', 'APLICADA', 'ERROR_POST_ARCA'))";
            }

            $where[] = '(' . implode(' OR ', $permitidos) . ')';
        }

        $sql = '
            SELECT vai.venta_item_id, SUM(vai.cantidad_anulada) AS total_anulado
            FROM venta_anulacion_items vai
            JOIN venta_anulaciones va ON va.id = vai.anulacion_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY vai.venta_item_id'
        ;

        $st = $pdo->prepare($sql);
        $st->execute([$ventaId]);

        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['venta_item_id']] = (float)$row['total_anulado'];
        }

        return $map;
    }
}

if (!function_exists('flus_venta_items_restantes')) {
    function flus_venta_items_restantes(array $ventaItems, array $yaAnulado): array
    {
        $restantes = [];

        foreach ($ventaItems as $itemId => $item) {
            $cantidadOriginal = (float)($item['cantidad'] ?? 0);
            $cantidadAnulada = (float)($yaAnulado[(int)$itemId] ?? 0);
            $cantidadRestante = round($cantidadOriginal - $cantidadAnulada, 3);

            if ($cantidadRestante <= 0.0009) {
                continue;
            }

            $restantes[(int)$itemId] = [
                'item' => $item,
                'cantidad_restante' => $cantidadRestante,
            ];
        }

        return $restantes;
    }
}

if (!function_exists('flus_venta_anulacion_items_cargar')) {
    function flus_venta_anulacion_items_cargar(PDO $pdo, int $anulacionId): array
    {
        if ($anulacionId <= 0 || !flus_table_exists($pdo, 'venta_anulacion_items')) {
            return [];
        }

        $st = $pdo->prepare('SELECT * FROM venta_anulacion_items WHERE anulacion_id = ? ORDER BY id ASC');
        $st->execute([$anulacionId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('flus_venta_anulaciones_historial')) {
    function flus_venta_anulaciones_historial(PDO $pdo, int $ventaId): array
    {
        if ($ventaId <= 0 || !flus_venta_anulaciones_habilitadas($pdo)) {
            return [];
        }

        $st = $pdo->prepare(
            'SELECT va.*, u.username AS anulado_por_username
             FROM venta_anulaciones va
             LEFT JOIN users u ON u.id = va.anulado_por
             WHERE va.venta_id = ?
             ORDER BY va.anulado_en DESC, va.id DESC'
        );
        $st->execute([$ventaId]);

        $historial = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $anulacionId = (int)($row['id'] ?? 0);
            if ($anulacionId <= 0) {
                continue;
            }

            $row['items'] = [];
            $row['cantidad_total_anulada'] = 0.0;
            $row['lineas_afectadas'] = 0;
            $historial[$anulacionId] = $row;
        }

        if ($historial === []) {
            return [];
        }

        $ids = array_keys($historial);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stItems = $pdo->prepare(
            "SELECT vai.*,
                    p.codigo AS producto_codigo,
                    p.nombre AS producto_nombre
             FROM venta_anulacion_items vai
             LEFT JOIN productos p ON p.id = vai.producto_id
             WHERE vai.anulacion_id IN ($placeholders)
             ORDER BY vai.anulacion_id DESC, vai.id ASC"
        );
        $stItems->execute($ids);

        foreach ($stItems->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $anulacionId = (int)($row['anulacion_id'] ?? 0);
            if (!isset($historial[$anulacionId])) {
                continue;
            }

            $historial[$anulacionId]['items'][] = $row;
            $historial[$anulacionId]['cantidad_total_anulada'] += (float)($row['cantidad_anulada'] ?? 0);
            $historial[$anulacionId]['lineas_afectadas']++;
        }

        foreach ($historial as &$row) {
            $row['cantidad_total_anulada'] = round((float)$row['cantidad_total_anulada'], 3);
        }
        unset($row);

        return array_values($historial);
    }
}

if (!function_exists('flus_venta_stock_reponer_items')) {
    function flus_venta_stock_reponer_items(PDO $pdo, array $items, int $ventaId, ?int $userId, string $comentario): array
    {
        if ($items === [] || !flus_table_exists($pdo, 'productos')) {
            return [];
        }

        $stStock = $pdo->prepare('UPDATE productos SET stock = stock + :qty WHERE id = :pid');
        $puedeMovimientos = flus_table_exists($pdo, 'movimientos_stock') && function_exists('insert_dynamic');
        $movimientos = [];

        foreach ($items as $row) {
            $item = $row['item'] ?? [];
            $qty = round((float)($row['cantidad'] ?? $row['cantidad_restante'] ?? 0), 3);
            $productoId = (int)($item['producto_id'] ?? 0);

            if ($productoId <= 0 || $qty <= 0) {
                continue;
            }

            $stStock->execute([
                ':qty' => $qty,
                ':pid' => $productoId,
            ]);

            if ($puedeMovimientos) {
                $payload = [
                    'producto_id' => $productoId,
                    'tipo' => 'ANULACION',
                    'cantidad' => $qty,
                    'venta_id' => $ventaId,
                    'referencia_venta_id' => $ventaId,
                    'comentario' => $comentario,
                    'fecha' => date('Y-m-d H:i:s'),
                ];

                if ($userId !== null && flus_column_exists($pdo, 'movimientos_stock', 'usuario_id')) {
                    $payload['usuario_id'] = $userId;
                }

                $movimientos[] = insert_dynamic($pdo, 'movimientos_stock', $payload);
            }
        }

        return $movimientos;
    }
}

if (!function_exists('flus_venta_cc_movimientos_origen')) {
    function flus_venta_cc_movimientos_origen(PDO $pdo, int $ventaId): array
    {
        if ($ventaId <= 0 || !flus_table_exists($pdo, 'cuenta_corriente_movimientos')) {
            return [];
        }

        if (flus_column_exists($pdo, 'cuenta_corriente_movimientos', 'venta_id')) {
            $st = $pdo->prepare(
                "SELECT id
                 FROM cuenta_corriente_movimientos
                 WHERE venta_id = ?
                   AND tipo IN ('CARGO', 'AJUSTE_POS')
                 ORDER BY id ASC"
            );
            $st->execute([$ventaId]);

            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        return [];
    }
}

if (!function_exists('flus_venta_anulaciones_insert_dynamic')) {
    function flus_venta_anulaciones_insert_dynamic(PDO $pdo, string $table, array $data): int
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
}

if (!function_exists('flus_venta_anulacion_registrar_total_restante')) {
    function flus_venta_anulacion_registrar_total_restante(PDO $pdo, int $ventaId, array $venta, array $itemsRestantes, string $motivo = '', ?int $userId = null): ?int
    {
        if ($ventaId <= 0 || !flus_venta_anulaciones_habilitadas($pdo)) {
            return null;
        }

        $snapshotItems = [];
        $montoAnulado = 0.0;

        foreach ($itemsRestantes as $row) {
            $item = is_array($row['item'] ?? null) ? $row['item'] : [];
            $cantidad = round((float)($row['cantidad_restante'] ?? 0), 3);
            if ($item === [] || $cantidad <= 0) {
                continue;
            }

            $cantidadOriginal = (float)($item['cantidad'] ?? 0);
            $precioUnitario = (float)($item['precio_unit_final'] ?? $item['precio'] ?? 0);
            if ($precioUnitario <= 0 && $cantidadOriginal > 0) {
                $precioUnitario = round((float)($item['subtotal'] ?? 0) / $cantidadOriginal, 2);
            }

            $subtotalSnapshot = round((float)($item['subtotal'] ?? 0), 2);
            $subtotalAnulado = round($precioUnitario * $cantidad, 2);
            $montoAnulado += $subtotalAnulado;

            $snapshotItems[] = [
                'venta_item_id' => (int)($item['id'] ?? 0),
                'producto_id' => (int)($item['producto_id'] ?? 0),
                'cantidad_anulada' => $cantidad,
                'precio_unitario_snapshot' => $precioUnitario,
                'descuento_monto_snapshot' => round((float)($item['descuento_monto'] ?? 0), 2),
                'iva_porcentaje_snapshot' => round((float)($item['iva_porcentaje'] ?? 0), 2),
                'subtotal_snapshot' => $subtotalSnapshot,
                'subtotal_anulado' => $subtotalAnulado,
            ];
        }

        if ($snapshotItems === []) {
            $montoAnulado = round((float)($venta['total'] ?? 0), 2);
            $montoAnulado = max(0.0, $montoAnulado);
        } else {
            $montoAnulado = round($montoAnulado, 2);
        }

        $motivoCorto = trim($motivo);
        if ($motivoCorto !== '') {
            $motivoCorto = function_exists('mb_substr') ? mb_substr($motivoCorto, 0, 255) : substr($motivoCorto, 0, 255);
        }

        $anulacionId = flus_venta_anulaciones_insert_dynamic($pdo, 'venta_anulaciones', [
            'venta_id' => $ventaId,
            'tipo' => 'TOTAL',
            'estado' => 'CONFIRMADA',
            'motivo' => $motivoCorto !== '' ? $motivoCorto : null,
            'monto_bruto' => $montoAnulado,
            'monto_neto' => $montoAnulado,
            'monto_iva' => 0,
            'monto_total' => $montoAnulado,
            'anulado_por' => $userId,
            'anulado_en' => date('Y-m-d H:i:s'),
        ]);

        foreach ($snapshotItems as $snapshot) {
            $snapshot['anulacion_id'] = $anulacionId;
            flus_venta_anulaciones_insert_dynamic($pdo, 'venta_anulacion_items', $snapshot);
        }

        return $anulacionId;
    }
}

if (!function_exists('flus_venta_cc_total_original')) {
    function flus_venta_cc_total_original(PDO $pdo, int $ventaId): float
    {
        $movIds = flus_venta_cc_movimientos_origen($pdo, $ventaId);
        if ($movIds === []) {
            return 0.0;
        }

        $placeholders = implode(',', array_fill(0, count($movIds), '?'));
        $st = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM cuenta_corriente_movimientos WHERE id IN ($placeholders)");
        $st->execute($movIds);

        return round((float)$st->fetchColumn(), 2);
    }
}

if (!function_exists('flus_venta_cc_revertir_monto')) {
    function flus_venta_cc_revertir_monto(PDO $pdo, array $venta, int $ventaId, float $montoObjetivo, ?int $userId, string $motivo): ?array
    {
        $montoPendiente = round($montoObjetivo, 2);
        if ($ventaId <= 0 || $montoPendiente <= 0) {
            return null;
        }

        if (!flus_table_exists($pdo, 'cuenta_corriente_movimientos') || !flus_table_exists($pdo, 'clientes') || !function_exists('insert_dynamic')) {
            return null;
        }

        $movIds = flus_venta_cc_movimientos_origen($pdo, $ventaId);
        if ($movIds === []) {
            return null;
        }

        $reversados = [];
        $omitidos = [];

        foreach ($movIds as $movId) {
            if ($montoPendiente <= 0.0009) {
                break;
            }

            $stMov = $pdo->prepare('SELECT * FROM cuenta_corriente_movimientos WHERE id = ? FOR UPDATE');
            $stMov->execute([$movId]);
            $mov = $stMov->fetch(PDO::FETCH_ASSOC);

            if (!$mov) {
                $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'NO_EXISTE'];
                continue;
            }

            $tipo = strtoupper((string)($mov['tipo'] ?? ''));
            if (!in_array($tipo, ['CARGO', 'AJUSTE_POS'], true)) {
                $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'TIPO_NO_SOPORTADO'];
                continue;
            }

            $montoOriginal = round((float)($mov['monto'] ?? 0), 2);
            if ($montoOriginal <= 0) {
                $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'MONTO_INVALIDO'];
                continue;
            }

            $montoRevertido = 0.0;
            if (flus_column_exists($pdo, 'cuenta_corriente_movimientos', 'reversa_de_id')) {
                $stRev = $pdo->prepare(
                    "SELECT COALESCE(SUM(monto), 0)
                     FROM cuenta_corriente_movimientos
                     WHERE reversa_de_id = ?
                       AND tipo = 'REVERSA'
                       AND estado = 'ACTIVO'"
                );
                $stRev->execute([$movId]);
                $montoRevertido = round((float)$stRev->fetchColumn(), 2);
            }

            $montoDisponible = round($montoOriginal - $montoRevertido, 2);
            if ($montoDisponible <= 0.0009) {
                $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'YA_REVERSADO'];
                continue;
            }

            $montoReversa = round(min($montoDisponible, $montoPendiente), 2);
            if ($montoReversa <= 0) {
                continue;
            }

            $clienteId = (int)($mov['cliente_id'] ?? 0);
            if ($clienteId <= 0) {
                $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'CLIENTE_INVALIDO'];
                continue;
            }

            $stCli = $pdo->prepare('SELECT cc_saldo FROM clientes WHERE id = ? FOR UPDATE');
            $stCli->execute([$clienteId]);
            $cli = $stCli->fetch(PDO::FETCH_ASSOC);

            if (!$cli) {
                $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'CLIENTE_NO_ENCONTRADO'];
                continue;
            }

            $saldoAnterior = round((float)($cli['cc_saldo'] ?? 0), 2);
            $saldoPosterior = round($saldoAnterior - $montoReversa, 2);

            $payload = [
                'cliente_id' => $clienteId,
                'venta_id' => $ventaId,
                'tipo' => 'REVERSA',
                'estado' => 'ACTIVO',
                'monto' => $montoReversa,
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoPosterior,
                'concepto' => 'REVERSA: ' . $motivo,
            ];

            if (flus_column_exists($pdo, 'cuenta_corriente_movimientos', 'reversa_de_id')) {
                $payload['reversa_de_id'] = $movId;
            }
            if ($userId !== null && flus_column_exists($pdo, 'cuenta_corriente_movimientos', 'created_by')) {
                $payload['created_by'] = $userId;
            }
            if (isset($venta['caja_id']) && flus_column_exists($pdo, 'cuenta_corriente_movimientos', 'caja_id')) {
                $payload['caja_id'] = $venta['caja_id'];
            }
            if (isset($_SERVER['REMOTE_ADDR']) && flus_column_exists($pdo, 'cuenta_corriente_movimientos', 'ip_address')) {
                $payload['ip_address'] = $_SERVER['REMOTE_ADDR'];
            }

            $reversaId = insert_dynamic($pdo, 'cuenta_corriente_movimientos', $payload);

            $pdo->prepare('UPDATE clientes SET cc_saldo = ? WHERE id = ?')->execute([$saldoPosterior, $clienteId]);

            $montoPendiente = round($montoPendiente - $montoReversa, 2);
            $reversados[] = [
                'movimiento_id' => $movId,
                'reversa_id' => $reversaId,
                'cliente_id' => $clienteId,
                'monto' => $montoReversa,
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoPosterior,
            ];

            $montoDisponibleRestante = round($montoDisponible - $montoReversa, 2);
            if ($montoDisponibleRestante <= 0.0009) {
                $setMov = ["estado = 'ANULADO'"];
                if (flus_column_exists($pdo, 'cuenta_corriente_movimientos', 'updated_at')) {
                    $setMov[] = 'updated_at = NOW()';
                }
                $pdo->prepare('UPDATE cuenta_corriente_movimientos SET ' . implode(', ', $setMov) . ' WHERE id = ?')->execute([$movId]);
            }
        }

        if ($reversados === [] && $omitidos === []) {
            return null;
        }

        return [
            'venta_id' => $ventaId,
            'reversados' => $reversados,
            'omitidos' => $omitidos,
            'pendiente_no_revertido' => max(0, $montoPendiente),
        ];
    }
}
