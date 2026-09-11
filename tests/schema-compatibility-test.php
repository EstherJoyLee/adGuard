<?php
require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/LogReader.php';
require_once dirname(__DIR__) . '/src/RiskCorrelationAnalyzer.php';
require_once dirname(__DIR__) . '/src/Telemetry/EventNormalizer.php';

$compatFailures = array();
$compatBase = sys_get_temp_dir() . '/adguard-schema-compat-' . uniqid('', true);
$compatLogs = $compatBase . '/logs';
@mkdir($compatLogs, 0700, true);

function compat_assert(&$failures, $label, $condition)
{
    echo ($condition ? 'ok:   ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures[] = $label;
    }
}

function compat_remove_tree($path)
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $item = $path . DIRECTORY_SEPARATOR . $name;
        if (is_dir($item)) {
            compat_remove_tree($item);
        } else {
            @unlink($item);
        }
    }
    @rmdir($path);
}

function compat_schema3()
{
    return array(
        'schema_version' => 3,
        'timestamp' => '2026-09-10T01:00:00+00:00',
        'request_id' => 'legacy-request',
        'site_id' => 'compat-site',
        'host' => 'compat.example',
        'path' => '/v3',
        'route_group' => 'legacy-route',
        'method' => 'GET',
        'raw_ip' => '198.51.100.31',
        'ip_canonical' => '198.51.100.31',
        'ip_hmac' => 'legacy-ip',
        'network_hmac' => 'legacy-network',
        'visitor_hmac' => 'legacy-visitor',
        'user_agent' => 'Googlebot/2.1',
        'ua_family' => 'googlebot',
        'crawler_status' => 'verified',
        'crawler_vendor' => 'google',
        'crawler_group' => 'search',
        'request_type' => 'document',
        'ad_opportunity' => true,
        'engine_level' => 'ELEVATED',
        'score' => 10,
        'action' => 'ALLOW',
        'policy_reason' => 'legacy-policy',
        'ads_allowed' => true,
        'degraded' => false,
        'ads_served' => true,
        'bootstrap_removed' => 0,
        'reasons' => array('legacy-reason'),
        'signals' => array(),
    );
}

function compat_schema4()
{
    return array(
        'schema_version' => 4,
        'event_type' => 'request',
        'event_id' => 'schema4-event',
        'request_id' => 'schema4-request',
        'project_id' => 'compat-site',
        'occurred_at_utc' => '2026-09-10T02:00:00.123Z',
        'request' => array(
            'method' => 'POST', 'host' => 'compat.example', 'path' => '/v4',
            'protocol' => 'HTTP/1.1', 'query_keys' => array('page'),
            'route_group' => 'schema4-route', 'redirect_rule_id' => '',
            'request_type' => 'document', 'is_document' => true,
            'referrer_host' => '', 'referrer_group' => '', 'country' => '', 'cf_ray' => '',
        ),
        'network' => array(
            'peer_ip' => '203.0.113.10', 'client_ip' => '198.51.100.32',
            'raw_ip' => '198.51.100.32', 'ip_source' => 'x_forwarded_for',
            'proxy_trusted' => null, 'forwarded_chain' => null,
            'resolution_status' => 'resolved', 'ip_hmac' => 'schema4-ip',
            'network_hmac' => 'schema4-network', 'visitor_hmac' => 'schema4-visitor',
        ),
        'headers' => array('user_agent' => 'Mozilla/5.0 Chrome/120.0'),
        'behavior' => array('ip_rate_10s' => null),
        'bot' => array(
            'claimed_identity' => null, 'provider' => null,
            'verification_status' => null, 'verification_level' => null,
            'fcrdns' => null, 'official_ip_range' => null, 'rfc9421' => null,
            'legacy' => array('crawler_status' => 'verified', 'crawler_vendor' => 'legacy', 'crawler_group' => 'legacy'),
        ),
        'risk' => array(
            'score' => null, 'level' => null, 'action' => null,
            'policy_reason' => null, 'signals' => array(), 'reasons' => array(),
            'ads_allowed' => null, 'degraded' => null, 'sample_rate' => 1.0,
        ),
        'response' => array('status' => 204, 'duration_ms' => 5.25, 'bytes' => 0),
        'advertising' => array(
            'ad_opportunity' => true, 'adsense_detected' => false,
            'bootstrap_removed' => 0, 'ads_served' => false,
            'external_suppression' => '',
            'ad_delivery' => array(
                'bootstrap' => array('opportunities' => 0, 'provided' => 0, 'blocked' => 0, 'missing' => 0),
                'manual_unit_count' => 0, 'manual_units' => array(), 'truncated' => false,
            ),
        ),
        'agent' => array(
            'version' => '2.0.0-phase1', 'rule_version' => 'legacy-v1',
            'guard_duration_ms' => 0.5, 'telemetry_write_duration_ms' => null,
            'dropped_event_count' => null, 'storage_error_count' => null, 'state_error_count' => null,
        ),
    );
}

$compatVersionless = array(
    'timestamp' => '2026-09-10T03:00:00+00:00',
    'site_id' => 'compat-site', 'host' => 'compat.example', 'path' => '/versionless',
    'route_group' => 'versionless-route', 'method' => 'GET',
    'engine_level' => 'NORMAL', 'score' => 0, 'action' => 'ALLOW',
    'ads_allowed' => true, 'ads_served' => true, 'signals' => array(), 'reasons' => array(),
);
$compatLines = array(
    json_encode(compat_schema3(), JSON_UNESCAPED_SLASHES),
    json_encode(compat_schema4(), JSON_UNESCAPED_SLASHES),
    json_encode($compatVersionless, JSON_UNESCAPED_SLASHES),
    json_encode(array('schema_version' => 1, 'event_type' => 'telemetry_health', 'reason' => 'open_failed')),
    '{malformed',
    json_encode(array('schema_version' => 99, 'timestamp' => '2026-09-10T04:00:00Z')),
    json_encode(array('schema_version' => 4, 'event_type' => 'request', 'request' => array())),
    str_repeat('X', 10000),
);
file_put_contents($compatLogs . '/ad-guard-2026-09-10.jsonl', implode("\n", $compatLines) . "\n");

