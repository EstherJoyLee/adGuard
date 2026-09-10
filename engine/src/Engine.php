<?php
namespace RiskEngine;

use RiskEngine\Storage\StorageInterface;
use RiskEngine\Scoring\ScoreCombiner;

/**
 * Orchestrator: runs every enabled signal against the current request and
 * hands the results to ScoreCombiner. A misbehaving signal degrades to a
 * neutral result instead of ever taking the calling page down -- this is
 * the one place that guarantee is enforced for all signals uniformly.
 */
class Engine
{
    private $config;
    private $storage;
    /** @var SignalInterface[] */
    private $signals;
    private $combiner;

    public function __construct(Config $config, StorageInterface $storage, $signals)
    {
        $this->config = $config;
        $this->storage = $storage;
        $this->signals = is_array($signals) ? $signals : array();
        $this->combiner = new ScoreCombiner($config);
    }

    /** Exposes immutable configuration to the package adapter only. */
    public function getConfig()
    {
        return $this->config;
    }

    public function run(RequestContext $context, $mutate)
    {
        if ($mutate) {
            $this->maybeGc();
        }

        $results = array();
        foreach ($this->signals as $signal) {
            $name = $signal->getName();
            if (!$this->config->get('signals.' . $name . '.enabled', true)) {
                continue;
            }
            try {
                $results[$name] = $signal->evaluate($context, $this->storage, $mutate);
            } catch (\Exception $e) {
                $results[$name] = SignalResult::neutral($name, 'signal error: ' . $e->getMessage());
            }
        }
        return $this->combiner->combine($results);
    }

    /**
     * No cron job -- runs inline on a small random fraction of mutating
     * requests only (never on inspect()), same pattern as PHP's own session
     * GC. A GC failure must never affect the request it happened to run on.
     */
    private function maybeGc()
    {
        $probability = (float)$this->config->get('storage.gc_probability', 0.01);
        if ($probability <= 0) {
            return;
        }
        $roll = mt_rand() / mt_getrandmax();
        if ($roll > $probability) {
            return;
        }
        try {
            $this->storage->gc((int)$this->config->get('storage.gc_max_age_seconds', 172800));
        } catch (\Exception $e) {
            // Deliberately swallowed -- GC is maintenance, not part of the
            // request's own correctness.
        }
    }
}
