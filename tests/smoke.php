<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/compras_helpers.php';
require_once __DIR__ . '/../src/facturacion_manual_lib.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/cobranzas_lib.php';
require_once __DIR__ . '/../public/includes/CuentaCorrienteController.php';
require_once __DIR__ . '/../src/Fiscal/bootstrap.php';

final class FlusFakePdoStatement extends PDOStatement
{
    private FlusFakePdo $pdo;
    private string $sql = '';
    private array $rows = [];
    private int $cursor = 0;
    private mixed $scalar = null;

    protected function __construct()
    {
    }

    public static function create(FlusFakePdo $pdo, string $sql): self
    {
        $ref = new ReflectionClass(self::class);
        /** @var self $stmt */
        $stmt = $ref->newInstanceWithoutConstructor();
        $stmt->pdo = $pdo;
        $stmt->sql = $sql;
        return $stmt;
    }

    public function execute(?array $params = null): bool
    {
        $result = $this->pdo->runStatement($this->sql, $params ?? []);
        $this->rows = $result['rows'] ?? [];
        $this->scalar = $result['scalar'] ?? null;
        $this->cursor = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }

        $row = $this->rows[$this->cursor];
        $this->cursor++;
        if ($mode === PDO::FETCH_COLUMN) {
            return array_values($row)[0] ?? false;
        }
        return $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            $column = isset($args[0]) ? (int)$args[0] : 0;
            return array_map(static function (array $row) use ($column) {
                $values = array_values($row);
                return $values[$column] ?? null;
            }, $this->rows);
        }
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->scalar !== null) {
            return $this->scalar;
        }
        if (!isset($this->rows[0])) {
            return false;
        }
        $values = array_values($this->rows[0]);
        return $values[$column] ?? false;
    }
}

final class FlusFakePdo extends PDO
{
    private string $schemaName = 'flus_test';
    /** @var array<string,array{columns:array<int,string>,rows:array<int,array<string,mixed>>,auto:int}> */
    private array $tables = [];
    private int $lastInsertIdValue = 0;
    private bool $inTx = false;
    private array $snapshot = [];

    public function __construct()
    {
    }

    public function seedTable(string $table, array $columns, array $rows = []): void
    {
        $normalizedRows = [];
        $maxId = 0;
        foreach ($rows as $row) {
            $normalizedRows[] = $row;
            $maxId = max($maxId, (int)($row['id'] ?? 0));
        }

        $this->tables[$table] = [
            'columns' => array_values(array_unique(array_map('strval', $columns))),
            'rows' => $normalizedRows,
            'auto' => $maxId + 1,
        ];
    }

    public function rows(string $table): array
    {
        return $this->tables[$table]['rows'] ?? [];
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = $this->prepare($query);
        if ($stmt === false) {
            return false;
        }
        $stmt->execute();
        return $stmt;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return FlusFakePdoStatement::create($this, $query);
    }

    public function beginTransaction(): bool
    {
        $this->inTx = true;
        $this->snapshot = [
            'tables' => unserialize(serialize($this->tables)),
            'lastInsertIdValue' => $this->lastInsertIdValue,
        ];
        return true;
    }

