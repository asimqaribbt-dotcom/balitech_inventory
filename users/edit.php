<?php
/**
 * Users & Roles - edit a user account (and handle password resets).
 * Only the Super Admin role has the "users" ability.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('users');

$id     = (int) get_param('id', post('id', 0));
$record = db_row('SELECT * FROM users WHERE id = ?', [$id]);

if (!$record) {
    set_flash('danger', 'That user account could not be found.');
    redirect('users/index.php');
}

$currentId = current_user_id();
$isSelf    = $id === $currentId;

$errors   = [];
$branches = db_all('SELECT id, branch_name FROM branches ORDER BY branch_name');

/* ---------------------------------------------------------------------
 * POST handling (password reset or full update)
 * ------------------------------------------------------------------ */
if (is_post()) {
    csrf_verify();

    /* ---- Quick password reset (from the list page modal) ---- */
    if (post('action') === 'reset_password') {
        $new      = (string) post('new_password');
        $confirm  = (string) post('new_password_confirm');

        if (strlen($new) < 8) {
            set_flash('danger', 'The new password must be at least 8 characters long.');
            redirect('users/index.php');
        }
        if ($new !== $confirm) {
            set_flash('danger', 'The password confirmation does not match.');
            redirect('users/index.php');
        }

        db_run('UPDATE users SET password = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $id]);
        log_activity('User', 'reset_password', $id, 'Reset password for user "' . $record['username'] . '"');
        set_flash('success', 'Password for "' . $record['full_name'] . '" was reset successfully.');
        redirect('users/index.php');
    }

    /* ---- Full account update ---- */
    $form = [
        'username'      => strtolower(post('username')),
        'full_name'     => post('full_name'),
        'email'         => strtolower(post('email')),
        'phone'         => post('phone'),
        'role'          => isset(roles_list()[post('role')]) ? post('role') : $record['role'],
        'branch_id'     => (int) post('branch_id'),
        'department_id' => (int) post('department_id'),
        'status'        => in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active',
    ];

    $newPassword     = (string) post('password');
    $passwordConfirm = (string) post('password_confirm');

    /* Username */
    if ($form['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[a-z0-9_]{4,30}$/', $form['username'])) {
        $errors[] = 'Username must be 4-30 characters and contain only lowercase letters, numbers and underscores.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$form['username'], $id]) > 0) {
        $errors[] = 'That username is already taken.';
    }

    /* Full name */
    if ($form['full_name'] === '') {
        $errors[] = 'Full name is required.';
    }

    /* Email */
    if ($form['email'] === '') {
        $errors[] = 'Email address is required.';
    } elseif (!is_valid_email($form['email'])) {
        $errors[] = 'Please enter a valid email address.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$form['email'], $id]) > 0) {
        $errors[] = 'That email address is already registered.';
    }

    /* Optional password change */
    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            $errors[] = 'The new password must be at least 8 characters long.';
        } elseif ($newPassword !== $passwordConfirm) {
            $errors[] = 'The password confirmation does not match.';
        }
    }

    /* Branch / department placement */
    if ($form['role'] === 'branch_staff' && $form['branch_id'] <= 0) {
        $errors[] = 'A branch is required for Branch IT Staff accounts.';
    }
    if ($form['branch_id'] > 0
        && (int) db_value('SELECT COUNT(*) FROM branches WHERE id = ?', [$form['branch_id']]) === 0) {
        $errors[] = 'The selected branch does not exist.';
    }
    if ($form['department_id'] > 0
        && (int) db_value('SELECT COUNT(*) FROM departments WHERE id = ?', [$form['department_id']]) === 0) {
        $errors[] = 'The selected department does not exist.';
    }

    /* Self protection: never let the admin demote or deactivate themselves */
    if ($isSelf) {
        if ($form['role'] !== 'super_admin') {
            $errors[] = 'You cannot change your own role away from Super Admin.';
        }
        if ($form['status'] !== 'active') {
            $errors[] = 'You cannot deactivate your own account.';
        }
    }

    /* Never remove the last active Super Admin */
    if ($record['role'] === 'super_admin' && $record['status'] === 'active'
        && ($form['role'] !== 'super_admin' || $form['status'] !== 'active')) {
        $otherSupers = (int) db_value(
            'SELECT COUNT(*) FROM users WHERE role = "super_admin" AND status = "active" AND id <> ?',
            [$id]
        );
        if ($otherSupers === 0) {
            $errors[] = 'You cannot demote or deactivate the last active Super Admin.';
        }
    }

    if (!$errors) {
        $sql    = 'UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, branch_id = ?,
                          department_id = ?, phone = ?, status = ?';
        $params = [
            $form['username'],
            $form['full_name'],
            $form['email'],
            $form['role'],
            $form['branch_id'] > 0 ? $form['branch_id'] : null,
            $form['department_id'] > 0 ? $form['department_id'] : null,
            null_if_empty($form['phone']),
            $form['status'],
        ];
        if ($newPassword !== '') {
            $sql .= ', password = ?';
            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = ?';
        $params[] = $id;

        db_run($sql, $params);

        log_activity('User', 'update', $id, 'Updated user "' . $form['username'] . '" (' . role_label($form['role']) . ')');
        if ($newPassword !== '') {
            log_activity('User', 'reset_password', $id, 'Password changed for user "' . $form['username'] . '"');
        }
        if ($isSelf) {
            refresh_user();
        }
        set_flash('success', 'User "' . $form['full_name'] . '" was updated successfully.');
        redirect('users/index.php');
    }
} else {
    $form = [
        'username'      => $record['username'],
        'full_name'     => $record['full_name'],
        'email'         => $record['email'],
        'phone'         => $record['phone'],
        'role'          => $record['role'],
        'branch_id'     => $record['branch_id'],
        'department_id' => $record['department_id'],
        'status'        => $record['status'],
    ];
}

