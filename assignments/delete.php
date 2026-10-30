<?php
/**
 * Asset Assignment - delete a history row (the asset itself is untouched).
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('assignments');

if (!is_post()) {
    redirect('assignments/index.php');
}
csrf_verify();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

$assignmentId = (int) post('assignment_id', 0);

$assignment = db_row(
    "SELECT a.id, a.asset_id, i.asset_code
       FROM asset_assignments a
       JOIN inventory i ON i.id = a.asset_id
      WHERE a.id = :id $scopeSql",
    array_merge(['id' => $assignmentId], $scopeParams)
);

if (!$assignment) {
    deny_access('The requested assignment could not be found for your branch.');
}

db_run('DELETE FROM asset_assignments WHERE id = ?', [$assignmentId]);

log_activity('Assignment', 'delete', $assignmentId,
    'Deleted assignment history record for ' . $assignment['asset_code']);

set_flash('success', 'Assignment record deleted.');
redirect('assignments/index.php');