    public function commit(): bool
    {
        $this->inTx = false;
        $this->snapshot = [];
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->snapshot !== []) {
            $this->tables = $this->snapshot['tables'];
            $this->lastInsertIdValue = (int)$this->snapshot['lastInsertIdValue'];
        }
        $this->inTx = false;
        $this->snapshot = [];
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string)$this->lastInsertIdValue;
    }

    public function runStatement(string $sql, array $params): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

        if (strcasecmp($normalized, 'SELECT DATABASE()') === 0) {
            return ['rows' => [[0 => $this->schemaName]], 'scalar' => $this->schemaName];
        }

        if (preg_match('/^SELECT 1 FROM `?(\w+)`? LIMIT 0$/i', $normalized, $m) === 1) {
            if (!isset($this->tables[$m[1]])) {
                throw new RuntimeException('Tabla inexistente en fake PDO: ' . $m[1]);
            }
            return ['rows' => []];
        }

        if (str_contains($normalized, 'FROM information_schema.TABLES')) {
            $table = (string)($params[1] ?? '');
            $exists = isset($this->tables[$table]);
            return ['rows' => $exists ? [[1]] : [], 'scalar' => $exists ? 1 : false];
        }

        if (str_contains($normalized, 'FROM information_schema.COLUMNS')) {
            $table = (string)($params[1] ?? '');
            $columns = $this->tables[$table]['columns'] ?? [];
            if (count($params) >= 3) {
                $column = (string)($params[2] ?? '');
                if (in_array($column, $columns, true)) {
                    return ['rows' => [[
                        'COLUMN_NAME' => $column,
                        'COLUMN_TYPE' => 'int',
                        'DATA_TYPE' => 'int',
                        'IS_NULLABLE' => 'YES',
                        'COLUMN_DEFAULT' => null,
                    ]]];
                }
                return ['rows' => []];
            }
            return ['rows' => array_map(static fn(string $column): array => ['COLUMN_NAME' => $column], $columns)];
        }

        if (preg_match('/^SHOW COLUMNS FROM `?(\w+)`?$/i', $normalized, $m) === 1) {
            $columns = $this->tables[$m[1]]['columns'] ?? [];
            return ['rows' => array_map(static fn(string $column): array => ['Field' => $column], $columns)];
        }

        if (preg_match('/^INSERT INTO `?(\w+)`? \((.+)\) VALUES \((.+)\)$/i', $normalized, $m) === 1) {
            $table = $m[1];
            if (!isset($this->tables[$table])) {
                throw new RuntimeException('Tabla inexistente en insert fake: ' . $table);
            }
            preg_match_all('/`([^`]+)`/', $m[2], $colMatches);
            $columns = $colMatches[1] ?? [];
            if ($columns === []) {
                $columns = array_map(static fn(string $col): string => trim($col, " `\t\n\r\0\x0B"), explode(',', $m[2]));
            }
            $row = [];
            $position = 0;
            foreach ($columns as $column) {
                if ($params !== [] && array_is_list($params)) {
                    $row[$column] = $params[$position] ?? null;
                    $position++;
                    continue;
                }
                $row[$column] = $params[':' . $column] ?? null;
            }
            if (in_array('request_uid', $this->tables[$table]['columns'], true) && !empty($row['request_uid'])) {
                foreach ($this->tables[$table]['rows'] as $existing) {
                    if ((string)($existing['request_uid'] ?? '') === (string)$row['request_uid']) {
                        throw new RuntimeException('Duplicate request_uid');
                    }
                }
            }
            if (in_array('id', $this->tables[$table]['columns'], true) && !isset($row['id'])) {
                $row['id'] = $this->tables[$table]['auto'];
                $this->tables[$table]['auto']++;
            }
            $this->tables[$table]['rows'][] = $row;
            $this->lastInsertIdValue = (int)($row['id'] ?? 0);
            return ['rows' => []];
        }

        if (preg_match('/^UPDATE `?(\w+)`? SET (.+) WHERE (.+)$/i', $normalized, $m) === 1) {
            $table = $m[1];
            $assignments = array_map('trim', explode(',', $m[2]));
            $position = 0;
            $updates = [];
            foreach ($assignments as $assignment) {
                if (preg_match('/`?(\w+)`?\s*=\s*(:\w+|\?)/', $assignment, $assignMatch) !== 1) {
                    continue;
                }
                $column = $assignMatch[1];
                $token = $assignMatch[2];
                $updates[$column] = $token === '?' ? ($params[$position++] ?? null) : ($params[$token] ?? null);
            }

            if (preg_match('/id\s*=\s*(:\w+|\?)/i', $m[3], $whereMatch) !== 1) {
                throw new RuntimeException('WHERE id no soportado en fake PDO: ' . $normalized);
            }
            $token = $whereMatch[1];
            $id = $token === '?' ? ($params[$position] ?? null) : ($params[$token] ?? null);

            foreach ($this->tables[$table]['rows'] as &$row) {
                if ((int)($row['id'] ?? 0) !== (int)$id) {
                    continue;
                }
                foreach ($updates as $column => $value) {
                    $row[$column] = $value;
                }
                unset($row);
                return ['rows' => []];
            }
            unset($row);
            return ['rows' => []];
        }

        if (preg_match('/^SELECT \* FROM (\w+) WHERE (\w+) = \? (?:ORDER BY id DESC )?LIMIT 1$/i', $normalized, $m) === 1) {
            $table = $m[1];
            $column = $m[2];
            $value = $params[0] ?? null;
            $rows = array_values(array_filter($this->tables[$table]['rows'] ?? [], static function (array $row) use ($column, $value): bool {
                return (string)($row[$column] ?? '') === (string)$value;
            }));
            if (str_contains($normalized, 'ORDER BY id DESC')) {
                usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            }
            return ['rows' => $rows !== [] ? [$rows[0]] : []];
        }

        if (preg_match('/^SELECT \* FROM documentos_comerciales WHERE documento_origen_id = \? AND tipo_documento = \? ORDER BY id DESC LIMIT 1$/i', $normalized) === 1) {
            $origenId = (int)($params[0] ?? 0);
            $tipo = (string)($params[1] ?? '');
            $rows = array_values(array_filter($this->tables['documentos_comerciales']['rows'] ?? [], static function (array $row) use ($origenId, $tipo): bool {
                return (int)($row['documento_origen_id'] ?? 0) === $origenId
                    && strtoupper((string)($row['tipo_documento'] ?? '')) === strtoupper($tipo);
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            return ['rows' => $rows !== [] ? [$rows[0]] : []];
        }

        if (preg_match('/^SELECT \* FROM documentos_comerciales WHERE documento_origen_id = \? ORDER BY id DESC$/i', $normalized) === 1) {
            $origenId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['documentos_comerciales']['rows'] ?? [], static function (array $row) use ($origenId): bool {
                return (int)($row['documento_origen_id'] ?? 0) === $origenId;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            return ['rows' => $rows];
        }

        if (preg_match('/^SELECT \* FROM facturas WHERE documento_id = \? ORDER BY id DESC LIMIT 1$/i', $normalized) === 1) {
            $documentoId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['facturas']['rows'] ?? [], static function (array $row) use ($documentoId): bool {
                return (int)($row['documento_id'] ?? 0) === $documentoId;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            return ['rows' => $rows !== [] ? [$rows[0]] : []];
        }

        if (preg_match('/^SELECT k, v FROM app_config WHERE k IN \((.+)\)$/i', $normalized) === 1) {
            $rows = [];
            foreach ($this->tables['app_config']['rows'] ?? [] as $row) {
                if (in_array((string)($row['k'] ?? ''), ['business_email', 'business_name'], true)) {
                    $rows[] = ['k' => (string)($row['k'] ?? ''), 'v' => (string)($row['v'] ?? '')];
                }
            }
            return ['rows' => $rows];
        }


        if (preg_match('/^SELECT cc_habilitado, cc_saldo, nombre FROM clientes WHERE id = \? FOR UPDATE$/i', $normalized) === 1) {
            $clienteId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['clientes']['rows'] ?? [], static function (array $row) use ($clienteId): bool {
                return (int)($row['id'] ?? 0) === $clienteId;
            }));
            return ['rows' => $rows !== [] ? [[
                'cc_habilitado' => $rows[0]['cc_habilitado'] ?? 0,
                'cc_saldo' => $rows[0]['cc_saldo'] ?? 0,
                'nombre' => $rows[0]['nombre'] ?? '',
            ]] : []];
        }

        if (preg_match('/^SELECT cc_saldo FROM clientes WHERE id = \? FOR UPDATE$/i', $normalized) === 1) {
            $clienteId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['clientes']['rows'] ?? [], static function (array $row) use ($clienteId): bool {
                return (int)($row['id'] ?? 0) === $clienteId;
            }));
            return ['rows' => $rows !== [] ? [[
                'cc_saldo' => $rows[0]['cc_saldo'] ?? 0,
            ]] : []];
        }

        if (preg_match('/^SELECT \* FROM cuenta_corriente_movimientos WHERE cliente_id = \? AND tipo = \? AND estado = \? AND monto = \? AND medio_pago = \? AND created_by = \? ORDER BY id DESC$/i', $normalized) === 1) {
            $clienteId = (int)($params[0] ?? 0);
            $tipo = (string)($params[1] ?? '');
            $estado = (string)($params[2] ?? '');
            $monto = round((float)($params[3] ?? 0), 2);
            $medioPago = (string)($params[4] ?? '');
            $createdBy = (int)($params[5] ?? 0);
            $rows = array_values(array_filter($this->tables['cuenta_corriente_movimientos']['rows'] ?? [], static function (array $row) use ($clienteId, $tipo, $estado, $monto, $medioPago, $createdBy): bool {
                return (int)($row['cliente_id'] ?? 0) === $clienteId
                    && (string)($row['tipo'] ?? '') === $tipo
                    && (string)($row['estado'] ?? '') === $estado
                    && round((float)($row['monto'] ?? 0), 2) === $monto
                    && (string)($row['medio_pago'] ?? '') === $medioPago
                    && (int)($row['created_by'] ?? 0) === $createdBy;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            return ['rows' => $rows];
        }

        if (preg_match('/^SELECT \* FROM cuenta_corriente_movimientos WHERE id = \? FOR UPDATE$/i', $normalized) === 1) {
            $movimientoId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['cuenta_corriente_movimientos']['rows'] ?? [], static function (array $row) use ($movimientoId): bool {
                return (int)($row['id'] ?? 0) === $movimientoId;
            }));
            return ['rows' => $rows !== [] ? [$rows[0]] : []];
        }

        if (preg_match('/^SELECT id FROM cuenta_corriente_movimientos WHERE reversa_de_id = \? AND estado = \?$/i', $normalized) === 1) {
            $reversaDeId = (int)($params[0] ?? 0);
            $estado = (string)($params[1] ?? '');
            $rows = array_values(array_filter($this->tables['cuenta_corriente_movimientos']['rows'] ?? [], static function (array $row) use ($reversaDeId, $estado): bool {
                return (int)($row['reversa_de_id'] ?? 0) === $reversaDeId
                    && (string)($row['estado'] ?? '') === $estado;
            }));
            return ['rows' => $rows !== [] ? [['id' => (int)($rows[0]['id'] ?? 0)]] : []];
        }

        if (preg_match('/^SELECT MAX\(DATE\(created_at\)\) FROM cuenta_corriente_movimientos WHERE cliente_id = \? AND tipo = \? AND estado = \?$/i', $normalized) === 1) {
            $clienteId = (int)($params[0] ?? 0);
            $tipo = (string)($params[1] ?? '');
            $estado = (string)($params[2] ?? '');
            $maxDate = null;
            foreach ($this->tables['cuenta_corriente_movimientos']['rows'] ?? [] as $row) {
                if ((int)($row['cliente_id'] ?? 0) !== $clienteId
                    || (string)($row['tipo'] ?? '') !== $tipo
                    || (string)($row['estado'] ?? '') !== $estado) {
                    continue;
                }
                $createdAt = (string)($row['created_at'] ?? '');
                $candidate = $createdAt !== '' ? substr($createdAt, 0, 10) : null;
                if ($candidate === null || $candidate === '') {
                    continue;
                }
                if ($maxDate === null || strcmp($candidate, $maxDate) > 0) {
                    $maxDate = $candidate;
                }
            }
            return ['rows' => $maxDate !== null ? [[0 => $maxDate]] : [], 'scalar' => $maxDate];
        }

        if (preg_match("/^SELECT \\* FROM facturas WHERE documento_id = \\? AND naturaleza = 'FACTURA' ORDER BY id DESC LIMIT 1$/i", $normalized) === 1) {
            $value = $params[0] ?? null;
            $rows = array_values(array_filter($this->tables['facturas']['rows'] ?? [], static function (array $row) use ($value): bool {
                return (int)($row['documento_id'] ?? 0) === (int)$value && (string)($row['naturaleza'] ?? '') === 'FACTURA';
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            return ['rows' => $rows !== [] ? [$rows[0]] : []];
        }

        if (preg_match('/^SELECT codigo, descripcion AS nombre, cantidad, precio_unitario AS precio, subtotal, iva_porcentaje FROM documento_items WHERE documento_id = \? ORDER BY id ASC$/i', $normalized) === 1) {
            $documentoId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['documento_items']['rows'] ?? [], static function (array $row) use ($documentoId): bool {
                return (int)($row['documento_id'] ?? 0) === $documentoId;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
            $rows = array_map(static function (array $row): array {
                return [
                    'codigo' => $row['codigo'] ?? null,
                    'nombre' => $row['descripcion'] ?? '',
                    'cantidad' => $row['cantidad'] ?? 0,
                    'precio' => $row['precio_unitario'] ?? 0,
                    'subtotal' => $row['subtotal'] ?? 0,
                    'iva_porcentaje' => $row['iva_porcentaje'] ?? 21,
                ];
            }, $rows);
            return ['rows' => $rows];
        }

        if (preg_match('/^SELECT codigo, descripcion AS nombre, cantidad, precio_unitario AS precio, subtotal, iva_porcentaje FROM factura_manual_items WHERE venta_id = \? ORDER BY id ASC$/i', $normalized) === 1) {
            $ventaId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['factura_manual_items']['rows'] ?? [], static function (array $row) use ($ventaId): bool {
                return (int)($row['venta_id'] ?? 0) === $ventaId;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
            $rows = array_map(static function (array $row): array {
                return [
                    'codigo' => $row['codigo'] ?? null,
                    'nombre' => $row['descripcion'] ?? '',
                    'cantidad' => $row['cantidad'] ?? 0,
                    'precio' => $row['precio_unitario'] ?? 0,
                    'subtotal' => $row['subtotal'] ?? 0,
                    'iva_porcentaje' => $row['iva_porcentaje'] ?? 21,
                ];
            }, $rows);
            return ['rows' => $rows];
        }

        if (preg_match('/^SELECT \* FROM cobranzas WHERE external_key = \? ORDER BY id DESC LIMIT 1$/i', $normalized) === 1) {
            $externalKey = (string)($params[0] ?? '');
            $rows = array_values(array_filter($this->tables['cobranzas']['rows'] ?? [], static function (array $row) use ($externalKey): bool {
                return (string)($row['external_key'] ?? '') === $externalKey;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            return ['rows' => $rows !== [] ? [$rows[0]] : []];
        }

        if (preg_match('/^SELECT \* FROM cobranza_aplicaciones WHERE application_key = \? ORDER BY id DESC LIMIT 1$/i', $normalized) === 1) {
            $applicationKey = (string)($params[0] ?? '');
            $rows = array_values(array_filter($this->tables['cobranza_aplicaciones']['rows'] ?? [], static function (array $row) use ($applicationKey): bool {
                return (string)($row['application_key'] ?? '') === $applicationKey;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            return ['rows' => $rows !== [] ? [$rows[0]] : []];
        }

        if (preg_match('/^SELECT \* FROM cobranza_aplicaciones WHERE venta_id = \? ORDER BY id ASC$/i', $normalized) === 1) {
            $ventaId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['cobranza_aplicaciones']['rows'] ?? [], static function (array $row) use ($ventaId): bool {
                return (int)($row['venta_id'] ?? 0) === $ventaId;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
            return ['rows' => $rows];
        }

        if (preg_match('/^SELECT \* FROM cobranza_aplicaciones WHERE factura_id = \? ORDER BY id ASC$/i', $normalized) === 1) {
            $facturaId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['cobranza_aplicaciones']['rows'] ?? [], static function (array $row) use ($facturaId): bool {
                return (int)($row['factura_id'] ?? 0) === $facturaId;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
            return ['rows' => $rows];
        }

        if (preg_match('/^SELECT \* FROM cobranzas WHERE id = \? LIMIT 1$/i', $normalized) === 1) {
            $cobranzaId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['cobranzas']['rows'] ?? [], static function (array $row) use ($cobranzaId): bool {
                return (int)($row['id'] ?? 0) === $cobranzaId;
            }));
            return ['rows' => $rows !== [] ? [$rows[0]] : []];
        }

        if (preg_match('/^SELECT \* FROM recibo_aplicaciones WHERE application_key = \? ORDER BY id DESC LIMIT 1$/i', $normalized) === 1) {
            $applicationKey = (string)($params[0] ?? '');
            $rows = array_values(array_filter($this->tables['recibo_aplicaciones']['rows'] ?? [], static function (array $row) use ($applicationKey): bool {
                return (string)($row['application_key'] ?? '') === $applicationKey;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            return ['rows' => $rows !== [] ? [$rows[0]] : []];
        }

        if (preg_match('/^SELECT \* FROM recibo_aplicaciones WHERE factura_id = \? ORDER BY id ASC$/i', $normalized) === 1) {
            $facturaId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['recibo_aplicaciones']['rows'] ?? [], static function (array $row) use ($facturaId): bool {
                return (int)($row['factura_id'] ?? 0) === $facturaId;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
            return ['rows' => $rows];
        }

        if (preg_match('/^SELECT \* FROM recibo_aplicaciones WHERE documento_id = \? ORDER BY id ASC$/i', $normalized) === 1) {
            $documentoId = (int)($params[0] ?? 0);
            $rows = array_values(array_filter($this->tables['recibo_aplicaciones']['rows'] ?? [], static function (array $row) use ($documentoId): bool {
                return (int)($row['documento_id'] ?? 0) === $documentoId;
            }));
            usort($rows, static fn(array $a, array $b): int => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
            return ['rows' => $rows];
        }

        throw new RuntimeException('SQL fake no soportado: ' . $normalized);
    }
}

final class FlusFakeFacturaRepository implements FacturaFiscalRepository
{
    /** @param array<int,array<string,mixed>> $facturas */
    public function __construct(private array $facturas = [])
    {
    }

    public function lockVenta(int $ventaId): array { return []; }
    public function lockVentaAnulacion(int $ventaAnulacionId): array { return []; }
    public function findVentaAnulacionByRequestUid(string $requestUid): ?array { return null; }
    public function findFacturaOrigenByVentaId(int $ventaId): ?array { return null; }
    public function findFacturaOrigenByDocumentoId(int $documentoId): ?array { return null; }
    public function findFacturaById(int $facturaId): ?array {
        foreach ($this->facturas as $factura) {
            if ((int)($factura['id'] ?? 0) === $facturaId) {
                return $factura;
            }
        }
        return null;
    }
    public function lockFacturaById(int $facturaId): array { return []; }
    public function findFacturaByRequestUid(string $requestUid): ?array {
        foreach ($this->facturas as $factura) {
            if ((string)($factura['fiscal_request_uid'] ?? '') === $requestUid) {
                return $factura;
            }
        }
        return null;
    }
    public function findFacturaItems(int $facturaId): array { return []; }
    public function insertFactura(array $header): int { return 0; }
    public function insertFacturaItems(int $facturaId, array $items): void {}
    public function insertArcaEvent(array $event): int { return 0; }
    public function findArcaEventByRequestUid(string $requestUid): ?array { return null; }
    public function updateArcaEventResult(string $requestUid, array $patch): void {}
    public function updateFactura(int $facturaId, array $patch): void {}
    public function updateFacturaFiscalState(int $facturaId, string $estadoFiscal, array $patch = []): void {}
    public function updateVentaAnulacionFiscalState(int $ventaAnulacionId, string $estadoFiscal, array $patch = []): void {}
    public function updateVentaAnulacionLinkage(int $ventaAnulacionId, ?int $facturaOrigenId, ?int $ncFacturaId, bool $updateFacturaOrigenId = false, bool $updateNcFacturaId = false): void {}
    public function linkNotaCreditoToAnulacion(int $ventaAnulacionId, int $ncFacturaId): void {}
}

function flus_test_facturacion_fake_pdo(): FlusFakePdo
{
    $pdo = new FlusFakePdo();
    $pdo->seedTable('documentos_comerciales', [
        'id', 'request_uid', 'tipo_documento', 'origen', 'estado', 'cliente_id', 'venta_id', 'documento_origen_id',
        'nota', 'medio_pago', 'total', 'created_at', 'updated_at'
    ]);

    $pdo->seedTable('clientes', [
        'id', 'nombre', 'cc_habilitado', 'cc_saldo', 'cc_fecha_ultimo_pago'
    ]);
    $pdo->seedTable('cuenta_corriente_movimientos', [
        'id', 'cliente_id', 'tipo', 'estado', 'monto', 'saldo_anterior', 'saldo_posterior',
        'venta_id', 'concepto', 'medio_pago', 'referencia', 'request_uid', 'reversa_de_id',
        'created_at', 'created_by', 'autorizado_por', 'caja_id', 'caja_movimiento_id',
        'terminal_id', 'ip_address', 'updated_at'
    ]);
    $pdo->seedTable('caja_movimientos', [
        'id', 'caja_id', 'tipo', 'concepto', 'monto', 'usuario_registro', 'medio_pago', 'cc_movimiento_id'
    ]);
    $pdo->seedTable('documento_items', [
        'id', 'documento_id', 'codigo', 'descripcion', 'cantidad', 'precio_unitario',
        'subtotal', 'iva_porcentaje', 'created_at'
    ]);
    $pdo->seedTable('factura_manual_items', [
        'id', 'venta_id', 'codigo', 'descripcion', 'cantidad', 'precio_unitario',
        'subtotal', 'iva_porcentaje', 'created_at'
    ]);
    $pdo->seedTable('ventas', [
        'id', 'fecha', 'total', 'descuento_total', 'recargo_total', 'medio_pago', 'monto_pagado',
        'vuelto', 'nota', 'cliente_id', 'estado', 'facturada', 'uuid'
    ]);
    $pdo->seedTable('facturas', [
        'id', 'venta_id', 'documento_id', 'cliente_id', 'fiscal_request_uid', 'estado_fiscal', 'naturaleza'
    ]);
    $pdo->seedTable('cobranzas', [
        'id', 'external_key', 'origen', 'estado', 'venta_id', 'cliente_id', 'cc_movimiento_id',
        'caja_id', 'caja_movimiento_id', 'recibo_documento_id', 'medio_pago', 'importe_total', 'referencia',
        'observaciones', 'created_by', 'created_at', 'updated_at'
    ]);
    $pdo->seedTable('cobranza_aplicaciones', [
        'id', 'cobranza_id', 'application_key', 'tipo_aplicacion', 'venta_id', 'documento_id',
        'factura_id', 'cc_movimiento_id', 'monto', 'created_at'
    ]);
    $pdo->seedTable('recibo_aplicaciones', [
        'id', 'recibo_documento_id', 'cobranza_id', 'application_key', 'tipo_aplicacion', 'cliente_id',
        'cc_movimiento_id', 'documento_id', 'factura_id', 'monto', 'created_at'
    ]);
    return $pdo;
}

function flus_test_cc_controller_fake_pdo(): FlusFakePdo
{
    $pdo = flus_test_facturacion_fake_pdo();
    $pdo->seedTable('clientes', [
        'id', 'nombre', 'cc_habilitado', 'cc_saldo', 'cc_fecha_ultimo_pago'
    ], [
        ['id' => 77, 'nombre' => 'Cliente Demo', 'cc_habilitado' => 1, 'cc_saldo' => 1200.00, 'cc_fecha_ultimo_pago' => '2026-03-01'],
        ['id' => 88, 'nombre' => 'Cliente B', 'cc_habilitado' => 1, 'cc_saldo' => 900.00, 'cc_fecha_ultimo_pago' => '2026-03-01'],
    ]);
    return $pdo;
}

function flus_seed_cc_movimientos(FlusFakePdo $pdo, array $rows): void
{
    $pdo->seedTable('cuenta_corriente_movimientos', [
        'id', 'cliente_id', 'tipo', 'estado', 'monto', 'saldo_anterior', 'saldo_posterior',
        'venta_id', 'concepto', 'medio_pago', 'referencia', 'request_uid', 'reversa_de_id',
        'created_at', 'created_by', 'autorizado_por', 'caja_id', 'caja_movimiento_id',
        'terminal_id', 'ip_address', 'updated_at'
    ], $rows);
}

function flus_find_row_by_id(array $rows, int $id): ?array
{
    foreach ($rows as $row) {
        if ((int)($row['id'] ?? 0) === $id) {
            return $row;
        }
    }

    return null;
}

$results = [];

function flus_collect_permission_slugs_from_public(string $publicRoot): array
{
    if (!is_dir($publicRoot)) {
        throw new RuntimeException('Missing public root for permission scan');
    }

    $slugs = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($publicRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $php = (string)file_get_contents($file->getPathname());

        if (preg_match_all('/\b(?:require_permission|user_has_permission|\$can)\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $php, $matches)) {
            foreach ($matches[1] as $slug) {
                $slugs[] = $slug;
            }
        }

        if (preg_match_all('/\b(?:require_any_permission|user_has_any_permission)\(\s*\[(.*?)\]\s*\)/s', $php, $matches)) {
            foreach ($matches[1] as $list) {
                if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $list, $slugMatches)) {
                    foreach ($slugMatches[1] as $slug) {
                        $slugs[] = $slug;
                    }
                }
            }
        }
    }

    $slugs = array_values(array_unique(array_map('strval', $slugs)));
    sort($slugs, SORT_STRING);

    return $slugs;
}

function flus_collect_permission_slugs_from_sql_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing SQL file: ' . $path);
    }

    $sql = (string)file_get_contents($path);
    $slugs = [];

    if (preg_match_all("/\\(\\s*\\d+\\s*,\\s*'[^']+'\\s*,\\s*'([^']+)'\\s*,\\s*NOW\\(\\)\\s*\\)/", $sql, $matches)) {
        $slugs = $matches[1];
    } elseif (preg_match_all("/\\(\\s*'[^']+'\\s*,\\s*'([^']+)'\\s*,\\s*NOW\\(\\)\\s*\\)/", $sql, $matches)) {
        $slugs = $matches[1];
    }

    $slugs = array_values(array_unique(array_map('strval', $slugs)));
    sort($slugs, SORT_STRING);

    return $slugs;
}

function flus_collect_permission_slugs_from_sql_paths(array $paths): array
{
    $slugs = [];

    foreach ($paths as $path) {
        $slugs = array_merge($slugs, flus_collect_permission_slugs_from_sql_file($path));
    }

    $slugs = array_values(array_unique(array_map('strval', $slugs)));
    sort($slugs, SORT_STRING);

    return $slugs;
}

function flus_collect_permission_slugs_from_migrations(string $migrationsRoot): array
{
    if (!is_dir($migrationsRoot)) {
        throw new RuntimeException('Missing migrations root for permission scan');
    }

    $paths = glob($migrationsRoot . DIRECTORY_SEPARATOR . '*.sql');
    if ($paths === false) {
        throw new RuntimeException('Could not enumerate migration SQL files');
    }

    sort($paths, SORT_STRING);

    return flus_collect_permission_slugs_from_sql_paths($paths);
}

function flus_create_sqlite_memory_pdo(string $testName): PDO
{
    $drivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
    if (!in_array('sqlite', $drivers, true)) {
        flus_skip($testName . ': falta el driver pdo_sqlite');
    }

    try {
        $pdo = new PDO('sqlite::memory:');
    } catch (Throwable $e) {
        flus_skip($testName . ': sqlite::memory no se pudo inicializar (' . $e->getMessage() . ')');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function flus_open_project_pdo(string $repoRoot): PDO
{
    $configPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($configPath)) {
        flus_skip('permissions stay aligned across code, install, migrations and admin role: falta src/config.php');
    }

    require_once $configPath;

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        flus_skip('permissions stay aligned across code, install, migrations and admin role: faltan constantes DB_*');
    }

    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        flus_skip('permissions stay aligned across code, install, migrations and admin role: falta el driver pdo_mysql');
    }

    $port = defined('DB_PORT') ? (string)DB_PORT : '3306';
    $charset = defined('DB_CHARSET') ? (string)DB_CHARSET : 'utf8mb4';
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        (string)DB_HOST,
        $port,
        (string)DB_NAME,
        $charset
    );

    try {
        return new PDO($dsn, (string)DB_USER, (string)DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        flus_skip('permissions stay aligned across code, install, migrations and admin role: entorno MySQL no disponible (' . $e->getMessage() . ')');
    }
}

function flus_fetch_first_column(PDO $pdo, string $sql): array
{
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $values = array_values(array_unique(array_filter(array_map('strval', $rows), static fn(string $value): bool => $value !== '')));
    sort($values, SORT_STRING);

    return $values;
}

function flus_format_slug_diff(array $slugs): string
{
    return $slugs === [] ? 'ninguno' : implode(', ', $slugs);
}

function flus_count_file_lines(string $path): int
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('No se pudo leer el archivo para contar lineas: ' . $path);
    }

    if ($contents === '') {
        return 0;
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", $contents);

    return substr_count($normalized, "\n") + 1;
}

/**
 * @return array<string,int>
 */
function flus_hotspot_line_budgets(): array
{
    return [
        'src/facturacion_lib.php' => 2000,
        'src/facturacion_manual_lib.php' => 1350,
        'public/includes/CuentaCorrienteController.php' => 1550,
        'public/productos.php' => 1850,
        'public/compras.php' => 1650,
        'public/assets/js/caja.js' => 3850,
        'public/api/index.php' => 675,
        'public/bootstrap.php' => 350,
    ];
}

$results[] = flus_run_test('sh_quote handles platform quoting', function (): void {
    $path = 'C:\Program Files\MySQL\bin\mysqldump.exe';
    $quoted = sh_quote($path);
    $expected = stripos(PHP_OS_FAMILY, 'Windows') === 0
        ? '"' . str_replace('"', '""', $path) . '"'
        : escapeshellarg($path);
    flus_assert_same($expected, $quoted);
});

$results[] = flus_run_test('backup_restore_in_progress detects active lock', function (): void {
    $lockPath = FLUS_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'restore.lock';

    flus_assert_false(backup_restore_in_progress(), 'restore should start inactive');

    $fp = fopen($lockPath, 'c');
    if (!$fp) {
        throw new RuntimeException('Could not open restore.lock for test');
    }

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        throw new RuntimeException('Could not lock restore.lock in test');
    }

    flus_assert_true(backup_restore_in_progress(), 'active flock should be detected');

    flock($fp, LOCK_UN);
    fclose($fp);

    flus_assert_false(backup_restore_in_progress(), 'released lock should not be detected as active');
});

$results[] = flus_run_test('flus_make_shareable_path masks FLUS_ROOT', function (): void {
    $path = FLUS_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
    $label = flus_make_shareable_path($path);
    flus_assert_same('[FLUS_ROOT]/storage/logs/app.log', $label);
});

$results[] = flus_run_test('flus_sanitize_log_line redacts obvious secrets', function (): void {
    $line = 'email=cliente@example.com token=abc123456789 127.0.0.1 path=' . FLUS_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
    $sanitized = flus_sanitize_log_line($line);

    flus_assert_contains('[EMAIL]', $sanitized);
    flus_assert_contains('[IP]', $sanitized);
    flus_assert_contains('[FLUS_ROOT]', str_replace('\\', '/', $sanitized));
    flus_assert_not_contains('cliente@example.com', $sanitized);
    flus_assert_not_contains('127.0.0.1', $sanitized);
});

$results[] = flus_run_test('flus_get_sanitized_config masks shareable db values', function (): void {
    $shareable = flus_get_sanitized_config(true);
    $normal = flus_get_sanitized_config(false);

    flus_assert_same('***SET***', $shareable['DB_HOST']);
    flus_assert_same('***SET***', $shareable['DB_NAME']);
    flus_assert_same('***SET***', $shareable['DB_USER']);
    flus_assert_same(APP_NAME, $shareable['APP_NAME']);
    flus_assert_same(DB_HOST, $normal['DB_HOST']);
});

$results[] = flus_run_test('flus_build_diagnostic_overview escalates active problems', function (): void {
    $baseHealth = [
        'database' => ['connected' => true, 'name' => 'kiosco', 'selected_db' => 'kiosco'],
        'critical_tables' => ['missing_count' => 0, 'check_failed' => false],
        'disk' => ['used_percent' => 20],
        'active_locks' => [],
        'locks' => ['restore_in_progress' => false],
        'maintenance' => ['active' => false],
    ];

    $ok = flus_build_diagnostic_overview($baseHealth, null, null, null, ['total_critical' => 0]);
    flus_assert_same('ok', $ok['status']);

    $warnHealth = $baseHealth;
    $warnHealth['locks']['restore_in_progress'] = true;
    $warn = flus_build_diagnostic_overview($warnHealth, null, null, null, ['total_critical' => 0]);
    flus_assert_same('warning', $warn['status']);

    $errorHealth = $baseHealth;
    $errorHealth['critical_tables']['missing_count'] = 2;
    $error = flus_build_diagnostic_overview($errorHealth, null, null, null, ['total_critical' => 0]);
    flus_assert_same('error', $error['status']);
});

$results[] = flus_run_test('flus_format_bytes keeps current UI format', function (): void {
    flus_assert_same('1,50 KB', flus_format_bytes(1536));
});

$results[] = flus_run_test('flus_is_critical_role recognizes protected admin slugs', function (): void {
    flus_assert_true(flus_is_critical_role('admin'));
    flus_assert_true(flus_is_critical_role('administrador'));
    flus_assert_false(flus_is_critical_role('cajero'));
});

$results[] = flus_run_test('flus_validate_user_payload checks duplicates and role existence', function (): void {
    $pdo = flus_create_sqlite_memory_pdo('flus_validate_user_payload checks duplicates and role existence');
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO roles (id, nombre, slug) VALUES (1, 'Administrador', 'admin'), (2, 'Cajero', 'cajero')");
    $pdo->exec("INSERT INTO users (id, email, username, role_id, activo) VALUES (1, 'admin@flus.local', 'admin', 1, 1)");

    $result = flus_validate_user_payload($pdo, [
        'nombre' => 'Pe',
        'email' => 'admin@flus.local',
        'username' => 'admin',
        'password' => '123',
        'role_id' => 99,
        'activo' => 1,
    ], [
        'require_password' => true,
        'require_email' => true,
        'default_activo' => 1,
    ]);

    flus_assert_contains('El nombre debe tener al menos 3 caracteres', implode(' | ', $result['errors']));
    flus_assert_contains('Este email ya esta registrado', implode(' | ', $result['errors']));
    flus_assert_contains('Este nombre de usuario ya esta en uso', implode(' | ', $result['errors']));
    flus_assert_contains('La contrasena debe tener al menos 6 caracteres', implode(' | ', $result['errors']));
    flus_assert_contains('Debe seleccionar un rol valido', implode(' | ', $result['errors']));
});

$results[] = flus_run_test('flus_guard_user_admin_mutation blocks self deactivation', function (): void {
    $pdo = flus_create_sqlite_memory_pdo('flus_guard_user_admin_mutation blocks self deactivation');
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO roles (id, nombre, slug) VALUES (1, 'Administrador', 'admin')");
    $pdo->exec('INSERT INTO users (id, role_id, activo) VALUES (1, 1, 1)');

    $error = flus_guard_user_admin_mutation($pdo, 1, 1, 0, false, null);
    flus_assert_same('No puedes desactivar tu propio usuario', $error);
});

$results[] = flus_run_test('flus_guard_user_admin_mutation protects reserved admin account role', function (): void {
    $pdo = flus_create_sqlite_memory_pdo('flus_guard_user_admin_mutation protects reserved admin account role');
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO roles (id, nombre, slug) VALUES (1, 'Administrador', 'admin'), (2, 'Operador', 'operador')");
    $pdo->exec("INSERT INTO users (id, email, username, role_id, activo) VALUES (1, 'admin@flus.local', 'admin', 1, 1), (2, 'owner@flus.local', 'owner', 1, 1)");

    $error = flus_guard_user_admin_mutation($pdo, 2, 1, 1, false, 2, 'admin');
    flus_assert_same('La cuenta admin de resguardo mantiene su rol original.', $error);
});

$results[] = flus_run_test('flus_guard_reserved_admin_role_mutation locks reserved role permissions', function (): void {
    $pdo = flus_create_sqlite_memory_pdo('flus_guard_reserved_admin_role_mutation locks reserved role permissions');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO users (id, email, username, role_id, activo) VALUES (1, 'admin@flus.local', 'admin', 7, 1)");

    flus_assert_same('El rol base de la cuenta admin de resguardo no se puede editar desde Roles y Permisos.', flus_guard_reserved_admin_role_mutation($pdo, 7));
    flus_assert_same(null, flus_guard_reserved_admin_role_mutation($pdo, 8));
});
$results[] = flus_run_test('flus_normalize_sale_status normalizes empty and custom states', function (): void {
    flus_assert_same('EMITIDA', flus_normalize_sale_status(null));
    flus_assert_same('ANULADA', flus_normalize_sale_status('anulada'));
    flus_assert_same('PENDIENTE', flus_normalize_sale_status('pendiente'));
});

$results[] = flus_run_test('flus_sale_helpers keep annulled criteria consistent', function (): void {
    flus_assert_true(flus_sale_is_annulled(['estado' => 'ANULADA']));
    flus_assert_false(flus_sale_can_be_annulled(['estado' => 'ANULADA']));
    flus_assert_true(flus_sale_can_be_annulled(['estado' => null]));
    flus_assert_true(flus_sale_can_be_annulled(['estado' => 'PARCIALMENTE_ANULADA']));
    flus_assert_same("(v.estado IS NULL OR v.estado <> 'ANULADA')", flus_sale_emitida_where('v'));
    flus_assert_same("(estado IS NULL OR estado <> 'ANULADA')", flus_sale_emitida_where(''));
});

$results[] = flus_run_test('anulacion total comparte trazabilidad con anulaciones parciales', function () use ($repoRoot): void {
    $lib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'venta_anulaciones_lib.php');
    $action = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'anular_venta.php');

    flus_assert_contains('function flus_venta_anulacion_registrar_total_restante', $lib);
    flus_assert_contains("flus_venta_anulacion_registrar_total_restante(\$pdo, \$ventaId, \$venta, \$itemsRestantes, \$motivo, \$userId)", $action);
    flus_assert_contains("\$payload['anulacion_id']", $action);
});

$results[] = flus_run_test('flus_calcular_estado_producto keeps product status rules consistent', function (): void {
    flus_assert_same('inactivo', flus_calcular_estado_producto([
        'activo' => 0,
        'stock' => 10,
        'stock_minimo' => 5,
    ]));
    flus_assert_same('sin', flus_calcular_estado_producto([
        'activo' => 1,
        'stock' => 0,
        'stock_minimo' => 5,
    ]));
    flus_assert_same('bajo', flus_calcular_estado_producto([
        'activo' => 1,
        'stock' => 3,
        'stock_minimo' => 5,
    ]));
    flus_assert_same('ok', flus_calcular_estado_producto([
        'activo' => 1,
        'stock' => 8,
        'stock_minimo' => 5,
    ]));
});
$results[] = flus_run_test('facturacion mode helpers normalize aliases consistently', function (): void {
    flus_assert_same('homologacion', flus_facturacion_normalizar_modo('homo'));
    flus_assert_same('produccion', flus_facturacion_normalizar_modo('prod'));
    flus_assert_same('demo', flus_facturacion_normalizar_modo('demo'));
    flus_assert_same('Demo', flus_facturacion_modo_label('demo'));
    flus_assert_same('homo', flus_facturacion_arca_env_esperado('homologacion'));
    flus_assert_same('prod', flus_facturacion_arca_env_esperado('produccion'));
    flus_assert_same('', flus_facturacion_arca_env_esperado('demo'));
});

$results[] = flus_run_test('facturacion runtime helpers quedan extraidos del hotspot principal', function (): void {
    $repoRoot = dirname(__DIR__);
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');
    $runtimeLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_runtime_lib.php');

    flus_assert_contains("require_once __DIR__ . '/facturacion_runtime_lib.php';", $facturacionLib);
    flus_assert_contains('function flus_facturacion_modo_db_value', $runtimeLib);
    flus_assert_contains('function flus_facturacion_estado_fiscal_label', $runtimeLib);
    flus_assert_contains('function flus_facturacion_request_uid_manual', $runtimeLib);
    flus_assert_not_contains('function flus_facturacion_modo_db_value', $facturacionLib);
    flus_assert_not_contains('function flus_facturacion_estado_fiscal_label', $facturacionLib);
});

$results[] = flus_run_test('facturacion preflight y estado ARCA quedan extraidos del hotspot principal', function (): void {
    $repoRoot = dirname(__DIR__);
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');
    $preflightLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_preflight_lib.php');

    flus_assert_contains("require_once __DIR__ . '/facturacion_preflight_lib.php';", $facturacionLib);
    flus_assert_contains('function flus_facturacion_preflight_emision(PDO $pdo, ?array $config = null, array $opciones = []): array', $preflightLib);
    flus_assert_contains('function flus_facturacion_arca_status_current(PDO $pdo, ?string $modoEsperado = null, bool $forceProbe = false): array', $preflightLib);
    flus_assert_contains('function flus_facturacion_humanizar_error_arca(?string $raw): string', $preflightLib);
    flus_assert_not_contains('function flus_facturacion_preflight_emision(PDO $pdo, ?array $config = null, array $opciones = []): array', $facturacionLib);
    flus_assert_not_contains('function flus_facturacion_arca_status_current(PDO $pdo, ?string $modoEsperado = null, bool $forceProbe = false): array', $facturacionLib);
});

$results[] = flus_run_test('facturacion contexto y payload quedan extraidos del hotspot principal', function (): void {
    $repoRoot = dirname(__DIR__);
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');
    $contextLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_context_lib.php');

    flus_assert_contains("require_once __DIR__ . '/facturacion_context_lib.php';", $facturacionLib);
    flus_assert_contains('function flus_facturacion_request_uid_from_context(array $context, array $opciones = []): string', $contextLib);
    flus_assert_contains('function flus_facturacion_importes_desde_items(array $items, float $fallbackTotal, int $tipoCbte): array', $contextLib);
    flus_assert_contains('function flus_facturacion_factura_header_base(array $context, string $estadoFiscal = \'PENDIENTE_ENVIO\'): array', $contextLib);
    flus_assert_not_contains('function flus_facturacion_request_uid_from_context(array $context, array $opciones = []): string', $facturacionLib);
    flus_assert_not_contains('function flus_facturacion_factura_header_base(array $context, string $estadoFiscal = \'PENDIENTE_ENVIO\'): array', $facturacionLib);
});

$results[] = flus_run_test('facturacion iva and comprobante helpers stay stable', function (): void {
    flus_assert_same('RI', determinarCondIvaReceptor(['cond_iva' => 'Responsable Inscripto']));
    flus_assert_same('MT', determinarCondIvaReceptor(['cond_iva' => 'Monotributo']));
    flus_assert_same('CF', determinarCondIvaReceptor(['cond_iva' => 'Consumidor Final']));
    flus_assert_same(5, obtenerIdAlicuotaAfip(21.0));
    flus_assert_same(4, obtenerIdAlicuotaAfip(10.5));
    flus_assert_same('FA', obtenerNombreTipoComprobante(1));
    flus_assert_same('FC', obtenerNombreTipoComprobante(11));
});

$results[] = flus_run_test('facturacion manual items normalize totals and validate iva', function (): void {
    $items = flus_facturacion_normalize_manual_items([
        [
            'codigo' => 'P001',
            'descripcion' => 'Producto demo',
            'cantidad' => '2',
            'precio' => '150.50',
            'iva_porcentaje' => '21',
        ],
        [
            'descripcion' => 'Servicio exento',
            'cantidad' => '1',
            'precio' => '99.99',
            'iva_porcentaje' => '0',
        ],
    ]);

    flus_assert_same(2, count($items));
    flus_assert_same(301.0, $items[0]['subtotal']);
    flus_assert_same(99.99, $items[1]['subtotal']);

    try {
        flus_facturacion_normalize_manual_items([[
            'descripcion' => 'IVA invalido',
            'cantidad' => '1',
            'precio' => '10',
            'iva_porcentaje' => '3',
        ]]);
        throw new RuntimeException('Expected invalid IVA to throw');
    } catch (RuntimeException $e) {
        flus_assert_contains('alicuota IVA', $e->getMessage());
    }
});

$results[] = flus_run_test('compras helpers keep item discount calculations consistent', function (): void {
    $porc = flus_compra_item_metrics([
        'cantidad' => 2,
        'costo_unitario' => 100,
        'descuento_tipo' => 'PORC',
        'descuento_porc' => 10,
        'unidad_venta' => 'UNIDAD',
    ]);

    flus_assert_same(200.0, $porc['subtotal']);
    flus_assert_same(20.0, $porc['descuento_monto']);
    flus_assert_same(180.0, $porc['neto']);

    $monto = flus_compra_item_metrics([
        'cantidad' => 1,
        'costo_unitario' => 50,
        'subtotal' => 50,
        'descuento_tipo' => 'MONTO',
        'descuento' => 80,
        'unidad_venta' => 'UNIDAD',
    ]);

    flus_assert_same(50.0, $monto['descuento_monto']);
    flus_assert_same(0.0, $monto['neto']);
});

$results[] = flus_run_test('compras schema lives in migrations instead of runtime DDL', function (): void {
    $repoRoot = dirname(__DIR__);
    $migrationPath = $repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '005_compras_descuentos_schema.sql';
    $comprasPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'compras.php';

    if (!is_file($migrationPath)) {
        throw new RuntimeException('Missing compras schema migration');
    }
    if (!is_file($comprasPath)) {
        throw new RuntimeException('Missing compras.php');
    }

    $migrationSql = (string)file_get_contents($migrationPath);
    $comprasPhp = (string)file_get_contents($comprasPath);

    flus_assert_contains('ALTER TABLE compras', $migrationSql);
    flus_assert_contains('ALTER TABLE compra_items', $migrationSql);
    flus_assert_contains('005_compras_descuentos_schema.sql', $comprasPhp);
    flus_assert_not_contains('function flus_compras_ensure_schema', $comprasPhp);
    flus_assert_not_contains('ALTER TABLE compras ADD COLUMN', $comprasPhp);
    flus_assert_not_contains('ALTER TABLE compra_items ADD COLUMN', $comprasPhp);
});

$results[] = flus_run_test('pagination helper is centralized in src helpers', function (): void {
    $repoRoot = dirname(__DIR__);
    $helpersPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helpers.php';
    $pages = [
        'public/caja_historial.php',
        'public/movimientos.php',
        'public/stock.php',
        'public/ventas.php',
    ];

    if (!is_file($helpersPath)) {
        throw new RuntimeException('Missing shared helpers.php');
    }

    $helpersPhp = (string)file_get_contents($helpersPath);
    flus_assert_contains('function render_pagination', $helpersPhp);

    foreach ($pages as $pageFile) {
        $pagePath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pageFile);
        if (!is_file($pagePath)) {
            throw new RuntimeException('Missing page: ' . $pageFile);
        }

        $pagePhp = (string)file_get_contents($pagePath);
        flus_assert_not_contains('function render_pagination', $pagePhp, $pageFile);
    }
});
$results[] = flus_run_test('schema checks are centralized outside public pages', function (): void {
    $repoRoot = dirname(__DIR__);
    $schemaPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db_schema.php';
    $pages = [
        'public/proveedores.php',
        'public/productos.php',
        'public/precios_historial.php',
    ];

    if (!is_file($schemaPath)) {
        throw new RuntimeException('Missing db_schema.php');
    }

    $schemaPhp = (string)file_get_contents($schemaPath);
    flus_assert_contains('function flus_table_columns', $schemaPhp);
    flus_assert_contains('SHOW COLUMNS FROM', $schemaPhp);

    foreach ($pages as $pageFile) {
        $pagePath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pageFile);
        if (!is_file($pagePath)) {
            throw new RuntimeException('Missing page: ' . $pageFile);
        }

        $pagePhp = (string)file_get_contents($pagePath);
        flus_assert_not_contains('SHOW COLUMNS', $pagePhp, $pageFile);
    }
});

$results[] = flus_run_test('diagnostics access keeps dedicated permission compatibility', function (): void {
    $repoRoot = dirname(__DIR__);
    $installPath = $repoRoot . DIRECTORY_SEPARATOR . 'install.sql';
    $migrationPath = $repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '006_diagnostics_permission.sql';
    $authPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'auth.php';
    $diagPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'diagnostico.php';
    $diagDownloadPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'diagnostico_download.php';
    $navPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'nav.php';

    foreach ([$installPath, $migrationPath, $authPath, $diagPath, $diagDownloadPath, $navPath] as $requiredPath) {
        if (!is_file($requiredPath)) {
            throw new RuntimeException('Missing file: ' . $requiredPath);
        }
    }

    $installSql = (string)file_get_contents($installPath);
    $migrationSql = (string)file_get_contents($migrationPath);
    $authPhp = (string)file_get_contents($authPath);
    $diagPhp = (string)file_get_contents($diagPath);
    $diagDownloadPhp = (string)file_get_contents($diagDownloadPath);
    $navPhp = (string)file_get_contents($navPath);

    flus_assert_contains('ver_diagnostico', $installSql);
    flus_assert_contains('ver_diagnostico', $migrationSql);
    flus_assert_contains('gestionar_backups', $migrationSql);
    flus_assert_contains('function user_can_access_diagnostics', $authPhp);
    flus_assert_contains('function require_diagnostics_permission', $authPhp);
    flus_assert_contains('require_diagnostics_permission();', $diagPhp);
    flus_assert_contains('user_can_access_diagnostics()', $diagDownloadPhp);
    flus_assert_contains("\$can('ver_diagnostico')", $navPhp);
});

$results[] = flus_run_test('support schema is versioned for clean installs and upgrades', function (): void {
    $repoRoot = dirname(__DIR__);
    $installPath = $repoRoot . DIRECTORY_SEPARATOR . 'install.sql';
    $migrationPath = $repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '007_support_modules_schema.sql';
    $manualLibPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_manual_lib.php';

    foreach ([$installPath, $migrationPath, $manualLibPath] as $requiredPath) {
        if (!is_file($requiredPath)) {
            throw new RuntimeException('Missing file: ' . $requiredPath);
        }
    }

    $installSql = (string)file_get_contents($installPath);
    $migrationSql = (string)file_get_contents($migrationPath);
    $manualLibPhp = (string)file_get_contents($manualLibPath);

    flus_assert_contains('flusadmin123', $installSql);
    flus_assert_contains('yPokhUEft2w2kngTRjoBkuaq7cwygVwwfYA.oY.lKVH7Sxytlkkde', $installSql);
    flus_assert_contains('ver_diagnostico', $installSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS factura_manual_items', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS producto_reposicion', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS producto_precios_hist', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS inventario_sesiones', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS cuenta_corriente_movimientos', $migrationSql);
    flus_assert_contains('migrations/007_support_modules_schema.sql', $manualLibPhp);
    flus_assert_not_contains('CREATE TABLE IF NOT EXISTS factura_manual_items', $manualLibPhp);

    $precioHistPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'precio_historial.php');
    $auditEventsPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'audit_events.php');
    $cajaHistorialPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'caja_historial.php');
    $reposicionPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'reposicion_sugerida.php');

    flus_assert_contains('migrations/007_support_modules_schema.sql', $precioHistPhp);
    flus_assert_not_contains('CREATE TABLE IF NOT EXISTS producto_precios_hist', $precioHistPhp);
    flus_assert_contains("missing audit_log table", $auditEventsPhp);
    flus_assert_not_contains('CREATE TABLE IF NOT EXISTS audit_log', $auditEventsPhp);
    flus_assert_contains('migrations/007_support_modules_schema.sql', $cajaHistorialPhp);
    flus_assert_not_contains('CREATE TABLE IF NOT EXISTS caja_auditoria', $cajaHistorialPhp);
    flus_assert_not_contains('CREATE TABLE IF NOT EXISTS producto_reposicion', $reposicionPhp);
    flus_assert_contains('function _repo_join_aux(PDO $pdo): string', $reposicionPhp);
});

$results[] = flus_run_test('technical panel access stays centralized and visible in nav', function (): void {
    $repoRoot = dirname(__DIR__);
    $authPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'auth.php';
    $tecnicoPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'tecnico.php';
    $navPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'nav.php';

    foreach ([$authPath, $tecnicoPath, $navPath] as $requiredPath) {
        if (!is_file($requiredPath)) {
            throw new RuntimeException('Missing file: ' . $requiredPath);
        }
    }

    $authPhp = (string)file_get_contents($authPath);
    $tecnicoPhp = (string)file_get_contents($tecnicoPath);
    $navPhp = (string)file_get_contents($navPath);

    flus_assert_contains('function user_can_access_technical_panel', $authPhp);
    flus_assert_contains('function require_technical_permission', $authPhp);
    flus_assert_contains('require_technical_permission();', $tecnicoPhp);
    flus_assert_contains('Estado actual', $tecnicoPhp);
    flus_assert_contains('Operacion tecnica', $tecnicoPhp);
    flus_assert_contains('user_can_access_technical_panel', $navPhp);
});
$results[] = flus_run_test('admin pages rely on bootstrap session startup', function (): void {
    $repoRoot = dirname(__DIR__);
    $pages = [
        'public/roles.php',
        'public/rol_guardar.php',
        'public/rol_permisos.php',
        'public/usuarios.php',
        'public/usuario_editar.php',
        'public/usuario_guardar.php',
        'public/usuario_nuevo.php',
        'public/tecnico.php',
        'public/diagnostico_download.php',
    ];

    foreach ($pages as $pageFile) {
        $pagePath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pageFile);
        if (!is_file($pagePath)) {
            throw new RuntimeException('Missing page: ' . $pageFile);
        }

        $pagePhp = (string)file_get_contents($pagePath);
        flus_assert_not_contains('session_start(', $pagePhp, $pageFile);
        flus_assert_not_contains('startSecureSession(', $pagePhp, $pageFile);
    }
});

$results[] = flus_run_test('ticket humanizes CC and digital payment labels consistently', function (): void {
    $repoRoot = dirname(__DIR__);
    $ticketPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'ticket.php');
    $ticketPublicoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'ticket_publico.php');

    flus_assert_contains("if (\$m === 'CC') return 'Cuenta Corriente';", $ticketPhp);
    flus_assert_contains("if (\$m === 'TRANSFERENCIA' || \$m === 'TRANSFER') return 'Transferencia';", $ticketPhp);
    flus_assert_contains("function humanize_ticket_medio_pago(string \$medio): string {", $ticketPublicoPhp);
    flus_assert_contains("'CC' => 'Cuenta Corriente',", $ticketPublicoPhp);
    flus_assert_contains("return implode(' + ', array_map('humanize_ticket_medio_pago', \$parts));", $ticketPublicoPhp);
});

