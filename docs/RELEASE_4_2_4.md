# Release 4.2.4

Fecha: 2026-07-23

Objetivo: publicar FLUS 4.2.4 como hotfix incremental para instalaciones
4.2.x, corrigiendo movimientos de caja en maquinas con PHP 8.0 y cerrando el
pulido del Top productos del Dashboard.

## Alcance

- Movimientos de caja evita sintaxis PHP 8.1+ (`readonly`) para mantener
  compatibilidad con el stack portable actual basado en PHP 8.0.
- `caja_movimientos.php` responde JSON cuando la llamada lo pide por query,
  header o request AJAX, incluso si falta sesion, permiso o terminal.
- El modal de Caja deja de recibir HTML completo cuando pide ultimos
  movimientos.
- Dashboard permite elegir Top productos por unidades, ventas o ganancia.
- Dashboard permite cambiar el limite visible de Top productos sin recargar la
  pagina completa.

## Version

- Version visible: `4.2.4`
- Build: `2026-07-23`
- Rama base: `Ver-4.0.0`

## Migraciones

No agrega migraciones nuevas sobre 4.2.3.

El esquema vigente se mantiene hasta:

- `044_movimientos_request_uid_idempotencia.sql`

## Preservacion durante upgrade

El instalador debe preservar:

- `app/src/config.php`
- `app/src/config.local.php`
- `app/src/config_arca.php`
- `app/src/config_mp.php`
- `app/storage/`
- `db/backups/`
- Base MySQL/MariaDB existente
- Licencia y cache cloud existentes

## Instalador

Artefactos esperados:

- `FLUS_Server_Setup_4.2.4.exe`
- `FLUS_Terminal_Setup_4.2.4.exe`

Ruta de build local:

`C:\Users\Martin\Documents\FLUS_installer_V4.2.4\installer\output`

## Validaciones requeridas

- PHP lint sobre archivos PHP modificados.
- JavaScript syntax check sobre archivos JS modificados.
- `php tests/smoke.php`.
- Build de instalador servidor y terminal.
- Upgrade local sobre una instalacion existente antes de usar en maquinas
  reales.
- Confirmar version, licencia, login, caja, movimiento manual, ultimos
  movimientos y dashboard Top productos.

## QA operativo recomendado

1. Hacer backup desde FLUS o copia externa de la instalacion.
2. Ejecutar el instalador servidor como administrador.
3. Confirmar que FLUS muestre `v4.2.4`.
4. Abrir Caja y tocar Movimientos.
5. Registrar un ingreso manual y verificar que aparezca en Ultimos movimientos.
6. Registrar un egreso manual y verificar que aparezca en Ultimos movimientos.
7. Hacer una venta normal para confirmar que caja sigue operativa.
8. Abrir Dashboard y cambiar Top productos entre unidades, ventas y ganancia.

