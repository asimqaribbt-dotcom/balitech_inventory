<?php
/**
 * Brands - create a new equipment brand.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('masters');

$errors = [];

$form = [
    'brand_name'  => '',
    'description' => '',
    'status'      => 'active',
];

if (is_post()) {
    csrf_verify();

    $form['brand_name']  = post('brand_name');
    $form['description'] = post('description');
    $form['status']      = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    if ($form['brand_name'] === '') {
        $errors[] = 'Brand name is required.';
    } elseif (mb_strlen($form['brand_name']) > 100) {
        $errors[] = 'Brand name must not exceed 100 characters.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM brands WHERE brand_name = ?', [$form['brand_name']]) > 0) {
        $errors[] = 'That brand name already exists.';
    }

    if (!$errors) {
        db_run(
            'INSERT INTO brands (brand_name, description, status) VALUES (?, ?, ?)',
            [$form['brand_name'], null_if_empty($form['description']), $form['status']]
        );
        $newId = (int) db()->lastInsertId();
        log_activity('Brand', 'create', $newId, 'Added brand ' . $form['brand_name']);
        set_flash('success', 'Brand "' . $form['brand_name'] . '" was created successfully.');
        redirect('brands/index.php');
    }
}

$page_title    = 'Add Brand';
$page_subtitle = 'Register a new equipment brand';
$active_menu   = 'brands';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('brands/index.php') ?>">Brands</a></li>
                <li class="breadcrumb-item active">Add Brand</li>
            </ol>
        </nav>
        <h2>Add Brand</h2>
        <p>Manufacturer / supplier name used across the asset register</p>
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

<form method="post" action="<?= url('brands/create.php') ?>" class="needs-validation" novalidate>
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
                           value="<?= e($form['brand_name']) ?>" placeholder="e.g. Dell">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active"<?= selected('active', $form['status']) ?>>Active</option>
                        <option value="inactive"<?= selected('inactive', $form['status']) ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" name="description" maxlength="255"
                           value="<?= e($form['description']) ?>" placeholder="Short description">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('brands/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Save Brand</button>
        </div>
    </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
