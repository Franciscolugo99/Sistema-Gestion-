# Upgrade Guide - FLUS 3.8.3

Guia pensada para actualizar instalaciones existentes con datos reales.

## Alcance de esta version

- Consolida la capa fiscal comun con recovery minimo, estados fiscales mas claros y visibilidad operativa.
- Agrega registro de sesiones activas para diagnostico, revocacion y controles de soporte.
- Mantiene el contrato del proyecto: instalacion limpia con `install.sql` y upgrades con `php scripts/migrate.php`.

## Migraciones incluidas en esta linea

- `migrations/002_p0_hardening.sql`
- `migrations/003_fix_views.sql`
- `migrations/004_facturas_unique_scope.sql`
- `migrations/005_compras_descuentos_schema.sql`
- `migrations/006_diagnostics_permission.sql`
- `migrations/007_support_modules_schema.sql`
- `migrations/008_permissions_catalog_sync.sql`
- `migrations/009_operator_role_seed.sql`
- `migrations/010_anulaciones_parciales.sql`
- `migrations/011_cc_schema_compat.sql`
- `migrations/012_venta_anulaciones_fiscal.sql`
- `migrations/013_facturas_fiscal_ext.sql`
- `migrations/014_factura_items_eventos_arca.sql`
- `migrations/015_fiscal_nc_hardening.sql`
- `migrations/016_factura_comun_fiscal_flow.sql`
- `migrations/017_facturacion_documentos_manual.sql`
- `migrations/018_cobranzas_base.sql`
- `migrations/019_recibos_aplicaciones.sql`
- `migrations/020_facturas_envio_trazabilidad.sql`
- `migrations/021_documentos_relaciones_presupuestos_remitos.sql`
- `migrations/022_facturas_fiscal_contingencia.sql`
- `migrations/023_user_sessions_registry.sql`
- `migrations/024_permissions_backfill_nc_y_anulacion_items.sql`

## Impacto esperado

- Facturacion:
  - Facturas sin CAE quedan trazadas con `estado_fiscal`, `fiscal_request_uid` y eventos ARCA para recovery manual seguro.
  - `ERROR_POST_ARCA` y `RECUPERADA` quedan formalizados para distinguir mejor contingencia y regularizacion.
- Diagnostico / seguridad operativa:
  - Se registra la sesion activa del usuario y se habilitan controles de revocacion y refresh en vivo.
- Caja / operacion:
  - La venta sigue funcionando aunque la facturacion fiscal se gestione por flujo separado.
  - Conviene validar explicitamente caja + facturacion + recovery despues del deploy.

## Preparacion previa

1. Confirmar ventana de mantenimiento.
2. Hacer backup completo de la base de datos.
3. Hacer backup de la carpeta del proyecto actual.
4. Preservar `src/config.php` y el contenido de `storage/`.
5. Tener a mano el PHP del servidor para correr migraciones.
6. Si la instancia usa cache agresiva del navegador, planear una recarga forzada despues del deploy.

## Pasos de actualizacion

1. Copiar la nueva version del proyecto sobre la instancia existente.
2. Verificar que `src/config.php` no haya sido sobrescrito.
3. Ejecutar:

```powershell
C:\xampp\php\php.exe scripts\migrate.php
```

4. Confirmar que el runner termine con `OK - Migraciones aplicadas`.
5. Abrir el sistema y validar flujos criticos.

## Pruebas minimas post deploy

- Login y carga del dashboard.
- Caja:
  - venta simple
  - split payment
  - cuenta corriente si aplica
- Facturacion:
  - preflight en `facturacion_config.php`
  - factura manual o desde documento
  - vista `factura_ver.php`
  - PDF
- Recovery fiscal:
  - abrir `facturacion_recovery.php`
  - verificar casos pendientes/transitorios si existen
- Diagnostico:
  - abrir `diagnostico.php`
  - revisar sesiones activas y refresco en vivo

## Riesgos a vigilar

- Instalaciones con JS cacheado: si una pantalla parece vieja, hacer recarga forzada.
- Si la instancia usa facturacion integrada, validar preflight y recovery antes de habilitar operacion plena.
- Si hay servicios o automatismos externos que asumen un set viejo de migraciones/documentacion, actualizarlos para contemplar `023_user_sessions_registry.sql`.

## Rollback

1. Restaurar backup de archivos.
2. Restaurar backup de base de datos.
3. Verificar acceso con una prueba basica de login.

## Archivos clave de la release

- `src/version.php`
- `README.md`
- `install.sql`
- `migrations/`
- `tests/smoke.php`
