<?php
/**
 * Measure viewer query cost against SYNTHETIC logs.
 *
 * Never point this at production logs: it exists so an operator can size the
 * whole-history query on their own expected volume without copying real
 * visitor telemetry anywhere. Every record it reads is generated here.
 *
 * Usage:
 *   php adguard/tools/viewer-benchmark.php [days] [records-per-day]
 *
 * Reports, per query mode: raw files opened, records matched, wall time and
 * peak memory. "files opened" is the number the reader actually opened; if it
 * ever exceeds the number of files on disk, a file was read twice.
 */

require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/LogReader.php';

/*
 * Each query runs in its own process. Peak memory is a per-process high-water
 * mark that never falls, so measuring several queries in one process would
 * report the largest query's peak for every later query.
 */
if (isset($argv[1]) && $argv[1] === '--child') {
    $childConfig = (string)$argv[2];
    /*
     * The filters travel in a file, not on the command line: Windows'
     * escapeshellarg() replaces double quotes with spaces, which silently
     * turns a JSON argument into unparseable text and every measurement
     * into a zero.
     */
    $childFilters = json_decode((string)file_get_contents($argv[3]), true);
    $childPage = (int)$argv[4];
    $reader = new \AdGuard\LogReader(\AdGuard\Config::load($childConfig));
    $t0 = microtime(true);
    $out = $reader->read(is_array($childFilters) ? $childFilters : array(), $childPage, 100);
    $elapsed = microtime(true) - $t0;
    echo json_encode(array(
        'files_read' => $out['files_read'],
        'total' => $out['total'],
        'summary_total' => $out['summary']['total'],
        'rows' => count($out['rows']),
        'elapsed' => $elapsed,
        'peak' => memory_get_peak_usage(true),
    ));
    exit(0);
}

$days = isset($argv[1]) ? max(1, (int)$argv[1]) : 120;
$perDay = isset($argv[2]) ? max(1, (int)$argv[2]) : 2000;
// Third argument sets the memory_limit each measured query runs under, so an
// operator can find the ceiling their own php.ini would impose.
$memoryLimit = isset($argv[3]) ? (string)$argv[3] : '128M';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adguard-benchmark-' . uniqid('', true);
$logs = $root . DIRECTORY_SEPARATOR . 'logs';
if (!mkdir($logs, 0700, true)) {
    fwrite(STDERR, "cannot create temporary log directory\n");
    exit(1);
}

function bench_remove_tree($path)
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
            bench_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

/*
 * Actor cardinality drives the summary's per-actor memory, so make it
 * realistic rather than trivial: a fixed pool of returning identities plus a
 * long tail of one-off visitors.
 */
$returningActors = 200;

echo "Generating synthetic logs: " . $days . " day(s) x " . $perDay . " records/day\n";
$start = new DateTime('2026-01-01 00:00:00', new DateTimeZone('UTC'));
$totalWritten = 0;
for ($d = 0; $d < $days; $d++) {
    $fileDate = clone $start;
    $fileDate->modify('+' . $d . ' day');
    $stamp = $fileDate->format('Y-m-d');
    $logPath = $logs . '/ad-guard-' . $stamp . '.jsonl';
    $writer = fopen($logPath, 'wb');
    if ($writer === false) {
        fwrite(STDERR, "cannot open synthetic log: " . $logPath . "\n");
        exit(1);
    }
    $buffer = '';
    for ($i = 0; $i < $perDay; $i++) {
        $second = (int)floor(($i / $perDay) * 86400);
        $moment = clone $fileDate;
        $moment->modify('+' . $second . ' second');
        $isReturning = ($i % 3) !== 0;
        $actor = $isReturning
            ? 'ip-' . str_pad((string)($i % $returningActors), 6, '0', STR_PAD_LEFT)
            : 'ip-oneoff-' . $d . '-' . $i;
        $denied = ($i % 17) === 0;
        $buffer .= json_encode(array(
            'schema_version' => 3,
            'timestamp' => $moment->format('c'),
            'path' => '/page-' . ($i % 25) . '.php',
            'method' => 'GET',
            'engine_level' => $denied ? 'SUSPICIOUS' : 'NORMAL',
            'score' => $denied ? 60 : 3,
            'action' => $denied ? 'MONITOR_DENY' : 'ALLOW',
            'policy_reason' => $denied ? 'blocked_engine_level' : 'risk_below_deny_threshold',
            'ads_allowed' => !$denied,
            'ads_served' => !$denied,
            'degraded' => false,
            'adsense_detected' => true,
            'bootstrap_removed' => 0,
            'ua_family' => $denied ? 'python' : 'chrome',
            'raw_ip' => '198.51.100.' . (($i % 250) + 1),
            'ip_canonical' => '198.51.100.' . (($i % 250) + 1),
            'ip_resolution_status' => 'resolved',
            'user_agent' => $denied ? 'python-requests/2.31.0' : 'Mozilla/5.0 Chrome/120.0 Safari/537.36',
            'crawler_status' => 'not_claimed',
            'crawler_vendor' => '',
            'crawler_group' => '',
            'request_type' => 'document',
            'is_document' => true,
            'ad_opportunity' => true,
            'referrer_host' => 'example.test',
            'ip_hmac' => hash('sha256', $actor),
            'visitor_hmac' => hash('sha256', 'visitor-' . $actor),
            'ad_delivery' => array(
                'bootstrap' => array(
                    'opportunities' => 1,
                    'provided' => $denied ? 0 : 1,
                    'blocked' => $denied ? 1 : 0,
                    'missing' => 0,
                ),
                'manual_unit_count' => 2,
                'manual_units' => array(
                    array('slot' => '1111111111', 'format' => 'auto', 'ordinal' => 1,
                          'status' => $denied ? 'blocked' : 'provided'),
                    array('slot' => '2222222222', 'format' => 'auto', 'ordinal' => 1,
                          'status' => $denied ? 'blocked' : 'provided'),
                ),
            ),
            'signals' => array(
                'user_agent' => array('score' => $denied ? 60 : 0, 'triggered' => $denied),
            ),
            'reasons' => $denied ? array('user_agent: automation tool named') : array(),
        ), JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($buffer) >= 262144) {
            fwrite($writer, $buffer);
            $buffer = '';
        }
        $totalWritten++;
    }
    if ($buffer !== '') {
        fwrite($writer, $buffer);
    }
    fclose($writer);
}

