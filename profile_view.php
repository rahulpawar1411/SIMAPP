<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Models\IoTDevice;
use App\Services\QRCodeService;

AuthService::requireLogin();
$currentUser = AuthService::currentUser();

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

$pageTitle = 'Freezer Profile: ' . $freezer['unique_freezer_id'];
require_once __DIR__ . '/includes/header.php';
?>

<!-- Header Action Bar -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <h2 style="font-size: 1.4rem; font-weight: 800; font-family: var(--font-mono); color: #0284c7;">
                <?= htmlspecialchars($freezer['unique_freezer_id']) ?>
            </h2>
            <?php if ($freezer['status'] === 'Active'): ?>
                <span class="badge badge-success">Active & Operational</span>
            <?php else: ?>
                <span class="badge badge-warning"><?= htmlspecialchars($freezer['status']) ?></span>
            <?php endif; ?>
        </div>
        <p style="color: #64748b; font-size: 0.875rem; margin-top: 0.2rem;">
            <?= htmlspecialchars($freezer['store_name']) ?> &bull; <?= htmlspecialchars($freezer['store_city']) ?>, <?= htmlspecialchars($freezer['store_state']) ?>
        </p>
    </div>

    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="profiles.php" class="btn btn-secondary">
            &larr; Back to List
        </a>
        <a href="freezer_transfer.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-outline">
            Transfer store
        </a>
        <a href="freezer_kyc.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-outline">
            KYC <?= $kycStatus['due'] ? '(due)' : 'verify' ?>
        </a>
        <a href="profile_export.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-outline">
            Export data
        </a>
        <a href="qr_print.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-outline" target="_blank">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect x="6" y="14" width="12" height="8"/>
            </svg>
            <span>Print Asset Tag</span>
        </a>
        <a href="profile_edit.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 20h9"/>
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            <span>Edit Profile</span>
        </a>
        <?php if (AuthService::hasRole('super_admin')): ?>
            <a href="profile_delete.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this freezer profile and all attached records?');">
                Delete
            </a>
        <?php endif; ?>
    </div>
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

