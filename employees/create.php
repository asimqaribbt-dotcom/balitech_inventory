<?php
/**
 * Employees - create a new employee.
 * The department select is populated through the app.js cascade contract.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('employees');

$errors = [];
$branches = db_all('SELECT id, branch_name FROM branches ORDER BY branch_name');

$form = [
    'emp_code'       => '',
    'employee_name'  => '',
    'branch_id'      => '',
    'department_id'  => '',
    'designation'    => '',
    'contact_number' => '',
    'email'          => '',
    'joining_date'   => '',
    'status'         => 'active',
];

if (is_post()) {
    csrf_verify();

    $form['emp_code']       = strtoupper(post('emp_code'));
    $form['employee_name']  = post('employee_name');
    $form['branch_id']      = (int) post('branch_id');
    $form['department_id']  = (int) post('department_id');
    $form['designation']    = post('designation');
    $form['contact_number'] = post('contact_number');
    $form['email']          = post('email');
    $form['joining_date']   = post('joining_date');
    $form['status']         = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    if ($form['emp_code'] === '') {
        $form['emp_code'] = generate_next_code('employees', 'emp_code', 'EMP-', 4);
    }
    $form['emp_code'] = strtoupper($form['emp_code']);

    if ($form['emp_code'] === '') {
        $errors[] = 'Employee code is required.';
    } elseif (mb_strlen($form['emp_code']) > 20) {
        $errors[] = 'Employee code must not exceed 20 characters.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM employees WHERE emp_code = ?', [$form['emp_code']]) > 0) {
        $errors[] = 'That employee code is already in use.';
    }

    if ($form['employee_name'] === '') {
        $errors[] = 'Employee name is required.';
    }
    if ($form['email'] !== '' && !is_valid_email($form['email'])) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($form['joining_date'] !== '' && !is_valid_date($form['joining_date'])) {
        $errors[] = 'Joining date must be a valid date (YYYY-MM-DD).';
    }
    if ($form['branch_id'] > 0
        && (int) db_value('SELECT COUNT(*) FROM branches WHERE id = ?', [$form['branch_id']]) === 0) {
        $errors[] = 'The selected branch does not exist.';
    }
    if ($form['department_id'] > 0
        && (int) db_value('SELECT COUNT(*) FROM departments WHERE id = ?', [$form['department_id']]) === 0) {
        $errors[] = 'The selected department does not exist.';
    }

    if (!$errors) {
        db_run(
            'INSERT INTO employees
                (emp_code, employee_name, branch_id, department_id, designation, contact_number, email, joining_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $form['emp_code'],
                $form['employee_name'],
                $form['branch_id'] > 0 ? $form['branch_id'] : null,
                $form['department_id'] > 0 ? $form['department_id'] : null,
                null_if_empty($form['designation']),
                null_if_empty($form['contact_number']),
                null_if_empty($form['email']),
                null_if_empty($form['joining_date']),
                $form['status'],
            ]
        );
        $newId = (int) db()->lastInsertId();
        log_activity('Employee', 'create', $newId, 'Added employee ' . $form['emp_code'] . ' - ' . $form['employee_name']);
        set_flash('success', 'Employee "' . $form['employee_name'] . '" was created successfully.');
        redirect('employees/index.php');
    }
}

$page_title    = 'Add Employee';
$page_subtitle = 'Register a new staff member';
$active_menu   = 'employees';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('employees/index.php') ?>">Employees</a></li>
                <li class="breadcrumb-item active">Add Employee</li>
            </ol>
        </nav>
        <h2>Add Employee</h2>
        <p>Leave the employee code empty to generate the next sequential code automatically</p>
    </div>
    <div class="page-actions">
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

<form method="post" action="<?= url('employees/create.php') ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-user-plus"></i></div>
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
                           value="<?= e($form['emp_code']) ?>" placeholder="Auto (EMP-0001)">
                    <div class="form-text">Leave empty to auto-generate.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Employee Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="employee_name" maxlength="120" required
                           value="<?= e($form['employee_name']) ?>" placeholder="Full name">
                </div>
            </div>

            <div class="form-section-title mt-4">Placement</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Branch</label>
                    <select class="form-select" name="branch_id" id="branch_id">
                        <option value="">-- Select branch --</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $form['branch_id']) ?>><?= e($b['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_id" id="department_id"
                            data-cascade="departments" data-url="api/options.php"
                            data-parent="#branch_id" data-placeholder="-- Select department --"
                            data-selected="<?= e($form['department_id']) ?>"></select>
                    <div class="form-text">Departments are filtered by the selected branch.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Designation</label>
                    <input type="text" class="form-control" name="designation" maxlength="120"
                           value="<?= e($form['designation']) ?>" placeholder="e.g. Network Engineer">
                </div>
            </div>

            <div class="form-section-title mt-4">Contact &amp; employment</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" name="contact_number" maxlength="30"
                           value="<?= e($form['contact_number']) ?>" placeholder="+92-...">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" maxlength="120"
                           value="<?= e($form['email']) ?>" placeholder="employee@balitech.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Joining Date</label>
                    <input type="date" class="form-control" name="joining_date"
                           value="<?= e($form['joining_date']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active"<?= selected('active', $form['status']) ?>>Active</option>
                        <option value="inactive"<?= selected('inactive', $form['status']) ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('employees/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Save Employee</button>
        </div>
    </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
