<?php
/**
 * Departments - single department profile with inventory and staff.
 */
require_once __DIR__ . '/../init.php';
require_login();

$user = current_user();
$id = (int) get_param('id');

$department = db_row(
    'SELECT d.*, b.branch_name FROM departments d LEFT JOIN branches b ON b.id = d.branch_id WHERE d.id = ?',
    [$id]
);
if (!$department) {
    set_flash('danger', 'The requested department could not be found.');
    redirect('departments/index.php');
}

// Data scope guards
if (is_role('dept_manager') && (int) $department['id'] !== (int) ($user['department_id'] ?? 0)) {
    deny_access('You may only view your own department.');
}
if (is_role('branch_staff') && (int) $department['branch_id'] !== (int) ($user['branch_id'] ?? 0)) {
    deny_access('You may only view departments of your own branch.');
}

$canWrite = can('departments');

/* ---------------------------------------------------------------------
 * Statistics
 * ------------------------------------------------------------------ */
$empCount        = (int) db_value('SELECT COUNT(*) FROM employees WHERE department_id = ?', [$id]);
$assetTotal      = (int) db_value('SELECT COUNT(*) FROM inventory WHERE department_id = ?', [$id]);
$assetAssigned   = (int) db_value("SELECT COUNT(*) FROM inventory WHERE department_id = ? AND status = 'assigned'", [$id]);
$assetAvailable  = (int) db_value("SELECT COUNT(*) FROM inventory WHERE department_id = ? AND status = 'available'", [$id]);

/* ---------------------------------------------------------------------
 * Paginated inventory + employees
 * ------------------------------------------------------------------ */
$perPage = ITEMS_PER_PAGE;
$invTotal = (int) db_value('SELECT COUNT(*) FROM inventory WHERE department_id = ?', [$id]);
$page = max(1, (int) get_param('page', 1));
$page = min($page, max(1, (int) ceil($invTotal / $perPage)));
$offset = ($page - 1) * $perPage;

$inventory = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.status, c.category_name, e.employee_name
       FROM inventory i
       LEFT JOIN asset_categories c ON c.id = i.category_id
       LEFT JOIN employees e ON e.id = i.assigned_employee_id
      WHERE i.department_id = ?
      ORDER BY i.asset_code ASC
      LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    [$id]
);

$employees = db_all(
    'SELECT * FROM employees WHERE department_id = ? ORDER BY employee_name ASC',
    [$id]
);

$page_title    = 'Department: ' . $department['department_name'];
$page_subtitle = $department['dept_code'] . ($department['branch_name'] ? ' · ' . $department['branch_name'] : '');
$active_menu   = 'departments';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('departments/index.php') ?>">Departments</a></li>
                <li class="breadcrumb-item active"><?= e($department['dept_code']) ?></li>
            </ol>
        </nav>
        <h2><?= e($department['department_name']) ?></h2>
        <p><?= e($department['branch_name'] ?: 'All branches') ?> &middot; Head: <?= e($department['dept_head'] ?: 'Not assigned') ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('reports/department.php?department_id=' . $id) ?>"><i class="fa-solid fa-chart-column me-2"></i>Department Report</a>
        <?php if ($canWrite): ?>
        <a class="btn btn-brand" href="<?= url('departments/edit.php?id=' . $id) ?>"><i class="fa-solid fa-pen me-2"></i>Edit</a>
        <?php endif; ?>
        <a class="btn btn-outline-brand" href="<?= url('departments/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back</a>
    </div>
</div>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-purple"><i class="fa-solid fa-users"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($empCount) ?></span>
                <span class="stat-label d-block">Employees</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-navy"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($assetTotal) ?></span>
                <span class="stat-label d-block">Assets</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-user-check"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($assetAssigned) ?></span>
                <span class="stat-label d-block">Assigned</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-green"><i class="fa-solid fa-warehouse"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($assetAvailable) ?></span>
                <span class="stat-label d-block">Available</span>
            </span>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Department profile -->
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-gray" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-circle-info"></i></div>
                <div><h3 class="mb-0">Department profile</h3><p class="sub mb-0">Registered details</p></div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Code</span><span class="v"><?= e($department['dept_code']) ?></span></li>
                    <li><span class="k">Name</span><span class="v"><?= e($department['department_name']) ?></span></li>
                    <li><span class="k">Branch</span><span class="v"><?= e($department['branch_name'] ?: 'All branches') ?></span></li>
                    <li><span class="k">Head</span><span class="v"><?= e($department['dept_head'] ?: '-') ?></span></li>
                    <li><span class="k">Description</span><span class="v"><?= e($department['description'] ?: '-') ?></span></li>
                    <li><span class="k">Status</span><span class="v"><?= active_badge($department['status']) ?></span></li>
                    <li><span class="k">Created</span><span class="v"><?= e(fdate($department['created_at'])) ?></span></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Employees -->
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-users"></i></div>
                <div><h3 class="mb-0">Department employees</h3><p class="sub mb-0"><?= count($employees) ?> staff member(s)</p></div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Code</th><th>Employee</th><th>Designation</th><th>Contact</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$employees): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No employees in this department.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td><a class="fw-code" href="<?= url('employees/view.php?id=' . (int) $emp['id']) ?>"><?= e($emp['emp_code']) ?></a></td>
                            <td><?= e($emp['employee_name']) ?></td>
                            <td class="text-muted-sm"><?= e($emp['designation'] ?: '-') ?></td>
                            <td class="text-muted-sm"><?= e($emp['contact_number'] ?: '-') ?></td>
                            <td><?= active_badge($emp['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ======================= DEPARTMENT INVENTORY ======================= -->
<div class="card mt-3">
    <div class="card-header">
        <div class="stat-icon bg-soft-navy" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div><h3 class="mb-0">Department inventory</h3><p class="sub mb-0">Assets currently associated with this department</p></div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Asset Code</th><th>Equipment</th><th>Category</th><th>Assigned To</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php if (!$inventory): ?>
                <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No assets in this department.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($inventory as $a): ?>
                <tr>
                    <td><a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $a['id']) ?>"><?= e($a['asset_code']) ?></a></td>
                    <td><?= e($a['equipment_name']) ?></td>
                    <td class="text-muted-sm"><?= e($a['category_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($a['employee_name'] ?: '-') ?></td>
                    <td><?= status_badge($a['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted" style="font-size:12.5px"><?= pagination_summary($invTotal, $perPage, $page, 'assets') ?></div>
        <?= paginate($invTotal, $perPage, $page) ?>
    </div>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
