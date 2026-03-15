<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Strip punctuation and lowercase the name so we can compare products
// across different stores without worrying about casing or symbols.
function normalizeProductName(string $name): string {

    $name = strtolower($name);

    // Remove anything that's not a letter, digit, or space
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);

    // Collapse multiple spaces into one
    $name = preg_replace('/\s+/', ' ', $name);

    return trim($name);

}

// Try to detect the brand from the product title.
// Most stores don't provide a separate brand field, so we just check
// if the name contains anything from our known brands list.
function extractBrandFromTitle(string $title): ?string {

    $brands = ['Samsung', 'Apple', 'LG', 'Sony', 'Panasonic', 'Abans', 'Philips', 'Hisense', 'Singer', 'Nokia', 'Xiaomi', 'Oppo', 'Vivo', 'Huawei'];

    $titleLower = strtolower($title);

    foreach ($brands as $brand) {

        if (strpos($titleLower, strtolower($brand)) !== false) {

            return $brand;

        }

    }

    // No match found; caller can decide what to do
    return null;

}

// Before creating a new product, check if we already have it in the database.
// We try several matching strategies in order of reliability:
//   1. Exact URL match (most reliable — same URL = definitely the same product)
//   2. Store + source product key (e.g. SKU from the original site)
//   3. Product name match (risky but usually fine for our dataset)
//   4. Model number match (helpful for electronics)
function findExistingProductMatch(PDO $pdo, array $scrapedItem): ?int {

    $url = $scrapedItem['product_url'];

    // Check if we've seen this exact product URL before
    $stmt = $pdo->prepare("SELECT product_id FROM product_store_links WHERE product_url = ? LIMIT 1");
    $stmt->execute([$url]);
    $urlMatch = $stmt->fetchColumn();

    if ($urlMatch) {
        return (int) $urlMatch;
    }

    // Try matching by the store's own product ID / SKU if we captured one
    if (!empty($scrapedItem['source_product_key'])) {

        $stmt = $pdo->prepare("SELECT id FROM products WHERE source_store_id = ? AND source_product_key = ? LIMIT 1");
        $stmt->execute([$scrapedItem['store_id'], $scrapedItem['source_product_key']]);
        $keyMatch = $stmt->fetchColumn();

        if ($keyMatch) {
            return (int) $keyMatch;
        }

    }

    // Fall back to name matching — this catches slight URL differences for the same product
    $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
    $stmt->execute([$scrapedItem['name']]);
    $nameMatch = $stmt->fetchColumn();

    if ($nameMatch) {
        return (int) $nameMatch;
    }

    // Last resort: match by model number if we have one
    if (!empty($scrapedItem['model'])) {

         $stmt = $pdo->prepare("SELECT id FROM products WHERE model = ? LIMIT 1");
         $stmt->execute([$scrapedItem['model']]);
         $modelMatch = $stmt->fetchColumn();

         if ($modelMatch) {
             return (int) $modelMatch;
         }

    }

    // Nothing matched — treat this as a brand new product
    return null;

}

