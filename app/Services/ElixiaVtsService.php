<?php
namespace App\Services;

use App\Config\AppConfig;

/**
 * Elixia VTS warehouse API helper.
 * Matches Elixia vehicleno to our Unique Freezer ID (normalized).
 */
class ElixiaVtsService {

    /**
     * Normalize IDs so "DF-RW-... MAA1-..." matches "DF-RW-...MAA1-...".
     */
    public static function normalizeId(string $id): string {
        $id = strtoupper(trim($id));
        $id = preg_replace('/\s+/', '', $id) ?? $id;
        return $id;
    }

    /**
     * Map one API row to a small safe array for the profile UI.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function mapDevice(array $row): array {
        $lat = isset($row['lat']) && $row['lat'] !== '' ? (float)$row['lat'] : null;
        $lng = isset($row['lng']) && $row['lng'] !== '' ? (float)$row['lng'] : null;
        $location = trim((string)($row['location'] ?? ''));
        if ($location === '') {
            $location = trim((string)($row['locationName'] ?? ''));
        }
        return [
            'vehicleno' => (string)($row['vehicleno'] ?? ''),
            'vehicleid' => $row['vehicleid'] ?? null,
            'kind' => (string)($row['kind'] ?? ''),
            'unitno' => (string)($row['unitno'] ?? ''),
            'groupname' => (string)($row['groupname'] ?? ''),
            'status' => (string)($row['vehiclestatus'] ?? $row['vehiclestatus_new'] ?? ''),
            'status_new' => (string)($row['vehiclestatus_new'] ?? ''),
            'lat' => $lat,
            'lng' => $lng,
            'location' => $location,
            'lastupdated' => (string)($row['lastupdated'] ?? ''),
            'vehiclespeed' => $row['vehiclespeed'] ?? null,
            'odometer' => $row['odometer'] ?? null,
            'distance' => $row['distance'] ?? null,
            'digitalstatus' => (string)($row['digitalstatus'] ?? ''),
            'temperature1' => (string)($row['temperature1'] ?? ''),
            'temperature2' => (string)($row['temperature2'] ?? ''),
            'temperature3' => (string)($row['temperature3'] ?? ''),
            'temperature4' => (string)($row['temperature4'] ?? ''),
            'drivername' => (string)($row['drivername'] ?? ''),
            'ignition' => $row['ignition'] ?? null,
            'timestamp' => (string)($row['timestamp'] ?? ''),
            'utctimestamp' => (string)($row['utctimestamp'] ?? ''),
            'systemtime' => (string)($row['systemtime'] ?? ''),
            'utcsystemtime' => (string)($row['utcsystemtime'] ?? ''),
            'doorSensor1' => (string)($row['doorSensor1'] ?? ''),
            'doorSensor2' => (string)($row['doorSensor2'] ?? ''),
            'idleSince' => $row['idleSince'] ?? null,
            'direction' => $row['direction'] ?? null,
            'deviceId' => $row['deviceId'] ?? null,
            'type' => (string)($row['type'] ?? ''),
            'vehicleMotionStatus' => (string)($row['vehicleMotionStatus'] ?? ''),
            'deviceTrackingState' => (string)($row['deviceTrackingState'] ?? ''),
            'shorturl' => (string)($row['shorturl'] ?? ''),
        ];
    }

    /**
     * Call getVehicleDataV1 once.
     *
     * @return array{ok:bool,error?:string,devices?:list<array>,total?:int,raw_status?:string}
     */
    public static function fetchPage(string $search = '', int $pageIndex = 1, int $pageSize = 40): array {
        $userkey = trim((string)AppConfig::ELIXIA_VTS_USERKEY);
        if ($userkey === '') {
            return ['ok' => false, 'error' => 'Elixia userkey is not configured.'];
        }

        $payload = [
            'userkey' => $userkey,
            'searchstring' => $search,
            'pageindex' => max(1, $pageIndex),
            'pagesize' => max(1, min(40, $pageSize)),
            'isWarehouse' => 1,
        ];
        $url = rtrim(AppConfig::ELIXIA_VTS_BASE_URL, '?&')
            . '?action=getVehicleDataV1&jsonreq=' . rawurlencode(json_encode($payload));

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'PHP cURL extension is required for Elixia.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'ReeferON-DF/2.1 ElixiaVts',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $body === false) {
            return ['ok' => false, 'error' => 'Elixia request failed: ' . ($err ?: 'network error')];
        }
        if ($http >= 400) {
            return ['ok' => false, 'error' => 'Elixia HTTP ' . $http];
        }

