# FLUS Architecture Reference

## Repository Shape

- `public/`: web entrypoints, pages, API routers, AJAX endpoints and frontend assets.
- `public/api/index.php`: main action router for many POS APIs.
- `public/api/actions/`: action files used by the API router, including ventas, promos, terminales and Mercado Pago actions.
- `public/api/ventas_api.php`: ventas-related API entrypoint for ticket sharing and token validation.
- `public/api/secure_actions_guard.php`: centralized guard for selected API actions.
- `public/auth.php`: login/session helpers and `require_permission()`.
- `public/lib/csrf.php`: CSRF helpers used by pages and endpoints.
- `src/`: shared libraries and domain logic.
- `migrations/`: numbered SQL migrations.
- `scripts/migrate.php`: migration runner entrypoint.
- `install.sql`: clean-install baseline schema.
- `tests/smoke.php`: structural and fast regression suite.
- `tests/integration_db.php`: DB integration runner, gated by `FLUS_TEST_DB=1`.
- `docs/INTEGRATION_DB_RUNNER.md`: documented DB integration command and environment.

## Hotspot Modules

- Caja and ventas: `public/caja.php`, `public/caja_lib.php`, `public/api/actions/registrar_venta.php`, `public/api/actions/calcular_carrito.php`, `public/api/actions/anular_venta.php`, `public/api/actions/anular_items_venta.php`, `src/venta_api_lib.php`, `src/venta_anulaciones_lib.php`.
- Productos and stock: `public/productos.php`, `public/stock.php`, `public/stock_ajax.php`, `src/productos_helpers.php`, `src/upload_helpers.php`.
- Compras and proveedores: `public/compras.php`, `public/compra_detalle.php`, `public/proveedores.php`, `src/compras_helpers.php`, `src/compras_precio_historial_lib.php`, `src/compras_tesoreria_lib.php`.
- Clientes, cobranzas and cuenta corriente: `public/clientes.php`, `public/cobranzas.php`, `public/cuenta_corriente.php`, `public/api/cuenta_corriente_api.php`, `public/api/factura_cobranza_api.php`, `src/cobranzas_lib.php`.
- Tesoreria: `public/tesoreria*.php`, `src/tesoreria_lib.php`.
- Mercado Pago: `public/mercadopago_config.php`, `public/mercadopago_liquidaciones.php`, `public/mercadopago_webhook.php`, `public/api/actions/mp_*.php`, `src/mercadopago_*_lib.php`, `src/config_mp.php`.
- Facturacion: `public/factura_*.php`, `public/facturacion*.php`, `src/facturacion*_lib.php`, `src/Fiscal/`.
- Usuarios and permisos: `public/usuarios.php`, `public/usuario_*.php`, `public/roles.php`, `public/rol_*.php`, `src/user_admin_lib.php`.
- Backups: `public/backups.php`, `public/backup_download.php`, `src/backup_lib.php`, `src/backup_enhanced.php`.

## Conventions

- Use PHP pages/endpoints plus vanilla JS. Do not add React, Tailwind or new frameworks unless explicitly requested.
- Keep fiscal logic out of UI code.
- Keep ARCA traceability separate from email or commercial tracing.
- Keep legacy ventas, facturas, caja, notas de credito and reportes compatible.
- Prefer helpers already present in `src/` and `public/lib/` over new abstractions.
- Use `window.apiJson` and existing notification helpers where current UI code does.
- Use `require_login()`, `require_permission()` and CSRF helpers on backend actions, not only hidden buttons.

## Discovery Patterns

- Find routes/actions: `rg -n "action=|case '|require_permission|require_csrf_json|apiJson|fetch\\(" public src tests`
- Find table and column assumptions: `rg -n "CREATE TABLE|ALTER TABLE|FROM table|JOIN table|INSERT INTO|UPDATE table" install.sql migrations src public tests`
- Find permissions: `rg -n "require_permission\\('|INSERT INTO permisos|slug" public src install.sql migrations tests`
- Find idempotency: `rg -n "idempot|request_uid|mp_payment_id|external|duplicate|UNIQUE" public src migrations install.sql tests`
