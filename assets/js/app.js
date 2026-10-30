/**
 * ReeferON SIM - Application JavaScript
 * Real-time ID Generation, GPS Geolocation, Image Upload Previews & Map integration
 */

document.addEventListener('DOMContentLoaded', function () {
    initSidebarToggle();
    initIdGenerator();
    initCascadingLocationSelector();
    initGpsCapture();
    initImageUploadPreviews();
    initStoreFrontExifAutofill();
    initIndianPhoneInputs();
    initLightbox();
});

// -------------------------------------------------------------
// Camera / Location permission popups
// -------------------------------------------------------------
function ensurePermModal() {
    let modal = document.getElementById('perm-access-modal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'perm-access-modal';
    modal.className = 'perm-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
        <div class="perm-sheet" role="dialog" aria-modal="true" aria-labelledby="perm-title">
            <h3 id="perm-title">Permission required</h3>
            <p id="perm-body"></p>
            <ul id="perm-steps" hidden></ul>
            <div class="perm-actions">
                <button type="button" class="btn btn-secondary" id="perm-cancel">Not now</button>
                <button type="button" class="btn btn-primary" id="perm-allow">Allow</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    return modal;
}

function closePermModal() {
    const modal = document.getElementById('perm-access-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

/**
 * Show permission prompt. Resolves true if user taps Allow, false if cancel.
 * mode: 'ask' | 'denied'
 */
function showPermissionPopup(options) {
    const opts = options || {};
    const mode = opts.mode || 'ask';
    const title = opts.title || 'Permission required';
    const body = opts.body || 'Please allow access to continue.';
    const steps = opts.steps || [];
    const allowLabel = opts.allowLabel || (mode === 'denied' ? 'Try again' : 'Allow');
    const cancelLabel = opts.cancelLabel || 'Not now';

    const modal = ensurePermModal();
    const sheet = modal.querySelector('.perm-sheet');
    const titleEl = modal.querySelector('#perm-title');
    const bodyEl = modal.querySelector('#perm-body');
    const stepsEl = modal.querySelector('#perm-steps');
    const allowBtn = modal.querySelector('#perm-allow');
    const cancelBtn = modal.querySelector('#perm-cancel');

    sheet.classList.toggle('is-denied', mode === 'denied');
    titleEl.textContent = title;
    bodyEl.textContent = body;
    if (steps.length) {
        stepsEl.hidden = false;
        stepsEl.innerHTML = steps.map(function (s) { return '<li>' + s + '</li>'; }).join('');
    } else {
        stepsEl.hidden = true;
        stepsEl.innerHTML = '';
    }
    allowBtn.textContent = allowLabel;
    cancelBtn.textContent = cancelLabel;

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');

    return new Promise(function (resolve) {
        const finish = function (ok) {
            allowBtn.onclick = null;
            cancelBtn.onclick = null;
            modal.onclick = null;
            closePermModal();
            resolve(ok);
        };
        allowBtn.onclick = function () { finish(true); };
        cancelBtn.onclick = function () { finish(false); };
        modal.onclick = function (e) {
            if (e.target === modal) finish(false);
        };
    });
}

async function queryBrowserPermission(name) {
    try {
        if (!navigator.permissions || !navigator.permissions.query) return 'prompt';
        const status = await navigator.permissions.query({ name: name });
        return status.state || 'prompt';
    } catch (e) {
        return 'prompt';
    }
}

function geoErrorMessage(err) {
    if (!err) return 'Unable to get location.';
    if (err.code === 1 || err.name === 'NotAllowedError') {
        return 'Location permission is blocked. Please allow Location for this site.';
    }
    if (err.code === 2) return 'GPS signal unavailable. Turn on Location / GPS and try again.';
    if (err.code === 3) return 'Location request timed out. Move outdoors and try again.';
    return err.message || 'Unable to get location.';
}

function cameraErrorMessage(err) {
    if (!err) return 'Unable to open camera.';
    const name = err.name || '';
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
        return 'Camera permission is blocked. Please allow Camera for this site.';
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        return 'No camera found on this device.';
    }
    if (name === 'NotReadableError' || name === 'TrackStartError') {
        return 'Camera is in use by another app. Close it and try again.';
    }
    return err.message || 'Unable to open camera.';
}

/**
 * Ask for location if needed. Returns true when we can call geolocation.
 */
async function ensureLocationPermission() {
    if (!navigator.geolocation) {
        await showPermissionPopup({
            mode: 'denied',
            title: 'Location not supported',
            body: 'This browser does not support GPS / location access.',
            allowLabel: 'OK',
            cancelLabel: 'Close'
        });
        return false;
    }

    const state = await queryBrowserPermission('geolocation');
    if (state === 'granted') return true;

    if (state === 'denied') {
        const retry = await showPermissionPopup({
            mode: 'denied',
            title: 'Allow Location',
            body: 'Location is blocked for this site. Enable it to capture GPS on Store Front photos and stamps.',
            steps: [
                'Tap the lock / info icon in the browser address bar',
                'Set Location to Allow',
                'Reload the page, then try again'
            ],
            allowLabel: 'I enabled it — Try again',
            cancelLabel: 'Cancel'
        });
        return retry;
    }

    // prompt — show our explain dialog, then browser prompt on Allow
    const ok = await showPermissionPopup({
        mode: 'ask',
        title: 'Allow Location access?',
        body: 'ReeferON needs your location to stamp GPS on photos and auto-fill store address / PIN.',
        steps: [
            'Tap Allow on the next browser popup',
            'Keep Location / GPS ON on your phone'
        ],
        allowLabel: 'Allow Location',
        cancelLabel: 'Not now'
    });
    return ok;
}

/**
 * Ask for camera if needed. Returns true when getUserMedia may proceed.
 */
async function ensureCameraPermission() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        await showPermissionPopup({
            mode: 'denied',
            title: 'Camera not supported',
            body: 'This browser cannot open the camera. Try Chrome / Safari on your phone.',
            allowLabel: 'OK',
            cancelLabel: 'Close'
        });
        return false;
    }

    // Permissions API name varies: 'camera' (Chrome)
    const state = await queryBrowserPermission('camera');
    if (state === 'granted') return true;

    if (state === 'denied') {
        const retry = await showPermissionPopup({
            mode: 'denied',
            title: 'Allow Camera',
            body: 'Camera is blocked for this site. Enable it to capture Store Front and freezer photos.',
            steps: [
                'Tap the lock / info icon in the browser address bar',
                'Set Camera to Allow',
                'Reload the page, then try again'
            ],
            allowLabel: 'I enabled it — Try again',
            cancelLabel: 'Cancel'
        });
        return retry;
    }

    const ok = await showPermissionPopup({
        mode: 'ask',
        title: 'Allow Camera access?',
        body: 'ReeferON needs the camera to capture Store Front and freezer photos with GPS stamp.',
        steps: [
            'Tap Allow on the next browser popup',
            'Prefer the rear camera for clear store photos'
        ],
        allowLabel: 'Allow Camera',
        cancelLabel: 'Not now'
    });
    return ok;
}

async function ensureCameraAndLocationPermissions() {
    const locOk = await ensureLocationPermission();
    if (!locOk) return { camera: false, location: false };

    const camOk = await ensureCameraPermission();
    return { camera: camOk, location: locOk };
}

/**
 * Normalize to 10-digit Indian mobile (no +91 / 0 prefix).
 * Handles paste/autofill like +91XXXXXXXXXX, 91XXXXXXXXXX, 0XXXXXXXXXX.
 */
function normalizeIndianMobileDigits(value) {
    let digits = String(value || '').replace(/\D/g, '');
    if (digits.length >= 12 && digits.indexOf('91') === 0) {
        digits = digits.slice(-10);
    } else if (digits.length === 11 && digits.charAt(0) === '0') {
        digits = digits.slice(1);
    } else if (digits.length > 10) {
        digits = digits.slice(-10);
    }
    return digits.slice(0, 10);
}

function isValidIndianMobile(value) {
    const digits = normalizeIndianMobileDigits(value);
    if (digits === '') return true; // optional empty
    return /^[6-9]\d{9}$/.test(digits);
}

// +91 prefix fields: exactly 10 digits (6–9 start)
function initIndianPhoneInputs() {
    document.querySelectorAll('#phone_number, #phone').forEach(function (phoneInput) {
        phoneInput.setAttribute('maxlength', '10');
        phoneInput.setAttribute('inputmode', 'numeric');
        phoneInput.setAttribute('autocomplete', 'tel-national');
        // Avoid cryptic browser pattern tooltips — we validate in JS
        phoneInput.removeAttribute('pattern');
        phoneInput.removeAttribute('required');

        // Normalize existing/autofilled value once
        if (phoneInput.value) {
            phoneInput.value = normalizeIndianMobileDigits(phoneInput.value);
        }

        phoneInput.addEventListener('input', function () {
            const caret = this.selectionStart;
            const before = this.value;
            this.value = normalizeIndianMobileDigits(this.value);
            // Keep caret reasonable after strip
            if (typeof caret === 'number' && before.length === this.value.length) {
                try { this.setSelectionRange(caret, caret); } catch (e) { /* ignore */ }
            }
        });

        phoneInput.addEventListener('blur', function () {
            this.value = normalizeIndianMobileDigits(this.value);
        });

        phoneInput.addEventListener('paste', function (e) {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text') || '';
            this.value = normalizeIndianMobileDigits(text);
            this.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
}

// Sidebar Mobile Toggle
function initSidebarToggle() {
    const toggleBtn = document.getElementById('menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');

    function setOpen(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('open', open);
        if (backdrop) {
            backdrop.classList.toggle('show', open);
            backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
        }
        document.body.style.overflow = open ? 'hidden' : '';
    }

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            setOpen(!sidebar.classList.contains('open'));
        });
    }
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setOpen(false);
        });
    }
    // Close drawer after nav tap on mobile
    if (sidebar) {
        sidebar.querySelectorAll('.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.matchMedia('(max-width: 992px)').matches) {
                    setOpen(false);
                }
            });
        });
    }
}

