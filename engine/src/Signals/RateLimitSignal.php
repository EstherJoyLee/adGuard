<?php
namespace RiskEngine\Signals;

use RiskEngine\RequestContext;
use RiskEngine\SignalInterface;
use RiskEngine\SignalResult;
use RiskEngine\Storage\StorageInterface;

/**
 * Per-IP request-rate limiting across one or more fixed windows (e.g. a
 * short burst window and a longer sustained window -- catches both a
 * flash-flood and a slow grind that a single window would miss).
 *
 * Storage shape per IP: {"windows": {"<seconds>": {"count": N,
 * "window_start": ts}, ...}, "updated_at": ts}. Fixed-window counting, not
 * a sliding log -- O(1) per request, bounded size.
 */
class RateLimitSignal implements SignalInterface
{
    /** @var array seconds => allowed_count */
    private $windows;

    public function __construct($weight, $windows)
    {
        // Kept for API compatibility. ScoreCombiner owns weighting.
        $this->windows = is_array($windows) && $windows ? $windows : array(60 => 60);
    }

    public function getName()
    {
        return 'rate_limit';
    }

    public function evaluate(RequestContext $context, StorageInterface $storage, $mutate)
    {
        $ip = $context->getIp();
        if ($ip === '') {
            return SignalResult::neutral($this->getName(), 'no client IP available');
        }

        $key = 'rate:' . $ip;
        $now = $context->now();

        if ($mutate) {
            // $this is captured automatically inside a closure defined in an
            // instance method (PHP 5.4+) -- no explicit `use ($this)`.
            $data = $storage->withLock($key, function ($current) use ($now) {
                return $this->advance($current, $now);
            });
        } else {
            $data = $storage->read($key);
        }

        return $this->score($data, $now);
    }

    private function advance($current, $now)
    {
        $windowsState = isset($current['windows']) && is_array($current['windows']) ? $current['windows'] : array();
        foreach ($this->windows as $seconds => $allowed) {
            $seconds = (int)$seconds;
            $state = isset($windowsState[$seconds]) ? $windowsState[$seconds] : null;
            if (!is_array($state) || !isset($state['window_start']) || ($now - (int)$state['window_start']) > $seconds) {
                $windowsState[$seconds] = array('count' => 1, 'window_start' => $now);
            } else {
                $windowsState[$seconds] = array('count' => (int)$state['count'] + 1, 'window_start' => (int)$state['window_start']);
            }
        }
        return array('windows' => $windowsState, 'updated_at' => $now);
    }

    private function score($data, $now)
    {
        $degraded = isset($data['__risk_engine_storage_degraded']);
        $windowsState = isset($data['windows']) && is_array($data['windows']) ? $data['windows'] : array();
        $maxScore = 0;
        $reasons = array();
        $metrics = array('storage_degraded' => $degraded);

        foreach ($this->windows as $seconds => $allowed) {
            $seconds = (int)$seconds;
            $allowed = max(1, (int)$allowed);
            $state = isset($windowsState[$seconds]) ? $windowsState[$seconds] : null;
            $count = 0;
            $stale = true;
            if (is_array($state) && isset($state['window_start'])) {
                $stale = ($now - (int)$state['window_start']) > $seconds;
                $count = $stale ? 0 : (int)$state['count'];
            }

            $metrics['window_' . $seconds . 's'] = array('count' => $count, 'allowed' => $allowed, 'stale' => $stale);

            if ($count > $allowed) {
                $over = $count - $allowed;
                $windowScore = max(1, (int)min(100, round(($over / $allowed) * 100)));
                if ($windowScore > $maxScore) {
                    $maxScore = $windowScore;
                }
                $reasons[] = $count . ' requests in ' . $seconds . 's window (threshold ' . $allowed . ')';
            }
        }

        if ($degraded) {
            $reasons[] = 'risk storage unavailable';
        }

        $score = max(0, min(100, (int)round($maxScore)));

        return new SignalResult(
            $this->getName(),
            $score,
            count($reasons) > ($degraded ? 1 : 0),
            implode('; ', $reasons),
            $metrics
        );
    }
}
