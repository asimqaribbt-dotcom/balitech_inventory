<?php
/**
 * Branches - list, filter and export all company branches.
 * Readable by every signed in user; write actions need the "branches" ability.
 */
require_once __DIR__ . '/../init.php';
require_login();

$user = current_user();

/* ---------------------------------------------------------------------
 * Filters (kept in the query string so pagination preserves them)
 * ------------------------------------------------------------------ */
$search       = get_param('q');
$cityFilter   = get_param('city');
$statusFilter = get_param('status');
if (!in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

$where  = ' WHERE 1 = 1';
$params = [];

if ($search !== '') {
    $where .= ' AND (b.branch_name LIKE ? OR b.branch_code LIKE ? OR b.city LIKE ? OR b.branch_manager LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($cityFilter !== '') {
    $where .= ' AND b.city = ?';
    $params[] = $cityFilter;
}
if ($statusFilter !== '') {
    $where .= ' AND b.status = ?';
    $params[] = $statusFilter;
}

// Branch IT Staff may only see their own branch
$scopedToOwnBranch = is_role('branch_staff') && !empty($user['branch_id']);
if ($scopedToOwnBranch) {
    $where .= ' AND b.id = ?';
    $params[] = (int) $user['branch_id'];
}

/* ---------------------------------------------------------------------
 * CSV export (self contained, streams before any HTML is sent)
 * ------------------------------------------------------------------ */
if (get_param('export') === 'csv') {
    $rows = db_all(
        "SELECT b.branch_code, b.branch_name, b.city, b.branch_manager, b.contact_number, b.email, b.status
           FROM branches b $where ORDER BY b.branch_name", $params);
    $data = [];
    foreach ($rows as $r) {
        $data[] = [$r['branch_code'], $r['branch_name'], $r['city'], $r['branch_manager'], $r['contact_number'], $r['email'], $r['status']];
    }
    log_activity('Branch', 'export', null, 'Exported branch list (CSV)');
    csv_download('branches-' . date('Ymd-His') . '.csv',
        ['Code', 'Branch', 'City', 'Manager', 'Contact', 'Email', 'Status'], $data);
}

/* ---------------------------------------------------------------------
 * Pagination + list query (single aggregated query)
 * ------------------------------------------------------------------ */
$perPage = ITEMS_PER_PAGE;
$total   = (int) db_value("SELECT COUNT(*) FROM branches b $where", $params);
$page    = max(1, (int) get_param('page', 1));
$page    = min($page, max(1, (int) ceil($total / $perPage)));
$offset  = ($page - 1) * $perPage;

$branches = db_all(
    "SELECT b.*,
            COUNT(DISTINCT d.id) AS dept_count,
            COUNT(DISTINCT e.id) AS emp_count,
            COUNT(DISTINCT i.id) AS asset_count
       FROM branches b
       LEFT JOIN departments d ON d.branch_id = b.id
       LEFT JOIN employees   e ON e.branch_id = b.id
       LEFT JOIN inventory   i ON i.branch_id = b.id
       $where
      GROUP BY b.id
      ORDER BY b.branch_name ASC
      LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    $params
);

/* ---------------------------------------------------------------------
 * Summary figures
 * ------------------------------------------------------------------ */
$activeBranches = (int) db_value("SELECT COUNT(*) FROM branches b $where AND b.status = 'active'", $params);
$totalAssets    = (int) db_value("SELECT COUNT(*) FROM inventory i JOIN branches b ON b.id = i.branch_id $where", $params);
$totalEmployees = (int) db_value("SELECT COUNT(*) FROM employees e JOIN branches b ON b.id = e.branch_id $where", $params);

$cities = db_all("SELECT DISTINCT city FROM branches WHERE city <> '' ORDER BY city");

$exportQuery = $_GET;
unset($exportQuery['page']);
$exportQuery['export'] = 'csv';
$exportUrl = url('branches/index.php?' . http_build_query($exportQuery));

$page_title    = 'Branches';
$page_subtitle = 'Company locations and their asset footprint';
$active_menu   = 'branches';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Branches</li>
            </ol>
        </nav>
        <h2>Branches</h2>
        <p><?= e(APP_COMPANY) ?> &middot; <?= (int) $total ?> branch(es) listed</p>
    </div>
    <div class="page-actions">
        <?php if (can('branches')): ?>
        <a class="btn btn-brand" href="<?= url('branches/create.php') ?>"><i class="fa-solid fa-plus me-2"></i>Add Branch</a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-navy"><i class="fa-solid fa-building"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($total) ?></span>
                <span class="stat-label d-block">Total Branches</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-green"><i class="fa-solid fa-circle-check"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($activeBranches) ?></span>
                <span class="stat-label d-block">Active Branches</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($totalAssets) ?></span>
                <span class="stat-label d-block">Total Assets</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-purple"><i class="fa-solid fa-users"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($totalEmployees) ?></span>
                <span class="stat-label d-block">Total Employees</span>
            </span>
        </div>
    </div>
</div>

<!-- ============================ FILTER BAR ============================ -->
<form class="filter-bar" method="get" action="<?= url('branches/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-4 col-md-6">
            <label class="form-label">Search</label>
            <input type="search" class="form-control" name="q" value="<?= e($search) ?>"
                   placeholder="Name, code, city or manager">
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="form-label">City</label>
            <select class="form-select" name="city">
                <option value="">All cities</option>
                <?php foreach ($cities as $c): ?>
                <option value="<?= e($c['city']) ?>"<?= selected($c['city'], $cityFilter) ?>><?= e($c['city']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All</option>
                <option value="active"<?= selected('active', $statusFilter) ?>>Active</option>
                <option value="inactive"<?= selected('inactive', $statusFilter) ?>>Inactive</option>
            </select>
        </div>
        <div class="col-lg-3 col-md-6">
            <button type="submit" class="btn btn-brand btn-sm"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <a class="btn btn-outline-brand btn-sm" href="<?= url('branches/index.php') ?>">Reset</a>
        </div>
    </div>
</form>

<!-- ============================== TABLE ============================== -->
<div class="card">
    <div class="table-toolbar">
        <div class="left">
            <div class="input-group" style="max-width:280px">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" class="form-control form-control-sm" placeholder="Filter this table..." data-table-filter="#branchesTable">
            </div>
        </div>
        <div class="right">
            <a class="btn btn-outline-brand btn-sm" href="<?= e($exportUrl) ?>"><i class="fa-solid fa-file-csv me-1"></i>Export</a>
            <button type="button" class="btn btn-outline-brand btn-sm" data-print><i class="fa-solid fa-print me-1"></i>Print</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table" id="branchesTable">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>City</th>
                    <th>Manager</th>
                    <th>Contact</th>
                    <th class="text-center">Departments</th>
                    <th class="text-center">Employees</th>
                    <th class="text-center">Assets</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$branches): ?>
                <tr class="no-filter"><td colspan="9">
                    <div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No branches found.</p></div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($branches as $b): ?>
                <tr>
                    <td>
                        <a class="fw-code" href="<?= url('branches/view.php?id=' . (int) $b['id']) ?>"><?= e($b['branch_code']) ?></a>
                        <div><?= e($b['branch_name']) ?></div>
                    </td>
                    <td class="text-muted-sm"><?= e($b['city']) ?></td>
                    <td class="text-muted-sm"><?= e($b['branch_manager'] ?: '-') ?></td>
                    <td class="text-muted-sm">
                        <?= e($b['contact_number'] ?: '-') ?>
                        <?php if ($b['email']): ?><div><?= e($b['email']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int) $b['dept_count'] ?></td>
                    <td class="text-center"><?= (int) $b['emp_count'] ?></td>
                    <td class="text-center"><?= (int) $b['asset_count'] ?></td>
                    <td><?= active_badge($b['status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn-icon" title="View" href="<?= url('branches/view.php?id=' . (int) $b['id']) ?>"><i class="fa-solid fa-eye"></i></a>
                            <?php if (can('branches')): ?>
                            <a class="btn-icon" title="Edit" href="<?= url('branches/edit.php?id=' . (int) $b['id']) ?>"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" action="<?= url('branches/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete"
                                        data-confirm="Delete branch &quot;<?= e($b['branch_name']) ?>&quot;?"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted" style="font-size:12.5px"><?= pagination_summary($total, $perPage, $page, 'branches') ?></div>
        <?= paginate($total, $perPage, $page) ?>
    </div>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
