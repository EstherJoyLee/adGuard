<?php
namespace RiskEngine;

require_once __DIR__ . '/Identity/IpResolver.php';

/**
 * The only place in this module that reads PHP superglobals. Every signal
 * receives one of these instead of touching $_SERVER/$_COOKIE directly --
 * keeps signals testable with synthetic input and keeps the "what do we
 * trust from the request" decision in one auditable spot.
 *
 * IP resolution trusts REMOTE_ADDR by default. Proxy headers
 * (X-Forwarded-For etc.) are accepted only when the immediate peer is in the
 * explicit identity.trusted_proxies list; this keeps direct hosting safe
 * while allowing a known reverse proxy to preserve the real client address.
 */
class RequestContext
{
    private $server;
    private $cookies;
    private $now;
    private $identity;

    public function __construct($server = null, $cookies = null, $now = null, $identityConfig = array())
    {
        $this->server = $server === null ? $_SERVER : $server;
        $this->cookies = $cookies === null ? $_COOKIE : $cookies;
        $this->now = $now === null ? time() : (int)$now;
        $this->identity = Identity\IpResolver::resolve($this->server, $identityConfig);
    }

    public function getIp()
    {
        return isset($this->identity['canonical_ip']) ? (string)$this->identity['canonical_ip'] : '';
    }

    public function getRawIp()
    {
        return isset($this->identity['raw_ip']) ? (string)$this->identity['raw_ip'] : '';
    }

    public function getIpIdentity()
    {
        return $this->identity;
    }

    public function getUserAgent()
    {
        return isset($this->server['HTTP_USER_AGENT']) ? (string)$this->server['HTTP_USER_AGENT'] : '';
    }

    /** @param string $name e.g. "Accept-Language" -> reads HTTP_ACCEPT_LANGUAGE */
    public function getHeader($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', (string)$name));
        return isset($this->server[$key]) ? (string)$this->server[$key] : '';
    }

    public function getCookie($name)
    {
        return isset($this->cookies[$name]) ? (string)$this->cookies[$name] : null;
    }

    public function isHttps()
    {
        if (isset($this->server['HTTPS']) && $this->server['HTTPS'] !== '' && strtolower((string)$this->server['HTTPS']) !== 'off') {
            return true;
        }
        return isset($this->server['SERVER_PORT']) && (string)$this->server['SERVER_PORT'] === '443';
    }

    public function now()
    {
        return $this->now;
    }
}