$compatConfigPath = $compatBase . '/guard.php';
file_put_contents($compatConfigPath, '<?php return ' . var_export(array(
    'logging' => array('path' => $compatLogs),
    'telemetry' => array('max_event_bytes' => 4096),
    'analytics' => array('reporting_timezone' => 'UTC', 'site_id' => 'compat-site', 'baseline_days' => 3),
), true) . ';');
$compatConfig = \AdGuard\Config::load($compatConfigPath);

try {
    $compatReason = '';
    $compatLegacy = \AdGuard\Telemetry\EventNormalizer::normalize(compat_schema3(), $compatReason);
    compat_assert($compatFailures, 'schema 3 keeps legacy provenance and request meaning',
        is_array($compatLegacy)
        && $compatLegacy['_source_schema_version'] === 3
        && $compatLegacy['path'] === '/v3'
        && $compatLegacy['score'] === 10);
    compat_assert($compatFailures, 'legacy crawler verified is never promoted to FCrDNS PASS',
        $compatLegacy['crawler_status'] === 'verified'
        && $compatLegacy['fcrdns_status'] === null
        && $compatLegacy['bot_verification_status'] === null);

    $compatSchema4 = \AdGuard\Telemetry\EventNormalizer::normalize(compat_schema4(), $compatReason);
    compat_assert($compatFailures, 'schema 4 flattens for existing consumers and preserves IDs',
        is_array($compatSchema4)
        && $compatSchema4['_source_schema_version'] === 4
        && $compatSchema4['event_id'] === 'schema4-event'
        && $compatSchema4['path'] === '/v4'
        && $compatSchema4['response_status'] === 204);
    compat_assert($compatFailures, 'missing schema 4 risk evidence remains null',
        array_key_exists('score', $compatSchema4) && $compatSchema4['score'] === null
        && $compatSchema4['engine_level'] === null
        && $compatSchema4['bot_verification_status'] === null);

    $compatReader = new \AdGuard\LogReader($compatConfig);
    $compatResult = $compatReader->read(array('date_mode' => 'all'), 1, 100);
    compat_assert($compatFailures, 'only supported request records are counted once',
        $compatResult['total'] === 3 && $compatResult['summary']['total'] === 3);
    compat_assert($compatFailures, 'read diagnostics separate health, malformed, unsupported, and invalid records',
        $compatResult['read_health']['health'] === 1
        && $compatResult['read_health']['malformed'] === 2
        && $compatResult['read_health']['unsupported'] === 1
        && $compatResult['read_health']['invalid'] === 1);
    $compatNullRow = null;
    foreach ($compatResult['rows'] as $row) {
        if ($row['path'] === '/v4') {
            $compatNullRow = $row;
        }
    }
    compat_assert($compatFailures, 'viewer read model does not invent a zero score',
        is_array($compatNullRow) && array_key_exists('score', $compatNullRow) && $compatNullRow['score'] === null);

    $compatSnapshot = array(
        'source' => 'compat.csv',
        'rows' => array(array(
            'date' => '2026-09-10', 'page_url' => 'https://compat.example/v3',
            'domain' => 'compat.example', 'clicks' => 0, 'impressions' => 1,
            'page_views' => 1, 'estimated_earnings' => 0.0,
        )),
    );
    $compatAnalysis = (new \AdGuard\RiskCorrelationAnalyzer($compatConfig))
        ->analyze('2026-09-10', $compatSnapshot, $compatLogs);
    $compatAnalyzedRequests = 0;
    foreach ($compatAnalysis['groups'] as $group) {
        $compatAnalyzedRequests += $group['local']['ad_bearing_requests'];
    }
    compat_assert($compatFailures, 'correlation analyzer reads schema 3, schema 4, and versionless records once',
        $compatAnalyzedRequests === 3);
    compat_assert($compatFailures, 'correlation diagnostics use the shared skip categories',
        isset($compatAnalysis['local_read_health'])
        && $compatAnalysis['local_read_health']['health'] === 1
        && $compatAnalysis['local_read_health']['unsupported'] === 1);

    $compatOldConfig = getenv('AD_GUARD_CONFIG');
    putenv('AD_GUARD_CONFIG=' . $compatConfigPath);
    $compatCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/report.php')
        . ' 2026-09-10 ' . escapeshellarg($compatLogs);
    $compatOutput = array();
    $compatExit = 1;
    exec($compatCommand, $compatOutput, $compatExit);
    putenv($compatOldConfig === false ? 'AD_GUARD_CONFIG' : 'AD_GUARD_CONFIG=' . $compatOldConfig);
    $compatReport = implode("\n", $compatOutput);
    compat_assert($compatFailures, 'CLI report reads the same three compatible request records',
        $compatExit === 0 && strpos($compatReport, 'records: 3 ') !== false);
} finally {
    compat_remove_tree($compatBase);
}

if ($compatFailures) {
    echo "\n" . count($compatFailures) . " schema compatibility failure(s).\n";
    exit(1);
}
echo "\nSchema compatibility tests passed.\n";
