<?php
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/AdsenseDetector.php';
require_once __DIR__ . '/../src/DecisionLogger.php';
require_once __DIR__ . '/../src/Guard.php';

class RecordingAdGuardLogger
{
    public $records = array();
    public function log($decision, $meta)
    {
        $this->records[] = array('decision' => $decision, 'meta' => $meta);
        return true;
    }
}

$failures = array();
function ad_guard_assert(&$failures, $label, $condition)
{
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

function ad_guard_config($mode)
{
    $path = sys_get_temp_dir() . '/ad-guard-config-' . uniqid() . '.php';
    $src = '<?php return ' . var_export(array(
        'mode' => $mode,
        'logging' => array('enabled' => false),
    ), true) . ';';
    file_put_contents($path, $src);
    $config = \AdGuard\Config::load($path);
    @unlink($path);
    return $config;
}

function verdict($level, $score, $signals)
{
    return array('level' => $level, 'score' => $score, 'reasons' => array('test'), 'signals' => $signals);
}

$adHtml = '<!doctype html><html><head>'
    . '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-123" crossorigin="anonymous"></script>'
    . '<script src="/safe.js"></script></head><body><main>content survives</main>'
    . '<ins class="adsbygoogle" data-ad-client="ca-pub-123" data-ad-slot="slot-main" data-ad-format="auto"></ins>'
    . '<ins class="adsbygoogle" data-ad-client="ca-pub-123" data-ad-slot="slot-main"></ins>'
    . '<script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></body></html>';

$logger = new RecordingAdGuardLogger();
$allow = new \AdGuard\Guard(ad_guard_config('enforce'), function () {
    return verdict('NORMAL', 0, array());
}, $logger);
$allowedHtml = $allow->processHtml($adHtml);
ad_guard_assert($failures, 'ALLOW leaves the complete HTML byte-for-byte unchanged', $allowedHtml === $adHtml);
ad_guard_assert($failures, 'ALLOW logs one ad decision', count($logger->records) === 1 && $logger->records[0]['meta']['bootstrap_removed'] === 0);
$allowDelivery = $logger->records[0]['meta']['ad_delivery'];
ad_guard_assert($failures, 'ALLOW inventories repeated manual placements by slot and DOM order',
    $allowDelivery['manual_unit_count'] === 2
    && $allowDelivery['manual_units'][0]['slot'] === 'slot-main'
    && $allowDelivery['manual_units'][0]['ordinal'] === 1
    && $allowDelivery['manual_units'][1]['ordinal'] === 2);
ad_guard_assert($failures, 'ALLOW records loader and manual placements as provided, not impressions',
    $allowDelivery['bootstrap']['provided'] === 1
    && $allowDelivery['manual_units'][0]['status'] === 'provided'
    && $allowDelivery['manual_units'][1]['status'] === 'provided');

$logger = new RecordingAdGuardLogger();
$deny = new \AdGuard\Guard(ad_guard_config('enforce'), function () {
    return verdict('SUSPICIOUS', 55, array());
}, $logger);
$deniedHtml = $deny->processHtml($adHtml);
ad_guard_assert($failures, 'DENY removes the Google executable bootstrap', strpos($deniedHtml, 'pagead2.googlesyndication.com') === false);
ad_guard_assert($failures, 'DENY preserves page content and unrelated scripts', strpos($deniedHtml, 'content survives') !== false && strpos($deniedHtml, '/safe.js') !== false);
ad_guard_assert($failures, 'DENY may leave inert manual unit markup', strpos($deniedHtml, 'class="adsbygoogle"') !== false);
ad_guard_assert($failures, 'DENY logs exactly one removed bootstrap', count($logger->records) === 1 && $logger->records[0]['meta']['bootstrap_removed'] === 1);
$denyDelivery = $logger->records[0]['meta']['ad_delivery'];
ad_guard_assert($failures, 'DENY records the loader and every manual placement as blocked',
    $denyDelivery['bootstrap']['blocked'] === 1
    && $denyDelivery['manual_units'][0]['status'] === 'blocked'
    && $denyDelivery['manual_units'][1]['status'] === 'blocked');

$logger = new RecordingAdGuardLogger();
$external = new \AdGuard\Guard(ad_guard_config('enforce'), function () {
    return verdict('NORMAL', 0, array());
}, $logger);
$external->markAdOpportunity();
$external->noteExternalSuppression('preview_override');
$external->processHtml('<html><body><!-- bootstrap deliberately not emitted --></body></html>');
$externalDelivery = $logger->records[0]['meta']['ad_delivery'];
ad_guard_assert($failures, 'adapter-suppressed Auto Ads opportunity is counted as one blocked bootstrap',
    $externalDelivery['bootstrap']['opportunities'] === 1
    && $externalDelivery['bootstrap']['blocked'] === 1
    && $externalDelivery['bootstrap']['provided'] === 0);

$logger = new RecordingAdGuardLogger();
$hardDeny = new \AdGuard\Guard(ad_guard_config('enforce'), function () {
    return verdict('ELEVATED', 40, array(
        'user_agent' => array('score' => 40, 'triggered' => true, 'reason' => 'automation', 'metrics' => array()),
    ));
}, $logger);
$hardDecision = $hardDeny->getDecision();
ad_guard_assert($failures, 'strong automation signal denies even at ELEVATED combined level', !$hardDecision['ads_allowed'] && $hardDecision['policy_reason'] === 'hard_deny_signal:user_agent');

$logger = new RecordingAdGuardLogger();
$degraded = new \AdGuard\Guard(ad_guard_config('enforce'), function () {
    return verdict('NORMAL', 0, array(
        'rate_limit' => array('score' => 0, 'triggered' => false, 'reason' => '', 'metrics' => array('storage_degraded' => true)),
    ));
}, $logger);
$degradedDecision = $degraded->getDecision();
ad_guard_assert(
    $failures,
    'storage-degraded risk verdict fails closed for ads',
    !$degradedDecision['ads_allowed']
        && $degradedDecision['degraded']
        && $degradedDecision['policy_reason'] === 'risk_storage_degraded_fail_closed'
);

$logger = new RecordingAdGuardLogger();
$providerFailure = new \AdGuard\Guard(ad_guard_config('enforce'), function () {
    throw new Exception('secret internal detail');
}, $logger);
$failureDecision = $providerFailure->getDecision();
ad_guard_assert($failures, 'decision-provider exception fails closed without escaping', !$failureDecision['ads_allowed'] && $failureDecision['policy_reason'] === 'risk_engine_degraded_fail_closed');

$logger = new RecordingAdGuardLogger();
$monitor = new \AdGuard\Guard(ad_guard_config('monitor'), function () {
    return verdict('SEVERE', 100, array());
}, $logger);
$monitorHtml = $monitor->processHtml($adHtml);
$monitorDecision = $monitor->getDecision();
ad_guard_assert($failures, 'monitor mode records would-deny but leaves ads executable', $monitorHtml === $adHtml && $monitorDecision['action'] === 'MONITOR_DENY');

$logger = new RecordingAdGuardLogger();
$noAdProviderCalls = 0;
$noAd = new \AdGuard\Guard(ad_guard_config('enforce'), function () use (&$noAdProviderCalls) {
    $noAdProviderCalls++;
    return verdict('NORMAL', 0, array());
}, $logger);
$plain = '<html><body>plain content</body></html>';
ad_guard_assert($failures, 'non-ad HTML passes unchanged', $noAd->processHtml($plain) === $plain);
ad_guard_assert($failures, 'non-ad HTML does not evaluate or log', $noAdProviderCalls === 0 && count($logger->records) === 0);

$json = '{"example":"adsbygoogle"}';
ad_guard_assert($failures, 'non-HTML response is never rewritten', $deny->processHtml($json, 'application/json') === $json);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAll AdGuard unit tests passed.\n";
