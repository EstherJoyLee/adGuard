<?php
namespace RiskEngine\Storage;

/**
 * Storage contract used by stateful signals (rate-limit, session-churn).
 * The bundled default is FileStorage (JSON files + flock, zero setup). A
 * high-traffic integrator can implement this interface against Redis/a
 * database instead -- no signal code needs to change either way.
 */
interface StorageInterface
{
    /**
     * Atomically read-modify-write the value stored at $key.
     *
     * $callback receives the current value (empty array if none exists yet)
     * and must return the new array to persist. Implementations must never
     * let a lock failure propagate as an exception -- on failure they call
     * $callback with an empty array and return its result without
     * persisting, so callers always get a well-formed value back and can
     * choose to fail open.
     *
     * @param string $key
     * @param callable $callback function(array $current): array
     * @return array
     */
    public function withLock($key, $callback);

    /**
     * Read-only fetch. May race harmlessly with a concurrent writer (readers
     * only ever see a fully-written document, never a partial one).
     *
     * @param string $key
     * @return array empty array if the key has no stored value
     */
    public function read($key);

    /**
     * Best-effort removal of entries whose own "updated_at" is older than
     * $maxAgeSeconds. Returns the number of entries removed. Never fatal.
     *
     * @param int $maxAgeSeconds
     * @return int
     */
    public function gc($maxAgeSeconds);
}
