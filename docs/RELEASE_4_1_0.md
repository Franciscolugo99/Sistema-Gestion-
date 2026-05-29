# Release 4.1.0

Fecha base: 2026-05-29

Objetivo: dejar FLUS 4.1.0 como corte operativo posterior a 4.0.0, incorporando el endurecimiento de caja/terminales/permisos y la trazabilidad generica de reglas de precio.

## Estado de salida

- version visible: `4.1.0`
- build: `2026-05-29`
- rama local: `Ver-4.0.0`
- fuente activa: `C:\xampp82\htdocs\kiosco`
- PHP de validacion: `C:\xampp82\php\php.exe`
- smoke tecnico: `134 OK / 0 fallidas / 0 skipped`
- migracion local aplicada: `038_venta_items_ajustes_redondeo.sql`
- instalador servidor: `C:\Users\Martin\Documents\FLUS_installer_V4.0.0\installer\output\FLUS_Server_Setup_4.1.0.exe`
- instalador terminal: `C:\Users\Martin\Documents\FLUS_installer_V4.0.0\installer\output\FLUS_Terminal_Setup_4.1.0.exe`

## Alcance

- Caja y terminales quedan endurecidos para evitar choques entre usuarios/cajas.
- Permisos base del rol cajero quedan alineados al flujo operativo minimo.
- Ventas recientes desde caja permite reimprimir y anular con permisos dedicados.
- Rendimiento de cajeros queda como modulo administrativo.
- Reglas de precio queda preparada como base general, no solo para kioscos 24 hs.
- Las ventas con ajuste automatico guardan precio base, precio cobrado, monto de ajuste y redondeo por item.

## Reglas de precio

El precio final cobrado sigue guardandose en `ventas.total` y `venta_items.subtotal`. Los reportes de ventas, panel, rotacion, ABC y analisis comercial siguen leyendo el importe real cobrado.

La diferencia de 4.1.0 es que tambien queda guardado el origen del ajuste:

- `ventas.ajuste_precio_aplicado`
- `ventas.ajuste_precio_total`
- `ventas.ajuste_precio_redondeo_total`
- `venta_items.precio_unit_base`
- `venta_items.ajuste_precio_tipo`
- `venta_items.ajuste_precio_origen`
- `venta_items.ajuste_precio_nombre`
- `venta_items.ajuste_precio_pct`
- `venta_items.ajuste_precio_unit_monto`
- `venta_items.ajuste_precio_total`
- `venta_items.ajuste_precio_regla_unit_monto`
- `venta_items.ajuste_precio_redondeo_modo`
- `venta_items.ajuste_precio_redondeo_unit_monto`
- `venta_items.ajuste_precio_redondeo_total`

El redondeo opcional se aplica despues del porcentaje y siempre hacia arriba. Esta pensado para agilizar cobros de efectivo sin perder trazabilidad: FLUS conserva cuanto vino de la regla porcentual y cuanto vino solo del redondeo.

Inventario base sigue usando `productos.precio` para valuar stock actual. El ajuste es condicion de venta, no cambio permanente de lista.

## Validaciones realizadas

- `C:\xampp82\php\php.exe -l src\recargo_horario.php`
- `C:\xampp82\php\php.exe -l src\venta_api_lib.php`
- `C:\xampp82\php\php.exe -l public\api\actions\calcular_carrito.php`
- `C:\xampp82\php\php.exe -l public\api\actions\registrar_venta.php`
- `C:\xampp82\php\php.exe -l public\caja.php`
- `C:\xampp82\php\php.exe -l public\precios_historial.php`
- `C:\xampp82\php\php.exe -l public\venta_detalle.php`
- `C:\xampp82\php\php.exe scripts\migrate.php`
- `C:\xampp82\php\php.exe tests\smoke.php`
- `$env:FLUS_TEST_DB='1'; C:\xampp82\php\php.exe tests\integration_db.php`
- `C:\Users\Martin\Documents\FLUS_installer_V4.0.0\build_release.ps1 -SourceRoot C:\xampp82\htdocs\kiosco`
- Browser QA en caja: producto base `$1.234`, regla `+10%`, redondeo a `$10`, venta #174 cobrada a `$1.360,00`.

Resultado del smoke:

```text
Total: 134, failed: 0, skipped: 0
```

Resultado del smoke dentro del empaquetado portable:

```text
Total: 134, failed: 0, skipped: 6
```

## Pendientes antes de produccion

- Crear tag `v4.1.0` si se usa versionado por tags.

## Artefactos compilados

- Servidor: `FLUS_Server_Setup_4.1.0.exe` (`84.078.461` bytes)
  - SHA256: `45840683B24B3F297F1A8D4DA14B444E382046964838BB518DE98457DAC0414E`
- Terminal: `FLUS_Terminal_Setup_4.1.0.exe` (`2.035.364` bytes)
  - SHA256: `0B5DFABBDDADDD47F04E0C104519B9C5836A04677A13834F4B56BFD91AB71E09`
