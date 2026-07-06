<?php
/**
 * EasyRide - Live Επιχειρησιακό
 * Live operations view: active missions, shift staff counters, map, alerts.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ride-functions.php';
requirePermission('ops_dashboard');

$pageTitle = 'Live Επιχειρησιακό';
$currentUser = getCurrentUser();

// ── Handle quick attendance POST, broadcast & mission chat ───────────────────
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'mark_attended') {
        $prId = (int) post('pr_id');
        $shiftId = (int) post('shift_id');
        $pr = dbFetchOne(
            "SELECT pr.*, s.start_time, s.end_time FROM participation_requests pr
             JOIN shifts s ON pr.shift_id = s.id
             WHERE pr.id = ? AND pr.status = ?",
            [$prId, PARTICIPATION_APPROVED]
        );
        if ($pr) {
            $actualHours = round(
                (strtotime($pr['end_time']) - strtotime($pr['start_time'])) / 3600, 2
            );
            dbExecute(
                "UPDATE participation_requests SET attended = 1, actual_hours = ?, updated_at = NOW() WHERE id = ?",
                [$actualHours, $prId]
            );
            logAudit('mark_attended', 'participation_requests', $prId);
        }

    } elseif ($action === 'broadcast') {
        $missionId    = (int) post('mission_id');
        $broadcastMsg = trim(post('broadcast_message', ''));
        if ($missionId && $broadcastMsg) {
            $mission = dbFetchOne("SELECT title FROM missions WHERE id = ?", [$missionId]);
            if ($mission) {
                $vols = dbFetchAll(
                    "SELECT DISTINCT pr.volunteer_id FROM participation_requests pr
                     JOIN shifts s ON pr.shift_id = s.id
                     WHERE s.mission_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "'",
                    [$missionId]
                );
                
                $userIds = array_column($vols, 'volunteer_id');
                if (!empty($userIds)) {
                    sendBulkNotifications($userIds, '📢 ' . h($mission['title']), $broadcastMsg, 'info');
                }
                
                logAudit('broadcast', 'missions', $missionId);
                setFlash('success', 'Η ανακοίνωση εστάλη σε ' . count($vols) . ' μέλη.');
            }
        }
    } elseif ($action === 'send_ops_chat_message') {
        $missionId = (int) post('mission_id');
        $message = trim(post('message', ''));
        $canWriteChat = false;

        if ($missionId && $message !== '') {
            $isResponsible = (bool) dbFetchValue(
                "SELECT COUNT(*) FROM missions WHERE id = ? AND responsible_user_id = ? AND deleted_at IS NULL",
                [$missionId, $currentUser['id']]
            );
            $isParticipant = (bool) dbFetchValue(
                "SELECT COUNT(*) FROM participation_requests pr
                 JOIN shifts s ON s.id = pr.shift_id
                 WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
                [$missionId, $currentUser['id'], PARTICIPATION_APPROVED]
            );
            $canWriteChat = isSystemAdmin() || $isResponsible || $isParticipant;
        }

        if ($canWriteChat) {
            dbInsert(
                "INSERT INTO mission_chat_messages (mission_id, user_id, message) VALUES (?, ?, ?)",
                [$missionId, $currentUser['id'], $message]
            );
            logAudit('send_ops_chat_message', 'missions', $missionId);
            setFlash('success', 'Το μήνυμα στάλθηκε.');
        } else {
            setFlash('error', 'Το chat είναι διαθέσιμο μόνο στους συμμετέχοντες της δράσης.');
        }

        redirect('ops-dashboard.php#mission-chat-' . $missionId);
    } elseif ($action === 'dismiss_help') {
        $prId = (int) post('pr_id');
        if ($prId) {
            dbExecute(
                "UPDATE participation_requests SET field_status = NULL, field_status_updated_at = NOW() WHERE id = ?",
                [$prId]
            );
            logAudit('dismiss_help', 'participation_requests', $prId);
            setFlash('success', 'Η ειδοποίηση βοήθειας απορρίφθηκε.');
        }
    }

    redirect('ops-dashboard.php');
}

// ── Core data query ───────────────────────────────────────────────────────────
$missionRows = dbFetchAll(
    "SELECT m.id as mission_id, m.title, m.is_urgent, m.status as mission_status,
            m.start_datetime, m.end_datetime, m.location, m.location_details, m.route_points,
            m.route_geometry, m.route_distance_meters, m.route_duration_seconds, m.route_provider,
            m.latitude, m.longitude, m.type as mission_type,
            d.name as department_name,
            s.id as shift_id, s.start_time, s.end_time,
            s.max_volunteers, s.min_volunteers, s.notes as shift_notes,
            COALESCE(SUM(pr.status = '" . PARTICIPATION_APPROVED . "'), 0)     as approved,
            COALESCE(SUM(pr.status = '" . PARTICIPATION_PENDING . "'), 0)      as pending,
            COALESCE(SUM(pr.status = '" . PARTICIPATION_APPROVED . "' AND pr.attended = 1), 0) as attended
     FROM missions m
     JOIN shifts s ON s.mission_id = m.id
     LEFT JOIN departments d ON m.department_id = d.id
     LEFT JOIN participation_requests pr ON pr.shift_id = s.id
     WHERE m.status IN ('" . STATUS_OPEN . "', '" . STATUS_COMPLETED . "') 
       AND m.deleted_at IS NULL
       AND (m.status = '" . STATUS_OPEN . "' OR (m.status = '" . STATUS_COMPLETED . "' AND m.end_datetime >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)))
     GROUP BY s.id
     ORDER BY m.is_urgent DESC, m.start_datetime ASC, s.start_time ASC",
    []
);

// ── Group rows by mission ─────────────────────────────────────────────────────
$missions = [];
foreach ($missionRows as $row) {
    $mid = $row['mission_id'];
    if (!isset($missions[$mid])) {
        $missions[$mid] = [
            'id'              => $mid,
            'title'           => $row['title'],
            'is_urgent'       => $row['is_urgent'],
            'start_datetime'  => $row['start_datetime'],
            'end_datetime'    => $row['end_datetime'],
            'location'        => $row['location'],
            'location_details'=> $row['location_details'],
            'route_points'    => $row['route_points'],
            'route_geometry'  => $row['route_geometry'],
            'route_distance_meters' => $row['route_distance_meters'],
            'route_duration_seconds' => $row['route_duration_seconds'],
            'route_provider'  => $row['route_provider'],
            'latitude'        => $row['latitude'],
            'longitude'       => $row['longitude'],
            'mission_type'    => $row['mission_type'],
            'department_name' => $row['department_name'],
            'shifts'          => [],
        ];
    }
    $missions[$mid]['shifts'][] = [
        'shift_id'    => $row['shift_id'],
        'start_time'  => $row['start_time'],
        'end_time'    => $row['end_time'],
        'max_volunteers' => (int)$row['max_volunteers'],
        'min_volunteers' => (int)$row['min_volunteers'],
        'shift_notes' => $row['shift_notes'],
        'approved'    => (int)$row['approved'],
        'pending'     => (int)$row['pending'],
        'attended'    => (int)$row['attended'],
    ];
}

// ── Pre-compute shiftIds, approvedVolunteers (needed by ajax + alerts) ─────────
$shiftIds = !empty($missions)
    ? array_merge(...array_map(fn($m) => array_column($m['shifts'], 'shift_id'), array_values($missions)))
    : [];

// ── Split missions into "in progress" vs "upcoming" ──────────────────────────
$now = time();
$activeMissions  = [];
$upcomingMissions = [];
foreach ($missions as $mid => $m) {
    $hasActiveShift = false;
    foreach ($m['shifts'] as $s) {
        $sStart = strtotime($s['start_time']);
        $sEnd   = strtotime($s['end_time']);
        if ($sStart <= $now && $sEnd > $now) {
            $hasActiveShift = true;
            break;
        }
    }
    // Also consider mission-level: if any shift hasn't ended yet but at least one started
    if (!$hasActiveShift) {
        $mStart = strtotime($m['start_datetime']);
        $mEnd   = strtotime($m['end_datetime']);
        if ($mStart <= $now && $mEnd > $now) {
            $hasActiveShift = true;
        }
    }
    if ($hasActiveShift) {
        $activeMissions[$mid] = $m;
    } else {
        $upcomingMissions[$mid] = $m;
    }
}

// Detect if new columns / tables exist on this DB server
$hasFieldStatus = !empty($shiftIds) && (bool) dbFetchValue(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participation_requests' AND COLUMN_NAME = 'field_status'"
);
$hasPingsTable = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'volunteer_pings'"
);

$rideStatusLabels = [
    'on_way' => 'Σε Κίνηση',
    'on_site' => 'Επί Τόπου',
    'needs_help' => 'SOS / Χρειάζεται Βοήθεια',
    'breakdown' => 'Βλάβη',
    'left_behind' => 'Έμεινε πίσω',
    'ok' => 'Είναι ΟΚ',
];
$rideCriticalStatuses = ['needs_help', 'breakdown', 'left_behind'];

$approvedVolunteers = [];
if (!empty($shiftIds)) {
    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
    $fsSelect = $hasFieldStatus
        ? ', pr.field_status, pr.field_status_updated_at'
        : ', NULL as field_status, NULL as field_status_updated_at';
    $rows = dbFetchAll(
        "SELECT pr.id as pr_id, pr.shift_id, pr.attended{$fsSelect},
                u.id as user_id, u.name, u.phone
         FROM participation_requests pr
         JOIN users u ON pr.volunteer_id = u.id
         WHERE pr.shift_id IN ($placeholders) AND pr.status = '" . PARTICIPATION_APPROVED . "'
         ORDER BY u.name",
        $shiftIds
    );
    foreach ($rows as $r) {
        $approvedVolunteers[$r['shift_id']][] = $r;
    }
}

$missionIds = array_map('intval', array_keys($missions));
$chatAccessByMission = [];
$chatMessagesByMission = [];
foreach ($missionIds as $missionId) {
    $chatAccessByMission[$missionId] = isSystemAdmin();
    $chatMessagesByMission[$missionId] = [];
}

if (!empty($missionIds) && !isSystemAdmin()) {
    $missionPlaceholders = implode(',', array_fill(0, count($missionIds), '?'));
    $participantRows = dbFetchAll(
        "SELECT DISTINCT s.mission_id
         FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id IN ($missionPlaceholders)
           AND pr.volunteer_id = ?
           AND pr.status = ?",
        array_merge($missionIds, [$currentUser['id'], PARTICIPATION_APPROVED])
    );
    foreach ($participantRows as $row) {
        $chatAccessByMission[(int)$row['mission_id']] = true;
    }

    $responsibleRows = dbFetchAll(
        "SELECT id FROM missions
         WHERE id IN ($missionPlaceholders)
           AND responsible_user_id = ?
           AND deleted_at IS NULL",
        array_merge($missionIds, [$currentUser['id']])
    );
    foreach ($responsibleRows as $row) {
        $chatAccessByMission[(int)$row['id']] = true;
    }
}

$chatMissionIds = array_keys(array_filter($chatAccessByMission));
if (!empty($chatMissionIds)) {
    $chatPlaceholders = implode(',', array_fill(0, count($chatMissionIds), '?'));
    $chatRows = dbFetchAll(
        "SELECT m.*, u.name as user_name
         FROM mission_chat_messages m
         JOIN users u ON u.id = m.user_id
         WHERE m.mission_id IN ($chatPlaceholders)
         ORDER BY m.created_at ASC, m.id ASC",
        $chatMissionIds
    );
    foreach ($chatRows as $row) {
        $chatMessagesByMission[(int)$row['mission_id']][] = $row;
    }
}

// ── Ajax endpoint: return JSON for live refresh ───────────────────────────────
if (get('ajax') === '1') {
    header('Content-Type: application/json');
    $payload = [];
    foreach ($missions as $m) {
        foreach ($m['shifts'] as $s) {
            $payload['shift_' . $s['shift_id']] = [
                'approved' => $s['approved'],
                'pending'  => $s['pending'],
                'attended' => $s['attended'],
                'max'      => $s['max_volunteers'],
                'min'      => $s['min_volunteers'],
            ];
        }
    }
    // Live GPS pins & field statuses (only if tables/columns exist)
    $livePins   = [];
    $liveStatus = [];
    if ($hasPingsTable && !empty($shiftIds)) {
        try {
            $ph = implode(',', array_fill(0, count($shiftIds), '?'));
            $fsCol = $hasFieldStatus ? ', pr.field_status' : ', NULL as field_status';
            $pingRowsAjax = dbFetchAll(
                "SELECT vp.user_id, vp.shift_id, vp.lat, vp.lng, vp.created_at, u.name{$fsCol}
                 FROM volunteer_pings vp
                 JOIN users u ON vp.user_id = u.id
                 LEFT JOIN participation_requests pr ON pr.volunteer_id = vp.user_id AND pr.shift_id = vp.shift_id
                 WHERE vp.shift_id IN ($ph)
                   AND vp.created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                   AND vp.id = (SELECT MAX(vp2.id) FROM volunteer_pings vp2
                                WHERE vp2.user_id = vp.user_id AND vp2.shift_id = vp.shift_id)",
                $shiftIds
            );
            foreach ($pingRowsAjax as $p) {
                $livePins[] = [
                    'lat'          => (float)$p['lat'],
                    'lng'          => (float)$p['lng'],
                    'name'         => $p['name'],
                    'field_status' => $p['field_status'],
                    'ts'           => date('H:i', strtotime($p['created_at'])),
                ];
            }
        } catch (Exception $e) { /* table not yet migrated */ }
    }
    if ($hasFieldStatus && !empty($shiftIds)) {
        try {
            $ph = implode(',', array_fill(0, count($shiftIds), '?'));
            $fsRows = dbFetchAll(
                "SELECT pr.id as pr_id, pr.field_status
                 FROM participation_requests pr
                 WHERE pr.shift_id IN ($ph) AND pr.status = '" . PARTICIPATION_APPROVED . "'",
                $shiftIds
            );
            foreach ($fsRows as $fs) {
                $liveStatus['pr_' . $fs['pr_id']] = $fs['field_status'];
            }
        } catch (Exception $e) { /* column not yet migrated */ }
    }
    // Ride Mode alerts for real-time banner update
    $alertsAjax = [];
    foreach ($approvedVolunteers as $shiftId => $vols) {
        foreach ($vols as $v) {
            $fieldStatus = $v['field_status'] ?? '';
            if (in_array($fieldStatus, $rideCriticalStatuses, true)) {
                $mTitle = '';
                foreach ($missions as $m) {
                    foreach ($m['shifts'] as $s) {
                        if ($s['shift_id'] == $shiftId) { $mTitle = $m['title']; break 2; }
                    }
                }
                $alertsAjax[] = [
                    'type'          => $fieldStatus,
                    'status_label'  => $rideStatusLabels[$fieldStatus] ?? 'Ειδοποίηση',
                    'name'          => $v['name'],
                    'shift_id'      => $shiftId,
                    'pr_id'         => $v['pr_id'],
                    'mission_title' => $mTitle,
                ];
            }
        }
    }
    foreach ($missions as $m) {
        foreach ($m['shifts'] as $s) {
            $secsToStart = strtotime($s['start_time']) - time();
            $isUnderstaffed = $s['approved'] < $s['min_volunteers'];
            $startingSoon   = $secsToStart > 0 && $secsToStart < 7200;
            $alreadyActive  = $secsToStart <= 0 && strtotime($s['end_time']) > time();
            if ($isUnderstaffed && ($startingSoon || $alreadyActive)) {
                $alertsAjax[] = [
                    'type'          => 'understaffed',
                    'mission_title' => $m['title'],
                    'shift_id'      => $s['shift_id'],
                    'start_time'    => $s['start_time'],
                    'approved'      => $s['approved'],
                    'min'           => $s['min_volunteers'],
                ];
            }
        }
    }
    echo json_encode(['ts' => date('H:i:s'), 'shifts' => $payload, 'pins' => $livePins, 'field_status' => $liveStatus, 'alerts' => $alertsAjax]);
    exit;
}

