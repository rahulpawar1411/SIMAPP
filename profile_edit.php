<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\FreezerIdGenerator;
use App\Services\FileUploadService;
use App\Config\AppConfig;
use App\Models\MasterData;
use App\Models\Store;
use App\Models\Freezer;

AuthService::requireRole(['super_admin', 'sub_admin', 'technician']);
$currentUser = AuthService::currentUser();

$id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$freezer = Freezer::findById($id);

if (!$freezer) {
    die("<h1>Profile Not Found</h1><p>Freezer profile #{$id} does not exist.</p><p><a href='profiles.php'>Back to Profiles</a></p>");
}

$error = null;
$success = null;
$csrfToken = AuthService::generateCsrfToken();

$freezerTypes = MasterData::getFreezerTypes();
$brands = MasterData::getBrands();
$customers = MasterData::getCustomerList();
$conditions = MasterData::getConditions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security CSRF token.';
    } else {
        try {
            // Check for new image uploads
            $imgStoreFront = !empty($_FILES['image_store_front']['name'])
                ? FileUploadService::handleImageUpload($_FILES['image_store_front'], 'stores', 'store_front')
                : null;
            if ($imgStoreFront && !$imgStoreFront['success']) {
                throw new Exception("Store Front Image Error: " . $imgStoreFront['error']);
            }

            $imgLeftResult = !empty($_FILES['image_left_side']['name'])
                ? FileUploadService::handleImageUpload($_FILES['image_left_side'], 'freezers', 'frz_left')
                : null;
            if ($imgLeftResult && !$imgLeftResult['success']) {
                throw new Exception("Left Side Image Error: " . $imgLeftResult['error']);
            }

            $imgRightResult = !empty($_FILES['image_right_side']['name'])
                ? FileUploadService::handleImageUpload($_FILES['image_right_side'], 'freezers', 'frz_right')
                : null;
            if ($imgRightResult && !$imgRightResult['success']) {
                throw new Exception("Right Side Image Error: " . $imgRightResult['error']);
            }

            $imgIotResult = null;
            if (AppConfig::IOT_ENABLED && !empty($_FILES['image_iot_device']['name'])) {
                $imgIotResult = FileUploadService::handleImageUpload($_FILES['image_iot_device'], 'iot', 'iot_dev');
                if ($imgIotResult && !$imgIotResult['success']) {
                    throw new Exception("IoT Device Image Error: " . $imgIotResult['error']);
                }
            }

            // Update Store
            Store::update((int)$freezer['store_id'], [
                'outlet_id' => $_POST['outlet_id'] ?? $freezer['outlet_id'],
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
                'image_store_front' => $imgStoreFront ? $imgStoreFront['path'] : null,
                'status' => 'Active',
            ]);

            // Update Freezer Profile
            $candidateId = strtoupper(trim($_POST['unique_freezer_id'] ?? $freezer['unique_freezer_id']));
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
                'unit_sequence' => !empty($_POST['unit_sequence'])
                    ? strtoupper(trim($_POST['unit_sequence']))
                    : ($freezer['unit_sequence'] ?? null),
                'installation_date' => $installDate,
                'image_left_side' => $imgLeftResult ? $imgLeftResult['path'] : null,
                'image_right_side' => $imgRightResult ? $imgRightResult['path'] : null,
                'status' => $_POST['freezer_status'] ?? $freezer['status'],
                'notes' => $_POST['notes'] ?? '',
            ];

            if (AppConfig::IOT_ENABLED) {
                $updatePayload['image_iot_device'] = $imgIotResult ? $imgIotResult['path'] : null;
                $updatePayload['iot_serial'] = $_POST['iot_serial'] ?? $freezer['device_serial'];
                $updatePayload['iot_imei'] = $_POST['iot_imei'] ?? $freezer['imei_or_mac'];
                $updatePayload['iot_type'] = $_POST['iot_type'] ?? $freezer['device_type'];
                $updatePayload['iot_owner'] = $_POST['iot_owner'] ?? $freezer['device_owner'];
            }

            Freezer::update($id, $updatePayload);

            header("Location: profile_view.php?id={$id}&updated=1");
            exit;

        } catch (Exception $e) {
            $error = $e->getMessage();
            $freezer = Freezer::findById($id); // refresh
        }
    }
}

