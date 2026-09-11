<?php
namespace AdGuard\Storage;

require_once dirname(__DIR__) . '/Config.php';

use AdGuard\Config;

/** Bounded, non-throwing, append-only JSONL storage for normalized events. */
class LocalEventStore
{
    private $config;
    private $health;
    private $retentionChecked = false;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->health = array(
            'dropped_event_count' => 0,
            'storage_error_count' => 0,
            'last_error' => '',
            'last_write_duration_ms' => null,
        );
    }

    public function append($event)
    {
        $startedAt = microtime(true);
        if (!$this->config->get('logging.enabled', true)) {
            return $this->result(false, false, 'logging_disabled', $startedAt);
        }
        if (!is_array($event)) {
            return $this->failure('', 'invalid_event', $startedAt);
        }
        $line = json_encode($event, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return $this->failure('', 'json_encode_failed', $startedAt);
        }
        $maxEventBytes = max(1024, min(65536, (int)$this->config->get('telemetry.max_event_bytes', 16384)));
        if (strlen($line) > $maxEventBytes) {
            return $this->failure($this->path(), 'event_too_large', $startedAt);
        }

        $path = $this->path();
        if ($path === '' || !$this->provisionDirectory($path)) {
            return $this->failure($path, 'directory_unavailable', $startedAt);
        }
        $this->pruneExpiredLogs($path);
        $file = $path . '/ad-guard-' . gmdate('Y-m-d') . '.jsonl';
        $handle = @fopen($file, 'ab');
        if ($handle === false) {
            return $this->failure($path, 'open_failed', $startedAt);
        }

        $locked = false;
        $timeoutMs = max(0, min(50, (int)$this->config->get('telemetry.lock_timeout_ms', 2)));
        $deadline = microtime(true) + ($timeoutMs / 1000.0);
        do {
            $locked = @flock($handle, LOCK_EX | LOCK_NB);
            if ($locked || microtime(true) >= $deadline) {
                break;
            }
            usleep(250);
        } while (true);
        if (!$locked) {
            @fclose($handle);
            return $this->failure($path, 'lock_busy', $startedAt);
        }

        $maxDailyBytes = max($maxEventBytes, (int)$this->config->get('logging.max_daily_bytes', 20971520));
        $stat = @fstat($handle);
        $size = is_array($stat) && isset($stat['size']) ? (int)$stat['size'] : 0;
        if ($size + strlen($line) + 1 > $maxDailyBytes) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            return $this->failure($path, 'daily_byte_cap', $startedAt);
        }

        $payload = $line . "\n";
        $written = @fwrite($handle, $payload);
        if ($written === strlen($payload)) {
            @fflush($handle);
        }
        @flock($handle, LOCK_UN);
        @fclose($handle);
        @chmod($file, 0600);
        if ($written !== strlen($payload)) {
            return $this->failure($path, 'write_failed', $startedAt);
        }
        return $this->result(true, false, '', $startedAt);
    }

    public function getHealthMetrics()
    {
        return $this->health;
    }

    private function failure($path, $reason, $startedAt)
    {
        $this->health['dropped_event_count']++;
        $this->health['storage_error_count']++;
        $this->health['last_error'] = substr((string)$reason, 0, 64);
        $result = $this->result(false, true, $reason, $startedAt);
        if ($path !== '' && is_dir($path)) {
            $this->writeHealth($path, $reason, $result['duration_ms']);
        }
        return $result;
    }

    private function result($written, $dropped, $reason, $startedAt)
    {
        $duration = max(0.0, (microtime(true) - (float)$startedAt) * 1000.0);
        $this->health['last_write_duration_ms'] = $duration;
        return array(
            'written' => (bool)$written,
            'dropped' => (bool)$dropped,
            'reason' => substr((string)$reason, 0, 64),
            'duration_ms' => (float)$duration,
        );
    }

    /** Exceptional health writes use immediate non-blocking locking too. */
    private function writeHealth($path, $reason, $durationMs)
    {
        $file = rtrim($path, '/\\') . '/ad-guard-' . gmdate('Y-m-d') . '-health.jsonl';
        $max = max(256, min(8388608, (int)$this->config->get('logging.health_max_daily_bytes', 1048576)));
        $event = json_encode(array(
            'schema_version' => 1,
            'event_type' => 'telemetry_health',
            'timestamp' => gmdate('c'),
            'reason' => substr((string)$reason, 0, 64),
            'dropped_event_count' => $this->health['dropped_event_count'],
            'storage_error_count' => $this->health['storage_error_count'],
            'telemetry_write_duration_ms' => max(0.0, min(60000.0, (float)$durationMs)),
        ), JSON_UNESCAPED_SLASHES);
        if ($event === false || strlen($event) > 1024) {
            return;
        }
        $payload = $event . "\n";
        $handle = @fopen($file, 'ab');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle !== false) {
                @fclose($handle);
            }
            return;
        }
        // Size is authoritative only while holding the writer lock. This
        // second check keeps concurrent failures under the configured cap.
        $stat = @fstat($handle);
        $size = is_array($stat) && isset($stat['size']) ? (int)$stat['size'] : 0;
        if ($size + strlen($payload) <= $max) {
            @fwrite($handle, $payload);
            @fflush($handle);
        }
        @flock($handle, LOCK_UN);
        @fclose($handle);
        @chmod($file, 0600);
    }

    private function path()
    {
        return rtrim((string)$this->config->get('logging.path', ''), '/\\');
    }

    private function provisionDirectory($path)
    {
        if ($path === '' || $path === '.') {
            return false;
        }
        if (!is_dir($path) && !@mkdir($path, 0700, true)) {
            return false;
        }
        @chmod($path, 0700);
        $fixed = array(
            '.htaccess' => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
            'index.html' => '',
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n",
        );
        foreach ($fixed as $name => $content) {
            $file = $path . '/' . $name;
            if (!is_file($file)) {
                @file_put_contents($file, $content);
            }
        }
        return true;
    }

    /** Best-effort retention with a hard per-request directory entry budget. */
    private function pruneExpiredLogs($path)
    {
        if ($this->retentionChecked) {
            return;
        }
        $this->retentionChecked = true;
        $days = (int)$this->config->get('logging.retention_days', 90);
        if ($days <= 0) {
            return;
        }
        $limit = max(1, min(512, (int)$this->config->get('telemetry.retention_scan_limit', 256)));
        $cutoff = time() - ($days * 86400);
        try {
            $iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        } catch (\Exception $exception) {
            return;
        }
        $visited = 0;
        foreach ($iterator as $item) {
            if (++$visited > $limit) {
                break;
            }
            if (!$item->isFile()
                || !preg_match('/^ad-guard-(\d{4})-(\d{2})-(\d{2})(?:-health)?\.jsonl$/D', $item->getFilename(), $match)) {
                continue;
            }
            $stamp = @gmmktime(23, 59, 59, (int)$match[2], (int)$match[3], (int)$match[1]);
            if ($stamp !== false && $stamp < $cutoff) {
                @unlink($item->getPathname());
            }
        }
    }
}
