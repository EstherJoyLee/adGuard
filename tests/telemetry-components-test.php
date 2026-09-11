<?php
/** Phase 1 component contract: bounded schema 4 values and fail-open JSONL I/O. */

require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/Telemetry/RequestTelemetry.php';
require_once dirname(__DIR__) . '/src/Telemetry/ResponseTelemetry.php';
require_once dirname(__DIR__) . '/src/Telemetry/TelemetryEvent.php';
require_once dirname(__DIR__) . '/src/Storage/LocalEventStore.php';

class TelemetryReadOnlyStream
{
    public $context;

    public function url_stat($path, $flags)
    {
        return array(
            0 => 0, 1 => 0, 2 => 0040555, 3 => 0, 4 => 0, 5 => 0, 6 => 0,
            7 => 0, 8 => time(), 9 => time(), 10 => time(), 11 => -1, 12 => -1,
            'dev' => 0, 'ino' => 0, 'mode' => 0040555, 'nlink' => 0,
            'uid' => 0, 'gid' => 0, 'rdev' => 0, 'size' => 0,
            'atime' => time(), 'mtime' => time(), 'ctime' => time(),
            'blksize' => -1, 'blocks' => -1,
        );
    }

    public function stream_open($path, $mode, $options, &$openedPath)
    {
        return false;
    }
}

$failures = array();
function telemetry_component_assert(&$failures, $label, $condition)
{
    echo ($condition ? 'ok:   ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures[] = $label;
    }
}

function telemetry_component_remove_tree($path)
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach ((array)glob(rtrim($path, '/\\') . '/*') as $child) {
        telemetry_component_remove_tree($child);
    }
    foreach ((array)glob(rtrim($path, '/\\') . '/.*') as $child) {
        if (basename($child) !== '.' && basename($child) !== '..') {
            telemetry_component_remove_tree($child);
        }
    }
    @rmdir($path);
}

function telemetry_component_config($base, $overrides)
{
    $path = $base . '/guard-' . uniqid('', true) . '.php';
    file_put_contents($path, '<?php return ' . var_export($overrides, true) . ';');
    return \AdGuard\Config::load($path);
}

$base = sys_get_temp_dir() . '/adguard-telemetry-components-' . uniqid('', true);
@mkdir($base, 0700, true);
$logPath = $base . '/logs';
$config = telemetry_component_config($base, array(
    'analytics' => array('site_id' => 'project-x'),
    'identity' => array('trusted_proxies' => array('203.0.113.10')),
    'logging' => array(
        'path' => $logPath,
        'hmac_key' => 'component-test-key',
        'hmac_key_path' => $base . '/hmac-key',
    ),
    'telemetry' => array(
        'max_event_bytes' => 16384,
        'max_header_bytes' => 128,
        'max_query_keys' => 3,
        'max_query_key_bytes' => 16,
        'lock_timeout_ms' => 2,
    ),
));

