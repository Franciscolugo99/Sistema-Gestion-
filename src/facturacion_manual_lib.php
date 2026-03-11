<?php
// src/facturacion_manual_lib.php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function flus_facturacion_habilitada(PDO $pdo): bool
{
    return config_get($pdo, 'facturacion_habilitada', '0') === '1';
}

function flus_facturacion_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function flus_facturacion_ensure_manual_items_table(PDO $pdo): void
{
    if (flus_table_exists($pdo, 'factura_manual_items')) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS factura_manual_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            venta_id INT NOT NULL,
            codigo VARCHAR(80) DEFAULT NULL,
            descripcion VARCHAR(255) NOT NULL,
            cantidad DECIMAL(10,3) NOT NULL DEFAULT 1.000,
            precio_unitario DECIMAL(12,2) NOT NULL,
            subtotal DECIMAL(12,2) NOT NULL,
            iva_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 21.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_factura_manual_items_venta (venta_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $pdo->exec($sql);
}

function flus_facturacion_manual_items_fetch(PDO $pdo, int $ventaId): array
{
    if ($ventaId <= 0 || !flus_table_exists($pdo, 'factura_manual_items')) {
        return [];
    }

    $st = $pdo->prepare('
        SELECT
            codigo,
            descripcion AS nombre,
            cantidad,
            precio_unitario AS precio,
            subtotal,
            iva_porcentaje
        FROM factura_manual_items
        WHERE venta_id = ?
        ORDER BY id ASC
    ');
    $st->execute([$ventaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function flus_facturacion_normalize_manual_items(array $items): array
{
    $normalized = [];
    $allowedIva = [0.0, 2.5, 5.0, 10.5, 21.0, 27.0];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $descripcion = trim((string)($item['descripcion'] ?? ''));
        if ($descripcion === '') {
            continue;
        }

        $cantidad = round((float)($item['cantidad'] ?? 0), 3);
        $precio = round((float)($item['precio'] ?? $item['precio_unitario'] ?? 0), 2);
        $codigo = trim((string)($item['codigo'] ?? ''));
        $iva = round((float)($item['iva_porcentaje'] ?? 21), 2);

        if ($cantidad <= 0) {
            throw new RuntimeException('Cada item manual debe tener una cantidad mayor a 0.');
        }
        if ($precio < 0) {
            throw new RuntimeException('Cada item manual debe tener un precio valido.');
        }
        if (!in_array($iva, $allowedIva, true)) {
            throw new RuntimeException('La alicuota IVA del item manual no es valida.');
        }

        $normalized[] = [
            'codigo' => $codigo !== '' ? $codigo : null,
            'descripcion' => $descripcion,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'subtotal' => round($cantidad * $precio, 2),
            'iva_porcentaje' => $iva,
        ];
    }

    if ($normalized === []) {
        throw new RuntimeException('Debes cargar al menos un item para la factura manual.');
    }

    return $normalized;
}

function flus_facturacion_consumidor_final(PDO $pdo): ?array
{
    if (!flus_table_exists($pdo, 'clientes')) {
        return null;
    }

    $nombreExpr = flus_column_exists($pdo, 'clientes', 'nombre') ? 'nombre' : 'NULL';
    $order = flus_column_exists($pdo, 'clientes', 'id') ? ' ORDER BY id DESC' : '';

    if ($nombreExpr !== 'NULL') {
        $sql = 'SELECT * FROM clientes WHERE UPPER(' . $nombreExpr . ') = ?';
        if (flus_column_exists($pdo, 'clientes', 'activo')) {
            $sql .= ' AND activo = 1';
        }
        $sql .= $order . ' LIMIT 1';

        $st = $pdo->prepare($sql);
        $st->execute(['CONSUMIDOR FINAL']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    $data = [
        'nombre' => 'Consumidor Final',
        'cond_iva' => 'CF',
        'activo' => 1,
        'creado_en' => date('Y-m-d H:i:s'),
    ];

    $clienteId = flus_facturacion_insert_dynamic($pdo, 'clientes', $data);
    $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
    $st->execute([$clienteId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function flus_facturacion_resolver_cliente(PDO $pdo, int $clienteId): array
{
    if ($clienteId > 0) {
        if (!flus_table_exists($pdo, 'clientes')) {
            throw new RuntimeException('La tabla clientes no existe.');
        }

        $sql = 'SELECT * FROM clientes WHERE id = ?';
        if (flus_column_exists($pdo, 'clientes', 'activo')) {
            $sql .= ' AND activo = 1';
        }
        $sql .= ' LIMIT 1';

        $st = $pdo->prepare($sql);
        $st->execute([$clienteId]);
        $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cliente === null) {
            throw new RuntimeException('Cliente no encontrado.');
        }

        return [
            'cliente_id' => (int)($cliente['id'] ?? $clienteId),
            'cliente' => $cliente,
            'consumidor_final' => false,
        ];
    }

    $cliente = flus_facturacion_consumidor_final($pdo);
    if ($cliente !== null) {
        return [
            'cliente_id' => (int)($cliente['id'] ?? 0),
            'cliente' => $cliente,
            'consumidor_final' => true,
        ];
    }

    return [
        'cliente_id' => 0,
        'cliente' => null,
        'consumidor_final' => true,
    ];
}

function flus_facturacion_resolver_cliente_padron(PDO $pdo, array $lookup): array
{
    if (!flus_table_exists($pdo, 'clientes')) {
        throw new RuntimeException('La tabla clientes no existe.');
    }

    require_once __DIR__ . '/../public/includes/CuitValidator.php';

    $cuitRaw = trim((string)($lookup['cuit'] ?? ''));
    $cuit = class_exists('CuitValidator') ? CuitValidator::limpiar($cuitRaw) : preg_replace('/\D+/', '', $cuitRaw);
    if ($cuit === '' || strlen($cuit) !== 11) {
        throw new RuntimeException('Debes ingresar un CUIT/CUIL valido para consultar ARCA.');
    }
    if (class_exists('CuitValidator') && !CuitValidator::validar($cuit)) {
        $detalle = CuitValidator::obtenerError($cuitRaw);
        throw new RuntimeException($detalle !== '' ? $detalle : 'El CUIT/CUIL consultado no es valido.');
    }

    $nombre = trim((string)($lookup['nombre'] ?? ''));
    if ($nombre === '') {
        throw new RuntimeException('ARCA no devolvio nombre o razon social para ese CUIT/CUIL.');
    }

    $condIva = strtoupper(trim((string)($lookup['cond_iva'] ?? '')));
    if (!in_array($condIva, ['RI', 'MT', 'EX', 'CF'], true)) {
        $condIva = 'CF';
    }

    $direccion = trim((string)($lookup['direccion'] ?? ''));
    $tipoCliente = strtoupper(trim((string)($lookup['tipo_cliente'] ?? 'MINORISTA')));
    if (!in_array($tipoCliente, ['MINORISTA', 'MAYORISTA', 'CORPORATIVO'], true)) {
        $tipoCliente = 'MINORISTA';
    }

    $cuitFormatted = class_exists('CuitValidator') ? (CuitValidator::formatear($cuit) ?? $cuit) : $cuit;

    $st = $pdo->prepare("SELECT * FROM clientes WHERE REPLACE(REPLACE(COALESCE(cuit, ''), '-', ''), ' ', '') = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$cuit]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($cliente !== null) {
        $updates = [];
        if (flus_column_exists($pdo, 'clientes', 'nombre')) {
            $updates['nombre'] = $nombre;
        }
        if (flus_column_exists($pdo, 'clientes', 'cuit')) {
            $updates['cuit'] = $cuitFormatted;
        }
        if (flus_column_exists($pdo, 'clientes', 'cond_iva')) {
            $updates['cond_iva'] = $condIva;
        }
        if ($direccion !== '' && flus_column_exists($pdo, 'clientes', 'direccion')) {
            $updates['direccion'] = $direccion;
        }
        if (flus_column_exists($pdo, 'clientes', 'tipo_cliente')) {
            $updates['tipo_cliente'] = $tipoCliente;
        }

        if ($updates !== []) {
            $sets = [];
            $params = [':id' => (int)$cliente['id']];
            foreach ($updates as $col => $value) {
                $sets[] = "`{$col}` = :{$col}";
                $params[':' . $col] = $value;
            }
            $sql = 'UPDATE clientes SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $up = $pdo->prepare($sql);
            $up->execute($params);

            $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
            $st->execute([(int)$cliente['id']]);
            $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: $cliente;
        }

        return [
            'cliente_id' => (int)($cliente['id'] ?? 0),
            'cliente' => $cliente,
            'consumidor_final' => false,
        ];
    }

    $clienteId = flus_facturacion_insert_dynamic($pdo, 'clientes', [
        'nombre' => $nombre,
        'cuit' => $cuitFormatted,
        'cond_iva' => $condIva,
        'tipo_cliente' => $tipoCliente,
        'direccion' => $direccion !== '' ? $direccion : null,
        'activo' => 1,
        'creado_en' => date('Y-m-d H:i:s'),
    ]);

    $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
    $st->execute([$clienteId]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'cliente_id' => $clienteId,
        'cliente' => $cliente,
        'consumidor_final' => false,
    ];
}

function flus_facturacion_cliente_lookup_post(array $source): ?array
{
    $activo = trim((string)($source['cliente_lookup_activo'] ?? '0')) === '1';
    if (!$activo) {
        return null;
    }

    $cuit = trim((string)($source['cliente_lookup_cuit'] ?? ''));
    $nombre = trim((string)($source['cliente_lookup_nombre'] ?? ''));
    if ($cuit === '' || $nombre === '') {
        return null;
    }

    return [
        'cuit' => $cuit,
        'nombre' => $nombre,
        'cond_iva' => trim((string)($source['cliente_lookup_cond_iva'] ?? '')),
        'direccion' => trim((string)($source['cliente_lookup_direccion'] ?? '')),
        'tipo_cliente' => trim((string)($source['cliente_lookup_tipo_cliente'] ?? 'MINORISTA')),
        'estado' => trim((string)($source['cliente_lookup_estado'] ?? '')),
    ];
}

function flus_facturacion_crear_venta_manual(PDO $pdo, int $clienteId, array $items, array $meta = []): int
{
    if (!flus_table_exists($pdo, 'ventas')) {
        throw new RuntimeException('La tabla ventas no existe.');
    }

    flus_facturacion_ensure_manual_items_table($pdo);

    $total = 0.0;
    foreach ($items as $item) {
        $total += (float)($item['subtotal'] ?? 0);
    }
    $total = round($total, 2);
    $timestamp = date('Y-m-d H:i:s');

    $ventaId = flus_facturacion_insert_dynamic($pdo, 'ventas', [
        'fecha' => $timestamp,
        'total' => $total,
        'descuento_total' => 0,
        'recargo_total' => 0,
        'medio_pago' => trim((string)($meta['medio_pago'] ?? 'FACTURA')) ?: 'FACTURA',
        'monto_pagado' => $total,
        'vuelto' => 0,
        'nota' => trim((string)($meta['nota'] ?? 'Factura manual')) ?: 'Factura manual',
        'cliente_id' => $clienteId > 0 ? $clienteId : null,
        'estado' => 'EMITIDA',
        'facturada' => 0,
        'uuid' => flus_facturacion_uuid_v4(),
    ]);

    foreach ($items as $item) {
        flus_facturacion_insert_dynamic($pdo, 'factura_manual_items', [
            'venta_id' => $ventaId,
            'codigo' => $item['codigo'] ?? null,
            'descripcion' => $item['descripcion'],
            'cantidad' => $item['cantidad'],
            'precio_unitario' => $item['precio_unitario'],
            'subtotal' => $item['subtotal'],
            'iva_porcentaje' => $item['iva_porcentaje'],
            'created_at' => $timestamp,
        ]);
    }

    return $ventaId;
}

