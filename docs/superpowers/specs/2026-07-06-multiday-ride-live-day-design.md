# Multi-Day Mission — Live "Today's Day" Routing (Sub-project 3 of 3)

## Purpose

Phase 1 (v3.81.4) added the `mission_days` table and authoring UI. Phase 2 (v3.81.5) displays the full day-by-day itinerary on `mission-view.php` via manually-clicked day tabs. Neither `ride-mode.php` nor `ops-dashboard.php` know about `mission_days` yet — both only read the mission-level `route_points`/`route_geometry`/etc. columns, which are left NULL for multi-day missions (by phase 1's design). Today, a multi-day mission shows **no route at all** in Ride Mode or on the Ops Dashboard.

This phase (3 of 3) makes `ride-mode.php` and `ops-dashboard.php` automatically resolve and display **today's calendar day's route** for a multi-day mission, based on the server date. This is a live/"now" view, unlike phase 2's manually-selected day tabs.

## Scope

This is phase 3 of 3, closing out the multi-day mission itinerary project. Explicitly out of scope:
- `mission-view.php`'s day tabs (phase 2) are unchanged — that remains a manually-browsed view of all days, defaulting to day 1.
- Ride Replay stays single-day-only (unchanged from phase 2's decision).
- No manual day switcher added to `ride-mode.php` or `ops-dashboard.php` — both auto-select today's day only, per the roadmap's phrasing ("πρέπει να επιλέγουν αυτόματα").

## Design

### New helper: `resolveActiveMissionDayRoute()`

Added to `includes/ride-functions.php`:

```php
function resolveActiveMissionDayRoute(array $mission): array {
    $missionDays = dbFetchAll(
        "SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number",
        [$mission['id']]
    );

    if (empty($missionDays)) {
        return $mission + ['is_multiday' => false, 'active_day_label' => null];
    }

    $today = date('Y-m-d');
    $activeDay = null;
    foreach ($missionDays as $day) {
        if ($day['day_date'] === $today) {
            $activeDay = $day;
            break;
        }
    }
    if ($activeDay === null) {
        $activeDay = $today < $missionDays[0]['day_date']
            ? $missionDays[0]
            : $missionDays[count($missionDays) - 1];
    }

    $label = ($activeDay['title'] ?: 'Μέρα ' . (int)$activeDay['day_number'])
        . ' · ' . date('d/m', strtotime($activeDay['day_date']));

    return array_merge($mission, [
        'route_points'            => $activeDay['route_points'],
        'route_geometry'          => $activeDay['route_geometry'],
        'route_distance_meters'   => $activeDay['route_distance_meters'],
        'route_duration_seconds'  => $activeDay['route_duration_seconds'],
        'route_provider'          => $activeDay['route_provider'],
        'is_multiday'             => true,
        'active_day_label'        => $label,
    ]);
}
```

**Fallback rule** (today outside the mission's day range — e.g. opened before the trip starts or after it ends, due to timezone/delay): clamp to the nearest day — day 1 if today is before the range, the last day if today is after it.

Because `mission_days` columns mirror `missions` columns exactly (phase 1's design), no changes are needed to `normalizeRideRoutePoints()`, `rideMissionRouteMetrics()`, `rideRouteGeometry()`, or `buildRideDirectionsUrl()` — they keep reading route columns by key off whatever array they're handed, single-day mission row or resolved multi-day copy alike.

### `ride-mode.php`

Immediately after `$mission = getRideMission($missionId)`:

```php
$mission = resolveActiveMissionDayRoute($mission);
```

Every subsequent line (`$routePoints`, `$routeMetrics`, `$routeGeometry`, `$directionsUrl`) is unchanged — they already read off `$mission`.

In the template, the "Χρονοδιάγραμμα" card header shows a small badge with `$mission['active_day_label']` when `$mission['is_multiday']` is true:

```php
<h5>...Χρονοδιάγραμμα...
    <?php if ($mission['is_multiday']): ?>
        <span class="badge bg-light text-dark border"><?= h($mission['active_day_label']) ?></span>
    <?php endif; ?>
</h5>
```

No other change to `ride-mode.php`. Single-day missions: `resolveActiveMissionDayRoute()` is a pass-through, zero behavior change.

### `ops-dashboard.php`

In the existing `foreach ($missions as $m)` loop that builds `$mapPins`/`$routeTimelineMissions` (around line 468), add as the first line inside the loop:

```php
$m = resolveActiveMissionDayRoute($m);
```

Everything below it (route point decoding, `$routeTimelineMissions[]`/`$mapPins[]` construction) is unchanged, since it already reads `$m['route_points']` etc.

Add `'day_label' => $m['is_multiday'] ? $m['active_day_label'] : null` to the `$routeTimelineMissions[]` entry. In the route-timeline card's mission-title row (around line 737), render it as a small badge next to the mission title when set:

```php
<a href="mission-view.php?id=<?= $routeMission['id'] ?>" class="fw-semibold text-decoration-none route-mission-title">
    <?= h($routeMission['title']) ?>
</a>
<?php if ($routeMission['day_label']): ?>
    <span class="badge bg-light text-dark border"><?= h($routeMission['day_label']) ?></span>
<?php endif; ?>
```

This loop runs over all dashboard missions (active + upcoming) — independent of the earlier `$activeMissions`/`$upcomingMissions` grouping, which doesn't render route data — so no other part of the dashboard is affected.

## Edge Cases

- A "rest day" with zero route points for the resolved day: `normalizeRideRoutePoints()`/decoding already handles an empty `route_points` gracefully — `ride-mode.php` shows "Δεν έχει οριστεί διαδρομή", `ops-dashboard.php` simply doesn't add that mission to `$routeTimelineMissions` and (if it also has no `latitude`/`longitude`) doesn't add a map pin either — identical to today's existing empty-route behavior for single-day missions.
- Single-day missions: no `mission_days` rows exist, so `resolveActiveMissionDayRoute()` always takes the pass-through branch.

## Testing

No automated test framework exists in this repo (project-wide convention). Manual verification:
1. Take an existing multi-day test mission (or create one spanning today plus adjacent days) with a distinct route drawn on each day via `mission-form.php`.
2. Open `ride-mode.php` for that mission and confirm the map/timeline/distance-duration badges/"Πλοήγηση" link match **today's** day, with the day badge shown next to "Χρονοδιάγραμμα".
3. Open `ops-dashboard.php` and confirm the same mission's map pin/route polyline and route-timeline entry reflect today's day, with the day badge next to its title.
4. Temporarily adjust a test mission's `mission_days.day_date` rows so today falls before the first day (or after the last) and confirm the fallback clamps to day 1 (or the last day) instead of showing nothing.
5. Confirm an ordinary single-day mission's Ride Mode and Ops Dashboard views are pixel-for-pixel unchanged (no day badge appears).