$server = array(
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/checkout.php?page=secret-value&empty=&second=hidden&third=nope&fourth=drop',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'HTTP_HOST' => 'example.test',
    'REMOTE_ADDR' => '203.0.113.10',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.8',
    'HTTP_USER_AGENT' => str_repeat('U', 2048),
    'HTTP_ACCEPT' => 'text/html',
    'HTTP_ACCEPT_LANGUAGE' => 'ko-KR',
    'HTTP_REFERER' => 'https://referrer.test/private?token=never-store',
    'HTTP_ORIGIN' => 'https://origin.test',
    'HTTP_SEC_FETCH_SITE' => 'same-origin',
    'HTTP_SEC_FETCH_MODE' => 'navigate',
    'HTTP_SEC_FETCH_DEST' => 'document',
    'HTTP_SEC_FETCH_USER' => '?1',
    'HTTP_AUTHORIZATION' => 'Bearer never-store-authorization',
    'HTTP_COOKIE' => 'session=never-store-cookie',
    'HTTP_X_SECRET_HEADER' => 'never-store-generic',
);
$cookies = array('__rek_id' => 'visitor-raw-never-store', 'session' => 'never-store-session');
$request = new \AdGuard\Telemetry\RequestTelemetry($config, $server, $cookies, 1000.25);
$decision = array(
    'engine_level' => 'NORMAL',
    'score' => 0,
    'action' => 'ALLOW',
    'policy_reason' => 'risk_below_deny_threshold',
    'ads_allowed' => true,
    'degraded' => false,
    'reasons' => array(),
    'signals' => array(
        'user_agent' => array(
            'score' => 0,
            'triggered' => false,
            'metrics' => array(
                'crawler_status' => 'verified',
                'crawler_vendor' => 'legacy-vendor',
                'crawler_group' => 'legacy-group',
                'user_agent' => 'must-not-be-copied-from-metrics',
            ),
        ),
    ),
);
$meta = array(
    'route_group' => 'checkout',
    'redirect_rule_id' => 'rule-7',
    'traffic_source_group' => 'search',
    'adsense_detected' => false,
    'ad_opportunity' => false,
    'ads_served' => false,
);
$response = new \AdGuard\Telemetry\ResponseTelemetry($decision, $meta, null, 12.5, 321, 0.7);
$event = new \AdGuard\Telemetry\TelemetryEvent(
    $request,
    'project-x',
    str_repeat('a', 32),
    str_repeat('b', 24)
);
$event->complete($response, '2.0.0-phase1', 'legacy-v1');
$record = $event->toArray();
$recordAgain = $event->toArray();
$json = json_encode($record, JSON_UNESCAPED_SLASHES);

telemetry_component_assert($failures, 'schema 4 and event identity remain stable',
    $record['schema_version'] === 4
    && $record['event_type'] === 'request'
    && $recordAgain['event_id'] === str_repeat('a', 32)
    && $recordAgain['request_id'] === str_repeat('b', 24));
telemetry_component_assert($failures, 'request timestamp preserves milliseconds in UTC',
    $record['occurred_at_utc'] === '1970-01-01T00:16:40.250Z');
telemetry_component_assert($failures, 'query keys are bounded and values are absent',
    $record['request']['query_keys'] === array('page', 'empty', 'second')
    && strpos($json, 'secret-value') === false
    && strpos($json, 'hidden') === false);
telemetry_component_assert($failures, 'only allowlisted bounded headers are stored',
    strlen($record['headers']['user_agent']) === 128
    && isset($record['headers']['accept'], $record['headers']['x_forwarded_for'])
    && strpos($json, 'never-store-authorization') === false
    && strpos($json, 'never-store-cookie') === false
    && strpos($json, 'never-store-generic') === false
    && strpos($json, 'never-store-session') === false);
telemetry_component_assert($failures, 'raw and canonical IP evidence plus hashes are preserved',
    $record['network']['peer_ip'] === '203.0.113.10'
    && $record['network']['raw_ip'] === '198.51.100.8'
    && $record['network']['client_ip'] === '198.51.100.8'
    && $record['network']['ip_hmac'] === hash_hmac('sha256', '198.51.100.8', 'component-test-key')
    && $record['network']['visitor_hmac'] === hash_hmac('sha256', 'visitor-raw-never-store', 'component-test-key'));
telemetry_component_assert($failures, 'Phase 2 identity evidence remains explicitly uncollected',
    $record['network']['proxy_trusted'] === null
    && $record['network']['forwarded_chain'] === null);
telemetry_component_assert($failures, 'unknown response status remains null beside real zero risk',
    $record['response']['status'] === null
    && $record['risk']['score'] === 0
    && $record['risk']['degraded'] === false);
telemetry_component_assert($failures, 'legacy crawler evidence is isolated without FCrDNS promotion',
    $record['bot']['verification_status'] === null
    && $record['bot']['verification_level'] === null
    && $record['bot']['fcrdns'] === null
    && $record['bot']['legacy']['crawler_status'] === 'verified');