$results[] = flus_run_test('promo actions rely on centralized API guards', function (): void {
    $repoRoot = dirname(__DIR__);
    $indexPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php');
    $promoActualizarPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'promo_actualizar.php');
    $promoEliminarPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'promo_eliminar.php');
    $promoObtenerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'promo_obtener.php');
    $promoProductosPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'promo_productos.php');

    flus_assert_contains("'promo_actualizar' => [", $indexPhp);
    flus_assert_contains("'promo_eliminar' => [", $indexPhp);
    flus_assert_contains("'promo_obtener' => [", $indexPhp);
    flus_assert_contains("'promo_productos' => [", $indexPhp);
    flus_assert_contains("flus_enforce_action_guard(\$action, \$body);", $indexPhp);

    foreach ([$promoActualizarPhp, $promoEliminarPhp, $promoObtenerPhp, $promoProductosPhp] as $php) {
        flus_assert_not_contains('require_login_json();', $php);
        flus_assert_not_contains('require_perm_json(', $php);
        flus_assert_not_contains('require_csrf_json(', $php);
    }
});

$results[] = flus_run_test('cuenta corriente actions salen del switch y usan guard central', function (): void {
    $repoRoot = dirname(__DIR__);
    $indexPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php');
    $buscarClientesPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'buscar_clientes_cc.php');
    $verificarCcPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'verificar_cc.php');

    flus_assert_contains("'buscar_clientes_cc' => [", $indexPhp);
    flus_assert_contains("'verificar_cc' => [", $indexPhp);
    flus_assert_contains("'any_permissions' => ['registrar_cargo_cc', 'registrar_pago_cc', 'ver_cuenta_corriente'],", $indexPhp);
    flus_assert_not_contains("case 'buscar_clientes_cc': {", $indexPhp);
    flus_assert_not_contains("case 'verificar_cc': {", $indexPhp);

    foreach ([$buscarClientesPhp, $verificarCcPhp] as $php) {
        flus_assert_not_contains('require_login_json();', $php);
        flus_assert_not_contains('require_perm_json(', $php);
        flus_assert_not_contains('require_any_perm_json(', $php);
    }

    flus_assert_contains("AND (nombre LIKE ? OR telefono LIKE ? OR cuit LIKE ?)", $buscarClientesPhp);
    flus_assert_contains("\$st->execute([\$like, \$like, \$like]);", $buscarClientesPhp);
    flus_assert_contains("ORDER BY cc_saldo DESC, nombre ASC", $buscarClientesPhp);
    flus_assert_contains("\$ccCtrl = new CuentaCorrienteController(\$pdo);", $verificarCcPhp);
    flus_assert_contains("'puede_autorizar' => \$puedeAutorizar,", $verificarCcPhp);
});

