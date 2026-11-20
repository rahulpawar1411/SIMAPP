<?php
/**
 * Freezer Profiles list (Assets).
 * Search, filters, export, and mobile card layout.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\ImportExportService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\MasterData;
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
// AMC due filter: profiles.php?kyc=pending|done|failed
$kycList = $asList($_GET['kyc'] ?? []);
$kycList = array_values(array_intersect($kycList, ['pending', 'done', 'failed']));

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20; // Fixed page size — keeps Assets list light under load
$offset = ($page - 1) * $limit;

$filters = [
    'q' => $search,
    'type' => $typeList,
    'brand' => $brandList,
    'status' => $statusList,
    'city' => $cityList,
    'missing' => $missingList,
    'kyc' => $kycList, // pending = AMC due
];

// Technicians with assigned zone/city only see freezers in that area
$areaScope = AuthService::areaScope();
$filters = AuthService::applyAreaToFilters($filters);
if ($areaScope['city'] !== '') {
    $cityList = [$areaScope['city']];
}
// Technician Home “Total installations” → only freezers they filled
$mineOnly = !empty($_GET['mine']) && (($currentUser['role'] ?? '') === AppConfig::ROLE_TECHNICIAN);
if ($mineOnly) {
    $filters['created_by'] = (int)($currentUser['id'] ?? 0);
}

$activeFilterCount = count($typeList) + count($brandList) + count($statusList)
    + count($cityList) + count($missingList) + count($kycList);
$hasActiveFilters = $search !== '' || $activeFilterCount > 0;

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

$tabCountAll = 0;
try {
    $tabCountAll = Freezer::countFiltered($filters);
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'profiles.tab_counts');
    $tabCountAll = 0;
}

$totalCount = 0;
$freezers = [];
$profilesListError = null;
try {
    $totalCount = Freezer::countFiltered($filters);
    $totalPages = max(1, (int)ceil($totalCount / max(1, $limit)));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $limit;
    $freezers = $totalCount > 0 ? Freezer::getFiltered($filters, $limit, $offset) : [];
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'profiles.list');
    $profilesListError = \App\Services\AppLogger::friendlyMessage(
        $e,
        'Could not load profiles. Please refresh the page.'
    );
    $totalPages = 1;
    $page = 1;
    $offset = 0;
}
$rangeFrom = $totalCount === 0 ? 0 : ($offset + 1);
$rangeTo = min($offset + $limit, $totalCount);

/** Shared query extras preserved across tabs / pagination */
$profilesKeepParams = static function () use ($search, $typeList, $brandList, $statusList, $cityList, $missingList, $mineOnly): array {
    return array_filter([
        'q' => $search !== '' ? $search : null,
        'type' => $typeList ?: null,
        'brand' => $brandList ?: null,
        'status' => $statusList ?: null,
        'city' => $cityList ?: null,
        'missing' => $missingList ?: null,
        'mine' => $mineOnly ? '1' : null,
    ], static fn($v) => $v !== null && $v !== '' && $v !== []);
};

/** Build page URL keeping current filters */
$profilesPageUrl = static function (int $p) use ($profilesKeepParams, $kycList): string {
    $params = $profilesKeepParams();
    if ($kycList) {
        $params['kyc'] = $kycList;
    }
    if ($p > 1) {
        $params['page'] = $p;
    }
    $qs = http_build_query($params);
    return 'profiles.php' . ($qs !== '' ? '?' . $qs : '');
};

$freezerTypes = MasterData::getFreezerTypes();
$brands = MasterData::getBrands();

$cityOptions = [];
try {
    if ($areaScope['city'] !== '') {
        $cityOptions = [$areaScope['city']];
    } else {
        $cityOptions = Freezer::dashboardCities($areaScope['zone'] !== '' ? $areaScope['zone'] : null);
        if (count($cityOptions) > 80) {
            $cityOptions = array_slice($cityOptions, 0, 80);
        }
    }
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'profiles.cities');
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

