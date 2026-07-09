# Auto-Regenerate PWA Icons from Settings Logo Upload — Design

## Purpose

Since the v3.91.0 visual identity revamp, the app's PWA install icon (`assets/icons/icon-*.png`, `icon-maskable-512.png`, referenced by `manifest.json`) is a separate static asset pipeline from the in-app `app_logo` setting — uploading a new logo via Settings → General only updates the in-app logo, not the PWA icons. Keeping them in sync currently requires manually re-running `scripts/regenerate-pwa-icons.php` against a source file saved to `assets/branding/club-badge-source.png`, a step that's easy to forget (and did get missed once already this session). This change makes the PWA icon set regenerate automatically whenever a logo is uploaded through Settings.

**Explicitly out of scope:** the Android companion app's (`easyride-android`) APK icon. Android app icons are compiled into the signed APK as resources at build time — there is no runtime mechanism for an installed app to pull a new icon from the server. Syncing that would require a new APK build + signing + release in the separate `easyride-android` repo, a different pipeline entirely, and is not addressed here.

## Architecture

**New shared function**, extracted from the resize/pad logic currently inline in `scripts/regenerate-pwa-icons.php`:

- New file: `includes/pwa-icons.php`
- Function: `regeneratePwaIconsFromImage(string $sourcePath): bool`
  - Loads `$sourcePath` via the correct GD loader based on detected MIME type (`imagecreatefromjpeg`/`imagecreatefrompng`/`imagecreatefromgif`/`imagecreatefromwebp`) — covering exactly the four formats Settings' upload validation already accepts (JPG/PNG/GIF/WebP). No SVG handling needed; Settings never accepted SVG.
  - Resizes into the same 9 outputs as today (`icon-72.png` through `icon-512.png`, `icon-maskable-512.png` with the same 80%-safe-zone padding against the `--ez-black` (`#141110`) background), overwriting `assets/icons/`.
  - Returns `true` on success, `false` on any failure (unreadable file, GD error) — does not throw, so callers can degrade gracefully.

**Two callers, no duplicated logic:**
1. `settings.php`'s existing logo-upload handler (`action === 'save_general'`, the `$_FILES['app_logo']` block) — after the uploaded file is saved to `uploads/logos/{filename}` and the `app_logo` setting is updated (existing behavior, unchanged), it calls `regeneratePwaIconsFromImage()` against that same freshly-saved file.
2. `scripts/regenerate-pwa-icons.php` — simplified to a thin CLI wrapper: resolve the source path (`assets/branding/club-badge-source.png`, unchanged), call the shared function, print the same status lines as today. Kept for ops use (re-running without a browser session, e.g. after a fresh install or if icons need a manual rebuild) — not removed.

## Data Flow (Settings Upload)

1. Admin uploads a logo via Settings → General (existing validation: JPG/PNG/GIF/WebP, ≤2MB, MIME-sniffed — unchanged).
2. Existing code saves the file to `uploads/logos/{new filename}`, deletes the old logo file, updates the `app_logo` setting. Unchanged.
3. **New step**: call `regeneratePwaIconsFromImage(__DIR__ . '/uploads/logos/' . $newFilename)`.
4. **On success**: redirect with the existing success flash message (unchanged) — no new user-facing message needed when everything works.
5. **On failure** (function returns `false`): the logo upload itself is NOT rolled back or blocked — the `app_logo` setting change and file save from step 2 stand. Log the failure (`error_log`) and set an additional flash message: "Το λογότυπο αποθηκεύτηκε, αλλά η ενημέρωση των εικονιδίων PWA απέτυχε." (logo saved, but the PWA icon update failed) so the admin knows to investigate or re-run the CLI script, rather than silently having stale icons with no indication anything's off.

## Logo Removal

No change to icon-regeneration behavior on removal: clicking "Διαγραφή Λογότυπου" reverts `app_logo` to blank (the app falls back to text-based branding, existing behavior), and the PWA icon files are left exactly as they last were. There is no generic/default icon asset to revert to, and generating one is out of scope — this is a deliberate, discussed decision, not an oversight.

## Testing / Verification

No automated test framework exists in this repo (established project convention). Verification:
1. `php -l` on all touched/new files (`includes/pwa-icons.php`, `settings.php`, `scripts/regenerate-pwa-icons.php`).
2. Manual: upload a new logo image (each of the 4 accepted formats, at least once) via Settings → General in the browser; confirm all 9 `assets/icons/*.png` files update (check file mtimes and visually via the Read tool) and the success flash still shows.
3. Manual: temporarily rename/corrupt a test source file to force `regeneratePwaIconsFromImage()` to fail; confirm the logo still saves successfully and the new "icons failed to update" flash appears, and that this doesn't produce a fatal error or blank page.
4. Manual: re-run `scripts/regenerate-pwa-icons.php` via CLI after the refactor; confirm it still produces identical output to before (same icons, same `app_logo` DB update) — this is a regression check that the extraction didn't change its behavior.
5. Confirm removing the logo via Settings still reverts `app_logo` to blank and leaves `assets/icons/*.png` untouched (mtimes unchanged).
