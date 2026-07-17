# Release 4.2.3

Fecha: 2026-07-17

Objetivo: publicar FLUS 4.2.3 como patch incremental para instalaciones 4.2.x,
cerrando QA operativo de Caja, Mercado Pago manual y Stock sin agregar
migraciones nuevas.

## Alcance

- Corrige la consulta de stock cuando se busca texto en codigo, nombre,
  categoria o proveedor.
- Caja muestra confirmacion visible para ventas sin vuelto, incluido Mercado
  Pago con registro manual despues de cancelar QR.
- Se documenta el QA operativo realizado en navegador sobre PC de prueba.
- Se mantiene la compatibilidad de instalador/actualizador con instalaciones
  existentes.

## Version

- Version visible: `4.2.3`
- Build: `2026-07-17`
- Rama base: `Ver-4.0.0`

## Migraciones

No agrega migraciones nuevas sobre 4.2.2.

El esquema vigente se mantiene hasta:

- `044_movimientos_request_uid_idempotencia.sql`

## Preservacion durante upgrade

El instalador debe preservar:

- `app/src/config.php`
- `app/src/config.local.php`
- `app/src/config_arca.php`
- `app/storage/`
- `db/backups/`
- Base MySQL/MariaDB existente
- Licencia y cache cloud existentes

## Instalador

Artefactos esperados:

- `FLUS_Server_Setup_4.2.3.exe`
- `FLUS_Terminal_Setup_4.2.3.exe`

Ruta de build local:

`C:\Users\Martin\Documents\FLUS_installer_V4.2.3\installer\output`

## Validaciones requeridas

- PHP lint sobre archivos PHP modificados.
- JavaScript syntax check sobre archivos JS modificados.
- `php tests/smoke.php`.
- Integracion DB descartable cuando haya MySQL local disponible.
- Build de instalador servidor y terminal.
- Upgrade local sobre una instalacion existente antes de usar en las dos
  maquinas reales.
- Confirmar version, licencia, login, caja, venta de prueba, Mercado Pago manual
  y busqueda de stock.

## Estado del piloto local

Completado el 2026-07-17:

- Smoke fuente: `168 OK / 0 fallidas / 0 skipped`.
- Integracion MySQL/MariaDB descartable: baseline limpio, 44 migraciones y
  flujos comerciales criticos en verde.
- QA operativo en navegador sobre PC de prueba: caja, Mercado Pago manual,
  stock, stock consulta, inventario fisico, sucursales, terminales, tecnico,
  compras, movimientos y control de turnos sin errores 500 ni consola.

Pendiente antes de instalar en las dos maquinas reales:

- Hacer backup de la instalacion actual.
- Ejecutar el instalador servidor como administrador.
- Validar que el actualizador no cambie configuracion, licencia, storage ni base.
- Probar una venta y una busqueda de stock despues del upgrade.
