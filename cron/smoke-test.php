<?php

/**
 * Basic smoke test for compare.lk
 *
 * Usage:
 *   php cron/smoke-test.php
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/product_importer.php';

$results = [];

function addResult(array &$results, string $name, bool $ok, string $detail = ''): void
{
    $results[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

try {
    $pdo = getDB();
    $pdo->query('SELECT 1')->fetchColumn();
    addResult($results, 'db_connection', true);
} catch (Throwable $e) {
    addResult($results, 'db_connection', false, $e->getMessage());
}

try {
    $cats = getCategories();
    addResult($results, 'get_categories', is_array($cats), 'count=' . count($cats));
} catch (Throwable $e) {
    addResult($results, 'get_categories', false, $e->getMessage());
}

try {
    $latest = getLatestProducts(3);
    addResult($results, 'get_latest_products', is_array($latest), 'count=' . count($latest));
} catch (Throwable $e) {
    addResult($results, 'get_latest_products', false, $e->getMessage());
}

try {
    ensureScraperTables(getDB());
    addResult($results, 'ensure_scraper_tables', true);
} catch (Throwable $e) {
    addResult($results, 'ensure_scraper_tables', false, $e->getMessage());
}

try {
    ensureImportSourcesTable(getDB());
    addResult($results, 'ensure_import_sources_table', true);
} catch (Throwable $e) {
    addResult($results, 'ensure_import_sources_table', false, $e->getMessage());
}

$failed = 0;
foreach ($results as $row) {
    $status = $row['ok'] ? 'PASS' : 'FAIL';
    if (!$row['ok']) {
        $failed++;
    }
    echo sprintf('[%s] %s', $status, $row['name']);
    if ($row['detail'] !== '') {
        echo ' - ' . $row['detail'];
    }
    echo PHP_EOL;
}

echo PHP_EOL;
echo $failed === 0 ? "Smoke test OK\n" : "Smoke test FAILED: {$failed} check(s)\n";

exit($failed === 0 ? 0 : 1);
