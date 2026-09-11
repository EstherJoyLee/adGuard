<?php
namespace AdGuard\Telemetry;

require_once dirname(__DIR__) . '/Config.php';
require_once dirname(__DIR__) . '/AnalyticsContext.php';
require_once dirname(dirname(__DIR__)) . '/engine/src/Identity/IpResolver.php';

use AdGuard\AnalyticsContext;
use AdGuard\Config;

/** Bounded, server-observed request snapshot captured at guard start. */
class RequestTelemetry
{
    private $startedAt;
    private $occurredAtUtc;
    private $projectId;
    private $request;
    private $network;
    private $headers;
    private $behavior;

    public function __construct(Config $config, $server = null, $cookies = null, $startedAt = null)
    {
        $server = is_array($server) ? $server : $_SERVER;
        $cookies = is_array($cookies) ? $cookies : $_COOKIE;
        $this->startedAt = $startedAt === null ? microtime(true) : (float)$startedAt;
        if ($this->startedAt < 0) {
            $this->startedAt = 0.0;
        }
        $this->occurredAtUtc = $this->utcTimestamp($this->startedAt);

        $uri = isset($server['REQUEST_URI']) ? (string)$server['REQUEST_URI'] : '';
        $path = @parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $query = @parse_url($uri, PHP_URL_QUERY);
        $query = is_string($query) ? $query : '';

        $analytics = new AnalyticsContext($config);
        $host = $analytics->currentHost($server);
        $this->projectId = $analytics->siteId($server);
        $referer = isset($server['HTTP_REFERER']) ? (string)$server['HTTP_REFERER'] : '';
        $refererHost = $referer !== '' ? @parse_url($referer, PHP_URL_HOST) : '';
        $refererHost = is_string($refererHost) ? $refererHost : '';

        $identity = \RiskEngine\Identity\IpResolver::resolve(
            $server,
            (array)$config->get('identity', array())
        );
        $peerIp = isset($server['REMOTE_ADDR']) ? (string)$server['REMOTE_ADDR'] : '';
        $rawIp = isset($identity['raw_ip']) ? (string)$identity['raw_ip'] : '';
        $clientIp = isset($identity['canonical_ip']) ? (string)$identity['canonical_ip'] : '';
        $key = $this->hmacKey($config);
        $visitorCookie = (string)$config->get('logging.visitor_cookie_name', '__rek_id');
        if ($visitorCookie === '') {
            $visitorCookie = '__rek_id';
        }
        $visitor = isset($cookies[$visitorCookie]) ? (string)$cookies[$visitorCookie] : '';

        $this->request = array(
            'method' => $this->clean(isset($server['REQUEST_METHOD']) ? $server['REQUEST_METHOD'] : '', 12),
            'host' => $this->clean($host, 253),
            'path' => $this->clean($path, 512),
            'protocol' => $this->clean(isset($server['SERVER_PROTOCOL']) ? $server['SERVER_PROTOCOL'] : '', 24),
            'query_keys' => $this->queryKeys(
                $query,
                (int)$config->get('telemetry.max_query_keys', 32),
                (int)$config->get('telemetry.max_query_key_bytes', 64)
            ),
            'route_group' => $this->clean($analytics->routeGroup($path, ''), 80),
            'redirect_rule_id' => '',
            'request_type' => $this->requestType($path),
            'is_document' => $this->requestType($path) === 'document',
            'referrer_host' => $this->clean($refererHost, 255),
            'referrer_group' => $this->clean($analytics->referrerGroup($refererHost), 80),
            'country' => $this->clean(isset($server['HTTP_CF_IPCOUNTRY']) ? $server['HTTP_CF_IPCOUNTRY'] : '', 8),
            'cf_ray' => $this->clean(isset($server['HTTP_CF_RAY']) ? $server['HTTP_CF_RAY'] : '', 64),
        );

        $networkPrefix = AnalyticsContext::networkPrefix($clientIp);
        $this->network = array(
            'peer_ip' => $this->clean($peerIp, 128),
            'client_ip' => $this->clean($clientIp, 128),
            'raw_ip' => $this->clean($rawIp, 128),
            'ip_source' => $this->clean(isset($identity['source']) ? $identity['source'] : 'unresolved', 32),
            // Phase 2 owns trusted-proxy unification and the full chain.
            'proxy_trusted' => null,
            'forwarded_chain' => null,
            'resolution_status' => $this->clean(isset($identity['status']) ? $identity['status'] : 'missing_peer', 48),
            'ip_hmac' => $this->hashIdentifier($clientIp !== '' ? $clientIp : $rawIp, $key),
            'network_hmac' => $this->hashIdentifier($networkPrefix, $key),
            'visitor_hmac' => $this->hashIdentifier($visitor, $key),
        );

        $headerMax = max(64, min(4096, (int)$config->get('telemetry.max_header_bytes', 1024)));
        $this->headers = array(
            'user_agent' => $config->get('logging.store_user_agent', true)
                ? $this->clean(isset($server['HTTP_USER_AGENT']) ? $server['HTTP_USER_AGENT'] : '', $headerMax)
                : '',
            'accept' => $this->clean(isset($server['HTTP_ACCEPT']) ? $server['HTTP_ACCEPT'] : '', $headerMax),
            'accept_language' => $this->clean(isset($server['HTTP_ACCEPT_LANGUAGE']) ? $server['HTTP_ACCEPT_LANGUAGE'] : '', $headerMax),
            'referer' => $this->safeUrlHeader($referer, $headerMax),
            'origin' => $this->safeOrigin(isset($server['HTTP_ORIGIN']) ? $server['HTTP_ORIGIN'] : '', $headerMax),
            'sec_fetch_site' => $this->clean(isset($server['HTTP_SEC_FETCH_SITE']) ? $server['HTTP_SEC_FETCH_SITE'] : '', $headerMax),
            'sec_fetch_mode' => $this->clean(isset($server['HTTP_SEC_FETCH_MODE']) ? $server['HTTP_SEC_FETCH_MODE'] : '', $headerMax),
            'sec_fetch_dest' => $this->clean(isset($server['HTTP_SEC_FETCH_DEST']) ? $server['HTTP_SEC_FETCH_DEST'] : '', $headerMax),
            'sec_fetch_user' => $this->clean(isset($server['HTTP_SEC_FETCH_USER']) ? $server['HTTP_SEC_FETCH_USER'] : '', $headerMax),
            'x_forwarded_for' => $this->clean(isset($server['HTTP_X_FORWARDED_FOR']) ? $server['HTTP_X_FORWARDED_FOR'] : '', $headerMax),
        );

        // Phase 3 fills these from bounded server-side counters.
        $this->behavior = array(
            'ip_rate_10s' => null,
            'ip_rate_60s' => null,
            'ip_rate_600s' => null,
            'visitor_rate_10s' => null,
            'visitor_rate_60s' => null,
            'visitor_rate_600s' => null,
            'session_age_sec' => null,
            'session_churn' => null,
        );
    }

