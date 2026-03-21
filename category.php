<?php
/**
 * Category Page - compare.lk
 */
require_once 'includes/functions.php';

$slug = trim($_GET['slug'] ?? '');
$category = $slug ? getCategoryBySlug($slug) : null;

if (!$category) {
    header('HTTP/1.0 404 Not Found');
    $pageTitle = 'Category Not Found';
    require_once 'includes/lang.php';
    require_once 'includes/header.php';
    echo '<div class="container py-5"><div class="empty-state"><div class="empty-state-icon"><i class="bi bi-exclamation-circle"></i></div><h4>' . t('category_not_found') . '</h4><a href="' . url('') . '" class="btn" style="background:var(--primary);color:#fff;border-radius:12px;font-weight:600;padding:.65rem 1.5rem;">' . t('go_home') . '</a></div></div>';
    require_once 'includes/footer.php';
    exit;
}

$sort = in_array($_GET['sort'] ?? '', ['price', 'latest']) ? $_GET['sort'] : 'latest';
$products = getProductsByCategory($category['id'], $sort);

require_once 'includes/lang.php';
$pageTitle = $category['name'] . ' — ' . t('site_name');
$pageDesc = 'Compare prices on ' . $category['name'] . ' from top Sri Lankan online stores.';
require_once 'includes/header.php';
?>

