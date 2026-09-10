<?php
/** Configure this absolute file path as PHP's auto_prepend_file. */
require_once __DIR__ . '/adguard.php';

// Normal CLI tools must not unexpectedly buffer/rewrite their output. The
// integration self-test opts in explicitly.
if (PHP_SAPI !== 'cli' || getenv('AD_GUARD_FORCE_AUTO_PREPEND') === '1') {
    ad_guard_boot();
}
