<?php
/** Offline behavior features need samples before reporting periodic/slow patterns. */

require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/RiskCorrelationAnalyzer.php';

$failures = array();
function behavior_assert(&$failures, $label, $condition)
{
    echo ($condition ? 'ok:   ' : 'FAIL: ') . $label . "\n";
    if (!$condition) { $failures[] = $label; }
}

$root = sys_get_temp_dir() . '/adguard-behavior-' . uniqid('', true);
$logs = $root . '/logs';
@mkdir($logs, 0700, true);
$rows = array();
for ($i = 0; $i < 6; $i++) {
    $rows[] = json_encode(array(
        'timestamp' => gmdate('c', strtotime('2026-08-31 00:00:00 UTC') + ($i * 120)),
        'site_id' => 'test-site', 'route_group' => 'article', 'path' => '/article/a',
        'ip_hmac' => 'ip-' . $i, 'network_hmac' => 'net-' . $i, 'visitor_hmac' => 'visitor-1',
        'ua_family' => 'chrome', 'engine_level' => 'SUSPICIOUS', 'score' => 70,
        'action' => 'MONITOR_DENY', 'ads_allowed' => true, 'ads_served' => true,
        'signals' => array('visitor_rate' => array('score' => 100, 'triggered' => true)),
    ), JSON_UNESCAPED_SLASHES);
}
file_put_contents($logs . '/ad-guard-2026-08-31.jsonl', implode("\n", $rows) . "\n");
$configPath = $root . '/guard.php';
file_put_contents($configPath, '<?php return ' . var_export(array(
    'analytics' => array('reporting_timezone' => 'UTC', 'baseline_days' => 3),
), true) . ';');
$config = \AdGuard\Config::load($configPath);
$analysis = (new \AdGuard\RiskCorrelationAnalyzer($config))->analyze('2026-08-31', array('rows' => array()), $logs);
$actor = isset($analysis['groups'][0]['candidate_actors'][0]) ? $analysis['groups'][0]['candidate_actors'][0] : array();
$pattern = isset($analysis['groups'][0]['behavior_clusters'][0]) ? $analysis['groups'][0]['behavior_clusters'][0] : array();

behavior_assert($failures, 'low-and-slow actor has bounded timing samples', isset($actor['timing']) && $actor['timing']['sample_count'] === 6 && $actor['timing']['low_and_slow'] === true);
behavior_assert($failures, 'regular intervals are reported as periodic evidence', isset($actor['timing']['periodic']) && $actor['timing']['periodic'] === true);
behavior_assert($failures, 'distributed rotation remains visible beside timing', !empty($actor['ip_rotation_observed']));
behavior_assert($failures, 'behavior cluster carries timing without click attribution', isset($pattern['timing']['sample_count']) && $pattern['click_attribution'] === false);

foreach ((array)glob($root . '/*') as $file) {
    if (is_dir($file)) {
        foreach ((array)glob($file . '/*') as $child) { @unlink($child); }
        @rmdir($file);
    } else { @unlink($file); }
}
@rmdir($root);
if ($failures) { echo "\n" . count($failures) . " failure(s).\n"; exit(1); }
echo "\nBehavior analysis test passed.\n";
