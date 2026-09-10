<?php
/**
 * Operator view of the AdGuard decision log.
 *
 * The raw JSONL is never linked or served; it is read server-side, filtered,
 * and rendered with truncated pseudonyms. Access is DENIED BY DEFAULT --
 * these are security records about real visitors, so the allowlist must be
 * filled in deliberately (adguard/config/guard.php -> viewer.allowed_ips).
 */

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/LogReader.php';

$config = \AdGuard\Config::load();

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$viewerEnabled = (bool)$config->get('viewer.enabled', true);
$allowedIps = $config->get('viewer.allowed_ips', array());
$allowedIps = is_array($allowedIps) ? $allowedIps : array();
$clientIp = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$allowed = $viewerEnabled && in_array($clientIp, $allowedIps, true);

if (!$allowed) {
    /*
     * Deliberately a plain 404 with setup instructions rather than 403: an
     * unauthenticated visitor learns nothing about whether this file exists
     * or what it holds, while an operator who lands here knows what to do.
     */
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "페이지를 찾을 수 없습니다.\n\n";
    echo "(사이트 운영자 안내: adguard/config/guard.php의 viewer.allowed_ips에\n";
    echo " 현재 접속 IP를 등록하기 전에는 모든 접근을 거부합니다.\n";
    echo " 현재 접속 IP: " . ($clientIp === '' ? '확인 불가' : $clientIp) . "\n";
    echo " 가능하면 원본 JSONL은 SSH에서 확인하는 것을 권장합니다.)\n";
    exit;
}

$reader = new \AdGuard\LogReader($config);
$dates = $reader->availableDates();
$displayTimezone = $reader->timezoneName();
$displayTimezoneLabel = $reader->timezoneLabel();

/**
 * Read one GET parameter as a bounded scalar.
 *
 * ?date[]=x arrives as an array; casting that to string emits a notice and
 * yields "Array", which would then be validated as a date. Arrays are turned
 * into an explicit invalid marker so LogReader reports a validation error
 * rather than silently answering with some other period.
 */
function rlv_param($key, $maxLength)
{
    if (!isset($_GET[$key])) {
        return '';
    }
    if (is_array($_GET[$key])) {
        return array();
    }
    return substr((string)$_GET[$key], 0, (int)$maxLength);
}

$filters = array(
    'date_mode' => rlv_param('date_mode', 16),
    'date' => rlv_param('date', 32),
    'start_date' => rlv_param('start_date', 32),
    'end_date' => rlv_param('end_date', 32),
    'month' => rlv_param('month', 16),
    'level' => rlv_param('level', 24),
    'action' => rlv_param('action', 24),
    'served' => rlv_param('served', 8),
    'path' => rlv_param('path', 200),
    'identifier' => rlv_param('identifier', 64),
);
$page = isset($_GET['page']) && !is_array($_GET['page']) ? (int)$_GET['page'] : 1;
$pageSize = (int)$config->get('viewer.page_size', 100);

$result = $reader->read($filters, $page, $pageSize);
$rows = $result['rows'];
$summary = $result['summary'];
$total = $result['total'];
$activeDate = $result['date'];
$period = $result['period'];
$periodError = $period['error'];
$pageCount = $total > 0 ? (int)ceil($total / $pageSize) : 1;
$page = min(max(1, $page), $pageCount);

/*
 * A single local day can show bare wall-clock times unambiguously; any wider
 * period cannot, so every timestamp gains its date.
 */
$multiDay = $period['mode'] === 'all' || $period['start'] !== $period['end'];

$activeMode = $period['mode'];
$formDate = $activeMode === 'day' ? $period['start'] : $activeDate;
$formStart = $activeMode === 'range' ? $period['start'] : '';
$formEnd = $activeMode === 'range' ? $period['end'] : '';
$formMonth = $activeMode === 'month' ? substr($period['start'], 0, 7) : '';

