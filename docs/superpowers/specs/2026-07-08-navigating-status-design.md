# "Στην Πλοήγηση" Status — Design

## Purpose

When a rider taps "Πλοήγηση" in Ride Mode, Google Maps opens in a new tab and the EasyRide tab is backgrounded. Mobile browsers throttle or suspend JavaScript in backgrounded tabs, so the rider's GPS broadcast (`navigator.geolocation.watchPosition`) silently stalls. Today the only visible symptom is that the rider eventually shows up as generic "Stale" on the Επιχειρησιακό (ops) dashboard and in Ride Mode's own participant list — indistinguishable from an actual problem (phone died, app crashed, rider genuinely lost signal).

This spec adds a live "🧭 Στην πλοήγηση" indicator so the mission's responsible person can tell "nothing is wrong, they just opened Google Maps" apart from "something may actually be wrong" — without adding any friction for the rider and without leaving a permanent record.

## Scope

**In scope:**
- A `navigating_since` timestamp set the moment a rider taps "Πλοήγηση" in Ride Mode (the live-ride screen only — not the pre-ride planning links on `mission-view.php`, which aren't tied to an active rider on the road).
- Surfacing this as a distinct, reassuring status wherever staleness is already shown (`api-ride-data.php`'s participant feed, consumed by `ride-mode.php` in both rider and Controller/"Ride Control" mode — the mission's responsible person watches the same page, just with `$isController` enabling extra affordances, not a separate page).
- Suppressing the app's existing automatic "stale" `ride_events` logging (see Background below) while a rider is in this navigating state, so no permanent record is created purely because someone used Google Maps.
- A passive, non-blocking "welcome back" message in Ride Mode when the rider's own tab regains visibility after a real gap in their GPS signal — reminding them to keep the screen open, without a push notification.

**Out of scope:**
- Any push notification nudging the rider back mid-navigation. Rejected: the rider is on a motorcycle, actively navigating — a notification they can't safely act on is a distraction risk, not a safety win. If a "come back" push is wanted later for long absences (15–20+ min, i.e. likely stopped, not riding), that's a separate future spec.
- Changing how `watchPosition`/Wake Lock actually behave in the background. This is a real mobile-browser platform limitation; this spec is about *visibility* of the resulting gap, not eliminating it.
- The "Πλοήγηση" links on `mission-view.php` (pre-ride map/timeline cards, multi-day nav button) — those are viewed by admins/planners, not necessarily by someone currently riding.

## Background: existing auto-stale-logging (discovered during design)

`api-ride-data.php:117-259` already computes `is_stale` per participant (`last_ping_age_seconds > 120`, i.e. **2 minutes** — this is the canonical staleness threshold used everywhere in the app, reused by this spec) and, at `api-ride-data.php:239-257`, automatically calls `recordRideEvent()` for every stale participant, deduplicated by a 5-minute fingerprint bucket. Left alone, this means a rider on a 20-minute Google Maps detour would still accumulate several permanent "Χωρίς πρόσφατο στίγμα" entries in the mission's `ride_events` — directly contradicting the "no permanent record" requirement for the navigating state. This spec's server-side change must classify a navigating rider *before* that `elseif ($participant['is_stale'])` branch, so navigating participants skip event-logging entirely rather than falling through to it.

## Data Model

New column:

```sql
ALTER TABLE participation_requests ADD COLUMN navigating_since TIMESTAMP NULL AFTER field_status_updated_at;
```

Added via a new migration (version 81, idempotent column-exists check, matching the existing pattern in `includes/migrations.php`), bumping `DB_SCHEMA_VERSION` to 81 in `config.php`.

`navigating_since` is set to `NOW()` each time the rider taps "Πλοήγηση" (re-tapping just refreshes it — no special-casing needed). It is never explicitly cleared by the client; it becomes irrelevant on its own once a fresh GPS ping arrives (see below), so there's no separate "I'm back" signal to wire up.

## Server-Side Flow

### New endpoint: `api-ride-navigate.php`

Modeled directly on `api-ride-ping.php`'s structure (`requireLogin()`, CSRF check via `$_POST['csrf_token']`, JSON response):

- POST params: `csrf_token`, `mission_id`, `shift_id`.
- Looks up the caller's own `participation_requests` row for that `shift_id` (`member_id = current user`), same lookup pattern `api-ride-ping.php` already uses.
- `UPDATE participation_requests SET navigating_since = NOW() WHERE id = ?` on that row.
- Responds `{"ok": true}` (or an error shape matching `api-ride-ping.php`'s conventions on failure — missing mission/shift, no approved participation, etc.).
- No CSRF-exempt fire-and-forget shortcuts — this is a normal authenticated POST, just not awaited by the click handler (see below), so it never blocks opening Google Maps.

### Classifying "navigating" in `api-ride-data.php`

In the per-participant loop (`api-ride-data.php:117` onward), compute:

```php
$isNavigating = !empty($row['navigating_since'])
    && strtotime($row['navigating_since']) > ($lastPingTs ?? 0)
    && (time() - strtotime($row['navigating_since'])) <= 120;
```

This says: "the rider tapped Navigate more recently than their last real GPS ping, and it's been at most 120 seconds (the same staleness window) since they did." Both halves matter:
- **More recent than the last ping** — the instant a fresh normal ping arrives (e.g. the rider glances back at EasyRide, or GPS briefly recovers), that ping's timestamp overtakes `navigating_since` and the badge stops showing on its own. No explicit clear needed.
- **Within the 120s window** — if the rider has genuinely been gone far longer than a normal stale-tolerance window, this stops being a reassuring "they're just navigating" signal and the participant should fall back to being flagged as plain "Stale" like today, since at that point something may actually be worth the responsible person's attention.

Add `'is_navigating' => $isNavigating` to the `$participant` array (`api-ride-data.php:150-173`).

**Branch ordering** in the alert/readiness logic (`api-ride-data.php:176-258`): check `$isNavigating` *before* the existing `is_stale` branch:

```php
if ($isNavigating) {
    $readiness['navigating']++;
    // no alert, no $eventCandidates entry — nothing permanent is logged
} elseif ($ping && !$participant['is_stale']) {
    $readiness['tracking']++;
} elseif ($participant['is_stale']) {
    // existing stale handling, unchanged
}
```

`$readiness` gets a new `navigating` counter alongside the existing `approved`/`tracking`/`stale`/`not_started`/`with_status` keys, initialized to `0` at `api-ride-data.php:42`.

### Where this surfaces

Because `ride-mode.php` renders the exact same participant list/map for a rider and for the mission's controller (only `$isController` differs — there's no separate admin-only page for this), adding `is_navigating` to `api-ride-data.php`'s response means both audiences pick it up automatically from one code change. `ops-dashboard.php` is a separate, multi-mission overview (aggregate staffing/mission map, no per-participant staleness rendering) and is out of scope here. Client-side rendering:
- Participant list/badges: where `is_stale` currently renders a muted "Χωρίς στίγμα" badge (`ride-mode.php:505`), check `is_navigating` first and render "🧭 Στην πλοήγηση" instead, in a neutral/informational color (not the muted-gray "stale" treatment, not a warning color) — it should read as "expected, not urgent."
- Map markers (`markerHtml()`, `ride-mode.php:477-478`): navigating participants keep full marker opacity (they're not actually lost), just with the same badge treatment as the list.
- Readiness summary (`ride-mode.php:311` `Stale` metric-lite, `mission-view.php:1801-1802` Ride Readiness card): add a "Στην πλοήγηση" count next to the existing Stale count, so the aggregate numbers don't lump navigating riders in with genuinely stale ones.

## Client-Side: Firing the Signal

In `ride-mode.php`'s Πλοήγηση button (`ride-mode.php:349-353`), add a click handler that fires the navigate-signal request without blocking or delaying the link's default behavior:

```js
document.getElementById('navigateBtn')?.addEventListener('click', () => {
    fetch('api-ride-navigate.php', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({
            csrf_token: csrfTokenValue,
            mission_id: String(missionId),
            shift_id: String(shiftId),
        }),
    }).catch(() => {}); // best-effort — never block navigation on this
});
```

The `<a href target="_blank">` continues to work exactly as today (browser opens the link in parallel with the fetch firing); `keepalive: true` protects the request from being cancelled if the tab is backgrounded a moment later. No confirmation dialog, no delay — zero added friction for the rider, per the earlier decision.

## Client-Side: Passive "Welcome Back" Message

Extend the existing `visibilitychange` listener (`ride-mode.php:880-885`), which today only re-acquires the wake lock on return to visibility:

```js
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && tracking) {
        const goneMs = Date.now() - lastSentAt;
        if (goneMs > 120000) {
            const minutes = Math.round(goneMs / 60000);
            showToast('Το στίγμα σου διακόπηκε ' + minutes + ' λεπτά. Άφησε ανοιχτή αυτή την οθόνη για να συνεχίσει η μετάδοση.');
        }
        requestWakeLock();
        rideMap.invalidateSize();
    }
});
```

Uses the same 120-second threshold as staleness elsewhere, and the `lastSentAt` variable Ride Mode already tracks (used today for the 10s ping-throttle check at `ride-mode.php:841-844`) — no new state needed.

No toast/banner component exists anywhere in this codebase today (confirmed by search), so `showToast()` is a small new self-contained helper using Bootstrap 5's native `Toast` component (Bootstrap 5 is already loaded on every page) rather than inventing a new UI pattern:

```html
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="rideModeToast" class="toast align-items-center text-white bg-dark border-0" role="status">
        <div class="d-flex">
            <div class="toast-body" id="rideModeToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>
```
```js
function showToast(message) {
    document.getElementById('rideModeToastBody').textContent = message;
    new bootstrap.Toast(document.getElementById('rideModeToast'), { delay: 6000 }).show();
}
```

Auto-dismisses after 6 seconds or on manual close — never a `confirm()`/`alert()` blocking dialog, and only ever shown from inside the `visibilityState === 'visible'` branch (i.e. never while the rider is actually away — only at the moment they come back on their own).

## Edge Cases

- **Rider taps Πλοήγηση but never actually leaves the tab** (e.g. long-presses, cancels, or Google Maps fails to open): harmless. `navigating_since` gets set, but the next normal GPS ping (still arriving, since the tab never actually backgrounded) immediately overtakes it, so no badge ever appears.
- **Rider taps Πλοήγηση repeatedly** (e.g. re-opens directions mid-ride): each tap just refreshes `navigating_since = NOW()`, extending the window. No duplicate rows, no special handling needed.
- **Rider is gone longer than 120s**: `is_navigating` naturally becomes `false` (the timestamp falls outside the window) and they fall through to the existing `is_stale` branch — back to today's behavior (generic Stale badge, auto-logged event resumes). This is intentional: reassurance has a shelf life, and beyond it the responsible person should see the same signal as before.
- **Multi-day missions**: `navigating_since` lives on `participation_requests` (per shift/day), so it's naturally scoped to whichever day's shift the rider is currently checked into — no extra logic needed.

## Testing

No automated test framework in this repo (project convention). Manual verification:
1. `php -l` on all touched/new files (`api-ride-navigate.php`, `api-ride-data.php`, `ride-mode.php`, `includes/migrations.php`, `config.php`).
2. As a rider with an active Ride Mode session, tap "Πλοήγηση"; confirm (via a second browser/session as the responsible person viewing `ride-mode.php` in Controller mode, or the DB directly) that `navigating_since` updates and the participant list shows "🧭 Στην πλοήγηση" instead of Stale for that participant within the 120s window.
3. Background the rider's tab for over 2 minutes (simulating the real-world GPS stall), confirm the badge reverts to normal "Stale" after the 120s window elapses.
4. Confirm no new `ride_events` rows are created for that participant while `is_navigating` is true (query `ride_events WHERE event_type = 'stale' AND participation_id = ...` before/after); confirm stale-event logging resumes normally once the navigating window expires.
5. Bring the rider's tab back to visible after a >120s gap; confirm the passive toast appears once, with a roughly-correct elapsed-minutes count, and does not block interaction or require dismissal via a modal.
6. Confirm the toast does *not* appear on a normal short backgrounding (e.g. rider checks a text message for 10 seconds) — only after a real gap.
