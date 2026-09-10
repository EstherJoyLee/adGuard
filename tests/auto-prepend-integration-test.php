<?php
$root = dirname(__DIR__);
$configPath = sys_get_temp_dir() . '/ad-guard-integration-config-' . uniqid() . '.php';
$riskConfigPath = sys_get_temp_dir() . '/risk-engine-integration-config-' . uniqid() . '.php';
$logPath = sys_get_temp_dir() . '/ad-guard-integration-log-' . uniqid();
$riskPath = sys_get_temp_dir() . '/ad-guard-integration-risk-' . uniqid();

$config = array(
    'mode' => 'enforce',
    'logging' => array('enabled' => true, 'path' => $logPath, 'hmac_key_path' => $logPath . '/.key'),
);
file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');
file_put_contents($riskConfigPath, '<?php return ' . var_export(array('storage' => array('path' => $riskPath)), true) . ';');

$php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
$command = escapeshellarg($php)
    . ' -d ' . escapeshellarg('auto_prepend_file=' . $root . '/auto-prepend.php')
    . ' ' . escapeshellarg(__DIR__ . '/fixtures/ad-page.php');
$oldForce = getenv('AD_GUARD_FORCE_AUTO_PREPEND');
$oldAdConfig = getenv('AD_GUARD_CONFIG');
$oldRiskConfig = getenv('RISK_ENGINE_CONFIG');
putenv('AD_GUARD_FORCE_AUTO_PREPEND=1');
putenv('AD_GUARD_CONFIG=' . $configPath);
putenv('RISK_ENGINE_CONFIG=' . $riskConfigPath);
$descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$process = proc_open($command, $descriptors, $pipes);
$stdout = '';
$stderr = '';
$exit = 1;
if (is_resource($process)) {
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
}

putenv($oldForce === false ? 'AD_GUARD_FORCE_AUTO_PREPEND' : 'AD_GUARD_FORCE_AUTO_PREPEND=' . $oldForce);
putenv($oldAdConfig === false ? 'AD_GUARD_CONFIG' : 'AD_GUARD_CONFIG=' . $oldAdConfig);
putenv($oldRiskConfig === false ? 'RISK_ENGINE_CONFIG' : 'RISK_ENGINE_CONFIG=' . $oldRiskConfig);

@unlink($configPath);
@unlink($riskConfigPath);

$failures = array();
function auto_prepend_assert(&$failures, $label, $condition)
{
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

auto_prepend_assert($failures, 'child PHP process exits cleanly', $exit === 0 && trim($stderr) === '');
auto_prepend_assert($failures, 'auto-prepend preserves page content', strpos($stdout, 'AUTO_PREPEND_CONTENT_SURVIVES') !== false);
auto_prepend_assert($failures, 'headerless CLI request is denied and bootstrap removed', strpos($stdout, 'pagead2.googlesyndication.com') === false);
$logs = is_dir($logPath) ? glob($logPath . '/ad-guard-*.jsonl') : array();
auto_prepend_assert($failures, 'auto-detected ad page creates a structured decision log', is_array($logs) && count($logs) === 1 && filesize($logs[0]) > 0);

if ($failures) {
    if ($stderr !== '') {
        echo "child stderr: " . $stderr . "\n";
    }
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAdGuard auto-prepend integration test passed.\n";
