<?php
/**
 * Branches - delete a branch (POST only).
 * Blocked when the branch still has departments or inventory rows.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('branches');

if (!is_post()) {
    redirect('branches/index.php');
}

csrf_verify();

$id = (int) post('id');
$branch = db_row('SELECT * FROM branches WHERE id = ?', [$id]);
if (!$branch) {
    set_flash('danger', 'The requested branch could not be found.');
    redirect('branches/index.php');
}

$deptCount = (int) db_value('SELECT COUNT(*) FROM departments WHERE branch_id = ?', [$id]);
$assetCount = (int) db_value('SELECT COUNT(*) FROM inventory WHERE branch_id = ?', [$id]);

if ($deptCount > 0 || $assetCount > 0) {
    set_flash('danger', 'Cannot delete "' . $branch['branch_name'] . '": it still has '
        . $deptCount . ' department(s) and ' . $assetCount . ' asset(s) linked to it.');
    redirect('branches/index.php');
}

try {
    db_run('DELETE FROM branches WHERE id = ?', [$id]);
    log_activity('Branch', 'delete', $id, 'Deleted branch ' . $branch['branch_code'] . ' - ' . $branch['branch_name']);
    set_flash('success', 'Branch "' . $branch['branch_name'] . '" was deleted.');
} catch (PDOException $e) {
    error_log('[BALITECH][branch delete] ' . $e->getMessage());
    set_flash('danger', 'This branch is referenced by other records and could not be deleted.');
}

redirect('branches/index.php');
