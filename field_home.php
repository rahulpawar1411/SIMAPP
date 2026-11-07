<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;

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

// Home = totals only (no lists) + KYC alert
$myInstallCount = 0;
$kycPending = 0;
$myTodayCount = 0;

try {
    $myInstallCount = Freezer::countFiltered(AuthService::applyAreaToFilters([
        'created_by' => $uid,
    ]));
} catch (Throwable $e) {
    $myInstallCount = 0;
}

try {
    $kycDash = FreezerLifecycle::dashboardKycStats(0, $areaScope);
    $kycPending = (int)$kycDash['pending'];
} catch (Throwable $e) {
    $kycPending = 0;
}

if ($uid > 0) {
    try {
        $queue = FreezerLifecycle::technicianWorkQueue($uid, 1, $areaScope);
        $myTodayCount = (int)($queue['today_count'] ?? 0);
    } catch (Throwable $e) {
        $myTodayCount = 0;
    }
}

$hasKycAlert = $kycPending > 0;

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
            <?php if ($hasKycAlert): ?>
                <span class="mapp-alert-dot" title="KYC pending"></span>
            <?php endif; ?>
        </div>
    </header>

    <div class="mapp-kpi mapp-kpi-totals">
        <a class="mapp-kpi-card" href="profiles.php?mine=1">
            <span class="mapp-kpi-label">Total installations</span>
            <strong><?= number_format($myInstallCount) ?></strong>
        </a>
        <a class="mapp-kpi-card is-pending<?= $hasKycAlert ? ' has-alert' : '' ?>" href="profiles.php?kyc[]=pending">
            <?php if ($hasKycAlert): ?>
                <span class="mapp-alert-dot mapp-alert-dot-card" aria-hidden="true"></span>
            <?php endif; ?>
            <span class="mapp-kpi-label">KYC pending<?= $hasKycAlert ? ' · alert' : '' ?></span>
            <strong><?= number_format($kycPending) ?></strong>
        </a>
        <div class="mapp-kpi-card is-ok">
            <span class="mapp-kpi-label">Today KYC</span>
            <strong><?= number_format($myTodayCount) ?></strong>
        </div>
        <a class="mapp-kpi-card" href="qr_scanner.php">
            <span class="mapp-kpi-label">Add new</span>
            <strong>Scan</strong>
        </a>
    </div>

    <?php if ($hasKycAlert): ?>
    <a href="profiles.php?kyc[]=pending" class="mapp-kyc-alert">
        <span class="mapp-alert-dot" aria-hidden="true"></span>
        <div>
            <strong>KYC alert</strong>
            <span><?= number_format($kycPending) ?> freezer<?= $kycPending === 1 ? '' : 's' ?> pending in your area</span>
        </div>
        <span class="mapp-banner-cta">Open</span>
    </a>
    <?php endif; ?>

    <div class="mapp-actions mapp-actions-row">
        <a href="qr_scanner.php" class="btn btn-primary mapp-btn">Scan QR — fill data</a>
        <a href="profiles.php?kyc[]=pending" class="btn btn-secondary mapp-btn">
            KYC pending
            <?php if ($hasKycAlert): ?><span class="mapp-alert-dot mapp-alert-dot-btn" aria-hidden="true"></span><?php endif; ?>
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
