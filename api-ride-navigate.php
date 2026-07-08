<?php
/**
 * EasyRide - Ride Mode "navigating to Google Maps" signal endpoint
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

if ($missionId <= 0) {
    $respond(['ok' => false, 'error' => 'Δεν βρέθηκε δράση.'], 422);
}

$mission = getRideMission($missionId);
if (!$mission || !canAccessRideMission($mission, $user)) {
    $respond(['ok' => false, 'error' => 'Δεν έχετε πρόσβαση στο Ride Mode αυτής της δράσης.'], 403);
}

$participation = getRideParticipation($missionId, $userId, $requestedShiftId > 0 ? $requestedShiftId : null);
if (!$participation) {
    $respond(['ok' => false, 'error' => 'Δεν βρέθηκε εγκεκριμένη συμμετοχή.'], 403);
}

dbExecute(
    "UPDATE participation_requests SET navigating_since = NOW() WHERE id = ?",
    [(int)$participation['participation_id']]
);

$respond(['ok' => true]);
