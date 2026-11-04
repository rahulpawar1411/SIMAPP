<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Models\AssetTag;
use App\Models\IoTDevice;
use App\Services\QRCodeService;

AuthService::requireLogin();
$currentUser = AuthService::currentUser();
$csrfToken = AuthService::generateCsrfToken();
Freezer::ensureSimColumns();

$idParam = $_GET['id'] ?? null;
if (!$idParam) {
    header("Location: profiles.php");
    exit;
}

// Find freezer either by numeric ID or unique ID string
if (is_numeric($idParam)) {
    $freezer = Freezer::findById((int)$idParam);
} else {
    $freezer = Freezer::findByUniqueId($idParam);
}

if (!$freezer) {
    die("<h1>Profile Not Found</h1><p>The requested freezer profile could not be found.</p><p><a href='profiles.php'>Back to Profiles</a></p>");
}

// Generate QR Code if file doesn't exist
$qrPath = $freezer['qr_code_path'];
if (!$qrPath || !file_exists(__DIR__ . '/' . $qrPath)) {
    $qrGen = QRCodeService::generateAndSave($freezer['unique_freezer_id']);
    $qrPath = $qrGen['file_path'];
}

// Fetch IoT telemetry (when enabled)
$telemetryHistory = [];
$latestTelemetry = null;
if (AppConfig::IOT_ENABLED) {
    $telemetryHistory = IoTDevice::getRecentTelemetry($freezer['id'], 15);
    $latestTelemetry = IoTDevice::getLatestTelemetry($freezer['id']);
}

FreezerLifecycle::ensureOpenPlacement($freezer);
$placementHistory = FreezerLifecycle::placementHistory((int)$freezer['id']);
$kycStatus = FreezerLifecycle::kycStatusForFreezer((int)$freezer['id']);
$kycHistory = FreezerLifecycle::kycHistory((int)$freezer['id'], 50);
$lifecycleTimeline = FreezerLifecycle::buildTimeline((int)$freezer['id']);

// Tag inventory row for this freezer (Tag code / Status / Batch / Linked freezer / Created)
try {
    $profileAssetTag = AssetTag::ensureForFreezer(
        (int)$freezer['id'],
        isset($currentUser['id']) ? (int)$currentUser['id'] : null
    );
} catch (Throwable $e) {
    $profileAssetTag = AssetTag::findByFreezerId((int)$freezer['id']);
}
if ($profileAssetTag && !empty($profileAssetTag['qr_code_path']) && file_exists(__DIR__ . '/' . $profileAssetTag['qr_code_path'])) {
    $qrPath = $profileAssetTag['qr_code_path'];
}

