<?php
namespace AdGuard;

/**
 * One request-scoped decision and one response-scoped enforcement point.
 * Content is never blocked; only the executable AdSense bootstrap is gated.
 */
class Guard
{
    private $config;
    private $detector;
    private $logger;
    private $decisionProvider;
    private $decision = null;
    private $started = false;
    private $logged = false;
    private $adOpportunity = false;
    private $buffer = '';
    private $externalSuppression = '';
    private $analyticsContext = array();

    public function __construct(Config $config, $decisionProvider, $logger = null, $detector = null)
    {
        $this->config = $config;
        $this->decisionProvider = $decisionProvider;
        $this->logger = $logger === null ? new DecisionLogger($config) : $logger;
        $this->detector = $detector === null ? new AdsenseDetector() : $detector;
    }

    public function start()
    {
        if ($this->started || !$this->isActive() || $this->isExcludedPath()) {
            return false;
        }
        $this->started = true;
        ob_start(array($this, 'filterOutput'));
        return true;
    }

    public function markAdOpportunity()
    {
        $this->adOpportunity = true;
    }

    /**
     * Record that something OUTSIDE this package suppressed the ad bootstrap
     * for this response (for example a site-level preview override, or an
     * integrator's own risk provider).
     *
     * Without this, the audit log would report the guard's own verdict --
     * typically ALLOW -- for a response that in fact served no ads, so an
     * operator reading the log could not tell what actually happened.
     */
    public function noteExternalSuppression($reason)
    {
        $reason = trim((string)$reason);
        if ($reason !== '' && $this->externalSuppression === '') {
            $this->externalSuppression = $reason;
        }
    }

    /**
     * Attach stable, server-owned labels to this ad-bearing response.
     * These labels support aggregate joins; they are never click evidence.
     */
    public function setAnalyticsContext($context)
    {
        if (!is_array($context)) {
            return false;
        }
        foreach (array('route_group', 'redirect_rule_id', 'traffic_source_group') as $key) {
            if (isset($context[$key])) {
                $value = str_replace(array("\r", "\n", "\0"), ' ', (string)$context[$key]);
                $this->analyticsContext[$key] = substr(trim($value), 0, 80);
            }
        }
        return true;
    }

    public function adsAllowed()
    {
        $this->markAdOpportunity();
        $decision = $this->getDecision();
        return !empty($decision['ads_allowed']);
    }

    public function getDecision()
    {
        if ($this->decision !== null) {
            return $this->decision;
        }

        if (!$this->isActive()) {
            $this->decision = $this->allowDecision('guard_disabled');
            return $this->decision;
        }

        $providerFailed = false;
        try {
            $verdict = call_user_func($this->decisionProvider);
            if (!is_array($verdict)) {
                throw new \RuntimeException('decision provider returned a non-array result');
            }
        } catch (\Exception $e) {
            $providerFailed = true;
            $verdict = array(
                'level' => 'SEVERE',
                'score' => 100,
                'reasons' => array('decision provider failure'),
                'signals' => array(),
            );
        }

        $level = strtoupper(isset($verdict['level']) ? (string)$verdict['level'] : 'NORMAL');
        $score = isset($verdict['score']) ? max(0, min(100, (int)$verdict['score'])) : 0;
        $signals = isset($verdict['signals']) && is_array($verdict['signals']) ? $verdict['signals'] : array();
        $reasons = isset($verdict['reasons']) && is_array($verdict['reasons']) ? $verdict['reasons'] : array();

        $signalFailed = $this->hasSignalError($signals);
        $storageDegraded = $this->hasStorageDegradation($signals);
        $degraded = $providerFailed || $signalFailed || $storageDegraded;
        $policyAllowed = true;
        $policyReason = 'risk_below_deny_threshold';

        if (($providerFailed || $signalFailed) && $this->config->get('policy.fail_closed', true)) {
            $policyAllowed = false;
            $policyReason = 'risk_engine_degraded_fail_closed';
        } elseif ($storageDegraded && $this->config->get('policy.fail_closed_on_storage_degraded', true)) {
            $policyAllowed = false;
            $policyReason = 'risk_storage_degraded_fail_closed';
        } elseif (in_array($level, (array)$this->config->get('policy.blocked_levels', array()), true)) {
            $policyAllowed = false;
            $policyReason = 'blocked_engine_level';
        } else {
            $hardSignal = $this->hardDenySignal($signals);
            if ($hardSignal !== '') {
                $policyAllowed = false;
                $policyReason = 'hard_deny_signal:' . $hardSignal;
            }
        }

        $mode = (string)$this->config->get('mode', 'enforce');
        $actuallyAllowed = $mode === 'monitor' ? true : $policyAllowed;
        $action = $policyAllowed ? 'ALLOW' : ($mode === 'monitor' ? 'MONITOR_DENY' : 'DENY');

        $this->decision = array(
            'engine_level' => $level,
            'score' => $score,
            'reasons' => $reasons,
            'signals' => $signals,
            'policy_allowed' => $policyAllowed,
            'ads_allowed' => $actuallyAllowed,
            'action' => $action,
            'policy_reason' => $policyReason,
            'degraded' => $degraded,
        );
        return $this->decision;
    }

