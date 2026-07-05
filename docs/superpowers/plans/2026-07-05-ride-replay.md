# Ride Replay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an in-app "Ride Replay" modal to `mission-view.php` that animates a marker along a completed mission's recorded route, with live distance/time counters and ride-event pins.

**Architecture:** No new backend endpoint, page, or database table. `mission-view.php` already loads everything needed (`$routeGeometry`, `$routeMetrics`, `$rideEvents`) — a new PHP helper annotates each ride event with its position along the route (0.0–1.0), the page serializes it all to one inline JSON block (same pattern already used for `routePoints`/`routeGeometry` at line ~2294), and a new vanilla-JS module (`assets/js/ride-replay.js`) drives a Leaflet map + `requestAnimationFrame` animation inside a Bootstrap modal.

**Tech Stack:** PHP 8.2 (no framework), MySQL, vanilla JS, Leaflet 1.9.4 (already loaded on this page), Bootstrap 5.3 modals (already used elsewhere in this file).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-05-ride-replay-design.md`
- No new DB tables, no new page/route, no public/unauthenticated sharing (future phase, out of scope here).
- Animation is a fixed 25-second playback regardless of the ride's real duration; marker position is linear interpolation over cumulative route distance (not real GPS speed).
- Entry point condition: `$mission['status'] === STATUS_COMPLETED` AND `count($routeGeometry) >= 2`.
- Follow existing file conventions: escape output with `h()`, cache-bust new JS asset with `?v=<?= APP_VERSION ?>`, reuse the severity→color map already defined at `mission-view.php:1154-1159`.
- No PHPUnit/JS test runner exists in this repo. Verify PHP logic with a throwaway CLI script run via `C:\xampp\php\php.exe`, deleted before committing (not part of the deployed app). Verify UI behavior manually in the browser (dev server via XAMPP, per project convention).

---

### Task 1: `rideEventRoutePositions()` helper

**Files:**
- Modify: `includes/ride-functions.php` (add function after `rideRouteGeometry()`, which ends at line 277)
- Test: throwaway script at repo root, `verify-ride-event-positions.php` (created, run, then deleted — not committed)

**Interfaces:**
- Consumes: `rideDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float` (already defined at `includes/ride-functions.php:125`).
- Produces: `rideEventRoutePositions(array $routeGeometry, array $events): array` — takes `$routeGeometry` as an array of `[lat, lng]` pairs (the shape `rideRouteGeometry()` returns) and `$events` as an array of associative arrays each with `lat`/`lng` keys (the shape `getRideEvents()` rows have). Returns the same `$events` array with an added `route_fraction` key per event: a float in `[0.0, 1.0]`, or `null` when the event has no lat/lng, or when `$routeGeometry` has fewer than 2 points, or when the route's total distance is 0.

- [ ] **Step 1: Write the failing verification script**

Create `verify-ride-event-positions.php` at the repo root:

```php
<?php
define('VOLUNTEEROPS', true);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/ride-functions.php';

function assertTrue(bool $cond, string $label): void {
    echo ($cond ? "PASS: " : "FAIL: ") . $label . "\n";
    if (!$cond) {
        $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + 1;
    }
}

$GLOBALS['failures'] = 0;

// Three collinear points, roughly evenly spaced (~11km per hop on a sphere,
// since 1 degree of latitude is ~111km regardless of longitude).
$route = [
    [37.9838, 23.7275],
    [38.0838, 23.7275],
    [38.1838, 23.7275],
];

// Event exactly at the middle vertex -> fraction ~0.5
$events = [
    ['lat' => 38.0838, 'lng' => 23.7275],
];
$result = rideEventRoutePositions($route, $events);
assertTrue($result[0]['route_fraction'] !== null, 'middle event has a non-null fraction');
assertTrue(abs($result[0]['route_fraction'] - 0.5) < 0.01, 'middle event fraction is ~0.5, got ' . $result[0]['route_fraction']);

