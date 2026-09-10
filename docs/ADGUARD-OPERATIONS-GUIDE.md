# AdGuard 운영 · 이식 가이드

이 문서는 **`adguard/` 폴더를 다른 PHP 프로젝트로 옮겨 설치하고 운영**하기 위한 것입니다.
현재 프로젝트(`example-site`)의 내부 구조를 전혀 몰라도 이 문서와 `adguard/` 폴더만으로 설치할 수 있도록 썼습니다.

이 문서의 모든 수치와 판정은 **실제 코드 실행·테스트 결과**에서 나왔습니다.
검증하지 못한 항목은 `NOT VERIFIED`로 표시했습니다.

- 측정 환경: PHP 8.5.5 (CLI), Windows. 지원 목표는 PHP 5.6 이상.
- 검증 일자: 2026-09-04

---

## Part I — 10분 설치

### 0. 이 패키지가 하는 일 / 못 하는 일

**하는 일**: HTTP 응답 HTML에서 **실행 가능한 AdSense 공통 로더(bootstrap)를 내보낼지 말지**를 요청 단위로 판정하고, 그 판정을 감사 로그로 남깁니다.

**못 하는 일** (구조적 제약이므로 설정으로 해결되지 않음):

- 광고 **노출·클릭·수익**을 알 수 없습니다. 서버는 광고 iframe 내부를 관찰하지 못합니다.
- 따라서 **"이 클릭은 이 IP가 했다"는 귀속(attribution)을 하지 않습니다.** 뷰어의 "로더 제공"은 *최종 HTML에 로더가 남아 있었다*는 뜻일 뿐입니다.
- **IP는 사람이 아닙니다.** CGNAT·회사·학교·PC방·VPN 뒤에서 수십 명이 한 IP를 공유합니다. 이 패키지의 IP 기반 신호가 단독으로 차단하지 못하도록 막혀 있는 이유입니다(`tests/policy-safety-test.php`가 강제).

### 1. 폴더 복사

```bash
cp -r /기존프로젝트/adguard /새프로젝트/adguard
```

복사 **직후 반드시 지워야 하는 것** (이전 사이트의 비밀·운영 데이터):

```bash
rm -rf /새프로젝트/adguard/storage/engine    # 이전 사이트 방문자 카운터
rm -rf /새프로젝트/adguard/storage/logs      # 이전 사이트 감사 로그 (개인정보)
rm -f  /새프로젝트/adguard/storage/.hmac-key # 이전 사이트 가명화 키
```

세 가지 모두 **자동으로 다시 생성**되므로 지워도 기능에 문제가 없습니다.
`.hmac-key`를 그대로 옮기면 두 사이트의 가명 식별자가 서로 연결되어 버리므로, 재사용할 이유가 없다면 반드시 새로 만드십시오.

### 2. 설정 파일 정리

`adguard/config/guard.php`는 **이전 사이트의 설정이 그대로 따라옵니다.** 반드시 새로 쓰십시오.

새 프로젝트용 최소 설정:

```php
<?php
return array(
    'mode' => 'monitor',                       // 처음에는 반드시 monitor
    'identity' => array(
        // reverse proxy를 쓰지 않으면 빈 배열 유지
        'trusted_proxies' => array(),
    ),
    'excluded_paths' => array(
        '/adguard/viewer.php',                 // 뷰어가 놓인 실제 경로로 수정
    ),
    'analytics' => array(
        'site_id' => '',                       // 예: 'mysite'
        'reporting_timezone' => 'Asia/Seoul',  // 뷰어·요약이 쓰는 기준 시간대
    ),
    'viewer' => array(
        'allowed_ips' => array(),              // 비우면 전부 거부(기본 안전값)
    ),
);
```

리버스 프록시를 쓰는 경우 같은 `trusted_proxies` 목록을
`adguard/config/engine.php`에도 설정해야 엔진 bucket과 v3 logger가 같은
client identity를 사용합니다. 임의의 `X-Forwarded-For` 값은 목록이 비어 있으면 무시됩니다.

> `viewer.allowed_ips`를 비워두면 뷰어는 **모두 404**입니다. 이건 정상 동작입니다.
> 접속해 보면 404 화면이 현재 접속 IP를 알려주므로, 그 값을 넣으면 열립니다.

### 3. 저장소 쓰기 권한

