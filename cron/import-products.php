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
ensureImportSourcesTable($pdo);

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
