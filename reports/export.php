<?php
/**
 * Reports - shared CSV / printable PDF exporter.
 *
 * Parameters:
 *   report = inventory|branch|department|employee|category|stock|maintenance|damaged|transfer
 *   format = csv|pdf
 * plus the same filter query params used by the report pages
 * (branch_id, department_id, category_id, brand_id, status, type,
 *  date_from, date_to, q, detail, include_retired).
 */
require_once __DIR__ . '/../init.php';
require_login();

$report = get_param('report');
$format = get_param('format', 'csv') === 'pdf' ? 'pdf' : 'csv';

$f = [
    'branch_id'       => (int) get_param('branch_id', 0),
    'department_id'   => (int) get_param('department_id', 0),
    'category_id'     => (int) get_param('category_id', 0),
    'brand_id'        => (int) get_param('brand_id', 0),
    'status'          => get_param('status'),
    'type'            => get_param('type'),
    'date_from'       => get_param('date_from'),
    'date_to'         => get_param('date_to'),
    'q'               => get_param('q'),
    'detail'          => (int) get_param('detail', 0),
    'include_retired' => (int) get_param('include_retired', 0),
];

/* =====================================================================
 * Report builders - each returns the same structure:
 *   ['title' => string, 'headers' => [...], 'rows' => [[...], ...], 'totals' => [...]]
 * ===================================================================*/

/** Build the WHERE clause for inventory based reports. */
function rpt_inventory_where(array $f, string $extra = ''): array
{
    $scope  = rbac_scope('i');
    $where  = ' WHERE 1 = 1 ' . $scope['sql'];
    $params = $scope['params'];

    if ($f['branch_id'] > 0) {
        $where .= ' AND i.branch_id = :branch';
        $params['branch'] = $f['branch_id'];
    }
    if ($f['department_id'] > 0) {
        $where .= ' AND i.department_id = :dept';
        $params['dept'] = $f['department_id'];
    }
    if ($f['category_id'] > 0) {
        $where .= ' AND i.category_id = :cat';
        $params['cat'] = $f['category_id'];
    }
    if ($f['brand_id'] > 0) {
        $where .= ' AND i.brand_id = :brand';
        $params['brand'] = $f['brand_id'];
    }
    if ($f['status'] !== '' && isset(asset_statuses()[$f['status']])) {
        $where .= ' AND i.status = :status';
        $params['status'] = $f['status'];
    }
    if (is_valid_date($f['date_from'])) {
        $where .= ' AND i.purchase_date >= :date_from';
        $params['date_from'] = $f['date_from'];
    }
    if (is_valid_date($f['date_to'])) {
        $where .= ' AND i.purchase_date <= :date_to';
        $params['date_to'] = $f['date_to'];
    }
    if ($f['q'] !== '') {
        $where .= ' AND (i.asset_code LIKE :kw1 OR i.equipment_name LIKE :kw2 OR i.serial_number LIKE :kw3)';
        $like = '%' . $f['q'] . '%';
        $params['kw1'] = $like;
        $params['kw2'] = $like;
        $params['kw3'] = $like;
    }
    if ($extra !== '') {
        $where .= $extra;
    }
    return [$where, $params];
}

function rpt_inventory(array $f): array
{
    [$where, $params] = rpt_inventory_where($f);

    $rows = db_all(
        "SELECT i.asset_code, c.category_name, p.category_name AS group_name, i.equipment_name,
                b.brand_name, i.model_number, i.serial_number, br.branch_name, d.department_name,
                e.employee_name, i.purchase_date, i.purchase_price, i.warranty_expiry,
                i.asset_condition, i.status
           FROM inventory i
           LEFT JOIN asset_categories c ON c.id = i.category_id
           LEFT JOIN asset_categories p ON p.id = c.parent_id
           LEFT JOIN brands b ON b.id = i.brand_id
           LEFT JOIN branches br ON br.id = i.branch_id
           LEFT JOIN departments d ON d.id = i.department_id
           LEFT JOIN employees e ON e.id = i.assigned_employee_id
          $where
          ORDER BY i.asset_code", $params
    );

    $out = [];
    $totalValue = 0.0;
    foreach ($rows as $row) {
        $totalValue += (float) $row['purchase_price'];
        $out[] = [
            $row['asset_code'],
            $row['category_name'] ?: '-',
            $row['group_name'] ?: '-',
            $row['equipment_name'],
            $row['brand_name'] ?: '-',
            $row['model_number'] ?: '-',
            $row['serial_number'] ?: '-',
            $row['branch_name'] ?: '-',
            $row['department_name'] ?: '-',
            $row['employee_name'] ?: '-',
            fdate($row['purchase_date']),
            money($row['purchase_price'], false),
            fdate($row['warranty_expiry']),
            $row['asset_condition'] ?: '-',
            asset_statuses()[$row['status']] ?? $row['status'],
        ];
    }

    return [
        'title'   => 'Complete Inventory Report',
        'meta'    => ['Records' => count($out), 'Total value' => money($totalValue)],
        'headers' => ['Asset ID', 'Category', 'Group', 'Equipment', 'Brand', 'Model', 'Serial',
                      'Branch', 'Department', 'Assigned To', 'Purchase Date', 'Price', 'Warranty',
                      'Condition', 'Status'],
        'rows'    => $out,
        'totals'  => ['Total records' => count($out), 'Total value' => money($totalValue)],
    ];
}