```bash
mkdir -p /새프로젝트/adguard/storage
chmod 700 /새프로젝트/adguard/storage
# 웹서버 실행 계정이 소유하도록
chown <웹서버계정> /새프로젝트/adguard/storage
```

하위 디렉터리(`logs/`, `engine/`)와 `.htaccess`·`index.html` 가드는 **자동 생성**됩니다.

### 4. 저장소 웹 노출 차단 — 서버 종류에 따라 다름 ⚠️

패키지는 `storage/`에 `.htaccess`와 IIS용 `web.config`를 자동으로 넣지만,
서버 밖 경로가 가능하면 그 방식을 우선하십시오. nginx는 아래 `location` 차단이 필요합니다.

| 웹서버 | `.htaccess` | 필요한 작업 |
|---|---|---|
| Apache (`AllowOverride` 허용) | 동작 | 없음 |
| **nginx** | **무시됨** | 아래 `location` 블록 **필수** |
| PHP 내장 서버 | 무시됨 | 개발용으로만 사용 |

```nginx
location ~ ^/adguard/storage/ { deny all; return 404; }
```

**실측 확인**: `.htaccess`를 읽지 않는 서버에서는 원본 JSONL 로그와 `.hmac-key`가
`http://.../adguard/storage/logs/ad-guard-YYYY-MM-DD.jsonl` 로 **HTTP 200으로 그대로 내려받아집니다.**
설치 후 반드시 아래를 실행해 403/404가 나오는지 확인하십시오.

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://내도메인/adguard/storage/logs/
curl -s -o /dev/null -w "%{http_code}\n" https://내도메인/adguard/storage/.hmac-key
```

가능하다면 `storage`를 웹 루트 밖으로 옮기는 편이 더 안전합니다
(`config/engine.php`의 `storage.path`, `config/guard.php`의 `logging.path`).

### 5. 실행 지점 연결 — 둘 중 하나

**방법 A. 사이트 전체 자동 적용 (`auto_prepend_file`)**

경로를 직접 타이핑하지 말고 아래로 뽑으십시오:

```bash
php /새프로젝트/adguard/tools/print-install-config.php
```

출력된 한 줄을 `.user.ini`(PHP-FPM/FastCGI) 또는 pool/vhost 설정에 넣습니다:

```ini
auto_prepend_file=/ABSOLUTE/PATH/TO/PROJECT/adguard/auto-prepend.php
```

> PHP-FPM은 `user_ini.cache_ttl`(보통 300초) 때문에 반영이 최대 5분 늦을 수 있습니다.

**방법 B. 코드에서 명시적으로 호출**

```php
require_once '/절대경로/adguard/adguard.php';
ad_guard_boot();               // 출력이 시작되기 전에
```

또는 광고 출력 지점에서 직접 물어보는 방식:

```php
if (ad_guard_ads_allowed()) {
    // 구글이 준 광고 코드를 그대로 출력
}
```

### 6. 동작 확인 (스모크 테스트)

```bash
php /새프로젝트/adguard/tests/ad-guard-test.php
php /새프로젝트/adguard/tests/viewer-date-range-test.php
```

브라우저로 광고가 있는 페이지를 몇 번 열어 본 뒤:

```bash
ls -l /새프로젝트/adguard/storage/logs/
```

`ad-guard-YYYY-MM-DD.jsonl`이 생겼으면 정상입니다. 이어서 뷰어를 엽니다:
`https://내도메인/adguard/viewer.php`

### 7. 문제가 생기면 — 되돌리는 순서

| 증상 | 조치 |
|---|---|
| 광고가 과하게 막힘 | `config/guard.php`의 `mode`를 `monitor`로 |
| 그래도 이상함 | `mode`를 `off`로 (패키지 완전 우회) |
| **모든 페이지가 500 / 백지** | 아래 참조 — `mode=off`로 해결되지 않습니다 |

> ### ⚠️ `mode=off`로 복구되지 않는 유일한 경우
>
> `auto_prepend_file` 자체가 실패하는 상황(경로 오타, 파일 누락, parse error, require 실패)에서는
> **AdGuard의 설정 파일을 읽기도 전에** PHP가 죽습니다. 이때는 `mode`를 어떻게 바꿔도 소용없습니다.
>
> 복구 방법은 **`auto_prepend_file` 지시자 자체를 제거**하는 것뿐입니다
> (`.user.ini`에서 그 줄을 지우고, PHP-FPM 캐시 TTL만큼 기다리거나 FPM을 재시작).
>
> 그래서 첫 설치는 **방법 B(코드 호출)로 먼저 검증한 뒤** 방법 A로 넘어가는 편이 안전합니다.

