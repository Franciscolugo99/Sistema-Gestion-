# CHANGELOG - FLUS

## [3.2.2] - 2026-01-29

### Added
- Diagnóstico exportable (ZIP) para soporte.
- Inventario físico (conteo) + aplicación de ajustes con movimientos de stock.
- Reposición sugerida + export CSV.
- Historial de precios / ajustes masivos.
- `system_api`: endpoints para operación (health/diagnóstico/backups) y soporte de módulos (inventario/reposición/precios).

### Changed
- Refactor: alineación entre scripts API/UI + install + helpers.
- Migraciones: runner `scripts/migrate.php` + `migrations/` (idempotente).
- BD: views portables sin `DEFINER` + limpieza de inconsistencias (FKs duplicadas).

### Security
- Contrato JSON estándar (`ok/error`) en APIs.
- CSRF reforzado en endpoints JSON.

### Repo / Maintenance
- `.gitignore`: se ignora el estado local de licencia (no se versiona).
- Se versionan migraciones SQL (`migrations/*.sql`).

### Docs
- README/CHANGELOG actualizados + bump de versión + ajustes de navegación (según permisos).

---

## [2.3.1] - (tag v2.3.1)
- Release histórico (ver tag `v2.3.1`).
