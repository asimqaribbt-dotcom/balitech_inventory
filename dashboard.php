<?php
/**
 * Dashboard - live inventory overview.
 * All figures are calculated from the MySQL database and respect the
 * data scope of the signed in role (branch / department).
 */
require_once __DIR__ . '/init.php';
require_login();

$user  = current_user();
$scope = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

/* ---------------------------------------------------------------------
 * KPI figures
 * ------------------------------------------------------------------ */
$totalAssets = (int) db_value("SELECT COUNT(*) FROM inventory i WHERE 1 = 1 $scopeSql", $scopeParams);

$statusCounts = [];
foreach (db_all("SELECT i.status, COUNT(*) AS total FROM inventory i WHERE 1 = 1 $scopeSql GROUP BY i.status",
        $scopeParams) as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}
$countOf = static function (string $status) use ($statusCounts): int {
    return $statusCounts[$status] ?? 0;
};

/** Count assets that belong to an equipment group (parent category) or match a leaf name. */
$groupCount = static function (string $groupLike, string $leafLike = '') use ($scopeSql, $scopeParams): int {
    if ($leafLike !== '') {
        $sql = "SELECT COUNT(*)
                  FROM inventory i
                  JOIN asset_categories c ON c.id = i.category_id
                 WHERE c.category_name LIKE :leaf1
                 $scopeSql";
        $params = ['leaf1' => $leafLike];
    } else {
        $sql = "SELECT COUNT(*)
                  FROM inventory i
                  JOIN asset_categories c ON c.id = i.category_id
                 WHERE (c.parent_id = (SELECT id FROM asset_categories
                                        WHERE parent_id IS NULL AND category_name LIKE :grp1 LIMIT 1)
                        OR c.category_name LIKE :grp2)
                 $scopeSql";
        $params = ['grp1' => $groupLike, 'grp2' => $groupLike];
    }
    return (int) db_value($sql, array_merge($params, $scopeParams));
};

// Organisation counts respect the role scope as well
if (is_role('branch_staff') && !empty($user['branch_id'])) {
    $totalBranches = 1;
    $totalDepartments = (int) db_value('SELECT COUNT(*) FROM departments WHERE branch_id = ?', [$user['branch_id']]);
    $totalEmployees = (int) db_value('SELECT COUNT(*) FROM employees WHERE branch_id = ?', [$user['branch_id']]);
} elseif (is_role('dept_manager') && !empty($user['department_id'])) {
    $totalBranches = (int) db_value('SELECT COUNT(DISTINCT branch_id) FROM inventory WHERE department_id = ?', [$user['department_id']]);
    $totalDepartments = 1;
    $totalEmployees = (int) db_value('SELECT COUNT(*) FROM employees WHERE department_id = ?', [$user['department_id']]);
} else {
    $totalBranches    = (int) db_value('SELECT COUNT(*) FROM branches');
    $totalDepartments = (int) db_value('SELECT COUNT(*) FROM departments');
    $totalEmployees   = (int) db_value('SELECT COUNT(*) FROM employees');
}

$assignedAssets  = $countOf('assigned');
$availableStock  = $countOf('available');
$underMaint      = $countOf('under_maintenance');
$damagedAssets   = $countOf('damaged');
$retiredAssets   = $countOf('retired');

$totalPCs        = $groupCount('Computers%');
$totalMonitors   = $groupCount('Monitors%');
$totalHeadsets   = $groupCount('', '%Headset%');
$totalNetworking = $groupCount('Networking Equipment%');

$totalValue   = (float) db_value("SELECT COALESCE(SUM(i.purchase_price),0) FROM inventory i WHERE i.status <> 'retired' $scopeSql", $scopeParams);
$assignedRate = $totalAssets > 0 ? round(($assignedAssets / $totalAssets) * 100) : 0;

// Assignments that are still open + warranty expiring within 60 days
$activeAssignments = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments a
       JOIN inventory i ON i.id = a.asset_id
      WHERE a.assignment_status = 'assigned' $scopeSql", $scopeParams);

