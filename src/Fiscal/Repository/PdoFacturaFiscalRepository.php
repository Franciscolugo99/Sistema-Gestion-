<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db_schema.php';

final class PdoFacturaFiscalRepository implements FacturaFiscalRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function lockVenta(int $ventaId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ventas WHERE id = ? LIMIT 1 FOR UPDATE');
        $st->execute([$ventaId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function lockVentaAnulacion(int $ventaAnulacionId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM venta_anulaciones WHERE id = ? LIMIT 1 FOR UPDATE');
        $st->execute([$ventaAnulacionId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function findVentaAnulacionByRequestUid(string $requestUid): ?array
    {
        if ($requestUid === '' || !flus_column_exists($this->pdo, 'venta_anulaciones', 'fiscal_request_uid')) {
            return null;
        }

        $st = $this->pdo->prepare('SELECT * FROM venta_anulaciones WHERE fiscal_request_uid = ? LIMIT 1');
        $st->execute([$requestUid]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    public function findFacturaOrigenByVentaId(int $ventaId): ?array
    {
        if ($ventaId <= 0 || !flus_table_exists($this->pdo, 'facturas')) {
            return null;
        }

        $sql = "SELECT * FROM facturas WHERE venta_id = ?";
        if (flus_column_exists($this->pdo, 'facturas', 'naturaleza')) {
            $sql .= " AND naturaleza = 'FACTURA'";
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $st = $this->pdo->prepare($sql);
        $st->execute([$ventaId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    public function findFacturaById(int $facturaId): ?array
    {
        if ($facturaId <= 0) {
            return null;
        }

        $st = $this->pdo->prepare('SELECT * FROM facturas WHERE id = ? LIMIT 1');
        $st->execute([$facturaId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    public function findFacturaItems(int $facturaId): array
    {
        if ($facturaId <= 0 || !flus_table_exists($this->pdo, 'factura_items')) {
            return [];
        }

        $st = $this->pdo->prepare('SELECT * FROM factura_items WHERE factura_id = ? ORDER BY linea_orden ASC, id ASC');
        $st->execute([$facturaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function insertFactura(array $header): int
    {
        $schema = flus_current_db($this->pdo);
        if ($schema === '' || !flus_table_exists($this->pdo, 'facturas', $schema)) {
            throw new RuntimeException('La tabla facturas no existe.');
        }

        $colsSet = flus_columns_set($this->pdo, $schema, 'facturas');
        $cols = [];
        $placeholders = [];
        $params = [];

        foreach ($header as $col => $value) {
            $col = (string)$col;
            if (!isset($colsSet[$col])) {
                continue;
            }
            $cols[] = "`{$col}`";
            $placeholders[] = ':' . $col;
            $params[':' . $col] = $value;
        }

        if ($cols === []) {
            throw new RuntimeException('No hay columnas compatibles para insertar en facturas.');
        }

        $sql = sprintf(
            'INSERT INTO `facturas` (%s) VALUES (%s)',
            implode(', ', $cols),
            implode(', ', $placeholders)
        );

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    public function insertFacturaItems(int $facturaId, array $items): void
    {
        if ($facturaId <= 0 || $items === []) {
            return;
        }
        if (!flus_table_exists($this->pdo, 'factura_items')) {
            throw new RuntimeException('La tabla factura_items no existe.');
        }

        $schema = flus_current_db($this->pdo);
        $colsSet = flus_columns_set($this->pdo, $schema, 'factura_items');

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload = ['factura_id' => $facturaId] + $row;
            $cols = [];
            $placeholders = [];
            $params = [];
            foreach ($payload as $col => $value) {
                $col = (string)$col;
                if (!isset($colsSet[$col])) {
                    continue;
                }
                $cols[] = "`{$col}`";
                $placeholders[] = ':' . $col;
                $params[':' . $col] = $value;
            }
            if ($cols === []) {
                continue;
            }
            $sql = sprintf(
                'INSERT INTO `factura_items` (%s) VALUES (%s)',
                implode(', ', $cols),
                implode(', ', $placeholders)
            );
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
        }
    }

    public function insertArcaEvent(array $event): int
    {
        if (!flus_table_exists($this->pdo, 'factura_eventos_arca')) {
            throw new RuntimeException('La tabla factura_eventos_arca no existe.');
        }

        $schema = flus_current_db($this->pdo);
        $colsSet = flus_columns_set($this->pdo, $schema, 'factura_eventos_arca');
        $cols = [];
        $placeholders = [];
        $params = [];

        foreach ($event as $col => $value) {
            $col = (string)$col;
            if (!isset($colsSet[$col])) {
                continue;
            }
            $cols[] = "`{$col}`";
            $placeholders[] = ':' . $col;
            $params[':' . $col] = $value;
        }

        if ($cols === []) {
            throw new RuntimeException('No hay columnas compatibles para insertar en factura_eventos_arca.');
        }

        $sql = sprintf(
            'INSERT INTO `factura_eventos_arca` (%s) VALUES (%s)',
            implode(', ', $cols),
            implode(', ', $placeholders)
        );

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    public function findArcaEventByRequestUid(string $requestUid): ?array
    {
        if ($requestUid === '' || !flus_table_exists($this->pdo, 'factura_eventos_arca')) {
            return null;
        }

        $st = $this->pdo->prepare('SELECT * FROM factura_eventos_arca WHERE request_uid = ? LIMIT 1');
        $st->execute([$requestUid]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    public function updateArcaEventResult(string $requestUid, array $patch): void
    {
        if ($requestUid === '' || !flus_table_exists($this->pdo, 'factura_eventos_arca')) {
            return;
        }

        $allowed = [
            'venta_anulacion_id',
            'factura_id',
            'resultado',
            'intento_no',
            'modo',
            'error_code',
            'error_message',
            'request_json',
            'response_json',
            'finished_at',
        ];

        $sets = [];
        $params = [':request_uid' => $requestUid];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $patch) || !flus_column_exists($this->pdo, 'factura_eventos_arca', $col)) {
                continue;
            }
            $sets[] = sprintf('`%s` = :%s', $col, $col);
            $params[':' . $col] = $patch[$col];
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE factura_eventos_arca SET ' . implode(', ', $sets) . ' WHERE request_uid = :request_uid';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    public function updateVentaAnulacionFiscalState(int $ventaAnulacionId, string $estadoFiscal, array $patch = []): void
    {
        if ($ventaAnulacionId <= 0 || !flus_table_exists($this->pdo, 'venta_anulaciones')) {
            return;
        }

        $sets = [];
        $params = [':id' => $ventaAnulacionId];

        if (flus_column_exists($this->pdo, 'venta_anulaciones', 'estado_fiscal')) {
            $sets[] = 'estado_fiscal = :estado_fiscal';
            $params[':estado_fiscal'] = $estadoFiscal;
        }

        $allowed = [
            'requiere_nc',
            'factura_origen_id',
            'nc_factura_id',
            'fiscal_request_uid',
            'fiscal_intentos',
            'fiscal_error_code',
            'fiscal_error_message',
            'fiscal_requested_at',
            'fiscal_approved_at',
            'fiscal_applied_at',
        ];

        foreach ($allowed as $col) {
            if (!array_key_exists($col, $patch) || !flus_column_exists($this->pdo, 'venta_anulaciones', $col)) {
                continue;
            }
            $sets[] = sprintf('`%s` = :%s', $col, $col);
            $params[':' . $col] = $patch[$col];
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE venta_anulaciones SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    public function updateVentaAnulacionLinkage(int $ventaAnulacionId, ?int $facturaOrigenId, ?int $ncFacturaId): void
    {
        if ($ventaAnulacionId <= 0 || !flus_table_exists($this->pdo, 'venta_anulaciones')) {
            return;
        }

        $sets = [];
        $params = [':id' => $ventaAnulacionId];

        if (flus_column_exists($this->pdo, 'venta_anulaciones', 'factura_origen_id')) {
            $sets[] = 'factura_origen_id = :factura_origen_id';
            $params[':factura_origen_id'] = $facturaOrigenId;
        }
        if (flus_column_exists($this->pdo, 'venta_anulaciones', 'nc_factura_id')) {
            $sets[] = 'nc_factura_id = :nc_factura_id';
            $params[':nc_factura_id'] = $ncFacturaId;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE venta_anulaciones SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    public function linkNotaCreditoToAnulacion(int $ventaAnulacionId, int $ncFacturaId): void
    {
        $this->updateVentaAnulacionLinkage($ventaAnulacionId, null, $ncFacturaId);
    }
}
