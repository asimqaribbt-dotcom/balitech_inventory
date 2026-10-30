<?php
/**
 * Users & Roles - create a new user account.
 * Only the Super Admin role has the "users" ability.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('users');

$errors   = [];
$branches = db_all('SELECT id, branch_name FROM branches ORDER BY branch_name');

$form = [
    'username'      => '',
    'full_name'     => '',
    'email'         => '',
    'phone'         => '',
    'role'          => 'branch_staff',
    'branch_id'     => '',
    'department_id' => '',
    'status'        => 'active',
];

if (is_post()) {
    csrf_verify();

    $form['username']      = strtolower(post('username'));
    $form['full_name']     = post('full_name');
    $form['email']         = strtolower(post('email'));
    $form['phone']         = post('phone');
    $form['role']          = isset(roles_list()[post('role')]) ? post('role') : 'branch_staff';
    $form['branch_id']     = (int) post('branch_id');
    $form['department_id'] = (int) post('department_id');
    $form['status']        = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    $password        = (string) post('password');
    $passwordConfirm = (string) post('password_confirm');

    /* Username */
    if ($form['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[a-z0-9_]{4,30}$/', $form['username'])) {
        $errors[] = 'Username must be 4-30 characters and contain only lowercase letters, numbers and underscores.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE username = ?', [$form['username']]) > 0) {
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
    } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$form['email']]) > 0) {
        $errors[] = 'That email address is already registered.';
    }

    /* Password */
    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $passwordConfirm) {
        $errors[] = 'The password confirmation does not match.';
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

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db_run(
            'INSERT INTO users
                (username, full_name, email, password, role, branch_id, department_id, phone, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $form['username'],
                $form['full_name'],
                $form['email'],
                $hash,
                $form['role'],
                $form['branch_id'] > 0 ? $form['branch_id'] : null,
                $form['department_id'] > 0 ? $form['department_id'] : null,
                null_if_empty($form['phone']),
                $form['status'],
            ]
        );
        $newId = (int) db()->lastInsertId();
        log_activity('User', 'create', $newId, 'Created user "' . $form['username'] . '" (' . role_label($form['role']) . ')');
        set_flash('success', 'User "' . $form['full_name'] . '" was created successfully.');
        redirect('users/index.php');
    }
}

$page_title    = 'Add User';
$page_subtitle = 'Create a new system account';
$active_menu   = 'users';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('users/index.php') ?>">Users &amp; Roles</a></li>
                <li class="breadcrumb-item active">Add User</li>
            </ol>
        </nav>
        <h2>Add User</h2>
        <p>Create an account and assign a role, branch and department</p>
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

<form method="post" action="<?= url('users/create.php') ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-user-plus"></i></div>
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
                           value="<?= e($form['username']) ?>" placeholder="e.g. jsmith">
                    <div class="form-text">4-30 lowercase letters, numbers or underscores.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Full Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="full_name" maxlength="120" required
                           value="<?= e($form['full_name']) ?>" placeholder="Full name">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email <span class="req">*</span></label>
                    <input type="email" class="form-control" name="email" maxlength="120" required
                           value="<?= e($form['email']) ?>" placeholder="user@balitech.com">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone" maxlength="30"
                           value="<?= e($form['phone']) ?>" placeholder="+92-...">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Role <span class="req">*</span></label>
                    <select class="form-select" name="role" required>
                        <?php foreach (roles_list() as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= selected($key, $form['role']) ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active"<?= selected('active', $form['status']) ?>>Active</option>
                        <option value="inactive"<?= selected('inactive', $form['status']) ?>>Inactive</option>
                    </select>
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
                    <div class="form-text">Required for Branch IT Staff.</div>
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

            <div class="form-section-title mt-4">Credentials</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Password <span class="req">*</span></label>
                    <input type="password" class="form-control" name="password" minlength="8" required autocomplete="new-password">
                    <div class="form-text">Minimum 8 characters.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm Password <span class="req">*</span></label>
                    <input type="password" class="form-control" name="password_confirm" minlength="8" required autocomplete="new-password">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('users/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Save User</button>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
