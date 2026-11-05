<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\KycScanGate;
use App\Models\Freezer;
use App\Models\AssetTag;

AuthService::requireLogin();

$purpose = strtolower(trim((string)($_GET['purpose'] ?? $_POST['purpose'] ?? '')));
$isKycPurpose = ($purpose === 'kyc');
$expectUnique = trim((string)($_GET['expect'] ?? $_POST['expect'] ?? ''));
$error = null;

// Camera / file scan unlock for KYC (JS posts here)
if ($isKycPurpose && !empty($_POST['kyc_scan_code'])) {
    $unlock = KycScanGate::tryUnlock((string)$_POST['kyc_scan_code'], $expectUnique);
    if ($unlock['ok'] && $unlock['freezer']) {
        header('Location: freezer_kyc.php?id=' . (int)$unlock['freezer']['id']);
        exit;
    }
    $error = $unlock['error'] ?? 'Scan unlock failed.';
}

// Manual lookup submission (freezer Unique ID or blank tag RO-XXXXXX)
if (!empty($_POST['manual_freezer_id'])) {
    $searchId = trim($_POST['manual_freezer_id']);
    if ($isKycPurpose) {
        $unlock = KycScanGate::tryUnlock($searchId, $expectUnique);
        if ($unlock['ok'] && $unlock['freezer']) {
            header('Location: freezer_kyc.php?id=' . (int)$unlock['freezer']['id']);
            exit;
        }
        $error = $unlock['error'] ?? "No match for '{$searchId}'.";
    } else {
        $found = Freezer::findByUniqueId($searchId);
        if ($found) {
            header("Location: profile_view.php?id=" . urlencode($found['unique_freezer_id']));
            exit;
        }
        $tag = AssetTag::findByCode($searchId);
        if ($tag) {
            header('Location: tag.php?t=' . urlencode($tag['tag_code']));
            exit;
        }
        $error = "No freezer or blank QR tag found matching '{$searchId}'.";
    }
}

$pageTitle = $isKycPurpose ? 'Scan QR for KYC' : 'Scan Freezer QR Tag';
require_once __DIR__ . '/includes/header.php';
?>

