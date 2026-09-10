<?php
/**
 * Boundary check: risk-engine/ must have ZERO knowledge of what any
 * particular integrating site is protecting (ads, login forms, whatever)
 * and must never reach outside its own folder for code. This is what makes
 * "copy this folder into any project" actually true instead of aspirational.
 *
 * Scans risk-engine.php, src/, and config/ -- NOT tests/, since test files
 * may legitimately need to describe scenarios that mention such words.
 * Fails (non-zero exit) if it finds:
 *   1. Any of a list of site-specific/ad-specific substrings (case-insensitive)
 *   2. A require/include whose literal-string target resolves outside risk-engine/
 *
 * Usage:
 *   php risk-engine/tests/boundary-check.php            # scans the real module
 *   php risk-engine/tests/boundary-check.php /some/path # scans an arbitrary
 *                                                          root instead (used
 *                                                          by this checker's
 *                                                          own self-test)
 */

$root = isset($argv[1]) ? rtrim($argv[1], '/\\') : dirname(__DIR__);

$scanTargets = array(
    $root . '/risk-engine.php',
    $root . '/src',
    $root . '/config',
);

/*
 * Terms the engine must never contain.
 *
 * The first group is universal to this package's design boundary: engine/ is
 * a generic request-risk scorer and must stay ignorant of advertising.
 *
 * The second group is the HOST PROJECT's own name. It is intentionally empty
 * in the template -- add the names your project uses (its directory name,
 * product name, any internal codename) so this check also catches the engine
 * accidentally learning about the site it happens to be installed in.
 * Example: array('acmeshop', 'acme_shop').
 */
$forbiddenSubstrings = array_merge(
    array(
        'adsense', 'adsbygoogle', 'googlesyndication', 'doubleclick',
        'ad-defense', 'ad_defense', 'ad-preview', 'ad_preview',
    ),
    // >>> Add this project's own identifiers here. <<<
    array()
);

function rek_boundary_collect_files($path)
{
    $files = array();
    if (is_file($path)) {
        if (substr($path, -4) === '.php') {
            $files[] = $path;
        }
        return $files;
    }
    if (!is_dir($path)) {
        return $files;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (substr($f->getFilename(), -4) === '.php') {
            $files[] = $f->getPathname();
        }
    }
    return $files;
}

$files = array();
foreach ($scanTargets as $target) {
    if (file_exists($target)) {
        $files = array_merge($files, rek_boundary_collect_files($target));
    }
}
sort($files);

$rootReal = realpath($root);
$violations = array();

foreach ($files as $file) {
    $src = file_get_contents($file);
    $lower = strtolower($src);

    foreach ($forbiddenSubstrings as $needle) {
        if (strpos($lower, $needle) !== false) {
            $violations[] = $file . ': contains forbidden substring "' . $needle . '"';
        }
    }

    // Tokenized (not regex-over-raw-source) so a require/include mentioned
    // inside a comment or string literal elsewhere never gets mistaken for
    // real code -- only actual T_REQUIRE*/T_INCLUDE* tokens are inspected.
    $toks = token_get_all($src);
    $tn = count($toks);
    $requireTokens = array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE);
    for ($ti = 0; $ti < $tn; $ti++) {
        if (!is_array($toks[$ti]) || !in_array($toks[$ti][0], $requireTokens, true)) {
            continue;
        }
        // Scan forward to the statement-ending ';', collecting the last
        // string literal on the line (the actual path, after any
        // `__DIR__ .` concatenation).
        $literal = null;
        for ($tj = $ti + 1; $tj < $tn; $tj++) {
            $tok = $toks[$tj];
            if ($tok === ';') {
                break;
            }
            if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = trim($tok[1], "'\"");
            }
        }
        if ($literal === null || $literal === '') {
            continue;
        }
        $resolved = realpath(dirname($file) . '/' . $literal);
        if ($resolved === false) {
            $violations[] = $file . ': require/include target "' . $literal . '" could not be resolved for boundary checking (verify manually)';
            continue;
        }
        if ($rootReal === false || strpos($resolved, $rootReal) !== 0) {
            $violations[] = $file . ': require/include target "' . $literal . '" resolves outside risk-engine/ (' . $resolved . ')';
        }
    }
}

if ($violations) {
    fwrite(STDERR, "BOUNDARY CHECK FAILED (" . count($violations) . " issue(s)):\n");
    foreach ($violations as $v) {
        fwrite(STDERR, "  - $v\n");
    }
    exit(1);
}

echo "BOUNDARY CHECK PASSED -- " . count($files) . " file(s) scanned under risk-engine.php/src/config, zero ad-domain or cross-boundary references.\n";
exit(0);
