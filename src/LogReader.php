<?php
namespace AdGuard;

/**
 * Server-side reader for the decision log.
 *
 * The raw JSONL is never linked or streamed to a browser: this class opens
 * the file, filters it, and hands back only the fields the viewer renders.
 * Identifiers arrive already HMAC-pseudonymized from DecisionLogger; this
 * class truncates them further for display so a full pseudonym cannot be
 * copied out of the UI and correlated elsewhere.
 */
class LogReader
{
    const ID_DISPLAY_LENGTH = 12;

    private $config;
    private $timezone;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $zone = (string)$config->get('analytics.reporting_timezone', 'Asia/Seoul');
        try {
            $this->timezone = new \DateTimeZone($zone !== '' ? $zone : 'Asia/Seoul');
        } catch (\Exception $e) {
            $this->timezone = new \DateTimeZone('Asia/Seoul');
        }
    }

    /** Human-facing timezone used by the viewer and daily summaries. */
    public function timezoneName()
    {
        return $this->timezone->getName();
    }

    public function timezoneLabel()
    {
        $now = new \DateTime('now', $this->timezone);
        $label = $now->format('T');
        return $label !== '' ? $label : $this->timezone->getName();
    }

    /** @return string[] available local reporting dates, newest first */
    public function availableDates()
    {
        $dates = array();
        foreach ($this->logFiles() as $fileDate => $file) {
            /*
             * Raw files remain UTC for stable storage. A UTC file can
             * overlap two Asia/Seoul reporting dates, so expose every
             * local date covered by that file. For today's still-open
             * file, stop at the current instant to avoid a future date.
             */
            $utc = new \DateTimeZone('UTC');
            $start = new \DateTime($fileDate . ' 00:00:00', $utc);
            $end = new \DateTime($fileDate . ' 23:59:59', $utc);
            $now = new \DateTime('now', $utc);
            if ($fileDate === $now->format('Y-m-d') && $now < $end) {
                $end = $now;
            }
            $start->setTimezone($this->timezone);
            $end->setTimezone($this->timezone);
            $cursor = new \DateTime($start->format('Y-m-d') . ' 00:00:00', $this->timezone);
            $last = $end->format('Y-m-d');
            while ($cursor->format('Y-m-d') <= $last) {
                $dates[$cursor->format('Y-m-d')] = true;
                $cursor->modify('+1 day');
            }
        }
        $dates = array_keys($dates);
        rsort($dates, SORT_STRING);
        return $dates;
    }

    /**
     * Normalize the viewer's date parameters into one local reporting period.
     *
     * Four modes: a single local day (the historical default), an inclusive
     * local range, one calendar month, and every retained log. Boundaries are
     * expressed in the reporting timezone; the UTC JSONL files overlapping
     * that local period are selected separately by filesForPeriod().
     *
     * An ABSENT parameter falls back to today. An explicitly SUPPLIED but
     * invalid value returns an error instead, so a typo is visible rather
     * than silently answered with a different period's data.
     *
     * @param array $filters date, date_mode, start_date, end_date, month
     * @return array{mode:string,start:string,end:string,error:string}
     */
    public function resolvePeriod($filters)
    {
        $filters = is_array($filters) ? $filters : array();
        $raw = $this->scalarParam($filters, 'date_mode');
        if ($raw === null) {
            return $this->periodError('조회 단위 값이 올바르지 않습니다.');
        }
        $raw = strtolower($raw);
        if ($raw !== '' && !in_array($raw, array('day', 'all', 'range', 'month'), true)) {
            return $this->periodError('조회 단위 값이 올바르지 않습니다.');
        }
        $mode = $raw === '' ? 'day' : $raw;

        if ($mode === 'all') {
            return array('mode' => 'all', 'start' => '', 'end' => '', 'error' => '');
        }

        if ($mode === 'month') {
            $month = $this->scalarParam($filters, 'month');
            if ($month === null) {
                return $this->periodError('월 값이 올바르지 않습니다.');
            }
            if ($month === '') {
                $month = substr($this->localToday(), 0, 7);
            }
            if (!preg_match('~^(\d{4})-(\d{2})$~', $month, $m) || !checkdate((int)$m[2], 1, (int)$m[1])) {
                return $this->periodError('월 형식이 올바르지 않습니다. 예: 2026-08');
            }
            // Calendar arithmetic -- never a hardcoded month length.
            $first = new \DateTime($month . '-01 00:00:00', $this->timezone);
            $last = clone $first;
            $last->modify('first day of next month');
            $last->modify('-1 day');
            return array(
                'mode' => 'month',
                'start' => $first->format('Y-m-d'),
                'end' => $last->format('Y-m-d'),
                'error' => '',
            );
        }

        if ($mode === 'range') {
            $start = $this->scalarParam($filters, 'start_date');
            $end = $this->scalarParam($filters, 'end_date');
            if ($start === null || $end === null) {
                return $this->periodError('기간 값이 올바르지 않습니다.');
            }
            if ($start === '') {
                $start = $this->localToday();
            }
            if ($end === '') {
                $end = $this->localToday();
            }
            if (!$this->isCalendarDate($start) || !$this->isCalendarDate($end)) {
                return $this->periodError('기간 날짜 형식이 올바르지 않습니다. 예: 2026-08-01');
            }
            if (strcmp($start, $end) > 0) {
                return $this->periodError('시작일이 종료일보다 늦습니다.');
            }
            return array('mode' => 'range', 'start' => $start, 'end' => $end, 'error' => '');
        }

        $date = $this->scalarParam($filters, 'date');
        if ($date === null) {
            return $this->periodError('날짜 값이 올바르지 않습니다.');
        }
        if ($date === '') {
            $date = $this->localToday();
        }
        if (!$this->isCalendarDate($date)) {
            return $this->periodError('날짜 형식이 올바르지 않습니다. 예: 2026-09-04');
        }
        return array('mode' => 'day', 'start' => $date, 'end' => $date, 'error' => '');
    }

    private function periodError($message)
    {
        $today = $this->localToday();
        return array('mode' => 'day', 'start' => $today, 'end' => $today, 'error' => (string)$message);
    }

    /** Reject arrays/objects so ?date[]=x never reaches date parsing. */
    private function scalarParam($filters, $key)
    {
        if (!isset($filters[$key])) {
            return '';
        }
        $value = $filters[$key];
        if (is_array($value) || is_object($value)) {
            return null;
        }
        return trim((string)$value);
    }

    /** Regex shape alone is not enough -- 2026-02-31 must be rejected too. */
    private function isCalendarDate($value)
    {
        if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', (string)$value, $m)) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * Every retained log file, keyed by its UTC filename date.
     *
     * Names come from the directory listing and must match the exact expected
     * pattern AND be a real calendar date; nothing from the query string is
     * ever concatenated into a path. Keying by UTC date also guarantees one
     * file can never be read twice within a single request.
     *
     * @return array<string,string> utc date => file path, ascending by date
     */
    private function logFiles()
    {
        $path = rtrim((string)$this->config->get('logging.path'), '/\\');
        if ($path === '' || !is_dir($path)) {
            return array();
        }
        $files = glob($path . '/ad-guard-*.jsonl');
        if (!is_array($files)) {
            return array();
        }
        $out = array();
        foreach ($files as $file) {
            if (!preg_match('~ad-guard-(\d{4})-(\d{2})-(\d{2})\.jsonl$~', basename($file), $m)) {
                continue;
            }
            if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                continue;
            }
            $out[$m[1] . '-' . $m[2] . '-' . $m[3]] = $file;
        }
        ksort($out);
        return $out;
    }

    /**
     * The UTC files overlapping one local period, each listed exactly once.
     *
     * A local day can straddle two UTC files, so a naive "loop the days and
     * read each day's files" would reopen the shared boundary file and
     * double-count its records. Selecting a range against a UTC-date-keyed
     * map makes a duplicate read structurally impossible.
     */
    private function filesForPeriod($period)
    {
        $files = $this->logFiles();
        if ($period['mode'] === 'all') {
            return $files;
        }

        $utc = new \DateTimeZone('UTC');
        $start = new \DateTime($period['start'] . ' 00:00:00', $this->timezone);
        /*
         * Half-open local interval [start 00:00, end+1day 00:00). The last
         * instant that can still belong to the period is one second earlier,
         * and that instant decides the last UTC file worth opening.
         */
        $end = new \DateTime($period['end'] . ' 00:00:00', $this->timezone);
        $end->modify('+1 day -1 second');
        $start->setTimezone($utc);
        $end->setTimezone($utc);
        $firstUtc = $start->format('Y-m-d');
        $lastUtc = $end->format('Y-m-d');

        $selected = array();
        foreach ($files as $fileDate => $file) {
            if (strcmp($fileDate, $firstUtc) >= 0 && strcmp($fileDate, $lastUtc) <= 0) {
                $selected[$fileDate] = $file;
            }
        }
        return $selected;
    }

    /**
     * @param array $filters date, level, action, served, path, reason
     * @return array{rows:array,total:int,summary:array}
     */
    public function read($filters, $page, $pageSize)
    {
        $period = $this->resolvePeriod($filters);
        $summary = $this->emptySummary();
        $pageSize = max(10, min(500, (int)$pageSize));
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $pageSize;

        $result = array(
            'rows' => array(),
            'total' => 0,
            'summary' => $summary,
            // Retained for backward compatibility: callers written against the
            // single-day viewer still read $result['date'].
            'date' => $period['start'] !== '' ? $period['start'] : '',
            'period' => $period,
            'data_start' => '',
            'data_end' => '',
            'files_read' => 0,
        );
        if ($period['error'] !== '') {
            $this->finalizeSummary($summary);
            $result['summary'] = $summary;
            return $result;
        }

        /*
         * Rows render newest-first and only one page is ever displayed, so
         * only the newest ($offset + $pageSize) matched records can reach the
         * output. Retaining a bounded tail instead of every matched record
         * keeps a whole-history query from holding the entire log in memory,
         * and is exact: the discarded records are provably off-page. The
         * SUMMARY still sees every record -- totals are never sampled.
         */
        $keep = $offset + $pageSize;
        $tail = array();
        $total = 0;
        $dataStart = '';
        $dataEnd = '';
        $filesRead = 0;

        foreach ($this->filesForPeriod($period) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            $filesRead++;
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $record = json_decode($line, true);
                if (!is_array($record)) {
                    continue;
                }
                /*
                 * Membership is decided by the record's own timestamp, never
                 * by the UTC filename it happened to live in: one local day
                 * spans two UTC files, and each of those files also holds
                 * records belonging to the neighbouring local day.
                 */
                $localDate = $this->localDateForRecord($record);
                if ($localDate === '') {
                    continue;
                }
                if ($period['mode'] !== 'all'
                    && (strcmp($localDate, $period['start']) < 0 || strcmp($localDate, $period['end']) > 0)) {
                    continue;
                }
                if ($dataStart === '' || strcmp($localDate, $dataStart) < 0) {
                    $dataStart = $localDate;
                }
                if ($dataEnd === '' || strcmp($localDate, $dataEnd) > 0) {
                    $dataEnd = $localDate;
                }

                // Summary covers the selected period, independent of the row
                // filters, so totals and rows share the same local boundary.
                $this->accumulate($summary, $record);

                if (!$this->matches($record, $filters)) {
                    continue;
                }
                $total++;
                $tail[] = $record;
                // Amortized O(1): trim in blocks rather than shifting per row.
                if (count($tail) > $keep * 2) {
                    $tail = array_slice($tail, -$keep);
                }
            }
            fclose($handle);
        }

        if (count($tail) > $keep) {
            $tail = array_slice($tail, -$keep);
        }
        $tail = array_reverse($tail); // newest first
        /*
         * $tail now holds the newest min($total, $keep) matched records, so
         * the requested page starts at ($offset - the number of newer records
         * already dropped) -- which is zero, because nothing newer than the
         * retained tail was ever discarded.
         */
        $slice = array_slice($tail, $offset, $pageSize);

        $rows = array();
        foreach ($slice as $record) {
            $rows[] = $this->presentRow($record);
        }

        $this->finalizeSummary($summary);

        $result['rows'] = $rows;
        $result['total'] = $total;
        $result['summary'] = $summary;
        // For "all", the observed extent of the data; otherwise the observed
        // extent WITHIN the requested period, which may be narrower than it.
        $result['data_start'] = $dataStart;
        $result['data_end'] = $dataEnd;
        $result['files_read'] = $filesRead;
        return $result;
    }

    /**
     * Turn the accumulation buckets into the display shape.
     *
     * Every return path from read() runs this, including the one taken when
     * the requested period was rejected: the viewer renders the same template
     * either way, so an error result must have the same keys as a successful
     * one or the page fills with undefined-key warnings.
     */
    private function finalizeSummary(&$summary)
    {
        $summary['top_reasons'] = $this->topN($summary['reason_counts'], 8);
        $summary['top_denied_paths'] = $this->topN($summary['denied_path_counts'], 8);
        $summary['top_ua_families'] = $this->topN($summary['ua_family_counts'], 8);
        $summary['ad_units'] = $this->rankAdUnits($summary['ad_unit_counts'], 50);
        $summary['top_ips'] = $this->rankActors($summary['ip_activity'], 12);
        $summary['top_visitors'] = $this->rankActors($summary['visitor_activity'], 12);
        $summary['unique_visitors'] = count($summary['visitor_set']);
        $summary['unique_ips'] = count($summary['ip_set']);
        $summary['unique_raw_ips'] = count($summary['raw_ip_set']);
        unset(
            $summary['reason_counts'],
            $summary['denied_path_counts'],
            $summary['ua_family_counts'],
            $summary['visitor_set'],
            $summary['ip_set'],
            $summary['raw_ip_set'],
            $summary['ad_unit_counts'],
            $summary['ip_activity'],
            $summary['visitor_activity']
        );
    }

    private function localToday()
    {
        $now = new \DateTime('now', $this->timezone);
        return $now->format('Y-m-d');
    }

    private function localDateForRecord($record)
    {
        $timestamp = isset($record['timestamp']) ? (string)$record['timestamp'] : '';
        if ($timestamp === '') {
            return '';
        }
        try {
            $value = new \DateTime($timestamp, new \DateTimeZone('UTC'));
            $value->setTimezone($this->timezone);
            return $value->format('Y-m-d');
        } catch (\Exception $e) {
            return '';
        }
    }

    private function localTimestamp($timestamp)
    {
        if ((string)$timestamp === '') {
            return '';
        }
        try {
            $value = new \DateTime((string)$timestamp, new \DateTimeZone('UTC'));
            $value->setTimezone($this->timezone);
            return $value->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return '';
        }
    }

    private function emptySummary()
    {
        return array(
            'total' => 0,
            'levels' => array('NORMAL' => 0, 'ELEVATED' => 0, 'SUSPICIOUS' => 0, 'SEVERE' => 0),
            'actions' => array('ALLOW' => 0, 'DENY' => 0, 'MONITOR_DENY' => 0),
            'ads_served' => 0,
            'ads_not_served' => 0,
            'degraded' => 0,
            'bootstrap_removed' => 0,
            'ad_delivery' => array(
                'bootstrap_opportunities' => 0,
                'bootstrap_provided' => 0,
                'bootstrap_blocked' => 0,
                'bootstrap_missing' => 0,
                'manual_opportunities' => 0,
                'manual_provided' => 0,
                'manual_blocked' => 0,
                'manual_missing' => 0,
                'legacy_records' => 0,
            ),
            'ad_unit_counts' => array(),
            'ip_activity' => array(),
            'visitor_activity' => array(),
            'reason_counts' => array(),
            'denied_path_counts' => array(),
            'ua_family_counts' => array(),
            'visitor_set' => array(),
            'ip_set' => array(),
            'raw_ip_set' => array(),
            'unique_raw_ips' => 0,
            'actor_buckets_truncated' => false,
        );
    }

    private function accumulate(&$summary, $record)
    {
        $summary['total']++;

        $level = isset($record['engine_level']) ? (string)$record['engine_level'] : '';
        if (isset($summary['levels'][$level])) {
            $summary['levels'][$level]++;
        }

        $action = isset($record['action']) ? (string)$record['action'] : '';
        if (isset($summary['actions'][$action])) {
            $summary['actions'][$action]++;
        }

        // "Did the visitor actually receive a runnable bootstrap?" -- older
        // records predate this field, so fall back to the decision.
        $served = array_key_exists('ads_served', $record)
            ? !empty($record['ads_served'])
            : !empty($record['ads_allowed']);
        if ($served) {
            $summary['ads_served']++;
        } else {
            $summary['ads_not_served']++;
        }

        if (!empty($record['degraded'])) {
            $summary['degraded']++;
        }
        $summary['bootstrap_removed'] += isset($record['bootstrap_removed']) ? (int)$record['bootstrap_removed'] : 0;

        if (!empty($record['reasons']) && is_array($record['reasons'])) {
            foreach ($record['reasons'] as $reason) {
                $key = $this->reasonKey((string)$reason);
                if (!isset($summary['reason_counts'][$key])) {
                    $summary['reason_counts'][$key] = 0;
                }
                $summary['reason_counts'][$key]++;
            }
        }

        if (!$served && !empty($record['path'])) {
            $p = (string)$record['path'];
            if (!isset($summary['denied_path_counts'][$p])) {
                $summary['denied_path_counts'][$p] = 0;
            }
            $summary['denied_path_counts'][$p]++;
        }

        $ua = isset($record['ua_family']) ? (string)$record['ua_family'] : '';
        if ($ua !== '') {
            if (!isset($summary['ua_family_counts'][$ua])) {
                $summary['ua_family_counts'][$ua] = 0;
            }
            $summary['ua_family_counts'][$ua]++;
        }

        if (!empty($record['visitor_hmac'])) {
            $summary['visitor_set'][(string)$record['visitor_hmac']] = true;
        }
        if (!empty($record['ip_hmac'])) {
            $summary['ip_set'][(string)$record['ip_hmac']] = true;
        }
        if (!empty($record['raw_ip'])) {
            $summary['raw_ip_set'][(string)$record['raw_ip']] = true;
        }

        $this->accumulateAdDelivery($summary, $record, $served);
        $this->accumulateActor(
            $summary['ip_activity'],
            isset($record['ip_hmac']) ? (string)$record['ip_hmac'] : '',
            $record,
            $served,
            true,
            $summary['actor_buckets_truncated']
        );
        $this->accumulateActor(
            $summary['visitor_activity'],
            isset($record['visitor_hmac']) ? (string)$record['visitor_hmac'] : '',
            $record,
            $served,
            false,
            $summary['actor_buckets_truncated']
        );
    }

    private function accumulateAdDelivery(&$summary, $record, $served)
    {
        $delivery = isset($record['ad_delivery']) && is_array($record['ad_delivery'])
            ? $record['ad_delivery'] : array();
        $bootstrap = isset($delivery['bootstrap']) && is_array($delivery['bootstrap'])
            ? $delivery['bootstrap'] : array();

        if (!$bootstrap) {
            // Pre-schema-v2 compatibility: the old record knows only whether
            // a runnable loader survived, not the manual slots on the page.
            $summary['ad_delivery']['legacy_records']++;
            $summary['ad_delivery']['bootstrap_opportunities']++;
            if ($served) {
                $summary['ad_delivery']['bootstrap_provided']++;
            } elseif ($this->recordWasPolicyBlocked($record)) {
                $summary['ad_delivery']['bootstrap_blocked']++;
            } else {
                $summary['ad_delivery']['bootstrap_missing']++;
            }
            return;
        }

        foreach (array('opportunities', 'provided', 'blocked', 'missing') as $field) {
            $target = 'bootstrap_' . $field;
            $summary['ad_delivery'][$target] += isset($bootstrap[$field]) ? max(0, (int)$bootstrap[$field]) : 0;
        }

        $path = isset($record['path']) ? (string)$record['path'] : '';
        foreach ((array)(isset($delivery['manual_units']) ? $delivery['manual_units'] : array()) as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $slot = isset($unit['slot']) && $unit['slot'] !== '' ? (string)$unit['slot'] : 'unlabeled';
            $format = isset($unit['format']) && $unit['format'] !== '' ? (string)$unit['format'] : 'default';
            $ordinal = max(1, isset($unit['ordinal']) ? (int)$unit['ordinal'] : 1);
            $status = isset($unit['status']) ? (string)$unit['status'] : 'missing';
            if (!in_array($status, array('provided', 'blocked', 'missing'), true)) {
                $status = 'missing';
            }
            $summary['ad_delivery']['manual_opportunities']++;
            $summary['ad_delivery']['manual_' . $status]++;

            $key = $path . "\x1f" . $slot . "\x1f" . $ordinal . "\x1f" . $format;
            if (!isset($summary['ad_unit_counts'][$key])) {
                $summary['ad_unit_counts'][$key] = array(
                    'path' => $path,
                    'slot' => $slot,
                    'ordinal' => $ordinal,
                    'format' => $format,
                    'opportunities' => 0,
                    'provided' => 0,
                    'blocked' => 0,
                    'missing' => 0,
                );
            }
            $summary['ad_unit_counts'][$key]['opportunities']++;
            $summary['ad_unit_counts'][$key][$status]++;
        }
    }

    private function recordWasPolicyBlocked($record)
    {
        $action = isset($record['action']) ? (string)$record['action'] : '';
        return $action === 'DENY'
            || (!empty($record['external_suppression']))
            || (array_key_exists('ads_allowed', $record) && empty($record['ads_allowed']));
    }

    private function accumulateActor(&$bucket, $identifier, $record, $served, $storeRawIp = false, &$truncated = null)
    {
        $identifier = (string)$identifier;
        if ($identifier === '') {
            return;
        }
        if (!isset($bucket[$identifier])) {
            $limit = max(100, (int)$this->config->get('viewer.max_actor_buckets', 50000));
            if (count($bucket) >= $limit) {
                $truncated = true;
                return;
            }
            $bucket[$identifier] = array(
                'id' => $identifier,
                'responses' => 0,
                'loader_provided' => 0,
                'loader_not_provided' => 0,
                'high_risk' => 0,
                'max_score' => 0,
                'raw_ip' => '',
                'ip_resolution_status' => '',
                // Packed big-endian uint32 seconds rather than a PHP array of
                // ints. A whole-history query keeps one entry per RECORD here,
                // and a PHP array element costs roughly twenty times what the
                // four raw bytes do. The values, their order, and every
                // statistic derived from them are unchanged -- rankActors()
                // unpacks and sorts exactly as before.
                'times' => '',
            );
        }
        $item =& $bucket[$identifier];
        $item['responses']++;
        if ($served) {
            $item['loader_provided']++;
        } else {
            $item['loader_not_provided']++;
        }
        $score = isset($record['score']) ? (int)$record['score'] : 0;
        $item['max_score'] = max($item['max_score'], $score);
        if ($storeRawIp && !empty($record['raw_ip'])) {
            $item['raw_ip'] = (string)$record['raw_ip'];
            $item['ip_resolution_status'] = isset($record['ip_resolution_status'])
                ? (string)$record['ip_resolution_status'] : '';
        }
        if ($this->recordIsHighRisk($record)) {
            $item['high_risk']++;
        }
        $timestamp = isset($record['timestamp']) ? strtotime((string)$record['timestamp']) : false;
        // uint32 holds 1970-01-01 .. 2106-02-07; anything outside that is not
        // a timestamp this log could legitimately contain, so drop it rather
        // than silently wrapping it into a wrong instant.
        if ($timestamp !== false && $timestamp >= 0 && $timestamp <= 4294967295) {
            $item['times'] .= pack('N', (int)$timestamp);
        }
        unset($item);
    }

    /** Restore packed uint32 seconds to a plain list of integers. */
    private function unpackTimes($packed)
    {
        if (!is_string($packed)) {
            // Defensive: an older caller may still hand over a plain array.
            return array_values((array)$packed);
        }
        if ($packed === '') {
            return array();
        }
        $values = unpack('N*', $packed);
        return is_array($values) ? array_values($values) : array();
    }

    private function recordIsHighRisk($record)
    {
        $score = isset($record['score']) ? (int)$record['score'] : 0;
        $level = isset($record['engine_level']) ? (string)$record['engine_level'] : '';
        $action = isset($record['action']) ? (string)$record['action'] : '';
        return $score >= 50
            || in_array($level, array('SUSPICIOUS', 'SEVERE'), true)
            || in_array($action, array('DENY', 'MONITOR_DENY'), true);
    }

    /** Group reasons by their signal prefix so the tally stays readable. */
    private function reasonKey($reason)
    {
        $pos = strpos($reason, ':');
        $key = $pos === false ? $reason : substr($reason, 0, $pos);
        return substr(trim($key), 0, 64);
    }

    private function matches($record, $filters)
    {
        if (!empty($filters['level'])) {
            if ((string)$record['engine_level'] !== (string)$filters['level']) {
                return false;
            }
        }
        if (!empty($filters['action'])) {
            if (!isset($record['action']) || (string)$record['action'] !== (string)$filters['action']) {
                return false;
            }
        }
        if (isset($filters['served']) && $filters['served'] !== '') {
            $served = array_key_exists('ads_served', $record)
                ? !empty($record['ads_served'])
                : !empty($record['ads_allowed']);
            if ($filters['served'] === 'yes' && !$served) {
                return false;
            }
            if ($filters['served'] === 'no' && $served) {
                return false;
            }
        }
        if (!empty($filters['path'])) {
            $needle = (string)$filters['path'];
            if (stripos(isset($record['path']) ? (string)$record['path'] : '', $needle) === false) {
                return false;
            }
        }
        if (!empty($filters['identifier'])) {
            $needle = strtolower((string)$filters['identifier']);
            $ip = strtolower(isset($record['ip_hmac']) ? (string)$record['ip_hmac'] : '');
            $rawIp = strtolower(isset($record['raw_ip']) ? (string)$record['raw_ip'] : '');
            $visitor = strtolower(isset($record['visitor_hmac']) ? (string)$record['visitor_hmac'] : '');
            if (strpos($ip, $needle) !== 0 && strpos($rawIp, $needle) !== 0 && strpos($visitor, $needle) !== 0) {
                return false;
            }
        }
        return true;
    }

    private function presentRow($record)
    {
        $signals = array();
        if (!empty($record['signals']) && is_array($record['signals'])) {
            foreach ($record['signals'] as $name => $signal) {
                if (!empty($signal['triggered'])) {
                    $signals[] = $name . '=' . (isset($signal['score']) ? (int)$signal['score'] : 0);
                }
            }
        }

        $served = array_key_exists('ads_served', $record)
            ? !empty($record['ads_served'])
            : !empty($record['ads_allowed']);

        return array(
            'timestamp' => isset($record['timestamp']) ? (string)$record['timestamp'] : '',
            'timestamp_local' => $this->localTimestamp(isset($record['timestamp']) ? $record['timestamp'] : ''),
            'path' => isset($record['path']) ? (string)$record['path'] : '',
            'method' => isset($record['method']) ? (string)$record['method'] : '',
            'raw_ip' => isset($record['raw_ip']) ? (string)$record['raw_ip'] : '',
            'ip_resolution_status' => isset($record['ip_resolution_status']) ? (string)$record['ip_resolution_status'] : '',
            'request_type' => isset($record['request_type']) ? (string)$record['request_type'] : '',
            'ad_opportunity' => !empty($record['ad_opportunity']),
            'crawler_status' => isset($record['crawler_status']) ? (string)$record['crawler_status'] : '',
            'crawler_vendor' => isset($record['crawler_vendor']) ? (string)$record['crawler_vendor'] : '',
            'level' => isset($record['engine_level']) ? (string)$record['engine_level'] : '',
            'score' => isset($record['score']) ? (int)$record['score'] : 0,
            'action' => isset($record['action']) ? (string)$record['action'] : '',
            'policy_reason' => isset($record['policy_reason']) ? (string)$record['policy_reason'] : '',
            'ads_served' => $served,
            'bootstrap_removed' => isset($record['bootstrap_removed']) ? (int)$record['bootstrap_removed'] : 0,
            'external_suppression' => isset($record['external_suppression']) ? (string)$record['external_suppression'] : '',
            'degraded' => !empty($record['degraded']),
            'ua_family' => isset($record['ua_family']) ? (string)$record['ua_family'] : '',
            'referrer_host' => isset($record['referrer_host']) ? (string)$record['referrer_host'] : '',
            'ip_short' => $this->shortId(isset($record['ip_hmac']) ? $record['ip_hmac'] : ''),
            'visitor_short' => $this->shortId(isset($record['visitor_hmac']) ? $record['visitor_hmac'] : ''),
            'signals' => implode(' ', $signals),
            'ad_units' => $this->presentAdUnits($record),
            'reasons' => !empty($record['reasons']) && is_array($record['reasons']) ? $record['reasons'] : array(),
        );
    }

    private function presentAdUnits($record)
    {
        $delivery = isset($record['ad_delivery']) && is_array($record['ad_delivery'])
            ? $record['ad_delivery'] : array();
        $out = array();
        foreach ((array)(isset($delivery['manual_units']) ? $delivery['manual_units'] : array()) as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $slot = isset($unit['slot']) ? (string)$unit['slot'] : 'unlabeled';
            $ordinal = max(1, isset($unit['ordinal']) ? (int)$unit['ordinal'] : 1);
            $status = isset($unit['status']) ? (string)$unit['status'] : 'missing';
            $out[] = $slot . '#' . $ordinal . ':' . $status;
        }
        return implode(' ', array_slice($out, 0, 24));
    }

    private function shortId($value)
    {
        $value = (string)$value;
        return $value === '' ? '' : substr($value, 0, self::ID_DISPLAY_LENGTH);
    }

    private function topN($counts, $n)
    {
        arsort($counts);
        $out = array();
        $i = 0;
        foreach ($counts as $key => $count) {
            if ($i++ >= $n) {
                break;
            }
            $out[] = array('key' => $key, 'count' => $count);
        }
        return $out;
    }

    private function rankAdUnits($items, $limit)
    {
        $ranked = array_values((array)$items);
        usort($ranked, function ($a, $b) {
            if ($a['opportunities'] === $b['opportunities']) {
                return strcmp($a['path'] . $a['slot'], $b['path'] . $b['slot']);
            }
            return $a['opportunities'] < $b['opportunities'] ? 1 : -1;
        });
        return array_slice($ranked, 0, max(1, (int)$limit));
    }

    private function rankActors($items, $limit)
    {
        $ranked = array();
        foreach ((array)$items as $item) {
            $times = $this->unpackTimes(isset($item['times']) ? $item['times'] : '');
            sort($times, SORT_NUMERIC);
            $last = $times ? $times[count($times) - 1] : 0;
            $ranked[] = array(
                'id_short' => $this->shortId(isset($item['id']) ? $item['id'] : ''),
                'raw_ip' => isset($item['raw_ip']) ? (string)$item['raw_ip'] : '',
                'ip_resolution_status' => isset($item['ip_resolution_status']) ? (string)$item['ip_resolution_status'] : '',
                'responses' => isset($item['responses']) ? (int)$item['responses'] : 0,
                'loader_provided' => isset($item['loader_provided']) ? (int)$item['loader_provided'] : 0,
                'loader_not_provided' => isset($item['loader_not_provided']) ? (int)$item['loader_not_provided'] : 0,
                'high_risk' => isset($item['high_risk']) ? (int)$item['high_risk'] : 0,
                'max_score' => isset($item['max_score']) ? (int)$item['max_score'] : 0,
                'max_10m' => $this->rollingMaximum($times, 600),
                'max_1h' => $this->rollingMaximum($times, 3600),
                'last_time' => $last > 0 ? $this->localTimestamp(gmdate('c', $last)) : '',
            );
        }
        usort($ranked, function ($a, $b) {
            if ($a['responses'] !== $b['responses']) {
                return $a['responses'] < $b['responses'] ? 1 : -1;
            }
            if ($a['high_risk'] !== $b['high_risk']) {
                return $a['high_risk'] < $b['high_risk'] ? 1 : -1;
            }
            return strcmp($a['id_short'], $b['id_short']);
        });
        return array_slice($ranked, 0, max(1, (int)$limit));
    }

    private function rollingMaximum($times, $windowSeconds)
    {
        $times = array_values((array)$times);
        $left = 0;
        $max = 0;
        $count = count($times);
        for ($right = 0; $right < $count; $right++) {
            while ($left <= $right && $times[$right] - $times[$left] >= (int)$windowSeconds) {
                $left++;
            }
            $max = max($max, $right - $left + 1);
        }
        return $max;
    }
}
