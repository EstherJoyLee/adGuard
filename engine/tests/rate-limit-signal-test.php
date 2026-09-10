<?php
/** Self-test for RateLimitSignal. Run: php risk-engine/tests/rate-limit-signal-test.php */

require_once __DIR__ . '/../src/RequestContext.php';
require_once __DIR__ . '/../src/SignalResult.php';
require_once __DIR__ . '/../src/SignalInterface.php';
require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Storage/FileStorage.php';
require_once __DIR__ . '/../src/Signals/RateLimitSignal.php';

$failures = array();

function rek_assert(&$failures, $label, $condition)
{
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

$storage = new \RiskEngine\Storage\FileStorage(sys_get_temp_dir() . '/risk-engine-selftest-' . uniqid());
// Small windows so the test runs fast and deterministically: 5 requests
// allowed per 10s window, 20 per 300s window.
$signal = new \RiskEngine\Signals\RateLimitSignal(1.0, array(10 => 5, 300 => 20));

$now = 1000000;
$ctxFor = function ($ip) use ($now) {
    return new \RiskEngine\RequestContext(array('REMOTE_ADDR' => $ip), array(), $now);
};

// 5 requests under the short-window threshold -> never triggers
$ip = '203.0.113.10';
for ($i = 0; $i < 5; $i++) {
    $result = $signal->evaluate($ctxFor($ip), $storage, true);
}
rek_assert($failures, 'at threshold (5/5) -> not yet triggered', $result->triggered === false);

// 6th request within the same window -> now over threshold
$result = $signal->evaluate($ctxFor($ip), $storage, true);
rek_assert($failures, '6th request in window -> triggered', $result->triggered === true);
rek_assert($failures, '6th request -> score > 0', $result->score > 0);
rek_assert($failures, 'reason mentions the 10s window', strpos($result->reason, '10s window') !== false);

// A different IP is an independent bucket -- must not be affected
$otherIp = '198.51.100.20';
$result = $signal->evaluate($ctxFor($otherIp), $storage, true);
rek_assert($failures, 'different IP -> independent bucket, not triggered', $result->triggered === false);

// inspect() (mutate=false) must never increment -- call it repeatedly and
// confirm the stored count for a fresh IP stays at 0/not-triggered.
$freshIp = '198.51.100.99';
for ($i = 0; $i < 10; $i++) {
    $result = $signal->evaluate($ctxFor($freshIp), $storage, false);
}
rek_assert($failures, 'inspect() called 10x -> never mutates, still not triggered', $result->triggered === false);
$readOnly = $storage->read('rate:' . $freshIp);
rek_assert($failures, 'inspect() never wrote a storage entry for a fresh IP', $readOnly === array());

// A request with no resolvable IP degrades to neutral, never throws.
$noIpCtx = new \RiskEngine\RequestContext(array(), array(), $now);
$result = $signal->evaluate($noIpCtx, $storage, true);
rek_assert($failures, 'missing IP -> neutral, not triggered', $result->triggered === false);
rek_assert($failures, 'missing IP -> score 0', $result->score === 0);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAll RateLimitSignal self-tests passed.\n";
exit(0);
