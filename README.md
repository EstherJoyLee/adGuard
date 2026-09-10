# adguard — AdSense 무효 트래픽 방어 패키지

**이 폴더 하나만 복사하면 됩니다.** 다른 폴더에 흩어진 파일도, Composer도, DB도, 크론도 없습니다.

PHP 5.6 이상에서 동작합니다.

---

## 1. 이게 뭘 하는가 (그리고 뭘 못 하는가)

**하는 일**: 들어오는 HTTP 요청이 얼마나 수상한지 점수를 매기고, 수상하면 **그 응답에서 AdSense 부트스트랩 스크립트(`adsbygoogle.js`)가 실행되지 못하게** 합니다. 스크립트가 없으면 Display·In-feed·In-article·Multiplex는 물론 Anchor·Vignette·Side rail 같은 Auto Ads, 그리고 **앞으로 구글이 추가할 새 광고 형식까지** 전부 실행될 수 없습니다.

**못 하는 일** — 이건 명확히 알고 계셔야 합니다:

- **실제 광고 클릭은 탐지하지 못합니다.** 광고는 구글 도메인의 iframe 안에서 렌더링되고, 브라우저 보안 정책(same-origin)이 그 안의 클릭을 사이트 코드로부터 완전히 차단합니다. 클릭 정보는 구글 서버로만 갑니다. 이건 이 패키지의 한계가 아니라 웹의 구조적 제약입니다.
- 따라서 이건 "부정클릭 탐지기"가 아니라 **"수상한 방문자에게는 애초에 광고를 요청하지 않는 게이트"**입니다.
- 정상 판정·차단 경로에서는 사이트 콘텐츠를 차단하지 않고 광고 bootstrap만 제거합니다. 다만 잘못된 `auto_prepend_file` 경로, 누락/parse 오류 같은 PHP fatal은 요청 전체를 500으로 만들 수 있으므로 배포 전 lint·HTTP smoke test와 rollback 절차가 필요합니다.

---

## 2. 설치 (3단계)

### 1단계 — 폴더 복사

프로젝트 어디든 `adguard/` 폴더를 통째로 올립니다. 웹 루트 바로 아래를 권장합니다.

```
내프로젝트/
├── adguard/          ← 이 폴더(운영 문서는 adguard/docs/)
├── index.php
└── ...
```

### 2단계 — 실행 지점 연결

**방법 A: 전역 설치 (권장)**

PHP의 `auto_prepend_file`에 절대경로를 지정합니다. 이러면 **앞으로 새로 만드는 PHP 페이지도 자동으로 보호**됩니다.

정확한 설정 문구를 출력해 줍니다:

```bash
php adguard/tools/print-install-config.php
```

- **PHP-FPM / FastCGI**: 출력된 줄을 사이트의 `.user.ini` 또는 풀 설정에 넣습니다 (`adguard/.user.ini.example` 참고)
- **Apache mod_php**: vhost에 `php_value auto_prepend_file /절대/경로/adguard/auto-prepend.php`

> 상대경로는 하위 디렉터리 스크립트에서 깨집니다. **반드시 절대경로**를 쓰세요.

**방법 B: 공통 헤더에 한 줄 (auto_prepend를 못 쓸 때)**

모든 페이지가 include 하는 공통 파일 맨 위(출력 시작 전)에:

```php
require_once __DIR__ . '/../adguard/adguard.php';
ad_guard_boot();
```

이 방법은 **그 공통 파일을 include 하는 페이지만** 보호됩니다. 나중에 만든 페이지가 빠질 수 있으니 방법 A가 안전합니다.

### 3단계 — 동작 확인

페이지를 한 번 열고:

```bash
ls adguard/storage/logs/
```

`ad-guard-YYYY-MM-DD.jsonl` 파일이 생겼으면 정상입니다.

---

## 3. 설정은 파일 하나

**`adguard/config/guard.php`** — 여기만 수정하면 됩니다. 나머지 코드는 건드리지 마세요.

