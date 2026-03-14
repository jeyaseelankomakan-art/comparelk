<?php
/**
 * Admin Categories - compare.lk
 */
$adminTitle = 'Categories';
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
        $icon = trim($_POST['icon'] ?? 'bi-tag');
        $id = (int) ($_POST['id'] ?? 0);

        if (!$name) {
            $error = 'Category name is required.';
        } else {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE categories SET name=?, slug=?, icon=? WHERE id=?");
                $stmt->execute([$name, $slug, $icon, $id]);
                $msg = 'Category updated successfully.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (name, slug, icon) VALUES (?, ?, ?)");
                $stmt->execute([$name, $slug, $icon]);
                $msg = 'Category added successfully.';
            }
            $action = 'list';
        }
    }
}

// Delete (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!csrf_verify()) {
        $error = 'Security check failed.';
    } else {
        $delId = (int) $_POST['delete_id'];
        // Check if category has products first (referential integrity check)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
        $stmt->execute([$delId]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Cannot delete category: it still contains products.';
        } else {
            $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$delId]);
            $msg = 'Category deleted.';
        }
    }
    $action = 'list';
}

// Get edit data
$editCat = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id=?");
    $stmt->execute([(int) $_GET['id']]);
    $editCat = $stmt->fetch();
}

$categories = $pdo->query("
    SELECT c.*, COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id ORDER BY c.name
")->fetchAll();

$iconOptions = ['bi-phone', 'bi-laptop', 'bi-tv', 'bi-house-heart', 'bi-headphones', 'bi-camera', 'bi-watch', 'bi-controller', 'bi-printer', 'bi-cpu', 'bi-tag'];
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
                <h5><?= ($action === 'edit' && $editCat) ? '<i class="bi bi-pencil me-2"></i>Edit Category' : '<i class="bi bi-plus-circle me-2"></i>Add Category' ?>
                </h5>
            </div>
            <div class="form-card-body">
                <form method="POST" action="<?= url('admin/categories.php') ?>">
                    <?= csrf_field() ?>
                    <?php if ($editCat): ?>
                        <input type="hidden" name="id" value="<?= $editCat['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Phones"
                            value="<?= e($editCat['name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input type="text" name="slug" class="form-control" placeholder="auto-generated"
                            value="<?= e($editCat['slug'] ?? '') ?>">
                        <div class="form-text">Leave blank to auto-generate</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Icon (Bootstrap Icons class)</label>
                        <select name="icon" class="form-select">
                            <?php foreach ($iconOptions as $ic): ?>
                                <option value="<?= $ic ?>" <?= ($editCat['icon'] ?? '') === $ic ? 'selected' : '' ?>>
                                    <?= $ic ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Preview: <i class="bi <?= e($editCat['icon'] ?? 'bi-tag') ?>"
                                id="iconPreview"></i></div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-check-lg me-1"></i><?= $editCat ? 'Update' : 'Add Category' ?>
                        </button>
                        <?php if ($editCat): ?>
                            <a href="<?= url('admin/categories.php') ?>" class="btn btn-outline-secondary">Cancel</a>
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
                <h6><i class="bi bi-grid me-2"></i>All Categories (<?= count($categories) ?>)</h6>
            </div>
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="admin-table">
                    <thead style="position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th>#</th>
                            <th>Icon</th>
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Products</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><?= $cat['id'] ?></td>
                                <td><i class="bi <?= e($cat['icon']) ?> text-primary fs-5"></i></td>
                                <td class="fw-600"><?= e($cat['name']) ?></td>
                                <td><code style="font-size:.78rem;"><?= e($cat['slug']) ?></code></td>
                                <td><span class="badge bg-primary"><?= $cat['product_count'] ?></span></td>
                                <td>
                                    <a href="<?= url('admin/categories.php?action=edit&id=' . $cat['id']) ?>"
                                        class="btn btn-icon btn-outline-primary me-1" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="<?= url('admin/categories.php') ?>" class="d-inline"
                                        onsubmit="return confirm('Delete this category? Only empty categories can be deleted.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-icon btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No categories yet</td>
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
<script>
    document.querySelector('[name="icon"]')?.addEventListener('change', function () {
        document.getElementById('iconPreview').className = 'bi ' + this.value;
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>