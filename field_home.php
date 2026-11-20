<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Models\AppNotification;
use App\Services\KycScanGate;

AuthService::requireLogin();
$currentUser = AuthService::currentUser();
$isTech = (($currentUser['role'] ?? '') === AppConfig::ROLE_TECHNICIAN);
// Home is technician-only
if (!$isTech) {
    if (($currentUser['role'] ?? '') === AppConfig::ROLE_SUPER_ADMIN) {
        header('Location: index.php');
    } else {
        header('Location: profiles.php');
    }
    exit;
}
$uid = (int)($currentUser['id'] ?? 0);
$areaScope = AuthService::areaScope();
$areaLabel = trim(
    ($areaScope['zone'] !== '' ? $areaScope['zone'] : '')
    . ($areaScope['city'] !== '' ? ($areaScope['zone'] !== '' ? ' · ' : '') . $areaScope['city'] : '')
);
if ($areaLabel === '') {
    $areaLabel = 'All areas';
}

// Home = totals only (no lists)
$myInstallCount = 0;

try {
    $myInstallCount = Freezer::countFiltered(AuthService::applyAreaToFilters([
        'created_by' => $uid,
    ]));
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'field_home.install_count');
    $myInstallCount = 0;
}

$myEditRequests = [];
$myEditUnlocks = [];
$myEditPendingCount = 0;
if ($uid > 0) {
    try {
        AppNotification::expireStalePendingEdits(7);
        $myEditUnlocks = AppNotification::listActiveEditUnlocks($uid, 8);
        $myEditRequests = AppNotification::listMyEditRequests($uid, 12);
        foreach ($myEditRequests as $req) {
            if (($req['status'] ?? '') === 'pending') {
                $myEditPendingCount++;
            }
        }
    } catch (Throwable $e) {
        \App\Services\AppLogger::exception($e, 'field_home.edit_requests');
        $myEditRequests = [];
        $myEditUnlocks = [];
        $myEditPendingCount = 0;
    }
}

$hasEditUnlockAlert = count($myEditUnlocks) > 0;

$amcDueCount = 0;
$amcDueList = [];
$amcGapMonths = (int)FreezerLifecycle::KYC_GAP_MONTHS;
try {
    $amcStats = FreezerLifecycle::dashboardKycStats(8, [
        'zone' => $areaScope['zone'] ?? '',
        'city' => $areaScope['city'] ?? '',
    ]);
    $amcDueCount = (int)($amcStats['pending'] ?? 0);
    $amcDueList = is_array($amcStats['pending_list'] ?? null) ? $amcStats['pending_list'] : [];
    $amcGapMonths = (int)($amcStats['gap_months'] ?? $amcGapMonths);
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'field_home.amc_due');
}

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = trim(explode(' ', (string)($currentUser['name'] ?? 'there'))[0]);

