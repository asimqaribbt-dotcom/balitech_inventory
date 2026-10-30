<?php
/**
 * BALITECH Pvt. Ltd. - IT Inventory Management System
 * ---------------------------------------------------------------------
 * Application configuration: paths, database credentials, session
 * hardening and global constants. Edit the database settings below to
 * match your server (XAMPP defaults are pre-configured).
 */

// ---------------------------------------------------------------------
// 1. Database credentials
// ---------------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'balitech_inventory');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP default MySQL password is empty
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------
// 2. Application settings
// ---------------------------------------------------------------------
define('APP_NAME',   'BALITECH IT Inventory');
define('APP_FULL_NAME', 'BALITECH Pvt. Ltd. - IT Inventory Management System');
define('APP_COMPANY', 'BALITECH Pvt. Ltd.');
define('APP_VERSION', '1.0.0');
define('ITEMS_PER_PAGE', 15);
define('APP_TIMEZONE', 'Asia/Karachi');

// Absolute path of the project folder (folder that contains /config)
define('APP_ROOT', dirname(__DIR__));

// ---------------------------------------------------------------------
// 3. Auto-detected base URL (works in any sub-folder / hosting setup)
// ---------------------------------------------------------------------
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: '') : '';
$appRoot = str_replace('\\', '/', APP_ROOT);
$basePath = '';
if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $basePath = substr($appRoot, strlen($docRoot));
}
$basePath = '/' . trim(str_replace('\\', '/', $basePath), '/');
define('BASE_URL', $basePath === '/' ? '/' : $basePath . '/');

// Upload directory (used by the optional asset photo upload)
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('UPLOAD_URL', BASE_URL . 'uploads/');

// ---------------------------------------------------------------------
// 4. Session hardening + error handling
// ---------------------------------------------------------------------
date_default_timezone_set(APP_TIMEZONE);

// Show errors while developing on XAMPP, keep them out of the HTML output
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => BASE_URL,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
    } else {
        session_set_cookie_params(0, BASE_URL, '', $secure, true);
    }
    session_name('BALITECH_SESSID');
    session_start();
}

// Regenerate the session id periodically to reduce session fixation risk
if (!isset($_SESSION['_created'])) {
    $_SESSION['_created'] = time();
} elseif (time() - $_SESSION['_created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['_created'] = time();
}
