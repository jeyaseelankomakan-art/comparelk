<?php
/**
 * Auto price scraper helpers - compare.lk
 *
 * This file defines:
 * - ensureScraperTables(PDO $pdo)
 * - extractPriceFromHtml(string $html): ?float
 * - extractOriginalPriceFromHtml(string $html, float $actualPrice): ?float
 * - scrapeProductStoreLink(PDO $pdo, array $link): array
 *
 * It is used by:
 * - cron/auto-scrape.php (CLI / cron)
 * - admin/scraper.php (Run now from admin)
 */

require_once __DIR__ . '/db.php';

/**
 * Create scraper tables if they do not exist.
 */
function ensureScraperTables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_store_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            store_id INT NOT NULL,
            product_url VARCHAR(500) NOT NULL,
            auto_enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_price DECIMAL(12,2) DEFAULT NULL,
            last_status VARCHAR(20) DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            last_scraped_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_product_store (product_id, store_id),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
}

/**
 * Extract a numeric price from HTML using multiple strategies in priority order.
 *
 * KEY FIX: Previous version used preg_match (first match only) for JSON-LD which
 * would pick a card-offer / coupon price like Rs. 500 if it appeared before the
 * real product price of Rs. 42,999 in the page source.  Now we collect ALL
 * matching values and return the LARGEST plausible one.
 *
 * @return float|null  Price in LKR or null if no pattern matched.
 */
