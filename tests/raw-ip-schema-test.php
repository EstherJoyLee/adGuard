<?php
/**
 * Contract test for the schema 4 audit boundary: raw client IP is retained beside
 * canonical/HMAC forms, and forwarded headers are accepted only from a
 * configured trusted proxy.
 */

require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/DecisionLogger.php';

$failures = array();
function raw_ip_schema_assert(&$failures, $label, $condition)
{
    if ($condition) {
        echo "ok:   " . $label . "\n";
        return;
    }
    $failures[] = $label;
    echo "FAIL: " . $label . "\n";
}

$base = sys_get_temp_dir() . '/ad-guard-raw-ip-' . uniqid('', true);
@mkdir($base, 0700, true);
$configPath = $base . '/guard.php';
$source = '<?php return ' . var_export(array(
    'logging' => array(
        'enabled' => true,
        'path' => $base . '/logs',
        'hmac_key' => 'test-hmac-key',
        'hmac_key_path' => $base . '/hmac-key',
    ),
    'identity' => array('trusted_proxies' => array('203.0.113.10')),
), true) . ';';
file_put_contents($configPath, $source);
$config = \AdGuard\Config::load($configPath);
$logger = new \AdGuard\DecisionLogger($config);
$decision = array(
    'engine_level' => 'NORMAL',
    'score' => 3,
    'action' => 'ALLOW',
    'policy_reason' => 'risk_below_deny_threshold',
    'ads_allowed' => true,
    'degraded' => false,
    'signals' => array(),
    'reasons' => array(),
);

$_COOKIE = array('__rek_id' => 'raw-ip-test-visitor');
$_SERVER = array(
    'REQUEST_URI' => '/index.php',
    'REQUEST_METHOD' => 'GET',
    'REMOTE_ADDR' => '198.51.100.7',
    'HTTP_X_FORWARDED_FOR' => '192.0.2.44',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120.0 Safari/537.36',
);
raw_ip_schema_assert($failures, 'untrusted forwarded header is ignored', $logger->log($decision, array('adsense_detected' => true)));

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.8';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; TestBot/1.0)';
raw_ip_schema_assert($failures, 'trusted proxy forwarded address is logged', $logger->log($decision, array('adsense_detected' => true)));

$_SERVER['REMOTE_ADDR'] = '::ffff:198.51.100.8';
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
raw_ip_schema_assert($failures, 'IPv4-mapped IPv6 address is logged', $logger->log($decision, array('adsense_detected' => true)));

$files = glob($base . '/logs/ad-guard-*.jsonl');
$records = array();
if (is_array($files)) {
    foreach ($files as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }
    }
}
raw_ip_schema_assert($failures, 'three schema 4 records were written', count($records) === 3);
if (count($records) === 3) {
    raw_ip_schema_assert($failures, 'schema version is 4', $records[0]['schema_version'] === 4);
    raw_ip_schema_assert($failures, 'raw IP preserves direct peer', $records[0]['network']['raw_ip'] === '198.51.100.7');
    raw_ip_schema_assert($failures, 'untrusted XFF does not change canonical IP', $records[0]['network']['client_ip'] === '198.51.100.7');
    raw_ip_schema_assert($failures, 'trusted XFF selects client address', $records[1]['network']['raw_ip'] === '198.51.100.8');
    raw_ip_schema_assert($failures, 'mapped IPv6 canonicalizes to IPv4', $records[2]['network']['client_ip'] === '198.51.100.8');
    raw_ip_schema_assert($failures, 'IP HMAC is retained beside raw IP', $records[1]['network']['ip_hmac'] === hash_hmac('sha256', '198.51.100.8', 'test-hmac-key'));
    raw_ip_schema_assert($failures, 'mapped IPv6 shares the canonical IP HMAC', $records[2]['network']['ip_hmac'] === $records[1]['network']['ip_hmac']);
    raw_ip_schema_assert($failures, 'full user agent is retained', strpos($records[0]['headers']['user_agent'], 'Chrome/120.0') !== false);
    raw_ip_schema_assert($failures, 'request is classified as an ad opportunity document', $records[0]['request']['request_type'] === 'document' && $records[0]['advertising']['ad_opportunity'] === true);
}

foreach (array($configPath, $base . '/hmac-key') as $file) {
    @unlink($file);
}
if (is_dir($base . '/logs')) {
    foreach ((array)glob($base . '/logs/*') as $file) {
        @unlink($file);
    }
    @rmdir($base . '/logs');
}
@rmdir($base);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nRaw IP schema test passed.\n";
