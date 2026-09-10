<?php
/**
 * HTTP endpoint fixture for cookie-header-http-test.php. Served by a real
 * PHP dev server (not invoked via CLI) so header()'s Set-Cookie actually
 * becomes an observable HTTP response header.
 */
require_once __DIR__ . '/../../src/RequestContext.php';
require_once __DIR__ . '/../../src/SignalResult.php';
require_once __DIR__ . '/../../src/SignalInterface.php';
require_once __DIR__ . '/../../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../../src/Storage/FileStorage.php';
require_once __DIR__ . '/../../src/Signals/SessionChurnSignal.php';

$storage = new \RiskEngine\Storage\FileStorage(sys_get_temp_dir() . '/risk-engine-http-test-' . uniqid());
$signal = new \RiskEngine\Signals\SessionChurnSignal(1.0, 600, 2, '__rek_id_test', 31536000);
$ctx = new \RiskEngine\RequestContext($_SERVER, $_COOKIE, time());

$result = $signal->evaluate($ctx, $storage, true);
echo json_encode($result->toArray());
