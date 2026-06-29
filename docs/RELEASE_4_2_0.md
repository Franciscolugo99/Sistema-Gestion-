# Release 4.2.0

Fecha: 2026-06-28

Objetivo: publicar FLUS 4.2.0 incorporando el control horario de promociones
agregado despues del corte 4.1.1 y los pulidos operativos sincronizados desde
`origin/Ver-4.0.0`.

## Alcance

- Promociones permite apagar globalmente la aplicacion de descuentos.
- Promociones permite pausar descuentos todos los dias en una franja horaria.
- La pausa horaria soporta rangos que cruzan medianoche, por ejemplo de 22:00 a 06:00.
- Caja incorpora un selector de promociones para consultar y cargar productos de una promo.
- Promociones incorpora activacion/desactivacion individual desde API y UI.
- Caja recibe el estado de disponibilidad de promociones al listar promociones y recalcular carrito.
- El backend no aplica promociones cuando el estado global u horario las deja no disponibles.
- Se preservan pulidos operativos en precios, documentos comerciales, cobranzas, cuenta corriente, facturacion, tesoreria, stock, reposicion, proveedores, clientes, diagnostico e inventario.
- Se agrega cobertura smoke para disponibilidad de promociones, selector de promos y sincronizacion con Caja.

## Version

- Version visible: `4.2.0`
- Build: `2026-06-28`
- Rama base: `Ver-4.0.0`
- Fuente: `C:\xampp\htdocs\kiosco`

## Migraciones

Este corte no agrega migraciones ni modifica `install.sql`. La configuracion usa
las claves existentes de configuracion mediante `config_get` y `config_set`.

## Validaciones

- PHP lint: sin errores en 62 archivos PHP sincronizados para el corte.
- JavaScript syntax check: sin errores en 25 archivos JS sincronizados para el corte.
- Smoke fuente: `159 OK / 0 fallidas / 0 skipped`.
- Smoke de build portable: `159 total / 0 fallidas / 6 skipped` por drivers omitidos al correr PHP con `-n`.
- Compilacion Inno Setup: servidor y terminal OK.

## Artefactos

- `FLUS_Server_Setup_4.2.0.exe`
  - Tamano: `84.809.913` bytes
  - SHA256: `5862F8C2AE5A21B2F1D959EE40FBDFCF0804E42F5AE87706E2F4637A31FE03D1`
- `FLUS_Terminal_Setup_4.2.0.exe`
  - Tamano: `1.893.624` bytes
  - SHA256: `94E08B07DC1A557A83CAF2EF8376B5D83E905D0CC54FDE786135F18E9F7E8005`

Ruta:

`C:\Users\Francisco\Documents\Versiones de FLUS\FLUS_installer_V4.2.0\installer\output`

Ambos ejecutables informan version de archivo `4.2.0.0`. Actualmente no estan
firmados digitalmente.
