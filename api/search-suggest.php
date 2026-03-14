<?php
/**
 * Search Suggestions API - compare.lk
 */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$pdo = getDB();
$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.brand, c.name AS category_name
    FROM products p
    JOIN categories c ON p.category_id = c.id
    WHERE p.name LIKE ? OR p.brand LIKE ? OR p.model LIKE ?
    LIMIT 8
");
$kw = '%' . $q . '%';
$stmt->execute([$kw, $kw, $kw]);
$results = $stmt->fetchAll();

echo json_encode(array_map(fn($r) => [
    'id' => $r['id'],
    'name' => $r['brand'] ? $r['brand'] . ' ' . $r['name'] : $r['name'],
    'category_name' => $r['category_name'],
], $results));