### 8. 완전 제거

1. `auto_prepend_file` 지시자 삭제 (또는 `ad_guard_boot()` 호출 삭제)
2. 프로젝트 어댑터에서 `require .../adguard/adguard.php` 삭제
3. `adguard/` 폴더 삭제

---

## Part II — 구조

### 2.1 Runtime lifecycle (실제 호출 순서)

```
HTTP 요청
  └─ auto-prepend.php            (auto_prepend_file 로 설정된 경우)
       └─ adguard.php            공개 함수 6개 정의
            └─ ad_guard_boot()
                 └─ AdGuard\Guard::start()
                      ├─ isActive()        enabled && mode!=off
                      ├─ isExcludedPath()  excluded_paths 확인
                      └─ ob_start([Guard,'filterOutput'])
  └─ 프로젝트 페이지 실행 (HTML 생성)
  └─ 응답 종료 → filterOutput(FINAL)
       └─ Guard::processHtml()
            ├─ AdsenseDetector::containsAdsense()   광고 없으면 여기서 끝(로그도 없음)
            ├─ AdsenseDetector::inventory()         슬롯·DOM 순번 목록화
            ├─ Guard::getDecision()
            │    └─ risk_engine_evaluate()          ← 판정은 engine/ 이 담당
            │         └─ RiskEngine\Engine::run($ctx, mutate=true)
            │              ├─ UserAgentAnomalySignal
            │              ├─ RateLimitSignal        (IP 단위)
            │              ├─ VisitorRateSignal      (방문자 쿠키 단위)
            │              ├─ SessionChurnSignal     (IP 단위)
            │              └─ ScoreCombiner → Verdict(level, score, reasons)
            ├─ (mode=enforce && DENY) AdsenseDetector::removeBootstrap()
            └─ DecisionLogger::log()                 JSONL 1줄 append
```

핵심 설계: **`engine/`은 광고를 전혀 모릅니다.** 그 경계는 `engine/tests/boundary-check.php`가 자동으로 강제합니다(현재 14개 파일 스캔, 위반 0).

### 2.2 판정 → 조치

| mode | DENY 판정일 때 | HTML |
|---|---|---|
| `enforce` | 로더 제거 | 광고 로더만 제거, 본문·다른 스크립트는 보존 |
| `monitor` | 기록만 (`MONITOR_DENY`) | **변경 없음** |
| `off` | 판정 자체를 안 함 | 변경 없음 |

**본문 콘텐츠는 어떤 경우에도 차단하지 않습니다.** 판정이 불확실하면 광고만 숨기고 페이지는 정상 제공합니다(`policy.fail_closed`).

`removeBootstrap()`은 정규식 실패(거대·비정상 HTML의 backtrack 한계) 시 **원본 HTML을 그대로 반환**합니다. 백지 페이지를 내보내느니 광고가 남는 편이 낫다는 판단이며, 이 동작은 코드 주석에 근거와 함께 남아 있습니다.

### 2.3 신호 4종

| 신호 | 버킷 단위 | 단독 차단 | 이유 |
|---|---|---|---|
| `user_agent` | 요청 | **가능** (≥40) | 클라이언트가 자동화 도구임을 스스로 밝힘 = 직접 증거 |
| `visitor_rate` | 방문자 쿠키 | **가능** (≥100) | 단일 브라우저 프로필 = 정밀도 높음 |
| `rate_limit` | **IP** | 불가 | 공유 IP는 여러 사람. 측정 결과 정상 방문자 25명이 88/SEVERE를 만듦 |
| `session_churn` | **IP** | 불가 | 새 방문자 수를 셈 = 그 IP 뒤 사람 수에 비례. 정상 20명이 52를 만듦 |

IP 단위 신호는 가중치가 0.45로 제한되어 **단독으로는 차단 구간(50)에 도달할 수 없습니다.** 이 불변식은 `tests/policy-safety-test.php`가 자동 검증합니다.

