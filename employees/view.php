<?php
/**
 * Employees - single employee profile with assigned equipment and history.
 */
require_once __DIR__ . '/../init.php';
require_login();

$user = current_user();
$id = (int) get_param('id');

$employee = db_row(
    'SELECT e.*, b.branch_name, d.department_name
       FROM employees e
       LEFT JOIN branches b ON b.id = e.branch_id
       LEFT JOIN departments d ON d.id = e.department_id
      WHERE e.id = ?',
    [$id]
);
if (!$employee) {
    set_flash('danger', 'The requested employee could not be found.');
    redirect('employees/index.php');
}

// Data scope guards
if (is_role('branch_staff') && (int) $employee['branch_id'] !== (int) ($user['branch_id'] ?? 0)) {
    deny_access('You may only view employees of your own branch.');
}
if (is_role('dept_manager') && (int) $employee['department_id'] !== (int) ($user['department_id'] ?? 0)) {
    deny_access('You may only view employees of your own department.');
}

$canWrite    = can('employees');
$canAssign   = can('assignments');
$canTransfer = can('transfers');

/* ---------------------------------------------------------------------
 * Statistics
 * ------------------------------------------------------------------ */
$assignedCount = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments WHERE employee_id = ? AND assignment_status = 'assigned'", [$id]);
$returnedCount = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments WHERE employee_id = ? AND assignment_status = 'returned'", [$id]);
$maintenanceCount = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments aa
       JOIN inventory i ON i.id = aa.asset_id
      WHERE aa.employee_id = ? AND aa.assignment_status = 'assigned' AND i.status = 'under_maintenance'", [$id]);

/* ---------------------------------------------------------------------
 * Tab data
 * ------------------------------------------------------------------ */
$tab = get_param('tab', 'assigned');
if (!in_array($tab, ['assigned', 'history'], true)) {
    $tab = 'assigned';
}

$assigned = db_all(
    "SELECT aa.id AS assignment_id, aa.assignment_date, aa.asset_id,
            i.asset_code, i.equipment_name, i.status,
            c.category_name, u.full_name AS assigned_by_name
       FROM asset_assignments aa
       JOIN inventory i ON i.id = aa.asset_id
       LEFT JOIN asset_categories c ON c.id = i.category_id
       LEFT JOIN users u ON u.id = aa.assigned_by
      WHERE aa.employee_id = ? AND aa.assignment_status = 'assigned'
      ORDER BY aa.assignment_date DESC, aa.id DESC",
    [$id]
);

$history = db_all(
    "SELECT aa.*, i.asset_code, i.equipment_name
       FROM asset_assignments aa
       JOIN inventory i ON i.id = aa.asset_id
      WHERE aa.employee_id = ?
      ORDER BY aa.assignment_date DESC, aa.id DESC",
    [$id]
);

$page_title    = 'Employee: ' . $employee['employee_name'];
$page_subtitle = $employee['emp_code'] . ($employee['designation'] ? ' · ' . $employee['designation'] : '');
$active_menu   = 'employees';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('employees/index.php') ?>">Employees</a></li>
                <li class="breadcrumb-item active"><?= e($employee['emp_code']) ?></li>
            </ol>
        </nav>
        <h2><?= e($employee['employee_name']) ?></h2>
        <p><?= e($employee['designation'] ?: 'Employee') ?> &middot; <?= e($employee['branch_name'] ?: 'No branch') ?> &middot; <?= e($employee['department_name'] ?: 'No department') ?></p>
    </div>
    <div class="page-actions">
        <?php if ($canWrite): ?>
        <a class="btn btn-brand" href="<?= url('employees/edit.php?id=' . $id) ?>"><i class="fa-solid fa-pen me-2"></i>Edit</a>
        <?php endif; ?>
        <a class="btn btn-outline-brand" href="<?= url('employees/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back</a>
    </div>
