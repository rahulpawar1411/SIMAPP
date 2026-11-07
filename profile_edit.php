<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\FreezerIdGenerator;
use App\Services\FileUploadService;
use App\Services\UndoService;
use App\Config\AppConfig;
use App\Models\MasterData;
use App\Models\Store;
use App\Models\Freezer;
use App\Models\AssetTag;
use App\Models\AppNotification;

AuthService::requireRole(['super_admin', 'technician']);
Freezer::ensureSimColumns();
$currentUser = AuthService::currentUser();
$isTechnician = (($currentUser['role'] ?? '') === AppConfig::ROLE_TECHNICIAN);
$areaScope = AuthService::areaScope();
$lockZone = $areaScope['zone'] !== '';
$lockCity = $areaScope['city'] !== '';

$id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$freezer = Freezer::findById($id);

if (!$freezer) {
    die("<h1>Profile Not Found</h1><p>Freezer profile #{$id} does not exist.</p><p><a href='profiles.php'>Back to Profiles</a></p>");
}

if (!AuthService::canAccessArea($freezer['store_zone'] ?? $freezer['zone'] ?? null, $freezer['store_city'] ?? null)) {
    http_response_code(403);
    die("<h1>403 Forbidden</h1><p>This store is outside your assigned zone/city.</p><p><a href='profiles.php'>Back to Assets</a></p>");
}

$error = null;
$success = null;
$csrfToken = AuthService::generateCsrfToken();
$pendingEdit = null;
$editUnlock = null;
try {
    $pendingEdit = AppNotification::findPendingProfileEdit((int)$freezer['id']);
    if ($isTechnician && !empty($currentUser['id'])) {
        $editUnlock = AppNotification::findActiveEditUnlock((int)$freezer['id'], (int)$currentUser['id']);
    }
} catch (Throwable $e) {
    $pendingEdit = null;
    $editUnlock = null;
}
$canEditNow = !$isTechnician || ($editUnlock !== null);

