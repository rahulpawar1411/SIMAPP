<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Models\AssetTag;
use App\Models\AppNotification;
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
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Not found</title></head><body>'
        . '<h1>Profile not found</h1>'
        . '<p>The requested freezer profile could not be found.</p>'
        . '<p><a href="profiles.php">Back to Assets</a></p></body></html>';
    exit;
}

// Technicians with zone/city assignment cannot open out-of-area profiles
AuthService::requireAreaAccess(
    $freezer['store_zone'] ?? $freezer['zone'] ?? null,
    $freezer['store_city'] ?? null,
    'profiles.php'
);

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
$lifecycleTimeline = FreezerLifecycle::buildTimeline((int)$freezer['id']);
$isAdminUser = AuthService::hasRole(AppConfig::ROLE_SUPER_ADMIN);

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
/** Keep address clean — Unique Freezer ID / QR payload never shown inside Address */
$splitAddressFreezerId = static function (string $text, ?string $knownId = null): array {
    $text = trim($text);
    $fid = trim((string)$knownId);
    $found = null;
    if ($fid !== '' && $text !== '') {
        $escaped = preg_quote($fid, '/');
        if (preg_match('/' . $escaped . '/i', $text)) {
            $found = $fid;
            $text = preg_replace('/' . $escaped . '/i', '', $text);
        }
    }
    // Any DF-/DCF-/CF- freezer id glued into address (start, middle, or end)
    if (preg_match('/((?:DF|DCF|CF)-[A-Z0-9]{2,}(?:-[A-Z0-9]+)+)/i', $text, $m)) {
        $found = $found ?? strtoupper($m[1]);
        $text = preg_replace('/(?:\s*[,;.\-]?\s*)?(?:DF|DCF|CF)-[A-Z0-9]{2,}(?:-[A-Z0-9]+)+/i', '', $text);
    }
    $text = trim(preg_replace('/\s{2,}/', ' ', $text), " \t,;.-");
    return ['address' => $text, 'freezer_id' => $found];
};
$rawStoreAddress = trim((string)($freezer['store_address'] ?? ''));
$heroAddressParts = $splitAddressFreezerId($rawStoreAddress, $freezer['unique_freezer_id'] ?? null);
$lineAddressParts = $splitAddressFreezerId($storeAddressLine, $freezer['unique_freezer_id'] ?? null);
$valOrDash = static function ($v): string {
    $s = trim((string)($v ?? ''));
    return $s !== '' ? $s : '—';
};
/** Title-case place names (WEST/pune → West/Pune); leave codes/numbers as-is */
$displayTitleCase = static function ($v): string {
    $s = trim((string)($v ?? ''));
    if ($s === '' || $s === '—') {
        return $s === '' ? '—' : $s;
    }
    return preg_match('/^[A-Za-z\s\-]+$/', $s) ? ucwords(strtolower($s)) : $s;
};
$cleanHistAddress = static function ($addr) use ($splitAddressFreezerId, $freezer): string {
    $parts = $splitAddressFreezerId(trim((string)($addr ?? '')), $freezer['unique_freezer_id'] ?? null);
    $a = trim((string)($parts['address'] ?? ''));
    return $a !== '' ? $a : '—';
};

$activeTab = $_GET['tab'] ?? 'overview';
if ($activeTab === 'documents' || $activeTab === 'location') {
    $activeTab = 'overview'; // Location merged into Overview map card
}
$allowedTabs = ['overview', 'freezers', 'history'];
if (AppConfig::ELIXIA_VTS_ENABLED) {
    $allowedTabs[] = 'elixia';
}
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}