function rpt_branch(array $f): array
{
    $scope  = rbac_scope('i');
    $params = $scope['params'];
    $filter = '';
    if ($f['branch_id'] > 0) {
        $filter = ' AND b.id = :branch';
        $params['branch'] = $f['branch_id'];
    }

    $rows = db_all(
        "SELECT b.branch_name, b.city, b.branch_manager,
                COUNT(i.id) AS total_assets,
                SUM(i.status = 'assigned') AS assigned,
                SUM(i.status = 'available') AS available,
                SUM(i.status = 'under_maintenance') AS under_maintenance,
                SUM(i.status = 'damaged') AS damaged,
                SUM(i.status = 'retired') AS retired,
                COALESCE(SUM(i.purchase_price), 0) AS total_value
           FROM branches b
           LEFT JOIN inventory i ON i.branch_id = b.id " . $scope['sql'] . "
          WHERE b.status = 'active' $filter
          GROUP BY b.id, b.branch_name, b.city, b.branch_manager
          ORDER BY total_assets DESC, b.branch_name", $params
    );

    $grand = (int) array_sum(array_map(static fn($r) => (int) $r['total_assets'], $rows));
    $out = [];
    $totalValue = 0.0;
    foreach ($rows as $row) {
        $totalValue += (float) $row['total_value'];
        $share = $grand > 0 ? round(((int) $row['total_assets'] / $grand) * 100, 1) : 0;
        $out[] = [
            $row['branch_name'],
            $row['city'] ?: '-',
            $row['branch_manager'] ?: '-',
            (int) $row['total_assets'],
            (int) $row['assigned'],
            (int) $row['available'],
            (int) $row['under_maintenance'],
            (int) $row['damaged'],
            (int) $row['retired'],
            money($row['total_value'], false),
            $share . '%',
        ];
    }

    return [
        'title'   => 'Branch-wise Inventory Report',
        'meta'    => ['Branches' => count($out), 'Total assets' => $grand, 'Total value' => money($totalValue)],
        'headers' => ['Branch', 'City', 'Manager', 'Total Assets', 'Assigned', 'Available',
                      'Under Maintenance', 'Damaged', 'Retired', 'Total Value', 'Share'],
        'rows'    => $out,
        'totals'  => ['Total branches' => count($out), 'Total assets' => $grand, 'Total value' => money($totalValue)],
    ];
}

