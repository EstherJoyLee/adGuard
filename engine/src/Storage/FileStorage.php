<?php
namespace RiskEngine\Storage;

/**
 * Default zero-setup storage adapter: one small JSON file per key, sharded
 * into subdirectories by hash prefix so a single directory never has to
 * hold an unbounded number of files.
 *
 * Hardening (verified in risk-engine/tests/concurrency-storage-test.php):
 *  - withLock() uses a bounded, non-blocking flock retry. Under contention
 *    it never hangs the calling page -- after LOCK_RETRIES attempts it
 *    fails open, running $callback against an empty array and returning
 *    that without persisting, exactly like a missing/corrupt file.
 *  - The storage directory (and each shard directory) gets a same-directory
 *    access guard on first write: an .htaccess denying all requests (both
 *    Apache 2.2 and 2.4 syntax), an IIS web.config deny rule, and an empty
 *    index.html.
 *    This is defense in depth, not a substitute for moving storage/ outside
 *    the web root where the host allows it -- nginx and other servers don't
 *    honor .htaccess at all.
 *  - gc() actually removes entries whose own "updated_at" is older than
 *    the given age. No cron: the caller (Engine) triggers gc()
 *    probabilistically on a small fraction of real requests, the same
 *    pattern PHP's own session GC uses.
 */
class FileStorage implements StorageInterface
{
    const LOCK_RETRIES = 20;
    const LOCK_RETRY_MICROSECONDS = 10000; // 10ms; 20 retries -> 200ms worst case

    private $basePath;
    private $baseProvisioned = false;

    public function __construct($basePath)
    {
        $this->basePath = rtrim((string)$basePath, '/\\');
    }

    public function withLock($key, $callback)
    {
        $path = $this->pathFor($key);
        $this->ensureDirFor($path);

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return $this->degradedCallback($callback, 'open_failed');
        }

        $locked = false;
        for ($attempt = 0; $attempt < self::LOCK_RETRIES; $attempt++) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(self::LOCK_RETRY_MICROSECONDS);
        }

        if (!$locked) {
            fclose($handle);
            return $this->degradedCallback($callback, 'lock_timeout');
        }

        $current = $this->decode(stream_get_contents($handle));
        $updated = call_user_func($callback, $current);
        if (!is_array($updated)) {
            $updated = array();
        }

        $encoded = json_encode($updated);
        $persisted = false;
        if ($encoded !== false) {
            $ready = rewind($handle) && ftruncate($handle, 0);
            $written = $ready ? fwrite($handle, $encoded) : false;
            $persisted = $written !== false && $written === strlen($encoded) && fflush($handle);
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        if (!$persisted) {
            $updated['__risk_engine_storage_degraded'] = 'write_failed';
        }
        return $updated;
    }

    public function read($key)
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return array();
        }
        $raw = @file_get_contents($path);
        return $raw === false ? array('__risk_engine_storage_degraded' => 'read_failed') : $this->decode($raw);
    }

    public function gc($maxAgeSeconds)
    {
        if (!is_dir($this->basePath)) {
            return 0;
        }

        $maxAgeSeconds = max(0, (int)$maxAgeSeconds);
        $now = time();
        $removed = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->getExtension() !== 'json') {
                continue;
            }
            $data = $this->decode(@file_get_contents($f->getPathname()));
            $updatedAt = isset($data['updated_at']) ? (int)$data['updated_at'] : 0;
            if ($updatedAt === 0 || ($now - $updatedAt) > $maxAgeSeconds) {
                if (@unlink($f->getPathname())) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function decode($raw)
    {
        if ($raw === false || $raw === null || $raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function degradedCallback($callback, $reason)
    {
        $updated = call_user_func($callback, array('__risk_engine_storage_degraded' => (string)$reason));
        if (!is_array($updated)) {
            $updated = array();
        }
        $updated['__risk_engine_storage_degraded'] = (string)$reason;
        return $updated;
    }

    private function pathFor($key)
    {
        $hash = md5((string)$key);
        $shard = substr($hash, 0, 2);
        return $this->basePath . '/' . $shard . '/' . $hash . '.json';
    }

    private function ensureDirFor($path)
    {
        if (!$this->baseProvisioned) {
            if (!is_dir($this->basePath)) {
                @mkdir($this->basePath, 0700, true);
            }
            $this->guardDirectory($this->basePath);
            $this->baseProvisioned = true;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
            $this->guardDirectory($dir);
        }
    }

    private function guardDirectory($dir)
    {
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
            );
        }
        $indexGuard = $dir . '/index.html';
        if (!is_file($indexGuard)) {
            @file_put_contents($indexGuard, '');
        }
        $iisGuard = $dir . '/web.config';
        if (!is_file($iisGuard)) {
            @file_put_contents(
                $iisGuard,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration><system.webServer><security><authorization>"
                . "<remove users=\"*\" /><add accessType=\"Deny\" users=\"*\" />"
                . "</authorization></security></system.webServer></configuration>\n"
            );
        }
    }
}