$pageTitle = 'Edit Freezer: ' . $freezer['unique_freezer_id'];
require_once __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2 style="font-size: 1.4rem; font-weight: 800; color: #0f172a;">Edit Freezer Profile</h2>
        <p style="color: #64748b; font-size: 0.875rem;">Modify specifications or replace photos. New Store Front photo updates location.</p>
    </div>
    <a href="profile_view.php?id=<?= $freezer['id'] ?>" class="btn btn-secondary">&larr; Cancel & Return</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" id="exclude_freezer_id" value="<?= (int)$freezer['id'] ?>">

    <!-- ID Preview Card -->
    <div class="id-generator-card" style="margin-bottom: 1.5rem;">
        <div class="id-preview-header">
            <div>
                <span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; font-weight: 700;">Unique Freezer ID</span>
                <div style="font-size: 0.85rem; color: #cbd5e1; margin-top: 0.1rem;">Validated Unique ID:</div>
            </div>
            <span id="id-status-badge" class="id-preview-badge">Current Profile</span>
        </div>

        <div class="id-preview-display">
            <span id="id-preview-display"><?= htmlspecialchars($freezer['unique_freezer_id']) ?></span>
            <span style="font-size: 0.7rem; font-family: var(--font-sans); color: #94a3b8; background: rgba(255,255,255,0.1); padding: 0.2rem 0.5rem; border-radius: 4px;">Assigned ID</span>
        </div>
        <input type="hidden" id="unique_freezer_id" name="unique_freezer_id" value="<?= htmlspecialchars($freezer['unique_freezer_id']) ?>">
    </div>

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

            <div class="form-grid">
                <div class="col-6 form-group">
                    <label class="form-label required" for="store_name">Store Name</label>
                    <input type="text" id="store_name" name="store_name" class="form-control" value="<?= htmlspecialchars($freezer['store_name']) ?>" required>
                </div>
                <div class="col-3 form-group">
                    <label class="form-label" for="outlet_id">Outlet ID</label>
                    <input type="text" id="outlet_id" name="outlet_id" class="form-control" value="<?= htmlspecialchars($freezer['outlet_id']) ?>" required>
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
                    <label class="form-label required" for="zone">Operational Zone</label>
                    <select id="zone" name="zone" class="form-select" required>
                        <option value="CENTRAL" <?= ($freezer['store_zone'] ?? '') === 'CENTRAL' ? 'selected' : '' ?>>CENTRAL</option>
                        <option value="WEST" <?= ($freezer['store_zone'] ?? 'WEST') === 'WEST' ? 'selected' : '' ?>>WEST</option>
                        <option value="NORTH" <?= ($freezer['store_zone'] ?? '') === 'NORTH' ? 'selected' : '' ?>>NORTH</option>
                        <option value="SOUTH" <?= ($freezer['store_zone'] ?? '') === 'SOUTH' ? 'selected' : '' ?>>SOUTH</option>
                        <option value="EAST" <?= ($freezer['store_zone'] ?? '') === 'EAST' ? 'selected' : '' ?>>EAST</option>
                    </select>
                </div>
                <div class="col-4 form-group">
                    <label class="form-label required" for="city">City / Location</label>
                    <input type="text" id="city" name="city" class="form-control" list="city-list" value="<?= htmlspecialchars($freezer['store_city']) ?>" placeholder="Type City" required autocomplete="off">
                    <datalist id="city-list"></datalist>
                </div>
                <div class="col-4 form-group">
                    <label class="form-label required" for="pincode">PIN Code</label>
                    <input type="text" id="pincode" name="pincode" class="form-control" value="<?= htmlspecialchars($freezer['store_pincode']) ?>" required>
                </div>
                <div class="col-6 form-group">
                    <label class="form-label required" for="address">Address</label>
                    <input type="text" id="address" name="address" class="form-control" value="<?= htmlspecialchars($freezer['store_address']) ?>" required>
                </div>
                <div class="col-6 form-group">
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
                        <input type="tel" id="phone_number" name="phone_number" class="form-control" value="<?= htmlspecialchars($editPhone) ?>" maxlength="10" inputmode="numeric" pattern="[6-9][0-9]{9}" placeholder="10-digit mobile" title="10-digit Indian mobile" autocomplete="tel-national">
                    </div>
                    <span class="form-help">Exactly 10 digits · saved as +91XXXXXXXXXX</span>
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="email">Store operator email ID</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($freezer['store_email'] ?? '') ?>" placeholder="operator@example.com" autocomplete="email">
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="aadhar_number">Store operator Aadhaar number</label>
                    <input type="text" id="aadhar_number" name="aadhar_number" class="form-control" value="<?= htmlspecialchars($freezer['aadhar_number'] ?? '') ?>" placeholder="12-digit Aadhaar" maxlength="12" inputmode="numeric" pattern="[0-9]{12}" title="Exactly 12 digits" autocomplete="off">
                    <span class="form-help">Exactly 12 digits</span>
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="fssai_number">FSSAI number</label>
                    <input type="text" id="fssai_number" name="fssai_number" class="form-control" value="<?= htmlspecialchars($freezer['fssai_number'] ?? '') ?>" placeholder="14-digit FSSAI licence" maxlength="14" inputmode="numeric" pattern="[0-9]{14}" title="Exactly 14 digits" autocomplete="off">
                    <span class="form-help">Optional · exactly 14 digits</span>
                </div>
                <div class="col-6 form-group">
                    <label class="form-label" for="gst_number">GST number</label>
                    <input type="text" id="gst_number" name="gst_number" class="form-control" value="<?= htmlspecialchars($freezer['gst_number'] ?? '') ?>" placeholder="15-character GSTIN" maxlength="15" pattern="[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]" title="Valid 15-character GSTIN" autocomplete="off" style="text-transform: uppercase;">
                    <span class="form-help">Optional · 15-character GSTIN</span>
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
                                <?= htmlspecialchars($ft['code']) ?> - <?= htmlspecialchars($ft['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-4 form-group">
                    <label class="form-label required" for="company_code">Brand / OEM</label>
                    <select id="company_code" name="company_code" class="form-select" required>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= htmlspecialchars($b['code']) ?>" <?= ($freezer['company_code'] === $b['code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['code']) ?> - <?= htmlspecialchars($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-4 form-group">
                    <label class="form-label required" for="model_number">Model Number</label>
                    <input type="text" id="model_number" name="model_number" class="form-control" value="<?= htmlspecialchars($freezer['model_number']) ?>" required>
                </div>
                <div class="col-3 form-group">
                    <label class="form-label required" for="location_code">Location Code</label>
                    <select id="location_code" name="location_code" class="form-select" required>
                        <?php
                        $curLoc = strtoupper(trim((string)($freezer['location_code'] ?? '')));
                        $cityName = (string)($freezer['store_city'] ?? '');
                        $prefixes = MasterData::getCityLocationPrefixes();
                        $prefix = $prefixes[$cityName] ?? preg_replace('/\d+$/', '', $curLoc);
                        if ($prefix === '' || $prefix === null) {
                            $prefix = 'LOC';
                        }
                        foreach (MasterData::locationCodesForPrefix((string)$prefix, 3) as $code):
                        ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= $curLoc === $code ? 'selected' : '' ?>><?= htmlspecialchars($code) ?></option>
                        <?php endforeach; ?>
                        <?php if ($curLoc !== '' && !preg_match('/^' . preg_quote((string)$prefix, '/') . '[123]$/', $curLoc)): ?>
                            <option value="<?= htmlspecialchars($curLoc) ?>" selected><?= htmlspecialchars($curLoc) ?> (current)</option>
                        <?php endif; ?>
                    </select>
                    <span class="form-help">From city · e.g. AMD1/2/3, BPL1/2/3, MUM1/2/3</span>
                </div>
                <div class="col-3 form-group">
                    <label class="form-label required" for="installation_date">Installation Date</label>
                    <input type="date" id="installation_date" name="installation_date" class="form-control" value="<?= htmlspecialchars($freezer['installation_date']) ?>" required>
                </div>
                <div class="col-3 form-group">
                    <label class="form-label" for="unit_sequence">Unit Sequence</label>
                    <input type="text" id="unit_sequence" name="unit_sequence" class="form-control" value="<?= htmlspecialchars($freezer['unit_sequence'] ?? '') ?>" maxlength="12" placeholder="Auto if blank">
                    <span class="form-help">Optional · blank keeps current / auto on new</span>
                </div>
                <div class="col-3 form-group">
                    <label class="form-label" for="freezer_status">Status</label>
                    <select id="freezer_status" name="freezer_status" class="form-select">
                        <option value="Active" <?= $freezer['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Maintenance" <?= $freezer['status'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="Under Inspection" <?= $freezer['status'] === 'Under Inspection' ? 'selected' : '' ?>>Under Inspection</option>
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
                <div class="col-6 form-group">
                    <label class="form-label" for="notes">Remark</label>
                    <input type="text" id="notes" name="notes" class="form-control" value="<?= htmlspecialchars($freezer['notes'] ?? '') ?>">
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
                A new <strong>Store Front</strong> photo (Location ON) may update GPS and address fields.
                Please review any auto-filled details and kindly confirm them with the store representative.
            </p>
            <div class="photo-upload-grid photo-upload-grid-4">
                <!-- Store Front -->
                <div class="upload-box">
                    <input type="file" name="image_store_front" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-store-front">
                    <div class="upload-title">Change Store Front Photo</div>
                    <div class="upload-hint">GPS + date auto-fill</div>
                    <div class="upload-preview-container" id="edit-preview-store-front" style="<?= !empty($freezer['image_store_front']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_store_front'] ?? '') ?>" alt="Store Front">
                    </div>
                </div>

                <!-- Left Side -->
                <div class="upload-box">
                    <input type="file" name="image_left_side" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-left">
                    <div class="upload-title">Change Left Side Photo</div>
                    <div class="upload-preview-container" id="edit-preview-left" style="<?= !empty($freezer['image_left_side']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_left_side'] ?? '') ?>" alt="Left Side">
                    </div>
                </div>

                <!-- Right Side -->
                <div class="upload-box">
                    <input type="file" name="image_right_side" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-right">
                    <div class="upload-title">Change Right Side Photo</div>
                    <div class="upload-preview-container" id="edit-preview-right" style="<?= !empty($freezer['image_right_side']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_right_side'] ?? '') ?>" alt="Right Side">
                    </div>
                </div>

                <?php if (AppConfig::IOT_ENABLED): ?>
                <!-- IoT Device -->
                <div class="upload-box">
                    <input type="file" name="image_iot_device" class="upload-input" accept="image/*" capture="environment" data-preview-target="edit-preview-iot">
                    <div class="upload-title">Change IoT Device Photo</div>
                    <div class="upload-preview-container" id="edit-preview-iot" style="<?= !empty($freezer['image_iot_device']) ? 'display:block;' : '' ?>">
                        <img src="<?= htmlspecialchars($freezer['image_iot_device'] ?? '') ?>" alt="IoT Device">
                    </div>
                </div>
                <?php endif; ?>
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
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-bottom: 2rem;">
        <a href="profile_view.php?id=<?= $freezer['id'] ?>" class="btn btn-secondary btn-lg">Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg">Save Changes</button>
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
        phone.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 10);
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>

