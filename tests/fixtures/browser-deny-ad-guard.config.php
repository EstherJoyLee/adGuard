<?php
/** Browser-runtime fixture: force every engine level into the ad-deny path. */
return array(
    'mode' => 'enforce',
    'excluded_paths' => array(),
    'policy' => array(
        'blocked_levels' => array('NORMAL', 'ELEVATED', 'SUSPICIOUS', 'SEVERE'),
    ),
    'logging' => array(
        'enabled' => true,
        'path' => sys_get_temp_dir() . '/ad-guard-browser-deny/logs',
        'hmac_key_path' => sys_get_temp_dir() . '/ad-guard-browser-deny/.hmac-key',
    ),
);
