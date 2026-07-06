<?php
/**
 * EasyRide - Ride Mode GPS ping endpoint
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ride-functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$respond = function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isPost()) {
    $respond(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $respond(['ok' => false, 'error' => 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.'], 403);
}

$user = getCurrentUser();
$userId = (int)$user['id'];
$missionId = (int)post('mission_id');
$requestedShiftId = (int)post('shift_id', 0);
$lat = (float)post('lat');
$lng = (float)post('lng');
$accuracy = post('accuracy', null);
$speed = post('speed', null);
$heading = post('heading', null);
$batteryLevel = post('battery_level', null);
$status = post('status', '');
$statusOptions = rideStatusOptions();

if ($missionId <= 0) {
    $respond(['ok' => false, 'error' => 'Δεν βρέθηκε δράση.'], 422);
}

$mission = getRideMission($missionId);
if (!$mission || !canAccessRideMission($mission, $user)) {
    $respond(['ok' => false, 'error' => 'Δεν έχετε πρόσβαση στο Ride Mode αυτής της δράσης.'], 403);
}

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
    $respond(['ok' => false, 'error' => 'Μη έγκυρες συντεταγμένες.'], 422);
}

$participation = getRideParticipation($missionId, $userId, $requestedShiftId > 0 ? $requestedShiftId : null);
if (!$participation) {
    $respond(['ok' => false, 'error' => 'Μπορείτε να στείλετε στίγμα μόνο αν έχετε εγκεκριμένη συμμετοχή στη δράση.'], 403);
}

if ($status !== '' && !isset($statusOptions[$status])) {
    $respond(['ok' => false, 'error' => 'Μη έγκυρη κατάσταση Ride Mode.'], 422);
}

$accuracy = is_numeric($accuracy) ? max(0, min(999999.99, (float)$accuracy)) : null;
$speed = is_numeric($speed) ? max(0, min(999999.99, (float)$speed)) : null;
$heading = is_numeric($heading) ? max(0, min(359.99, (float)$heading)) : null;
$batteryLevel = is_numeric($batteryLevel) ? max(0, min(100, (int)round((float)$batteryLevel))) : null;

try {
    $columns = dbFetchAll(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'member_pings'"
    );
    $existing = array_flip(array_column($columns, 'COLUMN_NAME'));

    $insertColumns = ['user_id', 'shift_id', 'lat', 'lng'];
    $placeholders = ['?', '?', '?', '?'];
    $values = [$userId, (int)$participation['shift_id'], $lat, $lng];

    $optional = [
        'accuracy' => $accuracy,
        'speed' => $speed,
        'heading' => $heading,
        'battery_level' => $batteryLevel,
        'status' => $status !== '' ? $status : null,
    ];
    foreach ($optional as $column => $value) {
        if (isset($existing[$column])) {
            $insertColumns[] = $column;
            $placeholders[] = '?';
            $values[] = $value;
        }
    }

    $insertColumns[] = 'created_at';
    $placeholders[] = 'NOW()';

    dbInsert(
        'INSERT INTO member_pings (' . implode(',', $insertColumns) . ') VALUES (' . implode(',', $placeholders) . ')',
        $values
    );

    if ($status !== '') {
        dbExecute(
            "UPDATE participation_requests
             SET field_status = ?, field_status_updated_at = NOW()
             WHERE id = ?",
            [$status, (int)$participation['participation_id']]
        );

        $eventSeverity = in_array($status, ['needs_help'], true)
            ? 'danger'
            : (in_array($status, ['breakdown', 'left_behind'], true) ? 'warning' : 'info');
        $statusLabel = $statusOptions[$status]['label'] ?? $status;
        $fingerprintBucket = date('YmdHi') . ':' . floor((int)date('i') / 5);
        recordRideEvent([
            'mission_id' => $missionId,
            'shift_id' => (int)$participation['shift_id'],
            'user_id' => $userId,
            'participation_id' => (int)$participation['participation_id'],
            'event_type' => 'status_' . $status,
            'severity' => $eventSeverity,
            'title' => $statusLabel,
            'message' => ($user['name'] ?? 'Μέλος') . ': ' . $statusLabel,
            'lat' => $lat,
            'lng' => $lng,
            'metadata' => [
                'accuracy' => $accuracy,
                'speed' => $speed,
                'heading' => $heading,
                'battery_level' => $batteryLevel,
            ],
            'fingerprint' => 'manual:' . $missionId . ':' . $userId . ':' . $status . ':' . $fingerprintBucket,
        ]);
    }
} catch (Exception $e) {
    $respond(['ok' => false, 'error' => 'Η αποστολή στίγματος δεν είναι διαθέσιμη ακόμη.'], 500);
}

$respond([
    'ok' => true,
    'ts' => date('H:i:s'),
    'shift_id' => (int)$participation['shift_id'],
    'status' => $status,
]);
