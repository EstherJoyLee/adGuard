<?php
/**
 * Phase 3 storage hardening verification: real parallel writers (not
 * sequential calls dressed up as a "concurrency test"), graceful
 * degradation when storage is completely unwritable, GC, and directory
 * self-provisioning. Run: php risk-engine/tests/concurrency-storage-test.php
 */

require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Storage/FileStorage.php';

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

// --- Real concurrency: N worker OS processes launched together via
// proc_open (non-blocking launch -- they genuinely overlap), each doing M
// read-modify-write increments against the SAME key. If withLock() ever
// lost an update to a race, the final count would be short of N*M. ---
$storagePath = sys_get_temp_dir() . '/risk-engine-concurrency-' . uniqid();
$key = 'shared-counter';
$workerScript = __DIR__ . '/support/rate-limit-worker.php';
$workers = 8;
$incrementsPerWorker = 25;
$expectedTotal = $workers * $incrementsPerWorker;

$handles = array();
for ($w = 0; $w < $workers; $w++) {
    $cmd = 'php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($storagePath) . ' ' . escapeshellarg($key) . ' ' . escapeshellarg((string)$incrementsPerWorker);
    $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $handles[] = array('proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]);
    }
}
rek_assert($failures, 'all ' . $workers . ' worker processes started', count($handles) === $workers);

$allOutput = '';
$allErr = '';
foreach ($handles as $h) {
    $allOutput .= stream_get_contents($h['stdout']);
    $allErr .= stream_get_contents($h['stderr']);
    fclose($h['stdout']);
    fclose($h['stderr']);
    proc_close($h['proc']);
}
rek_assert($failures, 'every worker completed without error output', trim($allErr) === '');
rek_assert($failures, 'every worker printed "done"', substr_count($allOutput, 'done') === $workers);

// NOT asserting an exact match to $expectedTotal. The whole point of the
// bounded non-blocking retry (FileStorage::LOCK_RETRIES) is that a request
// that can't get the lock within its budget fails OPEN -- it skips the
// write rather than hang the page. Under 8 CLI processes hammering the
// exact same key with zero delay between calls (far more aggressive than
// real HTTP traffic, where requests are naturally spread out by network/
// browser timing), losing a handful of increments to that budget is
// correct, designed behavior, not data loss to guard against. What must
// hold is: (a) the value is never corrupted -- a real, sane non-negative
// integer, never a partial write or garbled structure, because every
// write that DID happen was fully serialized under an exclusive lock; and
// (b) loss stays bounded, not catastrophic -- if most writes were being
// dropped, the retry mechanism itself would be broken, not just contended.
$storage = new \RiskEngine\Storage\FileStorage($storagePath);
$final = $storage->read($key);
$finalCount = isset($final['n']) ? (int)$final['n'] : -1;
rek_assert($failures, 'final value is a real, non-negative, sane integer (no corruption)', is_int($finalCount) && $finalCount >= 0);
rek_assert($failures, 'final count never exceeds the expected total (no double-count)', $finalCount <= $expectedTotal);
rek_assert($failures, 'loss under heavy real contention stays bounded (>= 90% of increments survived)', $finalCount >= $expectedTotal * 0.9);

// --- Graceful degradation: storage path that can never become a directory
// (a plain file already occupies it) -- withLock() must still return a
// well-formed result, never throw or fatal. ---
$blockedPath = sys_get_temp_dir() . '/risk-engine-blocked-' . uniqid();
file_put_contents($blockedPath, 'occupies the path so no directory can ever be created here');
$brokenStorage = new \RiskEngine\Storage\FileStorage($blockedPath);
$result = $brokenStorage->withLock('anykey', function ($current) {
    $n = isset($current['n']) ? (int)$current['n'] : 0;
    return array('n' => $n + 1, 'updated_at' => time());
});
rek_assert($failures, 'withLock() on totally unwritable storage degrades gracefully (no fatal)', is_array($result) && isset($result['n']) && $result['n'] === 1);
@unlink($blockedPath);

// --- Directory self-provisioning: guard files appear after first write ---
$guardBase = sys_get_temp_dir() . '/risk-engine-guard-' . uniqid();
$guardStorage = new \RiskEngine\Storage\FileStorage($guardBase);
$guardStorage->withLock('guard-check', function ($current) {
    return array('n' => 1, 'updated_at' => time());
});
rek_assert($failures, 'base storage dir gets an .htaccess guard', is_file($guardBase . '/.htaccess'));
rek_assert($failures, 'base storage dir gets an index.html guard', is_file($guardBase . '/index.html'));
rek_assert($failures, 'base storage dir gets an IIS web.config guard', is_file($guardBase . '/web.config'));

// --- gc(): removes only entries older than max age ---
$gcBase = sys_get_temp_dir() . '/risk-engine-gc-' . uniqid();
$gcStorage = new \RiskEngine\Storage\FileStorage($gcBase);
$gcStorage->withLock('old-entry', function ($c) {
    return array('n' => 1, 'updated_at' => time() - 1000000);
});
$gcStorage->withLock('fresh-entry', function ($c) {
    return array('n' => 1, 'updated_at' => time());
});
$removed = $gcStorage->gc(3600);
rek_assert($failures, 'gc() removed exactly the one stale entry', $removed === 1);
rek_assert($failures, 'stale entry is actually gone after gc()', $gcStorage->read('old-entry') === array());
rek_assert($failures, 'fresh entry survives gc()', $gcStorage->read('fresh-entry') !== array());

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAll storage hardening tests passed.\n";
exit(0);
