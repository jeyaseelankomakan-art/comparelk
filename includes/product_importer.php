<?php
/**
 * Product Importer Logic - compare.lk
 * Handles scraping category pages, building product data, and duplicate checking.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Normalizes a product name for matching
 */
function normalizeProductName(string $name): string {
    $name = strtolower($name);
    // Remove common words like brand names if you want, or just basic cleanup
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

/**
 * Attempt to extract brand from product title
 */
function extractBrandFromTitle(string $title): ?string {
    // Basic heuristics: list of known brands.
    $brands = ['Samsung', 'Apple', 'LG', 'Sony', 'Panasonic', 'Abans', 'Philips', 'Hisense', 'Singer', 'Nokia', 'Xiaomi', 'Oppo', 'Vivo', 'Huawei'];
    $titleLower = strtolower($title);
    foreach ($brands as $brand) {
        if (strpos($titleLower, strtolower($brand)) !== false) {
            return $brand;
        }
    }
    return null;
}

/**
 * Check if a scraped product already exists in the main products table
 * Returns the exact matched product ID, or null.
 */
function findExistingProductMatch(PDO $pdo, array $scrapedItem): ?int {
    // 1. Check by URL in product_store_links
    $url = $scrapedItem['product_url'];
    $stmt = $pdo->prepare("SELECT product_id FROM product_store_links WHERE product_url = ? LIMIT 1");
    $stmt->execute([$url]);
    $urlMatch = $stmt->fetchColumn();
    if ($urlMatch) {
        return (int) $urlMatch;
    }

    // 2. Check by source_product_key and source_store_id inside products table
    if (!empty($scrapedItem['source_product_key'])) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE source_store_id = ? AND source_product_key = ? LIMIT 1");
        $stmt->execute([$scrapedItem['store_id'], $scrapedItem['source_product_key']]);
        $keyMatch = $stmt->fetchColumn();
        if ($keyMatch) {
            return (int) $keyMatch;
        }
    }

    // 3. Check by exact name match
    $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
    $stmt->execute([$scrapedItem['name']]);
    $nameMatch = $stmt->fetchColumn();
    if ($nameMatch) {
        return (int) $nameMatch;
    }

    // If model is present, check by model
    if (!empty($scrapedItem['model'])) {
         $stmt = $pdo->prepare("SELECT id FROM products WHERE model = ? LIMIT 1");
         $stmt->execute([$scrapedItem['model']]);
         $modelMatch = $stmt->fetchColumn();
         if ($modelMatch) {
             return (int) $modelMatch;
         }
    }

    return null; // No definite match found
}

/**
 * Insert or queue a scraped item
 */
