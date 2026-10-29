<?php
// API Endpoint: IoT Temperature & Status Telemetry Monitor & Ingestion

header('Content-Type: application/json');
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\AppConfig;
use App\Models\IoTDevice;
use App\Models\Freezer;

if (!AppConfig::IOT_ENABLED) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'IoT is temporarily disabled']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Handle GET request: fetch recent telemetry
if ($method === 'GET') {
    $freezerId = !empty($_GET['freezer_id']) ? (int)$_GET['freezer_id'] : null;
    $uniqueId = !empty($_GET['unique_id']) ? trim($_GET['unique_id']) : null;

    if (!$freezerId && $uniqueId) {
        $freezer = Freezer::findByUniqueId($uniqueId);
        if ($freezer) {
            $freezerId = (int)$freezer['id'];
        }
    }

    if (!$freezerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'freezer_id or unique_id required']);
        exit;
    }

    $history = IoTDevice::getRecentTelemetry($freezerId, 30);
    $latest = IoTDevice::getLatestTelemetry($freezerId);
    $device = IoTDevice::findByFreezerId($freezerId);

    echo json_encode([
        'success' => true,
        'freezer_id' => $freezerId,
        'latest' => $latest,
        'device' => $device,
        'history' => $history
    ]);
    exit;
}

// Handle POST request: Ingest reading or simulate ping
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? 'record';
    $freezerId = !empty($input['freezer_id']) ? (int)$input['freezer_id'] : null;

    if (!$freezerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'freezer_id is required']);
        exit;
    }

    if ($action === 'simulate_ping') {
        // Generate a random realistic reading around -20°C
        $temp = round(-21.0 + (rand(-15, 15) / 10), 1);
        $compressor = ($temp > -19.5);
        $door = (rand(1, 10) === 1);
        $batt = rand(92, 99);

        $id = IoTDevice::recordTelemetry($freezerId, $temp, $compressor, $door, 'ON', $batt);
        $latest = IoTDevice::getLatestTelemetry($freezerId);

        echo json_encode([
            'success' => true,
            'message' => 'Simulated sensor ping recorded successfully',
            'reading' => $latest
        ]);
        exit;
    }

    // Standard ingest
    $temp = isset($input['temperature']) ? (float)$input['temperature'] : -20.0;
    $compressor = !empty($input['compressor_running']);
    $door = !empty($input['door_open']);
    $pwr = $input['power_status'] ?? 'ON';
    $batt = isset($input['battery_level']) ? (int)$input['battery_level'] : 98;

    $id = IoTDevice::recordTelemetry($freezerId, $temp, $compressor, $door, $pwr, $batt);

    echo json_encode([
        'success' => true,
        'telemetry_id' => $id,
        'message' => 'Telemetry logged successfully'
    ]);
    exit;
}
