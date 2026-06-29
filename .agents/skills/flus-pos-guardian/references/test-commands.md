# Test Commands Reference

Use PowerShell from the repository root.

## PHP Executable

Prefer the XAMPP PHP used by the project when available:

```powershell
& "C:\xampp\php\php.exe" -v
```

If that path is unavailable, use:

```powershell
php -v
```

## PHP Lint

Run lint on every modified PHP file:

```powershell
& "C:\xampp\php\php.exe" -l public\archivo_modificado.php
```

For a reviewed list of modified PHP files, generate the list with Git first, then lint each path deliberately. Do not lint `vendor/`, `storage/`, backups or generated dumps.

## Smoke Tests

Run:

```powershell
& "C:\xampp\php\php.exe" tests\smoke.php
```

Expected successful shape:

```text
Total: N, failed: 0, skipped: M
```

The total can change as tests are added. Report the actual total, failures and skips.

## JavaScript Syntax

For modified JS files, when Node is available:

```powershell
node --check public\assets\js\archivo_modificado.js
```

If system `node` is unavailable but the Codex bundled runtime is available, use its Node executable. The project does not define an npm test script in the inspected repo; do not invent one.

## DB Integration Runner

Use only when the change needs real MySQL/MariaDB or migration validation:

```powershell
$env:FLUS_TEST_DB='1'
$env:FLUS_TEST_DB_HOST='127.0.0.1'
$env:FLUS_TEST_DB_PORT='3306'
$env:FLUS_TEST_DB_USER='root'
$env:FLUS_TEST_DB_PASS=''
& "C:\xampp\php\php.exe" tests\integration_db.php
```

Optional inspection mode:

```powershell
$env:FLUS_TEST_DB_KEEP='1'
```

`FLUS_TEST_DB_NAME` must start with `flus_it_` if set. If MySQL is stopped or rejects the connection, report that exact failure instead of treating it as a code failure.

## Migrations

For local upgrade validation:

```powershell
& "C:\xampp\php\php.exe" scripts\migrate.php
```

Only run this against an intended local/dev database, never against unknown production data.

## Browser/UI Checks

- Test the affected page manually when the workflow is UI-driven.
- For UI-heavy changes, use Playwright when available to check desktop, tablet and smaller widths.
- Verify keyboard/barcode-scanner speed for caja/product flows.
