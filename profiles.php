<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\MasterData;

AuthService::requireLogin();
$currentUser = AuthService::currentUser();

// Search & Filter parameters
$search = $_GET['q'] ?? '';
$type = $_GET['type'] ?? '';
$brand = $_GET['brand'] ?? '';
$status = $_GET['status'] ?? '';
$city = $_GET['city'] ?? '';
$missing = $_GET['missing'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$filters = [
    'q' => $search,
    'type' => $type,
    'brand' => $brand,
    'status' => $status,
    'city' => $city,
    'missing' => $missing,
];

// Handle CSV export
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    $allRecords = Freezer::getFiltered($filters, 2000, 0);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=freezer_profiles_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Freezer ID', 'Store Name', 'Outlet ID', 'Type', 'Brand', 'Model', 'Capacity (L)', 'Condition', 'Location Code', 'PIN Code', 'Install Date', 'City', 'State', 'GPS Lat', 'GPS Lng', 'Status']);
    foreach ($allRecords as $r) {
        fputcsv($output, [
            $r['unique_freezer_id'],
            $r['store_name'],
            $r['outlet_id'],
            $r['freezer_type'],
            $r['company_code'],
            $r['model_number'],
            $r['capacity_litres'],
            $r['freezer_condition'],
            $r['location_code'],
            $r['pincode_last3'],
            $r['installation_date'],
            $r['store_city'],
            $r['store_state'],
            $r['store_latitude'],
            $r['store_longitude'],
            $r['status'],
        ]);
    }
    fclose($output);
    exit;
}

$freezers = Freezer::getFiltered($filters, $limit, $offset);
$totalCount = Freezer::countFiltered($filters);
$totalPages = ceil($totalCount / $limit);

$freezerTypes = MasterData::getFreezerTypes();
$brands = MasterData::getBrands();

