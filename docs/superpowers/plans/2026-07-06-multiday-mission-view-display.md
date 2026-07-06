# Multi-Day Mission Itinerary Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Display the per-day route/title/overnight itinerary (stored in `mission_days`, phase 1, already shipped) on `mission-view.php` via day tabs, for multi-day missions only — single-day missions are completely unaffected.

**Architecture:** `mission-view.php` gains a new PHP block that loads `mission_days` rows and computes per-day metrics/geometry by reusing the existing `rideMissionRouteMetrics()`/`rideRouteGeometry()` helpers unchanged (since `mission_days` columns mirror `missions` columns). When multi-day, a new "Ημερήσιο Πρόγραμμα" card (day tabs + one shared read-only Leaflet map + timeline + badges) replaces the existing single-route map/timeline cards, which stay untouched for single-day missions. A small JS module redraws the shared map and rebuilds the timeline/badges HTML when a tab is clicked, using data embedded as one inline JSON payload.

**Tech Stack:** PHP 8.2 (no framework), vanilla JS, Leaflet 1.9.4 (already loaded on this page), Bootstrap 5.3 nav-pills.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-06-multiday-mission-view-display-design.md`
- No changes to `mission-form.php` (phase 1, already shipped), `shifts`, or `mission_recurrences`.
- Ride Replay stays single-day-only in this phase — no "Ride Replay" button on the new multi-day card, and the existing Ride Replay data/script setup must not run for multi-day missions.
- Single-day missions must remain pixel-for-pixel, behavior-for-behavior unchanged — every task's verification must re-confirm this.
- No automated test framework exists in this repo. Verify PHP logic with a throwaway CLI script (created, run, then deleted, never committed) where practical, and verify UI/JS behavior manually in the browser — this project's established convention.

---

### Task 1: Backend PHP — load `mission_days`, compute per-day data, gate existing cards

**Files:**
- Modify: `mission-view.php` (add loading/computation block after line 33 `$routeGeometry = rideRouteGeometry($mission);`; modify the two existing card guards at line 1072 and the timeline card's guard further down)

**Interfaces:**
- Consumes: `rideMissionRouteMetrics(array $mission, ?array $routePoints = null): array`, `rideRouteGeometry(array $mission): array`, `normalizeRideRoutePoints($raw): array` (all pre-existing in `includes/ride-functions.php`, unchanged), `buildGoogleMapsDirectionsUrl(array $routePoints, array $mission): string` (pre-existing in this file, unchanged), `formatDateGreek($date)` (pre-existing in `includes/functions.php`).
- Produces: `$missionDays` (array of raw DB rows plus computed keys `route_points_decoded`, `metrics`, `geometry`, `directions_url`, `date_label`), `$isMultiDayMission` (bool) — both consumed by Task 2 (markup) and Task 3 (JS payload).

- [ ] **Step 1: Load and compute per-day data**

In `mission-view.php`, find this line (currently line 33):
```php
$routeGeometry = rideRouteGeometry($mission);
```

Add immediately after it:
```php

$missionDays = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number", [$id]);
$isMultiDayMission = !empty($missionDays);
foreach ($missionDays as &$day) {
    $day['route_points_decoded'] = normalizeRideRoutePoints($day['route_points'] ?? '[]');
    $day['metrics'] = rideMissionRouteMetrics($day, $day['route_points_decoded']);
    $day['geometry'] = rideRouteGeometry($day);
    $day['date_label'] = formatDateGreek($day['day_date']);
}
unset($day);
```

Note: `$id` is already defined earlier in this file (line 10: `$id = (int) get('id');`) and `$mission` is already fetched (line 15) before this point, so both are safely available here.

- [ ] **Step 2: Compute `directions_url` per day**

`buildGoogleMapsDirectionsUrl()` is defined later in the file (currently starting at line 35) — since PHP function declarations are hoisted, calling it from code placed earlier in the file works fine, but for clarity add this loop AFTER the function definition and the existing `$googleDirectionsUrl = buildGoogleMapsDirectionsUrl($routePoints, $mission);` line (currently line 71):

```php
$googleDirectionsUrl = buildGoogleMapsDirectionsUrl($routePoints, $mission);

