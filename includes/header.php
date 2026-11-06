<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;

AuthService::startSession();
$currentUser = AuthService::currentUser();
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle ?? 'ReeferON SIM') ?> | Freezer Management</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    
    <!-- Leaflet Map CSS for GPS View/Picker -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    
    <!-- Application CSS -->
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (@filemtime(__DIR__ . '/../assets/css/app.css') ?: time()) ?>-theme-green">
    <?php if ($currentUser): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(AuthService::generateCsrfToken()) ?>">
    <?php endif; ?>
</head>
<body class="theme-cfg">
<div class="sidebar-backdrop" id="sidebar-backdrop" aria-hidden="true"></div>
<div class="app-layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="assets/img/logo.png" alt="ReeferON Logo" class="sidebar-logo">
            <div class="brand-text">
                <h1>ReeferON DF</h1>
                <span>Cold-Chain Assets</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <?php if ($currentUser && AuthService::hasRole('super_admin')): ?>
            <a href="index.php" class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
                <span>Dashboard</span>
            </a>
            <?php endif; ?>

            <a href="profiles.php" class="nav-link <?= in_array($currentPage, ['profiles.php', 'profile_view.php', 'profile_edit.php'], true) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                </svg>
                <span>Freezer Assets</span>
            </a>

            <a href="qr_scanner.php" class="nav-link <?= in_array($currentPage, ['qr_scanner.php', 'profile_add.php', 'tag.php'], true) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"/>
                    <rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/>
                    <rect x="3" y="14" width="7" height="7"/>
                </svg>
                <span>Scan QR</span>
            </a>

            <?php if ($currentUser && AuthService::hasRole(['super_admin', 'technician'])): ?>
            <a href="blank_qr.php" class="nav-link <?= in_array($currentPage, ['blank_qr.php', 'blank_qr_print.php'], true) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <path d="M7 7h3v3H7zM14 7h3v3h-3zM7 14h3v3H7zM14 14h3v3h-3z"/>
                </svg>
                <span>QR Management</span>
            </a>
            <?php endif; ?>

            <?php if ($currentUser && AuthService::hasRole('super_admin')): ?>
            <?php
            $notifPending = 0;
            try {
                // Legacy pending only (new KYC completes immediately and shows in date history)
                $notifPending = \App\Models\AppNotification::countPending();
            } catch (Throwable $e) {
                $notifPending = 0;
            }
            ?>
            <div class="nav-label">Operations</div>
            <a href="notifications.php" class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?><?= $notifPending > 0 ? ' has-notif-dot' : '' ?>">
                <span class="nav-link-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    <?php if ($notifPending > 0): ?>
                        <span class="nav-notif-dot" aria-hidden="true"></span>
                    <?php endif; ?>
                </span>
                <span>Activity</span>
                <?php if ($notifPending > 0): ?>
                    <span class="nav-link-badge"><?= $notifPending > 99 ? '99+' : (int)$notifPending ?></span>
                <?php endif; ?>
            </a>
            <a href="import_export.php" class="nav-link <?= $currentPage === 'import_export.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
                <span>Reports</span>
            </a>
            <a href="users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <span>Technicians</span>
            </a>
            <a href="admin_logs.php" class="nav-link <?= $currentPage === 'admin_logs.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <line x1="10" y1="9" x2="8" y2="9"/>
                </svg>
                <span>Logs</span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <?php if ($currentUser): ?>
                <div class="user-badge">
                    <div class="user-avatar"><?= strtoupper(substr($currentUser['name'] ?? 'U', 0, 1)) ?></div>
                    <div class="user-info">
                        <div class="user-name"><?= htmlspecialchars($currentUser['name']) ?></div>
                        <div class="user-role"><?= htmlspecialchars(str_replace('_', ' ', $currentUser['role'])) ?></div>
                    </div>
                    <a href="logout.php" title="Sign Out" style="color: #94a3b8; margin-left: auto;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                    </a>
                </div>
            <?php else: ?>
                <a href="login.php" class="btn btn-sm btn-primary" style="width: 100%;">Sign In</a>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" id="menu-toggle" class="menu-toggle" aria-label="Toggle menu">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="3" y1="12" x2="21" y2="12"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <div class="page-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></div>
            </div>

            <div class="topbar-right">
                <a href="qr_scanner.php" class="btn btn-sm btn-primary" title="Scan QR then fill details">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                        <circle cx="12" cy="13" r="4"/>
                    </svg>
                    <span>Scan</span>
                </a>
            </div>
        </header>

        <!-- Main Body Container -->
        <main class="container">