**클라이언트 IP는 기본적으로 `REMOTE_ADDR`을 사용합니다.** `X-Forwarded-For`는
`guard.php`와 `engine.php` 양쪽의 `identity.trusted_proxies`에 명시한 즉시 peer에서만
오른쪽부터 검증해 사용합니다. 그 외의 전달 헤더는 위조 가능한 입력으로 무시합니다.
v3 로그에는 선택된 원본 IP와 canonical/HMAC 값이 함께 저장됩니다.

### 2.4 부작용 있는 API / 없는 API

| 함수 | 카운터 증가 |
|---|---|
| `risk_engine_evaluate()` | **예** — 요청 1건으로 집계됨 |
| `risk_engine_inspect()` | 아니오 — 읽기 전용 (디버그용) |
| `ad_guard_decision()` / `ad_guard_ads_allowed()` | 요청당 1회만 (결과 캐시됨) |

`ad_guard_ads_allowed()`를 한 페이지에서 여러 번 불러도 판정은 1회만 수행됩니다(`tests/ad-defense-bridge-test.php`가 검증).

### 2.5 공개 API (이 6개만 사용할 것)

```php
ad_guard_boot()                              // 응답 감시 시작
ad_guard_ads_allowed()                       // 광고를 내보내도 되는가
ad_guard_decision()                          // 판정 전체
ad_guard_mark_ad_opportunity()               // 이 응답은 광고 기회였다고 표시
ad_guard_note_external_suppression($reason)  // 다른 이유로 막았음을 로그에 기록
ad_guard_set_analytics_context($context)     // route_group 등 안정적 라벨
```

`\AdGuard\*` 클래스를 프로젝트 코드에서 직접 참조하지 마십시오. 업그레이드 시 깨집니다.

---

## Part III — 이식성 (Portability Contract)

### 3.1 검증 방법

`adguard/` 폴더만 빈 프로젝트에 복사하고(운영 storage·비밀키 제외), 최소 PHP 페이지 하나만 둔 상태에서 전 테스트를 실행했습니다.

**결과: 부팅 성공, 15개 스위트 중 14개 통과, 1개는 의도적으로 skip.**

| 항목 | 결과 |
|---|---|
| `adguard/adguard.php` require → 페이지 렌더 | PASS (fatal 없음) |
| `storage/`·`logs/`·`.htaccess` 자동 생성 | PASS |
| `.hmac-key` 자동 생성 | PASS |
| 설정 로드 | PASS |
| `viewer.php` 단독 로드 (거부·허용 양쪽) | PASS |
| 판정 API 동작 | PASS |
| 패키지 테스트 14종 | PASS |
| `ad-defense-bridge-test.php` | **SKIP** (호스트 어댑터 전용) |

### 3.2 Dependency Matrix

| 의존성 | 분류 | 필수 | 현재 구현 | 이식성 | 새 프로젝트에서 할 일 |
|---|---|---|---|---|---|
| `adguard/src/*`, `engine/*`, `config/*` | Self-contained | Y | `__DIR__` 상대 require만 사용 | **그대로 동작** | 없음 |
| `storage/` 자동 생성 | Self-contained | Y | `DecisionLogger::provisionDirectory()` | 그대로 동작 | 쓰기 권한만 |
| PHP 5.6+ | Installation | Y | 정적 검사기 내장 | 조건부 | PHP 버전 확인 |
| `storage/` 쓰기 권한 | Installation | Y | 자동 `mkdir 0700` | 조건부 | 소유자·권한 설정 |
| **`storage/` 웹 차단** | Installation | Y | `.htaccess` 자동 생성 | **Apache만** | **nginx는 `location` 블록 필수** |
| `auto_prepend_file` | Installation | N (방법 B면 불필요) | `.user.ini` 절대경로 | 서버마다 다름 | 절대경로 재설정 |
| `reporting_timezone` | Installation | Y | 기본 `Asia/Seoul` | 조건부 | 사이트 기준 시간대로 |
| `viewer.allowed_ips` | Installation | Y | 기본 빈 배열 = 전부 거부 | 조건부 | 운영자 IP 등록 |
| **광고 로더 출력 지점** | **Project integration** | Y | 프로젝트가 출력, AdGuard가 필터 | **프로젝트마다 다름** | 어댑터 또는 API 호출 |
| **광고 slot / publisher ID** | **Project integration** | Y | AdGuard는 **모름** (HTML에서 탐지) | 해당 없음 | 프로젝트가 관리 |
| 방문자 쿠키 이름 | Installation | Y | `logging.visitor_cookie_name` (기본 `__rek_id`) | 조건부 | 엔진 설정과 **일치시킬 것** |
| `include/ad-defense.php` | **Project integration** | N | 이 프로젝트 전용 어댑터 | **복사 대상 아님** | 없음 (또는 직접 작성) |
| AdSense OAuth 자격증명 | Installation (선택) | N | 환경변수만 사용 | 조건부 | 선택 기능 |

