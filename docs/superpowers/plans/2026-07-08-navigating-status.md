# "Στην Πλοήγηση" Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the responsible person on the Επιχειρησιακό dashboard (and other riders in Ride Mode) tell "this rider just opened Google Maps for turn-by-turn directions" apart from "this rider's GPS signal genuinely went stale" — without adding any friction for the rider tapping Navigate, and without leaving a permanent record purely because someone used Google Maps.

**Architecture:** A new `navigating_since` timestamp on `participation_requests` is set via a fire-and-forget POST the instant a rider taps "Πλοήγηση" in Ride Mode. Both places that already compute GPS staleness (`api-ride-data.php`'s live feed, and `includes/ride-functions.php`'s `getRideReadinessSummary()`) get a new "is this person navigating right now" check that runs *before* their existing staleness classification, so a navigating rider shows a distinct, reassuring badge instead of "Stale" — and, critically, skips the app's existing automatic stale-event auto-logging while navigating. A passive, non-blocking toast (not a push notification) reminds the rider to keep the screen open, shown only the moment they return to a backgrounded tab on their own.

**Tech Stack:** PHP 8.2, vanilla JS, Leaflet.js (already a dependency), Bootstrap 5 (CSS only on `ride-mode.php` — no Bootstrap JS bundle is loaded there, see Task 6).

## Global Constraints

- No automated test framework — verify via `php -l` + manual/CLI-based checks against the real DB, per project convention.
- The existing 120-second staleness threshold (`api-ride-data.php:162`, `includes/ride-functions.php:769`) is the canonical value reused everywhere in this feature — do not introduce a second magic number.
- No push notification of any kind — this is explicitly rejected in the spec (distraction risk for someone riding). Only a passive toast shown when the rider's own tab becomes visible again.
- Nothing about this feature is written to `ride_events` or any other permanent table — it must leave zero trace once the window elapses.
- The "Πλοήγηση" signal-firing only applies to `ride-mode.php`'s button (the live-ride screen) — not the pre-ride planning nav links on `mission-view.php`.
- Working directly on `main`, no feature branch (established project convention this session).

---

### Task 1: `navigating_since` migration

