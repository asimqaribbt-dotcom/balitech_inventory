<?php
/**
 * My profile - the signed in user can view and update their own details.
 */
require_once __DIR__ . '/init.php';
require_login();

$user   = current_user();
$errors = [];

if (is_post()) {
    csrf_verify();

    $fullName = (string) post('full_name');
    $email    = (string) post('email');
    $phone    = (string) post('phone');

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }
    if (!is_valid_email($email)) {
        $errors[] = 'Please provide a valid e-mail address.';
    } else {
        $duplicate = db_value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $user['id']]);
        if ((int) $duplicate > 0) {
            $errors[] = 'That e-mail address is already used by another account.';
        }
    }

    if (!$errors) {
        db_run('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?',
            [$fullName, $email, null_if_empty($phone), $user['id']]);
        log_activity('User', 'update', (int) $user['id'], 'Updated own profile details');
        refresh_user();
        set_flash('success', 'Your profile has been updated.');
        redirect('profile.php');
    }
}

$stats = [
    'assigned' => (int) db_value('SELECT COUNT(*) FROM inventory WHERE assigned_employee_id IS NOT NULL AND status = "assigned" AND branch_id = ?', [$user['branch_id']]),
    'records'  => (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE user_id = ?', [$user['id']]),
    'assets'   => (int) db_value('SELECT COUNT(*) FROM inventory WHERE created_by = ?', [$user['id']]),
    'maintenance' => (int) db_value('SELECT COUNT(*) FROM maintenance_records WHERE reported_by = ?', [$user['id']]),
];

$myActivity = db_all('SELECT module, action, description, created_at FROM activity_logs
                       WHERE user_id = ? ORDER BY created_at DESC LIMIT 10', [$user['id']]);

$page_title    = 'My Profile';
$page_subtitle = 'Account details and recent activity';
$active_menu   = 'profile';

require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">My profile</li></ol></nav>
        <h2>My profile</h2>
        <p>Manage your account details and review your recent activity.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('change_password.php') ?>"><i class="fa-solid fa-key me-2"></i>Change password</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger app-alert">
    <i class="fa-solid fa-circle-xmark me-2"></i>
    <?php foreach ($errors as $index => $error): ?><?= $index ? '<br>' : '' ?><?= e($error) ?><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <span class="avatar avatar-lg mx-auto mb-3"><?= e(user_initials($user['full_name'])) ?></span>
                <h3 class="mb-1"><?= e($user['full_name']) ?></h3>
                <div class="text-muted-sm mb-2">@<?= e($user['username']) ?></div>
                <span class="badge badge-soft badge-primary"><?= e(role_label($user['role'])) ?></span>
                <hr>
                <ul class="info-list text-start">
                    <li><span class="k">Email</span><span class="v"><?= e($user['email']) ?></span></li>
                    <li><span class="k">Phone</span><span class="v"><?= e($user['phone'] ?: '-') ?></span></li>
                    <li><span class="k">Branch</span><span class="v"><?= e($user['branch_name'] ?: 'All branches') ?></span></li>
                    <li><span class="k">Department</span><span class="v"><?= e($user['department_name'] ?: '-') ?></span></li>
                    <li><span class="k">Status</span><span class="v"><?= active_badge($user['status']) ?></span></li>
                    <li><span class="k">Last login</span><span class="v"><?= e(fdatetime($user['last_login'])) ?></span></li>
                    <li><span class="k">Member since</span><span class="v"><?= e(fdate($user['created_at'])) ?></span></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="row g-3 mb-3">
            <div class="col-md-6 col-xl-3">
                <div class="stat-card">
                    <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-user-check"></i></span>
                    <span class="stat-meta">
                        <span class="stat-value d-block"><?= number_format($stats['assigned']) ?></span>
                        <span class="stat-label d-block">Branch assignments</span>
                    </span>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-card">
                    <span class="stat-icon bg-soft-green"><i class="fa-solid fa-box-open"></i></span>
                    <span class="stat-meta">
                        <span class="stat-value d-block"><?= number_format($stats['assets']) ?></span>
                        <span class="stat-label d-block">Assets created</span>
                    </span>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-card">
                    <span class="stat-icon bg-soft-amber"><i class="fa-solid fa-screwdriver-wrench"></i></span>
                    <span class="stat-meta">
                        <span class="stat-value d-block"><?= number_format($stats['maintenance']) ?></span>
                        <span class="stat-label d-block">Faults reported</span>
                    </span>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-card">
                    <span class="stat-icon bg-soft-navy"><i class="fa-solid fa-clipboard-list"></i></span>
                    <span class="stat-meta">
                        <span class="stat-value d-block"><?= number_format($stats['records']) ?></span>
                        <span class="stat-label d-block">Logged actions</span>
                    </span>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <div class="stat-icon bg-soft-navy" style="width:40px;height:40px;font-size:15px"><i class="fa-regular fa-id-card"></i></div>
                <div><h3 class="mb-0">Update my details</h3><p class="sub mb-0">Your role and branch are managed by an administrator</p></div>
            </div>
            <form method="post" action="<?= url('profile.php') ?>" class="needs-validation" novalidate>
                <?= csrf_field() ?>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="full_name">Full name <span class="req">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name"
                                   value="<?= e(post('full_name', $user['full_name'])) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email address <span class="req">*</span></label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= e(post('email', $user['email'])) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone"
                                   value="<?= e(post('phone', $user['phone'])) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" value="<?= e($user['username']) ?>" disabled>
                            <div class="form-text">Usernames can only be changed by a Super Admin.</div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-brand" href="<?= url('dashboard.php') ?>">Cancel</a>
                    <button class="btn btn-brand" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save changes</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="stat-icon bg-soft-gray" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div><h3 class="mb-0">My recent activity</h3><p class="sub mb-0">Your last 10 recorded actions</p></div>
            </div>
            <div class="card-body">
                <?php if (!$myActivity): ?>
                    <div class="empty-state py-4"><i class="fa-regular fa-clock"></i><p class="mb-0">No activity recorded yet.</p></div>
                <?php else: ?>
                <div class="timeline">
                    <?php foreach ($myActivity as $log): ?>
                    <div class="timeline-item">
                        <div class="t-title"><?= e($log['description']) ?></div>
                        <div class="t-meta"><?= e($log['module']) ?> &middot; <?= e(ucfirst(str_replace('_', ' ', $log['action']))) ?>
                            &middot; <?= e(time_ago($log['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
