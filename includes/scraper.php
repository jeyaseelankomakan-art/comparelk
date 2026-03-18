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

    // ── Strategy 0: Store-specific Exact Selectors ─────────────────────────────
    
    // Daraz: pdp-price or pdt_price JS variable
    if (preg_match('/"pdt_price"\s*:\s*"([\d,]+(?:\.\d{1,2})?)"/is', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }
    if (preg_match('/class="[^"]*pdp-price_type_normal[^"]*"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }

    // Softlogic: main-price OR discounted-price
    if (preg_match('/class="[^"]*discounted-price[^"]*"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }
    if (preg_match('/id="product-promotion-price"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }
    if (preg_match('/id="product-price"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }
    if (preg_match('/class="main-price"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }

    // Singer: special-price
    if (preg_match('/class="special-price"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }
    
    // BuyAbans: sale-price or just price (excluding was-price)
    if (preg_match('/class="[^"]*(?:sale-price|current-price)[^"]*"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }

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
        // Lowest value wins: JSON-LD typically lists the current selling price as the
        // lowest 'price' entry (the higher values are original/was-prices or delivery costs).
        return min($jsonLdCandidates);
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
    if (preg_match_all('/["\'](?:price|salePrice|sellingPrice)["\']\s*:\s*["\']?([\d,]+(?:\.\d{1,2})?)["\']?/i', $html, $matches)) {
        $candidates = array_map(fn($v) => (float) str_replace(',', '', $v), $matches[1]);
        $candidates = array_filter($candidates, fn($v) => $v > 100);
        if (!empty($candidates)) {
            // Most-frequent price wins, BUT if we only have 1 occurrence, assume MIN price is the current price (not max)
            $freq = array_count_values(array_map('strval', $candidates));
            arsort($freq);
            $topFreq = reset($freq);
            $topVal  = (float) array_key_first($freq);
            return ($topFreq > 1) ? $topVal : min($candidates);
        }
    }

    // ── Strategy 5: Rs./LKR text prices ──────────────────────────────────────
    // Use a threshold of 1 000 to skip delivery fees, coupon amounts, etc.
    //
    // IMPORTANT: Many Sri Lankan store pages (especially BuyAbans) include bank
    // installment plan eligibility text like "Easy payment plans from Sampath Bank
    // apply to transactions over Rs 10,000". These Rs. amounts repeat once per bank
    // and can outnumber the actual product price on the page. We filter them out by
    // checking the surrounding context for installment/bank-related keywords.
    if (preg_match_all('/\b(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
        $candidates = [];
        // Keywords that indicate a price is part of bank/installment/BNPL plan text
        $installmentKeywords = [
            'payment plan', 'installment', 'transactions over', 'transactions between',
            'transactions from', 'emi', 'monthly', 'per month', 'easy payment',
            'applies to', 'apply to', 'eligible for', 'koko', 'buy now pay later',
            'split into', 'pay in',
        ];
        foreach ($matches[1] as $match) {
            $raw    = $match[0];
            $offset = $match[1];
            $num    = (float) str_replace([',', ' '], '', $raw);
            if ($num <= 1000) continue;

            // Grab ~200 chars before this match to check for installment-related context
            $contextStart  = max(0, $offset - 200);
            $contextBefore = strtolower(substr($html, $contextStart, $offset - $contextStart));
            // Also decode HTML entities in the context (BuyAbans encodes its JS strings)
            $contextBefore = html_entity_decode($contextBefore, ENT_QUOTES, 'UTF-8');

            $isInstallment = false;
            foreach ($installmentKeywords as $kw) {
                if (strpos($contextBefore, $kw) !== false) {
                    $isInstallment = true;
                    break;
                }
            }
            if ($isInstallment) continue;

            $candidates[] = $num;
        }
        if (!empty($candidates)) {
            // Use the FIRST candidate — on Sri Lankan product pages the current selling
            // price always appears at the top, before repeated strikethrough/was prices.
            // The frequency heuristic is unreliable because the original price often
            // appears multiple times (price display, meta tags, structured data).
            return $candidates[0];
        }
    }

    return null;
}

/**
 * Extract the original / was-price from HTML (before discount).
 * Returns price as float or null if not found.
 */
function extractOriginalPriceFromHtml(string $html, float $actualPrice): ?float {
    // Store-specific Exact Selectors
    // Daraz: old-price or originalPrice JSON
    if (preg_match('/"originalPrice"\s*:\s*"([\d,]+(?:\.\d{1,2})?)"/is', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }
    if (preg_match('/class="[^"]*pdp-price_type_deleted[^"]*"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }

    // Softlogic: main-price-del
    if (preg_match('/class="main-price-del"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }
    
    // Singer: old-price
    if (preg_match('/class="old-price"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }

    // BuyAbans: was-price
    if (preg_match('/class="was-price"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }

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
        if (!empty($prices)) return min($prices);
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

    // ── Fallback: Rs. text scan ─────────────────────────────────────────────
    // BuyAbans and similar sites show the original/was-price as Rs. X,XXX near
    // the sale price but use HTML-entity-encoded markup inside JS rather than
    // standard <del> tags. Scan all Rs. amounts, filter out installment/bank
    // plan amounts, and pick the smallest value above the sale price.
    if (preg_match_all('/\b(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
        $installmentKeywords = [
            'payment plan', 'installment', 'transactions over', 'transactions between',
            'transactions from', 'emi', 'monthly', 'per month', 'easy payment',
            'applies to', 'apply to', 'eligible for', 'koko', 'buy now pay later',
            'split into', 'pay in',
        ];
        $wasCandidates = [];
        foreach ($matches[1] as $match) {
            $raw    = $match[0];
            $offset = $match[1];
            $num    = (float) str_replace([',', ' '], '', $raw);

            // Only interested in amounts higher than the actual sale price
            if ($num <= $actualPrice) continue;

            // Filter out installment/bank plan threshold amounts
            $contextStart  = max(0, $offset - 200);
            $contextBefore = strtolower(substr($html, $contextStart, $offset - $contextStart));
            $contextBefore = html_entity_decode($contextBefore, ENT_QUOTES, 'UTF-8');

            $isInstallment = false;
            foreach ($installmentKeywords as $kw) {
                if (strpos($contextBefore, $kw) !== false) {
                    $isInstallment = true;
                    break;
                }
            }
            if ($isInstallment) continue;

            $wasCandidates[] = $num;
        }
        // Return the smallest value above sale price — that's the original/was price
        if (!empty($wasCandidates)) {
            return min($wasCandidates);
        }
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

    // Reset the PHP execution timer for each individual URL so one slow page
    // cannot exhaust the global max_execution_time for the entire scraper run.
    set_time_limit(60);

    // Use cURL instead of file_get_contents — stream context 'timeout' is unreliable
    // on Windows/WAMP and can allow a stalled connection to hang indefinitely.
    // CURLOPT_CONNECTTIMEOUT and CURLOPT_TIMEOUT are honoured rock-solidly.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,   // abort if we can't connect within 8 s
        CURLOPT_TIMEOUT        => 20,  // abort the whole transfer after 20 s
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_ENCODING       => '',  // handle gzip/deflate automatically
    ]);

    $html     = curl_exec($ch);

    // ── Bypass: Softlogic / Zenedge BOT challenge ────────────────────────────
    // If the HTML is very short and contains the __zjc cookie challenge
    if (strlen($html) < 2000 && strpos($html, '__zjc') !== false && preg_match('/var\s+v\s*=\s*([\d.]+)\s*\*\s*([\d.]+);/', $html, $m)) {
        $v1 = (float)$m[1];
        $v2 = (float)$m[2];
        $cookieVal = floor($v1 * $v2);
        $cookieName = 'zjc_session';
        if (preg_match('/__zjc(\d+)/', $html, $cm)) {
            $cookieName = '__zjc' . $cm[1];
        }

        // Apply cookie and re-fetch. Zenedge often requires hitting root or "/" first
        // to fully establish the session before the specific product URL works.
        $cookieHeader = "Cookie: $cookieName=$cookieVal";
        curl_setopt($ch, CURLOPT_HTTPHEADER, [$cookieHeader]);
        
        // Initial "activation" hit
        curl_setopt($ch, CURLOPT_URL, "https://mysoftlogic.lk/");
        curl_exec($ch);
        
        // Final hit back to product URL
        curl_setopt($ch, CURLOPT_URL, $url);
        $html = curl_exec($ch);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($html === false || !empty($curlErr)) {
        $result['message'] = 'Fetch failed: ' . $curlErr;
        return $result;
    }

    if ($httpCode >= 400) {
        $result['message'] = "HTTP $httpCode";
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
