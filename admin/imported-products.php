<?php
/**
 * Imported Products Management - compare.lk
 * NOTE: All PHP logic (including redirects) MUST run before header.php is included.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';

ensureSessionStarted();
requireAdminLogin();

$pdo = getDB();

// Handle basic actions (POST only — protects against CSRF via crafted GET links)
$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0 && csrf_verify()) {
    try {
        if ($action === 'reject') {
            $pdo->prepare("UPDATE scraped_products SET status = 'rejected' WHERE id = ?")->execute([$id]);
            $_SESSION['admin_msg'] = "Product #$id marked as rejected.";
        } elseif ($action === 'delete') {
             $pdo->prepare("DELETE FROM scraped_products WHERE id = ?")->execute([$id]);
             $_SESSION['admin_msg'] = "Product #$id deleted completely.";
        }
        redirect('admin/imported-products.php');
    } catch (Exception $e) {
        $error = "Error updating product: " . $e->getMessage();
    }
}

// Filters
$filterStatus = $_GET['status'] ?? 'pending';
$validStatuses = ['pending', 'approved', 'rejected', 'merged'];
if (!in_array($filterStatus, $validStatuses)) {
    $filterStatus = 'pending';
}

$page = max(1, (int)($_GET['p'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Fetch data
$countSql = "SELECT COUNT(*) FROM scraped_products WHERE status = ?";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute([$filterStatus]);
$totalItems = $stmtCount->fetchColumn();
$totalPages = ceil($totalItems / $limit);

$sql = "SELECT sp.*, s.name as store_name, c.name as category_name 
        FROM scraped_products sp 
        JOIN stores s ON sp.store_id = s.id 
        LEFT JOIN categories c ON sp.category_id = c.id
        WHERE sp.status = ? 
        ORDER BY sp.scraped_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute([$filterStatus]);
$products = $stmt->fetchAll();

$msg = $_SESSION['admin_msg'] ?? null;
unset($_SESSION['admin_msg']);

// ── All redirects done above. Now safe to output HTML. ──────────────────────
$adminTitle = 'Imported Products';
require_once __DIR__ . '/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <p class="text-muted mb-0">Review automatically scraped products before they go live on the site.</p>
    </div>
    <div class="col-auto">
        <a href="<?= url('cron/import-products.php') ?>" target="_blank" class="btn btn-outline-primary shadow-sm rounded-pill">
            <i class="bi bi-play-circle"></i> Run Importer
        </a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?= e($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <?= e($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4">
    <?php foreach ($validStatuses as $st): ?>
    <li class="nav-item">
        <a class="nav-link <?= $filterStatus === $st ? 'active fw-bold' : '' ?>" href="?status=<?= $st ?>">
            <?= ucfirst($st) ?>
            <?php
            if ($st === 'pending') {
                $cStmt = $pdo->query("SELECT COUNT(*) FROM scraped_products WHERE status='pending'");
                $c = $cStmt->fetchColumn();
                if ($c > 0) echo "<span class='badge bg-danger ms-1'>$c</span>";
            }
            ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="table-card" style="border-radius:1rem; overflow:hidden;">
    <div class="table-responsive">
        <table class="admin-table" style="min-width: 900px;">
            <thead>
                <tr>
                    <th>Store</th>
                    <th>Image</th>
                    <th>Title</th>
                    <th>Price</th>
                    <th>Brand/Model</th>
                    <th>Scraped At</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 mb-2 d-block"></i> No <?= e($filterStatus) ?> products found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td>
                                <span class="badge bg-secondary"><?= e($p['store_name']) ?></span>
                            </td>
                            <td>
                                <?php if ($p['image_url']): ?>
                                    <img src="<?= e($p['image_url']) ?>" alt="Img" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
                                <?php else: ?>
                                    <div class="bg-light d-flex align-items-center justify-content-center text-muted rounded" style="width:40px;height:40px;">
                                        <i class="bi bi-image"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= e($p['product_url']) ?>" target="_blank" class="fw-bold text-decoration-none">
                                    <?= e($p['name']) ?>
                                    <i class="bi bi-box-arrow-up-right ms-1 text-muted" style="font-size:0.75rem;"></i>
                                </a>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-link-45deg"></i>
                                    <?= e(parse_url($p['product_url'], PHP_URL_HOST) ?? $p['product_url']) ?>
                                </div>
                            </td>
                            <td class="fw-bold text-success">
                                <?= formatPrice((float)$p['price']) ?>
                            </td>
                            <td>
                                <small>Brand: <strong><?= e($p['brand'] ?? 'N/A') ?></strong><br>
                                Model: <strong><?= e($p['model'] ?? 'N/A') ?></strong></small>
                            </td>
                            <td>
                                <small class="text-muted"><?= e(date('M d, Y H:i', strtotime($p['scraped_at']))) ?></small>
                            </td>
                            <td class="text-end">
                                <?php if ($p['status'] === 'pending'): ?>
                                    <a href="<?= url("admin/review-product.php?id={$p['id']}") ?>" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">
                                        Review
                                    </a>
                                <?php endif; ?>
                                
                                <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Are you sure you want to completely delete this record?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-4">
    <ul class="pagination justify-content-center">
        <?php for($i=1; $i<=$totalPages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?status=<?= urlencode($filterStatus) ?>&p=<?= $i ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