// -------------------------------------------------------------
// Real-Time Freezer ID Generation & Validation
// -------------------------------------------------------------
function initIdGenerator() {
    const typeInput = document.getElementById('freezer_type');
    const brandInput = document.getElementById('company_code');
    const modelInput = document.getElementById('model_number');
    const locInput = document.getElementById('location_code');
    const pinInput = document.getElementById('pincode');
    const dateInput = document.getElementById('installation_date');
    const seqInput = document.getElementById('unit_sequence');

    const previewDisplay = document.getElementById('id-preview-display');
    const statusBadge = document.getElementById('id-status-badge');

    if (!typeInput || !previewDisplay) return;

    let debounceTimer = null;

    function sanitizeModel(m) {
        return (m || 'SFR550DDU').replace(/[^a-zA-Z0-9]/g, '').toUpperCase() || 'GENERIC';
    }

    function extractPinCode(p) {
        const digits = (p || '000000').replace(/\D/g, '');
        return digits.length >= 6 ? digits.slice(0, 6) : digits.padStart(6, '0');
    }

    function formatMmYy(d) {
        if (!d) {
            const now = new Date();
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const yy = String(now.getFullYear()).slice(-2);
            return mm + yy;
        }
        const dt = new Date(d);
        if (isNaN(dt.getTime())) return '0324';
        const mm = String(dt.getMonth() + 1).padStart(2, '0');
        const yy = String(dt.getFullYear()).slice(-2);
        return mm + yy;
    }

    function updatePreview() {
        const type = (typeInput.value || 'DF').toUpperCase();
        const brand = (brandInput.value || 'RW').toUpperCase();
        const model = sanitizeModel(modelInput.value);
        const loc = (locInput.value || 'AMD1').replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
        const pinCode = extractPinCode(pinInput ? pinInput.value : '380058');
        const mmyy = formatMmYy(dateInput ? dateInput.value : '');
        const seqRaw = seqInput && seqInput.value ? seqInput.value.trim().toUpperCase() : '';
        const autoSeq = !seqRaw;
        const seq = seqRaw || 'AUTO';

        // Order: TYPE-BRAND-MODEL-LOC-MMYY-PIN-SEQ
        const candidateId = `${type}-${brand}-${model}-${loc}-${mmyy}-${pinCode}-${seq}`;
        previewDisplay.textContent = candidateId;

        // Update chips if present
        const chipType = document.getElementById('chip-type');
        const chipBrand = document.getElementById('chip-brand');
        const chipModel = document.getElementById('chip-model');
        const chipLoc = document.getElementById('chip-loc');
        const chipPin = document.getElementById('chip-pin');
        const chipMmyy = document.getElementById('chip-mmyy');
        const chipSeq = document.getElementById('chip-seq');

        if (chipType) chipType.textContent = type;
        if (chipBrand) chipBrand.textContent = brand;
        if (chipModel) chipModel.textContent = model;
        if (chipLoc) chipLoc.textContent = loc;
        if (chipPin) chipPin.textContent = pinCode;
        if (chipMmyy) chipMmyy.textContent = mmyy;
        if (chipSeq) chipSeq.textContent = seq;

        // Empty sequence → leave ID blank so PHP auto-generates on save
        const hiddenId = document.getElementById('unique_freezer_id');
        if (hiddenId) {
            hiddenId.value = autoSeq ? '' : candidateId;
        }

        // Debounce server validation / auto-seq suggestion
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            checkIdWithServer(type, brand, model, loc, pinCode, mmyy, seqRaw, autoSeq);
        }, 350);
    }

    function checkIdWithServer(type, brand, model, loc, pinCode, mmyy, seqRaw, autoSeq) {
        if (!statusBadge) return;
        statusBadge.textContent = autoSeq ? 'Auto sequence…' : 'Checking...';
        statusBadge.className = 'id-preview-badge';

        const params = new URLSearchParams({
            type: type,
            brand: brand,
            model: model,
            loc: loc,
            pin: pinCode,
            mmyy: mmyy,
            seq: seqRaw || ''
        });

        // If editing, include exclude_id
        const excludeId = document.getElementById('exclude_freezer_id');
        if (excludeId && excludeId.value) {
            params.append('exclude_id', excludeId.value);
        }

        fetch('api/generate_id.php?' + params.toString())
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    statusBadge.textContent = 'Ready';
                    return;
                }

                const chipSeq = document.getElementById('chip-seq');
                const hiddenId = document.getElementById('unique_freezer_id');

                if (autoSeq) {
                    previewDisplay.textContent = data.suggested_id;
                    if (chipSeq) chipSeq.textContent = data.suggested_seq || 'AUTO';
                    if (hiddenId) hiddenId.value = ''; // server assigns final unique seq on save
                    statusBadge.textContent = 'Auto: ' + (data.suggested_seq || 'next');
                    statusBadge.style.backgroundColor = 'rgba(16, 185, 129, 0.2)';
                    statusBadge.style.color = '#34d399';
                    return;
                }

                if (data.is_duplicate) {
                    statusBadge.textContent = 'Duplicate - Next: ' + data.suggested_id;
                    statusBadge.style.backgroundColor = 'rgba(239, 68, 68, 0.2)';
                    statusBadge.style.color = '#f87171';
                } else {
                    statusBadge.textContent = 'Valid & Unique';
                    statusBadge.style.backgroundColor = 'rgba(16, 185, 129, 0.2)';
                    statusBadge.style.color = '#34d399';
                }
            })
            .catch(() => {
                statusBadge.textContent = 'Ready';
            });
    }

    // Attach listeners
    [typeInput, brandInput, modelInput, locInput, pinInput, dateInput, seqInput].forEach(el => {
        if (el) {
            el.addEventListener('input', updatePreview);
            el.addEventListener('change', updatePreview);
        }
    });

    // Expose preview update globally
    window.updateFreezerIdPreview = updatePreview;

    // Initial run
    updatePreview();
}

