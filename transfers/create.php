<?php
/**
 * Asset Transfers - create a transfer between branches.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('transfers');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];
$userId      = current_user_id();

$preAssetId = (int) get_param('asset_id', 0);

if (is_post()) {
    csrf_verify();

    $assetId      = (int) post('asset_id', 0);
    $toBranchId   = (int) post('to_branch_id', 0);
    $toDeptId     = (int) post('to_department_id', 0);
    $transferDate = post('transfer_date');
    $reason       = post('reason');
    $completeNow  = post('complete_now') === '1';

    if (!is_valid_date($transferDate)) {
        $transferDate = date('Y-m-d');
    }

    $asset = db_row(
        "SELECT i.* FROM inventory i WHERE i.id = :id $scopeSql",
        array_merge(['id' => $assetId], $scopeParams)
    );
    if (!$asset) {
        deny_access('You can only transfer assets that belong to your own branch.');
    }

    $toBranch = db_row('SELECT id, branch_name FROM branches WHERE id = ? AND status = "active"', [$toBranchId]);
    if (!$toBranch) {
        set_flash('danger', 'Please select a valid destination branch.');
        redirect('transfers/create.php?asset_id=' . $assetId);
    }
    if ((int) $asset['branch_id'] === (int) $toBranchId) {
        set_flash('danger', 'The destination branch must be different from the current branch.');
        redirect('transfers/create.php?asset_id=' . $assetId);
    }

    $toDeptId = $toDeptId > 0 ? $toDeptId : null;
    if ($toDeptId !== null) {
        $deptCheck = (int) db_value('SELECT COUNT(*) FROM departments WHERE id = ? AND status = "active"', [$toDeptId]);
        if ($deptCheck === 0) {
            $toDeptId = null;
        }
    }

    $fromBranchName = (string) db_value('SELECT branch_name FROM branches WHERE id = ?', [$asset['branch_id']], '-');

    try {
        db()->beginTransaction();

        db_run(
            'INSERT INTO asset_transfers
                (asset_id, from_branch_id, to_branch_id, from_department_id, to_department_id,
                 transfer_date, transferred_by, reason, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $assetId,
                null_if_empty($asset['branch_id']),
                $toBranchId,
                null_if_empty($asset['department_id']),
                $toDeptId,
                $transferDate,
                $userId,
                null_if_empty($reason),
                $completeNow ? 'completed' : 'pending',
            ]
        );
        $transferId = (int) db()->lastInsertId();

        if ($completeNow) {
            $wasAssigned = ($asset['status'] === 'assigned' || !empty($asset['assigned_employee_id']));

            if ($wasAssigned) {
                db_run(
                    'UPDATE inventory
                        SET branch_id = ?, department_id = ?, assigned_employee_id = NULL, status = "available"
                      WHERE id = ?',
                    [$toBranchId, $toDeptId, $assetId]
                );
                db_run(
                    'UPDATE asset_assignments
                        SET assignment_status = "transferred", return_date = ?
                      WHERE asset_id = ? AND assignment_status = "assigned"',
                    [$transferDate, $assetId]
                );
            } else {
                db_run(
                    'UPDATE inventory
                        SET branch_id = ?, department_id = ?, assigned_employee_id = NULL
                      WHERE id = ?',
                    [$toBranchId, $toDeptId, $assetId]
                );
            }

            db_run(
                'INSERT INTO stock_transactions
                    (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes,
                     performed_by, transaction_date, created_at)
                 VALUES (?, ?, "transfer", 1, ?, ?, ?, ?, ?, NOW())',
                [
                    $assetId,
                    $asset['category_id'],
                    $toBranchId,
                    'TRF-' . date('Y') . '-' . str_pad((string) $transferId, 4, '0', STR_PAD_LEFT),
                    'Transferred from ' . $fromBranchName . ' to ' . $toBranch['branch_name'],
                    $userId,
                    $transferDate,
                ]
            );
        }

        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        error_log('[BALITECH][transfers/create] ' . $ex->getMessage());
        set_flash('danger', 'Unable to save the transfer. Please try again.');
        redirect('transfers/create.php?asset_id=' . $assetId);
    }

    log_activity('Transfer', 'create', $transferId,
        ($completeNow ? 'Transferred ' : 'Requested transfer of ') . $asset['asset_code']
        . ' to ' . $toBranch['branch_name']);
    set_flash('success', $completeNow
        ? 'Asset ' . $asset['asset_code'] . ' transferred to ' . $toBranch['branch_name'] . '.'
        : 'Transfer of ' . $asset['asset_code'] . ' recorded as pending.');
    redirect('transfers/index.php');
}

/* ---------------------------------------------------------------------
 * Form data
 * ------------------------------------------------------------------ */
