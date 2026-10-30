<?php
/**
 * Reports - available stock report.
 * One row per category with an available-stock percentage and a totals row.
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

$rows = db_all(
    "SELECT c.category_name, p.category_name AS parent_name,
            COUNT(i.id) AS total_units,
            SUM(i.status = 'available') AS available,
            SUM(i.status = 'assigned') AS assigned,
            SUM(i.status = 'under_maintenance') AS under_maintenance,
            SUM(i.status = 'damaged') AS damaged,
            SUM(i.status = 'retired') AS retired
       FROM asset_categories c
       LEFT JOIN asset_categories p ON p.id = c.parent_id
       $join
      WHERE c.status = 'active' AND c.parent_id IS NOT NULL $catFilter
      GROUP BY c.id, c.category_name, p.category_name
      ORDER BY c.category_name", $params
);

$tot = ['total' => 0, 'available' => 0, 'assigned' => 0, 'maint' => 0, 'damaged' => 0, 'retired' => 0];
foreach ($rows as $row) {
    $tot['total']     += (int) $row['total_units'];
    $tot['available'] += (int) $row['available'];
    $tot['assigned']  += (int) $row['assigned'];
    $tot['maint']     += (int) $row['under_maintenance'];
    $tot['damaged']   += (int) $row['damaged'];
    $tot['retired']   += (int) $row['retired'];
}
$totPct = $tot['total'] > 0 ? round(($tot['available'] / $tot['total']) * 100, 1) : 0;

$categories = db_all('SELECT id, category_name FROM asset_categories WHERE parent_id IS NOT NULL AND status = "active" ORDER BY category_name');
$branches   = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$exportBase = $_GET;
unset($exportBase['page']);
$csvUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'stock', 'format' => 'csv'])));
$pdfUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'stock', 'format' => 'pdf'])));

$page_title    = 'Available Stock';
$page_subtitle = 'Available vs assigned, maintenance and damaged stock';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('reports/index.php') ?>">Reports</a></li>
                <li class="breadcrumb-item active">Available Stock</li>
            </ol>
        </nav>
        <h2>Available stock report</h2>
        <p><?= count($rows) ?> category(ies) &middot; <?= number_format($tot['available']) ?> of <?= number_format($tot['total']) ?> unit(s) available</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-navy"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($tot['total']) ?></span><span class="stat-label d-block">Total units</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-green"><i class="fa-solid fa-warehouse"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($tot['available']) ?></span><span class="stat-label d-block">Available</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-blue"><i class="fa-solid fa-user-check"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($tot['assigned']) ?></span><span class="stat-label d-block">Assigned</span></span></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-teal"><i class="fa-solid fa-percent"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= $totPct ?>%</span><span class="stat-label d-block">Available ratio</span></span></div>
    </div>
</div>

<form class="filter-bar no-print" method="get" action="<?= url('reports/stock.php') ?>">
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
            <a class="btn btn-outline-brand" href="<?= url('reports/stock.php') ?>"><i class="fa-solid fa-rotate-left me-2"></i>Reset</a>
        </div>
    </div>
</form>

<div class="print-toolbar no-print">
    <span class="text-muted-sm">Available stock &middot; <?= count($rows) ?> row(s)</span>
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
                    <th>Category</th><th>Parent Group</th><th class="text-end">Total Units</th><th class="text-end">Available</th>
                    <th class="text-end">Assigned</th><th class="text-end">Maintenance</th><th class="text-end">Damaged</th>
                    <th class="text-end">Retired</th><th style="min-width:140px">Available %</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="no-filter"><td colspan="9"><div class="empty-state"><i class="fa-solid fa-warehouse"></i><h4>No records found</h4><p>Adjust the filters and try again.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row):
                $pct = (int) $row['total_units'] > 0 ? round(((int) $row['available'] / (int) $row['total_units']) * 100, 1) : 0; ?>
                <tr>
                    <td><?= e($row['category_name']) ?></td>
                    <td class="text-muted-sm"><?= e($row['parent_name'] ?: '-') ?></td>
                    <td class="text-end"><?= number_format((int) $row['total_units']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['available']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['assigned']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['under_maintenance']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['damaged']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['retired']) ?></td>
                    <td>
                        <div class="d-flex justify-content-between" style="font-size:11.5px"><span><?= $pct ?>%</span></div>
                        <div class="progress progress-thin mt-1"><div class="progress-bar" style="width:<?= min(100, $pct) ?>%;background:var(--success)"></div></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="2">Totals</td>
                    <td class="text-end"><?= number_format($tot['total']) ?></td>
                    <td class="text-end"><?= number_format($tot['available']) ?></td>
                    <td class="text-end"><?= number_format($tot['assigned']) ?></td>
                    <td class="text-end"><?= number_format($tot['maint']) ?></td>
                    <td class="text-end"><?= number_format($tot['damaged']) ?></td>
                    <td class="text-end"><?= number_format($tot['retired']) ?></td>
                    <td><?= $totPct ?>%</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