$isTechnician = (($currentUser['role'] ?? '') === AppConfig::ROLE_TECHNICIAN);
$pendingEdit = null;
$myPendingEdit = null;
$editUnlock = null;
$lastEditDecision = null;
try {
    $pendingEdit = AppNotification::findPendingProfileEdit((int)$freezer['id']);
    if ($isTechnician && !empty($currentUser['id'])) {
        $uid = (int)$currentUser['id'];
        $myPendingEdit = AppNotification::findPendingProfileEditForUser((int)$freezer['id'], $uid);
        $editUnlock = AppNotification::findActiveEditUnlock((int)$freezer['id'], $uid);
        if (!$editUnlock && !$myPendingEdit) {
            $decided = AppNotification::findLatestDecidedEditForUser((int)$freezer['id'], $uid);
            if ($decided && (string)($decided['status'] ?? '') === 'rejected') {
                $when = strtotime((string)($decided['reviewed_at'] ?? $decided['created_at'] ?? ''));
                if ($when && (time() - $when) <= 7 * 86400) {
                    $lastEditDecision = $decided;
                }
            }
        }
    }
} catch (Throwable $e) {
    $pendingEdit = null;
    $myPendingEdit = null;
    $editUnlock = null;
    $lastEditDecision = null;
}
$editBtnLabel = 'Edit';
if ($isTechnician && !$editUnlock) {
    if ($myPendingEdit) {
        $editBtnLabel = 'Edit pending';
    } elseif ($pendingEdit) {
        $editBtnLabel = 'Edit locked';
    } else {
        $editBtnLabel = 'Request edit';
    }
}
$pageTitle = 'Store Profile: ' . ($freezer['outlet_id'] ?? $freezer['store_name']);
$bodyClass = $isTechnician ? 'field-app-page field-shell-page' : 'app-profile-page';
require_once __DIR__ . '/includes/header.php';

$homeHref = $isTechnician ? 'field_home.php' : ($isAdminUser ? 'index.php' : 'profiles.php');
$galleryPhotos = [
    ['path' => $freezer['image_store_front'] ?? '', 'label' => 'Store front'],
    ['path' => $freezer['image_left_side'] ?? '', 'label' => 'Freezer full'],
    ['path' => $freezer['image_aadhar_card'] ?? '', 'label' => 'Aadhaar front'],
    ['path' => $freezer['image_aadhar_back'] ?? '', 'label' => 'Aadhaar back'],
    ['path' => $freezer['image_sim'] ?? '', 'label' => 'SIM'],
];
?>

<nav class="sp-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= htmlspecialchars($homeHref) ?>">Home</a>
    <span>/</span>
    <a href="profiles.php">Assets</a>
    <span>/</span>
    <strong><?= htmlspecialchars($freezer['outlet_id'] ?? '—') ?></strong>
</nav>

<div class="sp-actions field-actions-row">
    <a href="qr_scanner.php" class="btn btn-primary btn-sm"><span class="btn-label-full">Scan QR</span><span class="btn-label-short">Scan</span></a>
    <a href="qr_print.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-outline btn-sm" target="_blank"><span class="btn-label-full">Print QR</span><span class="btn-label-short">Print</span></a>
    <a href="profile_edit.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-secondary btn-sm"><?= htmlspecialchars($editBtnLabel) ?></a>
    <?php if (AuthService::hasRole('super_admin')): ?>
        <a href="profile_delete.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-danger btn-sm sp-actions-delete" title="Password required">
            <span class="btn-label-full">Delete</span><span class="btn-label-short">Del</span>
        </a>
    <?php endif; ?>
</div>

<?php if ($isTechnician): ?>
<div class="tech-sticky-bar no-print" aria-label="Technician actions">
    <a href="profile_edit.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-primary"><?= htmlspecialchars($editBtnLabel) ?></a>
    <a href="field_home.php" class="btn btn-secondary">Home</a>
</div>
<?php endif; ?>

<?php if (!empty($_GET['edit_pending'])): ?>
    <div class="alert alert-warning app-flash-toast-only" style="margin-bottom:1rem;" data-app-flash="Remark sent for Super Admin approval." data-app-flash-variant="success">
        Remark sent for Super Admin approval. After approval you can edit this profile.
    </div>
<?php endif; ?>
<?php if (!empty($_GET['edit_cancelled'])): ?>
    <div class="alert alert-success app-flash-toast-only" style="margin-bottom:1rem;" data-app-flash="Edit request cancelled. You can send a new remark anytime." data-app-flash-variant="success">
        Edit request cancelled. You can send a new remark anytime.
    </div>
