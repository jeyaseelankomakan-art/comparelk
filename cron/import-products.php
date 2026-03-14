<?php
/**
 * Cron Job: Auto Import Products from Store Categories - compare.lk
 * Usage: php cron/import-products.php
 * Or set up via cPanel cron / Windows Task Scheduler
 */

$isCli = (php_sapi_name() === 'cli');

// Block direct browser access by strangers — require admin session or CLI
if (!$isCli) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/functions.php';
    ensureSessionStarted();
    if (!isAdminLoggedIn()) {
        http_response_code(403);
        exit('Access denied.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/product_importer.php';

set_time_limit(300); // Allow up to 5 minutes for scraping

$pdo = getDB();

// Ensure the import sources table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS store_import_urls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        store_id INT UNSIGNED NOT NULL,
        category_url VARCHAR(500) NOT NULL,
        parser_class VARCHAR(100) DEFAULT 'GenericCategoryParser',
        target_category_id INT UNSIGNED DEFAULT NULL,
        last_run DATETIME DEFAULT NULL,
        FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
    ) ENGINE=InnoDB
");

$log = [];
$log[] = '[' . date('Y-m-d H:i:s') . '] Importer started.';

$stmt = $pdo->query("SELECT * FROM store_import_urls ORDER BY last_run ASC LIMIT 10");
$sources = $stmt->fetchAll();

if (empty($sources)) {
    $log[] = 'No category import URLs configured in `store_import_urls`.';
    $log[] = 'Add store category URLs via the database or admin to start importing.';
    echo implode("\n", $log) . "\n";
    exit(0);
}

$totalProcessed = 0;
$totalErrors    = 0;

foreach ($sources as $source) {
    $log[] = "Scraping: {$source['category_url']} (Parser: {$source['parser_class']})";

    try {
        $result = scrapeCategoryPage($pdo, $source['store_id'], $source['category_url'], $source['parser_class']);

        $log[] = "  -> Status: {$result['status']} | Processed: {$result['processed_count']}";
        if (!empty($result['message'])) {
            $log[] = "  -> Message: {$result['message']}";
        }

        $totalProcessed += $result['processed_count'];

        $pdo->prepare("UPDATE store_import_urls SET last_run = NOW() WHERE id = ?")
            ->execute([$source['id']]);

    } catch (Exception $e) {
        $log[] = "  -> ERROR: " . $e->getMessage();
        $totalErrors++;
    }
}

$log[] = '';
$log[] = '[' . date('Y-m-d H:i:s') . "] Done. Total processed: {$totalProcessed} | Errors: {$totalErrors}";

echo implode("\n", $log) . "\n";