function rpt_department(array $f): array
{
    $scope  = rbac_scope('i');
    $params = $scope['params'];
    $filter = '';
    if ($f['department_id'] > 0) {
        $filter = ' AND d.id = :dept';
        $params['dept'] = $f['department_id'];
    }
    if ($f['branch_id'] > 0) {
        $filter .= ' AND d.branch_id = :branch';
        $params['branch'] = $f['branch_id'];
    }

    $rows = db_all(
        "SELECT d.department_name, br.branch_name, d.dept_head,
                COUNT(i.id) AS total_assets,
                SUM(i.status = 'assigned') AS assigned,
                SUM(i.status = 'available') AS available,
                SUM(i.status = 'under_maintenance') AS under_maintenance,
                SUM(i.status = 'damaged') AS damaged,
                COALESCE(SUM(i.purchase_price), 0) AS total_value
           FROM departments d
           LEFT JOIN branches br ON br.id = d.branch_id
           LEFT JOIN inventory i ON i.department_id = d.id " . $scope['sql'] . "
          WHERE d.status = 'active' $filter
          GROUP BY d.id, d.department_name, br.branch_name, d.dept_head
          ORDER BY total_assets DESC, d.department_name", $params
    );

    $out = [];
    $totalValue = 0.0;
    $grand = 0;
    foreach ($rows as $row) {
        $grand += (int) $row['total_assets'];
        $totalValue += (float) $row['total_value'];
        $out[] = [
            $row['department_name'],
            $row['branch_name'] ?: '-',
            $row['dept_head'] ?: '-',
            (int) $row['total_assets'],
            (int) $row['assigned'],
            (int) $row['available'],
            (int) $row['under_maintenance'],
            (int) $row['damaged'],
            money($row['total_value'], false),
        ];
    }

    return [
        'title'   => 'Department-wise Inventory Report',
        'meta'    => ['Departments' => count($out), 'Total assets' => $grand, 'Total value' => money($totalValue)],
        'headers' => ['Department', 'Branch', 'Head', 'Total Assets', 'Assigned', 'Available',
                      'Maintenance', 'Damaged', 'Total Value'],
        'rows'    => $out,
        'totals'  => ['Total departments' => count($out), 'Total assets' => $grand, 'Total value' => money($totalValue)],
    ];
}

function rpt_employee(array $f): array
{
    $scope  = rbac_scope('i');
    $params = $scope['params'];
    $filter = '';
    if ($f['branch_id'] > 0) {
        $filter .= ' AND e.branch_id = :branch';
        $params['branch'] = $f['branch_id'];
    }
    if ($f['department_id'] > 0) {
        $filter .= ' AND e.department_id = :dept';
        $params['dept'] = $f['department_id'];
    }

    if ($f['detail']) {
        $rows = db_all(
            "SELECT i.asset_code, i.equipment_name, e.employee_name, e.emp_code, br.branch_name,
                    d.department_name, e.designation, i.purchase_date, i.purchase_price, i.status
               FROM inventory i
               JOIN employees e ON e.id = i.assigned_employee_id
               LEFT JOIN branches br ON br.id = i.branch_id
               LEFT JOIN departments d ON d.id = i.department_id
              WHERE 1 = 1 " . $scope['sql'] . " $filter
              ORDER BY e.employee_name, i.asset_code", $params
        );
        $out = [];
        $totalValue = 0.0;
        foreach ($rows as $row) {
            $totalValue += (float) $row['purchase_price'];
            $out[] = [
                $row['asset_code'], $row['equipment_name'], $row['employee_name'],
                $row['emp_code'], $row['branch_name'] ?: '-', $row['department_name'] ?: '-',
                $row['designation'] ?: '-', fdate($row['purchase_date']),
                money($row['purchase_price'], false), asset_statuses()[$row['status']] ?? $row['status'],
            ];
        }
        return [
            'title'   => 'Employee-wise Asset Report (detailed)',
            'meta'    => ['Assets' => count($out), 'Total value' => money($totalValue)],
            'headers' => ['Asset ID', 'Equipment', 'Employee', 'Emp Code', 'Branch', 'Department',
                          'Designation', 'Purchase Date', 'Price', 'Status'],
            'rows'    => $out,
            'totals'  => ['Total assets' => count($out), 'Total value' => money($totalValue)],
        ];
    }

    $rows = db_all(
        "SELECT e.employee_name, e.emp_code, br.branch_name, d.department_name, e.designation,
                COUNT(i.id) AS total_assets,
                GROUP_CONCAT(i.asset_code ORDER BY i.asset_code SEPARATOR ', ') AS asset_codes,
                COALESCE(SUM(i.purchase_price), 0) AS total_value
           FROM employees e
           LEFT JOIN branches br ON br.id = e.branch_id
           LEFT JOIN departments d ON d.id = e.department_id
           LEFT JOIN inventory i ON i.assigned_employee_id = e.id " . $scope['sql'] . "
          WHERE e.status = 'active' $filter
          GROUP BY e.id, e.employee_name, e.emp_code, br.branch_name, d.department_name, e.designation
          ORDER BY total_assets DESC, e.employee_name", $params
    );

    $out = [];
    $totalValue = 0.0;
    foreach ($rows as $row) {
        $codes = trim((string) $row['asset_codes']);
        $list  = $codes === '' ? [] : explode(', ', $codes);
        $shown = array_slice($list, 0, 5);
        $label = $shown ? implode(', ', $shown) : '-';
        if (count($list) > 5) {
            $label .= ' +' . (count($list) - 5) . ' more';
        }
        $totalValue += (float) $row['total_value'];
        $out[] = [
            $row['employee_name'], $row['emp_code'], $row['branch_name'] ?: '-',
            $row['department_name'] ?: '-', $row['designation'] ?: '-',
            (int) $row['total_assets'], $label, money($row['total_value'], false),
        ];
    }

    return [
        'title'   => 'Employee-wise Asset Report',
        'meta'    => ['Employees' => count($out), 'Total value' => money($totalValue)],
        'headers' => ['Employee', 'Emp Code', 'Branch', 'Department', 'Designation',
                      'Assets', 'Asset Codes', 'Total Value'],
        'rows'    => $out,
        'totals'  => ['Total employees' => count($out), 'Total value' => money($totalValue)],
    ];
}

