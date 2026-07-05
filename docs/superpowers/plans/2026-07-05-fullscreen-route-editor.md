# Fullscreen Route Point Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a fullscreen route-drawing modal to `mission-form.php` with a large map (so drawing a multi-stop route is comfortable) and let an admin remove a wrongly-placed point by clicking its marker directly, in addition to the existing list-based delete.

**Architecture:** Generalize the existing single-map drawing code in `mission-form.php` into a small "map instance" registry so any number of Leaflet maps can stay in sync with the same in-memory `routePoints` array. The existing small inline map becomes the first registered instance (no behavior change beyond gaining a delete button in its point popups); a second map is registered only while the new fullscreen Bootstrap modal is open, and unregistered when it closes.

**Tech Stack:** PHP 8.2 (no framework), vanilla JS, Leaflet 1.9.4 (already loaded on this page), Bootstrap 5.3 modals (`modal-fullscreen`).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-05-fullscreen-route-editor-design.md`
- No new DB columns, no new page, no change to `route_points`/`route_geometry` submission or the Google-routing snap logic (`scheduleRouteFetch`).
- Detailed per-point fields (scheduled time, stop minutes, notes) stay editable only in the existing below-map list (`#routePointList`) — the fullscreen modal's compact panel shows only index + title + remove.
- The right-hand panel inside the fullscreen modal must be narrow (~200px) — the map gets the majority of the width. This was explicit user direction: "θελουμε megalo xarti gia na ginete aneta i xaraksi poreias."
- Unifying the marker-drawing code (Task 1) means the existing small inline map's point markers also gain a "Διαγραφή σημείου" popup button, identical to the fullscreen map's. This is an intentional, accepted side effect of sharing one drawing function — not scope creep — and should not be flagged as an unrequested addition in review.
- No automated test framework exists in this repo (project-wide fact). Verify JS changes with `node --check <file>` for syntax only, and manual browser verification for behavior — mirrors the convention used for the Ride Replay feature earlier this session.

---

### Task 1: Multi-map instance registry (refactor, no visible behavior change on the small map except popup delete buttons)

**Files:**
- Modify: `mission-form.php` (JS block starting at line 719; specifically the globals around line 721-730, `syncRouteInput()` at line 911-920, `renderRoute()` at line 1010-1052, and `initRouteEditor()` at line 1054-1095)