$freezerTypes = MasterData::getFreezerTypes();
$brands = MasterData::getBrands();
$customers = MasterData::getCustomerList();
$conditions = MasterData::getConditions();
$capacities = MasterData::getCapacities();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string)($_POST['form_action'] ?? 'save'));
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security CSRF token.';
    } elseif ($isTechnician && $postAction === 'request_edit') {
        // Step 1: technician submits remark → Super Admin notification
        try {
            if ($pendingEdit) {
                throw new Exception('An edit request is already waiting for Super Admin approval.');
            }
            if ($editUnlock) {
                throw new Exception('Edit access is already approved. You can edit the profile now.');
            }
            $remark = trim((string)($_POST['edit_remark'] ?? ''));
            if ($remark === '' || mb_strlen($remark) < 5) {
                throw new Exception('Please enter a remark (at least 5 characters) explaining why you need to edit.');
            }
            if (mb_strlen($remark) > 1000) {
                throw new Exception('Remark is too long (max 1000 characters).');
            }
            $techName = trim((string)($currentUser['name'] ?? 'Technician'));
            AppNotification::create([
                'type' => AppNotification::TYPE_PROFILE_EDIT,
                'status' => AppNotification::STATUS_PENDING,
                'freezer_id' => $id,
                'unique_freezer_id' => $freezer['unique_freezer_id'] ?? null,
                'title' => 'Edit access — ' . ($freezer['unique_freezer_id'] ?? ('#' . $id)),
                'message' => $techName . ' requested permission to edit this profile.',
                'created_by' => (int)($currentUser['id'] ?? 0) ?: null,
                'created_by_name' => $techName,
                'payload' => [
                    'request_kind' => 'edit_access',
                    'remark' => $remark,
                    'unlocked' => false,
                    'consumed' => false,
                ],
            ]);
            header('Location: profile_view.php?id=' . $id . '&edit_pending=1');
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($isTechnician && !$canEditNow) {
        $error = $pendingEdit
            ? 'Your remark is waiting for Super Admin approval. You can edit only after approval.'
            : 'Submit a remark first. After Super Admin approval you can edit.';
    } else {
        try {
            $undoSnapshot = UndoService::captureProfileSnapshot($freezer);
            $editTag = AssetTag::findByFreezerId((int)$freezer['id']);
            $imgNameMeta = [
                'tag_code' => $editTag['tag_code'] ?? preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string)$freezer['unique_freezer_id'])),
                'store_name' => trim((string)($_POST['store_name'] ?? $freezer['store_name'] ?? 'Store')),
                'datetime' => date('Ymd'),
            ];

            // Check for new image uploads — Tag-date-Store-ImageType
            $imgStoreFront = !empty($_FILES['image_store_front']['name'])
                ? FileUploadService::handleImageUpload($_FILES['image_store_front'], 'stores', 'store_front', $imgNameMeta)
                : null;
            if ($imgStoreFront && !$imgStoreFront['success']) {
                throw new Exception("Store Front Image Error: " . $imgStoreFront['error']);
            }

            $imgAadhar = !empty($_FILES['image_aadhar_card']['name'])
                ? FileUploadService::handleImageUpload($_FILES['image_aadhar_card'], 'stores', 'aadhar_front', $imgNameMeta)
                : null;
            if ($imgAadhar && !$imgAadhar['success']) {
                throw new Exception("Aadhaar Front Image Error: " . $imgAadhar['error']);
            }

            $imgAadharBack = !empty($_FILES['image_aadhar_back']['name'])
                ? FileUploadService::handleImageUpload($_FILES['image_aadhar_back'], 'stores', 'aadhar_back', $imgNameMeta)
                : null;
            if ($imgAadharBack && !$imgAadharBack['success']) {
                throw new Exception("Aadhaar Back Image Error: " . $imgAadharBack['error']);
            }

            $imgLeftResult = !empty($_FILES['image_left_side']['name'])
                ? FileUploadService::handleImageUpload($_FILES['image_left_side'], 'freezers', 'frz_left', $imgNameMeta)
                : null;
            if ($imgLeftResult && !$imgLeftResult['success']) {
                throw new Exception("Left Side Image Error: " . $imgLeftResult['error']);
            }

            $imgRightResult = !empty($_FILES['image_right_side']['name'])
                ? FileUploadService::handleImageUpload($_FILES['image_right_side'], 'freezers', 'frz_right', $imgNameMeta)
                : null;
            if ($imgRightResult && !$imgRightResult['success']) {
                throw new Exception("Right Side Image Error: " . $imgRightResult['error']);
            }

            $imgIotResult = null;
            if (AppConfig::IOT_ENABLED && !empty($_FILES['image_iot_device']['name'])) {
                $imgIotResult = FileUploadService::handleImageUpload($_FILES['image_iot_device'], 'iot', 'iot_dev', $imgNameMeta);
                if ($imgIotResult && !$imgIotResult['success']) {
                    throw new Exception("IoT Device Image Error: " . $imgIotResult['error']);
                }
            }

            $imgSimResult = null;
            if (!empty($_FILES['image_sim']['name'])) {
                $imgSimResult = FileUploadService::handleImageUpload($_FILES['image_sim'], 'freezers', 'sim_img', $imgNameMeta);
                if (!$imgSimResult['success']) {
                    throw new Exception('SIM photo error: ' . $imgSimResult['error']);
                }
            }

            // Update Store — Outlet ID locked (auto at create; no typing)
            // Area-scoped technicians cannot change zone/city outside assignment
            $storePayload = AuthService::applyAreaToStoreData([
                'outlet_id' => $freezer['outlet_id'],
                'store_name' => $_POST['store_name'],
                'customer_name' => $_POST['customer_name'] ?? $freezer['customer_name'],
                'distributor_name' => $_POST['distributor_name'] ?? '',
                'contact_person' => $_POST['contact_person'] ?? '',
                'phone_number' => $_POST['phone_number'] ?? '',
                'email' => $_POST['email'] ?? '',
                'aadhar_number' => $_POST['aadhar_number'] ?? '',
                'fssai_number' => $_POST['fssai_number'] ?? '',
                'gst_number' => $_POST['gst_number'] ?? '',
                'address' => $_POST['address'],
                'city' => $_POST['city'],
                'state' => $_POST['state'],
                'pincode' => $_POST['pincode'],
                'zone' => $_POST['zone'] ?? 'WEST',
                'location_type' => $_POST['location_type'] ?? 'Retail Store',
                'latitude' => !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null,
                'longitude' => !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null,
                'gps_accuracy' => !empty($_POST['gps_accuracy']) ? (float)$_POST['gps_accuracy'] : null,
                'location_link' => !empty($_POST['location_link'])
                    ? trim((string)$_POST['location_link'])
                    : (!empty($_POST['latitude']) && !empty($_POST['longitude'])
                        ? ('https://maps.google.com/?q=' . rawurlencode((float)$_POST['latitude'] . ',' . (float)$_POST['longitude']))
                        : null),
                'image_store_front' => $imgStoreFront ? $imgStoreFront['path'] : null,
                'image_aadhar_card' => $imgAadhar ? $imgAadhar['path'] : null,
                'image_aadhar_back' => $imgAadharBack ? $imgAadharBack['path'] : null,
                'status' => 'Active',
            ]);
            if (!AuthService::canAccessArea($storePayload['zone'] ?? null, $storePayload['city'] ?? null)) {
                throw new Exception('Zone/city is outside your assigned area.');
            }

            // Update Freezer Profile — Unique Freezer ID never changes after create
            $candidateId = $freezer['unique_freezer_id'];
            $installDate = $_POST['installation_date'] ?? $freezer['installation_date'];
            $installMmYy = date('my', strtotime($installDate));

            $updatePayload = [
                'unique_freezer_id' => $candidateId,
                'store_id' => $freezer['store_id'],
                'freezer_type' => $_POST['freezer_type'] ?? $freezer['freezer_type'],
                'company_code' => $_POST['company_code'] ?? $freezer['company_code'],
                'model_number' => $_POST['model_number'] ?? $freezer['model_number'],
                'capacity_litres' => !empty($_POST['capacity_litres']) ? (int)$_POST['capacity_litres'] : $freezer['capacity_litres'],
                'freezer_condition' => $_POST['freezer_condition'] ?? $freezer['freezer_condition'],
                'location_code' => $_POST['location_code'] ?? $freezer['location_code'],
                'pincode_last3' => FreezerIdGenerator::extractPinCode($_POST['pincode'] ?? ''),
                'install_month_year' => $installMmYy,
                'unit_sequence' => $freezer['unit_sequence'] ?? null,
                'installation_date' => $installDate,
                'image_left_side' => $imgLeftResult ? $imgLeftResult['path'] : null,
                'image_right_side' => $imgRightResult ? $imgRightResult['path'] : null,
                'image_sim' => $imgSimResult ? $imgSimResult['path'] : null,
                'sim_number' => trim((string)($_POST['sim_number'] ?? '')),
                'sim_company' => trim((string)($_POST['sim_company'] ?? '')),
                'status' => $_POST['freezer_status'] ?? $freezer['status'],
                'notes' => $_POST['notes'] ?? '',
            ];

            if (AppConfig::IOT_ENABLED) {
                $updatePayload['image_iot_device'] = $imgIotResult ? $imgIotResult['path'] : null;
                $updatePayload['iot_serial'] = $_POST['iot_serial'] ?? $freezer['device_serial'];
                $updatePayload['iot_imei'] = $_POST['iot_imei'] ?? $freezer['imei_or_mac'];
                $updatePayload['iot_sim_number'] = trim($_POST['iot_sim_number'] ?? '')
                    ?: ($updatePayload['sim_number'] ?: ($freezer['sim_number'] ?? null));
                $updatePayload['iot_sim_company'] = trim($_POST['iot_sim_company'] ?? '')
                    ?: ($updatePayload['sim_company'] ?: ($freezer['sim_company'] ?? null));
                $updatePayload['iot_type'] = $_POST['iot_type'] ?? $freezer['device_type'];
                $updatePayload['iot_owner'] = $_POST['iot_owner'] ?? $freezer['device_owner'];
            }

            if ($isTechnician && !$editUnlock) {
                throw new Exception('Edit access is not approved yet. Submit a remark and wait for Super Admin approval.');
            }

            Store::update((int)$freezer['store_id'], $storePayload);
            Freezer::update($id, $updatePayload);

            if ($isTechnician && $editUnlock) {
                AppNotification::consumeEditUnlock((int)$editUnlock['id']);
            }

            $undoToken = UndoService::remember('edit', $undoSnapshot);
            header('Location: profile_view.php?id=' . $id . '&updated=1&undo=' . urlencode($undoToken));
            exit;

        } catch (Exception $e) {
            $error = $e->getMessage();
            $freezer = Freezer::findById($id); // refresh
        }
    }
}

