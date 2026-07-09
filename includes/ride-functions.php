<?php
/**
 * EasyRide - Ride Mode helpers
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

function rideStatusOptions(): array {
    return [
        'on_way'      => ['label' => 'Σε κίνηση', 'icon' => 'bi-signpost-2-fill', 'color' => '#fd7e14'],
        'on_site'     => ['label' => 'Στάση', 'icon' => 'bi-cup-hot-fill', 'color' => '#198754'],
        'needs_help'  => ['label' => 'SOS', 'icon' => 'bi-exclamation-octagon-fill', 'color' => '#dc3545'],
        'breakdown'   => ['label' => 'Βλάβη', 'icon' => 'bi-tools', 'color' => '#6f42c1'],
        'left_behind' => ['label' => 'Έμεινα πίσω', 'icon' => 'bi-person-dash-fill', 'color' => '#0dcaf0'],
        'ok'          => ['label' => 'Είμαι ΟΚ', 'icon' => 'bi-check-circle-fill', 'color' => '#20c997'],
    ];
}

function normalizeRideRoutePoints($raw): array {
    $points = is_array($raw) ? $raw : json_decode($raw ?? '[]', true);
    if (!is_array($points)) {
        return [];
    }

    $points = array_filter($points, function ($point) {
        return is_array($point)
            && isset($point['lat'], $point['lng'])
            && is_numeric($point['lat'])
            && is_numeric($point['lng']);
    });

    return array_values(array_map(function ($point) {
        return [
            'lat' => (float)$point['lat'],
            'lng' => (float)$point['lng'],
            'title' => trim((string)($point['title'] ?? '')),
            'scheduled_time' => trim((string)($point['scheduled_time'] ?? '')),
            'stop_minutes' => max(0, (int)($point['stop_minutes'] ?? 0)),
            'notes' => trim((string)($point['notes'] ?? '')),
        ];
    }, $points));
}

function buildRideDirectionsUrl(array $routePoints): string {
    if (count($routePoints) < 2) {
        return '';
    }

    $origin = $routePoints[0]['lat'] . ',' . $routePoints[0]['lng'];
    $destinationPoint = $routePoints[count($routePoints) - 1];
    $destination = $destinationPoint['lat'] . ',' . $destinationPoint['lng'];
    $waypoints = array_slice($routePoints, 1, -1);

    $params = [
        'api' => '1',
        'origin' => $origin,
        'destination' => $destination,
        'travelmode' => 'driving',
    ];
    if (!empty($waypoints)) {
        $params['waypoints'] = implode('|', array_map(fn($point) => $point['lat'] . ',' . $point['lng'], $waypoints));
    }

    return 'https://www.google.com/maps/dir/?' . http_build_query($params);
}

function getRideMission(int $missionId): ?array {
    return dbFetchOne(
        "SELECT m.*, u.name as responsible_name
         FROM missions m
         LEFT JOIN users u ON u.id = m.responsible_user_id
         WHERE m.id = ? AND m.deleted_at IS NULL",
        [$missionId]
    );
}

function isRideController(array $mission, array $user): bool {
    return isSystemAdmin()
        || isAdmin()
        || (($user['role'] ?? '') === ROLE_SHIFT_LEADER)
        || ((int)($mission['responsible_user_id'] ?? 0) === (int)$user['id']);
}

function getRideParticipation(int $missionId, int $userId, ?int $shiftId = null): ?array {
    $params = [$missionId, $userId, PARTICIPATION_APPROVED];
    $shiftFilter = '';
    if ($shiftId !== null && $shiftId > 0) {
        $shiftFilter = ' AND s.id = ?';
        $params[] = $shiftId;
    }

    return dbFetchOne(
        "SELECT pr.id as participation_id, pr.shift_id, pr.field_status, pr.field_status_updated_at,
                s.start_time, s.end_time
         FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ?
           AND pr.member_id = ?
           AND pr.status = ?
           {$shiftFilter}
         ORDER BY
           CASE WHEN s.start_time <= NOW() AND s.end_time >= NOW() THEN 0 ELSE 1 END,
           ABS(TIMESTAMPDIFF(MINUTE, s.start_time, NOW())) ASC,
           s.start_time ASC
         LIMIT 1",
        $params
    );
}

function canAccessRideMission(array $mission, array $user): bool {
    if (isRideController($mission, $user)) {
        return true;
    }

    return (bool) getRideParticipation((int)$mission['id'], (int)$user['id']);
}

function getRideShiftIds(int $missionId): array {
    $rows = dbFetchAll("SELECT id FROM shifts WHERE mission_id = ? ORDER BY start_time ASC", [$missionId]);
    return array_map(fn($row) => (int)$row['id'], $rows);
}

function rideDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadius = 6371000;
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLng = deg2rad($lng2 - $lng1);

    $a = sin($deltaLat / 2) ** 2
        + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function ridePointToSegmentDistanceMeters(array $point, array $start, array $end): float {
    $latScale = 111320;
    $lngScale = 111320 * cos(deg2rad($point['lat']));

    $px = $point['lng'] * $lngScale;
    $py = $point['lat'] * $latScale;
    $sx = $start['lng'] * $lngScale;
    $sy = $start['lat'] * $latScale;
    $ex = $end['lng'] * $lngScale;
    $ey = $end['lat'] * $latScale;

    $dx = $ex - $sx;
    $dy = $ey - $sy;
    $lenSquared = $dx * $dx + $dy * $dy;
    if ($lenSquared <= 0) {
        return rideDistanceMeters($point['lat'], $point['lng'], $start['lat'], $start['lng']);
    }

    $t = (($px - $sx) * $dx + ($py - $sy) * $dy) / $lenSquared;
    $t = max(0, min(1, $t));

    $closestX = $sx + $t * $dx;
    $closestY = $sy + $t * $dy;

    return sqrt(($px - $closestX) ** 2 + ($py - $closestY) ** 2);
}

function rideDistanceFromRouteMeters(array $point, array $routePoints): ?float {
    $count = count($routePoints);
    if ($count === 0) {
        return null;
    }
    if ($count === 1) {
        return rideDistanceMeters($point['lat'], $point['lng'], $routePoints[0]['lat'], $routePoints[0]['lng']);
    }

    $minDistance = null;
    for ($i = 0; $i < $count - 1; $i++) {
        $distance = ridePointToSegmentDistanceMeters($point, $routePoints[$i], $routePoints[$i + 1]);
        $minDistance = $minDistance === null ? $distance : min($minDistance, $distance);
    }

    return $minDistance;
}

function rideFormatDistance(float $meters): string {
    if ($meters >= 1000) {
        return number_format($meters / 1000, 1, ',', '.') . ' km';
    }
    return (string)round($meters) . ' m';
}

function rideRouteDistanceMeters(array $routePoints): float {
    $distance = 0.0;
    $count = count($routePoints);
    for ($i = 0; $i < $count - 1; $i++) {
        $distance += rideDistanceMeters(
            (float)$routePoints[$i]['lat'],
            (float)$routePoints[$i]['lng'],
            (float)$routePoints[$i + 1]['lat'],
            (float)$routePoints[$i + 1]['lng']
        );
    }

    return $distance;
}

function rideRouteStopMinutes(array $routePoints): int {
    $minutes = 0;
    foreach ($routePoints as $point) {
        $minutes += max(0, (int)($point['stop_minutes'] ?? 0));
    }

    return $minutes;
}

function rideEstimateRouteMovingMinutes(float $distanceMeters, ?float $averageKmh = null): int {
    if ($distanceMeters <= 0) {
        return 0;
    }

    $averageKmh = $averageKmh ?: (float)getSetting('ride_route_avg_speed_kmh', '55');
    if ($averageKmh < 10 || $averageKmh > 140) {
        $averageKmh = 55.0;
    }

    return max(1, (int)ceil(($distanceMeters / 1000) / $averageKmh * 60));
}

function rideFormatDurationMinutes(int $minutes): string {
    $minutes = max(0, $minutes);
    if ($minutes < 60) {
        return $minutes . "'";
    }

    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    return $remaining > 0 ? $hours . 'ω ' . $remaining . "'" : $hours . 'ω';
}

function rideRouteMetrics(array $routePoints): array {
    $distanceMeters = rideRouteDistanceMeters($routePoints);
    $movingMinutes = rideEstimateRouteMovingMinutes($distanceMeters);
    $stopMinutes = rideRouteStopMinutes($routePoints);
    $totalMinutes = $movingMinutes + $stopMinutes;

    return [
        'distance_meters' => $distanceMeters,
        'distance_label' => rideFormatDistance($distanceMeters),
        'moving_minutes' => $movingMinutes,
        'moving_label' => rideFormatDurationMinutes($movingMinutes),
        'stop_minutes' => $stopMinutes,
        'stop_label' => rideFormatDurationMinutes($stopMinutes),
        'total_minutes' => $totalMinutes,
        'total_label' => rideFormatDurationMinutes($totalMinutes),
        'point_count' => count($routePoints),
        'estimated' => true,
    ];
}

function rideRouteGeometry(array $mission): array {
    if (empty($mission['route_geometry'])) {
        return [];
    }

    $geometry = json_decode((string)$mission['route_geometry'], true);
    if (!is_array($geometry)) {
        return [];
    }

    $points = [];
    foreach ($geometry as $pair) {
        if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
            continue;
        }
        $points[] = [(float)$pair[0], (float)$pair[1]];
    }

    return count($points) >= 2 ? $points : [];
}

function rideReplayPoints(array $geometry, array $points): array {
    if (count($geometry) >= 2) {
        return $geometry;
    }
    $pairs = array_map(fn($p) => [(float)$p['lat'], (float)$p['lng']], $points);
    return count($pairs) >= 2 ? $pairs : [];
}

function rideEventRoutePositions(array $routeGeometry, array $events): array {
    $vertexCount = count($routeGeometry);

    if ($vertexCount < 2) {
        foreach ($events as &$event) {
            $event['route_fraction'] = null;
        }
        unset($event);
        return $events;
    }

    $cumulative = [0.0];
    for ($i = 1; $i < $vertexCount; $i++) {
        $cumulative[] = $cumulative[$i - 1] + rideDistanceMeters(
            (float)$routeGeometry[$i - 1][0],
            (float)$routeGeometry[$i - 1][1],
            (float)$routeGeometry[$i][0],
            (float)$routeGeometry[$i][1]
        );
    }
    $totalDistance = $cumulative[$vertexCount - 1];

    foreach ($events as &$event) {
        $lat = $event['lat'] ?? null;
        $lng = $event['lng'] ?? null;

        if ($lat === null || $lng === null || $totalDistance <= 0) {
            $event['route_fraction'] = null;
            continue;
        }

        $nearestIndex = 0;
        $nearestDistance = null;
        for ($i = 0; $i < $vertexCount; $i++) {
            $distance = rideDistanceMeters(
                (float)$lat,
                (float)$lng,
                (float)$routeGeometry[$i][0],
                (float)$routeGeometry[$i][1]
            );
            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestIndex = $i;
            }
        }

        $event['route_fraction'] = $cumulative[$nearestIndex] / $totalDistance;
    }
    unset($event);

    return $events;
}

function buildReplayEvents(array $routePoints, array $events): array {
    $replayEvents = [];
    $eventsWithFractions = rideEventRoutePositions($routePoints, $events);
    foreach ($eventsWithFractions as $event) {
        if ($event['route_fraction'] === null) {
            continue;
        }
        $replayEvents[] = [
            'lat' => (float)$event['lat'],
            'lng' => (float)$event['lng'],
            'title' => (string)$event['title'],
            'severity' => (string)($event['severity'] ?? 'info'),
            'routeFraction' => (float)$event['route_fraction'],
        ];
    }
    return $replayEvents;
}

function ridePingTableExists(): bool {
    try {
        return (bool) dbFetchValue(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'member_pings'"
        );
    } catch (Exception $e) {
        return false;
    }
}

function rideReplayColor(int $index): string {
    $colors = [
        '#2563eb', '#dc2626', '#16a34a', '#f59e0b', '#7c3aed',
        '#0891b2', '#db2777', '#65a30d', '#ea580c', '#475569',
    ];
    return $colors[$index % count($colors)];
}

function buildActualRideReplayData(array $mission, ?string $dayDate = null, bool $public = false): array {
    $missionId = (int)($mission['id'] ?? 0);
    $shiftIds = getRideShiftIds($missionId);
    $empty = [
        'mode' => 'actual',
        'tracks' => [],
        'events' => [],
        'referenceRoute' => [],
        'startTime' => null,
        'endTime' => null,
        'durationSeconds' => 0,
        'distanceMeters' => 0.0,
        'totalMinutes' => 0,
        'hasActualData' => false,
    ];

    if ($missionId <= 0 || empty($shiftIds) || !ridePingTableExists()) {
        return $empty;
    }

    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
    $params = $shiftIds;
    $dayFilter = '';
    if ($dayDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayDate)) {
        $dayFilter = ' AND DATE(mp.created_at) = ?';
        $params[] = $dayDate;
    }

    try {
        $rows = dbFetchAll(
            "SELECT mp.user_id, mp.shift_id, mp.lat, mp.lng, mp.accuracy, mp.speed, mp.heading,
                    mp.battery_level, mp.status, mp.created_at, u.name AS user_name
             FROM member_pings mp
             LEFT JOIN users u ON u.id = mp.user_id
             WHERE mp.shift_id IN ($placeholders)
               {$dayFilter}
             ORDER BY mp.created_at ASC, mp.id ASC",
            $params
        );
    } catch (Exception $e) {
        return $empty;
    }

    if (empty($rows)) {
        return $empty;
    }

    $statusOptions = rideStatusOptions();
    $tracksByUser = [];
    $firstTs = null;
    $lastTs = null;

    foreach ($rows as $row) {
        $lat = (float)$row['lat'];
        $lng = (float)$row['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            continue;
        }

        $ts = strtotime((string)$row['created_at']);
        if (!$ts) {
            continue;
        }

        $userId = (int)$row['user_id'];
        if (!isset($tracksByUser[$userId])) {
            $tracksByUser[$userId] = [
                'user_id' => $userId,
                'name' => trim((string)($row['user_name'] ?? '')) ?: 'Μέλος',
                'points' => [],
                'distanceMeters' => 0.0,
            ];
        }

        $status = (string)($row['status'] ?? '');
        $tracksByUser[$userId]['points'][] = [
            'lat' => $lat,
            'lng' => $lng,
            'ts' => $ts,
            'time' => date('H:i:s', $ts),
            'status' => $status !== '' ? $status : null,
            'statusLabel' => $status !== '' ? ($statusOptions[$status]['label'] ?? $status) : null,
            'accuracy' => $row['accuracy'] !== null ? (float)$row['accuracy'] : null,
            'speed' => $row['speed'] !== null ? (float)$row['speed'] : null,
            'heading' => $row['heading'] !== null ? (float)$row['heading'] : null,
            'battery' => $row['battery_level'] !== null ? (int)$row['battery_level'] : null,
        ];

        $firstTs = $firstTs === null ? $ts : min($firstTs, $ts);
        $lastTs = $lastTs === null ? $ts : max($lastTs, $ts);
    }

    if ($firstTs === null || $lastTs === null) {
        return $empty;
    }

    $tracks = [];
    $publicLabels = [];
    $trackIndex = 0;
    foreach ($tracksByUser as $userId => $track) {
        if (count($track['points']) < 1) {
            continue;
        }

        $distance = 0.0;
        for ($i = 1; $i < count($track['points']); $i++) {
            $distance += rideDistanceMeters(
                (float)$track['points'][$i - 1]['lat'],
                (float)$track['points'][$i - 1]['lng'],
                (float)$track['points'][$i]['lat'],
                (float)$track['points'][$i]['lng']
            );
        }

        $label = $public ? ('Αναβάτης ' . ($trackIndex + 1)) : $track['name'];
        if (!$public && (int)($mission['responsible_user_id'] ?? 0) === (int)$userId) {
            $label .= ' · Υπεύθυνος';
        }
        $publicLabels[$userId] = 'Αναβάτης ' . ($trackIndex + 1);

        $tracks[] = [
            'id' => $public ? ('rider_' . ($trackIndex + 1)) : $userId,
            'label' => $label,
            'color' => rideReplayColor($trackIndex),
            'isLead' => (int)($mission['responsible_user_id'] ?? 0) === (int)$userId,
            'distanceMeters' => $distance,
            'points' => $track['points'],
        ];
        $trackIndex++;
    }

    if (empty($tracks)) {
        return $empty;
    }

    $events = [];
    $rideEvents = getRideEvents($missionId, 100, false);
    foreach (array_reverse($rideEvents) as $event) {
        if ($dayDate !== null && substr((string)$event['created_at'], 0, 10) !== $dayDate) {
            continue;
        }
        if ($event['lat'] === null || $event['lng'] === null) {
            continue;
        }
        $ts = strtotime((string)$event['created_at']);
        if (!$ts) {
            continue;
        }

        $type = (string)$event['event_type'];
        if (str_starts_with($type, 'status_')) {
            $type = substr($type, 7);
        }
        $title = $statusOptions[$type]['label'] ?? (string)$event['title'];
        $userId = (int)($event['user_id'] ?? 0);
        $userLabel = $public
            ? ($publicLabels[$userId] ?? 'Αναβάτης')
            : (trim((string)($event['user_name'] ?? '')) ?: 'Μέλος');

        $events[] = [
            'lat' => (float)$event['lat'],
            'lng' => (float)$event['lng'],
            'title' => $title,
            'label' => $userLabel . ' · ' . $title,
            'severity' => (string)($event['severity'] ?? 'info'),
            'type' => $type,
            'ts' => $ts,
            'time' => date('H:i:s', $ts),
        ];
    }

    $referenceRoute = rideReplayPoints(
        rideRouteGeometry($mission),
        normalizeRideRoutePoints($mission['route_points'] ?? '[]')
    );
    $distanceMeters = array_sum(array_map(fn($track) => (float)$track['distanceMeters'], $tracks));
    $durationSeconds = max(0, $lastTs - $firstTs);

    return [
        'mode' => 'actual',
        'tracks' => $tracks,
        'events' => $events,
        'referenceRoute' => $referenceRoute,
        'startTime' => date('H:i:s', $firstTs),
        'endTime' => date('H:i:s', $lastTs),
        'durationSeconds' => $durationSeconds,
        'distanceMeters' => $distanceMeters,
        'totalMinutes' => (int)ceil($durationSeconds / 60),
        'hasActualData' => true,
    ];
}

function rideMissionRouteMetrics(array $mission, ?array $routePoints = null): array {
    if ($routePoints === null) {
        $routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
    }

    $metrics = rideRouteMetrics($routePoints);
    $metrics['provider'] = 'estimate';

    $provider = (string)($mission['route_provider'] ?? '');
    $distanceMeters = (int)($mission['route_distance_meters'] ?? 0);
    $durationSeconds = (int)($mission['route_duration_seconds'] ?? 0);

    if ($provider === 'google' && $distanceMeters > 0 && $durationSeconds > 0 && count($routePoints) >= 2) {
        $movingMinutes = max(1, (int)ceil($durationSeconds / 60));
        $stopMinutes = rideRouteStopMinutes($routePoints);

        $metrics['distance_meters'] = (float)$distanceMeters;
        $metrics['distance_label'] = rideFormatDistance((float)$distanceMeters);
        $metrics['moving_minutes'] = $movingMinutes;
        $metrics['moving_label'] = rideFormatDurationMinutes($movingMinutes);
        $metrics['stop_minutes'] = $stopMinutes;
        $metrics['stop_label'] = rideFormatDurationMinutes($stopMinutes);
        $metrics['total_minutes'] = $movingMinutes + $stopMinutes;
        $metrics['total_label'] = rideFormatDurationMinutes($movingMinutes + $stopMinutes);
        $metrics['estimated'] = false;
        $metrics['provider'] = 'google';
    }

    return $metrics;
}

function resolveActiveMissionDayRoute(array $mission): array {
    $missionDays = dbFetchAll(
        "SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number",
        [$mission['id']]
    );

    if (empty($missionDays)) {
        return $mission + ['is_multiday' => false, 'active_day_label' => null];
    }

    $today = date('Y-m-d');
    $activeDay = null;
    foreach ($missionDays as $day) {
        if ($day['day_date'] === $today) {
            $activeDay = $day;
            break;
        }
    }
    if ($activeDay === null) {
        $activeDay = $today < $missionDays[0]['day_date']
            ? $missionDays[0]
            : $missionDays[count($missionDays) - 1];
    }

    $label = ($activeDay['title'] ?: 'Μέρα ' . (int)$activeDay['day_number'])
        . ' · ' . date('d/m', strtotime($activeDay['day_date']));

    return array_merge($mission, [
        'route_points'           => $activeDay['route_points'],
        'route_geometry'         => $activeDay['route_geometry'],
        'route_distance_meters'  => $activeDay['route_distance_meters'],
        'route_duration_seconds' => $activeDay['route_duration_seconds'],
        'route_provider'         => $activeDay['route_provider'],
        'is_multiday'            => true,
        'active_day_label'       => $label,
    ]);
}

function rideEventTableExists(): bool {
    try {
        return (bool) dbFetchValue(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ride_events'"
        );
    } catch (Exception $e) {
        return false;
    }
}

function recordRideEvent(array $event): ?int {
    if (!rideEventTableExists()) {
        return null;
    }

    $metadata = $event['metadata'] ?? null;
    if (is_array($metadata)) {
        $metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
    }

    $fingerprint = $event['fingerprint'] ?? null;
    try {
        dbInsert(
            "INSERT INTO ride_events (
                mission_id, shift_id, user_id, participation_id, event_type, severity,
                title, message, lat, lng, metadata, fingerprint, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                message = VALUES(message),
                lat = VALUES(lat),
                lng = VALUES(lng),
                metadata = VALUES(metadata),
                resolved_at = NULL,
                resolved_by = NULL",
            [
                (int)$event['mission_id'],
                isset($event['shift_id']) ? (int)$event['shift_id'] : null,
                isset($event['user_id']) ? (int)$event['user_id'] : null,
                isset($event['participation_id']) ? (int)$event['participation_id'] : null,
                (string)$event['event_type'],
                (string)($event['severity'] ?? 'info'),
                mb_substr((string)$event['title'], 0, 160),
                $event['message'] ?? null,
                $event['lat'] ?? null,
                $event['lng'] ?? null,
                $metadata,
                $fingerprint,
            ]
        );
        return (int)db()->lastInsertId();
    } catch (Exception $e) {
        error_log('[ride event] Failed: ' . $e->getMessage());
        return null;
    }
}

function getRideEvents(int $missionId, int $limit = 30, bool $activeOnly = false): array {
    if (!rideEventTableExists()) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $where = 'mission_id = ?';
    if ($activeOnly) {
        $where .= ' AND resolved_at IS NULL';
    }

    return dbFetchAll(
        "SELECT re.*, u.name as user_name, rb.name as resolved_by_name
         FROM ride_events re
         LEFT JOIN users u ON u.id = re.user_id
         LEFT JOIN users rb ON rb.id = re.resolved_by
         WHERE {$where}
         ORDER BY re.created_at DESC, re.id DESC
         LIMIT {$limit}",
        [$missionId]
    );
}

function getRideEvent(int $eventId): ?array {
    if (!rideEventTableExists()) {
        return null;
    }

    return dbFetchOne(
        "SELECT * FROM ride_events WHERE id = ?",
        [$eventId]
    );
}

function resolveRideEvent(int $eventId, int $userId): bool {
    if (!rideEventTableExists()) {
        return false;
    }

    return dbExecute(
        "UPDATE ride_events
         SET resolved_at = COALESCE(resolved_at, NOW()),
             resolved_by = COALESCE(resolved_by, ?)
         WHERE id = ?",
        [$userId, $eventId]
    ) > 0;
}

function getResolvedRideEventFingerprints(int $missionId): array {
    if (!rideEventTableExists()) {
        return [];
    }

    $rows = dbFetchAll(
        "SELECT fingerprint
         FROM ride_events
         WHERE mission_id = ?
           AND resolved_at IS NOT NULL
           AND fingerprint IS NOT NULL
           AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)",
        [$missionId]
    );

    return array_fill_keys(array_column($rows, 'fingerprint'), true);
}

function ridePartnerTypeMeta(): array {
    return [
        'workshop' => ['label' => 'Συνεργείο', 'icon' => 'bi-tools', 'color' => '#0d6efd'],
        'parts' => ['label' => 'Ανταλλακτικά', 'icon' => 'bi-gear', 'color' => '#198754'],
        'tires' => ['label' => 'Ελαστικά', 'icon' => 'bi-circle', 'color' => '#ffc107'],
        'roadside' => ['label' => 'Οδική Βοήθεια', 'icon' => 'bi-truck', 'color' => '#dc3545'],
    ];
}

function getRideNearbyPartners(float $lat, float $lng, int $limit = 5): array {
    try {
        $rows = dbFetchAll(
            "SELECT id, type, name, description, address, area, phone, website, maps_link,
                    latitude, longitude, subscription_status
             FROM partner_locations
             WHERE is_active = 1
               AND latitude IS NOT NULL
               AND longitude IS NOT NULL"
        );
    } catch (Exception $e) {
        return [];
    }

    $typeMeta = ridePartnerTypeMeta();
    $partners = [];
    foreach ($rows as $row) {
        $distance = rideDistanceMeters($lat, $lng, (float)$row['latitude'], (float)$row['longitude']);
        $mapsUrl = $row['maps_link'] ?: 'https://www.google.com/maps?q=' . urlencode($row['latitude'] . ',' . $row['longitude']);
        $navUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($row['latitude'] . ',' . $row['longitude']) . '&travelmode=driving';
        $meta = $typeMeta[$row['type']] ?? ['label' => $row['type'], 'icon' => 'bi-geo-alt', 'color' => '#6c757d'];

        $partners[] = [
            'id' => (int)$row['id'],
            'type' => $row['type'],
            'type_label' => $meta['label'],
            'type_icon' => $meta['icon'],
            'type_color' => $meta['color'],
            'name' => $row['name'],
            'description' => $row['description'],
            'area' => $row['area'],
            'address' => $row['address'],
            'phone' => $row['phone'],
            'website' => $row['website'],
            'maps_url' => $mapsUrl,
            'nav_url' => $navUrl,
            'lat' => (float)$row['latitude'],
            'lng' => (float)$row['longitude'],
            'subscription_status' => $row['subscription_status'],
            'is_subscriber' => $row['subscription_status'] === 'active',
            'distance_meters' => round($distance),
            'distance_label' => rideFormatDistance($distance),
        ];
    }

    usort($partners, function ($a, $b) {
        if ($a['is_subscriber'] !== $b['is_subscriber']) {
            return $a['is_subscriber'] ? -1 : 1;
        }
        return $a['distance_meters'] <=> $b['distance_meters'];
    });

    return array_slice($partners, 0, max(1, min(20, $limit)));
}

function buildRideDebriefSuggestion(int $missionId): array {
    $events = getRideEvents($missionId, 100, false);
    $eventCounts = [];
    $resolvedCount = 0;
    foreach ($events as $event) {
        $type = $event['event_type'];
        if (str_starts_with($type, 'status_')) {
            $type = substr($type, 7);
        }
        $eventCounts[$type] = ($eventCounts[$type] ?? 0) + 1;
        if (!empty($event['resolved_at'])) {
            $resolvedCount++;
        }
    }

    $shiftIds = getRideShiftIds($missionId);
    $pingStats = [
        'riders' => 0,
        'first_ping' => null,
        'last_ping' => null,
        'total_pings' => 0,
    ];
    if (!empty($shiftIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
            $row = dbFetchOne(
                "SELECT COUNT(*) as total_pings,
                        COUNT(DISTINCT user_id) as riders,
                        MIN(created_at) as first_ping,
                        MAX(created_at) as last_ping
                 FROM member_pings
                 WHERE shift_id IN ($placeholders)",
                $shiftIds
            );
            if ($row) {
                $pingStats = [
                    'riders' => (int)$row['riders'],
                    'first_ping' => $row['first_ping'],
                    'last_ping' => $row['last_ping'],
                    'total_pings' => (int)$row['total_pings'],
                ];
            }
        } catch (Exception $e) {
            // Keep debrief usable even if GPS table is not available.
        }
    }

    $typeLabels = [
        'needs_help' => 'SOS',
        'breakdown' => 'βλάβη',
        'left_behind' => 'μέλος που έμεινε πίσω',
        'off_route' => 'εκτός διαδρομής',
        'stationary' => 'ακινητοποίηση',
        'stale' => 'απώλεια στίγματος',
        'on_way' => 'έναρξη/κίνηση',
        'on_site' => 'στάση',
        'ok' => 'δήλωση ΟΚ',
    ];

    $countParts = [];
    foreach ($eventCounts as $type => $count) {
        $countParts[] = $count . ' ' . ($typeLabels[$type] ?? $type);
    }

    $summaryLines = [];
    if ($pingStats['riders'] > 0) {
        $summaryLines[] = 'Το Ride Mode χρησιμοποιήθηκε από ' . $pingStats['riders'] . ' μέλη με ' . $pingStats['total_pings'] . ' στίγματα GPS'
            . ($pingStats['first_ping'] && $pingStats['last_ping']
                ? ' από ' . date('H:i', strtotime($pingStats['first_ping'])) . ' έως ' . date('H:i', strtotime($pingStats['last_ping'])) . '.'
                : '.');
    } else {
        $summaryLines[] = 'Δεν καταγράφηκαν στίγματα GPS από Ride Mode.';
    }

    if (!empty($countParts)) {
        $summaryLines[] = 'Καταγράφηκαν ' . count($events) . ' συμβάντα διαδρομής: ' . implode(', ', $countParts) . '.';
    } else {
        $summaryLines[] = 'Δεν καταγράφηκαν συμβάντα διαδρομής.';
    }

    if (count($events) > 0) {
        $summaryLines[] = $resolvedCount . ' από τα ' . count($events) . ' συμβάντα έχουν σημειωθεί ως επιλυμένα.';
    }

    $incidentLines = [];
    foreach (array_reverse($events) as $event) {
        $type = $event['event_type'];
        if (str_starts_with($type, 'status_')) {
            $type = substr($type, 7);
        }
        if (!in_array($type, ['needs_help', 'breakdown', 'left_behind', 'off_route', 'stationary', 'stale'], true)) {
            continue;
        }
        $line = date('H:i', strtotime($event['created_at'])) . ' - ' . ($event['message'] ?: $event['title']);
        if (!empty($event['resolved_at'])) {
            $line .= ' (επιλύθηκε ' . date('H:i', strtotime($event['resolved_at'])) . ')';
        }
        $incidentLines[] = $line;
    }

    return [
        'summary' => implode("\n", $summaryLines),
        'incidents' => empty($incidentLines) ? '' : implode("\n", $incidentLines),
        'event_count' => count($events),
        'resolved_count' => $resolvedCount,
        'ping_stats' => $pingStats,
    ];
}

function getRideReadinessSummary(int $missionId): array {
    $shiftIds = getRideShiftIds($missionId);
    $summary = [
        'approved' => 0,
        'tracking' => 0,
        'navigating' => 0,
        'stale' => 0,
        'not_started' => 0,
        'ready_percent' => 0,
    ];
    if (empty($shiftIds)) {
        return $summary;
    }

    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
    $participants = dbFetchAll(
        "SELECT DISTINCT pr.member_id
         FROM participation_requests pr
         WHERE pr.shift_id IN ($placeholders)
           AND pr.status = ?",
        array_merge($shiftIds, [PARTICIPATION_APPROVED])
    );
    $summary['approved'] = count($participants);
    if ($summary['approved'] === 0) {
        return $summary;
    }

    try {
        $pingRows = dbFetchAll(
            "SELECT vp.user_id, MAX(vp.created_at) as last_ping
             FROM member_pings vp
             WHERE vp.shift_id IN ($placeholders)
             GROUP BY vp.user_id",
            $shiftIds
        );
        $lastByUser = [];
        foreach ($pingRows as $row) {
            $lastByUser[(int)$row['user_id']] = $row['last_ping'];
        }

        $navRows = dbFetchAll(
            "SELECT member_id, MAX(navigating_since) as navigating_since
             FROM participation_requests
             WHERE shift_id IN ($placeholders)
               AND status = ?
             GROUP BY member_id",
            array_merge($shiftIds, [PARTICIPATION_APPROVED])
        );
        $navigatingByUser = [];
        foreach ($navRows as $row) {
            if (!empty($row['navigating_since'])) {
                $navigatingByUser[(int)$row['member_id']] = $row['navigating_since'];
            }
        }

        foreach ($participants as $participant) {
            $uid = (int)$participant['member_id'];
            if (empty($lastByUser[$uid])) {
                $summary['not_started']++;
                continue;
            }
            $lastPingTs = strtotime($lastByUser[$uid]);
            $age = time() - $lastPingTs;
            $navSince = $navigatingByUser[$uid] ?? null;
            $navSinceTs = $navSince ? strtotime($navSince) : null;
            $isNavigating = $navSinceTs !== null
                && $navSinceTs > $lastPingTs
                && (time() - $navSinceTs) <= 120;
            if ($isNavigating) {
                $summary['navigating']++;
            } elseif ($age > 120) {
                $summary['stale']++;
            } else {
                $summary['tracking']++;
            }
        }
    } catch (Exception $e) {
        $summary['not_started'] = $summary['approved'];
    }

    $summary['ready_percent'] = $summary['approved'] > 0
        ? (int)round(($summary['tracking'] / $summary['approved']) * 100)
        : 0;

    return $summary;
}

function rideChecklistDefinitions(): array {
    return [
        'ride_link_sent' => 'Στάλθηκε Ride Link στους συμμετέχοντες',
        'gps_ready' => 'Οι βασικοί συμμετέχοντες στέλνουν GPS',
        'route_confirmed' => 'Επιβεβαιώθηκε η διαδρομή και τα σημεία στάσης',
        'navigation_ready' => 'Ο road captain έχει ανοίξει πλοήγηση',
        'screen_notice' => 'Έγινε ενημέρωση ότι η οθόνη πρέπει να μένει ανοιχτή',
        'partners_checked' => 'Ελέγχθηκαν κοντινοί συνεργάτες/οδική βοήθεια',
        'sweep_rider' => 'Ορίστηκε τελευταίος/sweep rider',
    ];
}

function rideChecklistTableExists(): bool {
    try {
        return (bool) dbFetchValue(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ride_checklist_items'"
        );
    } catch (Exception $e) {
        return false;
    }
}

function getRideChecklist(int $missionId): array {
    $definitions = rideChecklistDefinitions();
    $items = [];
    foreach ($definitions as $key => $label) {
        $items[$key] = [
            'key' => $key,
            'label' => $label,
            'completed' => false,
            'completed_at' => null,
            'completed_by_name' => null,
        ];
    }
    if (!rideChecklistTableExists()) {
        return array_values($items);
    }

    $rows = dbFetchAll(
        "SELECT rci.*, u.name as completed_by_name
         FROM ride_checklist_items rci
         LEFT JOIN users u ON u.id = rci.completed_by
         WHERE rci.mission_id = ?",
        [$missionId]
    );
    foreach ($rows as $row) {
        $key = $row['item_key'];
        if (!isset($items[$key])) {
            continue;
        }
        $items[$key]['completed'] = !empty($row['completed_at']);
        $items[$key]['completed_at'] = $row['completed_at'];
        $items[$key]['completed_by_name'] = $row['completed_by_name'];
    }

    return array_values($items);
}

function setRideChecklistItem(int $missionId, string $itemKey, bool $completed, int $userId): bool {
    if (!rideChecklistTableExists() || !isset(rideChecklistDefinitions()[$itemKey])) {
        return false;
    }

    dbExecute(
        "INSERT INTO ride_checklist_items (mission_id, item_key, completed_at, completed_by)
         VALUES (?, ?, " . ($completed ? 'NOW()' : 'NULL') . ", ?)
         ON DUPLICATE KEY UPDATE
            completed_at = VALUES(completed_at),
            completed_by = VALUES(completed_by)",
        [$missionId, $itemKey, $completed ? $userId : null]
    );

    return true;
}

function getRideChecklistSummary(int $missionId): array {
    $items = getRideChecklist($missionId);
    $completed = count(array_filter($items, fn($item) => !empty($item['completed'])));
    return [
        'items' => $items,
        'completed' => $completed,
        'total' => count($items),
        'percent' => count($items) > 0 ? (int)round(($completed / count($items)) * 100) : 0,
    ];
}

function buildRideClosureSummary(int $missionId): array {
    $readiness = getRideReadinessSummary($missionId);
    $checklist = getRideChecklistSummary($missionId);
    $suggestion = buildRideDebriefSuggestion($missionId);
    $events = getRideEvents($missionId, 100, false);

    $activeEvents = 0;
    $dangerEvents = 0;
    $warningEvents = 0;
    foreach ($events as $event) {
        if (empty($event['resolved_at'])) {
            $activeEvents++;
        }
        if (($event['severity'] ?? '') === 'danger') {
            $dangerEvents++;
        } elseif (($event['severity'] ?? '') === 'warning') {
            $warningEvents++;
        }
    }

    $summaryLines = [
        'Συμμετέχοντες εγκεκριμένοι: ' . (int)$readiness['approved'] . '.',
        'Ride Mode/GPS: ' . (int)$readiness['tracking'] . ' ενεργοί, ' . (int)$readiness['stale'] . ' stale, ' . (int)$readiness['not_started'] . ' χωρίς εκκίνηση.',
        'Συμβάντα Ride Mode: ' . count($events) . ' συνολικά, ' . (int)$suggestion['resolved_count'] . ' επιλυμένα, ' . $activeEvents . ' ενεργά.',
        'Checklist εκκίνησης: ' . (int)$checklist['completed'] . '/' . (int)$checklist['total'] . ' (' . (int)$checklist['percent'] . '%).',
    ];

    if (!empty($suggestion['summary'])) {
        $summaryLines[] = '';
        $summaryLines[] = $suggestion['summary'];
    }

    $incidents = trim((string)($suggestion['incidents'] ?? ''));
    if ($incidents === '' && $dangerEvents === 0 && $warningEvents === 0) {
        $incidents = 'Δεν καταγράφηκαν κρίσιμα συμβάντα από το Ride Mode.';
    }

    return [
        'readiness' => $readiness,
        'checklist' => $checklist,
        'event_count' => count($events),
        'resolved_count' => (int)$suggestion['resolved_count'],
        'active_event_count' => $activeEvents,
        'danger_event_count' => $dangerEvents,
        'warning_event_count' => $warningEvents,
        'summary' => trim(implode("\n", $summaryLines)),
        'incidents' => $incidents,
    ];
}
