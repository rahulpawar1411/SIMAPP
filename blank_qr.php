<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\AssetTag;

AuthService::requireRole('super_admin');
$currentUser = AuthService::currentUser();

$error = null;
$success = null;
$csrfToken = AuthService::generateCsrfToken();
$createdIds = [];

AssetTag::ensureTable();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security CSRF token. Please try again.';
    } else {
        try {
            $count = (int)($_POST['count'] ?? 10);
            $city = preg_replace('/\s+/', ' ', trim((string)($_POST['batch_city'] ?? '')));
            if ($city === '') {
                throw new Exception('Enter city name for the batch.');
            }
            // Batch = City-auto date time  e.g. Pune-20261102-132845
            $batch = $city . '-' . date('Ymd-His');
            $created = AssetTag::createBatch($count, (int)$currentUser['id'], $batch);
            $createdIds = array_map(static fn($r) => (int)$r['id'], $created);
            $success = count($created) . ' blank QR tag(s) generated. Batch: ' . $batch . '. Print Logo + QR only — no freezer details yet.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$statusFilter = $_GET['status'] ?? 'unused';
if (!in_array($statusFilter, ['unused', 'assigned', 'all'], true)) {
    $statusFilter = 'unused';
}
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$listStatus = $statusFilter === 'all' ? '' : $statusFilter;
$totalTags = AssetTag::countTags($listStatus);
$totalPages = max(1, (int)ceil($totalTags / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$stats = AssetTag::getStats();
$tags = AssetTag::listTags($listStatus, $perPage, $offset);
$fromRow = $totalTags === 0 ? 0 : $offset + 1;
$toRow = min($offset + $perPage, $totalTags);

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

<div class="form-grid" style="gap:1.25rem; align-items:stretch; margin-bottom:1.25rem;">
    <div class="card col-6" style="margin:0;">
        <div class="card-header">
            <div class="card-title"><span>Generate blank QR batch</span></div>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="form-grid">
                    <div class="col-4 form-group">
                        <label class="form-label required" for="count">How many tags?</label>
                        <input type="number" id="count" name="count" class="form-control" min="1" max="200" value="10" required>
                    </div>
                    <div class="col-8 form-group">
                        <label class="form-label required" for="batch_city">City name</label>
                        <input type="text" id="batch_city" name="batch_city" class="form-control"
                               placeholder="e.g. Pune" required maxlength="60"
                               value="<?= htmlspecialchars($_POST['batch_city'] ?? '') ?>"
                               autocomplete="address-level2">
                    </div>
                </div>
                <p style="font-size:0.8rem; color:#64748b; margin:0 0 0.5rem;">
                    Batch auto-builds as <strong>City + date time</strong>:
                    <code id="batch-preview" style="font-weight:700; color:#0f172a;">—</code>
                </p>
                <p style="font-size:0.8rem; color:#64748b; margin:0 0 1rem;">
                    Each tag gets a code like <code>RO-A7K2M9</code>. Sticker print shows <strong>only logo + QR</strong> — no type/model/store text.
                </p>
                <button type="submit" class="btn btn-primary">Generate blank QR tags</button>
            </form>
            <script>
            (function () {
                const cityEl = document.getElementById('batch_city');
                const preview = document.getElementById('batch-preview');
                function pad(n) { return n < 10 ? '0' + n : '' + n; }
                function stamp() {
                    const d = new Date();
                    return '' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate())
                        + '-' + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds());
                }
                function refresh() {
                    const city = (cityEl.value || '').trim().replace(/\s+/g, ' ') || 'City';
                    preview.textContent = city + '-' + stamp();
                }
                cityEl.addEventListener('input', refresh);
                refresh();
                setInterval(refresh, 30000);
            })();
            </script>
        </div>
    </div>

    <div class="card col-6" style="margin:0;">
        <div class="card-header">
            <div class="card-title"><span>Print unused stickers</span></div>
        </div>
        <div class="card-body">
            <p style="font-size:0.875rem; color:#475569; margin-bottom:1rem;">
                Open a print sheet of unused tags. Stick on freezer → scan → fill data.
            </p>
            <div style="display:flex; flex-wrap:wrap; gap:0.75rem;">
                <a href="blank_qr_print.php?status=unused&limit=20" class="btn btn-primary" target="_blank">Print 20 unused</a>
                <a href="blank_qr_print.php?status=unused&limit=50" class="btn btn-secondary" target="_blank">Print 50 unused</a>
                <a href="blank_qr_print.php?status=unused&limit=100" class="btn btn-secondary" target="_blank">Print 100 unused</a>
            </div>
        </div>
    </div>
</div>

<div class="card tag-inventory">
    <div class="card-header tag-inventory-head">
        <div class="card-title"><span>Tag inventory</span></div>
        <div class="tag-inventory-filters">
            <a href="blank_qr.php?status=unused" class="btn btn-sm <?= $statusFilter === 'unused' ? 'btn-primary' : 'btn-secondary' ?>">Unused</a>
            <a href="blank_qr.php?status=assigned" class="btn btn-sm <?= $statusFilter === 'assigned' ? 'btn-primary' : 'btn-secondary' ?>">Assigned</a>
            <a href="blank_qr.php?status=all" class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All</a>
        </div>
    </div>

    <!-- Desktop table -->
    <div class="card-body tag-inventory-desktop" style="padding:0; overflow-x:auto;">
        <table class="data-table tag-inventory-table">
            <thead>
                <tr>
                    <th>Tag code</th>
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
                        <td colspan="6" style="text-align:center; padding:2rem; color:#94a3b8;">No tags yet. Generate a batch above.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tags as $t): ?>
                        <tr>
                            <td><code class="tag-code"><?= htmlspecialchars($t['tag_code']) ?></code></td>
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
                    <li class="tag-inv-card">
                        <div class="tag-inv-card-top">
                            <code class="tag-code"><?= htmlspecialchars($t['tag_code']) ?></code>
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
    <?php if ($totalTags > 0): ?>
    <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
        <div style="font-size:0.85rem; color:#64748b;">
            Showing <strong><?= (int)$fromRow ?>–<?= (int)$toRow ?></strong> of <strong><?= (int)$totalTags ?></strong>
            · 15 per page
        </div>
        <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
            <?php
            $baseQs = 'status=' . urlencode($statusFilter);
            $prevPage = max(1, $page - 1);
            $nextPage = min($totalPages, $page + 1);
            ?>
            <a class="btn btn-sm btn-secondary <?= $page <= 1 ? 'disabled' : '' ?>"
               href="blank_qr.php?<?= $baseQs ?>&page=<?= $prevPage ?>"
               <?= $page <= 1 ? 'aria-disabled="true" onclick="return false;"' : '' ?>>Prev</a>
            <?php
            $window = 2;
            $startP = max(1, $page - $window);
            $endP = min($totalPages, $page + $window);
            if ($startP > 1): ?>
                <a class="btn btn-sm btn-secondary" href="blank_qr.php?<?= $baseQs ?>&page=1">1</a>
                <?php if ($startP > 2): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $startP; $p <= $endP; $p++): ?>
                <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"
                   href="blank_qr.php?<?= $baseQs ?>&page=<?= $p ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($endP < $totalPages): ?>
                <?php if ($endP < $totalPages - 1): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
                <a class="btn btn-sm btn-secondary" href="blank_qr.php?<?= $baseQs ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a>
            <?php endif; ?>
            <a class="btn btn-sm btn-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>"
               href="blank_qr.php?<?= $baseQs ?>&page=<?= $nextPage ?>"
               <?= $page >= $totalPages ? 'aria-disabled="true" onclick="return false;"' : '' ?>>Next</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
