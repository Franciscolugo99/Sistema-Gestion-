# Release 4.1.1

Fecha: 2026-06-11

Objetivo: publicar un parche operativo sobre FLUS 4.1.0 con mejoras de Caja,
pagos divididos, compatibilidad de stock y anulacion de compras.

## Alcance

- Caja integra resumen y acciones en un unico pie responsive.
- Se elimina informacion duplicada entre ticket y panel de pagos.
- Los temas claro y oscuro comparten bordes, radios y espaciado coherentes.
- Los montos de pagos divididos siguen editables al cambiar medios repetidamente.
- Mercado Pago QR y Point validan sus importes parciales.
- FLUS incluye un asistente guiado para crear una sucursal y POS QR con credenciales Mercado Pago de prueba.
- Caja, Ventas y reimpresiones usan una configuracion central de tickets.
- El ticket termico admite logo y visibilidad independiente de caja y cajero.
- Compras restaura correctamente el costo previo cuando un producto se repite.
- La migracion `040` repara snapshots de stock en instalaciones legacy.
- Las instalaciones limpias comienzan la primera apertura de caja en ID `1`.

## Version

- Version visible: `4.1.1`
- Build: `2026-06-11`
- Rama base: `Ver-4.0.0`
- Fuente: `C:\xampp\htdocs\kiosco`

## Validaciones

- PHP lint: sin errores en los archivos PHP modificados.
- JavaScript syntax check: sin errores.
- CSS: llaves balanceadas y `git diff --check` sin errores.
- Smoke fuente: `142 OK / 0 fallidas / 0 skipped`.
- Integracion DB: instalacion limpia, 42 migraciones y flujos criticos OK.
- Migraciones `039` a `042` cubiertas por la instalacion limpia y el runner DB.

## Artefactos historicos

Los siguientes ejecutables fueron compilados antes de los cambios finales del
11 de junio. Sus hashes se conservan como referencia, pero deben regenerarse
antes de distribuir esta revision.

- Servidor: `FLUS_Server_Setup_4.1.1.exe`
  - Tamano: `83.996.205` bytes
  - SHA256: `9F9A6800F9BA0338D1ADCF82E3843C658248CBA064F0B6BD14E0824D7E816E35`
- Terminal: `FLUS_Terminal_Setup_4.1.1.exe`
  - Tamano: `1.893.629` bytes
  - SHA256: `D8616E1582D866120EC1E99037C3731ADBE40B2A40D61CF89B466D754D9BE4C8`

Ruta:

`C:\Users\Francisco\Documents\Versiones de FLUS\FLUS_intaller_V4.1.1\installer\output`

Ambos ejecutables informan version de archivo `4.1.1.0`. Actualmente no estan
firmados digitalmente.

## QA operativo pendiente

- Probar pagos divididos con credenciales Mercado Pago de prueba o entorno controlado.
- Confirmar instalacion/upgrade en una PC limpia antes de desplegar a produccion.
