<?php
/**
 * AJAX endpoint: global search suggestions (top bar auto complete).
 * GET api/search_suggest.php?q=dell
 */

require_once __DIR__ . '/../init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$term = get_param('q');
$results = [];

if (mb_strlen($term) >= 2) {
    $like  = '%' . $term . '%';
    $scope = rbac_scope('i');

    $sql = 'SELECT i.id, i.asset_code, i.equipment_name, i.serial_number, i.status,
                   b.brand_name, br.branch_name
              FROM inventory i
              LEFT JOIN brands b ON b.id = i.brand_id
              LEFT JOIN branches br ON br.id = i.branch_id
             WHERE (i.asset_code LIKE :term1
                 OR i.equipment_name LIKE :term2
                 OR i.serial_number LIKE :term3
                 OR i.model_number LIKE :term4
                 OR b.brand_name LIKE :term5)'
         . $scope['sql']
         . ' ORDER BY i.updated_at DESC LIMIT 8';

    $params = [
        'term1' => $like,
        'term2' => $like,
        'term3' => $like,
        'term4' => $like,
        'term5' => $like,
    ] + $scope['params'];

    try {
        foreach (db_all($sql, $params) as $row) {
            $results[] = [
                'code'  => $row['asset_code'],
                'title' => $row['equipment_name'],
                'meta'  => ($row['brand_name'] ? $row['brand_name'] . ' &middot; ' : '') . $row['branch_name'],
                'url'   => url('inventory/view.php?id=' . (int) $row['id']),
            ];
        }
    } catch (Throwable $e) {
        error_log('[BALITECH][api/search_suggest] ' . $e->getMessage());
    }
}

echo json_encode(['success' => true, 'results' => $results]);
