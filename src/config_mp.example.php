<?php
// src/config_mp.example.php
// Copiar a src/config_mp.php y completar con credenciales de Mercado Pago.
declare(strict_types=1);

// Ambiente activo. APP_USR puede pertenecer tanto a prueba como a produccion,
// por eso FLUS no intenta deducirlo desde el prefijo del token.
define('FLUS_MP_ENVIRONMENT', 'test');

// Access Token del ambiente activo.
define('FLUS_MP_ACCESS_TOKEN', '');

// Clave secreta generada en Mercado Pago > Webhooks para el ambiente activo.
define('FLUS_MP_WEBHOOK_SECRET', '');
define('FLUS_MP_WEBHOOK_URL', '');

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

// Texto que se muestra en la order. Evitar referencias a prueba en produccion.
define('FLUS_MP_QR_DESCRIPTION', 'Cobro FLUS QR');

// URLs devueltas al crear la caja/POS QR. Sirven para imprimir el QR estatico.
define('FLUS_MP_QR_IMAGE_URL', '');
define('FLUS_MP_QR_TEMPLATE_DOCUMENT_URL', '');
define('FLUS_MP_QR_TEMPLATE_IMAGE_URL', '');

// Terminal Mercado Pago Point asociada a esta caja FLUS.
// Se obtiene con GET /terminals/v1/list. Ej: NEWLAND_N950__...
define('FLUS_MP_POINT_TERMINAL_ID', '');