// Sibling freezers at same store (Associated Freezers card)
$storeFreezers = [$freezer];
try {
    $pdoSf = \App\Config\Database::getConnection();
    Freezer::ensureSimColumns();
    $sfStmt = $pdoSf->prepare("SELECT f.id, f.unique_freezer_id, f.status, f.company_code, f.model_number, f.freezer_type
        FROM freezers f WHERE f.store_id = :sid AND f.deleted_at IS NULL ORDER BY f.id ASC");
    $sfStmt->execute(['sid' => (int)$freezer['store_id']]);
    $rows = $sfStmt->fetchAll();
    if ($rows) {
        $storeFreezers = $rows;
    }
} catch (Throwable $e) {
    $storeFreezers = [$freezer];
}

$viewPhone = trim((string)($freezer['phone_number'] ?? ''));
if ($viewPhone !== '' && !str_starts_with($viewPhone, '+')) {
    $d = preg_replace('/\D/', '', $viewPhone);
    if (strlen($d) === 10) {
        $viewPhone = '+91' . $d;
    }
}
$aadhar = preg_replace('/\D/', '', (string)($freezer['aadhar_number'] ?? ''));
$aadharDisplay = strlen($aadhar) === 12
    ? (substr($aadhar, 0, 4) . '-' . substr($aadhar, 4, 4) . '-' . substr($aadhar, 8, 4))
    : 'N/A';
$storeFrontOk = !empty($freezer['image_store_front']) && file_exists(__DIR__ . '/' . $freezer['image_store_front']);

$hasGps = !empty($freezer['store_latitude']) && !empty($freezer['store_longitude']);
$storeMapsLink = trim((string)($freezer['store_location_link'] ?? ''));
if ($storeMapsLink === '' && $hasGps) {
    $storeMapsLink = 'https://maps.google.com/?q=' . rawurlencode($freezer['store_latitude'] . ',' . $freezer['store_longitude']);
}
$storeAddressLine = trim(implode(', ', array_filter([
    trim((string)($freezer['store_address'] ?? '')),
    trim((string)($freezer['store_city'] ?? '')),
    trim((string)($freezer['store_state'] ?? '')),
])));
if (!empty($freezer['store_pincode'])) {
    $storeAddressLine = trim($storeAddressLine . ' - ' . $freezer['store_pincode'], ' -');
}
$valOrDash = static function ($v): string {
    $s = trim((string)($v ?? ''));
    return $s !== '' ? $s : '—';
};

$activeTab = $_GET['tab'] ?? 'overview';
if ($activeTab === 'documents') {
    $activeTab = 'overview'; // merged into Overview
}
if (!in_array($activeTab, ['overview', 'freezers', 'location', 'history'], true)) {
    $activeTab = 'overview';
}

$pageTitle = 'Store Profile: ' . ($freezer['outlet_id'] ?? $freezer['store_name']);
require_once __DIR__ . '/includes/header.php';
?>

<nav class="sp-breadcrumb" aria-label="Breadcrumb">
    <a href="index.php">Home</a>
    <span>/</span>
    <a href="profiles.php">Store Profile</a>
    <span>/</span>
    <strong><?= htmlspecialchars($freezer['outlet_id'] ?? '—') ?></strong>
</nav>

<div class="sp-actions field-actions-row">
    <a href="qr_scanner.php" class="btn btn-primary btn-sm">Scan QR</a>
    <a href="qr_print.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-outline btn-sm" target="_blank">Print QR</a>
    <a href="profile_edit.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
    <a href="freezer_kyc.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-sm <?= $kycStatus['due'] ? 'btn-primary' : 'btn-outline' ?>">
            KYC <?= $kycStatus['due'] ? '(due)' : 'verify' ?>
        </a>
        <?php if (AuthService::hasRole('super_admin')): ?>
        <a href="profile_delete.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-danger btn-sm" title="Password required">Delete</a>
        <?php endif; ?>
</div>

<?php if (!empty($_GET['created'])): ?>
    <div class="card" style="margin-bottom: 1.5rem; border: 1px solid #86efac; background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 55%);">
        <div class="card-body" style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: center;">
            <div style="background: #fff; padding: 0.75rem; border-radius: 12px; border: 2px solid #0f172a; box-shadow: var(--shadow-sm);">
                <?php if ($qrPath && file_exists(__DIR__ . '/' . $qrPath)): ?>
                    <img src="<?= htmlspecialchars($qrPath) ?>" alt="Generated QR" style="width: 160px; height: 160px; display: block;">
                <?php endif; ?>
            </div>
            <div style="flex: 1; min-width: 220px;">
                <div class="badge badge-success" style="margin-bottom: 0.5rem;">Profile Saved</div>
                <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-bottom: 0.35rem;">QR Code generated from filled data</h3>
                <p style="font-family: var(--font-mono); font-size: 0.9rem; font-weight: 700; color: #0284c7; word-break: break-all; margin-bottom: 0.75rem;">
                    <?= htmlspecialchars($freezer['unique_freezer_id']) ?>
                </p>
                <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 1rem;">
                    All store / freezer fields are saved. Print the QR image sticker now, or download it.
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 0.6rem;">
                    <a href="qr_print.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" target="_blank" class="btn btn-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 6 2 18 2 18 9"/>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                            <rect x="6" y="14" width="12" height="8"/>
                        </svg>
                        <span>Print QR Image</span>
                    </a>
                    <?php if ($qrPath): ?>
                        <a href="<?= htmlspecialchars($qrPath) ?>" download="<?= htmlspecialchars($freezer['unique_freezer_id']) ?>_QR.svg" class="btn btn-secondary">
                            Download QR
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['transferred'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;">
        Freezer transferred. Placement history updated. Unique Freezer ID unchanged.
        <?php if (!empty($_GET['new_outlet'])): ?>
            New store ID: <strong style="font-family:var(--font-mono);"><?= htmlspecialchars($_GET['new_outlet']) ?></strong>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if (!empty($_GET['kyc'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;">
        KYC verification saved for this cycle.
        <?php if (!empty($_GET['new_outlet'])): ?>
            New outlet created with Store ID: <strong style="font-family:var(--font-mono);"><?= htmlspecialchars($_GET['new_outlet']) ?></strong>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Store Profile hero (mockup) -->
<div class="sp-hero-grid">
    <div class="card sp-hero">
        <div class="sp-hero-media">
            <?php if ($storeFrontOk): ?>
                <img src="<?= htmlspecialchars($freezer['image_store_front']) ?>" alt="Store front" class="lightbox-trigger" data-caption="<?= htmlspecialchars($freezer['store_name']) ?>">
            <?php else: ?>
                <div class="sp-hero-placeholder">No store photo</div>
            <?php endif; ?>
                </div>
        <div class="sp-hero-body">
            <div class="sp-hero-top">
                <div>
                    <h2 class="sp-store-name"><?= htmlspecialchars($freezer['store_name']) ?></h2>
                    <div class="sp-outlet"><?= htmlspecialchars($freezer['outlet_id'] ?? '—') ?></div>
            </div>
                <?php if (($freezer['status'] ?? '') === 'Active'): ?>
                    <span class="badge badge-success">Active</span>
                    <?php else: ?>
                    <span class="badge badge-warning"><?= htmlspecialchars($freezer['status'] ?? '—') ?></span>
                    <?php endif; ?>
                </div>
            <div class="sp-contact-row">
                <div><span>Owner / Operator</span><strong><?= htmlspecialchars(($freezer['contact_person'] ?? '') !== '' ? $freezer['contact_person'] : 'N/A') ?></strong></div>
                <div><span>Phone</span><strong><?= htmlspecialchars($viewPhone !== '' ? $viewPhone : 'N/A') ?></strong></div>
                <div><span>Email</span><strong><?= htmlspecialchars(($freezer['store_email'] ?? '') !== '' ? $freezer['store_email'] : 'N/A') ?></strong></div>
                </div>
            <div class="sp-location-chips">
                <span><?= htmlspecialchars($freezer['store_zone'] ?? $freezer['zone'] ?? '—') ?></span>
                <span><?= htmlspecialchars($freezer['store_state'] ?? '—') ?></span>
                <span><?= htmlspecialchars($freezer['store_city'] ?? '—') ?></span>
                <span>PIN <?= htmlspecialchars($freezer['store_pincode'] ?? $freezer['pincode_last3'] ?? '—') ?></span>
            </div>
            <p class="sp-address"><?= htmlspecialchars($freezer['store_address'] ?? '') ?></p>
            <?php if ($hasGps): ?>
                <div class="sp-current-loc-chip">
                    <span class="sp-current-loc-label">Current location</span>
                    <a href="<?= htmlspecialchars($storeMapsLink) ?>" target="_blank" rel="noopener" class="sp-current-loc-link">
                        <?= htmlspecialchars(number_format((float)$freezer['store_latitude'], 6) . ', ' . number_format((float)$freezer['store_longitude'], 6)) ?>
                    </a>
                    <?php if (!empty($freezer['store_gps_captured_at'])): ?>
                        <span class="sp-current-loc-meta">Saved <?= htmlspecialchars((string)$freezer['store_gps_captured_at']) ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="sp-current-loc-chip is-empty">Current location not saved yet</div>
            <?php endif; ?>
            </div>
        </div>

    <div class="card sp-qr-card">
            <div class="card-header">
            <div class="card-title"><span>Asset QR</span></div>
                </div>
        <div class="card-body" style="text-align:center;">
            <div class="sp-qr-frame">
                <?php if ($qrPath && file_exists(__DIR__ . '/' . $qrPath)): ?>
                    <img src="<?= htmlspecialchars($qrPath) ?>" alt="QR Code">
                <?php else: ?>
                    <div class="sp-qr-missing">QR</div>
                <?php endif; ?>
            </div>
            <?php if ($profileAssetTag): ?>
                <div class="sp-qr-label">Tag code</div>
                <div class="sp-qr-code"><?= htmlspecialchars($profileAssetTag['tag_code']) ?></div>
            <?php endif; ?>
            <div class="sp-qr-label">Unique Freezer ID</div>
            <div class="sp-qr-fid"><?= htmlspecialchars($freezer['unique_freezer_id']) ?></div>
            <div class="sp-qr-actions">
                <a href="qr_print.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" target="_blank" class="btn btn-sm btn-primary">Print QR</a>
                <?php if ($qrPath): ?>
                    <a href="<?= htmlspecialchars($qrPath) ?>" download class="btn btn-sm btn-outline">Download QR</a>
                <?php endif; ?>
                    </div>
                </div>
                    </div>
                    </div>

<!-- Tabs -->
<div class="sp-tabs" role="tablist">
    <?php
    $tabs = [
        'overview' => 'Overview',
        'freezers' => 'Freezers (' . count($storeFreezers) . ')',
        'location' => 'Location',
        'history' => 'History',
    ];
    $baseUrl = 'profile_view.php?id=' . urlencode($freezer['unique_freezer_id']);
    foreach ($tabs as $key => $label):
    ?>
        <a href="<?= $baseUrl ?>&tab=<?= $key ?><?= $key === 'history' ? '#store-history' : '' ?>"
           class="sp-tab <?= $activeTab === $key ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
                    </div>

<?php if ($activeTab === 'overview'): ?>
<div class="sp-main-grid">
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span>Store details</span></div>
            <a href="profile_edit.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    </div>
        <div class="card-body">
            <div class="profile-kv-grid">
                <div class="profile-kv"><span class="profile-kv-label">Store name</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_name'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Outlet ID</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['outlet_id'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Customer</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['customer_name'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Distributor</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['distributor_name'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Contact person</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['contact_person'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Phone</span><strong class="profile-kv-value"><?= htmlspecialchars($viewPhone !== '' ? $viewPhone : '—') ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Email</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_email'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Location type</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['location_type'] ?? 'Retail Store')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Zone</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_zone'] ?? $freezer['zone'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">City</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_city'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">State</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_state'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">PIN</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['store_pincode'] ?? '')) ?></strong></div>
                <div class="profile-kv profile-kv-wide"><span class="profile-kv-label">Address</span><strong class="profile-kv-value"><?= htmlspecialchars($storeAddressLine !== '' ? $storeAddressLine : '—') ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Aadhaar</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($aadharDisplay) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">FSSAI</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['fssai_number'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">GST</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['gst_number'] ?? '')) ?></strong></div>
                </div>

            <div class="sp-current-loc-panel">
                <div class="sp-current-loc-panel-head">
                    <h4>Current location</h4>
                    <?php if ($hasGps): ?>
                        <span class="badge badge-success">Recorded</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Not set</span>
                    <?php endif; ?>
                </div>
                <?php if ($hasGps): ?>
                    <div class="profile-kv-grid">
                        <div class="profile-kv"><span class="profile-kv-label">Latitude</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars(number_format((float)$freezer['store_latitude'], 6)) ?></strong></div>
                        <div class="profile-kv"><span class="profile-kv-label">Longitude</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars(number_format((float)$freezer['store_longitude'], 6)) ?></strong></div>
                        <div class="profile-kv"><span class="profile-kv-label">Accuracy</span><strong class="profile-kv-value"><?= !empty($freezer['store_gps_accuracy']) ? ('± ' . htmlspecialchars((string)round((float)$freezer['store_gps_accuracy'])) . ' m') : '—' ?></strong></div>
                        <div class="profile-kv"><span class="profile-kv-label">Captured at</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_gps_captured_at'] ?? '')) ?></strong></div>
                        <div class="profile-kv profile-kv-wide">
                            <span class="profile-kv-label">Maps link</span>
                            <strong class="profile-kv-value">
                                <a href="<?= htmlspecialchars($storeMapsLink) ?>" target="_blank" rel="noopener" class="sp-maps-link"><?= htmlspecialchars($storeMapsLink) ?></a>
                            </strong>
            </div>
        </div>
                <?php else: ?>
                    <p class="sp-current-loc-empty">No current location saved. Use <strong>Save my current location</strong> on the map card.</p>
        <?php endif; ?>
    </div>
                </div>
            </div>

    <div class="sp-side-stack">
        <div class="card">
            <div class="card-header"><div class="card-title"><span>Store status &amp; KYC</span></div></div>
            <div class="card-body">
                <div class="sp-status-row">
                    <span>Store / unit status</span>
                    <?php if (($freezer['status'] ?? '') === 'Active'): ?>
                        <span class="badge badge-success">Active</span>
                    <?php else: ?>
                        <span class="badge badge-warning"><?= htmlspecialchars($freezer['status'] ?? '—') ?></span>
                    <?php endif; ?>
                    </div>
                <div class="sp-kyc-box">
                    <?php
                    $kycLastAtStore = $kycStatus['last_at_store'] ?? $kycStatus['current'] ?? null;
                    $kycLastDate = $kycLastAtStore['verified_at'] ?? ($kycStatus['last']['verified_at'] ?? null);
                    ?>
                    <div>
                        <span>KYC window</span>
                        <strong>
                            <?= $kycLastDate
                                ? 'Last KYC: ' . htmlspecialchars((string)$kycLastDate)
                                : 'Last KYC: —' ?>
                            <?php if (!empty($kycLastAtStore['result'])): ?>
                                · <?= htmlspecialchars((string)$kycLastAtStore['result']) ?>
                            <?php endif; ?>
                        </strong>
                    </div>
                    <div><span>Gap</span><strong>Every <?= (int)($kycStatus['gap_months'] ?? 6) ?> months</strong></div>
                    <?php if (!$kycLastDate && ($kycStatus['anchor_source'] ?? '') === 'installation' && !empty($kycStatus['anchor_date'])): ?>
                        <div><span>From install</span><strong><?= htmlspecialchars((string)$kycStatus['anchor_date']) ?></strong></div>
                    <?php endif; ?>
                    <div><span>Window ends / due</span>
                        <strong class="<?= !empty($kycStatus['due']) ? 'text-warn' : '' ?>">
                            <?php if (!empty($kycStatus['due'])): ?>
                                Due now
                                <?php if (!empty($kycStatus['next_due_at'])): ?>
                                    (<?= htmlspecialchars((string)$kycStatus['next_due_at']) ?>)
                                <?php endif; ?>
                            <?php else: ?>
                                <?= htmlspecialchars((string)($kycStatus['next_due_at'] ?? '—')) ?>
                            <?php endif; ?>
                        </strong>
                    </div>
                    </div>
                <a href="freezer_kyc.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-primary btn-sm" style="margin-top:0.75rem;"><?= !empty($kycStatus['due']) ? 'Complete KYC' : 'KYC verify' ?></a>
                    </div>
                    </div>

        <?php if ($profileAssetTag): ?>
        <div class="card tag-inv-profile">
            <div class="card-header"><div class="card-title"><span>Tag inventory</span></div></div>
            <div class="card-body">
                <div class="profile-kv-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="profile-kv"><span class="profile-kv-label">Tag code</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($profileAssetTag['tag_code']) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">Status</span>
                        <?php if (($profileAssetTag['status'] ?? '') === 'assigned'): ?>
                            <span class="badge badge-success">Assigned</span>
                        <?php else: ?>
                            <span class="badge badge-primary">Unused</span>
                        <?php endif; ?>
                    </div>
                    <div class="profile-kv profile-kv-wide hide-mobile"><span class="profile-kv-label">Batch</span><strong class="profile-kv-value"><?= htmlspecialchars($profileAssetTag['batch_label'] ?: '—') ?></strong></div>
                    <div class="profile-kv profile-kv-wide hide-mobile"><span class="profile-kv-label">Created</span><strong class="profile-kv-value"><?= htmlspecialchars($profileAssetTag['created_at'] ?? '—') ?></strong></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
            </div>
        </div>

