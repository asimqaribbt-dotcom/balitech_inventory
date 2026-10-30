<?php
/**
 * Reports - department-wise inventory report.
 * Supports ?department_id= to focus on a single department.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeParams = $scope['params'];

$deptId   = (int) get_param('department_id', 0);
$branchId = (int) get_param('branch_id', 0);
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');

$join   = ' LEFT JOIN inventory i ON i.department_id = d.id ' . $scope['sql'];
$params = $scopeParams;
if (is_valid_date($dateFrom)) { $join .= ' AND i.purchase_date >= :date_from'; $params['date_from'] = $dateFrom; }
if (is_valid_date($dateTo))   { $join .= ' AND i.purchase_date <= :date_to';   $params['date_to'] = $dateTo; }

$where = " WHERE d.status = 'active'";
if ($deptId > 0)   { $where .= ' AND d.id = :dept'; $params['dept'] = $deptId; }
if ($branchId > 0) { $where .= ' AND d.branch_id = :branch'; $params['branch'] = $branchId; }

$rows = db_all(
    "SELECT d.id, d.department_name, br.branch_name, d.dept_head,
            COUNT(i.id) AS total_assets,
            SUM(i.status = 'assigned') AS assigned,
            SUM(i.status = 'available') AS available,
            SUM(i.status = 'under_maintenance') AS under_maintenance,
            SUM(i.status = 'damaged') AS damaged,
            COALESCE(SUM(i.purchase_price), 0) AS total_value
       FROM departments d
       LEFT JOIN branches br ON br.id = d.branch_id
       $join
      $where
      GROUP BY d.id, d.department_name, br.branch_name, d.dept_head
      ORDER BY total_assets DESC, d.department_name", $params
);

$grand = 0;
$grandValue = 0.0;
foreach ($rows as $row) {
    $grand += (int) $row['total_assets'];
    $grandValue += (float) $row['total_value'];
}

$departments = db_all('SELECT id, department_name FROM departments WHERE status = "active" ORDER BY department_name');
$branches    = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$exportBase = $_GET;
unset($exportBase['page']);
$csvUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'department', 'format' => 'csv'])));
$pdfUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'department', 'format' => 'pdf'])));

$page_title    = 'Department Report';
$page_subtitle = 'Assets held by each department';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('reports/index.php') ?>">Reports</a></li>
                <li class="breadcrumb-item active">Department-wise</li>
            </ol>
        </nav>
        <h2>Department-wise inventory report</h2>
        <p><?= count($rows) ?> department(s) &middot; <?= number_format($grand) ?> asset(s) &middot; <?= e(money($grandValue)) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sitemap"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= count($rows) ?></span><span class="stat-label d-block">Departments</span></span></div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-navy"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($grand) ?></span><span class="stat-label d-block">Total assets</span></span></div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-blue"><i class="fa-solid fa-sack-dollar"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= e(money($grandValue, false)) ?></span><span class="stat-label d-block">Total value (PKR)</span></span></div>
    </div>
</div>

<form class="filter-bar no-print" method="get" action="<?= url('reports/department.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="department_id">Department</label>
            <select class="form-select" id="department_id" name="department_id">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($d['id'], $deptId) ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="branch_id">Branch</label>
            <select class="form-select" id="branch_id" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchId) ?>><?= e($b['branch_name']) ?></option><?php endforeach; ?>
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
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-brand" type="submit"><i class="fa-solid fa-filter me-2"></i>Apply</button>
            <a class="btn btn-outline-brand" href="<?= url('reports/department.php') ?>"><i class="fa-solid fa-rotate-left me-2"></i>Reset</a>
        </div>
    </div>
</form>

<div class="print-toolbar no-print">
    <span class="text-muted-sm">Department-wise report &middot; <?= count($rows) ?> row(s)</span>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-brand" href="<?= e($csvUrl) ?>"><i class="fa-solid fa-file-csv me-2"></i>Export CSV</a>
        <a class="btn btn-brand" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf me-2"></i>Export PDF / Print</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Department</th><th>Branch</th><th>Head</th><th class="text-end">Total</th><th class="text-end">Assigned</th>
                    <th class="text-end">Available</th><th class="text-end">Maintenance</th><th class="text-end">Damaged</th><th class="text-end">Total Value</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="no-filter"><td colspan="9"><div class="empty-state"><i class="fa-solid fa-sitemap"></i><h4>No records found</h4><p>Adjust the filters and try again.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><span class="fw-code"><?= e($row['department_name']) ?></span></td>
                    <td class="text-muted-sm"><?= e($row['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['dept_head'] ?: '-') ?></td>
                    <td class="text-end"><?= number_format((int) $row['total_assets']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['assigned']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['available']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['under_maintenance']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['damaged']) ?></td>
                    <td class="text-end"><?= e(money($row['total_value'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="3">Totals</td>
                    <td class="text-end"><?= number_format($grand) ?></td>
                    <td colspan="4"></td>
                    <td class="text-end"><?= e(money($grandValue)) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