<div class="field-scan-wrap" style="max-width: 600px; margin: 0 auto;">
    <div class="cfg-toolbar" style="margin-bottom:0.75rem;">
        <div class="cfg-toolbar-left">
            <h1 class="cfg-toolbar-title"><?= $isKycPurpose ? 'Scan QR → Unlock KYC' : 'Scan QR → Register / View' ?></h1>
        </div>
    </div>
    <?php if ($isKycPurpose): ?>
        <p style="color: var(--text-secondary); font-size: 0.875rem; margin: 0 0 0.85rem; line-height: 1.45;">
            Is freezer ka physical QR / Tag scan karo.
            <?php if ($expectUnique !== ''): ?>
                Expected: <code><?= htmlspecialchars($expectUnique) ?></code>
            <?php endif; ?>
            Galat unit scan hone pe KYC nahi khulegi.
        </p>
    <?php else: ?>
    <p style="color: var(--text-secondary); font-size: 0.875rem; margin: 0 0 0.85rem; line-height: 1.45;">
        <strong>Unused</strong> sticker → fill form. <strong>Registered</strong> sticker → show details.
    </p>
    <?php endif; ?>
    <div class="field-scan-steps" style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem; font-size:0.75rem; font-weight:600; color:#475569;">
        <span style="background:#f0fdf4; color:#166534; padding:0.25rem 0.55rem; border-radius:6px; border:1px solid #bbf7d0;">1. Scan</span>
        <span style="background:#fefce8; color:#854d0e; padding:0.25rem 0.55rem; border-radius:6px; border:1px solid #fde68a;">2. Fill info</span>
        <span style="background:#dcfce7; color:#14532d; padding:0.25rem 0.55rem; border-radius:6px; border:1px solid #86efac;">3. Done</span>
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

        <div class="field-scan-camera" style="position: relative; background: #0f172a; min-height: 320px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
            <video id="qr-video" playsinline muted style="width: 100%; max-height: 380px; object-fit: cover;"></video>
            <canvas id="qr-canvas" style="display: none;"></canvas>

            <!-- Target Reticle Overlay -->
            <div style="position: absolute; width: 220px; height: 220px; border: 2px dashed #38bdf8; border-radius: 16px; pointer-events: none; box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.45); display: flex; align-items: center; justify-content: center;">
                <span style="color: #38bdf8; font-size: 0.75rem; font-weight: 700; background: rgba(15, 23, 42, 0.8); padding: 0.2rem 0.5rem; border-radius: 4px;">Align QR Code</span>
            </div>
        </div>

        <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" id="btn-toggle-cam" class="btn btn-secondary" style="flex:1; min-width:140px; justify-content:center;">
                Restart Camera
            </button>
            <label class="btn btn-outline" style="margin: 0; cursor: pointer; flex:1; min-width:140px; justify-content:center;">
                <span>Upload QR Image</span>
                <input type="file" id="qr-file-input" accept="image/*" capture="environment" style="display: none;">
            </label>
        </div>
    </div>

    <!-- Manual ID Fallback Form -->
    <div class="card field-scan-manual">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <span>Type code if camera fails</span>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="manual-qr-form" style="display: flex; gap: 0.75rem; align-items: flex-start;">
                <?php if ($isKycPurpose): ?>
                    <input type="hidden" name="purpose" value="kyc">
                    <input type="hidden" name="expect" value="<?= htmlspecialchars($expectUnique) ?>">
                <?php endif; ?>
                <div class="qr-suggest-wrap" style="flex:1; min-width:0;">
                    <input type="text" name="manual_freezer_id" id="manual-freezer-id" class="form-control"
                           inputmode="text" autocomplete="off" autocapitalize="characters"
                           placeholder="Start typing RO-… or Unique ID" required
                           aria-autocomplete="list" aria-controls="qr-suggest-panel" aria-expanded="false">
                    <div id="qr-suggest-panel" class="qr-suggest-panel" hidden role="listbox"></div>
                    <p class="qr-suggest-hint"><?= $isKycPurpose
                        ? 'Type the same Unique Freezer ID / Tag as on the physical unit.'
                        : 'Suggestions by QR type — <strong>Unused</strong> blank tags &amp; <strong>Assigned</strong> (with freezer type)' ?></p>
                </div>
                <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                    <?= $isKycPurpose ? 'Unlock KYC' : 'Open' ?>
                </button>
            </form>
            <?php if ($isKycPurpose): ?>
            <form method="POST" action="" id="kyc-scan-unlock-form" hidden>
                <input type="hidden" name="purpose" value="kyc">
                <input type="hidden" name="expect" value="<?= htmlspecialchars($expectUnique) ?>">
                <input type="hidden" name="kyc_scan_code" id="kyc-scan-code" value="">
            </form>
            <?php endif; ?>
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

    const isKycPurpose = <?= $isKycPurpose ? 'true' : 'false' ?>;

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

        // Blank tag URL, profile URL, or raw ID / RO-XXXXXX
        let raw = data.trim();

        // KYC: server must verify scan matches expected freezer
        if (isKycPurpose) {
            const unlockForm = document.getElementById('kyc-scan-unlock-form');
            const codeInput = document.getElementById('kyc-scan-code');
            if (unlockForm && codeInput) {
                codeInput.value = raw;
                unlockForm.submit();
                return;
            }
        }

        try {
            const url = new URL(raw, window.location.origin);
            const tagCode = url.searchParams.get('t');
            if (tagCode || /tag\.php$/i.test(url.pathname)) {
                window.location.href = 'tag.php?t=' + encodeURIComponent(tagCode || '');
                return;
            }
            const freezerId = url.searchParams.get('id');
            if (freezerId) {
                window.location.href = 'profile_view.php?id=' + encodeURIComponent(freezerId);
                return;
            }
        } catch (e) {}

        if (/^RO-[A-Z0-9]+$/i.test(raw)) {
            window.location.href = 'tag.php?t=' + encodeURIComponent(raw.toUpperCase());
            return;
        }

        window.location.href = 'profile_view.php?id=' + encodeURIComponent(raw);
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
    initManualQrSuggest();
});