function queueScrapedProduct(PDO $pdo, array $item): void {
    $normalized_name = normalizeProductName($item['name']);

    // Check if it's already in the scraped_products table
    $stmt = $pdo->prepare("SELECT id, status FROM scraped_products WHERE store_id = ? AND product_url = ? LIMIT 1");
    $stmt->execute([$item['store_id'], $item['product_url']]);
    $existingStaged = $stmt->fetch();

    if ($existingStaged) {
        // If it's already approved, merged or rejected, we might not want to touch it,
        // or we just update the price if pending.
        if ($existingStaged['status'] === 'pending') {
            $pdo->prepare("UPDATE scraped_products SET price = ?, scraped_at = NOW() WHERE id = ?")
                ->execute([$item['price'], $existingStaged['id']]);
        }
        return;
    }

    // Attempt to auto-match with existing product
    $matchId = findExistingProductMatch($pdo, $item);

    if ($matchId) {
        // Automatically insert into product_prices and product_store_links instead of staging!
        try {
            $pdo->beginTransaction();

            $pdo->prepare("INSERT IGNORE INTO product_store_links (product_id, store_id, product_url, last_price, last_scraped_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$matchId, $item['store_id'], $item['product_url'], $item['price']]);
            
            // Check if price already exists
            $pStmt = $pdo->prepare("SELECT id FROM product_prices WHERE product_id = ? AND store_id = ?");
            $pStmt->execute([$matchId, $item['store_id']]);
            if ($pRow = $pStmt->fetch()) {
                $pdo->prepare("UPDATE product_prices SET price = ?, product_url = ?, last_updated = NOW() WHERE id = ?")
                    ->execute([$item['price'], $item['product_url'], $pRow['id']]);
            } else {
                $pdo->prepare("INSERT INTO product_prices (product_id, store_id, price, product_url, stock_status, last_updated) VALUES (?, ?, ?, ?, ?, NOW())")
                    ->execute([$matchId, $item['store_id'], $item['price'], $item['product_url'], $item['stock_status'] ?? 'in_stock']);
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Failed to auto-update existing product prices: " . $e->getMessage());
        }
        return;

    } else {
        // No match found, push to scraped_products for review
        $pdo->prepare("
            INSERT INTO scraped_products 
            (store_id, category_id, name, normalized_name, product_url, image_url, price, brand, model, stock_status, source_product_key, scraped_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')
        ")->execute([
            $item['store_id'],
            $item['category_id'] ?? null,
            $item['name'],
            $normalized_name,
            $item['product_url'],
            $item['image_url'] ?? null,
            $item['price'],
            $item['brand'] ?? null,
            $item['model'] ?? null,
            $item['stock_status'] ?? 'in_stock',
            $item['source_product_key'] ?? null
        ]);
    }
}

/**
 * Download external image and save to local uploads directory and return new local filename
 */
function downloadAndSaveImage(string $url): ?string {
    if (empty($url)) return null;

    $context = stream_context_create([
        'http' => ['timeout' => 15, 'user_agent' => 'compare.lk /1.0 (Price comparison)'],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $imageBytes = @file_get_contents($url, false, $context);
    if (!$imageBytes) return null;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($imageBytes);
    
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) return null;

    $ext = $allowed[$mime];
    $filename = uniqid('prod_auto_') . '.' . $ext;
    
    $uploadDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $dest = $uploadDir . $filename;
    if (file_put_contents($dest, $imageBytes)) {
        return $filename;
    }
    return null;
}

/**
 * Base scrape logic. Extendable for different stores.
 * Since this is a generic script, we will support standard DOM crawling or passing HTML.
 */
function scrapeCategoryPage(PDO $pdo, int $storeId, string $url, string $parserClass): array {
    $result = ['status' => 'error', 'message' => '', 'processed_count' => 0];

    $context = stream_context_create([
        'http' => ['timeout' => 20, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/100.0.4896.75 Safari/537.36'],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    $html = @file_get_contents($url, false, $context);
    if ($html === false) {
        $result['message'] = "Could not fetch URL: $url";
        return $result;
    }

    if (!class_exists($parserClass)) {
         $result['message'] = "Parser class $parserClass not found.";
         return $result;
    }

    $parser = new $parserClass();
    $items = $parser->parseHtml($html, $url);

    if (empty($items)) {
         $result['message'] = "No items found on page.";
         return $result;
    }

    $processed = 0;
    foreach ($items as $item) {
        $item['store_id'] = $storeId;
        // Basic required fields
        if (empty($item['name']) || empty($item['product_url']) || empty($item['price'])) {
            continue; 
        }

        // Cleanup price
        $item['price'] = (float) str_replace([',', ' '], '', $item['price']);
        if ($item['price'] <= 0) continue;

        if (empty($item['brand'])) {
             $item['brand'] = extractBrandFromTitle($item['name']);
        }

        try {
            queueScrapedProduct($pdo, $item);
            $processed++;
        } catch (Exception $e) {
            error_log("Queue error: " . $e->getMessage());
        }
    }

    $result['status'] = 'ok';
    $result['message'] = "Processing complete.";
    $result['processed_count'] = $processed;
    
    return $result;
}

// ---------------------------------------------------------
// Example Store Parsers (Modular)
// ---------------------------------------------------------

interface CategoryParserInterface {
    public function parseHtml(string $html, string $baseUrl): array;
}

/**
 * Generic DOM Parser - searches for common grid layouts
 */
class GenericCategoryParser implements CategoryParserInterface {
    public function parseHtml(string $html, string $baseUrl): array {
        $items = [];
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        // Simple heuristic: find elements that look like product cards
        // For instance, looking for anything with 'product' or 'item' in class name and having a link + price.
        // This is highly site dependent. It is better to use specific parsing for known sites.
        // As a fallback, this finds JSON-LD scripts on standard pages.
        $jsonScripts = $xpath->query('//script[@type="application/ld+json"]');
        foreach ($jsonScripts as $script) {
            $data = json_decode($script->nodeValue, true);
            if (!$data) continue;
            
            // Sometimes it's a @graph or List
            $graph = $data['@graph'] ?? [$data];
            foreach ($graph as $obj) {
                if (isset($obj['@type']) && (strtolower($obj['@type']) === 'product' || strtolower($obj['@type']) === 'itemlist')) {
                    if (strtolower($obj['@type']) === 'itemlist' && isset($obj['itemListElement'])) {
                         foreach ($obj['itemListElement'] as $element) {
                             $prod = $element['item'] ?? null;
                             if($prod) {
                                 $item = $this->extractProductFromJson($prod, $baseUrl);
                                 if($item) $items[] = $item;
                             }
                         }
                    } else if (strtolower($obj['@type']) === 'product') {
                         $item = $this->extractProductFromJson($obj, $baseUrl);
                         if($item) $items[] = $item;
                    }
                }
            }
        }

        return $items;
    }

    private function extractProductFromJson(array $obj, string $baseUrl): ?array {
        $name = $obj['name'] ?? null;
        $url  = $obj['url']  ?? null;
        $img  = null;

        if (isset($obj['image'])) {
            $img = is_array($obj['image']) ? ($obj['image'][0] ?? null) : $obj['image'];
            // image may be an object with a 'url' key
            if (is_array($img) && isset($img['url'])) {
                $img = $img['url'];
            }
        }

        $price       = null;
        $stockStatus = 'in_stock'; // default
        $offers      = null;

        if (isset($obj['offers'])) {
            // Normalise: if it's an AggregateOffer use it directly, otherwise pick first element
            $raw = $obj['offers'];
            if (is_array($raw) && !isset($raw['price']) && !isset($raw['lowPrice']) && isset($raw[0])) {
                $offers = $raw[0];
            } else {
                $offers = $raw;
            }
            $price = $offers['price'] ?? $offers['lowPrice'] ?? null;

            if (isset($offers['availability'])) {
                $stockStatus = stripos($offers['availability'], 'InStock') !== false ? 'in_stock' : 'out_of_stock';
            }
        }

        if (!$name || !$url || !$price) return null;

        // Resolve relative URLs
        if (strpos($url, '//') === 0) {
            $parsed = parse_url($baseUrl);
            $url = ($parsed['scheme'] ?? 'https') . ':' . $url;
        } elseif (strpos($url, '/') === 0) {
            $parsed = parse_url($baseUrl);
            $url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $url;
        }

        return [
            'name'         => $name,
            'product_url'  => $url,
            'image_url'    => $img,
            'price'        => $price,
            'brand'        => $obj['brand']['name'] ?? null,
            'stock_status' => $stockStatus,
        ];
    }
}

/**
 * Singer Sri Lanka Parser - parses singersl.com product listing pages
 * Works with URLs like: https://www.singersl.com/products?category=-@243
 */
class SingerParser implements CategoryParserInterface {
    public function parseHtml(string $html, string $baseUrl): array {
        $items = [];
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        // Each product card lives inside div.productfilter
        $cards = $xpath->query('//div[contains(@class, "productfilter")]');

        foreach ($cards as $card) {
            // Product link & image
            $linkNode = $xpath->query('.//a[contains(@href, "/product/")]', $card)->item(0);
            if (!$linkNode) continue;

            $url = trim($linkNode->getAttribute('href'));
            $imgNode = $xpath->query('.//img[contains(@class, "card-img-top")]', $card)->item(0);
            $name = $imgNode ? trim($imgNode->getAttribute('alt')) : '';
            $imgUrl = $imgNode ? trim($imgNode->getAttribute('src')) : '';

            if (empty($name) || empty($url)) continue;

            // Product code / SKU (used as source_product_key)
            $codeNode = $xpath->query('.//p[contains(@class, "product__code")]', $card)->item(0);
            $sku = $codeNode ? trim($codeNode->nodeValue) : null;

            // Price extraction — find all text nodes containing "Rs"
            // Singer shows: "Rs  499,999" (sale) then "Rs  541,099" (original)
            // We want the first (sale / current) price
            $cardText = $card->nodeValue;
            preg_match_all('/Rs\s+([\d,]+(?:\.\d{2})?)/', $cardText, $priceMatches);

            $price = null;
            if (!empty($priceMatches[1])) {
                // First match = current/sale price
                $price = str_replace(',', '', $priceMatches[1][0]);
            }

            if (!$price || (float)$price <= 0) continue;

            // Stock status — look for "Out of Stock" text or "Pre Order"
            $stockStatus = 'in_stock';
            if (stripos($cardText, 'out of stock') !== false) {
                $stockStatus = 'out_of_stock';
            } elseif (stripos($cardText, 'pre order') !== false) {
                $stockStatus = 'limited';
            }

            // Brand extraction from product name
            $brand = null;
            $knownBrands = ['Samsung', 'Apple', 'Honor', 'Nubia', 'Nokia', 'Xiaomi', 'Oppo', 'Vivo', 'Huawei', 'Realme', 'OnePlus', 'Google'];
            $nameLower = strtolower($name);
            foreach ($knownBrands as $b) {
                if (strpos($nameLower, strtolower($b)) !== false) {
                    $brand = $b;
                    break;
                }
            }

            $items[] = [
                'name'               => $name,
                'product_url'        => $url,
                'image_url'          => $imgUrl,
                'price'              => $price,
                'brand'              => $brand,
                'model'              => $sku,
                'stock_status'       => $stockStatus,
                'source_product_key' => $sku,
            ];
        }

        return $items;
    }
}

/**
 * Daraz Sri Lanka Parser - parses daraz.lk category listing pages
 * Works with URLs like: https://www.daraz.lk/smartphones/
 * Daraz embeds product data in a script tag as JSON (window.pageData or similar).
 */
class DarazParser implements CategoryParserInterface {
    public function parseHtml(string $html, string $baseUrl): array {
        $items = [];

        // Strategy 1: Daraz embeds listing data as JSON inside <script> tags
        // Look for patterns like: "listItems":[{...}] or window.pageData = {...}
        if (preg_match('/\"listItems\"\s*:\s*(\[.*?\])\s*[,}]/s', $html, $m)) {
            $listData = json_decode($m[1], true);
            if (is_array($listData)) {
                foreach ($listData as $prod) {
                    $item = $this->extractDarazProduct($prod, $baseUrl);
                    if ($item) $items[] = $item;
                }
            }
        }

        // Strategy 2: Look for mods->listItems in __NEXT_DATA__ or pageData JSON
        if (empty($items) && preg_match('/window\.pageData\s*=\s*(\{.*?\});\s*<\/script>/s', $html, $m)) {
            $pageData = json_decode($m[1], true);
            if (is_array($pageData)) {
                $listItems = $pageData['mods']['listItems'] ?? [];
                foreach ($listItems as $prod) {
                    $item = $this->extractDarazProduct($prod, $baseUrl);
                    if ($item) $items[] = $item;
                }
            }
        }

        // Strategy 3: Fallback to JSON-LD
        if (empty($items)) {
            $generic = new GenericCategoryParser();
            $items = $generic->parseHtml($html, $baseUrl);
        }

        return $items;
    }

    private function extractDarazProduct(array $prod, string $baseUrl): ?array {
        $name  = $prod['name'] ?? $prod['title'] ?? null;
        $url   = $prod['productUrl'] ?? $prod['itemUrl'] ?? $prod['url'] ?? null;
        $price = $prod['price'] ?? $prod['priceShow'] ?? $prod['salePrice'] ?? null;
        $img   = $prod['image'] ?? $prod['thumbUrl'] ?? null;
        $sku   = $prod['itemId'] ?? $prod['nid'] ?? $prod['skuId'] ?? null;

        if (!$name || !$price) return null;

        // Cleanup price: remove "Rs. " prefix and commas
        if (is_string($price)) {
            $price = preg_replace('/[^0-9.]/', '', str_replace(',', '', $price));
        }
        $price = (float) $price;
        if ($price <= 0) return null;

        // Build full URL if relative
        if ($url && strpos($url, '//') !== 0 && strpos($url, 'http') !== 0) {
            $parsed = parse_url($baseUrl);
            $url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.daraz.lk') . '/' . ltrim($url, '/');
        }
        if (!$url) return null;

        // Stock status
        $stockStatus = 'in_stock';
        if (isset($prod['inStock']) && !$prod['inStock']) {
            $stockStatus = 'out_of_stock';
        }

        // Brand
        $brand = $prod['brandName'] ?? $prod['brand'] ?? null;

        return [
            'name'               => $name,
            'product_url'        => $url,
            'image_url'          => $img,
            'price'              => $price,
            'brand'              => $brand,
            'model'              => null,
            'stock_status'       => $stockStatus,
            'source_product_key' => $sku ? (string) $sku : null,
        ];
    }
}

/**
 * Buyabans Parser - parses buyabans.com product listing pages
 * Works with WooCommerce-style grids: <ul class="products"> / <li class="product">
 * Also works with URLs like: https://www.buyabans.com/product-category/televisions/
 */
class BuyabansParser implements CategoryParserInterface {
    public function parseHtml(string $html, string $baseUrl): array {
        $items = [];
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        // WooCommerce product cards: li.product or div.product
        $cards = $xpath->query('//li[contains(@class, "product")] | //div[contains(@class, "product-item")]');

        foreach ($cards as $card) {
            // Product link
            $linkNode = $xpath->query('.//a[contains(@href, "/product/") or contains(@href, "/shop/")]', $card)->item(0);
            if (!$linkNode) {
                // Fallback: first <a> tag with an href
                $linkNode = $xpath->query('.//a[@href]', $card)->item(0);
            }
            if (!$linkNode) continue;

            $url = trim($linkNode->getAttribute('href'));
            if (!$url || $url === '#') continue;

            // Product name
            $nameNode = $xpath->query('.//h2 | .//h3 | .//h4 | .//*[contains(@class, "product-title")] | .//*[contains(@class, "woocommerce-loop-product__title")]', $card)->item(0);
            $name = $nameNode ? trim($nameNode->textContent) : '';
            if (empty($name)) continue;

            // Image
            $imgNode = $xpath->query('.//img', $card)->item(0);
            $imgUrl = '';
            if ($imgNode) {
                $imgUrl = $imgNode->getAttribute('data-src') ?: $imgNode->getAttribute('src');
            }

            // Price — look for <span class="amount"> or <bdi> inside price wrapper
            $priceNode = $xpath->query('.//*[contains(@class, "price")]//*[contains(@class, "amount")] | .//*[contains(@class, "price")]//bdi', $card)->item(0);
            $price = null;
            if ($priceNode) {
                $priceText = $priceNode->textContent;
                $price = preg_replace('/[^0-9.]/', '', str_replace(',', '', $priceText));
            }

            // Fallback: look for Rs/LKR text in the card
            if (!$price) {
                $cardText = $card->textContent;
                if (preg_match('/(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{2})?)/', $cardText, $pm)) {
                    $price = str_replace(',', '', $pm[1]);
                }
            }

            if (!$price || (float)$price <= 0) continue;

            // Stock status
            $stockStatus = 'in_stock';
            $cardText = $card->textContent;
            if (stripos($cardText, 'out of stock') !== false) {
                $stockStatus = 'out_of_stock';
            }

            // Brand from title
            $brand = null;
            $knownBrands = ['Samsung', 'Apple', 'LG', 'Sony', 'Panasonic', 'Abans', 'Philips', 'Hisense',
                            'Singer', 'Nokia', 'Xiaomi', 'Oppo', 'Vivo', 'Huawei', 'Haier', 'Midea',
                            'Beko', 'Bosch', 'Sharp', 'TCL', 'Whirlpool', 'Electrolux'];
            $nameLower = strtolower($name);
            foreach ($knownBrands as $b) {
                if (strpos($nameLower, strtolower($b)) !== false) {
                    $brand = $b;
                    break;
                }
            }

            // Resolve relative URL
            if (strpos($url, 'http') !== 0) {
                $parsed = parse_url($baseUrl);
                if (strpos($url, '//') === 0) {
                    $url = ($parsed['scheme'] ?? 'https') . ':' . $url;
                } elseif (strpos($url, '/') === 0) {
                    $url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $url;
                } else {
                    $url = rtrim($baseUrl, '/') . '/' . $url;
                }
            }

            $items[] = [
                'name'               => $name,
                'product_url'        => $url,
                'image_url'          => $imgUrl,
                'price'              => $price,
                'brand'              => $brand,
                'model'              => null,
                'stock_status'       => $stockStatus,
                'source_product_key' => null,
            ];
        }

        // Fallback: if DOM parsing found nothing, try JSON-LD
        if (empty($items)) {
            $generic = new GenericCategoryParser();
            $items = $generic->parseHtml($html, $baseUrl);
        }

        return $items;
    }
}
