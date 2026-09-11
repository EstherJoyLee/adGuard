<?php
namespace AdGuard\Telemetry;

/** One immutable request identity completed once with response evidence. */
class TelemetryEvent
{
    private $record;
    private $completed = false;

    public function __construct(RequestTelemetry $request, $projectId, $eventId = null, $requestId = null)
    {
        $parts = $request->toArray();
        $projectId = $this->clean($projectId !== '' ? $projectId : $request->projectId(), 64);
        $this->record = array(
            'schema_version' => 4,
            'event_type' => 'request',
            'event_id' => $this->token($eventId, 16),
            'project_id' => $projectId,
            'request_id' => $this->token($requestId, 12),
            'occurred_at_utc' => $request->occurredAtUtc(),
            'request' => $parts['request'],
            'network' => $parts['network'],
            'headers' => $parts['headers'],
            'behavior' => $parts['behavior'],
            'bot' => array(
                'claimed_identity' => null, 'provider' => null,
                'verification_status' => null, 'verification_level' => null,
                'fcrdns' => null, 'official_ip_range' => null, 'rfc9421' => null,
                'legacy' => array('crawler_status' => null, 'crawler_vendor' => null, 'crawler_group' => null),
            ),
            'risk' => array(
                'score' => null, 'level' => null, 'action' => null,
                'policy_reason' => null, 'signals' => array(), 'reasons' => array(),
                'ads_allowed' => null, 'degraded' => null, 'sample_rate' => null,
            ),
            'response' => array('status' => null, 'duration_ms' => null, 'bytes' => null),
            'advertising' => array(
                'ad_opportunity' => false, 'adsense_detected' => false,
                'bootstrap_removed' => 0, 'ads_served' => false,
                'external_suppression' => '',
                'ad_delivery' => array(
                    'bootstrap' => array('opportunities' => 0, 'provided' => 0, 'blocked' => 0, 'missing' => 0),
                    'manual_unit_count' => 0, 'manual_units' => array(), 'truncated' => false,
                ),
            ),
            'agent' => array(
                'version' => '', 'rule_version' => '',
                'guard_duration_ms' => null, 'telemetry_write_duration_ms' => null,
                'dropped_event_count' => null, 'storage_error_count' => null,
                'state_error_count' => null,
            ),
        );
    }

    public function complete(ResponseTelemetry $response, $agentVersion, $ruleVersion)
    {
        if ($this->completed) {
            return false;
        }
        $parts = $response->toArray();
        foreach (array('route_group', 'redirect_rule_id', 'referrer_group') as $key) {
            if ($parts['analytics'][$key] !== '') {
                $this->record['request'][$key] = $parts['analytics'][$key];
            }
        }
        $this->record['risk'] = $parts['risk'];
        $this->record['bot'] = $parts['bot'];
        $this->record['response'] = $parts['response'];
        $this->record['advertising'] = $parts['advertising'];
        $this->record['agent'] = array_merge($parts['agent_metrics'], array(
            'version' => $this->clean($agentVersion, 40),
            'rule_version' => $this->clean($ruleVersion, 40),
        ));
        // Keep the documented, stable key order for consumers and reviews.
        $this->record['agent'] = array(
            'version' => $this->record['agent']['version'],
            'rule_version' => $this->record['agent']['rule_version'],
            'guard_duration_ms' => $this->record['agent']['guard_duration_ms'],
            'telemetry_write_duration_ms' => $this->record['agent']['telemetry_write_duration_ms'],
            'dropped_event_count' => $this->record['agent']['dropped_event_count'],
            'storage_error_count' => $this->record['agent']['storage_error_count'],
            'state_error_count' => $this->record['agent']['state_error_count'],
        );
        $this->completed = true;
        return true;
    }

    public function toArray()
    {
        return $this->record;
    }

    private function token($value, $bytes)
    {
        if ($value !== null) {
            $clean = preg_replace('/[^A-Za-z0-9._:-]/', '', (string)$value);
            if ($clean !== '') {
                return substr($clean, 0, 128);
            }
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $raw = openssl_random_pseudo_bytes((int)$bytes, $strong);
            if ($raw !== false && $strong === true) {
                return bin2hex($raw);
            }
        }
        return hash('sha256', uniqid('', true) . ':' . mt_rand() . ':' . microtime(true));
    }

    private function clean($value, $maximum)
    {
        return substr(str_replace(array("\r", "\n", "\0"), ' ', (string)$value), 0, (int)$maximum);
    }
}
