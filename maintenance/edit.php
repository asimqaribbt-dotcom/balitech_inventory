<?php
/**
 * Maintenance - edit / update a maintenance record.
 * Also serves the quick "Mark as completed" action from the index page
 * (posted with a quick=1 flag and only status/repair_date/cost fields).
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('maintenance');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$id = (int) (post('id', get_param('id', 0)));

$record = db_row(
    "SELECT m.*, i.asset_code, i.equipment_name, i.status AS asset_status, i.branch_id AS asset_branch_id
       FROM maintenance_records m
       JOIN inventory i ON i.id = m.asset_id
      WHERE m.id = :id $scopeSql",
    ['id' => $id] + $scopeParams
);

if (!$record) {
    set_flash('danger', 'Maintenance record not found.');
    redirect('maintenance/index.php');
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $values = [
        'issue_title'              => post('issue_title', $record['issue_title']),
        'issue_description'        => post('issue_description', $record['issue_description']),
        'maintenance_type'         => post('maintenance_type', $record['maintenance_type']),
        'technician_name'          => post('technician_name', $record['technician_name']),
        'vendor'                   => post('vendor', $record['vendor']),
        'reported_date'            => post('reported_date', $record['reported_date']),
        'repair_date'              => post('repair_date', $record['repair_date']),
        'cost'                     => post('cost', $record['cost']),
        'status'                   => post('status', $record['status']),
        'equipment_condition_after'=> post('equipment_condition_after', $record['equipment_condition_after']),
        'remarks'                  => post('remarks', $record['remarks']),
    ];

    if ($values['issue_title'] === '') {
        $errors[] = 'Issue title is required.';
    }
    if (!isset(maintenance_statuses()[$values['status']])) {
        $errors[] = 'Please choose a valid status.';
    }
    if (!isset(maintenance_types()[$values['maintenance_type']])) {
        $values['maintenance_type'] = $record['maintenance_type'];
    }
    if (!is_valid_date($values['reported_date'])) {
        $errors[] = 'Reported date is not a valid date.';
    }
    if ($values['repair_date'] !== '' && !is_valid_date($values['repair_date'])) {
        $errors[] = 'Repair date is not a valid date.';
    }
    if ($values['equipment_condition_after'] !== '' && !isset(asset_conditions()[$values['equipment_condition_after']])) {
        $errors[] = 'Please choose a valid equipment condition.';
    }
    if (!is_numeric($values['cost']) || (float) $values['cost'] < 0) {
        $errors[] = 'Cost must be a number of zero or greater.';
    }

    if (!$errors) {
        $oldStatus  = $record['status'];
        $newStatus  = $values['status'];
        $newCond    = null_if_empty($values['equipment_condition_after']);
        $assetId    = (int) $record['asset_id'];
        $pdo        = db();

        try {
            $pdo->beginTransaction();

            db_run(
                'UPDATE maintenance_records
                    SET issue_title = ?, issue_description = ?, maintenance_type = ?, technician_name = ?,
                        vendor = ?, reported_date = ?, repair_date = ?, cost = ?, status = ?,
                        equipment_condition_after = ?, remarks = ?, updated_at = NOW()
                  WHERE id = ?',
                [
                    $values['issue_title'],
                    null_if_empty($values['issue_description']),
                    $values['maintenance_type'],
                    null_if_empty($values['technician_name']),
                    null_if_empty($values['vendor']),
                    $values['reported_date'],
                    null_if_empty($values['repair_date']),
                    round((float) $values['cost'], 2),
                    $newStatus,
                    $newCond,
                    null_if_empty($values['remarks']),
                    $id,
                ]
            );

            // Reflect the maintenance outcome on the asset (never touch a retired asset)
            if ($newStatus === 'completed') {
                if ($newCond) {
                    db_run('UPDATE inventory SET status = "available", asset_condition = ? WHERE id = ? AND status <> "retired"', [$newCond, $assetId]);
                } else {
                    db_run('UPDATE inventory SET status = "available" WHERE id = ? AND status <> "retired"', [$assetId]);
                }
            } elseif ($newStatus === 'unrepairable') {
                db_run('UPDATE inventory SET status = "damaged", asset_condition = "Poor" WHERE id = ? AND status <> "retired"', [$assetId]);
            } elseif ($newStatus === 'in_progress') {
                db_run('UPDATE inventory SET status = "under_maintenance" WHERE id = ? AND status <> "retired"', [$assetId]);
            }

            $statusLabels = maintenance_statuses();
            log_activity(
                'Maintenance', 'update', $id,
                'Record #' . $id . ' on ' . $record['asset_code'] . ': '
                . ($statusLabels[$oldStatus] ?? $oldStatus) . ' -> ' . ($statusLabels[$newStatus] ?? $newStatus)
            );

            $pdo->commit();
            set_flash('success', 'Maintenance record #' . $id . ' updated.');
            redirect('maintenance/view.php?id=' . $id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[BALITECH][maintenance/edit] ' . $e->getMessage());
            set_flash('danger', 'Unable to update the maintenance record. Please try again.');
        }
    }
}

// Merge posted values for re-rendering on validation error
$view = array_merge($record, [
    'issue_title'               => $values['issue_title'] ?? $record['issue_title'],
    'issue_description'         => $values['issue_description'] ?? $record['issue_description'],
    'maintenance_type'          => $values['maintenance_type'] ?? $record['maintenance_type'],
    'technician_name'           => $values['technician_name'] ?? $record['technician_name'],
    'vendor'                    => $values['vendor'] ?? $record['vendor'],
    'reported_date'             => $values['reported_date'] ?? $record['reported_date'],
    'repair_date'               => $values['repair_date'] ?? $record['repair_date'],
    'cost'                      => $values['cost'] ?? $record['cost'],
    'status'                    => $values['status'] ?? $record['status'],
    'equipment_condition_after' => $values['equipment_condition_after'] ?? $record['equipment_condition_after'],
    'remarks'                   => $values['remarks'] ?? $record['remarks'],
]);

$page_title    = 'Edit Maintenance #' . $id;
$page_subtitle = 'Update the maintenance record and asset outcome';
$active_menu   = 'maintenance';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('maintenance/index.php') ?>">Maintenance</a></li>
                <li class="breadcrumb-item"><a href="<?= url('maintenance/view.php?id=' . $id) ?>">#<?= $id ?></a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
        <h2>Edit maintenance record #<?= $id ?></h2>
        <p><a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $record['asset_id']) ?>"><?= e($record['asset_code']) ?></a> &middot; <?= e($record['equipment_name']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('maintenance/view.php?id=' . $id) ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to record</a>
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

<form method="post" action="<?= url('maintenance/edit.php?id=' . $id) ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header">
                    <div class="stat-icon bg-soft-amber" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div><h3 class="mb-0">Fault details</h3><p class="sub mb-0">Issue information and diagnosis</p></div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="issue_title">Issue title <span class="req">*</span></label>
                            <input type="text" class="form-control" id="issue_title" name="issue_title" maxlength="150"
                                   value="<?= e($view['issue_title']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="maintenance_type">Maintenance type</label>
                            <select class="form-select" id="maintenance_type" name="maintenance_type">
                                <?php foreach (maintenance_types() as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= selected($key, $view['maintenance_type']) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="issue_description">Issue description</label>
                            <textarea class="form-control" id="issue_description" name="issue_description" rows="4"><?= e($view['issue_description']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="technician_name">Technician</label>
                            <input type="text" class="form-control" id="technician_name" name="technician_name" maxlength="120" value="<?= e($view['technician_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="vendor">Vendor / service partner</label>
                            <input type="text" class="form-control" id="vendor" name="vendor" maxlength="120" value="<?= e($view['vendor']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="remarks">Remarks</label>
                            <input type="text" class="form-control" id="remarks" name="remarks" maxlength="255" value="<?= e($view['remarks']) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header">
                    <div class="stat-icon bg-soft-green" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-clipboard-check"></i></div>
                    <div><h3 class="mb-0">Outcome</h3><p class="sub mb-0">Status, dates and cost</p></div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status">
                            <?php foreach (maintenance_statuses() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= selected($key, $view['status']) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="equipment_condition_after">Condition after repair</label>
                        <select class="form-select" id="equipment_condition_after" name="equipment_condition_after">
                            <option value="">-- Not applicable --</option>
                            <?php foreach (asset_conditions() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= selected($key, $view['equipment_condition_after']) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="reported_date">Reported date</label>
                        <input type="date" class="form-control" id="reported_date" name="reported_date" value="<?= e($view['reported_date']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="repair_date">Repair date</label>
                        <input type="date" class="form-control" id="repair_date" name="repair_date" value="<?= e($view['repair_date']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="cost">Estimated / actual cost</label>
                        <input type="number" class="form-control" id="cost" name="cost" min="0" step="0.01" value="<?= e($view['cost']) ?>">
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-brand" href="<?= url('maintenance/view.php?id=' . $id) ?>">Cancel</a>
                    <button class="btn btn-brand" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Update record</button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
