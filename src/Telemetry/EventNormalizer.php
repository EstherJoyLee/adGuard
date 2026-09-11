<?php
namespace AdGuard\Telemetry;

/** Converts supported JSONL records into the established flat read model. */
class EventNormalizer
{
    /** Read one bounded physical JSONL record; false means EOF. */
    public static function readNext($handle, $maximumBytes, &$reason = null)
    {
        $reason = '';
        if (!is_resource($handle)) {
            $reason = 'malformed';
            return false;
        }
        $maximumBytes = max(1024, min(65536, (int)$maximumBytes));
        $line = fgets($handle, $maximumBytes + 2);
        if ($line === false) {
            $reason = 'eof';
            return false;
        }
        $hasNewline = substr($line, -1) === "\n";
        if (!$hasNewline && !feof($handle)) {
            do {
                $chunk = fgets($handle, $maximumBytes + 2);
            } while ($chunk !== false && substr($chunk, -1) !== "\n");
            $reason = 'malformed';
            return null;
        }
        $line = rtrim($line, "\r\n");
        if (strlen($line) > $maximumBytes) {
            $reason = 'malformed';
            return null;
        }
        if (trim($line) === '') {
            $reason = 'blank';
            return null;
        }
        $record = json_decode($line, true);
        if (!is_array($record)) {
            $reason = 'malformed';
            return null;
        }
        return $record;
    }

    public static function normalize($record, &$reason = null)
    {
        $reason = '';
        if (!is_array($record)) {
            $reason = 'malformed';
            return null;
        }
        if (self::isHealth($record)) {
            $reason = 'health';
            return null;
        }

        if (!array_key_exists('schema_version', $record)) {
            if (!self::validFlat($record)) {
                $reason = 'invalid';
                return null;
            }
            return self::fromFlat($record, null, 'versionless');
        }

        $version = is_numeric($record['schema_version']) ? (int)$record['schema_version'] : -1;
        if ($version === 3) {
            if (!self::validFlat($record)) {
                $reason = 'invalid';
                return null;
            }
            return self::fromFlat($record, 3, 'schema3');
        }
        if ($version !== 4) {
            $reason = 'unsupported';
            return null;
        }
        if (!self::validSchema4($record)) {
            $reason = 'invalid';
            return null;
        }
        return self::fromSchema4($record);
    }

    private static function isHealth($record)
    {
        $type = isset($record['event_type']) ? (string)$record['event_type'] : '';
        $legacy = isset($record['event']) ? (string)$record['event'] : '';
        return $type === 'telemetry_health' || $legacy === 'logger_health';
    }

    private static function validFlat($record)
    {
        return isset($record['timestamp'])
            && is_string($record['timestamp'])
            && trim($record['timestamp']) !== ''
            && (array_key_exists('path', $record) || array_key_exists('action', $record));
    }

    private static function validSchema4($record)
    {
        return isset($record['event_type'], $record['occurred_at_utc'])
            && $record['event_type'] === 'request'
            && is_string($record['occurred_at_utc'])
            && trim($record['occurred_at_utc']) !== ''
            && isset($record['request'], $record['network'], $record['risk'], $record['response'], $record['advertising'])
            && is_array($record['request'])
            && is_array($record['network'])
            && is_array($record['risk'])
            && is_array($record['response'])
            && is_array($record['advertising']);
    }

