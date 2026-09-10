<?php
namespace AdGuard;

require_once __DIR__ . '/AnalyticsContext.php';

/**
 * Compares daily aggregate AdSense metrics with local ad-bearing risk logs.
 *
 * This produces candidate activity clusters, never click-to-IP attribution.
 */
class RiskCorrelationAnalyzer
{
    private $config;
    private $context;
    private $timezone;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->context = new AnalyticsContext($config);
        $zone = (string)$config->get('analytics.reporting_timezone', 'UTC');
        try {
            $this->timezone = new \DateTimeZone($zone);
        } catch (\Exception $e) {
            $this->timezone = new \DateTimeZone('UTC');
        }
    }

    public function analyze($targetDate, $snapshot, $logDirectory)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)$targetDate)) {
            throw new \InvalidArgumentException('Invalid target date.');
        }
        if (!is_array($snapshot) || !isset($snapshot['rows']) || !is_array($snapshot['rows'])) {
            throw new \InvalidArgumentException('Invalid AdSense snapshot.');
        }

        $baselineDays = max(3, min(90, (int)$this->config->get('analytics.baseline_days', 14)));
        $startDate = $this->shiftDate($targetDate, -$baselineDays);
        $adsense = $this->aggregateAdsense($snapshot['rows'], $startDate, $targetDate);
        $local = $this->aggregateLocal($logDirectory, $startDate, $targetDate);

        $targetKeys = array();
        if (isset($adsense['days'][$targetDate])) {
            foreach ($adsense['days'][$targetDate] as $key => $value) {
                $targetKeys[$key] = true;
            }
        }
        if (isset($local['days'][$targetDate])) {
            foreach ($local['days'][$targetDate] as $key => $value) {
                $targetKeys[$key] = true;
            }
        }

        $groups = array();
        foreach (array_keys($targetKeys) as $key) {
            $parts = explode("\x1f", $key, 2);
            $siteId = isset($parts[0]) ? $parts[0] : 'unknown-site';
            $routeGroup = isset($parts[1]) ? $parts[1] : '/';
            $targetAdsense = $this->metricOrEmpty($adsense['days'], $targetDate, $key, 'adsense');
            $targetLocal = $this->metricOrEmpty($local['days'], $targetDate, $key, 'local');
            $adsenseBaseline = array();
            $localBaseline = array();
            for ($offset = -$baselineDays; $offset < 0; $offset++) {
                $date = $this->shiftDate($targetDate, $offset);
                if (isset($adsense['days'][$date][$key])) {
                    $metric = $adsense['days'][$date][$key];
                    $denominator = $this->adsenseDenominator($metric);
                    if ($denominator > 0) {
                        $adsenseBaseline[] = $metric['clicks'] / $denominator;
                    }
                }
                if (isset($local['days'][$date][$key]) && $local['days'][$date][$key]['requests'] > 0) {
                    $metric = $local['days'][$date][$key];
                    $localBaseline[] = $metric['high_risk'] / $metric['requests'];
                }
            }

            $adsenseCtr = $this->adsenseDenominator($targetAdsense) > 0
                ? $targetAdsense['clicks'] / $this->adsenseDenominator($targetAdsense) : 0.0;
            $localRiskRate = $targetLocal['requests'] > 0
                ? $targetLocal['high_risk'] / $targetLocal['requests'] : 0.0;
            $adsenseMedian = $this->median($adsenseBaseline);
            $localMedian = $this->median($localBaseline);
            $adsenseThreshold = max(
                $adsenseMedian * (float)$this->config->get('analytics.ctr_multiplier', 2.0),
                $adsenseMedian + (float)$this->config->get('analytics.ctr_absolute_increase', 0.01)
            );
            $localThreshold = max(
                $localMedian * (float)$this->config->get('analytics.risk_multiplier', 2.0),
                $localMedian + (float)$this->config->get('analytics.risk_absolute_increase', 0.05)
            );
            $adsenseReady = count($adsenseBaseline) >= 3;
            $localReady = count($localBaseline) >= 3;
            $adsenseAnomaly = $adsenseReady
                && $targetAdsense['clicks'] >= (int)$this->config->get('analytics.minimum_clicks', 3)
                && $adsenseCtr >= $adsenseThreshold;
            $localAnomaly = $localReady
                && $targetLocal['high_risk'] >= 2
                && $localRiskRate >= $localThreshold;

            $groupActors = isset($local['actors'][$targetDate][$key])
                ? $this->rankActors($local['actors'][$targetDate][$key]) : array();
            $groupIps = isset($local['ips'][$targetDate][$key])
                ? $this->rankIps($local['ips'][$targetDate][$key]) : array();
            $groupPatterns = isset($local['patterns'][$targetDate][$key])
                ? $this->rankPatterns($local['patterns'][$targetDate][$key]) : array();
            $status = 'NO_CONCURRENT_ANOMALY';
            if (!$adsenseReady || !$localReady) {
                $status = 'INSUFFICIENT_BASELINE';
            }
            if ($adsenseAnomaly && $localAnomaly) {
                $status = 'CORRELATED_AGGREGATE_ANOMALY';
            } elseif ($adsenseAnomaly) {
                $status = 'ADSENSE_ANOMALY_ONLY';
            } elseif ($localAnomaly) {
                $status = 'LOCAL_RISK_ANOMALY_ONLY';
            }

            $groups[] = array(
                'site_id' => $siteId,
                'route_group' => $routeGroup,
                'status' => $status,
                'adsense' => array(
                    'clicks' => $targetAdsense['clicks'],
                    'impressions' => $targetAdsense['impressions'],
                    'page_views' => $targetAdsense['page_views'],
                    'estimated_earnings' => $targetAdsense['estimated_earnings'],
                    'ctr' => $adsenseCtr,
                    'baseline_median_ctr' => $adsenseMedian,
                    'anomaly_threshold_ctr' => $adsenseThreshold,
                    'baseline_samples' => count($adsenseBaseline),
                    'anomaly' => $adsenseAnomaly,
                ),
                'local' => array(
                    'ad_bearing_requests' => $targetLocal['requests'],
                    'ads_served' => $targetLocal['ads_served'],
                    'high_risk_requests' => $targetLocal['high_risk'],
                    'deny' => $targetLocal['deny'],
                    'monitor_deny' => $targetLocal['monitor_deny'],
                    'risk_rate' => $localRiskRate,
                    'baseline_median_risk_rate' => $localMedian,
                    'anomaly_threshold_risk_rate' => $localThreshold,
                    'baseline_samples' => count($localBaseline),
                    'anomaly' => $localAnomaly,
                    'unique_visitors' => count($targetLocal['visitors']),
                    'unique_ips' => count($targetLocal['ips']),
                    'unique_networks' => count($targetLocal['networks']),
                ),
                'candidate_actors' => array_slice($groupActors, 0, 20),
                'candidate_ips' => array_slice($groupIps, 0, 20),
                'behavior_clusters' => array_slice($groupPatterns, 0, 20),
                'interpretation' => $this->interpretation($status, count($groupActors)),
            );
        }

        usort($groups, function ($a, $b) {
            $rank = array(
                'CORRELATED_AGGREGATE_ANOMALY' => 4,
                'ADSENSE_ANOMALY_ONLY' => 3,
                'LOCAL_RISK_ANOMALY_ONLY' => 2,
                'INSUFFICIENT_BASELINE' => 1,
                'NO_CONCURRENT_ANOMALY' => 0,
            );
            $left = isset($rank[$a['status']]) ? $rank[$a['status']] : 0;
            $right = isset($rank[$b['status']]) ? $rank[$b['status']] : 0;
            if ($left !== $right) {
                return $left < $right ? 1 : -1;
            }
            return $a['adsense']['clicks'] < $b['adsense']['clicks'] ? 1 : -1;
        });

        return array(
            'schema' => 'adguard-risk-correlation-v1',
            'generated_at' => gmdate('c'),
            'target_date' => $targetDate,
            'reporting_timezone' => $this->timezone->getName(),
            'baseline_days' => $baselineDays,
            'adsense_source' => isset($snapshot['source']) ? $snapshot['source'] : '',
            'local_invalid_lines' => $local['invalid_lines'],
            'click_to_ip_attribution' => false,
            'groups' => $groups,
            'limitations' => array(
                'AdSense reports are aggregated and contain no click-level IP or visitor identifier.',
                'Candidate actors were merely active on the same site/route/date; they are not proven clickers.',
                'IP and network clusters can represent NAT, offices, schools, carriers, VPNs, or proxies.',
                'Daily correlation cannot establish event order; use it for investigation and prevention only.',
            ),
        );
    }

    public function saveAnalysis($analysis, $directory)
    {
        $directory = rtrim((string)$directory, '/\\');
        if (!$this->provisionDirectory($directory)) {
            throw new \RuntimeException('Cannot provision analysis directory: ' . $directory);
        }
        $date = isset($analysis['target_date']) ? $analysis['target_date'] : 'unknown';
        $encoded = json_encode($analysis, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new \RuntimeException('Cannot encode analysis.');
        }
        $path = $directory . '/analysis-' . $date . '-' . gmdate('His') . '.json';
        if (@file_put_contents($path, $encoded . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Cannot save analysis: ' . $path);
        }
        @chmod($path, 0600);
        return $path;
    }

    private function aggregateAdsense($rows, $startDate, $endDate)
    {
        $result = array('days' => array());
        foreach ((array)$rows as $row) {
            $date = isset($row['date']) ? (string)$row['date'] : '';
            if ($date < $startDate || $date > $endDate) {
                continue;
            }
            $pageUrl = isset($row['page_url']) ? (string)$row['page_url'] : '';
            $host = isset($row['domain']) ? (string)$row['domain'] : '';
            $path = '/';
            if ($pageUrl !== '') {
                $parsedHost = parse_url($pageUrl, PHP_URL_HOST);
                $parsedPath = parse_url($pageUrl, PHP_URL_PATH);
                if (is_string($parsedHost) && $parsedHost !== '') {
                    $host = $parsedHost;
                }
                if (is_string($parsedPath) && $parsedPath !== '') {
                    $path = $parsedPath;
                }
            }
            $site = $this->context->siteIdForHost($host);
            $route = $pageUrl !== '' ? $this->context->routeGroup($path) : '*';
            $key = $site . "\x1f" . $route;
            if (!isset($result['days'][$date][$key])) {
                $result['days'][$date][$key] = $this->emptyAdsenseMetric();
            }
            foreach (array('clicks', 'impressions', 'page_views') as $metric) {
                $result['days'][$date][$key][$metric] += isset($row[$metric]) ? (int)$row[$metric] : 0;
            }
            $result['days'][$date][$key]['estimated_earnings'] += isset($row['estimated_earnings'])
                ? (float)$row['estimated_earnings'] : 0.0;
        }
        return $result;
    }

    private function aggregateLocal($logDirectory, $startDate, $endDate)
    {
        $result = array(
            'days' => array(), 'actors' => array(), 'ips' => array(),
            'patterns' => array(), 'invalid_lines' => 0,
        );
        $utcStart = $this->shiftDate($startDate, -1);
        $utcEnd = $this->shiftDate($endDate, 1);
        for ($fileDate = $utcStart; $fileDate <= $utcEnd; $fileDate = $this->shiftDate($fileDate, 1)) {
            $file = rtrim((string)$logDirectory, '/\\') . '/ad-guard-' . $fileDate . '.jsonl';
            if (!is_file($file)) {
                continue;
            }
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);
                if (!is_array($record)) {
                    $result['invalid_lines']++;
                    continue;
                }
                $date = $this->localDate(isset($record['timestamp']) ? $record['timestamp'] : '');
                if ($date < $startDate || $date > $endDate) {
                    continue;
                }
                $path = isset($record['path']) ? (string)$record['path'] : '/';
                $host = isset($record['host']) ? (string)$record['host'] : '';
                $site = isset($record['site_id']) && $record['site_id'] !== ''
                    ? (string)$record['site_id'] : $this->context->siteIdForHost($host);
                $route = isset($record['route_group']) && $record['route_group'] !== ''
                    ? (string)$record['route_group'] : $this->context->routeGroup($path);
                $key = $site . "\x1f" . $route;
                if (!isset($result['days'][$date][$key])) {
                    $result['days'][$date][$key] = $this->emptyLocalMetric();
                }
                $metric =& $result['days'][$date][$key];
                $metric['requests']++;
                $metric['ads_served'] += !empty($record['ads_served']) ? 1 : 0;
                $action = isset($record['action']) ? (string)$record['action'] : '';
                $score = isset($record['score']) ? (int)$record['score'] : 0;
                $level = isset($record['engine_level']) ? (string)$record['engine_level'] : '';
                $highRisk = $score >= 50 || in_array($level, array('SUSPICIOUS', 'SEVERE'), true)
                    || in_array($action, array('DENY', 'MONITOR_DENY'), true);
                $metric['high_risk'] += $highRisk ? 1 : 0;
                $metric['deny'] += $action === 'DENY' ? 1 : 0;
                $metric['monitor_deny'] += $action === 'MONITOR_DENY' ? 1 : 0;
                $visitor = isset($record['visitor_hmac']) ? (string)$record['visitor_hmac'] : '';
                $ip = isset($record['ip_hmac']) ? (string)$record['ip_hmac'] : '';
                $network = isset($record['network_hmac']) ? (string)$record['network_hmac'] : '';
                if ($visitor !== '') {
                    $metric['visitors'][$visitor] = true;
                }
                if ($ip !== '') {
                    $metric['ips'][$ip] = true;
                }
                if ($network !== '') {
                    $metric['networks'][$network] = true;
                }
                if ($date === $endDate) {
                    $actorId = $visitor !== '' ? 'visitor:' . $visitor : ($ip !== '' ? 'ip:' . $ip : 'request:' . (string)$metric['requests']);
                    if (!isset($result['actors'][$date][$key][$actorId])) {
                        $result['actors'][$date][$key][$actorId] = $this->emptyActor($actorId, $visitor !== '');
                    }
                    $this->addActorRecord($result['actors'][$date][$key][$actorId], $record, $highRisk, $ip, $network);
                    if ($ip !== '') {
                        if (!isset($result['ips'][$date][$key][$ip])) {
                            $result['ips'][$date][$key][$ip] = $this->emptyIp($ip);
                        }
                        $this->addIpRecord($result['ips'][$date][$key][$ip], $record, $highRisk, $visitor, $network);
                    }
                    $patternKey = $this->patternKey($record);
                    if (!isset($result['patterns'][$date][$key][$patternKey])) {
                        $result['patterns'][$date][$key][$patternKey] = $this->emptyPattern($record);
                    }
                    $this->addPatternRecord(
                        $result['patterns'][$date][$key][$patternKey],
                        $record,
                        $highRisk,
                        $visitor,
                        $ip,
                        $network
                    );
                }
                unset($metric);
            }
            fclose($handle);
        }
        return $result;
    }

    private function addActorRecord(&$actor, $record, $highRisk, $ip, $network)
    {
        $actor['requests']++;
        $actor['high_risk'] += $highRisk ? 1 : 0;
        $actor['max_score'] = max($actor['max_score'], isset($record['score']) ? (int)$record['score'] : 0);
        if ($ip !== '') {
            $actor['ips'][$ip] = true;
        }
        if ($network !== '') {
            $actor['networks'][$network] = true;
        }
        $actor['paths'][isset($record['path']) ? (string)$record['path'] : '/'] = true;
        $actor['ua_families'][isset($record['ua_family']) ? (string)$record['ua_family'] : ''] = true;
        $this->recordTiming($actor, $record);
        foreach ((array)(isset($record['signals']) ? $record['signals'] : array()) as $name => $signal) {
            if (is_array($signal) && !empty($signal['triggered'])) {
                $score = isset($signal['score']) ? (int)$signal['score'] : 0;
                $actor['signals'][$name] = max(isset($actor['signals'][$name]) ? $actor['signals'][$name] : 0, $score);
            }
        }
    }

    private function addIpRecord(&$item, $record, $highRisk, $visitor, $network)
    {
        $item['requests']++;
        $item['high_risk'] += $highRisk ? 1 : 0;
        $item['max_score'] = max($item['max_score'], isset($record['score']) ? (int)$record['score'] : 0);
        if ($visitor !== '') {
            $item['visitors'][$visitor] = true;
        }
        if ($network !== '') {
            $item['networks'][$network] = true;
        }
        $this->recordTiming($item, $record);
    }

    private function patternKey($record)
    {
        $signals = array();
        foreach ((array)(isset($record['signals']) ? $record['signals'] : array()) as $name => $signal) {
            if (is_array($signal) && !empty($signal['triggered'])) {
                $signals[] = (string)$name;
            }
        }
        sort($signals, SORT_STRING);
        $referrerGroup = isset($record['referrer_group']) && $record['referrer_group'] !== ''
            ? (string)$record['referrer_group']
            : $this->context->referrerGroup(isset($record['referrer_host']) ? $record['referrer_host'] : '');
        $parts = array(
            $referrerGroup,
            isset($record['country']) ? (string)$record['country'] : '',
            isset($record['ua_family']) ? (string)$record['ua_family'] : '',
            implode('+', $signals),
            isset($record['redirect_rule_id']) ? (string)$record['redirect_rule_id'] : '',
        );
        return hash('sha256', implode("\x1f", $parts));
    }

    private function addPatternRecord(&$item, $record, $highRisk, $visitor, $ip, $network)
    {
        $item['requests']++;
        $item['high_risk'] += $highRisk ? 1 : 0;
        $item['max_score'] = max($item['max_score'], isset($record['score']) ? (int)$record['score'] : 0);
        if ($visitor !== '') {
            $item['visitors'][$visitor] = true;
        }
        if ($ip !== '') {
            $item['ips'][$ip] = true;
        }
        if ($network !== '') {
            $item['networks'][$network] = true;
        }
        $this->recordTiming($item, $record);
    }

    private function rankActors($actors)
    {
        $ranked = array();
        foreach ((array)$actors as $actor) {
            if ($actor['high_risk'] <= 0) {
                continue;
            }
            $direct = isset($actor['signals']['user_agent']) || (isset($actor['signals']['visitor_rate']) && $actor['signals']['visitor_rate'] >= 100);
            $evidence = $direct ? 'STRONG_LOCAL_BEHAVIOR' : ($actor['type'] === 'visitor' && $actor['high_risk'] >= 2 ? 'MEDIUM_LOCAL_BEHAVIOR' : 'WEAK_CONTEXT_ONLY');
            $ranked[] = array(
                'actor_type' => $actor['type'],
                'actor_hmac' => $actor['id'],
                'evidence' => $evidence,
                'requests' => $actor['requests'],
                'high_risk_requests' => $actor['high_risk'],
                'max_score' => $actor['max_score'],
                'unique_ips' => count($actor['ips']),
                'unique_networks' => count($actor['networks']),
                'ip_rotation_observed' => $actor['type'] === 'visitor' && count($actor['ips']) >= 2,
                'timing' => $this->timingFeatures($actor['times']),
                'signals' => $actor['signals'],
                'paths' => array_slice(array_keys($actor['paths']), 0, 10),
                'ua_families' => array_slice(array_keys($actor['ua_families']), 0, 10),
                'click_attribution' => false,
            );
        }
        usort($ranked, function ($a, $b) {
            $rank = array('STRONG_LOCAL_BEHAVIOR' => 3, 'MEDIUM_LOCAL_BEHAVIOR' => 2, 'WEAK_CONTEXT_ONLY' => 1);
            $left = $rank[$a['evidence']] * 100000 + $a['max_score'] * 100 + $a['high_risk_requests'];
            $right = $rank[$b['evidence']] * 100000 + $b['max_score'] * 100 + $b['high_risk_requests'];
            return $left === $right ? 0 : ($left < $right ? 1 : -1);
        });
        return $ranked;
    }

    private function rankIps($ips)
    {
        $ranked = array();
        foreach ((array)$ips as $item) {
            if ($item['high_risk'] <= 0) {
                continue;
            }
            $visitorCount = count($item['visitors']);
            $ranked[] = array(
                'ip_hmac' => $item['id'],
                'requests' => $item['requests'],
                'high_risk_requests' => $item['high_risk'],
                'max_score' => $item['max_score'],
                'unique_visitors' => $visitorCount,
                'unique_networks' => count($item['networks']),
                'timing' => $this->timingFeatures($item['times']),
                'evidence' => $visitorCount > 1 ? 'SHARED_IP_CAUTION' : 'IP_ONLY_CONTEXT',
                'click_attribution' => false,
            );
        }
        usort($ranked, function ($a, $b) {
            if ($a['max_score'] !== $b['max_score']) {
                return $a['max_score'] < $b['max_score'] ? 1 : -1;
            }
            return $a['high_risk_requests'] < $b['high_risk_requests'] ? 1 : -1;
        });
        return $ranked;
    }

    private function rankPatterns($patterns)
    {
        $ranked = array();
        foreach ((array)$patterns as $item) {
            if ($item['high_risk'] < 2) {
                continue;
            }
            $distributed = count($item['ips']) >= 3 || count($item['visitors']) >= 3 || count($item['networks']) >= 2;
            $ranked[] = array(
                'evidence' => $distributed ? 'DISTRIBUTED_BEHAVIOR_PATTERN' : 'REPEATED_BEHAVIOR_PATTERN',
                'referrer_group' => $item['referrer_group'],
                'country' => $item['country'],
                'ua_family' => $item['ua_family'],
                'signal_signature' => $item['signal_signature'],
                'redirect_rule_id' => $item['redirect_rule_id'],
                'requests' => $item['requests'],
                'high_risk_requests' => $item['high_risk'],
                'max_score' => $item['max_score'],
                'unique_visitors' => count($item['visitors']),
                'unique_ips' => count($item['ips']),
                'unique_networks' => count($item['networks']),
                'timing' => $this->timingFeatures($item['times']),
                'click_attribution' => false,
            );
        }
        usort($ranked, function ($a, $b) {
            $left = ($a['evidence'] === 'DISTRIBUTED_BEHAVIOR_PATTERN' ? 100000 : 0)
                + $a['max_score'] * 100 + $a['high_risk_requests'];
            $right = ($b['evidence'] === 'DISTRIBUTED_BEHAVIOR_PATTERN' ? 100000 : 0)
                + $b['max_score'] * 100 + $b['high_risk_requests'];
            return $left === $right ? 0 : ($left < $right ? 1 : -1);
        });
        return $ranked;
    }

    private function emptyAdsenseMetric()
    {
        return array('clicks' => 0, 'impressions' => 0, 'page_views' => 0, 'estimated_earnings' => 0.0);
    }

    private function emptyLocalMetric()
    {
        return array(
            'requests' => 0, 'ads_served' => 0, 'high_risk' => 0, 'deny' => 0, 'monitor_deny' => 0,
            'visitors' => array(), 'ips' => array(), 'networks' => array(),
        );
    }

    private function emptyActor($id, $hasVisitor)
    {
        return array(
            'id' => substr($id, strpos($id, ':') + 1), 'type' => $hasVisitor ? 'visitor' : 'ip',
            'requests' => 0, 'high_risk' => 0, 'max_score' => 0, 'ips' => array(), 'networks' => array(),
            'signals' => array(), 'paths' => array(), 'ua_families' => array(), 'times' => array(),
        );
    }

    private function emptyIp($id)
    {
        return array('id' => $id, 'requests' => 0, 'high_risk' => 0, 'max_score' => 0, 'visitors' => array(), 'networks' => array(), 'times' => array());
    }

    private function emptyPattern($record)
    {
        $signals = array();
        foreach ((array)(isset($record['signals']) ? $record['signals'] : array()) as $name => $signal) {
            if (is_array($signal) && !empty($signal['triggered'])) {
                $signals[] = (string)$name;
            }
        }
        sort($signals, SORT_STRING);
        return array(
            'referrer_group' => isset($record['referrer_group']) && $record['referrer_group'] !== ''
                ? (string)$record['referrer_group']
                : $this->context->referrerGroup(isset($record['referrer_host']) ? $record['referrer_host'] : ''),
            'country' => isset($record['country']) ? (string)$record['country'] : '',
            'ua_family' => isset($record['ua_family']) ? (string)$record['ua_family'] : '',
            'signal_signature' => implode('+', $signals),
            'redirect_rule_id' => isset($record['redirect_rule_id']) ? (string)$record['redirect_rule_id'] : '',
            'requests' => 0, 'high_risk' => 0, 'max_score' => 0,
            'visitors' => array(), 'ips' => array(), 'networks' => array(),
            'times' => array(),
        );
    }

    /** Keep bounded event times for offline low-and-slow/periodicity hints. */
    private function recordTiming(&$item, $record)
    {
        if (!isset($item['times']) || count($item['times']) >= 256) {
            return;
        }
        $timestamp = isset($record['timestamp']) ? strtotime((string)$record['timestamp']) : false;
        if ($timestamp !== false && $timestamp >= 0) {
            $item['times'][] = (int)$timestamp;
        }
    }

    /**
     * Timing is descriptive evidence only. It needs four samples and never
     * becomes a runtime deny rule; retries and shared NAT can look periodic.
     */
    private function timingFeatures($times)
    {
        $times = array_values(array_map('intval', (array)$times));
        sort($times, SORT_NUMERIC);
        $count = count($times);
        if ($count < 2) {
            return array(
                'sample_count' => $count, 'observation_seconds' => 0,
                'median_interval_seconds' => 0, 'interval_jitter' => null,
                'periodic' => false, 'low_and_slow' => false,
            );
        }
        $intervals = array();
        for ($i = 1; $i < $count; $i++) {
            $delta = $times[$i] - $times[$i - 1];
            if ($delta >= 0) {
                $intervals[] = $delta;
            }
        }
        sort($intervals, SORT_NUMERIC);
        $n = count($intervals);
        $middle = (int)floor($n / 2);
        $median = $n % 2 ? (float)$intervals[$middle] : ((float)$intervals[$middle - 1] + (float)$intervals[$middle]) / 2.0;
        $mean = array_sum($intervals) / max(1, $n);
        $variance = 0.0;
        foreach ($intervals as $interval) {
            $variance += pow($interval - $mean, 2);
        }
        $jitter = $mean > 0 ? sqrt($variance / max(1, $n)) / $mean : null;
        $periodic = $count >= 4 && $mean > 0 && $jitter !== null && $jitter <= 0.25;
        $span = max(0, $times[$count - 1] - $times[0]);
        $lowAndSlow = $count >= 4 && $span >= 600 && $median >= 30;
        return array(
            'sample_count' => $count,
            'observation_seconds' => $span,
            'median_interval_seconds' => $median,
            'interval_jitter' => $jitter,
            'periodic' => $periodic,
            'low_and_slow' => $lowAndSlow,
        );
    }

    private function metricOrEmpty($days, $date, $key, $type)
    {
        if (isset($days[$date][$key])) {
            return $days[$date][$key];
        }
        return $type === 'adsense' ? $this->emptyAdsenseMetric() : $this->emptyLocalMetric();
    }

    private function adsenseDenominator($metric)
    {
        return !empty($metric['impressions']) ? (int)$metric['impressions'] : (int)$metric['page_views'];
    }

    private function median($values)
    {
        if (!$values) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = (int)floor($count / 2);
        return $count % 2 ? (float)$values[$middle] : ((float)$values[$middle - 1] + (float)$values[$middle]) / 2.0;
    }

    private function shiftDate($date, $days)
    {
        $value = new \DateTime((string)$date . ' 00:00:00', new \DateTimeZone('UTC'));
        $value->modify(((int)$days >= 0 ? '+' : '') . (int)$days . ' days');
        return $value->format('Y-m-d');
    }

    private function localDate($timestamp)
    {
        try {
            $value = new \DateTime((string)$timestamp, new \DateTimeZone('UTC'));
            $value->setTimezone($this->timezone);
            return $value->format('Y-m-d');
        } catch (\Exception $e) {
            return '';
        }
    }

    private function interpretation($status, $candidateCount)
    {
        if ($status === 'CORRELATED_AGGREGATE_ANOMALY') {
            return 'AdSense CTR and local risk rose together. Review candidates, but none is proven to have clicked an ad.';
        }
        if ($status === 'ADSENSE_ANOMALY_ONLY') {
            return 'AdSense CTR rose without a matching local risk surge; investigate layout, traffic mix, and report segmentation.';
        }
        if ($status === 'LOCAL_RISK_ANOMALY_ONLY') {
            return 'Local hostile behavior rose, but the aggregate click report did not rise concurrently.';
        }
        if ($status === 'INSUFFICIENT_BASELINE') {
            return 'Collect at least three comparable prior dates before anomaly conclusions.';
        }
        return $candidateCount > 0
            ? 'Risk candidates exist, but aggregate behavior is within the current baseline.'
            : 'No concurrent aggregate anomaly was detected.';
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
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        $index = $path . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
        return true;
    }
}
