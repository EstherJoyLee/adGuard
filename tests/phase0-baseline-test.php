<?php
/** Phase 0 characterization retained around the intentional Phase 1 telemetry migration. */
require_once dirname(__DIR__) . '/adguard.php';
require_once dirname(__DIR__) . '/engine/risk-engine.php';

$base = sys_get_temp_dir() . '/adguard-phase0-' . uniqid('', true);
mkdir($base, 0700, true);
$savedServer = $_SERVER;
$savedCookies = $_COOKIE;
$savedGuardEnv = getenv('AD_GUARD_CONFIG');
$savedEngineEnv = getenv('RISK_ENGINE_CONFIG');
$failures = array();
$assertions = 0;

function p0_assert($label, $condition)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures[] = $label;
    }
    echo ($condition ? 'ok:   ' : 'FAIL: ') . $label . "\n";
}

function p0_config($file, $config)
{
    file_put_contents($file, '<?php return ' . var_export($config, true) . ';');
}

function p0_records($path)
{
    $records = array();
    foreach ((array)glob($path . '/ad-guard-*.jsonl') as $file) {
        if (strpos($file, '-health.jsonl') !== false) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }
    }
    return $records;
}

function p0_remove($path, $root)
{
    $resolved = realpath($path);
    if ($resolved === false) {
        return;
    }
    if ($resolved !== $root && strpos($resolved, $root . DIRECTORY_SEPARATOR) !== 0) {
        throw new RuntimeException('Refusing cleanup outside test directory');
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                p0_remove($path . DIRECTORY_SEPARATOR . $name, $root);
            }
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

try {
    $guardSettings = array(
        'mode' => 'monitor',
        'identity' => array('trusted_proxies' => array('203.0.113.10')),
        'logging' => array('path' => $base . '/logs', 'hmac_key' => 'synthetic-phase0-key'),
    );
    p0_config($base . '/guard.php', $guardSettings);
    p0_config($base . '/engine.php', array(
        'identity' => array('trusted_proxies' => array()),
        'storage' => array('path' => $base . '/state', 'gc_probability' => 0),
    ));
    putenv('AD_GUARD_CONFIG=' . $base . '/guard.php');
    putenv('RISK_ENGINE_CONFIG=' . $base . '/engine.php');
    $_SERVER = array(
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.8',
        'REQUEST_URI' => '/normal.php?token=phase0-query-secret',
        'REQUEST_METHOD' => 'GET',
        'HTTP_HOST' => 'phase0.example.test',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120.0 Safari/537.36',
        'HTTP_ACCEPT' => 'text/html',
        'HTTP_ACCEPT_LANGUAGE' => 'ko-KR',
        'HTTP_ACCEPT_ENCODING' => 'gzip',
        'HTTP_AUTHORIZATION' => 'Bearer phase0-authorization-secret',
        'HTTP_COOKIE' => '__rek_id=phase0-cookie-secret',
    );
    $_COOKIE = array('__rek_id' => 'phase0-cookie-secret');
    $plain = '<html><body>PHASE0_PLAIN</body></html>';
    $ad = '<html><body>PHASE0_AD<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-test"></script></body></html>';

    ob_start();
    $booted = ad_guard_boot();
    echo $plain;
    $providerRanBeforeResponse = is_dir($base . '/state') && !is_dir($base . '/logs');
    ob_end_flush();
    $body = ob_get_clean();
    p0_assert('public boot captures telemetry and evaluates the provider before application output', $booted && $providerRanBeforeResponse);
    p0_assert('booted no-ad page is byte-identical', $body === $plain);
    p0_assert('booted no-ad response creates one counter update and one schema 4 event', is_dir($base . '/state') && count(p0_records($base . '/logs')) === 1);

    $provider = function () { return risk_engine_evaluate(); };
    $guardConfig = \AdGuard\Config::load($base . '/guard.php');
    $guard = new \AdGuard\Guard($guardConfig, $provider);
    p0_assert('normal ad page stays byte-identical in monitor', $guard->processHtml($ad) === $ad);
    $decision = $guard->getDecision();
    $records = p0_records($base . '/logs');
    p0_assert('ad page increments engine rate counter exactly once after the no-ad request', $decision['signals']['rate_limit']['metrics']['window_10s']['count'] === 2);
    p0_assert('ad page creates one event, repeated processing does not duplicate it', count($records) === 2 && $guard->processHtml($ad) === $ad && count(p0_records($base . '/logs')) === 2);
    $record = $records[1];
    $storage = new \RiskEngine\Storage\FileStorage($base . '/state');
    p0_assert('engine uses untrusted-proxy peer bucket', isset($storage->read('rate:203.0.113.10')['windows']));
    p0_assert('guard telemetry independently trusts XFF and records a different client', $record['network']['raw_ip'] === '198.51.100.8' && $storage->read('rate:198.51.100.8') === array());
    p0_assert('new event uses nested schema 4 and retains bounded full UA', $record['schema_version'] === 4 && $record['headers']['user_agent'] === $_SERVER['HTTP_USER_AGENT']);
    p0_assert('schema 4 retains bounded rate window metrics from the engine result', isset($decision['signals']['rate_limit']['metrics']['window_10s']) && isset($record['risk']['signals']['rate_limit']['metrics']['window_10s']));
    $serialized = json_encode($record);
    p0_assert('query value, Authorization and cookie plaintext are absent', strpos($serialized, 'phase0-query-secret') === false && strpos($serialized, 'phase0-authorization-secret') === false && strpos($serialized, 'phase0-cookie-secret') === false);
    p0_assert('schema 4 adds peer, response and stable event identity fields', isset($record['network']['peer_ip'], $record['response'], $record['event_id']) && array_key_exists('forwarded_chain', $record['network']));

    // Use the real signal and combiner; only the policy mode differs.
    $_SERVER['HTTP_USER_AGENT'] = 'curl/8.0';
    $guardSettings['mode'] = 'enforce';
    p0_config($base . '/enforce.php', $guardSettings);
    $enforce = new \AdGuard\Guard(\AdGuard\Config::load($base . '/enforce.php'), $provider);
    $curlDecision = $enforce->getDecision();
    p0_assert('real curl UA alone produces 40 points while behavior remains zero', $curlDecision['signals']['user_agent']['score'] === 40 && $curlDecision['signals']['rate_limit']['score'] === 0 && $curlDecision['signals']['visitor_rate']['score'] === 0 && $curlDecision['signals']['session_churn']['score'] === 0);
    p0_assert('v1 enforce hard-denies ads for UA alone', $curlDecision['action'] === 'DENY' && $curlDecision['policy_reason'] === 'hard_deny_signal:user_agent');
    $denied = $enforce->processHtml($ad);
    p0_assert('UA deny removes bootstrap while keeping page content', strpos($denied, 'pagead2.googlesyndication.com') === false && strpos($denied, 'PHASE0_AD') !== false);
    $monitor = new \AdGuard\Guard($guardConfig, $provider);
    p0_assert('same UA in monitor preserves complete HTML and records would-deny', $monitor->processHtml($ad) === $ad && $monitor->getDecision()['action'] === 'MONITOR_DENY');

    $_SERVER['HTTP_USER_AGENT'] = str_repeat('A', 1400);
    $logger = new \AdGuard\DecisionLogger($guardConfig);
    $logger->log($decision, array('adsense_detected' => true));
    $records = p0_records($base . '/logs');
    $last = $records[count($records) - 1];
    p0_assert('UA is truncated to 1024 bytes rather than stored without a bound', $last['headers']['user_agent'] === str_repeat('A', 1024));
    $explicit = new \AdGuard\Guard($guardConfig, $provider);
    $explicit->adsAllowed();
    $before = count(p0_records($base . '/logs'));
    $explicit->processHtml($plain);
    p0_assert('explicit adsAllowed marks an opportunity even if final HTML has no ads', count(p0_records($base . '/logs')) === $before + 1);
    p0_assert('template and built-in policy both default to monitor', \AdGuard\Config::defaults()['mode'] === 'monitor' && \AdGuard\Config::load(dirname(__DIR__) . '/config/guard.php')->get('mode') === 'monitor');
} finally {
    $_SERVER = $savedServer;
    $_COOKIE = $savedCookies;
    putenv($savedGuardEnv === false ? 'AD_GUARD_CONFIG' : 'AD_GUARD_CONFIG=' . $savedGuardEnv);
    putenv($savedEngineEnv === false ? 'RISK_ENGINE_CONFIG' : 'RISK_ENGINE_CONFIG=' . $savedEngineEnv);
    p0_remove($base, realpath($base));
}
echo "\nPhase 0 characterization: " . $assertions . ' assertions, ' . count($failures) . " failures.\n";
exit($failures ? 1 : 0);
