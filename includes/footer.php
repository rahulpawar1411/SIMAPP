        </main>
        
        <footer style="padding: 1.25rem 1.5rem; text-align: center; font-size: 0.8rem; color: #94a3b8; border-top: 1px solid var(--border-color); background: #ffffff; margin-top: auto;">
            <div>&copy; <?= date('Y') ?> ReeferON RaaS Asset Infrastructure &bull; SIM Freezer & Store Profile Management</div>
            <div style="font-size: 0.725rem; margin-top: 0.25rem;">Compliant with IoT Cold-Chain Standards &bull; Auto-ID Generator & QR Verification v2.1</div>
        </footer>
    </div>
</div>

<!-- Leaflet JS for interactive map -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<!-- EXIF reader for store-front GPS / date auto-fill -->
<script src="https://unpkg.com/exifr@7.1.3/dist/full.umd.js"></script>

<!-- Application Scripts -->
<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
</body>
</html>
