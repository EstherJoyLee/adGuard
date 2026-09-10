<?php
namespace RiskEngine\Tests\Support;

use RiskEngine\RequestContext;
use RiskEngine\SignalInterface;
use RiskEngine\Storage\StorageInterface;

/**
 * Test-only fixture: a signal that always throws, used to prove Engine::run()
 * degrades a misbehaving signal to a neutral result instead of ever letting
 * the exception propagate and crash the calling page. Lives under tests/,
 * not shipped as part of the module.
 */
class ThrowingSignal implements SignalInterface
{
    public function getName()
    {
        return 'broken';
    }

    public function evaluate(RequestContext $context, StorageInterface $storage, $mutate)
    {
        throw new \Exception('deliberately broken for the reliability test');
    }
}
