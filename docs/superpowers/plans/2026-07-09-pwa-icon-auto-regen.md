# PWA Icon Auto-Regeneration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Uploading a logo through Settings → General automatically regenerates the PWA install icon set, instead of requiring a manual re-run of a separate CLI script.

**Architecture:** Extract the resize/pad logic already in `scripts/regenerate-pwa-icons.php` into a shared, format-agnostic function in a new `includes/pwa-icons.php`. `settings.php`'s existing upload handler and the CLI script both call it — no duplicated logic, and the CLI script becomes a thin wrapper.

**Tech Stack:** PHP, GD (already used by the existing script), no build step, no new dependencies.

## Global Constraints

- Android APK icon is explicitly out of scope — compiled into the signed APK at build time, cannot be updated from a web upload.
- Settings' existing upload validation (JPG/PNG/GIF/WebP only, ≤2MB, MIME-sniffed via `finfo`) is unchanged — the shared function only needs to support these four formats.
- A PWA icon regeneration failure must NOT block or roll back the logo upload/`app_logo` setting save — it degrades gracefully with a warning flash message, not a fatal error.
- Removing the logo (`delete_logo` action) does not attempt to reset/regenerate PWA icons — they're left as last generated. This was a deliberate decision, not an oversight.
- No automated test framework exists in this repo (confirmed project convention) — verification is `php -l` plus manual browser/CLI checks.

---

### Task 1: Extract shared PWA icon regeneration function

**Files:**
- Create: `includes/pwa-icons.php`
- Modify: `scripts/regenerate-pwa-icons.php`

**Interfaces:**
- Produces: `regeneratePwaIconsFromImage(string $sourcePath): bool` — the function Task 2 calls from `settings.php`. Returns `true` on success, `false` on any failure (unreadable file, unsupported format, GD write failure). Never throws.

- [ ] **Step 1: Create `includes/pwa-icons.php`**

```php
<?php
/**
 * EasyRide - Shared PWA icon regeneration
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Resizes $source onto a square transparent canvas of $size x $size,
 * centered, preserving aspect ratio (letterboxed if source isn't square).
 */
function pwaIconSquareResize($source, int $srcW, int $srcH, int $size): \GdImage {
    $canvas = imagecreatetruecolor($size, $size);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);

    $scale = min($size / $srcW, $size / $srcH);
    $destW = (int) round($srcW * $scale);
    $destH = (int) round($srcH * $scale);
    $destX = (int) (($size - $destW) / 2);
    $destY = (int) (($size - $destH) / 2);

    imagecopyresampled($canvas, $source, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
    return $canvas;
}

/**
 * Loads an image file via the correct GD loader based on its detected MIME
 * type. Supports exactly the four formats Settings' logo upload accepts:
 * JPEG, PNG, GIF, WebP. Returns null if the file can't be read or its MIME
 * type isn't one of these four.
 */
function pwaIconLoadSource(string $path): ?\GdImage {
    if (!file_exists($path)) {
        return null;
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
    } else {
        $mime = mime_content_type($path);
    }
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/gif' => @imagecreatefromgif($path),
        'image/webp' => @imagecreatefromwebp($path),
        default => false,
    };
    return $image ?: null;
}

/**
 * Regenerates the full PWA icon set (assets/icons/icon-*.png plus the
 * maskable variant) from a source image at $sourcePath. Returns true on
 * success, false on any failure -- never throws, so callers (a web upload
 * handler or a CLI script) can degrade gracefully instead of crashing.
 */
function regeneratePwaIconsFromImage(string $sourcePath): bool {
    $source = pwaIconLoadSource($sourcePath);
    if (!$source) {
        return false;
    }
    imagesavealpha($source, true);
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    if ($srcW < 1 || $srcH < 1) {
        imagedestroy($source);
        return false;
    }

    $iconSizes = [72, 96, 128, 144, 152, 192, 384, 512];
    foreach ($iconSizes as $size) {
        $canvas = pwaIconSquareResize($source, $srcW, $srcH, $size);
        $ok = imagepng($canvas, __DIR__ . "/../assets/icons/icon-{$size}.png");
        imagedestroy($canvas);
        if (!$ok) {
            imagedestroy($source);
            return false;
        }
    }

    // Maskable icon: solid brand-black background, badge scaled to 80% of
    // the canvas so the OS's circular/rounded-square mask never clips the
    // emblem (standard maskable-icon safe-zone guideline).
    $maskableSize = 512;
    $maskable = imagecreatetruecolor($maskableSize, $maskableSize);
    $bg = imagecolorallocate($maskable, 0x14, 0x11, 0x10); // --ez-black
    imagefill($maskable, 0, 0, $bg);
    $safeZone = (int) round($maskableSize * 0.8);
    $scale = min($safeZone / $srcW, $safeZone / $srcH);
    $destW = (int) round($srcW * $scale);
    $destH = (int) round($srcH * $scale);
    $destX = (int) (($maskableSize - $destW) / 2);
    $destY = (int) (($maskableSize - $destH) / 2);
    imagecopyresampled($maskable, $source, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
    $ok = imagepng($maskable, __DIR__ . '/../assets/icons/icon-maskable-512.png');
    imagedestroy($maskable);
    imagedestroy($source);

    return $ok;
}
```

