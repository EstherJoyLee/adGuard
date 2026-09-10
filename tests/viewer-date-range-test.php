<?php
/**
 * Viewer date-selection tests: day / all / range / month.
 *
 * The fixtures deliberately straddle the UTC filename boundary, because that
 * is where a date feature is most likely to be silently wrong: one local
 * (Asia/Seoul) day is spread across two UTC files, and each of those files
 * also holds records belonging to the neighbouring local day. A reader that
 * selected records by filename, or that looped over days and reopened the
 * shared boundary file, would double-count without ever looking broken.
 */
require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/LogReader.php';

function viewer_range_assert(&$failures, $label, $condition)
{
    if ($condition) {
        echo "ok:   " . $label . "\n";
        return;
    }
    $failures++;
    echo "FAIL: " . $label . "\n";
}

function viewer_range_record($timestamp, $path, $ip, $extra = array())
{
    return json_encode(array_merge(array(
        'timestamp' => $timestamp,
        'path' => $path,
        'engine_level' => 'NORMAL',
        'score' => 0,
        'action' => 'ALLOW',
        'policy_reason' => 'test',
        'ads_allowed' => true,
        'ads_served' => true,
        'degraded' => false,
        'ip_hmac' => $ip,
        'visitor_hmac' => 'visitor-' . $ip,
        'ad_delivery' => array(
            'bootstrap' => array('opportunities' => 1, 'provided' => 1, 'blocked' => 0, 'missing' => 0),
            'manual_unit_count' => 0,
            'manual_units' => array(),
        ),
        'signals' => array(),
        'reasons' => array(),
    ), $extra)) . "\n";
}

function viewer_range_remove_tree($path)
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
            viewer_range_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

$failures = 0;
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adguard-viewer-range-' . uniqid('', true);
$logs = $temp . DIRECTORY_SEPARATOR . 'logs';
mkdir($logs, 0700, true);

/*
 * Asia/Seoul is UTC+9, so UTC 15:00 is the local midnight that starts the
 * NEXT local day. Each UTC file below therefore contributes records to two
 * different local reporting dates.
 *
 *   KST 2026-08-30 : 1 record
 *   KST 2026-08-31 : 3 records
 *   KST 2026-09-01 : 2 records   -> 6 records in 3 files
 */
file_put_contents($logs . '/ad-guard-2026-08-30.jsonl',
    viewer_range_record('2026-08-30T14:59:59+00:00', '/aug30-last', 'ip-boundary')
    . viewer_range_record('2026-08-30T15:00:00+00:00', '/aug31-first', 'ip-boundary')
    . viewer_range_record('2026-08-30T15:05:00+00:00', '/aug31-second', 'ip-other'));
file_put_contents($logs . '/ad-guard-2026-08-31.jsonl',
    viewer_range_record('2026-08-31T14:59:59+00:00', '/aug31-last', 'ip-other')
    . viewer_range_record('2026-08-31T15:00:00+00:00', '/sep01-first', 'ip-other'));
file_put_contents($logs . '/ad-guard-2026-09-01.jsonl',
    viewer_range_record('2026-09-01T03:00:00+00:00', '/sep01-noon', 'ip-other'));

// Non-conforming names must never be read, even in "all" mode.
file_put_contents($logs . '/ad-guard-2026-13-45.jsonl',
    viewer_range_record('2026-09-01T04:00:00+00:00', '/impossible-date', 'ip-other'));
file_put_contents($logs . '/ad-guard-backup.jsonl',
    viewer_range_record('2026-09-01T05:00:00+00:00', '/not-a-date', 'ip-other'));
file_put_contents($logs . '/unrelated.jsonl',
    viewer_range_record('2026-09-01T06:00:00+00:00', '/unrelated', 'ip-other'));

$configPath = $temp . DIRECTORY_SEPARATOR . 'guard.php';
file_put_contents($configPath, '<?php return ' . var_export(array(
    'logging' => array('path' => $logs),
    'analytics' => array('reporting_timezone' => 'Asia/Seoul'),
), true) . ';');

$config = \AdGuard\Config::load($configPath);
$reader = new \AdGuard\LogReader($config);

