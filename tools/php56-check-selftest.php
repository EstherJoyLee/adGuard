<?php
/**
 * Proves tools/php56-compat-check.php actually detects what it claims to,
 * rather than just passing quietly on already-clean code. Run:
 *   php tools/php56-compat-check-selftest.php
 */

$root = dirname(__DIR__);
$checker = __DIR__ . '/php56-check.php';
$badFixture = __DIR__ . '/fixtures/php56-known-bad.php';
$cleanFixture = __DIR__ . '/fixtures/php56-known-clean.php';

$failures = array();

function php56s_assert(&$failures, $label, $condition)
{
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

// --- Bad fixture: every category must be flagged at least once ---
$badOutput = shell_exec('php ' . escapeshellarg($checker) . ' ' . escapeshellarg($badFixture) . ' 2>&1');

$expectedCategories = array(
    'declare(strict_types)',
    'scalar parameter type',
    'nullable parameter type',
    'return type declaration',
    'union parameter type',
    'T_COALESCE',
    'T_SPACESHIP',
    'function random_bytes()',
    'function intdiv()',
    'function str_contains()',
    'function array_key_first()',
    'T_FN',
    'T_MATCH',
    'T_NULLSAFE_OBJECT_OPERATOR',
    'anonymous class',
    'T_COALESCE_EQUAL',
    'class/interface Throwable',
    'multi-catch',
    'class/interface TypeError',
    'class/interface ValueError',
    'constant PHP_INT_MIN',
    'constant JSON_THROW_ON_ERROR',
    'T_YIELD_FROM',
);

foreach ($expectedCategories as $category) {
    php56s_assert($failures, 'bad fixture flags: ' . $category, strpos($badOutput, $category) !== false);
}

$badExit = 0;
exec('php ' . escapeshellarg($checker) . ' ' . escapeshellarg($badFixture), $tmp, $badExit);
php56s_assert($failures, 'bad fixture -> non-zero exit code', $badExit !== 0);

// --- Clean fixture: zero findings, exit 0 ---
$cleanOutput = shell_exec('php ' . escapeshellarg($checker) . ' ' . escapeshellarg($cleanFixture) . ' 2>&1');
php56s_assert($failures, 'clean fixture -> "no post-PHP-5.6" result', strpos($cleanOutput, 'no post-PHP-5.6') !== false);

$cleanExit = 0;
exec('php ' . escapeshellarg($checker) . ' ' . escapeshellarg($cleanFixture), $tmp2, $cleanExit);
php56s_assert($failures, 'clean fixture -> exit code 0', $cleanExit === 0);

// --- Real project code (risk-engine so far) must also be clean ---
$projectOutput = shell_exec('php ' . escapeshellarg($checker) . ' ' . escapeshellarg($root . '/engine') . ' 2>&1');
php56s_assert($failures, 'engine/ -> "no post-PHP-5.6" result', strpos($projectOutput, 'no post-PHP-5.6') !== false);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}
echo "\nphp56-compat-check.php self-test passed: detects every known-bad category, zero false positives on clean code, engine/ itself is clean.\n";
exit(0);
