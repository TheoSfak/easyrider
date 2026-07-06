<?php
/**
 * EasyRide - PWA Ride Mode / Ride Control
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ride-functions.php';
requireLogin();

$currentUser = getCurrentUser();
$missionId = (int)get('mission_id', get('id', 0));
$mission = $missionId > 0 ? getRideMission($missionId) : null;

if (!$mission) {
    setFlash('error', 'Η δράση δεν βρέθηκε.');
    redirect('missions.php');
}

if (!canAccessRideMission($mission, $currentUser)) {
    setFlash('error', 'Το Ride Mode είναι διαθέσιμο μόνο στους συμμετέχοντες και στους υπεύθυνους της δράσης.');
    redirect('mission-view.php?id=' . $missionId);
}

$isController = isRideController($mission, $currentUser);
$participation = getRideParticipation($missionId, (int)$currentUser['id']);
$mission = resolveActiveMissionDayRoute($mission);
$routePoints = normalizeRideRoutePoints($mission['route_points'] ?? '[]');
$routeMetrics = rideMissionRouteMetrics($mission, $routePoints);
$routeGeometry = rideRouteGeometry($mission);
$directionsUrl = buildRideDirectionsUrl($routePoints);
$statusOptions = rideStatusOptions();
$csrf = csrfToken();
$pageTitle = $isController ? 'Ride Control' : 'Ride Mode';
?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <meta name="csrf-token" content="<?= h($csrf) ?>">
    <title><?= h($pageTitle) ?> - <?= h(APP_NAME) ?></title>
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --ride-bg: #0f172a;
            --ride-panel: #ffffff;
            --ride-muted: #64748b;
        }
        body {
            margin: 0;
            background: #e5ebf2;
            color: #111827;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .ride-shell {
            min-height: 100dvh;
            display: grid;
            grid-template-rows: auto minmax(320px, 48dvh) auto;
        }
        .ride-topbar {
            background: var(--ride-bg);
            color: #fff;
            padding: calc(env(safe-area-inset-top, 0px) + 12px) 14px 12px;
        }
        .ride-title {
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.2;
        }
        .ride-subtitle {
            color: #cbd5e1;
            font-size: .8rem;
        }
        .ride-status-pill {
            border: 1px solid #334155;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: .72rem;
            white-space: nowrap;
        }
        #rideMap {
            width: 100%;
            height: 100%;
            background: #dbeafe;
            z-index: 1;
        }
        .ride-bottom {
            background: #f8fafc;
            padding: 12px;
            padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 12px);
        }
        .ride-card {
            background: var(--ride-panel);
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
        }
        .ride-primary-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .ride-status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .ride-status-btn {
            min-height: 62px;
            border-radius: 8px;
            font-weight: 700;
            font-size: .82rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
        }
        .ride-status-btn i {
            font-size: 1.15rem;
        }
        .metric-lite {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px;
            background: #f8fafc;
            text-align: center;
            min-height: 58px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .metric-lite strong {
            font-size: 1.15rem;
            line-height: 1.1;
        }
        .metric-lite span {
            font-size: .68rem;
            color: #64748b;
        }
        .ride-meta {
            color: var(--ride-muted);
            font-size: .78rem;
        }
        .ride-alert {
            border-left: 4px solid #dc3545;
            background: #fff7f7;
        }
        .participant-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
            border-top: 1px solid #edf2f7;
            padding: 8px 0;
        }
        .participant-row:first-child {
            border-top: 0;
            padding-top: 0;
        }
        .participant-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .route-step {
            display: grid;
            grid-template-columns: 28px 1fr;
            gap: 8px;
            padding: 8px 0;
            border-top: 1px solid #edf2f7;
        }
        .route-step:first-child {
            border-top: 0;
        }
        .route-index {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #0d6efd;
            color: #fff;
            font-weight: 800;
            font-size: .8rem;
        }
        @media (min-width: 992px) {
            .ride-shell {
                grid-template-columns: minmax(360px, 420px) 1fr;
                grid-template-rows: auto 1fr;
            }
            .ride-topbar {
                grid-column: 1 / -1;
            }
            #rideMap {
                grid-column: 2;
                grid-row: 2;
            }
            .ride-bottom {
                grid-column: 1;
                grid-row: 2;
                overflow-y: auto;
            }
        }
    </style>
</head>
<body>
<div class="ride-shell">
    <header class="ride-topbar">
        <div class="d-flex align-items-start justify-content-between gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <a href="mission-view.php?id=<?= $missionId ?>" class="text-white text-decoration-none" title="Πίσω στη δράση">
                        <i class="bi bi-arrow-left fs-5"></i>
                    </a>
                    <div class="ride-title"><?= h($pageTitle) ?></div>
                </div>
                <div class="fw-semibold"><?= h($mission['title']) ?></div>
                <div class="ride-subtitle">
                    <i class="bi bi-geo-alt me-1"></i><?= h($mission['location'] ?: 'Χωρίς τοποθεσία') ?>
                </div>
            </div>
            <div class="text-end">
                <div class="ride-status-pill" id="connectionPill">
                    <i class="bi bi-circle-fill text-secondary me-1"></i>Ανενεργό
                </div>
                <div class="ride-subtitle mt-1" id="lastPingText">Χωρίς στίγμα</div>
            </div>
        </div>
    </header>

    <div id="rideMap"></div>

    <main class="ride-bottom">
        <div class="ride-card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                <div>
                    <div class="fw-bold">Μετάδοση στίγματος</div>
                    <div class="ride-meta">Λειτουργεί όσο αυτή η οθόνη παραμένει ανοιχτή.</div>
                </div>
                <span class="badge <?= $isController ? 'bg-primary' : 'bg-success' ?>">
                    <?= $isController ? 'Control' : 'Rider' ?>
                </span>
            </div>

            <?php if (!$participation): ?>
                <div class="alert alert-info py-2 mb-2">
                    Μπορείτε να παρακολουθείτε τη δράση, αλλά για να στείλετε δικό σας στίγμα χρειάζεται εγκεκριμένη συμμετοχή.
                </div>
            <?php endif; ?>

            <div class="alert alert-warning py-2 d-none" id="secureWarning">
                <i class="bi bi-lock-fill me-1"></i>Το Ride Mode απαιτεί HTTPS ή localhost για GPS.
            </div>
            <div class="alert alert-danger py-2 d-none" id="gpsError"></div>

            <div class="ride-primary-actions mb-2">
                <button class="btn btn-success btn-lg" id="startBtn" type="button" <?= $participation ? '' : 'disabled' ?>>
                    <i class="bi bi-play-fill me-1"></i>Έναρξη
                </button>
                <button class="btn btn-outline-dark btn-lg" id="stopBtn" type="button" disabled>
                    <i class="bi bi-stop-fill me-1"></i>Τέλος
                </button>
            </div>

            <div class="ride-status-grid">
                <button type="button" class="btn btn-danger ride-status-btn" data-status="needs_help" <?= $participation ? '' : 'disabled' ?>>
                    <i class="bi bi-exclamation-octagon-fill"></i>SOS
                </button>
                <button type="button" class="btn btn-outline-purple ride-status-btn" data-status="breakdown" style="border-color:#6f42c1;color:#6f42c1" <?= $participation ? '' : 'disabled' ?>>
                    <i class="bi bi-tools"></i>Βλάβη
                </button>
                <button type="button" class="btn btn-outline-info ride-status-btn" data-status="left_behind" <?= $participation ? '' : 'disabled' ?>>
                    <i class="bi bi-person-dash-fill"></i>Πίσω
                </button>
                <button type="button" class="btn btn-outline-success ride-status-btn" data-status="on_site" <?= $participation ? '' : 'disabled' ?>>
                    <i class="bi bi-cup-hot-fill"></i>Στάση
                </button>
                <button type="button" class="btn btn-outline-warning ride-status-btn" data-status="on_way" <?= $participation ? '' : 'disabled' ?>>
                    <i class="bi bi-signpost-2-fill"></i>Κίνηση
                </button>
                <button type="button" class="btn btn-outline-primary ride-status-btn" data-status="ok" <?= $participation ? '' : 'disabled' ?>>
                    <i class="bi bi-check-circle-fill"></i>OK
                </button>
            </div>
        </div>

        <div class="ride-card p-3 mb-3" id="alertsCard">
            <div class="fw-bold mb-2"><i class="bi bi-bell-fill me-1 text-danger"></i>Ειδοποιήσεις Ride</div>
            <div class="ride-meta" id="alertsList">Δεν υπάρχουν ενεργές ειδοποιήσεις.</div>
        </div>

        <div class="ride-card p-3 mb-3" id="partnerAssistCard">
            <div class="fw-bold mb-2"><i class="bi bi-tools me-1 text-primary"></i>Κοντινοί Συνεργάτες</div>
            <div class="ride-meta" id="partnerAssistList">Δεν υπάρχει ενεργό περιστατικό για υποστήριξη συνεργάτη.</div>
        </div>

        <div class="ride-card p-3 mb-3">
            <div class="fw-bold mb-2"><i class="bi bi-speedometer2 me-1 text-success"></i>Readiness Ride Mode</div>
            <div class="ride-status-grid" id="readinessGrid">
                <div class="metric-lite"><strong>0</strong><span>Εγκεκριμένοι</span></div>
                <div class="metric-lite"><strong>0</strong><span>Στέλνουν GPS</span></div>
                <div class="metric-lite"><strong>0</strong><span>Δεν ξεκίνησαν</span></div>
                <div class="metric-lite"><strong>0</strong><span>Stale</span></div>
                <div class="metric-lite"><strong>0</strong><span>Status</span></div>
                <div class="metric-lite"><strong>0%</strong><span>Έτοιμοι</span></div>
            </div>
        </div>

        <div class="ride-card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold"><i class="bi bi-ui-checks-grid me-1 text-primary"></i>Checklist Εκκίνησης</div>
                <span class="badge bg-light text-dark border" id="checklistProgress">0/0</span>
            </div>
            <div id="rideChecklist" class="ride-meta">Φόρτωση...</div>
        </div>

        <div class="ride-card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold"><i class="bi bi-activity me-1"></i>Συμβάντα Διαδρομής</div>
                <span class="badge bg-light text-dark border" id="eventCount">0</span>
            </div>
            <div id="eventsList" class="ride-meta">Φόρτωση...</div>
        </div>

        <div class="ride-card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold"><i class="bi bi-people-fill me-1"></i>Μέλη στη δράση</div>
                <span class="badge bg-light text-dark border" id="participantCount">0</span>
            </div>
            <div id="participantsList" class="ride-meta">Φόρτωση...</div>
        </div>

        <div class="ride-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold">
                    <i class="bi bi-clock-history me-1"></i>Χρονοδιάγραμμα
                    <?php if ($mission['is_multiday']): ?>
                        <span class="badge bg-light text-dark border ms-1"><?= h($mission['active_day_label']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($directionsUrl): ?>
                    <a href="<?= h($directionsUrl) ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-sign-turn-right me-1"></i>Πλοήγηση
                    </a>
                <?php endif; ?>
            </div>
            <?php if (empty($routePoints)): ?>
                <div class="ride-meta">Δεν έχει οριστεί διαδρομή.</div>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge bg-light text-dark border"><i class="bi bi-signpost-2 me-1"></i><?= h($routeMetrics['distance_label']) ?></span>
                    <span class="badge bg-light text-dark border"><i class="bi bi-stopwatch me-1"></i><?= h($routeMetrics['moving_label']) ?></span>
                    <span class="badge bg-warning text-dark"><i class="bi bi-cup-hot me-1"></i><?= h($routeMetrics['stop_label']) ?></span>
                    <span class="badge bg-primary"><i class="bi bi-clock-history me-1"></i><?= h($routeMetrics['total_label']) ?></span>
                </div>
                <?php foreach ($routePoints as $idx => $point): ?>
                <div class="route-step">
                    <span class="route-index"><?= $idx + 1 ?></span>
                    <div>
                        <div class="fw-semibold"><?= h($point['title'] ?: 'Σημείο ' . ($idx + 1)) ?></div>
                        <div class="ride-meta">
                            <?php if ($point['scheduled_time']): ?>
                                <span class="me-2"><i class="bi bi-clock me-1"></i><?= h($point['scheduled_time']) ?></span>
                            <?php endif; ?>
                            <?php if ($point['stop_minutes'] > 0): ?>
                                <span><i class="bi bi-cup-hot me-1"></i>Στάση <?= (int)$point['stop_minutes'] ?>'</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($point['notes']): ?>
                            <div class="ride-meta mt-1"><?= nl2br(h($point['notes'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const missionId = <?= (int)$missionId ?>;
const shiftId = <?= $participation ? (int)$participation['shift_id'] : 0 ?>;
const csrfTokenValue = <?= json_encode($csrf) ?>;
const routePoints = <?= json_encode($routePoints, JSON_UNESCAPED_UNICODE) ?>;
const routeGeometry = <?= json_encode($routeGeometry) ?>;
const isController = <?= $isController ? 'true' : 'false' ?>;
const statusColors = <?= json_encode(array_map(fn($item) => $item['color'], $statusOptions), JSON_UNESCAPED_UNICODE) ?>;
const statusLabels = <?= json_encode(array_map(fn($item) => $item['label'], $statusOptions), JSON_UNESCAPED_UNICODE) ?>;

let rideMap;
let routeLayer;
let participantLayer;
let partnerLayer;
let selfMarker = null;
let watchId = null;
let wakeLock = null;
let tracking = false;
let lastPosition = null;
let lastSentAt = 0;
let lastStatus = '';
let batteryLevel = null;
let canResolveEvents = isController;

const connectionPill = document.getElementById('connectionPill');
const lastPingText = document.getElementById('lastPingText');
const gpsError = document.getElementById('gpsError');
const secureWarning = document.getElementById('secureWarning');
const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');

function htmlEscape(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
}

function setPill(active, text) {
    connectionPill.innerHTML = active
        ? '<i class="bi bi-circle-fill text-success me-1"></i>' + text
        : '<i class="bi bi-circle-fill text-secondary me-1"></i>' + text;
}

function showGpsError(message) {
    gpsError.textContent = message;
    gpsError.classList.remove('d-none');
}

function clearGpsError() {
    gpsError.classList.add('d-none');
    gpsError.textContent = '';
}

function initMap() {
    const center = routePoints.length ? [routePoints[0].lat, routePoints[0].lng] : [35.3387, 25.1442];
    const zoom = routePoints.length ? 12 : 11;

    rideMap = L.map('rideMap', { zoomControl: true }).setView(center, zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(rideMap);

    routeLayer = L.layerGroup().addTo(rideMap);
    participantLayer = L.layerGroup().addTo(rideMap);
    partnerLayer = L.layerGroup().addTo(rideMap);

    if (routePoints.length) {
        const latLngs = routePoints.map(point => [point.lat, point.lng]);
        if (latLngs.length >= 2) {
            L.polyline(routeGeometry.length >= 2 ? routeGeometry : latLngs, { color: '#0d6efd', weight: 5, opacity: .8 }).addTo(routeLayer);
        }
        routePoints.forEach((point, index) => {
            L.circleMarker([point.lat, point.lng], {
                radius: 6,
                color: '#0d6efd',
                fillColor: '#0d6efd',
                fillOpacity: .9,
                weight: 2
            }).addTo(routeLayer).bindTooltip(String(index + 1), { permanent: true, direction: 'center', className: 'fw-bold' });
        });
        if (latLngs.length > 1) {
            rideMap.fitBounds(L.latLngBounds(latLngs), { padding: [30, 30] });
        }
    }

    setTimeout(() => rideMap.invalidateSize(), 200);
}

function markerHtml(color, isSelf, isStale) {
    const opacity = isStale ? '.45' : '1';
    const size = isSelf ? 26 : 22;
    return '<div style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:' + color + ';opacity:' + opacity + ';border:3px solid #fff;box-shadow:0 2px 8px #0005;"></div>';
}

function renderParticipants(participants) {
    participantLayer.clearLayers();
    const list = document.getElementById('participantsList');
    document.getElementById('participantCount').textContent = participants.length;

    if (!participants.length) {
        list.textContent = 'Δεν υπάρχουν εγκεκριμένοι συμμετέχοντες.';
        return;
    }

    list.innerHTML = participants.map(p => {
        const color = p.is_stale ? '#94a3b8' : (p.status_color || '#0d6efd');
        const last = p.last_ping_at ? 'Στίγμα ' + p.last_ping_at : 'Χωρίς στίγμα';
        const phoneLink = p.phone
            ? '<a class="btn btn-sm btn-outline-success" href="tel:' + htmlEscape(p.phone) + '"><i class="bi bi-telephone-fill"></i></a>'
            : '';
        const navLink = p.lat && p.lng
            ? '<a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer" href="https://www.google.com/maps?q=' + encodeURIComponent(p.lat + ',' + p.lng) + '"><i class="bi bi-geo-alt-fill"></i></a>'
            : '';
        const badges = [
            p.is_off_route ? '<span class="badge bg-warning text-dark ms-1">Εκτός διαδρομής</span>' : '',
            p.is_stationary ? '<span class="badge bg-warning text-dark ms-1">Ακίνητος</span>' : '',
            p.is_stale ? '<span class="badge bg-secondary ms-1">Χωρίς στίγμα</span>' : ''
        ].join('');
        return '<div class="participant-row">'
            + '<div><div class="fw-semibold"><span class="participant-dot" style="background:' + color + '"></span>' + htmlEscape(p.name) + (p.is_self ? ' <span class="badge bg-primary">Εσύ</span>' : '') + badges + '</div>'
            + '<div>' + htmlEscape(p.status_label || 'Χωρίς κατάσταση') + ' · ' + htmlEscape(last) + '</div></div>'
            + '<div class="d-flex gap-1">' + phoneLink + navLink + '</div>'
            + '</div>';
    }).join('');

    participants.forEach(p => {
        if (!p.lat || !p.lng) return;
        const color = p.is_stale ? '#94a3b8' : (p.status_color || '#0d6efd');
        const icon = L.divIcon({
            className: '',
            html: markerHtml(color, p.is_self, p.is_stale),
            iconSize: [p.is_self ? 26 : 22, p.is_self ? 26 : 22],
            iconAnchor: [p.is_self ? 13 : 11, p.is_self ? 13 : 11]
        });
        L.marker([p.lat, p.lng], { icon })
            .addTo(participantLayer)
            .bindPopup('<strong>' + htmlEscape(p.name) + '</strong><br>' + htmlEscape(p.status_label || '') + '<br><small>' + htmlEscape(p.last_ping_at || 'Χωρίς στίγμα') + '</small>');
    });
}

function renderAlerts(alerts) {
    const list = document.getElementById('alertsList');
    if (!alerts.length) {
        list.className = 'ride-meta';
        list.textContent = 'Δεν υπάρχουν ενεργές ειδοποιήσεις.';
        return;
    }
    list.className = '';
    list.innerHTML = alerts.map(alert => {
        const cls = alert.severity === 'danger' ? 'alert-danger' : (alert.severity === 'warning' ? 'alert-warning' : 'alert-secondary');
        return '<div class="alert ' + cls + ' py-2 mb-2">' + htmlEscape(alert.message) + '</div>';
    }).join('');
}

function partnerMarkerHtml(color) {
    return '<div style="width:26px;height:26px;border-radius:8px;background:' + color + ';border:3px solid #fff;box-shadow:0 2px 8px #0005;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:14px;">+</div>';
}

function renderPartnerAssist(assists, partnerPins) {
    partnerLayer.clearLayers();
    const list = document.getElementById('partnerAssistList');

    (partnerPins || []).forEach(partner => {
        if (!partner.lat || !partner.lng) return;
        const icon = L.divIcon({
            className: '',
            html: partnerMarkerHtml(partner.type_color || '#0d6efd'),
            iconSize: [26, 26],
            iconAnchor: [13, 13]
        });
        const phone = partner.phone ? '<br><a href="tel:' + htmlEscape(partner.phone).replace(/\s+/g, '') + '">' + htmlEscape(partner.phone) + '</a>' : '';
        L.marker([partner.lat, partner.lng], { icon })
            .addTo(partnerLayer)
            .bindPopup('<strong>' + htmlEscape(partner.name) + '</strong><br>' + htmlEscape(partner.type_label || '') + '<br>' + htmlEscape(partner.distance_label || '') + phone);
    });

    if (!assists || !assists.length) {
        list.className = 'ride-meta';
        list.textContent = 'Δεν υπάρχει ενεργό περιστατικό για υποστήριξη συνεργάτη.';
        return;
    }

    list.className = '';
    list.innerHTML = assists.map(assist => {
        const partnersHtml = assist.partners.map(partner => {
            const phoneLink = partner.phone
                ? '<a class="btn btn-sm btn-outline-success" href="tel:' + htmlEscape(partner.phone).replace(/\s+/g, '') + '"><i class="bi bi-telephone-fill"></i></a>'
                : '';
            const navLink = partner.nav_url
                ? '<a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer" href="' + htmlEscape(partner.nav_url) + '"><i class="bi bi-sign-turn-right"></i></a>'
                : '';
            const partnerLink = '<a class="btn btn-sm btn-outline-secondary" href="partners.php?q=' + encodeURIComponent(partner.name) + '"><i class="bi bi-box-arrow-up-right"></i></a>';
            const subBadge = partner.is_subscriber ? '<span class="badge bg-success ms-1">Συνδρομητής</span>' : '';
            return '<div class="participant-row">'
                + '<div><div class="fw-semibold"><span class="participant-dot" style="background:' + htmlEscape(partner.type_color || '#0d6efd') + '"></span>' + htmlEscape(partner.name) + subBadge + '</div>'
                + '<div class="ride-meta">' + htmlEscape(partner.type_label || '') + ' · ' + htmlEscape(partner.distance_label || '') + (partner.area ? ' · ' + htmlEscape(partner.area) : '') + '</div></div>'
                + '<div class="d-flex gap-1">' + phoneLink + navLink + partnerLink + '</div>'
                + '</div>';
        }).join('');
        return '<div class="alert alert-light border py-2 mb-2">'
            + '<div class="fw-semibold mb-1">' + htmlEscape(assist.message) + '</div>'
            + partnersHtml
            + '</div>';
    }).join('');
}

function renderReadiness(readiness) {
    const grid = document.getElementById('readinessGrid');
    if (!grid) return;
    const approved = Number(readiness.approved || 0);
    const tracking = Number(readiness.tracking || 0);
    const readyPct = approved > 0 ? Math.round((tracking / approved) * 100) : 0;
    const values = [
        approved,
        tracking,
        Number(readiness.not_started || 0),
        Number(readiness.stale || 0),
        Number(readiness.with_status || 0),
        readyPct + '%'
    ];
    grid.querySelectorAll('.metric-lite strong').forEach((node, index) => {
        node.textContent = values[index] ?? '0';
    });
}

function renderChecklist(checklist) {
    const container = document.getElementById('rideChecklist');
    const progress = document.getElementById('checklistProgress');
    if (!container || !progress) return;

    const items = checklist.items || [];
    progress.textContent = (checklist.completed || 0) + '/' + (checklist.total || items.length);
    if (!items.length) {
        container.textContent = 'Δεν υπάρχει checklist.';
        return;
    }

    container.className = '';
    container.innerHTML = items.map(item => {
        const checked = item.completed ? 'checked' : '';
        const disabled = canResolveEvents ? '' : 'disabled';
        const meta = item.completed
            ? '<div class="ride-meta text-success">Ολοκληρώθηκε' + (item.completed_by_name ? ' από ' + htmlEscape(item.completed_by_name) : '') + '</div>'
            : '';
        return '<label class="participant-row" style="cursor:pointer">'
            + '<div><div class="fw-semibold">'
            + '<input class="form-check-input me-2 ride-checklist-input" type="checkbox" data-item-key="' + htmlEscape(item.key) + '" ' + checked + ' ' + disabled + '>'
            + htmlEscape(item.label) + '</div>' + meta + '</div>'
            + '<div>' + (item.completed ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-light text-dark border">Pending</span>') + '</div>'
            + '</label>';
    }).join('');
}

async function updateChecklistItem(itemKey, completed) {
    const body = new URLSearchParams({
        csrf_token: csrfTokenValue,
        mission_id: String(missionId),
        item_key: itemKey,
        completed: completed ? '1' : '0'
    });
    try {
        const response = await fetch('api-ride-checklist.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body
        });
        const data = await response.json();
        if (!data.ok) {
            showGpsError(data.error || 'Δεν ενημερώθηκε το checklist.');
            refreshRideData();
            return;
        }
        clearGpsError();
        renderChecklist(data.checklist || {});
    } catch (error) {
        showGpsError('Δεν ενημερώθηκε το checklist.');
        refreshRideData();
    }
}

function renderEvents(events) {
    const list = document.getElementById('eventsList');
    const count = document.getElementById('eventCount');
    count.textContent = events.length;

    if (!events.length) {
        list.className = 'ride-meta';
        list.textContent = 'Δεν υπάρχουν καταγεγραμμένα συμβάντα.';
        return;
    }

    list.className = '';
    list.innerHTML = events.map(event => {
        const color = event.severity === 'danger'
            ? '#dc3545'
            : (event.severity === 'warning' ? '#f59e0b' : (event.severity === 'secondary' ? '#64748b' : '#0d6efd'));
        const navLink = event.lat && event.lng
            ? '<a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer" href="https://www.google.com/maps?q=' + encodeURIComponent(event.lat + ',' + event.lng) + '"><i class="bi bi-geo-alt-fill"></i></a>'
            : '';
        const resolveButton = canResolveEvents && !event.is_resolved
            ? '<button type="button" class="btn btn-sm btn-outline-success" data-resolve-event="' + event.id + '"><i class="bi bi-check2-circle"></i></button>'
            : '';
        const resolvedText = event.is_resolved
            ? '<div class="ride-meta text-success">Επιλύθηκε' + (event.resolved_by_name ? ' από ' + htmlEscape(event.resolved_by_name) : '') + '</div>'
            : '';
        return '<div class="participant-row">'
            + '<div><div class="fw-semibold"><span class="participant-dot" style="background:' + color + '"></span>' + htmlEscape(event.title) + '</div>'
            + '<div class="ride-meta">' + htmlEscape(event.created_at || '') + ' · ' + htmlEscape(event.message || '') + '</div>'
            + resolvedText + '</div>'
            + '<div class="d-flex gap-1">' + resolveButton + navLink + '</div>'
            + '</div>';
    }).join('');
}

async function resolveRideEvent(eventId) {
    const body = new URLSearchParams({
        csrf_token: csrfTokenValue,
        event_id: String(eventId)
    });
    try {
        const response = await fetch('api-ride-event-resolve.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body
        });
        const data = await response.json();
        if (!data.ok) {
            showGpsError(data.error || 'Δεν επιλύθηκε το συμβάν.');
            return;
        }
        clearGpsError();
        refreshRideData();
    } catch (error) {
        showGpsError('Δεν επιλύθηκε το συμβάν.');
    }
}

async function refreshRideData() {
    try {
        const response = await fetch('api-ride-data.php?mission_id=' + encodeURIComponent(missionId), {
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!data.ok) return;
        canResolveEvents = !!data.can_resolve_events;
        renderReadiness(data.readiness || {});
        renderChecklist(data.checklist || {});
        renderParticipants(data.participants || []);
        renderAlerts(data.alerts || []);
        renderPartnerAssist(data.partner_assists || [], data.partner_pins || []);
        renderEvents(data.events || []);
    } catch (error) {
        // Keep the screen quiet while riding; the next poll will retry.
    }
}

async function captureBattery() {
    if (!navigator.getBattery) return;
    try {
        const battery = await navigator.getBattery();
        batteryLevel = Math.round((battery.level || 0) * 100);
        battery.addEventListener('levelchange', () => {
            batteryLevel = Math.round((battery.level || 0) * 100);
        });
    } catch (error) {}
}

async function requestWakeLock() {
    if (!('wakeLock' in navigator)) return;
    try {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', () => { wakeLock = null; });
    } catch (error) {}
}

async function releaseWakeLock() {
    if (!wakeLock) return;
    try { await wakeLock.release(); } catch (error) {}
    wakeLock = null;
}

async function sendPing(status = '') {
    if (!lastPosition) {
        return false;
    }
    const coords = lastPosition.coords;
    const body = new URLSearchParams({
        csrf_token: csrfTokenValue,
        mission_id: String(missionId),
        shift_id: String(shiftId),
        lat: String(coords.latitude),
        lng: String(coords.longitude),
        accuracy: coords.accuracy != null ? String(coords.accuracy) : '',
        speed: coords.speed != null ? String(coords.speed) : '',
        heading: coords.heading != null ? String(coords.heading) : '',
        battery_level: batteryLevel != null ? String(batteryLevel) : '',
        status
    });

    const response = await fetch('api-ride-ping.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body
    });
    const data = await response.json();
    if (!data.ok) {
        showGpsError(data.error || 'Δεν στάλθηκε το στίγμα.');
        return false;
    }

    lastSentAt = Date.now();
    if (status) lastStatus = status;
    lastPingText.textContent = 'Στίγμα ' + data.ts;
    clearGpsError();
    refreshRideData();
    return true;
}

function updateSelfPosition(position) {
    lastPosition = position;
    const coords = [position.coords.latitude, position.coords.longitude];
    if (!selfMarker) {
        const icon = L.divIcon({ className: '', html: markerHtml('#2563eb', true, false), iconSize: [26, 26], iconAnchor: [13, 13] });
        selfMarker = L.marker(coords, { icon }).addTo(rideMap).bindPopup('Η θέση μου');
        rideMap.setView(coords, Math.max(rideMap.getZoom(), 15));
    } else {
        selfMarker.setLatLng(coords);
    }
}

function startTracking() {
    if (!navigator.geolocation) {
        showGpsError('Ο browser δεν υποστηρίζει GPS.');
        return;
    }
    if (!window.isSecureContext) {
        secureWarning.classList.remove('d-none');
        return;
    }

    tracking = true;
    setPill(true, 'Ride Mode ενεργό');
    startBtn.disabled = true;
    stopBtn.disabled = false;
    clearGpsError();
    requestWakeLock();

    watchId = navigator.geolocation.watchPosition(async position => {
        updateSelfPosition(position);
        const elapsed = Date.now() - lastSentAt;
        if (elapsed > 10000) {
            await sendPing(lastStatus || 'on_way');
        }
    }, error => {
        showGpsError(error.message || 'Δεν ήταν δυνατή η λήψη GPS.');
    }, {
        enableHighAccuracy: true,
        maximumAge: 5000,
        timeout: 15000
    });
}

function stopTracking() {
    tracking = false;
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }
    releaseWakeLock();
    setPill(false, 'Ανενεργό');
    startBtn.disabled = false;
    stopBtn.disabled = true;
}

function sendStatus(status) {
    lastStatus = status;
    if (!lastPosition && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(async position => {
            updateSelfPosition(position);
            await sendPing(status);
        }, error => {
            showGpsError(error.message || 'Δεν ήταν δυνατή η λήψη GPS.');
        }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 });
        return;
    }
    sendPing(status);
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && tracking) {
        requestWakeLock();
        rideMap.invalidateSize();
    }
});

startBtn.addEventListener('click', startTracking);
stopBtn.addEventListener('click', stopTracking);
document.querySelectorAll('[data-status]').forEach(button => {
    button.addEventListener('click', () => sendStatus(button.dataset.status));
});
document.addEventListener('click', event => {
    const button = event.target.closest('[data-resolve-event]');
    if (button) {
        resolveRideEvent(button.dataset.resolveEvent);
        return;
    }

    const checklistInput = event.target.closest('.ride-checklist-input');
    if (checklistInput) {
        updateChecklistItem(checklistInput.dataset.itemKey, checklistInput.checked);
    }
});

if (!window.isSecureContext) {
    secureWarning.classList.remove('d-none');
}

initMap();
captureBattery();
refreshRideData();
setInterval(refreshRideData, 10000);
setInterval(() => {
    if (tracking && lastPosition && Date.now() - lastSentAt > 20000) {
        sendPing(lastStatus || 'on_way');
    }
}, 5000);
</script>
</body>
</html>
