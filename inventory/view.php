<?php
/**
 * Inventory - asset detail page (read-only) with full lifecycle history.
 */
require_once __DIR__ . '/../init.php';
require_login();

$user  = current_user();
$scope = rbac_scope('i');
$id    = (int) get_param('id');

$asset = $id > 0
    ? db_row(
        "SELECT i.*, c.category_name, pc.category_name AS group_name, b.brand_name,
                br.branch_name, d.department_name, emp.employee_name, cu.full_name AS created_by_name
           FROM inventory i
           LEFT JOIN asset_categories c ON c.id = i.category_id
           LEFT JOIN asset_categories pc ON pc.id = c.parent_id
           LEFT JOIN brands b ON b.id = i.brand_id
           LEFT JOIN branches br ON br.id = i.branch_id
           LEFT JOIN departments d ON d.id = i.department_id
           LEFT JOIN employees emp ON emp.id = i.assigned_employee_id
           LEFT JOIN users cu ON cu.id = i.created_by
          WHERE i.id = :id $scope[sql]",
        array_merge(['id' => $id], $scope['params'])
    )
    : null;

if (!$asset) {
    set_flash('warning', 'The requested asset was not found or is outside your scope.');
    redirect('inventory/index.php');
}

/* ---------------------------- Lifecycle history --------------------------- */
$assignmentHistory = db_all(
    'SELECT a.*, e.employee_name, e.emp_code, u.full_name AS assigned_by_name,
            br.branch_name, dp.department_name
       FROM asset_assignments a
       LEFT JOIN employees e ON e.id = a.employee_id
       LEFT JOIN users u ON u.id = a.assigned_by
       LEFT JOIN branches br ON br.id = a.branch_id
       LEFT JOIN departments dp ON dp.id = a.department_id
      WHERE a.asset_id = ?
      ORDER BY a.assignment_date DESC, a.id DESC',
    [$id]
);

$transferHistory = db_all(
    'SELECT t.*, fb.branch_name AS from_branch, tb.branch_name AS to_branch,
            fd.department_name AS from_dept, td.department_name AS to_dept, u.full_name AS by_name
       FROM asset_transfers t
       LEFT JOIN branches fb ON fb.id = t.from_branch_id
       LEFT JOIN branches tb ON tb.id = t.to_branch_id
       LEFT JOIN departments fd ON fd.id = t.from_department_id
       LEFT JOIN departments td ON td.id = t.to_department_id
       LEFT JOIN users u ON u.id = t.transferred_by
      WHERE t.asset_id = ?
      ORDER BY t.transfer_date DESC, t.id DESC',
    [$id]
);

$maintenanceHistory = db_all(
    'SELECT * FROM maintenance_records WHERE asset_id = ? ORDER BY reported_date DESC, id DESC',
    [$id]
);
$maintenanceCost = (float) db_value('SELECT COALESCE(SUM(cost),0) FROM maintenance_records WHERE asset_id = ?', [$id], 0);

$stockHistory = db_all(
    'SELECT s.*, u.full_name AS by_name
       FROM stock_transactions s
       LEFT JOIN users u ON u.id = s.performed_by
      WHERE s.asset_id = ?
      ORDER BY s.transaction_date DESC, s.id DESC',
    [$id]
);

/* ------------------------------- Derived values --------------------------- */
$ageText = '-';
if (!empty($asset['purchase_date'])) {
    $purchase = new DateTime($asset['purchase_date']);
    $now      = new DateTime('today');
    if ($purchase <= $now) {
        $diff    = $purchase->diff($now);
        $ageText = $diff->y . ' y ' . $diff->m . ' m';
    }
}

$warrantyExpired = !empty($asset['warranty_expiry']) && $asset['warranty_expiry'] <= date('Y-m-d');
$isAssigned      = $asset['assigned_employee_id'] !== null && $asset['status'] === 'assigned';