### 3.3 절대 복사하면 안 되는 것

| 대상 | 이유 |
|---|---|
| `storage/.hmac-key` | 가명화 비밀키. 재사용하면 두 사이트의 방문자 가명이 서로 연결됨 |
| `storage/logs/*.jsonl` | 실제 방문자 보안 텔레메트리(개인정보) |
| `storage/engine/**` | 이전 사이트 방문자·IP 카운터 |
| `config/guard.php`의 `viewer.allowed_ips` | 이전 운영자 IP가 새 사이트 로그를 열람하게 됨 |
| `ADSENSE_*` 환경변수 | OAuth 토큰·클라이언트 시크릿 |

> **현재 저장소 상태 주의**: `adguard/config/guard.php`에는 이 사이트 운영자의 실제 IP가,
> `adguard/storage/.hmac-key`에는 실제 가명화 키가, `storage/`에는 실제 운영 로그
> (JSONL 6개, 엔진 카운터 199개)가 **버전 관리 트리 안에 그대로 들어 있습니다.**
> 폴더를 통째로 복사·공유할 때 반드시 §1의 삭제 절차를 먼저 수행하십시오.

### 3.4 Core는 프로젝트를 모른다 — 확인된 사실

`adguard/` 안에서 **폴더 밖을 참조하는 코드는 단 한 곳**이며, 그것도 테스트입니다:

```
adguard/tests/ad-defense-bridge-test.php → ../../include/ad-defense.php
```

이 테스트는 **패키지가 아니라 이 프로젝트의 어댑터**를 검사합니다. 어댑터가 없으면
실패하지 않고 `skip:`으로 건너뛰도록 되어 있습니다.

Core 소스(`src/`, `engine/src/`, `viewer.php`, `adguard.php`)에는
`DOCUMENT_ROOT`, `SCRIPT_FILENAME`, 프로젝트 경로, publisher ID, slot ID가 **전혀 없습니다.**
광고 슬롯은 설정이 아니라 **응답 HTML에서 탐지**하므로, 슬롯 목록을 새로 등록할 필요가 없습니다.

### 3.5 Integration 방식 판정

이 프로젝트는 **A(출력 필터) + B(명시적 API) 혼합**입니다.

- A: `ad_guard_boot()`이 `ob_start()`로 응답을 감시하다가 DENY면 로더 제거
- B: 프로젝트 어댑터가 `ad_guard_decision()`을 물어 스스로 출력을 억제하고, `ad_guard_note_external_suppression()`으로 로그에 남김

새 프로젝트는 **A만으로도 동작합니다**(코드 수정 없이 `auto_prepend_file`만 설정).
B는 "광고 자리에 대체 콘텐츠를 넣고 싶다" 같은 경우에만 추가하면 됩니다.

---

## Part IV — 뷰어

`https://내도메인/adguard/viewer.php` · **기본 전부 거부(404)**

### 4.1 조회 단위 4가지

| 단위 | URL | 의미 |
|---|---|---|
| 특정 날짜 | `?date_mode=day&date=2026-09-04` | 그 하루 (**기본값**) |
| 전체 날짜 | `?date_mode=all` | 보관 중인 모든 로그 |
| 기간 | `?date_mode=range&start_date=2026-08-01&end_date=2026-09-04` | 양 끝 **모두 포함** |
| 월 | `?date_mode=month&month=2026-08` | 1일 ~ 말일 (달력 계산) |

- 파라미터가 없으면 **예전과 동일하게 오늘 하루**입니다. 기존 `?date=YYYY-MM-DD` 링크도 그대로 동작합니다.
- JavaScript 없이도 폼 제출만으로 전부 동작합니다. JS는 해당 없는 입력칸을 숨기는 용도뿐입니다.
- 조건을 새로 적용하면 항상 **1페이지**로 돌아가고, 페이지 이동·식별자 드릴다운에서는 **기간이 유지**됩니다.

