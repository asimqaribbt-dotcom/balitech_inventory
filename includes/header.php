<?php
/**
 * Application header: document head, top navigation bar and the opening
 * markup of the content area. Include this file right after require_login().
 *
 * Expected (optional) variables before including this file:
 *   $page_title    string  Page heading shown in the top bar
 *   $page_subtitle string  Small text below the heading
 *   $active_menu   string  Sidebar key used to highlight the current item
 *   $extra_css     array   Additional stylesheets
 */

require_once __DIR__ . '/auth.php';
require_login();

$user         = current_user();
$page_title   = $page_title   ?? 'Dashboard';
$page_subtitle = $page_subtitle ?? '';
$active_menu  = $active_menu  ?? 'dashboard';
$extra_css    = $extra_css    ?? [];

// ---------------------------------------------------------------------
// Top bar notification data (kept cheap, failures are ignored)
// ---------------------------------------------------------------------
$notifMaintenance = 0;
$notifLowStock    = 0;
$recentNotifs     = [];
try {
    $notifMaintenance = (int) db_value(
        "SELECT COUNT(*) FROM maintenance_records WHERE status IN ('reported','in_progress')"
    );

    $notifLowStock = (int) db_value(
        "SELECT COUNT(*) FROM (
            SELECT c.id
              FROM asset_categories c
              LEFT JOIN inventory i ON i.category_id = c.id AND i.status = 'available'
             WHERE c.status = 'active' AND c.parent_id IS NOT NULL
             GROUP BY c.id, c.low_stock_threshold
            HAVING COUNT(i.id) < c.low_stock_threshold
         ) AS low_stock"
    );

    $recentNotifs = db_all(
        'SELECT module, action, description, created_at
           FROM activity_logs
          ORDER BY created_at DESC, id DESC
          LIMIT 6'
    );
} catch (Throwable $e) {
    error_log('[BALITECH][header] ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="BALITECH Pvt. Ltd. IT Inventory Management System">
<title><?= e($page_title) ?> &middot; <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= url('assets/img/favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
<?php foreach ($extra_css as $css): ?>
<link rel="stylesheet" href="<?= url('assets/css/' . $css) ?>">
<?php endforeach; ?>
</head>
<body>
<div class="app-wrapper">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">

        <header class="app-topbar no-print">
            <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="topbar-titles">
                <h1><?= e($page_title) ?></h1>
                <?php if ($page_subtitle !== ''): ?><p><?= e($page_subtitle) ?></p><?php endif; ?>
            </div>

            <form class="topbar-search" action="<?= url('search.php') ?>" method="get" role="search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="q" id="globalSearch" autocomplete="off"
                       placeholder="Search assets, serial, employee, branch..."
                       value="<?= e(get_param('q')) ?>">
            </form>

            <div class="topbar-actions">
                <a class="icon-btn" href="<?= url('inventory/add.php') ?>" title="Add new asset">
                    <i class="fa-solid fa-plus"></i>
                </a>

                <div class="dropdown">
                    <button class="icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                            title="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($notifMaintenance > 0 || $notifLowStock > 0): ?><span class="dot"></span><?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end" style="width:330px">
                        <div class="dropdown-header d-flex justify-content-between align-items-center">
                            <span>Notifications</span>
                            <span class="badge badge-soft badge-primary"><?= (int) ($notifMaintenance + $notifLowStock) ?> new</span>
                        </div>
                        <div class="notif-list">
                            <?php if ($notifMaintenance > 0): ?>
                            <a class="notif-item" href="<?= url('maintenance/index.php?status=reported') ?>">
                                <span class="n-icon bg-soft-amber"><i class="fa-solid fa-screwdriver-wrench"></i></span>
                                <span>
                                    <span class="n-text d-block"><?= (int) $notifMaintenance ?> equipment item(s) awaiting maintenance</span>
                                    <span class="n-time">Maintenance module</span>
                                </span>
                            </a>
                            <?php endif; ?>
                            <?php if ($notifLowStock > 0): ?>
                            <a class="notif-item" href="<?= url('stock/index.php?filter=low') ?>">
                                <span class="n-icon bg-soft-red"><i class="fa-solid fa-arrow-trend-down"></i></span>
                                <span>
                                    <span class="n-text d-block"><?= (int) $notifLowStock ?> categor(y/ies) below the stock threshold</span>
                                    <span class="n-time">Stock management</span>
                                </span>
                            </a>
                            <?php endif; ?>
                            <?php foreach ($recentNotifs as $notif): ?>
                            <a class="notif-item" href="<?= url('activity_logs.php') ?>">
                                <span class="n-icon bg-soft-blue"><i class="fa-solid fa-clock-rotate-left"></i></span>
                                <span>
                                    <span class="n-text d-block"><?= e($notif['description']) ?></span>
                                    <span class="n-time"><?= e(time_ago($notif['created_at'])) ?></span>
                                </span>
                            </a>
                            <?php endforeach; ?>
                            <?php if (!$recentNotifs && $notifMaintenance === 0 && $notifLowStock === 0): ?>
                            <div class="px-3 py-4 text-center text-muted" style="font-size:12.5px">No notifications</div>
                            <?php endif; ?>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item justify-content-center" href="<?= url('activity_logs.php') ?>">
                            <i class="fa-solid fa-list-ul"></i> View activity log
                        </a>
                    </div>
                </div>

                <div class="dropdown">
                    <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar"><?= e(user_initials($user['full_name'])) ?></span>
                        <span class="text-start">
                            <span class="u-name d-block"><?= e($user['full_name']) ?></span>
                            <span class="u-role d-block"><?= e(role_label($user['role'])) ?></span>
                        </span>
                        <i class="fa-solid fa-chevron-down ms-1" style="font-size:10px;opacity:.6"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end" style="width:250px">
                        <div class="px-3 py-2">
                            <div class="fw-semibold" style="color:var(--navy-800)"><?= e($user['full_name']) ?></div>
                            <div class="text-muted" style="font-size:12px"><?= e($user['email']) ?></div>
                            <div class="mt-2"><span class="badge badge-soft badge-primary"><?= e(role_label($user['role'])) ?></span></div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="<?= url('profile.php') ?>"><i class="fa-regular fa-user"></i> My profile</a>
                        <a class="dropdown-item" href="<?= url('change_password.php') ?>"><i class="fa-solid fa-key"></i> Change password</a>
                        <?php if (can('reports')): ?>
                        <a class="dropdown-item" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-chart-column"></i> Reports</a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="fa-solid fa-power-off"></i> Sign out</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="app-content">
            <?= render_flash() ?>
