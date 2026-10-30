<?php
/**
 * Stock Management - complete stock transaction history.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('stock');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

/* ---------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */
$typeF    = get_param('transaction_type');
$branchF  = (int) get_param('branch_id', 0);
$catF     = (int) get_param('category_id', 0);
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');
$search   = get_param('q');
$page     = max(1, (int) get_param('page', 1));
$perPage  = ITEMS_PER_PAGE;

$where  = '1 = 1';
$params = [];

if (isset(stock_types()[$typeF])) {
    $where .= ' AND s.transaction_type = :f_type';
    $params['f_type'] = $typeF;
}
if ($branchF > 0) {
    $where .= ' AND s.branch_id = :f_branch';
    $params['f_branch'] = $branchF;
}
if ($catF > 0) {
    $where .= ' AND s.category_id = :f_cat';
    $params['f_cat'] = $catF;
}
if (is_valid_date($dateFrom)) {
    $where .= ' AND s.transaction_date >= :f_from';
    $params['f_from'] = $dateFrom;
}
if (is_valid_date($dateTo)) {
    $where .= ' AND s.transaction_date <= :f_to';
    $params['f_to'] = $dateTo;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= ' AND (s.reference_no LIKE :q_ref
                     OR s.notes LIKE :q_notes
                     OR i.asset_code LIKE :q_code
                     OR i.equipment_name LIKE :q_equip)';
    $params['q_ref']   = $like;
    $params['q_notes'] = $like;
    $params['q_code']  = $like;
    $params['q_equip'] = $like;
}

$bindParams = array_merge($params, $scopeParams);

/* ---------------------------------------------------------------------
 * KPI figures
 * ------------------------------------------------------------------ */
$sumByType = function (string $type) use ($scopeSql, $scopeParams): int {
    return (int) db_value(
        "SELECT COALESCE(SUM(s.quantity), 0)
           FROM stock_transactions s
           LEFT JOIN inventory i ON i.id = s.asset_id
          WHERE s.transaction_type = :kpi_type $scopeSql",
        array_merge(['kpi_type' => $type], $scopeParams)
    );
};

$stockIn  = $sumByType('in');
$stockOut = $sumByType('out');
$transfers = $sumByType('transfer');
$returns    = $sumByType('return');

/* ---------------------------------------------------------------------
 * Lookup lists for the filter bar
 * ------------------------------------------------------------------ */
$branches   = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');
$categories = db_all('SELECT id, category_name FROM asset_categories WHERE status = "active" ORDER BY category_name');

/* ---------------------------------------------------------------------
 * Paginated list
 * ------------------------------------------------------------------ */
$countSql = "SELECT COUNT(*)
               FROM stock_transactions s
               LEFT JOIN inventory i ON i.id = s.asset_id
              WHERE $where $scopeSql";
$stmt = db()->prepare($countSql);
$stmt->execute($bindParams);
$total = (int) $stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$listSql = "SELECT s.id, s.transaction_type, s.quantity, s.reference_no, s.notes,
                   s.transaction_date, s.created_at,
                   i.id AS asset_id, i.asset_code, i.equipment_name,
                   c.category_name, b.branch_name,
                   u.full_name AS performed_by_name
              FROM stock_transactions s
              LEFT JOIN inventory i ON i.id = s.asset_id
              LEFT JOIN asset_categories c ON c.id = s.category_id
              LEFT JOIN branches b ON b.id = s.branch_id
              LEFT JOIN users u ON u.id = s.performed_by
             WHERE $where $scopeSql
             ORDER BY s.transaction_date DESC, s.id DESC
             LIMIT :limit OFFSET :offset";
