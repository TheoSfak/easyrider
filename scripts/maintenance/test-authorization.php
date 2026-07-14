<?php
/**
 * CLI-only authorization contract regression suite.
 *
 * Usage: php scripts/maintenance/test-authorization.php
 * This suite is intentionally read-only: it validates source-level access
 * boundaries without connecting to or modifying the database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$failures = [];
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$source = static function (string $relativePath) use ($projectRoot): string {
    $path = $projectRoot . '/' . $relativePath;
    if (!is_file($path)) {
        throw new RuntimeException("Missing source file: {$relativePath}");
    }
    return (string) file_get_contents($path);
};

try {
    $permissionsSource = $source('includes/permissions.php');
    preg_match_all("/'slug'\s*=>\s*'([a-z_]+)'/", $permissionsSource, $matches);
    $definedPermissions = array_values(array_unique($matches[1] ?? []));
    $definedSet = array_flip($definedPermissions);

    // Every permission exposed in the role editor must have a concrete access boundary.
    $permissionCoverage = [
        'ops_dashboard'       => [['ops-dashboard.php', "requirePermission('ops_dashboard')"], ['includes/ride-functions.php', "hasPagePermission('ops_dashboard')"]],
        'attendance_manage'   => [['attendance.php', "hasPagePermission('attendance_manage')"]],
        'missions_view'       => [['mission-view.php', "hasPagePermission('missions_view')"]],
        'missions_manage'     => [['mission-form.php', "requirePermission('missions_manage')"]],
        'shifts_manage'       => [['shift-form.php', "requirePermission('shifts_manage')"]],
        'tasks_manage'        => [['task-form.php', "requirePermission('tasks_manage')"]],
        'members_view'        => [['member-view.php', "requirePermission('members_view')"]],
        'members_manage'      => [['member-form.php', "requirePermission('members_manage')"]],
        'inactive_members'    => [['inactive-members.php', "requirePermission('inactive_members')"]],
        'complaints_view'     => [['complaints.php', "requirePermission('complaints_view')"]],
        'complaints_manage'   => [['complaint-view.php', "hasPagePermission('complaints_manage')"]],
        'certificates_manage' => [['certificates.php', "requirePermission('certificates_manage')"]],
        'citizens_view'       => [['citizens.php', "requirePermission('citizens_view')"]],
        'citizens_manage'     => [['citizen-certificates.php', "requirePermission('citizens_manage')"]],
        'positions_manage'    => [['member-positions.php', "requirePermission('positions_manage')"]],
        'inventory_manage'    => [['inventory-notes.php', "requirePermission('inventory_manage')"]],
        'skills_manage'       => [['skills.php', "requirePermission('skills_manage')"]],
        'training_view'       => [['training-admin.php', "requirePermission('training_view')"]],
        'training_manage'     => [['exam-form.php', "requirePermission('training_manage')"]],
        'questions_manage'    => [['questions-pool.php', "requirePermission('questions_manage')"]],
    ];

    foreach ($permissionCoverage as $permission => $checks) {
        $assert(isset($definedSet[$permission]), "Permission is not assignable: {$permission}");
        foreach ($checks as [$file, $needle]) {
            $assert(str_contains($source($file), $needle), "{$permission} lacks its expected boundary in {$file}");
        }
    }
    foreach ($definedPermissions as $permission) {
        $assert(isset($permissionCoverage[$permission]), "No regression coverage declared for custom permission: {$permission}");
    }

    // Any direct permission requirement must be selectable in the custom-role map.
    $requiredPermissions = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));
        if (preg_match('#^(?:\.claude|\.git|backups|downloads|exports|uploads|scripts/maintenance)/#', $relative)) {
            continue;
        }
        preg_match_all("/requirePermission\('([a-z_]+)'\)/", (string) file_get_contents($file->getPathname()), $required);
        foreach ($required[1] ?? [] as $permission) {
            $requiredPermissions[$permission] = true;
        }
    }
    foreach (array_keys($requiredPermissions) as $permission) {
        $assert(isset($definedSet[$permission]), "requirePermission() uses an unassignable slug: {$permission}");
    }

    // Generic custom-role membership must not regain administrator privileges.
    $auth = $source('includes/auth.php');
    $start = strpos($auth, 'function isAdmin()');
    $end = strpos($auth, 'function isSystemAdmin()', $start ?: 0);
    $isAdminBody = $start !== false && $end !== false ? substr($auth, $start, $end - $start) : '';
    $assert($isAdminBody !== '' && !str_contains($isAdminBody, 'custom_role_id'), 'isAdmin() must not grant access from custom_role_id');

    // Ride endpoints: GET data is read-only; commands require POST, CSRF, and mission access.
    $readEndpoint = $source('api-ride-data.php');
    foreach (['requireLogin()', 'canAccessRideMission', 'rideSchemaCapabilities'] as $needle) {
        $assert(str_contains($readEndpoint, $needle), "api-ride-data.php missing read guard: {$needle}");
    }
    foreach (['recordRideEvent(', 'dbExecute(', 'dbInsert('] as $forbidden) {
        $assert(!str_contains($readEndpoint, $forbidden), "api-ride-data.php must remain read-only: {$forbidden}");
    }

    $commandEndpoints = [
        'api-ride-ping.php'          => ['canAccessRideMission', '$_POST[\'csrf_token\']', 'recordRideEvent('],
        'api-ride-navigate.php'      => ['canAccessRideMission', '$_POST[\'csrf_token\']'],
        'api-ride-checklist.php'     => ['isRideController', '$_POST[\'csrf_token\']', 'setRideChecklistItem('],
        'api-ride-event-resolve.php' => ['isRideController', '$_POST[\'csrf_token\']', 'resolveRideEvent('],
    ];
    foreach ($commandEndpoints as $file => $needles) {
        $endpoint = $source($file);
        $assert(str_contains($endpoint, 'requireLogin()'), "{$file} must require login");
        $assert(str_contains($endpoint, 'if (!isPost())'), "{$file} must reject non-POST requests");
        foreach ($needles as $needle) {
            $assert(str_contains($endpoint, $needle), "{$file} missing command guard: {$needle}");
        }
    }

    // Member rename compatibility: current routes and templates must exist,
    // while incoming MEMBER labels are normalized to the stored enum value.
    $membersPage = $source('members.php');
    $assert(str_contains($membersPage, 'exports/export-members.php'), 'members.php must link to the member export endpoint');
    $assert(is_file($projectRoot . '/exports/export-members.php'), 'Member export endpoint is missing');
    $assert(is_file($projectRoot . '/exports/templates/members_template.csv'), 'Member import template is missing');
    $importFunctions = $source('includes/import-functions.php');
    $assert(substr_count($importFunctions, "if (\$role === 'MEMBER')") >= 2, 'CSV MEMBER role must be normalized for validation and import');
    $assert(substr_count($importFunctions, "if (\$vtype === 'MEMBER')") === 1, 'CSV MEMBER type must be normalized for validation');
    $assert(substr_count($importFunctions, "if (\$rawType === 'MEMBER')") === 1, 'CSV MEMBER type must be normalized for import');

    // Retired public artifacts must not be restored, and web-server rules must
    // deny operational endpoints and accidental SQL/log/config exposure.
    foreach (['test_error.php', 'pwa-announcement-email.html', 'demo_data.sql', 'scripts/maintenance/test_app.php', 'scripts/maintenance/test_full.php'] as $retiredPath) {
        $assert(!is_file($projectRoot . '/' . $retiredPath), "Retired legacy artifact restored: {$retiredPath}");
    }
    $htaccess = $source('.htaccess');
    foreach (['backup-ajax', 'update', 'config\\.local\\.php', '(?:sql|log|bak|backup|dist|md)'] as $needle) {
        $assert(str_contains($htaccess, $needle), ".htaccess missing legacy hardening rule: {$needle}");
    }

    // Outbound messages and exported calendar artefacts must use the current product name.
    foreach (['includes/email.php', 'email-template-preview.php', 'api-shifts-calendar-ics.php'] as $file) {
        $content = $source($file);
        $assert(!str_contains($content, 'VolunteerOps') && !str_contains($content, 'volunteerops'), "Legacy brand remains in {$file}");
    }
} catch (Throwable $exception) {
    $failures[] = 'Test setup failure: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Authorization contract failures (" . count($failures) . "/{$assertions}):" . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}" . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "Authorization contract passed ({$assertions} assertions)." . PHP_EOL);
