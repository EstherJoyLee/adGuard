<?php
/** Loopback-only fixture enabled by tools/phase0-benchmark.ps1. */
if (getenv('PHASE0_BASELINE') !== '1' || !in_array(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', array('127.0.0.1', '::1'), true)) {
    http_response_code(404);
    exit;
}
$scenario = isset($_GET['scenario']) ? (string)$_GET['scenario'] : '';
$allowed = array('minimal', 'guard-no-ads', 'plain-ads', 'guard-ads', 'guard-write-failure', 'viewer-proxy');
if (!in_array($scenario, $allowed, true)) {
    http_response_code(400);
    exit;
}
$base = getenv('PHASE0_BASE_DIR');
if ($base === false || !is_dir($base)) {
    http_response_code(503);
    exit;
}
$slot = isset($_GET['slot']) ? max(1, min(64, (int)$_GET['slot'])) : 1;
// Synthetic direct clients avoid driving default thresholds into abuse bands.
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $slot;
$_COOKIE['__rek_id'] = 'phase0-synthetic-visitor-' . $slot;
putenv('AD_GUARD_CONFIG=' . $base . '/guard.php');
putenv('RISK_ENGINE_CONFIG=' . $base . ($scenario === 'guard-write-failure' ? '/engine-blocked.php' : '/engine.php'));

if ($scenario === 'viewer-proxy') {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    require dirname(dirname(__DIR__)) . '/viewer.php';
    exit;
}
$isGuarded = strpos($scenario, 'guard-') === 0;
$hasAds = in_array($scenario, array('plain-ads', 'guard-ads', 'guard-write-failure'), true);
$html = '<!doctype html><html><head><title>Phase 0</title></head><body><main>PHASE0_CONTENT</main>';
if ($hasAds) {
    $html .= '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-synthetic"></script>';
}
$html .= '</body></html>';
$start = microtime(true);
ob_start();
if ($isGuarded) {
    require dirname(dirname(__DIR__)) . '/adguard.php';
    ad_guard_boot();
}
echo $html;
if ($isGuarded) {
    ob_end_flush();
}
$output = ob_get_clean();
$elapsed = (microtime(true) - $start) * 1000;
header('Content-Type: text/html; charset=UTF-8');
header('X-Phase0-Elapsed-Ms: ' . sprintf('%.6f', $elapsed));
header('X-Phase0-Peak-Bytes: ' . memory_get_peak_usage(false));
header('X-Phase0-Peak-Allocated: ' . memory_get_peak_usage(true));
if ($isGuarded && $hasAds) {
    $decision = ad_guard_decision(); // Cached: normal ad output already evaluated.
    header('X-Phase0-Risk: ' . $decision['engine_level']);
    header('X-Phase0-Degraded: ' . ($decision['degraded'] ? '1' : '0'));
}
echo $output;