```php
<?php
return array(
    'mode' => 'monitor',        // ← 가장 중요. 아래 4장 참고

    'excluded_paths' => array(  // 이 경로들은 아예 건드리지 않음
        '/adguard/viewer.php',
    ),

    'viewer' => array(
        // 기본은 전부 거부. 여기 넣은 IP만 로그 뷰어에 접근 가능
        'allowed_ips' => array('내.사무실.IP.주소'),
    ),
);
```

적어놓지 않은 값은 전부 기본값을 씁니다. 기본값 전체는 `adguard/src/Config.php`(게이트)와 `adguard/engine/src/Config.php`(점수 엔진)에서 볼 수 있습니다.

임계값을 바꾸려면 `adguard/config/engine.php`를 만듭니다 (`engine.php.example` 복사).

---

## 4. 세 가지 모드 — **처음엔 반드시 `monitor`**

| mode | 판정 | 로그 | 광고 차단 | 언제 |
|---|---|---|---|---|
| `monitor` | 함 | 함 | **안 함** | **처음 도입할 때** |
| `enforce` | 함 | 함 | 함 | 검증 끝난 뒤 |
| `off` | 안 함 | 안 함 | 안 함 | 완전 비활성화 |

### 왜 `monitor`부터인가

기본 임계값은 **당신 사이트의 실제 트래픽으로 검증된 값이 아닙니다.** 실제로 이 패키지를 감사하면서 측정했을 때, 초기 설정에서는 **한 IP를 공유한 정상 방문자 15명이 41번째 요청에서 전원 광고를 잃었습니다.** 그 시점 엔진 판정은 `NORMAL`(점수 3)이었는데도요. 그 결함들은 고쳤지만, **남은 값들이 당신 트래픽에서 안전한지는 아무도 모릅니다.**

한국 트래픽은 특히 위험합니다. 모바일 통신사 CGNAT, PC방, 회사·학교 네트워크는 **IP 하나 뒤에 수백~수만 명**이 있습니다. IP 기반 판단은 이런 환경에서 쉽게 정상 사용자를 잡습니다.

`monitor` 모드는 판정과 로깅은 다 하면서 HTML은 손대지 않습니다. 차단했을 요청은 로그에 `action=MONITOR_DENY`로 남습니다. **차단 없이 오탐률을 먼저 재는 것**이 목적입니다.

전환은 한 줄입니다. 배포 없이 파일만 고치면 다음 요청부터 적용됩니다.

---

## 5. 로그 뷰어

브라우저에서 `https://내도메인/adguard/viewer.php`

**기본은 전부 거부(404)입니다.** `config/guard.php`의 `viewer.allowed_ips`에 본인 IP를 넣어야 열립니다. 404 화면에 현재 접속 IP가 표시되니 그걸 복사해 넣으면 됩니다.

보여주는 것:

- **요약**: 광고 감지 응답, 로더 제공/미제공, 정책 차단율, 등급 분포, MONITOR_DENY 수, 고유 방문자/IP 추정치
- **광고 위치별 집계**: 페이지 경로 + `data-ad-slot` + 같은 슬롯의 DOM 순번별 제공 기회/로더 제공/정책 차단/구현 누락
- **IP·방문자별 밀도**: 가명 식별자별 광고 감지 응답 수, 로더 제공/미제공, 가장 밀집한 10분·1시간 요청 수, 고위험 응답과 최고 점수
- **Top N**: 위험 사유, 광고가 안 나간 경로, UA 종류
- **표**: 시각·경로·등급·점수·조치·로더 제공 여부·광고 슬롯 상태·정책 사유·발동 신호·마스킹된 식별자
- **조회 단위**: 특정 날짜 / 전체 날짜 / 기간 / 월 (아래 참고)
- **필터**: 등급·조치·광고 제공 여부·경로·식별자 접두사
- 100행씩 페이지네이션, **자동 새로고침 없음**

### 조회 단위 4가지