// -------------------------------------------------------------
// Geographical Hierarchy Engine (Zone -> City; state auto/hidden)
// -------------------------------------------------------------
function initCascadingLocationSelector() {
    const zoneSelect = document.getElementById('zone');
    const stateInput = document.getElementById('state'); // may be hidden
    const cityInput = document.getElementById('city');
    const cityList = document.getElementById('city-list');
    const pinInput = document.getElementById('pincode');
    const locInput = document.getElementById('location_code');
    const latField = document.getElementById('latitude');
    const lngField = document.getElementById('longitude');

    if (!cityInput) return;

    // Use embedded geo hierarchy or fallback
    const geo = window.GEO_HIERARCHY || {
        'CENTRAL': {
            'Madhya Pradesh': [
                { city: 'Bhopal', code: 'BPL1', prefix: 'BPL', pin: '462001', lat: 23.2599, lng: 77.4126 },
                { city: 'Indore', code: 'IND1', pin: '452001', lat: 22.7196, lng: 75.8577 },
                { city: 'Gwalior', code: 'GWL1', pin: '474001', lat: 26.2183, lng: 78.1828 },
                { city: 'Jabalpur', code: 'JAB1', pin: '482001', lat: 23.1815, lng: 79.9864 },
                { city: 'Ujjain', code: 'UJJ1', pin: '456001', lat: 23.1765, lng: 75.7885 },
                { city: 'Sagar', code: 'SAG1', pin: '470001', lat: 23.8388, lng: 78.7378 },
                { city: 'Ratlam', code: 'RAT1', pin: '457001', lat: 23.3315, lng: 75.0367 },
                { city: 'Rewa', code: 'REW1', pin: '486001', lat: 24.5362, lng: 81.3037 }
            ],
            'Chhattisgarh': [
                { city: 'Raipur', code: 'RAI1', pin: '492001', lat: 21.2514, lng: 81.6296 },
                { city: 'Bilaspur', code: 'BIL1', pin: '495001', lat: 22.0797, lng: 82.1409 },
                { city: 'Durg-Bhilai', code: 'DUR1', pin: '490006', lat: 21.2167, lng: 81.4333 }
            ]
        },
        'WEST': {
            'Gujarat': [
                { city: 'Ahmedabad', code: 'AMD1', pin: '380058', lat: 23.0338, lng: 72.5570 },
                { city: 'Surat', code: 'SUR1', pin: '395001', lat: 21.1702, lng: 72.8311 },
                { city: 'Vadodara', code: 'BRD1', pin: '390001', lat: 22.3072, lng: 73.1812 },
                { city: 'Rajkot', code: 'RAJ1', pin: '360001', lat: 22.3039, lng: 70.8022 },
                { city: 'Gandhinagar', code: 'GND1', pin: '382010', lat: 23.2156, lng: 72.6369 },
                { city: 'Bhavnagar', code: 'BHV1', pin: '364001', lat: 21.7645, lng: 72.1519 }
            ],
            'Maharashtra': [
                { city: 'Mumbai', code: 'MUM1', pin: '400018', lat: 18.9986, lng: 72.8258 },
                { city: 'Pune', code: 'PUN1', pin: '411001', lat: 18.5204, lng: 73.8567 },
                { city: 'Nagpur', code: 'NAG1', pin: '440001', lat: 21.1458, lng: 79.0882 },
                { city: 'Thane', code: 'THA1', pin: '400601', lat: 19.2183, lng: 72.9781 },
                { city: 'Nashik', code: 'NAS1', pin: '422001', lat: 19.9975, lng: 73.7898 },
                { city: 'Aurangabad', code: 'AUR1', pin: '431001', lat: 19.8762, lng: 75.3433 }
            ],
            'Goa': [
                { city: 'Panaji', code: 'GOA1', pin: '403001', lat: 15.4909, lng: 73.8278 },
                { city: 'Margao', code: 'GOA2', pin: '403601', lat: 15.2832, lng: 73.9862 }
            ]
        },
        'NORTH': {
            'Delhi NCR': [
                { city: 'New Delhi', code: 'DEL1', pin: '110001', lat: 28.6139, lng: 77.2090 },
                { city: 'South Delhi', code: 'DEL2', pin: '110017', lat: 28.5355, lng: 77.2100 },
                { city: 'Noida', code: 'NOI1', pin: '201301', lat: 28.5355, lng: 77.3910 },
                { city: 'Gurugram', code: 'GGN1', pin: '122001', lat: 28.4595, lng: 77.0266 }
            ],
            'Rajasthan': [
                { city: 'Jaipur', code: 'JAI1', pin: '302001', lat: 26.9124, lng: 75.7873 },
                { city: 'Jodhpur', code: 'JOD1', pin: '342001', lat: 26.2389, lng: 73.0243 },
                { city: 'Udaipur', code: 'UDA1', pin: '313001', lat: 24.5854, lng: 73.7125 },
                { city: 'Kota', code: 'KOT1', pin: '324001', lat: 25.2138, lng: 75.8648 }
            ],
            'Uttar Pradesh': [
                { city: 'Lucknow', code: 'LKO1', pin: '226001', lat: 26.8467, lng: 80.9462 },
                { city: 'Kanpur', code: 'KNP1', pin: '208001', lat: 26.4499, lng: 80.3319 },
                { city: 'Varanasi', code: 'VAR1', pin: '221001', lat: 25.3176, lng: 82.9739 },
                { city: 'Agra', code: 'AGR1', pin: '282001', lat: 27.1767, lng: 78.0081 }
            ],
            'Punjab': [
                { city: 'Chandigarh', code: 'CHD1', pin: '160001', lat: 30.7333, lng: 76.7794 },
                { city: 'Ludhiana', code: 'LDH1', pin: '141001', lat: 30.9010, lng: 75.8573 },
                { city: 'Amritsar', code: 'ASR1', pin: '143001', lat: 31.6340, lng: 74.8723 }
            ]
        },
        'SOUTH': {
            'Karnataka': [
                { city: 'Bengaluru', code: 'BLR1', pin: '560001', lat: 12.9716, lng: 77.5946 },
                { city: 'Mysuru', code: 'MYS1', pin: '570001', lat: 12.2958, lng: 76.6394 },
                { city: 'Hubballi', code: 'HUB1', pin: '580020', lat: 15.3647, lng: 75.1240 }
            ],
            'Telangana': [
                { city: 'Hyderabad', code: 'HYD1', pin: '500001', lat: 17.3850, lng: 78.4867 },
                { city: 'Warangal', code: 'WAR1', pin: '506002', lat: 17.9689, lng: 79.5941 }
            ],
            'Tamil Nadu': [
                { city: 'Chennai', code: 'CHN1', pin: '600001', lat: 13.0827, lng: 80.2707 },
                { city: 'Coimbatore', code: 'CBE1', pin: '641001', lat: 11.0168, lng: 76.9558 }
            ],
            'Kerala': [
                { city: 'Kochi', code: 'COK1', pin: '682001', lat: 9.9312, lng: 76.2673 },
                { city: 'Thiruvananthapuram', code: 'TRV1', pin: '695001', lat: 8.5241, lng: 76.9366 }
            ]
        },
        'EAST': {
            'West Bengal': [
                { city: 'Kolkata', code: 'KOL1', pin: '700001', lat: 22.5726, lng: 88.3639 },
                { city: 'Howrah', code: 'HOW1', pin: '711101', lat: 22.5958, lng: 88.2636 }
            ],
            'Bihar': [
                { city: 'Patna', code: 'PAT1', pin: '800001', lat: 25.5941, lng: 85.1376 }
            ],
            'Odisha': [
                { city: 'Bhubaneswar', code: 'BBI1', pin: '751001', lat: 20.2961, lng: 85.8245 }
            ],
            'Assam': [
                { city: 'Guwahati', code: 'GAU1', pin: '781001', lat: 26.1445, lng: 91.7362 }
            ]
        }
    };

    function getStates(zoneFilter) {
        let states = [];
        for (const [z, zoneStates] of Object.entries(geo)) {
            if (!zoneFilter || zoneFilter === z) {
                states = states.concat(Object.keys(zoneStates));
            }
        }
        return [...new Set(states)].sort();
    }

    function getCitiesForState(stateName) {
        if (!stateName) return [];
        const norm = stateName.toLowerCase().trim();
        for (const [z, zoneStates] of Object.entries(geo)) {
            for (const [st, cities] of Object.entries(zoneStates)) {
                if (st.toLowerCase() === norm) {
                    return cities;
                }
            }
        }
        return [];
    }

    function findZoneForState(stateName) {
        if (!stateName) return null;
        const norm = stateName.toLowerCase().trim();
        for (const [z, zoneStates] of Object.entries(geo)) {
            for (const st of Object.keys(zoneStates)) {
                if (st.toLowerCase() === norm) {
                    return z;
                }
            }
        }
        return null;
    }

    function getCitiesForZone(zoneFilter) {
        let cities = [];
        for (const [z, zoneStates] of Object.entries(geo)) {
            if (zoneFilter && zoneFilter !== z) continue;
            for (const [st, cityArr] of Object.entries(zoneStates)) {
                cityArr.forEach(c => cities.push({ ...c, state: st, zone: z }));
            }
        }
        cities.sort((a, b) => a.city.localeCompare(b.city));
        return cities;
    }

    function findCityMatch(cityName, preferredZone) {
        const needle = cityName.toLowerCase().trim();
        if (!needle) return null;
        const zoneCities = preferredZone ? getCitiesForZone(preferredZone) : [];
        let match = zoneCities.find(c => c.city.toLowerCase() === needle);
        if (match) return match;
        return getCitiesForZone('').find(c => c.city.toLowerCase() === needle) || null;
    }

    function populateCitySuggestions(zoneFilter) {
        if (!cityList) return;
        cityList.innerHTML = '';
        getCitiesForZone(zoneFilter || (zoneSelect ? zoneSelect.value : '')).forEach(c => {
            const prefix = resolveCityPrefix(c.city, c);
            const opt = document.createElement('option');
            opt.value = c.city;
            opt.label = `${c.city} (${prefix}1/2/3) - PIN: ${c.pin}`;
            cityList.appendChild(opt);
        });
    }

    /** City → AMD / BPL / MUM / PUN… then codes AMD1, AMD2, AMD3 */
    function resolveCityPrefix(cityName, match) {
        const map = window.CITY_LOC_PREFIXES || {};
        const needle = String(cityName || '').trim();
        if (needle && map[needle]) return String(map[needle]).toUpperCase();
        // Case-insensitive lookup
        const key = Object.keys(map).find(k => k.toLowerCase() === needle.toLowerCase());
        if (key) return String(map[key]).toUpperCase();
        if (match && match.prefix) return String(match.prefix).toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3);
        if (match && match.code) return String(match.code).toUpperCase().replace(/\d+$/, '').replace(/[^A-Z]/g, '').slice(0, 3);
        const fallback = needle.replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 3);
        return fallback || 'LOC';
    }

    function locationCodesForPrefix(prefix) {
        const p = String(prefix || 'LOC').toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3) || 'LOC';
        return [1, 2, 3].map(n => p + n);
    }

    function populateLocationCodes(prefix, preferredCode) {
        if (!locInput) return;
        const codes = locationCodesForPrefix(prefix);
        const keep = preferredCode ? String(preferredCode).toUpperCase() : '';
        const isSelect = locInput.tagName === 'SELECT';

        if (isSelect) {
            const prev = (locInput.value || keep || '').toUpperCase();
            locInput.innerHTML = '';
            codes.forEach((code) => {
                const opt = document.createElement('option');
                opt.value = code;
                opt.textContent = code;
                locInput.appendChild(opt);
            });
            if (keep && codes.includes(keep)) {
                locInput.value = keep;
            } else if (prev && codes.includes(prev)) {
                locInput.value = prev;
            } else {
                locInput.value = codes[0];
            }
            // Keep a non-standard current code visible (edit edge-case)
            if (keep && !codes.includes(keep)) {
                const extra = document.createElement('option');
                extra.value = keep;
                extra.textContent = keep + ' (current)';
                extra.selected = true;
                locInput.appendChild(extra);
            }
        } else {
            locInput.value = (keep && /^[A-Z]{2,4}\d+$/.test(keep)) ? keep : codes[0];
            locInput.setAttribute('list', 'location-code-list');
            let list = document.getElementById('location-code-list');
            if (!list) {
                list = document.createElement('datalist');
                list.id = 'location-code-list';
                locInput.parentNode.appendChild(list);
            }
            list.innerHTML = '';
            codes.forEach(code => {
                const opt = document.createElement('option');
                opt.value = code;
                list.appendChild(opt);
            });
        }

        locInput.dispatchEvent(new Event('change', { bubbles: true }));
        locInput.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // 1. Zone → city list
    if (zoneSelect) {
        zoneSelect.addEventListener('change', function () {
            const chosenZone = this.value;
            populateCitySuggestions(chosenZone);
            if (cityInput.value) {
                const match = findCityMatch(cityInput.value, chosenZone);
                if (!match || match.zone !== chosenZone) {
                    cityInput.value = '';
                    if (stateInput) stateInput.value = '';
                } else {
                    onCityChanged();
                }
            }
        });
    }

    // 2. City → auto state (hidden) + PIN + location codes (PREFIX1/2/3) + map
    function onCityChanged() {
        const cityName = cityInput.value.trim();
        if (!cityName) return;

        const preferredZone = zoneSelect ? zoneSelect.value : '';
        const match = findCityMatch(cityName, preferredZone);
        const prefix = resolveCityPrefix(cityName, match);

        if (match) {
            if (stateInput) stateInput.value = match.state;
            if (zoneSelect && match.zone) zoneSelect.value = match.zone;

            populateLocationCodes(prefix, match.code || (prefix + '1'));

            // Never overwrite GPS/photo location (PIN, lat/lng, map) with city-center defaults
            const locFromGps = window.__locationSource === 'geocode'
                || window.__locationSource === 'gps'
                || window.__pincodeSource === 'geocode'
                || window.__pincodeSource === 'gps';

            if (pinInput && !locFromGps && !String(pinInput.value || '').trim()) {
                pinInput.value = match.pin;
                window.__pincodeSource = 'city';
                pinInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            // Keep live Store Front GPS — do not jump map/coords to city center
            if (match.lat && match.lng && !locFromGps) {
                if (latField) latField.value = match.lat.toFixed(6);
                if (lngField) lngField.value = match.lng.toFixed(6);

                const dispLat = document.getElementById('disp-lat');
                const dispLng = document.getElementById('disp-lng');
                const dispAcc = document.getElementById('disp-acc');
                if (dispLat) dispLat.textContent = match.lat.toFixed(6);
                if (dispLng) dispLng.textContent = match.lng.toFixed(6);
                if (dispAcc) dispAcc.textContent = '± City Center Reference';

                if (window.leafletMap && window.leafletMarker) {
                    const newLatLng = new L.LatLng(match.lat, match.lng);
                    window.leafletMarker.setLatLng(newLatLng);
                    window.leafletMap.panTo(newLatLng);
                }
            }

            if (typeof window.updateFreezerIdPreview === 'function') {
                window.updateFreezerIdPreview();
            }
        } else if (cityName.length >= 3) {
            populateLocationCodes(prefix, prefix + '1');
            if (typeof window.updateFreezerIdPreview === 'function') {
                window.updateFreezerIdPreview();
            }
        }
    }

    cityInput.addEventListener('input', onCityChanged);
    cityInput.addEventListener('change', onCityChanged);

    // Initial city suggestions by zone + sync location codes if city already filled
    populateCitySuggestions(zoneSelect ? zoneSelect.value : '');
    if (cityInput.value.trim()) {
        onCityChanged();
    }
}

// -------------------------------------------------------------
// Mobile GPS Geolocation Capture
// -------------------------------------------------------------
function initGpsCapture() {
    const captureBtn = document.getElementById('btn-capture-gps');
    const statusPill = document.getElementById('gps-status-pill');

    async function resolvePlace(lat, lng, accuracyMeters) {
        applyGpsToForm(lat, lng, accuracyMeters, 'Finding place name…');
        try {
            const data = await reverseGeocodeAndFill(lat, lng);
            const place = formatPlaceLabel(data);
            applyGpsToForm(lat, lng, accuracyMeters, place || data.display_name || 'Location captured');
            if (statusPill) {
                statusPill.innerHTML = '✓ Place detected';
                statusPill.style.color = '#15803d';
                statusPill.style.backgroundColor = '#dcfce7';
            }
        } catch (e) {
            console.warn(e);
            applyGpsToForm(lat, lng, accuracyMeters, 'GPS OK — address lookup failed. Fill address manually.');
            if (statusPill) {
                statusPill.innerHTML = '✓ GPS (±' + Number(accuracyMeters).toFixed(0) + 'm)';
                statusPill.style.color = '#15803d';
                statusPill.style.backgroundColor = '#dcfce7';
            }
        }
    }

    // Used by Store Front photo EXIF (no separate GPS button needed)
    window.resolveGpsPlace = resolvePlace;

    if (!captureBtn) return;

    captureBtn.addEventListener('click', async function () {
        const allowed = await ensureLocationPermission();
        if (!allowed) return;

        if (statusPill) {
            statusPill.innerHTML = 'Getting location…';
            statusPill.style.color = '#0284c7';
            statusPill.style.backgroundColor = '#e0f2fe';
        }
        setGpsPlaceText('Detecting your place…');
        captureBtn.disabled = true;
        const originalHtml = captureBtn.innerHTML;
        captureBtn.innerHTML = 'Getting place…';

        navigator.geolocation.getCurrentPosition(
            async function (position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const acc = position.coords.accuracy;

                await resolvePlace(lat, lng, acc);

                captureBtn.disabled = false;
                captureBtn.innerHTML = 'Update GPS Location';
            },
            async function (error) {
                captureBtn.disabled = false;
                captureBtn.innerHTML = originalHtml || 'Capture GPS Location Now';
                const msg = geoErrorMessage(error);
                setGpsPlaceText(msg);
                if (statusPill) {
                    statusPill.innerHTML = '⚠️ Failed';
                    statusPill.style.color = '#dc2626';
                    statusPill.style.backgroundColor = '#fee2e2';
                }
                await showPermissionPopup({
                    mode: 'denied',
                    title: 'Location needed',
                    body: msg,
                    steps: error && error.code === 1 ? [
                        'Tap the lock / info icon in the address bar',
                        'Set Location to Allow',
                        'Reload and tap Capture GPS again'
                    ] : [
                        'Turn on Location / GPS on your phone',
                        'Try again outdoors for a stronger signal'
                    ],
                    allowLabel: 'Try again',
                    cancelLabel: 'Close'
                });
            },
            {
                enableHighAccuracy: true,
                timeout: 20000,
                maximumAge: 0
            }
        );
    });
}

// -------------------------------------------------------------
// Camera-only capture (no gallery) + Previews / Retake / Remove
// -------------------------------------------------------------
let activeCameraStream = null;

function stopActiveCameraStream() {
    if (activeCameraStream) {
        activeCameraStream.getTracks().forEach(t => t.stop());
        activeCameraStream = null;
    }
}

function assignFileToInput(input, file) {
    try {
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    } catch (err) {
        console.warn('Could not assign captured file', err);
        return false;
    }
}

// -------------------------------------------------------------
// GPS Map Camera stamp (overlay on captured photos)
// -------------------------------------------------------------
async function reverseGeocodeOnly(lat, lng) {
    const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&addressdetails=1&zoom=18`;
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('Geocode failed');
    return res.json();
}

function formatGpsStampHeader(address) {
    const a = address || {};
    const city = a.city || a.town || a.village || a.municipality || a.county || '';
    const state = a.state || '';
    const country = a.country || 'India';
    return [city, state, country].filter(Boolean).join(', ') || 'Location';
}

/** Short street line for stamp — full Nominatim display_name is too long for panel. */
function formatGpsStampAddress(data) {
    if (!data) return '';
    const a = data.address || {};
    const parts = [
        a.house_number,
        a.road || a.pedestrian || a.footway || a.path,
        a.neighbourhood || a.suburb || a.quarter || a.residential,
        a.city || a.town || a.village || a.municipality,
        a.postcode
    ].filter(Boolean);
    if (parts.length) return parts.join(', ');
    if (data.display_name) {
        return String(data.display_name).split(',').slice(0, 4).join(',').trim();
    }
    return formatPlaceLabel(data);
}

function formatGpsStampTimestamp(date) {
    const d = date || new Date();
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const day = days[d.getDay()];
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yyyy = d.getFullYear();
    let h = d.getHours();
    const min = String(d.getMinutes()).padStart(2, '0');
    const sec = String(d.getSeconds()).padStart(2, '0');
    const ampm = h >= 12 ? 'pm' : 'am';
    h = h % 12;
    if (h === 0) h = 12;
    const hh = String(h).padStart(2, '0');
    const offsetMin = -d.getTimezoneOffset();
    const sign = offsetMin >= 0 ? '+' : '-';
    const abs = Math.abs(offsetMin);
    const oh = String(Math.floor(abs / 60)).padStart(2, '0');
    const om = String(abs % 60).padStart(2, '0');
    // Compact so it fits inside the stamp panel on phone photos
    return `${day} ${dd}/${mm}/${yyyy}  ${hh}:${min}:${sec} ${ampm}  GMT${sign}${oh}:${om}`;
}

function canvasFitText(ctx, text, maxWidth) {
    let t = String(text || '');
    if (!t) return '';
    if (ctx.measureText(t).width <= maxWidth) return t;
    while (t.length > 4 && ctx.measureText(t + '…').width > maxWidth) {
        t = t.slice(0, -1);
    }
    return t + '…';
}

function canvasWrapText(ctx, text, x, y, maxWidth, lineHeight, maxLines) {
    const words = String(text || '').split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';
    for (let i = 0; i < words.length; i++) {
        const test = line ? (line + ' ' + words[i]) : words[i];
        if (ctx.measureText(test).width > maxWidth && line) {
            lines.push(line);
            line = words[i];
            if (lines.length >= maxLines) {
                line = '';
                break;
            }
        } else {
            line = test;
        }
    }
    if (line && lines.length < maxLines) lines.push(line);
    if (lines.length === maxLines && lines.length) {
        lines[maxLines - 1] = canvasFitText(ctx, lines[maxLines - 1], maxWidth);
    }
    lines.forEach((ln, i) => ctx.fillText(ln, x, y + i * lineHeight));
    return lines.length || 1;
}

function loadImageFromUrl(url) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Map thumb failed'));
        img.src = url;
    });
}

async function loadImageFromBlob(blob) {
    // Honor EXIF orientation so stamp sits correctly on phone photos
    if (typeof createImageBitmap === 'function') {
        try {
            return await createImageBitmap(blob, { imageOrientation: 'from-image' });
        } catch (e) {
            try {
                return await createImageBitmap(blob);
            } catch (e2) { /* fall through */ }
        }
    }
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(blob);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Image load failed'));
        };
        img.src = url;
    });
}

/**
 * Stamps GPS Map Camera overlay (map + place + lat/lng + time) onto a photo blob.
 * Font sizes are capped for high-res phone cameras so text stays inside the panel.
 */
async function stampGpsOverlayOnBlob(blob, lat, lng) {
    if (!blob || typeof lat !== 'number' || typeof lng !== 'number') {
        return blob;
    }

    let geo = null;
    try {
        geo = await reverseGeocodeOnly(lat, lng);
    } catch (e) {
        console.warn('GPS stamp geocode failed', e);
    }

    const headerRaw = formatGpsStampHeader(geo && geo.address) + ' 🇮🇳';
    const address = formatGpsStampAddress(geo) || 'Address unavailable';
    const coords = `Lat ${Number(lat).toFixed(6)}°   Long ${Number(lng).toFixed(6)}°`;
    const when = formatGpsStampTimestamp(new Date());

    let mapImg = null;
    try {
        mapImg = await loadImageFromUrl(
            'api/map_thumb.php?lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng) + '&t=' + Date.now()
        );
    } catch (e) {
        console.warn('GPS stamp map thumb failed', e);
    }

    const photo = await loadImageFromBlob(blob);
    const w = photo.width || photo.naturalWidth;
    const h = photo.height || photo.naturalHeight;
    if (!w || !h) return blob;

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(photo, 0, 0, w, h);

    // Larger stamp for readability — still capped so 12MP phones don't explode fonts
    const ref = Math.min(w, h);
    const s = Math.max(0.75, Math.min(1.75, ref / 900));
    const pad = Math.round(18 * s);
    const titleSize = Math.round(30 * s);
    const bodySize = Math.round(22 * s);
    const noteSize = Math.round(16 * s);
    const lineGap = Math.round(7 * s);
    const radius = Math.round(14 * s);

    const panelW = w - pad * 2;
    const mapSize = Math.round(Math.min(panelW * 0.32, 240 * s));
    const textGap = Math.round(16 * s);
    const textX = pad + Math.round(pad * 0.55) + mapSize + textGap;
    const textMaxW = Math.max(100, panelW - (textX - pad) - pad);

    // Measure content height so location + datetime always fit inside panel
    ctx.font = `700 ${titleSize}px system-ui, -apple-system, Segoe UI, sans-serif`;
    const headerLines = 1;
    ctx.font = `500 ${bodySize}px system-ui, -apple-system, Segoe UI, sans-serif`;
    // rough wrap count for address (2 lines max)
    const addrProbe = String(address).split(/\s+/);
    let probeLine = '';
    let addrLineCount = 1;
    for (let i = 0; i < addrProbe.length; i++) {
        const t = probeLine ? probeLine + ' ' + addrProbe[i] : addrProbe[i];
        if (ctx.measureText(t).width > textMaxW && probeLine) {
            addrLineCount++;
            probeLine = addrProbe[i];
            if (addrLineCount >= 2) break;
        } else {
            probeLine = t;
        }
    }
    addrLineCount = Math.min(2, Math.max(1, addrLineCount));

    const contentH =
        pad +
        headerLines * titleSize + lineGap +
        addrLineCount * (bodySize + 3) + lineGap +
        bodySize + lineGap + // coords
        bodySize + lineGap + // datetime
        noteSize +
        pad;

    const panelH = Math.max(
        mapSize + pad * 1.6,
        contentH,
        Math.round(160 * s)
    );
    const maxPanelH = Math.round(h * 0.42);
    const finalPanelH = Math.min(panelH, maxPanelH);
    const panelX = pad;
    const panelY = h - finalPanelH - pad;

    // Panel background
    ctx.save();
    ctx.fillStyle = 'rgba(0, 0, 0, 0.78)';
    roundRectPath(ctx, panelX, panelY, panelW, finalPanelH, radius);
    ctx.fill();

    // Clip all stamp content to panel so nothing spills
    roundRectPath(ctx, panelX, panelY, panelW, finalPanelH, radius);
    ctx.clip();

    // Map thumb
    const mapX = panelX + Math.round(pad * 0.55);
    const mapY = panelY + Math.round((finalPanelH - mapSize) / 2);
    const mapR = Math.max(6, Math.round(radius * 0.65));
    ctx.save();
    roundRectPath(ctx, mapX, mapY, mapSize, mapSize, mapR);
    ctx.clip();
    if (mapImg) {
        ctx.drawImage(mapImg, mapX, mapY, mapSize, mapSize);
    } else {
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(mapX, mapY, mapSize, mapSize);
        ctx.fillStyle = '#ef4444';
        ctx.beginPath();
        ctx.arc(mapX + mapSize / 2, mapY + mapSize / 2, mapSize * 0.08, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.restore();

    // Text — reserved bottom rows for coords + datetime so they never get pushed out
    ctx.textBaseline = 'top';
    const textBottomReserve = bodySize + lineGap + bodySize + lineGap + noteSize + pad * 0.4;
    let ty = panelY + Math.round(pad * 0.7);
    const textBottomLimit = panelY + finalPanelH - textBottomReserve;

    // Brand (top-right, above content)
    ctx.font = `600 ${noteSize}px system-ui, -apple-system, Segoe UI, sans-serif`;
    const brand = 'GPS Map Camera';
    const brandW = ctx.measureText(brand).width;
    ctx.fillStyle = 'rgba(255,255,255,0.8)';
    ctx.fillText(brand, panelX + panelW - pad - brandW, panelY + Math.round(pad * 0.45));

    // City / state header (leave room for brand)
    ctx.fillStyle = '#ffffff';
    ctx.font = `700 ${titleSize}px system-ui, -apple-system, Segoe UI, sans-serif`;
    const headerMaxW = Math.max(60, textMaxW - brandW - Math.round(8 * s));
    ctx.fillText(canvasFitText(ctx, headerRaw, headerMaxW), textX, ty);
    ty += titleSize + lineGap;

    // Address (max 2 lines, clipped before datetime zone)
    if (ty < textBottomLimit) {
        ctx.fillStyle = 'rgba(255,255,255,0.92)';
        ctx.font = `500 ${bodySize}px system-ui, -apple-system, Segoe UI, sans-serif`;
        const maxAddrLines = Math.max(1, Math.min(2, Math.floor((textBottomLimit - ty) / (bodySize + 3))));
        const used = canvasWrapText(ctx, address, textX, ty, textMaxW, bodySize + 3, maxAddrLines);
        ty += used * (bodySize + 3) + lineGap;
    }

    // Lat / Long — always inside panel
    const coordsY = Math.min(Math.max(ty, panelY + pad), panelY + finalPanelH - (bodySize + lineGap + bodySize + noteSize + pad * 0.5));
    ctx.fillStyle = '#ffffff';
    ctx.font = `600 ${bodySize}px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace`;
    ctx.fillText(canvasFitText(ctx, coords, textMaxW), textX, coordsY);

    // Date / time — always inside panel
    const whenY = coordsY + bodySize + lineGap;
    ctx.fillStyle = 'rgba(255,255,255,0.95)';
    ctx.font = `600 ${bodySize}px system-ui, -apple-system, Segoe UI, sans-serif`;
    ctx.fillText(canvasFitText(ctx, when, textMaxW), textX, whenY);

    // Note footer
    const noteY = Math.min(whenY + bodySize + lineGap, panelY + finalPanelH - noteSize - Math.round(pad * 0.35));
    ctx.fillStyle = 'rgba(255,255,255,0.7)';
    ctx.font = `500 ${noteSize}px system-ui, -apple-system, Segoe UI, sans-serif`;
    ctx.fillText(canvasFitText(ctx, 'Note : Captured by GPS Map Camera', textMaxW), textX, noteY);

    ctx.restore();

    if (typeof photo.close === 'function') {
        try { photo.close(); } catch (e) { /* ImageBitmap */ }
    }

    return await new Promise((resolve) => {
        canvas.toBlob(function (outBlob) {
            resolve(outBlob || blob);
        }, 'image/jpeg', 0.92);
    });
}

function roundRectPath(ctx, x, y, w, h, r) {
    const radius = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.arcTo(x + w, y, x + w, y + h, radius);
    ctx.arcTo(x + w, y + h, x, y + h, radius);
    ctx.arcTo(x, y + h, x, y, radius);
    ctx.arcTo(x, y, x + w, y, radius);
    ctx.closePath();
}

/**
 * Capture GPS → stamp overlay → assign file to input.
 */
async function finalizeCapturedPhoto(input, rawBlob, gps) {
    let blob = rawBlob;
    let usedGps = gps;

    if (!usedGps || typeof usedGps.lat !== 'number') {
        try {
            const pos = await getDevicePosition();
            usedGps = {
                lat: pos.coords.latitude,
                lng: pos.coords.longitude,
                acc: pos.coords.accuracy
            };
            window.__captureGps = usedGps;
        } catch (e) {
            console.warn('GPS unavailable for stamp', e);
        }
    }

    if (usedGps && typeof usedGps.lat === 'number') {
        try {
            blob = await stampGpsOverlayOnBlob(rawBlob, usedGps.lat, usedGps.lng);
        } catch (e) {
            console.warn('GPS stamp failed, using raw photo', e);
            blob = rawBlob;
        }
    }

    const file = new File([blob], 'gps_cam_' + Date.now() + '.jpg', { type: 'image/jpeg' });
    assignFileToInput(input, file);
    return { file, gps: usedGps };
}

function forceNativeCameraInput(input) {
    // Temporary capture-only input — avoids gallery chooser when possible
    const tmp = document.createElement('input');
    tmp.type = 'file';
    tmp.accept = 'image/*';
    tmp.setAttribute('capture', 'environment');
    tmp.style.display = 'none';
    document.body.appendChild(tmp);
    tmp.addEventListener('change', async function () {
        const file = tmp.files && tmp.files[0];
        tmp.remove();
        if (!file) return;
        try {
            let gps = window.__captureGps || null;
            if (!gps) {
                try {
                    const pos = await getDevicePosition();
                    gps = { lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy };
                    window.__captureGps = gps;
                } catch (e) { /* optional */ }
            }
            await finalizeCapturedPhoto(input, file, gps);
        } catch (e) {
            console.warn('Native capture stamp failed', e);
            assignFileToInput(input, file);
        }
    });
    tmp.addEventListener('cancel', function () { tmp.remove(); });
    tmp.click();
}

function ensureCameraModal() {
    let modal = document.getElementById('camera-capture-modal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'camera-capture-modal';
    modal.className = 'camera-capture-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
        <div class="camera-capture-sheet" role="dialog" aria-modal="true" aria-label="Camera capture">
            <div class="camera-capture-top">
                <span class="camera-capture-title">Camera</span>
                <button type="button" class="camera-capture-close" aria-label="Close">&times;</button>
            </div>
            <video class="camera-capture-video" playsinline autoplay muted></video>
            <canvas class="camera-capture-canvas" hidden></canvas>
            <p class="camera-capture-hint">Rear camera · GPS Map Camera stamp on capture</p>
            <div class="camera-capture-actions">
                <button type="button" class="btn btn-secondary camera-capture-cancel">Cancel</button>
                <button type="button" class="btn btn-success camera-capture-shutter">Capture</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    return modal;
}

async function openLiveCameraCapture(input) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        throw new Error('getUserMedia unavailable');
    }

    const modal = ensureCameraModal();
    const video = modal.querySelector('.camera-capture-video');
    const canvas = modal.querySelector('.camera-capture-canvas');
    const closeBtn = modal.querySelector('.camera-capture-close');
    const cancelBtn = modal.querySelector('.camera-capture-cancel');
    const shutterBtn = modal.querySelector('.camera-capture-shutter');

    stopActiveCameraStream();

    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1920 },
                height: { ideal: 1080 }
            }
        });
    } catch (rearErr) {
        const denied = rearErr && (rearErr.name === 'NotAllowedError' || rearErr.name === 'PermissionDeniedError');
        if (denied) {
            throw rearErr;
        }
        try {
            // Fallback: any camera (still no gallery)
            stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: true
            });
        } catch (anyErr) {
            throw anyErr;
        }
    }
    activeCameraStream = stream;
    video.srcObject = stream;
    await video.play().catch(() => {});

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');

    return new Promise((resolve) => {
        const cleanup = () => {
            stopActiveCameraStream();
            video.srcObject = null;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            closeBtn.onclick = null;
            cancelBtn.onclick = null;
            shutterBtn.onclick = null;
        };

        const cancel = () => {
            cleanup();
            resolve(false);
        };

        closeBtn.onclick = cancel;
        cancelBtn.onclick = cancel;

        shutterBtn.onclick = async () => {
            shutterBtn.disabled = true;
            const originalLabel = shutterBtn.textContent;
            shutterBtn.textContent = 'Getting GPS…';

            // All in-app captures: grab GPS for Map Camera stamp
            try {
                const pos = await getDevicePosition();
                window.__captureGps = {
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    acc: pos.coords.accuracy
                };
            } catch (gpsErr) {
                console.warn('Capture-time GPS failed', gpsErr);
                if (!window.__captureGps) window.__captureGps = null;
            }

            const w = video.videoWidth || 1280;
            const h = video.videoHeight || 720;
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, w, h);

            shutterBtn.textContent = 'Stamping GPS…';
            canvas.toBlob(async function (blob) {
                try {
                    if (!blob) {
                        cleanup();
                        shutterBtn.disabled = false;
                        shutterBtn.textContent = originalLabel;
                        resolve(false);
                        return;
                    }
                    await finalizeCapturedPhoto(input, blob, window.__captureGps);
                    cleanup();
                    shutterBtn.disabled = false;
                    shutterBtn.textContent = originalLabel;
                    resolve(true);
                } catch (err) {
                    console.warn(err);
                    cleanup();
                    shutterBtn.disabled = false;
                    shutterBtn.textContent = originalLabel;
                    resolve(false);
                }
            }, 'image/jpeg', 0.92);
        };
    });
}

/** Pick existing photo from device gallery (must run inside a user click). */
function openGalleryForInput(input) {
    if (!input) return;
    const tmp = document.createElement('input');
    tmp.type = 'file';
    tmp.accept = 'image/jpeg,image/png,image/webp,image/*';
    // No capture attribute → opens gallery / file picker
    tmp.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0;width:1px;height:1px;';
    document.body.appendChild(tmp);
    tmp.addEventListener('change', function () {
        const file = tmp.files && tmp.files[0];
        setTimeout(function () { try { tmp.remove(); } catch (e) { /* ignore */ } }, 0);
        if (!file) return;
        assignFileToInput(input, file);
    }, { once: true });
    tmp.click();
}

/**
 * Show Capture / Open Gallery sheet. Gallery opens in the same click
 * (browsers block file-picker after async await).
 */
function choosePhotoForInput(input) {
    if (!input) return;

    let modal = document.getElementById('photo-source-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'photo-source-modal';
        modal.className = 'photo-source-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="photo-source-sheet" role="dialog" aria-modal="true" aria-labelledby="photo-source-title">
                <h3 id="photo-source-title">Add photo</h3>
                <p>Choose Capture (camera) or Open Gallery</p>
                <div class="photo-source-actions">
                    <button type="button" class="btn btn-primary" id="photo-source-camera">Capture</button>
                    <button type="button" class="btn btn-secondary" id="photo-source-gallery">Open Gallery</button>
                    <button type="button" class="btn btn-outline" id="photo-source-cancel">Cancel</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    const cam = modal.querySelector('#photo-source-camera');
    const gal = modal.querySelector('#photo-source-gallery');
    const cancel = modal.querySelector('#photo-source-cancel');

    const closeChooser = function () {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        cam.onclick = null;
        gal.onclick = null;
        cancel.onclick = null;
        modal.onclick = null;
    };

    cam.onclick = function (e) {
        e.preventDefault();
        e.stopPropagation();
        closeChooser();
        openCameraForInput(input);
    };
    gal.onclick = function (e) {
        e.preventDefault();
        e.stopPropagation();
        closeChooser();
        // Must stay synchronous with this click — do not await
        openGalleryForInput(input);
    };
    cancel.onclick = function (e) {
        e.preventDefault();
        e.stopPropagation();
        closeChooser();
    };
    modal.onclick = function (e) {
        if (e.target === modal) closeChooser();
    };

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
}

async function openCameraForInput(input) {
    if (!input) return;
    input.setAttribute('capture', 'environment');
    input.setAttribute('accept', 'image/*');

    // Explain + request Location then Camera (browser popups follow Allow taps)
    const perms = await ensureCameraAndLocationPermissions();
    if (!perms.camera) return;

    // Warm up GPS while camera opens (stamp + Store Front auto-fill)
    if (perms.location && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                window.__captureGps = {
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    acc: pos.coords.accuracy
                };
            },
            async function (geoErr) {
                console.warn('GPS warm-up failed', geoErr);
                if (geoErr && geoErr.code === 1) {
                    await showPermissionPopup({
                        mode: 'denied',
                        title: 'Allow Location',
                        body: geoErrorMessage(geoErr),
                        steps: [
                            'Tap the lock / info icon in the address bar',
                            'Set Location to Allow',
                            'Reload, then retake the photo'
                        ],
                        allowLabel: 'OK',
                        cancelLabel: 'Close'
                    });
                }
            },
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 10000 }
        );
    }

    // Prefer in-app rear camera (no gallery). Fallback to native capture.
    try {
        const ok = await openLiveCameraCapture(input);
        if (ok) return;
        // User cancelled live camera — do not open gallery
        return;
    } catch (err) {
        console.warn('Live camera unavailable', err);
        const denied = err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError');
        if (denied) {
            await showPermissionPopup({
                mode: 'denied',
                title: 'Allow Camera',
                body: cameraErrorMessage(err),
                steps: [
                    'Tap the lock / info icon in the address bar',
                    'Set Camera to Allow',
                    'Reload the page, then capture again'
                ],
                allowLabel: 'Try again',
                cancelLabel: 'Cancel'
            });
            return;
        }
        // Device / browser cannot use getUserMedia — try native capture intent
        forceNativeCameraInput(input);
    }
}

function clearImagePreview(previewContainer, input) {
    if (!previewContainer || !input) return;
    input.value = '';
    const img = previewContainer.querySelector('img');
    if (img) {
        img.removeAttribute('src');
        img.src = '';
    }
    previewContainer.style.display = 'none';
    const parentBox = input.closest('.upload-box');
    if (parentBox) {
        parentBox.classList.remove('has-preview');
        parentBox.classList.remove('is-invalid');
    }
    if (input.name === 'image_store_front') {
        const status = document.getElementById('store-front-exif-status');
        if (status) {
            status.style.display = 'none';
            status.textContent = '';
        }
        if (typeof setStoreFrontLoading === 'function') {
            setStoreFrontLoading(false);
        }
        window.__pincodeSource = null;
        window.__locationSource = null;
    }
}

function ensurePreviewControls(previewContainer, input) {
    if (!previewContainer) return;

    if (!previewContainer.querySelector('.upload-remove-btn')) {
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'upload-remove-btn';
        removeBtn.setAttribute('aria-label', 'Remove photo');
        removeBtn.title = 'Remove photo';
        removeBtn.innerHTML = '&times;';
        removeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            clearImagePreview(previewContainer, input);
        });
        previewContainer.appendChild(removeBtn);
    }

    if (!previewContainer.querySelector('.upload-retake-btn')) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'upload-retake-btn';
        btn.innerHTML = '<span aria-hidden="true">↻</span> Retake';
        btn.title = 'Capture or open gallery';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            choosePhotoForInput(input);
        });
        previewContainer.appendChild(btn);
    }
}

function initImageUploadPreviews() {
    const inputs = document.querySelectorAll('.upload-input');
    inputs.forEach(input => {
        const targetId = input.getAttribute('data-preview-target');
        const previewContainer = targetId ? document.getElementById(targetId) : null;
        const box = input.closest('.upload-box');

        // Camera only — block direct gallery file-picker on the hidden input
        input.setAttribute('capture', 'environment');
        input.setAttribute('accept', 'image/*');
        input.classList.add('upload-input-camera-only');

        if (box) {
            box.style.cursor = 'pointer';
            box.addEventListener('click', function (e) {
                if (e.target.closest('.upload-retake-btn, .upload-remove-btn, .upload-loading-overlay')) return;
                if (box.classList.contains('is-loading')) return;
                // Existing preview: use Retake / Remove only
                if (box.classList.contains('has-preview')) return;
                e.preventDefault();
                e.stopPropagation();
                try {
                    choosePhotoForInput(input);
                } catch (err) {
                    console.warn('Photo chooser failed, opening camera', err);
                    openCameraForInput(input);
                }
            });
        }

        // Block native input activation (gallery)
        input.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
        });

        if (previewContainer) {
            const img = previewContainer.querySelector('img');
            const hasSrc = img && img.getAttribute('src') && img.getAttribute('src') !== '';
            const visible = previewContainer.style.display !== 'none' && hasSrc;
            if (visible) {
                ensurePreviewControls(previewContainer, input);
                if (box) box.classList.add('has-preview');
            }
        }

        input.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            if (file.size > 10 * 1024 * 1024) {
                alert('File size exceeds 10MB limit.');
                this.value = '';
                return;
            }

            const prev = previewContainer || document.getElementById(this.getAttribute('data-preview-target'));
            if (!prev) return;

            const img = prev.querySelector('img');
            const reader = new FileReader();

            reader.onload = function (e) {
                img.src = e.target.result;
                prev.style.display = 'block';
                ensurePreviewControls(prev, input);
                const parentBox = input.closest('.upload-box');
                if (parentBox) parentBox.classList.add('has-preview');
            };

            reader.readAsDataURL(file);
        });
    });
}

// -------------------------------------------------------------
// Store Front photo → EXIF GPS + date → auto-fill location fields
// -------------------------------------------------------------
function setExifStatus(message, type) {
    const el = document.getElementById('store-front-exif-status');
    if (!el) return;
    el.style.display = 'block';
    el.textContent = message;
    el.className = 'store-front-exif-status';
    if (type === 'ok') el.classList.add('is-ok');
    if (type === 'warn') el.classList.add('is-warn');
    if (type === 'err') el.classList.add('is-err');
}

function ensureStoreFrontLoadingOverlay() {
    const box = document.getElementById('dropzone-store-front')
        || document.querySelector('input[name="image_store_front"]')?.closest('.upload-box');
    if (!box) return null;
    let overlay = box.querySelector('.upload-loading-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'upload-loading-overlay';
        overlay.innerHTML = `
            <div class="upload-loading-spinner" aria-hidden="true"></div>
            <div class="upload-loading-text">Reading photo…</div>
            <div class="upload-loading-sub">Location, PIN &amp; details</div>
        `;
        box.appendChild(overlay);
    }
    return { box, overlay };
}

function setStoreFrontLoading(on, message, sub) {
    const refs = ensureStoreFrontLoadingOverlay();
    if (!refs) return;
    const { box, overlay } = refs;
    const textEl = overlay.querySelector('.upload-loading-text');
    const subEl = overlay.querySelector('.upload-loading-sub');
    if (textEl && message) textEl.textContent = message;
    if (subEl) {
        subEl.textContent = sub || 'Location, address, city, PIN, zone & date';
        subEl.style.display = sub === '' ? 'none' : '';
    }
    box.classList.toggle('is-loading', !!on);
    overlay.setAttribute('aria-busy', on ? 'true' : 'false');
    overlay.setAttribute('aria-hidden', on ? 'false' : 'true');

    // Disable retake/remove while fetching
    box.querySelectorAll('.upload-retake-btn, .upload-remove-btn').forEach(btn => {
        btn.disabled = !!on;
    });
}

function setGpsPlaceText(text) {
    const el = document.getElementById('disp-place');
    if (el) el.textContent = text || 'Location unavailable';
}

function applyGpsToForm(lat, lng, accuracyLabel, placeText) {
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const accInput = document.getElementById('gps_accuracy');
    const dispLat = document.getElementById('disp-lat');
    const dispLng = document.getElementById('disp-lng');
    const dispAcc = document.getElementById('disp-acc');
    const pill = document.getElementById('gps-status-pill');

    // Lock so city autofill cannot overwrite Store Front GPS
    window.__locationSource = 'gps';
    window.__pincodeSource = window.__pincodeSource === 'geocode' ? 'geocode' : 'gps';

    if (latInput) latInput.value = Number(lat).toFixed(6);
    if (lngInput) lngInput.value = Number(lng).toFixed(6);
    if (accInput) accInput.value = typeof accuracyLabel === 'number' ? accuracyLabel : (accuracyLabel || '');
    if (dispLat) dispLat.textContent = Number(lat).toFixed(5);
    if (dispLng) dispLng.textContent = Number(lng).toFixed(5);
    if (dispAcc) {
        dispAcc.textContent = typeof accuracyLabel === 'number'
            ? ('± ' + Number(accuracyLabel).toFixed(0) + ' m')
            : (accuracyLabel || '—');
    }
    if (placeText) setGpsPlaceText(placeText);

    if (pill) {
        pill.innerHTML = placeText ? '✓ Location found' : '✓ GPS Captured';
        pill.style.backgroundColor = '#dcfce7';
        pill.style.color = '#15803d';
    }

    if (window.leafletMap && window.leafletMarker) {
        const ll = L.latLng(lat, lng);
        window.leafletMarker.setLatLng(ll);
        window.leafletMap.setView(ll, 16);
    }
}

function findZoneForStateName(stateName) {
    const geo = window.GEO_HIERARCHY;
    if (!geo || !stateName) return null;
    const needle = String(stateName).toLowerCase();
    for (const zone of Object.keys(geo)) {
        for (const state of Object.keys(geo[zone])) {
            if (state.toLowerCase() === needle || state.toLowerCase().includes(needle) || needle.includes(state.toLowerCase())) {
                return { zone, state };
            }
        }
    }
    return null;
}

function extractIndianPincode(address, displayName) {
    // Prefer structured postcode from Nominatim
    let pin = String((address && address.postcode) || '').replace(/\D/g, '');
    if (pin.length > 6) pin = pin.slice(0, 6);
    if (/^[1-9]\d{5}$/.test(pin)) return pin;

    // Fallback: 6-digit Indian PIN inside display_name / address text
    const blob = [
        displayName || '',
        address && address.postcode,
        address && address.suburb,
        address && address.neighbourhood
    ].filter(Boolean).join(' ');
    const m = String(blob).match(/\b([1-9]\d{5})\b/);
    return m ? m[1] : '';
}

function fillLocationFromAddress(address, displayName) {
    if (!address) return;

    const roadBits = [
        address.house_number,
        address.road,
        address.pedestrian,
        address.neighbourhood,
        address.suburb,
        address.residential
    ].filter(Boolean).join(', ');

    const city = address.city
        || address.town
        || address.village
        || address.municipality
        || address.city_district
        || address.county
        || address.state_district
        || '';
    const state = address.state || '';
    const pin = extractIndianPincode(address, displayName);

    const addressEl = document.getElementById('address');
    const cityEl = document.getElementById('city');
    const stateEl = document.getElementById('state');
    const pinEl = document.getElementById('pincode');
    const zoneEl = document.getElementById('zone');

    // Lock location + PIN so city master cannot overwrite photo GPS / address
    window.__locationSource = 'geocode';
    window.__pincodeSource = 'geocode';

    // Set PIN first (before city) so city autofill cannot replace GPS PIN
    if (pinEl && /^[1-9]\d{5}$/.test(pin)) {
        pinEl.value = pin;
        pinEl.dispatchEvent(new Event('input', { bubbles: true }));
    }

    if (addressEl && (roadBits || displayName)) {
        addressEl.value = roadBits || displayName;
        addressEl.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (stateEl && state) {
        stateEl.value = state;
        stateEl.dispatchEvent(new Event('input', { bubbles: true }));
        stateEl.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (cityEl && city) {
        cityEl.value = city;
        cityEl.dispatchEvent(new Event('input', { bubbles: true }));
        cityEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Re-apply geocode PIN + address after city handlers (city master must not win)
    if (pinEl && /^[1-9]\d{5}$/.test(pin)) {
        pinEl.value = pin;
        window.__pincodeSource = 'geocode';
        window.__locationSource = 'geocode';
        pinEl.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (addressEl && (roadBits || displayName)) {
        addressEl.value = roadBits || displayName;
    }

    const matched = findZoneForStateName(state);
    if (zoneEl && matched) {
        zoneEl.value = matched.zone;
        zoneEl.dispatchEvent(new Event('change', { bubbles: true }));
        if (stateEl && matched.state) {
            stateEl.value = matched.state;
            stateEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // Zone/city handlers may run again — restore geocode fields
    window.__locationSource = 'geocode';
    window.__pincodeSource = 'geocode';
    if (pinEl && /^[1-9]\d{5}$/.test(pin)) {
        pinEl.value = pin;
    }
    if (addressEl && (roadBits || displayName)) {
        addressEl.value = roadBits || displayName;
    }
}

function formatPlaceLabel(data) {
    if (!data) return '';
    const a = data.address || {};
    const short = [
        a.road || a.pedestrian || a.neighbourhood || a.suburb,
        a.city || a.town || a.village || a.county,
        a.state,
        a.postcode
    ].filter(Boolean).join(', ');
    return short || data.display_name || '';
}

async function reverseGeocodeAndFill(lat, lng) {
    // zoom=18 for street-level postcode; fallback zoom=16 if PIN missing
    const fetchRev = async (zoom) => {
        const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&addressdetails=1&zoom=${zoom}`;
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('Geocode failed (' + res.status + ')');
        return res.json();
    };

    let data = await fetchRev(18);
    let pin = extractIndianPincode(data.address || {}, data.display_name || '');
    if (!/^[1-9]\d{5}$/.test(pin)) {
        try {
            const wider = await fetchRev(16);
            const pin2 = extractIndianPincode(wider.address || {}, wider.display_name || '');
            if (/^[1-9]\d{5}$/.test(pin2)) {
                data = wider;
                pin = pin2;
            }
        } catch (e) {
            console.warn('PIN fallback geocode failed', e);
        }
    }

    fillLocationFromAddress(data.address || {}, data.display_name || '');
    const place = formatPlaceLabel(data);
    if (place) setGpsPlaceText(place);
    return data;
}

