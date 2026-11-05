<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Services\KycScanGate;
use App\Models\Freezer;
use App\Models\AssetTag;
use App\Models\FreezerLifecycle;
use App\Models\MasterData;

AuthService::requireRole(['super_admin', 'technician']);
$currentUser = AuthService::currentUser();

$id = (int)($_GET['id'] ?? $_POST['freezer_id'] ?? 0);
$freezer = $id ? Freezer::findById($id) : null;
if (!$freezer) {
    die('<h1>Freezer not found</h1><p><a href="profiles.php">Back</a></p>');
}

FreezerLifecycle::ensureOpenPlacement($freezer);
$kycStatus = FreezerLifecycle::kycStatusForFreezer((int)$freezer['id']);
$cycle = $kycStatus['cycle'];
$error = null;
$csrfToken = AuthService::generateCsrfToken();

$assetTag = AssetTag::findByFreezerId((int)$freezer['id']);
$scanUnlocked = KycScanGate::check((int)$freezer['id']);
$kycScanUrl = KycScanGate::scannerUrl($freezer);

// Must scan this freezer's QR/Tag before KYC form (blocks list/profile shortcut)
if (!$scanUnlocked && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $pageTitle = 'KYC — Scan required';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div style="max-width:640px;margin:0 auto;">
        <div class="card">
            <div class="card-header">
                <div class="card-title"><span>Scan QR to start KYC</span></div>
            </div>
            <div class="card-body">
                <div class="alert alert-warning" style="margin-bottom:1rem;">
                    KYC sirf usi freezer pe ho sakti hai jiska QR / Tag aapne abhi scan kiya ho.
                    List ya profile se seedha KYC nahi khulegi.
                </div>
                <div class="profile-kv-grid" style="grid-template-columns:1fr 1fr;margin-bottom:1rem;">
                    <div class="profile-kv">
                        <span class="profile-kv-label">Unique Freezer ID</span>
                        <strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($freezer['unique_freezer_id']) ?></strong>
                    </div>
                    <div class="profile-kv">
                        <span class="profile-kv-label">Store</span>
                        <strong class="profile-kv-value"><?= htmlspecialchars($freezer['store_name'] ?? '—') ?></strong>
                    </div>
                    <?php if ($assetTag): ?>
                    <div class="profile-kv profile-kv-wide">
                        <span class="profile-kv-label">Expected Tag</span>
                        <strong class="profile-kv-value profile-kv-mono"><?= htmlspecialchars($assetTag['tag_code']) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                    <a href="<?= htmlspecialchars($kycScanUrl) ?>" class="btn btn-primary">Scan QR / Tag</a>
                    <a href="profile_view.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-outline">Back to profile</a>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$scanUnlocked) {
        $error = 'Scan this freezer QR / Tag first, then submit KYC.';
    } elseif (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } elseif (!$kycStatus['due'] && $kycStatus['current']) {
        $nextDue = $kycStatus['next_due_at'] ?? '';
        $error = 'KYC for this store already done. Next due after '
            . (int)($kycStatus['gap_months'] ?? 6) . ' months'
            . ($nextDue !== '' ? ' (from ' . $nextDue . ')' : '') . '.';
    } else {
        try {
            $locationMode = $_POST['location_mode'] ?? 'same';
            $result = strtoupper(trim($_POST['result'] ?? 'PASS'));
            $newOutletId = null;
            $verifiedAt = trim($_POST['verified_at'] ?? '') ?: date('Y-m-d');

            if (!in_array($result, ['PASS', 'FAIL', 'PENDING'], true)) {
                throw new Exception('Select a valid verification result.');
            }

            // Confirm asset identity was checked
            if (empty($_POST['confirm_asset'])) {
                throw new Exception('Confirm Tag code / Unique Freezer ID match the physical unit.');
            }

            $kycTag = AssetTag::findByFreezerId((int)$freezer['id']);
            $imgNameMeta = [
                'tag_code' => $kycTag['tag_code'] ?? preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string)$freezer['unique_freezer_id'])),
                'store_name' => trim((string)($_POST['store_name'] ?? $freezer['store_name'] ?? 'Store')),
                'datetime' => date('Ymd'),
            ];

            // Location / move — same store or new outlet only
            if ($locationMode === 'new') {
                foreach (['store_name', 'address', 'city', 'pincode'] as $req) {
                    if (trim((string)($_POST[$req] ?? '')) === '') {
                        throw new Exception('New outlet: fill store name, address, city and PIN.');
                    }
                }
                $imgNameMeta['store_name'] = trim((string)$_POST['store_name']);

                $imgNewStore = null;
                $newStoreFile = !empty($_FILES['image_new_store_front']['name'])
                    ? $_FILES['image_new_store_front']
                    : (!empty($_FILES['image_store_front']['name']) ? $_FILES['image_store_front'] : null);
                if ($newStoreFile) {
                    $up = FileUploadService::handleImageUpload($newStoreFile, 'stores', 'store_front', $imgNameMeta);
                    if (!$up['success']) {
                        throw new Exception('New store photo: ' . $up['error']);
                    }
                    $imgNewStore = $up['path'];
                }

                $created = FreezerLifecycle::createNewOutlet([
                    'store_name' => $_POST['store_name'] ?? '',
                    'address' => $_POST['address'] ?? '',
                    'city' => $_POST['city'] ?? '',
                    'state' => $_POST['state'] ?? '',
                    'pincode' => $_POST['pincode'] ?? '',
                    'zone' => $_POST['zone'] ?? 'WEST',
                    'contact_person' => $_POST['contact_person'] ?? ($freezer['contact_person'] ?? ''),
                    'phone_number' => $_POST['phone_number'] ?? '',
                    'aadhar_number' => $_POST['aadhar_number'] ?? '',
                    'customer_name' => $freezer['customer_name'] ?? 'Baskin Robbins',
                    'latitude' => $_POST['latitude'] ?? null,
                    'longitude' => $_POST['longitude'] ?? null,
                    'gps_accuracy' => $_POST['gps_accuracy'] ?? null,
                    'gps_captured_at' => $_POST['gps_captured_at'] ?? null,
                    'location_link' => $_POST['location_link'] ?? null,
                    'image_store_front' => $imgNewStore,
                ], (int)$currentUser['id']);

                $newOutletId = $created['outlet_id'];

                FreezerLifecycle::transfer(
                    (int)$freezer['id'],
                    $created['store_id'],
                    'KYC — moved to new outlet (auto ID ' . $newOutletId . ')',
                    trim($_POST['notes'] ?? '') ?: null,
                    (int)$currentUser['id'],
                    $verifiedAt
                );
                $freezer = Freezer::findById((int)$freezer['id']);
            }

            $imgStore = null;
            $imgFrz = null;
            if ($locationMode !== 'new') {
                $imgNameMeta['store_name'] = trim((string)($freezer['store_name'] ?? 'Store'));
            }
            if ($locationMode !== 'new' && !empty($_FILES['image_store_front']['name'])) {
                $up = FileUploadService::handleImageUpload($_FILES['image_store_front'], 'stores', 'kyc_store', $imgNameMeta);
                if (!$up['success']) {
                    throw new Exception('Store photo: ' . $up['error']);
                }
                $imgStore = $up['path'];
            } elseif ($locationMode === 'new') {
                $imgStore = $imgNewStore ?? null;
            }
            if (!empty($_FILES['image_freezer']['name'])) {
                $up = FileUploadService::handleImageUpload($_FILES['image_freezer'], 'freezers', 'kyc_frz', $imgNameMeta);
                if (!$up['success']) {
                    throw new Exception('Freezer photo: ' . $up['error']);
                }
                $imgFrz = $up['path'];
            }

            // Proper PASS verification needs photo evidence
            if ($result === 'PASS' && (!$imgStore || !$imgFrz)) {
                throw new Exception('PASS requires both Store front photo and Freezer photo.');
            }

            FreezerLifecycle::recordKyc([
                'freezer_id' => (int)$freezer['id'],
                'verified_at' => $verifiedAt,
                'cycle_year' => (int)($_POST['cycle_year'] ?? $cycle['year']),
                'cycle_half' => (int)($_POST['cycle_half'] ?? $cycle['half']),
                'result' => $result,
                'notes' => trim($_POST['notes'] ?? '') ?: null,
                'image_store_front' => $imgStore,
                'image_freezer' => $imgFrz,
                'latitude' => $_POST['latitude'] ?? null,
                'longitude' => $_POST['longitude'] ?? null,
                'verified_by' => (int)$currentUser['id'],
            ]);

            KycScanGate::consume((int)$freezer['id']);
            \App\Services\AppLogger::audit('kyc.submit', 'KYC verification saved', [
                'freezer_id' => (int)$freezer['id'],
                'unique_freezer_id' => $freezer['unique_freezer_id'] ?? null,
                'result' => $result,
                'location_mode' => $locationMode,
            ]);
            $redir = 'profile_view.php?id=' . urlencode($freezer['unique_freezer_id']) . '&kyc=1';
            if ($newOutletId) {
                $redir .= '&new_outlet=' . urlencode($newOutletId);
            }
            if ($locationMode !== 'same') {
                $redir .= '&transferred=1';
            }
            header('Location: ' . $redir);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            \App\Services\AppLogger::failed('kyc.submit', $e->getMessage(), [
                'freezer_id' => (int)$freezer['id'],
                'unique_freezer_id' => $freezer['unique_freezer_id'] ?? null,
            ]);
            $kycStatus = FreezerLifecycle::kycStatusForFreezer((int)$freezer['id']);
        }
    }
}

