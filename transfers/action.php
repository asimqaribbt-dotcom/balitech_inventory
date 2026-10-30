<?php
/**
 * Asset Transfers - POST only endpoint for complete / cancel / delete.
 */
require_once __DIR__ . '/../init.php';
require_login();

if (!is_post()) {
    redirect('transfers/index.php');
}
require_ability('transfers');
csrf_verify();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];
$userId      = current_user_id();

$action     = post('action');
$transferId = (int) post('transfer_id', 0);

$transfer = db_row(
    "SELECT t.*, i.asset_code, i.equipment_name, i.category_id, i.status AS asset_status,
            i.assigned_employee_id,
            fb.branch_name AS from_branch, tb.branch_name AS to_branch
       FROM asset_transfers t
       JOIN inventory i ON i.id = t.asset_id
       LEFT JOIN branches fb ON fb.id = t.from_branch_id
       LEFT JOIN branches tb ON tb.id = t.to_branch_id
      WHERE t.id = :id $scopeSql",
    array_merge(['id' => $transferId], $scopeParams)
);

if (!$transfer) {
    deny_access('The requested transfer could not be found for your branch.');
}

/* ---------------------------------------------------------------------
 * Delete (history only)
 * ------------------------------------------------------------------ */
if ($action === 'delete') {
    db_run('DELETE FROM asset_transfers WHERE id = ?', [$transferId]);
    log_activity('Transfer', 'delete', $transferId,
        'Deleted transfer record for ' . $transfer['asset_code']);
    set_flash('success', 'Transfer record deleted.');
    redirect('transfers/index.php');
}

/* ---------------------------------------------------------------------
 * Cancel a pending transfer
 * ------------------------------------------------------------------ */
if ($action === 'cancel') {
    if ($transfer['status'] !== 'pending') {
        set_flash('warning', 'Only pending transfers can be cancelled.');
        redirect('transfers/index.php');
    }
    db_run('UPDATE asset_transfers SET status = "cancelled" WHERE id = ?', [$transferId]);
    log_activity('Transfer', 'cancel', $transferId,
        'Cancelled transfer of ' . $transfer['asset_code']);
    set_flash('success', 'Transfer cancelled.');
    redirect('transfers/index.php');
}

/* ---------------------------------------------------------------------
 * Complete a pending transfer (moves the asset)
 * ------------------------------------------------------------------ */
if ($action === 'complete') {
    if ($transfer['status'] !== 'pending') {
        set_flash('warning', 'Only pending transfers can be completed.');
        redirect('transfers/index.php');
    }

    $toBranchId = (int) $transfer['to_branch_id'];
    $toDeptId   = $transfer['to_department_id'] !== null ? (int) $transfer['to_department_id'] : null;
    $transferDate = $transfer['transfer_date'];

    try {
        db()->beginTransaction();

        $asset = db_row('SELECT * FROM inventory WHERE id = ?', [$transfer['asset_id']]);
        $wasAssigned = ($asset['status'] === 'assigned' || !empty($asset['assigned_employee_id']));

        if ($wasAssigned) {
            db_run(
                'UPDATE inventory
                    SET branch_id = ?, department_id = ?, assigned_employee_id = NULL, status = "available"
                  WHERE id = ?',
                [$toBranchId, $toDeptId, $transfer['asset_id']]
            );
            db_run(
                'UPDATE asset_assignments
                    SET assignment_status = "transferred", return_date = ?
                  WHERE asset_id = ? AND assignment_status = "assigned"',
                [$transferDate, $transfer['asset_id']]
            );
        } else {
            db_run(
                'UPDATE inventory
                    SET branch_id = ?, department_id = ?, assigned_employee_id = NULL
                  WHERE id = ?',
                [$toBranchId, $toDeptId, $transfer['asset_id']]
            );
        }

        db_run('UPDATE asset_transfers SET status = "completed" WHERE id = ?', [$transferId]);

        db_run(
            'INSERT INTO stock_transactions
                (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes,
                 performed_by, transaction_date, created_at)
             VALUES (?, ?, "transfer", 1, ?, ?, ?, ?, ?, NOW())',
            [
                $transfer['asset_id'],
                $transfer['category_id'],
                $toBranchId,
                'TRF-' . date('Y') . '-' . str_pad((string) $transferId, 4, '0', STR_PAD_LEFT),
                'Transferred from ' . ($transfer['from_branch'] ?: '-') . ' to ' . ($transfer['to_branch'] ?: '-'),
                $userId,
                $transferDate,
            ]
        );

        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        error_log('[BALITECH][transfers/action:complete] ' . $ex->getMessage());
        set_flash('danger', 'Unable to complete the transfer. Please try again.');
        redirect('transfers/index.php');
    }

    log_activity('Transfer', 'complete', $transferId,
        'Completed transfer of ' . $transfer['asset_code'] . ' to ' . ($transfer['to_branch'] ?: '-'));
    set_flash('success', 'Transfer completed and asset moved.');
    redirect('transfers/index.php');
}

redirect('transfers/index.php');
