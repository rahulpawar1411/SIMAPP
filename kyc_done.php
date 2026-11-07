<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;

AuthService::requireLogin();

$uid = trim((string)($_GET['id'] ?? ''));
$result = strtoupper(trim((string)($_GET['result'] ?? 'PASS')));
if (!in_array($result, ['PASS', 'FAIL'], true)) {
    $result = 'PASS';
}
$newOutlet = trim((string)($_GET['new_outlet'] ?? ''));

if ($uid === '') {
    header('Location: field_home.php');
    exit;
}

$freezer = Freezer::findByUniqueId($uid);
if (!$freezer) {
    header('Location: profiles.php');
    exit;
}

$kycStatus = [];
try {
    $kycStatus = FreezerLifecycle::kycStatusForFreezer((int)$freezer['id']);
} catch (Throwable $e) {
    $kycStatus = [];
}
$verifiedAt = (string)($kycStatus['last_at_store']['verified_at']
    ?? $kycStatus['current']['verified_at']
    ?? date('Y-m-d H:i:s'));
$whenLabel = date('d M Y, h:i A', strtotime($verifiedAt) ?: time());
$isFail = $result === 'FAIL';

$pageTitle = $isFail ? 'KYC Failed' : 'KYC Completed';
$bodyClass = 'field-app-page field-shell-page';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mapp mapp-dash kyc-done">
    <div class="kyc-done-card">
        <div class="kyc-done-icon <?= $isFail ? 'is-fail' : 'is-pass' ?>" aria-hidden="true">
            <?php if ($isFail): ?>
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <?php else: ?>
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?php endif; ?>
        </div>
        <h1><?= $isFail ? 'KYC saved as FAIL' : 'KYC completed!' ?></h1>
        <p class="kyc-done-sub">
            <?= $isFail
                ? 'Failure note is on the profile. Admin can review under Assets → Issue.'
                : 'Verification is saved on the freezer profile.' ?>
        </p>

        <ul class="kyc-done-summary">
            <li><span>Store</span><strong><?= htmlspecialchars((string)($freezer['store_name'] ?? '—')) ?></strong></li>
            <li><span>Freezer ID</span><strong class="mono"><?= htmlspecialchars((string)$freezer['unique_freezer_id']) ?></strong></li>
            <li><span>City</span><strong><?= htmlspecialchars((string)($freezer['store_city'] ?? $freezer['city'] ?? '—')) ?></strong></li>
            <li><span>Result</span><strong class="<?= $isFail ? 'bad' : 'good' ?>"><?= htmlspecialchars($result) ?></strong></li>
            <li><span>Date &amp; time</span><strong><?= htmlspecialchars($whenLabel) ?></strong></li>
            <?php if ($newOutlet !== ''): ?>
                <li><span>New store ID</span><strong class="mono"><?= htmlspecialchars($newOutlet) ?></strong></li>
            <?php endif; ?>
        </ul>

        <div class="kyc-done-actions">
            <a href="profile_view.php?id=<?= urlencode((string)$freezer['unique_freezer_id']) ?>" class="btn btn-primary mapp-btn">View profile</a>
            <a href="qr_scanner.php" class="btn btn-outline mapp-btn">Scan next QR</a>
            <a href="field_home.php" class="btn btn-secondary mapp-btn">Home</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
