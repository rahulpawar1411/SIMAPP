<?php
/**
 * Approvals (Super Admin).
 * Review pending edit / KYC requests, filter by zone/city/tech, paginate lists.
 * Approve or reject can offer a short Undo in the toast.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\UndoService;
use App\Config\AppConfig;
use App\Models\AppNotification;
use App\Models\Freezer;

// Admin only — Approvals (edit access requests)
AuthService::requireRole(AppConfig::ROLE_SUPER_ADMIN);
$currentUser = AuthService::currentUser();
$csrfToken = AuthService::generateCsrfToken();

$filterZone = strtoupper(trim((string)($_GET['zone'] ?? $_POST['filter_zone'] ?? '')));
$filterCity = trim((string)($_GET['city'] ?? $_POST['filter_city'] ?? ''));
$filterTechId = (int)($_GET['tech'] ?? $_POST['filter_tech'] ?? 0);
if ($filterZone === 'ALL') {
    $filterZone = '';
}
if (strcasecmp($filterCity, 'All') === 0) {
    $filterCity = '';
}

$zoneOptions = Freezer::dashboardZones();
$cityOptions = Freezer::dashboardCities($filterZone !== '' ? $filterZone : null);
if ($filterCity !== '' && !in_array($filterCity, $cityOptions, true)) {
    $filterCity = '';
}

$approvalFilters = [
    'zone' => $filterZone,
    'city' => $filterCity,
    'technician_id' => $filterTechId,
];
$hasApprovalFilter = ($filterZone !== '' || $filterCity !== '' || $filterTechId > 0);

$filterQuery = static function (array $extra = []) use ($filterZone, $filterCity, $filterTechId): string {
    $params = [];
    if ($filterZone !== '') {
        $params['zone'] = $filterZone;
    }
    if ($filterCity !== '') {
        $params['city'] = $filterCity;
    }
    if ($filterTechId > 0) {
        $params['tech'] = $filterTechId;
    }
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '' || $v === 0 || $v === '0') {
            continue;
        }
        $params[$k] = $v;
    }
    return $params === [] ? '' : ('?' . http_build_query($params));
};

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['notif_action']) || isset($_POST['bulk_action']))) {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $adminId = (int)($currentUser['id'] ?? 0);
        $notes = trim((string)($_POST['review_notes'] ?? $_POST['bulk_notes'] ?? ''));
        try {
            // Bulk approve / reject selected rows
            if (isset($_POST['bulk_action'])) {
                $bulkAction = strtolower(trim((string)$_POST['bulk_action']));
                $ids = $_POST['notif_ids'] ?? [];
                if (!is_array($ids)) {
                    $ids = [];
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
                if ($ids === []) {
                    throw new Exception('Select at least one request.');
                }
                if (!in_array($bulkAction, ['approve', 'reject'], true)) {
                    throw new Exception('Unknown bulk action.');
                }
                $ok = 0;
                $fail = 0;
                $undoItems = [];
                foreach ($ids as $nid) {
                    try {
                        $before = AppNotification::findById($nid);
                        if (!$before) {
                            $fail++;
                            continue;
                        }
                        if ($bulkAction === 'approve') {
                            $result = AppNotification::approve($nid, $adminId, $notes !== '' ? $notes : null);
                            $snap = UndoService::buildApprovalUndo($before, 'approve', $result);
                        } else {
                            AppNotification::reject($nid, $adminId, $notes !== '' ? $notes : null);
                            $snap = UndoService::buildApprovalUndo($before, 'reject', ['undoable' => true]);
                        }
                        if ($snap) {
                            $undoItems[] = $snap;
                        }
                        $ok++;
                    } catch (Throwable $e) {
                        $fail++;
                        \App\Services\AppLogger::exception($e, 'approvals.bulk_item', ['notif_id' => $nid]);
                    }
                }
                if ($ok === 0) {
                    throw new Exception('No requests were updated. They may already be decided.');
                }
                $flash = ucfirst($bulkAction) . 'd ' . $ok . ' request' . ($ok === 1 ? '' : 's') . '.';
                if ($fail > 0) {
                    $flash .= ' ' . $fail . ' skipped.';
                }
                $q = $filterQuery();
                $sep = $q === '' ? '?' : '&';
                $loc = 'notifications.php' . $q . $sep . 'ok=' . rawurlencode($flash);
                if ($undoItems !== []) {
                    $undoToken = UndoService::remember('approval_bulk', ['items' => $undoItems]);
                    $loc .= '&undo=' . rawurlencode($undoToken);
                }
                header('Location: ' . $loc);
                exit;
            }

            $notifId = (int)($_POST['notif_id'] ?? 0);
            $action = strtolower(trim((string)($_POST['notif_action'] ?? '')));
            $undoToken = null;
            $before = AppNotification::findById($notifId);
            if ($action === 'approve') {
                $result = AppNotification::approve($notifId, $adminId, $notes !== '' ? $notes : null);
                if (($result['type'] ?? '') === AppNotification::TYPE_PROFILE_EDIT) {
                    $flash = 'Edit access approved. Technician can now edit this profile (one save / 24 hours).';
                } elseif (!empty($result['outlet_id'])) {
                    $flash = 'Approved. New Store ID ' . $result['outlet_id'] . ' linked to freezer profile.';
                } else {
                    $flash = 'Request approved.';
                }
                if ($before) {
                    $snap = UndoService::buildApprovalUndo($before, 'approve', $result);
                    if ($snap) {
                        $undoToken = UndoService::remember('approval_decision', $snap);
                    }
                }
            } elseif ($action === 'reject') {
                AppNotification::reject($notifId, $adminId, $notes !== '' ? $notes : null);
                $flash = 'Request rejected.';
                if ($before) {
                    $snap = UndoService::buildApprovalUndo($before, 'reject', ['undoable' => true]);
                    if ($snap) {
                        $undoToken = UndoService::remember('approval_decision', $snap);
                    }
                }
            } else {
                $error = 'Unknown action.';
            }
            if ($flash && $error === null) {
                $q = $filterQuery();
                $sep = $q === '' ? '?' : '&';
                $loc = 'notifications.php' . $q . $sep . 'ok=' . rawurlencode($flash);
                if ($undoToken) {
                    $loc .= '&undo=' . rawurlencode($undoToken);
                }
                header('Location: ' . $loc);
                exit;
            }
        } catch (Throwable $e) {
            \App\Services\AppLogger::exception($e, 'approvals.action');
            $error = \App\Services\AppLogger::friendlyMessage($e, 'Could not update this request. Please try again.');
        }
    }
}

if (!empty($_GET['ok'])) {
    $flash = trim((string)$_GET['ok']);
}

// Approvals lists (pending + recent decisions), paginated for mobile/desktop tables
$pendingApprovals = [];
$pendingCount = 0;
$pendingCountAll = 0;
$recentDecisions = [];
$recentDecisionsTotal = 0;
$techOptions = [];
$expiredCount = 0;
$pendingLimit = 20;
$decisionsLimit = 20;
$pendingPage = max(1, (int)($_GET['ppage'] ?? 1));
$decisionsPage = max(1, (int)($_GET['dpage'] ?? 1));
$pendingPages = 1;
$decisionsPages = 1;
$pendingOffset = 0;
$decisionsOffset = 0;
try {
    // Auto-close very old pending edit requests (7+ days)
    $expiredCount = AppNotification::expireStalePendingEdits(7);
    $pendingCountAll = AppNotification::countPendingApprovals();
    $pendingCount = AppNotification::countPendingApprovals($approvalFilters);
    $pendingPages = max(1, (int)ceil($pendingCount / $pendingLimit));
    if ($pendingPage > $pendingPages) {
        $pendingPage = $pendingPages;
    }
    $pendingOffset = ($pendingPage - 1) * $pendingLimit;
    $pendingApprovals = AppNotification::listPendingApprovals($pendingLimit, $approvalFilters, $pendingOffset);

    $recentDecisionsTotal = AppNotification::countRecentDecisions($approvalFilters);
    $decisionsPages = max(1, (int)ceil($recentDecisionsTotal / $decisionsLimit));
    if ($decisionsPage > $decisionsPages) {
        $decisionsPage = $decisionsPages;
    }
    $decisionsOffset = ($decisionsPage - 1) * $decisionsLimit;
    $recentDecisions = AppNotification::listRecentDecisions($decisionsLimit, $approvalFilters, $decisionsOffset);
    $techOptions = AppNotification::listRequesterTechnicians();
} catch (Throwable $e) {
    // Keep empty lists if DB fails — page still loads with a clear message
    \App\Services\AppLogger::exception($e, 'approvals.list');
    $error = $error ?: \App\Services\AppLogger::friendlyMessage(
        $e,
        'Could not load approvals right now. Please refresh the page.'
    );
    $pendingApprovals = [];
    $recentDecisions = [];
    $techOptions = [];
    $pendingCount = 0;
    $recentDecisionsTotal = 0;
    $pendingPages = 1;
    $decisionsPages = 1;
    $pendingOffset = 0;
    $decisionsOffset = 0;
}
$pendingFrom = $pendingCount === 0 ? 0 : ($pendingOffset + 1);
$pendingTo = min($pendingOffset + count($pendingApprovals), $pendingCount);
$decisionsFrom = $recentDecisionsTotal === 0 ? 0 : ($decisionsOffset + 1);
$decisionsTo = min($decisionsOffset + count($recentDecisions), $recentDecisionsTotal);

$approvalsPageUrl = static function (string $which, int $page) use ($filterQuery, $pendingPage, $decisionsPage): string {
    $page = max(1, $page);
    $extra = [
        'ppage' => $which === 'pending' ? $page : $pendingPage,
        'dpage' => $which === 'decisions' ? $page : $decisionsPage,
    ];
    if ((int)$extra['ppage'] <= 1) {
        unset($extra['ppage']);
    }
    if ((int)$extra['dpage'] <= 1) {
        unset($extra['dpage']);
    }
    return 'notifications.php' . $filterQuery($extra);
};
if ($expiredCount > 0 && $flash === null) {
    $flash = $expiredCount . ' stale edit request' . ($expiredCount === 1 ? '' : 's') . ' auto-expired (7+ days).';
}

$pageTitle = 'Approvals';
require_once __DIR__ . '/includes/header.php';
?>

<div class="logs-page users-page notif-excel-page">
    <div class="cfg-toolbar logs-toolbar-compact">
        <div class="cfg-toolbar-left">
            <h1 class="cfg-toolbar-title">Approvals</h1>
            <?php if ($pendingCountAll > 0): ?>
                <span class="badge badge-warning"><?= number_format($pendingCountAll) ?> pending</span>
            <?php else: ?>
                <span class="badge badge-secondary">No pending</span>
            <?php endif; ?>
            <?php if ($hasApprovalFilter): ?>
                <span class="badge badge-primary"><?= number_format($pendingCount) ?> match filter</span>
            <?php endif; ?>
        </div>
        <div class="logs-toolbar-actions">
            <a href="notifications.php<?= htmlspecialchars($filterQuery()) ?>" class="btn btn-secondary btn-sm">Refresh</a>
        </div>
    </div>

    <div class="card logs-filters-card logs-filters-compact">
        <div class="card-body">
            <form method="GET" action="notifications.php" class="approvals-filter-form" id="approvals-filter-form">
                <div class="approvals-filter-row">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="appr-zone">Zone</label>
                        <select id="appr-zone" name="zone" class="form-control form-control-sm"
                                onchange="document.getElementById('appr-city').value=''; this.form.submit();">
                            <option value="">All zones</option>
                            <?php foreach ($zoneOptions as $z): ?>
                                <option value="<?= htmlspecialchars($z) ?>" <?= $filterZone === $z ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($z) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="appr-city">City</label>
                        <select id="appr-city" name="city" class="form-control form-control-sm" onchange="this.form.submit();">
                            <option value="">All cities</option>
                            <?php foreach ($cityOptions as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>" <?= $filterCity === $c ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="appr-tech">Technician</label>
                        <select id="appr-tech" name="tech" class="form-control form-control-sm" onchange="this.form.submit();">
                            <option value="">All technicians</option>
                            <?php foreach ($techOptions as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= $filterTechId === (int)$t['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)($t['name'] ?? ('#' . $t['id']))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($hasApprovalFilter): ?>
                        <div class="form-group" style="margin:0;align-self:flex-end;">
                            <a href="notifications.php" class="btn btn-secondary btn-sm">Clear</a>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
            <p class="logs-filters-meta" style="margin:0.65rem 0 0;">
                Approve = unlock edit (1 save / 24h) · Reject = no access ·
                <?= number_format($pendingCount) ?> pending · click a row for details
            </p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-success app-flash-toast-only" style="margin-bottom:1rem;" data-app-flash="<?= htmlspecialchars($flash) ?>" data-app-flash-variant="success"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card logs-entries-card">
        <div class="card-body logs-entries-body">
            <?php if (empty($pendingApprovals)): ?>
                <p class="users-empty">No pending notifications. All clear.</p>
            <?php else: ?>
                <form method="POST" action="notifications.php<?= htmlspecialchars($filterQuery()) ?>" id="bulk-approvals-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="filter_zone" value="<?= htmlspecialchars($filterZone) ?>">
                    <input type="hidden" name="filter_city" value="<?= htmlspecialchars($filterCity) ?>">
                    <input type="hidden" name="filter_tech" value="<?= (int)$filterTechId ?>">
                    <div class="approvals-bulk-bar">
                        <label class="approvals-bulk-select-all">
                            <input type="checkbox" id="bulk-select-all" aria-label="Select all">
                            <span>Select all</span>
                        </label>
                        <input type="text" name="bulk_notes" class="form-control form-control-sm" placeholder="Optional note for bulk action" style="max-width:220px;">
                        <button type="submit" name="bulk_action" value="approve" class="btn btn-primary btn-sm"
                                onclick="return confirmBulk('approve');">Approve selected</button>
                        <button type="submit" name="bulk_action" value="reject" class="btn btn-secondary btn-sm"
                                onclick="return confirmBulk('reject');">Reject selected</button>
                        <span class="approvals-bulk-count" id="bulk-selected-count">0 selected</span>
                    </div>
                <div class="logs-table-wrap">
                    <table class="logs-excel notif-excel">
                        <thead>
                            <tr>
                                <th class="notif-check-col">Sel</th>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Freezer</th>
                                <th>Profile / Store</th>
                                <th>Zone</th>
                                <th>City</th>
                                <th>Technician</th>
                                <th>Remark</th>
                                <th>Note</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingApprovals as $n):
                                $payload = AppNotification::decodePayload($n);
                                $isEdit = (($n['type'] ?? '') === AppNotification::TYPE_PROFILE_EDIT);
                                $editRemark = trim((string)($payload['remark'] ?? ''));
                                $isAccessRequest = $isEdit && (($payload['request_kind'] ?? '') === 'edit_access' || $editRemark !== '');
                                $typeLabel = $isAccessRequest ? 'Edit access' : ($isEdit ? 'Profile edit' : 'KYC move');
                                $freezerId = (string)($n['unique_freezer_id'] ?? '');
                                $tech = (string)($n['created_by_name'] ?? 'Technician');
                                $title = (string)($n['title'] ?? 'Request');
                                $time = (string)($n['created_at'] ?? '');
                                $message = (string)($n['message'] ?? '');
                                $remarkCell = $editRemark !== '' ? $editRemark : $message;
                                $fid = (int)($n['freezer_id'] ?? 0);
                                $profileUrl = 'profile_view.php?id=' . $fid;
                                $storePayload = is_array($payload['store'] ?? null) ? $payload['store'] : [];
                                $freezerPayload = is_array($payload['freezer'] ?? null) ? $payload['freezer'] : [];
                                $kycPayload = is_array($payload['kyc'] ?? null) ? $payload['kyc'] : [];
                                $newStore = is_array($payload['new_store'] ?? null) ? $payload['new_store'] : [];
                                $profileStore = (string)($n['profile_store_name'] ?? '');
                                $profileOutlet = (string)($n['profile_outlet_id'] ?? '');
                                $profileCity = (string)($n['profile_city'] ?? '');
                                $profileZone = (string)($n['profile_zone'] ?? '');
                                if ($profileStore === '') {
                                    $profileStore = (string)($storePayload['store_name'] ?? $newStore['store_name'] ?? $kycPayload['store_name'] ?? '');
                                }
                                if ($profileOutlet === '') {
                                    $profileOutlet = (string)($storePayload['outlet_id'] ?? $newStore['outlet_id'] ?? '');
                                }
                                if ($profileCity === '') {
                                    $profileCity = (string)($storePayload['city'] ?? $newStore['city'] ?? '');
                                }
                                if ($profileZone === '') {
                                    $profileZone = (string)($storePayload['zone'] ?? $newStore['zone'] ?? '');
                                }
                                $detailPayload = [
                                    'id' => (int)$n['id'],
                                    'form_id' => 'notif-form-' . (int)$n['id'],
                                    'title' => $title !== '' ? $title : 'Request',
                                    'type_label' => $typeLabel,
                                    'status' => 'Pending',
                                    'time' => $time,
                                    'unique_freezer_id' => $freezerId,
                                    'freezer_id' => (string)$fid,
                                    'profile_url' => $profileUrl,
                                    'technician' => $tech,
                                    'remark' => $editRemark,
                                    'message' => $message,
                                    'request_kind' => (string)($payload['request_kind'] ?? ''),
                                    'store_name' => $profileStore,
                                    'outlet_id' => $profileOutlet,
                                    'city' => $profileCity,
                                    'zone' => $profileZone,
                                    'phone' => (string)($storePayload['phone'] ?? $newStore['phone'] ?? ''),
                                    'address' => (string)($storePayload['address'] ?? $newStore['address'] ?? ''),
                                    'image_store_front' => (string)($kycPayload['image_store_front'] ?? $newStore['image_store_front'] ?? $payload['image_store_front'] ?? ''),
                                    'image_freezer' => (string)($kycPayload['image_freezer'] ?? $payload['image_freezer'] ?? ''),
                                    'notes' => (string)($kycPayload['notes'] ?? $payload['notes'] ?? ''),
                                    'has_field_changes' => ($storePayload !== [] || $freezerPayload !== []),
                                    'steps' => $isAccessRequest
                                        ? [
                                            'Technician sent a remark to request edit access.',
                                            'Approve = unlock edit for one save within 24 hours.',
                                            'Reject = technician cannot edit this profile.',
                                        ]
                                        : ($isEdit
                                            ? [
                                                'Technician submitted profile changes for approval.',
                                                'Approve applies the queued store/freezer updates.',
                                                'Reject discards the request.',
                                            ]
                                            : [
                                                'Technician requested a KYC store move.',
                                                'Approve links the new store to the freezer.',
                                                'Reject keeps the current store assignment.',
                                            ]),
                                ];
                                ?>
                                <tr class="kyc-act-row notif-act-row"
                                    tabindex="0"
                                    role="button"
                                    aria-label="Open notification details"
                                    data-detail="<?= htmlspecialchars(json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8') ?>">
                                    <td class="notif-check-col" data-stop-row-open>
                                        <input type="checkbox" class="bulk-notif-check" name="notif_ids[]" value="<?= (int)$n['id'] ?>" form="bulk-approvals-form" aria-label="Select request">
                                    </td>
                                    <td title="<?= htmlspecialchars($time) ?>"><?= htmlspecialchars($time !== '' ? $time : '—') ?></td>
                                    <td title="<?= htmlspecialchars($typeLabel) ?>">
                                        <span class="badge badge-sm badge-primary"><?= htmlspecialchars($typeLabel) ?></span>
                                    </td>
                                    <td title="Pending">
                                        <span class="badge badge-sm badge-warning">Pending</span>
                                    </td>
                                    <td title="<?= htmlspecialchars($freezerId) ?>">
                                        <a class="kyc-act-row-link" href="<?= htmlspecialchars($profileUrl) ?>">
                                            <?= htmlspecialchars($freezerId !== '' ? $freezerId : ('#' . $fid)) ?>
                                        </a>
                                    </td>
                                    <td title="<?= htmlspecialchars($profileStore . ($profileOutlet !== '' ? ' · ' . $profileOutlet : '')) ?>">
                                        <?= htmlspecialchars($profileStore !== '' ? $profileStore : '—') ?>
                                        <?php if ($profileOutlet !== ''): ?>
                                            <div class="notif-day-sub"><?= htmlspecialchars($profileOutlet) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($profileZone !== '' ? $profileZone : '—') ?></td>
                                    <td><?= htmlspecialchars($profileCity !== '' ? $profileCity : '—') ?></td>
                                    <td title="<?= htmlspecialchars($tech) ?>"><?= htmlspecialchars($tech !== '' ? $tech : '—') ?></td>
                                    <td title="<?= htmlspecialchars($remarkCell) ?>"><?= htmlspecialchars($remarkCell !== '' ? $remarkCell : '—') ?></td>
                                    <td class="notif-excel-note-cell" data-stop-row-open>
                                        <form method="POST" class="notif-excel-row-form" id="notif-form-<?= (int)$n['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="notif_id" value="<?= (int)$n['id'] ?>">
                                            <input type="hidden" name="filter_zone" value="<?= htmlspecialchars($filterZone) ?>">
                                            <input type="hidden" name="filter_city" value="<?= htmlspecialchars($filterCity) ?>">
                                            <input type="hidden" name="filter_tech" value="<?= (int)$filterTechId ?>">
                                            <input type="text" name="review_notes" class="form-control form-control-sm" placeholder="Optional note" title="Optional review note">
                                        </form>
                                    </td>
                                    <td class="notif-excel-actions-cell" data-stop-row-open>
                                        <div class="notif-excel-actions">
                                            <button type="submit" form="notif-form-<?= (int)$n['id'] ?>" name="notif_action" value="approve" class="btn btn-primary btn-sm">Approve</button>
                                            <button type="submit" form="notif-form-<?= (int)$n['id'] ?>" name="notif_action" value="reject" class="btn btn-secondary btn-sm">Reject</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($pendingCount > 0): ?>
        <div class="card-footer profiles-pagination" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
            <div style="font-size:0.85rem; color:#64748b;">
                Showing <strong><?= (int)$pendingFrom ?>–<?= (int)$pendingTo ?></strong> of <strong><?= number_format($pendingCount) ?></strong>
                · <?= (int)$pendingLimit ?> per page
                <?php if ($pendingPages > 1): ?>
                    · Page <strong><?= (int)$pendingPage ?></strong> of <strong><?= (int)$pendingPages ?></strong>
                <?php endif; ?>
            </div>
            <?php if ($pendingPages > 1): ?>
            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                <?php if ($pendingPage > 1): ?>
                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($approvalsPageUrl('pending', $pendingPage - 1)) ?>">Prev</a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-secondary" disabled>Prev</button>
                <?php endif; ?>
                <?php if ($pendingPage < $pendingPages): ?>
                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($approvalsPageUrl('pending', $pendingPage + 1)) ?>">Next</a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-secondary" disabled>Next</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="card logs-entries-card" style="margin-top:1rem;" id="recent-decisions">
        <div class="card-header notif-day-head">
            <div class="card-title"><span>Recent decisions</span></div>
            <div class="notif-day-counts">
                <span class="badge badge-secondary"><?= number_format($recentDecisionsTotal) ?> total</span>
            </div>
        </div>
        <div class="card-body logs-entries-body">
            <?php if (empty($recentDecisions)): ?>
                <p class="users-empty">No approved/rejected history yet.</p>
            <?php else: ?>
                <div class="logs-table-wrap">
                    <table class="logs-excel notif-excel">
                        <thead>
                            <tr>
                                <th>Decided</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Freezer</th>
                                <th>Profile / Store</th>
                                <th>Zone</th>
                                <th>City</th>
                                <th>Technician</th>
                                <th>Remark</th>
                                <th>Admin note</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentDecisions as $n):
                                $payload = AppNotification::decodePayload($n);
                                $isEdit = (($n['type'] ?? '') === AppNotification::TYPE_PROFILE_EDIT);
                                $editRemark = trim((string)($payload['remark'] ?? ''));
                                $isAccessRequest = $isEdit && (($payload['request_kind'] ?? '') === 'edit_access' || $editRemark !== '');
                                $typeLabel = $isAccessRequest ? 'Edit access' : ($isEdit ? 'Profile edit' : 'KYC move');
                                $status = strtolower((string)($n['status'] ?? ''));
                                $statusClass = $status === 'approved' ? 'badge-success' : 'badge-danger';
                                $freezerId = (string)($n['unique_freezer_id'] ?? '');
                                $tech = (string)($n['created_by_name'] ?? 'Technician');
                                $decidedAt = (string)($n['reviewed_at'] ?? $n['created_at'] ?? '');
                                $adminNote = (string)($n['review_notes'] ?? '');
                                $reviewer = (string)($n['reviewed_by_name'] ?? '');
                                $fid = (int)($n['freezer_id'] ?? 0);
                                $profileStore = (string)($n['profile_store_name'] ?? '');
                                $profileOutlet = (string)($n['profile_outlet_id'] ?? '');
                                $profileCity = (string)($n['profile_city'] ?? '');
                                $profileZone = (string)($n['profile_zone'] ?? '');
                                ?>
                                <tr>
                                    <td title="<?= htmlspecialchars($decidedAt) ?>"><?= htmlspecialchars($decidedAt !== '' ? $decidedAt : '—') ?></td>
                                    <td>
                                        <span class="badge badge-sm <?= $statusClass ?>"><?= htmlspecialchars(ucfirst($status !== '' ? $status : '—')) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($typeLabel) ?></td>
                                    <td>
                                        <a href="profile_view.php?id=<?= $fid ?>">
                                            <?= htmlspecialchars($freezerId !== '' ? $freezerId : ('#' . $fid)) ?>
                                        </a>
                                    </td>
                                    <td title="<?= htmlspecialchars($profileStore . ($profileOutlet !== '' ? ' · ' . $profileOutlet : '')) ?>">
                                        <?= htmlspecialchars($profileStore !== '' ? $profileStore : '—') ?>
                                        <?php if ($profileOutlet !== ''): ?>
                                            <div class="notif-day-sub"><?= htmlspecialchars($profileOutlet) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($profileZone !== '' ? $profileZone : '—') ?></td>
                                    <td><?= htmlspecialchars($profileCity !== '' ? $profileCity : '—') ?></td>
                                    <td><?= htmlspecialchars($tech !== '' ? $tech : '—') ?></td>
                                    <td title="<?= htmlspecialchars($editRemark !== '' ? $editRemark : (string)($n['message'] ?? '')) ?>">
                                        <?= htmlspecialchars($editRemark !== '' ? $editRemark : ((string)($n['message'] ?? '') !== '' ? (string)$n['message'] : '—')) ?>
                                    </td>
                                    <td><?= htmlspecialchars($adminNote !== '' ? $adminNote : '—') ?></td>
                                    <td><?= htmlspecialchars($reviewer !== '' ? $reviewer : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($recentDecisionsTotal > 0): ?>
        <div class="card-footer profiles-pagination" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
            <div style="font-size:0.85rem; color:#64748b;">
                Showing <strong><?= (int)$decisionsFrom ?>–<?= (int)$decisionsTo ?></strong> of <strong><?= number_format($recentDecisionsTotal) ?></strong>
                · <?= (int)$decisionsLimit ?> per page
                <?php if ($decisionsPages > 1): ?>
                    · Page <strong><?= (int)$decisionsPage ?></strong> of <strong><?= (int)$decisionsPages ?></strong>
                <?php endif; ?>
            </div>
            <?php if ($decisionsPages > 1): ?>
            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                <?php if ($decisionsPage > 1): ?>
                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($approvalsPageUrl('decisions', $decisionsPage - 1)) ?>#recent-decisions">Prev</a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-secondary" disabled>Prev</button>
                <?php endif; ?>
                <?php if ($decisionsPage < $decisionsPages): ?>
                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($approvalsPageUrl('decisions', $decisionsPage + 1)) ?>#recent-decisions">Next</a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-secondary" disabled>Next</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="kyc-act-modal" id="notif-act-modal" hidden aria-hidden="true">
    <div class="kyc-act-modal-backdrop" data-notif-act-close></div>
    <div class="kyc-act-modal-sheet" role="dialog" aria-modal="true" aria-labelledby="notif-act-modal-title">
        <header class="kyc-act-modal-head">
            <div>
                <p class="kyc-act-modal-kicker">Approval</p>
                <h2 id="notif-act-modal-title">Details</h2>
            </div>
            <button type="button" class="kyc-act-modal-close" data-notif-act-close aria-label="Close">&times;</button>
        </header>
        <div class="kyc-act-modal-body" id="notif-act-modal-body"></div>
        <footer class="kyc-act-modal-foot" id="notif-act-modal-foot"></footer>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('notif-act-modal');
    var titleEl = document.getElementById('notif-act-modal-title');
    var bodyEl = document.getElementById('notif-act-modal-body');
    var footEl = document.getElementById('notif-act-modal-foot');
    if (!modal || !titleEl || !bodyEl || !footEl) return;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function dash(v) {
        v = String(v == null ? '' : v).trim();
        return v !== '' ? v : '—';
    }
    function kv(label, valueHtml) {
        return '<div class="kyc-act-kv"><span>' + esc(label) + '</span><strong>' + valueHtml + '</strong></div>';
    }

    function openModal(data) {
        var freezerLabel = data.unique_freezer_id || (data.freezer_id ? ('#' + data.freezer_id) : '—');
        titleEl.textContent = data.title || 'Notification';

        var badges = '<div class="notif-approval-badges">' +
            '<span class="badge badge-primary">' + esc(data.type_label || 'Request') + '</span>' +
            '<span class="badge badge-warning">' + esc(data.status || 'Pending') + '</span>' +
            '</div>';

        var grid = '<div class="kyc-act-kv-grid">' +
            kv('Freezer', '<a class="profile-kv-mono" href="' + esc(data.profile_url || '#') + '">' + esc(freezerLabel) + '</a>') +
            kv('Technician', esc(dash(data.technician))) +
            kv('Time', esc(dash(data.time))) +
            kv('Request', esc(dash(data.request_kind || data.type_label))) +
            '</div>';

        if (data.store_name || data.outlet_id || data.city || data.zone || data.phone || data.address) {
            grid += '<div class="kyc-act-kv-grid">' +
                kv('Store', esc(dash(data.store_name))) +
                kv('Outlet ID', esc(dash(data.outlet_id))) +
                kv('City', esc(dash(data.city))) +
                kv('Zone', esc(dash(data.zone))) +
                (data.phone ? kv('Phone', esc(data.phone)) : '') +
                (data.address ? kv('Address', esc(data.address)) : '') +
                '</div>';
        }

        var remarkText = (data.remark && String(data.remark).trim()) || (data.message && String(data.message).trim()) || (data.notes && String(data.notes).trim()) || '';
        var remark = remarkText
            ? '<div class="notif-remark-box"><div class="notif-remark-label">Remark / message</div><div class="notif-remark-text">' + esc(remarkText) + '</div></div>'
            : '';

        var photos = '';
        var photoBits = [];
        if (data.image_store_front) {
            photoBits.push('<a href="' + esc(data.image_store_front) + '" target="_blank" rel="noopener"><img src="' + esc(data.image_store_front) + '" alt="Store photo"><span>Store</span></a>');
        }
        if (data.image_freezer) {
            photoBits.push('<a href="' + esc(data.image_freezer) + '" target="_blank" rel="noopener"><img src="' + esc(data.image_freezer) + '" alt="Freezer photo"><span>Freezer</span></a>');
        }
        if (photoBits.length) {
            photos = '<div class="kyc-act-photos"><div class="notif-remark-label">Photos</div><div class="kyc-act-photo-grid">' + photoBits.join('') + '</div></div>';
        }

        var steps = '';
        if (Array.isArray(data.steps) && data.steps.length) {
            steps = '<ol class="notif-approval-steps">' + data.steps.map(function (s) {
                return '<li>' + esc(s) + '</li>';
            }).join('') + '</ol>';
        }

        bodyEl.innerHTML = badges +
            '<p class="kyc-act-modal-lead">' + esc(freezerLabel) + ' · ' + esc(dash(data.technician)) + '</p>' +
            grid + remark + photos + steps +
            '<div class="form-group" style="margin:0.85rem 0 0;">' +
                '<label class="form-label" for="notif-modal-notes">Admin note (optional)</label>' +
                '<input type="text" id="notif-modal-notes" class="form-control" placeholder="Optional note for approve / reject">' +
            '</div>';

        footEl.innerHTML =
            '<button type="button" class="btn btn-secondary" data-notif-act-close>Close</button>' +
            '<a class="btn btn-outline" href="' + esc(data.profile_url || '#') + '">Open profile</a>' +
            '<button type="button" class="btn btn-secondary" data-notif-act-submit="reject" data-form="' + esc(data.form_id || '') + '">Reject</button>' +
            '<button type="button" class="btn btn-primary" data-notif-act-submit="approve" data-form="' + esc(data.form_id || '') + '">Approve</button>';

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(function () { modal.classList.add('is-open'); });
        document.body.classList.add('kyc-act-modal-open');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('kyc-act-modal-open');
        setTimeout(function () {
            if (!modal.classList.contains('is-open')) {
                modal.hidden = true;
                bodyEl.innerHTML = '';
                footEl.innerHTML = '';
            }
        }, 200);
    }

    function submitFromModal(action, formId) {
        var form = document.getElementById(formId);
        if (!form) return;
        var noteInput = document.getElementById('notif-modal-notes');
        var rowNote = form.querySelector('input[name="review_notes"]');
        if (noteInput && rowNote) {
            rowNote.value = noteInput.value || '';
        }
        var actionInput = form.querySelector('input[name="notif_action"][data-modal-action]');
        if (!actionInput) {
            actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'notif_action';
            actionInput.setAttribute('data-modal-action', '1');
            form.appendChild(actionInput);
        }
        actionInput.value = action;
        form.submit();
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-notif-act-close]')) {
            closeModal();
            return;
        }
        var submitBtn = e.target.closest('[data-notif-act-submit]');
        if (submitBtn) {
            e.preventDefault();
            submitFromModal(submitBtn.getAttribute('data-notif-act-submit') || '', submitBtn.getAttribute('data-form') || '');
            return;
        }
        if (e.target.closest('.kyc-act-row-link') || e.target.closest('[data-stop-row-open]')) {
            return;
        }
        var row = e.target.closest('.notif-act-row');
        if (!row) return;
        try {
            openModal(JSON.parse(row.getAttribute('data-detail') || '{}'));
        } catch (err) {}
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
            return;
        }
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var row = e.target.closest('.notif-act-row');
        if (!row || e.target.closest('.kyc-act-row-link') || e.target.closest('[data-stop-row-open]')) return;
        e.preventDefault();
        try {
            openModal(JSON.parse(row.getAttribute('data-detail') || '{}'));
        } catch (err) {}
    });

    // Bulk select
    var selectAll = document.getElementById('bulk-select-all');
    var countEl = document.getElementById('bulk-selected-count');
    function allChecks() {
        return Array.prototype.slice.call(document.querySelectorAll('.bulk-notif-check'));
    }
    function updateBulkCount() {
        var n = allChecks().filter(function (c) { return c.checked; }).length;
        if (countEl) countEl.textContent = n + ' selected';
        if (selectAll) {
            var total = allChecks().length;
            selectAll.checked = total > 0 && n === total;
            selectAll.indeterminate = n > 0 && n < total;
        }
    }
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            allChecks().forEach(function (c) { c.checked = selectAll.checked; });
            updateBulkCount();
        });
    }
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('bulk-notif-check')) {
            updateBulkCount();
        }
    });
    updateBulkCount();

    window.confirmBulk = function (action) {
        var n = allChecks().filter(function (c) { return c.checked; }).length;
        if (!n) {
            alert('Select at least one request.');
            return false;
        }
        return confirm(action === 'approve'
            ? ('Approve ' + n + ' selected request(s)?')
            : ('Reject ' + n + ' selected request(s)?'));
    };
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
