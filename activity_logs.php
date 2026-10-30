<?php
/**
 * Activity log - audit trail of everything recorded by the system.
 * Visible to Super Admin and IT Admin only.
 */
require_once __DIR__ . '/init.php';
require_login();

if (!is_role('super_admin', 'it_admin')) {
    deny_access('The activity log is available to administrators only.');
}

$searchModule = get_param('module');
$searchAction = get_param('action');
$searchUser   = get_param('user');
$dateFrom     = get_param('date_from');
$dateTo       = get_param('date_to');
$term         = get_param('q');

$where  = ['1 = 1'];
$params = [];

if ($searchModule !== '') {
    $where[] = 'l.module = :module';
    $params['module'] = $searchModule;
}
if ($searchAction !== '') {
    $where[] = 'l.action = :action';
    $params['action'] = $searchAction;
}
if ($searchUser !== '') {
    $where[] = 'l.user_id = :user_id';
    $params['user_id'] = (int) $searchUser;
}
if (is_valid_date($dateFrom)) {
    $where[] = 'DATE(l.created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if (is_valid_date($dateTo)) {
    $where[] = 'DATE(l.created_at) <= :date_to';
    $params['date_to'] = $dateTo;
}
if ($term !== '') {
    $where[] = '(l.description LIKE :term1 OR l.user_name LIKE :term2 OR l.ip_address LIKE :term3)';
    $params['term1'] = '%' . $term . '%';
    $params['term2'] = '%' . $term . '%';
    $params['term3'] = '%' . $term . '%';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$total = (int) db_value('SELECT COUNT(*) FROM activity_logs l' . $whereSql, $params);

$page    = max(1, (int) get_param('page', 1));
$perPage = ITEMS_PER_PAGE;
$offset  = ($page - 1) * $perPage;

$stmt = db()->prepare('SELECT l.*, u.username, u.role
                         FROM activity_logs l
                         LEFT JOIN users u ON u.id = l.user_id' . $whereSql .
                       ' ORDER BY l.created_at DESC, l.id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$modules = db_all('SELECT DISTINCT module FROM activity_logs ORDER BY module');
$actions = db_all('SELECT DISTINCT action FROM activity_logs ORDER BY action');
$users   = db_all('SELECT id, full_name FROM users ORDER BY full_name');

$todayActions = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()');
$todayLogins  = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE action = "login" AND DATE(created_at) = CURDATE()');
$topModule    = db_value('SELECT module FROM activity_logs GROUP BY module ORDER BY COUNT(*) DESC LIMIT 1', [], '-');
$failedLogins = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE action = "login_failed"');

$page_title    = 'Activity Log';
$page_subtitle = 'Complete audit trail of system actions';
$active_menu   = 'logs';

require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Activity log</li></ol></nav>
        <h2>Activity log</h2>
        <p>Every create, update, delete, login and stock movement recorded by the system.</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline-brand" data-print><i class="fa-solid fa-print me-2"></i>Print</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-navy"><i class="fa-solid fa-list-check"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($total) ?></span>
                <span class="stat-label d-block">Total log entries</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-calendar-day"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($todayActions) ?></span>
                <span class="stat-label d-block">Actions today</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-green"><i class="fa-solid fa-right-to-bracket"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($todayLogins) ?></span>
                <span class="stat-label d-block">Sign-ins today</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-red"><i class="fa-solid fa-shield-halved"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($failedLogins) ?></span>
                <span class="stat-label d-block">Failed login attempts</span>
                <span class="stat-foot text-muted d-block"><i class="fa-solid fa-chart-simple"></i>Most active: <?= e($topModule ?: '-') ?></span>
            </span>
        </div>
    </div>
</div>

<form class="filter-bar" method="get" action="<?= url('activity_logs.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($term) ?>" placeholder="Description, user or IP">
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label" for="module">Module</label>
            <select class="form-select" id="module" name="module">
                <option value="">All modules</option>
                <?php foreach ($modules as $row): ?>
                <option value="<?= e($row['module']) ?>"<?= selected($row['module'], $searchModule) ?>><?= e($row['module']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label" for="action">Action</label>
            <select class="form-select" id="action" name="action">
                <option value="">All actions</option>
                <?php foreach ($actions as $row): ?>
                <option value="<?= e($row['action']) ?>"<?= selected($row['action'], $searchAction) ?>><?= e(ucfirst(str_replace('_', ' ', $row['action']))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-4">
            <label class="form-label" for="user">User</label>
            <select class="form-select" id="user" name="user">
                <option value="">All users</option>
                <?php foreach ($users as $row): ?>
                <option value="<?= (int) $row['id'] ?>"<?= selected($row['id'], $searchUser) ?>><?= e($row['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 col-md-4">
            <label class="form-label" for="date_from">From</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-lg-1 col-md-4">
            <label class="form-label" for="date_to">To</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-lg-1 col-md-12 d-flex gap-2">
            <button class="btn btn-brand btn-sm w-100" type="submit"><i class="fa-solid fa-filter"></i></button>
            <a class="btn btn-outline-brand btn-sm w-100" href="<?= url('activity_logs.php') ?>"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-toolbar">
        <div class="left">
            <input type="search" class="form-control form-control-sm" style="width:230px" data-table-filter="#logsTable" placeholder="Filter this table...">
        </div>
        <div class="right"><?= pagination_summary($total, $perPage, $page, 'log entries') ?></div>
    </div>
    <div class="table-responsive">
        <table class="table" id="logsTable">
            <thead>
                <tr><th>#</th><th>Date &amp; time</th><th>User</th><th>Module</th><th>Action</th><th>Description</th><th class="hide-sm">IP address</th></tr>
            </thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No activity found for the selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="text-muted-sm"><?= (int) $log['id'] ?></td>
                    <td class="text-muted-sm">
                        <?= e(fdatetime($log['created_at'])) ?><br>
                        <span class="text-muted"><?= e(time_ago($log['created_at'])) ?></span>
                    </td>
                    <td>
                        <div><?= e($log['user_name'] ?: 'System') ?></div>
                        <div class="text-muted-sm"><?= e($log['role'] ? role_label($log['role']) : '-') ?></div>
                    </td>
                    <td><span class="badge badge-soft badge-secondary"><?= e($log['module']) ?></span></td>
                    <td>
                        <?php
                        $actionColors = ['create' => 'success', 'update' => 'info', 'delete' => 'danger', 'login' => 'primary',
                                         'logout' => 'secondary', 'login_failed' => 'danger', 'return' => 'warning'];
                        ?>
                        <span class="badge badge-soft badge-<?= $actionColors[$log['action']] ?? 'secondary' ?>">
                            <?= e(ucfirst(str_replace('_', ' ', $log['action']))) ?>
                        </span>
                    </td>
                    <td class="text-muted-sm"><?= e($log['description']) ?></td>
                    <td class="text-muted-sm hide-sm"><?= e($log['ip_address'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted-sm"><?= pagination_summary($total, $perPage, $page, 'log entries') ?></span>
        <?= paginate($total, $perPage, $page) ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
