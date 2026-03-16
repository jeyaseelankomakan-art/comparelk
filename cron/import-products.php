<?php

$isCli = (php_sapi_name() === 'cli');

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

set_time_limit(300); 

$pdo = getDB();

$pdo->exec("

    CREATE TABLE IF NOT EXISTS store_import_urls (

        id INT AUTO_INCREMENT PRIMARY KEY,

        store_id INT UNSIGNED NOT NULL,

        category_url VARCHAR(500) NOT NULL,

        parser_class VARCHAR(100) DEFAULT 'GenericCategoryParser',

        target_category_id INT UNSIGNED DEFAULT NULL,

        enabled TINYINT(1) NOT NULL DEFAULT 1,

        last_run DATETIME DEFAULT NULL,

        last_result TEXT DEFAULT NULL,

        FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE

    ) ENGINE=InnoDB

");

// Add missing columns to existing tables (safe to run even if columns already exist)
try { $pdo->exec("ALTER TABLE store_import_urls ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException $e) { /* column already exists */ }
try { $pdo->exec("ALTER TABLE store_import_urls ADD COLUMN last_result TEXT DEFAULT NULL"); } catch (PDOException $e) { /* column already exists */ }

$log = [];
$log[] = '[' . date('Y-m-d H:i:s') . '] Importer started.';

$stmt = $pdo->query("SELECT * FROM store_import_urls WHERE enabled = 1 ORDER BY last_run ASC LIMIT 10");

$sources = $stmt->fetchAll();

if (empty($sources)) {

    $log[] = 'No category import URLs configured in `store_import_urls`.';

    $log[] = 'Add store category URLs via Admin > Import Sources to start importing.';

    echo implode("\n", $log) . "\n";

    exit(0);

}

$totalProcessed = 0;

$totalErrors    = 0;

foreach ($sources as $source) {

    $log[] = "Scraping: {$source['category_url']} (Parser: {$source['parser_class']})";

    try {

        $categoryId = !empty($source['target_category_id']) ? (int) $source['target_category_id'] : null;

        $result = scrapeCategoryPage($pdo, $source['store_id'], $source['category_url'], $source['parser_class'], $categoryId);

        $log[] = "  -> Status: {$result['status']} | Processed: {$result['processed_count']}";

        if (!empty($result['message'])) {

            $log[] = "  -> Message: {$result['message']}";

        }

        $totalProcessed += $result['processed_count'];

        $pdo->prepare("UPDATE store_import_urls SET last_run = NOW(), last_result = ? WHERE id = ?")

            ->execute([json_encode($result), $source['id']]);

    } catch (Exception $e) {

        $log[] = "  -> ERROR: " . $e->getMessage();

        $totalErrors++;

        $pdo->prepare("UPDATE store_import_urls SET last_run = NOW(), last_result = ? WHERE id = ?")

            ->execute([json_encode(['status' => 'error', 'message' => $e->getMessage()]), $source['id']]);

    }

}

$log[] = '';

$log[] = '[' . date('Y-m-d H:i:s') . "] Done. Total processed: {$totalProcessed} | Errors: {$totalErrors}";

echo implode("\n", $log) . "\n";

