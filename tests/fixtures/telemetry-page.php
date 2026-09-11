<?php
if (getenv('ADGUARD_PHASE1_FIXTURE') !== '1'
    || !in_array(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', array('127.0.0.1', '::1'), true)) {
    http_response_code(404);
    exit;
}

require dirname(dirname(__DIR__)) . '/adguard.php';
ad_guard_boot();
echo 'PHASE1_HTTP_CONTENT';
