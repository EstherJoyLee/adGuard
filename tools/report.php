<?php
/**
 * Read-only daily summary for AdGuard JSONL decision logs.
 *
 * Usage:
 *   php ad-guard/tools/report.php
 *   php ad-guard/tools/report.php 2026-08-28
 *   php ad-guard/tools/report.php 2026-08-28 /absolute/log/directory
 */

require_once dirname(__DIR__) . '/src/Config.php';

function ad_guard_report_increment(&$bucket, $key)
{
    $key = trim((string)$key);
    if ($key === '') {
        $key = '(none)';
    }
    if (!isset($bucket[$key])) {
        $bucket[$key] = 0;
    }
    $bucket[$key]++;
}

function ad_guard_report_print_bucket($title, $bucket, $limit)
{
    echo "\n" . $title . "\n";
    if (!$bucket) {
        echo "  (no data)\n";
        return;
    }

    arsort($bucket, SORT_NUMERIC);
    $shown = 0;
    foreach ($bucket as $key => $count) {
        printf("  %-48s %8d\n", substr((string)$key, 0, 48), $count);
        $shown++;
        if ($shown >= $limit) {
            break;
        }
    }
}

$config = \AdGuard\Config::load();
$zoneName = (string)$config->get('analytics.reporting_timezone', 'Asia/Seoul');
try {
    $reportTimezone = new DateTimeZone($zoneName !== '' ? $zoneName : 'Asia/Seoul');
} catch (Exception $e) {
    $reportTimezone = new DateTimeZone('Asia/Seoul');
}
$nowLocal = new DateTime('now', $reportTimezone);
$date = isset($argv[1]) ? (string)$argv[1] : $nowLocal->format('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
    fwrite(STDERR, "Invalid date. Use YYYY-MM-DD (" . $reportTimezone->getName() . ").\n");
    exit(2);
}

$logDir = isset($argv[2]) ? (string)$argv[2] : (string)$config->get('logging.path', '');
$localStart = new DateTime($date . ' 00:00:00', $reportTimezone);
$localEnd = clone $localStart;
$localEnd->modify('+1 day -1 second');
$utc = new DateTimeZone('UTC');
$localStart->setTimezone($utc);
$localEnd->setTimezone($utc);
$utcDates = array($localStart->format('Y-m-d') => true, $localEnd->format('Y-m-d') => true);
$files = array();
foreach (array_keys($utcDates) as $utcDate) {
    $candidate = rtrim($logDir, '/\\') . '/ad-guard-' . $utcDate . '.jsonl';
    if (is_file($candidate)) {
        $files[] = $candidate;
    }
}
if (!$files) {
    fwrite(STDERR, "No logs overlap " . $date . " " . $reportTimezone->getName() . ".\n");
    exit(1);
}

$summary = array(
    'actions' => array(),
    'levels' => array(),
    'sites' => array(),
    'route_groups' => array(),
    'paths' => array(),
    'deny_paths' => array(),
    'policy_reasons' => array(),
    'signals' => array(),
    'ua_families' => array(),
    'referrer_hosts' => array(),
    'referrer_groups' => array(),
);
$rows = 0;
$invalidRows = 0;
$denied = 0;
$degraded = 0;
$uniqueVisitors = array();
$uniqueIps = array();
$uniqueNetworks = array();
$ipResponseCounts = array();
$visitorResponseCounts = array();
$deliveryTotals = array(
    'bootstrap_opportunities' => 0, 'bootstrap_provided' => 0,
    'bootstrap_blocked' => 0, 'bootstrap_missing' => 0,
    'manual_opportunities' => 0, 'manual_provided' => 0,
    'manual_blocked' => 0, 'manual_missing' => 0,
);
$manualPlacements = array();
$scoreTotal = 0;
$scoreMax = 0;
$firstTimestamp = '';
$lastTimestamp = '';

