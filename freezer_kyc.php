<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Models\Freezer;
use App\Models\Store;
use App\Models\FreezerLifecycle;

AuthService::requireRole('super_admin');
$currentUser = AuthService::currentUser();

$id = (int)($_GET['id'] ?? $_POST['freezer_id'] ?? 0);
$freezer = $id ? Freezer::findById($id) : null;
if (!$freezer) {
    die('<h1>Freezer not found</h1><p><a href="profiles.php">Back</a></p>');
}

$kycStatus = FreezerLifecycle::kycStatusForFreezer((int)$freezer['id']);
$cycle = $kycStatus['cycle'];
$error = null;
$csrfToken = AuthService::generateCsrfToken();
$stores = Store::getAll(null, 100);
$stores = array_values(array_filter($stores, fn($s) => (int)$s['id'] !== (int)$freezer['store_id']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } elseif ($kycStatus['current']) {
        $error = 'KYC for ' . $cycle['label'] . ' is already done.';
    } else {
        try {
            $locationMode = $_POST['location_mode'] ?? 'same';
            $newOutletId = null;

            // If freezer found at another / new outlet during KYC → move first
            if ($locationMode === 'existing') {
                $targetStoreId = (int)($_POST['new_store_id'] ?? 0);
                if ($targetStoreId <= 0) {
                    throw new Exception('Select the store where the freezer was found.');
                }
                FreezerLifecycle::transfer(
                    (int)$freezer['id'],
                    $targetStoreId,
                    'KYC found at different existing outlet',
                    trim($_POST['notes'] ?? '') ?: null,
                    (int)$currentUser['id'],
                    trim($_POST['verified_at'] ?? '') ?: date('Y-m-d')
                );
                $freezer = Freezer::findById((int)$freezer['id']);
            } elseif ($locationMode === 'new') {
                $imgNewStore = null;
                if (!empty($_FILES['image_store_front']['name'])) {
                    $up = FileUploadService::handleImageUpload($_FILES['image_store_front'], 'stores', 'kyc_store');
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
                    trim($_POST['verified_at'] ?? '') ?: date('Y-m-d')
                );
                $freezer = Freezer::findById((int)$freezer['id']);
            }

            $imgStore = null;
            $imgFrz = null;
            // For "same" or "existing", store front may still be uploaded as KYC evidence
            if ($locationMode !== 'new' && !empty($_FILES['image_store_front']['name'])) {
                $up = FileUploadService::handleImageUpload($_FILES['image_store_front'], 'stores', 'kyc_store');
                if (!$up['success']) {
                    throw new Exception('Store photo: ' . $up['error']);
                }
                $imgStore = $up['path'];
            } elseif ($locationMode === 'new') {
                $imgStore = $imgNewStore ?? null;
            }
            if (!empty($_FILES['image_freezer']['name'])) {
                $up = FileUploadService::handleImageUpload($_FILES['image_freezer'], 'freezers', 'kyc_frz');
                if (!$up['success']) {
                    throw new Exception('Freezer photo: ' . $up['error']);
                }
                $imgFrz = $up['path'];
            }

            FreezerLifecycle::recordKyc([
                'freezer_id' => (int)$freezer['id'],
                'verified_at' => trim($_POST['verified_at'] ?? '') ?: date('Y-m-d'),
                'cycle_year' => (int)($_POST['cycle_year'] ?? $cycle['year']),
                'cycle_half' => (int)($_POST['cycle_half'] ?? $cycle['half']),
                'result' => $_POST['result'] ?? 'PASS',
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
            header('Location: ' . $redir);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'KYC Verification';
require_once __DIR__ . '/includes/header.php';
$locMode = $_POST['location_mode'] ?? 'same';
?>

<div style="max-width: 760px; margin: 0 auto;">
    <div style="margin-bottom: 1.25rem;">
        <a href="profile_view.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-secondary btn-sm">&larr; Back to profile</a>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title"><span>Bi-annual KYC verification</span></div>
        </div>
        <div class="card-body">
            <p style="font-size: 0.875rem; color: #475569; margin-bottom: 1rem;">
                Year mein 2 baar (H1 / H2) verify karo. Agar freezer <strong>dusre / naye outlet</strong> pe mila,
                yahan naya store daalo — system <strong>nayi automatic Store ID</strong> banayega.
                Freezer Unique ID same rahegi.
            </p>

            <div style="display:flex; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem;">
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:0.75rem 1rem; flex:1; min-width:140px;">
                    <div style="font-size:0.7rem; font-weight:700; color:#1d4ed8; text-transform:uppercase;">Cycle</div>
                    <div style="font-weight:800;"><?= htmlspecialchars($cycle['label']) ?></div>
                    <div style="font-size:0.8rem; color:#64748b;"><?= htmlspecialchars($cycle['window']) ?></div>
                </div>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:0.75rem 1rem; flex:2; min-width:200px;">
                    <div style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Currently linked store</div>
                    <div style="font-weight:700;"><?= htmlspecialchars($freezer['store_name']) ?></div>
                    <div style="font-size:0.8rem; color:#64748b; font-family:var(--font-mono);"><?= htmlspecialchars($freezer['outlet_id']) ?></div>
                </div>
            </div>

            <?php if ($kycStatus['current']): ?>
                <div class="alert alert-success">
                    KYC for <?= htmlspecialchars($cycle['label']) ?> already completed on
                    <?= htmlspecialchars($kycStatus['current']['verified_at']) ?>.
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="freezer_id" value="<?= (int)$freezer['id'] ?>">
                    <input type="hidden" name="cycle_year" value="<?= (int)$cycle['year'] ?>">
                    <input type="hidden" name="cycle_half" value="<?= (int)$cycle['half'] ?>">
                    <input type="hidden" name="latitude" id="kyc_latitude" value="">
                    <input type="hidden" name="longitude" id="kyc_longitude" value="">

                    <div class="form-group">
                        <label class="form-label required">Where is the freezer today?</label>
                        <div style="display:flex; flex-direction:column; gap:0.45rem; font-size:0.875rem;">
                            <label><input type="radio" name="location_mode" value="same" <?= $locMode === 'same' ? 'checked' : '' ?> onchange="toggleLoc()"> Same store (<?= htmlspecialchars($freezer['outlet_id']) ?>)</label>
                            <label><input type="radio" name="location_mode" value="existing" <?= $locMode === 'existing' ? 'checked' : '' ?> onchange="toggleLoc()"> Different store already in system</label>
                            <label><input type="radio" name="location_mode" value="new" <?= $locMode === 'new' ? 'checked' : '' ?> onchange="toggleLoc()"> <strong>New outlet</strong> — create store + new auto Store ID</label>
                        </div>
                    </div>

                    <div id="loc-existing" style="<?= $locMode === 'existing' ? '' : 'display:none;' ?>">
                        <div class="form-group">
                            <label class="form-label required" for="new_store_id">Select existing store</label>
                            <select name="new_store_id" id="new_store_id" class="form-control">
                                <option value="">— Select —</option>
                                <?php foreach ($stores as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>">
                                        <?= htmlspecialchars($s['outlet_id'] . ' · ' . $s['store_name'] . ' · ' . $s['city']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="loc-new" style="<?= $locMode === 'new' ? '' : 'display:none;' ?>">
                        <div class="alert alert-info" style="font-size:0.8rem; margin-bottom:0.85rem;">
                            Naya outlet save hote hi auto Store ID milegi (jaise <code>W-AHM-20541</code>).
                            Freezer us nayi ID pe link ho jayega; Unique Freezer ID same rahegi.
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
                            <div class="col-12 form-group">
                                <label class="form-label required" for="address">Address</label>
                                <input type="text" name="address" id="address" class="form-control" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label required" for="city">City</label>
                                <input type="text" name="city" id="city" class="form-control" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label" for="state">State</label>
                                <input type="text" name="state" id="state" class="form-control" value="<?= htmlspecialchars($_POST['state'] ?? ($freezer['store_state'] ?? '')) ?>">
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label required" for="pincode">PIN</label>
                                <input type="text" name="pincode" id="pincode" class="form-control" maxlength="6" value="<?= htmlspecialchars($_POST['pincode'] ?? '') ?>">
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label" for="zone">Zone</label>
                                <select name="zone" id="zone" class="form-control">
                                    <?php foreach (['WEST','EAST','NORTH','SOUTH','CENTRAL'] as $z): ?>
                                        <option value="<?= $z ?>"><?= $z ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-4 form-group">
                                <label class="form-label" for="phone_number">Phone</label>
                                <input type="tel" name="phone_number" id="phone_number" class="form-control" maxlength="10" value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="col-6 form-group">
                            <label class="form-label required" for="verified_at">Verification date</label>
                            <input type="date" name="verified_at" id="verified_at" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6 form-group">
                            <label class="form-label required" for="result">Result</label>
                            <select name="result" id="result" class="form-control" required>
                                <option value="PASS">PASS — verified at this location</option>
                                <option value="FAIL">FAIL — not found / mismatch</option>
                                <option value="PENDING">PENDING — follow-up</option>
                            </select>
                        </div>
                        <div class="col-6 form-group">
                            <label class="form-label" for="image_store_front">Store front photo</label>
                            <input type="file" name="image_store_front" id="image_store_front" class="form-control" accept="image/*" capture="environment">
                        </div>
                        <div class="col-6 form-group">
                            <label class="form-label" for="image_freezer">Freezer photo</label>
                            <input type="file" name="image_freezer" id="image_freezer" class="form-control" accept="image/*" capture="environment">
                        </div>
                        <div class="col-12 form-group">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>

                    <p id="kyc-gps-status" style="font-size:0.8rem; color:#64748b; margin-bottom:0.85rem;">Capturing GPS if available…</p>
                    <button type="submit" class="btn btn-primary">Save KYC for <?= htmlspecialchars($cycle['label']) ?></button>
                </form>

                <script>
                function toggleLoc() {
                    const m = document.querySelector('input[name="location_mode"]:checked').value;
                    document.getElementById('loc-existing').style.display = m === 'existing' ? '' : 'none';
                    document.getElementById('loc-new').style.display = m === 'new' ? '' : 'none';
                }
                toggleLoc();
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function (pos) {
                        document.getElementById('kyc_latitude').value = pos.coords.latitude.toFixed(6);
                        document.getElementById('kyc_longitude').value = pos.coords.longitude.toFixed(6);
                        document.getElementById('kyc-gps-status').textContent = 'GPS captured for this verification.';
                    }, function () {
                        document.getElementById('kyc-gps-status').textContent = 'GPS not available — you can still save KYC.';
                    }, { enableHighAccuracy: true, timeout: 8000 });
                }
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
