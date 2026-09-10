<?php
namespace RiskEngine\Scoring;

use RiskEngine\Config;
use RiskEngine\SignalResult;
use RiskEngine\Verdict;

/**
 * Combines per-signal results into one Verdict: a config-weighted average,
 * with a small set of hard-override rules that can force a floor
 * regardless of the weighted average (a combination of weak signals can add
 * up to something the average alone would understate).
 */
class ScoreCombiner
{
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param SignalResult[] $results keyed by signal name
     * @return Verdict
     *
     * Deliberately NOT a flat average across every registered signal: that
     * would dilute one strong signal (e.g. a known-automation User-Agent)
     * by however many other signals happen to be inactive, which rewards
     * an integrator for wiring in more signals by making each individual
     * alarm weaker. Instead: the worst single (weighted) signal sets the
     * base score, and each additional signal that also triggered stacks a
     * partial bonus on top -- several independently-weak signals firing
     * together are worse than any one alone, but one signal never gets
     * washed out just because others stayed quiet.
     */
    public function combine($results)
    {
        $maxScore = 0;
        $triggeredScores = array();
        $reasons = array();

        foreach ($results as $name => $result) {
            $weight = (float)$this->config->get('signals.' . $name . '.weight', 1.0);
            $weighted = max(0, min(100, (int)round($result->score * $weight)));
            if ($weighted > $maxScore) {
                $maxScore = $weighted;
            }
            if ($result->triggered) {
                $triggeredScores[] = $weighted;
            }
            if ($result->triggered && $result->reason !== '') {
                $reasons[] = $name . ': ' . $result->reason;
            }
        }

        $score = $maxScore;
        if (count($triggeredScores) > 1) {
            sort($triggeredScores);
            array_pop($triggeredScores); // the max is already the base score
            $bonus = 0.0;
            foreach ($triggeredScores as $s) {
                $bonus += $s * 0.25;
            }
            $score = $score + $bonus;
        }
        $score = (int)round(max(0, min(100, $score)));
        $score = $this->applyHardOverrides($results, $score);

        return new Verdict($this->levelFor($score), $score, $reasons, $results);
    }

    /**
     * A request with neither a User-Agent nor an Accept header essentially
     * never comes from a real browser -- force at least SUSPICIOUS
     * regardless of how the weighted average landed.
     */
    private function applyHardOverrides($results, $score)
    {
        if (isset($results['user_agent'])) {
            $metrics = $results['user_agent']->metrics;
            $ua = isset($metrics['user_agent']) ? $metrics['user_agent'] : '';
            $hasAccept = isset($metrics['has_accept']) ? $metrics['has_accept'] : true;
            if ($ua === '' && !$hasAccept) {
                $floor = (int)$this->config->get('thresholds.suspicious', 50);
                if ($score < $floor) {
                    $score = $floor;
                }
            }
        }
        return $score;
    }

    private function levelFor($score)
    {
        if ($score >= (int)$this->config->get('thresholds.severe', 75)) {
            return Verdict::LEVEL_SEVERE;
        }
        if ($score >= (int)$this->config->get('thresholds.suspicious', 50)) {
            return Verdict::LEVEL_SUSPICIOUS;
        }
        if ($score >= (int)$this->config->get('thresholds.elevated', 25)) {
            return Verdict::LEVEL_ELEVATED;
        }
        return Verdict::LEVEL_NORMAL;
    }
}
