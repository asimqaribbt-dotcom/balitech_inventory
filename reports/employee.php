<?php
/**
 * Reports - employee-wise asset report.
 * Use ?detail=1 for one row per assigned asset instead of per employee.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeParams = $scope['params'];

$detail   = (int) get_param('detail', 0);
$branchId = (int) get_param('branch_id', 0);
$deptId   = (int) get_param('department_id', 0);
$q        = get_param('q');
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');

$branchJoin = ' LEFT JOIN inventory i ON i.assigned_employee_id = e.id ' . $scope['sql'];
$params     = $scopeParams;
if (is_valid_date($dateFrom)) { $branchJoin .= ' AND i.purchase_date >= :date_from'; $params['date_from'] = $dateFrom; }
if (is_valid_date($dateTo))   { $branchJoin .= ' AND i.purchase_date <= :date_to';   $params['date_to'] = $dateTo; }

$where = " WHERE e.status = 'active'";
if ($branchId > 0) { $where .= ' AND e.branch_id = :branch'; $params['branch'] = $branchId; }
if ($deptId > 0)   { $where .= ' AND e.department_id = :dept'; $params['dept'] = $deptId; }
if ($q !== '') {
    $where .= ' AND (e.employee_name LIKE :kw1 OR e.emp_code LIKE :kw2)';
    $like = '%' . $q . '%';
    $params['kw1'] = $like; $params['kw2'] = $like;
}

if ($detail) {
    $rows = db_all(
        "SELECT i.asset_code, i.equipment_name, e.employee_name, e.emp_code, br.branch_name,
                d.department_name, e.designation, i.purchase_date, i.purchase_price, i.status
           FROM employees e
           LEFT JOIN branches br ON br.id = e.branch_id
           LEFT JOIN departments d ON d.id = e.department_id
           INNER JOIN inventory i ON i.assigned_employee_id = e.id " . $scope['sql'] . "
          $where
          ORDER BY e.employee_name, i.asset_code", $params
    );
    $totalValue = 0.0;
    foreach ($rows as $row) { $totalValue += (float) $row['purchase_price']; }
} else {
    $rows = db_all(
        "SELECT e.employee_name, e.emp_code, br.branch_name, d.department_name, e.designation,
                COUNT(i.id) AS total_assets,
                GROUP_CONCAT(i.asset_code ORDER BY i.asset_code SEPARATOR ', ') AS asset_codes,
                COALESCE(SUM(i.purchase_price), 0) AS total_value
           FROM employees e
           LEFT JOIN branches br ON br.id = e.branch_id
           LEFT JOIN departments d ON d.id = e.department_id
           $branchJoin
          $where
          GROUP BY e.id, e.employee_name, e.emp_code, br.branch_name, d.department_name, e.designation
          ORDER BY total_assets DESC, e.employee_name", $params
    );
    $totalValue = 0.0;
    foreach ($rows as $row) { $totalValue += (float) $row['total_value']; }
}

$branches    = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');
$departments = db_all('SELECT id, department_name FROM departments WHERE status = "active" ORDER BY department_name');

$exportBase = $_GET;
unset($exportBase['page']);
$csvUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'employee', 'format' => 'csv'])));
$pdfUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'employee', 'format' => 'pdf'])));
$toggleUrl = url('reports/employee.php?' . http_build_query(array_merge($_GET, ['detail' => $detail ? 0 : 1])));

$page_title    = 'Employee Report';
$page_subtitle = $detail ? 'One row per assigned asset' : 'Assets assigned to each employee';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('reports/index.php') ?>">Reports</a></li>
                <li class="breadcrumb-item active">Employee-wise</li>
            </ol>
        </nav>
        <h2>Employee-wise asset report</h2>
        <p><?= count($rows) ?> row(s) &middot; total value <?= e(money($totalValue)) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= e($toggleUrl) ?>"><i class="fa-solid fa-list me-2"></i><?= $detail ? 'Group by employee' : 'Show per asset' ?></a>
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-purple"><i class="fa-solid fa-users"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format(count($rows)) ?></span><span class="stat-label d-block"><?= $detail ? 'Assigned assets' : 'Employees' ?></span></span></div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-blue"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($detail ? count($rows) : array_sum(array_map(static fn($r) => (int) $r['total_assets'], $rows))) ?></span><span class="stat-label d-block">Total assets</span></span></div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sack-dollar"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= e(money($totalValue, false)) ?></span><span class="stat-label d-block">Total value (PKR)</span></span></div>
    </div>
</div>

<form class="filter-bar no-print" method="get" action="<?= url('reports/employee.php') ?>">
    <input type="hidden" name="detail" value="<?= $detail ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Employee name or code">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="branch_id">Branch</label>
            <select class="form-select" id="branch_id" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchId) ?>><?= e($b['branch_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="department_id">Department</label>
            <select class="form-select" id="department_id" name="department_id">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($d['id'], $deptId) ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_from">From</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_to">To</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-1 d-flex gap-2">
            <button class="btn btn-brand w-100" type="submit"><i class="fa-solid fa-filter"></i></button>
        </div>
    </div>
</form>

<div class="print-toolbar no-print">
    <span class="text-muted-sm">Employee-wise report &middot; <?= count($rows) ?> row(s)</span>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-brand" href="<?= e($csvUrl) ?>"><i class="fa-solid fa-file-csv me-2"></i>Export CSV</a>
        <a class="btn btn-brand" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf me-2"></i>Export PDF / Print</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
            <?php if ($detail): ?>
                <tr><th>Asset ID</th><th>Equipment</th><th>Employee</th><th>Emp Code</th><th>Branch</th><th>Department</th><th>Designation</th><th>Purchase Date</th><th class="text-end">Price</th><th>Status</th></tr>
            <?php else: ?>
                <tr><th>Employee</th><th>Emp Code</th><th>Branch</th><th>Department</th><th>Designation</th><th class="text-end">Assets</th><th>Asset Codes</th><th class="text-end">Total Value</th></tr>
            <?php endif; ?>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="no-filter"><td colspan="<?= $detail ? 10 : 8 ?>"><div class="empty-state"><i class="fa-solid fa-users"></i><h4>No records found</h4><p>Adjust the filters and try again.</p></div></td></tr>
            <?php endif; ?>
            <?php if ($detail): ?>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><span class="fw-code"><?= e($row['asset_code']) ?></span></td>
                    <td><?= e($row['equipment_name']) ?></td>
                    <td><?= e($row['employee_name']) ?></td>
                    <td class="text-muted-sm"><?= e($row['emp_code']) ?></td>
                    <td class="text-muted-sm"><?= e($row['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['department_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['designation'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e(fdate($row['purchase_date'])) ?></td>
                    <td class="text-end"><?= e(money($row['purchase_price'])) ?></td>
                    <td><?= status_badge($row['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($rows as $row):
                    $codes = trim((string) $row['asset_codes']);
                    $list  = $codes === '' ? [] : explode(', ', $codes);
                    $shown = array_slice($list, 0, 5);
                    $label = $shown ? implode(', ', $shown) : '-';
                    if (count($list) > 5) { $label .= ' +' . (count($list) - 5) . ' more'; }
                ?>
                <tr>
                    <td><?= e($row['employee_name']) ?></td>
                    <td class="text-muted-sm"><?= e($row['emp_code']) ?></td>
                    <td class="text-muted-sm"><?= e($row['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['department_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['designation'] ?: '-') ?></td>
                    <td class="text-end"><?= number_format((int) $row['total_assets']) ?></td>
                    <td class="text-muted-sm"><?= e($label) ?></td>
                    <td class="text-end"><?= e(money($row['total_value'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
