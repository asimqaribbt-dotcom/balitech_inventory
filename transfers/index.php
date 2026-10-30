<?php
/**
 * Asset Transfers - history of asset moves between branches.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('transfers');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

/* ---------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */
$search   = get_param('q');
$fromF    = (int) get_param('from_branch_id', 0);
$toF      = (int) get_param('to_branch_id', 0);
$statusF  = get_param('status');
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');
$page     = max(1, (int) get_param('page', 1));
$perPage  = ITEMS_PER_PAGE;

$transferStatuses = ['pending' => 'Pending', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$statusMap        = ['pending' => 'warning', 'completed' => 'success', 'cancelled' => 'secondary'];

$where  = '1 = 1';
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= ' AND (i.asset_code LIKE :q_code OR i.equipment_name LIKE :q_equip)';
    $params['q_code']  = $like;
    $params['q_equip'] = $like;
}
if ($fromF > 0) {
    $where .= ' AND t.from_branch_id = :f_from';
    $params['f_from'] = $fromF;
}
if ($toF > 0) {
    $where .= ' AND t.to_branch_id = :f_to';
    $params['f_to'] = $toF;
}
if (isset($transferStatuses[$statusF])) {
    $where .= ' AND t.status = :f_status';
    $params['f_status'] = $statusF;
}
if (is_valid_date($dateFrom)) {
    $where .= ' AND t.transfer_date >= :f_date_from';
    $params['f_date_from'] = $dateFrom;
}
if (is_valid_date($dateTo)) {
    $where .= ' AND t.transfer_date <= :f_date_to';
    $params['f_date_to'] = $dateTo;
}

$bindParams = array_merge($params, $scopeParams);

/* ---------------------------------------------------------------------
 * KPI figures
 * ------------------------------------------------------------------ */
