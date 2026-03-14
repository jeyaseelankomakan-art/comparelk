<?php
/**
 * Product Detail Page - compare.lk
 */
require_once 'includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$product = $id ? getProductById($id) : null;

if (!$product) {
    header('HTTP/1.0 404 Not Found');
    require_once 'includes/lang.php';
    $pageTitle = t('product_not_found');
    require_once 'includes/header.php';
    echo '<div class="container py-5"><div class="empty-state"><div class="empty-state-icon"><i class="bi bi-exclamation-circle"></i></div><h4>' . e(t('product_not_found')) . '</h4><a href="' . url('') . '" class="btn btn-primary">' . e(t('go_home')) . '</a></div></div>';
    require_once 'includes/footer.php';
    exit;
}

$prices = getProductPrices($id);
$bestPrice = !empty($prices) ? $prices[0]['price'] : null; // already sorted ASC
$bestPriceOriginal = !empty($prices) ? $prices[0]['original_price'] : null;

// Build chart data
$chartData = [];
foreach ($prices as $p) {
    $history = getPriceHistory($id, $p['store_id']);
    if (!empty($history)) {
        $chartData[$p['store_name']] = array_map(fn($h) => [
            'date' => date('Y-m-d', strtotime($h['recorded_at'])),
            'price' => $h['price'],
        ], $history);
    }
}

$pageTitle = ($product['brand'] && stripos($product['name'], $product['brand']) === false ? $product['brand'] . ' ' : '') . $product['name'];
$pageDesc = 'Compare prices for ' . $product['name'] . ' from top Sri Lankan stores.';
require_once 'includes/header.php';
?>

