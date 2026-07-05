# Release 4.2.1

Fecha: 2026-07-05

Objetivo: publicar FLUS 4.2.1 con validacion cloud de licencias integrada al
instalador, manteniendo el cache offline y la compatibilidad de instalaciones
existentes.

## Alcance

- FLUS conserva la licencia offline firmada en `storage/license.json`.
- El instalador de servidor deja preparada la configuracion cloud para consultar
  la API de licencias de FLUS Admin/Wiros.
- La validacion cloud puede suspender, vencer, revocar o reactivar la
  instalacion sin depender de una conexion permanente.
- Si internet falla, FLUS usa la ultima respuesta firmada dentro de la ventana
  de gracia configurada.
- Se mantiene el modo sin cloud cuando `FLUS_LICENSE_CLOUD_URL` no esta
  configurado.
- Se incorporan los cambios de 4.2.0: promociones, caja, idempotencia de ventas
  y migraciones hasta `043_ventas_request_uid_idempotencia.sql`.

## Version

- Version visible: `4.2.1`
- Build: `2026-07-05`
- Rama base: `Ver-4.0.0`

## Migraciones

No agrega migraciones nuevas sobre 4.2.0. La base sigue alineada hasta:

- `043_ventas_request_uid_idempotencia.sql`

## Instalador

Artefactos esperados:

- `FLUS_Server_Setup_4.2.1.exe`
- `FLUS_Terminal_Setup_4.2.1.exe`

Ruta de build:

`C:\Users\Francisco\Documents\Versiones de FLUS\FLUS_installer_V4.2.1\installer\output`

## Validaciones requeridas

- PHP lint sobre archivos PHP modificados.
- `php tests/smoke.php`.
- Build del instalador servidor y terminal.
- Prueba de instalacion/actualizacion en maquina local antes de distribuir.
