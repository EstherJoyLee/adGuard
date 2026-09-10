<?php
/**
 * Locks in the false-positive safety invariants that were established by
 * measurement, so a later config tweak cannot silently reintroduce them.
 *
 * Background: with the original settings, fifteen ordinary visitors sharing
 * one public IP had their ads denied at the 41st request while the risk
 * engine itself rated the traffic NORMAL (score 3). Two separate defects
 * combined to do that -- a hard-deny rule that fired on any threshold
 * crossing, and per-IP signal weights high enough to cross the blocking
 * band alone.
 *
 * Run: php ad-guard/tests/policy-safety-test.php
 */

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../engine/src/Config.php';

$failures = array();
function pst_assert(&$failures, $label, $condition, $detail = '')
{
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    } else {
        echo "ok:   $label\n";
    }
}

$guard = \AdGuard\Config::load();
$engine = \RiskEngine\Config::load();

$suspicious = (int)$engine->get('thresholds.suspicious', 50);

/* ---------------------------------------------------------------------
 * Invariant 1: a signal whose bucket is keyed by IP describes an ADDRESS,
 * not a person. Many unrelated people share one address (carrier NAT,
 * internet cafes, offices, schools), so such a signal must never be able
 * to reach the blocking band on its own -- it always needs corroboration.
 * ------------------------------------------------------------------- */
$sharedIpSignals = array('rate_limit', 'session_churn');
foreach ($sharedIpSignals as $name) {
    $weight = (float)$engine->get('signals.' . $name . '.weight', 1.0);
    $soloMax = (int)round(100 * $weight);
    pst_assert(
        $failures,
        "per-IP signal '$name' cannot reach the blocking band alone",
        $soloMax < $suspicious,
        "weighted max $soloMax vs suspicious threshold $suspicious"
    );
}

/* ---------------------------------------------------------------------
 * Invariant 2: those same per-IP signals must not appear as hard-deny
 * rules, which bypass the combined verdict entirely.
 * ------------------------------------------------------------------- */
$hardDeny = (array)$guard->get('policy.hard_deny_signals', array());
foreach ($sharedIpSignals as $name) {
    pst_assert(
        $failures,
        "per-IP signal '$name' is not a hard-deny rule",
        !array_key_exists($name, $hardDeny),
        'hard_deny_signals: ' . implode(', ', array_keys($hardDeny))
    );
}

/* ---------------------------------------------------------------------
 * Invariant 3: a hard-deny minimum is the signal's own score. For the rate
 * signals that score is "percent over the configured allowance", so a
 * minimum of 1 means "deny the moment the allowance is exceeded by a
 * single request" -- far too tight to justify overriding the verdict.
 * ------------------------------------------------------------------- */
foreach ($hardDeny as $name => $minimum) {
    pst_assert(
        $failures,
        "hard-deny minimum for '$name' is a meaningful score, not a hair trigger",
        (int)$minimum >= 40,
        "minimum is $minimum"
    );
}

/* ---------------------------------------------------------------------
 * Invariant 4: at least one high-precision signal must still be able to
 * block, otherwise the fixes above would have simply disabled protection.
 * ------------------------------------------------------------------- */
$blockingCapable = array();
foreach (array('user_agent', 'visitor_rate') as $name) {
    $weight = (float)$engine->get('signals.' . $name . '.weight', 1.0);
    if ((int)round(100 * $weight) >= $suspicious) {
        $blockingCapable[] = $name;
    }
}
pst_assert(
    $failures,
    'a per-identity or direct-evidence signal can still block',
    count($blockingCapable) > 0,
    'blocking-capable: ' . implode(', ', $blockingCapable)
);

/* ---------------------------------------------------------------------
 * Invariant 5: the log viewer denies by default. These records describe
 * real visitors, so an empty allowlist must mean nobody.
 * ------------------------------------------------------------------- */
$viewerIps = $guard->get('viewer.allowed_ips', array());
pst_assert(
    $failures,
    'viewer.allowed_ips exists and is a list (deny-by-default when empty)',
    is_array($viewerIps)
);

/* Invalid/missing local config must fail to the monitor-safe default. */
$missing = \AdGuard\Config::load(sys_get_temp_dir() . '/adguard-config-does-not-exist-' . uniqid());
pst_assert($failures, 'missing guard config keeps monitor mode', $missing->get('mode') === 'monitor');
$badPath = sys_get_temp_dir() . '/adguard-invalid-mode-' . uniqid() . '.php';
file_put_contents($badPath, '<?php return array("mode" => "unexpected");');
$bad = \AdGuard\Config::load($badPath);
@unlink($badPath);
pst_assert($failures, 'invalid guard mode keeps monitor mode', $bad->get('mode') === 'monitor');

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nAll policy safety invariants hold.\n";
exit(0);
