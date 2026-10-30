<?php
/**
 * Inventory - add a new asset.
 * Validated insert + optional immediate assignment + stock-in transaction.
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('inventory');

$user = current_user();

$form = [
    'category_id'        => '',
    'brand_id'           => '',
    'equipment_name'     => '',
    'model_number'       => '',
    'serial_number'      => '',
    'branch_id'          => is_role('branch_staff') && !empty($user['branch_id']) ? (string) $user['branch_id'] : '',
    'department_id'      => '',
    'purchase_date'      => date('Y-m-d'),
    'purchase_price'     => '',
    'warranty_expiry'    => '',
    'asset_condition'    => 'Good',
    'status'             => 'available',
    'remarks'            => '',
];
$assignNow    = false;
$assignEmpId  = '';
$errors       = [];

if (is_post()) {
    csrf_verify();

    $form['category_id']     = post('category_id');
    $form['brand_id']        = post('brand_id');
    $form['equipment_name']  = post('equipment_name');
    $form['model_number']    = post('model_number');
    $form['serial_number']   = post('serial_number');
    $form['branch_id']       = post('branch_id');
    $form['department_id']   = post('department_id');
    $form['purchase_date']   = post('purchase_date', date('Y-m-d'));
    $form['purchase_price']  = post('purchase_price');
    $form['warranty_expiry'] = post('warranty_expiry');
    $form['asset_condition'] = post('asset_condition', 'Good');
    $form['status']          = post('status', 'available');
    $form['remarks']         = post('remarks');
    $assignNow               = post('assign_now') === '1';
    $assignEmpId             = post('employee_id');

    if ($form['category_id'] === '' || (int) $form['category_id'] <= 0
        || (int) db_value('SELECT COUNT(*) FROM asset_categories WHERE id = ?', [(int) $form['category_id']]) === 0) {
        $errors[] = 'Please choose a valid category.';
    }
    if ($form['equipment_name'] === '') {
        $errors[] = 'Equipment name is required.';
    }
    if ($form['serial_number'] === '') {
        $errors[] = 'Serial number is required.';
    } elseif ((int) db_value('SELECT COUNT(*) FROM inventory WHERE LOWER(serial_number) = LOWER(?)', [$form['serial_number']]) > 0) {
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
        $form['asset_condition'] = 'Good';
    }
    if (!isset(asset_statuses()[$form['status']])) {
        $form['status'] = 'available';
    }
    if ($assignNow) {
        if ($assignEmpId === '' || (int) db_value('SELECT COUNT(*) FROM employees WHERE id = ?', [(int) $assignEmpId]) === 0) {
            $errors[] = 'Please choose an employee to assign the asset to.';
        }
        if ($form['status'] === 'retired' || $form['status'] === 'damaged') {
            $errors[] = 'A retired or damaged asset cannot be assigned immediately.';
        }
    }

    /* -------------------- Optional photo upload -------------------- */
    $photoNote = '';
    if (!empty($_FILES['asset_photo']['name']) && is_uploaded_file($_FILES['asset_photo']['tmp_name'] ?? '')) {
        $file = $_FILES['asset_photo'];
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'The photo must be 2 MB or smaller.';
        } else {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = 'application/octet-stream';
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file($file['tmp_name']);
            }
            if (!isset($allowed[$mime])) {
                $errors[] = 'Only JPG, PNG or WEBP images are allowed.';
            } else {
                if (!is_dir(UPLOAD_DIR)) {
                    @mkdir(UPLOAD_DIR, 0775, true);
                }
                $filename = 'asset_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $filename)) {
                    $photoNote = 'Photo: ' . $filename;
                } else {
                    $errors[] = 'The uploaded photo could not be stored.';
                }
            }
        }
    }

    if (!$errors) {
        $assetCode = generate_asset_code();
        $remarks   = trim((string) $form['remarks']);
        if ($photoNote !== '') {
            $remarks = $remarks === '' ? $photoNote : $remarks . "\n" . $photoNote;
        }
        $status    = $assignNow ? 'assigned' : $form['status'];
        $empId     = $assignNow ? (int) $assignEmpId : null;
        $pdo       = db();

        try {
            $pdo->beginTransaction();

            $insert = $pdo->prepare(
                'INSERT INTO inventory
                    (asset_code, category_id, brand_id, equipment_name, model_number, serial_number,
                     branch_id, department_id, assigned_employee_id, purchase_date, purchase_price,
                     warranty_expiry, asset_condition, status, remarks, created_by)
                 VALUES
                    (:asset_code, :category_id, :brand_id, :equipment_name, :model_number, :serial_number,
                     :branch_id, :department_id, :assigned_employee_id, :purchase_date, :purchase_price,
                     :warranty_expiry, :asset_condition, :status, :remarks, :created_by)'
            );
            $insert->execute([
                ':asset_code'           => $assetCode,
                ':category_id'          => (int) $form['category_id'],
                ':brand_id'             => null_if_empty($form['brand_id']) !== null ? (int) $form['brand_id'] : null,
                ':equipment_name'       => $form['equipment_name'],
                ':model_number'         => null_if_empty($form['model_number']),
                ':serial_number'        => $form['serial_number'],
                ':branch_id'            => null_if_empty($form['branch_id']) !== null ? (int) $form['branch_id'] : null,
                ':department_id'        => null_if_empty($form['department_id']) !== null ? (int) $form['department_id'] : null,
                ':assigned_employee_id' => $empId,
                ':purchase_date'        => null_if_empty($form['purchase_date']),
                ':purchase_price'       => $form['purchase_price'] === '' ? 0 : (float) $form['purchase_price'],
                ':warranty_expiry'      => null_if_empty($form['warranty_expiry']),
                ':asset_condition'      => $form['asset_condition'],
                ':status'               => $status,
                ':remarks'              => null_if_empty($remarks),
                ':created_by'           => current_user_id(),
            ]);

            $id = (int) $pdo->lastInsertId();

            if ($assignNow) {
                $pdo->prepare(
                    'INSERT INTO asset_assignments
                        (asset_id, employee_id, branch_id, department_id, assignment_date, assigned_by,
                         assignment_status, condition_on_assign, remarks)
                     VALUES (?, ?, ?, ?, ?, ?, "assigned", ?, ?)'
                )->execute([
                    $id,
                    $empId,
                    null_if_empty($form['branch_id']) !== null ? (int) $form['branch_id'] : null,
                    null_if_empty($form['department_id']) !== null ? (int) $form['department_id'] : null,
                    date('Y-m-d'),
                    current_user_id(),
                    $form['asset_condition'],
                    'Assigned at asset creation',
                ]);
            }

            $reference = 'GRN-' . date('Y') . '-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
            $pdo->prepare(
                'INSERT INTO stock_transactions
                    (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes, performed_by, transaction_date)
                 VALUES (?, ?, "in", 1, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                (int) $form['category_id'],
                null_if_empty($form['branch_id']) !== null ? (int) $form['branch_id'] : null,
                $reference,
                'Asset received into stock',
                current_user_id(),
                date('Y-m-d'),
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[BALITECH][inventory/add] ' . $e->getMessage());
            set_flash('danger', 'The asset could not be saved. Please try again.');
            redirect('inventory/add.php');
        }

        log_activity('Inventory', 'create', $id, 'Added asset ' . $assetCode . ' - ' . $form['equipment_name']);
        set_flash('success', 'Asset ' . $assetCode . ' was added successfully.');
        redirect('inventory/view.php?id=' . $id);
    }

    foreach ($errors as $error) {
        set_flash('danger', $error);
    }
}

/* ---------------------------------------------------------------------
 * Option lists
 * ------------------------------------------------------------------ */
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

$assetCode = generate_asset_code();

$page_title    = 'Add New Asset';
$page_subtitle = 'Register a new equipment unit';
$active_menu   = 'inventory_add';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('inventory/index.php') ?>">Inventory</a></li>
                <li class="breadcrumb-item active">Add New Asset</li>
            </ol>
        </nav>
        <h2>Add New Asset</h2>
        <p>Fill in the equipment details. Fields marked <span class="req text-danger">*</span> are required.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('inventory/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to list</a>
    </div>
</div>

<form method="post" action="<?= url('inventory/add.php') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="form-section-title">Asset identification</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Asset ID</label>
                            <input type="text" class="form-control fw-code" value="<?= e($assetCode) ?>" readonly>
                            <div class="form-text">Generated automatically</div>
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
                            <div class="form-text">Must be unique (checked case-insensitively).</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Asset photo</label>
                            <input type="file" name="asset_photo" class="form-control" accept="image/*" data-preview="#assetPreview">
                            <div class="form-text">Optional. JPG, PNG or WEBP up to 2 MB.</div>
                            <img id="assetPreview" src="" alt="" class="img-fluid rounded mt-2" style="display:none;max-height:120px">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <div class="form-section-title">Location &amp; assignment</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Branch <span class="req">*</span></label>
                            <select name="branch_id" id="branch_id" class="form-select" required>
                                <option value="">-- Select branch --</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int) $branch['id'] ?>"<?= selected($branch['id'], $form['branch_id']) ?>><?= e($branch['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Department</label>
                            <select name="department_id" id="department_id" class="form-select"
                                    data-cascade="departments" data-url="api/options.php" data-parent="#branch_id"
                                    data-allow-empty data-placeholder="-- None --"
                                    data-selected="<?= e($form['department_id']) ?>"></select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="assign_now" value="1"
                                       id="assign_now" data-toggle-fields="#assignWrap"<?= $assignNow ? ' checked' : '' ?>>
                                <label class="form-check-label" for="assign_now">Assign to employee immediately</label>
                            </div>
                        </div>
                        <div class="col-12" id="assignWrap" data-toggle-wrap<?= $assignNow ? '' : ' style="display:none"' ?>>
                            <label class="form-label">Assign to employee</label>
                            <select name="employee_id" id="employee_id" class="form-select"
                                    data-cascade="employees" data-url="api/options.php"
                                    data-branch-source="#branch_id" data-extra="#department_id"
                                    data-allow-empty data-placeholder="-- Select employee --"
                                    data-selected="<?= e($assignEmpId) ?>"></select>
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
                        <div class="form-text">A stock-in transaction is recorded automatically.</div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-brand flex-grow-1"><i class="fa-solid fa-floppy-disk me-2"></i>Save Asset</button>
                    <a class="btn btn-outline-brand" href="<?= url('inventory/index.php') ?>">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
