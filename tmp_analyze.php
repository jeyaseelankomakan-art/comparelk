<?php
$html = file_get_contents('C:\wamp64\www\compare.lk\singer_test.html');
$dom = new DOMDocument();
@$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);

$products = $xpath->query('//*[contains(@class, "product")]');
echo "Found " . $products->length . " elements with class product.\n";

$count = 0;
foreach ($products as $p) {
    echo "Class: " . $p->getAttribute('class') . "\n";
    // Get text content truncated
    $text = trim(preg_replace('/\s+/', ' ', $p->nodeValue));
    echo "Text: " . substr($text, 0, 150) . "\n---\n";
    if (++$count > 5) break;
}