$page_title    = 'Edit User';
$page_subtitle = 'Update account details and access';
$active_menu   = 'users';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('users/index.php') ?>">Users &amp; Roles</a></li>
                <li class="breadcrumb-item active">Edit User</li>
            </ol>
        </nav>
        <h2>Edit User</h2>
        <p><?= e($record['full_name']) ?> &middot; @<?= e($record['username']) ?><?= $isSelf ? ' (your account)' : '' ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('users/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to list</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger app-alert">
    <i class="fa-solid fa-circle-xmark me-2"></i><b>Please fix the following:</b>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="<?= url('users/edit.php?id=' . $id) ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-user-pen"></i></div>
            <div>
                <h3 class="mb-0">Account details</h3>
                <p class="sub mb-0">Identity, role and credentials</p>
            </div>
        </div>
        <div class="card-body">
            <div class="form-section-title">Identity</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Username <span class="req">*</span></label>
                    <input type="text" class="form-control" name="username" maxlength="30" required
                           value="<?= e($form['username']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Full Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="full_name" maxlength="120" required
                           value="<?= e($form['full_name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email <span class="req">*</span></label>
                    <input type="email" class="form-control" name="email" maxlength="120" required
                           value="<?= e($form['email']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone" maxlength="30"
                           value="<?= e($form['phone']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Role <span class="req">*</span></label>
                    <select class="form-select" name="role" required<?= $isSelf ? ' disabled' : '' ?>>
                        <?php foreach (roles_list() as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= selected($key, $form['role']) ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isSelf): ?>
                    <input type="hidden" name="role" value="<?= e($form['role']) ?>">
                    <div class="form-text">You cannot change your own role.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status"<?= $isSelf ? ' disabled' : '' ?>>
                        <option value="active"<?= selected('active', $form['status']) ?>>Active</option>
                        <option value="inactive"<?= selected('inactive', $form['status']) ?>>Inactive</option>
                    </select>
                    <?php if ($isSelf): ?>
                    <input type="hidden" name="status" value="<?= e($form['status']) ?>">
                    <div class="form-text">You cannot deactivate your own account.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-section-title mt-4">Placement</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Branch</label>
                    <select class="form-select" name="branch_id" id="branch_id">
                        <option value="">-- Select branch --</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $form['branch_id']) ?>><?= e($b['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_id" id="department_id"
                            data-cascade="departments" data-url="api/options.php"
                            data-parent="#branch_id" data-placeholder="-- Select department --"
                            data-selected="<?= e($form['department_id']) ?>"></select>
                    <div class="form-text">Departments are filtered by the selected branch.</div>
                </div>
            </div>

            <div class="form-section-title mt-4">Change password</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">New Password</label>
                    <input type="password" class="form-control" name="password" minlength="8" autocomplete="new-password">
                    <div class="form-text">Leave empty to keep the current password.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" name="password_confirm" minlength="8" autocomplete="new-password">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('users/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Update User</button>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
