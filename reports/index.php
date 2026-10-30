<?php
/**
 * Reports - catalogue of all available reports with a global date range
 * filter, an inventory summary panel and CSV / PDF shortcuts.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

/* Global date range (appended to every report link) */
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');
$range    = [];
if (is_valid_date($dateFrom)) {
    $range['date_from'] = $dateFrom;
}
if (is_valid_date($dateTo)) {
    $range['date_to'] = $dateTo;
}
$rangeQs = $range ? '?' . http_build_query($range) : '';
$rangeAmp = $range ? '&' . http_build_query($range) : '';

/* ---------------------------------------------------------------- summary */
$totalAssets = (int) db_value("SELECT COUNT(*) FROM inventory i WHERE 1 = 1 $scopeSql", $scopeParams);
$totalValue  = (float) db_value("SELECT COALESCE(SUM(i.purchase_price), 0) FROM inventory i WHERE i.status <> 'retired' $scopeSql", $scopeParams);

$statusCounts = [];
foreach (db_all("SELECT i.status, COUNT(*) AS total FROM inventory i WHERE 1 = 1 $scopeSql GROUP BY i.status", $scopeParams) as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}
$assigned   = $statusCounts['assigned'] ?? 0;
$available  = $statusCounts['available'] ?? 0;
$maintCount = $statusCounts['under_maintenance'] ?? 0;
$damaged    = $statusCounts['damaged'] ?? 0;
$retired    = $statusCounts['retired'] ?? 0;

$summaryCards = [
    ['Total Assets',     $totalAssets, 'fa-solid fa-boxes-stacked',       'bg-soft-navy'],
    ['Total Value',      $totalValue,  'fa-solid fa-sack-dollar',         'bg-soft-teal'],
    ['Assigned',         $assigned,    'fa-solid fa-user-check',          'bg-soft-blue'],
    ['Available',        $available,   'fa-solid fa-warehouse',           'bg-soft-green'],
    ['Under Maintenance', $maintCount, 'fa-solid fa-screwdriver-wrench',  'bg-soft-amber'],
    ['Damaged',          $damaged,     'fa-solid fa-triangle-exclamation','bg-soft-red'],
];

/* -------------------------------------------------------------- catalogue */
$reports = [
    ['key' => 'inventory',   'file' => 'inventory.php',   'title' => 'Complete Inventory',   'icon' => 'fa-solid fa-boxes-stacked',        'bg' => 'bg-soft-navy',   'desc' => 'Every asset with category, branch, department, assignment, price and status.'],
    ['key' => 'branch',      'file' => 'branch.php',      'title' => 'Branch-wise Inventory','icon' => 'fa-solid fa-building',             'bg' => 'bg-soft-blue',   'desc' => 'Asset distribution and value per branch with status breakdown.'],
    ['key' => 'department',  'file' => 'department.php',  'title' => 'Department-wise',      'icon' => 'fa-solid fa-sitemap',              'bg' => 'bg-soft-teal',   'desc' => 'Assets held by each department, grouped by branch.'],
    ['key' => 'employee',    'file' => 'employee.php',    'title' => 'Employee-wise Assets', 'icon' => 'fa-solid fa-users',                'bg' => 'bg-soft-purple', 'desc' => 'Assets assigned to each employee with total value.'],
    ['key' => 'category',    'file' => 'category.php',    'title' => 'Category-wise',        'icon' => 'fa-solid fa-layer-group',          'bg' => 'bg-soft-amber',  'desc' => 'Sub-category stock with low-stock threshold warnings.'],
    ['key' => 'stock',       'file' => 'stock.php',       'title' => 'Available Stock',      'icon' => 'fa-solid fa-warehouse',            'bg' => 'bg-soft-green',  'desc' => 'Available vs assigned / maintenance stock per category.'],
    ['key' => 'maintenance', 'file' => 'maintenance.php', 'title' => 'Maintenance',          'icon' => 'fa-solid fa-screwdriver-wrench',   'bg' => 'bg-soft-red',    'desc' => 'Fault records, technicians, repair dates and maintenance cost.'],
    ['key' => 'damaged',     'file' => 'damaged.php',     'title' => 'Damaged Equipment',    'icon' => 'fa-solid fa-triangle-exclamation', 'bg' => 'bg-soft-red',    'desc' => 'Damaged (and optionally retired) assets with book value.'],
    ['key' => 'transfer',    'file' => 'transfer.php',    'title' => 'Equipment Transfers',  'icon' => 'fa-solid fa-right-left',           'bg' => 'bg-soft-gray',   'desc' => 'Asset movement between branches and departments.'],
];

$page_title    = 'Reports';
$page_subtitle = 'Operational and analytical reports across the inventory';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </nav>
        <h2>Report centre</h2>
        <p>Choose a report to view, export as CSV or print as PDF.</p>
    </div>
</div>

<!-- ======================== GLOBAL DATE RANGE ======================= -->
<form class="filter-bar" method="get" action="<?= url('reports/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="date_from">Global date range - from</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="date_to">To</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-6 d-flex gap-2">
            <button class="btn btn-brand" type="submit"><i class="fa-solid fa-filter me-2"></i>Apply to all reports</button>
            <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-rotate-left me-2"></i>Reset</a>
            <?php if ($range): ?>
            <span class="align-self-center text-muted-sm">Applied: <?= e(($dateFrom ?: '...') . ' to ' . ($dateTo ?: '...')) ?></span>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- ========================= SUMMARY PANEL ========================== -->
<div class="row g-3 mb-4">
    <?php foreach ($summaryCards as $card): list($label, $value, $icon, $bg) = $card; ?>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon <?= e($bg) ?>"><i class="<?= e($icon) ?>"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= $label === 'Total Value' ? e(money($value, false)) : number_format((float) $value) ?></span>
                <span class="stat-label d-block"><?= e($label) ?><?= $label === 'Total Value' ? ' (PKR)' : '' ?></span>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ========================= REPORT CARDS =========================== -->
<div class="row g-3">
    <?php foreach ($reports as $report):
        $openUrl = url('reports/' . $report['file'] . $rangeQs);
        $csvUrl  = url('reports/export.php?report=' . $report['key'] . '&format=csv' . $rangeAmp);
        $pdfUrl  = url('reports/export.php?report=' . $report['key'] . '&format=pdf' . $rangeAmp);
    ?>
    <div class="col-xl-4 col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon <?= e($report['bg']) ?>" style="width:44px;height:44px;font-size:17px"><i class="<?= e($report['icon']) ?>"></i></div>
                <div><h3 class="mb-0"><?= e($report['title']) ?></h3><p class="sub mb-0">Report</p></div>
            </div>
            <div class="card-body">
                <p class="text-muted-sm mb-0" style="min-height:40px"><?= e($report['desc']) ?></p>
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-brand" href="<?= e($openUrl) ?>"><i class="fa-solid fa-arrow-right me-1"></i>Open</a>
                <a class="btn btn-sm btn-outline-brand" href="<?= e($csvUrl) ?>"><i class="fa-solid fa-file-csv me-1"></i>CSV</a>
                <a class="btn btn-sm btn-outline-brand" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf me-1"></i>PDF</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
