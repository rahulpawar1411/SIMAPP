<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\User;
use App\Config\AppConfig;

// User create / edit / status — Super Admin only
AuthService::requireRole(AppConfig::ROLE_SUPER_ADMIN);
$currentUser = AuthService::currentUser();
$csrfToken = AuthService::generateCsrfToken();

$error = null;
$success = null;
$editUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'create';

        if ($action === 'create') {
            $result = User::create([
                'name' => $_POST['name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'password' => $_POST['password'] ?? '',
                'role' => AppConfig::ROLE_SUPER_ADMIN,
                'phone' => $_POST['phone'] ?? '',
                'status' => 'active',
            ]);

            if ($result['success']) {
                $success = 'User created successfully. They can now sign in with the email and password you set.';
            } else {
                $error = $result['error'] ?? 'Failed to create user.';
            }
        } elseif ($action === 'update') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $editUser = $userId > 0 ? User::findById($userId) : null;

            if (!$editUser) {
                $error = 'User not found.';
            } else {
                $result = User::update($userId, [
                    'name' => $_POST['name'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'password' => $_POST['password'] ?? '',
                    'role' => AppConfig::ROLE_SUPER_ADMIN,
                    'phone' => $_POST['phone'] ?? '',
                    'status' => $_POST['status'] ?? $editUser['status'],
                ]);

                if ($result['success']) {
                    $success = 'User updated successfully.';
                    $editUser = null;
                    header('Location: users.php?updated=1');
                    exit;
                }

                $error = $result['error'] ?? 'Failed to update user.';
                $editUser = User::findById($userId) ?: $editUser;
            }
        } elseif ($action === 'toggle_status') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newStatus = ($_POST['status'] ?? '') === 'active' ? 'inactive' : 'active';

            if ($userId === (int)$currentUser['id']) {
                $error = 'You cannot deactivate your own account.';
            } elseif ($userId > 0 && User::setStatus($userId, $newStatus)) {
                $success = $newStatus === 'active' ? 'User activated.' : 'User deactivated.';
            } else {
                $error = 'Could not update user status.';
            }
        }
    }
}

if (isset($_GET['updated']) && $_GET['updated'] === '1' && !$error) {
    $success = 'User updated successfully.';
}

// Load user for edit (GET ?edit=id) — Super Admin page only
if (!$editUser && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $editUser = User::findById($editId);
        if (!$editUser) {
            $error = $error ?: 'User not found for editing.';
        }
    }
}

$isEdit = $editUser !== null;
$users = User::all();

$keepCreate = $error && !$isEdit && (($_POST['action'] ?? '') === 'create');
$formName = $isEdit
    ? ($error && (($_POST['action'] ?? '') === 'update') ? ($_POST['name'] ?? $editUser['name']) : $editUser['name'])
    : ($keepCreate ? ($_POST['name'] ?? '') : '');
$formEmail = $isEdit
    ? ($error && (($_POST['action'] ?? '') === 'update') ? ($_POST['email'] ?? $editUser['email']) : $editUser['email'])
    : ($keepCreate ? ($_POST['email'] ?? '') : '');
$formPassword = $isEdit ? '' : ($keepCreate ? ($_POST['password'] ?? 'Admin@123') : 'Admin@123');
$formRole = AppConfig::ROLE_SUPER_ADMIN;
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

$pageTitle = $isEdit ? 'Edit User' : 'Manage Users';
require_once __DIR__ . '/includes/header.php';
?>

<div class="users-page">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 800; color: #0f172a; letter-spacing: -0.02em;">
                Manage Users
            </h2>
            <p style="color: #64748b; font-size: 0.9rem; margin-top: 0.2rem;">
                Create &amp; edit users — Super Admin only. Access is enforced by role on every page.
            </p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
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