$pageTitle = 'KYC Verification';
require_once __DIR__ . '/includes/header.php';
$locMode = ($_POST['location_mode'] ?? 'same') === 'new' ? 'new' : 'same';
$resultVal = $_POST['result'] ?? 'PASS';
?>

<div style="max-width: 920px; margin: 0 auto;">
    <div class="cfg-toolbar">
        <div class="cfg-toolbar-left">
            <h1 class="cfg-toolbar-title">KYC Verification</h1>
        </div>
        <a href="profile_view.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-secondary btn-sm">Back to profile</a>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title"><span>KYC verification</span></div>
            <?php if ($kycStatus['due']): ?>
                <span class="badge badge-warning">Due</span>
            <?php elseif ($kycStatus['current']): ?>
                <span class="badge badge-success">Done</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php
            $kycLastDate = $kycStatus['last_at_store']['verified_at']
                ?? $kycStatus['current']['verified_at']
                ?? $kycStatus['last']['verified_at']
                ?? null;
            ?>

            <?php if (!$kycStatus['due'] && $kycStatus['current']): ?>
                <div class="alert alert-success">
                    KYC for this store completed on
                    <?= htmlspecialchars($kycStatus['current']['verified_at']) ?>
                    (<?= htmlspecialchars($kycStatus['current']['result'] ?? '') ?>).
                    Next due after <?= (int)($kycStatus['gap_months'] ?? 6) ?> months
                    <?php if (!empty($kycStatus['next_due_at'])): ?>
                        (<?= htmlspecialchars((string)$kycStatus['next_due_at']) ?>)
                    <?php endif; ?>.
                    Next cycle window: after this half ends.
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" id="kyc-form" class="cfg-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="freezer_id" value="<?= (int)$freezer['id'] ?>">
                    <input type="hidden" name="cycle_year" value="<?= (int)$cycle['year'] ?>">
                    <input type="hidden" name="cycle_half" value="<?= (int)$cycle['half'] ?>">
                    <input type="hidden" name="latitude" id="latitude" value="<?= htmlspecialchars($_POST['latitude'] ?? '') ?>">
                    <input type="hidden" name="longitude" id="longitude" value="<?= htmlspecialchars($_POST['longitude'] ?? '') ?>">
                    <input type="hidden" name="gps_accuracy" id="gps_accuracy" value="<?= htmlspecialchars($_POST['gps_accuracy'] ?? '') ?>">
                    <input type="hidden" name="gps_captured_at" id="gps_captured_at" value="<?= htmlspecialchars($_POST['gps_captured_at'] ?? '') ?>">
                    <input type="hidden" name="location_link" id="location_link" value="<?= htmlspecialchars($_POST['location_link'] ?? '') ?>">

                    <!-- Options at top -->
                    <fieldset class="form-subsection kyc-options-top">
                        <legend>Verification options</legend>
                        <div class="form-grid" style="margin-bottom:0;">
                            <div class="col-6 form-group">
                                <label class="form-label required" for="result">Result</label>
                                <select name="result" id="result" class="form-select" required onchange="togglePhotoReq()">
                                    <option value="PASS" <?= $resultVal === 'PASS' ? 'selected' : '' ?>>PASS — unit found &amp; verified</option>
                                    <option value="FAIL" <?= $resultVal === 'FAIL' ? 'selected' : '' ?>>FAIL — not found / mismatch</option>
                                    <option value="PENDING" <?= $resultVal === 'PENDING' ? 'selected' : '' ?>>PENDING — follow-up needed</option>
                                </select>
                            </div>
                            <div class="col-6 form-group">
                                <label class="form-label required" for="verified_at">Verification date</label>
                                <input type="date" name="verified_at" id="verified_at" class="form-control"
                                       value="<?= htmlspecialchars($_POST['verified_at'] ?? date('Y-m-d')) ?>" required>
                            </div>
                            <div class="col-12 form-group" style="margin-bottom:0;">
                                <label class="form-label required">Where is the freezer today?</label>
                                <div class="kyc-loc-options">
                                    <label class="kyc-loc-option">
                                        <input type="radio" name="location_mode" value="same" <?= $locMode !== 'new' ? 'checked' : '' ?> onchange="toggleLoc()">
                                        <span><strong>Same store</strong> — still at <?= htmlspecialchars($freezer['outlet_id']) ?></span>
                                    </label>
                                    <label class="kyc-loc-option">
                                        <input type="radio" name="location_mode" value="new" <?= $locMode === 'new' ? 'checked' : '' ?> onchange="toggleLoc()">
                                        <span><strong>Moved to new outlet</strong> — create store + auto Store ID</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Identity summary -->
                    <div class="profile-kv-grid" style="margin:1.15rem 0; padding:1rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:var(--radius-md);">
                        <div class="profile-kv">
                            <span class="profile-kv-label">KYC window</span>
                            <strong class="profile-kv-value profile-kv-mono">
                                <?= $kycLastDate
                                    ? 'Last KYC: ' . htmlspecialchars((string)$kycLastDate)
                                    : 'Last KYC: —' ?>
                            </strong>
                            <div style="font-size:0.75rem; color:#64748b;">
                                Every <?= (int)($kycStatus['gap_months'] ?? 6) ?> months
                                <?php if (!$kycLastDate && ($kycStatus['anchor_source'] ?? '') === 'installation' && !empty($kycStatus['anchor_date'])): ?>
                                    · from install <?= htmlspecialchars((string)$kycStatus['anchor_date']) ?>
                                <?php endif; ?>
                                <?php if (!empty($kycStatus['next_due_at'])): ?>
                                    · Window ends <?= htmlspecialchars((string)$kycStatus['next_due_at']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="profile-kv">
                            <span class="profile-kv-label">Tag code</span>
                            <strong class="profile-kv-value profile-kv-mono profile-kv-lg">
                                <?= htmlspecialchars($assetTag['tag_code'] ?? '—') ?>
                            </strong>
                        </div>
                        <div class="profile-kv">
                            <span class="profile-kv-label">Unique Freezer ID</span>
                            <strong class="profile-kv-value profile-kv-mono" style="color:#0284c7; font-size:0.8rem; word-break:break-all;">
                                <?= htmlspecialchars($freezer['unique_freezer_id']) ?>
                            </strong>
                        </div>
                        <div class="profile-kv profile-kv-wide">
                            <span class="profile-kv-label">Currently linked store</span>
                            <strong class="profile-kv-value"><?= htmlspecialchars($freezer['store_name']) ?></strong>
                            <div style="font-size:0.8rem; color:#64748b; font-family:var(--font-mono);">
                                <?= htmlspecialchars($freezer['outlet_id']) ?>
                                · <?= htmlspecialchars($freezer['store_city'] ?? '') ?>, <?= htmlspecialchars($freezer['store_state'] ?? '') ?>
                            </div>
                        </div>
                    </div>

                    <!-- 1. Asset confirm -->
                    <div class="form-group" style="padding:0.85rem 1rem; border:1px solid #bbf7d0; background:#f0fdf4; border-radius:8px;">
                        <label style="display:flex; gap:0.6rem; align-items:flex-start; font-size:0.875rem; margin:0; cursor:pointer;">
                            <input type="checkbox" name="confirm_asset" value="1" style="margin-top:0.2rem;"
                                <?= (!empty($_POST['confirm_asset']) || $scanUnlocked) ? 'checked' : '' ?> required>
                            <span>
                                <strong>1. Asset match confirmed</strong><br>
                                <span style="color:#475569;">QR / Tag already scanned for this Unique Freezer ID<?= $assetTag ? ' / ' . htmlspecialchars($assetTag['tag_code']) : '' ?> — confirm physical unit is the same.</span>
                            </span>
                        </label>
                    </div>

                    <div id="loc-new" style="<?= $locMode === 'new' ? '' : 'display:none; margin-top:1.15rem;' ?>">
                        <div class="alert alert-info" style="font-size:0.8rem; margin-bottom:0.85rem;">
                            Naya outlet save hote hi auto Store ID milegi.
                            Freezer us pe link hoga; Unique Freezer ID same rahegi.
                            Capture <strong>Store Front</strong> with Location ON — same as onboarding.
                        </div>

                        <div class="form-group" style="margin-bottom:1rem;">
                            <label class="form-label required" for="image_new_store_front">New outlet — Store Front photo</label>
                            <div class="upload-box upload-box-featured" id="dropzone-new-store-front">
                                <input type="file" name="image_new_store_front" id="image_new_store_front" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-new-store-front">
                                <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 21h18"/>
                                    <path d="M5 21V7l7-4 7 4v14"/>
                                    <path d="M9 21v-6h6v6"/>
                                </svg>
                                <div class="upload-title">Store Front photo *</div>
                                <div class="upload-hint">Required · Camera · GPS + location auto-fill</div>
                                <div class="upload-preview-container" id="preview-new-store-front">
                                    <img src="" alt="New Store Front Preview">
                                    <span class="preview-badge">Store Front</span>
                                </div>
                            </div>
                            <div class="gps-capture-widget gps-capture-widget-compact" style="margin: 1rem 0 0;">
                                <div class="gps-header">
                                    <strong style="font-size: 0.9rem; color: #14532d;">New store location</strong>
                                    <span id="gps-status-pill" class="gps-status-pill">Not set</span>
                                </div>
                                <div id="gps-process-loading" class="gps-process-loading" hidden aria-live="polite">
                                    <span class="gps-process-spinner" aria-hidden="true"></span>
                                    <span class="gps-process-text">Processing…</span>
                                </div>
                                <span id="disp-place" hidden></span>
                                <span id="disp-lat" hidden></span>
                                <span id="disp-lng" hidden></span>
                                <span id="disp-acc" hidden></span>
                                <div class="gps-action-row" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center;">
                                    <button type="button" class="btn btn-success" data-save-current-location
                                        data-wa-title="New outlet location (KYC)">
                                        Save my current location
                                    </button>
                                    <button type="button" class="btn btn-primary js-share-location-btn" data-share-location="saved"
                                        data-wa-title="New outlet location" hidden>
                                        Share location
                                    </button>
                                </div>
                                <?php
                                $kycLocLink = trim((string)($_POST['location_link'] ?? ''));
                                ?>
                                <div class="sp-location-link-box" id="current-location-link-box" <?= $kycLocLink === '' ? 'hidden' : '' ?>>
                                    <div class="sp-location-link-label">Current location link</div>
                                    <a class="sp-location-link" id="current-location-link"
                                       href="<?= $kycLocLink !== '' ? htmlspecialchars($kycLocLink) : '#' ?>"
                                       target="_blank" rel="noopener"><?= $kycLocLink !== '' ? htmlspecialchars($kycLocLink) : '' ?></a>
                                    <button type="button" class="btn btn-sm btn-outline" id="btn-copy-location-link"
                                        data-copy="<?= htmlspecialchars($kycLocLink) ?>">Copy link</button>
                                </div>
                                <div class="sp-latlng" id="current-location-latlng" <?= empty($_POST['latitude']) ? 'hidden' : '' ?>>
                                    <?php if (!empty($_POST['latitude']) && !empty($_POST['longitude'])): ?>
                                        Lat <?= htmlspecialchars((string)$_POST['latitude']) ?>
                                        · Lng <?= htmlspecialchars((string)$_POST['longitude']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="col-6 form-group">
                                <label class="form-label required" for="store_name">New store name</label>
                                <input type="text" name="store_name" id="store_name" class="form-control" value="<?= htmlspecialchars($_POST['store_name'] ?? '') ?>">
                            </div>
                            <div class="col-6 form-group">
                                <label class="form-label" for="contact_person">Manager / operator</label>
                                <input type="text" name="contact_person" id="contact_person" class="form-control" value="<?= htmlspecialchars($_POST['contact_person'] ?? ($freezer['contact_person'] ?? '')) ?>">
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label" for="zone">Zone</label>
                                <select name="zone" id="zone" class="form-control">
                                    <?php foreach (['WEST','EAST','NORTH','SOUTH','CENTRAL'] as $z): ?>
                                        <option value="<?= $z ?>" <?= (($_POST['zone'] ?? 'WEST') === $z) ? 'selected' : '' ?>><?= $z ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label required" for="city">City</label>
                                <input type="text" name="city" id="city" class="form-control" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>" placeholder="Select zone, then type city…" autocomplete="off">
                                <datalist id="city-list"></datalist>
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label" for="state">State</label>
                                <input type="text" name="state" id="state" class="form-control" value="<?= htmlspecialchars($_POST['state'] ?? ($freezer['store_state'] ?? '')) ?>">
                            </div>
                            <div class="col-12 form-group">
                                <label class="form-label required" for="address">Address</label>
                                <input type="text" name="address" id="address" class="form-control" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label required" for="pincode">PIN</label>
                                <input type="text" name="pincode" id="pincode" class="form-control" maxlength="6" value="<?= htmlspecialchars($_POST['pincode'] ?? '') ?>">
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label" for="phone_number">Phone</label>
                                <input type="tel" name="phone_number" id="phone_number" class="form-control" maxlength="10" value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>">
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label" for="aadhar_number">Aadhaar (optional)</label>
                                <input type="text" name="aadhar_number" id="aadhar_number" class="form-control" maxlength="12" value="<?= htmlspecialchars($_POST['aadhar_number'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- 3. Evidence -->
                    <div style="margin-top:1.25rem; margin-bottom:0.5rem;">
                        <label class="form-label required" style="margin-bottom:0;">3. Photo evidence</label>
                        <p style="font-size:0.8rem; color:#64748b; margin:0.25rem 0 0.75rem;">Required for <strong>PASS</strong>. Use phone camera on site.</p>
                    </div>
                    <div class="form-grid">
                        <div class="col-6 form-group" id="kyc-same-store-photo-wrap">
                            <label class="form-label required" for="image_store_front">Store front photo</label>
                            <div class="upload-box upload-box-featured" id="dropzone-store-front">
                                <input type="file" name="image_store_front" id="image_store_front" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-kyc-store-front">
                                <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 21h18"/>
                                    <path d="M5 21V7l7-4 7 4v14"/>
                                    <path d="M9 21v-6h6v6"/>
                                </svg>
                                <div class="upload-title">Store Front</div>
                                <div class="upload-hint">Camera · GPS stamp</div>
                                <div class="upload-preview-container" id="preview-kyc-store-front">
                                    <img src="" alt="Store Front Preview">
                                    <span class="preview-badge">Store Front</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 form-group" id="kyc-freezer-photo-wrap">
                            <label class="form-label required" for="image_freezer">Freezer photo (with QR / Tag)</label>
                            <div class="upload-box" id="dropzone-kyc-freezer">
                                <input type="file" name="image_freezer" id="image_freezer" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-kyc-freezer">
                                <div class="upload-icon">📷</div>
                                <div class="upload-title">Freezer / Tag</div>
                                <div class="upload-hint">Camera · Date stamp</div>
                                <div class="upload-preview-container" id="preview-kyc-freezer">
                                    <img src="" alt="Freezer Preview">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:1rem;">
                        <label class="form-label" for="notes">Notes / remarks</label>
                        <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Condition, access issues, operator remarks…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <p id="kyc-gps-status" style="font-size:0.8rem; color:#64748b; margin-bottom:0.85rem;">Capturing GPS…</p>
                    <button type="submit" class="btn btn-primary">
                        Save KYC for <?= htmlspecialchars($cycle['label']) ?>
                    </button>
                </form>

                <script>
                function toggleLoc() {
                    const m = document.querySelector('input[name="location_mode"]:checked').value;
                    const isNew = m === 'new';
                    document.getElementById('loc-new').style.display = isNew ? '' : 'none';
                    const sameWrap = document.getElementById('kyc-same-store-photo-wrap');
                    const frzWrap = document.getElementById('kyc-freezer-photo-wrap');
                    if (sameWrap) sameWrap.style.display = isNew ? 'none' : '';
                    if (frzWrap) {
                        frzWrap.classList.toggle('col-6', !isNew);
                        frzWrap.classList.toggle('col-12', isNew);
                    }
                    const sameInput = document.getElementById('image_store_front');
                    const newInput = document.getElementById('image_new_store_front');
                    if (sameInput) {
                        sameInput.disabled = isNew;
                        if (isNew) sameInput.required = false;
                    }
                    if (newInput) {
                        newInput.disabled = !isNew;
                        if (!isNew) newInput.required = false;
                    }
                    togglePhotoReq();
                }
                function togglePhotoReq() {
                    const pass = document.getElementById('result').value === 'PASS';
                    const isNew = document.querySelector('input[name="location_mode"]:checked').value === 'new';
                    const sameInput = document.getElementById('image_store_front');
                    const newInput = document.getElementById('image_new_store_front');
                    const frz = document.getElementById('image_freezer');
                    if (sameInput) sameInput.required = pass && !isNew;
                    if (newInput) newInput.required = pass && isNew;
                    if (frz) frz.required = pass;
                }
                toggleLoc();
                togglePhotoReq();
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function (pos) {
                        const latEl = document.getElementById('latitude');
                        const lngEl = document.getElementById('longitude');
                        if (latEl) latEl.value = pos.coords.latitude.toFixed(6);
                        if (lngEl) lngEl.value = pos.coords.longitude.toFixed(6);
                        const accEl = document.getElementById('gps_accuracy');
                        if (accEl && pos.coords.accuracy != null) accEl.value = Math.round(pos.coords.accuracy);
                        document.getElementById('kyc-gps-status').textContent = 'GPS captured for this verification.';
                        document.getElementById('kyc-gps-status').style.color = '#059669';
                    }, function () {
                        document.getElementById('kyc-gps-status').textContent = 'GPS not available — you can still save KYC.';
                    }, { enableHighAccuracy: true, timeout: 10000 });
                } else {
                    document.getElementById('kyc-gps-status').textContent = 'GPS not supported on this device.';
                }
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
window.GEO_HIERARCHY = <?= json_encode(MasterData::getGeoHierarchy()) ?>;
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