// Event exactly at the first vertex -> fraction 0.0
$events = [['lat' => 37.9838, 'lng' => 23.7275]];
$result = rideEventRoutePositions($route, $events);
assertTrue($result[0]['route_fraction'] === 0.0, 'start event fraction is 0.0, got ' . var_export($result[0]['route_fraction'], true));

// Event with no lat/lng -> null fraction
$events = [['lat' => null, 'lng' => null]];
$result = rideEventRoutePositions($route, $events);
assertTrue($result[0]['route_fraction'] === null, 'event without coordinates gets null fraction');

// Route with fewer than 2 points -> every event gets null
$events = [['lat' => 37.9838, 'lng' => 23.7275]];
$result = rideEventRoutePositions([[37.9838, 23.7275]], $events);
assertTrue($result[0]['route_fraction'] === null, 'single-point route yields null fraction');

echo $GLOBALS['failures'] === 0 ? "\nALL PASS\n" : "\n{$GLOBALS['failures']} FAILURE(S)\n";
exit($GLOBALS['failures'] === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `C:\xampp\php\php.exe verify-ride-event-positions.php`
Expected: fatal error — `rideEventRoutePositions()` is undefined (function does not exist yet).

- [ ] **Step 3: Implement `rideEventRoutePositions()`**

In `includes/ride-functions.php`, add this function immediately after `rideRouteGeometry()` (after line 277):

```php
function rideEventRoutePositions(array $routeGeometry, array $events): array {
    $vertexCount = count($routeGeometry);

    if ($vertexCount < 2) {
        foreach ($events as &$event) {
            $event['route_fraction'] = null;
        }
        unset($event);
        return $events;
    }

    $cumulative = [0.0];
    for ($i = 1; $i < $vertexCount; $i++) {
        $cumulative[] = $cumulative[$i - 1] + rideDistanceMeters(
            (float)$routeGeometry[$i - 1][0],
            (float)$routeGeometry[$i - 1][1],
            (float)$routeGeometry[$i][0],
            (float)$routeGeometry[$i][1]
        );
    }
    $totalDistance = $cumulative[$vertexCount - 1];

    foreach ($events as &$event) {
        $lat = $event['lat'] ?? null;
        $lng = $event['lng'] ?? null;

        if ($lat === null || $lng === null || $totalDistance <= 0) {
            $event['route_fraction'] = null;
            continue;
        }

        $nearestIndex = 0;
        $nearestDistance = null;
        for ($i = 0; $i < $vertexCount; $i++) {
            $distance = rideDistanceMeters(
                (float)$lat,
                (float)$lng,
                (float)$routeGeometry[$i][0],
                (float)$routeGeometry[$i][1]
            );
            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestIndex = $i;
            }
        }

        $event['route_fraction'] = $cumulative[$nearestIndex] / $totalDistance;
    }
    unset($event);

    return $events;
}
```

- [ ] **Step 4: Run the verification script to confirm it passes**

Run: `C:\xampp\php\php.exe verify-ride-event-positions.php`
Expected:
```
PASS: middle event has a non-null fraction
PASS: middle event fraction is ~0.5, got 0.5...
PASS: start event fraction is 0.0, got 0.0
PASS: event without coordinates gets null fraction
PASS: single-point route yields null fraction

ALL PASS
```

- [ ] **Step 5: Delete the throwaway script and commit**

```bash
rm verify-ride-event-positions.php
C:\xampp\php\php.exe -l includes/ride-functions.php
git add includes/ride-functions.php
git commit -m "Add rideEventRoutePositions() helper for Ride Replay"
```

---

### Task 2: Ride Replay button, data payload, and modal shell in `mission-view.php`

**Files:**
- Modify: `mission-view.php` (button in the map card header, ~lines 1060-1075; modal markup near the other modals, e.g. after the `deleteMissionModal` block which ends around line 2053; inline JSON payload near the existing Leaflet inline script block starting at line 2286)

**Interfaces:**
- Consumes: `$routeGeometry` (from `mission-view.php:33`), `$routeMetrics` (from `mission-view.php:32`), `$rideEvents` (from `mission-view.php:109`, already gated by `$canViewRideEvents`), `rideEventRoutePositions()` (from Task 1), `STATUS_COMPLETED` (from `config.php`).
- Produces: a global JS object `window.easyRideReplayData = { points, distanceMeters, distanceLabel, totalMinutes, totalLabel, events }` available to Task 3's `ride-replay.js`. `points` is `$routeGeometry` as-is (array of `[lat,lng]`). `events` is an array of `{ lat, lng, title, severity, routeFraction }` filtered to only events with a non-null `route_fraction`. The modal DOM provides: `#rideReplayModal` (the modal), `#rideReplayMap` (map container div), `#rideReplayPlayPause` (button), `#rideReplayScrubber` (range input), `#rideReplayRestart` (button), `#rideReplayKm` and `#rideReplayTime` (live counter spans).

- [ ] **Step 1: Add the button**

In `mission-view.php`, inside the map card's button group (the `<div class="d-flex gap-1">` block spanning lines 1060-1075), add this button right after the closing `<?php endif; ?>` of the Google Maps link block (after line 1074, before line 1075's closing `</div>`):

