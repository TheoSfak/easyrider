# Ride Replay Public Share Link Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a mission manager generate a public, no-login URL that renders a completed mission's Ride Replay (animated route recap), so it can be shared outside EasyRide (social media, WhatsApp), and let them revoke it later.

**Architecture:** A new `missions.replay_share_token` column (32 random bytes, hex-encoded) is generated/revoked from a small modal in `mission-view.php`, gated to mission managers. A new standalone page, `replay-share.php`, looks up the mission by token (no session/login required) and renders the exact same animated-route data the in-app modal already computes, reusing existing helpers. `assets/js/ride-replay.js`'s shared controller gets a small, backward-compatible `standalone`/`autoplay` mode so the public page can drive the animation without a Bootstrap modal.

**Tech Stack:** PHP 8.2, vanilla JS, Leaflet.js (already a dependency), Bootstrap 5.

## Global Constraints

- No automated test framework — verify via `php -l` + manual/CLI-based checks against the real DB, per project convention.
- Public share page requires no login and no CSRF token (read-only GET page) — mirrors the existing `verify-email.php` standalone-page pattern.
- Generating/revoking a link is restricted to `hasPagePermission('missions_manage')`, checked server-side, not just hidden in the UI.
- One share link per mission (covers all days for multi-day missions) — not one link per day.
- No expiry on the link — valid until a manager revokes it.
- Public page shows only: mission title, completion date, animated route + event pins (title/severity only, never member names), distance/time counters, and an app-name footer credit. No description, no location text.
- Public page autoplays the animation on load.
- Existing in-app Ride Replay behavior (single-mission modal and per-day modal) must not change.
- Working directly on `main`, no feature branch (established project convention this session).

---

### Task 1: Add `missions.replay_share_token` column

**Files:**
- Modify: `includes/migrations.php` (add migration entry after version 79, before the closing `];` of the `$migrations` array at line 4684)
- Modify: `config.php:15` (`DB_SCHEMA_VERSION`)

**Interfaces:**
- Produces: `missions.replay_share_token` column (`VARCHAR(64) NULL UNIQUE`) — Task 4 and Task 5 read/write it.

- [ ] **Step 1: Add the migration entry**

In `includes/migrations.php`, insert this new array entry immediately after the version 79 entry's closing `],` (line 4682) and before the blank line + closing `];` (lines 4683-4684):

```php
        [
            'version'     => 80,
            'description' => 'Add missions.replay_share_token for public no-login Ride Replay sharing',
            'up' => function () {
                $colExists = dbFetchValue(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'missions' AND COLUMN_NAME = 'replay_share_token'"
                );
                if (!$colExists) {
                    dbExecute("ALTER TABLE missions ADD COLUMN replay_share_token VARCHAR(64) NULL UNIQUE AFTER route_provider");
                }
            },
        ],
```

- [ ] **Step 2: Bump `DB_SCHEMA_VERSION`**

In `config.php:15`, change:
```php
define('DB_SCHEMA_VERSION', 79);
```
to:
```php
define('DB_SCHEMA_VERSION', 80);
```

- [ ] **Step 3: Lint**

Run: `C:\xampp\php\php.exe -l includes/migrations.php && C:\xampp\php\php.exe -l config.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Trigger the migration and verify the column exists**

Run:
```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/easyride/dashboard.php
```
Expected: `200` (this hits the live app, which runs pending migrations on every request — if the app isn't reachable, start it first with `cmd //c start "" "C:\xampp\mysql_start.bat"` then `cmd //c start "" "C:\xampp\apache_start.bat"`).

Then verify the column landed:
```bash
C:/xampp/mysql/bin/mysql.exe -u root -D easyride -e "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='easyride' AND TABLE_NAME='missions' AND COLUMN_NAME='replay_share_token';"
```
Expected: one row, `replay_share_token | varchar(64) | YES`.

- [ ] **Step 5: Commit**

```bash
git add includes/migrations.php config.php
git commit -m "Add missions.replay_share_token column for Ride Replay public sharing"
```

---

### Task 2: Move `buildReplayEvents()` into `includes/ride-functions.php`

**Files:**
- Modify: `includes/ride-functions.php` (add function after `rideEventRoutePositions()`, which ends at line 338)
- Modify: `mission-view.php:128-144` (remove the local copy — `mission-view.php` already `require_once`s `includes/ride-functions.php` at line 7, so the function becomes available with no other change)