$page_title    = 'Asset ' . $asset['asset_code'];
$page_subtitle = (string) $asset['equipment_name'];
$active_menu   = 'inventory';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('inventory/index.php') ?>">Inventory</a></li>
                <li class="breadcrumb-item active"><?= e($asset['asset_code']) ?></li>
            </ol>
        </nav>
        <h2>
            <?= e($asset['equipment_name']) ?>
            <span class="badge badge-soft badge-secondary ms-1 fw-code"><?= e($asset['asset_code']) ?></span>
            <?= status_badge($asset['status']) ?>
        </h2>
        <p><?= e($asset['brand_name'] ?: 'No brand') ?> &middot; <?= e($asset['category_name'] ?: 'Uncategorised') ?></p>
    </div>
    <div class="page-actions">
        <?php if (can('inventory')): ?>
        <a class="btn btn-brand" href="<?= url('inventory/edit.php?id=' . $id) ?>"><i class="fa-solid fa-pen me-2"></i>Edit</a>
        <?php endif; ?>
        <?php if (can('assignments')): ?>
        <a class="btn btn-outline-brand" href="<?= url('assignments/create.php?asset_id=' . $id) ?>"><i class="fa-solid fa-user-check me-2"></i>Assign</a>
        <?php endif; ?>
        <?php if (can('transfers')): ?>
        <a class="btn btn-outline-brand" href="<?= url('transfers/create.php?asset_id=' . $id) ?>"><i class="fa-solid fa-right-left me-2"></i>Transfer</a>
        <?php endif; ?>
        <?php if (can('inventory')): ?>
        <button type="button" class="btn btn-outline-brand" data-bs-toggle="modal" data-bs-target="#statusModal"><i class="fa-solid fa-sliders me-2"></i>Update Status</button>
        <?php endif; ?>
        <?php if (can('maintenance')): ?>
        <a class="btn btn-outline-brand" href="<?= url('maintenance/create.php?asset_id=' . $id) ?>"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Report Fault</a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-brand" data-print><i class="fa-solid fa-print me-2"></i>Print</button>
        <?php if (can('inventory')): ?>
        <form method="post" action="<?= url('inventory/delete.php') ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="asset_id" value="<?= $id ?>">
            <button type="submit" class="btn btn-soft-danger"
                    data-confirm="Delete asset <?= e($asset['asset_code']) ?>? This also removes its assignment, transfer, maintenance and stock history.">
                <i class="fa-solid fa-trash me-2"></i>Delete
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($isAssigned): ?>
<div class="alert alert-info app-alert d-flex align-items-center gap-2" role="alert">
    <i class="fa-solid fa-user-check"></i>
    <span>Currently assigned to
        <b><?= e($asset['employee_name']) ?></b><?= $asset['department_name'] ? ' &middot; ' . e($asset['department_name']) : '' ?><?= $asset['branch_name'] ? ' &middot; ' . e($asset['branch_name']) : '' ?>.
    </span>
</div>
<?php endif; ?>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="stat-card">
            <span class="stat-icon bg-soft-green"><i class="fa-solid fa-money-bill-wave"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block" style="font-size:19px"><?= e(money($asset['purchase_price'])) ?></span>
                <span class="stat-label d-block">Purchase Price</span>
            </span>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-heart-pulse"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block" style="font-size:19px"><?= condition_badge($asset['asset_condition']) ?></span>
                <span class="stat-label d-block">Current Condition</span>
            </span>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="stat-card">
            <span class="stat-icon bg-soft-amber"><i class="fa-solid fa-shield-halved"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block" style="font-size:19px"><?= e(fdate($asset['warranty_expiry'])) ?></span>
                <span class="stat-label d-block">Warranty<?= $warrantyExpired ? ' <span class="badge badge-soft badge-danger ms-1">Expired</span>' : '' ?></span>
            </span>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="stat-card">
            <span class="stat-icon bg-soft-navy"><i class="fa-solid fa-hourglass-half"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block" style="font-size:19px"><?= e($ageText) ?></span>
                <span class="stat-label d-block">Age in Service</span>
            </span>
        </div>
    </div>
</div>

