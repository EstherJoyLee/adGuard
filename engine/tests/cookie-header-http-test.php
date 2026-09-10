<?php
/**
 * The only thing that can prove SessionChurnSignal's cookie actually
 * reaches a browser: a real HTTP response. PHP's CLI SAPI in this
 * environment reports headers_sent() === true unconditionally (see the
 * note in session-churn-signal-test.php), so this check starts the PHP
 * built-in dev server and inspects a real response over curl instead.
 *
 * Run: php risk-engine/tests/cookie-header-http-test.php
 * Requires `php` and `curl` on PATH.
 */

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

$docRoot = __DIR__ . '/support';
$port = 39217 + (getmypid() % 500); // spread out to reduce collision odds across parallel runs
$host = '127.0.0.1:' . $port;

// All three descriptors explicitly piped -- never left unspecified/inherited.
// On Windows, an unspecified descriptor can end up sharing a handle with
// whatever the PARENT process's own stdout/stderr is (e.g. a pipe to
// another command in a shell chain); if this dev server subprocess then
// outlives proc_terminate() (see below), it keeps that inherited handle
// open forever and anything waiting on EOF from it hangs indefinitely.
$descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
// bypass_shell: on Windows, avoids the extra cmd.exe wrapper proc_open
// otherwise inserts, so the PID we get back is the actual php.exe -- that
// makes tree-termination below target the real process, not a shell that
// already exited leaving its child (the server) orphaned.
$process = proc_open('php -S ' . $host . ' -t ' . escapeshellarg($docRoot), $descriptors, $pipes, $docRoot, null, array('bypass_shell' => true));

if (!is_resource($process)) {
    echo "FAIL: could not start PHP built-in dev server\n";
    exit(1);
}
fclose($pipes[0]);

$status = proc_get_status($process);
$serverPid = isset($status['pid']) ? (int)$status['pid'] : 0;

// Give the server a moment to bind before the first request.
usleep(400000);

// curl, not file_get_contents()'s http:// stream wrapper -- the wrapper's
// $http_response_header mechanism is deprecated as of PHP 8.5 and its
// replacement (http_get_last_response_headers(), 8.4+) isn't available on
// every PHP this project might run its test suite under.
$raw = shell_exec('curl -s -D - -o - ' . escapeshellarg('http://' . $host . '/emit-identity-cookie.php'));
$raw = (string)$raw;
$parts = preg_split('/\r?\n\r?\n/', $raw, 2);
$rawHeaders = isset($parts[0]) ? $parts[0] : '';
$response = isset($parts[1]) ? $parts[1] : false;
$responseHeaders = preg_split('/\r?\n/', $rawHeaders);

// proc_terminate() alone is not reliable for killing a php -S dev server on
// Windows -- it can leave the actual server process running (and its port
// bound) after the script exits. taskkill /T tree-kills everything rooted
// at the PID; proc_terminate() stays as the cross-platform fallback.
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && $serverPid > 0) {
    shell_exec('taskkill /F /T /PID ' . (int)$serverPid . ' 2>NUL');
} else {
    proc_terminate($process);
}
foreach ($pipes as $p) {
    if (is_resource($p)) {
        fclose($p);
    }
}
proc_close($process);

rek_assert($failures, 'dev server responded', $response !== false);

$setCookie = '';
foreach ($responseHeaders as $h) {
    if (stripos($h, 'Set-Cookie:') === 0) {
        $setCookie = $h;
        break;
    }
}
rek_assert($failures, 'response includes a Set-Cookie header', $setCookie !== '');
rek_assert($failures, 'Set-Cookie names the configured cookie', strpos($setCookie, '__rek_id_test=') !== false);
rek_assert($failures, 'Set-Cookie is HttpOnly', stripos($setCookie, 'HttpOnly') !== false);
rek_assert($failures, 'Set-Cookie sets SameSite=Lax', stripos($setCookie, 'SameSite=Lax') !== false);

if ($response !== false) {
    $decoded = json_decode($response, true);
    rek_assert($failures, 'endpoint returned a well-formed signal result', is_array($decoded) && isset($decoded['metrics']['request_had_identity_cookie']));
    if (is_array($decoded)) {
        rek_assert($failures, 'first request correctly had no identity cookie yet', $decoded['metrics']['request_had_identity_cookie'] === false);
    }
}

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAll cookie-header HTTP tests passed.\n";
exit(0);
