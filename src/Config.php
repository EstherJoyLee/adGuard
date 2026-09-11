<?php
namespace AdGuard;

/**
 * Portable configuration with an optional local override.
 *
 * The package deliberately has no page, slot, size, or publisher-specific
 * defaults. A single install can therefore protect any HTML response that
 * happens to contain an AdSense bootstrap.
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
        $root = dirname(__DIR__);
        return array(
            'enabled' => true,
            // monitor: make and log the decision, but leave HTML unchanged
            // enforce: remove the bootstrap on DENY after an explicit review
            // off:     bypass the package entirely
            'mode' => 'monitor',
            'excluded_paths' => array(),
            'identity' => array(
                // X-Forwarded-For is ignored unless the immediate peer is
                // listed here. Each entry may be an IP or CIDR.
                'trusted_proxies' => array(),
            ),
            'policy' => array(
                'blocked_levels' => array('SUSPICIOUS', 'SEVERE'),
                /*
                 * A strong individual signal can deny ads without waiting for
                 * several weak signals to lift the combined verdict.
                 *
                 * The value is the signal's own score, and for the rate
                 * signals that score is "percent over the configured
                 * allowance" (see RateLimitSignal::score). So 100 means
                 * "twice the allowed rate", not "1 request over".
                 *
                 * These minimums were RAISED after a measured false positive:
                 * with rate_limit set to 1, fifteen ordinary visitors sharing
                 * one public IP (carrier NAT / internet cafe / office) had ads
                 * denied at the 41st request even though the risk engine
                 * itself rated that traffic NORMAL with a score of 3. A
                 * hard-deny must represent evidence strong enough to override
                 * the combined verdict -- merely crossing a shared-IP rate
                 * allowance by one request is not that.
                 */
                'hard_deny_signals' => array(
                    // Direct evidence: the client names an automation tool.
                    'user_agent' => 40,
                    // 2x the allowance for ONE persistent first-party identity.
                    // This is the highest-precision rate signal because the
                    // bucket belongs to a single browser profile, not to
                    // everyone behind a shared address.
                    'visitor_rate' => 100,
                    /*
                     * rate_limit is deliberately NOT a hard-deny signal
                     * either, for the same reason as session_churn: it is a
                     * per-IP bucket, so its value is driven as much by how
                     * many people share the address as by anyone's behaviour.
                     * Measured here: twenty-five ordinary visitors from one IP
                     * drove it to 88/SEVERE on its own. It still contributes
                     * to the combined score, where corroboration from a
                     * per-identity signal is required before ads are denied.
                     */
                    /*
                     * session_churn is deliberately NOT a hard-deny signal.
                     *
                     * It counts DISTINCT new visitors per IP, so it rises
                     * purely as a function of how many unrelated people share
                     * one public address -- exactly the normal situation on
                     * carrier NAT, in an internet cafe, an office, or a
                     * school. Measured here: twenty ordinary visitors from one
                     * IP pushed this signal to 52 and, while it was a
                     * hard-deny rule, lost their ads even though nothing about
                     * their individual behaviour was abnormal. It may still
                     * contribute to the combined score as corroboration, but
                     * it must never block on its own.
                     */
                ),
                // Risk-engine remains fail-safe for host content. AdGuard is
                // intentionally stricter: an uncertain decision hides ads but
                // never blocks or changes the surrounding page.
                'fail_closed' => true,
                'fail_closed_on_storage_degraded' => true,
            ),
            'logging' => array(
                'enabled' => true,
                'path' => $root . '/storage/logs',
                'hmac_key' => '',
                'hmac_key_path' => $root . '/storage/.hmac-key',
                'allow_sample_rate' => 1.0,
                'deny_sample_rate' => 1.0,
                /*
                 * First-party visitor cookie used ONLY to pseudonymize the
                 * visitor for the audit log. It must match whatever the risk
                 * provider issues -- for the bundled engine that is
                 * signals.visitor_rate.cookie_name / session_churn.cookie_name
                 * in config/engine.php. If the two disagree, visitor_hmac is
                 * recorded empty and every visitor-level number in the viewer
                 * (unique visitors, top visitors, visitor drill-down) silently
                 * goes blank, so keep them in step.
                 *
                 * Named here rather than read from the engine's config so the
                 * gate stays usable with a completely different risk provider.
                 */
                'visitor_cookie_name' => '__rek_id',
                // Full UA is retained for the bounded security record. It is
                // never used as a browser identity and is capped/sanitized.
                'store_user_agent' => true,
                // Runtime pruning is best-effort and never blocks a request.
                'retention_days' => 90,
                'health_max_daily_bytes' => 1048576,
                // Prevent an attack from turning security logging into a
                // disk-exhaustion attack. Logging stops; ad policy continues.
                'max_daily_bytes' => 20971520,
            ),
            'telemetry' => array(
                // Every limit is clamped again at the use site. These values
                // keep request evidence useful without allowing attacker-
                // controlled headers or events to grow without bound.
                'max_event_bytes' => 16384,
                'max_header_bytes' => 1024,
                'max_query_keys' => 32,
                'max_query_key_bytes' => 64,
                // Short retry budget; failure drops telemetry, never content.
                'lock_timeout_ms' => 2,
                'retention_scan_limit' => 256,
                'agent_version' => '2.0.0-phase1',
                'rule_version' => 'legacy-v1',
            ),
            /*
             * Stable labels used to compare local risk logs with aggregated
             * AdSense reports. They never claim that a report click belongs
             * to a particular visitor or IP.
             */
            'analytics' => array(
                // Set a stable project name when a site changes hostnames.
                // Empty means the validated request host is used.
                'site_id' => '',
                // Domains/host patterns that belong to site_id. "*.example.com"
                // is supported. Empty means site_id applies to every host in
                // this installation.
                'site_domains' => array(),
                // Stable page groups: group => exact/wildcard path patterns.
                'route_groups' => array(),
                // Optional traffic-source grouping by referrer host pattern.
                'referrer_groups' => array(),
                // AdSense report dates use the account timezone. UTC is safe
                // until the operator deliberately configures the account zone.
                // Human-facing viewer/report dates use Korean Standard Time.
                // Raw JSONL storage remains UTC for stable cross-server logs.
                'reporting_timezone' => 'Asia/Seoul',
                'report_path' => $root . '/storage/adsense',
                'analysis_path' => $root . '/storage/analysis',
                'baseline_days' => 14,
                'minimum_clicks' => 3,
                'ctr_multiplier' => 2.0,
                'ctr_absolute_increase' => 0.01,
                'risk_multiplier' => 2.0,
                'risk_absolute_increase' => 0.05,
            ),
            /*
             * Web log viewer. DENY BY DEFAULT: these records are security
             * telemetry about real visitors, so an empty allowlist must mean
             * "nobody", not "everybody". Add exact REMOTE_ADDR values to
             * enable it, and prefer viewing logs over SSH where possible.
             */
            'viewer' => array(
                'enabled' => true,
                'allowed_ips' => array(),
                'page_size' => 100,
                // Bound per-actor ranking memory on whole-history queries.
                // Totals and rows remain exact; only the long tail is omitted
                // from top-actor ranking once this cap is reached.
                'max_actor_buckets' => 10000,
            ),
        );
    }

    public static function load($overridePath = null)
    {
        $data = self::defaults();
        if ($overridePath === null || $overridePath === '') {
            $fromEnv = getenv('AD_GUARD_CONFIG');
            $overridePath = $fromEnv !== false && $fromEnv !== ''
                ? $fromEnv
                : dirname(__DIR__) . '/config/guard.php';
        }

        if (is_file($overridePath)) {
            $override = require $overridePath;
            if (is_array($override)) {
                $data = self::mergeRecursive($data, $override);
            }
        }

        $mode = isset($data['mode']) ? strtolower((string)$data['mode']) : 'monitor';
        if (!in_array($mode, array('enforce', 'monitor', 'off'), true)) {
            $mode = 'monitor';
        }
        $data['mode'] = $mode;

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
