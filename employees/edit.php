<?php
/**
 * Employees - edit an existing employee.
 * The department select lists all departments grouped per branch so the
 * current value is always shown correctly.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('employees');

$id = (int) get_param('id');
$employee = db_row('SELECT * FROM employees WHERE id = ?', [$id]);
if (!$employee) {
    set_flash('danger', 'The requested employee could not be found.');
    redirect('employees/index.php');
}

$errors   = [];
$branches = db_all('SELECT id, branch_name FROM branches ORDER BY branch_name');

$deptOptions = db_all(
    'SELECT d.id, d.department_name, b.branch_name
       FROM departments d
       LEFT JOIN branches b ON b.id = d.branch_id
      ORDER BY b.branch_name, d.department_name'
);
$deptGroups = [];
foreach ($deptOptions as $d) {
    $key = $d['branch_name'] ?: 'All branches';
    $deptGroups[$key][] = $d;
}

if (is_post()) {
    csrf_verify();

    $employee['emp_code']       = strtoupper(post('emp_code'));
    $employee['employee_name']  = post('employee_name');
    $employee['branch_id']      = (int) post('branch_id');
    $employee['department_id']  = (int) post('department_id');
    $employee['designation']    = post('designation');
    $employee['contact_number'] = post('contact_number');
    $employee['email']          = post('email');
    $employee['joining_date']   = post('joining_date');
    $employee['status']         = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    if ($employee['emp_code'] === '') {
        $employee['emp_code'] = generate_next_code('employees', 'emp_code', 'EMP-', 4);
    }
    $employee['emp_code'] = strtoupper($employee['emp_code']);

    if ($employee['emp_code'] === '') {
        $errors[] = 'Employee code is required.';
    } elseif (mb_strlen($employee['emp_code']) > 20) {
        $errors[] = 'Employee code must not exceed 20 characters.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM employees WHERE emp_code = ? AND id <> ?', [$employee['emp_code'], $id]) > 0) {
        $errors[] = 'That employee code is already in use by another employee.';
    }

    if ($employee['employee_name'] === '') {
        $errors[] = 'Employee name is required.';
    }
    if ($employee['email'] !== '' && !is_valid_email($employee['email'])) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($employee['joining_date'] !== '' && !is_valid_date($employee['joining_date'])) {
        $errors[] = 'Joining date must be a valid date (YYYY-MM-DD).';
    }
    if ($employee['branch_id'] > 0
        && (int) db_value('SELECT COUNT(*) FROM branches WHERE id = ?', [$employee['branch_id']]) === 0) {
        $errors[] = 'The selected branch does not exist.';
    }
    if ($employee['department_id'] > 0
        && (int) db_value('SELECT COUNT(*) FROM departments WHERE id = ?', [$employee['department_id']]) === 0) {
        $errors[] = 'The selected department does not exist.';
    }

    if (!$errors) {
        db_run(
            'UPDATE employees
                SET emp_code = ?, employee_name = ?, branch_id = ?, department_id = ?, designation = ?,
                    contact_number = ?, email = ?, joining_date = ?, status = ?
              WHERE id = ?',
            [
                $employee['emp_code'],
                $employee['employee_name'],
                $employee['branch_id'] > 0 ? $employee['branch_id'] : null,
                $employee['department_id'] > 0 ? $employee['department_id'] : null,
                null_if_empty($employee['designation']),
                null_if_empty($employee['contact_number']),
                null_if_empty($employee['email']),
                null_if_empty($employee['joining_date']),
                $employee['status'],
                $id,
            ]
        );
        log_activity('Employee', 'update', $id, 'Updated employee ' . $employee['emp_code'] . ' - ' . $employee['employee_name']);
        set_flash('success', 'Employee "' . $employee['employee_name'] . '" was updated successfully.');
        redirect('employees/index.php');
    }
}

$page_title    = 'Edit Employee';
$page_subtitle = 'Update employee details';
$active_menu   = 'employees';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('employees/index.php') ?>">Employees</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
        <h2>Edit Employee</h2>
        <p><?= e($employee['emp_code']) ?> &middot; <?= e($employee['employee_name']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('employees/view.php?id=' . $id) ?>"><i class="fa-solid fa-eye me-2"></i>View</a>
        <a class="btn btn-outline-brand" href="<?= url('employees/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to list</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger app-alert">
    <i class="fa-solid fa-circle-xmark me-2"></i><b>Please fix the following:</b>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="<?= url('employees/edit.php?id=' . $id) ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-user-pen"></i></div>
            <div>
                <h3 class="mb-0">Employee details</h3>
                <p class="sub mb-0">Identity and placement</p>
            </div>
        </div>
        <div class="card-body">
            <div class="form-section-title">Identification</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Employee Code</label>
                    <input type="text" class="form-control" name="emp_code" maxlength="20"
                           value="<?= e($employee['emp_code']) ?>">
                    <div class="form-text">Leave empty to auto-generate.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Employee Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="employee_name" maxlength="120" required
                           value="<?= e($employee['employee_name']) ?>">
                </div>
            </div>

            <div class="form-section-title mt-4">Placement</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Branch</label>
                    <select class="form-select" name="branch_id">
                        <option value="">-- Select branch --</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $employee['branch_id']) ?>><?= e($b['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_id">
                        <option value="">-- Select department --</option>
                        <?php foreach ($deptGroups as $groupName => $group): ?>
                        <optgroup label="<?= e($groupName) ?>">
                            <?php foreach ($group as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"<?= selected($d['id'], $employee['department_id']) ?>><?= e($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Designation</label>
                    <input type="text" class="form-control" name="designation" maxlength="120"
                           value="<?= e($employee['designation']) ?>">
                </div>
            </div>

            <div class="form-section-title mt-4">Contact &amp; employment</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" name="contact_number" maxlength="30"
                           value="<?= e($employee['contact_number']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" maxlength="120"
                           value="<?= e($employee['email']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Joining Date</label>
                    <input type="date" class="form-control" name="joining_date"
                           value="<?= e($employee['joining_date']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active"<?= selected('active', $employee['status']) ?>>Active</option>
                        <option value="inactive"<?= selected('inactive', $employee['status']) ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('employees/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Update Employee</button>
        </div>
    </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
