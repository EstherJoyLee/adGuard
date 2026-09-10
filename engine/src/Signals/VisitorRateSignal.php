<?php
namespace RiskEngine\Signals;

use RiskEngine\RequestContext;
use RiskEngine\SignalInterface;
use RiskEngine\SignalResult;
use RiskEngine\Storage\StorageInterface;

/**
 * Request velocity for one persistent engine-owned visitor identity.
 * Unlike the per-IP limiter, this does not punish a whole carrier NAT,
 * office, school, or cafe for one visitor's repeated page reloads.
 */
class VisitorRateSignal implements SignalInterface
{
    private $windows;
    private $cookieName;

    public function __construct($weight, $windows, $cookieName)
    {
        // $weight remains in the signature for backwards consistency with
        // other signals. Weighting is applied exactly once by ScoreCombiner.
        $this->windows = is_array($windows) && $windows ? $windows : array(60 => 30);
        $cookieName = (string)$cookieName;
        $this->cookieName = $cookieName !== '' ? $cookieName : '__rek_id';
    }

    public function getName()
    {
        return 'visitor_rate';
    }

    public function evaluate(RequestContext $context, StorageInterface $storage, $mutate)
    {
        $visitor = $context->getCookie($this->cookieName);
        if ($visitor === null || $visitor === '') {
            return SignalResult::neutral($this->getName(), 'no persistent visitor identity yet');
        }

        $key = 'visitor-rate:' . hash('sha256', (string)$visitor);
        $now = $context->now();
        if ($mutate) {
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
            $seconds = max(1, (int)$seconds);
            $state = isset($windowsState[$seconds]) ? $windowsState[$seconds] : null;
            if (!is_array($state) || !isset($state['window_start']) || ($now - (int)$state['window_start']) > $seconds) {
                $windowsState[$seconds] = array('count' => 1, 'window_start' => $now);
            } else {
                $windowsState[$seconds] = array(
                    'count' => isset($state['count']) ? (int)$state['count'] + 1 : 1,
                    'window_start' => (int)$state['window_start'],
                );
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
            $seconds = max(1, (int)$seconds);
            $allowed = max(1, (int)$allowed);
            $state = isset($windowsState[$seconds]) ? $windowsState[$seconds] : null;
            $count = 0;
            $stale = true;
            if (is_array($state) && isset($state['window_start'])) {
                $stale = ($now - (int)$state['window_start']) > $seconds;
                $count = $stale ? 0 : (int)$state['count'];
            }
            $metrics['window_' . $seconds . 's'] = array(
                'count' => $count, 'allowed' => $allowed, 'stale' => $stale,
            );
            if ($count > $allowed) {
                $over = $count - $allowed;
                $windowScore = max(1, (int)min(100, round(($over / $allowed) * 100)));
                $maxScore = max($maxScore, $windowScore);
                $reasons[] = $count . ' requests by one visitor in ' . $seconds . 's (threshold ' . $allowed . ')';
            }
        }

        if ($degraded) {
            $reasons[] = 'risk storage unavailable';
        }

        return new SignalResult(
            $this->getName(),
            $maxScore,
            count($reasons) > ($degraded ? 1 : 0),
            implode('; ', $reasons),
            $metrics
        );
    }
}
