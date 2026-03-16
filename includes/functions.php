<?php
/**
 * Global Helper Functions - compare.lk
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function ensureSessionStarted(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function csrf_token(): string
{
    ensureSessionStarted();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(): bool
{
    ensureSessionStarted();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }
    $sent = $_POST['_csrf'] ?? '';
    $expected = $_SESSION['_csrf'] ?? '';
    return is_string($sent) && is_string($expected) && $sent !== '' && hash_equals($expected, $sent);
}

/**
 * Format price in LKR
 */
function formatPrice(float $price): string
{
    return 'Rs ' . number_format($price, 2);
}

/**
 * Get all categories
 */
function getCategories(): array
{
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
    return $stmt->fetchAll();
}

/**
 * Get category by slug
 */
function getCategoryBySlug(string $slug): ?array
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

/**
 * Get products by category
 */
function getProductsByCategory(int $categoryId, string $sort = 'latest', int $limit = 0): array
{
    $pdo = getDB();
    $orderBy = $sort === 'price' ? 'min_price ASC' : 'p.created_at DESC, p.id DESC';
    $limitSql = $limit > 0 ? 'LIMIT ' . (int)$limit : '';
    $stmt = $pdo->prepare("
        SELECT p.*,
               MIN(pp.price) AS min_price,
               COUNT(DISTINCT pp.store_id) AS store_count,
               MAX(pp.last_updated) AS last_updated
        FROM products p
        LEFT JOIN product_prices pp ON p.id = pp.product_id
        WHERE p.category_id = ?
        GROUP BY p.id
        ORDER BY $orderBy
        $limitSql
    ");
    $stmt->execute([$categoryId]);
    return $stmt->fetchAll();
}

/**
 * Get product by ID with prices
 */
function getProductById(int $id): ?array
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Get product prices with store info
 */
function getProductPrices(int $productId): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT pp.*, s.name AS store_name, s.slug AS store_slug, s.logo AS store_logo, s.website_url
        FROM product_prices pp
        JOIN stores s ON pp.store_id = s.id
        WHERE pp.product_id = ?
        ORDER BY pp.price ASC
    ");
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

/**
 * Get price history for chart
 */
function getPriceHistory(int $productId, int $storeId): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT price, recorded_at
        FROM price_history
        WHERE product_id = ? AND store_id = ?
        ORDER BY recorded_at ASC
        LIMIT 30
    ");
    $stmt->execute([$productId, $storeId]);
    return $stmt->fetchAll();
}

/**
 * Search products
 */