$results[] = flus_run_test('ventas y promos chicos salen del switch y usan action files', function (): void {
    $repoRoot = dirname(__DIR__);
    $indexPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php');
    $buscarProductoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'buscar_producto.php');
    $buscarProductosPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'buscar_productos.php');
    $listarPromosPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'listar_promos_activas.php');
    $calcularCarritoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'calcular_carrito.php');
    $terminalListPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'terminal_list.php');

    flus_assert_contains("'buscar_producto' => [", $indexPhp);
    flus_assert_contains("'buscar_productos' => [", $indexPhp);
    flus_assert_contains("'listar_promos_activas' => [", $indexPhp);
    flus_assert_contains("'calcular_carrito' => [", $indexPhp);
    flus_assert_contains("'terminal_list' => [", $indexPhp);
    flus_assert_not_contains("case 'buscar_producto': {", $indexPhp);
    flus_assert_not_contains("case 'buscar_productos': {", $indexPhp);
    flus_assert_not_contains("case 'listar_promos_activas': {", $indexPhp);
    flus_assert_not_contains("case 'calcular_carrito': {", $indexPhp);
    flus_assert_not_contains("case 'terminal_list': {", $indexPhp);

    foreach ([$buscarProductoPhp, $buscarProductosPhp, $listarPromosPhp, $calcularCarritoPhp, $terminalListPhp] as $php) {
        flus_assert_not_contains('require_login_json();', $php);
        flus_assert_not_contains('require_perm_json(', $php);
    }

    flus_assert_contains("WHERE codigo = :cod AND activo = 1", $buscarProductoPhp);
    flus_assert_contains("\$limit = max(1, min(\$limit, 20));", $buscarProductosPhp);
    flus_assert_contains("json_fail('buscar_productos SQL execute fallo: ' . (\$error[2] ?? 'sin detalle'), 500);", $buscarProductosPhp);
    flus_assert_contains("\$promos = obtenerPromosActivas(\$pdo);", $listarPromosPhp);
    flus_assert_contains("\$calc = calcular_totales_con_promos(\$srvItems, \$promos);", $calcularCarritoPhp);
    flus_assert_contains("'descuento_global' => round(\$descGlobalMonto, 2),", $calcularCarritoPhp);
    flus_assert_contains("'current_terminal_id' => \$currentTid,", $terminalListPhp);
});

$results[] = flus_run_test('terminal actions salen del switch y usan action files', function (): void {
    $repoRoot = dirname(__DIR__);
    $indexPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php');
    $terminalSelectPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'terminal_select.php');
    $terminalSwitchPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'terminal_switch.php');
    $terminalHeartbeatPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'terminal_heartbeat.php');
    $sessionHeartbeatPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'session_heartbeat.php');

    flus_assert_contains("'terminal_select' => [", $indexPhp);
    flus_assert_contains("'terminal_switch' => [", $indexPhp);
    flus_assert_contains("'terminal_heartbeat' => [", $indexPhp);
    flus_assert_contains("'session_heartbeat' => [", $indexPhp);
    flus_assert_not_contains("case 'terminal_select': {", $indexPhp);
    flus_assert_not_contains("case 'terminal_switch': {", $indexPhp);
    flus_assert_not_contains("case 'terminal_heartbeat': {", $indexPhp);
    flus_assert_not_contains("case 'session_heartbeat': {", $indexPhp);

    foreach ([$terminalSelectPhp, $terminalSwitchPhp, $terminalHeartbeatPhp, $sessionHeartbeatPhp] as $php) {
        flus_assert_not_contains('require_login_json();', $php);
        flus_assert_not_contains('require_csrf_json(', $php);
    }

    flus_assert_contains("terminal_locks_gc(\$pdo, \$ttl);", $terminalSelectPhp);
    flus_assert_contains("flus_session_update_selected_terminal(\$pdo, \$sid, \$requestedTerminalId);", $terminalSelectPhp);
    flus_assert_contains("terminal_set_cookie(\$newTid);", $terminalSwitchPhp);
    flus_assert_contains("\$res = terminal_lock_heartbeat(\$pdo, \$tid, \$uid, \$sid, \$ttl);", $terminalHeartbeatPhp);
    flus_assert_contains("json_ok(['session_id' => \$sid]);", $sessionHeartbeatPhp);
});

$results[] = flus_run_test('registrar venta delega logica interna a venta_api_lib', function (): void {
    $repoRoot = dirname(__DIR__);
    $indexPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php');
    $registrarVentaPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'registrar_venta.php');
    $ventaLibPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'venta_api_lib.php');

    flus_assert_contains("'registrar_venta' => [", $indexPhp);
    flus_assert_not_contains("case 'registrar_venta': {", $indexPhp);
    flus_assert_contains("require_once __DIR__ . '/../../src/venta_api_lib.php';", $indexPhp);
    flus_assert_contains('require_terminal_lock_json();', $registrarVentaPhp);
    flus_assert_not_contains('require_login_json();', $registrarVentaPhp);
    flus_assert_not_contains('require_csrf_json(', $registrarVentaPhp);
    flus_assert_contains("flus_venta_parse_request_inputs(\$body)", $registrarVentaPhp);
    flus_assert_contains("flus_venta_aggregate_items(\$itemsIn)", $registrarVentaPhp);
    flus_assert_contains("flus_venta_build_items_snapshot(", $registrarVentaPhp);
    flus_assert_contains("flus_venta_prepare_payment_data(", $registrarVentaPhp);
    flus_assert_contains("flus_venta_validate_cc_payment(", $registrarVentaPhp);
    flus_assert_contains("flus_venta_store_items_and_stock(", $registrarVentaPhp);
    flus_assert_contains('catch (FlusVentaDomainException $e)', $registrarVentaPhp);
    flus_assert_contains("json_fail('No se pudo registrar la venta.', 500, ['error_code' => 'INTERNAL_ERROR']);", $registrarVentaPhp);
    flus_assert_contains('final class FlusVentaDomainException extends RuntimeException', $ventaLibPhp);
    flus_assert_contains('function flus_venta_fail(string $message, string $errorCode = \'VALIDATION_ERROR\', int $statusCode = 422): never', $ventaLibPhp);
    flus_assert_contains("function flus_venta_build_items_snapshot(", $ventaLibPhp);
    flus_assert_contains("function flus_venta_register_cc_charge(", $ventaLibPhp);
    flus_assert_contains("function flus_venta_build_response(", $ventaLibPhp);
});

$results[] = flus_run_test('user legacy api endpoints share centralized bootstrap and csrf extraction', function (): void {
    $repoRoot = dirname(__DIR__);
    $usuarioEliminarPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'usuario_eliminar.php');
    $usuarioTogglePhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'usuario_toggle_estado.php');

    foreach ([$usuarioEliminarPhp, $usuarioTogglePhp] as $php) {
        flus_assert_contains("require_once __DIR__ . '/_bootstrap.php';", $php);
        flus_assert_contains('$input = api_read_json();', $php);
        flus_assert_contains('require_login_json();', $php);
        flus_assert_contains("require_perm_json('administrar_usuarios');", $php);
        flus_assert_contains("require_method_json('POST');", $php);
        flus_assert_contains('require_csrf_json($input);', $php);
        flus_assert_not_contains("require_once __DIR__ . '/../bootstrap.php';", $php);
        flus_assert_not_contains("require_once __DIR__ . '/_csrf_guard.php';", $php);
        flus_assert_not_contains("json_decode(file_get_contents('php://input'), true);", $php);
    }
});

$results[] = flus_run_test('permissions stay aligned across code, install, migrations and admin role', function (): void {
    $repoRoot = dirname(__DIR__);
    $publicPath = $repoRoot . DIRECTORY_SEPARATOR . 'public';
    $installPath = $repoRoot . DIRECTORY_SEPARATOR . 'install.sql';
    $migrationsPath = $repoRoot . DIRECTORY_SEPARATOR . 'migrations';

    foreach ([$publicPath, $installPath, $migrationsPath] as $requiredPath) {
        if (!file_exists($requiredPath)) {
            throw new RuntimeException('Missing file or directory: ' . $requiredPath);
        }
    }

    $codePerms = flus_collect_permission_slugs_from_public($publicPath);
    $installPerms = flus_collect_permission_slugs_from_sql_file($installPath);
    $migrationPerms = flus_collect_permission_slugs_from_migrations($migrationsPath);

    $pdo = flus_open_project_pdo($repoRoot);
    $dbPerms = flus_fetch_first_column($pdo, 'SELECT slug FROM permissions ORDER BY slug');
    $adminPerms = flus_fetch_first_column(
        $pdo,
        "SELECT p.slug
         FROM permissions p
         INNER JOIN role_permission rp ON rp.permission_id = p.id
         INNER JOIN roles r ON r.id = rp.role_id
         WHERE LOWER(r.slug) IN ('admin', 'administrador')
         ORDER BY p.slug"
    );

    $missingInInstall = array_values(array_diff($codePerms, $installPerms));
    $missingInMigrations = array_values(array_diff($codePerms, $migrationPerms));
    $missingInDb = array_values(array_diff($codePerms, $dbPerms));
    $missingInAdmin = array_values(array_diff($codePerms, $adminPerms));

    if ($missingInInstall !== [] || $missingInMigrations !== [] || $missingInDb !== [] || $missingInAdmin !== []) {
        throw new RuntimeException(
            'Permisos desalineados'
            . ' | code->install: ' . flus_format_slug_diff($missingInInstall)
            . ' | code->migrations: ' . flus_format_slug_diff($missingInMigrations)
            . ' | code->db: ' . flus_format_slug_diff($missingInDb)
            . ' | code->admin: ' . flus_format_slug_diff($missingInAdmin)
        );
    }
});

$results[] = flus_run_test('fiscal scaffold bootstrap loads cleanly', function (): void {
    $repoRoot = dirname(__DIR__);
    require_once $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Fiscal' . DIRECTORY_SEPARATOR . 'bootstrap.php';

    flus_assert_true(interface_exists('NotaCreditoService', false));
    flus_assert_true(interface_exists('FiscalRecoveryService', false));
    flus_assert_true(interface_exists('FacturaFiscalRepository', false));
    flus_assert_true(interface_exists('AnulacionFiscalCoordinator', false));
    flus_assert_true(class_exists('EmitirNotaCreditoCommand', false));
    flus_assert_true(class_exists('EmitirNotaCreditoResult', false));
    flus_assert_true(class_exists('RecoveryResult', false));
    flus_assert_true(class_exists('AnulacionFiscalOutcome', false));
    flus_assert_true(class_exists('PdoFacturaFiscalRepository', false));
    flus_assert_true(class_exists('StubNotaCreditoService', false));
    flus_assert_true(class_exists('StubFiscalRecoveryService', false));
    flus_assert_true(class_exists('StubAnulacionFiscalCoordinator', false));
    flus_assert_true(class_exists('ArcaNotaCreditoService', false));
    flus_assert_true(class_exists('DbAnulacionFiscalCoordinator', false));
});

$results[] = flus_run_test('facturacion arca degradation keeps availability criteria consistent', function (): void {
    flus_assert_true(
        flus_facturacion_arca_is_availability_error(
            "Error invocando WSAA: SOAP-ERROR: Parsing WSDL: Couldn't load from 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL'"
        )
    );
    flus_assert_false(flus_facturacion_arca_is_availability_error('[10016] Comprobante duplicado'));
    flus_assert_same(
        'No se puede emitir ahora porque ARCA no responde.',
        flus_facturacion_humanizar_error_arca(
            "Error invocando WSAA: SOAP-ERROR: Parsing WSDL: Couldn't load from 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL'"
        )
    );

    $pdo = flus_create_sqlite_memory_pdo('facturacion arca degradation keeps availability criteria consistent');
    $pdo->exec('CREATE TABLE app_config (k TEXT PRIMARY KEY, v TEXT)');
    $pdo->exec("INSERT INTO app_config (k, v) VALUES ('facturacion_habilitada', '0')");

    $estado = flus_facturacion_arca_status_current($pdo, 'demo', false);
    flus_assert_same('not_required', $estado['status']);
    flus_assert_true((bool)$estado['can_emit']);
});

$results[] = flus_run_test('EmitirNotaCreditoCommand normalizes partial items safely', function (): void {
    require_once dirname(__DIR__) . '/src/Fiscal/bootstrap.php';

    $cmd = EmitirNotaCreditoCommand::fromArray([
        'venta_id' => 10,
        'venta_anulacion_id' => 20,
        'usuario_id' => 30,
        'scope' => 'partial',
        'modo' => 'HOMO',
        'legacy_tolerance' => '0.07',
        'partial_items' => [
            ['item_id' => 5, 'cantidad' => '1.250'],
            ['item_id' => 5, 'cantidad' => '0.250'],
            ['item_id' => 9, 'cantidad' => '2'],
            ['item_id' => 0, 'cantidad' => '9'],
        ],
    ]);

    flus_assert_same('PARTIAL', $cmd->scope);
    flus_assert_same('homo', $cmd->modo);
    flus_assert_same(0.07, $cmd->legacyTolerance);
    flus_assert_same(2, count($cmd->partialItems));
    flus_assert_same(5, $cmd->partialItems[0]['item_id']);
    flus_assert_same(1.5, $cmd->partialItems[0]['cantidad']);
    flus_assert_same(9, $cmd->partialItems[1]['item_id']);
    flus_assert_same(2.0, $cmd->partialItems[1]['cantidad']);
});

$results[] = flus_run_test('facturacion desde venta entra por la capa unificada', function (): void {
    $repoRoot = dirname(__DIR__);
    $emitirPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_emitir.php');
    $libPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');

    flus_assert_contains('crearFacturaDesdeVenta(', $emitirPhp);
    flus_assert_contains('flus_facturacion_asegurar_registro_desde_venta', $libPhp);
    flus_assert_contains('flus_facturacion_procesar_factura_registrada', $libPhp);

    $bodyStart = strpos($libPhp, 'function flus_facturacion_emitir_desde_venta');
    flus_assert_true($bodyStart !== false, 'No se encontró flus_facturacion_emitir_desde_venta');
    $body = substr($libPhp, $bodyStart, 1200);
    flus_assert_contains('flus_facturacion_asegurar_registro_desde_venta', $body);
    flus_assert_contains('flus_facturacion_procesar_factura_registrada', $body);
});

$results[] = flus_run_test('facturacion manual usa la misma capa y reusa request_uid', function (): void {
    $repoRoot = dirname(__DIR__);
    $manualPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_manual.php');
    $libPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');

    flus_assert_contains('crearFacturaManual([', $manualPhp);
    flus_assert_contains('findFacturaByRequestUid', $libPhp);
    flus_assert_contains('flus_facturacion_request_uid_manual', $libPhp);
    flus_assert_true(
        strpos($libPhp, 'findFacturaByRequestUid') < strpos($libPhp, 'flus_facturacion_crear_venta_manual'),
        'La búsqueda idempotente debería ocurrir antes de crear una nueva venta manual.'
    );

    $uidA = flus_facturacion_request_uid_manual(10, [
        ['descripcion' => 'Prod A', 'cantidad' => 1, 'precio' => 100, 'iva_porcentaje' => 21],
    ], ['nota' => 'nota original', 'medio_pago' => 'EFECTIVO'], ['concepto' => 1]);
    $uidB = flus_facturacion_request_uid_manual(10, [
        ['descripcion' => 'Prod A', 'cantidad' => 1, 'precio' => 100, 'iva_porcentaje' => 21],
    ], ['nota' => 'nota cambiada', 'medio_pago' => 'CTA CTE'], ['concepto' => 3]);
    $uidC = flus_facturacion_request_uid_manual(10, [
        ['descripcion' => 'Prod A', 'cantidad' => 2, 'precio' => 100, 'iva_porcentaje' => 21],
    ], ['nota' => 'nota original'], ['concepto' => 1]);

    flus_assert_same($uidA, $uidB);
    flus_assert_false($uidA === $uidC);
    flus_assert_contains('name="request_uid"', $manualPhp);
});

$results[] = flus_run_test('fase 2A manual crea documento base e items sin duplicar request_uid', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    $items = flus_facturacion_normalize_manual_items([
        ['codigo' => 'A1', 'descripcion' => 'Prod A', 'cantidad' => 1, 'precio' => 100, 'iva_porcentaje' => 21],
        ['codigo' => 'B2', 'descripcion' => 'Prod B', 'cantidad' => 2, 'precio' => 50, 'iva_porcentaje' => 21],
    ]);

    $documentoId = flus_facturacion_documento_crear_manual($pdo, 25, $items, ['nota' => 'primer intento'], [
        'request_uid' => 'req-f2a-1',
    ]);
    $documentoIdRetry = flus_facturacion_documento_crear_manual($pdo, 25, $items, ['nota' => 'segundo intento'], [
        'request_uid' => 'req-f2a-1',
    ]);

    flus_assert_same($documentoId, $documentoIdRetry);
    flus_assert_same(1, count($pdo->rows('documentos_comerciales')));
    flus_assert_same(2, count($pdo->rows('documento_items')));

    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    flus_assert_true(is_array($documento), 'El documento manual debería existir.');
    flus_assert_same('req-f2a-1', (string)($documento['request_uid'] ?? ''));
    flus_assert_same(200.0, (float)($documento['total'] ?? 0));
});

