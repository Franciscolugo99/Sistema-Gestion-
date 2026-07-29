# Release 4.2.7

FLUS 4.2.7 endurece la activacion y recuperacion de Cloud. La operacion local
sigue siendo offline-first y no depende de que la API este disponible.

- Version visible: `4.2.7`
- Servidor previsto: `FLUS_Server_Setup_4.2.7.exe`
- Terminal previsto: `FLUS_Terminal_Setup_4.2.7.exe`

## Cambios

- Los endpoints oficiales pasan a `https://api.flus.com.ar/license-check.php`
  y `https://api.flus.com.ar/sync-ingest.php`.
- El configurador conserva URLs personalizadas y migra solamente las URLs
  oficiales anteriores bajo `flus.com.ar/admin/api/`.
- Antes de instalar la tarea automatica valida una licencia firmada y ejecuta
  un preflight de sincronizacion sin eventos.
- Una respuesta ajena al contrato se clasifica sin exponer contenido interno;
  el bloqueo de Imunify360 usa `BOT_PROTECTION_BLOCKED`.
- Tecnico puede recuperar hasta 25 eventos fallidos por lote despues de un
  preflight correcto.

## Upgrade

El upgrade conserva base, licencia, storage, ARCA, Mercado Pago, token y datos
de sucursal. Si los nuevos endpoints no responden correctamente, restaura la
configuracion previa y no habilita la tarea automatica.

## Migraciones

No se agrega una migracion. Se reutiliza `045_cloud_sync_queue.sql`.

## Estado de publicacion

La fuente queda preparada. El subdominio alcanza el contrato PHP, responde
`401 UNAUTHORIZED` con credenciales ficticias y supero el preflight autenticado
de licencia y sincronizacion desde una instalacion 4.2.6. Antes de distribuir,
queda compilar y ejecutar el piloto de upgrade 4.2.7.
