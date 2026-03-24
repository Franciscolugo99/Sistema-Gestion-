<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/compras_helpers.php';
require_once __DIR__ . '/../src/facturacion_manual_lib.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/cobranzas_lib.php';
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
        'id', 'request_uid', 'tipo_documento', 'origen', 'estado', 'cliente_id', 'venta_id',
        'nota', 'medio_pago', 'total', 'created_at', 'updated_at'
    ]);
    $pdo->seedTable('documento_items', [
        'id', 'documento_id', 'codigo', 'descripcion', 'cantidad', 'precio_unitario',
        'subtotal', 'iva_porcentaje', 'created_at'
    ]);
    $pdo->seedTable('factura_manual_items', [
        'id', 'venta_id', 'codigo', 'descripcion', 'cantidad', 'precio_unitario',
        'subtotal', 'iva_porcentaje', 'created_at'
    ]);
    $pdo->seedTable('facturas', [
        'id', 'venta_id', 'documento_id', 'fiscal_request_uid', 'estado_fiscal', 'naturaleza'
    ]);
    $pdo->seedTable('cobranzas', [
        'id', 'external_key', 'origen', 'estado', 'venta_id', 'cliente_id', 'cc_movimiento_id',
        'caja_id', 'caja_movimiento_id', 'medio_pago', 'importe_total', 'referencia',
        'observaciones', 'created_by', 'created_at', 'updated_at'
    ]);
    $pdo->seedTable('cobranza_aplicaciones', [
        'id', 'cobranza_id', 'application_key', 'tipo_aplicacion', 'venta_id', 'documento_id',
        'factura_id', 'cc_movimiento_id', 'monto', 'created_at'
    ]);
    return $pdo;
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

function flus_open_project_pdo(string $repoRoot): PDO
{
    $configPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Missing src/config.php for database permission test');
    }

    require_once $configPath;

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        throw new RuntimeException('Database constants are not available for permission test');
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

    return new PDO($dsn, (string)DB_USER, (string)DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
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

$results[] = flus_run_test('sh_quote handles Windows quoting', function (): void {
    $quoted = sh_quote('C:\Program Files\MySQL\bin\mysqldump.exe');
    flus_assert_same('"C:\Program Files\MySQL\bin\mysqldump.exe"', $quoted);
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
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO roles (id, nombre, slug) VALUES (1, 'Administrador', 'admin')");
    $pdo->exec('INSERT INTO users (id, role_id, activo) VALUES (1, 1, 1)');

    $error = flus_guard_user_admin_mutation($pdo, 1, 1, 0, false, null);
    flus_assert_same('No puedes desactivar tu propio usuario', $error);
});

$results[] = flus_run_test('flus_guard_user_admin_mutation protects reserved admin account role', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO roles (id, nombre, slug) VALUES (1, 'Administrador', 'admin'), (2, 'Operador', 'operador')");
    $pdo->exec("INSERT INTO users (id, email, username, role_id, activo) VALUES (1, 'admin@flus.local', 'admin', 1, 1), (2, 'owner@flus.local', 'owner', 1, 1)");

    $error = flus_guard_user_admin_mutation($pdo, 2, 1, 1, false, 2, 'admin');
    flus_assert_same('La cuenta admin de resguardo mantiene su rol original.', $error);
});

$results[] = flus_run_test('flus_guard_reserved_admin_role_mutation locks reserved role permissions', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

$results[] = flus_run_test('fase 3 migracion y wiring mantienen alcance minimo y no destructivo', function (): void {
    $repoRoot = dirname(__DIR__);
    $migrationSql = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '018_cobranzas_base.sql');
    $apiPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php');
    $ccControllerPhp = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'CuentaCorrienteController.php');
    $facturacionLib = (string)file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_lib.php');

    flus_assert_contains('CREATE TABLE IF NOT EXISTS `cobranzas`', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS `cobranza_aplicaciones`', $migrationSql);
    flus_assert_contains('flus_cobranzas_register_sale_payment', $apiPhp);
    flus_assert_contains('flus_cobranzas_register_cc_payment', $ccControllerPhp);
    flus_assert_contains('flus_cobranzas_link_factura_from_sale', $facturacionLib);
    flus_assert_false(str_contains($migrationSql, 'DROP TABLE venta_pagos'));
});

$results[] = flus_run_test('errores ARCA se clasifican en estados fiscales consistentes', function (): void {
    flus_assert_same('Pendiente de envío', flus_facturacion_estado_fiscal_label('PENDIENTE_ENVIO'));
    flus_assert_same('Autorizada', flus_facturacion_estado_fiscal_label('AUTORIZADA'));
    flus_assert_same('ERROR_TRANSITORIO', flus_facturacion_estado_fiscal_por_error('SOAP Fault: timeout al conectar con WSAA'));
    flus_assert_same('RECHAZADA', flus_facturacion_estado_fiscal_por_error('[10015] El numero de documento es invalido'));
    flus_assert_same('TRANSIENT', flus_facturacion_error_code('SOAP Fault: timeout al conectar con WSAA'));
    flus_assert_same('10015', flus_facturacion_error_code('[10015] El numero de documento es invalido'));
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


$failed = array_values(array_filter($results, static fn(array $result): bool => !$result['ok']));

foreach ($results as $result) {
    $prefix = $result['ok'] ? '[OK] ' : '[FAIL] ';
    echo $prefix . $result['name'] . ' - ' . $result['message'] . PHP_EOL;
}

echo PHP_EOL;
echo 'Total: ' . count($results) . ', failed: ' . count($failed) . PHP_EOL;

exit(count($failed) > 0 ? 1 : 0);
