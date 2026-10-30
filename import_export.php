<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\ImportExportService;

AuthService::requireRole(['super_admin', 'sub_admin']);
$currentUser = AuthService::currentUser();

$error = null;
$success = null;
$importResult = null;
$csrfToken = AuthService::generateCsrfToken();

// Downloads (GET)
if (!empty($_GET['action'])) {
    $action = $_GET['action'];
    if ($action === 'template') {
        ImportExportService::streamTemplate();
        exit;
    }
    if ($action === 'template_csv') {
        ImportExportService::streamTemplateCsv();
        exit;
    }
    if ($action === 'template_store') {
        ImportExportService::streamStoreTemplate();
        exit;
    }
    if ($action === 'template_freezer') {
        ImportExportService::streamFreezerTemplate();
        exit;
    }
    if ($action === 'template_iot') {
        ImportExportService::streamIotTemplate();
        exit;
    }
    if ($action === 'export') {
        ImportExportService::streamExport();
        exit;
    }
    if ($action === 'export_csv') {
        ImportExportService::streamExportCsv();
        exit;
    }
}

// Import upload (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security CSRF token. Please try again.';
    } elseif (empty($_FILES['hub_csv']['tmp_name'])) {
        $error = 'Please choose a CSV file to import.';
    } else {
        $file = $_FILES['hub_csv'];
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error = 'Only .csv files are allowed.';
        } elseif (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'File upload failed. Please try again.';
        } else {
            try {
                $importResult = ImportExportService::importHubCsv($file['tmp_name'], (int)$currentUser['id']);
                if (($importResult['created'] ?? 0) > 0) {
                    $success = "Import finished: {$importResult['created']} profile(s) created"
                        . (($importResult['skipped'] ?? 0) > 0 ? ", {$importResult['skipped']} skipped." : '.');
                } else {
                    $error = 'No profiles were created. Check CSV columns and row data.';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Import / Export';
require_once __DIR__ . '/includes/header.php';
?>

<div class="users-page">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="font-size: 1.4rem; font-weight: 800; color: #0f172a;">Import / Export Hub</h2>
            <p style="color: #64748b; font-size: 0.875rem; margin-top: 0.25rem;">
                Admin tools to bulk import Store + Freezer + IoT from one CSV, or export existing hub data.
            </p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 1.25rem;">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"/>
                    <rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/>
                    <rect x="3" y="14" width="7" height="7"/>
                </svg>
                <span>Hub stickers (PDF)</span>
            </div>
        </div>
        <div class="card-body">
            <p style="font-size: 0.875rem; color: #475569; margin-bottom: 1rem;">
                Open all freezer asset stickers in one print view. Use browser <strong>Print → Save as PDF</strong> to download every sticker (e.g. 150 freezers) in a single PDF.
            </p>
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                <a href="qr_print_bulk.php" class="btn btn-primary" target="_blank">Open all stickers</a>
                <a href="qr_print_bulk.php?status=Active" class="btn btn-secondary" target="_blank">Active only</a>
            </div>
        </div>
    </div>

    <div class="form-grid" style="gap: 1.25rem; align-items: stretch;">
        <!-- Export -->
        <div class="card col-6" style="margin: 0;">
            <div class="card-header">
                <div class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    <span>Export</span>
                </div>
            </div>
            <div class="card-body">
                <p style="font-size: 0.875rem; color: #475569; margin-bottom: 1rem;">
                    Export = full details (full name + short code + GPS/QR/IoT). Same header colors:
                    <span style="background:#93C5FD;color:#1E3A8A;padding:0.1rem 0.4rem;border-radius:4px;font-weight:700;font-size:0.75rem;">STORE</span>
                    <span style="background:#FCD34D;color:#78350F;padding:0.1rem 0.4rem;border-radius:4px;font-weight:700;font-size:0.75rem;">FREEZER</span>
                    <span style="background:#86EFAC;color:#14532D;padding:0.1rem 0.4rem;border-radius:4px;font-weight:700;font-size:0.75rem;">IOT</span>
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                    <a href="import_export.php?action=export" class="btn btn-primary">
                        Export all data (.xlsx)
                    </a>
                    <a href="import_export.php?action=export_csv" class="btn btn-secondary">
                        Export all data (CSV)
                    </a>
                    <a href="import_export.php?action=template" class="btn btn-secondary">
                        Download hub Excel (.xlsx)
                    </a>
                    <a href="import_export.php?action=template_csv" class="btn btn-secondary">
                        Download hub CSV
                    </a>
                </div>
            </div>
        </div>

        <!-- Import -->
        <div class="card col-6" style="margin: 0;">
            <div class="card-header">
                <div class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <span>Import</span>
                </div>
            </div>
            <div class="card-body">
                <p style="font-size: 0.875rem; color: #475569; margin-bottom: 1rem;">
                    Upload a CSV to create Store + Freezer profiles. Blank <code>unique_freezer_id</code> / <code>outlet_id</code> are auto-generated. Matching <code>outlet_id</code> reuses that store.
                </p>
                <form method="post" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 0.85rem;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="import">
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label required" for="hub_csv">CSV file</label>
                        <input type="file" id="hub_csv" name="hub_csv" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <button type="submit" class="btn btn-success" style="align-self: flex-start;">
                        Import Store &amp; Freezer data
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 1.25rem;">
        <div class="card-header">
            <div class="card-title"><span>Download templates (Store / Freezer / IoT)</span></div>
        </div>
        <div class="card-body">
            <p style="font-size: 0.875rem; color: #475569; margin-bottom: 1rem;">
                Separate sheets optional —
                prefer <strong>combined hub v3</strong> (Store + Freezer + IoT together).
                Filter order <strong>Zone → State → City → PIN → location_code</strong>.
                Files: <code>uploads/import/</code> · <strong>download again</strong> (old files outdated).
            </p>
            <div class="form-grid" style="gap: 1rem;">
                <div class="col-4" style="background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; padding:1rem;">
                    <div style="font-weight:800; color:#1e3a8a; margin-bottom:0.35rem;">Store template</div>
                    <p style="font-size:0.8rem; color:#64748b; margin:0 0 0.5rem;">
                        Store master + photos:
                    </p>
                    <p style="font-size:0.75rem; color:#0f172a; margin:0 0 0.85rem; line-height:1.45;">
                        <code>image_store_front</code><br>
                        <code>image_aadhar_card</code> (front)<br>
                        <code>image_aadhar_back</code>
                    </p>
                    <a href="import_export.php?action=template_store" class="btn btn-secondary btn-sm">Download Store Excel</a>
                </div>
                <div class="col-4" style="background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; padding:1rem;">
                    <div style="font-weight:800; color:#78350f; margin-bottom:0.35rem;">Freezer template</div>
                    <p style="font-size:0.8rem; color:#64748b; margin:0 0 0.5rem;">
                        Freezer ID parts + photos:
                    </p>
                    <p style="font-size:0.75rem; color:#0f172a; margin:0 0 0.85rem; line-height:1.45;">
                        <code>image_left_side</code><br>
                        <code>image_right_side</code>
                    </p>
                    <a href="import_export.php?action=template_freezer" class="btn btn-secondary btn-sm">Download Freezer Excel</a>
                </div>
                <div class="col-4" style="background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:1rem;">
                    <div style="font-weight:800; color:#14532d; margin-bottom:0.35rem;">IoT template</div>
                    <p style="font-size:0.8rem; color:#64748b; margin:0 0 0.5rem;">
                        Device link + photo:
                    </p>
                    <p style="font-size:0.75rem; color:#0f172a; margin:0 0 0.85rem; line-height:1.45;">
                        <code>image_iot_device</code>
                    </p>
                    <a href="import_export.php?action=template_iot" class="btn btn-secondary btn-sm">Download IoT Excel</a>
                </div>
            </div>
            <p style="font-size:0.8rem; color:#64748b; margin:1rem 0 0;">
                <strong>Combined hub v3</strong> (Export card above) = Store + Freezer + IoT in one file:
                photos + <code>iot_serial</code>, <code>iot_imei</code>, <code>iot_type</code>, <code>iot_owner</code>, <code>iot_model</code>.
                Blank <code>iot_serial</code> = no IoT device on that row.
            </p>
        </div>
    </div>

    <?php if ($importResult): ?>
        <div class="card" style="margin-top: 1.25rem;">
            <div class="card-header">
                <div class="card-title"><span>Last import summary</span></div>
            </div>
            <div class="card-body" style="font-size: 0.875rem;">
                <div style="display: flex; flex-wrap: wrap; gap: 1.25rem; margin-bottom: 0.75rem;">
                    <div><span style="color:#64748b;">Created</span> <strong style="color:#15803d;"><?= (int)$importResult['created'] ?></strong></div>
                    <div><span style="color:#64748b;">Skipped</span> <strong style="color:#b45309;"><?= (int)$importResult['skipped'] ?></strong></div>
                </div>
                <?php if (!empty($importResult['errors'])): ?>
                    <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:0.75rem 1rem;">
                        <strong style="color:#9a3412;">Row errors</strong>
                        <ul style="margin:0.5rem 0 0; padding-left:1.1rem; color:#9a3412;">
                            <?php foreach ($importResult['errors'] as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-top: 1.25rem;">
        <div class="card-header">
            <div class="card-title"><span>CSV column guide</span></div>
        </div>
        <div class="card-body" style="font-size: 0.8rem; color: #475569;">
            <p style="margin-bottom: 0.75rem;">Required columns are marked *. Leave optional ID fields blank to auto-generate.</p>
            <div style="overflow-x: auto;">
                <table class="data-table" style="font-size: 0.78rem;">
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>store_name</code> *</td><td>Store / outlet name</td></tr>
                        <tr><td><code>address</code> *</td><td>Street address</td></tr>
                        <tr><td><code>city</code> *</td><td>City</td></tr>
                        <tr><td><code>freezer_type</code> *</td><td>Full name · e.g. <code>Deep Freezer</code> (system → DF in Unique ID)</td></tr>
                        <tr><td><code>company</code> *</td><td>Full name · e.g. <code>Rockwell</code> (system → RW in Unique ID)</td></tr>
                        <tr><td><code>model_number</code> *</td><td>ID seq 3 · model in Freezer ID</td></tr>
                        <tr><td><code>location_code</code></td><td>ID seq 4 · blank = auto from city (Ahmedabad → AMD1)</td></tr>
                        <tr><td><code>pincode</code> *</td><td>ID seq 5 · 6-digit PIN</td></tr>
                        <tr><td><code>installation_date</code></td><td>ID seq 6 · YYYY-MM-DD → MMYY</td></tr>
                        <tr><td><code>unit_sequence</code></td><td>ID seq 7 · e.g. AAA001, AAA002 (order / stickers)</td></tr>
                        <tr><td><code>unique_freezer_id</code></td><td>Blank = auto from sequence above + QR</td></tr>
                        <tr><td><code>outlet_id</code></td><td>Blank = auto · existing outlet reuses store</td></tr>
                        <tr><td><code>fssai_number</code> / <code>gst_number</code></td><td>Optional compliance IDs</td></tr>
                        <tr><td><code>phone_number</code></td><td>10-digit Indian mobile</td></tr>
                        <tr><td><code>image_store_front</code></td><td>Store photo filename in <code>uploads/import/</code></td></tr>
                        <tr><td><code>image_aadhar_card</code></td><td>Aadhaar front photo filename in <code>uploads/import/</code></td></tr>
                        <tr><td><code>image_aadhar_back</code></td><td>Aadhaar back photo filename</td></tr>
                        <tr><td><code>image_left_side</code> / <code>image_right_side</code></td><td>Freezer photos — same folder, exact filename</td></tr>
                        <tr><td><code>iot_serial</code></td><td>Optional · e.g. <code>RF-IOT-99210</code> (blank = no IoT)</td></tr>
                        <tr><td><code>iot_imei</code></td><td>Optional IMEI / MAC</td></tr>
                        <tr><td><code>iot_type</code></td><td>Default <code>Temperature &amp; GPS</code></td></tr>
                        <tr><td><code>iot_owner</code></td><td><code>ReeferON Own Device</code> / <code>Outsourced Device</code></td></tr>
                        <tr><td><code>iot_model</code></td><td>Default <code>RF-T100</code></td></tr>
                        <tr><td><code>image_iot_device</code></td><td>IoT photo filename (optional)</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 1.25rem;">
        <div class="card-header">
            <div class="card-title"><span>How images link to Store / Freezer</span></div>
        </div>
        <div class="card-body" style="font-size: 0.875rem; color: #475569;">
            <ol style="margin: 0 0 0.85rem 1.1rem; padding: 0; line-height: 1.55;">
                <li>Copy photos into folder <code>uploads/import/</code> on the server.</li>
                <li>In the CSV row, put the <strong>exact file name</strong> (not full path):
                    <ul style="margin: 0.35rem 0 0 1rem;">
                        <li><code>image_store_front</code> → that store’s front photo</li>
                        <li><code>image_aadhar_card</code> → Aadhaar front</li>
                        <li><code>image_aadhar_back</code> → Aadhaar back</li>
                        <li><code>image_left_side</code> / <code>image_right_side</code> → that freezer’s photos</li>
                        <li><code>image_iot_device</code> → that IoT device photo</li>
                    </ul>
                </li>
                <li>Import the CSV — each row attaches only the files named on that row (same store/freezer as that row’s data).</li>
            </ol>
            <p style="margin: 0; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:0.75rem; font-size:0.8rem;">
                Example: row with <code>unit_sequence=AAA001</code> and
                <code>image_left_side=freezer_AAA001_left.jpg</code> → photo must be
                <code>uploads/import/freezer_AAA001_left.jpg</code>. Blank image cells = no photo for that row.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
