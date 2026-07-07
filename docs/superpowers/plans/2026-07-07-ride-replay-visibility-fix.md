# Ride Replay Visibility Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the "Ride Replay" button/animation actually appear for completed missions — falling back to raw waypoints when there's no Google-snapped route, and adding a per-day Replay for multi-day missions (which currently can never show it at all).

**Architecture:** A new PHP helper picks the best available point set (Google geometry, else raw waypoints). `ride-replay.js` is refactored from a single hardcoded instance into a reusable controller factory so the same animation code drives both the existing single-mission modal and a new per-day modal. No schema or data-storage changes — only what's read and how it's displayed.

**Tech Stack:** PHP 8.2, vanilla JS, Leaflet.js (already a dependency), Bootstrap 5 modals.

## Global Constraints

- No automated test framework — verify via `php -l` + manual/CLI-based rendering checks against the real DB, per project convention.
- No database schema changes.
- The existing single-mission Ride Replay behavior must not change for missions that already have Google-snapped `route_geometry` — the fallback is additive, not a replacement.
- Working directly on `main`, no feature branch (established project convention this session).

---

### Task 1: `rideReplayPoints()` helper + reusable `ride-replay.js` controller

**Files:**
- Modify: `includes/ride-functions.php` (add function after `rideRouteGeometry()`, which ends at line 277)
- Modify: `assets/js/ride-replay.js` (full rewrite)

**Interfaces:**
- Produces: `rideReplayPoints(array $geometry, array $points): array` — PHP function. Returns `$geometry` if it has ≥2 pairs; otherwise converts `$points` (each `['lat' => float, 'lng' => float, ...]`) to plain `[lat, lng]` pairs and returns those if ≥2 exist; otherwise `[]`.
- Produces: `window.initRideReplayController(options)` — JS global function. `options = { modalEl: HTMLElement, elIds: {map, km, time, scrubber, playPause, restart}, getData: function(): {points, distanceMeters, totalMinutes, events}|null }`. Wires up a Leaflet-map-driven replay animation scoped to `modalEl`, calling `getData()` fresh every time the modal's `shown.bs.modal` event fires (so callers can change what data it shows between opens by updating whatever `getData` reads).
- Consumes (Task 2/3): Task 2 and 3 call `rideReplayPoints()` and `window.initRideReplayController()` by these exact names/shapes.

- [ ] **Step 1: Add `rideReplayPoints()` to `includes/ride-functions.php`**

Insert this immediately after the closing `}` of `rideRouteGeometry()` (line 277), before the blank line at 278:

```php

function rideReplayPoints(array $geometry, array $points): array {
    if (count($geometry) >= 2) {
        return $geometry;
    }
    $pairs = array_map(fn($p) => [(float)$p['lat'], (float)$p['lng']], $points);
    return count($pairs) >= 2 ? $pairs : [];
}
```

- [ ] **Step 2: Lint**

Run: `C:\xampp\php\php.exe -l includes/ride-functions.php`
Expected: `No syntax errors detected in includes/ride-functions.php`

- [ ] **Step 3: Verify the helper with a throwaway CLI script**

```bash
cat > /tmp/test_replay_points.php << 'EOF'
<?php
require_once 'C:/Users/user/Desktop/easyride/bootstrap.php';
var_dump(rideReplayPoints([], []));
var_dump(rideReplayPoints([], [['lat' => 1.0, 'lng' => 2.0]]));
var_dump(rideReplayPoints([], [['lat' => 1.0, 'lng' => 2.0], ['lat' => 3.0, 'lng' => 4.0]]));
var_dump(rideReplayPoints([[10.0, 20.0], [30.0, 40.0]], [['lat' => 1.0, 'lng' => 2.0], ['lat' => 3.0, 'lng' => 4.0]]));
EOF
C:\xampp\php\php.exe /tmp/test_replay_points.php
rm /tmp/test_replay_points.php
```

Expected: empty array; empty array (only 1 point); `[[1.0, 2.0], [3.0, 4.0]]` (falls back to points); `[[10.0, 20.0], [30.0, 40.0]]` (geometry wins when both present).

- [ ] **Step 4: Rewrite `assets/js/ride-replay.js`**

Replace the entire file content with:

