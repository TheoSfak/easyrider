<?php
/**
 * EasyRide - View Action
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ride-functions.php';
requireLogin();

$id = (int) get('id');
if (!$id) {
    redirect('missions.php');
}

$mission = dbFetchOne(
    "SELECT m.*, u.name as creator_name, r.name as responsible_name,
            mt.name as type_name, mt.color as type_color, mt.icon as type_icon
     FROM missions m
     LEFT JOIN users u ON m.created_by = u.id
     LEFT JOIN users r ON m.responsible_user_id = r.id
     LEFT JOIN mission_types mt ON m.mission_type_id = mt.id
     WHERE m.id = ?",
    [$id]
);

if (!$mission) {
    setFlash('error', 'Η δράση δεν βρέθηκε.');
    redirect('missions.php');
}

$routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
$routeMetrics = rideMissionRouteMetrics($mission, $routePoints);
$routeGeometry = rideRouteGeometry($mission);

$missionDays = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number", [$id]);
$isMultiDayMission = !empty($missionDays);
foreach ($missionDays as &$day) {
    $day['route_points_decoded'] = normalizeRideRoutePoints($day['route_points'] ?? '[]');
    $day['metrics'] = rideMissionRouteMetrics($day, $day['route_points_decoded']);
    $day['geometry'] = rideRouteGeometry($day);
    $day['date_label'] = formatDateGreek($day['day_date']);
}
unset($day);

function buildGoogleMapsDirectionsUrl(array $routePoints, array $mission): string {
    if (count($routePoints) >= 2) {
        $origin = $routePoints[0]['lat'] . ',' . $routePoints[0]['lng'];
        $destinationPoint = $routePoints[count($routePoints) - 1];
        $destination = $destinationPoint['lat'] . ',' . $destinationPoint['lng'];
        $waypoints = array_slice($routePoints, 1, -1);
        $waypoints = array_slice($waypoints, 0, 20);

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

    if (!empty($routePoints)) {
        return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($routePoints[0]['lat'] . ',' . $routePoints[0]['lng']);
    }

    if (!empty($mission['maps_link'])) {
        return $mission['maps_link'];
    }

    if (!empty($mission['latitude']) && !empty($mission['longitude'])) {
        return 'https://www.google.com/maps?q=' . urlencode($mission['latitude'] . ',' . $mission['longitude']);
    }

    return '';
}

$googleDirectionsUrl = buildGoogleMapsDirectionsUrl($routePoints, $mission);

foreach ($missionDays as &$day) {
    $day['directions_url'] = buildGoogleMapsDirectionsUrl($day['route_points_decoded'], $mission);
}
unset($day);

$pageTitle = $mission['title'];
$user = getCurrentUser();

// Load series siblings if this mission belongs to a recurrence series
$seriesMissions = [];
$recurrenceInfo = null;
if (!empty($mission['recurrence_id'])) {
    $seriesMissions = dbFetchAll(
        "SELECT id, title, start_datetime, end_datetime, recurrence_instance_date, status
         FROM missions
         WHERE recurrence_id = ? AND deleted_at IS NULL
         ORDER BY start_datetime ASC",
        [$mission['recurrence_id']]
    );
    $recurrenceInfo = dbFetchOne(
        "SELECT type, weekdays, random_dates, interval_days, end_date FROM mission_recurrences WHERE id = ?",
        [$mission['recurrence_id']]
    );
}

// Ειδικοί τύποι δράσης: αποκλεισμός για μη-εξουσιοδοτημένα μέλη
if (isTepMission((int)($mission['mission_type_id'] ?? 0)) && !canSeeTep($mission['responsible_user_id'])) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτόν τον τύπο δράσης.');
    redirect('missions.php');
}

// Permission flags for this page
$canViewAllMissions = hasPagePermission('missions_view');   // see draft/closed/completed
$canManageMissions  = hasPagePermission('missions_manage'); // edit/status/delete
$canManageShifts    = hasPagePermission('shifts_manage');   // approve/reject participants

// Non-admins can only view OPEN missions; users with missions_view see all statuses
$allowedStatuses = [STATUS_OPEN, STATUS_CLOSED];
$isResponsible = !empty($mission['responsible_user_id']) && $mission['responsible_user_id'] == $user['id'];
$canResolveRideEvents = isRideController($mission, $user);
$canViewRideEvents = $canManageMissions || $isResponsible || $canResolveRideEvents;
$rideEvents = $canViewRideEvents ? getRideEvents($id, 40, false) : [];

$rideReplayEvents = [];
if ($mission['status'] === STATUS_COMPLETED && count($routeGeometry) >= 2) {
    $eventsWithFractions = rideEventRoutePositions($routeGeometry, $rideEvents ?? []);
    foreach ($eventsWithFractions as $event) {
        if ($event['route_fraction'] === null) {
            continue;
        }
        $rideReplayEvents[] = [
            'lat' => (float)$event['lat'],
            'lng' => (float)$event['lng'],
            'title' => (string)$event['title'],
            'severity' => (string)($event['severity'] ?? 'info'),
            'routeFraction' => (float)$event['route_fraction'],
        ];
    }
}

$rideReadiness = $canViewRideEvents ? getRideReadinessSummary($id) : null;
$activeRideEventCount = 0;
foreach ($rideEvents as $rideEvent) {
    if (empty($rideEvent['resolved_at'])) {
        $activeRideEventCount++;
    }
}
if (!$canViewAllMissions && !$isResponsible && !in_array($mission['status'], $allowedStatuses)) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη δράση.');
    redirect('missions.php');
}

// Get shifts
$shifts = dbFetchAll(
    "SELECT s.*,
            (SELECT COUNT(*) FROM participation_requests WHERE shift_id = s.id AND status = ?) as approved_count,
            (SELECT COUNT(*) FROM participation_requests WHERE shift_id = s.id AND status = ?) as pending_count
     FROM shifts s
     WHERE s.mission_id = ?
     ORDER BY s.start_time ASC",
    [PARTICIPATION_APPROVED, PARTICIPATION_PENDING, $id]
);

// Check if current user has applied to any shift
$userParticipations = [];
if (!$canViewAllMissions) {
    $rawParts = dbFetchAll(
        "SELECT id, shift_id, status FROM participation_requests WHERE volunteer_id = ? AND shift_id IN (SELECT id FROM shifts WHERE mission_id = ?)",
        [$user['id'], $id]
    );
    $userParticipations = [];
    foreach ($rawParts as $rp) {
        $userParticipations[$rp['shift_id']] = ['status' => $rp['status'], 'id' => $rp['id']];
    }
}

// Check if user has approved participation (for chat access)
$isApprovedParticipant = false;
if (!$canViewAllMissions) {
    $isApprovedParticipant = dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests pr
         INNER JOIN shifts s ON pr.shift_id = s.id
         WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
        [$id, $user['id'], PARTICIPATION_APPROVED]
    ) > 0;
}
$canAccessChat = $canViewAllMissions || $isApprovedParticipant;

// Get chat messages if user has access
$chatMessages = [];
if ($canAccessChat) {
    $chatMessages = dbFetchAll(
        "SELECT m.*, u.name as user_name 
         FROM mission_chat_messages m
         INNER JOIN users u ON m.user_id = u.id
         WHERE m.mission_id = ?
         ORDER BY m.created_at ASC",
        [$id]
    );
}

// Get available volunteers for admin manual add
$availableVolunteers = [];
if ($canManageMissions) {
    $availableVolunteers = dbFetchAll(
        "SELECT id, name, email, role FROM users 
         WHERE is_active = 1
         ORDER BY name"
    );
}

// Positions for publish targeting panel
$publishPositions = dbFetchAll(
    "SELECT id, name FROM volunteer_positions WHERE is_active = 1 ORDER BY name"
);

// Get debrief if it exists (even if status is not completed yet, e.g. reopened)
$debrief = dbFetchOne(
    "SELECT md.*, COALESCE(u.name, 'Άγνωστος') as submitter_name 
     FROM mission_debriefs md
     LEFT JOIN users u ON md.submitted_by = u.id
     WHERE md.mission_id = ?",
    [$id]
);
$rideClosureSummary = ($canManageMissions || $isResponsible) ? buildRideClosureSummary($id) : null;

// Weather forecast (null if not applicable or no API key configured)
require_once __DIR__ . '/includes/weather.php';
$weather = getWeatherForMission($mission);

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'delete_chat_message':
            if ($canManageMissions && $canAccessChat) {
                $messageId = post('message_id');
                if ($messageId) {
                    dbExecute("DELETE FROM mission_chat_messages WHERE id = ? AND mission_id = ?", [$messageId, $id]);
                    setFlash('success', 'Το μήνυμα διαγράφηκε.');
                }
                redirect('mission-view.php?id=' . $id . '#chat');
            }
            break;
            
        case 'send_chat_message':
            if ($canAccessChat) {
                $message = trim(post('message'));
                if (!empty($message)) {
                    dbInsert(
                        "INSERT INTO mission_chat_messages (mission_id, user_id, message) VALUES (?, ?, ?)",
                        [$id, $user['id'], $message]
                    );
                    setFlash('success', 'Το μήνυμα στάλθηκε.');
                }
                redirect('mission-view.php?id=' . $id . '#chat');
            }
            break;
            
        case 'resend_email':
            if ($canManageMissions && $mission['status'] === STATUS_OPEN) {
                $allUsers = dbFetchAll(
                    "SELECT id, name, email FROM users WHERE is_active = 1 AND deleted_at IS NULL"
                );
                $missionUrl = rtrim(BASE_URL, '/') . '/mission-view.php?id=' . $id;
                $appName = getSetting('app_name', 'EasyRide');
                $sent = 0;
                
                $userIds = array_column($allUsers, 'id');
                if (!empty($userIds)) {
                    sendBulkNotifications(
                        $userIds,
                        'Υπενθύμιση Δράσης: ' . $mission['title'],
                        'Η δράση είναι ακόμα ανοιχτή και αναζητά μέλη. Δείτε τα διαθέσιμα σκέλη.'
                    );
                }
                
                foreach ($allUsers as $v) {
                    if (!empty($v['email'])) {
                        sendNotificationEmail('mission_reminder', $v['email'], [
                            'user_name'           => $v['name'],
                            'mission_title'       => $mission['title'],
                            'mission_description' => $mission['description'] ?? '',
                            'mission_url'         => $missionUrl,
                            'app_name'            => $appName,
                        ]);
                        $sent++;
                    }
                }
                logAudit('resend_mission_email', 'missions', $id);
                setFlash('success', 'Στάλθηκε υπενθύμιση σε ' . $sent . ' χρήστες.');
            }
            break;

        case 'send_ride_link':
            if ($canManageMissions || $isResponsible) {
                $participants = dbFetchAll(
                    "SELECT DISTINCT u.id, u.name, u.email
                     FROM participation_requests pr
                     JOIN shifts s ON s.id = pr.shift_id
                     JOIN users u ON u.id = pr.volunteer_id
                     WHERE s.mission_id = ?
                       AND pr.status = ?
                       AND u.is_active = 1
                       AND u.deleted_at IS NULL",
                    [$id, PARTICIPATION_APPROVED]
                );

                if (empty($participants)) {
                    setFlash('warning', 'Δεν υπάρχουν εγκεκριμένοι συμμετέχοντες για αποστολή Ride Link.');
                    redirect('mission-view.php?id=' . $id);
                }

                $rideUrl = rtrim(BASE_URL, '/') . '/ride-mode.php?mission_id=' . $id;
                $appName = getSetting('app_name', APP_NAME);
                $userIds = array_column($participants, 'id');
                sendBulkNotifications(
                    $userIds,
                    'Ride Mode: ' . $mission['title'],
                    'Άνοιξε το Ride Mode και πάτησε Έναρξη όταν ξεκινήσει η διαδρομή.'
                );

                $sent = 0;
                foreach ($participants as $participant) {
                    if (empty($participant['email'])) {
                        continue;
                    }

                    $subject = '[' . $appName . '] Ride Mode: ' . $mission['title'];
                    $body = '
<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#172033">
  <h2 style="color:#0d6efd">Ride Mode</h2>
  <p>Γεια σου <strong>' . h($participant['name']) . '</strong>,</p>
  <p>Για τη δράση <strong>' . h($mission['title']) . '</strong>, άνοιξε το Ride Mode από το κινητό σου και πάτησε <strong>Έναρξη</strong> όταν ξεκινήσει η διαδρομή.</p>
  <p style="background:#fff7ed;border-left:4px solid #f59e0b;padding:10px 12px">Το Ride Mode λειτουργεί όσο η οθόνη παραμένει ανοιχτή.</p>
  <p style="margin-top:20px">
    <a href="' . h($rideUrl) . '" style="background:#0d6efd;color:#fff;padding:12px 18px;text-decoration:none;border-radius:6px;display:inline-block">Άνοιγμα Ride Mode</a>
  </p>
  <p style="color:#64748b;font-size:13px;margin-top:24px">&mdash; ' . h($appName) . '</p>
</div>';
                    if (sendEmail($participant['email'], $subject, $body)) {
                        $sent++;
                    }
                }

                logAudit('send_ride_link', 'missions', $id, ['participants' => count($participants), 'emails' => $sent]);
                setFlash('success', 'Στάλθηκε Ride Link σε ' . count($participants) . ' συμμετέχοντες. Emails: ' . $sent . '.');
                redirect('mission-view.php?id=' . $id);
            }
            break;

        case 'publish':
            if ($canManageMissions && $mission['status'] === STATUS_DRAFT) {
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_OPEN, $id]);
                logAudit('publish', 'missions', $id);

                // Auto-create a shift if none exist
                $shiftCount = (int) dbFetchValue("SELECT COUNT(*) FROM shifts WHERE mission_id = ?", [$id]);
                if ($shiftCount === 0) {
                    dbInsert(
                        "INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers, min_volunteers, created_at, updated_at)
                         VALUES (?, ?, ?, 5, 1, NOW(), NOW())",
                        [$id, $mission['start_datetime'], $mission['end_datetime']]
                    );
                    logAudit('auto_create_shift', 'shifts', $id, 'Αυτόματη δημιουργία βαρδίας κατά τη δημοσίευση');
                }

                // Notify volunteers based on targeting selection
                if (isset($_POST['notify_volunteers'])) {
                    $notifyTarget = post('notify_target', 'all');
                    $whereFilter  = "is_active = 1 AND deleted_at IS NULL";
                    $filterParams = [];

                    if ($notifyTarget === 'roles' && !empty($_POST['notify_roles'])) {
                        $allowedRoles = [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER, ROLE_VOLUNTEER];
                        $roles = array_values(array_filter((array)$_POST['notify_roles'], fn($r) => in_array($r, $allowedRoles)));
                        if (!empty($roles)) {
                            $placeholders  = implode(',', array_fill(0, count($roles), '?'));
                            $whereFilter  .= " AND role IN ($placeholders)";
                            $filterParams  = $roles;
                        }
                    } elseif ($notifyTarget === 'vtypes' && !empty($_POST['notify_vtypes'])) {
                        $allowedVtypes = [VTYPE_TRAINEE, VTYPE_RESCUER];
                        $vtypes = array_values(array_filter((array)$_POST['notify_vtypes'], fn($v) => in_array($v, $allowedVtypes)));
                        if (!empty($vtypes)) {
                            $placeholders  = implode(',', array_fill(0, count($vtypes), '?'));
                            $whereFilter  .= " AND volunteer_type IN ($placeholders)";
                            $filterParams  = $vtypes;
                        }
                    } elseif ($notifyTarget === 'positions' && !empty($_POST['notify_positions'])) {
                        $positions = array_values(array_filter(array_map('intval', (array)$_POST['notify_positions']), fn($p) => $p > 0));
                        if (!empty($positions)) {
                            $placeholders  = implode(',', array_fill(0, count($positions), '?'));
                            $whereFilter  .= " AND position_id IN ($placeholders)";
                            $filterParams  = $positions;
                        }
                    }

                    $volunteers = dbFetchAll(
                        "SELECT id, name, email FROM users WHERE $whereFilter",
                        $filterParams
                    );
                    $missionUrl = rtrim(BASE_URL, '/') . '/mission-view.php?id=' . $id;
                $appName = getSetting('app_name', 'EasyRide');

                    // Human-readable label for flash message
                    $targetLabel = 'όλους τους χρήστες';
                    if ($notifyTarget === 'roles' && !empty($roles ?? [])) {
                        $roleLabels = array_map(fn($r) => ROLE_LABELS[$r] ?? $r, $roles);
                        $targetLabel = implode(', ', $roleLabels);
                    } elseif ($notifyTarget === 'vtypes' && !empty($vtypes ?? [])) {
                        $vtypeLabels = array_map(fn($v) => VOLUNTEER_TYPE_LABELS[$v] ?? $v, $vtypes);
                        $targetLabel = implode(', ', $vtypeLabels);
                    } elseif ($notifyTarget === 'positions' && !empty($positions ?? [])) {
                        $posPh = implode(',', array_fill(0, count($positions), '?'));
                        $posRows = dbFetchAll(
                            "SELECT name FROM volunteer_positions WHERE id IN ($posPh)",
                            $positions
                        );
                        $targetLabel = implode(', ', array_column($posRows, 'name'));
                    }
                    
                    $userIds = array_column($volunteers, 'id');
                    if (!empty($userIds)) {
                        sendBulkNotifications(
                            $userIds,
                            'Νέα Δράση: ' . $mission['title'],
                            'Μια νέα δράση δημοσιεύτηκε και αναζητά μέλη. Δείτε τα διαθέσιμα σκέλη.'
                        );
                    }
                    
                    $sent = 0; $failed = 0; $lastError = '';
                    foreach ($volunteers as $v) {
                        if (!empty($v['email'])) {
                            $result = sendNotificationEmail('new_mission', $v['email'], [
                                'user_name'           => $v['name'],
                                'mission_title'       => $mission['title'],
                                'mission_description' => $mission['description'] ?? '',
                                'location'            => $mission['location'] ?? 'Θα ανακοινωθεί',
                                'start_date'          => formatDate($mission['start_datetime']),
                                'end_date'            => formatDate($mission['end_datetime']),
                                'mission_url'         => $missionUrl,
                                'app_name'            => $appName,
                            ]);
                            if ($result['success']) {
                                $sent++;
                            } else {
                                $failed++;
                                $lastError = $result['message'];
                            }
                        }
                    }
                    if ($failed > 0) {
                        logAudit('email_send_error', 'missions', $id, 'Sent:' . $sent . ' Failed:' . $failed . ' Error:' . $lastError);
                        setFlash('warning', 'Η δράση δημοσιεύτηκε. Emails: ' . $sent . ' εστάλησαν σε (' . $targetLabel . '), ' . $failed . ' απέτυχαν (δείτε Audit Log).');
                    } else {
                        setFlash('success', 'Η δράση δημοσιεύτηκε και στάλθηκε email σε ' . $sent . ' χρήστες (' . $targetLabel . ').');
                    }
                } else {
                    setFlash('success', 'Η δράση δημοσιεύτηκε.');
                }

                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'close':
            if (($canManageMissions || $isResponsible) && $mission['status'] === STATUS_OPEN) {
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_CLOSED, $id]);
                logAudit('close', 'missions', $id);
                setFlash('success', 'Η δράση έκλεισε.');
                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'complete':
            if ($canManageMissions && $mission['status'] === STATUS_CLOSED) {
                // Redirect to debrief form instead of completing immediately
                redirect('mission-debrief.php?id=' . $id);
            }
            break;
            
        case 'complete_only':
            if ($canManageMissions && $mission['status'] === STATUS_CLOSED) {
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_COMPLETED, $id]);
                logAudit('complete_only', 'missions', $id);
                setFlash('success', 'Η δράση ολοκληρώθηκε (χωρίς αλλαγή στην αναφορά).');
                redirect('mission-view.php?id=' . $id);
            }
            break;

        case 'close_ride':
            if (($canManageMissions || $isResponsible) && in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED], true)) {
                $closureSummary = buildRideClosureSummary($id);
                $adminNotes = trim(post('closure_notes'));
                $incidents = trim(post('closure_incidents', $closureSummary['incidents'] ?? ''));
                $equipmentIssues = trim(post('closure_equipment_issues'));
                $objectivesMet = post('closure_objectives_met', 'YES');
                $rating = (int) post('closure_rating', 5);
                $notifyParticipants = post('notify_participants') === '1';

                if (!in_array($objectivesMet, ['YES', 'PARTIAL', 'NO'], true)) {
                    $objectivesMet = 'YES';
                }
                if ($rating < 1 || $rating > 5) {
                    $rating = 5;
                }

                $summary = trim($closureSummary['summary']);
                if ($adminNotes !== '') {
                    $summary .= "\n\nΣημειώσεις κλεισίματος:\n" . $adminNotes;
                }

                db()->beginTransaction();
                try {
                    $existingDebrief = dbFetchOne("SELECT id FROM mission_debriefs WHERE mission_id = ?", [$id]);
                    if ($existingDebrief) {
                        dbExecute(
                            "UPDATE mission_debriefs
                             SET summary = ?, objectives_met = ?, incidents = ?, equipment_issues = ?, rating = ?, updated_at = NOW()
                             WHERE mission_id = ?",
                            [$summary, $objectivesMet, $incidents ?: null, $equipmentIssues ?: null, $rating, $id]
                        );
                    } else {
                        dbInsert(
                            "INSERT INTO mission_debriefs (mission_id, submitted_by, summary, objectives_met, incidents, equipment_issues, rating)
                             VALUES (?, ?, ?, ?, ?, ?, ?)",
                            [$id, $user['id'], $summary, $objectivesMet, $incidents ?: null, $equipmentIssues ?: null, $rating]
                        );
                    }

                    dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_COMPLETED, $id]);
                    logAudit('close_ride', 'missions', $id, [
                        'checklist' => ($closureSummary['checklist']['completed'] ?? 0) . '/' . ($closureSummary['checklist']['total'] ?? 0),
                        'events' => $closureSummary['event_count'] ?? 0,
                        'active_events' => $closureSummary['active_event_count'] ?? 0,
                    ]);

                    db()->commit();

                    if ($notifyParticipants) {
                        $rideReportUrl = rtrim(BASE_URL, '/') . '/ride-report.php?mission_id=' . $id;
                        $appName = getSetting('app_name', APP_NAME);
                        $participants = dbFetchAll(
                            "SELECT DISTINCT pr.volunteer_id, u.name, u.email
                             FROM participation_requests pr
                             JOIN shifts s ON s.id = pr.shift_id
                             JOIN users u ON u.id = pr.volunteer_id
                             WHERE s.mission_id = ?
                               AND pr.status = ?
                               AND u.is_active = 1
                               AND u.deleted_at IS NULL",
                            [$id, PARTICIPATION_APPROVED]
                        );
                        $participantIds = array_column($participants, 'volunteer_id');
                        if (!empty($participantIds)) {
                            sendBulkNotifications(
                                $participantIds,
                                'Ολοκλήρωση Διαδρομής: ' . $mission['title'],
                                'Η διαδρομή ολοκληρώθηκε και είναι διαθέσιμο το Ride Report.'
                            );
                        }
                        foreach ($participants as $participant) {
                            if (empty($participant['email'])) {
                                continue;
                            }
                            $subject = '[' . $appName . '] Ολοκλήρωση Διαδρομής: ' . $mission['title'];
                            $body = '
<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#172033">
  <h2 style="color:#198754">Η διαδρομή ολοκληρώθηκε</h2>
  <p>Γεια σου <strong>' . h($participant['name']) . '</strong>,</p>
  <p>Η δράση <strong>' . h($mission['title']) . '</strong> ολοκληρώθηκε και το Ride Report είναι διαθέσιμο.</p>
  <p style="margin-top:20px">
    <a href="' . h($rideReportUrl) . '" style="background:#198754;color:#fff;padding:12px 18px;text-decoration:none;border-radius:6px;display:inline-block">Άνοιγμα Ride Report</a>
  </p>
  <p style="color:#64748b;font-size:13px;margin-top:24px">&mdash; ' . h($appName) . '</p>
</div>';
                            sendEmail($participant['email'], $subject, $body);
                        }
                    }

                    setFlash('success', 'Η διαδρομή έκλεισε και η δράση σημειώθηκε ως ολοκληρωμένη.');
                } catch (Exception $e) {
                    db()->rollBack();
                    setFlash('error', 'Σφάλμα στο κλείσιμο διαδρομής: ' . $e->getMessage());
                }

                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'reopen_to_closed':
            if ($canManageMissions && $mission['status'] === STATUS_COMPLETED) {
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_CLOSED, $id]);
                logAudit('reopen_to_closed', 'missions', $id);
                setFlash('success', 'Η δράση επέστρεψε σε «Κλειστή». Μπορείτε να αλλάξετε παρουσίες και μέλη.');
                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'cancel':
            if ($canManageMissions && in_array($mission['status'], [STATUS_DRAFT, STATUS_OPEN])) {
                $reason = post('cancellation_reason');
                
                db()->beginTransaction();
                try {
                    dbExecute(
                        "UPDATE missions SET status = ?, cancellation_reason = ?, canceled_by = ?, canceled_at = NOW(), updated_at = NOW() WHERE id = ?",
                        [STATUS_CANCELED, $reason, $user['id'], $id]
                    );
                    logAudit('cancel', 'missions', $id, null, ['reason' => $reason]);

                    // Notify all volunteers with PENDING or APPROVED participation in any shift
                    $cancelShifts = dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$id]);
                    $cancelShiftIds = array_column($cancelShifts, 'id');
                    $notifiedCancel = [];
                    if (!empty($cancelShiftIds)) {
                        $ph = implode(',', array_fill(0, count($cancelShiftIds), '?'));
                        $cancelParticipants = dbFetchAll(
                            "SELECT DISTINCT pr.volunteer_id, u.name, u.email
                             FROM participation_requests pr
                             JOIN users u ON pr.volunteer_id = u.id
                             WHERE pr.shift_id IN ($ph) AND pr.status IN (?,?)",
                            array_merge($cancelShiftIds, [PARTICIPATION_PENDING, PARTICIPATION_APPROVED])
                        );
                        
                        $userIds = array_column($cancelParticipants, 'volunteer_id');
                        if (!empty($userIds)) {
                            sendBulkNotifications(
                                $userIds,
                                'Ακύρωση Δράσης: ' . $mission['title'],
                                'Η δράση ακυρώθηκε' . ($reason ? '. Λόγος: ' . $reason : '') . '. Οι αιτήσεις σας ακυρώθηκαν αυτόματα.'
                            );
                        }
                        
                        foreach ($cancelParticipants as $cp) {
                            if (in_array($cp['volunteer_id'], $notifiedCancel)) continue;
                            $notifiedCancel[] = $cp['volunteer_id'];
                            // Email
                            if (!empty($cp['email'])) {
                                sendNotificationEmail('mission_canceled', $cp['email'], [
                                    'user_name'     => $cp['name'],
                                    'mission_title' => $mission['title'],
                                    'reason'        => $reason ?: 'Δεν δόθηκε αιτιολογία.',
                                ]);
                            }
                        }
                    }
                    
                    db()->commit();
                    
                    $msg = 'Η δράση ακυρώθηκε.';
                    if (!empty($notifiedCancel)) {
                        $msg .= ' Ειδοποιήθηκαν ' . count($notifiedCancel) . ' μέλη.';
                    }
                    setFlash('success', $msg);
                } catch (Exception $e) {
                    db()->rollBack();
                    setFlash('error', 'Σφάλμα κατά την ακύρωση της δράσης.');
                }
                redirect('missions.php');
            }
            break;
            
        case 'delete':
            if ($canManageMissions) {
                // Get all shifts for this mission
                $missionShifts = dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$id]);
                $shiftIds = array_column($missionShifts, 'id');
                $notifiedUsers = [];

                db()->beginTransaction();
                try {
                    if (!empty($shiftIds)) {
                        // Get all affected participants (PENDING or APPROVED)
                        $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
                        $affectedParticipants = dbFetchAll(
                            "SELECT DISTINCT pr.volunteer_id, u.name, u.email
                             FROM participation_requests pr 
                             JOIN users u ON pr.volunteer_id = u.id 
                             WHERE pr.shift_id IN ($placeholders) AND pr.status IN (?,?)",
                            array_merge($shiftIds, [PARTICIPATION_PENDING, PARTICIPATION_APPROVED])
                        );
                        
                        // Bulk in-app notification
                        $notifiedUsers = array_column($affectedParticipants, 'volunteer_id');
                        if (!empty($notifiedUsers)) {
                            sendBulkNotifications(
                                $notifiedUsers,
                                'Διαγραφή Δράσης: ' . $mission['title'],
                                'Η δράση "' . $mission['title'] . '" διαγράφηκε. Όλες οι αιτήσεις σας ακυρώθηκαν αυτόματα.'
                            );
                        }
                        
                        // Email each affected volunteer
                        foreach ($affectedParticipants as $participant) {
                            if (!empty($participant['email'])) {
                                sendNotificationEmail('mission_canceled', $participant['email'], [
                                    'user_name'     => $participant['name'],
                                    'mission_title' => $mission['title'],
                                    'reason'        => 'Η δράση διαγράφηκε από διαχειριστή.',
                                ]);
                            }
                        }
                        
                        // Delete participation requests
                        dbExecute("DELETE FROM participation_requests WHERE shift_id IN ($placeholders)", $shiftIds);
                        
                        // Delete shifts
                        dbExecute("DELETE FROM shifts WHERE mission_id = ?", [$id]);
                    }
                    
                    // Soft delete mission
                    dbExecute("UPDATE missions SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?", [$id]);
                    logAudit('delete', 'missions', $id, 'Notified ' . count($notifiedUsers) . ' volunteers');
                    
                    db()->commit();
                    
                    $msg = 'Η δράση διαγράφηκε.';
                    if (!empty($notifiedUsers)) {
                        $msg .= ' Ειδοποιήθηκαν ' . count($notifiedUsers) . ' μέλη.';
                    }
                    setFlash('success', $msg);
                } catch (Exception $e) {
                    db()->rollBack();
                    setFlash('error', 'Σφάλμα κατά τη διαγραφή της δράσης.');
                }
                redirect('missions.php');
            }
            break;
            
        case 'apply':
            $shiftId = post('shift_id');
            $volunteerNotes = post('volunteer_notes');
            // Block volunteer apply if mission has expired
            $missionExpired = in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED]) && strtotime($mission['end_datetime']) < time();
            if ($missionExpired && !$canManageMissions) {
                setFlash('error', 'Η δράση είναι ακόμα ανοιχτή αλλά ο χρόνος διεξαγωγής έχει παρέλθει. Δεν μπορείτε να υποβάλετε αίτηση.');
                redirect('mission-view.php?id=' . $id);
            }
            if ($shiftId && !$canManageMissions) {
                // Check if already applied
                $existing = dbFetchValue(
                    "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND shift_id = ?",
                    [$user['id'], $shiftId]
                );
                
                if (!$existing) {
                    dbInsert(
                        "INSERT INTO participation_requests (volunteer_id, shift_id, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
                        [$user['id'], $shiftId, PARTICIPATION_PENDING, $volunteerNotes ?: null]
                    );
                    logAudit('apply', 'participation_requests', null, null, ['shift_id' => $shiftId]);
                    setFlash('success', 'Η αίτησή σας υποβλήθηκε.');
                } else {
                    setFlash('error', 'Έχετε ήδη υποβάλει αίτηση για αυτή τη βάρδια.');
                }
                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'manual_add_volunteer':
            if ($canManageMissions && $mission['status'] !== STATUS_COMPLETED) {
                $shiftIds = post('shift_ids', []); // Array of shift IDs
                $volunteerId = post('volunteer_id');
                $adminNotes = post('admin_notes');
                
                if (!empty($shiftIds) && $volunteerId) {
                    $addedCount = 0;
                    $skippedCount = 0;
                    
                    // Get volunteer info once
                    $volunteer = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$volunteerId]);
                    
                    foreach ($shiftIds as $shiftId) {
                        // Check if already has an active (PENDING/APPROVED) participation
                        $existingPr = dbFetchOne(
                            "SELECT id, status FROM participation_requests WHERE volunteer_id = ? AND shift_id = ?",
                            [$volunteerId, $shiftId]
                        );
                        
                        if ($existingPr && in_array($existingPr['status'], [PARTICIPATION_PENDING, PARTICIPATION_APPROVED])) {
                            // Already active — skip
                            $skippedCount++;
                        } else {
                            if ($existingPr) {
                                // Reactivate existing rejected/canceled record
                                dbExecute(
                                    "UPDATE participation_requests 
                                     SET status = ?, rejection_reason = NULL, admin_notes = ?, 
                                         decided_by = ?, decided_at = NOW(), updated_at = NOW() 
                                     WHERE id = ?",
                                    [PARTICIPATION_APPROVED, $adminNotes, $user['id'], $existingPr['id']]
                                );
                                $prId = $existingPr['id'];
                            } else {
                                // Insert new record
                                $prId = dbInsert(
                                    "INSERT INTO participation_requests 
                                     (volunteer_id, shift_id, status, admin_notes, decided_by, decided_at, created_at, updated_at) 
                                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())",
                                    [$volunteerId, $shiftId, PARTICIPATION_APPROVED, $adminNotes, $user['id']]
                                );
                            }
                            
                            // Get shift info with mission details
                            $shift = dbFetchOne(
                                "SELECT s.*, m.title as mission_title, m.location 
                                 FROM shifts s 
                                 JOIN missions m ON s.mission_id = m.id 
                                 WHERE s.id = ?",
                                [$shiftId]
                            );
                            
                            // Send notification email
                            if ($volunteer && !empty($volunteer['email']) && isNotificationEnabled('admin_added_volunteer')) {
                                $gcalLink = buildGcalLink($shift['mission_title'], $shift['start_time'], $shift['end_time'], $shift['location'] ?: '');
                                sendNotificationEmail(
                                    'admin_added_volunteer',
                                    $volunteer['email'],
                                    [
                                        'user_name'     => $volunteer['name'],
                                        'mission_title' => $shift['mission_title'],
                                        'shift_date'    => formatDateTime($shift['start_time'], 'd/m/Y'),
                                        'shift_time'    => formatDateTime($shift['start_time'], 'H:i') . ' - ' . formatDateTime($shift['end_time'], 'H:i'),
                                        'location'      => $shift['location'] ?: 'Θα ανακοινωθεί',
                                        'admin_notes'   => $adminNotes ?: 'Προστεθήκατε από τον διαχειριστή.',
                                        'gcal_link'     => $gcalLink,
                                    ]
                                );
                            }
                            
                            // Send in-app notification
                            sendNotification(
                                $volunteerId,
                                'Τοποθετήθηκατε σε βάρδια',
                                'Ο διαχειριστής σας τοποθέτησε στη βάρδια: ' . $shift['mission_title'] . ' - ' . formatDateTime($shift['start_time'])
                            );
                            
                            logAudit('manual_add_volunteer', 'participation_requests', $prId);
                            $addedCount++;
                        }
                    }
                    
                    $message = '';
                    if ($addedCount > 0) {
                        $message = "Ο εθελοντής προστέθηκε σε {$addedCount} βάρδια/ες.";
                    }
                    if ($skippedCount > 0) {
                        $message .= " {$skippedCount} βάρδια/ες παραλείφθηκαν (ήδη συμμετέχει).";
                    }
                    
                    setFlash($addedCount > 0 ? 'success' : 'warning', $message);
                }
                redirect('mission-view.php?id=' . $id);
            }
            break;
    }
}

// Detect overdue mission (past end date but not closed/completed/canceled)
$isOverdue = in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED]) 
    && strtotime($mission['end_datetime']) < time();

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <?= h($mission['title']) ?>
            <?php if (!empty($mission['type_name'])): ?>
                <span class="badge bg-<?= h($mission['type_color'] ?? 'secondary') ?>">
                    <i class="bi <?= h($mission['type_icon'] ?? 'bi-flag') ?>"></i>
                    <?= h($mission['type_name']) ?>
                </span>
            <?php endif; ?>
            <?php if ($mission['is_urgent']): ?>
                <span class="badge bg-danger">Επείγον</span>
            <?php endif; ?>
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="missions.php">Δράσεις</a></li>
                <li class="breadcrumb-item active"><?= h($mission['title']) ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        <a href="ride-mode.php?mission_id=<?= $mission['id'] ?>" class="btn btn-primary">
            <i class="bi bi-broadcast-pin me-1"></i>Ride Mode
        </a>
        <?php if ($canViewRideEvents): ?>
            <a href="ride-report.php?mission_id=<?= $mission['id'] ?>" class="btn btn-outline-dark">
                <i class="bi bi-file-earmark-text me-1"></i>Ride Report
            </a>
        <?php endif; ?>
        <?php if ($canManageMissions): ?>
            <a href="mission-form.php?id=<?= $mission['id'] ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Επεξεργασία
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isOverdue && !$canManageMissions): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-clock-history fs-4 text-warning"></i>
    <div>
        Η αποστολή είναι ακόμα ανοιχτή αλλά <strong>ο χρόνος διεξαγωγής έχει παρέλθει</strong>.
        Δεν μπορείτε να υποβάλετε αίτηση συμμετοχής.
    </div>
</div>
<?php endif; ?>

<?php if ($isOverdue && $canManageMissions):
    $elapsed = time() - strtotime($mission['end_datetime']);
    $days = floor($elapsed / 86400);
    $hours = floor(($elapsed % 86400) / 3600);
    $elapsedText = '';
    if ($days > 0) $elapsedText .= $days . ' μέρ' . ($days == 1 ? 'α' : 'ες');
    if ($hours > 0) $elapsedText .= ($days > 0 ? ' και ' : '') . $hours . ' ώρ' . ($hours == 1 ? 'α' : 'ες');
    if (!$elapsedText) $elapsedText = 'Μόλις τώρα';
?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-4 shadow-sm border-danger" style="border-left: 5px solid #dc3545 !important;">
    <i class="bi bi-exclamation-triangle-fill fs-4 text-danger"></i>
    <div>
        <strong>Η αποστολή έχει λήξει!</strong>
        Η ημερομηνία λήξης (<?= formatDateTime($mission['end_datetime']) ?>) έχει παρέλθει — <strong class="text-danger">πριν <?= h($elapsedText) ?></strong>.
        <?php if ($mission['status'] === STATUS_OPEN): ?>
            Παρακαλώ <strong>κλείστε</strong> την αποστολή και στη συνέχεια <strong>ολοκληρώστε</strong> την.
        <?php else: ?>
            Παρακαλώ <strong>ολοκληρώστε</strong> την αποστολή.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($mission['status'] === STATUS_COMPLETED && $canManageMissions): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5"></i>
    <div>
        <strong>Η αποστολή είναι ολοκληρωμένη.</strong>
        Δεν μπορείτε να προσθέσετε εθελοντές ή να αλλάξετε παρουσίες. Αλλάξτε την κατάσταση σε «Κλειστή» για αλλαγές.
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <!-- Mission Details -->
        <?php
        $startDt = new DateTime($mission['start_datetime']);
        $endDt   = new DateTime($mission['end_datetime']);
        $diff    = $startDt->diff($endDt);
        $totalHours = round(($diff->days * 24) + $diff->h + ($diff->i / 60), 1);
        ?>
        <div class="card mb-4" style="border:2px solid #0d6efd;border-radius:10px;overflow:hidden">
            <div class="card-header d-flex justify-content-between align-items-center py-2" style="background:linear-gradient(135deg,#dbeafe 0%,#eff6ff 100%);border-bottom:2px solid #0d6efd">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-info-circle-fill me-2 text-primary"></i>Λεπτομέρειες</h6>
                <?= statusBadge($mission['status']) ?>
            </div>
            <div class="card-body py-3 px-3">

                <?php if ($mission['status'] === STATUS_CANCELED && $mission['cancellation_reason']): ?>
                    <div class="alert alert-danger py-2 mb-3">
                        <i class="bi bi-x-circle-fill me-1"></i>
                        <strong>Λόγος Ακύρωσης:</strong> <?= h($mission['cancellation_reason']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($mission['description']): ?>
                    <div class="mb-3 p-2 rounded-2 text-dark" style="background:#f0f6ff;border:1px solid #bfdbfe;font-size:.93rem">
                        <?= nl2br(h($mission['description'])) ?>
                    </div>
                <?php endif; ?>

                <!-- Info grid -->
                <div class="row g-2 mb-3">
                    <!-- Location -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#f8faff;border:1px solid #dbeafe">
                            <div class="text-primary mt-1" style="font-size:1.1rem"><i class="bi bi-geo-alt-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Τοποθεσία</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= h($mission['location']) ?></div>
                                <?php if ($mission['location_details']): ?>
                                    <div class="text-muted" style="font-size:.8rem"><?= h($mission['location_details']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($mission['maps_link']) || (!empty($mission['latitude']) && !empty($mission['longitude']))): ?>
                                    <?php $mapsUrl = !empty($mission['maps_link']) ? $mission['maps_link'] : 'https://www.google.com/maps?q=' . urlencode($mission['latitude'] . ',' . $mission['longitude']); ?>
                                    <a href="<?= h($mapsUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm mt-1 py-0 px-2" style="font-size:.75rem">
                                        <i class="bi bi-map me-1"></i>Χάρτης
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Type -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#f8faff;border:1px solid #dbeafe">
                            <div class="text-primary mt-1" style="font-size:1.1rem"><i class="bi bi-tag-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Τύπος</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= h($mission['type_name'] ?? $mission['type'] ?? 'Άγνωστος') ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- Creator -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#f8faff;border:1px solid #dbeafe">
                            <div class="text-primary mt-1" style="font-size:1.1rem"><i class="bi bi-person-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Δημιουργός</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= h($mission['creator_name'] ?? '-') ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- Start datetime -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-success mt-1" style="font-size:1.1rem"><i class="bi bi-calendar-event-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Έναρξη</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= formatDateGreek($mission['start_datetime']) ?></div>
                                <div class="text-success fw-bold" style="font-size:.9rem"><?= formatDateTime($mission['start_datetime'], 'H:i') ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- End datetime -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#fff7ed;border:1px solid #fed7aa">
                            <div class="text-warning mt-1" style="font-size:1.1rem"><i class="bi bi-calendar-check-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Λήξη</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= formatDateGreek($mission['end_datetime']) ?></div>
                                <div class="fw-bold" style="font-size:.9rem;color:#d97706"><?= formatDateTime($mission['end_datetime'], 'H:i') ?></div>
                            </div>
                        </div>
                    </div>
                    <?php if ($mission['responsible_user_id']): ?>
                    <!-- Responsible -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#fefce8;border:1px solid #fde68a">
                            <div class="mt-1" style="font-size:1.1rem;color:#d97706"><i class="bi bi-star-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Υπεύθυνος</div>
                                <span class="badge bg-warning text-dark mt-1"><?= h($mission['responsible_name']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Hours banner -->
                <div class="d-flex align-items-center justify-content-center gap-2 rounded-2 py-2 px-3" style="background:linear-gradient(90deg,#0d6efd18 0%,#0d6efd0a 100%);border:1.5px solid #bfdbfe">
                    <i class="bi bi-clock-fill text-primary" style="font-size:1.1rem"></i>
                    <span class="fw-bold text-primary" style="font-size:1rem">Διάρκεια Δράσης:</span>
                    <span class="badge bg-primary px-3 py-2" style="font-size:.95rem"><?= $totalHours ?> ώρες</span>
                </div>

                <?php if ($mission['requirements']): ?>
                    <div class="mt-3">
                        <div class="text-muted small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em"><i class="bi bi-list-check me-1"></i>Απαιτήσεις</div>
                        <div class="p-2 rounded-2 text-dark" style="background:#f8faff;border:1px solid #dbeafe;font-size:.93rem">
                            <?= nl2br(h($mission['requirements'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($mission['notes']): ?>
                    <div class="mt-3">
                        <div class="text-muted small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em"><i class="bi bi-sticky-fill me-1"></i>Σημειώσεις</div>
                        <div class="p-2 rounded-2 text-dark" style="background:#fffdf0;border-left:3px solid #fbbf24;font-size:.93rem">
                            <?= nl2br(h($mission['notes'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php if (!$isMultiDayMission && ((!empty($mission['latitude']) && !empty($mission['longitude'])) || !empty($routePoints))): ?>
        <!-- Mission Location Map -->
        <div class="card mb-4">
            <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-map me-1 text-primary"></i><?= !empty($routePoints) ? 'Χάρτης Διαδρομής' : 'Χάρτης Τοποθεσίας' ?></h6>
                <?php $mapsUrl = !empty($mission['maps_link']) ? $mission['maps_link'] : (!empty($mission['latitude']) && !empty($mission['longitude']) ? 'https://www.google.com/maps?q=' . urlencode($mission['latitude'] . ',' . $mission['longitude']) : ''); ?>
                <div class="d-flex gap-1">
                <?php if ($googleDirectionsUrl && count($routePoints) >= 2): ?>
                <a href="<?= h($googleDirectionsUrl) ?>"
                   target="_blank" rel="noopener noreferrer"
                   class="btn btn-primary btn-sm py-0 px-2" style="font-size:.75rem">
                    <i class="bi bi-sign-turn-right me-1"></i>Πλοήγηση
                </a>
                <?php endif; ?>
                <?php if ($mapsUrl): ?>
                <a href="<?= h($mapsUrl) ?>"
                   target="_blank" rel="noopener noreferrer"
                   class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size:.75rem">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Google Maps
                </a>
                <?php endif; ?>
                <?php if ($mission['status'] === STATUS_COMPLETED && count($routeGeometry) >= 2): ?>
                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size:.75rem"
                        data-bs-toggle="modal" data-bs-target="#rideReplayModal">
                    <i class="bi bi-film me-1"></i>Ride Replay
                </button>
                <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="missionMap" style="height:360px;border-radius:0 0 .375rem .375rem;"></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$isMultiDayMission && !empty($routePoints)): ?>
        <div class="card mb-4">
            <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock-history me-1 text-primary"></i>Χρονοδιάγραμμα Διαδρομής</h6>
                <?php if ($googleDirectionsUrl && count($routePoints) >= 2): ?>
                <a href="<?= h($googleDirectionsUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem">
                    <i class="bi bi-phone me-1"></i>Άνοιγμα στο κινητό
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body py-2 border-bottom">
                <div class="d-flex flex-wrap gap-2 small">
                    <span class="badge bg-light text-dark border"><i class="bi bi-signpost-2 me-1"></i><?= h($routeMetrics['distance_label']) ?></span>
                    <span class="badge bg-light text-dark border"><i class="bi bi-stopwatch me-1"></i>Οδήγηση <?= h($routeMetrics['moving_label']) ?></span>
                    <span class="badge bg-warning text-dark"><i class="bi bi-cup-hot me-1"></i>Στάσεις <?= h($routeMetrics['stop_label']) ?></span>
                    <span class="badge bg-primary"><i class="bi bi-clock-history me-1"></i>Σύνολο <?= h($routeMetrics['total_label']) ?></span>
                    <?php if (($routeMetrics['provider'] ?? '') === 'google'): ?>
                    <span class="text-success align-self-center"><i class="bi bi-google me-1"></i>Διαδρομή μέσω Google.</span>
                    <?php else: ?>
                    <span class="text-muted align-self-center">Εκτίμηση σε ευθεία γραμμή.</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($routePoints as $idx => $point): ?>
                <div class="list-group-item">
                    <div class="d-flex gap-2">
                        <span class="badge bg-primary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:28px;height:28px;"><?= $idx + 1 ?></span>
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <strong><?= h($point['title'] ?: 'Σημείο ' . ($idx + 1)) ?></strong>
                                <?php if ($point['scheduled_time']): ?>
                                    <span class="badge bg-light text-dark border"><i class="bi bi-clock me-1"></i><?= h($point['scheduled_time']) ?></span>
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
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isMultiDayMission): ?>
        <div class="card mb-4">
            <div class="card-header py-2 bg-light">
                <h6 class="mb-0"><i class="bi bi-calendar-range me-1 text-primary"></i>Ημερήσιο Πρόγραμμα</h6>
            </div>
            <ul class="nav nav-pills p-2 border-bottom" id="missionDayViewTabs">
                <?php foreach ($missionDays as $i => $day): ?>
                <li class="nav-item">
                    <a href="#" class="nav-link py-1 px-2<?= $i === 0 ? ' active' : '' ?>" data-day-number="<?= (int)$day['day_number'] ?>">
                        <?= h($day['title'] ?: 'Μέρα ' . (int)$day['day_number']) ?> · <?= h(date('d/m', strtotime($day['day_date']))) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center small">
                <span id="dayViewDate" class="text-muted"></span>
                <a href="#" id="dayViewNavBtn" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem;display:none;">
                    <i class="bi bi-sign-turn-right me-1"></i>Πλοήγηση
                </a>
            </div>
            <div id="dayViewOvernight" class="px-3 py-2 border-bottom small bg-light" style="display:none;">
                <i class="bi bi-moon-stars me-1"></i><span id="dayViewOvernightText"></span>
            </div>
            <div id="dayViewMap" style="height:320px;"></div>
            <div class="p-2 border-bottom d-flex flex-wrap gap-2 small" id="dayViewMetrics"></div>
            <div class="list-group list-group-flush" id="dayViewTimeline"></div>
        </div>
        <?php endif; ?>

        <?php if ($canViewRideEvents): ?>
        <div class="card mb-4" id="ride-events-panel">
            <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-activity me-1 text-primary"></i>Συμβάντα Διαδρομής</h6>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($activeRideEventCount > 0): ?>
                        <span class="badge bg-danger"><?= $activeRideEventCount ?> ενεργά</span>
                    <?php endif; ?>
                    <a href="ride-mode.php?mission_id=<?= (int)$mission['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem">
                        <i class="bi bi-broadcast-pin me-1"></i>Ride Control
                    </a>
                </div>
            </div>
            <?php if (empty($rideEvents)): ?>
                <div class="card-body text-muted small">
                    Δεν έχουν καταγραφεί συμβάντα διαδρομής.
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($rideEvents as $event): ?>
                    <?php
                        $severity = $event['severity'] ?? 'info';
                        $dotColor = [
                            'danger' => '#dc3545',
                            'warning' => '#f59e0b',
                            'secondary' => '#64748b',
                            'info' => '#0d6efd',
                        ][$severity] ?? '#0d6efd';
                        $isResolvedEvent = !empty($event['resolved_at']);
                        $mapsEventUrl = (!empty($event['lat']) && !empty($event['lng']))
                            ? 'https://www.google.com/maps?q=' . urlencode($event['lat'] . ',' . $event['lng'])
                            : '';
                    ?>
                    <div class="list-group-item ride-event-row <?= $isResolvedEvent ? 'bg-light' : '' ?>" id="ride-event-<?= (int)$event['id'] ?>">
                        <div class="d-flex align-items-start gap-2">
                            <span class="d-inline-block rounded-circle flex-shrink-0 mt-1" style="width:12px;height:12px;background:<?= h($dotColor) ?>"></span>
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <strong><?= h($event['title']) ?></strong>
                                    <?php if ($isResolvedEvent): ?>
                                        <span class="badge bg-success">Επιλύθηκε</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Ενεργό</span>
                                    <?php endif; ?>
                                    <span class="text-muted small"><?= formatDateTime($event['created_at']) ?></span>
                                </div>
                                <?php if (!empty($event['message'])): ?>
                                    <div class="small mt-1"><?= h($event['message']) ?></div>
                                <?php endif; ?>
                                <div class="text-muted small mt-1">
                                    <?php if (!empty($event['user_name'])): ?>
                                        <span class="me-2"><i class="bi bi-person me-1"></i><?= h($event['user_name']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($isResolvedEvent): ?>
                                        <span><i class="bi bi-check2-circle me-1"></i>Από <?= h($event['resolved_by_name'] ?: '-') ?> στις <?= formatDateTime($event['resolved_at']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-1 flex-shrink-0">
                                <?php if ($mapsEventUrl): ?>
                                    <a href="<?= h($mapsEventUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary" title="Θέση">
                                        <i class="bi bi-geo-alt-fill"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($canResolveRideEvents && !$isResolvedEvent): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success ride-resolve-btn" data-event-id="<?= (int)$event['id'] ?>" title="Το χειρίστηκα">
                                        <i class="bi bi-check2-circle"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($debrief): ?>
        <!-- Mission Debrief -->
        <div class="card mb-4" style="border:2px solid #198754;border-radius:10px;overflow:hidden">
            <div class="card-header d-flex justify-content-between align-items-center py-2" style="background:linear-gradient(135deg,#d1f0e0 0%,#eafaf1 100%);border-bottom:2px solid #198754">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-clipboard-check-fill me-2 text-success"></i>Αναφορά Μετά τη Δράση (Debrief)</h6>
                <span class="badge text-dark fw-normal" style="background:#b7e4c7;font-size:0.78rem"><i class="bi bi-person-fill me-1"></i><?= h($debrief['submitter_name']) ?></span>
            </div>
            <div class="card-body py-3 px-3">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="p-2 rounded-2 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-muted small mb-1">Επίτευξη Στόχων</div>
                            <?php if ($debrief['objectives_met'] === 'YES'): ?>
                                <span class="badge bg-success px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Πλήρως</span>
                            <?php elseif ($debrief['objectives_met'] === 'PARTIAL'): ?>
                                <span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-exclamation-circle-fill me-1"></i>Μερικώς</span>
                            <?php else: ?>
                                <span class="badge bg-danger px-2 py-1"><i class="bi bi-x-circle-fill me-1"></i>Όχι</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 rounded-2 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-muted small mb-1">Αξιολόγηση</div>
                            <span class="text-warning fw-bold" style="font-size:1rem;letter-spacing:1px"><?= str_repeat('★', $debrief['rating']) ?><span style="opacity:.3"><?= str_repeat('★', 5 - $debrief['rating']) ?></span></span>
                            <span class="text-muted small ms-1"><?= $debrief['rating'] ?>/5</span>
                        </div>
                    </div>
                </div>

                <div class="mb-2">
                    <div class="text-muted small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em"><i class="bi bi-text-paragraph me-1"></i>Σύνοψη</div>
                    <div class="p-2 rounded-2 text-dark" style="background:#f8fffe;border:1px solid #d1f0e0;font-size:.93rem">
                        <?= nl2br(h($debrief['summary'])) ?>
                    </div>
                </div>

                <?php if ($debrief['incidents']): ?>
                <div class="mb-2">
                    <div class="text-danger small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em"><i class="bi bi-exclamation-triangle-fill me-1"></i>Συμβάντα / Ατυχήματα</div>
                    <div class="p-2 rounded-2 text-dark" style="background:#fff5f5;border-left:3px solid #dc3545;font-size:.93rem">
                        <?= nl2br(h($debrief['incidents'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($debrief['equipment_issues']): ?>
                <div class="mb-2">
                    <div class="small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em;color:#856404"><i class="bi bi-tools me-1"></i>Προβλήματα Εξοπλισμού</div>
                    <div class="p-2 rounded-2 text-dark" style="background:#fffdf0;border-left:3px solid #ffc107;font-size:.93rem">
                        <?= nl2br(h($debrief['equipment_issues'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="text-muted mt-2" style="font-size:.78rem;text-align:right">
                    <i class="bi bi-clock me-1"></i>Υποβλήθηκε: <?= formatDateTime($debrief['created_at']) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Shifts -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-calendar3 me-1"></i>Σκέλη</h5>
                <?php if ($canManageMissions && $mission['status'] !== STATUS_COMPLETED): ?>
                    <a href="shift-form.php?mission_id=<?= $mission['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg"></i> Νέο Σκέλος
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($shifts)): ?>
                    <p class="text-muted text-center py-4">Δεν υπάρχουν βάρδιες.</p>
                <?php else: ?>
                    <!-- Desktop/Tablet table view (hidden on portrait phones) -->
                    <div class="table-responsive d-none d-sm-block">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Ώρες</th>
                                    <th>Θέσεις</th>
                                    <th>Κατάσταση</th>
                                    <th class="text-end">Ενέργειες</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shifts as $shift): ?>
                                    <tr>
                                        <td>
                                            <strong><?= formatDateTime($shift['start_time'], 'd/m/Y') ?></strong><br>
                                            <small><?= formatDateTime($shift['start_time'], 'H:i') ?> - <?= formatDateTime($shift['end_time'], 'H:i') ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?= $shift['approved_count'] ?></span>
                                            <span class="text-muted">/</span>
                                            <span class="badge bg-secondary"><?= $shift['max_volunteers'] ?></span>
                                            <?php if ($shift['pending_count'] > 0): ?>
                                                <span class="badge bg-warning ms-1"><?= $shift['pending_count'] ?> εκκρεμείς</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $isFull = $shift['approved_count'] >= $shift['max_volunteers'];
                                            $isPast = strtotime($shift['end_time']) < time();
                                            
                                            if ($isPast) {
                                                echo '<span class="badge bg-secondary">Ολοκληρώθηκε</span>';
                                            } elseif ($isFull) {
                                                echo '<span class="badge bg-danger">Πλήρης</span>';
                                            } else {
                                                echo '<span class="badge bg-success">Διαθέσιμη</span>';
                                            }
                                            ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!$canManageMissions): ?>
                                                <div class="d-flex gap-1 justify-content-end flex-wrap align-items-center">
                                                    <a href="shift-view.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-primary" title="Προβολή βάρδιας">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <?php if (isset($userParticipations[$shift['id']])): ?>
                                                        <?= statusBadge($userParticipations[$shift['id']]['status'], 'participation') ?>
                                                        <?php if ($userParticipations[$shift['id']]['status'] === PARTICIPATION_APPROVED && !$isPast): ?>
                                                            <a href="shift-swap.php?participation_id=<?= $userParticipations[$shift['id']]['id'] ?>"
                                                               class="btn btn-sm btn-outline-secondary" title="Αίτημα Αντικατάστασης">
                                                                <i class="bi bi-arrow-left-right"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php elseif (!$isPast && !$isFull && $mission['status'] === STATUS_OPEN && !$isOverdue): ?>
                                                        <button type="button" class="btn btn-sm btn-primary apply-btn" 
                                                                data-shift-id="<?= $shift['id'] ?>"
                                                                data-shift-date="<?= formatDateTime($shift['start_time'], 'd/m/Y H:i') ?>">
                                                            <i class="bi bi-hand-index"></i> Αίτηση
                                                        </button>
                                                    <?php elseif ($isOverdue && $mission['status'] === STATUS_OPEN): ?>
                                                        <span class="badge bg-secondary" title="Ο χρόνος διεξαγωγής έχει παρέλθει" data-bs-toggle="tooltip">
                                                            <i class="bi bi-clock-history me-1"></i>Έληξε
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <a href="shift-view.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="shift-form.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile card view (visible only on portrait phones <576px) -->
                    <div class="d-sm-none mobile-cards-container p-2">
                        <?php foreach ($shifts as $shift): ?>
                            <?php
                            $isFull = $shift['approved_count'] >= $shift['max_volunteers'];
                            $isPast = strtotime($shift['end_time']) < time();
                            ?>
                            <div class="card mobile-card <?= $isPast ? 'border-secondary' : '' ?>">
                                <div class="card-body <?= $isPast ? 'text-muted' : '' ?>">
                                    <div class="mobile-card-header">
                                        <div>
                                            <strong><?= formatDateTime($shift['start_time'], 'd/m/Y') ?></strong>
                                            <br><small><?= formatDateTime($shift['start_time'], 'H:i') ?> - <?= formatDateTime($shift['end_time'], 'H:i') ?></small>
                                        </div>
                                        <div>
                                            <?php if ($isPast): ?>
                                                <span class="badge bg-secondary">Ολοκληρώθηκε</span>
                                            <?php elseif ($isFull): ?>
                                                <span class="badge bg-danger">Πλήρης</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Διαθέσιμη</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mobile-card-row">
                                        <div class="mobile-card-label">Θέσεις</div>
                                        <span class="badge bg-success"><?= $shift['approved_count'] ?></span>
                                        <span class="text-muted">/</span>
                                        <span class="badge bg-secondary"><?= $shift['max_volunteers'] ?></span>
                                        <?php if ($shift['pending_count'] > 0): ?>
                                            <span class="badge bg-warning ms-1"><?= $shift['pending_count'] ?> εκκρεμείς</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mobile-card-actions">
                                        <?php if (!$canManageMissions): ?>
                                            <a href="shift-view.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye me-1"></i>Προβολή
                                            </a>
                                            <?php if (isset($userParticipations[$shift['id']])): ?>
                                                <?= statusBadge($userParticipations[$shift['id']]['status'], 'participation') ?>
                                                <?php if ($userParticipations[$shift['id']]['status'] === PARTICIPATION_APPROVED && !$isPast): ?>
                                                    <a href="shift-swap.php?participation_id=<?= $userParticipations[$shift['id']]['id'] ?>"
                                                       class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-arrow-left-right me-1"></i>Αντικατάσταση
                                                    </a>
                                                <?php endif; ?>
                                            <?php elseif (!$isPast && !$isFull && $mission['status'] === STATUS_OPEN && !$isOverdue): ?>
                                                <button type="button" class="btn btn-sm btn-primary apply-btn" 
                                                        data-shift-id="<?= $shift['id'] ?>"
                                                        data-shift-date="<?= formatDateTime($shift['start_time'], 'd/m/Y H:i') ?>">
                                                    <i class="bi bi-hand-index me-1"></i>Αίτηση
                                                </button>
                                            <?php elseif ($isOverdue && $mission['status'] === STATUS_OPEN): ?>
                                                <span class="badge bg-secondary" title="Ο χρόνος διεξαγωγής έχει παρέλθει">
                                                    <i class="bi bi-clock-history me-1"></i>Έληξε
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <a href="shift-view.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye me-1"></i>Προβολή
                                            </a>
                                            <a href="shift-form.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-pencil me-1"></i>Επεξεργασία
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Actions -->
        <?php if ($canManageMissions || $isResponsible): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Ενέργειες</h5>
                </div>
                <div class="card-body d-grid gap-2">
                    <?php if ($canManageMissions && $mission['status'] !== STATUS_COMPLETED): ?>
                    <button type="button" class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#addVolunteerModal">
                        <i class="bi bi-person-plus me-1"></i>Προσθήκη Μέλους
                    </button>
                    <?php endif; ?>

                    <?php if ($mission['status'] !== STATUS_DRAFT): ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="send_ride_link">
                        <button type="submit" class="btn btn-outline-primary w-100">
                            <i class="bi bi-send-check me-1"></i>Αποστολή Ride Link
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if (in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED], true)): ?>
                    <button type="button" class="btn btn-dark w-100" data-bs-toggle="modal" data-bs-target="#closeRideModal">
                        <i class="bi bi-flag-fill me-1"></i>Κλείσιμο Διαδρομής
                    </button>
                    <?php endif; ?>

                    <?php if ($canManageMissions && $mission['status'] === STATUS_DRAFT): ?>
                        <form method="post" id="publishForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="publish">

                            <!-- Notify toggle -->
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="notify_volunteers"
                                       id="notifyVolunteers" value="1" checked
                                       onchange="toggleNotifyPanel(this.checked)">
                                <label class="form-check-label small fw-semibold" for="notifyVolunteers">
                                    <i class="bi bi-envelope me-1"></i>Αποστολή ειδοποίησης Email
                                </label>
                            </div>

                            <!-- Targeting panel (visible when checked) -->
                            <div id="notifyPanel" class="border rounded p-2 mb-3 bg-light small">
                                <div class="mb-2 text-muted fw-semibold">Παραλήπτες:</div>

                                <!-- All -->
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="notify_target"
                                           id="targetAll" value="all" checked
                                           onchange="toggleTargetGroups()">
                                    <label class="form-check-label" for="targetAll">
                                        <i class="bi bi-people me-1 text-primary"></i>Όλοι οι ενεργοί χρήστες
                                    </label>
                                </div>

                                <!-- By Role -->
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="notify_target"
                                           id="targetRoles" value="roles"
                                           onchange="toggleTargetGroups()">
                                    <label class="form-check-label" for="targetRoles">
                                        <i class="bi bi-shield-check me-1 text-success"></i>Ανά Ρόλο
                                    </label>
                                </div>
                                <div id="rolesGroup" class="ps-3 mb-2 d-none">
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_roles[]" value="SHIFT_LEADER" id="roleShiftLeader">
                                        <label class="form-check-label" for="roleShiftLeader">Road Captains</label>
                                    </div>
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_roles[]" value="VOLUNTEER" id="roleVolunteer">
                                        <label class="form-check-label" for="roleVolunteer">Μέλη</label>
                                    </div>
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_roles[]" value="SYSTEM_ADMIN" id="roleSysAdmin">
                                        <label class="form-check-label" for="roleSysAdmin">Διαχ. Συστήματος</label>
                                    </div>
                                </div>

                                <!-- By Volunteer Type -->
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="notify_target"
                                           id="targetVtypes" value="vtypes"
                                           onchange="toggleTargetGroups()">
                                    <label class="form-check-label" for="targetVtypes">
                                        <i class="bi bi-person-badge me-1 text-warning"></i>Ανά Τύπο Μέλους
                                    </label>
                                </div>
                                <div id="vtypesGroup" class="ps-3 d-none">
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_vtypes[]" value="TRAINEE_RESCUER" id="vtypeTrainee">
                                        <label class="form-check-label" for="vtypeTrainee">Δόκιμοι Διασώστες</label>
                                    </div>
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_vtypes[]" value="RESCUER" id="vtypeRescuer">
                                        <label class="form-check-label" for="vtypeRescuer">Μέλη Λέσχης</label>
                                    </div>
                                </div>

                                <?php if (!empty($publishPositions)): ?>
                                <!-- By Position -->
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="notify_target"
                                           id="targetPositions" value="positions"
                                           onchange="toggleTargetGroups()">
                                    <label class="form-check-label" for="targetPositions">
                                        <i class="bi bi-briefcase me-1 text-danger"></i>Ανά Θέση
                                    </label>
                                </div>
                                <div id="positionsGroup" class="ps-3 d-none">
                                    <?php foreach ($publishPositions as $pos): ?>
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox"
                                               name="notify_positions[]" value="<?= (int)$pos['id'] ?>"
                                               id="pos<?= (int)$pos['id'] ?>">
                                        <label class="form-check-label" for="pos<?= (int)$pos['id'] ?>">
                                            <?= h($pos['name']) ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                            </div>

                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-send me-1"></i>Δημοσίευση
                            </button>
                        </form>
                        <script>
                        function toggleNotifyPanel(checked) {
                            document.getElementById('notifyPanel').style.display = checked ? '' : 'none';
                        }
                        function toggleTargetGroups() {
                            var target = document.querySelector('input[name="notify_target"]:checked').value;
                            document.getElementById('rolesGroup').classList.toggle('d-none', target !== 'roles');
                            document.getElementById('vtypesGroup').classList.toggle('d-none', target !== 'vtypes');
                            var pg = document.getElementById('positionsGroup');
                            if (pg) pg.classList.toggle('d-none', target !== 'positions');
                        }
                        </script>
                    <?php endif; ?>
                    
                    <?php if ($mission['status'] === STATUS_OPEN): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="close">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bi bi-lock me-1"></i>Κλείσιμο Αιτήσεων
                            </button>
                        </form>
                        <?php if ($canManageMissions): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="resend_email">
                            <button type="submit" class="btn btn-outline-primary w-100">
                                <i class="bi bi-envelope-arrow-up me-1"></i>Επαναποστολή Email
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($mission['status'] === STATUS_CLOSED): ?>
                        <a href="attendance.php?mission_id=<?= $mission['id'] ?>" class="btn btn-info w-100">
                            <i class="bi bi-clipboard-check me-1"></i>Διαχείριση Παρουσιών
                        </a>
                        <?php if ($canManageMissions): ?>
                            <?php if ($debrief): ?>
                                <button type="button" class="btn btn-primary w-100" onclick="confirmDebriefEdit()">
                                    <i class="bi bi-check-circle me-1"></i>Ολοκλήρωση & Αναφορά
                                </button>
                                <script>
                                function confirmDebriefEdit() {
                                    if (confirm('Υπάρχει ήδη αναφορά (debrief) για αυτή την αποστολή.\n\nΘέλετε να την επεξεργαστείτε;\n\nΠατήστε "OK" για επεξεργασία, ή "Ακύρωση" για απλή ολοκλήρωση της αποστολής χωρίς αλλαγή στην αναφορά.')) {
                                        window.location.href = 'mission-debrief.php?id=<?= $mission['id'] ?>';
                                    } else {
                                        document.getElementById('completeOnlyForm').submit();
                                    }
                                }
                                </script>
                                <form id="completeOnlyForm" method="post" style="display:none;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="complete_only">
                                </form>
                            <?php else: ?>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="complete">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-check-circle me-1"></i>Ολοκλήρωση & Αναφορά
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($isResponsible): ?>
                            <a href="mission-debrief.php?id=<?= $mission['id'] ?>" class="btn btn-primary w-100">
                                <i class="bi bi-journal-text me-1"></i>Συμπλήρωση Αναφοράς
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($mission['status'] === STATUS_COMPLETED): ?>
                        <?php if ($canManageMissions): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reopen_to_closed">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bi bi-unlock me-1"></i>Επαναφορά σε «Κλειστή»
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($isResponsible && !$canManageMissions): ?>
                            <a href="mission-debrief.php?id=<?= $mission['id'] ?>" class="btn btn-outline-primary w-100">
                                <i class="bi bi-journal-text me-1"></i>Επεξεργασία Αναφοράς
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($canManageMissions): ?>
                    <?php if (in_array($mission['status'], [STATUS_DRAFT, STATUS_OPEN])): ?>
                        <hr>
                        <button type="button" class="btn btn-outline-danger w-100 mb-2" data-bs-toggle="modal" data-bs-target="#cancelModal">
                            <i class="bi bi-x-circle me-1"></i>Ακύρωση Δράσης
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-danger w-100" data-bs-toggle="modal" data-bs-target="#deleteMissionModal">
                        <i class="bi bi-trash me-1"></i>Διαγραφή Δράσης
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($rideReadiness): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-speedometer2 me-1 text-success"></i>Ride Readiness</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Εγκεκριμένοι:</span>
                    <strong><?= (int)$rideReadiness['approved'] ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Στέλνουν GPS:</span>
                    <strong class="text-success"><?= (int)$rideReadiness['tracking'] ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Δεν ξεκίνησαν:</span>
                    <strong class="text-muted"><?= (int)$rideReadiness['not_started'] ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Stale:</span>
                    <strong class="text-warning"><?= (int)$rideReadiness['stale'] ?></strong>
                </div>
                <div class="progress mt-3" style="height:8px;">
                    <div class="progress-bar bg-success" style="width:<?= min(100, max(0, (int)$rideReadiness['ready_percent'])) ?>%"></div>
                </div>
                <div class="text-muted small mt-1"><?= (int)$rideReadiness['ready_percent'] ?>% έτοιμοι σε Ride Mode</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Στατιστικά</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Σύνολο Βαρδιών:</span>
                    <strong><?= count($shifts) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Εγκεκριμένα Μέλη:</span>
                    <strong><?= array_sum(array_column($shifts, 'approved_count')) ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Εκκρεμείς Αιτήσεις:</span>
                    <strong><?= array_sum(array_column($shifts, 'pending_count')) ?></strong>
                </div>
            </div>
        </div>

        <!-- Weather Widget -->
        <?php if ($weather !== null): ?>
        <div class="card mt-4">
            <?php if ($weather['status'] === 'ok'): ?>
            <div class="card-header py-2 <?= $weather['severity'] === 'danger' ? 'bg-danger bg-opacity-10' : ($weather['severity'] === 'warning' ? 'bg-warning bg-opacity-10' : 'bg-light') ?>">
                <h6 class="mb-0"><i class="bi bi-cloud-sun me-1 text-primary"></i>Καιρός Δράσης</h6>
            </div>
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <img src="https://openweathermap.org/img/wn/<?= h($weather['icon']) ?>@2x.png"
                         width="50" height="50" alt="<?= h($weather['description']) ?>"
                         style="margin:-6px 0 -6px -4px;">
                    <div>
                        <div class="fw-bold" style="font-size:1.3rem;"><?= $weather['temp'] ?>&deg;C</div>
                        <div class="text-muted small"><?= h($weather['description']) ?></div>
                    </div>
                </div>
                <div class="d-flex gap-3 small text-muted mb-2">
                    <span title="Αίσθηση"><i class="bi bi-thermometer-half"></i> <?= $weather['feels_like'] ?>&deg;C</span>
                    <span><i class="bi bi-wind"></i> <?= $weather['wind_speed'] ?> m/s</span>
                    <span><i class="bi bi-droplet-half"></i> <?= $weather['humidity'] ?>%</span>
                </div>
                <?php if (!empty($weather['warnings'])): ?>
                <div class="alert alert-<?= $weather['severity'] === 'danger' ? 'danger' : 'warning' ?> py-2 px-3 mb-2 small">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <?php foreach ($weather['warnings'] as $warn): ?>
                        <div><?= h($warn) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($weather['fallback_location'])): ?>
                <div class="alert alert-info py-1 px-2 mb-2 small">
                    <i class="bi bi-geo-alt me-1"></i>Δεν έχει οριστεί σημείο στο χάρτη. Εμφανίζεται πρόβλεψη για <strong>Ηράκλειο Κρήτης</strong>.
                </div>
                <?php endif; ?>
                <div class="text-muted" style="font-size:0.68rem;">
                    Πρόβλεψη για <?= date('d/m H:i', $weather['forecast_dt']) ?> &middot; Πηγή: OpenWeatherMap
                </div>
            </div>
            <?php elseif ($weather['status'] === 'too_far'): ?>
            <div class="card-header py-2 bg-light">
                <h6 class="mb-0"><i class="bi bi-cloud-sun me-1 text-muted"></i>Καιρός Δράσης</h6>
            </div>
            <div class="card-body py-2 px-3">
                <p class="text-muted small mb-0">
                    <i class="bi bi-calendar3 me-1"></i>Πρόβλεψη διαθέσιμη από <strong><?= h($weather['available_from']) ?></strong>
                </p>
            </div>
            <?php elseif ($canManageMissions && $weather['status'] === 'api_error'): ?>
            <div class="card-header py-2 bg-light">
                <h6 class="mb-0"><i class="bi bi-cloud-slash me-1 text-muted"></i>Καιρός Δράσης</h6>
            </div>
            <div class="card-body py-2 px-3">
                <p class="text-muted small mb-0"><i class="bi bi-exclamation-circle me-1"></i><?= h($weather['message'] ?? 'Σφάλμα λήψης καιρού') ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($seriesMissions)): ?>
        <!-- ── Series Panel ─────────────────────────────────────────────── -->
        <div class="card mt-4">
            <div class="card-header bg-info bg-opacity-10 d-flex justify-content-between align-items-center"
                 style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#seriesCollapse">
                <h6 class="mb-0">
                    <i class="bi bi-arrow-repeat me-2 text-info"></i>Σειρά Δράσεων
                    <span class="badge bg-info text-dark ms-1"><?= count($seriesMissions) ?></span>
                </h6>
                <i class="bi bi-chevron-down text-muted"></i>
            </div>
            <div class="collapse show" id="seriesCollapse">
                <div class="card-body p-0">
                    <?php if ($recurrenceInfo): ?>
                    <div class="px-3 py-2 border-bottom bg-light small text-muted">
                        <?php
                        $typeLabels = ['weekly' => 'Εβδομαδιαία', 'random_days' => 'Επιλεγμένες ημερομηνίες', 'interval' => 'Σταθερό διάστημα'];
                        echo h($typeLabels[$recurrenceInfo['type']] ?? $recurrenceInfo['type']);
                        if ($recurrenceInfo['interval_days']) {
                            echo ' · κάθε ' . (int)$recurrenceInfo['interval_days'] . ' ημέρες';
                        }
                        echo ' · έως ' . formatDateGreek($recurrenceInfo['end_date']);
                        ?>
                    </div>
                    <?php endif; ?>
                    <ul class="list-group list-group-flush" style="max-height:320px;overflow-y:auto;">
                        <?php foreach ($seriesMissions as $sm):
                            $isCurrent = $sm['id'] == $id;
                        ?>
                        <li class="list-group-item px-3 py-2<?= $isCurrent ? ' bg-info bg-opacity-10 fw-semibold' : '' ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <?php if ($isCurrent): ?>
                                        <i class="bi bi-arrow-right-circle-fill text-info me-1"></i>
                                    <?php endif; ?>
                                    <a href="mission-view.php?id=<?= $sm['id'] ?>" class="text-decoration-none">
                                        <?= formatDate($sm['start_datetime']) ?>
                                    </a>
                                    <small class="text-muted ms-1"><?= formatDateTime($sm['start_datetime'], 'H:i') ?></small>
                                </div>
                                <?= statusBadge($sm['status']) ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php if (($canManageMissions || $isResponsible) && in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED], true) && $rideClosureSummary): ?>
<!-- Close Ride Modal -->
<div class="modal fade" id="closeRideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="close_ride">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-flag-fill me-1"></i>Κλείσιμο Διαδρομής</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (($rideClosureSummary['active_event_count'] ?? 0) > 0): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Υπάρχουν ακόμα <strong><?= (int)$rideClosureSummary['active_event_count'] ?></strong> ενεργά συμβάντα. Μπορείτε να κλείσετε τη διαδρομή, αλλά καλό είναι να τα ελέγξετε.
                    </div>
                    <?php endif; ?>

                    <div class="row g-2 mb-3">
                        <div class="col-md-3 col-6">
                            <div class="border rounded p-2 h-100 bg-light">
                                <div class="text-muted small">Μέλη</div>
                                <div class="fw-bold fs-5"><?= (int)$rideClosureSummary['readiness']['approved'] ?></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="border rounded p-2 h-100 bg-light">
                                <div class="text-muted small">GPS ενεργοί</div>
                                <div class="fw-bold fs-5 text-success"><?= (int)$rideClosureSummary['readiness']['tracking'] ?></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="border rounded p-2 h-100 bg-light">
                                <div class="text-muted small">Συμβάντα</div>
                                <div class="fw-bold fs-5 <?= ($rideClosureSummary['event_count'] ?? 0) > 0 ? 'text-warning' : 'text-success' ?>"><?= (int)$rideClosureSummary['event_count'] ?></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="border rounded p-2 h-100 bg-light">
                                <div class="text-muted small">Checklist</div>
                                <div class="fw-bold fs-5"><?= (int)$rideClosureSummary['checklist']['completed'] ?>/<?= (int)$rideClosureSummary['checklist']['total'] ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Αυτόματη σύνοψη</label>
                        <pre class="bg-light border rounded p-2 small mb-0" style="white-space:pre-wrap;font-family:inherit;max-height:190px;overflow:auto;"><?= h($rideClosureSummary['summary']) ?></pre>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="closure_objectives_met" class="form-label fw-bold">Επιτεύχθηκαν οι στόχοι;</label>
                            <select class="form-select" id="closure_objectives_met" name="closure_objectives_met">
                                <option value="YES">Ναι, πλήρως</option>
                                <option value="PARTIAL">Μερικώς</option>
                                <option value="NO">Όχι</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="closure_rating" class="form-label fw-bold">Αξιολόγηση</label>
                            <select class="form-select" id="closure_rating" name="closure_rating">
                                <option value="5">5/5</option>
                                <option value="4">4/5</option>
                                <option value="3">3/5</option>
                                <option value="2">2/5</option>
                                <option value="1">1/5</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label for="closure_incidents" class="form-label fw-bold">Συμβάντα / SOS / Βλάβες</label>
                        <textarea class="form-control" id="closure_incidents" name="closure_incidents" rows="3"><?= h($rideClosureSummary['incidents']) ?></textarea>
                    </div>

                    <div class="mt-3">
                        <label for="closure_equipment_issues" class="form-label fw-bold">Θέματα εξοπλισμού / μηχανών</label>
                        <textarea class="form-control" id="closure_equipment_issues" name="closure_equipment_issues" rows="2" placeholder="Π.χ. λάστιχο, βλάβη, ανάγκη συνεργείου, εξοπλισμός που λείπει..."></textarea>
                    </div>

                    <div class="mt-3">
                        <label for="closure_notes" class="form-label fw-bold">Σημειώσεις admin</label>
                        <textarea class="form-control" id="closure_notes" name="closure_notes" rows="3" placeholder="Τελικές παρατηρήσεις για τη διαδρομή..."></textarea>
                    </div>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="notify_participants" name="notify_participants" value="1" checked>
                        <label class="form-check-label" for="notify_participants">
                            Αποστολή σύνοψης / Ride Report στους συμμετέχοντες
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Άκυρο</button>
                    <button type="submit" class="btn btn-dark">
                        <i class="bi bi-check2-circle me-1"></i>Κλείσιμο & Ολοκλήρωση
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="cancel">
                <div class="modal-header">
                    <h5 class="modal-title">Ακύρωση Δράσης</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Η ακύρωση θα ειδοποιήσει όλους τους εγγεγραμμένους εθελοντές.
                    </div>
                    <div class="mb-3">
                        <label for="cancellation_reason" class="form-label">Λόγος Ακύρωσης</label>
                        <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Κλείσιμο</button>
                    <button type="submit" class="btn btn-danger">Ακύρωση Δράσης</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Mission Modal -->
<?php
$allMissionParticipants = [];
$shiftIds = array_column($shifts, 'id');
if (!empty($shiftIds)) {
    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
    $allMissionParticipants = dbFetchAll(
        "SELECT DISTINCT u.name, pr.status 
         FROM participation_requests pr 
         JOIN users u ON pr.volunteer_id = u.id 
         WHERE pr.shift_id IN ($placeholders) AND pr.status IN (?,?)",
        array_merge($shiftIds, [PARTICIPATION_PENDING, PARTICIPATION_APPROVED])
    );
}
?>
<div class="modal fade" id="deleteMissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Διαγραφή Δράσης</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        <strong>Προσοχή!</strong> Αυτή η ενέργεια δεν μπορεί να αναιρεθεί.
                    </div>
                    
                    <?php if (count($allMissionParticipants) > 0): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-people me-1"></i>
                            <strong>Οι παρακάτω <?= count($allMissionParticipants) ?> αιτήσεις θα ακυρωθούν:</strong>
                        </div>
                        <ul class="list-group mb-3" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($allMissionParticipants as $p): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                    <span>
                                        <i class="bi bi-person me-1"></i><?= h($p['name']) ?>
                                    </span>
                                    <?php if ($p['status'] === 'APPROVED'): ?>
                                        <span class="badge bg-success">Εγκεκριμένη</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Εκκρεμεί</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="text-muted mb-0">
                            <i class="bi bi-bell me-1"></i>
                            Οι εθελοντές θα ειδοποιηθούν αυτόματα για την ακύρωση.
                        </p>
                    <?php else: ?>
                        <p>Η αποστολή δεν έχει ενεργές αιτήσεις συμμετοχής.</p>
                    <?php endif; ?>
                    
                    <hr>
                    <p class="mb-0"><strong>Είστε σίγουροι ότι θέλετε να διαγράψετε την αποστολή "<?= h($mission['title']) ?>";</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Διαγραφή
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="rideReplayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-film me-1"></i>Ride Replay</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="rideReplayMap" style="height:360px;"></div>
                <div class="p-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <span id="rideReplayKm" class="fw-bold">0 m</span>
                            <span class="text-muted mx-1">&middot;</span>
                            <span id="rideReplayTime" class="fw-bold">0 λεπτά</span>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" id="rideReplayRestart" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                            <button type="button" id="rideReplayPlayPause" class="btn btn-sm btn-primary">
                                <i class="bi bi-play-fill"></i>
                            </button>
                        </div>
                    </div>
                    <input type="range" id="rideReplayScrubber" class="form-range" min="0" max="1" step="0.001" value="0">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Apply Modal (for volunteers) -->
<?php if (!$canManageMissions): ?>
<div class="modal fade" id="applyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="shift_id" id="applyShiftId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-hand-index me-1"></i>Αίτηση Συμμετοχής</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Αίτηση για τη βάρδια: <strong id="applyShiftDate"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις (προαιρετικά)</label>
                        <textarea class="form-control" name="volunteer_notes" rows="3" 
                                  placeholder="Π.χ. διαθεσιμότητα, εμπειρία, ειδικές δεξιότητες..."></textarea>
                        <small class="text-muted">Οι σημειώσεις θα είναι ορατές στους διαχειριστές.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Υποβολή Αίτησης
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.apply-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('applyShiftId').value = this.getAttribute('data-shift-id');
        document.getElementById('applyShiftDate').textContent = this.getAttribute('data-shift-date');
        var modal = new bootstrap.Modal(document.getElementById('applyModal'));
        modal.show();
    });
});
</script>
<?php endif; ?>

<!-- Add Volunteer Modal (for admins) -->
<?php if ($canManageMissions): ?>
<div class="modal fade" id="addVolunteerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="manual_add_volunteer">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i>Προσθήκη Μέλους</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Επιλογή Χρήστη</label>
                        <select class="form-select" name="volunteer_id" required>
                            <option value="">-- Επιλέξτε χρήστη --</option>
                            <?php foreach ($availableVolunteers as $vol): ?>
                                <option value="<?= $vol['id'] ?>">
                                    <?= h($vol['name']) ?> (<?= h($vol['email']) ?>) — <?= h(ROLE_LABELS[$vol['role']] ?? $vol['role']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>Επιλογή Βαρδιών</strong> (μπορείτε να επιλέξετε πολλές)</label>
                        <?php if (empty($shifts)): ?>
                            <p class="text-muted">Δεν υπάρχουν διαθέσιμες βάρδιες.</p>
                        <?php else: ?>
                            <div class="list-group" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($shifts as $shift): ?>
                                    <label class="list-group-item d-flex align-items-start">
                                        <input class="form-check-input me-2 mt-1" type="checkbox" 
                                               name="shift_ids[]" value="<?= $shift['id'] ?>">
                                        <div class="flex-grow-1">
                                            <div><strong><?= formatDateTime($shift['start_time'], 'd/m/Y') ?></strong></div>
                                            <small class="text-muted">
                                                <?= formatDateTime($shift['start_time'], 'H:i') ?> - 
                                                <?= formatDateTime($shift['end_time'], 'H:i') ?>
                                                | <?= $shift['approved_count'] ?>/<?= $shift['max_volunteers'] ?> εθελοντές
                                            </small>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις Διαχειριστή (προαιρετικά)</label>
                        <textarea class="form-control" name="admin_notes" rows="2" 
                                  placeholder="Λόγος χειροκίνητης ανάθεσης..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        Ο εθελοντής θα προστεθεί αυτόματα ως <strong>εγκεκριμένος</strong> σε όλες τις επιλεγμένες βάρδιες και θα λάβει ειδοποιήσεις.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i>Ακύρωση
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-person-plus me-1"></i>Προσθήκη σε Επιλεγμένα Σκέλη
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Mission Chat Section -->
<?php if ($canAccessChat): ?>
<div class="card shadow-sm mb-4" id="chat">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-chat-dots me-2"></i>Συζήτηση Δράσης
            <?php if (!$canViewAllMissions): ?>
                <small class="ms-2">(Μόνο εγκεκριμένα μέλη)</small>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="chat-messages mb-3" style="max-height: 400px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; background: #f8f9fa;">
            <?php if (empty($chatMessages)): ?>
                <p class="text-muted text-center mb-0">
                    <i class="bi bi-chat-square-text me-2"></i>Δεν υπάρχουν μηνύματα ακόμα. Ξεκινήστε τη συζήτηση!
                </p>
            <?php else: ?>
                <?php foreach ($chatMessages as $msg): ?>
                    <div class="chat-message mb-3 <?= $msg['user_id'] == $user['id'] ? 'text-end' : '' ?>">
                        <div class="d-inline-block position-relative <?= $msg['user_id'] == $user['id'] ? 'bg-primary text-white' : 'bg-white border' ?>" 
                             style="max-width: 70%; padding: 10px 15px; border-radius: 15px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                            <?php if ($canManageMissions): ?>
                                <form method="post" class="position-absolute" style="top: 5px; right: 5px;" onsubmit="return confirm('Θέλετε να διαγράψετε αυτό το μήνυμα;');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_chat_message">
                                    <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding: 2px 6px; font-size: 0.7rem;">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <div class="fw-bold mb-1" style="font-size: 0.85rem;">
                                <?= h($msg['user_name']) ?>
                                <?= $msg['user_id'] == $user['id'] ? '(Εσείς)' : '' ?>
                            </div>
                            <div style="word-wrap: break-word;"><?= nl2br(h($msg['message'])) ?></div>
                            <div class="text-<?= $msg['user_id'] == $user['id'] ? 'white-50' : 'muted' ?> mt-1" style="font-size: 0.75rem;">
                                <?= formatDateTime($msg['created_at']) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div id="chatEnd"></div>
        </div>
        
        <form method="post" id="chatForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_chat_message">
            <div class="mb-2">
                <textarea class="form-control" name="message" id="chatMessage" rows="3" placeholder="Γράψτε το μήνυμά σας..." required style="resize: vertical;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-send"></i> Αποστολή
            </button>
        </form>
    </div>
</div>

<script>
// Auto-scroll to bottom of chat on load
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.querySelector('.chat-messages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Scroll to chat if hash is #chat
    if (window.location.hash === '#chat') {
        document.getElementById('chat').scrollIntoView({ behavior: 'smooth' });
    }

});
</script>
<?php endif; ?>

<?php if ($canViewRideEvents): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.ride-resolve-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            var eventId = button.getAttribute('data-event-id');
            var body = new URLSearchParams({
                csrf_token: <?= json_encode(csrfToken()) ?>,
                event_id: eventId
            });
            button.disabled = true;
            fetch('api-ride-event-resolve.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.ok) {
                    alert(data.error || 'Δεν επιλύθηκε το συμβάν.');
                    button.disabled = false;
                    return;
                }
                window.location.reload();
            })
            .catch(function() {
                alert('Δεν επιλύθηκε το συμβάν.');
                button.disabled = false;
            });
        });
    });
});
</script>
<?php endif; ?>

<?php if ((!$isMultiDayMission && ((!empty($mission['latitude']) && !empty($mission['longitude'])) || !empty($routePoints))) || $isMultiDayMission): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php if (!$isMultiDayMission): ?>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ride-replay.js?v=<?= APP_VERSION ?>"></script>
<script>
(function () {
    var lat = <?= !empty($mission['latitude']) ? (float)$mission['latitude'] : 'null' ?>;
    var lng = <?= !empty($mission['longitude']) ? (float)$mission['longitude'] : 'null' ?>;
    var label = <?= json_encode($mission['location']) ?>;
    var routePoints = <?= json_encode($routePoints, JSON_UNESCAPED_UNICODE) ?>;
    var routeGeometry = <?= json_encode($routeGeometry) ?>;
    window.easyRideReplayData = {
        points: routeGeometry,
        distanceMeters: <?= json_encode((float)($routeMetrics['distance_meters'] ?? 0)) ?>,
        distanceLabel: <?= json_encode((string)($routeMetrics['distance_label'] ?? '')) ?>,
        totalMinutes: <?= json_encode((int)($routeMetrics['total_minutes'] ?? 0)) ?>,
        totalLabel: <?= json_encode((string)($routeMetrics['total_label'] ?? '')) ?>,
        events: <?= json_encode($rideReplayEvents, JSON_UNESCAPED_UNICODE) ?>
    };
    var center = routePoints.length ? [routePoints[0].lat, routePoints[0].lng] : [lat, lng];
    var map = L.map('missionMap', { zoomControl: true, scrollWheelZoom: false }).setView(center, 15);
    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[ch];
        });
    }
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19
    }).addTo(map);
    if (lat !== null && lng !== null) {
        L.marker([lat, lng]).addTo(map).bindPopup(label);
    }
    if (routePoints.length) {
        var routeLatLngs = routePoints.map(function(point) { return [point.lat, point.lng]; });
        routeLatLngs.forEach(function(latLng, index) {
            var point = routePoints[index] || {};
            var popup = '<strong>' + esc(point.title || ('Σημείο ' + (index + 1))) + '</strong>';
            if (point.scheduled_time) popup += '<br>Ώρα: ' + esc(point.scheduled_time);
            if (point.stop_minutes > 0) popup += '<br>Στάση: ' + parseInt(point.stop_minutes, 10) + ' λεπτά';
            if (point.notes) popup += '<br><small>' + esc(point.notes) + '</small>';
            L.circleMarker(latLng, {
                radius: 5,
                color: '#0d6efd',
                fillColor: '#0d6efd',
                fillOpacity: 0.9,
                weight: 2
            }).addTo(map).bindTooltip(String(index + 1), {
                permanent: true,
                direction: 'center',
                className: 'route-point-label'
            }).bindPopup(popup);
        });
        if (routeLatLngs.length >= 2) {
            L.polyline(routeGeometry.length >= 2 ? routeGeometry : routeLatLngs, {
                color: '#0d6efd',
                weight: 5,
                opacity: 0.85
            }).addTo(map);
        }
        map.fitBounds(L.latLngBounds(routeGeometry.length >= 2 ? routeGeometry.concat(routeLatLngs) : routeLatLngs), { padding: [25, 25] });
    }
    setTimeout(function() { map.invalidateSize(); }, 150);
})();
</script>
<?php endif; ?>

<?php if ($isMultiDayMission): ?>
<script>
(function () {
    var days = <?= json_encode(array_map(function ($day) {
        return [
            'day_number' => (int)$day['day_number'],
            'date_label' => $day['date_label'],
            'overnight_notes' => (string)($day['overnight_notes'] ?? ''),
            'directions_url' => (string)($day['directions_url'] ?? ''),
            'points' => $day['route_points_decoded'],
            'geometry' => $day['geometry'],
            'metrics' => [
                'distance_label' => (string)($day['metrics']['distance_label'] ?? ''),
                'moving_label' => (string)($day['metrics']['moving_label'] ?? ''),
                'stop_label' => (string)($day['metrics']['stop_label'] ?? ''),
                'total_label' => (string)($day['metrics']['total_label'] ?? ''),
                'provider' => (string)($day['metrics']['provider'] ?? 'estimate'),
            ],
        ];
    }, $missionDays), JSON_UNESCAPED_UNICODE) ?>;
    var fallbackLat = <?= !empty($mission['latitude']) ? (float)$mission['latitude'] : 'null' ?>;
    var fallbackLng = <?= !empty($mission['longitude']) ? (float)$mission['longitude'] : 'null' ?>;

    var map = null;
    var markers = [];
    var polyline = null;

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[ch];
        });
    }

    function findDay(dayNumber) {
        for (var i = 0; i < days.length; i++) {
            if (days[i].day_number === dayNumber) return days[i];
        }
        return null;
    }

    function formatMetricsHtml(day) {
        if (!day.points.length) {
            return '<span class="text-muted small">Δεν έχουν οριστεί σημεία διαδρομής για αυτή τη μέρα.</span>';
        }
        var html = '<span class="badge bg-light text-dark border"><i class="bi bi-signpost-2 me-1"></i>' + esc(day.metrics.distance_label) + '</span>';
        html += '<span class="badge bg-light text-dark border"><i class="bi bi-stopwatch me-1"></i>Οδήγηση ' + esc(day.metrics.moving_label) + '</span>';
        html += '<span class="badge bg-warning text-dark"><i class="bi bi-cup-hot me-1"></i>Στάσεις ' + esc(day.metrics.stop_label) + '</span>';
        html += '<span class="badge bg-primary"><i class="bi bi-clock-history me-1"></i>Σύνολο ' + esc(day.metrics.total_label) + '</span>';
        html += day.metrics.provider === 'google'
            ? '<span class="text-success align-self-center"><i class="bi bi-google me-1"></i>Διαδρομή μέσω Google.</span>'
            : '<span class="text-muted align-self-center">Εκτίμηση σε ευθεία γραμμή.</span>';
        return html;
    }

    function formatTimelineHtml(day) {
        if (!day.points.length) {
            return '<div class="list-group-item text-muted small">Δεν έχουν οριστεί σημεία διαδρομής.</div>';
        }
        return day.points.map(function(point, idx) {
            var extras = '';
            if (point.scheduled_time) extras += '<span class="badge bg-light text-dark border"><i class="bi bi-clock me-1"></i>' + esc(point.scheduled_time) + '</span>';
            if (point.stop_minutes > 0) extras += '<span class="badge bg-warning text-dark"><i class="bi bi-cup-hot me-1"></i>Στάση ' + parseInt(point.stop_minutes, 10) + '\'</span>';
            var notes = point.notes ? '<div class="text-muted small mt-1">' + esc(point.notes).replace(/\n/g, '<br>') + '</div>' : '';
            return '<div class="list-group-item"><div class="d-flex gap-2">' +
                '<span class="badge bg-primary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:28px;height:28px;">' + (idx + 1) + '</span>' +
                '<div class="flex-grow-1"><div class="d-flex flex-wrap align-items-center gap-2"><strong>' + esc(point.title || ('Σημείο ' + (idx + 1))) + '</strong>' + extras + '</div>' + notes + '</div>' +
                '</div></div>';
        }).join('');
    }

    function renderDay(dayNumber) {
        var day = findDay(dayNumber);
        if (!day) return;

        document.querySelectorAll('#missionDayViewTabs .nav-link').forEach(function(tab) {
            tab.classList.toggle('active', parseInt(tab.dataset.dayNumber, 10) === dayNumber);
        });

        document.getElementById('dayViewDate').textContent = day.date_label;

        var overnightWrap = document.getElementById('dayViewOvernight');
        if (day.overnight_notes) {
            overnightWrap.style.display = '';
            document.getElementById('dayViewOvernightText').textContent = day.overnight_notes;
        } else {
            overnightWrap.style.display = 'none';
        }

        var navBtn = document.getElementById('dayViewNavBtn');
        if (day.directions_url) {
            navBtn.href = day.directions_url;
            navBtn.style.display = '';
        } else {
            navBtn.style.display = 'none';
        }

        document.getElementById('dayViewMetrics').innerHTML = formatMetricsHtml(day);
        document.getElementById('dayViewTimeline').innerHTML = formatTimelineHtml(day);

        markers.forEach(function(m) { m.remove(); });
        markers = [];
        if (polyline) { polyline.remove(); polyline = null; }

        var latLngs = day.points.map(function(p) { return [p.lat, p.lng]; });
        latLngs.forEach(function(latLng, index) {
            var point = day.points[index];
            var popup = '<strong>' + esc(point.title || ('Σημείο ' + (index + 1))) + '</strong>';
            if (point.notes) popup += '<br><small>' + esc(point.notes) + '</small>';
            var marker = L.circleMarker(latLng, {
                radius: 5, color: '#0d6efd', fillColor: '#0d6efd', fillOpacity: 0.9, weight: 2
            }).addTo(map).bindTooltip(String(index + 1), { permanent: true, direction: 'center', className: 'route-point-label' }).bindPopup(popup);
            markers.push(marker);
        });

        if (latLngs.length >= 2) {
            polyline = L.polyline(day.geometry.length >= 2 ? day.geometry : latLngs, { color: '#0d6efd', weight: 5, opacity: 0.85 }).addTo(map);
        }

        if (latLngs.length) {
            map.fitBounds(L.latLngBounds(day.geometry.length >= 2 ? day.geometry.concat(latLngs) : latLngs), { padding: [25, 25] });
        } else if (fallbackLat !== null) {
            map.setView([fallbackLat, fallbackLng], 13);
        } else {
            map.setView([35.3387, 25.1442], 9);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        map = L.map('dayViewMap', { zoomControl: true, scrollWheelZoom: false }).setView([35.3387, 25.1442], 9);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19
        }).addTo(map);

        document.querySelectorAll('#missionDayViewTabs .nav-link').forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                renderDay(parseInt(tab.dataset.dayNumber, 10));
            });
        });

        if (days.length) {
            renderDay(days[0].day_number);
        }
        setTimeout(function() { map.invalidateSize(); }, 150);
    });
})();
</script>
<?php endif; ?>

<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
