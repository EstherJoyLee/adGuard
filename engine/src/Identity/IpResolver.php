<?php
namespace RiskEngine\Identity;

/**
 * Resolve the client address once at the request boundary.
 *
 * REMOTE_ADDR is authoritative unless the immediate peer is explicitly listed
 * in identity.trusted_proxies. Forwarded headers from an untrusted peer are
 * never accepted. The resolver keeps the selected transport value (for raw
 * audit storage) separate from a canonical value (for buckets, HMAC joins and
 * CIDR matching).
 */
class IpResolver
{
    public static function resolve($server = null, $config = array())
    {
        $server = is_array($server) ? $server : $_SERVER;
        $peerRaw = isset($server['REMOTE_ADDR']) ? trim((string)$server['REMOTE_ADDR']) : '';
        $peer = self::normalize($peerRaw);
        if ($peer === '') {
            return array(
                'raw_ip' => '',
                'canonical_ip' => '',
                'source' => 'unresolved',
                'status' => $peerRaw === '' ? 'missing_peer' : 'invalid_peer',
            );
        }

        $trusted = isset($config['trusted_proxies']) ? (array)$config['trusted_proxies'] : array();
        if (!self::isTrusted($peer, $trusted)) {
            return self::result($peerRaw, $peer, 'remote_addr', 'resolved');
        }

        $forwarded = isset($server['HTTP_X_FORWARDED_FOR'])
            ? (string)$server['HTTP_X_FORWARDED_FOR'] : '';
        if (trim($forwarded) === '') {
            return self::result($peerRaw, $peer, 'remote_addr', 'trusted_peer_no_forwarded');
        }

        $tokens = explode(',', $forwarded);
        $hops = array();
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                return self::result($peerRaw, $peer, 'remote_addr', 'forwarded_invalid_fallback_peer');
            }
            $normalized = self::normalize($token);
            if ($normalized === '') {
                return self::result($peerRaw, $peer, 'remote_addr', 'forwarded_invalid_fallback_peer');
            }
            $hops[] = array('raw' => $token, 'canonical' => $normalized);
        }

        /* Walk from the trusted peer toward the client. The first untrusted
         * valid hop is the client selected by the configured proxy chain. */
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = $hops[$i];
            if (!self::isTrusted($hop['canonical'], $trusted)) {
                return self::result($hop['raw'], $hop['canonical'], 'x_forwarded_for', 'resolved');
            }
        }

        return self::result($peerRaw, $peer, 'remote_addr', 'forwarded_all_trusted');
    }

    /** Normalize IPv4, IPv6 and IPv4-mapped IPv6 into stable text. */
    public static function normalize($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false || !function_exists('inet_pton')) {
            return '';
        }
        $packed = @inet_pton($ip);
        if (!is_string($packed)) {
            return '';
        }
        /* ::ffff:a.b.c.d must share identity with a.b.c.d. */
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12, 4);
        }
        $normalized = @inet_ntop($packed);
        return is_string($normalized) ? strtolower($normalized) : '';
    }

    private static function result($raw, $canonical, $source, $status)
    {
        return array(
            'raw_ip' => (string)$raw,
            'canonical_ip' => (string)$canonical,
            'source' => (string)$source,
            'status' => (string)$status,
        );
    }

    private static function isTrusted($ip, $rules)
    {
        $ip = self::normalize($ip);
        if ($ip === '') {
            return false;
        }
        foreach ((array)$rules as $rule) {
            $rule = trim((string)$rule);
            if ($rule === '') {
                continue;
            }
            $parts = explode('/', $rule, 2);
            $network = self::normalize($parts[0]);
            if ($network === '') {
                continue;
            }
            $ipPacked = @inet_pton($ip);
            $networkPacked = @inet_pton($network);
            if (!is_string($ipPacked) || !is_string($networkPacked) || strlen($ipPacked) !== strlen($networkPacked)) {
                continue;
            }
            $bits = strlen($ipPacked) * 8;
            $prefix = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : $bits;
            if ($prefix < 0 || $prefix > $bits) {
                continue;
            }
            $wholeBytes = (int)floor($prefix / 8);
            $remainder = $prefix % 8;
            if ($wholeBytes > 0 && substr($ipPacked, 0, $wholeBytes) !== substr($networkPacked, 0, $wholeBytes)) {
                continue;
            }
            if ($remainder > 0) {
                $mask = (0xff << (8 - $remainder)) & 0xff;
                if ((ord($ipPacked[$wholeBytes]) & $mask) !== (ord($networkPacked[$wholeBytes]) & $mask)) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }
}
