<?php
/**
 * Admin Products - compare.lk
 */
$adminTitle = 'Products';
require_once __DIR__ . '/header.php';

$pdo = getDB();
$action = $_GET['action'] ?? 'list';
$msg = '';
$error = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $category_id = (int) ($_POST['category_id'] ?? 0);
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        $image = $_POST['existing_image'] ?? null;
        if (!empty($_FILES['image']['name'])) {
            $uploaded = uploadProductImage($_FILES['image']);
            if ($uploaded) {
                $image = $uploaded;
            } else {
                $error = 'Image upload failed. Use JPG/PNG/WEBP (max 5MB).';
            }
        }

        if (!$error) {
            if (!$name || !$category_id) {
                $error = 'Name and Category are required.';
            } else {
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE products SET name=?, category_id=?, brand=?, model=?, description=?, image=? WHERE id=?");
                    $stmt->execute([$name, $category_id, $brand, $model, $description, $image, $id]);
                    $msg = 'Product updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO products (name, category_id, brand, model, description, image) VALUES (?,?,?,?,?,?)");
                    $stmt->execute([$name, $category_id, $brand, $model, $description, $image]);
                    $msg = 'Product added.';
                }
                $action = 'list';
            }
        }
    }
}

// Delete (POST + CSRF required — in its own else so it never fires alongside the add/edit above)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product_id']) && !isset($_POST['name'])) {
    if (!csrf_verify()) {
        $error = 'Security check failed.';
    } else {
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([(int) $_POST['delete_product_id']]);
        $msg = 'Product deleted.';
    }
}

$editProduct = null;
if (($action === 'edit' || $action === 'add') && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([(int) $_GET['id']]);
    $editProduct = $stmt->fetch();
}

$showForm = ($action === 'add' || $action === 'edit');

