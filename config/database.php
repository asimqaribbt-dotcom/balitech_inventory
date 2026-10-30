<?php
/**
 * PDO database connection (singleton).
 * All queries in the application use prepared statements through this
 * connection, which prevents SQL injection.
 *
 * Usage:  $pdo = db();
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET time_zone = '+05:00'");
    } catch (PDOException $e) {
        error_log('[BALITECH][DB] ' . $e->getMessage());
        http_response_code(500);
        $hint = '';
        if (strpos($e->getMessage(), 'Unknown database') !== false) {
            $hint = '<p style="margin-top:14px">The database <b>' . DB_NAME . '</b> was not found. '
                  . 'Import <code>database/balitech_inventory.sql</code> in phpMyAdmin first.</p>';
        } else {
            $hint = '<p style="margin-top:14px">Check the database credentials inside '
                  . '<code>config/config.php</code>.</p>';
        }
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Database connection error</title>'
           . '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f1f4f9;margin:0;'
           . 'display:flex;align-items:center;justify-content:center;height:100vh}'
           . '.box{background:#fff;border-top:4px solid #0b2545;padding:34px 38px;border-radius:12px;'
           . 'box-shadow:0 12px 34px rgba(11,37,69,.12);max-width:560px}h1{font-size:19px;color:#0b2545;margin:0 0 8px}'
           . 'p{color:#5a6b82;font-size:14px;line-height:1.6}code{background:#eef2f8;padding:2px 6px;border-radius:4px}</style>'
           . '</head><body><div class="box"><h1>Database connection failed</h1>'
           . '<p>The application could not connect to MySQL.</p>' . $hint . '</div></body></html>';
        exit;
    }

    return $pdo;
}

/**
 * Helper wrapper: run a prepared statement and return the statement object.
 */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch a single row (or null). */
function db_row(string $sql, array $params = [])
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** Fetch a single scalar value. */
function db_value(string $sql, array $params = [], $default = null)
{
    $value = db_run($sql, $params)->fetchColumn();
    return $value === false ? $default : $value;
}
