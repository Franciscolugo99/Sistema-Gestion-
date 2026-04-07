<?php
// src/facturacion_lib.php
declare(strict_types=1);
$flusConfigPath = __DIR__ . '/config.php';
if (is_file($flusConfigPath)) {
    require_once $flusConfigPath;
}
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/facturacion_context_lib.php';
require_once __DIR__ . '/facturacion_legacy_recovery_lib.php';
require_once __DIR__ . '/facturacion_manual_lib.php';
require_once __DIR__ . '/facturacion_preflight_lib.php';
require_once __DIR__ . '/facturacion_runtime_lib.php';
require_once __DIR__ . '/cobranzas_lib.php';
$flusConfigArcaPath = __DIR__ . '/config_arca.php';
if (file_exists($flusConfigArcaPath)) {
    require_once $flusConfigArcaPath;
}
/**
 * Inserta solo en columnas existentes para tolerar esquemas legacy.
 *
 * @return int ID insertado
 */
function flus_facturacion_insert_dynamic(PDO $pdo, string $table, array $data): int
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

/**
 * Actualiza venta_fiscal sin usar REPLACE para evitar DELETE+INSERT implicitos.
 */
function flus_facturacion_upsert_venta_fiscal(PDO $pdo, int $ventaId, array $data): void
{
    if ($ventaId <= 0 || !flus_table_exists($pdo, 'venta_fiscal')) {
        return;
    }

    foreach (['venta_id', 'pto_vta', 'tipo_cmp', 'nro_cmp', 'cae', 'cae_vto', 'moneda', 'ctz'] as $col) {
        if (!flus_column_exists($pdo, 'venta_fiscal', (string) $col)) {
            return;
        }
    }

    $st = $pdo->prepare(
        'INSERT INTO venta_fiscal (venta_id, pto_vta, tipo_cmp, nro_cmp, cae, cae_vto, moneda, ctz)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             pto_vta = VALUES(pto_vta),
             tipo_cmp = VALUES(tipo_cmp),
             nro_cmp = VALUES(nro_cmp),
             cae = VALUES(cae),
             cae_vto = VALUES(cae_vto),
             moneda = VALUES(moneda),
             ctz = VALUES(ctz)'
    );
    $st->execute([
        $ventaId,
        (int) ($data['punto_venta'] ?? 0),
        (int) ($data['tipo_cbte'] ?? 0),
        (int) ($data['numero'] ?? 0),
        ($data['cae'] ?? null) ?: null,
        flus_facturacion_normalizar_cae_vto(isset($data['cae_vto']) ? (string) $data['cae_vto'] : null),
        ($data['moneda_id'] ?? null) ?: null,
        isset($data['moneda_cotiz']) ? (float) $data['moneda_cotiz'] : null,
    ]);
}

function flus_facturacion_config_activa(PDO $pdo, bool $forUpdate = false): ?array
{
    if (!flus_table_exists($pdo, 'config_facturacion')) {
        return null;
    }

    $order = flus_column_exists($pdo, 'config_facturacion', 'id') ? ' ORDER BY id DESC' : '';
    $lock = $forUpdate ? ' FOR UPDATE' : '';

    if (flus_column_exists($pdo, 'config_facturacion', 'activo')) {
        $st = $pdo->query('SELECT * FROM config_facturacion WHERE activo = 1' . $order . ' LIMIT 1' . $lock);
        $row = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        if ($row !== null) {
            return $row;
        }
    }

    $st = $pdo->query('SELECT * FROM config_facturacion' . $order . ' LIMIT 1' . $lock);
    $row = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    return $row;
}