$results[] = flus_run_test('fase 2A retry reutiliza la misma venta legacy enlazada al documento', function (): void {
    $_SESSION = [];

    $pdo = flus_test_facturacion_fake_pdo();
    $items = flus_facturacion_normalize_manual_items([
        ['codigo' => 'A1', 'descripcion' => 'Prod A', 'cantidad' => 1, 'precio' => 100, 'iva_porcentaje' => 21],
    ]);
    $requestUid = 'req-f2a-venta-1';

    $documentoId = flus_facturacion_documento_crear_manual($pdo, 25, $items, ['nota' => 'base'], [
        'request_uid' => $requestUid,
    ]);
    flus_facturacion_documento_actualizar_venta($pdo, $documentoId, 501);
    flus_facturacion_manual_retry_state_guardar($requestUid, 501, 25, $items, 'PENDIENTE_ENVIO', null);

    $base = flus_facturacion_manual_resolver_base_existente($pdo, new FlusFakeFacturaRepository(), $requestUid, 25, $items);
    flus_assert_same($documentoId, (int)($base['documento_id'] ?? 0));
    flus_assert_same(501, (int)($base['venta_id'] ?? 0));

    flus_facturacion_documento_actualizar_venta($pdo, $documentoId, 999);
    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    flus_assert_same(501, (int)($documento['venta_id'] ?? 0));
});

$results[] = flus_run_test('fase 2A retry no toma otra venta si el request_uid no coincide', function (): void {
    $_SESSION = [];

    $pdo = flus_test_facturacion_fake_pdo();
    $items = flus_facturacion_normalize_manual_items([
        ['codigo' => 'A1', 'descripcion' => 'Prod A', 'cantidad' => 1, 'precio' => 100, 'iva_porcentaje' => 21],
    ]);

    flus_facturacion_manual_retry_state_guardar('req-distinto', 777, 25, $items, 'PENDIENTE_ENVIO', null);
    $base = flus_facturacion_manual_resolver_base_existente($pdo, new FlusFakeFacturaRepository(), 'req-real', 25, $items);

    flus_assert_same(0, (int)($base['venta_id'] ?? 0));
    flus_assert_same(0, (int)($base['documento_id'] ?? 0));
});

$results[] = flus_run_test('fase 2A persiste documento_id en facturas via repository', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    $pdo->seedTable('facturas', ['id', 'venta_id', 'documento_id', 'fiscal_request_uid', 'estado_fiscal', 'naturaleza'], [[
        'id' => 9001,
        'venta_id' => 501,
        'documento_id' => null,
        'fiscal_request_uid' => 'req-f2a-factura',
        'estado_fiscal' => 'PENDIENTE_ENVIO',
        'naturaleza' => 'FACTURA',
    ]]);

    $repo = new PdoFacturaFiscalRepository($pdo);
    $repo->updateFactura(9001, ['documento_id' => 77]);
    $factura = $repo->findFacturaById(9001);

    flus_assert_true(is_array($factura), 'La factura fake debería existir.');
    flus_assert_same(77, (int)($factura['documento_id'] ?? 0));
});

$results[] = flus_run_test('fase 2A factura_ver puede reconstruir detalle desde documento_items', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    $items = flus_facturacion_normalize_manual_items([
        ['codigo' => 'A1', 'descripcion' => 'Prod A', 'cantidad' => 1, 'precio' => 100, 'iva_porcentaje' => 21],
        ['codigo' => 'B2', 'descripcion' => 'Prod B', 'cantidad' => 2, 'precio' => 50, 'iva_porcentaje' => 21],
    ]);
    $documentoId = flus_facturacion_documento_crear_manual($pdo, 25, $items, ['nota' => 'detalle'], [
        'request_uid' => 'req-f2a-detalle',
    ]);

    $pdo->seedTable('factura_manual_items', [
        'id', 'venta_id', 'codigo', 'descripcion', 'cantidad', 'precio_unitario', 'subtotal', 'iva_porcentaje', 'created_at'
    ], [[
        'id' => 1,
        'venta_id' => 501,
        'codigo' => 'LEG',
        'descripcion' => 'Legacy',
        'cantidad' => 1,
        'precio_unitario' => 999,
        'subtotal' => 999,
        'iva_porcentaje' => 21,
        'created_at' => date('Y-m-d H:i:s'),
    ]]);

    $rows = flus_facturacion_factura_detalle_items_fetch($pdo, [
        'id' => 9001,
        'documento_id' => $documentoId,
        'venta_id' => 501,
    ]);

    flus_assert_same(2, count($rows));
    flus_assert_same('Prod A', (string)($rows[0]['nombre'] ?? ''));
    flus_assert_same('Prod B', (string)($rows[1]['nombre'] ?? ''));
});


$results[] = flus_run_test('fase 3 registra cobranza base desde pago real de venta sin tocar deuda CC', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();

    $cobranzaId = flus_cobranzas_register_sale_payment($pdo, [
        'venta_id' => 701,
        'cliente_id' => null,
        'caja_id' => 9,
        'medio_pago' => 'EFECTIVO',
        'monto' => 1500.00,
        'linea' => 1,
        'created_by' => 3,
    ]);

    flus_assert_true($cobranzaId > 0, 'La cobranza de venta debería haberse creado.');
    flus_assert_same(1, count($pdo->rows('cobranzas')));
    flus_assert_same(1, count($pdo->rows('cobranza_aplicaciones')));

    $row = $pdo->rows('cobranzas')[0] ?? [];
    flus_assert_same('VENTA', (string)($row['origen'] ?? ''));
    flus_assert_same(701, (int)($row['venta_id'] ?? 0));
    flus_assert_same('EFECTIVO', (string)($row['medio_pago'] ?? ''));
    flus_assert_same(1500.0, (float)($row['importe_total'] ?? 0));

    $app = $pdo->rows('cobranza_aplicaciones')[0] ?? [];
    flus_assert_same('VENTA', (string)($app['tipo_aplicacion'] ?? ''));
    flus_assert_same(701, (int)($app['venta_id'] ?? 0));
    flus_assert_same(1500.0, (float)($app['monto'] ?? 0));
});

$results[] = flus_run_test('fase 3 retry reutiliza misma cobranza por external_key de venta', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();

    $a = flus_cobranzas_register_sale_payment($pdo, [
        'venta_id' => 702,
        'medio_pago' => 'DEBITO',
        'monto' => 800.00,
        'linea' => 1,
        'created_by' => 4,
    ]);
    $b = flus_cobranzas_register_sale_payment($pdo, [
        'venta_id' => 702,
        'medio_pago' => 'DEBITO',
        'monto' => 800.00,
        'linea' => 1,
        'created_by' => 4,
    ]);

    flus_assert_same($a, $b);
    flus_assert_same(1, count($pdo->rows('cobranzas')));
    flus_assert_same(1, count($pdo->rows('cobranza_aplicaciones')));
});

$results[] = flus_run_test('fase 3 registra pago CC como cobranza real separada de la venta', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();

    $cobranzaId = flus_cobranzas_register_cc_payment($pdo, [
        'cliente_id' => 55,
        'cc_movimiento_id' => 9901,
        'caja_id' => 11,
        'caja_movimiento_id' => 123,
        'medio_pago' => 'TRANSFERENCIA',
        'monto' => 450.00,
        'created_by' => 8,
    ]);

    flus_assert_true($cobranzaId > 0, 'El pago CC debería generar cobranza base.');
    flus_assert_same(1, count($pdo->rows('cobranzas')));
    flus_assert_same('CC_PAGO', (string)(($pdo->rows('cobranzas')[0] ?? [])['origen'] ?? ''));
    flus_assert_same(9901, (int)(($pdo->rows('cobranzas')[0] ?? [])['cc_movimiento_id'] ?? 0));
    flus_assert_same(123, (int)(($pdo->rows('cobranzas')[0] ?? [])['caja_movimiento_id'] ?? 0));
    flus_assert_same(1, count($pdo->rows('cobranza_aplicaciones')));
    flus_assert_same('CC_MOVIMIENTO', (string)(($pdo->rows('cobranza_aplicaciones')[0] ?? [])['tipo_aplicacion'] ?? ''));
});

$results[] = flus_run_test('fase 3 enlaza cobranzas de venta con factura y documento sin duplicar aplicaciones', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();

    $cobranzaId = flus_cobranzas_register_sale_payment($pdo, [
        'venta_id' => 703,
        'medio_pago' => 'MP',
        'monto' => 999.99,
        'linea' => 1,
        'created_by' => 4,
    ]);
    flus_assert_true($cobranzaId > 0);

    flus_cobranzas_link_factura_from_sale($pdo, 703, 5001, 77);
    flus_cobranzas_link_factura_from_sale($pdo, 703, 5001, 77);

    $apps = flus_cobranzas_fetch_by_factura($pdo, 5001);
    flus_assert_same(1, count($apps));
    flus_assert_same(703, (int)($apps[0]['venta_id'] ?? 0));
    flus_assert_same(77, (int)($apps[0]['documento_id'] ?? 0));
    flus_assert_same(5001, (int)($apps[0]['factura_id'] ?? 0));
    flus_assert_same('MP', (string)($apps[0]['medio_pago'] ?? ''));
});


$results[] = flus_run_test('fase 4 genera recibo documental minimo para cobranza CC', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();

    $cobranzaId = flus_cobranzas_register_cc_payment($pdo, [
        'cliente_id' => 55,
        'cc_movimiento_id' => 9902,
        'medio_pago' => 'EFECTIVO',
        'monto' => 300.00,
        'created_by' => 8,
    ]);

    $recibo = flus_cobranzas_attach_receipt_to_cobranza($pdo, $cobranzaId, [
        'cliente_id' => 55,
        'cc_movimiento_id' => 9902,
        'monto' => 300.00,
    ]);

    flus_assert_true($cobranzaId > 0, 'La cobranza base debe existir antes de generar recibo.');
    flus_assert_true((int)($recibo['recibo_documento_id'] ?? 0) > 0, 'Debe crearse un documento RECIBO.');
    flus_assert_same(1, count($pdo->rows('documentos_comerciales')));
    flus_assert_same('RECIBO', (string)(($pdo->rows('documentos_comerciales')[0] ?? [])['tipo_documento'] ?? ''));
    flus_assert_same((int)($recibo['recibo_documento_id'] ?? 0), (int)(($pdo->rows('cobranzas')[0] ?? [])['recibo_documento_id'] ?? 0));
    flus_assert_same(1, count($pdo->rows('recibo_aplicaciones')));
    flus_assert_same('SALDO_CC', (string)(($pdo->rows('recibo_aplicaciones')[0] ?? [])['tipo_aplicacion'] ?? ''));
    flus_assert_same(9902, (int)(($pdo->rows('recibo_aplicaciones')[0] ?? [])['cc_movimiento_id'] ?? 0));
});

$results[] = flus_run_test('fase 4 vincula recibo con factura y documento sin romper idempotencia', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    $pdo->seedTable('facturas', ['id', 'venta_id', 'documento_id', 'cliente_id', 'fiscal_request_uid', 'estado_fiscal', 'naturaleza'], [[
        'id' => 5009,
        'venta_id' => 0,
        'documento_id' => 77,
        'cliente_id' => 55,
        'fiscal_request_uid' => 'req-f4',
        'estado_fiscal' => 'AUTORIZADA',
        'naturaleza' => 'FACTURA',
    ]]);
    $pdo->seedTable('documentos_comerciales', [
        'id', 'request_uid', 'tipo_documento', 'origen', 'estado', 'cliente_id', 'venta_id',
        'nota', 'medio_pago', 'total', 'created_at', 'updated_at'
    ], [[
        'id' => 77,
        'request_uid' => 'doc-77',
        'tipo_documento' => 'FACTURA_MANUAL',
        'origen' => 'MANUAL',
        'estado' => 'EMITIDO',
        'cliente_id' => 55,
        'venta_id' => null,
        'nota' => 'Factura manual',
        'medio_pago' => 'CTA CTE',
        'total' => 900.00,
        'created_at' => '2026-03-24 10:00:00',
        'updated_at' => '2026-03-24 10:00:00',
    ]]);

    $cobranzaId = flus_cobranzas_register_cc_payment($pdo, [
        'cliente_id' => 55,
        'cc_movimiento_id' => 9903,
        'medio_pago' => 'TRANSFERENCIA',
        'monto' => 250.00,
        'created_by' => 8,
    ]);

    $a = flus_cobranzas_attach_receipt_to_cobranza($pdo, $cobranzaId, [
        'cliente_id' => 55,
        'cc_movimiento_id' => 9903,
        'documento_id' => 77,
        'monto' => 250.00,
    ]);
    $b = flus_cobranzas_attach_receipt_to_cobranza($pdo, $cobranzaId, [
        'cliente_id' => 55,
        'cc_movimiento_id' => 9903,
        'documento_id' => 77,
        'monto' => 250.00,
    ]);

    flus_assert_same((int)($a['recibo_documento_id'] ?? 0), (int)($b['recibo_documento_id'] ?? 0));
    flus_assert_same(1, count($pdo->rows('recibo_aplicaciones')));
    flus_assert_same('DOCUMENTO', (string)(($pdo->rows('recibo_aplicaciones')[0] ?? [])['tipo_aplicacion'] ?? ''));

    flus_cobranzas_link_receipt_factura_from_documento($pdo, 77, 5009);
    $rows = flus_cobranzas_fetch_receipts_by_factura($pdo, 5009, 77);

    flus_assert_same(1, count($rows));
    flus_assert_same(5009, (int)($rows[0]['factura_id'] ?? 0));
    flus_assert_same(77, (int)($rows[0]['documento_id'] ?? 0));
    flus_assert_same('TRANSFERENCIA', (string)($rows[0]['medio_pago'] ?? ''));
    flus_assert_same(250.0, (float)($rows[0]['monto_aplicado'] ?? 0));
});


$results[] = flus_run_test('fase 4 endurece coherencia cliente cruzado y factura-documento', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    $pdo->seedTable('documentos_comerciales', [
        'id', 'request_uid', 'tipo_documento', 'origen', 'estado', 'cliente_id', 'venta_id',
        'nota', 'medio_pago', 'total', 'created_at', 'updated_at'
    ], [
        ['id' => 77, 'request_uid' => 'doc-77', 'tipo_documento' => 'FACTURA_MANUAL', 'origen' => 'MANUAL', 'estado' => 'EMITIDO', 'cliente_id' => 77, 'venta_id' => 0, 'nota' => 'Doc 77', 'medio_pago' => 'CC', 'total' => 500.00, 'created_at' => '2026-03-24 10:00:00', 'updated_at' => '2026-03-24 10:00:00'],
        ['id' => 88, 'request_uid' => 'doc-88', 'tipo_documento' => 'FACTURA_MANUAL', 'origen' => 'MANUAL', 'estado' => 'EMITIDO', 'cliente_id' => 88, 'venta_id' => 0, 'nota' => 'Doc 88', 'medio_pago' => 'CC', 'total' => 500.00, 'created_at' => '2026-03-24 10:00:00', 'updated_at' => '2026-03-24 10:00:00'],
    ]);
    $pdo->seedTable('facturas', [
        'id', 'venta_id', 'documento_id', 'cliente_id', 'fiscal_request_uid', 'estado_fiscal', 'naturaleza'
    ], [
        ['id' => 5001, 'venta_id' => 0, 'documento_id' => 77, 'cliente_id' => 77, 'fiscal_request_uid' => 'fac-5001', 'estado_fiscal' => 'NO_APLICA', 'naturaleza' => 'FACTURA'],
        ['id' => 5002, 'venta_id' => 0, 'documento_id' => 88, 'cliente_id' => 88, 'fiscal_request_uid' => 'fac-5002', 'estado_fiscal' => 'NO_APLICA', 'naturaleza' => 'FACTURA'],
    ]);

    $crossClient = flus_cobranzas_resolve_receipt_target($pdo, 77, null, 88);
    flus_assert_false((bool)($crossClient['ok'] ?? false));

    $mismatch = flus_cobranzas_resolve_receipt_target($pdo, 77, 5001, 88);
    flus_assert_false((bool)($mismatch['ok'] ?? false));

    $ok = flus_cobranzas_resolve_receipt_target($pdo, 77, 5001, 77);
    flus_assert_true((bool)($ok['ok'] ?? false));
    flus_assert_same('FACTURA', (string)($ok['tipo_aplicacion'] ?? ''));
});

$results[] = flus_run_test('fase 4 no permite dos aplicaciones distintas para la misma cobranza', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    $pdo->seedTable('documentos_comerciales', [
        'id', 'request_uid', 'tipo_documento', 'origen', 'estado', 'cliente_id', 'venta_id',
        'nota', 'medio_pago', 'total', 'created_at', 'updated_at'
    ], [
        ['id' => 77, 'request_uid' => 'doc-77', 'tipo_documento' => 'FACTURA_MANUAL', 'origen' => 'MANUAL', 'estado' => 'EMITIDO', 'cliente_id' => 77, 'venta_id' => 0, 'nota' => 'Doc 77', 'medio_pago' => 'CC', 'total' => 500.00, 'created_at' => '2026-03-24 10:00:00', 'updated_at' => '2026-03-24 10:00:00'],
    ]);
    $cobranzaId = flus_cobranzas_register_cc_payment($pdo, [
        'cliente_id' => 77,
        'cc_movimiento_id' => 9904,
        'medio_pago' => 'EFECTIVO',
        'monto' => 300.00,
    ]);

    $a = flus_cobranzas_attach_receipt_to_cobranza($pdo, $cobranzaId, [
        'cliente_id' => 77,
        'cc_movimiento_id' => 9904,
        'documento_id' => 77,
        'monto' => 150.00,
    ]);
    flus_assert_true((int)($a['recibo_aplicacion_id'] ?? 0) > 0);

    $thrown = false;
    try {
        flus_cobranzas_attach_receipt_to_cobranza($pdo, $cobranzaId, [
            'cliente_id' => 77,
            'cc_movimiento_id' => 9904,
            'monto' => 300.00,
        ]);
    } catch (RuntimeException $e) {
        $thrown = true;
    }
    flus_assert_true($thrown, 'Debe rechazar una segunda aplicacion distinta para la misma cobranza.');
    flus_assert_same(1, count($pdo->rows('recibo_aplicaciones')));
});

$results[] = flus_run_test('fase 4 controller evita duplicar pago por request_uid y reusa cobranza-recibo', function (): void {
    $pdo = flus_test_cc_controller_fake_pdo();
    $controller = new CuentaCorrienteController($pdo);

    $first = $controller->registrarPago(77, 200.00, 'EFECTIVO', 9, 'ref-1', 'Pago duplicable', [
        'request_uid' => 'rq-cc-1',
    ]);
    $second = $controller->registrarPago(77, 200.00, 'EFECTIVO', 9, 'ref-1', 'Pago duplicable', [
        'request_uid' => 'rq-cc-1',
    ]);

    flus_assert_true((bool)($first['success'] ?? false));
    flus_assert_true((bool)($second['success'] ?? false));
    flus_assert_same((int)($first['movimiento_id'] ?? 0), (int)($second['movimiento_id'] ?? 0));
    flus_assert_same((int)($first['cobranza_id'] ?? 0), (int)($second['cobranza_id'] ?? 0));
    flus_assert_same((int)($first['recibo_documento_id'] ?? 0), (int)($second['recibo_documento_id'] ?? 0));
    flus_assert_same(1, count($pdo->rows('cuenta_corriente_movimientos')));
    flus_assert_same(1, count($pdo->rows('cobranzas')));
    flus_assert_same(1, count($pdo->rows('recibo_aplicaciones')));
});