<form method="POST" action="" id="user-create-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
    <?php endif; ?>

    <div class="card" style="margin-bottom: 1.5rem;" id="user-form-card">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
            <span class="badge badge-primary">Super Admin only</span>
        </div>
        <div class="card-body">
            <p class="form-step-hint" style="margin-top:0;">
                <?= $isEdit
                    ? 'Update account details. Leave password blank to keep the current password.'
                    : 'Super Admin only · bcrypt password · role-based access' ?>
            </p>

            <!-- Account details -->
            <div class="form-subsection">
                <div class="form-subsection-head">
                    <h3 class="form-subsection-title">
                        Account Details
                        <span><?= $isEdit ? 'Name, email &amp; optional password reset' : 'Name, email &amp; first-login password' ?></span>
                    </h3>
                </div>

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
                        <span class="form-help">Letters &amp; spaces only — no numbers</span>
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
                        <span class="form-help">Login ID · name@domain.com</span>
                        <div class="form-error">Enter a valid email address.</div>
                    </div>

                    <div class="col-4 form-group">
                        <label class="form-label<?= $isEdit ? '' : ' required' ?>" for="password">Password</label>
                        <input
                            type="text"
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
                        <span class="form-help"><?= $isEdit ? 'Optional · blank = no change' : 'Share with user for first login' ?></span>
                        <div class="form-error">Password must be at least 6 characters.</div>
                    </div>
                </div>
            </div>

            <!-- Role & phone -->
            <div class="form-subsection">
                <div class="form-subsection-head">
                    <h3 class="form-subsection-title">
                        Access &amp; Contact
                        <span>Role permissions + optional mobile</span>
                    </h3>
                </div>

                <div class="form-grid">
                    <div class="col-4 form-group">
                        <label class="form-label required" for="role">User role</label>
                        <input type="hidden" name="role" value="super_admin">
                        <select id="role" class="form-select" disabled>
                            <option value="super_admin" selected>Super Admin — Full access</option>
                        </select>
                        <span class="form-help">Only Super Admin role is available</span>
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
                            <span class="form-help">You cannot deactivate yourself</span>
                        <?php else: ?>
                            <span class="form-help">Inactive users cannot sign in</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="col-<?= $isEdit ? '4' : '6' ?> form-group">
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
                        <span class="form-help">Optional · 10 digits · starts with 6–9</span>
                        <div class="form-error">Enter a valid 10-digit Indian mobile.</div>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-top: 0.25rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <div style="font-size: 0.85rem; color: #64748b;">
                    <?= $isEdit ? 'Editing:' : 'New account:' ?>
                    <strong id="user-form-summary" style="color: #0f172a;"><?= htmlspecialchars($isEdit ? $formName : 'Super Admin') ?></strong>
                </div>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <?php if ($isEdit): ?>
                        <a href="users.php" class="btn btn-outline">Cancel Edit</a>
                        <button type="submit" class="btn btn-primary" id="user-submit-btn">
                            Save User Changes
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

<!-- Users directory -->
<div class="card" id="users-directory" style="margin-top: 1.5rem;">
    <div class="card-header">
        <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span>All Users (<?= count($users) ?>)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th class="hide-mobile">Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5" style="text-align:center; color:#64748b; padding:1.5rem;">No users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <strong style="color:#0f172a;"><?= htmlspecialchars($u['name']) ?></strong>
                                <?php if (!empty($u['phone'])): ?>
                                    <div style="font-size:0.75rem;color:#64748b;"><?= htmlspecialchars($u['phone']) ?></div>
                                <?php endif; ?>
                                <div class="hide-desktop" style="font-size:0.75rem;color:#64748b; margin-top:0.15rem;"><?= htmlspecialchars($u['email']) ?></div>
                            </td>
                            <td class="hide-mobile" style="font-size:0.85rem;"><?= htmlspecialchars($u['email']) ?></td>
                            <td>
                                <span class="badge badge-primary" style="text-transform: capitalize;">
                                    <?= htmlspecialchars(str_replace('_', ' ', $u['role'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($u['status'] === 'active'): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <a href="users.php?edit=<?= (int)$u['id'] ?>#user-form-card" class="btn btn-sm btn-secondary" title="Edit user (Super Admin)">Edit</a>
                                <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <input type="hidden" name="status" value="<?= htmlspecialchars($u['status']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline">
                                            <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:0.75rem;color:#94a3b8; margin-left:0.35rem;">You</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
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
                    <li>Dashboard, all freezer profiles</li>
                    <li>Add / edit / delete profiles</li>
                    <li>QR scan &amp; print</li>
                    <li>Manage Users (this page only)</li>
                    <li>Create / edit users</li>
                    <li>Activate / deactivate accounts</li>
                </ul>
            </div>

        </div>
        <p style="margin-top:1rem; font-size:0.8rem; color:#94a3b8;">
            Only <strong>Super Admin</strong> accounts can sign in. Create additional Super Admins from this page.
        </p>
    </div>
</div>

<script>
(function () {
    var nameInput = document.getElementById('name');
    var emailInput = document.getElementById('email');
    var phoneInput = document.getElementById('phone');
    var passwordInput = document.getElementById('password');
    var roleSelect = document.getElementById('role');
    var summary = document.getElementById('user-form-summary');
    var form = document.getElementById('user-create-form');

    function setFieldError(el, on) {
        if (!el) return;
        var group = el.closest('.form-group');
        el.classList.toggle('is-invalid', on);
        if (group) group.classList.toggle('has-error', on);
    }

    function syncSummary() {
        if (!summary || !roleSelect) return;
        var label = roleSelect.options[roleSelect.selectedIndex].text.split('—')[0].trim();
        var name = (nameInput && nameInput.value.trim()) || 'New user';
        summary.textContent = name + ' · ' + label;
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
        roleSelect.addEventListener('change', syncSummary);
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

    syncSummary();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
