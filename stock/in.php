<?php
/**
 * Stock Management - Stock In (receive new stock or return an asset to service).
 */
require_once __DIR__ . '/../init.php';
require_login();
require_ability('stock');

$scope       = rbac_scope('i');
$scopeSql    = $scope['sql'];
$scopeParams = $scope['params'];
$userId      = current_user_id();
$user        = current_user();

if (is_post()) {
    csrf_verify();

    $mode = post('mode', 'new');

    try {
        if ($mode === 'existing') {
            /* ---------------------------------------------------------
             * Mode B - receive an existing damaged / retired asset back
             * ------------------------------------------------------ */
            $assetId   = (int) post('asset_id', 0);
            $condition = post('asset_condition', 'Good');
            $referenceNo = post('reference_no');
            $notes       = post('notes');
            $transDate   = post('transaction_date');
            if (!is_valid_date($transDate)) {
                $transDate = date('Y-m-d');
            }
            if (!isset(asset_conditions()[$condition])) {
                $condition = 'Good';
            }

            $asset = db_row(
                "SELECT i.* FROM inventory i
                  WHERE i.id = :id AND i.status IN ('retired','damaged') $scopeSql",
                array_merge(['id' => $assetId], $scopeParams)
            );
            if (!$asset) {
                set_flash('danger', 'The selected asset could not be found or is not retired / damaged.');
                redirect('stock/in.php');
            }

            db()->beginTransaction();

            db_run('UPDATE inventory SET status = "available", asset_condition = ? WHERE id = ?', [$condition, $assetId]);

            db_run(
                'INSERT INTO stock_transactions
                    (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes,
                     performed_by, transaction_date, created_at)
                 VALUES (?, ?, "in", 1, ?, ?, ?, ?, ?, NOW())',
                [
                    $assetId,
                    $asset['category_id'],
                    null_if_empty($asset['branch_id']),
                    null_if_empty($referenceNo),
                    null_if_empty($notes) ?? 'Asset returned to service',
                    $userId,
                    $transDate,
                ]
            );

            db()->commit();

            log_activity('Stock', 'create', $assetId, 'Stock in: ' . $asset['asset_code'] . ' returned to service');
            set_flash('success', '1 asset added back to service.');
            redirect('stock/index.php');
        }

        /* ------------------------------------------------------------
         * Mode A - register N new assets
         * --------------------------------------------------------- */
        $categoryId     = (int) post('category_id', 0);
        $brandId        = (int) post('brand_id', 0);
        $equipmentName  = post('equipment_name');
        $modelNumber    = post('model_number');
        $serialNumber   = post('serial_number');
        $branchId       = (int) post('branch_id', 0);
        $deptId         = (int) post('department_id', 0);
        $purchaseDate   = post('purchase_date');
        $purchasePrice  = post('purchase_price');
        $warrantyExpiry = post('warranty_expiry');
        $condition      = post('asset_condition', 'Good');
        $quantity       = (int) post('quantity', 1);
        $referenceNo    = post('reference_no');
        $notes          = post('notes');

        if ($equipmentName === '') {
            set_flash('danger', 'Equipment name is required.');
            redirect('stock/in.php');
        }
        if ((int) db_value('SELECT COUNT(*) FROM asset_categories WHERE id = ? AND status = "active"', [$categoryId]) === 0) {
            set_flash('danger', 'Please select a valid category.');
            redirect('stock/in.php');
        }
        if ($branchId <= 0 || (int) db_value('SELECT COUNT(*) FROM branches WHERE id = ? AND status = "active"', [$branchId]) === 0) {
            set_flash('danger', 'Please select a valid branch.');
            redirect('stock/in.php');
        }
        if (is_role('branch_staff') && !empty($user['branch_id']) && $branchId !== (int) $user['branch_id']) {
            deny_access('You can only receive stock into your own branch.');
        }
        if ($quantity < 1 || $quantity > 50) {
            set_flash('danger', 'Quantity must be between 1 and 50.');
            redirect('stock/in.php');
        }
        if (!isset(asset_conditions()[$condition])) {
            $condition = 'Good';
        }
        if ($brandId > 0 && (int) db_value('SELECT COUNT(*) FROM brands WHERE id = ?', [$brandId]) === 0) {
            $brandId = 0;
        }
        if ($deptId > 0 && (int) db_value('SELECT COUNT(*) FROM departments WHERE id = ? AND status = "active"', [$deptId]) === 0) {
            $deptId = 0;
        }
        $purchaseDate   = is_valid_date($purchaseDate) ? $purchaseDate : null;
        $warrantyExpiry = is_valid_date($warrantyExpiry) ? $warrantyExpiry : null;
        $purchasePrice  = (is_numeric($purchasePrice) && (float) $purchasePrice >= 0) ? round((float) $purchasePrice, 2) : 0.00;
        $transDate      = $purchaseDate ?: date('Y-m-d');

        // Build and validate the serial list
        $serials = [];
        $serialBase = trim($serialNumber);
        if ($serialBase !== '') {
            for ($n = 1; $n <= $quantity; $n++) {
                $serial = $quantity > 1 ? $serialBase . '-' . $n : $serialBase;
                if ((int) db_value('SELECT COUNT(*) FROM inventory WHERE serial_number = ?', [$serial]) > 0) {
                    set_flash('danger', 'Serial number "' . $serial . '" already exists.');
                    redirect('stock/in.php');
                }
                $serials[$n] = $serial;
            }
        }

        db()->beginTransaction();

        $createdCodes = [];
        for ($n = 1; $n <= $quantity; $n++) {
            $code = generate_asset_code();
            db_run(
                'INSERT INTO inventory
                    (asset_code, category_id, brand_id, equipment_name, model_number, serial_number,
                     branch_id, department_id, assigned_employee_id, purchase_date, purchase_price,
                     warranty_expiry, asset_condition, status, remarks, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, "available", ?, ?, NOW())',
                [
                    $code,
                    $categoryId,
                    $brandId > 0 ? $brandId : null,
                    $equipmentName,
                    null_if_empty($modelNumber),
                    $serials[$n] ?? null,
                    $branchId,
                    $deptId > 0 ? $deptId : null,
                    $purchaseDate,
                    $purchasePrice,
                    $warrantyExpiry,
                    $condition,
                    null_if_empty($notes),
                    $userId,
                ]
            );
            $createdCodes[] = $code;
        }

        $firstId = $createdCodes ? (int) db_value('SELECT id FROM inventory WHERE asset_code = ?', [$createdCodes[0]]) : null;

        db_run(
            'INSERT INTO stock_transactions
                (asset_id, category_id, transaction_type, quantity, branch_id, reference_no, notes,
                 performed_by, transaction_date, created_at)
             VALUES (?, ?, "in", ?, ?, ?, ?, ?, ?, NOW())',
            [
                $firstId,
                $categoryId,
                $quantity,
                $branchId,
                null_if_empty($referenceNo),
                null_if_empty($notes) ?? ($quantity . ' x ' . $equipmentName . ' added to stock'),
                $userId,
                $transDate,
            ]
        );

        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('[BALITECH][stock/in] ' . $ex->getMessage());
        set_flash('danger', 'Unable to record the stock-in. Please try again.');
        redirect('stock/in.php');
    }

    log_activity('Stock', 'create', null, 'Stock in: ' . $quantity . ' x ' . $equipmentName);
    set_flash('success', $quantity . ' asset(s) added');
    redirect('stock/index.php');
}

