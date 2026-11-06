<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\ImportExportService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\MasterData;
use App\Models\FreezerLifecycle;
use App\Models\AssetTag;

AuthService::requireLogin();
AssetTag::ensureTable();
$currentUser = AuthService::currentUser();

/** @return list<string> */
$asList = static function ($value): array {
    if ($value === null || $value === '' || $value === []) {
        return [];
    }
    if (!is_array($value)) {
        $value = [$value];
    }
    $out = [];
    foreach ($value as $item) {
        $s = trim((string)$item);
        if ($s !== '') {
            $out[] = $s;
        }
    }
    return array_values(array_unique($out));
};

// Search & Filter parameters (checkbox multi-select)
$search = trim((string)($_GET['q'] ?? ''));
$typeList = $asList($_GET['type'] ?? []);
$brandList = $asList($_GET['brand'] ?? []);
$statusList = $asList($_GET['status'] ?? []);
$cityList = $asList($_GET['city'] ?? []);
$missingList = $asList($_GET['missing'] ?? []);
$kycList = array_values(array_intersect($asList($_GET['kyc'] ?? []), ['pending', 'done']));

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$filters = [
    'q' => $search,
    'type' => $typeList,
    'brand' => $brandList,
    'status' => $statusList,
    'city' => $cityList,
    'missing' => $missingList,
    'kyc' => $kycList,
];

$kycGapMonths = FreezerLifecycle::KYC_GAP_MONTHS;
$activeFilterCount = count($typeList) + count($brandList) + count($statusList)
    + count($cityList) + count($missingList) + count($kycList);
$hasActiveFilters = $search !== '' || $activeFilterCount > 0;

/* Single-value helpers for labels that still expect one KYC mode */
$kyc = count($kycList) === 1 ? $kycList[0] : '';
$city = count($cityList) === 1 ? $cityList[0] : '';
$status = count($statusList) === 1 ? $statusList[0] : '';

// Full data export — admin only (same as Reports)
if (!empty($_GET['export'])) {
    AuthService::requireRole(AppConfig::ROLE_SUPER_ADMIN);
    $exportType = strtolower((string)$_GET['export']);
    if ($exportType === 'xlsx') {
        ImportExportService::streamProfilesExportXlsx($filters);
        exit;
    }
    if ($exportType === 'csv') {
        ImportExportService::streamProfilesExportCsv($filters);
        exit;
    }
}

$isAdmin = AuthService::hasRole(AppConfig::ROLE_SUPER_ADMIN);

