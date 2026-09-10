<?php
/**
 * Control fixture: exercises one construct per line from every category
 * tools/php56-compat-check.php claims to detect. Used by
 * tools/php56-compat-check-selftest.php to prove the checker actually
 * catches violations instead of just passing on already-clean code. This
 * file is intentionally invalid on PHP 5.6 -- never require/include it.
 */
declare(strict_types=1);

function a(int $x, ?string $y): bool
{
    return $x > 0 && $y !== null;
}

function b(string|int $z)
{
    return $z;
}

$v = $_GET['q'] ?? 'default';
$w = 1 <=> 2;
$t = random_bytes(16);
$u = intdiv(7, 2);
$s = str_contains('abc', 'b');
$k = array_key_first(array(1, 2));
$f = fn($n) => $n * 2;
$m = match (true) {
    default => 1,
};
$o = null;
$p = $o?->foo;
$c = new class {
    public $x = 1;
};
$q = $v ??= 'z';

try {
    a(1, 'x');
} catch (Throwable $e) {
}
try {
    a(1, 'x');
} catch (TypeError | ValueError $e) {
}

echo PHP_INT_MIN;
echo JSON_THROW_ON_ERROR;

function gen()
{
    yield from array(1, 2);
}
