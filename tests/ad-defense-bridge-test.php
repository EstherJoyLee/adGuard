<?php
/**
 * Ensures repeated site helper calls evaluate one real request only once.
 *
 * This is a PROJECT INTEGRATION test, not a test of the package. It exercises
 * the host project's adapter (include/ad-defense.php), which lives outside
 * adguard/ and is not part of what a new project copies. In a project that
 * wires AdGuard up differently -- or calls ad_guard_ads_allowed() directly --
 * that adapter does not exist, so the test reports itself as skipped instead
 * of failing on a missing file. Every other suite under adguard/tests/ runs
 * against the copied package alone.
 */

$adapter = dirname(dirname(__DIR__)) . '/include/ad-defense.php';
if (!is_file($adapter)) {
    echo "skip: host project adapter not present (" . $adapter . ")\n";
    echo "      This suite only applies to an installation that integrates\n";
    echo "      AdGuard through its own ad-defense adapter.\n";
    exit(0);
}

$failures = array();
function ad_guard_bridge_assert(&$failures, $label, $condition)
{
    if ($condition) {
        echo "ok:   " . $label . "\n";
        return;
    }
    $failures[] = $label;
    echo "FAIL: " . $label . "\n";
}

$base = sys_get_temp_dir() . '/ad-guard-bridge-' . uniqid('', true);
$guardConfig = $base . '-guard.php';
$riskConfig = $base . '-risk.php';
$riskStorage = $base . '-storage';

$guardSource = '<?php return ' . var_export(array(
    'excluded_paths' => array('/bridge-test.php'),
    'logging' => array('enabled' => false),
), true) . ';';
$riskSource = '<?php return ' . var_export(array(
    'storage' => array('path' => $riskStorage, 'gc_probability' => 0),
), true) . ';';

file_put_contents($guardConfig, $guardSource);
file_put_contents($riskConfig, $riskSource);
putenv('AD_GUARD_CONFIG=' . $guardConfig);
putenv('RISK_ENGINE_CONFIG=' . $riskConfig);

$_SERVER['REQUEST_URI'] = '/bridge-test.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0 Safari/537.36';
$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ko-KR,ko;q=0.9';
$_COOKIE['__rek_id'] = 'bridge-test-visitor';

require_once $adapter;

$first = ad_defense_decision();
$second = ad_defense_decision();
$allowedA = ad_defense_ads_allowed();
$allowedB = ad_defense_ads_allowed();
$inspected = risk_engine_inspect();

$rate = isset($inspected['signals']['rate_limit']['metrics']['window_10s']['count'])
    ? (int)$inspected['signals']['rate_limit']['metrics']['window_10s']['count']
    : -1;
$visitorRate = isset($inspected['signals']['visitor_rate']['metrics']['window_10s']['count'])
    ? (int)$inspected['signals']['visitor_rate']['metrics']['window_10s']['count']
    : -1;

ad_guard_bridge_assert($failures, 'site helper returns a stable decision', $first === $second);
ad_guard_bridge_assert($failures, 'normal browser request allows ads', $allowedA && $allowedB && !empty($first['ads_allowed']));
ad_guard_bridge_assert($failures, 'repeated helper calls count as one IP request', $rate === 1);
ad_guard_bridge_assert($failures, 'repeated helper calls count as one visitor request', $visitorRate === 1);

@unlink($guardConfig);
@unlink($riskConfig);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}

echo "\nAd-defense bridge test passed.\n";
