<?php

/**
 * Database Connection - compare.lk
 * Uses PDO with prepared statements for security.
 *
 * Environment variable overrides:
 * - COMPARE_DB_HOST
 * - COMPARE_DB_NAME
 * - COMPARE_DB_USER
 * - COMPARE_DB_PASS
 * - COMPARE_DB_CHARSET
 */

function envOrDefault(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : (string) $value;
}

define('DB_HOST', envOrDefault('COMPARE_DB_HOST', 'localhost'));
define('DB_NAME', envOrDefault('COMPARE_DB_NAME', 'comparelk'));
define('DB_USER', envOrDefault('COMPARE_DB_USER', 'root'));
define('DB_PASS', envOrDefault('COMPARE_DB_PASS', ''));
define('DB_CHARSET', envOrDefault('COMPARE_DB_CHARSET', 'utf8mb4'));

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('<div style="font-family:sans-serif;padding:2rem;color:#c00;">'
                . '<h2>Database Connection Failed</h2>'
                . '<p>Please contact the site administrator.</p>'
                . '</div>');
        }
    }
    return $pdo;
}
