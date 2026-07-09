<?php
/**
 * Regenerate PWA icons and the app_logo setting from the real club badge.
 * Run once from the repo root: C:/xampp/php/php.exe scripts/regenerate-pwa-icons.php
 */

define('VOLUNTEEROPS', true);
require_once __DIR__ . '/../config.php';
if (file_exists(__DIR__ . '/../config.local.php')) {
    require_once __DIR__ . '/../config.local.php';
}

$sourcePath = __DIR__ . '/../assets/branding/club-badge-source.png';
if (!file_exists($sourcePath)) {
    fwrite(STDERR, "Source badge not found at $sourcePath\n");
    exit(1);
}

$source = imagecreatefrompng($sourcePath);
if (!$source) {
    fwrite(STDERR, "Failed to read $sourcePath as PNG\n");
    exit(1);
}
imagesavealpha($source, true);
$srcW = imagesx($source);
$srcH = imagesy($source);
$srcSide = max($srcW, $srcH);

/**
 * Resizes $source onto a square transparent canvas of $size x $size,
 * centered, preserving aspect ratio (letterboxed if source isn't square).
 */
function squareResize($source, int $srcW, int $srcH, int $size): \GdImage {
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

$iconSizes = [72, 96, 128, 144, 152, 192, 384, 512];
foreach ($iconSizes as $size) {
    $canvas = squareResize($source, $srcW, $srcH, $size);
    $destPath = __DIR__ . "/../assets/icons/icon-{$size}.png";
    imagepng($canvas, $destPath);
    imagedestroy($canvas);
    echo "Wrote $destPath\n";
}

/**
 * Maskable icon: solid brand-black background, badge scaled to 80% of the
 * canvas so the OS's circular/rounded-square mask never clips the emblem
 * (per the standard maskable-icon safe-zone guideline).
 */
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
$maskablePath = __DIR__ . '/../assets/icons/icon-maskable-512.png';
imagepng($maskable, $maskablePath);
imagedestroy($maskable);
echo "Wrote $maskablePath\n";

// Copy the source into uploads/logos/ under a fresh filename, following the
// same naming convention settings.php uses for logo uploads ('logo_' . time()).
$logoFilename = 'club-badge.png';
$logoDest = __DIR__ . '/../uploads/logos/' . $logoFilename;
copy($sourcePath, $logoDest);
echo "Wrote $logoDest\n";

imagedestroy($source);

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