$onDisk = count(glob($logs . '/ad-guard-*.jsonl'));
$bytes = 0;
foreach (glob($logs . '/ad-guard-*.jsonl') as $f) {
    $bytes += filesize($f);
}
echo "Wrote " . number_format($totalWritten) . " records in " . $onDisk . " files ("
    . round($bytes / 1048576, 1) . " MiB)\n\n";

$configPath = $root . DIRECTORY_SEPARATOR . 'guard.php';
file_put_contents($configPath, '<?php return ' . var_export(array(
    'logging' => array('path' => $logs),
    'analytics' => array('reporting_timezone' => 'Asia/Seoul'),
), true) . ';');

$lastDate = clone $start;
$lastDate->modify('+' . ($days - 1) . ' day');
$firstLocal = '2026-01-01';
$lastLocal = $lastDate->format('Y-m-d');
$weekStart = clone $lastDate;
$weekStart->modify('-6 day');

$queries = array(
    'day (single local day)' => array('date_mode' => 'day', 'date' => $lastLocal),
    'range (7 days)' => array('date_mode' => 'range',
        'start_date' => $weekStart->format('Y-m-d'), 'end_date' => $lastLocal),
    'month (1 calendar month)' => array('date_mode' => 'month', 'month' => '2026-01'),
    'range (all days, explicit)' => array('date_mode' => 'range',
        'start_date' => $firstLocal, 'end_date' => $lastLocal),
    'all (whole history)' => array('date_mode' => 'all'),
    'all + path filter' => array('date_mode' => 'all', 'path' => '/page-7.php'),
    'all + deep page 50' => array('date_mode' => 'all', '__page' => 50),
);

$php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
$self = __FILE__;

printf("%-28s %7s %10s %10s %11s %11s\n",
    'query', 'files', 'matched', 'summary', 'time', 'peak mem');
echo str_repeat('-', 82) . "\n";

$anyDuplicate = false;
$failed = 0;
foreach ($queries as $label => $filters) {
    $page = 1;
    if (isset($filters['__page'])) {
        $page = (int)$filters['__page'];
        unset($filters['__page']);
    }
    $filterFile = $root . DIRECTORY_SEPARATOR . 'filters.json';
    file_put_contents($filterFile, json_encode($filters));
    $command = escapeshellarg($php)
        . ' -d ' . escapeshellarg('memory_limit=' . $memoryLimit)
        . ' ' . escapeshellarg($self) . ' --child '
        . escapeshellarg($configPath) . ' ' . escapeshellarg($filterFile) . ' '
        . escapeshellarg((string)$page);
    $raw = shell_exec($command . ' 2>' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'child.err'));
    $measured = json_decode((string)$raw, true);
    if (!is_array($measured)) {
        $failed++;
        $err = @file_get_contents($root . DIRECTORY_SEPARATOR . 'child.err');
        $why = (is_string($err) && stripos($err, 'memory size') !== false)
            ? 'OUT OF MEMORY at ' . $memoryLimit
            : 'MEASUREMENT FAILED';
        printf("%-28s %s\n", $label, $why);
        continue;
    }
    if ($measured['files_read'] > $onDisk) {
        $anyDuplicate = true;
    }
    printf("%-28s %7d %10s %10s %9.3fs %8.1fMiB\n",
        $label,
        $measured['files_read'],
        number_format($measured['total']),
        number_format($measured['summary_total']),
        $measured['elapsed'],
        $measured['peak'] / 1048576);
}

echo "\nFiles on disk: " . $onDisk . "\n";
echo "Duplicate raw-file reads detected: " . ($anyDuplicate ? "YES" : "NO") . "\n";
echo "Peak memory is the child process's total high-water mark, PHP runtime included\n";
echo "(a bare PHP CLI process already costs roughly 2 MiB before any log is read).\n";
if ($failed > 0) {
    echo "WARNING: " . $failed . " measurement(s) failed to run.\n";
}

bench_remove_tree($root);