<div class="sp-main-grid sp-main-grid-2">
        <div class="card">
        <div class="card-header"><div class="card-title"><span>Location on map</span></div>
            <div class="sp-map-actions-wrap">
                <div class="sp-map-actions">
                <?php if (!empty($freezer['store_latitude'])): ?>
                        <a href="https://maps.google.com/?q=<?= urlencode($freezer['store_latitude'] . ',' . $freezer['store_longitude']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">Open Maps</a>
                        <?php
                        $shareMapsLink = trim((string)($freezer['store_location_link'] ?? ''));
                        if ($shareMapsLink === '') {
                            $shareMapsLink = 'https://maps.google.com/?q=' . rawurlencode($freezer['store_latitude'] . ',' . $freezer['store_longitude']);
                        }
                        ?>
                        <button type="button" class="btn btn-sm btn-primary js-share-location-btn"
                            data-share-location="saved"
                            data-wa-lat="<?= htmlspecialchars((string)$freezer['store_latitude']) ?>"
                            data-wa-lng="<?= htmlspecialchars((string)$freezer['store_longitude']) ?>"
                            data-wa-url="<?= htmlspecialchars($shareMapsLink) ?>"
                            data-wa-title="<?= htmlspecialchars($freezer['store_name'] ?? 'Store location') ?>"
                            data-wa-address="<?= htmlspecialchars(trim(($freezer['store_address'] ?? '') . ', ' . ($freezer['store_city'] ?? '') . ' - ' . ($freezer['store_pincode'] ?? ''), ' ,-')) ?>"
                            data-wa-phone="<?= htmlspecialchars($viewPhone) ?>">
                            Share location
                        </button>
                <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-success"
                        data-save-current-location
                        data-store-id="<?= (int)$freezer['store_id'] ?>"
                        data-freezer-id="<?= (int)$freezer['id'] ?>"
                        data-csrf="<?= htmlspecialchars($csrfToken) ?>"
                        data-wa-phone="<?= htmlspecialchars($viewPhone) ?>"
                        data-wa-title="<?= htmlspecialchars($freezer['store_name'] ?? 'Store location') ?>">
                        Save my current location
                    </button>
            </div>
                <div id="gps-process-loading" class="gps-process-loading" hidden aria-live="polite">
                    <span class="gps-process-spinner" aria-hidden="true"></span>
                    <span class="gps-process-text">Processing…</span>
                    </div>
                    </div>
                    </div>
        <div class="card-body">
            <?php if (!empty($freezer['store_latitude'])): ?>
                        <?php
                $storeMapsLink = trim((string)($freezer['store_location_link'] ?? ''));
                if ($storeMapsLink === '') {
                    $storeMapsLink = 'https://maps.google.com/?q=' . rawurlencode($freezer['store_latitude'] . ',' . $freezer['store_longitude']);
                }
                ?>
                <div class="sp-map-wrap">
                    <div id="store-view-map"></div>
                    </div>
                <div class="sp-location-link-box">
                    <div class="sp-location-link-label">Current location link</div>
                    <a class="sp-location-link" href="<?= htmlspecialchars($storeMapsLink) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($storeMapsLink) ?></a>
                    <button type="button" class="btn btn-sm btn-outline" id="btn-copy-location-link" data-copy="<?= htmlspecialchars($storeMapsLink) ?>">Copy link</button>
                    </div>
                <div class="sp-latlng">
                    Lat <?= htmlspecialchars((string)$freezer['store_latitude']) ?>
                    · Lng <?= htmlspecialchars((string)$freezer['store_longitude']) ?>
                    <?php if (!empty($freezer['store_gps_captured_at'])): ?>
                        · Saved <?= htmlspecialchars((string)$freezer['store_gps_captured_at']) ?>
                    <?php endif; ?>
                    </div>
            <?php else: ?>
                <p style="color:#94a3b8; font-size:0.875rem; margin:0;">No GPS yet — tap <strong>Save my current location</strong> to capture &amp; store your live Maps link.</p>
            <?php endif; ?>
                    </div>
                </div>

    <div class="card">
        <div class="card-header"><div class="card-title"><span>Store photos</span></div></div>
        <div class="card-body">
            <div class="sp-photo-row">
                            <?php
                $photos = [
                    ['path' => $freezer['image_store_front'] ?? '', 'label' => 'Store front'],
                    ['path' => $freezer['image_left_side'] ?? '', 'label' => 'Freezer left'],
                    ['path' => $freezer['image_right_side'] ?? '', 'label' => 'Freezer right'],
                ];
                foreach ($photos as $ph):
                    $ok = !empty($ph['path']) && file_exists(__DIR__ . '/' . $ph['path']);
                ?>
                    <div class="sp-photo-tile">
                        <?php if ($ok): ?>
                            <img src="<?= htmlspecialchars($ph['path']) ?>" alt="<?= htmlspecialchars($ph['label']) ?>" class="lightbox-trigger" data-caption="<?= htmlspecialchars($ph['label']) ?>">
                        <?php else: ?>
                            <div class="sp-photo-empty"><?= htmlspecialchars($ph['label']) ?></div>
                        <?php endif; ?>
                        </div>
                <?php endforeach; ?>
                    </div>
            <div class="sp-photo-row" style="margin-top:0.75rem;">
                <?php
                $docs = [
                    ['path' => $freezer['image_aadhar_card'] ?? '', 'label' => 'Aadhaar front'],
                    ['path' => $freezer['image_aadhar_back'] ?? '', 'label' => 'Aadhaar back'],
                    ['path' => $freezer['image_sim'] ?? '', 'label' => 'SIM number'],
                ];
                foreach ($docs as $ph):
                    $ok = !empty($ph['path']) && file_exists(__DIR__ . '/' . $ph['path']);
                ?>
                    <div class="sp-photo-tile">
                        <?php if ($ok): ?>
                            <img src="<?= htmlspecialchars($ph['path']) ?>" alt="<?= htmlspecialchars($ph['label']) ?>" class="lightbox-trigger" data-caption="<?= htmlspecialchars($ph['label']) ?>">
                        <?php else: ?>
                            <div class="sp-photo-empty"><?= htmlspecialchars($ph['label']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($freezer['sim_number']) || !empty($freezer['sim_company'])): ?>
                <div class="profile-kv-grid" style="margin-top:0.85rem;">
                    <div class="profile-kv"><span class="profile-kv-label">SIM number</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['sim_number'] ?? '')) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">SIM company</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['sim_company'] ?? '')) ?></strong></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
        </div>

