<?php
/**
 * Inventory - CSV export.
 * Streams the filtered asset list (same filters as index.php) as a download.
 */
require_once __DIR__ . '/../init.php';
require_login();

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];

/* ----------------------------- Filters (GET) ----------------------------- */
$q          = get_param('q');
$branchId   = get_param('branch_id');
$deptId     = get_param('department_id');
$categoryId = get_param('category_id');
$brandId    = get_param('brand_id');
$status     = get_param('status');
$condition  = get_param('asset_condition');
$dateFrom   = get_param('purchase_date_from');
$dateTo     = get_param('purchase_date_to');

$where  = '1 = 1';
$params = [];

if ($q !== '') {
    $term = '%' . $q . '%';
    $where .= ' AND (i.asset_code LIKE :q1 OR i.equipment_name LIKE :q2 OR i.serial_number LIKE :q3'
            . ' OR i.model_number LIKE :q4 OR b.brand_name LIKE :q5 OR emp.employee_name LIKE :q6'
            . ' OR br.branch_name LIKE :q7 OR d.department_name LIKE :q8)';
    foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8'] as $key) {
        $params[$key] = $term;
    }
}
if ($branchId !== '') {
    $where .= ' AND i.branch_id = :f_branch';
    $params['f_branch'] = (int) $branchId;
}
if ($deptId !== '') {
    $where .= ' AND i.department_id = :f_dept';
    $params['f_dept'] = (int) $deptId;
}
if ($categoryId !== '') {
    $where .= ' AND i.category_id = :f_cat';
    $params['f_cat'] = (int) $categoryId;
}
if ($brandId !== '') {
    $where .= ' AND i.brand_id = :f_brand';
    $params['f_brand'] = (int) $brandId;
}
if ($status !== '' && isset(asset_statuses()[$status])) {
    $where .= ' AND i.status = :f_status';
    $params['f_status'] = $status;
}
if ($condition !== '' && isset(asset_conditions()[$condition])) {
    $where .= ' AND i.asset_condition = :f_cond';
    $params['f_cond'] = $condition;
}
if ($dateFrom !== '' && is_valid_date($dateFrom)) {
    $where .= ' AND i.purchase_date >= :f_from';
    $params['f_from'] = $dateFrom;
}
if ($dateTo !== '' && is_valid_date($dateTo)) {
    $where .= ' AND i.purchase_date <= :f_to';
    $params['f_to'] = $dateTo;
}

$where .= $scopeSql;
$params = array_merge($params, $scopeParams);

$rows = db_all(
    "SELECT i.asset_code, i.equipment_name, i.model_number, i.serial_number, i.purchase_date,
            i.purchase_price, i.warranty_expiry, i.asset_condition, i.status, i.remarks, i.created_at,
            c.category_name, pc.category_name AS group_name, b.brand_name,
            br.branch_name, d.department_name, emp.employee_name
       FROM inventory i
       LEFT JOIN asset_categories c ON c.id = i.category_id
       LEFT JOIN asset_categories pc ON pc.id = c.parent_id
       LEFT JOIN brands b ON b.id = i.brand_id
       LEFT JOIN branches br ON br.id = i.branch_id
       LEFT JOIN departments d ON d.id = i.department_id
       LEFT JOIN employees emp ON emp.id = i.assigned_employee_id
      WHERE $where
      ORDER BY i.asset_code ASC",
    $params
);

$headers = [
    'Asset ID', 'Category', 'Group', 'Equipment', 'Brand', 'Model', 'Serial',
    'Branch', 'Department', 'Assigned To', 'Purchase Date', 'Purchase Price',
    'Warranty Expiry', 'Condition', 'Status', 'Remarks', 'Date Added',
];

$exportRows = [];
foreach ($rows as $row) {
    $exportRows[] = [
        $row['asset_code'],
        $row['category_name'],
        $row['group_name'],
        $row['equipment_name'],
        $row['brand_name'],
        $row['model_number'],
        $row['serial_number'],
        $row['branch_name'],
        $row['department_name'],
        $row['employee_name'],
        $row['purchase_date'],
        $row['purchase_price'],
        $row['warranty_expiry'],
        $row['asset_condition'],
        asset_statuses()[$row['status']] ?? $row['status'],
        $row['remarks'],
        $row['created_at'],
    ];
}

csv_download('balitech_inventory_assets.csv', $headers, $exportRows);
