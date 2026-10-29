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
    if ($action === 'export') {
        ImportExportService::streamExport();
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
                Admin tools to bulk import Store + Freezer profiles from CSV, or export existing hub data.
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
                    Download all Store + Freezer hub records as CSV (same columns as the import template).
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                    <a href="import_export.php?action=export" class="btn btn-primary">
                        Export all data (CSV)
                    </a>
                    <a href="import_export.php?action=template" class="btn btn-secondary">
                        Download blank template
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
                        <tr><td><code>pincode</code> *</td><td>6-digit PIN (used in Freezer ID)</td></tr>
                        <tr><td><code>freezer_type</code> *</td><td>e.g. DF / DCF / UDF</td></tr>
                        <tr><td><code>company_code</code> *</td><td>Brand code e.g. RW</td></tr>
                        <tr><td><code>model_number</code> *</td><td>Model used in Freezer ID</td></tr>
                        <tr><td><code>location_code</code> *</td><td>e.g. AMD1, MUM2</td></tr>
                        <tr><td><code>outlet_id</code></td><td>Blank = auto · existing outlet reuses store</td></tr>
                        <tr><td><code>unique_freezer_id</code></td><td>Blank = auto-generate + QR</td></tr>
                        <tr><td><code>fssai_number</code> / <code>gst_number</code></td><td>Optional compliance IDs</td></tr>
                        <tr><td><code>phone_number</code></td><td>10-digit Indian mobile</td></tr>
                        <tr><td><code>installation_date</code></td><td>YYYY-MM-DD (default today)</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
