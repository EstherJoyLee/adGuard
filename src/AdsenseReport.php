<?php
namespace AdGuard;

/** Normalize AdSense API JSON or exported CSV into one local snapshot format. */
class AdsenseReport
{
    const SCHEMA = 'adguard-adsense-report-v1';

    public static function loadFile($path)
    {
        $path = (string)$path;
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Cannot read AdSense report: ' . $path);
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException('Cannot load AdSense report: ' . $path);
        }
        $trimmed = ltrim($raw);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON AdSense report: ' . $path);
            }
            return self::fromDecoded($decoded, basename($path));
        }
        return self::fromCsvString($raw, basename($path));
    }

    public static function fromDecoded($decoded, $source)
    {
        if (isset($decoded['schema']) && $decoded['schema'] === self::SCHEMA) {
            $rows = isset($decoded['rows']) && is_array($decoded['rows']) ? $decoded['rows'] : array();
            return self::snapshot(self::normalizeRows($rows), $source, isset($decoded['metadata']) ? $decoded['metadata'] : array());
        }

        if (isset($decoded['headers']) && isset($decoded['rows']) && is_array($decoded['headers'])) {
            $headers = array();
            foreach ($decoded['headers'] as $header) {
                $headers[] = is_array($header) && isset($header['name']) ? (string)$header['name'] : '';
            }
            $rows = array();
            foreach ((array)$decoded['rows'] as $row) {
                $values = array();
                foreach ((array)(isset($row['cells']) ? $row['cells'] : array()) as $cell) {
                    $values[] = is_array($cell) && isset($cell['value']) ? $cell['value'] : '';
                }
                $rows[] = self::associate($headers, $values);
            }
            $metadata = array(
                'format' => 'adsense-api-v2',
                'total_matched_rows' => isset($decoded['totalMatchedRows']) ? (int)$decoded['totalMatchedRows'] : count($rows),
                'warnings' => isset($decoded['warnings']) ? (array)$decoded['warnings'] : array(),
            );
            return self::snapshot(self::normalizeRows($rows), $source, $metadata);
        }

        if (isset($decoded[0]) && is_array($decoded[0])) {
            return self::snapshot(self::normalizeRows($decoded), $source, array('format' => 'json-rows'));
        }
        throw new \RuntimeException('Unsupported AdSense JSON structure.');
    }

    public static function fromCsvString($raw, $source)
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot allocate CSV parser.');
        }
        fwrite($handle, (string)$raw);
        rewind($handle);
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($headers)) {
            fclose($handle);
            throw new \RuntimeException('AdSense CSV has no header row.');
        }
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        }
        $rows = array();
        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($values) === 1 && trim((string)$values[0]) === '') {
                continue;
            }
            $rows[] = self::associate($headers, $values);
        }
        fclose($handle);
        return self::snapshot(self::normalizeRows($rows), $source, array('format' => 'csv'));
    }

    public static function saveSnapshot($snapshot, $directory)
    {
        if (!is_array($snapshot) || !isset($snapshot['rows'])) {
            throw new \InvalidArgumentException('Invalid normalized AdSense snapshot.');
        }
        $directory = rtrim((string)$directory, '/\\');
        if (!self::provisionDirectory($directory)) {
            throw new \RuntimeException('Cannot provision AdSense report directory: ' . $directory);
        }
        $dates = array();
        foreach ((array)$snapshot['rows'] as $row) {
            if (!empty($row['date'])) {
                $dates[(string)$row['date']] = true;
            }
        }
        $dateList = array_keys($dates);
        sort($dateList, SORT_STRING);
        $start = $dateList ? reset($dateList) : 'unknown';
        $end = $dateList ? end($dateList) : 'unknown';
        $encoded = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new \RuntimeException('Cannot encode normalized AdSense snapshot.');
        }
        $hash = substr(hash('sha256', $encoded), 0, 12);
        $path = $directory . '/adsense-' . $start . '-to-' . $end . '-' . $hash . '.json';
        if (@file_put_contents($path, $encoded . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Cannot save normalized AdSense snapshot: ' . $path);
        }
        @chmod($path, 0600);
        return $path;
    }

    public static function findLatest($directory)
    {
        $files = glob(rtrim((string)$directory, '/\\') . '/adsense-*.json');
        if (!$files) {
            return '';
        }
        usort($files, function ($a, $b) {
            $ma = @filemtime($a);
            $mb = @filemtime($b);
            return $ma === $mb ? strcmp($b, $a) : ($ma < $mb ? 1 : -1);
        });
        return (string)$files[0];
    }

    private static function snapshot($rows, $source, $metadata)
    {
        return array(
            'schema' => self::SCHEMA,
            'imported_at' => gmdate('c'),
            'source' => substr(str_replace(array("\r", "\n", "\0"), ' ', (string)$source), 0, 255),
            'metadata' => is_array($metadata) ? $metadata : array(),
            'rows' => array_values($rows),
        );
    }

    private static function normalizeRows($rows)
    {
        $normalized = array();
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $canonical = array();
            foreach ($row as $key => $value) {
                $canonical[self::canonicalHeader($key)] = $value;
            }
            $date = trim((string)self::value($canonical, array('DATE')));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
                continue;
            }
            $pageUrl = trim((string)self::value($canonical, array('PAGE_URL', 'URL')));
            $domain = trim((string)self::value($canonical, array('OWNED_SITE_DOMAIN_NAME', 'DOMAIN_NAME', 'DOMAIN_CODE', 'DOMAIN')));
            if ($domain === '' && $pageUrl !== '') {
                $parsedHost = parse_url($pageUrl, PHP_URL_HOST);
                $domain = is_string($parsedHost) ? $parsedHost : '';
            }
            $normalized[] = array(
                'date' => $date,
                'page_url' => substr($pageUrl, 0, 2048),
                'domain' => substr(strtolower($domain), 0, 253),
                'url_channel' => substr((string)self::value($canonical, array('URL_CHANNEL_NAME', 'URL_CHANNEL_ID')), 0, 255),
                'custom_channel' => substr((string)self::value($canonical, array('CUSTOM_CHANNEL_NAME', 'CUSTOM_CHANNEL_ID')), 0, 255),
                'ad_unit' => substr((string)self::value($canonical, array('AD_UNIT_NAME', 'AD_UNIT_ID')), 0, 255),
                'country' => substr((string)self::value($canonical, array('COUNTRY_CODE', 'COUNTRY_NAME')), 0, 64),
                'clicks' => (int)round(self::number(self::value($canonical, array('CLICKS')))),
                'impressions' => (int)round(self::number(self::value($canonical, array('IMPRESSIONS', 'TOTAL_IMPRESSIONS')))),
                'page_views' => (int)round(self::number(self::value($canonical, array('PAGE_VIEWS')))),
                'estimated_earnings' => self::number(self::value($canonical, array('ESTIMATED_EARNINGS'))),
            );
        }
        if (!$normalized) {
            throw new \RuntimeException('No usable rows. Require DATE plus AdSense metrics; PAGE_URL is strongly recommended.');
        }
        return $normalized;
    }

    private static function associate($headers, $values)
    {
        $row = array();
        foreach ((array)$headers as $index => $header) {
            $row[(string)$header] = isset($values[$index]) ? $values[$index] : '';
        }
        return $row;
    }

    private static function canonicalHeader($header)
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', trim((string)$header));
        $aliases = array(
            '날짜' => 'DATE', '페이지 URL' => 'PAGE_URL', '도메인' => 'DOMAIN_NAME',
            '클릭' => 'CLICKS', '클릭수' => 'CLICKS', '노출' => 'IMPRESSIONS', '노출수' => 'IMPRESSIONS',
            '페이지뷰' => 'PAGE_VIEWS', '예상 수입' => 'ESTIMATED_EARNINGS',
        );
        if (isset($aliases[$header])) {
            return $aliases[$header];
        }
        $header = strtoupper($header);
        $header = preg_replace('/[^A-Z0-9]+/', '_', $header);
        return trim($header, '_');
    }

    private static function value($row, $keys)
    {
        foreach ((array)$keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return '';
    }

    private static function number($value)
    {
        $value = str_replace(array(',', ' '), '', trim((string)$value));
        if ($value === '') {
            return 0.0;
        }
        $value = preg_replace('/[^0-9eE+\-.]/', '', $value);
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private static function provisionDirectory($path)
    {
        if ($path === '' || $path === '.') {
            return false;
        }
        if (!is_dir($path) && !@mkdir($path, 0700, true)) {
            return false;
        }
        @chmod($path, 0700);
        $htaccess = $path . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        $index = $path . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
        return true;
    }
}
