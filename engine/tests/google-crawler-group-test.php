<?php
/** Google crawler claims must be checked against their published list class. */

require_once dirname(__DIR__) . '/src/KnownCrawlers/IpRangeSet.php';
require_once dirname(__DIR__) . '/src/KnownCrawlers/CrawlerVerifier.php';
require_once dirname(__DIR__) . '/src/KnownCrawlers/RangeCache.php';

$failures = array();
function google_group_assert(&$failures, $label, $condition)
{
    if ($condition) {
        echo "ok:   " . $label . "\n";
        return;
    }
    $failures[] = $label;
    echo "FAIL: " . $label . "\n";
}

$special = new \RiskEngine\KnownCrawlers\IpRangeSet();
$special->add('192.0.2.0/24', 'special');
$common = new \RiskEngine\KnownCrawlers\IpRangeSet();
$common->add('198.51.100.0/24', 'common');
$fetchers = new \RiskEngine\KnownCrawlers\IpRangeSet();
$fetchers->add('203.0.113.0/24', 'fetchers');
$verifier = new \RiskEngine\KnownCrawlers\CrawlerVerifier(array(
    'google' => array('special' => $special, 'common' => $common, 'fetchers' => $fetchers),
));

$googlebot = $verifier->classify('198.51.100.7', 'Googlebot/2.1');
$wrongGooglebot = $verifier->classify('192.0.2.7', 'Googlebot/2.1');
$adsbot = $verifier->classify('192.0.2.7', 'AdsBot-Google');
$wrongAdsbot = $verifier->classify('198.51.100.7', 'AdsBot-Google');
$inspection = $verifier->classify('203.0.113.7', 'Google-InspectionTool/1.0');

google_group_assert($failures, 'Googlebot verifies only in common range', $googlebot['status'] === \RiskEngine\KnownCrawlers\CrawlerVerifier::VERIFIED && $googlebot['group'] === 'common');
google_group_assert($failures, 'Googlebot does not verify in special range', $wrongGooglebot['status'] === \RiskEngine\KnownCrawlers\CrawlerVerifier::CLAIMED_UNVERIFIED);
google_group_assert($failures, 'AdsBot verifies in special range', $adsbot['status'] === \RiskEngine\KnownCrawlers\CrawlerVerifier::VERIFIED && $adsbot['group'] === 'special');
google_group_assert($failures, 'AdsBot does not verify in common range', $wrongAdsbot['status'] === \RiskEngine\KnownCrawlers\CrawlerVerifier::CLAIMED_UNVERIFIED);
google_group_assert($failures, 'InspectionTool verifies in user-triggered range', $inspection['status'] === \RiskEngine\KnownCrawlers\CrawlerVerifier::VERIFIED && $inspection['group'] === 'fetchers');

$cacheFile = sys_get_temp_dir() . '/google-crawler-groups-' . uniqid('', true) . '.php';
$payload = array(
    'generated_at' => time(),
    'vendors' => array('google' => array(
        array('192.0.2.0/24', 'special'),
        array('198.51.100.0/24', 'common'),
    )),
);
file_put_contents($cacheFile, "<?php return " . var_export($payload, true) . ";");
$cache = new \RiskEngine\KnownCrawlers\RangeCache($cacheFile);
$cachedVerifier = $cache->verifier();
$cached = $cachedVerifier->classify('198.51.100.7', 'Googlebot/2.1');
google_group_assert($failures, 'refreshed cache preserves claim groups', $cached['status'] === \RiskEngine\KnownCrawlers\CrawlerVerifier::VERIFIED && $cached['group'] === 'common');
@unlink($cacheFile);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nGoogle crawler group test passed.\n";
