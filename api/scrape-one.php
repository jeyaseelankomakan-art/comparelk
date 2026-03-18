<?php
/**
 * AJAX endpoint: scrape a single product_store_link by id.
 * POST /api/scrape-one.php  { id: <int>, _csrf: <token> }
 * Returns JSON: { status, price, message }
 */
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraper.php';

// Must be admin
if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// CSRF verification
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF check failed']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid id']);
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM product_store_links WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$link = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$link) {
    echo json_encode(['status' => 'error', 'message' => 'Link not found']);
    exit;
}

// Each call gets its own generous time budget
set_time_limit(60);

$result = scrapeProductStoreLink($pdo, $link);
echo json_encode($result);
