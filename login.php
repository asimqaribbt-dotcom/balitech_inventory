<?php
/**
 * Secure login screen (BALITECH branding).
 */
require_once __DIR__ . '/init.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error    = null;
$username = '';
$setupNeeded = false;

// Detect a fresh installation without user accounts
try {
    $setupNeeded = ((int) db_value('SELECT COUNT(*) FROM users')) === 0;
} catch (Throwable $e) {
    // database errors are reported by config/database.php
}

if (is_post()) {
    csrf_verify();
    $username = (string) post('username');
    $password = (string) ($_POST['password'] ?? '');

    $error = attempt_login($username, $password);

    if ($error === null) {
        $target = $_SESSION['redirect_after_login'] ?? null;
        unset($_SESSION['redirect_after_login']);
        set_flash('success', 'Welcome back, ' . current_user()['full_name'] . '!');
        redirect($target ?: 'dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= url('assets/img/favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-page">

    <section class="auth-visual">
        <div class="auth-brand">
            <span class="brand-mark">B</span>
            <span>
                <span class="b-name d-block">BALITECH</span>
                <span class="b-sub d-block">Private Limited</span>
            </span>
        </div>

        <div class="auth-hero">
            <h2>IT Asset &amp; Inventory<br>Management System</h2>
            <p>Track every computer, monitor, network device and accessory across all BALITECH
               branches from a single, centralised dashboard. Assign equipment, monitor stock
               levels and stay ahead of maintenance.</p>

            <ul class="auth-features">
                <li><span class="f-ico"><i class="fa-solid fa-boxes-stacked"></i></span> Centralised multi-branch asset register</li>
                <li><span class="f-ico"><i class="fa-solid fa-user-check"></i></span> Employee wise assignment &amp; return history</li>
                <li><span class="f-ico"><i class="fa-solid fa-screwdriver-wrench"></i></span> Maintenance tracking with repair costs</li>
                <li><span class="f-ico"><i class="fa-solid fa-chart-pie"></i></span> Live dashboards and exportable reports</li>
            </ul>
        </div>

        <div class="auth-copy">
            &copy; <?= date('Y') ?> BALITECH Pvt. Ltd. &middot; All rights reserved.
        </div>
    </section>

    <section class="auth-form-side">
        <div class="auth-card">
            <div class="brand-mobile mb-4 d-flex align-items-center gap-3">
                <span class="brand-mark">B</span>
                <span>
                    <span class="b-name d-block" style="color:var(--navy-800);font-weight:700;letter-spacing:1px">BALITECH</span>
                    <span class="b-sub d-block text-muted" style="font-size:10.5px;letter-spacing:1.6px;text-transform:uppercase">IT Inventory</span>
                </span>
            </div>

            <h3>Sign in to your account</h3>
            <p class="lead-text">Enter your credentials to access the IT inventory dashboard.</p>

            <?php if ($setupNeeded): ?>
            <div class="alert alert-warning app-alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                No user accounts were found. Run <a href="<?= url('setup.php') ?>"><b>setup.php</b></a> once to create the default accounts.
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger app-alert">
                <i class="fa-solid fa-circle-xmark me-2"></i><?= e($error) ?>
            </div>
            <?php elseif ($msg = render_flash()): ?>
                <?= $msg ?>
            <?php endif; ?>

            <form method="post" action="<?= url('login.php') ?>" class="needs-validation" novalidate>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label" for="username">Username or email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-regular fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= e($username) ?>" placeholder="e.g. superadmin" required autofocus>
                    </div>
                    <div class="invalid-feedback d-block" style="display:none"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <div class="pw-wrap">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="Enter your password" required>
                        </div>
                        <button type="button" class="pw-toggle" id="pwToggle" tabindex="-1"
                                aria-label="Show password" style="right:10px;top:30px">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4" style="font-size:13px">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    <a href="<?= url('forgot_password.php') ?>" style="color:var(--brand)">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-brand w-100">
                    <i class="fa-solid fa-right-to-bracket me-2"></i>Sign in
                </button>
            </form>

            <div class="demo-accounts">
                <b>Default accounts</b> (create them by running <code>setup.php</code> once):<br>
                <code>superadmin</code> &middot; <code>itadmin</code> &middot; <code>branchstaff</code> &middot; <code>deptmanager</code>
                &mdash; password <code>Admin@123</code>
            </div>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('pwToggle').addEventListener('click', function () {
    var field = document.getElementById('password');
    var icon  = this.querySelector('i');
    var show  = field.type === 'password';
    field.type = show ? 'text' : 'password';
    icon.className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
});
</script>
</body>
</html>
