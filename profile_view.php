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
    $allowedTabs[] = 'freezer_location';
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
        'overview' => 'Store profile',
    ];
    if (AppConfig::ELIXIA_VTS_ENABLED) {
        $tabs['freezer_location'] = 'Freezer location';
    }
    $tabs['freezers'] = 'Freezers (' . count($storeFreezers) . ')';
    $tabs['history'] = 'History';
    $baseUrl = 'profile_view.php?id=' . urlencode($freezer['unique_freezer_id']);
    foreach ($tabs as $key => $label):
    ?>
        <a href="<?= $baseUrl ?>&tab=<?= $key ?><?= $key === 'history' ? '#store-history' : '' ?>"
           class="sp-tab <?= $activeTab === $key ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
                    </div>

<?php
$renderProfileSection = static function (string $title, array $rows, ?string $headerRightHtml = null): void {
    ?>
    <section class="sp-pro-section">
        <div class="sp-pro-section-head">
            <h3 class="sp-pro-section-title"><?= htmlspecialchars($title) ?></h3>
            <?php if ($headerRightHtml !== null && $headerRightHtml !== ''): ?>
                <div class="sp-pro-section-actions"><?= $headerRightHtml ?></div>
            <?php endif; ?>
        </div>
        <dl class="sp-pro-grid">
            <?php foreach ($rows as $row):
                $label = (string)($row[0] ?? '');
                $value = $row[1] ?? '—';
                $isHtml = !empty($row[2]);
                $wide = !empty($row[3]);
            ?>
                <div class="sp-pro-item<?= $wide ? ' is-wide' : '' ?>">
                    <dt><?= htmlspecialchars($label) ?></dt>
                    <dd>
                        <?php if ($isHtml): ?>
                            <?= $value ?>
                        <?php else: ?>
                            <?= htmlspecialchars((string)$value) ?>
                        <?php endif; ?>
                    </dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>
    <?php
};

$statusBadge = (($freezer['status'] ?? '') === 'Active')
    ? '<span class="badge badge-success">Active</span>'
    : '<span class="badge badge-warning">' . htmlspecialchars((string)($freezer['status'] ?? '—')) . '</span>';

$tagStatusBadge = '';
if ($profileAssetTag) {
    $tagStatusBadge = (($profileAssetTag['status'] ?? '') === 'assigned')
        ? '<span class="badge badge-success">Assigned</span>'
        : '<span class="badge badge-primary">Unused</span>';
}

$gpsBadge = $hasGps
    ? '<span class="badge badge-success">Recorded</span>'
    : '<span class="badge badge-warning">Not set</span>';

$editBtn = '<a href="profile_edit.php?id=' . (int)$freezer['id'] . '" class="btn btn-sm btn-outline">' . htmlspecialchars($editBtnLabel) . '</a>';

$machineType = trim(($freezer['freezer_type'] ?? '') . (!empty($freezer['type_name']) ? ' — ' . $freezer['type_name'] : '')) ?: '—';
$machineBrand = trim(($freezer['company_code'] ?? '') . (!empty($freezer['brand_name']) ? ' — ' . $freezer['brand_name'] : '')) ?: '—';
$machineCap = !empty($freezer['capacity_litres']) ? ((string)$freezer['capacity_litres'] . ' L') : '—';
$installCombined = trim(($freezer['installation_date'] ?? '') . (!empty($freezer['install_month_year']) ? ' (' . $freezer['install_month_year'] . ')' : '')) ?: '—';

$installerDisplay = trim((string)($freezer['created_by_name'] ?? ''));
if ($installerDisplay === '' && !empty($freezer['created_by'])) {
    try {
        $iu = \App\Models\User::findById((int)$freezer['created_by']);
        $installerDisplay = trim((string)($iu['name'] ?? ''));
    } catch (Throwable $e) {
        $installerDisplay = '';
    }
}
$storeZoneDisp = $displayTitleCase(trim((string)($freezer['store_zone'] ?? $freezer['zone'] ?? '')));
$storeCityDisp = $displayTitleCase(trim((string)($freezer['store_city'] ?? '')));
$storeStateDisp = $displayTitleCase(trim((string)($freezer['store_state'] ?? '')));
$storeAddrDisp = $lineAddressParts['address'] !== '' ? $lineAddressParts['address'] : '—';
if ($hasGps) {
    $storeMapsLink = trim((string)($freezer['store_location_link'] ?? ''));
    if ($storeMapsLink === '') {
        $storeMapsLink = 'https://maps.google.com/?q=' . rawurlencode($freezer['store_latitude'] . ',' . $freezer['store_longitude']);
    }
}
?>