```php
                <?php if ($mission['status'] === STATUS_COMPLETED && count($routeGeometry) >= 2): ?>
                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size:.75rem"
                        data-bs-toggle="modal" data-bs-target="#rideReplayModal">
                    <i class="bi bi-film me-1"></i>Ride Replay
                </button>
                <?php endif; ?>
```

- [ ] **Step 2: Build the replay data payload (PHP)**

`$routeGeometry` is set at line 33 (`$routeGeometry = rideRouteGeometry($mission);`) but `$rideEvents` isn't defined until line 109 (`$rideEvents = $canViewRideEvents ? getRideEvents($id, 40, false) : [];`) — since this code needs both, add it directly **after line 109**:

```php
$rideReplayEvents = [];
if ($mission['status'] === STATUS_COMPLETED && count($routeGeometry) >= 2) {
    $eventsWithFractions = rideEventRoutePositions($routeGeometry, $rideEvents ?? []);
    foreach ($eventsWithFractions as $event) {
        if ($event['route_fraction'] === null) {
            continue;
        }
        $rideReplayEvents[] = [
            'lat' => (float)$event['lat'],
            'lng' => (float)$event['lng'],
            'title' => (string)$event['title'],
            'severity' => (string)($event['severity'] ?? 'info'),
            'routeFraction' => (float)$event['route_fraction'],
        ];
    }
}
```

- [ ] **Step 3: Add the modal markup**

Add this modal block in `mission-view.php` right after the `deleteMissionModal` block closes (after its closing `</div>` that ends that modal, following the pattern of the other modals):

```php
<div class="modal fade" id="rideReplayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-film me-1"></i>Ride Replay</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="rideReplayMap" style="height:360px;"></div>
                <div class="p-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <span id="rideReplayKm" class="fw-bold">0 m</span>
                            <span class="text-muted mx-1">&middot;</span>
                            <span id="rideReplayTime" class="fw-bold">0 λεπτά</span>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" id="rideReplayRestart" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                            <button type="button" id="rideReplayPlayPause" class="btn btn-sm btn-primary">
                                <i class="bi bi-play-fill"></i>
                            </button>
                        </div>
                    </div>
                    <input type="range" id="rideReplayScrubber" class="form-range" min="0" max="1" step="0.001" value="0">
                </div>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Emit the JSON payload and include the JS module**

In `mission-view.php`, inside the existing Leaflet inline-script conditional block (starts at line 2286: `<?php if ((!empty($mission['latitude']) ...) || !empty($routePoints)): ?>`), right after the existing `var routeGeometry = <?= json_encode($routeGeometry) ?>;` line (2295), add:

```php
    window.easyRideReplayData = {
        points: routeGeometry,
        distanceMeters: <?= json_encode((float)($routeMetrics['distance_meters'] ?? 0)) ?>,
        distanceLabel: <?= json_encode((string)($routeMetrics['distance_label'] ?? '')) ?>,
        totalMinutes: <?= json_encode((int)($routeMetrics['total_minutes'] ?? 0)) ?>,
        totalLabel: <?= json_encode((string)($routeMetrics['total_label'] ?? '')) ?>,
        events: <?= json_encode($rideReplayEvents, JSON_UNESCAPED_UNICODE) ?>
    };