$stmt = db()->prepare($listSql);
foreach ($bindParams as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll();

$filterCount = count($params);
$hasFilters  = $filterCount > 0;

/* Export link preserves the active filters. */
$exportQuery = http_build_query(array_filter([
    'transaction_type' => isset(stock_types()[$typeF]) ? $typeF : '',
    'branch_id'        => $branchF > 0 ? $branchF : '',
    'category_id'      => $catF > 0 ? $catF : '',
    'date_from'        => is_valid_date($dateFrom) ? $dateFrom : '',
    'date_to'          => is_valid_date($dateTo) ? $dateTo : '',
    'q'                => $search !== '' ? $search : '',
], static fn($v) => $v !== ''));
$exportUrl = url('reports/export.php?report=stock&format=csv' . ($exportQuery !== '' ? '&' . $exportQuery : ''));

$statCards = [
    ['Stock in',   $stockIn,   'fa-solid fa-arrow-down',  'bg-soft-green', '#157f5b'],
    ['Stock out',  $stockOut,  'fa-solid fa-arrow-up',    'bg-soft-red',   '#c1121f'],
    ['Transfers',  $transfers, 'fa-solid fa-right-left',  'bg-soft-blue',  '#1b6ca8'],
    ['Returns',    $returns,   'fa-solid fa-rotate-left', 'bg-soft-amber', '#b7791f'],
];

$page_title    = 'Stock History';
$page_subtitle = 'Every stock movement recorded across the warehouse';
$active_menu   = 'stock';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('stock/index.php') ?>">Stock Management</a></li>
                <li class="breadcrumb-item active">Stock History</li>
            </ol>
        </nav>
        <h2>Stock history</h2>
        <p>Complete log of stock in, stock out, transfers and returns.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= e($exportUrl) ?>"><i class="fa-solid fa-file-csv me-2"></i>Export CSV</a>
        <button type="button" class="btn btn-outline-brand" data-print><i class="fa-solid fa-print me-2"></i>Print</button>
        <a class="btn btn-brand" href="<?= url('stock/in.php') ?>"><i class="fa-solid fa-arrow-down me-2"></i>Stock In</a>
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

<form class="filter-bar" method="get" action="<?= url('stock/history.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>"
                   placeholder="Reference, asset code or notes">
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Type</label>
            <select class="form-select" name="transaction_type">
                <option value="">All types</option>
                <?php foreach (stock_types() as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($key, $typeF) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Branch</label>
            <select class="form-select" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $branch): ?>
                <option value="<?= (int) $branch['id'] ?>"<?= selected($branch['id'], $branchF) ?>><?= e($branch['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Category</label>
            <select class="form-select" name="category_id">
                <option value="">All categories</option>
                <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>"<?= selected($category['id'], $catF) ?>><?= e($category['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 col-md-6">
            <label class="form-label">From</label>
            <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-lg-1 col-md-6">
            <label class="form-label">To</label>
            <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-lg-1 col-md-6 d-flex gap-2">
            <button type="submit" class="btn btn-brand w-100" title="Filter"><i class="fa-solid fa-filter"></i></button>
            <?php if ($hasFilters): ?>
            <a class="btn btn-outline-brand" href="<?= url('stock/history.php') ?>" title="Reset filters"><i class="fa-solid fa-xmark"></i></a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div>
            <h3 class="mb-0">Stock transactions</h3>
            <p class="sub mb-0"><?= pagination_summary($total, $perPage, $page, 'transactions') ?></p>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table" id="stockTable">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Asset</th>
                    <th>Category</th>
                    <th class="text-center">Qty</th>
                    <th>Branch</th>
                    <th>Reference</th>
                    <th>Notes</th>
                    <th>Performed by</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$transactions): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No stock transactions found.</td></tr>
            <?php endif; ?>
            <?php foreach ($transactions as $row): ?>
                <tr>
                    <td class="text-muted-sm"><?= e(fdate($row['transaction_date'])) ?></td>
                    <td><?= stock_badge($row['transaction_type']) ?></td>
                    <td>
                        <?php if ($row['asset_id']): ?>
                            <a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $row['asset_id']) ?>"><?= e($row['asset_code']) ?></a>
                            <div class="text-muted-sm"><?= e($row['equipment_name']) ?></div>
                        <?php else: ?>
                            <span class="text-muted-sm">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted-sm"><?= e($row['category_name'] ?: '-') ?></td>
                    <td class="text-center"><?= (int) $row['quantity'] ?></td>
                    <td class="text-muted-sm"><?= e($row['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['reference_no'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['notes'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($row['performed_by_name'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted-sm"><?= pagination_summary($total, $perPage, $page, 'transactions') ?></span>
        <?= paginate($total, $perPage, $page) ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
