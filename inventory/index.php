<?php
/**
 * Inventory - "All Assets" listing.
 * Read-only page: every signed in user may open it, but the write actions
 * (edit / delete / status change) are only rendered for can('inventory').
 * Data scope (branch / department) is applied to every query.
 */
require_once __DIR__ . '/../init.php';
require_login();

$user        = current_user();
$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

/* ---------------------------------------------------------------------
 * Statistic cards (respect the data scope)
 * ------------------------------------------------------------------ */
$statusCounts = [];
foreach (db_all("SELECT i.status, COUNT(*) AS total FROM inventory i WHERE 1 = 1 $scopeSql GROUP BY i.status", $scopeParams) as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}
$totalAssets = array_sum($statusCounts);
$countStatus = static fn(string $status): int => $statusCounts[$status] ?? 0;

/* ---------------------------------------------------------------------
 * Filters (GET)
 * ------------------------------------------------------------------ */
$filters = [
    'q'                  => get_param('q'),
    'branch_id'          => get_param('branch_id'),
    'department_id'      => get_param('department_id'),
    'category_id'        => get_param('category_id'),
    'brand_id'           => get_param('brand_id'),
    'status'             => get_param('status'),
    'asset_condition'    => get_param('asset_condition'),
    'purchase_date_from' => get_param('purchase_date_from'),
    'purchase_date_to'   => get_param('purchase_date_to'),
];

$where  = '1 = 1';
$params = [];

if ($filters['q'] !== '') {
    $term = '%' . $filters['q'] . '%';
    $where .= ' AND (i.asset_code LIKE :q1 OR i.equipment_name LIKE :q2 OR i.serial_number LIKE :q3'
            . ' OR i.model_number LIKE :q4 OR b.brand_name LIKE :q5 OR emp.employee_name LIKE :q6'
            . ' OR br.branch_name LIKE :q7 OR d.department_name LIKE :q8)';
    foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8'] as $key) {
        $params[$key] = $term;
    }
}
if ($filters['branch_id'] !== '') {
    $where .= ' AND i.branch_id = :f_branch';
    $params['f_branch'] = (int) $filters['branch_id'];
}
if ($filters['department_id'] !== '') {
    $where .= ' AND i.department_id = :f_dept';
    $params['f_dept'] = (int) $filters['department_id'];
}
if ($filters['category_id'] !== '') {
    $where .= ' AND i.category_id = :f_cat';
    $params['f_cat'] = (int) $filters['category_id'];
}
if ($filters['brand_id'] !== '') {
    $where .= ' AND i.brand_id = :f_brand';
    $params['f_brand'] = (int) $filters['brand_id'];
}
if ($filters['status'] !== '' && isset(asset_statuses()[$filters['status']])) {
    $where .= ' AND i.status = :f_status';
    $params['f_status'] = $filters['status'];
}
if ($filters['asset_condition'] !== '' && isset(asset_conditions()[$filters['asset_condition']])) {
    $where .= ' AND i.asset_condition = :f_cond';
    $params['f_cond'] = $filters['asset_condition'];
}
if ($filters['purchase_date_from'] !== '' && is_valid_date($filters['purchase_date_from'])) {
    $where .= ' AND i.purchase_date >= :f_from';
    $params['f_from'] = $filters['purchase_date_from'];
}
if ($filters['purchase_date_to'] !== '' && is_valid_date($filters['purchase_date_to'])) {
    $where .= ' AND i.purchase_date <= :f_to';
    $params['f_to'] = $filters['purchase_date_to'];
}

// Data scope (branch / department) - uses named placeholders as well
$where .= $scopeSql;
$params = array_merge($params, $scopeParams);

/* ---------------------------------------------------------------------
 * Sorting (whitelisted)
 * ------------------------------------------------------------------ */
