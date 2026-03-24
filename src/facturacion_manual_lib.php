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
    throw new RuntimeException(
        'Falta la tabla factura_manual_items. Aplica primero la migracion migrations/007_support_modules_schema.sql.'
    );
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

function flus_facturacion_documentos_table_ready(PDO $pdo): bool
{
    return flus_table_exists($pdo, 'documentos_comerciales') && flus_table_exists($pdo, 'documento_items');
}

function flus_facturacion_documento_buscar(PDO $pdo, int $documentoId): ?array
{
    if ($documentoId <= 0 || !flus_facturacion_documentos_table_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM documentos_comerciales WHERE id = ? LIMIT 1');
    $st->execute([$documentoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function flus_facturacion_documento_buscar_por_request_uid(PDO $pdo, string $requestUid): ?array
{
    $requestUid = trim($requestUid);
    if ($requestUid === '' || !flus_facturacion_documentos_table_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM documentos_comerciales WHERE request_uid = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$requestUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function flus_facturacion_documento_items_fetch(PDO $pdo, int $documentoId): array
{
    if ($documentoId <= 0 || !flus_facturacion_documentos_table_ready($pdo)) {
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
        FROM documento_items
        WHERE documento_id = ?
        ORDER BY id ASC
    ');
    $st->execute([$documentoId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function flus_facturacion_documento_crear_manual(PDO $pdo, int $clienteId, array $items, array $meta = [], array $opciones = []): int
{
    if (!flus_facturacion_documentos_table_ready($pdo)) {
        throw new RuntimeException(
            'Faltan las tablas documentales de facturación manual. Aplica primero la migracion migrations/017_facturacion_documentos_manual.sql.'
        );
    }

    $requestUid = trim((string)($opciones['request_uid'] ?? ''));
    if ($requestUid !== '') {
        $existing = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
        if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
            return (int)$existing['id'];
        }
    }

    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }

    try {
        $timestamp = date('Y-m-d H:i:s');
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float)($item['subtotal'] ?? 0);
        }
        $total = round($total, 2);

        $documentoId = flus_facturacion_insert_dynamic($pdo, 'documentos_comerciales', [
            'request_uid' => $requestUid !== '' ? $requestUid : null,
            'tipo_documento' => 'FACTURA_MANUAL',
            'origen' => 'MANUAL',
            'estado' => 'PENDIENTE',
            'cliente_id' => $clienteId > 0 ? $clienteId : null,
            'venta_id' => isset($opciones['venta_id']) && (int)$opciones['venta_id'] > 0 ? (int)$opciones['venta_id'] : null,
            'nota' => trim((string)($meta['nota'] ?? 'Factura manual sin caja')) ?: 'Factura manual sin caja',
            'medio_pago' => trim((string)($meta['medio_pago'] ?? 'FACTURA_MANUAL')) ?: 'FACTURA_MANUAL',
            'total' => $total,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        foreach ($items as $item) {
            flus_facturacion_insert_dynamic($pdo, 'documento_items', [
                'documento_id' => $documentoId,
                'codigo' => $item['codigo'] ?? null,
                'descripcion' => $item['descripcion'],
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $item['precio_unitario'],
                'subtotal' => $item['subtotal'],
                'iva_porcentaje' => $item['iva_porcentaje'],
                'created_at' => $timestamp,
            ]);
        }

        if ($ownsTx) {
            $pdo->commit();
        }

        return $documentoId;
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($requestUid !== '') {
            $existing = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                return (int)$existing['id'];
            }
        }

        throw $e;
    }
}

function flus_facturacion_documento_actualizar_venta(PDO $pdo, int $documentoId, int $ventaId): void
{
    if ($documentoId <= 0 || $ventaId <= 0 || !flus_facturacion_documentos_table_ready($pdo)) {
        return;
    }

    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    $ventaActual = (int)($documento['venta_id'] ?? 0);
    if ($ventaActual > 0 && $ventaActual !== $ventaId) {
        return;
    }

    $st = $pdo->prepare('UPDATE documentos_comerciales SET venta_id = ?, updated_at = ? WHERE id = ?');
    $st->execute([$ventaId, date('Y-m-d H:i:s'), $documentoId]);
}

function flus_facturacion_documento_actualizar_estado(PDO $pdo, int $documentoId, string $estado): void
{
    if ($documentoId <= 0 || !flus_facturacion_documentos_table_ready($pdo)) {
        return;
    }

    $st = $pdo->prepare('UPDATE documentos_comerciales SET estado = ?, updated_at = ? WHERE id = ?');
    $st->execute([trim($estado) !== '' ? trim($estado) : 'PENDIENTE', date('Y-m-d H:i:s'), $documentoId]);
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

    if ($nombreExpr === 'NULL') {
        return null;
    }

    $sql = 'SELECT * FROM clientes WHERE UPPER(' . $nombreExpr . ') = ?';
    if (flus_column_exists($pdo, 'clientes', 'activo')) {
        $sql .= ' AND activo = 1';
    }
    $sql .= $order . ' LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute(['CONSUMIDOR FINAL']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function flus_facturacion_asegurar_consumidor_final(PDO $pdo): ?array
{
    $cliente = flus_facturacion_consumidor_final($pdo);
    if ($cliente !== null) {
        return $cliente;
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
        if (flus_column_exists($pdo, 'clientes', 'nombre') && trim((string)($cliente['nombre'] ?? '')) === '') {
            $updates['nombre'] = $nombre;
        }
        if (flus_column_exists($pdo, 'clientes', 'cuit')) {
            $cuitActual = preg_replace('/\D+/', '', (string)($cliente['cuit'] ?? ''));
            if ($cuitActual === '' || $cuitActual === $cuit) {
                $updates['cuit'] = $cuitFormatted;
            }
        }
        if (flus_column_exists($pdo, 'clientes', 'cond_iva') && trim((string)($cliente['cond_iva'] ?? '')) === '') {
            $updates['cond_iva'] = $condIva;
        }
        if ($direccion !== '' && flus_column_exists($pdo, 'clientes', 'direccion') && trim((string)($cliente['direccion'] ?? '')) === '') {
            $updates['direccion'] = $direccion;
        }
        if (flus_column_exists($pdo, 'clientes', 'tipo_cliente') && trim((string)($cliente['tipo_cliente'] ?? '')) === '') {
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

function flus_facturacion_cliente_lookup_confirmado(array $source): bool
{
    return trim((string)($source['cliente_lookup_confirmado'] ?? '0')) === '1';
}

function flus_facturacion_resolver_cliente_desde_input(PDO $pdo, array $source, array $options = []): array
{
    $mensajeVacio = trim((string)($options['mensaje_vacio'] ?? 'Debes seleccionar un cliente, Consumidor Final o consultar un CUIT/CUIL.'));
    $mensajeInvalido = trim((string)($options['mensaje_invalido'] ?? 'El cliente seleccionado no es valido.'));
    $mensajeConfirmacionLookup = trim((string)($options['mensaje_lookup_confirmacion'] ?? 'Confirma que quieres emitir con los datos consultados en ARCA. Si no los vas a usar, descartalos y sigue con el cliente seleccionado.'));
    $clienteSeleccionadoRaw = trim((string)($source['cliente_id'] ?? ''));

    $result = [
        'cliente_id' => null,
        'cliente_raw' => $clienteSeleccionadoRaw,
        'resolved_cliente' => null,
        'errors' => [],
    ];

    $clienteLookup = flus_facturacion_cliente_lookup_post($source);
    if ($clienteLookup !== null) {
        if (!flus_facturacion_cliente_lookup_confirmado($source)) {
            $result['errors'][] = $mensajeConfirmacionLookup;
            return $result;
        }

        try {
            $resolvedCliente = flus_facturacion_resolver_cliente_padron($pdo, $clienteLookup);
            $result['cliente_id'] = (int)($resolvedCliente['cliente_id'] ?? 0);
            $result['resolved_cliente'] = $resolvedCliente;
        } catch (Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    if ($clienteSeleccionadoRaw === '') {
        $result['errors'][] = $mensajeVacio;
        return $result;
    }

    if ($clienteSeleccionadoRaw !== '0' && !ctype_digit($clienteSeleccionadoRaw)) {
        $result['errors'][] = $mensajeInvalido;
        return $result;
    }

    $clienteId = $clienteSeleccionadoRaw === '0' ? 0 : (int)$clienteSeleccionadoRaw;
    try {
        $resolvedCliente = flus_facturacion_resolver_cliente($pdo, $clienteId);
        $result['cliente_id'] = (int)($resolvedCliente['cliente_id'] ?? $clienteId);
        $result['resolved_cliente'] = $resolvedCliente;
    } catch (Throwable $e) {
        $result['errors'][] = $clienteId > 0 ? $mensajeInvalido : $e->getMessage();
    }

    return $result;
}

function flus_facturacion_manual_retry_fingerprint(int $clienteId, array $items, array $meta = [], array $opciones = []): string
{
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $rows[] = [
            'codigo' => trim((string)($item['codigo'] ?? '')),
            'descripcion' => trim((string)($item['descripcion'] ?? '')),
            'cantidad' => round((float)($item['cantidad'] ?? 0), 3),
            'precio' => round((float)($item['precio'] ?? $item['precio_unitario'] ?? 0), 2),
            'iva_porcentaje' => round((float)($item['iva_porcentaje'] ?? 21), 2),
            'subtotal' => round((float)($item['subtotal'] ?? 0), 2),
        ];
    }

    $payload = [
        'cliente_id' => max(0, $clienteId),
        'items' => $rows,
    ];

    return sha1((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function flus_facturacion_manual_retry_state_bucket(): array
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }

    $bucket = $_SESSION['flus_facturacion_manual_retry'] ?? [];
    return is_array($bucket) ? $bucket : [];
}

function flus_facturacion_manual_retry_state_guardar(
    string $requestUid,
    int $ventaId,
    int $clienteId,
    array $items,
    string $estadoFiscal = 'PENDIENTE_ENVIO',
    ?int $facturaId = null
): void {
    $requestUid = trim($requestUid);
    if ($requestUid === '') {
        return;
    }

    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }

    $fingerprint = flus_facturacion_manual_retry_fingerprint($clienteId, $items);
    $_SESSION['flus_facturacion_manual_retry'][$fingerprint] = [
        'request_uid' => $requestUid,
        'venta_id' => max(0, $ventaId),
        'factura_id' => $facturaId !== null ? max(0, $facturaId) : 0,
        'estado_fiscal' => trim($estadoFiscal) !== '' ? trim($estadoFiscal) : 'PENDIENTE_ENVIO',
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function flus_facturacion_manual_retry_state_es_reutilizable(string $estadoFiscal): bool
{
    $estado = strtoupper(trim($estadoFiscal));
    if ($estado === 'APROBADA') {
        $estado = 'AUTORIZADA';
    }

    return in_array($estado, ['PENDIENTE_ENVIO', 'ERROR_TRANSITORIO', 'AUTORIZADA'], true);
}

function flus_facturacion_manual_retry_state_buscar(int $clienteId, array $items): ?array
{
    $fingerprint = flus_facturacion_manual_retry_fingerprint($clienteId, $items);
    $bucket = flus_facturacion_manual_retry_state_bucket();
    $row = $bucket[$fingerprint] ?? null;
    if (!is_array($row)) {
        return null;
    }

    $requestUid = trim((string)($row['request_uid'] ?? ''));
    if ($requestUid === '') {
        return null;
    }

    $estadoFiscal = (string)($row['estado_fiscal'] ?? 'PENDIENTE_ENVIO');
    if (!flus_facturacion_manual_retry_state_es_reutilizable($estadoFiscal)) {
        return null;
    }

    $row['request_uid'] = $requestUid;
    $row['venta_id'] = (int)($row['venta_id'] ?? 0);
    $row['factura_id'] = (int)($row['factura_id'] ?? 0);
    $row['estado_fiscal'] = $estadoFiscal;
    return $row;
}

function flus_facturacion_manual_resolver_base_existente(PDO $pdo, $repo, string $requestUid, int $clienteId, array $items): array
{
    $requestUid = trim($requestUid);
    $base = [
        'factura' => null,
        'documento' => null,
        'factura_id' => 0,
        'documento_id' => 0,
        'venta_id' => 0,
    ];

    if ($requestUid === '') {
        return $base;
    }

    if (is_object($repo) && method_exists($repo, 'findFacturaByRequestUid')) {
        $factura = $repo->findFacturaByRequestUid($requestUid);
        if (is_array($factura)) {
            $base['factura'] = $factura;
            $base['factura_id'] = (int)($factura['id'] ?? 0);
            $base['documento_id'] = (int)($factura['documento_id'] ?? 0);
            $base['venta_id'] = (int)($factura['venta_id'] ?? 0);
        }
    }

    $documento = null;
    if ($base['documento_id'] > 0) {
        $documento = flus_facturacion_documento_buscar($pdo, $base['documento_id']);
    }
    if (!is_array($documento)) {
        $documento = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
    }
    if (is_array($documento)) {
        $base['documento'] = $documento;
        $base['documento_id'] = (int)($documento['id'] ?? 0);
        if ($base['venta_id'] <= 0) {
            $base['venta_id'] = (int)($documento['venta_id'] ?? 0);
        }
    }

    $retryState = flus_facturacion_manual_retry_state_buscar($clienteId, $items);
    if (is_array($retryState) && trim((string)($retryState['request_uid'] ?? '')) === $requestUid) {
        if ($base['venta_id'] <= 0) {
            $base['venta_id'] = (int)($retryState['venta_id'] ?? 0);
        }
        if ($base['factura_id'] <= 0) {
            $base['factura_id'] = (int)($retryState['factura_id'] ?? 0);
        }
    }

    if ($base['factura_id'] > 0 && !is_array($base['factura']) && is_object($repo) && method_exists($repo, 'findFacturaById')) {
        $factura = $repo->findFacturaById($base['factura_id']);
        if (is_array($factura)) {
            $base['factura'] = $factura;
            $base['documento_id'] = max($base['documento_id'], (int)($factura['documento_id'] ?? 0));
            $base['venta_id'] = max($base['venta_id'], (int)($factura['venta_id'] ?? 0));
        }
    }

    if ($base['documento_id'] > 0 && !is_array($base['documento'])) {
        $base['documento'] = flus_facturacion_documento_buscar($pdo, $base['documento_id']);
    }

    return $base;
}

function flus_facturacion_factura_detalle_items_fetch(PDO $pdo, array $factura): array
{
    $facturaId = (int)($factura['id'] ?? 0);
    $documentoId = (int)($factura['documento_id'] ?? 0);
    $ventaId = (int)($factura['venta_id'] ?? 0);
    $itemRows = [];

    if ($facturaId > 0 && flus_table_exists($pdo, 'factura_items')) {
        $usaProductos = flus_table_exists($pdo, 'productos');
        $joinProductos = $usaProductos ? 'LEFT JOIN productos p ON p.id = fi.producto_id' : '';
        $codigoExpr = $usaProductos && flus_column_exists($pdo, 'productos', 'codigo')
            ? 'COALESCE(fi.codigo_snapshot, p.codigo)'
            : 'fi.codigo_snapshot';
        $descripcionExpr = $usaProductos && flus_column_exists($pdo, 'productos', 'nombre')
            ? 'COALESCE(fi.descripcion_snapshot, p.nombre)'
            : 'fi.descripcion_snapshot';
        $ivaExpr = 'COALESCE(fi.iva_porcentaje, 21)';

        $sqlFacturaItems = "
          SELECT
            fi.*,
            {$codigoExpr} AS codigo,
            {$descripcionExpr} AS descripcion,
            fi.precio_unitario_bruto AS precio_unitario,
            fi.subtotal_total AS subtotal,
            {$ivaExpr} AS iva_porcentaje
          FROM factura_items fi
          {$joinProductos}
          WHERE fi.factura_id = ?
          ORDER BY COALESCE(fi.linea_orden, fi.id) ASC, fi.id ASC
        ";
        $stFacturaItems = $pdo->prepare($sqlFacturaItems);
        $stFacturaItems->execute([$facturaId]);
        $itemRows = $stFacturaItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($itemRows === [] && $documentoId > 0) {
        $itemRows = flus_facturacion_documento_items_fetch($pdo, $documentoId);
    }

    if ($itemRows === [] && $ventaId > 0 && flus_table_exists($pdo, 'venta_items') && flus_table_exists($pdo, 'productos')) {
        $sqlItems = '
          SELECT vi.*, p.codigo, p.nombre, p.iva AS producto_iva
          FROM venta_items vi
          JOIN productos p ON p.id = vi.producto_id
          WHERE vi.venta_id = ?
          ORDER BY vi.id ASC
        ';
        $stmtItems = $pdo->prepare($sqlItems);
        $stmtItems->execute([$ventaId]);
        $itemRows = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($itemRows === [] && $ventaId > 0) {
        $itemRows = flus_facturacion_manual_items_fetch($pdo, $ventaId);
    }

    return $itemRows;
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
        'medio_pago' => trim((string)($meta['medio_pago'] ?? 'FACTURA_MANUAL')) ?: 'FACTURA_MANUAL',
        'monto_pagado' => $total,
        'vuelto' => 0,
        'nota' => trim((string)($meta['nota'] ?? 'Factura manual sin caja')) ?: 'Factura manual sin caja',
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
