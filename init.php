<?php
/**
 * Application bootstrap.
 * Every page starts with:
 *
 *   root pages       : require_once __DIR__ . '/init.php';
 *   sub-folder pages : require_once __DIR__ . '/../init.php';
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Keep the session user in sync with the database (role / branch changes)
if (is_logged_in()) {
    refresh_user();
}
