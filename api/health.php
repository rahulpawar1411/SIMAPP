<?php
/**
 * Combined health check — own app + Elixia API in one URL.
 *
 * GET /api/health.php
 *
 * No login required. Does not expose secrets (no DB password / Elixia userkey).
 * HTTP 200 = all critical checks OK · 503 = any critical check failed.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Config\Database;
use App\Services\ElixiaVtsService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$started = microtime(true);
$checks = [];
$ok = true;

// ── PHP ──────────────────────────────────────────────
$ext = [
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'curl' => extension_loaded('curl'),
    'json' => extension_loaded('json'),
    'mbstring' => extension_loaded('mbstring'),
];
$phpOk = !in_array(false, $ext, true);
$checks['php'] = [
    'ok' => $phpOk,
    'version' => PHP_VERSION,
    'extensions' => $ext,
];
if (!$phpOk) {
    $ok = false;
}

// ── Database (own app) ───────────────────────────────
$dbStart = microtime(true);
try {
    $pdo = Database::getConnection();
    $pdo->query('SELECT 1');
    $freezers = (int)$pdo->query('SELECT COUNT(*) FROM freezers WHERE deleted_at IS NULL')->fetchColumn();
    $checks['database'] = [
        'ok' => true,
        'latency_ms' => (int)round((microtime(true) - $dbStart) * 1000),
        'freezers' => $freezers,
    ];
} catch (Throwable $e) {
    $ok = false;
    $checks['database'] = [
        'ok' => false,
        'latency_ms' => (int)round((microtime(true) - $dbStart) * 1000),
        'error' => 'Database unreachable',
    ];
}

// ── Uploads (own app) ────────────────────────────────
$uploadDir = AppConfig::UPLOAD_DIR;
$writable = is_dir($uploadDir) && is_writable($uploadDir);
$checks['uploads'] = [
    'ok' => $writable,
    'path_exists' => is_dir($uploadDir),
    'writable' => $writable,
];
if (!$writable) {
    $ok = false;
}

// ── Elixia API ───────────────────────────────────────
$configOk = AppConfig::ELIXIA_VTS_ENABLED
    && trim((string)AppConfig::ELIXIA_VTS_USERKEY) !== ''
    && function_exists('curl_init');

$checks['elixia_config'] = [
    'ok' => $configOk,
    'enabled' => AppConfig::ELIXIA_VTS_ENABLED,
    'userkey_set' => trim((string)AppConfig::ELIXIA_VTS_USERKEY) !== '',
    'curl' => function_exists('curl_init'),
];
if (!$configOk) {
    $ok = false;
}

if ($configOk) {
    $apiStart = microtime(true);
    try {
        $probe = ElixiaVtsService::fetchPage('', 1, 1);
        $apiOk = !empty($probe['ok']);
        $devices = is_array($probe['devices'] ?? null) ? $probe['devices'] : [];
        $checks['elixia_api'] = [
            'ok' => $apiOk,
            'latency_ms' => (int)round((microtime(true) - $apiStart) * 1000),
            'action' => 'getVehicleDataV1',
            'devices_returned' => count($devices),
            'total_reported' => isset($probe['total']) ? (int)$probe['total'] : null,
            'error' => $apiOk ? null : (string)($probe['error'] ?? 'Elixia Status not OK'),
        ];
        if (!$apiOk) {
            $ok = false;
        }
    } catch (Throwable $e) {
        $ok = false;
        $checks['elixia_api'] = [
            'ok' => false,
            'latency_ms' => (int)round((microtime(true) - $apiStart) * 1000),
            'error' => 'Elixia request exception',
        ];
    }
} else {
    $checks['elixia_api'] = [
        'ok' => false,
        'skipped' => true,
        'error' => !AppConfig::ELIXIA_VTS_ENABLED
            ? 'Elixia VTS is disabled'
            : (!function_exists('curl_init') ? 'cURL missing' : 'Elixia userkey not configured'),
    ];
}

$cacheFile = AppConfig::ELIXIA_VTS_CACHE_FILE;
$cacheInfo = [
    'exists' => is_file($cacheFile),
    'age_seconds' => null,
    'within_ttl' => false,
];
if (is_file($cacheFile)) {
    $age = max(0, time() - (int)filemtime($cacheFile));
    $cacheInfo['age_seconds'] = $age;
    $cacheInfo['within_ttl'] = $age < (int)AppConfig::ELIXIA_VTS_CACHE_SECONDS;
}
$checks['elixia_cache'] = $cacheInfo;

// ── Summary ──────────────────────────────────────────
$summary = [
    'app' => !empty($checks['php']['ok']) && !empty($checks['database']['ok']) && !empty($checks['uploads']['ok']),
    'elixia' => !empty($checks['elixia_config']['ok']) && !empty($checks['elixia_api']['ok']),
];

http_response_code($ok ? 200 : 503);

echo json_encode([
    'ok' => $ok,
    'status' => $ok ? 'healthy' : 'unhealthy',
    'summary' => $summary,
    'service' => 'reeferon',
    'app' => AppConfig::APP_SHORT_NAME,
    'version' => AppConfig::VERSION,
    'time' => gmdate('c'),
    'latency_ms' => (int)round((microtime(true) - $started) * 1000),
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
