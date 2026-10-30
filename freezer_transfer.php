<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\Freezer;
use App\Models\Store;
use App\Models\FreezerLifecycle;

AuthService::requireRole(['super_admin', 'sub_admin', 'technician']);
$currentUser = AuthService::currentUser();

$id = (int)($_GET['id'] ?? $_POST['freezer_id'] ?? 0);
$freezer = $id ? Freezer::findById($id) : null;
if (!$freezer) {
    die('<h1>Freezer not found</h1><p><a href="profiles.php">Back</a></p>');
}

FreezerLifecycle::ensureOpenPlacement($freezer);

$error = null;
$csrfToken = AuthService::generateCsrfToken();
$q = trim($_GET['q'] ?? '');
$mode = $_POST['target_mode'] ?? ($_GET['mode'] ?? 'existing');
$stores = Store::getAll($q !== '' ? $q : null, 80);
$stores = array_values(array_filter($stores, fn($s) => (int)$s['id'] !== (int)$freezer['store_id']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $mode = $_POST['target_mode'] ?? 'existing';
            $transferDate = trim($_POST['transfer_date'] ?? '') ?: date('Y-m-d');
            $reason = trim($_POST['transfer_reason'] ?? '');
            $notes = trim($_POST['notes'] ?? '') ?: null;

            if ($mode === 'new') {
                $created = FreezerLifecycle::createNewOutlet([
                    'store_name' => $_POST['store_name'] ?? '',
                    'address' => $_POST['address'] ?? '',
                    'city' => $_POST['city'] ?? '',
                    'state' => $_POST['state'] ?? '',
                    'pincode' => $_POST['pincode'] ?? '',
                    'zone' => $_POST['zone'] ?? 'WEST',
                    'contact_person' => $_POST['contact_person'] ?? ($freezer['contact_person'] ?? ''),
                    'phone_number' => $_POST['phone_number'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'aadhar_number' => $_POST['aadhar_number'] ?? '',
                    'customer_name' => $_POST['customer_name'] ?? ($freezer['customer_name'] ?? 'Baskin Robbins'),
                    'latitude' => $_POST['latitude'] ?? null,
                    'longitude' => $_POST['longitude'] ?? null,
                ], (int)$currentUser['id']);

                $newStoreId = $created['store_id'];
                $reason = $reason !== '' ? $reason : 'Moved to new outlet (auto ID ' . $created['outlet_id'] . ')';
                $notes = trim(($notes ? $notes . ' · ' : '') . 'New outlet_id=' . $created['outlet_id']);
            } else {
                $newStoreId = (int)($_POST['new_store_id'] ?? 0);
                if ($newStoreId <= 0) {
                    throw new Exception('Select an existing store, or choose Create new outlet.');
                }
            }

            FreezerLifecycle::transfer(
                (int)$freezer['id'],
                $newStoreId,
                $reason,
                $notes,
                (int)$currentUser['id'],
                $transferDate
            );

            $redir = 'profile_view.php?id=' . urlencode($freezer['unique_freezer_id']) . '&transferred=1';
            if ($mode === 'new' && !empty($created['outlet_id'])) {
                $redir .= '&new_outlet=' . urlencode($created['outlet_id']);
            }
            header('Location: ' . $redir);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Transfer Freezer';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 760px; margin: 0 auto;">
    <div style="margin-bottom: 1.25rem;">
        <a href="profile_view.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-secondary btn-sm">&larr; Back to profile</a>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title"><span>Transfer freezer to another store</span></div>
        </div>
        <div class="card-body">
            <p style="font-size: 0.875rem; color: #475569; margin-bottom: 1rem;">
                Unique Freezer ID <strong style="font-family: var(--font-mono);"><?= htmlspecialchars($freezer['unique_freezer_id']) ?></strong>
                stays the same. If you create a <strong>new outlet</strong>, system generates a new automatic Store ID
                (e.g. <code>W-AHM-20541</code>).
            </p>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:0.85rem; margin-bottom:1rem; font-size:0.85rem;">
                <div style="color:#64748b; font-size:0.75rem; text-transform:uppercase; font-weight:700;">Current store</div>
                <div style="font-weight:700;"><?= htmlspecialchars($freezer['store_name']) ?></div>
                <div style="color:#64748b; font-family:var(--font-mono);"><?= htmlspecialchars($freezer['outlet_id']) ?> · <?= htmlspecialchars($freezer['store_city']) ?></div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" id="transfer-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="freezer_id" value="<?= (int)$freezer['id'] ?>">

                <div class="form-group">
                    <label class="form-label required">Where is the freezer moving?</label>
                    <div style="display:flex; flex-wrap:wrap; gap:0.75rem;">
                        <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.875rem;">
                            <input type="radio" name="target_mode" value="existing" <?= $mode !== 'new' ? 'checked' : '' ?> onchange="toggleMode()">
                            Existing store (same / other outlet already in system)
                        </label>
                        <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.875rem;">
                            <input type="radio" name="target_mode" value="new" <?= $mode === 'new' ? 'checked' : '' ?> onchange="toggleMode()">
                            Create <strong>new outlet</strong> → new auto Store ID
                        </label>
                    </div>
                </div>

                <div id="mode-existing" style="<?= $mode === 'new' ? 'display:none;' : '' ?>">
                    <div style="display:flex; gap:0.5rem; margin-bottom:0.75rem; flex-wrap:wrap;">
                        <input type="search" name="q_display" id="store_search" class="form-control" style="flex:1; min-width:200px;" placeholder="Filter stores…" value="<?= htmlspecialchars($q) ?>" oninput="filterStores()">
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="new_store_id">Move to existing store</label>
                        <select name="new_store_id" id="new_store_id" class="form-control">
                            <option value="">— Select store —</option>
                            <?php foreach ($stores as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"
                                    data-search="<?= htmlspecialchars(strtolower($s['outlet_id'] . ' ' . $s['store_name'] . ' ' . $s['city'] . ' ' . ($s['contact_person'] ?? ''))) ?>">
                                    <?= htmlspecialchars($s['outlet_id'] . ' · ' . $s['store_name'] . ' · ' . $s['city']) ?>
                                    <?php if (!empty($s['contact_person'])): ?>
                                        (<?= htmlspecialchars($s['contact_person']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="mode-new" style="<?= $mode === 'new' ? '' : 'display:none;' ?>">
                    <div class="alert alert-info" style="margin-bottom:0.85rem; font-size:0.8rem;">
                        New outlet = new automatic <code>outlet_id</code> (blank ID field — system generates on save).
                        Freezer Unique ID still stays the same.
                    </div>
                    <div class="form-grid">
                        <div class="col-6 form-group">
                            <label class="form-label required" for="store_name">New store name</label>
                            <input type="text" name="store_name" id="store_name" class="form-control" value="<?= htmlspecialchars($_POST['store_name'] ?? '') ?>" placeholder="Outlet / shop name">
                        </div>
                        <div class="col-6 form-group">
                            <label class="form-label" for="contact_person">Store manager / operator</label>
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
                            <input type="text" name="pincode" id="pincode" class="form-control" maxlength="6" inputmode="numeric" value="<?= htmlspecialchars($_POST['pincode'] ?? '') ?>">
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
                            <label class="form-label" for="phone_number">Phone</label>
                            <input type="tel" name="phone_number" id="phone_number" class="form-control" maxlength="10" value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>">
                        </div>
                        <div class="col-4 form-group">
                            <label class="form-label" for="aadhar_number">Aadhaar (optional)</label>
                            <input type="text" name="aadhar_number" id="aadhar_number" class="form-control" maxlength="12" value="<?= htmlspecialchars($_POST['aadhar_number'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-grid" style="margin-top:0.5rem;">
                    <div class="col-6 form-group">
                        <label class="form-label required" for="transfer_date">Transfer date</label>
                        <input type="date" name="transfer_date" id="transfer_date" class="form-control" value="<?= htmlspecialchars($_POST['transfer_date'] ?? date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-6 form-group">
                        <label class="form-label required" for="transfer_reason">Reason</label>
                        <select name="transfer_reason" id="transfer_reason" class="form-control" required>
                            <option value="Moved to another outlet (same owner)">Moved to another outlet (same owner)</option>
                            <option value="KYC found at new outlet">KYC found at new outlet</option>
                            <option value="Store closed / relocated">Store closed / relocated</option>
                            <option value="Redistribution">Redistribution</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-12 form-group">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea name="notes" id="notes" class="form-control" rows="2"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Confirm transfer</button>
            </form>
        </div>
    </div>
</div>

<script>
function toggleMode() {
    const isNew = document.querySelector('input[name="target_mode"]:checked').value === 'new';
    document.getElementById('mode-new').style.display = isNew ? '' : 'none';
    document.getElementById('mode-existing').style.display = isNew ? 'none' : '';
    const sel = document.getElementById('new_store_id');
    if (sel) sel.required = !isNew;
}
function filterStores() {
    const q = (document.getElementById('store_search').value || '').toLowerCase();
    document.querySelectorAll('#new_store_id option[data-search]').forEach(function (opt) {
        opt.hidden = q && !opt.getAttribute('data-search').includes(q);
    });
}
toggleMode();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