```

Then, immediately after the `<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>` line (2288), add the new module script tag (Task 3 creates this file — including it now is safe, the browser just won't find `initRideReplay` until Task 3 lands, and nothing calls it yet):

```php
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ride-replay.js?v=<?= APP_VERSION ?>"></script>
```

- [ ] **Step 5: Manual verification (shell only — no JS yet)**

1. Sync to XAMPP: `robocopy C:\Users\user\Desktop\easyride C:\xampp\htdocs\easyride /MIR /XD .git backups exports uploads /XF config.local.php update.log` (run via PowerShell, not the bash tool, so `/MIR` isn't mis-parsed).
2. Lint: `C:\xampp\php\php.exe -l mission-view.php`
3. In the browser, open a mission with `status = STATUS_COMPLETED` and a recorded route (≥2 points in `route_geometry`). Confirm the "Ride Replay" button appears in the map card header and clicking it opens the modal showing an empty map area (no animation yet — that's Task 3) with no console errors about the JSON payload itself (a 404 or "initRideReplay is not defined" for the not-yet-created JS file is expected and fine at this step).

- [ ] **Step 6: Commit**

```bash
git add mission-view.php
git commit -m "Add Ride Replay button, data payload, and modal shell"
```

---

### Task 3: `ride-replay.js` — animated map, controls, and cleanup

**Files:**
- Create: `assets/js/ride-replay.js`

**Interfaces:**
- Consumes: `window.easyRideReplayData` (produced by Task 2, shape documented there), the global Leaflet `L` object (already loaded on the page before this script), and the DOM ids from Task 2's modal (`#rideReplayModal`, `#rideReplayMap`, `#rideReplayPlayPause`, `#rideReplayScrubber`, `#rideReplayRestart`, `#rideReplayKm`, `#rideReplayTime`).
- Produces: nothing consumed by later tasks — this is the last task in the plan.

- [ ] **Step 1: Write the module**

Create `assets/js/ride-replay.js`:

