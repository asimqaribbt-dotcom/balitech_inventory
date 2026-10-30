<?php
/**
 * Categories - create a new equipment group or sub-category.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('masters');

$errors = [];
$groups = db_all('SELECT id, category_name FROM asset_categories WHERE parent_id IS NULL ORDER BY category_name');

$form = [
    'category_code'       => '',
    'category_name'       => '',
    'parent_id'           => '',
    'description'         => '',
    'low_stock_threshold' => 5,
    'status'              => 'active',
];

if (is_post()) {
    csrf_verify();

    $form['category_code']       = strtoupper(post('category_code'));
    $form['category_name']       = post('category_name');
    $form['parent_id']           = (int) post('parent_id');
    $form['description']         = post('description');
    $form['low_stock_threshold'] = (int) post('low_stock_threshold');
    $form['status']              = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    if ($form['category_code'] === '') {
        $form['category_code'] = generate_next_code('asset_categories', 'category_code', 'CAT-', 2);
    }
    $form['category_code'] = strtoupper($form['category_code']);

    if ($form['category_code'] === '') {
        $errors[] = 'Category code is required.';
    } elseif (mb_strlen($form['category_code']) > 20) {
        $errors[] = 'Category code must not exceed 20 characters.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM asset_categories WHERE category_code = ?', [$form['category_code']]) > 0) {
        $errors[] = 'That category code is already in use.';
    }

    if ($form['category_name'] === '') {
        $errors[] = 'Category name is required.';
    }

    if ($form['parent_id'] > 0) {
        $parent = db_row('SELECT id, parent_id FROM asset_categories WHERE id = ?', [$form['parent_id']]);
        if (!$parent) {
            $errors[] = 'The selected parent group does not exist.';
        } elseif ($parent['parent_id'] !== null) {
            $errors[] = 'Only top level groups can be used as a parent (depth is limited to two levels).';
        }
    }

    if ($form['low_stock_threshold'] < 0) {
        $errors[] = 'Low stock threshold cannot be negative.';
    }

    if (!$errors) {
        db_run(
            'INSERT INTO asset_categories (category_code, category_name, parent_id, description, low_stock_threshold, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $form['category_code'],
                $form['category_name'],
                $form['parent_id'] > 0 ? $form['parent_id'] : null,
                null_if_empty($form['description']),
                $form['low_stock_threshold'],
                $form['status'],
            ]
        );
        $newId = (int) db()->lastInsertId();
        log_activity('Category', 'create', $newId, 'Added category ' . $form['category_code'] . ' - ' . $form['category_name']);
        set_flash('success', 'Category "' . $form['category_name'] . '" was created successfully.');
        redirect('categories/index.php');
    }
}

$page_title    = 'Add Category';
$page_subtitle = 'Create an equipment group or sub-category';
$active_menu   = 'categories';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('categories/index.php') ?>">Categories</a></li>
                <li class="breadcrumb-item active">Add Category</li>
            </ol>
        </nav>
        <h2>Add Category</h2>
        <p>Leave the parent empty to create a top level equipment group</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('categories/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to list</a>
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

<form method="post" action="<?= url('categories/create.php') ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-layer-group"></i></div>
            <div>
                <h3 class="mb-0">Category details</h3>
                <p class="sub mb-0">Identification and hierarchy</p>
            </div>
        </div>
        <div class="card-body">
            <div class="form-section-title">Identification</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Category Code</label>
                    <input type="text" class="form-control" name="category_code" maxlength="20"
                           value="<?= e($form['category_code']) ?>" placeholder="Auto (CAT-01)">
                    <div class="form-text">Leave empty to auto-generate.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Category Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="category_name" maxlength="120" required
                           value="<?= e($form['category_name']) ?>" placeholder="e.g. Laptops">
                </div>
            </div>

            <div class="form-section-title mt-4">Hierarchy</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Parent Group</label>
                    <select class="form-select" name="parent_id">
                        <option value="">None (top level equipment group)</option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= (int) $g['id'] ?>"<?= selected($g['id'], $form['parent_id']) ?>><?= e($g['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Only top level groups can be a parent (two level hierarchy).</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Low Stock Threshold</label>
                    <input type="number" class="form-control" name="low_stock_threshold" min="0" step="1"
                           value="<?= (int) $form['low_stock_threshold'] ?>">
                </div>
                <div class="col-md-3">
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
            <a class="btn btn-outline-brand" href="<?= url('categories/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Save Category</button>
        </div>
    </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