### 4.2 시간대 — 가장 틀리기 쉬운 부분

| 대상 | 시간대 |
|---|---|
| 원본 JSONL **파일명** | **UTC** (`ad-guard-2026-09-04.jsonl`) |
| 각 레코드의 `timestamp` | **UTC** |
| 뷰어 화면·요약·날짜 필터 | `analytics.reporting_timezone` (기본 **KST**) |

KST는 UTC+9이므로 **로컬 하루가 UTC 파일 2개에 걸칩니다.**
따라서 포함 여부는 **파일명이 아니라 각 레코드의 timestamp**로 판정합니다.
파일명만 보고 거르면 하루의 앞뒤 9시간이 통째로 어긋납니다.

같은 이유로, 기간 조회를 "날짜마다 read()를 반복"하는 방식으로 구현하면
경계 파일을 두 번 열어 **레코드가 중복 집계**됩니다. 현재 구현은 로컬 기간을 UTC 파일
집합으로 한 번에 변환하고 **UTC 날짜를 키로 중복을 구조적으로 제거**하므로,
한 파일은 요청당 최대 1회만 열립니다.

**실측 검증** (운영 로그, 2026-08-30 ~ 09-04):

```
일별 합계 = 0 + 8 + 8 + 1948 + 1289 + 5 = 3258
전체 조회                              = 3258   ← 정확히 일치
2026년 8월(8) + 2026년 9월(3250)       = 3258   ← 정확히 일치
기간 08-31~09-02                       = 1964 = 8 + 8 + 1948
```

### 4.3 날짜 입력 검증

- 값을 **주지 않으면** 오늘로 기본값을 씁니다.
- 값을 **줬는데 잘못됐으면** 조용히 오늘로 바꾸지 않고 **오류를 표시**합니다.
  (`2026-02-31`처럼 형식은 맞지만 달력에 없는 날짜도 거부 — `checkdate()`로 검사)
- `start > end`, 없는 월, 알 수 없는 `date_mode`, 배열 주입(`?date[]=`), 경로 순회(`../../etc/passwd`) 모두 거부합니다.
- **미래 날짜는 정상**입니다. 데이터가 없으면 빈 결과가 나옵니다.

**쿼리 문자열은 파일 경로에 절대 연결되지 않습니다.** 파일 목록은 디렉터리 스캔으로
얻고, `ad-guard-YYYY-MM-DD.jsonl` 패턴 + `checkdate()`를 통과한 것만 읽습니다.

### 4.4 요약(summary)의 의미

- **요약은 기간 전체를 셉니다** — 행 필터(등급·경로·식별자)의 영향을 받지 않습니다.
  즉 "경로 필터를 걸었는데 상단 숫자가 안 변한다"는 **정상**입니다.
- "로더 제공"은 최종 HTML에 실행 가능한 로더가 남았다는 뜻이며, **Google이 노출·클릭으로 인정했다는 뜻이 아닙니다.**
- "고유 방문자/IP"는 가명 식별자 기준 **추정치**입니다. 쿠키를 지운 사람은 새 방문자로, 공유 IP 뒤 여러 명은 하나로 집계됩니다.
- 하루보다 넓은 기간에서는 표의 시각이 `YYYY-MM-DD HH:MM:SS`로 **날짜까지** 표시됩니다.
- Top IP/방문자의 "최대 10분 / 1시간"은 **실제 timestamp 차이**로 계산하므로,
  자정을 넘어가도 창이 초기화되지 않습니다(테스트로 검증).

### 4.5 전체 조회 성능 — 반드시 확인 ⚠️

합성 로그 기준 실측값입니다(`php adguard/tools/viewer-benchmark.php 120 2000 128M`).
운영 로그를 복제하지 않고 측정하도록 만든 도구입니다.

| 조회 | 로그 파일 | 레코드 | 시간 | 최대 메모리 |
|---|---:|---:|---:|---:|
| 하루 | 2 | 2,000 | 0.07초 | 4 MiB |
| 7일 | 8 | 14,000 | 0.34초 | 14 MiB |
| 1개월 | 31 | 61,250 | 1.4초 | 52 MiB |
| 전체(4개월) | 120 | 240,000 | 5.9초 | 206 MiB |

