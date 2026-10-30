<?php
/**
 * Branches - single branch profile with inventory, departments, employees
 * and equipment statistics tabs.
 */
require_once __DIR__ . '/../init.php';
require_login();

$user = current_user();
$id = (int) get_param('id');

$branch = db_row('SELECT * FROM branches WHERE id = ?', [$id]);
if (!$branch) {
    set_flash('danger', 'The requested branch could not be found.');
    redirect('branches/index.php');
}

// Branch IT Staff may only view their own branch
if (is_role('branch_staff') && (int) $branch['id'] !== (int) ($user['branch_id'] ?? 0)) {
    deny_access('You may only view your own branch.');
}

$canWrite = can('branches');

/* ---------------------------------------------------------------------
 * Branch asset statistics
 * ------------------------------------------------------------------ */
$assetTotal   = (int) db_value('SELECT COUNT(*) FROM inventory WHERE branch_id = ?', [$id]);
$assetAssigned = (int) db_value("SELECT COUNT(*) FROM inventory WHERE branch_id = ? AND status = 'assigned'", [$id]);
$assetAvailable = (int) db_value("SELECT COUNT(*) FROM inventory WHERE branch_id = ? AND status = 'available'", [$id]);
$assetMaintenance = (int) db_value("SELECT COUNT(*) FROM inventory WHERE branch_id = ? AND status = 'under_maintenance'", [$id]);

/* ---------------------------------------------------------------------
 * Tab data
 * ------------------------------------------------------------------ */
$tab = get_param('tab', 'inventory');
if (!in_array($tab, ['inventory', 'departments', 'employees', 'statistics'], true)) {
    $tab = 'inventory';
}

// Inventory (paginated)
$perPage = ITEMS_PER_PAGE;
$invTotal = (int) db_value('SELECT COUNT(*) FROM inventory WHERE branch_id = ?', [$id]);
$page = max(1, (int) get_param('page', 1));
$page = min($page, max(1, (int) ceil($invTotal / $perPage)));
$offset = ($page - 1) * $perPage;

$inventory = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.status, c.category_name, e.employee_name
       FROM inventory i
       LEFT JOIN asset_categories c ON c.id = i.category_id
       LEFT JOIN employees e ON e.id = i.assigned_employee_id
      WHERE i.branch_id = ?
      ORDER BY i.asset_code ASC
      LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    [$id]
);

// Departments of the branch
$departments = db_all(
    'SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id) AS emp_count
       FROM departments d
      WHERE d.branch_id = ?
      ORDER BY d.department_name ASC',
    [$id]
);

// Employees of the branch
$employees = db_all(
    'SELECT e.*, d.department_name
       FROM employees e
       LEFT JOIN departments d ON d.id = e.department_id
      WHERE e.branch_id = ?
      ORDER BY e.employee_name ASC',
    [$id]
);

// Equipment statistics by parent category group
$stats = db_all(
    'SELECT p.id, p.category_name, COUNT(i.id) AS total
       FROM asset_categories p
       LEFT JOIN asset_categories c ON c.parent_id = p.id
       LEFT JOIN inventory i ON i.category_id = c.id AND i.branch_id = ?
      WHERE p.parent_id IS NULL
      GROUP BY p.id, p.category_name
      ORDER BY total DESC',
    [$id]
);
$statsMax = 0;
foreach ($stats as $s) {
    $statsMax = max($statsMax, (int) $s['total']);
}

$page_title    = 'Branch: ' . $branch['branch_name'];
$page_subtitle = $branch['branch_code'] . ' · ' . $branch['city'];
$active_menu   = 'branches';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('branches/index.php') ?>">Branches</a></li>
                <li class="breadcrumb-item active"><?= e($branch['branch_code']) ?></li>
            </ol>
        </nav>
        <h2><?= e($branch['branch_name']) ?></h2>
        <p><?= e($branch['city']) ?> &middot; <?= e($branch['branch_manager'] ?: 'No manager assigned') ?></p>
    </div>
    <div class="page-actions">
        <?php if ($canWrite): ?>
        <a class="btn btn-brand" href="<?= url('branches/edit.php?id=' . $id) ?>"><i class="fa-solid fa-pen me-2"></i>Edit Branch</a>
        <?php endif; ?>
        <a class="btn btn-outline-brand" href="<?= url('branches/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to list</a>
    </div>