function rpt_category(array $f): array
{
    $scope  = rbac_scope('i');
    $params = $scope['params'];
    $filter = '';
    if ($f['category_id'] > 0) {
        $filter .= ' AND c.id = :cat';
        $params['cat'] = $f['category_id'];
    }

    $rows = db_all(
        "SELECT c.category_name, p.category_name AS parent_name,
                COUNT(i.id) AS total_assets,
                SUM(i.status = 'available') AS available,
                SUM(i.status = 'assigned') AS assigned,
                SUM(i.status = 'under_maintenance') AS under_maintenance,
                SUM(i.status = 'damaged') AS damaged,
                COALESCE(SUM(i.purchase_price), 0) AS total_value,
                c.low_stock_threshold
           FROM asset_categories c
           LEFT JOIN asset_categories p ON p.id = c.parent_id
           LEFT JOIN inventory i ON i.category_id = c.id " . $scope['sql'] . "
          WHERE c.status = 'active' AND c.parent_id IS NOT NULL $filter
          GROUP BY c.id, c.category_name, p.category_name, c.low_stock_threshold
          ORDER BY p.category_name, c.category_name", $params
    );

    $out = [];
    $totalValue = 0.0;
    $grand = 0;
    foreach ($rows as $row) {
        $grand += (int) $row['total_assets'];
        $totalValue += (float) $row['total_value'];
        $low = (int) $row['available'] < (int) $row['low_stock_threshold'] ? 'LOW' : '';
        $out[] = [
            $row['category_name'], $row['parent_name'] ?: '-', (int) $row['total_assets'],
            (int) $row['available'], (int) $row['assigned'], (int) $row['under_maintenance'],
            (int) $row['damaged'], money($row['total_value'], false),
            (int) $row['low_stock_threshold'], $low,
        ];
    }

    return [
        'title'   => 'Category-wise Inventory Report',
        'meta'    => ['Categories' => count($out), 'Total assets' => $grand, 'Total value' => money($totalValue)],
        'headers' => ['Category', 'Parent Group', 'Total Assets', 'Available', 'Assigned',
                      'Maintenance', 'Damaged', 'Total Value', 'Threshold', 'Low Stock'],
        'rows'    => $out,
        'totals'  => ['Total categories' => count($out), 'Total assets' => $grand, 'Total value' => money($totalValue)],
    ];
}