/** Build Assets tab URL keeping search + filters (+ mine) */
$profilesTabUrl = static function (string $tab) use ($profilesKeepParams): string {
    $params = $profilesKeepParams();
    if ($tab === 'pending') {
        $params['kyc'] = ['pending'];
    } elseif ($tab === 'done') {
        $params['kyc'] = ['done'];
    } elseif ($tab === 'failed') {
        $params['kyc'] = ['failed'];
    }
    // page resets to 1 when switching tabs
    $qs = http_build_query($params);
    return 'profiles.php' . ($qs !== '' ? '?' . $qs : '');
};
$activeTab = 'all';
if ($kycList === ['pending']) {
    $activeTab = 'pending';
} elseif ($kycList === ['done']) {
    $activeTab = 'done';
} elseif ($kycList === ['failed']) {
    $activeTab = 'failed';
}

$pageTitle = 'Freezer & Store Profiles';
require_once __DIR__ . '/includes/header.php';
?>

<div class="cfg-toolbar">
    <div class="cfg-toolbar-left">
        <h1 class="cfg-toolbar-title">Freezer &amp; Store Profiles</h1>
    </div>
    <div class="cfg-toolbar-actions">
        <?php if ($isAdmin): ?>
        <a href="qr_print_bulk.php?<?= http_build_query(array_filter(['q' => $search, 'city' => $cityList, 'status' => $statusList], static fn($v) => $v !== '' && $v !== [])) ?>" class="btn btn-outline" target="_blank">
            Print all stickers (PDF)
        </a>
        <a href="profiles.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-secondary" title="Full store + freezer + IoT data">
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

<?php if (!empty($profilesListError)): ?>
<div class="alert alert-danger" role="alert" style="margin:0.75rem 0;">
    <?= htmlspecialchars($profilesListError) ?>
</div>
<?php endif; ?>

