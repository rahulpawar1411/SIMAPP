<?php
// API Endpoint: Dynamic Freezer ID Generation & Duplicate Validation

header('Content-Type: application/json');
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\FreezerIdGenerator;

$type = $_GET['type'] ?? 'DF';
$brand = $_GET['brand'] ?? 'RW';
$model = $_GET['model'] ?? 'SFR550DDU';
$loc = $_GET['loc'] ?? 'AMD1';
$pin = $_GET['pin'] ?? '380058';
$mmyy = $_GET['mmyy'] ?? date('my');
$seqRaw = trim((string)($_GET['seq'] ?? ''));
$autoSeq = ($seqRaw === '' || strtoupper($seqRaw) === 'AUTO');
$seq = $autoSeq ? '' : strtoupper($seqRaw);
$excludeId = !empty($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;

try {
    $prefix = FreezerIdGenerator::buildPrefix($type, $brand, $model, $loc, $pin, $mmyy);
    $autoGen = FreezerIdGenerator::generateUniqueId($type, $brand, $model, $loc, $pin, $mmyy, null, $excludeId);

    if ($autoSeq) {
        $currentCandidate = $autoGen['freezer_id'];
        $isDuplicate = false;
        $seqOut = $autoGen['unit_sequence'];
    } else {
        $currentCandidate = $prefix . $seq;
        $isDuplicate = FreezerIdGenerator::isDuplicate($currentCandidate, $excludeId);
        $seqOut = $seq;
    }

    echo json_encode([
        'success' => true,
        'auto_seq' => $autoSeq,
        'candidate_id' => $currentCandidate,
        'is_duplicate' => $isDuplicate,
        'suggested_id' => $autoGen['freezer_id'],
        'suggested_seq' => $autoGen['unit_sequence'],
        'prefix' => $prefix,
        'parts' => [
            'type' => strtoupper(trim($type)),
            'brand' => strtoupper(trim($brand)),
            'model' => FreezerIdGenerator::sanitizeModel($model),
            'location' => strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $loc))),
            'pin_last3' => FreezerIdGenerator::extractPinCode($pin),
            'pin_code' => FreezerIdGenerator::extractPinCode($pin),
            'mmyy' => FreezerIdGenerator::formatInstallMonthYear($mmyy),
            'seq' => $seqOut,
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