function rpt_stock(array $f): array
{
    $scope  = rbac_scope('i');
    $params = $scope['params'];
    $filter = '';
    if ($f['category_id'] > 0) {
        $filter .= ' AND c.id = :cat';
        $params['cat'] = $f['category_id'];
    }
    if ($f['branch_id'] > 0) {
        $filter .= ' AND i.branch_id = :branch';
        $params['branch'] = $f['branch_id'];
    }

    $rows = db_all(
        "SELECT c.category_name,
                COUNT(i.id) AS total_units,
                SUM(i.status = 'available') AS available,
                SUM(i.status = 'assigned') AS assigned,
                SUM(i.status = 'under_maintenance') AS under_maintenance,
                SUM(i.status = 'damaged') AS damaged,
                SUM(i.status = 'retired') AS retired
           FROM asset_categories c
           LEFT JOIN inventory i ON i.category_id = c.id " . $scope['sql'] . "
          WHERE c.status = 'active' AND c.parent_id IS NOT NULL $filter
          GROUP BY c.id, c.category_name
          ORDER BY c.category_name", $params
    );

    $out = [];
    $totals = ['total' => 0, 'available' => 0, 'assigned' => 0, 'maint' => 0, 'damaged' => 0, 'retired' => 0];
    foreach ($rows as $row) {
        $totals['total']     += (int) $row['total_units'];
        $totals['available'] += (int) $row['available'];
        $totals['assigned']  += (int) $row['assigned'];
        $totals['maint']     += (int) $row['under_maintenance'];
        $totals['damaged']   += (int) $row['damaged'];
        $totals['retired']   += (int) $row['retired'];
        $pct = (int) $row['total_units'] > 0 ? round(((int) $row['available'] / (int) $row['total_units']) * 100, 1) : 0;
        $out[] = [
            $row['category_name'], (int) $row['total_units'], (int) $row['available'],
            (int) $row['assigned'], (int) $row['under_maintenance'], (int) $row['damaged'],
            (int) $row['retired'], $pct . '%',
        ];
    }

    return [
        'title'   => 'Available Stock Report',
        'meta'    => ['Categories' => count($out), 'Total units' => $totals['total'], 'Available' => $totals['available']],
        'headers' => ['Category', 'Total Units', 'Available', 'Assigned', 'Under Maintenance',
                      'Damaged', 'Retired', 'Available %'],
        'rows'    => $out,
        'totals'  => ['Total units' => $totals['total'], 'Available' => $totals['available'],
                      'Assigned' => $totals['assigned'], 'Maintenance' => $totals['maint'],
                      'Damaged' => $totals['damaged'], 'Retired' => $totals['retired']],
        'totals_row' => ['Total', $totals['total'], $totals['available'], $totals['assigned'],
                         $totals['maint'], $totals['damaged'], $totals['retired'],
                         ($totals['total'] > 0 ? round(($totals['available'] / $totals['total']) * 100, 1) . '%' : '0%')],
    ];
}

function rpt_maintenance(array $f): array
{
    $scope  = rbac_scope('i');
    $where  = ' WHERE 1 = 1 ' . $scope['sql'];
    $params = $scope['params'];

    if ($f['status'] !== '' && isset(maintenance_statuses()[$f['status']])) {
        $where .= ' AND m.status = :status';
        $params['status'] = $f['status'];
    }
    if ($f['type'] !== '' && isset(maintenance_types()[$f['type']])) {
        $where .= ' AND m.maintenance_type = :type';
        $params['type'] = $f['type'];
    }
    if ($f['branch_id'] > 0) {
        $where .= ' AND i.branch_id = :branch';
        $params['branch'] = $f['branch_id'];
    }
    if (is_valid_date($f['date_from'])) {
        $where .= ' AND m.reported_date >= :date_from';
        $params['date_from'] = $f['date_from'];
    }
    if (is_valid_date($f['date_to'])) {
        $where .= ' AND m.reported_date <= :date_to';
        $params['date_to'] = $f['date_to'];
    }

    $rows = db_all(
        "SELECT m.id, i.asset_code, m.issue_title, m.maintenance_type, m.technician_name, m.vendor,
                m.reported_date, m.repair_date, m.cost, m.status
           FROM maintenance_records m
           JOIN inventory i ON i.id = m.asset_id
          $where
          ORDER BY m.reported_date DESC, m.id DESC", $params
    );

    $out = [];
    $totalCost = 0.0;
    foreach ($rows as $row) {
        $totalCost += (float) $row['cost'];
        $out[] = [
            '#' . (int) $row['id'], $row['asset_code'], $row['issue_title'],
            maintenance_types()[$row['maintenance_type']] ?? $row['maintenance_type'],
            $row['technician_name'] ?: '-', $row['vendor'] ?: '-',
            fdate($row['reported_date']), fdate($row['repair_date']),
            money($row['cost'], false), maintenance_statuses()[$row['status']] ?? $row['status'],
        ];
    }

    return [
        'title'   => 'Maintenance Report',
        'meta'    => ['Records' => count($out), 'Total cost' => money($totalCost)],
        'headers' => ['Record #', 'Asset', 'Issue', 'Type', 'Technician', 'Vendor',
                      'Reported', 'Repair Date', 'Cost', 'Status'],
        'rows'    => $out,
        'totals'  => ['Total records' => count($out), 'Total cost' => money($totalCost)],
    ];
}

