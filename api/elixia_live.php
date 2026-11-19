<?php
/**
 * JSON: Elixia live row for one Unique Freezer ID.
 * GET ?unique_id=DF-...&refresh=1
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Services\AuthService;
use App\Services\ElixiaVtsService;
use App\Services\AppLogger;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    AuthService::requireLogin();

    if (!AppConfig::ELIXIA_VTS_ENABLED) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'found' => false, 'error' => 'Elixia VTS is disabled.']);
        exit;
    }

    $uniqueId = trim((string)($_GET['unique_id'] ?? $_GET['id'] ?? ''));
    if ($uniqueId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'found' => false, 'error' => 'unique_id is required.']);
        exit;
    }

    $force = !empty($_GET['refresh']) || !empty($_GET['force']);
    $match = ElixiaVtsService::findByUniqueFreezerId($uniqueId, $force);

    echo json_encode([
        'ok' => !empty($match['ok']),
        'found' => !empty($match['found']),
        'device' => $match['device'] ?? null,
        'error' => $match['error'] ?? null,
        'unique_id' => $uniqueId,
        'normalized_id' => ElixiaVtsService::normalizeId($uniqueId),
    ]);
} catch (Throwable $e) {
    AppLogger::exception($e, 'api.elixia_live');
    http_response_code(500);
    echo json_encode(['ok' => false, 'found' => false, 'error' => 'Server error loading Elixia data.']);
}
