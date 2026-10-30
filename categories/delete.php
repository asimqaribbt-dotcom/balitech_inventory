<?php
/**
 * Categories - delete a category (POST only).
 * Blocked when inventory rows use the category. Child sub-categories are
 * removed with the parent (ON DELETE CASCADE).
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('masters');

if (!is_post()) {
    redirect('categories/index.php');
}

csrf_verify();

$id = (int) post('id');
$category = db_row('SELECT * FROM asset_categories WHERE id = ?', [$id]);
if (!$category) {
    set_flash('danger', 'The requested category could not be found.');
    redirect('categories/index.php');
}

// Assets belonging to this category or to any of its sub-categories
$assetCount = (int) db_value(
    'SELECT COUNT(*) FROM inventory i
      JOIN asset_categories c ON c.id = i.category_id
     WHERE c.id = ? OR c.parent_id = ?',
    [$id, $id]
);

if ($assetCount > 0) {
    set_flash('danger', 'Cannot delete "' . $category['category_name'] . '": '
        . $assetCount . ' asset(s) still use this category or one of its sub-categories.');
    redirect('categories/index.php');
}

$childCount = (int) db_value('SELECT COUNT(*) FROM asset_categories WHERE parent_id = ?', [$id]);

try {
    db_run('DELETE FROM asset_categories WHERE id = ?', [$id]);
    log_activity('Category', 'delete', $id, 'Deleted category ' . $category['category_code'] . ' - ' . $category['category_name']);
    $message = 'Category "' . $category['category_name'] . '" was deleted.';
    if ($childCount > 0) {
        $message .= ' ' . $childCount . ' child sub-category(ies) were deleted with it.';
        set_flash('warning', $message);
    } else {
        set_flash('success', $message);
    }
} catch (PDOException $e) {
    error_log('[BALITECH][category delete] ' . $e->getMessage());
    set_flash('danger', 'This category is referenced by other records and could not be deleted.');
}

redirect('categories/index.php');
