<?php
/**
 * Departments - create a new department.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('departments');

$errors = [];
$branches = db_all('SELECT id, branch_name FROM branches ORDER BY branch_name');

$form = [
    'dept_code'       => '',
    'department_name' => '',
    'branch_id'       => '',
    'dept_head'       => '',
    'description'     => '',
    'status'          => 'active',
];

if (is_post()) {
    csrf_verify();

    $form['dept_code']       = strtoupper(post('dept_code'));
    $form['department_name'] = post('department_name');
    $form['branch_id']       = (int) post('branch_id');
    $form['dept_head']       = post('dept_head');
    $form['description']     = post('description');
    $form['status']          = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    if ($form['dept_code'] === '') {
        $form['dept_code'] = generate_next_code('departments', 'dept_code', 'DP-', 2);
    }
    $form['dept_code'] = strtoupper($form['dept_code']);

    if ($form['dept_code'] === '') {
        $errors[] = 'Department code is required.';
    } elseif (mb_strlen($form['dept_code']) > 20) {
        $errors[] = 'Department code must not exceed 20 characters.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM departments WHERE dept_code = ?', [$form['dept_code']]) > 0) {
        $errors[] = 'That department code is already in use.';
    }

    if ($form['department_name'] === '') {
        $errors[] = 'Department name is required.';
    }

    if ($form['branch_id'] > 0
        && (int) db_value('SELECT COUNT(*) FROM branches WHERE id = ?', [$form['branch_id']]) === 0) {
        $errors[] = 'The selected branch does not exist.';
    }

    if (!$errors) {
        db_run(
            'INSERT INTO departments (dept_code, department_name, branch_id, dept_head, description, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $form['dept_code'],
                $form['department_name'],
                $form['branch_id'] > 0 ? $form['branch_id'] : null,
                null_if_empty($form['dept_head']),
                null_if_empty($form['description']),
                $form['status'],
            ]
        );
        $newId = (int) db()->lastInsertId();
        log_activity('Department', 'create', $newId, 'Added department ' . $form['dept_code'] . ' - ' . $form['department_name']);
        set_flash('success', 'Department "' . $form['department_name'] . '" was created successfully.');
        redirect('departments/index.php');
    }
}

$page_title    = 'Add Department';
$page_subtitle = 'Create a new department';
$active_menu   = 'departments';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('departments/index.php') ?>">Departments</a></li>
                <li class="breadcrumb-item active">Add Department</li>
            </ol>
        </nav>
        <h2>Add Department</h2>
        <p>Leave the department code empty to generate the next sequential code automatically</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('departments/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to list</a>
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

<form method="post" action="<?= url('departments/create.php') ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-teal" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-sitemap"></i></div>
            <div>
                <h3 class="mb-0">Department details</h3>
                <p class="sub mb-0">Identification and placement</p>
            </div>
        </div>
        <div class="card-body">
            <div class="form-section-title">Identification</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Department Code</label>
                    <input type="text" class="form-control" name="dept_code" maxlength="20"
                           value="<?= e($form['dept_code']) ?>" placeholder="Auto (DP-01)">
                    <div class="form-text">Leave empty to auto-generate.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Department Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="department_name" maxlength="120" required
                           value="<?= e($form['department_name']) ?>" placeholder="e.g. IT Department">
                </div>
            </div>

            <div class="form-section-title mt-4">Placement</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Branch</label>
                    <select class="form-select" name="branch_id">
                        <option value="">All branches (not branch specific)</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $form['branch_id']) ?>><?= e($b['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department Head</label>
                    <input type="text" class="form-control" name="dept_head" maxlength="120"
                           value="<?= e($form['dept_head']) ?>" placeholder="Head of department">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" name="description" maxlength="255"
                           value="<?= e($form['description']) ?>" placeholder="Short description">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active"<?= selected('active', $form['status']) ?>>Active</option>
                        <option value="inactive"<?= selected('inactive', $form['status']) ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('departments/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Save Department</button>
        </div>
    </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
