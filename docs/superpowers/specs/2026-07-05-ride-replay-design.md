# Ride Replay — Design

## Purpose

After a mission/ride is completed, let members watch an animated recap of the route inside EasyRide: a marker travels the recorded path with live distance/time counters and markers for key ride events (stops, SOS). In-app only for now — no external sharing link.

## Scope

- Applies to missions where `status` is completed/closed AND `route_geometry` (from the existing Google Routes API / straight-line fallback pipeline) has at least 2 points.
- No new database tables. No new page/route. No public/unauthenticated sharing.
- Real GPS pings (`volunteer_pings`) are NOT used as the source of truth — the replay animates along the stored `route_geometry` only. This means playback speed is a constant, linear interpolation over time, not a reconstruction of actual moment-to-moment speed. This is a deliberate simplification and is called out to the user in review.

## Future Consideration: Public Share Link (not in this scope)

The user wants a follow-up phase where a Ride Replay can be published via a public, no-login link for social media sharing. Not designed or built now, but this design is kept compatible with it:

- The data-prep step (route + annotated events → JSON) is written as a self-contained server-side step, not tangled into page-specific auth checks, so it can later be re-exposed behind a public token-based endpoint without rewriting it.
- `ride-replay.js` takes its data as a plain JS object/JSON, not tied to being inline-rendered — so a future public page can feed it the same shape of data fetched via a public URL instead of an inline `<script>` block.
- Out of scope for now: share tokens, a public route, expiry/revocation, rate limiting, and hiding any sensitive info (e.g. member names) from a public audience — all of that is a separate spec when that phase starts.

## Entry Point

- New button "🎬 Ride Replay" rendered in `mission-view.php`, in the same area as the existing route/map card.
- Visibility condition: mission status is completed/closed AND `count($routeGeometry) >= 2`.
- Clicking opens a Bootstrap modal (`#rideReplayModal`) containing the replay map and controls. No new page.

## Data Flow

### Server-side (mission-view.php, at render time)

Reuses variables already computed on the page:
- `$routeGeometry` — from `rideRouteGeometry($mission)` (line ~33), array of `[lat, lng]` pairs already in Leaflet-ready order.
- `$routeMetrics` — from `rideMissionRouteMetrics($mission, $routePoints)` (line ~32): `distance_meters`, `distance_label`, `total_minutes`, `total_label`.

New work:
1. Call existing `getRideEvents($missionId)` (in `includes/ride-functions.php`), filter to events where `lat`/`lng` are not null.
2. New helper function in `includes/ride-functions.php`:
   ```php
   function rideEventRoutePositions(array $routeGeometry, array $events): array
   ```
   For each event, finds the nearest point on the `$routeGeometry` polyline (simple nearest-vertex search, no need for perpendicular-projection precision given this is a visual recap, not a navigation tool) and returns the cumulative-distance fraction (0.0–1.0) along the route at that vertex. Returns the input events array with an added `route_fraction` key per event (events that can't be matched, e.g. empty geometry, get `route_fraction = null` and are skipped client-side).
3. All of the above (`routeGeometry`, `routeMetrics` fields, annotated events) are serialized into one inline JSON block via `json_encode(..., JSON_UNESCAPED_UNICODE)`, following the exact existing pattern at mission-view.php:2294-2295 (a plain `var easyRideReplayData = {...}` in an inline `<script>` tag, no AJAX call).

### Client-side (new file: `assets/js/ride-replay.js`)

- Exposes a single init function, e.g. `initRideReplay(data)`, called from an inline script in mission-view.php when the modal markup is present (mirrors how the existing static map is initialized inline).
- On `shown.bs.modal`:
  - Create Leaflet map in the modal's map container (reuses the Leaflet JS/CSS already loaded on the page for the static map — no duplicate `<script src>` tags).
  - Draw the full route as a dimmed polyline (reuse existing style conventions: blue, lower opacity).
  - Place event pins at their `route_fraction` position along the route, initially in a muted/inactive style, with a small icon per `event_type`/`severity`, matching the icon/color mapping already used for `ride_events` in `ops-dashboard.php`'s incident feed.
  - Place the animated marker at the route start.
  - Call `map.invalidateSize()` after a short delay (standard Leaflet-in-modal fix, same as the existing `setTimeout(() => map.invalidateSize(), 150)` pattern at mission-view.php:2339).
- Animation:
  - Fixed total playback duration: 25 seconds, regardless of the ride's real duration.
  - `requestAnimationFrame` loop tracks elapsed animation time; `elapsedFraction = min(1, animElapsedMs / 25000)`.
  - Marker position = point at `elapsedFraction * totalRouteDistance` along the cumulative-distance-indexed `route_geometry` (linear interpolation between the two bracketing vertices).
  - Live counters update each frame: `km so far = elapsedFraction * routeMetrics.distance_meters`, `time so far = elapsedFraction * routeMetrics.total_minutes`, both formatted with the same label helpers' conventions used elsewhere (e.g. "X.X χλμ", "X λεπτά").
  - Each event pin whose `route_fraction <= elapsedFraction` switches from muted to highlighted style (one-way; no un-highlighting on scrub-back, kept simple).
- Controls (in the modal, below the map):
  - Play/Pause toggle button.
  - Scrub slider (`<input type="range" min="0" max="1" step="0.001">`) two-way bound to `elapsedFraction`: dragging it seeks the animation; playing updates the slider position.
  - Restart button (resets `elapsedFraction` to 0, re-mutes all event pins, keeps paused/playing state).
- Cleanup: on `hidden.bs.modal`, cancel the active `requestAnimationFrame` and destroy the Leaflet map instance (`map.remove()`) so reopening the modal starts fresh and no timer keeps running in the background.

## Edge Cases

- Route with exactly 2 points (start/end only, no intermediate stops): animation still works — it's just a straight-line interpolation between two points.
- Zero matching ride_events: replay still works, just marker + route with no pins.
- Mission has `route_geometry` but is not yet completed: button is not rendered (entry-point condition covers this).

## Testing / Verification

No JS test runner exists in this repo (plain PHP + vanilla JS, no build step). Verification will be manual:
1. Open a completed mission that has a route with recorded `route_geometry` in the browser.
2. Confirm the "Ride Replay" button appears, opens the modal, and the map renders (no grey-tile bug).
3. Play the animation end-to-end; confirm counters reach the full total distance/time at the end.
4. Confirm event pins (if any exist on that mission) highlight at roughly the right point in playback.
5. Confirm scrub slider and restart work, and that closing/reopening the modal doesn't leak animation frames or duplicate map instances.