| 단위 | URL | 의미 |
|---|---|---|
| 특정 날짜 | `?date_mode=day&date=2026-09-04` | 그 하루 (기본값) |
| 전체 날짜 | `?date_mode=all` | 보관 중인 모든 로그 |
| 기간 | `?date_mode=range&start_date=2026-08-01&end_date=2026-09-04` | 시작일·종료일 **모두 포함** |
| 월 | `?date_mode=month&month=2026-08` | 그 달 1일 ~ 말일 (말일은 달력 계산) |

- 파라미터를 아무것도 주지 않으면 **예전과 똑같이 오늘 하루**입니다. 기존 `?date=YYYY-MM-DD` 링크도 그대로 동작합니다.
- 모든 경계는 `analytics.reporting_timezone`(기본 `Asia/Seoul`) 기준입니다. 원본 JSONL 파일명은 UTC이므로 하루가 파일 2개에 걸치며, 포함 여부는 **파일명이 아니라 각 레코드의 timestamp**로 판정합니다.
- 날짜를 잘못 입력하면 조용히 오늘로 바꾸지 않고 **오류를 표시**합니다. 값을 아예 주지 않았을 때만 오늘로 기본값을 씁니다.
- 하루보다 넓은 기간에서는 표의 시각이 `YYYY-MM-DD HH:MM:SS`로 날짜까지 표시됩니다.
- 기간·필터는 페이지 이동과 식별자 드릴다운에서 유지되며, 조건을 새로 적용하면 항상 1페이지로 돌아갑니다.

**전체 조회 성능은 로그 양에 비례합니다.** 아래 §13을 반드시 확인하세요.

여기서 **제공**은 최종 HTML에 실행 가능한 AdSense 공통 로더가 남았다는 뜻입니다. Google이 실제 광고 요청·노출·클릭으로 인정했다는 뜻은 아닙니다. 수동 광고는 `data-ad-slot`별로 구분하지만 Auto Ads의 개별 위치는 Google이 브라우저에서 동적으로 정하므로 서버에서는 **공통 로더 1개 단위**로만 집계합니다. 같은 슬롯 ID를 한 페이지에서 반복하면 `#1`, `#2`처럼 DOM 순번을 붙여 위치를 구분합니다.

새 집계 필드는 로그 `schema_version=2`부터 기록됩니다. 예전 로그도 공통 로더 제공/미제공 합계에는 포함되지만, 존재하지 않았던 슬롯별 정보는 복원하지 않습니다.

원본 로그 파일은 브라우저로 전송되지 않습니다. 서버에서 읽어 필터링한 결과만 HTML로 나갑니다.

---

## 6. 로그

```
adguard/storage/logs/ad-guard-YYYY-MM-DD.jsonl   (UTC 일별)
adguard/storage/.hmac-key                        (가명화 키)
adguard/storage/engine/                          (속도 카운터)
```

**개인정보 처리**: IP와 방문자 ID는 **원본을 저장하지 않습니다.** 서버 전용 키로 HMAC 처리해 저장하고, 화면에는 앞 12자만 보여줍니다. 비밀번호·Authorization 헤더·쿠키 원문·쿼리스트링·요청 본문·전체 User-Agent는 **아예 기록하지 않습니다.**

**안전장치**: 하루 20MB 상한(초과 시 로깅만 멈추고 광고 정책은 계속 동작), 잠금 실패 시 조용히 포기 — **로그 실패가 페이지에 영향을 주지 않습니다.**

### ⚠️ 배포 후 반드시 확인할 것

```bash
curl -I https://내도메인/adguard/storage/logs/
curl -I https://내도메인/adguard/storage/.hmac-key
```

**둘 다 403 또는 404여야 합니다.** 200이 나오면 즉시 조치하세요.

패키지가 `.htaccess`를 자동으로 넣지만 **Apache에서만 동작합니다.** nginx는 무시합니다:

```nginx
location ~ /adguard/storage/ { deny all; }
```

