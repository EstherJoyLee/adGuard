<?php
/**
 * Public API for the universal AdSense response gate.
 *
 * Page/framework code should need only ad_guard_boot(). For server-wide
 * coverage, configure ad-guard/auto-prepend.php as PHP's auto_prepend_file.
 */

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/AnalyticsContext.php';
require_once __DIR__ . '/src/AdsenseDetector.php';
require_once __DIR__ . '/src/DecisionLogger.php';
require_once __DIR__ . '/src/Guard.php';

if (!function_exists('ad_guard_instance')) {
    function ad_guard_instance()
    {
        static $guard = null;
        if ($guard !== null) {
            return $guard;
        }

        $config = \AdGuard\Config::load();
        $provider = function () {
            require_once __DIR__ . '/engine/risk-engine.php';
            return risk_engine_evaluate();
        };
        $guard = new \AdGuard\Guard($config, $provider);
        return $guard;
    }
}

if (!function_exists('ad_guard_boot')) {
    function ad_guard_boot()
    {
        return ad_guard_instance()->start();
    }
}

if (!function_exists('ad_guard_decision')) {
    function ad_guard_decision()
    {
        return ad_guard_instance()->getDecision();
    }
}

if (!function_exists('ad_guard_ads_allowed')) {
    function ad_guard_ads_allowed()
    {
        return ad_guard_instance()->adsAllowed();
    }
}

if (!function_exists('ad_guard_note_external_suppression')) {
    /**
     * Tell the guard that the integration itself suppressed ads for this
     * response, so the audit log records what the visitor actually got.
     */
    function ad_guard_note_external_suppression($reason)
    {
        ad_guard_instance()->noteExternalSuppression($reason);
    }
}

if (!function_exists('ad_guard_mark_ad_opportunity')) {
    function ad_guard_mark_ad_opportunity()
    {
        ad_guard_instance()->markAdOpportunity();
    }
}

if (!function_exists('ad_guard_set_analytics_context')) {
    /**
     * Optional site integration for stable route/redirect/source labels.
     * Call before the response finishes; never pass a raw visitor identifier.
     */
    function ad_guard_set_analytics_context($context)
    {
        return ad_guard_instance()->setAnalyticsContext($context);
    }
}
