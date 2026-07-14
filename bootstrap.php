<?php
/**
 * EasyRide - Bootstrap file
 * Include this at the top of every page
 */

define('VOLUNTEEROPS', true);

// Load configuration
require_once __DIR__ . '/config.php';

// Global exception/fatal handlers — must load before anything that can throw
require_once __DIR__ . '/includes/error-handler.php';

// Load core includes
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/webpush.php';
require_once __DIR__ . '/includes/newsletter-functions.php';
require_once __DIR__ . '/includes/training-functions.php';
require_once __DIR__ . '/includes/achievements-functions.php';
// inventory-functions.php is loaded on-demand by inventory pages and branches.php only

// Schema migrations are deliberately never loaded or run by web requests.
// Run `php scripts/maintenance/migrate.php` as a locked deployment step.

// CLI commands need the application services but must not create an HTTP
// session, send headers, or be redirected by maintenance mode.
if (PHP_SAPI === 'cli') {
    return;
}

// Prevent PHP's default Session Garbage Collection from causing intermittent 5-7s pauses on shared hosting
ini_set('session.gc_probability', 0);
// Start session
initSession();

// Security headers — prevent clickjacking, MIME-sniffing, XSS
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self)');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://fonts.googleapis.com; img-src 'self' data: https:; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; connect-src 'self' https://cdn.jsdelivr.net https://unpkg.com https://*.push.services.mozilla.com https://fcm.googleapis.com https://updates.push.services.mozilla.com; worker-src 'self'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Maintenance mode — only admins can access
$__currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__maintenanceExcluded = ['login.php', 'logout.php', 'install.php', 'replay-share.php', 'cron_daily.php', 'cron_shift_reminders.php', 'cron_task_reminders.php', 'cron_certificate_expiry.php', 'cron_citizen_cert_expiry.php', 'cron_shelf_expiry.php', 'cron_incomplete_missions.php'];
if (getSetting('maintenance_mode', '0') && !in_array($__currentScript, $__maintenanceExcluded)) {
    if (isLoggedIn()) {
        $__mUser = getCurrentUser();
        if ($__mUser && $__mUser['role'] !== ROLE_SYSTEM_ADMIN) {
            setFlash('warning', 'Το σύστημα βρίσκεται σε συντήρηση. Παρακαλώ δοκιμάστε αργότερα.');
            logout();
            redirect('login.php');
        }
    } elseif ($__currentScript !== 'login.php') {
        setFlash('warning', 'Το σύστημα βρίσκεται σε συντήρηση. Παρακαλώ δοκιμάστε αργότερα.');
        redirect('login.php');
    }
}
unset($__currentScript, $__maintenanceExcluded);

// Role preview mode: handle exit request
if (isLoggedIn() && isset($_GET['exit_preview'])) {
    unset($_SESSION['preview_role_id']);
    redirect('roles.php');
}