$totalTransfers = (int) db_value(
    "SELECT COUNT(*) FROM asset_transfers t JOIN inventory i ON i.id = t.asset_id
      WHERE 1 = 1 $scopeSql", $scopeParams);
$pendingTransfers = (int) db_value(
    "SELECT COUNT(*) FROM asset_transfers t JOIN inventory i ON i.id = t.asset_id
      WHERE t.status = 'pending' $scopeSql", $scopeParams);
$completedTransfers = (int) db_value(
    "SELECT COUNT(*) FROM asset_transfers t JOIN inventory i ON i.id = t.asset_id
      WHERE t.status = 'completed' $scopeSql", $scopeParams);
$monthTransfers = (int) db_value(
    "SELECT COUNT(*) FROM asset_transfers t JOIN inventory i ON i.id = t.asset_id
      WHERE t.transfer_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') $scopeSql", $scopeParams);

$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

/* ---------------------------------------------------------------------
 * Paginated list
 * ------------------------------------------------------------------ */
$countSql = "SELECT COUNT(*)
               FROM asset_transfers t
               JOIN inventory i ON i.id = t.asset_id
              WHERE $where $scopeSql";
$stmt = db()->prepare($countSql);
$stmt->execute($bindParams);
$total = (int) $stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$listSql = "SELECT t.id, t.asset_id, t.transfer_date, t.status, t.reason,
                   i.asset_code, i.equipment_name,
                   fb.branch_name AS from_branch, tb.branch_name AS to_branch,
                   fd.department_name AS from_dept, td.department_name AS to_dept,
                   u.full_name AS transferred_by_name
              FROM asset_transfers t
              JOIN inventory i ON i.id = t.asset_id
              LEFT JOIN branches fb ON fb.id = t.from_branch_id
              LEFT JOIN branches tb ON tb.id = t.to_branch_id
              LEFT JOIN departments fd ON fd.id = t.from_department_id
              LEFT JOIN departments td ON td.id = t.to_department_id
              LEFT JOIN users u ON u.id = t.transferred_by
             WHERE $where $scopeSql
             ORDER BY t.transfer_date DESC, t.id DESC
             LIMIT :limit OFFSET :offset";
$stmt = db()->prepare($listSql);
foreach ($bindParams as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transfers = $stmt->fetchAll();

$hasFilters = count($params) > 0;

$statCards = [
    ['Total transfers', $totalTransfers,     'fa-solid fa-right-left',  'bg-soft-navy'],
    ['Pending',         $pendingTransfers,   'fa-solid fa-hourglass-half', 'bg-soft-amber'],
    ['Completed',       $completedTransfers, 'fa-solid fa-circle-check','bg-soft-green'],
    ['This month',      $monthTransfers,     'fa-regular fa-calendar',  'bg-soft-blue'],
];

$page_title    = 'Asset Transfers';
$page_subtitle = 'Move assets between branches and departments';
$active_menu   = 'transfers';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Transfers</li>
            </ol>
        </nav>
        <h2>Asset transfers</h2>
        <p>Every inter-branch equipment movement, pending or completed.</p>
    </div>
    <div class="page-actions">
        <?php if (can('transfers')): ?>
        <a class="btn btn-brand" href="<?= url('transfers/create.php') ?>">
            <i class="fa-solid fa-plus me-2"></i>New transfer
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($statCards as $card): list($label, $value, $icon, $bg) = $card; ?>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon <?= e($bg) ?>"><i class="<?= e($icon) ?>"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format((float) $value) ?></span>
                <span class="stat-label d-block"><?= e($label) ?></span>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<form class="filter-bar" method="get" action="<?= url('transfers/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>"
                   placeholder="Asset code or equipment">
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">From branch</label>
            <select class="form-select" name="from_branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $branch): ?>
                <option value="<?= (int) $branch['id'] ?>"<?= selected($branch['id'], $fromF) ?>><?= e($branch['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">To branch</label>
            <select class="form-select" name="to_branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $branch): ?>
                <option value="<?= (int) $branch['id'] ?>"<?= selected($branch['id'], $toF) ?>><?= e($branch['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All statuses</option>
                <?php foreach ($transferStatuses as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($key, $statusF) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">From date</label>
            <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-lg-1 col-md-6">
            <label class="form-label">To date</label>
            <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-lg-2 col-md-6 d-flex gap-2">
            <button type="submit" class="btn btn-brand w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <?php if ($hasFilters): ?>
            <a class="btn btn-outline-brand" href="<?= url('transfers/index.php') ?>" title="Reset filters"><i class="fa-solid fa-xmark"></i></a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <div class="stat-icon bg-soft-teal" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-right-left"></i></div>
        <div>
            <h3 class="mb-0">Transfers</h3>
            <p class="sub mb-0"><?= pagination_summary($total, $perPage, $page, 'transfers') ?></p>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Asset</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Transfer date</th>
                    <th>Transferred by</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$transfers): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No transfers found.</td></tr>
            <?php endif; ?>
            <?php foreach ($transfers as $row): ?>
                <tr>
                    <td>
                        <a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $row['asset_id']) ?>"><?= e($row['asset_code']) ?></a>
                        <div class="text-muted-sm"><?= e($row['equipment_name']) ?></div>
                    </td>
                    <td class="text-muted-sm">
                        <div><?= e($row['from_branch'] ?: '-') ?></div>
                        <div><?= e($row['from_dept'] ?: '-') ?></div>
                    </td>
                    <td class="text-muted-sm">
                        <div><?= e($row['to_branch'] ?: '-') ?></div>
                        <div><?= e($row['to_dept'] ?: '-') ?></div>
                    </td>
                    <td class="text-muted-sm"><?= e(fdate($row['transfer_date'])) ?></td>
                    <td class="text-muted-sm"><?= e($row['transferred_by_name'] ?: '-') ?></td>
                    <td><span class="badge badge-soft badge-<?= e($statusMap[$row['status']] ?? 'secondary') ?>"><?= e($transferStatuses[$row['status']] ?? ucfirst((string) $row['status'])) ?></span></td>
                    <td>
                        <div class="table-actions">
                            <?php if ($row['status'] === 'pending' && can('transfers')): ?>
                            <form method="post" action="<?= url('transfers/action.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="transfer_id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-accent" data-confirm="Complete this transfer and move the asset now?">
                                    <i class="fa-solid fa-check me-1"></i>Approve
                                </button>
                            </form>
                            <form method="post" action="<?= url('transfers/action.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="transfer_id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-soft-danger" data-confirm="Cancel this pending transfer?">
                                    <i class="fa-solid fa-ban me-1"></i>Cancel
                                </button>
                            </form>
                            <?php endif; ?>
                            <a class="btn-icon" href="<?= url('inventory/view.php?id=' . (int) $row['asset_id']) ?>" title="View asset">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <?php if (can('transfers')): ?>
                            <form method="post" action="<?= url('transfers/action.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="transfer_id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete record"
                                        data-confirm="Delete this transfer record?">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted-sm"><?= pagination_summary($total, $perPage, $page, 'transfers') ?></span>
        <?= paginate($total, $perPage, $page) ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
