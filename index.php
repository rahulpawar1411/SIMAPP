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
$trendPeriod = strtolower(trim((string)($_GET['trend'] ?? '6months')));
if (!in_array($trendPeriod, ['month', '6months', 'year', 'custom'], true)) {
    $trendPeriod = '6months';
}
$trendFrom = trim((string)($_GET['trend_from'] ?? ''));
$trendTo = trim((string)($_GET['trend_to'] ?? ''));
if ($trendPeriod === 'custom') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trendFrom)) {
        $trendFrom = date('Y-m-d', strtotime('-29 days'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trendTo)) {
        $trendTo = date('Y-m-d');
    }
}

$dashFilters = [
    'zone' => $filterZone,
    'city' => $filterCity,
    'trend_period' => $trendPeriod,
    'trend_from' => $trendFrom,
    'trend_to' => $trendTo,
];
$zoneOptions = Freezer::dashboardZones();
$cityOptions = Freezer::dashboardCities($filterZone !== '' ? $filterZone : null);
if ($filterCity !== '' && !in_array($filterCity, $cityOptions, true)) {
    $filterCity = '';
    $dashFilters['city'] = '';
}

$stats = Freezer::getDashboardStats($dashFilters);

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
    $kycFailedCount = 0;
}

$trend = $stats['trend'] ?? [];
$trendMeta = $stats['trend_meta'] ?? Freezer::resolveTrendRange($dashFilters);
$byZone = $stats['by_zone'] ?? [];
$topStores = $stats['top_stores'] ?? [];
$activities = $stats['activities'] ?? [];