<!-- Search + Filter button (Flipkart-style drawer) -->
<div class="card profiles-filter-bar">
    <div class="card-body">
        <form method="GET" action="" class="profiles-search-row" id="profiles-search-form">
            <?php if ($mineOnly): ?>
                <input type="hidden" name="mine" value="1">
            <?php endif; ?>
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
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Right filter drawer -->
<div class="filter-drawer-backdrop" id="profiles-filter-backdrop" hidden></div>
<aside class="filter-drawer" id="profiles-filter-drawer" role="dialog" aria-modal="true" aria-labelledby="profiles-filter-title" hidden>
    <form method="GET" action="" id="profiles-filter-form" class="filter-drawer-form">
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <?php if ($mineOnly): ?>
            <input type="hidden" name="mine" value="1">
        <?php endif; ?>
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
            <span>Registered Freezers</span>
            <span class="badge badge-neutral" style="margin-left: 0.5rem;"><?= number_format($totalCount) ?> record<?= $totalCount === 1 ? '' : 's' ?></span>
            <?php if ($totalPages > 1): ?>
                <span class="badge badge-neutral" style="margin-left:0.35rem;">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span>
            <?php endif; ?>
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
                    <?php if (AppConfig::IOT_ENABLED): ?><th>IoT Status</th><?php endif; ?>
                    <th>Active since</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($freezers)): ?>
                    <tr>
                        <td colspan="<?= AppConfig::IOT_ENABLED ? 8 : 7 ?>" style="text-align: center; padding: 3rem 1rem; color: #94a3b8;">
                            <div style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem;">No freezer profiles matched your search</div>
                            <p style="font-size: 0.85rem;">Try modifying your keyword filter or register a new freezer.</p>
                            <a href="qr_scanner.php" class="btn btn-primary" style="margin-top: 1rem;">Scan QR → Register</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($freezers as $row): ?>
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
                            <?php if (AppConfig::IOT_ENABLED): ?>
                            <td>
                                <?php if (!empty($row['device_serial'])): ?>
                                    <span class="badge badge-success">Online (<?= htmlspecialchars($row['device_serial']) ?>)</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral">No IoT</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="elixia-active-since" data-unique-id="<?= htmlspecialchars((string)$row['unique_freezer_id']) ?>">
                                <?php if (AppConfig::ELIXIA_VTS_ENABLED): ?>
                                    <span style="font-size:0.78rem;color:#94a3b8;">Loading…</span>
                                <?php else: ?>
                                    <span style="font-size:0.8rem;color:#94a3b8;">—</span>
                                <?php endif; ?>
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

    <!-- Mobile card list (Freezer SIM style) -->
    <div class="mapp-asset-list" id="profiles-mobile-cards">
        <?php if (empty($freezers)): ?>
            <div class="mapp-empty-state">
                <strong>No freezers found</strong>
                <p>Try another tab or register a new unit.</p>
                <a href="qr_scanner.php" class="btn btn-primary mapp-btn">Scan QR → Register</a>
            </div>
        <?php else: ?>
            <?php foreach ($freezers as $row): ?>
                <?php
                $fid = (string)($row['unique_freezer_id'] ?? '');
                $canEdit = AuthService::hasRole(['super_admin', 'technician']);
                ?>
                <article class="mapp-asset-card" data-unique-id="<?= htmlspecialchars($fid) ?>">
                    <a class="mapp-asset-main" href="profile_view.php?id=<?= urlencode($fid) ?>">
                        <div class="mapp-asset-top">
                            <strong><?= htmlspecialchars((string)($row['store_name'] ?? 'Store')) ?></strong>
                            <span class="mapp-pill mapp-pill-pending elixia-active-since-ago" data-unique-id="<?= htmlspecialchars($fid) ?>">…</span>
                        </div>
                        <div class="mapp-asset-id"><?= htmlspecialchars($fid) ?></div>
                        <div class="mapp-asset-meta">
                            <span><?= htmlspecialchars((string)($row['store_city'] ?? '—')) ?></span>
                            <span><?= htmlspecialchars((string)($row['tag_code'] ?? $row['freezer_type'] ?? '')) ?></span>
                            <span class="elixia-active-since" data-unique-id="<?= htmlspecialchars($fid) ?>">Active since…</span>
                        </div>
                    </a>
                    <div class="mapp-asset-actions">
                        <?php if ($canEdit): ?>
                            <a href="profile_edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                        <?php endif; ?>
                        <a href="profile_view.php?id=<?= urlencode($fid) ?>" class="btn btn-sm btn-secondary">Details</a>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination — count matches active tab filter -->
    <div class="card-footer profiles-pagination" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
        <div style="font-size:0.85rem; color:#64748b;">
            <?php if ($totalCount === 0): ?>
                <strong>0</strong> records
            <?php else: ?>
                Showing <strong><?= (int)$rangeFrom ?>–<?= (int)$rangeTo ?></strong> of <strong><?= number_format($totalCount) ?></strong>
                · <?= (int)$limit ?> per page
                <?php if ($totalPages > 1): ?>
                    · Page <strong><?= (int)$page ?></strong> of <strong><?= (int)$totalPages ?></strong>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
        <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
            <?php
            $prevPage = max(1, $page - 1);
            $nextPage = min($totalPages, $page + 1);
            $canPrev = $page > 1;
            $canNext = $page < $totalPages;
            $window = 2;
            $startP = max(1, $page - $window);
            $endP = min($totalPages, $page + $window);
            ?>
            <?php if ($canPrev): ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($profilesPageUrl($prevPage)) ?>" data-profiles-loading>Prev</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary" disabled aria-disabled="true">Prev</button>
            <?php endif; ?>
            <?php if ($startP > 1): ?>
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
                <button type="button" class="btn btn-sm btn-secondary" disabled aria-disabled="true">Next</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var openBtn = document.getElementById('profiles-filter-open');
    var closeBtn = document.getElementById('profiles-filter-close');
    var drawer = document.getElementById('profiles-filter-drawer');
    var backdrop = document.getElementById('profiles-filter-backdrop');
    var loadingEl = document.getElementById('profiles-list-loading');

    function showProfilesLoading(msg) {
        var el = loadingEl || document.getElementById('profiles-list-loading');
        if (!el) {
            el = document.createElement('div');
            el.id = 'profiles-list-loading';
            el.className = 'profiles-fetch-loading';
            el.innerHTML = '<div class="profiles-list-loading-inner">' +
                '<div class="profiles-list-spinner" aria-hidden="true"></div>' +
                '<div class="profiles-list-loading-text">Loading freezers…</div>' +
                '<div class="profiles-list-loading-sub">Please wait</div>' +
                '<div class="profiles-skeleton" aria-hidden="true">' +
                '<div class="profiles-skel-card"></div><div class="profiles-skel-card"></div><div class="profiles-skel-card"></div>' +
                '</div></div>';
            document.body.appendChild(el);
            loadingEl = el;
        }
        var textEl = el.querySelector('.profiles-list-loading-text');
        if (textEl && msg) {
            textEl.textContent = msg;
        }
        el.hidden = false;
        el.removeAttribute('hidden');
        el.classList.add('is-on');
        el.setAttribute('aria-busy', 'true');
        document.body.classList.add('profiles-is-loading');
        var tabs = document.getElementById('assets-status-tabs');
        if (tabs) {
            tabs.classList.add('is-loading');
            tabs.setAttribute('aria-busy', 'true');
        }
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
        var tabLink = e.target.closest('#assets-status-tabs a[data-profiles-loading]');
        var link = tabLink || e.target.closest('[data-profiles-loading], .profiles-filter-bar a.btn-secondary, .filter-drawer-foot a.btn-secondary');
        if (!link) return;
        if (link.disabled || link.getAttribute('aria-disabled') === 'true' || link.classList.contains('disabled')) {
            e.preventDefault();
            return;
        }
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        if (tabLink) {
            if (tabLink.classList.contains('is-active')) {
                e.preventDefault();
                return;
            }
            var label = (tabLink.getAttribute('data-assets-tab') || tabLink.textContent || 'list').trim();
            var msgMap = { all: 'Loading all freezers…', done: 'Loading freezers…', pending: 'Loading freezers…', failed: 'Loading freezers…' };
            showProfilesLoading(msgMap[label] || ('Loading ' + tabLink.textContent.trim() + '…'));
            return;
        }
        showProfilesLoading();
    }, true);
})();
</script>