```javascript
(function () {
    var DURATION_MS = 25000;

    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('rideReplayModal');
        var data = window.easyRideReplayData;
        if (!modalEl || !data || !Array.isArray(data.points) || data.points.length < 2) {
            return;
        }

        var map = null;
        var marker = null;
        var eventMarkers = [];
        var rafId = null;
        var playing = false;
        var elapsedMs = 0;
        var lastFrameTime = null;

        var cumulative = [0];
        for (var i = 1; i < data.points.length; i++) {
            cumulative.push(cumulative[i - 1] + haversineMeters(data.points[i - 1], data.points[i]));
        }
        var totalDistance = cumulative[cumulative.length - 1];

        var severityColor = {
            danger: '#dc3545',
            warning: '#f59e0b',
            secondary: '#64748b',
            info: '#0d6efd'
        };

        function haversineMeters(a, b) {
            var R = 6371000;
            var lat1 = a[0] * Math.PI / 180;
            var lat2 = b[0] * Math.PI / 180;
            var dLat = (b[0] - a[0]) * Math.PI / 180;
            var dLng = (b[1] - a[1]) * Math.PI / 180;
            var sinA = Math.sin(dLat / 2) * Math.sin(dLat / 2)
                + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return R * 2 * Math.atan2(Math.sqrt(sinA), Math.sqrt(1 - sinA));
        }

        function positionAtFraction(fraction) {
            var targetDistance = fraction * totalDistance;
            for (var i = 0; i < cumulative.length - 1; i++) {
                if (targetDistance <= cumulative[i + 1] || i === cumulative.length - 2) {
                    var segStart = cumulative[i];
                    var segEnd = cumulative[i + 1];
                    var segFraction = segEnd > segStart ? (targetDistance - segStart) / (segEnd - segStart) : 0;
                    segFraction = Math.max(0, Math.min(1, segFraction));
                    var a = data.points[i];
                    var b = data.points[i + 1];
                    return [
                        a[0] + (b[0] - a[0]) * segFraction,
                        a[1] + (b[1] - a[1]) * segFraction
                    ];
                }
            }
            return data.points[data.points.length - 1];
        }

        function formatKm(meters) {
            return meters >= 1000
                ? (meters / 1000).toFixed(1).replace('.', ',') + ' km'
                : Math.round(meters) + ' m';
        }

        function formatMinutes(minutes) {
            var rounded = Math.round(minutes);
            var hours = Math.floor(rounded / 60);
            var mins = rounded % 60;
            return hours > 0 ? (hours + 'ω ' + mins + 'λ') : (mins + ' λεπτά');
        }

        function updateFrame(fraction) {
            var pos = positionAtFraction(fraction);
            marker.setLatLng(pos);

            document.getElementById('rideReplayKm').textContent = formatKm(fraction * data.distanceMeters);
            document.getElementById('rideReplayTime').textContent = formatMinutes(fraction * data.totalMinutes);
            document.getElementById('rideReplayScrubber').value = fraction;

            eventMarkers.forEach(function (entry) {
                var shouldHighlight = fraction >= entry.routeFraction;
                if (shouldHighlight !== entry.highlighted) {
                    entry.highlighted = shouldHighlight;
                    entry.marker.setStyle({
                        radius: shouldHighlight ? 7 : 5,
                        fillOpacity: shouldHighlight ? 1 : 0.4,
                        opacity: shouldHighlight ? 1 : 0.4
                    });
                }
            });
        }

        function step(timestamp) {
            if (lastFrameTime === null) {
                lastFrameTime = timestamp;
            }
            elapsedMs += timestamp - lastFrameTime;
            lastFrameTime = timestamp;

            var fraction = Math.min(1, elapsedMs / DURATION_MS);
            updateFrame(fraction);

            if (fraction >= 1) {
                setPlaying(false);
                return;
            }
            if (playing) {
                rafId = requestAnimationFrame(step);
            }
        }

        function setPlaying(next) {
            playing = next;
            var icon = document.querySelector('#rideReplayPlayPause i');
            icon.className = playing ? 'bi bi-pause-fill' : 'bi bi-play-fill';
            if (playing) {
                lastFrameTime = null;
                rafId = requestAnimationFrame(step);
            } else if (rafId !== null) {
                cancelAnimationFrame(rafId);
                rafId = null;
            }
        }

        function initMap() {
            map = L.map('rideReplayMap', { zoomControl: true, scrollWheelZoom: false });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19
            }).addTo(map);

            L.polyline(data.points, { color: '#0d6efd', weight: 4, opacity: 0.4 }).addTo(map);
            map.fitBounds(L.latLngBounds(data.points), { padding: [20, 20] });

            (data.events || []).forEach(function (event) {
                var color = severityColor[event.severity] || severityColor.info;
                var circleMarker = L.circleMarker([event.lat, event.lng], {
                    radius: 5,
                    color: color,
                    fillColor: color,
                    fillOpacity: 0.4,
                    opacity: 0.4,
                    weight: 2
                }).addTo(map).bindPopup(event.title);
                eventMarkers.push({ marker: circleMarker, routeFraction: event.routeFraction, highlighted: false });
            });

            marker = L.circleMarker(data.points[0], {
                radius: 8,
                color: '#198754',
                fillColor: '#198754',
                fillOpacity: 1,
                weight: 2
            }).addTo(map);

            setTimeout(function () { map.invalidateSize(); }, 150);
        }

        function reset() {
            elapsedMs = 0;
            lastFrameTime = null;
            eventMarkers.forEach(function (entry) {
                entry.highlighted = false;
                entry.marker.setStyle({ radius: 5, fillOpacity: 0.4, opacity: 0.4 });
            });
            updateFrame(0);
        }

        modalEl.addEventListener('shown.bs.modal', function () {
            if (!map) {
                initMap();
                reset();
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            setPlaying(false);
            if (map) {
                map.remove();
                map = null;
                marker = null;
                eventMarkers = [];
            }
        });

        document.getElementById('rideReplayPlayPause').addEventListener('click', function () {
            if (elapsedMs / DURATION_MS >= 1) {
                reset();
            }
            setPlaying(!playing);
        });

        document.getElementById('rideReplayRestart').addEventListener('click', function () {
            setPlaying(false);
            reset();
        });

        document.getElementById('rideReplayScrubber').addEventListener('input', function (e) {
            setPlaying(false);
            elapsedMs = parseFloat(e.target.value) * DURATION_MS;
            updateFrame(parseFloat(e.target.value));
        });
    });
})();
```

