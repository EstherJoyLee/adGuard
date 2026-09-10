<?php
namespace AdGuard;

/**
 * Stable, privacy-minimized labels shared by request logging and report joins.
 *
 * The labels deliberately describe a site/route/referrer rule, not a person.
 * Exact IP and visitor identifiers remain HMAC-pseudonymized elsewhere.
 */
class AnalyticsContext
{
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function siteId($server = null)
    {
        $server = is_array($server) ? $server : $_SERVER;
        $host = $this->currentHost($server);
        $configured = $this->cleanLabel($this->config->get('analytics.site_id', ''), 64);
        $domains = (array)$this->config->get('analytics.site_domains', array());

        if ($configured !== '' && (!$domains || $this->hostMatchesAny($host, $domains))) {
            return $configured;
        }
        return $host !== '' ? $host : ($configured !== '' ? $configured : 'unknown-site');
    }

    public function siteIdForHost($host)
    {
        $host = $this->normalizeHost($host);
        $configured = $this->cleanLabel($this->config->get('analytics.site_id', ''), 64);
        $domains = (array)$this->config->get('analytics.site_domains', array());
        if ($configured !== '' && (!$domains || $this->hostMatchesAny($host, $domains))) {
            return $configured;
        }
        return $host !== '' ? $host : ($configured !== '' ? $configured : 'unknown-site');
    }

    public function currentHost($server = null)
    {
        $server = is_array($server) ? $server : $_SERVER;
        $value = isset($server['HTTP_HOST']) ? $server['HTTP_HOST'] : '';
        if ($value === '' && isset($server['SERVER_NAME'])) {
            $value = $server['SERVER_NAME'];
        }
        return $this->normalizeHost($value);
    }

    public function routeGroup($path, $explicit = '')
    {
        $explicit = $this->cleanLabel($explicit, 80);
        if ($explicit !== '') {
            return $explicit;
        }

        $path = $this->normalizePath($path);
        $groups = (array)$this->config->get('analytics.route_groups', array());
        foreach ($groups as $group => $patterns) {
            foreach ((array)$patterns as $pattern) {
                if ($this->wildcardMatch($path, (string)$pattern, false)) {
                    return $this->cleanLabel($group, 80);
                }
            }
        }
        return $path !== '' ? $path : '/';
    }

    public function referrerGroup($host)
    {
        $host = $this->normalizeHost($host);
        if ($host === '') {
            return 'direct-or-unknown';
        }
        $groups = (array)$this->config->get('analytics.referrer_groups', array());
        foreach ($groups as $group => $patterns) {
            foreach ((array)$patterns as $pattern) {
                if ($this->wildcardMatch($host, (string)$pattern, true)) {
                    return $this->cleanLabel($group, 80);
                }
            }
        }
        return $host;
    }

    public function normalizeHost($host)
    {
        $host = strtolower(trim((string)$host));
        if ($host === '') {
            return '';
        }
        if ($host[0] === '[') {
            $end = strpos($host, ']');
            $host = $end === false ? '' : substr($host, 1, $end - 1);
        } else {
            $host = preg_replace('/:\d+$/D', '', $host);
        }
        if ($host === '' || strlen($host) > 253) {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        return preg_match('/^[a-z0-9.-]+$/D', $host) ? trim($host, '.') : '';
    }

    public function normalizePath($path)
    {
        $path = (string)$path;
        $parsed = parse_url($path, PHP_URL_PATH);
        if (is_string($parsed)) {
            $path = $parsed;
        }
        $path = preg_replace('#/+#', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        return substr($path, 0, 512);
    }

    /** IPv4 /24 or IPv6 /64 network, used only as input to an HMAC. */
    public static function networkPrefix($ip)
    {
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && function_exists('inet_pton')) {
            $packed = @inet_pton($ip);
            if (is_string($packed) && strlen($packed) === 16) {
                $network = substr($packed, 0, 8) . str_repeat("\0", 8);
                $text = @inet_ntop($network);
                return is_string($text) ? strtolower($text) . '/64' : '';
            }
        }
        return '';
    }

    private function hostMatchesAny($host, $patterns)
    {
        if ($host === '') {
            return false;
        }
        foreach ((array)$patterns as $pattern) {
            if ($this->wildcardMatch($host, (string)$pattern, true)) {
                return true;
            }
        }
        return false;
    }

    private function wildcardMatch($value, $pattern, $hostMode)
    {
        $value = $hostMode ? $this->normalizeHost($value) : $this->normalizePath($value);
        $pattern = trim((string)$pattern);
        if ($hostMode) {
            $pattern = strtolower($pattern);
        } elseif ($pattern === '' || $pattern[0] !== '/') {
            $pattern = '/' . ltrim($pattern, '/');
        }
        if ($value === '' || $pattern === '') {
            return false;
        }
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\\*', '.*', $regex);
        return preg_match('#^' . $regex . '$#D', $value) === 1;
    }

    private function cleanLabel($value, $maxLength)
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9._:\/-]+/', '-', $value);
        return trim(substr($value, 0, (int)$maxLength), '-');
    }
}
