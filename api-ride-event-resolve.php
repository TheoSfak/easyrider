<?php
/**
 * EasyRide - Resolve/Acknowledge a Ride Mode incident event
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

$eventId = (int)post('event_id');
if ($eventId <= 0) {
    $respond(['ok' => false, 'error' => 'Δεν βρέθηκε συμβάν.'], 422);
}

$event = getRideEvent($eventId);
if (!$event) {
    $respond(['ok' => false, 'error' => 'Το συμβάν δεν βρέθηκε.'], 404);
}

$mission = getRideMission((int)$event['mission_id']);
$user = getCurrentUser();
if (!$mission || !isRideController($mission, $user)) {
    $respond(['ok' => false, 'error' => 'Μόνο admin, road captain ή υπεύθυνος δράσης μπορεί να επιλύσει συμβάντα.'], 403);
}

resolveRideEvent($eventId, (int)$user['id']);
logAudit('resolve_ride_event', 'ride_events', $eventId);

$respond(['ok' => true, 'event_id' => $eventId, 'resolved_by' => $user['name'] ?? '']);
