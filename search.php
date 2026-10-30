<?php
/**
 * Global search - searches assets, employees, branches and departments
 * in one place. Results respect the data scope of the signed in role.
 */
require_once __DIR__ . '/init.php';
require_login();

$term  = get_param('q');
$type  = get_param('type', 'all');
$user  = current_user();
$scope = rbac_scope('i');

$assets      = [];
$employees   = [];
$branches    = [];
$departments = [];
$searched    = $term !== '' && mb_strlen($term) >= 2;

if ($searched) {
    $like = '%' . $term . '%';

    /* ------------------------------------------------ asset matches */
    if ($type === 'all' || $type === 'assets') {
        $sql = "SELECT i.id, i.asset_code, i.equipment_name, i.model_number, i.serial_number,
                       i.purchase_date, i.purchase_price, i.status, i.asset_condition,
                       c.category_name, b.brand_name, br.branch_name, d.department_name,
                       e.employee_name
                  FROM inventory i
                  LEFT JOIN asset_categories c ON c.id = i.category_id
                  LEFT JOIN brands b ON b.id = i.brand_id
                  LEFT JOIN branches br ON br.id = i.branch_id
                  LEFT JOIN departments d ON d.id = i.department_id
                  LEFT JOIN employees e ON e.id = i.assigned_employee_id
                 WHERE (i.asset_code LIKE :a1 OR i.equipment_name LIKE :a2 OR i.serial_number LIKE :a3
                     OR i.model_number LIKE :a4 OR c.category_name LIKE :a5
                     OR b.brand_name LIKE :a6 OR e.employee_name LIKE :a7
                     OR br.branch_name LIKE :a8 OR d.department_name LIKE :a9)"
             . $scope['sql']
             . ' ORDER BY i.asset_code LIMIT 60';

        $assets = db_all($sql, [
            'a1' => $like, 'a2' => $like, 'a3' => $like, 'a4' => $like, 'a5' => $like,
            'a6' => $like, 'a7' => $like, 'a8' => $like, 'a9' => $like,
        ] + $scope['params']);
    }

    /* --------------------------------------------- employee matches */
    if (($type === 'all' || $type === 'employees') && !is_role('dept_manager')) {
        $extra = '';
        $params = ['e1' => $like, 'e2' => $like, 'e3' => $like, 'e4' => $like];
        if (is_role('branch_staff') && !empty($user['branch_id'])) {
            $extra = ' AND e.branch_id = :e_branch';
            $params['e_branch'] = (int) $user['branch_id'];
        }
        $employees = db_all(
            "SELECT e.id, e.emp_code, e.employee_name, e.designation, e.email, e.contact_number,
                    b.branch_name, d.department_name, e.status,
                    (SELECT COUNT(*) FROM asset_assignments a
                      WHERE a.employee_id = e.id AND a.assignment_status = 'assigned') AS assigned_count
               FROM employees e
               LEFT JOIN branches b ON b.id = e.branch_id
               LEFT JOIN departments d ON d.id = e.department_id
              WHERE (e.emp_code LIKE :e1 OR e.employee_name LIKE :e2 OR e.email LIKE :e3 OR e.designation LIKE :e4)
              $extra
              ORDER BY e.employee_name LIMIT 40", $params
        );
    }

    /* --------------------------------------------- branch / department */
    if ($type === 'all' || $type === 'organisation') {
        if (!is_role('branch_staff')) {
            $branches = db_all(
                'SELECT b.id, b.branch_code, b.branch_name, b.city, b.branch_manager, b.contact_number, b.status,
                        (SELECT COUNT(*) FROM inventory i WHERE i.branch_id = b.id) AS asset_count
                   FROM branches b
                  WHERE (b.branch_code LIKE :b1 OR b.branch_name LIKE :b2 OR b.city LIKE :b3 OR b.branch_manager LIKE :b4)
                  ORDER BY b.branch_name LIMIT 20',
                ['b1' => $like, 'b2' => $like, 'b3' => $like, 'b4' => $like]
            );
        }
        if (!is_role('dept_manager')) {
            $deptParams = ['d1' => $like, 'd2' => $like, 'd3' => $like];
            $deptExtra  = '';
            if (is_role('branch_staff') && !empty($user['branch_id'])) {
                $deptExtra = ' AND (d.branch_id = :d_branch OR d.branch_id IS NULL)';
                $deptParams['d_branch'] = (int) $user['branch_id'];
            }
            $departments = db_all(
                "SELECT d.id, d.dept_code, d.department_name, d.dept_head, d.status,
                        b.branch_name,
                        (SELECT COUNT(*) FROM inventory i WHERE i.department_id = d.id) AS asset_count
                   FROM departments d
                   LEFT JOIN branches b ON b.id = d.branch_id
                  WHERE (d.dept_code LIKE :d1 OR d.department_name LIKE :d2 OR d.dept_head LIKE :d3)
                  $deptExtra
                  ORDER BY d.department_name LIMIT 20", $deptParams
            );
        }
    }
}

