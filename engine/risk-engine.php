<?php
/**
 * Portable, framework-agnostic HTTP request/session risk-scoring engine.
 *
 * Copy this whole risk-engine/ folder into any PHP 5.6+ project and:
 *
 *   require_once __DIR__ . '/risk-engine/risk-engine.php';
 *   $verdict = risk_engine_evaluate();
 *
 * See README.md for the full contract. This module has zero knowledge of
 * what the caller does with its verdict -- it only answers "how suspicious
 * does this request/session look," using signals that need no external
 * accounts, APIs, or bundled data files. No Composer, no database, no
 * build step.
 */

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/RequestContext.php';
require_once __DIR__ . '/src/SignalResult.php';
require_once __DIR__ . '/src/Verdict.php';
require_once __DIR__ . '/src/SignalInterface.php';
require_once __DIR__ . '/src/Storage/StorageInterface.php';
require_once __DIR__ . '/src/Storage/FileStorage.php';
require_once __DIR__ . '/src/Scoring/ScoreCombiner.php';
require_once __DIR__ . '/src/KnownCrawlers/IpRangeSet.php';
require_once __DIR__ . '/src/KnownCrawlers/CrawlerVerifier.php';
require_once __DIR__ . '/src/KnownCrawlers/RangeCache.php';
require_once __DIR__ . '/src/Signals/UserAgentAnomalySignal.php';
require_once __DIR__ . '/src/Signals/RateLimitSignal.php';
require_once __DIR__ . '/src/Signals/VisitorRateSignal.php';
require_once __DIR__ . '/src/Signals/SessionChurnSignal.php';
require_once __DIR__ . '/src/Engine.php';

if (!function_exists('risk_engine_build')) {
    /** @return \RiskEngine\Engine */
    function risk_engine_build()
    {
        static $engine = null;
        if ($engine !== null) {
            return $engine;
        }

        $config = \RiskEngine\Config::load();
        $storage = new \RiskEngine\Storage\FileStorage($config->get('storage.path'));

        /*
         * Known-crawler classification reads a locally cached range file that
         * tools/refresh-crawler-ranges.php maintains. It is optional in every
         * sense: with no cache the verifier is empty, classification reports
         * "unavailable", and scoring is byte-for-byte identical. The engine
         * keeps its "works with zero configuration and no bundled data" property.
         */
        $rangeCache = new \RiskEngine\KnownCrawlers\RangeCache(
            (string)$config->get('known_crawlers.cache_path', __DIR__ . '/../storage/crawler-ranges.php'),
            $config->get('known_crawlers.max_age_seconds', null)
        );
        // Deferred: an ordinary browser request never loads the range file.
        $crawlerVerifier = $rangeCache->lazyVerifier();

        $signals = array(
            new \RiskEngine\Signals\UserAgentAnomalySignal(
                (float)$config->get('signals.user_agent.weight', 1.0),
                $crawlerVerifier
            ),
            new \RiskEngine\Signals\RateLimitSignal(
                (float)$config->get('signals.rate_limit.weight', 1.0),
                $config->get('signals.rate_limit.windows', array(60 => 60))
            ),
            new \RiskEngine\Signals\VisitorRateSignal(
                (float)$config->get('signals.visitor_rate.weight', 1.0),
                $config->get('signals.visitor_rate.windows', array(60 => 30)),
                (string)$config->get('signals.visitor_rate.cookie_name', '__rek_id')
            ),
            new \RiskEngine\Signals\SessionChurnSignal(
                (float)$config->get('signals.session_churn.weight', 1.0),
                (int)$config->get('signals.session_churn.window_seconds', 600),
                (int)$config->get('signals.session_churn.threshold', 3),
                (string)$config->get('signals.session_churn.cookie_name', '__rek_id'),
                (int)$config->get('signals.session_churn.cookie_ttl_seconds', 31536000)
            ),
        );

        $engine = new \RiskEngine\Engine($config, $storage, $signals);
        return $engine;
    }
}

if (!function_exists('risk_engine_evaluate')) {
    /**
     * Evaluates the CURRENT request and mutates any stateful signal counters
     * (rate-limit windows, session-churn counters). Call once per real page
     * load you want the engine to observe.
     *
     * @return array{level:string,score:int,reasons:array,signals:array}
     */
    function risk_engine_evaluate()
    {
        $engine = risk_engine_build();
        $context = new \RiskEngine\RequestContext(
            null,
            null,
            null,
            $engine->getConfig()->get('identity', array())
        );
        return $engine->run($context, true)->toArray();
    }
}

if (!function_exists('risk_engine_inspect')) {
    /**
     * Read-only introspection -- never mutates stored counters. Pass
     * array('ip' => '203.0.113.9') to inspect a specific IP's current state
     * instead of the caller's own request (e.g. an admin debug lookup).
     *
     * @param array $overrides
     * @return array{level:string,score:int,reasons:array,signals:array}
     */
    function risk_engine_inspect($overrides = array())
    {
        $engine = risk_engine_build();
        $server = $_SERVER;
        if (isset($overrides['ip'])) {
            $server['REMOTE_ADDR'] = $overrides['ip'];
        }
        $context = new \RiskEngine\RequestContext(
            $server,
            null,
            null,
            $engine->getConfig()->get('identity', array())
        );
        return $engine->run($context, false)->toArray();
    }
}
