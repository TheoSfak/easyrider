# Multi-Day Mission Days Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin draw a distinct route + overnight note per calendar day of a multi-day mission in `mission-form.php`, storing each day in a new `mission_days` table, while leaving single-day missions (the overwhelming majority) completely unchanged.

**Architecture:** A new `mission_days` table mirrors the existing per-mission route columns, one row per calendar day. Multi-day is detected automatically from the existing start/end datetime fields (different calendar dates = multi-day) — no new checkbox. On the client, the existing single route editor (map + fullscreen modal + point list, built earlier this session) is reused unchanged for whichever day is "active"; a thin day-switching layer swaps the in-memory route state in and out of a per-day store when the admin clicks a day tab, and a submit-time step serializes all days into one hidden field.

**Tech Stack:** PHP 8.2 (no framework), MySQL, vanilla JS, Leaflet 1.9.4, Bootstrap 5.3 (nav-pills for day tabs).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-05-multiday-mission-days-design.md`
- This is phase 1 of 3. `mission-view.php`, `ride-mode.php`, and `ops-dashboard.php` are NOT touched by this plan and will not display any `mission_days` data yet — this is an explicitly accepted interim limitation, not a gap to fix here.
- No changes to `shifts` or `mission_recurrences` — unrelated existing features. The **recurring-mission creation path** (`is_recurring` checkbox, creates N separate missions from one form submit) is explicitly out of scope for multi-day: it keeps using the flat single-day `route_points`/etc. fields exactly as today, and does not read `mission_days_json`. Multi-day only applies to the single-mission create path and the edit path.
- Single-day missions (start/end on the same calendar date) must be pixel-for-pixel, behavior-for-behavior unchanged from before this plan — every task's manual verification must include re-confirming this.
- No automated test framework exists in this repo. Verify PHP logic with a throwaway CLI script run via `C:\xampp\php\php.exe` (created, run, then deleted — never committed), and verify UI/JS behavior manually in the browser, per this project's established convention this session.

---

### Task 1: `mission_days` table (migration + schema.sql + version bump)

**Files:**
- Modify: `includes/migrations.php` (add migration version 75, after the version-74 block which currently ends the migrations array around line 4383)
- Modify: `sql/schema.sql` (add the same `CREATE TABLE` for fresh installs, following the existing convention of keeping schema.sql in sync with migrations — see e.g. the `ride_events`/`ride_checklist_items` tables already present in both places)
- Modify: `config.php` (`DB_SCHEMA_VERSION` 74 → 75)

**Interfaces:**
- Produces: table `mission_days` with columns `id, mission_id, day_number, day_date, title, overnight_notes, route_points, route_geometry, route_distance_meters, route_duration_seconds, route_provider, created_at, updated_at`, unique on `(mission_id, day_number)`, `ON DELETE CASCADE` from `missions`.

- [ ] **Step 1: Add the migration**

In `includes/migrations.php`, find the migration array entry for version 74 (search for `'version'     => 74,`) and add this new entry immediately after its closing `],` (still inside the `$migrations = [ ... ];` array, before the array's closing `];`):

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
```

- [ ] **Step 2: Add the same table to `sql/schema.sql`**

