<?php
namespace RiskEngine\KnownCrawlers;

/**
 * Classifies a request against operator-supplied lists of known crawler IP
 * ranges. It answers one narrow question:
 *
 *     "Does this request CLAIM to be a well-known crawler, and does the
 *      address it came from actually appear in that crawler's published
 *      ranges?"
 *
 * WHY THIS EXISTS
 * ---------------
 * Treating "the User-Agent says bot" as evidence of abuse punishes exactly
 * the clients that identify themselves honestly, and does nothing to a client
 * that lies. Verified search/ad crawlers are legitimate automation; abusive
 * automation is a different thing. This class separates the two so the risk
 * model can stop conflating them.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 *  - No network access, no DNS. Verification uses a locally cached range list
 *    only, so it can never add latency or a external dependency to a request.
 *  - No allowlisting by User-Agent alone. A UA is trivially forged, so a UA
 *    claim on its own only ever yields CLAIMED_UNVERIFIED.
 *  - No allowlisting by "owned by the same company". A cloud provider's
 *    general-purpose ranges are rentable by anyone; only ranges the vendor
 *    publishes *for its crawlers* count.
 *
 * FAILURE BEHAVIOUR
 * -----------------
 * Missing, stale or unreadable range data yields VERIFICATION_UNAVAILABLE,
 * which is neither an accusation nor a pass. Callers must not treat it as
 * either. Nothing about this class's failure re-enables a hard deny.
 */
class CrawlerVerifier
{
    /** Not claiming to be a known crawler. */
    const NOT_CLAIMED = 'not_claimed';
    /** Claims a known crawler UA AND the address is in that vendor's published crawler ranges. */
    const VERIFIED = 'verified';
    /** Claims a known crawler UA but the address is NOT in the published ranges. */
    const CLAIMED_UNVERIFIED = 'claimed_unverified';
    /** Claims a known crawler UA but we have no usable range data to check against. */
    const VERIFICATION_UNAVAILABLE = 'verification_unavailable';

    /**
     * UA substrings that constitute a claim, mapped to a vendor label.
     * Matching is case-insensitive and substring-based because vendors ship
     * many UA variants around the same token.
     *
     * Order matters: more specific tokens first, so "adsbot-google" is not
     * swallowed by a broader "google" style token.
     */
    private static $claimTokens = array(
        // Google publishes separate common, special and user-triggered lists.
        // Keep that claim-to-list binding so an AdsBot UA cannot be verified
        // by a Googlebot-only range (or vice versa).
        'adsbot-google-mobile' => array('google', 'special'),
        'adsbot-google'        => array('google', 'special'),
        'mediapartners-google' => array('google', 'special'),
        'storebot-google'      => array('google', 'special'),
        'google-inspectiontool'=> array('google', 'fetchers'),
        'google-site-verification' => array('google', 'fetchers'),
        'google-read-aloud'    => array('google', 'fetchers'),
        'googlebot'            => array('google', 'common'),
        'googleother'          => array('google', 'common'),
        'google-extended'      => array('google', 'common'),
        'bingbot'              => array('bing', ''),
        'adidxbot'             => array('bing', ''),
        'duckduckbot'          => array('duckduckgo', ''),
        'yandexbot'            => array('yandex', ''),
        'baiduspider'          => array('baidu', ''),
        'applebot'             => array('apple', ''),
    );

    /** @var array vendor => group => IpRangeSet (group '*' is legacy/combined) */
    private $ranges = array();
    /** @var array vendor => bool */
    private $usable = array();
    /** @var callable|null resolved on first actual need */
    private $loader = null;
    private $loaded = false;

    /**
     * @param array|callable $rangeSets vendor => IpRangeSet (legacy combined)
     *        or vendor => group => IpRangeSet, or a callable returning it.
     *
     * PASS A CALLABLE WHEN THE DATA IS EXPENSIVE.
     * The published range lists run to a few thousand prefixes; building them
     * costs single-digit milliseconds and roughly half a megabyte. Almost
     * every real request is an ordinary browser whose User-Agent claims
     * nothing, and those requests never need the data at all. Deferring the
     * build until a UA actually claims a known crawler keeps the common path
     * at roughly a microsecond and zero allocation. Measured, not assumed.
     *
     * A vendor absent from the resolved array (or present with an empty set)
     * can only ever produce VERIFICATION_UNAVAILABLE for that vendor.
     */
    public function __construct($rangeSets = array())
    {
        if (is_callable($rangeSets)) {
            $this->loader = $rangeSets;
            return;
        }
        $this->adopt($rangeSets);
        $this->loaded = true;
    }