// ---------------------------------------------------------------- day mode
$day = $reader->read(array('date_mode' => 'day', 'date' => '2026-08-31'), 1, 100);
viewer_range_assert($failures, 'day: one local day spans two UTC files and yields 3 records',
    $day['total'] === 3 && $day['period']['mode'] === 'day');
viewer_range_assert($failures, 'day: reads exactly the two overlapping UTC files, never more',
    $day['files_read'] === 2);

$legacyDay = $reader->read(array('date' => '2026-08-31'), 1, 100);
viewer_range_assert($failures, 'backward compatible: ?date= alone still means that single day',
    $legacyDay['total'] === 3 && $legacyDay['period']['mode'] === 'day'
    && $legacyDay['date'] === '2026-08-31');

$defaulted = $reader->read(array(), 1, 100);
$todayLocal = date_create('now', new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
viewer_range_assert($failures, 'no date parameter keeps the historical default of today',
    $defaulted['period']['mode'] === 'day' && $defaulted['period']['start'] === $todayLocal
    && $defaulted['period']['error'] === '');

// ---------------------------------------------------------------- all mode
$all = $reader->read(array('date_mode' => 'all'), 1, 100);
viewer_range_assert($failures, 'all: every valid record is counted exactly once (6, not 12)',
    $all['total'] === 6);
viewer_range_assert($failures, 'all: each raw log file is opened exactly once (3 files)',
    $all['files_read'] === 3);
viewer_range_assert($failures, 'all: summary total matches the row total',
    $all['summary']['total'] === 6);
viewer_range_assert($failures, 'all: reports the real data extent, not the request',
    $all['data_start'] === '2026-08-30' && $all['data_end'] === '2026-09-01');

$allPaths = array();
foreach ($reader->read(array('date_mode' => 'all'), 1, 500) as $k => $v) {
    if ($k !== 'rows') {
        continue;
    }
    foreach ($v as $row) {
        $allPaths[] = $row['path'];
    }
}
viewer_range_assert($failures, 'all: impossible and non-conforming filenames are never read',
    !in_array('/impossible-date', $allPaths, true)
    && !in_array('/not-a-date', $allPaths, true)
    && !in_array('/unrelated', $allPaths, true));
viewer_range_assert($failures, 'all: rows are ordered newest first across file boundaries',
    isset($allPaths[0]) && $allPaths[0] === '/sep01-noon'
    && $allPaths[5] === '/aug30-last');

// -------------------------------------------------------------- range mode
$range = $reader->read(
    array('date_mode' => 'range', 'start_date' => '2026-08-30', 'end_date' => '2026-08-31'),
    1,
    100
);
viewer_range_assert($failures, 'range: boundaries are inclusive on both ends (4 records)',
    $range['total'] === 4);
viewer_range_assert($failures, 'range: excludes the local day just past the end date',
    !in_array('/sep01-first', array_map(function ($r) { return $r['path']; }, $range['rows']), true));

$singleDayRange = $reader->read(
    array('date_mode' => 'range', 'start_date' => '2026-08-31', 'end_date' => '2026-08-31'),
    1,
    100
);
viewer_range_assert($failures, 'range: a one-day range equals the same day in day mode',
    $singleDayRange['total'] === $day['total']);

// -------------------------------------------------------------- month mode
$month = $reader->read(array('date_mode' => 'month', 'month' => '2026-08'), 1, 100);
viewer_range_assert($failures, 'month: August covers its own local days only (4 records)',
    $month['total'] === 4);
viewer_range_assert($failures, 'month: end date uses calendar arithmetic, not a fixed length',
    $month['period']['start'] === '2026-08-01' && $month['period']['end'] === '2026-08-31');

$february = $reader->read(array('date_mode' => 'month', 'month' => '2026-02'), 1, 100);
viewer_range_assert($failures, 'month: a 28-day February ends on the 28th',
    $february['period']['end'] === '2026-02-28' && $february['total'] === 0);

$leap = $reader->read(array('date_mode' => 'month', 'month' => '2024-02'), 1, 100);
viewer_range_assert($failures, 'month: a leap February ends on the 29th',
    $leap['period']['end'] === '2024-02-29');

// -------------------------------------------------- validation and defaults
$badDate = $reader->read(array('date_mode' => 'day', 'date' => '2026-02-31'), 1, 100);
viewer_range_assert($failures, 'an explicitly supplied impossible date is an error, not today',
    $badDate['period']['error'] !== '');

$badShape = $reader->read(array('date_mode' => 'day', 'date' => 'yesterday'), 1, 100);
viewer_range_assert($failures, 'a malformed date is rejected rather than coerced',
    $badShape['period']['error'] !== '');

$badMode = $reader->read(array('date_mode' => 'week'), 1, 100);
viewer_range_assert($failures, 'an unknown date_mode is rejected',
    $badMode['period']['error'] !== '');

$badMonth = $reader->read(array('date_mode' => 'month', 'month' => '2026-13'), 1, 100);
viewer_range_assert($failures, 'an impossible month is rejected',
    $badMonth['period']['error'] !== '');

$reversed = $reader->read(
    array('date_mode' => 'range', 'start_date' => '2026-09-01', 'end_date' => '2026-08-01'),
    1,
    100
);
viewer_range_assert($failures, 'a range whose start is after its end is rejected',
    $reversed['period']['error'] !== '');

$injected = $reader->read(array('date_mode' => 'day', 'date' => array('2026-08-31')), 1, 100);
viewer_range_assert($failures, 'an array-valued date parameter is rejected, not stringified',
    $injected['period']['error'] !== '');

$traversal = $reader->read(array('date_mode' => 'day', 'date' => '../../../etc/passwd'), 1, 100);
viewer_range_assert($failures, 'a path-traversal date is rejected before any file is touched',
    $traversal['period']['error'] !== '' && $traversal['total'] === 0);

/*
 * The viewer renders one template for every outcome, so a rejected period
 * must hand back the same summary keys a successful one does. Without this
 * the error page filled with undefined-key warnings.
 */
$errorShapeKeys = array(
    'total', 'levels', 'actions', 'ads_served', 'ads_not_served', 'degraded',
    'bootstrap_removed', 'ad_delivery', 'top_reasons', 'top_denied_paths',
    'top_ua_families', 'ad_units', 'top_ips', 'top_visitors',
    'unique_visitors', 'unique_ips',
);
$okShape = true;
$errShape = true;
foreach ($errorShapeKeys as $key) {
    if (!array_key_exists($key, $badDate['summary'])) {
        $errShape = false;
    }
    if (!array_key_exists($key, $day['summary'])) {
        $okShape = false;
    }
}
viewer_range_assert($failures, 'a rejected period returns the same summary shape as a valid one',
    $errShape && $okShape
    && array_keys($badDate['summary']) === array_keys($day['summary']));

$future = $reader->read(array('date_mode' => 'day', 'date' => '2099-01-01'), 1, 100);
viewer_range_assert($failures, 'a valid future date is a normal query with an empty result',
    $future['period']['error'] === '' && $future['total'] === 0);

$emptyRange = $reader->read(
    array('date_mode' => 'range', 'start_date' => '2020-01-01', 'end_date' => '2020-01-31'),
    1,
    100
);
viewer_range_assert($failures, 'a period with no data returns an empty result, not an error',
    $emptyRange['period']['error'] === '' && $emptyRange['total'] === 0
    && $emptyRange['data_start'] === '');

// ------------------------------------------------------- filters + paging
$filtered = $reader->read(
    array('date_mode' => 'all', 'path' => 'aug31'),
    1,
    100
);
viewer_range_assert($failures, 'a path filter narrows rows but not the period summary',
    $filtered['total'] === 3 && $filtered['summary']['total'] === 6);

$actor = $reader->read(
    array('date_mode' => 'all', 'identifier' => 'ip-boundary'),
    1,
    100
);
viewer_range_assert($failures, 'identifier drill-down works across the whole period',
    $actor['total'] === 2 && $actor['summary']['total'] === 6);

$levelFiltered = $reader->read(
    array('date_mode' => 'range', 'start_date' => '2026-08-30', 'end_date' => '2026-09-01',
          'level' => 'SEVERE'),
    1,
    100
);
viewer_range_assert($failures, 'combined range + level filter yields no false matches',
    $levelFiltered['total'] === 0 && $levelFiltered['summary']['total'] === 6);

// ------------------------------------------- rolling window across midnight
$dayBoundary = $reader->read(array('date_mode' => 'day', 'date' => '2026-08-30'), 1, 100);
$boundaryDayActor = null;
foreach ($dayBoundary['summary']['top_ips'] as $candidate) {
    if (strpos('ip-boundary', $candidate['id_short']) === 0) {
        $boundaryDayActor = $candidate;
    }
}
$boundaryAllActor = null;
foreach ($all['summary']['top_ips'] as $candidate) {
    if (strpos('ip-boundary', $candidate['id_short']) === 0) {
        $boundaryAllActor = $candidate;
    }
}
viewer_range_assert($failures, 'one-day view sees only that day half of a midnight-straddling burst',
    $boundaryDayActor !== null && $boundaryDayActor['responses'] === 1
    && $boundaryDayActor['max_10m'] === 1);
viewer_range_assert($failures, 'multi-day view keeps the rolling window across the date change',
    $boundaryAllActor !== null && $boundaryAllActor['responses'] === 2
    && $boundaryAllActor['max_10m'] === 2 && $boundaryAllActor['max_1h'] === 2);

// ----------------------------------------------- pagination over many rows
$bulk = $temp . DIRECTORY_SEPARATOR . 'bulk';
mkdir($bulk, 0700, true);
$lines = '';
for ($i = 0; $i < 250; $i++) {
    // 00:00:00Z .. 04:09:00Z, all inside one KST day (2026-07-02).
    $lines .= viewer_range_record(
        sprintf('2026-07-01T%02d:%02d:00+00:00', (int)floor($i / 60), $i % 60),
        '/bulk-' . $i,
        'ip-bulk'
    );
}
file_put_contents($bulk . '/ad-guard-2026-07-01.jsonl', $lines);
$bulkConfigPath = $temp . DIRECTORY_SEPARATOR . 'guard-bulk.php';
file_put_contents($bulkConfigPath, '<?php return ' . var_export(array(
    'logging' => array('path' => $bulk),
    'analytics' => array('reporting_timezone' => 'Asia/Seoul'),
), true) . ';');
$bulkReader = new \AdGuard\LogReader(\AdGuard\Config::load($bulkConfigPath));

$p1 = $bulkReader->read(array('date_mode' => 'all'), 1, 10);
$p2 = $bulkReader->read(array('date_mode' => 'all'), 2, 10);
$p13 = $bulkReader->read(array('date_mode' => 'all'), 13, 10);
viewer_range_assert($failures, 'pagination: every page reports the same unfiltered total',
    $p1['total'] === 250 && $p2['total'] === 250 && $p13['total'] === 250);
viewer_range_assert($failures, 'pagination: page 1 starts at the newest record',
    $p1['rows'][0]['path'] === '/bulk-249' && count($p1['rows']) === 10);
viewer_range_assert($failures, 'pagination: page 2 continues without gap or overlap',
    $p1['rows'][9]['path'] === '/bulk-240' && $p2['rows'][0]['path'] === '/bulk-239');
viewer_range_assert($failures, 'pagination: a deep page still resolves to the right offset',
    $p13['rows'][0]['path'] === '/bulk-129');
viewer_range_assert($failures, 'pagination: the last page holds the remaining oldest rows',
    $bulkReader->read(array('date_mode' => 'all'), 25, 10)['rows'][9]['path'] === '/bulk-0');
viewer_range_assert($failures, 'pagination: a page past the end is empty, not an error',
    count($bulkReader->read(array('date_mode' => 'all'), 99, 10)['rows']) === 0);

viewer_range_remove_tree($temp);

if ($failures > 0) {
    echo "\n" . $failures . " viewer date-range test(s) failed.\n";
    exit(1);
}
echo "\nViewer date-range tests passed (day / all / range / month).\n";
