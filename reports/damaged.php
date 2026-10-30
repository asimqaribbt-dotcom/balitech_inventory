<?php
/**
 * Reports - damaged equipment report.
 * One row per damaged asset (optionally including retired) with book value.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$branchId       = (int) get_param('branch_id', 0);
$deptId         = (int) get_param('department_id', 0);
$categoryId     = (int) get_param('category_id', 0);
$includeRetired = (int) get_param('include_retired', 0);

$statuses = $includeRetired ? "i.status IN ('damaged','retired')" : "i.status = 'damaged'";

$where  = ' WHERE 1 = 1 ' . $scopeSql . ' AND ' . $statuses;
$params = $scopeParams;
if ($branchId > 0)   { $where .= ' AND i.branch_id = :branch'; $params['branch'] = $branchId; }
if ($deptId > 0)     { $where .= ' AND i.department_id = :dept'; $params['dept'] = $deptId; }
if ($categoryId > 0) { $where .= ' AND i.category_id = :cat'; $params['cat'] = $categoryId; }

$records = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, b.brand_name, i.serial_number, br.branch_name,
            d.department_name, i.asset_condition, i.status, i.purchase_date, i.purchase_price, i.remarks,
            (SELECT m.issue_title FROM maintenance_records m WHERE m.asset_id = i.id
              ORDER BY m.reported_date DESC, m.id DESC LIMIT 1) AS last_issue,
            (SELECT m.cost FROM maintenance_records m WHERE m.asset_id = i.id
              ORDER BY m.reported_date DESC, m.id DESC LIMIT 1) AS last_cost
       FROM inventory i
       LEFT JOIN brands b ON b.id = i.brand_id
       LEFT JOIN branches br ON br.id = i.branch_id
       LEFT JOIN departments d ON d.id = i.department_id
      $where
      ORDER BY i.asset_code", $params
);

$total      = count($records);
$totalValue = 0.0;
foreach ($records as $row) {
    $totalValue += (float) $row['purchase_price'];
}

$branches    = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');
$departments = db_all('SELECT id, department_name FROM departments WHERE status = "active" ORDER BY department_name');
$categories  = db_all('SELECT id, category_name FROM asset_categories WHERE parent_id IS NOT NULL AND status = "active" ORDER BY category_name');

$exportBase = $_GET;
unset($exportBase['page']);
$csvUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'damaged', 'format' => 'csv'])));
$pdfUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'damaged', 'format' => 'pdf'])));

$page_title    = 'Damaged Equipment';
$page_subtitle = 'Damaged assets with latest maintenance and book value';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('reports/index.php') ?>">Reports</a></li>
                <li class="breadcrumb-item active">Damaged Equipment</li>
            </ol>
        </nav>
        <h2>Damaged equipment report</h2>
        <p><?= number_format($total) ?> asset(s)<?= $includeRetired ? ' (incl. retired)' : '' ?> &middot; book value <?= e(money($totalValue)) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-red"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($total) ?></span><span class="stat-label d-block">Damaged<?= $includeRetired ? ' / retired' : '' ?> assets</span></span></div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sack-dollar"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= e(money($totalValue, false)) ?></span><span class="stat-label d-block">Book value (PKR)</span></span></div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-navy"><i class="fa-solid fa-calculator"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= e(money($total > 0 ? $totalValue / $total : 0, false)) ?></span><span class="stat-label d-block">Average value (PKR)</span></span></div>
    </div>
</div>

<form class="filter-bar no-print" method="get" action="<?= url('reports/damaged.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="branch_id">Branch</label>
            <select class="form-select" id="branch_id" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchId) ?>><?= e($b['branch_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="form-label" for="department_id">Department</label>
            <select class="form-select" id="department_id" name="department_id">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($d['id'], $deptId) ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="form-label" for="category_id">Category</label>
            <select class="form-select" id="category_id" name="category_id">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($c['id'], $categoryId) ?>><?= e($c['category_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" id="include_retired" name="include_retired" value="1"<?= $includeRetired ? ' checked' : '' ?>>
                <label class="form-check-label" for="include_retired">Include retired</label>
            </div>
        </div>
        <div class="col-lg-2 col-md-6 d-flex gap-2">
            <button class="btn btn-brand w-100" type="submit"><i class="fa-solid fa-filter"></i></button>
            <a class="btn btn-outline-brand" href="<?= url('reports/damaged.php') ?>"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </div>
</form>

<div class="print-toolbar no-print">
    <span class="text-muted-sm">Damaged equipment &middot; <?= number_format($total) ?> row(s)</span>
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
                    <th>Asset ID</th><th>Equipment</th><th>Brand</th><th>Serial</th><th>Branch</th><th>Department</th>
                    <th>Condition</th><th>Status</th><th>Purchase Date</th><th class="text-end">Price</th>
                    <th>Remarks</th><th>Latest Issue</th><th class="text-end">Latest Cost</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr class="no-filter"><td colspan="13"><div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><h4>No damaged assets found</h4><p>Adjust the filters and try again.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($records as $row): ?>
                <tr>
                    <td><a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $row['id']) ?>"><?= e($row['asset_code']) ?></a></td>
                    <td><?= e($row['equipment_name']) ?></td>
                    <td class="text-muted-sm"><?= e($row['brand_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['serial_number'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['department_name'] ?: '-') ?></td>
                    <td><?= condition_badge($row['asset_condition']) ?></td>
                    <td><?= status_badge($row['status']) ?></td>
                    <td class="text-muted-sm"><?= e(fdate($row['purchase_date'])) ?></td>
                    <td class="text-end"><?= e(money($row['purchase_price'])) ?></td>
                    <td class="text-muted-sm"><?= e($row['remarks'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['last_issue'] ?: '-') ?></td>
                    <td class="text-end"><?= $row['last_cost'] !== null ? e(money($row['last_cost'])) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($records): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="9">Totals (<?= number_format($total) ?> asset(s))</td>
                    <td class="text-end"><?= e(money($totalValue)) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