<div class="sp-main-grid sp-main-grid-2">
        <div class="card">
            <div class="card-header">
            <div class="card-title"><span>Associated freezers (<?= count($storeFreezers) ?>)</span></div>
                </div>
        <div class="card-body" style="padding:0;">
            <ul class="sp-freezer-list">
                <?php foreach ($storeFreezers as $sf): ?>
                    <li class="<?= (int)$sf['id'] === (int)$freezer['id'] ? 'is-current' : '' ?>">
                        <a href="profile_view.php?id=<?= urlencode($sf['unique_freezer_id']) ?>">
                            <code><?= htmlspecialchars($sf['unique_freezer_id']) ?></code>
                            <span><?= htmlspecialchars(($sf['company_code'] ?? '') . ' · ' . ($sf['model_number'] ?? '')) ?></span>
                        </a>
                        <?php if (($sf['status'] ?? '') === 'Active'): ?>
                            <span class="badge badge-success">Active</span>
                            <?php else: ?>
                            <span class="badge badge-warning"><?= htmlspecialchars($sf['status'] ?? '—') ?></span>
                            <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
                        </div>
                    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title"><span>Machine specifications</span></div>
            <span class="badge badge-primary"><?= htmlspecialchars($freezer['freezer_type']) ?></span>
        </div>
        <div class="card-body">
            <div class="profile-kv-grid">
                <div class="profile-kv profile-kv-wide"><span class="profile-kv-label">Unique Freezer ID</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['unique_freezer_id'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Type</span><strong class="profile-kv-value"><?= htmlspecialchars(trim(($freezer['freezer_type'] ?? '') . (!empty($freezer['type_name']) ? ' — ' . $freezer['type_name'] : '')) ?: '—') ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Brand</span><strong class="profile-kv-value"><?= htmlspecialchars(trim(($freezer['company_code'] ?? '') . (!empty($freezer['brand_name']) ? ' — ' . $freezer['brand_name'] : '')) ?: '—') ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Model</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['model_number'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Capacity</span><strong class="profile-kv-value"><?= !empty($freezer['capacity_litres']) ? htmlspecialchars((string)$freezer['capacity_litres']) . ' L' : '—' ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Location code</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['location_code'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Install</span><strong class="profile-kv-value"><?= htmlspecialchars(trim(($freezer['installation_date'] ?? '') . (!empty($freezer['install_month_year']) ? ' (' . $freezer['install_month_year'] . ')' : '')) ?: '—') ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Condition</span><span class="badge badge-neutral"><?= htmlspecialchars($valOrDash($freezer['freezer_condition'] ?? '')) ?></span></div>
                <div class="profile-kv"><span class="profile-kv-label">Sequence</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['unit_sequence'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Status</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['status'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">SIM number</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['sim_number'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">SIM company</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['sim_company'] ?? '')) ?></strong></div>
                <div class="profile-kv profile-kv-wide"><span class="profile-kv-label">Remark / notes</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['notes'] ?? '')) ?></strong></div>
            </div>
            <?php if (AppConfig::IOT_ENABLED): ?>
            <div class="sp-iot-mini">
                <h4>IoT device</h4>
                <div class="profile-kv-grid">
                    <div class="profile-kv"><span class="profile-kv-label">Serial</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['device_serial'] ?? '')) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">IMEI / MAC</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['imei_or_mac'] ?? '')) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">SIM</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['sim_number'] ?? '')) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">SIM company</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['sim_company'] ?? '')) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">Power</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['iot_power_status'] ?? '')) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">Last ping</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['iot_last_ping'] ?? '')) ?></strong></div>
                </div>
            </div>
                            <?php endif; ?>
        </div>
                        </div>
                    </div>

