<?php
/**
 * Sidebar navigation. Requires $active_menu to be set (header.php sets it).
 */

$active_menu = $active_menu ?? 'dashboard';

if (!function_exists('nav_active')) {
    function nav_active(string $key, string $current): string
    {
        return $key === $current ? ' active' : '';
    }
    function nav_open(array $keys, string $current): string
    {
        return in_array($current, $keys, true) ? ' show' : '';
    }
}
$inventoryChildren = ['inventory', 'inventory_add', 'inventory_assign', 'inventory_transfer', 'inventory_status'];
$user = current_user();
?>
<aside class="app-sidebar no-print" id="appSidebar">
    <div class="sidebar-brand">
        <span class="brand-mark">B</span>
        <span class="brand-text">
            <span class="brand-name">BALITECH</span>
            <span class="brand-sub">IT Inventory</span>
        </span>
    </div>

    <div class="sidebar-scroll">
        <div class="nav-label">Main</div>
        <ul class="sidebar-nav">
            <li>
                <a class="<?= trim(nav_active('dashboard', $active_menu)) ?>" href="<?= url('dashboard.php') ?>">
                    <i class="fa-solid fa-gauge-high nav-icon"></i> Dashboard
                </a>
            </li>
        </ul>

        <div class="nav-label">IT Inventory</div>
        <ul class="sidebar-nav">
            <li>
                <a class="<?= trim(nav_active('inventory', $active_menu)) ?>" data-bs-toggle="collapse"
                   href="#menuInventory" role="button" aria-expanded="<?= in_array($active_menu, $inventoryChildren, true) ? 'true' : 'false' ?>">
                    <i class="fa-solid fa-boxes-stacked nav-icon"></i> Inventory
                    <i class="fa-solid fa-chevron-right nav-caret"></i>
                </a>
                <ul class="collapse submenu<?= nav_open($inventoryChildren, $active_menu) ?>" id="menuInventory">
                    <li><a class="<?= trim(nav_active('inventory', $active_menu)) ?>" href="<?= url('inventory/index.php') ?>">All Assets</a></li>
                    <?php if (can('inventory')): ?>
                    <li><a class="<?= trim(nav_active('inventory_add', $active_menu)) ?>" href="<?= url('inventory/add.php') ?>">Add New Asset</a></li>
                    <?php endif; ?>
                    <li><a class="<?= trim(nav_active('inventory_assign', $active_menu)) ?>" href="<?= url('assignments/index.php') ?>">Assign / Return</a></li>
                    <li><a class="<?= trim(nav_active('inventory_transfer', $active_menu)) ?>" href="<?= url('transfers/index.php') ?>">Transfers</a></li>
                    <li><a class="<?= trim(nav_active('inventory_status', $active_menu)) ?>" href="<?= url('inventory/index.php?status=under_maintenance') ?>">Status Board</a></li>
                </ul>
            </li>
            <li>
                <a class="<?= trim(nav_active('assignments', $active_menu)) ?>" href="<?= url('assignments/index.php') ?>">
                    <i class="fa-solid fa-user-check nav-icon"></i> Asset Assignment
                </a>
            </li>
            <li>
                <a class="<?= trim(nav_active('transfers', $active_menu)) ?>" href="<?= url('transfers/index.php') ?>">
                    <i class="fa-solid fa-right-left nav-icon"></i> Transfers
                </a>
            </li>
            <li>
                <a class="<?= trim(nav_active('stock', $active_menu)) ?>" href="<?= url('stock/index.php') ?>">
                    <i class="fa-solid fa-warehouse nav-icon"></i> Stock Management
                    <?php if (!empty($notifLowStock)): ?><span class="nav-badge"><?= (int) $notifLowStock ?></span><?php endif; ?>
                </a>
            </li>
            <li>
                <a class="<?= trim(nav_active('maintenance', $active_menu)) ?>" href="<?= url('maintenance/index.php') ?>">
                    <i class="fa-solid fa-screwdriver-wrench nav-icon"></i> Maintenance
                    <?php if (!empty($notifMaintenance)): ?><span class="nav-badge"><?= (int) $notifMaintenance ?></span><?php endif; ?>
                </a>
            </li>
        </ul>

        <div class="nav-label">Organisation</div>
        <ul class="sidebar-nav">
            <li>
                <a class="<?= trim(nav_active('branches', $active_menu)) ?>" href="<?= url('branches/index.php') ?>">
                    <i class="fa-solid fa-building nav-icon"></i> Branches
                </a>
            </li>
            <li>
                <a class="<?= trim(nav_active('departments', $active_menu)) ?>" href="<?= url('departments/index.php') ?>">
                    <i class="fa-solid fa-sitemap nav-icon"></i> Departments
                </a>
            </li>
            <li>
                <a class="<?= trim(nav_active('employees', $active_menu)) ?>" href="<?= url('employees/index.php') ?>">
                    <i class="fa-solid fa-users nav-icon"></i> Employees
                </a>
            </li>
            <li>
                <a class="<?= trim(nav_active('categories', $active_menu)) ?>" href="<?= url('categories/index.php') ?>">
                    <i class="fa-solid fa-layer-group nav-icon"></i> Categories
                </a>
            </li>
            <li>
                <a class="<?= trim(nav_active('brands', $active_menu)) ?>" href="<?= url('brands/index.php') ?>">
                    <i class="fa-solid fa-tags nav-icon"></i> Brands
                </a>
            </li>
        </ul>

        <div class="nav-label">Analytics</div>
        <ul class="sidebar-nav">
            <li>
                <a class="<?= trim(nav_active('reports', $active_menu)) ?>" href="<?= url('reports/index.php') ?>">
                    <i class="fa-solid fa-chart-column nav-icon"></i> Reports
                </a>
            </li>
            <?php if (can('inventory')): ?>
            <li>
                <a class="<?= trim(nav_active('search', $active_menu)) ?>" href="<?= url('search.php') ?>">
                    <i class="fa-solid fa-magnifying-glass nav-icon"></i> Global Search
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <?php if (is_role('super_admin', 'it_admin')): ?>
        <div class="nav-label">Administration</div>
        <ul class="sidebar-nav">
            <?php if (is_role('super_admin')): ?>
            <li>
                <a class="<?= trim(nav_active('users', $active_menu)) ?>" href="<?= url('users/index.php') ?>">
                    <i class="fa-solid fa-user-shield nav-icon"></i> Users &amp; Roles
                </a>
            </li>
            <?php endif; ?>
            <li>
                <a class="<?= trim(nav_active('logs', $active_menu)) ?>" href="<?= url('activity_logs.php') ?>">
                    <i class="fa-solid fa-clipboard-list nav-icon"></i> Activity Log
                </a>
            </li>
            <li>
                <a class="<?= trim(nav_active('profile', $active_menu)) ?>" href="<?= url('profile.php') ?>">
                    <i class="fa-regular fa-circle-user nav-icon"></i> My Profile
                </a>
            </li>
        </ul>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <strong><?= e(role_label($user['role'] ?? '')) ?></strong><br>
        <?= $user['branch_name'] ? e($user['branch_name']) : 'All branches access' ?>
        <div class="mt-1">v<?= e(APP_VERSION) ?> &middot; <?= date('Y') ?></div>
    </div>
</aside>
