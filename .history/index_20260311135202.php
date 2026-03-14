<?php
/**
 * Home Page - compare.lk
 */
$pageTitle = 'Compare Prices from Top Sri Lankan Stores';
$pageDesc = 'Compare prices on phones, laptops, TVs and more from Daraz, Kapruka, Singer, Softlogic and other top Sri Lankan stores.';

require_once 'includes/header.php';
$latestProducts = getLatestProducts(8);
$categories = getCategories();

$pdo = getDB();
$countRows = $pdo->query("SELECT category_id, COUNT(*) AS cnt FROM products GROUP BY category_id")->fetchAll();
$catCounts = [];
foreach ($countRows as $cr) {
    $catCounts[$cr['category_id']] = $cr['cnt'];
}

$totalProducts = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalStores = (int) $pdo->query("SELECT COUNT(*) FROM stores")->fetchColumn();
$totalPrices = (int) $pdo->query("SELECT COUNT(*) FROM product_prices")->fetchColumn();
$stores = getStores();
?>

<!--  HERO  -->
<section class="hero-section position-relative overflow-hidden">
    <!-- decorative blobs -->
    <div class="hero-blob hero-blob-1"></div>
    <div class="hero-blob hero-blob-2"></div>

    <div class="container position-relative">
        <div class="row align-items-center min-vh-hero g-5">

            <!-- LEFT copy -->
            <div class="col-lg-6">
                <div class="hero-pill mb-4 d-inline-flex align-items-center gap-2">
                    <span class="hero-pill-dot"></span>
                    <span><?= e(t('hero_badge')) ?></span>
                </div>

                <h1 class="hero-title mb-3">
                    <?= e(t('hero_title_1')) ?><br>
                    <span class="hero-title-highlight"><?= e(t('hero_title_2')) ?></span><br>
                    <?= e(t('hero_title_3')) ?>
                </h1>

                <p class="hero-subtitle mb-5"><?= e(t('hero_subtitle')) ?></p>

                <!-- Search -->
                <form class="hero-search-wrap" action="<?= url('search.php') ?>" method="GET" id="heroSearchForm">
                    <div class="hero-search-inner">
                        <i class="bi bi-search hero-search-icon"></i>
                        <input type="text" name="q" id="heroQ" class="hero-search-input"
                            placeholder="<?= e(t('hero_search_placeholder')) ?>" autocomplete="off">
                        <button type="submit" class="hero-search-btn">
                            <?= e(t('nav_search')) ?> <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                    <div class="hero-search-hints mt-2 d-flex flex-wrap gap-2">
                        <?php foreach (['Samsung Galaxy', 'iPhone 15', 'MacBook', 'Sony TV'] as $hint): ?>
                        <button type="button" class="hero-hint-chip"
                            onclick="document.getElementById('heroQ').value=this.dataset.q;document.getElementById('heroSearchForm').submit()"
                            data-q="<?= e($hint) ?>">
                            <?= e($hint) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </form>

                <!-- Stats row -->
                <div class="hero-stats mt-5">
                    <div class="hero-stat">
                        <div class="hero-stat-num" data-count="<?= $totalProducts ?>">0</div>
                        <div class="hero-stat-lbl"><?= e(t('products')) ?></div>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <div class="hero-stat-num" data-count="<?= $totalStores ?>">0</div>
                        <div class="hero-stat-lbl"><?= e(t('stores')) ?></div>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <div class="hero-stat-num" data-count="<?= $totalPrices ?>">0</div>
                        <div class="hero-stat-lbl"><?= e(t('price_points')) ?></div>
                    </div>
                </div>
            </div>

            <!-- RIGHT visual -->
            <div class="col-lg-6 d-none d-lg-flex justify-content-center">
                <div class="hero-visual">
                    <!-- floating price cards -->
                    <div class="hero-price-card hero-price-card--1">
                        <div class="hpc-store"><i class="bi bi-shop me-1"></i>Daraz</div>
                        <div class="hpc-price">Rs. 485,000</div>
                        <div class="hpc-badge best">Best Deal</div>
                    </div>
                    <div class="hero-price-card hero-price-card--2">
                        <div class="hpc-store"><i class="bi bi-shop me-1"></i>Singer</div>
                        <div class="hpc-price">Rs. 499,000</div>
                        <div class="hpc-badge">In Stock</div>
                    </div>
                    <div class="hero-price-card hero-price-card--3">
                        <div class="hpc-store"><i class="bi bi-shop me-1"></i>Softlogic</div>
                        <div class="hpc-price">Rs. 510,000</div>
                    </div>
                    <!-- center logo -->
                    <div class="hero-visual-center">
                        <div class="hvc-icon">
                            <img src="<?= url('assets/img/logo.png') ?>" alt="compare.lk"
                                style="width:54px;height:54px;object-fit:contain;">
                        </div>
                        <div class="hvc-label">compare.lk</div>
                    </div>
                    <!-- ring -->
                    <div class="hero-ring"></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!--  HOW IT WORKS-->
