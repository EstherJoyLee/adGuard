<?php
namespace RiskEngine\Signals;

use RiskEngine\RequestContext;
use RiskEngine\SignalInterface;
use RiskEngine\SignalResult;
use RiskEngine\Storage\StorageInterface;

/**
 * Detects a single IP cycling through many "new" client identities in a
 * short window. Uses the engine's OWN first-party cookie rather than the
 * host's PHP session -- see README.md "Why an engine-owned cookie": a
 * portable module can't assume the host calls session_start() or never
 * regenerates its session ID for unrelated reasons. A client that never
 * persists cookies (common for unsophisticated bots/scripts) looks "new" on
 * every request and trips this fast -- a useful side effect, not the
 * primary mechanism.
 *
 * Storage shape per IP: {"count": N, "window_start": ts, "updated_at": ts}.
 */
class SessionChurnSignal implements SignalInterface
{
    private $windowSeconds;
    private $threshold;
    private $cookieName;
    private $cookieTtlSeconds;

    public function __construct($weight, $windowSeconds, $threshold, $cookieName, $cookieTtlSeconds = 31536000)
    {
        // Kept for API compatibility. ScoreCombiner owns weighting.
        $this->windowSeconds = max(1, (int)$windowSeconds);
        $this->threshold = max(1, (int)$threshold);
        $cookieName = (string)$cookieName;
        $this->cookieName = $cookieName !== '' ? $cookieName : '__rek_id';
        $this->cookieTtlSeconds = max(60, (int)$cookieTtlSeconds);
    }

    public function getName()
    {
        return 'session_churn';
    }

    public function evaluate(RequestContext $context, StorageInterface $storage, $mutate)
    {
        $ip = $context->getIp();
        if ($ip === '') {
            return SignalResult::neutral($this->getName(), 'no client IP available');
        }

        $hasIdentity = $context->getCookie($this->cookieName) !== null;
        $key = 'churn:' . $ip;
        $now = $context->now();

        /*
         * Only count a "new identity" if we actually succeeded in ISSUING one.
         *
         * issueIdentityCookie() bails out when headers are already sent, which
         * happens on any host page that echoes output before calling the
         * engine (extremely common: most PHP pages emit <!DOCTYPE> before
         * their <head> include runs, and whether it trips depends on the
         * host's output_buffering ini setting). If we counted those requests
         * as new identities we would increment on EVERY request forever --
         * the visitor can never present a cookie we never managed to set --
         * and a completely ordinary visitor would cross the churn threshold
         * within a handful of page views. Counting only successfully-issued
         * identities makes the signal degrade to "does nothing" instead of
         * "blocks everyone" when it cannot set its cookie.
         */
        $issuedNow = false;
        if ($mutate && !$hasIdentity) {
            $issuedNow = $this->issueIdentityCookie($context);
        }
        $countable = !$hasIdentity && $issuedNow;

        if ($mutate) {
            $data = $storage->withLock($key, function ($current) use ($now, $countable) {
                return $this->advance($current, $now, $countable);
            });
        } else {
            $data = $storage->read($key);
        }

        $degraded = $mutate && !$hasIdentity && !$issuedNow;
        return $this->score($data, $now, $hasIdentity, $degraded);
    }

    private function advance($current, $now, $countable)
    {
        $windowStart = isset($current['window_start']) ? (int)$current['window_start'] : $now;
        $count = isset($current['count']) ? (int)$current['count'] : 0;

        if (($now - $windowStart) > $this->windowSeconds) {
            $windowStart = $now;
            $count = 0;
        }
        if ($countable) {
            $count++;
        }

        return array('count' => $count, 'window_start' => $windowStart, 'updated_at' => $now);
    }

    private function score($data, $now, $hasIdentity, $degraded = false)
    {
        $storageDegraded = isset($data['__risk_engine_storage_degraded']);
        $windowStart = isset($data['window_start']) ? (int)$data['window_start'] : $now;
        $count = isset($data['count']) ? (int)$data['count'] : 0;
        $stale = ($now - $windowStart) > $this->windowSeconds;
        if ($stale) {
            $count = 0;
        }

        $triggered = $count > $this->threshold;
        $score = 0;
        $reason = '';
        if ($triggered) {
            $over = $count - $this->threshold;
            $raw = 40 + min(60, round(($over / $this->threshold) * 60));
            $score = max(0, min(100, (int)round($raw)));
            $reason = $count . ' new identities from this IP in ' . $this->windowSeconds . 's (threshold ' . $this->threshold . ')';
        }

        if ($degraded) {
            $reason = $reason !== ''
                ? $reason . ' (NOTE: identity cookie could not be set -- headers already sent; this request was not counted)'
                : 'identity cookie could not be set (headers already sent by the host page) -- churn counting disabled for this request';
        }
        if ($storageDegraded) {
            $reason = $reason !== '' ? $reason . '; risk storage unavailable' : 'risk storage unavailable';
        }

        return new SignalResult(
            $this->getName(),
            $score,
            $triggered,
            $reason,
            array(
                'count' => $count,
                'window_seconds' => $this->windowSeconds,
                'threshold' => $this->threshold,
                'stale' => $stale,
                'request_had_identity_cookie' => $hasIdentity,
                'cookie_issue_degraded' => $degraded,
                'storage_degraded' => $storageDegraded,
            )
        );
    }

    /** protected so tests can simulate a host where headers are not yet sent. */
    protected function issueIdentityCookie(RequestContext $context)
    {
        if (headers_sent()) {
            return false;
        }
        $value = $this->generateToken();
        $expires = time() + $this->cookieTtlSeconds;
        $header = $this->cookieName . '=' . rawurlencode($value)
            . '; Expires=' . gmdate('D, d-M-Y H:i:s', $expires) . ' GMT'
            . '; Path=/; HttpOnly';
        if ($context->isHttps()) {
            $header .= '; Secure';
        }
        // SameSite via a raw header string, not setcookie()'s options array
        // (that array form is PHP 7.3+ only).
        $header .= '; SameSite=Lax';
        header('Set-Cookie: ' . $header, false);
        return true;
    }

    private function generateToken()
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes(24, $strong);
            if ($bytes !== false && $strong === true) {
                return bin2hex($bytes);
            }
        }
        return hash('sha256', uniqid('', true) . ':' . mt_rand() . ':' . microtime(true));
    }
}
