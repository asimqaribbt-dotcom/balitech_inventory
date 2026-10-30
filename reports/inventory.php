<?php
/**
 * Reports - complete inventory report.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$q          = get_param('q');
$status     = get_param('status');
$branchId   = (int) get_param('branch_id', 0);
$deptId     = (int) get_param('department_id', 0);
$categoryId = (int) get_param('category_id', 0);
$brandId    = (int) get_param('brand_id', 0);
$dateFrom   = get_param('date_from');
$dateTo     = get_param('date_to');

$where  = ' WHERE 1 = 1 ' . $scopeSql;
$params = $scopeParams;
if ($branchId > 0)   { $where .= ' AND i.branch_id = :branch'; $params['branch'] = $branchId; }
if ($deptId > 0)     { $where .= ' AND i.department_id = :dept'; $params['dept'] = $deptId; }
if ($categoryId > 0) { $where .= ' AND i.category_id = :cat'; $params['cat'] = $categoryId; }
if ($brandId > 0)    { $where .= ' AND i.brand_id = :brand'; $params['brand'] = $brandId; }
if ($status !== '' && isset(asset_statuses()[$status])) { $where .= ' AND i.status = :status'; $params['status'] = $status; }
if (is_valid_date($dateFrom)) { $where .= ' AND i.purchase_date >= :date_from'; $params['date_from'] = $dateFrom; }
if (is_valid_date($dateTo))   { $where .= ' AND i.purchase_date <= :date_to';   $params['date_to'] = $dateTo; }
if ($q !== '') {
    $where .= ' AND (i.asset_code LIKE :kw1 OR i.equipment_name LIKE :kw2 OR i.serial_number LIKE :kw3)';
    $like = '%' . $q . '%';
    $params['kw1'] = $like; $params['kw2'] = $like; $params['kw3'] = $like;
}

$records = db_all(
    "SELECT i.asset_code, c.category_name, p.category_name AS group_name, i.equipment_name,
            b.brand_name, i.model_number, i.serial_number, br.branch_name, d.department_name,
            e.employee_name, i.purchase_date, i.purchase_price, i.warranty_expiry,
            i.asset_condition, i.status
       FROM inventory i
       LEFT JOIN asset_categories c ON c.id = i.category_id
       LEFT JOIN asset_categories p ON p.id = c.parent_id
       LEFT JOIN brands b ON b.id = i.brand_id
       LEFT JOIN branches br ON br.id = i.branch_id
       LEFT JOIN departments d ON d.id = i.department_id
       LEFT JOIN employees e ON e.id = i.assigned_employee_id
      $where
      ORDER BY i.asset_code", $params
);

$total = count($records);
$totalValue = 0.0;
$byStatus = [];
foreach ($records as $row) {
    $totalValue += (float) $row['purchase_price'];
    $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
}

$branches   = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');
$departments = db_all('SELECT id, department_name FROM departments WHERE status = "active" ORDER BY department_name');
$categories = db_all('SELECT id, category_name FROM asset_categories WHERE parent_id IS NOT NULL AND status = "active" ORDER BY category_name');
$brands     = db_all('SELECT id, brand_name FROM brands WHERE status = "active" ORDER BY brand_name');

$exportBase = $_GET;
unset($exportBase['page']);
$csvUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'inventory', 'format' => 'csv'])));
$pdfUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'inventory', 'format' => 'pdf'])));

$page_title    = 'Inventory Report';
$page_subtitle = 'Complete register of all assets';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('reports/index.php') ?>">Reports</a></li>
                <li class="breadcrumb-item active">Complete Inventory</li>
            </ol>
        </nav>
        <h2>Complete inventory report</h2>
        <p><?= number_format($total) ?> asset(s) &middot; total value <?= e(money($totalValue)) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-navy"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($total) ?></span><span class="stat-label d-block">Total assets</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sack-dollar"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= e(money($totalValue, false)) ?></span><span class="stat-label d-block">Total value (PKR)</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-blue"><i class="fa-solid fa-user-check"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($byStatus['assigned'] ?? 0) ?></span><span class="stat-label d-block">Assigned</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-green"><i class="fa-solid fa-warehouse"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($byStatus['available'] ?? 0) ?></span><span class="stat-label d-block">Available</span></span></div>
    </div>
</div>

<form class="filter-bar no-print" method="get" action="<?= url('reports/inventory.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Asset code, equipment or serial">
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="branch_id">Branch</label>
            <select class="form-select" id="branch_id" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchId) ?>><?= e($b['branch_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="department_id">Department</label>
            <select class="form-select" id="department_id" name="department_id">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($d['id'], $deptId) ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="category_id">Category</label>
            <select class="form-select" id="category_id" name="category_id">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($c['id'], $categoryId) ?>><?= e($c['category_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 col-md-6">
            <label class="form-label" for="brand_id">Brand</label>
            <select class="form-select" id="brand_id" name="brand_id">
                <option value="">All</option>
                <?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $brandId) ?>><?= e($b['brand_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 col-md-6">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (asset_statuses() as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($key, $status) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 col-md-6">
            <label class="form-label" for="date_from">From</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-lg-1 col-md-6">
            <label class="form-label" for="date_to">To</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-lg-1 col-md-12 d-flex gap-2">
            <button class="btn btn-brand w-100" type="submit"><i class="fa-solid fa-filter"></i></button>
            <a class="btn btn-outline-brand" href="<?= url('reports/inventory.php') ?>"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </div>
</form>

<div class="print-toolbar no-print">
    <span class="text-muted-sm">Complete inventory &middot; <?= number_format($total) ?> row(s)</span>
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
                    <th>Asset ID</th><th>Category</th><th>Group</th><th>Equipment</th><th>Brand</th><th>Model</th>
                    <th>Serial</th><th>Branch</th><th>Department</th><th>Assigned To</th><th>Purchase Date</th>
                    <th class="text-end">Price</th><th>Warranty</th><th>Condition</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr class="no-filter"><td colspan="15"><div class="empty-state"><i class="fa-solid fa-box-open"></i><h4>No records found</h4><p>Adjust the filters and try again.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($records as $row): ?>
                <tr>
                    <td><span class="fw-code"><?= e($row['asset_code']) ?></span></td>
                    <td class="text-muted-sm"><?= e($row['category_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['group_name'] ?: '-') ?></td>
                    <td><?= e($row['equipment_name']) ?></td>
                    <td class="text-muted-sm"><?= e($row['brand_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['model_number'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['serial_number'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['department_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['employee_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e(fdate($row['purchase_date'])) ?></td>
                    <td class="text-end"><?= e(money($row['purchase_price'])) ?></td>
                    <td class="text-muted-sm"><?= e(fdate($row['warranty_expiry'])) ?></td>
                    <td><?= condition_badge($row['asset_condition']) ?></td>
                    <td><?= status_badge($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($records): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="11">Totals</td>
                    <td class="text-end"><?= e(money($totalValue)) ?></td>
                    <td colspan="3"><?= number_format($total) ?> asset(s)</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