    /** Output-buffer callback. Retains chunks until FINAL to avoid split tags. */
    public function filterOutput($chunk, $phase = null)
    {
        $this->buffer .= (string)$chunk;
        $finalFlag = defined('PHP_OUTPUT_HANDLER_FINAL') ? PHP_OUTPUT_HANDLER_FINAL : 8;
        if ($phase !== null && (((int)$phase & $finalFlag) === 0)) {
            return '';
        }

        $html = $this->buffer;
        $this->buffer = '';
        return $this->processHtml($html, $this->responseContentType());
    }

    /** Public for deterministic integration tests and non-buffer adapters. */
    public function processHtml($html, $contentType = 'text/html')
    {
        if (!$this->isHtmlContentType($contentType)) {
            return $html;
        }

        $detected = $this->adOpportunity || $this->detector->containsAdsense($html);
        if (!$detected) {
            return $html;
        }

        $inventory = $this->detector->inventory($html);
        $decision = $this->getDecision();
        $removed = 0;
        $result = $html;
        if (empty($decision['ads_allowed']) && $this->config->get('mode', 'enforce') === 'enforce') {
            $result = $this->detector->removeBootstrap($html, $removed);
            if ($removed > 0 && !headers_sent() && function_exists('header_remove')) {
                header_remove('Content-Length');
            }
        }

        if (!$this->logged) {
            $this->logged = true;
            // "Was a runnable bootstrap actually delivered?" is what an
            // operator needs from this log -- not just what this package
            // decided. A response can end up ad-free because the guard
            // stripped it, OR because the integration never emitted it.
            $finalBootstrapCount = $this->detector->bootstrapCount($result);
            $bootstrapPresent = $finalBootstrapCount > 0;
            $policySuppressed = !$bootstrapPresent && (
                ($this->config->get('mode', 'enforce') === 'enforce' && empty($decision['ads_allowed']))
                || $this->externalSuppression !== ''
            );
            $this->logger->log($decision, array_merge($this->analyticsContext, array(
                'adsense_detected' => $detected,
                'bootstrap_removed' => $removed,
                'external_suppression' => $this->externalSuppression,
                'ads_served' => $bootstrapPresent,
                'ad_delivery' => $this->adDelivery($inventory, $finalBootstrapCount, $policySuppressed),
            )));
        }

        return $result;
    }