    private static function fromFlat($record, $sourceVersion, $sourceFormat)
    {
        return array(
            '_source_schema_version' => $sourceVersion,
            '_source_format' => $sourceFormat,
            'schema_version' => $sourceVersion,
            'event_type' => 'request',
            'event_id' => self::stringValue($record, 'event_id', 128),
            'request_id' => self::stringValue($record, 'request_id', 128),
            'timestamp' => self::stringValue($record, 'timestamp', 48),
            'site_id' => self::stringValue($record, 'site_id', 64),
            'host' => self::stringValue($record, 'host', 253),
            'path' => self::stringValue($record, 'path', 512),
            'route_group' => self::stringValue($record, 'route_group', 80),
            'redirect_rule_id' => self::stringValue($record, 'redirect_rule_id', 80),
            'method' => self::stringValue($record, 'method', 12),
            'protocol' => self::stringValue($record, 'protocol', 24),
            'query_keys' => self::stringList(isset($record['query_keys']) ? $record['query_keys'] : null, 64, 64),
            'referrer_host' => self::stringValue($record, 'referrer_host', 255),
            'referrer_group' => self::stringValue($record, 'referrer_group', 80),
            'country' => self::stringValue($record, 'country', 8),
            'cf_ray' => self::stringValue($record, 'cf_ray', 64),
            'peer_ip' => self::stringValue($record, 'peer_ip', 128),
            'raw_ip' => self::stringValue($record, 'raw_ip', 128),
            'ip_canonical' => self::stringValue($record, 'ip_canonical', 128),
            'ip_source' => self::stringValue($record, 'ip_source', 32),
            'proxy_trusted' => self::boolValue($record, 'proxy_trusted'),
            'forwarded_chain' => self::stringList(isset($record['forwarded_chain']) ? $record['forwarded_chain'] : null, 16, 128),
            'ip_resolution_status' => self::stringValue($record, 'ip_resolution_status', 48),
            'ip_hmac' => self::stringValue($record, 'ip_hmac', 128),
            'network_hmac' => self::stringValue($record, 'network_hmac', 128),
            'visitor_hmac' => self::stringValue($record, 'visitor_hmac', 128),
            'user_agent' => self::stringValue($record, 'user_agent', 4096),
            'ua_family' => self::stringValue($record, 'ua_family', 40),
            'crawler_status' => self::stringValue($record, 'crawler_status', 40),
            'crawler_vendor' => self::stringValue($record, 'crawler_vendor', 40),
            'crawler_group' => self::stringValue($record, 'crawler_group', 40),
            'bot_claimed_identity' => null,
            'bot_provider' => null,
            'bot_verification_status' => null,
            'bot_verification_level' => null,
            // A legacy "verified" crawler label is not evidence of Phase 4 FCrDNS.
            'fcrdns_status' => null,
            'official_ip_range' => null,
            'rfc9421_status' => null,
            'request_type' => self::stringValue($record, 'request_type', 24),
            'is_document' => self::boolValue($record, 'is_document'),
            'behavior' => isset($record['behavior']) && is_array($record['behavior']) ? $record['behavior'] : null,
            'engine_level' => self::stringValue($record, 'engine_level', 24),
            'score' => self::integerValue($record, 'score'),
            'action' => self::stringValue($record, 'action', 24),
            'policy_reason' => self::stringValue($record, 'policy_reason', 128),
            'ads_allowed' => self::boolValue($record, 'ads_allowed'),
            'degraded' => self::boolValue($record, 'degraded'),
            'sample_rate' => self::numberValue($record, 'sample_rate'),
            'reasons' => self::stringList(isset($record['reasons']) ? $record['reasons'] : array(), 8, 256),
            'signals' => self::signals(isset($record['signals']) ? $record['signals'] : array()),
            'response_status' => self::integerValue($record, 'response_status'),
            'response_duration_ms' => self::numberValue($record, 'response_duration_ms'),
            'response_bytes' => self::integerValue($record, 'response_bytes'),
            'ad_opportunity' => self::boolValue($record, 'ad_opportunity'),
            'adsense_detected' => self::boolValue($record, 'adsense_detected'),
            'bootstrap_removed' => self::integerValue($record, 'bootstrap_removed'),
            'ads_served' => self::boolValue($record, 'ads_served'),
            'external_suppression' => self::stringValue($record, 'external_suppression', 64),
            'ad_delivery' => self::adDelivery(isset($record['ad_delivery']) ? $record['ad_delivery'] : array()),
            'agent_version' => self::stringValue($record, 'agent_version', 40),
            'rule_version' => self::stringValue($record, 'rule_version', 40),
            'guard_duration_ms' => self::numberValue($record, 'guard_duration_ms'),
            'telemetry_write_duration_ms' => self::numberValue($record, 'telemetry_write_duration_ms'),
            'dropped_event_count' => self::integerValue($record, 'dropped_event_count'),
            'storage_error_count' => self::integerValue($record, 'storage_error_count'),
            'state_error_count' => self::integerValue($record, 'state_error_count'),
        );
    }

