<?php

declare(strict_types=1);

/**
 * Optional integration check for the real MySQL/MariaDB schema path.
 *
 * It is intentionally opt-in because local developer machines may not have a
 * disposable MySQL server available.
 *
 * Example:
 *   $env:FLUS_TEST_DB='1'
 *   $env:FLUS_TEST_DB_HOST='127.0.0.1'
 *   $env:FLUS_TEST_DB_USER='root'
 *   $env:FLUS_TEST_DB_PASS=''
 *   C:\xampp\php\php.exe tests\integration_db.php
 *
 * See docs/INTEGRATION_DB_RUNNER.md for the release checklist and failure
 * handling.
 */

$root = dirname(__DIR__);

if ((string)getenv('FLUS_TEST_DB') !== '1') {
    echo "[SKIP] Set FLUS_TEST_DB=1 to run the DB integration check. See docs/INTEGRATION_DB_RUNNER.md.\n";
    exit(0);
}

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "[FAIL] pdo_mysql is not loaded.\n");
    exit(1);
}

require_once $root . '/src/migrations_runner.php';
require_once $root . '/src/facturacion_lib.php';
require_once $root . '/src/venta_anulaciones_lib.php';
require_once $root . '/src/Fiscal/bootstrap.php';
require_once $root . '/src/cobranzas_lib.php';
require_once $root . '/src/tesoreria_lib.php';
require_once $root . '/src/compras_tesoreria_lib.php';
require_once $root . '/src/compras_precio_historial_lib.php';
require_once $root . '/public/includes/CuentaCorrienteController.php';

function flus_it_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : (string)$value;
}

function flus_it_quote_ident(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new RuntimeException("Unsafe identifier: {$identifier}");
    }

    return '`' . $identifier . '`';
}

function flus_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo "[OK] {$message}\n";
}

function flus_it_table_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);

    return (bool)$stmt->fetchColumn();
}

