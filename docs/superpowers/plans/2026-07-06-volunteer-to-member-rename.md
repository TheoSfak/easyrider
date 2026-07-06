# Volunteer to Member Rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename "volunteer"/"Εθελοντής" to "member"/"Μέλος" everywhere in the EasyRide codebase — database tables/columns/indexes, PHP identifiers, file names/URLs, and UI text — without breaking the live app or any of the 92 real user accounts' authentication.

**Architecture:** A versioned database migration renames the schema first (tables via `RENAME TABLE`, columns via `CHANGE COLUMN` — both confirmed on this exact MariaDB 10.4.32 install to correctly carry forward existing foreign keys). Files are physically renamed via `git mv` with 301-redirect stubs left at the old paths. A single automated text-rename script then sweeps every `.php`/`.js` file (plus `sql/schema.sql`) for the remaining identifier/text occurrences, with two hard-coded protections: the `VolunteerOps`/`VOLUNTEEROPS` family (the app's original pre-rebrand internal access-guard constant, never user-facing, must stay internally consistent) and the literal ALL-CAPS quoted string `'VOLUNTEER'`/`"VOLUNTEER"` (the actual stored enum value in `users.role` and `missions.type`, held by 89 of 92 real accounts — renaming this string, as opposed to the `ROLE_VOLUNTEER` constant that points to it, would silently break login/authorization for those accounts since the constant would no longer match any stored row). Greek UI text is swept separately since it doesn't share a Latin root with the English identifiers.

**Tech Stack:** PHP 8.2 (no framework), MariaDB 10.4.32. No automated test framework — verification is `php -l`, a throwaway smoke-test script, and live browser walkthroughs (established project convention this session).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-06-volunteer-to-member-rename-design.md`
- **Do not rename** any occurrence of `VolunteerOps`/`VOLUNTEEROPS`/`volunteerops` (the app's internal access-guard constant and its lowercase incidental occurrences) — confirmed via full-codebase audit this is purely internal plumbing, never rendered to a user.
- **Do not change** the stored ENUM value `'VOLUNTEER'` in `users.role` or `missions.type` (confirmed via user decision this session) — only the `ROLE_VOLUNTEER` PHP constant's *name* becomes `ROLE_MEMBER`; its *value* stays the literal string `'VOLUNTEER'`, exactly like the already-existing `'MEDICAL'`/`'RESCUER'`/`'TRAINEE_RESCUER'` legacy enum values this app already carries unrenamed.
- **Do not touch** `sql/migrations/*.sql` (historical, already-executed, timestamped one-off migration scripts) — these are an immutable historical record, not live schema.
- **Deviation from spec:** the spec listed renaming FK constraint names (e.g. `volunteer_certificates_ibfk_1`) and calling it "free while altering these tables anyway." That assumption turned out to be wrong: this MariaDB 10.4.32 install has no `RENAME INDEX` support (added only in MariaDB 10.5.2+, confirmed by testing against this exact install), so index renames require a DROP+CREATE pair, and FK constraint renames would require DROP+ADD FOREIGN KEY — real extra risk for a purely cosmetic, never-user-facing identifier. Task 1 renames only the two plain (non-FK) indexes that literally contain "volunteer" in their name; FK constraint names and the abbreviated `idx_*_vol` indexes are left as-is.
- **Do not touch** `docs/`, `backups/`, `.superpowers/`, `.git/` — planning docs and historical snapshots, not live app code.
- A full `mysqldump` backup of every database on this shared local MariaDB instance was taken before this plan's work started: `C:\Users\user\Desktop\easyride_pre_rename_backup_20260706\`. If anything goes wrong, `easyride.sql` in that folder restores the pre-rename state.
- PHP binary: `C:\xampp\php\php.exe` (not on PATH). MySQL client: `C:\xampp\mysql\bin\mysql.exe`.
- Sync command after each task (PowerShell): `robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log` — **note:** this excludes the real `exports/` directory from sync (pre-existing project convention, not something to change), so files under `exports/` are edited in the source tree but won't appear in the synced htdocs copy; this matches how `exports/` already behaves for every other file in it.
- Work directly on the `main` branch (established workflow this session, user has confirmed this repeatedly).
- The user has explicitly asked for continuous execution across all tasks without pausing for approval between them — but every task still gets its full verification steps; "no pausing for approval" does not mean skipping `php -l`, dry-runs, or diff review.

---

### Task 1: Database migration — rename tables, columns, indexes

**Files:**
- Modify: `includes/migrations.php` (add migration version 76 at the end of the `$migrations` array, before the closing `];` — currently at line 4410-4412, following the exact pattern of migration 75 immediately above it)
- Modify: `config.php:15` (`DB_SCHEMA_VERSION` constant, `75` → `76`)

**Interfaces:**
- Consumes: `dbExecute(string $sql, array $params = [])`, `dbFetchValue(string $sql, array $params = [])` (pre-existing, `includes/db.php`).
- Produces: renamed tables `member_certificates`, `member_documents`, `member_pings`, `member_points`, `member_positions`, `member_profiles`; renamed columns `participation_requests.member_id`, `shift_swap_requests.from_member_id`/`to_member_id`/`to_member_responded_at`, `users.member_type`, `shifts.max_members`/`min_members`, `inventory_bookings.member_name`/`member_phone`/`member_email` — all consumed by Task 3's rename script (which updates every PHP query referencing them) and Task 4's verification.

- [ ] **Step 1: Add migration 76**

In `includes/migrations.php`, find (currently lines 4386-4410):
```php
        [
            'version'     => 75,
            'description' => 'Create mission_days table for multi-day mission itineraries',
            'up' => function () {
                dbExecute(
                    "CREATE TABLE IF NOT EXISTS mission_days (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        mission_id INT UNSIGNED NOT NULL,
                        day_number TINYINT UNSIGNED NOT NULL,
                        day_date DATE NOT NULL,
                        title VARCHAR(160) NULL,
                        overnight_notes TEXT NULL,
                        route_points MEDIUMTEXT NULL,
                        route_geometry MEDIUMTEXT NULL,
                        route_distance_meters INT UNSIGNED NULL,
                        route_duration_seconds INT UNSIGNED NULL,
                        route_provider VARCHAR(40) NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_mission_day (mission_id, day_number),
                        CONSTRAINT fk_mission_days_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            },
        ],

    ];
```

Replace with (adds the new migration 76 entry before the closing `];`):
```php
        [
            'version'     => 75,
            'description' => 'Create mission_days table for multi-day mission itineraries',
            'up' => function () {
                dbExecute(
                    "CREATE TABLE IF NOT EXISTS mission_days (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        mission_id INT UNSIGNED NOT NULL,
                        day_number TINYINT UNSIGNED NOT NULL,
                        day_date DATE NOT NULL,
                        title VARCHAR(160) NULL,
                        overnight_notes TEXT NULL,
                        route_points MEDIUMTEXT NULL,
                        route_geometry MEDIUMTEXT NULL,
                        route_distance_meters INT UNSIGNED NULL,
                        route_duration_seconds INT UNSIGNED NULL,
                        route_provider VARCHAR(40) NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_mission_day (mission_id, day_number),
                        CONSTRAINT fk_mission_days_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            },
        ],
        [
            'version'     => 76,
            'description' => 'Rename volunteer_* tables/columns/indexes to member_* (volunteer to member terminology rename)',
            'up' => function () {
                // ── Rename tables (RENAME TABLE auto-updates existing FK references) ──
                $tableRenames = [
                    'volunteer_certificates' => 'member_certificates',
                    'volunteer_documents'    => 'member_documents',
                    'volunteer_pings'        => 'member_pings',
                    'volunteer_points'       => 'member_points',
                    'volunteer_positions'    => 'member_positions',
                    'volunteer_profiles'     => 'member_profiles',
                ];
                foreach ($tableRenames as $old => $new) {
                    $exists = dbFetchValue(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                        [$old]
                    );
                    if ($exists) {
                        dbExecute("RENAME TABLE `$old` TO `$new`");
                    }
                }

                // ── Rename columns (CHANGE COLUMN auto-updates existing FKs on that column) ──
                $columnRenames = [
                    ['participation_requests', 'volunteer_id', "`member_id` int(10) unsigned NOT NULL"],
                    ['shift_swap_requests', 'from_volunteer_id', "`from_member_id` int(10) unsigned NOT NULL"],
                    ['shift_swap_requests', 'to_volunteer_id', "`to_member_id` int(10) unsigned NOT NULL"],
                    ['shift_swap_requests', 'to_volunteer_responded_at', "`to_member_responded_at` datetime DEFAULT NULL"],
                    ['users', 'volunteer_type', "`member_type` enum('TRAINEE_RESCUER','RESCUER') NOT NULL DEFAULT 'RESCUER'"],
                    ['shifts', 'max_volunteers', "`max_members` int(11) DEFAULT 5"],
                    ['shifts', 'min_volunteers', "`min_members` int(11) DEFAULT 1"],
                    ['inventory_bookings', 'volunteer_name', "`member_name` varchar(255) DEFAULT NULL"],
                    ['inventory_bookings', 'volunteer_phone', "`member_phone` varchar(20) DEFAULT NULL"],
                    ['inventory_bookings', 'volunteer_email', "`member_email` varchar(255) DEFAULT NULL"],
                ];
                foreach ($columnRenames as [$table, $oldCol, $newColDef]) {
                    $exists = dbFetchValue(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                        [$table, $oldCol]
                    );
                    if ($exists) {
                        dbExecute("ALTER TABLE `$table` CHANGE COLUMN `$oldCol` $newColDef");
                    }
                }

                // ── Rename indexes (MariaDB 10.4 has no RENAME INDEX; drop + recreate) ──
                $indexRenames = [
                    ['participation_requests', 'idx_participation_volunteer', 'idx_participation_member', 'member_id'],
                    ['users', 'idx_volunteer_type', 'idx_member_type', 'member_type'],
                ];
                foreach ($indexRenames as [$table, $oldIdx, $newIdx, $column]) {
                    $exists = dbFetchValue(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                        [$table, $oldIdx]
                    );
                    if ($exists) {
                        dbExecute("ALTER TABLE `$table` DROP INDEX `$oldIdx`");
                        dbExecute("ALTER TABLE `$table` ADD INDEX `$newIdx` (`$column`)");
                    }
                }
            },
        ],

    ];
```

Note: `idx_pr_vol_status`, `idx_pr_vol_attended`, `idx_ssr_from_vol`, `idx_ssr_to_vol` on these same tables use an abbreviated "vol" in their names, not the literal word "volunteer" — per the Global Constraints these are left unrenamed (out of scope; cosmetic index-name abbreviations, not the "volunteer" word itself). They automatically continue working correctly regardless, since they follow their columns through the rename (confirmed by this session's own tests of this exact mechanism).

- [ ] **Step 2: Bump `DB_SCHEMA_VERSION`**

In `config.php:15`, change:
```php
define('DB_SCHEMA_VERSION', 75);
```
to:
```php
define('DB_SCHEMA_VERSION', 76);
```

- [ ] **Step 3: Lint and trigger the migration**

```bash
C:\xampp\php\php.exe -l includes/migrations.php
C:\xampp\php\php.exe -l config.php
```
Expected: `No syntax errors detected` for both.

Sync to htdocs so the live install picks up the change (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

Trigger the migration by loading any page through the running Apache/PHP stack (the migration runner runs automatically on every request via `runSchemaMigrations()`), or run this throwaway script directly:

Create `_run_migration.php` at the repo root:
```php
<?php
define('VOLUNTEEROPS', true);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/migrations.php';
runSchemaMigrations();
echo "db_schema_version is now: " . dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'") . "\n";
```

Run: `C:\xampp\php\php.exe _run_migration.php`
Expected: `db_schema_version is now: 76`

Delete the throwaway script: `rm _run_migration.php` (do not commit it).

- [ ] **Step 4: Verify the schema changes landed correctly**

```bash
C:\xampp\mysql\bin\mysql.exe -u root easyride -e "SHOW TABLES LIKE 'member_%';"
```
Expected: 6 rows — `member_certificates`, `member_documents`, `member_pings`, `member_points`, `member_positions`, `member_profiles`.

```bash
C:\xampp\mysql\bin\mysql.exe -u root easyride -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='easyride' AND column_name IN ('member_id','from_member_id','to_member_id','to_member_responded_at','member_type','max_members','min_members','member_name','member_phone','member_email');"
```
Expected: `10`

```bash
C:\xampp\mysql\bin\mysql.exe -u root easyride -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='easyride' AND table_name LIKE 'volunteer_%';"
```
Expected: `0` (all old table names gone)

```bash
C:\xampp\mysql\bin\mysql.exe -u root easyride -e "SELECT role, COUNT(*) FROM users GROUP BY role;"
```
Expected: `VOLUNTEER` still appears as a role value with the same count as before (89) — confirming the ENUM value itself was correctly left untouched, only the schema (table/column names) changed.

- [ ] **Step 5: Commit**

```bash
git add includes/migrations.php config.php
git commit -m "Rename volunteer_* tables/columns/indexes to member_* in database schema"
```

---

### Task 2: Physical file renames + redirect stubs

**Files:**
- Rename (via `git mv`): `volunteers.php`→`members.php`, `volunteer-view.php`→`member-view.php`, `volunteer-form.php`→`member-form.php`, `volunteer-positions.php`→`member-positions.php`, `volunteer-report.php`→`member-report.php`, `volunteer-status.php`→`member-status.php`, `volunteer-doc-download.php`→`member-doc-download.php`, `exports/import-volunteers.php`→`exports/import-members.php`, `inactive-volunteers.php`→`inactive-members.php`
- Create: 9 redirect stub files at the old paths (listed above, left behind after the `git mv`)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: the 9 new filenames, consumed by Task 3's rename script (which updates every `href`/`action`/`fetch()` string reference to point at them) and Task 4's verification.

- [ ] **Step 1: Rename the files**

```bash
cd "C:\Users\user\Desktop\easyride"
git mv volunteers.php members.php
git mv volunteer-view.php member-view.php
git mv volunteer-form.php member-form.php
git mv volunteer-positions.php member-positions.php
git mv volunteer-report.php member-report.php
git mv volunteer-status.php member-status.php
git mv volunteer-doc-download.php member-doc-download.php
git mv exports/import-volunteers.php exports/import-members.php
git mv inactive-volunteers.php inactive-members.php
```

- [ ] **Step 2: Create the 9 redirect stubs**

Create `volunteers.php`:
```php
<?php
header('Location: members.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
```

Create `volunteer-view.php`:
```php
<?php
header('Location: member-view.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
```

Create `volunteer-form.php`:
```php
<?php
header('Location: member-form.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
```

Create `volunteer-positions.php`:
```php
<?php
header('Location: member-positions.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
```

Create `volunteer-report.php`:
```php
<?php
header('Location: member-report.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
```

Create `volunteer-status.php`:
```php
<?php
header('Location: member-status.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
```

Create `volunteer-doc-download.php`:
```php
<?php
header('Location: member-doc-download.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
```

Create `exports/import-volunteers.php`:
```php
<?php
header('Location: import-members.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
```

Create `inactive-volunteers.php`:
```php
<?php
header('Location: inactive-members.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
```

- [ ] **Step 3: Lint everything touched**

```bash
C:\xampp\php\php.exe -l members.php
C:\xampp\php\php.exe -l member-view.php
C:\xampp\php\php.exe -l member-form.php
C:\xampp\php\php.exe -l member-positions.php
C:\xampp\php\php.exe -l member-report.php
C:\xampp\php\php.exe -l member-status.php
C:\xampp\php\php.exe -l member-doc-download.php
C:\xampp\php\php.exe -l exports/import-members.php
C:\xampp\php\php.exe -l inactive-members.php
C:\xampp\php\php.exe -l volunteers.php
C:\xampp\php\php.exe -l volunteer-view.php
C:\xampp\php\php.exe -l volunteer-form.php
C:\xampp\php\php.exe -l volunteer-positions.php
C:\xampp\php\php.exe -l volunteer-report.php
C:\xampp\php\php.exe -l volunteer-status.php
C:\xampp\php\php.exe -l volunteer-doc-download.php
C:\xampp\php\php.exe -l exports/import-volunteers.php
C:\xampp\php\php.exe -l inactive-volunteers.php
```
Expected: `No syntax errors detected` for all 18.

Note: internal `href`/`action` links throughout the codebase still point at the OLD filenames at this point in the plan — that's expected and fine, since the redirect stubs mean nothing is broken yet; Task 3 updates every such reference in the same pass as the rest of the identifier rename.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "Rename volunteer-*.php files to member-*.php with redirect stubs"
```

---

### Task 3: Automated identifier/text rename script — dry run, review, apply

**Files:**
- Create (throwaway, not committed): `_rename_volunteer_to_member.php`
- Modify: every `.php`/`.js` file in the repo containing "volunteer" text (approx. 140 files per the spec's audit), plus `sql/schema.sql`

**Interfaces:**
- Consumes: the renamed tables/columns from Task 1, the renamed filenames from Task 2 (both needed so this script's blind text substitution produces a codebase that's internally consistent with what already exists on disk/in the DB).
- Produces: a codebase where every PHP/JS identifier, SQL query, href/URL string, and English UI string says "member" instead of "volunteer", except the two protected exceptions in Global Constraints — consumed by Task 4 (verification) and Task 5 (Greek text sweep, which only needs to handle the Greek alphabet forms since this task already covers everything Latin-alphabet).

- [ ] **Step 1: Write the rename script**

Create `_rename_volunteer_to_member.php` at the repo root:
```php
<?php
/**
 * Throwaway rename script. Not committed. Deleted after use.
 * Usage: php _rename_volunteer_to_member.php --dry-run
 *        php _rename_volunteer_to_member.php
 */

$repoRoot = __DIR__;
$excludeDirs = ['.git', 'backups', '.superpowers', 'docs'];
$includeExtensions = ['php', 'js'];
$extraFiles = ['sql' . DIRECTORY_SEPARATOR . 'schema.sql'];

$dryRun = in_array('--dry-run', $argv, true);

function isExcluded(string $dirPath, array $excludeDirs, string $repoRoot): bool {
    $rel = ltrim(str_replace($repoRoot, '', $dirPath), DIRECTORY_SEPARATOR);
    foreach ($excludeDirs as $ex) {
        if ($rel === $ex || strpos($rel, $ex . DIRECTORY_SEPARATOR) === 0) {
            return true;
        }
    }
    return false;
}

function collectFiles(string $repoRoot, array $excludeDirs, array $includeExtensions, array $extraFiles): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isDir()) continue;
        if (isExcluded($fileInfo->getPath(), $excludeDirs, $repoRoot)) continue;
        $ext = strtolower($fileInfo->getExtension());
        if (in_array($ext, $includeExtensions, true)) {
            $files[] = $fileInfo->getPathname();
        }
    }
    foreach ($extraFiles as $extra) {
        $full = $repoRoot . DIRECTORY_SEPARATOR . $extra;
        if (file_exists($full) && !in_array($full, $files, true)) {
            $files[] = $full;
        }
    }
    return $files;
}