// This is the main entry point for each scraped item.
// It decides whether to:
//   a) Skip (already queued and still pending review)
//   b) Update prices on an existing product we already know about
//   c) Insert it as a brand new product directly
function queueScrapedProduct(PDO $pdo, array $item): void {

    $normalized_name = normalizeProductName($item['name']);

    // Check if this URL is already waiting in the staging queue
    $stmt = $pdo->prepare("SELECT id, status FROM scraped_products WHERE store_id = ? AND product_url = ? LIMIT 1");
    $stmt->execute([$item['store_id'], $item['product_url']]);
    $existingStaged = $stmt->fetch();

    if ($existingStaged) {

        // If it's still pending approval, just update the price in case it changed
        if ($existingStaged['status'] === 'pending') {

            $pdo->prepare("UPDATE scraped_products SET price = ?, scraped_at = NOW() WHERE id = ?")
                ->execute([$item['price'], $existingStaged['id']]);

        }

        // Either way, nothing more to do for this item
        return;

    }

    // See if the product already exists in our main catalog
    $matchId = findExistingProductMatch($pdo, $item);

    if ($matchId) {

        // We found a match — just update the price and store link, no need to create anything new
        try {

            $pdo->beginTransaction();

            // Make sure the store link exists (this is a no-op if it's already there)
            $pdo->prepare("INSERT IGNORE INTO product_store_links (product_id, store_id, product_url, last_price, last_scraped_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$matchId, $item['store_id'], $item['product_url'], $item['price']]);

            // Update or insert the price record for this store
            $pStmt = $pdo->prepare("SELECT id FROM product_prices WHERE product_id = ? AND store_id = ?");
            $pStmt->execute([$matchId, $item['store_id']]);

            if ($pRow = $pStmt->fetch()) {

                // Price already on record — just refresh it
                $pdo->prepare("UPDATE product_prices SET price = ?, product_url = ?, last_updated = NOW() WHERE id = ?")
                    ->execute([$item['price'], $item['product_url'], $pRow['id']]);

            } else {

                // First time we've seen this store selling this product
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

        // Brand new product — insert it straight into the catalog
        // Default to category 1 if we couldn't figure out the right category
        $catId = !empty($item['category_id']) ? $item['category_id'] : 1;

        $imageFilename = null;

        // Try to download the product image if the scraper found one
        if (!empty($item['image_url'])) {

            $imageFilename = downloadAndSaveImage($item['image_url']);

        }

        try {

            $pdo->beginTransaction();

            // Insert the product itself — mark it as auto_added so admins know it came from a scrape
            $pdo->prepare("

                INSERT INTO products 

                (category_id, name, brand, model, image, source_store_id, source_product_url, source_product_key, auto_added)

                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)

            ")->execute([

                $catId,
                $item['name'],
                $item['brand'] ?? null,
                $item['model'] ?? null,
                $imageFilename,
                $item['store_id'],
                $item['product_url'],
                $item['source_product_key'] ?? null

            ]);

            $newProductId = $pdo->lastInsertId();

            // Wire up the scraper link so future runs can track price changes
            $pdo->prepare("INSERT INTO product_store_links (product_id, store_id, product_url, auto_enabled, last_price, last_status, last_scraped_at) VALUES (?, ?, ?, 1, ?, 'ok', NOW())")
                ->execute([$newProductId, $item['store_id'], $item['product_url'], $item['price']]);

            // Add the initial price record
            $pdo->prepare("INSERT INTO product_prices (product_id, store_id, price, original_price, product_url, stock_status, last_updated) VALUES (?, ?, ?, NULL, ?, ?, NOW())")
                ->execute([$newProductId, $item['store_id'], $item['price'], $item['product_url'], $item['stock_status'] ?? 'in_stock']);

            $pdo->commit();

        } catch (Exception $e) {

            $pdo->rollBack();
            error_log("Failed to auto-insert new product directly: " . $e->getMessage());

        }

    }

}

// Download a product image from a URL and save it to the uploads folder.
// Returns just the filename so we can store it in the DB, or null if anything fails.
function downloadAndSaveImage(string $url): ?string {

    if (empty($url)) return null;

    $context = stream_context_create([

        'http' => ['timeout' => 15, 'user_agent' => 'compare.lk /1.0 (Price comparison)'],

        // Disable SSL verification — some stores use self-signed certs
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]

    ]);

    $imageBytes = @file_get_contents($url, false, $context);

    if (!$imageBytes) return null;

    // Use finfo to check the actual MIME type, not just the file extension
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($imageBytes);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    // Reject anything that isn't a recognised image format
    if (!isset($allowed[$mime])) return null;

    $ext = $allowed[$mime];

    // Use a unique prefix so filenames never collide
    $filename = uniqid('prod_auto_') . '.' . $ext;

    $uploadDir = __DIR__ . '/../uploads/products/';

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $dest = $uploadDir . $filename;

    if (file_put_contents($dest, $imageBytes)) {

        return $filename;

    }

    return null;

}

