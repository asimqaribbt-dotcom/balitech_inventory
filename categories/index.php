<?php
/**
 * Categories - equipment groups (parent categories) and their sub-categories.
 * Readable by every signed in user; write actions need the "masters" ability.
 */
require_once __DIR__ . '/../init.php';
require_login();

$canWrite = can('masters');

/* ---------------------------------------------------------------------
 * Filters for the sub-category section
 * ------------------------------------------------------------------ */
$search     = get_param('q');
$parentFilter = (int) get_param('parent_id');

/* ---------------------------------------------------------------------
 * Section 1: equipment groups (parent_id IS NULL)
 * ------------------------------------------------------------------ */
$groups = db_all(
    'SELECT p.*,
            (SELECT COUNT(*) FROM asset_categories c WHERE c.parent_id = p.id) AS sub_count,
            (SELECT COUNT(*) FROM inventory i
               JOIN asset_categories c ON c.id = i.category_id
              WHERE c.parent_id = p.id OR c.id = p.id) AS asset_count
       FROM asset_categories p
      WHERE p.parent_id IS NULL
      ORDER BY p.category_name ASC'
);

/* ---------------------------------------------------------------------
 * Section 2: sub-categories (parent_id IS NOT NULL), paginated
 * ------------------------------------------------------------------ */
$subWhere  = ' WHERE c.parent_id IS NOT NULL';
$subParams = [];
if ($parentFilter > 0) {
    $subWhere .= ' AND c.parent_id = ?';
    $subParams[] = $parentFilter;
}
if ($search !== '') {
    $subWhere .= ' AND (c.category_name LIKE ? OR c.category_code LIKE ?)';
    $like = '%' . $search . '%';
    array_push($subParams, $like, $like);
}

$perPage = ITEMS_PER_PAGE;
$subTotal = (int) db_value("SELECT COUNT(*) FROM asset_categories c $subWhere", $subParams);
$page     = max(1, (int) get_param('page', 1));
$page     = min($page, max(1, (int) ceil($subTotal / $perPage)));
$offset   = ($page - 1) * $perPage;

$subCategories = db_all(
    "SELECT c.*, p.category_name AS parent_name,
            (SELECT COUNT(*) FROM inventory i WHERE i.category_id = c.id AND i.status = 'available') AS available_stock,
            (SELECT COUNT(*) FROM inventory i WHERE i.category_id = c.id) AS asset_count
       FROM asset_categories c
       LEFT JOIN asset_categories p ON p.id = c.parent_id
       $subWhere
      ORDER BY p.category_name ASC, c.category_name ASC
      LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    $subParams
);

$page_title    = 'Categories';
$page_subtitle = 'Equipment groups and sub-categories';
$active_menu   = 'categories';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Categories</li>
            </ol>
        </nav>
        <h2>Asset Categories</h2>
        <p>Equipment groups and their sub-categories</p>
    </div>
    <div class="page-actions">
        <?php if ($canWrite): ?>
        <a class="btn btn-brand" href="<?= url('categories/create.php') ?>"><i class="fa-solid fa-plus me-2"></i>Add Category</a>
        <?php endif; ?>
    </div>
</div>

