<?php

require_once __DIR__ . '/db.php';

require_once __DIR__ . '/functions.php';

function normalizeProductName(string $name): string {

    $name = strtolower($name);

    $name = preg_replace('/[^a-z0-9\s]/', '', $name);

    $name = preg_replace('/\s+/', ' ', $name);

    return trim($name);

}

function extractBrandFromTitle(string $title): ?string {

    $brands = ['Samsung', 'Apple', 'LG', 'Sony', 'Panasonic', 'Abans', 'Philips', 'Hisense', 'Singer', 'Nokia', 'Xiaomi', 'Oppo', 'Vivo', 'Huawei'];

    $titleLower = strtolower($title);

    foreach ($brands as $brand) {

        if (strpos($titleLower, strtolower($brand)) !== false) {

            return $brand;

        }

    }

    return null;

}

function findExistingProductMatch(PDO $pdo, array $scrapedItem): ?int {

    $url = $scrapedItem['product_url'];

    $stmt = $pdo->prepare("SELECT product_id FROM product_store_links WHERE product_url = ? LIMIT 1");

    $stmt->execute([$url]);

    $urlMatch = $stmt->fetchColumn();

    if ($urlMatch) {

        return (int) $urlMatch;

    }

    if (!empty($scrapedItem['source_product_key'])) {

        $stmt = $pdo->prepare("SELECT id FROM products WHERE source_store_id = ? AND source_product_key = ? LIMIT 1");

        $stmt->execute([$scrapedItem['store_id'], $scrapedItem['source_product_key']]);

        $keyMatch = $stmt->fetchColumn();

        if ($keyMatch) {

            return (int) $keyMatch;

        }

    }

    $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");

    $stmt->execute([$scrapedItem['name']]);

    $nameMatch = $stmt->fetchColumn();

    if ($nameMatch) {

        return (int) $nameMatch;

    }

    if (!empty($scrapedItem['model'])) {

         $stmt = $pdo->prepare("SELECT id FROM products WHERE model = ? LIMIT 1");

         $stmt->execute([$scrapedItem['model']]);

         $modelMatch = $stmt->fetchColumn();

         if ($modelMatch) {

             return (int) $modelMatch;

         }

    }

    return null; 

}