$results[] = flus_run_test('fase 4 controller detecta doble submit reciente sin request_uid y no duplica recibo', function (): void {
    $pdo = flus_test_cc_controller_fake_pdo();
    $controller = new CuentaCorrienteController($pdo);

    $first = $controller->registrarPago(77, 180.00, 'EFECTIVO', 9, 'ref-dup', 'Pago doble click', []);
    $second = $controller->registrarPago(77, 180.00, 'EFECTIVO', 9, 'ref-dup', 'Pago doble click', []);

    flus_assert_true((bool)($first['success'] ?? false));
    flus_assert_true((bool)($second['success'] ?? false));
    flus_assert_true((bool)($second['duplicate_guard'] ?? false));
    flus_assert_same((int)($first['movimiento_id'] ?? 0), (int)($second['movimiento_id'] ?? 0));
    flus_assert_same(1, count($pdo->rows('cuenta_corriente_movimientos')));
    flus_assert_same(1, count($pdo->rows('cobranzas')));
    flus_assert_same(1, count($pdo->rows('recibo_aplicaciones')));
});

$results[] = flus_run_test('cuenta corriente no permite reversar una reversa', function (): void {
    $pdo = flus_test_cc_controller_fake_pdo();
    flus_seed_cc_movimientos($pdo, [
        [
            'id' => 501,
            'cliente_id' => 77,
            'tipo' => 'REVERSA',
            'estado' => 'ACTIVO',
            'monto' => 100.00,
            'saldo_anterior' => 200.00,
            'saldo_posterior' => 100.00,
            'reversa_de_id' => 500,
            'concepto' => 'REVERSA: test',
            'created_by' => 9,
            'created_at' => '2026-03-24 10:00:00',
        ],
    ]);
    $controller = new CuentaCorrienteController($pdo);

    $result = $controller->reversarMovimiento(501, 'Reversa invalida', 9);

    flus_assert_false((bool)($result['success'] ?? false));
    flus_assert_same('No se puede reversar una reversa', (string)($result['error'] ?? ''));
    flus_assert_same(1, count($pdo->rows('cuenta_corriente_movimientos')));
});

$results[] = flus_run_test('cuenta corriente no permite reversar dos veces el mismo movimiento', function (): void {
    $pdo = flus_test_cc_controller_fake_pdo();
    flus_seed_cc_movimientos($pdo, [
        [
            'id' => 601,
            'cliente_id' => 77,
            'tipo' => 'PAGO',
            'estado' => 'ACTIVO',
            'monto' => 120.00,
            'saldo_anterior' => 1020.00,
            'saldo_posterior' => 900.00,
            'medio_pago' => 'EFECTIVO',
            'concepto' => 'Pago ya reversado',
            'created_by' => 9,
            'created_at' => '2026-03-24 10:00:00',
        ],
        [
            'id' => 602,
            'cliente_id' => 77,
            'tipo' => 'REVERSA',
            'estado' => 'ACTIVO',
            'monto' => 120.00,
            'saldo_anterior' => 900.00,
            'saldo_posterior' => 1020.00,
            'reversa_de_id' => 601,
            'concepto' => 'REVERSA: previa',
            'created_by' => 9,
            'created_at' => '2026-03-24 10:05:00',
        ],
    ]);
    $controller = new CuentaCorrienteController($pdo);

    $result = $controller->reversarMovimiento(601, 'Segunda reversa', 9);

    flus_assert_false((bool)($result['success'] ?? false));
    flus_assert_same('Este movimiento ya fue reversado', (string)($result['error'] ?? ''));
    flus_assert_same(2, count($pdo->rows('cuenta_corriente_movimientos')));
});

$results[] = flus_run_test('cuenta corriente reversa un pago y recalcula ultimo pago', function (): void {
    $pdo = flus_test_cc_controller_fake_pdo();
    $pdo->seedTable('clientes', [
        'id', 'nombre', 'cc_habilitado', 'cc_saldo', 'cc_fecha_ultimo_pago'
    ], [
        ['id' => 77, 'nombre' => 'Cliente Demo', 'cc_habilitado' => 1, 'cc_saldo' => 150.00, 'cc_fecha_ultimo_pago' => '2026-03-24'],
    ]);
    flus_seed_cc_movimientos($pdo, [
        [
            'id' => 701,
            'cliente_id' => 77,
            'tipo' => 'PAGO',
            'estado' => 'ACTIVO',
            'monto' => 200.00,
            'saldo_anterior' => 350.00,
            'saldo_posterior' => 150.00,
            'medio_pago' => 'EFECTIVO',
            'concepto' => 'Pago reciente',
            'created_by' => 9,
            'created_at' => '2026-03-24 15:00:00',
        ],
        [
            'id' => 702,
            'cliente_id' => 77,
            'tipo' => 'PAGO',
            'estado' => 'ACTIVO',
            'monto' => 50.00,
            'saldo_anterior' => 400.00,
            'saldo_posterior' => 350.00,
            'medio_pago' => 'EFECTIVO',
            'concepto' => 'Pago anterior',
            'created_by' => 9,
            'created_at' => '2026-03-10 09:00:00',
        ],
    ]);
    $controller = new CuentaCorrienteController($pdo);

    $result = $controller->reversarMovimiento(701, 'Anular pago', 9);
    $movimientos = $pdo->rows('cuenta_corriente_movimientos');
    $original = flus_find_row_by_id($movimientos, 701);
    $reversa = flus_find_row_by_id($movimientos, (int)($result['reversa_id'] ?? 0));
    $cliente = flus_find_row_by_id($pdo->rows('clientes'), 77);

    flus_assert_true((bool)($result['success'] ?? false));
    flus_assert_true(array_key_exists('success', $result));
    flus_assert_true(array_key_exists('reversa_id', $result));
    flus_assert_true(array_key_exists('saldo_anterior', $result));
    flus_assert_true(array_key_exists('saldo_posterior', $result));
    flus_assert_same(150.00, (float)($result['saldo_anterior'] ?? 0));
    flus_assert_same(350.00, (float)($result['saldo_posterior'] ?? 0));
    flus_assert_same('ANULADO', (string)($original['estado'] ?? ''));
    flus_assert_same('REVERSA', (string)($reversa['tipo'] ?? ''));
    flus_assert_same('ACTIVO', (string)($reversa['estado'] ?? ''));
    flus_assert_same(701, (int)($reversa['reversa_de_id'] ?? 0));
    flus_assert_same(350.00, round((float)($cliente['cc_saldo'] ?? 0), 2));
    flus_assert_same('2026-03-10', (string)($cliente['cc_fecha_ultimo_pago'] ?? ''));
});

$results[] = flus_run_test('cuenta corriente reversa un cargo en el sentido correcto', function (): void {
    $pdo = flus_test_cc_controller_fake_pdo();
    $pdo->seedTable('clientes', [
        'id', 'nombre', 'cc_habilitado', 'cc_saldo', 'cc_fecha_ultimo_pago'
    ], [
        ['id' => 77, 'nombre' => 'Cliente Demo', 'cc_habilitado' => 1, 'cc_saldo' => 320.00, 'cc_fecha_ultimo_pago' => '2026-03-01'],
    ]);
    flus_seed_cc_movimientos($pdo, [
        [
            'id' => 801,
            'cliente_id' => 77,
            'tipo' => 'CARGO',
            'estado' => 'ACTIVO',
            'monto' => 120.00,
            'saldo_anterior' => 200.00,
            'saldo_posterior' => 320.00,
            'concepto' => 'Cargo demo',
            'created_by' => 9,
            'created_at' => '2026-03-24 10:00:00',
        ],
    ]);
    $controller = new CuentaCorrienteController($pdo);

    $result = $controller->reversarMovimiento(801, 'Anular cargo', 9);
    $movimientos = $pdo->rows('cuenta_corriente_movimientos');
    $original = flus_find_row_by_id($movimientos, 801);
    $reversa = flus_find_row_by_id($movimientos, (int)($result['reversa_id'] ?? 0));
    $cliente = flus_find_row_by_id($pdo->rows('clientes'), 77);

    flus_assert_true((bool)($result['success'] ?? false));
    flus_assert_same(320.00, (float)($result['saldo_anterior'] ?? 0));
    flus_assert_same(200.00, (float)($result['saldo_posterior'] ?? 0));
    flus_assert_same('ANULADO', (string)($original['estado'] ?? ''));
    flus_assert_same('REVERSA', (string)($reversa['tipo'] ?? ''));
    flus_assert_same('ACTIVO', (string)($reversa['estado'] ?? ''));
    flus_assert_same(801, (int)($reversa['reversa_de_id'] ?? 0));
    flus_assert_same(200.00, round((float)($cliente['cc_saldo'] ?? 0), 2));
});

$results[] = flus_run_test('cuenta corriente reversa exitosa mantiene claves de respuesta', function (): void {
    $pdo = flus_test_cc_controller_fake_pdo();
    $pdo->seedTable('clientes', [
        'id', 'nombre', 'cc_habilitado', 'cc_saldo', 'cc_fecha_ultimo_pago'
    ], [
        ['id' => 77, 'nombre' => 'Cliente Demo', 'cc_habilitado' => 1, 'cc_saldo' => 500.00, 'cc_fecha_ultimo_pago' => '2026-03-01'],
    ]);
    flus_seed_cc_movimientos($pdo, [
        [
            'id' => 901,
            'cliente_id' => 77,
            'tipo' => 'CARGO',
            'estado' => 'ACTIVO',
            'monto' => 100.00,
            'saldo_anterior' => 400.00,
            'saldo_posterior' => 500.00,
            'concepto' => 'Cargo contrato',
            'created_by' => 9,
            'created_at' => '2026-03-24 10:00:00',
        ],
    ]);
    $controller = new CuentaCorrienteController($pdo);

    $result = $controller->reversarMovimiento(901, 'Anular cargo contrato', 9);

    flus_assert_true((bool)($result['success'] ?? false));
    flus_assert_true(array_key_exists('success', $result));
    flus_assert_true(array_key_exists('reversa_id', $result));
    flus_assert_true(array_key_exists('saldo_anterior', $result));
    flus_assert_true(array_key_exists('saldo_posterior', $result));
});

$results[] = flus_run_test('facturacion panel delega lectura y export a helper dedicado', function (): void {
    $repoRoot = dirname(__DIR__);
    $panelHelper = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_panel_lib.php');
    $facturacionPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion.php');

    flus_assert_contains("function flus_facturacion_panel_read", $panelHelper);
    flus_assert_contains("function flus_facturacion_panel_export_rows", $panelHelper);
    flus_assert_contains("require_once __DIR__ . '/../src/facturacion_panel_lib.php';", $facturacionPhp);
    flus_assert_contains("\$panel = flus_facturacion_panel_read(\$pdo", $facturacionPhp);
    flus_assert_contains("\$exportRows = flus_facturacion_panel_export_rows(\$pdo, \$panel['plan']);", $facturacionPhp);
    flus_assert_not_contains('$sqlCount = "', $facturacionPhp);
    flus_assert_not_contains('$sqlStats = "', $facturacionPhp);
    flus_assert_not_contains('$sqlList = "', $facturacionPhp);
    flus_assert_contains("['Fecha', 'Tipo', 'Punto de venta', 'Numero', 'Cliente', 'CUIT', 'Total', 'Estado', 'Estado fiscal', 'Venta', 'CAE', 'CAE vto', 'Modo']", $facturacionPhp);
});

$results[] = flus_run_test('factura_ver delega hidratacion a helper dedicado', function (): void {
    $repoRoot = dirname(__DIR__);
    $viewHelper = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'factura_view_lib.php');
    $facturaVerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_ver.php');

    flus_assert_contains('function flus_factura_view_load(PDO $pdo, int $facturaId): ?array', $viewHelper);
    flus_assert_contains('function flus_factura_view_fetch_factura(PDO $pdo, int $facturaId): ?array', $viewHelper);
    flus_assert_contains("require_once __DIR__ . '/../src/factura_view_lib.php';", $facturaVerPhp);
    flus_assert_contains('$viewData = flus_factura_view_load($pdo, $id);', $facturaVerPhp);
    flus_assert_not_contains('$sql = \'', $facturaVerPhp);
});

$results[] = flus_run_test('fase 4 migracion y wiring agregan recibos sin tocar baseline', function (): void {
    $repoRoot = dirname(__DIR__);
    $migrationSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '019_recibos_aplicaciones.sql');
    $installSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'install.sql');
    $ccControllerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'CuentaCorrienteController.php');
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');
    $facturaViewLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'factura_view_lib.php');
    $facturaVerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_ver.php');

    flus_assert_contains('CREATE TABLE IF NOT EXISTS `recibo_aplicaciones`', $migrationSql);
    flus_assert_contains('ADD COLUMN `recibo_documento_id`', $migrationSql);
    flus_assert_contains('ADD COLUMN `request_uid`', $migrationSql);
    flus_assert_contains('findRecentDuplicatePago', $ccControllerPhp);
    flus_assert_contains('flus_cobranzas_attach_receipt_to_cobranza', $ccControllerPhp);
    flus_assert_contains('flus_cobranzas_link_receipt_factura_from_documento', $facturacionLib);
    flus_assert_contains('flus_cobranzas_fetch_receipts_by_factura', $facturaViewLib);
    flus_assert_contains("require_once __DIR__ . '/../src/factura_view_lib.php';", $facturaVerPhp);
    flus_assert_false(str_contains($installSql, 'recibo_aplicaciones'));
    flus_assert_false(str_contains($installSql, 'recibo_documento_id'));
});

$results[] = flus_run_test('fase 3 migracion y wiring mantienen alcance minimo y no destructivo', function (): void {
    $repoRoot = dirname(__DIR__);
    $migrationSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '018_cobranzas_base.sql');
    $ventaApiLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'venta_api_lib.php');
    $ccControllerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'CuentaCorrienteController.php');
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');

    flus_assert_contains('CREATE TABLE IF NOT EXISTS `cobranzas`', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS `cobranza_aplicaciones`', $migrationSql);
    flus_assert_contains('flus_cobranzas_register_sale_payment', $ventaApiLib);
    flus_assert_contains('flus_cobranzas_register_cc_payment', $ccControllerPhp);
    flus_assert_contains('flus_cobranzas_link_factura_from_sale', $facturacionLib);
    flus_assert_false(str_contains($migrationSql, 'DROP TABLE venta_pagos'));
});

$results[] = flus_run_test('errores ARCA se clasifican en estados fiscales consistentes', function (): void {
    flus_assert_same('Pendiente de envío', flus_facturacion_estado_fiscal_label('PENDIENTE_ENVIO'));
    flus_assert_same('Autorizada', flus_facturacion_estado_fiscal_label('AUTORIZADA'));
    flus_assert_same('Error post-ARCA', flus_facturacion_estado_fiscal_label('ERROR_POST_ARCA'));
    flus_assert_same('Recuperada', flus_facturacion_estado_fiscal_label('RECUPERADA'));
    flus_assert_true(flus_facturacion_estado_fiscal_requiere_intervencion('ERROR_POST_ARCA'));
    flus_assert_false(flus_facturacion_estado_fiscal_requiere_intervencion('RECUPERADA'));
    flus_assert_same('ERROR_TRANSITORIO', flus_facturacion_estado_fiscal_por_error('SOAP Fault: timeout al conectar con WSAA'));
    flus_assert_same('RECHAZADA', flus_facturacion_estado_fiscal_por_error('[10015] El numero de documento es invalido'));
    flus_assert_same('TRANSIENT', flus_facturacion_error_code('SOAP Fault: timeout al conectar con WSAA'));
    flus_assert_same('10015', flus_facturacion_error_code('[10015] El numero de documento es invalido'));
    flus_assert_true(flus_facturacion_manual_retry_state_es_reutilizable('ERROR_POST_ARCA'));
    flus_assert_true(flus_facturacion_manual_retry_state_es_reutilizable('RECUPERADA'));
});

$results[] = flus_run_test('fase 7 deja recovery comun cableado sin romper baseline', function (): void {
    $repoRoot = dirname(__DIR__);
    $migrationSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '022_facturas_fiscal_contingencia.sql');
    $installSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'install.sql');
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');
    $repoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Fiscal' . DIRECTORY_SEPARATOR . 'Repository' . DIRECTORY_SEPARATOR . 'PdoFacturaFiscalRepository.php');
    $facturacionPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion.php');
    $facturaVerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_ver.php');
    $recoveryPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion_recovery.php');

    flus_assert_contains('ERROR_POST_ARCA', $migrationSql);
    flus_assert_contains('RECUPERADA', $migrationSql);
    flus_assert_not_contains('ERROR_POST_ARCA', $installSql);
    flus_assert_not_contains('RECUPERADA', $installSql);
    flus_assert_contains('flus_facturacion_regularizar_factura', $facturacionLib);
    flus_assert_not_contains('envio_ultimo_estado', $facturacionLib);
    flus_assert_not_contains("'envio_ultimo_estado'", $repoPhp);
    flus_assert_contains('facturacion_recovery.php', $facturacionPhp);
    flus_assert_contains('Regularizar factura', $facturaVerPhp);
    flus_assert_contains('Ultima interaccion ARCA', $recoveryPhp);
    flus_assert_contains('Ultimo envio comercial', $facturaVerPhp);
});

