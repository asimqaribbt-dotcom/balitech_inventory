<?php
/**
 * Categories - edit an existing category.
 * Enforces a two level hierarchy: a category that has children cannot be
 * moved under another parent, and a category can never be its own parent.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('masters');

$id = (int) get_param('id');
$category = db_row('SELECT * FROM asset_categories WHERE id = ?', [$id]);
if (!$category) {
    set_flash('danger', 'The requested category could not be found.');
    redirect('categories/index.php');
}

$errors = [];

// Top level groups that can act as a parent, excluding this category itself
$groups = db_all(
    'SELECT id, category_name FROM asset_categories WHERE parent_id IS NULL AND id <> ? ORDER BY category_name',
    [$id]
);

$childCount  = (int) db_value('SELECT COUNT(*) FROM asset_categories WHERE parent_id = ?', [$id]);
$hasChildren = $childCount > 0;

if (is_post()) {
    csrf_verify();

    $category['category_code']       = strtoupper(post('category_code'));
    $category['category_name']       = post('category_name');
    $category['parent_id']           = $hasChildren ? 0 : (int) post('parent_id');
    $category['description']         = post('description');
    $category['low_stock_threshold'] = (int) post('low_stock_threshold');
    $category['status']              = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';

    if ($category['category_code'] === '') {
        $category['category_code'] = generate_next_code('asset_categories', 'category_code', 'CAT-', 2);
    }
    $category['category_code'] = strtoupper($category['category_code']);

    if ($category['category_code'] === '') {
        $errors[] = 'Category code is required.';
    } elseif (mb_strlen($category['category_code']) > 20) {
        $errors[] = 'Category code must not exceed 20 characters.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM asset_categories WHERE category_code = ? AND id <> ?', [$category['category_code'], $id]) > 0) {
        $errors[] = 'That category code is already in use by another category.';
    }

    if ($category['category_name'] === '') {
        $errors[] = 'Category name is required.';
    }

    if ($category['parent_id'] > 0) {
        if ($category['parent_id'] === $id) {
            $errors[] = 'A category cannot be its own parent.';
        } else {
            $parent = db_row('SELECT id, parent_id FROM asset_categories WHERE id = ?', [$category['parent_id']]);
            if (!$parent) {
                $errors[] = 'The selected parent group does not exist.';
            } elseif ($parent['parent_id'] !== null) {
                $errors[] = 'Only top level groups can be used as a parent (depth is limited to two levels).';
            }
        }
    }

    if ($category['low_stock_threshold'] < 0) {
        $errors[] = 'Low stock threshold cannot be negative.';
    }

    if (!$errors) {
        db_run(
            'UPDATE asset_categories
                SET category_code = ?, category_name = ?, parent_id = ?, description = ?, low_stock_threshold = ?, status = ?
              WHERE id = ?',
            [
                $category['category_code'],
                $category['category_name'],
                $category['parent_id'] > 0 ? $category['parent_id'] : null,
                null_if_empty($category['description']),
                $category['low_stock_threshold'],
                $category['status'],
                $id,
            ]
        );
        log_activity('Category', 'update', $id, 'Updated category ' . $category['category_code'] . ' - ' . $category['category_name']);
        set_flash('success', 'Category "' . $category['category_name'] . '" was updated successfully.');
        redirect('categories/index.php');
    }
}

$page_title    = 'Edit Category';
$page_subtitle = 'Update category details';
$active_menu   = 'categories';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('categories/index.php') ?>">Categories</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
        <h2>Edit Category</h2>
        <p><?= e($category['category_code']) ?> &middot; <?= e($category['category_name']) ?></p>
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

<form method="post" action="<?= url('categories/edit.php?id=' . $id) ?>" class="needs-validation" novalidate>
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
                           value="<?= e($category['category_code']) ?>">
                    <div class="form-text">Leave empty to auto-generate.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Category Name <span class="req">*</span></label>
                    <input type="text" class="form-control" name="category_name" maxlength="120" required
                           value="<?= e($category['category_name']) ?>">
                </div>
            </div>

            <div class="form-section-title mt-4">Hierarchy</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Parent Group</label>
                    <?php if ($hasChildren): ?>
                    <input type="text" class="form-control" value="<?= e($category['category_name']) ?> (top level group)" disabled>
                    <div class="form-text">This category has <?= (int) $childCount ?> sub-category(ies) and must remain a top level group.</div>
                    <?php else: ?>
                    <select class="form-select" name="parent_id">
                        <option value="">None (top level equipment group)</option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= (int) $g['id'] ?>"<?= selected($g['id'], $category['parent_id']) ?>><?= e($g['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Only top level groups can be a parent (two level hierarchy).</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Low Stock Threshold</label>
                    <input type="number" class="form-control" name="low_stock_threshold" min="0" step="1"
                           value="<?= (int) $category['low_stock_threshold'] ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active"<?= selected('active', $category['status']) ?>>Active</option>
                        <option value="inactive"<?= selected('inactive', $category['status']) ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" name="description" maxlength="255"
                           value="<?= e($category['description']) ?>">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('categories/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-2"></i>Update Category</button>
        </div>
    </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
