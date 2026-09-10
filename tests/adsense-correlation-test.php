<?php
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/AnalyticsContext.php';
require_once __DIR__ . '/../src/AdsenseReport.php';
require_once __DIR__ . '/../src/DecisionLogger.php';
require_once __DIR__ . '/../src/RiskCorrelationAnalyzer.php';

function correlation_assert(&$failures, $label, $condition)
{
    echo ($condition ? 'ok:   ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

function correlation_remove_tree($path)
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $item = $path . '/' . $name;
        if (is_dir($item)) {
            correlation_remove_tree($item);
        } else {
            @unlink($item);
        }
    }
    @rmdir($path);
}

function correlation_record($timestamp, $score, $visitor, $ip, $network, $action)
{
    $high = $score >= 50;
    return array(
        'timestamp' => $timestamp,
        'site_id' => 'test-site',
        'host' => 'www.example.com',
        'path' => '/article/a',
        'route_group' => 'article',
        'ip_hmac' => $ip,
        'network_hmac' => $network,
        'visitor_hmac' => $visitor,
        'ua_family' => 'chrome',
        'engine_level' => $high ? 'SUSPICIOUS' : 'NORMAL',
        'score' => $score,
        'action' => $action,
        'ads_allowed' => true,
        'ads_served' => true,
        'signals' => array(
            'visitor_rate' => array('score' => $high ? 100 : 0, 'triggered' => $high),
        ),
    );
}

$failures = 0;
$root = sys_get_temp_dir() . '/adguard-correlation-' . uniqid('', true);
$logDir = $root . '/logs';
$reportDir = $root . '/adsense';
$analysisDir = $root . '/analysis';
@mkdir($logDir, 0700, true);

$override = $root . '/guard.php';
$configData = array(
    'mode' => 'monitor',
    'logging' => array(
        'path' => $logDir,
        'hmac_key' => 'unit-test-hmac-key',
        'hmac_key_path' => $root . '/hmac-key',
    ),
    'analytics' => array(
        'site_id' => 'test-site',
        'site_domains' => array('example.com', '*.example.com'),
        'route_groups' => array('article' => array('/article/*')),
        'referrer_groups' => array('search' => array('*.google.com')),
        'reporting_timezone' => 'UTC',
        'report_path' => $reportDir,
        'analysis_path' => $analysisDir,
        'baseline_days' => 14,
        'minimum_clicks' => 3,
    ),
);
file_put_contents($override, "<?php\nreturn " . var_export($configData, true) . ";\n");
$config = \AdGuard\Config::load($override);
$context = new \AdGuard\AnalyticsContext($config);

correlation_assert($failures, 'site aliases resolve to one stable site_id', $context->siteIdForHost('www.example.com') === 'test-site');
correlation_assert($failures, 'wildcard paths resolve to one route group', $context->routeGroup('/article/new-slug') === 'article');
correlation_assert($failures, 'IPv4 network correlation uses /24 before HMAC', \AdGuard\AnalyticsContext::networkPrefix('203.0.113.9') === '203.0.113.0/24');
correlation_assert($failures, 'IPv6 network correlation uses /64 before HMAC', substr(\AdGuard\AnalyticsContext::networkPrefix('2001:db8:1:2::9'), -3) === '/64');

$_SERVER = array(
    'REQUEST_URI' => '/article/a?volatile=1',
    'REQUEST_METHOD' => 'GET',
    'REMOTE_ADDR' => '203.0.113.9',
    'HTTP_HOST' => 'www.example.com',
    'HTTP_REFERER' => 'https://news.google.com/story',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120.0',
);
$_COOKIE = array('__rek_id' => 'visitor-cookie');
$logger = new \AdGuard\DecisionLogger($config);
$logger->log(
    array('ads_allowed' => true, 'engine_level' => 'NORMAL', 'score' => 0, 'action' => 'ALLOW', 'signals' => array()),
    array(
        'adsense_detected' => true,
        'ads_served' => true,
        'redirect_rule_id' => 'campaign-main',
        'ad_delivery' => array(
            'bootstrap' => array('opportunities' => 1, 'provided' => 1, 'blocked' => 0, 'missing' => 0),
            'manual_unit_count' => 1,
            'manual_units' => array(array(
                'slot' => '1111111111', 'format' => 'auto', 'ordinal' => 1, 'status' => 'provided',
            )),
        ),
    )
);
$schemaFile = $logDir . '/ad-guard-' . gmdate('Y-m-d') . '.jsonl';
$schemaRecord = is_file($schemaFile) ? json_decode(trim(file_get_contents($schemaFile)), true) : array();
correlation_assert($failures, 'new log records stable site and route labels', isset($schemaRecord['site_id'], $schemaRecord['route_group']) && $schemaRecord['site_id'] === 'test-site' && $schemaRecord['route_group'] === 'article');
correlation_assert($failures, 'new log records raw IP beside network HMAC', $schemaRecord['raw_ip'] === '203.0.113.9' && !empty($schemaRecord['network_hmac']) && strpos($schemaRecord['network_hmac'], '/') === false);
correlation_assert($failures, 'server-owned redirect rule label is recorded', isset($schemaRecord['redirect_rule_id']) && $schemaRecord['redirect_rule_id'] === 'campaign-main');
correlation_assert($failures, 'schema v3 stores bounded per-slot delivery outcomes',
    isset($schemaRecord['schema_version'], $schemaRecord['ad_delivery']['manual_units'][0])
    && $schemaRecord['schema_version'] === 3
    && $schemaRecord['ad_delivery']['manual_units'][0]['slot'] === '1111111111'
    && $schemaRecord['ad_delivery']['manual_units'][0]['status'] === 'provided');