$pageTitle = 'Freezer & Store Profiles';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2 style="font-size: 1.4rem; font-weight: 800; color: #0f172a;">Freezer & Store Profiles Directory</h2>
        <p style="color: #64748b; font-size: 0.875rem;">Search and manage deployed units, view QR tags, and inspect GPS coordinates.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="qr_print_bulk.php?<?= http_build_query(array_filter(['q' => $search, 'city' => $city, 'status' => $status])) ?>" class="btn btn-outline" target="_blank">
            Print all stickers (PDF)
        </a>
        <a href="profiles.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <span>Export CSV</span>
        </a>
        <a href="profile_add.php" class="btn btn-primary">
            + Add New Profile
        </a>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body" style="padding: 1rem 1.25rem;">
        <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <div style="flex: 2; min-width: 220px;">
                <label class="form-label" style="font-size: 0.75rem;">Search Keyword</label>
                <input type="text" name="q" class="form-control" placeholder="Search Freezer ID, Store Name, Model, City..." value="<?= htmlspecialchars($search) ?>">
            </div>

            <div style="flex: 1; min-width: 130px;">
                <label class="form-label" style="font-size: 0.75rem;">Freezer Type</label>
                <select name="type" class="form-select">
                    <option value="">All Types</option>
                    <?php foreach ($freezerTypes as $ft): ?>
                        <option value="<?= htmlspecialchars($ft['code']) ?>" <?= $type === $ft['code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ft['code']) ?> (<?= htmlspecialchars($ft['name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 1; min-width: 120px;">
                <label class="form-label" style="font-size: 0.75rem;">Brand / OEM</label>
                <select name="brand" class="form-select">
                    <option value="">All Brands</option>
                    <?php foreach ($brands as $b): ?>
                        <option value="<?= htmlspecialchars($b['code']) ?>" <?= $brand === $b['code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['code']) ?> - <?= htmlspecialchars($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 1; min-width: 110px;">
                <label class="form-label" style="font-size: 0.75rem;">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Maintenance" <?= $status === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    <option value="Under Inspection" <?= $status === 'Under Inspection' ? 'selected' : '' ?>>Under Inspection</option>
                </select>
            </div>

            <div style="flex: 1; min-width: 160px;">
                <label class="form-label" style="font-size: 0.75rem;">Missing / GPS</label>
                <select name="missing" class="form-select">
                    <option value="">All profiles</option>
                    <option value="any" <?= $missing === 'any' ? 'selected' : '' ?>>Any gap (GPS / photos / IoT)</option>
                    <option value="gps" <?= $missing === 'gps' ? 'selected' : '' ?>>Missing GPS</option>
                    <option value="gps_approx" <?= $missing === 'gps_approx' ? 'selected' : '' ?>>City approx GPS (Excel)</option>
                    <option value="gps_exact" <?= $missing === 'gps_exact' ? 'selected' : '' ?>>Exact GPS only</option>
                    <option value="photos" <?= $missing === 'photos' ? 'selected' : '' ?>>Missing photos</option>
                    <option value="iot" <?= $missing === 'iot' ? 'selected' : '' ?>>Missing IoT</option>
                </select>
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1rem;">
                    Filter
                </button>
                <?php if ($search || $type || $brand || $status || $city || $missing): ?>
                    <a href="profiles.php" class="btn btn-secondary" style="padding: 0.65rem 0.85rem;" title="Clear Filters">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Profiles Table -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>Registered Freezers</span>
            <span class="badge badge-neutral" style="margin-left: 0.5rem;"><?= $totalCount ?> Total Records</span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Unique Freezer ID</th>
                    <th>Store / Outlet</th>
                    <th>Type & Brand</th>
                    <th>GPS & City</th>
                    <?php if (AppConfig::IOT_ENABLED): ?><th>IoT Status</th><?php endif; ?>
                    <th>Condition</th>
                    <th>QR Tag</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($freezers)): ?>
                    <tr>
                        <td colspan="<?= AppConfig::IOT_ENABLED ? 8 : 7 ?>" style="text-align: center; padding: 3rem 1rem; color: #94a3b8;">
                            <div style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem;">No freezer profiles matched your search</div>
                            <p style="font-size: 0.85rem;">Try modifying your keyword filter or register a new freezer.</p>
                            <a href="profile_add.php" class="btn btn-primary" style="margin-top: 1rem;">+ Add Store & Freezer Profile</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($freezers as $row): ?>
                        <tr>
                            <td>
                                <a href="profile_view.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="table-id-link">
                                    <?= htmlspecialchars($row['unique_freezer_id']) ?>
                                </a>
                                <div style="font-size: 0.75rem; color: #64748b;">Model: <?= htmlspecialchars($row['model_number']) ?></div>
                            </td>
                            <td>
                                <strong style="color: #0f172a;"><?= htmlspecialchars($row['store_name']) ?></strong>
                                <div style="font-size: 0.725rem; color: #94a3b8;">Outlet: <?= htmlspecialchars($row['outlet_id']) ?></div>
                            </td>
                            <td>
                                <span class="badge badge-primary"><?= htmlspecialchars($row['freezer_type']) ?></span>
                                <span style="font-size: 0.8rem; font-weight: 600; color: #475569; margin-left: 0.2rem;"><?= htmlspecialchars($row['company_code']) ?></span>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #334155; font-size: 0.85rem;"><?= htmlspecialchars($row['store_city']) ?></div>
                                <?php
                                $hasGps = !empty($row['store_latitude']) && !empty($row['store_longitude']);
                                $gpsAcc = isset($row['store_gps_accuracy']) ? (float)$row['store_gps_accuracy'] : null;
                                if (!$hasGps): ?>
                                    <span style="font-size: 0.7rem; color: #b91c1c; background: #fee2e2; padding: 0.1rem 0.35rem; border-radius: 4px;">Missing GPS</span>
                                <?php elseif ($gpsAcc !== null && $gpsAcc >= 1000): ?>
                                    <span style="font-size: 0.7rem; color: #b45309; background: #ffedd5; padding: 0.1rem 0.35rem; border-radius: 4px;">City approx</span>
                                <?php else: ?>
                                    <span style="font-size: 0.7rem; color: #15803d; background: #dcfce7; padding: 0.1rem 0.35rem; border-radius: 4px;">Exact GPS</span>
                                <?php endif; ?>
                            </td>
                            <?php if (AppConfig::IOT_ENABLED): ?>
                            <td>
                                <?php if (!empty($row['device_serial'])): ?>
                                    <span class="badge badge-success">Online (<?= htmlspecialchars($row['device_serial']) ?>)</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral">No IoT</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <span style="font-size: 0.75rem; color: #475569;"><?= htmlspecialchars($row['freezer_condition']) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($row['qr_code_path']) && file_exists(__DIR__ . '/' . $row['qr_code_path'])): ?>
                                    <a href="qr_print.php?id=<?= urlencode($row['unique_freezer_id']) ?>" title="Print QR Sticker" target="_blank">
                                        <img src="<?= htmlspecialchars($row['qr_code_path']) ?>" alt="QR" style="width: 36px; height: 36px; border: 1px solid var(--border-color); border-radius: 4px; padding: 2px;">
                                    </a>
                                <?php else: ?>
                                    <a href="profile_view.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="btn btn-sm btn-outline">QR</a>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="profile_view.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="btn btn-sm btn-secondary" title="View Full Details">View</a>
                                <a href="profile_edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline" title="Edit">Edit</a>
                                <a href="qr_print.php?id=<?= urlencode($row['unique_freezer_id']) ?>" class="btn btn-sm btn-outline" target="_blank" title="Print Tag">Print</a>
                                <?php if (AuthService::hasRole('super_admin')): ?>
                                    <a href="profile_delete.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline" style="color: #dc2626;" onclick="return confirm('Delete this freezer profile?');" title="Delete">Del</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
            <span>Showing Page <?= $page ?> of <?= $totalPages ?> (<?= $totalCount ?> total items)</span>
            <div style="display: flex; gap: 0.35rem;">
                <?php if ($page > 1): ?>
                    <a href="profiles.php?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-sm btn-outline">&larr; Prev</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="profiles.php?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-sm btn-outline">Next &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
