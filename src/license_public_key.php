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
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEA0VFFbGvWqeAIOdUcnYLg
iOWgO35kVIBoD8SZHe5vZiayI49ipzMMUa0bwdBKyLxRjsNSCGcJSGw3mxYkVFY2
bVxJ3PvsO8l63Q5P9bOV1Wc/SkDUnf2eNtIm6cp+eN3TjsLqUvwK5iFCzuQ+nzxi
Sj53Pe5zJcE3yNToxu+uSxeeSfpB8l4k3uFmZAsZN05p5Uml2A8ZVdHKtUU4U7XO
iR164PLwUEOBwFzsXXx8Q6mGYV/hDyyOdbyilLU2q2AyzZmNgQjMJ2eh8dyLFLHB
VijJJTnMN0eY73fgyEHgN8DORy1p0I6PX3D9mC2gDZElltUxwh6uEOii4uNKKqNi
C6E4nNCjOpbaK2r/tVgluxegE8TiwIwM8vteVkX4cPWlu90tdgUCmc2zHfI+6YDx
5AhXYFxnNbCCD9H/lcCsoF8QFEiKbCT/iEeCNY3AB2HWxO3N/yRXUOgemOnS4Mug
cpsYcR0AQlK+L6UN19ypWtPytoMV0lcgLDJk82oecsTtAgMBAAE=
-----END PUBLIC KEY-----
PEM;
    }
}
