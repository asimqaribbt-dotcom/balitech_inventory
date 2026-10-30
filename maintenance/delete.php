<?php
/**
 * Maintenance - delete a maintenance record (POST only).
 * If the linked asset is under maintenance and no other open record
 * remains, it is returned to the available pool.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('maintenance');

if (!is_post()) {
    redirect('maintenance/index.php');
}
csrf_verify();

$scope = rbac_scope('i');
$id    = (int) post('id');

$record = db_row(
    "SELECT m.id, m.asset_id, i.asset_code, i.status AS asset_status
       FROM maintenance_records m
       JOIN inventory i ON i.id = m.asset_id
      WHERE m.id = :id " . $scope['sql'],
    ['id' => $id] + $scope['params']
);

if (!$record) {
    set_flash('danger', 'Maintenance record not found.');
    redirect('maintenance/index.php');
}

$assetId = (int) $record['asset_id'];
$pdo     = db();

try {
    $pdo->beginTransaction();

    db_run('DELETE FROM maintenance_records WHERE id = ?', [$id]);

    $openRecords = (int) db_value(
        'SELECT COUNT(*) FROM maintenance_records WHERE asset_id = ? AND status IN ("reported", "in_progress")',
        [$assetId]
    );
    if ($record['asset_status'] === 'under_maintenance' && $openRecords === 0) {
        db_run('UPDATE inventory SET status = "available" WHERE id = ? AND status = "under_maintenance"', [$assetId]);
    }

    log_activity('Maintenance', 'delete', $id, 'Deleted maintenance record #' . $id . ' for ' . $record['asset_code']);

    $pdo->commit();
    set_flash('success', 'Maintenance record #' . $id . ' deleted.');
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[BALITECH][maintenance/delete] ' . $e->getMessage());
    set_flash('danger', 'Unable to delete the maintenance record. Please try again.');
}

redirect('maintenance/index.php');
