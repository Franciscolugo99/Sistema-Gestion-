<?php
// src/facturacion_lib.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/facturacion_manual_lib.php';

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
 * Obtiene la configuracion activa de facturacion con lock pesimista.
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

    $st = $pdo->prepare('REPLACE INTO venta_fiscal (venta_id, pto_vta, tipo_cmp, nro_cmp, cae, cae_vto, moneda, ctz) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
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

function flus_facturacion_config_activa(PDO $pdo): ?array
{
    if (!flus_table_exists($pdo, 'config_facturacion')) {
        return null;
    }

    $order = flus_column_exists($pdo, 'config_facturacion', 'id') ? ' ORDER BY id DESC' : '';
    $lock = ' FOR UPDATE';

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
function flus_facturacion_facturas_modo_value(PDO $pdo, string $modo): string
{
    $normalizado = flus_facturacion_normalizar_modo($modo);

    if (!flus_column_exists($pdo, 'facturas', 'modo')) {
        return flus_facturacion_modo_db_value($normalizado);
    }

    try {
        $st = $pdo->query("SHOW COLUMNS FROM facturas LIKE 'modo'");
        $col = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        $type = strtolower(trim((string)($col['Type'] ?? '')));
        if (str_starts_with($type, 'enum(') && !str_contains($type, "'homologacion'")) {
            return flus_facturacion_modo_db_value($normalizado);
        }
    } catch (Throwable $e) {
        return flus_facturacion_modo_db_value($normalizado);
    }

    return $normalizado;
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

    if ($modo !== null && $modo !== '' && flus_column_exists($pdo, 'facturas', 'modo')) {
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

/**
 * Normaliza el modo fiscal de la app.
 */
function flus_facturacion_normalizar_modo(?string $raw): string
{
    $modo = strtolower(trim((string)$raw));

    if (in_array($modo, ['produccion', 'prod'], true)) {
        return 'produccion';
    }

    if (in_array($modo, ['homologacion', 'homologaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n', 'homo', 'testing', 'test'], true)) {
        return 'homologacion';
    }

    return 'demo';
}

/**
 * Obtiene el modo efectivo para la operacion actual.
 */
function flus_facturacion_modo_actual(array $config = [], array $opciones = []): string
{
    if (isset($opciones['modo'])) {
        return flus_facturacion_normalizar_modo((string)$opciones['modo']);
    }

    try {
        $pdo = getPDO();
        $persistido = trim((string)config_get($pdo, 'facturacion_modo', ''));
        if ($persistido !== '') {
            return flus_facturacion_normalizar_modo($persistido);
        }
    } catch (Throwable $e) {
        // fallback al valor de config_facturacion si app_config no esta disponible
    }

    return flus_facturacion_normalizar_modo((string)($config['modo'] ?? 'demo'));
}

/**
 * Indica si el modo necesita conexion real con ARCA.
 */
function flus_facturacion_modo_requires_arca(string $modo): bool
{
    return flus_facturacion_normalizar_modo($modo) !== 'demo';
}

/**
 * Etiqueta amigable para UI.
 */
function flus_facturacion_modo_label(string $modo): string
{
    return match (flus_facturacion_normalizar_modo($modo)) {
        'homologacion' => 'Homologacion',
        'produccion' => 'Produccion',
        default => 'Demo',
    };
}

/**
 * Valor compatible para columnas legacy enum(''demo'',''produccion'').
 */
function flus_facturacion_modo_db_value(string $modo): string
{
    return flus_facturacion_normalizar_modo($modo) === 'demo' ? 'demo' : 'produccion';
}

/**
 * Ambiente ARCA esperado para cada modo.
 */
function flus_facturacion_arca_env_esperado(string $modo): string
{
    return match (flus_facturacion_normalizar_modo($modo)) {
        'homologacion' => 'homo',
        'produccion' => 'prod',
        default => '',
    };
}

/**
 * Ambiente ARCA actualmente configurado.
 */
function flus_facturacion_arca_env_actual(): string
{
    $env = strtolower(trim((string)(defined('FLUS_ARCA_ENV') ? FLUS_ARCA_ENV : 'prod')));
    return $env === 'homo' ? 'homo' : 'prod';
}

/**
 * Normaliza el modo, con demo como fallback seguro.
 */
function flus_facturacion_modo_demo(array $config, array $opciones): bool
{
    return flus_facturacion_modo_actual($config, $opciones) === 'demo';
}

/**
 * Normaliza fecha de vencimiento CAE para guardar y mostrar consistente.
 */
function flus_facturacion_normalizar_cae_vto(?string $caeVto): ?string
{
    if ($caeVto === null) {
        return null;
    }

    $caeVto = trim($caeVto);
    if ($caeVto === '') {
        return null;
    }

    if (preg_match('/^\d{8}$/', $caeVto) === 1) {
        $dt = DateTime::createFromFormat('Ymd', $caeVto);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($caeVto);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return $caeVto;
}

/**
 * Emite una factura para una venta dentro de una transaccion ya abierta.
 */
function flus_facturacion_emitir_desde_venta(PDO $pdo, int $ventaId, int $clienteId, array $opciones = []): int
{
    $st = $pdo->prepare('SELECT * FROM ventas WHERE id = ? LIMIT 1 FOR UPDATE');
    $st->execute([$ventaId]);
    $venta = $st->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        throw new Exception('Venta no encontrada.');
    }


    $printItemCount = flus_facturacion_count_items_venta($pdo, $ventaId);
    flus_facturacion_assert_print_item_limit($printItemCount);
    $st = $pdo->prepare('SELECT id FROM facturas WHERE venta_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$ventaId]);
    if ($st->fetchColumn()) {
        throw new Exception('La venta ya tiene una factura emitida.');
    }

    $clienteData = $opciones['resolved_cliente'] ?? flus_facturacion_resolver_cliente($pdo, $clienteId);
    if (!is_array($clienteData)) {
        throw new Exception('No se pudo resolver el cliente para la factura.');
    }

    $cliente = isset($clienteData['cliente']) && is_array($clienteData['cliente']) ? $clienteData['cliente'] : null;
    $clienteIdFiscal = (int)($clienteData['cliente_id'] ?? 0);
    $consumidorFinal = !empty($clienteData['consumidor_final']);

    if ($clienteIdFiscal > 0 && flus_column_exists($pdo, 'ventas', 'cliente_id') && (int)($venta['cliente_id'] ?? 0) !== $clienteIdFiscal) {
        $stVentaCliente = $pdo->prepare('UPDATE ventas SET cliente_id = ? WHERE id = ?');
        $stVentaCliente->execute([$clienteIdFiscal, $ventaId]);
        $venta['cliente_id'] = $clienteIdFiscal;
    }

    $config = flus_facturacion_config_activa($pdo);
    if (!$config) {
        throw new Exception('No hay configuracion de facturacion activa. Configure un punto de venta primero.');
    }

    $puntoVenta = max(1, (int)($config['punto_venta'] ?? 1));
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

    $numero = $modoDemo
        ? max(1, (int)($config['proximo_numero'] ?? 1), flus_facturacion_numero_local_siguiente($pdo, $puntoVenta, $tipoStr, $modoOperacion))
        : max(1, flus_facturacion_numero_local_siguiente($pdo, $puntoVenta, $tipoStr, $modoOperacion));

    if (!$modoDemo) {
        $envEsperado = flus_facturacion_arca_env_esperado($modoOperacion);
        $envActual = flus_facturacion_arca_env_actual();
        if ($envEsperado !== '' && $envActual !== $envEsperado) {
            throw new Exception('El modo ' . flus_facturacion_modo_label($modoOperacion) . ' requiere FLUS_ARCA_ENV=' . strtoupper($envEsperado) . ' pero hoy esta en ' . strtoupper($envActual) . '.');
        }

        require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
        $ultimoAfip = ArcaWsfe::getUltimoAutorizado($puntoVenta, $tipoCbte);
        if ($ultimoAfip !== null) {
            $numero = max($numero, $ultimoAfip + 1);
        }
    }

    $importes = calcularImportesFactura($pdo, $ventaId, $venta, $tipoCbte);

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
    ];

    $docData = determinarDocumentoCliente($cliente, $consumidorFinal);
    $comprobante['tipo_doc'] = $docData['tipo'];
    $comprobante['nro_doc'] = $docData['numero'];
    $comprobante['condicion_iva_receptor_id'] = determinarCondicionIvaReceptorAfip($cliente, $consumidorFinal);

    if ($importes['iva'] > 0 && !empty($importes['iva_detalle'])) {
        $comprobante['iva'] = $importes['iva_detalle'];
    }

    $cae = null;
    $caeVto = null;
    $estado = 'EMITIDA';

    if ($modoDemo) {
        $cae = 'DEMO' . str_pad((string)$numero, 14, '0', STR_PAD_LEFT);
        $caeVto = date('Y-m-d', strtotime('+10 days'));
    } else {
        require_once __DIR__ . '/../public/includes/ArcaWsfe.php';

        $resultado = ArcaWsfe::solicitarCAE($comprobante);

        if (!$resultado) {
            $errorMsg = ArcaWsfe::getLastError() ?: '';
            if (strpos($errorMsg, '10016') !== false || stripos($errorMsg, 'ya fue') !== false) {
                $ultimoAfip = ArcaWsfe::getUltimoAutorizado($puntoVenta, $tipoCbte);
                if ($ultimoAfip !== null) {
                    $numero = max(flus_facturacion_numero_local_siguiente($pdo, $puntoVenta, $tipoStr, $modoOperacion), $ultimoAfip + 1);
                    $comprobante['numero'] = $numero;
                    $resultado = ArcaWsfe::solicitarCAE($comprobante);
                }
            }
        }

        if (!$resultado) {
            throw new Exception('Error de AFIP: ' . (ArcaWsfe::getLastError() ?: 'Error desconocido'));
        }

        $cae = (string)($resultado['cae'] ?? '');
        $caeVto = flus_facturacion_normalizar_cae_vto((string)($resultado['vencimiento'] ?? ''));
        $numero = (int)($resultado['numero'] ?? $numero);
    }

    $timestamp = date('Y-m-d H:i:s');

    $facturaId = flus_facturacion_insert_dynamic($pdo, 'facturas', [
        'venta_id' => $ventaId,
        'cliente_id' => $clienteIdFiscal > 0 ? $clienteIdFiscal : null,
        'tipo' => $tipoStr,
        'punto_venta' => $puntoVenta,
        'numero' => $numero,
        'fecha' => $timestamp,
        'importe_neto' => $importes['neto'],
        'importe_iva' => $importes['iva'],
        'importe_exento' => $importes['exento'],
        'total' => $importes['total'],
        'cae' => $cae,
        'cae_vto' => flus_facturacion_normalizar_cae_vto($caeVto),
        'estado' => $estado,
        'modo' => $modoFactura,
        'creado_en' => $timestamp,
    ]);

    flus_facturacion_upsert_venta_fiscal($pdo, $ventaId, [
        'punto_venta' => $puntoVenta,
        'tipo_cbte' => $tipoCbte,
        'numero' => $numero,
        'cae' => $cae,
        'cae_vto' => $caeVto,
        'moneda_id' => 'PES',
        'moneda_cotiz' => 1,
    ]);

    if (flus_column_exists($pdo, 'config_facturacion', 'proximo_numero')) {
        $st = $pdo->prepare('UPDATE config_facturacion SET proximo_numero = :nuevo WHERE id = :id');
        $st->execute([
            ':nuevo' => $numero + 1,
            ':id' => $config['id'],
        ]);
    }

    if (flus_column_exists($pdo, 'ventas', 'facturada')) {
        $st = $pdo->prepare('UPDATE ventas SET facturada = 1 WHERE id = ?');
        $st->execute([$ventaId]);
    }

    return $facturaId;
}

/**
 * Crea una factura desde una venta existente.
 *
 * @param int $ventaId ID de la venta
 * @param int $clienteId ID del cliente (puede ser 0 para consumidor final)
 * @param array $opciones Opciones adicionales:
 *   - modo: 'demo', 'homologacion' o 'produccion' (default: segun config)
 *   - tipo_cbte: int (forzar tipo de comprobante)
 *   - concepto: int (1=Productos, 2=Servicios, 3=Ambos)
 * @return int ID de la factura creada
 * @throws Exception Si hay error
 */
function crearFacturaDesdeVenta(int $ventaId, int $clienteId, array $opciones = []): int
{
    $pdo = getPDO();

    if (!flus_facturacion_habilitada($pdo)) {
        throw new Exception('El modulo de facturacion no esta habilitado.');
    }

    $pdo->beginTransaction();

    try {
        $facturaId = flus_facturacion_emitir_desde_venta($pdo, $ventaId, $clienteId, $opciones);
        $pdo->commit();
        return $facturaId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Crea una venta manual y emite su factura dentro de la misma transaccion.
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
        'nota' => trim((string)($payload['nota'] ?? 'Factura manual')),
        'medio_pago' => trim((string)($payload['medio_pago'] ?? 'FACTURA')),
    ];
    $opciones = isset($payload['opciones']) && is_array($payload['opciones']) ? $payload['opciones'] : [];

    $pdo->beginTransaction();

    try {
        $clienteData = (isset($opciones['resolved_cliente']) && is_array($opciones['resolved_cliente'])) ? $opciones['resolved_cliente'] : flus_facturacion_resolver_cliente($pdo, $clienteId);
        $ventaId = flus_facturacion_crear_venta_manual($pdo, (int)($clienteData['cliente_id'] ?? 0), $items, $meta);
        $facturaId = flus_facturacion_emitir_desde_venta($pdo, $ventaId, $clienteId, $opciones + ['resolved_cliente' => $clienteData]);
        $pdo->commit();
        return $facturaId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function flus_facturacion_print_item_limit(): int
{
    // Limite conservador para mantener la factura en una sola hoja A4.
    // Con la version densa de impresion, soporta operaciones largas con descripciones cortas.
    return 22;
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
        $neto = 0.0;
        $iva = 0.0;
        $ivaDetalle = [];

        foreach ($manualItems as $item) {
            $pct = (float)($item['iva_porcentaje'] ?? 21);
            $subtotal = (float)($item['subtotal'] ?? 0);

            if ($pct <= 0) {
                $neto += $subtotal;
                continue;
            }

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

        return [
            'total' => round($total, 2),
            'neto' => round($neto, 2),
            'iva' => round($iva, 2),
            'exento' => 0.0,
            'no_gravado' => 0.0,
            'iva_detalle' => $ivaDetalle,
        ];
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
        'status' => $envEsperado === '' || $envActual === $envEsperado ? 'ok' : 'warning',
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








