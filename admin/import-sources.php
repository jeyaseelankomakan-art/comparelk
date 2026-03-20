<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/product_importer.php';

ensureSessionStarted();
requireAdminLogin();

$pdo = getDB();
ensureImportSourcesTable($pdo);

$msg = '';
$error = '';

$availableParsers = [
    'GenericCategoryParser'  => 'Generic (JSON-LD)',
    'KaprukaCategoryParser'  => 'Kapruka.com',
    'SingerParser'           => 'Singer Sri Lanka',
    'DarazParser'            => 'Daraz.lk',
    'BuyabansParser'         => 'BuyAbans.com (buyabans.com)',
    'SoftlogicParser'        => 'mysoftlogic.lk',
];

// BuyAbans note: use the AJAX API URL directly for best results, e.g.:
// https://buyabans.com/product-list?category_id=15&stamp_banner_id=0&sort=new_arrivals&is_search_list=false
// category_id=15 → Mobile Phones

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $storeId    = (int) ($_POST['store_id'] ?? 0);
            $catUrl     = trim($_POST['category_url'] ?? '');
            $parser     = trim($_POST['parser_class'] ?? 'GenericCategoryParser');
            $targetCat  = (int) ($_POST['target_category_id'] ?? 0) ?: null;

            if (!$storeId || !$catUrl) {
                $error = 'Please select a store and enter a category URL.';
            } elseif (!preg_match('#^https?://#i', $catUrl)) {
                $error = 'Please enter a valid URL starting with http:// or https://';
            } elseif (!array_key_exists($parser, $availableParsers)) {
                $error = 'Invalid parser class selected.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO store_import_urls (store_id, category_url, parser_class, target_category_id)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$storeId, $catUrl, $parser, $targetCat]);
                $msg = 'Import source added successfully!';
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $pdo->prepare("DELETE FROM store_import_urls WHERE id = ?")->execute([$id]);
            $msg = 'Import source deleted.';
        } elseif ($action === 'toggle' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $enabled = (int) ($_POST['enabled'] ?? 0);
            $pdo->prepare("UPDATE store_import_urls SET enabled = ? WHERE id = ?")->execute([$enabled ? 1 : 0, $id]);
            $msg = 'Import source ' . ($enabled ? 'enabled' : 'disabled') . '.';
        } elseif ($action === 'run_one' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $src = $pdo->prepare("SELECT * FROM store_import_urls WHERE id = ?");
            $src->execute([$id]);
            $source = $src->fetch();

            if ($source) {
                try {
                    $categoryId = !empty($source['target_category_id']) ? (int) $source['target_category_id'] : null;
                    $result = scrapeCategoryPage($pdo, $source['store_id'], $source['category_url'], $source['parser_class'], $categoryId);
                    $resultJson = json_encode($result);
                    $pdo->prepare("UPDATE store_import_urls SET last_run = NOW(), last_result = ? WHERE id = ?")
                        ->execute([$resultJson, $id]);
                    $msg = "Scrape complete! Status: {$result['status']} — Processed: {$result['processed_count']} products.";
                    if (!empty($result['message'])) {
                        $msg .= " ({$result['message']})";
                    }
                } catch (Exception $e) {
                    $error = 'Scrape error: ' . $e->getMessage();
                    $pdo->prepare("UPDATE store_import_urls SET last_run = NOW(), last_result = ? WHERE id = ?")
                        ->execute([json_encode(['status' => 'error', 'message' => $e->getMessage()]), $id]);
                }
            } else {
                $error = 'Source not found.';
            }
        } elseif ($action === 'run_all') {
            $stmtAll = $pdo->query("SELECT * FROM store_import_urls WHERE enabled = 1 ORDER BY last_run ASC LIMIT 20");
            $sources = $stmtAll->fetchAll();
            $ok = 0;
            $fail = 0;
            $totalProcessed = 0;

            foreach ($sources as $source) {
                try {
                    $categoryId = !empty($source['target_category_id']) ? (int) $source['target_category_id'] : null;
                    $result = scrapeCategoryPage($pdo, $source['store_id'], $source['category_url'], $source['parser_class'], $categoryId);
                    $pdo->prepare("UPDATE store_import_urls SET last_run = NOW(), last_result = ? WHERE id = ?")
                        ->execute([json_encode($result), $source['id']]);
                    $totalProcessed += $result['processed_count'];
                    if ($result['status'] === 'ok') $ok++;
                    else $fail++;
                } catch (Exception $e) {
                    $fail++;
                    $pdo->prepare("UPDATE store_import_urls SET last_run = NOW(), last_result = ? WHERE id = ?")
                        ->execute([json_encode(['status' => 'error', 'message' => $e->getMessage()]), $source['id']]);
                }
                usleep(300000);
            }
            $msg = "Import complete! Sources OK: {$ok}, Failed: {$fail}. Total products processed: {$totalProcessed}";
        }

        if ($msg || $error) {
            $_SESSION['import_msg'] = $msg;
            $_SESSION['import_error'] = $error;
            redirect('admin/import-sources.php');
        }
    }
}

