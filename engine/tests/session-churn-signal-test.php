<?php
/** Self-test for SessionChurnSignal. Run: php risk-engine/tests/session-churn-signal-test.php */

require_once __DIR__ . '/../src/RequestContext.php';
require_once __DIR__ . '/../src/SignalResult.php';
require_once __DIR__ . '/../src/SignalInterface.php';
require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Storage/FileStorage.php';
require_once __DIR__ . '/../src/Signals/SessionChurnSignal.php';
require_once __DIR__ . '/support/CookieIssuingChurnSignal.php';

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

function rek_storage()
{
    return new \RiskEngine\Storage\FileStorage(sys_get_temp_dir() . '/risk-engine-selftest-' . uniqid('', true));
}

$now = 2000000;
$noCookieCtx = function ($ip) use ($now) {
    return new \RiskEngine\RequestContext(array('REMOTE_ADDR' => $ip), array(), $now);
};
$withCookieCtx = function ($ip) use ($now) {
    return new \RiskEngine\RequestContext(array('REMOTE_ADDR' => $ip), array('__rek_id_test' => 'existing-token'), $now);
};

/* ---------------------------------------------------------------------
 * Group 1: FAIL-SAFE -- host page already sent headers (i.e. it echoed
 * output before the engine ran, which is the normal shape of most PHP
 * pages: <!DOCTYPE> is emitted before the <head> include). The cookie
 * cannot be issued, so churn MUST NOT be counted: counting an identity we
 * never managed to assign would increment on every single request forever
 * and block ordinary visitors within a few page views.
 *
 * The precondition is established explicitly (echo + flush) rather than
 * assumed, and then asserted -- this test is worthless if headers happen
 * not to be sent yet.
 * ------------------------------------------------------------------- */
echo "-- establishing precondition: sending output so headers_sent() becomes true --\n";
flush();
rek_assert($failures, 'precondition: headers really are sent', headers_sent() === true);

$storage = rek_storage();
$failSafe = new \RiskEngine\Signals\SessionChurnSignal(1.0, 600, 2, '__rek_id_test', 31536000);

$ip = '203.0.113.30';
$result = null;
for ($i = 0; $i < 10; $i++) {
    $result = $failSafe->evaluate($noCookieCtx($ip), $storage, true);
}
rek_assert($failures, 'fail-safe: 10 cookie-less requests never trigger when cookie cannot be issued', $result->triggered === false);
rek_assert($failures, 'fail-safe: score stays 0', $result->score === 0);
rek_assert($failures, 'fail-safe: counter never advanced past 0', $result->metrics['count'] === 0);
rek_assert($failures, 'fail-safe: result is marked degraded', $result->metrics['cookie_issue_degraded'] === true);
rek_assert($failures, 'fail-safe: reason explains why counting is disabled', strpos($result->reason, 'could not be set') !== false);

/* ---------------------------------------------------------------------
 * Group 2: NORMAL PATH -- host page can still set cookies. Uses the test
 * double so the counting/scoring logic is exercised despite CLI's
 * unconditional headers_sent().
 * ------------------------------------------------------------------- */
$storage = rek_storage();
$signal = new \RiskEngine\Tests\Support\CookieIssuingChurnSignal(1.0, 600, 2, '__rek_id_test', 31536000);

$ip = '203.0.113.31';
$result = $signal->evaluate($noCookieCtx($ip), $storage, true);
rek_assert($failures, '1st new identity -> not yet triggered', $result->triggered === false);
$result = $signal->evaluate($noCookieCtx($ip), $storage, true);
rek_assert($failures, '2nd new identity -> not yet triggered (at threshold)', $result->triggered === false);

$result = $signal->evaluate($noCookieCtx($ip), $storage, true);
rek_assert($failures, '3rd new identity -> triggered', $result->triggered === true);
rek_assert($failures, '3rd new identity -> score > 0', $result->score > 0);
rek_assert($failures, 'reason mentions "new identities"', strpos($result->reason, 'new identities') !== false);
rek_assert($failures, 'not marked degraded when the cookie was issued', $result->metrics['cookie_issue_degraded'] === false);
rek_assert($failures, 'a cookie was issued once per new identity', $signal->issueCalls === 3);

/* A request that DOES carry the identity cookie never counts as churn, and
   must never trigger a redundant cookie issue. */
$stableIp = '198.51.100.40';
$before = $signal->issueCalls;
for ($i = 0; $i < 10; $i++) {
    $result = $signal->evaluate($withCookieCtx($stableIp), $storage, true);
}
rek_assert($failures, '10 requests with a stable cookie -> never triggered', $result->triggered === false);
rek_assert($failures, 'stable cookie -> no redundant cookie issued', $signal->issueCalls === $before);

/* inspect() (mutate=false) must never increment nor issue a cookie. */
$freshIp = '198.51.100.60';
$before = $signal->issueCalls;
for ($i = 0; $i < 5; $i++) {
    $result = $signal->evaluate($noCookieCtx($freshIp), $storage, false);
}
rek_assert($failures, 'inspect() called 5x -> never triggers (never mutated)', $result->triggered === false);
rek_assert($failures, 'inspect() never issued a cookie', $signal->issueCalls === $before);
rek_assert($failures, 'inspect() never wrote a storage entry for a fresh IP', $storage->read('churn:' . $freshIp) === array());

/* A request with no resolvable IP degrades to neutral, never throws. */
$noIpCtx = new \RiskEngine\RequestContext(array(), array(), $now);
$result = $signal->evaluate($noIpCtx, $storage, true);
rek_assert($failures, 'missing IP -> neutral, not triggered', $result->triggered === false);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAll SessionChurnSignal self-tests passed.\n";
exit(0);
