<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Config\AppConfig;
use App\Models\MasterData;
use App\Models\Store;
use App\Models\Freezer;
use App\Models\AssetTag;

AuthService::requireRole(['super_admin', 'technician']);
$currentUser = AuthService::currentUser();
Freezer::ensureSimColumns();
$areaScope = AuthService::areaScope();
$lockZone = $areaScope['zone'] !== '';
$lockCity = $areaScope['city'] !== '';

$error = null;
$success = null;
$csrfToken = AuthService::generateCsrfToken();

$freezerTypes = MasterData::getFreezerTypes();
$brands = MasterData::getBrands();
$customers = MasterData::getCustomerList();
$conditions = MasterData::getConditions();
$capacities = MasterData::getCapacities();

// Scan-first workflow: blank QR must be scanned (or opened) before filling data
$boundTagCode = strtoupper(trim((string)($_GET['tag'] ?? $_POST['asset_tag_code'] ?? '')));
$boundTag = null;
if ($boundTagCode !== '') {
    $boundTag = AssetTag::findByCode($boundTagCode);
    if (!$boundTag) {
        $error = "Blank QR tag '{$boundTagCode}' was not found. Generate & print tags under Blank QR Tags, then scan.";
        $boundTagCode = '';
    } elseif ($boundTag['status'] === 'assigned' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $freezer = !empty($boundTag['freezer_id']) ? Freezer::findById((int)$boundTag['freezer_id']) : null;
        if ($freezer) {
            header('Location: profile_view.php?id=' . urlencode($freezer['unique_freezer_id']));
            exit;
        }
        $error = "QR tag '{$boundTagCode}' is already assigned.";
        $boundTagCode = '';
        $boundTag = null;
    } elseif ($boundTag['status'] !== 'unused' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $error = "QR tag '{$boundTagCode}' is not available for registration.";
        $boundTagCode = '';
        $boundTag = null;
    }
}
if (!empty($_GET['err']) && $_GET['err'] === 'assigned' && empty($error)) {
    $error = 'This QR tag is already assigned — scan shows existing details.';
}

$requireScanFirst = ($boundTag === null || $boundTagCode === '');

