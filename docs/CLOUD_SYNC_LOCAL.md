# Cloud Sync Local

FLUS local es offline-first: las ventas, caja y stock operativo no dependen de
internet.

Esta base agrega una cola local (`cloud_sync_queue`) y un envio automatico y
seguro hacia FLUS Web. Envia ventas y stock de solo lectura para el portal
cloud. Si la nube no responde, el evento queda pendiente y se reintenta con
espera progresiva sin bloquear Caja.

## Configuracion

En `src/config.php` o variables de entorno:

```php
define('FLUS_LICENSE_CLOUD_URL', 'https://tu-dominio.com/flus-web/admin/api/license-check.php');
define('FLUS_LICENSE_CLOUD_TOKEN', 'token-compartido-con-flus-web');

// Opcional: si no se define, FLUS deriva /admin/api/sync-ingest.php desde FLUS_LICENSE_CLOUD_URL.
define('FLUS_CLOUD_SYNC_URL', 'https://api.flus.com.ar/sync-ingest.php');
define('FLUS_CLOUD_SYNC_TOKEN', 'token-compartido-con-flus-web');
define('FLUS_CLOUD_SYNC_ENABLED', true);
define('FLUS_CLOUD_SYNC_STOCK_SNAPSHOT_INTERVAL_SEC', 900);

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

1. En FLUS Web, crear el cliente y la licencia con plan `Cloud mensual` o
   `Cloud multi-sucursal`. Una licencia `Local mensual/anual` no acepta eventos
   de sincronizacion.
2. Confirmar que `license.cloud_api_token` este configurado en
   `flus-web/admin/config/config.local.php`.
3. En la PC del comercio, instalar FLUS y cargar la licencia correspondiente.
4. En `src/config.php`, configurar como minimo:

```php
define('FLUS_LICENSE_CLOUD_URL', 'https://tu-dominio.com/flus-web/admin/api/license-check.php');
define('FLUS_LICENSE_CLOUD_TOKEN', 'token-cloud-compartido');
define('FLUS_CLOUD_SYNC_ENABLED', true);
define('FLUS_CLOUD_SYNC_URL', 'https://api.flus.com.ar/sync-ingest.php');
define('FLUS_CLOUD_BRANCH_CODE', 'central');
define('FLUS_CLOUD_BRANCH_NAME', 'Casa central');
define('FLUS_CLOUD_INSTALLATION_NAME', 'Caja principal');
```

5. Ejecutar migraciones locales.
6. Entrar al panel tecnico de FLUS y verificar que `Sincronizacion cloud` figure
   operativa, con endpoint configurado.
7. Hacer una venta de prueba y esperar el envio automatico de la tarea local.
8. En FLUS Web, revisar `Sucursales cloud`.
9. Si el cliente va a mirar desde el celular, crearle un usuario de portal en
   FLUS Web y probar `portal/login.php`.
10. Confirmar el primer snapshot automatico de stock. `Enviar stock actual`
    queda disponible solo como accion operativa manual.

El alta queda correcta cuando FLUS local vende aunque no haya internet, deja los
eventos pendientes y los envia despues sin duplicarlos.

## Activacion recomendada en una PC instalada

El instalador 4.2.5 preserva `src/config.php`, `storage/`, licencia, base, ARCA
y Mercado Pago. Si detecta una licencia Cloud con configuracion valida, instala
o repara automaticamente la tarea local. Si falta URL o token, abre la
reparacion guiada sin tocar datos operativos.

Para una configuracion manual o una instalacion anterior, usar:

```powershell
& "C:\FLUS\stack\php\php.exe" C:\FLUS\app\scripts\migrate.php
PowerShell -ExecutionPolicy Bypass -NoProfile -File C:\FLUS\app\scripts\cloud_sync_setup.ps1 -Root C:\FLUS\app -BranchCode central -BranchName "Casa central" -InstallationName "Caja principal"
```

Desde el menu de Windows tambien se puede abrir `FLUS > Servidor >
Configuracion > Configurar Cloud`. Ese acceso ejecuta el mismo asistente y evita
tener que escribir la ruta completa.

El asistente pide el token cloud oculto, hace backup de `src/config.php`,
configura endpoints productivos de `flus.com.ar`, valida URLs y sintaxis PHP,
aplica migraciones pendientes y comprueba que URL y token hayan quedado
persistidos. Si falla la verificacion final, restaura el `config.php` anterior.

Para recuperar el token, entrar con un administrador en FLUS Web y abrir
`Configuracion cloud`. El valor solo se revela despues de confirmar la
contraseña actual. No debe enviarse por email, chat ni incluirse en comandos o
logs.

El acceso directo no bloquea esperando un envio. La tarea procesa la cola y el
primer snapshot en segundo plano cuando la configuracion queda valida.

Para diagnosticar una instalacion sin modificarla:

```powershell
PowerShell -ExecutionPolicy Bypass -NoProfile -File C:\FLUS\app\scripts\cloud_sync_setup.ps1 -Root C:\FLUS\app -StatusOnly
```

Para desactivar Cloud sin borrar ventas ni licencia:

```powershell
PowerShell -ExecutionPolicy Bypass -NoProfile -File C:\FLUS\app\scripts\cloud_sync_setup.ps1 -Root C:\FLUS\app -DisableCloud
```

Despues de activarlo, entrar a `Tecnico > Sincronizacion cloud` y confirmar:

- estado `Automatica`;
- endpoint `Configurado`;
- tarea local activa y ultimo intento visible;
- `Enviar stock actual` aparece en FLUS Web bajo el cliente y sucursal correctos.

## Migracion requerida

Aplicar:

```powershell
& "C:\xampp82\php\php.exe" scripts\migrate.php
```

Las migraciones Cloud requeridas llegan hasta `046_cloud_command_receipts.sql`.

## Prueba rapida

1. Verificar en FLUS Web que la licencia este activa y que exista el endpoint:
   `https://api.flus.com.ar/sync-ingest.php`