$pageTitle = 'Home';
$bodyClass = 'field-app-page field-home-page';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mapp mapp-dash mapp-home-totals">
    <header class="mapp-hero adash-welcome">
        <div class="mapp-hero-text">
            <p class="mapp-greet"><?= htmlspecialchars($greeting) ?>,</p>
            <h1 class="mapp-name adash-welcome-title"><?= htmlspecialchars($firstName) ?></h1>
            <p class="mapp-role adash-welcome-sub">Technician · <?= htmlspecialchars($areaLabel) ?></p>
        </div>
        <div class="mapp-hero-av mapp-hero-av-alert" aria-hidden="true">
            <?= strtoupper(substr($firstName, 0, 1)) ?>
        </div>
    </header>

    <div class="mapp-kpi mapp-kpi-totals">
        <a class="mapp-kpi-card" href="profiles.php?mine=1">
            <span class="mapp-kpi-label">Total installations</span>
            <strong><?= number_format($myInstallCount) ?></strong>
        </a>
        <a class="mapp-kpi-card" href="profiles.php?kyc=pending">
            <span class="mapp-kpi-label">AMC due</span>
            <strong><?= number_format($amcDueCount) ?></strong>
        </a>
        <a class="mapp-kpi-card" href="qr_scanner.php">
            <span class="mapp-kpi-label">Add new</span>
            <strong>Scan</strong>
        </a>
    </div>

    <?php if ($amcDueCount > 0): ?>
    <a href="profiles.php?kyc=pending" class="mapp-kyc-alert">
        <span class="mapp-alert-dot" aria-hidden="true"></span>
        <div>
            <strong>AMC alert</strong>
            <span><?= (int)$amcDueCount ?> freezer<?= $amcDueCount === 1 ? '' : 's' ?> — alert <?= (int)FreezerLifecycle::KYC_ALERT_LEAD_MONTHS ?> month before due (cycle <?= (int)$amcGapMonths ?> mo) · <?= htmlspecialchars($areaLabel) ?></span>
        </div>
        <span class="mapp-banner-cta">Open list</span>
    </a>
    <?php if (!empty($amcDueList)): ?>
    <section class="mapp-edit-req-card" aria-label="AMC due list">
        <div class="mapp-edit-req-head">
            <h2>Due for AMC</h2>
            <span class="badge badge-warning"><?= (int)$amcDueCount ?></span>
        </div>
        <ul class="mapp-due-list">
            <?php foreach ($amcDueList as $dueRow):
                $dueFid = (int)($dueRow['id'] ?? 0);
                $dueUid = (string)($dueRow['unique_freezer_id'] ?? '');
                $dueStore = trim((string)($dueRow['store_name'] ?? ''));
                $dueCity = trim((string)($dueRow['city'] ?? ''));
                $dueWhen = (string)($dueRow['next_kyc_due'] ?? '');
                $dueWhenLabel = ($dueWhen !== '' && strtotime($dueWhen)) ? date('d M Y', strtotime($dueWhen)) : '—';
                $isOverdueNow = ($dueWhen !== '' && strtotime($dueWhen) !== false && strtotime($dueWhen) <= strtotime(date('Y-m-d')));
                $dueTag = $isOverdueNow ? 'Overdue' : 'Due soon';
                $doAmcHref = KycScanGate::scannerUrl([
                    'id' => $dueFid,
                    'unique_freezer_id' => $dueUid,
                ]);
                ?>
                <li>
                    <a href="profile_view.php?id=<?= urlencode($dueUid !== '' ? $dueUid : (string)$dueFid) ?>&tab=amc">
                        <strong><?= htmlspecialchars($dueStore !== '' ? $dueStore : 'Store') ?></strong>
                        <span class="mapp-due-id"><?= htmlspecialchars($dueUid !== '' ? $dueUid : ('#' . $dueFid)) ?></span>
                        <span class="mapp-due-meta"><?= htmlspecialchars(trim($dueCity . ' · ' . $dueTag . ' ' . $dueWhenLabel, ' ·')) ?></span>
                    </a>
                    <a class="mapp-due-go" href="<?= htmlspecialchars($doAmcHref) ?>">Do AMC</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($hasEditUnlockAlert):
        $firstUnlock = $myEditUnlocks[0];
        $unlockFid = (int)($firstUnlock['freezer_id'] ?? 0);
        $unlockTag = (string)($firstUnlock['unique_freezer_id'] ?? ('#' . $unlockFid));
        ?>
    <a href="profile_edit.php?id=<?= $unlockFid ?>" class="mapp-kyc-alert mapp-edit-unlock-alert">
        <span class="mapp-alert-dot mapp-alert-dot-ok" aria-hidden="true"></span>
        <div>
            <strong>Edit approved</strong>
            <span>
                <?= htmlspecialchars($unlockTag) ?> ready to edit
                <?php if (count($myEditUnlocks) > 1): ?>
                    · +<?= count($myEditUnlocks) - 1 ?> more
                <?php endif; ?>
                (1 save / 24h)
            </span>
        </div>
        <span class="mapp-banner-cta">Edit now</span>
    </a>
    <?php endif; ?>

    <?php
    $latestReject = null;
    foreach ($myEditRequests as $req) {
        if (strtolower((string)($req['status'] ?? '')) !== 'rejected') {
            continue;
        }
        $when = strtotime((string)($req['reviewed_at'] ?? $req['created_at'] ?? ''));
        if ($when && (time() - $when) <= 3 * 86400) {
            $latestReject = $req;
            break;
        }
    }
    if (!$hasEditUnlockAlert && $latestReject):
        $rejFid = (int)($latestReject['freezer_id'] ?? 0);
        $rejTag = (string)($latestReject['unique_freezer_id'] ?? ('#' . $rejFid));
        $rejNote = trim((string)($latestReject['review_notes'] ?? ''));
        ?>
    <a href="profile_view.php?id=<?= $rejFid ?>" class="mapp-kyc-alert">
        <span class="mapp-alert-dot" aria-hidden="true"></span>
        <div>
            <strong>Edit rejected</strong>
            <span>
                <?= htmlspecialchars($rejTag) ?>
                <?php if ($rejNote !== '' && stripos($rejNote, 'auto-expired') === false): ?>
                    · <?= htmlspecialchars(mb_substr($rejNote, 0, 80)) ?>
                <?php elseif (stripos($rejNote, 'auto-expired') !== false): ?>
                    · request expired (7 days)
                <?php else: ?>
                    · send a new remark if still needed
                <?php endif; ?>
            </span>
        </div>
        <span class="mapp-banner-cta">Open</span>
    </a>
    <?php endif; ?>

    <section class="mapp-edit-req-card" aria-label="My edit requests">
        <div class="mapp-edit-req-head">
            <h2>My edit requests</h2>
            <?php if ($myEditPendingCount > 0): ?>
                <span class="badge badge-warning"><?= (int)$myEditPendingCount ?> pending</span>
            <?php elseif ($hasEditUnlockAlert): ?>
                <span class="badge badge-success"><?= count($myEditUnlocks) ?> approved</span>
            <?php else: ?>
                <span class="badge badge-secondary">None open</span>
            <?php endif; ?>
        </div>
        <?php if (empty($myEditRequests)): ?>
            <p class="mapp-edit-req-empty">No edit requests yet. Open a profile → Request edit → send remark.</p>
        <?php else: ?>
            <ul class="mapp-edit-req-list">
                <?php foreach ($myEditRequests as $req):
                    $st = strtolower((string)($req['status'] ?? ''));
                    $fid = (int)($req['freezer_id'] ?? 0);
                    $tag = (string)($req['unique_freezer_id'] ?? ('#' . $fid));
                    $store = (string)($req['profile_store_name'] ?? '');
                    $payload = AppNotification::decodePayload($req);
                    $isUnlocked = $st === 'approved'
                        && !empty($payload['unlocked'])
                        && empty($payload['consumed']);
                    $unlockedAt = strtotime((string)($payload['unlocked_at'] ?? $req['reviewed_at'] ?? ''));
                    if ($isUnlocked && $unlockedAt && (time() - $unlockedAt) > 86400) {
                        $isUnlocked = false;
                    }
                    $href = $isUnlocked
                        ? ('profile_edit.php?id=' . $fid)
                        : ('profile_view.php?id=' . $fid);
                    $badgeClass = $st === 'pending' ? 'badge-warning' : ($st === 'approved' ? 'badge-success' : 'badge-danger');
                    $badgeLabel = $isUnlocked ? 'Ready to edit' : ucfirst($st !== '' ? $st : '—');
                    if ($st === 'approved' && !$isUnlocked) {
                        $badgeLabel = !empty($payload['consumed']) ? 'Used' : 'Expired';
                        $badgeClass = 'badge-secondary';
                    }
                    ?>
                    <li>
                        <a href="<?= htmlspecialchars($href) ?>">
                            <div class="mapp-edit-req-main">
                                <strong><?= htmlspecialchars($tag) ?></strong>
                                <span><?= htmlspecialchars($store !== '' ? $store : '—') ?></span>
                            </div>
                            <span class="badge badge-sm <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <div class="mapp-actions mapp-actions-row">
        <a href="qr_scanner.php" class="btn btn-primary mapp-btn">Scan QR — fill data</a>
        <a href="profiles.php" class="btn btn-secondary mapp-btn">Freezer Assets</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