```js
function initRideReplayController(options) {
    var modalEl = options.modalEl;
    var elIds = options.elIds;
    var getData = options.getData;
    if (!modalEl) return;

    var DURATION_MS = 25000;
    var map = null;
    var marker = null;
    var eventMarkers = [];
    var rafId = null;
    var playing = false;
    var elapsedMs = 0;
    var lastFrameTime = null;
    var data = null;
    var cumulative = [];
    var totalDistance = 0;

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

    function computeCumulative() {
        cumulative = [0];
        for (var i = 1; i < data.points.length; i++) {
            cumulative.push(cumulative[i - 1] + haversineMeters(data.points[i - 1], data.points[i]));
        }
        totalDistance = cumulative[cumulative.length - 1];
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

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[ch];
        });
    }

    function updateFrame(fraction) {
        var pos = positionAtFraction(fraction);
        marker.setLatLng(pos);

        document.getElementById(elIds.km).textContent = formatKm(fraction * data.distanceMeters);
        document.getElementById(elIds.time).textContent = formatMinutes(fraction * data.totalMinutes);
        document.getElementById(elIds.scrubber).value = fraction;

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
        var icon = document.querySelector('#' + elIds.playPause + ' i');
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
        map = L.map(elIds.map, { zoomControl: true, scrollWheelZoom: false });
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
            }).addTo(map).bindPopup(esc(event.title));
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
        data = getData();
        if (!data || !Array.isArray(data.points) || data.points.length < 2) {
            return;
        }
        computeCumulative();
        eventMarkers = [];
        if (!map) {
            initMap();
        }
        reset();
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

    document.getElementById(elIds.playPause).addEventListener('click', function () {
        if (!data) return;
        if (elapsedMs / DURATION_MS >= 1) {
            reset();
        }
        setPlaying(!playing);
    });

    document.getElementById(elIds.restart).addEventListener('click', function () {
        if (!data) return;
        setPlaying(false);
        reset();
    });

    document.getElementById(elIds.scrubber).addEventListener('input', function (e) {
        if (!data) return;
        setPlaying(false);
        elapsedMs = parseFloat(e.target.value) * DURATION_MS;
        updateFrame(parseFloat(e.target.value));
    });
}

window.initRideReplayController = initRideReplayController;

document.addEventListener('DOMContentLoaded', function () {
    var singleModal = document.getElementById('rideReplayModal');
    if (singleModal) {
        initRideReplayController({
            modalEl: singleModal,
            elIds: {
                map: 'rideReplayMap',
                km: 'rideReplayKm',
                time: 'rideReplayTime',
                scrubber: 'rideReplayScrubber',
                playPause: 'rideReplayPlayPause',
                restart: 'rideReplayRestart'
            },
            getData: function () { return window.easyRideReplayData || null; }
        });
    }
});
```

Note: this preserves the exact behavior of the original file for the single-mission modal (same DOM ids, same animation math, same `shown.bs.modal`/`hidden.bs.modal` lifecycle) — the only functional change is that `data` and `cumulative`/`totalDistance` are now computed fresh on every `shown.bs.modal` (via `getData()`) instead of once at page load, which makes no observable difference when `getData()` always returns the same `window.easyRideReplayData` object, as the single-mission case does.

- [ ] **Step 5: Commit**

```bash
git add includes/ride-functions.php assets/js/ride-replay.js
git commit -m "Add rideReplayPoints fallback helper and reusable ride-replay controller"
```

---

### Task 2: Single-day fallback wiring in `mission-view.php`

**Files:**
- Modify: `mission-view.php:31-33` (add `$replayPoints` computation)
- Modify: `mission-view.php:126-141` (`$rideReplayEvents` block — use `$replayPoints`, extract a reusable `buildReplayEvents()` function for Task 3 to reuse)
- Modify: `mission-view.php:1108` (button condition)
- Modify: `mission-view.php:2397-2405` (JS: add `replayPoints` var, use it in `window.easyRideReplayData.points`)

**Interfaces:**
- Consumes: `rideReplayPoints()` from Task 1.
- Produces: `$replayPoints` (PHP array, `[lat,lng]` pairs) and `buildReplayEvents(array $routePoints, array $events): array` (PHP function) — Task 3 reuses `buildReplayEvents()` by this exact name.

- [ ] **Step 1: Add `$replayPoints` right after the existing route variables**