**Interfaces:**
- Produces: `buildReplayEvents(array $routePoints, array $events): array` — same signature and behavior as today, just relocated. Task 5 (the new public page) calls this by this exact name.

- [ ] **Step 1: Add the function to `includes/ride-functions.php`**

Insert this immediately after the closing `}` of `rideEventRoutePositions()` (line 338), before the blank line at 339:

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
```

- [ ] **Step 2: Remove the local copy from `mission-view.php`**

Replace (lines 128-144):
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
```
with:
```php
$rideReplayEvents = [];
```
(Only the function definition is removed — the `$rideReplayEvents = [];` line and everything below it stays exactly as-is. The function is now provided by `includes/ride-functions.php`.)

- [ ] **Step 3: Lint**

Run: `C:\xampp\php\php.exe -l includes/ride-functions.php && C:\xampp\php\php.exe -l mission-view.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Regression check — confirm mission #116 and #114 in-app Replay still work**

Navigate to `http://localhost/easyride/mission-view.php?id=116` — confirm the "Ride Replay" button still appears and opens the modal with the animation playing correctly.

Navigate to `http://localhost/easyride/mission-view.php?id=114` — confirm the per-day "Ride Replay" button still appears for each day tab and animates that day's own route/events.

- [ ] **Step 5: Commit**

```bash
git add includes/ride-functions.php mission-view.php
git commit -m "Move buildReplayEvents() into ride-functions.php for reuse by the public share page"
```

---

### Task 3: Standalone/autoplay mode in `assets/js/ride-replay.js`

**Files:**
- Modify: `assets/js/ride-replay.js` (full rewrite of `initRideReplayController`)

**Interfaces:**
- Produces: `window.initRideReplayController(options)` now also accepts `options.standalone` (bool, default falsy) and `options.autoplay` (bool, default falsy), and returns `{ reload: function() }`. When `standalone` is true, `options.modalEl` is not required. Task 5 calls `initRideReplayController({ standalone: true, autoplay: true, elIds, getData })` and uses the returned `.reload()` to switch days.
- Consumes: nothing new — same DOM element ids (`elIds.map/km/time/scrubber/playPause/restart`) as today.

- [ ] **Step 1: Replace the entire file content**

```js
function initRideReplayController(options) {
    var modalEl = options.modalEl || null;
    var elIds = options.elIds;
    var getData = options.getData;
    var standalone = !!options.standalone;
    var autoplay = !!options.autoplay;
    if (!standalone && !modalEl) return;

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

    function loadData() {
        data = getData();
        if (!data || !Array.isArray(data.points) || data.points.length < 2) {
            return false;
        }
        computeCumulative();
        eventMarkers = [];
        if (map) {
            map.remove();
            map = null;
            marker = null;
        }
        initMap();
        reset();
        return true;
    }

    if (standalone) {
        if (loadData() && autoplay) {
            setPlaying(true);
        }
    } else {
        modalEl.addEventListener('shown.bs.modal', function () {
            loadData();
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
    }

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

    return {
        reload: function () {
            setPlaying(false);
            if (loadData() && autoplay) {
                setPlaying(true);
            }
        }
    };
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

Note: the only behavior change for the two existing (non-standalone) call sites is that `loadData()` now unconditionally tears down and recreates the map on every `shown.bs.modal` instead of the old `if (!map) { initMap(); }` check — this is not an observable difference, because `hidden.bs.modal` already destroys the map every time the modal closes, so `map` is always `null` by the time `shown.bs.modal` fires again in practice.

- [ ] **Step 2: Lint (syntax check via Node, since this is plain JS with no build step)**

Run: `node --check assets/js/ride-replay.js`
Expected: no output (exit code 0). If `node` isn't available, skip this step — the browser checks in Task 5 will surface any syntax error.

- [ ] **Step 3: Regression check — confirm existing modals still animate correctly**

Navigate to `http://localhost/easyride/mission-view.php?id=116`, open the "Ride Replay" modal, confirm it plays, scrubs, and restarts exactly as before. Close and reopen it once more to confirm no console errors.

- [ ] **Step 4: Commit**

```bash
git add assets/js/ride-replay.js
git commit -m "Add standalone/autoplay mode to the Ride Replay JS controller"
```

---

### Task 4: Share/revoke UI in `mission-view.php`