// ── Compute alerts (understaffed shifts + volunteers needing help) ────────────
$alerts = [];
$now = time();
foreach ($missions as $m) {
    foreach ($m['shifts'] as $s) {
        $secsToStart = strtotime($s['start_time']) - $now;
        $isUnderstaffed = $s['approved'] < $s['min_volunteers'];
        $startingSoon   = $secsToStart > 0 && $secsToStart < 7200; // < 2 hours
        $alreadyActive  = $secsToStart <= 0 && strtotime($s['end_time']) > $now;
        if ($isUnderstaffed && ($startingSoon || $alreadyActive)) {
            $alerts[] = [
                'type'          => 'understaffed',
                'mission_title' => $m['title'],
                'shift_id'      => $s['shift_id'],
                'start_time'    => $s['start_time'],
                'approved'      => $s['approved'],
                'min'           => $s['min_volunteers'],
            ];
        }
    }
}
// Ride Mode alerts from field_status
foreach ($approvedVolunteers as $shiftId => $vols) {
    foreach ($vols as $v) {
        $fieldStatus = $v['field_status'] ?? '';
        if (in_array($fieldStatus, $rideCriticalStatuses, true)) {
            // find mission title
            $mTitle = '';
            foreach ($missions as $m) {
                foreach ($m['shifts'] as $s) {
                    if ($s['shift_id'] == $shiftId) { $mTitle = $m['title']; break 2; }
                }
            }
            $alerts[] = [
                'type'    => $fieldStatus,
                'status_label' => $rideStatusLabels[$fieldStatus] ?? 'Ειδοποίηση',
                'name'    => $v['name'],
                'shift_id'=> $shiftId,
                'pr_id'   => $v['pr_id'],
                'mission_title' => $mTitle,
            ];
        }
    }
}

