<?php
/**
 * Admin Prices Management - compare.lk
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

ensureSessionStarted();
requireAdminLogin();

$pdo = getDB();

// Handle delete via plain POST form (debugging version)
if (isset($_POST['delete_price_id'])) {
    if (hash_equals(csrf_token(), $_POST['token'] ?? '')) {
        $delId  = (int) $_POST['delete_price_id'];
        $delPid = (int) ($_POST['product_id'] ?? 0);
        
        $stmt = $pdo->prepare("DELETE FROM product_prices WHERE id=?");
        $stmt->execute([$delId]);
        
        header('Location: ' . url('admin/prices.php') . '?product_id=' . $delPid . '&deleted=1');
        exit;
    } else {
        die("<h1>CSRF Token Mismatch</h1><p>Expected: " . csrf_token() . "</p><p>Got: " . ($_POST['token'] ?? 'none') . "</p>");
    }
}

$adminTitle = 'Price Management';
require_once __DIR__ . '/header.php';

$msg = '';
$error = '';
$productId = (int) ($_GET['product_id'] ?? 0);

if (isset($_GET['deleted'])) {
    $msg = 'Price entry removed successfully.';
}

// Handle POST — add/update price
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['price'])) {
    if (!csrf_verify()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $pId = (int) ($_POST['product_id'] ?? 0);
        $storeId = (int) ($_POST['store_id'] ?? 0);
        $price = (float) ($_POST['price'] ?? 0);
        $url = trim($_POST['product_url'] ?? '');
        $status = $_POST['stock_status'] ?? 'in_stock';
        $priceId = (int) ($_POST['price_id'] ?? 0);

        if (!$pId || !$storeId || !$price || !$url) {
            $error = 'All fields are required.';
        } else {
            if ($priceId) {
                // Update existing price
                $stmt = $pdo->prepare("UPDATE product_prices SET product_id=?, store_id=?, price=?, product_url=?, stock_status=?, last_updated=NOW() WHERE id=?");
                $stmt->execute([$pId, $storeId, $price, $url, $status, $priceId]);
            } else {
                // Insert or update (UPSERT)
                $stmt = $pdo->prepare("
                INSERT INTO product_prices (product_id, store_id, price, product_url, stock_status, last_updated)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE price=VALUES(price), product_url=VALUES(product_url),
                                        stock_status=VALUES(stock_status), last_updated=NOW()
            ");
                $stmt->execute([$pId, $storeId, $price, $url, $status]);
            }

            // Record price history
            $stmt2 = $pdo->prepare("INSERT INTO price_history (product_id, store_id, price) VALUES (?, ?, ?)");
            $stmt2->execute([$pId, $storeId, $price]);

            // Ensure scraper mapping exists / updated
            require_once __DIR__ . '/../includes/scraper.php';
            ensureScraperTables($pdo);
            $stmt3 = $pdo->prepare("
            INSERT INTO product_store_links (product_id, store_id, product_url, last_price, last_status, last_scraped_at)
            VALUES (?, ?, ?, ?, 'manual', NOW())
            ON DUPLICATE KEY UPDATE product_url = VALUES(product_url),
                                    last_price = VALUES(last_price),
                                    last_status = 'manual',
                                    last_scraped_at = NOW()
        ");
            $stmt3->execute([$pId, $storeId, $url, $price]);

            $msg = 'Price saved and history recorded.';
            $productId = $pId; // Keep the product selected to see the updated price list and add more
        }
    }
}

// Backwards compatibility placeholder

// Selected product
$selectedProduct = null;
if ($productId) {
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON p.category_id = c.id WHERE p.id=?");
    $stmt->execute([$productId]);
    $selectedProduct = $stmt->fetch();
}

$editPrice = null;
if (isset($_GET['edit_price'])) {
    $stmt = $pdo->prepare("SELECT * FROM product_prices WHERE id=?");
    $stmt->execute([(int) $_GET['edit_price']]);
    $editPrice = $stmt->fetch();
    if ($editPrice)
        $productId = $editPrice['product_id'];
}

// Current prices for selected product
$currentPrices = [];
if ($productId) {
    $stmt = $pdo->prepare("
        SELECT pp.*, s.name AS store_name, s.logo AS store_logo
        FROM product_prices pp JOIN stores s ON pp.store_id = s.id
        WHERE pp.product_id = ? ORDER BY pp.price ASC
    ");
    $stmt->execute([$productId]);
    $currentPrices = $stmt->fetchAll();
}

$allProducts = $pdo->query("SELECT p.id, p.name, p.brand FROM products p ORDER BY p.name")->fetchAll();
$allStores = $pdo->query("SELECT * FROM stores ORDER BY name")->fetchAll();
?>

<?php if ($msg): ?>
    <div class="alert alert-success mb-3"><i class="bi bi-check-circle me-2"></i><?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Add/Edit Price Form -->
    <div class="col-lg-4">
        <div class="form-card">
            <div class="form-card-header">
                <h5><i class="bi bi-tags me-2"></i><?= $editPrice ? 'Edit Price' : 'Add / Update Price' ?></h5>
            </div>
            <div class="form-card-body">
                <form method="POST" action="<?= url('admin/prices.php') ?>">
                    <?= csrf_field() ?>
                    <?php if ($editPrice): ?>
                        <input type="hidden" name="price_id" value="<?= $editPrice['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Product <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-select" required
                            <?= !$editPrice ? 'onchange="window.location=\''.url('admin/prices.php').'?product_id=\'+this.value"' : '' ?>>
                            <option value="">Select product...</option>
                            <?php foreach ($allProducts as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($editPrice ? $editPrice['product_id'] : $productId) == $p['id'] ? 'selected' : '' ?>>
                                    <?= e(($p['brand'] && stripos($p['name'], $p['brand']) === false ? $p['brand'] . ' ' : '') . $p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Store <span class="text-danger">*</span></label>
                        <select name="store_id" class="form-select" required>
                            <option value="">Select store...</option>
                            <?php foreach ($allStores as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= ($editPrice['store_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Price (LKR) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">Rs</span>
                            <input type="number" name="price" class="form-control" step="0.01" min="0"
                                placeholder="0.00" required value="<?= $editPrice ? $editPrice['price'] : '' ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Product URL <span class="text-danger">*</span></label>
                        <input type="url" name="product_url" class="form-control"
                            placeholder="https://store.lk/product..." required
                            value="<?= e($editPrice['product_url'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Stock Status</label>
                        <select name="stock_status" class="form-select">
                            <option value="in_stock" <?= ($editPrice['stock_status'] ?? '') === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                            <option value="out_of_stock" <?= ($editPrice['stock_status'] ?? '') === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                            <option value="limited" <?= ($editPrice['stock_status'] ?? '') === 'limited' ? 'selected' : '' ?>>Limited</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-check-lg me-1"></i><?= $editPrice ? 'Update Price' : 'Save Price' ?>
                        </button>
                        <?php if ($editPrice): ?>
                            <a href="<?= url('admin/prices.php?product_id=' . $editPrice['product_id']) ?>"
                                class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Current Prices / Product List -->
    <div class="col-lg-8">
        <?php if ($selectedProduct): ?>
            <!-- Selected product prices -->
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h6 class="mb-0 fw-700"><?= e($selectedProduct['name']) ?></h6>
                    <small class="text-muted"><?= e($selectedProduct['category_name']) ?> ·
                        <?= e($selectedProduct['brand'] ?? '') ?></small>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= url('product.php?id=' . $selectedProduct['id']) ?>" target="_blank"
                        class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye me-1"></i>Preview
                    </a>
                    <a href="<?= url('admin/prices.php') ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>All Products
                    </a>
                </div>
            </div>

            <div class="table-card">
                <div class="table-card-header">
                    <h6><i class="bi bi-tags me-2 text-primary"></i>Store Prices (<?= count($currentPrices) ?>)</h6>
                </div>
                <table class="admin-table">
                    <thead style="position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th>Store</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentPrices as $cp): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($cp['store_logo'] && file_exists(__DIR__ . '/../uploads/stores/' . $cp['store_logo'])): ?>
                                            <img src="<?= url('uploads/stores/' . e($cp['store_logo'])) ?>" alt=""
                                                class="store-logo-sm">
                                        <?php endif; ?>
                                        <span class="fw-600"><?= e($cp['store_name']) ?></span>
                                    </div>
                                </td>
                                <td class="fw-700 text-primary"><?= formatPrice((float) $cp['price']) ?></td>
                                <td><?= stockBadge($cp['stock_status']) ?></td>
                                <td><span class="text-muted"
                                        style="font-size:.78rem;"><?= date('d M Y H:i', strtotime($cp['last_updated'])) ?></span>
                                </td>
                                <td>
                                    <a href="<?= url('admin/prices.php?edit_price=' . $cp['id']) ?>"
                                        class="btn btn-icon btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                                    <form method="POST" action="<?= url('admin/prices.php') ?>" class="d-inline">
                                        <input type="hidden" name="token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="delete_price_id" value="<?= $cp['id'] ?>">
                                        <input type="hidden" name="product_id" value="<?= $productId ?>">
                                        <button type="submit" class="btn btn-icon btn-outline-danger" title="Delete price">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($currentPrices)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">No prices yet. Add one on the left.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <!-- All products list to select from -->
            <div class="table-card">
                <div class="table-card-header">
                    <h6><i class="bi bi-box-seam me-2"></i>Select a product to manage prices</h6>
                </div>
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="admin-table">
                        <thead style="position: sticky; top: 0; z-index: 10;">
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Stores</th>
                                <th>Min Price</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $prods = $pdo->query("
                            SELECT p.*, c.name AS category_name,
                                   COUNT(DISTINCT pp.store_id) AS store_count,
                                   MIN(pp.price) AS min_price
                            FROM products p JOIN categories c ON p.category_id=c.id
                            LEFT JOIN product_prices pp ON pp.product_id=p.id
                            GROUP BY p.id ORDER BY p.name
                        ")->fetchAll();
                            foreach ($prods as $p):
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-600"><?= e($p['name']) ?></div>
                                        <small class="text-muted"><?= e($p['brand'] ?? '') ?></small>
                                    </td>
                                    <td><span class="badge"
                                            style="background:var(--admin-gray);color:#374151;"><?= e($p['category_name']) ?></span>
                                    </td>
                                    <td><span
                                            class="badge bg-<?= $p['store_count'] > 0 ? 'success' : 'secondary' ?>"><?= $p['store_count'] ?></span>
                                    </td>
                                    <td><?= $p['min_price'] ? formatPrice((float) $p['min_price']) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td>
                                        <a href="<?= url('admin/prices.php?product_id=' . $p['id']) ?>"
                                            class="btn btn-sm btn-primary">
                                            <i class="bi bi-tags me-1"></i>Manage
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .fw-600 { font-weight: 600; }
    .fw-700 { font-weight: 700; }
</style>

<?php require_once __DIR__ . '/footer.php'; ?>