<?php if (AppConfig::ELIXIA_VTS_ENABLED && !empty($freezers)): ?>
<script>
(function () {
    function normalizeId(id) {
        return String(id || '').toUpperCase().replace(/\s+/g, '');
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function parseApiTime(raw) {
        if (!raw) return null;
        var s = String(raw).trim();
        if (!s) return null;
        // Common Elixia formats: "19-11-2026 18:45:00", "2026-11-19 18:45:00", ISO
        var m = s.match(/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
        if (m) {
            return new Date(+m[3], +m[2] - 1, +m[1], +m[4], +m[5], +(m[6] || 0));
        }
        m = s.match(/^(\d{4})[\/\-](\d{2})[\/\-](\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
        if (m) {
            return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
        }
        var d = new Date(s);
        return isNaN(d.getTime()) ? null : d;
    }
    function agoText(date) {
        if (!date) return '';
        var diff = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
        var days = Math.floor(diff / 86400);
        return days + ' day' + (days === 1 ? '' : 's') + ' ago';
    }
    function fmtDate(date, fallback) {
        if (!date) return fallback || '—';
        var dd = String(date.getDate()).padStart(2, '0');
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var hh = String(date.getHours()).padStart(2, '0');
        var mm = String(date.getMinutes()).padStart(2, '0');
        return dd + ' ' + months[date.getMonth()] + ' ' + date.getFullYear() + ', ' + hh + ':' + mm;
    }
    function idleLabel(sec) {
        if (sec === null || sec === undefined || sec === '') return '';
        var n = parseInt(sec, 10);
        if (!isFinite(n) || n < 0) return '';
        var d = Math.floor(n / 86400);
        var h = Math.floor((n % 86400) / 3600);
        var m = Math.floor((n % 3600) / 60);
        var parts = [];
        if (d > 0) parts.push(d + 'd');
        if (h > 0) parts.push(h + 'h');
        if (m > 0 || !parts.length) parts.push(m + 'm');
        return parts.join(' ');
    }

    var cells = document.querySelectorAll('.elixia-active-since[data-unique-id]');
    var agoPills = document.querySelectorAll('.elixia-active-since-ago[data-unique-id]');
    if (!cells.length && !agoPills.length) return;

    fetch('api/elixia_list.php?status=all', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var map = {};
            if (data && data.ok && Array.isArray(data.devices)) {
                data.devices.forEach(function (d) {
                    var key = normalizeId(d.vehicleno || '');
                    if (key) map[key] = d;
                });
            }

            function fill(el, isMobileMeta) {
                var key = normalizeId(el.getAttribute('data-unique-id') || '');
                var d = map[key];
                if (!d) {
                    el.innerHTML = isMobileMeta
                        ? 'No API match'
                        : '<span style="font-size:0.8rem;color:#94a3b8;">No API match</span>';
                    return { ago: 'No API', ok: false };
                }
                var raw = (d.lastupdated || d.timestamp || d.systemtime || '').toString().trim();
                var dt = parseApiTime(raw);
                var main = fmtDate(dt, raw || '—');
                var ago = agoText(dt);
                var idle = idleLabel(d.idleSince);
                if (isMobileMeta) {
                    el.textContent = raw ? ('Active since ' + main) : 'No last update';
                } else {
                    var html = '<div style="font-size:0.78rem;font-weight:700;color:#0f172a;white-space:nowrap;" title="'
                        + esc(raw) + '">' + esc(main) + '</div>';
                    if (ago) {
                        html += '<div style="font-size:0.68rem;color:#64748b;margin-top:0.1rem;">' + esc(ago) + '</div>';
                    }
                    if (idle) {
                        html += '<div style="font-size:0.68rem;color:#0284c7;margin-top:0.05rem;">Idle ' + esc(idle) + '</div>';
                    }
                    el.innerHTML = html;
                }
                return { ago: ago || (raw ? 'Updated' : '—'), ok: !!raw, active: !!d.active };
            }

            cells.forEach(function (el) {
                fill(el, !!el.closest('.mapp-asset-meta'));
            });
            agoPills.forEach(function (el) {
                var key = normalizeId(el.getAttribute('data-unique-id') || '');
                var d = map[key];
                el.classList.remove('mapp-pill-pending', 'mapp-pill-success');
                if (!d) {
                    el.classList.add('mapp-pill-pending');
                    el.textContent = 'No API';
                    return;
                }
                var raw = (d.lastupdated || d.timestamp || '').toString().trim();
                var dt = parseApiTime(raw);
                var ago = agoText(dt);
                el.classList.add(d.active ? 'mapp-pill-success' : 'mapp-pill-pending');
                el.textContent = ago || (d.active ? 'Active' : 'Inactive');
            });
        })
        .catch(function () {
            cells.forEach(function (el) {
                el.innerHTML = '<span style="font-size:0.8rem;color:#94a3b8;">API error</span>';
            });
            agoPills.forEach(function (el) {
                el.classList.remove('mapp-pill-success');
                el.classList.add('mapp-pill-pending');
                el.textContent = 'API error';
            });
        });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