HMAC 키가 유출되면 IP 가명화가 무의미해집니다(IPv4 전체를 대입해 역산 가능).

### 로그 정리

자동 삭제는 없습니다. 30~90일 권장:

```bash
find adguard/storage/logs -name 'ad-guard-*.jsonl' -mtime +90 -delete
```

### 일별 요약 (CLI)

```bash
php adguard/tools/report.php 2026-08-28
```

---

## 7. 임계값 조정

`adguard/config/engine.php`를 만들어(없으면 `engine.php.example` 복사) 바꿀 값만 적습니다.

```php
<?php
return array(
    'signals' => array(
        'visitor_rate'  => array('windows' => array(10 => 12, 60 => 45, 600 => 180)),
        'rate_limit'    => array('windows' => array(10 => 200, 300 => 2000)),
        'session_churn' => array('threshold' => 60),
    ),
);
```

### 어떤 값을 올릴지 고르는 법

뷰어의 **Top risk reasons**에서 가장 많이 나온 신호부터 완화합니다. **한 번에 하나씩** 바꾸고 하루는 지켜보세요.

- `user_agent`가 대부분 → 정상입니다 (자동화 도구를 잡고 있는 것)
- `rate_limit` / `session_churn`이 상위 → **공유 IP 오탐 의심**. 해당 값을 올리세요
- `visitor_rate`가 상위 → 한 브라우저가 실제로 과속. 진짜일 가능성 높음

### 지켜야 할 안전 규칙 (자동 검사됨)

```bash
php adguard/tests/policy-safety-test.php
```

1. **IP 기반 신호(`rate_limit`, `session_churn`)의 weight × 100 < 차단 임계값(50).** IP는 주소이지 사람이 아니므로 단독 차단 근거가 될 수 없습니다.
2. **IP 기반 신호를 `hard_deny_signals`에 넣지 마세요.** 결합 판정을 우회합니다.
3. **hard-deny 최소값은 40 이상.** 이 점수는 "임계값 초과 비율(%)"이라 `1`은 사실상 "1회만 넘어도 차단"입니다.
4. **차단 가능한 신호가 최소 하나는 남아야 합니다.** 안 그러면 보호를 끈 것과 같습니다.

설정을 바꾼 뒤엔 이 테스트를 꼭 다시 돌리세요.

---

## 8. 문제가 생겼을 때

| 증상 | 확인 | 조치 |
|---|---|---|
| 로그가 안 쌓임 | `adguard/storage/logs/` 권한, 디스크, 일 20MB 상한 | 권한 수정 |
| 뷰어가 404 | 404 화면에 표시된 현재 IP | `viewer.allowed_ips`에 추가 |
| 뷰어가 비어 있음 | 선택한 **한국시간 날짜**에 광고 감지 응답이 없음 | 날짜/광고 페이지 확인 |
| **광고 수익 급감** | 뷰어 Block rate → Top risk reasons | 아래 순서 참고 |
| 정상 사용자가 대량 차단 | Top reasons가 `rate_limit`/`session_churn`인지 | 해당 임계값 완화 |
| `degraded` 다수 | 저장소 쓰기 가능 여부 | 디스크/권한 확인 |

### 광고 수익이 급감했을 때 판단 순서

1. 뷰어에서 **Block rate**를 봅니다. 평소와 같으면 **이 패키지 문제가 아닙니다** (AdSense fill률 등 다른 원인).
2. 올랐다면 **Top risk reasons**를 봅니다.
3. `rate_limit`/`session_churn`이 상위면 **공유 IP 오탐**입니다 → 임계값 완화.
4. 원인 파악 전에 급하면 아래로 즉시 롤백하세요.

### 즉시 롤백

```php
// adguard/config/guard.php
'mode' => 'monitor',   // 차단만 중단, 로깅은 유지
```

완전히 끄려면 `'mode' => 'off'`. 배포 없이 파일만 고치면 다음 요청부터 적용됩니다. **미리 한 번 연습해두세요.**

