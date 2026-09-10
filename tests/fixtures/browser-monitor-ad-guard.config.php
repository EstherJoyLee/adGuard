<?php
/** Browser-runtime normal-user scenarios: evaluate and log, never block ads. */
return array(
    'mode' => 'monitor',
    'excluded_paths' => array(),
    'logging' => array(
        'enabled' => true,
        'path' => sys_get_temp_dir() . '/ad-guard-browser-monitor-20260831/logs',
        'hmac_key_path' => sys_get_temp_dir() . '/ad-guard-browser-monitor-20260831/.hmac-key',
    ),
);