$totalCount = Freezer::countFiltered($filters);
$totalPages = max(1, (int)ceil($totalCount / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}
$freezers = Freezer::getFiltered($filters, $limit, $offset);
$rangeFrom = $totalCount === 0 ? 0 : ($offset + 1);
$rangeTo = min($offset + $limit, $totalCount);

/** Build page URL keeping current filters */
$profilesPageUrl = static function (int $p) use ($search, $typeList, $brandList, $statusList, $cityList, $missingList, $kycList): string {
    $params = array_filter([
        'q' => $search !== '' ? $search : null,
        'type' => $typeList ?: null,
        'brand' => $brandList ?: null,
        'status' => $statusList ?: null,
        'city' => $cityList ?: null,
        'missing' => $missingList ?: null,
        'kyc' => $kycList ?: null,
        'page' => $p > 1 ? $p : null,
    ], static fn($v) => $v !== null && $v !== '' && $v !== []);
    $qs = http_build_query($params);
    return 'profiles.php' . ($qs !== '' ? '?' . $qs : '');
};

$freezerTypes = MasterData::getFreezerTypes();
$brands = MasterData::getBrands();

$cityOptions = [];
try {
    $pdoCities = \App\Config\Database::getConnection();
    Freezer::ensureSimColumns();
    $cityOptions = $pdoCities->query(
        "SELECT DISTINCT s.city FROM `stores` s
         INNER JOIN `freezers` f ON f.store_id = s.id
         WHERE f.deleted_at IS NULL AND s.city IS NOT NULL AND s.city <> ''
         ORDER BY s.city ASC
         LIMIT 80"
    )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $cityOptions = [];
}

$statusOptions = ['Active', 'Maintenance', 'Under Inspection', 'Decommissioned'];
$missingOptions = [
    'any' => 'Any gap (GPS / photos' . (AppConfig::IOT_ENABLED ? ' / IoT' : '') . ')',
    'gps' => 'Missing GPS',
    'gps_approx' => 'City approx GPS (Excel)',
    'gps_exact' => 'Exact GPS only',
    'photos' => 'Missing photos',
];
if (AppConfig::IOT_ENABLED) {
    $missingOptions['iot'] = 'Missing IoT';
}

$pageTitle = 'Freezer & Store Profiles';
require_once __DIR__ . '/includes/header.php';
?>

<div class="cfg-toolbar">
    <div class="cfg-toolbar-left">
        <h1 class="cfg-toolbar-title">Freezer &amp; Store Profiles</h1>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <?php if ($isAdmin): ?>
        <a href="qr_print_bulk.php?<?= http_build_query(array_filter(['q' => $search, 'city' => $cityList, 'status' => $statusList], static fn($v) => $v !== '' && $v !== [])) ?>" class="btn btn-outline" target="_blank">
            Print all stickers (PDF)
        </a>
        <a href="profiles.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-secondary" title="Full store + freezer + KYC + IoT data">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <span>Export full CSV</span>
        </a>
        <a href="profiles.php?<?= http_build_query(array_merge($_GET, ['export' => 'xlsx'])) ?>" class="btn btn-outline" title="Full Excel export">
            Export Excel
        </a>
        <?php endif; ?>
        <a href="qr_scanner.php" class="btn btn-primary cfg-done-btn">
            Scan QR → Register
        </a>
    </div>
</div>

<!-- Search + Filter button (Flipkart-style drawer) -->
<div class="card profiles-filter-bar">
    <div class="card-body">
        <form method="GET" action="" class="profiles-search-row" id="profiles-search-form">
            <div class="profiles-search-field">
                <label class="form-label" for="profiles-q">Search</label>
                <input type="text" id="profiles-q" name="q" class="form-control"
                       placeholder="Tag code, Freezer ID, Store, Model…"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="profiles-search-actions">
                <button type="submit" class="btn btn-primary">Search</button>
                <button type="button" class="btn btn-outline profiles-filter-open" id="profiles-filter-open" aria-haspopup="dialog" aria-controls="profiles-filter-drawer">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                    </svg>
                    <span>Filter</span>
                    <?php if ($activeFilterCount > 0): ?>
                        <span class="profiles-filter-count"><?= (int)$activeFilterCount ?></span>
                    <?php endif; ?>
                </button>
                <?php if ($hasActiveFilters): ?>
                    <a href="profiles.php" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($activeFilterCount > 0): ?>
            <div class="profiles-active-chips" aria-label="Active filters">
                <?php foreach ($typeList as $t): ?>
                    <span class="profiles-chip">Type: <?= htmlspecialchars($t) ?></span>
                <?php endforeach; ?>
                <?php foreach ($brandList as $b): ?>
                    <span class="profiles-chip">Brand: <?= htmlspecialchars($b) ?></span>
                <?php endforeach; ?>
                <?php foreach ($statusList as $st): ?>
                    <span class="profiles-chip">Status: <?= htmlspecialchars($st) ?></span>
                <?php endforeach; ?>
                <?php foreach ($cityList as $c): ?>
                    <span class="profiles-chip">City: <?= htmlspecialchars($c) ?></span>
                <?php endforeach; ?>
                <?php foreach ($missingList as $m): ?>
                    <span class="profiles-chip"><?= htmlspecialchars($missingOptions[$m] ?? $m) ?></span>
                <?php endforeach; ?>
                <?php foreach ($kycList as $kv): ?>
                    <span class="profiles-chip">KYC: <?= $kv === 'pending' ? 'Pending' : 'Within ' . (int)$kycGapMonths . ' mo' ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Right filter drawer -->
<div class="filter-drawer-backdrop" id="profiles-filter-backdrop" hidden></div>
<aside class="filter-drawer" id="profiles-filter-drawer" role="dialog" aria-modal="true" aria-labelledby="profiles-filter-title" hidden>
    <form method="GET" action="" id="profiles-filter-form" class="filter-drawer-form">
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <header class="filter-drawer-head">
            <h2 id="profiles-filter-title">Filters</h2>
            <button type="button" class="filter-drawer-close" id="profiles-filter-close" aria-label="Close filters">&times;</button>
        </header>
        <div class="filter-drawer-body">
            <section class="filter-section">
                <h3 class="filter-section-title">Freezer type</h3>
                <div class="filter-check-list">
                    <?php foreach ($freezerTypes as $ft): ?>
                        <label class="filter-check">
                            <input type="checkbox" name="type[]" value="<?= htmlspecialchars($ft['code']) ?>"
                                <?= in_array($ft['code'], $typeList, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($ft['code']) ?> — <?= htmlspecialchars($ft['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="filter-section">
                <h3 class="filter-section-title">Brand / OEM</h3>
                <div class="filter-check-list">
                    <?php foreach ($brands as $b): ?>
                        <label class="filter-check">
                            <input type="checkbox" name="brand[]" value="<?= htmlspecialchars($b['code']) ?>"
                                <?= in_array($b['code'], $brandList, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($b['code']) ?> — <?= htmlspecialchars($b['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="filter-section">
                <h3 class="filter-section-title">Status</h3>
                <div class="filter-check-list">
                    <?php foreach ($statusOptions as $st): ?>
                        <label class="filter-check">
                            <input type="checkbox" name="status[]" value="<?= htmlspecialchars($st) ?>"
                                <?= in_array($st, $statusList, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($st) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if (!empty($cityOptions)): ?>
            <section class="filter-section">
                <h3 class="filter-section-title">City</h3>
                <div class="filter-check-list filter-check-list-scroll">
                    <?php foreach ($cityOptions as $cName): ?>
                        <label class="filter-check">
                            <input type="checkbox" name="city[]" value="<?= htmlspecialchars($cName) ?>"
                                <?= in_array($cName, $cityList, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($cName) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="filter-section">
                <h3 class="filter-section-title">Missing / GPS</h3>
                <div class="filter-check-list">
                    <?php foreach ($missingOptions as $mVal => $mLabel): ?>
                        <label class="filter-check">
                            <input type="checkbox" name="missing[]" value="<?= htmlspecialchars($mVal) ?>"
                                <?= in_array($mVal, $missingList, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($mLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="filter-section">
                <h3 class="filter-section-title">KYC status</h3>
                <div class="filter-check-list">
                    <label class="filter-check">
                        <input type="checkbox" name="kyc[]" value="pending" <?= in_array('pending', $kycList, true) ? 'checked' : '' ?>>
                        <span>KYC pending</span>
                    </label>
                    <label class="filter-check">
                        <input type="checkbox" name="kyc[]" value="done" <?= in_array('done', $kycList, true) ? 'checked' : '' ?>>
                        <span>KYC within <?= (int)$kycGapMonths ?> months</span>
                    </label>
                </div>
            </section>
        </div>
        <footer class="filter-drawer-foot">
            <a href="profiles.php<?= $search !== '' ? ('?q=' . urlencode($search)) : '' ?>" class="btn btn-secondary">Clear all</a>
            <button type="submit" class="btn btn-primary">Apply filters</button>
        </footer>
    </form>
</aside>

<!-- Global loading overlay (search / filter / page change) -->
<div class="profiles-fetch-loading" id="profiles-list-loading" hidden aria-live="polite" aria-busy="false">
    <div class="profiles-list-loading-inner">
        <div class="profiles-list-spinner" aria-hidden="true"></div>
        <div class="profiles-list-loading-text">Loading freezers…</div>
        <div class="profiles-list-loading-sub">Please wait</div>
    </div>
</div>

<!-- Profiles Table -->
<div class="card profiles-list-card" id="profiles-list-card">
    <div class="card-header">
        <div class="card-title">
            <span><?= $kyc === 'pending' ? 'KYC pending freezers' : ($kyc === 'done' ? 'KYC within window' : 'Registered Freezers') ?></span>
            <span class="badge <?= $kyc === 'pending' ? 'badge-warning' : 'badge-neutral' ?>" style="margin-left: 0.5rem;"><?= $totalCount ?> Total Records</span>
        </div>
        <span class="profiles-page-size">20 per page</span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Unique Freezer ID</th>
                    <th>Store / Outlet</th>
                    <th>Type</th>
                    <th>Brand</th>
                    <th>Tag code</th>
                    <th>KYC</th>
                    <?php if (AppConfig::IOT_ENABLED): ?><th>IoT Status</th><?php endif; ?>
                    <th>Condition</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($freezers)): ?>
                    <tr>
                        <td colspan="<?= AppConfig::IOT_ENABLED ? 9 : 8 ?>" style="text-align: center; padding: 3rem 1rem; color: #94a3b8;">
                            <div style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem;">No freezer profiles matched your search</div>
                            <p style="font-size: 0.85rem;">Try modifying your keyword filter or register a new freezer.</p>
                            <a href="qr_scanner.php" class="btn btn-primary" style="margin-top: 1rem;">Scan QR → Register</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($freezers as $row): ?>
                        <?php
                        $rowInstall = !empty($row['installation_date']) ? (string)$row['installation_date'] : null;
                        $rowLastKyc = !empty($row['last_kyc_at']) ? (string)$row['last_kyc_at'] : null;
                        $rowKycDue = FreezerLifecycle::isKycDue($rowLastKyc, null, $rowInstall);
                        $rowAnchor = FreezerLifecycle::kycAnchorDate($rowLastKyc, $rowInstall);
                        $rowNextDue = FreezerLifecycle::nextKycDueDate($rowAnchor);
                        ?>
                        <tr>
                            <td>
                                <a href="profile_view.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="table-id-link">
                                    <?= htmlspecialchars($row['unique_freezer_id']) ?>
                                </a>
                                <div style="font-size: 0.75rem; color: #64748b;">Model: <?= htmlspecialchars($row['model_number']) ?></div>
                            </td>
                            <td>
                                <strong style="color: #0f172a;"><?= htmlspecialchars($row['store_name']) ?></strong>
                                <div style="font-size: 0.725rem; color: #94a3b8;">Outlet: <?= htmlspecialchars($row['outlet_id']) ?></div>
                            </td>
                            <td>
                                <span class="badge badge-primary"><?= htmlspecialchars($row['freezer_type']) ?></span>
                            </td>
                            <td>
                                <span style="font-size: 0.85rem; font-weight: 600; color: #334155;"><?= htmlspecialchars($row['brand_name'] ?? $row['company_code'] ?? '—') ?></span>
                                <?php if (!empty($row['company_code'])): ?>
                                    <div style="font-size: 0.7rem; color: #94a3b8;"><?= htmlspecialchars($row['company_code']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['tag_code'])): ?>
                                    <a href="tag.php?t=<?= urlencode($row['tag_code']) ?>"
                                       style="font-family:'JetBrains Mono',ui-monospace,monospace; font-weight:700; font-size:0.85rem; color:#14532d; text-decoration:none; letter-spacing:0.02em;">
                                        <?= htmlspecialchars($row['tag_code']) ?>
                                    </a>
                                    <div style="font-size:0.7rem; color:#94a3b8; margin-top:0.15rem;"><?= htmlspecialchars($row['store_city'] ?? '') ?></div>
                                <?php else: ?>
                                    <span style="font-size:0.8rem; color:#94a3b8;">—</span>
                                    <?php if (!empty($row['store_city'])): ?>
                                        <div style="font-size:0.7rem; color:#94a3b8;"><?= htmlspecialchars($row['store_city']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rowKycDue): ?>
                                    <div class="kyc-pending-date">
                                        <?php if ($rowLastKyc): ?>
                                            Last <?= htmlspecialchars($rowLastKyc) ?>
                                        <?php elseif ($rowInstall): ?>
                                            Install <?= htmlspecialchars($rowInstall) ?>
                                        <?php else: ?>
                                            Never done
                                        <?php endif; ?>
                                    </div>
                                    <?php if (AuthService::hasRole(['super_admin', 'technician'])): ?>
                                        <a href="<?= htmlspecialchars(\App\Services\KycScanGate::scannerUrl($row)) ?>" class="btn btn-sm btn-primary" style="margin-top:0.35rem;">KYC</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="kyc-ok-date">
                                        Due <?= htmlspecialchars((string)($rowNextDue ?? '—')) ?>
                                        <?php if (!$rowLastKyc && $rowInstall): ?>
                                            <span style="display:block;">from install</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php if (AppConfig::IOT_ENABLED): ?>
                            <td>
                                <?php if (!empty($row['device_serial'])): ?>
                                    <span class="badge badge-success">Online (<?= htmlspecialchars($row['device_serial']) ?>)</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral">No IoT</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <span style="font-size: 0.75rem; color: #475569;"><?= htmlspecialchars($row['freezer_condition']) ?></span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="profile_view.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="btn btn-sm btn-secondary" title="View Full Details">View</a>
                                <a href="profile_edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline" title="Edit">Edit</a>
                                <a href="qr_print.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="btn btn-sm btn-outline" target="_blank" title="Print Tag">Print</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination — same pattern as Tag inventory -->
    <?php if ($totalCount > 0): ?>
    <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
        <div style="font-size:0.85rem; color:#64748b;">
            Showing <strong><?= (int)$rangeFrom ?>–<?= (int)$rangeTo ?></strong> of <strong><?= (int)$totalCount ?></strong>
            · 20 per page
        </div>
        <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
            <?php
            $prevPage = max(1, $page - 1);
            $nextPage = min($totalPages, $page + 1);
            $canPrev = $page > 1 && $totalCount > 0;
            $canNext = $page < $totalPages && $totalCount > 0;
            ?>
            <?php if ($canPrev): ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($profilesPageUrl($prevPage)) ?>" data-profiles-loading>Prev</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary" disabled aria-disabled="true" title="No previous page">Prev</button>
            <?php endif; ?>
            <?php
            $window = 2;
            $startP = max(1, $page - $window);
            $endP = min($totalPages, $page + $window);
            if ($startP > 1): ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($profilesPageUrl(1)) ?>" data-profiles-loading>1</a>
                <?php if ($startP > 2): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $startP; $p <= $endP; $p++): ?>
                <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"
                   href="<?= htmlspecialchars($profilesPageUrl($p)) ?>"
                   data-profiles-loading><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($endP < $totalPages): ?>
                <?php if ($endP < $totalPages - 1): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($profilesPageUrl($totalPages)) ?>" data-profiles-loading><?= (int)$totalPages ?></a>
            <?php endif; ?>
            <?php if ($canNext): ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($profilesPageUrl($nextPage)) ?>" data-profiles-loading>Next</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary" disabled aria-disabled="true" title="No next page">Next</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var openBtn = document.getElementById('profiles-filter-open');
    var closeBtn = document.getElementById('profiles-filter-close');
    var drawer = document.getElementById('profiles-filter-drawer');
    var backdrop = document.getElementById('profiles-filter-backdrop');
    var loadingEl = document.getElementById('profiles-list-loading');

    function showProfilesLoading() {
        var el = loadingEl || document.getElementById('profiles-list-loading');
        if (!el) {
            el = document.createElement('div');
            el.id = 'profiles-list-loading';
            el.className = 'profiles-fetch-loading';
            el.innerHTML = '<div class="profiles-list-loading-inner">' +
                '<div class="profiles-list-spinner" aria-hidden="true"></div>' +
                '<div class="profiles-list-loading-text">Loading freezers…</div>' +
                '<div class="profiles-list-loading-sub">Please wait</div></div>';
            document.body.appendChild(el);
            loadingEl = el;
        }
        el.hidden = false;
        el.removeAttribute('hidden');
        el.classList.add('is-on');
        el.setAttribute('aria-busy', 'true');
        document.body.classList.add('profiles-is-loading');
        // Force paint before navigation so spinner is visible
        void el.offsetWidth;
    }

    function openDrawer() {
        if (!drawer || !backdrop) return;
        drawer.hidden = false;
        backdrop.hidden = false;
        requestAnimationFrame(function () {
            drawer.classList.add('is-open');
            backdrop.classList.add('is-open');
        });
        document.body.classList.add('filter-drawer-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    }

    function closeDrawer() {
        if (!drawer || !backdrop) return;
        drawer.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('filter-drawer-open');
        setTimeout(function () {
            if (!drawer.classList.contains('is-open')) {
                drawer.hidden = true;
                backdrop.hidden = true;
            }
        }, 280);
    }

    if (openBtn) openBtn.addEventListener('click', openDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if (backdrop) backdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drawer && drawer.classList.contains('is-open')) closeDrawer();
    });

    // Loading spinner on search / filter / pagination
    document.querySelectorAll('#profiles-search-form, #profiles-filter-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            showProfilesLoading();
        });
    });

    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-profiles-loading], .profiles-filter-bar a.btn-secondary, .filter-drawer-foot a.btn-secondary');
        if (!link) return;
        if (link.disabled || link.getAttribute('aria-disabled') === 'true' || link.classList.contains('disabled')) {
            e.preventDefault();
            return;
        }
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        showProfilesLoading();
    }, true);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