$totalResults = count($assets) + count($employees) + count($branches) + count($departments);

$page_title    = 'Global Search';
$page_subtitle = $searched ? $totalResults . ' result(s) for "' . $term . '"' : 'Search across the whole inventory database';
$active_menu   = 'search';

require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Global search</li></ol></nav>
        <h2>Global search</h2>
        <p>Search by asset ID, serial number, brand, model, equipment name, employee, branch or department.</p>
    </div>
</div>

<div class="filter-bar">
    <form method="get" action="<?= url('search.php') ?>" class="row g-2 align-items-end">
        <div class="col-lg-8">
            <label class="form-label" for="q">Search term</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($term) ?>" autofocus
                       placeholder="e.g. BLT-00001, SN12345, Dell, Latitude, Ali Hassan, Lahore, IT Department">
            </div>
        </div>
        <div class="col-lg-2">
            <label class="form-label" for="type">Limit to</label>
            <select class="form-select" id="type" name="type">
                <option value="all"<?= selected('all', $type) ?>>Everything</option>
                <option value="assets"<?= selected('assets', $type) ?>>Assets only</option>
                <option value="employees"<?= selected('employees', $type) ?>>Employees only</option>
                <option value="organisation"<?= selected('organisation', $type) ?>>Branches &amp; departments</option>
            </select>
        </div>
        <div class="col-lg-2">
            <button class="btn btn-brand w-100" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Search</button>
        </div>
    </form>
</div>

<?php if (!$searched): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fa-solid fa-magnifying-glass"></i>
                <h4>Start typing to search</h4>
                <p>Enter at least two characters. Results are filtered automatically to the data you are allowed to see.</p>
            </div>
        </div>
    </div>
