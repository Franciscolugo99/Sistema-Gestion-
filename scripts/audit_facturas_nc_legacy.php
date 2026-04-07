<?php
declare(strict_types=1);

/**
 * scripts/audit_facturas_nc_legacy.php
 * Auditoria de solo lectura para detectar facturas legacy que podrian
 * quedar fuera de la regla actual de "apta para NC".
 *
 * Uso:
 *   php scripts/audit_facturas_nc_legacy.php
 *   php scripts/audit_facturas_nc_legacy.php --limit=200
 */

$bootstrapCandidates = [
    __DIR__ . '/../public/bootstrap.php',
    __DIR__ . '/../../public/bootstrap.php',
];
$boot = null;
foreach ($bootstrapCandidates as $candidate) {
    if (is_file($candidate)) {
        $boot = $candidate;
        break;
    }
}

if ($boot === null) {
    fwrite(STDERR, "No se encontro public/bootstrap.php\n");
    exit(1);
}

require_once $boot;
require_once __DIR__ . '/../src/db_schema.php';

$pdo = function_exists('getPDO') ? getPDO() : null;
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "PDO no disponible\n");
    exit(1);
}

if (!flus_table_exists($pdo, 'facturas')) {
    fwrite(STDERR, "No existe la tabla facturas\n");
    exit(1);
}

$limit = 100;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m) === 1) {
        $limit = max(1, min(1000, (int)$m[1]));
    }
}

$hasNaturaleza = flus_column_exists($pdo, 'facturas', 'naturaleza');
$hasTipo = flus_column_exists($pdo, 'facturas', 'tipo');
$hasEstadoFiscal = flus_column_exists($pdo, 'facturas', 'estado_fiscal');
$hasCae = flus_column_exists($pdo, 'facturas', 'cae');
$hasPv = flus_column_exists($pdo, 'facturas', 'punto_venta');
$hasNumero = flus_column_exists($pdo, 'facturas', 'numero');
$hasVentaId = flus_column_exists($pdo, 'facturas', 'venta_id');
$hasFecha = flus_column_exists($pdo, 'facturas', 'fecha');
$hasCreadoEn = flus_column_exists($pdo, 'facturas', 'creado_en');
$hasRequestUid = flus_column_exists($pdo, 'facturas', 'fiscal_request_uid');

$fechaExpr = $hasFecha ? 'f.fecha' : ($hasCreadoEn ? 'f.creado_en' : 'NULL');
$where = [];
$where[] = $hasCae ? "COALESCE(TRIM(f.cae), '') <> ''" : '1=0';
if ($hasNaturaleza) {
    $where[] = "COALESCE(TRIM(f.naturaleza), 'FACTURA') = 'FACTURA'";
} elseif ($hasTipo) {
    $where[] = "UPPER(COALESCE(TRIM(f.tipo), '')) NOT IN ('NCA','NCB','NCC','NDA','NDB','NDC')";
}

$reasonExprs = [];
if ($hasEstadoFiscal) {
    $reasonExprs[] = "CASE WHEN UPPER(COALESCE(TRIM(f.estado_fiscal), '')) NOT IN ('AUTORIZADA','RECUPERADA') THEN 'ESTADO_FISCAL' END";
}
if ($hasTipo) {
    $reasonExprs[] = "CASE WHEN COALESCE(TRIM(f.tipo), '') = '' THEN 'TIPO_VACIO' END";
}
if ($hasPv) {
    $reasonExprs[] = "CASE WHEN COALESCE(f.punto_venta, 0) <= 0 THEN 'PUNTO_VENTA_INVALIDO' END";
}
if ($hasNumero) {
    $reasonExprs[] = "CASE WHEN COALESCE(f.numero, 0) <= 0 THEN 'NUMERO_INVALIDO' END";
}

$reasonExpr = $reasonExprs === []
    ? "'SIN_DATOS_PARA_AUDITAR'"
    : 'CONCAT_WS(",", ' . implode(",\n                ", $reasonExprs) . ')';

$sql = "
    SELECT
        f.id,
        " . ($hasTipo ? "COALESCE(TRIM(f.tipo), '')" : "''") . " AS tipo,
        " . ($hasEstadoFiscal ? "COALESCE(TRIM(f.estado_fiscal), '')" : "''") . " AS estado_fiscal,
        " . ($hasCae ? "COALESCE(TRIM(f.cae), '')" : "''") . " AS cae,
        " . ($hasPv ? 'COALESCE(f.punto_venta, 0)' : '0') . " AS punto_venta,
        " . ($hasNumero ? 'COALESCE(f.numero, 0)' : '0') . " AS numero,
        " . ($hasVentaId ? 'COALESCE(f.venta_id, 0)' : '0') . " AS venta_id,
        " . ($hasRequestUid ? "COALESCE(TRIM(f.fiscal_request_uid), '')" : "''") . " AS fiscal_request_uid,
        {$fechaExpr} AS fecha_ref,
        {$reasonExpr} AS motivos
    FROM facturas f
    WHERE " . implode("\n      AND ", $where) . "
    HAVING COALESCE(TRIM(motivos), '') <> ''
    ORDER BY " . ($fechaExpr !== 'NULL' ? 'fecha_ref DESC, ' : '') . "f.id DESC
    LIMIT :limit
";

$st = $pdo->prepare($sql);
$st->bindValue(':limit', $limit, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "=== Auditoria NC legacy ===\n";
echo "Limite: {$limit}\n";
echo 'Hallazgos: ' . count($rows) . "\n\n";

if ($rows === []) {
    echo "No se detectaron facturas con CAE y metadata fiscal incompleta para NC.\n";
    exit(0);
}

$counts = [];
foreach ($rows as $row) {
    $motivos = array_filter(array_map('trim', explode(',', (string)($row['motivos'] ?? ''))));
    foreach ($motivos as $motivo) {
        $counts[$motivo] = ($counts[$motivo] ?? 0) + 1;
    }
}

echo "Resumen por motivo:\n";
ksort($counts);
foreach ($counts as $motivo => $count) {
    echo ' - ' . $motivo . ': ' . $count . "\n";
}

echo "\nDetalle:\n";
foreach ($rows as $row) {
    $label = '#' . (int)$row['id'];
    if ((int)($row['punto_venta'] ?? 0) > 0 && (int)($row['numero'] ?? 0) > 0) {
        $label .= ' ' . sprintf('%s %04d-%08d', trim((string)($row['tipo'] ?? 'FAC')), (int)$row['punto_venta'], (int)$row['numero']);
    }
    echo '- ' . $label . "\n";
    echo '  CAE: ' . (string)($row['cae'] ?? '') . "\n";
    echo '  Estado fiscal: ' . ((string)($row['estado_fiscal'] ?? '') !== '' ? (string)$row['estado_fiscal'] : '(vacio)') . "\n";
    echo '  Venta: ' . (int)($row['venta_id'] ?? 0) . "\n";
    echo '  Request UID: ' . ((string)($row['fiscal_request_uid'] ?? '') !== '' ? (string)$row['fiscal_request_uid'] : '(vacio)') . "\n";
    echo '  Fecha: ' . ((string)($row['fecha_ref'] ?? '') !== '' ? (string)$row['fecha_ref'] : '(sin fecha)') . "\n";
    echo '  Motivos: ' . (string)$row['motivos'] . "\n";
}

exit(0);
