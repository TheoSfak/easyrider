<?php
/**
 * EasyRide - Daily Reminders (All-in-One)
 * Run this script once daily via Windows Task Scheduler or cron
 * 
 * Usage:
 * php C:\xampp\htdocs\easyride\cron_daily.php
 */

// CLI only - prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line.');
}

require_once __DIR__ . '/bootstrap.php';

echo "==============================================\n";
echo "EasyRide - Daily Reminders\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

// 1. Task Deadline Reminders
echo "[1/6] Processing Task Deadline Reminders...\n";
include __DIR__ . '/cron_task_reminders.php';
echo "\n";

// 2. Shift Reminders
echo "[2/6] Processing Shift Reminders...\n";
include __DIR__ . '/cron_shift_reminders.php';
echo "\n";

// 3. Incomplete Mission Alerts
echo "[3/6] Processing Incomplete Mission Alerts...\n";
include __DIR__ . '/cron_incomplete_missions.php';
echo "\n";

// 4. Certificate Expiry Reminders
echo "[4/6] Processing Certificate Expiry Reminders...\n";
include __DIR__ . '/cron_certificate_expiry.php';
echo "\n";

// 5. Shelf Item Expiry Reminders (skipped when the Απόθεμα/inventory module is disabled)
echo "[5/6] Processing Shelf Item Expiry Reminders...\n";
if (getSetting('inventory_nav_enabled', '0') === '1') {
    include __DIR__ . '/cron_shelf_expiry.php';
} else {
    echo "Skipped: inventory module is disabled.\n";
}
echo "\n";

// 6. Citizen Certificate Expiry Reminders
echo "[6/6] Processing Citizen Certificate Expiry Reminders...\n";
include __DIR__ . '/cron_citizen_cert_expiry.php';
echo "\n";

echo "==============================================\n";
echo "All reminders completed successfully!\n";
echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n";