telemetry_component_assert($failures, 'advertising and stable analytics evidence are retained',
    $record['request']['route_group'] === 'checkout'
    && $record['request']['redirect_rule_id'] === 'rule-7'
    && $record['advertising']['ad_opportunity'] === false
    && $record['advertising']['ads_served'] === false);
telemetry_component_assert($failures, 'self-observability keeps current write duration unknown before append',
    $record['agent']['guard_duration_ms'] === 0.7
    && $record['agent']['telemetry_write_duration_ms'] === null
    && $record['agent']['state_error_count'] === null);

$store = new \AdGuard\Storage\LocalEventStore($config);
$write = $store->append($record);
$health = $store->getHealthMetrics();
telemetry_component_assert($failures, 'valid event is appended with measurable bounded health result',
    $write['written'] === true
    && $write['dropped'] === false
    && $write['reason'] === ''
    && is_float($write['duration_ms'])
    && $write['duration_ms'] >= 0.0
    && $health['dropped_event_count'] === 0
    && $health['storage_error_count'] === 0);

$dailyFiles = glob($logPath . '/ad-guard-*.jsonl');
$storedLine = is_array($dailyFiles) && count($dailyFiles) === 1
    ? trim((string)file_get_contents($dailyFiles[0])) : '';
$stored = json_decode($storedLine, true);
telemetry_component_assert($failures, 'stored JSONL line is the same schema 4 event',
    is_array($stored) && $stored['event_id'] === str_repeat('a', 32));

file_put_contents($dailyFiles[0], "{malformed-existing\n", FILE_APPEND);
$secondWrite = $store->append($record);
telemetry_component_assert($failures, 'malformed existing JSONL does not block append', $secondWrite['written'] === true);

$smallConfig = telemetry_component_config($base, array(
    'logging' => array('path' => $base . '/small-logs'),
    'telemetry' => array('max_event_bytes' => 1024),
));
$smallStore = new \AdGuard\Storage\LocalEventStore($smallConfig);
$oversized = $record;
$oversized['risk']['reasons'] = array(str_repeat('R', 5000));
$tooLarge = $smallStore->append($oversized);
telemetry_component_assert($failures, 'oversized event is dropped before file append',
    $tooLarge['written'] === false
    && $tooLarge['dropped'] === true
    && $tooLarge['reason'] === 'event_too_large');

$blockedPath = $base . '/blocked-parent';
file_put_contents($blockedPath, 'not a directory');
$blockedConfig = telemetry_component_config($base, array(
    'logging' => array('path' => $blockedPath . '/logs'),
));
$blockedStore = new \AdGuard\Storage\LocalEventStore($blockedConfig);
$blockedStart = microtime(true);
$blocked = $blockedStore->append($record);
$blockedElapsed = (microtime(true) - $blockedStart) * 1000;
telemetry_component_assert($failures, 'directory/open failure drops quickly without throwing',
    $blocked['written'] === false
    && $blocked['dropped'] === true
    && in_array($blocked['reason'], array('directory_unavailable', 'open_failed'), true)
    && $blockedElapsed < 100.0);

$readOnlyRegistered = @stream_wrapper_register('adguardreadonly', 'TelemetryReadOnlyStream');
if ($readOnlyRegistered) {
    $readOnlyConfig = telemetry_component_config($base, array(
        'logging' => array('path' => 'adguardreadonly://logs'),
    ));
    $readOnlyStore = new \AdGuard\Storage\LocalEventStore($readOnlyConfig);
    $readOnlyResult = $readOnlyStore->append($record);
    telemetry_component_assert($failures, 'read-only storage drops telemetry without throwing',
        $readOnlyResult['dropped'] === true && $readOnlyResult['reason'] === 'open_failed');
    stream_wrapper_unregister('adguardreadonly');
} else {
    telemetry_component_assert($failures, 'read-only storage drops telemetry without throwing', false);
}