<div class="card">
    <div class="card-header"><div class="card-title"><span>Store activity</span></div></div>
    <div class="card-body">
        <?php if (empty($lifecycleTimeline)): ?>
            <p style="color:#94a3b8; margin:0;">No activity yet.</p>
                            <?php else: ?>
            <ul class="sp-timeline">
                <?php foreach (array_slice($lifecycleTimeline, 0, 8) as $ev): ?>
                    <li>
                        <div class="sp-timeline-dot"></div>
                        <div>
                            <strong><?= htmlspecialchars($ev['title'] ?? $ev['type'] ?? 'Event') ?></strong>
                            <div class="sp-timeline-meta">
                                <?= htmlspecialchars($ev['sort_date'] ?? $ev['date'] ?? '') ?>
                                <?php if (!empty($ev['outlet_id'])): ?>
                                    · <code><?= htmlspecialchars($ev['outlet_id']) ?></code>
                                <?php endif; ?>
                                <?php if (!empty($ev['store_name'])): ?>
                                    · <?= htmlspecialchars($ev['store_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    </li>
                <?php endforeach; ?>
            </ul>
                            <?php endif; ?>
                        </div>
                    </div>

<?php elseif ($activeTab === 'freezers'): ?>
<div class="card">
    <div class="card-header"><div class="card-title"><span>Freezers at this store (<?= count($storeFreezers) ?>)</span></div></div>
    <div class="card-body" style="padding:0;">
        <ul class="sp-freezer-list">
            <?php foreach ($storeFreezers as $sf): ?>
                <li class="<?= (int)$sf['id'] === (int)$freezer['id'] ? 'is-current' : '' ?>">
                    <a href="profile_view.php?id=<?= urlencode($sf['unique_freezer_id']) ?>">
                        <code><?= htmlspecialchars($sf['unique_freezer_id']) ?></code>
                        <span><?= htmlspecialchars(($sf['company_code'] ?? '') . ' · ' . ($sf['model_number'] ?? '') . ' · ' . ($sf['freezer_type'] ?? '')) ?></span>
                    </a>
                    <span class="badge <?= ($sf['status'] ?? '') === 'Active' ? 'badge-success' : 'badge-warning' ?>"><?= htmlspecialchars($sf['status'] ?? '—') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<?php elseif ($activeTab === 'location'): ?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><span>Location</span></div>
        <div class="sp-map-actions-wrap">
            <div class="sp-map-actions">
                <?php if (!empty($freezer['store_latitude'])): ?>
                    <a href="https://maps.google.com/?q=<?= urlencode($freezer['store_latitude'] . ',' . $freezer['store_longitude']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">Open Maps</a>
                    <?php
                    $shareMapsLinkTab = trim((string)($freezer['store_location_link'] ?? ''));
                    if ($shareMapsLinkTab === '') {
                        $shareMapsLinkTab = 'https://maps.google.com/?q=' . rawurlencode($freezer['store_latitude'] . ',' . $freezer['store_longitude']);
                    }
                    ?>
                    <button type="button" class="btn btn-sm btn-primary js-share-location-btn"
                        data-share-location="saved"
                        data-wa-lat="<?= htmlspecialchars((string)$freezer['store_latitude']) ?>"
                        data-wa-lng="<?= htmlspecialchars((string)$freezer['store_longitude']) ?>"
                        data-wa-url="<?= htmlspecialchars($shareMapsLinkTab) ?>"
                        data-wa-title="<?= htmlspecialchars($freezer['store_name'] ?? 'Store location') ?>"
                        data-wa-address="<?= htmlspecialchars(trim(($freezer['store_address'] ?? '') . ', ' . ($freezer['store_city'] ?? '') . ' - ' . ($freezer['store_pincode'] ?? ''), ' ,-')) ?>"
                        data-wa-phone="<?= htmlspecialchars($viewPhone) ?>">
                        Share location
                    </button>
                            <?php endif; ?>
                <button type="button" class="btn btn-sm btn-success"
                    data-save-current-location
                    data-store-id="<?= (int)$freezer['store_id'] ?>"
                    data-freezer-id="<?= (int)$freezer['id'] ?>"
                    data-csrf="<?= htmlspecialchars($csrfToken) ?>"
                    data-wa-phone="<?= htmlspecialchars($viewPhone) ?>"
                    data-wa-title="<?= htmlspecialchars($freezer['store_name'] ?? 'Store location') ?>">
                    Save my current location
                </button>
                        </div>
            <div class="gps-process-loading" hidden aria-live="polite">
                <span class="gps-process-spinner" aria-hidden="true"></span>
                <span class="gps-process-text">Processing…</span>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="sp-loc-tab-grid">
            <div class="profile-kv"><span class="profile-kv-label">Zone</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_zone'] ?? $freezer['zone'] ?? '')) ?></strong></div>
            <div class="profile-kv"><span class="profile-kv-label">City</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_city'] ?? '')) ?></strong></div>
            <div class="profile-kv"><span class="profile-kv-label">State</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_state'] ?? '')) ?></strong></div>
            <div class="profile-kv"><span class="profile-kv-label">PIN</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['store_pincode'] ?? '')) ?></strong></div>
            <div class="profile-kv" style="grid-column:1 / -1;"><span class="profile-kv-label">Address</span><strong class="profile-kv-value"><?= htmlspecialchars($storeAddressLine !== '' ? $storeAddressLine : '—') ?></strong></div>
                    </div>

        <div class="sp-current-loc-panel" style="margin-top:0; margin-bottom:1.15rem;">
            <div class="sp-current-loc-panel-head">
                <h4>Current location</h4>
                <?php if ($hasGps): ?>
                    <span class="badge badge-success">Recorded</span>
                            <?php else: ?>
                    <span class="badge badge-warning">Not set</span>
                            <?php endif; ?>
                        </div>
            <?php if ($hasGps): ?>
                <div class="profile-kv-grid">
                    <div class="profile-kv"><span class="profile-kv-label">Latitude</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars(number_format((float)$freezer['store_latitude'], 6)) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">Longitude</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars(number_format((float)$freezer['store_longitude'], 6)) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">Accuracy</span><strong class="profile-kv-value"><?= !empty($freezer['store_gps_accuracy']) ? ('± ' . htmlspecialchars((string)round((float)$freezer['store_gps_accuracy'])) . ' m') : '—' ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">Captured at</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['store_gps_captured_at'] ?? '')) ?></strong></div>
                    <div class="profile-kv profile-kv-wide">
                        <span class="profile-kv-label">Maps link</span>
                        <strong class="profile-kv-value">
                            <a href="<?= htmlspecialchars($storeMapsLink) ?>" target="_blank" rel="noopener" class="sp-maps-link"><?= htmlspecialchars($storeMapsLink) ?></a>
                        </strong>
                    </div>
                </div>
            <?php else: ?>
                <p class="sp-current-loc-empty">No current location saved yet.</p>
                    <?php endif; ?>
                </div>

        <?php if ($hasGps): ?>
            <div class="sp-map-wrap sp-map-wrap-lg"><div id="store-view-map"></div></div>
            <div class="sp-location-link-box">
                <div class="sp-location-link-label">Current location link</div>
                <a class="sp-location-link" href="<?= htmlspecialchars($storeMapsLink) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($storeMapsLink) ?></a>
                <button type="button" class="btn btn-sm btn-outline" data-copy="<?= htmlspecialchars($storeMapsLink) ?>">Copy link</button>
            </div>
            <div class="sp-latlng">
                Lat <?= htmlspecialchars(number_format((float)$freezer['store_latitude'], 6)) ?>
                · Lng <?= htmlspecialchars(number_format((float)$freezer['store_longitude'], 6)) ?>
                <?php if (!empty($freezer['store_gps_captured_at'])): ?>
                    · Saved <?= htmlspecialchars((string)$freezer['store_gps_captured_at']) ?>
                <?php endif; ?>
        </div>
        <?php else: ?>
            <p style="color:#94a3b8;">No GPS yet — tap <strong>Save my current location</strong> to store your live Maps link.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($activeTab === 'history'): ?>