<!-- =========================== DETAIL CARDS =========================== -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-fingerprint"></i></div>
                <div><h3 class="mb-0">Identification</h3><p class="sub mb-0">Asset identifiers</p></div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Asset ID</span><span class="v fw-code"><?= e($asset['asset_code']) ?></span></li>
                    <li><span class="k">Serial No</span><span class="v"><?= e($asset['serial_number'] ?: '-') ?></span></li>
                    <li><span class="k">Model</span><span class="v"><?= e($asset['model_number'] ?: '-') ?></span></li>
                    <li><span class="k">Brand</span><span class="v"><?= e($asset['brand_name'] ?: '-') ?></span></li>
                    <li><span class="k">Category</span><span class="v"><?= e($asset['category_name'] ?: '-') ?></span></li>
                    <li><span class="k">Group</span><span class="v"><?= e($asset['group_name'] ?: '-') ?></span></li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-teal" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-location-dot"></i></div>
                <div><h3 class="mb-0">Location</h3><p class="sub mb-0">Where the asset is deployed</p></div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Branch</span><span class="v"><?= e($asset['branch_name'] ?: '-') ?></span></li>
                    <li><span class="k">Department</span><span class="v"><?= e($asset['department_name'] ?: '-') ?></span></li>
                    <li>
                        <span class="k">Assigned To</span>
                        <span class="v">
                            <?php if ($asset['assigned_employee_id']): ?>
                            <a href="<?= url('employees/view.php?id=' . (int) $asset['assigned_employee_id']) ?>"><?= e($asset['employee_name']) ?></a>
                            <?php else: ?>-<?php endif; ?>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-green" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-cart-shopping"></i></div>
                <div><h3 class="mb-0">Purchase</h3><p class="sub mb-0">Procurement details</p></div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Purchase Date</span><span class="v"><?= e(fdate($asset['purchase_date'])) ?></span></li>
                    <li><span class="k">Purchase Price</span><span class="v"><?= e(money($asset['purchase_price'])) ?></span></li>
                    <li>
                        <span class="k">Warranty Expiry</span>
                        <span class="v"><?= e(fdate($asset['warranty_expiry'])) ?>
                            <?= $warrantyExpired ? ' <span class="badge badge-soft badge-danger">Expired</span>' : '' ?>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-gray" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-circle-info"></i></div>
                <div><h3 class="mb-0">Other</h3><p class="sub mb-0">Record metadata</p></div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Condition</span><span class="v"><?= condition_badge($asset['asset_condition']) ?></span></li>
                    <li><span class="k">Status</span><span class="v"><?= status_badge($asset['status']) ?></span></li>
                    <li><span class="k">Date Added</span><span class="v"><?= e(fdate($asset['created_at'], 'd M Y, h:i A')) ?></span></li>
                    <li><span class="k">Added By</span><span class="v"><?= e($asset['created_by_name'] ?: 'System') ?></span></li>
                    <li><span class="k">Remarks</span><span class="v"><?= $asset['remarks'] ? e($asset['remarks']) : '-' ?></span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ============================== TABS ============================== -->
