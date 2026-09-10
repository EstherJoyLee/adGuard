<?php
/**
 * Fetch a read-only AdSense Management API v2 aggregate report.
 *
 * Required environment:
 *   ADSENSE_ACCOUNT=accounts/pub-123... (or pub-123...)
 * and either:
 *   ADSENSE_ACCESS_TOKEN=short-lived-token
 * or all three refresh credentials:
 *   ADSENSE_CLIENT_ID, ADSENSE_CLIENT_SECRET, ADSENSE_REFRESH_TOKEN
 *
 * Usage:
 *   php adguard/tools/fetch-adsense-report.php 2026-08-01 2026-08-31
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/AdsenseReport.php';

function ad_guard_adsense_http($method, $url, $headers, $body)
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        if ($body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            throw new RuntimeException('HTTP transport failed: ' . $error);
        }
        return array($status, $response);
    }

    $headerText = implode("\r\n", $headers);
    $options = array('http' => array(
        'method' => $method,
        'header' => $headerText,
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 60,
    ));
    $response = @file_get_contents($url, false, stream_context_create($options));
    $status = 0;
    $scope = get_defined_vars();
    $responseHeaders = isset($scope['http_response_header']) ? $scope['http_response_header'] : array();
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match)) {
        $status = (int)$match[1];
    }
    if ($response === false) {
        throw new RuntimeException('HTTP transport failed; enable cURL or allow_url_fopen.');
    }
    return array($status, $response);
}

function ad_guard_adsense_token()
{
    $token = getenv('ADSENSE_ACCESS_TOKEN');
    if ($token !== false && trim($token) !== '') {
        return trim($token);
    }
    $clientId = getenv('ADSENSE_CLIENT_ID');
    $clientSecret = getenv('ADSENSE_CLIENT_SECRET');
    $refreshToken = getenv('ADSENSE_REFRESH_TOKEN');
    if (!$clientId || !$clientSecret || !$refreshToken) {
        throw new RuntimeException('Set ADSENSE_ACCESS_TOKEN or the three refresh credential environment variables.');
    }
    $body = http_build_query(array(
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ), '', '&');
    list($status, $response) = ad_guard_adsense_http(
        'POST',
        'https://oauth2.googleapis.com/token',
        array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'),
        $body
    );
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
        throw new RuntimeException('OAuth token refresh failed with HTTP ' . $status . '.');
    }
    return (string)$decoded['access_token'];
}

function ad_guard_adsense_query($pairs)
{
    $parts = array();
    foreach ($pairs as $key => $values) {
        foreach ((array)$values as $value) {
            $parts[] = rawurlencode($key) . '=' . rawurlencode((string)$value);
        }
    }
    return implode('&', $parts);
}

$start = isset($argv[1]) ? (string)$argv[1] : '';
$end = isset($argv[2]) ? (string)$argv[2] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $end)) {
    fwrite(STDERR, "Usage: php adguard/tools/fetch-adsense-report.php YYYY-MM-DD YYYY-MM-DD\n");
    exit(2);
}

try {
    $account = trim((string)getenv('ADSENSE_ACCOUNT'));
    if ($account === '') {
        throw new RuntimeException('Set ADSENSE_ACCOUNT.');
    }
    if (strpos($account, 'accounts/') !== 0) {
        $account = 'accounts/' . $account;
    }
    if (!preg_match('#^accounts/[A-Za-z0-9_-]+$#D', $account)) {
        throw new RuntimeException('Invalid ADSENSE_ACCOUNT format.');
    }
    $token = ad_guard_adsense_token();
    $query = ad_guard_adsense_query(array(
        'startDate.year' => substr($start, 0, 4),
        'startDate.month' => (int)substr($start, 5, 2),
        'startDate.day' => (int)substr($start, 8, 2),
        'endDate.year' => substr($end, 0, 4),
        'endDate.month' => (int)substr($end, 5, 2),
        'endDate.day' => (int)substr($end, 8, 2),
        'dimensions' => array('DATE', 'PAGE_URL'),
        'metrics' => array('CLICKS', 'IMPRESSIONS', 'PAGE_VIEWS', 'ESTIMATED_EARNINGS'),
        'reportingTimeZone' => 'ACCOUNT_TIME_ZONE',
        'languageCode' => 'en-US',
        'limit' => 100000,
    ));
    $url = 'https://adsense.googleapis.com/v2/' . $account . '/reports:generate?' . $query;
    list($status, $response) = ad_guard_adsense_http(
        'GET',
        $url,
        array('Authorization: Bearer ' . $token, 'Accept: application/json'),
        ''
    );
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        $message = is_array($decoded) && isset($decoded['error']['message'])
            ? (string)$decoded['error']['message'] : 'unknown API error';
        throw new RuntimeException('AdSense API HTTP ' . $status . ': ' . $message);
    }
    $snapshot = \AdGuard\AdsenseReport::fromDecoded($decoded, 'adsense-api:' . $account);
    $config = \AdGuard\Config::load();
    $output = \AdGuard\AdsenseReport::saveSnapshot(
        $snapshot,
        (string)$config->get('analytics.report_path')
    );
    $matched = isset($decoded['totalMatchedRows']) ? (int)$decoded['totalMatchedRows'] : count($snapshot['rows']);
    echo "Fetched AdSense aggregate report with read-only OAuth.\n";
    echo "rows: " . count($snapshot['rows']) . " / matched: " . $matched . "\n";
    echo "saved: " . $output . "\n";
    if ($matched > count($snapshot['rows'])) {
        echo "WARNING: report was truncated; narrow the date range.\n";
    }
    echo "Important: Google does not return click-level IPs in this report.\n";
} catch (Exception $e) {
    fwrite(STDERR, "Fetch failed: " . $e->getMessage() . "\n");
    exit(1);
}
