<?php
// src/tesoreria_lib.php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function flus_tesoreria_tables_ready(PDO $pdo): bool
{
    return flus_table_exists($pdo, 'tesoreria_cuentas')
        && flus_table_exists($pdo, 'tesoreria_categorias')
        && flus_table_exists($pdo, 'tesoreria_movimientos')
        && flus_table_exists($pdo, 'tesoreria_obligaciones');
}

function flus_tesoreria_user_id(): ?int
{
    if (function_exists('session_user_id')) {
        $id = session_user_id();
        return $id > 0 ? $id : null;
    }
    $id = (int)($_SESSION['usuario_id'] ?? ($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0)));
    return $id > 0 ? $id : null;
}

function flus_tesoreria_parse_amount(mixed $value): float
{
    $amount = function_exists('parse_money_ar') ? parse_money_ar($value) : (float)$value;
    return round($amount, 2);
}

function flus_tesoreria_valid_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
}

function flus_tesoreria_valid_datetime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return date('Y-m-d H:i:s');
    }

    $date = flus_tesoreria_valid_date(substr($value, 0, 10));
    if ($date === null) {
        return null;
    }

    if (strlen($value) <= 10) {
        return $date . ' 00:00:00';
    }

    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value)
        ?: DateTime::createFromFormat('Y-m-d H:i:s', $value)
        ?: DateTime::createFromFormat('Y-m-d H:i', $value);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

