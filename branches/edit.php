<?php
/**
 * Branches - edit an existing branch.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('branches');

$id = (int) get_param('id');
$branch = db_row('SELECT * FROM branches WHERE id = ?', [$id]);
if (!$branch) {
    set_flash('danger', 'The requested branch could not be found.');
    redirect('branches/index.php');
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $branch['branch_code']    = strtoupper(post('branch_code'));
    $branch['branch_name']    = post('branch_name');
    $branch['city']           = post('city');
    $branch['address']        = post('address');
    $branch['branch_manager'] = post('branch_manager');
    $branch['contact_number'] = post('contact_number');
    $branch['email']          = post('email');
    $branch['status']         = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    if ($branch['branch_code'] === '') {
        $branch['branch_code'] = generate_next_code('branches', 'branch_code', 'BR-', 2);
    }
    $branch['branch_code'] = strtoupper($branch['branch_code']);

    if ($branch['branch_code'] === '') {
        $errors[] = 'Branch code is required.';
    } elseif (mb_strlen($branch['branch_code']) > 20) {
        $errors[] = 'Branch code must not exceed 20 characters.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM branches WHERE branch_code = ? AND id <> ?', [$branch['branch_code'], $id]) > 0) {
        $errors[] = 'That branch code is already in use by another branch.';
    }

    if ($branch['branch_name'] === '') {
        $errors[] = 'Branch name is required.';
    }
    if ($branch['city'] === '') {
        $errors[] = 'City is required.';
    }
    if ($branch['email'] !== '' && !is_valid_email($branch['email'])) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!$errors) {
        db_run(
            'UPDATE branches
                SET branch_code = ?, branch_name = ?, city = ?, address = ?, branch_manager = ?,
                    contact_number = ?, email = ?, status = ?
              WHERE id = ?',
            [
                $branch['branch_code'],
                $branch['branch_name'],
                $branch['city'],
                null_if_empty($branch['address']),
                null_if_empty($branch['branch_manager']),
                null_if_empty($branch['contact_number']),
                null_if_empty($branch['email']),
                $branch['status'],
                $id,
            ]
        );
        log_activity('Branch', 'update', $id, 'Updated branch ' . $branch['branch_code'] . ' - ' . $branch['branch_name']);
        set_flash('success', 'Branch "' . $branch['branch_name'] . '" was updated successfully.');
        redirect('branches/index.php');
    }
}

$page_title    = 'Edit Branch';
$page_subtitle = 'Update branch details and status';
$active_menu   = 'branches';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('branches/index.php') ?>">Branches</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
        <h2>Edit Branch</h2>
        <p><?= e($branch['branch_code']) ?> &middot; <?= e($branch['branch_name']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('branches/view.php?id=' . $id) ?>"><i class="fa-solid fa-eye me-2"></i>View</a>
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

<form method="post" action="<?= url('branches/edit.php?id=' . $id) ?>" class="needs-validation" novalidate>
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
                           value="<?= e($branch['branch_code']) ?>">
                    <div class="form-text">Leave empty to auto-generate.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Branch Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="branch_name" maxlength="120" required
                           value="<?= e($branch['branch_name']) ?>">
                </div>
            </div>

            <div class="form-section-title mt-4">Location &amp; contact</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">City <span class="req">*</span></label>
                    <input type="text" class="form-control" name="city" maxlength="80" required
                           value="<?= e($branch['city']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Branch Manager</label>
                    <input type="text" class="form-control" name="branch_manager" maxlength="120"
                           value="<?= e($branch['branch_manager']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" name="contact_number" maxlength="30"
                           value="<?= e($branch['contact_number']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" maxlength="120"
                           value="<?= e($branch['email']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <input type="text" class="form-control" name="address" maxlength="255"
                           value="<?= e($branch['address']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active"<?= selected('active', $branch['status']) ?>>Active</option>
                        <option value="inactive"<?= selected('inactive', $branch['status']) ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('branches/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Update Branch</button>
        </div>
    </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