**Files:**
- Modify: `includes/migrations.php` (add migration entry after version 80, before the closing `];` of the `$migrations` array — version 80's block currently closes at line 4695, followed by a blank line then `];` at line 4697)
- Modify: `config.php:15` (`DB_SCHEMA_VERSION`)

**Interfaces:**
- Produces: `participation_requests.navigating_since` column (`TIMESTAMP NULL`) — Tasks 2, 3, and 4 read/write it.

- [ ] **Step 1: Add the migration entry**

In `includes/migrations.php`, insert this new array entry immediately after the version 80 entry's closing `],` (line 4695) and before the blank line + closing `];`:

```php
        [
            'version'     => 81,
            'description' => 'Add participation_requests.navigating_since for live "navigating to Google Maps" rider status',
            'up' => function () {
                $colExists = dbFetchValue(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participation_requests' AND COLUMN_NAME = 'navigating_since'"
                );
                if (!$colExists) {
                    dbExecute("ALTER TABLE participation_requests ADD COLUMN navigating_since TIMESTAMP NULL AFTER field_status_updated_at");
                }
            },
        ],
```

- [ ] **Step 2: Bump `DB_SCHEMA_VERSION`**

In `config.php:15`, change:
```php
define('DB_SCHEMA_VERSION', 80);
```
to:
```php
define('DB_SCHEMA_VERSION', 81);
```

- [ ] **Step 3: Lint**

Run: `C:\xampp\php\php.exe -l includes/migrations.php && C:\xampp\php\php.exe -l config.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Trigger the migration and verify the column exists**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/easyride/dashboard.php
```
Expected: `200` (this hits the live app, which runs pending migrations on every request — if unreachable, start XAMPP first: `cmd //c start "" "C:\xampp\mysql_start.bat"` then `cmd //c start "" "C:\xampp\apache_start.bat"`).

Then verify:
```bash
C:/xampp/mysql/bin/mysql.exe -u root -D easyride -e "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='easyride' AND TABLE_NAME='participation_requests' AND COLUMN_NAME='navigating_since';"
```
Expected: one row, `navigating_since | timestamp | YES`.

- [ ] **Step 5: Commit**

```bash
git add includes/migrations.php config.php
git commit -m "Add participation_requests.navigating_since column for live navigating status"
```

---

### Task 2: `api-ride-navigate.php` endpoint

**Files:**
- Create: `api-ride-navigate.php`

**Interfaces:**
- Consumes: `getRideParticipation(int $missionId, int $userId, ?int $shiftId = null): ?array` (existing, `includes/ride-functions.php:86`), `getRideMission()`, `canAccessRideMission()` (existing, used identically in `api-ride-ping.php`).
- Produces: a POST endpoint at `api-ride-navigate.php` accepting `csrf_token`, `mission_id`, `shift_id` — Task 6 calls it via `fetch()`.

- [ ] **Step 1: Create the endpoint**

```php
<?php
/**
 * EasyRide - Ride Mode "navigating to Google Maps" signal endpoint
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ride-functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$respond = function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isPost()) {
    $respond(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $respond(['ok' => false, 'error' => 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.'], 403);
}

$user = getCurrentUser();
$userId = (int)$user['id'];
$missionId = (int)post('mission_id');
$requestedShiftId = (int)post('shift_id', 0);

if ($missionId <= 0) {
    $respond(['ok' => false, 'error' => 'Δεν βρέθηκε δράση.'], 422);
}

$mission = getRideMission($missionId);
if (!$mission || !canAccessRideMission($mission, $user)) {
    $respond(['ok' => false, 'error' => 'Δεν έχετε πρόσβαση στο Ride Mode αυτής της δράσης.'], 403);
}

$participation = getRideParticipation($missionId, $userId, $requestedShiftId > 0 ? $requestedShiftId : null);
if (!$participation) {
    $respond(['ok' => false, 'error' => 'Δεν βρέθηκε εγκεκριμένη συμμετοχή.'], 403);
}

dbExecute(
    "UPDATE participation_requests SET navigating_since = NOW() WHERE id = ?",
    [(int)$participation['participation_id']]
);

$respond(['ok' => true]);
```

- [ ] **Step 2: Lint**

Run: `C:\xampp\php\php.exe -l api-ride-navigate.php`
Expected: `No syntax errors detected in api-ride-navigate.php`

- [ ] **Step 3: Verify against a real approved participation via CLI**

```bash
cat > /tmp/test_navigate_endpoint.php << 'EOF'
<?php
require_once 'C:/Users/user/Desktop/easyride/bootstrap.php';
require_once 'C:/Users/user/Desktop/easyride/includes/ride-functions.php';
// Find any approved participation row to test against
$pr = dbFetchOne("SELECT id, shift_id, member_id FROM participation_requests WHERE status = 'APPROVED' LIMIT 1");
if (!$pr) {
    echo "No approved participation found in this DB to test against — skip live-row check.\n";
    exit;
}
echo "Testing against participation_requests.id={$pr['id']} (member {$pr['member_id']}, shift {$pr['shift_id']})\n";
dbExecute("UPDATE participation_requests SET navigating_since = NOW() WHERE id = ?", [$pr['id']]);
$row = dbFetchOne("SELECT navigating_since FROM participation_requests WHERE id = ?", [$pr['id']]);
echo "navigating_since after direct UPDATE: {$row['navigating_since']}\n";
// Reset so this doesn't leave test state behind
dbExecute("UPDATE participation_requests SET navigating_since = NULL WHERE id = ?", [$pr['id']]);
EOF
C:\xampp\php\php.exe /tmp/test_navigate_endpoint.php
rm /tmp/test_navigate_endpoint.php
```

Expected: prints a non-null `navigating_since` timestamp, confirming the column write path works (this validates the DB write mechanics the endpoint itself does; the endpoint's own auth/CSRF path is exercised end-to-end in Task 6's manual browser check, since it requires a real logged-in session).

- [ ] **Step 4: Commit**

```bash
git add api-ride-navigate.php
git commit -m "Add api-ride-navigate.php endpoint to signal a rider opened Google Maps"
```

---

### Task 3: Classify "navigating" in `api-ride-data.php`

**Files:**
- Modify: `api-ride-data.php:39-45` (`$readiness` init)
- Modify: `api-ride-data.php:59-68` (participant SELECT)
- Modify: `api-ride-data.php:117-259` (per-participant classification loop)

**Interfaces:**
- Consumes: `participation_requests.navigating_since` (Task 1).
- Produces: `$participant['is_navigating']` (bool) in the JSON `participants` array, and `$readiness['navigating']` (int) in the JSON `readiness` object — Task 6 (`ride-mode.php`) reads both by these exact names.

- [ ] **Step 1: Add `navigating` to the readiness counters**

Replace (lines 39-45):
```php
$readiness = [
    'approved' => 0,
    'tracking' => 0,
    'stale' => 0,
    'not_started' => 0,
    'with_status' => 0,
];
```
with:
```php
$readiness = [
    'approved' => 0,
    'tracking' => 0,
    'navigating' => 0,
    'stale' => 0,
    'not_started' => 0,
    'with_status' => 0,
];
```

- [ ] **Step 2: Select `navigating_since` for each participant**

Replace (lines 59-68):
```php
    $participantRows = dbFetchAll(
        "SELECT pr.id as participation_id, pr.shift_id, pr.field_status, pr.field_status_updated_at,
                u.id as user_id, u.name, u.phone
         FROM participation_requests pr
         JOIN users u ON u.id = pr.member_id
         WHERE pr.shift_id IN ($placeholders)
           AND pr.status = ?
         ORDER BY u.name ASC",
        array_merge($shiftIds, [PARTICIPATION_APPROVED])
    );
```
with:
```php
    $participantRows = dbFetchAll(
        "SELECT pr.id as participation_id, pr.shift_id, pr.field_status, pr.field_status_updated_at, pr.navigating_since,
                u.id as user_id, u.name, u.phone
         FROM participation_requests pr
         JOIN users u ON u.id = pr.member_id
         WHERE pr.shift_id IN ($placeholders)
           AND pr.status = ?
         ORDER BY u.name ASC",
        array_merge($shiftIds, [PARTICIPATION_APPROVED])
    );
```

- [ ] **Step 3: Compute `is_navigating` and reorder the classification branches**

Replace (the `$ageSeconds`/`$status` setup, lines 120-122):
```php
        $lastPingTs = $ping ? strtotime($ping['created_at']) : null;
        $ageSeconds = $lastPingTs ? max(0, time() - $lastPingTs) : null;
        $status = $row['field_status'] ?: ($ping['status'] ?? null);
```
with:
```php
        $lastPingTs = $ping ? strtotime($ping['created_at']) : null;
        $ageSeconds = $lastPingTs ? max(0, time() - $lastPingTs) : null;
        $status = $row['field_status'] ?: ($ping['status'] ?? null);
        $navigatingSinceTs = !empty($row['navigating_since']) ? strtotime($row['navigating_since']) : null;
        $isNavigating = $navigatingSinceTs !== null
            && $navigatingSinceTs > ($lastPingTs ?? 0)
            && (time() - $navigatingSinceTs) <= 120;
```

Replace (the `$participant` array's `is_stale` line, line 162):
```php
            'is_stale' => $ageSeconds !== null && $ageSeconds > 120,
```
with:
```php
            'is_stale' => $ageSeconds !== null && $ageSeconds > 120,
            'is_navigating' => $isNavigating,
```

Replace (the readiness-bucket branch, lines 176-182):
```php
        if ($ping && !$participant['is_stale']) {
            $readiness['tracking']++;
        } elseif ($participant['is_stale']) {
            $readiness['stale']++;
        } else {
            $readiness['not_started']++;
        }
```
with:
```php
        if ($isNavigating) {
            $readiness['navigating']++;
        } elseif ($ping && !$participant['is_stale']) {
            $readiness['tracking']++;
        } elseif ($participant['is_stale']) {
            $readiness['stale']++;
        } else {
            $readiness['not_started']++;
        }
```

Replace (the alert-generation `elseif ($participant['is_stale'])` branch, lines 239-258) — add a guard so a navigating participant never falls into the stale alert/event-logging branch:
```php
        } elseif ($participant['is_stale']) {
            $alert = [
                'type' => 'stale',
                'severity' => 'secondary',
                'name' => $row['name'],
                'user_id' => $participant['user_id'],
                'message' => 'Χωρίς πρόσφατο στίγμα: ' . $row['name'] . ' (' . round($ageSeconds / 60) . ' λεπτά)',
                'lat' => $participant['lat'],
                'lng' => $participant['lng'],
            ];
            $alerts[] = $alert;
            $eventCandidates[] = [
                'event_type' => 'stale',
                'severity' => 'secondary',
                'title' => 'Χωρίς πρόσφατο στίγμα',
                'message' => $alert['message'],
                'participant' => $participant,
                'metadata' => ['age_seconds' => $ageSeconds],
            ];
        }
```
with:
```php
        } elseif ($participant['is_stale'] && !$isNavigating) {
            $alert = [
                'type' => 'stale',
                'severity' => 'secondary',
                'name' => $row['name'],
                'user_id' => $participant['user_id'],
                'message' => 'Χωρίς πρόσφατο στίγμα: ' . $row['name'] . ' (' . round($ageSeconds / 60) . ' λεπτά)',
                'lat' => $participant['lat'],
                'lng' => $participant['lng'],
            ];
            $alerts[] = $alert;
            $eventCandidates[] = [
                'event_type' => 'stale',
                'severity' => 'secondary',
                'title' => 'Χωρίς πρόσφατο στίγμα',
                'message' => $alert['message'],
                'participant' => $participant,
                'metadata' => ['age_seconds' => $ageSeconds],
            ];
        }
```

(This `&& !$isNavigating` guard is defensive — in practice this `elseif` branch is only reached when `$participant['is_stale']` is `true`, and `$isNavigating` can only be `true` when `$ageSeconds > 120` doesn't yet apply in the same way; the guard makes the intent explicit and safe against future edits to the branch ordering above, matching the spec's requirement that navigating participants never generate a `stale` alert or `ride_events` row.)

- [ ] **Step 4: Lint**

Run: `C:\xampp\php\php.exe -l api-ride-data.php`
Expected: `No syntax errors detected in api-ride-data.php`

- [ ] **Step 5: Verify the classification logic with a CLI script against a real mission**

```bash
cat > /tmp/test_navigating_classification.php << 'EOF'
<?php
require_once 'C:/Users/user/Desktop/easyride/bootstrap.php';
require_once 'C:/Users/user/Desktop/easyride/includes/ride-functions.php';

$pr = dbFetchOne("SELECT id, member_id, shift_id FROM participation_requests WHERE status = 'APPROVED' LIMIT 1");
if (!$pr) {
    echo "No approved participation found — skip.\n";
    exit;
}

// Simulate: no ping yet, then set navigating_since to just now.
dbExecute("DELETE FROM member_pings WHERE user_id = ? AND shift_id = ?", [$pr['member_id'], $pr['shift_id']]);
dbExecute("UPDATE participation_requests SET navigating_since = NOW() WHERE id = ?", [$pr['id']]);

$row = dbFetchOne("SELECT navigating_since FROM participation_requests WHERE id = ?", [$pr['id']]);
$navigatingSinceTs = strtotime($row['navigating_since']);
$lastPingTs = null; // no ping recorded
$isNavigating = $navigatingSinceTs !== null
    && $navigatingSinceTs > ($lastPingTs ?? 0)
    && (time() - $navigatingSinceTs) <= 120;
echo "isNavigating (no prior ping, just set): " . ($isNavigating ? 'true' : 'false') . " (expect true)\n";

// Simulate: navigating_since set 200 seconds ago (outside the 120s window)
dbExecute("UPDATE participation_requests SET navigating_since = DATE_SUB(NOW(), INTERVAL 200 SECOND) WHERE id = ?", [$pr['id']]);
$row = dbFetchOne("SELECT navigating_since FROM participation_requests WHERE id = ?", [$pr['id']]);
$navigatingSinceTs = strtotime($row['navigating_since']);
$isNavigating = $navigatingSinceTs !== null
    && $navigatingSinceTs > ($lastPingTs ?? 0)
    && (time() - $navigatingSinceTs) <= 120;
echo "isNavigating (200s ago): " . ($isNavigating ? 'true' : 'false') . " (expect false)\n";

// Cleanup
dbExecute("UPDATE participation_requests SET navigating_since = NULL WHERE id = ?", [$pr['id']]);
EOF
C:\xampp\php\php.exe /tmp/test_navigating_classification.php
rm /tmp/test_navigating_classification.php
```

Expected: `isNavigating (no prior ping, just set): true`, `isNavigating (200s ago): false`.

- [ ] **Step 6: Commit**

```bash
git add api-ride-data.php
git commit -m "Classify navigating riders separately from stale in the live ride-data feed"
```

---

### Task 4: `getRideReadinessSummary()` + Ride Readiness card on `mission-view.php`

**Files:**
- Modify: `includes/ride-functions.php:724-784` (`getRideReadinessSummary()`)
- Modify: `mission-view.php:1782-1809` (Ride Readiness card)

**Interfaces:**
- Consumes: `participation_requests.navigating_since` (Task 1).
- Produces: `$rideReadiness['navigating']` (int) — used only within this task's own template change.

- [ ] **Step 1: Add navigating-since lookup and classification to `getRideReadinessSummary()`**

Replace (`includes/ride-functions.php:724-784`, the full function):
```php
function getRideReadinessSummary(int $missionId): array {
    $shiftIds = getRideShiftIds($missionId);
    $summary = [
        'approved' => 0,
        'tracking' => 0,
        'stale' => 0,
        'not_started' => 0,
        'ready_percent' => 0,
    ];
    if (empty($shiftIds)) {
        return $summary;
    }

    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
    $participants = dbFetchAll(
        "SELECT DISTINCT pr.member_id
         FROM participation_requests pr
         WHERE pr.shift_id IN ($placeholders)
           AND pr.status = ?",
        array_merge($shiftIds, [PARTICIPATION_APPROVED])
    );
    $summary['approved'] = count($participants);
    if ($summary['approved'] === 0) {
        return $summary;
    }

    try {
        $pingRows = dbFetchAll(
            "SELECT vp.user_id, MAX(vp.created_at) as last_ping
             FROM member_pings vp
             WHERE vp.shift_id IN ($placeholders)
             GROUP BY vp.user_id",
            $shiftIds
        );
        $lastByUser = [];
        foreach ($pingRows as $row) {
            $lastByUser[(int)$row['user_id']] = $row['last_ping'];
        }
        foreach ($participants as $participant) {
            $uid = (int)$participant['member_id'];
            if (empty($lastByUser[$uid])) {
                $summary['not_started']++;
                continue;
            }
            $age = time() - strtotime($lastByUser[$uid]);
            if ($age > 120) {
                $summary['stale']++;
            } else {
                $summary['tracking']++;
            }
        }
    } catch (Exception $e) {
        $summary['not_started'] = $summary['approved'];
    }

    $summary['ready_percent'] = $summary['approved'] > 0
        ? (int)round(($summary['tracking'] / $summary['approved']) * 100)
        : 0;

    return $summary;
}
```
with:
```php
function getRideReadinessSummary(int $missionId): array {
    $shiftIds = getRideShiftIds($missionId);
    $summary = [
        'approved' => 0,
        'tracking' => 0,
        'navigating' => 0,
        'stale' => 0,
        'not_started' => 0,
        'ready_percent' => 0,
    ];
    if (empty($shiftIds)) {
        return $summary;
    }

    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
    $participants = dbFetchAll(
        "SELECT DISTINCT pr.member_id
         FROM participation_requests pr
         WHERE pr.shift_id IN ($placeholders)
           AND pr.status = ?",
        array_merge($shiftIds, [PARTICIPATION_APPROVED])
    );
    $summary['approved'] = count($participants);
    if ($summary['approved'] === 0) {
        return $summary;
    }

    try {
        $pingRows = dbFetchAll(
            "SELECT vp.user_id, MAX(vp.created_at) as last_ping
             FROM member_pings vp
             WHERE vp.shift_id IN ($placeholders)
             GROUP BY vp.user_id",
            $shiftIds
        );
        $lastByUser = [];
        foreach ($pingRows as $row) {
            $lastByUser[(int)$row['user_id']] = $row['last_ping'];
        }

        $navRows = dbFetchAll(
            "SELECT member_id, MAX(navigating_since) as navigating_since
             FROM participation_requests
             WHERE shift_id IN ($placeholders)
               AND status = ?
             GROUP BY member_id",
            array_merge($shiftIds, [PARTICIPATION_APPROVED])
        );
        $navigatingByUser = [];
        foreach ($navRows as $row) {
            if (!empty($row['navigating_since'])) {
                $navigatingByUser[(int)$row['member_id']] = $row['navigating_since'];
            }
        }

        foreach ($participants as $participant) {
            $uid = (int)$participant['member_id'];
            if (empty($lastByUser[$uid])) {
                $summary['not_started']++;
                continue;
            }
            $lastPingTs = strtotime($lastByUser[$uid]);
            $age = time() - $lastPingTs;
            $navSince = $navigatingByUser[$uid] ?? null;
            $navSinceTs = $navSince ? strtotime($navSince) : null;
            $isNavigating = $navSinceTs !== null
                && $navSinceTs > $lastPingTs
                && (time() - $navSinceTs) <= 120;
            if ($isNavigating) {
                $summary['navigating']++;
            } elseif ($age > 120) {
                $summary['stale']++;
            } else {
                $summary['tracking']++;
            }
        }
    } catch (Exception $e) {
        $summary['not_started'] = $summary['approved'];
    }

    $summary['ready_percent'] = $summary['approved'] > 0
        ? (int)round(($summary['tracking'] / $summary['approved']) * 100)
        : 0;

    return $summary;
}
```

- [ ] **Step 2: Add the "Στην πλοήγηση" row to the Ride Readiness card**

Replace (`mission-view.php`, the Stale row inside the Ride Readiness card):
```php
                <div class="d-flex justify-content-between mb-2">
                    <span>Stale:</span>
                    <strong class="text-warning"><?= (int)$rideReadiness['stale'] ?></strong>
                </div>
```
with:
```php
                <div class="d-flex justify-content-between mb-2">
                    <span><i class="bi bi-signpost-2 me-1"></i>Στην πλοήγηση:</span>
                    <strong class="text-info"><?= (int)$rideReadiness['navigating'] ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Stale:</span>
                    <strong class="text-warning"><?= (int)$rideReadiness['stale'] ?></strong>
                </div>
```

- [ ] **Step 3: Lint**

Run: `C:\xampp\php\php.exe -l includes/ride-functions.php && C:\xampp\php\php.exe -l mission-view.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Verify with a CLI script**

```bash
cat > /tmp/test_readiness_summary.php << 'EOF'
<?php
require_once 'C:/Users/user/Desktop/easyride/bootstrap.php';
require_once 'C:/Users/user/Desktop/easyride/includes/ride-functions.php';

$mission = dbFetchOne("SELECT id FROM missions WHERE status IN ('OPEN','COMPLETED') ORDER BY id DESC LIMIT 1");
if (!$mission) {
    echo "No mission found — skip.\n";
    exit;
}
$summary = getRideReadinessSummary((int)$mission['id']);
echo "Keys present: " . implode(', ', array_keys($summary)) . "\n";
var_dump($summary);
EOF
C:\xampp\php\php.exe /tmp/test_readiness_summary.php
rm /tmp/test_readiness_summary.php
```

Expected: `Keys present: approved, tracking, navigating, stale, not_started, ready_percent` — confirms the new `navigating` key exists and the function still runs without error.

- [ ] **Step 5: Commit**

```bash
git add includes/ride-functions.php mission-view.php
git commit -m "Surface navigating count in getRideReadinessSummary() and the Ride Readiness card"
```

---

### Task 5: Fire the navigating signal + passive "welcome back" toast in `ride-mode.php`

**Files:**
- Modify: `ride-mode.php:349-353` (Πλοήγηση button — add an id)
- Modify: `ride-mode.php:880-885` (`visibilitychange` listener)
- Modify: `ride-mode.php` (add toast markup once, near the top of `<body>`, and a `showToast()` helper near the other script functions)

**Interfaces:**
- Consumes: `api-ride-navigate.php` (Task 2), existing `missionId`, `shiftId`, `csrfTokenValue`, `lastSentAt`, `tracking` JS variables (`ride-mode.php:390-408`).
- Produces: `showToast(message: string): void` — a plain global function, not consumed elsewhere but kept name-stable in case Task 6 also needs it (it does not, per this plan).

**Important discovery affecting this task:** `ride-mode.php` loads Bootstrap 5 CSS but **not** the Bootstrap JS bundle (only `leaflet.js` is loaded as an external script — confirmed by grepping `<script src` in this file). The spec's toast snippet uses `new bootstrap.Toast(...)`, which would throw `bootstrap is not defined` here. This task implements the toast with Bootstrap's existing CSS classes (`.toast`, `.show`) but toggles visibility with plain vanilla JS instead of instantiating the Bootstrap JS component — same visual result, no new script dependency added to a GPS-tracking page where every extra script matters.

- [ ] **Step 1: Give the Πλοήγηση button an id**

Replace (`ride-mode.php:349-353`):
```php
                <?php if ($directionsUrl): ?>
                    <a href="<?= h($directionsUrl) ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-sign-turn-right me-1"></i>Πλοήγηση
                    </a>
                <?php endif; ?>
```
with:
```php
                <?php if ($directionsUrl): ?>
                    <a href="<?= h($directionsUrl) ?>" id="navigateBtn" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-sign-turn-right me-1"></i>Πλοήγηση
                    </a>
                <?php endif; ?>
```

- [ ] **Step 2: Add the toast markup**

Find the `<body>` tag (`ride-mode.php:214`) and insert this immediately after it:
```php
<body>
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="rideModeToast" class="toast align-items-center text-white bg-dark border-0" role="status">
        <div class="d-flex">
            <div class="toast-body" id="rideModeToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('rideModeToast').classList.remove('show')"></button>
        </div>
    </div>
</div>
```
(Only the `<div class="toast-container"...>...</div>` block is new; the surrounding `<body>` tag itself already exists at line 214 and is unchanged.)

- [ ] **Step 3: Add `showToast()` and the navigate-signal click handler**

Find the `visibilitychange` listener (`ride-mode.php:880-885`):
```js
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && tracking) {
        requestWakeLock();
        rideMap.invalidateSize();
    }
});
```
Replace it with:
```js
let toastTimer = null;
function showToast(message) {
    const toastEl = document.getElementById('rideModeToast');
    if (!toastEl) return;
    document.getElementById('rideModeToastBody').textContent = message;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 6000);
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && tracking) {
        const goneMs = Date.now() - lastSentAt;
        if (goneMs > 120000) {
            const minutes = Math.round(goneMs / 60000);
            showToast('Το στίγμα σου διακόπηκε ' + minutes + ' λεπτά. Άφησε ανοιχτή αυτή την οθόνη για να συνεχίσει η μετάδοση.');
        }
        requestWakeLock();
        rideMap.invalidateSize();
    }
});