$pageTitle = $canEditNow
    ? ('Edit Freezer: ' . $freezer['unique_freezer_id'])
    : ('Request edit: ' . $freezer['unique_freezer_id']);
require_once __DIR__ . '/includes/header.php';

$pendingRemark = '';
if ($pendingEdit) {
    $pendingPayload = AppNotification::decodePayload($pendingEdit);
    $pendingRemark = trim((string)($pendingPayload['remark'] ?? ''));
}
?>

<div class="cfg-toolbar">
    <div class="cfg-toolbar-left">
        <h1 class="cfg-toolbar-title">
            <?= $canEditNow ? 'Edit Freezer Configuration' : 'Request edit access' ?>
        </h1>
    </div>
    <div style="display:flex; gap:0.5rem; flex-shrink:0;">
        <a href="profile_view.php?id=<?= $freezer['id'] ?>" class="btn btn-secondary">Cancel</a>
        <?php if ($canEditNow): ?>
            <button type="submit" form="edit-freezer-form" class="btn btn-primary cfg-done-btn">Done</button>
        <?php elseif (!$pendingEdit): ?>
            <button type="submit" form="edit-request-form" class="btn btn-primary cfg-done-btn">Send remark</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if ($isTechnician && !$canEditNow): ?>
    <?php if ($pendingEdit): ?>
        <div class="card" style="max-width:720px;">
            <div class="card-body">
                <div class="alert alert-warning" style="margin-bottom:1rem;">
                    Your remark was sent. Waiting for Super Admin approval. After approval you can edit this profile.
                </div>
                <?php if ($pendingRemark !== ''): ?>
                    <label class="form-label">Your remark</label>
                    <div class="form-control" style="min-height:88px;background:#f8fafc;white-space:pre-wrap;"><?= htmlspecialchars($pendingRemark) ?></div>
                <?php endif; ?>
                <p style="margin:0.85rem 0 0;color:#64748b;font-size:0.9rem;">
                    Requested <?= htmlspecialchars((string)($pendingEdit['created_at'] ?? '')) ?>
                </p>
                <div style="margin-top:1rem;">
                    <a href="profile_view.php?id=<?= (int)$freezer['id'] ?>" class="btn btn-secondary">Back to profile</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="max-width:720px;">
            <div class="card-header">
                <div class="card-title">Why do you need to edit?</div>
            </div>
            <div class="card-body">
                <p style="margin:0 0 1rem;color:#64748b;font-size:0.92rem;">
                    First send a remark to Super Admin. After approval, you will be able to edit this freezer profile.
                </p>
                <form method="POST" action="" id="edit-request-form" class="cfg-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="request_edit">
                    <div class="form-group">
                        <label class="form-label required" for="edit_remark">Remark</label>
                        <textarea id="edit_remark" name="edit_remark" class="form-control" rows="5" required
                                  minlength="5" maxlength="1000"
                                  placeholder="Example: Wrong store phone / address correction / SIM details update"><?= htmlspecialchars((string)($_POST['edit_remark'] ?? '')) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Send for approval</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>

