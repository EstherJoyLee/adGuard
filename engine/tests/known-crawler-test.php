<?php
/**
 * Known-crawler classification tests.
 *
 * These lock in the fix for the defect where a single User-Agent substring
 * ("bot") scored 40 points and, because 40 is a hard-deny threshold, suppressed
 * the caller's action for Googlebot, AdsBot-Google, AdsBot-Google-Mobile, the
 * mobile Mediapartners-Google variant and bingbot.
 *
 * The two properties that must never regress:
 *
 *   1. Self-declaring as a crawler is worth ZERO points, with or without a
 *      verifier present. The safety of the fix must not depend on the cache.
 *   2. A User-Agent claim ALONE never yields "verified". Only an address in
 *      the vendor's own published crawler ranges does.
 */

require_once __DIR__ . '/../src/RequestContext.php';
require_once __DIR__ . '/../src/SignalResult.php';
require_once __DIR__ . '/../src/SignalInterface.php';
require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Storage/FileStorage.php';
require_once __DIR__ . '/../src/KnownCrawlers/IpRangeSet.php';
require_once __DIR__ . '/../src/KnownCrawlers/CrawlerVerifier.php';
require_once __DIR__ . '/../src/KnownCrawlers/RangeCache.php';
require_once __DIR__ . '/../src/Signals/UserAgentAnomalySignal.php';

use RiskEngine\KnownCrawlers\CrawlerVerifier;
use RiskEngine\KnownCrawlers\IpRangeSet;
use RiskEngine\KnownCrawlers\RangeCache;

$failures = 0;
function kc_assert(&$failures, $label, $condition, $detail = '')
{
    if ($condition) {
        echo "ok:   " . $label . "\n";
        return;
    }
    $failures++;
    echo "FAIL: " . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
}

function kc_remove_tree($path)
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            unlink($path);
        }
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        kc_remove_tree($path . DIRECTORY_SEPARATOR . $name);
    }
    rmdir($path);
}

/*
 * Real published prefixes, small subset, used only as fixture data. They are
 * public information from the vendor's own range files.
 */
$googleSpecial = array('72.14.199.96/27', '72.14.199.224/27');
$googleCommon  = array('66.249.79.0/27', '66.249.79.224/27');
$googleV6      = array('2001:4860:4801:10::/64');

function kc_verifier($special, $common, $v6 = array())
{
    $set = new IpRangeSet();
    foreach ($special as $c) { $set->add($c, 'special'); }
    foreach ($common as $c)  { $set->add($c, 'common'); }
    foreach ($v6 as $c)      { $set->add($c, 'special'); }
    return new CrawlerVerifier(array('google' => $set));
}

$UA = array(
    'googlebot'      => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'adsbot'         => 'AdsBot-Google (+http://www.google.com/adsbot.html)',
    'adsbot_mobile'  => 'Mozilla/5.0 (Linux; Android 5.0; SM-G920A) AppleWebKit (KHTML, like Gecko) Chrome Mobile Safari (compatible; AdsBot-Google-Mobile; +http://www.google.com/mobile/adsbot.html)',
    'mediapartners'  => 'Mediapartners-Google',
    'mediapartners_m'=> 'Mozilla/5.0 (Linux; Android 5.0; SM-G920A) AppleWebKit (KHTML, like Gecko) Chrome Mobile Safari (compatible; Mediapartners-Google/2.1; +http://www.google.com/bot.html)',
    'inspection'     => 'Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)',
    'bingbot'        => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    'chrome'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
    'curl'           => 'curl/8.4.0',
    'python'         => 'python-requests/2.31.0',
    'headless'       => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/128.0 Safari/537.36',
);

$browserHeaders = array(
    'HTTP_ACCEPT' => 'text/html',
    'HTTP_ACCEPT_LANGUAGE' => 'en-US',
    'HTTP_ACCEPT_ENCODING' => 'gzip',
);

$storage = new \RiskEngine\Storage\FileStorage(
    sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kc-test-' . uniqid('', true)
);

