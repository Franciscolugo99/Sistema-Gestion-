# Release 4.2.6

FLUS 4.2.6 completa el enlace operativo entre Caja y el portal Cloud. La
actualizacion es incremental y no requiere una migracion nueva.

- Version visible: `4.2.6`
- Servidor: `FLUS_Server_Setup_4.2.6.exe`
- Terminal: `FLUS_Terminal_Setup_4.2.6.exe`

## Cambios

- Apertura de caja encola `cash.opened` despues de confirmar la operacion local.
- Cierre de caja encola `cash.closed` despues del commit, incluyendo importes y
  diferencia de arqueo.
- Cada evento usa una clave idempotente `cash-session:<id>:<accion>`.
- El envio sigue siendo asincronico: una falla de internet no bloquea Caja.
- El instalador conserva base, licencia, storage, ARCA y Mercado Pago al
  actualizar una instalacion existente.

## Actualizacion piloto

1. Verificar el SHA256 del instalador servidor.
2. Cerrar FLUS y ejecutar el setup como administrador.
3. Confirmar que se creo `C:\FLUS\upgrade_backups\FLUS_pre_4.2.6_*`.
4. Verificar servicios `FLUS_Apache` y `FLUS_MariaDB` en ejecucion.
5. Ingresar a Tecnico y confirmar migraciones al dia y Cloud operativo.
6. Abrir y cerrar una caja de prueba; comprobar que la operacion local funciona.
7. Verificar en el portal que el estado aparece tras el siguiente envio Cloud.

## Reversion

Ante una falla, no borrar datos ni reinstalar desde cero. Conservar el mensaje,
el log de upgrade y la carpeta de backup previa para diagnostico y recuperacion.

## Migraciones

No se agrega una migracion. Se reutiliza la cola idempotente creada por
`045_cloud_sync_queue.sql`.
