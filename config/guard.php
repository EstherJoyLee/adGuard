<?php
/**
 * AdGuard site configuration -- TEMPLATE DEFAULTS.
 *
 * Every value here is optional: the package runs with zero configuration and
 * falls back to src/Config.php defaults. This file exists so a fresh install
 * starts in the safest possible state and so an operator has one obvious
 * place to edit.
 *
 * Nothing in this file is specific to any particular site. Fill in the
 * project-specific values for YOUR site; do not copy another project's.
 */
return array(
    /*
     * TEMPLATE DEFAULT: monitor.
     *
     * The package's own built-in default is 'enforce'. A fresh install
     * deliberately overrides that to 'monitor' so a new site NEVER starts by
     * silently removing ad loaders on thresholds that have not yet been
     * measured against its own real traffic.
     *
     *   monitor : decide and log, but leave every response unchanged
     *   enforce : remove the AdSense loader when policy says DENY
     *   off     : bypass the package entirely
     *
     * Switch to 'enforce' only after reviewing MONITOR_DENY rows in the
     * viewer and confirming they are not ordinary visitors.
     */
    'mode' => 'monitor',

    /*
     * Client IP resolution. Forwarded headers are ignored unless the
     * immediate REMOTE_ADDR is explicitly trusted here. Keep empty for direct
     * hosting; configure the same trusted proxy list in engine.php when the
     * risk engine also needs proxy-resolved buckets.
     */
    'identity' => array(
        'trusted_proxies' => array(),
    ),

    'logging' => array(
        // Schema 4 stores the bounded User-Agent and selected raw client IP.
        // Legacy schema 3 files remain readable. Keep logs outside the
        // document root where possible.
        'store_user_agent' => true,
        'retention_days' => 90,
        'health_max_daily_bytes' => 1048576,
    ),

    'telemetry' => array(
        'max_event_bytes' => 16384,
        'max_header_bytes' => 1024,
        'max_query_keys' => 32,
        'max_query_key_bytes' => 64,
        'lock_timeout_ms' => 2,
    ),

    /*
     * Paths that must never be gated. Add the viewer's own URL path as it is
     * actually mounted on this site -- the viewer serves no ads and enforces
     * its own allowlist. Adjust if adguard/ is mounted somewhere else.
     */
    'excluded_paths' => array(
        '/adguard/viewer.php',
    ),

    /*
     * Stable labels for joining local risk logs with aggregated AdSense
     * reports. They describe a site/route/referrer rule, never a person.
     */
    'analytics' => array(
        // Stable project name, kept constant even if the hostname changes.
        // Empty means "use the validated request host".
        'site_id' => '',
        // Hosts belonging to site_id. "*.example.com" is supported.
        'site_domains' => array(),
        // Human-facing viewer/report timezone. Raw JSONL stays UTC.
        // Set this to the timezone the operator actually reads dates in.
        'reporting_timezone' => 'UTC',
        // Stable page groups: group name => exact or wildcard path patterns.
        'route_groups' => array(),
        // Optional traffic-source grouping by referrer host pattern.
        'referrer_groups' => array(),
    ),

    /*
     * Web log viewer. DENY BY DEFAULT.
     *
     * These records are security telemetry about real visitors, so an empty
     * allowlist means "nobody", not "everybody". Add the operator's exact
     * REMOTE_ADDR only after deciding that browser access is warranted;
     * reading the JSONL over SSH is safer where that is practical.
     *
     * Do NOT ship this file to another project with addresses still in it.
     */
    'viewer' => array(
        'enabled' => true,
        'allowed_ips' => array(),
        'page_size' => 100,
        'max_actor_buckets' => 10000,
    ),
);
