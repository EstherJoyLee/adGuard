<?php
require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/AdsenseDetector.php';
require_once dirname(__DIR__) . '/src/DecisionLogger.php';
require_once dirname(__DIR__) . '/src/Guard.php';

$telemetryFailures = array();
$telemetryBase = sys_get_temp_dir() . '/adguard-phase1-lifecycle-' . uniqid('', true);
$telemetrySavedServer = $_SERVER;
$telemetrySavedCookies = $_COOKIE;
@mkdir($telemetryBase, 0700, true);

function telemetry_lifecycle_assert(&$failures, $label, $condition)
{
    echo ($condition ? 'ok:   ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures[] = $label;
    }
}

function telemetry_lifecycle_config($base, $name, $extra)
{
    $data = array(
        'mode' => 'monitor',
        'logging' => array(
            'enabled' => true,
            'path' => $base . '/' . $name . '-logs',
            'hmac_key' => 'phase1-lifecycle-key',
        ),
    );
    foreach ((array)$extra as $section => $values) {
        $data[$section] = isset($data[$section]) && is_array($data[$section]) && is_array($values)
            ? array_merge($data[$section], $values)
            : $values;
    }
    $path = $base . '/' . $name . '.php';
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    return \AdGuard\Config::load($path);
}

function telemetry_lifecycle_records($path)
{
    $records = array();
    foreach ((array)glob(rtrim($path, '/\\') . '/ad-guard-*.jsonl') as $file) {
        if (strpos($file, '-health.jsonl') !== false) {
            continue;
        }
        foreach ((array)@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }
    }
    return $records;
}

function telemetry_lifecycle_render($guard, $body)
{
    ob_start();
    $started = $guard->start();
    echo $body;
    ob_end_flush();
    return array($started, ob_get_clean());
}

function telemetry_lifecycle_remove($path, $root)
{
    $resolved = realpath($path);
    if ($resolved === false) {
        return;
    }
    if ($resolved !== $root && strpos($resolved, $root . DIRECTORY_SEPARATOR) !== 0) {
        throw new RuntimeException('Refusing cleanup outside lifecycle test directory');
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                telemetry_lifecycle_remove($path . DIRECTORY_SEPARATOR . $name, $root);
            }
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
}

function telemetry_lifecycle_verdict()
{
    return array(
        'level' => 'NORMAL',
        'score' => 0,
        'reasons' => array(),
        'signals' => array(),
    );
}