</div>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-navy"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($assetTotal) ?></span>
                <span class="stat-label d-block">Total Assets</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-user-check"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($assetAssigned) ?></span>
                <span class="stat-label d-block">Assigned</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-green"><i class="fa-solid fa-warehouse"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($assetAvailable) ?></span>
                <span class="stat-label d-block">Available</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-amber"><i class="fa-solid fa-screwdriver-wrench"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($assetMaintenance) ?></span>
                <span class="stat-label d-block">Under Maintenance</span>
            </span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Branch detail card -->
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-gray" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-circle-info"></i></div>
                <div><h3 class="mb-0">Branch profile</h3><p class="sub mb-0">Registered details</p></div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Branch Code</span><span class="v"><?= e($branch['branch_code']) ?></span></li>
                    <li><span class="k">Branch Name</span><span class="v"><?= e($branch['branch_name']) ?></span></li>
                    <li><span class="k">City</span><span class="v"><?= e($branch['city']) ?></span></li>
                    <li><span class="k">Manager</span><span class="v"><?= e($branch['branch_manager'] ?: '-') ?></span></li>
                    <li><span class="k">Contact</span><span class="v"><?= e($branch['contact_number'] ?: '-') ?></span></li>
                    <li><span class="k">Email</span><span class="v"><?= e($branch['email'] ?: '-') ?></span></li>
                    <li><span class="k">Address</span><span class="v"><?= e($branch['address'] ?: '-') ?></span></li>
                    <li><span class="k">Status</span><span class="v"><?= active_badge($branch['status']) ?></span></li>
                    <li><span class="k">Created</span><span class="v"><?= e(fdate($branch['created_at'])) ?></span></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Equipment statistics -->
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-chart-simple"></i></div>
                <div><h3 class="mb-0">Branch-wise equipment statistics</h3><p class="sub mb-0">Assets per equipment group</p></div>
            </div>
            <div class="card-body">
                <?php if (!$stats): ?>
                    <div class="empty-state py-4"><i class="fa-solid fa-inbox"></i><p class="mb-0">No equipment recorded for this branch.</p></div>
                <?php else: ?>
                    <?php foreach ($stats as $s): ?>
                    <?php $pct = $statsMax > 0 ? (int) round(((int) $s['total'] / $statsMax) * 100) : 0; ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between" style="font-size:13px">
                            <b><?= e($s['category_name']) ?></b>
                            <span class="text-muted"><?= (int) $s['total'] ?> asset(s)</span>
                        </div>
                        <div class="progress progress-thin mt-2">
                            <div class="progress-bar" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================== TABS ============================== -->
<div class="card">
    <div class="card-header pb-0">
        <ul class="nav nav-tabs w-100" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $tab === 'inventory' ? ' active' : '' ?>" href="<?= url('branches/view.php?id=' . $id . '&tab=inventory') ?>">
                    <i class="fa-solid fa-boxes-stacked me-1"></i>Inventory (<?= (int) $invTotal ?>)
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $tab === 'departments' ? ' active' : '' ?>" href="<?= url('branches/view.php?id=' . $id . '&tab=departments') ?>">
                    <i class="fa-solid fa-sitemap me-1"></i>Departments (<?= count($departments) ?>)
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $tab === 'employees' ? ' active' : '' ?>" href="<?= url('branches/view.php?id=' . $id . '&tab=employees') ?>">
                    <i class="fa-solid fa-users me-1"></i>Employees (<?= count($employees) ?>)
                </a>
            </li>
        </ul>
    </div>

    <?php if ($tab === 'inventory'): ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Asset Code</th><th>Equipment</th><th>Category</th><th>Assigned To</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php if (!$inventory): ?>
                <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No assets in this branch.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($inventory as $a): ?>
                <tr>
                    <td><a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $a['id']) ?>"><?= e($a['asset_code']) ?></a></td>
                    <td><?= e($a['equipment_name']) ?></td>
                    <td class="text-muted-sm"><?= e($a['category_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($a['employee_name'] ?: '-') ?></td>
                    <td><?= status_badge($a['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted" style="font-size:12.5px"><?= pagination_summary($invTotal, $perPage, $page, 'assets') ?></div>
        <?= paginate($invTotal, $perPage, $page) ?>
    </div>

    <?php elseif ($tab === 'departments'): ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Code</th><th>Department</th><th>Head</th><th class="text-center">Employees</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php if (!$departments): ?>
                <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No departments in this branch.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($departments as $d): ?>
                <tr>
                    <td><a class="fw-code" href="<?= url('departments/view.php?id=' . (int) $d['id']) ?>"><?= e($d['dept_code']) ?></a></td>
                    <td><?= e($d['department_name']) ?></td>
                    <td class="text-muted-sm"><?= e($d['dept_head'] ?: '-') ?></td>
                    <td class="text-center"><?= (int) $d['emp_count'] ?></td>
                    <td><?= active_badge($d['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Code</th><th>Employee</th><th>Department</th><th>Designation</th><th>Contact</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php if (!$employees): ?>
                <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No employees in this branch.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($employees as $emp): ?>
                <tr>
                    <td><a class="fw-code" href="<?= url('employees/view.php?id=' . (int) $emp['id']) ?>"><?= e($emp['emp_code']) ?></a></td>
                    <td><?= e($emp['employee_name']) ?></td>
                    <td class="text-muted-sm"><?= e($emp['department_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($emp['designation'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($emp['contact_number'] ?: '-') ?></td>
                    <td><?= active_badge($emp['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
