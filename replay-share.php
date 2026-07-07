<?php
/**
 * EasyRide - Public Ride Replay Share Page
 * No login required. Looks up a mission by its replay_share_token and
 * renders the same animated route recap shown in-app, for sharing outside
 * EasyRide (social media, WhatsApp, etc).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ride-functions.php';

$token = trim(get('token', ''));
$mission = null;
$state = 'invalid'; // 'invalid' | 'unavailable' | 'ok'

if (!empty($token)) {
    $mission = dbFetchOne("SELECT * FROM missions WHERE replay_share_token = ?", [$token]);
    if ($mission) {
        $state = $mission['status'] === STATUS_COMPLETED ? 'ok' : 'unavailable';
    }
}

$appName = getSetting('app_name', 'EasyRide');
$appLogo = getSetting('app_logo', '');
$days = [];

if ($state === 'ok') {
    $routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
    $routeGeometry = rideRouteGeometry($mission);
    $replayPoints = rideReplayPoints($routeGeometry, $routePoints);

    $missionDays = dbFetchAll("SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number", [$mission['id']]);
    $isMultiDayMission = !empty($missionDays);
    $rideEvents = getRideEvents((int)$mission['id'], 100, false);

    if ($isMultiDayMission) {
        foreach ($missionDays as $day) {
            $dayPoints = normalizeRideRoutePoints($day['route_points'] ?? '[]');
            $dayGeometry = rideRouteGeometry($day);
            $dayReplayPoints = rideReplayPoints($dayGeometry, $dayPoints);
            if (count($dayReplayPoints) < 2) {
                continue;
            }
            $dayMetrics = rideMissionRouteMetrics($day, $dayPoints);
            $dayEvents = array_values(array_filter($rideEvents, function ($event) use ($day) {
                return substr((string)$event['created_at'], 0, 10) === $day['day_date'];
            }));
            $days[] = [
                'label' => ($day['title'] ?: 'Μέρα ' . (int)$day['day_number']) . ' · ' . formatDateGreek($day['day_date']),
                'points' => $dayReplayPoints,
                'distanceMeters' => (float)($dayMetrics['distance_meters'] ?? 0),
                'totalMinutes' => (int)($dayMetrics['total_minutes'] ?? 0),
                'events' => buildReplayEvents($dayReplayPoints, $dayEvents),
            ];
        }
    } elseif (count($replayPoints) >= 2) {
        $routeMetrics = rideMissionRouteMetrics($mission, $routePoints);
        $days[] = [
            'label' => $mission['title'],
            'points' => $replayPoints,
            'distanceMeters' => (float)($routeMetrics['distance_meters'] ?? 0),
            'totalMinutes' => (int)($routeMetrics['total_minutes'] ?? 0),
            'events' => buildReplayEvents($replayPoints, $rideEvents),
        ];
    }

    if (empty($days)) {
        $state = 'unavailable';
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= $state === 'ok' ? h($mission['title']) . ' - Ride Replay' : 'Ride Replay' ?> - <?= h($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <?php if ($state === 'ok'): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <?php endif; ?>
    <style>
        body {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .replay-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 640px;
            width: 100%;
            margin: 0 auto;
            overflow: hidden;
        }
    </style>
</head>
<body>
<div class="replay-card">
    <?php if ($state !== 'ok'): ?>
        <div class="text-center py-5 px-4">
            <?php if ($appLogo): ?>
                <img src="<?= h($appLogo) ?>" alt="Logo" style="max-height:60px;margin-bottom:1rem">
            <?php else: ?>
                <h4 class="fw-bold text-primary mb-4"><?= h($appName) ?></h4>
            <?php endif; ?>
            <div class="text-danger mb-3" style="font-size:3rem;"><i class="bi bi-film"></i></div>
            <?php if ($state === 'invalid'): ?>
                <h4 class="fw-bold mb-2">Μη Έγκυρος Σύνδεσμος</h4>
                <p class="text-muted mb-0">Αυτός ο σύνδεσμος Ride Replay δεν είναι έγκυρος ή έχει ανακληθεί.</p>
            <?php else: ?>
                <h4 class="fw-bold mb-2">Μη Διαθέσιμο</h4>
                <p class="text-muted mb-0">Αυτό το Ride Replay δεν είναι διαθέσιμο αυτή τη στιγμή.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="px-4 pt-4 pb-0">
            <?php if ($appLogo): ?>
                <img src="<?= h($appLogo) ?>" alt="Logo" style="max-height:40px;margin-bottom:.75rem">
            <?php endif; ?>
            <h4 class="fw-bold mb-1"><i class="bi bi-film me-1 text-primary"></i><?= h($mission['title']) ?></h4>
            <p class="text-muted small mb-3">Ολοκληρώθηκε: <?= h(formatDateGreek($mission['end_datetime'] ?? $mission['start_datetime'])) ?></p>
        </div>

        <?php if (count($days) > 1): ?>
        <ul class="nav nav-pills px-4 pb-2" id="replayShareDayTabs">
            <?php foreach ($days as $i => $day): ?>
            <li class="nav-item">
                <a href="#" class="nav-link py-1 px-2<?= $i === 0 ? ' active' : '' ?>" data-day-index="<?= $i ?>"><?= h($day['label']) ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div id="replayShareMap" style="height:360px;"></div>
        <div class="p-4 border-top">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <span id="replayShareKm" class="fw-bold">0 m</span>
                    <span class="text-muted mx-1">&middot;</span>
                    <span id="replayShareTime" class="fw-bold">0 λεπτά</span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" id="replayShareRestart" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button type="button" id="replaySharePlayPause" class="btn btn-sm btn-primary">
                        <i class="bi bi-play-fill"></i>
                    </button>
                </div>
            </div>
            <input type="range" id="replayShareScrubber" class="form-range" min="0" max="1" step="0.001" value="0">
        </div>
        <div class="text-center py-3 border-top small text-muted">
            Powered by <?= h($appName) ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($state === 'ok'): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ride-replay.js?v=<?= APP_VERSION ?>"></script>
<script>
    var replayDays = <?= json_encode($days, JSON_UNESCAPED_UNICODE) ?>;
    var currentDayIndex = 0;
    var replayController = window.initRideReplayController({
        standalone: true,
        autoplay: true,
        elIds: {
            map: 'replayShareMap',
            km: 'replayShareKm',
            time: 'replayShareTime',
            scrubber: 'replayShareScrubber',
            playPause: 'replaySharePlayPause',
            restart: 'replayShareRestart'
        },
        getData: function () { return replayDays[currentDayIndex]; }
    });

    document.querySelectorAll('#replayShareDayTabs .nav-link').forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('#replayShareDayTabs .nav-link').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            currentDayIndex = parseInt(tab.dataset.dayIndex, 10);
            replayController.reload();
        });
    });
</script>
<?php endif; ?>
</body>
</html>