try {
    $_SERVER = array(
        'REMOTE_ADDR' => '198.51.100.21',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/plain.php?secret=must-not-be-stored&empty=',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'HTTP_HOST' => 'phase1.example.test',
        'HTTP_USER_AGENT' => str_repeat('U', 5000),
        'HTTP_ACCEPT' => 'text/html',
        'HTTP_AUTHORIZATION' => 'Bearer lifecycle-authorization-secret',
        'HTTP_COOKIE' => '__rek_id=lifecycle-cookie-secret',
    );
    $_COOKIE = array('__rek_id' => 'lifecycle-cookie-secret');
    $plainConfig = telemetry_lifecycle_config($telemetryBase, 'plain', array());
    $plainLogger = new \AdGuard\DecisionLogger($plainConfig);
    $plainProviderCalls = 0;
    $plainGuard = new \AdGuard\Guard($plainConfig, function () use (&$plainProviderCalls) {
        $plainProviderCalls++;
        return telemetry_lifecycle_verdict();
    }, $plainLogger);
    http_response_code(202);
    $plainBody = '<!doctype html><html><body>PHASE1_PLAIN</body></html>';
    list($plainStarted, $plainOutput) = telemetry_lifecycle_render($plainGuard, $plainBody);
    $plainDecision = $plainGuard->getDecision();
    $plainRecords = telemetry_lifecycle_records($telemetryBase . '/plain-logs');
    $plainRecord = count($plainRecords) === 1 ? $plainRecords[0] : array();

    telemetry_lifecycle_assert($telemetryFailures, 'active no-ad response starts and remains byte-identical',
        $plainStarted && $plainOutput === $plainBody);
    telemetry_lifecycle_assert($telemetryFailures, 'no-ad request evaluates provider once and records one event',
        $plainProviderCalls === 1 && count($plainRecords) === 1 && $plainDecision['action'] === 'ALLOW');
    telemetry_lifecycle_assert($telemetryFailures, 'no-ad event is schema 4 with stable request identity',
        isset($plainRecord['schema_version'], $plainRecord['event_id'], $plainRecord['request_id'])
        && $plainRecord['schema_version'] === 4
        && $plainRecord['event_type'] === 'request'
        && $plainRecord['event_id'] !== ''
        && $plainRecord['request_id'] !== '');
    telemetry_lifecycle_assert($telemetryFailures, 'final response and guard timings are measured',
        isset($plainRecord['response'], $plainRecord['agent'])
        && $plainRecord['response']['status'] === 202
        && $plainRecord['response']['bytes'] === strlen($plainBody)
        && is_float($plainRecord['response']['duration_ms'])
        && is_float($plainRecord['agent']['guard_duration_ms']));
    telemetry_lifecycle_assert($telemetryFailures, 'oversized UA is bounded and sensitive values are absent',
        isset($plainRecord['headers']['user_agent'])
        && strlen($plainRecord['headers']['user_agent']) === 1024
        && strpos(json_encode($plainRecord), 'must-not-be-stored') === false
        && strpos(json_encode($plainRecord), 'lifecycle-authorization-secret') === false
        && strpos(json_encode($plainRecord), 'lifecycle-cookie-secret') === false);
    telemetry_lifecycle_assert($telemetryFailures, 'write health metrics are observable outside the event being written',
        method_exists($plainLogger, 'getLastWriteMetrics')
        && $plainLogger->getLastWriteMetrics()['written'] === true
        && is_float($plainLogger->getLastWriteMetrics()['duration_ms']));

    $_SERVER['REQUEST_URI'] = '/ad.php';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0';
    $adConfig = telemetry_lifecycle_config($telemetryBase, 'ad', array());
    $adLogger = new \AdGuard\DecisionLogger($adConfig);
    $adCalls = 0;
    $adGuard = new \AdGuard\Guard($adConfig, function () use (&$adCalls) {
        $adCalls++;
        return telemetry_lifecycle_verdict();
    }, $adLogger);
    $adBody = '<html><body>PHASE1_AD<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-test"></script></body></html>';
    list($adStarted, $adOutput) = telemetry_lifecycle_render($adGuard, $adBody);
    $adRecords = telemetry_lifecycle_records($telemetryBase . '/ad-logs');
    telemetry_lifecycle_assert($telemetryFailures, 'monitor ad response stays byte-identical and records once',
        $adStarted && $adOutput === $adBody && $adCalls === 1 && count($adRecords) === 1);
    telemetry_lifecycle_assert($telemetryFailures, 'ad event retains delivery evidence without a duplicate event',
        $adRecords[0]['advertising']['adsense_detected'] === true
        && $adRecords[0]['advertising']['ad_delivery']['bootstrap']['provided'] === 1);

    $_SERVER['REQUEST_URI'] = '/malformed.php';
    $malformedConfig = telemetry_lifecycle_config($telemetryBase, 'malformed', array());
    @mkdir($telemetryBase . '/malformed-logs', 0700, true);
    file_put_contents($telemetryBase . '/malformed-logs/ad-guard-' . gmdate('Y-m-d') . '.jsonl', "{malformed\n");
    $malformedGuard = new \AdGuard\Guard($malformedConfig, function () {
        return telemetry_lifecycle_verdict();
    });
    list($malformedStarted, $malformedOutput) = telemetry_lifecycle_render($malformedGuard, 'MALFORMED_EXISTING_LOG_SURVIVES');
    telemetry_lifecycle_assert($telemetryFailures, 'malformed existing JSONL does not alter or block the response',
        $malformedStarted && $malformedOutput === 'MALFORMED_EXISTING_LOG_SURVIVES'
        && count(telemetry_lifecycle_records($telemetryBase . '/malformed-logs')) === 1);

    $_SERVER['REQUEST_URI'] = '/storage-failure.php';
    $blocked = $telemetryBase . '/blocked-parent';
    file_put_contents($blocked, 'not a directory');
    $failureConfig = telemetry_lifecycle_config($telemetryBase, 'failure', array(
        'logging' => array('path' => $blocked . '/logs'),
    ));
    $failureLogger = new \AdGuard\DecisionLogger($failureConfig);
    $failureGuard = new \AdGuard\Guard($failureConfig, function () {
        return telemetry_lifecycle_verdict();
    }, $failureLogger);
    list($failureStarted, $failureOutput) = telemetry_lifecycle_render($failureGuard, 'STORAGE_FAILURE_SURVIVES');
    telemetry_lifecycle_assert($telemetryFailures, 'log open/provision failure is dropped without changing output',
        $failureStarted && $failureOutput === 'STORAGE_FAILURE_SURVIVES'
        && method_exists($failureLogger, 'getLastWriteMetrics')
        && $failureLogger->getLastWriteMetrics()['dropped'] === true);

    $_SERVER['REQUEST_URI'] = '/open-failure.php';
    $openFailureConfig = telemetry_lifecycle_config($telemetryBase, 'open-failure', array());
    $openFailurePath = $telemetryBase . '/open-failure-logs';
    @mkdir($openFailurePath, 0700, true);
    @mkdir($openFailurePath . '/ad-guard-' . gmdate('Y-m-d') . '.jsonl', 0700, true);
    $openFailureLogger = new \AdGuard\DecisionLogger($openFailureConfig);
    $openFailureGuard = new \AdGuard\Guard($openFailureConfig, function () {
        return telemetry_lifecycle_verdict();
    }, $openFailureLogger);
    list($openFailureStarted, $openFailureOutput) = telemetry_lifecycle_render($openFailureGuard, 'OPEN_FAILURE_SURVIVES');
    telemetry_lifecycle_assert($telemetryFailures, 'daily log fopen failure is dropped without changing output',
        $openFailureStarted && $openFailureOutput === 'OPEN_FAILURE_SURVIVES'
        && $openFailureLogger->getLastWriteMetrics()['reason'] === 'open_failed');

} finally {
    $_SERVER = $telemetrySavedServer;
    $_COOKIE = $telemetrySavedCookies;
    telemetry_lifecycle_remove($telemetryBase, realpath($telemetryBase));
}

if ($telemetryFailures) {
    echo "\n" . count($telemetryFailures) . " telemetry lifecycle failure(s).\n";
    exit(1);
}
echo "\nTelemetry lifecycle tests passed.\n";
