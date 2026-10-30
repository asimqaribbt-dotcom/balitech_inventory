<?php
/**
 * Users & Roles - list, filter and manage system user accounts.
 * Only the Super Admin role has the "users" ability.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('users');

$currentId = current_user_id();

/* ---------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */
$search       = get_param('q');
$roleFilter   = get_param('role');
$branchFilter = (int) get_param('branch_id');
$statusFilter = get_param('status');
if (!isset(roles_list()[$roleFilter])) { $roleFilter = ''; }
if (!in_array($statusFilter, ['active', 'inactive'], true)) { $statusFilter = ''; }

$where  = ' WHERE 1 = 1';
$params = [];
if ($search !== '') {
    $where .= ' AND (u.username LIKE :s1 OR u.full_name LIKE :s2 OR u.email LIKE :s3)';
    $like = '%' . $search . '%';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
}
if ($roleFilter !== '') {
    $where .= ' AND u.role = :f_role';
    $params['f_role'] = $roleFilter;
}
if ($branchFilter > 0) {
    $where .= ' AND u.branch_id = :f_branch';
    $params['f_branch'] = $branchFilter;
}
if ($statusFilter !== '') {
    $where .= ' AND u.status = :f_status';
    $params['f_status'] = $statusFilter;
}

/* ---------------------------------------------------------------------
 * Global summary figures
 * ------------------------------------------------------------------ */
$totalUsers = (int) db_value('SELECT COUNT(*) FROM users');
$roleCounts = [];
foreach (db_all('SELECT role, COUNT(*) AS total FROM users GROUP BY role') as $row) {
    $roleCounts[$row['role']] = (int) $row['total'];
}
$activeUsers   = (int) db_value('SELECT COUNT(*) FROM users WHERE status = "active"');
$inactiveUsers = $totalUsers - $activeUsers;

/* ---------------------------------------------------------------------
 * Pagination + list query
 * ------------------------------------------------------------------ */
$perPage = ITEMS_PER_PAGE;
$total   = (int) db_value("SELECT COUNT(*) FROM users u $where", $params);
$page    = max(1, (int) get_param('page', 1));
$page    = min($page, max(1, (int) ceil($total / $perPage)));
$offset  = ($page - 1) * $perPage;

$users = db_all(
    "SELECT u.id, u.username, u.full_name, u.email, u.role, u.phone, u.status, u.last_login,
            u.branch_id, u.department_id, b.branch_name, d.department_name
       FROM users u
       LEFT JOIN branches b ON b.id = u.branch_id
       LEFT JOIN departments d ON d.id = u.department_id
       $where
      ORDER BY u.full_name ASC
      LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    $params
);

$branches = db_all('SELECT id, branch_name FROM branches ORDER BY branch_name');

$roleColors = [
    'super_admin'  => 'danger',
    'it_admin'     => 'primary',
    'branch_staff' => 'info',
    'dept_manager' => 'warning',
];

$page_title    = 'Users & Roles';
$page_subtitle = 'Manage system accounts and access levels';
$active_menu   = 'users';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Users &amp; Roles</li>
            </ol>
        </nav>
        <h2>Users &amp; Roles</h2>
        <p><?= e(APP_COMPANY) ?> &middot; <?= (int) $totalUsers ?> account(s)</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-brand" href="<?= url('users/create.php') ?>"><i class="fa-solid fa-user-plus me-2"></i>Add User</a>
    </div>
</div>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-navy"><i class="fa-solid fa-users"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($totalUsers) ?></span><span class="stat-label d-block">Total Users</span></span>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-red"><i class="fa-solid fa-user-shield"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($roleCounts['super_admin'] ?? 0) ?></span><span class="stat-label d-block">Super Admins</span></span>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-user-gear"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($roleCounts['it_admin'] ?? 0) ?></span><span class="stat-label d-block">IT Admins</span></span>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-teal"><i class="fa-solid fa-building-user"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($roleCounts['branch_staff'] ?? 0) ?></span><span class="stat-label d-block">Branch Staff</span></span>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-amber"><i class="fa-solid fa-user-tie"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($roleCounts['dept_manager'] ?? 0) ?></span><span class="stat-label d-block">Dept Managers</span></span>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-green"><i class="fa-solid fa-circle-check"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($activeUsers) ?> / <?= number_format($inactiveUsers) ?></span><span class="stat-label d-block">Active / Inactive</span></span>
        </div>
    </div>
