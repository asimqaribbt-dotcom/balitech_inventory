<?php
/**
 * Branches - create a new branch.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('branches');

$errors = [];

$form = [
    'branch_code'    => '',
    'branch_name'    => '',
    'city'           => '',
    'address'        => '',
    'branch_manager' => '',
    'contact_number' => '',
    'email'          => '',
    'status'         => 'active',
];

if (is_post()) {
    csrf_verify();

    $form['branch_code']    = strtoupper(post('branch_code'));
    $form['branch_name']    = post('branch_name');
    $form['city']           = post('city');
    $form['address']        = post('address');
    $form['branch_manager'] = post('branch_manager');
    $form['contact_number'] = post('contact_number');
    $form['email']          = post('email');
    $form['status']         = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    if ($form['branch_code'] === '') {
        $form['branch_code'] = generate_next_code('branches', 'branch_code', 'BR-', 2);
    }
    $form['branch_code'] = strtoupper($form['branch_code']);

    if ($form['branch_code'] === '') {
        $errors[] = 'Branch code is required.';
    } elseif (mb_strlen($form['branch_code']) > 20) {
        $errors[] = 'Branch code must not exceed 20 characters.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM branches WHERE branch_code = ?', [$form['branch_code']]) > 0) {
        $errors[] = 'That branch code is already in use.';
    }

    if ($form['branch_name'] === '') {
        $errors[] = 'Branch name is required.';
    }
    if ($form['city'] === '') {
        $errors[] = 'City is required.';
    }
    if ($form['email'] !== '' && !is_valid_email($form['email'])) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!$errors) {
        db_run(
            'INSERT INTO branches (branch_code, branch_name, city, address, branch_manager, contact_number, email, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $form['branch_code'],
                $form['branch_name'],
                $form['city'],
                null_if_empty($form['address']),
                null_if_empty($form['branch_manager']),
                null_if_empty($form['contact_number']),
                null_if_empty($form['email']),
                $form['status'],
            ]
        );
        $newId = (int) db()->lastInsertId();
        log_activity('Branch', 'create', $newId, 'Added branch ' . $form['branch_code'] . ' - ' . $form['branch_name']);
        set_flash('success', 'Branch "' . $form['branch_name'] . '" was created successfully.');
        redirect('branches/index.php');
    }
}

$page_title    = 'Add Branch';
$page_subtitle = 'Register a new company location';
$active_menu   = 'branches';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('branches/index.php') ?>">Branches</a></li>
                <li class="breadcrumb-item active">Add Branch</li>
            </ol>
        </nav>
        <h2>Add Branch</h2>
        <p>Leave the branch code empty to generate the next sequential code automatically</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('branches/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to list</a>
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

<form method="post" action="<?= url('branches/create.php') ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-navy" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-building"></i></div>
            <div>
                <h3 class="mb-0">Branch details</h3>
                <p class="sub mb-0">Location and contact information</p>
            </div>
        </div>
        <div class="card-body">
            <div class="form-section-title">Identification</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Branch Code</label>
                    <input type="text" class="form-control" name="branch_code" maxlength="20"
                           value="<?= e($form['branch_code']) ?>" placeholder="Auto (BR-01)">
                    <div class="form-text">Leave empty to auto-generate.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Branch Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="branch_name" maxlength="120" required
                           value="<?= e($form['branch_name']) ?>" placeholder="e.g. Karachi Branch">
                </div>
            </div>

            <div class="form-section-title mt-4">Location &amp; contact</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">City <span class="req">*</span></label>
                    <input type="text" class="form-control" name="city" maxlength="80" required
                           value="<?= e($form['city']) ?>" placeholder="e.g. Lahore">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Branch Manager</label>
                    <input type="text" class="form-control" name="branch_manager" maxlength="120"
                           value="<?= e($form['branch_manager']) ?>" placeholder="Manager name">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" name="contact_number" maxlength="30"
                           value="<?= e($form['contact_number']) ?>" placeholder="+92-...">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" maxlength="120"
                           value="<?= e($form['email']) ?>" placeholder="branch@balitech.com">
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <input type="text" class="form-control" name="address" maxlength="255"
                           value="<?= e($form['address']) ?>" placeholder="Street, area, city">
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
            <a class="btn btn-outline-brand" href="<?= url('branches/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Save Branch</button>
        </div>
    </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