foreach ($files as $file) {
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Cannot read log file: " . $file . "\n");
        continue;
    }
    while (($line = fgets($handle)) !== false) {
        $record = json_decode($line, true);
        if (!is_array($record)) {
            $invalidRows++;
            continue;
        }
        $timestamp = isset($record['timestamp']) ? (string)$record['timestamp'] : '';
        try {
            $recordTime = new DateTime($timestamp, $utc);
            $recordTime->setTimezone($reportTimezone);
        } catch (Exception $e) {
            $invalidRows++;
            continue;
        }
        if ($recordTime->format('Y-m-d') !== $date) {
            continue;
        }

        $rows++;
        $score = isset($record['score']) ? (int)$record['score'] : 0;
        $scoreTotal += $score;
        $scoreMax = max($scoreMax, $score);
        if ($timestamp !== '') {
            if ($firstTimestamp === '' || strcmp($timestamp, $firstTimestamp) < 0) {
                $firstTimestamp = $timestamp;
            }
            if ($lastTimestamp === '' || strcmp($timestamp, $lastTimestamp) > 0) {
                $lastTimestamp = $timestamp;
            }
        }

        $allowed = !empty($record['ads_allowed']);
        if (!$allowed) {
            $denied++;
        }
        if (!empty($record['degraded'])) {
            $degraded++;
        }

        $path = isset($record['path']) ? $record['path'] : '';
        ad_guard_report_increment($summary['actions'], isset($record['action']) ? $record['action'] : '');
        ad_guard_report_increment($summary['levels'], isset($record['engine_level']) ? $record['engine_level'] : '');
        ad_guard_report_increment($summary['sites'], isset($record['site_id']) ? $record['site_id'] : '');
        ad_guard_report_increment($summary['route_groups'], isset($record['route_group']) ? $record['route_group'] : $path);
        ad_guard_report_increment($summary['paths'], $path);
        if (!$allowed) {
            ad_guard_report_increment($summary['deny_paths'], $path);
        }
        ad_guard_report_increment($summary['policy_reasons'], isset($record['policy_reason']) ? $record['policy_reason'] : '');
        ad_guard_report_increment($summary['ua_families'], isset($record['ua_family']) ? $record['ua_family'] : '');
        ad_guard_report_increment($summary['referrer_hosts'], isset($record['referrer_host']) ? $record['referrer_host'] : '');
        ad_guard_report_increment($summary['referrer_groups'], isset($record['referrer_group']) ? $record['referrer_group'] : '');

        if (!empty($record['visitor_hmac'])) {
            $uniqueVisitors[(string)$record['visitor_hmac']] = true;
            ad_guard_report_increment($visitorResponseCounts, substr((string)$record['visitor_hmac'], 0, 12));
        }
        if (!empty($record['ip_hmac'])) {
            $uniqueIps[(string)$record['ip_hmac']] = true;
            ad_guard_report_increment($ipResponseCounts, substr((string)$record['ip_hmac'], 0, 12));
        }
        if (!empty($record['network_hmac'])) {
            $uniqueNetworks[(string)$record['network_hmac']] = true;
        }

        if (isset($record['signals']) && is_array($record['signals'])) {
            foreach ($record['signals'] as $signalName => $signal) {
                if (is_array($signal) && !empty($signal['triggered'])) {
                    ad_guard_report_increment($summary['signals'], $signalName);
                }
            }
        }

        $delivery = isset($record['ad_delivery']) && is_array($record['ad_delivery'])
            ? $record['ad_delivery'] : array();
        $bootstrap = isset($delivery['bootstrap']) && is_array($delivery['bootstrap'])
            ? $delivery['bootstrap'] : array();
        if ($bootstrap) {
            foreach (array('opportunities', 'provided', 'blocked', 'missing') as $field) {
                $deliveryTotals['bootstrap_' . $field] += isset($bootstrap[$field]) ? max(0, (int)$bootstrap[$field]) : 0;
            }
        } else {
            $deliveryTotals['bootstrap_opportunities']++;
            if (!empty($record['ads_served'])) {
                $deliveryTotals['bootstrap_provided']++;
            } elseif ((isset($record['action']) && $record['action'] === 'DENY') || !empty($record['external_suppression'])) {
                $deliveryTotals['bootstrap_blocked']++;
            } else {
                $deliveryTotals['bootstrap_missing']++;
            }
        }
        foreach ((array)(isset($delivery['manual_units']) ? $delivery['manual_units'] : array()) as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $slot = isset($unit['slot']) ? (string)$unit['slot'] : 'unlabeled';
            $ordinal = max(1, isset($unit['ordinal']) ? (int)$unit['ordinal'] : 1);
            $format = isset($unit['format']) ? (string)$unit['format'] : 'default';
            $status = isset($unit['status']) && in_array($unit['status'], array('provided', 'blocked', 'missing'), true)
                ? (string)$unit['status'] : 'missing';
            $deliveryTotals['manual_opportunities']++;
            $deliveryTotals['manual_' . $status]++;
            $placement = $path . ' | ' . $slot . '#' . $ordinal . ' (' . $format . ')';
            if (!isset($manualPlacements[$placement])) {
                $manualPlacements[$placement] = array('opportunities' => 0, 'provided' => 0, 'blocked' => 0, 'missing' => 0);
            }
            $manualPlacements[$placement]['opportunities']++;
            $manualPlacements[$placement][$status]++;
        }
    }
    fclose($handle);
}

