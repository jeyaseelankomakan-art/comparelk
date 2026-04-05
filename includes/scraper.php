<?php

/**
 * Auto price scraper helpers - compare.lk
 *
 * This file defines:
 * - ensureScraperTables(PDO $pdo)
 * - extractPriceFromHtml(string $html, string $sourceUrl): ?float
 * - extractOriginalPriceFromHtml(string $html, float $actualPrice, string $sourceUrl): ?float
 * - scrapeProductStoreLink(PDO $pdo, array $link): array
 *
 * It is used by:
 * - cron/auto-scrape.php (CLI / cron)
 * - admin/scraper.php (Run now from admin)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http_client.php';

/**
 * Create scraper tables if they do not exist.
 */
function ensureScraperTables(PDO $pdo): void
{
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
function extractPriceFromHtml(string $html, string $sourceUrl = ''): ?float
{

    // ── Strategy 0: Store-specific Exact Selectors ─────────────────────────────

    // Daraz: pdp-price or pdt_price JS variable
    if (preg_match('/"pdt_price"\s*:\s*"([\d,]+(?:\.\d{1,2})?)"/is', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }
    if (preg_match('/class="[^"]*pdp-price_type_normal[^"]*"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }

    // ── Softlogic: data-product JSON (most reliable) ──────────────────────────
    // Softlogic embeds product data as HTML-entity-encoded JSON inside
    // data-product="..." attributes. The JSON contains:
    //   - "promotionPrice" : current selling price
    //   - "basePrice"      : original / list price
    //   - "id"             : Softlogic product ID
    //
    // If the URL has /p/NNNNN, we validate that the product ID exists on the
    // page. If it doesn't, the page was likely redirected to the homepage and
    // any prices found would be for unrelated products (gift vouchers, etc.).
    $isSoftlogic = (stripos($sourceUrl, 'softlogic') !== false);

    if ($isSoftlogic) {
        // Extract product ID from URL  e.g. /p/135085
        $urlProductId = null;
        if (preg_match('#/p/(\d+)#', $sourceUrl, $um)) {
            $urlProductId = $um[1];
        }

        // Decode the HTML entities used in data-product attributes
        $decoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        // If we have a URL product ID, validate it exists in the page
        if ($urlProductId !== null) {
            $productExists = (
                strpos($decoded, '"id":' . $urlProductId) !== false ||
                strpos($decoded, '"id":"' . $urlProductId . '"') !== false ||
                strpos($decoded, '"productID":"' . $urlProductId . '"') !== false ||
                strpos($decoded, 'prod-icons_' . $urlProductId) !== false
            );
            if (!$productExists) {
                // Page does not contain this product — likely a redirect to homepage.
                // Return null to avoid extracting wrong prices from gift voucher cards.
                return null;
            }
        }

        // Try to find the specific product's data-product JSON block
        if ($urlProductId !== null) {
            // Look for the data-product JSON that contains this specific product ID
            if (preg_match('/data-product="([^"]*?"id":\s*' . preg_quote($urlProductId, '/') . '\b[^"]*?)"/i', $decoded, $dpMatch)) {
                $json = json_decode($dpMatch[1], true);
                if ($json) {
                    // promotionPrice is the current selling price
                    $promoPrice = $json['promotionPrice'] ?? null;
                    $basePrice  = $json['basePrice'] ?? null;
                    $price = ($promoPrice && $promoPrice > 100) ? (float) $promoPrice : (($basePrice && $basePrice > 100) ? (float) $basePrice : null);
                    if ($price) return $price;
                }
            }
        }

        // Fallback: find ALL data-product JSON blocks and pick the first valid one
        if (preg_match_all('/data-product="([^"]+)"/i', $decoded, $dpMatches)) {
            foreach ($dpMatches[1] as $dpRaw) {
                $json = json_decode($dpRaw, true);
                if (!$json || !isset($json['basePrice'])) continue;
                $promoPrice = $json['promotionPrice'] ?? null;
                $basePrice  = $json['basePrice'] ?? null;
                $price = ($promoPrice && $promoPrice > 100) ? (float) $promoPrice : (($basePrice && $basePrice > 100) ? (float) $basePrice : null);
                if ($price) return $price;
            }
        }

        // Legacy CSS-class selectors (kept as final Softlogic fallbacks)
        if (preg_match('/id="product-promotion-price"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        if (preg_match('/id="product-price"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        if (preg_match('/class="main-price"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        if (preg_match('/id="pd-info"[^>]*>.*?(?:class="product_price discount[^>]*>.*?<span[^>]*>|class="product_price[^>]*>)\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
    }

    // Singer: special-price
    $isSinger = (stripos($sourceUrl, 'singer') !== false);
    if ($isSinger && preg_match('/class="special-price"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }

    // BuyAbans: extract price_convert from the HTML-entity-encoded inline JSON.
    // BuyAbans embeds product data as JS inside large &quot;-encoded strings:
    //   &quot;price_convert&quot;:329999,&quot;special_price_convert&quot;:0,...
    // We HTML-decode once, then grab the FIRST non-zero price_convert value.
    $isAbans = (stripos($sourceUrl, 'buyabans') !== false);
    if ($isAbans) {
        $abDecoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        // special_price_convert is the SALE price when non-zero; price_convert is list price.
        // Walk all occurrences and return the first value > 1000 (avoids Rs. 0 and tiny sums).
        if (preg_match_all('/"(?:special_price_convert|price_convert)"\s*:\s*(\d+)/', $abDecoded, $abm)) {
            // Collect non-zero special prices first, then list prices
            $specials = [];
            $lists    = [];
            // Re-match with key name captured
            if (preg_match_all('/"(special_price_convert|price_convert)"\s*:\s*(\d+)/', $abDecoded, $abk)) {
                foreach ($abk[1] as $i => $key) {
                    $val = (int) $abk[2][$i];
                    if ($val <= 0) continue;
                    if ($key === 'special_price_convert') $specials[] = $val;
                    else                                   $lists[]    = $val;
                }
            }
            // Prefer special (sale) price; fall back to list price
            $abCandidates = array_merge($specials, $lists);
            if (!empty($abCandidates)) {
                // The first value is the primary product variant price
                return (float) $abCandidates[0];
            }
        }
    }

    // BuyAbans CSS fallback: sale-price or current-price element (must be > 0)
    if (preg_match('/class="[^"]*(?:sale-price|current-price|selling-price-de)[^"]*"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/i', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > 0) return $val;
    }

    // ── Strategy 1: JSON-LD "price" (most reliable) ───────────────────────────
    //
    // Parse structured JSON-LD objects explicitly to avoid mistakenly picking up
    // shipping costs or randomly named JS variables that happen to use "price".
    $jsonLdCandidates = [];

    if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $scriptMatches)) {
        foreach ($scriptMatches[1] as $jsonStr) {
            $data = json_decode($jsonStr, true);
            if (!$data) continue;

            $graph = isset($data['@graph']) ? $data['@graph'] : [$data];
            foreach ($graph as $obj) {
                // Normalise @type to a lowercase string
                $rawType = $obj['@type'] ?? '';
                $type = is_array($rawType) ? strtolower((string)($rawType[0] ?? '')) : strtolower((string)$rawType);

                // Only consider the main product object
                if ($type === 'product') {
                    $offers = $obj['offers'] ?? null;
                    if ($offers) {
                        $offerList = (is_array($offers) && !isset($offers['price']) && !isset($offers['lowPrice'])) ? $offers : [$offers];
                        foreach ($offerList as $offer) {
                            $p = $offer['price'] ?? $offer['lowPrice'] ?? null;
                            if ($p && (float)$p > 100) {
                                $jsonLdCandidates[] = (float) str_replace(',', '', (string)$p);
                            }
                        }
                    } else if (isset($obj['price']) && (float)$obj['price'] > 100) {
                        $jsonLdCandidates[] = (float) str_replace(',', '', (string)$obj['price']);
                    }
                }
            }
        }
    }

    if (!empty($jsonLdCandidates)) {
        // If multiple variants exist, the lowest one is typically the "from" price
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

    // Strategies 4 and 5 (Generic JS price key and aggressive Rs. text scanning) 
    // were removed to prevent false positives (like matching a Rs. 5000 Gift voucher)
    // when a product page is out of stock or redirected. It is safer to return null.

    return null;
}

/**
 * Extract the original / was-price from HTML (before discount).
 * Returns price as float or null if not found.
 */
function extractOriginalPriceFromHtml(string $html, float $actualPrice, string $sourceUrl = ''): ?float
{
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

    // Softlogic: data-product JSON — basePrice is the original when promotionPrice differs
    $isSoftlogic = (stripos($sourceUrl, 'softlogic') !== false);
    if ($isSoftlogic) {
        $decoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $urlProductId = null;
        if (preg_match('#/p/(\d+)#', $sourceUrl, $um)) {
            $urlProductId = $um[1];
        }
        // Find the matching data-product JSON
        $pattern = $urlProductId
            ? '/data-product="([^"]*?"id":\s*' . preg_quote($urlProductId, '/') . '\b[^"]*?)"/i'
            : '/data-product="([^"]+)"/i';
        if (preg_match($pattern, $decoded, $dpMatch)) {
            $json = json_decode($dpMatch[1], true);
            if ($json) {
                $basePrice = (float) ($json['basePrice'] ?? 0);
                if ($basePrice > $actualPrice) return $basePrice;
            }
        }
    }

    // Softlogic: main-price-del (CSS fallback)
    if (preg_match('/class="main-price-del"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }
    if (preg_match('/id="product-price"[^>]*>\s*(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }

    // Singer: old-price
    $isSinger = (stripos($sourceUrl, 'singer') !== false);
    if ($isSinger && preg_match('/class="old-price"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }

    // BuyAbans CSS was-price fallback
    $isAbans = (stripos($sourceUrl, 'buyabans') !== false);
    if ($isAbans && preg_match('/class="[^"]*(?:was-price|cut-off|market-price-de)[^"]*"[^>]*>.*?(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{1,2})?)/is', $html, $m)) {
        $val = (float) str_replace(',', '', $m[1]);
        if ($val > $actualPrice) return $val;
    }

    // Parse structured JSON-LD objects explicitly to avoid mistakenly picking up
    // background listPrices/regularPrices from non-visual Javascript data blobs
    // (like Abans' underlying product JSON).
    if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $scriptMatches)) {
        $jsonPrices = [];
        foreach ($scriptMatches[1] as $jsonStr) {
            $data = json_decode($jsonStr, true);
            if (!$data) continue;

            $graph = isset($data['@graph']) ? $data['@graph'] : [$data];
            foreach ($graph as $obj) {
                $rawType = $obj['@type'] ?? '';
                $type = is_array($rawType) ? strtolower((string)($rawType[0] ?? '')) : strtolower((string)$rawType);

                if ($type === 'product') {
                    // Check for standard high-level MRVP properties
                    $keys = ['originalPrice', 'regularPrice', 'listPrice', 'fullPrice', 'compareAtPrice', 'mrp_price'];
                    foreach ($keys as $k) {
                        if (isset($obj[$k])) {
                            $val = (float) str_replace(',', '', (string)$obj[$k]);
                            if ($val > $actualPrice) $jsonPrices[] = $val;
                        }
                    }

                    // Look within offers array (e.g. WooCommerce/Singer)
                    $offers = $obj['offers'] ?? null;
                    if ($offers) {
                        $offerList = (is_array($offers) && !isset($offers['price']) && !isset($offers['lowPrice'])) ? $offers : [$offers];
                        foreach ($offerList as $offer) {
                            $p = $offer['price'] ?? $offer['lowPrice'] ?? null;
                            if ($p && (float)$p > $actualPrice) {
                                $jsonPrices[] = (float) str_replace(',', '', (string)$p);
                            }
                        }
                    } else if (isset($obj['price']) && (float)$obj['price'] > $actualPrice) {
                        $jsonPrices[] = (float) str_replace(',', '', (string)$obj['price']);
                    }
                }
            }
        }
        if (!empty($jsonPrices)) {
            return min($jsonPrices);
        }
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
            'payment plan',
            'installment',
            'transactions over',
            'transactions between',
            'transactions from',
            'emi',
            'monthly',
            'per month',
            'easy payment',
            'applies to',
            'apply to',
            'eligible for',
            'koko',
            'buy now pay later',
            'split into',
            'pay in',
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
 * Uses the shared httpFetch() helper which handles WAF bypasses (Zenedge,
 * Cloudflare) automatically.
 *
 * @param PDO   $pdo
 * @param array $link Row from product_store_links (associative).
 * @return array ['status' => 'ok|no_price|error', 'message' => string, 'price' => ?float]
 */
function scrapeProductStoreLink(PDO $pdo, array $link): array
{
    $url = $link['product_url'];
    $result = ['status' => 'error', 'message' => '', 'price' => null];

    if (!preg_match('#^https?://#i', $url)) {
        $result['message'] = 'Invalid URL';
        return $result;
    }

    // Reset the PHP execution timer for each individual URL so one slow page
    // cannot exhaust the global max_execution_time for the entire scraper run.
    set_time_limit(60);

    // Use shared HTTP client with built-in WAF bypass (Zenedge, Cloudflare)
    $fetch = httpFetch($url, [
        'timeout'         => 20,
        'connect_timeout' => 8,
        'max_redirs'      => 5,
        'encoding'        => '',
        'bypass_waf'      => true,
    ]);

    if (!$fetch['ok']) {
        $result['message'] = 'Fetch failed'
            . (!empty($fetch['error']) ? ': ' . $fetch['error'] : " (HTTP {$fetch['http_code']})");
        return $result;
    }

    $html = $fetch['body'];

    $price = extractPriceFromHtml($html, $url);
    if ($price === null) {
        $result['status']  = 'no_price';
        $result['message'] = 'No price pattern found';

        // Explicitly update database to reflect out of stock / missing data
        // otherwise UI will infinitely show ghost/stale prices
        $productId = (int) $link['product_id'];
        $storeId   = (int) $link['store_id'];

        $pdo->prepare("
            UPDATE product_prices
            SET stock_status = 'out_of_stock', last_updated = NOW()
            WHERE product_id = ? AND store_id = ?
        ")->execute([$productId, $storeId]);

        $pdo->prepare("
            UPDATE product_store_links
            SET last_status = 'no_price', last_error = ?, last_scraped_at = NOW()
            WHERE id = ?
        ")->execute([$result['message'], $link['id']]);

        return $result;
    }

    // Try to extract original (before-discount) price
    $originalPrice = extractOriginalPriceFromHtml($html, $price, $url);

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
            $oldOrig  = $existing['original_price'] !== null ? (float) $existing['original_price'] : null;
            
            // Trigger DB update if:
            // 1. The main price changed
            // 2. The original price changed (e.g. sale began, or sale ended)
            if (abs($oldPrice - $price) > 0.009 || $originalPrice !== $oldOrig) {
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
            } else {
                // Even if no price diff, ensure it's marked 'in_stock' and bump updated time
                $pdo->prepare("UPDATE product_prices SET stock_status = 'in_stock', last_updated = NOW() WHERE id = ?")
                    ->execute([$existing['id']]);
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