/* ---------------------------------------------------------------------
 * Form data
 * ------------------------------------------------------------------ */
$parentCats = db_all('SELECT id, category_name FROM asset_categories WHERE parent_id IS NULL AND status = "active" ORDER BY category_name');
$childCats  = db_all('SELECT id, category_name, parent_id FROM asset_categories WHERE parent_id IS NOT NULL AND status = "active" ORDER BY category_name');
$grouped    = [];
foreach ($childCats as $child) {
    $grouped[$child['parent_id']][] = $child;
}

$brands   = db_all('SELECT id, brand_name FROM brands WHERE status = "active" ORDER BY brand_name');
$branches = db_all('SELECT id, branch_name FROM branches WHERE status = "active" ORDER BY branch_name');

$inactiveAssets = db_all(
    "SELECT i.id, i.asset_code, i.equipment_name, i.status, b.branch_name
       FROM inventory i
       LEFT JOIN branches b ON b.id = i.branch_id
      WHERE i.status IN ('retired','damaged') $scopeSql
      ORDER BY i.asset_code
      LIMIT 300",
    $scopeParams
);

$page_title    = 'Stock In';
$page_subtitle = 'Receive new equipment or return an asset to service';
$active_menu   = 'stock';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= url('stock/index.php') ?>">Stock Management</a></li>
                <li class="breadcrumb-item active">Stock In</li>
            </ol>
        </nav>
        <h2>Stock in</h2>
        <p>Register newly received equipment or bring a retired / damaged asset back into service.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-brand" href="<?= url('stock/index.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to stock</a>
    </div>
</div>

<form method="post" action="<?= url('stock/in.php') ?>" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <div class="stat-icon bg-soft-green" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-arrow-down"></i></div>
            <div>
                <h3 class="mb-0">Stock in entry</h3>
                <p class="sub mb-0">Choose how this stock is being received</p>
            </div>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-4 mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="mode_new" value="new"
                           data-toggle-fields="#newModeFields" checked>
                    <label class="form-check-label" for="mode_new"><b>Register new assets</b> - add new equipment units</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="mode_existing" value="existing"
                           data-toggle-fields="#existingModeFields">
                    <label class="form-check-label" for="mode_existing"><b>Receive existing asset into branch</b> - restore a retired / damaged asset</label>
                </div>
            </div>

            <!-- ===================== MODE A ===================== -->
            <div id="newModeFields">
                <div class="form-section-title">Equipment</div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="category_id">Category <span class="req">*</span></label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="">-- Select category --</option>
                            <?php foreach ($parentCats as $parent): ?>
                                <?php if (empty($grouped[$parent['id']])): continue; endif; ?>
                                <optgroup label="<?= e($parent['category_name']) ?>">
                                    <?php foreach ($grouped[$parent['id']] as $child): ?>
                                    <option value="<?= (int) $child['id'] ?>"><?= e($child['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="brand_id">Brand</label>
                        <select class="form-select" id="brand_id" name="brand_id">
                            <option value="">-- Select brand --</option>
                            <?php foreach ($brands as $brand): ?>
                            <option value="<?= (int) $brand['id'] ?>"><?= e($brand['brand_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="quantity">Quantity <span class="req">*</span></label>
                        <input type="number" class="form-control" id="quantity" name="quantity" min="1" max="50" value="1" required>
                        <div class="form-text">Between 1 and 50 units.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="equipment_name">Equipment name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="equipment_name" name="equipment_name" maxlength="150" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="model_number">Model number</label>
                        <input type="text" class="form-control" id="model_number" name="model_number" maxlength="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="serial_number">Serial number</label>
                        <input type="text" class="form-control" id="serial_number" name="serial_number" maxlength="100">
                        <div class="form-text">Must be unique. Suffixed -1, -2 when multiple units.</div>
                    </div>
                </div>

                <div class="form-section-title mt-4">Location &amp; purchase</div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="branch_id">Branch <span class="req">*</span></label>
                        <select class="form-select" id="branch_id" name="branch_id" required>
                            <option value="">-- Select branch --</option>
                            <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>"><?= e($branch['branch_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="department_id">Department</label>
                        <select class="form-select" id="department_id" name="department_id"
                                data-cascade="departments" data-url="api/options.php"
                                data-parent="#branch_id" data-placeholder="-- Select department (optional) --"
                                data-allow-empty>
                            <option value="">-- Select department (optional) --</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="asset_condition">Condition <span class="req">*</span></label>
                        <select class="form-select" id="asset_condition" name="asset_condition" required>
                            <?php foreach (asset_conditions() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= selected($key, 'New') ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="purchase_date">Purchase date</label>
                        <input type="date" class="form-control" id="purchase_date" name="purchase_date">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="purchase_price">Purchase price</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="purchase_price" name="purchase_price" value="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="warranty_expiry">Warranty expiry</label>
                        <input type="date" class="form-control" id="warranty_expiry" name="warranty_expiry">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="reference_no">Reference no</label>
                        <input type="text" class="form-control" id="reference_no" name="reference_no" maxlength="60" placeholder="e.g. GRN-2026-001">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Optional notes"></textarea>
                    </div>
                </div>
            </div>

            <!-- ===================== MODE B ===================== -->
            <div id="existingModeFields">
                <div class="form-section-title">Restore asset</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="asset_id">Asset (retired / damaged) <span class="req">*</span></label>
                        <select class="form-select" id="asset_id" name="asset_id">
                            <option value="">-- Select asset --</option>
                            <?php foreach ($inactiveAssets as $asset): ?>
                            <option value="<?= (int) $asset['id'] ?>">
                                <?= e($asset['asset_code']) ?> - <?= e($asset['equipment_name']) ?>
                                (<?= e($asset['branch_name'] ?: 'No branch') ?>, <?= e($asset['status']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="existing_condition">Condition <span class="req">*</span></label>
                        <select class="form-select" id="existing_condition" name="asset_condition">
                            <?php foreach (asset_conditions() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= selected($key, 'Good') ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="existing_date">Transaction date</label>
                        <input type="date" class="form-control" id="existing_date" name="transaction_date" value="<?= e(date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="existing_reference">Reference no</label>
                        <input type="text" class="form-control" id="existing_reference" name="reference_no" maxlength="60">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="existing_notes">Notes</label>
                        <input type="text" class="form-control" id="existing_notes" name="notes" maxlength="255">
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-brand" href="<?= url('stock/index.php') ?>">Cancel</a>
            <button type="submit" class="btn btn-brand"><i class="fa-solid fa-arrow-down me-2"></i>Save stock in</button>
        </div>
    </div>
</form>

<script>
(function () {
    var radios = document.querySelectorAll('input[name="mode"]');
    function setDisabled(selector, disabled) {
        var box = document.querySelector(selector);
        if (!box) { return; }
        box.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.disabled = disabled;
        });
    }
    function update() {
        var mode = 'new';
        radios.forEach(function (radio) { if (radio.checked) { mode = radio.value; } });
        setDisabled('#newModeFields', mode !== 'new');
        setDisabled('#existingModeFields', mode !== 'existing');
    }
    radios.forEach(function (radio) { radio.addEventListener('change', update); });
    update();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
