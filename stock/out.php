<?php
/**
 * Stock Management - Stock Out (dispatch an asset to a branch / department).
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('stock');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];
$userId      = current_user_id();
$user        = current_user();

if (is_post()) {
    csrf_verify();

    $assetId     = (int) post('asset_id', 0);
    $branchId    = (int) post('branch_id', 0);
    $deptId      = (int) post('department_id', 0);
    $employeeId  = (int) post('employee_id', 0);
    $newStatus   = post('status', 'available');
    $referenceNo = post('reference_no');
    $notes       = post('notes');
    $transDate   = post('transaction_date');

    if (!is_valid_date($transDate)) {
        $transDate = date('Y-m-d');
    }
    if (!isset(asset_statuses()[$newStatus])) {
        $newStatus = 'available';
    }

    $asset = db_row(
        "SELECT i.* FROM inventory i
          WHERE i.id = :id AND i.status IN ('available','assigned') $scopeSql",
        array_merge(['id' => $assetId], $scopeParams)
    );
    if (!$asset) {
        deny_access('The selected asset could not be found for your branch.');
    }

    if ($branchId <= 0 || (int) db_value('SELECT COUNT(*) FROM branches WHERE id = ? AND status = "active"', [$branchId]) === 0) {
        set_flash('danger', 'Please select a valid destination branch.');
        redirect('stock/out.php');
    }

    $deptId = $deptId > 0 ? $deptId : null;
    if ($deptId !== null && (int) db_value('SELECT COUNT(*) FROM departments WHERE id = ? AND status = "active"', [$deptId]) === 0) {
        $deptId = null;
    }

    if ($newStatus === 'assigned') {
        $employee = db_row('SELECT * FROM employees WHERE id = ? AND status = "active"', [$employeeId]);
        if (!$employee) {
            set_flash('danger', 'An employee must be selected when the status is "Assigned".');
            redirect('stock/out.php');
        }
    } else {
        $employeeId = 0;
    }

    $needsUpdate = ($newStatus !== $asset['status']) || $employeeId > 0 || (int) $asset['branch_id'] !== $branchId;

    try {
        db()->beginTransaction();

        db_run(
            'INSERT INTO stock_transactions
                (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes,
                 performed_by, transaction_date, created_at)
             VALUES (?, ?, "out", 1, ?, ?, ?, ?, ?, NOW())',
            [
                $assetId,
                $asset['category_id'],
                $branchId,
                null_if_empty($referenceNo),
                null_if_empty($notes) ?? 'Stock issued from store',
                $userId,
                $transDate,
            ]
        );
        $transactionId = (int) db()->lastInsertId();

        if ($needsUpdate) {
            db_run(
                'UPDATE inventory
                    SET status = ?, branch_id = ?, department_id = ?, assigned_employee_id = ?
                  WHERE id = ?',
                [
                    $newStatus,
                    $branchId,
                    $deptId,
                    $employeeId > 0 ? $employeeId : null,
                    $assetId,
                ]
            );

            if ($newStatus === 'assigned') {
                db_run(
                    'INSERT INTO asset_assignments
                        (asset_id, employee_id, branch_id, department_id, assignment_date, assigned_by,
                         assignment_status, condition_on_assign, remarks, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, "assigned", ?, ?, NOW())',
                    [
                        $assetId,
                        $employeeId,
                        $branchId,
                        $deptId,
                        $transDate,
                        $userId,
                        $asset['asset_condition'] ?: 'Good',
                        null_if_empty($notes),
                    ]
                );
            } elseif ($asset['status'] === 'assigned') {
                db_run(
                    'UPDATE asset_assignments
                        SET assignment_status = "returned", return_date = ?
                      WHERE asset_id = ? AND assignment_status = "assigned"',
                    [$transDate, $assetId]
                );
            }
        }

        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        error_log('[BALITECH][stock/out] ' . $ex->getMessage());
        set_flash('danger', 'Unable to record the stock-out. Please try again.');
        redirect('stock/out.php');
    }

    log_activity('Stock', 'create', $assetId, 'Stock out: ' . $asset['asset_code'] . ' (' . $newStatus . ')');
    set_flash('success', 'Stock out recorded for ' . $asset['asset_code'] . '.');
    redirect('stock/history.php');
}

/* ---------------------------------------------------------------------
 * Form data
 * ------------------------------------------------------------------ */