function rpt_damaged(array $f): array
{
    $statuses = $f['include_retired'] ? "i.status IN ('damaged','retired')" : "i.status = 'damaged'";
    [$where, $params] = rpt_inventory_where($f, ' AND ' . $statuses);

    $rows = db_all(
        "SELECT i.asset_code, i.equipment_name, b.brand_name, i.serial_number, br.branch_name,
                d.department_name, i.asset_condition, i.status, i.purchase_date, i.purchase_price, i.remarks,
                (SELECT m.issue_title FROM maintenance_records m WHERE m.asset_id = i.id
                  ORDER BY m.reported_date DESC, m.id DESC LIMIT 1) AS last_issue,
                (SELECT m.cost FROM maintenance_records m WHERE m.asset_id = i.id
                  ORDER BY m.reported_date DESC, m.id DESC LIMIT 1) AS last_cost
           FROM inventory i
           LEFT JOIN brands b ON b.id = i.brand_id
           LEFT JOIN branches br ON br.id = i.branch_id
           LEFT JOIN departments d ON d.id = i.department_id
          $where
          ORDER BY i.asset_code", $params
    );

    $out = [];
    $totalValue = 0.0;
    foreach ($rows as $row) {
        $totalValue += (float) $row['purchase_price'];
        $out[] = [
            $row['asset_code'], $row['equipment_name'], $row['brand_name'] ?: '-',
            $row['serial_number'] ?: '-', $row['branch_name'] ?: '-', $row['department_name'] ?: '-',
            $row['asset_condition'] ?: '-', asset_statuses()[$row['status']] ?? $row['status'],
            fdate($row['purchase_date']), money($row['purchase_price'], false),
            $row['remarks'] ?: '-',
            $row['last_issue'] ?: '-',
            $row['last_cost'] !== null ? money($row['last_cost'], false) : '-',
        ];
    }

    return [
        'title'   => 'Damaged Equipment Report',
        'meta'    => ['Assets' => count($out), 'Book value' => money($totalValue)],
        'headers' => ['Asset ID', 'Equipment', 'Brand', 'Serial', 'Branch', 'Department',
                      'Condition', 'Status', 'Purchase Date', 'Price', 'Remarks',
                      'Latest Issue', 'Latest Cost'],
        'rows'    => $out,
        'totals'  => ['Damaged assets' => count($out), 'Total book value' => money($totalValue)],
    ];
}

function rpt_transfer(array $f): array
{
    $scope  = rbac_scope('i');
    $where  = ' WHERE 1 = 1 ' . $scope['sql'];
    $params = $scope['params'];

    if ($f['branch_id'] > 0) {
        $where .= ' AND (t.from_branch_id = :branch OR t.to_branch_id = :branch2)';
        $params['branch']  = $f['branch_id'];
        $params['branch2'] = $f['branch_id'];
    }
    if ($f['status'] !== '' && in_array($f['status'], ['pending', 'completed', 'cancelled'], true)) {
        $where .= ' AND t.status = :status';
        $params['status'] = $f['status'];
    }
    if (is_valid_date($f['date_from'])) {
        $where .= ' AND t.transfer_date >= :date_from';
        $params['date_from'] = $f['date_from'];
    }
    if (is_valid_date($f['date_to'])) {
        $where .= ' AND t.transfer_date <= :date_to';
        $params['date_to'] = $f['date_to'];
    }

    $rows = db_all(
        "SELECT t.transfer_date, i.asset_code, fb.branch_name AS from_branch, tb.branch_name AS to_branch,
                fd.department_name AS from_dept, td.department_name AS to_dept, t.reason,
                u.full_name AS transferred_by_name, t.status
           FROM asset_transfers t
           JOIN inventory i ON i.id = t.asset_id
           LEFT JOIN branches fb ON fb.id = t.from_branch_id
           LEFT JOIN branches tb ON tb.id = t.to_branch_id
           LEFT JOIN departments fd ON fd.id = t.from_department_id
           LEFT JOIN departments td ON td.id = t.to_department_id
           LEFT JOIN users u ON u.id = t.transferred_by
          $where
          ORDER BY t.transfer_date DESC, t.id DESC", $params
    );

    $out = [];
    $completed = 0;
    $pending = 0;
    foreach ($rows as $row) {
        if ($row['status'] === 'completed') {
            $completed++;
        } elseif ($row['status'] === 'pending') {
            $pending++;
        }
        $out[] = [
            fdate($row['transfer_date']), $row['asset_code'], $row['from_branch'] ?: '-',
            $row['to_branch'] ?: '-', $row['from_dept'] ?: '-', $row['to_dept'] ?: '-',
            $row['reason'] ?: '-', $row['transferred_by_name'] ?: '-', ucfirst((string) $row['status']),
        ];
    }

    return [
        'title'   => 'Equipment Transfer Report',
        'meta'    => ['Transfers' => count($out), 'Completed' => $completed, 'Pending' => $pending],
        'headers' => ['Transfer Date', 'Asset', 'From Branch', 'To Branch', 'From Department',
                      'To Department', 'Reason', 'Transferred By', 'Status'],
        'rows'    => $out,
        'totals'  => ['Total transfers' => count($out), 'Completed' => $completed, 'Pending' => $pending],
    ];
}

