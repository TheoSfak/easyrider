# Android Ride Mode Companion App — Design

## Purpose

Ride Mode's GPS broadcast (`navigator.geolocation.watchPosition`, [ride-mode.php:849](../../../ride-mode.php)) only works while the browser tab stays foregrounded — the moment a rider minimizes the browser, locks their screen, or switches apps, mobile-OS JS throttling silently stalls the ping. v3.87.0 made this gap *visible* (the "Στην Πλοήγηση" navigating-status feature, and the "welcome back" toast) but did not solve it — riders still lose live tracking any time the phone leaves the foreground, which is the normal state of a phone during an actual ride.

This spec adds a small native Android companion app whose only job is to keep GPS pings flowing to the existing `api-ride-ping.php` endpoint via an OS-level foreground service, independent of whether the app's WebView is visible. It reuses the existing web app almost entirely — no server-side rewrite, no parallel API, no new database schema.

## Scope

**In scope (v1):**
- A standalone Android app (separate repo, separate release lifecycle from the PHP web app) whose WebView shows only: `login.php` → `my-participations.php` → `ride-mode.php?shift_id=…` — a focused Ride Mode companion, not a full replica of the admin site.
- A foreground `RideTrackingService` that acquires GPS independently of the WebView and POSTs to `api-ride-ping.php`, so tracking survives minimizing the app or locking the screen.
- A small, additive JS bridge hook in `ride-mode.php`'s existing tracking functions, feature-detected so the page keeps working unchanged in a normal browser.
- Distribution as a signed APK on GitHub Releases (sideload), matching the club's existing informal distribution style.

**Out of scope (v1) — explicitly deferred:**
- iOS app. Apple's background-location model, entitlements, and store-review requirement (sideloading isn't an option there) need their own design pass. Revisit once the Android version is proven with real riders.
- Google Play Store listing (background-location apps face extra Play policy scrutiny; sideloading avoids it entirely for a closed club audience).
- Wrapping the rest of the site (dashboard, members, missions admin, inventory, etc.) in the app.
- Any Capacitor/Cordova/background-geolocation-plugin framework — raw native Kotlin, per explicit decision below.
- In-app auto-update checking.
- Any change to `api-ride-ping.php` or the database — the native service reuses the endpoint exactly as-is.

## Why raw native Kotlin, not Capacitor

Considered and rejected: a Capacitor wrapper + community background-geolocation plugin. It would add an npm/Node build layer and a third-party plugin dependency (of variable maintenance quality) for what is, in the end, one Activity + one Service. Raw Kotlin keeps the native surface small enough to read end-to-end (a few hundred lines), fully under our control, with no framework upgrade treadmill. The trade-off — no code-sharing head start for a future iOS app — is accepted, since iOS needs substantially different background-location handling regardless of shell framework.

Also considered and rejected: `WorkManager` periodic background sync instead of a true foreground service. Ruled out immediately — `WorkManager`'s minimum periodic interval is 15 minutes, far too coarse for live ride tracking (existing ping cadence is ~10s); it cannot meet the "still sends signals while minimized" requirement.

## Architecture

**New, separate repo** (e.g. `TheoSfak/easyride-android`), not a subfolder of this PHP repo. It has an entirely different build/release lifecycle (Gradle, APK signing, its own version number) from the web app's git-tag/robocopy release flow ([[easyride-release-workflow]]); keeping it separate avoids bloating htdocs syncs or mixing two unrelated toolchains in one repo.

Components:
- **`MainActivity`** — single Activity, fullscreen `WebView` pointed at `BASE_URL` (resolved at launch — see Domain Configuration below, since the deployment domain is still in transition as of this writing: localhost XAMPP today → a temporary domain shortly → a permanent domain later).
- **`RideTrackingService`** — a foreground service (manifest type `location`, required Android 10+) that:
  - Uses `FusedLocationProviderClient.requestLocationUpdates()` for GPS fixes, matching Ride Mode's existing ~10s ping cadence ([ride-mode.php:852](../../../ride-mode.php), `elapsed > 10000`).
  - POSTs to `api-ride-ping.php` via OkHttp with the same payload shape `sendPing()` already builds ([ride-mode.php:788-798](../../../ride-mode.php)): `shift_id`, `lat`, `lng`, `accuracy`, `speed`, `heading`, `battery_level`, `status`, plus `csrf_token`.
  - Shows a persistent notification ("EasyRide — Ride Mode ενεργό") with a one-tap Stop action, satisfying Android's foreground-service UX requirement and doubling as rider reassurance that tracking is alive.
  - Stops itself when the rider taps Stop (native or in-page), or when a ping response indicates the session/auth is no longer valid — never runs unattended indefinitely.