<div class="card">
    <div class="card-header pb-0">
        <ul class="nav nav-tabs w-100" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabAssign" type="button">Assignment history</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabTransfer" type="button">Transfer history</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabMaint" type="button">Maintenance history</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabStock" type="button">Stock transactions</button></li>
        </ul>
    </div>
    <div class="tab-content">
        <!-- Assignment history -->
        <div class="tab-pane fade show active" id="tabAssign">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Employee</th><th>Branch</th><th>Department</th><th>Assigned</th><th>Expected Return</th><th>Returned</th><th>Status</th><th>Assigned By</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$assignmentHistory): ?>
                        <tr><td colspan="9"><div class="empty-state py-4"><i class="fa-solid fa-user-check"></i><p class="mb-0">No assignment history.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($assignmentHistory as $row): ?>
                        <tr>
                            <td><?= e($row['employee_name'] ?: '-') ?><?= $row['emp_code'] ? ' <span class="text-muted-sm">(' . e($row['emp_code']) . ')</span>' : '' ?></td>
                            <td class="text-muted-sm"><?= e($row['branch_name'] ?? '-') ?></td>
                            <td class="text-muted-sm"><?= e($row['department_name'] ?? '-') ?></td>
                            <td class="text-muted-sm"><?= e(fdate($row['assignment_date'])) ?></td>
                            <td class="text-muted-sm"><?= e(fdate($row['expected_return_date'])) ?></td>
                            <td class="text-muted-sm"><?= e(fdate($row['return_date'])) ?></td>
                            <td><?= assignment_badge($row['assignment_status']) ?></td>
                            <td class="text-muted-sm"><?= e($row['assigned_by_name'] ?: '-') ?></td>
                            <td class="text-end">
                                <?php if ($row['assignment_status'] === 'assigned' && can('assignments')): ?>
                                <a class="btn btn-sm btn-outline-brand" href="<?= url('assignments/return.php?assignment_id=' . (int) $row['id']) ?>">Return</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Transfer history -->
        <div class="tab-pane fade" id="tabTransfer">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Date</th><th>From</th><th>To</th><th>Reason</th><th>Status</th><th>By</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$transferHistory): ?>
                        <tr><td colspan="6"><div class="empty-state py-4"><i class="fa-solid fa-right-left"></i><p class="mb-0">No transfer history.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($transferHistory as $row): ?>
                        <tr>
                            <td class="text-muted-sm"><?= e(fdate($row['transfer_date'])) ?></td>
                            <td class="text-muted-sm"><?= e($row['from_branch'] ?: '-') ?><?= $row['from_dept'] ? ' / ' . e($row['from_dept']) : '' ?></td>
                            <td class="text-muted-sm"><?= e($row['to_branch'] ?: '-') ?><?= $row['to_dept'] ? ' / ' . e($row['to_dept']) : '' ?></td>
                            <td class="text-muted-sm"><?= e($row['reason'] ?: '-') ?></td>
                            <td><span class="badge badge-soft badge-<?= $row['status'] === 'completed' ? 'success' : ($row['status'] === 'pending' ? 'warning' : 'secondary') ?>"><?= e(ucfirst($row['status'])) ?></span></td>
                            <td class="text-muted-sm"><?= e($row['by_name'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Maintenance history -->
        <div class="tab-pane fade" id="tabMaint">
            <div class="table-toolbar">
                <div class="left"><span class="text-muted-sm">Total maintenance cost: <b><?= e(money($maintenanceCost)) ?></b></span></div>
                <div class="right">
                    <?php if (can('maintenance')): ?>
                    <a class="btn btn-sm btn-brand" href="<?= url('maintenance/create.php?asset_id=' . $id) ?>"><i class="fa-solid fa-plus me-1"></i>Add record</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Issue</th><th>Type</th><th>Technician</th><th>Reported</th><th>Repaired</th><th class="text-end">Cost</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$maintenanceHistory): ?>
                        <tr><td colspan="7"><div class="empty-state py-4"><i class="fa-solid fa-screwdriver-wrench"></i><p class="mb-0">No maintenance records.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($maintenanceHistory as $row): ?>
                        <tr>
                            <td>
                                <div><?= e($row['issue_title']) ?></div>
                                <?php if ($row['vendor']): ?><div class="text-muted-sm"><?= e($row['vendor']) ?></div><?php endif; ?>
                            </td>
                            <td class="text-muted-sm"><?= e(maintenance_types()[$row['maintenance_type']] ?? ucfirst($row['maintenance_type'])) ?></td>
                            <td class="text-muted-sm"><?= e($row['technician_name'] ?: '-') ?></td>
                            <td class="text-muted-sm"><?= e(fdate($row['reported_date'])) ?></td>
                            <td class="text-muted-sm"><?= e(fdate($row['repair_date'])) ?></td>
                            <td class="text-end text-muted-sm"><?= e(money($row['cost'])) ?></td>
                            <td><?= maintenance_badge($row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Stock transactions -->
        <div class="tab-pane fade" id="tabStock">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Type</th><th class="text-end">Qty</th><th>Reference</th><th>Date</th><th>Performed By</th><th>Notes</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$stockHistory): ?>
                        <tr><td colspan="6"><div class="empty-state py-4"><i class="fa-solid fa-warehouse"></i><p class="mb-0">No stock transactions.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($stockHistory as $row): ?>
                        <tr>
                            <td><?= stock_badge($row['transaction_type']) ?></td>
                            <td class="text-end"><?= (int) $row['quantity'] ?></td>
                            <td class="fw-code"><?= e($row['reference_no'] ?: '-') ?></td>
                            <td class="text-muted-sm"><?= e(fdate($row['transaction_date'])) ?></td>
                            <td class="text-muted-sm"><?= e($row['by_name'] ?: '-') ?></td>
                            <td class="text-muted-sm"><?= e($row['notes'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (can('inventory')): ?>
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= url('inventory/status.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="asset_id" value="<?= $id ?>">
            <div class="modal-header">
                <h5 class="modal-title">Update asset status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted-sm mb-3">Asset: <span class="fw-code"><?= e($asset['asset_code']) ?></span></p>
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (asset_statuses() as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= selected($key, $asset['status']) ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Condition</label>
                    <select name="condition" class="form-select">
                        <?php foreach (asset_conditions() as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= selected($key, $asset['asset_condition']) ?>><?= e($label) ?></option>
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
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
