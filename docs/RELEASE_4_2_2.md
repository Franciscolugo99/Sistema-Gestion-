# Release 4.2.2

Fecha: 2026-07-11

Objetivo: publicar FLUS 4.2.2 como actualizacion incremental y compatible para
instalaciones 4.2.0 y 4.2.1, preservando base, configuracion, licencia, uploads y
auditoria historica.

## Alcance

- Endurecimiento de licencias cloud y acceso a la pantalla de renovacion en
  modo bloqueado.
- Menor exposicion de errores internos en API, facturacion y terminales.
- Control administrativo de sesiones y cambio obligatorio de la clave inicial.
- Reparacion del stack portable de PHP/Apache para OpenSSL durante upgrades.
- Mejor deteccion de la base configurada por el actualizador.
- Validacion de backups y restauraciones con esquema vigente.
- Busqueda de stock con productos activos priorizados sobre registros inactivos.
- Idempotencia persistente para movimientos manuales de caja y ajustes de stock.
- Serializacion de ventas, movimientos y cierre sobre la sesion de caja.
- Bloqueo HTTP de archivos internos cuando el repo se sirve desde su raiz.
- Consolidacion de los cambios operativos posteriores a 4.2.1.

## Version

- Version visible: `4.2.2`
- Build: `2026-07-11`
- Rama base: `Ver-4.0.0`

## Migraciones

Agrega una migracion incremental y mantiene el esquema versionado hasta:

- `044_movimientos_request_uid_idempotencia.sql`

La migracion agrega `request_uid` nullable e indices unicos a
`caja_movimientos` y `movimientos_stock`. Las filas historicas conservan
`NULL` y no se reescriben.

El upgrade debe detectar el nombre real desde `app/src/config.php`. No debe
aplicar migraciones a una base fallback si la instalacion ya esta configurada.

## Preservacion durante upgrade

- `app/src/config.php`
- `app/src/config.local.php`
- `app/src/config_arca.php`
- `app/storage/`
- `db/backups/`
- Base MySQL/MariaDB existente
- Licencia y cache cloud existentes

## Instalador

Artefactos esperados:

- `FLUS_Server_Setup_4.2.2.exe`
- `FLUS_Terminal_Setup_4.2.2.exe`

Ruta de build:

`C:\Users\Francisco\Documents\Versiones de FLUS\FLUS_installer_V4.2.2\release\installer\output`

## Validaciones requeridas

- PHP lint sobre archivos PHP modificados.
- `php tests/smoke.php`.
- Build de instalador servidor y terminal.
- Backup previo y upgrade local 4.2.0 a 4.2.2 sobre `C:\FLUS`.
- Confirmar version, base seleccionada, licencia, login, caja, venta de prueba y
  busqueda de stock.
- Confirmar que reintentar el actualizador no duplica migraciones ni altera
  datos historicos.

## Estado del piloto local

Completado el 2026-07-11:

- PHP lint sin errores en los archivos PHP modificados.
- Smoke fuente: `167 OK / 0 fallidas / 0 skipped`.
- Smoke del payload portable: `159 OK / 0 fallidas / 7 skipped` por extensiones
  deshabilitadas al ejecutar PHP con `-n`.
- Integracion MySQL/MariaDB descartable: baseline limpio, 44 migraciones y
  flujos comerciales criticos en verde.
- Instaladores servidor y terminal compilados correctamente.
- Backup previo de `flus_db`, configuracion y licencia generado.

Pendiente antes de distribuir:

- Ejecutar el instalador servidor con permisos de administrador sobre la
  instalacion local `4.2.0`.
- Validar el upgrade y una segunda ejecucion idempotente sobre `C:\FLUS`.
- Probar login, caja, venta, doble envio y busqueda de stock desde navegador.
