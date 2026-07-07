<?php
/**
 * EasyRide - Print-friendly Ride Report
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ride-functions.php';
requireLogin();

$missionId = (int)get('mission_id', get('id', 0));
if ($missionId <= 0) {
    redirect('missions.php');
}

$mission = dbFetchOne(
    "SELECT m.*, u.name as creator_name, r.name as responsible_name,
            mt.name as type_name
     FROM missions m
     LEFT JOIN users u ON u.id = m.created_by
     LEFT JOIN users r ON r.id = m.responsible_user_id
     LEFT JOIN mission_types mt ON mt.id = m.mission_type_id
     WHERE m.id = ? AND m.deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    setFlash('error', 'Η δράση δεν βρέθηκε.');
    redirect('missions.php');
}

$currentUser = getCurrentUser();
$isResponsible = !empty($mission['responsible_user_id']) && (int)$mission['responsible_user_id'] === (int)$currentUser['id'];
if (!isRideController($mission, $currentUser) && !$isResponsible && !hasPagePermission('missions_view')) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης στο Ride Report.');
    redirect('mission-view.php?id=' . $missionId);
}

$routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
$routeMetrics = rideMissionRouteMetrics($mission, $routePoints);
$directionsUrl = buildRideDirectionsUrl($routePoints);
$rideSummary = buildRideDebriefSuggestion($missionId);
$rideEvents = getRideEvents($missionId, 100, false);
$rideChecklist = getRideChecklistSummary($missionId);
$activeEvents = array_values(array_filter($rideEvents, fn($event) => empty($event['resolved_at'])));
$resolvedEvents = array_values(array_filter($rideEvents, fn($event) => !empty($event['resolved_at'])));
$typeLabels = [
    'status_needs_help' => 'SOS',
    'status_breakdown' => 'Βλάβη',
    'status_left_behind' => 'Έμεινε πίσω',
    'status_on_way' => 'Σε κίνηση',
    'status_on_site' => 'Στάση',
    'status_ok' => 'ΟΚ',
    'off_route' => 'Εκτός διαδρομής',
    'stationary' => 'Ακίνητος',
    'stale' => 'Χωρίς πρόσφατο στίγμα',
];

$shifts = dbFetchAll(
    "SELECT s.*,
            COUNT(DISTINCT CASE WHEN pr.status = ? THEN pr.member_id END) as approved_count
     FROM shifts s
     LEFT JOIN participation_requests pr ON pr.shift_id = s.id
     WHERE s.mission_id = ?
     GROUP BY s.id
     ORDER BY s.start_time ASC",
    [PARTICIPATION_APPROVED, $missionId]
);

$latestPings = [];
$shiftIds = getRideShiftIds($missionId);
if (!empty($shiftIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
        $latestPings = dbFetchAll(
            "SELECT vp.user_id, u.name, vp.lat, vp.lng, vp.created_at
             FROM member_pings vp
             JOIN users u ON u.id = vp.user_id
             WHERE vp.shift_id IN ($placeholders)
               AND vp.id = (
                   SELECT MAX(vp2.id)
                   FROM member_pings vp2
                   WHERE vp2.user_id = vp.user_id
                     AND vp2.shift_id IN ($placeholders)
               )
             ORDER BY u.name ASC",
            array_merge($shiftIds, $shiftIds)
        );
    } catch (Exception $e) {
        $latestPings = [];
    }
}

function reportEventLabel(array $event, array $typeLabels): string {
    return $typeLabels[$event['event_type']] ?? $event['title'] ?? $event['event_type'];
}
?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ride Report - <?= h($mission['title']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; color:#172033; margin:0; background:#eef2f7; }
        .page { max-width: 980px; margin: 24px auto; background:#fff; padding: 30px; box-shadow:0 12px 30px rgba(15,23,42,.12); }
        h1 { margin:0 0 6px; font-size:28px; }
        h2 { margin:24px 0 10px; font-size:18px; border-bottom:2px solid #dbeafe; padding-bottom:6px; }
        h3 { margin:14px 0 8px; font-size:15px; }
        .muted { color:#64748b; }
        .top-actions { display:flex; gap:8px; justify-content:flex-end; margin-bottom:18px; }
        .btn { display:inline-block; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; color:#0f172a; text-decoration:none; background:#fff; font-size:14px; }
        .btn-primary { background:#0d6efd; color:#fff; border-color:#0d6efd; }
        .grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; margin-top:18px; }
        .metric { border:1px solid #e2e8f0; border-radius:8px; padding:12px; background:#f8fafc; }
        .metric strong { display:block; font-size:22px; }
        .meta-grid { display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:16px; }
        .meta { border:1px solid #e2e8f0; padding:10px; border-radius:8px; }
        table { width:100%; border-collapse: collapse; margin-top:8px; }
        th, td { text-align:left; border-bottom:1px solid #e2e8f0; padding:8px; font-size:13px; vertical-align:top; }
        th { background:#f8fafc; color:#475569; }
        .badge { display:inline-block; padding:3px 7px; border-radius:999px; font-size:12px; font-weight:700; }
        .danger { background:#fee2e2; color:#991b1b; }
        .warning { background:#fef3c7; color:#92400e; }
        .info { background:#dbeafe; color:#1d4ed8; }
        .secondary { background:#e2e8f0; color:#475569; }
        .success { background:#dcfce7; color:#166534; }
        pre { white-space:pre-wrap; font-family:inherit; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; }
        .footer { margin-top:28px; font-size:12px; color:#64748b; text-align:right; }
        @media print {
            body { background:#fff; }
            .page { box-shadow:none; margin:0; max-width:none; padding:0; }
            .top-actions { display:none; }
            a { color:#000; text-decoration:none; }
            h2 { break-after: avoid; }
            table { break-inside:auto; }
            tr { break-inside:avoid; break-after:auto; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="top-actions">
        <a class="btn" href="mission-view.php?id=<?= $missionId ?>">Πίσω στη δράση</a>
        <button class="btn btn-primary" onclick="window.print()">Εκτύπωση / PDF</button>
    </div>

    <h1>Ride Report</h1>
    <div class="muted"><?= h($mission['title']) ?> · Δημιουργήθηκε <?= date('d/m/Y H:i') ?></div>

    <div class="meta-grid">
        <div class="meta"><strong>Τύπος:</strong> <?= h($mission['type_name'] ?: '-') ?></div>
        <div class="meta"><strong>Τοποθεσία:</strong> <?= h($mission['location'] ?: '-') ?></div>
        <div class="meta"><strong>Έναρξη:</strong> <?= formatDateTime($mission['start_datetime']) ?></div>
        <div class="meta"><strong>Λήξη:</strong> <?= formatDateTime($mission['end_datetime']) ?></div>
        <div class="meta"><strong>Υπεύθυνος:</strong> <?= h($mission['responsible_name'] ?: '-') ?></div>
        <div class="meta"><strong>Δημιουργός:</strong> <?= h($mission['creator_name'] ?: '-') ?></div>
    </div>

    <div class="grid">
        <div class="metric"><strong><?= (int)$rideSummary['ping_stats']['riders'] ?></strong><span>μέλη με GPS</span></div>
        <div class="metric"><strong><?= (int)$rideSummary['ping_stats']['total_pings'] ?></strong><span>στίγματα GPS</span></div>
        <div class="metric"><strong><?= count($rideEvents) ?></strong><span>συμβάντα</span></div>
        <div class="metric"><strong><?= count($resolvedEvents) ?></strong><span>επιλυμένα</span></div>
    </div>

    <h2>Checklist Εκκίνησης</h2>
    <p><strong><?= (int)$rideChecklist['completed'] ?>/<?= (int)$rideChecklist['total'] ?></strong> ολοκληρωμένα (<?= (int)$rideChecklist['percent'] ?>%).</p>
    <table>
        <thead><tr><th>Έλεγχος</th><th>Κατάσταση</th><th>Από</th><th>Ώρα</th></tr></thead>
        <tbody>
        <?php foreach ($rideChecklist['items'] as $item): ?>
            <tr>
                <td><?= h($item['label']) ?></td>
                <td><?= $item['completed'] ? '<span class="badge success">OK</span>' : '<span class="badge secondary">Pending</span>' ?></td>
                <td><?= h($item['completed_by_name'] ?: '-') ?></td>
                <td><?= $item['completed_at'] ? formatDateTime($item['completed_at']) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Σύνοψη Ride Mode</h2>
    <pre><?= h($rideSummary['summary']) ?></pre>
    <?php if (!empty($rideSummary['incidents'])): ?>
        <h3>Συμβάντα για Debrief</h3>
        <pre><?= h($rideSummary['incidents']) ?></pre>
    <?php endif; ?>

    <h2>Διαδρομή</h2>
    <?php if ($directionsUrl): ?>
        <p><strong>Google Maps:</strong> <a href="<?= h($directionsUrl) ?>"><?= h($directionsUrl) ?></a></p>
    <?php endif; ?>
    <?php if (!empty($routePoints)): ?>
        <p>
            <strong>Απόσταση:</strong> <?= h($routeMetrics['distance_label']) ?> &nbsp;
            <strong>Οδήγηση:</strong> <?= h($routeMetrics['moving_label']) ?> &nbsp;
            <strong>Στάσεις:</strong> <?= h($routeMetrics['stop_label']) ?> &nbsp;
            <strong>Σύνολο:</strong> <?= h($routeMetrics['total_label']) ?>
        </p>
        <table>
            <thead><tr><th>#</th><th>Σημείο</th><th>Ώρα</th><th>Στάση</th><th>Σημειώσεις</th></tr></thead>
            <tbody>
            <?php foreach ($routePoints as $idx => $point): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td><?= h($point['title'] ?: 'Σημείο ' . ($idx + 1)) ?></td>
                    <td><?= h($point['scheduled_time'] ?: '-') ?></td>
                    <td><?= $point['stop_minutes'] > 0 ? (int)$point['stop_minutes'] . "'" : '-' ?></td>
                    <td><?= h($point['notes'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="muted">Δεν έχει οριστεί χρονοδιάγραμμα διαδρομής.</p>
    <?php endif; ?>

    <h2>Συμβάντα Διαδρομής</h2>
    <?php if (empty($rideEvents)): ?>
        <p class="muted">Δεν υπάρχουν καταγεγραμμένα συμβάντα.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Ώρα</th><th>Τύπος</th><th>Μέλος</th><th>Μήνυμα</th><th>Κατάσταση</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($rideEvents) as $event): ?>
                <tr>
                    <td><?= formatDateTime($event['created_at']) ?></td>
                    <td><span class="badge <?= h($event['severity'] ?: 'info') ?>"><?= h(reportEventLabel($event, $typeLabels)) ?></span></td>
                    <td><?= h($event['user_name'] ?: '-') ?></td>
                    <td><?= h($event['message'] ?: '-') ?></td>
                    <td>
                        <?php if (!empty($event['resolved_at'])): ?>
                            <span class="badge success">Επιλύθηκε</span><br>
                            <span class="muted"><?= h($event['resolved_by_name'] ?: '-') ?> · <?= formatDateTime($event['resolved_at']) ?></span>
                        <?php else: ?>
                            <span class="badge danger">Ενεργό</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Κύκλοι Εγγραφών & Συμμετοχές</h2>
    <table>
        <thead><tr><th>Κύκλος Εγγραφών</th><th>Ώρες</th><th>Εγκεκριμένα μέλη</th></tr></thead>
        <tbody>
        <?php foreach ($shifts as $shift): ?>
            <tr>
                <td><?= h($shift['title'] ?? 'Κύκλος Εγγραφών #' . $shift['id']) ?></td>
                <td><?= formatDateTime($shift['start_time']) ?> - <?= formatDateTime($shift['end_time']) ?></td>
                <td><?= (int)$shift['approved_count'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Τελευταία Στίγματα Μελών</h2>
    <?php if (empty($latestPings)): ?>
        <p class="muted">Δεν υπάρχουν στίγματα GPS.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Μέλος</th><th>Ώρα</th><th>Συντεταγμένες</th><th>Χάρτης</th></tr></thead>
            <tbody>
            <?php foreach ($latestPings as $ping): ?>
                <?php $pingUrl = 'https://www.google.com/maps?q=' . urlencode($ping['lat'] . ',' . $ping['lng']); ?>
                <tr>
                    <td><?= h($ping['name']) ?></td>
                    <td><?= formatDateTime($ping['created_at']) ?></td>
                    <td><?= h($ping['lat']) ?>, <?= h($ping['lng']) ?></td>
                    <td><a href="<?= h($pingUrl) ?>">Άνοιγμα</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="footer">
        <?= h(APP_NAME) ?> · Ride Report · <?= h($currentUser['name'] ?? '') ?>
    </div>
</div>
</body>
</html>
