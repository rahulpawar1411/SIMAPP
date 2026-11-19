<?php
/**
 * Manage Users (Super Admin only).
 * - Create / update accounts
 * - Edit, On/Off, Delete require admin password confirmation
 * - Success actions show a toast with Undo (short time window)
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\AppLogger;
use App\Services\UndoService;
use App\Models\User;
use App\Config\AppConfig;

// Only Super Admin can open this page
AuthService::requireRole(AppConfig::ROLE_SUPER_ADMIN);
User::ensureRoleColumn();
$currentUser = AuthService::currentUser();
$csrfToken = AuthService::generateCsrfToken();

$error = null;
$success = null;
$editUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
        AppLogger::failed('users.csrf', $error);
    } else {
        $action = $_POST['action'] ?? 'create';
        try {

        if ($action === 'create') {
            $result = User::create([
                'name' => $_POST['name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'password' => $_POST['password'] ?? '',
                'role' => $_POST['role'] ?? AppConfig::ROLE_SUPER_ADMIN,
                'phone' => $_POST['phone'] ?? '',
                'zone' => $_POST['zone'] ?? '',
                'city' => $_POST['city'] ?? '',
                'status' => 'active',
            ]);

            if ($result['success']) {
                $success = 'User created successfully. They can now sign in with the email and password you set.';
                AppLogger::audit('users.create', 'User created', [
                    'email' => $_POST['email'] ?? '',
                    'role' => $_POST['role'] ?? '',
                ]);
            } else {
                $error = $result['error'] ?? 'Failed to create user.';
                AppLogger::failed('users.create', $error, ['email' => $_POST['email'] ?? '']);
            }
        } elseif ($action === 'update') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $editUser = $userId > 0 ? User::findById($userId) : null;
            $unlockKey = 'users_edit_unlock_' . $userId;
            $unlockMeta = $_SESSION[$unlockKey] ?? null;
            $unlockOk = is_array($unlockMeta)
                && (int)($unlockMeta['admin_id'] ?? 0) === (int)$currentUser['id']
                && ((int)($unlockMeta['at'] ?? 0) + 1800) >= time();

            if (!$editUser) {
                $error = 'User not found.';
                AppLogger::failed('users.update', $error, ['user_id' => $userId]);
            } elseif (!$unlockOk) {
                unset($_SESSION[$unlockKey]);
                $error = 'Admin password required before editing this user.';
                AppLogger::failed('users.update', $error, ['user_id' => $userId]);
                header('Location: users.php?confirm=edit&id=' . $userId);
                exit;
            } else {
                $before = User::findRawById($userId);
                $result = User::update($userId, [
                    'name' => $_POST['name'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'password' => $_POST['password'] ?? '',
                    'role' => $_POST['role'] ?? $editUser['role'],
                    'phone' => $_POST['phone'] ?? '',
                    'zone' => $_POST['zone'] ?? '',
                    'city' => $_POST['city'] ?? '',
                    'status' => $_POST['status'] ?? $editUser['status'],
                ]);

                if ($result['success']) {
                    unset($_SESSION[$unlockKey]);
                    AppLogger::audit('users.update', 'User updated', ['user_id' => $userId]);
                    $editUser = null;
                    $undo = '';
                    if (is_array($before)) {
                        $undo = UndoService::remember('user_edit', [
                            'user_id' => $userId,
                            'user' => $before,
                            'label' => (string)($before['name'] ?? ('#' . $userId)),
                        ]);
                    }
                    $loc = 'users.php?ok=' . rawurlencode('User updated');
                    if ($undo !== '') {
                        $loc .= '&undo=' . rawurlencode($undo);
                    }
                    header('Location: ' . $loc . '#users-directory');
                    exit;
                }

                $error = $result['error'] ?? 'Failed to update user.';
                AppLogger::failed('users.update', $error, ['user_id' => $userId]);
                $editUser = User::findById($userId) ?: $editUser;
            }
        } elseif ($action === 'unlock_edit') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $adminPassword = trim((string)($_POST['admin_password'] ?? ''));
            $target = $userId > 0 ? User::findById($userId) : null;

            if (!$target) {
                $error = 'User not found.';
                AppLogger::failed('users.unlock_edit', $error, ['user_id' => $userId]);
            } elseif ($adminPassword === '') {
                $error = 'Enter your admin password to open edit.';
                AppLogger::failed('users.unlock_edit', $error, ['user_id' => $userId]);
            } elseif (!AuthService::verifyCurrentUserPassword($adminPassword)) {
                $error = 'Incorrect admin password. Edit was not opened.';
                AppLogger::failed('users.unlock_edit', $error, ['user_id' => $userId]);
            } else {
                $_SESSION['users_edit_unlock_' . $userId] = [
                    'at' => time(),
                    'admin_id' => (int)$currentUser['id'],
                ];
                AppLogger::audit('users.unlock_edit', 'Edit unlocked', ['user_id' => $userId]);
                header('Location: users.php?edit=' . $userId . '#user-form-card');
                exit;
            }
        } elseif ($action === 'toggle_status') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newStatus = ($_POST['status'] ?? '') === 'active' ? 'inactive' : 'active';
            $adminPassword = trim((string)($_POST['admin_password'] ?? ''));

            if ($adminPassword === '') {
                $error = 'Enter your admin password to confirm On / Off.';
                AppLogger::failed('users.toggle_status', $error, ['user_id' => $userId]);
            } elseif (!AuthService::verifyCurrentUserPassword($adminPassword)) {
                $error = 'Incorrect admin password. Status was not changed.';
                AppLogger::failed('users.toggle_status', $error, ['user_id' => $userId]);
            } else {
                $liveAdmin = AuthService::currentUser();
                if ($userId === (int)($liveAdmin['id'] ?? 0)) {
                    $error = 'You cannot deactivate your own account.';
                    AppLogger::failed('users.toggle_status', $error, ['user_id' => $userId]);
                } elseif ($userId > 0) {
                    $before = User::findRawById($userId);
                    $prevStatus = (string)($before['status'] ?? $_POST['status'] ?? 'active');
                    if (!User::setStatus($userId, $newStatus)) {
                        $error = 'Could not update user status.';
                        AppLogger::failed('users.toggle_status', $error, ['user_id' => $userId]);
                    } else {
                        $msg = $newStatus === 'active' ? 'User activated' : 'User deactivated';
                        AppLogger::audit('users.toggle_status', $msg, [
                            'user_id' => $userId,
                            'status' => $newStatus,
                        ]);
                        $undo = UndoService::remember('user_status', [
                            'user_id' => $userId,
                            'prev_status' => $prevStatus === 'inactive' ? 'inactive' : 'active',
                            'label' => (string)($before['name'] ?? ('#' . $userId)),
                        ]);
                        header(
                            'Location: users.php?ok=' . rawurlencode($msg)
                            . '&undo=' . rawurlencode($undo)
                            . '#users-directory'
                        );
                        exit;
                    }
                } else {
                    $error = 'Could not update user status.';
                    AppLogger::failed('users.toggle_status', $error, ['user_id' => $userId]);
                }
            }
        } elseif ($action === 'delete') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $adminPassword = trim((string)($_POST['admin_password'] ?? ''));
            $target = $userId > 0 ? User::findRawById($userId) : null;

            if (!$target) {
                $error = 'User not found.';
                AppLogger::failed('users.delete', $error, ['user_id' => $userId]);
            } elseif ($adminPassword === '') {
                $error = 'Enter your admin password to confirm delete.';
                AppLogger::failed('users.delete', $error, ['user_id' => $userId]);
            } elseif (!AuthService::verifyCurrentUserPassword($adminPassword)) {
                $error = 'Incorrect admin password. User was not deleted.';
                AppLogger::failed('users.delete', $error, ['user_id' => $userId]);
            } else {
                $liveAdmin = AuthService::currentUser();
                if ($userId === (int)($liveAdmin['id'] ?? 0)) {
                    $error = 'You cannot delete your own account.';
                    AppLogger::failed('users.delete', $error, ['user_id' => $userId]);
                } elseif (User::delete($userId)) {
                    unset($_SESSION['users_edit_unlock_' . $userId]);
                    AppLogger::audit('users.delete', 'User deleted', [
                        'user_id' => $userId,
                        'email' => $target['email'] ?? '',
                    ]);
                    $undo = UndoService::remember('user_delete', [
                        'user' => $target,
                        'label' => (string)($target['name'] ?? ('#' . $userId)),
                    ]);
                    header(
                        'Location: users.php?ok=' . rawurlencode('User deleted')
                        . '&undo=' . rawurlencode($undo)
                        . '#users-directory'
                    );
                    exit;
                } else {
                    $error = 'Could not delete user.';
                    AppLogger::failed('users.delete', $error, ['user_id' => $userId]);
                }
            }
        } else {
            $error = 'Unknown action. Please try again.';
            AppLogger::failed('users.unknown_action', $error, ['action' => $action]);
        }
        } catch (Throwable $e) {
            // Unexpected DB / system failure — log details, show safe message
            AppLogger::exception($e, 'users.' . (string)$action);
            $error = AppLogger::friendlyMessage($e, 'Could not complete this user action. Please try again.');
        }
    }
}

if (isset($_GET['lock_edit'])) {
    $lockId = (int)$_GET['lock_edit'];
    if ($lockId > 0) {
        unset($_SESSION['users_edit_unlock_' . $lockId]);
    }
    header('Location: users.php');
    exit;
}

// Success feedback for update/status/delete is shown via toast (?ok=&undo=)

$confirmAction = null;
$confirmUser = null;
$confirmParam = strtolower(trim((string)($_GET['confirm'] ?? '')));
if (in_array($confirmParam, ['edit', 'toggle', 'delete'], true)) {
    $confirmId = (int)($_GET['id'] ?? 0);
    $confirmUser = $confirmId > 0 ? User::findById($confirmId) : null;
    if (!$confirmUser) {
        $error = $error ?: 'User not found for confirmation.';
    } elseif (
        in_array($confirmParam, ['toggle', 'delete'], true)
        && (int)$confirmUser['id'] === (int)$currentUser['id']
    ) {
        $error = $error ?: ($confirmParam === 'delete'
            ? 'You cannot delete your own account.'
            : 'You cannot deactivate your own account.');
        $confirmUser = null;
    } else {
        $confirmAction = $confirmParam;
    }
}
if ($error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $failedAction = (string)($_POST['action'] ?? '');
    $failedMap = [
        'unlock_edit' => 'edit',
        'toggle_status' => 'toggle',
        'delete' => 'delete',
    ];
    if (isset($failedMap[$failedAction])) {
        $confirmId = (int)($_POST['user_id'] ?? 0);
        $confirmUser = $confirmId > 0 ? User::findById($confirmId) : null;
        if ($confirmUser) {
            $mapped = $failedMap[$failedAction];
            if (
                in_array($mapped, ['toggle', 'delete'], true)
                && (int)$confirmUser['id'] === (int)$currentUser['id']
            ) {
                $confirmUser = null;
            } else {
                $confirmAction = $mapped;
            }
        }
    }
}

// Load user for edit (GET ?edit=id) — password unlock required
if (!$editUser && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $candidate = User::findById($editId);
        if (!$candidate) {
            $error = $error ?: 'User not found for editing.';
        } else {
            $unlockKey = 'users_edit_unlock_' . $editId;
            $unlockMeta = $_SESSION[$unlockKey] ?? null;
            $unlockOk = is_array($unlockMeta)
                && (int)($unlockMeta['admin_id'] ?? 0) === (int)$currentUser['id']
                && ((int)($unlockMeta['at'] ?? 0) + 1800) >= time();
            if (!$unlockOk) {
                unset($_SESSION[$unlockKey]);
                header('Location: users.php?confirm=edit&id=' . $editId);
                exit;
            }
            $editUser = $candidate;
        }
    }
}

$isEdit = $editUser !== null;
$usersPage = max(1, (int)($_GET['upage'] ?? 1));
$usersLimit = 20;
$usersTotal = 0;
$usersPages = 1;
$usersOffset = 0;
$users = [];
try {
    $usersTotal = User::countAll();
    $usersPages = max(1, (int)ceil($usersTotal / $usersLimit));
    if ($usersPage > $usersPages) {
        $usersPage = $usersPages;
    }
    $usersOffset = ($usersPage - 1) * $usersLimit;
    $users = User::listPaged($usersLimit, $usersOffset);
} catch (Throwable $e) {
    AppLogger::exception($e, 'users.list');
    $error = $error ?: AppLogger::friendlyMessage($e, 'Could not load the user list. Please refresh the page.');
    $usersPages = 1;
    $usersPage = 1;
    $usersOffset = 0;
}
$usersFrom = $usersTotal === 0 ? 0 : ($usersOffset + 1);
$usersTo = min($usersOffset + $usersLimit, $usersTotal);

$keepCreate = $error && !$isEdit && (($_POST['action'] ?? '') === 'create');
$formName = $isEdit
    ? ($error && (($_POST['action'] ?? '') === 'update') ? ($_POST['name'] ?? $editUser['name']) : $editUser['name'])
    : ($keepCreate ? ($_POST['name'] ?? '') : '');
$formEmail = $isEdit
    ? ($error && (($_POST['action'] ?? '') === 'update') ? ($_POST['email'] ?? $editUser['email']) : $editUser['email'])
    : ($keepCreate ? ($_POST['email'] ?? '') : '');
$formPassword = $isEdit ? '' : ($keepCreate ? ($_POST['password'] ?? AppConfig::DEFAULT_ADMIN_PASSWORD) : AppConfig::DEFAULT_ADMIN_PASSWORD);
$formRole = $isEdit
    ? ($error && (($_POST['action'] ?? '') === 'update') ? ($_POST['role'] ?? $editUser['role']) : $editUser['role'])
    : ($keepCreate ? ($_POST['role'] ?? AppConfig::ROLE_SUPER_ADMIN) : AppConfig::ROLE_SUPER_ADMIN);
if (!in_array($formRole, AppConfig::allowedRoles(), true)) {
    $formRole = AppConfig::ROLE_SUPER_ADMIN;
}
$formStatus = $isEdit
    ? ($error && (($_POST['action'] ?? '') === 'update') ? ($_POST['status'] ?? $editUser['status']) : $editUser['status'])
    : 'active';
$formPhone = $isEdit
    ? preg_replace('/\D/', '', (string)($error && (($_POST['action'] ?? '') === 'update') ? ($_POST['phone'] ?? $editUser['phone']) : ($editUser['phone'] ?? '')))
    : ($keepCreate ? preg_replace('/\D/', '', $_POST['phone'] ?? '') : '');
if (strlen($formPhone) === 12 && str_starts_with($formPhone, '91')) {
    $formPhone = substr($formPhone, 2);
}
$formPhone = substr($formPhone, -10);
$formZone = $isEdit
    ? strtoupper(trim((string)($error && (($_POST['action'] ?? '') === 'update') ? ($_POST['zone'] ?? ($editUser['zone'] ?? '')) : ($editUser['zone'] ?? ''))))
    : strtoupper(trim((string)($keepCreate ? ($_POST['zone'] ?? '') : '')));
$formCity = $isEdit
    ? trim((string)($error && (($_POST['action'] ?? '') === 'update') ? ($_POST['city'] ?? ($editUser['city'] ?? '')) : ($editUser['city'] ?? '')))
    : trim((string)($keepCreate ? ($_POST['city'] ?? '') : ''));
$zoneOptions = AppConfig::zones();
/** @var array<string, list<string>> master cities by zone (+ store cities merged) */
$citiesByZone = ['_all' => []];
try {
    $citiesByZone = \App\Models\MasterData::getCitiesByZone();
    // Merge any cities already on live stores (custom / extra names)
    foreach ($zoneOptions as $zOpt) {
        $fromStores = \App\Models\Freezer::dashboardCities($zOpt);
        if ($fromStores === []) {
            continue;
        }
        $base = $citiesByZone[$zOpt] ?? [];
        foreach ($fromStores as $sc) {
            $sc = trim((string)$sc);
            if ($sc === '') {
                continue;
            }
            $exists = false;
            foreach ($base as $b) {
                if (strcasecmp($b, $sc) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $base[] = $sc;
            }
        }
        sort($base, SORT_NATURAL | SORT_FLAG_CASE);
        $citiesByZone[$zOpt] = $base;
    }
    $allStore = \App\Models\Freezer::dashboardCities(null);
    foreach ($allStore as $sc) {
        $sc = trim((string)$sc);
        if ($sc === '') {
            continue;
        }
        $exists = false;
        foreach ($citiesByZone['_all'] as $b) {
            if (strcasecmp($b, $sc) === 0) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $citiesByZone['_all'][] = $sc;
        }
    }
    sort($citiesByZone['_all'], SORT_NATURAL | SORT_FLAG_CASE);
} catch (Throwable $e) {
    AppLogger::exception($e, 'users.cities');
    try {
        $citiesByZone = \App\Models\MasterData::getCitiesByZone();
    } catch (Throwable $e2) {
        AppLogger::exception($e2, 'users.cities.fallback');
        $citiesByZone = ['_all' => []];
    }
}
$cityOptions = $formZone !== '' && !empty($citiesByZone[$formZone])
    ? $citiesByZone[$formZone]
    : ($citiesByZone['_all'] ?? []);
if ($formCity !== '' && !in_array($formCity, $cityOptions, true)) {
    $cityOptions[] = $formCity;
}

// Password-only screen for Edit / On-Off / Delete (same style for all)
$passwordGateView = ($confirmAction && $confirmUser);
$gatePageTitle = 'Manage Users';
$gateHint = '';
$gateLabel = '';
$gateAction = '';
$gateBtn = 'Confirm';
$gateBtnClass = 'btn-primary';
$gateDanger = false;
if ($passwordGateView) {
    if ($confirmAction === 'edit') {
        $gatePageTitle = 'Edit User';
        $gateHint = 'Edit requires your admin password.';
        $gateLabel = 'Edit user';
        $gateAction = 'unlock_edit';
        $gateBtn = 'Open edit';
        $gateBtnClass = 'btn-primary';
        $gateDanger = false;
    } elseif ($confirmAction === 'toggle') {
        $willBeActive = (($confirmUser['status'] ?? '') !== 'active');
        $gatePageTitle = $willBeActive ? 'Turn User On' : 'Turn User Off';
        $gateHint = 'Status change requires your admin password.';
        $gateLabel = $willBeActive ? 'Turn user On' : 'Turn user Off';
        $gateAction = 'toggle_status';
        $gateBtn = $willBeActive ? 'Confirm On' : 'Confirm Off';
        $gateBtnClass = 'btn-primary';
        $gateDanger = false;
    } else {
        $gatePageTitle = 'Delete User';
        $gateHint = 'Deletion requires your admin password.';
        $gateLabel = 'Delete user';
        $gateAction = 'delete';
        $gateBtn = 'Delete user';
        $gateBtnClass = 'btn-danger';
        $gateDanger = true;
    }
}

$pageTitle = $passwordGateView ? $gatePageTitle : ($isEdit ? 'Edit User' : 'Manage Users');
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($passwordGateView): ?>
<div class="users-page users-password-gate">
    <div class="cfg-toolbar">
        <div class="cfg-toolbar-left">
            <h1 class="cfg-toolbar-title"><?= htmlspecialchars($gatePageTitle) ?></h1>
        </div>
        <a href="users.php" class="btn btn-secondary btn-sm">Cancel</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: 1rem;">
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <div class="card users-confirm-card" id="user-confirm-card" style="max-width:520px;">
        <div class="card-header">
            <div class="card-title"><span>Password required</span></div>
        </div>
        <div class="card-body">
            <p style="font-size:0.875rem; color:var(--text-secondary); margin:0 0 1rem; line-height:1.45;">
                <?= htmlspecialchars($gateHint) ?>
                Logged in as
                <strong><?= htmlspecialchars($currentUser['email'] ?? $currentUser['name']) ?></strong>.
            </p>

            <div class="users-confirm-target<?= $gateDanger ? ' is-danger' : '' ?>">
                <div class="users-confirm-target-label"><?= htmlspecialchars($gateLabel) ?></div>
                <div class="users-confirm-target-name"><?= htmlspecialchars((string)$confirmUser['name']) ?></div>
                <div class="users-confirm-target-meta">
                    <?= htmlspecialchars((string)$confirmUser['email']) ?>
                    · <?= htmlspecialchars(str_replace('_', ' ', (string)($confirmUser['role'] ?? ''))) ?>
                </div>
            </div>

            <form method="POST" action="users.php?confirm=<?= htmlspecialchars($confirmAction) ?>&amp;id=<?= (int)$confirmUser['id'] ?>" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="<?= htmlspecialchars($gateAction) ?>">
                <input type="hidden" name="user_id" value="<?= (int)$confirmUser['id'] ?>">
                <?php if ($confirmAction === 'toggle'): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars((string)($confirmUser['status'] ?? 'active')) ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label required" for="admin_password">Admin password</label>
                    <div class="password-field">
                        <input type="password" id="admin_password" name="admin_password" class="form-control"
                               required autofocus autocomplete="current-password"
                               placeholder="Enter your admin password">
                        <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" title="Show password">
                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:1rem;">
                    <button type="submit" class="btn <?= htmlspecialchars($gateBtnClass) ?>"><?= htmlspecialchars($gateBtn) ?></button>
                    <a href="users.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php return; endif; ?>

<div class="users-page">
    <div class="cfg-toolbar">
        <div class="cfg-toolbar-left">
            <h1 class="cfg-toolbar-title">Manage Users</h1>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <a href="#users-directory" class="btn btn-secondary">View Users</a>
            <a href="index.php" class="btn btn-outline">Dashboard</a>
        </div>
    </div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: 1rem;">
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom: 1rem;">
        <span><?= htmlspecialchars($success) ?></span>
    </div>
<?php endif; ?>

<form method="POST" action="" id="user-create-form" class="cfg-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
    <?php endif; ?>

    <div class="card" style="margin-bottom: 1.5rem;" id="user-form-card">
        <div class="card-header users-form-head">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <?php if ($isEdit): ?>
                        <path d="M12 20h9"/>
                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                    <?php else: ?>
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="8.5" cy="7" r="4"/>
                        <line x1="20" y1="8" x2="20" y2="14"/>
                        <line x1="23" y1="11" x2="17" y2="11"/>
                    <?php endif; ?>
                </svg>
                <span><?= $isEdit ? 'Edit User' : 'Add New User' ?></span>
            </div>
            <span class="badge badge-primary users-admin-badge">Super Admin only</span>
        </div>
        <div class="card-body">
            <p class="form-step-hint" style="margin-top:0;">
                <?= $isEdit
                    ? 'Update account details. Leave password blank to keep the current password.'
                    : 'Super Admin only · bcrypt password · role-based access' ?>
            </p>

            <!-- Account + Access & Contact (one section) -->
            <div class="form-subsection">
                <div class="form-grid">
                    <div class="col-4 form-group">
                        <label class="form-label required" for="name">Full name</label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="form-control"
                            required
                            autocomplete="name"
                            inputmode="text"
                            pattern="[A-Za-z][A-Za-z .'-]{1,98}"
                            maxlength="100"
                            placeholder="e.g. Ravi Kumar"
                            title="Letters only (spaces, ., ', - allowed)"
                            value="<?= htmlspecialchars($formName) ?>"
                        >
                        <div class="form-error">Enter a valid full name.</div>
                    </div>

                    <div class="col-4 form-group">
                        <label class="form-label required" for="email">Email ID</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            required
                            autocomplete="email"
                            inputmode="email"
                            pattern="[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}"
                            maxlength="150"
                            placeholder="e.g. ravi.tech@reeferon.com"
                            title="Valid email format: name@domain.com"
                            value="<?= htmlspecialchars($formEmail) ?>"
                        >
                        <div class="form-error">Enter a valid email address.</div>
                    </div>

                    <div class="col-4 form-group">
                        <label class="form-label<?= $isEdit ? '' : ' required' ?>" for="password">Password</label>
                        <div class="password-field">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control"
                                <?= $isEdit ? '' : 'required' ?>
                                minlength="6"
                                maxlength="64"
                                autocomplete="new-password"
                                placeholder="<?= $isEdit ? 'Leave blank to keep current' : 'Min 6 characters' ?>"
                                value="<?= htmlspecialchars($formPassword) ?>"
                            >
                            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" title="Show password">
                                <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                    <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            </button>
                        </div>
                        <div class="form-error">Password must be at least 6 characters.</div>
                    </div>

                    <div class="col-4 form-group">
                        <label class="form-label required" for="role">User type</label>
                        <select id="role" name="role" class="form-select" required>
                            <?php foreach (AppConfig::getUserRoles() as $roleOpt): ?>
                                <option value="<?= htmlspecialchars($roleOpt['value']) ?>" <?= $formRole === $roleOpt['value'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($roleOpt['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($isEdit): ?>
                    <div class="col-4 form-group">
                        <label class="form-label required" for="status">Status</label>
                        <select id="status" name="status" class="form-select" required
                            <?= ((int)$editUser['id'] === (int)$currentUser['id']) ? 'disabled' : '' ?>>
                            <option value="active" <?= $formStatus === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $formStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <?php if ((int)$editUser['id'] === (int)$currentUser['id']): ?>
                            <input type="hidden" name="status" value="active">
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="col-4 form-group">
                        <label class="form-label" for="phone">Phone / mobile</label>
                        <div class="phone-input-group">
                            <span class="phone-prefix" aria-hidden="true">+91</span>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                class="form-control"
                                inputmode="numeric"
                                pattern="[6-9][0-9]{9}"
                                maxlength="10"
                                placeholder="10-digit mobile"
                                value="<?= htmlspecialchars($formPhone) ?>"
                                title="Enter 10-digit Indian mobile number"
                                autocomplete="tel-national"
                            >
                        </div>
                        <div class="form-error">Enter a valid 10-digit Indian mobile.</div>
                    </div>

                    <div class="col-4 form-group" id="user-zone-group">
                        <label class="form-label" for="user_zone">Assigned zone</label>
                        <select id="user_zone" name="zone" class="form-select">
                            <option value="">All zones</option>
                            <?php foreach ($zoneOptions as $z): ?>
                                <option value="<?= htmlspecialchars($z) ?>" <?= $formZone === $z ? 'selected' : '' ?>><?= htmlspecialchars($z) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:#64748b;font-size:0.75rem;">Admin sets the access area. This limits what the technician can see.</small>
                    </div>

                    <div class="col-4 form-group" id="user-city-group">
                        <label class="form-label" for="user_city">Assigned city</label>
                        <div class="user-city-combo">
                            <input
                                type="text"
                                id="user_city"
                                name="city"
                                class="form-control"
                                list="user-city-datalist"
                                placeholder="Type or pick city…"
                                maxlength="100"
                                autocomplete="off"
                                value="<?= htmlspecialchars($formCity) ?>"
                            >
                            <datalist id="user-city-datalist">
                                <?php foreach ($cityOptions as $cName): ?>
                                    <option value="<?= htmlspecialchars($cName) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <small style="color:#64748b;font-size:0.75rem;">Pick a zone, then type or search a city. Leave blank for all cities.</small>
                    </div>
                    <script type="application/json" id="user-cities-by-zone"><?= json_encode($citiesByZone, JSON_UNESCAPED_UNICODE) ?></script>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-top: 0.25rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <div style="font-size: 0.85rem; color: #64748b;">
                    <?= $isEdit ? 'Editing:' : 'New account:' ?>
                    <strong id="user-form-summary" style="color: #0f172a;"><?= htmlspecialchars($isEdit ? $formName : 'Super Admin') ?></strong>
                </div>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <?php if ($isEdit): ?>
                        <a href="users.php?lock_edit=<?= (int)$editUser['id'] ?>" class="btn btn-outline">Cancel Edit</a>
                        <button type="submit" class="btn btn-warning" id="user-submit-btn">
                            Update User Account
                        </button>
                    <?php else: ?>
                        <a href="index.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary" id="user-submit-btn">
                            + Create User Account
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>
</div><!-- /users-page -->

<!-- Users directory — Excel / logs-style table -->
<div class="card users-directory logs-entries-card" id="users-directory">
    <div class="card-header users-directory-head logs-toolbar-compact">
        <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span>All Users (<?= number_format($usersTotal) ?>)</span>
            <span class="badge badge-neutral" style="margin-left:0.5rem;"><?= (int)$usersFrom ?>–<?= (int)$usersTo ?> of <?= number_format($usersTotal) ?></span>
        </div>
    </div>

    <div class="card-body logs-entries-body">
        <?php if (empty($users)): ?>
            <p class="users-empty">No users found.</p>
        <?php else: ?>
            <div class="logs-table-wrap">
                <table class="logs-excel users-excel">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Type</th>
                            <th>Area</th>
                            <th>Status</th>
                            <th>Last login</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php
                            $uZone = trim((string)($u['zone'] ?? ''));
                            $uCity = trim((string)($u['city'] ?? ''));
                            $uArea = $uZone === '' && $uCity === ''
                                ? 'All areas'
                                : trim(($uZone !== '' ? $uZone : 'Any zone') . ($uCity !== '' ? ' · ' . $uCity : ''));
                            $uRole = str_replace('_', ' ', (string)($u['role'] ?? ''));
                            $lastLogin = trim((string)($u['last_login'] ?? ''));
                            $isYou = (int)$u['id'] === (int)$currentUser['id'];
                            ?>
                            <tr>
                                <td title="<?= (int)$u['id'] ?>"><?= (int)$u['id'] ?></td>
                                <td title="<?= htmlspecialchars($u['name']) ?>">
                                    <?= htmlspecialchars($u['name']) ?>
                                    <?php if ($isYou): ?><span class="users-excel-you">You</span><?php endif; ?>
                                </td>
                                <td title="<?= htmlspecialchars($u['email']) ?>"><?= htmlspecialchars($u['email']) ?></td>
                                <td title="<?= htmlspecialchars((string)($u['phone'] ?? '')) ?>"><?= htmlspecialchars((string)($u['phone'] ?? '—')) ?></td>
                                <td class="logs-col-level" title="<?= htmlspecialchars($uRole) ?>">
                                    <span class="badge badge-sm badge-primary" style="text-transform:capitalize;"><?= htmlspecialchars($uRole) ?></span>
                                </td>
                                <td title="<?= htmlspecialchars($uArea) ?>"><?= htmlspecialchars($uArea) ?></td>
                                <td class="logs-col-level">
                                    <?php if ($u['status'] === 'active'): ?>
                                        <span class="badge badge-sm badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-sm badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td title="<?= htmlspecialchars($lastLogin !== '' ? $lastLogin : 'Never') ?>">
                                    <?= htmlspecialchars($lastLogin !== '' ? $lastLogin : '—') ?>
                                </td>
                                <td class="users-excel-actions">
                                    <a href="users.php?confirm=edit&amp;id=<?= (int)$u['id'] ?>"
                                       class="btn btn-sm btn-secondary"
                                       title="Edit user (password required)">Edit</a>
                                    <?php if (!$isYou): ?>
                                        <a href="users.php?confirm=toggle&amp;id=<?= (int)$u['id'] ?>"
                                           class="btn btn-sm btn-outline"
                                           title="<?= $u['status'] === 'active' ? 'Turn Off (password required)' : 'Turn On (password required)' ?>">
                                            <?= $u['status'] === 'active' ? 'Off' : 'On' ?>
                                        </a>
                                        <a href="users.php?confirm=delete&amp;id=<?= (int)$u['id'] ?>"
                                           class="btn btn-sm btn-danger"
                                           title="Delete user (password required)">Del</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($usersTotal > 0): ?>
    <div class="card-footer profiles-pagination" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
        <div style="font-size:0.85rem; color:#64748b;">
            Showing <strong><?= (int)$usersFrom ?>–<?= (int)$usersTo ?></strong> of <strong><?= number_format($usersTotal) ?></strong>
            · <?= (int)$usersLimit ?> per page
            <?php if ($usersPages > 1): ?>
                · Page <strong><?= (int)$usersPage ?></strong> of <strong><?= (int)$usersPages ?></strong>
            <?php endif; ?>
        </div>
        <?php if ($usersPages > 1): ?>
        <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
            <?php
            $uPrev = max(1, $usersPage - 1);
            $uNext = min($usersPages, $usersPage + 1);
            $uStart = max(1, $usersPage - 2);
            $uEnd = min($usersPages, $usersPage + 2);
            ?>
            <?php if ($usersPage > 1): ?>
                <a class="btn btn-sm btn-secondary" href="users.php?upage=<?= $uPrev ?>#users-directory">Prev</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary" disabled>Prev</button>
            <?php endif; ?>
            <?php if ($uStart > 1): ?>
                <a class="btn btn-sm btn-secondary" href="users.php?upage=1#users-directory">1</a>
                <?php if ($uStart > 2): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $uStart; $p <= $uEnd; $p++): ?>
                <a class="btn btn-sm <?= $p === $usersPage ? 'btn-primary' : 'btn-secondary' ?>"
                   href="users.php?upage=<?= $p ?>#users-directory"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($uEnd < $usersPages): ?>
                <?php if ($uEnd < $usersPages - 1): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
                <a class="btn btn-sm btn-secondary" href="users.php?upage=<?= (int)$usersPages ?>#users-directory"><?= (int)$usersPages ?></a>
            <?php endif; ?>
            <?php if ($usersPage < $usersPages): ?>
                <a class="btn btn-sm btn-secondary" href="users.php?upage=<?= $uNext ?>#users-directory">Next</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary" disabled>Next</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Role access guide -->
<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header">
        <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="16" x2="12" y2="12"/>
                <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <span>Role access details</span>
        </div>
    </div>
    <div class="card-body">
        <p style="color:#64748b; font-size:0.875rem; margin-bottom:1rem;">
            Assign the right role when creating a user. Access is enforced on every page.
        </p>
        <div class="role-access-grid">
            <div class="role-access-card">
                <div class="role-access-title">
                    <span class="badge badge-primary">Super Admin</span>
                </div>
                <ul class="role-access-list">
                    <li>Full system access</li>
                    <li>Add / edit / delete profiles</li>
                    <li>QR scan, blank tags</li>
                    <li>Manage Users + Import / Export</li>
                </ul>
            </div>
            <div class="role-access-card">
                <div class="role-access-title">
                    <span class="badge badge-success">Technician</span>
                </div>
                <ul class="role-access-list">
                    <li>Field: Scan QR → fill store / freezer / photos / GPS → Save</li>
                    <li>Edit existing profiles (update data &amp; photos)</li>
                    <li>Register / update freezers on site</li>
                    <li>Zone/City at create — only that area’s data &amp; fills</li>
                    <li>View / print QR · no Users, Import, Delete, Blank tags</li>
                </ul>
            </div>
            <div class="role-access-card">
                <div class="role-access-title">
                    <span class="badge badge-neutral">Viewer</span>
                </div>
                <ul class="role-access-list">
                    <li>Dashboard &amp; profiles view — All areas</li>
                    <li>QR print / view only</li>
                    <li>No zone lock (same as Admin view)</li>
                    <li>No add / edit</li>
                    <li>No user management</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var nameInput = document.getElementById('name');
    var emailInput = document.getElementById('email');
    var phoneInput = document.getElementById('phone');
    var passwordInput = document.getElementById('password');
    var roleSelect = document.getElementById('role');
    var zoneSelect = document.getElementById('user_zone');
    var cityInput = document.getElementById('user_city');
    var cityDatalist = document.getElementById('user-city-datalist');
    var zoneGroup = document.getElementById('user-zone-group');
    var cityGroup = document.getElementById('user-city-group');
    var summary = document.getElementById('user-form-summary');
    var form = document.getElementById('user-create-form');
    var citiesByZone = {};
    try {
        var citiesEl = document.getElementById('user-cities-by-zone');
        citiesByZone = citiesEl ? JSON.parse(citiesEl.textContent || '{}') : {};
    } catch (e) {
        citiesByZone = {};
    }

    function setFieldError(el, on) {
        if (!el) return;
        var group = el.closest('.form-group');
        el.classList.toggle('is-invalid', on);
        if (group) group.classList.toggle('has-error', on);
    }

    // Zone + City always visible — admin sets them when giving user access
    function syncAreaVisibility() {
        if (zoneGroup) zoneGroup.style.display = '';
        if (cityGroup) cityGroup.style.display = '';
    }

    /** Refresh datalist options for selected zone (type-to-search) */
    function refillCities(keepValue) {
        if (!cityInput || !cityDatalist) return;
        var z = zoneSelect && zoneSelect.value ? String(zoneSelect.value).toUpperCase() : '';
        var list;
        if (z && Array.isArray(citiesByZone[z]) && citiesByZone[z].length) {
            list = citiesByZone[z];
        } else if (z) {
            list = [];
        } else {
            list = citiesByZone._all || [];
        }
        var prev = keepValue ? String(cityInput.value || '').trim() : '';
        cityDatalist.innerHTML = '';
        (list || []).forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c;
            cityDatalist.appendChild(opt);
        });
        if (prev) {
            var found = null;
            for (var i = 0; i < list.length; i++) {
                if (String(list[i]).toLowerCase() === prev.toLowerCase()) {
                    found = list[i];
                    break;
                }
            }
            if (found) {
                cityInput.value = found;
            } else if (keepValue) {
                cityInput.value = prev;
                var extra = document.createElement('option');
                extra.value = prev;
                cityDatalist.appendChild(extra);
            } else {
                cityInput.value = '';
            }
        } else if (!keepValue) {
            cityInput.value = '';
        }
        cityInput.placeholder = z
            ? ('Type city in ' + z + '…')
            : 'Type or pick city…';
    }

    function syncSummary() {
        if (!summary || !roleSelect) return;
        var label = roleSelect.options[roleSelect.selectedIndex].text.split('—')[0].trim();
        var name = (nameInput && nameInput.value.trim()) || 'New user';
        var z = zoneSelect && zoneSelect.value ? zoneSelect.value : '';
        var c = cityInput && cityInput.value.trim() ? cityInput.value.trim() : '';
        var area = '';
        if (roleSelect.value === 'technician') {
            if (z || c) area = ' · ' + (z || 'Any') + (c ? ' / ' + c : '');
            else area = ' · All areas';
        } else {
            area = ' · All areas';
        }
        summary.textContent = name + ' · ' + label + area;
    }

    if (nameInput) {
        nameInput.addEventListener('input', function () {
            this.value = this.value.replace(/[^A-Za-z .'-]/g, '').replace(/\s{2,}/g, ' ');
            setFieldError(this, false);
            syncSummary();
        });
    }

    if (emailInput) {
        emailInput.addEventListener('input', function () {
            this.value = this.value.replace(/\s/g, '').toLowerCase().replace(/[^a-z0-9._%+\-@]/g, '');
            setFieldError(this, false);
        });
    }

    if (passwordInput) {
        passwordInput.addEventListener('input', function () {
            setFieldError(this, false);
        });
    }

    if (phoneInput) {
        phoneInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 10);
            setFieldError(this, false);
        });
        phoneInput.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text') || '';
            var digits = text.replace(/\D/g, '');
            if (digits.length === 12 && digits.indexOf('91') === 0) digits = digits.slice(2);
            if (digits.length === 11 && digits.charAt(0) === '0') digits = digits.slice(1);
            this.value = digits.slice(0, 10);
        });
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', function () {
            syncAreaVisibility();
            syncSummary();
        });
    }
    if (zoneSelect) {
        zoneSelect.addEventListener('change', function () {
            refillCities(false);
            syncSummary();
        });
    }
    if (cityInput) {
        cityInput.addEventListener('input', syncSummary);
        cityInput.addEventListener('change', syncSummary);
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            var ok = true;
            var nameBad = !nameInput || !/^[A-Za-z][A-Za-z .'-]{1,98}$/.test(nameInput.value.trim());
            var emailBad = !emailInput || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim());
            var isEdit = !!(form.querySelector('input[name="action"][value="update"]'));
            var passVal = passwordInput ? passwordInput.value : '';
            var passBad = isEdit ? (passVal !== '' && passVal.length < 6) : (!passwordInput || passVal.length < 6);
            setFieldError(nameInput, nameBad);
            setFieldError(emailInput, emailBad);
            setFieldError(passwordInput, passBad);
            if (nameBad || emailBad || passBad) ok = false;

            if (phoneInput && phoneInput.value.trim() !== '') {
                var phoneBad = !/^[6-9]\d{9}$/.test(phoneInput.value);
                setFieldError(phoneInput, phoneBad);
                if (phoneBad) ok = false;
            }

            if (!ok) e.preventDefault();
        });
    }

    syncAreaVisibility();
    refillCities(true);
    syncSummary();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
