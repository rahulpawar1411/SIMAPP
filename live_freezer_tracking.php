<?php
/**
 * Live freezer tracking — Elixia list + click-through device details.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Config\Database;
use App\Services\AuthService;
use App\Services\ElixiaVtsService;

AuthService::requireLogin();

$pageTitle = 'Live freezer tracking';
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}
$forceRefresh = !empty($_GET['refresh']);
$viewId = trim((string)($_GET['vid'] ?? ''));
$lazyList = ($viewId === '');

$apiOk = false;
$apiError = null;
$fromCache = false;
$devices = [];
$totalApi = 0;

if (!AppConfig::ELIXIA_VTS_ENABLED) {
    $apiError = 'Elixia VTS is disabled in config.';
} elseif (!$lazyList) {
    // Detail view still resolves on server; list view lazy-loads via API.
    $result = ElixiaVtsService::fetchWarehouseList($forceRefresh);
    $apiOk = !empty($result['ok']);
    $apiError = $result['error'] ?? null;
    $fromCache = !empty($result['from_cache']);
    $devices = is_array($result['devices'] ?? null) ? $result['devices'] : [];
    $totalApi = (int)($result['total'] ?? count($devices));
} else {
    $apiOk = true; // shell only; rows come from JS
}

$isActiveDevice = static function (array $d): bool {
    $raw = strtolower(trim((string)($d['status'] ?? $d['status_new'] ?? '')));
    $ign = $d['ignition'] ?? null;

    // Explicitly offline / inactive
    if ($raw !== '') {
        if (str_contains($raw, 'inactive') || str_contains($raw, 'offline') || str_contains($raw, 'ignition off')) {
            return false;
        }
        // Idle + Ignition On, Active, Running, etc. = live / active
        if (str_contains($raw, 'ignition on')
            || str_contains($raw, 'idle')
            || str_contains($raw, 'active')
            || str_contains($raw, 'running')
            || str_contains($raw, 'moving')
            || str_contains($raw, 'online')) {
            return true;
        }
    }

    // Fallback: Elixia ignition flag
    return ((string)$ign === '1' || $ign === 1 || $ign === true);
};

$dash = static function ($v): string {
    if ($v === null) {
        return '—';
    }
    if (is_bool($v)) {
        return $v ? 'Yes' : 'No';
    }
    $s = trim((string)$v);
    return $s !== '' ? $s : '—';
};

$ignitionLabel = static function ($v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    if ((string)$v === '1' || $v === 1 || $v === true) {
        return 'ON';
    }
    if ((string)$v === '0' || $v === 0 || $v === false) {
        return 'OFF';
    }
    return (string)$v;
};

$idleLabel = static function ($v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    $sec = (int)$v;
    if ($sec <= 0) {
        return '0s';
    }
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    $parts = [];
    if ($d > 0) {
        $parts[] = $d . 'd';
    }
    if ($h > 0) {
        $parts[] = $h . 'h';
    }
    if ($m > 0 || !$parts) {
        $parts[] = $m . 'm';
    }
    return implode(' ', $parts);
};

$localByNorm = [];
try {
    $pdo = Database::getConnection();
    $rows = $pdo->query(
        "SELECT f.id, f.unique_freezer_id, s.store_name, s.outlet_id, s.city, s.zone
         FROM freezers f
         INNER JOIN stores s ON s.id = f.store_id
         WHERE f.deleted_at IS NULL"
    )->fetchAll();
    foreach ($rows as $r) {
        $norm = ElixiaVtsService::normalizeId((string)($r['unique_freezer_id'] ?? ''));
        if ($norm !== '') {
            $localByNorm[$norm] = $r;
        }
    }
} catch (Throwable $e) {
    $localByNorm = [];
}

$activeCount = 0;
$inactiveCount = 0;
foreach ($devices as $d) {
    if ($isActiveDevice($d)) {
        $activeCount++;
    } else {
        $inactiveCount++;
    }
}

// Resolve detail device (before search filter, from full API list)
$selected = null;
if ($viewId !== '' && $apiOk) {
    foreach ($devices as $d) {
        $vid = (string)($d['vehicleid'] ?? '');
        $vehNorm = ElixiaVtsService::normalizeId((string)($d['vehicleno'] ?? ''));
        if ($vid === $viewId || $vehNorm === ElixiaVtsService::normalizeId($viewId)) {
            $selected = $d;
            break;
        }
    }
    if (!$selected) {
        $match = ElixiaVtsService::findByUniqueFreezerId($viewId, false);
        if (!empty($match['found']) && is_array($match['device'] ?? null)) {
            $selected = $match['device'];
        }
    }
}

if ($search !== '') {
    $qNorm = ElixiaVtsService::normalizeId($search);
    $qLower = strtolower($search);
    $devices = array_values(array_filter($devices, static function (array $d) use ($qNorm, $qLower): bool {
        $blob = strtolower(implode(' ', [
            (string)($d['vehicleno'] ?? ''),
            (string)($d['unitno'] ?? ''),
            (string)($d['status'] ?? ''),
            (string)($d['location'] ?? ''),
            (string)($d['drivername'] ?? ''),
            (string)($d['groupname'] ?? ''),
            (string)($d['kind'] ?? ''),
        ]));
        if ($qLower !== '' && str_contains($blob, $qLower)) {
            return true;
        }
        $vehNorm = ElixiaVtsService::normalizeId((string)($d['vehicleno'] ?? ''));
        return $qNorm !== '' && str_contains($vehNorm, $qNorm);
    }));
}

if ($statusFilter === 'active') {
    $devices = array_values(array_filter($devices, $isActiveDevice));
} elseif ($statusFilter === 'inactive') {
    $devices = array_values(array_filter($devices, static function (array $d) use ($isActiveDevice): bool {
        return !$isActiveDevice($d);
    }));
}

$listQuery = static function (array $extra = []) use ($search, $statusFilter): string {
    $params = [
        'q' => $search !== '' ? $search : null,
        'status' => ($statusFilter !== 'all' ? $statusFilter : null),
    ];
    foreach ($extra as $k => $v) {
        $params[$k] = $v;
    }
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $clean[$k] = $v;
    }
    return $clean ? ('?' . http_build_query($clean)) : '';
};

require_once __DIR__ . '/includes/header.php';

if ($selected):
    $veh = (string)($selected['vehicleno'] ?? '');
    $norm = ElixiaVtsService::normalizeId($veh);
    $local = $localByNorm[$norm] ?? null;
    $active = $isActiveDevice($selected);
    $lat = $selected['lat'] ?? null;
    $lng = $selected['lng'] ?? null;
    $hasCoords = $lat !== null && $lng !== null;
    $mapsUrl = $hasCoords
        ? ('https://maps.google.com/?q=' . rawurlencode($lat . ',' . $lng))
        : '';
    $short = trim((string)($selected['shorturl'] ?? ''));
    $backHref = 'live_freezer_tracking.php' . $listQuery();
?>
<div class="page-toolbar" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;justify-content:space-between;margin-bottom:1rem;">
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
        <a href="<?= htmlspecialchars($backHref) ?>" class="btn btn-sm btn-outline">← Back to list</a>
        <?php if ($active): ?>
            <span class="badge badge-success">Active</span>
        <?php else: ?>
            <span class="badge badge-warning">Inactive</span>
        <?php endif; ?>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
        <?php if ($hasCoords): ?>
            <a href="<?= htmlspecialchars($mapsUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary">Open Maps</a>
            <button type="button" class="btn btn-sm btn-outline" data-copy="<?= htmlspecialchars($mapsUrl) ?>">Copy maps URL</button>
        <?php endif; ?>
        <?php if ($local): ?>
            <a href="profile_view.php?id=<?= urlencode((string)$local['unique_freezer_id']) ?>" class="btn btn-sm btn-outline">Store profile</a>
        <?php endif; ?>
        <?php if ($short !== ''): ?>
            <a href="<?= htmlspecialchars($short) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">Elixia link</a>
        <?php endif; ?>
    </div>
</div>

<?php
    $detailRows = [
        ['Active', $active ? 'Active' : 'Inactive'],
        ['Freezer / Vehicle no.', $dash($veh)],
        ['Vehicle ID', $dash($selected['vehicleid'] ?? null)],
        ['Unit no.', $dash($selected['unitno'] ?? null)],
        ['Kind', $dash($selected['kind'] ?? null)],
        ['Group', $dash($selected['groupname'] ?? null)],
        ['Type', $dash($selected['type'] ?? null)],
        ['Device ID', $dash($selected['deviceId'] ?? null)],
        ['API status', $dash($selected['status'] ?? null)],
        ['Status (new)', $dash($selected['status_new'] ?? null)],
        ['Motion', $dash($selected['vehicleMotionStatus'] ?? null)],
        ['Tracking state', $dash($selected['deviceTrackingState'] ?? null)],
        ['Ignition', $ignitionLabel($selected['ignition'] ?? null)],
        ['Speed', $dash($selected['vehiclespeed'] ?? null)],
        ['Idle since', $idleLabel($selected['idleSince'] ?? null)],
        ['Digital status', $dash($selected['digitalstatus'] ?? null)],
        ['Driver', $dash($selected['drivername'] ?? null)],
        ['Temperature 1', $dash($selected['temperature1'] ?? null)],
        ['Temperature 2', $dash($selected['temperature2'] ?? null)],
        ['Temperature 3', $dash($selected['temperature3'] ?? null)],
        ['Temperature 4', $dash($selected['temperature4'] ?? null)],
        ['Door sensor 1', $dash($selected['doorSensor1'] ?? null)],
        ['Door sensor 2', $dash($selected['doorSensor2'] ?? null)],
        ['Latitude', $hasCoords ? number_format((float)$lat, 6) : '—'],
        ['Longitude', $hasCoords ? number_format((float)$lng, 6) : '—'],
        ['Maps URL', $hasCoords ? $mapsUrl : '—'],
        ['Location', $dash($selected['location'] ?? null)],
        ['Direction', $dash($selected['direction'] ?? null)],
        ['Odometer', $dash($selected['odometer'] ?? null)],
        ['Distance', $dash($selected['distance'] ?? null)],
        ['Last updated', $dash($selected['lastupdated'] ?? null)],
        ['Timestamp', $dash($selected['timestamp'] ?? null)],
        ['UTC timestamp', $dash($selected['utctimestamp'] ?? null)],
        ['System time', $dash($selected['systemtime'] ?? null)],
        ['UTC system time', $dash($selected['utcsystemtime'] ?? null)],
        ['Short URL', $dash($short !== '' ? $short : null)],
        ['App store', $local ? (string)($local['store_name'] ?? '—') : 'Not in app'],
        ['Outlet ID', $local ? $dash($local['outlet_id'] ?? null) : '—'],
        ['App city', $local ? $dash($local['city'] ?? null) : '—'],
        ['App zone', $local ? $dash($local['zone'] ?? null) : '—'],
    ];
    // Append any remaining raw keys not already covered
    $shownKeys = [
        'vehicleno','vehicleid','unitno','kind','groupname','type','deviceId','status','status_new',
        'vehicleMotionStatus','deviceTrackingState','ignition','vehiclespeed','idleSince','digitalstatus',
        'drivername','temperature1','temperature2','temperature3','temperature4','doorSensor1','doorSensor2',
        'lat','lng','location','direction','odometer','distance','lastupdated','timestamp','utctimestamp',
        'systemtime','utcsystemtime','shorturl',
    ];
    foreach ($selected as $key => $val) {
        if (in_array((string)$key, $shownKeys, true)) {
            continue;
        }
        if (is_array($val)) {
            $val = json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        $detailRows[] = [(string)$key, $dash($val)];
    }
?>
<div class="card sp-history-card">
    <div class="card-header sp-history-header">
        <div>
            <div class="card-title"><span>API profile details</span></div>
            <p class="excel-sheet-sub" style="margin:0.35rem 0 0;">
                Same Field / Value table as Raw API fields —
                <code><?= htmlspecialchars($veh !== '' ? $veh : '—') ?></code>
            </p>
        </div>
    </div>
    <div class="card-body">
        <div class="excel-legend">
            <span>Source: Elixia VTS API</span>
            <?php if ($active): ?>
                <span class="badge badge-success">Active</span>
            <?php else: ?>
                <span class="badge badge-warning">Inactive</span>
            <?php endif; ?>
        </div>
        <div class="excel-wrap" style="max-height:min(70vh,680px);margin-bottom:<?= $hasCoords ? '1rem' : '0' ?>;">
            <table class="excel-sheet live-detail-excel">
                <thead>
                    <tr>
                        <th style="width:240px;">Field</th>
                        <th>Value from Elixia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailRows as [$label, $value]):
                        $rowCls = '';
                        if ($label === 'Active') {
                            $rowCls = $active ? 'excel-row-install' : 'excel-row-kyc-rejected';
                        }
                    ?>
                        <tr class="<?= htmlspecialchars($rowCls) ?>">
                            <td class="excel-mono"><?= htmlspecialchars($label) ?></td>
                            <td title="<?= htmlspecialchars($value) ?>" style="white-space:normal;max-width:none;">
                                <?php if ($label === 'Active'): ?>
                                    <?php if ($active): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Inactive</span>
                                    <?php endif; ?>
                                <?php elseif ($label === 'Maps URL' && $hasCoords): ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:0.4rem;align-items:center;">
                                        <a href="<?= htmlspecialchars($mapsUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($mapsUrl) ?></a>
                                        <button type="button" class="btn btn-sm btn-outline" data-copy="<?= htmlspecialchars($mapsUrl) ?>">Copy</button>
                                    </div>
                                <?php elseif ($label === 'App store' && $local): ?>
                                    <a href="profile_view.php?id=<?= urlencode((string)$local['unique_freezer_id']) ?>"><?= htmlspecialchars($value) ?></a>
                                <?php elseif ($label === 'Short URL' && $short !== ''): ?>
                                    <a href="<?= htmlspecialchars($short) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($value) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($value) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($hasCoords): ?>
            <div class="sp-map-wrap">
                <div id="live-detail-map" style="width:100%;height:100%;"></div>
            </div>
        <?php endif; ?>
    </div>
</div>
<style>
.live-detail-excel th,
.live-detail-excel td {
    max-width: none;
    white-space: normal;
}
.live-detail-excel td:first-child {
    width: 240px;
    white-space: nowrap;
    font-weight: 700;
}
</style>

<?php if ($hasCoords): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('live-detail-map');
    if (!el || typeof L === 'undefined') return;
    var lat = <?= json_encode((float)$lat) ?>;
    var lng = <?= json_encode((float)$lng) ?>;
    var map = L.map(el).setView([lat, lng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    L.marker([lat, lng]).addTo(map)
        .bindPopup(<?= json_encode($veh !== '' ? $veh : 'Freezer') ?>).openPopup();
});
</script>
<?php endif; ?>

<?php else: ?>
<div class="card sp-history-card">
    <div class="card-header sp-history-header">
        <div>
            <div class="card-title"><span>Live freezer tracking</span></div>
            <p class="excel-sheet-sub" style="margin:0.35rem 0 0;" id="live-list-sub">
                Elixia VTS list — click a freezer to open full API details
                <span id="live-list-meta">· loading…</span>
            </p>
        </div>
        <div class="sp-history-actions" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
            <form method="get" action="live_freezer_tracking.php" id="live-list-search-form" style="display:flex;gap:0.5rem;align-items:center;margin:0;">
                <?php if ($statusFilter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <?php endif; ?>
                <input type="search" name="q" class="form-control" style="min-width:220px;height:34px;"
                       placeholder="Search ID, unit, status, location…"
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-outline btn-sm">Search</button>
                <?php if ($search !== '' || $statusFilter !== 'all'): ?>
                    <a href="live_freezer_tracking.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>
            <button type="button" class="btn btn-outline btn-sm" id="live-list-refresh">Refresh</button>
        </div>
    </div>
    <div class="card-body" id="live-list-panel"
         data-q="<?= htmlspecialchars($search) ?>"
         data-status="<?= htmlspecialchars($statusFilter) ?>"
         data-enabled="<?= AppConfig::ELIXIA_VTS_ENABLED ? '1' : '0' ?>"
         data-auto-refresh="<?= $forceRefresh ? '1' : '0' ?>">
        <?php if ($apiError && !$lazyList): ?>
            <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($apiError) ?></div>
        <?php endif; ?>

        <div class="excel-legend" style="gap:0.75rem;" id="live-list-legend">
            <a href="live_freezer_tracking.php<?= $listQuery(['status' => null]) ?>" class="badge <?= $statusFilter === 'all' ? 'badge-primary' : 'badge-secondary' ?>" data-status-link="all">All</a>
            <a href="live_freezer_tracking.php<?= $listQuery(['status' => 'active']) ?>" class="badge <?= $statusFilter === 'active' ? 'badge-success' : 'badge-secondary' ?>" data-status-link="active">Active</a>
            <a href="live_freezer_tracking.php<?= $listQuery(['status' => 'inactive']) ?>" class="badge <?= $statusFilter === 'inactive' ? 'badge-warning' : 'badge-secondary' ?>" data-status-link="inactive">Inactive</a>
            <span>Click Freezer no. / row to open details</span>
        </div>

        <div id="live-list-alert"></div>
        <div class="excel-wrap live-track-excel-wrap">
            <table class="excel-sheet live-track-excel">
                <thead>
                    <tr>
                        <th class="excel-col-index">#</th>
                        <th>Freezer / Vehicle no.</th>
                        <th>Active</th>
                        <th>API status</th>
                        <th>Unit no.</th>
                        <th>Ignition</th>
                        <th>Temp 1</th>
                        <th>Temp 2</th>
                        <th>Latitude</th>
                        <th>Longitude</th>
                        <th>Maps URL</th>
                        <th>Location</th>
                        <th>Last updated</th>
                        <th>Timestamp</th>
                        <th>App store</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="live-list-tbody">
                    <tr>
                        <td colspan="16">
                            <div class="sp-lazy-loading sp-lazy-table-loading" role="status">
                                <div class="sp-lazy-spinner" aria-hidden="true"></div>
                                <p>Loading Elixia list…</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.live-track-excel-wrap { max-height: min(72vh, 720px); }
.live-track-excel th, .live-track-excel td { max-width: none; }
.live-track-excel td:nth-child(2), .live-track-excel th:nth-child(2) { min-width: 260px; }
.live-track-excel td:nth-child(11), .live-track-excel th:nth-child(11) { min-width: 180px; max-width: 280px; white-space: normal; }
.live-track-row:hover td { background: #e8f2ff !important; }
.excel-legend .badge { text-decoration: none; }
</style>
<script>
(function () {
    var panel = document.getElementById('live-list-panel');
    var tbody = document.getElementById('live-list-tbody');
    var meta = document.getElementById('live-list-meta');
    var alertBox = document.getElementById('live-list-alert');
    var refreshBtn = document.getElementById('live-list-refresh');
    if (!panel || !tbody) return;

    var localMap = <?= json_encode($localByNorm, JSON_UNESCAPED_UNICODE) ?> || {};
    var loading = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function dash(v) {
        if (v === null || v === undefined) return '—';
        var s = String(v).trim();
        return s !== '' ? s : '—';
    }
    function ignLabel(v) {
        if (v === null || v === undefined || v === '') return '—';
        if (String(v) === '1' || v === 1 || v === true) return 'ON';
        if (String(v) === '0' || v === 0 || v === false) return 'OFF';
        return String(v);
    }
    function normalizeId(id) {
        return String(id || '').toUpperCase().replace(/\s+/g, '');
    }
    function listQuery(extra) {
        var params = new URLSearchParams();
        var q = panel.getAttribute('data-q') || '';
        var status = panel.getAttribute('data-status') || 'all';
        if (q) params.set('q', q);
        if (status && status !== 'all') params.set('status', status);
        if (extra) {
            Object.keys(extra).forEach(function (k) {
                if (extra[k] == null || extra[k] === '') params.delete(k);
                else params.set(k, extra[k]);
            });
        }
        var s = params.toString();
        return s ? ('?' + s) : '';
    }
    function showLoading() {
        tbody.innerHTML = '<tr><td colspan="16"><div class="sp-lazy-loading sp-lazy-table-loading" role="status">'
            + '<div class="sp-lazy-spinner" aria-hidden="true"></div><p>Loading Elixia list…</p></div></td></tr>';
        if (meta) meta.textContent = '· loading…';
        if (refreshBtn) refreshBtn.disabled = true;
    }
    function bindRows() {
        tbody.querySelectorAll('.live-track-row[data-href]').forEach(function (row) {
            row.addEventListener('click', function () {
                var href = row.getAttribute('data-href');
                if (href) window.location.href = href;
            });
        });
    }
    function render(data, fresh) {
        if (refreshBtn) refreshBtn.disabled = false;
        if (!data || !data.ok) {
            tbody.innerHTML = '<tr><td colspan="16"><p class="excel-cell-empty" style="margin:0.75rem;">'
                + esc((data && data.error) || 'Could not load Elixia data.') + '</p></td></tr>';
            if (meta) meta.textContent = '· error';
            if (alertBox) {
                alertBox.innerHTML = '<div class="alert alert-danger" style="margin-bottom:1rem;">'
                    + esc((data && data.error) || 'Could not load Elixia data.') + '</div>';
            }
            return;
        }
        if (alertBox) alertBox.innerHTML = '';
        var devices = data.devices || [];
        var activeCount = data.active_count || 0;
        var inactiveCount = data.inactive_count || 0;
        var total = data.total || devices.length;
        var shown = data.shown != null ? data.shown : devices.length;

        var allLink = document.querySelector('[data-status-link="all"]');
        var actLink = document.querySelector('[data-status-link="active"]');
        var inaLink = document.querySelector('[data-status-link="inactive"]');
        if (allLink) allLink.textContent = 'All';
        if (actLink) actLink.textContent = 'Active (' + activeCount + ')';
        if (inaLink) inaLink.textContent = 'Inactive (' + inactiveCount + ')';

        if (meta) {
            meta.innerHTML = '· ' + shown + ' shown of ' + total
                + ' · <span class="badge badge-success">Active ' + activeCount + '</span>'
                + ' · <span class="badge badge-warning">Inactive ' + inactiveCount + '</span>'
                + (fresh ? ' · live pull' : (data.from_cache ? ' · cached' : ' · live pull'));
        }

        if (!devices.length) {
            tbody.innerHTML = '<tr><td colspan="16"><p class="excel-cell-empty" style="margin:0.75rem;">No devices matched.</p></td></tr>';
            return;
        }

        var html = '';
        devices.forEach(function (d, i) {
            var veh = String(d.vehicleno || '');
            var norm = normalizeId(veh);
            var local = localMap[norm] || null;
            var lat = d.lat;
            var lng = d.lng;
            var hasCoords = lat !== null && lat !== undefined && lng !== null && lng !== undefined && lat !== '' && lng !== '';
            var maps = hasCoords ? ('https://maps.google.com/?q=' + encodeURIComponent(String(lat) + ',' + String(lng))) : '';
            var active = !!d.active;
            var vid = String(d.vehicleid || norm);
            var detailHref = 'live_freezer_tracking.php' + listQuery({ vid: vid });
            var rowCls = active ? 'excel-row-install' : (local ? 'excel-row-current' : '');
            html += '<tr class="' + esc(rowCls) + ' live-track-row" data-href="' + esc(detailHref) + '" style="cursor:pointer;">';
            html += '<td class="excel-col-index">' + (i + 1) + '</td>';
            html += '<td class="excel-mono" title="' + esc(veh) + '"><a href="' + esc(detailHref) + '" onclick="event.stopPropagation();">'
                + esc(veh || '—') + '</a></td>';
            html += '<td>' + (active
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-warning">Inactive</span>') + '</td>';
            html += '<td title="' + esc(dash(d.status)) + '">' + esc(dash(d.status)) + '</td>';
            html += '<td class="excel-mono">' + esc(dash(d.unitno)) + '</td>';
            html += '<td>' + esc(ignLabel(d.ignition)) + '</td>';
            html += '<td>' + esc(dash(d.temperature1)) + '</td>';
            html += '<td>' + esc(dash(d.temperature2)) + '</td>';
            html += '<td class="excel-mono">' + (hasCoords ? esc(Number(lat).toFixed(6)) : '—') + '</td>';
            html += '<td class="excel-mono">' + (hasCoords ? esc(Number(lng).toFixed(6)) : '—') + '</td>';
            html += '<td style="white-space:normal;min-width:220px;" onclick="event.stopPropagation();">';
            if (maps) {
                html += '<a href="' + esc(maps) + '" target="_blank" rel="noopener" title="' + esc(maps) + '">Open</a> · '
                    + '<button type="button" class="btn btn-sm btn-outline" style="padding:0.1rem 0.35rem;font-size:0.65rem;min-height:0;" data-copy="'
                    + esc(maps) + '">Copy</button>';
            } else {
                html += '—';
            }
            html += '</td>';
            html += '<td title="' + esc(dash(d.location)) + '">' + esc(dash(d.location)) + '</td>';
            html += '<td>' + esc(dash(d.lastupdated)) + '</td>';
            html += '<td>' + esc(dash(d.timestamp)) + '</td>';
            html += '<td>';
            if (local) {
                html += '<a href="profile_view.php?id=' + encodeURIComponent(local.unique_freezer_id) + '" onclick="event.stopPropagation();">'
                    + esc(local.store_name || 'Store') + '</a>';
            } else {
                html += 'Not in app';
            }
            html += '</td>';
            html += '<td><a href="' + esc(detailHref) + '" onclick="event.stopPropagation();">Open</a></td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
        bindRows();
    }

    function load(forceRefresh) {
        if (loading) return;
        if (panel.getAttribute('data-enabled') !== '1') {
            render({ ok: false, error: 'Elixia VTS is disabled in config.' });
            return;
        }
        loading = true;
        showLoading();
        var q = panel.getAttribute('data-q') || '';
        var status = panel.getAttribute('data-status') || 'all';
        var url = 'api/elixia_list.php?status=' + encodeURIComponent(status)
            + (q ? '&q=' + encodeURIComponent(q) : '')
            + (forceRefresh ? '&refresh=1' : '');
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                loading = false;
                render(data, !!forceRefresh);
                if (forceRefresh && window.history && window.history.replaceState) {
                    try {
                        var u = new URL(window.location.href);
                        u.searchParams.delete('refresh');
                        window.history.replaceState({}, '', u.pathname + u.search + u.hash);
                    } catch (e) {}
                }
            })
            .catch(function () {
                loading = false;
                render({ ok: false, error: 'Network error loading Elixia list.' });
            });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () { load(true); });
    }

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                io.disconnect();
                load(panel.getAttribute('data-auto-refresh') === '1');
            });
        }, { rootMargin: '160px 0px' });
        io.observe(panel);
    } else {
        load(panel.getAttribute('data-auto-refresh') === '1');
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