foreach ($missionDays as &$day) {
    $day['directions_url'] = buildGoogleMapsDirectionsUrl($day['route_points_decoded'], $mission);
}
unset($day);
```

(Passing the real `$mission` as the second argument is intentional and correct: `buildGoogleMapsDirectionsUrl()` only reads `$mission['maps_link']`/`latitude`/`longitude` as a fallback when a day has zero route points — the mission's own location link is a sensible fallback for an empty day.)

- [ ] **Step 3: Gate the existing "Χάρτης Διαδρομής"/"Χάρτης Τοποθεσίας" card**

Find (currently line 1072):
```php
        <?php if ((!empty($mission['latitude']) && !empty($mission['longitude'])) || !empty($routePoints)): ?>
```

Replace with:
```php
        <?php if (!$isMultiDayMission && ((!empty($mission['latitude']) && !empty($mission['longitude'])) || !empty($routePoints))): ?>
```

- [ ] **Step 4: Gate the existing "Χρονοδιάγραμμα Διαδρομής" card**

Find the timeline card's opening guard — search for `<?php if (!empty($routePoints)): ?>` that immediately precedes the `<h6 class="mb-0"><i class="bi bi-clock-history me-1 text-primary"></i>Χρονοδιάγραμμα Διαδρομής</h6>` heading (currently around line 1107). Replace that specific occurrence with:
```php
        <?php if (!$isMultiDayMission && !empty($routePoints)): ?>
```

(There may be other unrelated `<?php if (!empty($routePoints)): ?>` checks elsewhere in the file for different purposes — only change the one immediately guarding this specific timeline card block, identifiable by the "Χρονοδιάγραμμα Διαδρομής" heading directly inside it.)

- [ ] **Step 5: Verify with a throwaway script**

Create `verify-mission-days-view.php` at the repo root:
```php
<?php
define('VOLUNTEEROPS', true);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/ride-functions.php';
require __DIR__ . '/includes/functions.php';

function assertTrue(bool $cond, string $label): void {
    echo ($cond ? "PASS: " : "FAIL: ") . $label . "\n";
    if (!$cond) $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + 1;
}
$GLOBALS['failures'] = 0;

$day = [
    'day_number' => 1,
    'day_date' => '2026-09-10',
    'title' => 'Day one',
    'overnight_notes' => 'Hotel X',
    'route_points' => json_encode([['lat' => 35.3, 'lng' => 25.1, 'title' => '', 'scheduled_time' => '', 'stop_minutes' => 0, 'notes' => '']]),
    'route_geometry' => null,
    'route_distance_meters' => null,
    'route_duration_seconds' => null,
    'route_provider' => null,
];

$points = normalizeRideRoutePoints($day['route_points']);
assertTrue(count($points) === 1, 'normalizeRideRoutePoints decodes 1 point from a mission_days row');

$metrics = rideMissionRouteMetrics($day, $points);
assertTrue(isset($metrics['distance_label']), 'rideMissionRouteMetrics works unchanged on a mission_days row (has distance_label)');
assertTrue($metrics['provider'] === 'estimate', 'falls back to straight-line estimate when the day has no Google-snapped geometry');

$geometry = rideRouteGeometry($day);
assertTrue($geometry === [], 'rideRouteGeometry returns empty array for a day with no route_geometry');

$label = formatDateGreek($day['day_date']);
assertTrue(strpos($label, '2026') !== false, 'formatDateGreek formats a mission_days day_date correctly, got: ' . $label);

