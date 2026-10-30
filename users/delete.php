<?php
/**
 * Users & Roles - delete a user account (POST only).
 * The current account and the last active Super Admin can never be removed.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('users');

if (!is_post()) {
    redirect('users/index.php');
}

csrf_verify();

$id   = (int) post('id');
$user = db_row('SELECT * FROM users WHERE id = ?', [$id]);

if (!$user) {
    set_flash('danger', 'The requested user account could not be found.');
    redirect('users/index.php');
}

if ($id === current_user_id()) {
    set_flash('danger', 'You cannot delete your own account.');
    redirect('users/index.php');
}

if ($user['role'] === 'super_admin' && $user['status'] === 'active') {
    $otherSupers = (int) db_value(
        'SELECT COUNT(*) FROM users WHERE role = "super_admin" AND status = "active" AND id <> ?',
        [$id]
    );
    if ($otherSupers === 0) {
        set_flash('danger', 'You cannot delete the last active Super Admin.');
        redirect('users/index.php');
    }
}

try {
    db_run('DELETE FROM users WHERE id = ?', [$id]);
    log_activity('User', 'delete', $id, 'Deleted user "' . $user['username'] . '" (' . role_label($user['role']) . ')');
    set_flash('warning', 'User "' . $user['full_name'] . '" was deleted.');
} catch (PDOException $e) {
    error_log('[BALITECH][user delete] ' . $e->getMessage());
    set_flash('danger', 'This user is referenced by other records and could not be deleted.');
}

redirect('users/index.php');
