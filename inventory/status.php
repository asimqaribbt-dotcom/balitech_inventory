<?php
/**
 * Inventory - status update endpoint (POST only, no output).
 * Accepts asset_id + status + condition + remarks, updates the asset,
 * records a stock adjustment when the asset leaves service and logs the
 * activity. Used by the inline "Update Status" modal on index.php / view.php.
 */
require_once __DIR__ . '/../init.php';
require_login();

if (!is_post()) {
    redirect('inventory/index.php');
}

csrf_verify();

if (!can('inventory')) {
    deny_access('You do not have permission to change asset status.');
}

$user  = current_user();
$scope = rbac_scope('i');

$id = (int) post('asset_id');
$asset = $id > 0
    ? db_row("SELECT i.* FROM inventory i WHERE i.id = :id $scope[sql]", array_merge(['id' => $id], $scope['params']))
    : null;

if (!$asset) {
    set_flash('danger', 'The requested asset was not found.');
    redirect('inventory/index.php');
}
if (is_role('branch_staff') && (int) $asset['branch_id'] !== (int) $user['branch_id']) {
    deny_access('You may only update assets of your own branch.');
}

$statuses   = asset_statuses();
$conditions = asset_conditions();

$status    = post('status');
$condition = post('condition');
$remarks   = post('remarks');

if (!isset($statuses[$status])) {
    set_flash('danger', 'Please choose a valid status.');
    redirect('inventory/view.php?id=' . $id);
}
if ($condition === '' || !isset($conditions[$condition])) {
    $condition = (string) $asset['asset_condition'];
}

$oldStatus = (string) $asset['status'];
$newRemarks = $asset['remarks'];
if ($remarks !== '') {
    $newRemarks = trim(($newRemarks !== null && $newRemarks !== '' ? $newRemarks . "\n" : '') . $remarks);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    db_run(
        'UPDATE inventory SET status = ?, asset_condition = ?, remarks = ? WHERE id = ?',
        [$status, $condition, null_if_empty($newRemarks), $id]
    );

    if ($oldStatus !== $status && in_array($status, ['damaged', 'under_maintenance', 'retired'], true)) {
        db_run(
            'INSERT INTO stock_transactions
                (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes, performed_by, transaction_date)
             VALUES (?, ?, "adjustment", 1, ?, ?, ?, ?, ?)',
            [
                $id,
                (int) $asset['category_id'],
                $asset['branch_id'] !== null ? (int) $asset['branch_id'] : null,
                'ADJ-' . date('Y') . '-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT),
                'Status changed to ' . $statuses[$status],
                current_user_id(),
                date('Y-m-d'),
            ]
        );
    }

    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[BALITECH][inventory/status] ' . $e->getMessage());
    set_flash('danger', 'The status could not be updated. Please try again.');
    redirect('inventory/view.php?id=' . $id);
}

log_activity(
    'Inventory',
    'update',
    $id,
    'Status changed from ' . ($statuses[$oldStatus] ?? $oldStatus) . ' to ' . $statuses[$status]
    . ' for ' . $asset['asset_code']
);
set_flash('success', 'Status for ' . $asset['asset_code'] . ' was updated to ' . $statuses[$status] . '.');

if (post('redirect') === 'index') {
    redirect('inventory/index.php');
}
redirect('inventory/view.php?id=' . $id);