**Files:**
- Modify: `mission-view.php:45` (add `$hasReplayData` right after the per-day `unset($day);` at line 45)
- Modify: `mission-view.php:880-881` (add two new `switch` cases before the closing `}`)
- Modify: `mission-view.php:921-925` (header action buttons — add the Share button)
- Modify: `mission-view.php:2209-2211` (insert the new share modal between the existing `dayReplayModal` block and the `applyModal` comment)

**Interfaces:**
- Consumes: `missions.replay_share_token` column (Task 1).
- Produces: `$hasReplayData` (bool) — not consumed elsewhere in this plan, but keeps the button-visibility logic in one place for future maintenance.

- [ ] **Step 1: Compute `$hasReplayData`**

Replace (line 45, immediately after the per-day loop that sets `replay_points`):
```php
unset($day);

function buildGoogleMapsDirectionsUrl(array $routePoints, array $mission): string {
```
with:
```php
unset($day);

$hasReplayData = (!$isMultiDayMission && count($replayPoints) >= 2)
    || ($isMultiDayMission && count(array_filter($missionDays, fn($d) => count($d['replay_points']) >= 2)) > 0);

function buildGoogleMapsDirectionsUrl(array $routePoints, array $mission): string {
```

- [ ] **Step 2: Add the `generate_replay_share` and `revoke_replay_share` switch cases**

Replace (lines 878-881):
```php
                redirect('mission-view.php?id=' . $id);
            }
            break;
    }
}
```
with:
```php
                redirect('mission-view.php?id=' . $id);
            }
            break;

        case 'generate_replay_share':
            if ($canManageMissions && $mission['status'] === STATUS_COMPLETED) {
                $shareToken = bin2hex(random_bytes(32));
                dbExecute("UPDATE missions SET replay_share_token = ? WHERE id = ?", [$shareToken, $id]);
                logAudit('generate_replay_share_link', 'missions', $id);
                setFlash('success', 'Δημιουργήθηκε δημόσιο link κοινοποίησης.');
            }
            redirect('mission-view.php?id=' . $id);
            break;

        case 'revoke_replay_share':
            if ($canManageMissions) {
                dbExecute("UPDATE missions SET replay_share_token = NULL WHERE id = ?", [$id]);
                logAudit('revoke_replay_share_link', 'missions', $id);
                setFlash('success', 'Το δημόσιο link ανακλήθηκε.');
            }
            redirect('mission-view.php?id=' . $id);
            break;
    }
}
```