<?php if ($isTechnician && $editUnlock): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;">
        Edit access approved. Make your changes and tap Done. This unlock is for one save (expires in 24 hours).
    </div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data" class="cfg-form" id="edit-freezer-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="form_action" value="save">
    <input type="hidden" id="exclude_freezer_id" value="<?= (int)$freezer['id'] ?>">

    <input type="hidden" id="unique_freezer_id" name="unique_freezer_id" value="<?= htmlspecialchars($freezer['unique_freezer_id']) ?>">
    <span id="id-preview-display" hidden><?= htmlspecialchars($freezer['unique_freezer_id']) ?></span>
    <span id="id-status-badge" hidden></span>

    <!-- Store Profile (GPS from Store Front photo) -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                <span>Store Profile</span>
            </div>
        </div>
        <div class="card-body">
            <input type="hidden" id="latitude" name="latitude" value="<?= htmlspecialchars($freezer['store_latitude'] ?? '') ?>">
            <input type="hidden" id="longitude" name="longitude" value="<?= htmlspecialchars($freezer['store_longitude'] ?? '') ?>">
            <input type="hidden" id="gps_accuracy" name="gps_accuracy" value="<?= htmlspecialchars($freezer['store_gps_accuracy'] ?? '') ?>">
            <?php
            $editLocLink = trim((string)($freezer['store_location_link'] ?? ''));
            if ($editLocLink === '' && !empty($freezer['store_latitude']) && !empty($freezer['store_longitude'])) {
                $editLocLink = 'https://maps.google.com/?q=' . rawurlencode($freezer['store_latitude'] . ',' . $freezer['store_longitude']);
            }
            ?>
            <input type="hidden" id="location_link" name="location_link" value="<?= htmlspecialchars($editLocLink) ?>">

            <div class="form-grid">
                <div class="col-6 form-group">
                    <label class="form-label required" for="store_name">Store Name</label>
                    <input type="text" id="store_name" name="store_name" class="form-control" value="<?= htmlspecialchars($freezer['store_name']) ?>" required>
                </div>
                <div class="col-3 form-group">
                    <label class="form-label" for="outlet_id">Outlet ID</label>
                    <input type="text" id="outlet_id" class="form-control" value="<?= htmlspecialchars($freezer['outlet_id']) ?>" readonly
                           style="background:#f1f5f9; font-family:var(--font-mono); cursor:default;" tabindex="-1">
                </div>
                <div class="col-3 form-group">
                    <label class="form-label" for="customer_name">Customer Account</label>
                    <select id="customer_name" name="customer_name" class="form-select">
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= ($freezer['customer_name'] === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" id="state" name="state" value="<?= htmlspecialchars($freezer['store_state'] ?? '') ?>">
                <div class="col-4 form-group">
                    <label class="form-label required" for="zone">Zone</label>
                    <?php
                    $editZoneVal = $lockZone ? $areaScope['zone'] : (string)($freezer['store_zone'] ?? 'WEST');
                    $editZones = $lockZone ? [$areaScope['zone']] : AppConfig::zones();
                    ?>
                    <select id="zone" name="zone" class="form-select" required <?= $lockZone ? 'disabled' : '' ?>>
                        <?php foreach ($editZones as $z): ?>
                            <option value="<?= htmlspecialchars($z) ?>" <?= $editZoneVal === $z ? 'selected' : '' ?>><?= htmlspecialchars($z) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($lockZone): ?>
                        <input type="hidden" name="zone" value="<?= htmlspecialchars($areaScope['zone']) ?>">
                    <?php endif; ?>
                </div>
                <div class="col-8 form-group">
                    <label class="form-label required" for="city">City</label>
                    <?php $editCityVal = $lockCity ? $areaScope['city'] : (string)($freezer['store_city'] ?? ''); ?>
                    <input type="text" id="city" name="city" class="form-control" value="<?= htmlspecialchars($editCityVal) ?>" placeholder="Select zone, then type city…" required autocomplete="off" <?= $lockCity ? 'readonly' : '' ?>>
                    <?php if (!$lockCity): ?><datalist id="city-list"></datalist><?php endif; ?>
                </div>
                <div class="col-12 form-group">
                    <label class="form-label required" for="address">Address</label>
                    <input type="text" id="address" name="address" class="form-control" value="<?= htmlspecialchars($freezer['store_address']) ?>" required>
                </div>
                <div class="col-4 form-group">
                    <label class="form-label required" for="pincode">PIN code</label>
                    <input type="text" id="pincode" name="pincode" class="form-control" value="<?= htmlspecialchars($freezer['store_pincode']) ?>" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" required>
                </div>
                <div class="col-8 form-group">
                    <label class="form-label" for="contact_person">Store operator name</label>
                    <input type="text" id="contact_person" name="contact_person" class="form-control" value="<?= htmlspecialchars($freezer['contact_person'] ?? '') ?>">
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="phone_number">Operator phone / mobile</label>
                    <?php
                        $editPhone = preg_replace('/\D/', '', (string)($freezer['phone_number'] ?? ''));
                        if (strlen($editPhone) === 12 && str_starts_with($editPhone, '91')) {
                            $editPhone = substr($editPhone, 2);
                        }
                        $editPhone = substr($editPhone, -10);
                    ?>
                    <div class="phone-input-group">
                        <span class="phone-prefix" aria-hidden="true">+91</span>
                        <input type="tel" id="phone_number" name="phone_number" class="form-control" value="<?= htmlspecialchars($editPhone) ?>" maxlength="10" inputmode="numeric" placeholder="10-digit mobile" title="10-digit Indian mobile (starts with 6–9)" autocomplete="tel-national">
                    </div>
                    <div class="form-error">Enter a valid 10-digit mobile starting with 6, 7, 8 or 9.</div>
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="email">Store operator email ID</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($freezer['store_email'] ?? '') ?>" placeholder="operator@example.com" autocomplete="email">
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="aadhar_number">Store operator Aadhaar number</label>
                    <input type="text" id="aadhar_number" name="aadhar_number" class="form-control" value="<?= htmlspecialchars($freezer['aadhar_number'] ?? '') ?>" placeholder="12-digit Aadhaar" maxlength="12" inputmode="numeric" pattern="[0-9]{12}" title="Exactly 12 digits" autocomplete="off">
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="fssai_number">FSSAI number</label>
                    <input type="text" id="fssai_number" name="fssai_number" class="form-control" value="<?= htmlspecialchars($freezer['fssai_number'] ?? '') ?>" placeholder="14-digit FSSAI licence" maxlength="14" inputmode="numeric" pattern="[0-9]{14}" title="Exactly 14 digits" autocomplete="off">
</div>
                <div class="col-6 form-group">
                    <label class="form-label" for="gst_number">GST number</label>
                    <input type="text" id="gst_number" name="gst_number" class="form-control" value="<?= htmlspecialchars($freezer['gst_number'] ?? '') ?>" placeholder="15-character GSTIN" maxlength="15" pattern="[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]" title="Valid 15-character GSTIN" autocomplete="off" style="text-transform: uppercase;">
</div>
            </div>
        </div>
    </div>

    <!-- Freezer Specifications -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                </svg>
                <span>Freezer Specifications</span>
            </div>
        </div>
        <div class="card-body">
            <div class="form-grid">
                <div class="col-4 form-group">
                    <label class="form-label required" for="freezer_type">Freezer Type</label>
                    <select id="freezer_type" name="freezer_type" class="form-select" required>
                        <?php foreach ($freezerTypes as $ft): ?>
                            <option value="<?= htmlspecialchars($ft['code']) ?>" <?= ($freezer['freezer_type'] === $ft['code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ft['name']) ?> (<?= htmlspecialchars($ft['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
</div>
                <div class="col-4 form-group">
                    <label class="form-label required" for="company_code">Brand / OEM</label>
                    <select id="company_code" name="company_code" class="form-select" required>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= htmlspecialchars($b['code']) ?>" <?= ($freezer['company_code'] === $b['code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['name']) ?> (<?= htmlspecialchars($b['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
</div>
                <div class="col-4 form-group">
                    <label class="form-label required" for="model_number">Model Number</label>
                    <input type="text" id="model_number" name="model_number" class="form-control" value="<?= htmlspecialchars($freezer['model_number']) ?>" required>
                </div>
                <div class="col-3 form-group">
                    <label class="form-label" for="location_code">Location code</label>
                    <input type="text" id="location_code" name="location_code" class="form-control form-control-display"
                           value="<?= htmlspecialchars(strtoupper(trim((string)($freezer['location_code'] ?? '')))) ?>"
                           placeholder="Auto from city"
                           readonly tabindex="-1" autocomplete="off">
                </div>
                <div class="col-3 form-group">
                    <label class="form-label required" for="installation_date">Installation Date</label>
                    <input type="date" id="installation_date" name="installation_date" class="form-control" value="<?= htmlspecialchars($freezer['installation_date']) ?>" required>
                </div>
                <div class="col-3 form-group">
                    <label class="form-label">Unit sequence</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($freezer['unit_sequence'] ?? '') ?>" readonly disabled>
</div>
                <div class="col-3 form-group">
                    <label class="form-label" for="freezer_status">Status</label>
                    <select id="freezer_status" name="freezer_status" class="form-select">
                        <option value="Active" <?= $freezer['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Maintenance" <?= $freezer['status'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="Under Inspection" <?= $freezer['status'] === 'Under Inspection' ? 'selected' : '' ?>>Under Inspection</option>
                    </select>
                </div>
                <div class="col-3 form-group">
                    <label class="form-label" for="capacity_litres">Capacity (litres)</label>
                    <?php $capEdit = (string)($freezer['capacity_litres'] ?? '550'); ?>
                    <select id="capacity_litres" name="capacity_litres" class="form-select">
                        <option value="">Select capacity</option>
                        <?php foreach ($capacities as $cap): ?>
                            <option value="<?= (int)$cap ?>" <?= $capEdit === (string)$cap ? 'selected' : '' ?>><?= (int)$cap ?> litres</option>
                        <?php endforeach; ?>
                        <?php if ($capEdit !== '' && !in_array((int)$capEdit, $capacities, true)): ?>
                            <option value="<?= (int)$capEdit ?>" selected><?= (int)$capEdit ?> litres</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="freezer_condition">Condition</label>
                    <select id="freezer_condition" name="freezer_condition" class="form-select">
                        <?php foreach ($conditions as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= ($freezer['freezer_condition'] === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Photos -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                </svg>
                <span>Replace / Update Images (Optional)</span>
            </div>
        </div>
        <div class="card-body">
            <div id="store-front-exif-status" class="store-front-exif-status" style="display:none;" role="status"></div>
            <p style="font-size: var(--fs-xs, 0.75rem); color: #64748b; margin-bottom: 1rem;">
                New <strong>Store Front</strong> photo gets a date &amp; time stamp. Use Save my current location for GPS.
            </p>
            <div class="photo-upload-grid photo-upload-grid-4">
                <!-- Store Front -->
                <div class="upload-box">
                    <input type="file" name="image_store_front" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-store-front">
                    <div class="upload-title">Change Store Front Photo</div>
                    <div class="upload-hint">Date-time stamp only</div>
                    <div class="upload-preview-container" id="edit-preview-store-front" style="<?= !empty($freezer['image_store_front']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_store_front'] ?? '') ?>" alt="Store Front" loading="lazy" decoding="async">
                    </div>
                </div>

                <!-- Aadhaar front -->
                <div class="upload-box">
                    <input type="file" name="image_aadhar_card" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-aadhar">
                    <div class="upload-title">Aadhaar front</div>
                    <div class="upload-hint">Store manager ID</div>
                    <div class="upload-preview-container" id="edit-preview-aadhar" style="<?= !empty($freezer['image_aadhar_card']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_aadhar_card'] ?? '') ?>" alt="Aadhaar Front" loading="lazy" decoding="async">
                    </div>
                </div>

                <!-- Aadhaar back -->
                <div class="upload-box">
                    <input type="file" name="image_aadhar_back" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-aadhar-back">
                    <div class="upload-title">Aadhaar back</div>
                    <div class="upload-hint">Store manager ID</div>
                    <div class="upload-preview-container" id="edit-preview-aadhar-back" style="<?= !empty($freezer['image_aadhar_back']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_aadhar_back'] ?? '') ?>" alt="Aadhaar Back" loading="lazy" decoding="async">
                    </div>
                </div>

                <!-- Left Side -->
                <div class="upload-box">
                    <input type="file" name="image_left_side" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-left">
                    <div class="upload-title">Change Left Side Photo</div>
                    <div class="upload-preview-container" id="edit-preview-left" style="<?= !empty($freezer['image_left_side']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_left_side'] ?? '') ?>" alt="Left Side" loading="lazy" decoding="async">
                    </div>
                </div>

                <!-- Right Side -->
                <div class="upload-box">
                    <input type="file" name="image_right_side" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-right">
                    <div class="upload-title">Change Right Side Photo</div>
                    <div class="upload-preview-container" id="edit-preview-right" style="<?= !empty($freezer['image_right_side']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_right_side'] ?? '') ?>" alt="Right Side" loading="lazy" decoding="async">
                    </div>
                </div>

                <?php if (AppConfig::IOT_ENABLED): ?>
                <!-- IoT Device -->
                <div class="upload-box">
                    <input type="file" name="image_iot_device" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-iot">
                    <div class="upload-title">Change IoT Device Photo</div>
                    <div class="upload-preview-container" id="edit-preview-iot" style="<?= !empty($freezer['image_iot_device']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_iot_device'] ?? '') ?>" alt="IoT Device" loading="lazy" decoding="async">
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title"><span>SIM installation</span></div>
        </div>
        <div class="card-body">
            <div class="form-grid">
                <div class="col-6 form-group">
                    <label class="form-label" for="sim_number">SIM number</label>
                    <input type="text" id="sim_number" name="sim_number" class="form-control"
                           placeholder="<?= htmlspecialchars(AppConfig::DEFAULT_SIM_NUMBER) ?>" maxlength="40"
                           value="<?= htmlspecialchars(($freezer['sim_number'] ?? '') !== '' ? $freezer['sim_number'] : AppConfig::DEFAULT_SIM_NUMBER) ?>" inputmode="numeric">
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="sim_company">SIM company</label>
                    <?php $simCoEdit = trim((string)($freezer['sim_company'] ?? '')) ?: AppConfig::DEFAULT_SIM_COMPANY; ?>
                    <select id="sim_company" name="sim_company" class="form-select">
                        <option value="">Select operator</option>
                        <?php foreach (['Jio', 'Airtel', 'VI', 'BSNL', 'Other'] as $op): ?>
                            <option value="<?= $op ?>" <?= $simCoEdit === $op ? 'selected' : '' ?>><?= $op ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 form-group">
                    <label class="form-label" for="image_sim">SIM number photo</label>
                    <div class="upload-box" id="dropzone-sim">
                        <input type="file" name="image_sim" id="image_sim" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-sim">
                        <div class="upload-icon">📷</div>
                        <div class="upload-title"><?= !empty($freezer['image_sim']) ? 'Change SIM photo' : 'SIM / ICCID photo' ?></div>
                        <div class="upload-hint">Camera · Clear SIM number</div>
                        <div class="upload-preview-container" id="edit-preview-sim" style="<?= !empty($freezer['image_sim']) ? 'display:block;' : '' ?>">
                            <img src="<?= htmlspecialchars($freezer['image_sim'] ?? '') ?>" alt="SIM" loading="lazy" decoding="async">
                            <span class="preview-badge">SIM</span>
                        </div>
                    </div>
                </div>
                <div class="col-12 form-group">
                    <label class="form-label" for="notes">Remark</label>
                    <input type="text" id="notes" name="notes" class="form-control" value="<?= htmlspecialchars($freezer['notes'] ?? '') ?>" placeholder="Installation observations, plug location, target temp…">
                </div>
            </div>
        </div>
    </div>

    <?php if (AppConfig::IOT_ENABLED): ?>
    <!-- IoT Hardware -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span>IoT Hardware Association</span>
            </div>
        </div>
        <div class="card-body">
            <div class="form-grid">
                <div class="col-6 form-group">
                    <label class="form-label" for="iot_serial">IoT Serial Number</label>
                    <input type="text" id="iot_serial" name="iot_serial" class="form-control" value="<?= htmlspecialchars($freezer['device_serial'] ?? 'RF-IOT-99210') ?>">
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="iot_imei">Device IMEI</label>
                    <input type="text" id="iot_imei" name="iot_imei" class="form-control" value="<?= htmlspecialchars($freezer['imei_or_mac'] ?? '') ?>">
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="iot_sim_number">SIM Number (IoT)</label>
                    <input type="text" id="iot_sim_number" name="iot_sim_number" class="form-control" placeholder="8991000900123456789" value="<?= htmlspecialchars($freezer['sim_number'] ?? '') ?>">
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="iot_sim_company">SIM Company (IoT)</label>
                    <?php $simCo = $freezer['sim_company'] ?? ''; ?>
                    <select id="iot_sim_company" name="iot_sim_company" class="form-select">
                        <option value="">Select operator</option>
                        <?php foreach (['Jio', 'Airtel', 'VI', 'BSNL', 'Other'] as $op): ?>
                            <option value="<?= $op ?>" <?= $simCo === $op ? 'selected' : '' ?>><?= $op ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="mapp-sticky-actions form-sticky-actions">
        <a href="profile_view.php?id=<?= $freezer['id'] ?>" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary mapp-btn">Save Changes</button>
    </div>
</form>

<script>
window.GEO_HIERARCHY = <?= json_encode(MasterData::getGeoHierarchy()) ?>;
window.CITY_LOC_PREFIXES = <?= json_encode(MasterData::getCityLocationPrefixes()) ?>;
document.addEventListener('DOMContentLoaded', function () {
    const aadhar = document.getElementById('aadhar_number');
    if (aadhar) {
        aadhar.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 12);
        });
    }
    const phone = document.getElementById('phone_number');
    if (phone) {
        function setPhoneErr(on) {
            const group = phone.closest('.form-group');
            phone.classList.toggle('is-invalid', on);
            if (group) group.classList.toggle('has-error', on);
        }
        phone.addEventListener('input', function () {
            this.value = (typeof normalizeIndianMobileDigits === 'function')
                ? normalizeIndianMobileDigits(this.value)
                : this.value.replace(/\D/g, '').slice(0, 10);
            const d = this.value;
            setPhoneErr(d !== '' && !/^[6-9]\d{9}$/.test(d));
        });
        phone.addEventListener('blur', function () {
            if (typeof normalizeIndianMobileDigits === 'function') {
                this.value = normalizeIndianMobileDigits(this.value);
            }
        });
    }
    const fssai = document.getElementById('fssai_number');
    if (fssai) {
        fssai.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 14);
        });
    }
    const gst = document.getElementById('gst_number');
    if (gst) {
        gst.addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 15);
        });
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

