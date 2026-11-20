<?php
/**
 * Blank QR / Tag inventory.
 * Create unused tags by zone, filter them, and print logo + QR only.
 * Technicians with an assigned zone can only create/list/print tags for that zone.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\AssetTag;

AuthService::requireRole(['super_admin', 'technician']);
AuthService::requirePermission('create');
$currentUser = AuthService::currentUser();
$areaScope = AuthService::areaScope();

// When true, zone dropdown is locked to the technician's assigned zone
$lockZone = ($areaScope['zone'] ?? '') !== '';
$zoneOptions = AssetTag::zoneTagCodes(); // e.g. WEST => WE for tag codes RO-WE-…

$error = null;
$success = null;
$csrfToken = AuthService::generateCsrfToken();
$createdIds = [];

AssetTag::ensureTable();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $count = (int)($_POST['count'] ?? 10);
            $zone = strtoupper(trim((string)($_POST['batch_zone'] ?? '')));
            if ($lockZone) {
                $zone = strtoupper((string)$areaScope['zone']);
            }
            if ($zone === '' || !isset($zoneOptions[$zone])) {
                throw new Exception('Select a valid zone (South / North / West / East).');
            }
            // Batch = Zone-auto date time  e.g. SOUTH-20261102-132845
            $batch = $zone . '-' . date('Ymd-His');
            $created = AssetTag::createBatch($count, (int)$currentUser['id'], $batch, $zone);
            $createdIds = array_map(static fn($r) => (int)$r['id'], $created);
            $sample = $created[0]['tag_code'] ?? ('RO-' . $zoneOptions[$zone] . '-XXXXXX');
            $success = count($created) . ' blank QR tag(s) generated (e.g. ' . $sample . '). Batch: ' . $batch . '. Print Logo + QR only — no freezer details yet.';
        } catch (Throwable $e) {
            \App\Services\AppLogger::exception($e, 'blank_qr.create');
            $error = \App\Services\AppLogger::friendlyMessage(
                $e,
                'Could not create blank QR tags. Please try again.'
            );
        }
    }
}

$formZone = $lockZone
    ? strtoupper((string)$areaScope['zone'])
    : strtoupper(trim((string)($_POST['batch_zone'] ?? 'WEST')));
if (!isset($zoneOptions[$formZone])) {
    $formZone = 'WEST';
}

$statusFilter = $_GET['status'] ?? 'unused';
if (!in_array($statusFilter, ['unused', 'assigned', 'all'], true)) {
    $statusFilter = 'unused';
}

$zoneFilter = strtoupper(trim((string)($_GET['zone'] ?? '')));
if ($zoneFilter === 'ALL') {
    $zoneFilter = '';
}
// Scoped technicians cannot switch inventory/print filter to another zone
if ($lockZone) {
    $zoneFilter = strtoupper((string)$areaScope['zone']);
}
if ($zoneFilter !== '' && !isset($zoneOptions[$zoneFilter])) {
    $zoneFilter = '';
}

$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$listStatus = $statusFilter === 'all' ? '' : $statusFilter;
$stats = AssetTag::getStats($zoneFilter);
$statsAll = $zoneFilter === '' ? $stats : AssetTag::getStats('');
// Counts must match list + pagination (same rules as stats cards)
$countUnused = (int)$stats['unused'];
$countAssigned = (int)$stats['assigned'];
$countAll = (int)$stats['total'];
$totalTags = $statusFilter === 'unused'
    ? $countUnused
    : ($statusFilter === 'assigned' ? $countAssigned : $countAll);
$totalPages = max(1, (int)ceil($totalTags / max(1, $perPage)));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$tags = $totalTags > 0 ? AssetTag::listTags($listStatus, $perPage, $offset, $zoneFilter) : [];
$fromRow = $totalTags === 0 ? 0 : $offset + 1;
$toRow = min($offset + count($tags), $totalTags);

$blankQrPageUrl = static function (int $p, ?string $status = null, ?string $zone = null) use ($statusFilter, $zoneFilter): string {
    $params = [
        'status' => $status ?? $statusFilter,
    ];
    $z = $zone !== null ? $zone : $zoneFilter;
    if ($z !== '') {
        $params['zone'] = $z;
    }
    if ($p > 1) {
        $params['page'] = $p;
    }
    return 'blank_qr.php?' . http_build_query($params);
};

$zoneFilterLabel = $zoneFilter !== '' ? ucfirst(strtolower($zoneFilter)) : 'All zones';
$statusPrintLabel = $statusFilter === 'assigned' ? 'Assigned' : ($statusFilter === 'all' ? 'All' : 'Unused');
$printSelectionUrl = 'blank_qr_print.php?' . http_build_query(array_filter([
    'status' => $statusFilter,
    'zone' => $zoneFilter !== '' ? $zoneFilter : null,
    'limit' => min(500, max(1, $totalTags ?: 1)),
], static fn($v) => $v !== null && $v !== ''));

$pageTitle = 'Blank QR Tags';
require_once __DIR__ . '/includes/header.php';
?>

<div class="cfg-toolbar">
    <div class="cfg-toolbar-left">
        <h1 class="cfg-toolbar-title">Blank QR Tags</h1>
    </div>
    <a href="qr_scanner.php" class="btn btn-primary cfg-done-btn">Scan QR Tag</a>
</div>
<p style="color:var(--text-secondary); font-size:0.875rem; margin:-0.35rem 0 1rem; max-width:36rem;">
    Print empty stickers first (<strong>Logo + QR only</strong>). Field team scans → fills store/freezer data → submit.
    Later scans show full details.
</p>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;">
        <?= htmlspecialchars($success) ?>
        <?php if ($createdIds): ?>
            <div style="margin-top:0.75rem;">
                <a class="btn btn-primary btn-sm" target="_blank"
                   href="blank_qr_print.php?ids=<?= htmlspecialchars(implode(',', $createdIds)) ?>">
                    Print these tags (Logo + QR)
                </a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p style="font-size:0.8rem;color:#64748b;margin:0 0 0.65rem;">
    Showing stats for <strong><?= htmlspecialchars($zoneFilterLabel) ?></strong>
    <?php if ($zoneFilter !== ''): ?>
        · <a href="<?= htmlspecialchars($blankQrPageUrl(1, $statusFilter, '')) ?>">All zones</a>
        (<?= number_format((int)$statsAll['unused']) ?> unused overall)
    <?php endif; ?>
</p>
<div class="stat-grid" style="margin-bottom:1.25rem;">
    <div class="stat-card" style="--stat-accent:#0284c7; --stat-bg:#e0f2fe; --stat-color:#0284c7;">
        <div class="stat-info">
            <h3>Unused (ready to print)</h3>
            <div class="stat-value"><?= number_format($stats['unused']) ?></div>
        </div>
    </div>
    <div class="stat-card" style="--stat-accent:#10b981; --stat-bg:#d1fae5; --stat-color:#10b981;">
        <div class="stat-info">
            <h3>Assigned (has data)</h3>
            <div class="stat-value"><?= number_format($stats['assigned']) ?></div>
        </div>
    </div>
    <div class="stat-card" style="--stat-accent:#6366f1; --stat-bg:#ede9fe; --stat-color:#6366f1;">
        <div class="stat-info">
            <h3>Total tags</h3>
            <div class="stat-value"><?= number_format($stats['total']) ?></div>
        </div>
    </div>
</div>

<div class="blank-qr-generate" style="margin-bottom:1.25rem;">
    <div class="card" style="margin:0; width:100%;">
        <div class="card-header">
            <div class="card-title"><span>Generate blank QR batch</span></div>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="form-grid">
                    <div class="col-6 form-group">
                        <label class="form-label required" for="count">How many?</label>
                        <input type="number" id="count" name="count" class="form-control" min="1" max="200" value="10" required>
                    </div>
                    <div class="col-6 form-group">
                        <label class="form-label required" for="batch_zone">Zone</label>
                        <select id="batch_zone" name="batch_zone" class="form-select" required <?= $lockZone ? 'disabled' : '' ?>>
                            <?php foreach ($zoneOptions as $zName => $zCode): ?>
                                <option value="<?= htmlspecialchars($zName) ?>" <?= $formZone === $zName ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst(strtolower($zName))) ?> (<?= htmlspecialchars($zCode) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($lockZone): ?>
                            <input type="hidden" name="batch_zone" value="<?= htmlspecialchars($formZone) ?>">
                        <?php endif; ?>
                    </div>
                </div>
                <p style="font-size:0.8rem; color:#64748b; margin:0 0 0.5rem;">
                    Tag format: <strong>RO</strong> (ReeferON) + zone + random —
                    e.g. <code id="tag-preview" style="font-weight:700; color:#0f172a;">RO-WE-XXXXXX</code>
                </p>
                <p style="font-size:0.8rem; color:#64748b; margin:0 0 0.5rem;">
                    Batch: <code id="batch-preview" style="font-weight:700; color:#0f172a;">—</code>
                </p>
                <p style="font-size:0.8rem; color:#64748b; margin:0 0 1rem;">
                    Sticker print shows <strong>only logo + QR</strong> — no type/model/store text.
                </p>
                <button type="submit" class="btn btn-primary">Generate blank QR tags</button>
            </form>
            <script>
            (function () {
                const zoneEl = document.getElementById('batch_zone');
                const preview = document.getElementById('batch-preview');
                const tagPreview = document.getElementById('tag-preview');
                const zoneMap = <?= json_encode($zoneOptions, JSON_UNESCAPED_UNICODE) ?>;
                function pad(n) { return n < 10 ? '0' + n : '' + n; }
                function stamp() {
                    const d = new Date();
                    return '' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate())
                        + '-' + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds());
                }
                function refresh() {
                    const zone = (zoneEl && zoneEl.value) ? zoneEl.value : 'WEST';
                    const zc = zoneMap[zone] || 'WE';
                    preview.textContent = zone + '-' + stamp();
                    if (tagPreview) tagPreview.textContent = 'RO-' + zc + '-XXXXXX';
                }
                if (zoneEl) zoneEl.addEventListener('change', refresh);
                refresh();
                setInterval(refresh, 30000);
            })();
            </script>
        </div>
    </div>
</div>

<div class="card tag-inventory">
    <div class="card-header tag-inventory-head">
        <div class="card-title"><span>Tag inventory · <?= htmlspecialchars($zoneFilterLabel) ?></span></div>
        <div class="tag-inventory-filters">
            <div class="tag-inventory-status">
                <a href="<?= htmlspecialchars($blankQrPageUrl(1, 'unused')) ?>" class="btn btn-sm <?= $statusFilter === 'unused' ? 'btn-primary' : 'btn-secondary' ?>">Unused · <?= number_format($countUnused) ?></a>
                <a href="<?= htmlspecialchars($blankQrPageUrl(1, 'assigned')) ?>" class="btn btn-sm <?= $statusFilter === 'assigned' ? 'btn-primary' : 'btn-secondary' ?>">Assigned · <?= number_format($countAssigned) ?></a>
                <a href="<?= htmlspecialchars($blankQrPageUrl(1, 'all')) ?>" class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All · <?= number_format($countAll) ?></a>
            </div>
            <?php if ($totalTags > 0): ?>
                <a href="<?= htmlspecialchars($printSelectionUrl) ?>" class="btn btn-sm btn-primary tag-inventory-print" target="_blank"
                   title="Print current filter: <?= htmlspecialchars($statusPrintLabel . ' · ' . $zoneFilterLabel) ?>">
                    <span class="tag-print-full">Print <?= htmlspecialchars($statusPrintLabel) ?> · <?= htmlspecialchars($zoneFilterLabel) ?> (<?= number_format($totalTags) ?>)</span>
                    <span class="tag-print-short">Print (<?= number_format($totalTags) ?>)</span>
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary tag-inventory-print" disabled>Print (0)</button>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$lockZone): ?>
    <div class="tag-zone-scroll" role="navigation" aria-label="Filter by zone">
        <a href="<?= htmlspecialchars($blankQrPageUrl(1, $statusFilter, '')) ?>" class="btn btn-sm <?= $zoneFilter === '' ? 'btn-primary' : 'btn-secondary' ?>">
            <span class="tag-zone-all-full">All zones</span>
            <span class="tag-zone-all-short">All</span>
        </a>
        <?php foreach ($zoneOptions as $zName => $zCode): ?>
            <a href="<?= htmlspecialchars($blankQrPageUrl(1, $statusFilter, $zName)) ?>"
               class="btn btn-sm <?= $zoneFilter === $zName ? 'btn-primary' : 'btn-secondary' ?>">
                <?= htmlspecialchars(ucfirst(strtolower($zName))) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Desktop table -->
    <div class="card-body tag-inventory-desktop" style="padding:0; overflow-x:auto;">
        <table class="data-table tag-inventory-table">
            <thead>
                <tr>
                    <th>Tag code</th>
                    <th>Zone</th>
                    <th>Status</th>
                    <th>Batch</th>
                    <th>Linked freezer</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$tags): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:2rem; color:#94a3b8;">No tags<?= $zoneFilter !== '' ? ' in ' . htmlspecialchars($zoneFilterLabel) : '' ?>. Generate a batch above.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tags as $t): ?>
                        <?php $rowZone = AssetTag::zoneNameFromTagCode((string)($t['tag_code'] ?? '')); ?>
                        <tr>
                            <td><code class="tag-code"><?= htmlspecialchars($t['tag_code']) ?></code></td>
                            <td>
                                <?php if ($rowZone !== ''): ?>
                                    <span class="badge badge-neutral"><?= htmlspecialchars(ucfirst(strtolower($rowZone))) ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t['status'] === 'unused'): ?>
                                    <span class="badge badge-primary">Unused</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Assigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($t['batch_label'] ?? '—') ?></td>
                            <td>
                                <?php if (!empty($t['unique_freezer_id'])): ?>
                                    <a href="profile_view.php?id=<?= urlencode($t['unique_freezer_id']) ?>">
                                        <?= htmlspecialchars($t['unique_freezer_id']) ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($t['created_at'] ?? '') ?></td>
                            <td style="white-space:nowrap;">
                                <a class="btn btn-sm btn-secondary" target="_blank"
                                   href="blank_qr_print.php?ids=<?= (int)$t['id'] ?>">Print</a>
                                <?php if ($t['status'] === 'unused'): ?>
                                    <a class="btn btn-sm btn-primary"
                                       href="profile_add.php?tag=<?= urlencode($t['tag_code']) ?>">Fill data</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile cards -->
    <div class="card-body tag-inventory-mobile">
        <?php if (!$tags): ?>
            <p class="tag-inv-empty">No tags yet. Generate a batch above.</p>
        <?php else: ?>
            <ul class="tag-inv-list">
                <?php foreach ($tags as $t): ?>
                    <?php $rowZoneM = AssetTag::zoneNameFromTagCode((string)($t['tag_code'] ?? '')); ?>
                    <li class="tag-inv-card">
                        <div class="tag-inv-card-top">
                            <code class="tag-code"><?= htmlspecialchars($t['tag_code']) ?></code>
                            <?php if ($rowZoneM !== ''): ?>
                                <span class="badge badge-neutral"><?= htmlspecialchars(ucfirst(strtolower($rowZoneM))) ?></span>
                            <?php endif; ?>
                            <?php if ($t['status'] === 'unused'): ?>
                                <span class="badge badge-primary">Unused</span>
                            <?php else: ?>
                                <span class="badge badge-success">Assigned</span>
                            <?php endif; ?>
                        </div>
                        <div class="tag-inv-card-actions">
                            <a class="btn btn-sm btn-secondary" target="_blank"
                               href="blank_qr_print.php?ids=<?= (int)$t['id'] ?>">Print</a>
                            <?php if ($t['status'] === 'unused'): ?>
                                <a class="btn btn-sm btn-primary"
                                   href="profile_add.php?tag=<?= urlencode($t['tag_code']) ?>">Fill data</a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
        <div style="font-size:0.85rem; color:#64748b;">
            <?php if ($totalTags === 0): ?>
                <strong>0</strong> tags
            <?php else: ?>
                Showing <strong><?= (int)$fromRow ?>–<?= (int)$toRow ?></strong> of <strong><?= number_format($totalTags) ?></strong>
                · <?= (int)$perPage ?> per page
                <?php if ($totalPages > 1): ?>
                    · Page <strong><?= (int)$page ?></strong> of <strong><?= (int)$totalPages ?></strong>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
        <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
            <?php
            $prevPage = max(1, $page - 1);
            $nextPage = min($totalPages, $page + 1);
            $window = 2;
            $startP = max(1, $page - $window);
            $endP = min($totalPages, $page + $window);
            ?>
            <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($blankQrPageUrl($prevPage)) ?>">Prev</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary" disabled>Prev</button>
            <?php endif; ?>
            <?php if ($startP > 1): ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($blankQrPageUrl(1)) ?>">1</a>
                <?php if ($startP > 2): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $startP; $p <= $endP; $p++): ?>
                <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"
                   href="<?= htmlspecialchars($blankQrPageUrl($p)) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($endP < $totalPages): ?>
                <?php if ($endP < $totalPages - 1): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($blankQrPageUrl($totalPages)) ?>"><?= (int)$totalPages ?></a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($blankQrPageUrl($nextPage)) ?>">Next</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary" disabled>Next</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
