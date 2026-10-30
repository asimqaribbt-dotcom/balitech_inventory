<?php
/**
 * One-time installer: creates the default BALITECH user accounts with
 * properly hashed passwords.
 *
 *  1. Open http://localhost/balitech_inventory/setup.php in the browser
 *  2. Press "Install default accounts"
 *  3. Delete setup.php (or leave the generated lock file in place)
 */
require_once __DIR__ . '/init.php';

$lockFile = APP_ROOT . '/config/installed.lock';
$installed = file_exists($lockFile);
$messages  = [];
$ready     = true;
$missing   = [];

// --- Environment checks ---------------------------------------------
$requiredTables = ['users', 'branches', 'departments', 'employees', 'asset_categories',
                   'brands', 'inventory', 'asset_assignments', 'asset_transfers',
                   'maintenance_records', 'stock_transactions', 'activity_logs'];

try {
    $found = db_all('SHOW TABLES');
    $found = array_map(static function ($row) { return array_values($row)[0]; }, $found);
    $missing = array_diff($requiredTables, $found);
    if ($missing) {
        $ready = false;
    }
} catch (Throwable $e) {
    $ready = false;
    $messages[] = ['danger', 'Database error: ' . $e->getMessage()];
}

$defaultPassword = 'Admin@123';

if (is_post() && $ready) {
    csrf_verify();

    $password = (string) post('password');
    if (strlen($password) < 8) {
        $password = $defaultPassword;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $branchA = db_value('SELECT id FROM branches ORDER BY id LIMIT 1');
    $branchB = db_value('SELECT id FROM branches ORDER BY id LIMIT 1 OFFSET 1');
    $branchB = $branchB ?: $branchA;
    $deptIT  = db_value('SELECT id FROM departments WHERE department_name LIKE "%IT%" LIMIT 1');
    $deptAny = db_value('SELECT id FROM departments ORDER BY id LIMIT 1');
    $deptOps = db_value('SELECT id FROM departments WHERE department_name LIKE "%Operations%" LIMIT 1') ?: $deptAny;

    $accounts = [
        ['superadmin',  'Ahsan Baloch',  'admin@balitech.com',        'super_admin',  $branchA, $deptIT ?: $deptAny, '+92-300-1234567'],
        ['itadmin',     'Usman Sheikh',  'it.admin@balitech.com',     'it_admin',     $branchA, $deptIT ?: $deptAny, '+92-301-2244668'],
        ['branchstaff', 'Hamza Sultan',  'branch.staff@balitech.com', 'branch_staff', $branchB, $deptOps,           '+92-302-3344556'],
        ['deptmanager', 'Fahad Iqbal',   'sales.manager@balitech.com','dept_manager', $branchA, $deptAny,           '+92-303-4455667'],
    ];

    try {
        db()->beginTransaction();
        foreach ($accounts as $account) {
            list($username, $fullName, $email, $role, $branchId, $deptId, $phone) = $account;
            $existing = db_row('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1', [$username, $email]);
            if ($existing) {
                db_run('UPDATE users SET full_name = ?, email = ?, password = ?, role = ?, branch_id = ?,
                                            department_id = ?, phone = ?, status = "active" WHERE id = ?',
                    [$fullName, $email, $hash, $role, $branchId, $deptId, $phone, $existing['id']]);
            } else {
                db_run('INSERT INTO users (username, full_name, email, password, role, branch_id, department_id,
                                           phone, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active", NOW())',
                    [$username, $fullName, $email, $hash, $role, $branchId, $deptId, $phone]);
            }
        }
        db()->commit();

        @file_put_contents($lockFile, 'Installed on ' . date('c') . PHP_EOL);
        $installed = true;
        $messages[] = ['success', 'Default accounts created successfully. You can now sign in.'];
        log_activity('Setup', 'install', null, 'Default user accounts were created by setup.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('[BALITECH][setup] ' . $e->getMessage());
        $messages[] = ['danger', 'Could not create the accounts: ' . $e->getMessage()];
    }
} elseif (is_post() && !$ready) {
    $messages[] = ['danger', 'The database is not ready yet. Import database/balitech_inventory.sql first.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup &middot; <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= url('assets/img/favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body style="background:var(--gray-bg)">
<div class="container py-5" style="max-width:880px">

    <div class="d-flex align-items-center gap-3 mb-4">
        <span class="brand-mark">B</span>
        <span>
            <span class="d-block" style="font-weight:700;letter-spacing:1.2px;color:var(--navy-800);font-size:19px">BALITECH</span>
            <span class="d-block text-muted" style="font-size:11px;letter-spacing:1.8px;text-transform:uppercase">IT Inventory &middot; Installation</span>
        </span>
    </div>

    <?php foreach ($messages as $message): ?>
    <div class="alert alert-<?= e($message[0]) ?> app-alert d-flex align-items-center">
        <i class="fa-solid fa-circle-info me-2"></i><?= e($message[1]) ?>
    </div>
    <?php endforeach; ?>

    <div class="card mb-4">
        <div class="card-header">
            <div class="stat-icon bg-soft-blue" style="width:42px;height:42px;font-size:16px"><i class="fa-solid fa-database"></i></div>
            <div>
                <h3 class="mb-0">Environment check</h3>
                <p class="sub mb-0">PHP <?= e(PHP_VERSION) ?> &middot; MySQL <code><?= e(DB_NAME) ?></code> @ <?= e(DB_HOST) ?></p>
            </div>
        </div>
        <div class="card-body">
            <?php if ($missing): ?>
                <p class="mb-2 text-danger"><i class="fa-solid fa-circle-xmark me-2"></i>The following tables are missing:</p>
                <p class="mb-0"><code><?= e(implode(', ', $missing)) ?></code></p>
                <hr>
                <p class="mb-0 text-muted" style="font-size:13px">
                    Import <code>database/balitech_inventory.sql</code> using phpMyAdmin
                    (Database <b><?= e(DB_NAME) ?></b> &rarr; Import &rarr; Choose file &rarr; Go) and reload this page.
                </p>
            <?php else: ?>
                <p class="mb-0 text-success"><i class="fa-solid fa-circle-check me-2"></i>Database connected - all required tables are present.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($ready): ?>
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-green" style="width:42px;height:42px;font-size:16px"><i class="fa-solid fa-user-shield"></i></div>
            <div>
                <h3 class="mb-0">Create the default accounts</h3>
                <p class="sub mb-0">Passwords are stored using PHP <code>password_hash()</code> (bcrypt).</p>
            </div>
        </div>
        <div class="card-body">
            <?php if ($installed): ?>
                <div class="alert alert-warning app-alert">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>
                    Setup has already been run. Running it again will simply reset the passwords of the default accounts.
                    For production, delete <code>setup.php</code> from the server.
                </div>
            <?php endif; ?>

            <table class="table mb-4">
                <thead>
                    <tr><th>Username</th><th>Role</th><th>Email</th><th>Default password</th></tr>
                </thead>
                <tbody>
                    <tr><td class="fw-code">superadmin</td><td>Super Admin</td><td>admin@balitech.com</td><td><code><?= e($defaultPassword) ?></code></td></tr>
                    <tr><td class="fw-code">itadmin</td><td>IT Admin</td><td>it.admin@balitech.com</td><td><code><?= e($defaultPassword) ?></code></td></tr>
                    <tr><td class="fw-code">branchstaff</td><td>Branch IT Staff</td><td>branch.staff@balitech.com</td><td><code><?= e($defaultPassword) ?></code></td></tr>
                    <tr><td class="fw-code">deptmanager</td><td>Department Manager</td><td>sales.manager@balitech.com</td><td><code><?= e($defaultPassword) ?></code></td></tr>
                </tbody>
            </table>

            <form method="post" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-7">
                    <label class="form-label" for="password">Password to apply to these accounts</label>
                    <input type="text" class="form-control" id="password" name="password"
                           value="<?= e($defaultPassword) ?>" minlength="8" required>
                    <div class="form-text">Minimum 8 characters. Change it from "Change password" after the first sign in.</div>
                </div>
                <div class="col-md-5">
                    <button type="submit" class="btn btn-brand w-100">
                        <i class="fa-solid fa-gear me-2"></i><?= $installed ? 'Reset default accounts' : 'Install default accounts' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between mt-4" style="font-size:12.5px">
        <span class="text-muted">After setup, open <a href="<?= url('login.php') ?>">the login page</a>.</span>
        <span class="text-muted">Delete <code>setup.php</code> when finished.</span>
    </div>
</div>
</body>
</html>