// Fetch a store category page and extract all the products from it.
// This is the main function called by both the admin "Run now" button
// and the cron job. It uses cURL so we can send a proper browser User-Agent
// — plain file_get_contents gets blocked by most stores.
function scrapeCategoryPage(PDO $pdo, int $storeId, string $url, string $parserClass, ?int $categoryId = null): array {

    $result = ['status' => 'error', 'message' => '', 'processed_count' => 0];

    // Use cURL with a real browser UA — many stores block requests without one
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false || $httpCode >= 400) {
        $result['message'] = "Could not fetch URL: $url (HTTP $httpCode)";
        return $result;
    }

    // Make sure the parser class actually exists before trying to use it
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

        $item['store_id']   = $storeId;
        $item['category_id'] = $categoryId;

        // Skip anything without the bare minimum data we need
        if (empty($item['name']) || empty($item['product_url']) || empty($item['price'])) {
            continue;
        }

        // Clean up the price — some scrapers return strings like "45,000"
        $item['price'] = (float) str_replace([',', ' '], '', $item['price']);

        if ($item['price'] <= 0) continue;

        // Try to fill in the brand if the parser didn't manage to get it
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

    $result['status']          = 'ok';
    $result['message']         = "Processing complete.";
    $result['processed_count'] = $processed;

    return $result;

}

// All parser classes implement this interface so scrapeCategoryPage()
// can call them the same way regardless of which store we're scraping.
interface CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array;

}

// A best-effort parser that works on most stores supporting JSON-LD structured data.
// It looks for <script type="application/ld+json"> blocks and pulls out
// Product or ItemList objects. Works surprisingly well without needing site-specific logic.
class GenericCategoryParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        $items = [];

        $dom = new DOMDocument();

        // Suppress warnings; store HTML is never perfectly valid
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);

        // Grab every JSON-LD block on the page
        $jsonScripts = $xpath->query('//script[@type="application/ld+json"]');

        foreach ($jsonScripts as $script) {

            $data = json_decode($script->nodeValue, true);

            if (!$data) continue;

            // Some sites use @graph to wrap multiple objects; others put it at the top level
            $graph = $data['@graph'] ?? [$data];

            foreach ($graph as $obj) {

                if (isset($obj['@type']) && (strtolower($obj['@type']) === 'product' || strtolower($obj['@type']) === 'itemlist')) {

                    if (strtolower($obj['@type']) === 'itemlist' && isset($obj['itemListElement'])) {

                        // ItemList wraps each product inside itemListElement.item
                         foreach ($obj['itemListElement'] as $element) {

                             $prod = $element['item'] ?? null;

                             if($prod) {

                                 $item = $this->extractProductFromJson($prod, $baseUrl);

                                 if($item) $items[] = $item;

                             }

                         }

                    } else if (strtolower($obj['@type']) === 'product') {

                        // Single product — this shows up on product detail pages
                         $item = $this->extractProductFromJson($obj, $baseUrl);

                         if($item) $items[] = $item;

                    }

                }

            }

        }

        return $items;

    }

    // Pull out a clean product array from a JSON-LD Product object.
    // Returns null if required fields are missing so the caller can skip it.
    private function extractProductFromJson(array $obj, string $baseUrl): ?array {

        $name = $obj['name'] ?? null;
        $url  = $obj['url']  ?? null;

        // Images can be a string, an object, or an array of either
        $img = null;

        if (isset($obj['image'])) {

            $img = is_array($obj['image']) ? ($obj['image'][0] ?? null) : $obj['image'];

            if (is_array($img) && isset($img['url'])) {

                $img = $img['url'];

            }

        }

        $price       = null;
        $stockStatus = 'in_stock'; // assume in stock unless told otherwise
        $offers      = null;

        if (isset($obj['offers'])) {

            $raw = $obj['offers'];

            // Offers can be a single object or an array; just grab the first one
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

        // Can't do anything useful without a name, URL, and price
        if (!$name || !$url || !$price) return null;

        // Resolve relative or protocol-relative URLs
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

// Singer Sri Lanka uses a custom WooCommerce-ish theme with specific CSS classes.
// The generic JSON-LD parser doesn't reliably pick up their category pages,
// so this parser directly walks the DOM for their product card structure.
class SingerParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        $items = [];

        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        // Each product lives inside a div with "productfilter" in the class name
        $cards = $xpath->query('//div[contains(@class, "productfilter")]');

        foreach ($cards as $card) {

            // The product link always contains "/product/" in the href
            $linkNode = $xpath->query('.//a[contains(@href, "/product/")]', $card)->item(0);

            if (!$linkNode) continue;

            $url = trim($linkNode->getAttribute('href'));

            // The product name is in the img alt text on Singer's pages
            $imgNode = $xpath->query('.//img[contains(@class, "card-img-top")]', $card)->item(0);
            $name    = $imgNode ? trim($imgNode->getAttribute('alt')) : '';
            $imgUrl  = $imgNode ? trim($imgNode->getAttribute('src'))  : '';

            if (empty($name) || empty($url)) continue;

            // Grab the SKU / product code if it's there — useful for deduplication
            $codeNode = $xpath->query('.//p[contains(@class, "product__code")]', $card)->item(0);
            $sku      = $codeNode ? trim($codeNode->nodeValue) : null;

            // Singer shows prices as "Rs 45,000" — pick up the first match
            $cardText = $card->nodeValue;
            preg_match_all('/Rs\s+([\d,]+(?:\.\d{2})?)/', $cardText, $priceMatches);

            $price = null;

            if (!empty($priceMatches[1])) {

                $price = str_replace(',', '', $priceMatches[1][0]);

            }

            if (!$price || (float)$price <= 0) continue;

            // Check for out of stock or pre-order text anywhere in the card
            $stockStatus = 'in_stock';

            if (stripos($cardText, 'out of stock') !== false) {

                $stockStatus = 'out_of_stock';

            } elseif (stripos($cardText, 'pre order') !== false) {

                $stockStatus = 'limited';

            }

            // Try to identify the brand from the product name
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
                'source_product_key' => $sku, // use SKU as our dedup key for Singer

            ];

        }

        return $items;

    }

}

