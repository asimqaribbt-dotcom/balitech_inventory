<?php
/**
 * Stock Management - overview dashboard.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('stock');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$onlyLow = get_param('filter') === 'low';

/* ---------------------------------------------------------------------
 * KPI figures
 * ------------------------------------------------------------------ */
$availableCount     = (int) db_value("SELECT COUNT(*) FROM inventory i WHERE i.status = 'available' $scopeSql", $scopeParams);
$totalUnits         = (int) db_value("SELECT COUNT(*) FROM inventory i WHERE 1 = 1 $scopeSql", $scopeParams);
$assignedCount      = (int) db_value("SELECT COUNT(*) FROM inventory i WHERE i.status = 'assigned' $scopeSql", $scopeParams);
$maintenanceCount   = (int) db_value("SELECT COUNT(*) FROM inventory i WHERE i.status = 'under_maintenance' $scopeSql", $scopeParams);
$damagedCount       = (int) db_value("SELECT COUNT(*) FROM inventory i WHERE i.status = 'damaged' $scopeSql", $scopeParams);
$retiredCount       = (int) db_value("SELECT COUNT(*) FROM inventory i WHERE i.status = 'retired' $scopeSql", $scopeParams);
$stockValue         = (float) db_value("SELECT COALESCE(SUM(i.purchase_price), 0) FROM inventory i WHERE i.status IN ('available','assigned') $scopeSql", $scopeParams);

/* ---------------------------------------------------------------------
 * Low stock sub-categories
 * ------------------------------------------------------------------ */
$lowStock = db_all(
    "SELECT c.id, c.category_name, c.low_stock_threshold, COUNT(i.id) AS available
       FROM asset_categories c
       LEFT JOIN inventory i ON i.category_id = c.id AND i.status = 'available' $scopeSql
      WHERE c.status = 'active' AND c.parent_id IS NOT NULL
      GROUP BY c.id, c.category_name, c.low_stock_threshold
     HAVING available < c.low_stock_threshold
      ORDER BY (available / c.low_stock_threshold) ASC",
    $scopeParams
);

/* ---------------------------------------------------------------------
 * Recent and unassigned equipment
 * ------------------------------------------------------------------ */
$newEquipment = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.created_at, i.status, i.purchase_price,
            b.branch_name, c.category_name
       FROM inventory i
       LEFT JOIN branches b ON b.id = i.branch_id
       LEFT JOIN asset_categories c ON c.id = i.category_id
      WHERE i.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) $scopeSql
      ORDER BY i.created_at DESC, i.id DESC
      LIMIT 8",
    $scopeParams
);

$unassignedEquipment = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.asset_condition, i.purchase_price, i.status,
            b.branch_name, c.category_name
       FROM inventory i
       LEFT JOIN branches b ON b.id = i.branch_id
       LEFT JOIN asset_categories c ON c.id = i.category_id
      WHERE i.status = 'available' AND i.assigned_employee_id IS NULL $scopeSql
      ORDER BY i.asset_code
      LIMIT 8",
    $scopeParams
);

$statCards = [
    ['Assets in stock',   $availableCount,   'fa-solid fa-warehouse',          'bg-soft-green'],
    ['Total units',       $totalUnits,       'fa-solid fa-boxes-stacked',      'bg-soft-navy'],
    ['Assigned out',      $assignedCount,    'fa-solid fa-user-check',         'bg-soft-blue'],
    ['Under maintenance', $maintenanceCount, 'fa-solid fa-screwdriver-wrench', 'bg-soft-amber'],
    ['Damaged',           $damagedCount,     'fa-solid fa-triangle-exclamation','bg-soft-red'],
    ['Retired',           $retiredCount,     'fa-solid fa-archive',            'bg-soft-gray'],
];