$lockDir = $base . '/lock-logs';
@mkdir($lockDir, 0700, true);
$lockFile = $lockDir . '/ad-guard-' . gmdate('Y-m-d') . '.jsonl';
$lockHandle = fopen($lockFile, 'ab');
flock($lockHandle, LOCK_EX | LOCK_NB);
$lockConfig = telemetry_component_config($base, array(
    'logging' => array('path' => $lockDir),
    'telemetry' => array('lock_timeout_ms' => 2),
));
$lockStore = new \AdGuard\Storage\LocalEventStore($lockConfig);
$lockStart = microtime(true);
$locked = $lockStore->append($record);
$lockElapsed = (microtime(true) - $lockStart) * 1000;
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
telemetry_component_assert($failures, 'busy event lock has a short bounded wait and drop result',
    $locked['written'] === false
    && $locked['dropped'] === true
    && $locked['reason'] === 'lock_busy'
    && $lockElapsed < 100.0);

$lockHealth = $lockStore->getHealthMetrics();
telemetry_component_assert($failures, 'drop and storage error counters are request-bounded',
    $lockHealth['dropped_event_count'] === 1
    && $lockHealth['storage_error_count'] === 1
    && $lockHealth['last_error'] === 'lock_busy');

$healthCapDir = $base . '/health-cap-logs';
@mkdir($healthCapDir, 0700, true);
$healthCapConfig = telemetry_component_config($base, array(
    'logging' => array('path' => $healthCapDir, 'health_max_daily_bytes' => 512),
    'telemetry' => array('max_event_bytes' => 1024),
));
$healthCapStore = new \AdGuard\Storage\LocalEventStore($healthCapConfig);
for ($i = 0; $i < 20; $i++) {
    $healthCapStore->append($oversized);
}
$healthFiles = glob($healthCapDir . '/ad-guard-*-health.jsonl');
$healthSize = is_array($healthFiles) && count($healthFiles) === 1 ? filesize($healthFiles[0]) : 0;
telemetry_component_assert($failures, 'configured health byte cap is enforced on the bytes written',
    $healthSize > 0 && $healthSize <= 512);

$oversizedKeyPath = $base . '/oversized-hmac-key';
file_put_contents($oversizedKeyPath, str_repeat('K', 4096));
$oversizedKeyConfig = telemetry_component_config($base, array(
    'logging' => array(
        'path' => $base . '/oversized-key-logs',
        'hmac_key' => '',
        'hmac_key_path' => $oversizedKeyPath,
    ),
));
$oversizedKeyRequest = new \AdGuard\Telemetry\RequestTelemetry(
    $oversizedKeyConfig,
    array('REMOTE_ADDR' => '198.51.100.9', 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET'),
    array(),
    1000.0
);
$oversizedKeyNetwork = $oversizedKeyRequest->toArray();
telemetry_component_assert($failures, 'oversized HMAC key file is rejected by a bounded read',
    $oversizedKeyNetwork['network']['ip_hmac'] === '');

$retentionDir = $base . '/retention-logs';
@mkdir($retentionDir, 0700, true);
$expiredLog = $retentionDir . '/ad-guard-2000-01-01.jsonl';
file_put_contents($expiredLog, "{}\n");
file_put_contents($retentionDir . '/unrelated-file.txt', 'keep');
$invalidDateLog = $retentionDir . '/ad-guard-2000-99-99.jsonl';
file_put_contents($invalidDateLog, "{}\n");
$retentionConfig = telemetry_component_config($base, array(
    'logging' => array('path' => $retentionDir, 'retention_days' => 1),
    'telemetry' => array('retention_scan_limit' => 16),
));
$retentionStore = new \AdGuard\Storage\LocalEventStore($retentionConfig);
$retentionResult = $retentionStore->append($record);
telemetry_component_assert($failures, 'bounded retention removes expired event logs only',
    $retentionResult['written'] === true
    && !file_exists($expiredLog)
    && is_file($retentionDir . '/unrelated-file.txt')
    && is_file($invalidDateLog));

telemetry_component_remove_tree($base);

if ($failures) {
    echo "\n" . count($failures) . " telemetry component failure(s).\n";
    exit(1);
}
echo "\nTelemetry component tests passed.\n";
