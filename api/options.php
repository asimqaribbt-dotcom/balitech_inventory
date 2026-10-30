<?php
/**
 * AJAX endpoint: cascading option lists.
 *
 * GET api/options.php?type=departments&branch_id=1
 * GET api/options.php?type=employees&branch_id=1&department_id=2
 * GET api/options.php?type=subcategories&parent_id=4
 *
 * Responds with {"success":true,"options":[{"id":1,"text":"IT Department"}]}
 */

require_once __DIR__ . '/../init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$type     = get_param('type');
$branchId = (int) get_param('branch_id', 0);
$deptId   = (int) get_param('department_id', 0);
$parentId = (int) get_param('parent_id', 0);

$options = [];

try {
    switch ($type) {
        case 'departments':
            $sql    = 'SELECT id, department_name AS text FROM departments WHERE status = "active"';
            $params = [];
            if ($branchId > 0) {
                // Departments of the branch plus branch-independent departments
                $sql .= ' AND (branch_id = ? OR branch_id IS NULL)';
                $params[] = $branchId;
            }
            $sql .= ' ORDER BY department_name';
            $options = db_all($sql, $params);
            break;

        case 'employees':
            $sql    = 'SELECT e.id, CONCAT(e.employee_name, " (", e.emp_code, ")") AS text
                         FROM employees e
                        WHERE e.status = "active"';
            $params = [];
            if ($branchId > 0) {
                $sql .= ' AND e.branch_id = ?';
                $params[] = $branchId;
            }
            if ($deptId > 0) {
                $sql .= ' AND e.department_id = ?';
                $params[] = $deptId;
            }
            $sql .= ' ORDER BY e.employee_name';
            $options = db_all($sql, $params);
            break;

        case 'subcategories':
            $sql    = 'SELECT id, CONCAT(category_name, " (", category_code, ")") AS text
                         FROM asset_categories
                        WHERE status = "active"';
            $params = [];
            if ($parentId > 0) {
                $sql .= ' AND parent_id = ?';
                $params[] = $parentId;
            } else {
                $sql .= ' AND parent_id IS NOT NULL';
            }
            $sql .= ' ORDER BY category_name';
            $options = db_all($sql, $params);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown option type.']);
            exit;
    }

    foreach ($options as &$option) {
        $option['id'] = (int) $option['id'];
        $option['text'] = (string) $option['text'];
    }
    unset($option);

    echo json_encode(['success' => true, 'options' => $options]);
} catch (Throwable $e) {
    error_log('[BALITECH][api/options] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load options.']);
}
