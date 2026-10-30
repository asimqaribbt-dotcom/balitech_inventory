<?php
/**
 * Reports - category-wise inventory report with a group summary table
 * and low-stock threshold warnings.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$categoryId = (int) get_param('category_id', 0);
$branchId   = (int) get_param('branch_id', 0);

$join   = ' LEFT JOIN inventory i ON i.category_id = c.id ' . $scopeSql;
$params = $scopeParams;
if ($branchId > 0) { $join .= ' AND i.branch_id = :branch'; $params['branch'] = $branchId; }

$catFilter = '';
if ($categoryId > 0) { $catFilter = ' AND c.id = :cat'; $params['cat'] = $categoryId; }

$groups = db_all(
    "SELECT p.category_name AS group_name,
            COUNT(i.id) AS total_assets,
            COALESCE(SUM(i.purchase_price), 0) AS total_value
       FROM asset_categories p
       LEFT JOIN asset_categories c ON c.parent_id = p.id
       LEFT JOIN inventory i ON i.category_id = c.id " . $scopeSql . "
      WHERE p.parent_id IS NULL AND p.status = 'active'
      GROUP BY p.id, p.category_name
      ORDER BY total_assets DESC, p.category_name", $scopeParams
);

$rows = db_all(
    "SELECT c.category_name, p.category_name AS parent_name,
            COUNT(i.id) AS total_assets,
            SUM(i.status = 'available') AS available,
            SUM(i.status = 'assigned') AS assigned,
            SUM(i.status = 'under_maintenance') AS under_maintenance,
            SUM(i.status = 'damaged') AS damaged,
            COALESCE(SUM(i.purchase_price), 0) AS total_value,
            c.low_stock_threshold
       FROM asset_categories c
       LEFT JOIN asset_categories p ON p.id = c.parent_id
       $join
      WHERE c.status = 'active' AND c.parent_id IS NOT NULL $catFilter
      GROUP BY c.id, c.category_name, p.category_name, c.low_stock_threshold
      ORDER BY p.category_name, c.category_name", $params
);

$grand = 0;
$grandValue = 0.0;
$lowCount = 0;
foreach ($rows as $row) {
    $grand += (int) $row['total_assets'];
    $grandValue += (float) $row['total_value'];
    if ((int) $row['available'] < (int) $row['low_stock_threshold']) { $lowCount++; }
}

$categories = db_all('SELECT id, category_name FROM asset_categories WHERE parent_id IS NOT NULL AND status = "active" ORDER BY category_name');
$branches   = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$exportBase = $_GET;
unset($exportBase['page']);
$csvUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'category', 'format' => 'csv'])));
$pdfUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'category', 'format' => 'pdf'])));

$page_title    = 'Category Report';
$page_subtitle = 'Stock and value by equipment category';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('reports/index.php') ?>">Reports</a></li>
                <li class="breadcrumb-item active">Category-wise</li>
            </ol>
        </nav>
        <h2>Category-wise inventory report</h2>
        <p><?= count($rows) ?> sub-category(ies) &middot; <?= number_format($grand) ?> asset(s) &middot; <?= e(money($grandValue)) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-amber"><i class="fa-solid fa-layer-group"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format(count($rows)) ?></span><span class="stat-label d-block">Sub-categories</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-navy"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($grand) ?></span><span class="stat-label d-block">Total assets</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sack-dollar"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= e(money($grandValue, false)) ?></span><span class="stat-label d-block">Total value (PKR)</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-red"><i class="fa-solid fa-arrow-trend-down"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($lowCount) ?></span><span class="stat-label d-block">Low stock categories</span></span></div>
    </div>
</div>

<form class="filter-bar no-print" method="get" action="<?= url('reports/category.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label" for="category_id">Category</label>
            <select class="form-select" id="category_id" name="category_id">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($c['id'], $categoryId) ?>><?= e($c['category_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="branch_id">Branch</label>
            <select class="form-select" id="branch_id" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $branchId) ?>><?= e($b['branch_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button class="btn btn-brand" type="submit"><i class="fa-solid fa-filter me-2"></i>Apply</button>
            <a class="btn btn-outline-brand" href="<?= url('reports/category.php') ?>"><i class="fa-solid fa-rotate-left me-2"></i>Reset</a>
        </div>
    </div>
</form>

<!-- Group summary -->
<div class="card mb-4">
    <div class="card-header">
        <div class="stat-icon bg-soft-navy" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-shapes"></i></div>
        <div><h3 class="mb-0">Equipment groups</h3><p class="sub mb-0">Summary by parent category</p></div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Group</th><th class="text-end">Total Assets</th><th class="text-end">Total Value</th></tr></thead>
            <tbody>
            <?php if (!$groups): ?><tr class="no-filter"><td colspan="3" class="text-center text-muted py-4">No groups found.</td></tr><?php endif; ?>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td><?= e($g['group_name']) ?></td>
                    <td class="text-end"><?= number_format((int) $g['total_assets']) ?></td>
                    <td class="text-end"><?= e(money($g['total_value'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="print-toolbar no-print">
    <span class="text-muted-sm">Category-wise report &middot; <?= count($rows) ?> row(s)</span>
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
                    <th>Category</th><th>Parent Group</th><th class="text-end">Total</th><th class="text-end">Available</th>
                    <th class="text-end">Assigned</th><th class="text-end">Maintenance</th><th class="text-end">Damaged</th>
                    <th class="text-end">Total Value</th><th class="text-end">Threshold</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="no-filter"><td colspan="9"><div class="empty-state"><i class="fa-solid fa-layer-group"></i><h4>No records found</h4><p>Adjust the filters and try again.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row):
                $isLow = (int) $row['available'] < (int) $row['low_stock_threshold']; ?>
                <tr<?= $isLow ? ' style="background:#fff8e8"' : '' ?>>
                    <td><?= e($row['category_name']) ?><?= $isLow ? ' <span class="badge badge-soft badge-warning ms-1"><i class="fa-solid fa-triangle-exclamation"></i> Low</span>' : '' ?></td>
                    <td class="text-muted-sm"><?= e($row['parent_name'] ?: '-') ?></td>
                    <td class="text-end"><?= number_format((int) $row['total_assets']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['available']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['assigned']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['under_maintenance']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['damaged']) ?></td>
                    <td class="text-end"><?= e(money($row['total_value'])) ?></td>
                    <td class="text-end"><?= (int) $row['low_stock_threshold'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="2">Totals</td>
                    <td class="text-end"><?= number_format($grand) ?></td>
                    <td colspan="4"></td>
                    <td class="text-end"><?= e(money($grandValue)) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