/** Dispatch to the requested report builder. */
function rpt_build(string $report, array $f): ?array
{
    switch ($report) {
        case 'inventory':   return rpt_inventory($f);
        case 'branch':      return rpt_branch($f);
        case 'department':  return rpt_department($f);
        case 'employee':    return rpt_employee($f);
        case 'category':    return rpt_category($f);
        case 'stock':       return rpt_stock($f);
        case 'maintenance': return rpt_maintenance($f);
        case 'damaged':     return rpt_damaged($f);
        case 'transfer':    return rpt_transfer($f);
        default:            return null;
    }
}

/** Render the standalone printable PDF (browser print dialog) page. */
function rpt_render_pdf(string $report, array $data): void
{
    $headers = $data['headers'];
    $rows    = $data['rows'];
    $totals  = $data['totals'] ?? [];
    $backQ   = $_GET;
    unset($backQ['report'], $backQ['format'], $backQ['page']);
    $back    = 'reports/' . $report . '.php' . ($backQ ? '?' . http_build_query($backQ) : '');

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($data['title']) . ' - ' . e(APP_COMPANY) . '</title>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">';
    echo '<link rel="stylesheet" href="' . e(url('assets/css/style.css')) . '">';
    echo '</head><body>';
    echo '<div class="container-fluid p-4">';

    echo '<div class="print-toolbar no-print">';
    echo '<a class="btn btn-outline-brand" href="' . e(url($back)) . '"><i class="fa-solid fa-arrow-left me-2"></i>Back to report</a>';
    echo '<span class="text-muted-sm">' . e($data['title']) . ' &middot; ' . count($rows) . ' row(s)</span>';
    echo '<button class="btn btn-brand" type="button" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>Print / Save as PDF</button>';
    echo '</div>';

    echo pdf_report_header($data['title'], $data['meta'] ?? []);

    echo '<table class="table table-bordered align-middle"><thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . e($header) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="' . count($headers) . '" class="text-center text-muted py-4">(no records found)</td></tr>';
    }
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . e((string) $cell) . '</td>';
        }
        echo '</tr>';
    }
    if (!empty($data['totals_row'])) {
        echo '<tr class="fw-bold">';
        foreach ($data['totals_row'] as $cell) {
            echo '<td>' . e((string) $cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';

    if ($totals) {
        echo '<div class="pdf-meta mt-3">';
        foreach ($totals as $label => $value) {
            echo '<span><b>' . e($label) . ':</b> ' . e((string) $value) . '</span>';
        }
        echo '</div>';
    }

    echo '<div class="pdf-footer"><span>' . e(APP_COMPANY) . ' - IT Inventory Management System</span>'
       . '<span>Generated ' . date('d M Y, h:i A') . ' &middot; Page 1</span></div>';
    echo '</div>';
    echo '<script>window.addEventListener("load", function () { window.print(); });</script>';
    echo '</body></html>';
}

/* =====================================================================
 * Execute
 * ===================================================================*/
$data = rpt_build($report, $f);

if (!$data) {
    http_response_code(404);
    set_flash('danger', 'Unknown report requested.');
    redirect('reports/index.php');
}

if ($format === 'pdf') {
    rpt_render_pdf($report, $data);
    exit;
}

$filename = 'balitech-' . $report . '-report-' . date('Ymd-His') . '.csv';
csv_download($filename, $data['headers'], $data['rows']);