- [ ] **Step 2: `php -l` check**

Run: `C:/xampp/php/php.exe -l includes/pwa-icons.php`
Expected: `No syntax errors detected in includes/pwa-icons.php`

- [ ] **Step 3: Refactor `scripts/regenerate-pwa-icons.php` to use the shared function**

Replace the entire file content with:

```php
<?php
/**
 * Regenerate PWA icons and the app_logo setting from the real club badge.
 * Run once from the repo root: C:/xampp/php/php.exe scripts/regenerate-pwa-icons.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('VOLUNTEEROPS', true);
require_once __DIR__ . '/../config.php';
if (file_exists(__DIR__ . '/../config.local.php')) {
    require_once __DIR__ . '/../config.local.php';
}
require_once __DIR__ . '/../includes/pwa-icons.php';

$sourcePath = __DIR__ . '/../assets/branding/club-badge-source.png';
if (!file_exists($sourcePath)) {
    fwrite(STDERR, "Source badge not found at $sourcePath\n");
    exit(1);
}

if (!regeneratePwaIconsFromImage($sourcePath)) {
    fwrite(STDERR, "Failed to regenerate PWA icons from $sourcePath\n");
    exit(1);
}
echo "Wrote assets/icons/icon-{72,96,128,144,152,192,384,512}.png and icon-maskable-512.png\n";

// Copy the source into uploads/logos/ under a fixed filename, following the
// same naming convention settings.php uses for logo uploads ('logo_' . time()).
$logoFilename = 'club-badge.png';
$logoDest = __DIR__ . '/../uploads/logos/' . $logoFilename;
copy($sourcePath, $logoDest);
echo "Wrote $logoDest\n";

// Update the app_logo setting.
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'app_logo'")->fetchColumn();
if ($exists) {
    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'app_logo'");
} else {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES ('app_logo', ?, NOW(), NOW())");
}
$stmt->execute([$logoFilename]);
echo "Updated app_logo setting to $logoFilename\n";

echo "Done.\n";
```

- [ ] **Step 4: `php -l` check**

Run: `C:/xampp/php/php.exe -l scripts/regenerate-pwa-icons.php`
Expected: `No syntax errors detected in scripts/regenerate-pwa-icons.php`

- [ ] **Step 5: Regression check — CLI script still produces identical output**

Run: `C:/xampp/php/php.exe scripts/regenerate-pwa-icons.php`
Expected: the same 11 output lines as before the refactor (`Wrote .../icon-72.png` through `icon-512.png`, `Wrote .../icon-maskable-512.png`, `Wrote .../uploads/logos/club-badge.png`, `Updated app_logo setting to club-badge.png`, `Done.`), no errors/warnings.

