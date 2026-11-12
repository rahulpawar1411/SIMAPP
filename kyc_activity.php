<?php
/**
 * KYC Activity (Super Admin).
 * Pending + completed KYC in one table, with tabs, filters, pagination, and Excel export.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Services\ExcelXlsxWriter;
use App\Models\FreezerLifecycle;

// Admin only — KYC verification history
AuthService::requireRole(AppConfig::ROLE_SUPER_ADMIN);
$currentUser = AuthService::currentUser();

$error = null;

$today = date('Y-m-d');
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate = trim((string)($_GET['to'] ?? ''));
$days = (int)($_GET['days'] ?? 0);
$locationFilter = strtolower(trim((string)($_GET['location'] ?? 'all')));
if (!in_array($locationFilter, ['all', 'same', 'new'], true)) {
    $locationFilter = 'all';
}
$resultFilter = strtoupper(trim((string)($_GET['result'] ?? 'all')));
if (!in_array($resultFilter, ['ALL', 'PASS', 'FAIL', 'PENDING'], true)) {
    $resultFilter = 'ALL';
}

$validDate = static function (string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
};

if (!$validDate($fromDate) || !$validDate($toDate)) {
    if (!in_array($days, [7, 30, 90, 180, 365], true)) {
        $days = 90;
    }
    $toDate = $today;
    $fromDate = date('Y-m-d', strtotime("-{$days} days"));
} else {
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
    $maxFrom = date('Y-m-d', strtotime($toDate . ' -180 days'));
    if ($fromDate < $maxFrom) {
        $fromDate = $maxFrom;
    }
    $days = 0;
}

$kycPendingCount = 0;
$kycPendingList = [];
$kycGapMonths = FreezerLifecycle::KYC_GAP_MONTHS;
try {
    $kycDash = FreezerLifecycle::dashboardKycStats(100);
    $kycPendingCount = (int)($kycDash['pending'] ?? 0);
    $kycPendingList = $kycDash['pending_list'] ?? [];
    $kycGapMonths = (int)($kycDash['gap_months'] ?? $kycGapMonths);
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'kyc_activity.pending');
    $kycPendingCount = 0;
    $kycPendingList = [];
}

$history = [];
try {
    $history = FreezerLifecycle::notificationHistoryByDate(90, $fromDate, $toDate, $locationFilter, 'ALL');
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'kyc_activity.history');
    $history = [];
    $error = \App\Services\AppLogger::friendlyMessage(
        $e,
        'Could not load KYC history. Please try again.'
    );
}

$historyTruncated = false;
$grandPass = 0;
$grandFail = 0;

// One unified row list: pending + completed history
$allRows = [];

foreach ($kycPendingList as $row) {
    $last = (string)($row['last_kyc_at'] ?? '');
    $due = (string)($row['next_kyc_due'] ?? '');
    $install = (string)($row['installation_date'] ?? '');
    $allRows[] = [
        'kind' => 'PENDING',
        'status' => 'Pending',
        'type' => 'Due',
        'title' => 'Pending KYC due',
        'freezer_id' => (string)($row['id'] ?? ''),
        'unique_freezer_id' => (string)($row['unique_freezer_id'] ?? ''),
        'store_name' => (string)($row['store_name'] ?? ''),
        'outlet_id' => (string)($row['outlet_id'] ?? ''),
        'city' => (string)($row['city'] ?? ''),
        'zone' => (string)($row['zone'] ?? ''),
        'date' => $due !== '' ? $due : '—',
        'date_label' => 'Due',
        'extra' => $last !== '' ? substr($last, 0, 10) : 'Never',
        'extra_label' => 'Last KYC',
        'notes' => '',
        'technician_name' => '',
        'verified_at' => '',
        'last_kyc_at' => $last !== '' ? substr($last, 0, 10) : '',
        'next_kyc_due' => $due,
        'installation_date' => $install !== '' ? substr($install, 0, 10) : '',
        'gap_months' => (int)$kycGapMonths,
        'image_store_front' => '',
        'image_freezer' => '',
        'latitude' => '',
        'longitude' => '',
        'sort' => $due !== '' ? $due : '9999-12-31',
    ];
}

foreach ($history as $day) {
    if (!empty($day['_truncated'])) {
        $historyTruncated = true;
    }
    $dayLabel = (string)($day['label'] ?? $day['date'] ?? '');
    foreach ($day['items'] ?? [] as $item) {
        $res = strtoupper(trim((string)($item['result'] ?? 'PASS')));
        if ($res === 'FAIL') {
            $grandFail++;
        } else {
            $grandPass++;
            $res = 'PASS';
        }
        $verified = (string)($item['verified_at'] ?? '');
        $type = !empty($item['is_new_store']) ? 'New store' : 'Same store';
        $allRows[] = [
            'kind' => $res,
            'status' => $res,
            'type' => $type,
            'title' => $res === 'FAIL' ? 'Failed KYC' : 'Completed KYC',
            'freezer_id' => (string)($item['freezer_id'] ?? ''),
            'unique_freezer_id' => (string)($item['unique_freezer_id'] ?? ''),
            'store_name' => (string)($item['store_name'] ?? ''),
            'outlet_id' => (string)($item['outlet_id'] ?? ''),
            'city' => (string)($item['city'] ?? ''),
            'zone' => (string)($item['zone'] ?? ''),
            'date' => $dayLabel !== '' ? $dayLabel : substr($verified, 0, 10),
            'date_label' => 'Done',
            'extra' => (string)($item['technician_name'] ?? ''),
            'extra_label' => 'Technician',
            'notes' => (string)($item['notes'] ?? ''),
            'technician_name' => (string)($item['technician_name'] ?? ''),
            'verified_at' => $verified,
            'last_kyc_at' => '',
            'next_kyc_due' => '',
            'installation_date' => '',
            'gap_months' => (int)$kycGapMonths,
            'image_store_front' => (string)($item['image_store_front'] ?? ''),
            'image_freezer' => (string)($item['image_freezer'] ?? ''),
            'latitude' => (string)($item['latitude'] ?? ''),
            'longitude' => (string)($item['longitude'] ?? ''),
            'sort' => $verified !== '' ? $verified : ((string)($day['date'] ?? '') . ' 00:00:00'),
        ];
    }
}

// Newest / soonest-due first
usort($allRows, static function (array $a, array $b): int {
    return strcmp((string)$b['sort'], (string)$a['sort']);
});

$tabCountPending = $kycPendingCount;
$tabCountComplete = $grandPass;
$tabCountFailed = $grandFail;
$tabCountAll = $tabCountPending + $tabCountComplete + $tabCountFailed;

$filteredRows = array_values(array_filter($allRows, static function (array $row) use ($resultFilter): bool {
    if ($resultFilter === 'ALL') {
        return true;
    }
    if ($resultFilter === 'PENDING') {
        return $row['kind'] === 'PENDING';
    }
    if ($resultFilter === 'PASS') {
        return $row['kind'] === 'PASS';
    }
    if ($resultFilter === 'FAIL') {
        return $row['kind'] === 'FAIL';
    }
    return true;
}));

$defaultFrom = date('Y-m-d', strtotime('-90 days'));
$isDefaultRange = ($fromDate === $defaultFrom && $toDate === $today && ($days === 90 || $days === 0));
$hasLocationFilter = $locationFilter !== 'all';
$hasActiveFilters = $hasLocationFilter || !$isDefaultRange;
$activeFilterCount = 0;
if (!$isDefaultRange) {
    $activeFilterCount++;
}
if ($hasLocationFilter) {
    $activeFilterCount++;
}

$kycLimit = 20;
$kycTotal = count($filteredRows);
$kycPages = max(1, (int)ceil($kycTotal / $kycLimit));
$kycPage = max(1, (int)($_GET['page'] ?? 1));
if ($kycPage > $kycPages) {
    $kycPage = $kycPages;
}
$kycOffset = ($kycPage - 1) * $kycLimit;
$rows = array_slice($filteredRows, $kycOffset, $kycLimit);
$kycFrom = $kycTotal === 0 ? 0 : ($kycOffset + 1);
$kycTo = min($kycOffset + count($rows), $kycTotal);

$kycQueryParams = static function (array $extra = []) use ($fromDate, $toDate, $days, $locationFilter, $resultFilter, $isDefaultRange, $kycPage): array {
    $params = array_merge([
        'result' => strtolower($resultFilter),
        'location' => $locationFilter,
    ], $extra);
    if ($isDefaultRange && in_array($days, [7, 30, 90, 180, 365], true)) {
        $params['days'] = $days > 0 ? $days : 90;
    } else {
        $params['from'] = $fromDate;
        $params['to'] = $toDate;
    }
    $page = (int)($params['page'] ?? $kycPage);
    if ($page > 1) {
        $params['page'] = $page;
    } else {
        unset($params['page']);
    }
    return $params;
};

$kycTabUrl = static function (string $result) use ($kycQueryParams): string {
    // Tab change always resets to page 1
    $params = $kycQueryParams(['result' => strtolower($result), 'page' => 1]);
    unset($params['page']);
    return 'kyc_activity.php?' . http_build_query($params);
};

$kycPageUrl = static function (int $page) use ($kycQueryParams): string {
    return 'kyc_activity.php?' . http_build_query($kycQueryParams(['page' => max(1, $page)]));
};

// Export current filtered rows (CSV / Excel) — full filtered set, not just page
$exportFmt = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($exportFmt, ['csv', 'xlsx'], true)) {
    $exportRows = [];
    foreach ($filteredRows as $i => $row) {
        $exportRows[] = [
            '#' => $i + 1,
            'Status' => (string)($row['status'] ?? ''),
            'Type' => (string)($row['type'] ?? ''),
            'Freezer' => (string)(($row['unique_freezer_id'] ?? '') !== '' ? $row['unique_freezer_id'] : ('#' . ($row['freezer_id'] ?? ''))),
            'Store' => (string)($row['store_name'] ?? ''),
            'Outlet ID' => (string)($row['outlet_id'] ?? ''),
            'City' => (string)($row['city'] ?? ''),
            'Zone' => (string)($row['zone'] ?? ''),
            'Date label' => (string)($row['date_label'] ?? ''),
            'Date' => (string)($row['date'] ?? ''),
            'Detail label' => (string)($row['extra_label'] ?? ''),
            'Detail' => (string)($row['extra'] ?? ''),
            'Technician' => (string)($row['technician_name'] ?? ''),
            'Verified at' => (string)($row['verified_at'] ?? ''),
            'Notes' => (string)($row['notes'] ?? ''),
        ];
    }
    $stamp = date('Ymd_His');
    $filenameBase = 'kyc_activity_' . strtolower($resultFilter) . '_' . $stamp;
    if ($exportFmt === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        if ($exportRows !== []) {
            fputcsv($out, array_keys($exportRows[0]));
            foreach ($exportRows as $er) {
                fputcsv($out, array_values($er));
            }
        } else {
            fputcsv($out, ['Status', 'Type', 'Freezer', 'Store', 'City', 'Date']);
        }
        fclose($out);
        exit;
    }
    $cols = $exportRows !== [] ? array_keys($exportRows[0]) : ['Status', 'Type', 'Freezer', 'Store', 'City', 'Date'];
    $writer = new ExcelXlsxWriter(static fn(string $c): string => 'kyc');
    $writer->setSheetName('KYC Activity')->setColumns($cols)->setRows($exportRows)->stream($filenameBase . '.xlsx');
    exit;
}

$pageTitle = 'KYC Activity';
require_once __DIR__ . '/includes/header.php';
?>

<div class="users-page notif-page">
    <div class="cfg-toolbar">
        <div class="cfg-toolbar-left">
            <h1 class="cfg-toolbar-title">KYC Activity</h1>
            <?php if ($historyTruncated): ?>
                <span class="badge badge-warning" title="Latest 500 matching records shown">Showing latest 500</span>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:0.45rem;flex-wrap:wrap;">
            <a class="btn btn-outline btn-sm" href="kyc_map.php">KYC Map</a>
            <a class="btn btn-secondary btn-sm" href="kyc_activity.php?<?= htmlspecialchars(http_build_query($kycQueryParams(['export' => 'csv']))) ?>">Export CSV</a>
            <a class="btn btn-outline btn-sm" href="kyc_activity.php?<?= htmlspecialchars(http_build_query($kycQueryParams(['export' => 'xlsx']))) ?>">Export Excel</a>
        </div>
    </div>

    <p class="notif-page-lead">
        Pending + completed KYC in one table. Use tabs + Filter. Click a row for full details.
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <section class="notif-section notif-section-kyc">
        <nav class="assets-status-tabs" id="kyc-activity-tabs" aria-label="KYC status">
            <a href="<?= htmlspecialchars($kycTabUrl('all')) ?>"
               data-kyc-activity-loading
               data-kyc-tab="all"
               class="<?= $resultFilter === 'ALL' ? 'is-active' : '' ?>">
                All <em><?= number_format($tabCountAll) ?></em>
            </a>
            <a href="<?= htmlspecialchars($kycTabUrl('pending')) ?>"
               data-kyc-activity-loading
               data-kyc-tab="pending"
               class="<?= $resultFilter === 'PENDING' ? 'is-active is-pending' : '' ?>">
                Pending KYC <em><?= number_format($tabCountPending) ?></em>
            </a>
            <a href="<?= htmlspecialchars($kycTabUrl('pass')) ?>"
               data-kyc-activity-loading
               data-kyc-tab="pass"
               class="<?= $resultFilter === 'PASS' ? 'is-active is-ok' : '' ?>">
                Complete KYC <em><?= number_format($tabCountComplete) ?></em>
            </a>
            <a href="<?= htmlspecialchars($kycTabUrl('fail')) ?>"
               data-kyc-activity-loading
               data-kyc-tab="fail"
               class="<?= $resultFilter === 'FAIL' ? 'is-active is-issue' : '' ?>">
                Failed <em><?= number_format($tabCountFailed) ?></em>
            </a>
        </nav>

        <div class="card profiles-filter-bar" style="margin-top:0.75rem;">
            <div class="card-body">
                <div class="profiles-search-row">
                    <div class="profiles-search-field">
                        <label class="form-label">Activity range</label>
                        <div class="notif-range-readonly">
                            <?= htmlspecialchars($fromDate) ?> → <?= htmlspecialchars($toDate) ?>
                            <?php if ($locationFilter === 'same'): ?>
                                · Same location
                            <?php elseif ($locationFilter === 'new'): ?>
                                · New location
                            <?php else: ?>
                                · All locations
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="profiles-search-actions">
                        <button type="button" class="btn btn-outline profiles-filter-open" id="activity-filter-open"
                                aria-haspopup="dialog" aria-controls="activity-filter-drawer" aria-expanded="false">
                            <span>Filter</span>
                            <?php if ($activeFilterCount > 0): ?>
                                <span class="profiles-filter-count"><?= (int)$activeFilterCount ?></span>
                            <?php endif; ?>
                        </button>
                        <?php if ($hasActiveFilters): ?>
                            <a href="kyc_activity.php?days=90&location=all&result=<?= urlencode(strtolower($resultFilter)) ?>"
                               class="btn btn-secondary" data-kyc-activity-loading>Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="filter-drawer-backdrop" id="activity-filter-backdrop" hidden></div>
        <aside class="filter-drawer" id="activity-filter-drawer" role="dialog" aria-modal="true" aria-labelledby="activity-filter-title" hidden>
            <form method="GET" action="kyc_activity.php" id="activity-filter-form" class="filter-drawer-form">
                <input type="hidden" name="result" value="<?= htmlspecialchars(strtolower($resultFilter)) ?>">
                <header class="filter-drawer-head">
                    <h2 id="activity-filter-title">Filters</h2>
                    <button type="button" class="filter-drawer-close" id="activity-filter-close" aria-label="Close filters">&times;</button>
                </header>
                <div class="filter-drawer-body">
                    <section class="filter-section">
                        <h3 class="filter-section-title">Date range</h3>
                        <div class="filter-date-grid">
                            <div class="form-group" style="margin-bottom:0.75rem;">
                                <label class="form-label" for="activity-from">From</label>
                                <input type="date" id="activity-from" name="from" class="form-control"
                                       value="<?= htmlspecialchars($fromDate) ?>" max="<?= htmlspecialchars($today) ?>">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="activity-to">To</label>
                                <input type="date" id="activity-to" name="to" class="form-control"
                                       value="<?= htmlspecialchars($toDate) ?>" max="<?= htmlspecialchars($today) ?>">
                            </div>
                        </div>
                        <div class="filter-preset-list" style="margin-top:0.75rem;">
                            <?php
                            $presets = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 180 => 'Last 180 days', 365 => 'Last 1 year'];
                            foreach ($presets as $d => $label):
                                $checked = ($days === (int)$d) || ($isDefaultRange && (int)$d === 90);
                                ?>
                                <label class="filter-check">
                                    <input type="radio" name="days" value="<?= (int)$d ?>"
                                        <?= $checked ? 'checked' : '' ?>
                                        data-preset-days="<?= (int)$d ?>">
                                    <span><?= htmlspecialchars($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="filter-section">
                        <h3 class="filter-section-title">Location type</h3>
                        <div class="filter-check-list">
                            <label class="filter-check">
                                <input type="radio" name="location" value="all" <?= $locationFilter === 'all' ? 'checked' : '' ?>>
                                <span>All locations</span>
                            </label>
                            <label class="filter-check">
                                <input type="radio" name="location" value="same" <?= $locationFilter === 'same' ? 'checked' : '' ?>>
                                <span>Same location</span>
                            </label>
                            <label class="filter-check">
                                <input type="radio" name="location" value="new" <?= $locationFilter === 'new' ? 'checked' : '' ?>>
                                <span>New location</span>
                            </label>
                        </div>
                    </section>
                </div>
                <footer class="filter-drawer-foot">
                    <a href="kyc_activity.php?days=90&location=all&result=<?= urlencode(strtolower($resultFilter)) ?>"
                       class="btn btn-secondary" data-kyc-activity-loading>Clear all</a>
                    <button type="submit" class="btn btn-primary">Apply filters</button>
                </footer>
            </form>
        </aside>

        <div class="card" style="margin-top:0.75rem;">
            <div class="card-header notif-day-head">
                <div class="card-title"><span>KYC records</span></div>
                <div class="notif-day-counts">
                    <span class="badge badge-primary"><?= number_format($kycTotal) ?> total</span>
                    <?php if ($resultFilter !== 'PENDING'): ?>
                        <span class="notif-day-pill"><?= htmlspecialchars($fromDate) ?> → <?= htmlspecialchars($toDate) ?></span>
                    <?php endif; ?>
                    <?php if ($resultFilter === 'PENDING' || $resultFilter === 'ALL'): ?>
                        <a href="profiles.php?kyc[]=pending" class="adash-link" style="font-size:0.8rem;">Open assets</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($rows)): ?>
                    <p style="margin:0;padding:1rem;color:#64748b;">No KYC records for this filter.</p>
                <?php else: ?>
                    <div class="logs-table-wrap" style="max-height:640px;">
                        <table class="logs-excel notif-excel">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>Freezer</th>
                                    <th>Store</th>
                                    <th>City</th>
                                    <th>Date</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $row):
                                    $fid = (string)$row['unique_freezer_id'];
                                    $status = (string)$row['status'];
                                    $type = (string)$row['type'];
                                    $statusClass = $status === 'Pending'
                                        ? 'badge-warning'
                                        : ($status === 'FAIL' ? 'badge-danger' : 'badge-success');
                                    $typeClass = $type === 'New store'
                                        ? 'badge-warning'
                                        : ($type === 'Due' ? 'badge-warning' : 'badge-success');
                                    $hrefId = $fid !== '' ? $fid : (string)$row['freezer_id'];
                                    $detailPayload = [
                                        'title' => (string)$row['title'],
                                        'status' => $status,
                                        'type' => $type,
                                        'unique_freezer_id' => $fid,
                                        'freezer_id' => (string)$row['freezer_id'],
                                        'profile_url' => 'profile_view.php?id=' . rawurlencode($hrefId),
                                        'store_name' => (string)$row['store_name'],
                                        'outlet_id' => (string)$row['outlet_id'],
                                        'city' => (string)$row['city'],
                                        'zone' => (string)$row['zone'],
                                        'date' => (string)$row['date'],
                                        'date_label' => (string)$row['date_label'],
                                        'extra' => (string)$row['extra'],
                                        'extra_label' => (string)$row['extra_label'],
                                        'notes' => (string)$row['notes'],
                                        'technician_name' => (string)$row['technician_name'],
                                        'verified_at' => (string)$row['verified_at'],
                                        'last_kyc_at' => (string)$row['last_kyc_at'],
                                        'next_kyc_due' => (string)$row['next_kyc_due'],
                                        'installation_date' => (string)$row['installation_date'],
                                        'gap_months' => (int)$row['gap_months'],
                                        'image_store_front' => (string)$row['image_store_front'],
                                        'image_freezer' => (string)$row['image_freezer'],
                                        'latitude' => (string)$row['latitude'],
                                        'longitude' => (string)$row['longitude'],
                                    ];
                                    ?>
                                    <tr class="kyc-act-row"
                                        tabindex="0"
                                        role="button"
                                        aria-label="Open KYC details"
                                        data-detail="<?= htmlspecialchars(json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8') ?>">
                                        <td><?= (int)($kycOffset + $i + 1) ?></td>
                                        <td>
                                            <span class="badge badge-sm <?= $statusClass ?>">
                                                <?= htmlspecialchars($status) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-sm <?= $typeClass ?>">
                                                <?= htmlspecialchars($type) ?>
                                            </span>
                                        </td>
                                        <td title="<?= htmlspecialchars($fid) ?>">
                                            <a class="kyc-act-row-link" href="profile_view.php?id=<?= urlencode($hrefId) ?>">
                                                <?= htmlspecialchars($fid !== '' ? $fid : ('#' . $row['freezer_id'])) ?>
                                            </a>
                                        </td>
                                        <td title="<?= htmlspecialchars((string)$row['store_name']) ?>">
                                            <?= htmlspecialchars($row['store_name'] !== '' ? $row['store_name'] : '—') ?>
                                            <?php if ($row['outlet_id'] !== ''): ?>
                                                <div class="notif-day-sub"><?= htmlspecialchars((string)$row['outlet_id']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['city'] !== '' ? $row['city'] : '—') ?></td>
                                        <td>
                                            <span class="notif-day-sub"><?= htmlspecialchars((string)$row['date_label']) ?></span>
                                            <?= htmlspecialchars((string)$row['date']) ?>
                                        </td>
                                        <td>
                                            <span class="notif-day-sub"><?= htmlspecialchars((string)$row['extra_label']) ?></span>
                                            <?= htmlspecialchars($row['extra'] !== '' ? (string)$row['extra'] : '—') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (($resultFilter === 'ALL' || $resultFilter === 'PENDING') && $kycPendingCount > count($kycPendingList)): ?>
                        <div class="notif-day-more-wrap">
                            <a href="profiles.php?kyc[]=pending" class="btn btn-sm btn-outline">
                                Show all <?= number_format($kycPendingCount) ?> pending
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($kycTotal > 0): ?>
            <div class="card-footer profiles-pagination" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
                <div style="font-size:0.85rem; color:#64748b;">
                    Showing <strong><?= (int)$kycFrom ?>–<?= (int)$kycTo ?></strong> of <strong><?= number_format($kycTotal) ?></strong>
                    · <?= (int)$kycLimit ?> per page
                    <?php if ($kycPages > 1): ?>
                        · Page <strong><?= (int)$kycPage ?></strong> of <strong><?= (int)$kycPages ?></strong>
                    <?php endif; ?>
                </div>
                <?php if ($kycPages > 1): ?>
                <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                    <?php
                    $prevPage = max(1, $kycPage - 1);
                    $nextPage = min($kycPages, $kycPage + 1);
                    $window = 2;
                    $startP = max(1, $kycPage - $window);
                    $endP = min($kycPages, $kycPage + $window);
                    ?>
                    <?php if ($kycPage > 1): ?>
                        <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($kycPageUrl($prevPage)) ?>" data-kyc-activity-loading>Prev</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-secondary" disabled>Prev</button>
                    <?php endif; ?>
                    <?php if ($startP > 1): ?>
                        <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($kycPageUrl(1)) ?>" data-kyc-activity-loading>1</a>
                        <?php if ($startP > 2): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $startP; $p <= $endP; $p++): ?>
                        <a class="btn btn-sm <?= $p === $kycPage ? 'btn-primary' : 'btn-secondary' ?>"
                           href="<?= htmlspecialchars($kycPageUrl($p)) ?>"
                           data-kyc-activity-loading><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($endP < $kycPages): ?>
                        <?php if ($endP < $kycPages - 1): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
                        <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($kycPageUrl($kycPages)) ?>" data-kyc-activity-loading><?= (int)$kycPages ?></a>
                    <?php endif; ?>
                    <?php if ($kycPage < $kycPages): ?>
                        <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($kycPageUrl($nextPage)) ?>" data-kyc-activity-loading>Next</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-secondary" disabled>Next</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="kyc-act-modal" id="kyc-act-modal" hidden aria-hidden="true">
    <div class="kyc-act-modal-backdrop" data-kyc-act-close></div>
    <div class="kyc-act-modal-sheet" role="dialog" aria-modal="true" aria-labelledby="kyc-act-modal-title">
        <header class="kyc-act-modal-head">
            <div>
                <p class="kyc-act-modal-kicker">KYC notification</p>
                <h2 id="kyc-act-modal-title">Details</h2>
            </div>
            <button type="button" class="kyc-act-modal-close" data-kyc-act-close aria-label="Close">&times;</button>
        </header>
        <div class="kyc-act-modal-body" id="kyc-act-modal-body"></div>
        <footer class="kyc-act-modal-foot" id="kyc-act-modal-foot"></footer>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('kyc-act-modal');
    var titleEl = document.getElementById('kyc-act-modal-title');
    var bodyEl = document.getElementById('kyc-act-modal-body');
    var footEl = document.getElementById('kyc-act-modal-foot');
    if (!modal || !bodyEl || !titleEl || !footEl) return;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function dash(v) {
        v = String(v == null ? '' : v).trim();
        return v !== '' ? v : '—';
    }

    function statusBadge(status) {
        var cls = status === 'Pending' ? 'badge-warning' : (status === 'FAIL' ? 'badge-danger' : 'badge-success');
        return '<span class="badge ' + cls + '">' + esc(status || '—') + '</span>';
    }

    function typeBadge(type) {
        var cls = (type === 'New store' || type === 'Due') ? 'badge-warning' : 'badge-success';
        return '<span class="badge ' + cls + '">' + esc(type || '—') + '</span>';
    }

    function kv(label, valueHtml) {
        return '<div class="kyc-act-kv">' +
            '<span>' + esc(label) + '</span>' +
            '<strong>' + valueHtml + '</strong>' +
            '</div>';
    }

    function openModal(data) {
        var freezerLabel = data.unique_freezer_id || (data.freezer_id ? ('#' + data.freezer_id) : '—');
        titleEl.textContent = data.title || 'KYC details';

        var badges = '<div class="notif-approval-badges">' +
            statusBadge(data.status) +
            typeBadge(data.type) +
            '</div>';

        var grid = '<div class="kyc-act-kv-grid">' +
            kv('Freezer', '<a class="profile-kv-mono" href="' + esc(data.profile_url || '#') + '">' + esc(freezerLabel) + '</a>') +
            kv('Store', esc(dash(data.store_name))) +
            kv('Outlet ID', esc(dash(data.outlet_id))) +
            kv('City', esc(dash(data.city))) +
            kv('Zone', esc(dash(data.zone))) +
            kv(data.date_label || 'Date', esc(dash(data.date))) +
            '</div>';

        if (data.status === 'Pending') {
            grid += '<div class="kyc-act-kv-grid">' +
                kv('Last KYC', esc(dash(data.last_kyc_at || data.extra))) +
                kv('Next due', esc(dash(data.next_kyc_due || data.date))) +
                kv('Installed', esc(dash(data.installation_date))) +
                kv('KYC gap', esc((data.gap_months || 0) + ' months')) +
                '</div>';
        } else {
            grid += '<div class="kyc-act-kv-grid">' +
                kv('Verified at', esc(dash(data.verified_at))) +
                kv('Technician', esc(dash(data.technician_name || data.extra))) +
                kv('Latitude', esc(dash(data.latitude))) +
                kv('Longitude', esc(dash(data.longitude))) +
                '</div>';
        }

        var remark = '';
        if (data.notes && String(data.notes).trim() !== '') {
            remark = '<div class="notif-remark-box">' +
                '<div class="notif-remark-label">Remark / notes</div>' +
                '<div class="notif-remark-text">' + esc(data.notes) + '</div>' +
                '</div>';
        } else if (data.status === 'Pending') {
            remark = '<div class="notif-remark-box">' +
                '<div class="notif-remark-label">Status</div>' +
                '<div class="notif-remark-text">KYC is due for this freezer. Last verification is older than the allowed gap (' +
                esc(data.gap_months || 0) + ' months), or never done at this store.</div>' +
                '</div>';
        }

        var photos = '';
        var photoBits = [];
        if (data.image_store_front) {
            photoBits.push('<a href="' + esc(data.image_store_front) + '" target="_blank" rel="noopener">' +
                '<img src="' + esc(data.image_store_front) + '" alt="Store photo"><span>Store</span></a>');
        }
        if (data.image_freezer) {
            photoBits.push('<a href="' + esc(data.image_freezer) + '" target="_blank" rel="noopener">' +
                '<img src="' + esc(data.image_freezer) + '" alt="Freezer photo"><span>Freezer</span></a>');
        }
        if (photoBits.length) {
            photos = '<div class="kyc-act-photos"><div class="notif-remark-label">Photos</div><div class="kyc-act-photo-grid">' +
                photoBits.join('') + '</div></div>';
        }

        var steps = '<ol class="notif-approval-steps">' +
            (data.status === 'Pending'
                ? '<li>Freezer is pending KYC verification.</li><li>Technician should visit the store and submit KYC.</li><li>Open the profile to check asset details before visit.</li>'
                : (data.status === 'FAIL'
                    ? '<li>KYC was recorded as FAIL.</li><li>Review technician notes and photos.</li><li>Plan a follow-up visit if needed.</li>'
                    : '<li>KYC completed successfully.</li><li>Location type: ' + esc(data.type || 'Same store') + '.</li><li>Open profile for full asset history.</li>')) +
            '</ol>';

        bodyEl.innerHTML = badges +
            '<p class="kyc-act-modal-lead">' + esc(freezerLabel) + ' · ' + esc(dash(data.store_name)) + '</p>' +
            grid + remark + photos + steps;

        footEl.innerHTML =
            '<button type="button" class="btn btn-secondary" data-kyc-act-close>Close</button>' +
            '<a class="btn btn-primary" href="' + esc(data.profile_url || '#') + '">Open profile</a>';

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(function () {
            modal.classList.add('is-open');
        });
        document.body.classList.add('kyc-act-modal-open');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('kyc-act-modal-open');
        setTimeout(function () {
            if (!modal.classList.contains('is-open')) {
                modal.hidden = true;
                bodyEl.innerHTML = '';
                footEl.innerHTML = '';
            }
        }, 200);
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-kyc-act-close]')) {
            closeModal();
            return;
        }
        if (e.target.closest('.kyc-act-row-link')) {
            e.stopPropagation();
            return;
        }
        var row = e.target.closest('.kyc-act-row');
        if (!row) return;
        var raw = row.getAttribute('data-detail') || '{}';
        var data;
        try {
            data = JSON.parse(raw);
        } catch (err) {
            data = null;
        }
        if (data) openModal(data);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
            return;
        }
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var row = e.target.closest('.kyc-act-row');
        if (!row || e.target.closest('.kyc-act-row-link')) return;
        e.preventDefault();
        var raw = row.getAttribute('data-detail') || '{}';
        try {
            openModal(JSON.parse(raw));
        } catch (err) {}
    });

    // Date / location filter drawer
    var openBtn = document.getElementById('activity-filter-open');
    var closeBtn = document.getElementById('activity-filter-close');
    var drawer = document.getElementById('activity-filter-drawer');
    var backdrop = document.getElementById('activity-filter-backdrop');
    var form = document.getElementById('activity-filter-form');
    var fromInput = document.getElementById('activity-from');
    var toInput = document.getElementById('activity-to');

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
    if (form) {
        form.querySelectorAll('input[data-preset-days]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked || !fromInput || !toInput) return;
                var d = parseInt(radio.getAttribute('data-preset-days'), 10) || 90;
                var to = new Date();
                var from = new Date();
                from.setDate(from.getDate() - d);
                function fmt(dt) {
                    var m = String(dt.getMonth() + 1).padStart(2, '0');
                    var day = String(dt.getDate()).padStart(2, '0');
                    return dt.getFullYear() + '-' + m + '-' + day;
                }
                toInput.value = fmt(to);
                fromInput.value = fmt(from);
            });
        });
        [fromInput, toInput].forEach(function (el) {
            if (!el) return;
            el.addEventListener('change', function () {
                form.querySelectorAll('input[data-preset-days]').forEach(function (r) { r.checked = false; });
            });
        });
    }

    // Loading overlay when switching tabs / applying filters
    var loadingEl = null;
    function showKycActivityLoading(msg) {
        var el = loadingEl || document.getElementById('kyc-activity-loading');
        if (!el) {
            el = document.createElement('div');
            el.id = 'kyc-activity-loading';
            el.className = 'profiles-fetch-loading';
            el.innerHTML = '<div class="profiles-list-loading-inner">' +
                '<div class="profiles-list-spinner" aria-hidden="true"></div>' +
                '<div class="profiles-list-loading-text">Loading KYC activity…</div>' +
                '<div class="profiles-list-loading-sub">Please wait</div>' +
                '</div>';
            document.body.appendChild(el);
            loadingEl = el;
        }
        var textEl = el.querySelector('.profiles-list-loading-text');
        if (textEl && msg) textEl.textContent = msg;
        el.hidden = false;
        el.removeAttribute('hidden');
        el.classList.add('is-on');
        el.setAttribute('aria-busy', 'true');
        document.body.classList.add('profiles-is-loading');
        var tabs = document.getElementById('kyc-activity-tabs');
        if (tabs) {
            tabs.classList.add('is-loading');
            tabs.setAttribute('aria-busy', 'true');
        }
        void el.offsetWidth;
    }

    if (form) {
        form.addEventListener('submit', function () {
            showKycActivityLoading('Applying filters…');
        });
    }

    document.addEventListener('click', function (e) {
        var tabLink = e.target.closest('#kyc-activity-tabs a[data-kyc-activity-loading]');
        var link = tabLink || e.target.closest('a[data-kyc-activity-loading]');
        if (!link) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        if (tabLink) {
            if (tabLink.classList.contains('is-active')) {
                e.preventDefault();
                return;
            }
            var key = (tabLink.getAttribute('data-kyc-tab') || '').toLowerCase();
            var msgMap = {
                all: 'Loading all KYC…',
                pending: 'Loading pending KYC…',
                pass: 'Loading complete KYC…',
                fail: 'Loading failed KYC…'
            };
            showKycActivityLoading(msgMap[key] || 'Loading KYC activity…');
            return;
        }
        showKycActivityLoading('Loading KYC activity…');
    }, true);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