<section class="hiw-section">
    <div class="container">
        <div class="hiw-track">
            <?php
            $steps = [
                ['bi-search', t('step1_title'), t('step1_desc'), '#F6A623'],
                ['bi-table', t('step2_title'), t('step2_desc'), '#4285F4'],
                ['bi-shop', t('step3_title'), t('step3_desc'), '#00C853'],
            ];
            foreach ($steps as $i => $step):
                ?>
            <div class="hiw-step">
                <div class="hiw-icon" style="--c:<?= $step[3] ?>">
                    <i class="bi <?= $step[0] ?>"></i>
                </div>
                <div>
                    <div class="hiw-title"><?= e($step[1]) ?></div>
                    <div class="hiw-desc"><?= e($step[2]) ?></div>
                </div>
            </div>
            <?php if ($i < 2): ?>
            <div class="hiw-arrow"><i class="bi bi-chevron-right"></i></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CATEGORIES  -->
<section class="section-gap">
    <div class="container">
        <div class="section-head mb-4">
            <div>
                <div class="section-eyebrow"><?= e(t('browse_by')) ?></div>
                <h2 class="section-title"><?= e(t('categories')) ?></h2>
            </div>
            <a href="<?= url('search.php') ?>" class="btn-see-all">
                <?= e(t('view_all')) ?> <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <div class="cat-grid">
            <?php foreach ($categories as $cat):
                $pcount = $catCounts[$cat['id']] ?? 0;
                ?>
            <a href="<?= url('category.php?slug=' . urlencode($cat['slug'])) ?>" class="cat-card">
                <div class="cat-icon-wrap">
                    <i class="bi <?= e($cat['icon']) ?>"></i>
                </div>
                <div class="cat-name"><?= e($cat['name']) ?></div>
                <div class="cat-count"><?= $pcount ?> <?= e(t('products_count')) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<!-- LATEST PRODUCTS -->
