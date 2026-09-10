<?php
/**
 * Static PHP 5.6 compatibility auditor.
 *
 * Runs on a modern PHP tokenizer and flags anything a PHP 5.6 parser or
 * runtime would reject: post-5.6 syntax tokens, type declarations, and
 * calls to functions/classes/constants that did not exist in 5.6.
 *
 * This is a STATIC audit only. It cannot see runtime-only failures (ini
 * settings, missing extensions) and is not a substitute for actually
 * running on a PHP 5.6 binary. See tools/php56-compat-check-selftest.php
 * for proof this checker actually detects what it claims to.
 *
 * Usage: php tools/php56-compat-check.php <file-or-dir> ...
 */

$targets = $argv;
array_shift($targets);
if (!$targets) {
    fwrite(STDERR, "usage: php php56-compat-check.php <file-or-dir> ...\n");
    exit(2);
}

$FUNCS = array(
    'random_bytes' => '7.0', 'random_int' => '7.0', 'intdiv' => '7.0',
    'error_clear_last' => '7.0', 'preg_replace_callback_array' => '7.0',
    'is_iterable' => '7.1', 'spl_object_id' => '7.2', 'stream_isatty' => '7.2',
    'is_countable' => '7.3', 'array_key_first' => '7.3', 'array_key_last' => '7.3',
    'hrtime' => '7.3', 'password_algos' => '7.4', 'mb_str_split' => '7.4',
    'get_mangled_object_vars' => '7.4', 'str_contains' => '8.0', 'str_starts_with' => '8.0',
    'str_ends_with' => '8.0', 'fdiv' => '8.0', 'get_debug_type' => '8.0',
    'preg_last_error_msg' => '8.0', 'array_is_list' => '8.1', 'enum_exists' => '8.1',
    'fsync' => '8.1', 'fdatasync' => '8.1', 'ini_parse_quantity' => '8.2',
    'memory_reset_peak_usage' => '8.2', 'json_validate' => '8.3', 'mb_str_pad' => '8.3',
    'array_find' => '8.4', 'array_any' => '8.4', 'array_all' => '8.4', 'mb_trim' => '8.4',
);

$CLASSES = array(
    'Throwable' => '7.0', 'Error' => '7.0', 'TypeError' => '7.0', 'ParseError' => '7.0',
    'ArithmeticError' => '7.0', 'AssertionError' => '7.0', 'DivisionByZeroError' => '7.0',
    'ArgumentCountError' => '7.1', 'JsonException' => '7.3', 'WeakReference' => '7.4',
    'Stringable' => '8.0', 'Attribute' => '8.0', 'ValueError' => '8.0',
    'UnhandledMatchError' => '8.0', 'WeakMap' => '8.0', 'Fiber' => '8.1',
    'SensitiveParameter' => '8.2',
);

$CONSTS = array(
    'PHP_INT_MIN' => '7.0', 'PHP_FLOAT_EPSILON' => '7.2', 'PHP_FLOAT_MAX' => '7.2',
    'PHP_FLOAT_MIN' => '7.2', 'PHP_FLOAT_DIG' => '7.2', 'JSON_THROW_ON_ERROR' => '7.3',
    'JSON_INVALID_UTF8_IGNORE' => '7.2', 'PASSWORD_ARGON2I' => '7.2', 'PASSWORD_ARGON2ID' => '7.3',
);

$TYPE_WORDS = array('int', 'float', 'string', 'bool', 'void', 'iterable', 'object', 'mixed', 'never', 'static', 'null', 'false', 'true');

$files = array();
foreach ($targets as $t) {
    if (is_dir($t)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (substr($f->getFilename(), -4) === '.php') {
                $files[] = $f->getPathname();
            }
        }
    } elseif (is_file($t)) {
        $files[] = $t;
    }
}
sort($files);

$findings = array();
function php56cc_flag(&$findings, $file, $line, $ver, $what)
{
    $findings[] = array('file' => $file, 'line' => $line, 'ver' => $ver, 'what' => $what);
}