Open `sql/schema.sql`, find the `ride_checklist_items` table definition (added by migration 71), and add this block immediately after it (matching the existing formatting style in that file — unquoted column names, no `IF NOT EXISTS` line-break style differences, following the surrounding tables' exact style):

```sql
-- =============================================
-- MULTI-DAY MISSION ITINERARIES
-- =============================================
CREATE TABLE IF NOT EXISTS `mission_days` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mission_id` INT UNSIGNED NOT NULL,
    `day_number` TINYINT UNSIGNED NOT NULL,
    `day_date` DATE NOT NULL,
    `title` VARCHAR(160) NULL,
    `overnight_notes` TEXT NULL,
    `route_points` MEDIUMTEXT NULL,
    `route_geometry` MEDIUMTEXT NULL,
    `route_distance_meters` INT UNSIGNED NULL,
    `route_duration_seconds` INT UNSIGNED NULL,
    `route_provider` VARCHAR(40) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_mission_day` (`mission_id`, `day_number`),
    CONSTRAINT `fk_mission_days_mission` FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 3: Bump `DB_SCHEMA_VERSION`**

In `config.php`, change:
```php
define('DB_SCHEMA_VERSION', 74);
```
to:
```php
define('DB_SCHEMA_VERSION', 75);
```

- [ ] **Step 4: Verify the migration runs and creates the table**

Run: `C:\xampp\php\php.exe -l includes/migrations.php` and `C:\xampp\php\php.exe -l config.php` — both should report no syntax errors.

Sync to XAMPP so the running app picks up the migration (PowerShell, not the bash tool, so `/MIR` isn't mis-parsed):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```
(exit codes 0-7 are success)

Trigger the migration by loading any page of the app once (migrations run on every request per `includes/migrations.php`'s bottom `runSchemaMigrations();` call — e.g. `curl -s -o /dev/null -w "%{http_code}\n" http://localhost/easyride/login.php` is enough to execute it, expect `200`).

Then verify the table exists with a throwaway script:

Create `verify-mission-days-table.php` at the repo root:
```php
<?php
define('VOLUNTEEROPS', true);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';

$exists = dbFetchValue(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mission_days'"
);
echo $exists ? "PASS: mission_days table exists\n" : "FAIL: mission_days table missing\n";
exit($exists ? 0 : 1);
```

Run: `C:\xampp\php\php.exe verify-mission-days-table.php`
Expected: `PASS: mission_days table exists`

Then delete the throwaway script: `rm verify-mission-days-table.php` (do not commit it).

- [ ] **Step 5: Commit**

```bash
git add includes/migrations.php sql/schema.sql config.php
git commit -m "Add mission_days table for multi-day mission itineraries"
```

---

### Task 2: Backend PHP — load existing days on edit, save on create/update

**Files:**
- Modify: `mission-form.php` (add two new functions near `normalizeRoutePointsJson`/`normalizeRouteGeometryJson`, currently lines 36-100; modify the `$data` construction block around lines 104-139; modify the edit-load section around lines 348-373; modify the edit `UPDATE` path around lines 173-192 and the single-mission create path around lines 313-338)

**Interfaces:**
- Consumes: existing `normalizeRoutePointsJson(string $raw): string` and `normalizeRouteGeometryJson(string $raw): string` (already defined in this file).
- Produces:
  - `normalizeMissionDaysJson($raw): array` — decodes/validates the `mission_days_json` POST field into a clean array of day arrays, each shaped `['day_number' => int, 'day_date' => 'Y-m-d', 'title' => string, 'overnight_notes' => string, 'route_points' => string (JSON), 'route_geometry' => string (JSON or ''), 'route_distance_meters' => int, 'route_duration_seconds' => int, 'route_provider' => string]`. Returns `[]` if the input is empty/invalid.
  - `saveMissionDaysForMission(int $missionId, array $days): void` — deletes all existing `mission_days` rows for that mission and re-inserts the given `$days` (0 or more). Safe/idempotent to call on every save, including when `$days` is empty (correctly clears old rows if a mission shrinks from multi-day back to single-day).
  - PHP variable `$missionDaysInitial` (array, same shape as above minus the JSON-string route fields — those are decoded back into PHP arrays for the JS to consume) available when rendering the form, for both new missions (`[]`) and edits (loaded from DB).
  - PHP variable `$isMultiDayInitial` (bool) — whether the mission's current start/end dates already span multiple calendar days, used so phase-1's JS can decide the initial UI state without waiting for a client-side date computation to run first (avoids a flash of the wrong section).

- [ ] **Step 1: Add `normalizeMissionDaysJson()` and `saveMissionDaysForMission()`**

In `mission-form.php`, immediately after the closing `}` of `normalizeRouteGeometryJson()` (currently ending at line 100, right before `$errors = [];` at line 102), add:

```php
function normalizeMissionDaysJson($raw): array {
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $days = [];
    foreach ($decoded as $day) {
        if (!is_array($day) || !isset($day['day_number'], $day['day_date'])) {
            continue;
        }
        $dayNumber = (int)$day['day_number'];
        if ($dayNumber < 1 || $dayNumber > 60) {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day['day_date'])) {
            continue;
        }

        $routePointsRaw = json_encode($day['route_points'] ?? []);
        $routeGeometryRaw = json_encode($day['route_geometry'] ?? []);

        $days[] = [
            'day_number' => $dayNumber,
            'day_date' => $day['day_date'],
            'title' => mb_substr(trim((string)($day['title'] ?? '')), 0, 160),
            'overnight_notes' => mb_substr(trim((string)($day['overnight_notes'] ?? '')), 0, 2000),
            'route_points' => normalizeRoutePointsJson($routePointsRaw),
            'route_geometry' => normalizeRouteGeometryJson($routeGeometryRaw),
            'route_distance_meters' => max(0, (int)($day['route_distance_meters'] ?? 0)),
            'route_duration_seconds' => max(0, (int)($day['route_duration_seconds'] ?? 0)),
            'route_provider' => in_array($day['route_provider'] ?? '', ['google'], true) ? 'google' : '',
        ];

        if (count($days) >= 60) {
            break;
        }
    }

    usort($days, function ($a, $b) {
        return $a['day_number'] <=> $b['day_number'];
    });

    return $days;
}

function saveMissionDaysForMission(int $missionId, array $days): void {
    dbExecute("DELETE FROM mission_days WHERE mission_id = ?", [$missionId]);

    foreach ($days as $day) {
        $geometry = $day['route_geometry'];
        $distance = $day['route_distance_meters'];
        $duration = $day['route_duration_seconds'];
        $provider = $day['route_provider'];

        if ($day['route_points'] === '' || $provider !== 'google' || $geometry === '' || $distance <= 0 || $duration <= 0) {
            $geometry = null;
            $distance = null;
            $duration = null;
            $provider = null;
        }

        dbExecute(
            "INSERT INTO mission_days
             (mission_id, day_number, day_date, title, overnight_notes, route_points, route_geometry, route_distance_meters, route_duration_seconds, route_provider, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $missionId,
                $day['day_number'],
                $day['day_date'],
                $day['title'] !== '' ? $day['title'] : null,
                $day['overnight_notes'] !== '' ? $day['overnight_notes'] : null,
                $day['route_points'] !== '' ? $day['route_points'] : null,
                $geometry,
                $distance,
                $duration,
                $provider,
            ]
        );
    }
}
```

- [ ] **Step 2: Detect multi-day submission and null out the mission-level route fields**

In `mission-form.php`, find this block (currently lines 131-139):

```php
    // Routed geometry is kept only as a complete Google result for an actual route,
    // otherwise the app falls back to the straight-line estimate
    if ($data['route_points'] === '' || $data['route_provider'] !== 'google' || $data['route_geometry'] === ''
        || $data['route_distance_meters'] <= 0 || $data['route_duration_seconds'] <= 0) {
        $data['route_geometry'] = null;
        $data['route_distance_meters'] = null;
        $data['route_duration_seconds'] = null;
        $data['route_provider'] = null;
    }
```

Add immediately after it:

```php

    // Multi-day mission: route data lives in mission_days instead of the mission-level columns
    $missionDaysData = normalizeMissionDaysJson($_POST['mission_days_json'] ?? '');
    $isMultiDaySubmit = !empty($missionDaysData);
    if ($isMultiDaySubmit) {
        $data['route_points'] = '';
        $data['route_geometry'] = null;
        $data['route_distance_meters'] = null;
        $data['route_duration_seconds'] = null;
        $data['route_provider'] = null;
    }
```

- [ ] **Step 3: Save `mission_days` rows after the mission is saved (edit path)**

Find the edit path (currently lines 173-192, inside `if ($isEdit) { ... }`):

```php
            if ($isEdit) {
                $sql = "UPDATE missions SET
                        title = ?, description = ?, mission_type_id = ?, department_id = ?,
                        location = ?, location_details = ?, maps_link = ?, route_points = ?,
                        route_geometry = ?, route_distance_meters = ?, route_duration_seconds = ?, route_provider = ?,
                        latitude = ?, longitude = ?,
                        start_datetime = ?, end_datetime = ?, requirements = ?, notes = ?,
                        is_urgent = ?, status = ?, responsible_user_id = ?, updated_at = NOW()
                        WHERE id = ?";
                dbExecute($sql, [
                    $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
                    $data['location'], $data['location_details'], $data['maps_link'] ?: null, $data['route_points'] ?: null,
                    $data['route_geometry'], $data['route_distance_meters'], $data['route_duration_seconds'], $data['route_provider'],
                    $data['latitude'], $data['longitude'],
                    $data['start_datetime'], $data['end_datetime'], $data['requirements'], $data['notes'],
                    $data['is_urgent'], $data['status'], $data['responsible_user_id'], $id
                ]);
                
                logAudit('update', 'missions', $id, $mission, $data);
                setFlash('success', 'Η δράση ενημερώθηκε επιτυχώς.');
            } else {
```

Add `saveMissionDaysForMission($id, $missionDaysData);` right after the `dbExecute($sql, [...]);` call and before `logAudit(...)`:

```php
            if ($isEdit) {
                $sql = "UPDATE missions SET
                        title = ?, description = ?, mission_type_id = ?, department_id = ?,
                        location = ?, location_details = ?, maps_link = ?, route_points = ?,
                        route_geometry = ?, route_distance_meters = ?, route_duration_seconds = ?, route_provider = ?,
                        latitude = ?, longitude = ?,
                        start_datetime = ?, end_datetime = ?, requirements = ?, notes = ?,
                        is_urgent = ?, status = ?, responsible_user_id = ?, updated_at = NOW()
                        WHERE id = ?";
                dbExecute($sql, [
                    $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
                    $data['location'], $data['location_details'], $data['maps_link'] ?: null, $data['route_points'] ?: null,
                    $data['route_geometry'], $data['route_distance_meters'], $data['route_duration_seconds'], $data['route_provider'],
                    $data['latitude'], $data['longitude'],
                    $data['start_datetime'], $data['end_datetime'], $data['requirements'], $data['notes'],
                    $data['is_urgent'], $data['status'], $data['responsible_user_id'], $id
                ]);

                saveMissionDaysForMission($id, $missionDaysData);

                logAudit('update', 'missions', $id, $mission, $data);
                setFlash('success', 'Η δράση ενημερώθηκε επιτυχώς.');
            } else {
```

**Why unconditional (not gated on `$isMultiDaySubmit`):** this must run on every edit save so that shrinking a mission from multi-day back to single-day correctly deletes its old `mission_days` rows (an empty `$missionDaysData` array still triggers the `DELETE` inside `saveMissionDaysForMission()`, just with zero re-inserts).

- [ ] **Step 4: Save `mission_days` rows after the mission is saved (single-mission create path)**

Find the single-mission create path (currently lines 313-338, inside the `else { // ── SINGLE MISSION ...` block):

```php
                } else {
                    // ── SINGLE MISSION ───────────────────────────────────────────────
                    $sql = "INSERT INTO missions
                            (title, description, mission_type_id, department_id, location, location_details, maps_link, route_points,
                             route_geometry, route_distance_meters, route_duration_seconds, route_provider,
                             latitude, longitude, start_datetime, end_datetime, requirements, notes,
                             is_urgent, status, responsible_user_id, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $newId = dbInsert($sql, [
                        $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
                        $data['location'], $data['location_details'], $data['maps_link'] ?: null, $data['route_points'] ?: null,
                        $data['route_geometry'], $data['route_distance_meters'], $data['route_duration_seconds'], $data['route_provider'],
                        $data['latitude'], $data['longitude'],
                        $data['start_datetime'], $data['end_datetime'], $data['requirements'], $data['notes'],
                        $data['is_urgent'], $data['status'], $data['responsible_user_id'], $user['id']
                    ]);
                    dbInsert(
                        "INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers, min_volunteers, created_at, updated_at)
                         VALUES (?, ?, ?, 5, 1, NOW(), NOW())",
                        [$newId, $data['start_datetime'], $data['end_datetime']]
                    );

                    logAudit('create', 'missions', $newId, null, $data);
                    setFlash('success', 'Η δράση δημιουργήθηκε επιτυχώς.');
                    redirect('mission-view.php?id=' . $newId);
                }
```

Add `saveMissionDaysForMission($newId, $missionDaysData);` right after the shifts `dbInsert(...)` call:

```php
                } else {
                    // ── SINGLE MISSION ───────────────────────────────────────────────
                    $sql = "INSERT INTO missions
                            (title, description, mission_type_id, department_id, location, location_details, maps_link, route_points,
                             route_geometry, route_distance_meters, route_duration_seconds, route_provider,
                             latitude, longitude, start_datetime, end_datetime, requirements, notes,
                             is_urgent, status, responsible_user_id, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $newId = dbInsert($sql, [
                        $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
                        $data['location'], $data['location_details'], $data['maps_link'] ?: null, $data['route_points'] ?: null,
                        $data['route_geometry'], $data['route_distance_meters'], $data['route_duration_seconds'], $data['route_provider'],
                        $data['latitude'], $data['longitude'],
                        $data['start_datetime'], $data['end_datetime'], $data['requirements'], $data['notes'],
                        $data['is_urgent'], $data['status'], $data['responsible_user_id'], $user['id']
                    ]);
                    dbInsert(
                        "INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers, min_volunteers, created_at, updated_at)
                         VALUES (?, ?, ?, 5, 1, NOW(), NOW())",
                        [$newId, $data['start_datetime'], $data['end_datetime']]
                    );

                    saveMissionDaysForMission($newId, $missionDaysData);

                    logAudit('create', 'missions', $newId, null, $data);
                    setFlash('success', 'Η δράση δημιουργήθηκε επιτυχώς.');
                    redirect('mission-view.php?id=' . $newId);
                }
```

Do NOT add `mission_days_json` handling to the recurring-missions branch (the `if ($isRecurring) { ... }` block above this one) — per Global Constraints, recurring missions stay single-day/flat-route only.

- [ ] **Step 5: Load existing `mission_days` for edit, and compute the initial multi-day flag**

Find this block (currently lines 348-373):

```php
// Format dates for form
$startDate = '';
$endDate = '';
$startDateIso = '';
$endDateIso = '';
if ($mission) {
    $startDate = formatDateTime($mission['start_datetime'], 'd/m/Y H:i');
    $endDate   = formatDateTime($mission['end_datetime'],   'd/m/Y H:i');
    $startDateIso = date('Y-m-d\TH:i', strtotime($mission['start_datetime']));
    $endDateIso   = date('Y-m-d\TH:i', strtotime($mission['end_datetime']));
}
$routePointsForForm = normalizeRoutePointsJson(post('route_points', $mission['route_points'] ?? ''));
$routePointsInitial = json_decode($routePointsForForm ?: '[]', true) ?: [];
$routeGeometryForForm = normalizeRouteGeometryJson(post('route_geometry', $mission['route_geometry'] ?? ''));
$routeGeometryInitial = json_decode($routeGeometryForForm ?: '[]', true) ?: [];
$routeProviderInitial = post('route_provider', $mission['route_provider'] ?? '') === 'google' ? 'google' : '';
$routeDistanceInitial = max(0, (int)post('route_distance_meters', $mission['route_distance_meters'] ?? 0));
$routeDurationInitial = max(0, (int)post('route_duration_seconds', $mission['route_duration_seconds'] ?? 0));
if ($routeProviderInitial !== 'google' || empty($routeGeometryInitial) || $routeDistanceInitial <= 0 || $routeDurationInitial <= 0) {
    $routeGeometryForForm = '';
    $routeGeometryInitial = [];
    $routeProviderInitial = '';
    $routeDistanceInitial = 0;
    $routeDurationInitial = 0;
}
$googleRoutingEnabled = trim((string)getSetting('google_maps_api_key', '')) !== '';
```

Add immediately after it (before `include __DIR__ . '/includes/header.php';`):

```php

// Multi-day itinerary: load existing days on edit, and detect the initial multi-day state
$missionDaysInitial = [];
if ($isEdit) {
    $missionDayRows = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number", [$id]);
    foreach ($missionDayRows as $row) {
        $missionDaysInitial[] = [
            'day_number' => (int)$row['day_number'],
            'day_date' => $row['day_date'],
            'title' => $row['title'] ?? '',
            'overnight_notes' => $row['overnight_notes'] ?? '',
            'route_points' => json_decode($row['route_points'] ?? '[]', true) ?: [],
            'route_geometry' => json_decode($row['route_geometry'] ?? '[]', true) ?: [],
            'route_distance_meters' => (int)($row['route_distance_meters'] ?? 0),
            'route_duration_seconds' => (int)($row['route_duration_seconds'] ?? 0),
            'route_provider' => $row['route_provider'] ?? '',
        ];
    }
}

$isMultiDayInitial = false;
if (!empty($startDate) && !empty($endDate)) {
    $startD = DateTime::createFromFormat('d/m/Y H:i', $startDate);
    $endD = DateTime::createFromFormat('d/m/Y H:i', $endDate);
    if ($startD && $endD) {
        $isMultiDayInitial = $startD->format('Y-m-d') !== $endD->format('Y-m-d');
    }
}
```

- [ ] **Step 6: Verify with a throwaway script**

Create `verify-mission-days-save.php` at the repo root:

```php
<?php
define('VOLUNTEEROPS', true);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/mission-form.php'; // defines normalizeMissionDaysJson/saveMissionDaysForMission (guarded by isPost()/CLI has no $_POST, safe to include)
```

This direct include will not work cleanly because `mission-form.php` executes page logic (permission checks, header include) on require. Instead, copy just the two functions under test into the throwaway script for isolated testing:

```php
<?php
define('VOLUNTEEROPS', true);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';

function normalizeRoutePointsJson($raw): string {
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) return '';
    $points = [];
    foreach ($decoded as $point) {
        if (!is_array($point) || !isset($point['lat'], $point['lng'])) continue;
        $points[] = ['lat' => round((float)$point['lat'], 6), 'lng' => round((float)$point['lng'], 6), 'title' => (string)($point['title'] ?? ''), 'scheduled_time' => '', 'stop_minutes' => 0, 'notes' => ''];
    }
    return empty($points) ? '' : json_encode($points, JSON_UNESCAPED_UNICODE);
}
function normalizeRouteGeometryJson($raw): string {
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) return '';
    $geometry = [];
    foreach ($decoded as $pair) {
        if (!is_array($pair) || !isset($pair[0], $pair[1])) continue;
        $geometry[] = [round((float)$pair[0], 6), round((float)$pair[1], 6)];
    }
    return count($geometry) >= 2 ? json_encode($geometry) : '';
}

function normalizeMissionDaysJson($raw): array {
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) return [];
    $days = [];
    foreach ($decoded as $day) {
        if (!is_array($day) || !isset($day['day_number'], $day['day_date'])) continue;
        $dayNumber = (int)$day['day_number'];
        if ($dayNumber < 1 || $dayNumber > 60) continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day['day_date'])) continue;
        $days[] = [
            'day_number' => $dayNumber,
            'day_date' => $day['day_date'],
            'title' => mb_substr(trim((string)($day['title'] ?? '')), 0, 160),
            'overnight_notes' => mb_substr(trim((string)($day['overnight_notes'] ?? '')), 0, 2000),
            'route_points' => normalizeRoutePointsJson(json_encode($day['route_points'] ?? [])),
            'route_geometry' => normalizeRouteGeometryJson(json_encode($day['route_geometry'] ?? [])),
            'route_distance_meters' => max(0, (int)($day['route_distance_meters'] ?? 0)),
            'route_duration_seconds' => max(0, (int)($day['route_duration_seconds'] ?? 0)),
            'route_provider' => in_array($day['route_provider'] ?? '', ['google'], true) ? 'google' : '',
        ];
    }
    usort($days, function ($a, $b) { return $a['day_number'] <=> $b['day_number']; });
    return $days;
}
function saveMissionDaysForMission(int $missionId, array $days): void {
    dbExecute("DELETE FROM mission_days WHERE mission_id = ?", [$missionId]);
    foreach ($days as $day) {
        dbExecute(
            "INSERT INTO mission_days (mission_id, day_number, day_date, title, overnight_notes, route_points, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$missionId, $day['day_number'], $day['day_date'], $day['title'] ?: null, $day['overnight_notes'] ?: null, $day['route_points'] ?: null]
        );
    }
}

function assertTrue(bool $cond, string $label): void {
    echo ($cond ? "PASS: " : "FAIL: ") . $label . "\n";
    if (!$cond) $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + 1;
}
$GLOBALS['failures'] = 0;

// Create a throwaway test mission to attach days to
$testMissionId = dbInsert(
    "INSERT INTO missions (title, mission_type_id, location, start_datetime, end_datetime, status, created_by, created_at, updated_at)
     VALUES ('TEST multiday plan verify', 1, 'Test', NOW(), NOW() + INTERVAL 2 DAY, 'DRAFT', 1, NOW(), NOW())"
);

$rawJson = json_encode([
    ['day_number' => 1, 'day_date' => '2026-09-10', 'title' => 'Day one', 'route_points' => [['lat' => 35.3, 'lng' => 25.1]]],
    ['day_number' => 2, 'day_date' => '2026-09-11', 'title' => 'Day two', 'route_points' => []],
]);
$days = normalizeMissionDaysJson($rawJson);
assertTrue(count($days) === 2, 'normalizeMissionDaysJson parses 2 valid days');
assertTrue($days[0]['day_number'] === 1 && $days[1]['day_number'] === 2, 'days are sorted by day_number');

saveMissionDaysForMission($testMissionId, $days);
$saved = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number", [$testMissionId]);
assertTrue(count($saved) === 2, 'saveMissionDaysForMission inserted 2 rows');
assertTrue($saved[0]['title'] === 'Day one', 'day 1 title saved correctly');

// Shrinking to 0 days must delete existing rows
saveMissionDaysForMission($testMissionId, []);
$afterShrink = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = ?", [$testMissionId]);
assertTrue(count($afterShrink) === 0, 'saveMissionDaysForMission with empty array deletes existing rows');

// Cleanup
dbExecute("DELETE FROM missions WHERE id = ?", [$testMissionId]);

echo $GLOBALS['failures'] === 0 ? "\nALL PASS\n" : "\n{$GLOBALS['failures']} FAILURE(S)\n";
exit($GLOBALS['failures'] === 0 ? 0 : 1);
```

Run: `C:\xampp\php\php.exe verify-mission-days-save.php`
Expected: all 5 assertions PASS, ending with `ALL PASS`.

Delete the throwaway script afterward: `rm verify-mission-days-save.php` (do not commit it).

- [ ] **Step 7: Lint, sync, commit**

```bash
C:\xampp\php\php.exe -l mission-form.php
```

Sync (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

```bash
git add mission-form.php
git commit -m "Add backend load/save logic for multi-day mission itineraries"
```

---

### Task 3: Frontend markup — day tabs, overnight/title fields, hidden field, CSS

**Files:**
- Modify: `mission-form.php` (style block at lines 381-387; the route editor card block, currently lines 486-500; add `id="missionForm"` to the `<form>` tag at line 416)

**Interfaces:**
- Consumes: `$missionDaysInitial` and `$isMultiDayInitial` PHP variables (produced by Task 2).
- Produces: DOM elements Task 4 wires up: `#missionForm` (the form), `#multiDayTabsWrap` (container, hidden by default), `#missionDayTabs` (empty `<ul>`, populated by JS), `#dayTitleInput`, `#dayOvernightInput`, `#mission_days_json` (hidden input for submission).

- [ ] **Step 1: Add `id="missionForm"` to the form tag**

Find (currently line 416):
```php
<form method="post" action="">
```

Replace with:
```php
<form method="post" action="" id="missionForm">
```

- [ ] **Step 2: Add CSS for the day tabs area**

Find the `<style>` block (currently lines 381-387):
```php
<style>
#routeEditorMap { height: 320px; border-radius: 8px; border: 1px solid #dee2e6; overflow: hidden; }
.route-point-count { min-width: 74px; text-align: center; }
.route-point-row { border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; background: #fff; }
.route-point-row + .route-point-row { margin-top: 8px; }
.route-point-index { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
</style>
```

Replace with (adds one rule):
```php
<style>
#routeEditorMap { height: 320px; border-radius: 8px; border: 1px solid #dee2e6; overflow: hidden; }
.route-point-count { min-width: 74px; text-align: center; }
.route-point-row { border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; background: #fff; }
.route-point-row + .route-point-row { margin-top: 8px; }
.route-point-index { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
#missionDayTabs .nav-link { cursor: pointer; }
</style>
```

- [ ] **Step 3: Add the multi-day tabs block and hidden field around the existing route editor**

Find this block (currently lines 486-500):
```php
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-signpost-split me-1 text-primary"></i>Διαδρομή Δράσης</label>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="routeUndoBtn">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Αναίρεση
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="routeClearBtn">
                                <i class="bi bi-trash me-1"></i>Καθαρισμός
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#routeFullscreenModal">
                                <i class="bi bi-arrows-fullscreen me-1"></i>Μεγάλος χάρτης
                            </button>
                            <span class="badge bg-light text-dark border route-point-count" id="routePointCount">0 σημεία</span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-2 small" id="routeMetricsSummary" style="display:none;"></div>
                        <div id="routeEditorMap"></div>
                        <div class="mt-3" id="routePointList"></div>
                    </div>
```

Replace with:
```php
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-signpost-split me-1 text-primary"></i>Διαδρομή Δράσης</label>
                        <div id="multiDayTabsWrap" style="<?= $isMultiDayInitial ? '' : 'display:none;' ?>" class="mb-3 p-2 border rounded bg-light">
                            <ul class="nav nav-pills mb-2" id="missionDayTabs"></ul>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">Τίτλος ημέρας (προαιρετικό)</label>
                                    <input type="text" class="form-control form-control-sm" id="dayTitleInput" maxlength="160" placeholder="π.χ. Ηράκλειο &rarr; Ρέθυμνο">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">Διανυκτέρευση (προαιρετικό)</label>
                                    <input type="text" class="form-control form-control-sm" id="dayOvernightInput" maxlength="500" placeholder="π.χ. Ξενοδοχείο Χ, Ρέθυμνο">
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="routeUndoBtn">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Αναίρεση
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="routeClearBtn">
                                <i class="bi bi-trash me-1"></i>Καθαρισμός
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#routeFullscreenModal">
                                <i class="bi bi-arrows-fullscreen me-1"></i>Μεγάλος χάρτης
                            </button>
                            <span class="badge bg-light text-dark border route-point-count" id="routePointCount">0 σημεία</span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-2 small" id="routeMetricsSummary" style="display:none;"></div>
                        <div id="routeEditorMap"></div>
                        <div class="mt-3" id="routePointList"></div>
                        <input type="hidden" id="mission_days_json" name="mission_days_json" value="">
                    </div>
```

- [ ] **Step 4: Lint, sync, manual verification**

```bash
C:\xampp\php\php.exe -l mission-form.php
```

Sync (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

In the browser: open the mission form for a new mission — confirm `#multiDayTabsWrap` is present but hidden (`display:none`) and everything else (map, buttons, list) looks and behaves exactly as before this task (no JS wiring yet, so the tabs area staying empty/hidden is expected at this step).

- [ ] **Step 5: Commit**

```bash
git add mission-form.php
git commit -m "Add multi-day itinerary markup (day tabs, overnight field, CSS)"
```

---

### Task 4: Frontend JS — day-switch state machine

**Files:**
- Modify: `mission-form.php` (JS block inside the route-editor IIFE, currently starting at line 731; add new variables near line 735, new functions after `clearRoutedData()` around line 759, a call inside `initRouteEditor()` near its end (~line 1183), a submit listener and `window.updateMultiDayUI` exposure inside the `DOMContentLoaded` handler around lines 1292-1315, and one call from the date-picker's confirm handler around line 1564)

**Interfaces:**
- Consumes: `$missionDaysInitial`/`$isMultiDayInitial` PHP variables (Task 2) rendered as JSON; DOM ids from Task 3 (`#multiDayTabsWrap`, `#missionDayTabs`, `#dayTitleInput`, `#dayOvernightInput`, `#mission_days_json`, `#missionForm`); existing JS globals/functions from this session's fullscreen-editor work: `routePoints`, `routedGeometry`, `routedDistanceMeters`, `routedDurationSeconds`, `routedProvider`, `lastRoutedSignature`, `routeSignature()`, `renderRoute(fitBounds, skipListRender)`, `mapInstances`.
- Produces: nothing consumed by later tasks (Task 5 is verification/bookkeeping only).

- [ ] **Step 1: Add the per-day state variables**

Find (currently line 735):
```javascript
    var routePoints = <?= json_encode($routePointsInitial, JSON_UNESCAPED_UNICODE) ?>;
```

Add immediately after it:
```javascript
    var missionDaysStore = <?= json_encode($missionDaysInitial, JSON_UNESCAPED_UNICODE) ?>;
    var activeDayNumber = null; // null = single-day mode (no mission_days involved)
```

- [ ] **Step 2: Add the day-span/day-switch functions**

Find the end of `clearRoutedData()` (currently lines 750-759):
```javascript
    function clearRoutedData() {
        routedGeometry = [];
        routedDistanceMeters = 0;
        routedDurationSeconds = 0;
        routedProvider = '';
        document.getElementById('route_geometry').value = '';
        document.getElementById('route_distance_meters').value = '';
        document.getElementById('route_duration_seconds').value = '';
        document.getElementById('route_provider').value = '';
    }
```

Add immediately after it:
```javascript

    function parseDmyDateOnly(fieldId) {
        var val = document.getElementById(fieldId).value; // dd/mm/yyyy hh:ii
        var m = val.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
        if (!m) return null;
        return new Date(parseInt(m[3], 10), parseInt(m[2], 10) - 1, parseInt(m[1], 10));
    }

    function computeDaySpan() {
        var start = parseDmyDateOnly('start_datetime');
        var end = parseDmyDateOnly('end_datetime');
        if (!start || !end || end < start) return [];

        var pad = function(n) { return String(n).padStart(2, '0'); };
        var days = [];
        var cursor = new Date(start);
        var dayNumber = 1;
        while (cursor <= end && dayNumber <= 60) {
            days.push({
                day_number: dayNumber,
                day_date: cursor.getFullYear() + '-' + pad(cursor.getMonth() + 1) + '-' + pad(cursor.getDate())
            });
            cursor.setDate(cursor.getDate() + 1);
            dayNumber++;
        }
        return days;
    }

    function emptyDayEntry(dayNumber, dayDate) {
        return {
            day_number: dayNumber,
            day_date: dayDate,
            title: '',
            overnight_notes: '',
            route_points: [],
            route_geometry: [],
            route_distance_meters: 0,
            route_duration_seconds: 0,
            route_provider: ''
        };
    }

    function findDayEntry(dayNumber) {
        for (var i = 0; i < missionDaysStore.length; i++) {
            if (missionDaysStore[i].day_number === dayNumber) return missionDaysStore[i];
        }
        return null;
    }

    function flushActiveDayIntoStore() {
        if (activeDayNumber === null) return;
        var entry = findDayEntry(activeDayNumber);
        if (!entry) return;
        entry.title = document.getElementById('dayTitleInput').value;
        entry.overnight_notes = document.getElementById('dayOvernightInput').value;
        entry.route_points = routePoints;
        entry.route_geometry = routedGeometry;
        entry.route_distance_meters = routedDistanceMeters;
        entry.route_duration_seconds = routedDurationSeconds;
        entry.route_provider = routedProvider;
    }

    function switchToDay(dayNumber) {
        flushActiveDayIntoStore();
        activeDayNumber = dayNumber;
        var entry = findDayEntry(dayNumber);
        if (!entry) return;

        routePoints = entry.route_points || [];
        routedGeometry = entry.route_geometry || [];
        routedDistanceMeters = entry.route_distance_meters || 0;
        routedDurationSeconds = entry.route_duration_seconds || 0;
        routedProvider = entry.route_provider || '';
        lastRoutedSignature = routedProvider === 'google' ? routeSignature() : null;

        document.getElementById('route_geometry').value = routedGeometry.length ? JSON.stringify(routedGeometry) : '';
        document.getElementById('route_distance_meters').value = routedDistanceMeters || '';
        document.getElementById('route_duration_seconds').value = routedDurationSeconds || '';
        document.getElementById('route_provider').value = routedProvider || '';
        document.getElementById('dayTitleInput').value = entry.title || '';
        document.getElementById('dayOvernightInput').value = entry.overnight_notes || '';

        renderDayTabs();
        renderRoute(true);
    }

    function renderDayTabs() {
        var tabs = document.getElementById('missionDayTabs');
        tabs.innerHTML = '';
        missionDaysStore.forEach(function(entry) {
            var li = document.createElement('li');
            li.className = 'nav-item';
            var a = document.createElement('a');
            a.href = '#';
            a.className = 'nav-link py-1 px-2' + (entry.day_number === activeDayNumber ? ' active' : '');
            var dateParts = entry.day_date.split('-');
            a.textContent = 'Μέρα ' + entry.day_number + ' · ' + dateParts[2] + '/' + dateParts[1];
            a.addEventListener('click', function(e) {
                e.preventDefault();
                if (entry.day_number !== activeDayNumber) switchToDay(entry.day_number);
            });
            li.appendChild(a);
            tabs.appendChild(li);
        });
    }

    function updateMultiDayUI() {
        var span = computeDaySpan();
        var wrap = document.getElementById('multiDayTabsWrap');

        if (span.length < 2) {
            activeDayNumber = null;
            missionDaysStore = [];
            wrap.style.display = 'none';
            return;
        }

        wrap.style.display = '';
        missionDaysStore = span.map(function(day) {
            var existing = findDayEntry(day.day_number);
            if (existing) {
                existing.day_date = day.day_date;
                return existing;
            }
            return emptyDayEntry(day.day_number, day.day_date);
        });

        if (activeDayNumber === null || !findDayEntry(activeDayNumber)) {
            switchToDay(missionDaysStore[0].day_number);
        } else {
            renderDayTabs();
        }
    }

    window.updateMultiDayUI = updateMultiDayUI;
```

Note: `findDayEntry` is used both inside `updateMultiDayUI` (before `missionDaysStore` is reassigned, to look up entries from the *old* store) and after (to check `activeDayNumber` against the *new* store) — this works because `findDayEntry` always reads the current value of `missionDaysStore` at call time.

- [ ] **Step 3: Call `updateMultiDayUI()` at the end of `initRouteEditor()`**

Find the end of `initRouteEditor()` (currently ending around line 1183):
```javascript
        renderRoute(routePoints.length > 0);
        setTimeout(function() { routeMap.invalidateSize(); }, 150);
    }
```

Replace with:
```javascript
        renderRoute(routePoints.length > 0);
        setTimeout(function() { routeMap.invalidateSize(); }, 150);
        updateMultiDayUI();
    }
```

- [ ] **Step 4: Wire the submit-time flush**

Find the `DOMContentLoaded` handler (currently lines 1292-1314):
```javascript
    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('maps_link');
        var btn   = document.getElementById('parseMapsBtn');
        initRouteEditor();
        initFullscreenRouteEditor();

        // Auto-parse on paste
        input.addEventListener('paste', function(e) {
            setTimeout(function() { handleMapsInput(input.value); }, 50);
        });

        // Parse on button click
        btn.addEventListener('click', function() {
            handleMapsInput(input.value);
        });

        // If editing & lat/lng already set, show confirmation
        <?php if (!empty($mission['latitude']) && !empty($mission['longitude'])): ?>
        document.getElementById('mapsParseStatus').innerHTML =
            '<small class="text-success"><i class="bi bi-check-circle me-1"></i>' +
            'Η τοποθεσία έχει εντοπιστεί από το Google Maps.</small>';
        <?php endif; ?>
    });
```

Add a new statement right after `initFullscreenRouteEditor();`:
```javascript
    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('maps_link');
        var btn   = document.getElementById('parseMapsBtn');
        initRouteEditor();
        initFullscreenRouteEditor();

        document.getElementById('missionForm').addEventListener('submit', function() {
            flushActiveDayIntoStore();
            var jsonField = document.getElementById('mission_days_json');
            if (missionDaysStore.length >= 2) {
                jsonField.value = JSON.stringify(missionDaysStore);
                document.getElementById('route_points').value = '';
                document.getElementById('route_geometry').value = '';
                document.getElementById('route_distance_meters').value = '';
                document.getElementById('route_duration_seconds').value = '';
                document.getElementById('route_provider').value = '';
            } else {
                jsonField.value = '';
            }
        });

        // Auto-parse on paste
        input.addEventListener('paste', function(e) {
            setTimeout(function() { handleMapsInput(input.value); }, 50);
        });

        // Parse on button click
        btn.addEventListener('click', function() {
            handleMapsInput(input.value);
        });

        // If editing & lat/lng already set, show confirmation
        <?php if (!empty($mission['latitude']) && !empty($mission['longitude'])): ?>
        document.getElementById('mapsParseStatus').innerHTML =
            '<small class="text-success"><i class="bi bi-check-circle me-1"></i>' +
            'Η τοποθεσία έχει εντοπιστεί από το Google Maps.</small>';
        <?php endif; ?>
    });
```

- [ ] **Step 5: Recompute the day span whenever the date picker sets start/end datetime**

Find the date-picker confirm handler (currently lines 1534-1565), specifically this section near its end:
```javascript
    const isoVal = datePart + 'T' + timePart;
    const dmy = isoToDmy(isoVal);
    document.getElementById(window._dpField + '_datetime').value = dmy;
    document.getElementById(window._dpField + '_datetime_display').value = dmy;
    // Auto-fill end datetime if duration is set and we're setting start
    if (window._dpField === 'start') {
        const hours = parseFloat(document.getElementById('durationCustom').value);
        if (hours > 0) {
            const startMs = new Date(isoVal).getTime();
            const endDate = new Date(startMs + hours * 3600 * 1000);
            const pad = n => String(n).padStart(2, '0');
            const endIso = endDate.getFullYear() + '-' + pad(endDate.getMonth()+1) + '-' + pad(endDate.getDate()) + 'T' + pad(endDate.getHours()) + ':' + pad(endDate.getMinutes());
            const endDmy = isoToDmy(endIso);
            document.getElementById('end_datetime').value = endDmy;
            document.getElementById('end_datetime_display').value = endDmy;
        }
    }
    window._dpModal.hide();
});
```

Replace the final two lines (`window._dpModal.hide();\n});`) with:
```javascript
    if (window.updateMultiDayUI) window.updateMultiDayUI();
    window._dpModal.hide();
});
```

So the full block becomes:
```javascript
    const isoVal = datePart + 'T' + timePart;
    const dmy = isoToDmy(isoVal);
    document.getElementById(window._dpField + '_datetime').value = dmy;
    document.getElementById(window._dpField + '_datetime_display').value = dmy;
    // Auto-fill end datetime if duration is set and we're setting start
    if (window._dpField === 'start') {
        const hours = parseFloat(document.getElementById('durationCustom').value);
        if (hours > 0) {
            const startMs = new Date(isoVal).getTime();
            const endDate = new Date(startMs + hours * 3600 * 1000);
            const pad = n => String(n).padStart(2, '0');
            const endIso = endDate.getFullYear() + '-' + pad(endDate.getMonth()+1) + '-' + pad(endDate.getDate()) + 'T' + pad(endDate.getHours()) + ':' + pad(endDate.getMinutes());
            const endDmy = isoToDmy(endIso);
            document.getElementById('end_datetime').value = endDmy;
            document.getElementById('end_datetime_display').value = endDmy;
        }
    }
    if (window.updateMultiDayUI) window.updateMultiDayUI();
    window._dpModal.hide();
});
```

(The `window.updateMultiDayUI` existence check keeps this safe even if this script ever runs before the route-editor IIFE for any reason — it won't throw, just silently skip.)

- [ ] **Step 6: Lint, sync, manual end-to-end verification**

```bash
C:\xampp\php\php.exe -l mission-form.php
```

Sync (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

In the browser, verify the full feature end to end:
1. Create a new mission with start/end on the SAME calendar day — confirm the day-tabs area never appears, and the form behaves exactly as before this plan (draw points, save, reload the edit page, points are still there).
2. Create a new mission with start/end spanning 3 calendar days — confirm 3 day tabs appear ("Μέρα 1 · dd/mm", "Μέρα 2 · dd/mm", "Μέρα 3 · dd/mm").
3. On Day 1, draw 2-3 points and type a title + overnight note. Switch to Day 2 — confirm the map clears to Day 2's (empty) state and the title/overnight fields clear. Draw different points on Day 2. Switch back to Day 1 — confirm Day 1's points/title/overnight reappear exactly as left.
4. Save the mission. Re-open it for editing — confirm all 3 day tabs reload with their correct saved points/titles/overnight notes.
5. Edit the mission's end date to shrink the range back to a single day, save, re-open — confirm the day-tabs area is gone and the mission behaves as an ordinary single-day mission (its `mission_days` rows should be gone — this can be spot-checked with a one-off `SELECT COUNT(*) FROM mission_days WHERE mission_id = ?` via a throwaway script if desired, then deleted).
6. Confirm the ordinary single-day mission creation/edit flow (from Task 3's step 4) still works identically.
7. Check the browser console for errors during all of the above — expect none beyond the pre-existing, unrelated Summernote `$ is not defined` warning already confirmed present before this session's work.

- [ ] **Step 7: Commit**

```bash
git add mission-form.php
git commit -m "Wire multi-day itinerary day-switching and submit-time serialization"
```

---

### Task 5: Manual sign-off, version bump, and release notes

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

If the current `APP_VERSION` (in `config.php`) already has a matching `vX.Y.Z` tag, bump to the next patch version below. If not, use the current `APP_VERSION` as-is.

- [ ] **Step 2: Bump `APP_VERSION` if needed**

In `config.php`, update `define('APP_VERSION', '...')` to the next patch version, following the exact pattern already in that file.

- [ ] **Step 3: Add a README highlight entry**

In `README.md`, under `## Current Release Highlights`, add a new `### vX.Y.Z` section above the most recent one:

```markdown
### vX.Y.Z

- Multi-day mission itineraries (phase 1 of 3): admins can now draw a distinct route and add overnight/accommodation notes per calendar day on multi-day rides, in the mission creation/edit form. Single-day missions are unaffected. Displaying the itinerary elsewhere (mission page, live Ride Mode) lands in a follow-up release.
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
git commit -m "Bump version for multi-day mission itinerary (phase 1) release"
```

(Tagging and creating the GitHub release is a separate, explicit step the user triggers — not part of this implementation plan.)
