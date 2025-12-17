<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../src/config.php';

$action = $_GET['action'] ?? '';

try {
    $pdo = getPDO();

    /*******************************
     * 1) Buscar producto por código
     *******************************/
    if ($action === 'buscar_producto') {
        $codigo = $_GET['codigo'] ?? '';
        $stmt = $pdo->prepare("
            SELECT id, codigo, nombre, precio, stock
            FROM productos
            WHERE codigo = ? AND activo = 1
        ");
        $stmt->execute([$codigo]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($prod) {
            echo json_encode(['ok' => true, 'producto' => $prod]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Producto no encontrado o inactivo']);
        }
        exit;
    }

    /*******************************
     * 2) Registrar venta desde CAJA
     *******************************/
    if ($action === 'registrar_venta') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception('JSON inválido');
        }

        $items       = $input['items'] ?? [];
        $medio_pago  = $input['medio_pago'] ?? 'SIN_ESPECIFICAR';
        $monto_pagado = floatval($input['monto_pagado'] ?? 0);

        if (empty($items)) {
            throw new Exception('No hay ítems en la venta');
        }

        // Calcular total
        $total = 0;
        foreach ($items as $item) {
            $total += $item['cantidad'] * $item['precio'];
        }

        if ($monto_pagado < $total) {
            throw new Exception('Pago insuficiente');
        }

        $vuelto = $monto_pagado - $total;

        // --------- Transacción ----------
        $pdo->beginTransaction();

        // Insertar venta
        $stmtVenta = $pdo->prepare("
            INSERT INTO ventas (total, medio_pago, monto_pagado, vuelto)
            VALUES (?, ?, ?, ?)
        ");
        $stmtVenta->execute([$total, $medio_pago, $monto_pagado, $vuelto]);
        $ventaId = $pdo->lastInsertId();

        // Preparar sentencias
        $stmtItem = $pdo->prepare("
            INSERT INTO venta_items (venta_id, producto_id, cantidad, precio, subtotal)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmtStock = $pdo->prepare("
            UPDATE productos
            SET stock = stock - ?
            WHERE id = ? AND stock >= ?
        ");

        $stmtMov = $pdo->prepare("
            INSERT INTO movimientos_stock (producto_id, tipo, cantidad, referencia_venta_id, comentario)
            VALUES (?, 'VENTA', ?, ?, NULL)
        ");

        // Procesar cada ítem
        foreach ($items as $item) {
            $productoId = intval($item['id']);
            $cantidad   = intval($item['cantidad']);
            $precio     = floatval($item['precio']);
            $subtotal   = $cantidad * $precio;

            // Chequear stock actual
            $check = $pdo->prepare("SELECT stock, nombre FROM productos WHERE id = ? FOR UPDATE");
            $check->execute([$productoId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception("Producto ID $productoId no existe");
            }
            if ($row['stock'] < $cantidad) {
                throw new Exception("Stock insuficiente para {$row['nombre']} (stock: {$row['stock']}, solicitado: {$cantidad})");
            }

            // Insertar item de venta
            $stmtItem->execute([$ventaId, $productoId, $cantidad, $precio, $subtotal]);

            // Actualizar stock
            $stmtStock->execute([$cantidad, $productoId, $cantidad]);

            // Registrar movimiento de stock
            $stmtMov->execute([$productoId, $cantidad, $ventaId]);
        }

        $pdo->commit();

        echo json_encode([
            'ok'           => true,
            'venta_id'     => $ventaId,
            'total'        => $total,
            'monto_pagado' => $monto_pagado,
            'vuelto'       => $vuelto
        ]);
        exit;
    }

    // Acción desconocida
    echo json_encode(['ok' => false, 'error' => 'Acción no válida']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
        