---

## 9. 제거

1. `config/guard.php`에서 `'mode' => 'off'` — 즉시 무력화
2. `auto_prepend_file` 설정 제거 (또는 공통 헤더의 `require`/`ad_guard_boot()` 두 줄 삭제)
3. `adguard/` 폴더 삭제

다른 파일을 고칠 필요가 없습니다.

---

## 10. 테스트

```bash
php adguard/tests/ad-guard-test.php
php adguard/tests/policy-safety-test.php
php adguard/tests/auto-prepend-integration-test.php
php adguard/tests/adsense-correlation-test.php
php adguard/tests/viewer-timezone-test.php
php adguard/tests/viewer-date-range-test.php
php adguard/tests/ad-defense-bridge-test.php
php adguard/engine/tests/user-agent-signal-test.php
php adguard/engine/tests/rate-limit-signal-test.php
php adguard/engine/tests/visitor-rate-signal-test.php
php adguard/engine/tests/session-churn-signal-test.php
php adguard/engine/tests/combined-verdict-test.php
php adguard/engine/tests/concurrency-storage-test.php
php adguard/engine/tests/cookie-header-http-test.php
php adguard/engine/tests/boundary-check.php
php adguard/tools/php56-check-selftest.php
```

`ad-defense-bridge-test.php`는 **이 프로젝트의 어댑터**(`include/ad-defense.php`)를 검사합니다.
어댑터가 없는 프로젝트에서는 실패가 아니라 `skip:`으로 건너뜁니다.
나머지 테스트는 `adguard/` 폴더만 복사한 상태에서 전부 통과해야 합니다.

PHP 5.6 호환성 확인:

```bash
php adguard/tools/php56-check.php adguard/src adguard/engine/src adguard/viewer.php adguard/adguard.php adguard/auto-prepend.php
```

전체 조회 성능 측정 (합성 로그만 사용, 운영 로그를 건드리지 않음):

```bash
php adguard/tools/viewer-benchmark.php 120 2000 128M
```

---

## 11. 폴더 구조

```
adguard/
├── README.md                 이 문서
├── adguard.php               진입점 (수동 통합 시 이것만 require)
├── auto-prepend.php          auto_prepend_file 대상
├── viewer.php                로그 뷰어 (웹 접근)
├── config/
│   ├── guard.php             ★ 여기만 수정하면 됩니다
│   └── engine.php.example    임계값을 바꿀 때 복사해서 사용
├── docs/                     통합 운영 가이드·Viewer 요약표
├── src/                      광고 게이트 (판정·HTML 필터·로깅·로그읽기)
├── engine/                   광고를 전혀 모르는 범용 위험 점수 엔진
│   ├── risk-engine.php       공개 API 2개
│   ├── src/Signals/          신호 4종
│   └── tests/
├── storage/                  자동 생성 (로그·카운터·HMAC 키·AdSense 집계·분석물)
├── tools/                    리포트·AdSense 수집/상관분석·설치안내·PHP5.6 검사기
└── tests/                    게이트 테스트
```

`engine/`은 광고를 전혀 모르도록 설계되어 있고, 그 경계는 `engine/tests/boundary-check.php`가 자동으로 강제합니다. 광고와 무관한 다른 용도(로그인 폼 보호 등)로도 재사용할 수 있습니다.

---

## 12. 개발자용 API

공통 부트스트랩을 직접 출력하는 통합이라면:

```php
require_once '/절대경로/adguard/adguard.php';

if (ad_guard_ads_allowed()) {
    // 구글이 준 광고 코드를 그대로 출력
}
```

