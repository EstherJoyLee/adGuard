<?php
namespace RiskEngine\Tests\Support;

use RiskEngine\RequestContext;
use RiskEngine\Signals\SessionChurnSignal;

/**
 * Test double for the "host page CAN set cookies" case.
 *
 * Under PHP's CLI SAPI headers_sent() is unconditionally true, so the real
 * SessionChurnSignal correctly refuses to issue its identity cookie and
 * therefore correctly refuses to count churn (see the long comment in
 * SessionChurnSignal::evaluate). That fail-safe is itself worth testing --
 * but it also means the counting path can never be exercised from CLI
 * without this seam. Overriding only the cookie-issuing step keeps every
 * other line of counting/scoring logic under test.
 */
class CookieIssuingChurnSignal extends SessionChurnSignal
{
    public $issueCalls = 0;

    protected function issueIdentityCookie(RequestContext $context)
    {
        $this->issueCalls++;
        return true;
    }
}
