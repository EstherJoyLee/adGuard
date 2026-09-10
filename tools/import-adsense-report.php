<?php
/**
 * Normalize an AdSense UI CSV export or API v2 JSON response.
 *
 * Usage:
 *   php adguard/tools/import-adsense-report.php /secure/path/report.csv
 *   php adguard/tools/import-adsense-report.php /secure/path/report.json
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/AdsenseReport.php';

$input = isset($argv[1]) ? (string)$argv[1] : '';
if ($input === '') {
    fwrite(STDERR, "Usage: php adguard/tools/import-adsense-report.php /secure/path/report.csv|json\n");
    exit(2);
}

try {
    $config = \AdGuard\Config::load();
    $snapshot = \AdGuard\AdsenseReport::loadFile($input);
    $output = \AdGuard\AdsenseReport::saveSnapshot(
        $snapshot,
        (string)$config->get('analytics.report_path')
    );
    $dates = array();
    foreach ($snapshot['rows'] as $row) {
        $dates[(string)$row['date']] = true;
    }
    $dateList = array_keys($dates);
    sort($dateList, SORT_STRING);
    echo "Imported AdSense aggregate report.\n";
    echo "rows: " . count($snapshot['rows']) . "\n";
    echo "dates: " . ($dateList ? reset($dateList) . ' .. ' . end($dateList) : '(none)') . "\n";
    echo "saved: " . $output . "\n";
    echo "Important: this report has no click-level IP or visitor identity.\n";
} catch (Exception $e) {
    fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n");
    exit(1);
}
