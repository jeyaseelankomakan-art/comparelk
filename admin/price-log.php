<?php
/**
 * Admin Price Log - compare.lk
 */
$adminTitle = 'Price History Log';
require_once __DIR__ . '/header.php';

$pdo = getDB();
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

$total = $pdo->query("SELECT COUNT(*) FROM price_history")->fetchColumn();
$pages = ceil($total / $limit);

$history = $pdo->query("
    SELECT ph.*, p.name AS product_name, p.brand, s.name AS store_name
    FROM price_history ph
    JOIN products p ON ph.product_id = p.id
    JOIN stores s ON ph.store_id = s.id
    ORDER BY ph.recorded_at DESC
    LIMIT {$limit} OFFSET {$offset}
");
$rows = $history->fetchAll();
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div class="text-muted"><?= number_format($total) ?> total price records</div>
</div>

<div class="table-card">
    <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
        <table class="admin-table">
            <thead style="position: sticky; top: 0; z-index: 10;">
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>Store</th>
                    <th>Price</th>
                    <th>Recorded At</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-muted"><?= $row['id'] ?></td>
                        <td>
                            <div class="fw-600"><?= e(mb_strimwidth($row['product_name'], 0, 35, '…')) ?></div>
                            <small class="text-muted"><?= e($row['brand'] ?? '') ?></small>
                        </td>
                        <td><?= e($row['store_name']) ?></td>
                        <td class="fw-700 text-primary"><?= formatPrice((float) $row['price']) ?></td>
                        <td>
                            <div><?= date('d M Y', strtotime($row['recorded_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i:s', strtotime($row['recorded_at'])) ?></small>
                        </td>
                        <td>
                            <a href="<?= url('product.php?id=' . $row['product_id']) ?>" target="_blank"
                                class="btn btn-icon btn-outline-secondary" title="View Product">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No price history yet</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="p-3 border-top">
            <nav>
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
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
<?php require_once __DIR__ . '/footer.php'; ?>