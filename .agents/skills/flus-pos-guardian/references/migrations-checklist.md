# Migrations Checklist

## Before Editing Schema

- Inspect `install.sql`, existing `migrations/`, `scripts/migrate.php`, `src/migrations_runner.php` and relevant smoke tests.
- Verify whether the table/column/index/permission already exists in the clean-install baseline.
- Verify whether a previous migration already adds the same schema or data.
- Avoid modifying old migrations unless repairing a repository inconsistency with a clear reason.

## Numbering and Compatibility

- Do not reuse migration numbers. The repository currently contains two legacy `031_*.sql` files; do not create another duplicate.
- Use the next available number after the highest current migration.
- Make migrations additive when possible.
- Use `CREATE TABLE IF NOT EXISTS`, guarded `ALTER TABLE`, idempotent inserts or compatibility checks where the runner/project pattern supports them.
- Preserve data for existing installations. Add defaults/backfills instead of destructive rewrites.
- Keep `install.sql` and migrations aligned when a clean install also needs the new schema.

## Permissions

- If adding a permission slug, align code checks, `install.sql`, migrations and admin/default roles.
- Run smoke tests because `tests/smoke.php` checks permission alignment.

## Version and Docs

- Update `src/version.php`, release docs or changelog only when the change requires a versioned deliverable or documented upgrade behavior.
- Add manual installation steps when migrations or operational actions are required.

## Validation

- Run PHP lint on changed PHP.
- Run `php tests/smoke.php`.
- Run DB integration when schema, migrations, critical financial flows or clean install/upgrade compatibility are affected and a MySQL/MariaDB test server is available.
