<?php
/**
 * System Logs (Super Admin only).
 * Filter by file, level, and search text. Results are paginated.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\AppLogger;
use App\Config\AppConfig;

// Admin only — Notifications / Reports / Logs
AuthService::requireRole(AppConfig::ROLE_SUPER_ADMIN);

$files = AppLogger::listLogFiles();
$file = trim((string)($_GET['file'] ?? ''));
if ($file === '' || AppLogger::resolveLogFile($file) === null) {
    $file = $files[0] ?? '';
}

// Filters from the query string
$level = strtoupper(trim((string)($_GET['level'] ?? '')));
$allowedLevels = ['', 'INFO', 'AUDIT', 'WARNING', 'ERROR', 'FAILED'];
if (!in_array($level, $allowedLevels, true)) {
    $level = '';
}
$q = trim((string)($_GET['q'] ?? ''));
$perPage = (int)($_GET['per'] ?? 50);
if (!in_array($perPage, [25, 50, 100], true)) {
    $perPage = 50;
}
$page = max(1, (int)($_GET['page'] ?? 1));

$logError = null;
try {
    $logResult = $file !== ''
        ? AppLogger::readEntries($file, $perPage, $level, $q, ($page - 1) * $perPage)
        : ['entries' => [], 'total' => 0, 'capped' => false];
} catch (Throwable $e) {
    AppLogger::exception($e, 'admin_logs.read');
    $logError = AppLogger::friendlyMessage($e, 'Could not read this log file. Please try another file or refresh.');
    $logResult = ['entries' => [], 'total' => 0, 'capped' => false];
}
$entries = $logResult['entries'];
$logsTotal = (int)$logResult['total'];
$logsCapped = !empty($logResult['capped']);
$logsPages = max(1, (int)ceil(max(1, $logsTotal) / $perPage));
if ($logsTotal === 0) {
    $logsPages = 1;
    $page = 1;
} elseif ($page > $logsPages) {
    $page = $logsPages;
    try {
        $logResult = AppLogger::readEntries($file, $perPage, $level, $q, ($page - 1) * $perPage);
        $entries = $logResult['entries'];
        $logsTotal = (int)$logResult['total'];
        $logsCapped = !empty($logResult['capped']);
    } catch (Throwable $e) {
        AppLogger::exception($e, 'admin_logs.read_page');
        $logError = $logError ?: AppLogger::friendlyMessage($e, 'Could not read this log page.');
        $entries = [];
        $logsTotal = 0;
        $logsCapped = false;
    }
}
$logsOffset = ($page - 1) * $perPage;
$logsFrom = $logsTotal === 0 ? 0 : ($logsOffset + 1);
$logsTo = min($logsOffset + count($entries), $logsTotal);

$logsQueryParams = static function (array $extra = []) use ($file, $level, $q, $perPage, $page): array {
    $params = array_merge([
        'file' => $file,
        'level' => $level,
        'q' => $q,
        'per' => $perPage,
        'page' => $page,
    ], $extra);
    if (($params['level'] ?? '') === '') {
        unset($params['level']);
    }
    if (($params['q'] ?? '') === '') {
        unset($params['q']);
    }
    if ((int)($params['per'] ?? 50) === 50) {
        unset($params['per']);
    }
    if ((int)($params['page'] ?? 1) <= 1) {
        unset($params['page']);
    }
    if (($params['file'] ?? '') === '') {
        unset($params['file']);
    }
    return $params;
};

$logsPageUrl = static function (int $p) use ($logsQueryParams): string {
    return 'admin_logs.php?' . http_build_query($logsQueryParams(['page' => max(1, $p)]));
};

$pageTitle = 'System Logs';
require_once __DIR__ . '/includes/header.php';
?>
<?php if (!empty($logError)): ?>
<div class="alert alert-danger" role="alert" style="margin:0.75rem 0 0;">
    <?= htmlspecialchars($logError) ?>
</div>
<?php endif; ?>
<?php
$levelClass = static function (string $lv): string {
    return match (strtoupper($lv)) {
        'ERROR', 'FAILED' => 'badge-danger',
        'WARNING' => 'badge-warning',
        'AUDIT' => 'badge-success',
        default => 'badge-neutral',
    };
};

/** Compact context as key=value;key=value for one cell */
$ctxLine = static function (array $row): string {
    $ctx = $row['context'] ?? null;
    if (!is_array($ctx) || $ctx === []) {
        return '';
    }
    $parts = [];
    foreach ($ctx as $k => $v) {
        if ($v === null || $v === '' || $v === []) {
            continue;
        }
        $key = is_string($k) ? $k : ('k' . $k);
        if (is_scalar($v)) {
            $parts[] = $key . '=' . (string)$v;
        } else {
            $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($enc !== false && $enc !== '') {
                $parts[] = $key . '=' . $enc;
            }
        }
    }
    return implode('; ', $parts);
};
?>

