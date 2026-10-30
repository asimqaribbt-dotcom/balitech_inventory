<?php
/**
 * Departments - list, filter and export all departments.
 * Readable by every signed in user; write actions need the "departments" ability.
 */
require_once __DIR__ . '/../init.php';
require_login();

$user = current_user();

/* ---------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */
$search       = get_param('q');
$branchFilter = (int) get_param('branch_id');

$where  = ' WHERE 1 = 1';
$params = [];

if ($search !== '') {
    $where .= ' AND (d.department_name LIKE ? OR d.dept_code LIKE ? OR d.dept_head LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($branchFilter > 0) {
    $where .= ' AND d.branch_id = ?';
    $params[] = $branchFilter;
}

// Data scope: Branch IT Staff -> own branch, Department Manager -> own department
if (is_role('branch_staff') && !empty($user['branch_id'])) {
    $where .= ' AND d.branch_id = ?';
    $params[] = (int) $user['branch_id'];
} elseif (is_role('dept_manager') && !empty($user['department_id'])) {
    $where .= ' AND d.id = ?';
    $params[] = (int) $user['department_id'];
}

/* ---------------------------------------------------------------------
 * CSV export
 * ------------------------------------------------------------------ */
if (get_param('export') === 'csv') {
    $rows = db_all(
        "SELECT d.dept_code, d.department_name, b.branch_name, d.dept_head, d.status
           FROM departments d
           LEFT JOIN branches b ON b.id = d.branch_id
           $where ORDER BY d.department_name", $params);
    $data = [];
    foreach ($rows as $r) {
        $data[] = [$r['dept_code'], $r['department_name'], $r['branch_name'], $r['dept_head'], $r['status']];
    }
    log_activity('Department', 'export', null, 'Exported department list (CSV)');
    csv_download('departments-' . date('Ymd-His') . '.csv',
        ['Code', 'Department', 'Branch', 'Head', 'Status'], $data);
}

/* ---------------------------------------------------------------------
 * Pagination + list query
 * ------------------------------------------------------------------ */
$perPage = ITEMS_PER_PAGE;
$total   = (int) db_value("SELECT COUNT(*) FROM departments d $where", $params);
$page    = max(1, (int) get_param('page', 1));
$page    = min($page, max(1, (int) ceil($total / $perPage)));
$offset  = ($page - 1) * $perPage;

$departments = db_all(
    "SELECT d.*, b.branch_name,
            COUNT(DISTINCT e.id) AS emp_count,
            COUNT(DISTINCT i.id) AS asset_count
       FROM departments d
       LEFT JOIN branches  b ON b.id = d.branch_id
       LEFT JOIN employees e ON e.department_id = d.id
       LEFT JOIN inventory i ON i.department_id = d.id
       $where
      GROUP BY d.id
      ORDER BY d.department_name ASC
      LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    $params
);

/* ---------------------------------------------------------------------
 * Summary figures
 * ------------------------------------------------------------------ */
$activeDepartments = (int) db_value("SELECT COUNT(*) FROM departments d $where AND d.status = 'active'", $params);
$totalEmployees    = (int) db_value("SELECT COUNT(*) FROM employees e JOIN departments d ON d.id = e.department_id $where", $params);
$totalAssets       = (int) db_value("SELECT COUNT(*) FROM inventory i JOIN departments d ON d.id = i.department_id $where", $params);

$branches = db_all('SELECT id, branch_name FROM branches ORDER BY branch_name');

$exportQuery = $_GET;
unset($exportQuery['page']);
$exportQuery['export'] = 'csv';
$exportUrl = url('departments/index.php?' . http_build_query($exportQuery));

$page_title    = 'Departments';
$page_subtitle = 'Departments across all branches';
$active_menu   = 'departments';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Departments</li>
            </ol>
        </nav>
        <h2>Departments</h2>
        <p><?= e(APP_COMPANY) ?> &middot; <?= (int) $total ?> department(s) listed</p>
    </div>
    <div class="page-actions">
        <?php if (can('departments')): ?>
        <a class="btn btn-brand" href="<?= url('departments/create.php') ?>"><i class="fa-solid fa-plus me-2"></i>Add Department</a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sitemap"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($total) ?></span>
                <span class="stat-label d-block">Total Departments</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-green"><i class="fa-solid fa-circle-check"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($activeDepartments) ?></span>
                <span class="stat-label d-block">Active</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-purple"><i class="fa-solid fa-users"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($totalEmployees) ?></span>
                <span class="stat-label d-block">Total Employees</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($totalAssets) ?></span>
                <span class="stat-label d-block">Total Assets</span>
            </span>
        </div>
    </div>
</div>

<!-- ============================ FILTER BAR ============================ -->
<form class="filter-bar" method="get" action="<?= url('departments/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-4 col-md-6">
            <label class="form-label">Search</label>
            <input type="search" class="form-control" name="q" value="<?= e($search) ?>"
                   placeholder="Name, code or head">
        </div>
        <div class="col-lg-4 col-md-6">
            <label class="form-label">Branch</label>
            <select class="form-select" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchFilter) ?>><?= e($b['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-4 col-md-6">
            <button type="submit" class="btn btn-brand btn-sm"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <a class="btn btn-outline-brand btn-sm" href="<?= url('departments/index.php') ?>">Reset</a>
        </div>
    </div>
</form>

<!-- ============================== TABLE ============================== -->
<div class="card">
    <div class="table-toolbar">
        <div class="left">
            <div class="input-group" style="max-width:280px">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" class="form-control form-control-sm" placeholder="Filter this table..." data-table-filter="#departmentsTable">
            </div>
        </div>
        <div class="right">
            <a class="btn btn-outline-brand btn-sm" href="<?= e($exportUrl) ?>"><i class="fa-solid fa-file-csv me-1"></i>Export</a>
            <button type="button" class="btn btn-outline-brand btn-sm" data-print><i class="fa-solid fa-print me-1"></i>Print</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table" id="departmentsTable">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Branch</th>
                    <th>Head</th>
                    <th class="text-center">Employees</th>
                    <th class="text-center">Assets</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$departments): ?>
                <tr class="no-filter"><td colspan="7">
                    <div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No departments found.</p></div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($departments as $d): ?>
                <tr>
                    <td>
                        <a class="fw-code" href="<?= url('departments/view.php?id=' . (int) $d['id']) ?>"><?= e($d['dept_code']) ?></a>
                        <div><?= e($d['department_name']) ?></div>
                    </td>
                    <td class="text-muted-sm"><?= e($d['branch_name'] ?: 'All branches') ?></td>
                    <td class="text-muted-sm"><?= e($d['dept_head'] ?: '-') ?></td>
                    <td class="text-center"><?= (int) $d['emp_count'] ?></td>
                    <td class="text-center"><?= (int) $d['asset_count'] ?></td>
                    <td><?= active_badge($d['status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn-icon" title="View" href="<?= url('departments/view.php?id=' . (int) $d['id']) ?>"><i class="fa-solid fa-eye"></i></a>
                            <?php if (can('departments')): ?>
                            <a class="btn-icon" title="Edit" href="<?= url('departments/edit.php?id=' . (int) $d['id']) ?>"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" action="<?= url('departments/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete"
                                        data-confirm="Delete department &quot;<?= e($d['department_name']) ?>&quot;?"><i class="fa-solid fa-trash"></i></button>
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
        <div class="text-muted" style="font-size:12.5px"><?= pagination_summary($total, $perPage, $page, 'departments') ?></div>
        <?= paginate($total, $perPage, $page) ?>
    </div>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
