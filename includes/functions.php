<?php
/**
 * Global helper functions: output escaping, URLs, flash messages,
 * CSRF protection, activity logging, formatting, pagination and
 * reusable lookup lists.
 */

require_once __DIR__ . '/../config/database.php';

/* =====================================================================
 * Output escaping / URLs
 * ===================================================================*/

/** Escape a value for safe HTML output (XSS protection). */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an absolute application URL. */
function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/** Redirect to an application path and stop execution. */
function redirect(string $path): void
{
    $target = preg_match('#^https?://#i', $path) ? $path : url($path);
    header('Location: ' . $target);
    exit;
}

/** True when the current request is a POST request. */
function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Fetch a trimmed POST value. */
function post(string $key, $default = '')
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

/** Fetch a trimmed GET value. */
function get_param(string $key, $default = '')
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

/* =====================================================================
 * Flash messages
 * ===================================================================*/

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Render the flash message area (called from header.php). */
function render_flash(): string
{
    $html = '';
    foreach (get_flash() as $flash) {
        $type = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
        $icon = [
            'success' => 'fa-circle-check',
            'danger'  => 'fa-circle-xmark',
            'warning' => 'fa-triangle-exclamation',
            'info'    => 'fa-circle-info',
        ][$type];
        $html .= '<div class="alert alert-' . $type . ' alert-dismissible fade show app-alert" role="alert">'
               . '<i class="fa-solid ' . $icon . ' me-2"></i>' . e($flash['message'])
               . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    return $html;
}

/* =====================================================================
 * CSRF protection
 * ===================================================================*/

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to place inside every POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Validate the submitted token; aborts the request when invalid. */
function csrf_verify(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)) {
        set_flash('danger', 'Security token mismatch. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? url('dashboard.php');
        header('Location: ' . $back);
        exit;
    }
    return true;
}

/* =====================================================================
 * Input validation helpers
 * ===================================================================*/

