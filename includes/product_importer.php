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

                // @type can be a plain string OR an array of types (e.g. on Kapruka).
                // Normalise it to a single lowercase string before comparing.
                $rawType = $obj['@type'] ?? null;
                if (is_array($rawType)) {
                    // Use the first entry that is actually a string
                    $rawType = implode(' ', array_filter($rawType, 'is_string'));
                }
                $type = strtolower((string) $rawType);

                if ($type === 'product' || $type === 'itemlist') {

                    if ($type === 'itemlist' && isset($obj['itemListElement'])) {

                        // ItemList wraps each product inside itemListElement.item
                         foreach ($obj['itemListElement'] as $element) {

                             $prod = $element['item'] ?? null;

                             if($prod) {

                                 $item = $this->extractProductFromJson($prod, $baseUrl);

                                 if($item) $items[] = $item;

                             }

                         }

                    } else if ($type === 'product') {

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

// Kapruka doesn't use JSON-LD for their product listings — each product card is a plain
// <a href="/buyonline/..."> tag containing an image, a name div, and a price span.
// This parser walks the DOM to find those cards and extracts the fields we need.
// The SKU is pulled from the /kid/ segment of the product URL, which is stable across scrape runs.
class KaprukaCategoryParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        $items = [];

        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        // Every product card on Kapruka category pages is wrapped in an <a> that links
        // to a /buyonline/ path. That's the most reliable selector we have.
        $cards = $xpath->query('//a[contains(@href, "/buyonline/")]');

        foreach ($cards as $card) {

            $url = trim($card->getAttribute('href'));

            if (empty($url)) continue;

            // Resolve relative URLs to absolute using the base URL's scheme + host
            if (strpos($url, 'http') !== 0) {
                $parsed = parse_url($baseUrl);
                $url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.kapruka.com') . '/' . ltrim($url, '/');
            }

            // The product image is always the first <img> inside the card
            $imgNode = $xpath->query('.//img', $card)->item(0);
            $imgUrl  = '';
            if ($imgNode) {
                // Prefer data-src (lazy-loaded) over the placeholder src
                $imgUrl = $imgNode->getAttribute('data-src') ?: $imgNode->getAttribute('src');
                $imgUrl = trim($imgUrl);
            }

            // Kapruka puts the product name in the first text-heavy <div> inside the card.
            // We collect all direct child <div> elements and pick the one with the most text.
            $divs     = $xpath->query('.//div', $card);
            $name     = '';
            $maxLen   = 0;

            foreach ($divs as $div) {
                // Skip divs that contain child divs — those are wrappers, not the name
                $childDivs = $xpath->query('div', $div);
                if ($childDivs->length > 0) continue;

                $text = trim(preg_replace('/\s+/', ' ', $div->textContent));

                // Price spans end up inside divs too; skip anything that looks like a price
                if (preg_match('/RS\./i', $text)) continue;

                if (strlen($text) > $maxLen) {
                    $maxLen = strlen($text);
                    $name   = $text;
                }
            }

            if (empty($name)) continue;

            // Price is always in a <span> that contains the "RS." prefix
            $priceNode = $xpath->query('.//span[contains(., "RS.")]', $card)->item(0);

            if (!$priceNode) continue;

            $priceRaw = trim($priceNode->textContent);

            // Strip "RS." and commas, keep only the numeric part
            $price = (float) preg_replace('/[^0-9.]/', '', str_replace(',', '', $priceRaw));

            if ($price <= 0) continue;

            // Extract the SKU from the /kid/ segment: .../kid/ef_pc_elec0v4463pod00068fdp
            $sku = null;
            if (preg_match('#/kid/([^/?#]+)#', $url, $m)) {
                $sku = $m[1];
            }

            $items[] = [

                'name'               => $name,
                'product_url'        => $url,
                'image_url'          => $imgUrl ?: null,
                'price'              => $price,
                'brand'              => null, // extractBrandFromTitle() will fill this in later
                'model'              => null,
                'stock_status'       => 'in_stock',
                'source_product_key' => $sku,

            ];

        }

        return $items;

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

            $url = $linkNode ? trim($linkNode->getAttribute('href')) : '';

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

// BuyAbans (buyabans.com) loads products via an AJAX JSON endpoint rather than embedding
// them in the page HTML. When the category page loads, JavaScript calls:
//   GET /product-list?category_id={id}&sort=new_arrivals&is_search_list=false
// which returns a JSON object with an "html" key containing an escaped HTML fragment.
// This parser hits that endpoint directly with cURL (no browser needed) and then
// walks the resulting DOM using BuyAbans' known CSS class names.
class BuyabansParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        // Step 1: Extract category_id from the URL the admin configured.
        // BuyAbans category pages look like:
        //   https://buyabans.com/mobile-phones        (no ID in URL — we'll try the page)
        //   https://buyabans.com/category?id=15       (explicit ID)
        // If we can't find an ID we fall back to scraping the page HTML directly.
        $categoryId = $this->extractCategoryId($html, $baseUrl);

        $productHtml = '';

        if ($categoryId) {

            // Step 2: Hit the AJAX endpoint that actually contains the product data.
            // We send a browser-like UA so requests aren't rejected.
            $apiUrl = 'https://buyabans.com/product-list?category_id=' . $categoryId
                    . '&stamp_banner_id=0&sort=new_arrivals&is_search_list=false';

            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json, text/javascript, */*; q=0.01',
                    'X-Requested-With: XMLHttpRequest',
                    'Referer: ' . $baseUrl,
                ],
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            ]);

            $apiResponse = curl_exec($ch);
            curl_close($ch);

            if ($apiResponse) {

                // Step 3: The response is a JSON object; the "html" key holds the product cards.
                $decoded = json_decode($apiResponse, true);

                if (isset($decoded['html'])) {

                    // The HTML is JSON-encoded so unicode sequences and \/ are already unescaped by json_decode
                    $productHtml = $decoded['html'];

                } elseif (is_string($apiResponse) && strpos($apiResponse, 'product-list-item') !== false) {

                    // Fallback: some versions return raw HTML directly without a JSON wrapper
                    $productHtml = $apiResponse;

                }

            }

        }

        // Step 4: If the AJAX approach failed, try to parse whatever HTML was passed in.
        // This handles cases where the URL itself is the API endpoint.
        if (empty($productHtml)) {
            $productHtml = $html;
        }

        return $this->parseProductHtml($productHtml, $baseUrl);

    }

    // -------------------------------------------------------------------------
    // Extract the category ID from the page HTML.
    // BuyAbans embeds it in the JS initialisation code, e.g.:
    //   category_id: 15,
    // or it may appear in an AJAX request captured from the network.
    // -------------------------------------------------------------------------
    private function extractCategoryId(string $html, string $baseUrl): ?int {

        // Pattern 1: JS variable like  category_id: 15  or  "category_id":15
        if (preg_match('/"?category_id"?\s*:\s*(\d+)/i', $html, $m)) {
            return (int) $m[1];
        }

        // Pattern 2: URL query param  ?category_id=15  (when admin enters the API URL directly)
        $parsedUrl = parse_url($baseUrl);
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $qp);
            if (!empty($qp['category_id'])) {
                return (int) $qp['category_id'];
            }
        }

        // Pattern 3: data attribute  data-category="15"
        if (preg_match('/data-category[_-]?id\s*=\s*["\']?(\d+)/i', $html, $m)) {
            return (int) $m[1];
        }

        return null;

    }

    // -------------------------------------------------------------------------
    // Walk the product HTML fragment and extract items using BuyAbans' CSS classes.
    // Each card is a  div.product-list-item  containing:
    //   - .pro-name-compact   for the product name
    //   - .selling-price      for the current price (last span wins when discounted)
    //   - img.grid-product-img for the thumbnail
    //   - <a href="...">      for the product URL (inside .product-imgage)
    //   - data-product-id     on the wishlist button — used as our dedup SKU
    // -------------------------------------------------------------------------
    private function parseProductHtml(string $html, string $baseUrl): array {

        $items = [];

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);

// Each BuyAbans product card sits inside div.product-list-item
$cards = $xpath->query('//*[contains(@class, "product-list-item")]');

foreach ($cards as $card) {

// --- Name ---
$nameNode = $xpath->query('.//*[contains(@class, "pro-name-compact")]', $card)->item(0);
if (!$nameNode) continue;

// Prefer the title attribute (never truncated) over textContent
$name = trim($nameNode->getAttribute('title') ?: $nameNode->textContent);
if (empty($name)) continue;

// --- Product URL ---
// The image anchor is the most reliable link — it always points to the product page
$linkNode = $xpath->query('.//*[contains(@class, "product-imgage")]//a[@href] | .//a[contains(@href, "buyabans.com") or
starts-with(@href, "/")]', $card)->item(0);
if (!$linkNode) {
$linkNode = $xpath->query('.//a[@href]', $card)->item(0);
}
if (!$linkNode) continue;

$url = trim($linkNode->getAttribute('href'));
if (empty($url) || $url === '#') continue;

// Resolve to absolute URL
if (strpos($url, 'http') !== 0) {
$parsed = parse_url($baseUrl);
$url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'buyabans.com') . '/' . ltrim($url, '/');
}

// --- Price ---
// BuyAbans puts the selling price in span.selling-price.
// When a product is on sale there are TWO price spans (original + discounted);
// the last one is always the actual selling price.
$priceNodes = $xpath->query('.//*[contains(@class, "selling-price")]', $card);
$price = null;

if ($priceNodes->length > 0) {
// Use the LAST span — on discounted items the last one is the sale price
$lastPriceNode = $priceNodes->item($priceNodes->length - 1);
$priceRaw = trim($lastPriceNode->textContent);
$price = (float) preg_replace('/[^0-9.]/', '', str_replace(',', '', $priceRaw));
}

// Fallback: regex scan of the raw card text for "Rs. X,XXX"
if (!$price) {
$cardText = $card->textContent;
if (preg_match_all('/Rs\.\s*([\d,]+(?:\.\d{2})?)/', $cardText, $pm)) {
// Use the last matched price (same logic as above)
$price = (float) str_replace(',', '', end($pm[1]));
}
}

if (!$price || $price <= 0) continue;

            // --- Image ---
            $imgNode = $xpath->query('.//*[contains(@class, "grid-product-img")] | .//img', $card)->item(0);
            $imgUrl = '';
            if ($imgNode) {
                $imgUrl = trim($imgNode->getAttribute('data-src') ?: $imgNode->getAttribute('src'));
            }

    // --- Stock status ---
    $cardText = strtolower($card->textContent);
    $stockStatus = (strpos($cardText, 'out of stock') !== false) ? 'out_of_stock' : 'in_stock';

    // --- SKU (product ID) ---
    // BuyAbans puts data-product-id on the wishlist toggle button
    $skuNode = $xpath->query('.//*[@data-product-id]', $card)->item(0);
    $sku = $skuNode ? trim($skuNode->getAttribute('data-product-id')) : null;

    // --- Brand ---
    $brand = null;
    $knownBrands = ['Samsung', 'Apple', 'LG', 'Sony', 'Panasonic', 'Philips', 'Hisense',
    'Singer', 'Nokia', 'Xiaomi', 'Oppo', 'Vivo', 'Huawei', 'Haier', 'Midea',
    'Beko', 'Bosch', 'Sharp', 'TCL', 'Whirlpool', 'Electrolux', 'Motorola',
    'Realme', 'Infinix', 'Tecno', 'Itel', 'Lenovo', 'Honor'];

    $nameLower = strtolower($name);
    foreach ($knownBrands as $b) {
    if (strpos($nameLower, strtolower($b)) !== false) {
    $brand = $b;
    break;
    }
    }

    $items[] = [
    'name' => $name,
    'product_url' => $url,
    'image_url' => $imgUrl ?: null,
    'price' => $price,
    'brand' => $brand,
    'model' => null,
    'stock_status' => $stockStatus,
    'source_product_key' => $sku ?: null,
    ];

    }

    return $items;

    }

    }