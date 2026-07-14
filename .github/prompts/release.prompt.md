---
description: "Release EasyRide: verify, version, commit, tag, push, and publish a GitHub release"
agent: "agent"
argument-hint: "Short description of what changed"
---

Release a new EasyRide version safely.

1. Inspect `git status` and the staged/uncommitted diff. Preserve unrelated user work.
2. Read `APP_VERSION` from `config.php` and increment the patch version unless the user supplied a version.
3. Update `config.php` and the README version badge together.
4. If schema files changed, lint them and confirm `DB_SCHEMA_VERSION`, migration definitions, and `sql/schema.sql` are consistent. Do not execute migrations through HTTP; deployment runs `php scripts/maintenance/migrate.php`.
5. Run relevant lint and regression checks, including `php scripts/maintenance/test-authorization.php` for authorization changes.
6. Stage only the intended release files, commit with a clear message, create an annotated `vX.Y.Z` tag, and push `main` plus the tag.
7. Publish a GitHub release with concise, user-facing notes and report its URL.

Never assume a fixed local path, web URL, database, or administrator credential. Do not copy the repository to another directory as part of release automation.
