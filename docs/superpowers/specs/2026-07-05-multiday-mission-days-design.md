# Multi-Day Mission Days — Data Model & Authoring (Sub-project 1 of 3)

## Purpose

EasyRide has no concept today of a single mission spanning multiple calendar days with a different route/itinerary each day (e.g. a 3-day motorcycle trip). This is unrelated to `shifts` (a legacy single-day-split-in-two concept from the app's rescue-team origin, never used that way for motorcycle rides) and unrelated to `mission_recurrences` (which creates several *separate* missions on different dates, e.g. "every Saturday"). This spec covers only the first of three planned phases: the data model and the mission-form.php authoring UI for drawing a distinct route + overnight notes per day of a multi-day mission.

## Scope

This is phase 1 of 3. Explicitly deferred to later phases (separate specs/sessions/releases):
- Phase 2: displaying the day-by-day itinerary on `mission-view.php`.
- Phase 3: `ride-mode.php`/`ops-dashboard.php` automatically showing the current calendar day's route on the live map.

**Known limitation accepted for this phase:** once a multi-day mission is created via this phase's UI, `mission-view.php`, `ride-mode.php`, and `ops-dashboard.php` will show it as if it has no route at all (since they only read the mission-level `route_points`/`route_geometry`, which stay empty for multi-day missions — see Data Model). This is intentional and accepted; the user chose to ship phase-by-phase rather than build all three phases before anything is usable.

No changes to `shifts` or `mission_recurrences` — both are unrelated existing features.

## Data Model

New table, additive only (no changes to `missions` table structure):

```sql
CREATE TABLE IF NOT EXISTS mission_days (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mission_id INT UNSIGNED NOT NULL,
    day_number TINYINT UNSIGNED NOT NULL,
    day_date DATE NOT NULL,
    title VARCHAR(160) NULL,
    overnight_notes TEXT NULL,
    route_points MEDIUMTEXT NULL,
    route_geometry MEDIUMTEXT NULL,
    route_distance_meters INT UNSIGNED NULL,
    route_duration_seconds INT UNSIGNED NULL,
    route_provider VARCHAR(40) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mission_day (mission_id, day_number),
    CONSTRAINT fk_mission_days_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

The route-related columns deliberately mirror the existing `missions` table's `route_points`/`route_geometry`/`route_distance_meters`/`route_duration_seconds`/`route_provider` columns exactly, so phases 2/3 can reuse the existing `rideRouteGeometry()`/`rideMissionRouteMetrics()` logic (in `includes/ride-functions.php`) against a `mission_days` row instead of a `missions` row with minimal changes.

**Multi-day detection:** derived automatically from the mission's existing `start_datetime`/`end_datetime` fields — if their calendar dates differ, the mission is multi-day. There is no separate "is this multi-day?" checkbox or column. A single-day mission (the overwhelming majority) never gets any `mission_days` rows and its `missions.route_points`/`route_geometry`/etc. columns are used exactly as today — zero behavior change, zero migration risk for existing missions.

For a multi-day mission, `missions.route_points`/`route_geometry`/`route_distance_meters`/`route_duration_seconds`/`route_provider` are left NULL; all route data lives in `mission_days` rows instead.

## Authoring UI (mission-form.php)

- Start/End date fields work exactly as today. As soon as their calendar-date span covers more than one day, a new card **"Πολυήμερη Διαδρομή"** replaces the current single "Διαδρομή Δράσης" card (which continues to render unchanged for single-day missions — no visual change to the common case).
- The multi-day card shows one pill/tab per day in the span (e.g. "Μέρα 1", "Μέρα 2 · Σαβ 12/09", ...), computed from the date span — not manually configured.
- Each day's tab contains the same route editor already shipped this session (small inline map + "Μεγάλος χάρτης" fullscreen modal + point list with marker-click delete), scoped to that day's own route points, plus a new optional **"Διανυκτέρευση"** textarea for overnight/accommodation notes.
- Switching tabs saves the currently-active day's in-memory route points and loads the newly-selected day's — reusing the existing `mapInstances`/`renderRoute` machinery from this session's fullscreen-editor work; `routePoints` becomes "whichever day is currently active" rather than a single global array.
- Google Routes API snapping (`scheduleRouteFetch`) fires per active day when that day's points change, same debounce behavior as today, just scoped to the day currently being edited.
- Shrinking the date range (fewer days) simply drops the extra days' tabs and their data on next save — no confirmation prompt in this phase, kept simple.
- Editing an existing multi-day mission loads its existing `mission_days` rows into the matching tabs.

## Save Logic (mission-form.php backend)

- Single-day mission: unchanged — exact same `INSERT`/`UPDATE` into `missions` as today.
- Multi-day mission: the `missions` row is saved with its own route columns left NULL, then one row is inserted into `mission_days` per day (day_number, day_date computed from `start_datetime` + offset, optional title, optional overnight_notes, and that day's route_points/route_geometry/distance/duration/provider).
- Editing a multi-day mission: `DELETE FROM mission_days WHERE mission_id = ?` then re-insert all current days — simpler and safe (cascades via FK) given the small number of rows involved (a handful of days per mission), avoiding diff/merge logic.
- Each day's `route_points` is validated through the existing `normalizeRoutePointsJson()` helper, applied per day.

## Edge Cases

- A day with zero route points is allowed (e.g. a rest day with no ride).
- Extending a single-day mission into a multi-day one (edit): new empty day tabs appear; the original single-day route is **not** automatically copied into Day 1 — the admin redraws it. This is an accepted limitation for this phase, revisit later if it becomes a real pain point.
- Shrinking a multi-day mission back to a single day (edit): its `mission_days` rows are deleted on the next save, and it reverts to being a normal single-day mission using the mission-level route columns again (which the admin fills in via the normal single-day route editor at that point).

## Testing

No automated test framework exists in this repo (project-wide, confirmed convention from prior sessions this week). Verification is manual in the browser:
1. Create a mission with start/end spanning 3 calendar days — confirm the "Πολυήμερη Διαδρομή" card with 3 day tabs appears instead of the single route card.
2. Draw a different route + overnight note on each day tab, including leaving one day with zero points; save; confirm `mission_days` has the correct 3 rows via a DB check.
3. Edit that mission and confirm each tab reloads its saved route/overnight note correctly.
4. Shrink the date range to 1 day, save, confirm `mission_days` rows are gone and the mission behaves as a normal single-day mission again.
5. Create/edit an ordinary single-day mission and confirm its form/behavior is pixel-for-pixel unchanged from before this feature.
