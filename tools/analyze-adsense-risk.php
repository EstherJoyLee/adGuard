<?php
/**
 * Compare an AdSense aggregate snapshot with local AdGuard risk logs.
 *
 * Usage:
 *   php adguard/tools/analyze-adsense-risk.php 2026-08-30
 *   php adguard/tools/analyze-adsense-risk.php 2026-08-30 --adsense=/path/snapshot.json
 *   php adguard/tools/analyze-adsense-risk.php 2026-08-30 --json
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/AnalyticsContext.php';
require_once dirname(__DIR__) . '/src/AdsenseReport.php';
require_once dirname(__DIR__) . '/src/RiskCorrelationAnalyzer.php';

function ad_guard_correlation_percent($value)
{
    return number_format((float)$value * 100, 2) . '%';
}

function ad_guard_correlation_short($value)
{
    $value = (string)$value;
    return $value === '' ? '-' : substr($value, 0, 12);
}

$date = isset($argv[1]) ? (string)$argv[1] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
    fwrite(STDERR, "Usage: php adguard/tools/analyze-adsense-risk.php YYYY-MM-DD [--adsense=FILE] [--json]\n");
    exit(2);
}

$adsensePath = '';
$jsonOnly = false;
foreach (array_slice($argv, 2) as $argument) {
    if ($argument === '--json') {
        $jsonOnly = true;
    } elseif (strpos($argument, '--adsense=') === 0) {
        $adsensePath = substr($argument, strlen('--adsense='));
    }
}

try {
    $config = \AdGuard\Config::load();
    if ($adsensePath === '') {
        $adsensePath = \AdGuard\AdsenseReport::findLatest((string)$config->get('analytics.report_path'));
    }
    if ($adsensePath === '') {
        throw new RuntimeException('No normalized AdSense snapshot. Import or fetch one first.');
    }
    $snapshot = \AdGuard\AdsenseReport::loadFile($adsensePath);
    $analyzer = new \AdGuard\RiskCorrelationAnalyzer($config);
    $analysis = $analyzer->analyze($date, $snapshot, (string)$config->get('logging.path'));
    $saved = $analyzer->saveAnalysis($analysis, (string)$config->get('analytics.analysis_path'));

    if ($jsonOnly) {
        echo json_encode($analysis, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }

    echo "AdSense x AdGuard aggregate correlation\n";
    echo "date/timezone: " . $analysis['target_date'] . ' / ' . $analysis['reporting_timezone'] . "\n";
    echo "AdSense source: " . $adsensePath . "\n";
    echo "saved analysis: " . $saved . "\n";
    echo "\nCRITICAL LIMIT: no candidate below is proven to have clicked an ad.\n";
    echo "Google's report has no click-level IP/visitor identifier; this is same-date/site/route correlation only.\n";

    if (!$analysis['groups']) {
        echo "\nNo matching AdSense or local log groups for this date.\n";
        exit(0);
    }

    foreach ($analysis['groups'] as $group) {
        echo "\n[" . $group['status'] . '] ' . $group['site_id'] . ' :: ' . $group['route_group'] . "\n";
        echo "  AdSense clicks/impressions/CTR: " . $group['adsense']['clicks'] . '/'
            . $group['adsense']['impressions'] . '/' . ad_guard_correlation_percent($group['adsense']['ctr']) . "\n";
        echo "  AdSense baseline/threshold: " . ad_guard_correlation_percent($group['adsense']['baseline_median_ctr'])
            . '/' . ad_guard_correlation_percent($group['adsense']['anomaly_threshold_ctr'])
            . ' (n=' . $group['adsense']['baseline_samples'] . ")\n";
        echo "  Local ad/high-risk/risk-rate: " . $group['local']['ad_bearing_requests'] . '/'
            . $group['local']['high_risk_requests'] . '/' . ad_guard_correlation_percent($group['local']['risk_rate']) . "\n";
        echo "  Local baseline/threshold: " . ad_guard_correlation_percent($group['local']['baseline_median_risk_rate'])
            . '/' . ad_guard_correlation_percent($group['local']['anomaly_threshold_risk_rate'])
            . ' (n=' . $group['local']['baseline_samples'] . ")\n";
        echo "  Interpretation: " . $group['interpretation'] . "\n";

        if ($group['candidate_actors']) {
            echo "  Candidate activity clusters (not click attribution):\n";
            foreach (array_slice($group['candidate_actors'], 0, 10) as $actor) {
                echo "    " . $actor['evidence'] . ' ' . $actor['actor_type'] . '*='
                    . ad_guard_correlation_short($actor['actor_hmac'])
                    . ' risk=' . $actor['high_risk_requests'] . '/' . $actor['requests']
                    . ' max=' . $actor['max_score'] . ' ips=' . $actor['unique_ips']
                    . ($actor['ip_rotation_observed'] ? ' ROTATING_IP' : '') . "\n";
            }
        }
        if ($group['candidate_ips']) {
            echo "  IP HMAC context (shared-IP caution):\n";
            foreach (array_slice($group['candidate_ips'], 0, 5) as $item) {
                echo "    " . $item['evidence'] . ' ip*=' . ad_guard_correlation_short($item['ip_hmac'])
                    . ' risk=' . $item['high_risk_requests'] . '/' . $item['requests']
                    . ' max=' . $item['max_score'] . ' visitors=' . $item['unique_visitors'] . "\n";
            }
        }
        if ($group['behavior_clusters']) {
            echo "  Distributed/repeated behavior clusters (survive IP/cookie changes):\n";
            foreach (array_slice($group['behavior_clusters'], 0, 5) as $pattern) {
                echo "    " . $pattern['evidence']
                    . ' source=' . ($pattern['referrer_group'] !== '' ? $pattern['referrer_group'] : '-')
                    . ' country=' . ($pattern['country'] !== '' ? $pattern['country'] : '-')
                    . ' ua=' . ($pattern['ua_family'] !== '' ? $pattern['ua_family'] : '-')
                    . ' signals=' . ($pattern['signal_signature'] !== '' ? $pattern['signal_signature'] : '-')
                    . ' risk=' . $pattern['high_risk_requests'] . '/' . $pattern['requests']
                    . ' visitors=' . $pattern['unique_visitors'] . ' ips=' . $pattern['unique_ips'] . "\n";
            }
        }
    }

    echo "\nSafer decision rule\n";
    echo "  Block from direct/repeated local behavior evidence, not from AdSense aggregate clicks.\n";
    echo "  Visitor HMAC across changing IPs is stronger than an IP alone; network HMAC is weak context only.\n";
} catch (Exception $e) {
    fwrite(STDERR, "Analysis failed: " . $e->getMessage() . "\n");
    exit(1);
}