        $trim = ltrim((string)$body);
        if ($trim === '' || $trim[0] === '<') {
            return ['ok' => false, 'error' => 'Elixia returned HTML instead of JSON (use HTTP URL).'];
        }

        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'Elixia response is not valid JSON.'];
        }

        $status = (string)($json['Status'] ?? '');
        if ($status !== '1' && $status !== 'true') {
            return [
                'ok' => false,
                'error' => (string)($json['Message'] ?? 'Elixia Status=' . $status),
                'raw_status' => $status,
            ];
        }

        $result = is_array($json['Result'] ?? null) ? $json['Result'] : [];
        $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
        $devices = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $devices[] = self::mapDevice($row);
            }
        }

        return [
            'ok' => true,
            'devices' => $devices,
            'total' => (int)($result['totalVehicleCount'] ?? count($devices)),
            'raw_status' => $status,
        ];
    }

    /**
     * Search API by string (partial Unique Freezer ID works best).
     *
     * @return array{ok:bool,error?:string,devices?:list<array>,total?:int}
     */
    public static function fetchSearch(string $search): array {
        return self::fetchPage(trim($search), 1, AppConfig::ELIXIA_VTS_PAGE_SIZE);
    }

    /**
     * Cached full warehouse list (paged).
     *
     * @return array{ok:bool,error?:string,devices?:list<array>,total?:int,from_cache?:bool}
     */
    public static function fetchWarehouseList(bool $forceRefresh = false): array {
        if (!AppConfig::ELIXIA_VTS_ENABLED) {
            return ['ok' => false, 'error' => 'Elixia VTS is disabled.'];
        }

        $cacheFile = AppConfig::ELIXIA_VTS_CACHE_FILE;
        $ttl = (int)AppConfig::ELIXIA_VTS_CACHE_SECONDS;
        if (!$forceRefresh && is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['ok']) && isset($cached['devices'])) {
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        $all = [];
        $total = null;
        $page = 1;
        $pageSize = (int)AppConfig::ELIXIA_VTS_PAGE_SIZE;
        $maxPages = 20;

        while ($page <= $maxPages) {
            $chunk = self::fetchPage('', $page, $pageSize);
            if (empty($chunk['ok'])) {
                return $chunk;
            }
            $devices = $chunk['devices'] ?? [];
            $total = (int)($chunk['total'] ?? $total ?? 0);
            foreach ($devices as $d) {
                $all[] = $d;
            }
            if (count($devices) < $pageSize) {
                break;
            }
            if ($total > 0 && count($all) >= $total) {
                break;
            }
            $page++;
        }

        $out = [
            'ok' => true,
            'devices' => $all,
            'total' => $total ?? count($all),
            'from_cache' => false,
        ];

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($cacheFile, json_encode($out));

        return $out;
    }

    /**
     * Find one Elixia device for a Unique Freezer ID.
     *
     * @return array{ok:bool,found:bool,device:?array,error?:string}
     */
    public static function findByUniqueFreezerId(string $uniqueId, bool $forceRefresh = false): array {
        if (!AppConfig::ELIXIA_VTS_ENABLED) {
            return ['ok' => false, 'found' => false, 'device' => null, 'error' => 'Elixia VTS is disabled.'];
        }

        $want = self::normalizeId($uniqueId);
        if ($want === '') {
            return ['ok' => false, 'found' => false, 'device' => null, 'error' => 'Missing Unique Freezer ID.'];
        }

        // On refresh, bust warehouse cache first so fallback also has fresh rows.
        if ($forceRefresh) {
            self::fetchWarehouseList(true);
        }

        // Fast path: live API search with a distinctive fragment of the ID
        $parts = explode('-', $want);
        $searchHint = $want;
        if (count($parts) >= 4) {
            // e.g. AMD1 / MUM1 segment — good for searchstring
            $searchHint = $parts[3];
        }
        $search = self::fetchSearch($searchHint);
        if (!empty($search['ok'])) {
            foreach ($search['devices'] ?? [] as $d) {
                if (self::normalizeId((string)($d['vehicleno'] ?? '')) === $want) {
                    return ['ok' => true, 'found' => true, 'device' => $d];
                }
            }
        }

        // Fallback: scan warehouse list (already refreshed when forceRefresh)
        $list = self::fetchWarehouseList($forceRefresh);
        if (empty($list['ok'])) {
            return [
                'ok' => false,
                'found' => false,
                'device' => null,
                'error' => $list['error'] ?? ($search['error'] ?? 'Elixia lookup failed.'),
            ];
        }
        foreach ($list['devices'] ?? [] as $d) {
            if (self::normalizeId((string)($d['vehicleno'] ?? '')) === $want) {
                return ['ok' => true, 'found' => true, 'device' => $d];
            }
        }

        return ['ok' => true, 'found' => false, 'device' => null];
    }
}
