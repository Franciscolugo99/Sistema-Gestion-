<?php
// src/facturacion_lib.php
require_once __DIR__ . '/config.php';

function crearFacturaDesdeVenta(int $ventaId, int $clienteId): int {
    $pdo = getPDO();
    $pdo->beginTransaction();

    try {
        // 1) Leer la venta
        $st = $pdo->prepare("SELECT * FROM ventas WHERE id = ?");
        $st->execute([$ventaId]);
        $venta = $st->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            throw new Exception("Venta no encontrada.");
        }

        // 2) Verificar que no tenga factura previa
        $st = $pdo->prepare("SELECT id FROM facturas WHERE venta_id = ?");
        $st->execute([$ventaId]);
        if ($st->fetchColumn()) {
            throw new Exception("La venta ya tiene una factura emitida.");
        }

        // 3) Leer config de facturación activa
        $st = $pdo->prepare("
            SELECT *
            FROM config_facturacion
            WHERE activo = 1
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute();
        $config = $st->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            throw new Exception("No hay configuración de facturación activa.");
        }

        $puntoVenta = (int)$config['punto_venta'];
        $tipoCbte   = $config['tipo_comprobante'];   // ej. FA
        $numero     = (int)$config['proximo_numero'];

        // 4) Insertar factura
        $st = $pdo->prepare("
            INSERT INTO facturas
              (venta_id, cliente_id, tipo, punto_venta, numero, total, estado, creado_en)
            VALUES
              (:venta_id, :cliente_id, :tipo, :punto_venta, :numero, :total, :estado, NOW())
        ");

        $st->execute([
            ':venta_id'    => $ventaId,
            ':cliente_id'  => $clienteId,
            ':tipo'        => $tipoCbte,
            ':punto_venta' => $puntoVenta,
            ':numero'      => $numero,
            ':total'       => $venta['total'],
            ':estado'      => 'EMITIDA',
        ]);

        $facturaId = (int)$pdo->lastInsertId();

        // 5) Actualizar próximo número
        $st = $pdo->prepare("
            UPDATE config_facturacion
            SET proximo_numero = proximo_numero + 1
            WHERE id = :id
        ");
        $st->execute([':id' => $config['id']]);

        $pdo->commit();
        return $facturaId;

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
