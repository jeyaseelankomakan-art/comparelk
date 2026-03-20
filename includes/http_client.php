<?php

require_once __DIR__ . '/functions.php';

/**
 * Perform a simple HTTP GET request via cURL with consistent defaults.
 *
 * @param string $url
 * @param array $options Supported keys: timeout, connect_timeout, max_redirs, user_agent, headers, encoding
 * @return array{ok:bool, body:string, http_code:int, error:string}
 */
function httpFetch(string $url, array $options = []): array
{
    $timeout = (int)($options['timeout'] ?? 20);
    $connectTimeout = (int)($options['connect_timeout'] ?? 8);
    $maxRedirs = (int)($options['max_redirs'] ?? 5);
    $userAgent = (string)($options['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
    $headers = (array)($options['headers'] ?? []);
    $encoding = (string)($options['encoding'] ?? '');

    $verifyTls = shouldVerifyTls();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => $maxRedirs,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => $verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_ENCODING       => $encoding,
    ]);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $ok = is_string($body) && $body !== false && $err === '' && $httpCode < 400;

    return [
        'ok' => $ok,
        'body' => is_string($body) ? $body : '',
        'http_code' => $httpCode,
        'error' => $err,
    ];
}