function extractPriceFromHtml(string $html): ?float {

    // ── Strategy 1: JSON-LD "price" (most reliable) ───────────────────────────
    //
    // Collect EVERY "price" occurrence in JSON-LD / JSON objects, then return
    // the highest value above 100. This ensures that a small bank-offer amount
    // (e.g. "price":500 for a card discount) never wins over the actual product
    // price (e.g. "price":"42999").
    $jsonLdCandidates = [];

    // Quoted string form: "price":"42999.00"
    if (preg_match_all('/"price"\s*:\s*"([\d,]+(?:\.\d{1,2})?)"/', $html, $m)) {
        foreach ($m[1] as $v) {
            $val = (float) str_replace(',', '', $v);
            if ($val > 100) $jsonLdCandidates[] = $val;
        }
    }
    // Unquoted numeric form: "price":42999
    if (preg_match_all('/"price"\s*:\s*(\d+(?:\.\d{1,2})?)/', $html, $m)) {
        foreach ($m[1] as $v) {
            $val = (float) $v;
            if ($val > 100) $jsonLdCandidates[] = $val;
        }
    }
    if (!empty($jsonLdCandidates)) {
        // Largest value wins: product prices are always greater than coupon/offer amounts.
        return max($jsonLdCandidates);
    }

    // ── Strategy 2: OpenGraph / meta price tags ───────────────────────────────
    $metaPatterns = [
        '/<meta[^>]+(?:property|name)=["\']?(?:og:price:amount|product:price:amount)["\']?[^>]+content=["\']?([\d.,]+)["\']?/i',
        '/<meta[^>]+content=["\']?([\d.,]+)["\']?[^>]+(?:property|name)=["\']?(?:og:price:amount|product:price:amount)["\']?/i',
    ];
    foreach ($metaPatterns as $pat) {
        if (preg_match($pat, $html, $m)) {
            $num = (float) str_replace([',', ' '], '', $m[1]);
            if ($num > 100) return $num;
        }
    }

    // ── Strategy 3: HTML data-price / data-amount attributes ─────────────────
    // e.g. <span data-price="42999"> <div data-salePrice="36999">
    if (preg_match_all('/\bdata-(?:price|amount|salePrice|sale_price|finalPrice|final_price)\s*=\s*["\']?([\d,]+(?:\.\d{1,2})?)["\']?/i', $html, $m)) {
        $candidates = [];
        foreach ($m[1] as $v) {
            $val = (float) str_replace(',', '', $v);
            if ($val > 100) $candidates[] = $val;
        }
        if (!empty($candidates)) return max($candidates);
    }

    // ── Strategy 4: Generic JS/JSON "price" key ───────────────────────────────
    // Broader pattern that catches single-quoted keys and inline JS objects.
    if (preg_match_all('/["\']price["\']\s*:\s*["\']?([\d,]+(?:\.\d{1,2})?)["\']?/i', $html, $matches)) {
        $candidates = array_map(fn($v) => (float) str_replace(',', '', $v), $matches[1]);
        $candidates = array_filter($candidates, fn($v) => $v > 100);
        if (!empty($candidates)) {
            // Most-frequent price wins (product price usually appears several times)
            $freq = array_count_values(array_map('strval', $candidates));
            arsort($freq);
            $topFreq = reset($freq);
            $topVal  = (float) array_key_first($freq);
            return ($topFreq > 1) ? $topVal : max($candidates);
        }
    }

    // ── Strategy 5: Rs./LKR text prices ──────────────────────────────────────
    // Use a threshold of 1 000 to skip delivery fees, coupon amounts, etc.
    if (preg_match_all('/\b(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $matches)) {
        $candidates = [];
        foreach ($matches[1] as $raw) {
            $num = (float) str_replace([',', ' '], '', $raw);
            if ($num > 1000) {
                $candidates[] = $num;
            }
        }
        if (!empty($candidates)) {
            $freq     = array_count_values(array_map('strval', $candidates));
            arsort($freq);
            $topFreq  = reset($freq);
            if ($topFreq > 1) {
                return (float) array_key_first($freq);
            }
            return max($candidates);
        }
    }

    return null;
}

/**
 * Extract the original / was-price from HTML (before discount).
 * Returns price as float or null if not found.
 */
function extractOriginalPriceFromHtml(string $html, float $actualPrice): ?float {
    // JSON-LD: look for "originalPrice", "regularPrice", "listPrice", "fullPrice"
    $keys = ['originalPrice', 'regularPrice', 'listPrice', 'fullPrice', 'compareAtPrice', 'mrp_price'];
    foreach ($keys as $key) {
        if (preg_match('/"' . $key . '"\s*:\s*"?([\d.,]+)"?/i', $html, $m)) {
            $val = (float) str_replace(',', '', $m[1]);
            if ($val > $actualPrice) return $val;
        }
    }

    // Singer-style: two JSON-LD prices where the larger one is the original
    if (preg_match_all('/"price"\s*:\s*"?([\d.]+)"?/', $html, $m)) {
        $prices = array_map('floatval', $m[1]);
        $prices = array_filter($prices, fn($v) => $v > $actualPrice);
        if (!empty($prices)) return max($prices);
    }

    // HTML strikethrough patterns: <del>, <s>, .was-price, .original-price etc.
    if (preg_match('/<(?:del|s|strike)[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)\s*<\/(?:del|s|strike)>/i', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }
    // Daraz-style: class containing "origin" or "del" price
    if (preg_match('/class=["\'][^"\']*(?:origin|original|old|was|del|strike|crossed|mrp)[^"\']*["\'][^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }
    if (preg_match('/(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)[^<]*<\/[^>]+>\s*[^<]*class=["\'][^"\']*(?:origin|original|old|was|del|strike|crossed|mrp)[^"\']*["\']/i', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }

    return null;
}

/**
 * Scrape and update prices for a single product_store_links row.
 *
 * @param PDO   $pdo
 * @param array $link Row from product_store_links (associative).
 * @return array ['status' => 'ok|no_price|error', 'message' => string, 'price' => ?float]
 */
function scrapeProductStoreLink(PDO $pdo, array $link): array {
    $url = $link['product_url'];
    $result = ['status' => 'error', 'message' => '', 'price' => null];

    if (!preg_match('#^https?://#i', $url)) {
        $result['message'] = 'Invalid URL';
        return $result;
    }

    $context = stream_context_create([
        'http' => [
            'timeout'         => 15,
            'user_agent'      => 'compare.lk/1.0 (Price comparison; auto-scraper)',
            'follow_location' => 1,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $html = @file_get_contents($url, false, $context);
    if ($html === false) {
        $result['message'] = 'Fetch failed';
        return $result;
    }

    $price = extractPriceFromHtml($html);
    if ($price === null) {
        $result['status']  = 'no_price';
        $result['message'] = 'No price pattern found';
        return $result;
    }

    // Try to extract original (before-discount) price
    $originalPrice = extractOriginalPriceFromHtml($html, $price);

    $productId = (int) $link['product_id'];
    $storeId   = (int) $link['store_id'];

    try {
        $pdo->beginTransaction();

        // Read current price if any
        $stmt = $pdo->prepare("SELECT id, price FROM product_prices WHERE product_id = ? AND store_id = ? LIMIT 1");
        $stmt->execute([$productId, $storeId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $oldPrice = (float) $existing['price'];
            if (abs($oldPrice - $price) > 0.009 || $originalPrice !== null) {
                // Log history only when actual price changed
                if (abs($oldPrice - $price) > 0.009) {
                    $pdo->prepare("INSERT INTO price_history (product_id, store_id, price) VALUES (?, ?, ?)")
                        ->execute([$productId, $storeId, $price]);
                }
                $pdo->prepare("
                    UPDATE product_prices
                    SET price = ?, original_price = ?, stock_status = 'in_stock', last_updated = NOW()
                    WHERE id = ?
                ")->execute([$price, $originalPrice, $existing['id']]);
            }
        } else {
            // Insert new record + history
            $pdo->prepare("
                INSERT INTO product_prices (product_id, store_id, price, original_price, product_url, stock_status, last_updated)
                VALUES (?, ?, ?, ?, ?, 'in_stock', NOW())
            ")->execute([$productId, $storeId, $price, $originalPrice, $url]);
            $pdo->prepare("INSERT INTO price_history (product_id, store_id, price) VALUES (?, ?, ?)")
                ->execute([$productId, $storeId, $price]);
        }

        // Update helper table state
        $pdo->prepare("
            UPDATE product_store_links
            SET last_price = ?, last_status = 'ok', last_error = NULL, last_scraped_at = NOW()
            WHERE id = ?
        ")->execute([$price, $link['id']]);

        $pdo->commit();
        $result['status']         = 'ok';
        $result['message']        = 'Price scraped';
        $result['price']          = $price;
        $result['original_price'] = $originalPrice;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->prepare("
            UPDATE product_store_links
            SET last_status = 'error', last_error = ?, last_scraped_at = NOW()
            WHERE id = ?
        ")->execute([substr($e->getMessage(), 0, 500), $link['id']]);

        $result['status']  = 'error';
        $result['message'] = 'DB error';
    }

    return $result;
}
