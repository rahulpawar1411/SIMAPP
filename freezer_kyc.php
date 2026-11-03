<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Models\Freezer;
use App\Models\AssetTag;
use App\Models\FreezerLifecycle;
use App\Models\MasterData;

AuthService::requireRole('super_admin');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } elseif ($kycStatus['current']) {
        $error = 'KYC for ' . $cycle['label'] . ' is already done.';
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
                if (!empty($_FILES['image_store_front']['name'])) {
                    $up = FileUploadService::handleImageUpload($_FILES['image_store_front'], 'stores', 'kyc_store', $imgNameMeta);
                    if (!$up['success']) {
                        throw new Exception('Store photo: ' . $up['error']);
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
            $kycStatus = FreezerLifecycle::kycStatusForFreezer((int)$freezer['id']);
        }
    }
}

$pageTitle = 'KYC Verification';
require_once __DIR__ . '/includes/header.php';
$locMode = ($_POST['location_mode'] ?? 'same') === 'new' ? 'new' : 'same';
$resultVal = $_POST['result'] ?? 'PASS';
?>

<div style="max-width: 820px; margin: 0 auto;">
    <div style="margin-bottom: 1.25rem; display:flex; flex-wrap:wrap; gap:0.75rem; justify-content:space-between; align-items:center;">
        <a href="profile_view.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-secondary btn-sm">&larr; Back to profile</a>
        <span style="font-size:0.8rem; color:#64748b;">One place for verify + store move</span>
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
            <p style="font-size: 0.875rem; color: #475569; margin-bottom: 1rem;">
                Bi-annual check (H1 / H2). Confirm the physical freezer, capture photos + GPS,
                and if it moved — update store here. <strong>Unique Freezer ID never changes.</strong>
            </p>

            <!-- Identity summary -->
            <div class="profile-kv-grid" style="margin-bottom:1.25rem; padding:1rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:var(--radius-md);">
                <div class="profile-kv">
                    <span class="profile-kv-label">Cycle</span>
                    <strong class="profile-kv-value"><?= htmlspecialchars($cycle['label']) ?></strong>
                    <div style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($cycle['window']) ?></div>
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

            <?php if ($kycStatus['current']): ?>
                <div class="alert alert-success">
                    KYC for <?= htmlspecialchars($cycle['label']) ?> already completed on
                    <?= htmlspecialchars($kycStatus['current']['verified_at']) ?>
                    (<?= htmlspecialchars($kycStatus['current']['result'] ?? '') ?>).
                    Next cycle window: after this half ends.
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" id="kyc-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="freezer_id" value="<?= (int)$freezer['id'] ?>">
                    <input type="hidden" name="cycle_year" value="<?= (int)$cycle['year'] ?>">
                    <input type="hidden" name="cycle_half" value="<?= (int)$cycle['half'] ?>">
                    <input type="hidden" name="latitude" id="kyc_latitude" value="<?= htmlspecialchars($_POST['latitude'] ?? '') ?>">
                    <input type="hidden" name="longitude" id="kyc_longitude" value="<?= htmlspecialchars($_POST['longitude'] ?? '') ?>">

                    <!-- 1. Asset confirm -->
                    <div class="form-group" style="padding:0.85rem 1rem; border:1px solid #bfdbfe; background:#eff6ff; border-radius:8px;">
                        <label style="display:flex; gap:0.6rem; align-items:flex-start; font-size:0.875rem; margin:0; cursor:pointer;">
                            <input type="checkbox" name="confirm_asset" value="1" style="margin-top:0.2rem;"
                                <?= !empty($_POST['confirm_asset']) ? 'checked' : '' ?> required>
                            <span>
                                <strong>1. Asset match confirmed</strong><br>
                                <span style="color:#475569;">Physical Tag / QR matches this Unique Freezer ID and linked Tag code.</span>
                            </span>
                        </label>
                    </div>

                    <!-- 2. Location -->
                    <div class="form-group" style="margin-top:1.25rem;">
                        <label class="form-label required">2. Where is the freezer today?</label>
                        <p style="font-size:0.8rem; color:#64748b; margin:-0.25rem 0 0.65rem;">
                            Same outlet, or create a new outlet if the unit moved.
                        </p>
                        <div style="display:flex; flex-direction:column; gap:0.5rem; font-size:0.875rem;">
                            <label style="padding:0.65rem 0.85rem; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer;">
                                <input type="radio" name="location_mode" value="same" <?= $locMode !== 'new' ? 'checked' : '' ?> onchange="toggleLoc()">
                                <strong>Same store</strong> — still at <?= htmlspecialchars($freezer['outlet_id']) ?>
                            </label>
                            <label style="padding:0.65rem 0.85rem; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer;">
                                <input type="radio" name="location_mode" value="new" <?= $locMode === 'new' ? 'checked' : '' ?> onchange="toggleLoc()">
                                <strong>Moved to new outlet</strong> — create store + auto Store ID
                            </label>
                        </div>
                    </div>

                    <div id="loc-new" style="<?= $locMode === 'new' ? '' : 'display:none;' ?>">
                        <div class="alert alert-info" style="font-size:0.8rem; margin-bottom:0.85rem;">
                            Naya outlet save hote hi auto Store ID milegi.
                            Freezer us pe link hoga; Unique Freezer ID same rahegi.
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
                        <div class="col-6 form-group">
                            <label class="form-label required" for="image_store_front">Store front photo</label>
                            <div class="upload-box" id="dropzone-store-front">
                                <input type="file" name="image_store_front" id="image_store_front" class="upload-input" accept="image/*" capture="environment" data-preview-target="preview-kyc-store-front">
                                <div class="upload-icon">📷</div>
                                <div class="upload-title">Store Front</div>
                                <div class="upload-hint">Camera · GPS stamp</div>
                                <div class="upload-preview-container" id="preview-kyc-store-front">
                                    <img src="" alt="Store Front Preview">
                                </div>
                            </div>
                        </div>
                        <div class="col-6 form-group">
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

                    <!-- 4. Result -->
                    <div class="form-grid" style="margin-top:0.5rem;">
                        <div class="col-6 form-group">
                            <label class="form-label required" for="verified_at">4. Verification date</label>
                            <input type="date" name="verified_at" id="verified_at" class="form-control"
                                   value="<?= htmlspecialchars($_POST['verified_at'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-6 form-group">
                            <label class="form-label required" for="result">Result</label>
                            <select name="result" id="result" class="form-control" required onchange="togglePhotoReq()">
                                <option value="PASS" <?= $resultVal === 'PASS' ? 'selected' : '' ?>>PASS — unit found &amp; verified</option>
                                <option value="FAIL" <?= $resultVal === 'FAIL' ? 'selected' : '' ?>>FAIL — not found / mismatch</option>
                                <option value="PENDING" <?= $resultVal === 'PENDING' ? 'selected' : '' ?>>PENDING — follow-up needed</option>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label class="form-label" for="notes">Notes / remarks</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Condition, access issues, operator remarks…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <p id="kyc-gps-status" style="font-size:0.8rem; color:#64748b; margin-bottom:0.85rem;">Capturing GPS…</p>
                    <button type="submit" class="btn btn-primary">
                        Save KYC for <?= htmlspecialchars($cycle['label']) ?>
                    </button>
                </form>

                <script>
                function toggleLoc() {
                    const m = document.querySelector('input[name="location_mode"]:checked').value;
                    document.getElementById('loc-new').style.display = m === 'new' ? '' : 'none';
                }
                function togglePhotoReq() {
                    const pass = document.getElementById('result').value === 'PASS';
                    document.getElementById('image_store_front').required = pass;
                    document.getElementById('image_freezer').required = pass;
                }
                toggleLoc();
                togglePhotoReq();
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function (pos) {
                        document.getElementById('kyc_latitude').value = pos.coords.latitude.toFixed(6);
                        document.getElementById('kyc_longitude').value = pos.coords.longitude.toFixed(6);
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
