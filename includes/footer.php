        </main>
    </div>
</div>

<?php
$fieldNavPage = basename($_SERVER['PHP_SELF'] ?? '');
$fieldScanActive = in_array($fieldNavPage, ['qr_scanner.php', 'profile_add.php', 'tag.php'], true);
$fieldProfilesActive = in_array($fieldNavPage, ['profiles.php', 'profile_view.php', 'profile_edit.php'], true);
$fieldBlankActive = in_array($fieldNavPage, ['blank_qr.php', 'blank_qr_print.php'], true);
?>
<nav class="field-bottom-nav no-print" aria-label="Field shortcuts">
    <a href="qr_scanner.php" class="<?= $fieldScanActive ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
            <circle cx="12" cy="13" r="4"/>
        </svg>
        Scan QR
    </a>
    <a href="profiles.php" class="<?= $fieldProfilesActive ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="2" y="7" width="20" height="14" rx="2"/>
            <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
        </svg>
        Assets
    </a>
    <a href="blank_qr.php" class="<?= $fieldBlankActive ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
            <path d="M7 7h3v3H7zM14 7h3v3h-3zM7 14h3v3H7zM14 14h3v3h-3z"/>
        </svg>
        QR Tags
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
