<?php
/**
 * Brands - edit an existing brand.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('masters');

$id = (int) get_param('id');
$brand = db_row('SELECT * FROM brands WHERE id = ?', [$id]);
if (!$brand) {
    set_flash('danger', 'The requested brand could not be found.');
    redirect('brands/index.php');
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $brand['brand_name']  = post('brand_name');
    $brand['description'] = post('description');
    $brand['status']      = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    if ($brand['brand_name'] === '') {
        $errors[] = 'Brand name is required.';
    } elseif (mb_strlen($brand['brand_name']) > 100) {
        $errors[] = 'Brand name must not exceed 100 characters.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM brands WHERE brand_name = ? AND id <> ?', [$brand['brand_name'], $id]) > 0) {
        $errors[] = 'That brand name already exists.';
    }

    if (!$errors) {
        db_run(
            'UPDATE brands SET brand_name = ?, description = ?, status = ? WHERE id = ?',
            [$brand['brand_name'], null_if_empty($brand['description']), $brand['status'], $id]
        );
        log_activity('Brand', 'update', $id, 'Updated brand ' . $brand['brand_name']);
        set_flash('success', 'Brand "' . $brand['brand_name'] . '" was updated successfully.');
        redirect('brands/index.php');
    }
}

$page_title    = 'Edit Brand';
$page_subtitle = 'Update brand details';
$active_menu   = 'brands';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('brands/index.php') ?>">Brands</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
        <h2>Edit Brand</h2>
        <p><?= e($brand['brand_name']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('brands/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to list</a>
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

<form method="post" action="<?= url('brands/edit.php?id=' . $id) ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-navy" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-tags"></i></div>
            <div>
                <h3 class="mb-0">Brand details</h3>
                <p class="sub mb-0">Name and description</p>
            </div>
        </div>
        <div class="card-body">
            <div class="form-section-title">Identification</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Brand Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="brand_name" maxlength="100" required
                           value="<?= e($brand['brand_name']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active"<?= selected('active', $brand['status']) ?>>Active</option>
                        <option value="inactive"<?= selected('inactive', $brand['status']) ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" name="description" maxlength="255"
                           value="<?= e($brand['description']) ?>">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('brands/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Update Brand</button>
        </div>
    </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