function queueScrapedProduct(PDO $pdo, array $item): void {

    $normalized_name = normalizeProductName($item['name']);

    $stmt = $pdo->prepare("SELECT id, status FROM scraped_products WHERE store_id = ? AND product_url = ? LIMIT 1");

    $stmt->execute([$item['store_id'], $item['product_url']]);

    $existingStaged = $stmt->fetch();

    if ($existingStaged) {

        if ($existingStaged['status'] === 'pending') {

            $pdo->prepare("UPDATE scraped_products SET price = ?, scraped_at = NOW() WHERE id = ?")

                ->execute([$item['price'], $existingStaged['id']]);

        }

        return;

    }

    $matchId = findExistingProductMatch($pdo, $item);

    if ($matchId) {

        try {

            $pdo->beginTransaction();

            $pdo->prepare("INSERT IGNORE INTO product_store_links (product_id, store_id, product_url, last_price, last_scraped_at) VALUES (?, ?, ?, ?, NOW())")

                ->execute([$matchId, $item['store_id'], $item['product_url'], $item['price']]);

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

        $catId = !empty($item['category_id']) ? $item['category_id'] : 1; 

        $imageFilename = null;

        if (!empty($item['image_url'])) {

            $imageFilename = downloadAndSaveImage($item['image_url']);

        }

        try {

            $pdo->beginTransaction();

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

            $pdo->prepare("INSERT INTO product_store_links (product_id, store_id, product_url, auto_enabled, last_price, last_status, last_scraped_at) VALUES (?, ?, ?, 1, ?, 'ok', NOW())")

                ->execute([$newProductId, $item['store_id'], $item['product_url'], $item['price']]);

            $pdo->prepare("INSERT INTO product_prices (product_id, store_id, price, original_price, product_url, stock_status, last_updated) VALUES (?, ?, ?, NULL, ?, ?, NOW())")

                ->execute([$newProductId, $item['store_id'], $item['price'], $item['product_url'], $item['stock_status'] ?? 'in_stock']);

            $pdo->commit();

        } catch (Exception $e) {

            $pdo->rollBack();

            error_log("Failed to auto-insert new product directly: " . $e->getMessage());

        }

    }

}

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

function scrapeCategoryPage(PDO $pdo, int $storeId, string $url, string $parserClass, ?int $categoryId = null): array {

    $result = ['status' => 'error', 'message' => '', 'processed_count' => 0];

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

        $item['category_id'] = $categoryId;

        if (empty($item['name']) || empty($item['product_url']) || empty($item['price'])) {

            continue; 

        }

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

interface CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array;

}

class GenericCategoryParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        $items = [];

        $dom = new DOMDocument();

        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);

        $jsonScripts = $xpath->query('//script[@type="application/ld+json"]');

        foreach ($jsonScripts as $script) {

            $data = json_decode($script->nodeValue, true);

            if (!$data) continue;

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

            if (is_array($img) && isset($img['url'])) {

                $img = $img['url'];

            }

        }

        $price       = null;

        $stockStatus = 'in_stock'; 

        $offers      = null;

        if (isset($obj['offers'])) {

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

class SingerParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        $items = [];

        $dom = new DOMDocument();

        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);

        $cards = $xpath->query('//div[contains(@class, "productfilter")]');

        foreach ($cards as $card) {

            $linkNode = $xpath->query('.//a[contains(@href, "/product/")]', $card)->item(0);

            if (!$linkNode) continue;

            $url = trim($linkNode->getAttribute('href'));

            $imgNode = $xpath->query('.//img[contains(@class, "card-img-top")]', $card)->item(0);

            $name = $imgNode ? trim($imgNode->getAttribute('alt')) : '';

            $imgUrl = $imgNode ? trim($imgNode->getAttribute('src')) : '';

            if (empty($name) || empty($url)) continue;

            $codeNode = $xpath->query('.//p[contains(@class, "product__code")]', $card)->item(0);

            $sku = $codeNode ? trim($codeNode->nodeValue) : null;

            $cardText = $card->nodeValue;

            preg_match_all('/Rs\s+([\d,]+(?:\.\d{2})?)/', $cardText, $priceMatches);

            $price = null;

            if (!empty($priceMatches[1])) {

                $price = str_replace(',', '', $priceMatches[1][0]);

            }

            if (!$price || (float)$price <= 0) continue;

            $stockStatus = 'in_stock';

            if (stripos($cardText, 'out of stock') !== false) {

                $stockStatus = 'out_of_stock';

            } elseif (stripos($cardText, 'pre order') !== false) {

                $stockStatus = 'limited';

            }

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

class DarazParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        $items = [];

        if (preg_match('/\"listItems\"\s*:\s*(\[.*?\])\s*[,}]/s', $html, $m)) {

            $listData = json_decode($m[1], true);

            if (is_array($listData)) {

                foreach ($listData as $prod) {

                    $item = $this->extractDarazProduct($prod, $baseUrl);

                    if ($item) $items[] = $item;

                }

            }

        }

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

        if (is_string($price)) {

            $price = preg_replace('/[^0-9.]/', '', str_replace(',', '', $price));

        }

        $price = (float) $price;

        if ($price <= 0) return null;

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

class BuyabansParser implements CategoryParserInterface {

    public function parseHtml(string $html, string $baseUrl): array {

        $items = [];

        $dom = new DOMDocument();

        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);

        $cards = $xpath->query('//li[contains(@class, "product")] | //div[contains(@class, "product-item")]');

        foreach ($cards as $card) {

            $linkNode = $xpath->query('.//a[contains(@href, "/product/") or contains(@href, "/shop/")]', $card)->item(0);

            if (!$linkNode) {

                $linkNode = $xpath->query('.//a[@href]', $card)->item(0);

            }

            if (!$linkNode) continue;

            $url = trim($linkNode->getAttribute('href'));

            if (!$url || $url === '#') continue;

            $nameNode = $xpath->query('.//h2 | .//h3 | .//h4 | .//*[contains(@class, "product-title")] | .//*[contains(@class, "woocommerce-loop-product__title")]', $card)->item(0);

            $name = $nameNode ? trim($nameNode->textContent) : '';

            if (empty($name)) continue;

            $imgNode = $xpath->query('.//img', $card)->item(0);

            $imgUrl = '';

            if ($imgNode) {

                $imgUrl = $imgNode->getAttribute('data-src') ?: $imgNode->getAttribute('src');

            }

            $priceNode = $xpath->query('.//*[contains(@class, "price")]//*[contains(@class, "amount")] | .//*[contains(@class, "price")]//bdi', $card)->item(0);

            $price = null;

            if ($priceNode) {

                $priceText = $priceNode->textContent;

                $price = preg_replace('/[^0-9.]/', '', str_replace(',', '', $priceText));

            }

            if (!$price) {

                $cardText = $card->textContent;

                if (preg_match('/(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d{2})?)/', $cardText, $pm)) {

                    $price = str_replace(',', '', $pm[1]);

                }

            }

            if (!$price || (float)$price <= 0) continue;

            $stockStatus = 'in_stock';

            $cardText = $card->textContent;

            if (stripos($cardText, 'out of stock') !== false) {

                $stockStatus = 'out_of_stock';

            }

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

        if (empty($items)) {

            $generic = new GenericCategoryParser();

            $items = $generic->parseHtml($html, $baseUrl);

        }

        return $items;

    }

}

