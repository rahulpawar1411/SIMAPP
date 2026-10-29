<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\Freezer;

AuthService::requireLogin();

// Manual lookup submission
if (!empty($_POST['manual_freezer_id'])) {
    $searchId = trim($_POST['manual_freezer_id']);
    $found = Freezer::findByUniqueId($searchId);
    if ($found) {
        header("Location: profile_view.php?id=" . urlencode($found['unique_freezer_id']));
        exit;
    } else {
        $error = "No freezer found matching ID '{$searchId}'.";
    }
}

$pageTitle = 'Scan Freezer QR Tag';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 600px; margin: 0 auto;">
    <div style="text-align: center; margin-bottom: 1.5rem;">
        <h2 style="font-size: 1.4rem; font-weight: 800; color: #0f172a;">Scan Freezer QR Tag</h2>
        <p style="color: #64748b; font-size: 0.875rem;">Point your device camera at the freezer asset tag or enter ID manually.</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Camera Video Card -->
    <div class="card" style="margin-bottom: 1.5rem; overflow: hidden;">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                    <circle cx="12" cy="13" r="4"/>
                </svg>
                <span>Live Camera Viewfinder</span>
            </div>
            <span id="cam-status" class="badge badge-primary">Starting Camera...</span>
        </div>

        <div style="position: relative; background: #0f172a; min-height: 320px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
            <video id="qr-video" playsinline style="width: 100%; max-height: 380px; object-fit: cover;"></video>
            <canvas id="qr-canvas" style="display: none;"></canvas>

            <!-- Target Reticle Overlay -->
            <div style="position: absolute; width: 220px; height: 220px; border: 2px dashed #38bdf8; border-radius: 16px; pointer-events: none; box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.45); display: flex; align-items: center; justify-content: center;">
                <span style="color: #38bdf8; font-size: 0.75rem; font-weight: 700; background: rgba(15, 23, 42, 0.8); padding: 0.2rem 0.5rem; border-radius: 4px;">Align QR Code</span>
            </div>
        </div>

        <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center;">
            <button type="button" id="btn-toggle-cam" class="btn btn-sm btn-secondary">
                Restart Camera
            </button>
            <label class="btn btn-sm btn-outline" style="margin: 0; cursor: pointer;">
                <span>Upload QR Image</span>
                <input type="file" id="qr-file-input" accept="image/*" style="display: none;">
            </label>
        </div>
    </div>

    <!-- Manual ID Fallback Form -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <span>Manual Freezer ID Lookup</span>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" action="" style="display: flex; gap: 0.75rem;">
                <input type="text" name="manual_freezer_id" class="form-control" placeholder="e.g. DF-RW-SFR550DDU-AMD1-058-0324-AAA004" required>
                <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                    Open Profile
                </button>
            </form>
        </div>
    </div>
</div>

<!-- jsQR Library for Real-time In-Browser Barcode/QR Scanning -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const video = document.getElementById('qr-video');
    const canvas = document.getElementById('qr-canvas');
    const ctx = canvas.getContext('2d');
    const camStatus = document.getElementById('cam-status');
    const btnToggle = document.getElementById('btn-toggle-cam');
    const fileInput = document.getElementById('qr-file-input');

    let stream = null;
    let scanning = true;

    function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            camStatus.textContent = 'Camera Unavailable';
            camStatus.className = 'badge badge-warning';
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                video.setAttribute('playsinline', true);
                video.play();
                camStatus.textContent = 'Camera Active';
                camStatus.className = 'badge badge-success';
                requestAnimationFrame(tick);
            })
            .catch(function (err) {
                camStatus.textContent = 'Camera Permission Blocked';
                camStatus.className = 'badge badge-danger';
            });
    }

    function tick() {
        if (!scanning) return;

        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.height = video.videoHeight;
            canvas.width = video.videoWidth;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: 'dontInvert',
            });

            if (code && code.data) {
                handleScannedData(code.data);
                return;
            }
        }
        requestAnimationFrame(tick);
    }

    function handleScannedData(data) {
        scanning = false;
        camStatus.textContent = 'Tag Detected!';
        camStatus.className = 'badge badge-success';

        // Play feedback tone
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = audioCtx.createOscillator();
            osc.frequency.setValueAtTime(800, audioCtx.currentTime);
            osc.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.15);
        } catch (e) {}

        // Check if QR data is a URL or direct ID
        let freezerId = data.trim();
        if (freezerId.includes('id=')) {
            const url = new URL(freezerId, window.location.origin);
            freezerId = url.searchParams.get('id') || freezerId;
        }

        window.location.href = 'profile_view.php?id=' + encodeURIComponent(freezerId);
    }

    // File upload scanner
    if (fileInput) {
        fileInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (event) {
                const img = new Image();
                img.onload = function () {
                    canvas.width = img.width;
                    canvas.height = img.height;
                    ctx.drawImage(img, 0, 0);
                    const imageData = ctx.getImageData(0, 0, img.width, img.height);
                    const code = jsQR(imageData.data, imageData.width, imageData.height);
                    if (code && code.data) {
                        handleScannedData(code.data);
                    } else {
                        alert('No QR code detected in this image.');
                    }
                };
                img.src = event.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    if (btnToggle) {
        btnToggle.addEventListener('click', function () {
            if (stream) {
                stream.getTracks().forEach(t => t.stop());
            }
            scanning = true;
            startCamera();
        });
    }

    startCamera();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
