<?php
/**
 * EasyRide - Ride Start Checklist endpoint
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

$missionId = (int)post('mission_id');
$itemKey = post('item_key');
$completed = post('completed') === '1';
$mission = $missionId > 0 ? getRideMission($missionId) : null;
$user = getCurrentUser();

if (!$mission || !isRideController($mission, $user)) {
    $respond(['ok' => false, 'error' => 'Δεν έχετε δικαίωμα αλλαγής checklist.'], 403);
}

if (!setRideChecklistItem($missionId, $itemKey, $completed, (int)$user['id'])) {
    $respond(['ok' => false, 'error' => 'Το checklist δεν ενημερώθηκε.'], 422);
}

logAudit('update_ride_checklist', 'missions', $missionId, ['item_key' => $itemKey, 'completed' => $completed]);
$respond(['ok' => true, 'checklist' => getRideChecklistSummary($missionId)]);