$page_title    = 'Stock Management';
$page_subtitle = 'Warehouse stock levels, alerts and quick actions';
$active_menu   = 'stock';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Stock Management</li>
            </ol>
        </nav>
        <h2>Stock overview</h2>
        <p>Live warehouse position across all categories and branches.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-brand" href="<?= url('stock/in.php') ?>"><i class="fa-solid fa-arrow-down me-2"></i>Stock In</a>
        <a class="btn btn-accent" href="<?= url('stock/out.php') ?>"><i class="fa-solid fa-arrow-up me-2"></i>Stock Out</a>
        <a class="btn btn-outline-brand" href="<?= url('stock/history.php') ?>"><i class="fa-solid fa-clock-rotate-left me-2"></i>Stock History</a>
        <a class="btn btn-outline-brand" href="<?= url('reports/export.php?report=stock&format=csv') ?>"><i class="fa-solid fa-file-csv me-2"></i>Export CSV</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($statCards as $card): list($label, $value, $icon, $bg) = $card; ?>
    <div class="col-xl-2 col-md-4 col-sm-6">
        <div class="stat-card">
            <span class="stat-icon <?= e($bg) ?>"><i class="<?= e($icon) ?>"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format((float) $value) ?></span>
                <span class="stat-label d-block"><?= e($label) ?></span>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-xl-12">
        <div class="stat-card">
            <span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sack-dollar"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= e(money($stockValue, false)) ?></span>
                <span class="stat-label d-block">Total stock value (PKR) - available &amp; assigned assets</span>
            </span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- ===================== LOW STOCK ALERTS ===================== -->
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-red" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-arrow-trend-down"></i></div>
                <div>
                    <h3 class="mb-0">Low stock alerts</h3>
                    <p class="sub mb-0">Sub-categories below the configured threshold</p>
                </div>
                <?php if ($onlyLow): ?>
                <a class="btn btn-sm btn-outline-brand ms-auto" href="<?= url('stock/index.php') ?>">Show all</a>
                <?php else: ?>
                <a class="btn btn-sm btn-outline-brand ms-auto" href="<?= url('stock/index.php?filter=low') ?>">Filter low</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$lowStock): ?>
                    <div class="empty-state py-4"><i class="fa-solid fa-boxes-packing"></i><p class="mb-0">Stock levels are healthy.</p></div>
                <?php else: ?>
                    <?php foreach ($lowStock as $item): ?>
                    <?php $ratio = min(100, (int) ($item['available'] / max(1, (int) $item['low_stock_threshold']) * 100)); ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center" style="font-size:13px">
                            <b><?= e($item['category_name']) ?></b>
                            <span class="d-flex align-items-center gap-2">
                                <span class="text-muted"><?= (int) $item['available'] ?> / <?= (int) $item['low_stock_threshold'] ?></span>
                                <a class="btn btn-sm btn-outline-brand" href="<?= url('stock/in.php') ?>"><i class="fa-solid fa-plus me-1"></i>Add stock</a>
                            </span>
                        </div>
                        <div class="progress progress-thin mt-2">
                            <div class="progress-bar bg-danger" style="width:<?= $ratio ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($onlyLow): ?>
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-amber" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-circle-info"></i></div>
                <div>
                    <h3 class="mb-0">Low stock filter active</h3>
                    <p class="sub mb-0">Only categories below threshold are shown</p>
                </div>
            </div>
            <div class="card-body">
                <div class="alert-row danger">
                    <i class="fa-solid fa-arrow-trend-down" style="color:var(--danger);margin-top:3px"></i>
                    <div class="flex-grow-1">
                        <div class="a-title"><?= count($lowStock) ?> categor<?= count($lowStock) === 1 ? 'y' : 'ies' ?> below threshold</div>
                        <div class="a-meta">Replenish stock from the Stock In page.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- ===================== NEW EQUIPMENT ===================== -->
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-green" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-box-open"></i></div>
                <div>
                    <h3 class="mb-0">New equipment</h3>
                    <p class="sub mb-0">Assets added in the last 30 days</p>
                </div>
                <a class="btn btn-sm btn-outline-brand ms-auto" href="<?= url('inventory/index.php?status=available') ?>">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Asset</th><th>Category</th><th>Branch</th><th>Added</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$newEquipment): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No new assets in the last 30 days.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($newEquipment as $asset): ?>
                        <tr>
                            <td>
                                <a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $asset['id']) ?>"><?= e($asset['asset_code']) ?></a>
                                <div class="text-muted-sm"><?= e($asset['equipment_name']) ?></div>
                            </td>
                            <td class="text-muted-sm"><?= e($asset['category_name'] ?: '-') ?></td>
                            <td class="text-muted-sm"><?= e($asset['branch_name'] ?: '-') ?></td>
                            <td class="text-muted-sm"><?= e(time_ago($asset['created_at'])) ?></td>
                            <td><?= status_badge($asset['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!$onlyLow): ?>
<div class="row g-3">
    <!-- ===================== UNASSIGNED EQUIPMENT ===================== -->
    <div class="col-xl-12">
        <div class="card">
            <div class="card-header">
                <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-warehouse"></i></div>
                <div>
                    <h3 class="mb-0">Unassigned equipment</h3>
                    <p class="sub mb-0">Available assets currently held in store</p>
                </div>
                <a class="btn btn-sm btn-outline-brand ms-auto" href="<?= url('inventory/index.php?status=available') ?>">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Asset</th><th>Category</th><th>Branch</th><th>Condition</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php if (!$unassignedEquipment): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No unassigned equipment in store.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($unassignedEquipment as $asset): ?>
                        <tr>
                            <td>
                                <a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $asset['id']) ?>"><?= e($asset['asset_code']) ?></a>
                                <div class="text-muted-sm"><?= e($asset['equipment_name']) ?></div>
                            </td>
                            <td class="text-muted-sm"><?= e($asset['category_name'] ?: '-') ?></td>
                            <td class="text-muted-sm"><?= e($asset['branch_name'] ?: '-') ?></td>
                            <td><?= condition_badge($asset['asset_condition']) ?></td>
                            <td class="text-muted-sm"><?= e(money((float) $asset['purchase_price'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