function is_valid_date(?string $date): bool
{
    if (!$date) {
        return false;
    }
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function is_valid_email(?string $email): bool
{
    return $email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Null out empty strings so that DATE / FK columns receive NULL. */
function null_if_empty($value)
{
    $value = is_string($value) ? trim($value) : $value;
    return ($value === '' || $value === null) ? null : $value;
}

/* =====================================================================
 * Activity log
 * ===================================================================*/

function log_activity(string $module, string $action, ?int $recordId = null, string $description = ''): void
{
    $user = $_SESSION['user'] ?? null;
    db_run(
        'INSERT INTO activity_logs (user_id, user_name, module, action, record_id, description, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
        [
            $user['id'] ?? null,
            $user['full_name'] ?? 'System',
            $module,
            $action,
            $recordId,
            mb_substr($description, 0, 250),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
}

/* =====================================================================
 * Formatting helpers
 * ===================================================================*/

function money($amount, bool $withSymbol = true): string
{
    $value = number_format((float) $amount, 2, '.', ',');
    return $withSymbol ? 'PKR ' . $value : $value;
}

function fdate($date, string $format = 'd M Y'): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    $time = strtotime((string) $date);
    return $time ? date($format, $time) : '-';
}

function fdatetime($date, string $format = 'd M Y, h:i A'): string
{
    return fdate($date, $format);
}

/** Human readable "2 days ago" style text. */
function time_ago($datetime): string
{
    if (empty($datetime)) {
        return '-';
    }
    $diff = time() - (int) strtotime((string) $datetime);
    if ($diff < 60) {
        return 'just now';
    }
    $units = [
        'year'   => 31536000,
        'month'  => 2592000,
        'day'    => 86400,
        'hour'   => 3600,
        'minute' => 60,
    ];
    foreach ($units as $label => $seconds) {
        if ($diff >= $seconds) {
            $count = (int) floor($diff / $seconds);
            return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

/* =====================================================================
 * Lookup lists (statuses, roles, badge rendering)
 * ===================================================================*/

function asset_statuses(): array
{
    return [
        'available'         => 'Available',
        'assigned'          => 'Assigned',
        'under_maintenance' => 'Under Maintenance',
        'damaged'           => 'Damaged',
        'retired'           => 'Retired',
    ];
}

function asset_conditions(): array
{
    return ['New' => 'New', 'Good' => 'Good', 'Fair' => 'Fair', 'Poor' => 'Poor'];
}

function assignment_statuses(): array
{
    return ['assigned' => 'Assigned', 'returned' => 'Returned', 'transferred' => 'Transferred'];
}

function maintenance_statuses(): array
{
    return [
        'reported'     => 'Reported',
        'in_progress'  => 'In Progress',
        'completed'    => 'Completed',
        'unrepairable' => 'Unrepairable',
    ];
}

function maintenance_types(): array
{
    return [
        'preventive' => 'Preventive',
        'corrective' => 'Corrective',
        'upgrade'    => 'Upgrade',
        'inspection' => 'Inspection',
    ];
}

function stock_types(): array
{
    return [
        'in'         => 'Stock In',
        'out'        => 'Stock Out',
        'transfer'   => 'Transfer',
        'return'     => 'Return',
        'adjustment' => 'Adjustment',
    ];
}

function roles_list(): array
{
    return [
        'super_admin'  => 'Super Admin',
        'it_admin'     => 'IT Admin',
        'branch_staff' => 'Branch IT Staff',
        'dept_manager' => 'Department Manager',
    ];
}

function role_label(?string $role): string
{
    return roles_list()[$role] ?? 'Unknown';
}

/** Coloured Bootstrap badge for an asset status. */
function status_badge(?string $status): string
{
    $map = [
        'available'        => 'success',
        'assigned'         => 'primary',
        'under_maintenance'=> 'warning',
        'damaged'          => 'danger',
        'retired'          => 'secondary',
    ];
    $label = asset_statuses()[$status] ?? ucfirst((string) $status);
    return '<span class="badge badge-soft badge-' . ($map[$status] ?? 'secondary') . '">' . e($label) . '</span>';
}

function condition_badge(?string $condition): string
{
    $map = ['New' => 'success', 'Good' => 'info', 'Fair' => 'warning', 'Poor' => 'danger'];
    $label = $condition ?: '-';
    return '<span class="badge badge-soft badge-' . ($map[$condition] ?? 'secondary') . '">' . e($label) . '</span>';
}

function assignment_badge(?string $status): string
{
    $map = ['assigned' => 'primary', 'returned' => 'secondary', 'transferred' => 'info'];
    $label = assignment_statuses()[$status] ?? ucfirst((string) $status);
    return '<span class="badge badge-soft badge-' . ($map[$status] ?? 'secondary') . '">' . e($label) . '</span>';
}

function maintenance_badge(?string $status): string
{
    $map = [
        'reported'     => 'warning',
        'in_progress'  => 'info',
        'completed'    => 'success',
        'unrepairable' => 'danger',
    ];
    $label = maintenance_statuses()[$status] ?? ucfirst((string) $status);
    return '<span class="badge badge-soft badge-' . ($map[$status] ?? 'secondary') . '">' . e($label) . '</span>';
}

function stock_badge(?string $type): string
{
    $map = ['in' => 'success', 'out' => 'danger', 'transfer' => 'info', 'return' => 'warning', 'adjustment' => 'secondary'];
    $label = stock_types()[$type] ?? ucfirst((string) $type);
    return '<span class="badge badge-soft badge-' . ($map[$type] ?? 'secondary') . '">' . e($label) . '</span>';
}

function active_badge(?string $status): string
{
    return $status === 'active'
        ? '<span class="badge badge-soft badge-success">Active</span>'
        : '<span class="badge badge-soft badge-secondary">Inactive</span>';
}

/** Ensure an option is selected in a <select> element. */
function selected($value, $current): string
{
    return (string) $value === (string) $current ? ' selected' : '';
}

/* =====================================================================
 * Asset code generator
 * ===================================================================*/

function generate_asset_code(): string
{
    $next = (int) db_value('SELECT COALESCE(MAX(id), 0) FROM inventory') + 1;
    do {
        $code = 'BLT-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        $exists = (int) db_value('SELECT COUNT(*) FROM inventory WHERE asset_code = ?', [$code]);
        $next++;
    } while ($exists > 0);

    return $code;
}

/** Next sequential code for any table that uses a xxx-#### pattern. */
function generate_next_code(string $table, string $column, string $prefix, int $pad = 4): string
{
    $allowed = ['employees', 'branches', 'departments', 'asset_categories'];
    if (!in_array($table, $allowed, true)) {
        return $prefix . str_pad('1', $pad, '0', STR_PAD_LEFT);
    }
    $next = (int) db_value("SELECT COALESCE(MAX(id), 0) FROM {$table}") + 1;
    do {
        $code = $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
        $exists = (int) db_value("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?", [$code]);
        $next++;
    } while ($exists > 0);

    return $code;
}

/* =====================================================================
 * Pagination
 * ===================================================================*/

/**
 * Build Bootstrap pagination markup. Existing query string parameters are
 * preserved so that filters keep working while paging.
 */
function paginate(int $total, int $perPage, int $page, string $pageParam = 'page'): string
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) {
        return '';
    }

    $query = $_GET;
    $link = function (int $p) use ($query, $pageParam): string {
        $query[$pageParam] = $p;
        // keep the "path" part of the current script clean of query params
        $path = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        return e($path . '?' . http_build_query($query));
    };

    $from = max(1, $page - 2);
    $to   = min($pages, $from + 4);
    $from = max(1, $to - 4);

    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm app-pagination mb-0">';
    $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="'
           . $link(max(1, $page - 1)) . '"><i class="fa-solid fa-angle-left"></i></a></li>';

    if ($from > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $link(1) . '">1</a></li>';
        if ($from > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for ($i = $from; $i <= $to; $i++) {
        $html .= '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="'
               . $link($i) . '">' . $i . '</a></li>';
    }

    if ($to < $pages) {
        if ($to < $pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $link($pages) . '">' . $pages . '</a></li>';
    }

    $html .= '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="'
           . $link(min($pages, $page + 1)) . '"><i class="fa-solid fa-angle-right"></i></a></li>';
    $html .= '</ul></nav>';

    return $html;
}

/** Small "Showing X to Y of Z" caption. */
function pagination_summary(int $total, int $perPage, int $page, string $label = 'records'): string
{
    if ($total === 0) {
        return 'No ' . e($label) . ' found';
    }
    $start = (($page - 1) * $perPage) + 1;
    $end   = min($total, $page * $perPage);
    return 'Showing <b>' . $start . '</b> to <b>' . $end . '</b> of <b>' . $total . '</b> ' . e($label);
}

/* =====================================================================
 * Export helpers
 * ===================================================================*/

/** Stream a CSV file (Excel compatible, UTF-8 BOM included). */
function csv_download(string $filename, array $headers, array $rows): void
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel detects UTF-8
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static function ($value) {
            return is_scalar($value) || $value === null ? (string) $value : '';
        }, $row));
    }
    fclose($out);
    exit;
}

/** Build the printable PDF (browser print dialog) page header markup. */
function pdf_report_header(string $title, array $meta = []): string
{
    $html = '<div class="pdf-header">'
          . '<div class="pdf-brand"><span class="pdf-logo">B</span><div><h1>BALITECH Pvt. Ltd.</h1>'
          . '<p>IT Inventory Management System</p></div></div>'
          . '<div class="pdf-title"><h2>' . e($title) . '</h2>'
          . '<p>Generated on ' . date('d M Y, h:i A') . '</p></div></div>';
    if ($meta) {
        $html .= '<div class="pdf-meta">';
        foreach ($meta as $label => $value) {
            $html .= '<span><b>' . e($label) . ':</b> ' . e($value) . '</span>';
        }
        $html .= '</div>';
    }
    return $html;
}
