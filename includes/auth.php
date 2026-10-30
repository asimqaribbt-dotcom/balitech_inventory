<?php
/**
 * Authentication, session handling and role based access control (RBAC).
 */

require_once __DIR__ . '/functions.php';

/* =====================================================================
 * Session helpers
 * ===================================================================*/

function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

/** Currently logged in user (array from the session, or null). */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_user_id(): ?int
{
    return isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
}

/** Reload the session user record from the database. */
function refresh_user(): void
{
    if (!is_logged_in()) {
        return;
    }
    $user = db_row(
        'SELECT u.*, b.branch_name, d.department_name
           FROM users u
           LEFT JOIN branches b ON b.id = u.branch_id
           LEFT JOIN departments d ON d.id = u.department_id
          WHERE u.id = ? AND u.status = "active"',
        [current_user_id()]
    );
    if ($user) {
        unset($user['password']);
        $_SESSION['user'] = $user;
    } else {
        logout_user();
    }
}

/* =====================================================================
 * Login / logout
 * ===================================================================*/

/**
 * Verify credentials and create the authenticated session.
 * Returns null on success or an error message on failure.
 */
function attempt_login(string $username, string $password): ?string
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return 'Please enter both username and password.';
    }

    $user = db_row(
        'SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1',
        [$username, $username]
    );

    // Generic message for both cases, so usernames cannot be enumerated
    if (!$user || !password_verify($password, $user['password'])) {
        log_activity('Auth', 'login_failed', null, 'Failed login attempt for "' . $username . '"');
        return 'Invalid username or password. Please try again.';
    }

    if ($user['status'] !== 'active') {
        return 'This account has been deactivated. Please contact the administrator.';
    }

    // Prevent session fixation
    session_regenerate_id(true);

    unset($user['password']);
    $_SESSION['user']       = $user;
    $_SESSION['fingerprint'] = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|BALITECH');
    $_SESSION['login_time'] = time();
    $_SESSION['_created']   = time();

    db_run('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
    refresh_user();
    log_activity('Auth', 'login', (int) $user['id'], $user['full_name'] . ' signed in');

    return null;
}

function logout_user(bool $destroy = true): void
{
    $_SESSION = [];
    if ($destroy && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) ($params['secure'] ?? false), true);
    }
    session_destroy();
}

/* =====================================================================
 * Access control
 * ===================================================================*/

/**
 * Ability matrix per role.
 *  super_admin  : full access (*)
 *  it_admin     : inventory, assignments, transfers, stock, maintenance,
 *                 masters, employees, departments, reports
 *  branch_staff : inventory / assignments / stock / maintenance limited to
 *                 their own branch, reports
 *  dept_manager : read-only view of their department + reports
 */
function permissions(): array
{
    return [
        'super_admin' => ['*'],
        'it_admin' => [
            'inventory', 'assignments', 'transfers', 'stock', 'maintenance',
            'masters', 'employees', 'departments', 'reports', 'branches',
        ],
        'branch_staff' => ['inventory', 'assignments', 'transfers', 'stock', 'maintenance', 'reports'],
        'dept_manager' => ['reports'],
    ];
}

function can(string $ability): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    $abilities = permissions()[$user['role']] ?? [];
    return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
}

function is_role(string ...$roles): bool
{
    $user = current_user();
    return $user && in_array($user['role'], $roles, true);
}

/** Stop the request when the user may not perform an action. */
function require_ability(string $ability): void
{
    if (!can($ability)) {
        deny_access('You do not have permission to perform this action.');
    }
}

/** Stop the request when the user is not logged in. */
function require_login(): void
{
    if (!is_logged_in()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Session expired. Please sign in again.']);
            exit;
        }
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        set_flash('warning', 'Please sign in to continue.');
        redirect('login.php');
    }

    // Basic hijack protection: user agent fingerprint must match
    $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|BALITECH');
    if (!empty($_SESSION['fingerprint']) && !hash_equals($_SESSION['fingerprint'], $fingerprint)) {
        logout_user();
        redirect('login.php');
    }
}

/** Render a professional 403 page and exit. */
function deny_access(string $message = 'Access denied.'): void
{
    http_response_code(403);
    $home = url('dashboard.php');
    $safe = e($message);
    echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Access denied - BALITECH IT Inventory</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
 body{background:#f1f4f9;font-family:'Segoe UI',system-ui,Arial,sans-serif;height:100vh;display:flex;align-items:center;justify-content:center}
 .card-403{background:#fff;border-radius:16px;padding:44px 40px;text-align:center;max-width:520px;box-shadow:0 18px 40px rgba(11,37,69,.12)}
 .card-403 i{font-size:52px;color:#c1121f;margin-bottom:14px}
 .card-403 h1{font-size:22px;color:#0b2545;margin:0 0 10px}
 .card-403 p{color:#5a6b82;margin-bottom:22px}
 .btn-brand{background:#0b2545;color:#fff;border-radius:9px;padding:10px 22px;text-decoration:none;display:inline-block}
 .btn-brand:hover{background:#123a69;color:#fff}
</style></head>
<body><div class="card-403">
 <i class="fa-solid fa-lock"></i>
 <h1>403 &middot; Access denied</h1>
 <p>{$safe}</p>
 <a class="btn-brand" href="{$home}"><i class="fa-solid fa-house me-2"></i>Back to dashboard</a>
</div></body></html>
HTML;
    exit;
}

/**
 * Data scoping for the current role.
 * Returns SQL fragments + parameters that must be appended to inventory /
 * assignment style queries so that Branch IT Staff only see their branch
 * and Department Managers only see their department.
 *
 * @param string $alias     table alias used in the query
 * @param string $branchCol branch column name
 * @param string $deptCol   department column name
 * @return array{sql:string, params:array}
 */
function rbac_scope(string $alias = 'i', string $branchCol = 'branch_id', string $deptCol = 'department_id'): array
{
    $user = current_user();
    if (!$user) {
        return ['sql' => ' AND 1 = 0', 'params' => []];
    }
    if ($user['role'] === 'branch_staff' && !empty($user['branch_id'])) {
        return [
            'sql'    => " AND {$alias}.{$branchCol} = :scope_branch",
            'params' => ['scope_branch' => (int) $user['branch_id']],
        ];
    }
    if ($user['role'] === 'dept_manager' && !empty($user['department_id'])) {
        return [
            'sql'    => " AND {$alias}.{$deptCol} = :scope_dept",
            'params' => ['scope_dept' => (int) $user['department_id']],
        ];
    }
    return ['sql' => '', 'params' => []];
}

/** True when the current user may only read data (Department Manager). */
function is_read_only_role(): bool
{
    return is_role('dept_manager');
}

/** Initials used by the avatar circle. */
function user_initials(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return 'U';
    }
    $parts = preg_split('/\s+/', $name);
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}
