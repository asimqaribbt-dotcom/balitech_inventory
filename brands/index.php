<?php
/**
 * Brands - searchable list of equipment brands with asset counts.
 * Readable by every signed in user; write actions need the "masters" ability.
 */
require_once __DIR__ . '/../init.php';
require_login();

$canWrite = can('masters');
$search   = get_param('q');

/* ---------------------------------------------------------------------
 * List query + pagination
 * ------------------------------------------------------------------ */
$where  = ' WHERE 1 = 1';
$params = [];
if ($search !== '') {
    $where .= ' AND (b.brand_name LIKE ? OR b.description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}

$perPage = ITEMS_PER_PAGE;
$total   = (int) db_value("SELECT COUNT(*) FROM brands b $where", $params);
$page    = max(1, (int) get_param('page', 1));
$page    = min($page, max(1, (int) ceil($total / $perPage)));
$offset  = ($page - 1) * $perPage;

$brands = db_all(
    "SELECT b.*, COUNT(i.id) AS asset_count
       FROM brands b
       LEFT JOIN inventory i ON i.brand_id = b.id
       $where
      GROUP BY b.id
      ORDER BY b.brand_name ASC
      LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    $params
);

/* ---------------------------------------------------------------------
 * Summary figures (independent of the search filter)
 * ------------------------------------------------------------------ */
$totalBrands   = (int) db_value('SELECT COUNT(*) FROM brands');
$activeBrands  = (int) db_value("SELECT COUNT(*) FROM brands WHERE status = 'active'");
$brandedAssets = (int) db_value('SELECT COUNT(*) FROM inventory WHERE brand_id IS NOT NULL');
$topBrand = db_row(
    'SELECT b.brand_name, COUNT(i.id) AS total
       FROM brands b
       JOIN inventory i ON i.brand_id = b.id
      GROUP BY b.id, b.brand_name
      ORDER BY total DESC, b.brand_name ASC
      LIMIT 1'
);

$page_title    = 'Brands';
$page_subtitle = 'Equipment manufacturers and their asset counts';
$active_menu   = 'brands';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Brands</li>
            </ol>
        </nav>
        <h2>Brands</h2>
        <p><?= e(APP_COMPANY) ?> &middot; <?= (int) $total ?> brand(s) listed</p>
    </div>
    <div class="page-actions">
        <?php if ($canWrite): ?>
        <a class="btn btn-brand" href="<?= url('brands/create.php') ?>"><i class="fa-solid fa-plus me-2"></i>Add Brand</a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================ STAT CARDS ============================ -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-navy"><i class="fa-solid fa-tags"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($totalBrands) ?></span>
                <span class="stat-label d-block">Total Brands</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-green"><i class="fa-solid fa-circle-check"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($activeBrands) ?></span>
                <span class="stat-label d-block">Active</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-blue"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= number_format($brandedAssets) ?></span>
                <span class="stat-label d-block">Assets With A Brand</span>
            </span>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <span class="stat-icon bg-soft-purple"><i class="fa-solid fa-crown"></i></span>
            <span class="stat-meta">
                <span class="stat-value d-block"><?= e($topBrand ? $topBrand['brand_name'] : '-') ?></span>
                <span class="stat-label d-block">Top Brand<?= $topBrand ? ' (' . (int) $topBrand['total'] . ')' : '' ?></span>
            </span>
        </div>
    </div>
</div>

<!-- ============================ FILTER BAR ============================ -->
<form class="filter-bar" method="get" action="<?= url('brands/index.php') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-lg-8 col-md-6">
            <label class="form-label">Search</label>
            <input type="search" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Brand name or description">
        </div>
        <div class="col-lg-4 col-md-6">
            <button type="submit" class="btn btn-brand btn-sm"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <a class="btn btn-outline-brand btn-sm" href="<?= url('brands/index.php') ?>">Reset</a>
        </div>
    </div>
</form>

<!-- ============================== TABLE ============================== -->
<div class="card">
    <div class="table-toolbar">
        <div class="left">
            <div class="input-group" style="max-width:280px">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" class="form-control form-control-sm" placeholder="Filter this table..." data-table-filter="#brandsTable">
            </div>
        </div>
        <div class="right">
            <button type="button" class="btn btn-outline-brand btn-sm" data-print><i class="fa-solid fa-print me-1"></i>Print</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table" id="brandsTable">
            <thead>
                <tr>
                    <th>Brand</th>
                    <th>Description</th>
                    <th class="text-center">Assets</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$brands): ?>
                <tr class="no-filter"><td colspan="5">
                    <div class="empty-state"><i class="fa-solid fa-inbox"></i><p class="mb-0">No brands found.</p></div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($brands as $b): ?>
                <tr>
                    <td><span class="fw-semibold"><?= e($b['brand_name']) ?></span></td>
                    <td class="text-muted-sm"><?= e($b['description'] ?: '-') ?></td>
                    <td class="text-center"><?= (int) $b['asset_count'] ?></td>
                    <td><?= active_badge($b['status']) ?></td>
                    <td>
                        <div class="table-actions">
                            <?php if ($canWrite): ?>
                            <a class="btn-icon" title="Edit" href="<?= url('brands/edit.php?id=' . (int) $b['id']) ?>"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" action="<?= url('brands/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete"
                                        data-confirm="Delete brand &quot;<?= e($b['brand_name']) ?>&quot;? Assets using it will have their brand cleared."><i class="fa-solid fa-trash"></i></button>
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
        <div class="text-muted" style="font-size:12.5px"><?= pagination_summary($total, $perPage, $page, 'brands') ?></div>
        <?= paginate($total, $perPage, $page) ?>
    </div>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
