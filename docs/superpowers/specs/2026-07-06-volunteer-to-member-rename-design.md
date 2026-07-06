# Volunteer → Member Terminology Rename

## Purpose

EasyRide was originally built for a volunteer rescue team and later repurposed for a motorcycle club. The product-facing language has partly caught up (the "Μέλος Λέσχης" / "Club Member" badge already exists), but the codebase, database schema, and most admin-facing UI text still say "volunteer"/"Εθελοντής" throughout. This rename brings the whole application in line with the "member" terminology the club actually uses, everywhere: PHP code, file names/URLs, database tables/columns, and UI text (Greek and English).

Scope confirmed via a full-codebase audit: **1562** occurrences of "volunteer" across **140** PHP files, **9** files with "volunteer" in the filename, **6** database tables named `volunteer_*`, several `volunteer_*`/`*_volunteer_*` columns on other tables, 2 index names, and **128** Greek "Εθελοντ-" occurrences.

This is a separate, independent project from the "shifts → days" request made in the same conversation — that gets its own spec later.

## Phases

Ordered for safety: the riskiest, most foundational change (database + the code that depends on it) goes first, since the app cannot run with schema and code disagreeing even briefly. File renames and UI text are comparatively low-risk and independent of each other, so they follow in order of user-visible impact.

### Phase 1 — Database schema + backend code sync

**Tables renamed** (`RENAME TABLE`, which MariaDB automatically repoints existing FK references for):
- `volunteer_certificates` → `member_certificates`
- `volunteer_documents` → `member_documents`
- `volunteer_pings` → `member_pings`
- `volunteer_points` → `member_points`
- `volunteer_positions` → `member_positions`
- `volunteer_profiles` → `member_profiles`

**Columns renamed** (`ALTER TABLE ... CHANGE COLUMN`, re-declaring the FK definition where one exists on that column):
- `participation_requests.volunteer_id` → `member_id`
- `shift_swap_requests.from_volunteer_id` → `from_member_id`
- `shift_swap_requests.to_volunteer_id` → `to_member_id`
- `shift_swap_requests.to_volunteer_responded_at` → `to_member_responded_at`
- `users.volunteer_type` → `member_type`
- `shifts.max_volunteers` → `max_members`
- `shifts.min_volunteers` → `min_members`
- `inventory_bookings.volunteer_name` → `member_name`
- `inventory_bookings.volunteer_phone` → `member_phone`
- `inventory_bookings.volunteer_email` → `member_email`

**Indexes/constraints renamed** for consistency (functionally inert, but free while altering these tables anyway):
- `participation_requests.idx_participation_volunteer` → `idx_participation_member`
- `users.idx_volunteer_type` → `idx_member_type`
- FK constraint names containing "volunteer" (e.g. `volunteer_certificates_ibfk_1`, `fk_vd_user`, `fk_vd_uploader`, `uq_user_cert` stays as-is since "user_cert" doesn't say volunteer — only rename constraint names that literally contain "volunteer") get renamed to their `member` equivalent.

This ships as one new versioned migration in `includes/migrations.php`, run once via the app's existing migration runner (matching the pattern already used for schema changes like the v41 prerequisite-settings migration seen this session). Immediately after, every PHP file referencing any of the above table/column/index names gets updated in the same phase — SQL query strings, PHP variable names (e.g. `$volunteerId` → `$memberId`), array keys, and function names (e.g. `getApprovedVolunteers()` → `getApprovedMembers()`) that specifically refer to this renamed data. Function/variable names that use "volunteer" generically as a role-word but aren't tied to a renamed column (e.g. a local loop variable named `$volunteer` iterating over `$users`) are still renamed for consistency, since this phase already touches every file that needs review for the rename in general.

**Explicitly not touched in this phase:** the 9 `volunteer*.php` filenames themselves (Phase 2), and Greek/English UI display strings that don't correspond to a code identifier (Phase 3) — though inevitably some PHP files will get both code-identifier and adjacent display-string edits in the same pass if they're already being opened; that overlap is fine and not scope creep since the file is already being edited for Phase 1 reasons.

### Phase 2 — File renames + redirects

Files renamed:
| Old | New |
|---|---|
| `volunteers.php` | `members.php` |
| `volunteer-view.php` | `member-view.php` |
| `volunteer-form.php` | `member-form.php` |
| `volunteer-positions.php` | `member-positions.php` |
| `volunteer-report.php` | `member-report.php` |
| `volunteer-status.php` | `member-status.php` |
| `volunteer-doc-download.php` | `member-doc-download.php` |
| `import-volunteers.php` (under `exports/`) | `import-members.php` |
| `inactive-volunteers.php` | `inactive-members.php` |

Every internal reference — sidebar/menu links, `<a href>`, form `action`, JS `fetch()`/`location.href` targets — across the whole app gets updated to point at the new filenames. Each old filename becomes a minimal redirect stub:

```php
<?php
header('Location: ' . str_replace('volunteer-view.php', 'member-view.php', $_SERVER['REQUEST_URI']), true, 301);
exit;
```

(exact replacement target hardcoded per file, and query strings preserved via `$_SERVER['REQUEST_URI']` substitution) so any bookmarked/shared old URL keeps working indefinitely.

### Phase 3 — UI text sweep

Greek: "Εθελοντής" → "Μέλος", "Εθελοντές" → "Μέλη", "Εθελοντικ-" (adjective forms, e.g. "Εθελοντική Δράση") → the natural "member"-based Greek phrasing case by case (e.g. "Δράση Μελών"). English: any remaining "Volunteer"/"volunteer" in display strings (not code identifiers, already handled in Phase 1) → "Member"/"member". Covers page titles, table headers, CSV/export column headers, and email template placeholder/sample text.

## Testing

No automated test framework exists in this repo (confirmed project-wide convention). Verification per phase:
1. `php -l` on every touched file.
2. A full `mysqldump` backup of every database on the shared local MariaDB instance, taken before Phase 1's migration runs (already done — `C:\Users\user\Desktop\easyride_pre_rename_backup_20260706\`).
3. After Phase 1: a throwaway CLI script exercising the renamed tables/columns directly (insert/select against `member_profiles`, `participation_requests.member_id`, etc.), deleted before commit.
4. After each phase: live browser walkthrough of the main affected flows — dashboard, member list/view/form, shift signup and swap requests, certificates, documents, inventory bookings — confirming no fatal errors and correct data.
5. Rollback path: restore `easyride.sql` from the pre-rename backup if Phase 1's migration causes irrecoverable issues; git history covers all code changes regardless.

## Out of scope

"Shifts → days" conceptual change (separate spec, separate project). Any database/table outside `easyride` (this MariaDB instance hosts 8 unrelated other projects — untouched).