function ad_guard_report_local_timestamp($timestamp, $timezone)
{
    if ($timestamp === '') {
        return '(none)';
    }
    try {
        $value = new DateTime($timestamp, new DateTimeZone('UTC'));
        $value->setTimezone($timezone);
        return $value->format('Y-m-d H:i:s T');
    } catch (Exception $e) {
        return '(invalid)';
    }
}

echo "AdGuard daily report (" . $reportTimezone->getName() . " " . $date . ")\n";
echo "source UTC files: " . implode(', ', $files) . "\n";
echo "records: " . $rows . " (invalid lines: " . $invalidRows . ")\n";
echo "period: " . ad_guard_report_local_timestamp($firstTimestamp, $reportTimezone)
    . " .. " . ad_guard_report_local_timestamp($lastTimestamp, $reportTimezone) . "\n";
echo "ad denies: " . $denied . " ("
    . ($rows > 0 ? number_format(($denied / $rows) * 100, 2) : '0.00') . "%)\n";
echo "degraded decisions: " . $degraded . "\n";
echo "unique visitor hashes: " . count($uniqueVisitors) . "\n";
echo "unique IP hashes: " . count($uniqueIps) . "\n";
echo "unique network hashes (/24 or /64, weak context): " . count($uniqueNetworks) . "\n";
echo "score average/max: " . ($rows > 0 ? number_format($scoreTotal / $rows, 2) : '0.00')
    . "/" . $scoreMax . "\n";
echo "loader opportunities/provided/blocked/missing: "
    . $deliveryTotals['bootstrap_opportunities'] . "/"
    . $deliveryTotals['bootstrap_provided'] . "/"
    . $deliveryTotals['bootstrap_blocked'] . "/"
    . $deliveryTotals['bootstrap_missing'] . "\n";
echo "manual placements opportunities/provided/blocked/missing: "
    . $deliveryTotals['manual_opportunities'] . "/"
    . $deliveryTotals['manual_provided'] . "/"
    . $deliveryTotals['manual_blocked'] . "/"
    . $deliveryTotals['manual_missing'] . "\n";

ad_guard_report_print_bucket('Actions', $summary['actions'], 20);
ad_guard_report_print_bucket('Engine levels', $summary['levels'], 20);
ad_guard_report_print_bucket('Stable site IDs', $summary['sites'], 20);
ad_guard_report_print_bucket('Route groups', $summary['route_groups'], 20);
ad_guard_report_print_bucket('Triggered signals', $summary['signals'], 20);
ad_guard_report_print_bucket('Policy reasons', $summary['policy_reasons'], 20);
ad_guard_report_print_bucket('Top denied paths', $summary['deny_paths'], 20);
ad_guard_report_print_bucket('Top ad-bearing paths', $summary['paths'], 20);
ad_guard_report_print_bucket('User-Agent families', $summary['ua_families'], 20);
ad_guard_report_print_bucket('Referrer hosts', $summary['referrer_hosts'], 20);
ad_guard_report_print_bucket('Referrer groups', $summary['referrer_groups'], 20);
ad_guard_report_print_bucket('Top IP identifiers by ad-bearing responses', $ipResponseCounts, 20);
ad_guard_report_print_bucket('Top visitor identifiers by ad-bearing responses', $visitorResponseCounts, 20);

echo "\nManual ad placements (opportunities/provided/blocked/missing)\n";
if (!$manualPlacements) {
    echo "  (no schema-v2 slot data)\n";
} else {
    uasort($manualPlacements, function ($a, $b) {
        return $a['opportunities'] === $b['opportunities'] ? 0 : ($a['opportunities'] < $b['opportunities'] ? 1 : -1);
    });
    $shown = 0;
    foreach ($manualPlacements as $placement => $counts) {
        printf("  %-64s %6d/%6d/%6d/%6d\n", substr($placement, 0, 64),
            $counts['opportunities'], $counts['provided'], $counts['blocked'], $counts['missing']);
        if (++$shown >= 30) {
            break;
        }
    }
}

echo "\nNotes\n";
echo "  Counts cover only ad-bearing responses that were logged.\n";
echo "  If allow/deny sample rates are below 1.0, raw counts are samples.\n";
echo "  Hash counts are pseudonymous estimates, not verified people.\n";