function setInstallDateValue(dateObj) {
    const dateEl = document.getElementById('installation_date');
    if (!dateEl || !dateObj || Number.isNaN(dateObj.getTime())) return false;
    const yyyy = dateObj.getFullYear();
    const mm = String(dateObj.getMonth() + 1).padStart(2, '0');
    const dd = String(dateObj.getDate()).padStart(2, '0');
    dateEl.value = `${yyyy}-${mm}-${dd}`;
    dateEl.dispatchEvent(new Event('change', { bubbles: true }));
    dateEl.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
}

function fillInstallDateFromExif(exif) {
    const raw = exif && (exif.DateTimeOriginal || exif.CreateDate || exif.ModifyDate);
    if (!raw) return false;

    let d = null;
    if (raw instanceof Date) {
        d = raw;
    } else if (typeof raw === 'string') {
        const normalized = raw.replace(/^(\d{4}):(\d{2}):(\d{2})/, '$1-$2-$3');
        d = new Date(normalized);
    }
    return setInstallDateValue(d);
}

function fillInstallDateToday() {
    return setInstallDateValue(new Date());
}

function getDevicePosition() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation not supported'));
            return;
        }
        navigator.geolocation.getCurrentPosition(resolve, reject, {
            enableHighAccuracy: true,
            timeout: 25000,
            maximumAge: 15000
        });
    });
}

