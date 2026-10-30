<?php
/**
 * Asset Assignment - assign an available asset to an employee.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('assignments');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];
$userId      = current_user_id();

$preAssetId = (int) get_param('asset_id', 0);

if (is_post()) {
    csrf_verify();

    $assetId    = (int) post('asset_id', 0);
    $empId      = (int) post('employee_id', 0);
    $assignDate = post('assignment_date');
    $expReturn  = post('expected_return_date');
    $cond       = post('condition_on_assign', 'Good');
    $remarks    = post('remarks');

    if (!is_valid_date($assignDate)) {
        $assignDate = date('Y-m-d');
    }
    $expReturn = is_valid_date($expReturn) ? $expReturn : null;
    if (!isset(asset_conditions()[$cond])) {
        $cond = 'Good';
    }

    // The asset must exist, be available and fall inside the role data scope
    $asset = db_row(
        "SELECT i.* FROM inventory i WHERE i.id = :id $scopeSql",
        array_merge(['id' => $assetId], $scopeParams)
    );
    if (!$asset) {
        deny_access('You can only assign assets that belong to your own branch.');
    }
    if ($asset['status'] !== 'available') {
        set_flash('danger', 'Only assets with status "Available" can be assigned.');
        redirect('assignments/create.php?asset_id=' . $assetId);
    }

    $openAssignment = (int) db_value(
        "SELECT COUNT(*) FROM asset_assignments WHERE asset_id = ? AND assignment_status = 'assigned'",
        [$assetId]
    );
    if ($openAssignment > 0) {
        set_flash('danger', 'This asset already has an open assignment. Please return it first.');
        redirect('assignments/create.php?asset_id=' . $assetId);
    }

    $employee = db_row('SELECT * FROM employees WHERE id = ? AND status = "active"', [$empId]);
    if (!$employee) {
        set_flash('danger', 'Please select a valid employee.');
        redirect('assignments/create.php?asset_id=' . $assetId);
    }

    try {
        db()->beginTransaction();

        db_run(
            'INSERT INTO asset_assignments
                (asset_id, employee_id, branch_id, department_id, assignment_date, assigned_by,
                 expected_return_date, assignment_status, condition_on_assign, remarks, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, "assigned", ?, ?, NOW())',
            [
                $assetId,
                $empId,
                null_if_empty($employee['branch_id']),
                null_if_empty($employee['department_id']),
                $assignDate,
                $userId,
                $expReturn,
                $cond,
                null_if_empty($remarks),
            ]
        );
        $assignmentId = (int) db()->lastInsertId();

        db_run(
            'UPDATE inventory
                SET assigned_employee_id = ?, branch_id = ?, department_id = ?, status = "assigned"
              WHERE id = ?',
            [
                $empId,
                null_if_empty($employee['branch_id']),
                null_if_empty($employee['department_id']),
                $assetId,
            ]
        );

        db_run(
            'INSERT INTO stock_transactions
                (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes,
                 performed_by, transaction_date, created_at)
             VALUES (?, ?, "out", 1, ?, ?, ?, ?, ?, NOW())',
            [
                $assetId,
                $asset['category_id'],
                null_if_empty($employee['branch_id']),
                'ASG-' . date('Y') . '-' . str_pad((string) $assignmentId, 4, '0', STR_PAD_LEFT),
                'Assigned to employee ' . $employee['employee_name'],
                $userId,
                $assignDate,
            ]
        );

        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        error_log('[BALITECH][assignments/create] ' . $ex->getMessage());
        set_flash('danger', 'Unable to save the assignment. Please try again.');
        redirect('assignments/create.php?asset_id=' . $assetId);
    }

    log_activity('Assignment', 'create', $assignmentId,
        'Assigned ' . $asset['asset_code'] . ' to ' . $employee['employee_name'] . ' (' . $employee['emp_code'] . ')');
    set_flash('success', 'Asset ' . $asset['asset_code'] . ' assigned to ' . $employee['employee_name'] . '.');
    redirect('assignments/index.php');
}

/* ---------------------------------------------------------------------
 * Form data
 * ------------------------------------------------------------------ */
$assets = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.branch_id, b.branch_name
       FROM inventory i
       LEFT JOIN branches b ON b.id = i.branch_id
      WHERE i.status = 'available'
        AND NOT EXISTS (SELECT 1 FROM asset_assignments a
                         WHERE a.asset_id = i.id AND a.assignment_status = 'assigned')
        $scopeSql
      ORDER BY i.asset_code
      LIMIT 500",
    $scopeParams
);