function flus_it_run_pos_sale_case(PDO $pdo): array
{
    static $saleNo = 0;
    $saleNo++;
    $productCode = sprintf('IT-SALE-%03d', $saleNo);

    $pdo->beginTransaction();
    $cajaId = 0;
    $productoId = 0;
    $ventaId = 0;

    try {
        $pdo->exec("
            INSERT INTO caja_sesiones (user_id, fecha_apertura, saldo_inicial, terminal_id)
            VALUES (1, NOW(), 0.00, 1)
        ");
        $cajaId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO productos (codigo, nombre, categoria, precio, stock, costo, stock_minimo, activo, iva_porcentaje)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, 21.00)
        ");
        $stmt->execute([$productCode, 'Producto integracion POS ' . $saleNo, 'Integracion', 75.00, 10.000, 40.00, 1.000]);
        $productoId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO ventas (
                uuid, fecha, cliente_id, user_id, usuario_id, vendedor_id, caja_id, terminal_id,
                total, total_bruto, descuento_total, descuento_monto, medio_pago, monto_pagado,
                monto_cc, vuelto, estado, facturada
            ) VALUES (
                UUID(), NOW(), NULL, 1, 1, 1, ?, 1,
                150.00, 150.00, 0.00, 0.00, 'EFECTIVO', 150.00,
                0.00, 0.00, 'EMITIDA', 0
            )
        ");
        $stmt->execute([$cajaId]);
        $ventaId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO venta_items (
                venta_id, producto_id, cantidad, precio, subtotal,
                precio_unit_original, descuento_monto, precio_unit_final
            ) VALUES (?, ?, 2.000, 75.00, 150.00, 75.00, 0.00, 75.00)
        ");
        $stmt->execute([$ventaId, $productoId]);

        $stmt = $pdo->prepare("INSERT INTO venta_pagos (venta_id, medio_pago, monto) VALUES (?, 'EFECTIVO', 100.00)");
        $stmt->execute([$ventaId]);
        $stmt = $pdo->prepare("INSERT INTO venta_pagos (venta_id, medio_pago, monto) VALUES (?, 'MP', 50.00)");
        $stmt->execute([$ventaId]);

        $stmt = $pdo->prepare("
            INSERT INTO movimientos_stock (producto_id, tipo, cantidad, venta_id, referencia_venta_id, comentario, fecha)
            VALUES (?, 'VENTA', 2.000, ?, ?, 'Venta integracion POS', NOW())
        ");
        $stmt->execute([$productoId, $ventaId, $ventaId]);

        $stmt = $pdo->prepare("UPDATE productos SET stock = stock - 2.000 WHERE id = ?");
        $stmt->execute([$productoId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stmt = $pdo->prepare("
        SELECT
            v.total AS venta_total,
            COALESCE(SUM(vp.monto), 0) AS pagos_total,
            COUNT(DISTINCT vi.id) AS item_count,
            COUNT(DISTINCT vp.id) AS pago_count,
            GROUP_CONCAT(DISTINCT vp.medio_pago ORDER BY vp.medio_pago SEPARATOR '+') AS medios,
            p.stock AS stock_final
        FROM ventas v
        JOIN venta_items vi ON vi.venta_id = v.id
        JOIN productos p ON p.id = vi.producto_id
        LEFT JOIN venta_pagos vp ON vp.venta_id = v.id
        WHERE v.id = ?
        GROUP BY v.id, p.stock
    ");
    $stmt->execute([$ventaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    flus_it_assert(round((float)($row['venta_total'] ?? 0), 2) === 150.00, 'POS sale total is 150.00');
    flus_it_assert(round((float)($row['pagos_total'] ?? 0), 2) === 150.00, 'mixed POS payments match sale total');
    flus_it_assert((int)($row['item_count'] ?? 0) === 1, 'POS sale has one item row');
    flus_it_assert((int)($row['pago_count'] ?? 0) === 2, 'POS sale has two payment rows');
    flus_it_assert((string)($row['medios'] ?? '') === 'EFECTIVO+MP', 'POS sale keeps mixed payment labels');
    flus_it_assert(round((float)($row['stock_final'] ?? 0), 3) === 8.000, 'POS sale decrements stock');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE venta_id = ? AND tipo = 'VENTA'");
    $stmt->execute([$ventaId]);
    flus_it_assert((int)$stmt->fetchColumn() === 1, 'POS sale writes stock movement');

    $stmt = $pdo->prepare("SELECT stock_anterior, stock_nuevo FROM movimientos_stock WHERE venta_id = ? AND tipo = 'VENTA' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$ventaId]);
    $movement = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    flus_it_assert(round((float)($movement['stock_anterior'] ?? 0), 3) === 10.000, 'POS sale stock movement keeps previous stock');
    flus_it_assert(round((float)($movement['stock_nuevo'] ?? 0), 3) === 8.000, 'POS sale stock movement keeps resulting stock');

    return [
        'caja_id' => $cajaId,
        'producto_id' => $productoId,
        'venta_id' => $ventaId,
    ];
}

function flus_it_run_non_remote_fiscal_case(PDO $pdo, int $ventaId): array
{
    static $fiscalNo = 0;
    $fiscalNo++;
    $facturaNumero = 9000 + $fiscalNo;
    $clienteCuit = sprintf('201111111%02d', $fiscalNo);

    flus_it_assert(flus_it_table_has_column($pdo, 'facturas', 'documento_id'), 'facturas.documento_id exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'factura_eventos_arca', 'venta_id'), 'factura_eventos_arca.venta_id exists');

    $pdo->beginTransaction();
    $clienteId = 0;
    $documentoId = 0;
    $facturaId = 0;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO clientes (nombre, cuit, cond_iva, email, activo)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute(['Cliente integracion fiscal ' . $fiscalNo, $clienteCuit, 'CF', 'fiscal-it-' . $fiscalNo . '@flus.local']);
        $clienteId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("UPDATE ventas SET cliente_id = ? WHERE id = ?");
        $stmt->execute([$clienteId, $ventaId]);

        $stmt = $pdo->prepare("
            INSERT INTO documentos_comerciales (
                request_uid, tipo_documento, origen, estado, cliente_id, venta_id,
                nota, medio_pago, total
            ) VALUES (UUID(), 'FACTURA_MANUAL', 'MANUAL', 'EMITIDO', ?, ?, 'Documento integracion fiscal', 'MIXTO', 150.00)
        ");
        $stmt->execute([$clienteId, $ventaId]);
        $documentoId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO documento_items (
                documento_id, codigo, descripcion, cantidad, precio_unitario, subtotal, iva_porcentaje
            ) VALUES (?, 'DOC-IT-001', 'Item integracion fiscal demo', 2.000, 75.00, 150.00, 21.00)
        ");
        $stmt->execute([$documentoId]);

        $requestUid = (string)$pdo->query('SELECT UUID()')->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO facturas (
                venta_id, documento_id, cliente_id, naturaleza, tipo, tipo_cbte,
                punto_venta, numero, importe_neto, importe_iva, importe_exento,
                importe_no_gravado, moneda_id, moneda_cotiz, fecha, total, cae,
                cae_vto, estado, estado_fiscal, fiscal_request_uid, fiscal_intentos,
                fiscal_requested_at, fiscal_approved_at, modo, doc_tipo, doc_numero,
                condicion_iva_receptor_id
            ) VALUES (
                ?, ?, ?, 'FACTURA', 'FC', 11,
                1, ?, 123.97, 26.03, 0.00,
                0.00, 'PES', 1.000000, NOW(), 150.00, 'DEMO-CAE',
                DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 10 DAY), '%Y%m%d'),
                'EMITIDA', 'AUTORIZADA', ?, 1,
                NOW(), NOW(), 'demo', 99, '0',
                5
            )
        ");
        $stmt->execute([$ventaId, $documentoId, $clienteId, $facturaNumero, $requestUid]);
        $facturaId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO factura_items (
                factura_id, linea_orden, origen_tipo, snapshot_source, producto_id,
                codigo_snapshot, descripcion_snapshot, cantidad, precio_unitario_bruto,
                descuento_total, iva_porcentaje, neto_gravado, iva_importe, subtotal_total
            ) VALUES (
                ?, 1, 'VENTA', 'RECONSTRUIDO', NULL,
                'DOC-IT-001', 'Item integracion fiscal demo', 2.000, 75.000000,
                0.000000, 21.00, 123.970000, 26.030000, 150.000000
            )
        ");
        $stmt->execute([$facturaId]);

        $stmt = $pdo->prepare("
            INSERT INTO factura_eventos_arca (
                venta_id, cliente_id, factura_id, request_uid, operacion, resultado,
                intento_no, modo, request_json, response_json, created_at, finished_at
            ) VALUES (
                ?, ?, ?, ?, 'FACTURA_MANUAL', 'OK',
                1, 'demo', '{\"modo\":\"demo\"}', '{\"cae\":\"DEMO-CAE\"}', NOW(), NOW()
            )
        ");
        $stmt->execute([$ventaId, $clienteId, $facturaId, $requestUid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stmt = $pdo->prepare("
        SELECT
            f.id AS factura_id,
            f.total AS factura_total,
            f.estado_fiscal,
            f.modo,
            dc.tipo_documento,
            dc.estado AS documento_estado,
            COUNT(DISTINCT di.id) AS documento_items,
            COUNT(DISTINCT fi.id) AS factura_items,
            COUNT(DISTINCT fea.id) AS eventos
        FROM facturas f
        JOIN documentos_comerciales dc ON dc.id = f.documento_id
        JOIN documento_items di ON di.documento_id = dc.id
        JOIN factura_items fi ON fi.factura_id = f.id
        LEFT JOIN factura_eventos_arca fea ON fea.factura_id = f.id
        WHERE f.venta_id = ?
        GROUP BY f.id, dc.id
    ");
    $stmt->execute([$ventaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    flus_it_assert((int)($row['factura_id'] ?? 0) > 0, 'non-remote fiscal invoice is linked to sale');
    flus_it_assert(round((float)($row['factura_total'] ?? 0), 2) === 150.00, 'non-remote fiscal invoice total is 150.00');
    flus_it_assert((string)($row['estado_fiscal'] ?? '') === 'AUTORIZADA', 'non-remote fiscal invoice is authorized locally');
    flus_it_assert((string)($row['modo'] ?? '') === 'demo', 'non-remote fiscal invoice stays in demo mode');
    flus_it_assert((string)($row['tipo_documento'] ?? '') === 'FACTURA_MANUAL', 'non-remote fiscal invoice keeps document type');
    flus_it_assert((string)($row['documento_estado'] ?? '') === 'EMITIDO', 'non-remote fiscal document is emitted');
    flus_it_assert((int)($row['documento_items'] ?? 0) === 1, 'non-remote fiscal document has one item');
    flus_it_assert((int)($row['factura_items'] ?? 0) === 1, 'non-remote fiscal invoice has one fiscal item');
    flus_it_assert((int)($row['eventos'] ?? 0) === 1, 'non-remote fiscal invoice has one local ARCA event');

    return [
        'cliente_id' => $clienteId,
        'documento_id' => $documentoId,
        'factura_id' => $facturaId,
        'factura_numero' => $facturaNumero,
        'factura_punto_venta' => 1,
        'factura_tipo_cbte' => 11,
        'fiscal_request_uid' => $requestUid,
        'venta_id' => $ventaId,
    ];
}

function flus_it_run_non_remote_nc_total_case(PDO $pdo, array $fiscalCase): void
{
    $ventaId = (int)($fiscalCase['venta_id'] ?? 0);
    $clienteId = (int)($fiscalCase['cliente_id'] ?? 0);
    $facturaOrigenId = (int)($fiscalCase['factura_id'] ?? 0);
    $facturaOrigenNumero = (int)($fiscalCase['factura_numero'] ?? 0);
    $facturaOrigenPuntoVenta = (int)($fiscalCase['factura_punto_venta'] ?? 0);
    $facturaOrigenTipoCbte = (int)($fiscalCase['factura_tipo_cbte'] ?? 0);

    flus_it_assert($ventaId > 0 && $clienteId > 0 && $facturaOrigenId > 0, 'non-remote NC total has fiscal origin ids');
    flus_it_assert(flus_it_table_has_column($pdo, 'facturas', 'factura_asociada_id'), 'facturas.factura_asociada_id exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'facturas', 'venta_anulacion_id'), 'facturas.venta_anulacion_id exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'factura_items', 'venta_anulacion_item_id'), 'factura_items.venta_anulacion_item_id exists');

    $stmt = $pdo->prepare("
        SELECT vi.id, vi.producto_id, vi.cantidad, vi.precio, vi.subtotal, p.codigo, p.nombre, p.iva_porcentaje
        FROM venta_items vi
        JOIN productos p ON p.id = vi.producto_id
        WHERE vi.venta_id = ?
        ORDER BY vi.id ASC
        LIMIT 1
    ");
    $stmt->execute([$ventaId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $ventaItemId = (int)($item['id'] ?? 0);
    $productoId = (int)($item['producto_id'] ?? 0);
    flus_it_assert($ventaItemId > 0 && $productoId > 0, 'non-remote NC total can resolve original sale item');

    $pdo->beginTransaction();
    $anulacionId = 0;
    $anulacionItemId = 0;
    $ncFacturaId = 0;

    try {
        $requestUid = (string)$pdo->query('SELECT UUID()')->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO venta_anulaciones (
                venta_id, tipo, estado, motivo, monto_bruto, monto_neto, monto_iva, monto_total,
                anulado_por, anulado_en, requiere_nc, factura_origen_id, estado_fiscal,
                fiscal_request_uid, fiscal_intentos, fiscal_requested_at, fiscal_approved_at, fiscal_applied_at
            ) VALUES (
                ?, 'TOTAL', 'CONFIRMADA', 'NC total integracion', 150.00, 123.97, 26.03, 150.00,
                1, NOW(), 1, ?, 'APLICADA',
                ?, 1, NOW(), NOW(), NOW()
            )
        ");
        $stmt->execute([$ventaId, $facturaOrigenId, $requestUid]);
        $anulacionId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO venta_anulacion_items (
                anulacion_id, venta_item_id, producto_id, cantidad_anulada,
                precio_unitario_snapshot, descuento_monto_snapshot,
                iva_porcentaje_snapshot, subtotal_snapshot, subtotal_anulado
            ) VALUES (?, ?, ?, 2.000, 75.00, 0.00, 21.00, 150.00, 150.00)
        ");
        $stmt->execute([$anulacionId, $ventaItemId, $productoId]);
        $anulacionItemId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO facturas (
                venta_id, venta_anulacion_id, factura_asociada_id, cliente_id, naturaleza, tipo, tipo_cbte,
                comprobante_asoc_tipo_cbte, comprobante_asoc_punto_venta, comprobante_asoc_numero,
                punto_venta, numero, importe_neto, importe_iva, importe_exento,
                importe_no_gravado, moneda_id, moneda_cotiz, fecha, total, cae,
                cae_vto, estado, estado_fiscal, fiscal_request_uid, fiscal_intentos,
                fiscal_requested_at, fiscal_approved_at, modo, doc_tipo, doc_numero,
                condicion_iva_receptor_id
            ) VALUES (
                ?, ?, ?, ?, 'NC', 'NCC', 13,
                ?, ?, ?,
                1, 9101, 123.97, 26.03, 0.00,
                0.00, 'PES', 1.000000, NOW(), 150.00, 'DEMO-NC-CAE',
                DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 10 DAY), '%Y%m%d'),
                'EMITIDA', 'AUTORIZADA', ?, 1,
                NOW(), NOW(), 'demo', 99, '0',
                5
            )
        ");
        $stmt->execute([
            $ventaId,
            $anulacionId,
            $facturaOrigenId,
            $clienteId,
            $facturaOrigenTipoCbte,
            $facturaOrigenPuntoVenta,
            $facturaOrigenNumero,
            $requestUid,
        ]);
        $ncFacturaId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO factura_items (
                factura_id, linea_orden, origen_tipo, snapshot_source, venta_item_id,
                venta_anulacion_item_id, producto_id, codigo_snapshot, descripcion_snapshot,
                cantidad, precio_unitario_bruto, descuento_total, iva_porcentaje,
                neto_gravado, iva_importe, subtotal_total
            ) VALUES (
                ?, 1, 'ANULACION', 'RECONSTRUIDO', ?,
                ?, ?, ?, ?,
                2.000, 75.000000, 0.000000, 21.00,
                123.970000, 26.030000, 150.000000
            )
        ");
        $stmt->execute([
            $ncFacturaId,
            $ventaItemId,
            $anulacionItemId,
            $productoId,
            (string)($item['codigo'] ?? 'IT-SALE-001'),
            (string)($item['nombre'] ?? 'Producto integracion POS'),
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO factura_eventos_arca (
                venta_anulacion_id, venta_id, cliente_id, factura_id, request_uid,
                operacion, resultado, intento_no, modo, request_json, response_json,
                created_at, finished_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                'NC_TOTAL', 'OK', 1, 'demo', '{\"modo\":\"demo\",\"tipo\":\"NC_TOTAL\"}', '{\"cae\":\"DEMO-NC-CAE\"}',
                NOW(), NOW()
            )
        ");
        $stmt->execute([$anulacionId, $ventaId, $clienteId, $ncFacturaId, $requestUid]);

        $stmt = $pdo->prepare("UPDATE venta_anulaciones SET nc_factura_id = ? WHERE id = ?");
        $stmt->execute([$ncFacturaId, $anulacionId]);

        $stmt = $pdo->prepare("UPDATE ventas SET estado = 'ANULADA' WHERE id = ?");
        $stmt->execute([$ventaId]);

        $stmt = $pdo->prepare("
            INSERT INTO movimientos_stock (producto_id, tipo, cantidad, venta_id, referencia_venta_id, comentario, fecha)
            VALUES (?, 'ANULACION', 2.000, ?, ?, 'NC total integracion', NOW())
        ");
        $stmt->execute([$productoId, $ventaId, $ventaId]);

        $stmt = $pdo->prepare("UPDATE productos SET stock = stock + 2.000 WHERE id = ?");
        $stmt->execute([$productoId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stmt = $pdo->prepare("
        SELECT
            nc.id AS nc_factura_id,
            nc.naturaleza,
            nc.tipo AS nc_tipo,
            nc.total AS nc_total,
            nc.factura_asociada_id,
            va.tipo AS anulacion_tipo,
            va.estado_fiscal AS anulacion_estado_fiscal,
            va.nc_factura_id AS anulacion_nc_factura_id,
            v.estado AS venta_estado,
            p.stock AS stock_final,
            COUNT(DISTINCT vai.id) AS anulacion_items,
            COUNT(DISTINCT fi.id) AS factura_items,
            COUNT(DISTINCT fea.id) AS eventos,
            COUNT(DISTINCT ms.id) AS movimientos_anulacion
        FROM facturas nc
        JOIN venta_anulaciones va ON va.nc_factura_id = nc.id
        JOIN ventas v ON v.id = va.venta_id
        JOIN venta_anulacion_items vai ON vai.anulacion_id = va.id
        JOIN productos p ON p.id = vai.producto_id
        JOIN factura_items fi ON fi.factura_id = nc.id
        LEFT JOIN factura_eventos_arca fea ON fea.factura_id = nc.id
        LEFT JOIN movimientos_stock ms ON ms.venta_id = v.id AND ms.tipo = 'ANULACION'
        WHERE nc.id = ?
        GROUP BY nc.id, va.id, v.id, p.id
    ");
    $stmt->execute([$ncFacturaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    flus_it_assert((int)($row['nc_factura_id'] ?? 0) === $ncFacturaId, 'non-remote NC total invoice exists');
    flus_it_assert((string)($row['naturaleza'] ?? '') === 'NC', 'non-remote NC total uses NC nature');
    flus_it_assert((string)($row['nc_tipo'] ?? '') === 'NCC', 'non-remote NC total uses NCC type');
    flus_it_assert(round((float)($row['nc_total'] ?? 0), 2) === 150.00, 'non-remote NC total amount is 150.00');
    flus_it_assert((int)($row['factura_asociada_id'] ?? 0) === $facturaOrigenId, 'non-remote NC total is linked to original invoice');
    flus_it_assert((string)($row['anulacion_tipo'] ?? '') === 'TOTAL', 'non-remote NC total keeps annulment type');
    flus_it_assert((string)($row['anulacion_estado_fiscal'] ?? '') === 'APLICADA', 'non-remote NC total is applied locally');
    flus_it_assert((int)($row['anulacion_nc_factura_id'] ?? 0) === $ncFacturaId, 'non-remote NC total links annulment to NC invoice');
    flus_it_assert((string)($row['venta_estado'] ?? '') === 'ANULADA', 'non-remote NC total marks sale as annulled');
    flus_it_assert(round((float)($row['stock_final'] ?? 0), 3) === 10.000, 'non-remote NC total restores product stock');
    flus_it_assert((int)($row['anulacion_items'] ?? 0) === 1, 'non-remote NC total has one annulment item');
    flus_it_assert((int)($row['factura_items'] ?? 0) === 1, 'non-remote NC total has one fiscal item');
    flus_it_assert((int)($row['eventos'] ?? 0) === 1, 'non-remote NC total has one local ARCA event');
    flus_it_assert((int)($row['movimientos_anulacion'] ?? 0) === 1, 'non-remote NC total writes stock annulment movement');
}

function flus_it_run_non_remote_nc_partial_case(PDO $pdo, array $fiscalCase): void
{
    $ventaId = (int)($fiscalCase['venta_id'] ?? 0);
    $clienteId = (int)($fiscalCase['cliente_id'] ?? 0);
    $facturaOrigenId = (int)($fiscalCase['factura_id'] ?? 0);
    $facturaOrigenNumero = (int)($fiscalCase['factura_numero'] ?? 0);
    $facturaOrigenPuntoVenta = (int)($fiscalCase['factura_punto_venta'] ?? 0);
    $facturaOrigenTipoCbte = (int)($fiscalCase['factura_tipo_cbte'] ?? 0);

    flus_it_assert($ventaId > 0 && $clienteId > 0 && $facturaOrigenId > 0, 'non-remote NC partial has fiscal origin ids');

    $stmt = $pdo->prepare("
        SELECT vi.id, vi.producto_id, vi.cantidad, vi.precio, vi.subtotal, p.codigo, p.nombre, p.iva_porcentaje
        FROM venta_items vi
        JOIN productos p ON p.id = vi.producto_id
        WHERE vi.venta_id = ?
        ORDER BY vi.id ASC
        LIMIT 1
    ");
    $stmt->execute([$ventaId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $ventaItemId = (int)($item['id'] ?? 0);
    $productoId = (int)($item['producto_id'] ?? 0);
    flus_it_assert($ventaItemId > 0 && $productoId > 0, 'non-remote NC partial can resolve original sale item');

    $pdo->beginTransaction();
    $anulacionId = 0;
    $anulacionItemId = 0;
    $ncFacturaId = 0;

    try {
        $requestUid = (string)$pdo->query('SELECT UUID()')->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO venta_anulaciones (
                venta_id, tipo, estado, motivo, monto_bruto, monto_neto, monto_iva, monto_total,
                anulado_por, anulado_en, requiere_nc, factura_origen_id, estado_fiscal,
                fiscal_request_uid, fiscal_intentos, fiscal_requested_at, fiscal_approved_at, fiscal_applied_at
            ) VALUES (
                ?, 'PARCIAL', 'CONFIRMADA', 'NC parcial integracion', 75.00, 61.98, 13.02, 75.00,
                1, NOW(), 1, ?, 'APLICADA',
                ?, 1, NOW(), NOW(), NOW()
            )
        ");
        $stmt->execute([$ventaId, $facturaOrigenId, $requestUid]);
        $anulacionId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO venta_anulacion_items (
                anulacion_id, venta_item_id, producto_id, cantidad_anulada,
                precio_unitario_snapshot, descuento_monto_snapshot,
                iva_porcentaje_snapshot, subtotal_snapshot, subtotal_anulado
            ) VALUES (?, ?, ?, 1.000, 75.00, 0.00, 21.00, 150.00, 75.00)
        ");
        $stmt->execute([$anulacionId, $ventaItemId, $productoId]);
        $anulacionItemId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO facturas (
                venta_id, venta_anulacion_id, factura_asociada_id, cliente_id, naturaleza, tipo, tipo_cbte,
                comprobante_asoc_tipo_cbte, comprobante_asoc_punto_venta, comprobante_asoc_numero,
                punto_venta, numero, importe_neto, importe_iva, importe_exento,
                importe_no_gravado, moneda_id, moneda_cotiz, fecha, total, cae,
                cae_vto, estado, estado_fiscal, fiscal_request_uid, fiscal_intentos,
                fiscal_requested_at, fiscal_approved_at, modo, doc_tipo, doc_numero,
                condicion_iva_receptor_id
            ) VALUES (
                ?, ?, ?, ?, 'NC', 'NCC', 13,
                ?, ?, ?,
                1, 9102, 61.98, 13.02, 0.00,
                0.00, 'PES', 1.000000, NOW(), 75.00, 'DEMO-NC-PARCIAL-CAE',
                DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 10 DAY), '%Y%m%d'),
                'EMITIDA', 'AUTORIZADA', ?, 1,
                NOW(), NOW(), 'demo', 99, '0',
                5
            )
        ");
        $stmt->execute([
            $ventaId,
            $anulacionId,
            $facturaOrigenId,
            $clienteId,
            $facturaOrigenTipoCbte,
            $facturaOrigenPuntoVenta,
            $facturaOrigenNumero,
            $requestUid,
        ]);
        $ncFacturaId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO factura_items (
                factura_id, linea_orden, origen_tipo, snapshot_source, venta_item_id,
                venta_anulacion_item_id, producto_id, codigo_snapshot, descripcion_snapshot,
                cantidad, precio_unitario_bruto, descuento_total, iva_porcentaje,
                neto_gravado, iva_importe, subtotal_total
            ) VALUES (
                ?, 1, 'ANULACION', 'RECONSTRUIDO', ?,
                ?, ?, ?, ?,
                1.000, 75.000000, 0.000000, 21.00,
                61.980000, 13.020000, 75.000000
            )
        ");
        $stmt->execute([
            $ncFacturaId,
            $ventaItemId,
            $anulacionItemId,
            $productoId,
            (string)($item['codigo'] ?? 'IT-SALE-001'),
            (string)($item['nombre'] ?? 'Producto integracion POS'),
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO factura_eventos_arca (
                venta_anulacion_id, venta_id, cliente_id, factura_id, request_uid,
                operacion, resultado, intento_no, modo, request_json, response_json,
                created_at, finished_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                'NC_PARCIAL', 'OK', 1, 'demo', '{\"modo\":\"demo\",\"tipo\":\"NC_PARCIAL\"}', '{\"cae\":\"DEMO-NC-PARCIAL-CAE\"}',
                NOW(), NOW()
            )
        ");
        $stmt->execute([$anulacionId, $ventaId, $clienteId, $ncFacturaId, $requestUid]);

        $stmt = $pdo->prepare("UPDATE venta_anulaciones SET nc_factura_id = ? WHERE id = ?");
        $stmt->execute([$ncFacturaId, $anulacionId]);

        $stmt = $pdo->prepare("UPDATE ventas SET estado = 'PARCIALMENTE_ANULADA' WHERE id = ?");
        $stmt->execute([$ventaId]);

        $stmt = $pdo->prepare("
            INSERT INTO movimientos_stock (producto_id, tipo, cantidad, venta_id, referencia_venta_id, comentario, fecha)
            VALUES (?, 'ANULACION', 1.000, ?, ?, 'NC parcial integracion', NOW())
        ");
        $stmt->execute([$productoId, $ventaId, $ventaId]);

        $stmt = $pdo->prepare("UPDATE productos SET stock = stock + 1.000 WHERE id = ?");
        $stmt->execute([$productoId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stmt = $pdo->prepare("
        SELECT
            nc.id AS nc_factura_id,
            nc.naturaleza,
            nc.tipo AS nc_tipo,
            nc.total AS nc_total,
            nc.factura_asociada_id,
            va.tipo AS anulacion_tipo,
            va.estado_fiscal AS anulacion_estado_fiscal,
            va.nc_factura_id AS anulacion_nc_factura_id,
            v.estado AS venta_estado,
            p.stock AS stock_final,
            COUNT(DISTINCT vai.id) AS anulacion_items,
            COUNT(DISTINCT fi.id) AS factura_items,
            ROUND(COALESCE(SUM(DISTINCT fi.cantidad), 0), 3) AS cantidad_acreditada,
            COUNT(DISTINCT fea.id) AS eventos,
            COUNT(DISTINCT ms.id) AS movimientos_anulacion
        FROM facturas nc
        JOIN venta_anulaciones va ON va.nc_factura_id = nc.id
        JOIN ventas v ON v.id = va.venta_id
        JOIN venta_anulacion_items vai ON vai.anulacion_id = va.id
        JOIN productos p ON p.id = vai.producto_id
        JOIN factura_items fi ON fi.factura_id = nc.id
        LEFT JOIN factura_eventos_arca fea ON fea.factura_id = nc.id
        LEFT JOIN movimientos_stock ms ON ms.venta_id = v.id AND ms.tipo = 'ANULACION'
        WHERE nc.id = ?
        GROUP BY nc.id, va.id, v.id, p.id
    ");
    $stmt->execute([$ncFacturaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    flus_it_assert((int)($row['nc_factura_id'] ?? 0) === $ncFacturaId, 'non-remote NC partial invoice exists');
    flus_it_assert((string)($row['naturaleza'] ?? '') === 'NC', 'non-remote NC partial uses NC nature');
    flus_it_assert((string)($row['nc_tipo'] ?? '') === 'NCC', 'non-remote NC partial uses NCC type');
    flus_it_assert(round((float)($row['nc_total'] ?? 0), 2) === 75.00, 'non-remote NC partial amount is 75.00');
    flus_it_assert((int)($row['factura_asociada_id'] ?? 0) === $facturaOrigenId, 'non-remote NC partial is linked to original invoice');
    flus_it_assert((string)($row['anulacion_tipo'] ?? '') === 'PARCIAL', 'non-remote NC partial keeps annulment type');
    flus_it_assert((string)($row['anulacion_estado_fiscal'] ?? '') === 'APLICADA', 'non-remote NC partial is applied locally');
    flus_it_assert((int)($row['anulacion_nc_factura_id'] ?? 0) === $ncFacturaId, 'non-remote NC partial links annulment to NC invoice');
    flus_it_assert((string)($row['venta_estado'] ?? '') === 'PARCIALMENTE_ANULADA', 'non-remote NC partial marks sale as partially annulled');
    flus_it_assert(round((float)($row['stock_final'] ?? 0), 3) === 9.000, 'non-remote NC partial restores only credited stock');
    flus_it_assert((int)($row['anulacion_items'] ?? 0) === 1, 'non-remote NC partial has one annulment item');
    flus_it_assert((int)($row['factura_items'] ?? 0) === 1, 'non-remote NC partial has one fiscal item');
    flus_it_assert(round((float)($row['cantidad_acreditada'] ?? 0), 3) === 1.000, 'non-remote NC partial credits one unit');
    flus_it_assert((int)($row['eventos'] ?? 0) === 1, 'non-remote NC partial has one local ARCA event');
    flus_it_assert((int)($row['movimientos_anulacion'] ?? 0) === 1, 'non-remote NC partial writes stock annulment movement');
}

function flus_it_run_manual_legacy_nc_partial_case(PDO $pdo): void
{
    static $manualNo = 0;
    $manualNo++;
    $clienteCuit = sprintf('307777777%02d', $manualNo);
    $facturaNumero = 9500 + $manualNo;
    $requestUid = '';

    flus_it_assert(flus_it_table_has_column($pdo, 'factura_manual_items', 'id'), 'manual legacy items have stable ids');
    flus_it_assert(flus_it_table_has_column($pdo, 'factura_items', 'venta_item_id'), 'factura_items.venta_item_id exists for manual legacy NC tracking');

    $pdo->beginTransaction();
    $clienteId = 0;
    $ventaId = 0;
    $documentoId = 0;
    $facturaId = 0;
    $manualItemId = 0;
    $productoCocaId = 0;
    $productoFernetId = 0;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO clientes (nombre, cuit, cond_iva, email, activo)
            VALUES (?, ?, 'RI', ?, 1)
        ");
        $stmt->execute(['Cliente manual legacy ' . $manualNo, $clienteCuit, 'manual-legacy-' . $manualNo . '@flus.local']);
        $clienteId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO productos (codigo, nombre, categoria, precio, stock, costo, stock_minimo, activo, iva_porcentaje)
            VALUES (?, 'Coca 1.5 L legacy', 'Integracion', 3900.00, 7.000, 2500.00, 1.000, 1, 21.00)
        ");
        $stmt->execute(['IT-MAN-COCA-' . $manualNo]);
        $productoCocaId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO productos (codigo, nombre, categoria, precio, stock, costo, stock_minimo, activo, iva_porcentaje)
            VALUES (?, 'Fernet Branca 750 cc legacy', 'Integracion', 16250.00, 5.000, 10000.00, 1.000, 1, 21.00)
        ");
        $stmt->execute(['IT-MAN-FERNET-' . $manualNo]);
        $productoFernetId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO ventas (
                uuid, fecha, cliente_id, user_id, usuario_id, vendedor_id, caja_id, terminal_id,
                total, total_bruto, descuento_total, descuento_monto, medio_pago, monto_pagado,
                monto_cc, vuelto, estado, facturada
            ) VALUES (
                UUID(), NOW(), ?, 1, 1, 1, NULL, 1,
                20150.00, 20150.00, 0.00, 0.00, 'EFECTIVO', 20150.00,
                0.00, 0.00, 'EMITIDA', 1
            )
        ");
        $stmt->execute([$clienteId]);
        $ventaId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO factura_manual_items (
                venta_id, codigo, descripcion, cantidad, precio_unitario, subtotal, iva_porcentaje
            ) VALUES (?, ?, 'Coca 1.5 L', 1.000, 3900.00, 3900.00, 21.00)
        ");
        $stmt->execute([$ventaId, 'IT-MAN-COCA-' . $manualNo]);
        $manualItemId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO factura_manual_items (
                venta_id, codigo, descripcion, cantidad, precio_unitario, subtotal, iva_porcentaje
            ) VALUES (?, ?, 'Fernet Branca 750 cc', 1.000, 16250.00, 16250.00, 21.00)
        ");
        $stmt->execute([$ventaId, 'IT-MAN-FERNET-' . $manualNo]);

        $stmt = $pdo->prepare("
            INSERT INTO documentos_comerciales (
                request_uid, tipo_documento, origen, estado, cliente_id, venta_id,
                nota, medio_pago, total
            ) VALUES (UUID(), 'FACTURA_MANUAL', 'MANUAL', 'EMITIDO', ?, ?, 'Factura manual legacy para NC parcial', 'EFECTIVO', 20150.00)
        ");
        $stmt->execute([$clienteId, $ventaId]);
        $documentoId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO documento_items (
                documento_id, codigo, descripcion, cantidad, precio_unitario, subtotal, iva_porcentaje
            ) VALUES
                (?, ?, 'Coca 1.5 L', 1.000, 3900.00, 3900.00, 21.00),
                (?, ?, 'Fernet Branca 750 cc', 1.000, 16250.00, 16250.00, 21.00)
        ");
        $stmt->execute([$documentoId, 'IT-MAN-COCA-' . $manualNo, $documentoId, 'IT-MAN-FERNET-' . $manualNo]);

        $requestUid = (string)$pdo->query('SELECT UUID()')->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO facturas (
                venta_id, documento_id, cliente_id, naturaleza, tipo, tipo_cbte,
                punto_venta, numero, importe_neto, importe_iva, importe_exento,
                importe_no_gravado, moneda_id, moneda_cotiz, fecha, total, cae,
                cae_vto, estado, estado_fiscal, fiscal_request_uid, fiscal_intentos,
                fiscal_requested_at, fiscal_approved_at, modo, doc_tipo, doc_numero,
                condicion_iva_receptor_id
            ) VALUES (
                ?, ?, ?, 'FACTURA', 'FA', 1,
                1, ?, 16652.89, 3497.11, 0.00,
                0.00, 'PES', 1.000000, NOW(), 20150.00, 'DEMO-MANUAL-CAE',
                DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 10 DAY), '%Y%m%d'),
                'EMITIDA', 'AUTORIZADA', ?, 1,
                NOW(), NOW(), 'demo', 80, ?,
                1
            )
        ");
        $stmt->execute([$ventaId, $documentoId, $clienteId, $facturaNumero, $requestUid, $clienteCuit]);
        $facturaId = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM venta_items WHERE venta_id = ?");
    $stmt->execute([$ventaId]);
    flus_it_assert((int)$stmt->fetchColumn() === 0, 'manual legacy sale intentionally has no venta_items');

    $repo = new PdoFacturaFiscalRepository($pdo);
    $notaCredito = new ArcaNotaCreditoService($pdo, $repo);
    $coordinator = new DbAnulacionFiscalCoordinator($pdo, $repo, $notaCredito);
    $ncRequestUid = (string)$pdo->query('SELECT UUID()')->fetchColumn();
    $outcome = $coordinator->procesarParcial(
        $ventaId,
        [['item_id' => -$manualItemId, 'cantidad' => 1.000]],
        1,
        'NC parcial manual legacy integracion',
        [
            'modo' => 'demo',
            'factura_origen_id' => $facturaId,
            'request_uid' => $ncRequestUid,
        ]
    );

    flus_it_assert($outcome->estadoFiscal === 'APLICADA', 'manual legacy NC partial is applied locally');
    flus_it_assert((int)($outcome->ncFacturaId ?? 0) > 0, 'manual legacy NC partial creates invoice');

    $stmt = $pdo->prepare("
        SELECT
            nc.id AS nc_factura_id,
            nc.naturaleza,
            nc.tipo AS nc_tipo,
            nc.total AS nc_total,
            nc.factura_asociada_id,
            va.tipo AS anulacion_tipo,
            va.estado AS anulacion_estado,
            va.estado_fiscal AS anulacion_estado_fiscal,
            va.monto_total AS anulacion_monto_total,
            v.estado AS venta_estado,
            fi.venta_item_id,
            fi.codigo_snapshot,
            fi.descripcion_snapshot,
            fi.cantidad AS factura_item_cantidad,
            fi.subtotal_total AS factura_item_total,
            COUNT(DISTINCT vai.id) AS anulacion_items,
            COUNT(DISTINCT ms.id) AS movimientos_anulacion,
            COUNT(DISTINCT vi.id) AS venta_items
        FROM facturas nc
        JOIN venta_anulaciones va ON va.nc_factura_id = nc.id
        JOIN ventas v ON v.id = va.venta_id
        JOIN factura_items fi ON fi.factura_id = nc.id
        LEFT JOIN venta_anulacion_items vai ON vai.anulacion_id = va.id
        LEFT JOIN movimientos_stock ms ON ms.venta_id = v.id AND ms.tipo = 'ANULACION'
        LEFT JOIN venta_items vi ON vi.venta_id = v.id
        WHERE nc.id = ?
        GROUP BY nc.id, va.id, v.id, fi.id
    ");
    $stmt->execute([(int)$outcome->ncFacturaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    flus_it_assert((int)($row['nc_factura_id'] ?? 0) === (int)$outcome->ncFacturaId, 'manual legacy NC partial invoice exists');
    flus_it_assert((string)($row['naturaleza'] ?? '') === 'NC', 'manual legacy NC partial uses NC nature');
    flus_it_assert((string)($row['nc_tipo'] ?? '') === 'NCA', 'manual legacy NC partial maps FA to NCA');
    flus_it_assert(round((float)($row['nc_total'] ?? 0), 2) === 3900.00, 'manual legacy NC partial credits selected line total');
    flus_it_assert((int)($row['factura_asociada_id'] ?? 0) === $facturaId, 'manual legacy NC partial links original invoice');
    flus_it_assert((string)($row['anulacion_tipo'] ?? '') === 'PARCIAL', 'manual legacy NC partial keeps partial type');
    flus_it_assert((string)($row['anulacion_estado'] ?? '') === 'CONFIRMADA', 'manual legacy NC partial confirms annulment');
    flus_it_assert((string)($row['anulacion_estado_fiscal'] ?? '') === 'APLICADA', 'manual legacy NC partial stores applied fiscal state');
    flus_it_assert(round((float)($row['anulacion_monto_total'] ?? 0), 2) === 3900.00, 'manual legacy NC partial stores credited amount');
    flus_it_assert((string)($row['venta_estado'] ?? '') === 'PARCIALMENTE_ANULADA', 'manual legacy NC partial keeps sale partially annulled');
    flus_it_assert((int)($row['venta_item_id'] ?? 0) === -$manualItemId, 'manual legacy NC partial stores synthetic manual item id');
    flus_it_assert((string)($row['codigo_snapshot'] ?? '') === 'IT-MAN-COCA-' . $manualNo, 'manual legacy NC partial keeps selected item code');
    flus_it_assert((string)($row['descripcion_snapshot'] ?? '') === 'Coca 1.5 L', 'manual legacy NC partial keeps selected item description');
    flus_it_assert(round((float)($row['factura_item_cantidad'] ?? 0), 3) === 1.000, 'manual legacy NC partial credits one unit');
    flus_it_assert(round((float)($row['factura_item_total'] ?? 0), 2) === 3900.00, 'manual legacy NC partial fiscal item total is 3900.00');
    flus_it_assert((int)($row['anulacion_items'] ?? 0) === 0, 'manual legacy NC partial does not write venta_anulacion_items without real venta_items');
    flus_it_assert((int)($row['movimientos_anulacion'] ?? 0) === 0, 'manual legacy NC partial does not restore stock');
    flus_it_assert((int)($row['venta_items'] ?? 0) === 0, 'manual legacy NC partial does not backfill venta_items');

    $stmt = $pdo->prepare("SELECT stock FROM productos WHERE id = ?");
    $stmt->execute([$productoCocaId]);
    flus_it_assert(round((float)$stmt->fetchColumn(), 3) === 7.000, 'manual legacy NC partial leaves selected product stock unchanged');

    $stmt = $pdo->prepare("SELECT stock FROM productos WHERE id = ?");
    $stmt->execute([$productoFernetId]);
    flus_it_assert(round((float)$stmt->fetchColumn(), 3) === 5.000, 'manual legacy NC partial leaves non-selected product stock unchanged');
}

function flus_it_run_non_remote_recovery_case(PDO $pdo, array $fiscalCase): void
{
    $ventaId = (int)($fiscalCase['venta_id'] ?? 0);
    $clienteId = (int)($fiscalCase['cliente_id'] ?? 0);
    $facturaId = (int)($fiscalCase['factura_id'] ?? 0);
    $documentoId = (int)($fiscalCase['documento_id'] ?? 0);
    $facturaNumero = (int)($fiscalCase['factura_numero'] ?? 0);
    $requestUid = trim((string)($fiscalCase['fiscal_request_uid'] ?? ''));

    flus_it_assert($ventaId > 0 && $clienteId > 0 && $facturaId > 0 && $requestUid !== '', 'non-remote recovery has fiscal attempt ids');

    $pdo->beginTransaction();

    try {
        $transientMessage = 'No se puede emitir ahora porque ARCA no responde.';

        $stmt = $pdo->prepare("
            UPDATE facturas
            SET cae = NULL,
                cae_vto = NULL,
                estado = 'PENDIENTE',
                estado_fiscal = 'ERROR_TRANSITORIO',
                fiscal_error_code = 'TRANSIENT',
                fiscal_error_message = ?,
                fiscal_approved_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$transientMessage, $facturaId]);

        $stmt = $pdo->prepare("
            UPDATE factura_eventos_arca
            SET operacion = 'FACTURA_MANUAL',
                resultado = 'ERROR',
                error_code = 'TRANSIENT',
                error_message = ?,
                response_json = '{\"error\":\"ARCA no responde\"}',
                finished_at = NOW()
            WHERE request_uid = ?
        ");
        $stmt->execute([$transientMessage, $requestUid]);

        $stmt = $pdo->prepare("
            UPDATE factura_eventos_arca
            SET operacion = 'FACTURA_RECOVERY',
                resultado = 'OK',
                error_code = NULL,
                error_message = NULL,
                response_json = ?,
                finished_at = NOW()
            WHERE request_uid = ?
        ");
        $stmt->execute([
            json_encode([
                'cae' => 'DEMO-RECOVERY-CAE',
                'numero' => $facturaNumero,
                'cae_vto' => date('Ymd', strtotime('+10 days')),
                'recovered' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $requestUid,
        ]);

        $stmt = $pdo->prepare("
            UPDATE facturas
            SET cae = 'DEMO-RECOVERY-CAE',
                cae_vto = DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 10 DAY), '%Y-%m-%d'),
                estado = 'EMITIDA',
                estado_fiscal = 'RECUPERADA',
                fiscal_intentos = fiscal_intentos + 1,
                fiscal_error_code = NULL,
                fiscal_error_message = NULL,
                fiscal_approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$facturaId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stmt = $pdo->prepare("
        SELECT
            f.id AS factura_id,
            f.estado,
            f.estado_fiscal,
            f.cae,
            f.cae_vto,
            f.fiscal_error_code,
            f.fiscal_error_message,
            dc.estado AS documento_estado,
            COUNT(DISTINCT fi.id) AS factura_items,
            COUNT(DISTINCT fea.id) AS eventos,
            MAX(fea.operacion) AS evento_operacion,
            MAX(fea.resultado) AS evento_resultado,
            MAX(fea.error_code) AS evento_error_code
        FROM facturas f
        JOIN documentos_comerciales dc ON dc.id = f.documento_id
        JOIN factura_items fi ON fi.factura_id = f.id
        LEFT JOIN factura_eventos_arca fea ON fea.factura_id = f.id AND fea.request_uid = ?
        WHERE f.id = ? AND f.documento_id = ?
        GROUP BY f.id, dc.id
    ");
    $stmt->execute([$requestUid, $facturaId, $documentoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    flus_it_assert((int)($row['factura_id'] ?? 0) === $facturaId, 'non-remote recovery invoice exists');
    flus_it_assert((string)($row['estado'] ?? '') === 'EMITIDA', 'non-remote recovery keeps invoice emitted');
    flus_it_assert((string)($row['estado_fiscal'] ?? '') === 'RECUPERADA', 'non-remote recovery marks invoice as recovered');
    flus_it_assert((string)($row['cae'] ?? '') === 'DEMO-RECOVERY-CAE', 'non-remote recovery persists recovered CAE');
    flus_it_assert(trim((string)($row['cae_vto'] ?? '')) !== '', 'non-remote recovery persists CAE expiration');
    flus_it_assert((string)($row['fiscal_error_code'] ?? '') === '', 'non-remote recovery clears invoice error code');
    flus_it_assert((string)($row['fiscal_error_message'] ?? '') === '', 'non-remote recovery clears invoice error message');
    flus_it_assert((string)($row['documento_estado'] ?? '') === 'EMITIDO', 'non-remote recovery keeps document emitted');
    flus_it_assert((int)($row['factura_items'] ?? 0) === 1, 'non-remote recovery keeps one fiscal item');
    flus_it_assert((int)($row['eventos'] ?? 0) === 1, 'non-remote recovery keeps one local ARCA event');
    flus_it_assert((string)($row['evento_operacion'] ?? '') === 'FACTURA_RECOVERY', 'non-remote recovery rewrites event operation');
    flus_it_assert((string)($row['evento_resultado'] ?? '') === 'OK', 'non-remote recovery marks event as OK');
    flus_it_assert((string)($row['evento_error_code'] ?? '') === '', 'non-remote recovery clears event error code');
}

function flus_it_run_cobranza_receipt_case(PDO $pdo, array $posSale, array $fiscalCase): void
{
    $ventaId = (int)($fiscalCase['venta_id'] ?? 0);
    $clienteId = (int)($fiscalCase['cliente_id'] ?? 0);
    $facturaId = (int)($fiscalCase['factura_id'] ?? 0);
    $documentoId = (int)($fiscalCase['documento_id'] ?? 0);
    $cajaId = (int)($posSale['caja_id'] ?? 0);

    flus_it_assert(flus_cobranzas_tables_ready($pdo), 'cobranzas tables are ready');
    flus_it_assert(flus_cobranzas_receipts_ready($pdo), 'receipt tables are ready');
    flus_it_assert($ventaId > 0 && $clienteId > 0 && $facturaId > 0 && $documentoId > 0, 'cobranza receipt case has fiscal target ids');

    $cobranzaId = flus_cobranzas_register_sale_payment($pdo, [
        'venta_id' => $ventaId,
        'cliente_id' => $clienteId,
        'caja_id' => $cajaId,
        'medio_pago' => 'EFECTIVO',
        'monto' => 150.00,
        'documento_id' => $documentoId,
        'factura_id' => $facturaId,
        'referencia' => 'COB-IT-VENTA',
        'observaciones' => 'Cobranza integracion venta facturada',
        'created_by' => 1,
    ]);

    flus_it_assert($cobranzaId > 0, 'sale cobranza is registered');

    $sameCobranzaId = flus_cobranzas_register_sale_payment($pdo, [
        'venta_id' => $ventaId,
        'cliente_id' => $clienteId,
        'caja_id' => $cajaId,
        'medio_pago' => 'EFECTIVO',
        'monto' => 150.00,
        'documento_id' => $documentoId,
        'factura_id' => $facturaId,
        'referencia' => 'COB-IT-VENTA',
        'observaciones' => 'Cobranza integracion venta facturada',
        'created_by' => 1,
    ]);

    flus_it_assert($sameCobranzaId === $cobranzaId, 'sale cobranza registration is idempotent by external key');

    flus_cobranzas_link_factura_from_sale($pdo, $ventaId, $facturaId, $documentoId);

    $receipt = flus_cobranzas_attach_receipt_to_cobranza($pdo, $cobranzaId, [
        'cliente_id' => $clienteId,
        'factura_id' => $facturaId,
        'documento_id' => $documentoId,
        'monto' => 150.00,
    ]);

    $reciboDocumentoId = (int)($receipt['recibo_documento_id'] ?? 0);
    $reciboAplicacionId = (int)($receipt['recibo_aplicacion_id'] ?? 0);
    flus_it_assert($reciboDocumentoId > 0 && $reciboAplicacionId > 0, 'sale cobranza receipt is attached');

    $sameReceipt = flus_cobranzas_attach_receipt_to_cobranza($pdo, $cobranzaId, [
        'cliente_id' => $clienteId,
        'factura_id' => $facturaId,
        'documento_id' => $documentoId,
        'monto' => 150.00,
    ]);

    flus_it_assert((int)($sameReceipt['recibo_documento_id'] ?? 0) === $reciboDocumentoId, 'sale cobranza receipt document is idempotent');
    flus_it_assert((int)($sameReceipt['recibo_aplicacion_id'] ?? 0) === $reciboAplicacionId, 'sale cobranza receipt application is idempotent');

    $stmt = $pdo->prepare("
        SELECT
            c.id AS cobranza_id,
            c.origen,
            c.estado AS cobranza_estado,
            c.medio_pago,
            c.importe_total,
            c.recibo_documento_id,
            ca.tipo_aplicacion AS cobranza_tipo_aplicacion,
            ca.factura_id AS cobranza_factura_id,
            ca.documento_id AS cobranza_documento_id,
            ca.monto AS cobranza_monto,
            rd.tipo_documento AS recibo_tipo_documento,
            rd.origen AS recibo_origen,
            rd.estado AS recibo_estado,
            rd.total AS recibo_total,
            ra.tipo_aplicacion AS recibo_tipo_aplicacion,
            ra.factura_id AS recibo_factura_id,
            ra.documento_id AS recibo_documento_aplicado_id,
            ra.monto AS recibo_monto
        FROM cobranzas c
        JOIN cobranza_aplicaciones ca ON ca.cobranza_id = c.id
        JOIN documentos_comerciales rd ON rd.id = c.recibo_documento_id
        JOIN recibo_aplicaciones ra ON ra.cobranza_id = c.id AND ra.recibo_documento_id = rd.id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$cobranzaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    flus_it_assert((int)($row['cobranza_id'] ?? 0) === $cobranzaId, 'sale cobranza row exists');
    flus_it_assert((string)($row['origen'] ?? '') === 'VENTA', 'sale cobranza keeps sale origin');
    flus_it_assert((string)($row['cobranza_estado'] ?? '') === 'ACTIVA', 'sale cobranza is active');
    flus_it_assert((string)($row['medio_pago'] ?? '') === 'EFECTIVO', 'sale cobranza keeps payment method');
    flus_it_assert(round((float)($row['importe_total'] ?? 0), 2) === 150.00, 'sale cobranza total is 150.00');
    flus_it_assert((int)($row['recibo_documento_id'] ?? 0) === $reciboDocumentoId, 'sale cobranza links receipt document');
    flus_it_assert((string)($row['cobranza_tipo_aplicacion'] ?? '') === 'VENTA', 'sale cobranza application targets sale');
    flus_it_assert((int)($row['cobranza_factura_id'] ?? 0) === $facturaId, 'sale cobranza application links invoice');
    flus_it_assert((int)($row['cobranza_documento_id'] ?? 0) === $documentoId, 'sale cobranza application links document');
    flus_it_assert(round((float)($row['cobranza_monto'] ?? 0), 2) === 150.00, 'sale cobranza application amount is 150.00');
    flus_it_assert((string)($row['recibo_tipo_documento'] ?? '') === 'RECIBO', 'sale cobranza receipt is a commercial receipt');
    flus_it_assert((string)($row['recibo_origen'] ?? '') === 'COBRANZA', 'sale cobranza receipt keeps cobranza origin');
    flus_it_assert((string)($row['recibo_estado'] ?? '') === 'EMITIDO', 'sale cobranza receipt is emitted');
    flus_it_assert(round((float)($row['recibo_total'] ?? 0), 2) === 150.00, 'sale cobranza receipt total is 150.00');
    flus_it_assert((string)($row['recibo_tipo_aplicacion'] ?? '') === 'FACTURA', 'sale cobranza receipt applies to invoice');
    flus_it_assert((int)($row['recibo_factura_id'] ?? 0) === $facturaId, 'sale cobranza receipt links invoice');
    flus_it_assert((int)($row['recibo_documento_aplicado_id'] ?? 0) === $documentoId, 'sale cobranza receipt links original document');
    flus_it_assert(round((float)($row['recibo_monto'] ?? 0), 2) === 150.00, 'sale cobranza receipt amount is 150.00');

    $cobranzasByFactura = flus_cobranzas_fetch_by_factura($pdo, $facturaId);
    $receiptsByFactura = flus_cobranzas_fetch_receipts_by_factura($pdo, $facturaId, $documentoId);
    flus_it_assert(count($cobranzasByFactura) === 1, 'sale cobranza is fetchable by invoice');
    flus_it_assert(count($receiptsByFactura) === 1, 'sale cobranza receipt is fetchable by invoice');
}

function flus_it_run_invoice_direct_payment_case(PDO $pdo, array $posSale, array $fiscalCase): void
{
    $ventaId = (int)($fiscalCase['venta_id'] ?? 0);
    $clienteId = (int)($fiscalCase['cliente_id'] ?? 0);
    $facturaId = (int)($fiscalCase['factura_id'] ?? 0);
    $documentoId = (int)($fiscalCase['documento_id'] ?? 0);
    $cajaId = (int)($posSale['caja_id'] ?? 0);

    flus_it_assert($ventaId > 0 && $clienteId > 0 && $facturaId > 0 && $documentoId > 0, 'direct invoice payment case has fiscal target ids');
    flus_it_assert($cajaId > 0, 'direct invoice payment case has open caja id');

    $stFactura = $pdo->prepare('SELECT * FROM facturas WHERE id = ? LIMIT 1');
    $stFactura->execute([$facturaId]);
    $factura = $stFactura->fetch(PDO::FETCH_ASSOC) ?: [];
    flus_it_assert((int)($factura['id'] ?? 0) === $facturaId, 'direct invoice payment invoice exists');

    $before = flus_cobranzas_resumen_para_factura($pdo, $factura);
    flus_it_assert((string)($before['estado'] ?? '') === 'SIN_COBRAR', 'direct invoice payment starts unpaid');
    flus_it_assert(round((float)($before['saldo'] ?? 0), 2) === 150.00, 'direct invoice payment starts with full balance');

    $requestUid = (string)$pdo->query('SELECT UUID()')->fetchColumn();
    $first = flus_cobranzas_register_invoice_payment($pdo, [
        'factura_id' => $facturaId,
        'monto' => 80.00,
        'medio_pago' => 'TRANSFERENCIA',
        'referencia' => 'FAC-IT-PAGO-1',
        'observaciones' => 'Cobro directo integracion factura',
        'request_uid' => $requestUid,
        'caja_id' => $cajaId,
        'registrar_caja_mov' => true,
        'created_by' => 1,
        'usuario_nombre' => 'integracion',
    ]);

    flus_it_assert(($first['success'] ?? false) === true, 'direct invoice payment succeeds');
    flus_it_assert((int)($first['cobranza_id'] ?? 0) > 0, 'direct invoice payment creates cobranza');
    flus_it_assert((int)($first['recibo_documento_id'] ?? 0) > 0, 'direct invoice payment creates receipt document');
    flus_it_assert((int)($first['recibo_aplicacion_id'] ?? 0) > 0, 'direct invoice payment creates receipt application');
    flus_it_assert((int)($first['caja_movimiento_id'] ?? 0) > 0, 'direct invoice payment creates caja movement');

    $same = flus_cobranzas_register_invoice_payment($pdo, [
        'factura_id' => $facturaId,
        'monto' => 80.00,
        'medio_pago' => 'TRANSFERENCIA',
        'referencia' => 'FAC-IT-PAGO-1',
        'observaciones' => 'Cobro directo integracion factura',
        'request_uid' => $requestUid,
        'caja_id' => $cajaId,
        'registrar_caja_mov' => true,
        'created_by' => 1,
        'usuario_nombre' => 'integracion',
    ]);

    flus_it_assert(($same['success'] ?? false) === true, 'direct invoice payment duplicate succeeds');
    flus_it_assert((int)($same['cobranza_id'] ?? 0) === (int)($first['cobranza_id'] ?? 0), 'direct invoice payment duplicate reuses cobranza');
    flus_it_assert((int)($same['recibo_documento_id'] ?? 0) === (int)($first['recibo_documento_id'] ?? 0), 'direct invoice payment duplicate reuses receipt');

    $stmt = $pdo->prepare("
        SELECT
            c.origen,
            c.estado AS cobranza_estado,
            c.medio_pago,
            c.importe_total,
            c.caja_movimiento_id,
            ca.tipo_aplicacion AS cobranza_tipo_aplicacion,
            ca.factura_id AS cobranza_factura_id,
            ca.documento_id AS cobranza_documento_id,
            rd.tipo_documento AS recibo_tipo_documento,
            rd.origen AS recibo_origen,
            rd.estado AS recibo_estado,
            rd.total AS recibo_total,
            ra.tipo_aplicacion AS recibo_tipo_aplicacion,
            ra.factura_id AS recibo_factura_id,
            ra.documento_id AS recibo_documento_id,
            ra.monto AS recibo_monto
        FROM cobranzas c
        JOIN cobranza_aplicaciones ca ON ca.cobranza_id = c.id
        JOIN documentos_comerciales rd ON rd.id = c.recibo_documento_id
        JOIN recibo_aplicaciones ra ON ra.cobranza_id = c.id AND ra.recibo_documento_id = rd.id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)($first['cobranza_id'] ?? 0)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    flus_it_assert((string)($row['origen'] ?? '') === 'FACTURA', 'direct invoice payment creates invoice cobranza');
    flus_it_assert((string)($row['cobranza_estado'] ?? '') === 'ACTIVA', 'direct invoice payment cobranza is active');
    flus_it_assert((string)($row['medio_pago'] ?? '') === 'TRANSFERENCIA', 'direct invoice payment keeps method');
    flus_it_assert(round((float)($row['importe_total'] ?? 0), 2) === 80.00, 'direct invoice payment total is 80.00');
    flus_it_assert((int)($row['caja_movimiento_id'] ?? 0) === (int)($first['caja_movimiento_id'] ?? 0), 'direct invoice payment links caja movement');
    flus_it_assert((string)($row['cobranza_tipo_aplicacion'] ?? '') === 'FACTURA', 'direct invoice payment application targets invoice');
    flus_it_assert((int)($row['cobranza_factura_id'] ?? 0) === $facturaId, 'direct invoice payment application links invoice');
    flus_it_assert((int)($row['cobranza_documento_id'] ?? 0) === $documentoId, 'direct invoice payment application links document');
    flus_it_assert((string)($row['recibo_tipo_documento'] ?? '') === 'RECIBO', 'direct invoice payment receipt is commercial receipt');
    flus_it_assert((string)($row['recibo_origen'] ?? '') === 'COBRANZA', 'direct invoice payment receipt keeps origin');
    flus_it_assert((string)($row['recibo_estado'] ?? '') === 'EMITIDO', 'direct invoice payment receipt is emitted');
    flus_it_assert(round((float)($row['recibo_total'] ?? 0), 2) === 80.00, 'direct invoice payment receipt total is 80.00');
    flus_it_assert((string)($row['recibo_tipo_aplicacion'] ?? '') === 'FACTURA', 'direct invoice payment receipt applies to invoice');
    flus_it_assert((int)($row['recibo_factura_id'] ?? 0) === $facturaId, 'direct invoice payment receipt links invoice');
    flus_it_assert((int)($row['recibo_documento_id'] ?? 0) === $documentoId, 'direct invoice payment receipt links document');
    flus_it_assert(round((float)($row['recibo_monto'] ?? 0), 2) === 80.00, 'direct invoice payment receipt amount is 80.00');

    $after = flus_cobranzas_resumen_para_factura($pdo, $factura);
    flus_it_assert((string)($after['estado'] ?? '') === 'PARCIAL', 'direct invoice payment leaves partial state');
    flus_it_assert(round((float)($after['cobrado'] ?? 0), 2) === 80.00, 'direct invoice payment summary shows paid amount');
    flus_it_assert(round((float)($after['saldo'] ?? 0), 2) === 70.00, 'direct invoice payment summary shows remaining balance');

    $panel = flus_cobranzas_panel_read($pdo, [
        'estado_cobro' => 'PARCIAL',
        'cliente_id' => $clienteId,
        'per_page' => 100,
        'page' => 1,
    ]);
    $panelRow = null;
    foreach (($panel['rows'] ?? []) as $candidate) {
        if ((int)($candidate['id'] ?? 0) === $facturaId) {
            $panelRow = $candidate;
            break;
        }
    }
    flus_it_assert(is_array($panelRow), 'direct invoice payment appears in cobranzas panel');
    flus_it_assert((string)($panelRow['estado_cobro'] ?? '') === 'PARCIAL', 'direct invoice payment panel keeps partial state');
    flus_it_assert(round((float)($panelRow['cobrado'] ?? 0), 2) === 80.00, 'direct invoice payment panel shows paid amount');
    flus_it_assert(round((float)($panelRow['saldo'] ?? 0), 2) === 70.00, 'direct invoice payment panel shows remaining balance');

    $excess = flus_cobranzas_register_invoice_payment($pdo, [
        'factura_id' => $facturaId,
        'monto' => 71.00,
        'medio_pago' => 'EFECTIVO',
        'request_uid' => (string)$pdo->query('SELECT UUID()')->fetchColumn(),
        'created_by' => 1,
    ]);
    flus_it_assert(($excess['success'] ?? false) === false, 'direct invoice payment rejects amount over balance');
}

function flus_it_run_cuenta_corriente_case(PDO $pdo, array $fiscalCase): void
{
    $ventaId = (int)($fiscalCase['venta_id'] ?? 0);
    $clienteId = (int)($fiscalCase['cliente_id'] ?? 0);
    $facturaId = (int)($fiscalCase['factura_id'] ?? 0);
    $documentoId = (int)($fiscalCase['documento_id'] ?? 0);

    flus_it_assert($ventaId > 0 && $clienteId > 0 && $facturaId > 0 && $documentoId > 0, 'cuenta corriente case has fiscal target ids');
    flus_it_assert(flus_it_table_has_column($pdo, 'cuenta_corriente_movimientos', 'request_uid'), 'cuenta_corriente_movimientos.request_uid exists');

    $cc = new CuentaCorrienteController($pdo);
    $habilitar = $cc->habilitarCC($clienteId, 500.00);
    flus_it_assert(($habilitar['success'] ?? false) === true, 'cuenta corriente enables customer');

    $cargo = $cc->registrarCargo(
        $clienteId,
        150.00,
        1,
        $ventaId,
        'Cargo CC integracion venta facturada',
        null,
        ['terminal_id' => 1, 'ip' => '127.0.0.1']
    );
    flus_it_assert(($cargo['success'] ?? false) === true, 'cuenta corriente cargo is registered');
    flus_it_assert(round((float)($cargo['saldo_anterior'] ?? -1), 2) === 0.00, 'cuenta corriente cargo starts from zero balance');
    flus_it_assert(round((float)($cargo['saldo_posterior'] ?? 0), 2) === 150.00, 'cuenta corriente cargo leaves 150.00 balance');

    $requestUid = (string)$pdo->query('SELECT UUID()')->fetchColumn();
    $pago = $cc->registrarPago(
        $clienteId,
        150.00,
        'TRANSFERENCIA',
        1,
        'CC-IT-PAGO',
        'Pago CC integracion factura',
        [
            'terminal_id' => 1,
            'ip' => '127.0.0.1',
            'request_uid' => $requestUid,
            'factura_id' => $facturaId,
            'documento_id' => $documentoId,
        ]
    );
    flus_it_assert(($pago['success'] ?? false) === true, 'cuenta corriente payment is registered');
    flus_it_assert(round((float)($pago['saldo_anterior'] ?? 0), 2) === 150.00, 'cuenta corriente payment starts from 150.00 balance');
    flus_it_assert(round((float)($pago['saldo_posterior'] ?? -1), 2) === 0.00, 'cuenta corriente payment clears customer balance');
    flus_it_assert((int)($pago['cobranza_id'] ?? 0) > 0, 'cuenta corriente payment creates cobranza');
    flus_it_assert((int)($pago['recibo_documento_id'] ?? 0) > 0, 'cuenta corriente payment creates receipt document');
    flus_it_assert((int)($pago['recibo_aplicacion_id'] ?? 0) > 0, 'cuenta corriente payment creates receipt application');
    flus_it_assert((string)($pago['recibo_tipo_aplicacion'] ?? '') === 'FACTURA', 'cuenta corriente payment receipt applies to invoice');

    $samePago = $cc->registrarPago(
        $clienteId,
        150.00,
        'TRANSFERENCIA',
        1,
        'CC-IT-PAGO',
        'Pago CC integracion factura',
        [
            'terminal_id' => 1,
            'ip' => '127.0.0.1',
            'request_uid' => $requestUid,
            'factura_id' => $facturaId,
            'documento_id' => $documentoId,
        ]
    );
    flus_it_assert(($samePago['success'] ?? false) === true, 'cuenta corriente duplicate payment succeeds idempotently');
    flus_it_assert((int)($samePago['movimiento_id'] ?? 0) === (int)($pago['movimiento_id'] ?? 0), 'cuenta corriente duplicate payment reuses movement');
    flus_it_assert((int)($samePago['cobranza_id'] ?? 0) === (int)($pago['cobranza_id'] ?? 0), 'cuenta corriente duplicate payment reuses cobranza');
    flus_it_assert((int)($samePago['recibo_documento_id'] ?? 0) === (int)($pago['recibo_documento_id'] ?? 0), 'cuenta corriente duplicate payment reuses receipt document');
    flus_it_assert((int)($samePago['recibo_aplicacion_id'] ?? 0) === (int)($pago['recibo_aplicacion_id'] ?? 0), 'cuenta corriente duplicate payment reuses receipt application');

    $stmt = $pdo->prepare("
        SELECT
            c.cc_saldo,
            c.cc_fecha_ultimo_pago,
            COUNT(DISTINCT cargo.id) AS cargos,
            COUNT(DISTINCT pago.id) AS pagos,
            MAX(pago.request_uid) AS pago_request_uid,
            cb.origen AS cobranza_origen,
            cb.cc_movimiento_id AS cobranza_cc_movimiento_id,
            cb.medio_pago AS cobranza_medio_pago,
            cb.importe_total AS cobranza_importe_total,
            rd.tipo_documento AS recibo_tipo_documento,
            rd.estado AS recibo_estado,
            rd.total AS recibo_total,
            ra.tipo_aplicacion AS recibo_tipo_aplicacion,
            ra.cc_movimiento_id AS recibo_cc_movimiento_id,
            ra.factura_id AS recibo_factura_id,
            ra.documento_id AS recibo_documento_id,
            ra.monto AS recibo_monto
        FROM clientes c
        LEFT JOIN cuenta_corriente_movimientos cargo ON cargo.cliente_id = c.id AND cargo.tipo = 'CARGO'
        LEFT JOIN cuenta_corriente_movimientos pago ON pago.cliente_id = c.id AND pago.tipo = 'PAGO'
        LEFT JOIN cobranzas cb ON cb.cc_movimiento_id = pago.id
        LEFT JOIN documentos_comerciales rd ON rd.id = cb.recibo_documento_id
        LEFT JOIN recibo_aplicaciones ra ON ra.cobranza_id = cb.id AND ra.recibo_documento_id = rd.id
        WHERE c.id = ?
        GROUP BY c.id, cb.id, rd.id, ra.id
    ");
    $stmt->execute([$clienteId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    flus_it_assert(round((float)($row['cc_saldo'] ?? -1), 2) === 0.00, 'cuenta corriente customer balance is zero after payment');
    flus_it_assert(trim((string)($row['cc_fecha_ultimo_pago'] ?? '')) !== '', 'cuenta corriente stores last payment date');
    flus_it_assert((int)($row['cargos'] ?? 0) === 1, 'cuenta corriente has one active cargo');
    flus_it_assert((int)($row['pagos'] ?? 0) === 1, 'cuenta corriente has one active payment');
    flus_it_assert((string)($row['pago_request_uid'] ?? '') === $requestUid, 'cuenta corriente payment stores request uid');
    flus_it_assert((string)($row['cobranza_origen'] ?? '') === 'CC_PAGO', 'cuenta corriente payment creates CC cobranza');
    flus_it_assert((int)($row['cobranza_cc_movimiento_id'] ?? 0) === (int)($pago['movimiento_id'] ?? 0), 'cuenta corriente cobranza links payment movement');
    flus_it_assert((string)($row['cobranza_medio_pago'] ?? '') === 'TRANSFERENCIA', 'cuenta corriente cobranza keeps payment method');
    flus_it_assert(round((float)($row['cobranza_importe_total'] ?? 0), 2) === 150.00, 'cuenta corriente cobranza total is 150.00');
    flus_it_assert((string)($row['recibo_tipo_documento'] ?? '') === 'RECIBO', 'cuenta corriente payment receipt is a commercial receipt');
    flus_it_assert((string)($row['recibo_estado'] ?? '') === 'EMITIDO', 'cuenta corriente payment receipt is emitted');
    flus_it_assert(round((float)($row['recibo_total'] ?? 0), 2) === 150.00, 'cuenta corriente payment receipt total is 150.00');
    flus_it_assert((string)($row['recibo_tipo_aplicacion'] ?? '') === 'FACTURA', 'cuenta corriente receipt applies to invoice');
    flus_it_assert((int)($row['recibo_cc_movimiento_id'] ?? 0) === (int)($pago['movimiento_id'] ?? 0), 'cuenta corriente receipt links payment movement');
    flus_it_assert((int)($row['recibo_factura_id'] ?? 0) === $facturaId, 'cuenta corriente receipt links invoice');
    flus_it_assert((int)($row['recibo_documento_id'] ?? 0) === $documentoId, 'cuenta corriente receipt links document');
    flus_it_assert(round((float)($row['recibo_monto'] ?? 0), 2) === 150.00, 'cuenta corriente receipt amount is 150.00');

    $recalculo = $cc->recalcularSaldo($clienteId);
    flus_it_assert(($recalculo['success'] ?? false) === true, 'cuenta corriente recalculates balance');
    flus_it_assert(round((float)($recalculo['saldo_calculado'] ?? -1), 2) === 0.00, 'cuenta corriente recalculated balance is zero');
}

function flus_it_tesoreria_categoria_id(PDO $pdo, string $slug): int
{
    $st = $pdo->prepare('SELECT id FROM tesoreria_categorias WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    return (int)($st->fetchColumn() ?: 0);
}

function flus_it_run_tesoreria_v1_case(PDO $pdo): void
{
    flus_it_assert(flus_tesoreria_tables_ready($pdo), 'tesoreria tables are ready');

    $banco = flus_tesoreria_save_cuenta($pdo, [
        'nombre' => 'Banco integracion',
        'tipo' => 'BANCO',
        'saldo_inicial' => 10000.00,
        'estado' => 'ACTIVA',
    ]);
    $caja = flus_tesoreria_save_cuenta($pdo, [
        'nombre' => 'Caja central integracion',
        'tipo' => 'CAJA',
        'saldo_inicial' => 3000.00,
        'estado' => 'ACTIVA',
    ]);
    $bancoId = (int)($banco['cuenta_id'] ?? 0);
    $cajaId = (int)($caja['cuenta_id'] ?? 0);
    flus_it_assert($bancoId > 0 && $cajaId > 0, 'tesoreria creates financial accounts');

    $catAlquiler = flus_it_tesoreria_categoria_id($pdo, 'alquiler');
    $catImpuestos = flus_it_tesoreria_categoria_id($pdo, 'impuestos');
    $catServicios = flus_it_tesoreria_categoria_id($pdo, 'servicios');
    flus_it_assert($catAlquiler > 0 && $catImpuestos > 0 && $catServicios > 0, 'tesoreria default categories exist');

    $alquiler = flus_tesoreria_registrar_movimiento($pdo, [
        'tipo' => 'EGRESO',
        'cuenta_origen_id' => $bancoId,
        'categoria_id' => $catAlquiler,
        'fecha' => date('Y-m-d'),
        'importe' => 1200.00,
        'concepto' => 'Alquiler local centro',
        'sucursal_nombre' => 'Centro',
        'created_by' => 1,
        'request_uid' => 'tes-it-alquiler',
    ]);
    flus_it_assert(($alquiler['success'] ?? false) === true, 'tesoreria registers rent expense from bank');

    $impuesto = flus_tesoreria_registrar_movimiento($pdo, [
        'tipo' => 'EGRESO',
        'cuenta_origen_id' => $bancoId,
        'categoria_id' => $catImpuestos,
        'fecha' => date('Y-m-d'),
        'importe' => 500.00,
        'concepto' => 'Impuesto general',
        'created_by' => 1,
        'request_uid' => 'tes-it-impuesto',
    ]);
    flus_it_assert(($impuesto['success'] ?? false) === true, 'tesoreria registers general tax expense');

    $servicio = flus_tesoreria_registrar_movimiento($pdo, [
        'tipo' => 'EGRESO',
        'cuenta_origen_id' => $bancoId,
        'categoria_id' => $catServicios,
        'fecha' => date('Y-m-d'),
        'importe' => 300.00,
        'concepto' => 'Internet sucursal Godoy Cruz',
        'sucursal_nombre' => 'Godoy Cruz',
        'created_by' => 1,
        'request_uid' => 'tes-it-servicio',
    ]);
    flus_it_assert(($servicio['success'] ?? false) === true, 'tesoreria registers branch service expense');

    $transfer = flus_tesoreria_registrar_movimiento($pdo, [
        'tipo' => 'TRANSFERENCIA',
        'cuenta_origen_id' => $cajaId,
        'cuenta_destino_id' => $bancoId,
        'fecha' => date('Y-m-d'),
        'importe' => '1.000',
        'concepto' => 'Deposito de caja a banco',
        'created_by' => 1,
        'request_uid' => 'tes-it-transfer',
    ]);
    flus_it_assert(($transfer['success'] ?? false) === true, 'tesoreria registers internal transfer');

    $saldos = flus_tesoreria_cuentas($pdo, true);
    $saldoBanco = 0.0;
    $saldoCaja = 0.0;
    foreach ($saldos as $cuenta) {
        if ((int)$cuenta['id'] === $bancoId) {
            $saldoBanco = round((float)$cuenta['saldo_actual'], 2);
        }
        if ((int)$cuenta['id'] === $cajaId) {
            $saldoCaja = round((float)$cuenta['saldo_actual'], 2);
        }
    }
    flus_it_assert($saldoBanco === 9000.00 && $saldoCaja === 2000.00, 'tesoreria transfer does not inflate total balances');

    $ob = flus_tesoreria_save_obligacion($pdo, [
        'descripcion' => 'Alquiler a pagar integracion',
        'categoria_id' => $catAlquiler,
        'fecha_vencimiento' => date('Y-m-d', strtotime('+5 days')),
        'importe_estimado' => 800.00,
        'cuenta_sugerida_id' => $bancoId,
        'sucursal_nombre' => 'Centro',
        'created_by' => 1,
    ]);
    $obId = (int)($ob['obligacion_id'] ?? 0);
    flus_it_assert($obId > 0, 'tesoreria creates rent obligation');

    $pago = flus_tesoreria_pagar_obligacion($pdo, $obId, [
        'cuenta_origen_id' => $bancoId,
        'importe' => 800.00,
        'fecha' => date('Y-m-d'),
        'request_uid' => 'tes-it-pago-obligacion',
    ]);
    flus_it_assert(($pago['success'] ?? false) === true, 'tesoreria pays obligation with expense movement');
    flus_it_assert((string)($pago['estado'] ?? '') === 'PAGADO', 'tesoreria marks paid obligation as paid');

    $dup = flus_tesoreria_pagar_obligacion($pdo, $obId, [
        'cuenta_origen_id' => $bancoId,
        'importe' => 800.00,
        'fecha' => date('Y-m-d'),
        'request_uid' => 'tes-it-pago-obligacion-dup',
    ]);
    flus_it_assert(($dup['success'] ?? false) === false, 'tesoreria does not duplicate paid obligation');
    $stDup = $pdo->prepare('SELECT COUNT(*) FROM tesoreria_movimientos WHERE obligacion_id = ?');
    $stDup->execute([$obId]);
    flus_it_assert((int)$stDup->fetchColumn() === 1, 'tesoreria paid obligation has one payment movement');

    flus_tesoreria_save_obligacion($pdo, [
        'descripcion' => 'Internet proximo integracion',
        'categoria_id' => $catServicios,
        'fecha_vencimiento' => date('Y-m-d', strtotime('+7 days')),
        'importe_estimado' => 250.00,
        'sucursal_nombre' => 'Godoy Cruz',
        'created_by' => 1,
    ]);
    flus_tesoreria_save_obligacion($pdo, [
        'descripcion' => 'Impuesto vencido integracion',
        'categoria_id' => $catImpuestos,
        'fecha_vencimiento' => date('Y-m-d', strtotime('-1 day')),
        'importe_estimado' => 700.00,
        'created_by' => 1,
    ]);

    $report = flus_tesoreria_reportes($pdo, ['desde' => date('Y-m-01'), 'hasta' => date('Y-m-t')]);
    flus_it_assert((float)($report['flujo']['egresos'] ?? 0) === 2800.00, 'tesoreria report sums period expenses');
    flus_it_assert(count($report['proximos_vencimientos']) >= 1, 'tesoreria report shows upcoming obligations');
    flus_it_assert(count($report['obligaciones_vencidas']) >= 1, 'tesoreria report shows overdue obligations');

    $badAmount = flus_tesoreria_registrar_movimiento($pdo, [
        'tipo' => 'EGRESO',
        'cuenta_origen_id' => $bancoId,
        'categoria_id' => $catAlquiler,
        'fecha' => date('Y-m-d'),
        'importe' => -10,
        'concepto' => 'Monto invalido',
    ]);
    flus_it_assert(($badAmount['success'] ?? false) === false, 'tesoreria rejects negative amounts');

    $badTransfer = flus_tesoreria_registrar_movimiento($pdo, [
        'tipo' => 'TRANSFERENCIA',
        'cuenta_origen_id' => $bancoId,
        'cuenta_destino_id' => $bancoId,
        'fecha' => date('Y-m-d'),
        'importe' => 10,
        'concepto' => 'Transferencia invalida',
    ]);
    flus_it_assert(($badTransfer['success'] ?? false) === false, 'tesoreria rejects same account transfer');

    $badDate = flus_tesoreria_save_obligacion($pdo, [
        'descripcion' => 'Fecha invalida',
        'categoria_id' => $catAlquiler,
        'fecha_vencimiento' => '2026-99-99',
        'importe_estimado' => 10,
    ]);
    flus_it_assert(($badDate['success'] ?? false) === false, 'tesoreria rejects invalid obligation date');
}

function flus_it_run_purchase_integrity_case(PDO $pdo): void
{
    $suffix = date('His') . '-' . getmypid();
    $stProduct = $pdo->prepare("
        INSERT INTO productos
            (codigo, nombre, categoria, precio, stock, costo, stock_minimo, es_pesable, unidad_venta, activo, iva_porcentaje)
        VALUES (?, ?, 'Integracion', ?, ?, ?, 0.000, ?, ?, 1, 21.00)
    ");
    $stProduct->execute(['IT-COMPRA-U-' . $suffix, 'Compra integracion unidad', 180.00, 10.000, 100.00, 0, 'UNIDAD']);
    $unidadId = (int)$pdo->lastInsertId();
    $stProduct->execute(['IT-COMPRA-K-' . $suffix, 'Compra integracion pesable', 320.00, 5.500, 200.00, 1, 'KG']);
    $pesableId = (int)$pdo->lastInsertId();

    $stCompra = $pdo->prepare("
        INSERT INTO compras
            (fecha, tipo_comp, nro_comp, estado, total_neto, total_iva, total, total_bruto, created_by)
        VALUES (NOW(), 'FC', ?, 'CONFIRMADA', ?, 0.00, ?, ?, 1)
    ");
    $stItem = $pdo->prepare("
        INSERT INTO compra_items
            (compra_id, producto_id, cantidad, costo_unitario, subtotal)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stCompra->execute(['IT-U-' . $suffix, 360.00, 360.00, 360.00]);
    $compraUnidadId = (int)$pdo->lastInsertId();
    $stItem->execute([$compraUnidadId, $unidadId, 3.000, 120.00, 360.00]);

    $pdo->beginTransaction();
    flus_compras_actualizar_costo_con_historial($pdo, $unidadId, 120.00, $compraUnidadId);
    flus_compras_actualizar_costo_con_historial($pdo, $unidadId, 130.00, $compraUnidadId);
    $pdo->commit();
    flus_it_assert(
        round((float)$pdo->query("SELECT costo FROM productos WHERE id = {$unidadId}")->fetchColumn(), 2) === 130.00,
        'purchase confirmation applies the last cost when a product has multiple lines'
    );
    $stHistory = $pdo->prepare("SELECT motivo FROM producto_precios_hist WHERE producto_id = ? ORDER BY id DESC LIMIT 1");
    $stHistory->execute([$unidadId]);
    flus_it_assert(
        (string)$stHistory->fetchColumn() === "Compra #{$compraUnidadId} confirmada",
        'purchase cost history is stored on the same database connection'
    );

    $obligation = flus_tesoreria_create_purchase_obligation($pdo, $compraUnidadId, ['created_by' => 1]);
    flus_it_assert(($obligation['success'] ?? false) === true, 'confirmed purchase creates treasury obligation');
    $pdo->beginTransaction();
    $cancelObligation = flus_compras_cancelar_obligacion_al_anular($pdo, $compraUnidadId);
    flus_it_assert(($cancelObligation['success'] ?? false) === true, 'unpaid purchase obligation can be cancelled');
    flus_compras_revertir_costos_al_anular($pdo, [$unidadId], $compraUnidadId);
    $pdo->commit();

    $stObligation = $pdo->prepare("SELECT estado FROM tesoreria_obligaciones WHERE compra_id = ?");
    $stObligation->execute([$compraUnidadId]);
    flus_it_assert((string)$stObligation->fetchColumn() === 'CANCELADO', 'purchase annulment cancels unpaid obligation');
    flus_it_assert(
        round((float)$pdo->query("SELECT costo FROM productos WHERE id = {$unidadId}")->fetchColumn(), 2) === 100.00,
        'purchase annulment restores the cost before all lines from the same purchase'
    );

    $stCompra->execute(['IT-K-' . $suffix, 250.00, 250.00, 250.00]);
    $compraPesableId = (int)$pdo->lastInsertId();
    $stItem->execute([$compraPesableId, $pesableId, 1.250, 200.00, 250.00]);
    $pesableObligation = flus_tesoreria_create_purchase_obligation($pdo, $compraPesableId, ['created_by' => 1]);
    flus_it_assert(($pesableObligation['success'] ?? false) === true, 'weighable purchase creates treasury obligation');
    $stPartial = $pdo->prepare("
        UPDATE tesoreria_obligaciones
        SET estado = 'PARCIAL', importe_pagado = 100.00
        WHERE compra_id = ?
    ");
    $stPartial->execute([$compraPesableId]);

    $pdo->beginTransaction();
    $blocked = flus_compras_cancelar_obligacion_al_anular($pdo, $compraPesableId);
    flus_it_assert(($blocked['success'] ?? true) === false, 'purchase annulment blocks partially paid obligation');
    $pdo->rollBack();
    $stObligation->execute([$compraPesableId]);
    flus_it_assert((string)$stObligation->fetchColumn() === 'PARCIAL', 'blocked annulment leaves partial obligation unchanged');

    $pdo->beginTransaction();
    flus_compras_actualizar_costo_con_historial($pdo, $pesableId, 210.00, $compraPesableId);
    precio_registrar_cambio($pesableId, 210.00, 225.00, 'COSTO', 'Ajuste manual posterior', 1, $pdo);
    $pdo->prepare('UPDATE productos SET costo = 225.00 WHERE id = ?')->execute([$pesableId]);
    flus_compras_revertir_costos_al_anular($pdo, [$pesableId], $compraPesableId);
    $pdo->commit();
    flus_it_assert(
        round((float)$pdo->query("SELECT costo FROM productos WHERE id = {$pesableId}")->fetchColumn(), 2) === 225.00,
        'purchase annulment preserves a later manual cost change'
    );

    $stProductShape = $pdo->prepare('SELECT es_pesable, unidad_venta FROM productos WHERE id = ?');
    $stProductShape->execute([$pesableId]);
    $pesable = $stProductShape->fetch(PDO::FETCH_ASSOC) ?: [];
    flus_it_assert(
        (int)($pesable['es_pesable'] ?? 0) === 1 && (string)($pesable['unidad_venta'] ?? '') === 'KG',
        'purchase flow preserves weighable product metadata'
    );
}

function flus_it_run_purchase_permission_case(PDO $pdo): void
{
    $st = $pdo->prepare("
        SELECT COUNT(DISTINCT p.slug)
        FROM roles r
        JOIN role_permission rp ON rp.role_id = r.id
        JOIN permissions p ON p.id = rp.permission_id
        WHERE r.slug = ?
          AND p.slug IN ('editar_stock', 'ver_costos')
    ");

    $st->execute(['admin']);
    flus_it_assert((int)$st->fetchColumn() === 2, 'admin has both purchase permissions');

    $st->execute(['encargado']);
    flus_it_assert((int)$st->fetchColumn() === 2, 'manager has both purchase permissions');

    $st->execute(['cajero']);
    flus_it_assert((int)$st->fetchColumn() === 0, 'cashier has no purchase permissions');

    $st->execute(['operador']);
    flus_it_assert((int)$st->fetchColumn() === 1, 'operator cannot access purchase costs with stock permission alone');
}

$host = flus_it_env('FLUS_TEST_DB_HOST', '127.0.0.1');
$port = flus_it_env('FLUS_TEST_DB_PORT', '3306');
$user = flus_it_env('FLUS_TEST_DB_USER', 'root');
$pass = flus_it_env('FLUS_TEST_DB_PASS', '');
$charset = flus_it_env('FLUS_TEST_DB_CHARSET', 'utf8mb4');
$dbName = flus_it_env('FLUS_TEST_DB_NAME', 'flus_it_' . date('Ymd_His') . '_' . getmypid());
$keepDb = flus_it_env('FLUS_TEST_DB_KEEP', '0') === '1';

if (!preg_match('/^flus_it_[A-Za-z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "[FAIL] FLUS_TEST_DB_NAME must start with flus_it_ and contain only letters, numbers or underscores.\n");
    exit(1);
}

$serverDsn = sprintf('mysql:host=%s;port=%s;charset=%s', $host, $port, $charset);

$server = new PDO($serverDsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
]);

$quotedDb = flus_it_quote_ident($dbName);
$exitCode = 0;

try {
    $server->exec("DROP DATABASE IF EXISTS {$quotedDb}");
    $server->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $dbName, $charset);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);

    flus_exec_sql_file($pdo, $root . '/install.sql');
    echo "[OK] install.sql imported into {$dbName}\n";

    $result = flus_apply_migrations($pdo, $root . '/migrations', true);
    echo "[OK] migrations applied: " . count($result['applied']) . " new, " . count($result['skipped']) . " skipped\n";
    flus_it_assert(
        ($result['sequence_collisions'] ?? []) === [
            31 => [
                '031_clientes_razon_social_compat.sql',
                '031_movimientos_stock_tipo_compat.sql',
            ],
        ],
        'migration runner reports known legacy sequence collision'
    );

    $latest = (string)$pdo
        ->query("SELECT filename FROM schema_migrations ORDER BY filename DESC LIMIT 1")
        ->fetchColumn();

    flus_it_assert($latest === '042_mercadopago_liquidaciones.sql', 'latest migration is 042');
    $pdo->exec('DROP TRIGGER IF EXISTS `before_insert_movimiento_stock`');
    $pdo->exec('ALTER TABLE `movimientos_stock` DROP COLUMN `stock_anterior`, DROP COLUMN `stock_nuevo`');
    flus_exec_sql_file($pdo, $root . '/migrations/040_movimientos_stock_snapshots_compat.sql');
    flus_it_assert(flus_it_table_has_column($pdo, 'movimientos_stock', 'stock_anterior'), 'migration 040 restores movimientos_stock.stock_anterior');
    flus_it_assert(flus_it_table_has_column($pdo, 'movimientos_stock', 'stock_nuevo'), 'migration 040 restores movimientos_stock.stock_nuevo');
    $tipoColumn = $pdo
        ->query("SHOW COLUMNS FROM movimientos_stock LIKE 'tipo'")
        ->fetch(PDO::FETCH_ASSOC);
    $tipoDefinition = (string)($tipoColumn['Type'] ?? '');
    flus_it_assert(str_contains($tipoDefinition, 'ANULACION_VENTA'), 'movimientos_stock.tipo supports sale annulments');
    flus_it_assert(str_contains($tipoDefinition, 'ANULACION_COMPRA'), 'movimientos_stock.tipo supports purchase annulments');
    flus_it_assert(flus_it_table_has_column($pdo, 'clientes', 'razon_social'), 'clientes.razon_social exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'inventario_sesiones', 'categoria_nombre'), 'inventario_sesiones.categoria_nombre exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'inventario_conteos', 'stock_sistema_snapshot'), 'inventario_conteos.stock_sistema_snapshot exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'facturas', 'estado_fiscal'), 'facturas.estado_fiscal exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'ventas', 'ajuste_precio_total'), 'ventas.ajuste_precio_total exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'ventas', 'ajuste_precio_redondeo_total'), 'ventas.ajuste_precio_redondeo_total exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'venta_items', 'precio_unit_base'), 'venta_items.precio_unit_base exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'venta_items', 'ajuste_precio_tipo'), 'venta_items.ajuste_precio_tipo exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'venta_items', 'ajuste_precio_regla_unit_monto'), 'venta_items.ajuste_precio_regla_unit_monto exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'venta_items', 'ajuste_precio_redondeo_modo'), 'venta_items.ajuste_precio_redondeo_modo exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'venta_items', 'ajuste_precio_redondeo_unit_monto'), 'venta_items.ajuste_precio_redondeo_unit_monto exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'venta_items', 'ajuste_precio_redondeo_total'), 'venta_items.ajuste_precio_redondeo_total exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'venta_pagos', 'mp_order_id'), 'venta_pagos.mp_order_id exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'venta_pagos', 'mp_verified'), 'venta_pagos.mp_verified exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'mercadopago_integraciones', 'store_external_id'), 'mercadopago integration state exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'mercadopago_webhook_eventos', 'event_key'), 'mercadopago webhook trace exists');
    flus_it_assert(flus_it_table_has_column($pdo, 'mercadopago_liquidaciones', 'net_received_amount'), 'mercadopago settlements exist');
    flus_it_assert(
        (int)$pdo->query('SELECT COUNT(*) FROM caja_sesiones')->fetchColumn() === 0,
        'clean install starts without historical cash sessions'
    );
    $posSale = flus_it_run_pos_sale_case($pdo);
    flus_it_assert(
        (int)($posSale['caja_id'] ?? 0) === 1,
        'first cash session from a clean install uses id 1'
    );
    $fiscalCase = flus_it_run_non_remote_fiscal_case($pdo, (int)$posSale['venta_id']);
    flus_it_run_non_remote_nc_total_case($pdo, $fiscalCase);

    $partialPosSale = flus_it_run_pos_sale_case($pdo);
    $partialFiscalCase = flus_it_run_non_remote_fiscal_case($pdo, (int)$partialPosSale['venta_id']);
    flus_it_run_non_remote_nc_partial_case($pdo, $partialFiscalCase);
    flus_it_run_manual_legacy_nc_partial_case($pdo);

    $recoveryPosSale = flus_it_run_pos_sale_case($pdo);
    $recoveryFiscalCase = flus_it_run_non_remote_fiscal_case($pdo, (int)$recoveryPosSale['venta_id']);
    flus_it_run_non_remote_recovery_case($pdo, $recoveryFiscalCase);

    $cobranzaPosSale = flus_it_run_pos_sale_case($pdo);
    $cobranzaFiscalCase = flus_it_run_non_remote_fiscal_case($pdo, (int)$cobranzaPosSale['venta_id']);
    flus_it_run_cobranza_receipt_case($pdo, $cobranzaPosSale, $cobranzaFiscalCase);

    $directPaymentPosSale = flus_it_run_pos_sale_case($pdo);
    $directPaymentFiscalCase = flus_it_run_non_remote_fiscal_case($pdo, (int)$directPaymentPosSale['venta_id']);
    flus_it_run_invoice_direct_payment_case($pdo, $directPaymentPosSale, $directPaymentFiscalCase);

    $ccPosSale = flus_it_run_pos_sale_case($pdo);
    $ccFiscalCase = flus_it_run_non_remote_fiscal_case($pdo, (int)$ccPosSale['venta_id']);
    flus_it_run_cuenta_corriente_case($pdo, $ccFiscalCase);

    flus_it_run_tesoreria_v1_case($pdo);
    flus_it_run_purchase_integrity_case($pdo);
    flus_it_run_purchase_permission_case($pdo);

    echo "[OK] DB integration check finished.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[FAIL] " . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (!$keepDb) {
        try {
            $server->exec("DROP DATABASE IF EXISTS {$quotedDb}");
            echo "[OK] dropped {$dbName}\n";
        } catch (Throwable $dropError) {
            fwrite(STDERR, "[WARN] Could not drop {$dbName}: " . $dropError->getMessage() . "\n");
        }
    } else {
        echo "[KEEP] {$dbName}\n";
    }
}

exit($exitCode);
