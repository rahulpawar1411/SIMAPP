<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Services\KycScanGate;

AuthService::requireLogin();

$areaScope = AuthService::areaScope();
$filterZone = strtoupper(trim((string)($_GET['zone'] ?? '')));
$filterCity = trim((string)($_GET['city'] ?? ''));
if ($filterZone === 'ALL') {
    $filterZone = '';
}
if (strcasecmp($filterCity, 'All') === 0) {
    $filterCity = '';
}
if ($areaScope['zone'] !== '') {
    $filterZone = $areaScope['zone'];
}
if ($areaScope['city'] !== '') {
    $filterCity = $areaScope['city'];
}
$mapFilters = ['zone' => $filterZone, 'city' => $filterCity];
$zoneOptions = Freezer::dashboardZones();
$cityOptions = Freezer::dashboardCities($filterZone !== '' ? $filterZone : null);
$lockMapArea = AuthService::hasAreaScope();

$points = [];
try {
    $points = FreezerLifecycle::kycDueForMap(200, $mapFilters);
} catch (Throwable $e) {
    $points = [];
}

$withGps = array_values(array_filter($points, static fn($p) => !empty($p['has_gps'])));
$withoutGps = count($points) - count($withGps);

$pageTitle = 'KYC Map';
$bodyClass = 'field-app-page field-shell-page';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mapp mapp-dash kyc-map-page">
    <header class="mapp-hero" style="margin-bottom:0.35rem;">
        <div>
            <p class="mapp-greet">Locations</p>
            <h1 class="mapp-name" style="font-size:1.25rem;">KYC due nearby</h1>
            <p class="mapp-role"><?= number_format(count($withGps)) ?> on map · <?= number_format($withoutGps) ?> missing GPS</p>
        </div>
        <button type="button" class="btn btn-sm btn-primary" id="kyc-map-locate">Near me</button>
    </header>

    <form method="GET" action="kyc_map.php" class="kyc-map-filters">
        <?php if ($lockMapArea): ?>
            <input type="hidden" name="zone" value="<?= htmlspecialchars($filterZone) ?>">
            <input type="hidden" name="city" value="<?= htmlspecialchars($filterCity) ?>">
            <span class="adash-select adash-select-sm" style="display:inline-flex;align-items:center;opacity:0.9;">
                <?= htmlspecialchars(trim(($filterZone !== '' ? $filterZone : 'All zones') . ($filterCity !== '' ? ' · ' . $filterCity : ' · All cities'))) ?>
            </span>
        <?php else: ?>
            <select name="zone" class="adash-select adash-select-sm" onchange="this.form.submit()" aria-label="Zone">
                <option value="">All zones</option>
                <?php foreach ($zoneOptions as $z): ?>
                    <option value="<?= htmlspecialchars($z) ?>" <?= $filterZone === $z ? 'selected' : '' ?>><?= htmlspecialchars($z) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="city" class="adash-select adash-select-sm" onchange="this.form.submit()" aria-label="City">
                <option value="">All cities</option>
                <?php foreach ($cityOptions as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $filterCity === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </form>

    <div class="kyc-map-toggle" role="tablist">
        <button type="button" class="is-active" data-kyc-view="map" role="tab" aria-selected="true">Map</button>
        <button type="button" data-kyc-view="list" role="tab" aria-selected="false">List</button>
    </div>

    <div id="kyc-map-panel" class="kyc-map-panel">
        <div id="kyc-due-map" class="kyc-due-map" role="img" aria-label="Map of KYC due stores"></div>
        <p id="kyc-map-status" class="kyc-map-status">Locating you for nearby order…</p>
    </div>

    <div id="kyc-list-panel" class="kyc-list-panel" hidden>
        <?php if (empty($points)): ?>
            <div class="mapp-empty-state">
                <strong>No KYC due</strong>
                <p>Nothing pending in this filter.</p>
                <a href="profiles.php" class="btn btn-primary mapp-btn">Open Assets</a>
            </div>
        <?php else: ?>
            <ul class="mapp-due-list" id="kyc-map-list">
                <?php foreach ($points as $row): ?>
                    <?php
                    $fid = (string)($row['unique_freezer_id'] ?? '');
                    $scan = KycScanGate::scannerUrl($row);
                    ?>
                    <li data-fid="<?= htmlspecialchars($fid) ?>"
                        data-lat="<?= $row['has_gps'] ? htmlspecialchars((string)$row['latitude']) : '' ?>"
                        data-lng="<?= $row['has_gps'] ? htmlspecialchars((string)$row['longitude']) : '' ?>">
                        <a href="profile_view.php?id=<?= urlencode($fid) ?>">
                            <strong><?= htmlspecialchars((string)($row['store_name'] ?: 'Store')) ?></strong>
                            <span class="mapp-due-id"><?= htmlspecialchars($fid) ?></span>
                            <span class="mapp-due-meta">
                                <?= htmlspecialchars((string)($row['city'] ?: '—')) ?>
                                · Due <?= htmlspecialchars((string)($row['next_kyc_due'] ?: '—')) ?>
                                <?php if (empty($row['has_gps'])): ?> · No GPS<?php endif; ?>
                                <span class="kyc-dist" hidden></span>
                            </span>
                        </a>
                        <a class="mapp-due-go" href="<?= htmlspecialchars($scan) ?>">KYC</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var points = <?= json_encode(array_values($withGps), JSON_UNESCAPED_UNICODE) ?>;
    var mapEl = document.getElementById('kyc-due-map');
    var statusEl = document.getElementById('kyc-map-status');
    var mapPanel = document.getElementById('kyc-map-panel');
    var listPanel = document.getElementById('kyc-list-panel');
    var map = null;
    var userMarker = null;
    var markers = [];

    function haversineKm(lat1, lon1, lat2, lon2) {
        var R = 6371;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLon = (lon2 - lon1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function setStatus(msg) {
        if (statusEl) statusEl.textContent = msg || '';
    }

    function popupHtml(p) {
        var id = encodeURIComponent(p.unique_freezer_id || '');
        return '<div class="kyc-map-popup">' +
            '<strong>' + (p.store_name || 'Store') + '</strong><br>' +
            '<code>' + (p.unique_freezer_id || '') + '</code><br>' +
            (p.city || '') + ' · Due ' + (p.next_kyc_due || '—') + '<br>' +
            '<a href="profile_view.php?id=' + id + '">Open profile</a>' +
            '</div>';
    }

    function initMap() {
        if (!window.L || !mapEl) {
            setStatus('Map library not loaded.');
            return;
        }
        if (map) {
            setTimeout(function () { map.invalidateSize(); }, 80);
            return;
        }
        var center = [20.5937, 78.9629];
        var zoom = 5;
        if (points.length) {
            center = [points[0].latitude, points[0].longitude];
            zoom = 11;
        }
        map = L.map(mapEl, { zoomControl: true }).setView(center, zoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        var bounds = [];
        points.forEach(function (p) {
            var m = L.circleMarker([p.latitude, p.longitude], {
                radius: 9,
                color: '#fff',
                weight: 2,
                fillColor: '#d97706',
                fillOpacity: 0.95
            }).addTo(map);
            m.bindPopup(popupHtml(p));
            markers.push({ marker: m, point: p });
            bounds.push([p.latitude, p.longitude]);
        });
        if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [28, 28], maxZoom: 13 });
        } else if (bounds.length === 1) {
            map.setView(bounds[0], 14);
        }
        setStatus(points.length ? (points.length + ' KYC-due stores with GPS') : 'No GPS pins for KYC due in this filter.');
        setTimeout(function () { map.invalidateSize(); }, 120);
    }

    function sortListByDistance(lat, lng) {
        var list = document.getElementById('kyc-map-list');
        if (!list) return;
        var items = Array.prototype.slice.call(list.querySelectorAll('li'));
        items.forEach(function (li) {
            var plat = parseFloat(li.getAttribute('data-lat') || '');
            var plng = parseFloat(li.getAttribute('data-lng') || '');
            var distEl = li.querySelector('.kyc-dist');
            if (!isFinite(plat) || !isFinite(plng)) {
                li.dataset.dist = '999999';
                if (distEl) { distEl.hidden = true; }
                return;
            }
            var km = haversineKm(lat, lng, plat, plng);
            li.dataset.dist = String(km);
            if (distEl) {
                distEl.hidden = false;
                distEl.textContent = ' · ' + (km < 10 ? km.toFixed(1) : Math.round(km)) + ' km';
            }
        });
        items.sort(function (a, b) {
            return parseFloat(a.dataset.dist || '999999') - parseFloat(b.dataset.dist || '999999');
        });
        items.forEach(function (li) { list.appendChild(li); });
    }

    function showUserLocation(lat, lng) {
        if (!map || !window.L) return;
        if (userMarker) {
            userMarker.setLatLng([lat, lng]);
        } else {
            userMarker = L.circleMarker([lat, lng], {
                radius: 8,
                color: '#fff',
                weight: 2,
                fillColor: '#2563eb',
                fillOpacity: 1
            }).addTo(map).bindPopup('You are here');
        }
        if (points.length) {
            var nearest = null;
            var best = Infinity;
            points.forEach(function (p) {
                var d = haversineKm(lat, lng, p.latitude, p.longitude);
                if (d < best) { best = d; nearest = p; }
            });
            if (nearest) {
                map.setView([lat, lng], Math.min(13, map.getZoom() || 12));
                setStatus('Nearest KYC due: ' + (nearest.store_name || nearest.unique_freezer_id) + ' (~' + (best < 10 ? best.toFixed(1) : Math.round(best)) + ' km)');
            }
        } else {
            map.setView([lat, lng], 12);
            setStatus('Location found. No GPS pins in filter.');
        }
        sortListByDistance(lat, lng);
    }

    function locate() {
        if (!navigator.geolocation) {
            setStatus('Location not supported on this device.');
            initMap();
            return;
        }
        setStatus('Getting your location…');
        navigator.geolocation.getCurrentPosition(function (pos) {
            initMap();
            showUserLocation(pos.coords.latitude, pos.coords.longitude);
        }, function () {
            initMap();
            setStatus(points.length ? (points.length + ' KYC-due stores with GPS (location denied)') : 'Location denied / unavailable.');
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
    }

    document.querySelectorAll('.kyc-map-toggle button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.kyc-map-toggle button').forEach(function (b) {
                b.classList.remove('is-active');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('is-active');
            btn.setAttribute('aria-selected', 'true');
            var view = btn.getAttribute('data-kyc-view');
            if (view === 'list') {
                mapPanel.hidden = true;
                listPanel.hidden = false;
            } else {
                listPanel.hidden = true;
                mapPanel.hidden = false;
                initMap();
            }
        });
    });

    var locateBtn = document.getElementById('kyc-map-locate');
    if (locateBtn) locateBtn.addEventListener('click', locate);

    // Start on map
    locate();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
