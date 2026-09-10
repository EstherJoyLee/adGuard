<?php
/**
 * Self-test for UserAgentAnomalySignal. No external test framework -- a
 * small custom runner is enough for a portable, zero-dependency module.
 * Run: php risk-engine/tests/user-agent-signal-test.php
 */

require_once __DIR__ . '/../src/RequestContext.php';
require_once __DIR__ . '/../src/SignalResult.php';
require_once __DIR__ . '/../src/SignalInterface.php';
require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Storage/FileStorage.php';
require_once __DIR__ . '/../src/Signals/UserAgentAnomalySignal.php';

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
$signal = new \RiskEngine\Signals\UserAgentAnomalySignal(1.0);

$ctx = new \RiskEngine\RequestContext(array(
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36',
    'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
    'HTTP_ACCEPT_LANGUAGE' => 'ko-KR,ko;q=0.9',
    'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br',
));
$result = $signal->evaluate($ctx, $storage, false);
rek_assert($failures, 'normal browser -> not triggered', $result->triggered === false);
rek_assert($failures, 'normal browser -> score 0', $result->score === 0);

$ctx = new \RiskEngine\RequestContext(array());
$result = $signal->evaluate($ctx, $storage, false);
rek_assert($failures, 'missing everything -> triggered', $result->triggered === true);
rek_assert($failures, 'missing everything -> score >= 40', $result->score >= 40);

$ctx = new \RiskEngine\RequestContext(array('HTTP_USER_AGENT' => 'curl/8.4.0'));
$result = $signal->evaluate($ctx, $storage, false);
rek_assert($failures, 'curl UA -> triggered', $result->triggered === true);
rek_assert($failures, 'curl UA -> reason mentions curl', strpos($result->reason, 'curl') !== false);

$ctx = new \RiskEngine\RequestContext(array(
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15',
    'HTTP_ACCEPT' => 'text/html',
    'HTTP_ACCEPT_ENCODING' => 'gzip',
));
$result = $signal->evaluate($ctx, $storage, false);
rek_assert($failures, 'missing Accept-Language only -> triggered', $result->triggered === true);
rek_assert($failures, 'missing Accept-Language only -> weak score (<25)', $result->score < 25);

/*
 * Self-declared crawlers are no longer scored as automation.
 *
 * This assertion previously read "Googlebot UA -> triggered (crawler, not a
 * browser)". It passed, but not for the reason it claimed: that request also
 * carried no Accept headers, so the 30 points of header penalties did the
 * work. Once "bot" stopped scoring, the old assertion would have kept passing
 * on the header penalties alone and quietly stopped testing anything.
 *
 * Both halves are pinned separately now, so re-adding a crawler marker to the
 * automation list fails loudly.
 */
$googlebotUa = 'Googlebot/2.1 (+http://www.google.com/bot.html)';

$ctx = new \RiskEngine\RequestContext(array(
    'HTTP_USER_AGENT' => $googlebotUa,
    'HTTP_ACCEPT' => 'text/html',
    'HTTP_ACCEPT_LANGUAGE' => 'en-US',
    'HTTP_ACCEPT_ENCODING' => 'gzip',
));
$result = $signal->evaluate($ctx, $storage, false);
rek_assert(
    $failures,
    'Googlebot with ordinary headers -> NOT triggered, score 0 (self-declaration is not abuse)',
    $result->triggered === false && $result->score === 0,
    'score=' . $result->score
);
rek_assert(
    $failures,
    'Googlebot is still described as a self-declared crawler in metrics',
    !empty($result->metrics['self_declared_crawler'])
);

$ctx = new \RiskEngine\RequestContext(array('HTTP_USER_AGENT' => $googlebotUa));
$result = $signal->evaluate($ctx, $storage, false);
rek_assert(
    $failures,
    'Googlebot without headers scores the header penalties ONLY (30, not 70)',
    $result->score === 30,
    'score=' . $result->score
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAll UserAgentAnomalySignal self-tests passed.\n";
exit(0);
