<?php
declare(strict_types=1);

/**
 * Clave publica de licencias FLUS.
 *
 * No es secreta: permite validar las licencias firmadas por el panel interno.
 * La clave privada correspondiente vive fuera del repo, en flus-web/admin/config.
 */
if (!function_exists('flus_license_public_key_pem')) {
    function flus_license_public_key_pem(): string
    {
        return <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA4yGfGtmHWcfRMcnlkok3
q6eXIYlRTu5be3Qr6v+UJBzvBA/ywI8WEbWQPV2bKCxkfLupovGAs4SiOmWTtZ7T
oOCTD6x+5WLPz9M7S/M8y7HiEq3RRLdH6Wh1+C4SYW1Ejx2YHkKVCAjghsJ3niVa
TZ/2Vk6STKJripi5ybFgWVL1737kdO6tfkLu52N6ll2cOxlKHeUAxuOJU5HtoC8B
qs+cZMeMyBCmBR4xWuIgHI8O2mrqws9+zGknK9BjefC9dfTMZi1F67Mhni/pf+1w
3iDO6OxExupLhH19QKs6bedllC7abTy6taktIA6fKD7RcrdyZnJEoq3+JEdjWeoq
iwIDAQAB
-----END PUBLIC KEY-----
PEM;
    }
}
