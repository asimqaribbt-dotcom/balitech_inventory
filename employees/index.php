<?php
/**
 * Employees - list, filter and export employees.
 * Data scope: Branch IT Staff see their own branch, Department Managers
 * their own department (applied through rbac_scope()).
 */
require_once __DIR__ . '/../init.php';
require_login();

$user = current_user();

/* ---------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */
$search       = get_param('q');
$branchFilter = (int) get_param('branch_id');
$deptFilter   = (int) get_param('department_id');
$statusFilter = get_param('status');
if (!in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

// Role scope (named placeholders -> keep every other filter named too)
$scope  = rbac_scope('e');
$where  = ' WHERE 1 = 1' . $scope['sql'];
$params = $scope['params'];

if ($search !== '') {
    $where .= ' AND (e.employee_name LIKE :s1 OR e.emp_code LIKE :s2 OR e.email LIKE :s3 OR e.designation LIKE :s4)';
    $like = '%' . $search . '%';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
    $params['s4'] = $like;
}
if ($branchFilter > 0) {
    $where .= ' AND e.branch_id = :f_branch';
    $params['f_branch'] = $branchFilter;
}
if ($deptFilter > 0) {
    $where .= ' AND e.department_id = :f_dept';
    $params['f_dept'] = $deptFilter;
}
if ($statusFilter !== '') {
    $where .= ' AND e.status = :f_status';
    $params['f_status'] = $statusFilter;
}

/* ---------------------------------------------------------------------
 * CSV export
 * ------------------------------------------------------------------ */
if (get_param('export') === 'csv') {
    $rows = db_all(
        "SELECT e.emp_code, e.employee_name, b.branch_name, d.department_name, e.designation,
                e.contact_number, e.email, e.joining_date, e.status
           FROM employees e
           LEFT JOIN branches b ON b.id = e.branch_id
           LEFT JOIN departments d ON d.id = e.department_id
           $where ORDER BY e.employee_name", $params);
    $data = [];
    foreach ($rows as $r) {
        $data[] = [$r['emp_code'], $r['employee_name'], $r['branch_name'], $r['department_name'],
                   $r['designation'], $r['contact_number'], $r['email'], $r['joining_date'], $r['status']];
    }
    log_activity('Employee', 'export', null, 'Exported employee list (CSV)');
    csv_download('employees-' . date('Ymd-His') . '.csv',
        ['Code', 'Employee', 'Branch', 'Department', 'Designation', 'Contact', 'Email', 'Joining Date', 'Status'], $data);
}

/* ---------------------------------------------------------------------
 * Pagination + list query
 * ------------------------------------------------------------------ */
$perPage = ITEMS_PER_PAGE;
$total   = (int) db_value("SELECT COUNT(*) FROM employees e $where", $params);
$page    = max(1, (int) get_param('page', 1));
$page    = min($page, max(1, (int) ceil($total / $perPage)));
$offset  = ($page - 1) * $perPage;

$employees = db_all(
    "SELECT e.*, b.branch_name, d.department_name,
            COUNT(DISTINCT aa.id) AS assigned_count
       FROM employees e
       LEFT JOIN branches b ON b.id = e.branch_id
       LEFT JOIN departments d ON d.id = e.department_id
       LEFT JOIN asset_assignments aa ON aa.employee_id = e.id AND aa.assignment_status = 'assigned'
       $where
      GROUP BY e.id
      ORDER BY e.employee_name ASC
      LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    $params
);

/* ---------------------------------------------------------------------
 * Summary figures
 * ------------------------------------------------------------------ */
$activeEmployees = (int) db_value("SELECT COUNT(*) FROM employees e $where AND e.status = 'active'", $params);
$withAssigned = (int) db_value(
    "SELECT COUNT(DISTINCT e.id)
       FROM employees e
       JOIN asset_assignments aa ON aa.employee_id = e.id AND aa.assignment_status = 'assigned'
       $where", $params);
$totalAssigned = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments aa
       JOIN employees e ON e.id = aa.employee_id
      WHERE aa.assignment_status = 'assigned'" . $scope['sql'], $scope['params']);

$branches    = db_all('SELECT id, branch_name FROM branches ORDER BY branch_name');
$departments = db_all('SELECT id, department_name FROM departments ORDER BY department_name');

$exportQuery = $_GET;
unset($exportQuery['page']);
$exportQuery['export'] = 'csv';
$exportUrl = url('employees/index.php?' . http_build_query($exportQuery));

$page_title    = 'Employees';
$page_subtitle = 'Staff register with asset assignments';
$active_menu   = 'employees';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Employees</li>
            </ol>
        </nav>
        <h2>Employees</h2>
        <p><?= e(APP_COMPANY) ?> &middot; <?= (int) $total ?> employee(s) listed</p>
    </div>
    <div class="page-actions">
        <?php if (can('employees')): ?>
        <a class="btn btn-brand" href="<?= url('employees/create.php') ?>"><i class="fa-solid fa-plus me-2"></i>Add Employee</a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-purple"><i class="fa-solid fa-users"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($total) ?></span>
                <span class="stat-label d-block">Total Employees</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-green"><i class="fa-solid fa-circle-check"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($activeEmployees) ?></span>
                <span class="stat-label d-block">Active</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-user-check"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($withAssigned) ?></span>
                <span class="stat-label d-block">With Assigned Assets</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-navy"><i class="fa-solid fa-laptop"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($totalAssigned) ?></span>
                <span class="stat-label d-block">Total Assigned Assets</span>
            </span>
        </div>
    </div>
</div>

<!-- ============================ FILTER BAR ============================ -->
<form class="filter-bar" method="get" action="<?= url('employees/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label">Search</label>
            <input type="search" class="form-control" name="q" value="<?= e($search) ?>"
                   placeholder="Name, code, email or designation">
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Branch</label>
            <select class="form-select" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchFilter) ?>><?= e($b['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="form-label">Department</label>
            <select class="form-select" name="department_id">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>"<?= selected($d['id'], $deptFilter) ?>><?= e($d['department_name']) ?></option>
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
            <a class="btn btn-outline-brand btn-sm" href="<?= url('employees/index.php') ?>">Reset</a>
        </div>
    </div>
</form>

<!-- ============================== TABLE ============================== -->
<div class="card">
    <div class="table-toolbar">
        <div class="left">
            <div class="input-group" style="max-width:280px">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" class="form-control form-control-sm" placeholder="Filter this table..." data-table-filter="#employeesTable">
            </div>
        </div>
        <div class="right">
            <a class="btn btn-outline-brand btn-sm" href="<?= e($exportUrl) ?>"><i class="fa-solid fa-file-csv me-1"></i>Export</a>
            <button type="button" class="btn btn-outline-brand btn-sm" data-print><i class="fa-solid fa-print me-1"></i>Print</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table" id="employeesTable">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Branch</th>
                    <th>Department</th>
                    <th>Designation</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th class="text-center">Assets</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$employees): ?>
                <tr class="no-filter"><td colspan="9">
                    <div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No employees found.</p></div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($employees as $emp): ?>
                <tr>
                    <td>
                        <a class="fw-code" href="<?= url('employees/view.php?id=' . (int) $emp['id']) ?>"><?= e($emp['emp_code']) ?></a>
                        <div><?= e($emp['employee_name']) ?></div>
                    </td>
                    <td class="text-muted-sm"><?= e($emp['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($emp['department_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($emp['designation'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($emp['contact_number'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($emp['email'] ?: '-') ?></td>
                    <td class="text-center"><?= (int) $emp['assigned_count'] ?></td>
                    <td><?= active_badge($emp['status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn-icon" title="View" href="<?= url('employees/view.php?id=' . (int) $emp['id']) ?>"><i class="fa-solid fa-eye"></i></a>
                            <?php if (can('employees')): ?>
                            <a class="btn-icon" title="Edit" href="<?= url('employees/edit.php?id=' . (int) $emp['id']) ?>"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" action="<?= url('employees/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete"
                                        data-confirm="Delete employee &quot;<?= e($emp['employee_name']) ?>&quot;?"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted" style="font-size:12.5px"><?= pagination_summary($total, $perPage, $page, 'employees') ?></div>
        <?= paginate($total, $perPage, $page) ?>
    </div>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