    private static function fromSchema4($record)
    {
        $request = $record['request'];
        $network = $record['network'];
        $headers = isset($record['headers']) && is_array($record['headers']) ? $record['headers'] : array();
        $risk = $record['risk'];
        $response = $record['response'];
        $advertising = $record['advertising'];
        $bot = isset($record['bot']) && is_array($record['bot']) ? $record['bot'] : array();
        $legacyBot = isset($bot['legacy']) && is_array($bot['legacy']) ? $bot['legacy'] : array();
        $agent = isset($record['agent']) && is_array($record['agent']) ? $record['agent'] : array();
        $userAgent = self::stringValue($headers, 'user_agent', 4096);

        return array(
            '_source_schema_version' => 4,
            '_source_format' => 'schema4',
            'schema_version' => 4,
            'event_type' => 'request',
            'event_id' => self::stringValue($record, 'event_id', 128),
            'request_id' => self::stringValue($record, 'request_id', 128),
            'timestamp' => self::stringValue($record, 'occurred_at_utc', 48),
            'site_id' => self::stringValue($record, 'project_id', 64),
            'host' => self::stringValue($request, 'host', 253),
            'path' => self::stringValue($request, 'path', 512),
            'route_group' => self::stringValue($request, 'route_group', 80),
            'redirect_rule_id' => self::stringValue($request, 'redirect_rule_id', 80),
            'method' => self::stringValue($request, 'method', 12),
            'protocol' => self::stringValue($request, 'protocol', 24),
            'query_keys' => self::stringList(isset($request['query_keys']) ? $request['query_keys'] : null, 64, 64),
            'referrer_host' => self::stringValue($request, 'referrer_host', 255),
            'referrer_group' => self::stringValue($request, 'referrer_group', 80),
            'country' => self::stringValue($request, 'country', 8),
            'cf_ray' => self::stringValue($request, 'cf_ray', 64),
            'peer_ip' => self::stringValue($network, 'peer_ip', 128),
            'raw_ip' => self::stringValue($network, 'raw_ip', 128),
            'ip_canonical' => self::stringValue($network, 'client_ip', 128),
            'ip_source' => self::stringValue($network, 'ip_source', 32),
            'proxy_trusted' => self::boolValue($network, 'proxy_trusted'),
            'forwarded_chain' => self::stringList(isset($network['forwarded_chain']) ? $network['forwarded_chain'] : null, 16, 128),
            'ip_resolution_status' => self::stringValue($network, 'resolution_status', 48),
            'ip_hmac' => self::stringValue($network, 'ip_hmac', 128),
            'network_hmac' => self::stringValue($network, 'network_hmac', 128),
            'visitor_hmac' => self::stringValue($network, 'visitor_hmac', 128),
            'user_agent' => $userAgent,
            'ua_family' => self::userAgentFamily($userAgent),
            'crawler_status' => self::stringValue($legacyBot, 'crawler_status', 40),
            'crawler_vendor' => self::stringValue($legacyBot, 'crawler_vendor', 40),
            'crawler_group' => self::stringValue($legacyBot, 'crawler_group', 40),
            'bot_claimed_identity' => self::stringValue($bot, 'claimed_identity', 80),
            'bot_provider' => self::stringValue($bot, 'provider', 80),
            'bot_verification_status' => self::stringValue($bot, 'verification_status', 40),
            'bot_verification_level' => self::stringValue($bot, 'verification_level', 40),
            'fcrdns_status' => self::verificationStatus(isset($bot['fcrdns']) ? $bot['fcrdns'] : null),
            'official_ip_range' => isset($bot['official_ip_range']) ? $bot['official_ip_range'] : null,
            'rfc9421_status' => self::verificationStatus(isset($bot['rfc9421']) ? $bot['rfc9421'] : null),
            'request_type' => self::stringValue($request, 'request_type', 24),
            'is_document' => self::boolValue($request, 'is_document'),
            'behavior' => isset($record['behavior']) && is_array($record['behavior']) ? $record['behavior'] : null,
            'engine_level' => self::stringValue($risk, 'level', 24),
            'score' => self::integerValue($risk, 'score'),
            'action' => self::stringValue($risk, 'action', 24),
            'policy_reason' => self::stringValue($risk, 'policy_reason', 128),
            'ads_allowed' => self::boolValue($risk, 'ads_allowed'),
            'degraded' => self::boolValue($risk, 'degraded'),
            'sample_rate' => self::numberValue($risk, 'sample_rate'),
            'reasons' => self::stringList(isset($risk['reasons']) ? $risk['reasons'] : array(), 8, 256),
            'signals' => self::signals(isset($risk['signals']) ? $risk['signals'] : array()),
            'response_status' => self::integerValue($response, 'status'),
            'response_duration_ms' => self::numberValue($response, 'duration_ms'),
            'response_bytes' => self::integerValue($response, 'bytes'),
            'ad_opportunity' => self::boolValue($advertising, 'ad_opportunity'),
            'adsense_detected' => self::boolValue($advertising, 'adsense_detected'),
            'bootstrap_removed' => self::integerValue($advertising, 'bootstrap_removed'),
            'ads_served' => self::boolValue($advertising, 'ads_served'),
            'external_suppression' => self::stringValue($advertising, 'external_suppression', 64),
            'ad_delivery' => self::adDelivery(isset($advertising['ad_delivery']) ? $advertising['ad_delivery'] : array()),
            'agent_version' => self::stringValue($agent, 'version', 40),
            'rule_version' => self::stringValue($agent, 'rule_version', 40),
            'guard_duration_ms' => self::numberValue($agent, 'guard_duration_ms'),
            'telemetry_write_duration_ms' => self::numberValue($agent, 'telemetry_write_duration_ms'),
            'dropped_event_count' => self::integerValue($agent, 'dropped_event_count'),
            'storage_error_count' => self::integerValue($agent, 'storage_error_count'),
            'state_error_count' => self::integerValue($agent, 'state_error_count'),
        );
    }