<!-- ======================= SECTION 1: EQUIPMENT GROUPS ======================= -->
<div class="card mb-4">
    <div class="card-header">
        <div class="stat-icon bg-soft-purple" style="width:40px;height:40px;font-size:15px"><i class="fa-solid fa-layer-group"></i></div>
        <div><h3 class="mb-0">Equipment groups</h3><p class="sub mb-0">Top level categories (<?= count($groups) ?>)</p></div>
    </div>
    <div class="table-toolbar">
        <div class="left">
            <div class="input-group" style="max-width:280px">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" class="form-control form-control-sm" placeholder="Search groups..." data-table-filter="#groupsTable">
            </div>
        </div>
        <div class="right">
            <button type="button" class="btn btn-outline-brand btn-sm" data-print><i class="fa-solid fa-print me-1"></i>Print</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table" id="groupsTable">
            <thead>
                <tr>
                    <th>Group</th>
                    <th>Description</th>
                    <th class="text-center">Sub Categories</th>
                    <th class="text-center">Assets</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$groups): ?>
                <tr class="no-filter"><td colspan="6">
                    <div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No equipment groups defined.</p></div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td>
                        <a class="fw-code" href="<?= url('categories/index.php?parent_id=' . (int) $g['id']) ?>"><?= e($g['category_code']) ?></a>
                        <div><?= e($g['category_name']) ?></div>
                    </td>
                    <td class="text-muted-sm"><?= e($g['description'] ?: '-') ?></td>
                    <td class="text-center"><?= (int) $g['sub_count'] ?></td>
                    <td class="text-center"><?= (int) $g['asset_count'] ?></td>
                    <td><?= active_badge($g['status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <?php if ($canWrite): ?>
                            <a class="btn-icon" title="Edit" href="<?= url('categories/edit.php?id=' . (int) $g['id']) ?>"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" action="<?= url('categories/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete"
                                        data-confirm="Delete group &quot;<?= e($g['category_name']) ?>&quot; and all its sub-categories?"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ======================= SECTION 2: SUB-CATEGORIES ======================= -->
<form class="filter-bar" method="get" action="<?= url('categories/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-4 col-md-6">
            <label class="form-label">Search</label>
            <input type="search" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Sub-category name or code">
        </div>
        <div class="col-lg-4 col-md-6">
            <label class="form-label">Parent Group</label>
            <select class="form-select" name="parent_id">
                <option value="">All groups</option>
                <?php foreach ($groups as $g): ?>
                <option value="<?= (int) $g['id'] ?>"<?= selected($g['id'], $parentFilter) ?>><?= e($g['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-4 col-md-6">
            <button type="submit" class="btn btn-brand btn-sm"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <a class="btn btn-outline-brand btn-sm" href="<?= url('categories/index.php') ?>">Reset</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-toolbar">
        <div class="left">
            <div class="input-group" style="max-width:280px">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" class="form-control form-control-sm" placeholder="Filter this table..." data-table-filter="#subCategoriesTable">
            </div>
        </div>
        <div class="right">
            <button type="button" class="btn btn-outline-brand btn-sm" data-print><i class="fa-solid fa-print me-1"></i>Print</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table" id="subCategoriesTable">
            <thead>
                <tr>
                    <th>Sub Category</th>
                    <th>Parent Group</th>
                    <th class="text-center">Low Stock Threshold</th>
                    <th class="text-center">Available Stock</th>
                    <th class="text-center">Total Assets</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$subCategories): ?>
                <tr class="no-filter"><td colspan="7">
                    <div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No sub-categories found.</p></div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($subCategories as $c): ?>
                <tr>
                    <td>
                        <span class="fw-code"><?= e($c['category_code']) ?></span>
                        <div><?= e($c['category_name']) ?></div>
                    </td>
                    <td class="text-muted-sm"><?= e($c['parent_name'] ?: '-') ?></td>
                    <td class="text-center"><?= (int) $c['low_stock_threshold'] ?></td>
                    <td class="text-center"><?= (int) $c['available_stock'] ?></td>
                    <td class="text-center"><?= (int) $c['asset_count'] ?></td>
                    <td><?= active_badge($c['status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <?php if ($canWrite): ?>
                            <a class="btn-icon" title="Edit" href="<?= url('categories/edit.php?id=' . (int) $c['id']) ?>"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" action="<?= url('categories/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete"
                                        data-confirm="Delete sub-category &quot;<?= e($c['category_name']) ?>&quot;?"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted" style="font-size:12.5px"><?= pagination_summary($subTotal, $perPage, $page, 'sub-categories') ?></div>
        <?= paginate($subTotal, $perPage, $page) ?>
    </div>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
