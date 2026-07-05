<?php
/**
 * EasyRide - Action Create/Edit Form
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ride-functions.php';
requirePermission('missions_manage');

$id = (int) get('id');
$isEdit = !empty($id);
$pageTitle = $isEdit ? 'Επεξεργασία Δράσης' : 'Νέα Δράση';

$user = getCurrentUser();
$missionTypes = dbFetchAll("SELECT id, name, color, icon FROM mission_types WHERE is_active = 1 ORDER BY sort_order");

// Get all active volunteers for responsible selection
$allVolunteers = dbFetchAll("SELECT id, name, role FROM users WHERE is_active = 1 AND role IN (?, ?, ?, ?) ORDER BY name", 
    [ROLE_VOLUNTEER, ROLE_SHIFT_LEADER, ROLE_DEPARTMENT_ADMIN, ROLE_SYSTEM_ADMIN]);

$mission = null;
if ($isEdit) {
    $mission = dbFetchOne("SELECT * FROM missions WHERE id = ?", [$id]);
    if (!$mission) {
        setFlash('error', 'Η δράση δεν βρέθηκε.');
        redirect('missions.php');
    }
    
    // Check permission
    if ($user['role'] === ROLE_DEPARTMENT_ADMIN && $mission['department_id'] != $user['department_id']) {
        setFlash('error', 'Δεν έχετε δικαίωμα επεξεργασίας αυτής της δράσης.');
        redirect('missions.php');
    }
}

function normalizeRoutePointsJson($raw): string {
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return '';
    }

    $points = [];
    foreach ($decoded as $point) {
        if (!is_array($point) || !isset($point['lat'], $point['lng'])) {
            continue;
        }
        $lat = (float)$point['lat'];
        $lng = (float)$point['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            continue;
        }
        $title = trim((string)($point['title'] ?? ''));
        $scheduledTime = trim((string)($point['scheduled_time'] ?? ''));
        $stopMinutes = max(0, min(1440, (int)($point['stop_minutes'] ?? 0)));
        $notes = trim((string)($point['notes'] ?? ''));

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $scheduledTime)) {
            $scheduledTime = '';
        }

        $points[] = [
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
            'title' => mb_substr($title, 0, 120),
            'scheduled_time' => $scheduledTime,
            'stop_minutes' => $stopMinutes,
            'notes' => mb_substr($notes, 0, 500),
        ];
        if (count($points) >= 300) {
            break;
        }
    }

    return empty($points) ? '' : json_encode($points, JSON_UNESCAPED_UNICODE);
}

function normalizeRouteGeometryJson($raw): string {
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return '';
    }

    $geometry = [];
    foreach ($decoded as $pair) {
        if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
            continue;
        }
        $lat = (float)$pair[0];
        $lng = (float)$pair[1];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            continue;
        }
        $geometry[] = [round($lat, 6), round($lng, 6)];
        if (count($geometry) >= 20000) {
            break;
        }
    }

    return count($geometry) >= 2 ? json_encode($geometry) : '';
}

function normalizeMissionDaysJson($raw): array {
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $days = [];
    foreach ($decoded as $day) {
        if (!is_array($day) || !isset($day['day_number'], $day['day_date'])) {
            continue;
        }
        $dayNumber = (int)$day['day_number'];
        if ($dayNumber < 1 || $dayNumber > 60) {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day['day_date'])) {
            continue;
        }

        $routePointsRaw = json_encode($day['route_points'] ?? []);
        $routeGeometryRaw = json_encode($day['route_geometry'] ?? []);

        $days[] = [
            'day_number' => $dayNumber,
            'day_date' => $day['day_date'],
            'title' => mb_substr(trim((string)($day['title'] ?? '')), 0, 160),
            'overnight_notes' => mb_substr(trim((string)($day['overnight_notes'] ?? '')), 0, 2000),
            'route_points' => normalizeRoutePointsJson($routePointsRaw),
            'route_geometry' => normalizeRouteGeometryJson($routeGeometryRaw),
            'route_distance_meters' => max(0, (int)($day['route_distance_meters'] ?? 0)),
            'route_duration_seconds' => max(0, (int)($day['route_duration_seconds'] ?? 0)),
            'route_provider' => in_array($day['route_provider'] ?? '', ['google'], true) ? 'google' : '',
        ];

        if (count($days) >= 60) {
            break;
        }
    }

    usort($days, function ($a, $b) {
        return $a['day_number'] <=> $b['day_number'];
    });

    return $days;
}

function saveMissionDaysForMission(int $missionId, array $days): void {
    dbExecute("DELETE FROM mission_days WHERE mission_id = ?", [$missionId]);

    foreach ($days as $day) {
        $geometry = $day['route_geometry'];
        $distance = $day['route_distance_meters'];
        $duration = $day['route_duration_seconds'];
        $provider = $day['route_provider'];

        if ($day['route_points'] === '' || $provider !== 'google' || $geometry === '' || $distance <= 0 || $duration <= 0) {
            $geometry = null;
            $distance = null;
            $duration = null;
            $provider = null;
        }

        dbExecute(
            "INSERT INTO mission_days
             (mission_id, day_number, day_date, title, overnight_notes, route_points, route_geometry, route_distance_meters, route_duration_seconds, route_provider, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $missionId,
                $day['day_number'],
                $day['day_date'],
                $day['title'] !== '' ? $day['title'] : null,
                $day['overnight_notes'] !== '' ? $day['overnight_notes'] : null,
                $day['route_points'] !== '' ? $day['route_points'] : null,
                $geometry,
                $distance,
                $duration,
                $provider,
            ]
        );
    }
}

$errors = [];

if (isPost()) {
    verifyCsrf();
    
    $data = [
        'title' => post('title'),
        'description' => post('description'),
        'mission_type_id' => (int)post('mission_type_id', 1),
        'department_id' => null,
        'location' => post('location'),
        'location_details' => post('location_details'),
        'maps_link' => trim(post('maps_link')),
        'latitude' => post('latitude') ?: null,
        'longitude' => post('longitude') ?: null,
        'route_points' => normalizeRoutePointsJson($_POST['route_points'] ?? ''),
        'route_geometry' => normalizeRouteGeometryJson($_POST['route_geometry'] ?? ''),
        'route_distance_meters' => max(0, (int)post('route_distance_meters', 0)),
        'route_duration_seconds' => max(0, (int)post('route_duration_seconds', 0)),
        'route_provider' => in_array(post('route_provider'), ['google'], true) ? 'google' : '',
        'start_datetime' => post('start_datetime'),
        'end_datetime' => post('end_datetime'),
        'requirements' => post('requirements'),
        'notes' => post('notes'),
        'is_urgent' => isset($_POST['is_urgent']) ? 1 : 0,
        'status' => post('status') ?: STATUS_DRAFT,
        'responsible_user_id' => post('responsible_user_id') ?: null,
    ];

    // Routed geometry is kept only as a complete Google result for an actual route,
    // otherwise the app falls back to the straight-line estimate
    if ($data['route_points'] === '' || $data['route_provider'] !== 'google' || $data['route_geometry'] === ''
        || $data['route_distance_meters'] <= 0 || $data['route_duration_seconds'] <= 0) {
        $data['route_geometry'] = null;
        $data['route_distance_meters'] = null;
        $data['route_duration_seconds'] = null;
        $data['route_provider'] = null;
    }

    // Multi-day mission: route data lives in mission_days instead of the mission-level columns
    $missionDaysData = normalizeMissionDaysJson($_POST['mission_days_json'] ?? '');
    $isMultiDaySubmit = !empty($missionDaysData);
    if ($isMultiDaySubmit) {
        $data['route_points'] = '';
        $data['route_geometry'] = null;
        $data['route_distance_meters'] = null;
        $data['route_duration_seconds'] = null;
        $data['route_provider'] = null;
    }

    // Validation
    if (empty($data['title'])) $errors[] = 'Ο τίτλος είναι υποχρεωτικός.';
    if (empty($data['location'])) $errors[] = 'Η τοποθεσία είναι υποχρεωτική.';
    if (empty($data['start_datetime'])) $errors[] = 'Η ημερομηνία έναρξης είναι υποχρεωτική.';
    if (empty($data['end_datetime'])) $errors[] = 'Η ημερομηνία λήξης είναι υποχρεωτική.';
    if ($data['maps_link'] !== '' && !filter_var($data['maps_link'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Ο σύνδεσμος Google Maps δεν είναι έγκυρος.';
    }

    // Recurring fields (create-only)
    $isRecurring = !$isEdit && isset($_POST['is_recurring']);
    $recurType    = post('recur_type');      // 'weekly' | 'random_days' | 'interval'
    $recurEndDate = post('recur_end_date');  // Y-m-d
    if ($isRecurring) {
        if (!in_array($recurType, ['weekly', 'random_days', 'interval'])) {
            $errors[] = 'Επιλέξτε έγκυρο τύπο επανάληψης.';
        }
        if (empty($recurEndDate)) {
            $errors[] = 'Η ημερομηνία λήξης σειράς είναι υποχρεωτική.';
        }
    }
    
    // Convert date format
    if (!empty($data['start_datetime'])) {
        $data['start_datetime'] = DateTime::createFromFormat('d/m/Y H:i', $data['start_datetime'])->format('Y-m-d H:i:s');
    }
    if (!empty($data['end_datetime'])) {
        $data['end_datetime'] = DateTime::createFromFormat('d/m/Y H:i', $data['end_datetime'])->format('Y-m-d H:i:s');
    }
    
    if (empty($errors)) {
        try {
            if ($isEdit) {
                $sql = "UPDATE missions SET
                        title = ?, description = ?, mission_type_id = ?, department_id = ?,
                        location = ?, location_details = ?, maps_link = ?, route_points = ?,
                        route_geometry = ?, route_distance_meters = ?, route_duration_seconds = ?, route_provider = ?,
                        latitude = ?, longitude = ?,
                        start_datetime = ?, end_datetime = ?, requirements = ?, notes = ?,
                        is_urgent = ?, status = ?, responsible_user_id = ?, updated_at = NOW()
                        WHERE id = ?";
                dbExecute($sql, [
                    $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
                    $data['location'], $data['location_details'], $data['maps_link'] ?: null, $data['route_points'] ?: null,
                    $data['route_geometry'], $data['route_distance_meters'], $data['route_duration_seconds'], $data['route_provider'],
                    $data['latitude'], $data['longitude'],
                    $data['start_datetime'], $data['end_datetime'], $data['requirements'], $data['notes'],
                    $data['is_urgent'], $data['status'], $data['responsible_user_id'], $id
                ]);

                saveMissionDaysForMission($id, $missionDaysData);

                logAudit('update', 'missions', $id, $mission, $data);
                setFlash('success', 'Η δράση ενημερώθηκε επιτυχώς.');
            } else {
                if ($isRecurring) {
                    // ── RECURRING MISSIONS ──────────────────────────────────────────
                    $startDT       = new DateTime($data['start_datetime']);
                    $endDT         = new DateTime($data['end_datetime']);
                    $startDateOnly = new DateTime($startDT->format('Y-m-d'));
                    $endDateSeries = new DateTime($recurEndDate);
                    $startTime     = $startDT->format('H:i:s');
                    $endTime       = $endDT->format('H:i:s');
                    $recurDates    = [];
                    $meta          = [];

                    if ($recurType === 'weekly') {
                        $rawWeekdays = array_unique(array_map('intval', array_filter(
                            (array)($_POST['recur_weekdays'] ?? []),
                            fn($d) => $d >= 1 && $d <= 7
                        )));
                        if (empty($rawWeekdays)) {
                            throw new Exception('Επιλέξτε τουλάχιστον μία ημέρα εβδομάδας.');
                        }
                        $cursor = clone $startDateOnly;
                        while ($cursor <= $endDateSeries) {
                            if (in_array((int)$cursor->format('N'), $rawWeekdays)) {
                                $recurDates[] = $cursor->format('Y-m-d');
                            }
                            $cursor->modify('+1 day');
                        }
                        $meta = ['weekdays' => array_values($rawWeekdays)];

                    } elseif ($recurType === 'random_days') {
                        $rawDates = post('recur_random_dates');
                        foreach (array_filter(array_map('trim', explode(',', $rawDates))) as $d) {
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                                $dt = new DateTime($d);
                                if ($dt >= $startDateOnly && $dt <= $endDateSeries) {
                                    $recurDates[] = $dt->format('Y-m-d');
                                }
                            }
                        }
                        $recurDates = array_values(array_unique($recurDates));
                        sort($recurDates);
                        if (empty($recurDates)) {
                            throw new Exception('Επιλέξτε τουλάχιστον μία ημερομηνία στο καθορισμένο εύρος.');
                        }
                        $meta = ['random_dates' => $recurDates];

                    } else { // interval
                        $intervalDays = max(1, min(6, (int)post('recur_interval_days', 1)));
                        $cursor = clone $startDateOnly;
                        while ($cursor <= $endDateSeries) {
                            $recurDates[] = $cursor->format('Y-m-d');
                            $cursor->modify('+' . $intervalDays . ' days');
                        }
                        $meta = [
                            'interval_days'       => $intervalDays,
                            'interval_start_date' => $startDateOnly->format('Y-m-d'),
                        ];
                    }

                    if (count($recurDates) > 100) {
                        throw new Exception('Υπέρβαση ορίου 100 δράσεων ανά σειρά (βρέθηκαν ' . count($recurDates) . '). Μειώστε το εύρος ημερομηνιών.');
                    }
                    if (empty($recurDates)) {
                        throw new Exception('Δεν βρέθηκαν ημερομηνίες στο εύρος που ορίσατε.');
                    }

                    // Insert recurrence record
                    $recurrenceId = dbInsert(
                        "INSERT INTO mission_recurrences (type, weekdays, random_dates, interval_days, interval_start_date, end_date, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [
                            $recurType,
                            isset($meta['weekdays'])      ? json_encode($meta['weekdays'])      : null,
                            isset($meta['random_dates'])  ? json_encode($meta['random_dates'])  : null,
                            isset($meta['interval_days']) ? $meta['interval_days']              : null,
                            isset($meta['interval_start_date']) ? $meta['interval_start_date']  : null,
                            $recurEndDate,
                            $user['id'],
                        ]
                    );

                    // Insert one mission + one default shift per date
                    $createdCount = 0;
                    foreach ($recurDates as $instanceDate) {
                        $instStart = $instanceDate . ' ' . $startTime;
                        $instEnd   = $instanceDate . ' ' . $endTime;
                        $missionId = dbInsert(
                            "INSERT INTO missions
                             (title, description, mission_type_id, department_id, location, location_details, maps_link, route_points,
                              route_geometry, route_distance_meters, route_duration_seconds, route_provider,
                              latitude, longitude, start_datetime, end_datetime, requirements, notes,
                              is_urgent, status, responsible_user_id, created_by, recurrence_id, recurrence_instance_date, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                            [
                                $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
                                $data['location'], $data['location_details'], $data['maps_link'] ?: null, $data['route_points'] ?: null,
                                $data['route_geometry'], $data['route_distance_meters'], $data['route_duration_seconds'], $data['route_provider'],
                                $data['latitude'], $data['longitude'],
                                $instStart, $instEnd, $data['requirements'], $data['notes'],
                                $data['is_urgent'], STATUS_OPEN, $data['responsible_user_id'], $user['id'],
                                $recurrenceId, $instanceDate,
                            ]
                        );
                        dbInsert(
                            "INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers, min_volunteers, created_at, updated_at)
                             VALUES (?, ?, ?, 5, 1, NOW(), NOW())",
                            [$missionId, $instStart, $instEnd]
                        );
                        logAudit('create', 'missions', $missionId, null, [
                            'title'        => $data['title'],
                            'recurrence_id' => $recurrenceId,
                        ]);
                        $createdCount++;
                    }

                    $firstDate = formatDateGreek($recurDates[0]);
                    $lastDate  = formatDateGreek(end($recurDates));
                    setFlash('success', 'Δημιουργήθηκαν ' . $createdCount . ' δράσεις σε νέα σειρά (' . $firstDate . ' – ' . $lastDate . ').');
                    redirect('missions.php');

                } else {
                    // ── SINGLE MISSION ───────────────────────────────────────────────
                    $sql = "INSERT INTO missions
                            (title, description, mission_type_id, department_id, location, location_details, maps_link, route_points,
                             route_geometry, route_distance_meters, route_duration_seconds, route_provider,
                             latitude, longitude, start_datetime, end_datetime, requirements, notes,
                             is_urgent, status, responsible_user_id, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $newId = dbInsert($sql, [
                        $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
                        $data['location'], $data['location_details'], $data['maps_link'] ?: null, $data['route_points'] ?: null,
                        $data['route_geometry'], $data['route_distance_meters'], $data['route_duration_seconds'], $data['route_provider'],
                        $data['latitude'], $data['longitude'],
                        $data['start_datetime'], $data['end_datetime'], $data['requirements'], $data['notes'],
                        $data['is_urgent'], $data['status'], $data['responsible_user_id'], $user['id']
                    ]);
                    dbInsert(
                        "INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers, min_volunteers, created_at, updated_at)
                         VALUES (?, ?, ?, 5, 1, NOW(), NOW())",
                        [$newId, $data['start_datetime'], $data['end_datetime']]
                    );

                    saveMissionDaysForMission($newId, $missionDaysData);

                    logAudit('create', 'missions', $newId, null, $data);
                    setFlash('success', 'Η δράση δημιουργήθηκε επιτυχώς.');
                    redirect('mission-view.php?id=' . $newId);
                }
            }
            
            redirect('missions.php');
        } catch (Exception $e) {
            $errors[] = 'Σφάλμα αποθήκευσης: ' . $e->getMessage();
        }
    }
}

// Format dates for form
$startDate = '';
$endDate = '';
$startDateIso = '';
$endDateIso = '';
if ($mission) {
    $startDate = formatDateTime($mission['start_datetime'], 'd/m/Y H:i');
    $endDate   = formatDateTime($mission['end_datetime'],   'd/m/Y H:i');
    $startDateIso = date('Y-m-d\TH:i', strtotime($mission['start_datetime']));
    $endDateIso   = date('Y-m-d\TH:i', strtotime($mission['end_datetime']));
}
$routePointsForForm = normalizeRoutePointsJson(post('route_points', $mission['route_points'] ?? ''));
$routePointsInitial = json_decode($routePointsForForm ?: '[]', true) ?: [];
$routeGeometryForForm = normalizeRouteGeometryJson(post('route_geometry', $mission['route_geometry'] ?? ''));
$routeGeometryInitial = json_decode($routeGeometryForForm ?: '[]', true) ?: [];
$routeProviderInitial = post('route_provider', $mission['route_provider'] ?? '') === 'google' ? 'google' : '';
$routeDistanceInitial = max(0, (int)post('route_distance_meters', $mission['route_distance_meters'] ?? 0));
$routeDurationInitial = max(0, (int)post('route_duration_seconds', $mission['route_duration_seconds'] ?? 0));
if ($routeProviderInitial !== 'google' || empty($routeGeometryInitial) || $routeDistanceInitial <= 0 || $routeDurationInitial <= 0) {
    $routeGeometryForForm = '';
    $routeGeometryInitial = [];
    $routeProviderInitial = '';
    $routeDistanceInitial = 0;
    $routeDurationInitial = 0;
}
$googleRoutingEnabled = trim((string)getSetting('google_maps_api_key', '')) !== '';

// Multi-day itinerary: load existing days on edit, and detect the initial multi-day state
$missionDaysInitial = [];
if ($isEdit) {
    $missionDayRows = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number", [$id]);
    foreach ($missionDayRows as $row) {
        $missionDaysInitial[] = [
            'day_number' => (int)$row['day_number'],
            'day_date' => $row['day_date'],
            'title' => $row['title'] ?? '',
            'overnight_notes' => $row['overnight_notes'] ?? '',
            'route_points' => json_decode($row['route_points'] ?? '[]', true) ?: [],
            'route_geometry' => json_decode($row['route_geometry'] ?? '[]', true) ?: [],
            'route_distance_meters' => (int)($row['route_distance_meters'] ?? 0),
            'route_duration_seconds' => (int)($row['route_duration_seconds'] ?? 0),
            'route_provider' => $row['route_provider'] ?? '',
        ];
    }
}

$isMultiDayInitial = false;
if (!empty($startDate) && !empty($endDate)) {
    $startD = DateTime::createFromFormat('d/m/Y H:i', $startDate);
    $endD = DateTime::createFromFormat('d/m/Y H:i', $endDate);
    if ($startD && $endD) {
        $isMultiDayInitial = $startD->format('Y-m-d') !== $endD->format('Y-m-d');
    }
}

include __DIR__ . '/includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
#routeEditorMap { height: 320px; border-radius: 8px; border: 1px solid #dee2e6; overflow: hidden; }
.route-point-count { min-width: 74px; text-align: center; }
.route-point-row { border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; background: #fff; }
.route-point-row + .route-point-row { margin-top: 8px; }
.route-point-index { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
#missionDayTabs .nav-link { cursor: pointer; }
.route-fullscreen-layout { display: flex; height: 100%; }
.route-fullscreen-map { flex: 1 1 auto; height: 100%; }
.route-fullscreen-panel { flex: 0 0 200px; width: 200px; overflow-y: auto; padding: 10px; border-left: 1px solid #dee2e6; background: #f8f9fa; }
@media (max-width: 767.98px) {
    .route-fullscreen-layout { flex-direction: column; }
    .route-fullscreen-map { height: 60vh; }
    .route-fullscreen-panel { flex: 0 0 auto; width: 100%; border-left: 0; border-top: 1px solid #dee2e6; max-height: 30vh; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-flag me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="missions.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Πίσω
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="" id="missionForm">
    <?= csrfField() ?>
    
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Βασικές Πληροφορίες</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Τίτλος *</label>
                        <input type="text" class="form-control" id="title" name="title" 
                               value="<?= h($mission['title'] ?? post('title')) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Περιγραφή</label>
                        <textarea class="form-control summernote-basic" id="description" name="description"><?= h($mission['description'] ?? post('description')) ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="mission_type_id" class="form-label">Τύπος Δράσης</label>
                            <select class="form-select" id="mission_type_id" name="mission_type_id">
                                <?php foreach ($missionTypes as $mt): ?>
                                    <option value="<?= $mt['id'] ?>" <?= ($mission['mission_type_id'] ?? post('mission_type_id', 1)) == $mt['id'] ? 'selected' : '' ?>>
                                        <?= h($mt['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Τοποθεσία & Χρόνος</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="location" class="form-label">Τοποθεσία *</label>
                        <input type="text" class="form-control" id="location" name="location" 
                               value="<?= h($mission['location'] ?? post('location')) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="location_details" class="form-label">Λεπτομέρειες Τοποθεσίας</label>
                        <input type="text" class="form-control" id="location_details" name="location_details" 
                               value="<?= h($mission['location_details'] ?? post('location_details')) ?>"
                               placeholder="π.χ. Είσοδος από την οδό...">
                    </div>
                    
                    <div class="mb-3">
                        <label for="maps_link" class="form-label">
                            <i class="bi bi-geo-alt-fill text-danger me-1"></i>Σύνδεσμος Google Maps
                        </label>
                        <div class="input-group">
                            <input type="url" class="form-control" id="maps_link" name="maps_link"
                                   placeholder="https://maps.app.goo.gl/... ή https://www.google.com/maps?q=..."
                                   value="<?= h($mission['maps_link'] ?? post('maps_link')) ?>">
                            <button type="button" class="btn btn-outline-secondary" id="parseMapsBtn" title="Εξαγωγή συντεταγμένων">
                                <i class="bi bi-crosshair"></i>
                            </button>
                        </div>
                        <div class="form-text">Επικολλήστε έναν σύνδεσμο από Google Maps για χάρτη και ακριβή πρόβλεψη καιρού.</div>
                        <div id="mapsParseStatus" class="mt-1"></div>
                    </div>

                    <input type="hidden" id="latitude" name="latitude" value="<?= h($mission['latitude'] ?? post('latitude')) ?>">
                    <input type="hidden" id="longitude" name="longitude" value="<?= h($mission['longitude'] ?? post('longitude')) ?>">
                    <input type="hidden" id="route_points" name="route_points" value="<?= h($routePointsForForm) ?>">
                    <input type="hidden" id="route_geometry" name="route_geometry" value="<?= h($routeGeometryForForm) ?>">
                    <input type="hidden" id="route_distance_meters" name="route_distance_meters" value="<?= $routeDistanceInitial ?: '' ?>">
                    <input type="hidden" id="route_duration_seconds" name="route_duration_seconds" value="<?= $routeDurationInitial ?: '' ?>">
                    <input type="hidden" id="route_provider" name="route_provider" value="<?= h($routeProviderInitial) ?>">

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-signpost-split me-1 text-primary"></i>Διαδρομή Δράσης</label>
                        <div id="multiDayTabsWrap" style="<?= $isMultiDayInitial ? '' : 'display:none;' ?>" class="mb-3 p-2 border rounded bg-light">
                            <ul class="nav nav-pills mb-2" id="missionDayTabs"></ul>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">Τίτλος ημέρας (προαιρετικό)</label>
                                    <input type="text" class="form-control form-control-sm" id="dayTitleInput" maxlength="160" placeholder="π.χ. Ηράκλειο &rarr; Ρέθυμνο">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">Διανυκτέρευση (προαιρετικό)</label>
                                    <input type="text" class="form-control form-control-sm" id="dayOvernightInput" maxlength="500" placeholder="π.χ. Ξενοδοχείο Χ, Ρέθυμνο">
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="routeUndoBtn">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Αναίρεση
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="routeClearBtn">
                                <i class="bi bi-trash me-1"></i>Καθαρισμός
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#routeFullscreenModal">
                                <i class="bi bi-arrows-fullscreen me-1"></i>Μεγάλος χάρτης
                            </button>
                            <span class="badge bg-light text-dark border route-point-count" id="routePointCount">0 σημεία</span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-2 small" id="routeMetricsSummary" style="display:none;"></div>
                        <div id="routeEditorMap"></div>
                        <div class="mt-3" id="routePointList"></div>
                        <input type="hidden" id="mission_days_json" name="mission_days_json" value="">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Έναρξη *</label>
                            <div class="input-group">
                                <input type="hidden" id="start_datetime" name="start_datetime"
                                       value="<?= h($startDate ?: post('start_datetime')) ?>" required>
                                <input type="text" id="start_datetime_display" class="form-control bg-white"
                                       value="<?= h($startDate ?: post('start_datetime')) ?>"
                                       placeholder="ηη/μμ/εεεε ωω:λλ" readonly
                                       style="cursor:pointer;" onclick="openDateModal('start')" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="openDateModal('start')" tabindex="-1">
                                    <i class="bi bi-calendar3"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Λήξη *</label>
                            <div class="input-group">
                                <input type="hidden" id="end_datetime" name="end_datetime"
                                       value="<?= h($endDate ?: post('end_datetime')) ?>" required>
                                <input type="text" id="end_datetime_display" class="form-control bg-white"
                                       value="<?= h($endDate ?: post('end_datetime')) ?>"
                                       placeholder="ηη/μμ/εεεε ωω:λλ" readonly
                                       style="cursor:pointer;" onclick="openDateModal('end')" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="openDateModal('end')" tabindex="-1">
                                    <i class="bi bi-calendar3"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Επιπλέον Πληροφορίες</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="requirements" class="form-label">Απαιτήσεις</label>
                        <textarea class="form-control" id="requirements" name="requirements" rows="3"
                                  placeholder="π.χ. Απαιτείται κράνος, επικοινωνία, εμπειρία σε ομαδική διαδρομή..."><?= h($mission['requirements'] ?? post('requirements')) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Σημειώσεις</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"><?= h($mission['notes'] ?? post('notes')) ?></textarea>
                    </div>
                </div>
            </div>

        </div>
        
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Κατάσταση</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="status" class="form-label">Κατάσταση</label>
                        <select class="form-select" id="status" name="status">
                            <?php 
                            $allowedStatuses = [STATUS_DRAFT, STATUS_OPEN];
                            if ($isEdit && in_array($mission['status'], [STATUS_CLOSED, STATUS_COMPLETED, STATUS_CANCELED])) {
                                $allowedStatuses[] = $mission['status'];
                            }
                            foreach ($allowedStatuses as $s): ?>
                                <option value="<?= $s ?>" <?= ($mission['status'] ?? post('status', STATUS_DRAFT)) === $s ? 'selected' : '' ?>>
                                    <?= h($GLOBALS['STATUS_LABELS'][$s]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_urgent" name="is_urgent" 
                               <?= ($mission['is_urgent'] ?? post('is_urgent')) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_urgent">
                            <i class="bi bi-exclamation-triangle text-danger"></i> Επείγουσα Δράση
                        </label>
                    </div>
                    
                    <div class="mb-3">
                        <label for="responsible_user_id" class="form-label">Υπεύθυνος Δράσης</label>
                        <select class="form-select" id="responsible_user_id" name="responsible_user_id">
                            <option value="">Χωρίς υπεύθυνο</option>
                            <?php foreach ($allVolunteers as $v): ?>
                                <option value="<?= $v['id'] ?>" <?= ($mission['responsible_user_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
                                    <?= h($v['name']) ?> (<?= h($GLOBALS['ROLE_LABELS'][$v['role']]) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Επιλέξτε υπεύθυνο για τη δράση</small>
                    </div>
                </div>
            </div>

            <?php if (!$isEdit): ?>
            <div class="card mb-4 border-info" id="recurringCard">
                <div class="card-header bg-info bg-opacity-10">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="is_recurring" name="is_recurring"
                               onchange="toggleRecurring(this.checked)">
                        <label class="form-check-label fw-semibold" for="is_recurring">
                            <i class="bi bi-arrow-repeat me-1 text-info"></i>Επαναλαμβανόμενη Δράση
                        </label>
                        <small class="text-muted d-block ms-4">Δημιουργεί αυτόματα πολλές δράσεις με βάση χρονοδιάγραμμα</small>
                    </div>
                </div>
                <div class="card-body" id="recurringBody" style="display:none;">

                    <!-- Type selector -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Τύπος Επανάληψης</label>
                        <div class="d-flex flex-wrap gap-2">
                            <div class="form-check form-check-inline border rounded px-3 py-2">
                                <input class="form-check-input" type="radio" name="recur_type" id="recurTypeWeekly"
                                       value="weekly" checked onchange="switchRecurType('weekly')">
                                <label class="form-check-label" for="recurTypeWeekly">
                                    <i class="bi bi-calendar-week me-1"></i>Εβδομαδιαία
                                </label>
                            </div>
                            <div class="form-check form-check-inline border rounded px-3 py-2">
                                <input class="form-check-input" type="radio" name="recur_type" id="recurTypeRandom"
                                       value="random_days" onchange="switchRecurType('random_days')">
                                <label class="form-check-label" for="recurTypeRandom">
                                    <i class="bi bi-calendar3 me-1"></i>Επιλεγμένες ημερομηνίες
                                </label>
                            </div>
                            <div class="form-check form-check-inline border rounded px-3 py-2">
                                <input class="form-check-input" type="radio" name="recur_type" id="recurTypeInterval"
                                       value="interval" onchange="switchRecurType('interval')">
                                <label class="form-check-label" for="recurTypeInterval">
                                    <i class="bi bi-arrow-right-circle me-1"></i>Κάθε N ημέρες
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Weekly panel -->
                    <div id="recurPanelWeekly" class="mb-3">
                        <label class="form-label fw-semibold">Ημέρες Εβδομάδας</label>
                        <div class="d-flex flex-wrap gap-2" id="weekdayChips">
                            <?php
                            $greekDays = [1=>'Δευτ',2=>'Τρίτ',3=>'Τετ',4=>'Πέμπ',5=>'Παρ',6=>'Σάββ',7=>'Κυρ'];
                            foreach ($greekDays as $iso => $label): ?>
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-3 wday-chip"
                                    data-day="<?= $iso ?>" onclick="toggleWeekday(this)">
                                <?= $label ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div id="weekdayHiddenInputs"></div>
                    </div>

                    <!-- Random days panel (calendar) -->
                    <div id="recurPanelRandom" class="mb-3" style="display:none;">
                        <label class="form-label fw-semibold">Επιλέξτε Ημερομηνίες</label>
                        <input type="hidden" id="recur_random_dates" name="recur_random_dates">
                        <div id="recurCalContainer"></div>
                    </div>

                    <!-- Interval panel -->
                    <div id="recurPanelInterval" class="mb-3" style="display:none;">
                        <label class="form-label fw-semibold">Επανάληψη κάθε</label>
                        <div class="d-flex align-items-center gap-2" style="max-width:260px;">
                            <select class="form-select" name="recur_interval_days" id="recurIntervalDays">
                                <option value="1">1 ημέρα (καθημερινά)</option>
                                <option value="2">2 ημέρες</option>
                                <option value="3">3 ημέρες</option>
                                <option value="4">4 ημέρες</option>
                                <option value="5">5 ημέρες</option>
                                <option value="6">6 ημέρες</option>
                            </select>
                        </div>
                    </div>

                    <!-- Series end date -->
                    <div class="mb-2">
                        <label for="recur_end_date_display" class="form-label fw-semibold">
                            <i class="bi bi-calendar-x me-1 text-danger"></i>Τέλος σειράς
                        </label>
                        <input type="hidden" id="recur_end_date" name="recur_end_date">
                        <div class="input-group" style="max-width:220px;">
                            <input type="text" id="recur_end_date_display" class="form-control bg-white"
                                   placeholder="ηη/μμ/εεεε" readonly style="cursor:pointer;"
                                   onclick="openRecurEndDateModal()">
                            <button type="button" class="btn btn-outline-secondary" onclick="openRecurEndDateModal()" tabindex="-1">
                                <i class="bi bi-calendar3"></i>
                            </button>
                        </div>
                        <small class="text-muted">Τελευταία ημερομηνία για δημιουργία δράσεων</small>
                    </div>

                    <div class="alert alert-info py-2 mb-0 mt-3" id="recurPreviewAlert" style="display:none;">
                        <i class="bi bi-info-circle me-1"></i>
                        <span id="recurPreviewText"></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-lg me-1"></i>
                    <?= $isEdit ? 'Αποθήκευση Αλλαγών' : 'Δημιουργία Δράσης' ?>
                </button>
                <a href="missions.php" class="btn btn-outline-secondary">Ακύρωση</a>
            </div>
        </div>
    </div>
</form>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- Google Maps Link Parser + Route Editor -->
<script>
(function() {
    var routeMap = null;
    var locationMarker = null;
    var mapInstances = []; // each: { map: L.Map, markers: L.CircleMarker[], line: L.Polyline|null }
    var routePoints = <?= json_encode($routePointsInitial, JSON_UNESCAPED_UNICODE) ?>;
    var missionDaysStore = <?= json_encode($missionDaysInitial, JSON_UNESCAPED_UNICODE) ?>;
    var activeDayNumber = null; // null = single-day mode (no mission_days involved)
    var defaultCenter = [35.3387, 25.1442];
    var googleRoutingEnabled = <?= $googleRoutingEnabled ? 'true' : 'false' ?>;
    var routedGeometry = <?= json_encode($routeGeometryInitial) ?>;
    var routedDistanceMeters = <?= $routeDistanceInitial ?>;
    var routedDurationSeconds = <?= $routeDurationInitial ?>;
    var routedProvider = <?= json_encode($routeProviderInitial) ?>;
    var routeFetchTimer = null;
    var routeFetchPending = false;
    var lastRoutedSignature = routedProvider === 'google' ? routeSignature() : null;

    function routeSignature() {
        return JSON.stringify(routePoints.map(function(point) { return [point.lat, point.lng]; }));
    }

    function clearRoutedData() {
        routedGeometry = [];
        routedDistanceMeters = 0;
        routedDurationSeconds = 0;
        routedProvider = '';
        document.getElementById('route_geometry').value = '';
        document.getElementById('route_distance_meters').value = '';
        document.getElementById('route_duration_seconds').value = '';
        document.getElementById('route_provider').value = '';
    }

    function parseDmyDateOnly(fieldId) {
        var val = document.getElementById(fieldId).value; // dd/mm/yyyy hh:ii
        var m = val.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
        if (!m) return null;
        return new Date(parseInt(m[3], 10), parseInt(m[2], 10) - 1, parseInt(m[1], 10));
    }

    function computeDaySpan() {
        var start = parseDmyDateOnly('start_datetime');
        var end = parseDmyDateOnly('end_datetime');
        if (!start || !end || end < start) return [];

        var pad = function(n) { return String(n).padStart(2, '0'); };
        var days = [];
        var cursor = new Date(start);
        var dayNumber = 1;
        while (cursor <= end && dayNumber <= 60) {
            days.push({
                day_number: dayNumber,
                day_date: cursor.getFullYear() + '-' + pad(cursor.getMonth() + 1) + '-' + pad(cursor.getDate())
            });
            cursor.setDate(cursor.getDate() + 1);
            dayNumber++;
        }
        return days;
    }

    function emptyDayEntry(dayNumber, dayDate) {
        return {
            day_number: dayNumber,
            day_date: dayDate,
            title: '',
            overnight_notes: '',
            route_points: [],
            route_geometry: [],
            route_distance_meters: 0,
            route_duration_seconds: 0,
            route_provider: ''
        };
    }

    function findDayEntry(dayNumber) {
        for (var i = 0; i < missionDaysStore.length; i++) {
            if (missionDaysStore[i].day_number === dayNumber) return missionDaysStore[i];
        }
        return null;
    }

    function flushActiveDayIntoStore() {
        if (activeDayNumber === null) return;
        var entry = findDayEntry(activeDayNumber);
        if (!entry) return;
        entry.title = document.getElementById('dayTitleInput').value;
        entry.overnight_notes = document.getElementById('dayOvernightInput').value;
        entry.route_points = routePoints;
        entry.route_geometry = routedGeometry;
        entry.route_distance_meters = routedDistanceMeters;
        entry.route_duration_seconds = routedDurationSeconds;
        entry.route_provider = routedProvider;
    }

    function switchToDay(dayNumber) {
        flushActiveDayIntoStore();
        activeDayNumber = dayNumber;
        var entry = findDayEntry(dayNumber);
        if (!entry) return;

        routePoints = entry.route_points || [];
        routedGeometry = entry.route_geometry || [];
        routedDistanceMeters = entry.route_distance_meters || 0;
        routedDurationSeconds = entry.route_duration_seconds || 0;
        routedProvider = entry.route_provider || '';
        lastRoutedSignature = routedProvider === 'google' ? routeSignature() : null;

        document.getElementById('route_geometry').value = routedGeometry.length ? JSON.stringify(routedGeometry) : '';
        document.getElementById('route_distance_meters').value = routedDistanceMeters || '';
        document.getElementById('route_duration_seconds').value = routedDurationSeconds || '';
        document.getElementById('route_provider').value = routedProvider || '';
        document.getElementById('dayTitleInput').value = entry.title || '';
        document.getElementById('dayOvernightInput').value = entry.overnight_notes || '';

        renderDayTabs();
        renderRoute(true);
    }

    function renderDayTabs() {
        var tabs = document.getElementById('missionDayTabs');
        tabs.innerHTML = '';
        missionDaysStore.forEach(function(entry) {
            var li = document.createElement('li');
            li.className = 'nav-item';
            var a = document.createElement('a');
            a.href = '#';
            a.className = 'nav-link py-1 px-2' + (entry.day_number === activeDayNumber ? ' active' : '');
            var dateParts = entry.day_date.split('-');
            a.textContent = 'Μέρα ' + entry.day_number + ' · ' + dateParts[2] + '/' + dateParts[1];
            a.addEventListener('click', function(e) {
                e.preventDefault();
                if (entry.day_number !== activeDayNumber) switchToDay(entry.day_number);
            });
            li.appendChild(a);
            tabs.appendChild(li);
        });
    }

    function updateMultiDayUI() {
        var span = computeDaySpan();
        var wrap = document.getElementById('multiDayTabsWrap');

        if (span.length < 2) {
            activeDayNumber = null;
            missionDaysStore = [];
            wrap.style.display = 'none';
            return;
        }

        wrap.style.display = '';
        missionDaysStore = span.map(function(day) {
            var existing = findDayEntry(day.day_number);
            if (existing) {
                existing.day_date = day.day_date;
                return existing;
            }
            return emptyDayEntry(day.day_number, day.day_date);
        });

        if (activeDayNumber === null || !findDayEntry(activeDayNumber)) {
            switchToDay(missionDaysStore[0].day_number);
        } else {
            renderDayTabs();
        }
    }

    window.updateMultiDayUI = updateMultiDayUI;

    function scheduleRouteFetch() {
        if (routeFetchTimer) {
            clearTimeout(routeFetchTimer);
            routeFetchTimer = null;
        }
        if (!googleRoutingEnabled || routePoints.length < 2) return;
        routeFetchTimer = setTimeout(fetchSnappedRoute, 900);
    }

    function fetchSnappedRoute() {
        routeFetchTimer = null;
        if (routePoints.length < 2) return;
        var requestSignature = routeSignature();
        routeFetchPending = true;
        updateRouteMetricsSummary();
        fetch('api-route-directions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ points: routePoints.map(function(point) { return { lat: point.lat, lng: point.lng }; }) })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            routeFetchPending = false;
            // Route changed while the request was in flight — result is stale
            if (requestSignature !== routeSignature()) return;
            if (data && data.ok && data.provider === 'google' && Array.isArray(data.geometry) && data.geometry.length >= 2) {
                routedGeometry = data.geometry;
                routedDistanceMeters = parseInt(data.distance_meters, 10) || 0;
                routedDurationSeconds = parseInt(data.duration_seconds, 10) || 0;
                routedProvider = 'google';
                lastRoutedSignature = requestSignature;
                document.getElementById('route_geometry').value = JSON.stringify(routedGeometry);
                document.getElementById('route_distance_meters').value = routedDistanceMeters;
                document.getElementById('route_duration_seconds').value = routedDurationSeconds;
                document.getElementById('route_provider').value = 'google';
            } else {
                clearRoutedData();
            }
            renderRoute(false, true);
        })
        .catch(function() {
            routeFetchPending = false;
            clearRoutedData();
            renderRoute(false, true);
        });
    }

    function extractLatLng(url) {
        if (!url) return null;

        // Pattern 1 (HIGHEST PRIORITY): !3d<lat>!4d<lng> — exact pin coordinates in place URLs
        // e.g. /maps/place/Name/@35.33,25.13,13z/data=...!3d35.3387!4d25.1442
        // This MUST be checked before @ because @ is the map VIEW center, not the pin.
        var m = url.match(/!3d(-?\d+\.?\d*)!4d(-?\d+\.?\d*)/);
        if (m) return { lat: m[1], lng: m[2] };

        // Pattern 2: ?q=lat,lng or &q=lat,lng  e.g. ?q=37.9716,23.7257
        m = url.match(/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/);
        if (m) return { lat: m[1], lng: m[2] };

        // Pattern 3: ll=lat,lng parameter
        m = url.match(/[?&]ll=(-?\d+\.\d+),(-?\d+\.\d+)/);
        if (m) return { lat: m[1], lng: m[2] };

        // Pattern 4 (LOWER PRIORITY): @lat,lng in path — map view center, used only as fallback
        // e.g. /@37.9716,23.7257,15z
        m = url.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
        if (m) return { lat: m[1], lng: m[2] };

        return null;
    }

    function applyCoords(coords, source) {
        document.getElementById('latitude').value  = coords.lat;
        document.getElementById('longitude').value = coords.lng;
        setLocationMarker(parseFloat(coords.lat), parseFloat(coords.lng), routePoints.length === 0);
        var status = document.getElementById('mapsParseStatus');
        status.innerHTML = '<small class="text-success"><i class="bi bi-check-circle me-1"></i>' +
            'Η τοποθεσία εντοπίστηκε από το Google Maps.</small>';
    }

    function getSavedLocation() {
        var lat = parseFloat(document.getElementById('latitude').value);
        var lng = parseFloat(document.getElementById('longitude').value);
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            return [lat, lng];
        }
        return null;
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[ch];
        });
    }

    function routeDistanceMeters() {
        var total = 0;
        for (var i = 0; i < routePoints.length - 1; i++) {
            total += haversineMeters(routePoints[i], routePoints[i + 1]);
        }
        return total;
    }

    function haversineMeters(a, b) {
        var radius = 6371000;
        var lat1 = Number(a.lat) * Math.PI / 180;
        var lat2 = Number(b.lat) * Math.PI / 180;
        var dLat = (Number(b.lat) - Number(a.lat)) * Math.PI / 180;
        var dLng = (Number(b.lng) - Number(a.lng)) * Math.PI / 180;
        var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return radius * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    function formatRouteDistance(meters) {
        return meters >= 1000 ? (meters / 1000).toLocaleString('el-GR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' km' : Math.round(meters) + ' m';
    }

    function formatRouteMinutes(minutes) {
        minutes = Math.max(0, Math.round(minutes));
        if (minutes < 60) return minutes + "'";
        var hours = Math.floor(minutes / 60);
        var rest = minutes % 60;
        return rest ? hours + 'ω ' + rest + "'" : hours + 'ω';
    }

    function updateRouteMetricsSummary() {
        var el = document.getElementById('routeMetricsSummary');
        if (!el) return;
        if (routePoints.length < 2) {
            el.style.display = 'none';
            el.innerHTML = '';
            return;
        }
        var usingGoogle = routedProvider === 'google' && routedDistanceMeters > 0 && routedDurationSeconds > 0;
        var meters = usingGoogle ? routedDistanceMeters : routeDistanceMeters();
        var movingMinutes = usingGoogle ? Math.max(1, Math.ceil(routedDurationSeconds / 60)) : Math.max(1, Math.ceil((meters / 1000) / 55 * 60));
        var stopMinutes = routePoints.reduce(function(total, point) {
            return total + Math.max(0, parseInt(point.stop_minutes || '0', 10) || 0);
        }, 0);
        var sourceNote;
        if (usingGoogle) {
            sourceNote = '<span class="text-success align-self-center"><i class="bi bi-google me-1"></i>Διαδρομή μέσω Google.</span>';
        } else if (routeFetchPending) {
            sourceNote = '<span class="text-muted align-self-center"><span class="spinner-border spinner-border-sm me-1"></span>Υπολογισμός διαδρομής Google...</span>';
        } else if (googleRoutingEnabled) {
            sourceNote = '<span class="text-muted align-self-center">Εκτίμηση σε ευθεία γραμμή.</span>';
        } else {
            sourceNote = '<span class="text-muted align-self-center">Εκτίμηση — προσθέστε Google Maps API key στις Ρυθμίσεις για routing δρόμων.</span>';
        }
        el.innerHTML =
            '<span class="badge bg-light text-dark border"><i class="bi bi-signpost-2 me-1"></i>' + formatRouteDistance(meters) + '</span>' +
            '<span class="badge bg-light text-dark border"><i class="bi bi-stopwatch me-1"></i>Οδήγηση ' + formatRouteMinutes(movingMinutes) + '</span>' +
            '<span class="badge bg-warning text-dark"><i class="bi bi-cup-hot me-1"></i>Στάσεις ' + formatRouteMinutes(stopMinutes) + '</span>' +
            '<span class="badge bg-primary"><i class="bi bi-clock-history me-1"></i>Σύνολο ' + formatRouteMinutes(movingMinutes + stopMinutes) + '</span>' +
            sourceNote;
        el.style.display = '';
    }

    function syncRouteInput() {
        document.getElementById('route_points').value = routePoints.length ? JSON.stringify(routePoints) : '';
        var countLabel = routePoints.length + (routePoints.length === 1 ? ' σημείο' : ' σημεία');
        document.getElementById('routePointCount').textContent = countLabel;
        var fullscreenCount = document.getElementById('routeFullscreenPointCount');
        if (fullscreenCount) fullscreenCount.textContent = countLabel;
        if (routeSignature() !== lastRoutedSignature) {
            clearRoutedData();
            lastRoutedSignature = null;
            scheduleRouteFetch();
        }
        updateRouteMetricsSummary();
    }

    function updateRoutePoint(index, field, value) {
        if (!routePoints[index]) return;
        if (field === 'stop_minutes') {
            value = Math.max(0, Math.min(1440, parseInt(value || '0', 10) || 0));
        }
        routePoints[index][field] = value;
        syncRouteInput();
        renderRoute(false, true);
    }

    function renderRoutePointList() {
        var list = document.getElementById('routePointList');
        if (!list) return;

        list.innerHTML = '';
        if (!routePoints.length) {
            list.innerHTML = '<div class="text-muted small border rounded p-2 bg-light">Δεν έχουν οριστεί σημεία διαδρομής.</div>';
            return;
        }

        routePoints.forEach(function(point, index) {
            var row = document.createElement('div');
            row.className = 'route-point-row';
            row.innerHTML =
                '<div class="d-flex align-items-center gap-2 mb-2">' +
                    '<span class="route-point-index bg-primary text-white fw-bold">' + (index + 1) + '</span>' +
                    '<input type="text" class="form-control form-control-sm route-title" maxlength="120" placeholder="π.χ. Εκκίνηση ή Καφετέρια Ανατολή">' +
                    '<button type="button" class="btn btn-sm btn-outline-danger route-remove" title="Αφαίρεση σημείου"><i class="bi bi-x-lg"></i></button>' +
                '</div>' +
                '<div class="row g-2">' +
                    '<div class="col-sm-4">' +
                        '<label class="form-label small text-muted mb-1">Ώρα</label>' +
                        '<input type="time" class="form-control form-control-sm route-time">' +
                    '</div>' +
                    '<div class="col-sm-4">' +
                        '<label class="form-label small text-muted mb-1">Στάση λεπτά</label>' +
                        '<input type="number" class="form-control form-control-sm route-stop" min="0" max="1440" step="5" placeholder="0">' +
                    '</div>' +
                    '<div class="col-sm-4">' +
                        '<label class="form-label small text-muted mb-1">Συντεταγμένες</label>' +
                        '<input type="text" class="form-control form-control-sm" readonly value="' + point.lat.toFixed(5) + ', ' + point.lng.toFixed(5) + '">' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label small text-muted mb-1">Σημειώσεις</label>' +
                        '<textarea class="form-control form-control-sm route-notes" rows="2" maxlength="500" placeholder="π.χ. στάση 45 λεπτά για καφέ"></textarea>' +
                    '</div>' +
                '</div>';

            var titleInput = row.querySelector('.route-title');
            var timeInput = row.querySelector('.route-time');
            var stopInput = row.querySelector('.route-stop');
            var notesInput = row.querySelector('.route-notes');

            titleInput.value = point.title || '';
            timeInput.value = point.scheduled_time || '';
            stopInput.value = point.stop_minutes || '';
            notesInput.value = point.notes || '';

            titleInput.addEventListener('input', function() { updateRoutePoint(index, 'title', titleInput.value); });
            timeInput.addEventListener('input', function() { updateRoutePoint(index, 'scheduled_time', timeInput.value); });
            stopInput.addEventListener('input', function() { updateRoutePoint(index, 'stop_minutes', stopInput.value); });
            notesInput.addEventListener('input', function() { updateRoutePoint(index, 'notes', notesInput.value); });
            row.querySelector('.route-remove').addEventListener('click', function() {
                routePoints.splice(index, 1);
                renderRoute(false);
            });

            list.appendChild(row);
        });
    }

    function setLocationMarker(lat, lng, recenter) {
        if (!routeMap || !Number.isFinite(lat) || !Number.isFinite(lng)) return;

        if (!locationMarker) {
            locationMarker = L.marker([lat, lng], {
                opacity: 0.75,
                title: 'Τοποθεσία'
            }).addTo(routeMap);
        } else {
            locationMarker.setLatLng([lat, lng]);
        }

        if (recenter) {
            routeMap.setView([lat, lng], 14);
        }
    }

    function buildRoutePointPopupHtml(index) {
        var point = routePoints[index];
        var titleLine = point.title ? '<br>' + escapeHtml(point.title) : '';
        return '<div class="route-point-popup">' +
            '<strong>Σημείο ' + (index + 1) + '</strong>' + titleLine +
            '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-danger route-point-delete-btn">' +
            '<i class="bi bi-trash me-1"></i>Διαγραφή σημείου</button></div>' +
            '</div>';
    }

    function drawPointsOnMapInstance(instance) {
        instance.markers.forEach(function(marker) { marker.remove(); });
        instance.markers = [];
        if (instance.line) {
            instance.line.remove();
            instance.line = null;
        }

        var latLngs = routePoints.map(function(point) { return [point.lat, point.lng]; });
        latLngs.forEach(function(latLng, index) {
            var marker = L.circleMarker(latLng, {
                radius: 6,
                color: '#0d6efd',
                fillColor: '#0d6efd',
                fillOpacity: 0.9,
                weight: 2,
                bubblingMouseEvents: false
            }).addTo(instance.map).bindTooltip(String(index + 1), {
                permanent: true,
                direction: 'center',
                className: 'route-point-label'
            }).bindPopup(buildRoutePointPopupHtml(index));

            marker.on('popupopen', function(e) {
                var popupEl = e.popup.getElement();
                var deleteBtn = popupEl ? popupEl.querySelector('.route-point-delete-btn') : null;
                if (deleteBtn) {
                    L.DomEvent.disableClickPropagation(deleteBtn);
                    deleteBtn.addEventListener('click', function(evt) {
                        evt.preventDefault();
                        evt.stopPropagation();
                        // Defer past Leaflet's current click gesture: destroying/recreating this
                        // marker synchronously here races with Leaflet's own click-vs-drag
                        // detection and causes the map's add-point click to fire too.
                        setTimeout(function() {
                            routePoints.splice(index, 1);
                            renderRoute(false);
                        }, 0);
                    });
                }
            });

            instance.markers.push(marker);
        });

        if (latLngs.length >= 2) {
            var lineLatLngs = (routedProvider === 'google' && routedGeometry.length >= 2) ? routedGeometry : latLngs;
            instance.line = L.polyline(lineLatLngs, {
                color: '#0d6efd',
                weight: 5,
                opacity: 0.85
            }).addTo(instance.map);
        }
    }

    function renderFullscreenPointPanel() {
        var panel = document.getElementById('routeFullscreenPointPanel');
        if (!panel) return;

        panel.innerHTML = '';
        if (!routePoints.length) {
            panel.innerHTML = '<div class="text-muted small p-2">Δεν έχουν οριστεί σημεία.</div>';
            return;
        }

        routePoints.forEach(function(point, index) {
            var row = document.createElement('div');
            row.className = 'route-fullscreen-point-row d-flex align-items-center gap-1 mb-2';
            row.innerHTML =
                '<span class="route-point-index bg-primary text-white fw-bold flex-shrink-0">' + (index + 1) + '</span>' +
                '<input type="text" class="form-control form-control-sm route-fullscreen-title" maxlength="120" placeholder="Τίτλος">' +
                '<button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0 route-fullscreen-remove" title="Αφαίρεση σημείου"><i class="bi bi-x-lg"></i></button>';

            var titleInput = row.querySelector('.route-fullscreen-title');
            titleInput.value = point.title || '';
            titleInput.addEventListener('input', function() {
                updateRoutePoint(index, 'title', titleInput.value);
            });

            row.querySelector('.route-fullscreen-remove').addEventListener('click', function() {
                routePoints.splice(index, 1);
                renderRoute(false);
            });

            panel.appendChild(row);
        });
    }

    function renderRoute(fitBounds, skipListRender) {
        mapInstances.forEach(function(instance) {
            drawPointsOnMapInstance(instance);
            if (fitBounds && routePoints.length > 0) {
                instance.map.fitBounds(
                    L.latLngBounds(routePoints.map(function(point) { return [point.lat, point.lng]; })),
                    { padding: [25, 25] }
                );
            }
        });

        syncRouteInput();
        if (!skipListRender) {
            renderRoutePointList();
            renderFullscreenPointPanel();
        }
    }

    function initRouteEditor() {
        var mapEl = document.getElementById('routeEditorMap');
        if (!mapEl || typeof L === 'undefined') return;

        var savedLocation = getSavedLocation();
        var center = routePoints.length ? [routePoints[0].lat, routePoints[0].lng] : (savedLocation || defaultCenter);
        var zoom = routePoints.length || savedLocation ? 14 : 9;

        routeMap = L.map(mapEl, { scrollWheelZoom: false }).setView(center, zoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19
        }).addTo(routeMap);

        mapInstances.push({ map: routeMap, markers: [], line: null });

        if (savedLocation) {
            setLocationMarker(savedLocation[0], savedLocation[1], false);
        }

        routeMap.on('click', function(e) {
            routePoints.push({
                lat: Math.round(e.latlng.lat * 1000000) / 1000000,
                lng: Math.round(e.latlng.lng * 1000000) / 1000000,
                title: routePoints.length === 0 ? 'Εκκίνηση' : '',
                scheduled_time: '',
                stop_minutes: 0,
                notes: ''
            });
            renderRoute(false);
        });

        document.getElementById('routeUndoBtn').addEventListener('click', function() {
            routePoints.pop();
            renderRoute(false);
        });
        document.getElementById('routeClearBtn').addEventListener('click', function() {
            routePoints = [];
            renderRoute(false);
        });

        renderRoute(routePoints.length > 0);
        setTimeout(function() { routeMap.invalidateSize(); }, 150);
        updateMultiDayUI();
    }

    var fullscreenMapInstance = null;

    function openFullscreenRouteMap() {
        if (!fullscreenMapInstance) {
            var mapEl = document.getElementById('routeFullscreenMap');
            if (!mapEl || typeof L === 'undefined') return;

            var savedLocation = getSavedLocation();
            var center = routePoints.length ? [routePoints[0].lat, routePoints[0].lng] : (savedLocation || defaultCenter);
            var zoom = routePoints.length || savedLocation ? 14 : 9;

            var map = L.map(mapEl, { scrollWheelZoom: true }).setView(center, zoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19
            }).addTo(map);

            map.on('click', function(e) {
                routePoints.push({
                    lat: Math.round(e.latlng.lat * 1000000) / 1000000,
                    lng: Math.round(e.latlng.lng * 1000000) / 1000000,
                    title: routePoints.length === 0 ? 'Εκκίνηση' : '',
                    scheduled_time: '',
                    stop_minutes: 0,
                    notes: ''
                });
                renderRoute(false);
            });

            fullscreenMapInstance = { map: map, markers: [], line: null };
            mapInstances.push(fullscreenMapInstance);
        }

        drawPointsOnMapInstance(fullscreenMapInstance);
        renderFullscreenPointPanel();
        if (routePoints.length > 0) {
            fullscreenMapInstance.map.fitBounds(
                L.latLngBounds(routePoints.map(function(point) { return [point.lat, point.lng]; })),
                { padding: [25, 25] }
            );
        }
        setTimeout(function() { fullscreenMapInstance.map.invalidateSize(); }, 150);
    }

    function closeFullscreenRouteMap() {
        if (!fullscreenMapInstance) return;
        var idx = mapInstances.indexOf(fullscreenMapInstance);
        if (idx !== -1) mapInstances.splice(idx, 1);
        fullscreenMapInstance.map.remove();
        fullscreenMapInstance = null;
    }

    function initFullscreenRouteEditor() {
        var modalEl = document.getElementById('routeFullscreenModal');
        if (!modalEl) return;

        modalEl.addEventListener('shown.bs.modal', openFullscreenRouteMap);
        modalEl.addEventListener('hidden.bs.modal', closeFullscreenRouteMap);

        document.getElementById('routeFullscreenUndoBtn').addEventListener('click', function() {
            routePoints.pop();
            renderRoute(false);
        });
        document.getElementById('routeFullscreenClearBtn').addEventListener('click', function() {
            routePoints = [];
            renderRoute(false);
        });
    }

    function handleMapsInput(url) {
        url = url.trim();
        if (!url) return;

        var coords = extractLatLng(url);
        if (coords) {
            applyCoords(coords, 'direct');
            return;
        }

        // Short link (maps.app.goo.gl, goo.gl, etc.) — resolve server-side
        if (/goo\.gl|maps\.app\.goo\.gl/.test(url)) {
            var status = document.getElementById('mapsParseStatus');
            status.innerHTML = '<small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Επεξεργασία συνδέσμου...</small>';
            fetch('api-resolve-maps.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: 'url=' + encodeURIComponent(url)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.lat && data.lng) {
                    applyCoords({ lat: data.lat, lng: data.lng }, 'resolved');
                } else {
                    status.innerHTML = '<small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>' +
                        (data.error || 'Δεν βρέθηκαν συντεταγμένα στον σύνδεσμο.') + '</small>';
                }
            })
            .catch(function() {
                status.innerHTML = '<small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Αποτυχία επεξεργασίας συνδέσμου.</small>';
            });
            return;
        }

        document.getElementById('mapsParseStatus').innerHTML =
            '<small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>' +
            'Δεν βρέθηκαν συντεταγμένα. Χρησιμοποιήστε τον κανονικό σύνδεσμο από Google Maps (Κοινή χρήση &rarr; Αντιγραφή συνδέσμου).</small>';
    }

    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('maps_link');
        var btn   = document.getElementById('parseMapsBtn');
        initRouteEditor();
        initFullscreenRouteEditor();

        document.getElementById('missionForm').addEventListener('submit', function() {
            flushActiveDayIntoStore();
            var jsonField = document.getElementById('mission_days_json');
            if (missionDaysStore.length >= 2) {
                jsonField.value = JSON.stringify(missionDaysStore);
                document.getElementById('route_points').value = '';
                document.getElementById('route_geometry').value = '';
                document.getElementById('route_distance_meters').value = '';
                document.getElementById('route_duration_seconds').value = '';
                document.getElementById('route_provider').value = '';
            } else {
                jsonField.value = '';
            }
        });

        // Auto-parse on paste
        input.addEventListener('paste', function(e) {
            setTimeout(function() { handleMapsInput(input.value); }, 50);
        });

        // Parse on button click
        btn.addEventListener('click', function() {
            handleMapsInput(input.value);
        });

        // If editing & lat/lng already set, show confirmation
        <?php if (!empty($mission['latitude']) && !empty($mission['longitude'])): ?>
        document.getElementById('mapsParseStatus').innerHTML =
            '<small class="text-success"><i class="bi bi-check-circle me-1"></i>' +
            'Η τοποθεσία έχει εντοπιστεί από το Google Maps.</small>';
        <?php endif; ?>
    });
}());
</script>

<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/lang/summernote-el-GR.min.js"></script>

<!-- Fullscreen Route Point Editor -->
<div class="modal fade" id="routeFullscreenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="routeFullscreenUndoBtn">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Αναίρεση
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="routeFullscreenClearBtn">
                        <i class="bi bi-trash me-1"></i>Καθαρισμός
                    </button>
                    <span class="badge bg-light text-dark border route-point-count" id="routeFullscreenPointCount">0 σημεία</span>
                </div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="route-fullscreen-layout">
                    <div id="routeFullscreenMap" class="route-fullscreen-map"></div>
                    <div id="routeFullscreenPointPanel" class="route-fullscreen-panel"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Date/Time Picker Modal -->
<div class="modal fade" id="datePickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
        <div class="modal-content border-0 shadow-lg overflow-hidden">
            <!-- Gradient header -->
            <div class="modal-header border-0 text-white py-4 px-4"
                 style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width:42px;height:42px;background:rgba(255,255,255,0.2);">
                        <i class="bi bi-calendar3 fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-0 fw-bold" id="datePickerModalLabel">Επιλογή Ημερομηνίας &amp; Ώρας</h5>
                        <small class="opacity-75" id="datePickerModalSub">Κάντε κλικ για να επιλέξετε</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <!-- Body -->
            <div class="modal-body px-4 py-4" style="background:#f8f7ff;">
                <div class="row g-3">
                    <div class="col-7">
                        <label class="form-label text-muted small fw-semibold text-uppercase mb-2">
                            <i class="bi bi-calendar3 me-1"></i>Ημερομηνία
                        </label>
                        <input type="date" id="modalDatePart"
                               class="form-control form-control-lg text-center fw-semibold"
                               style="font-size:1.1rem; border:2px solid #e0ddff; border-radius:12px;
                                      background:#fff; color:#4f46e5; padding:14px 10px;
                                      box-shadow:0 2px 8px rgba(79,70,229,0.08); transition:border-color .2s,box-shadow .2s;"
                               onfocus="this.style.borderColor='#4f46e5';this.style.boxShadow='0 0 0 4px rgba(79,70,229,0.15)'"
                               onblur="this.style.borderColor='#e0ddff';this.style.boxShadow='0 2px 8px rgba(79,70,229,0.08)'">
                    </div>
                    <div class="col-5" id="modalTimeCol">
                        <label class="form-label text-muted small fw-semibold text-uppercase mb-2">
                            <i class="bi bi-clock me-1"></i>Ώρα
                        </label>
                        <input type="time" id="modalTimePart"
                               class="form-control form-control-lg text-center fw-semibold"
                               step="900"
                               style="font-size:1.1rem; border:2px solid #e0ddff; border-radius:12px;
                                      background:#fff; color:#4f46e5; padding:14px 10px;
                                      box-shadow:0 2px 8px rgba(79,70,229,0.08); transition:border-color .2s,box-shadow .2s;"
                               onfocus="this.style.borderColor='#4f46e5';this.style.boxShadow='0 0 0 4px rgba(79,70,229,0.15)'"
                               onblur="this.style.borderColor='#e0ddff';this.style.boxShadow='0 2px 8px rgba(79,70,229,0.08)'">
                    </div>
                </div>

                <!-- Duration section — shown only for Έναρξη -->
                <div id="durationSection" style="display:none;">
                    <hr class="my-3" style="border-color:#e0ddff;">
                    <label class="form-label text-muted small fw-semibold text-uppercase mb-2">
                        <i class="bi bi-hourglass-split me-1"></i>Διάρκεια δράσης
                        <span class="text-muted fw-normal text-lowercase">(προαιρετικά — συμπληρώνει αυτόματα τη Λήξη)</span>
                    </label>
                    <!-- Quick-select pills -->
                    <div class="d-flex flex-wrap gap-2 mb-3" id="durationPills">
                        <?php foreach ([1,2,3,4,6,8,12,24] as $h): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 dp-pill"
                                data-hours="<?= $h ?>">
                            <?= $h ?>ώρ
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <!-- Custom hours input -->
                    <div class="input-group input-group-sm" style="max-width:180px;">
                        <span class="input-group-text bg-white" style="border:2px solid #e0ddff; border-right:0; border-radius:10px 0 0 10px;">
                            <i class="bi bi-pencil text-muted"></i>
                        </span>
                        <input type="number" id="durationCustom" min="0.5" max="72" step="0.5"
                               class="form-control text-center fw-semibold"
                               style="border:2px solid #e0ddff; border-left:0; border-radius:0 10px 10px 0; color:#4f46e5;"
                               placeholder="π.χ. 4.5">
                        <span class="input-group-text bg-white ms-1 border-0 text-muted small">ώρες</span>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none p-0" id="clearDateBtn">
                        <i class="bi bi-x-circle me-1"></i>Καθαρισμός επιλογής
                    </button>
                </div>
            </div>
            <!-- Footer -->
            <div class="modal-footer border-0 px-4 pb-4 pt-0 gap-2" style="background:#f8f7ff;">
                <button type="button" class="btn btn-outline-secondary flex-fill py-2 rounded-3" data-bs-dismiss="modal">
                    Ακύρωση
                </button>
                <button type="button" class="btn flex-fill py-2 rounded-3 fw-semibold text-white" id="confirmDateBtn"
                        style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border:none;">
                    <i class="bi bi-check-lg me-1"></i>Επιλογή
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Pre-fill ISO values from PHP for edit mode
const _isoStart = <?= json_encode($startDateIso) ?>;
const _isoEnd   = <?= json_encode($endDateIso) ?>;
if (_isoStart && !document.getElementById('start_datetime').value) {
    document.getElementById('start_datetime').value = '';
}

window._dpField = null;
window._dpModal = null;
window._dpDateOnly = false;

function openRecurEndDateModal() {
    window._dpDateOnly = true;
    const currentVal = document.getElementById('recur_end_date').value; // Y-m-d
    document.getElementById('modalDatePart').value = currentVal || '';
    document.getElementById('modalTimePart').value = '00:00';
    document.getElementById('datePickerModalLabel').textContent = 'Τέλος Σειράς Δράσεων';
    document.getElementById('datePickerModalSub').textContent = 'Τελευταία ημερομηνία δημιουργίας';
    document.getElementById('durationSection').style.display = 'none';
    document.getElementById('modalTimeCol').style.display = 'none';
    if (!window._dpModal) window._dpModal = new bootstrap.Modal(document.getElementById('datePickerModal'));
    window._dpModal.show();
    document.getElementById('datePickerModal').addEventListener('shown.bs.modal', function focusIt() {
        document.getElementById('modalDatePart').focus();
        document.getElementById('datePickerModal').removeEventListener('shown.bs.modal', focusIt);
    });
}

function openDateModal(field) {
    window._dpDateOnly = false;
    document.getElementById('modalTimeCol').style.display = '';
    window._dpField = field;
    const hiddenId = field + '_datetime';
    const currentVal = document.getElementById(hiddenId).value;
    const isoVal = dmyToIso(currentVal); // yyyy-mm-ddTHH:ii
    document.getElementById('modalDatePart').value = isoVal ? isoVal.slice(0,10) : '';
    document.getElementById('modalTimePart').value = isoVal ? isoVal.slice(11,16) : '';
    document.getElementById('datePickerModalLabel').textContent =
        field === 'start' ? 'Ημερομηνία Έναρξης' : 'Ημερομηνία Λήξης';
    document.getElementById('datePickerModalSub').textContent =
        field === 'start' ? 'Πότε ξεκινά η δράση;' : 'Πότε λήγει η δράση;';
    // Show/hide duration section
    const durSection = document.getElementById('durationSection');
    durSection.style.display = field === 'start' ? '' : 'none';
    // Reset duration selection
    document.getElementById('durationCustom').value = '';
    document.querySelectorAll('.dp-pill').forEach(function(b) {
        b.classList.remove('btn-primary', 'text-white');
        b.classList.add('btn-outline-secondary');
    });
    if (!window._dpModal) window._dpModal = new bootstrap.Modal(document.getElementById('datePickerModal'));
    window._dpModal.show();
    document.getElementById('datePickerModal').addEventListener('shown.bs.modal', function focusIt() {
        document.getElementById('modalDatePart').focus();
        document.getElementById('datePickerModal').removeEventListener('shown.bs.modal', focusIt);
    });
}

// Duration pill click
document.getElementById('durationPills').addEventListener('click', function(e) {
    const btn = e.target.closest('.dp-pill');
    if (!btn) return;
    document.querySelectorAll('.dp-pill').forEach(function(b) {
        b.classList.remove('btn-primary', 'text-white');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-primary', 'text-white');
    document.getElementById('durationCustom').value = btn.dataset.hours;
});

// Custom input clears pill selection
document.getElementById('durationCustom').addEventListener('input', function() {
    document.querySelectorAll('.dp-pill').forEach(function(b) {
        b.classList.remove('btn-primary', 'text-white');
        b.classList.add('btn-outline-secondary');
    });
    // Re-highlight matching pill if value matches
    const v = parseFloat(this.value);
    document.querySelectorAll('.dp-pill').forEach(function(b) {
        if (parseFloat(b.dataset.hours) === v) {
            b.classList.remove('btn-outline-secondary');
            b.classList.add('btn-primary', 'text-white');
        }
    });
});

document.getElementById('confirmDateBtn').addEventListener('click', function() {
    const datePart = document.getElementById('modalDatePart').value; // yyyy-mm-dd
    if (window._dpDateOnly) {
        if (!datePart) return;
        document.getElementById('recur_end_date').value = datePart;
        const p = datePart.split('-');
        document.getElementById('recur_end_date_display').value = p[2] + '/' + p[1] + '/' + p[0];
        window._dpModal.hide();
        updateRecurPreview();
        return;
    }
    const timePart = document.getElementById('modalTimePart').value; // HH:ii
    if (!datePart || !timePart) { return; }
    const isoVal = datePart + 'T' + timePart;
    const dmy = isoToDmy(isoVal);
    document.getElementById(window._dpField + '_datetime').value = dmy;
    document.getElementById(window._dpField + '_datetime_display').value = dmy;
    // Auto-fill end datetime if duration is set and we're setting start
    if (window._dpField === 'start') {
        const hours = parseFloat(document.getElementById('durationCustom').value);
        if (hours > 0) {
            const startMs = new Date(isoVal).getTime();
            const endDate = new Date(startMs + hours * 3600 * 1000);
            const pad = n => String(n).padStart(2, '0');
            const endIso = endDate.getFullYear() + '-' + pad(endDate.getMonth()+1) + '-' + pad(endDate.getDate()) + 'T' + pad(endDate.getHours()) + ':' + pad(endDate.getMinutes());
            const endDmy = isoToDmy(endIso);
            document.getElementById('end_datetime').value = endDmy;
            document.getElementById('end_datetime_display').value = endDmy;
        }
    }
    if (window.updateMultiDayUI) window.updateMultiDayUI();
    window._dpModal.hide();
});

document.getElementById('clearDateBtn').addEventListener('click', function() {
    document.getElementById('modalDatePart').value = '';
    document.getElementById('modalTimePart').value = '';
    if (window._dpDateOnly) {
        document.getElementById('recur_end_date').value = '';
        document.getElementById('recur_end_date_display').value = '';
    } else {
        document.getElementById(window._dpField + '_datetime').value = '';
        document.getElementById(window._dpField + '_datetime_display').value = '';
    }
    window._dpModal.hide();
});

document.getElementById('modalTimePart').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('confirmDateBtn').click(); }
});

// Helpers: convert between d/m/Y H:i and Y-m-dTH:i
function dmyToIso(dmy) {
    if (!dmy) return '';
    // expected: dd/mm/yyyy hh:ii
    const m = dmy.match(/^(\d{2})\/(\d{2})\/(\d{4})\s(\d{2}):(\d{2})/);
    if (!m) return '';
    return m[3] + '-' + m[2] + '-' + m[1] + 'T' + m[4] + ':' + m[5];
}
function isoToDmy(iso) {
    if (!iso) return '';
    // expected: yyyy-mm-ddThh:ii
    const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    if (!m) return '';
    return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
}
</script>

<script>
$(document).ready(function() {
    $('.summernote-basic').summernote({
        height: 200,
        lang: 'el-GR',
        dialogsInBody: true,
        toolbar: [
            ['style', ['bold', 'italic', 'underline']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['insert', ['link']],
            ['view', ['codeview']]
        ],

    });
});
</script>

<style>
/* ── Recurring Calendar ─────────────────────────── */
.recur-cal-wrap { display: flex; flex-wrap: wrap; gap: 20px; }
.recur-cal-month { min-width: 210px; }
.recur-cal-grid  { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.recur-cal-hdr   { text-align:center; font-size:.68rem; font-weight:600; color:#6c757d; padding:3px 1px; }
.recur-cal-day   { text-align:center; padding:5px 2px; font-size:.78rem; border-radius:5px; cursor:pointer; transition:background .15s; }
.recur-cal-day:hover         { background:#dbeafe; }
.recur-cal-day.selected      { background:#4f46e5; color:#fff; font-weight:600; }
.recur-cal-day.disabled      { color:#ccc; cursor:default; pointer-events:none; }
.recur-cal-day.today         { outline:2px solid #4f46e5; outline-offset:-2px; }
.recur-cal-month-header      { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; font-size:.85rem; font-weight:600; }
</style>

<script>
// ══ RECURRING MISSIONS JS ════════════════════════════════════════════════════

function toggleRecurring(on) {
    document.getElementById('recurringBody').style.display = on ? '' : 'none';
    if (on && window._recurCalInstance) window._recurCalInstance.render();
    if (on) updateRecurPreview();
}

function switchRecurType(type) {
    document.getElementById('recurPanelWeekly').style.display   = type === 'weekly'      ? '' : 'none';
    document.getElementById('recurPanelRandom').style.display   = type === 'random_days' ? '' : 'none';
    document.getElementById('recurPanelInterval').style.display = type === 'interval'    ? '' : 'none';
    if (type === 'random_days') {
        if (!window._recurCalInstance) {
            window._recurCalInstance = new RecurCalendar('recurCalContainer', 'recur_random_dates');
        } else {
            window._recurCalInstance.render();
        }
    }
    updateRecurPreview();
}

// ── Weekday chips ───────────────────────────────────────────────────────────
function toggleWeekday(btn) {
    btn.classList.toggle('btn-primary');
    btn.classList.toggle('text-white');
    btn.classList.toggle('btn-outline-secondary');
    rebuildWeekdayInputs();
    updateRecurPreview();
}

function rebuildWeekdayInputs() {
    const wrap = document.getElementById('weekdayHiddenInputs');
    wrap.innerHTML = '';
    document.querySelectorAll('.wday-chip.btn-primary').forEach(function(b) {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'recur_weekdays[]';
        inp.value = b.dataset.day;
        wrap.appendChild(inp);
    });
}

// ── Count preview ────────────────────────────────────────────────────────────
// These elements only exist when creating a new mission (the recurring card is
// wrapped in <?php if (!$isEdit) ?>), so guard against edit-page loads.
const recurEndDateEl = document.getElementById('recur_end_date');
if (recurEndDateEl) recurEndDateEl.addEventListener('change', updateRecurPreview);
const recurIntervalDaysEl = document.getElementById('recurIntervalDays');
if (recurIntervalDaysEl) recurIntervalDaysEl.addEventListener('change', updateRecurPreview);

function getSeriesStartDate() {
    const startVal = document.getElementById('start_datetime').value;
    if (!startVal) return null;
    const m = startVal.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
    if (!m) return null;
    return new Date(parseInt(m[3]), parseInt(m[2])-1, parseInt(m[1]));
}

function updateRecurPreview() {
    const endDateVal = document.getElementById('recur_end_date').value;
    if (!endDateVal) { hidePreview(); return; }
    const endDate = new Date(endDateVal + 'T00:00:00');
    const startDate = getSeriesStartDate();
    if (!startDate || endDate < startDate) { hidePreview(); return; }

    const type = document.querySelector('input[name="recur_type"]:checked')?.value;
    let count = 0;

    if (type === 'weekly') {
        const selectedDays = Array.from(document.querySelectorAll('.wday-chip.btn-primary'))
                                  .map(b => parseInt(b.dataset.day));
        if (!selectedDays.length) { hidePreview(); return; }
        const cursor = new Date(startDate);
        while (cursor <= endDate) {
            const dow = cursor.getDay() === 0 ? 7 : cursor.getDay(); // ISO
            if (selectedDays.includes(dow)) count++;
            cursor.setDate(cursor.getDate() + 1);
            if (count > 100) break;
        }
    } else if (type === 'random_days') {
        const raw = document.getElementById('recur_random_dates').value;
        count = raw ? raw.split(',').filter(Boolean).length : 0;
    } else if (type === 'interval') {
        const n = parseInt(document.getElementById('recurIntervalDays').value) || 1;
        const cursor = new Date(startDate);
        while (cursor <= endDate) {
            count++;
            cursor.setDate(cursor.getDate() + n);
            if (count > 100) break;
        }
    }

    if (count === 0) { hidePreview(); return; }
    const alert = document.getElementById('recurPreviewAlert');
    alert.style.display = '';
    if (count > 100) {
        alert.className = 'alert alert-danger py-2 mb-0 mt-3';
        document.getElementById('recurPreviewText').textContent = 'Υπέρβαση ορίου: > 100 δράσεις. Μειώστε το εύρος.';
    } else {
        alert.className = 'alert alert-info py-2 mb-0 mt-3';
        document.getElementById('recurPreviewText').textContent = 'Θα δημιουργηθούν ' + count + ' δράσεις.';
    }
}

function hidePreview() {
    document.getElementById('recurPreviewAlert').style.display = 'none';
}

// ══ VANILLA JS CALENDAR ══════════════════════════════════════════════════════
class RecurCalendar {
    constructor(containerId, hiddenInputId) {
        this.container = document.getElementById(containerId);
        this.hidden    = document.getElementById(hiddenInputId);
        this.selected  = new Set(this.hidden.value ? this.hidden.value.split(',').filter(Boolean) : []);
        const now = new Date();
        this.year  = now.getFullYear();
        this.month = now.getMonth(); // 0-based
        this.render();
    }

    getStartLimit() {
        const d = getSeriesStartDate();
        return d;
    }

    getEndLimit() {
        const v = document.getElementById('recur_end_date').value;
        return v ? new Date(v + 'T00:00:00') : null;
    }

    renderMonth(year, month) {
        const startLimit = this.getStartLimit();
        const endLimit   = this.getEndLimit();
        const firstDay   = new Date(year, month, 1);
        const lastDay    = new Date(year, month + 1, 0);
        const monthNames = ['Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος',
                            'Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
        const dayNames = ['Δε','Τρ','Τε','Πε','Πα','Σα','Κυ'];
        const today    = new Date(); today.setHours(0,0,0,0);
        const pad = n => String(n).padStart(2,'0');
        const self = this;

        let html = '<div class="recur-cal-month"><div class="recur-cal-month-header">';
        html += '<strong>' + monthNames[month] + ' ' + year + '</strong></div>';
        html += '<div class="recur-cal-grid">';
        dayNames.forEach(function(d) { html += '<div class="recur-cal-hdr">' + d + '</div>'; });

        // Leading blanks (Mon=0)
        let startDow = firstDay.getDay();
        startDow = startDow === 0 ? 6 : startDow - 1;
        for (let i = 0; i < startDow; i++) html += '<div></div>';

        for (let d = 1; d <= lastDay.getDate(); d++) {
            const dt  = new Date(year, month, d);
            const key = year + '-' + pad(month+1) + '-' + pad(d);
            const isToday    = dt.getTime() === today.getTime();
            const isPast     = (startLimit && dt < startLimit) || (endLimit && dt > endLimit);
            const isSelected = self.selected.has(key);
            let cls = 'recur-cal-day';
            if (isPast) cls += ' disabled';
            else if (isSelected) cls += ' selected';
            if (isToday) cls += ' today';
            const click = isPast ? '' : ' onclick="window._recurCalInstance.toggle(\'' + key + '\')"';
            html += '<div class="' + cls + '" data-date="' + key + '"' + click + '>' + d + '</div>';
        }
        html += '</div></div>';
        return html;
    }

    render() {
        let nm = this.month + 1, ny = this.year;
        if (nm > 11) { nm = 0; ny++; }

        let html = '<div class="recur-cal-wrap">';
        html += this.renderMonth(this.year, this.month);
        html += this.renderMonth(ny, nm);
        html += '</div>';
        html += '<div class="mt-2 d-flex gap-2 align-items-center flex-wrap">';
        html += '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window._recurCalInstance.prevMonths()"><i class="bi bi-chevron-left"></i> Προηγ.</button>';
        html += '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window._recurCalInstance.nextMonths()">Επόμ. <i class="bi bi-chevron-right"></i></button>';
        if (this.selected.size > 0) {
            html += '<button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="window._recurCalInstance.clearAll()"><i class="bi bi-x-circle me-1"></i>Καθαρισμός</button>';
        }
        html += '<span class="ms-auto text-muted small">' + this.selected.size + ' επιλεγμένες</span>';
        html += '</div>';
        this.container.innerHTML = html;
        updateRecurPreview();
    }

    toggle(key) {
        if (this.selected.has(key)) this.selected.delete(key);
        else this.selected.add(key);
        this.hidden.value = Array.from(this.selected).sort().join(',');
        this.render();
    }

    clearAll() {
        this.selected.clear();
        this.hidden.value = '';
        this.render();
    }

    prevMonths() {
        this.month--;
        if (this.month < 0) { this.month = 11; this.year--; }
        this.render();
    }

    nextMonths() {
        this.month++;
        if (this.month > 11) { this.month = 0; this.year++; }
        this.render();
    }
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