- 대략 **레코드당 0.9 KiB · 24 µs** 선형 증가.
- **PHP 기본 `memory_limit=128M`에서는 약 14만 건에서 조회가 실패합니다.**
  실패 시 페이지가 중간에서 잘립니다(PHP fatal). 전체 조회를 상시로 쓰려면
  `memory_limit`을 늘리거나 오래된 로그를 정리하십시오.
- **중복 파일 읽기: 없음** (도구가 매 실행마다 검사).

**메모리 구조 (무엇이 메모리를 쓰는가)**

| 구조 | 증가 차원 | 비고 |
|---|---|---|
| IP·방문자별 활동 버킷 | **O(고유 actor 수)** | **지배적** |
| 고유 IP/방문자 집합 | O(고유 actor 수) | 위와 중복되는 키 |
| actor별 timestamp 이력 | O(레코드 수) | uint32로 **패킹**해 보관 |
| 표시할 행 tail | O(offset + page_size) | 페이지 깊이에만 비례 |
| 슬롯별 집계 | O(경로 × 슬롯 종류) | 작음 |

메모리 대부분은 행 목록이 아니라 **요약 집계**가 차지합니다. 그래서 필터로 행을 줄여도
메모리는 거의 줄지 않습니다 — 요약은 표본이 아니라 기간 전체를 세기 때문입니다.
기간을 좁히는 것만이 효과가 있습니다.

**통계를 훼손하지 않은 최적화만 적용했습니다**: actor timestamp는 버리지 않고
PHP 배열 대신 uint32 문자열로 담아 같은 값·같은 정렬·같은 rolling window 결과를 유지합니다
(측정: 60,000건 기준 최대 메모리 58.6 → 48.8 MiB).

**알려진 확장 한계**: 수백만 건 규모의 전체 조회는 현재 구조로는 감당하지 못합니다.
그 규모가 필요해지면 일별 사전 집계(rollup)가 필요하며, 이는 지금 넣지 않았습니다
(현재 아키텍처로 해결되는 범위를 넘어서는 설계 변경이므로).

---

## Part V — 운영

### 5.1 모드 전환 순서

```
monitor (최소 1~2주, 실제 트래픽 관찰)
   ↓  MONITOR_DENY 목록을 뷰어에서 검토
   ↓  정상 방문자가 섞여 있지 않은지 확인
enforce
```

`monitor`에서 `MONITOR_DENY`로 잡힌 응답을 뷰어에서 하나씩 보고,
**정상 사용자로 보이는 것이 있으면 임계값을 올리십시오.** 그대로 `enforce`로 넘기면 그 사용자들이 광고를 잃습니다.

### 5.2 오탐(false positive) 대응

증상: 특정 IP/방문자가 계속 `MONITOR_DENY`인데 행동이 평범함.

1. 뷰어에서 그 식별자를 드릴다운 → "최대 10분/1시간" 밀도 확인
2. `rate_limit` / `session_churn`만 떠 있다면 **공유 IP일 가능성이 큽니다** (PC방·회사·CGNAT)
3. `config/engine.php`에서 해당 신호의 `windows` / `threshold`를 올립니다
4. **가중치(weight)는 올리지 마십시오** — IP 단위 신호가 단독 차단 구간에 닿게 되어 안전 불변식이 깨집니다(`policy-safety-test.php`가 실패로 알려줍니다)

### 5.3 광고 수익이 급감했을 때 판단 순서

1. 뷰어 → **정책 차단률**을 봅니다.
   - 높다 → AdGuard가 원인. `mode=monitor`로 내리고 임계값 조정
   - 낮다/0 → AdGuard가 원인이 아님. 아래로
2. **"구현 누락(missing)"** 숫자를 봅니다. 높으면 광고 코드가 애초에 출력되지 않은 것 → 프로젝트 템플릿 문제
3. 둘 다 정상이면 AdSense 계정·정책·시장 요인 쪽입니다. 이 패키지는 노출·수익을 볼 수 없습니다.

### 5.4 로그 정리

로그는 **자동 삭제되지 않습니다.** 하루 상한은 `logging.max_daily_bytes`(기본 20 MiB)이며, 초과하면 그날 로깅만 멈추고 **광고 정책은 계속 동작합니다**(디스크 고갈 공격 방지).

