<?php
/**
 * Automatic price scraper runner - compare.lk
 *
 * Usage (CLI):
 *   php cron/auto-scrape.php [limit]
 *
 * You can configure a scheduled task / cron to call this periodically.
 * - It reads mappings from product_store_links (created when you save prices in Admin > Prices).
 * - For each enabled mapping it fetches the product URL, extracts a price, and updates:
 *      - product_prices (current price)
 *      - price_history  (log)
 *      - product_store_links.* status fields
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraper.php';

$pdo = getDB();
ensureScraperTables($pdo);

// Limit how many links to scrape in a single run
$limit = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : 25;
$limit = max(1, $limit);

$stmt = $pdo->query("
    SELECT *
    FROM product_store_links
    WHERE auto_enabled = 1
    ORDER BY (last_scraped_at IS NULL) DESC, last_scraped_at ASC
    LIMIT {$limit}
");
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (PHP_SAPI === 'cli') {
    echo 'Auto-scrape run at ' . date('Y-m-d H:i:s') . PHP_EOL;
    echo 'Links to scrape: ' . count($links) . PHP_EOL;
}

foreach ($links as $link) {
    $res = scrapeProductStoreLink($pdo, $link);
    if (PHP_SAPI === 'cli') {
        echo sprintf(
            "- product_id=%d store_id=%d status=%s price=%s msg=%s\n",
            $link['product_id'],
            $link['store_id'],
            $res['status'],
            $res['price'] !== null ? number_format($res['price'], 2) : 'null',
            $res['message']
        );
    }
    // Be nice: tiny pause between requests
    usleep(200000); // 0.2s
}

if (PHP_SAPI === 'cli') {
    echo "Done.\n";
}

