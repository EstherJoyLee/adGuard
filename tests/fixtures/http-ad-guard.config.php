<?php
return array(
    'mode' => 'enforce',
    'excluded_paths' => array(),
    'logging' => array(
        'enabled' => true,
        'path' => sys_get_temp_dir() . '/ad-guard-http-validation/logs',
        'hmac_key_path' => sys_get_temp_dir() . '/ad-guard-http-validation/.hmac-key',
    ),
);
