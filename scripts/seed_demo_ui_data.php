<?php
declare(strict_types=1);

/**
 * Seed local demo data for UI review.
 *
 * Adds, without deleting existing business data:
 * - 40 demo clients
 * - 40 demo providers
 * - 2 demo products per demo provider
 * - 40 demo purchases distributed across demo providers
 *
 * The script is idempotent for the DEMO-UI records.
 */

require_once dirname(__DIR__) . '/src/config.php';
require_once dirname(__DIR__) . '/src/db_helpers.php';

$pdo = getPDO();
$pdo->exec("SET NAMES utf8mb4");

function demo_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $cache[$table] = array_fill_keys(array_map('strval', array_column($rows, 'Field')), true);
    }
    return $cache[$table];
}

function demo_has_column(PDO $pdo, string $table, string $column): bool {
    $columns = demo_columns($pdo, $table);
    return isset($columns[$column]);
}

function demo_insert(PDO $pdo, string $table, array $data): int {
    $columns = demo_columns($pdo, $table);
    $data = array_intersect_key($data, $columns);

    if ($data === []) {
        throw new RuntimeException("No columns available for $table insert.");
    }

    $fieldSql = implode(', ', array_map(static fn(string $field): string => "`$field`", array_keys($data)));
    $paramSql = implode(', ', array_map(static fn(string $field): string => ":$field", array_keys($data)));
    $st = $pdo->prepare("INSERT INTO `$table` ($fieldSql) VALUES ($paramSql)");
    foreach ($data as $field => $value) {
        $st->bindValue(":$field", $value);
    }
    $st->execute();

    return (int)$pdo->lastInsertId();
}

function demo_update(PDO $pdo, string $table, int $id, array $data): void {
    $columns = demo_columns($pdo, $table);
    $data = array_intersect_key($data, $columns);
    unset($data['id']);

    if ($data === []) {
        return;
    }

    $setSql = implode(', ', array_map(static fn(string $field): string => "`$field` = :$field", array_keys($data)));
    $st = $pdo->prepare("UPDATE `$table` SET $setSql WHERE id = :id");
    foreach ($data as $field => $value) {
        $st->bindValue(":$field", $value);
    }
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->execute();
}

