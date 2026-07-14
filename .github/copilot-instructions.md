# EasyRide contributor instructions

EasyRide is a plain PHP/MySQL application for motorcycle-club rides, members, and live Ride Mode operations.

## Local configuration

- Read runtime configuration from `config.php`; use `config.local.php` for machine-specific database and URL settings. Never commit `config.local.php` or credentials.
- Do not assume a fixed local URL, database name, or administrator account. The installer collects administrator credentials interactively.
- Preserve `ROLE_MEMBER = 'VOLUNTEER'` and the `VOLUNTEEROPS` include guard until a dedicated, tested data migration replaces them. They are persisted compatibility contracts, not user-facing branding.

## Application conventions

- Every page includes `bootstrap.php`, requires login as appropriate, and verifies CSRF tokens for state changes.
- `isAdmin()` is only for the real administrative roles. Custom-role access must use `hasPagePermission()` or `requirePermission()`.
- Keep Ride Mode reads free of writes. Use explicit POST command endpoints for persistent changes.
- Put new feature code in `includes/features/<feature>/` with controllers, services, repositories, and templates.

## Database changes

- Do not run DDL from web requests or from `update.php`.
- Add idempotent schema definitions in `includes/migrations.php`, update `DB_SCHEMA_VERSION` in `config.php`, and update `sql/schema.sql` for fresh installs.
- Deploy changes with `php scripts/maintenance/migrate.php`; it holds the migration lock and must be run once per deployment.

## Safety and verification

- Do not add test, seed, diagnostic, repair, or migration entry points under the public root. Put CLI-only tools in `scripts/maintenance/`.
- Keep root SQL, logs, and local configuration web-denied. See `docs/server-hardening.md` for Apache and Nginx rules.
- Run PHP lint for changed files and `php scripts/maintenance/test-authorization.php` after authorization changes.
- Preserve existing user changes. Do not use destructive Git commands.

## Releases

- Bump `APP_VERSION` and the README version together.
- Review the staged diff, commit, create an annotated `vX.Y.Z` tag, push `main` and the tag, then publish a GitHub release.
- Do not mirror the repository into fixed local folders as a release step.
