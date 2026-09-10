<?php
/**
 * Control fixture: PHP 5.6-safe code that happens to look similar to some
 * flagged patterns (ternaries, catch blocks, function calls) so the checker
 * is also proven not to false-positive on ordinary PHP 5.6 code.
 */

function greet($name)
{
    $name = isset($name) ? $name : 'world';
    return 'hello ' . $name;
}

function safeDivide($a, $b)
{
    if ($b === 0) {
        return null;
    }
    return $a / $b;
}

try {
    safeDivide(1, 0);
} catch (Exception $e) {
    // ordinary single-type catch, PHP 5.0+
}

$data = array('a' => 1, 'b' => 2);
$first = isset($data['a']) ? $data['a'] : null;

echo greet(null);
echo PHP_INT_MAX;
echo json_encode($data);
echo hash('sha256', 'x');