async function fillPlaceFromCoords(lat, lng, accuracyLabel) {
    if (typeof window.resolveGpsPlace === 'function') {
        await window.resolveGpsPlace(lat, lng, accuracyLabel);
        return;
    }
    applyGpsToForm(lat, lng, accuracyLabel);
    await reverseGeocodeAndFill(lat, lng);
}

async function fillLocationFromDeviceGps() {
    setStoreFrontLoading(true, 'Getting live GPS…', 'Phone location (camera photos have no EXIF GPS)');
    try {
        const pos = await getDevicePosition();
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        const acc = pos.coords.accuracy;
        setStoreFrontLoading(true, 'Finding place…', 'Address, city, PIN & zone');
        await fillPlaceFromCoords(lat, lng, acc);
        return { lat, lng, acc };
    } catch (geoErr) {
        setStoreFrontLoading(false);
        await showPermissionPopup({
            mode: 'denied',
            title: 'Location needed',
            body: geoErrorMessage(geoErr),
            steps: [
                'Allow Location when the browser asks',
                'Or enable Location in site settings (lock icon)',
                'Retake Store Front photo after allowing'
            ],
            allowLabel: 'OK',
            cancelLabel: 'Close'
        });
        throw geoErr;
    }
}

function initStoreFrontExifAutofill() {
    const input = document.querySelector('input[name="image_store_front"]');
    if (!input) return;

    const pinEl = document.getElementById('pincode');
    if (pinEl && !pinEl.dataset.pinSourceBound) {
        pinEl.dataset.pinSourceBound = '1';
        pinEl.addEventListener('input', function () {
            // User typed PIN manually — allow it to stick
            if (document.activeElement === pinEl) {
                window.__pincodeSource = 'manual';
            }
        });
    }

    input.addEventListener('change', async function () {
        const file = this.files && this.files[0];
        if (!file) return;

        const box = document.getElementById('dropzone-store-front');
        if (box) box.classList.remove('is-invalid');

        setStoreFrontLoading(true, 'Reading photo…', 'GPS, date & location details');
        setExifStatus('Reading location from photo / phone GPS…', 'warn');

        let dateFilled = false;

        try {
            let gps = null;
            let exif = null;

            if (typeof exifr !== 'undefined') {
                setStoreFrontLoading(true, 'Extracting GPS from photo…', 'EXIF metadata');
                try {
                    gps = await exifr.gps(file);
                    exif = await exifr.parse(file, [
                        'DateTimeOriginal', 'CreateDate', 'ModifyDate',
                        'GPSLatitude', 'GPSLongitude'
                    ]);
                } catch (exifErr) {
                    console.warn('EXIF read failed', exifErr);
                }
                dateFilled = fillInstallDateFromExif(exif || {});
            }

            // Live/in-app camera JPEGs have no EXIF GPS — use pending capture GPS or phone GPS
            const pending = window.__captureGps;
            if ((!gps || typeof gps.latitude !== 'number') && pending && typeof pending.lat === 'number') {
                gps = { latitude: pending.lat, longitude: pending.lng };
            }
            window.__captureGps = null;

            if (gps && typeof gps.latitude === 'number' && typeof gps.longitude === 'number') {
                setStoreFrontLoading(true, 'Finding place…', 'Address, city, PIN & zone');
                await fillPlaceFromCoords(
                    gps.latitude,
                    gps.longitude,
                    pending && pending.acc != null ? pending.acc : 'From photo'
                );
            } else {
                // Always fall back to device GPS so fields still auto-fill
                try {
                    await fillLocationFromDeviceGps();
                } catch (geoErr) {
                    console.warn(geoErr);
                    if (!dateFilled) fillInstallDateToday();
                    setExifStatus(
                        'Location permission needed. Allow Location, then Retake Store Front photo.',
                        'err'
                    );
                    return;
                }
            }

            if (!dateFilled) {
                fillInstallDateToday();
            }

            setStoreFrontLoading(true, 'Filling details…', 'Almost done');
            setExifStatus(
                'Location details have been auto-filled. Please review them carefully and cross-check with the store representative before saving.',
                'ok'
            );

            setStoreFrontLoading(false);

            await showPermissionPopup({
                mode: 'ask',
                title: 'Please verify store details',
                body: 'Processing is complete. Kindly review all auto-filled information carefully. If any detail appears incorrect, please update it before continuing.',
                steps: [
                    'Confirm address, city, PIN code, zone and GPS location',
                    'Cross-check with the store representative where possible',
                    'Correct any inaccurate fields before saving the profile'
                ],
                allowLabel: 'Got it — Review details',
                cancelLabel: 'Close'
            });

            const anchor = document.getElementById('store-details-anchor') || document.getElementById('address');
            if (anchor) {
                setTimeout(() => anchor.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
            }
        } catch (err) {
            console.warn('Store front auto-fill failed', err);
            setExifStatus('Could not auto-fill location. Allow Location & Retake, or fill manually.', 'err');
        } finally {
            setStoreFrontLoading(false);
        }
    });
}

// -------------------------------------------------------------
// Image Gallery Lightbox (prev / next / download)
// -------------------------------------------------------------
function initLightbox() {
    const images = Array.from(document.querySelectorAll('.lightbox-trigger'));
    if (!images.length) return;

    let currentIndex = 0;

    let modal = document.getElementById('lightbox-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'lightbox-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', 'Photo gallery');
        modal.style.cssText = `
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.92);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            cursor: default;
        `;
        modal.innerHTML = `
            <button type="button" id="lightbox-prev" aria-label="Previous photo" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);width:48px;height:48px;border-radius:999px;border:none;background:rgba(255,255,255,0.12);color:#fff;font-size:1.75rem;cursor:pointer;z-index:2;line-height:1;">&#8249;</button>
            <button type="button" id="lightbox-next" aria-label="Next photo" style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);width:48px;height:48px;border-radius:999px;border:none;background:rgba(255,255,255,0.12);color:#fff;font-size:1.75rem;cursor:pointer;z-index:2;line-height:1;">&#8250;</button>
            <div id="lightbox-panel" style="position: relative; max-width: 90vw; max-height: 90vh; text-align: center;">
                <button type="button" id="lightbox-close" aria-label="Close" style="position: absolute; top: -44px; right: 0; background: none; border: none; color: white; font-size: 2rem; cursor: pointer; line-height: 1;">&times;</button>
                <div id="lightbox-counter" style="position:absolute;top:-40px;left:0;color:#cbd5e1;font-size:0.85rem;font-weight:600;"></div>
                <img id="lightbox-img" src="" alt="" style="max-width: 100%; max-height: 78vh; border-radius: 8px; box-shadow: 0 0 30px rgba(0,0,0,0.8); display: block; margin: auto;">
                <p id="lightbox-caption" style="color: #ffffff; text-align: center; margin: 0.75rem 0 0.65rem; font-weight: 600; font-size: 0.95rem;"></p>
                <a id="lightbox-download" href="#" download style="display: inline-flex; align-items: center; gap: 0.4rem; background: #0284c7; color: #fff; text-decoration: none; font-weight: 700; font-size: 0.85rem; padding: 0.55rem 1rem; border-radius: 8px; border: none; cursor: pointer;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Download image
                </a>
            </div>
        `;
        document.body.appendChild(modal);

        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeGallery();
        });
        const panel = document.getElementById('lightbox-panel');
        if (panel) {
            panel.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }
        const closeBtn = document.getElementById('lightbox-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                closeGallery();
            });
        }
        const prevBtn = document.getElementById('lightbox-prev');
        const nextBtn = document.getElementById('lightbox-next');
        if (prevBtn) {
            prevBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                showGalleryAt(currentIndex - 1);
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                showGalleryAt(currentIndex + 1);
            });
        }
        document.addEventListener('keydown', function (e) {
            if (!modal || modal.style.display !== 'flex') return;
            if (e.key === 'Escape') closeGallery();
            if (e.key === 'ArrowLeft') showGalleryAt(currentIndex - 1);
            if (e.key === 'ArrowRight') showGalleryAt(currentIndex + 1);
        });
    }

    const lightboxImg = document.getElementById('lightbox-img');
    const lightboxCap = document.getElementById('lightbox-caption');
    const lightboxDl = document.getElementById('lightbox-download');
    const lightboxCounter = document.getElementById('lightbox-counter');
    const prevBtn = document.getElementById('lightbox-prev');
    const nextBtn = document.getElementById('lightbox-next');

    function lightboxFilename(src, caption) {
        try {
            const path = (src || '').split('?')[0];
            const base = path.substring(path.lastIndexOf('/') + 1) || 'photo.jpg';
            if (/\.(jpe?g|png|webp|gif|svg)$/i.test(base)) return base;
        } catch (e) { /* ignore */ }
        const safe = String(caption || 'photo').replace(/[^\w\-]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60);
        return (safe || 'photo') + '.jpg';
    }

    function closeGallery() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    function showGalleryAt(index) {
        if (!images.length) return;
        currentIndex = ((index % images.length) + images.length) % images.length;
        const el = images[currentIndex];
        const src = el.currentSrc || el.src;
        const caption = el.getAttribute('data-caption') || el.alt || '';
        lightboxImg.src = src;
        lightboxCap.textContent = caption;
        if (lightboxCounter) {
            lightboxCounter.textContent = (currentIndex + 1) + ' / ' + images.length;
        }
        if (lightboxDl) {
            lightboxDl.href = src;
            lightboxDl.setAttribute('download', lightboxFilename(src, caption));
        }
        const multi = images.length > 1;
        if (prevBtn) prevBtn.style.display = multi ? 'block' : 'none';
        if (nextBtn) nextBtn.style.display = multi ? 'block' : 'none';
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    async function downloadLightboxImage(e) {
        e.preventDefault();
        e.stopPropagation();
        const src = lightboxImg && lightboxImg.src;
        if (!src) return;
        const filename = lightboxFilename(src, lightboxCap ? lightboxCap.textContent : '');
        try {
            const res = await fetch(src, { credentials: 'same-origin' });
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } catch (err) {
            const a = document.createElement('a');
            a.href = src;
            a.download = filename;
            a.target = '_blank';
            a.rel = 'noopener';
            document.body.appendChild(a);
            a.click();
            a.remove();
        }
    }

    if (lightboxDl && !lightboxDl.dataset.bound) {
        lightboxDl.dataset.bound = '1';
        lightboxDl.addEventListener('click', downloadLightboxImage);
    }

    images.forEach(function (img, idx) {
        img.style.cursor = 'zoom-in';
        if (img.dataset.galleryBound) return;
        img.dataset.galleryBound = '1';
        img.addEventListener('click', function (e) {
            e.stopPropagation();
            showGalleryAt(idx);
        });
    });
}