$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$page_title    = 'Assign Asset';
$page_subtitle = 'Issue available equipment to an employee';
$active_menu   = 'assignments';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('assignments/index.php') ?>">Asset Assignment</a></li>
                <li class="breadcrumb-item active">Assign</li>
            </ol>
        </nav>
        <h2>Assign new asset</h2>
        <p>Select an available asset and the employee who will receive it.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('assignments/index.php') ?>">
            <i class="fa-solid fa-arrow-left me-2"></i>Back to list
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <form method="post" action="<?= url('assignments/create.php') ?>" class="needs-validation" novalidate>
            <?= csrf_field() ?>
            <div class="card">
                <div class="card-header">
                    <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <h3 class="mb-0">Assignment details</h3>
                        <p class="sub mb-0">Asset, branch and employee information</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-section-title">Equipment</div>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label" for="asset_id">Asset <span class="req">*</span></label>
                            <select class="form-select" id="asset_id" name="asset_id" required>
                                <option value="">-- Select an available asset --</option>
                                <?php foreach ($assets as $asset): ?>
                                <option value="<?= (int) $asset['id'] ?>"
                                        data-branch="<?= (int) $asset['branch_id'] ?>"
                                        <?= selected($asset['id'], $preAssetId) ?>>
                                    <?= e($asset['asset_code']) ?> - <?= e($asset['equipment_name']) ?><?= $asset['branch_name'] ? ' (' . e($asset['branch_name']) . ')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only assets with status <b>Available</b> and no open assignment are listed.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="branch_id">Branch</label>
                            <select class="form-select" id="branch_id" name="branch_id">
                                <option value="">-- Select branch --</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int) $branch['id'] ?>"><?= e($branch['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Auto-filled from the selected asset.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="department_id">Department</label>
                            <select class="form-select" id="department_id" name="department_id"
                                    data-cascade="departments" data-url="api/options.php"
                                    data-parent="#branch_id" data-placeholder="-- Select department (optional) --"
                                    data-allow-empty>
                                <option value="">-- Select department (optional) --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title mt-4">Employee</div>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label" for="employee_id">Employee <span class="req">*</span></label>
                            <select class="form-select" id="employee_id" name="employee_id" required
                                    data-cascade="employees" data-url="api/options.php"
                                    data-branch-source="#branch_id" data-placeholder="-- Select employee --">
                                <option value="">-- Select employee --</option>
                            </select>
                            <div class="form-text">Employees are filtered by the selected branch.</div>
                        </div>
                    </div>

                    <div class="form-section-title mt-4">Assignment</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="assignment_date">Assignment date <span class="req">*</span></label>
                            <input type="date" class="form-control" id="assignment_date" name="assignment_date"
                                   value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="expected_return_date">Expected return</label>
                            <input type="date" class="form-control" id="expected_return_date" name="expected_return_date">
                            <div class="form-text">Optional.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="condition_on_assign">Condition on assign <span class="req">*</span></label>
                            <select class="form-select" id="condition_on_assign" name="condition_on_assign" required>
                                <?php foreach (asset_conditions() as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= selected($key, 'Good') ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label" for="remarks">Remarks</label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="2"
                                      placeholder="Optional notes about this assignment"></textarea>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-brand" href="<?= url('assignments/index.php') ?>">Cancel</a>
                    <button type="submit" class="btn btn-brand"><i class="fa-solid fa-check me-2"></i>Assign asset</button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-amber" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-circle-info"></i></div>
                <div>
                    <h3 class="mb-0">How it works</h3>
                    <p class="sub mb-0">Assignment rules</p>
                </div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Assignable</span><span class="v">Available assets only</span></li>
                    <li><span class="k">Single holder</span><span class="v">One open assignment per asset</span></li>
                    <li><span class="k">Branch / dept</span><span class="v">Copied from the employee</span></li>
                    <li><span class="k">Stock log</span><span class="v">Records a stock-out entry</span></li>
                </ul>
                <div class="alert-row info mt-3">
                    <i class="fa-solid fa-lightbulb" style="color:var(--info);margin-top:3px"></i>
                    <div class="flex-grow-1">
                        <div class="a-title">Returning equipment?</div>
                        <div class="a-meta">Use the Return action from the assignment list to close the record.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var assetSelect = document.getElementById('asset_id');
    var branchSelect = document.getElementById('branch_id');
    if (!assetSelect || !branchSelect) { return; }
    function syncBranch() {
        var option = assetSelect.options[assetSelect.selectedIndex];
        var branch = option ? option.getAttribute('data-branch') : '';
        if (branch) {
            branchSelect.value = branch;
            branchSelect.dispatchEvent(new Event('change'));
        }
    }
    assetSelect.addEventListener('change', syncBranch);
    syncBranch();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
