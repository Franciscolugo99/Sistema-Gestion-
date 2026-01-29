# FLUS Patch — Migraciones + Install (P1.1 / P1.2) + JS ok/success compat

Incluye:
- ✅ Runner de migraciones:
  - `src/migrations_runner.php`
  - `scripts/migrate.php` (CLI)
- ✅ Instalador web con 2 pasos:
  - `public/install.php` ahora:
    - crea `src/config.php`
    - si la DB está vacía: permite **crear estructura** (aplica migraciones)
    - crea un **usuario admin** con contraseña elegida
- ✅ Compatibilidad front:
  - `app.js` acepta `ok` o `success` (mientras terminás de unificar APIs)
  - varios módulos que chequeaban `result.success` ahora aceptan `result.ok`

Migraciones incluidas:
- `migrations/001_init_schema.sql` (schema + seeds mínimos: roles/permisos/terminales/app_config; **sin users**)
- `migrations/002_p0_hardening.sql` (uuid ventas + FKs duplicadas + views sin DEFINER + collation DB)

## Cómo aplicar
1) Hacé backup del proyecto (o commit).
2) Extraé el ZIP en la raíz del repo (manteniendo carpetas).
3) Nueva instalación:
   - Abrí `public/install.php`
   - Paso 1: crear `src/config.php`
   - Paso 2: “Crear estructura” + crear admin
4) Instalación existente:
   - Ejecutá en consola desde la raíz del proyecto:
     - `php scripts/migrate.php`

## Notas
- Este patch NO toca `src/VentasController.php` (P0.6 lo hacés vos).
