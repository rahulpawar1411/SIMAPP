<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;

AuthService::requireLogin();
$currentUser = AuthService::currentUser();
// Dashboard is Super Admin only — technician → Home, others → Assets
if (($currentUser['role'] ?? '') !== AppConfig::ROLE_SUPER_ADMIN) {
    if (($currentUser['role'] ?? '') === AppConfig::ROLE_TECHNICIAN) {
        header('Location: field_home.php');
    } else {
        header('Location: profiles.php');
    }
    exit;
}

$filterZone = strtoupper(trim((string)($_GET['zone'] ?? '')));
$filterCity = trim((string)($_GET['city'] ?? ''));
if ($filterZone === 'ALL') {
    $filterZone = '';
}
if (strcasecmp($filterCity, 'All') === 0) {
    $filterCity = '';
}
$dashFilters = [
    'zone' => $filterZone,
    'city' => $filterCity,
];
$zoneOptions = [];
$cityOptions = [];
$dashLoadError = null;
try {
    $zoneOptions = Freezer::dashboardZones();
    $cityOptions = Freezer::dashboardCities($filterZone !== '' ? $filterZone : null);
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'dashboard.filters');
}
if ($filterCity !== '' && !in_array($filterCity, $cityOptions, true)) {
    $filterCity = '';
    $dashFilters['city'] = '';
}

$stats = [
    'total_freezers' => 0,
    'active_stores' => 0,
    'by_zone' => [],
    'top_stores' => [],
    'activities' => [],
];
try {
    $stats = Freezer::getDashboardStats($dashFilters);
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'dashboard.stats');
    $dashLoadError = \App\Services\AppLogger::friendlyMessage(
        $e,
        'Could not load dashboard numbers. Please refresh the page.'
    );
}

$kycDone = 0;
$kycPending = 0;
$kycPendingList = [];
$kycGapMonths = FreezerLifecycle::KYC_GAP_MONTHS;
try {
    $kycDash = FreezerLifecycle::dashboardKycStats(10, $dashFilters);
    $kycDone = (int)$kycDash['done'];
    $kycPending = (int)$kycDash['pending'];
    $kycPendingList = $kycDash['pending_list'] ?? [];
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'dashboard.kyc_stats');
    $kycPending = (int)$stats['total_freezers'];
}
$hasDashFilter = ($filterZone !== '' || $filterCity !== '');

$kycFailedCount = 0;
try {
    $failFilters = ['kyc' => ['failed']];
    if ($filterCity !== '') {
        $failFilters['city'] = [$filterCity];
    } elseif ($filterZone !== '' && !empty($cityOptions)) {
        $failFilters['city'] = $cityOptions;
    }
    $kycFailedCount = Freezer::countFiltered($failFilters);
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'dashboard.kyc_failed');
    $kycFailedCount = 0;
}

$byZone = $stats['by_zone'] ?? [];
$topStores = $stats['top_stores'] ?? [];
$activities = $stats['activities'] ?? [];