function initManualQrSuggest() {
    const input = document.getElementById('manual-freezer-id');
    const panel = document.getElementById('qr-suggest-panel');
    if (!input || !panel) return;

    let timer = null;
    let activeIdx = -1;
    let items = [];
    let abortCtrl = null;

    function hide() {
        panel.hidden = true;
        panel.innerHTML = '';
        input.setAttribute('aria-expanded', 'false');
        activeIdx = -1;
        items = [];
    }

    function setActive(idx) {
        const buttons = panel.querySelectorAll('.qr-suggest-item');
        buttons.forEach(function (btn, i) {
            btn.classList.toggle('is-active', i === idx);
            if (i === idx) btn.setAttribute('aria-selected', 'true');
            else btn.removeAttribute('aria-selected');
        });
        activeIdx = idx;
        if (idx >= 0 && buttons[idx]) {
            buttons[idx].scrollIntoView({ block: 'nearest' });
        }
    }

    function pick(item) {
        if (!item || !item.code) return;
        input.value = item.code;
        hide();
        input.form && input.form.submit();
    }

    function render(list) {
        items = list || [];
        if (!items.length) {
            panel.innerHTML = '<div class="qr-suggest-empty">No matching QR / freezer ID</div>';
            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            return;
        }
        panel.innerHTML = items.map(function (it, i) {
            const status = it.status === 'assigned' ? 'assigned' : 'unused';
            const badge = status === 'assigned' ? 'Assigned' : 'Unused';
            const metaParts = [];
            if (it.freezer_type) metaParts.push(it.freezer_type);
            if (it.unique_freezer_id && it.unique_freezer_id !== it.code) metaParts.push(it.unique_freezer_id);
            if (it.store_name) metaParts.push(it.store_name);
            if (it.kind === 'freezer') metaParts.unshift('Freezer ID');
            const meta = metaParts.join(' · ');
            return '<button type="button" class="qr-suggest-item" role="option" data-idx="' + i + '">' +
                '<span class="qr-suggest-code">' + escapeHtml(it.code) + '</span>' +
                '<span class="qr-suggest-meta">' +
                    '<span class="qr-suggest-badge is-' + status + '">' + badge + '</span>' +
                    (meta ? '<span class="qr-suggest-type">' + escapeHtml(meta) + '</span>' : '') +
                '</span>' +
            '</button>';
        }).join('');
        panel.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        activeIdx = -1;
        panel.querySelectorAll('.qr-suggest-item').forEach(function (btn) {
            var onPick = function (e) {
                e.preventDefault();
                e.stopPropagation();
                pick(items[parseInt(btn.getAttribute('data-idx'), 10)]);
            };
            btn.addEventListener('pointerdown', onPick);
            btn.addEventListener('mousedown', onPick);
        });
    }

    function escapeHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fetchSuggest(q) {
        if (abortCtrl) abortCtrl.abort();
        abortCtrl = new AbortController();
        fetch('api/qr_suggest.php?q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            signal: abortCtrl.signal,
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    hide();
                    return;
                }
                render(data.items || []);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
            });
    }

    input.addEventListener('input', function () {
        const q = (input.value || '').trim();
        if (timer) clearTimeout(timer);
        if (q.length < 1) {
            hide();
            return;
        }
        timer = setTimeout(function () { fetchSuggest(q); }, 180);
    });

    input.addEventListener('keydown', function (e) {
        if (panel.hidden || !items.length) {
            if (e.key === 'Escape') hide();
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(Math.min(activeIdx + 1, items.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(Math.max(activeIdx - 1, 0));
        } else if (e.key === 'Enter' && activeIdx >= 0) {
            e.preventDefault();
            pick(items[activeIdx]);
        } else if (e.key === 'Escape') {
            hide();
        }
    });

    input.addEventListener('blur', function () {
        setTimeout(hide, 280);
    });

    input.addEventListener('focus', function () {
        const q = (input.value || '').trim();
        if (q.length >= 1) fetchSuggest(q);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
