<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

const FLUS_SUCURSAL_TRANSFER_FORMAT = 'flus_catalogo_sucursal_v1';

function flus_sucursal_transfer_bool(mixed $value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOL);
}

function flus_sucursal_transfer_text(mixed $value, int $max = 255): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
}

function flus_sucursal_transfer_float(mixed $value, float $default = 0.0): float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return $default;
    }

    if (str_contains($raw, ',')) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    }

    return is_numeric($raw) ? (float)$raw : $default;
}

function flus_sucursal_transfer_unidad(mixed $value): string
{
    $unidad = strtoupper(flus_sucursal_transfer_text($value, 12));
    return in_array($unidad, ['UNIDAD', 'KG', 'G', 'LT', 'ML'], true) ? $unidad : 'UNIDAD';
}

function flus_sucursal_transfer_export(PDO $pdo, array $options = []): array
{
    $includeStock = !empty($options['include_stock']);
    $includeInactive = !empty($options['include_inactive']);
    $includeProviders = array_key_exists('include_providers', $options) ? !empty($options['include_providers']) : true;
    $includeReposicion = array_key_exists('include_reposicion', $options) ? !empty($options['include_reposicion']) : true;

    $productExpr = static function (PDO $pdo, string $column, string $fallback): string {
        return flus_column_exists($pdo, 'productos', $column) ? '`' . $column . '`' : $fallback . ' AS `' . $column . '`';
    };

    $where = $includeInactive ? '' : 'WHERE activo = 1';
    $productSelect = implode(', ', [
        $productExpr($pdo, 'codigo', "''"),
        $productExpr($pdo, 'nombre', "''"),
        $productExpr($pdo, 'categoria', "''"),
        $productExpr($pdo, 'marca', "''"),
        $productExpr($pdo, 'proveedor', "''"),
        $productExpr($pdo, 'proveedor_id', 'NULL'),
        $productExpr($pdo, 'iva', 'NULL'),
        $productExpr($pdo, 'iva_porcentaje', 'NULL'),
        $productExpr($pdo, 'precio', '0'),
        $productExpr($pdo, 'costo', 'NULL'),
        $productExpr($pdo, 'stock', '0'),
        $productExpr($pdo, 'stock_minimo', '0'),
        $productExpr($pdo, 'es_pesable', '0'),
        $productExpr($pdo, 'unidad_venta', "'UNIDAD'"),
        $productExpr($pdo, 'activo', '1'),
    ]);
    $productsStmt = $pdo->query(
        "SELECT {$productSelect}
           FROM productos
           {$where}
          ORDER BY nombre ASC, codigo ASC"
    );
    $productsRaw = $productsStmt ? $productsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $providerById = [];
    $providers = [];
    if ($includeProviders && flus_table_exists($pdo, 'proveedores')) {
        $providerExpr = static function (PDO $pdo, string $column, string $fallback): string {
            return flus_column_exists($pdo, 'proveedores', $column) ? '`' . $column . '`' : $fallback . ' AS `' . $column . '`';
        };
        $provWhere = ($includeInactive || !flus_column_exists($pdo, 'proveedores', 'activo')) ? '' : 'WHERE activo = 1';
        $providerSelect = implode(', ', [
            '`id`',
            $providerExpr($pdo, 'nombre', "''"),
            $providerExpr($pdo, 'razon_social', "''"),
            $providerExpr($pdo, 'cuit', "''"),
            $providerExpr($pdo, 'contacto_nombre', "''"),
            $providerExpr($pdo, 'telefono', "''"),
            $providerExpr($pdo, 'email', "''"),
            $providerExpr($pdo, 'whatsapp', "''"),
            $providerExpr($pdo, 'direccion', "''"),
            $providerExpr($pdo, 'ciudad', "''"),
            $providerExpr($pdo, 'provincia', "''"),
            $providerExpr($pdo, 'dias_pago', '0'),
            $providerExpr($pdo, 'descuento_habitual', '0'),
            $providerExpr($pdo, 'notas', "''"),
            $providerExpr($pdo, 'activo', '1'),
        ]);
        $stmt = $pdo->query(
            "SELECT {$providerSelect}
               FROM proveedores
               {$provWhere}
              ORDER BY nombre ASC"
        );
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $provider = [
                'nombre' => (string)($row['nombre'] ?? ''),
                'razon_social' => (string)($row['razon_social'] ?? ''),
                'cuit' => (string)($row['cuit'] ?? ''),
                'contacto_nombre' => (string)($row['contacto_nombre'] ?? ''),
                'telefono' => (string)($row['telefono'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'whatsapp' => (string)($row['whatsapp'] ?? ''),
                'direccion' => (string)($row['direccion'] ?? ''),
                'ciudad' => (string)($row['ciudad'] ?? ''),
                'provincia' => (string)($row['provincia'] ?? ''),
                'dias_pago' => (int)($row['dias_pago'] ?? 0),
                'descuento_habitual' => round((float)($row['descuento_habitual'] ?? 0), 2),
                'notas' => (string)($row['notas'] ?? ''),
                'activo' => (int)($row['activo'] ?? 1),
            ];
            $providerById[(int)$row['id']] = $provider;
            $providers[] = $provider;
        }
    }

    $products = [];
    foreach ($productsRaw as $row) {
        $provider = $providerById[(int)($row['proveedor_id'] ?? 0)] ?? null;
        $products[] = [
            'codigo' => (string)($row['codigo'] ?? ''),
            'nombre' => (string)($row['nombre'] ?? ''),
            'categoria' => (string)($row['categoria'] ?? ''),
            'marca' => (string)($row['marca'] ?? ''),
            'proveedor' => (string)($row['proveedor'] ?? ''),
            'proveedor_cuit' => is_array($provider) ? (string)$provider['cuit'] : '',
            'iva' => $row['iva'] !== null ? round((float)$row['iva'], 2) : null,
            'iva_porcentaje' => $row['iva_porcentaje'] !== null ? round((float)$row['iva_porcentaje'], 2) : null,
            'precio' => round((float)($row['precio'] ?? 0), 2),
            'costo' => $row['costo'] !== null ? round((float)$row['costo'], 2) : null,
            'stock' => $includeStock ? round((float)($row['stock'] ?? 0), 3) : 0.0,
            'stock_minimo' => round((float)($row['stock_minimo'] ?? 0), 3),
            'es_pesable' => (int)($row['es_pesable'] ?? 0),
            'unidad_venta' => (string)($row['unidad_venta'] ?? 'UNIDAD'),
            'activo' => (int)($row['activo'] ?? 1),
        ];
    }

    $reposicion = [];
    if ($includeReposicion && flus_table_exists($pdo, 'producto_reposicion')) {
        $repoWhere = $includeInactive ? '' : 'WHERE p.activo = 1';
        $stmt = $pdo->query(
            "SELECT p.codigo AS producto_codigo, r.stock_minimo, r.stock_maximo, r.punto_reorden,
                    r.dias_reposicion, pv.nombre AS proveedor, pv.cuit AS proveedor_cuit
               FROM producto_reposicion r
               JOIN productos p ON p.id = r.producto_id
          LEFT JOIN proveedores pv ON pv.id = r.proveedor_id
               {$repoWhere}
           ORDER BY p.nombre ASC, p.codigo ASC"
        );
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $reposicion[] = [
                'producto_codigo' => (string)($row['producto_codigo'] ?? ''),
                'stock_minimo' => $row['stock_minimo'] !== null ? round((float)$row['stock_minimo'], 3) : null,
                'stock_maximo' => $row['stock_maximo'] !== null ? round((float)$row['stock_maximo'], 3) : null,
                'punto_reorden' => $row['punto_reorden'] !== null ? round((float)$row['punto_reorden'], 3) : null,
                'proveedor' => (string)($row['proveedor'] ?? ''),
                'proveedor_cuit' => (string)($row['proveedor_cuit'] ?? ''),
                'dias_reposicion' => (int)($row['dias_reposicion'] ?? 7),
            ];
        }
    }

    return [
        'format' => FLUS_SUCURSAL_TRANSFER_FORMAT,
        'version' => 1,
        'generated_at' => date('c'),
        'source' => [
            'app' => defined('APP_NAME') ? (string)APP_NAME : 'FLUS',
            'version' => defined('FLUS_VERSION') ? (string)FLUS_VERSION : '',
            'stock_included' => $includeStock,
        ],
        'summary' => [
            'productos' => count($products),
            'proveedores' => count($providers),
            'reposicion' => count($reposicion),
        ],
        'proveedores' => $providers,
        'productos' => $products,
        'reposicion' => $reposicion,
    ];
}

