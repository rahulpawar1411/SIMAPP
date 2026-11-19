<?php
/**
 * JSON: Elixia warehouse device list.
 * GET ?q=&status=all|active|inactive&refresh=1
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
        echo json_encode(['ok' => false, 'error' => 'Elixia VTS is disabled.', 'devices' => []]);
        exit;
    }

    $search = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
    $statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
    if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
        $statusFilter = 'all';
    }
    $force = !empty($_GET['refresh']) || !empty($_GET['force']);

    $result = ElixiaVtsService::fetchWarehouseList($force);
    if (empty($result['ok'])) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => (string)($result['error'] ?? 'Could not load Elixia list.'),
            'devices' => [],
        ]);
        exit;
    }

    $isActiveDevice = static function (array $d): bool {
        $raw = strtolower(trim((string)($d['status'] ?? $d['status_new'] ?? '')));
        $ign = $d['ignition'] ?? null;
        if ($raw !== '') {
            if (str_contains($raw, 'inactive') || str_contains($raw, 'offline') || str_contains($raw, 'ignition off')) {
                return false;
            }
            if (str_contains($raw, 'ignition on')
                || str_contains($raw, 'idle')
                || str_contains($raw, 'active')
                || str_contains($raw, 'running')
                || str_contains($raw, 'moving')
                || str_contains($raw, 'online')) {
                return true;
            }
        }
        return ((string)$ign === '1' || $ign === 1 || $ign === true);
    };

    $devices = is_array($result['devices'] ?? null) ? $result['devices'] : [];
    $filtered = [];
    $qNorm = ElixiaVtsService::normalizeId($search);
    $qLower = strtolower($search);

    foreach ($devices as $d) {
        if (!is_array($d)) {
            continue;
        }
        $active = $isActiveDevice($d);
        if ($statusFilter === 'active' && !$active) {
            continue;
        }
        if ($statusFilter === 'inactive' && $active) {
            continue;
        }
        if ($search !== '') {
            $hay = strtolower(implode(' ', [
                (string)($d['vehicleno'] ?? ''),
                (string)($d['vehicleid'] ?? ''),
                (string)($d['unitno'] ?? ''),
                (string)($d['status'] ?? ''),
                (string)($d['location'] ?? ''),
                (string)($d['groupname'] ?? ''),
            ]));
            $vehNorm = ElixiaVtsService::normalizeId((string)($d['vehicleno'] ?? ''));
            $matched = ($qNorm !== '' && str_contains($vehNorm, $qNorm))
                || ($qLower !== '' && str_contains($hay, $qLower));
            if (!$matched) {
                continue;
            }
        }
        $d['active'] = $active;
        $filtered[] = $d;
    }

    $activeCount = 0;
    $inactiveCount = 0;
    foreach ($devices as $d) {
        if (!is_array($d)) {
            continue;
        }
        if ($isActiveDevice($d)) {
            $activeCount++;
        } else {
            $inactiveCount++;
        }
    }

    echo json_encode([
        'ok' => true,
        'devices' => $filtered,
        'total' => (int)($result['total'] ?? count($devices)),
        'shown' => count($filtered),
        'active_count' => $activeCount,
        'inactive_count' => $inactiveCount,
        'from_cache' => !empty($result['from_cache']) && !$force,
        'q' => $search,
        'status' => $statusFilter,
    ]);
} catch (Throwable $e) {
    AppLogger::exception($e, 'api.elixia_list');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error loading Elixia list.', 'devices' => []]);
}