<!-- Breadcrumb -->
<div class="breadcrumb-section">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('') ?>"><i class="bi bi-house-fill"></i></a></li>
                <li class="breadcrumb-item"><a
                        href="<?= url('category.php?slug=' . urlencode($product['category_slug'])) ?>"><?= e($product['category_name']) ?></a>
                </li>
                <li class="breadcrumb-item active"><?= e($product['name']) ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="container py-4">
    <div class="row g-4">
        <!-- Product Image & Info -->
        <div class="col-lg-4">
            <?php if ($product['image']): ?>
                <img src="<?= url('uploads/products/' . e($product['image'])) ?>" alt="<?= e($product['name']) ?>"
                    class="product-hero-img w-100"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                <div class="product-hero-img-placeholder" style="display:none;">
                    <i class="bi bi-image"></i>
                </div>
            <?php else: ?>
                <div class="product-hero-img-placeholder">
                    <i class="bi bi-image"></i>
                </div>
            <?php endif; ?>

            <!-- Product quick info card -->
            <div class="info-card mt-3">
                <h6 class="fw-700 mb-3"
                    style="font-size:.8rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);">
                    Product Details</h6>
                <table class="w-100" style="font-size:.875rem;">
                    <?php if ($product['brand']): ?>
                        <tr>
                            <td class="text-muted py-1" style="width:40%;">Brand</td>
                            <td class="fw-600"><?= e($product['brand']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($product['model']): ?>
                        <tr>
                            <td class="text-muted py-1">Model</td>
                            <td class="fw-600"><?= e($product['model']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted py-1">Category</td>
                        <td><a href="<?= url('category.php?slug=' . urlencode($product['category_slug'])) ?>"
                                class="text-primary fw-600"><?= e($product['category_name']) ?></a></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-1">Stores</td>
                        <td class="fw-600"><?= count($prices) ?> available</td>
                    </tr>
                    <?php if ($bestPrice): ?>
                        <tr>
                            <td class="text-muted py-1">Best Price</td>
                            <td class="fw-700 text-price"><?= formatPrice((float) $bestPrice) ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Price Comparison -->
        <div class="col-lg-8">
            <div class="mb-1">
                <span class="product-detail-brand"><?= e($product['brand'] ?? '') ?></span>
            </div>
            <h1 class="product-detail-name mb-2"><?= e($product['name']) ?></h1>
            <?php if ($product['model']): ?>
                <span class="product-detail-model mb-3 d-inline-block">Model: <?= e($product['model']) ?></span>
            <?php endif; ?>

            <?php if ($product['description']): ?>
                <p class="text-muted mt-2" style="font-size:.9rem;line-height:1.7;"><?= e($product['description']) ?></p>
            <?php endif; ?>

            <!-- Best Price Banner -->
            <?php if ($bestPrice): ?>
                <div class="best-price-banner rounded-xl p-3 mb-4 d-flex align-items-center gap-3">
                    <div style="font-size:2rem;"><i class="bi bi-tag-fill text-primary"></i></div>
                    <div>
                        <div
                            style="font-size:.75rem;color:var(--primary);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
                            Best Price Available</div>
                        <div class="d-flex align-items-center gap-2">
                            <div style="font-family:var(--font-mono);font-size:1.8rem;font-weight:800;color:var(--primary);">
                                <?= formatPrice((float) $bestPrice) ?>
                            </div>
                            <?php if (!empty($bestPriceOriginal) && (float)$bestPriceOriginal > (float)$bestPrice): 
                                $diff = (float)$bestPriceOriginal - (float)$bestPrice;
                                $perc = round(($diff / (float)$bestPriceOriginal) * 100);
                            ?>
                            <div class="d-flex flex-column" style="line-height: 1;">
                                <span class="badge" style="background-color: #ea5455; font-size:.7rem; padding:.2em .4em; width: max-content;">
                                    -<?= $perc ?>%
                                </span>
                                <span class="text-muted text-decoration-line-through mt-1" style="font-size:.85rem;">
                                    <?= formatPrice((float) $bestPriceOriginal) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted" style="font-size:.78rem;">Lowest price across <?= count($prices) ?>
                            store<?= count($prices) != 1 ? 's' : '' ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Price Comparison Table -->
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-700 mb-0">Compare Store Prices</h5>
                <span class="text-muted" style="font-size:.8rem;"><i class="bi bi-clock me-1"></i>Updated
                    regularly</span>
            </div>

            <?php if (empty($prices)): ?>
                <div class="info-card text-center py-4">
                    <i class="bi bi-shop" style="font-size:2rem;color:var(--gray-400);"></i>
                    <p class="text-muted mt-2">No prices available yet. Check back soon!</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="price-table">
                        <thead>
                            <tr>
                                <th>Store</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prices as $i => $p):
                                $isBest = (float) $p['price'] === (float) $bestPrice;
                                ?>
                                <tr class="price-row <?= $isBest ? 'best-deal' : '' ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($p['store_logo'] && file_exists(__DIR__ . '/uploads/stores/' . $p['store_logo'])): ?>
                                                <img src="<?= url('uploads/stores/' . e($p['store_logo'])) ?>"
                                                    alt="<?= e($p['store_name']) ?>" class="store-logo-img">
                                            <?php else: ?>
                                                <div class="store-icon-fallback"
                                                    style="width:40px;height:40px;border-radius:8px;background:var(--primary-light);display:flex;align-items:center;justify-content:center;color:var(--primary);">
                                                    <i class="bi bi-shop"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="store-name-cell"><?= e($p['store_name']) ?></div>
                                                <?php if ($isBest): ?>
                                                    <span class="best-deal-badge mt-1">
                                                        <i class="bi bi-lightning-charge-fill"></i> <?= e(t('best_deal')) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['original_price']) && (float)$p['original_price'] > (float)$p['price']): 
                                            $diff = (float)$p['original_price'] - (float)$p['price'];
                                            $perc = round(($diff / (float)$p['original_price']) * 100);
                                        ?>
                                            <div class="price-value <?= $isBest ? 'best' : '' ?>" style="color: #ea5455; font-size: 1.15rem;">
                                                <?= formatPrice((float) $p['price']) ?>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 mt-1">
                                                <span class="text-muted text-decoration-line-through" style="font-size: .85rem;">
                                                    <?= formatPrice((float) $p['original_price']) ?>
                                                </span>
                                                <span class="badge" style="background-color: #ea5455; font-size: .7rem; padding: .25em .4em;">
                                                    -<?= $perc ?>%
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <div class="price-value <?= $isBest ? 'best' : '' ?>">
                                                <?= formatPrice((float) $p['price']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= stockBadge($p['stock_status']) ?></td>
                                    <td>
                                        <div class="last-updated-text">
                                            <?= date('d M Y', strtotime($p['last_updated'])) ?><br>
                                            <span
                                                style="font-size:.7rem;"><?= date('H:i', strtotime($p['last_updated'])) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="<?= e($p['product_url']) ?>" target="_blank" rel="noopener"
                                            class="btn btn-goto-store <?= $p['stock_status'] === 'out_of_stock' ? 'opacity-50' : '' ?>">
                                            <i class="bi bi-arrow-up-right-square me-1"></i><?= e(t('go_to_store')) ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="text-muted mt-2" style="font-size:.75rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    Prices are updated regularly. Click "Go to Store" to see live prices and purchase.
                </p>
            <?php endif; ?>

            <!-- Price History Chart -->
            <?php if (!empty($chartData)): ?>
                <div class="info-card mt-4">
                    <h6 class="fw-700 mb-3">
                        <i class="bi bi-graph-up me-2 text-primary"></i>Price History
                    </h6>
                    <div class="chart-wrapper">
                        <canvas id="priceHistoryChart"></canvas>
                    </div>
                </div>
                <script>
                    // Load Chart.js date adapter
                    const chartDataScript = document.createElement('script');
                    chartDataScript.src = '<?= url('assets/vendor/chartjs-adapter-date-fns.bundle.min.js') ?>';
                    document.head.appendChild(chartDataScript);

                    const chartData = <?= json_encode($chartData) ?>;
                </script>
            <?php endif; ?>
        </div>
    </div>

    <!-- Related Products -->
    <?php
    $related = getProductsByCategory($product['category_id'], 'latest');
    $related = array_filter($related, fn($r) => $r['id'] != $id);
    $related = array_slice(array_values($related), 0, 4);
    if (!empty($related)):
        ?>
        <div class="mt-5">
            <h5 class="fw-700 mb-3">More in <?= e($product['category_name']) ?></h5>
            <div class="row g-3">
                <?php foreach ($related as $r): ?>
                    <div class="col-sm-6 col-lg-3">
                        <a href="<?= url('product.php?id=' . $r['id']) ?>" class="text-decoration-none">
                            <div class="product-card">
                                <?php if ($r['image']): ?>
                                    <img src="<?= url('uploads/products/' . e($r['image'])) ?>" alt="<?= e($r['name']) ?>"
                                        class="product-card-img"
                                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="product-card-img-placeholder" style="display:none;"><i class="bi bi-image"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="product-card-img-placeholder"><i class="bi bi-image"></i></div>
                                <?php endif; ?>
                                <div class="product-card-body">
                                    <div class="product-brand"><?= e($r['brand'] ?? '') ?></div>
                                    <div class="product-name lh-sm"><?= e($r['name']) ?></div>
                                    <?php if ($r['min_price']): ?>
                                        <div class="product-price mt-2"><?= formatPrice((float) $r['min_price']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .fw-600 {
        font-weight: 600;
    }

    .fw-700 {
        font-weight: 700;
    }
</style>

<?php require_once 'includes/footer.php'; ?>