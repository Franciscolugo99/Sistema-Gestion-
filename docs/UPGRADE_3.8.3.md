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
- `migrations/025_inventario_fisico_schema.sql`
- `migrations/026_backfill_facturas_estado_fiscal_legacy.sql`
- `migrations/027_reclasificar_arca_no_responde_transitorio.sql`

## Impacto esperado

- Facturacion:
  - Facturas sin CAE quedan trazadas con `estado_fiscal`, `fiscal_request_uid` y eventos ARCA para recovery manual seguro.
  - `ERROR_POST_ARCA` y `RECUPERADA` quedan formalizados para distinguir mejor contingencia y regularizacion.
- Diagnostico / seguridad operativa:
  - Se registra la sesion activa del usuario y se habilitan controles de revocacion y refresh en vivo.
- Caja / operacion:
  - La venta sigue funcionando aunque la facturacion fiscal se gestione por flujo separado.
  - Conviene validar explicitamente caja + facturacion + recovery despues del deploy.
- Inventario fisico:
  - Se preserva el nombre de categoria y snapshot de stock del sistema para mejorar trazabilidad de conteos.
- Compatibilidad fiscal legacy:
  - Facturas antiguas con CAE y coordenadas fiscales validas se normalizan como `AUTORIZADA`.
  - Errores de disponibilidad de ARCA/WSDL se reclasifican como `ERROR_TRANSITORIO` para permitir recovery operativo.

## Preparacion previa

1. Confirmar ventana de mantenimiento.
2. Hacer backup completo de la base de datos.
3. Hacer backup de la carpeta del proyecto actual.
4. Preservar `src/config.php` y el contenido de `storage/`.
5. Verificar que `src/config.php` defina `APP_BUILD` y un `APP_SECRET` fuerte y persistente. No usar placeholders ni regenerarlo en cada deploy, porque invalida tokens publicos existentes.
6. Tener a mano el PHP del servidor para correr migraciones.
7. Si la instancia usa cache agresiva del navegador, planear una recarga forzada despues del deploy.

## Pasos de actualizacion

1. Copiar la nueva version del proyecto sobre la instancia existente.
2. Verificar que `src/config.php` no haya sido sobrescrito y conserve `APP_SECRET`; si viene de un config viejo, agregar `APP_BUILD` y `APP_SECRET` tomando `src/config.example.php` como referencia.
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
- Inventario fisico:
  - crear o abrir una sesion de conteo
  - verificar que el conteo preserve categoria y stock del sistema cuando existan
- Diagnostico:
  - abrir `diagnostico.php`
  - revisar sesiones activas y refresco en vivo
- Integracion DB de release:
  - correr `tests/integration_db.php` con `FLUS_TEST_DB=1` contra MySQL/MariaDB descartable antes de publicar cuando la release toque schema, facturacion, NC, cobranzas, recibos o cuenta corriente
  - usar `docs/INTEGRATION_DB_RUNNER.md` como checklist operativo
  - si falla, no publicar la release hasta corregir y repetir smoke + runner

## Riesgos a vigilar

- Instalaciones con JS cacheado: si una pantalla parece vieja, hacer recarga forzada.
- Si la instancia usa facturacion integrada, validar preflight y recovery antes de habilitar operacion plena.
- Si hay servicios o automatismos externos que asumen un set viejo de migraciones/documentacion, actualizarlos para contemplar migraciones hasta `027_reclasificar_arca_no_responde_transitorio.sql`.

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
