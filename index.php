<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;

AuthService::requireLogin();
$currentUser = AuthService::currentUser();
$stats = Freezer::getDashboardStats();

$cycle = FreezerLifecycle::currentCycle();
$kycDone = 0;
$kycPending = 0;
$kycPendingList = [];
$kycGapMonths = FreezerLifecycle::KYC_GAP_MONTHS;
try {
    $kycDash = FreezerLifecycle::dashboardKycStats(5);
    $kycDone = (int)$kycDash['done'];
    $kycPending = (int)$kycDash['pending'];
    $kycPendingList = $kycDash['pending_list'] ?? [];
} catch (Throwable $e) {
    $kycPending = (int)$stats['total_freezers'];
}

$pageTitle = 'Operations Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="dash-hero">
    <div>
        <div class="dash-greeting">Welcome back,</div>
        <h2 class="dash-title"><?= htmlspecialchars($currentUser['name']) ?></h2>
        <p class="dash-sub">Print blank QR → scan → register freezer → KYC verify on site.</p>
    </div>
    <a href="qr_scanner.php" class="btn btn-primary dash-scan-btn">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="7" height="7"/>
            <rect x="14" y="3" width="7" height="7"/>
            <rect x="14" y="14" width="7" height="7"/>
            <rect x="3" y="14" width="7" height="7"/>
        </svg>
        <span>Scan QR Code</span>
    </a>
</div>

<div class="stat-grid dash-kpi-grid">
    <div class="stat-card dash-kpi" style="--stat-accent:#16a34a; --stat-bg:#f0fdf4; --stat-color:#16a34a;">
        <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="7" width="20" height="14" rx="2"/>
                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
            </svg>
        </div>
        <div class="stat-info">
            <h3>Total Freezers</h3>
            <div class="stat-value"><?= number_format($stats['total_freezers']) ?></div>
        </div>
    </div>

    <div class="stat-card dash-kpi" style="--stat-accent:#22c55e; --stat-bg:#dcfce7; --stat-color:#15803d;">
        <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                <circle cx="12" cy="10" r="3"/>
            </svg>
        </div>
        <div class="stat-info">
            <h3>Active Outlets</h3>
            <div class="stat-value"><?= number_format($stats['total_stores']) ?></div>
        </div>
    </div>

    <div class="stat-card dash-kpi" style="--stat-accent:#22c55e; --stat-bg:#dcfce7; --stat-color:#16a34a;">
        <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>
        <div class="stat-info">
            <h3>KYC Completed</h3>
            <div class="stat-value"><?= number_format($kycDone) ?></div>
        </div>
    </div>

    <div class="stat-card dash-kpi" style="--stat-accent:#f59e0b; --stat-bg:#fef3c7; --stat-color:#d97706;">
        <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
        </div>
        <div class="stat-info">
            <h3>KYC Pending</h3>
            <div class="stat-value"><?= number_format($kycPending) ?></div>
        </div>
    </div>
</div>

<div class="dash-grid">
    <div class="card dash-panel dash-panel-recent">
        <div class="card-header">
            <div class="card-title"><span>Recent activity</span></div>
            <a href="profiles.php" class="btn btn-sm btn-outline">All assets</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($stats['recent'])): ?>
                <p style="padding:1.25rem; color:#94a3b8; text-align:center;">No registrations yet. Scan a blank QR to start.</p>
            <?php else: ?>
                <ul class="dash-activity">
                    <?php foreach ($stats['recent'] as $row): ?>
                        <li>
                            <div class="dash-activity-icon">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="2" y="7" width="20" height="14" rx="2"/>
                                </svg>
                            </div>
                            <div class="dash-activity-body">
                                <a href="profile_view.php?id=<?= urlencode($row['unique_freezer_id']) ?>">
                                    <?= htmlspecialchars($row['store_name']) ?>
                                </a>
                                <div class="dash-activity-meta">
                                    <code><?= htmlspecialchars($row['unique_freezer_id']) ?></code>
                                    · <?= htmlspecialchars($row['store_city'] ?? '') ?>
                                </div>
                            </div>
                            <span class="badge <?= ($row['status'] ?? '') === 'Active' ? 'badge-success' : 'badge-warning' ?>">
                                <?= htmlspecialchars($row['status'] ?? '—') ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card dash-panel dash-kyc-panel <?= $kycPending > 0 ? 'is-due' : 'is-ok' ?>">
        <div class="card-header">
            <div class="card-title"><span>KYC status</span></div>
            <?php if ($kycPending > 0): ?>
                <span class="badge badge-warning"><?= number_format($kycPending) ?> due</span>
            <?php else: ?>
                <span class="badge badge-success">All OK</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p class="dash-kyc-panel-text">
                Every <strong><?= (int)$kycGapMonths ?> months</strong> from installation / last KYC.
            </p>
            <div class="dash-qr-stats dash-kyc-stats">
                <div>
                    <span>Within window</span>
                    <strong><?= number_format($kycDone) ?></strong>
                </div>
                <div>
                    <span>Pending</span>
                    <strong><?= number_format($kycPending) ?></strong>
                </div>
            </div>
            <?php if ($kycPending > 0 && !empty($kycPendingList)): ?>
                <ul class="dash-kyc-panel-list">
                    <?php foreach ($kycPendingList as $row): ?>
                        <li>
                            <a href="<?= htmlspecialchars(\App\Services\KycScanGate::scannerUrl($row)) ?>">
                                <?= htmlspecialchars($row['store_name'] ?? 'Store') ?>
                            </a>
                            <code><?= htmlspecialchars($row['unique_freezer_id']) ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($kycPending > count($kycPendingList)): ?>
                    <p class="dash-kyc-panel-more">+<?= number_format($kycPending - count($kycPendingList)) ?> more</p>
                <?php endif; ?>
            <?php elseif ((int)$stats['total_freezers'] > 0 && $kycPending === 0): ?>
                <p class="dash-kyc-panel-ok">All units are within the KYC window.</p>
            <?php else: ?>
                <p class="dash-kyc-panel-ok">No freezers registered yet.</p>
            <?php endif; ?>
            <div class="dash-quick-actions" style="margin-top:0.85rem;">
                <a href="profiles.php?kyc=pending" class="btn btn-sm <?= $kycPending > 0 ? 'btn-primary' : 'btn-outline' ?>">View pending</a>
                <a href="qr_scanner.php" class="btn btn-sm btn-secondary">Scan &amp; verify</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