$dispatchAssets = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.status, b.branch_name
       FROM inventory i
       LEFT JOIN branches b ON b.id = i.branch_id
      WHERE i.status IN ('available','assigned') $scopeSql
      ORDER BY i.asset_code
      LIMIT 200",
    $scopeParams
);

$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$page_title    = 'Stock Out';
$page_subtitle = 'Dispatch an asset out of the store';
$active_menu   = 'stock';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('stock/index.php') ?>">Stock Management</a></li>
                <li class="breadcrumb-item active">Stock Out</li>
            </ol>
        </nav>
        <h2>Stock out</h2>
        <p>Issue an asset from the store to a destination branch, department or employee.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('stock/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to stock</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <form method="post" action="<?= url('stock/out.php') ?>" class="needs-validation" novalidate>
            <?= csrf_field() ?>
            <div class="card">
                <div class="card-header">
                    <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-arrow-up"></i></div>
                    <div>
                        <h3 class="mb-0">Stock out entry</h3>
                        <p class="sub mb-0">Asset, destination and resulting status</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-section-title">Asset</div>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label" for="asset_id">Asset <span class="req">*</span></label>
                            <select class="form-select" id="asset_id" name="asset_id" required>
                                <option value="">-- Select an asset --</option>
                                <?php foreach ($dispatchAssets as $asset): ?>
                                <option value="<?= (int) $asset['id'] ?>">
                                    <?= e($asset['asset_code']) ?> - <?= e($asset['equipment_name']) ?>
                                    (<?= e($asset['branch_name'] ?: 'No branch') ?>, <?= e($asset['status']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Available and assigned assets (latest 200).</div>
                        </div>
                    </div>

                    <div class="form-section-title mt-4">Destination</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="dest_branch_id">Destination branch <span class="req">*</span></label>
                            <select class="form-select" id="dest_branch_id" name="branch_id" required>
                                <option value="">-- Select branch --</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int) $branch['id'] ?>"><?= e($branch['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="department_id">Destination department</label>
                            <select class="form-select" id="department_id" name="department_id"
                                    data-cascade="departments" data-url="api/options.php"
                                    data-parent="#dest_branch_id" data-placeholder="-- Select department (optional) --"
                                    data-allow-empty>
                                <option value="">-- Select department (optional) --</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="employee_id">Employee (optional)</label>
                            <select class="form-select" id="employee_id" name="employee_id"
                                    data-cascade="employees" data-url="api/options.php"
                                    data-branch-source="#dest_branch_id" data-placeholder="-- Select employee (optional) --">
                                <option value="">-- Select employee (optional) --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title mt-4">Details</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="status">New status <span class="req">*</span></label>
                            <select class="form-select" id="status" name="status" required>
                                <?php foreach (asset_statuses() as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= selected($key, 'available') ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Defaults to <b>Assigned</b> when an employee is chosen.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="transaction_date">Transaction date <span class="req">*</span></label>
                            <input type="date" class="form-control" id="transaction_date" name="transaction_date"
                                   value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="reference_no">Reference no</label>
                            <input type="text" class="form-control" id="reference_no" name="reference_no" maxlength="60" placeholder="e.g. ISS-2026-001">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-brand" href="<?= url('stock/index.php') ?>">Cancel</a>
                    <button type="submit" class="btn btn-accent"><i class="fa-solid fa-arrow-up me-2"></i>Save stock out</button>
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
                    <p class="sub mb-0">Stock out behaviour</p>
                </div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Stock log</span><span class="v">Records a stock-out entry</span></li>
                    <li><span class="k">Status change</span><span class="v">Updates the asset status</span></li>
                    <li><span class="k">Assigned</span><span class="v">Creates an assignment record</span></li>
                    <li><span class="k">History</span><span class="v">View all entries in Stock History</span></li>
                </ul>
                <div class="alert-row info mt-3">
                    <i class="fa-solid fa-lightbulb" style="color:var(--info);margin-top:3px"></i>
                    <div class="flex-grow-1">
                        <div class="a-title">Pick an employee</div>
                        <div class="a-meta">Choosing an employee automatically sets the status to Assigned.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var employee = document.getElementById('employee_id');
    var status = document.getElementById('status');
    if (!employee || !status) { return; }
    employee.addEventListener('change', function () {
        if (employee.value) {
            status.value = 'assigned';
        } else if (status.value === 'assigned') {
            status.value = 'available';
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