    private static function stringValue($array, $key, $maximum)
    {
        if (!is_array($array) || !array_key_exists($key, $array) || $array[$key] === null
            || is_array($array[$key]) || is_object($array[$key])) {
            return null;
        }
        return substr(str_replace(array("\r", "\n", "\0"), ' ', (string)$array[$key]), 0, (int)$maximum);
    }

    private static function boolValue($array, $key)
    {
        return is_array($array) && array_key_exists($key, $array) && $array[$key] !== null
            ? (bool)$array[$key]
            : null;
    }

    private static function numberValue($array, $key)
    {
        return is_array($array) && array_key_exists($key, $array) && is_numeric($array[$key])
            ? (float)$array[$key]
            : null;
    }

    private static function integerValue($array, $key)
    {
        return is_array($array) && array_key_exists($key, $array) && is_numeric($array[$key])
            ? (int)$array[$key]
            : null;
    }

    private static function stringList($value, $maximumItems, $maximumBytes)
    {
        if ($value === null) {
            return null;
        }
        $out = array();
        foreach (array_slice((array)$value, 0, (int)$maximumItems) as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }
            $out[] = substr(str_replace(array("\r", "\n", "\0"), ' ', (string)$item), 0, (int)$maximumBytes);
        }
        return $out;
    }

    private static function signals($value)
    {
        $out = array();
        $count = 0;
        foreach ((array)$value as $name => $signal) {
            if ($count >= 16) {
                break;
            }
            if (!is_array($signal)) {
                continue;
            }
            $safeName = substr(str_replace(array("\r", "\n", "\0"), ' ', (string)$name), 0, 64);
            if ($safeName === '') {
                continue;
            }
            $out[$safeName] = $signal;
            $count++;
        }
        return $out;
    }

    private static function adDelivery($value)
    {
        if (!is_array($value)) {
            return array();
        }
        $out = $value;
        if (isset($out['manual_units'])) {
            $out['manual_units'] = array_slice((array)$out['manual_units'], 0, 24);
        }
        return $out;
    }

    private static function verificationStatus($value)
    {
        if (is_array($value)) {
            return self::stringValue($value, 'status', 40);
        }
        if ($value === null || is_object($value)) {
            return null;
        }
        return substr((string)$value, 0, 40);
    }

    private static function userAgentFamily($userAgent)
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }
        $ua = strtolower((string)$userAgent);
        $families = array(
            'googlebot' => 'googlebot', 'bingbot' => 'bingbot', 'headlesschrome' => 'headless',
            'curl' => 'curl', 'wget' => 'wget', 'python' => 'python', 'okhttp' => 'okhttp',
            'edg/' => 'edge', 'chrome/' => 'chrome', 'firefox/' => 'firefox', 'safari/' => 'safari',
        );
        foreach ($families as $needle => $name) {
            if (strpos($ua, $needle) !== false) {
                return $name;
            }
        }
        return 'other';
    }
}