$assets = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.branch_id, i.department_id, i.status, b.branch_name
       FROM inventory i
       LEFT JOIN branches b ON b.id = i.branch_id
      WHERE i.status <> 'retired'
        $scopeSql
      ORDER BY i.asset_code
      LIMIT 500",
    $scopeParams
);

$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$page_title    = 'New Transfer';
$page_subtitle = 'Move an asset to another branch or department';
$active_menu   = 'transfers';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('transfers/index.php') ?>">Transfers</a></li>
                <li class="breadcrumb-item active">New transfer</li>
            </ol>
        </nav>
        <h2>New transfer</h2>
        <p>Select an asset and its destination branch to record a movement.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('transfers/index.php') ?>">
            <i class="fa-solid fa-arrow-left me-2"></i>Back to list
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <form method="post" action="<?= url('transfers/create.php') ?>" class="needs-validation" novalidate>
            <?= csrf_field() ?>
            <div class="card">
                <div class="card-header">
                    <div class="stat-icon bg-soft-teal" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-right-left"></i></div>
                    <div>
                        <h3 class="mb-0">Transfer details</h3>
                        <p class="sub mb-0">Source asset and destination branch</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label" for="asset_id">Asset <span class="req">*</span></label>
                            <select class="form-select" id="asset_id" name="asset_id" required>
                                <option value="">-- Select an asset --</option>
                                <?php foreach ($assets as $asset): ?>
                                <option value="<?= (int) $asset['id'] ?>" <?= selected($asset['id'], $preAssetId) ?>>
                                    <?= e($asset['asset_code']) ?> - <?= e($asset['equipment_name']) ?> (<?= e($asset['branch_name'] ?: 'No branch') ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">All non-retired assets are listed.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="to_branch_id">Destination branch <span class="req">*</span></label>
                            <select class="form-select" id="to_branch_id" name="to_branch_id" required>
                                <option value="">-- Select destination branch --</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int) $branch['id'] ?>"><?= e($branch['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="to_department_id">Destination department</label>
                            <select class="form-select" id="to_department_id" name="to_department_id"
                                    data-cascade="departments" data-url="api/options.php"
                                    data-parent="#to_branch_id" data-placeholder="-- Select department (optional) --"
                                    data-allow-empty>
                                <option value="">-- Select department (optional) --</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="transfer_date">Transfer date <span class="req">*</span></label>
                            <input type="date" class="form-control" id="transfer_date" name="transfer_date"
                                   value="<?= e(date('Y-m-d')) ?>" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label" for="reason">Reason</label>
                            <textarea class="form-control" id="reason" name="reason" rows="3"
                                      placeholder="Why is this asset being transferred?"></textarea>
                        </div>

                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="complete_now" name="complete_now" value="1" checked>
                                <label class="form-check-label" for="complete_now">
                                    Complete the transfer immediately (uncheck to save as <b>pending</b> for approval)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-brand" href="<?= url('transfers/index.php') ?>">Cancel</a>
                    <button type="submit" class="btn btn-brand"><i class="fa-solid fa-check me-2"></i>Save transfer</button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="stat-icon bg-soft-amber" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-circle-info"></i></div>
                <div>
                    <h3 class="mb-0">Transfer rules</h3>
                    <p class="sub mb-0">What happens on completion</p>
                </div>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="k">Different branch</span><span class="v">Destination must differ</span></li>
                    <li><span class="k">Assigned asset</span><span class="v">Holder cleared, marked available</span></li>
                    <li><span class="k">Assignment</span><span class="v">Closed as transferred</span></li>
                    <li><span class="k">Stock log</span><span class="v">Records a transfer entry</span></li>
                </ul>
                <div class="alert-row mt-3">
                    <i class="fa-solid fa-triangle-exclamation" style="color:var(--warning);margin-top:3px"></i>
                    <div class="flex-grow-1">
                        <div class="a-title">Pending transfers</div>
                        <div class="a-meta">A pending transfer can be approved or cancelled from the transfers list.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