function rlv_e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Render a timestamp as date+time for multi-day periods, time alone for one day. */
function rlv_when($localTimestamp, $multiDay)
{
    $value = (string)$localTimestamp;
    if ($value === '') {
        return '';
    }
    return $multiDay ? $value : substr($value, 11, 8);
}

function rlv_query($overrides)
{
    $scalar = function ($key, $default) {
        if (!isset($_GET[$key]) || is_array($_GET[$key])) {
            return $default;
        }
        return (string)$_GET[$key];
    };
    $base = array(
        // The active period must survive pagination and drill-down links, or
        // clicking "next page" would silently jump back to today.
        'date_mode' => $scalar('date_mode', ''),
        'date' => $scalar('date', ''),
        'start_date' => $scalar('start_date', ''),
        'end_date' => $scalar('end_date', ''),
        'month' => $scalar('month', ''),
        'level' => $scalar('level', ''),
        'action' => $scalar('action', ''),
        'served' => $scalar('served', ''),
        'path' => $scalar('path', ''),
        'identifier' => $scalar('identifier', ''),
        'page' => $scalar('page', '1'),
    );
    foreach ($overrides as $k => $v) {
        $base[$k] = $v;
    }
    $parts = array();
    foreach ($base as $k => $v) {
        if ($v !== '') {
            $parts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
    }
    return '?' . implode('&', $parts);
}

function rlv_level_label($level)
{
    $labels = array(
        'NORMAL' => '정상',
        'ELEVATED' => '주의',
        'SUSPICIOUS' => '의심',
        'SEVERE' => '심각',
    );
    return isset($labels[$level]) ? $labels[$level] : (string)$level;
}

function rlv_action_label($action)
{
    $labels = array(
        'ALLOW' => '허용',
        'DENY' => '차단',
        'MONITOR_DENY' => '차단 후보(모니터)',
    );
    return isset($labels[$action]) ? $labels[$action] : (string)$action;
}

function rlv_signal_label($signal)
{
    $labels = array(
        'user_agent' => '사용자 환경 이상',
        'rate_limit' => 'IP 요청 과다',
        'visitor_rate' => '방문자 요청 과다',
        'session_churn' => '방문자 식별자 급증',
    );
    return isset($labels[$signal]) ? $labels[$signal] : (string)$signal;
}

function rlv_signal_summary($signals)
{
    $parts = preg_split('/\s+/', trim((string)$signals));
    $out = array();
    foreach ((array)$parts as $part) {
        if ($part === '') {
            continue;
        }
        $pair = explode('=', $part, 2);
        $out[] = rlv_signal_label($pair[0]) . (isset($pair[1]) ? '=' . $pair[1] : '');
    }
    return implode(' ', $out);
}

function rlv_policy_reason_label($reason)
{
    $reason = (string)$reason;
    $labels = array(
        'risk_below_deny_threshold' => '차단 기준 미만',
        'blocked_engine_level' => '위험 등급 기준 차단',
        'risk_engine_degraded_fail_closed' => '위험 엔진 오류로 안전 차단',
        'risk_storage_degraded_fail_closed' => '저장소 오류로 안전 차단',
    );
    if (isset($labels[$reason])) {
        return $labels[$reason];
    }
    $prefix = 'hard_deny_signal:';
    if (strpos($reason, $prefix) === 0) {
        return '강제 차단 신호: ' . rlv_signal_label(substr($reason, strlen($prefix)));
    }
    return $reason;
}

function rlv_ad_units_label($units)
{
    return str_replace(
        array(':provided', ':blocked', ':missing'),
        array(':제공', ':차단', ':누락'),
        (string)$units
    );
}

/** Say exactly which period is on screen, in the reporting timezone. */
function rlv_period_label($period)
{
    if ($period['mode'] === 'all') {
        return '전체';
    }
    if ($period['mode'] === 'month') {
        $parts = explode('-', $period['start']);
        return $parts[0] . '년 ' . (int)$parts[1] . '월';
    }
    if ($period['start'] === $period['end']) {
        return $period['start'];
    }
    return $period['start'] . ' ~ ' . $period['end'];
}

$noLoaderRate = $summary['total'] > 0
    ? round(($summary['ads_not_served'] / $summary['total']) * 100, 1)
    : 0.0;
$delivery = $summary['ad_delivery'];
$policyBlockRate = $delivery['bootstrap_opportunities'] > 0
    ? round(($delivery['bootstrap_blocked'] / $delivery['bootstrap_opportunities']) * 100, 1)
    : 0.0;
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>AdSense 위험 로그 뷰어</title>
<style>
:root{color-scheme:light;font-family:Arial,Helvetica,sans-serif}
*{box-sizing:border-box}
body{margin:0;background:#f4f6f8;color:#1f2937}
.shell{width:min(1400px,calc(100% - 28px));margin:0 auto;padding:22px 0 60px}
h1{font-size:24px;margin:0 0 4px}
.sub{color:#64748b;font-size:13px;margin:0 0 18px;line-height:1.6}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.stat{border:1px solid #e2e8f0;border-radius:9px;padding:11px}
.stat span{display:block;font-size:11px;color:#64748b;margin-bottom:4px}
.stat strong{font-size:18px}
.controls{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}
.controls label{font-size:12px;color:#475569;display:block;margin-bottom:3px}
select,input[type=text]{padding:7px 9px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px}
button,.btn{border:1px solid #cbd5e1;background:#fff;padding:7px 12px;border-radius:8px;cursor:pointer;font-size:13px;text-decoration:none;color:inherit;display:inline-block}
button:hover,.btn:hover{background:#f8fafc}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th,td{padding:7px 8px;border-bottom:1px solid #e8edf3;text-align:left;vertical-align:top}
th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#475569;position:sticky;top:0}
.tbl-wrap{overflow-x:auto}
code{font-family:Consolas,monospace;font-size:11.5px}
.pill{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700}
.lv-NORMAL{background:#dcfce7;color:#166534}
.lv-ELEVATED{background:#fef3c7;color:#92400e}
.lv-SUSPICIOUS{background:#ffedd5;color:#9a3412}
.lv-SEVERE{background:#fee2e2;color:#991b1b}
.served-yes{color:#166534;font-weight:700}
.served-no{color:#991b1b;font-weight:700}
.muted{color:#94a3b8}
.toplist{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:10px}
.toplist ul{list-style:none;margin:6px 0 0;padding:0;font-size:12.5px}
.toplist li{display:flex;justify-content:space-between;gap:10px;padding:3px 0;border-bottom:1px dotted #e2e8f0}
.toplist h3{font-size:12px;text-transform:uppercase;color:#475569;margin:0}
.note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:10px 12px;border-radius:9px;font-size:12.5px;line-height:1.6}
.pager{display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px}
.section-title{font-size:16px;margin:0 0 10px}
.status-provided{color:#166534;font-weight:700}
.status-blocked{color:#991b1b;font-weight:700}
.status-missing{color:#92400e;font-weight:700}
.alert{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:9px;font-size:13px;line-height:1.6;margin:0 0 16px}
input[type=date],input[type=month]{padding:6px 9px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px}
</style>
</head>
<body>
<main class="shell">
  <h1>AdSense 위험 로그 뷰어</h1>
  <p class="sub">
    AdGuard 판정 감사 로그. 원본 JSONL 파일은 브라우저로 전송되지 않고 서버에서 읽어 필터링한 결과만 표시합니다.
    IP/방문자 식별자는 저장 시점에 이미 HMAC 처리되어 있고, 화면에는 앞 12자만 노출합니다.
  </p>

  <?php if ($periodError !== ''): ?>
  <p class="alert">
    입력한 조회 조건을 사용할 수 없습니다: <?=rlv_e($periodError)?>
    아래는 기본값인 <strong><?=rlv_e($period['start'])?></strong> 하루 기준 결과입니다.
  </p>
  <?php endif; ?>

  <p class="note">
    조회 기간: <strong><?=rlv_e(rlv_period_label($period))?></strong>
    <span class="muted">(<?=rlv_e($displayTimezone)?> 기준)</span>
    <?php if ($result['data_start'] !== ''): ?>
      &middot; 실제 데이터 범위: <?=rlv_e($result['data_start'])?> ~ <?=rlv_e($result['data_end'])?>
    <?php else: ?>
      &middot; <span class="muted">이 기간에 기록된 로그가 없습니다.</span>
    <?php endif; ?>
    &middot; 읽은 로그 파일 <?=rlv_e($result['files_read'])?>개
  </p>

  <section class="card">
    <div class="grid">
      <div class="stat"><span>광고 감지 응답</span><strong><?=rlv_e($summary['total'])?></strong></div>
      <div class="stat"><span>로더 제공 응답</span><strong><?=rlv_e($summary['ads_served'])?></strong></div>
      <div class="stat"><span>로더 미제공 응답</span><strong><?=rlv_e($summary['ads_not_served'])?></strong></div>
      <div class="stat"><span>로더 미제공률</span><strong><?=rlv_e($noLoaderRate)?>%</strong></div>
      <div class="stat"><span>정책 차단 로더</span><strong><?=rlv_e($delivery['bootstrap_blocked'])?></strong></div>
      <div class="stat"><span>정책 차단률</span><strong><?=rlv_e($policyBlockRate)?>%</strong></div>
      <div class="stat"><span>정상 (NORMAL)</span><strong><?=rlv_e($summary['levels']['NORMAL'])?></strong></div>
      <div class="stat"><span>주의 (ELEVATED)</span><strong><?=rlv_e($summary['levels']['ELEVATED'])?></strong></div>
      <div class="stat"><span>의심 (SUSPICIOUS)</span><strong><?=rlv_e($summary['levels']['SUSPICIOUS'])?></strong></div>
      <div class="stat"><span>심각 (SEVERE)</span><strong><?=rlv_e($summary['levels']['SEVERE'])?></strong></div>
      <div class="stat"><span>차단 후보(모니터)</span><strong><?=rlv_e($summary['actions']['MONITOR_DENY'])?></strong></div>
      <div class="stat"><span>엔진·저장소 장애</span><strong><?=rlv_e($summary['degraded'])?></strong></div>
      <div class="stat"><span>고유 방문자*</span><strong><?=rlv_e($summary['unique_visitors'])?></strong></div>
      <div class="stat"><span>고유 IP*</span><strong><?=rlv_e($summary['unique_ips'])?></strong></div>
      <div class="stat"><span>고유 원본 IP</span><strong><?=rlv_e($summary['unique_raw_ips'])?></strong></div>
    </div>
    <p class="sub" style="margin:12px 0 0">
      * 가명화된 식별자 기준의 <em>추정치</em>입니다. 쿠키를 지운 방문자는 새 방문자로, 공유 IP 뒤의 여러 사람은 하나의 IP로 집계됩니다.
      이 수치는 보안 텔레메트리이지 AdSense 수익 지표나 확인된 사람 수가 아닙니다.
    </p>
  </section>

  <section class="card">
    <h2 class="section-title">광고 위치별 제공·차단 현황</h2>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>페이지</th><th>광고/슬롯</th><th>광고 형식</th><th>제공 기회</th><th>로더 제공</th><th>정책 차단</th><th>구현 누락</th></tr>
        </thead>
        <tbody>
          <tr>
            <td><code>전체 광고 페이지</code></td>
            <td><strong>AdSense 공통 로더</strong><br><span class="muted">자동 광고 포함</span></td>
            <td>공통 로더</td>
            <td><?=rlv_e($delivery['bootstrap_opportunities'])?></td>
            <td class="status-provided"><?=rlv_e($delivery['bootstrap_provided'])?></td>
            <td class="status-blocked"><?=rlv_e($delivery['bootstrap_blocked'])?></td>
            <td class="status-missing"><?=rlv_e($delivery['bootstrap_missing'])?></td>
          </tr>
          <?php foreach ($summary['ad_units'] as $unit): ?>
          <tr>
            <td><code><?=rlv_e($unit['path'])?></code></td>
            <td><code><?=rlv_e($unit['slot'])?>#<?=rlv_e($unit['ordinal'])?></code></td>
            <td><?=rlv_e($unit['format'])?></td>
            <td><?=rlv_e($unit['opportunities'])?></td>
            <td class="status-provided"><?=rlv_e($unit['provided'])?></td>
            <td class="status-blocked"><?=rlv_e($unit['blocked'])?></td>
            <td class="status-missing"><?=rlv_e($unit['missing'])?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$summary['ad_units']): ?>
          <tr><td colspan="7" class="muted">선택 날짜의 기존 로그에는 슬롯별 데이터가 없습니다. 새 코드 배포 후 생성되는 로그부터 표시됩니다.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="sub" style="margin:12px 0 0">
      “로더 제공”은 브라우저 응답에 실행 가능한 AdSense 로더가 포함됐다는 뜻이며 실제 Google 광고 요청·노출·클릭 수가 아닙니다.
      같은 슬롯을 한 페이지에서 여러 번 쓰면 DOM 순서에 따라 <code>#1</code>, <code>#2</code>로 구분합니다.
      <?php if ($delivery['legacy_records'] > 0): ?>기존 스키마 로그 <?=rlv_e($delivery['legacy_records'])?>건은 공통 로더만 역산했으며 슬롯별 집계에서는 제외했습니다.<?php endif; ?>
    </p>
  </section>

  <section class="card">
    <h2 class="section-title">IP·방문자별 광고 가능 응답 빈도</h2>
    <div class="toplist">
      <div class="tbl-wrap">
        <h3>요청이 많은 IP 식별자</h3>
        <table>
          <thead><tr><th>원본 IP</th><th>IP 식별자*</th><th>응답</th><th>로더 제공</th><th>미제공</th><th>최대 10분</th><th>최대 1시간</th><th>고위험</th><th>최고점</th><th>최근 시각</th></tr></thead>
          <tbody>
          <?php foreach ($summary['top_ips'] as $actor): ?>
            <tr>
              <td><code title="<?=rlv_e($actor['ip_resolution_status'])?>"><?=rlv_e($actor['raw_ip'] !== '' ? $actor['raw_ip'] : '-')?></code></td>
              <td><a href="<?=rlv_e(rlv_query(array('identifier' => $actor['id_short'], 'page' => '1')))?>"><code><?=rlv_e($actor['id_short'])?></code></a></td>
              <td><?=rlv_e($actor['responses'])?></td><td><?=rlv_e($actor['loader_provided'])?></td><td><?=rlv_e($actor['loader_not_provided'])?></td>
              <td><?=rlv_e($actor['max_10m'])?></td><td><?=rlv_e($actor['max_1h'])?></td><td><?=rlv_e($actor['high_risk'])?></td><td><?=rlv_e($actor['max_score'])?></td>
              <td><code><?=rlv_e(rlv_when($actor['last_time'], $multiDay))?></code></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$summary['top_ips']): ?><tr><td colspan="10" class="muted">IP 식별 데이터 없음</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="tbl-wrap">
        <h3>요청이 많은 방문자 식별자</h3>
        <table>
          <thead><tr><th>방문자*</th><th>응답</th><th>로더 제공</th><th>미제공</th><th>최대 10분</th><th>최대 1시간</th><th>고위험</th><th>최고점</th><th>최근 시각</th></tr></thead>
          <tbody>
          <?php foreach ($summary['top_visitors'] as $actor): ?>
            <tr>
              <td><a href="<?=rlv_e(rlv_query(array('identifier' => $actor['id_short'], 'page' => '1')))?>"><code><?=rlv_e($actor['id_short'])?></code></a></td>
              <td><?=rlv_e($actor['responses'])?></td><td><?=rlv_e($actor['loader_provided'])?></td><td><?=rlv_e($actor['loader_not_provided'])?></td>
              <td><?=rlv_e($actor['max_10m'])?></td><td><?=rlv_e($actor['max_1h'])?></td><td><?=rlv_e($actor['high_risk'])?></td><td><?=rlv_e($actor['max_score'])?></td>
              <td><code><?=rlv_e(rlv_when($actor['last_time'], $multiDay))?></code></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$summary['top_visitors']): ?><tr><td colspan="9" class="muted">방문자 식별 데이터 없음</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <p class="sub" style="margin:12px 0 0">
      응답 수는 광고가 감지된 서버 응답 수입니다. 최대 10분/1시간은 선택 날짜 안에서 관측된 가장 밀집한 구간이며 실제 광고 요청·클릭 수가 아닙니다.
      공유 IP는 여러 사람을 포함할 수 있으므로 IP 순위만으로 자동 차단하지 않습니다.
      <?php if (!empty($summary['actor_buckets_truncated'])): ?>전체 합산은 유지했지만 고유 식별자가 설정된 순위를 넘어 일부 장기 꼬리 항목은 상위 순위에서 제외되었습니다.<?php endif; ?>
    </p>
  </section>

  <?php if ($summary['top_reasons'] || $summary['top_denied_paths'] || $summary['top_ua_families']): ?>
  <section class="card">
    <div class="toplist">
      <div>
        <h3>주요 위험 사유</h3>
        <ul>
          <?php foreach ($summary['top_reasons'] as $r): ?>
          <li><span title="<?=rlv_e($r['key'])?>"><?=rlv_e(rlv_signal_label($r['key']))?></span><span><?=rlv_e($r['count'])?></span></li>
          <?php endforeach; ?>
          <?php if (!$summary['top_reasons']): ?><li class="muted">없음</li><?php endif; ?>
        </ul>
      </div>
      <div>
        <h3>광고 미제공이 많은 경로</h3>
        <ul>
          <?php foreach ($summary['top_denied_paths'] as $r): ?>
          <li><code><?=rlv_e($r['key'])?></code><span><?=rlv_e($r['count'])?></span></li>
          <?php endforeach; ?>
          <?php if (!$summary['top_denied_paths']): ?><li class="muted">없음</li><?php endif; ?>
        </ul>
      </div>
      <div>
        <h3>브라우저·접속 도구 종류</h3>
        <ul>
          <?php foreach ($summary['top_ua_families'] as $r): ?>
          <li><code><?=rlv_e($r['key'])?></code><span><?=rlv_e($r['count'])?></span></li>
          <?php endforeach; ?>
          <?php if (!$summary['top_ua_families']): ?><li class="muted">없음</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="card">
    <form method="get" class="controls">
      <div>
        <label>조회 단위 (<?=rlv_e($displayTimezoneLabel)?>)</label>
        <select name="date_mode" id="date-mode">
          <option value="day"<?=$activeMode === 'day' ? ' selected' : ''?>>특정 날짜</option>
          <option value="all"<?=$activeMode === 'all' ? ' selected' : ''?>>전체 날짜</option>
          <option value="range"<?=$activeMode === 'range' ? ' selected' : ''?>>기간</option>
          <option value="month"<?=$activeMode === 'month' ? ' selected' : ''?>>월</option>
        </select>
      </div>
      <div data-mode-field="day"<?=$activeMode === 'day' ? '' : ' hidden'?>>
        <label>날짜</label>
        <input type="date" name="date" value="<?=rlv_e($formDate)?>"
               list="rlv-available-dates">
        <datalist id="rlv-available-dates">
          <?php foreach ($dates as $d): ?><option value="<?=rlv_e($d)?>"></option><?php endforeach; ?>
        </datalist>
      </div>
      <div data-mode-field="range"<?=$activeMode === 'range' ? '' : ' hidden'?>>
        <label>시작일</label>
        <input type="date" name="start_date" value="<?=rlv_e($formStart)?>">
      </div>
      <div data-mode-field="range"<?=$activeMode === 'range' ? '' : ' hidden'?>>
        <label>종료일</label>
        <input type="date" name="end_date" value="<?=rlv_e($formEnd)?>">
      </div>
      <div data-mode-field="month"<?=$activeMode === 'month' ? '' : ' hidden'?>>
        <label>월</label>
        <input type="month" name="month" value="<?=rlv_e($formMonth)?>">
      </div>
      <div>
        <label>위험 등급</label>
        <select name="level">
          <option value="">전체</option>
          <?php foreach (array('NORMAL','ELEVATED','SUSPICIOUS','SEVERE') as $lv): ?>
          <option value="<?=$lv?>"<?=$filters['level'] === $lv ? ' selected' : ''?>><?=rlv_e(rlv_level_label($lv))?> (<?=$lv?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>조치</label>
        <select name="action">
          <option value="">전체</option>
          <?php foreach (array('ALLOW','DENY','MONITOR_DENY') as $ac): ?>
          <option value="<?=$ac?>"<?=$filters['action'] === $ac ? ' selected' : ''?>><?=rlv_e(rlv_action_label($ac))?> (<?=$ac?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>광고 로더</label>
        <select name="served">
          <option value="">전체</option>
          <option value="yes"<?=$filters['served'] === 'yes' ? ' selected' : ''?>>제공</option>
          <option value="no"<?=$filters['served'] === 'no' ? ' selected' : ''?>>미제공</option>
        </select>
      </div>
      <div>
        <label>페이지 경로 포함</label>
        <input type="text" name="path" value="<?=rlv_e($filters['path'])?>" placeholder="/topic.php">
      </div>
      <div>
        <label>식별자 앞자리</label>
        <input type="text" name="identifier" value="<?=rlv_e($filters['identifier'])?>" placeholder="IP/방문자 해시 앞자리">
      </div>
      <button type="submit">적용</button>
      <a class="btn" href="<?=rlv_e(strtok($_SERVER['PHP_SELF'], '?'))?>">초기화</a>
    </form>
    <p class="sub" style="margin:12px 0 0">
      자동 새로고침은 하지 않습니다. 최신 내용을 보려면 적용 버튼을 다시 누르세요.
      새 조건을 적용하면 항상 1페이지부터 다시 표시합니다.
    </p>
  </section>

  <script>
  /*
   * Progressive enhancement only: the four modes already work with plain form
   * submission, and every field stays reachable with JavaScript disabled.
   * This merely hides the inputs that do not apply to the selected mode.
   */
  (function () {
    var select = document.getElementById('date-mode');
    if (!select) { return; }
    var fields = document.querySelectorAll('[data-mode-field]');
    function sync() {
      for (var i = 0; i < fields.length; i++) {
        fields[i].hidden = fields[i].getAttribute('data-mode-field') !== select.value;
      }
    }
    select.addEventListener('change', sync);
    sync();
  })();
  </script>

  <section class="card">
    <div class="pager">
      조건에 맞는 로그 <strong><?=rlv_e($total)?></strong>건 &middot; <?=rlv_e($page)?> / <?=rlv_e($pageCount)?> 페이지
      <?php if ($page > 1): ?><a class="btn" href="<?=rlv_e(rlv_query(array('page' => (string)($page - 1))))?>">← 이전</a><?php endif; ?>
      <?php if ($page < $pageCount): ?><a class="btn" href="<?=rlv_e(rlv_query(array('page' => (string)($page + 1))))?>">다음 →</a><?php endif; ?>
    </div>
    <div class="tbl-wrap">
    <table>
      <thead>
        <tr>
          <th>시각 (<?=rlv_e($displayTimezoneLabel)?>)</th><th>페이지 경로</th><th>위험 등급</th><th>점수</th><th>조치</th>
          <th>광고 로더</th><th>광고 슬롯 상태</th><th>정책 사유</th><th>발동 신호</th><th>접속 도구</th>
          <th>원본 IP</th><th>IP*</th><th>방문자*</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="13" class="muted">이 조건에 해당하는 로그가 없습니다.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><code title="UTC <?=rlv_e($r['timestamp'])?>"><?=rlv_e(rlv_when($r['timestamp_local'], $multiDay))?></code></td>
          <td><code><?=rlv_e($r['path'])?></code></td>
          <td><span class="pill lv-<?=rlv_e($r['level'])?>" title="<?=rlv_e($r['level'])?>"><?=rlv_e(rlv_level_label($r['level']))?></span></td>
          <td><?=rlv_e($r['score'])?></td>
          <td title="<?=rlv_e($r['action'])?>"><?=rlv_e(rlv_action_label($r['action']))?></td>
          <td class="<?=$r['ads_served'] ? 'served-yes' : 'served-no'?>">
            <?=$r['ads_served'] ? '제공' : '미제공'?>
            <?php if ($r['bootstrap_removed'] > 0): ?><span class="muted">(<?=rlv_e($r['bootstrap_removed'])?>개 제거)</span><?php endif; ?>
            <?php if ($r['external_suppression'] !== ''): ?><span class="muted">(<?=rlv_e($r['external_suppression'])?>)</span><?php endif; ?>
          </td>
          <td><code><?=rlv_e($r['ad_units'] !== '' ? rlv_ad_units_label($r['ad_units']) : '-')?></code></td>
          <td title="<?=rlv_e($r['policy_reason'])?>"><?=rlv_e(rlv_policy_reason_label($r['policy_reason']))?><?php if ($r['degraded']): ?> <span class="pill lv-SEVERE">장애 상태</span><?php endif; ?></td>
          <td title="<?=rlv_e($r['signals'])?>"><?=rlv_e($r['signals'] !== '' ? rlv_signal_summary($r['signals']) : '-')?></td>
          <td title="<?=rlv_e($r['crawler_status'] . ($r['crawler_vendor'] !== '' ? ' / ' . $r['crawler_vendor'] : ''))?>"><?=rlv_e($r['ua_family'])?><?php if ($r['crawler_status'] === 'verified'): ?> <span class="muted">(검증)</span><?php elseif ($r['crawler_status'] === 'claimed_unverified'): ?> <span class="muted">(주장 불일치)</span><?php endif; ?></td>
          <td><code title="<?=rlv_e($r['ip_resolution_status'])?>"><?=rlv_e($r['raw_ip'] !== '' ? $r['raw_ip'] : '-')?></code></td>
          <td><code><?=rlv_e($r['ip_short'] !== '' ? $r['ip_short'] : '-')?></code></td>
          <td><code><?=rlv_e($r['visitor_short'] !== '' ? $r['visitor_short'] : '-')?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="sub" style="margin:12px 0 0">
      원본 IP는 v3 로그에 저장되며 이 Viewer의 허용된 운영자에게만 표시됩니다. IP 식별자와 방문자 ID는 서버 전용 키로 HMAC 처리된 가명의 앞 12자이며, 같은 값이면 같은 대상이라는 것만 알 수 있습니다.
      날짜와 시각은 <?=rlv_e($displayTimezone)?> 기준이며 원본 UTC 시각은 각 시각에 마우스를 올리면 확인할 수 있습니다.
    </p>
  </section>
</main>
</body>
</html>
