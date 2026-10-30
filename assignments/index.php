<?php
/**
 * Asset Assignment - assignment history with filters and quick actions.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

/* ---------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */
$search   = get_param('q');
$branchF  = (int) get_param('branch_id', 0);
$deptF    = (int) get_param('department_id', 0);
$statusF  = get_param('assignment_status');
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');
$page     = max(1, (int) get_param('page', 1));
$perPage  = ITEMS_PER_PAGE;

$where  = '1 = 1';
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= ' AND (i.asset_code LIKE :q_code
                     OR i.equipment_name LIKE :q_equip
                     OR emp.employee_name LIKE :q_emp
                     OR emp.emp_code LIKE :q_empcode)';
    $params['q_code']    = $like;
    $params['q_equip']   = $like;
    $params['q_emp']     = $like;
    $params['q_empcode'] = $like;
}
if ($branchF > 0) {
    $where .= ' AND a.branch_id = :f_branch';
    $params['f_branch'] = $branchF;
}
if ($deptF > 0) {
    $where .= ' AND a.department_id = :f_dept';
    $params['f_dept'] = $deptF;
}
if (isset(assignment_statuses()[$statusF])) {
    $where .= ' AND a.assignment_status = :f_status';
    $params['f_status'] = $statusF;
}
if (is_valid_date($dateFrom)) {
    $where .= ' AND a.assignment_date >= :f_from';
    $params['f_from'] = $dateFrom;
}
if (is_valid_date($dateTo)) {
    $where .= ' AND a.assignment_date <= :f_to';
    $params['f_to'] = $dateTo;
}

$bindParams = array_merge($params, $scopeParams);

/* ---------------------------------------------------------------------
 * KPI figures
 * ------------------------------------------------------------------ */
$currentlyAssigned = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments a
       JOIN inventory i ON i.id = a.asset_id
      WHERE a.assignment_status = 'assigned' $scopeSql", $scopeParams);
$returnedCount = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments a
       JOIN inventory i ON i.id = a.asset_id
      WHERE a.assignment_status = 'returned' $scopeSql", $scopeParams);
$transferredCount = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments a
       JOIN inventory i ON i.id = a.asset_id
      WHERE a.assignment_status = 'transferred' $scopeSql", $scopeParams);
$overdueCount = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments a
       JOIN inventory i ON i.id = a.asset_id
      WHERE a.assignment_status = 'assigned'
        AND a.expected_return_date IS NOT NULL
        AND a.expected_return_date < CURDATE() $scopeSql", $scopeParams);

/* ---------------------------------------------------------------------
 * Lookup lists for the filter bar
 * ------------------------------------------------------------------ */
$branches    = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');
$departments = db_all('SELECT id, department_name FROM departments WHERE status = "active" ORDER BY department_name');

/* ---------------------------------------------------------------------
 * Paginated list
 * ------------------------------------------------------------------ */
$countSql = "SELECT COUNT(*)
               FROM asset_assignments a
               JOIN inventory i ON i.id = a.asset_id
               JOIN employees emp ON emp.id = a.employee_id
              WHERE $where $scopeSql";
$stmt = db()->prepare($countSql);
$stmt->execute($bindParams);
$total = (int) $stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$listSql = "SELECT a.id, a.asset_id, a.employee_id, a.assignment_date, a.expected_return_date,
                   a.return_date, a.assignment_status,
                   i.asset_code, i.equipment_name,
                   emp.employee_name, emp.emp_code,
                   b.branch_name, d.department_name,
                   u.full_name AS assigned_by_name
              FROM asset_assignments a
              JOIN inventory i ON i.id = a.asset_id
              JOIN employees emp ON emp.id = a.employee_id
              LEFT JOIN branches b ON b.id = a.branch_id
              LEFT JOIN departments d ON d.id = a.department_id
              LEFT JOIN users u ON u.id = a.assigned_by
             WHERE $where $scopeSql
             ORDER BY a.assignment_date DESC, a.id DESC
             LIMIT :limit OFFSET :offset";