document.getElementById('navigateBtn')?.addEventListener('click', () => {
    fetch('api-ride-navigate.php', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({
            csrf_token: csrfTokenValue,
            mission_id: String(missionId),
            shift_id: String(shiftId),
        }),
    }).catch(() => {});
});
```

- [ ] **Step 4: Lint**

Run: `C:\xampp\php\php.exe -l ride-mode.php`
Expected: `No syntax errors detected in ride-mode.php`

- [ ] **Step 5: Manual browser check — navigate signal**

1. Log in as a member with an approved participation on an `OPEN` mission that has a route (≥2 points), open `ride-mode.php?mission_id=<id>`.
2. Confirm the "Πλοήγηση" button is present and has `id="navigateBtn"` (inspect element).
3. Click it. Confirm Google Maps opens in a new tab (existing behavior, unchanged) and, in DevTools Network tab, confirm a `POST api-ride-navigate.php` request fired with status 200 and `{"ok":true}`.
4. Query the DB directly to confirm the write landed: `SELECT navigating_since FROM participation_requests WHERE id = <participation_id>;` should show a fresh timestamp.

- [ ] **Step 6: Manual browser check — passive toast**

1. With Ride Mode tracking started (`tracking = true`), use DevTools to simulate: run `lastSentAt = Date.now() - 130000;` in the console to fake a 130-second gap (older than the 120s threshold).
2. Switch to a different browser tab, then switch back (triggers a real `visibilitychange` to `'visible'`).
3. Confirm the toast appears in the bottom-right with the message "Το στίγμα σου διακόπηκε 2 λεπτά. Άφησε ανοιχτή αυτή την οθόνη για να συνεχίσει η μετάδοση." and disappears on its own after ~6 seconds (or immediately if you click its close button).
4. Repeat with `lastSentAt = Date.now() - 30000;` (30 seconds, under the threshold), switch tabs and back — confirm the toast does **not** appear.

- [ ] **Step 7: Commit**

```bash
git add ride-mode.php
git commit -m "Fire navigating signal on Πλοήγηση click and show a passive return-to-app toast"
```

---

### Task 6: Render the navigating badge in Ride Mode's participant list and map

**Files:**
- Modify: `ride-mode.php:477-481` (`markerHtml()`)
- Modify: `ride-mode.php:483-527` (`renderParticipants()`)
- Modify: `ride-mode.php:307-315` (Readiness Ride Mode grid HTML)
- Modify: `ride-mode.php:595-612` (`renderReadiness()`)

**Interfaces:**
- Consumes: `participant.is_navigating` (bool) and `readiness.navigating` (int) from `api-ride-data.php`'s JSON response (Task 3) — exact field names as produced there.

- [ ] **Step 1: Give navigating participants a distinct marker color**

Replace (`ride-mode.php:493-494`):
```js
    list.innerHTML = participants.map(p => {
        const color = p.is_stale ? '#94a3b8' : (p.status_color || '#0d6efd');
```
with:
```js
    list.innerHTML = participants.map(p => {
        const color = p.is_navigating ? '#0dcaf0' : (p.is_stale ? '#94a3b8' : (p.status_color || '#0d6efd'));
```

Replace (`ride-mode.php:502-506`, the badges array):
```js
        const badges = [
            p.is_off_route ? '<span class="badge bg-warning text-dark ms-1">Εκτός διαδρομής</span>' : '',
            p.is_stationary ? '<span class="badge bg-warning text-dark ms-1">Ακίνητος</span>' : '',
            p.is_stale ? '<span class="badge bg-secondary ms-1">Χωρίς στίγμα</span>' : ''
        ].join('');
```
with:
```js
        const badges = [
            p.is_off_route ? '<span class="badge bg-warning text-dark ms-1">Εκτός διαδρομής</span>' : '',
            p.is_stationary ? '<span class="badge bg-warning text-dark ms-1">Ακίνητος</span>' : '',
            p.is_navigating ? '<span class="badge bg-info text-dark ms-1"><i class="bi bi-sign-turn-right me-1"></i>Στην πλοήγηση</span>' : (p.is_stale ? '<span class="badge bg-secondary ms-1">Χωρίς στίγμα</span>' : '')
        ].join('');
```

Replace (`ride-mode.php:514-522`, the map-marker loop):
```js
    participants.forEach(p => {
        if (!p.lat || !p.lng) return;
        const color = p.is_stale ? '#94a3b8' : (p.status_color || '#0d6efd');
        const icon = L.divIcon({
            className: '',
            html: markerHtml(color, p.is_self, p.is_stale),
            iconSize: [p.is_self ? 26 : 22, p.is_self ? 26 : 22],
            iconAnchor: [p.is_self ? 13 : 11, p.is_self ? 13 : 11]
        });
```
with:
```js
    participants.forEach(p => {
        if (!p.lat || !p.lng) return;
        const color = p.is_navigating ? '#0dcaf0' : (p.is_stale ? '#94a3b8' : (p.status_color || '#0d6efd'));
        const icon = L.divIcon({
            className: '',
            html: markerHtml(color, p.is_self, p.is_stale && !p.is_navigating),
            iconSize: [p.is_self ? 26 : 22, p.is_self ? 26 : 22],
            iconAnchor: [p.is_self ? 13 : 11, p.is_self ? 13 : 11]
        });
```

(`markerHtml()` itself, `ride-mode.php:477-481`, is unchanged — passing `false` for its `isStale` param when navigating keeps the marker at full opacity, since a navigating rider isn't actually lost, just off in Google Maps.)

- [ ] **Step 2: Add a "Στην πλοήγηση" tile to the Readiness Ride Mode grid**

Replace (`ride-mode.php:307-315`):
```html
            <div class="ride-status-grid" id="readinessGrid">
                <div class="metric-lite"><strong>0</strong><span>Εγκεκριμένοι</span></div>
                <div class="metric-lite"><strong>0</strong><span>Στέλνουν GPS</span></div>
                <div class="metric-lite"><strong>0</strong><span>Δεν ξεκίνησαν</span></div>
                <div class="metric-lite"><strong>0</strong><span>Stale</span></div>
                <div class="metric-lite"><strong>0</strong><span>Status</span></div>
                <div class="metric-lite"><strong>0%</strong><span>Έτοιμοι</span></div>
            </div>
```
with:
```html
            <div class="ride-status-grid" id="readinessGrid">
                <div class="metric-lite"><strong>0</strong><span>Εγκεκριμένοι</span></div>
                <div class="metric-lite"><strong>0</strong><span>Στέλνουν GPS</span></div>
                <div class="metric-lite"><strong>0</strong><span>Δεν ξεκίνησαν</span></div>
                <div class="metric-lite"><strong>0</strong><span>Στην πλοήγηση</span></div>
                <div class="metric-lite"><strong>0</strong><span>Stale</span></div>
                <div class="metric-lite"><strong>0</strong><span>Status</span></div>
                <div class="metric-lite"><strong>0%</strong><span>Έτοιμοι</span></div>
            </div>
```

- [ ] **Step 3: Update `renderReadiness()` to fill the new tile**

Replace (`ride-mode.php:595-612`):
```js
function renderReadiness(readiness) {
    const grid = document.getElementById('readinessGrid');
    if (!grid) return;
    const approved = Number(readiness.approved || 0);
    const tracking = Number(readiness.tracking || 0);
    const readyPct = approved > 0 ? Math.round((tracking / approved) * 100) : 0;
    const values = [
        approved,
        tracking,
        Number(readiness.not_started || 0),
        Number(readiness.stale || 0),
        Number(readiness.with_status || 0),
        readyPct + '%'
    ];
    grid.querySelectorAll('.metric-lite strong').forEach((node, index) => {
        node.textContent = values[index] ?? '0';
    });
}
```
with:
```js
function renderReadiness(readiness) {
    const grid = document.getElementById('readinessGrid');
    if (!grid) return;
    const approved = Number(readiness.approved || 0);
    const tracking = Number(readiness.tracking || 0);
    const readyPct = approved > 0 ? Math.round((tracking / approved) * 100) : 0;
    const values = [
        approved,
        tracking,
        Number(readiness.not_started || 0),
        Number(readiness.navigating || 0),
        Number(readiness.stale || 0),
        Number(readiness.with_status || 0),
        readyPct + '%'
    ];
    grid.querySelectorAll('.metric-lite strong').forEach((node, index) => {
        node.textContent = values[index] ?? '0';
    });
}
```

(The `values` array order must match the HTML tile order from Step 2 exactly: approved, tracking, not_started, navigating, stale, with_status, ready%.)

- [ ] **Step 4: Lint**

Run: `C:\xampp\php\php.exe -l ride-mode.php`
Expected: `No syntax errors detected in ride-mode.php`

- [ ] **Step 5: Manual browser check**

1. Using the same setup as Task 5's manual checks, tap "Πλοήγηση" as a rider (Browser A).
2. In a second browser/session logged in as the mission's responsible person (a controller — `isRideController()` returns true for them), open the same `ride-mode.php?mission_id=<id>` (this renders as "Ride Control" for them, per the existing `$isController` flag — same file and same `renderParticipants()`/`renderReadiness()` as the rider's own view, just with controller-only affordances enabled) and wait for the periodic refresh (`refreshRideData`, polled every 10s per the earlier research).
3. Confirm the rider from Browser A shows a cyan "🧭 Στην πλοήγηση" badge (not the gray "Χωρίς στίγμα" badge) in the participant list, their map marker is cyan and full-opacity, and the "Στην πλοήγηση" readiness tile shows `1`.
4. Wait over 2 minutes without any further ping from Browser A; confirm the badge reverts to the normal stale treatment and the readiness tiles update accordingly (navigating count back to 0, stale count incremented).

(Note: `ops-dashboard.php` is a separate, multi-mission overview page — aggregate staffing/mission-level map only, no per-participant `is_stale`/`is_navigating` rendering of its own — so it needs no changes here. The controller-facing live view for *this* ride is `ride-mode.php` itself in Controller mode, which Step 2 above already covers.)

- [ ] **Step 6: Commit**

```bash
git add ride-mode.php
git commit -m "Render the navigating badge and readiness tile in Ride Mode's participant list and map"
```

---

### Task 7: Version bump, docs, and release

**Files:**
- Modify: `config.php:14`
- Modify: `README.md`
- Modify: `ROADMAP.md`

- [ ] **Step 1: Bump `APP_VERSION`**

In `config.php:14`, change:
```php
define('APP_VERSION', '3.86.2');
```
to:
```php
define('APP_VERSION', '3.87.0');
```

- [ ] **Step 2: Update `README.md`**

Update the version line and badge:
```markdown
**Version:** 3.87.0  
```
```markdown
![Version](https://img.shields.io/badge/version-3.87.0-blue)
```

Add a new entry at the top of "Current Release Highlights" (replace):
```markdown
## Current Release Highlights

### v3.86.2
```
with:
```markdown
## Current Release Highlights

### v3.87.0

- Ride Mode now distinguishes a rider who tapped "Πλοήγηση" (opened Google Maps) from one whose GPS signal genuinely went stale: the ops dashboard and Ride Mode's own participant list show a "Στην πλοήγηση" badge instead of an alarming "Stale", and no permanent event is logged purely because someone used Google Maps. Riders also get a passive, non-intrusive reminder to keep the screen open if they return to a long-backgrounded tab — never a push notification, since that would be a distraction risk while riding.

### v3.86.2
```

- [ ] **Step 3: Update `ROADMAP.md`**

Add a new entry at the top of the "Deployed" section:
```markdown

### v3.87.0 — Κατάσταση "Στην Πλοήγηση" στο Ride Mode
- Όταν ένας αναβάτης πατάει "Πλοήγηση" στο Ride Mode, το Google Maps ανοίγει σε νέο tab και το στίγμα GPS συχνά σταματά σιωπηλά (περιορισμός των mobile browsers σε background tabs). Τώρα ο υπεύθυνος βλέπει "🧭 Στην πλοήγηση" αντί για ασαφές "Stale", χωρίς μόνιμη καταγραφή — και ο αναβάτης παίρνει ένα διακριτικό μήνυμα να αφήσει την οθόνη ανοιχτή όταν επιστρέψει μόνος του στην εφαρμογή.
- Spec: [docs/superpowers/specs/2026-07-08-navigating-status-design.md](docs/superpowers/specs/2026-07-08-navigating-status-design.md)
```

- [ ] **Step 4: Lint**

Run: `C:\xampp\php\php.exe -l config.php`
Expected: `No syntax errors detected in config.php`

- [ ] **Step 5: Commit**

```bash
git add config.php README.md ROADMAP.md
git commit -m "Bump version for navigating-status release"
```

- [ ] **Step 6: Sync and release**

```bash
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups uploads exports node_modules .superpowers /XF config.local.php update.log
git tag -a v3.87.0 -m "v3.87.0"
git push origin main
git push origin v3.87.0
gh release create v3.87.0 --repo TheoSfak/easyrider --title "EasyRide v3.87.0" --notes "Ride Mode now distinguishes a rider navigating via Google Maps from a genuinely stale GPS signal, with a passive reminder to keep the screen open — no push notifications, no permanent log entries."
```
