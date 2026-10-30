<?php
/**
 * Maintenance - view a single maintenance record with the asset card,
 * a status timeline, and the asset's full repair history.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$id = (int) get_param('id', 0);

$record = db_row(
    "SELECT m.*, i.asset_code, i.equipment_name, i.model_number, i.serial_number,
            i.status AS asset_status, i.asset_condition, i.purchase_price,
            i.branch_id, i.department_id,
            b.branch_name, d.department_name, c.category_name, br.brand_name,
            u.full_name AS reported_by_name
       FROM maintenance_records m
       JOIN inventory i ON i.id = m.asset_id
       LEFT JOIN branches b ON b.id = i.branch_id
       LEFT JOIN departments d ON d.id = i.department_id
       LEFT JOIN asset_categories c ON c.id = i.category_id
       LEFT JOIN brands br ON br.id = i.brand_id
       LEFT JOIN users u ON u.id = m.reported_by
      WHERE m.id = :id $scopeSql",
    ['id' => $id] + $scopeParams
);

if (!$record) {
    set_flash('danger', 'Maintenance record not found.');
    redirect('maintenance/index.php');
}

$assetId = (int) $record['asset_id'];

$assetTotalCost = (float) db_value(
    'SELECT COALESCE(SUM(cost), 0) FROM maintenance_records WHERE asset_id = ?', [$assetId]
);
$assetOpenCount = (int) db_value(
    'SELECT COUNT(*) FROM maintenance_records WHERE asset_id = ? AND status IN ("reported", "in_progress")', [$assetId]
);

$history = db_all(
    'SELECT id, issue_title, maintenance_type, technician_name, vendor, reported_date, repair_date,
            cost, status, equipment_condition_after
       FROM maintenance_records
      WHERE asset_id = ?
      ORDER BY reported_date DESC, id DESC',
    [$assetId]
);

$previous = array_values(array_filter($history, static function ($row) use ($id) {
    return (int) $row['id'] !== $id;
}));

$page_title    = 'Maintenance #' . $id;
$page_subtitle = $record['issue_title'];
$active_menu   = 'maintenance';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('maintenance/index.php') ?>">Maintenance</a></li>
                <li class="breadcrumb-item active">#<?= $id ?></li>
            </ol>
        </nav>
        <h2>Maintenance record #<?= $id ?></h2>
        <p><?= e($record['issue_title']) ?> &middot; <?= maintenance_badge($record['status']) ?></p>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline-brand" type="button" data-print><i class="fa-solid fa-print me-2"></i>Print</button>
        <?php if (can('maintenance')): ?>
        <a class="btn btn-outline-brand" href="<?= url('maintenance/edit.php?id=' . $id) ?>"><i class="fa-solid fa-pen me-2"></i>Edit</a>
        <?php if ($record['status'] !== 'completed'): ?>
        <form method="post" action="<?= url('maintenance/edit.php') ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="quick" value="1">
            <input type="hidden" name="status" value="completed">
            <input type="hidden" name="repair_date" value="<?= e(date('Y-m-d')) ?>">
            <input type="hidden" name="cost" value="<?= e($record['cost']) ?>">
            <button class="btn btn-brand" type="submit" data-confirm="Mark record #<?= $id ?> as completed?"><i class="fa-solid fa-circle-check me-2"></i>Mark completed</button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?= url('maintenance/delete.php') ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-soft-danger" type="submit" data-confirm="Delete maintenance record #<?= $id ?>? This cannot be undone."><i class="fa-regular fa-trash-can me-2"></i>Delete</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card mb-3">
            <div class="card-header">
                <div class="stat-icon bg-soft-amber" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                <div><h3 class="mb-0">Record details</h3><p class="sub mb-0">Reported fault and repair outcome</p></div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Issue title</span><span class="v"><?= e($record['issue_title']) ?></span></li>
                    <li><span class="k">Type</span><span class="v"><?= e(maintenance_types()[$record['maintenance_type']] ?? $record['maintenance_type']) ?></span></li>
                    <li><span class="k">Status</span><span class="v"><?= maintenance_badge($record['status']) ?></span></li>
                    <li><span class="k">Technician</span><span class="v"><?= e($record['technician_name'] ?: '-') ?></span></li>
                    <li><span class="k">Vendor</span><span class="v"><?= e($record['vendor'] ?: '-') ?></span></li>
                    <li><span class="k">Reported date</span><span class="v"><?= e(fdate($record['reported_date'])) ?></span></li>
                    <li><span class="k">Repair date</span><span class="v"><?= e(fdate($record['repair_date'])) ?></span></li>
                    <li><span class="k">Cost</span><span class="v"><?= e(money($record['cost'])) ?></span></li>
                    <li><span class="k">Condition after</span><span class="v"><?= $record['equipment_condition_after'] ? condition_badge($record['equipment_condition_after']) : '-' ?></span></li>
                    <li><span class="k">Reported by</span><span class="v"><?= e($record['reported_by_name'] ?: '-') ?></span></li>
                    <li><span class="k">Remarks</span><span class="v"><?= e($record['remarks'] ?: '-') ?></span></li>
                </ul>
                <?php if ($record['issue_description']): ?>
                <div class="mt-3">
                    <div class="k text-muted mb-1" style="font-size:12.4px;font-weight:600">Issue description</div>
                    <div class="text-muted-sm" style="white-space:pre-line"><?= e($record['issue_description']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div><h3 class="mb-0">Repair history</h3><p class="sub mb-0">All maintenance records for this asset</p></div>
                <span class="ms-auto badge badge-soft badge-secondary">Total <?= e(money($assetTotalCost)) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Record #</th><th>Issue</th><th>Type</th><th>Reported</th><th>Repair Date</th><th class="text-end">Cost</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$previous): ?>
                        <tr class="no-filter"><td colspan="7" class="text-center text-muted py-4">No previous maintenance records for this asset.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($previous as $row): ?>
                        <tr>
                            <td><a class="fw-code" href="<?= url('maintenance/view.php?id=' . (int) $row['id']) ?>">#<?= (int) $row['id'] ?></a></td>
                            <td><?= e($row['issue_title']) ?></td>
                            <td class="text-muted-sm"><?= e(maintenance_types()[$row['maintenance_type']] ?? $row['maintenance_type']) ?></td>
                            <td class="text-muted-sm"><?= e(fdate($row['reported_date'])) ?></td>
                            <td class="text-muted-sm"><?= e(fdate($row['repair_date'])) ?></td>
                            <td class="text-end"><?= e(money($row['cost'])) ?></td>
                            <td><?= maintenance_badge($row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card mb-3">
            <div class="card-header">
                <div class="stat-icon bg-soft-navy" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-box"></i></div>
                <div><h3 class="mb-0">Asset</h3><p class="sub mb-0">Equipment under maintenance</p></div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Asset code</span><span class="v"><a class="fw-code" href="<?= url('inventory/view.php?id=' . $assetId) ?>"><?= e($record['asset_code']) ?></a></span></li>
                    <li><span class="k">Equipment</span><span class="v"><?= e($record['equipment_name']) ?></span></li>
                    <li><span class="k">Category</span><span class="v"><?= e($record['category_name'] ?: '-') ?></span></li>
                    <li><span class="k">Brand</span><span class="v"><?= e($record['brand_name'] ?: '-') ?></span></li>
                    <li><span class="k">Model</span><span class="v"><?= e($record['model_number'] ?: '-') ?></span></li>
                    <li><span class="k">Serial</span><span class="v"><?= e($record['serial_number'] ?: '-') ?></span></li>
                    <li><span class="k">Branch</span><span class="v"><?= e($record['branch_name'] ?: '-') ?></span></li>
                    <li><span class="k">Department</span><span class="v"><?= e($record['department_name'] ?: '-') ?></span></li>
                    <li><span class="k">Current status</span><span class="v"><?= status_badge($record['asset_status']) ?></span></li>
                    <li><span class="k">Condition</span><span class="v"><?= condition_badge($record['asset_condition']) ?></span></li>
                    <li><span class="k">Purchase price</span><span class="v"><?= e(money($record['purchase_price'])) ?></span></li>
                    <li><span class="k">Open records</span><span class="v"><?= (int) $assetOpenCount ?></span></li>
                    <li><span class="k">Lifetime maint. cost</span><span class="v"><?= e(money($assetTotalCost)) ?></span></li>
                </ul>
                <a class="btn btn-outline-brand w-100 mt-3" href="<?= url('inventory/view.php?id=' . $assetId) ?>"><i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Open asset record</a>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <div class="stat-icon bg-soft-teal" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-timeline"></i></div>
                <div><h3 class="mb-0">Status timeline</h3><p class="sub mb-0">Progress of this fault</p></div>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="t-title">Fault reported</div>
                        <div class="t-meta"><?= e(fdate($record['reported_date'])) ?><?= $record['reported_by_name'] ? ' &middot; by ' . e($record['reported_by_name']) : '' ?></div>
                    </div>
                    <?php if (in_array($record['status'], ['in_progress', 'completed', 'unrepairable'], true)): ?>
                    <div class="timeline-item">
                        <div class="t-title">Work started</div>
                        <div class="t-meta"><?= $record['technician_name'] ? e($record['technician_name']) : 'Technician assigned' ?><?= $record['vendor'] ? ' &middot; ' . e($record['vendor']) : '' ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($record['status'] === 'completed'): ?>
                    <div class="timeline-item">
                        <div class="t-title">Repair completed</div>
                        <div class="t-meta"><?= e(fdate($record['repair_date'])) ?> &middot; cost <?= e(money($record['cost'])) ?></div>
                    </div>
                    <?php elseif ($record['status'] === 'unrepairable'): ?>
                    <div class="timeline-item">
                        <div class="t-title">Marked unrepairable</div>
                        <div class="t-meta">Asset set to damaged &middot; condition Poor</div>
                    </div>
                    <?php elseif ($record['status'] === 'in_progress'): ?>
                    <div class="timeline-item">
                        <div class="t-title">Repair in progress</div>
                        <div class="t-meta">Awaiting completion</div>
                    </div>
                    <?php else: ?>
                    <div class="timeline-item">
                        <div class="t-title">Awaiting action</div>
                        <div class="t-meta">Recorded and pending assignment</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
