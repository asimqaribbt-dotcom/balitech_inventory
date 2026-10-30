<?php
/**
 * Reports - equipment transfer report.
 * One row per transfer with total / completed / pending summary.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$branchId = (int) get_param('branch_id', 0);
$status   = get_param('status');
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');

$where  = ' WHERE 1 = 1 ' . $scopeSql;
$params = $scopeParams;
if ($branchId > 0) { $where .= ' AND (t.from_branch_id = :branch OR t.to_branch_id = :branch2)'; $params['branch'] = $branchId; $params['branch2'] = $branchId; }
if ($status !== '' && in_array($status, ['pending', 'completed', 'cancelled'], true)) { $where .= ' AND t.status = :status'; $params['status'] = $status; }
if (is_valid_date($dateFrom)) { $where .= ' AND t.transfer_date >= :date_from'; $params['date_from'] = $dateFrom; }
if (is_valid_date($dateTo))   { $where .= ' AND t.transfer_date <= :date_to';   $params['date_to'] = $dateTo; }

$records = db_all(
    "SELECT t.id, t.transfer_date, i.asset_code, i.equipment_name,
            fb.branch_name AS from_branch, tb.branch_name AS to_branch,
            fd.department_name AS from_dept, td.department_name AS to_dept,
            t.reason, u.full_name AS transferred_by_name, t.status
       FROM asset_transfers t
       JOIN inventory i ON i.id = t.asset_id
       LEFT JOIN branches fb ON fb.id = t.from_branch_id
       LEFT JOIN branches tb ON tb.id = t.to_branch_id
       LEFT JOIN departments fd ON fd.id = t.from_department_id
       LEFT JOIN departments td ON td.id = t.to_department_id
       LEFT JOIN users u ON u.id = t.transferred_by
      $where
      ORDER BY t.transfer_date DESC, t.id DESC", $params
);

$total     = count($records);
$completed = 0;
$pending   = 0;
$cancelled = 0;
foreach ($records as $row) {
    if ($row['status'] === 'completed')      { $completed++; }
    elseif ($row['status'] === 'pending')    { $pending++; }
    elseif ($row['status'] === 'cancelled')  { $cancelled++; }
}

$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$exportBase = $_GET;
unset($exportBase['page']);
$csvUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'transfer', 'format' => 'csv'])));
$pdfUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'transfer', 'format' => 'pdf'])));

$page_title    = 'Equipment Transfers';
$page_subtitle = 'Asset movement between branches and departments';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('reports/index.php') ?>">Reports</a></li>
                <li class="breadcrumb-item active">Equipment Transfers</li>
            </ol>
        </nav>
        <h2>Equipment transfer report</h2>
        <p><?= number_format($total) ?> transfer(s) &middot; <?= number_format($completed) ?> completed &middot; <?= number_format($pending) ?> pending</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-navy"><i class="fa-solid fa-right-left"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($total) ?></span><span class="stat-label d-block">Total transfers</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-green"><i class="fa-solid fa-circle-check"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($completed) ?></span><span class="stat-label d-block">Completed</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-amber"><i class="fa-solid fa-hourglass-half"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($pending) ?></span><span class="stat-label d-block">Pending</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-red"><i class="fa-solid fa-ban"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($cancelled) ?></span><span class="stat-label d-block">Cancelled</span></span></div>
    </div>
</div>

<form class="filter-bar no-print" method="get" action="<?= url('reports/transfer.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label" for="branch_id">Branch (from or to)</label>
            <select class="form-select" id="branch_id" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchId) ?>><?= e($b['branch_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                <option value="pending"<?= selected('pending', $status) ?>>Pending</option>
                <option value="completed"<?= selected('completed', $status) ?>>Completed</option>
                <option value="cancelled"<?= selected('cancelled', $status) ?>>Cancelled</option>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="date_from">From</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="date_to">To</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-lg-3 col-md-6 d-flex gap-2">
            <button class="btn btn-brand" type="submit"><i class="fa-solid fa-filter me-2"></i>Apply</button>
            <a class="btn btn-outline-brand" href="<?= url('reports/transfer.php') ?>"><i class="fa-solid fa-rotate-left me-2"></i>Reset</a>
        </div>
    </div>
</form>

<div class="print-toolbar no-print">
    <span class="text-muted-sm">Equipment transfers &middot; <?= number_format($total) ?> row(s)</span>
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
                    <th>Transfer Date</th><th>Asset</th><th>From Branch</th><th>To Branch</th>
                    <th>From Department</th><th>To Department</th><th>Reason</th><th>Transferred By</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr class="no-filter"><td colspan="9"><div class="empty-state"><i class="fa-solid fa-right-left"></i><h4>No records found</h4><p>Adjust the filters and try again.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($records as $row): ?>
                <tr>
                    <td class="text-muted-sm"><?= e(fdate($row['transfer_date'])) ?></td>
                    <td><span class="fw-code"><?= e($row['asset_code']) ?></span><br><span class="text-muted-sm"><?= e($row['equipment_name']) ?></span></td>
                    <td class="text-muted-sm"><?= e($row['from_branch'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['to_branch'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['from_dept'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['to_dept'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['reason'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['transferred_by_name'] ?: '-') ?></td>
                    <td><span class="badge badge-soft badge-<?= $row['status'] === 'completed' ? 'success' : ($row['status'] === 'pending' ? 'warning' : 'secondary') ?>"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($records): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="7">Totals (<?= number_format($total) ?> transfer(s))</td>
                    <td colspan="2"><?= number_format($completed) ?> completed &middot; <?= number_format($pending) ?> pending &middot; <?= number_format($cancelled) ?> cancelled</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