$allCategories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Products list
$products = $pdo->query("
    SELECT p.*, c.name AS category_name,
           MIN(pp.price) AS min_price,
           COUNT(DISTINCT pp.store_id) AS store_count
    FROM products p
    JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_prices pp ON pp.product_id = p.id
    GROUP BY p.id
    ORDER BY p.created_at DESC
")->fetchAll();
?>

<?php if ($msg): ?>
    <div class="alert alert-success mb-3"><i class="bi bi-check-circle me-2"></i><?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<?php if ($showForm): ?>
    <!-- Product Form -->
    <div class="mb-3">
        <a href="<?= url('admin/products.php') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Products
        </a>
    </div>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="form-card">
                <div class="form-card-header">
                    <h5><?= $editProduct ? '<i class="bi bi-pencil me-2"></i>Edit Product' : '<i class="bi bi-plus-circle me-2"></i>Add Product' ?>
                    </h5>
                </div>
                <div class="form-card-body">
                    <form method="POST" action="<?= url('admin/products.php') ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <?php if ($editProduct): ?>
                            <input type="hidden" name="id" value="<?= $editProduct['id'] ?>">
                            <input type="hidden" name="existing_image" value="<?= e($editProduct['image'] ?? '') ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Product Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required
                                    placeholder="e.g. Samsung Galaxy S24" value="<?= e($editProduct['name'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <select name="category_id" class="form-select" required>
                                    <option value="">Select...</option>
                                    <?php foreach ($allCategories as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= ($editProduct['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                            <?= e($c['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Brand</label>
                                <input type="text" name="brand" class="form-control" placeholder="e.g. Samsung"
                                    value="<?= e($editProduct['brand'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Model Number</label>
                                <input type="text" name="model" class="form-control" placeholder="e.g. SM-S921B"
                                    value="<?= e($editProduct['model'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3"
                                    placeholder="Brief product description..."><?= e($editProduct['description'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Product Image</label>
                                <?php if (!empty($editProduct['image'])): ?>
                                    <div class="mb-2">
                                        <img src="<?= url('uploads/products/' . e($editProduct['image'])) ?>" alt=""
                                            style="width:80px;height:80px;object-fit:contain;border-radius:10px;border:1px solid var(--admin-border);background:var(--admin-gray);">
                                        <small class="text-muted ms-2">Current image</small>
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="image" class="form-control" accept="image/*">
                                <div class="form-text">JPG, PNG, WEBP. Max 5MB. Leave blank to keep existing.</div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i><?= $editProduct ? 'Update Product' : 'Add Product' ?>
                            </button>
                            <a href="<?= url('admin/products.php') ?>" class="btn btn-outline-secondary">Cancel</a>
                            <?php if ($editProduct): ?>
                                <a href="<?= url('admin/prices.php?product_id=' . $editProduct['id']) ?>"
                                    class="btn btn-outline-info ms-auto">
                                    <i class="bi bi-tags me-1"></i>Manage Prices
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- Products List -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <span class="text-muted"><?= count($products) ?> products total</span>
        <a href="<?= url('admin/products.php?action=add') ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>Add Product
        </a>
    </div>

    <div class="table-card">
        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
            <table class="admin-table">
                <thead style="position: sticky; top: 0; z-index: 10;">
                    <tr>
                        <th>#</th>
                        <th>Image</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Min Price</th>
                        <th>Stores</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><?= $p['id'] ?></td>
                            <td>
                                <?php if ($p['image']): ?>
                                    <img src="<?= url('uploads/products/' . e($p['image'])) ?>" alt="" class="product-img-sm"
                                        onerror="this.style.opacity='.3';">
                                <?php else: ?>
                                    <div class="product-img-sm d-flex align-items-center justify-content-center"
                                        style="background:var(--admin-gray);">
                                        <i class="bi bi-image text-muted" style="font-size:.9rem;"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-600"><?= e($p['name']) ?></div>
                                <?php if ($p['brand']): ?>
                                    <small
                                        class="text-muted"><?= e($p['brand']) ?><?= $p['model'] ? ' · ' . e($p['model']) : '' ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="cat-pill"><?= e($p['category_name']) ?></span></td>
                            <td>
                                <?php if ($p['min_price']): ?>
                                    <span class="fw-600"
                                        style="color:var(--admin-primary);font-family:var(--font-mono);"><?= formatPrice((float) $p['min_price']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span
                                    class="badge bg-<?= $p['store_count'] > 0 ? 'success' : 'secondary' ?>"><?= $p['store_count'] ?></span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <a href="<?= url('product.php?id=' . $p['id']) ?>" target="_blank"
                                        class="btn btn-icon btn-outline-secondary" title="View on site"><i
                                            class="bi bi-eye"></i></a>
                                    <a href="<?= url('admin/products.php?action=edit&id=' . $p['id']) ?>"
                                        class="btn btn-icon btn-outline-primary" title="Edit product"><i
                                            class="bi bi-pencil"></i></a>
                                    <a href="<?= url('admin/prices.php?product_id=' . $p['id']) ?>"
                                        class="btn btn-icon btn-outline-secondary" title="Manage Prices"
                                        style="color:#0EA5E9;border-color:#BFDBFE;"><i class="bi bi-tags"></i></a>
                                    <form method="post" action="<?= url('admin/products.php') ?>" class="d-inline"
                                        onsubmit="return confirm('Delete this product and all its prices?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_product_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn btn-icon btn-outline-danger" title="Delete"><i
                                                class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No products yet. <a
                                    href="<?= url('admin/products.php?action=add') ?>">Add one!</a></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<style>
    .fw-600 {
        font-weight: 600;
    }

    .cat-pill {
        display: inline-block;
        background: #F1F5F9;
        color: #475569;
        border-radius: 6px;
        padding: .2em .65em;
        font-size: .72rem;
        font-weight: 600;
        white-space: nowrap;
    }

    html[data-theme="dark"] .cat-pill {
        background: #252836;
        color: #94A3B8;
    }
</style>
<?php require_once __DIR__ . '/footer.php'; ?>