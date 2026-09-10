<?php
namespace RiskEngine\Signals;

use RiskEngine\RequestContext;
use RiskEngine\SignalInterface;
use RiskEngine\SignalResult;
use RiskEngine\Storage\StorageInterface;

/*
 * This signal names CrawlerVerifier's status constants even when no verifier
 * was supplied, so the class must be loadable regardless of how the caller
 * wired things up. Requiring it here keeps the file self-sufficient: a caller
 * that includes only this signal (several unit tests do) still works, and the
 * status strings keep a single definition instead of being duplicated.
 */
require_once __DIR__ . '/../KnownCrawlers/IpRangeSet.php';
require_once __DIR__ . '/../KnownCrawlers/CrawlerVerifier.php';

/**
 * Stateless: needs no storage, just the current request's headers. Good
 * signal to build/verify first since it has no storage dependency.
 *
 * SELF-DECLARED CRAWLERS ARE NOT SCORED AS ABUSE
 * ----------------------------------------------
 * An earlier version treated "bot"/"spider"/"crawler" in the User-Agent as
 * automation evidence worth 40 points -- the same weight as a covert client
 * like curl. Because 40 is also a hard-deny threshold downstream, a single UA
 * substring was enough to suppress the caller's action outright, and it did so
 * to Googlebot, AdsBot-Google, AdsBot-Google-Mobile, the mobile
 * Mediapartners-Google variant, and bingbot. Measured, not assumed.
 *
 * The original reasoning was that a crawler has no use for the downstream
 * action, so flagging it costs nothing. That is wrong twice over:
 *
 *  1. Some crawlers exist specifically to see the page as a visitor does --
 *     an ad-quality or contextual crawler judges the page by what it is
 *     served. Serving it a stripped page degrades the thing it measures.
 *  2. It scores honesty. A client that says "bot" is telling the truth; one
 *     that wants to hide simply does not say it. Penalising the disclosure
 *     reaches only the cooperative.
 *
 * "Is this a crawler?" and "is this abusive?" are different questions, so they
 * are answered separately now. Self-declaration is recorded as a DESCRIPTION
 * in the metrics -- available to policy and analytics -- and contributes no
 * points. Covert automation markers still score, because presenting as a
 * browser while not being one is itself the evidence.
 *
 * A crawler that actually misbehaves is still caught: the rate, visitor and
 * churn signals judge behaviour and are untouched by this change.
 */
class UserAgentAnomalySignal implements SignalInterface
{
    /**
     * Clients that do not present themselves as a browser at all. Their
     * presence is evidence about the client regardless of any claim.
     */
    private static $automationMarkers = array(
        'curl', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
        'okhttp', 'headlesschrome', 'phantomjs', 'axios', 'node-fetch',
        'scrapy', 'libwww-perl', 'java/',
    );

    /** @var \RiskEngine\KnownCrawlers\CrawlerVerifier|null */
    private $verifier;

    /**
     * @param float $weight   kept for API compatibility; ScoreCombiner applies
     *                        the configured weight exactly once.
     * @param mixed $verifier optional CrawlerVerifier. When absent, crawler
     *                        classification reports "unavailable". Scoring is
     *                        identical either way -- nothing about this
     *                        signal's safety depends on the verifier working.
     */
    public function __construct($weight = 1.0, $verifier = null)
    {
        $this->verifier = $verifier;
    }

    public function getName()
    {
        return 'user_agent';
    }

    public function evaluate(RequestContext $context, StorageInterface $storage, $mutate)
    {
        $ua = $context->getUserAgent();
        $accept = $context->getHeader('Accept');
        $acceptLanguage = $context->getHeader('Accept-Language');
        $acceptEncoding = $context->getHeader('Accept-Encoding');

        $points = 0;
        $reasons = array();

        if ($ua === '') {
            $points += 40;
            $reasons[] = 'User-Agent header is missing';
        } else {
            $lowerUa = strtolower($ua);
            foreach (self::$automationMarkers as $marker) {
                if (strpos($lowerUa, $marker) !== false) {
                    $points += 40;
                    $reasons[] = 'User-Agent matches known automation client ("' . $marker . '")';
                    break;
                }
            }
        }

        if ($accept === '') {
            $points += 15;
            $reasons[] = 'Accept header is missing';
        }
        if ($acceptLanguage === '') {
            $points += 10;
            $reasons[] = 'Accept-Language header is missing';
        }
        if ($acceptEncoding === '') {
            $points += 5;
            $reasons[] = 'Accept-Encoding header is missing';
        }

        $score = (int)round(min(100, $points));
        $score = max(0, min(100, $score));

        /*
         * Classification is descriptive metadata, deliberately computed AFTER
         * the score and never folded into it. Recording that a request is a
         * verified crawler must not become a way to raise or lower risk by
         * the back door -- callers decide what to do with the label.
         */
        $crawler = $this->classifyCrawler($context, $ua);

        return new SignalResult(
            $this->getName(),
            $score,
            $points > 0,
            implode('; ', $reasons),
            array(
                'user_agent' => $ua,
                'has_accept' => $accept !== '',
                'has_accept_language' => $acceptLanguage !== '',
                'has_accept_encoding' => $acceptEncoding !== '',
                // Client said "I am automation" without naming a vendor we can
                // check. A description, not an accusation, and worth 0 points.
                'self_declared_crawler' => \RiskEngine\KnownCrawlers\CrawlerVerifier::looksSelfDeclaredCrawler($ua),
                'crawler_status' => $crawler['status'],
                'crawler_vendor' => $crawler['vendor'],
                'crawler_group' => $crawler['group'],
            )
        );
    }

    /**
     * @return array{status:string,vendor:string,group:string}
     */
    private function classifyCrawler(RequestContext $context, $ua)
    {
        if ($this->verifier === null) {
            /*
             * No verifier wired in. Report "unavailable" rather than guessing:
             * absence of verification is not evidence in either direction.
             */
            return array(
                'status' => \RiskEngine\KnownCrawlers\CrawlerVerifier::VERIFICATION_UNAVAILABLE,
                'vendor' => '',
                'group' => '',
            );
        }
        try {
            return $this->verifier->classify($context->getIp(), $ua);
        } catch (\Exception $e) {
            // Classification is enrichment. It must never be able to fail the
            // signal, because a failed signal is treated as engine degradation
            // upstream and that would suppress the caller's action.
            return array(
                'status' => \RiskEngine\KnownCrawlers\CrawlerVerifier::VERIFICATION_UNAVAILABLE,
                'vendor' => '',
                'group' => '',
            );
        }
    }
}
