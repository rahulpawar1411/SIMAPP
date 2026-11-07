<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\FreezerLifecycle;

// Admin only — menu + page
AuthService::requireRole(AppConfig::ROLE_SUPER_ADMIN);
$currentUser = AuthService::currentUser();

$flash = null;
$error = null;

$today = date('Y-m-d');
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate = trim((string)($_GET['to'] ?? ''));
$days = (int)($_GET['days'] ?? 0);
$locationFilter = strtolower(trim((string)($_GET['location'] ?? 'all')));
if (!in_array($locationFilter, ['all', 'same', 'new'], true)) {
    $locationFilter = 'all';
}
$resultFilter = strtoupper(trim((string)($_GET['result'] ?? 'all')));
if (!in_array($resultFilter, ['ALL', 'PASS', 'FAIL'], true)) {
    $resultFilter = 'ALL';
}

$validDate = static function (string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
};

// Preset quick ranges set from/to via calendar
if (!$validDate($fromDate) || !$validDate($toDate)) {
    if (!in_array($days, [7, 30, 90, 180, 365], true)) {
        $days = 90;
    }
    $toDate = $today;
    $fromDate = date('Y-m-d', strtotime("-{$days} days"));
} else {
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
    // Cap range at 180 days for load safety (use narrower filters for more detail)
    $maxFrom = date('Y-m-d', strtotime($toDate . ' -180 days'));
    if ($fromDate < $maxFrom) {
        $fromDate = $maxFrom;
    }
    $days = 0;
}

$history = [];
try {
    $history = FreezerLifecycle::notificationHistoryByDate(90, $fromDate, $toDate, $locationFilter, $resultFilter);
} catch (Throwable $e) {
    $history = [];
    $error = $error ?: ('Could not load history: ' . $e->getMessage());
}

$defaultFrom = date('Y-m-d', strtotime('-90 days'));
$hasLocationFilter = $locationFilter !== 'all';
$hasResultFilter = $resultFilter !== 'ALL';
$isDefaultRange = ($fromDate === $defaultFrom && $toDate === $today && ($days === 90 || $days === 0));
$hasActiveFilters = $hasLocationFilter || $hasResultFilter || !$isDefaultRange;

$activeChips = [];
$activeChips[] = 'Date: ' . $fromDate . ' → ' . $toDate;
if ($locationFilter === 'same') {
    $activeChips[] = 'Same location';
} elseif ($locationFilter === 'new') {
    $activeChips[] = 'New location';
} else {
    $activeChips[] = 'All locations';
}
if ($resultFilter === 'FAIL') {
    $activeChips[] = 'Result: FAIL';
} elseif ($resultFilter === 'PASS') {
    $activeChips[] = 'Result: PASS';
} else {
    $activeChips[] = 'Result: All';
}
$activeFilterCount = count($activeChips);

$grandTotal = 0;
$grandSame = 0;
$grandNew = 0;
$grandFail = 0;
$historyTruncated = false;
foreach ($history as $day) {
    $grandTotal += (int)$day['total'];
    $grandSame += (int)$day['same_store'];
    $grandNew += (int)$day['new_store'];
    $grandFail += (int)$day['fail'];
    if (!empty($day['_truncated'])) {
        $historyTruncated = true;
    }
}

$pageTitle = 'Activity';
require_once __DIR__ . '/includes/header.php';
?>

