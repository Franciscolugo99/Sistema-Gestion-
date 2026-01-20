<?php
// public/api/actions/cliente_consultar_cuit.php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/AfipApi.php';

header('Content-Type: application/json; charset=utf-8');

// Solo usuarios autenticados
if (!isset($_SESSION['usuario_id']) && !isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cuit = trim((string)($_GET['cuit'] ?? ''));
if ($cuit === '') {
    echo json_encode(['success' => false, 'error' => 'CUIT no proporcionado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$datos = AfipApi::consultarCuit($cuit);
if (!$datos) {
    $err = AfipApi::getLastError() ?: 'No se encontraron datos para este CUIT';
    // ✅ devolvemos 200 para no ensuciar consola; el front maneja success:false
    echo json_encode(['success' => false, 'error' => $err, 'cuit' => preg_replace('/\D+/', '', $cuit)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Determinar tipo de cliente según actividad / tipo contribuyente
$tipoCliente = 'MINORISTA';

if (stripos($datos['actividad'] ?? '', 'VENTA AL POR MAYOR') !== false ||
    stripos($datos['actividad'] ?? '', 'MAYORISTA') !== false) {
    $tipoCliente = 'MAYORISTA';
}

if (($datos['tipo_contribuyente'] ?? '') === 'JURIDICA' ||
    stripos($datos['nombre'] ?? '', 'S.A.') !== false ||
    stripos($datos['nombre'] ?? '', 'S.R.L.') !== false) {
    $tipoCliente = 'CORPORATIVO';
}

echo json_encode([
    'success' => true,
    'datos' => [
        'nombre' => $datos['nombre'] ?? '',
        'cuit' => $datos['cuit'] ?? '',
        'cond_iva' => $datos['cond_iva'] ?? '',
        'tipo_cliente' => $tipoCliente,
        'direccion' => $datos['direccion'] ?? '',
        'actividad' => $datos['actividad'] ?? '',
        'estado' => $datos['estado'] ?? '',
    ]
], JSON_UNESCAPED_UNICODE);
