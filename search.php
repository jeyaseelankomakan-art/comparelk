<?php
/**
 * Search Results Page - compare.lk
 */
require_once 'includes/functions.php';

$q = trim($_GET['q'] ?? '');
$catId = !empty($_GET['cat']) ? (int) $_GET['cat'] : null;
$minPrice = !empty($_GET['min_price']) ? (float) $_GET['min_price'] : null;
$maxPrice = !empty($_GET['max_price']) ? (float) $_GET['max_price'] : null;
$sort = in_array($_GET['sort'] ?? '', ['price', 'latest']) ? $_GET['sort'] : 'latest';

$results = [];
if ($q !== '') {
    $results = searchProducts($q, $catId, $minPrice, $maxPrice, $sort);
    
    // If no products found, automatically redirect to the contact page
    // Pre-fill the contact form with the missing product so they can request it
    if (empty($results)) {
        $subject = urlencode("Product Not Found Request");
        $msg = urlencode("I searched for \"{$q}\" but could not find any results. Could you please add it?");
        redirect(url("pages/contact.php?subject={$subject}&message={$msg}"));
        exit;
    }
}

require_once 'includes/lang.php';
$pageTitle = $q ? '"' . e($q) . '" — ' . t('search_results') : t('search_products');
require_once 'includes/header.php';
$allCats = getCategories();
?>

<!-- Breadcrumb -->
<div class="breadcrumb-section">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('index.php') ?>"><i class="bi bi-house-fill"></i></a></li>
                <li class="breadcrumb-item active"><?= e(t('footer_search')) ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="container py-4">
    <div class="row g-4">
        <!-- Filter Sidebar -->
        <div class="col-lg-3">
            <div class="filter-card sticky-top" style="top:80px;">
                <h6 class="filter-title"><i class="bi bi-funnel me-2"></i><?= e(t('filter_results')) ?></h6>
                <form method="GET" action="<?= url('search.php') ?>" id="filterForm">
                    <input type="hidden" name="q" value="<?= e($q) ?>">
                    <input type="hidden" name="sort" value="<?= e($sort) ?>">

                    <div class="mb-3">
                        <label class="form-label"><?= e(t('categories')) ?></label>
                        <select name="cat" class="form-select form-select-sm"
                            onchange="document.getElementById('filterForm').submit()">
                            <option value=""><?= e(t('all_categories')) ?></option>
                            <?php foreach ($allCats as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $catId == $c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= e(t('min_price')) ?></label>
                        <input type="number" name="min_price" class="form-control form-control-sm"
                            placeholder="e.g. 50000" value="<?= $minPrice !== null ? $minPrice : '' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('max_price')) ?></label>
                        <input type="number" name="max_price" class="form-control form-control-sm"
                            placeholder="e.g. 200000" value="<?= $maxPrice !== null ? $maxPrice : '' ?>">
                    </div>

                    <button type="submit" class="btn w-100 btn-sm"
                        style="background:var(--primary);color:#fff;border-color:var(--primary);font-weight:600;">
                        <i class="bi bi-search me-1"></i><?= e(t('apply_filters')) ?>
                    </button>
                    <a href="<?= url('search.php?q=' . urlencode($q)) ?>"
                        class="btn btn-outline-secondary w-100 btn-sm mt-2">
                        <i class="bi bi-x me-1"></i><?= e(t('clear_filters')) ?>
                    </a>
                </form>
            </div>
        </div>

        <!-- Results -->
        <div class="col-lg-9">
            <!-- Header -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
                <div>
                    <?php if ($q): ?>
                        <h4 class="fw-800 mb-0">
                            Results for <span style="color:var(--primary);">&ldquo;<?= e($q) ?>&rdquo;</span>
                        </h4>
                        <div class="text-muted" style="font-size:.875rem;"><?= count($results) ?>
                            <?= e(t('products_found')) ?>
                        </div>
                    <?php else: ?>
                        <h4 class="fw-800 mb-0"><?= e(t('search_products')) ?></h4>
                        <div class="text-muted" style="font-size:.875rem;">Enter a keyword to search</div>
                    <?php endif; ?>
                </div>
                <?php if ($q): ?>
                    <div class="d-flex align-items-center gap-2">
                        <label class="text-muted" style="font-size:.85rem;">Sort:</label>
                        <select id="sortSelect" class="form-select form-select-sm" style="width:auto;"
                            onchange="const u=new URL(location.href);u.searchParams.set('sort',this.value);location.href=u.toString()">
                            <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Latest</option>
                            <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Lowest Price</option>
                        </select>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($q === ''): ?>
                <!-- No search yet — show popular searches -->
                <div class="info-card text-center py-5">
                    <div style="font-size:3.5rem;color:var(--gray-400);margin-bottom:1rem;">
                        <i class="bi bi-search"></i>
                    </div>
                    <h5>What are you looking for?</h5>
                    <p class="text-muted mb-3">Try searching for a product name, brand, or model number</p>
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <?php $popular = ['Samsung Galaxy', 'iPhone', 'MacBook', 'Sony TV', 'LG AC'];
                        foreach ($popular as $p): ?>
                            <a href="<?= url('search.php?q=' . urlencode($p)) ?>"
                                class="btn btn-outline-primary btn-sm rounded-pill">
                                <?= e($p) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php elseif (empty($results)): ?>
                <!-- This will technically never render due to the redirect above, but keeping as a safe fallback -->
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-search"></i></div>
                    <h5>No results for "<?= e($q) ?>"</h5>
                    <p class="text-muted">You will be redirected to the contact page...</p>
                    <script>window.location.href = "<?= url('pages/contact.php') ?>"; </script>
                    <a href="<?= url('index.php') ?>" class="btn"
                        style="background:var(--primary);color:#fff;border-color:var(--primary);font-weight:600;border-radius:12px;padding:.65rem 1.5rem;">
                        <i class="bi bi-grid me-2"></i>Browse Categories
                    </a>
                </div>

            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($results as $product): ?>
                        <div class="col-sm-6 col-lg-4">
                            <a href="<?= url('product.php?id=' . $product['id']) ?>" class="text-decoration-none">
                                <div class="product-card">
                                    <?php if ($product['image']): ?>
                                        <img src="<?= url('uploads/products/' . e($product['image'])) ?>"
                                            alt="<?= e($product['name']) ?>" class="product-card-img"
                                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <div class="product-card-img-placeholder" style="display:none;">
                                            <i class="bi bi-image"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="product-card-img-placeholder">
                                            <i class="bi bi-image"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="product-card-body">
                                        <div class="product-brand"><?= e($product['brand'] ?? '') ?></div>
                                        <div class="product-name lh-sm"><?= e($product['name']) ?></div>
                                        <?php if ($product['category_name']): ?>
                                            <span class="badge badge-category mt-1"
                                                style="font-size:.7rem;"><?= e($product['category_name']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($product['min_price']): ?>
                                            <div class="product-price mt-2"><?= formatPrice((float) $product['min_price']) ?></div>
                                        <?php endif; ?>
                                        <div class="product-meta d-flex align-items-center justify-content-between">
                                            <span class="store-count-badge">
                                                <i class="bi bi-shop"></i>
                                                <?= $product['store_count'] ?>
                                                <?= $product['store_count'] != 1 ? e(t('stores_count')) : e(t('store')) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .fw-800 {
        font-weight: 800;
    }
</style>

<?php require_once 'includes/footer.php'; ?>