function buildOpsGoogleMapsDirectionsUrl(array $routePoints): string {
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

// ── Map pins/routes (missions with coordinates or route points) ──────────────
$mapPins = [];
$routeTimelineMissions = [];
foreach ($missions as $m) {
    $m = resolveActiveMissionDayRoute($m);
    $routePoints = json_decode($m['route_points'] ?? '[]', true);
    if (!is_array($routePoints)) {
        $routePoints = [];
    }
    $routePoints = array_values(array_filter($routePoints, fn($point) => is_array($point) && isset($point['lat'], $point['lng']) && is_numeric($point['lat']) && is_numeric($point['lng'])));
    $routePoints = array_map(fn($point) => [
        'lat' => (float)$point['lat'],
        'lng' => (float)$point['lng'],
        'title' => trim((string)($point['title'] ?? '')),
        'scheduled_time' => trim((string)($point['scheduled_time'] ?? '')),
        'stop_minutes' => max(0, (int)($point['stop_minutes'] ?? 0)),
        'notes' => trim((string)($point['notes'] ?? '')),
    ], $routePoints);

    if (!empty($routePoints)) {
        $routeTimelineMissions[] = [
            'id' => (int)$m['id'],
            'title' => $m['title'],
            'points' => $routePoints,
            'metrics' => rideMissionRouteMetrics($m, $routePoints),
            'directions_url' => buildOpsGoogleMapsDirectionsUrl($routePoints),
            'day_label' => $m['is_multiday'] ? $m['active_day_label'] : null,
        ];
    }

    if (($m['latitude'] && $m['longitude']) || !empty($routePoints)) {
        // Determine pin color based on worst shift
        $worstColor = 'green';
        foreach ($m['shifts'] as $s) {
            $pct = $s['max_volunteers'] > 0 ? $s['approved'] / $s['max_volunteers'] : 0;
            if ($s['approved'] < $s['min_volunteers']) { $worstColor = 'red'; break; }
            if ($pct < 0.5) { $worstColor = 'orange'; }
        }
        $pinLat = $m['latitude'] ?: $routePoints[0]['lat'];
        $pinLng = $m['longitude'] ?: $routePoints[0]['lng'];
        $mapPins[] = [
            'lat'   => (float)$pinLat,
            'lng'   => (float)$pinLng,
            'title' => $m['title'],
            'url'   => 'mission-view.php?id=' . $m['id'],
            'color' => $worstColor,
            'urgent'=> (bool)$m['is_urgent'],
            'route' => $routePoints,
            'geometry' => rideRouteGeometry($m),
        ];
    }
}

// ── Latest GPS pings per volunteer (last 2 hours, active shifts only) ───────
$volunteerPins = [];
if ($hasPingsTable && !empty($shiftIds)) {
    try {
        $placeholders2 = implode(',', array_fill(0, count($shiftIds), '?'));
        $fsCol2 = $hasFieldStatus ? ', pr.field_status' : ', NULL as field_status';
        $pingRows = dbFetchAll(
            "SELECT vp.user_id, vp.shift_id, vp.lat, vp.lng, vp.created_at, u.name{$fsCol2}
             FROM volunteer_pings vp
             JOIN users u ON vp.user_id = u.id
             LEFT JOIN participation_requests pr ON pr.volunteer_id = vp.user_id AND pr.shift_id = vp.shift_id
             WHERE vp.shift_id IN ($placeholders2)
               AND vp.created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND vp.id = (
                   SELECT MAX(vp2.id) FROM volunteer_pings vp2
                   WHERE vp2.user_id = vp.user_id AND vp2.shift_id = vp.shift_id
               )",
            $shiftIds
        );
        foreach ($pingRows as $pr) {
            $volunteerPins[] = [
                'lat'          => (float)$pr['lat'],
                'lng'          => (float)$pr['lng'],
                'name'         => $pr['name'],
                'field_status' => $pr['field_status'],
                'ts'           => date('H:i', strtotime($pr['created_at'])),
            ];
        }
    } catch (Exception $e) { /* table not yet migrated */ }
}

$renderMissionChat = function (array $mission) use ($chatAccessByMission, $chatMessagesByMission, $currentUser) {
    $missionId = (int) $mission['id'];
    if (empty($chatAccessByMission[$missionId])) {
        return;
    }

    $messages = $chatMessagesByMission[$missionId] ?? [];
    ?>
    <div class="ops-chat mt-3" id="mission-chat-<?= $missionId ?>">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="fw-semibold small">
                <i class="bi bi-chat-dots me-1"></i>Chat συμμετεχόντων
            </div>
            <span class="text-muted small"><?= count($messages) ?> μην.</span>
        </div>
        <div class="ops-chat-messages mb-2">
            <?php if (empty($messages)): ?>
                <div class="text-muted small text-center py-3">
                    Δεν υπάρχουν μηνύματα ακόμα.
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <?php $isOwn = (int)$msg['user_id'] === (int)$currentUser['id']; ?>
                    <div class="ops-chat-line <?= $isOwn ? 'own' : '' ?>">
                        <div class="ops-chat-bubble <?= $isOwn ? 'bg-primary text-white' : 'bg-white border' ?>">
                            <div class="fw-semibold small">
                                <?= h($msg['user_name']) ?><?= $isOwn ? ' (Εσείς)' : '' ?>
                            </div>
                            <div class="small"><?= nl2br(h($msg['message'])) ?></div>
                            <div class="<?= $isOwn ? 'text-white-50' : 'text-muted' ?> ops-chat-time">
                                <?= formatDateTime($msg['created_at'], 'd/m H:i') ?>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2 small">
                            <span class="badge bg-white text-dark border"><i class="bi bi-signpost-2 me-1"></i><?= h($routeMission['metrics']['distance_label']) ?></span>
                            <span class="badge bg-white text-dark border"><i class="bi bi-stopwatch me-1"></i>Οδήγηση <?= h($routeMission['metrics']['moving_label']) ?></span>
                            <span class="badge bg-warning text-dark"><i class="bi bi-cup-hot me-1"></i>Στάσεις <?= h($routeMission['metrics']['stop_label']) ?></span>
                            <span class="badge bg-primary"><i class="bi bi-clock-history me-1"></i>Σύνολο <?= h($routeMission['metrics']['total_label']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <form method="post" class="ops-chat-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_ops_chat_message">
            <input type="hidden" name="mission_id" value="<?= $missionId ?>">
            <div class="input-group input-group-sm">
                <textarea name="message" class="form-control" rows="1" placeholder="Μήνυμα προς συμμετέχοντες..." required maxlength="1000"></textarea>
                <button class="btn btn-primary" type="submit" title="Αποστολή">
                    <i class="bi bi-send"></i>
                </button>
            </div>
        </form>
    </div>
    <?php
};

include __DIR__ . '/includes/header.php';
?>

<!-- Auto-refresh styles -->
<style>
.ops-mission-card  { border-left: 4px solid #0d6efd; }
.ops-mission-card.urgent { border-left-color: #dc3545; }
.shift-row         { background: #f8f9fa; border-radius: 6px; padding: 10px 14px; margin-bottom: 8px; }
.shift-row.active-shift  { background: #fff3cd; }
.shift-row.ended-shift   { opacity: .6; }
.vol-chip          { display:inline-block; background:#e9ecef; border-radius:20px;
                     padding:2px 10px; font-size:.78rem; margin:2px; }
.vol-chip.attended { background:#d1e7dd; }
#mapPanel          { height: 520px; border-radius: 8px; overflow: hidden; }
.refresh-badge     { font-size:.75rem; }
.countdown         { font-size:.8rem; font-weight:600; }
#alertBanner .alert { margin-bottom: 6px; }
.ops-chat          { border:1px solid #dee2e6; border-radius:8px; background:#f8f9fa; padding:10px; }
.ops-chat-messages { max-height:220px; overflow-y:auto; padding:8px; background:#fff; border:1px solid #e9ecef; border-radius:6px; }
.ops-chat-line     { display:flex; margin-bottom:8px; }
.ops-chat-line.own { justify-content:flex-end; }
.ops-chat-bubble   { max-width:82%; padding:7px 10px; border-radius:8px; word-break:break-word; box-shadow:0 1px 2px rgba(0,0,0,.05); }
.ops-chat-time     { font-size:.7rem; margin-top:3px; }
.ops-chat-form textarea { resize:none; min-height:34px; }
.ops-route-timeline .list-group-item { padding:10px 12px; }
.ops-route-timeline .route-mission-title { font-size:.9rem; }
@media (max-width: 991.98px) {
    #mapPanel { height: 360px; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">
        <i class="bi bi-broadcast text-danger me-2"></i>Live Επιχειρησιακό
    </h1>
    <div class="d-flex align-items-center gap-3">
        <span class="text-muted refresh-badge">
            Ενημέρωση: <span id="lastRefresh"><?= date('H:i:s') ?></span>
            <span id="refreshSpinner" class="spinner-border spinner-border-sm ms-1 d-none" role="status"></span>
        </span>
        <a href="mission-form.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Νέα Δράση
        </a>
    </div>
</div>

<!-- ── Alert banner ── -->
<div id="alertBanner">
<?php if (!empty($alerts)): ?>
    <?php foreach ($alerts as $al): ?>
    <?php if (in_array($al['type'], $rideCriticalStatuses, true)): ?>
    <div class="alert alert-<?= $al['type'] === 'needs_help' ? 'danger' : 'warning' ?> py-2 d-flex align-items-center gap-2 alert-dismissible fade show">
        <i class="bi bi-sos fs-5"></i>
        <div class="flex-grow-1">
            🆘 <strong><?= h($al['name']) ?></strong>: <?= h($al['status_label'] ?? 'Ειδοποίηση') ?> στη δράση
            <strong><?= h($al['mission_title']) ?></strong>!
            <a href="shift-view.php?id=<?= $al['shift_id'] ?>" class="alert-link ms-2">Σκέλος →</a>
        </div>
        <form method="post" class="m-0 p-0">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="dismiss_help">
            <input type="hidden" name="pr_id" value="<?= $al['pr_id'] ?>">
            <button type="submit" class="btn-close" aria-label="Close"></button>
        </form>
    </div>
    <?php else: ?>
    <div class="alert alert-warning py-2 d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div>
            <strong><?= h($al['mission_title']) ?></strong> —
            Σκέλος <?= formatDateTime($al['start_time']) ?>:
            μόνο <strong><?= $al['approved'] ?>/<?= $al['min'] ?></strong> μέλη εγκεκριμένα!
            <a href="shift-view.php?id=<?= $al['shift_id'] ?>" class="alert-link ms-2">Διαχείριση →</a>
        </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<?php if (empty($missions)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν ανοιχτές δράσεις αυτή τη στιγμή.
    </div>
<?php else: ?>

<div class="row g-4">
    <!-- ── Main: map and route timeline ── -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-map me-1"></i>Χάρτης Δράσεων</h5>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleRoutesBtn">
                        <i class="bi bi-signpost-split me-1"></i>Διαδρομές
                    </button>
                    <?php if (empty($mapPins) && empty($volunteerPins)): ?>
                    <small class="text-muted">Δεν υπάρχουν δεδομένα</small>
                    <?php else: ?>
                    <small class="text-muted"><?= count($volunteerPins) ?> μέλη στον χάρτη</small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="mapPanel"></div>
            </div>
            <div class="card-footer small text-muted">
                <div class="mb-1"><strong>Δράσεις:</strong>
                    <span class="me-2"><i class="bi bi-geo-alt-fill text-success"></i> Πλήρης</span>
                    <span class="me-2"><i class="bi bi-geo-alt-fill" style="color:#fd7e14"></i> Μερικώς</span>
                    <span><i class="bi bi-geo-alt-fill text-danger"></i> Υποστελεχωμένη</span>
                </div>
                <div><strong>Μέλη:</strong>
                    <span class="me-2"><i class="bi bi-circle-fill" style="color:#fd7e14"></i> Σε Κίνηση</span>
                    <span class="me-2"><i class="bi bi-circle-fill text-success"></i> Επί Τόπου</span>
                    <span><i class="bi bi-circle-fill text-danger"></i> Βοήθεια</span>
                </div>
            </div>
        </div>

        <div class="card mt-3 ops-route-timeline">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history me-1"></i>Χρονοδιάγραμμα Διαδρομής</h5>
                <span class="badge bg-light text-dark border"><?= count($routeTimelineMissions) ?> διαδρομές</span>
            </div>
            <?php if (empty($routeTimelineMissions)): ?>
                <div class="card-body text-muted small">Δεν έχει οριστεί χρονοδιάγραμμα διαδρομής για τις ενεργές δράσεις.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($routeTimelineMissions as $routeMission): ?>
                    <div class="list-group-item bg-light">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <a href="mission-view.php?id=<?= $routeMission['id'] ?>" class="fw-semibold text-decoration-none route-mission-title">
                                    <?= h($routeMission['title']) ?>
                                </a>
                                <?php if ($routeMission['day_label']): ?>
                                <span class="badge bg-light text-dark border"><?= h($routeMission['day_label']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($routeMission['directions_url']): ?>
                            <a href="<?= h($routeMission['directions_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem">
                                <i class="bi bi-phone me-1"></i>Άνοιγμα στο κινητό
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php foreach ($routeMission['points'] as $idx => $point): ?>
                    <div class="list-group-item">
                        <div class="d-flex gap-2">
                            <span class="badge bg-primary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:26px;height:26px;"><?= $idx + 1 ?></span>
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <strong><?= h($point['title'] ?: 'Σημείο ' . ($idx + 1)) ?></strong>
                                    <?php if ($point['scheduled_time']): ?>
                                        <span class="badge bg-white text-dark border"><i class="bi bi-clock me-1"></i><?= h($point['scheduled_time']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($point['stop_minutes'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-cup-hot me-1"></i>Στάση <?= (int)$point['stop_minutes'] ?>'</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($point['notes']): ?>
                                    <div class="text-muted small mt-1"><?= nl2br(h($point['notes'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Side: mission cards, attendance and chat ── -->
    <div class="col-lg-4">

        <?php if (empty($activeMissions)): ?>
            <div class="alert alert-info mb-4">
                <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν δράσεις σε εξέλιξη αυτή τη στιγμή.
            </div>
        <?php endif; ?>

        <?php foreach ($activeMissions as $m): ?>
        <?php
            $isUrgent = (bool)$m['is_urgent'];
            $totalApproved = array_sum(array_column($m['shifts'], 'approved'));
            $totalMax      = array_sum(array_column($m['shifts'], 'max_volunteers'));
        ?>
        <div class="card mb-4 ops-mission-card <?= $isUrgent ? 'urgent' : '' ?>">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <?php if ($isUrgent): ?>
                        <span class="badge bg-danger"><i class="bi bi-lightning-fill"></i> ΕΠΕΙΓΟΝ</span>
                    <?php endif; ?>
                    <h5 class="mb-0"><?= h($m['title']) ?></h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-warning"
                            data-bs-toggle="modal" data-bs-target="#broadcastModal-<?= $m['id'] ?>"
                            title="Broadcast σε μέλη">
                        <i class="bi bi-megaphone-fill"></i>
                    </button>
                    <a href="ride-mode.php?mission_id=<?= $m['id'] ?>" class="btn btn-sm btn-primary" title="Ride Control">
                        <i class="bi bi-broadcast-pin"></i>
                    </a>
                    <a href="mission-view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <!-- Mission meta -->
                <div class="d-flex flex-wrap gap-3 mb-3 text-muted small">
                    <?php if ($m['location']): ?>
                        <span><i class="bi bi-geo-alt me-1"></i><?= h($m['location']) ?></span>
                    <?php endif; ?>
                    <span><i class="bi bi-calendar me-1"></i><?= formatDateTime($m['start_datetime']) ?> – <?= formatDateTime($m['end_datetime']) ?></span>
                    <span><i class="bi bi-people me-1"></i><?= $totalApproved ?> / <?= $totalMax ?> μέλη</span>
                </div>

                <!-- Shifts -->
                <?php foreach ($m['shifts'] as $s): ?>
                <?php
                    $startTs  = strtotime($s['start_time']);
                    $endTs    = strtotime($s['end_time']);
                    $nowTs    = time();
                    $isActive = $startTs <= $nowTs && $endTs > $nowTs;
                    $isEnded  = $endTs <= $nowTs;
                    $pct      = $s['max_volunteers'] > 0 ? round(($s['approved'] / $s['max_volunteers']) * 100) : 0;
                    $barColor = $s['approved'] < $s['min_volunteers'] ? 'danger' : ($pct < 60 ? 'warning' : 'success');
                    $shiftVols = $approvedVolunteers[$s['shift_id']] ?? [];
                ?>
                <div class="shift-row <?= $isActive ? 'active-shift' : ($isEnded ? 'ended-shift' : '') ?>"
                     id="shift-<?= $s['shift_id'] ?>">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <?php if ($isActive): ?>
                                <span class="badge bg-success me-1"><i class="bi bi-circle-fill"></i> ΣΕ ΕΞΕΛΙΞΗ</span>
                            <?php elseif ($isEnded): ?>
                                <span class="badge bg-secondary me-1">ΟΛΟΚΛΗΡΩΘΗΚΕ</span>
                            <?php else: ?>
                                <span class="badge bg-info text-dark me-1">ΠΡΟΣΕΧΩΣ</span>
                            <?php endif; ?>
                            <strong><?= formatDateTime($s['start_time']) ?></strong>
                            <span class="text-muted"> – <?= date('H:i', $endTs) ?></span>
                            <span class="countdown text-muted ms-2"
                                  data-start="<?= $startTs ?>" data-end="<?= $endTs ?>"></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success" id="appr-<?= $s['shift_id'] ?>"><?= $s['approved'] ?> Εγκ.</span>
                            <span class="badge bg-warning text-dark" id="pend-<?= $s['shift_id'] ?>"><?= $s['pending'] ?> Εκκρ.</span>
                            <span class="badge bg-primary" id="att-<?= $s['shift_id'] ?>"><?= $s['attended'] ?> Παρόντες</span>
                            <a href="shift-view.php?id=<?= $s['shift_id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Progress bar -->
                    <div class="progress mt-2" style="height:6px;" title="<?= $s['approved'] ?>/<?= $s['max_volunteers'] ?> εγκεκριμένοι">
                        <div class="progress-bar bg-<?= $barColor ?>"
                             id="bar-<?= $s['shift_id'] ?>"
                             style="width:<?= min($pct,100) ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <small class="text-muted"><?= $s['approved'] ?>/<?= $s['max_volunteers'] ?> (min: <?= $s['min_volunteers'] ?>)</small>
                        <small class="text-muted"><?= $pct ?>%</small>
                    </div>

                    <!-- Volunteer chips + quick attendance -->
                    <?php if (!empty($shiftVols)): ?>
                    <?php
                    $fsIcon  = ['on_way' => '🚗', 'on_site' => '✅', 'needs_help' => '🆘', 'breakdown' => '🔧', 'left_behind' => '↩', 'ok' => '✓'];
                    $fsBg    = ['on_way' => '#fff3cd', 'on_site' => '#d1e7dd', 'needs_help' => '#f8d7da', 'breakdown' => '#ede7f6', 'left_behind' => '#cff4fc', 'ok' => '#d1f2eb'];
                    ?>
                    <div class="mt-2">
                        <?php foreach ($shiftVols as $v): ?>
                        <?php
                            $fs       = $v['field_status'] ?? null;
                            $chipBg   = $v['attended'] ? '#d1e7dd' : ($fs ? ($fsBg[$fs] ?? '#e9ecef') : '#e9ecef');
                            $chipTitle= $fs ? ($fsIcon[$fs] ?? '') . ' ' . date('H:i', strtotime($v['field_status_updated_at'] ?? 'now')) : '';
                        ?>
                        <span class="vol-chip <?= $v['attended'] ? 'attended' : '' ?>"
                              style="background:<?= $chipBg ?>;"
                              title="<?= h($chipTitle) ?>"
                              id="chip-<?= $v['pr_id'] ?>">
                            <?php if ($v['attended']): ?>
                                <i class="bi bi-check-circle-fill text-success me-1"></i>
                            <?php elseif ($fs): ?>
                                <?= $fsIcon[$fs] ?? '' ?>
                            <?php endif; ?>
                            <?= h($v['name']) ?>
                            <?php if (!$v['attended'] && $isActive): ?>
                                <form method="post" class="d-inline ms-1"
                                      onsubmit="return confirm('Σήμανση παρουσίας για <?= h(addslashes($v['name'])) ?>;')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="mark_attended">
                                    <input type="hidden" name="pr_id" value="<?= $v['pr_id'] ?>">
                                    <input type="hidden" name="shift_id" value="<?= $s['shift_id'] ?>">
                                    <button type="submit" class="btn btn-link btn-sm p-0 text-success"
                                            title="Σήμανση παρουσίας">✓</button>
                                </form>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php $renderMissionChat($m); ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- ── Upcoming missions (collapsible) ── -->
        <?php if (!empty($upcomingMissions)): ?>
        <div class="text-center my-3">
            <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#upcomingSection" aria-expanded="false">
                <i class="bi bi-calendar-event me-1"></i>Δείτε τις επερχόμενες δράσεις
                <span class="badge bg-secondary ms-1"><?= count($upcomingMissions) ?></span>
            </button>
        </div>
        <div class="collapse" id="upcomingSection">
        <?php foreach ($upcomingMissions as $m): ?>
        <?php
            $isUrgent = (bool)$m['is_urgent'];
            $totalApproved = array_sum(array_column($m['shifts'], 'approved'));
            $totalMax      = array_sum(array_column($m['shifts'], 'max_volunteers'));
        ?>
        <div class="card mb-4 ops-mission-card <?= $isUrgent ? 'urgent' : '' ?>" style="opacity:0.85;">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-info text-dark"><i class="bi bi-clock"></i> ΕΠΕΡΧΟΜΕΝΗ</span>
                    <?php if ($isUrgent): ?>
                        <span class="badge bg-danger"><i class="bi bi-lightning-fill"></i> ΕΠΕΙΓΟΝ</span>
                    <?php endif; ?>
                    <h5 class="mb-0"><?= h($m['title']) ?></h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="ride-mode.php?mission_id=<?= $m['id'] ?>" class="btn btn-sm btn-primary" title="Ride Control">
                        <i class="bi bi-broadcast-pin"></i>
                    </a>
                    <a href="mission-view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3 mb-2 text-muted small">
                    <?php if ($m['location']): ?>
                        <span><i class="bi bi-geo-alt me-1"></i><?= h($m['location']) ?></span>
                    <?php endif; ?>
                    <span><i class="bi bi-calendar me-1"></i><?= formatDateTime($m['start_datetime']) ?> – <?= formatDateTime($m['end_datetime']) ?></span>
                    <span><i class="bi bi-people me-1"></i><?= $totalApproved ?> / <?= $totalMax ?> μέλη</span>
                </div>
                <?php foreach ($m['shifts'] as $s): ?>
                <?php
                    $pct = $s['max_volunteers'] > 0 ? round(($s['approved'] / $s['max_volunteers']) * 100) : 0;
                    $barColor = $s['approved'] < $s['min_volunteers'] ? 'danger' : ($pct < 60 ? 'warning' : 'success');
                ?>
                <div class="shift-row mb-2">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <span class="badge bg-info text-dark me-1">ΠΡΟΣΕΧΩΣ</span>
                            <strong><?= formatDateTime($s['start_time']) ?></strong>
                            <span class="text-muted"> – <?= date('H:i', strtotime($s['end_time'])) ?></span>
                        </div>
                        <div>
                            <span class="badge bg-success"><?= $s['approved'] ?> Εγκ.</span>
                            <span class="badge bg-warning text-dark"><?= $s['pending'] ?> Εκκρ.</span>
                            <a href="shift-view.php?id=<?= $s['shift_id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </div>
                    </div>
                    <div class="progress mt-2" style="height:6px;">
                        <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= min($pct,100) ?>%"></div>
                    </div>
                    <small class="text-muted"><?= $s['approved'] ?>/<?= $s['max_volunteers'] ?> (min: <?= $s['min_volunteers'] ?>)</small>
                </div>
                <?php endforeach; ?>
                <?php $renderMissionChat($m); ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php endif; ?>

<!-- Broadcast Modals (one per mission) -->
<?php foreach ($missions as $m): ?>
<div class="modal fade" id="broadcastModal-<?= $m['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="bi bi-megaphone-fill me-2"></i>Ανακοίνωση: <?= h($m['title']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="broadcast">
                <input type="hidden" name="mission_id" value="<?= $m['id'] ?>">
                <div class="modal-body">
                    <p class="text-muted small">Το μήνυμα θα αποσταλεί ως ειδοποίηση σε <strong>όλα τα εγκεκριμένα μέλη</strong> αυτής της δράσης.</p>
                    <textarea name="broadcast_message" class="form-control" rows="4"
                              placeholder="Πληκτρολογήστε το μήνυμα..."
                              required maxlength="500"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-megaphone-fill me-1"></i>Αποστολή Broadcast
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── Constants & helpers (MUST be before IIFE that uses them) ──────────────────
const fieldStatusColor = { on_way: '#fd7e14', on_site: '#198754', needs_help: '#dc3545', breakdown: '#6f42c1', left_behind: '#0dcaf0', ok: '#20c997' };
const fsIcon  = { on_way: '🚗', on_site: '✅', needs_help: '🆘', breakdown: '🔧', left_behind: '↩', ok: '✓' };
const fsBg    = { on_way: '#fff3cd', on_site: '#d1e7dd', needs_help: '#f8d7da', breakdown: '#ede7f6', left_behind: '#cff4fc', ok: '#d1f2eb' };

// Pulsing volunteer dot HTML
function pulseHtml(color) {
    return '<div style="position:relative;width:22px;height:22px;">'
        + '<div style="position:absolute;top:3px;left:3px;width:16px;height:16px;'
        +   'background:' + color + ';border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px #0004"></div>'
        + '<div style="position:absolute;top:0;left:0;width:22px;height:22px;'
        +   'background:' + color + ';border-radius:50%;opacity:0.4;animation:vol-ping 1.5s ease-out infinite"></div>'
        + '</div>';
}

function renderVolunteerPins(pins) {
    if (!volunteerLayerGroup) return;
    volunteerLayerGroup.clearLayers();
    const statusLabel = { on_way: '🚗 Σε Κίνηση', on_site: '✅ Επί Τόπου', needs_help: '🆘 Χρειάζεται Βοήθεια', breakdown: '🔧 Βλάβη', left_behind: '↩ Έμεινε πίσω', ok: '✓ Είναι ΟΚ' };

    // Group pins by location (round to ~11m precision to catch nearby volunteers)
    const groups = {};
    pins.forEach(p => {
        const key = parseFloat(p.lat).toFixed(4) + ',' + parseFloat(p.lng).toFixed(4);
        if (!groups[key]) groups[key] = [];
        groups[key].push(p);
    });

    Object.values(groups).forEach(group => {
        // Use the worst status color for the group icon.
        const priority = { needs_help: 5, breakdown: 4, left_behind: 3, on_way: 2, on_site: 1, ok: 1 };
        let worstColor = '#0d6efd';
        let worstPriority = 0;
        group.forEach(p => {
            const pr = priority[p.field_status] || 0;
            if (pr > worstPriority) { worstPriority = pr; worstColor = fieldStatusColor[p.field_status]; }
        });
        if (worstPriority === 0 && group.length > 0) {
            const firstStatus = group[0].field_status;
            worstColor = fieldStatusColor[firstStatus] || '#0d6efd';
        }

        // Build popup with all names
        const popupLines = group.map(p =>
            '<strong>' + p.name + '</strong> — ' + (statusLabel[p.field_status] || '—') + ' <small>στις ' + p.ts + '</small>'
        ).join('<br>');

        const countBadge = group.length > 1
            ? '<div style="position:absolute;top:-6px;right:-6px;background:#fff;color:#333;border-radius:50%;width:18px;height:18px;font-size:11px;font-weight:700;line-height:18px;text-align:center;box-shadow:0 1px 3px #0005;">' + group.length + '</div>'
            : '';

        const dotHtml = '<div style="position:relative;width:22px;height:22px;">'
            + '<div style="position:absolute;top:3px;left:3px;width:16px;height:16px;'
            +   'background:' + worstColor + ';border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px #0004"></div>'
            + '<div style="position:absolute;top:0;left:0;width:22px;height:22px;'
            +   'background:' + worstColor + ';border-radius:50%;opacity:0.4;animation:vol-ping 1.5s ease-out infinite"></div>'
            + countBadge + '</div>';

        const icon = L.divIcon({ className: '', html: dotHtml, iconSize: [22, 22], iconAnchor: [11, 11] });
        const avg = { lat: group[0].lat, lng: group[0].lng };
        L.marker([avg.lat, avg.lng], { icon })
         .addTo(volunteerLayerGroup)
         .bindPopup(popupLines, { maxWidth: 300 });
    });
}

// ── Map initialization ────────────────────────────────────────────────────────
let opsMap = null;
let volunteerLayerGroup = null;
let routeLayerGroup = null;

(function() {
    const missionPins = <?= json_encode(array_values($mapPins)) ?>;
    const volPins     = <?= json_encode(array_values($volunteerPins)) ?>;
    const routeStorageKey = 'easyride.ops.routes.visible';
    let routesVisible = localStorage.getItem(routeStorageKey) === '1';

    // Determine map center: missions first, then volunteer GPS, then Greece default
    let center = [37.97, 23.73], zoom = 7;
    if (missionPins.length)   { center = [missionPins[0].lat, missionPins[0].lng]; zoom = 10; }
    else if (volPins.length)  { center = [volPins[0].lat,     volPins[0].lng];     zoom = 13; }

    opsMap = L.map('mapPanel').setView(center, zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(opsMap);

    const svgPin = (color) => `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="${color}" viewBox="0 0 16 16" style="filter: drop-shadow(0px 2px 2px rgba(0,0,0,0.4));"><path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10m0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6"/></svg>`;
    const icons = {
        green:  L.divIcon({className:'', html: svgPin('#198754'), iconSize:[32,32], iconAnchor:[16,32], popupAnchor:[0,-32]}),
        orange: L.divIcon({className:'', html: svgPin('#fd7e14'), iconSize:[32,32], iconAnchor:[16,32], popupAnchor:[0,-32]}),
        red:    L.divIcon({className:'', html: svgPin('#dc3545'), iconSize:[32,32], iconAnchor:[16,32], popupAnchor:[0,-32]}),
    };

    routeLayerGroup = L.layerGroup();

    missionPins.forEach(p => {
        const icon = p.urgent ? icons.red : (icons[p.color] || icons.green);
        if (Array.isArray(p.route) && p.route.length) {
            const routeLatLngs = p.route.map(point => [point.lat, point.lng]);
            if (routeLatLngs.length >= 2) {
                L.polyline((Array.isArray(p.geometry) && p.geometry.length >= 2) ? p.geometry : routeLatLngs, {
                    color: p.urgent ? '#dc3545' : '#0d6efd',
                    weight: 4,
                    opacity: 0.75
                }).addTo(routeLayerGroup);
            }
            routeLatLngs.forEach((latLng, index) => {
                L.circleMarker(latLng, {
                    radius: 4,
                    color: p.urgent ? '#dc3545' : '#0d6efd',
                    fillColor: p.urgent ? '#dc3545' : '#0d6efd',
                    fillOpacity: 0.85,
                    weight: 1
                }).addTo(routeLayerGroup).bindTooltip(String(index + 1), {
                    permanent: false,
                    direction: 'top'
                });
            });
        }
        L.marker([p.lat, p.lng], {icon})
         .addTo(opsMap)
         .bindPopup(`<strong>${p.title}</strong><br><a href="${p.url}">Προβολή Δράσης →</a>`);
    });

    const toggleRoutesBtn = document.getElementById('toggleRoutesBtn');
    function applyRouteVisibility() {
        if (!routeLayerGroup || !toggleRoutesBtn) return;
        if (routesVisible) {
            routeLayerGroup.addTo(opsMap);
            toggleRoutesBtn.classList.remove('btn-outline-secondary');
            toggleRoutesBtn.classList.add('btn-primary');
        } else {
            routeLayerGroup.remove();
            toggleRoutesBtn.classList.add('btn-outline-secondary');
            toggleRoutesBtn.classList.remove('btn-primary');
        }
    }
    if (toggleRoutesBtn) {
        toggleRoutesBtn.addEventListener('click', () => {
            routesVisible = !routesVisible;
            localStorage.setItem(routeStorageKey, routesVisible ? '1' : '0');
            applyRouteVisibility();
        });
    }
    applyRouteVisibility();

    volunteerLayerGroup = L.layerGroup().addTo(opsMap);
    renderVolunteerPins(volPins);

    // Fit bounds to include ALL pins (missions + volunteers)
    const allCoords = [
        ...missionPins.map(p => [p.lat, p.lng]),
        ...(routesVisible ? missionPins.flatMap(p => Array.isArray(p.route) ? p.route.map(point => [point.lat, point.lng]) : []) : []),
        ...volPins.map(p => [p.lat, p.lng])
    ];
    if (allCoords.length > 1) {
        opsMap.fitBounds(L.latLngBounds(allCoords), {padding: [30, 30]});
    } else if (allCoords.length === 1) {
        opsMap.setView(allCoords[0], 14);
    }

    // Force redraw in case container had a layout pass before Leaflet init
    setTimeout(() => opsMap.invalidateSize(), 200);
})();

// ── Countdown timers ──────────────────────────────────────────────────────────
function updateCountdowns() {
    document.querySelectorAll('.countdown[data-start]').forEach(el => {
        const start = parseInt(el.dataset.start) * 1000;
        const end   = parseInt(el.dataset.end)   * 1000;
        const now   = Date.now();
        if (now < start) {
            const diff = Math.floor((start - now) / 1000);
            const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60);
            el.textContent = `(σε ${h}ω ${m}λ)`;
            el.className = 'countdown text-muted ms-2';
        } else if (now <= end) {
            const diff = Math.floor((end - now) / 1000);
            const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60);
            el.textContent = `(λήγει σε ${h}ω ${m}λ)`;
            el.className = 'countdown text-success ms-2';
        } else {
            el.textContent = '(ολοκληρώθηκε)';
            el.className = 'countdown text-muted ms-2';
        }
    });
}
updateCountdowns();
setInterval(updateCountdowns, 30000);

// ── Live refresh (every 15s) ──────────────────────────────────────────────────────
function liveRefresh() {
    const spinner = document.getElementById('refreshSpinner');
    const tsEl    = document.getElementById('lastRefresh');
    if (spinner) spinner.classList.remove('d-none');

    fetch('ops-dashboard.php?ajax=1')
        .then(r => r.json())
        .then(data => {
            tsEl.textContent = data.ts;

            // Update shift counters & progress bars
            Object.entries(data.shifts).forEach(([key, s]) => {
                const id = key.replace('shift_', '');
                const pct = s.max > 0 ? Math.min(Math.round(s.approved / s.max * 100), 100) : 0;
                const color = s.approved < s.min ? 'danger' : (pct < 60 ? 'warning' : 'success');
                const appr = document.getElementById('appr-' + id);
                const pend = document.getElementById('pend-' + id);
                const att  = document.getElementById('att-'  + id);
                const bar  = document.getElementById('bar-'  + id);
                if (appr) appr.textContent = s.approved + ' Εγκ.';
                if (pend) pend.textContent = s.pending  + ' Εκκρ.';
                if (att)  att.textContent  = s.attended + ' Παρόντες';
                if (bar) { bar.style.width = pct + '%'; bar.className = 'progress-bar bg-' + color; }
            });

            // Update volunteer chip background from field_status
            if (data.field_status) {
                Object.entries(data.field_status).forEach(([key, fs]) => {
                    const prId = key.replace('pr_', '');
                    const chip = document.getElementById('chip-' + prId);
                    if (chip && fs) chip.style.background = fsBg[fs] || '#e9ecef';
                });
            }

            // Refresh GPS volunteer pins on map + re-center if needed
            if (data.pins && data.pins.length) {
                renderVolunteerPins(data.pins);
                // If map is at default Greece view (zoom 7), center on the first volunteer
                if (opsMap && opsMap.getZoom() <= 7) {
                    opsMap.setView([data.pins[0].lat, data.pins[0].lng], 14);
                }
            } else if (data.pins) {
                renderVolunteerPins(data.pins);
            }

            // Real-time alert banner update
            if (data.alerts !== undefined) updateAlertBanner(data.alerts);
        })
        .catch(() => {})
        .finally(() => { if (spinner) spinner.classList.add('d-none'); });
}

let _prevNeedsHelp = new Set(
    <?php echo json_encode(array_map(fn($a) => $a['name'] ?? '', array_filter($alerts ?? [], fn($a) => in_array($a['type'] ?? '', $rideCriticalStatuses, true)))); ?>
);

function updateAlertBanner(alerts) {
    const banner = document.getElementById('alertBanner');
    if (!banner) return;
    let html = '';
    let newNeedsHelp = new Set();
    alerts.forEach(al => {
        if (['needs_help', 'breakdown', 'left_behind'].includes(al.type)) {
            newNeedsHelp.add(al.name);
            const alertClass = al.type === 'needs_help' ? 'alert-danger' : 'alert-warning';
            const label = al.status_label || 'Ειδοποίηση';
            html += `<div class="alert ${alertClass} py-2 d-flex align-items-center gap-2 alert-dismissible fade show">`
                  + `<i class="bi bi-sos fs-5"></i>`
                  + `<div class="flex-grow-1">🆘 <strong>${al.name}</strong>: ${label} στη δράση `
                  + `<strong>${al.mission_title}</strong>!`
                  + ` <a href="shift-view.php?id=${al.shift_id}" class="alert-link ms-2">Σκέλος →</a></div>`
                  + `<form method="post" class="m-0 p-0">`
                  + `<?= csrfField() ?>`
                  + `<input type="hidden" name="action" value="dismiss_help">`
                  + `<input type="hidden" name="pr_id" value="${al.pr_id}">`
                  + `<button type="submit" class="btn-close" aria-label="Close"></button>`
                  + `</form></div>`;
        } else if (al.type === 'understaffed') {
            html += `<div class="alert alert-warning py-2 d-flex align-items-center gap-2">`
                  + `<i class="bi bi-exclamation-triangle-fill fs-5"></i>`
                  + `<div><strong>${al.mission_title}</strong> — Σκέλος ${al.start_time}: `
                  + `μόνο <strong>${al.approved}/${al.min}</strong> μέλη εγκεκριμένα!`
                  + ` <a href="shift-view.php?id=${al.shift_id}" class="alert-link ms-2">Διαχείριση →</a></div></div>`;
        }
    });
    banner.innerHTML = html;
    // Flash banner red if new needs_help volunteer appeared
    newNeedsHelp.forEach(name => {
        if (!_prevNeedsHelp.has(name)) {
            banner.style.transition = 'background 0.5s';
            banner.style.background = '#f8d7da';
            setTimeout(() => { banner.style.background = ''; banner.style.transition = ''; }, 1500);
        }
    });
    _prevNeedsHelp = newNeedsHelp;
}
setInterval(liveRefresh, 15000);

document.querySelectorAll('.ops-chat-form textarea').forEach(textarea => {
    textarea.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || event.shiftKey) {
            return;
        }

        event.preventDefault();
        const form = textarea.closest('form');
        if (form && textarea.value.trim() !== '') {
            form.requestSubmit();
        }
    });
});
</script>
<style>
@keyframes vol-ping {
  0%   { transform: scale(0.8); opacity: 0.6; }
  100% { transform: scale(2.5); opacity: 0; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