Then run: `git status --short assets/icons/ uploads/logos/`
Expected: no output (or only `uploads/logos/club-badge.png` if it wasn't already present) — confirms the refactored code produces byte-identical icons to before, since `assets/icons/*.png` are git-tracked and would show as modified if the output differed.

- [ ] **Step 6: Commit**

```bash
git add includes/pwa-icons.php scripts/regenerate-pwa-icons.php
git commit -m "Extract PWA icon regeneration into a shared, format-agnostic function"
```

---

### Task 2: Auto-regenerate PWA icons on Settings logo upload

**Files:**
- Modify: `settings.php`

**Interfaces:**
- Consumes: `regeneratePwaIconsFromImage(string $sourcePath): bool` from `includes/pwa-icons.php` (Task 1).

- [ ] **Step 1: Wire the function into the upload handler**

In `settings.php`, the current successful-upload block (confirmed at lines 462–470) is:

```php
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFilename)) {
                // Save logo setting
                $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = 'app_logo'");
                if ($exists) {
                    dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'app_logo'", [$newFilename]);
                } else {
                    dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES ('app_logo', ?, NOW(), NOW())", [$newFilename]);
                }
                $settings['app_logo'] = $newFilename;
            } else {
```

Replace it with (adds the icon regeneration call and graceful-failure flash, right after `$settings['app_logo'] = $newFilename;`):

```php
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFilename)) {
                // Save logo setting
                $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = 'app_logo'");
                if ($exists) {
                    dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'app_logo'", [$newFilename]);
                } else {
                    dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES ('app_logo', ?, NOW(), NOW())", [$newFilename]);
                }
                $settings['app_logo'] = $newFilename;

                // Keep the PWA install icon in sync with the newly-uploaded logo.
                require_once __DIR__ . '/includes/pwa-icons.php';
                if (!regeneratePwaIconsFromImage($uploadDir . $newFilename)) {
                    error_log('regeneratePwaIconsFromImage failed for ' . $uploadDir . $newFilename);
                    setFlash('warning', 'Το λογότυπο αποθηκεύτηκε, αλλά η ενημέρωση των εικονιδίων PWA απέτυχε.');
                }
            } else {
```

Note: `settings.php` already requires `bootstrap.php` at the top (line 6), which defines `VOLUNTEEROPS` — so `includes/pwa-icons.php`'s access guard will not block this `require_once`.

- [ ] **Step 2: `php -l` check**

Run: `C:/xampp/php/php.exe -l settings.php`
Expected: `No syntax errors detected in settings.php`

- [ ] **Step 3: Manual browser verification — successful upload**

Using the project's dev server (`easyride-php-dev` preview config, or a throwaway `php -S`), log in as a `SYSTEM_ADMIN`, go to Settings → General, and upload a test logo image (try at least one of: JPG, PNG — the two most common). Confirm:
- The existing success flash still shows.
- `assets/icons/icon-512.png`'s file modified time updates to just now (`Get-Item assets/icons/icon-512.png | Select LastWriteTime` or equivalent), and opening it (e.g. via the Read tool) shows the newly-uploaded test image, not the old club badge.
- No new "PWA icons failed" warning appears (since this is the success path).

- [ ] **Step 4: Manual verification — graceful failure**

Temporarily rename `includes/pwa-icons.php` (e.g. to `pwa-icons.php.bak`) to simulate the function being unavailable, OR temporarily edit `pwaIconLoadSource()` to always `return null;`, then repeat the upload from Step 3. Confirm:
- The upload still succeeds (`app_logo` setting updates, no fatal error, no blank page).
- The new warning flash "Το λογότυπο αποθηκεύτηκε, αλλά η ενημέρωση των εικονιδίων PWA απέτυχε." appears alongside (or instead of) the success message.
- Revert the temporary change afterward (restore the file / undo the edit) and confirm `php -l settings.php` and `php -l includes/pwa-icons.php` still pass.

- [ ] **Step 5: Manual verification — logo removal leaves icons untouched**

Note `assets/icons/icon-512.png`'s modified time, then click "Διαγραφή Λογότυπου" in Settings. Confirm the in-app logo reverts to text-based branding (`app_logo` setting is now blank) and `assets/icons/icon-512.png`'s modified time is unchanged (no attempt was made to regenerate/reset it).

- [ ] **Step 6: Commit**

```bash
git add settings.php
git commit -m "Auto-regenerate PWA icons when a logo is uploaded via Settings"
```

---

## Self-Review Notes

- **Spec coverage:** Task 1 covers the shared-function extraction and the CLI-script regression check; Task 2 covers the Settings wiring, the graceful-failure path, and the logo-removal no-op — every section of `docs/superpowers/specs/2026-07-09-pwa-icon-auto-regen-design.md` maps to a task.
- **Type/name consistency:** `regeneratePwaIconsFromImage(string $sourcePath): bool` is defined once in Task 1 and called by name with the exact same signature in both the refactored CLI script (Task 1, Step 3) and `settings.php` (Task 2, Step 1).
- **Scope:** two tightly-scoped tasks, each independently testable (Task 1 via the CLI regression check, Task 2 via the three manual browser scenarios) — no need for further decomposition.
