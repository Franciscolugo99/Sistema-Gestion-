# Cloud Sync Local

FLUS local es offline-first: las ventas, caja y stock operativo no dependen de
internet.

Esta base agrega una cola local (`cloud_sync_queue`) y un envio manual/seguro
hacia FLUS Web. Por ahora envia ventas y stock de solo lectura para el portal
cloud. Si la nube no responde, el evento queda pendiente para reintentar.

## Configuracion

En `src/config.php` o variables de entorno:

```php
define('FLUS_LICENSE_CLOUD_URL', 'https://tu-dominio.com/flus-web/admin/api/license-check.php');
define('FLUS_LICENSE_CLOUD_TOKEN', 'token-compartido-con-flus-web');

// Opcional: si no se define, FLUS deriva /admin/api/sync-ingest.php desde FLUS_LICENSE_CLOUD_URL.
define('FLUS_CLOUD_SYNC_URL', 'https://tu-dominio.com/flus-web/admin/api/sync-ingest.php');
define('FLUS_CLOUD_SYNC_TOKEN', 'token-compartido-con-flus-web');
define('FLUS_CLOUD_SYNC_ENABLED', true);

define('FLUS_CLOUD_BRANCH_CODE', 'central');
define('FLUS_CLOUD_BRANCH_NAME', 'Casa central');
define('FLUS_CLOUD_INSTALLATION_NAME', 'Caja mostrador');
```

El token debe coincidir con `license.cloud_api_token` en `flus-web/admin/config/config.php`.
FLUS envia el token por `Authorization: Bearer` y tambien por `X-Flus-Cloud-Token` para cubrir Apache/XAMPP en Windows, donde el header `Authorization` puede no llegar a PHP.

Si `FLUS_CLOUD_SYNC_TOKEN` queda vacio, FLUS usa `FLUS_LICENSE_CLOUD_TOKEN`.
Para instalaciones nuevas conviene definir `FLUS_CLOUD_SYNC_URL` explicitamente,
asi no depende de derivar la ruta desde el endpoint de licencia.

## Alta de una instalacion nueva

1. En FLUS Web, crear el cliente y la licencia.
2. Confirmar que `license.cloud_api_token` este configurado en
   `flus-web/admin/config/config.local.php`.
3. En la PC del comercio, instalar FLUS y cargar la licencia correspondiente.
4. En `src/config.php`, configurar como minimo:

```php
define('FLUS_LICENSE_CLOUD_URL', 'https://tu-dominio.com/flus-web/admin/api/license-check.php');
define('FLUS_LICENSE_CLOUD_TOKEN', 'token-cloud-compartido');
define('FLUS_CLOUD_SYNC_ENABLED', true);
define('FLUS_CLOUD_SYNC_URL', 'https://tu-dominio.com/flus-web/admin/api/sync-ingest.php');
define('FLUS_CLOUD_BRANCH_CODE', 'central');
define('FLUS_CLOUD_BRANCH_NAME', 'Casa central');
define('FLUS_CLOUD_INSTALLATION_NAME', 'Caja principal');
```

5. Ejecutar migraciones locales.
6. Entrar al panel tecnico de FLUS y verificar que `Sincronizacion cloud` figure
   operativa, con endpoint configurado.
7. Hacer una venta de prueba y presionar `Enviar pendientes`.
8. En FLUS Web, revisar `Sucursales cloud`.
9. Si el cliente va a mirar desde el celular, crearle un usuario de portal en
   FLUS Web y probar `portal/login.php`.
10. Presionar `Enviar stock actual` desde el panel tecnico para cargar el primer
    snapshot de inventario en el portal.

El alta queda correcta cuando FLUS local vende aunque no haya internet, deja los
eventos pendientes y los envia despues sin duplicarlos.

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
6. En FLUS Web, abrir `Sucursales cloud` y confirmar que aparezca la instalacion
   y el evento.
7. En FLUS local, presionar `Enviar stock actual`.
8. En el portal del cliente, confirmar que aparezca `Stock por sucursal`.

Tambien se puede empujar desde consola:

```powershell
& "C:\xampp82\php\php.exe" scripts\cloud_sync_push.php 50
```

Para preparar y enviar stock desde consola:

```powershell
& "C:\xampp82\php\php.exe" scripts\cloud_sync_stock_snapshot.php 250
```

El script de consola carga el mismo contexto local que la web, incluyendo `FLUS_ROOT`, `storage/license.json` y el ID de instalacion cloud.

## Datos enviados

Eventos actuales:

- `sale.created`: resumen de venta, medio de pago e items.
- `stock.updated`: stock de productos afectados por una venta.
- `stock.snapshot`: stock actual enviado manualmente desde tecnico o consola.

El stock enviado es de solo lectura para FLUS Web. Incluye codigo, nombre,
categoria, marca, precio de venta, stock, stock minimo, unidad y estado. No se
envia costo ni margen.

## Diagnostico

- `TOKEN_MISSING`: falta token local o no coincide con FLUS Web.
- `URL_MISSING`: falta `FLUS_CLOUD_SYNC_URL` y no se pudo derivar desde licencia cloud.
- `LICENSE_KEY_MISSING`: no hay licencia local con clave FLUS.
- `SCHEMA_MISSING`: falta aplicar la migracion `045`.
- `HTTP_STATUS_401`: token incorrecto, ausente o no recibido por Apache/PHP.
- `HTTP_STATUS_403`: licencia suspendida, vencida o cliente inactivo en FLUS Web.