</div>

<!-- ============================ FILTER BAR ============================ -->
<form class="filter-bar" method="get" action="<?= url('users/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label">Search</label>
            <input type="search" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Username, name or email">
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Role</label>
            <select class="form-select" name="role">
                <option value="">All roles</option>
                <?php foreach (roles_list() as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($key, $roleFilter) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="form-label">Branch</label>
            <select class="form-select" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchFilter) ?>><?= e($b['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All</option>
                <option value="active"<?= selected('active', $statusFilter) ?>>Active</option>
                <option value="inactive"<?= selected('inactive', $statusFilter) ?>>Inactive</option>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <button type="submit" class="btn btn-brand btn-sm"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <a class="btn btn-outline-brand btn-sm" href="<?= url('users/index.php') ?>">Reset</a>
        </div>
    </div>
</form>

<!-- ============================== TABLE ============================== -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Branch</th>
                    <th>Department</th>
                    <th>Phone</th>
                    <th>Last Login</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$users): ?>
                <tr class="no-filter"><td colspan="9">
                    <div class="empty-state"><i class="fa-solid fa-users-slash"></i><p class="mb-0">No users found.</p></div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($users as $row): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="avatar"><?= e(user_initials($row['full_name'])) ?></span>
                            <div>
                                <div><?= e($row['full_name']) ?><?= (int) $row['id'] === $currentId ? ' <span class="badge badge-soft badge-secondary">You</span>' : '' ?></div>
                                <div class="text-muted-sm">@<?= e($row['username']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="text-muted-sm"><?= e($row['email']) ?></td>
                    <td><span class="badge badge-soft badge-<?= e($roleColors[$row['role']] ?? 'secondary') ?>"><?= e(role_label($row['role'])) ?></span></td>
                    <td class="text-muted-sm"><?= e($row['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['department_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['phone'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= $row['last_login'] ? e(fdatetime($row['last_login'])) . '<br><span class="text-muted-sm">' . e(time_ago($row['last_login'])) . '</span>' : 'Never' ?></td>
                    <td><?= active_badge($row['status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn-icon" title="Edit" href="<?= url('users/edit.php?id=' . (int) $row['id']) ?>"><i class="fa-solid fa-pen"></i></a>
                            <button type="button" class="btn-icon" title="Reset password" data-bs-toggle="modal" data-bs-target="#resetModal<?= (int) $row['id'] ?>"><i class="fa-solid fa-key"></i></button>
                            <?php if ((int) $row['id'] !== $currentId): ?>
                            <form method="post" action="<?= url('users/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete"
                                        data-confirm="Delete user &quot;<?= e($row['full_name']) ?>&quot;? This cannot be undone."><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>

                        <!-- Reset password modal -->
                        <div class="modal fade" id="resetModal<?= (int) $row['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <form class="modal-content" method="post" action="<?= url('users/edit.php') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fa-solid fa-key me-2"></i>Reset password</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p class="text-muted-sm">Set a new password for <b><?= e($row['full_name']) ?></b> (@<?= e($row['username']) ?>).</p>
                                        <div class="mb-3">
                                            <label class="form-label">New Password <span class="req">*</span></label>
                                            <input type="password" class="form-control" name="new_password" minlength="8" required autocomplete="new-password">
                                            <div class="form-text">Minimum 8 characters.</div>
                                        </div>
                                        <div class="mb-0">
                                            <label class="form-label">Confirm Password <span class="req">*</span></label>
                                            <input type="password" class="form-control" name="new_password_confirm" minlength="8" required autocomplete="new-password">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-brand" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Reset Password</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted" style="font-size:12.5px"><?= pagination_summary($total, $perPage, $page, 'users') ?></div>
        <?= paginate($total, $perPage, $page) ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
