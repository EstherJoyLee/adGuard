<?php
require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/LogReader.php';

function viewer_tz_assert(&$failures, $label, $condition)
{
    if ($condition) {
        echo "ok:   " . $label . "\n";
        return;
    }
    $failures++;
    echo "FAIL: " . $label . "\n";
}

function viewer_tz_record($timestamp, $path)
{
    return json_encode(array(
        'timestamp' => $timestamp,
        'path' => $path,
        'engine_level' => 'NORMAL',
        'score' => 0,
        'action' => 'ALLOW',
        'policy_reason' => 'test',
        'ads_allowed' => true,
        'ads_served' => true,
        'degraded' => false,
        'ip_hmac' => 'ip-hmac-one',
        'visitor_hmac' => 'visitor-hmac-one',
        'ad_delivery' => array(
            'bootstrap' => array('opportunities' => 1, 'provided' => 1, 'blocked' => 0, 'missing' => 0),
            'manual_unit_count' => 1,
            'manual_units' => array(array(
                'slot' => '1111111111', 'format' => 'auto', 'ordinal' => 1, 'status' => 'provided',
            )),
        ),
        'signals' => array(),
        'reasons' => array(),
    )) . "\n";
}

function viewer_tz_remove_tree($path)
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $name;
        if (is_dir($child)) {
            viewer_tz_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

$failures = 0;
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adguard-viewer-tz-' . uniqid('', true);
$logs = $temp . DIRECTORY_SEPARATOR . 'logs';
mkdir($logs, 0700, true);

file_put_contents($logs . '/ad-guard-2026-08-30.jsonl',
    viewer_tz_record('2026-08-30T14:59:59+00:00', '/kst-previous-day')
    . viewer_tz_record('2026-08-30T15:00:00+00:00', '/slot-page')
    . viewer_tz_record('2026-08-30T15:05:00+00:00', '/slot-page'));
file_put_contents($logs . '/ad-guard-2026-08-31.jsonl',
    viewer_tz_record('2026-08-31T14:59:59+00:00', '/slot-page-2')
    . viewer_tz_record('2026-08-31T15:00:00+00:00', '/kst-next-day'));

$configPath = $temp . DIRECTORY_SEPARATOR . 'guard.php';
file_put_contents($configPath, '<?php return ' . var_export(array(
    'logging' => array('path' => $logs),
    'analytics' => array('reporting_timezone' => 'Asia/Seoul'),
), true) . ';');

$config = \AdGuard\Config::load($configPath);
$reader = new \AdGuard\LogReader($config);
$dates = $reader->availableDates();
$result = $reader->read(array('date' => '2026-08-31'), 1, 100);

viewer_tz_assert($failures, 'viewer exposes Asia/Seoul as its reporting timezone',
    $reader->timezoneName() === 'Asia/Seoul');
viewer_tz_assert($failures, 'available dates include the KST date crossing a UTC filename boundary',
    in_array('2026-08-31', $dates, true));
viewer_tz_assert($failures, 'one KST day reads records from both overlapping UTC files',
    $result['total'] === 3);
viewer_tz_assert($failures, 'records outside the selected KST day are excluded',
    isset($result['rows'][0]['path'], $result['rows'][2]['path'])
    && $result['rows'][0]['path'] === '/slot-page-2'
    && $result['rows'][2]['path'] === '/slot-page');
viewer_tz_assert($failures, 'UTC timestamps are presented as KST wall-clock values',
    $result['rows'][0]['timestamp_local'] === '2026-08-31 23:59:59'
    && $result['rows'][2]['timestamp_local'] === '2026-08-31 00:00:00');
viewer_tz_assert($failures, 'slot opportunities and loader outcomes aggregate by path and placement',
    $result['summary']['ad_delivery']['manual_opportunities'] === 3
    && $result['summary']['ad_delivery']['manual_provided'] === 3
    && isset($result['summary']['ad_units'][0]['opportunities'])
    && $result['summary']['ad_units'][0]['opportunities'] === 2);
viewer_tz_assert($failures, 'IP activity exposes total and rolling 10-minute/one-hour density',
    isset($result['summary']['top_ips'][0])
    && $result['summary']['top_ips'][0]['responses'] === 3
    && $result['summary']['top_ips'][0]['max_10m'] === 2
    && $result['summary']['top_ips'][0]['max_1h'] === 2);

viewer_tz_remove_tree($temp);

if ($failures > 0) {
    echo "\n" . $failures . " viewer timezone test(s) failed.\n";
    exit(1);
}
echo "\nViewer KST timezone tests passed.\n";