$results[] = flus_run_test('baseline install.sql mas migraciones no superpone columnas de fase 1', function (): void {
    $repoRoot = dirname(__DIR__);
    $installSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'install.sql');
    $migrationsDir = $repoRoot . DIRECTORY_SEPARATOR . 'migrations';
    $migratePhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'migrate.php');

    $extractColumns = static function (string $sql, string $table): array {
        $columns = [];
        if (preg_match('/CREATE TABLE(?: IF NOT EXISTS)? `?' . preg_quote($table, '/') . '`? \((.*?)\) ENGINE=/is', $sql, $m) === 1) {
            if (preg_match_all('/^\s*`([^`]+)`\s+/m', $m[1], $matches)) {
                $columns = array_values(array_unique(array_map('strval', $matches[1])));
            }
        }
        return $columns;
    };

    $extractAlterAdds = static function (string $sql, string $table): array {
        $columns = [];
        if (preg_match_all('/ALTER TABLE `?' . preg_quote($table, '/') . '`?\s+ADD COLUMN `([^`]+)`/i', $sql, $matches)) {
            $columns = array_values(array_unique(array_map('strval', $matches[1])));
        }
        return $columns;
    };

    $extractAddsFromGlob = static function (string $dir, string $table, int $until) use ($extractAlterAdds): array {
        $columns = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $path) {
            $base = basename($path);
            if ((int)substr($base, 0, 3) >= $until) {
                continue;
            }
            $columns = array_merge($columns, $extractAlterAdds((string)file_get_contents($path), $table));
        }
        $columns = array_values(array_unique($columns));
        sort($columns, SORT_STRING);
        return $columns;
    };

    $facturasInstall = $extractColumns($installSql, 'facturas');
    $eventosInstall = $extractColumns($installSql, 'factura_eventos_arca');
    $facturasPre016 = $extractAddsFromGlob($migrationsDir, 'facturas', 16);
    $eventosPre016 = $extractAddsFromGlob($migrationsDir, 'factura_eventos_arca', 16);
    $mig016 = (string)file_get_contents($migrationsDir . DIRECTORY_SEPARATOR . '016_factura_comun_fiscal_flow.sql');
    $facturas016 = $extractAlterAdds($mig016, 'facturas');
    $eventos016 = $extractAlterAdds($mig016, 'factura_eventos_arca');

    foreach ($facturas016 as $col) {
        flus_assert_false(in_array($col, $facturasInstall, true), 'install.sql no debe traer ' . $col . ' porque vive en migraciones.');
        flus_assert_false(in_array($col, $facturasPre016, true), 'La migración 016 no debe duplicar columna previa: ' . $col);
    }
    foreach ($eventos016 as $col) {
        flus_assert_false(in_array($col, $eventosInstall, true), 'install.sql no debe traer ' . $col . ' porque vive en migraciones.');
        flus_assert_false(in_array($col, $eventosPre016, true), 'La migración 016 no debe duplicar columna previa: ' . $col);
    }

    flus_assert_contains('CREATE TABLE IF NOT EXISTS `factura_eventos_arca`', (string)file_get_contents($migrationsDir . DIRECTORY_SEPARATOR . '014_factura_items_eventos_arca.sql'));
    flus_assert_contains('ADD COLUMN `estado_fiscal`', $mig016);
    flus_assert_contains('ADD COLUMN `venta_id`', $mig016);
    flus_assert_contains('install.sql (baseline) + scripts/migrate.php', $mig016);
    flus_assert_contains('$migrationsDir = $root . \'/migrations\';', $migratePhp);
});


$results[] = flus_run_test('fase 6 crea presupuesto base y remito derivado con origen documental', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    $items = flus_facturacion_normalize_manual_items([
        ['descripcion' => 'Servicio base', 'cantidad' => 2, 'precio' => 150, 'iva_porcentaje' => 21],
    ]);

    $presupuestoId = flus_facturacion_documento_crear($pdo, 'PRESUPUESTO', 77, $items, [
        'nota' => 'Presupuesto inicial',
        'medio_pago' => 'PRESUPUESTO',
    ], [
        'request_uid' => 'fase6-pres-1',
        'origen' => 'MANUAL',
    ]);

    $remitoId = flus_facturacion_documento_clonar($pdo, $presupuestoId, 'REMITO', [], [
        'reusar_existente' => true,
    ]);
    $remitoRetryId = flus_facturacion_documento_clonar($pdo, $presupuestoId, 'REMITO', [], [
        'reusar_existente' => true,
    ]);

    flus_assert_same($remitoId, $remitoRetryId);
    flus_assert_same(2, count($pdo->rows('documentos_comerciales')));
    flus_assert_same(2, count($pdo->rows('documento_items')));

    $remito = flus_facturacion_documento_buscar($pdo, $remitoId);
    $presupuesto = flus_facturacion_documento_buscar($pdo, $presupuestoId);

    flus_assert_true(is_array($remito));
    flus_assert_same('REMITO', (string)($remito['tipo_documento'] ?? ''));
    flus_assert_same($presupuestoId, (int)($remito['documento_origen_id'] ?? 0));
    flus_assert_same('REMITADO', (string)($presupuesto['estado'] ?? ''));
});

$results[] = flus_run_test('fase 6 presupuesto convierte a venta manual y no duplica la conversion', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    $items = flus_facturacion_normalize_manual_items([
        ['descripcion' => 'Producto presupuestado', 'cantidad' => 1, 'precio' => 450, 'iva_porcentaje' => 21],
    ]);

    $presupuestoId = flus_facturacion_documento_crear($pdo, 'PRESUPUESTO', 55, $items, [
        'nota' => 'Presupuesto a venta',
        'medio_pago' => 'PRESUPUESTO',
    ], [
        'request_uid' => 'fase6-pres-venta',
    ]);

    $ventaA = flus_facturacion_documento_convertir_a_venta_manual($pdo, $presupuestoId);
    $ventaB = flus_facturacion_documento_convertir_a_venta_manual($pdo, $presupuestoId);
    $presupuesto = flus_facturacion_documento_buscar($pdo, $presupuestoId);

    flus_assert_same($ventaA, $ventaB);
    flus_assert_same(1, count($pdo->rows('ventas')));
    flus_assert_same(1, count($pdo->rows('factura_manual_items')));
    flus_assert_same($ventaA, (int)($presupuesto['venta_id'] ?? 0));
    flus_assert_same('CONVERTIDO_VENTA', (string)($presupuesto['estado'] ?? ''));
});

$results[] = flus_run_test('fase 6 relaciones documentales resuelven origen, hijos y factura', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    $pdo->seedTable('documentos_comerciales', [
        'id', 'request_uid', 'tipo_documento', 'origen', 'estado', 'cliente_id', 'venta_id', 'documento_origen_id',
        'nota', 'medio_pago', 'total', 'created_at', 'updated_at'
    ], [
        ['id' => 10, 'request_uid' => 'pres-10', 'tipo_documento' => 'PRESUPUESTO', 'origen' => 'MANUAL', 'estado' => 'CONVERTIDO_VENTA', 'cliente_id' => 77, 'venta_id' => 501, 'documento_origen_id' => null, 'nota' => 'Presupuesto', 'medio_pago' => 'PRESUPUESTO', 'total' => 500.00, 'created_at' => '2026-03-24 10:00:00', 'updated_at' => '2026-03-24 10:00:00'],
        ['id' => 11, 'request_uid' => 'rem-11', 'tipo_documento' => 'REMITO', 'origen' => 'PRESUPUESTO', 'estado' => 'EMITIDO', 'cliente_id' => 77, 'venta_id' => 501, 'documento_origen_id' => 10, 'nota' => 'Remito', 'medio_pago' => 'REMITO', 'total' => 500.00, 'created_at' => '2026-03-24 10:05:00', 'updated_at' => '2026-03-24 10:05:00'],
    ]);
    $pdo->seedTable('facturas', [
        'id', 'venta_id', 'documento_id', 'cliente_id', 'fiscal_request_uid', 'estado_fiscal', 'naturaleza'
    ], [
        ['id' => 3001, 'venta_id' => 501, 'documento_id' => 11, 'cliente_id' => 77, 'fiscal_request_uid' => 'fac-doc-11', 'estado_fiscal' => 'AUTORIZADA', 'naturaleza' => 'FACTURA'],
    ]);

    $rel = flus_facturacion_documento_relaciones($pdo, 11);
    $relOrigen = flus_facturacion_documento_relaciones($pdo, 10);

    flus_assert_same(10, (int)(($rel['origen'] ?? [])['id'] ?? 0));
    flus_assert_same(3001, (int)(($rel['factura'] ?? [])['id'] ?? 0));
    flus_assert_same(1, count((array)($relOrigen['hijos'] ?? [])));
    flus_assert_same(11, (int)((($relOrigen['hijos'] ?? [])[0] ?? [])['id'] ?? 0));
});

$results[] = flus_run_test('fase 6 migracion y wiring agregan relaciones documentales sin tocar baseline', function (): void {
    $repoRoot = dirname(__DIR__);
    $migrationSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '021_documentos_relaciones_presupuestos_remitos.sql');
    $installSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'install.sql');
    $manualLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_manual_lib.php');
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');
    $facturacionPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion.php');
    $documentosPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'documentos_comerciales.php');
    $documentoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'documento_comercial.php');
    $facturaVerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_ver.php');

    flus_assert_contains('ADD COLUMN `documento_origen_id`', $migrationSql);
    flus_assert_contains('idx_documentos_origen', $migrationSql);
    flus_assert_contains('flus_facturacion_documento_clonar', $manualLib);
    flus_assert_contains('flus_facturacion_documento_convertir_a_venta_manual', $manualLib);
    flus_assert_contains('emitirFacturaDesdeDocumento', $facturacionLib);
    flus_assert_contains('documentos_comerciales.php', $facturacionPhp);
    flus_assert_contains('Generar remito', $documentoPhp);
    flus_assert_contains('Documentos comerciales', $documentosPhp);
    flus_assert_contains('documento_comercial.php', $facturaVerPhp);
    flus_assert_false(str_contains($installSql, 'documento_origen_id'));
});

$results[] = flus_run_test('preflight de emision fiscal bloquea configuracion incompleta y tolera demo', function (): void {
    $pdo = flus_test_facturacion_fake_pdo();
    config_clear_cache();

    $demo = flus_facturacion_preflight_emision($pdo, [
        'modo' => 'demo',
        'punto_venta' => 1,
        'razon_social' => 'FLUS Demo',
        'cuit' => '',
        'domicilio' => '',
        'cond_iva' => 'RI',
        'proximo_numero' => 1,
    ], ['modo' => 'demo']);
    flus_assert_true((bool)($demo['ok'] ?? false));

    $prod = flus_facturacion_preflight_emision($pdo, [
        'modo' => 'produccion',
        'punto_venta' => 1,
        'razon_social' => 'FLUS Produccion',
        'cuit' => '',
        'domicilio' => 'San Martin 123',
        'cond_iva' => 'RI',
        'proximo_numero' => 1,
    ], ['modo' => 'produccion']);
    flus_assert_false((bool)($prod['ok'] ?? true));
    flus_assert_contains('CUIT emisor local', flus_facturacion_preflight_emision_error($prod));
});

$results[] = flus_run_test('preflight de emision queda cableado en pantallas y configuracion', function (): void {
    $repoRoot = dirname(__DIR__);
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');
    $preflightLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_preflight_lib.php');
    $facturaNuevaPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_nueva.php');
    $facturaManualPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_manual.php');
    $facturaEmitirPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_emitir.php');
    $facturacionConfigPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion_config.php');

    flus_assert_contains("require_once __DIR__ . '/facturacion_preflight_lib.php';", $facturacionLib);
    flus_assert_contains('function flus_facturacion_preflight_emision(PDO $pdo, ?array $config = null, array $opciones = []): array', $preflightLib);
    flus_assert_contains('function flus_facturacion_preflight_emision_error(array $preflight): string', $preflightLib);
    flus_assert_contains('function flus_facturacion_assert_preflight_emision(PDO $pdo, ?array $config = null, array $opciones = []): array', $preflightLib);
    flus_assert_contains('$emitPreflight = flus_facturacion_preflight_emision($pdo, $config);', $facturaNuevaPhp);
    flus_assert_contains('flus_facturacion_assert_preflight_emision($pdo, $config);', $facturaNuevaPhp);
    flus_assert_contains('$emitPreflight = flus_facturacion_preflight_emision($pdo, $config);', $facturaManualPhp);
    flus_assert_contains('flus_facturacion_assert_preflight_emision($pdo, $config);', $facturaManualPhp);
    flus_assert_contains('flus_facturacion_assert_preflight_emision($pdo, $config);', $facturaEmitirPhp);
    flus_assert_contains('$emitPreflight = flus_facturacion_preflight_emision($pdo, $configRow ?? null, [\'modo\' => $configModo]);', $facturacionConfigPhp);
    flus_assert_contains('Preflight de emision', $facturacionConfigPhp);
});

$results[] = flus_run_test('facturacion recovery tolera collations mixtas y navega desde el nav principal', function (): void {
    $repoRoot = dirname(__DIR__);
    $recoveryPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion_recovery.php');
    $navPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'nav.php');
    $facturacionPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion.php');
    $documentosPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'documentos_comerciales.php');
    $ncPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion_nc.php');
    $manualPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_manual.php');
    $facturaVerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_ver.php');

    flus_assert_contains('CONVERT(fe.request_uid USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(f.fiscal_request_uid USING utf8mb4) COLLATE utf8mb4_unicode_ci', $recoveryPhp);
    flus_assert_contains('$facturacionLinks[] = [\'href\' => \'facturacion.php\'', $navPhp);
    flus_assert_contains('$facturacionLinks[] = [\'href\' => \'documentos_comerciales.php\'', $navPhp);
    flus_assert_contains('$facturacionLinks[] = [\'href\' => \'facturacion_recovery.php\'', $navPhp);
    flus_assert_contains('\'facturacion_recovery.php\'     => \'facturacion\'', $navPhp);
    flus_assert_contains('\'facturacion_config.php\'       => \'facturacion\'', $navPhp);
    flus_assert_not_contains('facturacion_subnav', $facturacionPhp);
    flus_assert_not_contains('facturacion_subnav', $documentosPhp);
    flus_assert_not_contains('facturacion_subnav', $ncPhp);
    flus_assert_not_contains('facturacion_subnav', $manualPhp);
    flus_assert_not_contains('facturacion_subnav', $facturaVerPhp);
});

$results[] = flus_run_test('factura_ver imprime desde vista limpia y documentos comerciales recupera acciones propias', function (): void {
    $repoRoot = dirname(__DIR__);
    $manualLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_manual_lib.php');
    $documentoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'documento_comercial.php');
    $facturaVerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'factura_ver.php');
    $facturaCss = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'factura.css');

    flus_assert_contains('function flus_facturacion_documento_actualizar_cabecera(PDO $pdo, int $documentoId, array $data): void', $manualLib);
    flus_assert_contains('function flus_facturacion_documento_vincular_venta(PDO $pdo, int $documentoId, int $ventaId): void', $manualLib);
    flus_assert_contains('function flus_facturacion_documento_acciones(PDO $pdo, int $documentoId): array', $manualLib);
    flus_assert_contains('require_once __DIR__ . \'/../src/facturacion_manual_lib.php\';', $documentoPhp);
    flus_assert_contains("autoprint=1&pdf_token='", $facturaVerPhp);
    flus_assert_contains("window.print();", $facturaVerPhp);
    flus_assert_not_contains('onclick="window.print()"', $facturaVerPhp);
    flus_assert_contains('.nav-breadcrumb,', $facturaCss);
});

$results[] = flus_run_test('facturacion nc muestra flash visible y conserva historial especifico por factura', function (): void {
    $repoRoot = dirname(__DIR__);
    $ncPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion_nc.php');
    $ncJs = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'facturacion_nc.js');
    $panelLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_panel_lib.php');

    flus_assert_contains('data-nc-ok="<?= nc_h($ncOk) ?>"', $ncPhp);
    flus_assert_contains('data-nc-error="<?= nc_h($ncError) ?>"', $ncPhp);
    flus_assert_contains('Historial de NC emitidas sobre esta factura', $ncPhp);
    flus_assert_contains('function initFlashNotifications()', $ncJs);
    flus_assert_contains("window.Notif.exito", $ncJs);
    flus_assert_contains("window.Notif.error", $ncJs);
    flus_assert_contains("FROM facturas f", $panelLib);
});

$results[] = flus_run_test('facturacion general distingue factura, nc y nd visualmente en el historial', function (): void {
    $repoRoot = dirname(__DIR__);
    $facturacionPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'facturacion.php');
    $facturacionCss = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'facturacion.css');

    flus_assert_contains("\$naturalezaLabel = \$isNcFila ? 'NC' : (\$isNdFila ? 'ND' : 'FACTURA');", $facturacionPhp);
    flus_assert_contains("fact-inline-badge--nc", $facturacionPhp);
    flus_assert_contains("fact-inline-badge--doc", $facturacionPhp);
    flus_assert_contains('.fact-inline-badge--nc {', $facturacionCss);
    flus_assert_contains('.fact-inline-badge--doc {', $facturacionCss);
    flus_assert_contains('.fact-inline-badge--nd {', $facturacionCss);
});

$results[] = flus_run_test('facturacion desde documento no fuerza venta_id invalido y humaniza remitos con venta rota', function (): void {
    $repoRoot = dirname(__DIR__);
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');
    $contextLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_context_lib.php');
    $documentoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'documento_comercial.php');
    $documentoJs = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'documento_comercial.js');
    $documentosPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'documentos_comerciales.php');

    flus_assert_contains("'venta_id' => (int)(\$context['venta']['id'] ?? 0) > 0 ? (int)(\$context['venta']['id'] ?? 0) : null,", $contextLib);
    flus_assert_contains('El documento comercial apunta a una venta inexistente. Vincula una venta valida antes de facturar o genera una nueva desde el documento.', $facturacionLib);
    flus_assert_contains('function documento_comercial_humanizar_error(Throwable $e): string', $documentoPhp);
    flus_assert_contains('if (flus_facturacion_facturas_require_venta($pdo)) {', $documentoPhp);
    flus_assert_contains('Genera o vincula una venta antes de emitir la factura desde este documento.', $documentoPhp);
    flus_assert_contains("\$errores[] = documento_comercial_humanizar_error(\$e);", $documentoPhp);
    flus_assert_contains('data-doc-flash-ok="<?= h($flashOk) ?>"', $documentoPhp);
    flus_assert_contains('data-doc-flash-error="<?= h($flashError) ?>"', $documentoPhp);
    flus_assert_contains('function initDocumentoComercialFlash()', $documentoJs);
    flus_assert_contains('window.Notif.exito', $documentoJs);
    flus_assert_contains('window.Notif.error', $documentoJs);
    flus_assert_contains('elseif (!empty($accionesDocumento[\'puede_generar_venta\']))', $documentoPhp);
    flus_assert_contains('Si generás la venta desde este remito, FLUS la deja vinculada y después ya podés emitir la factura.', $documentoPhp);
    flus_assert_contains("return ['estado' => 'Listo para cierre', 'siguiente' => 'Generar o vincular venta'];", $documentosPhp);
});

$results[] = flus_run_test('auth html access errors keep FLUS styling and forbidden goes through central renderer', function (): void {
    $repoRoot = dirname(__DIR__);
    $authPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'auth.php');

    flus_assert_contains('class="flus-access-card"', $authPhp);
    flus_assert_contains("flus_render_access_error('html', 403, 'FORBIDDEN', 'No tenes permisos para acceder a esta seccion.');", $authPhp);
    flus_assert_not_contains('echo "No ten', $authPhp);
});

$results[] = flus_run_test('usuario form muestra validacion explicita para password corto y fuerza recarga del asset', function (): void {
    $repoRoot = dirname(__DIR__);
    $usuarioFormJs = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'usuario_form.js');
    $usuarioEditarPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'usuario_editar.php');
    $usuarioNuevoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'usuario_nuevo.php');
    $usuarioGuardarPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'usuario_guardar.php');
    $userAdminLibPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'user_admin_lib.php');

    flus_assert_contains("if (trimmedValue !== '' && value.length < 6) {", $usuarioFormJs);
    flus_assert_contains("setFieldError(field, 'Debe tener al menos 6 caracteres');", $usuarioFormJs);
    flus_assert_contains("assets/js/usuario_form.js?v=2", $usuarioEditarPhp);
    flus_assert_contains("assets/js/usuario_form.js?v=2", $usuarioNuevoPhp);
    flus_assert_contains("data-error-for=\"password\"><?= h(\$passwordFieldError) ?></span>", $usuarioEditarPhp);
    flus_assert_contains("require_once __DIR__ . '/../src/user_admin_lib.php';", $usuarioNuevoPhp);
    flus_assert_contains("require_once __DIR__ . '/../src/user_admin_lib.php';", $usuarioGuardarPhp);
    flus_assert_contains('flus_create_user_from_payload($pdo, $_POST);', $usuarioNuevoPhp);
    flus_assert_contains('flus_create_user_from_payload($pdo, $_POST);', $usuarioGuardarPhp);
    flus_assert_contains("'require_email' => true,", $userAdminLibPhp);
    flus_assert_contains("'default_activo' => 0,", $userAdminLibPhp);
    flus_assert_not_contains("'require_email' => false,", $usuarioGuardarPhp);
    flus_assert_not_contains("'default_activo' => 1,", $usuarioGuardarPhp);
});

