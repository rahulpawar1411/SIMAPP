        </main>
    </div>
</div>

<?php
$fieldNavPage = basename($_SERVER['PHP_SELF'] ?? '');
$fieldUser = \App\Services\AuthService::currentUser();
$fieldIsTech = $fieldUser && (($fieldUser['role'] ?? '') === \App\Config\AppConfig::ROLE_TECHNICIAN);
$fieldIsAdmin = $fieldUser && (($fieldUser['role'] ?? '') === \App\Config\AppConfig::ROLE_SUPER_ADMIN);
$fieldHomeActive = ($fieldNavPage === 'field_home.php');
$fieldDashActive = ($fieldNavPage === 'index.php');
$fieldAssetsActive = in_array($fieldNavPage, ['profiles.php', 'profile_view.php', 'profile_edit.php', 'freezer_kyc.php'], true);
$fieldScanActive = in_array($fieldNavPage, ['qr_scanner.php', 'profile_add.php', 'tag.php'], true);
$fieldMoreActive = in_array($fieldNavPage, ['more.php', 'users.php', 'notifications.php', 'admin_logs.php', 'import_export.php', 'blank_qr.php', 'blank_qr_print.php', 'kyc_map.php'], true);
// 4 tabs (Home/Dash + Assets + Scan + More) or 3 for viewer
$fieldNavCols = ($fieldIsTech || $fieldIsAdmin) ? 4 : 3;
?>
<nav class="field-bottom-nav field-nav-cols-<?= (int)$fieldNavCols ?> no-print" aria-label="Mobile navigation">
    <?php if ($fieldIsTech): ?>
    <a href="field_home.php" class="<?= $fieldHomeActive ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        <span>Home</span>
    </a>
    <?php elseif ($fieldIsAdmin): ?>
    <a href="index.php" class="<?= $fieldDashActive ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        <span>Dashboard</span>
    </a>
    <?php endif; ?>
    <a href="profiles.php" class="<?= $fieldAssetsActive ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <rect x="2" y="7" width="20" height="14" rx="2"/>
            <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
        </svg>
        <span>Assets</span>
    </a>
    <a href="qr_scanner.php" class="field-nav-scan <?= $fieldScanActive ? 'active' : '' ?>">
        <span class="field-nav-scan-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
            </svg>
        </span>
        <span>Scan</span>
    </a>
    <a href="more.php" class="<?= $fieldMoreActive ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>
        </svg>
        <span>More</span>
    </a>
</nav>

<!-- Leaflet JS for interactive map -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<!-- EXIF reader for store-front GPS / date auto-fill -->
<script src="https://unpkg.com/exifr@7.1.3/dist/full.umd.js"></script>

<!-- Application Scripts -->
<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
</body>
</html>
