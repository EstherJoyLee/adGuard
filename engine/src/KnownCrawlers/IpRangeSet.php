<?php
namespace RiskEngine\KnownCrawlers;

/**
 * A set of CIDR prefixes, each tagged with the group it came from.
 *
 * Matching works on packed binary addresses rather than integers so IPv4 and
 * IPv6 use one code path and nothing depends on the platform's integer width
 * (ip2long() overflows on 32-bit PHP for addresses above 127.255.255.255).
 *
 * This class performs NO network or DNS access. It answers only "is this
 * address inside one of the prefixes I was handed", which makes it safe to
 * call on every request.
 */
class IpRangeSet
{
    /** @var array list of array(binary prefix, prefix bits, group) */
    private $prefixes = array();

    /**
     * @param array $entries array of array('cidr' => '8.8.8.0/24', 'group' => 'common')
     */
    public function __construct($entries = array())
    {
        foreach ((array)$entries as $entry) {
            if (!is_array($entry) || !isset($entry['cidr'])) {
                continue;
            }
            $this->add(
                (string)$entry['cidr'],
                isset($entry['group']) ? (string)$entry['group'] : ''
            );
        }
    }

    /** @return bool whether the CIDR was understood and stored */
    public function add($cidr, $group)
    {
        $cidr = trim((string)$cidr);
        $slash = strrpos($cidr, '/');
        if ($slash === false) {
            return false;
        }
        $address = substr($cidr, 0, $slash);
        $bits = substr($cidr, $slash + 1);
        if ($bits === '' || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int)$bits;

        $packed = self::pack($address);
        if ($packed === null) {
            return false;
        }
        $maxBits = strlen($packed) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $this->prefixes[] = array($packed, $bits, (string)$group);
        return true;
    }

    public function count()
    {
        return count($this->prefixes);
    }

    /**
     * @return string the group of the first matching prefix, or '' if none.
     *                '' also means "no opinion" -- never treat it as proof of
     *                anything, only as absence of a match.
     */
    public function groupFor($ip)
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return '';
        }
        $length = strlen($packed);
        foreach ($this->prefixes as $prefix) {
            if (strlen($prefix[0]) !== $length) {
                continue; // different address family
            }
            if (self::matches($packed, $prefix[0], $prefix[1])) {
                return $prefix[2];
            }
        }
        return '';
    }

    public function contains($ip)
    {
        return $this->groupFor($ip) !== '';
    }

    /** Compare the first $bits bits of two equal-length packed addresses. */
    private static function matches($packed, $prefix, $bits)
    {
        $wholeBytes = $bits >> 3;
        if ($wholeBytes > 0 && strncmp($packed, $prefix, $wholeBytes) !== 0) {
            return false;
        }
        $remaining = $bits & 7;
        if ($remaining === 0) {
            return true;
        }
        // Compare only the high $remaining bits of the next byte.
        $mask = (0xFF << (8 - $remaining)) & 0xFF;
        $a = ord(substr($packed, $wholeBytes, 1));
        $b = ord(substr($prefix, $wholeBytes, 1));
        return ($a & $mask) === ($b & $mask);
    }

    /** @return string|null packed address, or null when not a valid IP */
    private static function pack($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '' || !function_exists('inet_pton')) {
            return null;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = @inet_pton($ip);
        return is_string($packed) && $packed !== '' ? $packed : null;
    }
}
