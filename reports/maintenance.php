<?php
/**
 * Reports - maintenance report.
 * One row per maintenance record with record count and total cost.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$status   = get_param('status');
$type     = get_param('type');
$branchId = (int) get_param('branch_id', 0);
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');

$where  = ' WHERE 1 = 1 ' . $scopeSql;
$params = $scopeParams;
if ($status !== '' && isset(maintenance_statuses()[$status])) { $where .= ' AND m.status = :status'; $params['status'] = $status; }
if ($type !== '' && isset(maintenance_types()[$type]))       { $where .= ' AND m.maintenance_type = :type'; $params['type'] = $type; }
if ($branchId > 0) { $where .= ' AND i.branch_id = :branch'; $params['branch'] = $branchId; }
if (is_valid_date($dateFrom)) { $where .= ' AND m.reported_date >= :date_from'; $params['date_from'] = $dateFrom; }
if (is_valid_date($dateTo))   { $where .= ' AND m.reported_date <= :date_to';   $params['date_to'] = $dateTo; }

$records = db_all(
    "SELECT m.id, i.asset_code, i.equipment_name, m.issue_title, m.maintenance_type,
            m.technician_name, m.vendor, m.reported_date, m.repair_date, m.cost, m.status
       FROM maintenance_records m
       JOIN inventory i ON i.id = m.asset_id
      $where
      ORDER BY m.reported_date DESC, m.id DESC", $params
);

$total      = count($records);
$totalCost  = 0.0;
$byStatus   = [];
foreach ($records as $row) {
    $totalCost += (float) $row['cost'];
    $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
}

$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$exportBase = $_GET;
unset($exportBase['page']);
$csvUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'maintenance', 'format' => 'csv'])));
$pdfUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'maintenance', 'format' => 'pdf'])));

$page_title    = 'Maintenance Report';
$page_subtitle = 'Fault records, technicians and maintenance cost';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('reports/index.php') ?>">Reports</a></li>
                <li class="breadcrumb-item active">Maintenance</li>
            </ol>
        </nav>
        <h2>Maintenance report</h2>
        <p><?= number_format($total) ?> record(s) &middot; total cost <?= e(money($totalCost)) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-navy"><i class="fa-solid fa-screwdriver-wrench"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($total) ?></span><span class="stat-label d-block">Total records</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sack-dollar"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= e(money($totalCost, false)) ?></span><span class="stat-label d-block">Total cost (PKR)</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-amber"><i class="fa-solid fa-clock-rotate-left"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format(($byStatus['reported'] ?? 0) + ($byStatus['in_progress'] ?? 0)) ?></span><span class="stat-label d-block">Open</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-green"><i class="fa-solid fa-circle-check"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($byStatus['completed'] ?? 0) ?></span><span class="stat-label d-block">Completed</span></span></div>
    </div>
</div>

<form class="filter-bar no-print" method="get" action="<?= url('reports/maintenance.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach (maintenance_statuses() as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($key, $status) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="type">Type</label>
            <select class="form-select" id="type" name="type">
                <option value="">All types</option>
                <?php foreach (maintenance_types() as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($key, $type) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="branch_id">Branch</label>
            <select class="form-select" id="branch_id" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchId) ?>><?= e($b['branch_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="date_from">Reported from</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="date_to">To</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-lg-2 col-md-6 d-flex gap-2">
            <button class="btn btn-brand w-100" type="submit"><i class="fa-solid fa-filter"></i></button>
            <a class="btn btn-outline-brand" href="<?= url('reports/maintenance.php') ?>"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </div>
</form>

<div class="print-toolbar no-print">
    <span class="text-muted-sm">Maintenance report &middot; <?= number_format($total) ?> row(s)</span>
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
                    <th>Record #</th><th>Asset</th><th>Issue</th><th>Type</th><th>Technician</th><th>Vendor</th>
                    <th>Reported</th><th>Repair Date</th><th class="text-end">Cost</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr class="no-filter"><td colspan="10"><div class="empty-state"><i class="fa-solid fa-screwdriver-wrench"></i><h4>No records found</h4><p>Adjust the filters and try again.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($records as $row): ?>
                <tr>
                    <td><a class="fw-code" href="<?= url('maintenance/view.php?id=' . (int) $row['id']) ?>">#<?= (int) $row['id'] ?></a></td>
                    <td><span class="fw-code"><?= e($row['asset_code']) ?></span><br><span class="text-muted-sm"><?= e($row['equipment_name']) ?></span></td>
                    <td><?= e($row['issue_title']) ?></td>
                    <td class="text-muted-sm"><?= e(maintenance_types()[$row['maintenance_type']] ?? $row['maintenance_type']) ?></td>
                    <td class="text-muted-sm"><?= e($row['technician_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['vendor'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e(fdate($row['reported_date'])) ?></td>
                    <td class="text-muted-sm"><?= e(fdate($row['repair_date'])) ?></td>
                    <td class="text-end"><?= e(money($row['cost'])) ?></td>
                    <td><?= maintenance_badge($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($records): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="8">Totals (<?= number_format($total) ?> record(s))</td>
                    <td class="text-end"><?= e(money($totalCost)) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