<div class="form-grid">
    <!-- LEFT COLUMN: QR Code Card & Photographic Verification -->
    <div class="col-4">
        <!-- QR Code Tag Card -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <div class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7"/>
                        <rect x="14" y="3" width="7" height="7"/>
                        <rect x="14" y="14" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/>
                    </svg>
                    <span>Freezer QR Asset Code</span>
                </div>
            </div>
            <div class="card-body" style="text-align: center;">
                <div style="background: #ffffff; padding: 1rem; border-radius: var(--radius-md); border: 2px dashed #cbd5e1; display: inline-block; box-shadow: var(--shadow-sm); margin-bottom: 1rem;">
                    <?php if ($qrPath && file_exists(__DIR__ . '/' . $qrPath)): ?>
                        <img src="<?= htmlspecialchars($qrPath) ?>" alt="QR Code" style="width: 190px; height: 190px; display: block;">
                    <?php else: ?>
                        <div style="width: 190px; height: 190px; display: flex; align-items: center; justify-content: center; background: #f1f5f9;">
                            QR Generated
                        </div>
                    <?php endif; ?>
                </div>

                <div style="font-family: var(--font-mono); font-size: 0.85rem; font-weight: 700; color: #0f172a; word-break: break-all; margin-bottom: 0.5rem;">
                    <?= htmlspecialchars($freezer['unique_freezer_id']) ?>
                </div>

                <p style="font-size: 0.75rem; color: #64748b; margin-bottom: 1rem;">
                    Scan using smartphone camera or the in-app scanner to open this profile.
                </p>

                <div style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
                    <a href="qr_print.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" target="_blank" class="btn btn-sm btn-primary">
                        Print QR Image
                    </a>
                    <?php if ($qrPath): ?>
                        <a href="<?= htmlspecialchars($qrPath) ?>" download="<?= htmlspecialchars($freezer['unique_freezer_id']) ?>_QR.svg" class="btn btn-sm btn-secondary">
                            Download QR
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (AppConfig::IOT_ENABLED): ?>
        <!-- IoT Sensor Telemetry Status Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/>
                    </svg>
                    <span>IoT Cold-Chain Telemetry</span>
                </div>
                <button type="button" id="btn-simulate-ping" class="btn btn-sm btn-outline" style="font-size: 0.7rem; padding: 0.2rem 0.5rem;">
                    ⚡ Test Ping
                </button>
            </div>
            <div class="card-body">
                <div style="text-align: center; padding: 0.75rem 0 1rem; border-bottom: 1px solid var(--border-color); margin-bottom: 1rem;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Live Freezer Temperature</span>
                    <div id="live-temp-display" style="font-size: 2.2rem; font-weight: 800; font-family: var(--font-mono); color: #0284c7; margin: 0.25rem 0;">
                        <?= $latestTelemetry ? number_format((float)$latestTelemetry['temperature'], 1) : '-20.5' ?> &deg;C
                    </div>
                    <span id="temp-status-badge" class="badge badge-success">COMPLIANT (-24&deg;C to -18&deg;C)</span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; font-size: 0.8rem; margin-bottom: 1rem;">
                    <div style="background: #f8fafc; padding: 0.5rem 0.75rem; border-radius: 6px;">
                        <span style="color: #64748b; display: block; font-size: 0.7rem;">COMPRESSOR</span>
                        <strong id="live-compressor" style="color: #10b981;">Running (Active)</strong>
                    </div>
                    <div style="background: #f8fafc; padding: 0.5rem 0.75rem; border-radius: 6px;">
                        <span style="color: #64748b; display: block; font-size: 0.7rem;">DOOR STATUS</span>
                        <strong id="live-door" style="color: #0f172a;">Closed (Secure)</strong>
                    </div>
                    <div style="background: #f8fafc; padding: 0.5rem 0.75rem; border-radius: 6px;">
                        <span style="color: #64748b; display: block; font-size: 0.7rem;">POWER</span>
                        <strong id="live-power" style="color: #10b981;">Grid ON</strong>
                    </div>
                    <div style="background: #f8fafc; padding: 0.5rem 0.75rem; border-radius: 6px;">
                        <span style="color: #64748b; display: block; font-size: 0.7rem;">BATTERY</span>
                        <strong id="live-battery" style="color: #0f172a;">98% (Healthy)</strong>
                    </div>
                </div>

                <div style="font-size: 0.75rem; color: #94a3b8; text-align: center;">
                    IoT Serial: <strong><?= htmlspecialchars($freezer['device_serial'] ?? 'RF-IOT-99210') ?></strong>
                    <?php if (!empty($freezer['sim_number']) || !empty($freezer['sim_company'])): ?>
                        &bull; SIM: <strong><?= htmlspecialchars($freezer['sim_number'] ?? '—') ?></strong>
                        (<?= htmlspecialchars($freezer['sim_company'] ?? '—') ?>)
                    <?php endif; ?>
                    &bull; ReeferON RaaS Telemetry
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT COLUMN: Detailed Specifications, Photos & GPS Map -->
    <div class="col-8">
        <!-- Freezer Specifications Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                        <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                    </svg>
                    <span>Machine Specifications</span>
                </div>
                <span class="badge badge-primary"><?= htmlspecialchars($freezer['freezer_type']) ?></span>
            </div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; font-size: 0.85rem;">
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Freezer Type</span>
                        <strong><?= htmlspecialchars($freezer['freezer_type']) ?> - <?= htmlspecialchars($freezer['type_name'] ?? 'Deep Freezer') ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Brand / OEM</span>
                        <strong><?= htmlspecialchars($freezer['company_code']) ?> - <?= htmlspecialchars($freezer['brand_name'] ?? 'Rockwell') ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Model Number</span>
                        <strong><?= htmlspecialchars($freezer['model_number']) ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Capacity</span>
                        <strong><?= $freezer['capacity_litres'] ? htmlspecialchars($freezer['capacity_litres']) . ' Litres' : '550 Litres' ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Location Code</span>
                        <strong><?= htmlspecialchars($freezer['location_code']) ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">PIN Code (in Freezer ID)</span>
                        <strong><?= htmlspecialchars($freezer['pincode_last3']) ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Installation MMYY</span>
                        <strong><?= htmlspecialchars($freezer['install_month_year']) ?> (Date: <?= htmlspecialchars($freezer['installation_date']) ?>)</strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Unit Sequence</span>
                        <strong style="color: #0284c7;"><?= htmlspecialchars($freezer['unit_sequence']) ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Freezer Condition</span>
                        <span class="badge badge-neutral"><?= htmlspecialchars($freezer['freezer_condition']) ?></span>
                    </div>
                </div>

                <?php if (!empty($freezer['notes'])): ?>
                    <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color); font-size: 0.8rem; color: #475569;">
                        <strong>Remark:</strong> <?= nl2br(htmlspecialchars($freezer['notes'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Store Profile & GPS Map Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <span>Store Profile & GPS Geolocation</span>
                </div>
                <?php if (!empty($freezer['store_latitude'])): ?>
                    <a href="https://maps.google.com/?q=<?= $freezer['store_latitude'] ?>,<?= $freezer['store_longitude'] ?>" target="_blank" class="btn btn-sm btn-outline">
                        Open in Google Maps &rarr;
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; font-size: 0.85rem; margin-bottom: 1.25rem;">
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Store Name & Outlet ID</span>
                        <strong><?= htmlspecialchars($freezer['store_name']) ?></strong> (<?= htmlspecialchars($freezer['outlet_id']) ?>)
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Customer Account</span>
                        <strong><?= htmlspecialchars($freezer['customer_name'] ?? 'Baskin Robbins') ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Address & City</span>
                        <?= htmlspecialchars($freezer['store_address']) ?>, <?= htmlspecialchars($freezer['store_city']) ?>, <?= htmlspecialchars($freezer['store_state']) ?> - <?= htmlspecialchars($freezer['store_pincode']) ?>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Operator</span>
                        <strong><?= htmlspecialchars(($freezer['contact_person'] ?? '') !== '' ? $freezer['contact_person'] : 'N/A') ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Phone</span>
                        <?php
                            $viewPhone = trim((string)($freezer['phone_number'] ?? ''));
                            if ($viewPhone !== '' && !str_starts_with($viewPhone, '+')) {
                                $d = preg_replace('/\D/', '', $viewPhone);
                                if (strlen($d) === 10) $viewPhone = '+91' . $d;
                            }
                        ?>
                        <strong><?= htmlspecialchars($viewPhone !== '' ? $viewPhone : 'N/A') ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Operator Email</span>
                        <strong><?= htmlspecialchars(($freezer['store_email'] ?? '') !== '' ? $freezer['store_email'] : 'N/A') ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">Operator Aadhaar</span>
                        <?php
                            $aadhar = preg_replace('/\D/', '', (string)($freezer['aadhar_number'] ?? ''));
                            $aadharDisplay = strlen($aadhar) === 12
                                ? (substr($aadhar, 0, 4) . '-' . substr($aadhar, 4, 4) . '-' . substr($aadhar, 8, 4))
                                : 'N/A';
                        ?>
                        <strong style="font-family: var(--font-mono);"><?= htmlspecialchars($aadharDisplay) ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">FSSAI Number</span>
                        <strong style="font-family: var(--font-mono);"><?= htmlspecialchars(($freezer['fssai_number'] ?? '') !== '' ? $freezer['fssai_number'] : 'N/A') ?></strong>
                    </div>
                    <div>
                        <span style="color: #64748b; display: block; font-size: 0.75rem; text-transform: uppercase;">GST Number</span>
                        <strong style="font-family: var(--font-mono);"><?= htmlspecialchars(($freezer['gst_number'] ?? '') !== '' ? $freezer['gst_number'] : 'N/A') ?></strong>
                    </div>
                </div>

                <!-- GPS Map display -->
                <?php if (!empty($freezer['store_latitude'])): ?>
                    <div style="position: relative; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-color);">
                        <div id="store-view-map" style="width: 100%; height: 240px; z-index: 1;"></div>
                        <div style="position: absolute; bottom: 8px; left: 8px; z-index: 1000; background: rgba(15, 23, 42, 0.85); color: #ffffff; padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-family: var(--font-mono);">
                            <?php
                            $gpsAcc = (float)($freezer['store_gps_accuracy'] ?? 3.5);
                            $gpsLabel = $gpsAcc >= 1000
                                ? 'City approx (Excel import) — capture Store Front GPS for exact pin'
                                : ('GPS: ' . $freezer['store_latitude'] . ', ' . $freezer['store_longitude'] . ' (±' . $gpsAcc . 'm)');
                            ?>
                            <?= htmlspecialchars($gpsLabel) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3 Uploaded Images Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21 15 16 10 5 21"/>
                    </svg>
                    <span>Photo gallery (click to open · arrows to browse)</span>
                </div>
            </div>
            <div class="card-body">
                <div class="photo-upload-grid photo-upload-grid-4">
                    <!-- Image 0: Store Front -->
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">1. STORE FRONT SIDE</div>
                        <div style="height: 180px; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-color); background: #0f172a;">
                            <?php if (!empty($freezer['image_store_front']) && file_exists(__DIR__ . '/' . $freezer['image_store_front'])): ?>
                                <img src="<?= htmlspecialchars($freezer['image_store_front']) ?>" alt="Store Front" class="lightbox-trigger" data-caption="Store Front - <?= htmlspecialchars($freezer['store_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #94a3b8; font-size: 0.8rem;">No Store Front Photo</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Image: Manager Aadhaar front -->
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">2. AADHAAR FRONT</div>
                        <div style="height: 180px; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-color); background: #0f172a;">
                            <?php if (!empty($freezer['image_aadhar_card']) && file_exists(__DIR__ . '/' . $freezer['image_aadhar_card'])): ?>
                                <img src="<?= htmlspecialchars($freezer['image_aadhar_card']) ?>" alt="Aadhaar Front" class="lightbox-trigger" data-caption="Aadhaar Front - <?= htmlspecialchars($freezer['contact_person'] ?? $freezer['store_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #94a3b8; font-size: 0.8rem;">No Aadhaar Front</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Image: Manager Aadhaar back -->
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">3. AADHAAR BACK</div>
                        <div style="height: 180px; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-color); background: #0f172a;">
                            <?php if (!empty($freezer['image_aadhar_back']) && file_exists(__DIR__ . '/' . $freezer['image_aadhar_back'])): ?>
                                <img src="<?= htmlspecialchars($freezer['image_aadhar_back']) ?>" alt="Aadhaar Back" class="lightbox-trigger" data-caption="Aadhaar Back - <?= htmlspecialchars($freezer['contact_person'] ?? $freezer['store_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #94a3b8; font-size: 0.8rem;">No Aadhaar Back</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Image 1: Left Side -->
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">4. FREEZER LEFT SIDE</div>
                        <div style="height: 180px; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-color); background: #0f172a;">
                            <?php if (!empty($freezer['image_left_side']) && file_exists(__DIR__ . '/' . $freezer['image_left_side'])): ?>
                                <img src="<?= htmlspecialchars($freezer['image_left_side']) ?>" alt="Left Side" class="lightbox-trigger" data-caption="Freezer Left Side - <?= htmlspecialchars($freezer['unique_freezer_id']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #94a3b8; font-size: 0.8rem;">No Left Photo</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Image 2: Right Side -->
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">5. FREEZER RIGHT SIDE</div>
                        <div style="height: 180px; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-color); background: #0f172a;">
                            <?php if (!empty($freezer['image_right_side']) && file_exists(__DIR__ . '/' . $freezer['image_right_side'])): ?>
                                <img src="<?= htmlspecialchars($freezer['image_right_side']) ?>" alt="Right Side" class="lightbox-trigger" data-caption="Freezer Right Side - <?= htmlspecialchars($freezer['unique_freezer_id']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #94a3b8; font-size: 0.8rem;">No Right Photo</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (AppConfig::IOT_ENABLED): ?>
                    <!-- Image 3: IoT Device -->
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">6. IOT DEVICE ATTACHMENT</div>
                        <div style="height: 180px; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-color); background: #0f172a;">
                            <?php if (!empty($freezer['image_iot_device']) && file_exists(__DIR__ . '/' . $freezer['image_iot_device'])): ?>
                                <img src="<?= htmlspecialchars($freezer['image_iot_device']) ?>" alt="IoT Device" class="lightbox-trigger" data-caption="IoT Device Attachment - Serial: <?= htmlspecialchars($freezer['device_serial'] ?? 'N/A') ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #94a3b8; font-size: 0.8rem;">No IoT Photo</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========== BOTTOM: Store history ========== -->
<div class="card" style="margin-top: 1.5rem; margin-bottom: 1.5rem;" id="store-history">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
        <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            <span>Store history</span>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">
            <a href="freezer_transfer.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-secondary btn-sm">Transfer store</a>
            <a href="freezer_kyc.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-primary btn-sm">
                <?= $kycStatus['due'] ? 'Complete KYC now' : 'Add KYC' ?>
            </a>
            <a href="profile_export.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-outline btn-sm">Export data</a>
        </div>
    </div>
    <div class="card-body">
        <?php if ($kycStatus['due']): ?>
            <div class="alert alert-warning" style="margin-bottom:1rem;">
                <strong><?= htmlspecialchars($kycStatus['cycle']['label']) ?></strong> KYC due
                (<?= htmlspecialchars($kycStatus['cycle']['window']) ?>).
                Current: <code><?= htmlspecialchars($freezer['outlet_id']) ?></code>
                — <?= htmlspecialchars($freezer['store_name']) ?>.
            </div>
        <?php else: ?>
            <div class="alert alert-success" style="margin-bottom:1rem;">
                <strong><?= htmlspecialchars($kycStatus['cycle']['label']) ?></strong> KYC done —
                <?= htmlspecialchars($kycStatus['current']['result'] ?? '') ?>
                on <?= htmlspecialchars($kycStatus['current']['verified_at'] ?? '') ?>
                at <code><?= htmlspecialchars($freezer['outlet_id']) ?></code>.
            </div>
        <?php endif; ?>

        <h3 style="font-size:0.95rem; font-weight:800; color:#0f172a; margin:0 0 0.75rem;">
            Where this freezer was (stores over time)
        </h3>
        <p style="font-size:0.8rem; color:#64748b; margin-bottom:1rem;">
            Unique Freezer ID same rehti hai. Har naye outlet pe nayi Store ID milti hai.
        </p>

        <?php if ($placementHistory): ?>
            <div style="overflow-x:auto; margin-bottom:1.25rem;">
                <table class="data-table" style="font-size:0.82rem;">
                    <thead>
                        <tr>
                            <th>Store ID</th>
                            <th>Store name</th>
                            <th>City / PIN</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($placementHistory as $p): ?>
                            <tr style="<?= empty($p['ended_at']) ? 'background:#f0f9ff;' : '' ?>">
                                <td style="font-family:var(--font-mono); font-weight:700; color:#0284c7;"><?= htmlspecialchars($p['outlet_id']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($p['store_name']) ?></strong>
                                    <?php if (!empty($p['contact_person'])): ?>
                                        <div style="font-size:0.72rem; color:#94a3b8;"><?= htmlspecialchars($p['contact_person']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(trim(($p['city'] ?? '') . ' ' . ($p['pincode'] ?? ''))) ?></td>
                                <td><?= htmlspecialchars($p['started_at']) ?></td>
                                <td><?= $p['ended_at'] ? htmlspecialchars($p['ended_at']) : '<span class="badge badge-success">Current</span>' ?></td>
                                <td><?= htmlspecialchars($p['transfer_reason'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color:#94a3b8; margin-bottom:1rem;">No store placement history yet.</p>
        <?php endif; ?>

        <h3 style="font-size:0.95rem; font-weight:800; color:#0f172a; margin:0 0 0.75rem;">
            Full timeline (store moves + KYC)
        </h3>

        <?php if (!$lifecycleTimeline): ?>
            <p style="color:#94a3b8;">No timeline events yet.</p>
        <?php else: ?>
            <div class="lifecycle-timeline">
                <?php foreach ($lifecycleTimeline as $ev): ?>
                    <?php
                    $dotColor = match ($ev['type']) {
                        'kyc' => ($ev['result'] === 'FAIL' ? '#dc2626' : ($ev['result'] === 'PENDING' ? '#d97706' : '#059669')),
                        'placement_current' => '#0284c7',
                        'left_store' => '#94a3b8',
                        default => '#6366f1',
                    };
                    $typeLabel = match ($ev['type']) {
                        'kyc' => 'KYC',
                        'placement_current' => 'CURRENT',
                        'left_store' => 'LEFT',
                        'placement' => 'PLACED',
                        default => strtoupper($ev['type']),
                    };
                    ?>
                    <div class="lifecycle-item" style="display:flex; gap:0.85rem; padding:0.85rem 0; border-bottom:1px solid #e2e8f0;">
                        <div style="width:4.5rem; flex-shrink:0; text-align:right;">
                            <div style="font-size:0.78rem; font-weight:700; color:#0f172a;"><?= htmlspecialchars($ev['date']) ?></div>
                            <?php if (!empty($ev['end_date'])): ?>
                                <div style="font-size:0.7rem; color:#94a3b8;">→ <?= htmlspecialchars($ev['end_date']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="position:relative; width:14px; flex-shrink:0;">
                            <div style="width:12px; height:12px; border-radius:50%; background:<?= $dotColor ?>; margin-top:0.3rem; box-shadow:0 0 0 3px <?= $dotColor ?>22;"></div>
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:0.4rem; margin-bottom:0.25rem;">
                                <span class="badge" style="background:<?= $dotColor ?>18; color:<?= $dotColor ?>; border:1px solid <?= $dotColor ?>44;"><?= $typeLabel ?></span>
                                <strong style="font-size:0.9rem;"><?= htmlspecialchars($ev['title']) ?></strong>
                                <?php if (!empty($ev['is_new_outlet'])): ?>
                                    <span class="badge badge-warning">New outlet</span>
                                <?php endif; ?>
                                <?php if (!empty($ev['is_current'])): ?>
                                    <span class="badge badge-success">Here now</span>
                                <?php endif; ?>
                                <?php if (!empty($ev['result'])): ?>
                                    <span class="badge <?= $ev['result'] === 'PASS' ? 'badge-success' : ($ev['result'] === 'FAIL' ? 'badge-danger' : 'badge-warning') ?>">
                                        <?= htmlspecialchars($ev['result']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:0.875rem; color:#0f172a;">
                                <span style="font-family:var(--font-mono); font-weight:700; color:#0284c7;"><?= htmlspecialchars($ev['outlet_id']) ?></span>
                                · <?= htmlspecialchars($ev['store_name']) ?>
                                <?php if ($ev['city'] !== ''): ?>
                                    · <?= htmlspecialchars($ev['city']) ?><?= $ev['pincode'] !== '' ? ' ' . htmlspecialchars($ev['pincode']) : '' ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($ev['reason'] !== ''): ?>
                                <div style="font-size:0.78rem; color:#475569; margin-top:0.2rem;">
                                    Reason: <?= htmlspecialchars($ev['reason']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($ev['type'] === 'kyc' && (!empty($ev['image_store_front']) || !empty($ev['image_freezer']))): ?>
                                <div style="display:flex; gap:0.5rem; margin-top:0.45rem; flex-wrap:wrap;">
                                    <?php if (!empty($ev['image_store_front']) && file_exists(__DIR__ . '/' . $ev['image_store_front'])): ?>
                                        <img src="<?= htmlspecialchars($ev['image_store_front']) ?>" alt="KYC store" class="lightbox-trigger" data-caption="KYC store <?= htmlspecialchars($ev['cycle'] ?? '') ?>" style="height:52px; width:72px; object-fit:cover; border-radius:6px; border:1px solid #e2e8f0;">
                                    <?php endif; ?>
                                    <?php if (!empty($ev['image_freezer']) && file_exists(__DIR__ . '/' . $ev['image_freezer'])): ?>
                                        <img src="<?= htmlspecialchars($ev['image_freezer']) ?>" alt="KYC freezer" class="lightbox-trigger" data-caption="KYC freezer <?= htmlspecialchars($ev['cycle'] ?? '') ?>" style="height:52px; width:72px; object-fit:cover; border-radius:6px; border:1px solid #e2e8f0;">
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($kycHistory): ?>
            <details style="margin-top:1.25rem;">
                <summary style="cursor:pointer; font-weight:700; font-size:0.85rem; color:#334155;">KYC table (all cycles)</summary>
                <div style="overflow-x:auto; margin-top:0.75rem;">
                    <table class="data-table" style="font-size:0.78rem;">
                        <thead>
                            <tr>
                                <th>Cycle</th>
                                <th>Date</th>
                                <th>Result</th>
                                <th>Store ID</th>
                                <th>Store / City</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kycHistory as $k): ?>
                                <tr>
                                    <td><?= (int)$k['cycle_year'] ?>-H<?= (int)$k['cycle_half'] ?></td>
                                    <td><?= htmlspecialchars($k['verified_at']) ?></td>
                                    <td><?= htmlspecialchars($k['result']) ?></td>
                                    <td style="font-family:var(--font-mono);"><?= htmlspecialchars($k['outlet_id']) ?></td>
                                    <td><?= htmlspecialchars($k['store_name'] . ' · ' . ($k['city'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($k['notes'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </div>
</div>

<!-- Map Script for Store View -->
<?php if (!empty($freezer['store_latitude'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const lat = <?= (float)$freezer['store_latitude'] ?>;
    const lng = <?= (float)$freezer['store_longitude'] ?>;
    const storeMap = L.map('store-view-map').setView([lat, lng], 16);

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
