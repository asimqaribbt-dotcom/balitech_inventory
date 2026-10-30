<?php
/**
 * Change password - requires the current password and uses
 * password_hash() / password_verify() for secure storage.
 */
require_once __DIR__ . '/init.php';
require_login();

$user   = current_user();
$errors = [];

if (is_post()) {
    csrf_verify();

    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        $errors[] = 'All password fields are required.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'The new password must be at least 8 characters long.';
    }
    if ($new !== $confirm) {
        $errors[] = 'The new password and confirmation do not match.';
    }
    if ($new === $current && $new !== '') {
        $errors[] = 'Please choose a password different from your current one.';
    }

    if (!$errors) {
        $row = db_row('SELECT password FROM users WHERE id = ?', [$user['id']]);
        if (!$row || !password_verify($current, $row['password'])) {
            log_activity('Auth', 'login_failed', (int) $user['id'], 'Incorrect current password on change password form');
            $errors[] = 'Your current password is incorrect.';
        } else {
            db_run('UPDATE users SET password = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            log_activity('User', 'update', (int) $user['id'], 'Changed own account password');
            session_regenerate_id(true);
            set_flash('success', 'Your password has been changed successfully.');
            redirect('profile.php');
        }
    }
}

$page_title    = 'Change Password';
$page_subtitle = 'Keep your account secure';
$active_menu   = 'profile';

require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= url('profile.php') ?>">My profile</a></li>
            <li class="breadcrumb-item active">Change password</li></ol></nav>
        <h2>Change password</h2>
        <p>Choose a strong password of at least 8 characters.</p>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger app-alert">
    <i class="fa-solid fa-circle-xmark me-2"></i>
    <?php foreach ($errors as $index => $error): ?><?= $index ? '<br>' : '' ?><?= e($error) ?><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header">
                <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-key"></i></div>
                <div><h3 class="mb-0">Password details</h3><p class="sub mb-0">@<?= e($user['username']) ?> &middot; <?= e(role_label($user['role'])) ?></p></div>
            </div>
            <form method="post" action="<?= url('change_password.php') ?>" class="needs-validation" novalidate>
                <?= csrf_field() ?>
                <div class="card-body">
                    <div class="form-section-title">Current credentials</div>
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password <span class="req">*</span></label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autofocus>
                    </div>

                    <div class="form-section-title">New password</div>
                    <div class="mb-3">
                        <label class="form-label" for="new_password">New password <span class="req">*</span></label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required>
                        <div class="form-text">Use a mix of letters, numbers and symbols.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="confirm_password">Confirm new password <span class="req">*</span></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" required>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-brand" href="<?= url('profile.php') ?>">Cancel</a>
                    <button class="btn btn-brand" type="submit"><i class="fa-solid fa-shield-halved me-2"></i>Update password</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-amber" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-shield-halved"></i></div>
                <div><h3 class="mb-0">Security guidance</h3><p class="sub mb-0">How BALITECH protects your account</p></div>
            </div>
            <div class="card-body">
                <div class="alert-row info">
                    <i class="fa-solid fa-lock" style="color:var(--info)"></i>
                    <div>
                        <div class="a-title">Bcrypt password hashing</div>
                        <div class="a-meta">Passwords are stored with PHP <code>password_hash()</code> and verified with <code>password_verify()</code> - never in plain text.</div>
                    </div>
                </div>
                <div class="alert-row">
                    <i class="fa-solid fa-user-shield" style="color:var(--warning)"></i>
                    <div>
                        <div class="a-title">Session protection</div>
                        <div class="a-meta">The session id is regenerated on every sign-in and password change to prevent session fixation.</div>
                    </div>
                </div>
                <div class="alert-row info">
                    <i class="fa-solid fa-clipboard-list" style="color:var(--info)"></i>
                    <div>
                        <div class="a-title">Audit trail</div>
                        <div class="a-meta">Sign-in attempts and account changes are written to the activity log.</div>
                    </div>
                </div>
                <ul class="info-list mt-3">
                    <li><span class="k">Minimum length</span><span class="v">8 characters</span></li>
                    <li><span class="k">Last login</span><span class="v"><?= e(fdatetime($user['last_login'])) ?></span></li>
                    <li><span class="k">Account status</span><span class="v"><?= active_badge($user['status']) ?></span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
