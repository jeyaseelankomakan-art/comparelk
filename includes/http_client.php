<?php

require_once __DIR__ . '/functions.php';

/**
 * Perform a simple HTTP GET request via cURL with consistent defaults.
 * Includes automatic WAF/bot-challenge bypass for known Sri Lankan stores.
 *
 * @param string $url
 * @param array $options Supported keys: timeout, connect_timeout, max_redirs, user_agent, headers, encoding, bypass_waf
 * @return array{ok:bool, body:string, http_code:int, error:string, bypassed:string|null}
 */
function httpFetch(string $url, array $options = []): array
{
    $timeout        = (int)($options['timeout'] ?? 20);
    $connectTimeout = (int)($options['connect_timeout'] ?? 8);
    $maxRedirs      = (int)($options['max_redirs'] ?? 5);
    $userAgent      = (string)($options['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
    $headers        = (array)($options['headers'] ?? []);
    $encoding       = (string)($options['encoding'] ?? '');
    $bypassWaf      = (bool)($options['bypass_waf'] ?? true); // default ON

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

    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    $bypassed = null;

    // ── WAF Bypass: Softlogic / Zenedge bot challenge ───────────────────────
    // Zenedge serves a tiny HTML page with a JS cookie calculation instead of
    // the real content.  Detect it and solve the challenge automatically.
    if ($bypassWaf && is_string($body) && strlen($body) < 2000
        && strpos($body, '__zjc') !== false
        && preg_match('/var\s+v\s*=\s*([\d.]+)\s*\*\s*([\d.]+);/', $body, $m)
    ) {
        $v1 = (float)$m[1];
        $v2 = (float)$m[2];
        $cookieVal  = floor($v1 * $v2);
        $cookieName = 'zjc_session';
        if (preg_match('/__zjc(\d+)/', $body, $cm)) {
            $cookieName = '__zjc' . $cm[1];
        }

        // Use a temp cookie jar so Zenedge session cookies persist across
        // the activation hit and the final product page fetch.
        $cookieJar = tempnam(sys_get_temp_dir(), 'cl_zenedge_');
        curl_setopt($ch, CURLOPT_COOKIEJAR,  $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);

        // Seed the computed cookie
        curl_setopt($ch, CURLOPT_COOKIE, "$cookieName=$cookieVal");

        // Activation hit to root domain to establish session
        $parsedHost = parse_url($url, PHP_URL_HOST);
        $rootUrl    = 'https://' . $parsedHost . '/';
        curl_setopt($ch, CURLOPT_URL, $rootUrl);
        curl_exec($ch); // discard result — server may set additional session cookies

        // Re-fetch the actual URL — cookie jar now contains all session cookies
        curl_setopt($ch, CURLOPT_URL, $url);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        $bypassed = 'zenedge';

        // Clean up temp file
        @unlink($cookieJar);
    }

    // ── WAF Bypass: BuyAbans / Cloudflare-style challenge ───────────────────
    // BuyAbans sometimes serves a 403 with a Cloudflare "checking your browser"
    // interstitial.  We retry once with an Accept header mimicking a real browser
    // and a Referer from the same domain. This won't solve JS challenges but
    // handles the simpler "managed challenge" / rate-limit 403s.
    if ($bypassWaf && is_string($body) && $httpCode === 403
        && (stripos($body, 'cloudflare') !== false || stripos($body, 'cf-browser-verification') !== false || stripos($body, 'buyabans') !== false)
    ) {
        $parsedHost = parse_url($url, PHP_URL_HOST);
        $extraHeaders = array_merge($headers, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: https://' . $parsedHost . '/',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
            'Cache-Control: no-cache',
        ]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
        curl_setopt($ch, CURLOPT_URL, $url);

        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        if ($httpCode < 400) {
            $bypassed = 'cloudflare-retry';
        }
    }

    curl_close($ch);

    $ok = is_string($body) && $body !== false && $err === '' && $httpCode < 400;

    return [
        'ok'        => $ok,
        'body'      => is_string($body) ? $body : '',
        'http_code' => $httpCode,
        'error'     => $err,
        'bypassed'  => $bypassed,
    ];
}