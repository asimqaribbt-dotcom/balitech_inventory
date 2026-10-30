<?php
/**
 * Asset Assignment - return a previously assigned asset.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('assignments');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];
$userId      = current_user_id();

$assignmentId = is_post() ? (int) post('assignment_id', 0) : (int) get_param('assignment_id', 0);

$assignment = db_row(
    "SELECT a.*, i.asset_code, i.equipment_name, i.serial_number, i.asset_condition, i.category_id,
            i.status AS asset_status,
            emp.employee_name, emp.emp_code,
            b.branch_name, d.department_name
       FROM asset_assignments a
       JOIN inventory i ON i.id = a.asset_id
       JOIN employees emp ON emp.id = a.employee_id
       LEFT JOIN branches b ON b.id = a.branch_id
       LEFT JOIN departments d ON d.id = a.department_id
      WHERE a.id = :id $scopeSql",
    array_merge(['id' => $assignmentId], $scopeParams)
);

if (!$assignment) {
    deny_access('The requested assignment could not be found for your branch.');
}

if (is_post()) {
    csrf_verify();

    if ($assignment['assignment_status'] !== 'assigned') {
        set_flash('warning', 'This assignment has already been closed.');
        redirect('assignments/index.php');
    }

    $returnDate  = post('return_date');
    $condReturn  = post('condition_on_return');
    $markDamaged = post('mark_damaged') === '1';
    $remarks     = post('remarks');

    if (!is_valid_date($returnDate)) {
        $returnDate = date('Y-m-d');
    }
    if (!isset(asset_conditions()[$condReturn])) {
        $condReturn = $assignment['asset_condition'] ?: 'Good';
    }

    $newStatus = $markDamaged ? 'damaged' : 'available';

    try {
        db()->beginTransaction();

        db_run(
            'UPDATE asset_assignments
                SET return_date = ?, assignment_status = "returned", condition_on_return = ?, remarks = ?
              WHERE id = ?',
            [$returnDate, $condReturn, null_if_empty($remarks), $assignmentId]
        );

        db_run(
            'UPDATE inventory
                SET assigned_employee_id = NULL, asset_condition = ?, status = ?
              WHERE id = ?',
            [$condReturn, $newStatus, $assignment['asset_id']]
        );

        db_run(
            'INSERT INTO stock_transactions
                (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes,
                 performed_by, transaction_date, created_at)
             VALUES (?, ?, "return", 1, ?, ?, ?, ?, ?, NOW())',
            [
                $assignment['asset_id'],
                $assignment['category_id'],
                null_if_empty($assignment['branch_id']),
                'RET-' . date('Y') . '-' . str_pad((string) $assignmentId, 4, '0', STR_PAD_LEFT),
                'Returned by ' . $assignment['employee_name'],
                $userId,
                $returnDate,
            ]
        );

        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        error_log('[BALITECH][assignments/return] ' . $ex->getMessage());
        set_flash('danger', 'Unable to process the return. Please try again.');
        redirect('assignments/return.php?assignment_id=' . $assignmentId);
    }

    log_activity('Assignment', 'return', $assignmentId,
        'Returned ' . $assignment['asset_code'] . ' from ' . $assignment['employee_name']
        . ' (' . $assignment['emp_code'] . ')' . ($markDamaged ? ' - marked damaged' : ''));
    set_flash('success', 'Asset ' . $assignment['asset_code'] . ' returned'
        . ($markDamaged ? ' and marked as damaged.' : '.'));
    redirect('assignments/index.php');
}

$page_title    = 'Return Asset';
$page_subtitle = 'Close an assignment and bring the asset back to store';
$active_menu   = 'assignments';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('assignments/index.php') ?>">Asset Assignment</a></li>
                <li class="breadcrumb-item active">Return</li>
            </ol>
        </nav>
        <h2>Return asset</h2>
        <p>Record the return of <b><?= e($assignment['asset_code']) ?></b> from <?= e($assignment['employee_name']) ?>.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('assignments/index.php') ?>">
            <i class="fa-solid fa-arrow-left me-2"></i>Back to list
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-navy" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-circle-info"></i></div>
                <div>
                    <h3 class="mb-0">Assignment summary</h3>
                    <p class="sub mb-0">Current record details</p>
                </div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Asset code</span><span class="v fw-code"><?= e($assignment['asset_code']) ?></span></li>
                    <li><span class="k">Equipment</span><span class="v"><?= e($assignment['equipment_name']) ?></span></li>
                    <li><span class="k">Serial number</span><span class="v"><?= e($assignment['serial_number'] ?: '-') ?></span></li>
                    <li><span class="k">Employee</span><span class="v"><?= e($assignment['employee_name']) ?> (<?= e($assignment['emp_code']) ?>)</span></li>
                    <li><span class="k">Branch</span><span class="v"><?= e($assignment['branch_name'] ?: '-') ?></span></li>
                    <li><span class="k">Department</span><span class="v"><?= e($assignment['department_name'] ?: '-') ?></span></li>
                    <li><span class="k">Assignment date</span><span class="v"><?= e(fdate($assignment['assignment_date'])) ?></span></li>
                    <li><span class="k">Expected return</span><span class="v"><?= e(fdate($assignment['expected_return_date'])) ?></span></li>
                    <li><span class="k">Condition on assign</span><span class="v"><?= condition_badge($assignment['condition_on_assign']) ?></span></li>
                    <li><span class="k">Status</span><span class="v"><?= assignment_badge($assignment['assignment_status']) ?></span></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <?php if ($assignment['assignment_status'] !== 'assigned'): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state py-4">
                    <i class="fa-solid fa-circle-check" style="color:var(--success)"></i>
                    <p class="mb-0">This assignment is already closed (<?= e($assignment['assignment_status']) ?>).</p>
                </div>
            </div>
        </div>
        <?php else: ?>
        <form method="post" action="<?= url('assignments/return.php') ?>" class="needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="assignment_id" value="<?= (int) $assignmentId ?>">
            <div class="card">
                <div class="card-header">
                    <div class="stat-icon bg-soft-green" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-rotate-left"></i></div>
                    <div>
                        <h3 class="mb-0">Return details</h3>
                        <p class="sub mb-0">Condition and notes on return</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="return_date">Return date <span class="req">*</span></label>
                            <input type="date" class="form-control" id="return_date" name="return_date"
                                   value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="condition_on_return">Condition on return <span class="req">*</span></label>
                            <select class="form-select" id="condition_on_return" name="condition_on_return" required>
                                <?php foreach (asset_conditions() as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= selected($key, $assignment['asset_condition']) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label" for="remarks">Remarks</label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="2"
                                      placeholder="Optional notes about the return"></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="mark_damaged" name="mark_damaged" value="1">
                                <label class="form-check-label" for="mark_damaged">
                                    Mark asset as <b>damaged</b> (otherwise it returns to <b>available</b>)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-brand" href="<?= url('assignments/index.php') ?>">Cancel</a>
                    <button type="submit" class="btn btn-brand"><i class="fa-solid fa-check me-2"></i>Confirm return</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
