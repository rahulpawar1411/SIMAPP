<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\AssetTag;
use App\Models\FreezerLifecycle;
use App\Config\Database;

AuthService::requireLogin();
$currentUser = AuthService::currentUser();
$stats = Freezer::getDashboardStats();

$tagStats = ['unused' => 0, 'assigned' => 0, 'total' => 0];
try {
    AssetTag::ensureTable();
    $tagStats = AssetTag::getStats();
} catch (Throwable $e) {
    // table may not exist yet
}

$kycDone = 0;
$kycPending = 0;
try {
    $cycle = FreezerLifecycle::currentCycle();
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT freezer_id) FROM kyc_verifications WHERE cycle_year = ? AND cycle_half = ?");
    $stmt->execute([(int)$cycle['year'], (int)$cycle['half']]);
    $kycDone = (int)$stmt->fetchColumn();
    $kycPending = max(0, (int)$stats['total_freezers'] - $kycDone);
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
    <div class="stat-card dash-kpi" style="--stat-accent:#2563eb; --stat-bg:#eff6ff; --stat-color:#2563eb;">
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

    <div class="stat-card dash-kpi" style="--stat-accent:#0ea5e9; --stat-bg:#e0f2fe; --stat-color:#0284c7;">
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
    <div class="card dash-panel">
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

    <div class="card dash-panel">
        <div class="card-header">
            <div class="card-title"><span>QR batch management</span></div>
            <a href="blank_qr.php" class="btn btn-sm btn-primary">Manage</a>
        </div>
        <div class="card-body">
            <div class="dash-qr-stats">
                <div>
                    <span>Unused</span>
                    <strong><?= number_format($tagStats['unused'] ?? 0) ?></strong>
                </div>
                <div>
                    <span>Assigned</span>
                    <strong><?= number_format($tagStats['assigned'] ?? 0) ?></strong>
                </div>
                <div>
                    <span>Total tags</span>
                    <strong><?= number_format($tagStats['total'] ?? 0) ?></strong>
                </div>
            </div>
            <div class="dash-quick-actions">
                <a href="blank_qr.php" class="btn btn-secondary btn-sm">Generate batch</a>
                <a href="blank_qr_print.php?status=unused&limit=20" class="btn btn-outline btn-sm" target="_blank">Print 20</a>
                <a href="qr_scanner.php" class="btn btn-primary btn-sm">Scan QR</a>
            </div>
            <?php if (AppConfig::IOT_ENABLED): ?>
                <p style="font-size:0.8rem; color:#64748b; margin-top:0.85rem;">IoT sensors: <?= number_format($stats['total_iot']) ?> connected</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