function protectExact(string $content, string $needle, string $tokenPrefix, array &$store): string {
    $result = '';
    $offset = 0;
    while (($pos = strpos($content, $needle, $offset)) !== false) {
        $result .= substr($content, $offset, $pos - $offset);
        $key = "\x01" . $tokenPrefix . count($store) . "\x02";
        $store[$key] = $needle;
        $result .= $key;
        $offset = $pos + strlen($needle);
    }
    $result .= substr($content, $offset);
    return $result;
}

$files = collectFiles($repoRoot, $excludeDirs, $includeExtensions, $extraFiles);
$changed = [];

foreach ($files as $path) {
    $original = file_get_contents($path);
    $content = $original;
    $protected = [];

    // Protect the VolunteerOps family (app's internal access-guard constant; never user-facing)
    $content = protectExact($content, 'VOLUNTEEROPS', 'A', $protected);
    $content = protectExact($content, 'VolunteerOps', 'A', $protected);
    $content = protectExact($content, 'volunteerops', 'A', $protected);

    // Protect the literal stored ENUM value (users.role / missions.type data, not an identifier)
    $content = protectExact($content, "'VOLUNTEER'", 'B', $protected);
    $content = protectExact($content, '"VOLUNTEER"', 'B', $protected);

    // General case-variant rename (covers table/column names, PHP identifiers, hrefs, English UI text)
    $content = str_replace('VOLUNTEER', 'MEMBER', $content);
    $content = str_replace('Volunteer', 'Member', $content);
    $content = str_replace('volunteer', 'member', $content);

    // Restore protected tokens
    $content = strtr($content, $protected);

    if ($content !== $original) {
        $changed[] = $path;
        if (!$dryRun) {
            file_put_contents($path, $content);
        }
    }
}