foreach ($files as $file) {
    $src = file_get_contents($file);
    $toks = token_get_all($src);
    $n = count($toks);

    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t)) {
            continue;
        }

        $name = token_name($t[0]);
        $line = $t[2];

        $syntaxMap = array(
            'T_COALESCE' => '7.0', 'T_SPACESHIP' => '7.0', 'T_YIELD_FROM' => '7.0',
            'T_COALESCE_EQUAL' => '7.4', 'T_FN' => '7.4',
            'T_NULLSAFE_OBJECT_OPERATOR' => '8.0', 'T_MATCH' => '8.0',
            'T_ATTRIBUTE' => '8.0', 'T_ENUM' => '8.1', 'T_READONLY' => '8.1',
        );
        if (isset($syntaxMap[$name])) {
            php56cc_flag($findings, $file, $line, $syntaxMap[$name], 'syntax ' . $name . ' "' . trim($t[1]) . '"');
        }
        if ($name === 'T_STRING' && strcasecmp($t[1], 'strict_types') === 0) {
            php56cc_flag($findings, $file, $line, '7.0', 'declare(strict_types)');
        }

        if ($t[0] === T_NEW) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($toks[$j]) && $toks[$j][0] === T_CLASS) {
                    php56cc_flag($findings, $file, $line, '7.0', 'anonymous class');
                }
                break;
            }
        }

        if ($t[0] === T_FUNCTION) {
            $depth = 0;
            $seenParen = false;
            $close = -1;
            for ($j = $i + 1; $j < $n; $j++) {
                $c = is_array($toks[$j]) ? $toks[$j][1] : $toks[$j];
                if ($c === '(') {
                    $depth++;
                    $seenParen = true;
                } elseif ($c === ')') {
                    $depth--;
                    if ($seenParen && $depth === 0) {
                        $close = $j;
                        break;
                    }
                } elseif ($seenParen && $depth === 1) {
                    if ($c === '?') {
                        php56cc_flag($findings, $file, $line, '7.1', 'nullable parameter type "?"');
                    }
                    if ($c === '|') {
                        php56cc_flag($findings, $file, $line, '8.0', 'union parameter type "|"');
                    }
                    if (is_array($toks[$j]) && $toks[$j][0] === T_STRING
                        && in_array(strtolower($toks[$j][1]), $GLOBALS['TYPE_WORDS'], true)) {
                        for ($k = $j + 1; $k < $n; $k++) {
                            if (is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) {
                                continue;
                            }
                            $nx = is_array($toks[$k]) ? $toks[$k][0] : $toks[$k];
                            if ($nx === T_VARIABLE || $nx === '&' || $nx === T_ELLIPSIS) {
                                php56cc_flag($findings, $file, $line, '7.0', 'scalar parameter type "' . $toks[$j][1] . '"');
                            }
                            break;
                        }
                    }
                }
            }
            if ($close > 0) {
                for ($k = $close + 1; $k < $n; $k++) {
                    if (is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) {
                        continue;
                    }
                    if ($toks[$k] === ':') {
                        php56cc_flag($findings, $file, $line, '7.0', 'return type declaration');
                    }
                    break;
                }
            }
        }

        if ($t[0] === T_CATCH) {
            for ($j = $i + 1; $j < $n && $j < $i + 40; $j++) {
                $c = is_array($toks[$j]) ? $toks[$j][1] : $toks[$j];
                if ($c === ')') {
                    break;
                }
                if ($c === '|') {
                    php56cc_flag($findings, $file, $line, '7.1', 'multi-catch "|"');
                    break;
                }
            }
        }

        if ($t[0] === T_STRING) {
            $lower = strtolower($t[1]);
            $prev = null;
            for ($p = $i - 1; $p >= 0; $p--) {
                if (is_array($toks[$p]) && $toks[$p][0] === T_WHITESPACE) {
                    continue;
                }
                $prev = $toks[$p];
                break;
            }
            $next = null;
            for ($q = $i + 1; $q < $n; $q++) {
                if (is_array($toks[$q]) && $toks[$q][0] === T_WHITESPACE) {
                    continue;
                }
                $next = $toks[$q];
                break;
            }
            $prevIsArrowOrDecl = is_array($prev)
                && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CLASS, T_CONST), true);

            if ($next === '(' && !$prevIsArrowOrDecl && isset($GLOBALS['FUNCS'][$lower])) {
                php56cc_flag($findings, $file, $line, $GLOBALS['FUNCS'][$lower], 'function ' . $t[1] . '()');
            }
            foreach ($GLOBALS['CLASSES'] as $cn => $cv) {
                if (strcasecmp($t[1], $cn) === 0 && !$prevIsArrowOrDecl) {
                    php56cc_flag($findings, $file, $line, $cv, 'class/interface ' . $cn);
                }
            }
            if ($next !== '(' && !$prevIsArrowOrDecl && isset($GLOBALS['CONSTS'][$t[1]])) {
                php56cc_flag($findings, $file, $line, $GLOBALS['CONSTS'][$t[1]], 'constant ' . $t[1]);
            }
        }
    }
}

echo "PHP 5.6 static compatibility audit\n";
echo "auditor running on PHP " . PHP_VERSION . "\n";
echo "files scanned: " . count($files) . "\n\n";

if (!$findings) {
    echo "RESULT: no post-PHP-5.6 syntax, function, class or constant usage found.\n";
    exit(0);
}

echo "RESULT: " . count($findings) . " potential incompatibilities\n\n";
foreach ($findings as $f) {
    printf("  %-50s :%-5d requires PHP %-5s  %s\n", $f['file'], $f['line'], $f['ver'], $f['what']);
}
exit(1);
