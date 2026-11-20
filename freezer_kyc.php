<?php
/**
 * AMC verify form — same outlet by default; checkbox unlocks outlet-change fields.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Models\AssetTag;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Services\KycScanGate;

AuthService::requireRole(['super_admin', 'technician']);
Freezer::ensureSimColumns();

$currentUser = AuthService::currentUser();
$userId = (int)($currentUser['id'] ?? 0);
$id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$fromProfile = !empty($_GET['from']) && $_GET['from'] === 'profile';
$freezer = $id > 0 ? Freezer::findById($id) : null;

if (!$freezer) {
    http_response_code(404);
    echo '<h1>Freezer not found</h1><p><a href="profiles.php">Back to Assets</a></p>';
    exit;
}

AuthService::requireAreaAccess(
    $freezer['store_zone'] ?? $freezer['zone'] ?? null,
    $freezer['store_city'] ?? null,
    'profiles.php'
);

if ($fromProfile) {
    KycScanGate::markFromProfile((int)$freezer['id']);
}

if (!KycScanGate::check((int)$freezer['id'])) {
    header('Location: ' . KycScanGate::scannerUrl($freezer));
    exit;
}

$profileTag = AssetTag::findByFreezerId((int)$freezer['id']);
$tagCode = (string)($profileTag['tag_code'] ?? '');
$csrfToken = AuthService::generateCsrfToken();
$error = null;
$success = null;
$outletChange = !empty($_POST['outlet_change']);

$nextDueHint = null;
try {
    $hist = FreezerLifecycle::kycHistory((int)$freezer['id'], 1);
    if ($hist) {
        $nextDueHint = FreezerLifecycle::nextKycDueDate((string)($hist[0]['verified_at'] ?? ''));
    } elseif (!empty($freezer['installation_date'])) {
        $nextDueHint = FreezerLifecycle::nextKycDueDate((string)$freezer['installation_date']);
    }
} catch (Throwable $e) {
    $nextDueHint = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Invalid security token. Please reload and try again.');
        }

        $outletChange = !empty($_POST['outlet_change']);
        $result = strtoupper(trim((string)($_POST['result'] ?? 'PASS')));
        if (!in_array($result, ['PASS', 'FAIL'], true)) {
            $result = 'PASS';
        }
        $notes = trim((string)($_POST['notes'] ?? ''));
        $lat = trim((string)($_POST['latitude'] ?? ''));
        $lng = trim((string)($_POST['longitude'] ?? ''));

        $imgMeta = [
            'tag_code' => $tagCode !== '' ? $tagCode : (string)($freezer['unique_freezer_id'] ?? 'AMC'),
            'store_name' => (string)($freezer['store_name'] ?? 'Store'),
            'datetime' => date('Ymd'),
        ];

        if (empty($_FILES['image_store_front']['name'])) {
            throw new RuntimeException('Store front photo is required.');
        }
        if (empty($_FILES['image_freezer']['name'])) {
            throw new RuntimeException('Freezer photo is required.');
        }

        $storeUp = FileUploadService::handleImageUpload(
            $_FILES['image_store_front'],
            'stores',
            'kyc_store',
            $imgMeta + ['role' => 'kyc_store']
        );
        if (empty($storeUp['success'])) {
            throw new RuntimeException($storeUp['error'] ?? 'Store photo upload failed.');
        }

        $frzUp = FileUploadService::handleImageUpload(
            $_FILES['image_freezer'],
            'freezers',
            'kyc_frz',
            $imgMeta + ['role' => 'kyc_frz']
        );
        if (empty($frzUp['success'])) {
            throw new RuntimeException($frzUp['error'] ?? 'Freezer photo upload failed.');
        }

        $storeImgPath = (string)$storeUp['path'];
        $frzImgPath = (string)$frzUp['path'];
        $verifiedAt = date('Y-m-d H:i:s');

        if ($outletChange) {
            $newStoreName = trim((string)($_POST['new_store_name'] ?? ''));
            $newAddress = trim((string)($_POST['new_address'] ?? ''));
            $newCity = trim((string)($_POST['new_city'] ?? ''));
            $newState = trim((string)($_POST['new_state'] ?? ''));
            $newPin = preg_replace('/\D/', '', (string)($_POST['new_pincode'] ?? ''));
            $newZone = strtoupper(trim((string)($_POST['new_zone'] ?? 'WEST'))) ?: 'WEST';
            $newPhone = trim((string)($_POST['new_phone'] ?? ''));
            $newContact = trim((string)($_POST['new_contact'] ?? ''));
            $moveReason = trim((string)($_POST['move_reason'] ?? ''));
            if ($moveReason === '') {
                $moveReason = 'AMC outlet change';
            }
            if ($newStoreName === '' || $newAddress === '' || $newCity === '') {
                throw new RuntimeException('Outlet change needs store name, address and city.');
            }
            if (!preg_match('/^\d{6}$/', (string)$newPin)) {
                throw new RuntimeException('Outlet change PIN must be 6 digits.');
            }
            if (!in_array($newZone, AppConfig::ZONES, true)) {
                $newZone = 'WEST';
            }

            $created = FreezerLifecycle::createNewOutlet([
                'store_name' => $newStoreName,
                'address' => $newAddress,
                'city' => $newCity,
                'state' => $newState !== '' ? $newState : 'Unknown',
                'pincode' => $newPin,
                'zone' => $newZone,
                'phone_number' => $newPhone,
                'contact_person' => $newContact,
                'latitude' => $lat,
                'longitude' => $lng,
                'image_store_front' => $storeImgPath,
            ], $userId);

            FreezerLifecycle::transfer(
                (int)$freezer['id'],
                (int)$created['store_id'],
                $moveReason,
                $notes !== '' ? $notes : null,
                $userId,
                date('Y-m-d')
            );

            // Refresh freezer after transfer so recordKyc uses new store_id
            $freezer = Freezer::findById((int)$freezer['id']) ?: $freezer;
            if ($notes === '') {
                $notes = 'AMC after outlet change → ' . ($created['outlet_id'] ?? '');
            } else {
                $notes .= ' · Outlet change → ' . ($created['outlet_id'] ?? '');
            }
        }

        FreezerLifecycle::recordKyc([
            'freezer_id' => (int)$freezer['id'],
            'verified_at' => $verifiedAt,
            'result' => $result,
            'notes' => $notes !== '' ? $notes : null,
            'image_store_front' => $storeImgPath,
            'image_freezer' => $frzImgPath,
            'latitude' => $lat !== '' ? $lat : null,
            'longitude' => $lng !== '' ? $lng : null,
            'verified_by' => $userId,
        ]);

        KycScanGate::consume((int)$freezer['id']);
        $uid = (string)($freezer['unique_freezer_id'] ?? '');
        header('Location: profile_view.php?id=' . rawurlencode($uid) . '&tab=amc&amc_ok=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'AMC verify';
$bodyClass = (($currentUser['role'] ?? '') === AppConfig::ROLE_TECHNICIAN) ? 'field-app-page field-shell-page' : '';
require_once __DIR__ . '/includes/header.php';

$backHref = 'profile_view.php?id=' . urlencode((string)$freezer['unique_freezer_id']) . '&tab=amc';
$zones = AppConfig::zones();
?>

<nav class="sp-breadcrumb" aria-label="Breadcrumb">
    <a href="profiles.php">Assets</a>
    <span>/</span>
    <a href="<?= htmlspecialchars($backHref) ?>"><?= htmlspecialchars((string)($freezer['outlet_id'] ?? 'Profile')) ?></a>
    <span>/</span>
    <strong>AMC verify</strong>
</nav>

<div class="amc-form-shell">
<div class="amc-form-hero">
    <div>
        <h1>AMC verify</h1>
        <p>
            <?= htmlspecialchars((string)$freezer['unique_freezer_id']) ?>
            · <?= htmlspecialchars((string)($freezer['store_name'] ?? '')) ?>
            (<?= htmlspecialchars((string)($freezer['outlet_id'] ?? '—')) ?>)
            <?php if ($nextDueHint): ?>
                · Next due: <?= htmlspecialchars($nextDueHint) ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="<?= htmlspecialchars($backHref) ?>" class="btn btn-sm btn-outline">← Back to AMC history</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="amc-form-card" id="amc-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

    <div class="amc-form-section">
        <h3>1 · Current outlet</h3>
        <div class="amc-form-outlet">
            <strong><?= htmlspecialchars((string)($freezer['store_name'] ?? '—')) ?></strong>
            <div class="meta">
                <?= htmlspecialchars((string)($freezer['outlet_id'] ?? '')) ?>
                · <?= htmlspecialchars(trim((string)($freezer['store_city'] ?? '') . ' ' . (string)($freezer['store_pincode'] ?? ''))) ?>
            </div>
            <div class="addr"><?= htmlspecialchars((string)($freezer['store_address'] ?? '')) ?></div>
        </div>

        <label class="amc-toggle <?= $outletChange ? 'is-on' : '' ?>" id="amc-outlet-toggle" style="margin-top:0.75rem;">
            <input type="checkbox" name="outlet_change" value="1" id="amc-outlet-change"
                <?= $outletChange ? 'checked' : '' ?>>
            <span>
                <strong>Outlet change?</strong>
                <span>Off = same store re-verify. On = enter new store and transfer this freezer.</span>
            </span>
        </label>

        <div id="amc-outlet-fields" class="amc-outlet-box" style="display:none;">
            <h3 style="margin-top:0;">New outlet details</h3>
            <table class="form-table">
                <tr>
                    <th><label class="form-label" for="new_store_name">Store name</label></th>
                    <td><input type="text" class="form-control" id="new_store_name" name="new_store_name"
                               value="<?= htmlspecialchars((string)($_POST['new_store_name'] ?? '')) ?>" maxlength="150"></td>
                </tr>
                <tr>
                    <th><label class="form-label" for="new_address">Address</label></th>
                    <td><textarea class="form-control" id="new_address" name="new_address" rows="2"><?= htmlspecialchars((string)($_POST['new_address'] ?? '')) ?></textarea></td>
                </tr>
                <tr>
                    <th><label class="form-label" for="new_city">City</label></th>
                    <td><input type="text" class="form-control" id="new_city" name="new_city"
                               value="<?= htmlspecialchars((string)($_POST['new_city'] ?? '')) ?>" maxlength="80"></td>
                </tr>
                <tr>
                    <th><label class="form-label" for="new_state">State</label></th>
                    <td><input type="text" class="form-control" id="new_state" name="new_state"
                               value="<?= htmlspecialchars((string)($_POST['new_state'] ?? '')) ?>" maxlength="80"></td>
                </tr>
                <tr>
                    <th><label class="form-label" for="new_pincode">PIN</label></th>
                    <td><input type="text" class="form-control" id="new_pincode" name="new_pincode" inputmode="numeric"
                               value="<?= htmlspecialchars((string)($_POST['new_pincode'] ?? '')) ?>" maxlength="6"></td>
                </tr>
                <tr>
                    <th><label class="form-label" for="new_zone">Zone</label></th>
                    <td>
                        <select class="form-select" id="new_zone" name="new_zone">
                            <?php
                            $selZone = strtoupper(trim((string)($_POST['new_zone'] ?? ($freezer['store_zone'] ?? 'WEST'))));
                            foreach ($zones as $z):
                            ?>
                                <option value="<?= htmlspecialchars($z) ?>" <?= $selZone === $z ? 'selected' : '' ?>><?= htmlspecialchars($z) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label class="form-label" for="new_contact">Contact</label></th>
                    <td><input type="text" class="form-control" id="new_contact" name="new_contact"
                               value="<?= htmlspecialchars((string)($_POST['new_contact'] ?? '')) ?>" maxlength="100"></td>
                </tr>
                <tr>
                    <th><label class="form-label" for="new_phone">Phone</label></th>
                    <td><input type="text" class="form-control" id="new_phone" name="new_phone" inputmode="tel"
                               value="<?= htmlspecialchars((string)($_POST['new_phone'] ?? '')) ?>" maxlength="20"></td>
                </tr>
                <tr>
                    <th><label class="form-label" for="move_reason">Move reason</label></th>
                    <td><input type="text" class="form-control" id="move_reason" name="move_reason"
                               value="<?= htmlspecialchars((string)($_POST['move_reason'] ?? 'AMC outlet change')) ?>" maxlength="200"></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="amc-form-section">
        <h3>2 · Photos &amp; result</h3>
        <table class="form-table">
            <tr>
                <th><span class="form-label">Store front photo</span></th>
                <td>
                    <input type="file" name="image_store_front" class="form-control" accept="image/*" capture="environment" required>
                </td>
            </tr>
            <tr>
                <th><span class="form-label">Freezer photo</span></th>
                <td>
                    <input type="file" name="image_freezer" class="form-control" accept="image/*" capture="environment" required>
                </td>
            </tr>
            <tr>
                <th><span class="form-label">Result</span></th>
                <td>
                    <?php $selResult = strtoupper(trim((string)($_POST['result'] ?? 'PASS'))); ?>
                    <div class="amc-result-pills">
                        <label><input type="radio" name="result" value="PASS" <?= $selResult !== 'FAIL' ? 'checked' : '' ?>> PASS</label>
                        <label><input type="radio" name="result" value="FAIL" <?= $selResult === 'FAIL' ? 'checked' : '' ?>> FAIL</label>
                    </div>
                </td>
            </tr>
            <tr>
                <th><label class="form-label" for="notes">Notes</label></th>
                <td><textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Optional remark"><?= htmlspecialchars((string)($_POST['notes'] ?? '')) ?></textarea></td>
            </tr>
            <tr>
                <th><span class="form-label">GPS (optional)</span></th>
                <td>
                    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
                        <input type="text" class="form-control" name="latitude" id="amc-lat" placeholder="Latitude"
                               value="<?= htmlspecialchars((string)($_POST['latitude'] ?? '')) ?>" style="max-width:140px;">
                        <input type="text" class="form-control" name="longitude" id="amc-lng" placeholder="Longitude"
                               value="<?= htmlspecialchars((string)($_POST['longitude'] ?? '')) ?>" style="max-width:140px;">
                        <button type="button" class="btn btn-sm btn-outline" id="amc-gps-btn">Use my location</button>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="amc-form-actions">
        <button type="submit" class="btn btn-primary">Save AMC</button>
        <a href="<?= htmlspecialchars($backHref) ?>" class="btn btn-outline">Cancel</a>
    </div>
</form>
</div>

<script>
(function () {
    var cb = document.getElementById('amc-outlet-change');
    var box = document.getElementById('amc-outlet-fields');
    var toggle = document.getElementById('amc-outlet-toggle');
    function sync() {
        if (!cb || !box) return;
        box.style.display = cb.checked ? 'block' : 'none';
        if (toggle) toggle.classList.toggle('is-on', !!cb.checked);
        ['new_store_name','new_address','new_city','new_pincode'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.required = !!cb.checked;
        });
    }
    if (cb) {
        cb.addEventListener('change', sync);
        sync();
    }
    var gpsBtn = document.getElementById('amc-gps-btn');
    if (gpsBtn && navigator.geolocation) {
        gpsBtn.addEventListener('click', function () {
            gpsBtn.disabled = true;
            gpsBtn.textContent = 'Getting…';
            navigator.geolocation.getCurrentPosition(function (pos) {
                var lat = document.getElementById('amc-lat');
                var lng = document.getElementById('amc-lng');
                if (lat) lat.value = pos.coords.latitude.toFixed(6);
                if (lng) lng.value = pos.coords.longitude.toFixed(6);
                gpsBtn.disabled = false;
                gpsBtn.textContent = 'Use my location';
            }, function () {
                gpsBtn.disabled = false;
                gpsBtn.textContent = 'Use my location';
                alert('Could not get GPS. Allow location or type manually.');
            }, { enableHighAccuracy: true, timeout: 15000 });
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
