<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\AppLogger;
use App\Config\AppConfig;

AuthService::requireRole(AppConfig::ROLE_SUPER_ADMIN);

$files = AppLogger::listLogFiles();
$file = trim((string)($_GET['file'] ?? ''));
if ($file === '' || AppLogger::resolveLogFile($file) === null) {
    $file = $files[0] ?? '';
}

$level = strtoupper(trim((string)($_GET['level'] ?? '')));
$allowedLevels = ['', 'INFO', 'AUDIT', 'WARNING', 'ERROR', 'FAILED'];
if (!in_array($level, $allowedLevels, true)) {
    $level = '';
}
$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 50) {
    $limit = 50;
}
if ($limit > 1000) {
    $limit = 1000;
}

$entries = $file !== '' ? AppLogger::readEntries($file, $limit, $level, $q) : [];

$pageTitle = 'System Logs';
require_once __DIR__ . '/includes/header.php';

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
            <a href="admin_logs.php<?= $file !== '' ? ('?file=' . rawurlencode($file)) : '' ?>" class="btn btn-secondary btn-sm">Refresh</a>
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
                    <label class="form-label" for="limit">Rows</label>
                    <select name="limit" id="limit" class="form-select form-select-sm">
                        <?php foreach ([100, 200, 500, 1000] as $n): ?>
                            <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?></option>
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
            <p class="logs-filters-meta"><?= count($entries) ?> entries<?= $file !== '' ? ' · ' . htmlspecialchars($file) : '' ?> · newest first · scroll sideways for all columns</p>
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
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