<?php else: ?>

    <!-- ------------------------------------------------------- assets -->
    <?php if ($type === 'all' || $type === 'assets'): ?>
    <div class="card mb-4">
        <div class="card-header">
            <div class="stat-icon bg-soft-blue" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div>
                <h3 class="mb-0">Assets (<?= count($assets) ?>)</h3>
                <p class="sub mb-0">Inventory items matching your search</p>
            </div>
            <a class="btn btn-sm btn-outline-brand ms-auto" href="<?= url('inventory/index.php?q=' . urlencode($term)) ?>">Open in inventory module</a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Asset ID</th><th>Equipment</th><th>Serial / Model</th><th>Category</th>
                        <th>Branch</th><th>Department</th><th>Assigned To</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (!$assets): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No matching assets.</td></tr>
                <?php endif; ?>
                <?php foreach ($assets as $asset): ?>
                    <tr>
                        <td><a class="fw-code" href="<?= url('inventory/view.php?id=' . (int) $asset['id']) ?>"><?= e($asset['asset_code']) ?></a></td>
                        <td>
                            <div><?= e($asset['equipment_name']) ?></div>
                            <div class="text-muted-sm"><?= e($asset['brand_name'] ?: '-') ?></div>
                        </td>
                        <td class="text-muted-sm"><?= e($asset['serial_number'] ?: '-') ?><br><?= e($asset['model_number'] ?: '-') ?></td>
                        <td class="text-muted-sm"><?= e($asset['category_name'] ?: '-') ?></td>
                        <td class="text-muted-sm"><?= e($asset['branch_name'] ?: '-') ?></td>
                        <td class="text-muted-sm"><?= e($asset['department_name'] ?: '-') ?></td>
                        <td class="text-muted-sm"><?= e($asset['employee_name'] ?: '-') ?></td>
                        <td><?= status_badge($asset['status']) ?></td>
                        <td class="text-end"><a class="btn-icon" title="View asset" href="<?= url('inventory/view.php?id=' . (int) $asset['id']) ?>"><i class="fa-solid fa-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ---------------------------------------------------- employees -->
    <?php if (($type === 'all' || $type === 'employees') && !is_role('dept_manager')): ?>
    <div class="card mb-4">
        <div class="card-header">
            <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-users"></i></div>
            <div>
                <h3 class="mb-0">Employees (<?= count($employees) ?>)</h3>
                <p class="sub mb-0">Staff records matching your search</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Emp Code</th><th>Employee</th><th>Designation</th><th>Branch</th><th>Department</th><th>Contact</th><th>Assigned Assets</th><th></th></tr></thead>
                <tbody>
                <?php if (!$employees): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No matching employees.</td></tr>
                <?php endif; ?>
                <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td class="fw-code"><?= e($employee['emp_code']) ?></td>
                        <td><?= e($employee['employee_name']) ?></td>
                        <td class="text-muted-sm"><?= e($employee['designation'] ?: '-') ?></td>
                        <td class="text-muted-sm"><?= e($employee['branch_name'] ?: '-') ?></td>
                        <td class="text-muted-sm"><?= e($employee['department_name'] ?: '-') ?></td>
                        <td class="text-muted-sm"><?= e($employee['contact_number'] ?: '-') ?><br><?= e($employee['email'] ?: '') ?></td>
                        <td><span class="badge badge-soft badge-primary"><?= (int) $employee['assigned_count'] ?></span></td>
                        <td class="text-end"><a class="btn-icon" title="View employee" href="<?= url('employees/view.php?id=' . (int) $employee['id']) ?>"><i class="fa-solid fa-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- --------------------------------------- branches & departments -->
    <?php if (($type === 'all' || $type === 'organisation')): ?>
    <div class="row g-3">
        <?php if (!is_role('branch_staff')): ?>
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <div class="stat-icon bg-soft-navy" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-building"></i></div>
                    <div><h3 class="mb-0">Branches (<?= count($branches) ?>)</h3><p class="sub mb-0">Location records</p></div>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Code</th><th>Branch</th><th>City</th><th>Manager</th><th>Assets</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$branches): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No matching branches.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($branches as $branch): ?>
                            <tr>
                                <td class="fw-code"><?= e($branch['branch_code']) ?></td>
                                <td><?= e($branch['branch_name']) ?></td>
                                <td class="text-muted-sm"><?= e($branch['city']) ?></td>
                                <td class="text-muted-sm"><?= e($branch['branch_manager'] ?: '-') ?></td>
                                <td><span class="badge badge-soft badge-primary"><?= (int) $branch['asset_count'] ?></span></td>
                                <td class="text-end"><a class="btn-icon" title="View branch" href="<?= url('branches/view.php?id=' . (int) $branch['id']) ?>"><i class="fa-solid fa-eye"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!is_role('dept_manager')): ?>
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <div class="stat-icon bg-soft-teal" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-sitemap"></i></div>
                    <div><h3 class="mb-0">Departments (<?= count($departments) ?>)</h3><p class="sub mb-0">Department records</p></div>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Code</th><th>Department</th><th>Branch</th><th>Head</th><th>Assets</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$departments): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No matching departments.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($departments as $department): ?>
                            <tr>
                                <td class="fw-code"><?= e($department['dept_code']) ?></td>
                                <td><?= e($department['department_name']) ?></td>
                                <td class="text-muted-sm"><?= e($department['branch_name'] ?: '-') ?></td>
                                <td class="text-muted-sm"><?= e($department['dept_head'] ?: '-') ?></td>
                                <td><span class="badge badge-soft badge-primary"><?= (int) $department['asset_count'] ?></span></td>
                                <td class="text-end"><a class="btn-icon" title="View department" href="<?= url('departments/view.php?id=' . (int) $department['id']) ?>"><i class="fa-solid fa-eye"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($totalResults === 0): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fa-regular fa-face-frown"></i>
                <h4>No results found</h4>
                <p>Nothing matched "<b><?= e($term) ?></b>". Try a different asset ID, serial number or name.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