**Interfaces:**
- Consumes: existing globals `routePoints`, `routedGeometry`, `routedProvider`, `defaultCenter`, `getSavedLocation()`, `escapeHtml()`, `updateRoutePoint(index, field, value)`, `renderRoutePointList()`, `syncRouteInput()` — all already defined elsewhere in this file, unchanged.
- Produces: a module-level array `mapInstances` (each entry shaped `{ map: L.Map, markers: L.CircleMarker[], line: L.Polyline|null }`), and these new/changed functions for Tasks 2-3 to build on:
  - `drawPointsOnMapInstance(instance)` — redraws markers/polyline for one instance from the current `routePoints`.
  - `buildRoutePointPopupHtml(index)` — returns the popup HTML (with a `.route-point-delete-btn` button) for the point at `index`.
  - `renderFullscreenPointPanel()` — renders the compact point list into `#routeFullscreenPointPanel` if that element exists (no-op otherwise, since the modal markup doesn't exist until Task 2).
  - `renderRoute(fitBounds, skipListRender)` — same signature as today, now iterates `mapInstances` instead of a single map.

- [ ] **Step 1: Replace the globals block**

In `mission-form.php`, find this block (currently lines 721-724):

```javascript
    var routeMap = null;
    var routeLine = null;
    var routeMarkers = [];
    var locationMarker = null;
```

Replace it with:

```javascript
    var routeMap = null;
    var locationMarker = null;
    var mapInstances = []; // each: { map: L.Map, markers: L.CircleMarker[], line: L.Polyline|null }
```

(`routeLine` and `routeMarkers` move into per-instance objects in `mapInstances` — they're no longer standalone globals.)

- [ ] **Step 2: Update `syncRouteInput()` to also update a fullscreen count badge if present**

Find (around line 911-920):

```javascript
    function syncRouteInput() {
        document.getElementById('route_points').value = routePoints.length ? JSON.stringify(routePoints) : '';
        document.getElementById('routePointCount').textContent = routePoints.length + (routePoints.length === 1 ? ' σημείο' : ' σημεία');
        if (routeSignature() !== lastRoutedSignature) {
            clearRoutedData();
            lastRoutedSignature = null;
            scheduleRouteFetch();
        }
        updateRouteMetricsSummary();
    }
```

Replace with:

```javascript
    function syncRouteInput() {
        document.getElementById('route_points').value = routePoints.length ? JSON.stringify(routePoints) : '';
        var countLabel = routePoints.length + (routePoints.length === 1 ? ' σημείο' : ' σημεία');
        document.getElementById('routePointCount').textContent = countLabel;
        var fullscreenCount = document.getElementById('routeFullscreenPointCount');
        if (fullscreenCount) fullscreenCount.textContent = countLabel;
        if (routeSignature() !== lastRoutedSignature) {
            clearRoutedData();
            lastRoutedSignature = null;
            scheduleRouteFetch();
        }
        updateRouteMetricsSummary();
    }
```

- [ ] **Step 3: Replace `renderRoute()` with the multi-instance version, and add the three new functions**

Find the existing `renderRoute()` function (currently lines 1010-1052):

```javascript
    function renderRoute(fitBounds, skipListRender) {
        if (!routeMap) return;

        routeMarkers.forEach(function(marker) { marker.remove(); });
        routeMarkers = [];
        if (routeLine) {
            routeLine.remove();
            routeLine = null;
        }

        var latLngs = routePoints.map(function(point) { return [point.lat, point.lng]; });
        latLngs.forEach(function(latLng, index) {
            var marker = L.circleMarker(latLng, {
                radius: 6,
                color: '#0d6efd',
                fillColor: '#0d6efd',
                fillOpacity: 0.9,
                weight: 2
            }).addTo(routeMap).bindTooltip(String(index + 1), {
                permanent: true,
                direction: 'center',
                className: 'route-point-label'
            }).bindPopup('<strong>Σημείο ' + (index + 1) + '</strong>' + (routePoints[index].title ? '<br>' + escapeHtml(routePoints[index].title) : ''));
            routeMarkers.push(marker);
        });

        if (latLngs.length >= 2) {
            var lineLatLngs = (routedProvider === 'google' && routedGeometry.length >= 2) ? routedGeometry : latLngs;
            routeLine = L.polyline(lineLatLngs, {
                color: '#0d6efd',
                weight: 5,
                opacity: 0.85
            }).addTo(routeMap);
        }

        syncRouteInput();
        if (!skipListRender) {
            renderRoutePointList();
        }
        if (fitBounds && latLngs.length > 0) {
            routeMap.fitBounds(L.latLngBounds(latLngs), { padding: [25, 25] });
        }
    }
```

Replace it with these four functions (in this order):

```javascript
    function buildRoutePointPopupHtml(index) {
        var point = routePoints[index];
        var titleLine = point.title ? '<br>' + escapeHtml(point.title) : '';
        return '<div class="route-point-popup">' +
            '<strong>Σημείο ' + (index + 1) + '</strong>' + titleLine +
            '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-danger route-point-delete-btn">' +
            '<i class="bi bi-trash me-1"></i>Διαγραφή σημείου</button></div>' +
            '</div>';
    }

    function drawPointsOnMapInstance(instance) {
        instance.markers.forEach(function(marker) { marker.remove(); });
        instance.markers = [];
        if (instance.line) {
            instance.line.remove();
            instance.line = null;
        }

        var latLngs = routePoints.map(function(point) { return [point.lat, point.lng]; });
        latLngs.forEach(function(latLng, index) {
            var marker = L.circleMarker(latLng, {
                radius: 6,
                color: '#0d6efd',
                fillColor: '#0d6efd',
                fillOpacity: 0.9,
                weight: 2
            }).addTo(instance.map).bindTooltip(String(index + 1), {
                permanent: true,
                direction: 'center',
                className: 'route-point-label'
            }).bindPopup(buildRoutePointPopupHtml(index));

            marker.on('popupopen', function(e) {
                var popupEl = e.popup.getElement();
                var deleteBtn = popupEl ? popupEl.querySelector('.route-point-delete-btn') : null;
                if (deleteBtn) {
                    deleteBtn.addEventListener('click', function() {
                        routePoints.splice(index, 1);
                        renderRoute(false);
                    });
                }
            });

            instance.markers.push(marker);
        });

        if (latLngs.length >= 2) {
            var lineLatLngs = (routedProvider === 'google' && routedGeometry.length >= 2) ? routedGeometry : latLngs;
            instance.line = L.polyline(lineLatLngs, {
                color: '#0d6efd',
                weight: 5,
                opacity: 0.85
            }).addTo(instance.map);
        }
    }

    function renderFullscreenPointPanel() {
        var panel = document.getElementById('routeFullscreenPointPanel');
        if (!panel) return;

        panel.innerHTML = '';
        if (!routePoints.length) {
            panel.innerHTML = '<div class="text-muted small p-2">Δεν έχουν οριστεί σημεία.</div>';
            return;
        }

        routePoints.forEach(function(point, index) {
            var row = document.createElement('div');
            row.className = 'route-fullscreen-point-row d-flex align-items-center gap-1 mb-2';
            row.innerHTML =
                '<span class="route-point-index bg-primary text-white fw-bold flex-shrink-0">' + (index + 1) + '</span>' +
                '<input type="text" class="form-control form-control-sm route-fullscreen-title" maxlength="120" placeholder="Τίτλος">' +
                '<button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0 route-fullscreen-remove" title="Αφαίρεση σημείου"><i class="bi bi-x-lg"></i></button>';

            var titleInput = row.querySelector('.route-fullscreen-title');
            titleInput.value = point.title || '';
            titleInput.addEventListener('input', function() {
                updateRoutePoint(index, 'title', titleInput.value);
            });

            row.querySelector('.route-fullscreen-remove').addEventListener('click', function() {
                routePoints.splice(index, 1);
                renderRoute(false);
            });

            panel.appendChild(row);
        });
    }

    function renderRoute(fitBounds, skipListRender) {
        mapInstances.forEach(function(instance) {
            drawPointsOnMapInstance(instance);
            if (fitBounds && routePoints.length > 0) {
                instance.map.fitBounds(
                    L.latLngBounds(routePoints.map(function(point) { return [point.lat, point.lng]; })),
                    { padding: [25, 25] }
                );
            }
        });

        syncRouteInput();
        if (!skipListRender) {
            renderRoutePointList();
            renderFullscreenPointPanel();
        }
    }
```

- [ ] **Step 4: Register the small map as the first instance in `initRouteEditor()`**

Find this line inside `initRouteEditor()` (currently around line 1062-1066, right after the map and tile layer are created):

```javascript
    routeMap = L.map(mapEl, { scrollWheelZoom: false }).setView(center, zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19
    }).addTo(routeMap);
```

Add this line immediately after it (still inside `initRouteEditor()`, before the `if (savedLocation)` check):

```javascript
    mapInstances.push({ map: routeMap, markers: [], line: null });
```

- [ ] **Step 5: Syntax-check and manual verification**

Run: `C:\xampp\php\php.exe -l mission-form.php` (this only checks the PHP is still valid; the JS inside is unaffected by this check but confirms no stray PHP was broken by the edits).

If Node.js is available, extract just the inline `<script>` block mentally is not practical — instead rely on careful manual review plus the browser check below (there's no separate `.js` file for this code, it's inline in the PHP page, so `node --check` doesn't apply here the way it did for `ride-replay.js`).

Sync to XAMPP:
```bash
robocopy.exe "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```
(exit codes 0-7 are success)

In the browser, open the mission form (new mission or edit an existing one) and confirm:
1. The small map still works exactly as before: clicking empty area adds a point, Undo removes the last point, Clear removes all, the point list below the map still shows/edits title/time/stop/notes and its own X button still removes a point.
2. Clicking an existing point's marker on the small map now opens a popup with a "Διαγραφή σημείου" button, and clicking that button removes the point (new, intentional, harmless side effect of the shared drawing code — see Global Constraints).
3. No console errors.

- [ ] **Step 6: Commit**

```bash
git add mission-form.php
git commit -m "Generalize route map drawing to support multiple synced map instances"
```

---

### Task 2: Fullscreen modal markup, trigger button, and CSS

**Files:**
- Modify: `mission-form.php` (style block at lines 381-387; toolbar buttons at lines 488-496; add new modal markup before the `<!-- Date/Time Picker Modal -->` comment, currently at line 1165)

**Interfaces:**
- Consumes: nothing from Task 1 directly (this task is markup/CSS only).
- Produces: DOM elements Task 3 wires up: `#routeFullscreenModal` (the modal), `#routeFullscreenMap` (map container div), `#routeFullscreenPointPanel` (compact point list container — already consumed by Task 1's `renderFullscreenPointPanel()`), `#routeFullscreenPointCount` (badge — already consumed by Task 1's `syncRouteInput()`), `#routeFullscreenUndoBtn`, `#routeFullscreenClearBtn`.

- [ ] **Step 1: Add the trigger button**

Find this block (currently lines 488-496):

```php
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="routeUndoBtn">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Αναίρεση
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="routeClearBtn">
                                <i class="bi bi-trash me-1"></i>Καθαρισμός
                            </button>
                            <span class="badge bg-light text-dark border route-point-count" id="routePointCount">0 σημεία</span>
                        </div>
```

Replace with (adds one button):

```php
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
```

- [ ] **Step 2: Add CSS for the fullscreen layout**

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

Replace with (adds four new rules):

```php
<style>
#routeEditorMap { height: 320px; border-radius: 8px; border: 1px solid #dee2e6; overflow: hidden; }
.route-point-count { min-width: 74px; text-align: center; }
.route-point-row { border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; background: #fff; }
.route-point-row + .route-point-row { margin-top: 8px; }
.route-point-index { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
.route-fullscreen-layout { display: flex; height: 100%; }
.route-fullscreen-map { flex: 1 1 auto; height: 100%; }
.route-fullscreen-panel { flex: 0 0 200px; width: 200px; overflow-y: auto; padding: 10px; border-left: 1px solid #dee2e6; background: #f8f9fa; }
@media (max-width: 767.98px) {
    .route-fullscreen-layout { flex-direction: column; }
    .route-fullscreen-map { height: 60vh; }
    .route-fullscreen-panel { flex: 0 0 auto; width: 100%; border-left: 0; border-top: 1px solid #dee2e6; max-height: 30vh; }
}
</style>
```

- [ ] **Step 3: Add the modal markup**

Find the `<!-- Date/Time Picker Modal -->` comment (currently at line 1165) and insert this new modal block immediately before it:

```php
<!-- Fullscreen Route Point Editor -->
<div class="modal fade" id="routeFullscreenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="routeFullscreenUndoBtn">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Αναίρεση
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="routeFullscreenClearBtn">
                        <i class="bi bi-trash me-1"></i>Καθαρισμός
                    </button>
                    <span class="badge bg-light text-dark border route-point-count" id="routeFullscreenPointCount">0 σημεία</span>
                </div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="route-fullscreen-layout">
                    <div id="routeFullscreenMap" class="route-fullscreen-map"></div>
                    <div id="routeFullscreenPointPanel" class="route-fullscreen-panel"></div>
                </div>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Lint, sync, and manual verification**

Run: `C:\xampp\php\php.exe -l mission-form.php`

Sync:
```bash
robocopy.exe "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

In the browser, open the mission form and confirm: the "Μεγάλος χάρτης" button appears next to Undo/Clear, and clicking it opens a fullscreen modal showing an empty map area (no Leaflet map rendered yet — that's Task 3) and an empty right-hand panel, with the Undo/Clear buttons and close button visible. No console errors expected beyond Task 3 not existing yet (clicking the modal's Undo/Clear buttons will do nothing yet — that's fine at this step).

- [ ] **Step 5: Commit**

```bash
git add mission-form.php
git commit -m "Add fullscreen route editor modal markup and CSS"
```

---

### Task 3: Wire the fullscreen map (open/close lifecycle, click-to-add, marker-click-to-delete, toolbar buttons)

**Files:**
- Modify: `mission-form.php` (add new functions near the other route-editor functions, e.g. right after `initRouteEditor()` which now ends around line 1096 after Task 1's change; modify the `DOMContentLoaded` handler currently at line 1136-1157 to call the new init function)

**Interfaces:**
- Consumes: `mapInstances` array, `drawPointsOnMapInstance(instance)`, `renderFullscreenPointPanel()`, `renderRoute(fitBounds, skipListRender)`, `getSavedLocation()`, `defaultCenter`, `routePoints` — all produced by Task 1. DOM ids `#routeFullscreenModal`, `#routeFullscreenMap`, `#routeFullscreenUndoBtn`, `#routeFullscreenClearBtn` — produced by Task 2.
- Produces: nothing consumed by later tasks (Task 4 is verification/bookkeeping only).

- [ ] **Step 1: Add the fullscreen map open/close functions**

In `mission-form.php`, immediately after the `initRouteEditor()` function's closing `}` (the function Task 1 modified, ending around line 1096), add:

```javascript
    var fullscreenMapInstance = null;

    function openFullscreenRouteMap() {
        if (!fullscreenMapInstance) {
            var mapEl = document.getElementById('routeFullscreenMap');
            if (!mapEl || typeof L === 'undefined') return;

            var savedLocation = getSavedLocation();
            var center = routePoints.length ? [routePoints[0].lat, routePoints[0].lng] : (savedLocation || defaultCenter);
            var zoom = routePoints.length || savedLocation ? 14 : 9;

            var map = L.map(mapEl, { scrollWheelZoom: true }).setView(center, zoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19
            }).addTo(map);

            map.on('click', function(e) {
                routePoints.push({
                    lat: Math.round(e.latlng.lat * 1000000) / 1000000,
                    lng: Math.round(e.latlng.lng * 1000000) / 1000000,
                    title: routePoints.length === 0 ? 'Εκκίνηση' : '',
                    scheduled_time: '',
                    stop_minutes: 0,
                    notes: ''
                });
                renderRoute(false);
            });

            fullscreenMapInstance = { map: map, markers: [], line: null };
            mapInstances.push(fullscreenMapInstance);
        }

        drawPointsOnMapInstance(fullscreenMapInstance);
        renderFullscreenPointPanel();
        if (routePoints.length > 0) {
            fullscreenMapInstance.map.fitBounds(
                L.latLngBounds(routePoints.map(function(point) { return [point.lat, point.lng]; })),
                { padding: [25, 25] }
            );
        }
        setTimeout(function() { fullscreenMapInstance.map.invalidateSize(); }, 150);
    }

    function closeFullscreenRouteMap() {
        if (!fullscreenMapInstance) return;
        var idx = mapInstances.indexOf(fullscreenMapInstance);
        if (idx !== -1) mapInstances.splice(idx, 1);
        fullscreenMapInstance.map.remove();
        fullscreenMapInstance = null;
    }

    function initFullscreenRouteEditor() {
        var modalEl = document.getElementById('routeFullscreenModal');
        if (!modalEl) return;

        modalEl.addEventListener('shown.bs.modal', openFullscreenRouteMap);
        modalEl.addEventListener('hidden.bs.modal', closeFullscreenRouteMap);

        document.getElementById('routeFullscreenUndoBtn').addEventListener('click', function() {
            routePoints.pop();
            renderRoute(false);
        });
        document.getElementById('routeFullscreenClearBtn').addEventListener('click', function() {
            routePoints = [];
            renderRoute(false);
        });
    }
```

- [ ] **Step 2: Call the new init function on page load**

Find this line inside the `DOMContentLoaded` handler (currently around line 1139):

```javascript
        initRouteEditor();
```

Add immediately after it:

```javascript
        initFullscreenRouteEditor();
```

- [ ] **Step 3: Sync and manual verification**

Run: `C:\xampp\php\php.exe -l mission-form.php`

Sync:
```bash
robocopy.exe "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
```

In the browser, open the mission form and verify the full feature end to end:
1. Click "Μεγάλος χάρτης" — modal opens with a large, tile-loaded map. If the form already had points, they appear as markers and the map fits their bounds.
2. Click an empty area of the fullscreen map — a new point is added; it appears immediately in the fullscreen map, the compact right-hand panel, and (after closing the modal) in the small map and the full below-map list.
3. Click an existing marker (in the fullscreen map) — a popup appears with "Διαγραφή σημείου"; clicking it removes that point everywhere (fullscreen map, compact panel, small map, full list).
4. Type a title into a compact panel row's text input — the change is reflected in the corresponding row of the full point list below the small map (and vice versa: editing the title in the full list updates the compact panel next time the modal opens).
5. Click Undo/Clear inside the modal — removes the last point / all points, reflected everywhere.
6. Close and reopen the modal several times — no duplicate markers, no leftover Leaflet map instances, no console errors (check via browser dev tools).
7. Save the mission form (create or edit) — confirm `route_points` submitted matches what was drawn, and (if a Google Maps API key is configured in Settings) route snapping still runs as before.
8. Resize the browser to a narrow/mobile width while the modal is open — panel stacks below the map per the responsive CSS from Task 2, remains usable.

- [ ] **Step 4: Commit**

```bash
git add mission-form.php
git commit -m "Wire fullscreen route map open/close, click-to-add, and marker delete"
```

---

### Task 4: Manual sign-off, version bump, and release notes

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

In `config.php`, update the `define('APP_VERSION', '...')` line to the next patch version, following the exact pattern already in that file.

- [ ] **Step 3: Add a README highlight entry**

In `README.md`, under `## Current Release Highlights`, add a new `### vX.Y.Z` section above the most recent one:

```markdown
### vX.Y.Z

- Fullscreen route point editor on the mission form: draw a route on a large map instead of the small inline one, and remove a wrongly-placed point by clicking its marker directly
```

Also update the `**Version:**` line and the `![Version](...)` badge near the top of `README.md` to match, following the exact pattern used for previous version bumps.

- [ ] **Step 4: Lint, sync, commit**

```bash
C:\xampp\php\php.exe -l config.php
robocopy.exe "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
git add config.php README.md
git commit -m "Bump version for fullscreen route editor release"
```

(Tagging and creating the GitHub release is a separate, explicit step the user triggers — not part of this implementation plan.)
