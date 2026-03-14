<?php
/**
 * Site Configuration - compare.lk
 * 
 * Set BASE_PATH to your project folder path.
 * Examples:
 *   ''                    = installed at document root (e.g. C:\wamp64\www\comparelk)
 *   '/comparelk'          = installed in subfolder comparelk
 *   '/New folder/comparelk' = installed in New folder/comparelk
 */
// BASE_PATH can be overridden via environment variable COMPARE_BASE_PATH.
// Otherwise, it is auto-detected from the current request.
if (!defined('BASE_PATH')) {
    $override = getenv('COMPARE_BASE_PATH');
    if ($override !== false && $override !== '') {
        define('BASE_PATH', rtrim((string)$override, '/'));
    } else {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        // If the entry script is in a common subfolder, strip it to get project root base path.
        // Example: /compare.lk/pages/about.php -> /compare.lk
        $base = preg_replace('#/(admin|pages|api|includes|cron)$#', '', $base);
        if ($base === '/' || $base === '.' || $base === '\\') {
            $base = '';
        }
        define('BASE_PATH', $base);
    }
}

/**
 * Get full URL path (for links, assets, redirects)
 */
function url(string $path = ''): string {
    $base = rtrim(BASE_PATH, '/');
    $path = ltrim($path, '/');
    return $base . ($path ? '/' . $path : '');
}
