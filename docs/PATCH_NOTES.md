# FLUS_PATCH_pulido_legacy

Este parche apunta a **limpiar legacy sin romper compatibilidad**.

## Qué cambia
- `src/db_helpers.php`
  - Ahora incluye `src/db_schema.php` si existe y lo usa como “source of truth”.
  - Agrega `has_view()` centralizado.
- `src/api_helpers.php`
  - `get_table_columns()` y `has_col()` ahora prefieren `db_schema.php` (cache central).
- Eliminación de helpers duplicados no usados:
  - `public/ventas.php`
  - `public/venta_detalle.php`
  - `public/ticket.php`
  - `public/api/actions/ventas_kpis.php`

## Impacto esperado
- Menos consultas repetidas a `INFORMATION_SCHEMA`.
- Menos “ruido legacy” (funciones duplicadas).
- Comportamiento más consistente entre API y backoffice.

## Cómo aplicar
1) Copiá el contenido del ZIP **encima** de tu instalación/repo (respeta rutas).
2) No requiere cambios SQL.
3) Test rápido recomendado:
   - Abrir `ventas.php` y ver filtros + KPIs.
   - Imprimir un `ticket.php?venta_id=...`
   - Probar endpoints API (Caja / Ventas KPIs).

