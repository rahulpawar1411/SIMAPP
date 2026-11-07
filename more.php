<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;

AuthService::requireLogin();
$currentUser = AuthService::currentUser();

$pageTitle = 'More';
$bodyClass = 'field-app-page field-shell-page';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mapp mapp-dash mapp-more">
    <div class="mapp-profile-card">
        <div class="mapp-hero-av lg"><?= strtoupper(substr((string)($currentUser['name'] ?? 'U'), 0, 1)) ?></div>
        <div>
            <strong><?= htmlspecialchars($currentUser['name'] ?? 'User') ?></strong>
            <p><?= htmlspecialchars($currentUser['email'] ?? '') ?></p>
            <span class="mapp-pill mapp-pill-pending"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $currentUser['role'] ?? ''))) ?></span>
        </div>
    </div>

    <nav class="mapp-menu" aria-label="More options">
        <a href="profiles.php">
            <span>Freezer Assets</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <?php if (($currentUser['role'] ?? '') === \App\Config\AppConfig::ROLE_TECHNICIAN): ?>
        <a href="field_home.php">
            <span>Home</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <?php endif; ?>
        <a href="blank_qr.php">
            <span>QR Management</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <?php if (AuthService::hasRole('super_admin')): ?>
        <a href="index.php">
            <span>Admin Dashboard</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="notifications.php">
            <span>Notifications<?php
                try {
                    $pc = \App\Models\AppNotification::countPendingApprovals();
                    if ($pc > 0) {
                        echo ' (' . (int)$pc . ')';
                    }
                } catch (Throwable $e) {}
            ?></span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="users.php">
            <span>Technicians</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <?php endif; ?>
        <a href="profiles.php?kyc[]=failed">
            <span>KYC Issues</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="logout.php" class="is-danger">
            <span>Logout</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
    </nav>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
