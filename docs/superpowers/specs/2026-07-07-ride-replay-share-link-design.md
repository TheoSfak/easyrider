# Ride Replay — Public Share Link

## Purpose

Let a mission manager generate a public, no-login URL for a completed mission's Ride Replay, so it can be shared outside EasyRide (social media, WhatsApp) without the recipient needing an account.

This was anticipated (but explicitly deferred) in the original [Ride Replay design](2026-07-05-ride-replay-design.md) and tracked in `ROADMAP.md`'s "Μένει να γίνει" section.

## Scope

**In scope:**
- One share link per mission, covering the whole mission (all days, for multi-day missions) — not one link per day.
- Generating and revoking the link is restricted to mission managers: `hasPagePermission('missions_manage')` (the same gate used for other manage-only actions in `mission-view.php`).
- No expiry — a link is valid indefinitely until a manager revokes it.
- Public page content: mission title, completion date, the animated route with event pins (title + severity only, no member names — this is already the shape of data the in-app Replay uses), distance/time counters, and a small "Powered by EasyRide" footer credit.
- Autoplay: the animation starts automatically when the public page loads.
- Multi-day missions: a simple day switcher on the public page (mirrors the in-app day tabs, minus the "Πλοήγηση" button, which is an internal-only action).

**Out of scope:**
- Per-day share links (rejected in favor of one link per mission).
- Link expiry / time-limited access.
- Any additional mission content beyond title/date/route/counters/events — no description, no location text, no participant info.
- Changes to how routes are recorded, snapped, or stored.

## Data Model

New column:

```sql
ALTER TABLE missions ADD COLUMN replay_share_token VARCHAR(64) NULL UNIQUE;
```

Added via a new migration entry in `includes/migrations.php`, bumping `DB_SCHEMA_VERSION` to 80 in `config.php`.

Token format: `bin2hex(random_bytes(32))` — the same pattern already used for `shifts.qr_token` (see `shift-view.php`). `UNIQUE` guards against collision; given 32 random bytes, a collision is astronomically unlikely and is not retried — a collision would simply fail the generating INSERT/UPDATE, surfacing as a generic error the manager can retry by clicking the button again.

## Generate / Revoke UI (`mission-view.php`)

- New button `🔗 Δημόσιο Link` in the mission header action row (next to the existing "Επεξεργασία" button), gated on `$canManageMissions`.
- Visibility condition: mission has usable replay data available at all — single-day: `count($replayPoints) >= 2`; multi-day: at least one day with `count($day['replay_points']) >= 2`. This is independent of current mission status (see "Public page status re-check" below for why).
- Clicking opens a modal (`#replayShareModal`):
  - If `missions.replay_share_token` is NULL: shows a "Δημιουργία Link" button. Submits a POST (CSRF-protected) that generates and stores the token, then re-renders the modal showing the resulting URL.
  - If a token exists: shows the full public URL (`replay-share.php?token=...`) in a read-only text input with a "Copy" button (plain `navigator.clipboard.writeText`, a small new inline script — no existing copy-to-clipboard helper exists elsewhere in the app to reuse), plus an "Ανάκληση" (revoke) button.
  - Revoke: POST (CSRF-protected) that sets `replay_share_token = NULL`. After revoking, the modal reverts to the "no token yet" state.