<div class="logs-page users-page">
    <div class="cfg-toolbar logs-toolbar-compact">
        <div class="cfg-toolbar-left">
            <h1 class="cfg-toolbar-title">System Logs</h1>
        </div>
        <div class="logs-toolbar-actions">
            <a href="admin_logs.php<?= $file !== '' ? ('?' . http_build_query($logsQueryParams(['page' => 1]))) : '' ?>" class="btn btn-secondary btn-sm">Refresh</a>
        </div>
    </div>

    <form method="get" action="admin_logs.php" class="card logs-filters-card logs-filters-compact">
        <div class="card-body">
            <div class="logs-filters-bar">
                <div class="form-group">
                    <label class="form-label" for="file">File</label>
                    <select name="file" id="file" class="form-select form-select-sm">
                        <?php if (!$files): ?>
                            <option value="">No logs yet</option>
                        <?php else: ?>
                            <?php foreach ($files as $f): ?>
                                <option value="<?= htmlspecialchars($f) ?>" <?= $f === $file ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="level">Level</label>
                    <select name="level" id="level" class="form-select form-select-sm">
                        <option value="" <?= $level === '' ? 'selected' : '' ?>>All</option>
                        <option value="INFO" <?= $level === 'INFO' ? 'selected' : '' ?>>INFO</option>
                        <option value="AUDIT" <?= $level === 'AUDIT' ? 'selected' : '' ?>>AUDIT</option>
                        <option value="WARNING" <?= $level === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                        <option value="ERROR" <?= $level === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                        <option value="FAILED" <?= $level === 'FAILED' ? 'selected' : '' ?>>FAILED</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="per">Per page</label>
                    <select name="per" id="per" class="form-select form-select-sm">
                        <?php foreach ([25, 50, 100] as $n): ?>
                            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group logs-filter-search">
                    <label class="form-label" for="q">Search</label>
                    <input type="text" name="q" id="q" class="form-control form-control-sm" value="<?= htmlspecialchars($q) ?>" placeholder="Search…">
                </div>
                <div class="form-group logs-filter-go">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary btn-sm">Go</button>
                </div>
            </div>
            <p class="logs-filters-meta">
                <?= number_format($logsTotal) ?><?= $logsCapped ? '+' : '' ?> matched
                <?= $file !== '' ? ' · ' . htmlspecialchars($file) : '' ?>
                · newest first · scroll sideways for all columns
            </p>
        </div>
    </form>

    <div class="card logs-entries-card">
        <div class="card-body logs-entries-body">
            <?php if (!$entries): ?>
                <p class="users-empty">No log entries. Use the app then refresh.</p>
            <?php else: ?>
                <div class="logs-table-wrap">
                    <table class="logs-excel">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Level</th>
                                <th>Event</th>
                                <th>Message</th>
                                <th>User ID</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>IP</th>
                                <th>Method</th>
                                <th>URI</th>
                                <th>Context</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $row): ?>
                                <?php
                                $lv = (string)($row['level'] ?? 'INFO');
                                $ctx = $ctxLine($row);
                                ?>
                                <tr>
                                    <td title="<?= htmlspecialchars((string)($row['ts'] ?? '')) ?>"><?= htmlspecialchars((string)($row['ts'] ?? '—')) ?></td>
                                    <td class="logs-col-level" title="<?= htmlspecialchars($lv) ?>">
                                        <span class="badge badge-sm <?= $levelClass($lv) ?>"><?= htmlspecialchars($lv) ?></span>
                                    </td>
                                    <td title="<?= htmlspecialchars((string)($row['event'] ?? '')) ?>"><?= htmlspecialchars((string)($row['event'] ?? '—')) ?></td>
                                    <td title="<?= htmlspecialchars((string)($row['message'] ?? '')) ?>"><?= htmlspecialchars((string)($row['message'] ?? '—')) ?></td>
                                    <td title="<?= htmlspecialchars((string)($row['user_id'] ?? '')) ?>"><?= htmlspecialchars((string)($row['user_id'] ?? '—')) ?></td>
                                    <td title="<?= htmlspecialchars((string)($row['user'] ?? '')) ?>"><?= htmlspecialchars((string)($row['user'] ?? '—')) ?></td>
                                    <td title="<?= htmlspecialchars((string)($row['role'] ?? '')) ?>"><?= htmlspecialchars((string)($row['role'] ?? '—')) ?></td>
                                    <td title="<?= htmlspecialchars((string)($row['ip'] ?? '')) ?>"><?= htmlspecialchars((string)($row['ip'] ?? '—')) ?></td>
                                    <td title="<?= htmlspecialchars((string)($row['method'] ?? '')) ?>"><?= htmlspecialchars((string)($row['method'] ?? '—')) ?></td>
                                    <td title="<?= htmlspecialchars((string)($row['uri'] ?? '')) ?>"><?= htmlspecialchars((string)($row['uri'] ?? '—')) ?></td>
                                    <td title="<?= htmlspecialchars($ctx) ?>"><?= $ctx !== '' ? htmlspecialchars($ctx) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($logsTotal > 0): ?>
        <div class="card-footer profiles-pagination" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
            <div style="font-size:0.85rem; color:#64748b;">
                Showing <strong><?= (int)$logsFrom ?>–<?= (int)$logsTo ?></strong> of <strong><?= number_format($logsTotal) ?><?= $logsCapped ? '+' : '' ?></strong>
                · <?= (int)$perPage ?> per page
                <?php if ($logsPages > 1): ?>
                    · Page <strong><?= (int)$page ?></strong> of <strong><?= (int)$logsPages ?></strong>
                <?php endif; ?>
            </div>
            <?php if ($logsPages > 1): ?>
            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                <?php
                $prevPage = max(1, $page - 1);
                $nextPage = min($logsPages, $page + 1);
                $window = 2;
                $startP = max(1, $page - $window);
                $endP = min($logsPages, $page + $window);
                ?>
                <?php if ($page > 1): ?>
                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($logsPageUrl($prevPage)) ?>">Prev</a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-secondary" disabled>Prev</button>
                <?php endif; ?>
                <?php if ($startP > 1): ?>
                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($logsPageUrl(1)) ?>">1</a>
                    <?php if ($startP > 2): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $startP; $p <= $endP; $p++): ?>
                    <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"
                       href="<?= htmlspecialchars($logsPageUrl($p)) ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($endP < $logsPages): ?>
                    <?php if ($endP < $logsPages - 1): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($logsPageUrl($logsPages)) ?>"><?= (int)$logsPages ?></a>
                <?php endif; ?>
                <?php if ($page < $logsPages): ?>
                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($logsPageUrl($nextPage)) ?>">Next</a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-secondary" disabled>Next</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