<?php endif; ?>
<?php if (!empty($_GET['edit_locked'])): ?>
    <div class="alert alert-success app-flash-toast-only" style="margin-bottom:1rem;" data-app-flash="Edit locked again. Password will be required next time." data-app-flash-variant="success">
        Edit locked again. Password will be required next time.
    </div>
<?php endif; ?>
<?php if ($editUnlock && empty($_GET['edit_pending'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;">
        Edit access approved (1 save / 24h).
        <a href="profile_edit.php?id=<?= (int)$freezer['id'] ?>">Open edit form</a>
    </div>
<?php elseif ($myPendingEdit && empty($_GET['edit_pending'])): ?>
    <div class="alert alert-warning" style="margin-bottom:1rem;">
        Your edit remark is waiting for Super Admin approval.
        <a href="profile_edit.php?id=<?= (int)$freezer['id'] ?>">View / cancel request</a>
    </div>
<?php elseif ($pendingEdit && empty($_GET['edit_pending'])): ?>
    <div class="alert alert-warning" style="margin-bottom:1rem;">
        Edit request pending Super Admin approval
        <?php if (!empty($pendingEdit['created_by_name'])): ?>
            (by <?= htmlspecialchars((string)$pendingEdit['created_by_name']) ?>)
        <?php endif; ?>
        <?php if ($isAdminUser): ?>
            — <a href="notifications.php">Review now</a>
        <?php endif; ?>
    </div>
<?php elseif ($lastEditDecision): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem;">
        Your last edit request was rejected<?= !empty($lastEditDecision['review_notes']) ? ': ' . htmlspecialchars((string)$lastEditDecision['review_notes']) : '.' ?>
        You can send a new remark.
    </div>
<?php endif; ?>
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
<?php if (!empty($_GET['from_scan'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;">
        QR scan successful — this is the freezer profile.
    </div>
<?php endif; ?>

<!-- Store Profile hero (mockup) -->
<div class="sp-hero-grid">
    <div class="card sp-hero">
        <div class="sp-hero-media">
            <?php if ($storeFrontOk): ?>
                <img src="<?= htmlspecialchars($freezer['image_store_front']) ?>" alt="Store front" class="lightbox-trigger" loading="lazy" decoding="async" data-caption="<?= htmlspecialchars($freezer['store_name']) ?>">
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
            <?php
            $heroZone = trim((string)($freezer['store_zone'] ?? $freezer['zone'] ?? ''));
            $heroState = trim((string)($freezer['store_state'] ?? ''));
            $heroCity = trim((string)($freezer['store_city'] ?? ''));
            $heroPin = trim((string)($freezer['store_pincode'] ?? $freezer['pincode_last3'] ?? ''));
            ?>
            <div class="sp-details-grid">
                <!-- Row 1 -->
                <div><span>Owner / Operator</span><strong><?= htmlspecialchars(($freezer['contact_person'] ?? '') !== '' ? $freezer['contact_person'] : 'N/A') ?></strong></div>
                <div><span>Phone</span><strong><?= htmlspecialchars($viewPhone !== '' ? $viewPhone : 'N/A') ?></strong></div>
                <div><span>Email</span><strong><?= htmlspecialchars(($freezer['store_email'] ?? '') !== '' ? $freezer['store_email'] : 'N/A') ?></strong></div>
                <!-- Row 2 -->
                <div><span>Zone</span><strong><?= htmlspecialchars($heroZone !== '' ? $displayTitleCase($heroZone) : '—') ?></strong></div>
                <div><span>State</span><strong><?= htmlspecialchars($heroState !== '' ? $displayTitleCase($heroState) : '—') ?></strong></div>
                <div><span>City</span><strong><?= htmlspecialchars($heroCity !== '' ? $displayTitleCase($heroCity) : '—') ?></strong></div>
            </div>
            <div class="sp-details-grid sp-details-row-3">
                <div><span>PIN</span><strong class="profile-kv-mono"><?= htmlspecialchars($heroPin !== '' ? $heroPin : '—') ?></strong></div>
            </div>
            <div class="sp-address-only">
                <span>Address</span>
                <strong><?= htmlspecialchars($heroAddressParts['address'] !== '' ? $heroAddressParts['address'] : '—') ?></strong>
            </div>
            </div>
        </div>

    <div class="card sp-qr-card">
        <div class="card-header">
            <div class="card-title"><span>Asset QR</span></div>
        </div>
        <div class="card-body sp-qr-body">
            <div class="sp-qr-frame">
                <?php if ($qrPath && file_exists(__DIR__ . '/' . $qrPath)): ?>
                    <img src="<?= htmlspecialchars($qrPath) ?>" alt="QR Code">
                <?php else: ?>
                    <div class="sp-qr-missing">QR</div>
                <?php endif; ?>
            </div>
            <div class="sp-qr-details">
                <?php if ($profileAssetTag): ?>
                    <div class="sp-qr-detail">
                        <span>Tag code</span>
                        <strong class="profile-kv-mono"><?= htmlspecialchars($profileAssetTag['tag_code']) ?></strong>
                    </div>
                <?php endif; ?>
                <div class="sp-qr-detail">
                    <span>Unique Freezer ID</span>
                    <strong class="profile-kv-mono"><?= htmlspecialchars($freezer['unique_freezer_id']) ?></strong>
                </div>
            </div>
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
        'overview' => 'Store info',
        'freezers' => 'Freezers (' . count($storeFreezers) . ')',
        'history' => 'History',
    ];
    if (AppConfig::ELIXIA_VTS_ENABLED) {
        $tabs['elixia'] = 'Freezer location';
    }
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
            <a href="profile_edit.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-sm btn-outline"><?= htmlspecialchars($editBtnLabel) ?></a>
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
                <div class="profile-kv profile-kv-wide">
                    <span class="profile-kv-label">Address</span>
                    <strong class="profile-kv-value"><?= htmlspecialchars($lineAddressParts['address'] !== '' ? $lineAddressParts['address'] : '—') ?></strong>
                </div>
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
                    <p class="sp-current-loc-empty">No current location saved for this store yet.</p>
        <?php endif; ?>
    </div>
                </div>
            </div>

    <div class="sp-side-stack">
        <div class="card">
            <div class="card-header"><div class="card-title"><span>Store status</span></div></div>
            <div class="card-body">
                <div class="profile-kv-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="profile-kv">
                        <span class="profile-kv-label">Store / unit status</span>
                        <?php if (($freezer['status'] ?? '') === 'Active'): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-warning"><?= htmlspecialchars($freezer['status'] ?? '—') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="profile-kv">
                        <span class="profile-kv-label">Install date</span>
                        <strong class="profile-kv-value"><?= htmlspecialchars((string)($freezer['installation_date'] ?? '—')) ?></strong>
                    </div>
                </div>
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
        <div class="card-header">
            <div class="card-title"><span>Location on map</span></div>
            <?php if (!empty($freezer['store_latitude'])): ?>
                <a href="https://maps.google.com/?q=<?= urlencode($freezer['store_latitude'] . ',' . $freezer['store_longitude']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">Open Maps</a>
            <?php endif; ?>
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
                <p style="color:#94a3b8; font-size:0.875rem; margin:0;">No GPS location saved for this store yet.</p>
            <?php endif; ?>
                    </div>
                </div>

    <div class="card tech-gallery-card">
        <div class="card-header"><div class="card-title"><span>Photo gallery</span></div></div>
        <div class="card-body">
            <div class="sp-photo-row sp-photo-row-all tech-photo-gallery">
                <?php foreach ($galleryPhotos as $ph):
                    $ok = !empty($ph['path']) && file_exists(__DIR__ . '/' . $ph['path']);
                ?>
                    <div class="sp-photo-tile <?= $ok ? 'has-photo' : '' ?>">
                        <?php if ($ok): ?>
                            <img src="<?= htmlspecialchars($ph['path']) ?>" alt="<?= htmlspecialchars($ph['label']) ?>" class="lightbox-trigger" loading="lazy" decoding="async" data-caption="<?= htmlspecialchars($ph['label']) ?>">
                            <div class="sp-photo-caption"><?= htmlspecialchars($ph['label']) ?></div>
                        <?php else: ?>
                            <div class="sp-photo-empty"><?= htmlspecialchars($ph['label']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
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
                <div class="profile-kv profile-kv-wide">
                    <span class="profile-kv-label">Unique Freezer ID</span>
                    <strong class="profile-kv-value"><code class="sp-freezer-id-code"><?= htmlspecialchars($valOrDash($freezer['unique_freezer_id'] ?? '')) ?></code></strong>
                </div>
                <div class="profile-kv"><span class="profile-kv-label">Type</span><strong class="profile-kv-value"><?= htmlspecialchars(trim(($freezer['freezer_type'] ?? '') . (!empty($freezer['type_name']) ? ' — ' . $freezer['type_name'] : '')) ?: '—') ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Brand</span><strong class="profile-kv-value"><?= htmlspecialchars(trim(($freezer['company_code'] ?? '') . (!empty($freezer['brand_name']) ? ' — ' . $freezer['brand_name'] : '')) ?: '—') ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Model</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['model_number'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Capacity</span><strong class="profile-kv-value"><?= !empty($freezer['capacity_litres']) ? htmlspecialchars((string)$freezer['capacity_litres']) . ' L' : '—' ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Location code</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['location_code'] ?? '')) ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Install</span><strong class="profile-kv-value"><?= htmlspecialchars(trim(($freezer['installation_date'] ?? '') . (!empty($freezer['install_month_year']) ? ' (' . $freezer['install_month_year'] . ')' : '')) ?: '—') ?></strong></div>
                <?php
                $installerDisplay = trim((string)($freezer['created_by_name'] ?? ''));
                if ($installerDisplay === '' && !empty($freezer['created_by'])) {
                    try {
                        $iu = \App\Models\User::findById((int)$freezer['created_by']);
                        $installerDisplay = trim((string)($iu['name'] ?? ''));
                    } catch (Throwable $e) {
                        $installerDisplay = '';
                    }
                }
                ?>
                <div class="profile-kv"><span class="profile-kv-label">Installed by</span><strong class="profile-kv-value"><?= htmlspecialchars($installerDisplay !== '' ? $installerDisplay : '—') ?></strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Condition</span><span class="badge badge-neutral"><?= htmlspecialchars($valOrDash($freezer['freezer_condition'] ?? '')) ?></span></div>
                <div class="profile-kv profile-kv-wide"><span class="profile-kv-label">Freezer kept at</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['freezer_placed_at'] ?? '')) ?></strong></div>
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
                    <div class="profile-kv"><span class="profile-kv-label">SIM</span><strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($valOrDash($freezer['iot_sim_number'] ?? $freezer['sim_number'] ?? '')) ?></strong></div>
                    <div class="profile-kv"><span class="profile-kv-label">SIM company</span><strong class="profile-kv-value"><?= htmlspecialchars($valOrDash($freezer['iot_sim_company'] ?? $freezer['sim_company'] ?? '')) ?></strong></div>
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

<?php elseif ($activeTab === 'history'): ?>
<?php
$exportBase = 'profile_export.php?id=' . urlencode($freezer['unique_freezer_id']);
$tagCodeHist = $profileAssetTag['tag_code'] ?? '—';
$isAdmin = AuthService::hasRole('super_admin');
$activityLog = FreezerLifecycle::buildActivityLog($freezer);
$activityRowClass = static function (string $key): string {
    return match ($key) {
        'installation' => 'excel-row-install',
        'kyc_same_store' => 'excel-row-kyc-same',
        'kyc_new_store' => 'excel-row-kyc-new',
        'kyc_rejected' => 'excel-row-kyc-rejected',
        'store_move_new', 'store_move' => 'excel-row-move',
        default => '',
    };
};
?>
<!-- ========== Store activity (single table) ========== -->
<div class="card sp-history-card" id="store-history">
    <div class="card-header sp-history-header">
        <div>
            <div class="card-title"><span>Store activity</span></div>
            <p class="excel-sheet-sub" style="margin:0.35rem 0 0;">
                Installation and store moves — one table.
            </p>
        </div>
        <div class="sp-history-actions">
            <?php if ($isAdmin): ?>
                <a href="<?= $exportBase ?>&sheet=activity&format=csv" class="btn btn-outline btn-sm">Export CSV</a>
                <a href="<?= $exportBase ?>&sheet=activity&format=xlsx" class="btn btn-outline btn-sm">Export Excel</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="excel-legend">
            <span><i class="excel-swatch" style="background:#dbeafe;"></i> Installation</span>
            <span><i class="excel-swatch" style="background:#fef3c7;"></i> Store move</span>
            <span>Freezer: <code><?= htmlspecialchars($freezer['unique_freezer_id']) ?></code></span>
            <span>Tag: <code><?= htmlspecialchars($tagCodeHist) ?></code></span>
        </div>

        <div class="excel-wrap">
            <table class="excel-sheet">
                <thead>
                    <tr>
                        <th class="excel-col-index">#</th>
                        <th>Date</th>
                        <th>Activity</th>
                        <th>Store ID</th>
                        <th>Store name</th>
                        <th>Address</th>
                        <th>City</th>
                        <th>PIN</th>
                        <th>Result</th>
                        <th>Cycle</th>
                        <th>Reason / notes</th>
                        <th>Technician</th>
                        <th>Photos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($activityLog): ?>
                        <?php $aNum = 1; foreach ($activityLog as $ev):
                            $reasonNotes = trim(implode(' · ', array_filter([
                                trim((string)($ev['reason'] ?? '')),
                                trim((string)($ev['notes'] ?? '')),
                            ], static fn($x) => $x !== '' && $x !== '—')));
                            $photos = [];
                            if (($ev['photo_store'] ?? '') === 'Yes') {
                                $photos[] = 'Store';
                            }
                            if (($ev['photo_freezer'] ?? '') === 'Yes') {
                                $photos[] = 'Freezer';
                            }
                            $cells = [
                                (string)$aNum++,
                                (string)($ev['date'] ?: '—'),
                                (string)($ev['activity'] ?: '—'),
                                (string)($ev['store_id'] ?: '—'),
                                (string)($ev['store_name'] ?: '—'),
                                $cleanHistAddress($ev['address'] ?? ''),
                                !empty($ev['city']) ? $displayTitleCase($ev['city']) : '—',
                                (string)($ev['pincode'] ?: '—'),
                                (string)($ev['result'] ?: '—'),
                                (string)($ev['cycle'] ?: '—'),
                                $reasonNotes !== '' ? $reasonNotes : '—',
                                (string)($ev['verified_by'] ?: '—'),
                                $photos ? implode('+', $photos) : '—',
                            ];
                            $rowCls = $activityRowClass((string)($ev['activity_key'] ?? ''));
                        ?>
                            <tr class="<?= htmlspecialchars($rowCls) ?>">
                                <?php foreach ($cells as $i => $val): ?>
                                    <td class="<?= $i === 0 ? 'excel-col-index' : (($i === 3 || $i === 7) ? 'excel-mono' : '') ?>" title="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($val) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="13" class="excel-cell-empty">No activity yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$editAuditLogs = [];
try {
    $editAuditLogs = Freezer::listAuditForFreezer((int)$freezer['id'], 25);
} catch (Throwable $e) {
    $editAuditLogs = [];
}
?>
<div class="card sp-history-card" id="edit-audit" style="margin-top:1rem;">
    <div class="card-header sp-history-header">
        <div>
            <div class="card-title"><span>Edit audit</span></div>
            <p class="excel-sheet-sub" style="margin:0.35rem 0 0;">
                Who changed this profile, when, and what fields changed. Also Approve / Reject decisions.
            </p>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($editAuditLogs)): ?>
            <p style="margin:0;padding:1rem;color:#94a3b8;">No edit audit entries yet.</p>
        <?php else: ?>
            <div class="logs-table-wrap" style="max-height:360px;">
                <table class="logs-excel notif-excel">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Who</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($editAuditLogs as $a):
                            $act = strtoupper((string)($a['action'] ?? ''));
                            $actClass = $act === 'UPDATE' ? 'badge-primary'
                                : ($act === 'APPROVE' ? 'badge-success'
                                : ($act === 'REJECT' ? 'badge-danger' : 'badge-secondary'));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($a['created_at'] ?? '—')) ?></td>
                                <td><?= htmlspecialchars((string)(($a['user_name'] ?? '') !== '' ? $a['user_name'] : '—')) ?></td>
                                <td><span class="badge badge-sm <?= $actClass ?>"><?= htmlspecialchars($act !== '' ? $act : '—') ?></span></td>
                                <td title="<?= htmlspecialchars((string)($a['details'] ?? '')) ?>">
                                    <?= htmlspecialchars((string)(($a['details'] ?? '') !== '' ? $a['details'] : '—')) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($activeTab === 'elixia' && AppConfig::ELIXIA_VTS_ENABLED): ?>
<?php
$elixiaUniqueId = (string)($freezer['unique_freezer_id'] ?? '');
?>
<div class="card" id="elixia-live-panel">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
        <div>
            <div class="card-title"><span>Freezer location</span></div>
            <p class="excel-sheet-sub" style="margin:0.35rem 0 0;">
                Live GPS / status / temperature from Elixia VTS for this freezer only — separate from store info.
            </p>
        </div>
        <button type="button" class="btn btn-sm btn-outline" id="elixia-refresh-btn">Refresh</button>
    </div>
    <div class="card-body">
        <div class="profile-kv-grid" style="margin-bottom:1rem;">
            <div class="profile-kv">
                <span class="profile-kv-label">Matched by Unique Freezer ID</span>
                <strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($elixiaUniqueId) ?></strong>
            </div>
            <div class="profile-kv">
                <span class="profile-kv-label">Source</span>
                <strong class="profile-kv-value">Elixia VTS API</strong>
            </div>
        </div>

        <div id="elixia-live-status" class="alert" style="margin-bottom:1rem;">Loading Elixia data…</div>

        <div id="elixia-live-body" hidden>
            <div class="profile-kv-grid" id="elixia-kv-grid">
                <div class="profile-kv"><span class="profile-kv-label">Vehicle / Freezer no.</span><strong class="profile-kv-value profile-kv-mono" data-elixia="vehicleno">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Unit no.</span><strong class="profile-kv-value profile-kv-mono" data-elixia="unitno">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Status</span><strong class="profile-kv-value" data-elixia="status">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Ignition</span><strong class="profile-kv-value" data-elixia="ignition">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Temperature 1</span><strong class="profile-kv-value" data-elixia="temperature1">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Temperature 2</span><strong class="profile-kv-value" data-elixia="temperature2">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Latitude</span><strong class="profile-kv-value profile-kv-mono" data-elixia="lat">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Longitude</span><strong class="profile-kv-value profile-kv-mono" data-elixia="lng">—</strong></div>
                <div class="profile-kv profile-kv-wide"><span class="profile-kv-label">Location</span><strong class="profile-kv-value" data-elixia="location">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Last updated</span><strong class="profile-kv-value" data-elixia="lastupdated">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Timestamp</span><strong class="profile-kv-value" data-elixia="timestamp">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Door sensor 1</span><strong class="profile-kv-value" data-elixia="doorSensor1">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Door sensor 2</span><strong class="profile-kv-value" data-elixia="doorSensor2">—</strong></div>
                <div class="profile-kv"><span class="profile-kv-label">Odometer</span><strong class="profile-kv-value" data-elixia="odometer">—</strong></div>
            </div>
            <div id="elixia-maps-row" style="margin-top:1rem;" hidden>
                <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-primary" id="elixia-maps-link">Open in Google Maps</a>
                <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline" id="elixia-short-link" hidden>Elixia short link</a>
            </div>
            <div id="elixia-live-map" style="height:280px;margin-top:1rem;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;" hidden></div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const uniqueId = <?= json_encode($elixiaUniqueId, JSON_UNESCAPED_UNICODE) ?>;
    const statusEl = document.getElementById('elixia-live-status');
    const bodyEl = document.getElementById('elixia-live-body');
    const refreshBtn = document.getElementById('elixia-refresh-btn');
    let elixiaMap = null;
    let elixiaMarker = null;

    function setKv(key, value) {
        const el = document.querySelector('[data-elixia="' + key + '"]');
        if (!el) return;
        const text = (value === null || value === undefined || value === '') ? '—' : String(value);
        el.textContent = text;
    }

    function renderDevice(device) {
        bodyEl.hidden = false;
        ['vehicleno','unitno','status','ignition','temperature1','temperature2','location','lastupdated','timestamp','doorSensor1','doorSensor2','odometer'].forEach(function (k) {
            setKv(k, device[k]);
        });
        setKv('lat', device.lat != null ? Number(device.lat).toFixed(6) : '');
        setKv('lng', device.lng != null ? Number(device.lng).toFixed(6) : '');

        const mapsRow = document.getElementById('elixia-maps-row');
        const mapsLink = document.getElementById('elixia-maps-link');
        const shortLink = document.getElementById('elixia-short-link');
        const mapBox = document.getElementById('elixia-live-map');

        if (device.lat != null && device.lng != null && !isNaN(device.lat) && !isNaN(device.lng)) {
            mapsRow.hidden = false;
            mapsLink.href = 'https://maps.google.com/?q=' + encodeURIComponent(device.lat + ',' + device.lng);
            mapBox.hidden = false;
            if (typeof L !== 'undefined') {
                if (!elixiaMap) {
                    elixiaMap = L.map(mapBox).setView([device.lat, device.lng], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap'
                    }).addTo(elixiaMap);
                    elixiaMarker = L.marker([device.lat, device.lng]).addTo(elixiaMap);
                } else {
                    elixiaMap.setView([device.lat, device.lng], 15);
                    elixiaMarker.setLatLng([device.lat, device.lng]);
                }
                setTimeout(function () { elixiaMap.invalidateSize(); }, 80);
            }
        } else {
            mapsRow.hidden = true;
            mapBox.hidden = true;
        }

        if (device.shorturl) {
            shortLink.hidden = false;
            shortLink.href = device.shorturl;
        } else {
            shortLink.hidden = true;
        }
    }

    function loadElixia(force) {
        statusEl.className = 'alert';
        statusEl.textContent = 'Loading Elixia data…';
        bodyEl.hidden = true;
        refreshBtn.disabled = true;

        const url = 'api/elixia_live.php?unique_id=' + encodeURIComponent(uniqueId) + (force ? '&refresh=1' : '');
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                refreshBtn.disabled = false;
                if (!data || !data.ok) {
                    statusEl.className = 'alert alert-danger';
                    statusEl.textContent = (data && data.error) ? data.error : 'Could not load Elixia data.';
                    return;
                }
                if (!data.found || !data.device) {
                    statusEl.className = 'alert alert-warning';
                    statusEl.textContent = 'No Elixia device matched this Unique Freezer ID yet.';
                    return;
                }
                statusEl.className = 'alert alert-success';
                statusEl.textContent = 'Live match found in Elixia VTS.';
                renderDevice(data.device);
            })
            .catch(function () {
                refreshBtn.disabled = false;
                statusEl.className = 'alert alert-danger';
                statusEl.textContent = 'Network error while calling Elixia API.';
            });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () { loadElixia(true); });
    }
    loadElixia(false);
});
</script>
<?php endif; ?>

<!-- Map Script for Store View -->
<?php if (!empty($freezer['store_latitude']) && $activeTab === 'overview'): ?>
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
