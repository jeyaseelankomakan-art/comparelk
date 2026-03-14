<?php

/**
 * Admin Price Scraper - compare.lk
 * - Configure auto scraping mappings (product + store + URL)
 * - Run an on-demand auto scrape (same logic as cron/auto-scrape.php)
 */
$adminTitle = 'Price Scraper';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/scraper.php';

$pdo = getDB();
ensureScraperTables($pdo);
$msg = '';
$error = '';
$fetchUrl = trim($_GET['url'] ?? '');
$isFetched = false;
$result = ['length' => 0, 'title' => '', 'prices' => [], 'firstPrice' => null];

// Handle create / toggle / run actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_link') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $storeId = (int) ($_POST['store_id'] ?? 0);
            $url = trim($_POST['product_url'] ?? '');
            if (!$productId || !$storeId || !$url) {
                $error = 'Please select product, store and enter URL.';
            } elseif (!preg_match('#^https?://#i', $url)) {
                $error = 'Please enter a valid URL (e.g. https://www.daraz.lk/...).';
            } else {
                $stmt = $pdo->prepare("
                INSERT INTO product_store_links (product_id, store_id, product_url, auto_enabled)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE product_url = VALUES(product_url), auto_enabled = 1
            ");
                $stmt->execute([$productId, $storeId, $url]);
                $msg = 'Scraper link saved.';
            }
        } elseif ($action === 'toggle' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $enabled = (int) ($_POST['enabled'] ?? 0);
            $pdo->prepare("UPDATE product_store_links SET auto_enabled = ? WHERE id = ?")
                ->execute([$enabled ? 1 : 0, $id]);
            $msg = 'Scraper link updated.';
        } elseif ($action === 'run_all') {
            // Run an immediate scrape for all enabled links (same logic as cron)
            $limit = 50;
            $stmt = $pdo->query("
            SELECT * FROM product_store_links
            WHERE auto_enabled = 1
            ORDER BY (last_scraped_at IS NULL) DESC, last_scraped_at ASC
            LIMIT {$limit}
        ");
            $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ok = 0;
            $fail = 0;
            foreach ($links as $link) {
                $res = scrapeProductStoreLink($pdo, $link);
                if ($res['status'] === 'ok')
                    $ok++;
                else
                    $fail++;
            }
            $msg = "Auto-scrape finished. Updated prices: {$ok}, issues: {$fail}.";
        } elseif ($action === 'quick_add_price') {
            // Quick-add price directly from the fetch result
            $productId = (int) ($_POST['product_id'] ?? 0);
            $storeId = (int) ($_POST['store_id'] ?? 0);
            $price = (float) ($_POST['price'] ?? 0);
            $url = trim($_POST['product_url'] ?? '');
            $status = $_POST['stock_status'] ?? 'in_stock';
            $backUrl = trim($_POST['back_url'] ?? '');

            if (!$productId || !$storeId || !$price || !$url) {
                $error = 'Please fill in all fields to add the price.';
            } else {
                // Upsert into product_prices
                $stmt = $pdo->prepare("
                    INSERT INTO product_prices (product_id, store_id, price, product_url, stock_status, last_updated)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE price=VALUES(price), product_url=VALUES(product_url),
                                            stock_status=VALUES(stock_status), last_updated=NOW()
                ");
                $stmt->execute([$productId, $storeId, $price, $url, $status]);

                // Record price history
                $pdo->prepare("INSERT INTO price_history (product_id, store_id, price) VALUES (?, ?, ?)")
                    ->execute([$productId, $storeId, $price]);

                // Ensure scraper link exists
                ensureScraperTables($pdo);
                $pdo->prepare("
                    INSERT INTO product_store_links (product_id, store_id, product_url, last_price, last_status, last_scraped_at)
                    VALUES (?, ?, ?, ?, 'manual', NOW())
                    ON DUPLICATE KEY UPDATE product_url=VALUES(product_url), last_price=VALUES(last_price),
                                            last_status='manual', last_scraped_at=NOW()
                ")->execute([$productId, $storeId, $url, $price]);

                $msg = 'Price added successfully!';
                // Redirect back to fetch result so user can keep editing
                if ($backUrl) {
                    header('Location: ' . url('admin/scraper.php') . '?url=' . urlencode($backUrl) . '&saved=1');
                    exit;
                }
            }
        }
    }
}

// Handle one-off fetch preview (GET)
if ($fetchUrl !== '') {
    if (!preg_match('#^https?://#i', $fetchUrl)) {
        $error = 'Please enter a valid URL (e.g. https://www.daraz.lk/...).';
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'compare.lk/1.0 (Admin fetch preview)',
                'follow_location' => 1,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $html = @file_get_contents($fetchUrl, false, $context);
        if ($html === false) {
            $error = 'Fetch failed. The site may block requests or require JavaScript.';
        } else {
            $title = null;
            if (preg_match('/<title[^>]*>(.*?)<\\/title>/is', $html, $m)) {
                $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
            }

            $prices = [];
            if (preg_match_all('/\\b(?:Rs\\.?|LKR)\\s*([\\d,]+(?:\\.\\d{2})?)/i', $html, $mm)) {
                foreach ($mm[0] as $raw) {
                    $raw = trim(preg_replace('/\\s+/', ' ', $raw));
                    if ($raw !== '')
                        $prices[] = $raw;
                }
            }
            $prices = array_values(array_unique($prices));
            if (count($prices) > 20)
                $prices = array_slice($prices, 0, 20);

            // Use the same smart extractor as the auto-scraper so the
            // pre-filled price field always matches what the cron job saves.
            $detectedPrice    = extractPriceFromHtml($html);
            $detectedOriginal = $detectedPrice ? extractOriginalPriceFromHtml($html, $detectedPrice) : null;

            $isFetched = true;
            $result = [
                'length'     => strlen($html),
                'title'      => $title,
                'prices'     => $prices,
                'firstPrice' => $detectedPrice,
                'origPrice'  => $detectedOriginal,
            ];
        }
    }
}

// Data for forms / table
$allProducts = $pdo->query("SELECT id, name, brand FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$allStores = $pdo->query("SELECT id, name FROM stores ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$linksStmt = $pdo->query("
    SELECT l.*, p.name AS product_name, p.brand, s.name AS store_name
    FROM product_store_links l
    JOIN products p ON l.product_id = p.id
    JOIN stores s ON l.store_id = s.id
    ORDER BY p.name, s.name
");
$links = $linksStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
/* Scraper page — full viewport fit */
.scraper-layout {
    display: flex;
    gap: 1.25rem;
    height: calc(100vh - 80px - 3.5rem);
    /* viewport minus topbar and content padding */
    min-height: 480px;
}

.scraper-col-left {
    flex: 0 0 380px;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    overflow-y: auto;
    /* scrollable so Add Price form is always reachable */
    overflow-x: hidden;
}

.scraper-col-right {
    flex: 1 1 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* fetch card — fixed height */
.scraper-fetch-card {
    flex-shrink: 0;
}

/* result card — natural height, no flex growing needed since col scrolls */
.scraper-result-card {
    flex-shrink: 0;
}

.scraper-result-card .form-card-body {
    /* scrolling happens at column level */
    overflow: visible;
}

/* right col — full height card */
.scraper-links-card {
    flex: 1 1 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.scraper-links-card .form-card-body {
    flex: 1 1 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* the add-link form + table container */
.scraper-table-wrap {
    flex: 1 1 0;
    overflow-y: auto;
}

/* shrink URL cell text */
.url-cell {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: .75rem;
    color: #6b7a99;
}

@media (max-width: 991px) {
    .scraper-layout {
        flex-direction: column;
        height: auto;
    }

    .scraper-col-left,
    .scraper-col-right {
        flex: unset;
    }

    .scraper-result-card,
    .scraper-links-card {
        max-height: 420px;
    }
}

/* price chip buttons */
.price-chip {
    padding: .15rem .5rem;
    font-size: .72rem;
    border: 1px solid var(--admin-primary);
    color: var(--admin-primary-dark);
    background: rgba(246, 166, 35, .08);
    border-radius: 6px;
    cursor: pointer;
    transition: background .15s;
}

.price-chip:hover {
    background: rgba(246, 166, 35, .22);
}

/* quick-add form compactness */
.quick-add-form .form-select-sm,
.quick-add-form .form-control-sm {
    font-size: .8rem;
}

/* fw-600 utility */
.fw-600 {
    font-weight: 600;
}
</style>

<div class="scraper-layout">

    <!-- ───── LEFT: Fetch tool ───── -->
    <div class="scraper-col-left">

        <!-- Fetch form -->
        <div class="form-card scraper-fetch-card">
            <div class="form-card-header">
                <h5><i class="bi bi-cloud-download me-2 text-primary"></i>Fetch product page</h5>
            </div>
            <div class="form-card-body">
                <p class="text-muted small mb-3">
                    Paste a store product URL to detect its title &amp; price in raw HTML.
                </p>
                <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><i
                        class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="get" action="<?= url('admin/scraper.php') ?>" class="mb-0">
                    <div class="input-group">
                        <input type="url" name="url" class="form-control"
                            placeholder="https://www.daraz.lk/products/..." value="<?= htmlspecialchars($fetchUrl) ?>"
                            required>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-1"></i>Fetch
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Quick Add Form (Always visible) -->
        <?php $saved = isset($_GET['saved']); ?>
        <div class="form-card scraper-result-card">
            <div class="form-card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0">
                    <i class="bi bi-plus-circle me-2 text-primary"></i>
                    <?= $isFetched ? 'Fetch Result & Add Price' : 'Add Price to Product' ?>
                </h6>
                <?php if ($isFetched): ?>
                <small class="text-muted"><?= number_format($result['length']) ?> bytes</small>
                <?php endif; ?>
            </div>
            <div class="form-card-body">

                <?php if ($saved): ?>
                <div class="alert alert-success py-2 small mb-2"><i class="bi bi-check-circle me-1"></i>Price saved
                    successfully!</div>
                <?php endif; ?>

                <?php if ($isFetched): ?>
                <!-- Detected info -->
                <?php if ($result['title']): ?>
                <p class="mb-1 small"><strong>Title:</strong> <?= htmlspecialchars($result['title']) ?></p>
                <?php endif; ?>
                <?php if ($result['firstPrice']): ?>
                <p class="mb-1 small">
                    <strong>Auto-detected price:</strong>
                    <span class="badge bg-success ms-1">Rs <?= number_format($result['firstPrice'], 2) ?></span>
                    <?php if (!empty($result['origPrice'])): ?>
                    <span class="text-muted text-decoration-line-through ms-1" style="font-size:.78rem;">
                        Rs <?= number_format($result['origPrice'], 2) ?>
                    </span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($result['prices'])): ?>
                <p class="mb-0 small text-muted">All Rs/LKR values on page:
                    <?= implode(', ', array_map('htmlspecialchars', array_slice($result['prices'], 0, 5))) ?>
                    <?= count($result['prices']) > 5 ? ' …' : '' ?>
                </p>
                <?php endif; ?>
                <hr class="my-2">
                <?php endif; ?>

                <!-- Quick Add Price Form -->
                <form method="post" action="<?= url('admin/scraper.php') ?>" class="quick-add-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="quick_add_price">
                    <input type="hidden" name="back_url" value="<?= htmlspecialchars($fetchUrl) ?>">

                    <div class="mb-2">
                        <label class="form-label mb-1" style="font-size:.78rem;">Product <span
                                class="text-danger">*</span></label>
                        <select name="product_id" class="form-select form-select-sm" required>
                            <option value="">Select product…</option>
                            <?php foreach ($allProducts as $pr): ?>
                            <option value="<?= (int) $pr['id'] ?>">
                                <?= e(($pr['brand'] ? $pr['brand'] . ' ' : '') . $pr['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-1" style="font-size:.78rem;">Store <span
                                class="text-danger">*</span></label>
                        <select name="store_id" class="form-select form-select-sm" required id="qaStoreSelect">
                            <option value="">Select store…</option>
                            <?php
                            $fetchHost = parse_url($fetchUrl, PHP_URL_HOST);
                            foreach ($allStores as $st):
                                // Auto-match store by domain
                                $storeHost = parse_url($st['website_url'] ?? '', PHP_URL_HOST);
                                $autoMatch = $fetchHost && $storeHost && (
                                    str_contains($fetchHost, str_replace('www.', '', $storeHost)) ||
                                    str_contains($storeHost, str_replace('www.', '', $fetchHost))
                                );
                            ?>
                            <option value="<?= (int) $st['id'] ?>" <?= $autoMatch ? 'selected' : '' ?>>
                                <?= e($st['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-1" style="font-size:.78rem;">Price (LKR) <span
                                class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Rs</span>
                            <input type="number" name="price" class="form-control" step="0.01" min="0" required
                                placeholder="0.00"
                                value="<?= $result['firstPrice'] ? htmlspecialchars($result['firstPrice']) : '' ?>">
                        </div>
                        <?php if (!empty($result['prices'])): ?>
                        <div class="mt-1">
                            <?php foreach ($result['prices'] as $pv): ?>
                            <?php $num = preg_replace('/[^0-9.]/', '', $pv);
                                    if (!$num)
                                        continue; ?>
                            <button type="button" class="btn btn-xs price-chip me-1 mb-1"
                                onclick="this.closest('form').querySelector('[name=price]').value='<?= htmlspecialchars($num) ?>'">
                                <?= htmlspecialchars($pv) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-1" style="font-size:.78rem;">Stock Status</label>
                        <select name="stock_status" class="form-select form-select-sm">
                            <option value="in_stock">In Stock</option>
                            <option value="out_of_stock">Out of Stock</option>
                            <option value="limited">Limited</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-1" style="font-size:.78rem;">Product URL</label>
                        <input type="url" name="product_url" class="form-control form-control-sm" required
                            value="<?= htmlspecialchars($fetchUrl) ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-plus-circle me-1"></i>Add Price
                    </button>
                </form>

            </div>
        </div>


    </div><!-- /left col -->

    <!-- ───── RIGHT: Auto-scrape links ───── -->
    <div class="scraper-col-right">
        <div class="form-card scraper-links-card">

            <!-- Header + run button -->
            <div class="form-card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0"><i class="bi bi-link-45deg me-2 text-primary"></i>Auto-scrape links
                    <span class="badge bg-secondary ms-1" style="font-size:.7rem;"><?= count($links) ?></span>
                </h6>
                <form method="post" action="<?= url('admin/scraper.php') ?>" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="run_all">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-play-fill me-1"></i>Run all now
                    </button>
                </form>
            </div>

            <div class="form-card-body">

                <!-- Alerts -->
                <?php if ($msg): ?>
                <div class="alert alert-success py-2 small mb-2"><i class="bi bi-check-circle me-1"></i><?= e($msg) ?>
                </div>
                <?php endif; ?>

                <!-- Add-link form -->
                <form method="post" action="<?= url('admin/scraper.php') ?>" class="row g-2 align-items-end mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_link">
                    <div class="col-md-4">
                        <label class="form-label mb-1" style="font-size:.78rem;">Product</label>
                        <select name="product_id" class="form-select form-select-sm" required>
                            <option value="">Select product…</option>
                            <?php foreach ($allProducts as $p): ?>
                            <option value="<?= (int) $p['id'] ?>">
                                <?= e(($p['brand'] ? $p['brand'] . ' ' : '') . $p['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1" style="font-size:.78rem;">Store</label>
                        <select name="store_id" class="form-select form-select-sm" required>
                            <option value="">Select store…</option>
                            <?php foreach ($allStores as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label mb-1" style="font-size:.78rem;">Product URL</label>
                        <input type="url" name="product_url" class="form-control form-control-sm"
                            placeholder="https://…" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </form>

                <!-- Table -->
                <div class="scraper-table-wrap">
                    <table class="admin-table">
                        <thead style="position:sticky;top:0;z-index:10;">
                            <tr>
                                <th>Product</th>
                                <th>Store</th>
                                <th>URL</th>
                                <th>Status</th>
                                <th>Last scraped</th>
                                <th>Enabled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($links as $l): ?>
                            <tr>
                                <td>
                                    <div class="fw-600" style="font-size:.82rem;">
                                        <?= e(mb_strimwidth(($l['brand'] ? $l['brand'] . ' ' : '') . $l['product_name'], 0, 30, '…')) ?>
                                    </div>
                                </td>
                                <td style="font-size:.82rem;"><?= e($l['store_name']) ?></td>
                                <td>
                                    <a href="<?= e($l['product_url']) ?>" target="_blank" class="url-cell d-block"
                                        title="<?= e($l['product_url']) ?>">
                                        <?= e(parse_url($l['product_url'], PHP_URL_HOST) . '…') ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                        $st = $l['last_status'] ?? '-';
                                        $badgeClass = match ($st) {
                                            'ok' => 'bg-success',
                                            'error' => 'bg-danger',
                                            'manual' => 'bg-info',
                                            'skip' => 'bg-warning text-dark',
                                            default => 'bg-secondary',
                                        };
                                        ?>
                                    <span class="badge <?= $badgeClass ?>"><?= e($st) ?></span>
                                    <?php if (!empty($l['last_error'])): ?>
                                    <div class="text-danger" style="font-size:.72rem;">
                                        <?= e(mb_strimwidth($l['last_error'], 0, 40, '…')) ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted" style="font-size:.78rem;">
                                    <?= $l['last_scraped_at'] ? e(date('d M H:i', strtotime($l['last_scraped_at']))) : '—' ?>
                                </td>
                                <td>
                                    <form method="post" action="<?= url('admin/scraper.php') ?>" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                        <input type="hidden" name="enabled"
                                            value="<?= (int) ($l['auto_enabled'] ? 0 : 1) ?>">
                                        <button type="submit"
                                            class="btn btn-sm <?= $l['auto_enabled'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                                            <?= $l['auto_enabled'] ? '<i class="bi bi-check-circle me-1"></i>On' : '<i class="bi bi-circle me-1"></i>Off' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($links)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No links yet. Add one above.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div><!-- /table-wrap -->

            </div><!-- /form-card-body -->
        </div><!-- /scraper-links-card -->
    </div><!-- /right col -->

</div><!-- /scraper-layout -->

<?php require_once __DIR__ . '/footer.php'; ?>