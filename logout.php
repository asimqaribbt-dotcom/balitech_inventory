<?php
/**
 * Logout: destroys the session and returns to the login screen.
 */
require_once __DIR__ . '/init.php';

if (is_logged_in()) {
    log_activity('Auth', 'logout', current_user_id(), current_user()['full_name'] . ' signed out');
}

logout_user();

// Start a fresh session for the flash message
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
set_flash('success', 'You have been signed out successfully.');

redirect('login.php');
