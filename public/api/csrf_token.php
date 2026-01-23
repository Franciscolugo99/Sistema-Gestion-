<?php
declare(strict_types=1);
require_once __DIR__ . '/_csrf_guard.php'; // asegura session y csrf_token()
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true, 'token'=>csrf_token()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
