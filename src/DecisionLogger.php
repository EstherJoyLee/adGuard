<?php
namespace AdGuard;

require_once __DIR__ . '/AnalyticsContext.php';
require_once __DIR__ . '/../engine/src/Identity/IpResolver.php';

/** Bounded, structured JSONL audit log for ad eligibility decisions. */
class DecisionLogger
{
    private $config;
    private $hmacKey = null;
    private $pruned = false;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function log($decision, $meta)
    {
        if (!$this->config->get('logging.enabled', true)) {
            return false;
        }

        $allowed = !empty($decision['ads_allowed']);
        $sampleRate = (float)$this->config->get(
            $allowed ? 'logging.allow_sample_rate' : 'logging.deny_sample_rate',
            1.0
        );
        $sampleRate = max(0.0, min(1.0, $sampleRate));
        if ($sampleRate <= 0.0 || ($sampleRate < 1.0 && $this->randomUnit() > $sampleRate)) {
            return false;
        }

        $path = rtrim((string)$this->config->get('logging.path'), '/\\');
        if ($path === '' || !$this->provisionDirectory($path)) {
            return false;
        }
        $this->pruneLogs($path);

        $file = $path . '/ad-guard-' . gmdate('Y-m-d') . '.jsonl';
        $maxBytes = max(1024, (int)$this->config->get('logging.max_daily_bytes', 20971520));
        if (is_file($file) && @filesize($file) >= $maxBytes) {
            $this->healthEvent($path, 'daily_byte_cap');
            return false;
        }

        $record = $this->buildRecord($decision, $meta, $sampleRate);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($line === false || strlen($line) > 8192) {
            $this->healthEvent($path, $line === false ? 'json_encode_failed' : 'record_too_large');
            return false;
        }

        $handle = @fopen($file, 'ab');
        if ($handle === false) {
            $this->healthEvent($path, 'open_failed');
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $this->healthEvent($path, 'lock_busy');
            return false;
        }
        $ok = fwrite($handle, $line . "\n") !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if (!$ok) {
            $this->healthEvent($path, 'write_failed');
        }
        @chmod($file, 0600);
        return $ok;
    }

