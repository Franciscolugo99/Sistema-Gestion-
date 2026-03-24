<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura']);

function factura_pdf_redirect_error(int $facturaId, string $message): never
{
    $target = 'facturacion.php';
    if ($facturaId > 0) {
        $target = 'factura_ver.php?id=' . $facturaId . '&pdf_error=' . urlencode($message);
    }
    header('Location: ' . $target);
    exit;
}

function factura_pdf_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            factura_pdf_rrmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

$facturaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($facturaId <= 0) {
    factura_pdf_redirect_error(0, 'Factura invalida.');
}

if (!flus_table_exists($pdo, 'facturas')) {
    factura_pdf_redirect_error($facturaId, 'La tabla de facturas no existe en esta instalacion.');
}

$st = $pdo->prepare('SELECT id, punto_venta, numero, tipo FROM facturas WHERE id = ? LIMIT 1');
$st->execute([$facturaId]);
$factura = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$factura) {
    factura_pdf_redirect_error($facturaId, 'Factura no encontrada.');
}

$browserPath = flus_factura_pdf_browser_path();
if ($browserPath === null) {
    factura_pdf_redirect_error($facturaId, 'No se encontro un navegador compatible para generar PDF.');
}

$storageRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
$cacheDir = $storageRoot . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'facturas_pdf';
if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
    factura_pdf_redirect_error($facturaId, 'No se pudo preparar la carpeta temporal para el PDF.');
}

$token = flus_factura_pdf_token_create($facturaId, time() + 120);
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = trim((string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
if ($host === '') {
    $host = '127.0.0.1';
}
$baseDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/public/factura_pdf.php')));
$baseDir = rtrim($baseDir, '/');
$renderUrl = $scheme . '://' . $host . $baseDir . '/factura_ver.php?id=' . $facturaId . '&pdf=1&pdf_token=' . rawurlencode($token);

$suffix = bin2hex(random_bytes(6));
$pdfPath = $cacheDir . '/factura_' . $facturaId . '_' . $suffix . '.pdf';
$profileDir = $cacheDir . '/chrome_profile_' . $suffix;
@mkdir($profileDir, 0755, true);

$cmd = '"' . $browserPath . '"'
    . ' --headless=new'
    . ' --disable-gpu'
    . ' --no-pdf-header-footer'
    . ' --run-all-compositor-stages-before-draw'
    . ' --virtual-time-budget=3000'
    . ' --user-data-dir="' . $profileDir . '"'
    . ' --print-to-pdf="' . $pdfPath . '"'
    . ' "' . $renderUrl . '"';

$output = [];
$exitCode = 1;
exec($cmd . ' 2>&1', $output, $exitCode);

factura_pdf_rrmdir($profileDir);

if ($exitCode !== 0 || !is_file($pdfPath) || filesize($pdfPath) <= 0) {
    @unlink($pdfPath);
    $error = trim(implode("\n", $output));
    factura_pdf_redirect_error($facturaId, 'No se pudo generar el PDF.' . ($error !== '' ? ' ' . $error : ''));
}

$numero = isset($factura['numero']) && $factura['numero'] !== null ? str_pad((string)(int)$factura['numero'], 8, '0', STR_PAD_LEFT) : (string)$facturaId;
$puntoVenta = isset($factura['punto_venta']) && $factura['punto_venta'] !== null ? str_pad((string)(int)$factura['punto_venta'], 4, '0', STR_PAD_LEFT) : '0000';
$tipo = trim((string)($factura['tipo'] ?? 'factura'));
$filename = 'factura_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $tipo . '_' . $puntoVenta . '_' . $numero) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string)filesize($pdfPath));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($pdfPath);
@unlink($pdfPath);
exit;