</div>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <div class="col-xl-4 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-user-check"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($assignedCount) ?></span>
                <span class="stat-label d-block">Assigned Assets</span>
            </span>
        </div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-gray"><i class="fa-solid fa-rotate-left"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($returnedCount) ?></span>
                <span class="stat-label d-block">Returned Assignments</span>
            </span>
        </div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-amber"><i class="fa-solid fa-screwdriver-wrench"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($maintenanceCount) ?></span>
                <span class="stat-label d-block">Assets Under Maintenance</span>
            </span>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Profile card -->
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <div class="avatar avatar-lg mx-auto mb-3"><?= e(user_initials($employee['employee_name'])) ?></div>
                <h3 class="mb-0"><?= e($employee['employee_name']) ?></h3>
                <p class="text-muted mb-1"><?= e($employee['emp_code']) ?></p>
                <?= active_badge($employee['status']) ?>
            </div>
            <div class="card-body pt-0">
                <ul class="info-list">
                    <li><span class="k">Branch</span><span class="v"><?= e($employee['branch_name'] ?: '-') ?></span></li>
                    <li><span class="k">Department</span><span class="v"><?= e($employee['department_name'] ?: '-') ?></span></li>
                    <li><span class="k">Designation</span><span class="v"><?= e($employee['designation'] ?: '-') ?></span></li>
                    <li><span class="k">Contact</span><span class="v"><?= e($employee['contact_number'] ?: '-') ?></span></li>
                    <li><span class="k">Email</span><span class="v"><?= e($employee['email'] ?: '-') ?></span></li>
                    <li><span class="k">Joining Date</span><span class="v"><?= e(fdate($employee['joining_date'])) ?></span></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Assignment tabs -->
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header pb-0">
                <ul class="nav nav-tabs w-100" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link<?= $tab === 'assigned' ? ' active' : '' ?>" href="<?= url('employees/view.php?id=' . $id . '&tab=assigned') ?>">
                            <i class="fa-solid fa-laptop me-1"></i>Assigned Equipment (<?= count($assigned) ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= $tab === 'history' ? ' active' : '' ?>" href="<?= url('employees/view.php?id=' . $id . '&tab=history') ?>">
                            <i class="fa-solid fa-clock-rotate-left me-1"></i>Assignment History (<?= count($history) ?>)
                        </a>
                    </li>
                </ul>
            </div>

            <?php if ($tab === 'assigned'): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Asset</th><th>Equipment</th><th>Category</th><th>Assigned</th><th>Assigned By</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$assigned): ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No equipment currently assigned.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($assigned as $a): ?>
                        <tr>
                            <td><a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $a['asset_id']) ?>"><?= e($a['asset_code']) ?></a></td>
                            <td>
                                <div><?= e($a['equipment_name']) ?></div>
                                <div class="text-muted-sm"><?= status_badge($a['status']) ?></div>
                            </td>
                            <td class="text-muted-sm"><?= e($a['category_name'] ?: '-') ?></td>
                            <td class="text-muted-sm"><?= e(fdate($a['assignment_date'])) ?></td>
                            <td class="text-muted-sm"><?= e($a['assigned_by_name'] ?: '-') ?></td>
                            <td>
                                <div class="table-actions">
                                    <?php if ($canAssign): ?>
                                    <a class="btn-icon" title="Return asset" href="<?= url('assignments/return.php?assignment_id=' . (int) $a['assignment_id']) ?>"><i class="fa-solid fa-rotate-left"></i></a>
                                    <?php endif; ?>
                                    <?php if ($canTransfer): ?>
                                    <a class="btn-icon" title="Transfer asset" href="<?= url('transfers/create.php?asset_id=' . (int) $a['asset_id']) ?>"><i class="fa-solid fa-right-left"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Asset</th><th>Equipment</th><th>Assigned Date</th><th>Return Date</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No assignment history.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td><a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $h['asset_id']) ?>"><?= e($h['asset_code']) ?></a></td>
                            <td><?= e($h['equipment_name']) ?></td>
                            <td class="text-muted-sm"><?= e(fdate($h['assignment_date'])) ?></td>
                            <td class="text-muted-sm"><?= e($h['return_date'] ? fdate($h['return_date']) : '-') ?></td>
                            <td><?= assignment_badge($h['assignment_status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