    /**
     * Per-response ad opportunity outcomes. "Provided" means the executable
     * loader survived in HTML, never that Google counted an impression.
     */
    private function adDelivery($inventory, $finalBootstrapCount, $policySuppressed)
    {
        $inventory = is_array($inventory) ? $inventory : array();
        $manualCount = isset($inventory['manual_unit_count']) ? (int)$inventory['manual_unit_count'] : 0;
        $bootstrapOriginal = isset($inventory['bootstrap_count']) ? (int)$inventory['bootstrap_count'] : 0;
        $bootstrapOpportunities = max($bootstrapOriginal, ($this->adOpportunity || $manualCount > 0) ? 1 : 0);
        $bootstrapProvided = min($bootstrapOpportunities, max(0, (int)$finalBootstrapCount));
        $bootstrapRemaining = max(0, $bootstrapOpportunities - $bootstrapProvided);
        $bootstrapBlocked = $policySuppressed ? $bootstrapRemaining : 0;
        $bootstrapMissing = max(0, $bootstrapRemaining - $bootstrapBlocked);

        $status = $finalBootstrapCount > 0
            ? 'provided'
            : ($policySuppressed ? 'blocked' : 'missing');
        $units = array();
        foreach ((array)(isset($inventory['manual_units']) ? $inventory['manual_units'] : array()) as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $units[] = array(
                'slot' => isset($unit['slot']) ? (string)$unit['slot'] : 'unlabeled',
                'format' => isset($unit['format']) ? (string)$unit['format'] : 'default',
                'ordinal' => isset($unit['ordinal']) ? (int)$unit['ordinal'] : 1,
                'status' => $status,
            );
        }

        return array(
            'bootstrap' => array(
                'opportunities' => $bootstrapOpportunities,
                'provided' => $bootstrapProvided,
                'blocked' => $bootstrapBlocked,
                'missing' => $bootstrapMissing,
            ),
            'manual_unit_count' => $manualCount,
            'manual_units' => $units,
            'truncated' => !empty($inventory['truncated']),
        );
    }

    private function isActive()
    {
        return $this->config->get('enabled', true) && $this->config->get('mode', 'enforce') !== 'off';
    }

    private function isExcludedPath()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        foreach ((array)$this->config->get('excluded_paths', array()) as $excluded) {
            $excluded = (string)$excluded;
            if ($excluded !== '' && ($path === $excluded || substr($path, -strlen($excluded)) === $excluded)) {
                return true;
            }
        }
        return false;
    }

    private function hardDenySignal($signals)
    {
        $rules = (array)$this->config->get('policy.hard_deny_signals', array());
        foreach ($rules as $name => $minimumScore) {
            if (!isset($signals[$name]) || !is_array($signals[$name])) {
                continue;
            }
            $signal = $signals[$name];
            $triggered = !empty($signal['triggered']);
            $score = isset($signal['score']) ? (int)$signal['score'] : 0;
            if ($triggered && $score >= (int)$minimumScore) {
                return (string)$name;
            }
        }
        return '';
    }

    private function hasSignalError($signals)
    {
        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $reason = isset($signal['reason']) ? (string)$signal['reason'] : '';
            if (strpos($reason, 'signal error:') === 0) {
                return true;
            }
        }
        return false;
    }

    private function hasStorageDegradation($signals)
    {
        foreach ($signals as $signal) {
            if (is_array($signal) && !empty($signal['metrics']['storage_degraded'])) {
                return true;
            }
        }
        return false;
    }

    private function responseContentType()
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return trim(substr($header, strlen('Content-Type:')));
            }
        }
        return 'text/html';
    }

    private function isHtmlContentType($contentType)
    {
        $contentType = strtolower((string)$contentType);
        return strpos($contentType, 'text/html') !== false
            || strpos($contentType, 'application/xhtml+xml') !== false;
    }

    private function allowDecision($reason)
    {
        return array(
            'engine_level' => 'NORMAL', 'score' => 0, 'reasons' => array(), 'signals' => array(),
            'policy_allowed' => true, 'ads_allowed' => true, 'action' => 'ALLOW',
            'policy_reason' => (string)$reason, 'degraded' => false,
        );
    }
}