<?php if ($activeTab === 'overview'): ?>
<div class="sp-pro-stack">
<?php
$renderProfileSection('Store', [
    ['Store name', $valOrDash($freezer['store_name'] ?? '')],
    ['Outlet ID', $valOrDash($freezer['outlet_id'] ?? '')],
    ['Customer', $valOrDash($freezer['customer_name'] ?? '')],
    ['Distributor', $valOrDash($freezer['distributor_name'] ?? '')],
    ['Location type', $valOrDash($freezer['location_type'] ?? 'Retail Store')],
    ['Contact', $valOrDash($freezer['contact_person'] ?? '')],
    ['Phone', $viewPhone !== '' ? $viewPhone : '—'],
    ['Email', $valOrDash($freezer['store_email'] ?? '')],
    ['Zone', $storeZoneDisp !== '' ? $storeZoneDisp : '—'],
    ['City', $storeCityDisp !== '' ? $storeCityDisp : '—'],
    ['State', $storeStateDisp !== '' ? $storeStateDisp : '—'],
    ['PIN', $valOrDash($freezer['store_pincode'] ?? '')],
    ['Address', $storeAddrDisp, false, true],
    ['Aadhaar', $aadharDisplay],
    ['FSSAI', $valOrDash($freezer['fssai_number'] ?? '')],
    ['GST', $valOrDash($freezer['gst_number'] ?? '')],
    ['Status', $statusBadge, true],
    ['Install date', (string)($freezer['installation_date'] ?? '—')],
    ['Installed by', $installerDisplay !== '' ? $installerDisplay : '—'],
    ['Freezers here', (string)count($storeFreezers)],
], $editBtn);

$tagGpsRows = [];
if ($profileAssetTag) {
    $tagGpsRows = array_merge($tagGpsRows, [
        ['Tag code', (string)$profileAssetTag['tag_code']],
        ['Tag status', $tagStatusBadge, true],
        ['Batch', $profileAssetTag['batch_label'] ?: '—'],
        ['Tag created', (string)($profileAssetTag['created_at'] ?? '—')],
    ]);
}
if ($hasGps) {
    $tagGpsRows = array_merge($tagGpsRows, [
        ['Latitude', number_format((float)$freezer['store_latitude'], 6)],
        ['Longitude', number_format((float)$freezer['store_longitude'], 6)],
        ['Accuracy', !empty($freezer['store_gps_accuracy']) ? ('± ' . (string)round((float)$freezer['store_gps_accuracy']) . ' m') : '—'],
        ['GPS captured', $valOrDash($freezer['store_gps_captured_at'] ?? '')],
        ['Maps', '<span class="sp-pro-maps-actions">'
            . '<a class="sp-pro-link" href="' . htmlspecialchars($storeMapsLink) . '" target="_blank" rel="noopener">Open Maps</a>'
            . '<button type="button" class="btn btn-sm btn-outline sp-pro-copy-btn" data-copy="' . htmlspecialchars($storeMapsLink) . '">Copy link</button>'
            . '</span>', true],
    ]);
} else {
    $tagGpsRows[] = ['GPS', 'Not set', false, true];
}
$renderProfileSection('QR & GPS', $tagGpsRows, $gpsBadge);

$freezerStatusLabel = trim((string)($freezer['status'] ?? 'Active'));
$freezerStatusBadge = (strcasecmp($freezerStatusLabel, 'Active') === 0)
    ? '<span class="badge badge-success">' . htmlspecialchars($freezerStatusLabel) . '</span>'
    : '<span class="badge badge-warning">' . htmlspecialchars($freezerStatusLabel !== '' ? $freezerStatusLabel : '—') . '</span>';

