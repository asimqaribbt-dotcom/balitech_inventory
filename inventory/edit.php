<?php
/**
 * Inventory - edit an existing asset.
 * Same validation as add, plus status change tracking (activity log +
 * stock adjustment transaction when the asset leaves service).
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('inventory');

$user  = current_user();
$scope = rbac_scope('i');

$id    = (int) get_param('id', post('asset_id', 0));
$asset = $id > 0
    ? db_row("SELECT i.* FROM inventory i WHERE i.id = :id $scope[sql]", array_merge(['id' => $id], $scope['params']))
    : null;

if (!$asset) {
    set_flash('warning', 'The requested asset was not found or is outside your scope.');
    redirect('inventory/index.php');
}
if (is_role('branch_staff') && (int) $asset['branch_id'] !== (int) $user['branch_id']) {
    deny_access('You may only edit assets of your own branch.');
}

$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');
$brands   = db_all('SELECT id, brand_name FROM brands WHERE status = "active" ORDER BY brand_name');

$catRows   = db_all('SELECT id, category_name, parent_id FROM asset_categories WHERE status = "active" ORDER BY category_name');
$catTree   = [];
$catLeaves = [];
foreach ($catRows as $cat) {
    if ($cat['parent_id'] === null) {
        $catTree[(int) $cat['id']] = ['name' => $cat['category_name'], 'children' => []];
    } else {
        $catLeaves[] = $cat;
    }
}
foreach ($catLeaves as $cat) {
    $parentKey = (int) $cat['parent_id'];
    if (isset($catTree[$parentKey])) {
        $catTree[$parentKey]['children'][] = $cat;
    }
}

$form = [
    'category_id'     => (string) $asset['category_id'],
    'brand_id'        => $asset['brand_id'] !== null ? (string) $asset['brand_id'] : '',
    'equipment_name'  => (string) $asset['equipment_name'],
    'model_number'    => (string) $asset['model_number'],
    'serial_number'   => (string) $asset['serial_number'],
    'branch_id'       => $asset['branch_id'] !== null ? (string) $asset['branch_id'] : '',
    'department_id'   => $asset['department_id'] !== null ? (string) $asset['department_id'] : '',
    'purchase_date'   => (string) $asset['purchase_date'],
    'purchase_price'  => (string) $asset['purchase_price'],
    'warranty_expiry' => (string) $asset['warranty_expiry'],
    'asset_condition' => (string) $asset['asset_condition'],
    'status'          => (string) $asset['status'],
    'remarks'         => (string) $asset['remarks'],
];
$errors = [];

if (is_post()) {
    csrf_verify();

    $form['category_id']     = post('category_id');
    $form['brand_id']        = post('brand_id');
    $form['equipment_name']  = post('equipment_name');
    $form['model_number']    = post('model_number');
    $form['serial_number']   = post('serial_number');
    $form['branch_id']       = post('branch_id');
    $form['department_id']   = post('department_id');
    $form['purchase_date']   = post('purchase_date');
    $form['purchase_price']  = post('purchase_price');
    $form['warranty_expiry'] = post('warranty_expiry');
    $form['asset_condition'] = post('asset_condition', $asset['asset_condition']);
    $form['status']          = post('status', $asset['status']);
    $form['remarks']         = post('remarks');

    if ((int) $form['category_id'] <= 0
        || (int) db_value('SELECT COUNT(*) FROM asset_categories WHERE id = ?', [(int) $form['category_id']]) === 0) {
        $errors[] = 'Please choose a valid category.';
    }
    if ($form['equipment_name'] === '') {
        $errors[] = 'Equipment name is required.';
    }
    if ($form['serial_number'] === '') {
        $errors[] = 'Serial number is required.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM inventory WHERE LOWER(serial_number) = LOWER(?) AND id <> ?', [$form['serial_number'], $id]) > 0) {
        $errors[] = 'That serial number already exists on another asset.';
    }
    if ($form['branch_id'] === '' || (int) db_value('SELECT COUNT(*) FROM branches WHERE id = ?', [(int) $form['branch_id']]) === 0) {
        $errors[] = 'Please choose a valid branch.';
    }
    if ($form['department_id'] !== '' && (int) db_value('SELECT COUNT(*) FROM departments WHERE id = ?', [(int) $form['department_id']]) === 0) {
        $errors[] = 'The selected department does not exist.';
    }
    if ($form['purchase_date'] !== '' && !is_valid_date($form['purchase_date'])) {
        $errors[] = 'Purchase date is not a valid date.';
    }
    if ($form['warranty_expiry'] !== '' && !is_valid_date($form['warranty_expiry'])) {
        $errors[] = 'Warranty expiry is not a valid date.';
    }
    if ($form['purchase_price'] !== '' && (!is_numeric($form['purchase_price']) || (float) $form['purchase_price'] < 0)) {
        $errors[] = 'Purchase price must be a number greater than or equal to zero.';
    }
    if (!isset(asset_conditions()[$form['asset_condition']])) {
        $form['asset_condition'] = $asset['asset_condition'];
    }
    if (!isset(asset_statuses()[$form['status']])) {
        $form['status'] = $asset['status'];
    }

    if (!$errors) {
        $oldStatus = (string) $asset['status'];
        $newStatus = (string) $form['status'];
        $statusChanged = $oldStatus !== $newStatus;
        $pdo = db();

        try {
            $pdo->beginTransaction();

            $update = $pdo->prepare(
                'UPDATE inventory
                    SET category_id = :category_id,
                        brand_id = :brand_id,
                        equipment_name = :equipment_name,
                        model_number = :model_number,
                        serial_number = :serial_number,
                        branch_id = :branch_id,
                        department_id = :department_id,
                        purchase_date = :purchase_date,
                        purchase_price = :purchase_price,
                        warranty_expiry = :warranty_expiry,
                        asset_condition = :asset_condition,
                        status = :status,
                        remarks = :remarks
                  WHERE id = :id'
            );
            $update->execute([
                ':category_id'     => (int) $form['category_id'],
                ':brand_id'        => null_if_empty($form['brand_id']) !== null ? (int) $form['brand_id'] : null,
                ':equipment_name'  => $form['equipment_name'],
                ':model_number'    => null_if_empty($form['model_number']),
                ':serial_number'   => $form['serial_number'],
                ':branch_id'       => null_if_empty($form['branch_id']) !== null ? (int) $form['branch_id'] : null,
                ':department_id'   => null_if_empty($form['department_id']) !== null ? (int) $form['department_id'] : null,
                ':purchase_date'   => null_if_empty($form['purchase_date']),
                ':purchase_price'  => $form['purchase_price'] === '' ? 0 : (float) $form['purchase_price'],
                ':warranty_expiry' => null_if_empty($form['warranty_expiry']),
                ':asset_condition' => $form['asset_condition'],
                ':status'          => $newStatus,
                ':remarks'         => null_if_empty($form['remarks']),
                ':id'              => $id,
            ]);

            if ($statusChanged && in_array($newStatus, ['damaged', 'under_maintenance', 'retired'], true)) {
                $pdo->prepare(
                    'INSERT INTO stock_transactions
                        (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes, performed_by, transaction_date)
                     VALUES (?, ?, "adjustment", 1, ?, ?, ?, ?, ?)'
                )->execute([
                    $id,
                    (int) $form['category_id'],
                    null_if_empty($form['branch_id']) !== null ? (int) $form['branch_id'] : null,
                    'ADJ-' . date('Y') . '-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT),
                    'Status changed to ' . (asset_statuses()[$newStatus] ?? $newStatus),
                    current_user_id(),
                    date('Y-m-d'),
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[BALITECH][inventory/edit] ' . $e->getMessage());
            set_flash('danger', 'The asset could not be updated. Please try again.');
            redirect('inventory/edit.php?id=' . $id);
        }

        if ($statusChanged) {
            log_activity(
                'Inventory',
                'update',
                $id,
                'Status changed from ' . (asset_statuses()[$oldStatus] ?? $oldStatus)
                . ' to ' . (asset_statuses()[$newStatus] ?? $newStatus)
                . ' for ' . $asset['asset_code']
            );
        } else {
            log_activity('Inventory', 'update', $id, 'Updated asset ' . $asset['asset_code'] . ' - ' . $form['equipment_name']);
        }

        set_flash('success', 'Asset ' . $asset['asset_code'] . ' was updated successfully.');
        redirect('inventory/view.php?id=' . $id);
    }

    foreach ($errors as $error) {
        set_flash('danger', $error);
    }
}

$page_title    = 'Edit Asset';
$page_subtitle = $asset['asset_code'] . ' - ' . $asset['equipment_name'];
$active_menu   = 'inventory';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('inventory/index.php') ?>">Inventory</a></li>
                <li class="breadcrumb-item"><a href="<?= url('inventory/view.php?id=' . $id) ?>"><?= e($asset['asset_code']) ?></a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
        <h2>Edit Asset</h2>
        <p><?= e($asset['equipment_name']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('inventory/view.php?id=' . $id) ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to asset</a>
    </div>
</div>

<form method="post" action="<?= url('inventory/edit.php?id=' . $id) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="asset_id" value="<?= $id ?>">
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="form-section-title">Asset identification</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Asset ID</label>
                            <input type="text" class="form-control fw-code" value="<?= e($asset['asset_code']) ?>" readonly>
                            <div class="form-text">Asset IDs are fixed.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category <span class="req">*</span></label>
                            <select name="category_id" class="form-select" required>
                                <option value="">-- Select category --</option>
                                <?php foreach ($catTree as $parent): ?>
                                <optgroup label="<?= e($parent['name']) ?>">
                                    <?php foreach ($parent['children'] as $child): ?>
                                    <option value="<?= (int) $child['id'] ?>"<?= selected($child['id'], $form['category_id']) ?>><?= e($child['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Brand</label>
                            <select name="brand_id" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($brands as $brand): ?>
                                <option value="<?= (int) $brand['id'] ?>"<?= selected($brand['id'], $form['brand_id']) ?>><?= e($brand['brand_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Equipment name <span class="req">*</span></label>
                            <input type="text" name="equipment_name" class="form-control" maxlength="150"
                                   value="<?= e($form['equipment_name']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Model number</label>
                            <input type="text" name="model_number" class="form-control" maxlength="100" value="<?= e($form['model_number']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Serial number <span class="req">*</span></label>
                            <input type="text" name="serial_number" class="form-control" maxlength="100"
                                   value="<?= e($form['serial_number']) ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <div class="form-section-title">Location</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Branch <span class="req">*</span></label>
                            <select name="branch_id" id="branch_id" class="form-select" required>
                                <option value="">-- Select branch --</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int) $branch['id'] ?>"<?= selected($branch['id'], $form['branch_id']) ?>><?= e($branch['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department_id" id="department_id" class="form-select"
                                    data-cascade="departments" data-url="api/options.php" data-parent="#branch_id"
                                    data-allow-empty data-placeholder="-- None --"
                                    data-selected="<?= e($form['department_id']) ?>"></select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="form-section-title">Purchase details</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Purchase date</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?= e($form['purchase_date']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Purchase price (PKR)</label>
                            <input type="number" step="0.01" min="0" name="purchase_price" class="form-control" value="<?= e($form['purchase_price']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Warranty expiry</label>
                            <input type="date" name="warranty_expiry" class="form-control" value="<?= e($form['warranty_expiry']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="3"><?= e($form['remarks']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <div class="form-section-title">Condition &amp; status</div>
                    <div class="mb-3">
                        <label class="form-label">Asset condition</label>
                        <select name="asset_condition" class="form-select">
                            <?php foreach (asset_conditions() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= selected($key, $form['asset_condition']) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (asset_statuses() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= selected($key, $form['status']) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Moving to damaged, under maintenance or retired records a stock adjustment.</div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-brand flex-grow-1"><i class="fa-solid fa-floppy-disk me-2"></i>Save Changes</button>
                    <a class="btn btn-outline-brand" href="<?= url('inventory/view.php?id=' . $id) ?>">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
