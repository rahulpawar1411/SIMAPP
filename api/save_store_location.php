<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AuthService;
use App\Models\Store;
use App\Models\Freezer;

AuthService::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$raw = file_get_contents('php://input');
$json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$data = is_array($json) ? $json : $_POST;

if (!AuthService::validateCsrfToken($data['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$storeId = (int)($data['store_id'] ?? 0);
$freezerId = (int)($data['freezer_id'] ?? 0);
$lat = isset($data['latitude']) ? (float)$data['latitude'] : null;
$lng = isset($data['longitude']) ? (float)$data['longitude'] : null;
$acc = isset($data['gps_accuracy']) && $data['gps_accuracy'] !== '' && $data['gps_accuracy'] !== null
    ? (float)$data['gps_accuracy']
    : null;

if ($storeId <= 0 && $freezerId > 0) {
    $freezer = Freezer::findById($freezerId);
    if ($freezer) {
        $storeId = (int)($freezer['store_id'] ?? 0);
    }
}

if ($storeId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'store_id required']);
    exit;
}

if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid latitude and longitude required']);
    exit;
}

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Coordinates out of range']);
    exit;
}

$store = Store::findById($storeId);
if (!$store) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Store not found']);
    exit;
}

$providedLink = trim((string)($data['location_link'] ?? ''));
$locationLink = Store::resolveLocationLink($providedLink !== '' ? $providedLink : null, $lat, $lng);

$ok = Store::updateGps($storeId, $lat, $lng, $acc, $locationLink);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save location']);
    exit;
}

echo json_encode([
    'success' => true,
    'store_id' => $storeId,
    'latitude' => round($lat, 8),
    'longitude' => round($lng, 8),
    'gps_accuracy' => $acc,
    'location_link' => $locationLink,
    'gps_captured_at' => date('Y-m-d H:i:s'),
]);
