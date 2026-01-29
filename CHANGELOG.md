# CHANGELOG - FLUS

## [3.2.2] - 2026-01-29

### Highlights
- Hardening P0: JSON `ok/error`, CSRF reforzado, BD portable (views sin DEFINER / limpieza FKs duplicadas).
- Migraciones P1: runner `scripts/migrate.php` + carpeta `migrations/`.
- Operación: Health + Diagnóstico exportable + Backups por API con auditoría.
- Nuevos módulos: Inventario físico (conteo y ajustes), Historial de precios/ajustes masivos, Reposición sugerida.

### Detalle técnico
- `public/api/system_api.php`: endpoints de health/diagnóstico/backups/auditoría/reposición/precios/inventario.
- `src/api_helpers.php`: helpers comunes + `require_csrf_json()` / `require_perm_json()` (API).
- `src/inventario_fisico.php`: sesiones de conteo + aplicar ajustes (movimientos_stock).
- UI: `inventario_fisico.php`, `precios_historial.php`, `reposicion.php`, `diagnostico.php`.
- Nav: se agregan accesos según permisos.

---

## [3.2.1] - 2026-01-22
- Base previa (Caja/Clientes/CC/Proveedores y UX unificada) — ver histórico anterior.