$msg   = $_SESSION['import_msg'] ?? '';
$error = $_SESSION['import_error'] ?? '';
unset($_SESSION['import_msg'], $_SESSION['import_error']);

$allStores     = $pdo->query("SELECT id, name FROM stores ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$allCategories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$sources       = $pdo->query("
    SELECT iu.*, s.name AS store_name, c.name AS category_name
    FROM store_import_urls iu
    JOIN stores s ON iu.store_id = s.id
    LEFT JOIN categories c ON iu.target_category_id = c.id
    ORDER BY s.name, iu.id
")->fetchAll(PDO::FETCH_ASSOC);
$adminTitle = 'Import Sources';
require_once __DIR__ . '/header.php';
?>

<style>
    .import-layout {
        display: flex;
        gap: 1.25rem;
        min-height: 480px;
    }

    .import-col-left {
        flex: 0 0 380px;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .import-col-right {
        flex: 1 1 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .import-sources-card {
        flex: 1 1 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .import-sources-card .form-card-body {
        flex: 1 1 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .import-table-wrap {
        flex: 1 1 0;
        overflow-y: auto;
    }

    .url-cell-import {
        max-width: 280px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: .78rem;
    }

    .result-badge {
        font-size: .7rem;
        padding: .15rem .45rem;
    }

    .parser-badge {
        font-size: .68rem;
        padding: .1rem .4rem;
        border-radius: 4px;
        background: rgba(99, 102, 241, .1);
        color: #6366f1;
        font-weight: 600;
    }

    [data-theme="dark"] .parser-badge {
        background: rgba(99, 102, 241, .2);
        color: #a5b4fc;
    }

    @media (max-width: 991px) {
        .import-layout {
            flex-direction: column;
        }

        .import-col-left {
            flex: unset;
        }

        .import-col-right {
            flex: unset;
        }

        .import-sources-card {
            max-height: 500px;
        }
    }

    .fw-600 {
        font-weight: 600;
    }

    .pending-alert {
        background: linear-gradient(135deg, rgba(246, 166, 35, .12), rgba(246, 166, 35, .04));
        border: 1px solid rgba(246, 166, 35, .3);
        border-radius: .75rem;
        padding: .75rem 1rem;
        display: flex;
        align-items: center;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    .pending-alert i {
        font-size: 1.2rem;
        color: #f6a623;
    }
</style>


<div class="import-layout">

    <!-- ───── LEFT: Add Source Form ───── -->
    <div class="import-col-left">

        <div class="form-card">
            <div class="form-card-header">
                <h5><i class="bi bi-plus-circle me-2 text-primary"></i>Add Import Source</h5>
            </div>
            <div class="form-card-body">
                <p class="text-muted small mb-3">
                    Add a store category page URL. The importer will scrape it to discover new products automatically.
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div>
                <?php endif; ?>
                <?php if ($msg): ?>
                    <div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i><?= e($msg) ?></div>
                <?php endif; ?>

                <form method="post" action="<?= url('admin/import-sources.php') ?>"
                    onsubmit="const b=this.querySelector('button[type=submit]'); b.innerHTML='<i class=\'bi bi-arrow-repeat spin me-1\'></i>Adding...'; b.disabled=true;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label class="form-label mb-1" style="font-size:.82rem;">Store <span class="text-danger">*</span></label>
                        <select name="store_id" class="form-select form-select-sm" required>
                            <option value="">Select store…</option>
                            <?php foreach ($allStores as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label mb-1" style="font-size:.82rem;">Category Page URL <span class="text-danger">*</span></label>
                        <input type="url" name="category_url" class="form-control form-control-sm"
                            placeholder="https://www.singersl.com/products?category=-@243" required>
                        <div class="form-text" style="font-size:.72rem;">
                            The URL of a store's product listing / category page.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label mb-1" style="font-size:.82rem;">Parser</label>
                        <select name="parser_class" class="form-select form-select-sm">
                            <?php foreach ($availableParsers as $cls => $lbl): ?>
                                <option value="<?= e($cls) ?>"><?= e($lbl) ?> (<?= e($cls) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" style="font-size:.72rem;">
                            Use "Generic (JSON-LD)" for most stores. Use a specific parser for sites like Singer or Daraz.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label mb-1" style="font-size:.82rem;">Target Category (optional)</label>
                        <select name="target_category_id" class="form-select form-select-sm">
                            <option value="">— Auto-detect / None —</option>
                            <?php foreach ($allCategories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" style="font-size:.72rem;">
                            Optionally assign all scraped products from this URL to a category.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-plus-lg me-1"></i>Add Import Source
                    </button>
                </form>
            </div>
        </div>

        <!-- How it works -->
        <div class="form-card">
            <div class="form-card-header">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2 text-primary"></i>How it works</h6>
            </div>
            <div class="form-card-body">
                <ol class="small text-muted mb-0" style="padding-left:1.1rem; line-height:1.8;">
                    <li>Add store category page URLs here</li>
                    <li>Click <strong>"Run"</strong> or set up a cron job</li>
                    <li>Products are auto-extracted from the source directly</li>
                    <li>They are instantly added to the live <a href="<?= url('admin/products.php') ?>">Products</a> list automatically</li>
                </ol>
                <hr class="my-2">
                <p class="small text-muted mb-0">
                    <strong>Cron command:</strong><br>
                    <code style="font-size:.72rem;">php <?= realpath(__DIR__ . '/../cron/import-products.php') ?: 'cron/import-products.php' ?></code>
                </p>
            </div>
        </div>

    </div><!-- /left col -->

    <!-- ───── RIGHT: Sources Table ───── -->
    <div class="import-col-right">
        <div class="form-card import-sources-card">

            <!-- Header -->
            <div class="form-card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0">
                    <i class="bi bi-collection me-2 text-primary"></i>Import Sources
                    <span class="badge bg-secondary ms-1" style="font-size:.7rem;"><?= count($sources) ?></span>
                </h6>
                <form method="post" action="<?= url('admin/import-sources.php') ?>" class="m-0"
                    onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').innerHTML='<i class=\'bi bi-arrow-repeat spin me-1\'></i>Running…';">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="run_all">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-play-fill me-1"></i>Run all now
                    </button>
                </form>
            </div>

            <div class="form-card-body">
                <div class="import-table-wrap">
                    <table class="admin-table">
                        <thead style="position:sticky;top:0;z-index:10;">
                            <tr>
                                <th>Store</th>
                                <th>Category URL</th>
                                <th>Parser</th>
                                <th>Category</th>
                                <th>Last Run</th>
                                <th>Result</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sources as $src): ?>
                                <?php
                                $lastResult = $src['last_result'] ? json_decode($src['last_result'], true) : null;
                                $resultStatus = $lastResult['status'] ?? null;
                                if ($resultStatus === 'ok') {
                                    $resultBadge = 'bg-success';
                                } elseif ($resultStatus === 'error') {
                                    $resultBadge = 'bg-danger';
                                } else {
                                    $resultBadge = 'bg-secondary';
                                }
                                ?>
                                <tr style="<?= !($src['enabled'] ?? 1) ? 'opacity:.5;' : '' ?>">
                                    <td class="fw-600" style="font-size:.82rem;"><?= e($src['store_name']) ?></td>
                                    <td>
                                        <a href="<?= e($src['category_url']) ?>" target="_blank"
                                            class="url-cell-import d-block text-decoration-none"
                                            title="<?= e($src['category_url']) ?>">
                                            <?= e(mb_strimwidth($src['category_url'], 0, 55, '…')) ?>
                                        </a>
                                    </td>
                                    <td><span class="parser-badge"><?= e($availableParsers[$src['parser_class']] ?? $src['parser_class']) ?></span></td>
                                    <td style="font-size:.82rem;"><?= $src['category_name'] ? e($src['category_name']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-muted" style="font-size:.78rem;">
                                        <?= $src['last_run'] ? e(date('d M H:i', strtotime($src['last_run']))) : '—' ?>
                                    </td>
                                    <td>
                                        <?php if ($lastResult): ?>
                                            <span class="badge <?= $resultBadge ?> result-badge"><?= e($resultStatus) ?></span>
                                            <?php if (isset($lastResult['processed_count'])): ?>
                                                <small class="text-muted ms-1"><?= (int)$lastResult['processed_count'] ?> items</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:.75rem;">Never run</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" style="white-space:nowrap;">
                                        <!-- Run one -->
                                        <form method="post" action="<?= url('admin/import-sources.php') ?>" class="d-inline"
                                            onsubmit="const b=this.querySelector('button[type=submit]'); b.innerHTML='<i class=\'bi bi-arrow-repeat spin\'></i>'; b.disabled=true;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="run_one">
                                            <input type="hidden" name="id" value="<?= (int)$src['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Run now">
                                                <i class="bi bi-play-fill"></i>
                                            </button>
                                        </form>

                                        <!-- Toggle -->
                                        <form method="post" action="<?= url('admin/import-sources.php') ?>" class="d-inline"
                                            onsubmit="const b=this.querySelector('button[type=submit]'); b.innerHTML='<i class=\'bi bi-arrow-repeat spin\'></i>'; b.disabled=true;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$src['id'] ?>">
                                            <input type="hidden" name="enabled" value="<?= ($src['enabled'] ?? 1) ? 0 : 1 ?>">
                                            <button type="submit" class="btn btn-sm <?= ($src['enabled'] ?? 1) ? 'btn-success' : 'btn-outline-secondary' ?>" title="<?= ($src['enabled'] ?? 1) ? 'Disable' : 'Enable' ?>">
                                                <i class="bi <?= ($src['enabled'] ?? 1) ? 'bi-check-circle' : 'bi-circle' ?>"></i>
                                            </button>
                                        </form>

                                        <!-- Delete -->
                                        <form method="post" action="<?= url('admin/import-sources.php') ?>" class="d-inline"
                                            onsubmit="if(confirm('Delete this import source?')) { const b=this.querySelector('button[type=submit]'); b.innerHTML='<i class=\'bi bi-arrow-repeat spin\'></i>'; b.disabled=true; return true; } return false;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$src['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($sources)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i>
                                        No import sources configured yet. Add one using the form on the left.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div><!-- /table-wrap -->
            </div><!-- /form-card-body -->
        </div><!-- /import-sources-card -->
    </div><!-- /right col -->

</div><!-- /import-layout -->

<style>
    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .spin {
        animation: spin .6s linear infinite;
        display: inline-block;
    }
</style>

<?php require_once __DIR__ . '/footer.php'; ?>