| 함수 | 용도 |
|---|---|
| `ad_guard_boot()` | 응답 감시 시작 (출력 전에 호출) |
| `ad_guard_ads_allowed()` | 이 요청에 광고를 내보내도 되는가 |
| `ad_guard_decision()` | 판정 전체 (등급·점수·사유·신호) |
| `ad_guard_mark_ad_opportunity()` | HTML에 로더가 안 보여도 이 응답은 광고 기회였다고 표시 |
| `ad_guard_note_external_suppression($reason)` | 다른 이유로 광고를 막았을 때 로그에 기록 |
| `ad_guard_set_analytics_context($context)` | `route_group`, `redirect_rule_id`, `traffic_source_group` 같은 안정적인 서버 라벨 설정 |

이 6개가 패키지의 **공개 API 전부**입니다. `\AdGuard\*` 클래스를 프로젝트 코드에서 직접 참조하면
패키지 업그레이드 때 깨질 수 있으므로 위 함수만 사용하세요.

---

## 13. 알려진 한계

정직하게 적습니다.

1. **실제 광고 클릭은 볼 수 없습니다** (구조적 제약).
2. **기본 임계값은 검증되지 않았습니다.** `monitor`로 먼저 재세요.
3. **residential proxy + 정상 UA + 느린 속도** 조합은 탐지 불가입니다. 가장 큰 사각지대입니다.
4. **IP 로테이션을 실시간으로 한 공격자라고 확정하지 못합니다.** 사후 분석에서는 Visitor HMAC의 IP 변화와 `/24`·`/64` network HMAC를 약한 근러스터 근거로 보여 줍니다.
5. **저속 장기 공격(low-and-slow)** 탐지가 없습니다. 현재는 요청 시점의 시간 윈도우만 봅니다.
6. **여러 IP에 걸친 분산 공격을 요청 즉시 차단하지는 못합니다.** 일별 AdSense 집계와 로컬 로그의 사후 상관분석은 지원합니다.
7. **정적 `.html` 파일은 보호할 수 없습니다.** PHP가 실행되지 않으므로 리버스 프록시 레벨 필터가 필요합니다.
8. **HTML이 캐시되면 우회될 수 있습니다.** CDN/브라우저가 광고 포함 HTML을 캐시하면 판정이 바뀌어도 이전 응답이 재사용됩니다. 광고가 있는 페이지에는 캐시를 걸지 않는 것을 권장합니다.
9. **VPN 사용자를 공격자로 취급하지 않습니다.** 이건 의도된 설계입니다 — VPN은 공격의 증거가 아닙니다.
10. **뷰어의 전체/장기간 조회는 메모리를 로그 건수에 비례해서 씁니다.** 아래 실측표를 보고 `memory_limit`을 정하세요.

### 뷰어 조회 비용 (실측)

합성 로그 기준, `php adguard/tools/viewer-benchmark.php`로 측정한 값입니다.
장비와 로그 내용에 따라 달라지므로 **본인 환경에서 다시 재세요.**

| 조회 | 로그 파일 | 레코드 | 시간 | 최대 메모리 |
|---|---:|---:|---:|---:|
| 하루 | 2 | 2,000 | 0.07초 | 4 MiB |
| 7일 | 8 | 14,000 | 0.34초 | 14 MiB |
| 1개월 | 31 | 61,250 | 1.4초 | 52 MiB |
| 전체(4개월) | 120 | 240,000 | 5.9초 | 206 MiB |

- 대략 **레코드당 0.9 KiB, 24 µs** 선형으로 늘어납니다.
- PHP 기본 `memory_limit=128M`에서는 **약 14만 건**을 넘기면 조회가 실패합니다.
  실패하면 페이지가 중간에서 잘립니다(치명적 오류). 전체 조회를 상시로 쓸 계획이면
  `memory_limit`을 넉넉히 주거나 오래된 로그를 정리하세요(§6 로그 정리).
- 메모리의 대부분은 행 목록이 아니라 **요약 집계**(고유 IP/방문자별 버킷)가 차지합니다.
  그래서 필터를 걸어 행 수를 줄여도 메모리는 거의 줄지 않습니다.
  요약은 표본이 아니라 기간 전체를 세기 때문입니다.