2. En FLUS local, entrar a `Tecnico`.
3. Aplicar migraciones pendientes hasta la migracion `046`.
4. Hacer una venta normal desde caja.
5. Volver a `Tecnico` y confirmar que la cola se envio automaticamente.
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

El instalador registra `FLUS_CloudSync` al iniciar Windows y cada minuto. Para
ejecutar un tick diagnostico manual:

```powershell
& "C:\xampp82\php\php.exe" scripts\cloud_sync_tick.php 250 50
```

El worker ejecuta un latido liviano cada 5 minutos aunque no existan eventos
pendientes. Ese preflight actualiza la presencia, version y nombre de la
instalacion en el portal sin crear ventas ni movimientos de stock.

Ese tick usa locks local y MySQL, envia pendientes y prepara un snapshot
completo de stock solo cuando paso
`FLUS_CLOUD_SYNC_STOCK_SNAPSHOT_INTERVAL_SEC`.

El script de consola carga el mismo contexto local que la web, incluyendo `FLUS_ROOT`, `storage/license.json` y el ID de instalacion cloud.

## Datos enviados

Eventos actuales:

- `sale.created`: resumen de venta, cajero, medio de pago e items.
- `sale.annulled`: anulacion total o parcial, importe anulado y estado vigente.
- `stock.updated`: stock de productos afectados por una venta.
- `stock.snapshot`: stock actual enviado automaticamente o bajo accion manual.

El mismo worker consulta comandos remotos en `command-poll.php`. Para un cambio
de precio, FLUS bloquea el producto, compara el precio esperado, actualiza el
precio y registra el historial dentro de una unica transaccion. Luego confirma
el resultado en `command-ack.php`. Los recibos locales impiden aplicar dos veces
la misma orden ante reintentos o perdida de conexion.

El stock enviado es de solo lectura para FLUS Web. Incluye codigo, nombre,
categoria, marca, precio de venta, stock, stock minimo, unidad y estado. No se
envia costo ni margen.

## Importacion de ventas anteriores a Cloud

La importacion historica solo agrega eventos a la cola. No crea ventas, no
registra pagos y no mueve stock. Primero ejecutar una vista previa:

```powershell
& "C:\FLUS\stack\php\php.exe" "C:\FLUS\app\scripts\cloud_sync_sales_backfill.php" --from=2026-01-01 --to=2026-07-31 --limit=100
```

Si el resumen es correcto, encolar el mismo lote de forma explicita:

```powershell
& "C:\FLUS\stack\php\php.exe" "C:\FLUS\app\scripts\cloud_sync_sales_backfill.php" --enqueue --from=2026-01-01 --to=2026-07-31 --after-id=0 --limit=100
```

Repetir con el `last_id` informado como nuevo `--after-id`. Las ventas que ya
existen en `cloud_sync_queue` se omiten por su `request_uid` o `venta_id`. El
script no llama al endpoint remoto; la tarea `FLUS_CloudSync` envia luego los
eventos con sus reintentos normales.

## Diagnostico

- `TOKEN_MISSING`: falta token local o no coincide con FLUS Web.
- `URL_MISSING`: falta `FLUS_CLOUD_SYNC_URL` y no se pudo derivar desde licencia cloud.
- `LICENSE_KEY_MISSING`: no hay licencia local con clave FLUS.
- `SCHEMA_MISSING`: faltan migraciones Cloud; para comandos remotos se requiere
  `046_cloud_command_receipts.sql`.
- `HTTP_STATUS_401`: token incorrecto, ausente o no recibido por Apache/PHP.
- `HTTP_STATUS_403`: licencia suspendida, vencida, cliente inactivo o plan local.
- `LICENSE_CLOUD_DISABLED`: la licencia existe, pero no tiene plan cloud.
