<?php
namespace RiskEngine;

/**
 * The output of a single signal's evaluation. Plain data holder, no behavior
 * beyond shape validation -- keeps every signal's result uniform regardless
 * of what it measured internally.
 */
class SignalResult
{
    public $name;
    public $score;
    public $triggered;
    public $reason;
    public $metrics;

    public function __construct($name, $score, $triggered, $reason, $metrics)
    {
        $this->name = (string)$name;
        $this->score = max(0, min(100, (int)$score));
        $this->triggered = (bool)$triggered;
        $this->reason = (string)$reason;
        $this->metrics = is_array($metrics) ? $metrics : array();
    }

    /** A signal that could not run (or found nothing) reports itself this way. */
    public static function neutral($name, $reason = '')
    {
        return new self($name, 0, false, $reason, array());
    }

    public function toArray()
    {
        return array(
            'score' => $this->score,
            'triggered' => $this->triggered,
            'reason' => $this->reason,
            'metrics' => $this->metrics,
        );
    }
}