function demo_scalar(PDO $pdo, string $sql, array $params = []): mixed {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

function demo_count(PDO $pdo, string $table): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

function demo_find_id_by_field(PDO $pdo, string $table, string $field, mixed $value): ?int {
    $st = $pdo->prepare("SELECT id FROM `$table` WHERE `$field` = :value LIMIT 1");
    $st->execute([':value' => $value]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function demo_money(float $value): float {
    return round($value, 2);
}

$before = [
    'clientes' => demo_count($pdo, 'clientes'),
    'proveedores' => demo_count($pdo, 'proveedores'),
    'productos' => demo_count($pdo, 'productos'),
    'compras' => demo_count($pdo, 'compras'),
    'compra_items' => demo_count($pdo, 'compra_items'),
];

$summary = [
    'clientes_created' => 0,
    'clientes_existing' => 0,
    'proveedores_created' => 0,
    'proveedores_existing' => 0,
    'productos_created' => 0,
    'productos_existing' => 0,
    'compras_created' => 0,
    'compras_existing' => 0,
    'items_created' => 0,
    'stock_added' => 0.0,
];

$pdo->beginTransaction();

try {
    $clientTypes = ['MINORISTA', 'MAYORISTA', 'CORPORATIVO'];
    $condIva = ['CF', 'RI', 'MT', 'EX'];
    $clientIds = [];

    for ($i = 1; $i <= 40; $i++) {
        $name = sprintf('Demo Cliente UI %02d', $i);
        $id = demo_find_id_by_field($pdo, 'clientes', 'nombre', $name);

        if ($id === null) {
            $ccEnabled = $i % 3 !== 0 ? 1 : 0;
            $ccLimit = $ccEnabled ? (float)(15000 + ($i * 1250)) : 0.0;
            $ccSaldo = $ccEnabled ? (float)(($i % 5) * 4200) : 0.0;
            if ($i % 11 === 0) {
                $ccSaldo = $ccLimit + 2500;
            }

            $data = [
                'nombre' => $name,
                'cuit' => sprintf('20-%08d-%d', 91000000 + $i, $i % 10),
                'cond_iva' => $condIva[$i % count($condIva)],
                'tipo_cliente' => $clientTypes[$i % count($clientTypes)],
                'direccion' => sprintf('Calle Demo %d, Local %d', 100 + $i, $i),
                'email' => sprintf('cliente.demo%02d@example.com', $i),
                'telefono' => sprintf('+54 261 555-%04d', 3000 + $i),
                'zona_reparto' => ($i % 4 === 0) ? 'CENTRO' : (($i % 4 === 1) ? 'NORTE' : (($i % 4 === 2) ? 'SUR' : 'ESTE')),
                'descuento_porcentaje' => ($i % 6 === 0) ? 8.5 : (($i % 10 === 0) ? 12.0 : 0.0),
                'notas' => 'Cliente demo para revision de UI.',
                'activo' => ($i % 13 === 0) ? 0 : 1,
                'cc_habilitado' => $ccEnabled,
                'cc_limite' => $ccLimit,
                'cc_saldo' => $ccSaldo,
                'cc_fecha_ultimo_pago' => $ccEnabled && $i % 4 !== 0 ? date('Y-m-d', strtotime("-$i days")) : null,
                'cc_notas' => $ccEnabled ? 'Cuenta corriente demo.' : null,
            ];

            $id = demo_insert($pdo, 'clientes', $data);
            $summary['clientes_created']++;
        } else {
            $summary['clientes_existing']++;
        }

        $clientIds[] = $id;
    }

    $providerIds = [];
    $providerNames = [];
    $providerProductIds = [];

    for ($i = 1; $i <= 40; $i++) {
        $name = sprintf('Demo Proveedor UI %02d', $i);
        $id = demo_find_id_by_field($pdo, 'proveedores', 'nombre', $name);

        if ($id === null) {
            $data = [
                'nombre' => $name,
                'razon_social' => sprintf('Demo Proveedor UI %02d SRL', $i),
                'cuit' => sprintf('30-%08d-%d', 93000000 + $i, $i % 10),
                'contacto_nombre' => sprintf('Contacto Demo %02d', $i),
                'telefono' => sprintf('261-444-%04d', 2000 + $i),
                'email' => sprintf('compras.demo%02d@example.com', $i),
                'whatsapp' => sprintf('549261555%04d', 1000 + $i),
                'direccion' => sprintf('Ruta Demo %d km %d', 20 + $i, $i),
                'ciudad' => ($i % 2 === 0) ? 'Mendoza' : 'Godoy Cruz',
                'provincia' => 'Mendoza',
                'dias_pago' => [0, 7, 15, 30, 45][$i % 5],
                'descuento_habitual' => ($i % 6 === 0) ? 7.5 : (($i % 9 === 0) ? 12.0 : 0.0),
                'notas' => 'Proveedor demo para revision de listados y compras.',
                'activo' => ($i % 17 === 0) ? 0 : 1,
            ];

            $id = demo_insert($pdo, 'proveedores', $data);
            $summary['proveedores_created']++;
        } else {
            $summary['proveedores_existing']++;
        }

        $providerIds[$i] = $id;
        $providerNames[$i] = $name;
        $providerProductIds[$i] = [];

        for ($j = 1; $j <= 2; $j++) {
            $code = sprintf('DEMO-UI-P%02d-%02d', $i, $j);
            $productId = demo_find_id_by_field($pdo, 'productos', 'codigo', $code);
            $cost = demo_money(450 + ($i * 37) + ($j * 180));
            $price = demo_money($cost * 1.65);

            $productData = [
                'codigo' => $code,
                'nombre' => sprintf('Producto Demo UI %02d-%02d', $i, $j),
                'categoria' => ($i % 2 === 0) ? 'Demo almacen' : 'Demo limpieza',
                'marca' => sprintf('Marca Demo %02d', (($i + $j) % 8) + 1),
                'proveedor' => $name,
                'proveedor_id' => $id,
                'iva' => 21.00,
                'precio' => $price,
                'stock' => $productId ? null : 0.000,
                'costo' => $cost,
                'stock_minimo' => 5.000,
                'es_pesable' => 0,
                'unidad_venta' => 'UNIDAD',
                'stock_inicial' => 0.000,
                'activo' => 1,
                'iva_porcentaje' => 21.00,
            ];

            if ($productId === null) {
                $productData = array_filter($productData, static fn(mixed $value): bool => $value !== null);
                $productId = demo_insert($pdo, 'productos', $productData);
                $summary['productos_created']++;
            } else {
                unset($productData['stock']);
                demo_update($pdo, 'productos', $productId, $productData);
                $summary['productos_existing']++;
            }

            $providerProductIds[$i][] = $productId;
        }
    }

    for ($i = 1; $i <= 40; $i++) {
        $nroComp = sprintf('DEMO-UI-%04d', $i);
        $existingCompraId = demo_scalar(
            $pdo,
            "SELECT id FROM compras WHERE nro_comp = :nro AND tipo_comp = 'DEMO' LIMIT 1",
            [':nro' => $nroComp]
        );

        if ($existingCompraId) {
            $summary['compras_existing']++;
            continue;
        }

        $providerIndex = (($i - 1) % 16) + 1;
        $providerId = $providerIds[$providerIndex];
        $products = $providerProductIds[$providerIndex];
        $estado = ($i % 10 === 0) ? 'ANULADA' : (($i % 7 === 0) ? 'BORRADOR' : 'CONFIRMADA');
        $fecha = date('Y-m-d H:i:s', strtotime('-' . (($i * 2) % 55) . ' days +' . ($i % 8) . ' hours'));

        $items = [];
        $totalBruto = 0.0;
        foreach ($products as $idx => $productId) {
            $qty = (float)(4 + (($i + $idx) % 9));
            $cost = demo_money(650 + ($providerIndex * 42) + ($idx * 210) + (($i % 5) * 35));
            $subtotal = demo_money($qty * $cost);
            $discountType = (($i + $idx) % 6 === 0) ? 'PORC' : 'MONTO';
            $discountPct = $discountType === 'PORC' ? 5.00 : 0.00;
            $discount = $discountType === 'PORC' ? $discountPct : (($i + $idx) % 8 === 0 ? 300.00 : 0.00);

            $items[] = [
                'producto_id' => $productId,
                'cantidad' => $qty,
                'costo_unitario' => $cost,
                'subtotal' => $subtotal,
                'comentario' => 'Item demo UI',
                'descuento' => $discount,
                'descuento_tipo' => $discountType,
                'descuento_porc' => $discountPct,
            ];
            $totalBruto += $subtotal;
        }

        $itemDiscountTotal = 0.0;
        foreach ($items as $item) {
            if ($item['descuento_tipo'] === 'PORC') {
                $itemDiscountTotal += demo_money($item['subtotal'] * ($item['descuento_porc'] / 100));
            } else {
                $itemDiscountTotal += min((float)$item['descuento'], (float)$item['subtotal']);
            }
        }
        $itemDiscountTotal = demo_money($itemDiscountTotal);
        $globalDiscount = ($i % 9 === 0) ? 750.00 : (($i % 11 === 0) ? demo_money(max(0, $totalBruto - $itemDiscountTotal) * 0.03) : 0.00);
        $total = demo_money(max(0, $totalBruto - $itemDiscountTotal - $globalDiscount));

        $compraData = [
            'proveedor_id' => $providerId,
            'fecha' => $fecha,
            'tipo_comp' => 'DEMO',
            'nro_comp' => $nroComp,
            'estado' => $estado,
            'total_neto' => $total,
            'total_iva' => 0.00,
            'total' => $total,
            'obs' => 'Compra demo para revision de UI.',
            'total_bruto' => demo_money($totalBruto),
            'descuento_tipo' => 'MONTO',
            'descuento_valor' => $globalDiscount,
            'descuento_total' => $globalDiscount,
        ];
        $compraId = demo_insert($pdo, 'compras', $compraData);
        $summary['compras_created']++;

        foreach ($items as $item) {
            demo_insert($pdo, 'compra_items', [
                'compra_id' => $compraId,
                'producto_id' => $item['producto_id'],
                'cantidad' => $item['cantidad'],
                'costo_unitario' => $item['costo_unitario'],
                'subtotal' => $item['subtotal'],
                'comentario' => $item['comentario'],
                'descuento' => $item['descuento'],
                'descuento_tipo' => $item['descuento_tipo'],
                'descuento_porc' => $item['descuento_porc'],
            ]);
            $summary['items_created']++;

            if ($estado === 'CONFIRMADA') {
                $st = $pdo->prepare("UPDATE productos SET stock = stock + :qty, costo = :cost WHERE id = :id");
                $st->execute([
                    ':qty' => $item['cantidad'],
                    ':cost' => $item['costo_unitario'],
                    ':id' => $item['producto_id'],
                ]);
                $summary['stock_added'] += (float)$item['cantidad'];
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Seed failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$after = [
    'clientes' => demo_count($pdo, 'clientes'),
    'proveedores' => demo_count($pdo, 'proveedores'),
    'productos' => demo_count($pdo, 'productos'),
    'compras' => demo_count($pdo, 'compras'),
    'compra_items' => demo_count($pdo, 'compra_items'),
];

echo "Demo UI seed completed." . PHP_EOL;
foreach ($summary as $key => $value) {
    echo "  $key: " . (is_float($value) ? number_format($value, 3, '.', '') : (string)$value) . PHP_EOL;
}
echo "Counts before:" . PHP_EOL;
foreach ($before as $table => $count) {
    echo "  $table: $count" . PHP_EOL;
}
echo "Counts after:" . PHP_EOL;
foreach ($after as $table => $count) {
    echo "  $table: $count" . PHP_EOL;
}
