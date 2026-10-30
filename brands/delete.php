<?php
/**
 * Brands - delete a brand (POST only).
 * Assets that used the brand have their brand_id cleared (ON DELETE SET NULL).
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('masters');

if (!is_post()) {
    redirect('brands/index.php');
}

csrf_verify();

$id = (int) post('id');
$brand = db_row('SELECT * FROM brands WHERE id = ?', [$id]);
if (!$brand) {
    set_flash('danger', 'The requested brand could not be found.');
    redirect('brands/index.php');
}

$affectedAssets = (int) db_value('SELECT COUNT(*) FROM inventory WHERE brand_id = ?', [$id]);

try {
    db_run('DELETE FROM brands WHERE id = ?', [$id]);
    log_activity('Brand', 'delete', $id, 'Deleted brand ' . $brand['brand_name']);
    $message = 'Brand "' . $brand['brand_name'] . '" was deleted.';
    if ($affectedAssets > 0) {
        $message .= ' ' . $affectedAssets . ' asset(s) had their brand cleared.';
    }
    set_flash('warning', $message);
} catch (PDOException $e) {
    error_log('[BALITECH][brand delete] ' . $e->getMessage());
    set_flash('danger', 'This brand is referenced by other records and could not be deleted.');
}

redirect('brands/index.php');
