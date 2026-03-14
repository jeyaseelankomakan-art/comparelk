<?php
/**
 * Review Imported Product - compare.lk
 * NOTE: All PHP logic MUST run before header.php is included (which outputs HTML).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/product_importer.php';

ensureSessionStarted();
requireAdminLogin();

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    redirect('admin/imported-products.php');
}

$stmt = $pdo->prepare("SELECT sp.*, s.name as store_name FROM scraped_products sp JOIN stores s ON sp.store_id = s.id WHERE sp.id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    $_SESSION['admin_msg'] = "Product not found or already reviewed.";
    redirect('admin/imported-products.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $mergeProductId = (int)($_POST['merge_product_id'] ?? 0);
    $pName = $_POST['name'] ?? $product['name'];
    $pBrand = $_POST['brand'] ?? $product['brand'];
    $pModel = $_POST['model'] ?? $product['model'];

    try {
        if ($action === 'reject') {
            $pdo->prepare("UPDATE scraped_products SET status = 'rejected' WHERE id = ?")->execute([$id]);
            $_SESSION['admin_msg'] = "Product rejected.";
            redirect('admin/imported-products.php');
        } 
        elseif ($action === 'approve') {
            if (!$categoryId) {
                throw new Exception("Please select a category for this new product.");
            }
            if (empty($pName)) {
                throw new Exception("Name is required.");
            }

            $pdo->beginTransaction();

            $slug = makeSlug($pName);
            $sStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE slug = ?");
            $sStmt->execute([$slug]);
            if ($sStmt->fetchColumn() > 0) {
                $slug .= '-' . time();
            }

            $localImg = downloadAndSaveImage($product['image_url']);
            
            $pStmt = $pdo->prepare("
                INSERT INTO products 
                (category_id, name, brand, model, image, description, created_at, source_store_id, source_product_url, source_product_key, auto_added)
                VALUES (?, ?, ?, ?, ?, '', NOW(), ?, ?, ?, 1)
            ");
            $pStmt->execute([
                $categoryId,
                $pName,
                $pBrand ?: null,
                $pModel ?: null,
                $localImg,
                $product['store_id'],
                $product['product_url'],
                $product['source_product_key']
            ]);
            
            $newProductId = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO product_store_links (product_id, store_id, product_url, last_price, last_scraped_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$newProductId, $product['store_id'], $product['product_url'], $product['price']]);

            $pdo->prepare("INSERT INTO product_prices (product_id, store_id, price, product_url, stock_status, last_updated) VALUES (?, ?, ?, ?, ?, NOW())")
                ->execute([$newProductId, $product['store_id'], $product['price'], $product['product_url'], $product['stock_status']]);

            $pdo->prepare("UPDATE scraped_products SET status = 'approved', category_id = ? WHERE id = ?")
                ->execute([$categoryId, $id]);

            $pdo->commit();
            
            $_SESSION['admin_msg'] = "Product approved and created successfully! (ID: $newProductId)";
            redirect('admin/imported-products.php');

        } 
        elseif ($action === 'merge') {
            if (!$mergeProductId) {
                 throw new Exception("Please select an existing product to merge into.");
            }
            
            $pdo->beginTransaction();

            $mStmt = $pdo->prepare("SELECT id FROM product_store_links WHERE product_id = ? AND store_id = ?");
            $mStmt->execute([$mergeProductId, $product['store_id']]);
            if ($mRow = $mStmt->fetch()) {
                 $pdo->prepare("UPDATE product_store_links SET product_url = ?, last_price = ?, last_scraped_at = NOW() WHERE id = ?")
                     ->execute([$product['product_url'], $product['price'], $mRow['id']]);
            } else {
                 $pdo->prepare("INSERT INTO product_store_links (product_id, store_id, product_url, last_price, last_scraped_at) VALUES (?, ?, ?, ?, NOW())")
                     ->execute([$mergeProductId, $product['store_id'], $product['product_url'], $product['price']]);
            }

            $pStmt = $pdo->prepare("SELECT id FROM product_prices WHERE product_id = ? AND store_id = ?");
            $pStmt->execute([$mergeProductId, $product['store_id']]);
            if ($pRow = $pStmt->fetch()) {
                $pdo->prepare("UPDATE product_prices SET price = ?, product_url = ?, last_updated = NOW() WHERE id = ?")
                    ->execute([$product['price'], $product['product_url'], $pRow['id']]);
            } else {
                $pdo->prepare("INSERT INTO product_prices (product_id, store_id, price, product_url, stock_status, last_updated) VALUES (?, ?, ?, ?, ?, NOW())")
                    ->execute([$mergeProductId, $product['store_id'], $product['price'], $product['product_url'], $product['stock_status']]);
            }

            $pdo->prepare("UPDATE scraped_products SET status = 'merged' WHERE id = ?")->execute([$id]);

            $pdo->commit();

            $_SESSION['admin_msg'] = "Product merged successfully!";
            redirect('admin/imported-products.php');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = class_exists('PDOException') && ltrim(get_class($e), '\\') === 'PDOException' && strpos($e->getMessage(), 'Duplicate entry') !== false
            ? "A constraint failed (possibly Duplicate Entry). Are you sure you're not merging a URL that already exists for this store?"
            : $e->getMessage();
    }
}

// ── All redirects done above. Now safe to output HTML. ──────────────────────
$categories = getCategories();

$firstWord = explode(' ', trim($product['name']))[0];
$kw = '%' . $firstWord . '%';
$simStmt = $pdo->prepare("SELECT p.id, p.name, c.name as category_name
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.name LIKE ?
    ORDER BY p.name ASC
    LIMIT 10");
$simStmt->execute([$kw]);
$suggestedMatches = $simStmt->fetchAll();

$adminTitle = 'Review Product';
require_once __DIR__ . '/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger rounded shadow-sm border-0"><i class="bi bi-exclamation-triangle-fill flex-shrink-0 me-2"></i> <?= e($error) ?></div>
<?php endif; ?>

<div class="mb-4 d-flex justify-content-between align-items-center">
    <div>
        <h4 class="mb-1 text-primary">Review: <?= e($product['name']) ?></h4>
        <span class="badge bg-secondary">Store: <?= e($product['store_name']) ?></span> | 
        <small class="text-muted ms-1"><i class="bi bi-clock-history"></i> Scraped: <?= date('M d, Y H:i', strtotime($product['scraped_at'])) ?></small>
    </div>
    <a href="<?= url('admin/imported-products.php') ?>" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm"><i class="bi bi-arrow-left"></i> Back to Queue</a>
</div>

<div class="row g-4">
    <!-- Left Column: Product Details & Create New Form -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0" style="border-radius:1rem;">
            <div class="card-header bg-gradient bg-light fw-bold px-4 py-3">
                <i class="bi bi-file-earmark-plus text-primary me-2"></i> Action 1: Create as NEW Product
            </div>
            <div class="card-body p-4 text-start">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">

                    <div class="mb-3 text-center">
                        <?php if ($product['image_url']): ?>
                            <img src="<?= e($product['image_url']) ?>" alt="Preview" class="img-thumbnail rounded" style="max-height:200px;">
                        <?php else: ?>
                            <div class="p-4 bg-light text-muted d-inline-block rounded"><i class="bi bi-image fs-1 opacity-50"></i></div>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-12">
                            <label class="form-label text-muted small fw-bold mb-1">Product Title</label>
                            <input type="text" class="form-control" name="name" value="<?= e($product['name']) ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold mb-1">Brand</label>
                            <input type="text" class="form-control" name="brand" value="<?= e($product['brand'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold mb-1">Model</label>
                            <input type="text" class="form-control" name="model" value="<?= e($product['model'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold mb-1">Scraped Price</label>
                            <div class="form-control bg-light fw-bold text-success border-0"><?= formatPrice((float)$product['price']) ?></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold mb-1">Select Category <span class="text-danger">*</span></label>
                            <select class="form-select border-primary" name="category_id" required>
                                <option value="">-- Choose Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex align-items-center mt-4">
                        <button type="submit" class="btn btn-primary rounded px-4 w-100 py-2 shadow-sm d-flex align-items-center justify-content-center gap-2">
                            <i class="bi bi-check-circle"></i> Approve &amp; Publish New Product
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Merge or Reject -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 mb-4" style="border-radius:1rem;">
            <div class="card-header bg-gradient bg-light fw-bold px-4 py-3 border-bottom-0">
                <i class="bi bi-intersect text-warning me-2"></i> Action 2: Merge with EXISTING Product
            </div>
            <div class="card-body p-4 bg-light" style="border-bottom-left-radius:1rem; border-bottom-right-radius:1rem;">
                <p class="text-muted small mb-3">Does this product already exist in your catalog? Select it below to simply append this store's price and link.</p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="merge">

                    <div class="mb-3">
                        <select class="form-select border-warning form-select-lg shadow-sm" name="merge_product_id" required>
                            <option value="">-- Select product to merge into --</option>
                            <?php if (!empty($suggestedMatches)): ?>
                                <optgroup label="Suggested Matches">
                                    <?php foreach ($suggestedMatches as $match): ?>
                                        <option value="<?= $match['id'] ?>">[<?= e($match['category_name']) ?>] <?= e($match['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <optgroup label="All Products (Not listed in suggestion? Use Search)">
                                <?php
                                $allProductsStmt = $pdo->query("SELECT id, name FROM products ORDER BY name ASC");
                                while ($row = $allProductsStmt->fetch(PDO::FETCH_ASSOC)) {
                                    echo "<option value='{$row['id']}'>" . e($row['name']) . "</option>";
                                }
                                ?>
                            </optgroup>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-warning rounded w-100 py-2 px-4 shadow-sm fw-bold">
                        <i class="bi bi-link-45deg fs-5"></i> Merge Price into Selected
                    </button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 border-danger" style="border-radius:1rem; overflow:hidden;">
             <div class="card-header bg-danger text-white fw-bold px-4 py-3">
                 <i class="bi bi-trash text-white me-2 opacity-75"></i> Action 3: Reject Spam/Irrelevant
             </div>
             <div class="card-body p-4">
                 <form method="POST" onsubmit="return confirm('Are you sure you want to reject this item?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <p class="small text-muted mb-3">If this is a fake product, an accessory disguised as a main product, or has an invalid price, click below to dismiss it permanently from the queue.</p>
                    <button type="submit" class="btn btn-outline-danger w-100 rounded">
                        <i class="bi bi-x-circle me-1"></i> Reject &amp; Remove from Queue
                    </button>
                 </form>
             </div>
        </div>

        <div class="mt-4 text-center">
             <a href="<?= e($product['product_url']) ?>" target="_blank" class="text-muted small text-decoration-none">
                 <i class="bi bi-box-arrow-up-right"></i> Verify original page at <?= e($product['store_name']) ?>
             </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
