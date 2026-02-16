<?php
/**
 * CONFIGURACIÓN AFIP/ARCA - FACTURACIÓN ELECTRÓNICA
 * =====================================================
 * 
 * Copiá este archivo a src/config_arca.php y completá los datos.
 * 
 * PASOS PARA CONFIGURAR:
 * 
 * 1. OBTENER CERTIFICADO DIGITAL
 *    - Entrá a https://www.afip.gob.ar con tu CUIT y clave fiscal
 *    - Andá a "Administrador de Relaciones de Clave Fiscal"
 *    - Buscá "ARCA - Autogestión de certificados"
 *    - Generá un nuevo certificado para "wsfe" (factura electrónica)
 *    - Descargá el certificado (.crt) y la clave privada (.key)
 * 
 * 2. CONVERTIR A FORMATO PEM
 *    Si te dan archivos .crt y .key, convertirlos:
 *    
 *    openssl x509 -in certificado.crt -out certificado.pem -outform PEM
 *    openssl rsa -in clave.key -out clave.pem -outform PEM
 * 
 * 3. UBICAR LOS ARCHIVOS
 *    Guardá los archivos .pem en la carpeta /storage/certs/ (créala si no existe)
 *    Asegurate de que tengan permisos de lectura para PHP
 * 
 * 4. AUTORIZAR EL SERVICIO
 *    En AFIP, autorizá el servicio "wsfe" para tu certificado
 * 
 * 5. COMPLETAR ESTE ARCHIVO
 *    Definí las constantes con tus datos
 * 
 * IMPORTANTE:
 * - NUNCA subas los certificados a repositorios públicos
 * - Agregá /storage/certs/ al .gitignore
 */

// =====================================================
// AMBIENTE: 'homo' para pruebas, 'prod' para producción
// =====================================================
// Usá 'homo' mientras probás, cambiá a 'prod' cuando estés listo
define('FLUS_ARCA_ENV', 'homo');

// =====================================================
// CUIT DEL EMISOR (tu CUIT, sin guiones)
// =====================================================
define('FLUS_ARCA_CUIT', '20123456789');

// =====================================================
// CUIT REPRESENTADA (generalmente igual al CUIT del emisor)
// =====================================================
// Solo es diferente si facturás en nombre de otro contribuyente
define('FLUS_ARCA_REP_CUIT', '20123456789');

// =====================================================
// RUTAS A LOS CERTIFICADOS
// =====================================================
// Rutas absolutas o relativas desde la raíz del proyecto
define('FLUS_ARCA_CERT_PEM', __DIR__ . '/../storage/certs/certificado.pem');
define('FLUS_ARCA_KEY_PEM', __DIR__ . '/../storage/certs/clave.pem');

// =====================================================
// PASSWORD DE LA CLAVE PRIVADA (si tiene)
// =====================================================
// Si tu clave no tiene password, dejalo vacío
define('FLUS_ARCA_KEY_PASS', '');

// =====================================================
// VERIFICACIÓN SSL
// =====================================================
// En producción debe ser true
// En desarrollo, si tenés problemas de SSL, podés poner false temporalmente
define('FLUS_ARCA_SSL_VERIFY', true);

// =====================================================
// PRUEBA RÁPIDA
// =====================================================
// Para probar si la configuración está bien, ejecutá desde consola:
// 
// php -r "
//   require 'src/config_arca.php';
//   require 'public/includes/ArcaWsaa.php';
//   \$ta = ArcaWsaa::getTA('wsfe');
//   if (\$ta) {
//     echo 'Conexión exitosa! Token expira: ' . date('Y-m-d H:i:s', \$ta['expires_at']);
//   } else {
//     echo 'Error: ' . ArcaWsaa::getLastError();
//   }
// "