function searchProducts(string $keyword, ?int $categoryId = null, ?float $minPrice = null, ?float $maxPrice = null, string $sort = 'latest'): array
{
    $pdo = getDB();
    $params = [];
    $where = ["(p.name LIKE ? OR p.brand LIKE ? OR p.model LIKE ?)"];
    $kw = '%' . $keyword . '%';
    $params = [$kw, $kw, $kw];

    if ($categoryId) {
        $where[] = "p.category_id = ?";
        $params[] = $categoryId;
    }

    $havingClauses = [];
    if ($minPrice !== null) {
        $havingClauses[] = "min_price >= ?";
        $params[] = $minPrice;
    }
    if ($maxPrice !== null) {
        $havingClauses[] = "min_price <= ?";
        $params[] = $maxPrice;
    }

    $orderBy = match ($sort) {
        'price_asc' => 'min_price ASC',
        'relevance' => 'p.created_at DESC, p.id DESC', // Default to latest for relevance
        'latest' => 'p.created_at DESC, p.id DESC',
        default => 'p.created_at DESC, p.id DESC', // Default to latest
    };
    $having = count($havingClauses) ? 'HAVING ' . implode(' AND ', $havingClauses) : '';

    $sql = "
        SELECT p.*,
               MIN(pp.price) AS min_price,
               COUNT(DISTINCT pp.store_id) AS store_count,
               MAX(pp.last_updated) AS last_updated,
               c.name AS category_name
        FROM products p
        LEFT JOIN product_prices pp ON p.id = pp.product_id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY p.id
        $having
        ORDER BY $orderBy
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get latest updated products
 */
function getLatestProducts(int $limit = 8): array
{
    $pdo = getDB();
    $limit = max(1, (int) $limit);
    $stmt = $pdo->query("
        SELECT p.*,
               MIN(pp.price) AS min_price,
               COUNT(DISTINCT pp.store_id) AS store_count,
               MAX(pp.last_updated) AS last_updated,
               c.name AS category_name
        FROM products p
        LEFT JOIN product_prices pp ON p.id = pp.product_id
        LEFT JOIN categories c ON p.category_id = c.id
        GROUP BY p.id
        ORDER BY COALESCE(MAX(pp.last_updated), p.created_at) DESC
        LIMIT {$limit}
    ");
    return $stmt->fetchAll();
}

/**
 * Get all stores
 */
function getStores(): array
{
    $pdo = getDB();
    return $pdo->query("SELECT * FROM stores ORDER BY name ASC")->fetchAll();
}

/**
 * Sanitize output
 */
function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate slug
 */
function makeSlug(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

/**
 * Redirect: pass relative path (e.g. 'admin/dashboard.php') or full URL.
 * Relative paths are prefixed with BASE_PATH via url().
 */
function redirect(string $url): void
{
    if (preg_match('#^https?://#', $url)) {
        header("Location: $url");
        exit;
    }
    $base = rtrim(BASE_PATH, '/');
    if ($base !== '' && strpos($url, $base) === 0) {
        header("Location: $url");
        exit;
    }
    header("Location: " . url($url));
    exit;
}

/**
 * Get stock badge HTML (uses t() for text when lang is loaded)
 */
function stockBadge(string $status): string
{
    $label = function_exists('t') ? match ($status) {
        'in_stock' => t('in_stock'),
        'out_of_stock' => t('out_of_stock'),
        'limited' => t('limited'),
        default => 'Unknown',
    } : match ($status) {
        'in_stock' => 'In Stock',
        'out_of_stock' => 'Out of Stock',
        'limited' => 'Limited',
        default => 'Unknown',
    };
    $class = match ($status) {
        'in_stock' => 'badge bg-success',
        'out_of_stock' => 'badge bg-danger',
        'limited' => 'badge bg-warning text-dark',
        default => 'badge bg-secondary',
    };
    return '<span class="' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Upload product image
 */
function uploadProductImage(array $file): string|false
{
    $uploadDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0755, true);

    // Use finfo for reliable MIME detection (not spoofable like $_FILES['type'])
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed))
        return false;
    if ($file['size'] > 5 * 1024 * 1024)
        return false;

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg',
    };
    $filename = uniqid('prod_') . '.' . $ext;
    $dest = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return $filename;
    }
    return false;
}

/**
 * Upload store logo
 */
function uploadStoreLogo(array $file): string|false
{
    $uploadDir = __DIR__ . '/../uploads/stores/';
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0755, true);

    // Use finfo for reliable MIME detection
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!in_array($mime, $allowed))
        return false;
    if ($file['size'] > 2 * 1024 * 1024)
        return false;

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        default => 'png',
    };
    $filename = uniqid('store_') . '.' . $ext;
    $dest = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return $filename;
    }
    return false;
}

/**
 * Get base URL
 */
function baseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // Detect subfolder
    $dir = dirname($script);
    $dir = rtrim($dir, '/');
    // Remove /admin or /pages suffix
    $dir = preg_replace('#/(admin|pages)$#', '', $dir);
    return $protocol . '://' . $host . $dir;
}

/**
 * Check if admin is logged in
 */
function isAdminLoggedIn(): bool
{
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Require admin login
 */
function requireAdminLogin(): void
{
    if (!isAdminLoggedIn()) {
        redirect(url('admin/login.php'));
    }
}