    private function buildRecord($decision, $meta, $sampleRate)
    {
        $meta = is_array($meta) ? $meta : array();
        $signals = array();
        $rawSignals = isset($decision['signals']) && is_array($decision['signals'])
            ? $decision['signals']
            : array();
        foreach ($rawSignals as $name => $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $signals[$this->clean($name, 64)] = array(
                'score' => isset($signal['score']) ? (int)$signal['score'] : 0,
                'triggered' => !empty($signal['triggered']),
                'storage_degraded' => !empty($signal['metrics']['storage_degraded']),
            );
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $requestPath = parse_url($uri, PHP_URL_PATH);
        if (!is_string($requestPath)) {
            $requestPath = '';
        }
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '';
        $refererHost = $referer !== '' ? parse_url($referer, PHP_URL_HOST) : '';
        if (!is_string($refererHost)) {
            $refererHost = '';
        }

        $reasons = array();
        if (isset($decision['reasons']) && is_array($decision['reasons'])) {
            foreach (array_slice($decision['reasons'], 0, 8) as $reason) {
                $reasons[] = $this->clean($reason, 256);
            }
        }

        $identity = \RiskEngine\Identity\IpResolver::resolve(
            $_SERVER,
            (array)$this->config->get('identity', array())
        );
        $ip = isset($identity['raw_ip']) ? (string)$identity['raw_ip'] : '';
        $canonicalIp = isset($identity['canonical_ip']) ? (string)$identity['canonical_ip'] : '';
        $visitorCookie = (string)$this->config->get('logging.visitor_cookie_name', '__rek_id');
        if ($visitorCookie === '') {
            $visitorCookie = '__rek_id';
        }
        $visitor = isset($_COOKIE[$visitorCookie]) ? (string)$_COOKIE[$visitorCookie] : '';
        $analytics = new AnalyticsContext($this->config);
        $host = $analytics->currentHost($_SERVER);
        $siteId = $analytics->siteId($_SERVER);
        $routeGroup = $analytics->routeGroup(
            $requestPath,
            isset($meta['route_group']) ? $meta['route_group'] : ''
        );
        $referrerGroup = isset($meta['traffic_source_group']) && $meta['traffic_source_group'] !== ''
            ? $this->clean($meta['traffic_source_group'], 80)
            : $analytics->referrerGroup($refererHost);
        $networkPrefix = AnalyticsContext::networkPrefix($canonicalIp);
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
        if (!$this->config->get('logging.store_user_agent', true)) {
            $userAgent = '';
        }
        $userAgentSignal = isset($rawSignals['user_agent']) && is_array($rawSignals['user_agent'])
            ? $rawSignals['user_agent'] : array();
        $crawlerStatus = isset($userAgentSignal['metrics']['crawler_status'])
            ? (string)$userAgentSignal['metrics']['crawler_status'] : 'verification_unknown';
        $crawlerVendor = isset($userAgentSignal['metrics']['crawler_vendor'])
            ? (string)$userAgentSignal['metrics']['crawler_vendor'] : '';
        $crawlerGroup = isset($userAgentSignal['metrics']['crawler_group'])
            ? (string)$userAgentSignal['metrics']['crawler_group'] : '';
        $requestType = isset($meta['request_type']) && in_array($meta['request_type'], array('document', 'api', 'static', 'other'), true)
            ? (string)$meta['request_type']
            : $this->requestType($requestPath);
        $adOpportunity = !empty($meta['ad_opportunity']) || !empty($meta['adsense_detected']);

        return array(
            'schema_version' => 3,
            'timestamp' => gmdate('c'),
            'request_id' => $this->randomToken(12),
            'site_id' => $this->clean($siteId, 64),
            'host' => $this->clean($host, 253),
            'path' => $this->clean($requestPath, 512),
            'route_group' => $this->clean($routeGroup, 80),
            'redirect_rule_id' => $this->clean(
                isset($meta['redirect_rule_id']) ? $meta['redirect_rule_id'] : '',
                80
            ),
            'method' => $this->clean(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '', 12),
            'referrer_host' => $this->clean($refererHost, 255),
            'referrer_group' => $this->clean($referrerGroup, 80),
            'country' => $this->clean(isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? $_SERVER['HTTP_CF_IPCOUNTRY'] : '', 8),
            'cf_ray' => $this->clean(isset($_SERVER['HTTP_CF_RAY']) ? $_SERVER['HTTP_CF_RAY'] : '', 64),
            'raw_ip' => $this->clean($ip, 128),
            'ip_canonical' => $this->clean($canonicalIp, 128),
            'ip_source' => $this->clean(isset($identity['source']) ? $identity['source'] : 'unresolved', 32),
            'ip_resolution_status' => $this->clean(isset($identity['status']) ? $identity['status'] : 'missing_peer', 48),
            // Correlation uses the canonical address so IPv4 and
            // IPv4-mapped IPv6 cannot silently split one client. The raw
            // selected token remains available in raw_ip for authorized
            // audit work.
            'ip_hmac' => $this->hashIdentifier($canonicalIp !== '' ? $canonicalIp : $ip),
            // Weaker correlation for IP rotation. Never expose the raw /24 or
            // /64, and never treat a shared network as proof of one attacker.
            'network_hmac' => $this->hashIdentifier($networkPrefix),
            'visitor_hmac' => $this->hashIdentifier($visitor),
            'user_agent' => $this->clean($userAgent, 1024),
            'ua_family' => $this->userAgentFamily($userAgent),
            'crawler_status' => $this->clean($crawlerStatus, 40),
            'crawler_vendor' => $this->clean($crawlerVendor, 40),
            'crawler_group' => $this->clean($crawlerGroup, 40),
            'request_type' => $requestType,
            'is_document' => $requestType === 'document',
            'ad_opportunity' => $adOpportunity,
            'engine_level' => $this->clean(isset($decision['engine_level']) ? $decision['engine_level'] : '', 24),
            'score' => isset($decision['score']) ? (int)$decision['score'] : 0,
            'action' => $this->clean(isset($decision['action']) ? $decision['action'] : '', 24),
            'policy_reason' => $this->clean(isset($decision['policy_reason']) ? $decision['policy_reason'] : '', 128),
            'ads_allowed' => !empty($decision['ads_allowed']),
            'degraded' => !empty($decision['degraded']),
            'adsense_detected' => !empty($meta['adsense_detected']),
            'bootstrap_removed' => isset($meta['bootstrap_removed']) ? (int)$meta['bootstrap_removed'] : 0,
            // What the visitor actually received, which can differ from this
            // package's own verdict when the integration suppressed ads for
            // its own reason (e.g. a preview override).
            'ads_served' => !empty($meta['ads_served']),
            'external_suppression' => $this->clean(
                isset($meta['external_suppression']) ? $meta['external_suppression'] : '',
                64
            ),
            'ad_delivery' => $this->normalizeAdDelivery(
                isset($meta['ad_delivery']) ? $meta['ad_delivery'] : array()
            ),
            'sample_rate' => $sampleRate,
            'reasons' => $reasons,
            'signals' => $signals,
        );
    }

    private function hashIdentifier($value)
    {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        $key = $this->getHmacKey();
        return $key === '' ? '' : hash_hmac('sha256', $value, $key);
    }

    private function getHmacKey()
    {
        if ($this->hmacKey !== null) {
            return $this->hmacKey;
        }

        $configured = (string)$this->config->get('logging.hmac_key', '');
        if ($configured !== '') {
            $this->hmacKey = $configured;
            return $this->hmacKey;
        }

        $keyPath = (string)$this->config->get('logging.hmac_key_path', '');
        $keyDir = dirname($keyPath);
        if ($keyPath === '' || !$this->provisionDirectory($keyDir)) {
            $this->hmacKey = '';
            return $this->hmacKey;
        }

        if (is_file($keyPath)) {
            $existing = @file_get_contents($keyPath);
            if (is_string($existing) && trim($existing) !== '') {
                $this->hmacKey = trim($existing);
                return $this->hmacKey;
            }
        }

        $key = $this->randomToken(32);
        $handle = @fopen($keyPath, 'x');
        if ($handle !== false) {
            fwrite($handle, $key);
            fclose($handle);
            @chmod($keyPath, 0600);
            $this->hmacKey = $key;
            return $this->hmacKey;
        }

        // Another request may have won the creation race.
        $existing = @file_get_contents($keyPath);
        $this->hmacKey = is_string($existing) ? trim($existing) : '';
        return $this->hmacKey;
    }

    private function provisionDirectory($path)
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
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
            );
        }
        $index = $path . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
        // IIS does not read Apache's .htaccess. Keep a native deny rule in
        // every runtime directory as a second server-family guard; operators
        // should still place logs outside the document root where possible.
        $webConfig = $path . '/web.config';
        if (!is_file($webConfig)) {
            @file_put_contents(
                $webConfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration><system.webServer><security><authorization>"
                . "<remove users=\"*\" roles=\"\" verbs=\"\" />"
                . "<add accessType=\"Deny\" users=\"*\" />"
                . "</authorization></security></system.webServer></configuration>\n"
            );
        }
        return true;
    }

    /** Best-effort retention; never turns an otherwise valid request into a failure. */
    private function pruneLogs($path)
    {
        if ($this->pruned) {
            return;
        }
        $this->pruned = true;
        $days = (int)$this->config->get('logging.retention_days', 90);
        if ($days <= 0) {
            return;
        }
        $cutoff = time() - ($days * 86400);
        foreach ((array)glob(rtrim($path, '/\\') . '/ad-guard-*.jsonl') as $file) {
            if (!preg_match('~ad-guard-(\d{4})-(\d{2})-(\d{2})(?:-health)?\.jsonl$~', basename($file), $m)) {
                continue;
            }
            $stamp = @gmmktime(23, 59, 59, (int)$m[2], (int)$m[3], (int)$m[1]);
            if ($stamp !== false && $stamp < $cutoff) {
                @unlink($file);
            }
        }
    }

    /** Append only exceptional logger outcomes; normal records stay one line. */
    private function healthEvent($path, $reason)
    {
        $file = rtrim($path, '/\\') . '/ad-guard-' . gmdate('Y-m-d') . '-health.jsonl';
        $max = max(8192, (int)$this->config->get('logging.health_max_daily_bytes', 1048576));
        if (is_file($file) && @filesize($file) >= $max) {
            return;
        }
        $event = json_encode(array(
            'schema_version' => 1,
            'timestamp' => gmdate('c'),
            'event' => 'logger_health',
            'reason' => $this->clean($reason, 64),
        ), JSON_UNESCAPED_SLASHES);
        if ($event !== false) {
            @file_put_contents($file, $event . "\n", FILE_APPEND | LOCK_EX);
            @chmod($file, 0600);
        }
    }

    private function randomToken($bytes)
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $raw = openssl_random_pseudo_bytes((int)$bytes, $strong);
            if ($raw !== false && $strong === true) {
                return bin2hex($raw);
            }
        }
        return hash('sha256', uniqid('', true) . ':' . mt_rand() . ':' . microtime(true));
    }

    private function randomUnit()
    {
        return mt_rand() / mt_getrandmax();
    }

    private function clean($value, $maxLength)
    {
        $value = str_replace(array("\r", "\n", "\0"), ' ', (string)$value);
        if (@preg_match('//u', $value) !== 1 && function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        return substr($value, 0, (int)$maxLength);
    }

    private function requestType($path)
    {
        $path = strtolower((string)$path);
        if (preg_match('~/(?:api)(?:/|$)|\.json$~', $path)) {
            return 'api';
        }
        if (preg_match('~\.(?:css|js|mjs|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|map|mp4|webm|pdf|zip)$~', $path)) {
            return 'static';
        }
        return 'document';
    }

    private function normalizeAdDelivery($value)
    {
        $value = is_array($value) ? $value : array();
        $bootstrap = isset($value['bootstrap']) && is_array($value['bootstrap'])
            ? $value['bootstrap'] : array();
        $out = array(
            'bootstrap' => array(
                'opportunities' => $this->boundedCount(isset($bootstrap['opportunities']) ? $bootstrap['opportunities'] : 0, 100),
                'provided' => $this->boundedCount(isset($bootstrap['provided']) ? $bootstrap['provided'] : 0, 100),
                'blocked' => $this->boundedCount(isset($bootstrap['blocked']) ? $bootstrap['blocked'] : 0, 100),
                'missing' => $this->boundedCount(isset($bootstrap['missing']) ? $bootstrap['missing'] : 0, 100),
            ),
            'manual_unit_count' => $this->boundedCount(
                isset($value['manual_unit_count']) ? $value['manual_unit_count'] : 0,
                1000
            ),
            'manual_units' => array(),
            'truncated' => !empty($value['truncated']),
        );

        foreach (array_slice((array)(isset($value['manual_units']) ? $value['manual_units'] : array()), 0, 24) as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $status = isset($unit['status']) ? (string)$unit['status'] : 'missing';
            if (!in_array($status, array('provided', 'blocked', 'missing'), true)) {
                $status = 'missing';
            }
            $out['manual_units'][] = array(
                'slot' => $this->clean(isset($unit['slot']) ? $unit['slot'] : 'unlabeled', 80),
                'format' => $this->clean(isset($unit['format']) ? $unit['format'] : 'default', 40),
                'ordinal' => max(1, min(100, isset($unit['ordinal']) ? (int)$unit['ordinal'] : 1)),
                'status' => $status,
            );
        }
        return $out;
    }

    private function boundedCount($value, $maximum)
    {
        return max(0, min((int)$maximum, (int)$value));
    }

    private function userAgentFamily($ua)
    {
        $ua = strtolower((string)$ua);
        $families = array(
            'googlebot' => 'googlebot', 'bingbot' => 'bingbot', 'headlesschrome' => 'headless',
            'curl' => 'curl', 'wget' => 'wget', 'python' => 'python', 'okhttp' => 'okhttp',
            'edg/' => 'edge', 'chrome/' => 'chrome', 'firefox/' => 'firefox',
            'safari/' => 'safari',
        );
        foreach ($families as $needle => $name) {
            if (strpos($ua, $needle) !== false) {
                return $name;
            }
        }
        return $ua === '' ? 'missing' : 'other';
    }
}
