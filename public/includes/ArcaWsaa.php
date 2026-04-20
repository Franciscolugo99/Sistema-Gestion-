<?php
// public/includes/ArcaWsaa.php
declare(strict_types=1);

/**
 * ARCA/AFIP WSAA helper (LoginCms) - obtiene y cachea Token+Sign.
 *
 * Requiere:
 * - extension openssl
 * - extension soap
 * - Certificado y clave privada (X.509) emitidos por ARCA (prod/homo según ambiente)
 *
 * URLs WSAA (según manual):
 * - Homologación: https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL
 * - Producción:   https://wsaa.afip.gov.ar/ws/services/LoginCms?WSDL
 */
final class ArcaWsaa
{
    /** @var string|null */
    private static ?string $lastError = null;

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Devuelve ['token'=>string,'sign'=>string,'expires_at'=>int] o null.
     */
    public static function getTA(string $serviceId): ?array
    {
        self::$lastError = null;

        if (!extension_loaded('soap')) {
            self::$lastError = 'Extensión SOAP no habilitada en PHP (extension=soap).';
            return null;
        }
        if (!extension_loaded('openssl')) {
            self::$lastError = 'Extensión OpenSSL no habilitada en PHP (extension=openssl).';
            return null;
        }

        $cacheFile = self::cacheFile($serviceId);
        $cached = self::readCache($cacheFile);
        if ($cached && isset($cached['token'], $cached['sign'], $cached['expires_at'])) {
            // margen de 120s
            if ((int)$cached['expires_at'] > time() + 120) {
                return $cached;
            }
        }

        $env = defined('FLUS_ARCA_ENV') ? (string)FLUS_ARCA_ENV : 'prod';
        $wsaaUrl = ($env === 'homo')
            ? 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'
            : 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
        $wsaaWsdl = $wsaaUrl . '?WSDL';

        $certPath = defined('FLUS_ARCA_CERT_PEM') ? (string)FLUS_ARCA_CERT_PEM : '';
        $keyPath  = defined('FLUS_ARCA_KEY_PEM') ? (string)FLUS_ARCA_KEY_PEM : '';
        $keyPass  = defined('FLUS_ARCA_KEY_PASS') ? (string)FLUS_ARCA_KEY_PASS : '';

        if ($certPath === '' || $keyPath === '') {
            self::$lastError = 'Falta configurar rutas de certificado/clave (FLUS_ARCA_CERT_PEM / FLUS_ARCA_KEY_PEM).';
            return null;
        }
        if (!is_file($certPath) || !is_readable($certPath)) {
            self::$lastError = 'No se puede leer el certificado: ' . $certPath;
            return null;
        }
        if (!is_file($keyPath) || !is_readable($keyPath)) {
            self::$lastError = 'No se puede leer la clave privada: ' . $keyPath;
            return null;
        }

        $cms = self::buildCms($serviceId, $certPath, $keyPath, $keyPass);
        if ($cms === null) {
            // lastError ya seteado
            return null;
        }

        try {
            $ctx = stream_context_create([
                'ssl' => [
                    // En prod conviene true. En Windows a veces rompe por CA.
                    'verify_peer' => defined('FLUS_ARCA_SSL_VERIFY') ? (bool)FLUS_ARCA_SSL_VERIFY : true,
                    'verify_peer_name' => defined('FLUS_ARCA_SSL_VERIFY') ? (bool)FLUS_ARCA_SSL_VERIFY : true,
                    'allow_self_signed' => false,
                ]
            ]);

            $client = new SoapClient($wsaaWsdl, [
                'trace' => false,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'connection_timeout' => 10,
                'stream_context' => $ctx,
                'soap_version' => SOAP_1_2,
                'location' => $wsaaUrl,
            ]);

            // WSAA usa loginCms(in0)
            $resp = $client->loginCms(['in0' => $cms]);
            $taXml = (string)($resp->loginCmsReturn ?? '');
            if ($taXml === '') {
                self::$lastError = 'WSAA respondió vacío.';
                return null;
            }

            $ta = @simplexml_load_string($taXml);
            if (!$ta) {
                self::$lastError = 'No se pudo parsear TA devuelto por WSAA.';
                return null;
            }

            $token = (string)($ta->credentials->token ?? '');
            $sign  = (string)($ta->credentials->sign ?? '');

            $expStr = (string)($ta->header->expirationTime ?? '');
            $expiresAt = $expStr !== '' ? strtotime($expStr) : (time() + 60 * 60);

            if ($token === '' || $sign === '') {
                self::$lastError = 'TA inválido: faltan token/sign.';
                return null;
            }

            $out = [
                'token' => $token,
                'sign' => $sign,
                'expires_at' => (int)($expiresAt ?: (time() + 60 * 60)),
            ];

            self::writeCache($cacheFile, $out);
            return $out;

        } catch (Throwable $e) {
            self::$lastError = 'Error invocando WSAA: ' . $e->getMessage();
            return null;
        }
    }

