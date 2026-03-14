<?php
/**
 * Admin Dashboard - compare.lk
 */
require_once __DIR__ . '/../includes/lang.php';
$adminTitle = t('admin_dashboard');
require_once __DIR__ . '/header.php';

$pdo = getDB();

// Stats
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalStores = $pdo->query("SELECT COUNT(*) FROM stores")->fetchColumn();
$totalCategories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$updatesToday = $pdo->query("SELECT COUNT(*) FROM product_prices WHERE DATE(last_updated) = CURDATE()")->fetchColumn();
$totalMessages = $pdo->query("SELECT COUNT(*) FROM contact_messages")->fetchColumn();
$totalHistory = $pdo->query("SELECT COUNT(*) FROM price_history")->fetchColumn();

// Recent price updates
$recentPrices = $pdo->query("
    SELECT pp.*, p.name AS product_name, s.name AS store_name
    FROM product_prices pp
    JOIN products p ON pp.product_id = p.id
    JOIN stores s ON pp.store_id = s.id
    ORDER BY pp.last_updated DESC
    LIMIT 10
")->fetchAll();

// Recent messages
$recentMessages = $pdo->query("
    SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 5
")->fetchAll();
?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <?php
    // [icon, icon-bg, icon-color, value, label, link]
    $stats = [
        ['bi-box-seam', 'rgba(246,166,35,.12)', '#D48806', $totalProducts, t('total_products'), '/admin/products.php'],
        ['bi-shop', 'rgba(34,197,94,.12)', '#16A34A', $totalStores, t('total_stores'), '/admin/stores.php'],
        ['bi-grid', 'rgba(139,44,44,.12)', '#8B2C2C', $totalCategories, t('total_categories'), '/admin/categories.php'],
        ['bi-tags', 'rgba(14,165,233,.12)', '#0284C7', $updatesToday, t('updated_today'), '/admin/prices.php'],
        ['bi-envelope', 'rgba(239,68,68,.12)', '#DC2626', $totalMessages, t('total_messages'), '/admin/messages.php'],
        ['bi-clock-history', 'rgba(100,116,139,.12)', '#64748B', $totalHistory, t('price_records'), '/admin/price-log.php'],
    ];
    foreach ($stats as $s): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="<?= url(ltrim($s[5], '/')) ?>" class="text-decoration-none h-100 d-block">
                <div class="stat-card h-100">
                    <div class="stat-icon" style="background:<?= $s[1] ?>;color:<?= $s[2] ?>;">
                        <i class="bi <?= $s[0] ?>"></i>
                    </div>
                    <div>
                        <div class="stat-num"><?= number_format($s[3]) ?></div>
                        <div class="stat-label"><?= $s[4] ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>

</div>

<div class="row g-4">
    <!-- Recent Price Updates -->
    <div class="col-lg-7">
        <div class="table-card">
            <div class="table-card-header">
                <h6><i class="bi bi-clock-history me-2 text-primary"></i><?= e(t('recent_price_updates')) ?></h6>
                <a href="<?= url('admin/prices.php') ?>"
                    class="btn btn-sm btn-outline-primary"><?= e(t('view_all')) ?></a>
            </div>
            <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
                <table class="admin-table">
                    <thead style="position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th><?= e(t('products')) ?></th>
                            <th><?= e(t('store')) ?></th>
                            <th><?= e(t('admin_prices')) ?></th>
                            <th><?= e(t('status')) ?></th>
                            <th><?= e(t('updated')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPrices as $row): ?>
                            <tr>
                                <td>
                                    <a href="<?= url('product.php?id=' . $row['product_id']) ?>" target="_blank"
                                        class="text-decoration-none fw-600 text-dark">
                                        <?= e(mb_strimwidth($row['product_name'], 0, 28, '…')) ?>
                                    </a>
                                </td>
                                <td><?= e($row['store_name']) ?></td>
                                <td class="fw-700 text-primary"><?= formatPrice((float) $row['price']) ?></td>
                                <td><?= stockBadge($row['stock_status']) ?></td>
                                <td><span class="text-muted"
                                        style="font-size:.78rem;"><?= date('d M H:i', strtotime($row['last_updated'])) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentPrices)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No price data yet</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Quick Actions + Recent Messages -->
    <div class="col-lg-5">
        <div class="form-card mb-3">
            <div class="form-card-header">
                <h5><i class="bi bi-lightning-charge me-2 text-warning"></i><?= e(t('quick_actions')) ?></h5>
            </div>
            <div class="form-card-body">
                <div class="d-grid gap-2">
                    <a href="<?= url('admin/products.php?action=add') ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i><?= e(t('add_new_product')) ?>
                    </a>
                    <a href="<?= url('admin/prices.php') ?>" class="btn btn-outline-primary">
                        <i class="bi bi-tags me-2"></i><?= e(t('update_prices')) ?>
                    </a>
                    <a href="<?= url('admin/stores.php?action=add') ?>" class="btn btn-outline-success">
                        <i class="bi bi-shop me-2"></i><?= e(t('add_store')) ?>
                    </a>
                    <a href="<?= url('admin/categories.php?action=add') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-grid-plus me-2"></i><?= e(t('add_category')) ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <h6><i class="bi bi-envelope me-2 text-danger"></i><?= e(t('recent_messages')) ?></h6>
                <a href="<?= url('admin/messages.php') ?>"
                    class="btn btn-sm btn-outline-danger"><?= e(t('view_all')) ?></a>
            </div>
            <div class="table-responsive" style="max-height: 260px; overflow-y: auto;">
                <table class="admin-table">
                    <thead style="position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th><?= e(t('from')) ?></th>
                            <th><?= e(t('date')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentMessages as $msg): ?>
                            <tr>
                                <td>
                                    <div class="fw-600"><?= e($msg['name']) ?></div>
                                    <div class="text-muted" style="font-size:.75rem;">
                                        <?= e(mb_strimwidth($msg['message'], 0, 40, '…')) ?>
                                    </div>
                                </td>
                                <td><span class="text-muted"
                                        style="font-size:.78rem;"><?= date('d M', strtotime($msg['created_at'])) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentMessages)): ?>
                            <tr>
                                <td colspan="2" class="text-center text-muted py-3"><?= e(t('no_messages_yet')) ?></td>
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

    .fw-700 {
        font-weight: 700;
    }
</style>

<?php require_once __DIR__ . '/footer.php'; ?>