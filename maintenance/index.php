<?php
/**
 * Maintenance - equipment fault register.
 * Lists every maintenance record (scoped to the role) with KPI cards,
 * filters, a status shortcut row and quick actions.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

/* ---------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */
$q        = get_param('q');
$status   = get_param('status');
$type     = get_param('type');
$branchId = (int) get_param('branch_id', 0);
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');
$page     = max(1, (int) get_param('page', 1));
$perPage  = ITEMS_PER_PAGE;

$where  = ' WHERE 1 = 1 ' . $scopeSql;
$params = $scopeParams;

if ($q !== '') {
    $where .= ' AND (m.issue_title LIKE :kw1 OR i.asset_code LIKE :kw2 OR m.technician_name LIKE :kw3)';
    $like = '%' . $q . '%';
    $params['kw1'] = $like;
    $params['kw2'] = $like;
    $params['kw3'] = $like;
}
if ($status !== '' && isset(maintenance_statuses()[$status])) {
    $where .= ' AND m.status = :mstatus';
    $params['mstatus'] = $status;
}
if ($type !== '' && isset(maintenance_types()[$type])) {
    $where .= ' AND m.maintenance_type = :mtype';
    $params['mtype'] = $type;
}
if ($branchId > 0) {
    $where .= ' AND i.branch_id = :fbranch';
    $params['fbranch'] = $branchId;
}
if (is_valid_date($dateFrom)) {
    $where .= ' AND m.reported_date >= :dfrom';
    $params['dfrom'] = $dateFrom;
}
if (is_valid_date($dateTo)) {
    $where .= ' AND m.reported_date <= :dto';
    $params['dto'] = $dateTo;
}

/* ---------------------------------------------------------------------
 * Pagination + data
 * ------------------------------------------------------------------ */
$total = (int) db_value(
    "SELECT COUNT(*) FROM maintenance_records m JOIN inventory i ON i.id = m.asset_id $where",
    $params
);

$sortMap = ['date' => 'm.reported_date', 'cost' => 'm.cost', 'status' => 'm.status', 'id' => 'm.id'];
$sort    = get_param('sort', 'date');
$sortCol = $sortMap[$sort] ?? 'm.reported_date';
$dir     = strtolower(get_param('dir')) === 'asc' ? 'ASC' : 'DESC';

$sql = "SELECT m.*, i.asset_code, i.equipment_name, i.branch_id, b.branch_name
          FROM maintenance_records m
          JOIN inventory i ON i.id = m.asset_id
          LEFT JOIN branches b ON b.id = i.branch_id
         $where
         ORDER BY $sortCol $dir, m.id DESC
         LIMIT :lim OFFSET :off";
$stmt = db()->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();

/* ---------------------------------------------------------------------
 * KPI figures
 * ------------------------------------------------------------------ */