echo ($dryRun ? "[DRY RUN] " : "") . "Files changed: " . count($changed) . "\n";
foreach ($changed as $c) {
    echo "  " . str_replace($repoRoot . DIRECTORY_SEPARATOR, '', $c) . "\n";
}
```

- [ ] **Step 2: Dry run and review**

```bash
C:\xampp\php\php.exe _rename_volunteer_to_member.php --dry-run > _rename_dry_run_report.txt
```

Check the reported file count is in the expected ballpark (spec's audit found "volunteer" in 140 PHP files; the file-count here may differ slightly since it also counts `.js` files and `sql/schema.sql`, and excludes `docs/`).

Confirm the two protections are actually holding by re-scanning: since this is a dry run, nothing has changed on disk yet, so verify the SCRIPT LOGIC is sound instead — run this targeted check against `config.php` specifically, which is the highest-stakes file (it defines both `ROLE_VOLUNTEER` and the "VOLUNTEEROPS" guard):

```bash
C:\xampp\php\php.exe -r "
define('VOLUNTEEROPS', true);
\$content = file_get_contents('config.php');
require 'includes/db.php'; // not used, just confirms no fatal parse issue in isolation
echo 'Contains VOLUNTEEROPS: ' . (strpos(\$content, 'VOLUNTEEROPS') !== false ? 'yes' : 'no') . PHP_EOL;
echo \"Contains define('ROLE_VOLUNTEER': \" . (strpos(\$content, \"define('ROLE_VOLUNTEER'\") !== false ? 'yes' : 'no') . PHP_EOL;
"
```
Expected: both `yes` (confirms the source file has the exact strings the protections target, before the rename runs).

- [ ] **Step 3: Apply for real**

```bash
C:\xampp\php\php.exe _rename_volunteer_to_member.php
```
Expected: same file count as the dry run.

- [ ] **Step 4: Verify the two protections held**

```bash
grep -c "VOLUNTEEROPS" config.php
grep -n "define('ROLE_MEMBER'" config.php
```
Expected: `VOLUNTEEROPS` count unchanged from before (still present, untouched), and a new line `define('ROLE_MEMBER', 'VOLUNTEER');` — confirming the constant's NAME was renamed but its VALUE (the literal string matching real stored data) was not.

```bash
C:\xampp\php\php.exe -r "
\$content = file_get_contents('exam-admin.php');
echo (strpos(\$content, \"role = 'VOLUNTEER'\") !== false) ? 'PASS: literal enum comparison preserved' . PHP_EOL : 'FAIL: literal enum comparison was altered' . PHP_EOL;
"
```
Expected: `PASS: literal enum comparison preserved`

- [ ] **Step 5: Lint every changed PHP file**

```bash
for f in $(cat _rename_dry_run_report.txt | grep '\.php$'); do C:\xampp\php\php.exe -l "$f" || echo "LINT FAILED: $f"; done
```
Expected: every file reports `No syntax errors detected`; no `LINT FAILED` lines. (Run this from Git Bash; if any file fails, open it and fix the syntax error before proceeding — do not proceed to Task 4 with a lint failure.)

- [ ] **Step 6: Confirm completeness — no unprotected "volunteer" identifiers remain**

```bash
grep -rio "[a-zA-Z]*volunteer[a-zA-Z]*" --include="*.php" --include="*.js" . 2>/dev/null | grep -v backups | grep -vi "volunteerops" | sort -u
```
Expected: empty output (every remaining match should be one of the `VolunteerOps`/`VOLUNTEEROPS`/`volunteerops` family, filtered out above; anything else printed here means the automated pass missed an occurrence and needs a manual fix).

- [ ] **Step 7: Clean up and commit**

```bash
rm _rename_volunteer_to_member.php _rename_dry_run_report.txt
```

Sync to htdocs (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

```bash
git add -A
git commit -m "Rename volunteer identifiers, queries, and UI text to member (automated pass)"
```

---

### Task 4: Post-rename verification

**Files:**
- No files modified — this task is pure verification. If it finds a bug, fix it in the specific file(s) affected and re-verify before committing that fix.

**Interfaces:**
- Consumes: everything from Tasks 1-3.
- Produces: confidence the app actually works, before moving to the (lower-risk) Greek text sweep.

- [ ] **Step 1: Functional smoke test against the renamed schema**

Create `_verify_rename.php` at the repo root:
```php
<?php
define('VOLUNTEEROPS', true);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';

function assertTrue(bool $cond, string $label): void {
    echo ($cond ? "PASS: " : "FAIL: ") . $label . "\n";
    if (!$cond) $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + 1;
}
$GLOBALS['failures'] = 0;

$userCount = (int) dbFetchValue("SELECT COUNT(*) FROM users");
assertTrue($userCount > 0, "users table readable, count=$userCount");

$volunteerRoleCount = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE role = 'VOLUNTEER'");
assertTrue($volunteerRoleCount > 0, "role='VOLUNTEER' literal still matches real rows, count=$volunteerRoleCount");

$memberProfileCount = (int) dbFetchValue("SELECT COUNT(*) FROM member_profiles");
assertTrue($memberProfileCount >= 0, "member_profiles table exists and is queryable");

$participationCount = (int) dbFetchValue("SELECT COUNT(*) FROM participation_requests WHERE member_id IS NOT NULL");
assertTrue($participationCount >= 0, "participation_requests.member_id column exists and is queryable");

$shiftRow = dbFetchOne("SELECT max_members, min_members FROM shifts LIMIT 1");
assertTrue($shiftRow !== null, "shifts.max_members/min_members columns exist and are queryable");

echo $GLOBALS['failures'] === 0 ? "\nALL PASS\n" : "\n{$GLOBALS['failures']} FAILURE(S)\n";
exit($GLOBALS['failures'] === 0 ? 0 : 1);
```

Run: `C:\xampp\php\php.exe _verify_rename.php`
Expected: all 5 assertions PASS, ending with `ALL PASS`.

Delete afterward: `rm _verify_rename.php` (do not commit).

- [ ] **Step 2: Live browser walkthrough**

Sync to htdocs if not already done (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

In the browser, logged in as an existing user:
1. Load `members.php` directly — confirm the member list loads with real data (names, roles), no fatal errors.
2. Load `volunteers.php` (old URL) — confirm it 301-redirects to `members.php`.
3. Click into a member's profile (`member-view.php?id=...`) — confirm it loads (was `volunteer-view.php` before this plan).
4. Load `volunteer-view.php?id=...` (old URL, same id) directly — confirm it redirects to `member-view.php?id=...` with the query string preserved.
5. Open `dashboard.php` — confirm it loads without errors (this file has dozens of renamed identifiers).
6. Open a shift's page (`shift-view.php`) and confirm the participant list, add-member modal, and any swap-request UI still work.
7. Open `certificates.php` — confirm the certificates list (now backed by `member_certificates`) loads.
8. Check the browser console for JS errors on each page (the `ops-dashboard.php`/`shift-view.php` inline `<script>` blocks had several renamed JS variable names — `volunteerPins`→`memberPins` etc. — confirm nothing references a variable name that no longer exists).

If anything is broken, use `superpowers:systematic-debugging` to find the root cause (likely a spot the automated script's exact-string matching missed, or a case-sensitivity mismatch) before proceeding.

- [ ] **Step 3: Commit** (only if Step 1 or Step 2 required a fix; skip if both passed cleanly)

```bash
git add -A
git commit -m "Fix remaining issue found during post-rename verification"
```

---

### Task 5: Greek UI text sweep

**Files:**
- Modify: files containing Greek "Εθελοντ-" forms (audit found 128 occurrences across roughly 40 files this session — exact file list obtained via the grep in Step 1 below, since the file set may have shifted slightly after Tasks 1-4's edits)

**Interfaces:**
- Consumes: nothing structural from earlier tasks (this is independent, string-only content).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Safe automatic substitutions**

These two Greek forms have an unambiguous, grammatically-correct 1:1 replacement regardless of context (both are neuter-noun forms that don't change meaning based on surrounding words):
- "Εθελοντές" (nominative/accusative plural, "the volunteers") → "Μέλη" (nominative/accusative plural of "μέλος", "the members")
- "Εθελοντών" (genitive plural, "of the volunteers") → "Μελών" (genitive plural of "μέλος", "of the members")

Create `_rename_greek_safe.php` at the repo root:
```php
<?php
$root = __DIR__;
$exclude = ['.git', 'backups', '.superpowers', 'docs'];
$changed = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->isDir() || strtolower($file->getExtension()) !== 'php') continue;
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    $skip = false;
    foreach ($exclude as $ex) {
        if (strpos($rel, $ex . DIRECTORY_SEPARATOR) === 0) { $skip = true; break; }
    }
    if ($skip) continue;

    $original = file_get_contents($file->getPathname());
    $content = str_replace('Εθελοντές', 'Μέλη', $original);
    $content = str_replace('Εθελοντών', 'Μελών', $content);

    if ($content !== $original) {
        file_put_contents($file->getPathname(), $content);
        $changed[] = $rel;
    }
}
echo "Files changed: " . count($changed) . "\n";
foreach ($changed as $c) echo "  $c\n";
```

Run: `C:\xampp\php\php.exe _rename_greek_safe.php`

Delete afterward: `rm _rename_greek_safe.php` (do not commit).

- [ ] **Step 2: Manual review of ambiguous grammatical forms**

The remaining forms need per-occurrence judgment, since Greek "μέλος" (a neuter noun) doesn't decline the same way as "εθελοντής" (masculine) — a blind substitution would produce grammatically wrong text:

Find every remaining occurrence:
```bash
grep -rn "Εθελοντή\|Εθελοντική\|Εθελοντικές\|Εθελοντικός\|Εθελοντής" --include="*.php" . 2>/dev/null | grep -v backups
```

For each match, apply this rule:
- **"Εθελοντής"** (nominative singular, "the volunteer") → replace with **"Μέλος"** (nominative singular of "μέλος") — this one IS a safe direct substitution (both are nominative singular in the same grammatical role), so replace every occurrence of "Εθελοντής" with "Μέλος" directly.
- **"Εθελοντή"** (accusative or genitive singular, ambiguous without reading the sentence) — read the surrounding Greek text: if it's the object of a verb or preposition (accusative — e.g. "βλέπω τον Εθελοντή" / "τον Εθελοντή" style usage), replace with "Μέλος" (accusative singular of "μέλος" is identical to nominative for this neuter noun). If it's possessive/"of the volunteer" (genitive — e.g. "στοιχεία του Εθελοντή" / "του Εθελοντή"), replace with "Μέλους" (genitive singular of "μέλος"). Look at the word immediately before it: "τον Εθελοντή" → accusative → "το Μέλος"; "του Εθελοντή" → genitive → "του Μέλους".
- **"Εθελοντική"**, **"Εθελοντικές"**, **"Εθελοντικός"** (adjective forms meaning "voluntary") — "μέλος" has no natural parallel adjective. Reword the phrase rather than substituting word-for-word: e.g. "Εθελοντική Δράση" ("Voluntary Action", used as placeholder/sample text in email templates) becomes "Δράση Μελών" ("Members' Action") or similar natural phrasing appropriate to that specific sentence — read each occurrence's context and rewrite the phrase naturally rather than attempting a mechanical word swap.

Fix each file found by the grep above directly with the Edit tool, reading enough surrounding context in each case to pick the grammatically correct form.

- [ ] **Step 3: Verify no Greek volunteer-forms remain**

```bash
grep -rn "Εθελοντ" --include="*.php" . 2>/dev/null | grep -v backups
```
Expected: empty output.

- [ ] **Step 4: Lint every file touched in this task**

```bash
git diff --name-only | grep '\.php$' | while read -r f; do C:\xampp\php\php.exe -l "$f" || echo "LINT FAILED: $f"; done
```
Expected: every file `No syntax errors detected`, no `LINT FAILED` lines.

- [ ] **Step 5: Live browser spot-check**

Sync to htdocs (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

In the browser, open `certificates.php`, `attendance.php`, and `branches.php` (three of the files with the highest concentration of Greek "Εθελοντ-" text found in the original audit) — confirm the visible Greek text now reads "Μέλος"/"Μέλη"/"Μελών" and reads naturally (not grammatically broken), and that CSV export headers (`certificates.php`'s export) show the updated Greek labels too.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Sweep remaining Greek Εθελοντ- UI text to Μέλος/Μέλη terminology"
```