    private static function buildCms(string $serviceId, string $certPath, string $keyPath, string $keyPass): ?string
    {
        $tmpDir = sys_get_temp_dir();
        $traFile = $tmpDir . DIRECTORY_SEPARATOR . 'flus_tra_' . bin2hex(random_bytes(4)) . '.xml';
        $signedFile = $tmpDir . DIRECTORY_SEPARATOR . 'flus_tra_' . bin2hex(random_bytes(4)) . '.p7s';

        $now = time();
        $uniqueId = (string)$now;
        $genTime = self::formatTraDateTime($now - 600);
        $expTime = self::formatTraDateTime($now + 60 * 60 * 12);

        $tra = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<loginTicketRequest version="1.0">'
            . '<header>'
            . '<uniqueId>' . htmlspecialchars($uniqueId, ENT_QUOTES, 'UTF-8') . '</uniqueId>'
            . '<generationTime>' . $genTime . '</generationTime>'
            . '<expirationTime>' . $expTime . '</expirationTime>'
            . '</header>'
            . '<service>' . htmlspecialchars($serviceId, ENT_QUOTES, 'UTF-8') . '</service>'
            . '</loginTicketRequest>';

        file_put_contents($traFile, $tra);

        $ok = @openssl_pkcs7_sign(
            $traFile,
            $signedFile,
            'file://' . $certPath,
            ['file://' . $keyPath, $keyPass],
            [],
            0
        );

        @unlink($traFile);

        if (!$ok || !is_file($signedFile)) {
            @unlink($signedFile);
            self::$lastError = 'No se pudo firmar el TRA (openssl_pkcs7_sign).';
            return null;
        }

        $signed = @file($signedFile, FILE_IGNORE_NEW_LINES);
        @unlink($signedFile);
        if (!is_array($signed) || $signed === []) {
            self::$lastError = 'No se pudo leer el CMS firmado.';
            return null;
        }

        $cms = '';
        foreach ($signed as $index => $line) {
            if ($index >= 4) {
                $cms .= $line . "\n";
            }
        }

        $cms = trim($cms);
        if ($cms === '') {
            self::$lastError = 'No se pudo extraer PKCS7 del S/MIME.';
            return null;
        }

        return $cms;
    }

    private static function formatTraDateTime(int $timestamp): string
    {
        $timezone = defined('APP_TIMEZONE') ? (string)APP_TIMEZONE : 'America/Argentina/Buenos_Aires';
        try {
            $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($timezone));
        } catch (Throwable $e) {
            $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
        }

        return $date->format('Y-m-d\TH:i:s');
    }

    private static function cacheFile(string $serviceId): string
    {
        $base = defined('FLUS_ROOT') ? (string)FLUS_ROOT : dirname(__DIR__, 2);
        $dir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'wsaa_' . preg_replace('/[^a-z0-9_\-]+/i', '_', $serviceId) . '.json';
    }

    private static function readCache(string $file): ?array
    {
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function writeCache(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
