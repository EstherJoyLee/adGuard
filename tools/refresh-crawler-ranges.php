<?php
/**
 * Refresh the locally cached known-crawler IP ranges.
 *
 * Run from cron (daily or weekly is plenty -- these ranges change slowly):
 *
 *     php adguard/tools/refresh-crawler-ranges.php
 *
 * Options:
 *     --out=<path>     where to write the cache (default: storage/crawler-ranges.php)
 *     --dry-run        fetch and validate, print a summary, write nothing
 *     --offline=<dir>  read the vendor JSON files from a local directory
 *                      instead of the network (for air-gapped installs and tests)
 *
 * WHY A CLI AND NOT A RUNTIME FETCH
 * ---------------------------------
 * A page request must never wait on developers.google.com. The request path
 * only ever reads the file this script produces; if the file is missing or
 * stale, crawler verification reports "unavailable" and nothing else changes.
 *
 * WHAT THIS GUARDS AGAINST
 * ------------------------
 * The documented JSON URLs REDIRECT. Following redirects is required, and a
 * redirect that lands on an HTML error page will happily "download" as a
 * 200. Every response is therefore parsed and shape-checked before it is
 * allowed to replace a working cache. A failed refresh leaves the previous
 * cache untouched.
 */

require_once dirname(__DIR__) . '/engine/src/KnownCrawlers/IpRangeSet.php';

$SOURCES = array(
    // vendor => array(group => url)
    'google' => array(
        'special' => 'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
        'common'  => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
        'fetchers' => 'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers.json',
    ),
);

$options = array('out' => '', 'dry-run' => false, 'offline' => '');
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry-run'] = true;
    } elseif (strpos($arg, '--out=') === 0) {
        $options['out'] = substr($arg, 6);
    } elseif (strpos($arg, '--offline=') === 0) {
        $options['offline'] = substr($arg, 10);
    } else {
        fwrite(STDERR, "unknown option: $arg\n");
        exit(2);
    }
}

$outPath = $options['out'] !== ''
    ? $options['out']
    : dirname(__DIR__) . '/storage/crawler-ranges.php';

function rcr_fetch($url, $timeout = 30)
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        // The documented URLs redirect; without this we would store the
        // redirect stub and silently lose every prefix.
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, (int)$timeout);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        /*
         * curl_close() is required on PHP 5.6 to release the handle, but has
         * been a no-op since PHP 8.0 and emits a deprecation notice from 8.5.
         * Calling it only where it does something keeps cron output clean on
         * modern PHP without leaking handles on the oldest supported version.
         */
        if (PHP_VERSION_ID < 80000) {
            curl_close($curl);
        }
        if ($body === false) {
            return array(null, 'curl error: ' . $error);
        }
        if ($status !== 200) {
            return array(null, 'HTTP ' . $status);
        }
        return array($body, '');
    }

    $context = stream_context_create(array('http' => array(
        'timeout' => (int)$timeout,
        'follow_location' => 1,
        'max_redirects' => 5,
    )));
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return array(null, 'file_get_contents failed');
    }
    return array($body, '');
}

/**
 * Accept only a real vendor range document. An HTML error page, a redirect
 * stub, or a JSON file with no usable prefixes must all be rejected so they
 * cannot overwrite a good cache.
 */
function rcr_parse($body)
{
    $body = (string)$body;
    $trimmed = ltrim($body);
    if ($trimmed === '' || $trimmed[0] !== '{') {
        return array(null, 'response is not a JSON object (redirect stub or HTML?)');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return array(null, 'JSON parse failed');
    }
    if (!isset($data['prefixes']) || !is_array($data['prefixes'])) {
        return array(null, 'no "prefixes" array');
    }
    $cidrs = array();
    foreach ($data['prefixes'] as $prefix) {
        if (!is_array($prefix)) {
            continue;
        }
        // Both keys appear in the published files; IPv6 must not be dropped.
        foreach (array('ipv4Prefix', 'ipv6Prefix') as $key) {
            if (isset($prefix[$key]) && is_string($prefix[$key]) && $prefix[$key] !== '') {
                $cidrs[] = $prefix[$key];
            }
        }
    }
    if (!$cidrs) {
        return array(null, 'zero usable prefixes');
    }
    return array(array(
        'creationTime' => isset($data['creationTime']) ? (string)$data['creationTime'] : '',
        'cidrs' => $cidrs,
    ), '');
}

