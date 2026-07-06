# Multi-Day Mission Itinerary Display (Sub-project 2 of 3)

## Purpose

Phase 1 (shipped as v3.81.4) added a `mission_days` table and authoring UI in `mission-form.php` so an admin can draw a distinct route + overnight note per calendar day of a multi-day mission. That data is invisible everywhere else — `mission-view.php` shows no route at all for a multi-day mission. This phase (2 of 3) displays the per-day itinerary on `mission-view.php`.

## Scope

This is phase 2 of 3. Explicitly deferred:
- Phase 3: `ride-mode.php`/`ops-dashboard.php` automatically showing the current calendar day's route on the live map.
- Ride Replay (the animated recap feature shipped earlier) staying single-day-only — it is NOT extended to multi-day missions in this phase. A multi-day mission's map card will not show a "Ride Replay" button (mirrors today's gating, which already requires `count($routeGeometry) >= 2` on the mission-level column — naturally false for multi-day missions since that column is NULL).

No changes to `mission-form.php`'s authoring flow (phase 1, already shipped) or to `shifts`/`mission_recurrences` (unrelated).

## Detection & Data Loading

`mission-view.php` already computes `$routePoints`, `$routeMetrics`, `$routeGeometry` from the mission-level columns (lines 31-33). Add:

```php
$missionDays = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number", [$id]);
$isMultiDayMission = !empty($missionDays);
```

For each row in `$missionDays`, compute per-day equivalents of the existing single-mission variables, reusing existing helpers unchanged (since `mission_days` columns mirror `missions` columns exactly, by phase 1's design):

```php
foreach ($missionDays as &$day) {
    $day['route_points_decoded'] = normalizeRideRoutePoints($day['route_points'] ?? '[]');
    $day['metrics'] = rideMissionRouteMetrics($day, $day['route_points_decoded']);
    $day['geometry'] = rideRouteGeometry($day);
}
unset($day);
```

`rideMissionRouteMetrics()` and `rideRouteGeometry()` (in `includes/ride-functions.php`) already read `route_provider`/`route_distance_meters`/`route_duration_seconds`/`route_geometry` by column name from whatever array is passed in — no changes needed to either function, since a `mission_days` row has the same shape as a `missions` row for these fields.

## UI

When `$isMultiDayMission` is true, a new card **"Ημερήσιο Πρόγραμμα"** replaces the current "Χάρτης Διαδρομής"/"Χάρτης Τοποθεσίας" card (`mission-view.php:1072-1102`) and "Χρονοδιάγραμμα Διαδρομής" card (`mission-view.php:1104+`) — both of which continue to render exactly as today when the mission is single-day (`$isMultiDayMission` false).

The new card contains:
- **Day tabs** (Bootstrap nav-pills, same visual pattern as `mission-form.php`'s day tabs): one per day, labeled with the day's title (if set) or "Μέρα N", plus its date.
- **Info bar** below the tabs: the active day's date, and its overnight/accommodation note if set (styled similarly to the existing route metrics badge row).
- **One shared, read-only Leaflet map** (no click handlers — this is a display, not an editor) that redraws with the active day's markers + polyline when a tab is clicked. Falls back to straight lines between stops when no Google-snapped geometry exists for that day, matching the existing single-day map's fallback behavior.
- **Timeline list** of the active day's stops (reusing the existing timeline row markup/style from the current "Χρονοδιάγραμμα Διαδρομής" card) plus distance/duration badges from that day's `metrics`.
- **"Πλοήγηση"/"Google Maps" buttons** scoped to the active day, reusing the existing `buildGoogleMapsDirectionsUrl()` helper against that day's `route_points_decoded`.
- No "Ride Replay" button on this card (see Scope).

## Testing

No automated test framework exists in this repo (project-wide convention). Manual verification:
1. Open a multi-day mission (e.g. recreate one similar to the phase-1 testing mission) and confirm the day tabs appear with correct dates/titles, and the old map/timeline cards are gone.
2. Switch tabs and confirm the map, timeline, distance/duration badges, overnight note, and navigation links all update to match the selected day.
3. Confirm a day with zero route points renders without errors: the map falls back to the mission's own `latitude`/`longitude` marker if set, else a default center (same fallback the single-day map already uses); the timeline list shows an empty-state message; no distance/duration badges are shown for that day.
4. Open an ordinary single-day mission and confirm its map/timeline cards are pixel-for-pixel unchanged from before this phase.
5. Confirm no "Ride Replay" button appears on a multi-day mission's card.