echo $GLOBALS['failures'] === 0 ? "\nALL PASS\n" : "\n{$GLOBALS['failures']} FAILURE(S)\n";
exit($GLOBALS['failures'] === 0 ? 0 : 1);
```

Run: `C:\xampp\php\php.exe verify-mission-days-view.php`
Expected: all 5 assertions PASS, ending with `ALL PASS`.

Delete the throwaway script afterward: `rm verify-mission-days-view.php` (do not commit it).

- [ ] **Step 6: Lint, sync, manual verification**

```bash
C:\xampp\php\php.exe -l mission-view.php
```

Sync (PowerShell, so `/MIR` isn't mis-parsed):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

In the browser: open an ordinary single-day mission and confirm the "Χάρτης Διαδρομής"/"Χρονοδιάγραμμα Διαδρομής" cards still render exactly as before. Open a multi-day mission (e.g. recreate one similar to phase 1's testing, with `mission_days` rows) and confirm those two cards are now GONE (no new card exists yet — that's Task 2, so an empty gap here at this step is expected and fine).

- [ ] **Step 7: Commit**

```bash
git add mission-view.php
git commit -m "Load and compute per-day mission_days data for view display"
```

---

### Task 2: New "Ημερήσιο Πρόγραμμα" card markup

**Files:**
- Modify: `mission-view.php` (add new card markup right after the timeline card's closing, guarded by `$isMultiDayMission`)

**Interfaces:**
- Consumes: `$missionDays` (from Task 1).
- Produces: DOM elements Task 3 wires up: `#missionDayViewTabs` (nav-pills `<ul>`), `#dayViewDate`, `#dayViewNavBtn`, `#dayViewOvernight` (wrapper) / `#dayViewOvernightText` (inner span), `#dayViewMap`, `#dayViewMetrics`, `#dayViewTimeline`.

- [ ] **Step 1: Add the new card**

