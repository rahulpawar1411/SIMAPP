<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;

AuthService::requireLogin();
$currentUser = AuthService::currentUser();
$stats = Freezer::getDashboardStats();

$pageTitle = 'Operations Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Welcome Banner -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 800; color: #0f172a; letter-spacing: -0.02em;">
            Welcome back, <?= htmlspecialchars($currentUser['name']) ?>!
        </h2>
        <p style="color: #64748b; font-size: 0.9rem; margin-top: 0.2rem;">
            Real-time cold-chain asset tracking and store profiles under ReeferON RaaS infrastructure.
        </p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <a href="qr_scanner.php" class="btn btn-secondary">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/>
                <rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span>Scan QR Tag</span>
        </a>
        <a href="profile_add.php" class="btn btn-primary">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="16"/>
                <line x1="8" y1="12" x2="16" y2="12"/>
            </svg>
            <span>+ Create Store &amp; Freezer</span>
        </a>
    </div>
</div>

<!-- KPI Stats Grid -->
<div class="stat-grid">
    <div class="stat-card" style="--stat-accent: #0284c7; --stat-bg: #e0f2fe; --stat-color: #0284c7;">
        <div class="stat-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="7" width="20" height="14" rx="2"/>
                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
            </svg>
        </div>
        <div class="stat-info">
            <h3>Total Freezers</h3>
            <div class="stat-value"><?= number_format($stats['total_freezers']) ?></div>
        </div>
    </div>

    <div class="stat-card" style="--stat-accent: #10b981; --stat-bg: #d1fae5; --stat-color: #10b981;">
        <div class="stat-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                <circle cx="12" cy="10" r="3"/>
            </svg>
        </div>
        <div class="stat-info">
            <h3>Registered Stores</h3>
            <div class="stat-value"><?= number_format($stats['total_stores']) ?></div>
        </div>
    </div>

    <div class="stat-card" style="--stat-accent: #6366f1; --stat-bg: #ede9fe; --stat-color: #6366f1;">
        <div class="stat-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>
        <div class="stat-info">
            <h3>Active Operational</h3>
            <div class="stat-value"><?= number_format($stats['active_units']) ?></div>
        </div>
    </div>

    <?php if (AppConfig::IOT_ENABLED): ?>
    <div class="stat-card" style="--stat-accent: #06b6d4; --stat-bg: #cffafe; --stat-color: #06b6d4;">
        <div class="stat-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
                <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
                <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
                <line x1="12" y1="20" x2="12.01" y2="20"/>
            </svg>
        </div>
        <div class="stat-info">
            <h3>IoT Sensor Connected</h3>
            <div class="stat-value"><?= number_format($stats['total_iot']) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Quick ID Format Guide -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header">
        <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="16" x2="12" y2="12"/>
                <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <span>Unique ID Standard</span>
        </div>
    </div>
    <div class="card-body" style="font-size: 0.85rem; color: #475569;">
        <p style="margin-bottom: 0.75rem;">Standard format compliant with ReeferON specifications:</p>
        <div style="background: #0f172a; color: #38bdf8; font-family: var(--font-mono); font-size: 0.8rem; padding: 0.65rem; border-radius: 6px; word-break: break-all; margin-bottom: 0.75rem;">
            DF-RW-SFR550DDU-AMD1-0324-380058-AAA004
        </div>
        <ul style="padding-left: 1.25rem; font-size: 0.775rem; display: flex; flex-direction: column; gap: 0.25rem; color: #64748b;">
            <li><strong>DF</strong>: Freezer Type (DF/DCF/UDF/FRZ)</li>
            <li><strong>RW</strong>: Brand / OEM Code (Rockwell)</li>
            <li><strong>SFR550DDU</strong>: Model Number</li>
            <li><strong>AMD1</strong>: Location Code (Ahmedabad-1)</li>
            <li><strong>380058</strong>: Full 6-digit PIN code</li>
            <li><strong>0324</strong>: Installation Month/Year (MMYY)</li>
            <li><strong>AAA004</strong>: Auto Unique Sequence</li>
        </ul>
    </div>
</div>

<!-- Recent Deployments Card -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <span>Recently Profiled Freezers</span>
        </div>
        <a href="profiles.php" class="btn btn-sm btn-outline">View All Profiles &rarr;</a>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Unique Freezer ID</th>
                    <th>Store / Outlet</th>
                    <th class="hide-mobile">Type & Brand</th>
                    <th class="hide-mobile">Location / City</th>
                    <th class="hide-mobile">Status</th>
                    <th class="hide-mobile">QR Tag</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stats['recent'])): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 2rem; color: #94a3b8;">
                            No freezer profiles found. Click <strong>+ Add New Profile</strong> to register your first freezer!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stats['recent'] as $row): ?>
                        <tr>
                            <td>
                                <a href="profile_view.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="table-id-link">
                                    <?= htmlspecialchars($row['unique_freezer_id']) ?>
                                </a>
                                <div style="font-size: 0.75rem; color: #94a3b8;">Model: <?= htmlspecialchars($row['model_number']) ?></div>
                            </td>
                            <td>
                                <strong style="color: #0f172a;"><?= htmlspecialchars($row['store_name']) ?></strong>
                                <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($row['store_city']) ?></div>
                            </td>
                            <td class="hide-mobile">
                                <span class="badge badge-primary"><?= htmlspecialchars($row['freezer_type']) ?></span>
                                <span style="font-size: 0.8rem; font-weight: 600; color: #475569; margin-left: 0.25rem;"><?= htmlspecialchars($row['company_code']) ?></span>
                            </td>
                            <td class="hide-mobile">
                                <div style="font-size: 0.85rem; font-weight: 600;"><?= htmlspecialchars($row['location_code']) ?></div>
                                <div style="font-size: 0.725rem; color: #94a3b8;">PIN: <?= htmlspecialchars($row['pincode_last3']) ?></div>
                            </td>
                            <td class="hide-mobile">
                                <?php if ($row['status'] === 'Active'): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><?= htmlspecialchars($row['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="hide-mobile">
                                <?php if (!empty($row['qr_code_path']) && file_exists(__DIR__ . '/' . $row['qr_code_path'])): ?>
                                    <a href="qr_print.php?id=<?= urlencode($row['unique_freezer_id']) ?>" title="Print Asset Tag">
                                        <img src="<?= htmlspecialchars($row['qr_code_path']) ?>" alt="QR" style="width: 38px; height: 38px; border: 1px solid var(--border-color); border-radius: 4px; padding: 2px;">
                                    </a>
                                <?php else: ?>
                                    <span style="font-size: 0.75rem; color: #94a3b8;">Auto QR</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="profile_view.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="btn btn-sm btn-secondary" title="View Full Profile">View</a>
                                <a href="profile_edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline hide-mobile" title="Edit Profile">Edit</a>
                                <a href="qr_print.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="btn btn-sm btn-outline hide-mobile" title="Print QR Sticker" target="_blank">Print</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
