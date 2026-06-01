<?php
// src/config_mp.example.php
// Copiar a src/config_mp.php y completar con credenciales de Mercado Pago.
declare(strict_types=1);

// Access Token de prueba o produccion.
// Ejemplo: TEST-... o APP_USR-...
define('FLUS_MP_ACCESS_TOKEN', '');

// Modo de caja:
// automatic = FLUS intenta confirmar QR/Point con la API cuando esta configurado.
// manual = FLUS solo registra el medio de pago, util para negocios sin integracion o sin internet en la PC.
define('FLUS_MP_CASHIER_MODE', 'manual');

// Si el modo automatic falla por conexion/API, permite que el cajero registre el cobro manualmente.
define('FLUS_MP_MANUAL_FALLBACK', true);

// External ID de la caja/POS creada en Mercado Pago.
// Debe coincidir con config.qr.external_pos_id.
define('FLUS_MP_QR_EXTERNAL_POS_ID', '');

// Hybrid permite usar el QR impreso/estatico y tambien mostrar QR dinamico en pantalla.
define('FLUS_MP_QR_MODE', 'hybrid');

// Sandbox visual: texto que se muestra en la order.
define('FLUS_MP_QR_DESCRIPTION', 'Prueba FLUS QR');

// URLs devueltas al crear la caja/POS QR. Sirven para imprimir el QR estatico.
define('FLUS_MP_QR_IMAGE_URL', '');
define('FLUS_MP_QR_TEMPLATE_DOCUMENT_URL', '');
define('FLUS_MP_QR_TEMPLATE_IMAGE_URL', '');

// Terminal Mercado Pago Point asociada a esta caja FLUS.
// Se obtiene con GET /terminals/v1/list. Ej: NEWLAND_N950__...
define('FLUS_MP_POINT_TERMINAL_ID', '');