    public function startedAt()
    {
        return $this->startedAt;
    }

    public function occurredAtUtc()
    {
        return $this->occurredAtUtc;
    }

    public function projectId()
    {
        return $this->projectId;
    }

    public function toArray()
    {
        return array(
            'request' => $this->request,
            'network' => $this->network,
            'headers' => $this->headers,
            'behavior' => $this->behavior,
        );
    }

    private function queryKeys($query, $maximum, $keyBytes)
    {
        $maximum = max(0, min(64, (int)$maximum));
        $keyBytes = max(8, min(128, (int)$keyBytes));
        if ($query === '' || $maximum === 0) {
            return array();
        }
        $keys = array();
        foreach (explode('&', substr((string)$query, 0, 8192)) as $pair) {
            if (count($keys) >= $maximum) {
                break;
            }
            $separator = strpos($pair, '=');
            $rawKey = $separator === false ? $pair : substr($pair, 0, $separator);
            $key = $this->clean(rawurldecode(str_replace('+', ' ', $rawKey)), $keyBytes);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    private function safeUrlHeader($value, $maximum)
    {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        $parts = @parse_url($value);
        if (!is_array($parts)) {
            return '';
        }
        $out = '';
        if (isset($parts['scheme'])) {
            $out .= strtolower($this->clean($parts['scheme'], 16)) . '://';
        }
        if (isset($parts['host'])) {
            $out .= $this->clean($parts['host'], 253);
        }
        if (isset($parts['port'])) {
            $out .= ':' . max(1, min(65535, (int)$parts['port']));
        }
        if (isset($parts['path'])) {
            $out .= $this->clean($parts['path'], 512);
        }
        return $this->clean($out, $maximum);
    }

    private function safeOrigin($value, $maximum)
    {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        $parts = @parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $out = strtolower($this->clean($parts['scheme'], 16)) . '://' . $this->clean($parts['host'], 253);
        if (isset($parts['port'])) {
            $out .= ':' . max(1, min(65535, (int)$parts['port']));
        }
        return $this->clean($out, $maximum);
    }

    private function hmacKey(Config $config)
    {
        $configured = (string)$config->get('logging.hmac_key', '');
        if ($configured !== '') {
            return $configured;
        }
        $path = (string)$config->get('logging.hmac_key_path', '');
        if ($path === '') {
            return '';
        }
        if (is_file($path)) {
            $existing = @file_get_contents($path);
            return is_string($existing) ? trim($existing) : '';
        }
        $directory = dirname($path);
        if ($directory === '' || $directory === '.' || (!is_dir($directory) && !@mkdir($directory, 0700, true))) {
            return '';
        }
        $key = $this->randomToken(32);
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            $written = @fwrite($handle, $key);
            @fclose($handle);
            if ($written === strlen($key)) {
                @chmod($path, 0600);
                return $key;
            }
        }
        $existing = @file_get_contents($path);
        return is_string($existing) ? trim($existing) : '';
    }

    private function hashIdentifier($value, $key)
    {
        return (string)$value !== '' && (string)$key !== ''
            ? hash_hmac('sha256', (string)$value, (string)$key)
            : '';
    }

    private function randomToken($bytes)
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $raw = openssl_random_pseudo_bytes((int)$bytes, $strong);
            if ($raw !== false && $strong === true) {
                return bin2hex($raw);
            }
        }
        return hash('sha256', uniqid('', true) . ':' . mt_rand() . ':' . microtime(true));
    }

    private function utcTimestamp($value)
    {
        $seconds = (int)floor((float)$value);
        $milliseconds = (int)floor((((float)$value) - $seconds) * 1000.0 + 0.000001);
        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', max(0, min(999, $milliseconds)));
    }

    private function requestType($path)
    {
        $path = strtolower((string)$path);
        if (preg_match('~/(?:api)(?:/|$)|\.json$~', $path)) {
            return 'api';
        }
        if (preg_match('~\.(?:css|js|mjs|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|map|mp4|webm|pdf|zip)$~', $path)) {
            return 'static';
        }
        return 'document';
    }

    private function clean($value, $maximum)
    {
        $value = str_replace(array("\r", "\n", "\0"), ' ', (string)$value);
        if (@preg_match('//u', $value) !== 1 && function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        return substr($value, 0, max(0, (int)$maximum));
    }
}