<!-- Breadcrumb -->
<div class="breadcrumb-section">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('index.php') ?>"><i class="bi bi-house-fill"></i></a></li>
                <li class="breadcrumb-item active"><?= e($category['name']) ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="container py-4">

    <!-- Category Header -->
    <div class="cat-page-header mb-4">
        <div class="cat-page-icon">
            <i class="bi <?= e($category['icon']) ?>"></i>
        </div>
        <div>
            <div class="section-eyebrow"><?= e(t('categories')) ?></div>
            <h1 class="section-title mb-0"><?= e($category['name']) ?></h1>
            <div class="text-muted" style="font-size:.875rem;margin-top:.15rem;">
                <?= count($products) ?> <?= e(t('products_found')) ?>
            </div>
        </div>
    </div>

    <!-- Category Pills + Sort Bar -->
    <div class="cat-bar mb-4">
        <div class="cat-pills-wrap">
            <?php $cats = getCategories();
            foreach ($cats as $c): ?>
                <a href="<?= url('category.php?slug=' . urlencode($c['slug'])) ?>"
                    class="cat-pill-btn <?= $c['id'] == $category['id'] ? 'active' : '' ?>">
                    <i class="bi <?= e($c['icon']) ?>"></i>
                    <?= e($c['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="sort-wrap">
            <label class="text-muted" style="font-size:.82rem;white-space:nowrap;"><?= e(t('status')) ?>:</label>
            <select id="sortSelect" class="cat-sort-select"
                onchange="location.href='<?= url('category.php') ?>?slug=<?= urlencode($category['slug']) ?>&sort='+this.value">
                <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Latest Updated</option>
                <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Lowest Price</option>
            </select>
        </div>
    </div>

    <!-- Products Grid -->
    <?php if (empty($products)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="bi bi-box-seam"></i></div>
            <h5>No products in this category yet</h5>
            <p class="text-muted">Check back soon or browse other categories.</p>
            <a href="<?= url('index.php') ?>" class="btn"
                style="background:var(--primary);color:#fff;border-radius:12px;font-weight:600;padding:.65rem 1.5rem;">
                <i class="bi bi-house me-2"></i><?= e(t('go_home')) ?>
            </a>
        </div>
    <?php else: ?>
        <div class="pcat-grid">
            <?php foreach ($products as $product): ?>
                <a href="<?= url('product.php?id=' . $product['id']) ?>" class="pcard">
                    <div class="pcard-img-wrap">
                        <?php if ($product['image']): ?>
                            <img src="<?= url('uploads/products/' . e($product['image'])) ?>" alt="<?= e($product['name']) ?>"
                                class="pcard-img" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="pcard-img-ph" style="display:none;"><i class="bi bi-image"></i></div>
                        <?php else: ?>
                            <div class="pcard-img-ph"><i class="bi bi-image"></i></div>
                        <?php endif; ?>
                        <?php if ($product['store_count'] > 0): ?>
                            <div class="pcard-stores-badge">
                                <i class="bi bi-shop"></i>
                                <?= $product['store_count'] ?>
                                <?= $product['store_count'] != 1 ? e(t('stores_count')) : e(t('store')) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="pcard-body">
                        <?php if ($product['brand']): ?>
                            <div class="pcard-brand"><?= e($product['brand']) ?></div>
                        <?php endif; ?>
                        <div class="pcard-name"><?= e($product['name']) ?></div>
                        <?php if ($product['model']): ?>
                            <div class="pcard-model"><?= e($product['model']) ?></div>
                        <?php endif; ?>
                        <div class="pcard-footer">
                            <?php if ($product['min_price']): ?>
                                <div class="pcard-price">
                                    <?= formatPrice((float) $product['min_price']) ?>
                                    <span class="pcard-onwards"><?= e(t('onwards')) ?></span>
                                </div>
                            <?php else: ?>
                                <div class="pcard-no-price"><?= e(t('no_price_yet')) ?></div>
                            <?php endif; ?>
                            <?php if ($product['last_updated']): ?>
                                <span class="pcard-date"><?= date('d M', strtotime($product['last_updated'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    /*CATEGORY PAGE HEADER  */
    .cat-page-header {
        display: flex;
        align-items: center;
        gap: 1.1rem;
    }

    .cat-page-icon {
        width: 60px;
        height: 60px;
        border-radius: 18px;
        background: var(--primary-light, rgba(246, 166, 35, .12));
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
        flex-shrink: 0;
        border: 1.5px solid rgba(246, 166, 35, .2);
    }

    /*  PILLS + SORT BAR */
    .cat-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .cat-pills-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: .45rem;
    }

    .cat-pill-btn {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .32rem .85rem;
        border-radius: 999px;
        font-size: .8rem;
        font-weight: 600;
        text-decoration: none;
        border: 1.5px solid var(--border, #e2e5ea);
        color: var(--text-secondary, #6b7280);
        background: transparent;
        transition: all .18s;
    }

    .cat-pill-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-light, rgba(246, 166, 35, .08));
    }

    .cat-pill-btn.active {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff !important;
    }

    .sort-wrap {
        display: flex;
        align-items: center;
        gap: .5rem;
        flex-shrink: 0;
    }

    .cat-sort-select {
        appearance: none;
        -webkit-appearance: none;
        border: 1.5px solid var(--border, #e2e5ea);
        border-radius: 10px;
        padding: .32rem .9rem .32rem .7rem;
        font-size: .82rem;
        font-weight: 600;
        background: transparent;
        color: var(--text-primary);
        cursor: pointer;
        outline: none;
        transition: border-color .18s;
    }

    .cat-sort-select:focus {
        border-color: var(--primary);
    }

    /* ─── PRODUCT GRID ─────────────────────────────────────────── */
    .pcat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: 1.1rem;
    }

    /* pcard-model */
    .pcard-model {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: .15rem;
    }

    /* pcard-date */
    .pcard-date {
        font-size: .7rem;
        color: var(--text-muted);
        flex-shrink: 0;
    }

    /* ─── DARK THEME ───────────────────────────────────────────── */
    html[data-theme="dark"] .cat-page-icon,
    body.theme-dark .cat-page-icon {
        background: rgba(246, 166, 35, .12);
        border-color: rgba(246, 166, 35, .2);
    }

    html[data-theme="dark"] .cat-pill-btn,
    body.theme-dark .cat-pill-btn {
        border-color: var(--border);
        color: var(--text-muted);
    }

    html[data-theme="dark"] .cat-pill-btn:hover,
    body.theme-dark .cat-pill-btn:hover {
        color: var(--primary);
        background: rgba(246, 166, 35, .08);
    }

    html[data-theme="dark"] .cat-sort-select,
    body.theme-dark .cat-sort-select {
        border-color: var(--border);
        color: var(--text-primary);
        background: var(--gray-200);
    }

    html[data-theme="dark"] .pcat-grid .pcard,
    body.theme-dark .pcat-grid .pcard {
        background: var(--gray-200);
        border-color: var(--border);
        color: var(--text-primary);
    }

    html[data-theme="dark"] .pcat-grid .pcard-img-wrap,
    body.theme-dark .pcat-grid .pcard-img-wrap {
        background: var(--gray-100);
    }

    @media (max-width: 576px) {
        .pcat-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: .75rem;
        }

        .cat-bar {
            flex-direction: column;
            align-items: flex-start;
        }
    }
</style>

<?php require_once 'includes/footer.php'; ?>