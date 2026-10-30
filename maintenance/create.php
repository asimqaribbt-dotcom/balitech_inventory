<?php
/**
 * Maintenance - report a new equipment fault.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('maintenance');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$assets    = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.status, b.branch_name
       FROM inventory i
       LEFT JOIN branches b ON b.id = i.branch_id
      WHERE 1 = 1 $scopeSql
      ORDER BY i.asset_code", $scopeParams
);
$assetIds  = array_map('intval', array_column($assets, 'id'));

$errors = [];

$assetId   = (int) (post('asset_id', get_param('asset_id', 0)));
$issueTitle = post('issue_title');
$issueDesc = post('issue_description');
$mtype     = post('maintenance_type', 'corrective');
$technician = post('technician_name');
$vendor    = post('vendor');
$reported  = post('reported_date', date('Y-m-d'));
$costInput = post('cost', '0');
$remarks   = post('remarks');

if (is_post()) {
    csrf_verify();

    if ($assetId <= 0 || !in_array($assetId, $assetIds, true)) {
        $errors[] = 'Please select a valid asset.';
    }
    if ($issueTitle === '') {
        $errors[] = 'Issue title is required.';
    }
    if (!isset(maintenance_types()[$mtype])) {
        $mtype = 'corrective';
    }
    if ($reported === '' ) {
        $reported = date('Y-m-d');
    }
    if (!is_valid_date($reported)) {
        $errors[] = 'Reported date is not a valid date.';
    }
    if (!is_numeric($costInput) || (float) $costInput < 0) {
        $errors[] = 'Cost must be a number of zero or greater.';
    }

    if (!$errors) {
        $cost = round((float) $costInput, 2);
        $pdo  = db();
        try {
            $pdo->beginTransaction();

            db_run(
                'INSERT INTO maintenance_records
                    (asset_id, issue_title, issue_description, maintenance_type, technician_name, vendor,
                     reported_date, cost, status, reported_by, remarks, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "reported", ?, ?, NOW())',
                [
                    $assetId, $issueTitle, null_if_empty($issueDesc), $mtype,
                    null_if_empty($technician), null_if_empty($vendor), $reported, $cost,
                    current_user_id(), null_if_empty($remarks),
                ]
            );
            $id = (int) $pdo->lastInsertId();

            $asset = db_row('SELECT id, asset_code, branch_id, category_id, status FROM inventory WHERE id = ?', [$assetId]);
            if ($asset && !in_array($asset['status'], ['damaged', 'retired'], true)) {
                db_run('UPDATE inventory SET status = "under_maintenance" WHERE id = ?', [$assetId]);
            }

            db_run(
                'INSERT INTO stock_transactions
                    (asset_id, category_id, transaction_type, quantity, branch_id, notes, performed_by, transaction_date)
                 VALUES (?, ?, "adjustment", 1, ?, ?, ?, CURDATE())',
                [
                    $assetId,
                    $asset['category_id'] ?? null,
                    $asset['branch_id'] ?? null,
                    'Marked under maintenance for record #' . $id,
                    current_user_id(),
                ]
            );

            log_activity('Maintenance', 'create', $id, 'Reported fault on ' . ($asset['asset_code'] ?? ('asset #' . $assetId)));

            $pdo->commit();
            set_flash('success', 'Maintenance record #' . $id . ' created and the asset marked under maintenance.');
            redirect('maintenance/view.php?id=' . $id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[BALITECH][maintenance/create] ' . $e->getMessage());
            set_flash('danger', 'Unable to save the maintenance record. Please try again.');
        }
    }
}

$page_title    = 'Report Equipment Fault';
$page_subtitle = 'Log a new maintenance record for an asset';
$active_menu   = 'maintenance';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('maintenance/index.php') ?>">Maintenance</a></li>
                <li class="breadcrumb-item active">Report fault</li>
            </ol>
        </nav>
        <h2>Report equipment fault</h2>
        <p>Select the asset and describe the reported issue.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('maintenance/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to list</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger app-alert" role="alert">
    <i class="fa-solid fa-circle-xmark me-2"></i>Please fix the following:
    <ul class="mb-0 mt-2">
        <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="<?= url('maintenance/create.php') ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header">
                    <div class="stat-icon bg-soft-amber" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div><h3 class="mb-0">Fault details</h3><p class="sub mb-0">Equipment and issue information</p></div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="asset_id">Asset <span class="req">*</span></label>
                            <select class="form-select" id="asset_id" name="asset_id" required>
                                <option value="">-- Select asset --</option>
                                <?php foreach ($assets as $asset): ?>
                                <option value="<?= (int) $asset['id'] ?>"<?= selected($asset['id'], $assetId) ?>>
                                    <?= e($asset['asset_code'] . ' - ' . $asset['equipment_name']) ?><?= $asset['branch_name'] ? ' (' . e($asset['branch_name']) . ')' : '' ?> [<?= e(asset_statuses()[$asset['status']] ?? $asset['status']) ?>]
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="issue_title">Issue title <span class="req">*</span></label>
                            <input type="text" class="form-control" id="issue_title" name="issue_title" maxlength="150"
                                   value="<?= e($issueTitle) ?>" required placeholder="e.g. Display flickering">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="maintenance_type">Maintenance type</label>
                            <select class="form-select" id="maintenance_type" name="maintenance_type">
                                <?php foreach (maintenance_types() as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= selected($key, $mtype) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="issue_description">Issue description</label>
                            <textarea class="form-control" id="issue_description" name="issue_description" rows="4"
                                      placeholder="Describe the fault, symptoms and any error codes"><?= e($issueDesc) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="technician_name">Technician</label>
                            <input type="text" class="form-control" id="technician_name" name="technician_name" maxlength="120"
                                   value="<?= e($technician) ?>" placeholder="Assigned technician or team">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="vendor">Vendor / service partner</label>
                            <input type="text" class="form-control" id="vendor" name="vendor" maxlength="120"
                                   value="<?= e($vendor) ?>" placeholder="External vendor (optional)">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="remarks">Remarks</label>
                            <input type="text" class="form-control" id="remarks" name="remarks" maxlength="255"
                                   value="<?= e($remarks) ?>" placeholder="Internal notes (optional)">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header">
                    <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-sack-dollar"></i></div>
                    <div><h3 class="mb-0">Reporting</h3><p class="sub mb-0">Dates and cost</p></div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="reported_date">Reported date</label>
                        <input type="date" class="form-control" id="reported_date" name="reported_date" value="<?= e($reported) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="cost">Estimated / actual cost</label>
                        <input type="number" class="form-control" id="cost" name="cost" min="0" step="0.01" value="<?= e($costInput) ?>">
                        <div class="form-text">Leave 0 when the cost is not known yet.</div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-brand" href="<?= url('maintenance/index.php') ?>">Cancel</a>
                    <button class="btn btn-brand" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save record</button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