function flus_facturacion_factura_existente_id(PDO $pdo, int $ventaId): ?int
{
    if ($ventaId <= 0 || !flus_table_exists($pdo, 'facturas') || !flus_column_exists($pdo, 'facturas', 'venta_id')) {
        return null;
    }

    $st = $pdo->prepare('SELECT id FROM facturas WHERE venta_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$ventaId]);
    $facturaId = $st->fetchColumn();
    return $facturaId !== false ? (int)$facturaId : null;
}

function flus_facturacion_cliente_activo(PDO $pdo, int $clienteId): bool
{
    if ($clienteId <= 0 || !flus_table_exists($pdo, 'clientes')) {
        return false;
    }

    $sql = 'SELECT id FROM clientes WHERE id = ?';
    if (flus_column_exists($pdo, 'clientes', 'activo')) {
        $sql .= ' AND activo = 1';
    }
    $sql .= ' LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$clienteId]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
}

function flus_facturacion_clientes_disponibles(PDO $pdo): array
{
    if (!flus_table_exists($pdo, 'clientes')) {
        return [];
    }

    $nombreExpr = flus_column_exists($pdo, 'clientes', 'nombre') ? 'nombre' : 'CONCAT("Cliente #", id)';
    $cuitExpr = flus_column_exists($pdo, 'clientes', 'cuit') ? 'cuit' : 'NULL';
    $condIvaExpr = flus_column_exists($pdo, 'clientes', 'cond_iva') ? 'cond_iva' : 'NULL';
    $where = flus_column_exists($pdo, 'clientes', 'activo') ? 'WHERE activo = 1' : '';

    $sql = "
        SELECT id, {$nombreExpr} AS nombre, {$cuitExpr} AS cuit, {$condIvaExpr} AS cond_iva
        FROM clientes
        {$where}
        ORDER BY nombre ASC
    ";

    $st = $pdo->query($sql);
    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function flus_facturacion_facturas_unique_indexes(PDO $pdo): array
{
    static $memo = [];

    if (!flus_table_exists($pdo, 'facturas')) {
        return [];
    }

    $schema = flus_current_db($pdo);
    if ($schema === '') {
        return [];
    }

    $key = spl_object_id($pdo) . '|facturas_unique_indexes';
    if (isset($memo[$key])) {
        return $memo[$key];
    }

    $sql = "
        SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = 'facturas'
          AND NON_UNIQUE = 0
        ORDER BY INDEX_NAME, SEQ_IN_INDEX
    ";

    $st = $pdo->prepare($sql);
    $st->execute([$schema]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $indexes = [];
    foreach ($rows as $row) {
        $indexName = (string)($row['INDEX_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($indexName === '' || $column === '') {
            continue;
        }
        $indexes[$indexName][] = $column;
    }

    $memo[$key] = $indexes;
    return $indexes;
}

function flus_facturacion_facturas_scope_uses_modo(PDO $pdo): bool
{
    if (!flus_column_exists($pdo, 'facturas', 'modo')) {
        return false;
    }

    foreach (flus_facturacion_facturas_unique_indexes($pdo) as $columns) {
        if ($columns === ['punto_venta', 'tipo', 'modo', 'numero']) {
            return true;
        }
    }

    return false;
}

function flus_facturacion_facturas_scope_requires_migration(PDO $pdo): bool
{
    if (!flus_column_exists($pdo, 'facturas', 'modo')) {
        return false;
    }

    if (flus_facturacion_facturas_scope_uses_modo($pdo)) {
        return false;
    }

    foreach (flus_facturacion_facturas_unique_indexes($pdo) as $columns) {
        if ($columns === ['punto_venta', 'tipo', 'numero']) {
            return true;
        }
    }

    return false;
}

function flus_facturacion_buscar_conflicto_numero(PDO $pdo, int $puntoVenta, string $tipoStr, int $numero): ?array
{
    if ($numero <= 0 || !flus_table_exists($pdo, 'facturas')) {
        return null;
    }

    $sql = 'SELECT id, tipo, punto_venta, numero';
    $sql .= flus_column_exists($pdo, 'facturas', 'modo') ? ', modo' : ", '' AS modo";
    $sql .= ' FROM facturas WHERE punto_venta = ? AND tipo = ? AND numero = ? ORDER BY id DESC LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$puntoVenta, $tipoStr, $numero]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row;
}

function flus_facturacion_assert_facturas_scope_compatible(PDO $pdo, int $puntoVenta, string $tipoStr, int $numero, string $modoOperacion): void
{
    if (!flus_facturacion_facturas_scope_requires_migration($pdo)) {
        return;
    }

    $conflicto = flus_facturacion_buscar_conflicto_numero($pdo, $puntoVenta, $tipoStr, $numero);
    if ($conflicto === null) {
        return;
    }

    $modoExistente = flus_facturacion_normalizar_modo((string)($conflicto['modo'] ?? 'demo'));
    $modoNuevo = flus_facturacion_normalizar_modo($modoOperacion);
    if ($modoExistente === $modoNuevo) {
        return;
    }

    throw new RuntimeException(
        'La tabla facturas sigue usando un indice unico antiguo que no separa por modo. '
        . 'Ya existe ' . $tipoStr . ' ' . str_pad((string)$puntoVenta, 4, '0', STR_PAD_LEFT) . '-' . str_pad((string)$numero, 8, '0', STR_PAD_LEFT)
        . ' en modo ' . flus_facturacion_modo_label($modoExistente) . '. '
        . 'Aplica la migracion migrations/004_facturas_unique_scope.sql y vuelve a emitir.'
    );
}

/**
 * Compatibilidad con instalaciones viejas que guardaban el tipo textual.
 */
function flus_facturacion_tipo_cbte_legacy(array $config): ?int
{
    $raw = strtoupper(trim((string)($config['tipo_comprobante'] ?? $config['tipo_default'] ?? '')));
    $map = [
        'A' => 1,
        'FA' => 1,
        'NDA' => 2,
        'NCA' => 3,
        'B' => 6,
        'FB' => 6,
        'NDB' => 7,
        'NCB' => 8,
        'C' => 11,
        'FC' => 11,
        'NDC' => 12,
        'NCC' => 13,
    ];

    return $map[$raw] ?? null;
}

/**
 * Obtiene la condicion IVA del emisor con fallbacks legacy.
 */
function flus_facturacion_cond_iva_emisor(array $config): string
{
    $cond = strtoupper(trim((string)($config['cond_iva'] ?? '')));
    if (in_array($cond, ['RI', 'MT', 'EX'], true)) {
        return $cond;
    }

    $legacy = strtoupper(trim((string)($config['tipo_comprobante'] ?? $config['tipo_default'] ?? '')));
    if (in_array($legacy, ['C', 'FC', 'NCC', 'NDC'], true)) {
        return 'MT';
    }

    return 'RI';
}

/**
 * Normaliza un CUIT/CUIL dejando solo digitos.
 */
function flus_facturacion_normalizar_doc(?string $value): string
{
    return preg_replace('/\D+/', '', (string)$value);
}

/**
 * Obtiene el CUIT del emisor desde ARCA o configuracion fiscal local.
 */
function flus_facturacion_cuit_emisor(array $config = []): string
{
    $arcaCuit = defined('FLUS_ARCA_CUIT') ? flus_facturacion_normalizar_doc((string)FLUS_ARCA_CUIT) : '';
    if ($arcaCuit !== '') {
        return $arcaCuit;
    }

    return flus_facturacion_normalizar_doc((string)($config['cuit'] ?? ''));
}

/**
 * Resuelve el tipo de comprobante para facturas comunes aun con esquemas legacy.
 */
function flus_facturacion_facturas_modo_acepta_normalizado(PDO $pdo): bool
{
    if (!flus_column_exists($pdo, 'facturas', 'modo')) {
        return false;
    }

    $meta = flus_column_metadata($pdo, 'facturas', 'modo');
    $type = strtolower(trim((string)($meta['COLUMN_TYPE'] ?? '')));
    if ($type === '') {
        return false;
    }

    return !str_starts_with($type, 'enum(') || str_contains($type, "'homologacion'");
}

function flus_facturacion_facturas_modo_value(PDO $pdo, string $modo): string
{
    $normalizado = flus_facturacion_normalizar_modo($modo);

    if (!flus_column_exists($pdo, 'facturas', 'modo')) {
        return flus_facturacion_modo_db_value($normalizado);
    }

    return flus_facturacion_facturas_modo_acepta_normalizado($pdo)
        ? $normalizado
        : flus_facturacion_modo_db_value($normalizado);
}

function flus_facturacion_numero_local_siguiente(PDO $pdo, int $puntoVenta, string $tipoStr, ?string $modo = null): int
{
    if (!flus_table_exists($pdo, 'facturas')) {
        return 1;
    }
    if (!flus_column_exists($pdo, 'facturas', 'punto_venta') || !flus_column_exists($pdo, 'facturas', 'tipo') || !flus_column_exists($pdo, 'facturas', 'numero')) {
        return 1;
    }

    $sql = 'SELECT MAX(numero) FROM facturas WHERE punto_venta = ? AND tipo = ?';
    $params = [$puntoVenta, $tipoStr];

    $usaModoEnScope = $modo !== null
        && $modo !== ''
        && flus_column_exists($pdo, 'facturas', 'modo')
        && (
            flus_facturacion_facturas_scope_uses_modo($pdo)
            || flus_facturacion_normalizar_modo($modo) !== 'demo'
        );

    if ($usaModoEnScope) {
        $sql .= " AND COALESCE(NULLIF(modo, ''), 'legacy') = ?";
        $params[] = flus_facturacion_facturas_modo_value($pdo, $modo);
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $max = $st->fetchColumn();
    return max(1, ((int)($max ?: 0)) + 1);
}

function flus_facturacion_resolver_tipo_cbte(array $config, ?array $cliente, array $opciones = []): int
{
    if (isset($opciones['tipo_cbte'])) {
        return (int)$opciones['tipo_cbte'];
    }

    $legacyRaw = strtoupper(trim((string)($config['tipo_comprobante'] ?? $config['tipo_default'] ?? '')));
    $legacyTipo = flus_facturacion_tipo_cbte_legacy($config);
    $condIvaEmisor = flus_facturacion_cond_iva_emisor($config);
    $condIvaReceptor = determinarCondIvaReceptor($cliente);

    // En configuraciones viejas FA/FB/FC representan la familia del comprobante.
    // Para facturas comunes se debe elegir segun emisor/receptor, no dejar siempre FA.
    if (in_array($legacyRaw, ['A', 'FA', 'B', 'FB', 'C', 'FC', ''], true)) {
        return determinarTipoComprobante($condIvaEmisor, $condIvaReceptor);
    }

    return $legacyTipo ?? determinarTipoComprobante($condIvaEmisor, $condIvaReceptor);
}

function flus_facturacion_fiscal_repository(PDO $pdo): FacturaFiscalRepository
{
    require_once __DIR__ . '/Fiscal/bootstrap.php';
    return new PdoFacturaFiscalRepository($pdo);
}

function flus_facturacion_preparar_contexto_desde_documento(PDO $pdo, int $documentoId, int $clienteId, array $opciones = []): array
{
    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    if (!is_array($documento)) {
        throw new RuntimeException('Documento comercial no encontrado.');
    }

    $documentoItems = flus_facturacion_documento_items_fetch($pdo, $documentoId);
    if ($documentoItems === []) {
        throw new RuntimeException('El documento comercial no tiene items.');
    }

    flus_facturacion_assert_print_item_limit(count($documentoItems));

    $clienteIdBase = $clienteId > 0 ? $clienteId : (int)($documento['cliente_id'] ?? 0);
    $clienteData = $opciones['resolved_cliente'] ?? flus_facturacion_resolver_cliente($pdo, $clienteIdBase);
    if (!is_array($clienteData)) {
        throw new Exception('No se pudo resolver el cliente para la factura.');
    }

    $cliente = isset($clienteData['cliente']) && is_array($clienteData['cliente']) ? $clienteData['cliente'] : null;
    $clienteIdFiscal = (int)($clienteData['cliente_id'] ?? 0);
    $consumidorFinal = !empty($clienteData['consumidor_final']);

    $config = flus_facturacion_config_activa($pdo, false);
    if (!$config) {
        throw new Exception('No hay configuracion de facturacion activa. Configure un punto de venta primero.');
    }

    $puntoVentaPreferido = isset($opciones['punto_venta_preferido']) ? (int)$opciones['punto_venta_preferido'] : 0;
    $puntoVenta = $puntoVentaPreferido > 0 ? $puntoVentaPreferido : max(1, (int)($config['punto_venta'] ?? 1));
    $modoOperacion = flus_facturacion_modo_actual($config, $opciones);
    $modoDemo = $modoOperacion === 'demo';
    $modoFactura = flus_facturacion_facturas_modo_value($pdo, $modoOperacion);
    $tipoCbte = flus_facturacion_resolver_tipo_cbte($config, $cliente, $opciones);
    $tipoStr = obtenerNombreTipoComprobante($tipoCbte);

    $clienteCuit = flus_facturacion_normalizar_doc((string)($cliente['cuit'] ?? ''));
    $emisorCuit = flus_facturacion_cuit_emisor($config);
    if (!$consumidorFinal && $clienteCuit !== '' && $emisorCuit !== '' && $clienteCuit === $emisorCuit) {
        throw new Exception('El CUIT del cliente coincide con el CUIT emisor configurado. Selecciona otro cliente o emite como Consumidor Final.');
    }

    $numeroPreferido = isset($opciones['numero_preferido']) ? (int)$opciones['numero_preferido'] : 0;
    $numero = $numeroPreferido > 0
        ? $numeroPreferido
        : ($modoDemo
            ? max(1, (int)($config['proximo_numero'] ?? 1), flus_facturacion_numero_local_siguiente($pdo, $puntoVenta, $tipoStr, $modoOperacion))
            : max(1, flus_facturacion_numero_local_siguiente($pdo, $puntoVenta, $tipoStr, $modoOperacion)));

    $documentoTotal = round((float)($documento['total'] ?? 0), 2);
    $importes = flus_facturacion_importes_desde_items($documentoItems, $documentoTotal, $tipoCbte);
    $docData = determinarDocumentoCliente($cliente, $consumidorFinal);

    $venta = [
        'id' => (int)($documento['venta_id'] ?? 0),
        'fecha' => (string)($documento['created_at'] ?? date('Y-m-d H:i:s')),
        'total' => $importes['total'],
        'medio_pago' => trim((string)($documento['medio_pago'] ?? 'FACTURA_MANUAL')) ?: 'FACTURA_MANUAL',
        'nota' => trim((string)($documento['nota'] ?? 'Factura manual sin caja')) ?: 'Factura manual sin caja',
        'cliente_id' => $clienteIdFiscal > 0 ? $clienteIdFiscal : null,
    ];

    if ((int)$venta['id'] > 0) {
        $stVenta = $pdo->prepare('SELECT * FROM ventas WHERE id = ? LIMIT 1');
        $stVenta->execute([(int)$venta['id']]);
        $ventaDb = $stVenta->fetch(PDO::FETCH_ASSOC) ?: null;
        if (is_array($ventaDb)) {
            $venta = $ventaDb + $venta;
        } else {
            throw new RuntimeException('El documento comercial apunta a una venta inexistente. Vincula una venta valida antes de facturar o genera una nueva desde el documento.');
        }
    }

    $comprobante = [
        'tipo_cbte' => $tipoCbte,
        'punto_venta' => $puntoVenta,
        'numero' => $numero,
        'concepto' => isset($opciones['concepto']) ? (int)$opciones['concepto'] : 1,
        'fecha' => date('Y-m-d'),
        'importe_total' => $importes['total'],
        'importe_neto' => $importes['neto'],
        'importe_iva' => $importes['iva'],
        'importe_exento' => $importes['exento'],
        'importe_no_gravado' => $importes['no_gravado'],
        'moneda_id' => 'PES',
        'moneda_cotiz' => 1,
        'tipo_doc' => $docData['tipo'],
        'nro_doc' => $docData['numero'],
        'condicion_iva_receptor_id' => determinarCondicionIvaReceptorAfip($cliente, $consumidorFinal),
    ];

    if ($importes['iva'] > 0 && !empty($importes['iva_detalle'])) {
        $comprobante['iva'] = $importes['iva_detalle'];
    }

    $context = [
        'venta' => $venta,
        'documento' => $documento,
        'documento_items' => $documentoItems,
        'cliente' => $cliente,
        'cliente_data' => $clienteData,
        'cliente_id_fiscal' => $clienteIdFiscal,
        'consumidor_final' => $consumidorFinal,
        'config' => $config,
        'punto_venta' => $puntoVenta,
        'modo_operacion' => $modoOperacion,
        'modo_demo' => $modoDemo,
        'modo_factura' => $modoFactura,
        'tipo_cbte' => $tipoCbte,
        'tipo_str' => $tipoStr,
        'numero' => $numero,
        'concepto' => (int)$comprobante['concepto'],
        'importes' => $importes,
        'doc_data' => $docData,
        'comprobante' => $comprobante,
        'origen_manual' => true,
    ];
    $context['request_uid'] = flus_facturacion_request_uid_from_context($context, $opciones);

    return $context;
}

function flus_facturacion_asegurar_registro_desde_documento(PDO $pdo, int $documentoId, int $clienteId, array $opciones = []): array
{
    $repo = flus_facturacion_fiscal_repository($pdo);
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }

    try {
        $context = flus_facturacion_preparar_contexto_desde_documento($pdo, $documentoId, $clienteId, $opciones);
        flus_facturacion_actualizar_cliente_venta_si_corresponde($pdo, (array)($context['venta'] ?? []), (int)($context['cliente_id_fiscal'] ?? 0));

        $requestUid = trim((string)($context['request_uid'] ?? ''));
        $factura = $requestUid !== '' ? $repo->findFacturaByRequestUid($requestUid) : null;
        $facturaDocumento = flus_facturacion_resolver_fallback_factura($repo->findFacturasOrigenByDocumentoId($documentoId), 'documento', $documentoId);
        $ventaId = (int)($context['venta']['id'] ?? 0);
        $facturaVenta = $ventaId > 0
            ? flus_facturacion_resolver_fallback_factura($repo->findFacturasOrigenByVentaId($ventaId), 'venta', $ventaId)
            : null;

        if ($factura === null && $facturaDocumento !== null) {
            $factura = $facturaDocumento;
        }
        if ($factura === null && $facturaVenta !== null) {
            $factura = $facturaVenta;
        }

        if ($factura !== null) {
            $estadoFiscal = flus_facturacion_estado_fiscal_normalizar((string)($factura['estado_fiscal'] ?? (($factura['cae'] ?? '') !== '' ? 'AUTORIZADA' : 'NO_APLICA')));
            if ($estadoFiscal !== 'AUTORIZADA' && trim((string)($factura['cae'] ?? '')) === '') {
                $patch = flus_facturacion_factura_header_base($context, 'PENDIENTE_ENVIO');
                $patch['fiscal_request_uid'] = $requestUid !== '' ? $requestUid : ($factura['fiscal_request_uid'] ?? null);
                $repo->updateFactura((int)$factura['id'], $patch);
                $factura = $repo->findFacturaById((int)$factura['id']) ?: ($factura + $patch);
            }
        } else {
            $facturaId = $repo->insertFactura(flus_facturacion_factura_header_base($context, 'PENDIENTE_ENVIO'));
            $factura = $repo->findFacturaById($facturaId) ?: ['id' => $facturaId] + flus_facturacion_factura_header_base($context, 'PENDIENTE_ENVIO');
        }

        flus_cobranzas_link_factura_from_sale(
            $pdo,
            $ventaId,
            (int)($factura['id'] ?? 0),
            (int)($context['documento']['id'] ?? 0) > 0 ? (int)$context['documento']['id'] : null
        );
        flus_cobranzas_link_receipt_factura_from_documento(
            $pdo,
            (int)($context['documento']['id'] ?? 0),
            (int)($factura['id'] ?? 0)
        );

        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'factura' => $factura,
            'context' => $context,
        ];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function flus_facturacion_preparar_contexto_desde_venta(PDO $pdo, int $ventaId, int $clienteId, array $opciones = []): array
{
    $st = $pdo->prepare('SELECT * FROM ventas WHERE id = ? LIMIT 1');
    $st->execute([$ventaId]);
    $venta = $st->fetch(PDO::FETCH_ASSOC);
    if (!$venta) {
        throw new Exception('Venta no encontrada.');
    }

    flus_facturacion_assert_venta_emitible($venta);

    $printItemCount = flus_facturacion_count_items_venta($pdo, $ventaId);
    flus_facturacion_assert_print_item_limit($printItemCount);

    $clienteData = $opciones['resolved_cliente'] ?? flus_facturacion_resolver_cliente($pdo, $clienteId);
    if (!is_array($clienteData)) {
        throw new Exception('No se pudo resolver el cliente para la factura.');
    }

    $cliente = isset($clienteData['cliente']) && is_array($clienteData['cliente']) ? $clienteData['cliente'] : null;
    $clienteIdFiscal = (int)($clienteData['cliente_id'] ?? 0);
    $consumidorFinal = !empty($clienteData['consumidor_final']);

    $config = flus_facturacion_config_activa($pdo, false);
    if (!$config) {
        throw new Exception('No hay configuracion de facturacion activa. Configure un punto de venta primero.');
    }

    $puntoVentaPreferido = isset($opciones['punto_venta_preferido']) ? (int)$opciones['punto_venta_preferido'] : 0;
    $puntoVenta = $puntoVentaPreferido > 0 ? $puntoVentaPreferido : max(1, (int)($config['punto_venta'] ?? 1));
    $modoOperacion = flus_facturacion_modo_actual($config, $opciones);
    $modoDemo = $modoOperacion === 'demo';
    $modoFactura = flus_facturacion_facturas_modo_value($pdo, $modoOperacion);
    $tipoCbte = flus_facturacion_resolver_tipo_cbte($config, $cliente, $opciones);
    $tipoStr = obtenerNombreTipoComprobante($tipoCbte);

    $clienteCuit = flus_facturacion_normalizar_doc((string)($cliente['cuit'] ?? ''));
    $emisorCuit = flus_facturacion_cuit_emisor($config);
    if (!$consumidorFinal && $clienteCuit !== '' && $emisorCuit !== '' && $clienteCuit === $emisorCuit) {
        throw new Exception('El CUIT del cliente coincide con el CUIT emisor configurado. Selecciona otro cliente o emite como Consumidor Final.');
    }

    $numeroPreferido = isset($opciones['numero_preferido']) ? (int)$opciones['numero_preferido'] : 0;
    $numero = $numeroPreferido > 0
        ? $numeroPreferido
        : ($modoDemo
            ? max(1, (int)($config['proximo_numero'] ?? 1), flus_facturacion_numero_local_siguiente($pdo, $puntoVenta, $tipoStr, $modoOperacion))
            : max(1, flus_facturacion_numero_local_siguiente($pdo, $puntoVenta, $tipoStr, $modoOperacion)));

    $importes = calcularImportesFactura($pdo, $ventaId, $venta, $tipoCbte);
    $docData = determinarDocumentoCliente($cliente, $consumidorFinal);

    $comprobante = [
        'tipo_cbte' => $tipoCbte,
        'punto_venta' => $puntoVenta,
        'numero' => $numero,
        'concepto' => isset($opciones['concepto']) ? (int)$opciones['concepto'] : 1,
        'fecha' => date('Y-m-d'),
        'importe_total' => $importes['total'],
        'importe_neto' => $importes['neto'],
        'importe_iva' => $importes['iva'],
        'importe_exento' => $importes['exento'],
        'importe_no_gravado' => $importes['no_gravado'],
        'moneda_id' => 'PES',
        'moneda_cotiz' => 1,
        'tipo_doc' => $docData['tipo'],
        'nro_doc' => $docData['numero'],
        'condicion_iva_receptor_id' => determinarCondicionIvaReceptorAfip($cliente, $consumidorFinal),
    ];

    if ($importes['iva'] > 0 && !empty($importes['iva_detalle'])) {
        $comprobante['iva'] = $importes['iva_detalle'];
    }

    $context = [
        'venta' => $venta,
        'cliente' => $cliente,
        'cliente_data' => $clienteData,
        'cliente_id_fiscal' => $clienteIdFiscal,
        'consumidor_final' => $consumidorFinal,
        'config' => $config,
        'punto_venta' => $puntoVenta,
        'modo_operacion' => $modoOperacion,
        'modo_demo' => $modoDemo,
        'modo_factura' => $modoFactura,
        'tipo_cbte' => $tipoCbte,
        'tipo_str' => $tipoStr,
        'numero' => $numero,
        'concepto' => (int)$comprobante['concepto'],
        'importes' => $importes,
        'doc_data' => $docData,
        'comprobante' => $comprobante,
        'origen_manual' => !empty($opciones['origen_manual']),
    ];
    $context['request_uid'] = flus_facturacion_request_uid_from_context($context, $opciones);

    return $context;
}

function flus_facturacion_asegurar_registro_desde_venta(PDO $pdo, int $ventaId, int $clienteId, array $opciones = []): array
{
    $repo = flus_facturacion_fiscal_repository($pdo);
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }

    try {
        $ventaLocked = $repo->lockVenta($ventaId);
        if ($ventaLocked === []) {
            throw new RuntimeException('Venta no encontrada.');
        }

        $context = flus_facturacion_preparar_contexto_desde_venta($pdo, $ventaId, $clienteId, $opciones);
        flus_facturacion_actualizar_cliente_venta_si_corresponde($pdo, $ventaLocked, (int)($context['cliente_id_fiscal'] ?? 0));

        $requestUid = trim((string)($context['request_uid'] ?? ''));
        $factura = $requestUid !== '' ? $repo->findFacturaByRequestUid($requestUid) : null;
        $facturaVenta = flus_facturacion_resolver_fallback_factura($repo->findFacturasOrigenByVentaId($ventaId), 'venta', $ventaId);

        if ($factura === null && $facturaVenta !== null) {
            $factura = $facturaVenta;
        }

        if ($factura !== null) {
            $estadoFiscal = flus_facturacion_estado_fiscal_normalizar((string)($factura['estado_fiscal'] ?? (($factura['cae'] ?? '') !== '' ? 'AUTORIZADA' : 'NO_APLICA')));
            if ($estadoFiscal !== 'AUTORIZADA' && trim((string)($factura['cae'] ?? '')) === '') {
                $patch = flus_facturacion_factura_header_base($context, 'PENDIENTE_ENVIO');
                $patch['fiscal_request_uid'] = $requestUid !== '' ? $requestUid : ($factura['fiscal_request_uid'] ?? null);
                $repo->updateFactura((int)$factura['id'], $patch);
                $factura = $repo->findFacturaById((int)$factura['id']) ?: ($factura + $patch);
            }
        } else {
            $facturaId = $repo->insertFactura(flus_facturacion_factura_header_base($context, 'PENDIENTE_ENVIO'));
            $factura = $repo->findFacturaById($facturaId) ?: ['id' => $facturaId] + flus_facturacion_factura_header_base($context, 'PENDIENTE_ENVIO');
        }

        flus_cobranzas_link_factura_from_sale(
            $pdo,
            $ventaId,
            (int)($factura['id'] ?? 0),
            null
        );
        flus_cobranzas_link_receipt_factura_from_documento(
            $pdo,
            (int)($context['documento']['id'] ?? 0),
            (int)($factura['id'] ?? 0)
        );

        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'factura' => $factura,
            'context' => $context,
        ];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function flus_facturacion_ejecutar_envio_arca(PDO $pdo, array $context, array $opciones = []): array
{
    $emitCallback = $opciones['emit_callback'] ?? null;
    if (is_callable($emitCallback)) {
        $resultado = $emitCallback($context);
        if (!is_array($resultado)) {
            throw new RuntimeException('Emit callback invalido.');
        }
        $resultado['raw_request'] = $resultado['raw_request'] ?? ($context['comprobante'] ?? []);
        $resultado['raw_response'] = $resultado['raw_response'] ?? $resultado;
        return $resultado;
    }

    $comprobante = $context['comprobante'];
    $modoOperacion = (string)($context['modo_operacion'] ?? 'demo');
    $modoDemo = !empty($context['modo_demo']);

    if ($modoDemo) {
        return [
            'cae' => 'DEMO' . str_pad((string)($comprobante['numero'] ?? 0), 14, '0', STR_PAD_LEFT),
            'vencimiento' => date('Y-m-d', strtotime('+10 days')),
            'numero' => (int)($comprobante['numero'] ?? 0),
            'raw_request' => $comprobante,
            'raw_response' => ['demo' => true, 'numero' => (int)($comprobante['numero'] ?? 0)],
        ];
    }

    $envEsperado = flus_facturacion_arca_env_esperado($modoOperacion);
    $envActual = flus_facturacion_arca_env_actual();
    if ($envEsperado !== '' && $envActual !== $envEsperado) {
        throw new RuntimeException('El modo ' . flus_facturacion_modo_label($modoOperacion) . ' requiere FLUS_ARCA_ENV=' . strtoupper($envEsperado) . ' pero hoy esta en ' . strtoupper($envActual) . '.');
    }

    flus_facturacion_arca_assert_emitible($pdo, $modoOperacion);

    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';

    $ultimoAfip = ArcaWsfe::getUltimoAutorizado((int)$comprobante['punto_venta'], (int)$comprobante['tipo_cbte']);
    if ($ultimoAfip !== null) {
        $comprobante['numero'] = max((int)$comprobante['numero'], $ultimoAfip + 1);
    } elseif (flus_facturacion_arca_is_availability_error(ArcaWsfe::getLastError())) {
        flus_facturacion_arca_status_write($pdo, 'unavailable', $modoOperacion, (string)ArcaWsfe::getLastError());
        throw new RuntimeException(flus_facturacion_arca_emision_bloqueada_message());
    }

    flus_facturacion_assert_facturas_scope_compatible($pdo, (int)$comprobante['punto_venta'], (string)($context['tipo_str'] ?? ''), (int)$comprobante['numero'], $modoOperacion);

    $resultado = ArcaWsfe::solicitarCAE($comprobante);
    if (!$resultado) {
        $lastError = (string)(ArcaWsfe::getLastError() ?: '');
        if (strpos($lastError, '10016') !== false || stripos($lastError, 'ya fue') !== false) {
            $consulta = ArcaWsfe::consultarComprobante((int)$comprobante['punto_venta'], (int)$comprobante['tipo_cbte'], (int)$comprobante['numero']);
            $normalizadoConsulta = flus_facturacion_resultado_normalizado(is_array($consulta) ? $consulta : []);
            if ($normalizadoConsulta !== null) {
                flus_facturacion_arca_status_write($pdo, 'available', $modoOperacion, '');
                return [
                    'cae' => $normalizadoConsulta['cae'],
                    'vencimiento' => $normalizadoConsulta['vencimiento'],
                    'numero' => $normalizadoConsulta['numero'] > 0 ? $normalizadoConsulta['numero'] : (int)$comprobante['numero'],
                    'raw_request' => $comprobante,
                    'raw_response' => $consulta,
                ];
            }
        }

        if (flus_facturacion_arca_is_availability_error($lastError)) {
            flus_facturacion_arca_status_write($pdo, 'unavailable', $modoOperacion, $lastError);
        }
        throw new RuntimeException(flus_facturacion_humanizar_error_arca($lastError));
    }

    flus_facturacion_arca_status_write($pdo, 'available', $modoOperacion, '');
    $resultado['raw_request'] = $comprobante;
    $resultado['raw_response'] = ArcaWsfe::getLastResponse() ?? $resultado;
    return $resultado;
}

function flus_facturacion_finalizar_factura_autorizada(PDO $pdo, FacturaFiscalRepository $repo, array $factura, array $context, array $resultado, array $meta = []): int
{
    $normalizado = flus_facturacion_resultado_normalizado($resultado);
    if ($normalizado === null) {
        throw new RuntimeException('No se pudo normalizar la respuesta de ARCA para finalizar la factura.');
    }

    $facturaId = (int)($factura['id'] ?? 0);
    if ($facturaId <= 0) {
        throw new RuntimeException('Factura local inexistente para finalizar.');
    }

    $requestUid = trim((string)($context['request_uid'] ?? $factura['fiscal_request_uid'] ?? ''));
    $ventaId = (int)($factura['venta_id'] ?? $context['venta']['id'] ?? 0);
    $intentoNo = max(1, (int)($factura['fiscal_intentos'] ?? 0));
    $timestamp = date('Y-m-d H:i:s');
    $estadoFinal = !empty($meta['recovered']) ? 'RECUPERADA' : 'AUTORIZADA';

    try {
        $pdo->beginTransaction();

        $facturaLocked = $repo->lockFacturaById($facturaId);
        if ($facturaLocked === []) {
            throw new RuntimeException('La factura local ya no existe.');
        }
        if ($ventaId > 0) {
            $repo->lockVenta($ventaId);
        }

        $numero = (int)($normalizado['numero'] ?? 0);
        if ($numero <= 0) {
            $numero = (int)($context['numero'] ?? $facturaLocked['numero'] ?? 0);
        }

        $repo->updateFactura($facturaId, [
            'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
            'naturaleza' => 'FACTURA',
            'tipo' => (string)($context['tipo_str'] ?? $facturaLocked['tipo'] ?? ''),
            'tipo_cbte' => (int)($context['tipo_cbte'] ?? 0),
            'punto_venta' => (int)($context['punto_venta'] ?? 0),
            'numero' => $numero,
            'importe_neto' => round((float)($context['importes']['neto'] ?? 0), 2),
            'importe_iva' => round((float)($context['importes']['iva'] ?? 0), 2),
            'importe_exento' => round((float)($context['importes']['exento'] ?? 0), 2),
            'importe_no_gravado' => round((float)($context['importes']['no_gravado'] ?? 0), 2),
            'total' => round((float)($context['importes']['total'] ?? 0), 2),
            'cae' => (string)$normalizado['cae'],
            'cae_vto' => flus_facturacion_normalizar_cae_vto((string)$normalizado['vencimiento']),
            'estado' => 'EMITIDA',
            'modo' => (string)($context['modo_factura'] ?? 'demo'),
            'doc_tipo' => (int)($context['doc_data']['tipo'] ?? 0) ?: null,
            'doc_numero' => (string)($context['doc_data']['numero'] ?? '') ?: null,
            'condicion_iva_receptor_id' => $context['comprobante']['condicion_iva_receptor_id'] ?? null,
            'moneda_id' => 'PES',
            'moneda_cotiz' => 1,
            'estado_fiscal' => $estadoFinal,
            'fiscal_request_uid' => $requestUid !== '' ? $requestUid : null,
            'fiscal_intentos' => $intentoNo,
            'fiscal_error_code' => null,
            'fiscal_error_message' => null,
            'fiscal_requested_at' => $facturaLocked['fiscal_requested_at'] ?? $timestamp,
            'fiscal_approved_at' => $timestamp,
        ]);

        if ($ventaId > 0) {
            flus_facturacion_upsert_venta_fiscal($pdo, $ventaId, [
                'punto_venta' => (int)($context['punto_venta'] ?? 0),
                'tipo_cbte' => (int)($context['tipo_cbte'] ?? 0),
                'numero' => $numero,
                'cae' => (string)$normalizado['cae'],
                'cae_vto' => (string)$normalizado['vencimiento'],
                'moneda_id' => 'PES',
                'moneda_cotiz' => 1,
            ]);

            if (flus_column_exists($pdo, 'ventas', 'facturada')) {
                $st = $pdo->prepare('UPDATE ventas SET facturada = 1 WHERE id = ?');
                $st->execute([$ventaId]);
            }
        }

        if (!empty($context['modo_demo']) && flus_column_exists($pdo, 'config_facturacion', 'proximo_numero')) {
            $configId = (int)($context['config']['id'] ?? 0);
            if ($configId > 0) {
                $stCfg = $pdo->prepare('UPDATE config_facturacion SET proximo_numero = GREATEST(COALESCE(proximo_numero, 1), :nuevo) WHERE id = :id');
                $stCfg->execute([
                    ':nuevo' => $numero + 1,
                    ':id' => $configId,
                ]);
            }
        }

        if ($requestUid !== '') {
            $repo->updateArcaEventResult($requestUid, [
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                'factura_id' => $facturaId,
                'operacion' => flus_facturacion_evento_operacion($context),
                'resultado' => 'OK',
                'intento_no' => $intentoNo,
                'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                'error_code' => null,
                'error_message' => null,
                'request_json' => json_encode($meta['raw_request'] ?? $context['comprobante'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_json' => json_encode($meta['raw_response'] ?? $resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => $timestamp,
            ]);
        }

        flus_cobranzas_link_factura_from_sale(
            $pdo,
            $ventaId,
            $facturaId,
            (int)($context['documento']['id'] ?? 0) > 0 ? (int)$context['documento']['id'] : null
        );
        flus_cobranzas_link_receipt_factura_from_documento(
            $pdo,
            (int)($context['documento']['id'] ?? 0),
            $facturaId
        );

        $pdo->commit();
        return $facturaId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($requestUid !== '') {
            try {
                $repo->updateArcaEventResult($requestUid, [
                    'venta_id' => $ventaId > 0 ? $ventaId : null,
                    'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                    'factura_id' => $facturaId,
                    'operacion' => flus_facturacion_evento_operacion($context),
                    'resultado' => 'OK',
                    'intento_no' => $intentoNo,
                    'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                    'error_code' => 'ERROR_POST_ARCA',
                    'error_message' => $e->getMessage(),
                    'request_json' => json_encode($meta['raw_request'] ?? $context['comprobante'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'response_json' => json_encode($meta['raw_response'] ?? $resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $ignored) {
            }
        }

        try {
            $repo->updateFacturaFiscalState($facturaId, 'ERROR_POST_ARCA', [
                'fiscal_error_code' => 'ERROR_POST_ARCA',
                'fiscal_error_message' => $e->getMessage(),
            ]);
        } catch (Throwable $ignored) {
        }

        throw new RuntimeException('ARCA autorizo el comprobante pero FLUS no pudo cerrar la registracion local. Reintenta para recovery simple. Detalle: ' . $e->getMessage(), 0, $e);
    }
}

function flus_facturacion_intentar_recovery_simple(PDO $pdo, FacturaFiscalRepository $repo, array $factura, array $context): ?int
{
    $requestUid = trim((string)($factura['fiscal_request_uid'] ?? $context['request_uid'] ?? ''));
    if ($requestUid === '') {
        return null;
    }

    $evento = $repo->findArcaEventByRequestUid($requestUid);
    if (is_array($evento)) {
        $response = flus_facturacion_json_decode_assoc((string)($evento['response_json'] ?? ''));
        $normalizado = flus_facturacion_resultado_normalizado($response);
        if ($normalizado !== null) {
            return flus_facturacion_finalizar_factura_autorizada($pdo, $repo, $factura, $context, $normalizado, [
                'raw_request' => flus_facturacion_json_decode_assoc((string)($evento['request_json'] ?? '')),
                'raw_response' => $response,
                'recovered' => true,
                'recovery_source' => 'EVENTO_ARCA',
            ]);
        }
    }

    if (!empty($context['modo_demo'])) {
        return null;
    }

    $numero = (int)($factura['numero'] ?? $context['numero'] ?? 0);
    if ($numero <= 0) {
        return null;
    }

    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
    $consulta = ArcaWsfe::consultarComprobante((int)($context['punto_venta'] ?? 0), (int)($context['tipo_cbte'] ?? 0), $numero);
    $normalizado = flus_facturacion_resultado_normalizado(is_array($consulta) ? $consulta : []);
    if ($normalizado === null) {
        return null;
    }

    if ($requestUid !== '') {
        $repo->updateArcaEventResult($requestUid, [
            'venta_id' => (int)($factura['venta_id'] ?? 0) > 0 ? (int)$factura['venta_id'] : null,
            'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
            'factura_id' => (int)($factura['id'] ?? 0) > 0 ? (int)$factura['id'] : null,
            'operacion' => flus_facturacion_evento_operacion($context),
            'resultado' => 'OK',
            'modo' => (string)($context['modo_operacion'] ?? 'demo'),
            'response_json' => json_encode($consulta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
    }

    return flus_facturacion_finalizar_factura_autorizada($pdo, $repo, $factura, $context, $normalizado, [
        'raw_request' => $context['comprobante'] ?? [],
        'raw_response' => $consulta,
        'recovered' => true,
        'recovery_source' => 'CONSULTA_ARCA',
    ]);
}

function flus_facturacion_procesar_factura_registrada(PDO $pdo, array $registro, array $opciones = []): int
{
    $repo = flus_facturacion_fiscal_repository($pdo);
    $factura = is_array($registro['factura'] ?? null) ? $registro['factura'] : [];
    $context = is_array($registro['context'] ?? null) ? $registro['context'] : [];

    $facturaId = (int)($factura['id'] ?? 0);
    if ($facturaId <= 0) {
        throw new RuntimeException('No se pudo registrar la factura local.');
    }

    $estadoFiscal = flus_facturacion_estado_fiscal_normalizar((string)($factura['estado_fiscal'] ?? (($factura['cae'] ?? '') !== '' ? 'AUTORIZADA' : 'NO_APLICA')));
    if ($estadoFiscal === 'AUTORIZADA' || trim((string)($factura['cae'] ?? '')) !== '') {
        return $facturaId;
    }

    if ($estadoFiscal === 'RECHAZADA') {
        $msg = trim((string)($factura['fiscal_error_message'] ?? ''));
        throw new RuntimeException($msg !== '' ? $msg : 'La factura ya fue rechazada por ARCA.');
    }

    $recovered = flus_facturacion_intentar_recovery_simple($pdo, $repo, $factura, $context);
    if ($recovered !== null) {
        return $recovered;
    }

    if ($estadoFiscal === 'ERROR_POST_ARCA') {
        throw new RuntimeException('La factura quedo en ERROR_POST_ARCA. FLUS no la reenviara automaticamente a ARCA: primero hay que regularizarla o confirmar manualmente el resultado remoto.');
    }

    $requestUid = trim((string)($context['request_uid'] ?? $factura['fiscal_request_uid'] ?? ''));
    $intentoNo = max(1, (int)($factura['fiscal_intentos'] ?? 0) + 1);
    $timestamp = date('Y-m-d H:i:s');
    $ventaId = (int)($factura['venta_id'] ?? $context['venta']['id'] ?? 0);

    $repo->updateFactura($facturaId, [
        'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
        'tipo' => (string)($context['tipo_str'] ?? ''),
        'tipo_cbte' => (int)($context['tipo_cbte'] ?? 0),
        'punto_venta' => (int)($context['punto_venta'] ?? 0),
        'numero' => (int)($factura['numero'] ?? $context['numero'] ?? 0),
        'importe_neto' => round((float)($context['importes']['neto'] ?? 0), 2),
        'importe_iva' => round((float)($context['importes']['iva'] ?? 0), 2),
        'importe_exento' => round((float)($context['importes']['exento'] ?? 0), 2),
        'importe_no_gravado' => round((float)($context['importes']['no_gravado'] ?? 0), 2),
        'total' => round((float)($context['importes']['total'] ?? 0), 2),
        'estado' => 'PENDIENTE',
        'estado_fiscal' => 'PENDIENTE_ENVIO',
        'fiscal_request_uid' => $requestUid !== '' ? $requestUid : null,
        'fiscal_intentos' => $intentoNo,
        'fiscal_requested_at' => $timestamp,
        'fiscal_error_code' => null,
        'fiscal_error_message' => null,
    ]);

    $requestPayload = flus_facturacion_request_payload($context);
    $requestJson = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $existingEvent = $requestUid !== '' ? $repo->findArcaEventByRequestUid($requestUid) : null;
    if ($requestUid !== '') {
        if ($existingEvent === null) {
            $repo->insertArcaEvent([
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                'factura_id' => $facturaId,
                'request_uid' => $requestUid,
                'operacion' => flus_facturacion_evento_operacion($context),
                'resultado' => 'PENDIENTE',
                'intento_no' => $intentoNo,
                'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                'request_json' => $requestJson,
                'created_at' => $timestamp,
            ]);
        } else {
            $repo->updateArcaEventResult($requestUid, [
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                'factura_id' => $facturaId,
                'operacion' => flus_facturacion_evento_operacion($context),
                'resultado' => 'PENDIENTE',
                'intento_no' => $intentoNo,
                'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                'error_code' => null,
                'error_message' => null,
                'request_json' => $requestJson,
                'finished_at' => null,
            ]);
        }
    }

    try {
        $resultado = flus_facturacion_ejecutar_envio_arca($pdo, $context, $opciones);
    } catch (Throwable $e) {
        $message = trim((string)$e->getMessage());
        $estadoError = flus_facturacion_estado_fiscal_por_error($message);
        $errorCode = flus_facturacion_error_code($message);

        if ($requestUid !== '') {
            $repo->updateArcaEventResult($requestUid, [
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                'factura_id' => $facturaId,
                'operacion' => flus_facturacion_evento_operacion($context),
                'resultado' => 'ERROR',
                'intento_no' => $intentoNo,
                'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                'error_code' => $errorCode,
                'error_message' => $message,
                'request_json' => $requestJson,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $repo->updateFacturaFiscalState($facturaId, $estadoError, [
            'estado' => $estadoError === 'RECHAZADA' ? 'RECHAZADA' : 'PENDIENTE',
            'fiscal_intentos' => $intentoNo,
            'fiscal_error_code' => $errorCode,
            'fiscal_error_message' => $message,
            'fiscal_requested_at' => $timestamp,
        ]);
        throw $e;
    }

    return flus_facturacion_finalizar_factura_autorizada($pdo, $repo, $factura + ['fiscal_intentos' => $intentoNo], $context, $resultado, [
        'raw_request' => $resultado['raw_request'] ?? ($context['comprobante'] ?? []),
        'raw_response' => $resultado['raw_response'] ?? $resultado,
    ]);
}

/**
 * Emite una factura para una venta usando una capa unificada de registro + envio.
 */
function flus_facturacion_emitir_desde_venta(PDO $pdo, int $ventaId, int $clienteId, array $opciones = []): int
{
    $registro = flus_facturacion_asegurar_registro_desde_venta($pdo, $ventaId, $clienteId, $opciones);
    return flus_facturacion_procesar_factura_registrada($pdo, $registro, $opciones);
}

/**
 * Crea una factura desde una venta existente.
 */
function crearFacturaDesdeVenta(int $ventaId, int $clienteId, array $opciones = []): int
{
    $pdo = getPDO();

    if (!flus_facturacion_habilitada($pdo)) {
        throw new Exception('El modulo de facturacion no esta habilitado.');
    }

    return flus_facturacion_emitir_desde_venta($pdo, $ventaId, $clienteId, $opciones);
}

function flus_facturacion_emitir_desde_documento(PDO $pdo, int $documentoId, int $clienteId, array $opciones = []): int
{
    if (!flus_facturacion_habilitada($pdo)) {
        throw new RuntimeException('El modulo de facturacion no esta habilitado.');
    }

    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    if (!is_array($documento)) {
        throw new RuntimeException('Documento comercial no encontrado.');
    }

    $tipoDocumento = flus_facturacion_documento_tipo_normalizar((string)($documento['tipo_documento'] ?? ''));
    if (!in_array($tipoDocumento, ['FACTURA_MANUAL', 'PRESUPUESTO', 'REMITO'], true)) {
        throw new RuntimeException('El documento indicado no se puede facturar en esta fase.');
    }
    if (flus_facturacion_documento_estado_bloqueado((string)($documento['estado'] ?? ''))) {
        throw new RuntimeException('El documento indicado esta anulado o cancelado.');
    }

    $clienteIdBase = $clienteId > 0 ? $clienteId : (int)($documento['cliente_id'] ?? 0);
    if ($clienteIdBase <= 0) {
        throw new RuntimeException('El documento debe tener cliente vinculado para emitir factura.');
    }

    $registro = flus_facturacion_asegurar_registro_desde_documento($pdo, $documentoId, $clienteIdBase, $opciones);
    $facturaId = flus_facturacion_procesar_factura_registrada($pdo, $registro, $opciones);
    flus_facturacion_documento_actualizar_estado($pdo, $documentoId, 'FACTURADO');

    return $facturaId;
}


function flus_facturacion_regularizar_factura(PDO $pdo, int $facturaId, array $opciones = []): int
{
    if ($facturaId <= 0) {
        throw new RuntimeException('Factura invalida para regularizar.');
    }
    if (!flus_facturacion_habilitada($pdo)) {
        throw new RuntimeException('El modulo de facturacion no esta habilitado.');
    }

    $repo = flus_facturacion_fiscal_repository($pdo);
    $factura = $repo->findFacturaById($facturaId);
    if (!is_array($factura)) {
        throw new RuntimeException('Factura no encontrada para regularizar.');
    }

    $estadoFiscal = flus_facturacion_estado_fiscal_normalizar((string)($factura['estado_fiscal'] ?? 'NO_APLICA'));
    if ($estadoFiscal === 'AUTORIZADA' || $estadoFiscal === 'RECUPERADA' || trim((string)($factura['cae'] ?? '')) !== '') {
        return (int)$factura['id'];
    }
    if ($estadoFiscal === 'RECHAZADA') {
        $msg = trim((string)($factura['fiscal_error_message'] ?? ''));
        throw new RuntimeException($msg !== '' ? $msg : 'La factura fue rechazada y no se puede regularizar automaticamente.');
    }

    $requestUid = trim((string)($factura['fiscal_request_uid'] ?? $opciones['request_uid'] ?? ''));
    $clienteId = (int)($factura['cliente_id'] ?? 0);
    $documentoId = (int)($factura['documento_id'] ?? 0);
    $ventaId = (int)($factura['venta_id'] ?? 0);
    $eventoArca = $requestUid !== '' ? $repo->findArcaEventByRequestUid($requestUid) : null;
    $requestJsonEvento = is_array($eventoArca) ? flus_facturacion_json_decode_assoc((string)($eventoArca['request_json'] ?? '')) : [];
    if ($requestUid === '' || $requestJsonEvento === []) {
        flus_facturacion_log_event('recovery_snapshot_fallback', [
            'factura_id' => $facturaId,
            'request_uid' => $requestUid,
            'has_event' => is_array($eventoArca),
            'has_request_json' => $requestJsonEvento !== [],
        ]);
    }
    $snapshotPayload = flus_facturacion_snapshot_payload_desde_factura($factura, is_array($eventoArca) ? $eventoArca : []);

    if ($clienteId <= 0 && $documentoId > 0) {
        $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
        $clienteId = (int)($documento['cliente_id'] ?? 0);
    }
    if ($clienteId <= 0 && $ventaId > 0 && flus_table_exists($pdo, 'ventas')) {
        $stVenta = $pdo->prepare('SELECT cliente_id FROM ventas WHERE id = ? LIMIT 1');
        $stVenta->execute([$ventaId]);
        $clienteId = (int)($stVenta->fetchColumn() ?: 0);
    }
    if ($clienteId <= 0) throw new RuntimeException('La factura no tiene cliente suficiente para rearmar el contexto fiscal.');
    $baseOpciones = flus_facturacion_opciones_regularizacion($requestUid !== '' ? ($opciones + ['request_uid' => $requestUid]) : $opciones, $snapshotPayload, $factura);
    if ($documentoId > 0) {
        $contextoActual = flus_facturacion_preparar_contexto_desde_documento($pdo, $documentoId, $clienteId, $baseOpciones + ['origen_regularizacion' => true]);
    } elseif ($ventaId > 0) {
        $contextoActual = flus_facturacion_preparar_contexto_desde_venta($pdo, $ventaId, $clienteId, $baseOpciones + ['origen_regularizacion' => true]);
    } else {
        throw new RuntimeException('La factura no tiene venta ni documento asociado para regularizar automaticamente.');
    }
    $payloadActual = flus_facturacion_request_payload($contextoActual);
    $snapshotDiff = flus_facturacion_request_payload_diff($snapshotPayload, $payloadActual);
    if ($snapshotDiff !== []) throw new RuntimeException('La factura cambio desde el intento fiscal original (' . implode(', ', $snapshotDiff) . '). Requiere revision manual antes de regularizar.');
    if ($documentoId > 0) {
        $registro = flus_facturacion_asegurar_registro_desde_documento($pdo, $documentoId, $clienteId, $baseOpciones + ['origen_regularizacion' => true]);
    } elseif ($ventaId > 0) {
        $registro = flus_facturacion_asegurar_registro_desde_venta($pdo, $ventaId, $clienteId, $baseOpciones + ['origen_regularizacion' => true]);
    } else {
        throw new RuntimeException('La factura no tiene venta ni documento asociado para regularizar automaticamente.');
    }
    return flus_facturacion_procesar_factura_registrada($pdo, $registro, $baseOpciones + ['origen_regularizacion' => true]);
}

function regularizarFacturaFiscal(int $facturaId, array $opciones = []): int
{
    $pdo = getPDO();
    return flus_facturacion_regularizar_factura($pdo, $facturaId, $opciones);
}

function emitirFacturaDesdeDocumento(int $documentoId, int $clienteId, array $opciones = []): int
{
    $pdo = getPDO();
    return flus_facturacion_emitir_desde_documento($pdo, $documentoId, $clienteId, $opciones);
}

/**
 * Crea o reutiliza una venta manual y luego emite por la misma capa de negocio.
 */
function crearFacturaManual(array $payload): int
{
    $pdo = getPDO();

    if (!flus_facturacion_habilitada($pdo)) {
        throw new Exception('El modulo de facturacion no esta habilitado.');
    }

    $clienteId = isset($payload['cliente_id']) ? (int)$payload['cliente_id'] : 0;
    $items = flus_facturacion_normalize_manual_items((array)($payload['items'] ?? []));
    $meta = [
        'nota' => trim((string)($payload['nota'] ?? 'Factura manual sin caja')),
        'medio_pago' => trim((string)($payload['medio_pago'] ?? 'FACTURA_MANUAL')),
    ];
    $opciones = isset($payload['opciones']) && is_array($payload['opciones']) ? $payload['opciones'] : [];
    $clienteData = (isset($opciones['resolved_cliente']) && is_array($opciones['resolved_cliente']))
        ? $opciones['resolved_cliente']
        : flus_facturacion_resolver_cliente($pdo, $clienteId);

    if (!is_array($clienteData)) {
        throw new RuntimeException('No se pudo resolver el cliente para la factura manual.');
    }

    $clienteFiscalId = (int)($clienteData['cliente_id'] ?? 0);
    $requestUid = flus_facturacion_request_uid_manual($clienteFiscalId, $items, $meta, $opciones);

    $repo = flus_facturacion_fiscal_repository($pdo);
    $baseExistente = flus_facturacion_manual_resolver_base_existente($pdo, $repo, $requestUid, $clienteFiscalId, $items);
    $facturaExistente = is_array($baseExistente['factura'] ?? null) ? $baseExistente['factura'] : null;
    $ventaId = (int)($baseExistente['venta_id'] ?? 0);
    $documentoId = (int)($baseExistente['documento_id'] ?? 0);

    if (is_array($facturaExistente)) {
        flus_facturacion_manual_retry_state_guardar(
            $requestUid,
            $ventaId,
            $clienteFiscalId,
            $items,
            (string)($facturaExistente['estado_fiscal'] ?? 'PENDIENTE_ENVIO'),
            (int)($facturaExistente['id'] ?? 0)
        );
    }

    if ($documentoId <= 0 && flus_facturacion_documentos_table_ready($pdo)) {
        $documentoId = flus_facturacion_documento_crear_manual($pdo, $clienteFiscalId, $items, $meta, [
            'request_uid' => $requestUid,
        ]);
    }

    if ($documentoId > 0 && $ventaId <= 0) {
        $documentoBase = flus_facturacion_documento_buscar($pdo, $documentoId);
        $ventaId = (int)($documentoBase['venta_id'] ?? 0);
    }

    if ($ventaId > 0) {
        flus_facturacion_manual_retry_state_guardar(
            $requestUid,
            $ventaId,
            $clienteFiscalId,
            $items,
            (string)($facturaExistente['estado_fiscal'] ?? 'PENDIENTE_ENVIO'),
            (int)($facturaExistente['id'] ?? 0)
        );
    }

    if ($ventaId <= 0) {
        $pdo->beginTransaction();
        try {
            $ventaId = flus_facturacion_crear_venta_manual($pdo, $clienteFiscalId, $items, $meta);
            if ($documentoId > 0) {
                flus_facturacion_documento_actualizar_venta($pdo, $documentoId, $ventaId);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($documentoId > 0) {
                flus_facturacion_documento_actualizar_estado($pdo, $documentoId, 'ERROR');
            }
            throw $e;
        }

        flus_facturacion_manual_retry_state_guardar(
            $requestUid,
            $ventaId,
            $clienteFiscalId,
            $items,
            is_array($facturaExistente) ? (string)($facturaExistente['estado_fiscal'] ?? 'PENDIENTE_ENVIO') : 'PENDIENTE_ENVIO',
            is_array($facturaExistente) ? (int)($facturaExistente['id'] ?? 0) : 0
        );
    } elseif ($documentoId > 0) {
        flus_facturacion_documento_actualizar_venta($pdo, $documentoId, $ventaId);
    }

    if ($documentoId > 0) {
        $registro = flus_facturacion_asegurar_registro_desde_documento($pdo, $documentoId, $clienteId, $opciones + [
            'resolved_cliente' => $clienteData,
            'request_uid' => $requestUid,
            'origen_manual' => true,
        ]);
    } else {
        $registro = flus_facturacion_asegurar_registro_desde_venta($pdo, $ventaId, $clienteId, $opciones + [
            'resolved_cliente' => $clienteData,
            'request_uid' => $requestUid,
            'origen_manual' => true,
        ]);
    }

    $facturaBase = is_array($registro['factura'] ?? null) ? $registro['factura'] : [];
    flus_facturacion_manual_retry_state_guardar(
        $requestUid,
        $ventaId,
        $clienteFiscalId,
        $items,
        (string)($facturaBase['estado_fiscal'] ?? 'PENDIENTE_ENVIO'),
        (int)($facturaBase['id'] ?? 0)
    );

    try {
        $facturaId = flus_facturacion_procesar_factura_registrada($pdo, $registro, $opciones + [
            'resolved_cliente' => $clienteData,
            'request_uid' => $requestUid,
            'origen_manual' => true,
        ]);
    } catch (Throwable $e) {
        $facturaActual = (int)($facturaBase['id'] ?? 0) > 0 ? $repo->findFacturaById((int)$facturaBase['id']) : null;
        flus_facturacion_manual_retry_state_guardar(
            $requestUid,
            $ventaId,
            $clienteFiscalId,
            $items,
            (string)($facturaActual['estado_fiscal'] ?? $facturaBase['estado_fiscal'] ?? 'PENDIENTE_ENVIO'),
            (int)($facturaActual['id'] ?? $facturaBase['id'] ?? 0)
        );
        if ($documentoId > 0) {
            flus_facturacion_documento_actualizar_estado($pdo, $documentoId, 'ERROR');
        }
        throw $e;
    }

    $facturaEmitida = $repo->findFacturaById($facturaId);
    flus_facturacion_manual_retry_state_guardar(
        $requestUid,
        $ventaId,
        $clienteFiscalId,
        $items,
        (string)($facturaEmitida['estado_fiscal'] ?? 'AUTORIZADA'),
        $facturaId
    );

    if ($documentoId > 0) {
        flus_facturacion_documento_actualizar_estado($pdo, $documentoId, 'FACTURADO');
    }

    return $facturaId;
}

function flus_facturacion_print_item_limit(?PDO $pdo = null): int
{
    $pdo = $pdo ?? getPDO();
    $raw = trim((string)config_get($pdo, 'facturacion_print_item_limit', '22'));
    $limit = ctype_digit($raw) ? (int)$raw : 22;

    // Limite operativo de impresion, configurable por comercio.
    return max(1, min(200, $limit));
}

function flus_facturacion_print_item_limit_message(int $count, ?int $limit = null): string
{
    $limit = $limit ?? flus_facturacion_print_item_limit();
    return 'La factura de una sola hoja admite hasta ' . $limit . ' items. Esta operacion tiene ' . $count . '. Divide los items restantes en otra factura.';
}

function flus_facturacion_assert_print_item_limit(int $count): void
{
    $limit = flus_facturacion_print_item_limit();
    if ($count > $limit) {
        throw new RuntimeException(flus_facturacion_print_item_limit_message($count, $limit));
    }
}

function flus_facturacion_count_items_venta(PDO $pdo, int $ventaId): int
{
    if ($ventaId <= 0) {
        return 0;
    }

    $manualItems = flus_facturacion_manual_items_fetch($pdo, $ventaId);
    if ($manualItems !== []) {
        return count($manualItems);
    }

    if (!flus_table_exists($pdo, 'venta_items')) {
        return 0;
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM venta_items WHERE venta_id = ?');
    $st->execute([$ventaId]);
    $count = $st->fetchColumn();
    return $count !== false ? (int)$count : 0;
}
/**
 * Determina la condicion de IVA del receptor.
 */
function determinarCondIvaReceptor(?array $cliente): string
{
    if (!$cliente) {
        return 'CF';
    }

    $condIva = strtoupper(trim((string)($cliente['cond_iva'] ?? '')));
    $mapa = [
        'RI' => 'RI',
        'RESPONSABLE INSCRIPTO' => 'RI',
        'MT' => 'MT',
        'MONOTRIBUTISTA' => 'MT',
        'MONOTRIBUTO' => 'MT',
        'EX' => 'EX',
        'EXENTO' => 'EX',
        'CF' => 'CF',
        'CONSUMIDOR FINAL' => 'CF',
    ];

    return $mapa[$condIva] ?? 'CF';
}

/**
 * Determina el tipo de comprobante segun condiciones de IVA.
 */
function determinarTipoComprobante(string $condIvaEmisor, string $condIvaReceptor): int
{
    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
    return ArcaWsfe::determinarTipoComprobante($condIvaEmisor, $condIvaReceptor);
}

/**
 * Determina tipo y numero de documento del cliente.
 */
function determinarDocumentoCliente(?array $cliente, bool $consumidorFinal = false): array
{
    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';

    if ($consumidorFinal) {
        return [
            'tipo' => ArcaWsfe::DOC_SIN_IDENTIFICAR,
            'numero' => '0',
        ];
    }

    $cuit = $cliente['cuit'] ?? null;
    $dni = $cliente['dni'] ?? $cliente['documento'] ?? null;

    return ArcaWsfe::determinarTipoDocumento($cuit, $dni);
}

/**
 * Devuelve la condicion frente al IVA del receptor para WSFE.
 */
function determinarCondicionIvaReceptorAfip(?array $cliente, bool $consumidorFinal = false): int
{
    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';

    if ($consumidorFinal) {
        return ArcaWsfe::IVA_CONSUMIDOR_FINAL;
    }

    return match (determinarCondIvaReceptor($cliente)) {
        'RI' => ArcaWsfe::IVA_RESPONSABLE_INSCRIPTO,
        'MT' => ArcaWsfe::IVA_MONOTRIBUTISTA,
        'EX' => ArcaWsfe::IVA_EXENTO,
        default => ArcaWsfe::IVA_CONSUMIDOR_FINAL,
    };
}
function calcularImportesFactura(PDO $pdo, int $ventaId, array $venta, int $tipoCbte): array
{
    $total = (float)($venta['total'] ?? 0);
    $esFacturaC = in_array($tipoCbte, [11, 12, 13], true);

    if ($esFacturaC) {
        return [
            'total' => $total,
            'neto' => $total,
            'iva' => 0.0,
            'exento' => 0.0,
            'no_gravado' => 0.0,
            'iva_detalle' => [],
        ];
    }

    $manualItems = flus_facturacion_manual_items_fetch($pdo, $ventaId);
    if ($manualItems !== []) {
        return flus_facturacion_importes_desde_items($manualItems, $total, $tipoCbte);
    }

    if (!flus_table_exists($pdo, 'venta_items')) {
        $neto = round($total / 1.21, 2);
        $iva = round($total - $neto, 2);
        return [
            'total' => round($total, 2),
            'neto' => $neto,
            'iva' => $iva,
            'exento' => 0.0,
            'no_gravado' => 0.0,
            'iva_detalle' => [['id' => 5, 'base' => $neto, 'importe' => $iva]],
        ];
    }

    $usaIvaProducto = flus_table_exists($pdo, 'productos') && flus_column_exists($pdo, 'productos', 'iva_porcentaje');
    $ivaExpr = $usaIvaProducto ? 'COALESCE(p.iva_porcentaje, 21)' : '21';
    $joinProductos = $usaIvaProducto ? 'LEFT JOIN productos p ON vi.producto_id = p.id' : '';

    $neto = 0.0;
    $iva = 0.0;
    $ivaDetalle = [];

    try {
        $st = $pdo->prepare("\n            SELECT\n                {$ivaExpr} AS iva_porcentaje,\n                SUM(vi.subtotal) AS subtotal\n            FROM venta_items vi\n            {$joinProductos}\n            WHERE vi.venta_id = ?\n            GROUP BY {$ivaExpr}\n        ");
        $st->execute([$ventaId]);
        $ivaGroups = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $ivaGroups = [];
    }

    if ($ivaGroups !== []) {
        foreach ($ivaGroups as $group) {
            $pct = (float)($group['iva_porcentaje'] ?? 21);
            $subtotal = (float)($group['subtotal'] ?? 0);
            $baseImp = $subtotal / (1 + $pct / 100);
            $impIva = $subtotal - $baseImp;

            $neto += $baseImp;
            $iva += $impIva;

            $ivaDetalle[] = [
                'id' => obtenerIdAlicuotaAfip($pct),
                'base' => round($baseImp, 2),
                'importe' => round($impIva, 2),
            ];
        }
    } else {
        $neto = $total / 1.21;
        $iva = $total - $neto;
        $ivaDetalle[] = [
            'id' => 5,
            'base' => round($neto, 2),
            'importe' => round($iva, 2),
        ];
    }

    $neto = round($neto, 2);
    $iva = round($iva, 2);
    $exento = 0.0;
    $noGravado = 0.0;

    $calculado = round($neto + $iva + $exento + $noGravado, 2);
    $diferencia = round($total - $calculado, 2);

    if (abs($diferencia) > 0 && abs($diferencia) <= 0.02) {
        $neto = round($neto + $diferencia, 2);
        if ($ivaDetalle !== []) {
            $lastIdx = count($ivaDetalle) - 1;
            $ivaDetalle[$lastIdx]['base'] = round($ivaDetalle[$lastIdx]['base'] + $diferencia, 2);
        }
    }

    return [
        'total' => round($total, 2),
        'neto' => $neto,
        'iva' => $iva,
        'exento' => $exento,
        'no_gravado' => $noGravado,
        'iva_detalle' => $ivaDetalle,
    ];
}

/**
 * Obtiene el ID de alicuota AFIP segun porcentaje.
 */
function obtenerIdAlicuotaAfip(float $porcentaje): int
{
    $mapa = [
        '0.0' => 3,
        '2.5' => 9,
        '5.0' => 8,
        '10.5' => 4,
        '21.0' => 5,
        '27.0' => 6,
    ];

    $key = sprintf('%.1f', round($porcentaje, 1));
    return $mapa[$key] ?? 5;
}

/**
 * Obtiene el nombre del tipo de comprobante.
 */
function obtenerNombreTipoComprobante(int $tipo): string
{
    $nombres = [
        1 => 'FA',
        2 => 'NDA',
        3 => 'NCA',
        6 => 'FB',
        7 => 'NDB',
        8 => 'NCB',
        11 => 'FC',
        12 => 'NDC',
        13 => 'NCC',
    ];

    return $nombres[$tipo] ?? 'FC';
}

/**
 * Revisa si la configuracion local de ARCA esta lista para probar.
 */
function flus_facturacion_preflight_arca(?string $modoEsperado = null): array
{
    $modo = $modoEsperado !== null ? flus_facturacion_normalizar_modo($modoEsperado) : null;
    $requiereArca = $modo !== null && flus_facturacion_modo_requires_arca($modo);
    $envActual = flus_facturacion_arca_env_actual();
    $envEsperado = $modo !== null ? flus_facturacion_arca_env_esperado($modo) : '';
    $configPath = __DIR__ . '/config_arca.php';
    $configExists = file_exists($configPath);
    $certPath = defined('FLUS_ARCA_CERT_PEM') ? (string)FLUS_ARCA_CERT_PEM : '';
    $keyPath = defined('FLUS_ARCA_KEY_PEM') ? (string)FLUS_ARCA_KEY_PEM : '';
    $cuit = defined('FLUS_ARCA_CUIT') ? preg_replace('/\D+/', '', (string)FLUS_ARCA_CUIT) : '';
    $repCuit = defined('FLUS_ARCA_REP_CUIT') ? preg_replace('/\D+/', '', (string)FLUS_ARCA_REP_CUIT) : '';

    $items = [];
    $items[] = [
        'label' => 'Archivo config_arca.php',
        'status' => $configExists ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => $configExists ? 'Detectado' : 'No existe',
        'hint' => $configPath,
    ];
    $items[] = [
        'label' => 'Entorno ARCA',
        'status' => $envEsperado === '' || $envActual === $envEsperado ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => strtoupper($envActual),
        'hint' => $envEsperado !== '' ? 'Esperado: ' . strtoupper($envEsperado) : 'Sin exigencia en demo',
    ];
    $items[] = [
        'label' => 'CUIT emisor',
        'status' => $cuit !== '' ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => $cuit !== '' ? $cuit : 'Pendiente',
        'hint' => 'FLUS_ARCA_CUIT',
    ];
    $items[] = [
        'label' => 'CUIT representada',
        'status' => $repCuit !== '' ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => $repCuit !== '' ? $repCuit : 'Pendiente',
        'hint' => 'FLUS_ARCA_REP_CUIT',
    ];
    $items[] = [
        'label' => 'Certificado PEM',
        'status' => ($certPath !== '' && is_file($certPath) && is_readable($certPath)) ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => ($certPath !== '' && is_file($certPath)) ? 'Detectado' : 'Pendiente',
        'hint' => $certPath !== '' ? $certPath : 'FLUS_ARCA_CERT_PEM',
    ];
    $items[] = [
        'label' => 'Clave PEM',
        'status' => ($keyPath !== '' && is_file($keyPath) && is_readable($keyPath)) ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => ($keyPath !== '' && is_file($keyPath)) ? 'Detectada' : 'Pendiente',
        'hint' => $keyPath !== '' ? $keyPath : 'FLUS_ARCA_KEY_PEM',
    ];

    $warnings = [];
    if ($requiereArca && $envEsperado !== '' && $envActual !== $envEsperado) {
        $warnings[] = 'El modo seleccionado en Flus y FLUS_ARCA_ENV no coinciden.';
    }

    $ok = true;
    foreach ($items as $item) {
        if (($item['status'] ?? 'ok') === 'error') {
            $ok = false;
            break;
        }
    }

    return [
        'ok' => $ok,
        'modo' => $modo,
        'env_actual' => $envActual,
        'env_esperado' => $envEsperado,
        'items' => $items,
        'warnings' => $warnings,
    ];
}

/**
 * Verifica el estado de la conexion con AFIP.
 */
function verificarConexionAfip(): array
{
    $resultado = [
        'conectado' => false,
        'mensaje' => '',
        'detalles' => [],
    ];

    if (!extension_loaded('soap')) {
        $resultado['mensaje'] = 'Extension SOAP no habilitada.';
        return $resultado;
    }
    if (!extension_loaded('openssl')) {
        $resultado['mensaje'] = 'Extension OpenSSL no habilitada.';
        return $resultado;
    }

    $certPath = defined('FLUS_ARCA_CERT_PEM') ? FLUS_ARCA_CERT_PEM : '';
    $keyPath = defined('FLUS_ARCA_KEY_PEM') ? FLUS_ARCA_KEY_PEM : '';
    $cuit = defined('FLUS_ARCA_CUIT') ? FLUS_ARCA_CUIT : '';

    if ($certPath === '' || $keyPath === '') {
        $resultado['mensaje'] = 'Falta configurar certificado y clave (FLUS_ARCA_CERT_PEM, FLUS_ARCA_KEY_PEM).';
        return $resultado;
    }

    if (!file_exists($certPath)) {
        $resultado['mensaje'] = 'No se encuentra el certificado: ' . $certPath;
        return $resultado;
    }

    if (!file_exists($keyPath)) {
        $resultado['mensaje'] = 'No se encuentra la clave privada: ' . $keyPath;
        return $resultado;
    }

    if ($cuit === '') {
        $resultado['mensaje'] = 'Falta configurar CUIT del emisor (FLUS_ARCA_CUIT).';
        return $resultado;
    }

    require_once __DIR__ . '/../public/includes/ArcaWsaa.php';
    $ta = ArcaWsaa::getTA('wsfe');

    if (!$ta) {
        $resultado['mensaje'] = 'Error de autenticacion: ' . (ArcaWsaa::getLastError() ?: 'Error desconocido');
        return $resultado;
    }

    $resultado['conectado'] = true;
    $resultado['mensaje'] = 'Conexion exitosa con AFIP/ARCA.';
    $resultado['detalles'] = [
        'token_expira' => date('Y-m-d H:i:s', $ta['expires_at']),
        'ambiente' => defined('FLUS_ARCA_ENV') ? FLUS_ARCA_ENV : 'prod',
    ];

    return $resultado;
}

/**
 * Obtiene los puntos de venta habilitados en AFIP.
 */
function obtenerPuntosVentaAfip(): ?array
{
    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
    return ArcaWsfe::getPuntosVenta();
}

function flus_factura_pdf_token_create(int $facturaId, int $expiresAt): string
{
    $payload = $facturaId . '|' . $expiresAt;
    $sig = hash_hmac('sha256', $payload, (string)APP_SECRET);
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

function flus_factura_pdf_token_validate(string $token, int $facturaId): bool
{
    if ($facturaId <= 0 || trim($token) === '') {
        return false;
    }

    $normalized = strtr($token, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $raw = base64_decode($normalized, true);
    if (!is_string($raw) || $raw === '') {
        return false;
    }

    $parts = explode('|', $raw);
    if (count($parts) !== 3) {
        return false;
    }

    [$tokenFacturaId, $expiresAt, $sig] = $parts;
    if (!ctype_digit($tokenFacturaId) || !ctype_digit($expiresAt)) {
        return false;
    }

    if ((int)$tokenFacturaId !== $facturaId || (int)$expiresAt < time()) {
        return false;
    }

    $expected = hash_hmac('sha256', $tokenFacturaId . '|' . $expiresAt, (string)APP_SECRET);
    return hash_equals($expected, (string)$sig);
}

function flus_factura_pdf_browser_path(): ?string
{
    static $cached = false;
    if ($cached !== false) {
        return $cached ?: null;
    }

    $candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $cached = $path;
            return $cached;
        }
    }

    $cached = '';
    return null;
}