$sortMap = [
    'asset_code'     => 'i.asset_code',
    'equipment_name' => 'i.equipment_name',
    'purchase_date'  => 'i.purchase_date',
    'status'         => 'i.status',
];
$sort = get_param('sort', 'asset_code');
if (!isset($sortMap[$sort])) {
    $sort = 'asset_code';
}
$dir = strtolower(get_param('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
$orderSql = $sortMap[$sort] . ' ' . strtoupper($dir) . ', i.id DESC';

$sortUrl = static function (string $col) use ($sort, $dir): string {
    $query = $_GET;
    $query['sort'] = $col;
    $query['dir']  = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    unset($query['page']);
    return url('inventory/index.php') . '?' . http_build_query($query);
};
$sortIcon = static function (string $col) use ($sort, $dir): string {
    if ($sort !== $col) {
        return '<i class="fa-solid fa-sort ms-1" style="opacity:.35"></i>';
    }
    return $dir === 'asc'
        ? '<i class="fa-solid fa-sort-up ms-1"></i>'
        : '<i class="fa-solid fa-sort-down ms-1"></i>';
};

/* ---------------------------------------------------------------------
 * Pagination
 * ------------------------------------------------------------------ */
$total = (int) db_value(
    "SELECT COUNT(*)
       FROM inventory i
       LEFT JOIN asset_categories c ON c.id = i.category_id
       LEFT JOIN brands b ON b.id = i.brand_id
       LEFT JOIN branches br ON br.id = i.branch_id
       LEFT JOIN departments d ON d.id = i.department_id
       LEFT JOIN employees emp ON emp.id = i.assigned_employee_id
      WHERE $where", $params
);

$page    = max(1, (int) get_param('page', 1));
$perPage = ITEMS_PER_PAGE;
$pages   = max(1, (int) ceil($total / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;

$sql = "SELECT i.*, c.category_name, pc.category_name AS group_name, b.brand_name,
               br.branch_name, d.department_name, emp.employee_name
          FROM inventory i
          LEFT JOIN asset_categories c ON c.id = i.category_id
          LEFT JOIN asset_categories pc ON pc.id = c.parent_id
          LEFT JOIN brands b ON b.id = i.brand_id
          LEFT JOIN branches br ON br.id = i.branch_id
          LEFT JOIN departments d ON d.id = i.department_id
          LEFT JOIN employees emp ON emp.id = i.assigned_employee_id
         WHERE $where
         ORDER BY $orderSql
         LIMIT :limit OFFSET :offset";

$stmt = db()->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$assets = $stmt->fetchAll();

/* ---------------------------------------------------------------------
 * Filter option lists
 * ------------------------------------------------------------------ */
$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');
$brands   = db_all('SELECT id, brand_name FROM brands WHERE status = "active" ORDER BY brand_name');

$catRows = db_all('SELECT id, category_name, parent_id FROM asset_categories WHERE status = "active" ORDER BY category_name');
$catTree = [];
$catLeaves = [];
foreach ($catRows as $cat) {
    if ($cat['parent_id'] === null) {
        $catTree[(int) $cat['id']] = ['name' => $cat['category_name'], 'children' => []];
    } else {
        $catLeaves[] = $cat;
    }
}
foreach ($catLeaves as $cat) {
    $parentKey = (int) $cat['parent_id'];
    if (isset($catTree[$parentKey])) {
        $catTree[$parentKey]['children'][] = $cat;
    }
}

// Export link reuses the current filters
$exportQuery = $_GET;
unset($exportQuery['page']);
$exportUrl = url('reports/export.php') . '?' . http_build_query(array_merge($exportQuery, [
    'report' => 'inventory',
    'format' => 'csv',
]));

$cards = [
    ['Total Assets',      $totalAssets,                'fa-solid fa-boxes-stacked',         'bg-soft-navy',  url('inventory/index.php')],
    ['Assigned',          $countStatus('assigned'),    'fa-solid fa-user-check',            'bg-soft-blue',  url('inventory/index.php?status=assigned')],
    ['Available',         $countStatus('available'),   'fa-solid fa-warehouse',             'bg-soft-green', url('inventory/index.php?status=available')],
    ['Under Maintenance', $countStatus('under_maintenance'), 'fa-solid fa-screwdriver-wrench', 'bg-soft-amber', url('inventory/index.php?status=under_maintenance')],
    ['Damaged',           $countStatus('damaged'),     'fa-solid fa-triangle-exclamation',  'bg-soft-red',   url('inventory/index.php?status=damaged')],
];

$page_title    = 'All Assets';
$page_subtitle = 'Complete IT asset register';
$active_menu   = 'inventory';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Inventory</li>
            </ol>
        </nav>
        <h2>All Assets</h2>
        <p><?= e(APP_COMPANY) ?> &middot; <?= (int) $total ?> asset(s) in scope</p>
    </div>
    <div class="page-actions">
        <?php if (can('inventory')): ?>
        <a class="btn btn-brand" href="<?= url('inventory/add.php') ?>"><i class="fa-solid fa-plus me-2"></i>Add New Asset</a>
        <?php endif; ?>
        <a class="btn btn-outline-brand" href="<?= e($exportUrl) ?>"><i class="fa-solid fa-file-csv me-2"></i>Export CSV</a>
        <button type="button" class="btn btn-outline-brand" data-print><i class="fa-solid fa-print me-2"></i>Print</button>
    </div>
</div>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <?php foreach ($cards as $card): ?>
    <div class="col-md-6 col-xl">
        <a class="stat-card" href="<?= e($card[4]) ?>">
            <span class="stat-icon <?= e($card[3]) ?>"><i class="<?= e($card[2]) ?>"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format((float) $card[1]) ?></span>
                <span class="stat-label d-block"><?= e($card[0]) ?></span>
            </span>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- =========================== FILTER BAR =========================== -->
<form class="filter-bar" method="get" action="<?= url('inventory/index.php') ?>">
    <div class="row g-2">
        <div class="col-lg-3 col-md-6">
            <input type="search" name="q" class="form-control" placeholder="Search code, name, serial, brand, employee..."
                   value="<?= e($filters['q']) ?>">
        </div>
        <div class="col-lg-2 col-md-6">
            <select name="branch_id" id="branch_id" class="form-select">
                <option value="">All branches</option>
                <?php foreach ($branches as $branch): ?>
                <option value="<?= (int) $branch['id'] ?>"<?= selected($branch['id'], $filters['branch_id']) ?>><?= e($branch['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <select name="department_id" id="department_id" class="form-select"
                    data-cascade="departments" data-url="api/options.php" data-parent="#branch_id"
                    data-allow-empty data-placeholder="All departments"
                    data-selected="<?= e($filters['department_id']) ?>"></select>
        </div>
        <div class="col-lg-2 col-md-6">
            <select name="category_id" class="form-select">
                <option value="">All categories</option>
                <?php foreach ($catTree as $parent): ?>
                <optgroup label="<?= e($parent['name']) ?>">
                    <?php foreach ($parent['children'] as $child): ?>
                    <option value="<?= (int) $child['id'] ?>"<?= selected($child['id'], $filters['category_id']) ?>><?= e($child['category_name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <select name="brand_id" class="form-select">
                <option value="">All brands</option>
                <?php foreach ($brands as $brand): ?>
                <option value="<?= (int) $brand['id'] ?>"<?= selected($brand['id'], $filters['brand_id']) ?>><?= e($brand['brand_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 col-md-6">
            <select name="status" class="form-select">
                <option value="">Status</option>
                <?php foreach (asset_statuses() as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($key, $filters['status']) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <select name="asset_condition" class="form-select">
                <option value="">Condition</option>
                <?php foreach (asset_conditions() as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($key, $filters['asset_condition']) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <input type="date" name="purchase_date_from" class="form-control" value="<?= e($filters['purchase_date_from']) ?>" title="Purchased from">
        </div>
        <div class="col-lg-2 col-md-6">
            <input type="date" name="purchase_date_to" class="form-control" value="<?= e($filters['purchase_date_to']) ?>" title="Purchased to">
        </div>
        <div class="col-lg-3 col-md-6 d-flex gap-2">
            <button type="submit" class="btn btn-brand flex-grow-1"><i class="fa-solid fa-filter me-2"></i>Filter</button>
            <a class="btn btn-outline-brand" href="<?= url('inventory/index.php') ?>"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </div>
</form>

<!-- ============================== TABLE ============================== -->
<div class="card">
    <div class="table-toolbar">
        <div class="left">
            <span class="text-muted-sm"><?= pagination_summary($total, $perPage, $page, 'assets') ?></span>
        </div>
        <div class="right">
            <div class="input-group input-group-sm" style="width:230px">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" class="form-control" placeholder="Filter this page..." data-table-filter="#assetsTable" data-table-empty="#assetsEmpty">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table" id="assetsTable">
            <thead>
                <tr>
                    <th style="width:36px"><input type="checkbox" class="form-check-input" data-check-all=".asset-check"></th>
                    <th><a href="<?= e($sortUrl('asset_code')) ?>" class="text-decoration-none">Asset ID<?= $sortIcon('asset_code') ?></a></th>
                    <th><a href="<?= e($sortUrl('equipment_name')) ?>" class="text-decoration-none">Equipment<?= $sortIcon('equipment_name') ?></a></th>
                    <th>Category</th>
                    <th>Serial No</th>
                    <th>Branch</th>
                    <th>Department</th>
                    <th>Assigned To</th>
                    <th><a href="<?= e($sortUrl('purchase_date')) ?>" class="text-decoration-none">Purchase Date<?= $sortIcon('purchase_date') ?></a></th>
                    <th class="text-end">Price</th>
                    <th><a href="<?= e($sortUrl('status')) ?>" class="text-decoration-none">Status<?= $sortIcon('status') ?></a></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$assets): ?>
                <tr id="assetsEmpty">
                    <td colspan="12">
                        <div class="empty-state py-4"><i class="fa-solid fa-box-open"></i><p class="mb-0">No assets match the current filters.</p></div>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($assets as $asset): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input asset-check" name="asset_ids[]" value="<?= (int) $asset['id'] ?>"></td>
                    <td><a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $asset['id']) ?>"><?= e($asset['asset_code']) ?></a></td>
                    <td>
                        <div><?= e($asset['equipment_name']) ?></div>
                        <div class="text-muted-sm"><?= e($asset['brand_name'] ?: '-') ?><?= $asset['model_number'] ? ' &middot; ' . e($asset['model_number']) : '' ?></div>
                    </td>
                    <td class="text-muted-sm">
                        <?= e($asset['category_name'] ?: '-') ?>
                        <?php if ($asset['group_name']): ?><div class="text-muted-sm"><?= e($asset['group_name']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-muted-sm"><?= e($asset['serial_number'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($asset['branch_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($asset['department_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e($asset['employee_name'] ?: '-') ?></td>
                    <td class="text-muted-sm"><?= e(fdate($asset['purchase_date'])) ?></td>
                    <td class="text-end text-muted-sm"><?= e(money($asset['purchase_price'])) ?></td>
                    <td><?= status_badge($asset['status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn-icon" href="<?= url('inventory/view.php?id=' . (int) $asset['id']) ?>" title="View"><i class="fa-solid fa-eye"></i></a>
                            <?php if (can('inventory')): ?>
                            <a class="btn-icon" href="<?= url('inventory/edit.php?id=' . (int) $asset['id']) ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <?php if (can('assignments')): ?>
                            <a class="btn-icon" href="<?= url('assignments/create.php?asset_id=' . (int) $asset['id']) ?>" title="Assign"><i class="fa-solid fa-user-check"></i></a>
                            <?php endif; ?>
                            <?php if (can('transfers')): ?>
                            <a class="btn-icon" href="<?= url('transfers/create.php?asset_id=' . (int) $asset['id']) ?>" title="Transfer"><i class="fa-solid fa-right-left"></i></a>
                            <?php endif; ?>
                            <button type="button" class="btn-icon" title="Update status"
                                    data-bs-toggle="modal" data-bs-target="#statusModal"
                                    data-asset-id="<?= (int) $asset['id'] ?>"
                                    data-asset-code="<?= e($asset['asset_code']) ?>"
                                    data-status="<?= e($asset['status']) ?>"
                                    data-condition="<?= e($asset['asset_condition']) ?>">
                                <i class="fa-solid fa-sliders"></i>
                            </button>
                            <form method="post" action="<?= url('inventory/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete"
                                        data-confirm="Delete asset <?= e($asset['asset_code']) ?>? This also removes its assignment, transfer, maintenance and stock history.">
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

    <?php if ($total > $perPage): ?>
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted-sm"><?= pagination_summary($total, $perPage, $page, 'assets') ?></span>
        <?= paginate($total, $perPage, $page) ?>
    </div>
    <?php endif; ?>
</div>

<?php if (can('inventory')): ?>
<!-- ===================== UPDATE STATUS MODAL ===================== -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= url('inventory/status.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="asset_id" id="smAssetId" value="">
            <input type="hidden" name="redirect" value="index">
            <div class="modal-header">
                <h5 class="modal-title">Update asset status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted-sm mb-3">Asset: <span class="fw-code" id="smAssetCode">-</span></p>
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" id="smStatus" class="form-select">
                        <?php foreach (asset_statuses() as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Condition</label>
                    <select name="condition" id="smCondition" class="form-select">
                        <?php foreach (asset_conditions() as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label">Remarks</label>
                    <input type="text" name="remarks" class="form-control" placeholder="Optional note">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-brand" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand">Save status</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('statusModal');
    if (!modal) { return; }
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) { return; }
        document.getElementById('smAssetId').value = btn.getAttribute('data-asset-id');
        document.getElementById('smAssetCode').textContent = btn.getAttribute('data-asset-code');
        document.getElementById('smStatus').value = btn.getAttribute('data-status');
        document.getElementById('smCondition').value = btn.getAttribute('data-condition');
    });
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
