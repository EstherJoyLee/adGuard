<?php
/** Whole-history actor ranking is bounded while totals remain exact. */
require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/LogReader.php';
$failures = array();
function actor_cap_assert(&$failures, $label, $condition) { echo ($condition ? 'ok:   ' : 'FAIL: ') . $label . "\n"; if (!$condition) { $failures[] = $label; } }
$root = sys_get_temp_dir() . '/adguard-actor-cap-' . uniqid('', true);
$logs = $root . '/logs'; @mkdir($logs, 0700, true);
$lines = array();
for ($i = 0; $i < 150; $i++) {
    $lines[] = json_encode(array(
        'timestamp' => gmdate('c', strtotime('2026-08-31 00:00:00 UTC') + $i),
        'path' => '/page.php', 'engine_level' => 'NORMAL', 'score' => 0,
        'action' => 'ALLOW', 'ads_allowed' => true, 'ads_served' => true,
        'ip_hmac' => 'ip-' . $i, 'visitor_hmac' => '', 'signals' => array(), 'reasons' => array(),
    ));
}
file_put_contents($logs . '/ad-guard-2026-08-31.jsonl', implode("\n", $lines) . "\n");
$configPath = $root . '/guard.php';
file_put_contents($configPath, '<?php return ' . var_export(array(
    'logging' => array('path' => $logs), 'viewer' => array('max_actor_buckets' => 100),
    'analytics' => array('reporting_timezone' => 'UTC'),
), true) . ';');
$result = (new \AdGuard\LogReader(\AdGuard\Config::load($configPath)))->read(array('date' => '2026-08-31'), 1, 100);
actor_cap_assert($failures, 'summary total stays exact after actor cap', $result['summary']['total'] === 150);
actor_cap_assert($failures, 'actor ranking is bounded', count($result['summary']['top_ips']) === 12);
actor_cap_assert($failures, 'truncation is explicit', !empty($result['summary']['actor_buckets_truncated']));
@unlink($logs . '/ad-guard-2026-08-31.jsonl'); @rmdir($logs); @unlink($configPath); @rmdir($root);
if ($failures) { echo "\n" . count($failures) . " failure(s).\n"; exit(1); }
echo "\nViewer actor cap test passed.\n";
