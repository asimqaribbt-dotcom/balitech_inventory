<?php
/**
 * BALITECH IT Inventory - entry point.
 * Sends visitors either to the dashboard or the login screen.
 */
require_once __DIR__ . '/init.php';

redirect(is_logged_in() ? 'dashboard.php' : 'login.php');