$zoneColors = ['#2563eb', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'];
// Always show all zones for clear comparison (even if count is 0)
$zoneOrder = ['SOUTH', 'NORTH', 'WEST', 'EAST'];
$zoneCountMap = [];
foreach ($byZone as $zRow) {
    $zk = strtoupper(trim((string)($zRow['zone'] ?? 'OTHER')));
    $zoneCountMap[$zk] = (int)($zRow['cnt'] ?? 0);
}
$byZoneCompare = [];
foreach ($zoneOrder as $zk) {
    $byZoneCompare[] = ['zone' => $zk, 'cnt' => (int)($zoneCountMap[$zk] ?? 0)];
    unset($zoneCountMap[$zk]);
}
foreach ($zoneCountMap as $zk => $cnt) {
    $byZoneCompare[] = ['zone' => $zk, 'cnt' => (int)$cnt];
}
$zoneMax = 0;
$zoneTotalCompare = 0;
foreach ($byZoneCompare as $zRow) {
    $zoneTotalCompare += (int)$zRow['cnt'];
    if ((int)$zRow['cnt'] > $zoneMax) {
        $zoneMax = (int)$zRow['cnt'];
    }
}

$pageTitle = 'Dashboard';
$bodyClass = 'admin-dash-page';
$adminDashLayout = true;
require_once __DIR__ . '/includes/header.php';
?>

<?php if (!empty($dashLoadError)): ?>
<div class="alert alert-danger" role="alert" style="margin:0.75rem 1rem 0;">
    <?= htmlspecialchars($dashLoadError) ?>
</div>
<?php endif; ?>

<div class="adash">
    <div class="adash-welcome">
        <div>
            <h1 class="adash-welcome-title">Welcome, <?= htmlspecialchars($currentUser['name'] ?? 'Admin') ?>!</h1>
            <p class="adash-welcome-sub">Overview of freezers, outlets, and KYC verification status.</p>
        </div>
        <form method="GET" action="index.php" class="adash-filters" id="dash-filter-form">
            <select id="dash-zone" name="zone" class="adash-select" onchange="document.getElementById('dash-city').value=''; this.form.submit();" aria-label="Zone">
                <option value="">All zones</option>
                <?php foreach ($zoneOptions as $z): ?>
                    <option value="<?= htmlspecialchars($z) ?>" <?= $filterZone === $z ? 'selected' : '' ?>><?= htmlspecialchars($z) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="dash-city" name="city" class="adash-select" onchange="this.form.submit()" aria-label="City">
                <option value="">All cities</option>
                <?php foreach ($cityOptions as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $filterCity === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($hasDashFilter): ?>
                <a href="index.php" class="adash-clear">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="adash-kpi-grid">
        <a class="adash-kpi adash-kpi-blue" href="profiles.php">
            <div class="adash-kpi-icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <div class="adash-kpi-body">
                <span class="adash-kpi-label">Total Freezers</span>
                <strong class="adash-kpi-value"><?= number_format($stats['total_freezers']) ?></strong>
                <span class="adash-kpi-meta"><?= number_format($stats['total_stores']) ?> active outlets</span>
            </div>
        </a>
        <a class="adash-kpi adash-kpi-green" href="profiles.php?kyc[]=done">
            <div class="adash-kpi-icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div class="adash-kpi-body">
                <span class="adash-kpi-label">KYC Passed</span>
                <strong class="adash-kpi-value"><?= number_format($kycDone) ?></strong>
                <span class="adash-kpi-meta up">Within <?= (int)$kycGapMonths ?> mo window</span>
            </div>
        </a>
        <a class="adash-kpi adash-kpi-orange" href="profiles.php?kyc[]=pending">
            <div class="adash-kpi-icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="adash-kpi-body">
                <span class="adash-kpi-label">KYC Pending</span>
                <strong class="adash-kpi-value"><?= number_format($kycPending) ?></strong>
                <span class="adash-kpi-meta">Due for verification</span>
            </div>
        </a>
        <a class="adash-kpi adash-kpi-red" href="profiles.php?kyc[]=failed">
            <div class="adash-kpi-icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div class="adash-kpi-body">
                <span class="adash-kpi-label">KYC Issues</span>
                <strong class="adash-kpi-value"><?= number_format($kycFailedCount) ?></strong>
                <span class="adash-kpi-meta <?= $kycFailedCount > 0 ? 'down' : '' ?>">Failed verifications</span>
            </div>
        </a>
    </div>

    <div class="adash-lower-grid adash-lower-grid-3">
        <div class="adash-card">
            <div class="adash-card-head">
                <h2>Nearby KYC Due</h2>
                <a href="profiles.php?kyc[]=pending" class="adash-link">View all (<?= number_format($kycPending) ?>)</a>
            </div>
            <div class="adash-table-wrap">
                <table class="adash-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Freezer / Store</th>
                            <th>Location</th>
                            <th>Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($kycPendingList)): ?>
                            <tr><td colspan="4" class="adash-empty">No KYC due right now</td></tr>
                        <?php else: ?>
                            <?php foreach ($kycPendingList as $i => $row): ?>
                                <?php
                                $due = (string)($row['next_kyc_due'] ?? '');
                                $dueLabel = $due !== '' ? $due : '—';
                                $fid = (string)($row['unique_freezer_id'] ?? '');
                                ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td>
                                        <a href="profile_view.php?id=<?= urlencode($fid) ?>" class="adash-row-link">
                                            <strong><?= htmlspecialchars($fid !== '' ? $fid : '—') ?></strong>
                                        </a>
                                        <div class="adash-sub"><?= htmlspecialchars((string)($row['store_name'] ?? '—')) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string)($row['city'] ?? '—')) ?></td>
                                    <td>
                                        <span class="adash-pill adash-pill-pending"><?= htmlspecialchars($dueLabel) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="adash-card">
            <div class="adash-card-head">
                <h2>Recent Activities</h2>
                <a href="kyc_activity.php" class="adash-link">KYC Activity</a>
            </div>
            <ul class="adash-activity">
                <?php if (empty($activities)): ?>
                    <li class="adash-empty">No recent activity</li>
                <?php else: ?>
                    <?php foreach ($activities as $act): ?>
                        <li class="adash-activity-item adash-act-<?= htmlspecialchars($act['kind']) ?>">
                            <span class="adash-act-dot" aria-hidden="true"></span>
                            <div class="adash-act-body">
                                <div class="adash-act-top">
                                    <a href="<?= htmlspecialchars($act['href']) ?>"><?= htmlspecialchars($act['title']) ?></a>
                                    <span class="adash-pill adash-pill-<?= htmlspecialchars($act['kind']) ?>"><?= htmlspecialchars($act['badge']) ?></span>
                                </div>
                                <div class="adash-act-sub"><?= htmlspecialchars($act['subtitle'] !== '' ? $act['subtitle'] : ($act['meta'] ?: '—')) ?></div>
                                <div class="adash-act-when"><?= htmlspecialchars($act['when'] !== '' ? $act['when'] : '—') ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <div class="adash-card adash-zone-compare-card">
            <div class="adash-card-head">
                <h2>By Zone</h2>
                <span class="adash-chip"><?= number_format($zoneTotalCompare) ?> freezers</span>
            </div>
            <p class="adash-zone-compare-hint">Freezer count per zone — compare South / North / West / East</p>
            <ul class="adash-zone-compare">
                <?php foreach ($byZoneCompare as $i => $z): ?>
                    <?php
                    $cnt = (int)($z['cnt'] ?? 0);
                    $color = $zoneColors[$i % count($zoneColors)];
                    $pct = $zoneTotalCompare > 0 ? round(($cnt / $zoneTotalCompare) * 100) : 0;
                    $bar = $zoneMax > 0 ? round(($cnt / $zoneMax) * 100) : 0;
                    $zoneLabel = ucfirst(strtolower((string)($z['zone'] ?? 'Other')));
                    ?>
                    <li>
                        <div class="adash-zone-compare-top">
                            <span class="adash-zone-compare-name">
                                <span class="adash-zone-dot" style="background:<?= htmlspecialchars($color) ?>"></span>
                                <?= htmlspecialchars($zoneLabel) ?>
                            </span>
                            <strong class="adash-zone-compare-count"><?= number_format($cnt) ?></strong>
                        </div>
                        <div class="adash-zone-compare-track" aria-hidden="true">
                            <span class="adash-zone-compare-fill" style="width:<?= (int)$bar ?>%;background:<?= htmlspecialchars($color) ?>"></span>
                        </div>
                        <div class="adash-zone-compare-meta"><?= (int)$pct ?>% of total</div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