$freezerRows = [
    ['Unique Freezer ID', $valOrDash($freezer['unique_freezer_id'] ?? ''), false, true],
    ['Type', $machineType],
    ['Brand', $machineBrand],
    ['Model', $valOrDash($freezer['model_number'] ?? '')],
    ['Capacity', $machineCap],
    ['Location code', $valOrDash($freezer['location_code'] ?? '')],
    ['Kept at', $valOrDash($freezer['freezer_placed_at'] ?? '')],
    ['Install', $installCombined],
    ['Installed by', $installerDisplay !== '' ? $installerDisplay : '—'],
    ['Sequence', $valOrDash($freezer['unit_sequence'] ?? '')],
    ['SIM number', $valOrDash($freezer['sim_number'] ?? '')],
    ['SIM company', $valOrDash($freezer['sim_company'] ?? '')],
    ['Remark', $valOrDash($freezer['notes'] ?? ''), false, true],
];
if (AppConfig::IOT_ENABLED) {
    $freezerRows = array_merge($freezerRows, [
        ['IoT serial', $valOrDash($freezer['device_serial'] ?? '')],
        ['IMEI / MAC', $valOrDash($freezer['imei_or_mac'] ?? '')],
        ['IoT SIM', $valOrDash($freezer['iot_sim_number'] ?? $freezer['sim_number'] ?? '')],
        ['Power', $valOrDash($freezer['iot_power_status'] ?? '')],
        ['Last ping', $valOrDash($freezer['iot_last_ping'] ?? '')],
    ]);
}
$renderProfileSection('Freezer', $freezerRows, $freezerStatusBadge . ' <span class="badge badge-primary">' . htmlspecialchars((string)($freezer['freezer_type'] ?? 'DF')) . '</span>');
?>

    <section class="sp-pro-section">
        <div class="sp-pro-section-head">
            <h3 class="sp-pro-section-title">Photos</h3>
        </div>
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
    </section>

    <section class="sp-pro-section">
        <div class="sp-pro-section-head">
            <h3 class="sp-pro-section-title">Associated freezers</h3>
            <div class="sp-pro-section-actions"><span class="badge badge-secondary"><?= count($storeFreezers) ?></span></div>
        </div>
        <ul class="sp-pro-list">
            <?php foreach ($storeFreezers as $sf): ?>
                <li class="<?= (int)$sf['id'] === (int)$freezer['id'] ? 'is-current' : '' ?>">
                    <a href="profile_view.php?id=<?= urlencode($sf['unique_freezer_id']) ?>">
                        <strong><?= htmlspecialchars($sf['unique_freezer_id']) ?></strong>
                        <span><?= htmlspecialchars(trim(($sf['company_code'] ?? '') . ' · ' . ($sf['model_number'] ?? ''), ' ·')) ?></span>
                    </a>
                    <?php if (($sf['status'] ?? '') === 'Active'): ?>
                        <span class="badge badge-success">Active</span>
                    <?php else: ?>
                        <span class="badge badge-warning"><?= htmlspecialchars($sf['status'] ?? '—') ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="sp-pro-section">
        <div class="sp-pro-section-head">
            <h3 class="sp-pro-section-title">Recent activity</h3>
        </div>
        <?php if (empty($lifecycleTimeline)): ?>
            <p class="sp-pro-empty">No activity yet.</p>
        <?php else: ?>
            <ul class="sp-pro-timeline">
                <?php foreach (array_slice($lifecycleTimeline, 0, 8) as $ev): ?>
                    <li>
                        <div class="sp-pro-timeline-dot"></div>
                        <div>
                            <strong><?= htmlspecialchars((string)($ev['title'] ?? $ev['type'] ?? 'Event')) ?></strong>
                            <p>
                                <?= htmlspecialchars((string)($ev['sort_date'] ?? $ev['date'] ?? '')) ?>
                                <?php if (!empty($ev['outlet_id'])): ?>
                                    · <?= htmlspecialchars((string)$ev['outlet_id']) ?>
                                <?php endif; ?>
                                <?php if (!empty($ev['store_name'])): ?>
                                    · <?= htmlspecialchars((string)$ev['store_name']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php elseif ($activeTab === 'freezer_location'): ?>
<?php
$profileUniqueId = (string)($freezer['unique_freezer_id'] ?? '');
$elixiaEnabled = AppConfig::ELIXIA_VTS_ENABLED;
$elixiaAutoRefresh = !empty($_GET['refresh']);
?>
<div class="sp-pro-stack">
    <section class="sp-pro-section sp-lazy-panel" id="elixia-lazy-panel"
             data-unique-id="<?= htmlspecialchars($profileUniqueId) ?>"
             data-enabled="<?= $elixiaEnabled ? '1' : '0' ?>"
             data-auto-refresh="<?= $elixiaAutoRefresh ? '1' : '0' ?>">
        <div class="sp-pro-section-head">
            <h3 class="sp-pro-section-title">Elixia API</h3>
            <div class="sp-pro-section-actions" data-elixia-actions>
                <button type="button" class="btn btn-sm btn-outline" data-elixia-refresh disabled>Refresh</button>
            </div>
        </div>
        <div class="sp-lazy-body" data-elixia-body>
            <div class="sp-lazy-loading" role="status" aria-live="polite">
                <div class="sp-lazy-spinner" aria-hidden="true"></div>
                <p>Loading live API data…</p>
                <div class="sp-lazy-skeleton">
                    <span></span><span></span><span></span><span></span><span></span><span></span>
                </div>
            </div>
        </div>
    </section>
</div>
<script>
(function () {
    var panel = document.getElementById('elixia-lazy-panel');
    if (!panel) return;

    var body = panel.querySelector('[data-elixia-body]');
    var actions = panel.querySelector('[data-elixia-actions]');
    var uniqueId = panel.getAttribute('data-unique-id') || '';
    var enabled = panel.getAttribute('data-enabled') === '1';
    var loading = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function dash(v) {
        if (v === null || v === undefined) return '—';
        if (typeof v === 'boolean') return v ? 'Yes' : 'No';
        var s = String(v).trim();
        return s !== '' ? s : '—';
    }
    function ignLabel(v) {
        if (v === null || v === undefined || v === '') return '—';
        if (String(v) === '1' || v === 1 || v === true) return 'ON';
        if (String(v) === '0' || v === 0 || v === false) return 'OFF';
        return String(v);
    }
    function idleLabel(idleSec) {
        if (idleSec === null || idleSec === undefined || idleSec === '') return '—';
        var sec = parseInt(idleSec, 10) || 0;
        var d = Math.floor(sec / 86400);
        var h = Math.floor((sec % 86400) / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var parts = [];
        if (d > 0) parts.push(d + 'd');
        if (h > 0) parts.push(h + 'h');
        if (m > 0 || !parts.length) parts.push(m + 'm');
        return parts.join(' ');
    }
    function itemHtml(label, valueHtml, wide) {
        return '<div class="sp-pro-item' + (wide ? ' is-wide' : '') + '">'
            + '<dt>' + esc(label) + '</dt><dd>' + valueHtml + '</dd></div>';
    }
    function textItem(label, value, wide) {
        return itemHtml(label, esc(dash(value)), !!wide);
    }
    function showLoading() {
        body.innerHTML = '<div class="sp-lazy-loading" role="status" aria-live="polite">'
            + '<div class="sp-lazy-spinner" aria-hidden="true"></div>'
            + '<p>Loading live API data…</p>'
            + '<div class="sp-lazy-skeleton"><span></span><span></span><span></span><span></span><span></span><span></span></div>'
            + '</div>';
        if (actions) {
            actions.innerHTML = '<span class="badge badge-secondary">Fetching…</span>'
                + ' <button type="button" class="btn btn-sm btn-outline" data-elixia-refresh disabled>Refresh</button>';
        }
    }
    function bindRefresh() {
        var btn = panel.querySelector('[data-elixia-refresh]');
        if (btn) btn.addEventListener('click', function () { load(true); });
    }
    function renderError(msg, triedFresh) {
        if (actions) {
            actions.innerHTML = '<button type="button" class="btn btn-sm btn-outline" data-elixia-refresh>Refresh</button>'
                + (triedFresh ? ' <span class="badge badge-warning">Tried fresh</span>' : '');
        }
        body.innerHTML = '<dl class="sp-pro-grid">' + textItem('status', msg || 'No API data', true) + '</dl>';
        bindRefresh();
    }
    function renderDevice(device, active, fresh) {
        var lat = device.lat;
        var lng = device.lng;
        var hasCoords = lat !== null && lat !== undefined && lng !== null && lng !== undefined && lat !== '' && lng !== '';
        var maps = hasCoords
            ? ('https://maps.google.com/?q=' + encodeURIComponent(String(lat) + ',' + String(lng)))
            : '';
        var lastUpdated = dash(device.lastupdated);
        var activeBadge = active
            ? '<span class="badge badge-success">Active</span>'
            : '<span class="badge badge-warning">Inactive</span>';

        if (actions) {
            actions.innerHTML = activeBadge
                + ' <span class="badge badge-secondary">Last update: ' + esc(lastUpdated) + '</span>'
                + ' <button type="button" class="btn btn-sm btn-outline" data-elixia-refresh>Refresh</button>'
                + (fresh ? ' <span class="badge badge-success">Fresh</span>' : '');
        }

        var preferred = [
            'vehicleno','vehicleid','unitno','kind','groupname','type','deviceId','status','status_new',
            'vehicleMotionStatus','deviceTrackingState','ignition','vehiclespeed','idleSince','digitalstatus',
            'drivername','temperature1','temperature2','temperature3','temperature4','doorSensor1','doorSensor2',
            'lat','lng','location','direction','odometer','distance','lastupdated','timestamp','utctimestamp',
            'systemtime','utcsystemtime','shorturl'
        ];
        var wideKeys = { vehicleno: 1, location: 1, shorturl: 1, lastupdated: 1 };
        var html = '';
        html += itemHtml('Active', activeBadge, false);
        html += textItem('Last update', lastUpdated, true);

        preferred.forEach(function (key) {
            var val;
            if (key === 'ignition') val = ignLabel(device[key]);
            else if (key === 'idleSince') val = idleLabel(device[key]);
            else if (key === 'lat') val = hasCoords ? Number(lat).toFixed(6) : '—';
            else if (key === 'lng') val = hasCoords ? Number(lng).toFixed(6) : '—';
            else val = dash(device[key]);
            html += textItem(key, val, !!wideKeys[key]);
        });

        if (maps) {
            html += itemHtml(
                'Maps URL',
                '<span class="sp-pro-maps-actions">'
                    + '<a class="sp-pro-link" href="' + esc(maps) + '" target="_blank" rel="noopener">Open Maps</a>'
                    + '<button type="button" class="btn btn-sm btn-outline sp-pro-copy-btn" data-copy="' + esc(maps) + '">Copy link</button>'
                    + '</span>',
                true
            );
        }

        Object.keys(device).forEach(function (key) {
            if (preferred.indexOf(key) !== -1) return;
            var val = device[key];
            if (val && typeof val === 'object') {
                try { val = JSON.stringify(val); } catch (e) { val = String(val); }
            }
            html += textItem(key, val, false);
        });

        body.innerHTML = '<dl class="sp-pro-grid">' + html + '</dl>';
        bindRefresh();
    }

    function load(forceRefresh) {
        if (loading) return;
        if (!enabled) {
            renderError('Elixia VTS is disabled.');
            return;
        }
        if (!uniqueId) {
            renderError('Missing Unique Freezer ID.');
            return;
        }
        loading = true;
        showLoading();
        bindRefresh();

        var url = 'api/elixia_live.php?unique_id=' + encodeURIComponent(uniqueId)
            + (forceRefresh ? '&refresh=1' : '');

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (res) { return res.json().then(function (data) { return { okHttp: res.ok, data: data }; }); })
            .then(function (pack) {
                loading = false;
                var data = pack.data || {};
                if (!data.ok) {
                    renderError(data.error || 'Could not load Elixia API data.', !!forceRefresh);
                    return;
                }
                if (!data.found || !data.device) {
                    renderError(data.error || 'No matching Freezer / Vehicle no. in Elixia API.', !!forceRefresh);
                    return;
                }
                renderDevice(data.device, !!data.active, !!data.fresh || !!forceRefresh);
                if (forceRefresh && window.history && window.history.replaceState) {
                    try {
                        var u = new URL(window.location.href);
                        u.searchParams.delete('refresh');
                        window.history.replaceState({}, '', u.pathname + u.search + u.hash);
                    } catch (e) {}
                }
            })
            .catch(function () {
                loading = false;
                renderError('Network error loading Elixia API.', !!forceRefresh);
            });
    }

    // Lazy: fetch when panel is near viewport (or immediately if already visible)
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                io.disconnect();
                load(panel.getAttribute('data-auto-refresh') === '1');
            });
        }, { rootMargin: '120px 0px' });
        io.observe(panel);
    } else {
        load(panel.getAttribute('data-auto-refresh') === '1');
    }
})();
</script>

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
