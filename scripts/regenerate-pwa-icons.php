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