function kc_eval($signal, $storage, $ua, $ip, $headers)
{
    $server = array_merge($headers, array('HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => $ip));
    $ctx = new \RiskEngine\RequestContext($server, array(), time());
    return $signal->evaluate($ctx, $storage, false)->toArray();
}

// ============================================================ scoring safety
echo "-- scoring: self-declared crawlers are worth 0 points --\n";

$withVerifier = new \RiskEngine\Signals\UserAgentAnomalySignal(1.0, kc_verifier($googleSpecial, $googleCommon));
$noVerifier   = new \RiskEngine\Signals\UserAgentAnomalySignal(1.0, null);

$crawlerUas = array('googlebot', 'adsbot', 'adsbot_mobile', 'mediapartners', 'mediapartners_m', 'bingbot', 'inspection');
foreach ($crawlerUas as $key) {
    $r = kc_eval($withVerifier, $storage, $UA[$key], '72.14.199.101', $browserHeaders);
    kc_assert($failures, 'crawler UA "' . $key . '" scores 0 and does not trigger',
        $r['score'] === 0 && !$r['triggered'], 'score=' . $r['score']);
}

// The fix must hold with no verifier at all -- that is the whole point.
foreach ($crawlerUas as $key) {
    $r = kc_eval($noVerifier, $storage, $UA[$key], '203.0.113.9', $browserHeaders);
    kc_assert($failures, 'crawler UA "' . $key . '" still scores 0 with NO verifier',
        $r['score'] === 0 && !$r['triggered'], 'score=' . $r['score']);
}

echo "\n-- scoring: covert automation still scores --\n";
foreach (array('curl' => 40, 'python' => 40, 'headless' => 40) as $key => $expected) {
    $r = kc_eval($withVerifier, $storage, $UA[$key], '203.0.113.9', $browserHeaders);
    kc_assert($failures, 'covert automation "' . $key . '" still scores ' . $expected,
        $r['score'] === $expected && $r['triggered'], 'score=' . $r['score']);
}

$r = kc_eval($withVerifier, $storage, $UA['chrome'], '203.0.113.9', $browserHeaders);
kc_assert($failures, 'ordinary Chrome scores 0', $r['score'] === 0 && !$r['triggered'], 'score=' . $r['score']);

$r = kc_eval($withVerifier, $storage, '', '203.0.113.9', $browserHeaders);
kc_assert($failures, 'missing User-Agent still scores 40', $r['score'] === 40, 'score=' . $r['score']);

// ====================================================== verification results
echo "\n-- verification: address decides, never the User-Agent alone --\n";

$r = kc_eval($withVerifier, $storage, $UA['adsbot'], '72.14.199.101', $browserHeaders);
kc_assert($failures, 'AdsBot from a published special range is VERIFIED',
    $r['metrics']['crawler_status'] === CrawlerVerifier::VERIFIED
    && $r['metrics']['crawler_group'] === 'special', json_encode($r['metrics']));

$r = kc_eval($withVerifier, $storage, $UA['googlebot'], '66.249.79.6', $browserHeaders);
kc_assert($failures, 'Googlebot from a published common range is VERIFIED',
    $r['metrics']['crawler_status'] === CrawlerVerifier::VERIFIED
    && $r['metrics']['crawler_group'] === 'common', json_encode($r['metrics']));

$r = kc_eval($withVerifier, $storage, $UA['googlebot'], '203.0.113.9', $browserHeaders);
kc_assert($failures, 'SPOOFED Google UA from an unrelated address is NOT verified',
    $r['metrics']['crawler_status'] === CrawlerVerifier::CLAIMED_UNVERIFIED, json_encode($r['metrics']));

// The measured real-world case: a Google-owned cloud address that is NOT in
// any published crawler range. Anyone can rent one.
$r = kc_eval($withVerifier, $storage, $UA['googlebot'], '34.91.153.4', $browserHeaders);
kc_assert($failures, 'generic Google Cloud address is NOT a verified crawler',
    $r['metrics']['crawler_status'] === CrawlerVerifier::CLAIMED_UNVERIFIED, json_encode($r['metrics']));

$r = kc_eval($withVerifier, $storage, $UA['chrome'], '72.14.199.101', $browserHeaders);
kc_assert($failures, 'a browser UA from a crawler range is NOT_CLAIMED (no reverse allowlisting)',
    $r['metrics']['crawler_status'] === CrawlerVerifier::NOT_CLAIMED, json_encode($r['metrics']));

$r = kc_eval($withVerifier, $storage, $UA['bingbot'], '72.14.199.101', $browserHeaders);
kc_assert($failures, 'bingbot is not verified by GOOGLE ranges (vendor is not cross-honoured)',
    $r['metrics']['crawler_status'] === CrawlerVerifier::VERIFICATION_UNAVAILABLE
    && $r['metrics']['crawler_vendor'] === 'bing', json_encode($r['metrics']));

$r = kc_eval($noVerifier, $storage, $UA['googlebot'], '72.14.199.101', $browserHeaders);
kc_assert($failures, 'no verifier -> VERIFICATION_UNAVAILABLE, never a free pass',
    $r['metrics']['crawler_status'] === CrawlerVerifier::VERIFICATION_UNAVAILABLE, json_encode($r['metrics']));

echo "\n-- self-declaration is recorded as description --\n";
$r = kc_eval($withVerifier, $storage, $UA['googlebot'], '72.14.199.101', $browserHeaders);
kc_assert($failures, 'self_declared_crawler is true for a bot UA', $r['metrics']['self_declared_crawler'] === true);
$r = kc_eval($withVerifier, $storage, $UA['chrome'], '203.0.113.9', $browserHeaders);
kc_assert($failures, 'self_declared_crawler is false for Chrome', $r['metrics']['self_declared_crawler'] === false);

// ================================================================ IPv4/IPv6
echo "\n-- CIDR matching --\n";
$v6verifier = kc_verifier(array(), array(), $googleV6);
$r = kc_eval(
    new \RiskEngine\Signals\UserAgentAnomalySignal(1.0, $v6verifier),
    $storage, $UA['googlebot'], '2001:4860:4801:10::5', $browserHeaders
);
kc_assert($failures, 'IPv6 prefix matches inside the /64',
    $r['metrics']['crawler_status'] === CrawlerVerifier::VERIFIED, json_encode($r['metrics']));
$r = kc_eval(
    new \RiskEngine\Signals\UserAgentAnomalySignal(1.0, $v6verifier),
    $storage, $UA['googlebot'], '2001:4860:4801:11::5', $browserHeaders
);
kc_assert($failures, 'IPv6 address outside the /64 does not match',
    $r['metrics']['crawler_status'] === CrawlerVerifier::CLAIMED_UNVERIFIED, json_encode($r['metrics']));

$set = new IpRangeSet();
$set->add('72.14.199.96/27', 'special');
kc_assert($failures, '/27 boundary: .96 is inside', $set->contains('72.14.199.96'));
kc_assert($failures, '/27 boundary: .127 is inside', $set->contains('72.14.199.127'));
kc_assert($failures, '/27 boundary: .95 is outside', !$set->contains('72.14.199.95'));
kc_assert($failures, '/27 boundary: .128 is outside', !$set->contains('72.14.199.128'));
kc_assert($failures, 'address above 127.x matches (no 32-bit integer overflow)',
    (function () { $s = new IpRangeSet(); $s->add('203.0.113.0/24', 'x'); return $s->contains('203.0.113.5'); })());
kc_assert($failures, 'malformed CIDR is rejected, not stored',
    !$set->add('not-an-ip/24', 'x') && !$set->add('72.14.199.96/99', 'x'));
kc_assert($failures, 'empty/garbage IP never matches', !$set->contains('') && !$set->contains('nope'));

// =========================================================== cache behaviour
echo "\n-- range cache: every failure mode is neutral --\n";
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kc-cache-' . uniqid('', true);
mkdir($tmpDir, 0700, true);

$absent = new RangeCache($tmpDir . '/nope.php');
$v = $absent->verifier();
kc_assert($failures, 'absent cache -> status "absent", verifier usable but empty',
    $absent->status() === 'absent'
    && $v->classify('72.14.199.101', $UA['googlebot'])['status'] === CrawlerVerifier::VERIFICATION_UNAVAILABLE);

file_put_contents($tmpDir . '/garbage.php', "<?php return 'not an array';");
$bad = new RangeCache($tmpDir . '/garbage.php');
$bad->verifier();
kc_assert($failures, 'malformed cache -> status "malformed", no exception', $bad->status() === 'malformed');

$goodPayload = array(
    'generated_at' => time(),
    // Googlebot claims are verified only against Google's common-crawler list.
    'vendors' => array('google' => array(array('72.14.199.96/27', 'common'))),
);
file_put_contents($tmpDir . '/good.php', '<?php return ' . var_export($goodPayload, true) . ';');
$good = new RangeCache($tmpDir . '/good.php');
$gv = $good->verifier();
kc_assert($failures, 'valid cache -> status "ok" and verification works',
    $good->status() === 'ok'
    && $gv->classify('72.14.199.101', $UA['googlebot'])['status'] === CrawlerVerifier::VERIFIED);

$stalePayload = $goodPayload;
$stalePayload['generated_at'] = time() - 2000000; // ~23 days
file_put_contents($tmpDir . '/stale.php', '<?php return ' . var_export($stalePayload, true) . ';');
$stale = new RangeCache($tmpDir . '/stale.php', 1209600);
$sv = $stale->verifier();
kc_assert($failures, 'stale cache is ignored -> "unavailable", not a stale assertion',
    $stale->status() === 'stale'
    && $sv->classify('72.14.199.101', $UA['googlebot'])['status'] === CrawlerVerifier::VERIFICATION_UNAVAILABLE);

$emptyPayload = array('generated_at' => time(), 'vendors' => array('google' => array()));
file_put_contents($tmpDir . '/empty.php', '<?php return ' . var_export($emptyPayload, true) . ';');
$empty = new RangeCache($tmpDir . '/empty.php');
$empty->verifier();
kc_assert($failures, 'cache with zero prefixes -> status "empty"', $empty->status() === 'empty');

echo "\n-- laziness: an ordinary browser must never pay for the range data --\n";
/*
 * The published lists run to a few thousand prefixes; building them costs
 * milliseconds and hundreds of kilobytes. Nearly every real request is a
 * browser that claims nothing, so the data must not be touched for those.
 * Without this the feature would add that cost to every single page load.
 */
$lazy = new RangeCache($tmpDir . '/good.php');
$lv = $lazy->lazyVerifier();
$r = $lv->classify('203.0.113.9', $UA['chrome']);
kc_assert($failures, 'browser UA does not load the range cache at all',
    $r['status'] === CrawlerVerifier::NOT_CLAIMED && $lazy->status() === 'not_loaded',
    'cache status=' . $lazy->status());

$r = $lv->classify('72.14.199.101', $UA['googlebot']);
kc_assert($failures, 'a crawler claim loads the cache on first need and verifies',
    $r['status'] === CrawlerVerifier::VERIFIED && $lazy->status() === 'ok',
    'cache status=' . $lazy->status());

$throwingLoader = new CrawlerVerifier(function () {
    throw new \RuntimeException('cache exploded');
});
kc_assert($failures, 'a deferred loader that throws yields "unavailable", not a fatal',
    $throwingLoader->classify('72.14.199.101', $UA['googlebot'])['status']
        === CrawlerVerifier::VERIFICATION_UNAVAILABLE);

// A verifier that throws must not fail the signal, because a failed signal is
// treated as engine degradation upstream and would suppress the caller.
class KcThrowingVerifier extends CrawlerVerifier
{
    public function classify($ip, $userAgent)
    {
        throw new \RuntimeException('boom');
    }
}
$r = kc_eval(
    new \RiskEngine\Signals\UserAgentAnomalySignal(1.0, new KcThrowingVerifier(array())),
    $storage, $UA['chrome'], '203.0.113.9', $browserHeaders
);
kc_assert($failures, 'a throwing verifier degrades to "unavailable" without failing the signal',
    $r['metrics']['crawler_status'] === CrawlerVerifier::VERIFICATION_UNAVAILABLE
    && strpos($r['reason'], 'signal error') === false, json_encode($r));

kc_remove_tree($tmpDir);

if ($failures > 0) {
    echo "\n" . $failures . " known-crawler test(s) failed.\n";
    exit(1);
}
echo "\nAll known-crawler classification tests passed.\n";
