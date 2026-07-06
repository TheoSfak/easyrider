<?php
/**
 * EasyRide - Ride Mode live data endpoint
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

$user = getCurrentUser();
$userId = (int)$user['id'];
$missionId = (int)get('mission_id');

if ($missionId <= 0) {
    $respond(['ok' => false, 'error' => 'Δεν βρέθηκε δράση.'], 422);
}

$mission = getRideMission($missionId);
if (!$mission || !canAccessRideMission($mission, $user)) {
    $respond(['ok' => false, 'error' => 'Δεν έχετε πρόσβαση στο Ride Mode αυτής της δράσης.'], 403);
}

$isController = isRideController($mission, $user);
$routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
$directionsUrl = buildRideDirectionsUrl($routePoints);
$shiftIds = getRideShiftIds($missionId);
$participants = [];
$alerts = [];
$partnerAssists = [];
$partnerPinsById = [];
$readiness = [
    'approved' => 0,
    'tracking' => 0,
    'stale' => 0,
    'not_started' => 0,
    'with_status' => 0,
];
$latestByUser = [];
$recentByUser = [];
$eventCandidates = [];
$statusOptions = rideStatusOptions();
$offRouteThresholdMeters = 350;
$stationaryWindowMinutes = 5;
$stationaryThresholdMeters = 35;
$resolvedFingerprints = getResolvedRideEventFingerprints($missionId);
$checklist = getRideChecklistSummary($missionId);

if (!empty($shiftIds)) {
    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));

    $participantRows = dbFetchAll(
        "SELECT pr.id as participation_id, pr.shift_id, pr.field_status, pr.field_status_updated_at,
                u.id as user_id, u.name, u.phone
         FROM participation_requests pr
         JOIN users u ON u.id = pr.member_id
         WHERE pr.shift_id IN ($placeholders)
           AND pr.status = ?
         ORDER BY u.name ASC",
        array_merge($shiftIds, [PARTICIPATION_APPROVED])
    );

    try {
        $columns = dbFetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'member_pings'"
        );
        $existing = array_flip(array_column($columns, 'COLUMN_NAME'));
        $optionalSelect = [];
        foreach (['accuracy', 'speed', 'heading', 'battery_level', 'status'] as $column) {
            $optionalSelect[] = isset($existing[$column]) ? "vp.{$column}" : "NULL as {$column}";
        }

        $pingRows = dbFetchAll(
            "SELECT vp.user_id, vp.shift_id, vp.lat, vp.lng, vp.created_at,
                    " . implode(', ', $optionalSelect) . "
             FROM member_pings vp
             WHERE vp.shift_id IN ($placeholders)
               AND vp.created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
               AND vp.id = (
                   SELECT MAX(vp2.id)
                   FROM member_pings vp2
                   WHERE vp2.user_id = vp.user_id
                     AND vp2.shift_id IN ($placeholders)
               )",
            array_merge($shiftIds, $shiftIds)
        );

        foreach ($pingRows as $row) {
            $latestByUser[(int)$row['user_id']] = $row;
        }

        $recentRows = dbFetchAll(
            "SELECT vp.user_id, vp.lat, vp.lng, vp.created_at
             FROM member_pings vp
             WHERE vp.shift_id IN ($placeholders)
               AND vp.created_at >= DATE_SUB(NOW(), INTERVAL {$stationaryWindowMinutes} MINUTE)
             ORDER BY vp.user_id ASC, vp.created_at ASC",
            $shiftIds
        );
        foreach ($recentRows as $row) {
            $recentByUser[(int)$row['user_id']][] = $row;
        }
    } catch (Exception $e) {
        $latestByUser = [];
        $recentByUser = [];
    }

    foreach ($participantRows as $row) {
        $uid = (int)$row['user_id'];
        $ping = $latestByUser[$uid] ?? null;
        $lastPingTs = $ping ? strtotime($ping['created_at']) : null;
        $ageSeconds = $lastPingTs ? max(0, time() - $lastPingTs) : null;
        $status = $row['field_status'] ?: ($ping['status'] ?? null);
        $distanceFromRoute = null;
        $isOffRoute = false;
        $stationaryDistance = null;
        $isStationary = false;

        if ($ping && !empty($routePoints)) {
            $distanceFromRoute = rideDistanceFromRouteMeters(
                ['lat' => (float)$ping['lat'], 'lng' => (float)$ping['lng']],
                $routePoints
            );
            $isOffRoute = $distanceFromRoute !== null && $distanceFromRoute > $offRouteThresholdMeters;
        }

        if ($ping && isset($recentByUser[$uid]) && count($recentByUser[$uid]) >= 2) {
            $firstRecent = $recentByUser[$uid][0];
            $stationaryDistance = rideDistanceMeters(
                (float)$firstRecent['lat'],
                (float)$firstRecent['lng'],
                (float)$ping['lat'],
                (float)$ping['lng']
            );
            $isStationary = $stationaryDistance <= $stationaryThresholdMeters
                && $ageSeconds !== null
                && $ageSeconds <= 120
                && !in_array($status, ['on_site', 'needs_help', 'breakdown'], true);
        }

        $participant = [
            'user_id' => $uid,
            'participation_id' => (int)$row['participation_id'],
            'shift_id' => (int)$row['shift_id'],
            'name' => $row['name'],
            'phone' => $isController ? ($row['phone'] ?? '') : '',
            'is_self' => $uid === $userId,
            'status' => $status,
            'status_label' => $statusOptions[$status]['label'] ?? '',
            'status_color' => $statusOptions[$status]['color'] ?? '#0d6efd',
            'last_ping_at' => $ping ? date('H:i:s', $lastPingTs) : null,
            'last_ping_age_seconds' => $ageSeconds,
            'is_stale' => $ageSeconds !== null && $ageSeconds > 120,
            'lat' => $ping ? (float)$ping['lat'] : null,
            'lng' => $ping ? (float)$ping['lng'] : null,
            'accuracy' => $ping && $ping['accuracy'] !== null ? (float)$ping['accuracy'] : null,
            'speed' => $ping && $ping['speed'] !== null ? (float)$ping['speed'] : null,
            'heading' => $ping && $ping['heading'] !== null ? (float)$ping['heading'] : null,
            'battery_level' => $ping && $ping['battery_level'] !== null ? (int)$ping['battery_level'] : null,
            'distance_from_route_meters' => $distanceFromRoute !== null ? round($distanceFromRoute) : null,
            'is_off_route' => $isOffRoute,
            'is_stationary' => $isStationary,
            'stationary_distance_meters' => $stationaryDistance !== null ? round($stationaryDistance) : null,
        ];
        $participants[] = $participant;
        $readiness['approved']++;
        if ($ping && !$participant['is_stale']) {
            $readiness['tracking']++;
        } elseif ($participant['is_stale']) {
            $readiness['stale']++;
        } else {
            $readiness['not_started']++;
        }
        if (!empty($status)) {
            $readiness['with_status']++;
        }

        if (in_array($status, ['needs_help', 'breakdown', 'left_behind'], true)) {
            $alert = [
                'type' => $status,
                'severity' => $status === 'needs_help' ? 'danger' : 'warning',
                'name' => $row['name'],
                'user_id' => $participant['user_id'],
                'message' => ($statusOptions[$status]['label'] ?? 'Ειδοποίηση') . ': ' . $row['name'],
                'lat' => $participant['lat'],
                'lng' => $participant['lng'],
            ];
            $alerts[] = $alert;
        } elseif ($isOffRoute) {
            $alert = [
                'type' => 'off_route',
                'severity' => 'warning',
                'name' => $row['name'],
                'user_id' => $participant['user_id'],
                'message' => 'Εκτός διαδρομής: ' . $row['name'] . ' (' . rideFormatDistance($distanceFromRoute) . ' από τη διαδρομή)',
                'lat' => $participant['lat'],
                'lng' => $participant['lng'],
            ];
            $alerts[] = $alert;
            $eventCandidates[] = [
                'event_type' => 'off_route',
                'severity' => 'warning',
                'title' => 'Εκτός διαδρομής',
                'message' => $alert['message'],
                'participant' => $participant,
                'metadata' => ['distance_from_route_meters' => round($distanceFromRoute)],
            ];
        } elseif ($isStationary) {
            $alert = [
                'type' => 'stationary',
                'severity' => 'warning',
                'name' => $row['name'],
                'user_id' => $participant['user_id'],
                'message' => 'Ακίνητος περίπου ' . $stationaryWindowMinutes . ' λεπτά: ' . $row['name'],
                'lat' => $participant['lat'],
                'lng' => $participant['lng'],
            ];
            $alerts[] = $alert;
            $eventCandidates[] = [
                'event_type' => 'stationary',
                'severity' => 'warning',
                'title' => 'Ακίνητος',
                'message' => $alert['message'],
                'participant' => $participant,
                'metadata' => [
                    'window_minutes' => $stationaryWindowMinutes,
                    'movement_meters' => $stationaryDistance !== null ? round($stationaryDistance) : null,
                ],
            ];
        } elseif ($participant['is_stale']) {
            $alert = [
                'type' => 'stale',
                'severity' => 'secondary',
                'name' => $row['name'],
                'user_id' => $participant['user_id'],
                'message' => 'Χωρίς πρόσφατο στίγμα: ' . $row['name'] . ' (' . round($ageSeconds / 60) . ' λεπτά)',
                'lat' => $participant['lat'],
                'lng' => $participant['lng'],
            ];
            $alerts[] = $alert;
            $eventCandidates[] = [
                'event_type' => 'stale',
                'severity' => 'secondary',
                'title' => 'Χωρίς πρόσφατο στίγμα',
                'message' => $alert['message'],
                'participant' => $participant,
                'metadata' => ['age_seconds' => $ageSeconds],
            ];
        }
    }
}

foreach ($eventCandidates as $candidate) {
    $participant = $candidate['participant'];
    $fingerprintBucket = date('YmdHi') . ':' . floor((int)date('i') / 5);
    $fingerprint = 'auto:' . $missionId . ':' . $participant['user_id'] . ':' . $candidate['event_type'] . ':' . $fingerprintBucket;
    if (isset($resolvedFingerprints[$fingerprint])) {
        continue;
    }
    recordRideEvent([
        'mission_id' => $missionId,
        'shift_id' => $participant['shift_id'],
        'user_id' => $participant['user_id'],
        'participation_id' => $participant['participation_id'],
        'event_type' => $candidate['event_type'],
        'severity' => $candidate['severity'],
        'title' => $candidate['title'],
        'message' => $candidate['message'],
        'lat' => $participant['lat'],
        'lng' => $participant['lng'],
        'metadata' => $candidate['metadata'],
        'fingerprint' => $fingerprint,
    ]);
}

$eventRows = getRideEvents($missionId, 25, false);
$activeEventKeys = [];
foreach (getRideEvents($missionId, 100, true) as $event) {
    if (!$event['user_id']) {
        continue;
    }
    $eventType = $event['event_type'];
    if (str_starts_with($eventType, 'status_')) {
        $eventType = substr($eventType, 7);
    }
    $activeEventKeys[$event['user_id'] . ':' . $eventType] = true;
}
$alerts = array_values(array_filter($alerts, function ($alert) use ($activeEventKeys) {
    $userId = $alert['user_id'] ?? null;
    if (!$userId) {
        return true;
    }
    return isset($activeEventKeys[$userId . ':' . $alert['type']]);
}));

foreach ($alerts as $alert) {
    if (!in_array($alert['type'], ['needs_help', 'breakdown', 'left_behind', 'off_route', 'stationary'], true)) {
        continue;
    }
    if (empty($alert['lat']) || empty($alert['lng'])) {
        continue;
    }

    $nearbyPartners = getRideNearbyPartners((float)$alert['lat'], (float)$alert['lng'], 4);
    if (empty($nearbyPartners)) {
        continue;
    }

    foreach ($nearbyPartners as $partner) {
        $partnerPinsById[$partner['id']] = $partner;
    }

    $partnerAssists[] = [
        'alert_type' => $alert['type'],
        'name' => $alert['name'],
        'message' => $alert['message'],
        'lat' => $alert['lat'],
        'lng' => $alert['lng'],
        'partners' => $nearbyPartners,
    ];
}

$events = array_map(function ($event) {
    return [
        'id' => (int)$event['id'],
        'event_type' => $event['event_type'],
        'severity' => $event['severity'],
        'title' => $event['title'],
        'message' => $event['message'],
        'user_name' => $event['user_name'],
        'created_at' => date('H:i:s', strtotime($event['created_at'])),
        'created_at_full' => $event['created_at'],
        'resolved_at' => $event['resolved_at'],
        'resolved_by_name' => $event['resolved_by_name'],
        'is_resolved' => !empty($event['resolved_at']),
        'lat' => $event['lat'] !== null ? (float)$event['lat'] : null,
        'lng' => $event['lng'] !== null ? (float)$event['lng'] : null,
    ];
}, $eventRows);

$respond([
    'ok' => true,
    'ts' => date('H:i:s'),
    'mission' => [
        'id' => (int)$mission['id'],
        'title' => $mission['title'],
        'location' => $mission['location'],
        'start_datetime' => $mission['start_datetime'],
        'end_datetime' => $mission['end_datetime'],
        'route_points' => $routePoints,
        'directions_url' => $directionsUrl,
    ],
    'is_controller' => $isController,
    'can_resolve_events' => $isController,
    'readiness' => $readiness,
    'checklist' => $checklist,
    'participants' => $participants,
    'alerts' => $alerts,
    'partner_assists' => $partnerAssists,
    'partner_pins' => array_values($partnerPinsById),
    'events' => $events,
]);
