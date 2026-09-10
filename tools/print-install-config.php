<?php
$prepend = realpath(__DIR__ . '/../auto-prepend.php');
if ($prepend === false) {
    fwrite(STDERR, "Could not resolve ad-guard/auto-prepend.php\n");
    exit(1);
}
echo 'auto_prepend_file=' . $prepend . PHP_EOL;