- 깊은 페이지로 갈수록 유지해야 하는 행이 늘어 메모리가 조금 더 듭니다
  (50페이지 = 약 +50 MiB). 기간을 좁히면 바로 줄어듭니다.
- 같은 원본 로그 파일을 두 번 읽지 않는 것은 테스트로 강제됩니다
  (`viewer-date-range-test.php`).

---

## 14. AdSense 집계 보고서와 위험 로그 비교

이 기능은 **클릭 IP 추적기가 아닙니다.** Google 보고서의 일별/페이지별 클릭·노출 집계와 같은 날짜·사이트·경로그룹의 로컬 위험 급증을 비교하고, 그 구간에 활동한 조사 후보를 보여 줍니다.

`config/guard.php` 설정:

```php
'analytics' => array(
    'site_id' => 'example-site',
    'site_domains' => array('www.example.com', '*.example.com'),
    'reporting_timezone' => 'Asia/Seoul',
    'route_groups' => array(
        'topic' => array('/topic.php', '/topic/*', '/new-topic.php'),
    ),
),
```

리다이렉트 목적지가 바뀌어도 의미가 같으면 `site_id`와 `route_group`을 유지합니다. 앱이 안정적인 리다이렉트 규칙 ID를 알고 있으면 광고가 있는 최종 응답에서만 다음을 호출합니다.

```php
ad_guard_set_analytics_context(array(
    'redirect_rule_id' => 'campaign-main',
    'route_group' => 'topic',
));
```

중간 301/302 응답은 광고가 없으면 현재 위험 로그에 세지 않습니다. 리다이렉트 이벤트를 남기려면 광고 위험 점수와 분리된 서버 감사 로그를 사용합니다.

AdSense UI에서 `DATE`, `PAGE_URL`, `CLICKS`, `IMPRESSIONS`, `PAGE_VIEWS`, `ESTIMATED_EARNINGS` 열이 포함된 CSV를 영문 헤더로 내보낸 뒤:

```bash
php adguard/tools/import-adsense-report.php /secure/path/adsense.csv
php adguard/tools/analyze-adsense-risk.php 2026-08-31
```

API는 읽기 전용 OAuth 자격 증명을 환경변수에만 넣고 호출합니다. 토큰과 client secret은 소스/config/로그에 넣지 않습니다.

```bash
export ADSENSE_ACCOUNT='accounts/pub-...'
export ADSENSE_CLIENT_ID='...'
export ADSENSE_CLIENT_SECRET='...'
export ADSENSE_REFRESH_TOKEN='...'
php adguard/tools/fetch-adsense-report.php 2026-08-01 2026-08-31
php adguard/tools/analyze-adsense-risk.php 2026-08-31
```

분석 판단:

- `CORRELATED_AGGREGATE_ANOMALY`: CTR과 로컬 고위험 비율이 함께 급증. 사건 조사 시작점이지 클릭자 증거는 아님.
- `ROTATING_IP`: 같은 Visitor HMAC가 둘 이상의 IP HMAC에서 관찰됨. 이동망/VPN도 가능.
- `STRONG_LOCAL_BEHAVIOR`: 자동화 UA 또는 매우 강한 단일 Visitor 속도 신호.
- `SHARED_IP_CAUTION`: 한 IP에 여러 Visitor가 있어 NAT/CGNAT 가능성이 큼.
- `DISTRIBUTED_BEHAVIOR_PATTERN`: IP/cookie가 바뀌어도 같은 유입그룹·국가·UA 계열·신호 조합이 반복됨. 공격 확정이 아닌 방어 규칙 후보.

자동 차단은 AdSense 클릭 집계가 아니라 직접·반복된 로컬 행동 신호로만 합니다.

---

## 15. 더 읽을거리

- **공식 통합 배포·운영 기준:** `adguard/docs/ADSENSE-RISK-ENGINE-GUIDE.md` (Part I=배포, Part II=운영)
- **Viewer 현장용 요약:** `adguard/docs/ADSENSE-RISK-VIEWER-CHEATSHEET.md`