- **`AndroidRideBridge`** — a `WebView.addJavascriptInterface` bridge exposing `startTracking(shiftId, csrfToken)` / `stopTracking()` to page JS.

## Domain Configuration: surviving the temp-domain → permanent-domain transition

As of this writing, EasyRide is deployed on localhost/XAMPP for development, moving to a temporary domain shortly, then a permanent domain once that's finalized — at least two domain changes are already expected after v1 of this app exists. Baking `BASE_URL` into `BuildConfig` at compile time would mean every domain change requires a new APK build *and* every rider re-sideloading it — acceptable once, painful to repeat.

Instead, `MainActivity` resolves `BASE_URL` via a small remote-config indirection, before ever loading the WebView:

1. On launch, fetch a tiny static JSON file — `{"base_url": "https://current-domain.example"}` — from one **fixed, permanent URL that never changes**: a raw file in the `easyride-android` GitHub repo itself (e.g. `https://raw.githubusercontent.com/TheoSfak/easyride-android/main/server-config.json`). This URL is independent of the EasyRide web app's own domain migrations, so it can't be caught in the same churn it's meant to route around.
2. On a successful fetch, cache the value in `SharedPreferences` and use it as `BASE_URL` for this session.
3. On failure (no network at launch, GitHub unreachable), fall back to the last cached value. On a first-ever launch with no cache and no network, fall back to a bootstrap default compiled into `BuildConfig` (whatever domain was current when that APK was built) — this only matters for the single edge case of a brand-new install with zero connectivity, which requires network at least once anyway to log in.
4. Show a brief loading state while this resolves (a single small JSON GET, typically well under a second) before the WebView starts loading `login.php`.

**Migrating domains later is then a one-line edit** to `server-config.json` in the `easyride-android` repo — no APK rebuild, no asking riders to reinstall. Already-installed apps pick up the new domain on their next cold start.

## Auth: reusing the existing PHP session, no backend changes

Android's `CookieManager` operates at the OS/WebView level, below JavaScript — it captures the PHP session cookie the moment the WebView's `login.php` submission succeeds, *even though the cookie is `HttpOnly`* (confirmed in [includes/auth.php:16-22](../../../includes/auth.php)). `RideTrackingService` reads that same cookie via `CookieManager.getInstance().getCookie(BASE_URL)` and attaches it to its own OkHttp requests, so `api-ride-ping.php` sees an identically authenticated session whether the ping came from the WebView's JS or the native service.

The CSRF token needed for the ping POST is captured once, via the bridge, at `startTracking()` time and passed to the service. This is safe to hold for the ride's duration: `$_SESSION['csrf_token']` is generated once at login and only regenerated on a fresh login ([includes/auth.php:214](../../../includes/auth.php)) — [includes/functions.php:242-245](../../../includes/functions.php) only lazily creates one if entirely missing, it does not rotate per-request.

## Client-side change: the JS bridge hook

The only edit to this repo. In `ride-mode.php`'s existing tracking functions, add feature-detected calls so the page behaves identically in a plain browser (bridge absent → no-op) and gains native tracking inside the app (bridge present):

```js
function startTracking() {
    // ...existing browser watchPosition logic, unchanged...

    if (window.AndroidRideBridge) {
        window.AndroidRideBridge.startTracking(String(shiftId), csrfTokenValue);
    }
}

function stopTracking() {
    // ...existing logic, unchanged...

    if (window.AndroidRideBridge) {
        window.AndroidRideBridge.stopTracking();
    }
}
```

No other page is touched. The existing `visibilitychange` "welcome back" toast ([ride-mode.php:900-910](../../../ride-mode.php)) needs no change — since native pings never actually stop while backgrounded, the elapsed-gap check simply won't cross its threshold when running inside the app, so the toast naturally stays silent for app users while continuing to serve plain-browser users exactly as today.

