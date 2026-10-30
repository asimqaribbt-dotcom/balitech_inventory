<?php
/**
 * Reports - branch-wise inventory report.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeParams = $scope['params'];

$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');

$join   = ' LEFT JOIN inventory i ON i.branch_id = b.id ' . $scope['sql'];
$params = $scopeParams;
if (is_valid_date($dateFrom)) { $join .= ' AND i.purchase_date >= :date_from'; $params['date_from'] = $dateFrom; }
if (is_valid_date($dateTo))   { $join .= ' AND i.purchase_date <= :date_to';   $params['date_to'] = $dateTo; }

$rows = db_all(
    "SELECT b.id, b.branch_name, b.city, b.branch_manager,
            COUNT(i.id) AS total_assets,
            SUM(i.status = 'assigned') AS assigned,
            SUM(i.status = 'available') AS available,
            SUM(i.status = 'under_maintenance') AS under_maintenance,
            SUM(i.status = 'damaged') AS damaged,
            SUM(i.status = 'retired') AS retired,
            COALESCE(SUM(i.purchase_price), 0) AS total_value
       FROM branches b
       $join
      WHERE b.status = 'active'
      GROUP BY b.id, b.branch_name, b.city, b.branch_manager
      ORDER BY total_assets DESC, b.branch_name", $params
);

$grand = 0;
$grandValue = 0.0;
foreach ($rows as $row) {
    $grand += (int) $row['total_assets'];
    $grandValue += (float) $row['total_value'];
}

$exportBase = $_GET;
unset($exportBase['page']);
$csvUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'branch', 'format' => 'csv'])));
$pdfUrl = url('reports/export.php?' . http_build_query(array_merge($exportBase, ['report' => 'branch', 'format' => 'pdf'])));

$page_title    = 'Branch Report';
$page_subtitle = 'Asset distribution and value per branch';
$active_menu   = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('reports/index.php') ?>">Reports</a></li>
                <li class="breadcrumb-item active">Branch-wise</li>
            </ol>
        </nav>
        <h2>Branch-wise inventory report</h2>
        <p><?= count($rows) ?> branch(es) &middot; <?= number_format($grand) ?> asset(s) &middot; <?= e(money($grandValue)) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-blue"><i class="fa-solid fa-building"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= count($rows) ?></span><span class="stat-label d-block">Branches</span></span></div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-navy"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= number_format($grand) ?></span><span class="stat-label d-block">Total assets</span></span></div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="stat-card"><span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sack-dollar"></i></span>
            <span class="stat-meta"><span class="stat-value d-block"><?= e(money($grandValue, false)) ?></span><span class="stat-label d-block">Total value (PKR)</span></span></div>
    </div>
</div>

<form class="filter-bar no-print" method="get" action="<?= url('reports/branch.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="date_from">Purchased from</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="date_to">To</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-6 d-flex gap-2">
            <button class="btn btn-brand" type="submit"><i class="fa-solid fa-filter me-2"></i>Apply</button>
            <a class="btn btn-outline-brand" href="<?= url('reports/branch.php') ?>"><i class="fa-solid fa-rotate-left me-2"></i>Reset</a>
        </div>
    </div>
</form>

<div class="print-toolbar no-print">
    <span class="text-muted-sm">Branch-wise report &middot; <?= count($rows) ?> row(s)</span>
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
                    <th>Branch</th><th>City</th><th>Manager</th><th class="text-end">Total</th><th class="text-end">Assigned</th>
                    <th class="text-end">Available</th><th class="text-end">Maint.</th><th class="text-end">Damaged</th>
                    <th class="text-end">Retired</th><th class="text-end">Total Value</th><th>Share</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="no-filter"><td colspan="11"><div class="empty-state"><i class="fa-solid fa-building"></i><h4>No records found</h4><p>Adjust the filters and try again.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row):
                $share = $grand > 0 ? round(((int) $row['total_assets'] / $grand) * 100, 1) : 0; ?>
                <tr>
                    <td>
                        <a class="fw-code" href="<?= url('branches/view.php?id=' . (int) $row['id']) ?>"><?= e($row['branch_name']) ?></a>
                    </td>
                    <td class="text-muted-sm"><?= e($row['city'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['branch_manager'] ?: '-') ?></td>
                    <td class="text-end"><?= number_format((int) $row['total_assets']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['assigned']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['available']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['under_maintenance']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['damaged']) ?></td>
                    <td class="text-end"><?= number_format((int) $row['retired']) ?></td>
                    <td class="text-end"><?= e(money($row['total_value'])) ?></td>
                    <td style="min-width:120px">
                        <div class="d-flex justify-content-between" style="font-size:11.5px"><span><?= $share ?>%</span></div>
                        <div class="progress progress-thin mt-1"><div class="progress-bar" style="width:<?= min(100, $share) ?>%;background:var(--brand)"></div></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="3">Totals</td>
                    <td class="text-end"><?= number_format($grand) ?></td>
                    <td colspan="5"></td>
                    <td class="text-end"><?= e(money($grandValue)) ?></td>
                    <td>100%</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