    private function adopt($rangeSets)
    {
        foreach ((array)$rangeSets as $vendor => $set) {
            $vendor = (string)$vendor;
            if ($set instanceof IpRangeSet) {
                // Backward-compatible constructor shape used by integrations
                // and older tests. A combined set cannot claim group safety,
                // so it is checked for every claim in that vendor.
                $this->ranges[$vendor] = array('*' => $set);
                $this->usable[$vendor] = $set->count() > 0;
                continue;
            }
            if (!is_array($set)) {
                continue;
            }
            foreach ($set as $group => $groupSet) {
                if (!($groupSet instanceof IpRangeSet)) {
                    continue;
                }
                $this->ranges[$vendor][(string)$group] = $groupSet;
            }
            $this->usable[$vendor] = !empty($this->ranges[$vendor]);
        }
    }

    /** Resolve the deferred loader exactly once, and never fatally. */
    private function ensureLoaded()
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        if ($this->loader === null) {
            return;
        }
        try {
            $resolved = call_user_func($this->loader);
            if (is_array($resolved)) {
                $this->adopt($resolved);
            }
        } catch (\Exception $e) {
            // Leave the sets empty: the caller sees VERIFICATION_UNAVAILABLE,
            // which is the correct neutral answer when data cannot be loaded.
        }
        $this->loader = null;
    }

    /**
     * @return array{status:string,vendor:string,group:string}
     *   status : one of the class constants
     *   vendor : which vendor the UA claimed to be ('' when NOT_CLAIMED)
     *   group  : which published list matched ('' unless VERIFIED)
     */
    public function classify($ip, $userAgent)
    {
        /*
         * The UA claim is checked FIRST, before any range data is touched.
         * This is what keeps an ordinary browser request from ever paying for
         * the range list.
         */
        $claim = $this->claimedInfo($userAgent);
        $vendor = $claim['vendor'];
        if ($vendor === '') {
            return $this->result(self::NOT_CLAIMED, '', '');
        }

        $this->ensureLoaded();

        if (empty($this->usable[$vendor])) {
            return $this->result(self::VERIFICATION_UNAVAILABLE, $vendor, '');
        }

        $claimGroup = $claim['group'];
        $sets = isset($this->ranges[$vendor]) ? $this->ranges[$vendor] : array();
        $set = null;
        if ($claimGroup !== '' && isset($sets[$claimGroup])) {
            $set = $sets[$claimGroup];
        } elseif (isset($sets['*'])) {
            $set = $sets['*'];
        }
        if (!($set instanceof IpRangeSet)) {
            // Range data exists for the vendor, but not for this claim class.
            return $this->result(self::VERIFICATION_UNAVAILABLE, $vendor, '');
        }

        $group = $set->groupFor($ip);
        if ($group !== '') {
            return $this->result(self::VERIFIED, $vendor, $group !== '' ? $group : $claimGroup);
        }
        return $this->result(self::CLAIMED_UNVERIFIED, $vendor, '');
    }

    /** @return string vendor label, or '' when the UA claims no known crawler */
    public function claimedVendor($userAgent)
    {
        $claim = $this->claimedInfo($userAgent);
        return $claim['vendor'];
    }

    /** @return array{vendor:string,group:string} */
    public function claimedInfo($userAgent)
    {
        $ua = strtolower(trim((string)$userAgent));
        if ($ua === '') {
            return array('vendor' => '', 'group' => '');
        }
        foreach (self::$claimTokens as $token => $claim) {
            if (strpos($ua, $token) !== false) {
                return array('vendor' => $claim[0], 'group' => $claim[1]);
            }
        }
        return array('vendor' => '', 'group' => '');
    }

    /**
     * Generic self-identification ("bot", "spider", "crawler") that does not
     * name a vendor we can verify. This is a DESCRIPTION, not an accusation:
     * it says the client identified itself as automation, which honest
     * automation does and dishonest automation does not.
     */
    public static function looksSelfDeclaredCrawler($userAgent)
    {
        $ua = strtolower(trim((string)$userAgent));
        if ($ua === '') {
            return false;
        }
        foreach (array('bot', 'spider', 'crawler') as $marker) {
            if (strpos($ua, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    private function result($status, $vendor, $group)
    {
        return array('status' => $status, 'vendor' => $vendor, 'group' => $group);
    }
}
