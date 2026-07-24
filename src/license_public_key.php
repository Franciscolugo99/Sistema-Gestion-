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
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAqBKJr0XTtM78kI/MLTwQ
ahCQG47CQyQxyO5Jq1sB9UinI4a1cOZdK44COLOkJA/MrhhoXn+i+5qfGWJRJZDT
Mde9KOnx/mtzctlHDwgr9NziraKhh0eBKRR9OOYB+ThEm2upe6MLEoW8y2grAuDx
EfVaZ4oqa98U8uPkBP19Nd3bnc1K0cdr08KBFC3i4Uewi2nTh+Lg4FTl0kQMLZ+C
5/2GbYEDGZovUlel/5bEh4Fr7HVy5xKWGMjBVUsZYz/H/jnWO3wm4uZiUFfi74TT
QR0gMLL0JiMlUbglFIOsv5iwLoFrZZrPAXHp0L3gZvrjZ29A3Z6YWh2Np0UqrfAZ
DZ9G7We4Q1efDnzbmCXO7xluJp0M+W+xtGcj0DUNDbEFuwNxVTzB9/iSPxTVtvO6
PE22njejxY5r8cLV2EI3yQusebGTVFEgC0LpyA1SuK8bWX23MJAQmSrWaD25Wyx1
raRkrrGKpt0FltkaBBgzCKruZes0ygIa+UKjSApgYfv1AgMBAAE=
-----END PUBLIC KEY-----
PEM;
    }
}