$results[] = flus_run_test('configuracion y login usan csrf centralizado y bootstrap de sesion', function (): void {
    $repoRoot = dirname(__DIR__);
    $configuracionPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'configuracion.php');
    $loginProcessPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'login_process.php');

    flus_assert_contains("require_once __DIR__ . '/lib/csrf.php';", $configuracionPhp);
    flus_assert_contains('$csrfToken = csrf_token();', $configuracionPhp);
    flus_assert_contains('if (!csrf_verify($token)) {', $configuracionPhp);
    flus_assert_not_contains('session_start();', $configuracionPhp);
    flus_assert_not_contains("hash_equals(\$_SESSION['csrf_token'], \$token)", $configuracionPhp);
    flus_assert_not_contains('session_start()', $loginProcessPhp);
    flus_assert_contains("if (!csrf_verify((string)(\$_POST['csrf_token'] ?? ''))) {", $loginProcessPhp);
});

$results[] = flus_run_test('pdo reconnection helpers stay centralized in config and scaffold files', function (): void {
    $repoRoot = dirname(__DIR__);
    $dbHelpersPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db_helpers.php');
    $configExamplePhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config.example.php');
    $authPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'auth.php');
    $bootstrapPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'bootstrap.php');
    $installPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'install.php');

    flus_assert_contains("function flus_pdo_fresh(int \$timeout = 3): PDO {", $dbHelpersPhp);
    flus_assert_contains("function flus_pdo_exception_is_connectivity(Throwable \$e): bool {", $dbHelpersPhp);
    flus_assert_not_contains('function flus_pdo_fresh(): PDO {', $authPhp);
    flus_assert_contains("require_once FLUS_ROOT . '/src/db_helpers.php';", $authPhp);
    flus_assert_contains("require_once FLUS_ROOT . '/src/db_helpers.php';", $bootstrapPhp);
    flus_assert_contains('if (flus_pdo_exception_is_connectivity($e)) {', $authPhp);
    flus_assert_contains("function flus_pdo_fresh(int \$timeout = 3): PDO {", $configExamplePhp);
    flus_assert_contains('// CONEXION PDO', $installPhp);
    flus_assert_contains('function flus_pdo_fresh(int \\$timeout = 3): PDO {\\n', $installPhp);
});

$results[] = flus_run_test('terminal selection no longer acquires locks before caja permissions', function (): void {
    $repoRoot = dirname(__DIR__);
    $apiIndexPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php');
    $sessionHeartbeatPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'session_heartbeat.php');
    $terminalSelectActionPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'terminal_select.php');
    $cajaPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'caja.php');
    $cajaCerrarPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'caja_cerrar.php');
    $cajaMovimientosPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'caja_movimientos.php');
    $terminalSelectJs = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'terminal_select.js');
    $terminalSelectPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'terminal_select.php');

    flus_assert_not_contains("case 'terminal_select': {", $apiIndexPhp);
    flus_assert_contains("'terminal_select' => [", $apiIndexPhp);
    flus_assert_not_contains('terminal_lock_acquire($pdo, $requestedTerminalId', $terminalSelectActionPhp);
    flus_assert_contains('terminal_lock_status($pdo, $requestedTerminalId);', $terminalSelectActionPhp);
    flus_assert_contains("setMsg(\"Elegí una terminal para seleccionarla y continuar.\");", $terminalSelectJs);
    flus_assert_contains('const isLocked = Boolean(t.locked) || String(t.status || "") === "locked";', $terminalSelectJs);
    flus_assert_contains('$duplicateBasePrefix = $base . \'/\' . $baseLeaf . \'/\';', $terminalSelectPhp);
    flus_assert_contains('while (u.pathname.startsWith(duplicateBasePrefix)) {', $terminalSelectJs);
    flus_assert_true(strpos($cajaPhp, "require_any_permission(['abrir_caja', 'realizar_ventas']);") < strpos($cajaPhp, 'require_pos();'));
    flus_assert_true(strpos($cajaCerrarPhp, "require_permission('cerrar_caja');") < strpos($cajaCerrarPhp, 'require_pos();'));
    flus_assert_true(strpos($cajaMovimientosPhp, "require_permission('realizar_ventas');") < strpos($cajaMovimientosPhp, 'require_pos();'));
    flus_assert_contains("'registrar_venta' => [", $apiIndexPhp);
    flus_assert_contains("'permissions' => ['realizar_ventas'],", $apiIndexPhp);
    flus_assert_contains('require_terminal_lock_json();', (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'registrar_venta.php'));
});

$results[] = flus_run_test('terminal release from admin redirects caja users with an explicit notice', function (): void {
    $repoRoot = dirname(__DIR__);
    $appJs = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'app.js');
    $terminalSelectPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'terminal_select.php');
    $terminalSelectJs = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'terminal_select.js');

    flus_assert_contains('terminal_select.php?next=caja.php&notice=terminal_released', $appJs);
    flus_assert_contains('terminal_select.php?next=caja.php&notice=terminal_required', $appJs);
    flus_assert_contains('const baseDelay = isCajaPage ? 5000 : 25000;', $appJs);
    flus_assert_contains('window.addEventListener("focus", requestFastPing);', $appJs);
    flus_assert_contains("'terminal_released' => 'Un administrador liberó la terminal que estabas usando. Elegí una terminal para continuar.',", $terminalSelectPhp);
    flus_assert_contains('data-notice-message="<?= h($noticeMessage) ?>"', $terminalSelectPhp);
    flus_assert_contains('if (noticeMessage) {', $terminalSelectJs);
    flus_assert_contains('toast(noticeMessage, "warn", 3600);', $terminalSelectJs);
});

$results[] = flus_run_test('caja activa modo compacto con split o cuenta corriente sin reubicar ccWrap', function (): void {
    $repoRoot = dirname(__DIR__);
    $cajaJs = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'caja.js');
    $cajaNeoCss = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'caja.neo.css');

    flus_assert_contains('const ccActivo = tieneCC();', $cajaJs);
    flus_assert_contains('cajaPanel.classList.toggle("is-payment-compact", split || ccActivo);', $cajaJs);
    flus_assert_contains('cajaPanel.classList.toggle("has-cc-payment", ccActivo);', $cajaJs);
    flus_assert_not_contains('insertAdjacentElement("afterend", ccWrap)', $cajaJs);
    flus_assert_not_contains('ccWrap.classList.toggle("cc-wrap--compact"', $cajaJs);
    flus_assert_contains('.pagos-row > #ccWrap', $cajaNeoCss);
    flus_assert_not_contains('.cc-wrap.cc-wrap--compact', $cajaNeoCss);
    flus_assert_not_contains('.cc-wrap.cc-wrap--after-pago2', $cajaNeoCss);
});

$results[] = flus_run_test('session registry wires login logout bootstrap heartbeat and diagnostics controls', function (): void {
    $repoRoot = dirname(__DIR__);
    $sessionRegistryPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'session_registry.php');
    $bootstrapPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'bootstrap.php');
    $loginProcessPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'login_process.php');
    $logoutPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'logout.php');
    $loginPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'login.php');
    $apiIndexPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php');
    $sessionHeartbeatPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'session_heartbeat.php');
    $appJs = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'app.js');
    $diagnosticoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'diagnostico.php');
    $terminalPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'terminal.php');
    $migrationSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '023_user_sessions_registry.sql');
    $installSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'install.sql');

    flus_assert_contains('function flus_session_register(PDO $pdo, array $user, array $meta = []): void', $sessionRegistryPhp);
    flus_assert_contains('function flus_session_revoke(PDO $pdo, string $sessionId, int $revokedBy, string $reason = \'\'): void', $sessionRegistryPhp);
    flus_assert_contains('function flus_session_resolve_terminal_id(PDO $pdo, array $meta = []): ?int', $sessionRegistryPhp);
    flus_assert_contains("if (\$lastPath === trim(\$path) && \$rowTerminalId === \$resolvedTerminalId) {", $sessionRegistryPhp);
    flus_assert_contains('function terminal_lock_release_by_session(PDO $pdo, string $sessionId): int', $terminalPhp);
    flus_assert_contains("flus_session_register(\$pdo, \$_SESSION['user']);", $loginProcessPhp);
    flus_assert_contains("flus_session_mark_logged_out(\$pdo, \$currentSessionId, \$reason === 'revoked');", $logoutPhp);
    flus_assert_contains("define('FLUS_SESSION_ENFORCE_BYPASS', true);", $logoutPhp);
    flus_assert_contains("'error' => 'SESSION_REVOKED'", $bootstrapPhp);
    flus_assert_contains("header('Location: logout.php?reason=revoked');", $bootstrapPhp);
    flus_assert_contains("'session_heartbeat' => [", $apiIndexPhp);
    flus_assert_not_contains("case 'session_heartbeat': {", $apiIndexPhp);
    flus_assert_contains("flus_session_touch(\$pdo, \$uid, \$sid, ['force' => true]);", $sessionHeartbeatPhp);
    flus_assert_contains('api/index.php?action=session_heartbeat', $appJs);
    flus_assert_contains('const nextDelay = () => document.visibilityState === "visible" ? 15000 : 60000;', $appJs);
    flus_assert_contains('window.addEventListener("focus", requestFastSessionPing);', $appJs);
    flus_assert_contains('logout.php?reason=revoked', $appJs);
    flus_assert_contains("case 'revoked': \$errorMsg = 'Tu sesi", $loginPhp);
    flus_assert_contains("value=\"revocar_sesion\"", $diagnosticoPhp);
    flus_assert_contains("value=\"liberar_terminal_sesion\"", $diagnosticoPhp);
    flus_assert_contains("audit_event('SESSION_REVOKE', AuditEntities::USER", $diagnosticoPhp);
    flus_assert_contains("audit_event('TERMINAL_FORCE_RELEASE', AuditEntities::TERMINAL", $diagnosticoPhp);
    flus_assert_contains("if (isset(\$_GET['panel']) && (string)\$_GET['panel'] === 'sessions_json') {", $diagnosticoPhp);
    flus_assert_contains("assets/js/diagnostico.js", $diagnosticoPhp);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS `user_sessions`', $migrationSql);
    flus_assert_contains('CREATE TABLE `user_sessions`', $installSql);
});

$results[] = flus_run_test('diagnostico live refresh keeps sessions and admin actions in sync', function (): void {
    $repoRoot = dirname(__DIR__);
    $diagnosticoJs = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'diagnostico.js');
    $diagnosticoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'diagnostico.php');

    flus_assert_contains('const endpoint = String(configEl.dataset.endpoint || "").trim();', $diagnosticoJs);
    flus_assert_contains('renderSessions(data.sessions || []);', $diagnosticoJs);
    flus_assert_contains('renderActions(data.actions || []);', $diagnosticoJs);
    flus_assert_contains('const nextDelay = () => document.visibilityState === "visible" ? 10000 : 30000;', $diagnosticoJs);
    flus_assert_contains('window.addEventListener("focus", requestFastRefresh);', $diagnosticoJs);
    flus_assert_contains('id="diagSessionsConfig"', $diagnosticoPhp);
    flus_assert_contains('id="diagSessionsBody"', $diagnosticoPhp);
    flus_assert_contains('id="diagAdminActions"', $diagnosticoPhp);
});

$results[] = flus_run_test('hotspot policy documents and enforces line budgets for giant files', function (): void {
    $repoRoot = dirname(__DIR__);
    $policyPath = $repoRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'HOTSPOT_POLICY.md';
    $policyDoc = (string)file_get_contents($policyPath);
    $readme = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'README.md');

    flus_assert_contains('si un archivo operativo supera las `800` lineas, entra en zona de alerta', $policyDoc);
    flus_assert_contains('si supera las `1000` lineas, entra en plan obligatorio de particion', $policyDoc);
    flus_assert_contains('src/facturacion_lib.php', $policyDoc);
    flus_assert_contains('public/assets/js/caja.js', $policyDoc);
    flus_assert_contains('docs/HOTSPOT_POLICY.md', $readme);

    foreach (flus_hotspot_line_budgets() as $relativePath => $budget) {
        $absolutePath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $lineCount = flus_count_file_lines($absolutePath);
        flus_assert_true(
            $lineCount <= $budget,
            sprintf('%s tiene %d lineas y supera el presupuesto de %d', $relativePath, $lineCount, $budget)
        );
    }
});

$results[] = flus_run_test('ux documental explica flujo y carga manual sin esconder presupuesto o remito', function (): void {
    $repoRoot = dirname(__DIR__);
    $documentoPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'documento_comercial.php');
    $documentosPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'documentos_comerciales.php');
    $facturacionCss = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'facturacion.css');

    flus_assert_contains('Presupuestos y remitos viven aca', $documentosPhp);
    flus_assert_contains('Pueden ser manuales', $documentosPhp);
    flus_assert_contains('Qué hace este documento', $documentoPhp);
    flus_assert_contains('Cómo cargar ítems', $documentoPhp);
    flus_assert_contains('Emitir factura desde este documento', $documentoPhp);
    flus_assert_contains('Generar remito con estos ítems', $documentoPhp);
    flus_assert_contains('.fact-doc-guide {', $facturacionCss);
    flus_assert_contains('.fact-doc-guide__inline-help {', $facturacionCss);
});

$results[] = flus_run_test('apis de cuenta corriente y licencia mantienen contrato json y minimizan datos sensibles', function (): void {
    $repoRoot = dirname(__DIR__);
    $cuentaCorrienteApiPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'cuenta_corriente_api.php');
    $licenseStatusPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'license_status.php');
    $preciosApiPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'precios_api.php');

    flus_assert_contains("require_once __DIR__ . '/_bootstrap.php';", $cuentaCorrienteApiPhp);
    flus_assert_contains('require_login_json();', $cuentaCorrienteApiPhp);
    flus_assert_contains('function cc_api_guard(string $action, array $input): void {', $cuentaCorrienteApiPhp);
    flus_assert_contains("require_perm_json((string)\$config['perm']);", $cuentaCorrienteApiPhp);
    flus_assert_contains('require_csrf_json($input);', $cuentaCorrienteApiPhp);
    flus_assert_contains("'error_code' => 'INTERNAL_ERROR'", $cuentaCorrienteApiPhp);
    flus_assert_not_contains("require_once __DIR__ . '/../bootstrap.php';", $cuentaCorrienteApiPhp);

    flus_assert_contains("require_perm_json('administrar_config');", $licenseStatusPhp);
    flus_assert_contains("'status' => is_array(\$lic) ? (string)(\$lic['status'] ?? '') : ''", $licenseStatusPhp);
    flus_assert_contains("'days_left' => is_array(\$lic) ? (\$lic['days_left'] ?? null) : null", $licenseStatusPhp);
    flus_assert_not_contains("'license' => \$lic", $licenseStatusPhp);

    flus_assert_contains("require_once __DIR__ . '/_bootstrap.php';", $preciosApiPhp);
    flus_assert_contains('require_login_json();', $preciosApiPhp);
    flus_assert_contains("require_perm_json('editar_productos');", $preciosApiPhp);
    flus_assert_contains('require_csrf_json($input);', $preciosApiPhp);
    flus_assert_contains("json_fail('Accion no valida', 400, ['error_code' => 'UNKNOWN_ACTION']);", $preciosApiPhp);
    flus_assert_contains("json_fail('No se pudo procesar la operacion de precios.', 500, ['error_code' => 'INTERNAL_ERROR']);", $preciosApiPhp);
    flus_assert_not_contains("require_once __DIR__ . '/../bootstrap.php';", $preciosApiPhp);
});

$results[] = flus_run_test('terminal switch y tickets por mail endurecen validaciones sensibles', function (): void {
    $repoRoot = dirname(__DIR__);
    $terminalSwitchPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'terminal_switch.php');
    $ventasApiPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'ventas_api.php');

    flus_assert_contains('terminal_get($pdo, $newTid);', $terminalSwitchPhp);
    flus_assert_contains("json_fail('Terminal invalida', 400, ['error_code' => 'TERMINAL_INVALIDA']);", $terminalSwitchPhp);
    flus_assert_contains("json_fail('CAJA_ABIERTA', 409, ['error_code' => 'CAJA_ABIERTA']);", $terminalSwitchPhp);
    flus_assert_contains("'error_code' => 'TERMINAL_LOCKED'", $terminalSwitchPhp);
    flus_assert_contains("'terminal_nombre' => (string)(\$terminal['nombre'] ?? ('Caja #' . \$newTid))", $terminalSwitchPhp);

    flus_assert_contains('function flus_mail_header_safe_value(string $value): string {', $ventasApiPhp);
    flus_assert_contains('filter_var($candidate, FILTER_VALIDATE_EMAIL)', $ventasApiPhp);
    flus_assert_contains("\$safeName = flus_mail_header_safe_value((string)\$row['v']);", $ventasApiPhp);
    flus_assert_contains("'From: ' . \$emailConfig['from_name'] . ' <' . \$emailConfig['from_email'] . '>'", $ventasApiPhp);
    flus_assert_contains("'Reply-To: ' . \$emailConfig['from_email']", $ventasApiPhp);
});

$results[] = flus_run_test('inventario fisico valida esquema versionado sin ddl runtime', function (): void {
    $repoRoot = dirname(__DIR__);
    $inventarioPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'inventario_fisico.php');
    $inventarioPagePhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'inventario_fisico.php');
    $migrationSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '025_inventario_fisico_schema.sql');
    $installSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'install.sql');

    flus_assert_contains('function inventario_schema_requirements(): array {', $inventarioPhp);
    flus_assert_contains('function inventario_require_schema(): void {', $inventarioPhp);
    flus_assert_contains('migrations/025_inventario_fisico_schema.sql', $inventarioPhp);
    flus_assert_not_contains('CREATE TABLE IF NOT EXISTS inventario_sesiones', $inventarioPhp);
    flus_assert_not_contains('CREATE TABLE IF NOT EXISTS inventario_conteos', $inventarioPhp);
    flus_assert_not_contains('ALTER TABLE inventario_sesiones', $inventarioPhp);
    flus_assert_not_contains('ALTER TABLE inventario_conteos', $inventarioPhp);
    flus_assert_contains('Inventario fisico: falta esquema compatible.', $inventarioPagePhp);

    flus_assert_contains('ADD COLUMN IF NOT EXISTS categoria_nombre', $migrationSql);
    flus_assert_contains('ADD COLUMN IF NOT EXISTS stock_sistema_snapshot', $migrationSql);
    flus_assert_contains('`categoria_nombre` varchar(100) DEFAULT NULL', $installSql);
    flus_assert_contains('`stock_sistema_snapshot` decimal(10,3) DEFAULT NULL', $installSql);
});

$skipped = array_values(array_filter($results, static fn(array $result): bool => (bool)($result['skipped'] ?? false)));
$failed = array_values(array_filter($results, static fn(array $result): bool => !$result['ok']));

foreach ($results as $result) {
    $prefix = '[OK] ';
    if (!empty($result['skipped'])) {
        $prefix = '[SKIP] ';
    } elseif (!$result['ok']) {
        $prefix = '[FAIL] ';
    }
    echo $prefix . $result['name'] . ' - ' . $result['message'] . PHP_EOL;
}

echo PHP_EOL;
echo 'Total: ' . count($results) . ', failed: ' . count($failed) . ', skipped: ' . count($skipped) . PHP_EOL;

exit(count($failed) > 0 ? 1 : 0);
