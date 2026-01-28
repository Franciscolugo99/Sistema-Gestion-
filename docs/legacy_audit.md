# Auditoría de “código legacy” (FLUS / Kiosco)

**Entrada analizada**
- Código: `Sistema-Gestion--main`
- BD dump: `kiosco (26).sql` (generado 2026-01-28)

## 1) Hallazgos principales (legacy/duplicado)

### A. Helpers de esquema duplicados
Encontré **3 “familias”** de helpers que consultaban `INFORMATION_SCHEMA` / `SHOW ...`:
- `src/db_schema.php` (ya estaba): cache + tolerante a fallas.
- `src/db_helpers.php`: wrappers sin cache (más “viejo”).
- `src/api_helpers.php`: `get_table_columns()` / `has_col()` con cache propio.

**Riesgo:** comportamiento distinto según qué archivo se incluya (y más consultas al schema).

✅ **Parche aplicado:** `src/db_helpers.php` y `src/api_helpers.php` ahora **prefieren** `src/db_schema.php` si está disponible (mismo cache y misma lógica), y caen a fallback solo si no existe.

### B. Helpers “legacy” muertos (copias pegadas)
Se detectaron funciones definidas pero **no utilizadas** (ruido / deuda técnica):
- `_legacy_has_table()` / `_legacy_has_column()` en:
  - `public/ventas.php`
  - `public/venta_detalle.php`
  - `public/ticket.php`
  - `public/api/actions/ventas_kpis.php`

✅ **Parche aplicado:** se eliminaron esas funciones y (donde hacía falta) se usa el helper central (`has_table` / `has_column` / `has_view`).

## 2) BD vs Código (compatibilidad)

### Tablas referenciadas por el código pero NO presentes en el dump
- `zonas_reparto` (referencia en `public/includes/ClienteController.php`)

🔎 Nota: el código ya hace un `SHOW TABLES LIKE 'zonas_reparto'` antes de consultar, así que funciona como feature opcional.  
**Recomendación:** si esta feature existe en algunas instalaciones, sumarla a tu `install.sql/upgrade.sql` como módulo opcional con migración.

### Tablas presentes en el dump pero sin referencias en el código (0 ocurrencias)
- `movimientos_stock_backup_7d`
- `venta_fiscal`

📌 Esto no significa “borrar ya”: puede ser usado por jobs/SQL manual o features que no están en este repo.  
Pero sí indica “posible legado / abandono”.

### Vistas del dump sin referencias en el código (0 ocurrencias)
- `v_movimientos_stock_resumen`
- `v_usuarios_completo`

## 3) Recomendaciones de limpieza (siguiente paso)
Si querés dejarlo “pro” a nivel arquitectura (sin romper):
1) Elegir **una** capa de helpers de schema:
   - Canon: `src/db_schema.php` (ya lo tenés).
   - Mantener `has_table/has_column/has_view` como API pública (wrappers).
2) Crear helper único para “ID de usuario en sesión” (evitar `usuario_id` / `user_id` / `user['id']` disperso).
3) Catalogar tablas “opcionales” (ej: `zonas_reparto`) y formalizarlas como módulo con migración.