@unlink($schemaFile);

$csv = "DATE,PAGE_URL,CLICKS,IMPRESSIONS,PAGE_VIEWS,ESTIMATED_EARNINGS\n"
    . "2026-08-27,https://www.example.com/article/a,1,100,100,1.00\n"
    . "2026-08-28,https://www.example.com/article/a,1,100,100,1.00\n"
    . "2026-08-29,https://www.example.com/article/a,1,100,100,1.00\n"
    . "2026-08-30,https://www.example.com/article/a,1,100,100,1.00\n"
    . "2026-08-31,https://www.example.com/article/a,10,100,100,5.00\n";
$snapshot = \AdGuard\AdsenseReport::fromCsvString($csv, 'synthetic.csv');
correlation_assert($failures, 'CSV report rows normalize without click identities', count($snapshot['rows']) === 5 && !isset($snapshot['rows'][0]['ip']));
$savedSnapshot = \AdGuard\AdsenseReport::saveSnapshot($snapshot, $reportDir);
correlation_assert($failures, 'normalized aggregate snapshot is stored privately', is_file($savedSnapshot));

foreach (array('2026-08-27', '2026-08-28', '2026-08-29', '2026-08-30') as $date) {
    $rows = array();
    for ($index = 0; $index < 10; $index++) {
        $rows[] = correlation_record($date . 'T01:00:' . sprintf('%02d', $index) . '+00:00', 0, 'baseline-' . $index, 'ip-' . $index, 'net-1', 'ALLOW');
    }
    $lines = array();
    foreach ($rows as $row) {
        $lines[] = json_encode($row, JSON_UNESCAPED_SLASHES);
    }
    file_put_contents($logDir . '/ad-guard-' . $date . '.jsonl', implode("\n", $lines) . "\n");
}

$targetRows = array(
    correlation_record('2026-08-31T01:00:01+00:00', 80, 'rotating-visitor', 'ip-a', 'net-a', 'MONITOR_DENY'),
    correlation_record('2026-08-31T01:00:02+00:00', 100, 'rotating-visitor', 'ip-b', 'net-b', 'MONITOR_DENY'),
    correlation_record('2026-08-31T01:00:03+00:00', 100, 'rotating-visitor', 'ip-c', 'net-c', 'MONITOR_DENY'),
    correlation_record('2026-08-31T01:00:04+00:00', 60, 'rotating-visitor', 'ip-c', 'net-c', 'MONITOR_DENY'),
    correlation_record('2026-08-31T01:00:05+00:00', 0, 'normal-visitor', 'ip-normal', 'net-normal', 'ALLOW'),
);
$lines = array();
foreach ($targetRows as $row) {
    $lines[] = json_encode($row, JSON_UNESCAPED_SLASHES);
}
file_put_contents($logDir . '/ad-guard-2026-08-31.jsonl', implode("\n", $lines) . "\n");

$analyzer = new \AdGuard\RiskCorrelationAnalyzer($config);
$analysis = $analyzer->analyze('2026-08-31', $snapshot, $logDir);
$group = isset($analysis['groups'][0]) ? $analysis['groups'][0] : array();
$actor = isset($group['candidate_actors'][0]) ? $group['candidate_actors'][0] : array();
$pattern = isset($group['behavior_clusters'][0]) ? $group['behavior_clusters'][0] : array();
correlation_assert($failures, 'CTR and local risk surges correlate only at aggregate level', isset($group['status']) && $group['status'] === 'CORRELATED_AGGREGATE_ANOMALY');
correlation_assert($failures, 'visitor identity survives and exposes rotating IP behavior', !empty($actor['ip_rotation_observed']) && $actor['unique_ips'] === 3);
correlation_assert($failures, 'direct visitor-rate behavior receives strong local evidence', isset($actor['evidence']) && $actor['evidence'] === 'STRONG_LOCAL_BEHAVIOR');
correlation_assert($failures, 'behavior pattern survives changing IPs without claiming a click', isset($pattern['evidence']) && $pattern['evidence'] === 'DISTRIBUTED_BEHAVIOR_PATTERN' && $pattern['unique_ips'] === 3 && $pattern['click_attribution'] === false);
correlation_assert($failures, 'analysis explicitly refuses click-to-IP attribution', isset($analysis['click_to_ip_attribution']) && $analysis['click_to_ip_attribution'] === false && $actor['click_attribution'] === false);
$savedAnalysis = $analyzer->saveAnalysis($analysis, $analysisDir);
correlation_assert($failures, 'analysis artifact is saved under protected storage', is_file($savedAnalysis));

correlation_remove_tree($root);
if ($failures > 0) {
    echo "\n" . $failures . " analytics correlation test(s) failed.\n";
    exit(1);
}
echo "\nAll AdSense aggregate correlation tests passed.\n";
