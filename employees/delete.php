<?php
/**
 * Employees - delete an employee (POST only).
 * Blocked while the employee still holds assigned assets.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('employees');

if (!is_post()) {
    redirect('employees/index.php');
}

csrf_verify();

$id = (int) post('id');
$employee = db_row('SELECT * FROM employees WHERE id = ?', [$id]);
if (!$employee) {
    set_flash('danger', 'The requested employee could not be found.');
    redirect('employees/index.php');
}

$heldAssets = (int) db_value(
    "SELECT COUNT(*) FROM asset_assignments WHERE employee_id = ? AND assignment_status = 'assigned'", [$id]);

if ($heldAssets > 0) {
    set_flash('danger', 'Cannot delete "' . $employee['employee_name'] . '": the employee still holds '
        . $heldAssets . ' assigned asset(s). Return them first.');
    redirect('employees/index.php');
}

$historyCount = (int) db_value('SELECT COUNT(*) FROM asset_assignments WHERE employee_id = ?', [$id]);

try {
    db_run('DELETE FROM employees WHERE id = ?', [$id]);
    log_activity('Employee', 'delete', $id, 'Deleted employee ' . $employee['emp_code'] . ' - ' . $employee['employee_name']);
    set_flash('warning', 'Employee "' . $employee['employee_name'] . '" was deleted. '
        . $historyCount . ' assignment history record(s) were removed along with the employee.');
} catch (PDOException $e) {
    error_log('[BALITECH][employee delete] ' . $e->getMessage());
    set_flash('danger', 'This employee is referenced by other records and could not be deleted.');
}

redirect('employees/index.php');
