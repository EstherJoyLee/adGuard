<?php
namespace AdGuard\Telemetry;

/** Bounded response, risk, and advertising evidence captured at completion. */
class ResponseTelemetry
{
    private $data;

    public function __construct($decision, $meta, $status, $durationMs, $bytes, $guardDurationMs)
    {
        $decision = is_array($decision) ? $decision : array();
        $meta = is_array($meta) ? $meta : array();
        $signals = $this->signals(isset($decision['signals']) ? $decision['signals'] : array());
        $userAgentSignal = isset($decision['signals']['user_agent']) && is_array($decision['signals']['user_agent'])
            ? $decision['signals']['user_agent'] : array();
        $crawlerMetrics = isset($userAgentSignal['metrics']) && is_array($userAgentSignal['metrics'])
            ? $userAgentSignal['metrics'] : array();

        $this->data = array(
            'risk' => array(
                'score' => array_key_exists('score', $decision) ? max(0, min(100, (int)$decision['score'])) : null,
                'level' => $this->nullableClean(isset($decision['engine_level']) ? $decision['engine_level'] : null, 24),
                'action' => $this->nullableClean(isset($decision['action']) ? $decision['action'] : null, 24),
                'policy_reason' => $this->nullableClean(isset($decision['policy_reason']) ? $decision['policy_reason'] : null, 128),
                'signals' => $signals,
                'reasons' => $this->reasons(isset($decision['reasons']) ? $decision['reasons'] : array()),
                'ads_allowed' => array_key_exists('ads_allowed', $decision) ? (bool)$decision['ads_allowed'] : null,
                'degraded' => array_key_exists('degraded', $decision) ? (bool)$decision['degraded'] : null,
                'sample_rate' => array_key_exists('sample_rate', $meta) ? max(0.0, min(1.0, (float)$meta['sample_rate'])) : null,
            ),
            'bot' => array(
                'claimed_identity' => null,
                'provider' => null,
                'verification_status' => null,
                'verification_level' => null,
                'fcrdns' => null,
                'official_ip_range' => null,
                'rfc9421' => null,
                'legacy' => array(
                    'crawler_status' => $this->nullableClean(isset($crawlerMetrics['crawler_status']) ? $crawlerMetrics['crawler_status'] : null, 40),
                    'crawler_vendor' => $this->nullableClean(isset($crawlerMetrics['crawler_vendor']) ? $crawlerMetrics['crawler_vendor'] : null, 40),
                    'crawler_group' => $this->nullableClean(isset($crawlerMetrics['crawler_group']) ? $crawlerMetrics['crawler_group'] : null, 40),
                ),
            ),
            'response' => array(
                'status' => is_int($status) && $status >= 100 && $status <= 599 ? $status : null,
                'duration_ms' => $this->nullableDuration($durationMs),
                'bytes' => is_int($bytes) && $bytes >= 0 ? min(2147483647, $bytes) : null,
            ),
            'advertising' => array(
                'ad_opportunity' => !empty($meta['ad_opportunity']) || !empty($meta['adsense_detected']),
                'adsense_detected' => !empty($meta['adsense_detected']),
                'bootstrap_removed' => max(0, min(1000, isset($meta['bootstrap_removed']) ? (int)$meta['bootstrap_removed'] : 0)),
                'ads_served' => !empty($meta['ads_served']),
                'external_suppression' => $this->clean(isset($meta['external_suppression']) ? $meta['external_suppression'] : '', 64),
                'ad_delivery' => $this->adDelivery(isset($meta['ad_delivery']) ? $meta['ad_delivery'] : array()),
            ),
            'analytics' => array(
                'route_group' => $this->clean(isset($meta['route_group']) ? $meta['route_group'] : '', 80),
                'redirect_rule_id' => $this->clean(isset($meta['redirect_rule_id']) ? $meta['redirect_rule_id'] : '', 80),
                'referrer_group' => $this->clean(isset($meta['traffic_source_group']) ? $meta['traffic_source_group'] : '', 80),
            ),
            'agent_metrics' => array(
                'guard_duration_ms' => $this->nullableDuration($guardDurationMs),
                // LocalEventStore measures the append itself; it cannot be
                // truthfully embedded in the line whose write is being timed.
                'telemetry_write_duration_ms' => null,
                'dropped_event_count' => null,
                'storage_error_count' => null,
                'state_error_count' => null,
            ),
        );
    }

    public function toArray()
    {
        return $this->data;
    }

