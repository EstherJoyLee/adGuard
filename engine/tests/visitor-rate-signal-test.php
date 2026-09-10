<?php
/** Self-test for persistent-visitor request velocity. */

require_once __DIR__ . '/../src/RequestContext.php';
require_once __DIR__ . '/../src/SignalResult.php';
require_once __DIR__ . '/../src/SignalInterface.php';
require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Storage/FileStorage.php';
require_once __DIR__ . '/../src/Signals/VisitorRateSignal.php';

$failures = array();
function visitor_rate_assert(&$failures, $label, $condition)
{
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

$storage = new \RiskEngine\Storage\FileStorage(sys_get_temp_dir() . '/visitor-rate-test-' . uniqid());
$signal = new \RiskEngine\Signals\VisitorRateSignal(1.0, array(10 => 3), '__rek_id_test');
$server = array('REMOTE_ADDR' => '203.0.113.10');
$now = 5000000;

$withoutIdentity = new \RiskEngine\RequestContext($server, array(), $now);
$result = $signal->evaluate($withoutIdentity, $storage, true);
visitor_rate_assert($failures, 'new visitor without identity is neutral', !$result->triggered && $result->score === 0);

$ctxA = new \RiskEngine\RequestContext($server, array('__rek_id_test' => 'visitor-a'), $now);
for ($i = 0; $i < 3; $i++) {
    $result = $signal->evaluate($ctxA, $storage, true);
}
visitor_rate_assert($failures, 'visitor at threshold is allowed', !$result->triggered);
$result = $signal->evaluate($ctxA, $storage, true);
visitor_rate_assert($failures, 'same visitor above threshold triggers', $result->triggered && $result->score > 0);

$ctxB = new \RiskEngine\RequestContext($server, array('__rek_id_test' => 'visitor-b'), $now);
$resultB = $signal->evaluate($ctxB, $storage, true);
visitor_rate_assert($failures, 'different visitor behind same IP has an independent bucket', !$resultB->triggered);

$blockedPath = sys_get_temp_dir() . '/visitor-rate-blocked-' . uniqid();
file_put_contents($blockedPath, 'not a directory');
$brokenStorage = new \RiskEngine\Storage\FileStorage($blockedPath);
$degraded = $signal->evaluate($ctxA, $brokenStorage, true);
visitor_rate_assert($failures, 'storage degradation is exposed in metrics', !empty($degraded->metrics['storage_degraded']));
@unlink($blockedPath);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAll VisitorRateSignal self-tests passed.\n";
