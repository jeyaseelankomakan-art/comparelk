<?php
/**
 * Admin Stores - compare.lk
 */
$adminTitle = 'Stores';
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
        $slug = makeSlug($_POST['slug'] ?? $name);
        $website_url = trim($_POST['website_url'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        // Handle logo upload
        $logo = $_POST['existing_logo'] ?? null;
        if (!empty($_FILES['logo']['name'])) {
            $uploaded = uploadStoreLogo($_FILES['logo']);
            if ($uploaded) {
                $logo = $uploaded;
            } else {
                $error = 'Logo upload failed. Use JPG, PNG, SVG (max 2MB).';
            }
        }

        if (!$error) {
            if (!$name || !$website_url) {
                $error = 'Name and Website URL are required.';
            } else {
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE stores SET name=?, slug=?, logo=?, website_url=? WHERE id=?");
                    $stmt->execute([$name, $slug, $logo, $website_url, $id]);
                    $msg = 'Store updated successfully.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO stores (name, slug, logo, website_url) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $slug, $logo, $website_url]);
                    $msg = 'Store added successfully.';
                }
                $action = 'list';
            }
        }
    }
}

// Delete (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!csrf_verify()) {
        $error = 'Security check failed.';
    } else {
        $delId = (int) $_POST['delete_id'];
        $pdo->prepare("DELETE FROM stores WHERE id=?")->execute([$delId]);
        $msg = 'Store deleted.';
    }
    $action = 'list';
}

// Get edit data
$editStore = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM stores WHERE id=?");
    $stmt->execute([(int) $_GET['id']]);
    $editStore = $stmt->fetch();
}

$stores = $pdo->query("
    SELECT s.*, COUNT(DISTINCT pp.product_id) AS product_count
    FROM stores s
    LEFT JOIN product_prices pp ON pp.store_id = s.id
    GROUP BY s.id ORDER BY s.name
")->fetchAll();
?>

<?php if ($msg): ?>
    <div class="alert alert-success mb-3"><i class="bi bi-check-circle me-2"></i><?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Form -->
    <div class="col-lg-4">
        <div class="form-card">
            <div class="form-card-header">
                <h5><?= ($action === 'edit' && $editStore) ? '<i class="bi bi-pencil me-2"></i>Edit Store' : '<i class="bi bi-plus-circle me-2"></i>Add Store' ?>
                </h5>
            </div>
            <div class="form-card-body">
                <form method="POST" action="<?= url('admin/stores.php') ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <?php if ($editStore): ?>
                        <input type="hidden" name="id" value="<?= $editStore['id'] ?>">
                        <input type="hidden" name="existing_logo" value="<?= e($editStore['logo'] ?? '') ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Store Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Daraz" required
                            value="<?= e($editStore['name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input type="text" name="slug" class="form-control" placeholder="auto-generated"
                            value="<?= e($editStore['slug'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Website URL <span class="text-danger">*</span></label>
                        <input type="url" name="website_url" class="form-control" placeholder="https://www.store.lk"
                            required value="<?= e($editStore['website_url'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo (optional)</label>
                        <?php if (!empty($editStore['logo'])): ?>
                            <div class="mb-2">
                                <img src="<?= url('uploads/stores/' . e($editStore['logo'])) ?>" alt="Current logo"
                                    style="width:48px;height:48px;object-fit:contain;border-radius:8px;border:1px solid var(--admin-border);">
                                <small class="text-muted ms-2">Current logo</small>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <div class="form-text">JPG, PNG, SVG. Max 2MB.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-check-lg me-1"></i><?= $editStore ? 'Update' : 'Add Store' ?>
                        </button>
                        <?php if ($editStore): ?>
                            <a href="<?= url('admin/stores.php') ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="col-lg-8">
        <div class="table-card">
            <div class="table-card-header">
                <h6><i class="bi bi-shop me-2"></i>All Stores (<?= count($stores) ?>)</h6>
            </div>
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="admin-table">
                    <thead style="position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th>#</th>
                            <th>Logo</th>
                            <th>Name</th>
                            <th>Website</th>
                            <th>Products</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stores as $s): ?>
                            <tr>
                                <td><?= $s['id'] ?></td>
                                <td>
                                    <?php if ($s['logo'] && file_exists(__DIR__ . '/../uploads/stores/' . $s['logo'])): ?>
                                        <img src="<?= url('uploads/stores/' . e($s['logo'])) ?>" alt="" class="store-logo-sm">
                                    <?php else: ?>
                                        <div class="store-logo-sm d-flex align-items-center justify-content-center"
                                            style="background:var(--admin-gray);">
                                            <i class="bi bi-shop text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-600"><?= e($s['name']) ?></td>
                                <td>
                                    <a href="<?= e($s['website_url']) ?>" target="_blank" class="text-primary"
                                        style="font-size:.8rem;">
                                        <?= e(parse_url($s['website_url'], PHP_URL_HOST) ?? $s['website_url']) ?>
                                        <i class="bi bi-arrow-up-right"></i>
                                    </a>
                                </td>
                                <td><span class="badge bg-success"><?= $s['product_count'] ?></span></td>
                                <td>
                                    <a href="<?= url('admin/stores.php?action=edit&id=' . $s['id']) ?>"
                                        class="btn btn-icon btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                                    <form method="POST" action="<?= url('admin/stores.php') ?>" class="d-inline"
                                        onsubmit="return confirm('Delete this store?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn btn-icon btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($stores)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No stores yet</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .fw-600 {
        font-weight: 600;
    }
</style>
<?php require_once __DIR__ . '/footer.php'; ?>