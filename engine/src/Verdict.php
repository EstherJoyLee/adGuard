<?php
namespace RiskEngine;

/**
 * The final, combined output of risk_engine_evaluate()/risk_engine_inspect().
 * Deliberately uses its own vocabulary (NORMAL/ELEVATED/SUSPICIOUS/SEVERE)
 * rather than any integrating site's terms -- see README.md "Why an
 * engine-owned vocabulary".
 */
class Verdict
{
    const LEVEL_NORMAL = 'NORMAL';
    const LEVEL_ELEVATED = 'ELEVATED';
    const LEVEL_SUSPICIOUS = 'SUSPICIOUS';
    const LEVEL_SEVERE = 'SEVERE';

    public $level;
    public $score;
    public $reasons;
    /** @var SignalResult[] keyed by signal name */
    public $signals;

    public function __construct($level, $score, $reasons, $signals)
    {
        $this->level = (string)$level;
        $this->score = (int)$score;
        $this->reasons = is_array($reasons) ? $reasons : array();
        $this->signals = is_array($signals) ? $signals : array();
    }

    public function toArray()
    {
        $signalsArray = array();
        foreach ($this->signals as $name => $result) {
            $signalsArray[$name] = $result instanceof SignalResult ? $result->toArray() : $result;
        }
        return array(
            'level' => $this->level,
            'score' => $this->score,
            'reasons' => array_values($this->reasons),
            'signals' => $signalsArray,
        );
    }
}