$warrantyExpiring = (int) db_value(
    "SELECT COUNT(*) FROM inventory i
      WHERE i.warranty_expiry IS NOT NULL
        AND i.warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
        AND i.status <> 'retired' $scopeSql", $scopeParams);

/* ---------------------------------------------------------------------
 * Chart data
 * ------------------------------------------------------------------ */
$statusChart = ['labels' => [], 'values' => [], 'colors' => []];
$statusColors = [
    'available'         => '#157f5b',
    'assigned'          => '#1b6ca8',
    'under_maintenance' => '#b7791f',
    'damaged'           => '#c1121f',
    'retired'           => '#7c8aa0',
];
foreach (asset_statuses() as $key => $label) {
    $statusChart['labels'][] = $label;
    $statusChart['values'][] = $countOf($key);
    $statusChart['colors'][] = $statusColors[$key];
}

$branchChart = ['labels' => [], 'values' => []];
foreach (db_all("SELECT b.branch_name AS label, COUNT(i.id) AS total
                   FROM branches b
                   LEFT JOIN inventory i ON i.branch_id = b.id AND 1 = 1 $scopeSql
                  GROUP BY b.id, b.branch_name
                  ORDER BY total DESC, b.branch_name", $scopeParams) as $row) {
    $branchChart['labels'][] = $row['label'];
    $branchChart['values'][] = (int) $row['total'];
}

$deptChart = ['labels' => [], 'values' => []];
foreach (db_all("SELECT d.department_name AS label, COUNT(i.id) AS total
                   FROM departments d
                   LEFT JOIN inventory i ON i.department_id = d.id AND 1 = 1 $scopeSql
                  GROUP BY d.id, d.department_name
                  ORDER BY total DESC, d.department_name
                  LIMIT 8", $scopeParams) as $row) {
    $deptChart['labels'][] = $row['label'];
    $deptChart['values'][] = (int) $row['total'];
}

$categoryChart = ['labels' => [], 'values' => []];
foreach (db_all("SELECT p.category_name AS label, COUNT(i.id) AS total
                   FROM asset_categories p
                   LEFT JOIN asset_categories c ON c.parent_id = p.id
                   LEFT JOIN inventory i ON i.category_id = c.id AND 1 = 1 $scopeSql
                  WHERE p.parent_id IS NULL
                  GROUP BY p.id, p.category_name
                  ORDER BY total DESC", $scopeParams) as $row) {
    $categoryChart['labels'][] = $row['label'];
    $categoryChart['values'][] = (int) $row['total'];
}

/* ---------------------------------------------------------------------
 * Tables
 * ------------------------------------------------------------------ */
$recentActivity = db_all(
    'SELECT module, action, description, user_name, created_at
       FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT 8'
);

$recentAssets = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.purchase_date, i.created_at, i.status,
            c.category_name, b.brand_name, br.branch_name
       FROM inventory i
       LEFT JOIN asset_categories c ON c.id = i.category_id
       LEFT JOIN brands b ON b.id = i.brand_id
       LEFT JOIN branches br ON br.id = i.branch_id
      WHERE 1 = 1 $scopeSql
      ORDER BY i.created_at DESC, i.id DESC LIMIT 6", $scopeParams
);

$maintenanceAlerts = db_all(
    "SELECT m.id, m.issue_title, m.status, m.reported_date, m.technician_name,
            i.id AS inventory_id, i.asset_code, i.equipment_name
       FROM maintenance_records m
       JOIN inventory i ON i.id = m.asset_id
      WHERE m.status IN ('reported','in_progress') $scopeSql
      ORDER BY FIELD(m.status, 'reported', 'in_progress'), m.reported_date DESC
      LIMIT 6", $scopeParams
);

$lowStock = db_all(
    "SELECT c.category_name, c.low_stock_threshold,
            COUNT(i.id) AS available
       FROM asset_categories c
       LEFT JOIN inventory i ON i.category_id = c.id AND i.status = 'available'
      WHERE c.status = 'active' AND c.parent_id IS NOT NULL
      GROUP BY c.id, c.category_name, c.low_stock_threshold
     HAVING available < c.low_stock_threshold
      ORDER BY (available / c.low_stock_threshold) ASC
      LIMIT 5"
);

// KPI card definitions --------------------------------------------------
$kpiCards = [
    ['Total IT Assets',  $totalAssets,    'fa-solid fa-boxes-stacked',      'bg-soft-navy',  'inventory/index.php',          'Worth ' . money($totalValue, false) . ' PKR'],
    ['Total Branches',   $totalBranches,  'fa-solid fa-building',           'bg-soft-blue',  'branches/index.php',           'Active locations'],
    ['Total Departments',$totalDepartments,'fa-solid fa-sitemap',           'bg-soft-teal',  'departments/index.php',        'Across the company'],
    ['Total Employees',  $totalEmployees, 'fa-solid fa-users',              'bg-soft-purple','employees/index.php',          'Registered staff'],
    ['Assigned Assets',  $assignedAssets, 'fa-solid fa-user-check',         'bg-soft-blue',  'inventory/index.php?status=assigned', $assignedRate . '% of all assets'],
    ['Available Stock',  $availableStock, 'fa-solid fa-warehouse',          'bg-soft-green', 'inventory/index.php?status=available', 'Ready to issue'],
    ['Under Maintenance', $underMaint,    'fa-solid fa-screwdriver-wrench', 'bg-soft-amber', 'maintenance/index.php',        $activeAssignments . ' active assignments'],
    ['Damaged Assets',   $damagedAssets,  'fa-solid fa-triangle-exclamation','bg-soft-red', 'inventory/index.php?status=damaged', 'Require attention'],
    ['Total PCs',        $totalPCs,       'fa-solid fa-desktop',            'bg-soft-navy',  'reports/category.php',         'Desktops, laptops, CPUs'],
    ['Total Monitors',   $totalMonitors,  'fa-solid fa-display',            'bg-soft-teal',  'reports/category.php',         'LCD, LED and curved'],
    ['Total Headsets',   $totalHeadsets,  'fa-solid fa-headset',            'bg-soft-purple','reports/category.php',         'Voice and support gear'],
    ['Networking Equip.', $totalNetworking, 'fa-solid fa-network-wired',   'bg-soft-blue',  'reports/category.php',         'Routers, switches, APs'],
];

$page_title    = 'Dashboard';
$page_subtitle = 'Welcome back, ' . $user['full_name'] . ' - here is the live inventory status';
$active_menu   = 'dashboard';
$load_charts   = true;

require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb"><li class="breadcrumb-item active">Dashboard</li></ol>
        </nav>
        <h2>Inventory overview</h2>
        <p><?= e(APP_COMPANY) ?> &middot; <?= e(role_label($user['role'])) ?>
            <?= $user['branch_name'] ? ' &middot; ' . e($user['branch_name']) : '' ?>
            &middot; as of <?= date('d M Y, h:i A') ?></p>
    </div>
    <div class="page-actions">
        <?php if (can('inventory')): ?>
        <a class="btn btn-brand" href="<?= url('inventory/add.php') ?>"><i class="fa-solid fa-plus me-2"></i>Add New Asset</a>
        <?php endif; ?>
        <?php if (can('assignments')): ?>
        <a class="btn btn-outline-brand" href="<?= url('assignments/create.php') ?>"><i class="fa-solid fa-user-check me-2"></i>Assign Asset</a>
        <?php endif; ?>
        <?php if (can('maintenance')): ?>
        <a class="btn btn-outline-brand" href="<?= url('maintenance/create.php') ?>"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Report Fault</a>
        <?php endif; ?>
        <a class="btn btn-outline-brand" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-chart-column me-2"></i>Reports</a>
    </div>
</div>

<!-- ============================ KPI CARDS ============================ -->
<div class="row g-3 mb-4">
    <?php foreach ($kpiCards as $card): list($label, $value, $icon, $bg, $link, $foot) = $card; ?>
    <div class="col-xxl-3 col-xl-4 col-md-6">
        <a class="stat-card" href="<?= url($link) ?>">
            <span class="stat-icon <?= e($bg) ?>"><i class="<?= e($icon) ?>"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format((float) $value) ?></span>
                <span class="stat-label d-block"><?= e($label) ?></span>
                <span class="stat-foot text-muted d-block"><i class="fa-solid fa-circle-info"></i><?= e($foot) ?></span>
            </span>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- ============================== CHARTS ============================= -->
<div class="row g-3 mb-4">
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-chart-pie"></i></div>
                <div>
                    <h3 class="mb-0">Inventory overview</h3>
                    <p class="sub mb-0">Assets by current status</p>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-box" style="height:250px"><canvas id="statusChart"></canvas></div>
                <ul class="chart-legend">
                    <?php foreach ($statusChart['labels'] as $index => $label): ?>
                    <li><span class="dot" style="background:<?= e($statusChart['colors'][$index]) ?>"></span><?= e($label) ?>
                        <b>(<?= (int) $statusChart['values'][$index] ?>)</b></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-navy" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-building"></i></div>
                <div>
                    <h3 class="mb-0">Branch-wise inventory</h3>
                    <p class="sub mb-0">Assets held per branch</p>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-box" style="height:288px"><canvas id="branchChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-teal" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-sitemap"></i></div>
                <div>
                    <h3 class="mb-0">Department-wise inventory</h3>
                    <p class="sub mb-0">Top departments by assets</p>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-box" style="height:288px"><canvas id="deptChart"></canvas></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- ======================= RECENT ACTIVITY ======================= -->
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-gray" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div>
                    <h3 class="mb-0">Recent inventory activity</h3>
                    <p class="sub mb-0">Latest actions recorded in the system</p>
                </div>
                <a class="btn btn-sm btn-outline-brand ms-auto" href="<?= url('activity_logs.php') ?>">View all</a>
            </div>
            <div class="card-body">
                <?php if (!$recentActivity): ?>
                    <div class="empty-state py-4"><i class="fa-regular fa-clock"></i><p class="mb-0">No activity recorded yet.</p></div>
                <?php else: ?>
                <div class="timeline">
                    <?php foreach ($recentActivity as $log): ?>
                    <div class="timeline-item">
                        <div class="t-title"><?= e($log['description']) ?></div>
                        <div class="t-meta">
                            <b><?= e($log['user_name']) ?></b> &middot; <?= e($log['module']) ?>
                            &middot; <?= e(time_ago($log['created_at'])) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==================== RECENTLY ADDED ASSETS ==================== -->
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-green" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-box-open"></i></div>
                <div>
                    <h3 class="mb-0">Recently added equipment</h3>
                    <p class="sub mb-0">Newest entries in the asset register</p>
                </div>
                <a class="btn btn-sm btn-outline-brand ms-auto" href="<?= url('inventory/index.php') ?>">All assets</a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Asset ID</th><th>Equipment</th><th>Branch</th><th>Purchased</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentAssets): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No assets found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentAssets as $asset): ?>
                        <tr>
                            <td><a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $asset['id']) ?>"><?= e($asset['asset_code']) ?></a></td>
                            <td>
                                <div><?= e($asset['equipment_name']) ?></div>
                                <div class="text-muted-sm"><?= e($asset['brand_name'] ?: '-') ?> &middot; <?= e($asset['category_name']) ?></div>
                            </td>
                            <td class="text-muted-sm"><?= e($asset['branch_name'] ?: '-') ?></td>
                            <td class="text-muted-sm"><?= e(fdate($asset['purchase_date'])) ?></td>
                            <td><?= status_badge($asset['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- ==================== MAINTENANCE ALERTS ==================== -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-amber" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <h3 class="mb-0">Equipment maintenance alerts</h3>
                    <p class="sub mb-0">Open faults and repairs in progress</p>
                </div>
                <a class="btn btn-sm btn-outline-brand ms-auto" href="<?= url('maintenance/index.php') ?>">Maintenance</a>
            </div>
            <div class="card-body">
                <?php if (!$maintenanceAlerts): ?>
                    <div class="empty-state py-4">
                        <i class="fa-solid fa-circle-check" style="color:var(--success)"></i>
                        <p class="mb-0">No open maintenance records. All equipment is healthy.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($maintenanceAlerts as $record): ?>
                    <div class="alert-row <?= $record['status'] === 'reported' ? 'danger' : '' ?>">
                        <i class="fa-solid fa-screwdriver-wrench" style="color:var(--warning);margin-top:3px"></i>
                        <div class="flex-grow-1">
                            <div class="a-title"><?= e($record['issue_title']) ?></div>
                            <div class="a-meta">
                                <a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $record['inventory_id']) ?>">
                                    <?= e($record['asset_code']) ?>
                                </a>
                                <?= e($record['equipment_name']) ?> &middot;
                                reported <?= e(fdate($record['reported_date'])) ?>
                                <?= $record['technician_name'] ? ' &middot; ' . e($record['technician_name']) : '' ?>
                            </div>
                        </div>
                        <?= maintenance_badge($record['status']) ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ========================= LOW STOCK ========================== -->
    <div class="col-xl-3">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-red" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-arrow-trend-down"></i></div>
                <div>
                    <h3 class="mb-0">Low stock alerts</h3>
                    <p class="sub mb-0">Below configured threshold</p>
                </div>
            </div>
            <div class="card-body">
                <?php if (!$lowStock): ?>
                    <div class="empty-state py-4"><i class="fa-solid fa-boxes-packing"></i><p class="mb-0">Stock levels are healthy.</p></div>
                <?php else: ?>
                    <?php foreach ($lowStock as $item): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between" style="font-size:13px">
                            <b><?= e($item['category_name']) ?></b>
                            <span class="text-muted"><?= (int) $item['available'] ?> / <?= (int) $item['low_stock_threshold'] ?></span>
                        </div>
                        <div class="progress progress-thin mt-2">
                            <div class="progress-bar bg-danger" style="width:<?= min(100, (int) ($item['available'] / max(1, $item['low_stock_threshold']) * 100)) ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ======================= CATEGORY SPLIT ======================= -->
    <div class="col-xl-3">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-layer-group"></i></div>
                <div>
                    <h3 class="mb-0">Equipment groups</h3>
                    <p class="sub mb-0">Distribution by category</p>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-box" style="height:200px"><canvas id="categoryChart"></canvas></div>
                <ul class="chart-legend">
                    <?php foreach ($categoryChart['labels'] as $index => $label): ?>
                    <li><?= e($label) ?> <b>(<?= (int) $categoryChart['values'][$index] ?>)</b></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($retiredAssets > 0): ?>
                <div class="text-muted mt-3" style="font-size:12.2px">
                    <i class="fa-solid fa-archive me-1"></i><?= (int) $retiredAssets ?> retired asset(s) excluded from valuations.
                </div>
                <?php endif; ?>
                <?php if ($warrantyExpiring > 0): ?>
                <div class="text-muted mt-2" style="font-size:12.2px">
                    <i class="fa-regular fa-calendar-xmark me-1"></i><?= (int) $warrantyExpiring ?> warrant(y|ies) expire within 60 days.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script id="dashboardData" type="application/json">
<?= json_encode([
    'status'     => $statusChart,
    'branch'     => $branchChart,
    'department' => $deptChart,
    'category'   => $categoryChart,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
</script>

<?php
$extra_js = ['dashboard.js'];
require __DIR__ . '/includes/footer.php';