- [ ] **Step 2: Sync and lint**

```bash
robocopy C:\Users\user\Desktop\easyride C:\xampp\htdocs\easyride /MIR /XD .git backups exports uploads /XF config.local.php update.log
```
(Run via PowerShell so `/MIR` is not mis-parsed by a POSIX shell.)

There's no JS linter configured in this repo, so skip lint for this file; proceed straight to manual verification.

- [ ] **Step 3: Manual browser verification**

Open the same completed mission from Task 2, Step 5, in the browser:
1. Click "Ride Replay" — modal opens, map renders with the full dimmed route and any event pins (muted color), and a green marker at the route start. No grey-tile rendering glitch.
2. Click play (▶) — marker moves smoothly along the route over ~25 seconds; the km/time counters increase from 0 to the mission's full distance/duration; any event pins brighten as the marker passes their position.
3. Drag the scrubber — marker jumps to the corresponding position and counters update immediately; playback pauses.
4. Click restart — marker returns to the start, counters reset to 0, event pins mute again.
5. Let it play to completion — play button reverts to the ▶ icon at the end.
6. Close the modal mid-playback, then reopen it — animation restarts cleanly from 0 (no leftover timers, no duplicate map instances, no console errors).
7. Open a mission that has ride events at multiple `route_fraction` positions (or use a mission with none) to confirm both cases render without errors.

- [ ] **Step 4: Commit**

```bash
git add assets/js/ride-replay.js
git commit -m "Add Ride Replay animation module"
```

---

### Task 4: Version bump and release notes

**Files:**
- Modify: `config.php` (`APP_VERSION`)
- Modify: `README.md` (version badge/line + new "Current Release Highlights" entry)

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing consumed by other tasks — this is bookkeeping to close out the feature per this project's release workflow.

- [ ] **Step 1: Check for an existing tag at the current version**

```bash
gh release list --limit 5
```

If the current `APP_VERSION` (in `config.php`) already has a matching `vX.Y.Z` tag, bump to the next patch version below. If not, use the current `APP_VERSION` as-is (no bump needed).

- [ ] **Step 2: Bump `APP_VERSION` if needed**

In `config.php`, update the `define('APP_VERSION', '...')` line to the next patch version (e.g. `3.81.1` → `3.81.2`), following the exact pattern already in that file.

- [ ] **Step 3: Add a README highlight entry**

In `README.md`, under `## Current Release Highlights`, add a new `### vX.Y.Z` section above the most recent one:

```markdown
### vX.Y.Z

- Ride Replay: animated in-app recap of a completed ride's route, with live distance/time counters and ride-event markers
```

Also update the `**Version:**` line and the `![Version](...)` badge near the top of `README.md` to match, following the exact pattern used for the previous version bump.

- [ ] **Step 4: Lint, sync, commit**

```bash
C:\xampp\php\php.exe -l config.php
robocopy C:\Users\user\Desktop\easyride C:\xampp\htdocs\easyride /MIR /XD .git backups exports uploads /XF config.local.php update.log
git add config.php README.md
git commit -m "Bump version for Ride Replay release"
```

(Tagging and creating the GitHub release is a separate, explicit step the user triggers at the end of the session per the existing release workflow — not part of this implementation plan.)