    private function signals($value)
    {
        $out = array();
        $count = 0;
        foreach ((array)$value as $name => $signal) {
            if ($count >= 16 || !is_array($signal)) {
                break;
            }
            $safeName = $this->clean($name, 64);
            if ($safeName === '') {
                continue;
            }
            $out[$safeName] = array(
                'score' => isset($signal['score']) ? max(0, min(100, (int)$signal['score'])) : null,
                'triggered' => !empty($signal['triggered']),
                'storage_degraded' => !empty($signal['metrics']['storage_degraded']),
                'metrics' => $this->metrics(isset($signal['metrics']) ? $signal['metrics'] : array()),
            );
            $count++;
        }
        return $out;
    }

    private function metrics($value)
    {
        $value = is_array($value) ? $value : array();
        $out = array();
        $scalarKeys = array(
            'storage_degraded', 'count', 'window_seconds', 'threshold', 'stale',
            'request_had_identity_cookie', 'cookie_issue_degraded',
            'has_accept', 'has_accept_language', 'has_accept_encoding',
            'self_declared_crawler', 'crawler_status', 'crawler_vendor', 'crawler_group',
        );
        foreach ($scalarKeys as $key) {
            if (!array_key_exists($key, $value) || is_array($value[$key]) || is_object($value[$key])) {
                continue;
            }
            $out[$key] = is_string($value[$key]) ? $this->clean($value[$key], 80) : $value[$key];
        }
        foreach ($value as $key => $window) {
            if (!preg_match('/^window_\d{1,6}s$/D', (string)$key) || !is_array($window)) {
                continue;
            }
            $out[$this->clean($key, 32)] = array(
                'count' => isset($window['count']) ? max(0, min(2147483647, (int)$window['count'])) : null,
                'allowed' => isset($window['allowed']) ? max(0, min(2147483647, (int)$window['allowed'])) : null,
                'stale' => isset($window['stale']) ? (bool)$window['stale'] : null,
            );
            if (count($out) >= 16) {
                break;
            }
        }
        return $out;
    }

    private function reasons($value)
    {
        $out = array();
        foreach (array_slice((array)$value, 0, 8) as $reason) {
            $out[] = $this->clean($reason, 256);
        }
        return $out;
    }

    private function adDelivery($value)
    {
        $value = is_array($value) ? $value : array();
        $bootstrap = isset($value['bootstrap']) && is_array($value['bootstrap']) ? $value['bootstrap'] : array();
        $out = array(
            'bootstrap' => array(
                'opportunities' => $this->count(isset($bootstrap['opportunities']) ? $bootstrap['opportunities'] : 0, 100),
                'provided' => $this->count(isset($bootstrap['provided']) ? $bootstrap['provided'] : 0, 100),
                'blocked' => $this->count(isset($bootstrap['blocked']) ? $bootstrap['blocked'] : 0, 100),
                'missing' => $this->count(isset($bootstrap['missing']) ? $bootstrap['missing'] : 0, 100),
            ),
            'manual_unit_count' => $this->count(isset($value['manual_unit_count']) ? $value['manual_unit_count'] : 0, 1000),
            'manual_units' => array(),
            'truncated' => !empty($value['truncated']),
        );
        foreach (array_slice((array)(isset($value['manual_units']) ? $value['manual_units'] : array()), 0, 24) as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $status = isset($unit['status']) ? (string)$unit['status'] : 'missing';
            if (!in_array($status, array('provided', 'blocked', 'missing'), true)) {
                $status = 'missing';
            }
            $out['manual_units'][] = array(
                'slot' => $this->clean(isset($unit['slot']) ? $unit['slot'] : 'unlabeled', 80),
                'format' => $this->clean(isset($unit['format']) ? $unit['format'] : 'default', 40),
                'ordinal' => max(1, min(100, isset($unit['ordinal']) ? (int)$unit['ordinal'] : 1)),
                'status' => $status,
            );
        }
        return $out;
    }

    private function nullableDuration($value)
    {
        return is_numeric($value) ? max(0.0, min(86400000.0, (float)$value)) : null;
    }

    private function nullableClean($value, $maximum)
    {
        return $value === null ? null : $this->clean($value, $maximum);
    }

    private function count($value, $maximum)
    {
        return max(0, min((int)$maximum, (int)$value));
    }

    private function clean($value, $maximum)
    {
        return substr(str_replace(array("\r", "\n", "\0"), ' ', (string)$value), 0, (int)$maximum);
    }
}
