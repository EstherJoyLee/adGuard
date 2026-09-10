<?php
namespace RiskEngine;

/**
 * In-code defaults, optionally overridden by config/risk-engine.config.php
 * if the integrator created one (copied from the .dist template). The
 * engine is fully functional with zero config file present.
 */
class Config
{
    private $data;

    private function __construct($data)
    {
        $this->data = $data;
    }

    public static function defaults()
    {
        return array(
            'identity' => array(
                // Forwarded headers are ignored unless the immediate peer is
                // explicitly trusted. Keep this list empty on direct hosting.
                'trusted_proxies' => array(),
            ),
            /*
             * Optional known-crawler classification. Entirely additive: with no
             * cache file present the engine behaves exactly as it did without
             * this feature and every score is unchanged. Populate the cache
             * with tools/refresh-crawler-ranges.php from cron.
             */
            'known_crawlers' => array(
                'cache_path' => __DIR__ . '/../../storage/crawler-ranges.php',
                // Past this age the cache is ignored and classification reports
                // "unavailable". Ignoring stale data is the neutral choice: it
                // withholds a positive claim rather than asserting a stale one.
                'max_age_seconds' => 1209600, // 14 days
            ),
            'storage' => array(
                'path' => __DIR__ . '/../../storage/engine',
                // No cron job: gc() runs inline on a small, random fraction
                // of mutating requests, the same pattern PHP's own session
                // GC uses (session.gc_probability / session.gc_divisor).
                'gc_probability' => 0.01,
                'gc_max_age_seconds' => 172800, // 2 days -- generous relative
                                                 // to every window this module
                                                 // currently uses (minutes).
            ),
            'signals' => array(
                'user_agent' => array(
                    'enabled' => true,
                    'weight' => 1.0,
                ),
                /*
                 * Same shared-IP caution as session_churn below: these are
                 * per-IP totals, not per-person, so a single carrier NAT or
                 * internet cafe legitimately produces a lot of traffic from
                 * one address. Tune against your own real traffic before
                 * tightening.
                 */
                'rate_limit' => array(
                    'enabled' => true,
                    /*
                     * Must stay below thresholds.suspicious / 100 so this
                     * per-IP signal can never reach a blocking level on its
                     * own -- a shared address is not evidence about any one
                     * visitor. At 0.45 the weighted maximum is 45, i.e. at
                     * most ELEVATED, so a block always needs corroboration
                     * from a per-identity signal. (0.6 previously allowed a
                     * weighted 60, which silently crossed the 50 threshold
                     * and contradicted this very comment.)
                     */
                    'weight' => 0.45,
                    // window_seconds => allowed_requests. Raised for
                    // shared-IP headroom; these remain UNVALIDATED defaults
                    // until real traffic is observed in monitor mode.
                    'windows' => array(
                        10 => 120,
                        300 => 1200,
                    ),
                ),
                'visitor_rate' => array(
                    'enabled' => true,
                    'weight' => 1.0,
                    // Deliberately tighter than the shared-IP limiter: this
                    // bucket belongs to one persistent first-party identity.
                    'windows' => array(
                        10 => 8,
                        60 => 30,
                        600 => 120,
                    ),
                    'cookie_name' => '__rek_id',
                ),
                /*
                 * Threshold/weight are deliberately conservative. This signal
                 * counts DISTINCT new visitors per IP, and huge numbers of
                 * unrelated real people legitimately share one public IP
                 * (carrier-grade NAT on mobile networks, offices, schools,
                 * internet cafes). A low threshold here does not catch bots --
                 * it blocks whole buildings. The weight is below 1.0 so churn
                 * alone cannot reach the SUSPICIOUS band; it takes a genuinely
                 * extreme count, or corroboration from another signal, to
                 * block. Raise the weight only after watching real traffic.
                 */
                'session_churn' => array(
                    'enabled' => true,
                    // Same ceiling rule as rate_limit above: 0.45 keeps the
                    // weighted maximum at 45 so this per-IP signal cannot by
                    // itself push a verdict into the blocking band.
                    'weight' => 0.45,
                    'window_seconds' => 600,
                    // Raised from 15 after measurement: twenty ordinary
                    // visitors sharing one public IP over ten minutes is an
                    // everyday situation on carrier NAT and in internet
                    // cafes, and 15 turned that into a blocking signal.
                    'threshold' => 40,
                    'cookie_name' => '__rek_id',
                    'cookie_ttl_seconds' => 31536000,
                ),
            ),
            'thresholds' => array(
                'elevated' => 25,
                'suspicious' => 50,
                'severe' => 75,
            ),
        );
    }

    public static function load()
    {
        $data = self::defaults();
        $fromEnv = getenv('RISK_ENGINE_CONFIG');
        $overridePath = $fromEnv !== false && $fromEnv !== ''
            ? $fromEnv
            : __DIR__ . '/../../config/engine.php';
        if (is_file($overridePath)) {
            $override = require $overridePath;
            if (is_array($override)) {
                $data = self::mergeRecursive($data, $override);
            }
        }
        return new self($data);
    }

    private static function mergeRecursive($base, $override)
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /** Dotted-path getter, e.g. get('signals.user_agent.weight', 1.0). */
    public function get($path, $default = null)
    {
        $parts = explode('.', (string)$path);
        $node = $this->data;
        foreach ($parts as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}
