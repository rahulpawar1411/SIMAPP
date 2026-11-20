<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\KycScanGate;
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
$amcStats = [
    'done' => 0,
    'pending' => 0,
    'pending_list' => [],
    'gap_months' => (int)FreezerLifecycle::KYC_GAP_MONTHS,
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
try {
    $amcStats = FreezerLifecycle::dashboardKycStats(25, $dashFilters);
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'dashboard.amc_due');
}
$amcPendingList = is_array($amcStats['pending_list'] ?? null) ? $amcStats['pending_list'] : [];
$amcPendingCount = (int)($amcStats['pending'] ?? 0);
$amcLeadMonths = (int)($amcStats['alert_lead_months'] ?? FreezerLifecycle::KYC_ALERT_LEAD_MONTHS);
$amcGapMonths = (int)($amcStats['gap_months'] ?? FreezerLifecycle::KYC_GAP_MONTHS);

$hasDashFilter = ($filterZone !== '' || $filterCity !== '');

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
            <p class="adash-welcome-sub">Overview of freezers and outlets.</p>
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
        <a class="adash-kpi adash-kpi-green" href="profiles.php">
            <div class="adash-kpi-icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <div class="adash-kpi-body">
                <span class="adash-kpi-label">Active Outlets</span>
                <strong class="adash-kpi-value"><?= number_format($stats['total_stores']) ?></strong>
                <span class="adash-kpi-meta up">Linked stores</span>
            </div>
        </a>
        <a class="adash-kpi adash-kpi-orange" href="profiles.php?kyc=pending">
            <div class="adash-kpi-icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="adash-kpi-body">
                <span class="adash-kpi-label">AMC due</span>
                <strong class="adash-kpi-value"><?= number_format($amcPendingCount) ?></strong>
                <span class="adash-kpi-meta <?= $amcPendingCount > 0 ? 'down' : 'up' ?>">
                    Alert <?= (int)$amcLeadMonths ?> mo early · every <?= (int)$amcGapMonths ?> mo · <?= number_format((int)($amcStats['done'] ?? 0)) ?> OK
                </span>
            </div>
        </a>
        <a class="adash-kpi adash-kpi-red" href="notifications.php">
            <div class="adash-kpi-icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </div>
            <div class="adash-kpi-body">
                <span class="adash-kpi-label">Approvals</span>
                <strong class="adash-kpi-value">Open</strong>
                <span class="adash-kpi-meta">Edit requests</span>
            </div>
        </a>
    </div>

    <?php if ($amcPendingCount > 0 && !empty($amcPendingList)): ?>
    <div class="adash-card adash-amc-card" id="amc-due-list">
        <div class="adash-card-head">
            <h2>AMC list — nearby due</h2>
            <div class="adash-amc-head-meta">
                <span class="adash-chip"><?= number_format($amcPendingCount) ?> alert<?= $amcPendingCount === 1 ? '' : 's' ?></span>
                <a href="profiles.php?kyc=pending" class="adash-link">Open in Assets</a>
            </div>
        </div>
        <p class="adash-amc-hint">
            Shows only when due is within <?= (int)$amcLeadMonths ?> month · cycle every <?= (int)$amcGapMonths ?> months
            <?php if ($hasDashFilter): ?>
                · filtered by zone/city
            <?php endif; ?>
        </p>
        <div class="adash-amc-table-wrap">
            <table class="adash-amc-table">
                <thead>
                    <tr>
                        <th>Freezer ID</th>
                        <th>Store</th>
                        <th>City</th>
                        <th>Zone</th>
                        <th>Due date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($amcPendingList as $dueRow):
                        $dueFid = (int)($dueRow['id'] ?? 0);
                        $dueUid = (string)($dueRow['unique_freezer_id'] ?? '');
                        $dueStore = trim((string)($dueRow['store_name'] ?? ''));
                        $dueCity = trim((string)($dueRow['city'] ?? ''));
                        $dueZone = strtoupper(trim((string)($dueRow['zone'] ?? '')));
                        $dueWhen = (string)($dueRow['next_kyc_due'] ?? '');
                        $dueWhenLabel = ($dueWhen !== '' && strtotime($dueWhen)) ? date('d M Y', strtotime($dueWhen)) : ($dueWhen !== '' ? $dueWhen : '—');
                        $isOverdueNow = ($dueWhen !== '' && strtotime($dueWhen) !== false && strtotime($dueWhen) <= strtotime(date('Y-m-d')));
                        $statusLabel = $isOverdueNow ? 'Overdue' : 'Due soon';
                        $statusClass = $isOverdueNow ? 'adash-pill-issue' : 'adash-pill-pending';
                        $profileHref = 'profile_view.php?id=' . urlencode($dueUid !== '' ? $dueUid : (string)$dueFid) . '&tab=amc';
                        $amcHref = KycScanGate::profileKycUrl([
                            'id' => $dueFid,
                            'unique_freezer_id' => $dueUid,
                        ]);
                        ?>
                        <tr>
                            <td>
                                <a class="adash-amc-id" href="<?= htmlspecialchars($profileHref) ?>">
                                    <?= htmlspecialchars($dueUid !== '' ? $dueUid : ('#' . $dueFid)) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($dueStore !== '' ? $dueStore : '—') ?></td>
                            <td><?= htmlspecialchars($dueCity !== '' ? $dueCity : '—') ?></td>
                            <td><?= htmlspecialchars($dueZone !== '' ? $dueZone : '—') ?></td>
                            <td><?= htmlspecialchars($dueWhenLabel) ?></td>
                            <td><span class="adash-pill <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                            <td class="adash-amc-actions">
                                <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($amcHref) ?>">Do AMC</a>
                                <a class="btn btn-sm btn-outline" href="<?= htmlspecialchars($profileHref) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($amcPendingCount > count($amcPendingList)): ?>
            <p class="adash-amc-more">
                Showing <?= count($amcPendingList) ?> of <?= number_format($amcPendingCount) ?>.
                <a href="profiles.php?kyc=pending">See full AMC list in Assets</a>
            </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="adash-lower-grid adash-lower-grid-2">
        <div class="adash-card adash-activity-card">
            <div class="adash-card-head">
                <h2>Recent Activities</h2>
                <a href="profiles.php" class="adash-link">Assets</a>
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
