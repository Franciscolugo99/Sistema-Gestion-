# FLUS 4.1.0

FLUS 4.1.0 queda como corte operativo posterior a 4.0.0.

Este release incorpora el trabajo reciente de caja, terminales, permisos de cajero, rendimiento de cajeros y ventas recientes desde caja. Tambien agrega trazabilidad generica de reglas de precio y redondeo operativo para que el sistema pueda explicar cuando un precio final surge de un ajuste automatico.

Validacion tecnica:

- `C:\xampp82\php\php.exe tests\smoke.php`
- `134 OK / 0 fallidas / 0 skipped`

Alcance destacado:

- Caja y terminales quedan mas protegidas frente a turnos abiertos por otro usuario.
- Rol cajero queda con permisos base operativos y acotados.
- Ventas recientes desde caja permite reimpresion y anulacion con permisos dedicados.
- Reglas de precio deja de pensarse como caso exclusivo 24 hs y pasa a una base comercial general.
- Ventas con ajuste guardan precio base, ajuste aplicado, redondeo y precio final cobrado.
- `install.sql` y migraciones `037_venta_items_ajustes_precio.sql` / `038_venta_items_ajustes_redondeo.sql` quedan alineados para instalacion limpia y upgrade.

Pendiente operativo:

- Crear tag `v4.1.0` si se usa versionado por tags.

Artefactos:

- `C:\Users\Martin\Documents\FLUS_installer_V4.0.0\installer\output\FLUS_Server_Setup_4.1.0.exe`
- `C:\Users\Martin\Documents\FLUS_installer_V4.0.0\installer\output\FLUS_Terminal_Setup_4.1.0.exe`
