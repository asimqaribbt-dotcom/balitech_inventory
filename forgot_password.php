<?php
/**
 * Password reset information page.
 * The system runs on local / shared hosting without an SMTP configuration,
 * so password resets are handled by the IT administrator through the
 * Users & Roles module.
 */
require_once __DIR__ . '/init.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot password &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body style="background:var(--gray-bg)">
<div class="container py-5" style="max-width:620px">
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-amber" style="width:42px;height:42px;font-size:16px"><i class="fa-solid fa-key"></i></div>
            <div>
                <h3 class="mb-0">Forgot your password?</h3>
                <p class="sub mb-0">Password recovery for BALITECH IT Inventory</p>
            </div>
        </div>
        <div class="card-body">
            <p style="font-size:13.6px" class="text-muted">
                For security reasons the system does not send passwords by e-mail. Password resets are performed by an
                administrator:
            </p>
            <ol style="font-size:13.6px;line-height:1.9">
                <li>Ask your IT administrator (Super Admin) to open <b>Users &amp; Roles</b>.</li>
                <li>The administrator edits your account and sets a new password.</li>
                <li>Sign in and change it again from <b>Change password</b>.</li>
            </ol>
            <p class="mb-0" style="font-size:13.6px">
                Still locked out? A Super Admin can reset any password from the
                <code>users</code> table in phpMyAdmin using PHP's <code>password_hash()</code>, or an IT
                administrator can re-run <a href="<?= url('setup.php') ?>">setup.php</a>.
            </p>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <a href="<?= url('login.php') ?>" class="btn btn-brand btn-sm"><i class="fa-solid fa-arrow-left me-2"></i>Back to sign in</a>
        </div>
    </div>
</div>
</body>
</html>
