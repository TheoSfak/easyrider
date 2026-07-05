# Fullscreen Route Point Editor — Design

## Purpose

The route-drawing map on the mission form (`mission-form.php`) is a small 320px-tall inline Leaflet map. Drawing an accurate multi-stop route on it is cramped. This adds a fullscreen modal with a large map so an admin can draw the route comfortably, plus the ability to delete a wrongly-placed point by clicking directly on its marker (in addition to the existing per-point delete button in the point list).

## Scope

- Applies only to `mission-form.php` (create/edit mission route drawing).
- No new database columns, no new page, no change to how `route_points`/`route_geometry` are submitted or stored.
- Detailed per-point fields (title, scheduled time, stop minutes, notes) continue to be edited only in the existing point list below the small map (`#routePointList`, `mission-form.php:499`) — unchanged. The fullscreen modal is focused on drawing/removing/reordering points, not editing their details.
- No changes to the Google Routes API snapping logic (`scheduleRouteFetch`, `mission-form.php:751`) — it keeps triggering the same way regardless of which map (small or fullscreen) changed `routePoints`.

## Current Behavior (reference)

- `routePoints` (`mission-form.php:725`) is a single in-memory array of `{lat, lng, title, scheduled_time, stop_minutes, notes}`, shared by all rendering.
- `initRouteEditor()` (`mission-form.php:1054`) creates the one Leaflet map (`routeMap`), binds a `click` handler that always **appends** a new point (`mission-form.php:1072-1082`), and wires the existing Undo (`routeUndoBtn`) / Clear (`routeClearBtn`) buttons.
- `renderRoute(fitBounds, skipListRender)` (`mission-form.php:1010`) clears and redraws all markers/polyline on `routeMap`, calls `syncRouteInput()` (writes `routePoints` into the hidden `#route_points` input and triggers re-routing if needed), and re-renders the point list unless skipped.
- `renderRoutePointList()` (`mission-form.php:932`) renders the below-map list of point rows, each with a working "Αφαίρεση σημείου" (X) button that does `routePoints.splice(index, 1); renderRoute(false);` (`mission-form.php:984-987`).

## Approach

Add a second Leaflet map instance inside a Bootstrap `modal-fullscreen`, reading and writing the same `routePoints` array. `renderRoute()` is generalized to redraw whichever map instances currently exist (small map, fullscreen map, or both), so both stay in sync automatically — no separate sync logic needed. When the fullscreen modal closes, the small map re-renders to reflect any changes made in fullscreen.

This was chosen over moving the single map DOM node in and out of the modal (fragile with Leaflet's internal sizing/event state) or a pure CSS fullscreen toggle of the existing map (doesn't give the two-pane layout with the compact point panel that was decided during design).

## UI

**Trigger:** A new "Μεγάλος χάρτης" button next to the existing Αναίρεση/Καθαρισμός buttons (`mission-form.php:488-496`), opening `#routeFullscreenModal`.

**Modal layout:** `modal-fullscreen`, two-pane:
- **Left (majority of width):** the fullscreen Leaflet map. This is the primary surface — the pane split favors mapping space over the list, per explicit direction that comfortable route drawing is the priority.
- **Right (~200px fixed width):** a compact point panel — one row per point showing only: index number, a small text input bound to `title` (so points can still be labeled while drawing), and a red "✕" remove button. No time/stop-minutes/notes fields here (those stay in the existing below-map list, unchanged).
- **Modal header/toolbar:** Undo (removes last point), Clear (removes all points), a point-count badge, and the modal close button. Undo/Clear call the same logic as the existing buttons, just also refreshing the fullscreen map.

**Map interaction inside the modal:**
- Click on empty map area → appends a new point (same behavior as the small map today).
- Click on an existing point's marker → opens a Leaflet popup with a "Διαγραφή σημείου" button; clicking it removes that point (`routePoints.splice(index, 1)`) and re-renders. This is the new capability — today, removing a specific (non-last) point is only possible via the list's X button.
- `scrollWheelZoom: true` for the fullscreen map (the small map keeps `scrollWheelZoom: false`, unchanged, since it sits inline in a scrolling form).

**Responsive:** On narrow viewports the two panes stack (map on top, point panel below), via CSS only — no JS branching.

## Data Flow / Sync

- `routePoints` remains the single source of truth (no duplication).
- `renderRoute()` is changed to loop over an array of active map "instances" (each with its own Leaflet map object, marker list, and polyline reference) and redraw all of them, instead of hard-coding the one `routeMap`/`routeMarkers`/`routeLine` variables. The fullscreen map registers itself as a second instance only while the modal is open.
- `syncRouteInput()`, `scheduleRouteFetch()`, and all Google-routing state (`routedGeometry`, `routedProvider`, etc.) are untouched — they already operate on `routePoints` directly, independent of which map triggered the change.
- On `shown.bs.modal`: initialize the fullscreen Leaflet map on first open (or just `invalidateSize()` on subsequent opens), fit bounds to current points.
- On `hidden.bs.modal`: no special teardown is required for correctness (the small map already reflects the same `routePoints` and gets its own `renderRoute` call whenever a change happens), but the map instance is removed (`map.remove()`) so reopening doesn't leak a stale Leaflet instance, mirroring the cleanup convention used by the Ride Replay modal shipped earlier this session.

## Edge Cases

- Opening the modal with zero points: map centers on the saved/parsed location if available, else the existing `defaultCenter` fallback — same logic `initRouteEditor()` already uses.
- Removing the last remaining point (via marker-click or list X) leaves an empty route; both maps and the list reflect zero points, matching today's "Clear" behavior.
- Rapid open/close of the modal must not accumulate duplicate Leaflet map instances or duplicate click handlers — enforced by the `map.remove()` teardown above and re-creating the map fresh on each `shown.bs.modal`.

## Testing

No automated test framework exists in this repo (project-wide, confirmed convention). Verification is manual in the browser:
1. Open the mission form, click "Μεγάλος χάρτης" — fullscreen modal opens with a large map and empty/populated point panel matching current `routePoints`.
2. Click empty map area — point appears in both the fullscreen map, the compact panel, and (after closing) the small map and full list.
3. Click an existing marker — popup appears with a delete button; clicking it removes the point everywhere.
4. Use Undo/Clear inside the modal — both maps and the list update consistently.
5. Close and reopen the modal repeatedly — no visual glitches, no duplicate markers, no console errors.
6. Submit the form — `route_points` hidden input still contains the correct, current point list, and (if a Google Maps API key is configured) route snapping still fires as before.