$zoneColors = ['#2563eb', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'];
$trendPeriodLabels = [
    'month' => 'This month',
    '6months' => 'Last 6 months',
    'year' => 'Last 1 year',
    'custom' => 'Custom dates',
];

$pageTitle = 'Dashboard';
$bodyClass = 'admin-dash-page';
$adminDashLayout = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="adash">
    <div class="adash-welcome">
        <div>
            <h1 class="adash-welcome-title">Welcome, <?= htmlspecialchars($currentUser['name'] ?? 'Admin') ?>!</h1>
            <p class="adash-welcome-sub">Overview of freezers, outlets, and KYC verification status.</p>
        </div>
        <form method="GET" action="index.php" class="adash-filters" id="dash-filter-form">
            <input type="hidden" name="trend" id="dash-trend-keep" value="<?= htmlspecialchars($trendPeriod) ?>">
            <input type="hidden" name="trend_from" id="dash-trend-from-keep" value="<?= htmlspecialchars($trendFrom) ?>">
            <input type="hidden" name="trend_to" id="dash-trend-to-keep" value="<?= htmlspecialchars($trendTo) ?>">
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
                <a href="index.php?trend=<?= urlencode($trendPeriod) ?><?= $trendPeriod === 'custom' ? '&trend_from=' . urlencode($trendFrom) . '&trend_to=' . urlencode($trendTo) : '' ?>" class="adash-clear">Clear</a>
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

    <div class="adash-mid-grid">
        <div class="adash-card adash-chart-card">
            <div class="adash-card-head adash-trend-head">
                <div>
                    <h2>Installation Trends</h2>
                    <span class="adash-chip"><?= htmlspecialchars((string)($trendMeta['label'] ?? $trendPeriodLabels[$trendPeriod] ?? 'Last 6 months')) ?></span>
                </div>
                <form method="GET" action="index.php" class="adash-trend-filters" id="adash-trend-form">
                    <?php if ($filterZone !== ''): ?>
                        <input type="hidden" name="zone" value="<?= htmlspecialchars($filterZone) ?>">
                    <?php endif; ?>
                    <?php if ($filterCity !== ''): ?>
                        <input type="hidden" name="city" value="<?= htmlspecialchars($filterCity) ?>">
                    <?php endif; ?>
                    <select name="trend" id="adash-trend-period" class="adash-select adash-select-sm" aria-label="Trend period">
                        <option value="month" <?= $trendPeriod === 'month' ? 'selected' : '' ?>>Month</option>
                        <option value="6months" <?= $trendPeriod === '6months' ? 'selected' : '' ?>>6 months</option>
                        <option value="year" <?= $trendPeriod === 'year' ? 'selected' : '' ?>>1 year</option>
                        <option value="custom" <?= $trendPeriod === 'custom' ? 'selected' : '' ?>>Custom dates</option>
                    </select>
                    <div class="adash-trend-custom<?= $trendPeriod === 'custom' ? ' is-open' : '' ?>" id="adash-trend-custom">
                        <input type="date" name="trend_from" class="adash-date" <?= $trendPeriod !== 'custom' ? 'disabled' : '' ?> value="<?= htmlspecialchars($trendPeriod === 'custom' ? $trendFrom : ($trendMeta['from'] ?? '')) ?>" aria-label="From date">
                        <span class="adash-trend-sep">to</span>
                        <input type="date" name="trend_to" class="adash-date" <?= $trendPeriod !== 'custom' ? 'disabled' : '' ?> value="<?= htmlspecialchars($trendPeriod === 'custom' ? $trendTo : ($trendMeta['to'] ?? date('Y-m-d'))) ?>" aria-label="To date">
                        <button type="submit" class="adash-trend-apply">Apply</button>
                    </div>
                </form>
            </div>
            <div class="adash-chart-wrap">
                <canvas id="adash-trend-chart" height="220" aria-label="Installation trends chart"></canvas>
            </div>
            <div class="adash-legend">
                <span><i style="background:#2563eb"></i> Installed</span>
                <span><i style="background:#93c5fd"></i> Target</span>
            </div>
        </div>

        <div class="adash-card adash-donut-card">
            <div class="adash-card-head">
                <h2>By Zone</h2>
            </div>
            <div class="adash-donut-wrap">
                <canvas id="adash-zone-chart" width="180" height="180" aria-label="Zone distribution"></canvas>
                <div class="adash-donut-center">
                    <strong><?= number_format($stats['total_freezers']) ?></strong>
                    <span>Total</span>
                </div>
            </div>
            <ul class="adash-zone-list">
                <?php if (empty($byZone)): ?>
                    <li>No zone data yet</li>
                <?php else: ?>
                    <?php foreach ($byZone as $i => $z): ?>
                        <?php
                        $cnt = (int)($z['cnt'] ?? 0);
                        $color = $zoneColors[$i % count($zoneColors)];
                        ?>
                        <li>
                            <span class="adash-zone-dot" style="background:<?= htmlspecialchars($color) ?>"></span>
                            <span class="adash-zone-name"><?= htmlspecialchars((string)($z['zone'] ?? 'OTHER')) ?></span>
                            <strong><?= number_format($cnt) ?></strong>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="adash-lower-grid">
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
                <a href="notifications.php" class="adash-link">Activity</a>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    var trend = <?= json_encode(array_values($trend), JSON_UNESCAPED_UNICODE) ?>;
    var zones = <?= json_encode(array_values($byZone), JSON_UNESCAPED_UNICODE) ?>;
    var colors = <?= json_encode($zoneColors) ?>;
    var trendBucket = <?= json_encode((string)($trendMeta['bucket'] ?? 'month')) ?>;

    var periodSel = document.getElementById('adash-trend-period');
    var customBox = document.getElementById('adash-trend-custom');
    var trendForm = document.getElementById('adash-trend-form');
    if (periodSel && trendForm) {
        periodSel.addEventListener('change', function () {
            var isCustom = periodSel.value === 'custom';
            if (customBox) {
                customBox.classList.toggle('is-open', isCustom);
            }
            if (!isCustom) {
                trendForm.querySelectorAll('input[name="trend_from"], input[name="trend_to"]').forEach(function (el) {
                    el.disabled = true;
                });
                trendForm.submit();
            } else {
                trendForm.querySelectorAll('input[name="trend_from"], input[name="trend_to"]').forEach(function (el) {
                    el.disabled = false;
                });
            }
        });
    }

    if (window.Chart && document.getElementById('adash-trend-chart')) {
        var labels = trend.map(function (t) { return t.label; });
        var installed = trend.map(function (t) { return t.count || 0; });
        var target = trend.map(function (t) { return t.target || 0; });
        var dense = trendBucket === 'day' && labels.length > 14;
        new Chart(document.getElementById('adash-trend-chart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Installed',
                        data: installed,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,0.12)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: dense ? 2 : 4,
                        pointBackgroundColor: '#2563eb'
                    },
                    {
                        label: 'Target',
                        data: target,
                        borderColor: '#93c5fd',
                        borderDash: [6, 4],
                        fill: false,
                        tension: 0.35,
                        pointRadius: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { precision: 0 } },
                    x: {
                        grid: { display: false },
                        ticks: {
                            maxRotation: dense ? 45 : 0,
                            autoSkip: true,
                            maxTicksLimit: dense ? 10 : 12
                        }
                    }
                }
            }
        });
    }

    if (window.Chart && document.getElementById('adash-zone-chart')) {
        var zLabels = zones.map(function (z) { return z.zone || 'OTHER'; });
        var zData = zones.map(function (z) { return parseInt(z.cnt, 10) || 0; });
        if (!zData.length) {
            zLabels = ['No data'];
            zData = [1];
            colors = ['#e2e8f0'];
        }
        new Chart(document.getElementById('adash-zone-chart'), {
            type: 'doughnut',
            data: {
                labels: zLabels,
                datasets: [{
                    data: zData,
                    backgroundColor: colors.slice(0, zData.length),
                    borderWidth: 0,
                    cutout: '68%'
                }]
            },
            options: {
                responsive: false,
                plugins: { legend: { display: false }, tooltip: { enabled: zLabels[0] !== 'No data' } }
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
