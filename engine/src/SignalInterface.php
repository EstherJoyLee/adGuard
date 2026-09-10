<?php
namespace RiskEngine;

use RiskEngine\Storage\StorageInterface;

/**
 * Contract every signal implements. A signal answers one narrow question
 * ("does the rate look off?", "does the User-Agent look automated?") and
 * must never know anything about what its verdict will be used for.
 */
interface SignalInterface
{
    /** Short, stable identifier -- also the key used in config and in the
     *  verdict's "signals" map. e.g. "user_agent", "rate_limit". */
    public function getName();

    /**
     * @param RequestContext $context
     * @param StorageInterface $storage
     * @param bool $mutate false = read-only inspect; a stateful signal must
     *                      not write/increment any counters when false.
     * @return SignalResult
     */
    public function evaluate(RequestContext $context, StorageInterface $storage, $mutate);
}
