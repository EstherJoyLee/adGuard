<?php
/**
 * Worker process for concurrency-storage-test.php: performs N sequential
 * withLock() read-modify-write increments against ONE shared key. The
 * orchestrator launches many of these truly in parallel (via proc_open) so
 * their increments race against each other for real, proving withLock()
 * loses no updates under genuine contention.
 *
 * Args: <storagePath> <key> <incrementsCount>
 */
require_once __DIR__ . '/../../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../../src/Storage/FileStorage.php';

$storagePath = $argv[1];
$key = $argv[2];
$count = (int)$argv[3];

$storage = new \RiskEngine\Storage\FileStorage($storagePath);
for ($i = 0; $i < $count; $i++) {
    $storage->withLock($key, function ($current) {
        $n = isset($current['n']) ? (int)$current['n'] : 0;
        return array('n' => $n + 1, 'updated_at' => time());
    });
}
echo "done\n";