echo "Refreshing known-crawler ranges\n";
echo "  output: " . $outPath . "\n";
if ($options['offline'] !== '') {
    echo "  source: offline directory " . $options['offline'] . "\n";
}
echo "\n";

$vendors = array();
$sourceMeta = array();
$failed = 0;
$validator = new \RiskEngine\KnownCrawlers\IpRangeSet();

foreach ($SOURCES as $vendor => $groups) {
    $vendors[$vendor] = array();
    foreach ($groups as $group => $url) {
        if ($options['offline'] !== '') {
            $file = rtrim($options['offline'], '/\\') . '/' . $group . '.json';
            $body = is_file($file) ? file_get_contents($file) : false;
            $error = $body === false ? 'missing ' . $file : '';
        } else {
            list($body, $error) = rcr_fetch($url);
        }

        if ($error !== '' || $body === false || $body === null) {
            printf("  %-8s %-10s FAILED  %s\n", $vendor, $group, $error);
            $failed++;
            continue;
        }

        list($parsed, $parseError) = rcr_parse($body);
        if ($parsed === null) {
            printf("  %-8s %-10s REJECTED  %s\n", $vendor, $group, $parseError);
            $failed++;
            continue;
        }

        $accepted = 0;
        $skipped = 0;
        foreach ($parsed['cidrs'] as $cidr) {
            // Validate through the same matcher the runtime uses, so an entry
            // that would silently never match is caught here instead.
            if ($validator->add($cidr, $group)) {
                $vendors[$vendor][] = array($cidr, $group);
                $accepted++;
            } else {
                $skipped++;
            }
        }
        $sourceMeta[$vendor . '/' . $group] = array(
            'creationTime' => $parsed['creationTime'],
            'accepted' => $accepted,
            'skipped' => $skipped,
        );
        printf(
            "  %-8s %-10s OK  %4d prefixes%s  (creationTime %s)\n",
            $vendor,
            $group,
            $accepted,
            $skipped > 0 ? sprintf(', %d unparseable', $skipped) : '',
            $parsed['creationTime'] !== '' ? $parsed['creationTime'] : 'n/a'
        );
    }
}

$total = 0;
foreach ($vendors as $entries) {
    $total += count($entries);
}

echo "\n";
if ($total === 0) {
    fwrite(STDERR, "No usable prefixes were obtained. Existing cache left untouched.\n");
    exit(1);
}
if ($failed > 0) {
    // Partial data would silently narrow verification and turn verified
    // crawlers into "claimed_unverified", which is worse than reporting
    // "unavailable". Refuse the write.
    fwrite(STDERR, "One or more sources failed (" . $failed . "). Refusing to write a partial cache;\n");
    fwrite(STDERR, "the existing cache is left untouched. Re-run once connectivity is restored.\n");
    exit(1);
}

echo "Total prefixes: " . $total . "\n";
if ($options['dry-run']) {
    echo "--dry-run: nothing written.\n";
    exit(0);
}

$payload = array(
    'generated_at' => time(),
    'sources' => $sourceMeta,
    'vendors' => $vendors,
);

$dir = dirname($outPath);
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
    fwrite(STDERR, "cannot create directory: " . $dir . "\n");
    exit(1);
}

$php = "<?php\n"
    . "/* Generated by adguard/tools/refresh-crawler-ranges.php -- do not edit. */\n"
    . "return " . var_export($payload, true) . ";\n";

// Atomic replace: a reader must never see a half-written file.
$tmp = $outPath . '.tmp' . getmypid();
if (@file_put_contents($tmp, $php) === false) {
    fwrite(STDERR, "cannot write: " . $tmp . "\n");
    exit(1);
}
if (!@rename($tmp, $outPath)) {
    @unlink($tmp);
    fwrite(STDERR, "cannot replace: " . $outPath . "\n");
    exit(1);
}
@chmod($outPath, 0640);

echo "Wrote " . $outPath . "\n";
echo "Re-run periodically (cron). A stale cache reports verification as unavailable,\n";
echo "which is neutral -- it never turns a crawler into a risk.\n";
exit(0);
