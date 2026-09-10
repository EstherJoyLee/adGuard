<?php
/**
 * Scenario matrix for the full pipeline (all 3 signals + ScoreCombiner),
 * using the same classes risk-engine.php wires together, but with
 * synthetic RequestContext objects so results are deterministic.
 * Run: php risk-engine/tests/combined-verdict-test.php
 */

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/RequestContext.php';
require_once __DIR__ . '/../src/SignalResult.php';
require_once __DIR__ . '/../src/Verdict.php';
require_once __DIR__ . '/../src/SignalInterface.php';
require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Storage/FileStorage.php';
require_once __DIR__ . '/../src/Scoring/ScoreCombiner.php';
require_once __DIR__ . '/../src/Signals/UserAgentAnomalySignal.php';
require_once __DIR__ . '/../src/Signals/RateLimitSignal.php';
require_once __DIR__ . '/../src/Signals/SessionChurnSignal.php';
require_once __DIR__ . '/../src/Engine.php';
require_once __DIR__ . '/support/CookieIssuingChurnSignal.php';

$failures = array();

function rek_assert(&$failures, $label, $condition, $detail = '')
{
    if (!$condition) {
        $failures[] = $label . ($detail !== '' ? " ($detail)" : '');
        echo "FAIL: $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    } else {
        echo "ok:   $label\n";
    }
}

/*
 * Disable the inline GC for this suite.
 *
 * These scenarios drive the engine with an INJECTED clock ($now below), so
 * every counter they write is stamped in 1970. gc() correctly uses the real
 * wall clock, which makes those entries look older than gc_max_age_seconds
 * and deletes them. With the default gc_probability of 0.01 that happened on
 * roughly one run in ten, wiping the rate-limit and churn counters mid-
 * scenario and dropping the verdict from SEVERE to SUSPICIOUS -- a flaky
 * failure with nothing wrong in the engine itself. Production never mixes the
 * two clocks, so this is a test-harness concern only.
 */
$rekGcConfig = sys_get_temp_dir() . '/risk-engine-nogc-' . uniqid('', true) . '.php';
file_put_contents($rekGcConfig, '<?php return ' . var_export(array(
    'storage' => array('gc_probability' => 0),
), true) . ';');
putenv('RISK_ENGINE_CONFIG=' . $rekGcConfig);
register_shutdown_function(function () use ($rekGcConfig) {
    @unlink($rekGcConfig);
});

$config = \RiskEngine\Config::load();
$storage = new \RiskEngine\Storage\FileStorage(sys_get_temp_dir() . '/risk-engine-selftest-' . uniqid());
$signals = array(
    new \RiskEngine\Signals\UserAgentAnomalySignal(1.0),
    new \RiskEngine\Signals\RateLimitSignal(1.0, array(10 => 5, 300 => 20)),
    // Test double: CLI's unconditional headers_sent() would otherwise make
    // the real signal correctly refuse to count churn, so this scenario
    // could never exercise all three signals together. See
    // support/CookieIssuingChurnSignal.php.
    new \RiskEngine\Tests\Support\CookieIssuingChurnSignal(1.0, 600, 2, '__rek_id', 31536000),
);
$engine = new \RiskEngine\Engine($config, $storage, $signals);

$now = 3000000;
$browserHeaders = array(
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0 Safari/537.36',
    'HTTP_ACCEPT' => 'text/html',
    'HTTP_ACCEPT_LANGUAGE' => 'ko-KR',
    'HTTP_ACCEPT_ENCODING' => 'gzip',
);

// --- Scenario 1: one normal browser request, stable cookie, low rate -> NORMAL ---
$ip1 = '203.0.113.101';
$server1 = $browserHeaders;
$server1['REMOTE_ADDR'] = $ip1;
$ctx = new \RiskEngine\RequestContext($server1, array('__rek_id' => 'stable-token'), $now);
$verdict = $engine->run($ctx, true);
rek_assert($failures, 'scenario 1 (normal traffic) -> NORMAL', $verdict->level === \RiskEngine\Verdict::LEVEL_NORMAL, 'got ' . $verdict->level . ' score=' . $verdict->score);

// --- Scenario 2: exactly one moderate signal (automation-tool User-Agent),
// everything else clean -- one indicator alone should raise a flag without
// escalating all the way to a full block. ---
$ip2 = '203.0.113.102';
$server2 = $browserHeaders;
$server2['HTTP_USER_AGENT'] = 'curl/8.4.0';
$server2['REMOTE_ADDR'] = $ip2;
$ctx = new \RiskEngine\RequestContext($server2, array('__rek_id' => 'stable-token'), $now);
$verdict = $engine->run($ctx, true);
rek_assert($failures, 'scenario 2 (one moderate signal only) -> ELEVATED', $verdict->level === \RiskEngine\Verdict::LEVEL_ELEVATED, 'got ' . $verdict->level . ' score=' . $verdict->score);

// --- Scenario 3: burst rate + churn + bad UA together -> SEVERE ---
$ip3 = '203.0.113.103';
$botServer = array('REMOTE_ADDR' => $ip3, 'HTTP_USER_AGENT' => 'curl/8.4.0');
// Drive both rate-limit and session-churn over threshold before the final
// measured call: 6 requests (threshold is 5/10s), no cookie each time
// (churn threshold is 2, so this also pushes churn over).
for ($i = 0; $i < 6; $i++) {
    $ctx = new \RiskEngine\RequestContext($botServer, array(), $now);
    $verdict = $engine->run($ctx, true);
}
rek_assert($failures, 'scenario 3 (burst + churn + bad UA) -> SEVERE', $verdict->level === \RiskEngine\Verdict::LEVEL_SEVERE, 'got ' . $verdict->level . ' score=' . $verdict->score);
rek_assert($failures, 'scenario 3 -> all three signals present in reasons', count($verdict->reasons) === 3, 'reasons=' . implode(' | ', $verdict->reasons));

// --- Reliability: a signal that throws must not crash the engine ---
require_once __DIR__ . '/support/ThrowingSignal.php';
$brokenSignals = array(new \RiskEngine\Tests\Support\ThrowingSignal());
$brokenEngine = new \RiskEngine\Engine($config, $storage, $brokenSignals);
$ctx = new \RiskEngine\RequestContext(array('REMOTE_ADDR' => '203.0.113.104'), array(), $now);
$verdict = $brokenEngine->run($ctx, true);
rek_assert($failures, 'a throwing signal degrades to neutral, engine does not crash', $verdict->level === \RiskEngine\Verdict::LEVEL_NORMAL);
$brokenResult = isset($verdict->signals['broken']) ? $verdict->signals['broken'] : null;
rek_assert($failures, 'the degraded result reason mentions the failure', $brokenResult !== null && strpos($brokenResult->reason, 'signal error') !== false);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAll combined-verdict scenarios passed.\n";
exit(0);