<section class="section-gap bg-alt">
    <div class="container">
        <div class="section-head mb-4">
            <div>
                <div class="section-eyebrow"><?= e(t('recently_updated')) ?></div>
                <h2 class="section-title"><?= e(t('latest_products')) ?></h2>
            </div>
            <a href="<?= url('search.php') ?>" class="btn-see-all">
                <?= e(t('view_all')) ?> <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <?php if (empty($latestProducts)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="bi bi-box-seam"></i></div>
            <h5><?= e(t('no_products')) ?></h5>
            <p class="text-muted"><?= e(t('no_products_desc')) ?></p>
        </div>
        <?php else: ?>
        <div class="products-grid">
            <?php foreach ($latestProducts as $product): ?>
            <a href="<?= url('product.php?id=' . $product['id']) ?>" class="pcard">
                <div class="pcard-img-wrap">
                    <?php if ($product['image']): ?>
                    <img src="<?= url('uploads/products/' . e($product['image'])) ?>" alt="<?= e($product['name']) ?>"
                        class="pcard-img"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
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
                    <div class="pcard-footer">
                        <?php if ($product['min_price']): ?>
                        <div class="pcard-price">
                            <?= formatPrice((float) $product['min_price']) ?>
                            <span class="pcard-onwards">onwards</span>
                        </div>
                        <?php else: ?>
                        <div class="pcard-no-price"><?= e(t('no_price_yet')) ?></div>
                        <?php endif; ?>
                        <?php if ($product['category_name']): ?>
                        <span class="pcard-cat-badge"><?= e($product['category_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- TRUST STRIP-->
<section class="trust-section">
    <div class="container">
        <div class="trust-grid">
            <div class="trust-item">
                <div class="trust-icon" style="--ti:#F6A623"><i class="bi bi-shield-fill-check"></i></div>
                <div>
                    <div class="trust-title">Verified Prices</div>
                    <div class="trust-desc">Checked from official store pages daily</div>
                </div>
            </div>
            <div class="trust-item">
                <div class="trust-icon" style="--ti:#4285F4"><i class="bi bi-clock-fill"></i></div>
                <div>
                    <div class="trust-title">Real-Time Updates</div>
                    <div class="trust-desc">Prices refreshed automatically</div>
                </div>
            </div>
            <div class="trust-item">
                <div class="trust-icon" style="--ti:#00C853"><i class="bi bi-graph-down-arrow"></i></div>
                <div>
                    <div class="trust-title">Price History</div>
                    <div class="trust-desc">Track trends & find the best time to buy</div>
                </div>
            </div>
            <div class="trust-item">
                <div class="trust-icon" style="--ti:#9C27B0"><i class="bi bi-geo-alt-fill"></i></div>
                <div>
                    <div class="trust-title">Sri Lanka Only</div>
                    <div class="trust-desc">Prices exclusively from local stores</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!--  STORES STRIP  -->
<section class="section-gap">
    <div class="container">
        <div class="text-center mb-4">
            <div class="section-eyebrow"><?= e(t('prices_from_stores')) ?></div>
            <h2 class="section-title">Trusted Sri Lankan Stores</h2>
        </div>
        <div class="stores-row">
            <?php foreach ($stores as $store): ?>
            <a href="<?= e($store['website_url']) ?>" target="_blank" rel="noopener" class="store-chip-card">
                <?php if ($store['logo'] && file_exists(__DIR__ . '/uploads/stores/' . $store['logo'])): ?>
                <img src="<?= url('uploads/stores/' . e($store['logo'])) ?>" alt="<?= e($store['name']) ?>"
                    class="store-chip-logo">
                <?php else: ?>
                <div class="store-chip-logo-ph"><i class="bi bi-shop"></i></div>
                <?php endif; ?>
                <span class="store-chip-name"><?= e($store['name']) ?></span>
                <i class="bi bi-arrow-up-right store-chip-arrow"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!--  CTA BANNER  -->
<section class="cta-section">
    <div class="container">
        <div class="cta-card">
            <div class="cta-glow"></div>
            <div class="cta-content">
                <div class="cta-eyebrow">Save More, Shop Smarter</div>
                <h2 class="cta-title">
                    <?= e(t('cta_title')) ?>
                    <span class="cta-title-accent"><?= e(t('cta_title_2')) ?></span>
                </h2>
                <p class="cta-desc"><?= e(t('cta_desc')) ?></p>
                <div class="d-flex flex-wrap gap-3 justify-content-center">
                    <a href="<?= url('search.php') ?>" class="btn btn-cta-primary">
                        <i class="bi bi-search me-2"></i><?= e(t('start_comparing')) ?>
                    </a>
                    <a href="<?= url('category.php?slug=electronics') ?>" class="btn btn-cta-secondary">
                        <i class="bi bi-grid me-2"></i>Browse Categories
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!--  STYLES  -->
<style>
/*HERO  */
.hero-section {
    background: linear-gradient(140deg, var(--dark) 0%, #3A1818 55%, var(--accent) 100%);
    color: #fff;
    padding: 5rem 0 4.5rem;
    min-height: 620px;
}

.min-vh-hero {
    min-height: 520px;
}

/* blobs */
.hero-blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    pointer-events: none;
}

.hero-blob-1 {
    width: 520px;
    height: 520px;
    background: radial-gradient(circle, rgba(246, 166, 35, .22), transparent 70%);
    top: -100px;
    left: -120px;
}

.hero-blob-2 {
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(139, 44, 44, .4), transparent 70%);
    bottom: -80px;
    right: 8%;
}

/* pill badge */
.hero-pill {
    background: rgba(255, 255, 255, .1);
    border: 1px solid rgba(255, 255, 255, .2);
    border-radius: 50px;
    padding: .35rem 1rem;
    font-size: .8rem;
    backdrop-filter: blur(8px);
    color: rgba(255, 255, 255, .9);
}

.hero-pill-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #4ade80;
    box-shadow: 0 0 6px #4ade80;
    flex-shrink: 0;
    animation: pulse-dot 2s infinite;
}

@keyframes pulse-dot {

    0%,
    100% {
        opacity: 1;
        transform: scale(1);
    }

    50% {
        opacity: .5;
        transform: scale(1.4);
    }
}

/* title */
.hero-title {
    font-size: clamp(2rem, 5vw, 3.4rem);
    font-weight: 800;
    line-height: 1.13;
    letter-spacing: -.5px;
    color: #fff;
}

.hero-title-highlight {
    background: linear-gradient(90deg, #F6A623, #FFD580);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.hero-subtitle {
    font-size: 1.05rem;
    opacity: .8;
    max-width: 500px;
    line-height: 1.7;
}

/* search */
.hero-search-wrap {
    max-width: 540px;
}

.hero-search-inner {
    display: flex;
    align-items: center;
    background: #fff;
    border-radius: 16px;
    padding: .5rem .5rem .5rem 1.2rem;
    box-shadow: 0 8px 40px rgba(0, 0, 0, .25);
}

.hero-search-icon {
    color: var(--gray-400);
    font-size: 1.1rem;
    flex-shrink: 0;
}

.hero-search-input {
    border: none;
    outline: none;
    flex: 1;
    padding: .55rem .75rem;
    font-size: .95rem;
    background: transparent;
    color: var(--text-primary);
}

.hero-search-btn {
    background: linear-gradient(135deg, var(--primary), #D48806);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: .65rem 1.4rem;
    font-weight: 700;
    font-size: .9rem;
    white-space: nowrap;
    cursor: pointer;
    transition: all .2s;
}

.hero-search-btn:hover {
    transform: scale(1.04);
    filter: brightness(1.05);
}

.hero-hint-chip {
    background: rgba(255, 255, 255, .1);
    border: 1px solid rgba(255, 255, 255, .2);
    border-radius: 50px;
    color: rgba(255, 255, 255, .8);
    padding: .25rem .8rem;
    font-size: .78rem;
    cursor: pointer;
    transition: all .2s;
}

.hero-hint-chip:hover {
    background: rgba(255, 255, 255, .2);
    color: #fff;
    border-color: rgba(255, 255, 255, .4);
}

/* stats */
.hero-stats {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.hero-stat-num {
    font-size: 2rem;
    font-weight: 800;
    font-family: var(--font-mono);
    line-height: 1;
}

.hero-stat-lbl {
    font-size: .72rem;
    opacity: .6;
    text-transform: uppercase;
    letter-spacing: .8px;
    margin-top: .2rem;
}

.hero-stat-divider {
    width: 1px;
    height: 40px;
    background: rgba(255, 255, 255, .2);
}

/* floating visual */
.hero-visual {
    position: relative;
    width: 380px;
    height: 380px;
}

.hero-ring {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    border: 1.5px dashed rgba(255, 255, 255, .15);
    animation: spin-ring 20s linear infinite;
}

@keyframes spin-ring {
    to {
        transform: rotate(360deg);
    }
}

.hero-visual-center {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
}

.hvc-icon {
    width: 80px;
    height: 80px;
    border-radius: 24px;
    background: linear-gradient(135deg, var(--primary), #D48806);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #fff;
    margin: 0 auto 8px;
    box-shadow: 0 16px 48px rgba(246, 166, 35, .4);
}

.hvc-label {
    font-size: .8rem;
    font-weight: 700;
    color: rgba(255, 255, 255, .7);
    letter-spacing: .5px;
}

.hero-price-card {
    position: absolute;
    background: rgba(255, 255, 255, .1);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, .2);
    border-radius: 14px;
    padding: .75rem 1rem;
    min-width: 150px;
    animation: float-card 4s ease-in-out infinite;
}

.hero-price-card--1 {
    top: 10%;
    left: -20px;
    animation-delay: 0s;
}

.hero-price-card--2 {
    top: 40%;
    right: -20px;
    animation-delay: 1.3s;
}

.hero-price-card--3 {
    bottom: 12%;
    left: 10px;
    animation-delay: 2.6s;
}

@keyframes float-card {

    0%,
    100% {
        transform: translateY(0);
    }

    50% {
        transform: translateY(-10px);
    }
}

.hpc-store {
    font-size: .72rem;
    opacity: .75;
    margin-bottom: .25rem;
    color: #fff;
}

.hpc-price {
    font-family: var(--font-mono);
    font-weight: 700;
    font-size: .95rem;
    color: #fff;
}

.hpc-badge {
    display: inline-block;
    margin-top: .3rem;
    border-radius: 6px;
    padding: .1rem .5rem;
    font-size: .66rem;
    font-weight: 700;
    background: rgba(255, 255, 255, .15);
    color: rgba(255, 255, 255, .85);
}

.hpc-badge.best {
    background: var(--primary);
    color: #fff;
}

/*  HOW IT WORKS */
.hiw-section {
    background: #fff;
    border-bottom: 1px solid var(--border);
    padding: 1.75rem 0;
}

.hiw-track {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.hiw-step {
    display: flex;
    align-items: center;
    gap: .85rem;
}

.hiw-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: color-mix(in srgb, var(--c, #F6A623) 15%, #fff);
    color: var(--c, #F6A623);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}

.hiw-title {
    font-weight: 700;
    font-size: .9rem;
}

.hiw-desc {
    font-size: .75rem;
    color: var(--text-muted);
}

.hiw-arrow {
    color: var(--gray-400);
    font-size: 1.1rem;
}

/*  SECTION UTILITIES  */
.section-gap {
    padding: 4.5rem 0;
}

.bg-alt {
    background: var(--gray-100);
}

.section-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

.section-eyebrow {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--primary);
    margin-bottom: .25rem;
}

.section-title {
    font-size: clamp(1.4rem, 3vw, 1.85rem);
    font-weight: 800;
    letter-spacing: -.3px;
    margin: 0;
}

.btn-see-all {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: .45rem 1rem;
    font-size: .85rem;
    font-weight: 600;
    color: var(--text-primary);
    text-decoration: none;
    transition: all .2s;
    white-space: nowrap;
}

.btn-see-all:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-light);
}

/*  CATEGORY GRID  */
.cat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 1rem;
}

.cat-card {
    background: #fff;
    border: 1.5px solid var(--border);
    border-radius: 18px;
    padding: 1.5rem 1rem 1.25rem;
    text-align: center;
    text-decoration: none;
    color: var(--text-primary);
    transition: all .22s;
    display: block;
}

.cat-card:hover {
    border-color: var(--primary);
    box-shadow: 0 6px 28px rgba(246, 166, 35, .18);
    transform: translateY(-4px);
    color: var(--primary);
}

.cat-icon-wrap {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    margin: 0 auto .85rem;
    transition: all .22s;
}

.cat-card:hover .cat-icon-wrap {
    background: var(--primary);
    color: #fff;
    box-shadow: 0 4px 16px rgba(246, 166, 35, .4);
}

.cat-name {
    font-weight: 700;
    font-size: .88rem;
    line-height: 1.2;
    margin-bottom: .2rem;
}

.cat-count {
    font-size: .72rem;
    color: var(--text-muted);
}

/*  PRODUCT GRID  */
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1.25rem;
}

.pcard {
    background: #fff;
    border: 1.5px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
    text-decoration: none;
    color: var(--text-primary);
    display: flex;
    flex-direction: column;
    transition: all .22s;
}

.pcard:hover {
    border-color: var(--primary);
    box-shadow: 0 8px 32px rgba(246, 166, 35, .15);
    transform: translateY(-4px);
}

.pcard-img-wrap {
    position: relative;
    background: var(--gray-100);
    height: 190px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.pcard-img {
    width: 100%;
    height: 190px;
    object-fit: contain;
    padding: .75rem;
    transition: transform .3s;
}

.pcard:hover .pcard-img {
    transform: scale(1.04);
}

.pcard-img-ph {
    height: 190px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    color: var(--gray-400);
    width: 100%;
}

.pcard-stores-badge {
    position: absolute;
    bottom: .6rem;
    left: .6rem;
    background: rgba(0, 0, 0, .65);
    backdrop-filter: blur(6px);
    color: #fff;
    border-radius: 8px;
    padding: .2rem .55rem;
    font-size: .68rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: .3rem;
}

.pcard-body {
    padding: 1rem 1rem .9rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.pcard-brand {
    font-size: .68rem;
    font-weight: 700;
    color: var(--primary);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: .2rem;
}

.pcard-name {
    font-size: .9rem;
    font-weight: 700;
    line-height: 1.3;
    flex: 1;
    margin-bottom: .65rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.pcard-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    flex-wrap: wrap;
}

.pcard-price {
    font-family: var(--font-mono);
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--accent);
}

.pcard-onwards {
    font-size: .65rem;
    font-weight: 500;
    color: var(--text-muted);
    font-family: var(--font-main);
}

.pcard-no-price {
    font-size: .78rem;
    color: var(--text-muted);
    font-style: italic;
}

.pcard-cat-badge {
    background: var(--primary-light);
    color: var(--primary);
    border-radius: 6px;
    padding: .15rem .55rem;
    font-size: .65rem;
    font-weight: 600;
    white-space: nowrap;
}

/*  TRUST STRIP */
.trust-section {
    background: linear-gradient(135deg, #0d1117 0%, #1a1d2e 100%);
    padding: 3rem 0;
}

.trust-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 2rem;
}

.trust-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    color: #fff;
}

.trust-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: color-mix(in srgb, var(--ti, #F6A623) 20%, transparent);
    border: 1px solid color-mix(in srgb, var(--ti, #F6A623) 30%, transparent);
    color: var(--ti, #F6A623);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}

.trust-title {
    font-weight: 700;
    font-size: .95rem;
    margin-bottom: .2rem;
}

.trust-desc {
    font-size: .78rem;
    opacity: .6;
    line-height: 1.4;
}

/*  STORES  */
.stores-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: .85rem;
}

.store-chip-card {
    display: flex;
    align-items: center;
    gap: .65rem;
    padding: .7rem 1.25rem;
    background: #fff;
    border: 1.5px solid var(--border);
    border-radius: 14px;
    text-decoration: none;
    color: var(--text-primary);
    font-weight: 600;
    font-size: .9rem;
    transition: all .2s;
}

.store-chip-card:hover {
    border-color: var(--primary);
    color: var(--primary);
    box-shadow: 0 4px 18px rgba(246, 166, 35, .15);
    transform: translateY(-2px);
}

.store-chip-logo {
    width: 28px;
    height: 28px;
    object-fit: contain;
    border-radius: 6px;
}

.store-chip-logo-ph {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .8rem;
}

.store-chip-name {
    font-weight: 700;
}

.store-chip-arrow {
    font-size: .7rem;
    opacity: .4;
}

/* CTA */
.cta-section {
    padding: 4.5rem 0;
}

.cta-card {
    background: linear-gradient(135deg, var(--dark), var(--accent));
    border-radius: 28px;
    padding: 4rem 2rem;
    text-align: center;
    color: #fff;
    position: relative;
    overflow: hidden;
}

.cta-glow {
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse 70% 80% at 80% 50%, rgba(246, 166, 35, .25), transparent);
    pointer-events: none;
}

.cta-content {
    position: relative;
}

.cta-eyebrow {
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    opacity: .65;
    margin-bottom: .75rem;
}

.cta-title {
    font-size: clamp(1.6rem, 4vw, 2.4rem);
    font-weight: 800;
    margin-bottom: 1rem;
}

.cta-title-accent {
    background: linear-gradient(90deg, #F6A623, #FFD580);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.cta-desc {
    opacity: .75;
    max-width: 500px;
    margin: 0 auto 2rem;
    line-height: 1.7;
}

.btn-cta-primary {
    display: inline-flex;
    align-items: center;
    background: var(--primary);
    color: #fff !important;
    border: none;
    border-radius: 14px;
    padding: .9rem 2rem;
    font-weight: 700;
    font-size: 1rem;
    text-decoration: none;
    transition: all .2s;
    box-shadow: 0 6px 24px rgba(246, 166, 35, .4);
}

.btn-cta-primary:hover {
    background: #D48806;
    color: #fff !important;
    transform: scale(1.04);
}

.btn-cta-secondary {
    display: inline-flex;
    align-items: center;
    background: rgba(255, 255, 255, .1);
    border: 1.5px solid rgba(255, 255, 255, .25);
    color: #fff !important;
    border-radius: 14px;
    padding: .9rem 2rem;
    font-weight: 700;
    font-size: 1rem;
    text-decoration: none;
    backdrop-filter: blur(8px);
    transition: all .2s;
}

.btn-cta-secondary:hover {
    background: rgba(255, 255, 255, .2);
    color: #fff !important;
}

/*  DARK THEME OVERRIDES  */
html[data-theme="dark"] .hiw-section,
body.theme-dark .hiw-section {
    background: var(--gray-200);
    border-color: var(--border);
}

html[data-theme="dark"] .cat-card,
body.theme-dark .cat-card {
    background: var(--gray-200);
    border-color: var(--border);
    color: var(--text-primary);
}

html[data-theme="dark"] .pcard,
body.theme-dark .pcard {
    background: var(--gray-200);
    border-color: var(--border);
    color: var(--text-primary);
}

html[data-theme="dark"] .pcard-img-wrap,
body.theme-dark .pcard-img-wrap {
    background: var(--gray-100);
}

html[data-theme="dark"] .store-chip-card,
body.theme-dark .store-chip-card {
    background: var(--gray-200);
    border-color: var(--border);
    color: var(--text-primary);
}

html[data-theme="dark"] .btn-see-all,
body.theme-dark .btn-see-all {
    border-color: var(--border);
    color: var(--text-primary);
}

html[data-theme="dark"] .hero-search-input,
body.theme-dark .hero-search-input {
    color: var(--gray-800) !important;
}

/*  RESPONSIVE  */
@media (max-width: 768px) {
    .hero-section {
        padding: 3rem 0 2.5rem;
    }

    .hero-stats {
        gap: 1rem;
    }

    .hero-stat-num {
        font-size: 1.5rem;
    }

    .hiw-arrow {
        display: none;
    }

    .cat-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    .products-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .trust-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1.25rem;
    }
}

@media (max-width: 480px) {
    .cat-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .products-grid {
        grid-template-columns: 1fr 1fr;
        gap: .75rem;
    }

    .trust-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Animated counter script -->
<script>
(function() {
    function animateCount(el, target) {
        var start = 0,
            duration = 1400,
            step = 16;
        var increment = target / (duration / step);
        var timer = setInterval(function() {
            start += increment;
            if (start >= target) {
                start = target;
                clearInterval(timer);
            }
            el.textContent = Math.floor(start) + '+';
        }, step);
    }
    var triggered = false;
    var nums = document.querySelectorAll('.hero-stat-num[data-count]');

    function onScroll() {
        if (triggered) return;
        var rect = nums[0] && nums[0].getBoundingClientRect();
        if (rect && rect.top < window.innerHeight) {
            triggered = true;
            nums.forEach(function(el) {
                animateCount(el, parseInt(el.dataset.count, 10));
            });
        }
    }
    window.addEventListener('scroll', onScroll, {
        passive: true
    });
    // fire immediately if already in view
    setTimeout(onScroll, 300);
})();
</script>

<?php require_once 'includes/footer.php'; 