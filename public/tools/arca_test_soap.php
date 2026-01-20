<?php
// public/tools/arca_test_soap.php
// Test rápido de WSAA + padrón (SOAP)

declare(strict_types=1);

require_once __DIR__ . '/../includes/AfipApi.php';

$cuit = $argv[1] ?? '30703088534';

$datos = AfipApi::consultarCuit((string)$cuit);
if (!$datos) {
    echo "FAIL: " . (AfipApi::getLastError() ?: 'sin error') . "\n";
    exit(1);
}

echo "OK\n";
print_r($datos);