```bash
find /프로젝트/adguard/storage/logs -name 'ad-guard-*.jsonl' -mtime +90 -delete
```

엔진 카운터(`storage/engine/`)는 요청의 1% 확률로 인라인 GC가 돌아 2일 지난 항목을 지웁니다. 별도 cron이 필요 없습니다.

### 5.5 500 에러 / 백지 복구

Part I §7의 표를 따르십시오. 요점만:

- **Guard가 로드된 뒤의 문제** → `mode`를 `monitor` → `off` 로 완화 가능
- **`auto_prepend_file` 자체의 실패** → `mode`로 복구 **불가**. 지시자를 제거해야 함

---

## Part VI — 다른 프로젝트로 옮기기 (체크리스트)

```
[ ] adguard/ 폴더 복사
[ ] storage/engine/ 삭제        (이전 사이트 카운터)
[ ] storage/logs/ 삭제          (이전 사이트 개인정보)
[ ] storage/.hmac-key 삭제      (이전 사이트 비밀키)
[ ] config/guard.php 새로 작성  (allowed_ips 비우기, mode=monitor)
[ ] config/engine.php 는 필요할 때만 생성 (없어도 기본값으로 동작)
[ ] storage/ 쓰기 권한 + 소유자 설정
[ ] 웹 차단 확인: storage/ 가 HTTP로 안 열리는지 curl 로 확인
        (nginx 는 location 블록 추가 필수)
[ ] 실행 지점 연결 (auto_prepend_file 또는 ad_guard_boot())
[ ] reporting_timezone 을 사이트 기준으로
[ ] 방문자 쿠키 이름을 엔진 설정과 일치시키기
[ ] 스모크 테스트 실행
[ ] 광고 페이지 방문 → 로그 파일 생성 확인
[ ] viewer.allowed_ips 에 운영자 IP 등록 → 뷰어 확인
[ ] monitor 로 1~2주 관찰
[ ] MONITOR_DENY 검토 후 enforce 전환 판단
```

### 검증 명령 모음

```bash
# 패키지 테스트 (호스트 어댑터가 없으면 bridge 테스트는 skip 됩니다)
php adguard/tests/ad-guard-test.php
php adguard/tests/policy-safety-test.php
php adguard/tests/viewer-timezone-test.php
php adguard/tests/viewer-date-range-test.php
php adguard/tests/adsense-correlation-test.php
php adguard/tests/auto-prepend-integration-test.php
php adguard/engine/tests/boundary-check.php
php adguard/engine/tests/combined-verdict-test.php
php adguard/engine/tests/concurrency-storage-test.php

# PHP 5.6 정적 호환성
php adguard/tools/php56-check.php adguard/src adguard/engine/src adguard/viewer.php

# 설치용 절대경로 출력
php adguard/tools/print-install-config.php

# 전체 조회 성능 (합성 로그, 운영 로그 미사용)
php adguard/tools/viewer-benchmark.php 120 2000 128M
```

---

## 부록 — 검증 상태

| 항목 | 상태 |
|---|---|
| `adguard/` 단독 복사 부팅 | **PASS** (격리 프로젝트에서 실측) |
| 패키지 테스트 14종 격리 실행 | **PASS** |
| 뷰어 4모드 (day/all/range/month) | **PASS** (단위 테스트 + HTTP) |
| 중복 원본 로그 읽기 | **없음** (테스트로 강제) |
| 일별 합계 = 전체 조회 합계 | **PASS** (운영 로그 3258건 일치) |
| 페이지네이션·드릴다운 기간 유지 | **PASS** (HTTP 확인) |
| 시간대 경계(UTC↔KST) | **PASS** |
| PHP 5.6 정적 호환성 | **PASS** |
| **실제 PHP 5.6 런타임** | **NOT VERIFIED** (5.6 바이너리 없음. 정적 검사만 수행) |
| 뷰어 접근 제어(기본 거부) | **PASS** (빈 allowlist → 404 실측) |
| `storage/` 웹 차단 | **Apache: 미검증 / `.htaccess` 무시 서버: 노출 확인됨** |
| 브라우저 UI (모드 전환·JS 없음 동작) | **PASS** |
| 전체 조회 성능·메모리 | **측정 완료** (Part IV §4.5) |
| 수백만 건 규모 | **NOT VERIFIED** — 현재 구조의 한계로 명시 |
