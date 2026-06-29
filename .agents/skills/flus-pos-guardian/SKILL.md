---
name: flus-pos-guardian
description: FLUS POS and commercial-management guardrails for PHP, JavaScript and MySQL work. Use when modifying, reviewing, testing or adding FLUS functionality related to ventas, caja, productos, stock, compras, proveedores, clientes, cobranzas, tesoreria, Mercado Pago, facturacion, usuarios, permisos, migraciones, backups, API or seguridad.
---

# FLUS POS Guardian

Use this skill to protect FLUS business invariants while changing or reviewing the POS and management system. FLUS is a PHP/MySQL application with vanilla JavaScript, custom CSS, endpoints under `public/`, shared logic under `src/`, SQL migrations under `migrations/`, and smoke coverage in `tests/smoke.php`.

## Required Workflow

Before modifying code:

1. Inspect the affected flow end to end, from UI or caller to endpoint, shared library, database tables, permissions and tests.
2. Identify real files, tables, endpoints, permissions and migrations in the repository. Do not assume names.
3. Check compatibility with existing installations and legacy data.
4. Prefer the smallest incremental change that preserves current behavior.
5. Avoid touching unrelated files unless there is a concrete reason tied to the task.

Use `rg`, `git diff`, `git log`, `git blame` and targeted file reads before opening large files. Do not read or commit `storage/`, dumps, backups, exports, uploads, `vendor/`, `node_modules/` or sensitive config unless the task explicitly requires it.

## References

Load only the references needed for the current task:

- `references/architecture.md`: read for any non-trivial FLUS change or review.
- `references/security-checklist.md`: read for API, permissions, users, destructive actions, tickets, backups, Mercado Pago, facturacion, or security-sensitive work.
- `references/sales-stock-checklist.md`: read for ventas, caja, pagos, stock, compras, productos, proveedores, clientes, cobranzas, tesoreria, Mercado Pago, anulations or fiscal/commercial flows.
- `references/migrations-checklist.md`: read for schema, permissions, install/upgrade, `install.sql`, `migrations/` or `scripts/migrate.php`.
- `references/test-commands.md`: read before validating changes or reporting test results.

## Non-Negotiable Rules

- Register every sale, payment and stock movement exactly once.
- Use idempotency for venta and pago endpoints when retries, double-clicks, timeouts or browser reconnects are possible.
- Never trust frontend prices, discounts, totals, permissions or stock. Recalculate amounts and permissions on the server.
- Use transactions for operations that update multiple tables.
- Lock stock rows correctly under concurrency, usually with `FOR UPDATE` inside the transaction.
- Never allow negative stock unless an explicit existing configuration permits it.
- Use prepared SQL statements.
- Require POST or the appropriate HTTP method, CSRF and a specific backend permission for destructive or sensitive actions.
- Deny unregistered routes/actions by default.
- Do not expose raw MySQL messages, local paths, secrets or stack traces to users.
- Preserve auditability for ventas, pagos, anulaciones, cierres de caja and stock changes.
- Do not physically delete historical financial records.
- Prevent external identifiers such as `mp_payment_id` from being associated twice.
- Keep compatibility with the PHP and MySQL/MariaDB versions currently used by the project.

## UI Changes

For interface changes, reuse existing components, CSS patterns and notification helpers. Do not introduce a second visual system. Keep cashier workflows fast for keyboard use and barcode scanners. Prioritize clarity and speed over animation. Check desktop, tablet and small widths; use Playwright when available and useful.

## Validation

After changes, run the validation appropriate to the touched files and risk:

- PHP lint for every modified PHP file.
- Existing smoke tests.
- JavaScript syntax checks for modified JS when Node is available.
- The main affected flow.
- Insufficient-permission behavior.
- Invalid data handling.
- Double submit or retry behavior for ventas or pagos.

Report any validation that could not be run and the exact reason.

## Final Response Format

At the end of each task, report:

1. Summary of the change.
2. Modified files.
3. Risks found.
4. Tests run and results.
5. Required migrations.
6. Manual installation steps.
7. Possible regressions.
8. Pending recommendations.
