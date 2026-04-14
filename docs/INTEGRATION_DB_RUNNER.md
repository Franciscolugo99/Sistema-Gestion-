# Runner de integracion DB

Este runner es el chequeo operativo para releases que tocan baseline, migraciones o flujos fiscales/comerciales. No reemplaza el smoke local: lo complementa con una base MySQL/MariaDB temporal real.

## Cuando correrlo

- Antes de publicar una release.
- Despues de cambios en `install.sql`, `migrations/`, facturacion, notas de credito, cobranzas, recibos o cuenta corriente.
- Despues de resolver conflictos de merge sobre esquema o datos fiscales/comerciales.
- Antes de investigar bugs de upgrade donde haya dudas entre baseline limpio y migraciones.

## Prerequisitos

- PHP CLI con `pdo_mysql` habilitado.
- MySQL/MariaDB local o descartable.
- Usuario de DB con permisos para `CREATE DATABASE` y `DROP DATABASE`.
- Ejecutarlo desde la raiz del repo.
- No apuntar este runner a produccion ni a una base con datos reales.

## Comando definitivo

```powershell
$env:FLUS_TEST_DB='1'
$env:FLUS_TEST_DB_HOST='127.0.0.1'
$env:FLUS_TEST_DB_PORT='3306'
$env:FLUS_TEST_DB_USER='root'
$env:FLUS_TEST_DB_PASS=''
C:\xampp\php\php.exe tests\integration_db.php
```

Por defecto crea una base temporal con prefijo `flus_it_` y la borra al terminar. Si hace falta inspeccionarla despues de una falla:

```powershell
$env:FLUS_TEST_DB_KEEP='1'
```

Si se define `FLUS_TEST_DB_NAME`, debe empezar con `flus_it_` y contener solo letras, numeros o guiones bajos.

## Que valida

- Importacion completa de `install.sql`.
- Aplicacion de todas las migraciones hasta `027_reclasificar_arca_no_responde_transitorio.sql`.
- Columnas criticas de inventario fisico y estado fiscal.
- Venta POS con item, pago mixto, descuento de stock y movimiento de stock.
- Facturacion no remota con documento comercial, factura, item fiscal y evento ARCA local.
- NC total y NC parcial no remotas vinculadas a la factura original.
- Recovery fiscal no remoto desde `ERROR_TRANSITORIO` hasta `RECUPERADA`.
- Cobranza y recibo para una venta facturada, con idempotencia por `external_key`.
- Cuenta corriente con cargo, pago idempotente por `request_uid`, cobranza, recibo aplicado y saldo recalculado en cero.

## Si falla

1. No publicar la release.
2. Leer el primer `[FAIL]`; normalmente apunta a una migracion, una diferencia entre baseline y migraciones, o una regresion fiscal/comercial.
3. Repetir con `FLUS_TEST_DB_KEEP=1` si hace falta inspeccionar la base temporal.
4. Corregir el schema o el flujo, correr `C:\xampp\php\php.exe tests\smoke.php` y volver a correr este runner.
5. Si la base quedo conservada, borrarla manualmente cuando termine la inspeccion.

La salida esperada termina con:

```text
[OK] DB integration check finished.
[OK] dropped flus_it_...
```