Find the end of the "Χρονοδιάγραμμα Διαδρομής" card block (the `<?php endif; ?>` that closes the block Task 1's Step 4 modified — this is the same `<?php if (!empty($routePoints)): ?>` block whose matching `<?php endif; ?>` appears after its `list-group` content; search for the closing `</div>` of that card followed by `<?php endif; ?>`). Add this new block immediately after that `<?php endif; ?>`:

```php
        <?php if ($isMultiDayMission): ?>
        <div class="card mb-4">
            <div class="card-header py-2 bg-light">
                <h6 class="mb-0"><i class="bi bi-calendar-range me-1 text-primary"></i>Ημερήσιο Πρόγραμμα</h6>
            </div>
            <ul class="nav nav-pills p-2 border-bottom" id="missionDayViewTabs">
                <?php foreach ($missionDays as $i => $day): ?>
                <li class="nav-item">
                    <a href="#" class="nav-link py-1 px-2<?= $i === 0 ? ' active' : '' ?>" data-day-number="<?= (int)$day['day_number'] ?>">
                        <?= h($day['title'] ?: 'Μέρα ' . (int)$day['day_number']) ?> · <?= h(date('d/m', strtotime($day['day_date']))) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center small">
                <span id="dayViewDate" class="text-muted"></span>
                <a href="#" id="dayViewNavBtn" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem;display:none;">
                    <i class="bi bi-sign-turn-right me-1"></i>Πλοήγηση
                </a>
            </div>
            <div id="dayViewOvernight" class="px-3 py-2 border-bottom small bg-light" style="display:none;">
                <i class="bi bi-moon-stars me-1"></i><span id="dayViewOvernightText"></span>
            </div>
            <div id="dayViewMap" style="height:320px;"></div>
            <div class="p-2 border-bottom d-flex flex-wrap gap-2 small" id="dayViewMetrics"></div>
            <div class="list-group list-group-flush" id="dayViewTimeline"></div>
        </div>
        <?php endif; ?>
```

- [ ] **Step 2: Lint, sync, manual verification**

```bash
C:\xampp\php\php.exe -l mission-view.php
```

Sync (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

In the browser: open the multi-day mission from Task 1's verification — confirm the new "Ημερήσιο Πρόγραμμα" card appears with tabs for each day, an empty map area (no Leaflet map rendered yet — that's Task 3), and empty metrics/timeline areas. Confirm the single-day mission's page is still unaffected.

- [ ] **Step 3: Commit**

```bash
git add mission-view.php
git commit -m "Add multi-day itinerary card markup with day tabs"
```

---

### Task 3: JS — render the active day's map/timeline/badges, wire tab clicks

**Files:**
- Modify: `mission-view.php` (the Leaflet-loading guard and inline script block, currently starting around line 2342; the exact line will have shifted slightly after Tasks 1-2, search for the anchor text shown below)

**Interfaces:**
- Consumes: `$missionDays`/`$isMultiDayMission` (Task 1), DOM ids from Task 2.
- Produces: nothing consumed by later tasks (Task 4 is verification/bookkeeping only).

- [ ] **Step 1: Widen the Leaflet-loading guard and separate the two script paths**

Find this block (search for the exact text — line numbers will have shifted from Tasks 1-2's insertions):
```php
<?php if ((!empty($mission['latitude']) && !empty($mission['longitude'])) || !empty($routePoints)): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ride-replay.js?v=<?= APP_VERSION ?>"></script>
<script>
(function () {
    var lat = <?= !empty($mission['latitude']) ? (float)$mission['latitude'] : 'null' ?>;
```

Replace the opening guard and the two script tags immediately below it with:
```php
<?php if ((!$isMultiDayMission && ((!empty($mission['latitude']) && !empty($mission['longitude'])) || !empty($routePoints))) || $isMultiDayMission): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php if (!$isMultiDayMission): ?>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ride-replay.js?v=<?= APP_VERSION ?>"></script>
<script>
(function () {
    var lat = <?= !empty($mission['latitude']) ? (float)$mission['latitude'] : 'null' ?>;
```

The outer parentheses around the first half are explicit and required for correct grouping — the condition reads as "(single-day AND (has location or route)) OR is-multi-day", i.e. "load Leaflet when either the single-day mission actually has something to show, or the mission is multi-day (which always needs the map)." Do not drop those parentheses — without them, PHP's operator precedence (`&&` binds tighter than `||`, so it would still parse the same way here, but do not rely on that; keep the explicit grouping so the intent is unambiguous to any future reader).

- [ ] **Step 2: Close the single-day script block with a new guard, and add the multi-day script block**

Find the end of the existing single-day map script (currently):
```javascript
    setTimeout(function() { map.invalidateSize(); }, 150);
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
```

Replace with:
```javascript
    setTimeout(function() { map.invalidateSize(); }, 150);
})();
</script>
<?php endif; ?>

<?php if ($isMultiDayMission): ?>
<script>
(function () {
    var days = <?= json_encode(array_map(function ($day) {
        return [
            'day_number' => (int)$day['day_number'],
            'date_label' => $day['date_label'],
            'overnight_notes' => (string)($day['overnight_notes'] ?? ''),
            'directions_url' => (string)($day['directions_url'] ?? ''),
            'points' => $day['route_points_decoded'],
            'geometry' => $day['geometry'],
            'metrics' => [
                'distance_label' => (string)($day['metrics']['distance_label'] ?? ''),
                'moving_label' => (string)($day['metrics']['moving_label'] ?? ''),
                'stop_label' => (string)($day['metrics']['stop_label'] ?? ''),
                'total_label' => (string)($day['metrics']['total_label'] ?? ''),
                'provider' => (string)($day['metrics']['provider'] ?? 'estimate'),
            ],
        ];
    }, $missionDays), JSON_UNESCAPED_UNICODE) ?>;
    var fallbackLat = <?= !empty($mission['latitude']) ? (float)$mission['latitude'] : 'null' ?>;
    var fallbackLng = <?= !empty($mission['longitude']) ? (float)$mission['longitude'] : 'null' ?>;

    var map = null;
    var markers = [];
    var polyline = null;

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[ch];
        });
    }

    function findDay(dayNumber) {
        for (var i = 0; i < days.length; i++) {
            if (days[i].day_number === dayNumber) return days[i];
        }
        return null;
    }

    function formatMetricsHtml(day) {
        if (!day.points.length) {
            return '<span class="text-muted small">Δεν έχουν οριστεί σημεία διαδρομής για αυτή τη μέρα.</span>';
        }
        var html = '<span class="badge bg-light text-dark border"><i class="bi bi-signpost-2 me-1"></i>' + esc(day.metrics.distance_label) + '</span>';
        html += '<span class="badge bg-light text-dark border"><i class="bi bi-stopwatch me-1"></i>Οδήγηση ' + esc(day.metrics.moving_label) + '</span>';
        html += '<span class="badge bg-warning text-dark"><i class="bi bi-cup-hot me-1"></i>Στάσεις ' + esc(day.metrics.stop_label) + '</span>';
        html += '<span class="badge bg-primary"><i class="bi bi-clock-history me-1"></i>Σύνολο ' + esc(day.metrics.total_label) + '</span>';
        html += day.metrics.provider === 'google'
            ? '<span class="text-success align-self-center"><i class="bi bi-google me-1"></i>Διαδρομή μέσω Google.</span>'
            : '<span class="text-muted align-self-center">Εκτίμηση σε ευθεία γραμμή.</span>';
        return html;
    }

    function formatTimelineHtml(day) {
        if (!day.points.length) {
            return '<div class="list-group-item text-muted small">Δεν έχουν οριστεί σημεία διαδρομής.</div>';
        }
        return day.points.map(function(point, idx) {
            var extras = '';
            if (point.scheduled_time) extras += '<span class="badge bg-light text-dark border"><i class="bi bi-clock me-1"></i>' + esc(point.scheduled_time) + '</span>';
            if (point.stop_minutes > 0) extras += '<span class="badge bg-warning text-dark"><i class="bi bi-cup-hot me-1"></i>Στάση ' + parseInt(point.stop_minutes, 10) + '\'</span>';
            var notes = point.notes ? '<div class="text-muted small mt-1">' + esc(point.notes).replace(/\n/g, '<br>') + '</div>' : '';
            return '<div class="list-group-item"><div class="d-flex gap-2">' +
                '<span class="badge bg-primary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:28px;height:28px;">' + (idx + 1) + '</span>' +
                '<div class="flex-grow-1"><div class="d-flex flex-wrap align-items-center gap-2"><strong>' + esc(point.title || ('Σημείο ' + (idx + 1))) + '</strong>' + extras + '</div>' + notes + '</div>' +
                '</div></div>';
        }).join('');
    }

    function renderDay(dayNumber) {
        var day = findDay(dayNumber);
        if (!day) return;

        document.querySelectorAll('#missionDayViewTabs .nav-link').forEach(function(tab) {
            tab.classList.toggle('active', parseInt(tab.dataset.dayNumber, 10) === dayNumber);
        });

        document.getElementById('dayViewDate').textContent = day.date_label;

        var overnightWrap = document.getElementById('dayViewOvernight');
        if (day.overnight_notes) {
            overnightWrap.style.display = '';
            document.getElementById('dayViewOvernightText').textContent = day.overnight_notes;
        } else {
            overnightWrap.style.display = 'none';
        }

        var navBtn = document.getElementById('dayViewNavBtn');
        if (day.directions_url) {
            navBtn.href = day.directions_url;
            navBtn.style.display = '';
        } else {
            navBtn.style.display = 'none';
        }

        document.getElementById('dayViewMetrics').innerHTML = formatMetricsHtml(day);
        document.getElementById('dayViewTimeline').innerHTML = formatTimelineHtml(day);

        markers.forEach(function(m) { m.remove(); });
        markers = [];
        if (polyline) { polyline.remove(); polyline = null; }

        var latLngs = day.points.map(function(p) { return [p.lat, p.lng]; });
        latLngs.forEach(function(latLng, index) {
            var point = day.points[index];
            var popup = '<strong>' + esc(point.title || ('Σημείο ' + (index + 1))) + '</strong>';
            if (point.notes) popup += '<br><small>' + esc(point.notes) + '</small>';
            var marker = L.circleMarker(latLng, {
                radius: 5, color: '#0d6efd', fillColor: '#0d6efd', fillOpacity: 0.9, weight: 2
            }).addTo(map).bindTooltip(String(index + 1), { permanent: true, direction: 'center', className: 'route-point-label' }).bindPopup(popup);
            markers.push(marker);
        });

        if (latLngs.length >= 2) {
            polyline = L.polyline(day.geometry.length >= 2 ? day.geometry : latLngs, { color: '#0d6efd', weight: 5, opacity: 0.85 }).addTo(map);
        }

        if (latLngs.length) {
            map.fitBounds(L.latLngBounds(day.geometry.length >= 2 ? day.geometry.concat(latLngs) : latLngs), { padding: [25, 25] });
        } else if (fallbackLat !== null) {
            map.setView([fallbackLat, fallbackLng], 13);
        } else {
            map.setView([35.3387, 25.1442], 9);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        map = L.map('dayViewMap', { zoomControl: true, scrollWheelZoom: false }).setView([35.3387, 25.1442], 9);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19
        }).addTo(map);

        document.querySelectorAll('#missionDayViewTabs .nav-link').forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                renderDay(parseInt(tab.dataset.dayNumber, 10));
            });
        });

        if (days.length) {
            renderDay(days[0].day_number);
        }
        setTimeout(function() { map.invalidateSize(); }, 150);
    });
})();
</script>
<?php endif; ?>

<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
```

Note the final structure has one extra `<?php endif; ?>` compared to the original file: the outer guard (Step 1) opens once, the single-day inner block (`<?php if (!$isMultiDayMission): ?> ... <?php endif; ?>`) is self-contained, the new multi-day inner block (`<?php if ($isMultiDayMission): ?> ... <?php endif; ?>`) is self-contained, and then the original outer `<?php endif; ?>` closes the whole thing before the footer include. Count carefully: 1 outer `if` + 1 outer `endif`, 1 inner single-day `if` + `endif`, 1 inner multi-day `if` + `endif` = 3 `if`/`endif` pairs total in this region.

- [ ] **Step 3: Lint, sync, manual end-to-end verification**

```bash
C:\xampp\php\php.exe -l mission-view.php
```

Sync (PowerShell):
```
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

In the browser, verify the full feature end to end on the multi-day mission from Task 1:
1. The "Ημερήσιο Πρόγραμμα" card shows a map with the first day's markers/polyline, correct distance/duration badges, correct timeline list, and correct date label.
2. If Day 1 has an overnight note, it's shown; if not, the overnight box is hidden.
3. Click Day 2's tab — confirm the map redraws with Day 2's route (or falls back to the mission's location / a default Cretan map view if Day 2 has zero points), the tab list highlights Day 2, badges/timeline/overnight/date/navigation link all update to Day 2's data.
4. Click through all days including a day with zero points — confirm no console errors and a sensible empty state (map falls back per Step 2's `else` branches, timeline shows the "no points" message, no distance/duration badges).
5. Confirm no "Ride Replay" button appears anywhere on this page for the multi-day mission.
6. Open an ordinary single-day mission and confirm its map/timeline cards and (if the mission is `STATUS_COMPLETED` with a route) its Ride Replay button all behave exactly as before this plan.
7. Check the browser console for errors — expect none beyond any already-known, unrelated pre-existing issues (if any surface, verify via `git blame` that they predate this session's work before assuming they're a regression).

- [ ] **Step 4: Commit**

```bash
git add mission-view.php
git commit -m "Render active day's map, timeline, and badges on mission-view.php"
```

---

### Task 4: Manual sign-off, version bump, and release notes

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

- Multi-day mission itineraries (phase 2 of 3): the mission page now shows the day-by-day itinerary (route map, timeline, overnight notes) for multi-day rides via day tabs. Ride Mode/live-map day-awareness lands in a follow-up release (phase 3).
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
git commit -m "Bump version for multi-day mission itinerary (phase 2) release"
```

(Tagging and creating the GitHub release is a separate, explicit step the user triggers — not part of this implementation plan.)