Replace (lines 31-33):
```php
$routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
$routeMetrics = rideMissionRouteMetrics($mission, $routePoints);
$routeGeometry = rideRouteGeometry($mission);
```
with:
```php
$routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
$routeMetrics = rideMissionRouteMetrics($mission, $routePoints);
$routeGeometry = rideRouteGeometry($mission);
$replayPoints = rideReplayPoints($routeGeometry, $routePoints);
```

- [ ] **Step 2: Extract `buildReplayEvents()` and use `$replayPoints` for the mission-level replay events**

Replace (lines 126-141):
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
with:
```php
function buildReplayEvents(array $routePoints, array $events): array {
    $replayEvents = [];
    $eventsWithFractions = rideEventRoutePositions($routePoints, $events);
    foreach ($eventsWithFractions as $event) {
        if ($event['route_fraction'] === null) {
            continue;
        }
        $replayEvents[] = [
            'lat' => (float)$event['lat'],
            'lng' => (float)$event['lng'],
            'title' => (string)$event['title'],
            'severity' => (string)($event['severity'] ?? 'info'),
            'routeFraction' => (float)$event['route_fraction'],
        ];
    }
    return $replayEvents;
}

$rideReplayEvents = [];
if ($mission['status'] === STATUS_COMPLETED && count($replayPoints) >= 2) {
    $rideReplayEvents = buildReplayEvents($replayPoints, $rideEvents ?? []);
}
```

- [ ] **Step 3: Update the button's visibility condition**

Replace (line 1108):
```php
<?php if ($mission['status'] === STATUS_COMPLETED && count($routeGeometry) >= 2): ?>
```
with:
```php
<?php if ($mission['status'] === STATUS_COMPLETED && count($replayPoints) >= 2): ?>
```
(The rest of that block — the button and its `<?php endif; ?>` — is unchanged.)

- [ ] **Step 4: Feed `$replayPoints` into the JS replay data object**