(This is the exact end of the existing `manual_add_member` case — only the two new `case` blocks are inserted before the switch's closing `}`; nothing else in that case changes.)

- [ ] **Step 3: Add the Share button to the header action row**

Replace:
```php
        <?php if ($canManageMissions): ?>
            <a href="mission-form.php?id=<?= $mission['id'] ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Επεξεργασία
            </a>
        <?php endif; ?>
    </div>
</div>
```
with:
```php
        <?php if ($canManageMissions): ?>
            <a href="mission-form.php?id=<?= $mission['id'] ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Επεξεργασία
            </a>
        <?php endif; ?>
        <?php if ($canManageMissions && $hasReplayData): ?>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#replayShareModal">
                <i class="bi bi-share me-1"></i>Δημόσιο Link
            </button>
        <?php endif; ?>
    </div>
</div>
```

- [ ] **Step 4: Add the share modal + copy-button script**

Replace:
```php
<?php endif; ?>

<!-- Apply Modal (for members) -->
```
with:
```php
<?php endif; ?>

<?php if ($canManageMissions && $hasReplayData): ?>
<div class="modal fade" id="replayShareModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-share me-1"></i>Δημόσιο Link Ride Replay</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($mission['replay_share_token'])): ?>
                    <p class="text-muted small mb-3">Δημιουργήστε έναν δημόσιο σύνδεσμο για να μοιραστείτε το Ride Replay αυτής της δράσης εκτός EasyRide, χωρίς να απαιτείται σύνδεση.</p>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="generate_replay_share">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-link-45deg me-1"></i>Δημιουργία Link
                        </button>
                    </form>
                <?php else: ?>
                    <?php $shareUrl = rtrim(BASE_URL, '/') . '/replay-share.php?token=' . urlencode($mission['replay_share_token']); ?>
                    <label class="form-label small text-muted">Δημόσιος σύνδεσμος</label>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" id="replayShareUrl" value="<?= h($shareUrl) ?>" readonly>
                        <button type="button" class="btn btn-outline-secondary" id="replayShareCopyBtn">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                    <p class="text-muted small">Όποιος έχει αυτόν τον σύνδεσμο μπορεί να δει το Ride Replay χωρίς σύνδεση, μέχρι να τον ανακαλέσετε.</p>
                    <form method="post" onsubmit="return confirm('Ανάκληση του δημόσιου συνδέσμου; Ο υπάρχων σύνδεσμος θα σταματήσει να λειτουργεί.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="revoke_replay_share">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-x-circle me-1"></i>Ανάκληση Link
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var copyBtn = document.getElementById('replayShareCopyBtn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var input = document.getElementById('replayShareUrl');
            navigator.clipboard.writeText(input.value).then(function () {
                var icon = copyBtn.querySelector('i');
                icon.className = 'bi bi-check-lg';
                setTimeout(function () { icon.className = 'bi bi-clipboard'; }, 1500);
            });
        });
    }
});
</script>
<?php endif; ?>

<!-- Apply Modal (for members) -->
```

- [ ] **Step 5: Lint**

Run: `C:\xampp\php\php.exe -l mission-view.php`
Expected: `No syntax errors detected in mission-view.php`

- [ ] **Step 6: Manual browser check — generate and revoke**

1. As a manager (has `missions_manage`), navigate to `http://localhost/easyride/mission-view.php?id=116` (completed, single-day, has geometry). Confirm the "Δημόσιο Link" button appears next to "Επεξεργασία".
2. Click it, click "Δημιουργία Link". Confirm the modal now shows a URL like `http://localhost/easyride/replay-share.php?token=...` with a working copy button (icon briefly turns into a checkmark).
3. Reload the page, reopen the modal — confirm the same URL is shown (token persisted).
4. Click "Ανάκληση Link", confirm the dialog and then confirm the modal reverts to the "Δημιουργία Link" state.
5. As a regular member (no `missions_manage`), confirm the "Δημόσιο Link" button is not shown at all.

- [ ] **Step 7: Commit**

```bash
git add mission-view.php
git commit -m "Add Ride Replay share-link generate/revoke UI to mission-view.php"
```

---

### Task 5: Public page `replay-share.php` + maintenance-mode exclusion

**Files:**
- Create: `replay-share.php`
- Modify: `bootstrap.php:56` (`$__maintenanceExcluded` array)

**Interfaces:**
- Consumes: `rideReplayPoints()`, `rideMissionRouteMetrics()`, `buildReplayEvents()`, `getRideEvents()` (all from `includes/ride-functions.php`), `window.initRideReplayController()` (Task 3, in standalone/autoplay mode).

- [ ] **Step 1: Add `replay-share.php` to the maintenance-mode exclusion list**

In `bootstrap.php:56`, replace:
```php
$__maintenanceExcluded = ['login.php', 'logout.php', 'install.php', 'cron_daily.php', 'cron_shift_reminders.php', 'cron_task_reminders.php', 'cron_certificate_expiry.php', 'cron_citizen_cert_expiry.php', 'cron_shelf_expiry.php', 'cron_incomplete_missions.php'];
```
with:
```php
$__maintenanceExcluded = ['login.php', 'logout.php', 'install.php', 'replay-share.php', 'cron_daily.php', 'cron_shift_reminders.php', 'cron_task_reminders.php', 'cron_certificate_expiry.php', 'cron_citizen_cert_expiry.php', 'cron_shelf_expiry.php', 'cron_incomplete_missions.php'];
```
(Without this, a logged-out visitor hitting the public share link while the site is in maintenance mode would be redirected to `login.php` instead of seeing the replay.)

- [ ] **Step 2: Create `replay-share.php`**

```php
<?php
/**
 * EasyRide - Public Ride Replay Share Page
 * No login required. Looks up a mission by its replay_share_token and
 * renders the same animated route recap shown in-app, for sharing outside
 * EasyRide (social media, WhatsApp, etc).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ride-functions.php';

$token = trim(get('token', ''));
$mission = null;
$state = 'invalid'; // 'invalid' | 'unavailable' | 'ok'

if (!empty($token)) {
    $mission = dbFetchOne("SELECT * FROM missions WHERE replay_share_token = ?", [$token]);
    if ($mission) {
        $state = $mission['status'] === STATUS_COMPLETED ? 'ok' : 'unavailable';
    }
}

$appName = getSetting('app_name', 'EasyRide');
$appLogo = getSetting('app_logo', '');
$days = [];

if ($state === 'ok') {
    $routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
    $routeGeometry = rideRouteGeometry($mission);
    $replayPoints = rideReplayPoints($routeGeometry, $routePoints);

    $missionDays = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number", [$mission['id']]);
    $isMultiDayMission = !empty($missionDays);
    $rideEvents = getRideEvents((int)$mission['id'], 100, false);

    if ($isMultiDayMission) {
        foreach ($missionDays as $day) {
            $dayPoints = normalizeRideRoutePoints($day['route_points'] ?? '[]');
            $dayGeometry = rideRouteGeometry($day);
            $dayReplayPoints = rideReplayPoints($dayGeometry, $dayPoints);
            if (count($dayReplayPoints) < 2) {
                continue;
            }
            $dayMetrics = rideMissionRouteMetrics($day, $dayPoints);
            $dayEvents = array_values(array_filter($rideEvents, function ($event) use ($day) {
                return substr((string)$event['created_at'], 0, 10) === $day['day_date'];
            }));
            $days[] = [
                'label' => ($day['title'] ?: 'Μέρα ' . (int)$day['day_number']) . ' · ' . formatDateGreek($day['day_date']),
                'points' => $dayReplayPoints,
                'distanceMeters' => (float)($dayMetrics['distance_meters'] ?? 0),
                'totalMinutes' => (int)($dayMetrics['total_minutes'] ?? 0),
                'events' => buildReplayEvents($dayReplayPoints, $dayEvents),
            ];
        }
    } elseif (count($replayPoints) >= 2) {
        $routeMetrics = rideMissionRouteMetrics($mission, $routePoints);
        $days[] = [
            'label' => $mission['title'],
            'points' => $replayPoints,
            'distanceMeters' => (float)($routeMetrics['distance_meters'] ?? 0),
            'totalMinutes' => (int)($routeMetrics['total_minutes'] ?? 0),
            'events' => buildReplayEvents($replayPoints, $rideEvents),
        ];
    }

    if (empty($days)) {
        $state = 'unavailable';
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= $state === 'ok' ? h($mission['title']) . ' - Ride Replay' : 'Ride Replay' ?> - <?= h($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <?php if ($state === 'ok'): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <?php endif; ?>
    <style>
        body {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .replay-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 640px;
            width: 100%;
            margin: 0 auto;
            overflow: hidden;
        }
    </style>
</head>
<body>
<div class="replay-card">
    <?php if ($state !== 'ok'): ?>
        <div class="text-center py-5 px-4">
            <?php if ($appLogo): ?>
                <img src="<?= h($appLogo) ?>" alt="Logo" style="max-height:60px;margin-bottom:1rem">
            <?php else: ?>
                <h4 class="fw-bold text-primary mb-4"><?= h($appName) ?></h4>
            <?php endif; ?>
            <div class="text-danger mb-3" style="font-size:3rem;"><i class="bi bi-film"></i></div>
            <?php if ($state === 'invalid'): ?>
                <h4 class="fw-bold mb-2">Μη Έγκυρος Σύνδεσμος</h4>
                <p class="text-muted mb-0">Αυτός ο σύνδεσμος Ride Replay δεν είναι έγκυρος ή έχει ανακληθεί.</p>
            <?php else: ?>
                <h4 class="fw-bold mb-2">Μη Διαθέσιμο</h4>
                <p class="text-muted mb-0">Αυτό το Ride Replay δεν είναι διαθέσιμο αυτή τη στιγμή.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="px-4 pt-4 pb-0">
            <?php if ($appLogo): ?>
                <img src="<?= h($appLogo) ?>" alt="Logo" style="max-height:40px;margin-bottom:.75rem">
            <?php endif; ?>
            <h4 class="fw-bold mb-1"><i class="bi bi-film me-1 text-primary"></i><?= h($mission['title']) ?></h4>
            <p class="text-muted small mb-3">Ολοκληρώθηκε: <?= h(formatDateGreek($mission['end_datetime'] ?? $mission['start_datetime'])) ?></p>
        </div>

        <?php if (count($days) > 1): ?>
        <ul class="nav nav-pills px-4 pb-2" id="replayShareDayTabs">
            <?php foreach ($days as $i => $day): ?>
            <li class="nav-item">
                <a href="#" class="nav-link py-1 px-2<?= $i === 0 ? ' active' : '' ?>" data-day-index="<?= $i ?>"><?= h($day['label']) ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div id="replayShareMap" style="height:360px;"></div>
        <div class="p-4 border-top">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <span id="replayShareKm" class="fw-bold">0 m</span>
                    <span class="text-muted mx-1">&middot;</span>
                    <span id="replayShareTime" class="fw-bold">0 λεπτά</span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" id="replayShareRestart" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button type="button" id="replaySharePlayPause" class="btn btn-sm btn-primary">
                        <i class="bi bi-play-fill"></i>
                    </button>
                </div>
            </div>
            <input type="range" id="replayShareScrubber" class="form-range" min="0" max="1" step="0.001" value="0">
        </div>
        <div class="text-center py-3 border-top small text-muted">
            Powered by <?= h($appName) ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($state === 'ok'): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ride-replay.js?v=<?= APP_VERSION ?>"></script>
<script>
    var replayDays = <?= json_encode($days, JSON_UNESCAPED_UNICODE) ?>;
    var currentDayIndex = 0;
    var replayController = window.initRideReplayController({
        standalone: true,
        autoplay: true,
        elIds: {
            map: 'replayShareMap',
            km: 'replayShareKm',
            time: 'replayShareTime',
            scrubber: 'replayShareScrubber',
            playPause: 'replaySharePlayPause',
            restart: 'replayShareRestart'
        },
        getData: function () { return replayDays[currentDayIndex]; }
    });

    document.querySelectorAll('#replayShareDayTabs .nav-link').forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('#replayShareDayTabs .nav-link').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            currentDayIndex = parseInt(tab.dataset.dayIndex, 10);
            replayController.reload();
        });
    });
</script>
<?php endif; ?>
</body>
</html>
```

- [ ] **Step 3: Lint**

Run: `C:\xampp\php\php.exe -l replay-share.php && C:\xampp\php\php.exe -l bootstrap.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Generate real share tokens for both test missions via CLI, bypassing the UI**

```bash
cat > /tmp/test_generate_share_tokens.php << 'EOF'
<?php
require_once 'C:/Users/user/Desktop/easyride/bootstrap.php';
foreach ([116, 114] as $missionId) {
    $token = bin2hex(random_bytes(32));
    dbExecute("UPDATE missions SET replay_share_token = ? WHERE id = ?", [$token, $missionId]);
    echo "Mission {$missionId}: http://localhost/easyride/replay-share.php?token={$token}\n";
}
EOF
C:\xampp\php\php.exe /tmp/test_generate_share_tokens.php
rm /tmp/test_generate_share_tokens.php
```

Expected: two lines printed, each a full URL. Keep this output — you'll open both URLs in the next step.

- [ ] **Step 5: Manual browser check — public page, single-day and multi-day**

1. Open a private/incognito browser window (no session cookie) and navigate to the mission #116 URL printed above. Confirm: the page loads with no login prompt, shows the mission title and "Ολοκληρώθηκε: ..." date, the map appears and the marker animation **starts automatically** (no need to press Play), and the counters tick up to the full distance/time.
2. In the same private window, navigate to the mission #114 URL. Confirm: a day-pill row appears above the map (2 pills, one per day), day 1 autoplays on load, and clicking the day 2 pill switches the map to day 2's own route and restarts the animation (autoplaying again).
3. Confirm neither page shows any member name anywhere (view page source / inspect the `replayDays` JS variable — `events[].title` should only ever contain generic status labels, never a person's name).
4. In DevTools, confirm the response `<head>` contains `<meta name="robots" content="noindex,nofollow">`.

- [ ] **Step 6: Manual browser check — invalid and revoked tokens**

1. Navigate to `http://localhost/easyride/replay-share.php?token=doesnotexist`. Confirm the "Μη Έγκυρος Σύνδεσμος" page appears.
2. Run:
```bash
C:/xampp/mysql/bin/mysql.exe -u root -D easyride -e "UPDATE missions SET status='OPEN' WHERE id=116;"
```
Reload the mission #116 share URL from Step 5. Confirm the "Μη Διαθέσιμο" page now appears (mission no longer completed).
3. Restore it: `C:/xampp/mysql/bin/mysql.exe -u root -D easyride -e "UPDATE missions SET status='COMPLETED' WHERE id=116;"` and reload the same URL — confirm it works again (the token wasn't cleared, so no regeneration was needed).
4. Clean up the test tokens so they don't linger in the live dataset:
```bash
C:/xampp/mysql/bin/mysql.exe -u root -D easyride -e "UPDATE missions SET replay_share_token = NULL WHERE id IN (116, 114);"
```

- [ ] **Step 7: Commit**

```bash
git add replay-share.php bootstrap.php
git commit -m "Add public no-login Ride Replay share page"
```

---

### Task 6: Version bump, docs, and release

**Files:**
- Modify: `config.php:14`
- Modify: `README.md`
- Modify: `ROADMAP.md`

- [ ] **Step 1: Bump `APP_VERSION`**

In `config.php:14`, change:
```php
define('APP_VERSION', '3.85.0');
```
to:
```php
define('APP_VERSION', '3.86.0');
```

- [ ] **Step 2: Update `README.md`**

Update the version line and badge:
```markdown
**Version:** 3.86.0  
```
```markdown
![Version](https://img.shields.io/badge/version-3.86.0-blue)
```

Add a new entry at the top of "Current Release Highlights" (replace):
```markdown
## Current Release Highlights

### v3.85.0
```
with:
```markdown
## Current Release Highlights

### v3.86.0

- Added a public, no-login share link for Ride Replay: mission managers can generate a URL that renders a completed mission's animated route recap (with autoplay, and a day switcher for multi-day missions) for sharing outside EasyRide, and revoke it later.

### v3.85.0
```

- [ ] **Step 3: Update `ROADMAP.md`**

Replace:
```markdown
## Deployed

### v3.85.0 — Ride Replay Visibility Fix
```
with:
```markdown
## Deployed

### v3.86.0 — Ride Replay Public Share Link
- Οι υπεύθυνοι δράσεων μπορούν πλέον να δημιουργήσουν έναν δημόσιο σύνδεσμο (χωρίς απαίτηση σύνδεσης) που εμφανίζει το Ride Replay μιας ολοκληρωμένης δράσης — με αυτόματη αναπαραγωγή, και εναλλαγή ημερών για πολυήμερες δράσεις — για κοινοποίηση εκτός EasyRide (social media, WhatsApp). Ο σύνδεσμος παραμένει ενεργός μέχρι να ανακληθεί χειροκίνητα.
- Spec: [docs/superpowers/specs/2026-07-07-ride-replay-share-link-design.md](docs/superpowers/specs/2026-07-07-ride-replay-share-link-design.md)

### v3.85.0 — Ride Replay Visibility Fix
```

Then remove the now-completed "Μένει να γίνει" entry — replace:
```markdown
## Μένει να γίνει

### Ride Replay — Δημόσιο link κοινοποίησης (χωρίς προγραμματισμένη ημερομηνία)
Αναφέρθηκε ως μελλοντική ιδέα κατά τον σχεδιασμό του Ride Replay: δυνατότητα δημιουργίας δημόσιου (χωρίς login) link ώστε να μοιράζεται το replay μιας δράσης εκτός EasyRide (π.χ. social media, WhatsApp). Requires ξεχωριστό σχεδιασμό (tokens, privacy, expiry) — δεν έχει προγραμματιστεί ακόμα.

### Άλλα ανοιχτά σημεία (μη κρίσιμα, cosmetic)
```
with:
```markdown
## Μένει να γίνει

### Άλλα ανοιχτά σημεία (μη κρίσιμα, cosmetic)
```

- [ ] **Step 4: Lint**

Run: `C:\xampp\php\php.exe -l config.php`
Expected: `No syntax errors detected in config.php`

- [ ] **Step 5: Commit**

```bash
git add config.php README.md ROADMAP.md
git commit -m "Bump version for Ride Replay public share link release"
```

- [ ] **Step 6: Sync and release**

```bash
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups uploads exports node_modules .superpowers /XF config.local.php update.log
git tag -a v3.86.0 -m "v3.86.0"
git push origin main
git push origin v3.86.0
gh release create v3.86.0 --repo TheoSfak/easyrider --title "EasyRide v3.86.0" --notes "Ride Replay now supports a public, no-login share link so a completed mission's animated route recap can be shared outside EasyRide."
```
