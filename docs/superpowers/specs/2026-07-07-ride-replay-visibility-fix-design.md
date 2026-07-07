# Ride Replay Visibility Fix

## Purpose

The "Ride Replay" button/modal on `mission-view.php` (animated recap of a completed ride's route, added in v3.81.2) currently never appears for any mission in the live dataset. Root cause, confirmed by direct DB inspection:

1. **Single-day missions**: the button only checks `missions.route_geometry` (the dense, Google-snapped polyline). It never falls back to `missions.route_points` (the sparse waypoints an admin manually draws). Any mission completed without a working Google Maps API key at the time has `route_points` populated but `route_geometry` NULL, so the button never renders — even though there's a perfectly usable route to animate.
2. **Multi-day missions**: route data for these lives entirely in `mission_days` (one row per day, each with its own `route_points`/`route_geometry`/`day_date`). The mission-level `missions.route_geometry` column is never populated for multi-day missions at all, so the existing button — which only reads the mission-level column — can never appear for them, regardless of completion status or how much per-day route data exists.

This spec fixes both so Replay actually shows up when there's real route data to animate.

## Scope

**In scope:**
- Single-day missions: fall back to `route_points` (converted to plain `[lat, lng]` pairs) when `route_geometry` is empty, for both the button's visibility check and the modal's animation data.
- Multi-day missions: one "Ride Replay" button per day, next to the existing day-tab controls (alongside "Πλοήγηση"), each opening a shared modal scoped to that day's own route (geometry-or-points, same fallback rule as above) and that day's own SOS/incident events (filtered by date).
- Refactor `assets/js/ride-replay.js` so its animation logic can be reused for both the existing single-mission modal and the new per-day modal, without duplicating the haversine/scrubber/marker code.

**Out of scope:**
- No database schema changes — all needed data (`route_points`, `route_geometry`, `mission_days.day_date`, `ride_events.created_at`) already exists.
- No change to how routes are drawn, snapped, or stored (`mission-form.php`, Google Routes API integration) — this only changes what Replay reads and displays.
- The public/social share-link feature (separate, upcoming spec) — this fix only affects the in-app, logged-in view.

## Data & Logic Changes

**New helper in `includes/ride-functions.php`:**

```php
function rideReplayPoints(array $geometry, array $points): array {
    if (count($geometry) >= 2) {
        return $geometry;
    }
    $pairs = array_map(fn($p) => [(float)$p['lat'], (float)$p['lng']], $points);
    return count($pairs) >= 2 ? $pairs : [];
}
```

`$geometry` is the output of `rideRouteGeometry()` (dense, road-snapped); `$points` is the output of `normalizeRideRoutePoints()` (sparse waypoints, each `['lat' => ..., 'lng' => ..., 'title' => ..., ...]`). Geometry wins when present (smoother animation); otherwise the raw waypoints are used, and the marker will travel in straight lines between them — the same "estimate" fallback already used elsewhere in the app (e.g. distance/duration) when Google routing isn't available, so this isn't a new UX pattern.

**Single-day mission (`mission-view.php`):** replace the button's condition and the modal's `points` source with `rideReplayPoints($routeGeometry, $routePoints)` instead of `$routeGeometry` directly. No other single-day behavior changes.

**Multi-day missions (`mission-view.php`):** for each `$missionDays` entry (already loaded with `route_points_decoded` and `geometry` per day), compute:
- `replay_points = rideReplayPoints($day['geometry'], $day['route_points_decoded'])`
- `replay_events`: from the mission's existing `$rideEvents` list, keep only events whose `created_at` date matches `$day['day_date']`, then run them through `rideEventRoutePositions($replay_points, $filteredEvents)` (same function already used for the single-mission case) to get each event's position along that day's route.

These two fields are added to the existing per-day JSON blob already sent to the client (`$days` JS array at the multi-day itinerary script) — no new endpoint or query needed, since `$rideEvents` is already fetched once for the whole mission.

**UI:** a "Ride Replay" button appears next to `#dayViewNavBtn` ("Πλοήγηση") in the day-tab header row, shown/hidden by `renderDay(dayNumber)` based on whether that day's `replay_points.length >= 2` (and the mission is `STATUS_COMPLETED` — checked once, since a mission's status doesn't vary per day). Clicking it opens a single shared modal (`#dayReplayModal`, distinct element from the existing single-mission `#rideReplayModal` so both code paths stay independent) pre-loaded with that day's `replay_points`/`replay_events`/`metrics`.

**JS refactor (`assets/js/ride-replay.js`):** extract the existing self-contained logic (haversine distance, play/pause, scrubber, marker/event-marker rendering) into a function `window.initRideReplayInstance(modalEl, data)` that wires up a *fresh* Leaflet map and controls scoped to whatever modal element it's given, tearing down any previous Leaflet map instance on that element first (`if (existingMap) existingMap.remove()`) to avoid Leaflet's "map container is already initialized" error on repeat opens. The file keeps its existing automatic behavior for the single-mission case (call `initRideReplayInstance` once on `DOMContentLoaded` using `#rideReplayModal` + `window.easyRideReplayData`, exactly as today), so the single-day code path doesn't change at all from the user's perspective. The new per-day path calls the same function on each button click, passing `#dayReplayModal` + that day's data computed on the PHP side.

## Testing

No automated test framework exists (project convention). Manual verification:
1. Mission #110 ("Βολταρακι Σητεία!" — completed, single-day, `route_points` set but `route_geometry` NULL): confirm the Ride Replay button now appears and the modal animates a marker in straight lines between the drawn waypoints.
2. Mission #114 (the 2-day Ηράκλειο-Σητεία-Ζηρο mission, completed): confirm a "Ride Replay" button appears next to each day's "Πλοήγηση" button, and clicking each shows that day's own route with only that day's own events (cross-check against `ride_events.created_at` dates for that mission).
3. A mission with real Google-snapped `route_geometry` (e.g. #116, currently OPEN): once it's marked completed, confirm Replay still uses the smooth Google geometry (not the fallback), i.e. the fallback doesn't regress the existing working case.
4. Re-open the per-day modal for a second day after already opening it once for a different day in the same page load — confirm no Leaflet "already initialized" console error (this is the specific regression risk the modal-reuse refactor introduces).
5. `php -l` on all touched files.