function flus_tesoreria_slug(string $value): string
{
    $value = trim(mb_strtolower($value));
    if (function_exists('iconv')) {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : ('categoria-' . substr(sha1((string)microtime(true)), 0, 8));
}

function flus_tesoreria_tipo_cuenta_options(): array
{
    return [
        'CAJA' => 'Caja',
        'BANCO' => 'Banco',
        'BILLETERA' => 'Billetera',
        'FONDO_FIJO' => 'Fondo fijo',
        'OTRO' => 'Otro',
    ];
}

function flus_tesoreria_tipo_movimiento_options(): array
{
    return [
        'INGRESO' => 'Ingreso',
        'EGRESO' => 'Egreso',
        'TRANSFERENCIA' => 'Transferencia',
    ];
}

function flus_tesoreria_normalize_tipo_cuenta(string $tipo): string
{
    $tipo = strtoupper(trim($tipo));
    return array_key_exists($tipo, flus_tesoreria_tipo_cuenta_options()) ? $tipo : 'OTRO';
}

function flus_tesoreria_normalize_tipo_categoria(string $tipo): string
{
    $tipo = strtoupper(trim($tipo));
    return in_array($tipo, ['INGRESO', 'EGRESO', 'AMBOS'], true) ? $tipo : 'EGRESO';
}

function flus_tesoreria_normalize_tipo_movimiento(string $tipo): string
{
    $tipo = strtoupper(trim($tipo));
    return array_key_exists($tipo, flus_tesoreria_tipo_movimiento_options()) ? $tipo : 'EGRESO';
}

function flus_tesoreria_find_cuenta(PDO $pdo, int $cuentaId): ?array
{
    if ($cuentaId <= 0 || !flus_tesoreria_tables_ready($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM tesoreria_cuentas WHERE id = ? LIMIT 1');
    $st->execute([$cuentaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function flus_tesoreria_find_categoria(PDO $pdo, int $categoriaId): ?array
{
    if ($categoriaId <= 0 || !flus_tesoreria_tables_ready($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM tesoreria_categorias WHERE id = ? LIMIT 1');
    $st->execute([$categoriaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function flus_tesoreria_find_categoria_by_slug(PDO $pdo, string $slug): ?array
{
    $slug = trim($slug);
    if ($slug === '' || !flus_tesoreria_tables_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM tesoreria_categorias WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function flus_tesoreria_obligaciones_compras_ready(PDO $pdo): bool
{
    return flus_tesoreria_tables_ready($pdo)
        && flus_column_exists($pdo, 'tesoreria_obligaciones', 'external_key')
        && flus_column_exists($pdo, 'tesoreria_obligaciones', 'entidad_tipo')
        && flus_column_exists($pdo, 'tesoreria_obligaciones', 'entidad_id')
        && flus_column_exists($pdo, 'tesoreria_obligaciones', 'proveedor_id')
        && flus_column_exists($pdo, 'tesoreria_obligaciones', 'compra_id');
}

function flus_tesoreria_find_obligacion_by_external_key(PDO $pdo, string $externalKey): ?array
{
    $externalKey = trim($externalKey);
    if ($externalKey === '' || !flus_tesoreria_obligaciones_compras_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM tesoreria_obligaciones WHERE external_key = ? LIMIT 1');
    $st->execute([$externalKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function flus_tesoreria_cuentas(PDO $pdo, bool $includeInactive = false): array
{
    if (!flus_tesoreria_tables_ready($pdo)) {
        return [];
    }

    $where = $includeInactive ? '' : "WHERE c.estado = 'ACTIVA'";
    $sql = "
        SELECT c.*,
               c.saldo_inicial
               + COALESCE(SUM(CASE
                   WHEN m.estado <> 'ACTIVO' THEN 0
                   WHEN m.tipo = 'INGRESO' AND m.cuenta_destino_id = c.id THEN m.importe
                   WHEN m.tipo = 'EGRESO' AND m.cuenta_origen_id = c.id THEN -m.importe
                   WHEN m.tipo = 'TRANSFERENCIA' AND m.cuenta_destino_id = c.id THEN m.importe
                   WHEN m.tipo = 'TRANSFERENCIA' AND m.cuenta_origen_id = c.id THEN -m.importe
                   ELSE 0
               END), 0) AS saldo_actual
        FROM tesoreria_cuentas c
        LEFT JOIN tesoreria_movimientos m
          ON m.cuenta_origen_id = c.id OR m.cuenta_destino_id = c.id
        {$where}
        GROUP BY c.id
        ORDER BY c.estado ASC, c.nombre ASC
    ";

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function flus_tesoreria_categorias(PDO $pdo, ?string $tipo = null, bool $includeInactive = false): array
{
    if (!flus_tesoreria_tables_ready($pdo)) {
        return [];
    }

    $where = [];
    $params = [];
    if (!$includeInactive) {
        $where[] = "estado = 'ACTIVA'";
    }
    if ($tipo !== null && $tipo !== '') {
        $tipo = flus_tesoreria_normalize_tipo_movimiento($tipo);
        if ($tipo !== 'TRANSFERENCIA') {
            $where[] = "(tipo = :tipo OR tipo = 'AMBOS')";
            $params[':tipo'] = $tipo;
        }
    }

    $sql = 'SELECT * FROM tesoreria_categorias'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY orden ASC, nombre ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function flus_tesoreria_save_cuenta(PDO $pdo, array $payload): array
{
    if (!flus_tesoreria_tables_ready($pdo)) {
        return ['success' => false, 'error' => 'Faltan migraciones de tesoreria.'];
    }

    $id = max(0, (int)($payload['id'] ?? 0));
    $nombre = trim((string)($payload['nombre'] ?? ''));
    $tipo = flus_tesoreria_normalize_tipo_cuenta((string)($payload['tipo'] ?? 'OTRO'));
    $estado = strtoupper(trim((string)($payload['estado'] ?? 'ACTIVA')));
    $estado = in_array($estado, ['ACTIVA', 'INACTIVA'], true) ? $estado : 'ACTIVA';
    $sucursalId = (int)($payload['sucursal_id'] ?? 0);
    $sucursalNombre = trim((string)($payload['sucursal_nombre'] ?? ''));
    $saldoInicial = flus_tesoreria_parse_amount($payload['saldo_inicial'] ?? 0);
    $observaciones = trim((string)($payload['observaciones'] ?? ''));

    if ($nombre === '') {
        return ['success' => false, 'error' => 'Ingresa un nombre de cuenta.'];
    }
    if ($saldoInicial < 0) {
        return ['success' => false, 'error' => 'El saldo inicial no puede ser negativo.'];
    }

    if ($id > 0) {
        $st = $pdo->prepare("
            UPDATE tesoreria_cuentas
            SET nombre = ?, tipo = ?, sucursal_id = ?, sucursal_nombre = ?, saldo_inicial = ?,
                estado = ?, observaciones = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $st->execute([
            mb_substr($nombre, 0, 120),
            $tipo,
            $sucursalId > 0 ? $sucursalId : null,
            $sucursalNombre !== '' ? mb_substr($sucursalNombre, 0, 120) : null,
            $saldoInicial,
            $estado,
            $observaciones !== '' ? mb_substr($observaciones, 0, 255) : null,
            $id,
        ]);
        return ['success' => true, 'cuenta_id' => $id];
    }

    $st = $pdo->prepare("
        INSERT INTO tesoreria_cuentas
            (nombre, tipo, sucursal_id, sucursal_nombre, saldo_inicial, estado, observaciones, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $st->execute([
        mb_substr($nombre, 0, 120),
        $tipo,
        $sucursalId > 0 ? $sucursalId : null,
        $sucursalNombre !== '' ? mb_substr($sucursalNombre, 0, 120) : null,
        $saldoInicial,
        $estado,
        $observaciones !== '' ? mb_substr($observaciones, 0, 255) : null,
    ]);

    return ['success' => true, 'cuenta_id' => (int)$pdo->lastInsertId()];
}

function flus_tesoreria_save_categoria(PDO $pdo, array $payload): array
{
    if (!flus_tesoreria_tables_ready($pdo)) {
        return ['success' => false, 'error' => 'Faltan migraciones de tesoreria.'];
    }

    $id = max(0, (int)($payload['id'] ?? 0));
    $nombre = trim((string)($payload['nombre'] ?? ''));
    $tipo = flus_tesoreria_normalize_tipo_categoria((string)($payload['tipo'] ?? 'EGRESO'));
    $estado = strtoupper(trim((string)($payload['estado'] ?? 'ACTIVA')));
    $estado = in_array($estado, ['ACTIVA', 'INACTIVA'], true) ? $estado : 'ACTIVA';
    $orden = (int)($payload['orden'] ?? 100);
    $observaciones = trim((string)($payload['observaciones'] ?? ''));

    if ($nombre === '') {
        return ['success' => false, 'error' => 'Ingresa un nombre de categoria.'];
    }

    $slug = flus_tesoreria_slug($nombre);

    if ($id > 0) {
        $st = $pdo->prepare("
            UPDATE tesoreria_categorias
            SET nombre = ?, slug = ?, tipo = ?, estado = ?, orden = ?, observaciones = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $st->execute([
            mb_substr($nombre, 0, 120),
            $slug,
            $tipo,
            $estado,
            $orden,
            $observaciones !== '' ? mb_substr($observaciones, 0, 255) : null,
            $id,
        ]);
        return ['success' => true, 'categoria_id' => $id];
    }

    $st = $pdo->prepare("
        INSERT INTO tesoreria_categorias
            (nombre, slug, tipo, estado, orden, observaciones, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $st->execute([
        mb_substr($nombre, 0, 120),
        $slug,
        $tipo,
        $estado,
        $orden,
        $observaciones !== '' ? mb_substr($observaciones, 0, 255) : null,
    ]);

    return ['success' => true, 'categoria_id' => (int)$pdo->lastInsertId()];
}

function flus_tesoreria_validate_movimiento(PDO $pdo, array $payload): array
{
    $tipo = flus_tesoreria_normalize_tipo_movimiento((string)($payload['tipo'] ?? 'EGRESO'));
    $cuentaOrigenId = (int)($payload['cuenta_origen_id'] ?? 0);
    $cuentaDestinoId = (int)($payload['cuenta_destino_id'] ?? 0);
    $categoriaId = (int)($payload['categoria_id'] ?? 0);
    $fecha = flus_tesoreria_valid_datetime((string)($payload['fecha'] ?? ''));
    $importe = flus_tesoreria_parse_amount($payload['importe'] ?? 0);
    $concepto = trim((string)($payload['concepto'] ?? ''));

    if ($fecha === null) {
        return ['success' => false, 'error' => 'Fecha invalida.'];
    }
    if ($importe <= 0) {
        return ['success' => false, 'error' => 'El importe debe ser mayor a cero.'];
    }
    if ($concepto === '') {
        return ['success' => false, 'error' => 'Ingresa un concepto.'];
    }

    $cuentaOrigen = $cuentaOrigenId > 0 ? flus_tesoreria_find_cuenta($pdo, $cuentaOrigenId) : null;
    $cuentaDestino = $cuentaDestinoId > 0 ? flus_tesoreria_find_cuenta($pdo, $cuentaDestinoId) : null;

    if ($tipo === 'INGRESO') {
        if (!is_array($cuentaDestino) || strtoupper((string)($cuentaDestino['estado'] ?? '')) !== 'ACTIVA') {
            return ['success' => false, 'error' => 'Selecciona una cuenta destino activa.'];
        }
        $cuentaOrigenId = 0;
    } elseif ($tipo === 'EGRESO') {
        if (!is_array($cuentaOrigen) || strtoupper((string)($cuentaOrigen['estado'] ?? '')) !== 'ACTIVA') {
            return ['success' => false, 'error' => 'Selecciona una cuenta origen activa.'];
        }
        $cuentaDestinoId = 0;
    } else {
        if (!is_array($cuentaOrigen) || !is_array($cuentaDestino)) {
            return ['success' => false, 'error' => 'Selecciona cuenta origen y destino.'];
        }
        if ($cuentaOrigenId === $cuentaDestinoId) {
            return ['success' => false, 'error' => 'La cuenta origen y destino deben ser distintas.'];
        }
        if (strtoupper((string)($cuentaOrigen['estado'] ?? '')) !== 'ACTIVA'
            || strtoupper((string)($cuentaDestino['estado'] ?? '')) !== 'ACTIVA') {
            return ['success' => false, 'error' => 'Las cuentas de transferencia deben estar activas.'];
        }
        $categoriaId = 0;
    }

    $categoria = $categoriaId > 0 ? flus_tesoreria_find_categoria($pdo, $categoriaId) : null;
    if ($tipo !== 'TRANSFERENCIA') {
        if (!is_array($categoria) || strtoupper((string)($categoria['estado'] ?? '')) !== 'ACTIVA') {
            return ['success' => false, 'error' => 'Selecciona una categoria activa.'];
        }
        $catTipo = strtoupper((string)($categoria['tipo'] ?? ''));
        if (!in_array($catTipo, [$tipo, 'AMBOS'], true)) {
            return ['success' => false, 'error' => 'La categoria no corresponde al tipo de movimiento.'];
        }
    }

    return [
        'success' => true,
        'tipo' => $tipo,
        'cuenta_origen_id' => $cuentaOrigenId > 0 ? $cuentaOrigenId : null,
        'cuenta_destino_id' => $cuentaDestinoId > 0 ? $cuentaDestinoId : null,
        'categoria_id' => $categoriaId > 0 ? $categoriaId : null,
        'fecha' => $fecha,
        'importe' => $importe,
        'concepto' => mb_substr($concepto, 0, 180),
    ];
}

function flus_tesoreria_registrar_movimiento(PDO $pdo, array $payload): array
{
    if (!flus_tesoreria_tables_ready($pdo)) {
        return ['success' => false, 'error' => 'Faltan migraciones de tesoreria.'];
    }

    $requestUid = trim((string)($payload['request_uid'] ?? ''));
    if ($requestUid !== '') {
        $stExisting = $pdo->prepare('SELECT id FROM tesoreria_movimientos WHERE request_uid = ? LIMIT 1');
        $stExisting->execute([$requestUid]);
        $existingId = (int)($stExisting->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return ['success' => true, 'movimiento_id' => $existingId, 'duplicate_guard' => true];
        }
    }

    $validated = flus_tesoreria_validate_movimiento($pdo, $payload);
    if (($validated['success'] ?? false) !== true) {
        return $validated;
    }

    $sucursalId = (int)($payload['sucursal_id'] ?? 0);
    $sucursalNombre = trim((string)($payload['sucursal_nombre'] ?? ''));
    $referencia = trim((string)($payload['referencia'] ?? ''));
    $observaciones = trim((string)($payload['observaciones'] ?? ''));
    $entidadTipo = trim((string)($payload['entidad_tipo'] ?? ''));
    $entidadId = (int)($payload['entidad_id'] ?? 0);
    $obligacionId = (int)($payload['obligacion_id'] ?? 0);
    $createdBy = (int)($payload['created_by'] ?? 0);
    if ($createdBy <= 0) {
        $createdBy = (int)(flus_tesoreria_user_id() ?? 0);
    }

    $st = $pdo->prepare("
        INSERT INTO tesoreria_movimientos
            (request_uid, tipo, estado, cuenta_origen_id, cuenta_destino_id, categoria_id,
             sucursal_id, sucursal_nombre, fecha, importe, concepto, referencia, observaciones,
             entidad_tipo, entidad_id, obligacion_id, created_by, created_at, updated_at)
        VALUES
            (?, ?, 'ACTIVO', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $st->execute([
        $requestUid !== '' ? mb_substr($requestUid, 0, 64) : null,
        $validated['tipo'],
        $validated['cuenta_origen_id'],
        $validated['cuenta_destino_id'],
        $validated['categoria_id'],
        $sucursalId > 0 ? $sucursalId : null,
        $sucursalNombre !== '' ? mb_substr($sucursalNombre, 0, 120) : null,
        $validated['fecha'],
        $validated['importe'],
        $validated['concepto'],
        $referencia !== '' ? mb_substr($referencia, 0, 120) : null,
        $observaciones !== '' ? mb_substr($observaciones, 0, 255) : null,
        $entidadTipo !== '' ? mb_substr(strtoupper($entidadTipo), 0, 40) : null,
        $entidadId > 0 ? $entidadId : null,
        $obligacionId > 0 ? $obligacionId : null,
        $createdBy > 0 ? $createdBy : null,
    ]);

    return ['success' => true, 'movimiento_id' => (int)$pdo->lastInsertId()];
}

function flus_tesoreria_movimientos(PDO $pdo, array $filters = []): array
{
    if (!flus_tesoreria_tables_ready($pdo)) {
        return ['rows' => [], 'total_rows' => 0, 'stats' => ['ingresos' => 0.0, 'egresos' => 0.0, 'transferencias' => 0.0]];
    }

    $desde = flus_tesoreria_valid_date((string)($filters['desde'] ?? ''));
    $hasta = flus_tesoreria_valid_date((string)($filters['hasta'] ?? ''));
    if ($desde !== null && $hasta !== null && $desde > $hasta) {
        [$desde, $hasta] = [$hasta, $desde];
    }
    $tipo = strtoupper(trim((string)($filters['tipo'] ?? '')));
    $cuentaId = max(0, (int)($filters['cuenta_id'] ?? 0));
    $categoriaId = max(0, (int)($filters['categoria_id'] ?? 0));
    $sucursal = trim((string)($filters['sucursal_nombre'] ?? ''));
    $q = trim((string)($filters['q'] ?? $filters['search'] ?? ''));
    $perPage = (int)($filters['per_page'] ?? 50);
    if (!in_array($perPage, [20, 50, 100], true)) {
        $perPage = 50;
    }
    $page = max(1, (int)($filters['page'] ?? 1));

    $where = ["m.estado = 'ACTIVO'"];
    $params = [];
    if ($desde !== null) {
        $where[] = 'm.fecha >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== null) {
        $where[] = 'm.fecha <= :hasta';
        $params[':hasta'] = $hasta . ' 23:59:59';
    }
    if (in_array($tipo, ['INGRESO', 'EGRESO', 'TRANSFERENCIA'], true)) {
        $where[] = 'm.tipo = :tipo';
        $params[':tipo'] = $tipo;
    }
    if ($cuentaId > 0) {
        $where[] = '(m.cuenta_origen_id = :cuenta_id OR m.cuenta_destino_id = :cuenta_id)';
        $params[':cuenta_id'] = $cuentaId;
    }
    if ($categoriaId > 0) {
        $where[] = 'm.categoria_id = :categoria_id';
        $params[':categoria_id'] = $categoriaId;
    }
    if ($sucursal !== '') {
        $where[] = 'm.sucursal_nombre LIKE :sucursal';
        $params[':sucursal'] = '%' . addcslashes($sucursal, "\\%_") . '%';
    }
    if ($q !== '') {
        $where[] = '(m.concepto LIKE :q OR m.referencia LIKE :q OR m.observaciones LIKE :q)';
        $params[':q'] = '%' . addcslashes($q, "\\%_") . '%';
    }

    $baseWhere = implode(' AND ', $where);
    $countSt = $pdo->prepare("SELECT COUNT(*) FROM tesoreria_movimientos m WHERE {$baseWhere}");
    $countSt->execute($params);
    $totalRows = (int)$countSt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = "
        SELECT m.*,
               co.nombre AS cuenta_origen_nombre,
               cd.nombre AS cuenta_destino_nombre,
               cat.nombre AS categoria_nombre
        FROM tesoreria_movimientos m
        LEFT JOIN tesoreria_cuentas co ON co.id = m.cuenta_origen_id
        LEFT JOIN tesoreria_cuentas cd ON cd.id = m.cuenta_destino_id
        LEFT JOIN tesoreria_categorias cat ON cat.id = m.categoria_id
        WHERE {$baseWhere}
        ORDER BY m.fecha DESC, m.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $statsSt = $pdo->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN m.tipo = 'INGRESO' THEN m.importe ELSE 0 END), 0) AS ingresos,
          COALESCE(SUM(CASE WHEN m.tipo = 'EGRESO' THEN m.importe ELSE 0 END), 0) AS egresos,
          COALESCE(SUM(CASE WHEN m.tipo = 'TRANSFERENCIA' THEN m.importe ELSE 0 END), 0) AS transferencias
        FROM tesoreria_movimientos m
        WHERE {$baseWhere}
    ");
    $statsSt->execute($params);
    $stats = $statsSt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'filters' => [
            'desde' => $desde ?? '',
            'hasta' => $hasta ?? '',
            'tipo' => $tipo,
            'cuenta_id' => $cuentaId,
            'categoria_id' => $categoriaId,
            'sucursal_nombre' => $sucursal,
            'q' => $q,
            'per_page' => $perPage,
            'page' => $page,
        ],
        'rows' => $rows,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages,
        'from_row' => $totalRows > 0 ? $offset + 1 : 0,
        'to_row' => min($offset + $perPage, $totalRows),
        'stats' => [
            'ingresos' => round((float)($stats['ingresos'] ?? 0), 2),
            'egresos' => round((float)($stats['egresos'] ?? 0), 2),
            'transferencias' => round((float)($stats['transferencias'] ?? 0), 2),
        ],
    ];
}

function flus_tesoreria_save_obligacion(PDO $pdo, array $payload): array
{
    if (!flus_tesoreria_tables_ready($pdo)) {
        return ['success' => false, 'error' => 'Faltan migraciones de tesoreria.'];
    }

    $descripcion = trim((string)($payload['descripcion'] ?? ''));
    $categoriaId = (int)($payload['categoria_id'] ?? 0);
    $fechaVencimiento = flus_tesoreria_valid_date((string)($payload['fecha_vencimiento'] ?? ''));
    $importe = flus_tesoreria_parse_amount($payload['importe_estimado'] ?? 0);
    $cuentaSugeridaId = (int)($payload['cuenta_sugerida_id'] ?? 0);
    $sucursalId = (int)($payload['sucursal_id'] ?? 0);
    $sucursalNombre = trim((string)($payload['sucursal_nombre'] ?? ''));
    $observaciones = trim((string)($payload['observaciones'] ?? ''));
    $createdBy = (int)($payload['created_by'] ?? 0);
    if ($createdBy <= 0) {
        $createdBy = (int)(flus_tesoreria_user_id() ?? 0);
    }

    if ($descripcion === '') {
        return ['success' => false, 'error' => 'Ingresa una descripcion.'];
    }
    if ($fechaVencimiento === null) {
        return ['success' => false, 'error' => 'Fecha de vencimiento invalida.'];
    }
    if ($importe <= 0) {
        return ['success' => false, 'error' => 'El importe debe ser mayor a cero.'];
    }
    $categoria = flus_tesoreria_find_categoria($pdo, $categoriaId);
    if (!is_array($categoria) || !in_array(strtoupper((string)($categoria['tipo'] ?? '')), ['EGRESO', 'AMBOS'], true)) {
        return ['success' => false, 'error' => 'Selecciona una categoria de egreso.'];
    }
    if ($cuentaSugeridaId > 0 && !is_array(flus_tesoreria_find_cuenta($pdo, $cuentaSugeridaId))) {
        return ['success' => false, 'error' => 'Cuenta sugerida invalida.'];
    }

    $estado = $fechaVencimiento < date('Y-m-d') ? 'VENCIDO' : 'PENDIENTE';
    $st = $pdo->prepare("
        INSERT INTO tesoreria_obligaciones
            (descripcion, categoria_id, sucursal_id, sucursal_nombre, fecha_vencimiento,
             importe_estimado, importe_pagado, estado, cuenta_sugerida_id, observaciones,
             created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, NOW(), NOW())
    ");
    $st->execute([
        mb_substr($descripcion, 0, 180),
        $categoriaId,
        $sucursalId > 0 ? $sucursalId : null,
        $sucursalNombre !== '' ? mb_substr($sucursalNombre, 0, 120) : null,
        $fechaVencimiento,
        $importe,
        $estado,
        $cuentaSugeridaId > 0 ? $cuentaSugeridaId : null,
        $observaciones !== '' ? mb_substr($observaciones, 0, 255) : null,
        $createdBy > 0 ? $createdBy : null,
    ]);

    return ['success' => true, 'obligacion_id' => (int)$pdo->lastInsertId()];
}

function flus_tesoreria_create_purchase_obligation(PDO $pdo, int $compraId, array $payload = []): array
{
    if ($compraId <= 0) {
        return ['success' => false, 'error' => 'Compra invalida.'];
    }
    if (!flus_tesoreria_obligaciones_compras_ready($pdo)) {
        return ['success' => false, 'error' => 'Falta aplicar la migracion de obligaciones por compra.'];
    }

    $externalKey = 'compra:' . $compraId;
    $existing = flus_tesoreria_find_obligacion_by_external_key($pdo, $externalKey);
    if (is_array($existing)) {
        return [
            'success' => true,
            'obligacion_id' => (int)$existing['id'],
            'already_exists' => true,
        ];
    }

    $stCompra = $pdo->prepare("
        SELECT c.id, c.proveedor_id, c.fecha, c.tipo_comp, c.nro_comp, c.estado, c.total,
               p.nombre AS proveedor_nombre
        FROM compras c
        LEFT JOIN proveedores p ON p.id = c.proveedor_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stCompra->execute([$compraId]);
    $compra = $stCompra->fetch(PDO::FETCH_ASSOC);
    if (!is_array($compra)) {
        return ['success' => false, 'error' => 'Compra no encontrada.'];
    }

    $estado = strtoupper((string)($compra['estado'] ?? ''));
    if ($estado !== 'CONFIRMADA') {
        return ['success' => false, 'error' => 'Solo se puede generar deuda de compras confirmadas.'];
    }

    $total = round((float)($compra['total'] ?? 0), 2);
    if ($total <= 0) {
        return ['success' => false, 'error' => 'La compra no tiene total valido para generar deuda.'];
    }

    $categoriaId = (int)($payload['categoria_id'] ?? 0);
    if ($categoriaId <= 0) {
        $categoria = flus_tesoreria_find_categoria_by_slug($pdo, 'compras-mercaderia');
        $categoriaId = is_array($categoria) ? (int)$categoria['id'] : 0;
    }
    if ($categoriaId <= 0) {
        return ['success' => false, 'error' => 'Falta la categoria de tesoreria para compras de mercaderia.'];
    }

    $proveedorId = (int)($compra['proveedor_id'] ?? 0);
    $proveedorNombre = trim((string)($compra['proveedor_nombre'] ?? ''));
    $comprobante = trim((string)($compra['tipo_comp'] ?? '') . ' ' . (string)($compra['nro_comp'] ?? ''));
    $fechaCompra = substr((string)($compra['fecha'] ?? ''), 0, 10);
    $fechaVencimiento = flus_tesoreria_valid_date((string)($payload['fecha_vencimiento'] ?? '')) ?? date('Y-m-d');
    $descripcion = 'Compra #' . $compraId;
    if ($proveedorNombre !== '') {
        $descripcion .= ' - ' . $proveedorNombre;
    }

    $observaciones = 'Generada desde compras.';
    if ($comprobante !== '') {
        $observaciones .= ' Comprobante: ' . $comprobante . '.';
    }
    if (flus_tesoreria_valid_date($fechaCompra) !== null) {
        $observaciones .= ' Fecha compra: ' . $fechaCompra . '.';
    }

    $createdBy = (int)($payload['created_by'] ?? 0);
    if ($createdBy <= 0) {
        $createdBy = (int)(flus_tesoreria_user_id() ?? 0);
    }

    $estadoObligacion = $fechaVencimiento < date('Y-m-d') ? 'VENCIDO' : 'PENDIENTE';
    $st = $pdo->prepare("
        INSERT INTO tesoreria_obligaciones
            (external_key, descripcion, categoria_id, sucursal_id, sucursal_nombre, fecha_vencimiento,
             importe_estimado, importe_pagado, estado, cuenta_sugerida_id, observaciones,
             entidad_tipo, entidad_id, proveedor_id, compra_id, created_by, created_at, updated_at)
        VALUES (?, ?, ?, NULL, NULL, ?, ?, 0.00, ?, NULL, ?, 'COMPRA', ?, ?, ?, ?, NOW(), NOW())
    ");
    $st->execute([
        $externalKey,
        mb_substr($descripcion, 0, 180),
        $categoriaId,
        $fechaVencimiento,
        $total,
        $estadoObligacion,
        mb_substr($observaciones, 0, 255),
        $compraId,
        $proveedorId > 0 ? $proveedorId : null,
        $compraId,
        $createdBy > 0 ? $createdBy : null,
    ]);

    return [
        'success' => true,
        'obligacion_id' => (int)$pdo->lastInsertId(),
        'already_exists' => false,
    ];
}

function flus_tesoreria_obligaciones(PDO $pdo, array $filters = []): array
{
    if (!flus_tesoreria_tables_ready($pdo)) {
        return [];
    }

    $estado = strtoupper(trim((string)($filters['estado'] ?? '')));
    $categoriaId = max(0, (int)($filters['categoria_id'] ?? 0));
    $proveedorId = max(0, (int)($filters['proveedor_id'] ?? 0));
    $compraId = max(0, (int)($filters['compra_id'] ?? 0));
    $sucursal = trim((string)($filters['sucursal_nombre'] ?? ''));
    $hasProveedorId = flus_column_exists($pdo, 'tesoreria_obligaciones', 'proveedor_id');
    $hasCompraId = flus_column_exists($pdo, 'tesoreria_obligaciones', 'compra_id');
    $where = ['1=1'];
    $params = [];
    if (in_array($estado, ['PENDIENTE', 'PAGADO', 'VENCIDO', 'PARCIAL', 'CANCELADO'], true)) {
        if ($estado === 'VENCIDO') {
            $where[] = "(o.estado = 'VENCIDO' OR (o.estado IN ('PENDIENTE', 'PARCIAL') AND o.fecha_vencimiento < CURDATE()))";
        } else {
            $where[] = 'o.estado = :estado';
            $params[':estado'] = $estado;
        }
    }
    if ($categoriaId > 0) {
        $where[] = 'o.categoria_id = :categoria_id';
        $params[':categoria_id'] = $categoriaId;
    }
    if ($proveedorId > 0 && $hasProveedorId) {
        $where[] = 'o.proveedor_id = :proveedor_id';
        $params[':proveedor_id'] = $proveedorId;
    }
    if ($compraId > 0 && $hasCompraId) {
        $where[] = 'o.compra_id = :compra_id';
        $params[':compra_id'] = $compraId;
    }
    if ($sucursal !== '') {
        $where[] = 'o.sucursal_nombre LIKE :sucursal';
        $params[':sucursal'] = '%' . addcslashes($sucursal, "\\%_") . '%';
    }

    $sql = "
        SELECT o.*,
               cat.nombre AS categoria_nombre,
               cs.nombre AS cuenta_sugerida_nombre,
               " . ($hasProveedorId ? 'p.nombre' : 'NULL') . " AS proveedor_nombre,
               " . ($hasCompraId ? 'c.nro_comp' : 'NULL') . " AS compra_nro_comp,
               CASE
                 WHEN o.estado IN ('PAGADO', 'CANCELADO') THEN o.estado
                 WHEN o.fecha_vencimiento < CURDATE() THEN 'VENCIDO'
                 ELSE o.estado
               END AS estado_efectivo
        FROM tesoreria_obligaciones o
        LEFT JOIN tesoreria_categorias cat ON cat.id = o.categoria_id
        LEFT JOIN tesoreria_cuentas cs ON cs.id = o.cuenta_sugerida_id
        " . ($hasProveedorId ? 'LEFT JOIN proveedores p ON p.id = o.proveedor_id' : '') . "
        " . ($hasCompraId ? 'LEFT JOIN compras c ON c.id = o.compra_id' : '') . "
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.fecha_vencimiento ASC, o.id ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function flus_tesoreria_pagar_obligacion(PDO $pdo, int $obligacionId, array $payload): array
{
    if (!flus_tesoreria_tables_ready($pdo)) {
        return ['success' => false, 'error' => 'Faltan migraciones de tesoreria.'];
    }
    if ($obligacionId <= 0) {
        return ['success' => false, 'error' => 'Obligacion invalida.'];
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }

    try {
        $st = $pdo->prepare('SELECT * FROM tesoreria_obligaciones WHERE id = ? FOR UPDATE');
        $st->execute([$obligacionId]);
        $ob = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ob)) {
            throw new RuntimeException('Obligacion no encontrada.');
        }
        $estado = strtoupper((string)($ob['estado'] ?? ''));
        if (in_array($estado, ['PAGADO', 'CANCELADO'], true)) {
            throw new RuntimeException('La obligacion ya no esta pendiente.');
        }

        $importeEstimado = round((float)($ob['importe_estimado'] ?? 0), 2);
        $importePagado = round((float)($ob['importe_pagado'] ?? 0), 2);
        $saldo = round(max(0.0, $importeEstimado - $importePagado), 2);
        $monto = flus_tesoreria_parse_amount($payload['importe'] ?? $saldo);
        if ($monto <= 0 || $monto > ($saldo + 0.009)) {
            throw new RuntimeException('El importe de pago es invalido para el saldo pendiente.');
        }

        $cuentaId = (int)($payload['cuenta_origen_id'] ?? $payload['cuenta_id'] ?? $ob['cuenta_sugerida_id'] ?? 0);
        $mov = flus_tesoreria_registrar_movimiento($pdo, [
            'tipo' => 'EGRESO',
            'cuenta_origen_id' => $cuentaId,
            'categoria_id' => (int)($ob['categoria_id'] ?? 0),
            'fecha' => (string)($payload['fecha'] ?? date('Y-m-d')),
            'importe' => $monto,
            'concepto' => 'Pago obligacion: ' . (string)($ob['descripcion'] ?? ''),
            'referencia' => trim((string)($payload['referencia'] ?? '')),
            'observaciones' => trim((string)($payload['observaciones'] ?? '')),
            'sucursal_id' => (int)($ob['sucursal_id'] ?? 0),
            'sucursal_nombre' => (string)($ob['sucursal_nombre'] ?? ''),
            'obligacion_id' => $obligacionId,
            'entidad_tipo' => 'OBLIGACION',
            'entidad_id' => $obligacionId,
            'created_by' => (int)(flus_tesoreria_user_id() ?? 0),
            'request_uid' => trim((string)($payload['request_uid'] ?? '')),
        ]);
        if (($mov['success'] ?? false) !== true) {
            throw new RuntimeException((string)($mov['error'] ?? 'No se pudo registrar el movimiento.'));
        }

        $nuevoPagado = round($importePagado + $monto, 2);
        $nuevoEstado = $nuevoPagado >= ($importeEstimado - 0.009) ? 'PAGADO' : 'PARCIAL';
        $movimientoId = (int)($mov['movimiento_id'] ?? 0);
        $stUp = $pdo->prepare("
            UPDATE tesoreria_obligaciones
            SET importe_pagado = ?, estado = ?, movimiento_pago_id = COALESCE(movimiento_pago_id, ?), updated_at = NOW()
            WHERE id = ?
        ");
        $stUp->execute([$nuevoPagado, $nuevoEstado, $movimientoId > 0 ? $movimientoId : null, $obligacionId]);

        if ($ownTx) {
            $pdo->commit();
        }
        return ['success' => true, 'movimiento_id' => $movimientoId, 'estado' => $nuevoEstado];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function flus_tesoreria_reportes(PDO $pdo, array $filters = []): array
{
    $today = new DateTimeImmutable('today');
    $desde = flus_tesoreria_valid_date((string)($filters['desde'] ?? '')) ?? $today->modify('first day of this month')->format('Y-m-d');
    $hasta = flus_tesoreria_valid_date((string)($filters['hasta'] ?? '')) ?? $today->modify('last day of this month')->format('Y-m-d');
    if ($desde > $hasta) {
        [$desde, $hasta] = [$hasta, $desde];
    }

    if (!flus_tesoreria_tables_ready($pdo)) {
        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'saldos' => [],
            'flujo' => ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0],
            'gastos_categoria' => [],
            'gastos_sucursal' => [],
            'proximos_vencimientos' => [],
            'obligaciones_vencidas' => [],
        ];
    }

    $movs = flus_tesoreria_movimientos($pdo, ['desde' => $desde, 'hasta' => $hasta, 'per_page' => 20]);

    $stCat = $pdo->prepare("
        SELECT COALESCE(cat.nombre, 'Sin categoria') AS categoria, COALESCE(SUM(m.importe), 0) AS total
        FROM tesoreria_movimientos m
        LEFT JOIN tesoreria_categorias cat ON cat.id = m.categoria_id
        WHERE m.estado = 'ACTIVO' AND m.tipo = 'EGRESO' AND m.fecha BETWEEN ? AND ?
        GROUP BY COALESCE(cat.nombre, 'Sin categoria')
        ORDER BY total DESC
    ");
    $stCat->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);

    $stSuc = $pdo->prepare("
        SELECT COALESCE(NULLIF(m.sucursal_nombre, ''), 'General') AS sucursal, COALESCE(SUM(m.importe), 0) AS total
        FROM tesoreria_movimientos m
        WHERE m.estado = 'ACTIVO' AND m.tipo = 'EGRESO' AND m.fecha BETWEEN ? AND ?
        GROUP BY COALESCE(NULLIF(m.sucursal_nombre, ''), 'General')
        ORDER BY total DESC
    ");
    $stSuc->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);

    $prox = flus_tesoreria_obligaciones($pdo, ['estado' => 'PENDIENTE']);
    $prox = array_values(array_filter($prox, static function (array $row): bool {
        $vto = (string)($row['fecha_vencimiento'] ?? '');
        return $vto >= date('Y-m-d') && $vto <= date('Y-m-d', strtotime('+30 days'));
    }));
    $vencidas = flus_tesoreria_obligaciones($pdo, ['estado' => 'VENCIDO']);

    $flujo = $movs['stats'];
    return [
        'desde' => $desde,
        'hasta' => $hasta,
        'saldos' => flus_tesoreria_cuentas($pdo, true),
        'flujo' => [
            'ingresos' => (float)$flujo['ingresos'],
            'egresos' => (float)$flujo['egresos'],
            'neto' => round((float)$flujo['ingresos'] - (float)$flujo['egresos'], 2),
        ],
        'gastos_categoria' => $stCat->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'gastos_sucursal' => $stSuc->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'proximos_vencimientos' => array_slice($prox, 0, 10),
        'obligaciones_vencidas' => array_slice($vencidas, 0, 10),
    ];
}