<?php
$exportBase = 'profile_export.php?id=' . urlencode($freezer['unique_freezer_id']);
$tagCodeHist = $profileAssetTag['tag_code'] ?? '—';
$kycByYear = [];
foreach ($kycHistory as $k) {
    $y = (int)$k['cycle_year'];
    $h = (int)$k['cycle_half'];
    if (!isset($kycByYear[$y])) {
        $kycByYear[$y] = ['h1' => null, 'h2' => null];
    }
    if ($h === 1) {
        $kycByYear[$y]['h1'] = $k;
    } elseif ($h === 2) {
        $kycByYear[$y]['h2'] = $k;
    }
}
krsort($kycByYear);
?>
<!-- ========== Store history: Implementation + KYC ========== -->
<div class="card" style="margin-top: 1.5rem; margin-bottom: 1.5rem;" id="store-history">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
        <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="1"/>
                <path d="M3 9h18M9 3v18"/>
            </svg>
            <span>Store history</span>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">
            <a href="freezer_kyc.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-primary btn-sm">
                <?= $kycStatus['due'] ? 'Complete KYC now' : 'KYC verify' ?>
            </a>
            <a href="<?= $exportBase ?>&sheet=all" class="btn btn-outline btn-sm">Export all CSV</a>
            <a href="<?= $exportBase ?>&sheet=implementation" class="btn btn-outline btn-sm">Export implementation</a>
            <a href="<?= $exportBase ?>&sheet=kyc" class="btn btn-outline btn-sm">Export KYC</a>
        </div>
    </div>
    <div class="card-body">
        <?php if ($kycStatus['due']): ?>
            <div class="alert alert-warning" style="margin-bottom:1rem;">
                KYC due (every <?= (int)($kycStatus['gap_months'] ?? 6) ?> months from install / last KYC).
                <?php if (($kycStatus['anchor_source'] ?? '') === 'installation'): ?>
                    <span class="kyc-pending-date" style="display:inline;">Install <?= htmlspecialchars((string)($kycStatus['anchor_date'] ?? '')) ?></span>.
                <?php endif; ?>
                Current store: <code><?= htmlspecialchars($freezer['outlet_id']) ?></code>
                — <?= htmlspecialchars($freezer['store_name']) ?>.
            </div>
        <?php else: ?>
            <div class="alert alert-success" style="margin-bottom:1rem;">
                <?php if (!empty($kycStatus['current'])): ?>
                    KYC done —
                <?= htmlspecialchars($kycStatus['current']['result'] ?? '') ?>
                    on <?= htmlspecialchars($kycStatus['current']['verified_at'] ?? '') ?>.
                <?php else: ?>
                    Within KYC window from installation
                    <?= htmlspecialchars((string)($kycStatus['anchor_date'] ?? $freezer['installation_date'] ?? '')) ?>.
                <?php endif; ?>
                Next due <?= htmlspecialchars((string)($kycStatus['next_due_at'] ?? '—')) ?>
                (<?= (int)($kycStatus['gap_months'] ?? 6) ?>-month gap)
                at <code><?= htmlspecialchars($freezer['outlet_id']) ?></code>.
            </div>
        <?php endif; ?>

        <div class="excel-legend">
            <span><i class="excel-swatch" style="background:#dff6dd;"></i> Current store</span>
            <span><i class="excel-swatch" style="background:#faf9f8;"></i> Past store</span>
            <span>Freezer ID: <code><?= htmlspecialchars($freezer['unique_freezer_id']) ?></code></span>
            <span>Tag: <code><?= htmlspecialchars($tagCodeHist) ?></code></span>
            <span>Install: <code><?= htmlspecialchars($freezer['installation_date'] ?? '—') ?></code></span>
        </div>

        <h3 class="excel-sheet-title">1 — Implementation (store placements)</h3>
        <p class="excel-sheet-sub">
            Where the freezer was implemented / placed. Unique Freezer ID never changes; Store ID can change on move.
        </p>

        <?php if ($placementHistory): ?>
            <div class="excel-wrap">
                <table class="excel-sheet">
                    <thead>
                        <tr>
                            <th class="excel-col-index">#</th>
                            <th>Status</th>
                            <th>Unique Freezer ID</th>
                            <th>Tag code</th>
                            <th>Install date</th>
                            <th>Store ID</th>
                            <th>Store name</th>
                            <th>Customer</th>
                            <th>Address</th>
                            <th>City</th>
                            <th>State</th>
                            <th>PIN</th>
                            <th>Zone</th>
                            <th>Contact</th>
                            <th>Phone</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Days</th>
                            <th>Reason</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rowNum = 1;
                        foreach ($placementHistory as $p):
                            $isCurrent = empty($p['ended_at']);
                            $fromTs = !empty($p['started_at']) ? strtotime($p['started_at']) : false;
                            $toTs = $isCurrent ? time() : (!empty($p['ended_at']) ? strtotime($p['ended_at']) : false);
                            $days = ($fromTs && $toTs) ? max(0, (int)floor(($toTs - $fromTs) / 86400)) : null;
                        ?>
                            <tr class="<?= $isCurrent ? 'excel-row-current' : 'excel-row-left' ?>">
                                <td class="excel-col-index"><?= $rowNum++ ?></td>
                                <td><?= $isCurrent ? 'CURRENT' : 'LEFT' ?></td>
                                <td class="excel-mono"><?= htmlspecialchars($freezer['unique_freezer_id']) ?></td>
                                <td class="excel-mono"><?= htmlspecialchars($tagCodeHist) ?></td>
                                <td><?= htmlspecialchars($freezer['installation_date'] ?? '—') ?></td>
                                <td class="excel-mono"><?= htmlspecialchars($p['outlet_id'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($p['store_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($p['customer_name'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['address'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['city'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['state'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['pincode'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['zone'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['contact_person'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['phone_number'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['started_at'] ?? '—') ?></td>
                                <td><?= $isCurrent ? 'Still here' : htmlspecialchars($p['ended_at']) ?></td>
                                <td><?= $days !== null ? $days : '—' ?></td>
                                <td><?= htmlspecialchars($p['transfer_reason'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color:#94a3b8; margin-bottom:1rem;">No implementation / placement rows yet.</p>
        <?php endif; ?>

        <h3 class="excel-sheet-title">2 — KYC verifications</h3>
        <p class="excel-sheet-sub">
            Bi-annual checks (H1 / H2) with result, store, GPS and photo evidence flags.
        </p>

        <?php if ($kycHistory): ?>
            <div class="excel-wrap">
                <table class="excel-sheet">
                    <thead>
                        <tr>
                            <th class="excel-col-index">#</th>
                            <th>Cycle</th>
                            <th>Verified date</th>
                            <th>Result</th>
                            <th>Store ID</th>
                            <th>Store name</th>
                            <th>Address</th>
                            <th>City</th>
                            <th>State</th>
                            <th>PIN</th>
                            <th>Contact</th>
                            <th>Phone</th>
                            <th>GPS lat</th>
                            <th>GPS lng</th>
                            <th>Store photo</th>
                            <th>Freezer photo</th>
                            <th>Notes</th>
                            <th>Verified by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $kNum = 1; foreach ($kycHistory as $k): ?>
                            <tr>
                                <td class="excel-col-index"><?= $kNum++ ?></td>
                                <td><strong><?= (int)$k['cycle_year'] ?>-H<?= (int)$k['cycle_half'] ?></strong></td>
                                <td><?= htmlspecialchars($k['verified_at'] ?? '—') ?></td>
                                <td>
                                    <?php if (($k['result'] ?? '') === 'PASS'): ?>
                                        <span class="badge badge-success">PASS</span>
                                    <?php elseif (($k['result'] ?? '') === 'FAIL'): ?>
                                        <span class="badge badge-danger">FAIL</span>
        <?php else: ?>
                                        <span class="badge badge-warning"><?= htmlspecialchars($k['result'] ?? '—') ?></span>
                            <?php endif; ?>
                                </td>
                                <td class="excel-mono"><?= htmlspecialchars($k['outlet_id'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($k['store_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($k['address'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($k['city'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($k['state'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($k['pincode'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($k['contact_person'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($k['phone_number'] ?: '—') ?></td>
                                <td class="excel-mono"><?= htmlspecialchars($k['latitude'] !== null && $k['latitude'] !== '' ? (string)$k['latitude'] : '—') ?></td>
                                <td class="excel-mono"><?= htmlspecialchars($k['longitude'] !== null && $k['longitude'] !== '' ? (string)$k['longitude'] : '—') ?></td>
                                <td><?= !empty($k['image_store_front']) ? 'Yes' : 'No' ?></td>
                                <td><?= !empty($k['image_freezer']) ? 'Yes' : 'No' ?></td>
                                <td><?= htmlspecialchars($k['notes'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($k['verified_by_name'] ?: '—') ?></td>
                            </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color:#94a3b8; margin-bottom:1rem;">No KYC rows yet. Use <strong>KYC verify</strong> to add the first check.</p>
        <?php endif; ?>

        <?php if ($kycByYear): ?>
            <h3 class="excel-sheet-title" style="margin-top:1.25rem;">3 — KYC by year</h3>
            <p class="excel-sheet-sub">
                H1 (Jan–Jun) / H2 (Jul–Dec). Blank cell = that half not done yet.
            </p>
            <div class="excel-wrap">
                <table class="excel-sheet">
                        <thead>
                            <tr>
                            <th class="excel-col-index">#</th>
                            <th>Year</th>
                            <th>H1 result</th>
                            <th>H1 date</th>
                            <th>H1 store ID</th>
                            <th>H1 store name</th>
                            <th>H2 result</th>
                            <th>H2 date</th>
                            <th>H2 store ID</th>
                            <th>H2 store name</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $yNum = 1; foreach ($kycByYear as $year => $halves): ?>
                            <?php $h1 = $halves['h1']; $h2 = $halves['h2']; ?>
                            <tr>
                                <td class="excel-col-index"><?= $yNum++ ?></td>
                                <td><strong><?= (int)$year ?></strong></td>
                                <td><?= $h1 ? htmlspecialchars($h1['result']) : '' ?></td>
                                <td><?= $h1 ? htmlspecialchars($h1['verified_at']) : '' ?></td>
                                <td class="excel-mono"><?= $h1 ? htmlspecialchars($h1['outlet_id']) : '' ?></td>
                                <td><?= $h1 ? htmlspecialchars($h1['store_name']) : '' ?></td>
                                <td><?= $h2 ? htmlspecialchars($h2['result']) : '' ?></td>
                                <td><?= $h2 ? htmlspecialchars($h2['verified_at']) : '' ?></td>
                                <td class="excel-mono"><?= $h2 ? htmlspecialchars($h2['outlet_id']) : '' ?></td>
                                <td><?= $h2 ? htmlspecialchars($h2['store_name']) : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Map Script for Store View -->
<?php if (!empty($freezer['store_latitude']) && in_array($activeTab, ['overview', 'location'], true)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const mapEl = document.getElementById('store-view-map');
    if (!mapEl) return;
    const lat = <?= (float)$freezer['store_latitude'] ?>;
    const lng = <?= (float)$freezer['store_longitude'] ?>;
    const storeMap = L.map(mapEl).setView([lat, lng], 16);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(storeMap);

    const marker = L.marker([lat, lng]).addTo(storeMap);
    marker.bindPopup(`<strong><?= addslashes($freezer['store_name']) ?></strong><br><?= addslashes($freezer['store_address']) ?>`).openPopup();

    <?php if (!empty($freezer['store_gps_accuracy'])): ?>
        L.circle([lat, lng], {
            radius: <?= (float)$freezer['store_gps_accuracy'] ?>,
            color: '#0284c7',
            fillColor: '#38bdf8',
            fillOpacity: 0.15
        }).addTo(storeMap);
    <?php endif; ?>
});
</script>
<?php endif; ?>

<?php if (AppConfig::IOT_ENABLED): ?>
<!-- Telemetry Simulation Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnPing = document.getElementById('btn-simulate-ping');
    if (btnPing) {
        btnPing.addEventListener('click', function() {
            btnPing.disabled = true;
            btnPing.innerHTML = 'Pinging...';

            fetch('api/iot_telemetry.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'simulate_ping',
                    freezer_id: <?= (int)$freezer['id'] ?>
                })
            })
            .then(res => res.json())
            .then(data => {
                btnPing.disabled = false;
                btnPing.innerHTML = '⚡ Test Ping';
                if (data.success && data.reading) {
                    const temp = parseFloat(data.reading.temperature).toFixed(1);
                    document.getElementById('live-temp-display').textContent = temp + ' °C';
                    
                    const badge = document.getElementById('temp-status-badge');
                    if (temp > -18.0) {
                        badge.className = 'badge badge-danger';
                        badge.textContent = 'HIGH TEMP ALERT (' + temp + '°C)';
                    } else {
                        badge.className = 'badge badge-success';
                        badge.textContent = 'COMPLIANT (-24°C to -18°C)';
                    }
                }
            })
            .catch(() => {
                btnPing.disabled = false;
                btnPing.innerHTML = '⚡ Test Ping';
            });
        });
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
