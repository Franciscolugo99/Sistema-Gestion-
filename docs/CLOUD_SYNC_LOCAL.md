# Cloud Sync Local

FLUS local es offline-first: las ventas y movimientos operativos no dependen de internet.

Esta base agrega una cola local (`cloud_sync_queue`) y un envio manual/seguro hacia FLUS Web. Por ahora se envia el evento `sale.created` despues de registrar una venta local correctamente. Si la nube no responde, el evento queda pendiente para reintentar.

## Configuracion

En `src/config.php` o variables de entorno:

```php
define('FLUS_LICENSE_CLOUD_URL', 'https://tu-dominio.com/flus-web/admin/api/license-check.php');
define('FLUS_LICENSE_CLOUD_TOKEN', 'token-compartido-con-flus-web');

// Opcional: si no se define, FLUS deriva /admin/api/sync-ingest.php desde FLUS_LICENSE_CLOUD_URL.
define('FLUS_CLOUD_SYNC_URL', 'https://tu-dominio.com/flus-web/admin/api/sync-ingest.php');
define('FLUS_CLOUD_SYNC_TOKEN', 'token-compartido-con-flus-web');

define('FLUS_CLOUD_BRANCH_CODE', 'central');
define('FLUS_CLOUD_BRANCH_NAME', 'Casa central');
define('FLUS_CLOUD_INSTALLATION_NAME', 'Caja mostrador');
```

El token debe coincidir con `license.cloud_api_token` en `flus-web/admin/config/config.php`.

## Migracion requerida

Aplicar:

```powershell
& "C:\xampp82\php\php.exe" scripts\migrate.php
```

La migracion nueva es `045_cloud_sync_queue.sql`.

## Prueba rapida

1. Verificar en FLUS Web que la licencia este activa y que exista el endpoint:
   `https://tu-dominio.com/flus-web/admin/api/sync-ingest.php`
2. En FLUS local, entrar a `Tecnico`.
3. Aplicar migraciones pendientes si aparece la migracion `045`.
4. Hacer una venta normal desde caja.
5. Volver a `Tecnico` y presionar `Enviar pendientes`.
6. En FLUS Web, abrir `Sucursales cloud` y confirmar que aparezca la instalacion y el evento.

Tambien se puede empujar desde consola:

```powershell
& "C:\xampp82\php\php.exe" scripts\cloud_sync_push.php 50
```

## Diagnostico

- `TOKEN_MISSING`: falta token local o no coincide con FLUS Web.
- `URL_MISSING`: falta `FLUS_CLOUD_SYNC_URL` y no se pudo derivar desde licencia cloud.
- `LICENSE_KEY_MISSING`: no hay licencia local con clave FLUS.
- `SCHEMA_MISSING`: falta aplicar la migracion `045`.
- `HTTP_STATUS_401`: token incorrecto.
- `HTTP_STATUS_403`: licencia suspendida, vencida o cliente inactivo en FLUS Web.