- All three actions (`generate`, `regenerate-after-revoke`, `revoke`) are handled inline in `mission-view.php`'s existing POST-handling block (following the file's established pattern of one `if (isPost() && post('action') === '...')` branch per action), gated again server-side on `$canManageMissions` (not just UI visibility) and requiring `$mission['status'] === STATUS_COMPLETED` for **generation** (revoking is always allowed regardless of status, in case a manager wants to pull down a link on a mission that's no longer completed).

## Public Page (`replay-share.php`)

New standalone file, structured like `verify-email.php`: no `bootstrap.php`-driven header/footer include, no session/login requirement, its own full `<html>`/`<head>` with Bootstrap, Bootstrap Icons, and Leaflet 1.9.4 loaded directly from the same CDNs already used elsewhere in the app (`unpkg.com/leaflet@1.9.4`).

- `<meta name="robots" content="noindex,nofollow">` so shared missions never appear in search results.
- Reads `?token=`, looks up `SELECT * FROM missions WHERE replay_share_token = ?`.
  - **Not found:** generic "Μη έγκυρος σύνδεσμος" page (visually mirrors `verify-email.php`'s invalid-token state).
  - **Found, but `status !== STATUS_COMPLETED`:** "Αυτό το Ride Replay δεν είναι διαθέσιμο αυτή τη στιγμή" page. The token is left intact — no need to regenerate once the mission is completed (or re-completed) again; the page simply starts working again automatically. This covers the edge case where a manager reopens a mission after already sharing its link.
  - **Found and completed:** proceeds to render the replay.
- Data assembly reuses the exact existing helpers from `includes/ride-functions.php` and the per-day computation pattern already in `mission-view.php` — no new data-shaping logic:
  - Single-day: `rideReplayPoints($routeGeometry, $routePoints)`, `rideMissionRouteMetrics(...)`, `buildReplayEvents($replayPoints, $rideEvents)` (this helper currently lives inline in `mission-view.php`; it moves to `includes/ride-functions.php` so both files can call it — a small, targeted refactor, not a redesign).
  - Multi-day: one payload per `mission_days` row, exactly as `mission-view.php` already computes for its own per-day JSON blob (`replay_points`, `replay_events`, metrics), filtered to that day's `ride_events` by date.
- Rendering:
  - Single-day mission: one inline map (no modal wrapper — the page's only content is the replay), autoplaying on load.
  - Multi-day mission: a pill/tab row above the map, one pill per day (label + date, no "Πλοήγηση" button), defaulting to day 1; switching pills swaps the loaded day's data into the same map instance and restarts playback for that day.
  - Mission title and formatted completion date shown above the map; "Powered by EasyRide" (app name from settings) shown below.

## Client JS Changes (`assets/js/ride-replay.js`)

`initRideReplayController(options)` gets two new optional flags, both defaulting to falsy so the two existing call sites (single-mission modal, per-day modal) are unaffected:

- `options.standalone` (bool): when true, skip the `shown.bs.modal` / `hidden.bs.modal` event bindings entirely (there is no Bootstrap modal on the public page) and instead run the map-init/reset logic immediately when `initRideReplayController` is called.
- `options.autoplay` (bool): when true, call `setPlaying(true)` right after the initial `reset()` in standalone mode.

No other logic in the file changes — the haversine/scrubber/marker/event-highlight code is identical between the in-app and public-page paths.

## Security Notes

- Token is a 256-bit random value in the URL path/query — not guessable, not sequential (unlike a mission ID).
- No member names, emails, or other PII are exposed — `buildReplayEvents` already strips events down to `lat`/`lng`/`title`/`severity`/`routeFraction`; the `title` field is a generic status label ("Εκτός διαδρομής", "Ακίνητος"), never a name (names appear only in the separate, never-exposed `message` field).
- Generation/revocation both require CSRF tokens and the `missions_manage` permission, checked server-side (not just hidden via UI).
- The public page performs no writes and requires no CSRF (read-only GET page, like `verify-email.php`'s read path).

## Edge Cases

- **Revoke while someone has the page open:** no live invalidation of an already-loaded page (the JSON payload was already delivered to the browser) — next page load (refresh or new visit) 404s. Same tradeoff as any static content becoming unshared; not solved here.
- **Collision on token generation:** covered above — effectively impossible, not retried.
- **Mission's replay data changes after a link exists** (e.g. an admin edits the route): the public page always reads live data at request time, so it reflects the current route, not a snapshot from when the link was generated.
- **Multi-day mission where only some days have usable replay data:** the day switcher only lists days with `count(replay_points) >= 2`; if that leaves zero days, the public page falls back to the same "not available" state as an incomplete mission.

## Testing / Verification

No automated test framework exists in this repo (project convention: manual verification only).

1. `php -l` on all touched/new files (`replay-share.php`, `mission-view.php`, `includes/ride-functions.php`, `includes/migrations.php`).
2. Generate a share link for a completed single-day mission (manager role); open the resulting URL in a private/incognito browser window (no session cookie); confirm the page loads, autoplays, shows title/date/counters/events, and requires no login.
3. Generate a share link for a completed multi-day mission; confirm the day switcher lists the right days and switching days reloads the correct route/events for that day.
4. Revoke the link; confirm the same URL now shows the "invalid link" page.
5. As a non-manager (regular member) viewing a mission, confirm the "🔗 Δημόσιο Link" button is not shown.
6. Generate a link, then change the mission's status away from completed (e.g. reopen); confirm the public URL shows "not available right now"; mark it completed again and confirm the same URL works again without regenerating.
7. Confirm the existing in-app single-mission and per-day Replay modals still work unchanged (regression check on the `initRideReplayController` extension).