function flus_sucursal_transfer_find_provider(PDO $pdo, string $nombre, string $cuit): ?int
{
    if (!flus_table_exists($pdo, 'proveedores')) {
        return null;
    }

    if ($cuit !== '') {
        $stmt = $pdo->prepare('SELECT id FROM proveedores WHERE cuit = ? LIMIT 1');
        $stmt->execute([$cuit]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    if ($nombre !== '') {
        $stmt = $pdo->prepare('SELECT id FROM proveedores WHERE TRIM(LOWER(nombre)) = TRIM(LOWER(?)) LIMIT 1');
        $stmt->execute([$nombre]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    return null;
}

function flus_sucursal_transfer_upsert_provider(PDO $pdo, array $raw, bool $updateExisting, array &$stats): ?int
{
    $nombre = flus_sucursal_transfer_text($raw['nombre'] ?? '', 120);
    if ($nombre === '' || !flus_table_exists($pdo, 'proveedores')) {
        return null;
    }

    $data = [
        'nombre' => $nombre,
        'razon_social' => flus_sucursal_transfer_text($raw['razon_social'] ?? '', 150),
        'cuit' => flus_sucursal_transfer_text($raw['cuit'] ?? '', 20),
        'contacto_nombre' => flus_sucursal_transfer_text($raw['contacto_nombre'] ?? '', 100),
        'telefono' => flus_sucursal_transfer_text($raw['telefono'] ?? '', 50),
        'email' => flus_sucursal_transfer_text($raw['email'] ?? '', 120),
        'whatsapp' => flus_sucursal_transfer_text($raw['whatsapp'] ?? '', 20),
        'direccion' => flus_sucursal_transfer_text($raw['direccion'] ?? '', 255),
        'ciudad' => flus_sucursal_transfer_text($raw['ciudad'] ?? '', 100),
        'provincia' => flus_sucursal_transfer_text($raw['provincia'] ?? '', 100),
        'dias_pago' => max(0, min(255, (int)($raw['dias_pago'] ?? 0))),
        'descuento_habitual' => max(0.0, min(100.0, flus_sucursal_transfer_float($raw['descuento_habitual'] ?? 0))),
        'notas' => flus_sucursal_transfer_text($raw['notas'] ?? '', 4000),
        'activo' => !empty($raw['activo']) ? 1 : 0,
    ];

    $existingId = flus_sucursal_transfer_find_provider($pdo, $data['nombre'], $data['cuit']);
    if ($existingId !== null) {
        if ($updateExisting) {
            $stmt = $pdo->prepare(
                'UPDATE proveedores
                    SET nombre = :nombre, razon_social = :razon_social, cuit = :cuit,
                        contacto_nombre = :contacto_nombre, telefono = :telefono, email = :email,
                        whatsapp = :whatsapp, direccion = :direccion, ciudad = :ciudad,
                        provincia = :provincia, dias_pago = :dias_pago,
                        descuento_habitual = :descuento_habitual, notas = :notas, activo = :activo
                  WHERE id = :id'
            );
            $stmt->execute($data + ['id' => $existingId]);
            $stats['proveedores_actualizados']++;
        }
        return $existingId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO proveedores
            (nombre, razon_social, cuit, contacto_nombre, telefono, email, whatsapp, direccion,
             ciudad, provincia, dias_pago, descuento_habitual, notas, activo)
         VALUES
            (:nombre, :razon_social, :cuit, :contacto_nombre, :telefono, :email, :whatsapp, :direccion,
             :ciudad, :provincia, :dias_pago, :descuento_habitual, :notas, :activo)'
    );
    $stmt->execute($data);
    $stats['proveedores_creados']++;

    return (int)$pdo->lastInsertId();
}

function flus_sucursal_transfer_find_product(PDO $pdo, string $codigo): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM productos WHERE codigo = ? LIMIT 1');
    $stmt->execute([$codigo]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function flus_sucursal_transfer_import(PDO $pdo, array $payload, array $options = []): array
{
    if (($payload['format'] ?? '') !== FLUS_SUCURSAL_TRANSFER_FORMAT) {
        throw new RuntimeException('El archivo no parece ser un catalogo de sucursal compatible con FLUS.');
    }

    $updateExisting = !empty($options['update_existing']);
    $importStock = !empty($options['import_stock']);
    $importInactive = !empty($options['import_inactive']);
    $importProviders = array_key_exists('import_providers', $options) ? !empty($options['import_providers']) : true;
    $importReposicion = array_key_exists('import_reposicion', $options) ? !empty($options['import_reposicion']) : true;

    $stats = [
        'proveedores_creados' => 0,
        'proveedores_actualizados' => 0,
        'productos_creados' => 0,
        'productos_actualizados' => 0,
        'productos_omitidos' => 0,
        'reposicion_actualizada' => 0,
        'errores' => [],
    ];

    $providerMap = [];
    $pdo->beginTransaction();
    try {
        if ($importProviders) {
            foreach (($payload['proveedores'] ?? []) as $provider) {
                if (!is_array($provider)) {
                    continue;
                }
                $id = flus_sucursal_transfer_upsert_provider($pdo, $provider, $updateExisting, $stats);
                if ($id !== null) {
                    $nameKey = strtolower(flus_sucursal_transfer_text($provider['nombre'] ?? '', 120));
                    $cuitKey = flus_sucursal_transfer_text($provider['cuit'] ?? '', 20);
                    if ($nameKey !== '') $providerMap['n:' . $nameKey] = $id;
                    if ($cuitKey !== '') $providerMap['c:' . $cuitKey] = $id;
                }
            }
        }

        $productIdByCode = [];
        $hasProviderId = flus_column_exists($pdo, 'productos', 'proveedor_id');
        $hasIvaPorcentaje = flus_column_exists($pdo, 'productos', 'iva_porcentaje');
        $hasStockInicial = flus_column_exists($pdo, 'productos', 'stock_inicial');

        foreach (($payload['productos'] ?? []) as $index => $raw) {
            if (!is_array($raw)) {
                $stats['productos_omitidos']++;
                continue;
            }

            $codigo = flus_sucursal_transfer_text($raw['codigo'] ?? '', 50);
            $nombre = flus_sucursal_transfer_text($raw['nombre'] ?? '', 100);
            $activo = !empty($raw['activo']) ? 1 : 0;
            if ($codigo === '' || $nombre === '' || (!$importInactive && $activo === 0)) {
                $stats['productos_omitidos']++;
                continue;
            }

            $proveedorNombre = flus_sucursal_transfer_text($raw['proveedor'] ?? '', 150);
            $proveedorCuit = flus_sucursal_transfer_text($raw['proveedor_cuit'] ?? '', 20);
            $providerId = null;
            if ($hasProviderId && $importProviders) {
                $providerId = $proveedorCuit !== '' ? ($providerMap['c:' . $proveedorCuit] ?? null) : null;
                $providerId = $providerId ?? ($proveedorNombre !== '' ? ($providerMap['n:' . strtolower($proveedorNombre)] ?? null) : null);
                if ($providerId === null && ($proveedorNombre !== '' || $proveedorCuit !== '')) {
                    $providerId = flus_sucursal_transfer_find_provider($pdo, $proveedorNombre, $proveedorCuit);
                }
            }

            $stock = $importStock ? max(0.0, flus_sucursal_transfer_float($raw['stock'] ?? 0)) : 0.0;
            $data = [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'categoria' => flus_sucursal_transfer_text($raw['categoria'] ?? '', 100),
                'marca' => flus_sucursal_transfer_text($raw['marca'] ?? '', 100),
                'proveedor' => $proveedorNombre,
                'proveedor_id' => $providerId,
                'iva' => array_key_exists('iva', $raw) && $raw['iva'] !== null ? flus_sucursal_transfer_float($raw['iva']) : null,
                'iva_porcentaje' => array_key_exists('iva_porcentaje', $raw) && $raw['iva_porcentaje'] !== null ? flus_sucursal_transfer_float($raw['iva_porcentaje'], 21.0) : 21.0,
                'precio' => max(0.0, flus_sucursal_transfer_float($raw['precio'] ?? 0)),
                'costo' => array_key_exists('costo', $raw) && $raw['costo'] !== null ? max(0.0, flus_sucursal_transfer_float($raw['costo'])) : null,
                'stock' => $stock,
                'stock_minimo' => max(0.0, flus_sucursal_transfer_float($raw['stock_minimo'] ?? 0)),
                'es_pesable' => !empty($raw['es_pesable']) ? 1 : 0,
                'unidad_venta' => flus_sucursal_transfer_unidad($raw['unidad_venta'] ?? 'UNIDAD'),
                'activo' => $activo,
            ];

            $existingId = flus_sucursal_transfer_find_product($pdo, $codigo);
            if ($existingId !== null) {
                $productIdByCode[$codigo] = $existingId;
                if (!$updateExisting) {
                    $stats['productos_omitidos']++;
                    continue;
                }

                $set = 'codigo = :codigo, nombre = :nombre, categoria = :categoria, marca = :marca,
                        proveedor = :proveedor, iva = :iva, precio = :precio, costo = :costo,
                        stock_minimo = :stock_minimo, es_pesable = :es_pesable,
                        unidad_venta = :unidad_venta, activo = :activo';
                if ($hasProviderId) $set .= ', proveedor_id = :proveedor_id';
                if ($hasIvaPorcentaje) $set .= ', iva_porcentaje = :iva_porcentaje';
                if ($importStock) $set .= ', stock = :stock';
                $stmt = $pdo->prepare("UPDATE productos SET {$set} WHERE id = :id");
                $updateParams = $data + ['id' => $existingId];
                if (!$hasProviderId) unset($updateParams['proveedor_id']);
                if (!$hasIvaPorcentaje) unset($updateParams['iva_porcentaje']);
                if (!$importStock) unset($updateParams['stock']);
                $stmt->execute($updateParams);
                $stats['productos_actualizados']++;
                continue;
            }

            $columns = ['codigo', 'nombre', 'categoria', 'marca', 'proveedor', 'iva', 'precio', 'costo', 'stock', 'stock_minimo', 'es_pesable', 'unidad_venta', 'activo'];
            if ($hasProviderId) $columns[] = 'proveedor_id';
            if ($hasIvaPorcentaje) $columns[] = 'iva_porcentaje';
            if ($hasStockInicial) $columns[] = 'stock_inicial';

            $insertData = $data;
            $insertData['stock_inicial'] = $stock;
            $fieldList = implode(', ', array_map(static fn(string $col): string => '`' . $col . '`', $columns));
            $placeholders = implode(', ', array_map(static fn(string $col): string => ':' . $col, $columns));
            $stmt = $pdo->prepare("INSERT INTO productos ({$fieldList}) VALUES ({$placeholders})");
            $stmt->execute(array_intersect_key($insertData, array_flip($columns)));
            $newId = (int)$pdo->lastInsertId();
            $productIdByCode[$codigo] = $newId;
            $stats['productos_creados']++;
        }

        if ($importReposicion && flus_table_exists($pdo, 'producto_reposicion')) {
            foreach (($payload['reposicion'] ?? []) as $raw) {
                if (!is_array($raw)) continue;
                $codigo = flus_sucursal_transfer_text($raw['producto_codigo'] ?? '', 50);
                $productId = $productIdByCode[$codigo] ?? flus_sucursal_transfer_find_product($pdo, $codigo);
                if (!$productId) continue;

                $proveedorNombre = flus_sucursal_transfer_text($raw['proveedor'] ?? '', 150);
                $proveedorCuit = flus_sucursal_transfer_text($raw['proveedor_cuit'] ?? '', 20);
                $providerId = $proveedorCuit !== '' ? ($providerMap['c:' . $proveedorCuit] ?? null) : null;
                $providerId = $providerId ?? ($proveedorNombre !== '' ? ($providerMap['n:' . strtolower($proveedorNombre)] ?? null) : null);
                $providerId = $providerId ?? flus_sucursal_transfer_find_provider($pdo, $proveedorNombre, $proveedorCuit);

                $stmt = $pdo->prepare(
                    'INSERT INTO producto_reposicion
                        (producto_id, stock_minimo, stock_maximo, punto_reorden, proveedor_id, dias_reposicion)
                     VALUES
                        (:producto_id, :stock_minimo, :stock_maximo, :punto_reorden, :proveedor_id, :dias_reposicion)
                     ON DUPLICATE KEY UPDATE
                        stock_minimo = VALUES(stock_minimo),
                        stock_maximo = VALUES(stock_maximo),
                        punto_reorden = VALUES(punto_reorden),
                        proveedor_id = VALUES(proveedor_id),
                        dias_reposicion = VALUES(dias_reposicion)'
                );
                $stmt->execute([
                    'producto_id' => $productId,
                    'stock_minimo' => array_key_exists('stock_minimo', $raw) && $raw['stock_minimo'] !== null ? max(0.0, flus_sucursal_transfer_float($raw['stock_minimo'])) : null,
                    'stock_maximo' => array_key_exists('stock_maximo', $raw) && $raw['stock_maximo'] !== null ? max(0.0, flus_sucursal_transfer_float($raw['stock_maximo'])) : null,
                    'punto_reorden' => array_key_exists('punto_reorden', $raw) && $raw['punto_reorden'] !== null ? max(0.0, flus_sucursal_transfer_float($raw['punto_reorden'])) : null,
                    'proveedor_id' => $providerId,
                    'dias_reposicion' => max(1, min(365, (int)($raw['dias_reposicion'] ?? 7))),
                ]);
                $stats['reposicion_actualizada']++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $stats;
}
