<?php
// public/api/cliente_consultar_cuit.php
// Stub de compatibilidad: redirige a /api/actions/cliente_consultar_cuit.php

declare(strict_types=1);

$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = '/kiosco/public/api/actions/cliente_consultar_cuit.php' . ($qs ? ('?' . $qs) : '');

// Si tu instalación no usa /kiosco, ajustá esta ruta o eliminá este archivo.
header('Location: ' . $target, true, 302);
exit;