// Handle form submission — only after QR scanned; data is linked to that QR
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security CSRF token. Please submit again.';
    } else {
        try {
            $postTagCode = strtoupper(trim((string)($_POST['asset_tag_code'] ?? '')));
            if ($postTagCode === '') {
                throw new Exception('Scan a blank QR tag first, then fill store & freezer details.');
            }
            $tagCheck = AssetTag::findByCode($postTagCode);
            if (!$tagCheck || $tagCheck['status'] !== 'unused') {
                throw new Exception("Blank QR tag '{$postTagCode}' is not available. Scan an unused sticker.");
            }
            $boundTagCode = $postTagCode;
            $boundTag = $tagCheck;
            $requireScanFirst = false;

            $requiredText = [
                'store_name' => 'Store / Outlet name',
                'address' => 'Street address',
                'city' => 'City',
                'pincode' => 'PIN code',
                'zone' => 'Zone',
                'freezer_type' => 'Freezer type',
                'company_code' => 'Company / series',
                'model_number' => 'Model number',
                'installation_date' => 'Installation date',
            ];
            foreach ($requiredText as $key => $label) {
                if (trim((string)($_POST[$key] ?? '')) === '') {
                    throw new Exception("{$label} is required.");
                }
            }
            if (!preg_match('/^\d{6}$/', trim((string)($_POST['pincode'] ?? '')))) {
                throw new Exception('Enter a valid 6-digit PIN code.');
            }
            if (empty($_FILES['image_store_front']['name'])) {
                throw new Exception('Store Front photo is required for KYS store profile.');
            }
            if (empty($_FILES['image_left_side']['name']) || empty($_FILES['image_right_side']['name'])) {
                throw new Exception('Freezer Left and Right photos are required for asset verification.');
            }

            // 1. Process uploads — filename: Tag-YYYYMMDD-StoreName-ImageType.ext
            $imgNameMeta = [
                'tag_code' => $postTagCode,
                'store_name' => trim((string)($_POST['store_name'] ?? 'Store')),
                'datetime' => date('Ymd'),
            ];
            $imgStoreFront = FileUploadService::handleImageUpload($_FILES['image_store_front'], 'stores', 'store_front', $imgNameMeta);
            if (!$imgStoreFront['success']) {
                throw new Exception('Store Front Image Error: ' . $imgStoreFront['error']);
            }

            $imgAadhar = null;
            if (!empty($_FILES['image_aadhar_card']['name'])) {
                $imgAadhar = FileUploadService::handleImageUpload($_FILES['image_aadhar_card'], 'stores', 'aadhar_front', $imgNameMeta);
                if (!$imgAadhar['success']) {
                    throw new Exception('Aadhaar Front Image Error: ' . $imgAadhar['error']);
                }
            }
            $imgAadharBack = null;
            if (!empty($_FILES['image_aadhar_back']['name'])) {
                $imgAadharBack = FileUploadService::handleImageUpload($_FILES['image_aadhar_back'], 'stores', 'aadhar_back', $imgNameMeta);
                if (!$imgAadharBack['success']) {
                    throw new Exception('Aadhaar Back Image Error: ' . $imgAadharBack['error']);
                }
            }

            $imgLeftResult = FileUploadService::handleImageUpload($_FILES['image_left_side'], 'freezers', 'frz_left', $imgNameMeta);
            if (!$imgLeftResult['success']) {
                throw new Exception('Left Side Image Error: ' . $imgLeftResult['error']);
            }

            $imgRightResult = FileUploadService::handleImageUpload($_FILES['image_right_side'], 'freezers', 'frz_right', $imgNameMeta);
            if (!$imgRightResult['success']) {
                throw new Exception('Right Side Image Error: ' . $imgRightResult['error']);
            }

            $imgIotResult = null;
            if (AppConfig::IOT_ENABLED && !empty($_FILES['image_iot_device']['name'])) {
                $imgIotResult = FileUploadService::handleImageUpload($_FILES['image_iot_device'], 'iot', 'iot_dev', $imgNameMeta);
                if (!$imgIotResult['success']) {
                    throw new Exception('IoT Device Image Error: ' . $imgIotResult['error']);
                }
            }

            $imgSimResult = null;
            if (!empty($_FILES['image_sim']['name'])) {
                $imgSimResult = FileUploadService::handleImageUpload($_FILES['image_sim'], 'freezers', 'sim_img', $imgNameMeta);
                if (!$imgSimResult['success']) {
                    throw new Exception('SIM photo error: ' . $imgSimResult['error']);
                }
            }

            // 2. Create Store — force assigned zone/city for scoped technicians
            $storePayload = AuthService::applyAreaToStoreData([
                'store_name' => $_POST['store_name'],
                'customer_name' => $_POST['customer_name'] ?? 'Baskin Robbins',
                'distributor_name' => $_POST['distributor_name'] ?? '',
                'contact_person' => $_POST['contact_person'] ?? '',
                'phone_number' => $_POST['phone_number'] ?? '',
                'email' => $_POST['email'] ?? '',
                'aadhar_number' => $_POST['aadhar_number'] ?? '',
                'fssai_number' => $_POST['fssai_number'] ?? '',
                'gst_number' => $_POST['gst_number'] ?? '',
                'address' => $_POST['address'],
                'city' => $_POST['city'],
                'state' => trim($_POST['state'] ?? '') !== '' ? trim($_POST['state']) : 'Unknown',
                'pincode' => $_POST['pincode'],
                'zone' => $_POST['zone'] ?? 'WEST',
                'location_type' => $_POST['location_type'] ?? 'Retail Store',
            ]);
            if (!AuthService::canAccessArea($storePayload['zone'] ?? null, $storePayload['city'] ?? null)) {
                throw new Exception('Zone/city is outside your assigned area.');
            }
            $_POST['city'] = $storePayload['city'];
            $_POST['zone'] = $storePayload['zone'];

            $outletId = Store::generateOutletId([
                'city' => $storePayload['city'] ?? 'AMD',
                'pincode' => $_POST['pincode'] ?? '',
                'location_code' => $_POST['location_code'] ?? '',
            ]);
            $hasGps = !empty($_POST['latitude']) && !empty($_POST['longitude']);
            $storeId = Store::create(array_merge($storePayload, [
                'outlet_id' => $outletId,
                'latitude' => $hasGps ? (float)$_POST['latitude'] : null,
                'longitude' => $hasGps ? (float)$_POST['longitude'] : null,
                'gps_accuracy' => !empty($_POST['gps_accuracy']) ? (float)$_POST['gps_accuracy'] : null,
                'gps_captured_at' => $hasGps ? date('Y-m-d H:i:s') : null,
                'location_link' => $hasGps
                    ? (trim((string)($_POST['location_link'] ?? '')) !== ''
                        ? trim((string)$_POST['location_link'])
                        : ('https://maps.google.com/?q=' . rawurlencode((float)$_POST['latitude'] . ',' . (float)$_POST['longitude'])))
                    : null,
                'image_store_front' => $imgStoreFront['path'],
                'image_aadhar_card' => $imgAadhar ? $imgAadhar['path'] : null,
                'image_aadhar_back' => $imgAadharBack ? $imgAadharBack['path'] : null,
                'status' => 'Active',
                'created_by' => $currentUser['id'],
            ]));

            // 3. Freezer ID inputs (store PIN + freezer specs → Unique ID → QR)
            $freezerType = $_POST['freezer_type'] ?? 'DF';
            $companyCode = $_POST['company_code'] ?? 'RW';
            $modelNumber = $_POST['model_number'] ?? '';
            $pincode = $_POST['pincode'] ?? '';
            $locationCode = MasterData::resolveLocationCodeForCity(
                (string)($_POST['city'] ?? ''),
                $_POST['location_code'] ?? ''
            );
            $installDate = $_POST['installation_date'] ?? date('Y-m-d');
            $installMmYy = date('my', strtotime($installDate));
            // Unique ID + random unit sequence assigned on save
            $candidateId = null;

            // 4. Create Freezer — Unique ID generated; physical QR sticker stays the scanned blank tag
            $freezerPayload = [
                'unique_freezer_id' => $candidateId,
                'store_id' => $storeId,
                'freezer_type' => $freezerType,
                'company_code' => $companyCode,
                'model_number' => $modelNumber,
                'capacity_litres' => !empty($_POST['capacity_litres']) ? (int)$_POST['capacity_litres'] : null,
                'freezer_condition' => $_POST['freezer_condition'] ?? 'WORKING CONDITION',
                'location_code' => $locationCode,
                'pincode' => $pincode,
                'install_month_year' => $installMmYy,
                'unit_sequence' => null,
                'installation_date' => $installDate,
                'image_left_side' => $imgLeftResult['path'],
                'image_right_side' => $imgRightResult['path'],
                'image_iot_device' => $imgIotResult ? $imgIotResult['path'] : null,
                'image_sim' => $imgSimResult ? $imgSimResult['path'] : null,
                'sim_number' => trim((string)($_POST['sim_number'] ?? '')) ?: null,
                'sim_company' => trim((string)($_POST['sim_company'] ?? '')) ?: null,
                'status' => $_POST['freezer_status'] ?? 'Active',
                'notes' => $_POST['notes'] ?? '',
                'created_by' => $currentUser['id'],
                'created_by_name' => trim((string)($currentUser['name'] ?? '')),
                'asset_tag_code' => $boundTagCode,
            ];

            if (AppConfig::IOT_ENABLED) {
                $freezerPayload['iot_serial'] = $_POST['iot_serial'] ?? ('RF-IOT-' . rand(10000, 99999));
                $freezerPayload['iot_imei'] = $_POST['iot_imei'] ?? null;
                $freezerPayload['iot_sim_number'] = trim($_POST['iot_sim_number'] ?? '')
                    ?: ($freezerPayload['sim_number'] ?? null);
                $freezerPayload['iot_sim_company'] = trim($_POST['iot_sim_company'] ?? '')
                    ?: ($freezerPayload['sim_company'] ?? null);
                $freezerPayload['iot_type'] = $_POST['iot_type'] ?? 'Temperature & GPS';
                $freezerPayload['iot_owner'] = $_POST['iot_owner'] ?? 'ReeferON Own Device';
                $freezerPayload['iot_model'] = $_POST['iot_model'] ?? 'RF-T100';
            }

            $freezerId = Freezer::create($freezerPayload);

            if (AppConfig::IOT_ENABLED) {
                \App\Models\IoTDevice::seedSampleTelemetry($freezerId);
            }

            $createdFreezer = Freezer::findById($freezerId);
            header('Location: profile_view.php?id=' . urlencode($createdFreezer['unique_freezer_id']) . '&created=1');
            exit;

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = $requireScanFirst ? 'Scan QR to Register' : 'Fill Store & Freezer Details';
$bodyClass = 'field-app-page field-shell-page profile-add-page';
require_once __DIR__ . '/includes/header.php';
?>

<div class="checkout-page">
<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: 1rem;">
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if ($requireScanFirst): ?>
    <div class="card" style="max-width: 520px; margin: 0 auto; text-align: center;">
        <div class="card-body" style="padding: 2rem 1.5rem;">
            <h2 style="font-weight: 800; color: #0a2540; letter-spacing: -0.02em; margin-bottom: 0.5rem;">
                Step 1 — Scan blank QR first
            </h2>
            <p style="color: #64748b; font-size: 0.9rem; margin: 0 auto 1.25rem; max-width: 26rem;">
                Print blank stickers (Logo + QR), stick on freezer, then scan.
                After you fill &amp; submit, <strong>that same QR</strong> will open all store &amp; freezer details.
            </p>
            <ol style="text-align: left; max-width: 22rem; margin: 0 auto 1.5rem; color: #475569; font-size: 0.875rem; line-height: 1.55; padding-left: 1.2rem;">
                <li>Print blank QR tags</li>
                <li>Scan the sticker on the freezer</li>
                <li>Fill store + freezer info &amp; photos</li>
                <li>Done — next scan shows full details</li>
            </ol>
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: center;">
                <a href="qr_scanner.php" class="btn btn-primary">Scan QR now</a>
                <a href="blank_qr.php" class="btn btn-secondary">Print blank QR tags</a>
            </div>
        </div>
    </div>
</div><!-- /checkout-page -->
<?php else: ?>

    <div class="cfg-toolbar">
        <div class="cfg-toolbar-left">
            <h1 class="cfg-toolbar-title">
                New Freezer Configuration
                <span class="cfg-tag-plain"><?= htmlspecialchars($boundTag['tag_code']) ?></span>
            </h1>
        </div>
    </div>

<div class="checkout-card onboard-card cfg-panel">
    <nav class="onboard-steps cfg-steps" id="checkout-methods" aria-label="Form steps">
        <button type="button" class="onboard-step checkout-method active" data-goto="1">
            <span class="onboard-step-num">1</span>
            <span class="onboard-step-label">Store</span>
        </button>
        <span class="onboard-step-divider" aria-hidden="true"></span>
        <button type="button" class="onboard-step checkout-method" data-goto="2">
            <span class="onboard-step-num">2</span>
            <span class="onboard-step-label">Freezer</span>
        </button>
        <span class="onboard-step-divider" aria-hidden="true"></span>
        <button type="button" class="onboard-step checkout-method" data-goto="3">
            <span class="onboard-step-num">3</span>
            <span class="onboard-step-label">Review</span>
        </button>
    </nav>

<form method="POST" action="" enctype="multipart/form-data" id="onboard-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="asset_tag_code" value="<?= htmlspecialchars($boundTagCode) ?>">
    <input type="hidden" id="unique_freezer_id" name="unique_freezer_id" value="">

    <div class="checkout-body">
    <!-- ========================================== -->
    <!-- STEP 1: STORE INFO                         -->
    <!-- ========================================== -->
    <div class="checkout-step active" data-step="1">
    <div class="card">
        <div class="card-body">
            <input type="hidden" id="latitude" name="latitude" value="<?= htmlspecialchars($_POST['latitude'] ?? '') ?>">
            <input type="hidden" id="longitude" name="longitude" value="<?= htmlspecialchars($_POST['longitude'] ?? '') ?>">
            <input type="hidden" id="location_link" name="location_link" value="<?= htmlspecialchars($_POST['location_link'] ?? '') ?>">
            <input type="hidden" id="gps_accuracy" name="gps_accuracy" value="<?= htmlspecialchars($_POST['gps_accuracy'] ?? '') ?>">
            <input type="hidden" id="state" name="state" value="<?= htmlspecialchars($_POST['state'] ?? '') ?>">

            <div id="store-front-exif-status" class="store-front-exif-status" style="display:none;" role="status"></div>

            <!-- Top: Save live location first -->
            <div class="loc-save-panel gps-capture-widget" role="region" aria-label="Store location">
                <div class="loc-save-panel-main">
                    <div class="loc-save-panel-head">
                        <div class="loc-save-panel-icon" aria-hidden="true">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                <circle cx="12" cy="10" r="3"/>
                            </svg>
                        </div>
                        <div class="loc-save-panel-copy">
                            <div class="loc-save-panel-title-row">
                                <h3 class="loc-save-panel-title">Store location</h3>
                                <span id="gps-status-pill" class="gps-status-pill">Not set</span>
                            </div>
                            <p class="loc-save-panel-desc">
                                Save live GPS first — address, city &amp; PIN auto-fill.
                            </p>
                        </div>
                    </div>
                    <div class="loc-save-panel-actions">
                        <button type="button" class="btn btn-success loc-save-btn" data-save-current-location
                            data-wa-title="Store location (onboarding)">
                            Save my current location
                        </button>
                        <button type="button" class="btn loc-share-trigger js-share-location-btn" data-share-location="saved"
                            data-wa-title="Store location" hidden>
                            <svg class="loc-share-trigger-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                            </svg>
                            <span>Share</span>
                        </button>
                    </div>
                </div>
                <div id="gps-process-loading" class="gps-process-loading" hidden aria-live="polite">
                    <span class="gps-process-spinner" aria-hidden="true"></span>
                    <span class="gps-process-text">Processing…</span>
                </div>
                <span id="disp-place" hidden></span>
                <span id="disp-lat" hidden></span>
                <span id="disp-lng" hidden></span>
                <span id="disp-acc" hidden></span>
                <div class="sp-location-link-box" id="current-location-link-box" hidden>
                    <div class="sp-location-link-label">Current location link</div>
                    <a class="sp-location-link" id="current-location-link" href="#" target="_blank" rel="noopener"></a>
                    <button type="button" class="btn btn-sm btn-outline" id="btn-copy-location-link" data-copy="">Copy link</button>
                </div>
                <div class="sp-latlng" id="current-location-latlng" hidden></div>
            </div>

            <!-- Sub: Store Front photo -->
            <fieldset class="form-subsection">
                <legend>
                    Store Front photo
                    <span>Required · date &amp; time stamp</span>
                </legend>

                <div class="photo-upload-grid photo-upload-grid-2" style="margin-bottom: 0;">
                    <div class="upload-box" id="dropzone-store-front">
                        <input type="file" name="image_store_front" id="image_store_front" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-store-front" required>
                        <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                            <circle cx="12" cy="13" r="4"/>
                        </svg>
                        <div class="upload-title">Store Front *</div>
                        <div class="upload-hint">Required · date-time stamp only</div>
                        <div class="upload-preview-container" id="preview-store-front">
                            <img src="" alt="Store Front Preview">
                            <span class="preview-badge">Front</span>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- Sub: Store details -->
            <fieldset class="form-subsection" id="store-details-anchor">
                <legend>
                    Store Details
                    <span>Outlet identity · address · zone</span>
                </legend>

                <div class="form-grid" id="store-fields-grid">
                    <div class="col-6 form-group">
                        <label class="form-label required" for="store_name">Store / Outlet name</label>
                        <input type="text" id="store_name" name="store_name" class="form-control" placeholder="e.g. Baskin Robbins - CG Road Hub" value="<?= htmlspecialchars($_POST['store_name'] ?? '') ?>" required>
                        <div class="form-error">Store name is required.</div>
                    </div>

                    <div class="col-3 form-group">
                        <label class="form-label" for="outlet_id_preview">Outlet ID / Code</label>
                        <input type="text" id="outlet_id_preview" class="form-control"
                               value="Auto on save" readonly
                               style="background:#f1f5f9; font-family:var(--font-mono); cursor:default;" tabindex="-1">
                    </div>

                    <div class="col-3 form-group">
                        <label class="form-label" for="customer_name">Customer account</label>
                        <select id="customer_name" name="customer_name" class="form-select">
                            <?php
                            $custVal = $_POST['customer_name'] ?? ($customers[0] ?? 'Baskin Robbins');
                            foreach ($customers as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>" <?= $custVal === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-4 form-group">
                        <label class="form-label required" for="zone">Zone</label>
                        <?php
                        $zoneVal = $lockZone ? $areaScope['zone'] : ($_POST['zone'] ?? 'WEST');
                        $addZones = $lockZone ? [$areaScope['zone']] : AppConfig::zones();
                        ?>
                        <select id="zone" name="zone" class="form-select" required <?= $lockZone ? 'disabled' : '' ?>>
                            <?php foreach ($addZones as $z): ?>
                                <option value="<?= htmlspecialchars($z) ?>" <?= $zoneVal === $z ? 'selected' : '' ?>><?= htmlspecialchars($z) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($lockZone): ?>
                            <input type="hidden" name="zone" value="<?= htmlspecialchars($areaScope['zone']) ?>">
                        <?php endif; ?>
                    </div>

                    <div class="col-8 form-group">
                        <label class="form-label required" for="city">City</label>
                        <?php $cityVal = $lockCity ? $areaScope['city'] : ($_POST['city'] ?? ''); ?>
                        <input type="text" id="city" name="city" class="form-control" placeholder="Select zone, then type city…" value="<?= htmlspecialchars($cityVal) ?>" required autocomplete="off" <?= $lockCity ? 'readonly' : '' ?>>
                        <?php if (!$lockCity): ?><datalist id="city-list"></datalist><?php endif; ?>
                    </div>

                    <div class="col-12 form-group">
                        <label class="form-label required" for="address">Street address</label>
                        <input type="text" id="address" name="address" class="form-control" placeholder="Shop No, Building, Street, Area" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" required>
                        <div class="form-error">Address is required.</div>
                    </div>

                    <div class="col-4 form-group">
                        <label class="form-label required" for="pincode">PIN code</label>
                        <input type="text" id="pincode" name="pincode" class="form-control" placeholder="6 digits" value="<?= htmlspecialchars($_POST['pincode'] ?? '') ?>" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" required>
                        <div class="form-error">Enter a valid 6-digit PIN code.</div>
                    </div>

                    <div class="col-4 form-group">
                        <label class="form-label" for="distributor_name">Distributor</label>
                        <input type="text" id="distributor_name" name="distributor_name" class="form-control" placeholder="Distributor / dealer name" value="<?= htmlspecialchars($_POST['distributor_name'] ?? '') ?>">
                    </div>

                    <div class="col-4 form-group">
                        <label class="form-label" for="location_type">Location type</label>
                        <select id="location_type" name="location_type" class="form-select">
                            <?php
                            $locTypes = ['Retail Store', 'Commercial Store', 'Warehouse', 'Distributor Point', 'Mall Kiosk', 'Other'];
                            $locVal = $_POST['location_type'] ?? 'Retail Store';
                            foreach ($locTypes as $lt):
                            ?>
                                <option value="<?= htmlspecialchars($lt) ?>" <?= $locVal === $lt ? 'selected' : '' ?>><?= htmlspecialchars($lt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 form-group">
                        <label class="form-label" for="fssai_number">FSSAI number</label>
                        <input type="text" id="fssai_number" name="fssai_number" class="form-control" placeholder="14-digit FSSAI licence" value="<?= htmlspecialchars($_POST['fssai_number'] ?? '') ?>" maxlength="14" inputmode="numeric" pattern="[0-9]{14}" title="Exactly 14 digits" autocomplete="off">
                        <div class="form-error">Enter a valid 14-digit FSSAI number.</div>
                    </div>

                    <div class="col-6 form-group">
                        <label class="form-label" for="gst_number">GST number</label>
                        <input type="text" id="gst_number" name="gst_number" class="form-control" placeholder="15-character GSTIN" value="<?= htmlspecialchars($_POST['gst_number'] ?? '') ?>" maxlength="15" pattern="[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]" title="Valid 15-character GSTIN" autocomplete="off" style="text-transform: uppercase;">
                        <div class="form-error">Enter a valid 15-character GSTIN.</div>
                    </div>
                </div>
            </fieldset>

            <!-- Sub: Store operator -->
            <fieldset class="form-subsection">
                <legend>
                    Store Operator
                    <span>Name, phone, email ID &amp; Aadhaar</span>
                </legend>

                <div class="form-grid">
                    <div class="col-6 form-group">
                        <label class="form-label" for="contact_person">Store operator name</label>
                        <input type="text" id="contact_person" name="contact_person" class="form-control" placeholder="Operator / manager name" value="<?= htmlspecialchars($_POST['contact_person'] ?? '') ?>">
                    </div>

                    <div class="col-6 form-group">
                        <label class="form-label" for="phone_number">Operator phone / mobile</label>
                        <div class="phone-input-group">
                            <span class="phone-prefix" aria-hidden="true">+91</span>
                            <input type="tel" id="phone_number" name="phone_number" class="form-control" placeholder="10-digit mobile" value="<?= htmlspecialchars(preg_replace('/\D/', '', substr((string)($_POST['phone_number'] ?? ''), -10))) ?>" maxlength="10" inputmode="numeric" title="10-digit Indian mobile (starts with 6–9)" autocomplete="tel-national">
                        </div>
                        <div class="form-error">Enter a valid 10-digit mobile starting with 6, 7, 8 or 9.</div>
                    </div>

                    <div class="col-6 form-group">
                        <label class="form-label" for="email">Store operator email ID</label>
                        <input type="email" id="email" name="email" class="form-control" placeholder="operator@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="email">
                    </div>

                    <div class="col-6 form-group">
                        <label class="form-label" for="aadhar_number">Store operator Aadhaar number</label>
                        <input type="text" id="aadhar_number" name="aadhar_number" class="form-control" placeholder="12-digit Aadhaar" value="<?= htmlspecialchars($_POST['aadhar_number'] ?? '') ?>" maxlength="12" inputmode="numeric" pattern="[0-9]{12}" title="Exactly 12 digits" autocomplete="off">
                        <div class="form-error">Enter a valid 12-digit Aadhaar number.</div>
                    </div>

                    <div class="col-12 form-group">
                        <label class="form-label">Store manager Aadhaar photos</label>
                        <div class="photo-upload-grid" style="grid-template-columns: repeat(2, minmax(0, 320px)); gap: 0.85rem;">
                            <div class="upload-box">
                                <input type="file" name="image_aadhar_card" id="image_aadhar_card" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-aadhar-card">
                                <div class="upload-title">Aadhaar front</div>
                                <div class="upload-hint">Optional · date-time stamp only</div>
                                <div class="upload-preview-container" id="preview-aadhar-card"></div>
                            </div>
                            <div class="upload-box">
                                <input type="file" name="image_aadhar_back" id="image_aadhar_back" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-aadhar-back">
                                <div class="upload-title">Aadhaar back</div>
                                <div class="upload-hint">Optional · date-time stamp only</div>
                                <div class="upload-preview-container" id="preview-aadhar-back"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>
        </div>
    </div>
    </div><!-- /step 1 -->

    <!-- ========================================== -->
    <!-- STEP 2: FREEZER INFO                       -->
    <!-- ========================================== -->
    <div class="checkout-step" data-step="2">
    <div class="card">
        <div class="card-body">
            <!-- Sub: Freezer photos -->
            <fieldset class="form-subsection">
                <legend>
                    Freezer Photos
                    <span>Left &amp; right side · required for verification</span>
                </legend>

                <div class="photo-upload-grid photo-upload-grid-2" style="margin-bottom: 0;">
                    <div class="upload-box" id="dropzone-left">
                        <input type="file" name="image_left_side" id="image_left_side" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-left" required>
                        <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                            <circle cx="12" cy="13" r="4"/>
                        </svg>
                        <div class="upload-title">Freezer Left *</div>
                        <div class="upload-hint">Required · date-time stamp only</div>
                        <div class="upload-preview-container" id="preview-left">
                            <img src="" alt="Left Side Preview">
                            <span class="preview-badge">Left</span>
                        </div>
                    </div>

                    <div class="upload-box" id="dropzone-right">
                        <input type="file" name="image_right_side" id="image_right_side" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-right" required>
                        <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                            <circle cx="12" cy="13" r="4"/>
                        </svg>
                        <div class="upload-title">Freezer Right *</div>
                        <div class="upload-hint">Required · date-time stamp only</div>
                        <div class="upload-preview-container" id="preview-right">
                            <img src="" alt="Right Side Preview">
                            <span class="preview-badge">Right</span>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- Sub: Freezer specs -->
            <fieldset class="form-subsection">
                <legend>
                    Freezer Specs
                    <span>Type / brand / model · builds Unique ID + QR</span>
                </legend>

                <div class="form-grid">
                    <div class="col-4 form-group">
                        <label class="form-label required" for="freezer_type">Freezer type</label>
                        <select id="freezer_type" name="freezer_type" class="form-select" required>
                            <?php foreach ($freezerTypes as $ft): ?>
                                <option value="<?= htmlspecialchars($ft['code']) ?>" <?= (($_POST['freezer_type'] ?? 'DF') === $ft['code']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ft['name']) ?> (<?= htmlspecialchars($ft['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
</div>

                    <div class="col-4 form-group">
                        <label class="form-label required" for="company_code">Brand / company</label>
                        <select id="company_code" name="company_code" class="form-select" required>
                            <?php foreach ($brands as $b): ?>
                                <option value="<?= htmlspecialchars($b['code']) ?>" <?= (($_POST['company_code'] ?? 'RW') === $b['code']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['name']) ?> (<?= htmlspecialchars($b['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
</div>

                    <div class="col-4 form-group">
                        <label class="form-label required" for="model_number">Model number</label>
                        <input type="text" id="model_number" name="model_number" class="form-control" placeholder="e.g. SFR550DDU" value="<?= htmlspecialchars($_POST['model_number'] ?? '') ?>" required maxlength="32" autocomplete="off">
                        <div class="form-error">Model number is required.</div>
</div>

                <div class="col-3 form-group">
                    <label class="form-label" for="location_code">Location code</label>
                    <input type="text" id="location_code" name="location_code" class="form-control form-control-display"
                           value="<?= htmlspecialchars(strtoupper(trim((string)($_POST['location_code'] ?? '')))) ?>"
                           placeholder="Auto from city"
                           readonly tabindex="-1" autocomplete="off">
                </div>

                    <div class="col-3 form-group">
                        <label class="form-label required" for="installation_date">Installation date</label>
                        <input type="date" id="installation_date" name="installation_date" class="form-control" value="<?= htmlspecialchars($_POST['installation_date'] ?? '') ?>" required>
                        <div class="form-error">Installation date is required.</div>
</div>

                    <div class="col-3 form-group">
                        <label class="form-label" for="capacity_litres">Capacity (litres)</label>
                        <select id="capacity_litres" name="capacity_litres" class="form-select">
                            <option value="">Select capacity</option>
                            <?php
                            $capSel = (string)($_POST['capacity_litres'] ?? '550');
                            foreach ($capacities as $cap):
                            ?>
                                <option value="<?= (int)$cap ?>" <?= $capSel === (string)$cap ? 'selected' : '' ?>><?= (int)$cap ?> litres</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 form-group">
                        <label class="form-label" for="freezer_condition">Freezer condition</label>
                        <select id="freezer_condition" name="freezer_condition" class="form-select">
                            <?php foreach ($conditions as $cond): ?>
                                <option value="<?= htmlspecialchars($cond) ?>" <?= (($_POST['freezer_condition'] ?? 'WORKING CONDITION') === $cond) ? 'selected' : '' ?>><?= htmlspecialchars($cond) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 form-group">
                        <label class="form-label" for="freezer_status">Operational status</label>
                        <select id="freezer_status" name="freezer_status" class="form-select">
                            <option value="Active" selected>Active (Online in RaaS)</option>
                            <option value="Maintenance">Maintenance Mode</option>
                            <option value="Under Inspection">Under Inspection</option>
                        </select>
                    </div>
                </div>
            </fieldset>
        </div>
    </div>
    </div><!-- /step 2 -->

    <!-- ========================================== -->
    <!-- STEP 3: REVIEW + SAVE / QR (IoT disabled) -->
    <!-- ========================================== -->
    <div class="checkout-step" data-step="3">
    <div class="card onboard-review-card" style="margin-bottom: 1rem;">
        <div class="card-body">
            <p class="onboard-review-label">Save summary</p>
            <div class="form-grid" style="gap: 0.75rem;">
                <div class="col-12" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:0.75rem;">
                    <div style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:0.35rem;">Store</div>
                    <div id="summary-store" style="font-weight:700; color:#0f172a;">—</div>
                    <div id="summary-store-meta" style="font-size:0.8rem; color:#64748b; margin-top:0.25rem;">—</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <?php if (AppConfig::IOT_ENABLED): ?>
            <p class="form-step-hint">
                Optional IoT photo &amp; serial. Then save — store + freezer are stored and QR is generated.
            </p>
            <?php else: ?>
            <p class="form-step-hint" style="margin-top:0;">
                Review details, then save — store + freezer are stored and the QR asset tag is generated.
            </p>
            <?php endif; ?>

            <?php if (AppConfig::IOT_ENABLED): ?>

            <p class="photo-step-sub" style="margin-top:0;">IoT photo <span style="font-weight:500; color:#94a3b8;">(recommended)</span></p>
            <div class="upload-box upload-box-featured" id="dropzone-iot" style="margin-bottom: 1.25rem;">
                <input type="file" name="image_iot_device" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-iot">
                <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
                    <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
                    <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
                    <line x1="12" y1="20" x2="12.01" y2="20"/>
                </svg>
                <div class="upload-title">IoT device photo</div>
                <div class="upload-hint">Mounted sensor · Camera</div>
                <div class="upload-preview-container" id="preview-iot">
                    <img src="" alt="IoT Device Preview">
                    <span class="preview-badge">IoT</span>
                </div>
            </div>

            <p class="photo-step-sub">Device details</p>
            <div class="form-grid">
                <div class="col-4 form-group">
                    <label class="form-label" for="iot_serial">IoT Device Serial / Asset Tag</label>
                    <input type="text" id="iot_serial" name="iot_serial" class="form-control" placeholder="RF-IOT-99215" value="RF-IOT-<?= rand(10000, 99999) ?>">
                </div>

                <div class="col-4 form-group">
                    <label class="form-label" for="iot_imei">Device IMEI / MAC</label>
                    <input type="text" id="iot_imei" name="iot_imei" class="form-control" placeholder="864201048291050" value="86420104829<?= rand(1000, 9999) ?>">
                </div>

                <div class="col-4 form-group">
                    <label class="form-label" for="iot_sim_number">SIM Number</label>
                    <input type="text" id="iot_sim_number" name="iot_sim_number" class="form-control" placeholder="<?= htmlspecialchars(AppConfig::DEFAULT_SIM_NUMBER) ?>" value="<?= htmlspecialchars(AppConfig::DEFAULT_SIM_NUMBER) ?>">
                </div>

                <div class="col-4 form-group">
                    <label class="form-label" for="iot_sim_company">SIM Company</label>
                    <select id="iot_sim_company" name="iot_sim_company" class="form-select">
                        <option value="">Select operator</option>
                        <option value="Jio">Jio</option>
                        <option value="Airtel" selected>Airtel</option>
                        <option value="VI">VI</option>
                        <option value="BSNL">BSNL</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="col-4 form-group">
                    <label class="form-label" for="iot_type">IoT Device Type</label>
                    <select id="iot_type" name="iot_type" class="form-select">
                        <option value="Temperature & GPS" selected>Temperature & GPS (Standard)</option>
                        <option value="Temperature Only">Temperature Only</option>
                        <option value="GPU & Compressor Status">GPU & Compressor Status</option>
                        <option value="Door & Power Monitor">Door & Power Monitor</option>
                    </select>
                </div>

                <div class="col-4 form-group">
                    <label class="form-label" for="iot_owner">Hardware Ownership</label>
                    <select id="iot_owner" name="iot_owner" class="form-select">
                        <option value="ReeferON Own Device" selected>ReeferON Own Device</option>
                        <option value="Outsourced Device">Outsourced OEM Device</option>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <fieldset class="form-subsection">
                <legend>
                    SIM Installation
                    <span>Number + photo of SIM / ICCID</span>
                </legend>
                <div class="form-grid" style="margin-bottom:0;">
                    <div class="col-6 form-group">
                        <label class="form-label" for="sim_number">SIM number</label>
                        <input type="text" id="sim_number" name="sim_number" class="form-control"
                               placeholder="<?= htmlspecialchars(AppConfig::DEFAULT_SIM_NUMBER) ?>" maxlength="40"
                               value="<?= htmlspecialchars($_POST['sim_number'] ?? AppConfig::DEFAULT_SIM_NUMBER) ?>" inputmode="numeric">
                    </div>
                    <div class="col-6 form-group">
                        <label class="form-label" for="sim_company">SIM company</label>
                        <select id="sim_company" name="sim_company" class="form-select">
                            <?php
                            $simCo = $_POST['sim_company'] ?? AppConfig::DEFAULT_SIM_COMPANY;
                            foreach (['' => 'Select operator', 'Jio' => 'Jio', 'Airtel' => 'Airtel', 'VI' => 'VI', 'BSNL' => 'BSNL', 'Other' => 'Other'] as $val => $label):
                            ?>
                                <option value="<?= htmlspecialchars($val) ?>" <?= $simCo === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 form-group">
                        <label class="form-label" for="image_sim">SIM number photo</label>
                        <div class="upload-box" id="dropzone-sim">
                            <input type="file" name="image_sim" id="image_sim" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-sim">
                            <div class="upload-icon">📷</div>
                            <div class="upload-title">SIM / ICCID photo</div>
                            <div class="upload-hint">Camera · Date stamp · Clear SIM number</div>
                            <div class="upload-preview-container" id="preview-sim">
                                <img src="" alt="SIM Preview">
                                <span class="preview-badge">SIM</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 form-group">
                        <label class="form-label" for="notes">Remark</label>
                        <input type="text" id="notes" name="notes" class="form-control" placeholder="Installation observations, plug location, target temp…" value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>">
                    </div>
                </div>
            </fieldset>
        </div>
    </div>
    </div><!-- /step 3 -->

    <!-- Hidden targets so ID generator JS still fills unique_freezer_id -->
    <span id="id-preview-display" hidden>DF-RW-SFR550DDU-AMD1-0324-380058-AAA004</span>
    <span id="id-status-badge" hidden></span>
    <span id="chip-type" hidden></span>
    <span id="chip-brand" hidden></span>
    <span id="chip-model" hidden></span>
    <span id="chip-loc" hidden></span>
    <span id="chip-pin" hidden></span>
    <span id="chip-mmyy" hidden></span>
    <span id="chip-seq" hidden></span>
    </div><!-- /checkout-body -->

    <div class="checkout-footer onboard-footer">
        <button type="button" class="btn btn-checkout" id="checkout-next-btn">
            Continue
        </button>
        <button type="submit" class="btn btn-checkout" id="checkout-submit-btn" style="display:none;">
            Save &amp; link to QR
        </button>
        <div class="checkout-nav-row">
            <button type="button" class="btn btn-secondary" id="checkout-back-btn" style="flex:1; display:none;">Back</button>
            <a href="qr_scanner.php" class="btn btn-outline" style="flex:1; justify-content:center;">Cancel</a>
        </div>
    </div>
</form>
</div><!-- /checkout-card -->
</div><!-- /checkout-page -->

<script>
window.GEO_HIERARCHY = <?= json_encode(MasterData::getGeoHierarchy()) ?>;
window.CITY_LOC_PREFIXES = <?= json_encode(MasterData::getCityLocationPrefixes()) ?>;

document.addEventListener('DOMContentLoaded', function() {
    let step = 1;
    const maxStep = 3;
    const steps = document.querySelectorAll('.checkout-step');
    const methods = document.querySelectorAll('.checkout-method');
    const nextBtn = document.getElementById('checkout-next-btn');
    const backBtn = document.getElementById('checkout-back-btn');
    const submitBtn = document.getElementById('checkout-submit-btn');
    const previewDisplay = document.getElementById('id-preview-display');

    function setFieldError(el, on) {
        if (!el) return;
        const group = el.closest('.form-group');
        el.classList.toggle('is-invalid', on);
        if (group) group.classList.toggle('has-error', on);
    }

    function hasFile(inputId) {
        const el = document.getElementById(inputId);
        return !!(el && el.files && el.files.length > 0);
    }

    function updateSummary() {
        const storeName = (document.getElementById('store_name')?.value || '').trim() || '—';
        const city = (document.getElementById('city')?.value || '').trim();
        const pin = (document.getElementById('pincode')?.value || '').trim();
        const customer = document.getElementById('customer_name')?.value || '';
        const meta = [customer, city, pin ? ('PIN ' + pin) : ''].filter(Boolean).join(' · ') || '—';
        const sEl = document.getElementById('summary-store');
        const mEl = document.getElementById('summary-store-meta');
        if (sEl) sEl.textContent = storeName;
        if (mEl) mEl.textContent = meta;
    }

    function validateStep(n) {
        let ok = true;
        if (n === 1) {
            const box = document.getElementById('dropzone-store-front');
            const hasSf = hasFile('image_store_front');
            if (box) box.classList.toggle('is-invalid', !hasSf);
            if (!hasSf) {
                ok = false;
                if (typeof setExifStatus === 'function') {
                    setExifStatus('Store Front photo required.', 'warn');
                }
            }

            const store = document.getElementById('store_name');
            const pin = document.getElementById('pincode');
            const address = document.getElementById('address');
            const city = document.getElementById('city');
            const phone = document.getElementById('phone_number');

            [[store, !store.value.trim()],
             [address, !address.value.trim()],
             [city, !city.value.trim()],
             [pin, !/^\d{6}$/.test(pin.value.trim())]
            ].forEach(([el, bad]) => {
                setFieldError(el, bad);
                if (bad) ok = false;
            });

            if (phone) {
                const digits = (typeof normalizeIndianMobileDigits === 'function')
                    ? normalizeIndianMobileDigits(phone.value)
                    : phone.value.replace(/\D/g, '').slice(-10);
                phone.value = digits;
                // Empty = optional OK; otherwise must be 10 digits starting 6–9
                const phoneBad = digits !== '' && !/^[6-9]\d{9}$/.test(digits);
                setFieldError(phone, phoneBad);
                if (phoneBad) ok = false;
            }

            const aadhar = document.getElementById('aadhar_number');
            if (aadhar && aadhar.value.trim() !== '') {
                const aadharBad = !/^\d{12}$/.test(aadhar.value.replace(/\D/g, ''));
                setFieldError(aadhar, aadharBad);
                if (aadharBad) ok = false;
            }

            const fssai = document.getElementById('fssai_number');
            if (fssai && fssai.value.trim() !== '') {
                const fssaiBad = !/^\d{14}$/.test(fssai.value.replace(/\D/g, ''));
                setFieldError(fssai, fssaiBad);
                if (fssaiBad) ok = false;
            }

            const gst = document.getElementById('gst_number');
            if (gst && gst.value.trim() !== '') {
                const gstVal = gst.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                const gstBad = !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(gstVal);
                setFieldError(gst, gstBad);
                if (gstBad) ok = false;
            }

            const email = document.getElementById('email');
            if (email && email.value.trim() !== '') {
                const emailBad = !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());
                setFieldError(email, emailBad);
                if (emailBad) ok = false;
            }
        }
        if (n === 2) {
            const leftOk = hasFile('image_left_side');
            const rightOk = hasFile('image_right_side');
            const leftBox = document.getElementById('dropzone-left');
            const rightBox = document.getElementById('dropzone-right');
            if (leftBox) leftBox.classList.toggle('is-invalid', !leftOk);
            if (rightBox) rightBox.classList.toggle('is-invalid', !rightOk);
            if (!leftOk || !rightOk) ok = false;

            const model = document.getElementById('model_number');
            const date = document.getElementById('installation_date');
            [[model, !model.value.trim()],
             [date, !date.value]
            ].forEach(([el, bad]) => {
                setFieldError(el, bad);
                if (bad) ok = false;
            });
        }
        return ok;
    }

    function goStep(n) {
        step = Math.max(1, Math.min(maxStep, n));
        steps.forEach(s => s.classList.toggle('active', Number(s.dataset.step) === step));
        methods.forEach(m => m.classList.toggle('active', Number(m.dataset.goto) === step));
        backBtn.style.display = step > 1 ? 'inline-flex' : 'none';
        nextBtn.style.display = step < maxStep ? 'inline-flex' : 'none';
        submitBtn.style.display = step === maxStep ? 'inline-flex' : 'none';
        if (nextBtn) {
            nextBtn.textContent = step === 1 ? 'Continue to Freezer' : (step === 2 ? 'Continue to Review' : 'Continue');
        }
        if (step === 3) updateSummary();
    }

    // Tabs: free jump to Store Info / Freezer Info / Review & QR
    methods.forEach(m => m.addEventListener('click', () => {
        goStep(Number(m.dataset.goto));
    }));

    nextBtn.addEventListener('click', () => {
        if (typeof isAnyImageProcessing === 'function' && isAnyImageProcessing()) {
            alert('Please wait — a photo is still being processed.');
            return;
        }
        if (!validateStep(step)) return;
        goStep(step + 1);
    });
    backBtn.addEventListener('click', () => goStep(step - 1));

    window.goCheckoutStep = function (n, skipValidate) {
        if (skipValidate || Number(n) === step) {
            goStep(Number(n));
            return;
        }
        // Programmatic forward still validates current step; tabs use goStep directly
        if (Number(n) < step || validateStep(step)) {
            goStep(Number(n));
        }
    };

    document.getElementById('onboard-form').addEventListener('submit', function (e) {
        if (typeof isAnyImageProcessing === 'function' && isAnyImageProcessing()) {
            e.preventDefault();
            alert('Please wait — a photo is still being processed and analyzed.');
            return;
        }
        for (let i = 1; i <= maxStep; i++) {
            if (!validateStep(i)) {
                e.preventDefault();
                goStep(i);
                return;
            }
        }
    });

    document.querySelectorAll('#onboard-form .form-control, #onboard-form .form-select').forEach(el => {
        el.addEventListener('input', () => setFieldError(el, false));
        el.addEventListener('change', () => setFieldError(el, false));
    });

    ['image_left_side', 'image_right_side', 'image_store_front', 'image_aadhar_card', 'image_aadhar_back', 'image_sim'].forEach(id => {
        const input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('change', () => {
            const box = input.closest('.upload-box');
            if (box && input.files && input.files.length) box.classList.remove('is-invalid');
        });
    });

    const phoneInput = document.getElementById('phone_number');
    if (phoneInput) {
        phoneInput.addEventListener('input', function () {
            if (typeof normalizeIndianMobileDigits === 'function') {
                this.value = normalizeIndianMobileDigits(this.value);
            } else {
                this.value = this.value.replace(/\D/g, '').slice(0, 10);
            }
            // Clear error as soon as value becomes valid / empty
            const digits = this.value;
            const phoneBad = digits !== '' && !/^[6-9]\d{9}$/.test(digits);
            setFieldError(this, phoneBad);
        });
    }
    const pinInput = document.getElementById('pincode');
    if (pinInput) {
        pinInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
    }
    const aadharInput = document.getElementById('aadhar_number');
    if (aadharInput) {
        aadharInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 12);
        });
    }
    const fssaiInput = document.getElementById('fssai_number');
    if (fssaiInput) {
        fssaiInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 14);
        });
    }
    const gstInput = document.getElementById('gst_number');
    if (gstInput) {
        gstInput.addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 15);
        });
    }

    const syncId = () => {
        if (previewDisplay && idSummary) idSummary.textContent = previewDisplay.textContent.trim() || 'Preview…';
        if (step === 3) updateSummary();
    };
    setInterval(syncId, 600);
    goStep(1);
});
</script>

<?php endif; /* requireScanFirst else — form */ ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