// Daraz embeds product data as JSON inside <script> tags rather than in the HTML itself.
// We try two common patterns before giving up and falling back to the generic parser.
class DarazParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        $items = [];

        // Pattern 1: Daraz sometimes puts a "listItems" array directly in the page JS
        if (preg_match('/\"listItems\"\s*:\s*(\[.*?\])\s*[,}]/s', $html, $m)) {

            $listData = json_decode($m[1], true);

            if (is_array($listData)) {

                foreach ($listData as $prod) {

                    $item = $this->extractDarazProduct($prod, $baseUrl);

                    if ($item) $items[] = $item;

                }

            }

        }

        // Pattern 2: Newer Daraz pages use window.pageData — try that if pattern 1 found nothing
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

        // Neither pattern worked — try the generic JSON-LD approach as a last resort
        if (empty($items)) {

            $generic = new GenericCategoryParser();
            $items = $generic->parseHtml($html, $baseUrl);

        }

        return $items;

    }

    // Map a raw Daraz product object (from their JS data) to our standard item format.
    // Field names vary between API versions, so we check several alternatives for each.
    private function extractDarazProduct(array $prod, string $baseUrl): ?array {

        $name  = $prod['name']       ?? $prod['title']     ?? null;
        $url   = $prod['productUrl'] ?? $prod['itemUrl']   ?? $prod['url']       ?? null;
        $price = $prod['price']      ?? $prod['priceShow'] ?? $prod['salePrice'] ?? null;
        $img   = $prod['image']      ?? $prod['thumbUrl']  ?? null;
        $sku   = $prod['itemId']     ?? $prod['nid']       ?? $prod['skuId']     ?? null;

        if (!$name || !$price) return null;

        // Strip any currency symbols or commas from the price string
        if (is_string($price)) {

            $price = preg_replace('/[^0-9.]/', '', str_replace(',', '', $price));

        }

        $price = (float) $price;

        if ($price <= 0) return null;

        // Fix relative URLs — Daraz sometimes gives paths without the domain
        if ($url && strpos($url, '//') !== 0 && strpos($url, 'http') !== 0) {

            $parsed = parse_url($baseUrl);
            $url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.daraz.lk') . '/' . ltrim($url, '/');

        }

        if (!$url) return null;

        $stockStatus = 'in_stock';

        if (isset($prod['inStock']) && !$prod['inStock']) {

            $stockStatus = 'out_of_stock';

        }

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

// Buyabans runs a WooCommerce store so their markup follows standard WC patterns.
// We look for product cards and try a couple of price selectors before falling
// back to a regex on the raw card text.
class BuyabansParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        $items = [];

        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        // WooCommerce product cards are usually <li class="product"> or similar
        $cards = $xpath->query('//li[contains(@class, "product")] | //div[contains(@class, "product-item")]');

        foreach ($cards as $card) {

            // Try to find the product link — prefer ones that look like product/shop paths
            $linkNode = $xpath->query('.//a[contains(@href, "/product/") or contains(@href, "/shop/")]', $card)->item(0);

            if (!$linkNode) {

                // Some cards wrap the whole thing in a plain <a>
                $linkNode = $xpath->query('.//a[@href]', $card)->item(0);

            }

            if (!$linkNode) continue;

            $url = trim($linkNode->getAttribute('href'));

            if (!$url || $url === '#') continue;

            // Product name is usually in a heading element or a WC title class
            $nameNode = $xpath->query('.//h2 | .//h3 | .//h4 | .//*[contains(@class, "product-title")] | .//*[contains(@class, "woocommerce-loop-product__title")]', $card)->item(0);
            $name     = $nameNode ? trim($nameNode->textContent) : '';

            if (empty($name)) continue;

            // Grab the image — prefer lazy-loaded src (data-src) over the placeholder
            $imgNode = $xpath->query('.//img', $card)->item(0);
            $imgUrl  = '';

            if ($imgNode) {

                $imgUrl = $imgNode->getAttribute('data-src') ?: $imgNode->getAttribute('src');

            }

            // Try the WC-standard price markup first
            $priceNode = $xpath->query('.//*[contains(@class, "price")]//*[contains(@class, "amount")] | .//*[contains(@class, "price")]//bdi', $card)->item(0);
            $price     = null;

            if ($priceNode) {

                $priceText = $priceNode->textContent;
                $price     = preg_replace('/[^0-9.]/', '', str_replace(',', '', $priceText));

            }

            // If that didn't work, just scan the card text for any Rs. amount
            if (!$price) {

                $cardText = $card->textContent;

                if (preg_match('/(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{2})?)/', $cardText, $pm)) {

                    $price = str_replace(',', '', $pm[1]);

                }

            }

            if (!$price || (float)$price <= 0) continue;

            // Check for out-of-stock notice anywhere in the card
            $stockStatus = 'in_stock';
            $cardText    = $card->textContent;

            if (stripos($cardText, 'out of stock') !== false) {

                $stockStatus = 'out_of_stock';

            }

            // Same brand detection as everywhere else
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

            // Resolve relative URLs against the base URL of the category page
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
                'model'              => null, // Buyabans doesn't expose model numbers in listing pages
                'stock_status'       => $stockStatus,
                'source_product_key' => null, // no SKU available from the listing page

            ];

        }

        // If we got nothing from the DOM, give the generic JSON-LD parser a shot
        if (empty($items)) {

            $generic = new GenericCategoryParser();
            $items   = $generic->parseHtml($html, $baseUrl);

        }

        return $items;

    }

}