$stmt = db()->prepare($listSql);
foreach ($bindParams as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$assignments = $stmt->fetchAll();

$filterCount = count($params);
$hasFilters  = $filterCount > 0;

$statCards = [
    ['Currently assigned', $currentlyAssigned, 'fa-solid fa-user-check',   'bg-soft-blue',  '#1b6ca8'],
    ['Returned',           $returnedCount,     'fa-solid fa-rotate-left',  'bg-soft-gray',  '#191919'],
    ['Transferred',        $transferredCount,  'fa-solid fa-right-left',   'bg-soft-teal',  '#161d1e'],
    ['Overdue returns',    $overdueCount,      'fa-solid fa-triangle-exclamation', 'bg-soft-red', '#171010'],
];

$page_title    = 'Asset Assignment';
$page_subtitle = 'Assign equipment to employees and track returns';
$active_menu   = 'assignments';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Asset Assignment</li>
            </ol>
        </nav>
        <h2>Assignment history</h2>
        <p>Every equipment issue, return and transfer recorded against employees.</p>
    </div>
    <div class="page-actions">
        <?php if (can('assignments')): ?>
        <a class="btn btn-brand" href="<?= url('assignments/create.php') ?>">
            <i class="fa-solid fa-plus me-2"></i>Assign new asset
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($statCards as $card): list($label, $value, $icon, $bg) = $card; ?>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon <?= e($bg) ?>"><i class="<?= e($icon) ?>"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format((float) $value) ?></span>
                <span class="stat-label d-block"><?= e($label) ?></span>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<form class="filter-bar" method="get" action="<?= url('assignments/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>"
                   placeholder="Asset code, equipment or employee">
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Branch</label>
            <select class="form-select" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $branch): ?>
                <option value="<?= (int) $branch['id'] ?>"<?= selected($branch['id'], $branchF) ?>><?= e($branch['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Department</label>
            <select class="form-select" name="department_id">
                <option value="">All departments</option>
                <?php foreach ($departments as $department): ?>
                <option value="<?= (int) $department['id'] ?>"<?= selected($department['id'], $deptF) ?>><?= e($department['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Status</label>
            <select class="form-select" name="assignment_status">
                <option value="">All statuses</option>
                <?php foreach (assignment_statuses() as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($key, $statusF) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">From date</label>
            <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-lg-1 col-md-6">
            <label class="form-label">To date</label>
            <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-lg-2 col-md-6 d-flex gap-2">
            <button type="submit" class="btn btn-brand w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <?php if ($hasFilters): ?>
            <a class="btn btn-outline-brand" href="<?= url('assignments/index.php') ?>" title="Reset filters"><i class="fa-solid fa-xmark"></i></a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-user-check"></i></div>
        <div>
            <h3 class="mb-0">Assignments</h3>
            <p class="sub mb-0"><?= pagination_summary($total, $perPage, $page, 'assignments') ?></p>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Asset</th>
                    <th>Employee</th>
                    <th>Branch</th>
                    <th>Department</th>
                    <th>Assignment date</th>
                    <th>Expected return</th>
                    <th>Return date</th>
                    <th>Assigned by</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$assignments): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No assignments found.</td></tr>
            <?php endif; ?>
            <?php foreach ($assignments as $row): ?>
                <tr>
                    <td>
                        <a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $row['asset_id']) ?>"><?= e($row['asset_code']) ?></a>
                        <div class="text-muted-sm"><?= e($row['equipment_name']) ?></div>
                    </td>
                    <td>
                        <a href="<?= url('employees/view.php?id=' . (int) $row['employee_id']) ?>"><?= e($row['employee_name']) ?></a>
                        <div class="text-muted-sm"><?= e($row['emp_code']) ?></div>
                    </td>
                    <td class="text-muted-sm"><?= e($row['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['department_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e(fdate($row['assignment_date'])) ?></td>
                    <td class="text-muted-sm">
                        <?= e(fdate($row['expected_return_date'])) ?>
                        <?php if ($row['assignment_status'] === 'assigned' && $row['expected_return_date'] && $row['expected_return_date'] < date('Y-m-d')): ?>
                            <i class="fa-solid fa-triangle-exclamation text-danger ms-1" title="Overdue return"></i>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted-sm"><?= e(fdate($row['return_date'])) ?></td>
                    <td class="text-muted-sm"><?= e($row['assigned_by_name'] ?: '-') ?></td>
                    <td><?= assignment_badge($row['assignment_status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <?php if ($row['assignment_status'] === 'assigned' && can('assignments')): ?>
                            <a class="btn btn-sm btn-accent" href="<?= url('assignments/return.php?assignment_id=' . (int) $row['id']) ?>">
                                <i class="fa-solid fa-rotate-left me-1"></i>Return
                            </a>
                            <?php endif; ?>
                            <a class="btn-icon" href="<?= url('inventory/view.php?id=' . (int) $row['asset_id']) ?>" title="View asset">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <?php if (can('assignments')): ?>
                            <form method="post" action="<?= url('assignments/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="assignment_id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete record"
                                        data-confirm="Delete this assignment history record? The asset itself is not affected.">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted-sm"><?= pagination_summary($total, $perPage, $page, 'assignments') ?></span>
        <?= paginate($total, $perPage, $page) ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
