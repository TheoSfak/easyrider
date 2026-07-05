<?php
/**
 * AJAX endpoint — snap a mission route to roads via Google Routes API.
 * POST only, login required.
 * Returns JSON:
 *   { ok: true, provider: 'google', geometry: [[lat,lng],...], distance_meters, duration_seconds }
 *   { ok: true, provider: 'estimate' }  — no API key or Google failed; caller keeps the local estimate
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

function routeDirectionsFallback(string $reason = ''): void {
    $response = ['ok' => true, 'provider' => 'estimate'];
    if ($reason !== '') {
        $response['reason'] = $reason;
    }
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρη μέθοδος']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$rawPoints = is_array($payload) ? ($payload['points'] ?? null) : null;
if (!is_array($rawPoints)) {
    echo json_encode(['ok' => false, 'error' => 'Δεν δόθηκαν σημεία διαδρομής']);
    exit;
}

$points = [];
foreach ($rawPoints as $point) {
    if (!is_array($point) || !isset($point['lat'], $point['lng']) || !is_numeric($point['lat']) || !is_numeric($point['lng'])) {
        continue;
    }
    $lat = (float)$point['lat'];
    $lng = (float)$point['lng'];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        continue;
    }
    $points[] = ['lat' => $lat, 'lng' => $lng];
    if (count($points) >= 300) {
        break;
    }
}

if (count($points) < 2) {
    echo json_encode(['ok' => false, 'error' => 'Απαιτούνται τουλάχιστον 2 σημεία']);
    exit;
}

$apiKey = trim((string)getSetting('google_maps_api_key', ''));
if ($apiKey === '' || !function_exists('curl_init')) {
    routeDirectionsFallback($apiKey === '' ? 'no_api_key' : 'no_curl');
}

// Routes API allows up to 25 intermediate waypoints — thin evenly if needed
$origin = array_shift($points);
$destination = array_pop($points);
$intermediates = $points;
if (count($intermediates) > 25) {
    $thinned = [];
    $step = count($intermediates) / 25;
    for ($i = 0; $i < 25; $i++) {
        $thinned[] = $intermediates[(int)floor($i * $step)];
    }
    $intermediates = $thinned;
}

$toWaypoint = fn(array $p) => ['location' => ['latLng' => ['latitude' => $p['lat'], 'longitude' => $p['lng']]]];

$body = [
    'origin' => $toWaypoint($origin),
    'destination' => $toWaypoint($destination),
    'travelMode' => 'DRIVE',
    'polylineEncoding' => 'GEO_JSON_LINESTRING',
];
if (!empty($intermediates)) {
    $body['intermediates'] = array_map($toWaypoint, $intermediates);
}

$ch = curl_init('https://routes.googleapis.com/directions/v2:computeRoutes');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $apiKey,
        'X-Goog-FieldMask: routes.distanceMeters,routes.duration,routes.polyline.geoJsonLinestring',
    ],
]);

$responseBody = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200 || !$responseBody) {
    routeDirectionsFallback('google_http_' . ($curlError ? 'error' : $httpCode));
}

$decoded = json_decode($responseBody, true);
$route = $decoded['routes'][0] ?? null;
if (!is_array($route)) {
    routeDirectionsFallback('google_no_route');
}

$distanceMeters = (int)($route['distanceMeters'] ?? 0);
$durationSeconds = (int)round((float)rtrim((string)($route['duration'] ?? '0s'), 's'));
$coordinates = $route['polyline']['geoJsonLinestring']['coordinates'] ?? null;

if ($distanceMeters <= 0 || $durationSeconds <= 0 || !is_array($coordinates) || count($coordinates) < 2) {
    routeDirectionsFallback('google_incomplete');
}

// GeoJSON is [lng, lat] — flip to [lat, lng] for Leaflet, cap the payload size
$geometry = [];
foreach ($coordinates as $pair) {
    if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
        continue;
    }
    $geometry[] = [round((float)$pair[1], 6), round((float)$pair[0], 6)];
    if (count($geometry) >= 20000) {
        break;
    }
}

if (count($geometry) < 2) {
    routeDirectionsFallback('google_bad_geometry');
}

echo json_encode([
    'ok' => true,
    'provider' => 'google',
    'geometry' => $geometry,
    'distance_meters' => $distanceMeters,
    'duration_seconds' => $durationSeconds,
]);