Replace (in the single-mission script block):
```php
    var routePoints = <?= json_encode($routePoints, JSON_UNESCAPED_UNICODE) ?>;
    var routeGeometry = <?= json_encode($routeGeometry) ?>;
    window.easyRideReplayData = {
        points: routeGeometry,
        distanceMeters: <?= json_encode((float)($routeMetrics['distance_meters'] ?? 0)) ?>,
        distanceLabel: <?= json_encode((string)($routeMetrics['distance_label'] ?? '')) ?>,
        totalMinutes: <?= json_encode((int)($routeMetrics['total_minutes'] ?? 0)) ?>,
        totalLabel: <?= json_encode((string)($routeMetrics['total_label'] ?? '')) ?>,
        events: <?= json_encode($rideReplayEvents, JSON_UNESCAPED_UNICODE) ?>
    };
```
with:
```php
    var routePoints = <?= json_encode($routePoints, JSON_UNESCAPED_UNICODE) ?>;
    var routeGeometry = <?= json_encode($routeGeometry) ?>;
    var replayPoints = <?= json_encode($replayPoints) ?>;
    window.easyRideReplayData = {
        points: replayPoints,
        distanceMeters: <?= json_encode((float)($routeMetrics['distance_meters'] ?? 0)) ?>,
        distanceLabel: <?= json_encode((string)($routeMetrics['distance_label'] ?? '')) ?>,
        totalMinutes: <?= json_encode((int)($routeMetrics['total_minutes'] ?? 0)) ?>,
        totalLabel: <?= json_encode((string)($routeMetrics['total_label'] ?? '')) ?>,
        events: <?= json_encode($rideReplayEvents, JSON_UNESCAPED_UNICODE) ?>
    };
```
(`routeGeometry` stays defined and used elsewhere in the same script for the static on-page map's polyline — do not remove it.)

- [ ] **Step 5: Lint**

Run: `C:\xampp\php\php.exe -l mission-view.php`
Expected: `No syntax errors detected in mission-view.php`

- [ ] **Step 6: Verify against mission #110 via a CLI script**

```bash
cat > /tmp/test_mission110_replay.php << 'EOF'
<?php
require_once 'C:/Users/user/Desktop/easyride/bootstrap.php';
$mission = dbFetchOne("SELECT * FROM missions WHERE id = 110");
$routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
$routeGeometry = rideRouteGeometry($mission);
$replayPoints = rideReplayPoints($routeGeometry, $routePoints);
echo "routeGeometry count: " . count($routeGeometry) . "\n";
echo "routePoints count: " . count($routePoints) . "\n";
echo "replayPoints count (expect >= 2, using points fallback): " . count($replayPoints) . "\n";
EOF
C:\xampp\php\php.exe /tmp/test_mission110_replay.php
rm /tmp/test_mission110_replay.php
```

Expected: `routeGeometry count: 0`, `routePoints count` > 0, `replayPoints count` equal to `routePoints count` and ≥ 2.

- [ ] **Step 7: Manual browser check**

Navigate to `mission-view.php?id=110` — confirm the "Ride Replay" button now appears next to "Ride Mode"/"Ride Report"/"Επεξεργασία", and clicking it opens the modal with a marker animating in straight lines between the mission's waypoints.

- [ ] **Step 8: Commit**

```bash
git add mission-view.php
git commit -m "Fall back to raw route points for Ride Replay when no Google geometry exists"
```

---

### Task 3: Per-day Ride Replay for multi-day missions

**Files:**
- Modify: `mission-view.php` (per-day PHP data prep, day-tabs button, new modal HTML, day-array JS, `renderDay()`, `DOMContentLoaded` init)

**Interfaces:**
- Consumes: `rideReplayPoints()` (Task 1), `buildReplayEvents()` (Task 2), `window.initRideReplayController()` (Task 1).

- [ ] **Step 1: Add `replay_points` to the per-day PHP data**

Replace (the per-day loop that currently reads):
```php
foreach ($missionDays as &$day) {
    $day['route_points_decoded'] = normalizeRideRoutePoints($day['route_points'] ?? '[]');
    $day['metrics'] = rideMissionRouteMetrics($day, $day['route_points_decoded']);
    $day['geometry'] = rideRouteGeometry($day);
    $day['date_label'] = formatDateGreek($day['day_date']);
}
unset($day);
```
with:
```php
foreach ($missionDays as &$day) {
    $day['route_points_decoded'] = normalizeRideRoutePoints($day['route_points'] ?? '[]');
    $day['metrics'] = rideMissionRouteMetrics($day, $day['route_points_decoded']);
    $day['geometry'] = rideRouteGeometry($day);
    $day['replay_points'] = rideReplayPoints($day['geometry'], $day['route_points_decoded']);
    $day['date_label'] = formatDateGreek($day['day_date']);
}
unset($day);
```

- [ ] **Step 2: Add per-day replay events, right after the mission-level `$rideReplayEvents` block from Task 2**

Add this new block immediately after the `$rideReplayEvents = [...]` code from Task 2 (i.e., right after its closing `}`):
```php

foreach ($missionDays as &$day) {
    $day['replay_events'] = [];
    if ($mission['status'] === STATUS_COMPLETED && count($day['replay_points']) >= 2) {
        $dayEvents = array_values(array_filter($rideEvents ?? [], function ($event) use ($day) {
            return substr((string)$event['created_at'], 0, 10) === $day['day_date'];
        }));
        $day['replay_events'] = buildReplayEvents($day['replay_points'], $dayEvents);
    }
}
unset($day);
```

- [ ] **Step 3: Add the per-day Replay button next to the day-tabs "Πλοήγηση" button**

Replace:
```php
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center small">
                <span id="dayViewDate" class="text-muted"></span>
                <a href="#" id="dayViewNavBtn" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem;display:none;">
                    <i class="bi bi-sign-turn-right me-1"></i>Πλοήγηση
                </a>
            </div>
```
with:
```php
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center small">
                <span id="dayViewDate" class="text-muted"></span>
                <div class="d-flex gap-2">
                    <button type="button" id="dayViewReplayBtn" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem;display:none;"
                            data-bs-toggle="modal" data-bs-target="#dayReplayModal">
                        <i class="bi bi-film me-1"></i>Ride Replay
                    </button>
                    <a href="#" id="dayViewNavBtn" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem;display:none;">
                        <i class="bi bi-sign-turn-right me-1"></i>Πλοήγηση
                    </a>
                </div>
            </div>
```

- [ ] **Step 4: Add the per-day Replay modal HTML, right after the existing `#rideReplayModal` block**

Insert this immediately after the closing `</div>` of the `#rideReplayModal` block (the `</div>` that closes it, followed currently by a blank line then `<!-- Apply Modal (for members) -->`):
```php

<?php if ($isMultiDayMission): ?>
<div class="modal fade" id="dayReplayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-film me-1"></i>Ride Replay — <span id="dayReplayDayLabel"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="dayReplayMap" style="height:360px;"></div>
                <div class="p-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <span id="dayReplayKm" class="fw-bold">0 m</span>
                            <span class="text-muted mx-1">&middot;</span>
                            <span id="dayReplayTime" class="fw-bold">0 λεπτά</span>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" id="dayReplayRestart" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                            <button type="button" id="dayReplayPlayPause" class="btn btn-sm btn-primary">
                                <i class="bi bi-play-fill"></i>
                            </button>
                        </div>
                    </div>
                    <input type="range" id="dayReplayScrubber" class="form-range" min="0" max="1" step="0.001" value="0">
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
```

- [ ] **Step 5: Move the `ride-replay.js` script include out of the single-mission-only block**

Replace:
```php
<?php if ((!$isMultiDayMission && ((!empty($mission['latitude']) && !empty($mission['longitude'])) || !empty($routePoints))) || $isMultiDayMission): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php if (!$isMultiDayMission): ?>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ride-replay.js?v=<?= APP_VERSION ?>"></script>
<script>
```
with:
```php
<?php if ((!$isMultiDayMission && ((!empty($mission['latitude']) && !empty($mission['longitude'])) || !empty($routePoints))) || $isMultiDayMission): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ride-replay.js?v=<?= APP_VERSION ?>"></script>
<?php if (!$isMultiDayMission): ?>
<script>
```
(Only the `ride-replay.js` line moved up one level; everything else in this region is unchanged, including the `<?php if (!$isMultiDayMission): ?>` that now wraps only the inline `<script>` block that follows.)

- [ ] **Step 6: Add `replay` data to the multi-day `days` JS array**

Replace:
```php
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
```
with:
```php
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
            'replay' => [
                'points' => $day['replay_points'],
                'distanceMeters' => (float)($day['metrics']['distance_meters'] ?? 0),
                'totalMinutes' => (int)($day['metrics']['total_minutes'] ?? 0),
                'events' => $day['replay_events'],
            ],
        ];
    }, $missionDays), JSON_UNESCAPED_UNICODE) ?>;
```

- [ ] **Step 7: Update `renderDay()` to show/hide the per-day Replay button and set its data**

Replace:
```js
        var navBtn = document.getElementById('dayViewNavBtn');
        if (day.directions_url) {
            navBtn.href = day.directions_url;
            navBtn.style.display = '';
        } else {
            navBtn.style.display = 'none';
        }

        document.getElementById('dayViewMetrics').innerHTML = formatMetricsHtml(day);
```
with:
```js
        var navBtn = document.getElementById('dayViewNavBtn');
        if (day.directions_url) {
            navBtn.href = day.directions_url;
            navBtn.style.display = '';
        } else {
            navBtn.style.display = 'none';
        }

        var replayBtn = document.getElementById('dayViewReplayBtn');
        if (day.replay.points.length >= 2) {
            window.easyDayReplayData = {
                points: day.replay.points,
                distanceMeters: day.replay.distanceMeters,
                totalMinutes: day.replay.totalMinutes,
                events: day.replay.events
            };
            document.getElementById('dayReplayDayLabel').textContent = day.date_label;
            replayBtn.style.display = '';
        } else {
            window.easyDayReplayData = null;
            replayBtn.style.display = 'none';
        }

        document.getElementById('dayViewMetrics').innerHTML = formatMetricsHtml(day);
```

- [ ] **Step 8: Initialize the day-replay controller once, in the existing `DOMContentLoaded` handler**

Replace:
```js
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
```
with:
```js
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

        var dayReplayModal = document.getElementById('dayReplayModal');
        if (dayReplayModal && window.initRideReplayController) {
            window.initRideReplayController({
                modalEl: dayReplayModal,
                elIds: {
                    map: 'dayReplayMap',
                    km: 'dayReplayKm',
                    time: 'dayReplayTime',
                    scrubber: 'dayReplayScrubber',
                    playPause: 'dayReplayPlayPause',
                    restart: 'dayReplayRestart'
                },
                getData: function () { return window.easyDayReplayData || null; }
            });
        }

        if (days.length) {
            renderDay(days[0].day_number);
        }
        setTimeout(function() { map.invalidateSize(); }, 150);
    });
```

- [ ] **Step 9: Lint**

Run: `C:\xampp\php\php.exe -l mission-view.php`
Expected: `No syntax errors detected in mission-view.php`

- [ ] **Step 10: Verify per-day data against mission #114 via a CLI script**

```bash
cat > /tmp/test_mission114_days.php << 'EOF'
<?php
require_once 'C:/Users/user/Desktop/easyride/bootstrap.php';
$missionDays = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = 114 ORDER BY day_number");
foreach ($missionDays as $day) {
    $points = normalizeRideRoutePoints($day['route_points'] ?? '[]');
    $geometry = rideRouteGeometry($day);
    $replayPoints = rideReplayPoints($geometry, $points);
    echo "Day {$day['day_number']} ({$day['day_date']}): geometry=" . count($geometry) . " points=" . count($points) . " replayPoints=" . count($replayPoints) . "\n";
}
EOF
C:\xampp\php\php.exe /tmp/test_mission114_days.php
rm /tmp/test_mission114_days.php
```

Expected: both days show `replayPoints` >= 2 (day 1 and day 2 each had ~45KB of geometry per the earlier investigation, so geometry should be used directly here).

- [ ] **Step 11: Manual browser check**

Navigate to `mission-view.php?id=114` — confirm a "Ride Replay" button appears next to "Πλοήγηση" in the day-tabs header for day 1, clicking it opens the modal and animates that day's route. Switch to day 2's tab, confirm the button is still visible, click it, and confirm the modal now animates day 2's own route (different path) — open it a second time in the same page load to confirm no Leaflet "already initialized" console error appears.

- [ ] **Step 12: Commit**

```bash
git add mission-view.php
git commit -m "Add per-day Ride Replay for multi-day missions"
```

---

### Task 4: Version bump and release

**Files:**
- Modify: `config.php:14`
- Modify: `README.md`
- Modify: `ROADMAP.md`

- [ ] **Step 1: Bump `APP_VERSION`**

In `config.php:14`, change `define('APP_VERSION', '3.84.0');` to `define('APP_VERSION', '3.85.0');`

- [ ] **Step 2: Update `README.md`**

Update the version line (`**Version:** 3.84.0` → `3.85.0`) and version badge (`3.84.0-blue` → `3.85.0-blue`), and add a new entry at the top of "Current Release Highlights":

```markdown

### v3.85.0

- Fixed Ride Replay (the animated route recap for completed missions) never appearing: it now falls back to the manually-drawn route when no Google-snapped route exists, and multi-day missions get a "Ride Replay" button per day, scoped to that day's own route and events.
```

- [ ] **Step 3: Update `ROADMAP.md`**

Add a new entry at the top of the "Deployed" section:

```markdown

### v3.85.0 — Ride Replay Visibility Fix
- Το Ride Replay (animated recap διαδρομής για ολοκληρωμένες δράσεις) δεν εμφανιζόταν ποτέ στην πράξη: απαιτούσε αποκλειστικά τη διαδρομή μέσω Google Routes API. Τώρα κάνει fallback στα χειροκίνητα σημεία διαδρομής όταν δεν υπάρχει snapped γεωμετρία, και οι πολυήμερες δράσεις αποκτούν ξεχωριστό κουμπί Ride Replay ανά ημέρα, με τη διαδρομή και τα συμβάντα της συγκεκριμένης ημέρας.
- Spec: [docs/superpowers/specs/2026-07-07-ride-replay-visibility-fix-design.md](docs/superpowers/specs/2026-07-07-ride-replay-visibility-fix-design.md)
```

- [ ] **Step 4: Lint**

Run: `C:\xampp\php\php.exe -l config.php`
Expected: `No syntax errors detected in config.php`

- [ ] **Step 5: Commit**

```bash
git add config.php README.md ROADMAP.md
git commit -m "Bump version for Ride Replay visibility fix release"
```

- [ ] **Step 6: Sync and release**

```bash
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups uploads exports node_modules .superpowers /XF config.local.php update.log
git tag -a v3.85.0 -m "v3.85.0"
git push origin main
git push origin v3.85.0
gh release create v3.85.0 --repo TheoSfak/easyrider --title "EasyRide v3.85.0" --notes "Ride Replay now falls back to manually-drawn routes when there's no Google-snapped geometry, and multi-day missions get a per-day Ride Replay button."
```