$statusCounts = [];
foreach (db_all(
        "SELECT m.status, COUNT(*) AS total
           FROM maintenance_records m
           JOIN inventory i ON i.id = m.asset_id
          WHERE 1 = 1 $scopeSql
          GROUP BY m.status", $scopeParams) as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}

$reportedCount     = $statusCounts['reported'] ?? 0;
$progressCount     = $statusCounts['in_progress'] ?? 0;
$completedCount    = $statusCounts['completed'] ?? 0;
$unrepairableCount = $statusCounts['unrepairable'] ?? 0;
$totalRecords      = array_sum($statusCounts);

$totalCost = (float) db_value(
    "SELECT COALESCE(SUM(m.cost), 0) FROM maintenance_records m JOIN inventory i ON i.id = m.asset_id WHERE 1 = 1 $scopeSql",
    $scopeParams
);
$avgCost = (float) db_value(
    "SELECT COALESCE(AVG(m.cost), 0) FROM maintenance_records m JOIN inventory i ON i.id = m.asset_id WHERE m.cost > 0 $scopeSql",
    $scopeParams
);

$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$kpiCards = [
    ['Reported',      $reportedCount,     'fa-solid fa-triangle-exclamation', 'bg-soft-amber'],
    ['In Progress',   $progressCount,     'fa-solid fa-screwdriver-wrench',   'bg-soft-blue'],
    ['Completed',     $completedCount,    'fa-solid fa-circle-check',         'bg-soft-green'],
    ['Unrepairable',  $unrepairableCount, 'fa-solid fa-ban',                  'bg-soft-red'],
    ['Total Records', $totalRecords,      'fa-solid fa-clipboard-list',       'bg-soft-navy'],
];

$page_title    = 'Maintenance';
$page_subtitle = 'Track equipment faults, repairs and maintenance cost';
$active_menu   = 'maintenance';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Maintenance</li>
            </ol>
        </nav>
        <h2>Maintenance register</h2>
        <p><?= number_format($totalRecords) ?> record(s) &middot; total cost <?= e(money($totalCost)) ?></p>
    </div>
    <div class="page-actions">
        <?php if (can('maintenance')): ?>
        <a class="btn btn-brand" href="<?= url('maintenance/create.php') ?>"><i class="fa-solid fa-plus me-2"></i>Report equipment fault</a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================ KPI CARDS ============================ -->
<div class="row g-3 mb-4">
    <?php foreach ($kpiCards as $card): list($label, $value, $icon, $bg) = $card; ?>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon <?= e($bg) ?>"><i class="<?= e($icon) ?>"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format((float) $value) ?></span>
                <span class="stat-label d-block"><?= e($label) ?></span>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-teal"><i class="fa-solid fa-sack-dollar"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= e(money($totalCost, false)) ?></span>
                <span class="stat-label d-block">Total cost (PKR)</span>
            </span>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-purple"><i class="fa-solid fa-calculator"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= e(money($avgCost, false)) ?></span>
                <span class="stat-label d-block">Avg repair cost</span>
            </span>
        </div>
    </div>
</div>

<!-- ========================== STATUS SHORTCUT ======================= -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm <?= $status === '' ? 'btn-brand' : 'btn-outline-brand' ?>" href="<?= url('maintenance/index.php') ?>">All</a>
    <?php foreach (maintenance_statuses() as $key => $label): ?>
    <a class="btn btn-sm <?= $status === $key ? 'btn-brand' : 'btn-outline-brand' ?>"
       href="<?= url('maintenance/index.php?status=' . urlencode($key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<!-- ============================ FILTERS ============================= -->
<form class="filter-bar" method="get" action="<?= url('maintenance/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>"
                   placeholder="Issue title, asset code or technician">
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach (maintenance_statuses() as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($key, $status) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="type">Type</label>
            <select class="form-select" id="type" name="type">
                <option value="">All types</option>
                <?php foreach (maintenance_types() as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($key, $type) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label" for="branch_id">Branch</label>
            <select class="form-select" id="branch_id" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $branch): ?>
                <option value="<?= (int) $branch['id'] ?>"<?= selected($branch['id'], $branchId) ?>><?= e($branch['branch_name']) ?></option>
                <?php endforeach; ?>
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
            <a class="btn btn-outline-brand" href="<?= url('maintenance/index.php') ?>" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </div>
</form>

<!-- ============================= TABLE ============================== -->
<div class="card">
    <div class="card-header">
        <div class="stat-icon bg-soft-amber" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-screwdriver-wrench"></i></div>
        <div>
            <h3 class="mb-0">Maintenance records</h3>
            <p class="sub mb-0"><?= pagination_summary($total, $perPage, $page, 'records') ?></p>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Record #</th>
                    <th>Asset</th>
                    <th>Issue</th>
                    <th>Type</th>
                    <th>Technician</th>
                    <th>Reported</th>
                    <th>Repair Date</th>
                    <th class="text-end">Cost</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr class="no-filter"><td colspan="10">
                    <div class="empty-state">
                        <i class="fa-solid fa-screwdriver-wrench"></i>
                        <h4>No maintenance records found</h4>
                        <p>Adjust the filters or report a new equipment fault.</p>
                        <?php if (can('maintenance')): ?>
                        <a class="btn btn-brand" href="<?= url('maintenance/create.php') ?>"><i class="fa-solid fa-plus me-2"></i>Report equipment fault</a>
                        <?php endif; ?>
                    </div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($records as $record): ?>
                <tr>
                    <td><span class="fw-code">#<?= (int) $record['id'] ?></span></td>
                    <td>
                        <a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $record['asset_id']) ?>"><?= e($record['asset_code']) ?></a>
                        <div class="text-muted-sm"><?= e($record['equipment_name']) ?></div>
                    </td>
                    <td>
                        <div><?= e($record['issue_title']) ?></div>
                        <div class="text-muted-sm"><?= e($record['branch_name'] ?: '-') ?></div>
                    </td>
                    <td><?= e(maintenance_types()[$record['maintenance_type']] ?? $record['maintenance_type']) ?></td>
                    <td class="text-muted-sm"><?= e($record['technician_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e(fdate($record['reported_date'])) ?></td>
                    <td class="text-muted-sm"><?= e(fdate($record['repair_date'])) ?></td>
                    <td class="text-end"><?= e(money($record['cost'])) ?></td>
                    <td><?= maintenance_badge($record['status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn-icon" href="<?= url('maintenance/view.php?id=' . (int) $record['id']) ?>" title="View"><i class="fa-regular fa-eye"></i></a>
                            <?php if (can('maintenance')): ?>
                            <a class="btn-icon" href="<?= url('maintenance/edit.php?id=' . (int) $record['id']) ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <?php if ($record['status'] !== 'completed'): ?>
                            <form method="post" action="<?= url('maintenance/edit.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                <input type="hidden" name="quick" value="1">
                                <input type="hidden" name="status" value="completed">
                                <input type="hidden" name="repair_date" value="<?= e(date('Y-m-d')) ?>">
                                <input type="hidden" name="cost" value="<?= e($record['cost']) ?>">
                                <button class="btn-icon" type="submit" title="Mark completed"
                                        data-confirm="Mark record #<?= (int) $record['id'] ?> as completed?"><i class="fa-solid fa-circle-check"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="<?= url('maintenance/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                <button class="btn-icon danger" type="submit" title="Delete"
                                        data-confirm="Delete maintenance record #<?= (int) $record['id'] ?>? This cannot be undone."><i class="fa-regular fa-trash-can"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > $perPage): ?>
    <div class="card-footer d-flex justify-content-center">
        <?= paginate($total, $perPage, $page) ?>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