---

### Task 6: Version bump and release notes

**Files:**
- Modify: `config.php` (`APP_VERSION`)
- Modify: `README.md` (version badge/line + new "Current Release Highlights" entry)

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing consumed by other tasks — bookkeeping to close out the feature per this project's release workflow.

- [ ] **Step 1: Check for an existing tag at the current version**

```bash
gh release list --limit 5
```

Current `APP_VERSION` (check `config.php`) — if a tag already exists at that version, bump to the next patch version; this rename touches so much of the app that it should ship as its own release regardless of what patch number that ends up being.

- [ ] **Step 2: Bump `APP_VERSION`**

In `config.php`, update `define('APP_VERSION', '...')` to the next patch version, following the exact pattern already in that file.

- [ ] **Step 3: Add a README highlight entry**

In `README.md`, under `## Current Release Highlights`, add a new `### vX.Y.Z` section above the most recent one:

```markdown
### vX.Y.Z

- Renamed "volunteer"/"Εθελοντής" to "member"/"Μέλος" throughout the application — database tables and columns, file names and URLs (old URLs redirect automatically), PHP code, and Greek/English UI text. Internal-only technical identifiers (the app's original access-guard constant, and the literal database enum value used for existing user roles) are intentionally left unchanged since they're invisible to users and renaming them would have added authentication risk for no visible benefit.
```

Also update the `**Version:**` line and the `![Version](...)` badge near the top of `README.md` to match.

- [ ] **Step 4: Lint, sync, commit**

```bash
C:\xampp\php\php.exe -l config.php
```

Sync (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

```bash
git add config.php README.md
git commit -m "Bump version for volunteer to member rename release"
```

(Tagging and creating the GitHub release is a separate, explicit step — matching this session's established workflow — done once the whole rename is confirmed stable.)
