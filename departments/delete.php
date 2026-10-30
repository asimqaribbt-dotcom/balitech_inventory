<?php
/**
 * Departments - delete a department (POST only).
 * Blocked when employees or assets still reference it.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('departments');

if (!is_post()) {
    redirect('departments/index.php');
}

csrf_verify();

$id = (int) post('id');
$department = db_row('SELECT * FROM departments WHERE id = ?', [$id]);
if (!$department) {
    set_flash('danger', 'The requested department could not be found.');
    redirect('departments/index.php');
}

$empCount   = (int) db_value('SELECT COUNT(*) FROM employees WHERE department_id = ?', [$id]);
$assetCount = (int) db_value('SELECT COUNT(*) FROM inventory WHERE department_id = ?', [$id]);

if ($empCount > 0 || $assetCount > 0) {
    set_flash('danger', 'Cannot delete "' . $department['department_name'] . '": it still has '
        . $empCount . ' employee(s) and ' . $assetCount . ' asset(s) linked to it.');
    redirect('departments/index.php');
}

try {
    db_run('DELETE FROM departments WHERE id = ?', [$id]);
    log_activity('Department', 'delete', $id, 'Deleted department ' . $department['dept_code'] . ' - ' . $department['department_name']);
    set_flash('success', 'Department "' . $department['department_name'] . '" was deleted.');
} catch (PDOException $e) {
    error_log('[BALITECH][department delete] ' . $e->getMessage());
    set_flash('danger', 'This department is referenced by other records and could not be deleted.');
}

redirect('departments/index.php');
