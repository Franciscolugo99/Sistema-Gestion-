# Security Checklist

## Backend Authorization

- Confirm the action requires login where needed.
- Confirm backend permission checks exist with the correct slug, not only hidden UI.
- For API actions, inspect `public/api/index.php`, `public/api/secure_actions_guard.php`, action files and direct API files under `public/api/`.
- Deny unknown actions by default.
- Keep public endpoints intentionally public only when their token or validation model is explicit.

## CSRF and HTTP Method

- Destructive or sensitive actions must require POST or the correct non-GET method.
- Require CSRF for state-changing browser actions.
- For JSON APIs, prefer existing `require_csrf_json()` / `flus_csrf_from_request()` patterns from `src/api_helpers.php`.
- Verify frontend sends token by the route's expected mechanism: form field, JSON body or `X-CSRF-Token`.

## Input, SQL and Output

- Validate identifiers as integers before DB use.
- Use prepared statements for all dynamic values.
- Do not concatenate untrusted input into SQL, filesystem paths, shell commands or redirects.
- Escape output in HTML and attributes with existing helpers.
- Do not expose raw SQL errors, stack traces, local filesystem paths, secrets, tokens or config values to the user.
- Log internal details with `src/logger.php` when useful, but return sanitized user messages.

## Sensitive Domains

- Backups: verify permission `gestionar_backups`, CSRF, managed filenames, safe path joins and no arbitrary file read/write.
- Tickets/PDFs: verify public tokens are strong, scoped and time-limited where intended.
- Mercado Pago: verify webhook/idempotency handling and prevent duplicate `mp_payment_id` association.
- Facturacion/ARCA: do not re-emit blindly if authorization, `request_uid` or fiscal trace already exists.
- Users/roles: validate permission mutations on the backend and preserve admin access.

## Review Questions

- Can a user perform this action by crafting a request even if the UI hides the button?
- Can a retry, double-click or timeout execute it twice?
- Can the frontend change totals, discounts, permissions or stock?
- Can invalid data reveal internals?
- Can a token, filename or external id be reused outside its intended scope?