<div class="users-page notif-page">
    <div class="cfg-toolbar">
        <div class="cfg-toolbar-left">
            <h1 class="cfg-toolbar-title">Activity</h1>
            <span class="badge badge-success"><?= number_format($grandTotal) ?> KYC in range</span>
            <?php if ($historyTruncated): ?>
                <span class="badge badge-warning" title="Latest 500 matching records shown">Showing latest 500</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Flipkart-style filter bar -->
    <div class="card profiles-filter-bar">
        <div class="card-body">
            <div class="profiles-search-row">
                <div class="profiles-search-field">
                    <label class="form-label">Activity range</label>
                    <div class="notif-range-readonly">
                        <?= htmlspecialchars($fromDate) ?> → <?= htmlspecialchars($toDate) ?>
                        <?php if ($locationFilter === 'same'): ?>
                            · Same location
                        <?php elseif ($locationFilter === 'new'): ?>
                            · New location
                        <?php else: ?>
                            · All locations
                        <?php endif; ?>
                        <?php if ($resultFilter === 'FAIL'): ?>
                            · FAIL only
                        <?php elseif ($resultFilter === 'PASS'): ?>
                            · PASS only
                        <?php endif; ?>
                    </div>
                </div>
                <div class="profiles-search-actions">
                    <button type="button" class="btn btn-outline profiles-filter-open" id="activity-filter-open" aria-haspopup="dialog" aria-controls="activity-filter-drawer" aria-expanded="false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                        </svg>
                        <span>Filter</span>
                        <?php if ($activeFilterCount > 0): ?>
                            <span class="profiles-filter-count"><?= (int)$activeFilterCount ?></span>
                        <?php endif; ?>
                    </button>
                    <?php if ($hasActiveFilters): ?>
                        <a href="notifications.php?days=90&location=all&result=all" class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($activeChips)): ?>
                <div class="profiles-active-chips" aria-label="Active filters">
                    <?php foreach ($activeChips as $chip): ?>
                        <span class="profiles-chip"><?= htmlspecialchars($chip) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right filter drawer (Flipkart style) -->
    <div class="filter-drawer-backdrop" id="activity-filter-backdrop" hidden></div>
    <aside class="filter-drawer" id="activity-filter-drawer" role="dialog" aria-modal="true" aria-labelledby="activity-filter-title" hidden>
        <form method="GET" action="notifications.php" id="activity-filter-form" class="filter-drawer-form">
            <header class="filter-drawer-head">
                <h2 id="activity-filter-title">Filters</h2>
                <button type="button" class="filter-drawer-close" id="activity-filter-close" aria-label="Close filters">&times;</button>
            </header>
            <div class="filter-drawer-body">
                <section class="filter-section">
                    <h3 class="filter-section-title">Date range</h3>
                    <div class="filter-date-grid">
                        <div class="form-group" style="margin-bottom:0.75rem;">
                            <label class="form-label" for="activity-from">From</label>
                            <input type="date" id="activity-from" name="from" class="form-control"
                                   value="<?= htmlspecialchars($fromDate) ?>" max="<?= htmlspecialchars($today) ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" for="activity-to">To</label>
                            <input type="date" id="activity-to" name="to" class="form-control"
                                   value="<?= htmlspecialchars($toDate) ?>" max="<?= htmlspecialchars($today) ?>">
                        </div>
                    </div>
                    <div class="filter-preset-list" style="margin-top:0.75rem;">
                        <?php
                        $presets = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 180 => 'Last 180 days', 365 => 'Last 1 year'];
                        foreach ($presets as $d => $label):
                            $checked = ($days === (int)$d) || ($isDefaultRange && (int)$d === 90);
                            ?>
                            <label class="filter-check">
                                <input type="radio" name="days" value="<?= (int)$d ?>"
                                    <?= $checked ? 'checked' : '' ?>
                                    data-preset-days="<?= (int)$d ?>">
                                <span><?= htmlspecialchars($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="filter-section">
                    <h3 class="filter-section-title">Location type</h3>
                    <div class="filter-check-list">
                        <label class="filter-check">
                            <input type="radio" name="location" value="all" <?= $locationFilter === 'all' ? 'checked' : '' ?>>
                            <span>All locations</span>
                        </label>
                        <label class="filter-check">
                            <input type="radio" name="location" value="same" <?= $locationFilter === 'same' ? 'checked' : '' ?>>
                            <span>Same location</span>
                        </label>
                        <label class="filter-check">
                            <input type="radio" name="location" value="new" <?= $locationFilter === 'new' ? 'checked' : '' ?>>
                            <span>New location</span>
                        </label>
                    </div>
                </section>

                <section class="filter-section">
                    <h3 class="filter-section-title">KYC result</h3>
                    <div class="filter-check-list">
                        <label class="filter-check">
                            <input type="radio" name="result" value="all" <?= $resultFilter === 'ALL' ? 'checked' : '' ?>>
                            <span>All results</span>
                        </label>
                        <label class="filter-check">
                            <input type="radio" name="result" value="PASS" <?= $resultFilter === 'PASS' ? 'checked' : '' ?>>
                            <span>PASS only</span>
                        </label>
                        <label class="filter-check">
                            <input type="radio" name="result" value="FAIL" <?= $resultFilter === 'FAIL' ? 'checked' : '' ?>>
                            <span>FAIL only</span>
                        </label>
                    </div>
                </section>
            </div>
            <footer class="filter-drawer-foot">
                <a href="notifications.php?days=90&location=all&result=all" class="btn btn-secondary">Clear all</a>
                <button type="submit" class="btn btn-primary">Apply filters</button>
            </footer>
        </form>
    </aside>

    <?php if ($flash): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="notif-summary-grid">
        <div class="notif-summary-card">
            <span>Total KYC</span>
            <strong><?= number_format($grandTotal) ?></strong>
        </div>
        <div class="notif-summary-card">
            <span>Same store</span>
            <strong><?= number_format($grandSame) ?></strong>
        </div>
        <div class="notif-summary-card">
            <span>New store moves</span>
            <strong><?= number_format($grandNew) ?></strong>
        </div>
        <div class="notif-summary-card">
            <span>FAIL</span>
            <strong><?= number_format($grandFail) ?></strong>
        </div>
    </div>

    <p class="notif-history-intro">
        Showing
        <?php if ($resultFilter === 'FAIL'): ?>
            <strong>FAIL</strong>
        <?php elseif ($resultFilter === 'PASS'): ?>
            <strong>PASS</strong>
        <?php else: ?>
            <strong>all results</strong>
        <?php endif; ?>
        ·
        <?php if ($locationFilter === 'same'): ?>
            <strong>same location</strong>
        <?php elseif ($locationFilter === 'new'): ?>
            <strong>new location</strong>
        <?php else: ?>
            <strong>all locations</strong>
        <?php endif; ?>
        from <strong><?= htmlspecialchars($fromDate) ?></strong>
        to <strong><?= htmlspecialchars($toDate) ?></strong>.
        <?php if ($grandFail > 0 && $resultFilter !== 'FAIL'): ?>
            <a href="profiles.php?kyc[]=failed">View failed profiles</a>
        <?php endif; ?>
    </p>

    <?php
    $lazyBatch = 5;
    $rowPreview = 12;
    $historyInitial = array_slice($history, 0, $lazyBatch);
    $historyRest = array_slice($history, $lazyBatch);
    $renderDayCard = static function (array $day, int $rowPreview): void {
        $items = $day['items'] ?? [];
        $totalItems = count($items);
        $visible = array_slice($items, 0, $rowPreview);
        $hiddenCount = max(0, $totalItems - count($visible));
        ?>
        <div class="card notif-day-card" data-day="<?= htmlspecialchars((string)($day['date'] ?? '')) ?>">
            <div class="card-header notif-day-head">
                <div class="card-title">
                    <span><?= htmlspecialchars((string)($day['label'] ?? '')) ?></span>
                </div>
                <div class="notif-day-counts">
                    <span class="badge badge-success"><?= (int)($day['total'] ?? 0) ?> total</span>
                    <span class="notif-day-pill"><?= (int)($day['same_store'] ?? 0) ?> same store</span>
                    <span class="notif-day-pill notif-day-pill-move"><?= (int)($day['new_store'] ?? 0) ?> new store</span>
                    <?php if ((int)($day['fail'] ?? 0) > 0): ?>
                        <span class="badge badge-danger"><?= (int)$day['fail'] ?> FAIL</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-responsive">
                    <table class="data-table notif-day-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Type</th>
                                <th>Freezer ID</th>
                                <th>Store</th>
                                <th>City</th>
                                <th>Result</th>
                                <th>Technician</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visible as $i => $item): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td>
                                        <?php if (!empty($item['is_new_store'])): ?>
                                            <span class="badge badge-warning">New store</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">Same store</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a class="profile-kv-mono" href="profile_view.php?id=<?= urlencode((string)$item['unique_freezer_id']) ?>">
                                            <?= htmlspecialchars($item['unique_freezer_id'] !== '' ? $item['unique_freezer_id'] : ('#' . $item['freezer_id'])) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($item['store_name'] !== '' ? $item['store_name'] : '—') ?>
                                        <?php if ($item['outlet_id'] !== ''): ?>
                                            <div class="notif-day-sub"><?= htmlspecialchars($item['outlet_id']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($item['city'] !== '' ? $item['city'] : '—') ?></td>
                                    <td>
                                        <span class="badge <?= ($item['result'] ?? '') === 'FAIL' ? 'badge-danger' : 'badge-success' ?>">
                                            <?= htmlspecialchars($item['result'] ?: 'PASS') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($item['technician_name'] !== '' ? $item['technician_name'] : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($hiddenCount > 0): ?>
                    <div class="notif-day-more-wrap">
                        <button type="button" class="btn btn-sm btn-outline notif-day-more-btn"
                            data-more-items="<?= htmlspecialchars(json_encode(array_slice($items, $rowPreview), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
                            data-start-index="<?= (int)$rowPreview ?>">
                            Show <?= (int)$hiddenCount ?> more
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    };
    ?>

    <?php if (empty($history)): ?>
        <div class="card">
            <div class="card-body">
                <p style="margin:0;color:#94a3b8;">No KYC activity in this date range.</p>
            </div>
        </div>
    <?php else: ?>
        <div id="activity-day-list">
            <?php foreach ($historyInitial as $day): ?>
                <?php $renderDayCard($day, $rowPreview); ?>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($historyRest)): ?>
            <div id="activity-lazy-sentinel" class="notif-lazy-sentinel" aria-hidden="true">
                <div class="notif-lazy-spinner" aria-hidden="true"></div>
                <span>Loading more activity…</span>
            </div>
            <script type="application/json" id="activity-lazy-data"><?= json_encode(array_values($historyRest), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
        <?php endif; ?>
    <?php endif; ?>

</div>

<script>
(function () {
    var openBtn = document.getElementById('activity-filter-open');
    var closeBtn = document.getElementById('activity-filter-close');
    var drawer = document.getElementById('activity-filter-drawer');
    var backdrop = document.getElementById('activity-filter-backdrop');
    var form = document.getElementById('activity-filter-form');
    var fromInput = document.getElementById('activity-from');
    var toInput = document.getElementById('activity-to');

    function openDrawer() {
        if (!drawer || !backdrop) return;
        drawer.hidden = false;
        backdrop.hidden = false;
        requestAnimationFrame(function () {
            drawer.classList.add('is-open');
            backdrop.classList.add('is-open');
        });
        document.body.classList.add('filter-drawer-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    }

    function closeDrawer() {
        if (!drawer || !backdrop) return;
        drawer.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('filter-drawer-open');
        setTimeout(function () {
            if (!drawer.classList.contains('is-open')) {
                drawer.hidden = true;
                backdrop.hidden = true;
            }
        }, 280);
    }

    if (openBtn) openBtn.addEventListener('click', openDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if (backdrop) backdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drawer && drawer.classList.contains('is-open')) closeDrawer();
    });

    if (form) {
        form.querySelectorAll('input[data-preset-days]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked || !fromInput || !toInput) return;
                var days = parseInt(radio.getAttribute('data-preset-days'), 10) || 90;
                var to = new Date();
                var from = new Date();
                from.setDate(from.getDate() - days);
                function fmt(d) {
                    var m = String(d.getMonth() + 1).padStart(2, '0');
                    var day = String(d.getDate()).padStart(2, '0');
                    return d.getFullYear() + '-' + m + '-' + day;
                }
                toInput.value = fmt(to);
                fromInput.value = fmt(from);
            });
        });
        [fromInput, toInput].forEach(function (el) {
            if (!el) return;
            el.addEventListener('change', function () {
                form.querySelectorAll('input[data-preset-days]').forEach(function (r) {
                    r.checked = false;
                });
            });
        });
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function rowHtml(item, index) {
        var uid = item.unique_freezer_id || ('#' + item.freezer_id);
        var typeBadge = item.is_new_store
            ? '<span class="badge badge-warning">New store</span>'
            : '<span class="badge badge-success">Same store</span>';
        var resultBadge = (item.result === 'FAIL')
            ? '<span class="badge badge-danger">FAIL</span>'
            : '<span class="badge badge-success">' + esc(item.result || 'PASS') + '</span>';
        var outlet = item.outlet_id
            ? '<div class="notif-day-sub">' + esc(item.outlet_id) + '</div>'
            : '';
        return '<tr>' +
            '<td>' + (index + 1) + '</td>' +
            '<td>' + typeBadge + '</td>' +
            '<td><a class="profile-kv-mono" href="profile_view.php?id=' + encodeURIComponent(item.unique_freezer_id || '') + '">' + esc(uid) + '</a></td>' +
            '<td>' + esc(item.store_name || '—') + outlet + '</td>' +
            '<td>' + esc(item.city || '—') + '</td>' +
            '<td>' + resultBadge + '</td>' +
            '<td>' + esc(item.technician_name || '—') + '</td>' +
            '</tr>';
    }

    function buildDayCard(day, rowPreview) {
        rowPreview = rowPreview || 12;
        var items = day.items || [];
        var visible = items.slice(0, rowPreview);
        var rest = items.slice(rowPreview);
        var fail = day.fail ? '<span class="badge badge-danger">' + day.fail + ' FAIL</span>' : '';
        var rows = visible.map(function (it, i) { return rowHtml(it, i); }).join('');
        var more = '';
        if (rest.length) {
            more = '<div class="notif-day-more-wrap">' +
                '<button type="button" class="btn btn-sm btn-outline notif-day-more-btn" data-start-index="' + rowPreview + '">' +
                'Show ' + rest.length + ' more</button></div>';
        }
        var el = document.createElement('div');
        el.className = 'card notif-day-card';
        el.setAttribute('data-day', day.date || '');
        el.innerHTML =
            '<div class="card-header notif-day-head">' +
                '<div class="card-title"><span>' + esc(day.label || '') + '</span></div>' +
                '<div class="notif-day-counts">' +
                    '<span class="badge badge-success">' + (day.total || 0) + ' total</span>' +
                    '<span class="notif-day-pill">' + (day.same_store || 0) + ' same store</span>' +
                    '<span class="notif-day-pill notif-day-pill-move">' + (day.new_store || 0) + ' new store</span>' +
                    fail +
                '</div>' +
            '</div>' +
            '<div class="card-body" style="padding:0;">' +
                '<div class="table-responsive"><table class="data-table notif-day-table">' +
                    '<thead><tr><th>#</th><th>Type</th><th>Freezer ID</th><th>Store</th><th>City</th><th>Result</th><th>Technician</th></tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                '</table></div>' + more +
            '</div>';
        if (rest.length) {
            var btn = el.querySelector('.notif-day-more-btn');
            if (btn) btn._moreItems = rest;
        }
        return el;
    }

    // Lazy-load remaining day cards on scroll
    var list = document.getElementById('activity-day-list');
    var sentinel = document.getElementById('activity-lazy-sentinel');
    var dataEl = document.getElementById('activity-lazy-data');
    var queue = [];
    var batchSize = 5;
    var loading = false;

    if (dataEl && list) {
        try {
            queue = JSON.parse(dataEl.textContent || '[]') || [];
        } catch (e) {
            queue = [];
        }
    }

    function appendBatch() {
        if (loading || !queue.length || !list) return;
        loading = true;
        var chunk = queue.splice(0, batchSize);
        chunk.forEach(function (day) {
            list.appendChild(buildDayCard(day, 12));
        });
        loading = false;
        if (!queue.length && sentinel) {
            sentinel.remove();
        }
    }

    if (sentinel && queue.length && 'IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) appendBatch();
            });
        }, { rootMargin: '240px 0px' });
        io.observe(sentinel);
    } else if (queue.length) {
        appendBatch();
        while (queue.length) appendBatch();
        if (sentinel) sentinel.remove();
    }

    // Expand rows inside a day card
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.notif-day-more-btn');
        if (!btn) return;
        var card = btn.closest('.notif-day-card');
        if (!card) return;
        var tbody = card.querySelector('tbody');
        if (!tbody) return;
        var rest = btn._moreItems;
        if (!rest) {
            try {
                rest = JSON.parse(btn.getAttribute('data-more-items') || '[]');
            } catch (err) {
                rest = [];
            }
        }
        var start = parseInt(btn.getAttribute('data-start-index'), 10) || tbody.children.length;
        rest.forEach(function (item, i) {
            tbody.insertAdjacentHTML('beforeend', rowHtml(item, start + i));
        });
        var wrap = btn.closest('.notif-day-more-wrap');
        if (wrap) wrap.remove();
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
