<?php
/**
 * Inventory - delete endpoint (POST only).
 * Blocked while the asset has an active assignment or open maintenance
 * records. Deleting cascades to assignment / transfer / maintenance / stock
 * history through the foreign keys.
 */
require_once __DIR__ . '/../init.php';
require_login();

if (!is_post()) {
    redirect('inventory/index.php');
}

csrf_verify();
require_ability('inventory');

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
    deny_access('You may only delete assets of your own branch.');
}

$activeAssignments = (int) db_value(
    'SELECT COUNT(*) FROM asset_assignments WHERE asset_id = ? AND assignment_status = "assigned"',
    [$id]
);
$openMaintenance = (int) db_value(
    'SELECT COUNT(*) FROM maintenance_records WHERE asset_id = ? AND status IN ("reported","in_progress")',
    [$id]
);

if ($activeAssignments > 0 || $openMaintenance > 0) {
    $reason = $activeAssignments > 0
        ? 'It still has an active assignment.'
        : 'It has open maintenance records.';
    set_flash('danger', 'Cannot delete ' . $asset['asset_code'] . '. ' . $reason . ' Please resolve it first.');
    redirect('inventory/view.php?id=' . $id);
}

try {
    db()->beginTransaction();
    db_run('DELETE FROM inventory WHERE id = ?', [$id]);
    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log('[BALITECH][inventory/delete] ' . $e->getMessage());
    set_flash('danger', 'The asset could not be deleted. Please try again.');
    redirect('inventory/view.php?id=' . $id);
}

log_activity('Inventory', 'delete', $id, 'Deleted asset ' . $asset['asset_code'] . ' - ' . $asset['equipment_name']);
set_flash('success', 'Asset ' . $asset['asset_code'] . ' was deleted.');
redirect('inventory/index.php');