## Permissions & Android version handling

Requested at first "Start" tap, with an in-app explanation screen before the OS dialog (since `ACCESS_BACKGROUND_LOCATION` requires a *second*, separate grant step the OS enforces — it cannot be requested alongside fine location):
- `ACCESS_FINE_LOCATION`, then `ACCESS_BACKGROUND_LOCATION` (Android 10+)
- `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION` (Android 14+ manifest declaration)
- `POST_NOTIFICATIONS` (Android 13+)
- Battery-optimization exemption prompt (`ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`) — not OS-required, but recommended in-app, since some OEMs (Xiaomi/Huawei/aggressive Samsung profiles) still kill background services without it.

**Known, accepted limitation:** OEM-specific aggressive battery/task killers can still terminate the service on some devices even with the above. This is called out in-app (a short note near the battery-exemption prompt) rather than presented as fully solved — it is a platform reality, not a bug to chase indefinitely.

## Distribution

- Signed release APK, built via Android Studio/Gradle, versioned independently of the web app (starts at `v1.0.0`).
- Hosted as a GitHub release asset on `TheoSfak/easyride-android`. Members sideload it (enable "install from unknown sources" once) — no Play Store submission, no developer fee, no background-location policy review.
- No in-app update checker for v1; riders check the releases page manually. Revisit only if manual checking proves annoying in practice.

## Edge Cases

- **Bridge absent (app not installed, page loaded in a normal browser):** `window.AndroidRideBridge` is `undefined`; both new hooks are no-ops. Zero behavior change for existing browser users.
- **Rider force-quits the app entirely** (not just backgrounds it) while tracking: Android will kill the foreground service along with the process — this is expected and matches the reality that no app can guarantee GPS after the user explicitly kills it. The existing web app's own staleness/stale-badge handling (v3.87.0) already covers this case identically to today.
- **Session expires mid-ride** (e.g. hits `SESSION_LIFETIME`): the native ping's response will fail auth; the service stops itself and the persistent notification is dismissed rather than silently pinging into the void.
- **Rider switches to a different active shift mid-app-session** (multi-day mission): each `startTracking()` call passes a fresh `shift_id`, so the service always pings for whichever shift the rider most recently started tracking on.
- **Domain changes while the app is already installed** (e.g. temp → permanent domain cutover): resolved automatically on the app's next cold start once `server-config.json` is updated — no rebuild, no reinstall. A rider whose app stays running/backgrounded through the cutover keeps using the old `BASE_URL` for that session until they next fully restart the app.
- **`server-config.json` fetch fails and there's no cache yet** (fresh install, no network at first launch): falls back to the `BuildConfig` bootstrap default; if that's already stale by the time someone installs it, login will simply fail against an unreachable/wrong host until they get connectivity for the remote-config fetch to succeed.

## Testing

No automated test framework for the native app in v1 (matches this repo's own no-test-framework convention — manual verification only). Manual verification plan:
1. Side-load a debug build on a real phone; log in through the WebView; confirm `my-participations.php` and `ride-mode.php` render identically to the browser experience.
2. Start tracking on a real active shift; confirm pings land (check `participation_requests`/`ride_events` or the mission's live map as the responsible person) at the same ~10s cadence as browser Ride Mode.
3. Lock the screen and/or switch to another app for several minutes; confirm pings continue landing throughout — this is the actual acceptance test for the whole feature.
4. Force-stop the app; confirm the foreground service and notification are torn down, and that the mission's live view correctly shows the rider going stale (existing v3.87.0 behavior, unchanged).
5. Tap Stop from the persistent notification; confirm the service stops and the in-page tracking UI reflects Stopped state next time the WebView is foregrounded.
6. Load `ride-mode.php` in a normal desktop/mobile browser (bridge absent); confirm tracking behaves exactly as it does today, with no errors from the missing bridge.
7. Edit `server-config.json` to point at a different (test) host, relaunch the app cold, confirm it picks up the new `BASE_URL` without a rebuild; then disable network entirely before launch and confirm it falls back to the last cached value instead of failing to load.
