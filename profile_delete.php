<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Services\UndoService;
use App\Models\Freezer;

AuthService::requireRole('super_admin');
Freezer::ensureSimColumns();
$currentUser = AuthService::currentUser();

$id = !empty($_GET['id']) ? (int)$_GET['id'] : (!empty($_POST['id']) ? (int)$_POST['id'] : 0);
$freezer = $id > 0 ? Freezer::findById($id) : null;

if (!$freezer) {
    header('Location: profiles.php');
    exit;
}

$error = null;
$csrfToken = AuthService::generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $password = (string)($_POST['admin_password'] ?? '');
        if ($password === '') {
            $error = 'Enter your admin password to confirm delete.';
        } elseif (!AuthService::verifyCurrentUserPassword($password)) {
            $error = 'Incorrect admin password. Profile was not deleted.';
        } else {
            $undoSnapshot = [
                'freezer_id' => $id,
                'label' => (string)($freezer['unique_freezer_id'] ?? ''),
            ];
            if (!Freezer::delete($id)) {
                $error = 'Failed to delete profile. Please try again.';
            } else {
                $undoToken = UndoService::remember('delete', $undoSnapshot);
                header('Location: profiles.php?deleted=1&undo=' . urlencode($undoToken));
                exit;
            }
        }
    }
}

$pageTitle = 'Delete Freezer Profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="cfg-toolbar">
    <div class="cfg-toolbar-left">
        <h1 class="cfg-toolbar-title">Delete freezer profile</h1>
    </div>
    <a href="profile_view.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-secondary btn-sm">Cancel</a>
</div>

<div class="card" style="max-width: 520px;">
    <div class="card-header">
        <div class="card-title"><span>Password required</span></div>
    </div>
    <div class="card-body">
        <p style="font-size:0.875rem; color:var(--text-secondary); margin:0 0 1rem; line-height:1.45;">
            Deletion requires password confirmation from the logged-in admin
            (<strong><?= htmlspecialchars($currentUser['email'] ?? $currentUser['name']) ?></strong>).
        </p>

        <div style="padding:0.85rem 1rem; border:1px solid #fecaca; background:#fef2f2; border-radius:6px; margin-bottom:1rem;">
            <div style="font-size:0.75rem; color:#991b1b; font-weight:600; margin-bottom:0.25rem;">Will remove from assets</div>
            <div style="font-weight:700; color:#0f172a; word-break:break-all;"><?= htmlspecialchars($freezer['unique_freezer_id']) ?></div>
            <div style="font-size:0.85rem; color:#475569; margin-top:0.25rem;">
                <?= htmlspecialchars($freezer['store_name'] ?? '') ?>
                · <?= htmlspecialchars($freezer['outlet_id'] ?? '') ?>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="id" value="<?= (int)$freezer['id'] ?>">

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
                <button type="submit" class="btn btn-danger">Delete profile</button>
                <a href="profiles.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
