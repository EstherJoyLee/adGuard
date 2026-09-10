# AdSense Risk Engine 통합 배포·운영 가이드

> 이 파일이 설치부터 운영·Viewer 해석·장애 대응까지의 단일 기준 문서입니다. 원본 코드의 사실은 OBSERVED/STATIC ANALYSIS/INFERENCE/NOT VERIFIED로 구분하고, 기능 상태는 IMPLEMENTED/PARTIAL/NOT IMPLEMENTED/RECOMMENDED로 표시합니다.

## 문서 사용 순서

```text
Part I  배포 → 설정 → Integration → Browser/Log 검증
Part II 운영 → Viewer 해석 → Daily/Weekly → Threshold → Incident/Rollback
```

현장용 한 페이지 요약은 [Viewer Cheat Sheet](ADSENSE-RISK-VIEWER-CHEATSHEET.md)를 사용합니다.

# Part I — 배포·통합·검증

> 감사 기준일: 2026-08-31  
> 대상 구현: `adguard/` 패키지와 현재 `example-site` 통합 코드  
> 결론 표기: **OBSERVED**(실행으로 확인), **STATIC ANALYSIS**(소스 확인), **INFERENCE**(확인된 구조에서 도출), **NOT VERIFIED**(검증하지 못함)  
> 기능 표기: **IMPLEMENTED**, **PARTIAL**, **NOT IMPLEMENTED**, **RECOMMENDED**

## 1. 가장 짧은 답

새 PHP 프로젝트에는 원칙적으로 **`adguard/` 폴더 전체를 그대로 복사**하고, 프로젝트별로 `adguard/config/guard.php`만 조정한 뒤 PHP의 `auto_prepend_file`을 새 프로젝트의 절대경로 `adguard/auto-prepend.php`에 연결한다. 임계값을 바꿀 때만 `adguard/config/engine.php`를 추가한다. 기존 페이지의 광고 코드는 바꿀 필요가 없지만, 모든 PHP 응답보다 먼저 전역 hook이 실행되는지 반드시 실제 HTTP/브라우저/로그로 증명해야 한다.

현재 사이트의 `include/ad-defense.php`, `config/ad-defense.php`, `ad-preview*.php`는 사이트별 광고 출력·미리보기 어댑터다. 범용 Core의 필수 구성은 아니다.

## 2. 감사 결과 요약

| 항목 | 상태 | 결론 |
|---|---|---|
| 요청 위험 점수 | IMPLEMENTED | UA, IP 요청률, 방문자 요청률, IP별 신규 방문자 수 4개 신호 |
| 광고만 차단 | IMPLEMENTED | HTTP 403이 아니라 실행 가능한 `adsbygoogle.js` 부트스트랩만 제거/미출력 |
| Manual Ads | IMPLEMENTED | 모든 수동 유닛이 공유하는 부트스트랩을 게이트함 |
| Auto Ads | IMPLEMENTED | 수동 `<ins>`가 없어도 부트스트랩을 게이트함 |
| Future Ads | PARTIAL | 같은 공식 부트스트랩을 사용하는 형식은 자동 포함. URL/로딩 방식이 바뀌면 detector 수정 필요 |
| Shadow mode | IMPLEMENTED | 이름은 `mode=monitor`; 정책상 차단은 `MONITOR_DENY`, 실제 광고는 허용 |
| JSONL 로그 | IMPLEMENTED | UTC 일별 파일, 광고 감지 응답만 기록, 원본 IP/쿠키/UA 미저장 |
| Viewer | PARTIAL | 서버 측 reader·마스킹·필터 구현. 로그인 인증은 없고 정확한 IP allowlist만 있음 |
| identity cookie 상태 관측 | PARTIAL | 조기 hook에서는 정상 발급. headers-sent 실패는 churn count를 안전하게 생략하지만 최종 AdGuard 로그의 `degraded`에는 드러나지 않음 |
| PHP 5.6 소스 호환 | STATIC ANALYSIS PASS | 배포 코드 21개에서 PHP 7+ 전용 구문/함수/클래스 0건. 실제 PHP 5.6 실행은 NOT VERIFIED |
| Proxy/Cloudflare 실제 IP | NOT IMPLEMENTED | `REMOTE_ADDR`만 사용. 웹서버가 먼저 실제 IP를 복원해야 함 |
| 자동 로그 보존 | NOT IMPLEMENTED | 일별 분할·20MB 상한만 있음. 만료 삭제는 cron/운영 작업 필요 |
| 장기/분산/ASN 분석 | NOT IMPLEMENTED | 현재는 짧은 고정 시간창과 일별 viewer뿐 |

가장 중요한 위험은 두 가지다.

1. 기본 임계값은 실제 서비스 트래픽으로 검증되지 않았다. 합성 브라우저에서 2~3초 동안 다중 이동·탭·재요청이 겹치자 한 방문자의 점수가 100까지 올라 `MONITOR_DENY` 7건이 발생했다. **처음에는 반드시 `monitor`**로 운영한다.
2. `auto_prepend_file` 자체의 include/parse fatal, PHP 7/8의 `Error` 계열은 `catch (Exception)`으로 모두 복구되지 않는다. “광고 방어 오류가 절대로 본문을 죽이지 않는다”는 절대 보장은 없다. 배포 전 lint와 실제 HTTP smoke test, 즉시 `off` 전환 절차가 필요하다.

## 3. 실제 관련 파일 구조

```text
project/
├─ .user.ini                         # 이 사이트의 전역 PHP hook 예시
├─ adguard/                           # 범용 패키지
│  ├─ adguard.php                     # 공개 API와 Guard singleton
│  ├─ auto-prepend.php                # auto_prepend_file 진입점
│  ├─ viewer.php                      # IP 제한 운영 Viewer
│  ├─ README.md
│  ├─ config/
│  │  ├─ guard.php                    # 프로젝트별 게이트/로그/Viewer 설정
│  │  └─ engine.php.example           # 임계값 override 예시
│  ├─ src/
│  │  ├─ Config.php
│  │  ├─ AdsenseDetector.php
│  │  ├─ Guard.php
│  │  ├─ DecisionLogger.php
│  │  └─ LogReader.php
│  ├─ engine/
│  │  ├─ risk-engine.php              # 엔진 공개 API
│  │  ├─ src/
│  │  │  ├─ Config.php
│  │  │  ├─ RequestContext.php
│  │  │  ├─ Engine.php
│  │  │  ├─ SignalResult.php
│  │  │  ├─ Verdict.php
│  │  │  ├─ Scoring/ScoreCombiner.php
│  │  │  ├─ Signals/*.php
│  │  │  └─ Storage/*.php
│  │  └─ tests/*.php
│  ├─ storage/                        # 런타임 생성/쓰기 대상
│  │  ├─ .hmac-key
│  │  ├─ engine/<shard>/*.json
│  │  └─ logs/ad-guard-YYYY-MM-DD.jsonl
│  ├─ tests/*.php
│  └─ tools/{report,print-install-config,php56-check}.php
├─ config/ad-defense.php              # 현재 사이트의 publisher/slot 설정
├─ include/
│  ├─ _head.php                       # 현재 사이트 공통 head + 호환 hook
│  ├─ ad-defense.php                  # 사이트 광고 출력 adapter
│  └─ ad-preview-*.php
├─ ad-preview.php                     # 현재 사이트 테스트 UI; 운영 필수 아님
├─ ad-preview-next.php
└─ topic.php, play.php, ...           # 실제 광고 유닛을 가진 페이지
```

DB는 사용하지 않는다. `js/ad-preview.js`와 `css/ad-preview.css`는 미리보기 도구 전용이며 위험 판정/차단 Core 의존성이 아니다.

## 4. Deployment Manifest

### A. 반드시 복사할 범용 Core

| 파일/폴더 | 필수 | 책임 | 프로젝트별 수정 | Core 수정 |
|---|---:|---|---:|---:|
| `adguard/adguard.php` | YES | 공개 API, request singleton 생성 | NO | NO |
| `adguard/auto-prepend.php` | YES | 전역 조기 실행 | NO | NO |
| `adguard/src/` | YES | AdSense 감지·정책·HTML 필터·로그 | NO | NO |
| `adguard/engine/risk-engine.php` | YES | 위험 엔진 조립/공개 API | NO | NO |
| `adguard/engine/src/` | YES | context, 신호, 점수, file storage | NO | NO |
| `adguard/storage/` | YES(디렉터리) | 상태·로그·HMAC 키 쓰기 | permission only | - |

`adguard/`를 선택적으로 잘라 복사하는 것보다 폴더 전체 복사가 안전하다. 상대 require 경로와 도구/테스트가 패키지 구조를 전제로 한다.

### B. 프로젝트별 설정

| 파일 | 필수 | 수정 내용 | 비고 |
|---|---:|---|---|
| `adguard/config/guard.php` | YES | `mode`, 제외 경로, Viewer IP; 필요 시 정책/로그 override | 현재 사이트는 `monitor` |
| `adguard/config/engine.php` | NO | 기본 신호 window/weight/threshold override | `engine.php.example`에서 복사 |
| `.user.ini` 또는 FPM/vhost 설정 | YES(전역 방식) | 새 프로젝트의 **절대** `auto_prepend_file` 경로 | 서버 유형별 위치 다름 |
| `config/ad-defense.php` | 현재 사이트만 | publisher ID, slot, 미리보기 | 범용 배포에는 불필요 |

실제 존재하는 주요 설정은 다음과 같다.

- Gate: `enabled`, `mode`, `excluded_paths`, `policy.blocked_levels`, `policy.hard_deny_signals`, 두 `fail_closed` 옵션.
- Logging: `enabled`, `path`, `hmac_key(_path)`, allow/deny sample rate, `max_daily_bytes`.
- Viewer: `enabled`, `allowed_ips`, `page_size`.
- Engine: storage path/GC, 각 signal enabled/weight/window, 방문자 cookie 이름/TTL, level threshold.
- **RECOMMENDED지만 현재 없음:** `trusted_proxies`, `retention_days`, 인증 provider, alert 기준, 장기 aggregate storage.

### C. 기존 프로젝트에서 수정할 Integration 지점

| 방식 | 수정 위치 | 범위 | 권장도 |
|---|---|---|---|
| 전역 hook | `.user.ini`, FPM pool, Apache vhost | 하위 모든 PHP | 권장 |
| 공통 include | 모든 페이지보다 먼저 실행되는 `bootstrap.php`, `common.php`, `header.php` | include한 페이지만 | 대안 |
| 선택적 광고 adapter | 기존 공통 광고 bootstrap 출력 함수 | bootstrap 미출력까지 조기 제어·명확한 로그 | 선택 |

전역 hook과 공통 include가 동시에 있어도 `Guard::start()`가 한 번만 시작하므로 현재 사이트처럼 중복 연결은 기능상 안전하다. 다만 운영 표준은 전역 hook 하나로 통일하는 편이 누락을 줄인다.

### D. 테스트·운영 도구

| 항목 | 배포 | 용도 |
|---|---:|---|
| `adguard/viewer.php` | 권장 | 일별 JSONL 요약·필터·마스킹 표시 |
| `adguard/tools/report.php` | 권장 | SSH 일별 보고서 |
| `adguard/tools/import-adsense-report.php` | 권장 | AdSense UI CSV/API JSON을 보호된 표준 snapshot으로 변환 |
| `adguard/tools/fetch-adsense-report.php` | 선택 | 읽기 전용 OAuth로 AdSense API v2 집계 수집 |
| `adguard/tools/analyze-adsense-risk.php` | 권장 | CTR/risk baseline 이상·Visitor/IP 회전·분산 행동 패턴 비교 |
| `adguard/tools/print-install-config.php` | 권장 | 절대 hook 설정 출력 |
| `adguard/tests/`, `adguard/engine/tests/` | 권장 | 배포 서버 정책/통합 회귀 검사 |
| `ad-preview*.php`, 관련 include/CSS/JS | 선택 | 이 사이트 광고 단위 확인. 배포 후 제거 가능 |
| `adguard/docs/` | 권장 | 인수인계·운영 표준. 범용 패키지에 포함 |

## 5. Core와 사이트별 값의 경계

**STATIC ANALYSIS:** 엔진 Core에는 도메인, publisher ID, slot ID, DB 접속 정보가 없다. 위험 엔진의 기본 storage/threshold는 Core default이며 `config/engine.php`로 override한다. Gate의 기본 로그 경로도 패키지 상대 경로이고 `guard.php`에서 바꿀 수 있다.

현재 사이트의 광고 값(`ca-pub-XXXXXXXXXXXXXXXX`, slot, 고정 크기)은 `config/ad-defense.php`에만 있다. Cloudflare `country`/`ray`는 로깅용 header를 읽지만 위험 점수에는 쓰지 않는다. Cloudflare trusted proxy 설정은 존재하지 않는다.

```text
Site .user.ini / common bootstrap
              ↓
adguard/auto-prepend.php → adguard/adguard.php → AdGuard Guard
                                                    ↓
                                      adguard/engine/risk-engine.php
                                                    ↓
                                    config/engine.php + file storage

Site ad adapter (선택) → ad_guard_ads_allowed() → 동일 request decision
```

## 6. 파일·함수 Dependency

| 파일/함수 | 호출자 | 호출 대상 | 입력 | 출력/Side effect |
|---|---|---|---|---|
| `auto-prepend.php` | PHP SAPI | `ad_guard_boot()` | 현재 HTTP request | output buffer 시작 |
| `adguard.php::ad_guard_instance()` | 공개 API | Config, Guard, risk provider | env/config | request singleton |
| `Guard::start()` | boot | `ob_start(filterOutput)` | URI/config | 최종 HTML 보류 |
| `Guard::getDecision()` | adapter 또는 최종 filter | `risk_engine_evaluate()` | request context | 캐시된 정책 결정 1개 |
| `risk_engine_build()` | evaluate | Config, FileStorage, 4 signals, Engine | engine config | process-local engine singleton |
| `RequestContext` | Engine | `$_SERVER`, `$_COOKIE`, time | REMOTE_ADDR/headers | 정규화된 request features |
| `Engine::run()` | evaluate | 각 Signal, ScoreCombiner | context, mutate=true | Verdict |
| `SessionChurnSignal` | Engine | `Set-Cookie`, FileStorage | IP, `__rek_id` | 신규 identity count/cookie |
| `ScoreCombiner` | Engine | threshold config | signal results | level/score/reasons |
| `Guard` policy | getDecision | blocked level/hard deny/failure rule | verdict | ALLOW/DENY/MONITOR_DENY |
| `AdsenseDetector` | final output filter | exact URL detector/regex removal | full HTML | bootstrap 제거 HTML |
| `DecisionLogger` | Guard final filter | HMAC, JSONL append | decision/response metadata | 일별 log row |
| `LogReader` | `viewer.php` | JSONL parse/filter | Asia/Seoul date/filter | summary/page rows |
| `include/ad-defense.php` | 현재 `_head.php` | Guard API | site config/preview session | bootstrap/push 출력 또는 억제 |

핵심 호출 흐름:

```text
HTTP request
  → PHP auto_prepend_file
  → ad_guard_boot()
  → output buffering
  → host page 실행
  → (선택) ad_defense_render_adsense_bootstrap()
      → ad_guard_ads_allowed()
      → risk_engine_evaluate() [요청당 1회]
  → response FINAL
  → AdSense 포함 여부 탐지
  → 동일 decision 조회
  → enforce DENY면 bootstrap 제거
  → 실제 bootstrap 잔존 여부 계산
  → JSONL 1행 기록
  → 최종 HTML 전송
```

## 7. 판정 규칙

### 신호

| 신호 | 키/기본값 | raw score | 단독 차단 가능성 |
|---|---|---|---|
| `user_agent` | UA/Accept 계열 header | UA 없음/자동화 marker +40, Accept 없음 +15, Language +10, Encoding +5 | raw 40 hard deny 가능 |
| `rate_limit` | IP, 10초 120 / 300초 1200 | 허용량 초과 비율, max 100 | weight 0.45로 단독 max 45; hard deny 아님 |
| `visitor_rate` | `__rek_id`, 10초 8 / 60초 30 / 600초 120 | 허용량 초과 비율, max 100 | weight 1; score 50부터 level 차단, raw 100 hard deny |
| `session_churn` | IP, 600초 신규 identity 40 | 초과 시 40부터 증가, max 100 | weight 0.45로 단독 max 45; hard deny 아님 |

점수는 단순 평균이 아니다. 가장 높은 weighted signal을 기본으로 하고, 추가로 trigger된 각 신호 점수의 25%를 더해 100으로 제한한다. Level은 `0~24 NORMAL`, `25~49 ELEVATED`, `50~74 SUSPICIOUS`, `75~100 SEVERE`다. 기본 광고 차단 level은 `SUSPICIOUS`, `SEVERE`다.

`rate_limit`과 `session_churn`은 CGNAT/회사/학교/카페 공유 IP 오탐을 줄이기 위해 단독 차단 불가다. VPN 여부·ASN·국가 자체는 신호가 아니다.

### TTL/회복

- 10/60/300/600초 **fixed window**가 지나면 해당 count가 다음 평가에서 0/새 window로 리셋된다. 연속 decay가 아니라 경계형 reset이다.
- `__rek_id` 기본 TTL은 1년이다. 쿠키가 지워지면 새 identity로 본다.
- engine state GC는 요청의 1%에서 2일 초과 파일을 제거한다.
- HIGH 이력 자체를 장기 가중하는 기능은 없다.

## 8. AdSense 방어 지점과 광고 형식 범위

현재 구현은 다음 조합이다.

- **D. AdSense bootstrap 자체 차단 — 주 방어점.** `pagead2.googlesyndication.com/pagead/js/adsbygoogle.js` script element를 응답에서 제거한다.
- **C. push 차단 — 현재 사이트 adapter에서 추가 제공.** `ad_defense_render_push()`가 정책 거부면 push를 출력하지 않는다.
- 수동 `<ins>`는 제거하지 않고 inert 상태로 남을 수 있다.
- 페이지/HTTP request 자체는 차단하지 않는다. CSS 숨김 방식도 아니다.

| 광고 형식 | 상태 | 보호 원리 | Runtime 근거 |
|---|---|---|---|
| Display fixed/responsive | IMPLEMENTED | 공통 bootstrap 제거 | 운영형 `topic.php` 정상 3 initialized, 차단 0 initialized |
| In-feed/In-article/Multiplex | IMPLEMENTED | 공통 bootstrap 제거 | STATIC ANALYSIS; 형식별 최신 runtime은 NOT VERIFIED |
| Anchor/Vignette/Side rail/Auto banner | IMPLEMENTED | Auto Ads loader 자체 제거 | Auto-only fixture 정상에서 Google ad iframe 생성, 차단에서 0 |
| Ad intents/기타 Auto Ads | PARTIAL | 같은 loader라면 포함 | INFERENCE; 형식별 runtime NOT VERIFIED |
| 향후 형식 | PARTIAL | 같은 공식 URL을 쓰면 자동 포함 | loader URL/방식 변경 시 detector 업데이트 필요 |

### Manual 0 + Auto Ads 검증

**OBSERVED, 2026-08-31, Codex In-app Chromium, localhost:** 원본 fixture에는 manual `<ins>`가 0개이고 공식 bootstrap만 있었다. 정상 경로에서 bootstrap 1개가 남고 Google 광고 iframe 3개(그중 `googleads.g.doubleclick.net/pagead/ads` 포함)가 생성됐다. 강제 차단 경로에서는 bootstrap 0, 광고 `<ins>` 0, iframe 0, console error 0, 본문 유지였다.

현재 브라우저 제어 API에서 2026-08-31의 `performance` resource 목록은 비어 있어 `adsbygoogle.js` **Network panel 숫자 자체는 NOT VERIFIED**다. 대신 HTTP 응답/DOM bootstrap 1개와 실제 `googleads.g.doubleclick.net/pagead/ads` iframe 1개 생성으로 실행을 관찰했다. 신규 사이트에서는 DevTools Network 숫자를 별도로 캡처한다.

강제 차단 fixture는 모든 level을 차단하도록 설정한 **enforcement-path 테스트**다. 실제 악성 트래픽으로 SUSPICIOUS를 만든 테스트와 동일하다고 과장하면 안 된다. 실제 엔진 HIGH 판정은 단위/통합 테스트에서 검증했고 브라우저에서는 정책 차단 결과를 검증했다.

## 9. Runtime과 서버 요구조건

| 환경 | 필수도 | 결론/조치 |
|---|---:|---|
| PHP | 필수 | 목표 5.6+. 실제 runtime은 PHP 8.5.5에서 OBSERVED; PHP 5.6 runtime NOT VERIFIED |
| PHP output buffering | 코드가 자체 시작 | 전체 HTML을 FINAL까지 메모리에 보류하므로 큰/streaming 응답 주의 |
| File write/flock | 필수 | worker가 `adguard/storage`를 생성·쓰기·잠금 가능해야 함 |
| Session | Core에는 불필요 | 엔진 자체 cookie 사용. 현재 preview adapter만 PHP session 사용 |
| OpenSSL | 선택 | 있으면 strong random token; 없으면 fallback hash 사용 |
| Apache | 지원 | `.htaccess`로 storage web 접근 방지 가능 |
| nginx | 지원 | `.htaccess` 무시. 별도 `location ~ /adguard/storage/ { deny all; }` 필요 |
| PHP-FPM/FastCGI | 지원 | `.user.ini` 또는 pool `php_admin_value`; cache TTL 반영 대기 |
| mod_php | 지원 | vhost/허용 시 `php_value auto_prepend_file ...` |
| Reverse proxy/Cloudflare | PARTIAL | Core는 `REMOTE_ADDR`만 신뢰. 웹서버에서 검증된 proxy IP만 받아 실제 IP 복원 필요 |
| IPv6 | STATIC ANALYSIS 지원 | 문자열/HMAC/hash storage key로 처리. 실제 IPv6 E2E NOT VERIFIED |
| DB | 불필요 | file storage only |

## 10. 신규 프로젝트 설치 절차

아래 `/ABS/PROJECT`와 worker 계정을 실제 값으로 바꾼다.

### STEP 1 — 업로드

```bash
cd /ABS/PROJECT
ls -la adguard/adguard.php adguard/auto-prepend.php
```

### STEP 2 — 처음에는 monitor

```php
// adguard/config/guard.php
<?php
return array(
    'mode' => 'monitor',
    'excluded_paths' => array('/adguard/viewer.php'),
    'viewer' => array('allowed_ips' => array('운영자.공인.IP')),
);
```

Preview를 나중에 삭제할 현재 사이트는 삭제 전까지만 `/ad-preview.php`, `/ad-preview-next.php`를 제외한다.

### STEP 3 — worker와 storage 권한

```bash
ps -eo user,group,comm | grep -E 'php-fpm|apache2|httpd|lsphp' | sort -u
mkdir -p adguard/storage/logs adguard/storage/engine
chown -R WEBUSER:WEBGROUP adguard/storage
chmod 700 adguard/storage adguard/storage/logs adguard/storage/engine
```

현재 운영 서버에서는 worker가 `daemon:daemon`이었고 이 권한 조정 후 로그가 생성됐다. 다른 서버에서 그대로 `daemon`을 가정하지 않는다.

### STEP 4 — 전역 hook

```bash
php adguard/tools/print-install-config.php
```

`.user.ini` 예:

```ini
auto_prepend_file=/ABS/PROJECT/adguard/auto-prepend.php
```

현재 운영 경로 예시는 `/ABSOLUTE/PATH/TO/PROJECT/adguard/auto-prepend.php`다. 반드시 줄 끝 newline을 둔다. `cat .user.ini` 출력 뒤에 shell prompt가 같은 줄에 붙으면 newline이 빠진 것이다.

공통 include 대안은 **어떤 HTML도 출력하기 전** 다음 두 줄이다.

```php
require_once '/ABS/PROJECT/adguard/adguard.php';
ad_guard_boot();
```

### STEP 5 — 웹서버에서 storage 차단

```bash
curl -I https://example.com/adguard/storage/logs/
curl -I https://example.com/adguard/storage/.hmac-key
```

둘 다 403/404여야 한다. nginx는 설정 추가 후 reload가 필요하다.

### STEP 6 — 정적/정책 테스트

```bash
php -l adguard/auto-prepend.php
php adguard/tests/ad-guard-test.php
php adguard/tests/policy-safety-test.php
php adguard/tests/ad-defense-bridge-test.php       # 사이트 adapter가 있을 때
php adguard/tests/auto-prepend-integration-test.php
php adguard/engine/tests/user-agent-signal-test.php
php adguard/engine/tests/rate-limit-signal-test.php
php adguard/engine/tests/visitor-rate-signal-test.php
php adguard/engine/tests/session-churn-signal-test.php
php adguard/engine/tests/combined-verdict-test.php
php adguard/engine/tests/concurrency-storage-test.php
php adguard/engine/tests/boundary-check.php
php adguard/tools/php56-check-selftest.php
php adguard/tools/php56-check.php adguard/src adguard/engine/src adguard/adguard.php adguard/auto-prepend.php adguard/viewer.php
```

`php56-check.php adguard` 전체를 실행하면 검사기의 의도적 known-bad fixture까지 잡으므로 배포 소스 경로만 지정한다.

### STEP 7 — HTTP/로그 smoke test

```bash
php -i | grep -i '^auto_prepend_file\|user_ini'
curl -sS -o /dev/null -w '%{http_code}\n' https://example.com/광고페이지.php
find adguard/storage/logs -type f -name 'ad-guard-*.jsonl' -ls
tail -n 3 adguard/storage/logs/ad-guard-$(date -u +%F).jsonl
php adguard/tools/report.php $(date -u +%F)
```

광고 없는 API는 의도적으로 로그를 만들지 않는다. 반드시 AdSense bootstrap/data-ad-client가 있는 페이지로 확인한다.

### STEP 8 — Browser LOW/HIGH

LOW:

1. 일반 Chromium, 새 페이지 요청.
2. Network에서 `adsbygoogle`, `googlesyndication`, `googleads`, `doubleclick` 필터.
3. 공식 bootstrap 요청 1개를 기대하되 실제 count를 기록.
4. DOM에서 manual unit initialization/Google iframe과 본문을 확인.
5. Console의 ReferenceError/TypeError/adsbygoogle/duplicate/CSP 오류 확인.

HIGH/enforcement:

1. 운영자의 안전한 강제 차단 test config 또는 preview override를 사용한다.
2. **새 요청**으로 다시 연다.
3. bootstrap 및 Google 광고 iframe 0인지 확인한다.
4. CSS 숨김만 된 것이 아니라 script가 응답/DOM에 없는지 확인한다.
5. 본문과 비광고 JS가 정상인지 확인한다.
6. 테스트 직후 원래 `monitor` config로 복구한다.

## 11. 잘못 연결했을 때

| 오류 | 결과 |
|---|---|
| bootstrap 출력 뒤 `ad_guard_boot()` | 이미 출력되거나 buffer 밖인 광고 요청을 막지 못할 수 있음 |
| 일부 header에만 include | 다른 PHP/Auto Ads 페이지는 무방비 |
| 상대 `auto_prepend_file` | 하위 경로/working directory에 따라 fatal |
| 잘못된 절대경로/누락 파일 | 해당 PHP 요청 전체 fatal 가능 |
| worker write 권한 없음 | storage degraded; 기본 정책은 광고 fail-closed, 본문은 계속 시도 |
| headers가 이미 전송됨 | identity cookie 발급 실패; churn 신호는 해당 요청을 세지 않고 degraded note만 남김 |
| proxy에서 REMOTE_ADDR 복원 안 함 | 모든 사용자가 proxy/Cloudflare edge 하나로 합쳐져 심각한 오탐 가능 |
| CDN이 광고 포함 HTML cache | 사용자별 decision을 우회해 이전 bootstrap 응답 재사용 가능 |
| duplicate bootstrap | 중복 초기화/광고 오류 가능; Guard는 기존 중복을 정리하는 도구가 아님 |
| JSON/API에 잘못 적용 | content-type이 JSON이면 rewrite하지 않지만, 잘못된 HTML content-type은 오탐 가능 |

## 12. 실제 검증 기록

### 로컬 2026-08-31

| Scenario | URL/환경 | 결과 | 근거 |
|---|---|---|---|
| Auto-only LOW | `127.0.0.1:8051/.../auto-only-page.php`, Chromium 1280×720 | bootstrap 1, manual 원본 0, Google ad iframe 생성, 본문 유지, console error 0 | OBSERVED |
| Auto-only forced deny | 동일 fixture, 8052 | bootstrap 0, ad ins 0, iframe 0, 본문 유지, console error 0 | OBSERVED |
| 운영형 LOW | `/topic.php`, 1280×720 | bootstrap 1, ad ins 3, initialized 3, Google iframe 4, error 0 | OBSERVED |
| 운영형 forced deny | `/topic.php` | bootstrap 0, inert ad ins 2, initialized 0, Google iframe 0, 본문 유지, error 0 | OBSERVED |
| 모바일 일반 | `/topic.php`, 390×844 | 본문/부트스트랩 정상, 후속 로그 NORMAL 0 | OBSERVED |
| 원본 로그 ↔ Viewer | UTC 원본 → 한국시간 표시 | Viewer가 한국 날짜 경계로 합계/표를 묶고 IP hash 12자 표시 | VERIFIED |

강제 deny log는 `action=DENY`, `ads_allowed=false`, `ads_served=false`였고 Auto-only 응답은 `bootstrap_removed=1`이었다. 운영형 adapter는 bootstrap을 애초에 출력하지 않아 `external_suppression=ad_defense_policy`, `bootstrap_removed=0`, `ads_served=false`로 남았다. 두 경우 모두 실제 광고 미제공을 구분해 기록한다.

### 운영 서버 2026-08-28 — 운영자 제공 관측

- 경로: `/ABSOLUTE/PATH/TO/PROJECT`
- worker: `daemon:daemon`
- unit/policy/auto-prepend integration test 통과.
- `adguard/storage/logs/ad-guard-2026-08-28.jsonl` 생성.
- `/example-site/topic.php` 실제 row: `NORMAL`, score 0, `ALLOW`, `adsense_detected=true`, `ads_served=true`, 4개 signal 모두 0.

이는 **OBSERVED (operator-supplied)**이며 이 감사에서 운영 서버에 직접 접속해 재검증한 것은 아니다.

### 검증하지 못한 항목

- 실제 PHP 5.6 SAPI 실행.
- 실제 Cloudflare/IPv6 client end-to-end.
- AdSense console 수익/invalid traffic 감소 효과와 실제 광고 클릭. same-origin iframe 때문에 사이트 코드가 클릭을 볼 수 없다.
- 각 Auto Ads 형식(Anchor/Vignette/Side rail/Ad intents)을 개별 시각 확인.
- 운영 트래픽의 false-positive 비율.

## 13. Portability Matrix

| 환경 | Core 무수정 가능? | 조건/판정 |
|---|---|---|
| Site A: PHP 5.6 + Apache + Manual | 조건부 YES | static audit PASS. 실제 5.6 runtime test 후 적용; mod_php/FPM 방식에 맞는 hook과 storage 권한 필요 |
| Site B: PHP + Auto Ads | YES | 공식 bootstrap을 PHP HTML이 출력해야 함. 정적 HTML/CDN cache면 NO |
| Site C: Cloudflare + Manual/Auto | 조건부 | 원본 웹서버가 신뢰된 CF range에 대해서만 실제 IP를 `REMOTE_ADDR`로 복원하면 Core 무수정. 아니면 현재 Config만으로 불가 |
| Site D: No Cloudflare + IPv6 | 조건부 YES | 문자열/HMAC/storage는 대응. 실제 IPv6 E2E와 웹서버 REMOTE_ADDR 확인 필요 |

## 14. Failure Mode와 Rollback

| 이슈 | 증상 | 영향 | 확인 | 대응 |
|---|---|---|---|---|
| hook/file fatal | 전 페이지 500 | 사이트 장애 | PHP error log, `php -l`, curl | hook 제거/경로 수정 |
| storage permission/disk full | `degraded`, 광고 미노출 증가 | 수익 영향 | log/report, `df -h`, `ls -ld` | 권한/용량 복구; 긴급 `monitor` |
| log lock/20MB cap | 로그 일부/이후 없음 | 관측 손실, 정책은 계속 | filesize, server log | 용량/수집 개선 |
| proxy IP 오류 | IP 신호 폭증 | 대규모 오탐 | unique IP 급감, REMOTE_ADDR 확인 | 웹서버 real-IP 설정, `monitor` |
| Viewer 큰 일자 | 느림/메모리 | 운영 UI 부하 | PHP memory/time | SSH report/aggregate, viewer 제한 |
| false-positive spike | MONITOR_DENY/DENY 급증 | enforce면 수익 영향 | top reasons/path | `monitor`, 원인 신호 완화 |
| duplicate bootstrap | console/광고 오류 | 광고 품질 저하 | Network/DOM | 광고 include 정리 |
| DB unavailable | 영향 없음 | DB 미사용 | - | - |

Fail 정책은 두 층이다.

- 위험 provider/signal/storage 오류: 기본적으로 **광고 fail-closed**, 즉 광고는 숨기되 본문은 계속 렌더하려 한다.
- logger 실패/상한/lock 실패: **logging fail-open**, 페이지/광고 정책을 바꾸지 않는다.
- PHP include/parse/fatal 또는 PHP 7+ `Error`: 복구가 보장되지 않아 **사이트 전체 fatal 가능**. 배포 smoke test가 필요한 이유다.

가장 빠른 rollback:

```php
// adguard/config/guard.php
'mode' => 'monitor', // 차단 즉시 중단, 판정/로그 유지
```

그래도 장애면:

```php
'mode' => 'off',     // Guard 비활성화
```

hook 자체 fatal이면 `guard.php`도 읽히지 않으므로 `.user.ini`/FPM/vhost의 `auto_prepend_file`을 제거하고 FPM cache/reload를 반영한다. 삭제는 마지막 단계다.

## 15. 파일별 Troubleshooting

| 구역/파일 | 증상 | 우선 확인 | 조치 |
|---|---|---|---|
| `.user.ini` / `auto-prepend.php` | 모든 페이지 500 또는 로그 0 | 절대경로, newline, `php -i`, FPM cache, `php -l` | 경로 수정; 긴급 hook 제거 |
| `config/guard.php` | 예상과 다른 차단/제외 | 실제 mode, suffix 방식 excluded path, env `AD_GUARD_CONFIG` | monitor 복귀, override 경로 정리 |
| `config/engine.php` | 점수 급변 | window/weight/threshold diff와 정책 안전 테스트 | 한 값씩 되돌리고 monitor |
| `storage/engine` | `degraded`/광고 감소 | worker user, 권한, flock, disk | chown/chmod/용량 복구 |
| `storage/logs` / Logger | 로그 없음/중간 정지 | 광고 페이지인지, UTC 날짜, 20MB, 권한 | report와 filesize 확인, cap/수집 개선 |
| `include/ad-defense.php` | bootstrap 0인데 Guard removed 0 | `external_suppression`, preview/custom provider | adapter 정책/preview session 확인 |
| `ad-preview*.php` | 테스트 결과가 운영과 다름 | Guard 제외 경로와 session override | 테스트 도구로만 사용; 배포 의존성으로 두지 말고 삭제 시 제외 목록도 제거 |
| AdSense | script는 있으나 광고 없음 | publisher/slot, CSP, console, fill | Google 발급 코드·정책 확인; Guard 문제와 분리 |
| `viewer.php` | 404/느림/빈 화면 | exact REMOTE_ADDR, 한국 날짜, 파일 크기 | allowlist/proxy 확인, SSH report/aggregate 사용 |

identity cookie가 headers-sent 때문에 발급되지 않으면 `SessionChurnSignal` 내부 metric에는 `cookie_issue_degraded=true`가 생기지만, 현재 Guard의 degraded 판정과 축약 JSONL signal에는 포함되지 않는다. 전역 auto-prepend를 출력보다 먼저 실행하면 회피되지만 수동 include 설치에서는 이 상태가 운영 화면에 조용히 숨을 수 있다. **RECOMMENDED:** 향후 logger에 이 metric을 보존하고 배포 smoke test에서 실제 `Set-Cookie`와 다음 요청의 cookie 반환을 확인한다.

## 16. 배포 완료 Checklist

```text
[ ] adguard/ 전체 업로드
[ ] guard.php = monitor
[ ] 실제 worker user/group 확인
[ ] storage/logs, storage/engine 생성 및 worker 쓰기 확인
[ ] auto_prepend_file 절대경로 설정
[ ] PHP-FPM .user.ini cache 반영 대기/reload
[ ] 전체 lint, 정책, engine, auto-prepend 테스트 PASS
[ ] 광고 페이지 HTTP 200 / 본문 정상
[ ] 일반 브라우저 bootstrap 1 / 광고 실행 / console error 0
[ ] 강제 차단 새 요청 bootstrap 0 / Google ad iframe 0 / 본문 정상
[ ] LOW와 forced deny JSONL row 확인
[ ] Viewer 합계와 raw row count 대조
[ ] storage URL 403/404
[ ] proxy/Cloudflare REMOTE_ADDR 검증
[ ] rollback을 monitor/off/hook 제거 순서로 연습
[ ] 1~2주 monitor 운영 시작
```

## 17. Q1~Q20 결론

1. **Q1:** `adguard/` 전체와 이 문서, 전역 hook 설정을 올린다.
2. **Q2:** `adguard/adguard.php`, `auto-prepend.php`, `src/`, `engine/risk-engine.php`, `engine/src/`는 프로젝트별로 수정하지 않는다.
3. **Q3:** `adguard/config/guard.php`는 반드시 검토하고, 임계값 변경 시만 `config/engine.php`; publisher/slot adapter를 쓸 때만 사이트 config를 수정한다.
4. **Q4:** 최우선은 `.user.ini`/FPM/vhost. 불가능하면 모든 응답보다 앞선 공통 bootstrap. 선택적으로 공통 AdSense 출력 함수를 Guard API로 감싼다.
5. **Q5:** 상세 호출/의존성은 6절 표와 graph와 같다.
6. **Q6:** PHP가 대상 script를 실행하기 전 `auto_prepend_file`에서 buffer를 시작하고, 위험 평가는 최초 광고 허용 질의 또는 HTML FINAL 감지 시 요청당 한 번 실행된다.
7. **Q7:** adapter 사용 시 bootstrap 출력 전, 범용 fallback에서는 최종 HTML 전송 직전 bootstrap 제거 단계다.
8. **Q8:** 같은 공식 bootstrap을 쓰는 Manual/Auto는 보호한다. Future는 같은 loader일 때만 자동 포함하므로 PARTIAL이다.
9. **Q9:** lint/test뿐 아니라 HTTP 200, LOW/deny Network·DOM·Console, JSONL, Viewer를 함께 증명한다.
10. **Q10:** 광고 페이지 요청 전후 UTC JSONL 행/크기 증가와 새 `request_id`, path, action을 확인한다.
11. **Q11:** Viewer는 같은 JSONL을 서버에서 읽는다. 로컬 5행=5행을 OBSERVED했지만, sampling/깨진 행/일 상한이면 운영 총량과 다를 수 있다.
12. **Q12:** 보장할 수 없다. 공유 IP 단독 차단은 막았지만 합성 급속 브라우징에서 visitor score 100이 관찰됐다. monitor 데이터가 필요하다.
13. **Q13:** 아니다. 현재 active config처럼 `monitor`부터 시작한다.
14. **Q14:** 충분한 정상 분포, 표본 오탐, reason/path, storage/log 안정성, threshold margin, rollback 연습이 모두 충족될 때다.
15. **Q15:** 광고 감지 요청/가명 방문자, level/action, MONITOR_DENY·block rate, degraded, top reason/path, 로그/디스크를 본다.
16. **Q16:** score 분포, HIGH 표본 오탐, 신호별 집중, NAT/경로 편향, 수익/트래픽 변화와 config 변경 효과를 분석한다.
17. **Q17:** 정상 세션 분포와 표본 오탐 근거가 있을 때 한 신호씩 변경하고 정책 테스트 후 다시 monitor한다.
18. **Q18:** 현재 로그로 일별 level/reason/path/UA/가명 반복자를 분석한다. 월간 time-series/ASN/저속·분산 공격은 별도 aggregate가 필요하다.
19. **Q19:** 미검증 기본 threshold, proxy IP 오식별, full-response buffering, PHP fatal 가능성, 자동 retention 부재가 가장 큰 운영 위험이다.
20. **Q20:** 먼저 `monitor`, 다음 `off`, 그래도 fatal이면 `auto_prepend_file` 제거/반영 순서다.

운영·분석·임계값 변경 절차는 이 통합 문서의 Part II를 따른다.



# Part II — 운영·모니터링

> 대상: `adguard/`를 설치한 PHP 프로젝트  
> 최초 원칙: **1~2주 `monitor` → 데이터 검토 → 승인 후 `enforce`**  
> 사실/기능 label의 의미는 배포 가이드와 같다.

## 1. 운영자가 먼저 알아야 할 것

이 시스템은 광고 클릭을 관찰하거나 공격자를 확정하는 도구가 아니다. 요청의 UA·속도·identity 패턴을 보고 위험도를 추정한 뒤, 위험한 요청에는 AdSense loader를 제공하지 않는 **광고 요청 예방 gate**다. 사이트 자체를 403으로 차단하지 않는다.

현재 active 설정은 `adguard/config/guard.php`의 `mode=monitor`다. monitor에서도 모든 판정과 로그는 실행되지만 실제 `ads_allowed=true`이고 bootstrap은 유지된다. 정책상 막았을 요청만 `action=MONITOR_DENY`로 표시된다.

```text
engine decision: SUSPICIOUS/SEVERE
policy_allowed: false
mode: monitor
actual ads_allowed: true
action: MONITOR_DENY
ads_served: 실제 응답에 bootstrap이 남았는지
```

별도 `shadow_score`, `shadow_level` 필드는 없다. `score`, `engine_level`, `reasons`, `action=MONITOR_DENY`, `ads_allowed=true`의 조합이 shadow 기록이다. 따라서 Shadow telemetry는 **IMPLEMENTED**, 전용 필드/대조 dashboard는 **PARTIAL**이다.

## 2. 운영 단계

### Phase 0 — 배포 직후 30분

목표는 수익 방어가 아니라 설치 사고 방지다.

```bash
cd /ABS/PROJECT
php -l adguard/auto-prepend.php
php adguard/tests/policy-safety-test.php
php adguard/tests/auto-prepend-integration-test.php
curl -sS -o /dev/null -w '%{http_code}\n' https://example.com/광고페이지.php
tail -n 3 adguard/storage/logs/ad-guard-$(date -u +%F).jsonl
```

확인:

- 광고 페이지 HTTP 200과 본문 정상.
- 일반 브라우저 bootstrap/광고 정상, console error 없음.
- JSONL에 새 path/score/action/ads_served가 존재.
- storage URL은 403/404.
- Viewer는 허용 IP에서만 열림.
- `degraded=false`.

### Phase 1 — 1~2주 monitor

- 실제 광고를 막지 않는다.
- 매일 로그 연속성·MONITOR_DENY·degraded·top reason/path를 확인한다.
- HIGH/SEVERE 표본을 정상/의심/판단불가로 분류한다.
- 광고가 없는 API 요청은 로그에 없다는 점을 감안한다.
- config를 변경하면 날짜·변경자·이유·before/after를 기록한다.

### Phase 2 — 전환 검토

“일주일이 지났다”만으로 전환하지 않는다. 다음이 모두 필요하다.

```text
[ ] 평일/주말과 주요 유입 시간대 포함
[ ] 광고 경로별 정상 score 분포 확보
[ ] SUSPICIOUS/SEVERE 표본 오탐 검토
[ ] rate_limit/session_churn의 공유 IP 편향 검토
[ ] visitor_rate 급등 원인이 실제 자동화인지 검토
[ ] degraded/로그 공백/20MB cap 없음
[ ] proxy의 REMOTE_ADDR 정확성 확인
[ ] NORMAL 95/99 percentile과 차단 threshold 사이 margin 확인
[ ] LOW/강제 deny 브라우저 회귀 PASS
[ ] monitor/off/hook 제거 rollback 연습
[ ] 수익 담당자·운영 책임자 승인
```

고정 허용 오탐률이나 percentile 숫자는 현재 데이터 없이 정하지 않는다.

### Phase 3 — enforce

```php
// adguard/config/guard.php
'mode' => 'enforce',
```

기본 정책은 SUSPICIOUS/HIGH부터 광고 차단한다. ELEVATED/MEDIUM까지 확대하지 않는다. 전환 직후 1시간, 당일, 다음날을 추가 점검한다. 차단률·수익·degraded가 급변하면 즉시 monitor로 되돌린다.

## 3. 로그의 의미와 한계

### 위치와 format

```text
adguard/storage/logs/ad-guard-YYYY-MM-DD.jsonl   # UTC 일별
adguard/storage/.hmac-key                        # HMAC 비밀 키
adguard/storage/engine/                          # 짧은 window 상태
```

각 JSONL 행은 광고가 감지되거나 adapter가 광고 기회를 표시한 **한 HTML 응답의 최종 결과**다.

주요 필드:

| 필드 | 의미 |
|---|---|
| `timestamp`, `request_id` | UTC 시각, 행 식별자 |
| `site_id`, `host`, `route_group` | 도메인/경로가 바뀌어도 비교할 수 있는 서버 라벨 |
| `path`, `method`, `referrer_host`, `referrer_group` | query를 제외한 경로와 요청·유입 정보 |
| `redirect_rule_id` | 앱이 선택적으로 넣는 안정적인 리다이렉트 규칙 ID; 목적지 URL 자체는 아님 |
| `ip_hmac`, `visitor_hmac` | 원본이 아닌 HMAC 가명 식별자 |
| `network_hmac` | IPv4 `/24` 또는 IPv6 `/64`를 HMAC한 IP 회전 조사용 약한 클러스터; 공유망을 공격자로 확정하면 안 됨 |
| `ua_family` | 전체 UA가 아닌 chrome/safari/bot 등 coarse family |
| `engine_level`, `score`, `reasons` | 엔진 위험 결과 |
| `signals` | 신호별 score/triggered/storage_degraded |
| `action` | ALLOW/DENY/MONITOR_DENY |
| `ads_allowed` | mode까지 반영한 실제 허용 값 |
| `ads_served` | 최종 HTML에 실행 가능한 bootstrap이 남았는지 |
| `bootstrap_removed` | Guard가 제거한 loader 수 |
| `external_suppression` | site adapter가 먼저 미출력한 이유 |
| `degraded` | engine/signal/storage 문제 여부 |

로그하지 않는 값: 원본 IP, cookie/visitor 원문, 전체 UA, query string, request body, Authorization, 비밀번호.

### 반드시 기억할 집계 한계

- Viewer의 `Total requests`는 **사이트 전체 요청이 아니라 광고 감지 응답 수**다.
- `Unique visitors/IPs`는 HMAC 기준 추정치다. 쿠키 삭제는 새 방문자, CGNAT는 여러 사람을 IP 1개로 만든다.
- 광고 없는 세션은 보이지 않으므로 전체 세션 분모가 필요하면 별도 analytics/서버 로그가 필요하다.
- sample rate를 1 미만으로 바꾸면 raw count와 비율에 sampling 보정이 필요하다.
- lock 획득 실패, 일 20MB 상한, 디스크 문제 시 일부 행이 유실될 수 있다.
- `ads_served`는 bootstrap 제공 여부이지 실제 fill/impression/수익이 아니다.

## 4. 매일 확인 절차

### 4.1 SSH 기본 명령

현재 운영 서버 예시:

```bash
cd /ABSOLUTE/PATH/TO/PROJECT
LOG="adguard/storage/logs/ad-guard-$(date -u +%F).jsonl"
ls -lh "$LOG"
tail -n 20 "$LOG"
php adguard/tools/report.php "$(date -u +%F)"
```

`date -u`가 중요하다. 한국 오전에는 파일 날짜가 전날 UTC일 수 있다.

실시간:

```bash
tail -F "adguard/storage/logs/ad-guard-$(date -u +%F).jsonl"
```

자정 UTC(한국 09:00)에 파일명이 바뀌므로 오래 켜둘 때는 새 파일로 다시 실행한다.

권한/용량:

```bash
ls -ld adguard/storage adguard/storage/logs adguard/storage/engine
df -h .
find adguard/storage/logs -maxdepth 1 -type f -name 'ad-guard-*.jsonl' -printf '%TY-%Tm-%Td %TH:%TM %s %p\n' | sort
```

### 4.2 Daily KPI

| 지표 | 현재 제공 | 해석 |
|---|---|---|
| 광고 감지 요청 | YES | viewer/report total. 전체 traffic 아님 |
| 가명 방문자/IP | YES | ad-bearing subset의 추정치 |
| NORMAL/ELEVATED/SUSPICIOUS/SEVERE | YES | 위험 분포 |
| MONITOR_DENY/DENY | YES | would-block/실차단 응답 수 |
| ads served/not served | YES | bootstrap 기준 |
| degraded | YES | storage/engine 장애 우선 확인 |
| top reasons/signals | YES | 과민 신호 파악 |
| top denied/no-ad paths | YES | 특정 경로 편향 |
| 전체 사이트 sessions | NO | analytics/access log 필요 |
| 실제 impression/click/revenue | NO | AdSense 보고서 필요 |

매일 기록할 최소 표:

```text
UTC date:
ad-bearing responses:
unique visitor HMAC / IP HMAC:
NORMAL / ELEVATED / SUSPICIOUS / SEVERE:
MONITOR_DENY or DENY:
would-block/block rate:
ads served / not served:
degraded:
top 3 signals:
top 3 affected paths:
log size / disk free:
AdSense revenue/RPM anomaly (별도 콘솔):
operator note:
```

항상 비율을 함께 본다.

```text
high-risk response rate = (SUSPICIOUS + SEVERE) / ad-bearing responses
would-block rate         = MONITOR_DENY / ad-bearing responses
enforced block rate      = DENY / ad-bearing responses
```

세션 비율은 별도 전체 세션 분모가 있을 때만 계산한다. response와 사람/session을 섞지 않는다.

### 4.3 Daily 이상 판단

고정 숫자를 지금 정하지 않는다. 이전 동일 요일/시간대 baseline과 비교한다.

즉시 조사할 패턴:

- `degraded > 0` 또는 로그 파일 성장 중단.
- MONITOR_DENY/DENY 비율의 급격한 상승.
- `rate_limit`/`session_churn`이 갑자기 1위.
- unique IP가 비정상적으로 1개/소수로 수렴 — proxy 설정 의심.
- 특정 path에 no-ad가 집중.
- log가 20MB 근처에서 멈춤.
- AdSense 수익 급락과 block rate 상승이 같은 시각에 발생.

block rate가 평소와 같은데 수익만 하락하면 fill, policy, placement, traffic quality 등 다른 원인을 우선 본다.

## 5. 매주 분석 절차

1. 일별 KPI를 한 표로 합쳐 시간 추세를 본다.
2. SUSPICIOUS/SEVERE 방문자 HMAC에서 표본을 뽑는다.
3. 각 표본을 `정상 / 의심 / 판단불가`로 분류하고 근거를 기록한다.
4. 신호별 trigger와 path 교차표를 만든다.
5. score 구간 분포를 만든다.
6. 공유 IP 오탐과 한 방문자 과속을 분리한다.
7. config 변경 전/후를 같은 요일·유입 조건으로 비교한다.
8. 로그 누락·storage·viewer 성능·retention을 확인한다.

표본은 한 사건의 여러 response를 그대로 여러 명으로 세지 말고 `visitor_hmac` 중심으로 묶는다. visitor HMAC이 없으면 IP HMAC과 시간/path를 보조로 쓴다.

### Score distribution

현재 Viewer는 score histogram을 제공하지 않는다(**NOT IMPLEMENTED**). `jq`가 설치된 서버에서는 다음처럼 임시 집계할 수 있다.

```bash
jq -r '.score' "$LOG" | awk '
  { b=int($1/10)*10; if (b>90) b=90; n[b]++ }
  END { for (b=0;b<=90;b+=10) printf "%02d-%s %d\n", b, (b==90?"100":b+9), n[b]+0 }'
```

신호 trigger:

```bash
jq -r '.signals | to_entries[] | select(.value.triggered==true) | .key' "$LOG" | sort | uniq -c | sort -nr
```

가명 방문자별 최대 점수:

```bash
jq -r 'select(.visitor_hmac != "") | [.visitor_hmac,.score,.engine_level,.path] | @tsv' "$LOG" \
  | sort -k1,1 -k2,2nr
```

`jq`가 없으면 `php adguard/tools/report.php KST_DATE`를 사용하고, 장기 histogram/세션 집계는 별도 read-only 분석기를 **RECOMMENDED**한다.

## 6. False Positive와 정상 사용자 검증

### 현재 증거

2026-08-31 로컬 Chromium 검증:

| Scenario | 관측 |
|---|---|
| 일반 방문 | NORMAL, score 0, 광고 허용 |
| 빠른 2개 페이지 이동 | NORMAL, score 0 |
| 약 1초 후 refresh | NORMAL, score 0 |
| mobile 390×844 | NORMAL, score 0, bootstrap 유지 |
| back/forward + 여러 tab + 연속 navigation을 수초 내 합성 | 최대 SEVERE 100, MONITOR_DENY 7 |

마지막 행은 사람의 현실 행동으로 단정할 수 없다. 여러 시나리오가 한 identity/storage에 연달아 실행됐고 페이지의 추가 navigation도 섞였다. 다만 기본 `visitor_rate`가 짧은 폭주에 민감하다는 **OBSERVED** 증거다. 현재 로그에는 scenario ID나 원인 navigation ID가 없어 각 행을 NORMAL-04/05에 완벽히 귀속할 수 없다(**PARTIAL**).

Threshold margin은 두 개로 분리해 기록한다.

- 격리된 일반 방문/이동/1회 refresh/mobile: normal max 0, 기본 block score 50 → 관측 margin 50점.
- 수초 내 합성 navigation/tab stress: max 100 → block threshold를 50점 초과, 안전 margin 없음.

따라서 “정상 사용자는 항상 50점 여유”라고 결론내릴 수 없다. 운영 로그에서 실제 사람의 p95/p99 max score와 다중 탭·중복 요청을 다시 측정해야 한다.

### NAT/CGNAT/VPN

- `rate_limit`과 `session_churn`은 IP 기반이지만 weight 0.45이고 hard deny가 아니므로 단독으로 차단 band에 도달하지 않는다.
- 여러 약한 신호가 함께 trigger되면 25% bonus가 붙으므로 완전 무관하지는 않다.
- `visitor_rate`는 first-party identity 기준이라 공유 IP 영향을 줄이지만, cookie 차단/삭제/브라우저 자동화에 따라 관측이 달라진다.
- VPN/국가/ASN은 현재 점수에 쓰지 않는다. VPN 자체를 공격으로 보지 않는다.
- Cloudflare에서 실제 IP가 복원되지 않으면 모든 사용자가 edge IP로 합쳐져 이 안전 설계가 무너진다.
- 수동 include가 HTML 출력 뒤 실행되어 identity cookie를 발급하지 못하면 churn은 오탐 방지를 위해 count하지 않는다. 현재 축약 로그에는 이 cookie 발급 실패가 `degraded`로 보이지 않는 관측 사각지대가 있다.

## 7. Threshold 조정 규칙

Threshold는 “HIGH가 많아 보인다”는 감으로 바꾸지 않는다.

### 순서

1. raw log와 전체 traffic/AdSense 지표의 시간대를 맞춘다.
2. affected visitor HMAC과 path를 표본 검토한다.
3. 과민한 **하나의 신호**를 특정한다.
4. 정상 세션의 max score 분포와 p95/p99를 계산한다.
5. 차단 threshold까지 margin을 본다.
6. 한 번에 하나의 window/threshold/weight만 완화한다.
7. `policy-safety-test.php`와 전체 회귀 테스트를 실행한다.
8. 다시 monitor에서 최소 동일 트래픽 주기를 관찰한다.
9. 변경 전/후 오탐과 탐지 손실을 비교한다.

예시 override는 숫자 권고가 아니라 문법 예시다.

```php
// adguard/config/engine.php
<?php
return array(
    'signals' => array(
        'visitor_rate' => array('windows' => array(10 => 12, 60 => 45, 600 => 180)),
    ),
);
```

변경 금지 안전선:

- `rate_limit`, `session_churn`의 `weight*100 < suspicious threshold` 유지.
- 두 IP 기반 신호를 `hard_deny_signals`에 넣지 않음.
- hard deny raw minimum 40 이상.
- 최소 하나의 정밀 신호(`user_agent` 또는 `visitor_rate`)가 차단 가능해야 함.

`user_agent`가 정상 브라우저에서 자주 trigger되면 실제 header가 proxy/WAF에서 제거되는지 먼저 조사한다. marker를 무작정 지우지 않는다.

## 8. 한 달 데이터로 가능한 것과 불가능한 것

| 분석 | 현재 로그로 가능? | 방법/추가 필요 |
|---|---:|---|
| 일별 level/action/path 추세 | 가능 | JSONL 일별 aggregate |
| 정상 baseline/score 분포 | 조건부 | visitor HMAC으로 session 유사 grouping |
| 반복 위험 방문자 | 조건부 | 같은 HMAC의 여러 날 이력; HMAC key 유지 필요 |
| 과민 rule/path | 가능 | signal × path 집계 |
| 시간대 공격 시작 | 조건부 | timestamp hourly aggregate |
| 저속 장기 공격 | 제한 | 현재 engine은 장기 state 없음; 분석 layer 필요 |
| 분산 공격 | 제한 | Visitor·network HMAC와 path/signal/timing의 사후 aggregate; 실시간 확정/차단은 아님 |
| network prefix 근러스터 | 조건부 | `/24`·`/64` HMAC로 약한 근러스터링; ASN enrichment와 원본 prefix는 없음 |
| 확정 클릭 사기 | 불가 | Google iframe click에 접근 불가 |

장기 고도화 우선순위(**RECOMMENDED**):

1. `tools/analyze-adsense-risk.php`로 AdSense 일별 aggregate와 raw JSONL을 읽기 전용 비교(원본 수정 금지).
2. hour/path/signal/score bucket/visitor max를 장기 보관.
3. deploy/config version을 각 row 또는 aggregate에 기록.
4. scenario/correlation ID를 내부 테스트에만 추가.
5. Cloudflare/webserver trusted proxy 표준화.
6. 기준선 대비 비율 alert.
7. 개인정보 검토 후 필요한 경우에만 prefix/ASN enrichment.

## 9. Incident Response

```text
이상 시각 확정
  → 전체 traffic/AdSense 지표 증가·감소 확인
  → level/action/degraded 변화 확인
  → top signal/reason
  → affected path
  → visitor/IP HMAC 반복·집중
  → proxy/worker/storage/disk 상태
  → 실제 ads_served/block 변화
  → monitor rollback 또는 한 신호 완화
  → 원인/조치/복구 시각 기록
```

### 수익 급락

1. `DENY/MONITOR_DENY`와 `ads_served=false` 비율이 같이 올랐는지 확인.
2. 올랐다면 top reason/path/degraded를 확인.
3. `rate_limit/session_churn` 중심이면 공유 IP/proxy를 우선 의심.
4. `visitor_rate` 중심이면 브라우저 중복 요청, SPA/reload, bot을 구분.
5. 원인 확정 전 수익 영향이 크면 `monitor`로 전환.

### 로그가 안 쌓임

```bash
pwd
ls -ld adguard/storage adguard/storage/logs
ps -eo user,group,comm | grep -E 'php-fpm|apache2|httpd|lsphp' | sort -u
php -i | grep -i '^auto_prepend_file\|user_ini'
df -h .
find adguard/storage/logs -maxdepth 1 -type f -ls
```

광고 없는 URL로 시험하지 않았는지, UTC 날짜, 20MB 상한, worker 권한을 차례로 본다.

### Viewer 404/느림

- 404: exact `REMOTE_ADDR`가 allowlist에 있는지 확인. proxy 뒤에서 보이는 IP는 사용자의 표시 IP와 다를 수 있다.
- 느림: Viewer는 하루 파일 전체를 배열로 읽는다. 큰 날짜는 SSH report 또는 aggregate를 사용한다.
- 로그인 인증은 없다. IP ACL을 인증으로 오해하지 않는다.

### Alert 후보

실데이터 baseline 후 다음의 변화율/지속시간으로 정한다. 현재 고정 임계값은 **NOT VERIFIED**다.

- high-risk/would-block/enforced-block rate 급증.
- SEVERE 또는 degraded 급증.
- 특정 path 집중.
- unique IP 급감 또는 하나의 IP HMAC 과집중.
- log growth 중단/20MB 도달/disk free 하락.
- AdSense 수익 급락과 block rate 동시 상승.

## 10. Retention·개인정보·성능

### Retention

자동 삭제는 없다. Raw는 짧게, aggregate는 길게 보관하는 것을 권장하되 정확한 기간은 사내 정책과 법률 검토로 결정한다.

```bash
# 먼저 대상 확인
find adguard/storage/logs -type f -name 'ad-guard-*.jsonl' -mtime +90 -print

# 승인된 정책일 때만 삭제
find adguard/storage/logs -type f -name 'ad-guard-*.jsonl' -mtime +90 -delete
```

법률 검토 필요: IP HMAC·visitor HMAC·행동 로그의 개인정보/가명정보 해당성, 고지·목적·보존기간·접근권한.

HMAC 키가 유출되면 IPv4 후보 대입 공격이 가능하므로 웹 접근 금지, 최소 권한, backup 접근 통제가 필요하다. 키를 바꾸면 과거/미래 HMAC 연속성이 끊긴다.

### 성장 계산

```text
일 증가량 ≈ 광고 감지 응답 수 × 평균 JSONL row bytes × sample rate
```

운영자 제공 실제 row는 약 901 bytes였다. 20MiB 상한은 대략 23,000행/일 수준이지만 reasons 길이 등에 따라 달라진다. `wc -l`, `wc -c`로 해당 사이트 평균을 계산한다.

```bash
wc -l "$LOG"
wc -c "$LOG"
```

### 요청 성능

- 4개 signal 중 3개가 file state를 읽고 갱신한다.
- `flock`은 최대 약 200ms(20×10ms) 시도 후 degraded로 진행한다.
- 전체 HTML을 응답 종료까지 메모리에 보류한다.
- GC는 mutating request의 1%에서 실행된다.
- Viewer는 해당 일자 파일 전체를 메모리에 읽는다.

고트래픽에서는 p50/p95/p99 응답시간, PHP memory, storage IOPS/lock 실패, log cap을 별도 측정한다. 현재 부하 성능 수치는 **NOT VERIFIED**다.

## 11. Fail-open / Fail-closed와 즉시 Rollback

| 실패 | 현재 동작 | 평가 |
|---|---|---|
| risk provider/signal/storage degraded | 광고 fail-closed | invalid traffic 방어 우선, 수익 영향 가능 |
| logger 실패/lock/cap | logging만 포기 | 본문/광고 정책 유지 |
| cookie header 발급 실패 | churn request를 세지 않음 | 오탐 방지 fail-open |
| regex 제거 실패 | 원 HTML 반환 | 본문 보호, 광고 fail-open 가능 |
| include/parse/PHP `Error` | fatal 가능 | 본 서비스 장애 위험 |

긴급 순서:

1. 차단만 즉시 중단: `mode=monitor`.
2. Guard 전체 중단: `mode=off`.
3. PHP fatal이면 `.user.ini`/FPM/vhost의 `auto_prepend_file` 제거 후 cache/reload.
4. HTTP 200, 본문, 광고, error log 확인.
5. 장애 시간대 config/log 복사본과 원인 기록.
6. 원인 수정 후 바로 enforce하지 말고 monitor로 재진입.

## 12. 1주 간격 운영 보고 템플릿

비개발자 보고용:

```text
광고 무효 트래픽 방어 기능을 현재는 ‘관찰 모드’로 운영하고 있습니다.
관찰 모드에서는 방문 패턴을 분석하지만 실제 광고는 차단하지 않습니다.

이번 주에는 위험 판정 비율, 정상 방문자가 잘못 분류된 사례, 특정 페이지나
접속 환경에 판정이 몰리는지, 로그와 서버가 안정적인지를 확인했습니다.

실제 차단 전환은 단순히 기간이 지났다는 이유로 하지 않고, 정상 이용자의
오탐 가능성이 충분히 낮고 긴급 해제 절차까지 검증됐을 때 별도 보고 후 진행하겠습니다.
```

개발자 주간 기록:

```text
기간(UTC/KST):
config version/change:
ad-bearing responses / unique visitor HMAC:
level/action distribution:
would-block rate:
degraded/log gaps/cap:
top signals and paths:
HIGH sample size / 정상 / 의심 / 판단불가:
score p50/p95/p99/max:
shared-IP/proxy findings:
AdSense/traffic anomaly correlation:
threshold change proposal and evidence:
decision: continue monitor / adjust then monitor / approve enforce / rollback
owner/approver:
```

## 13. Operations Checklist

```text
DAILY
[ ] UTC 최신 로그 존재/증가
[ ] report/viewer total 및 action/level
[ ] degraded 0 확인
[ ] top reason/path
[ ] block/would-block 비율과 전일·동요일 비교
[ ] 로그 크기/디스크
[ ] AdSense 수익 이상과 시간대 대조

WEEKLY
[ ] score distribution
[ ] HIGH/SEVERE visitor 표본 오탐 분류
[ ] signal × path 집계
[ ] NAT/CGNAT/proxy 편향
[ ] config 변경 전후 효과
[ ] retention/viewer 성능
[ ] enforce 전환 조건 또는 monitor 지속 결정

INCIDENT
[ ] mode=monitor 즉시 가능
[ ] mode=off 가능
[ ] hook 제거 위치/반영 방법 확인
[ ] 복구 후 monitor 재검증
```

## 14. `adguard/viewer.php` 사용 및 로그 해석 가이드

### 14.1 Viewer의 정확한 성격

`adguard/viewer.php`는 **공격자 목록이 아니다.** Risk Engine이 광고가 있는 응답에서 수집한 신호와 판정 결과를 운영자가 분석하는 관측 도구다. 한 IP, 한 signal, 한 SUSPICIOUS/SEVERE 행만으로 공격자라고 단정하지 않는다.

Viewer 접근은 로그인 인증이 아니라 `viewer.allowed_ips`와 `REMOTE_ADDR`의 exact match다. 빈 allowlist는 모두 404다. 원본 JSONL을 브라우저에 링크하지 않고 서버에서 읽으며, 전체 HMAC 대신 앞 12자만 표시한다. 자동 새로고침은 없다.

Viewer에 **실제로 있는 것**:

- 하루 전체 요약, 광고 위치별 제공·차단, IP·Visitor별 응답 밀도, Top risk reasons, Top paths without ads, UA families.
- Date/Level/Action/Ads served/Path/Identifier 필터.
- 최신순 행: Time, Path, Level, Score, Action, Ads served, 광고 슬롯 상태, Policy reason, Signals, UA, IP, Visitor.
- 기본 100행 pagination(설정 가능, reader 내부 10~500 제한).

Viewer에 **없는 것**:

- LOW/MEDIUM/HIGH/CRITICAL 명칭. 실제 명칭은 NORMAL/ELEVATED/SUSPICIOUS/SEVERE다.
- `ads_allowed` 컬럼, 전체 reason 문장, 인접 요청 간격, PHP session ID.
- rolling 24시간/time-range, reason 필터, ASN/prefix, 과거 월간 history, AdSense click/impression/revenue.
- 실제 사용자 신원, VPN 뒤 origin IP, Google invalid-traffic 판정.

### 14.2 상단 Summary 해석

상단 요약은 행 필터와 무관하게 **선택한 한국시간 날짜의 로그**를 집계한다. 자정 경계에서 두 UTC JSONL 파일을 함께 읽는다. 예를 들어 Level=SUSPICIOUS로 필터해도 상단 Total/NORMAL 등은 하루 전체 값이고, 표 위 `matching rows`만 필터 결과다.

| 화면 항목 | 정확한 의미/출처 | 운영 포인트 |
|---|---|---|
| Ad-bearing responses | 유효 JSONL 행 수 | 전체 사이트 요청이 아니라 **로그된 광고 감지 응답 수** |
| Loader-provided responses | `ads_served=true` 행 | 최종 HTML에 실행 가능한 bootstrap이 있었음. 실제 fill/impression은 아님 |
| No-loader responses | `ads_served=false` 행 | Guard 제거, adapter 미출력, 구현 누락 등 모든 원인 포함 |
| No-loader rate | `No-loader responses / Ad-bearing responses` | **DENY 비율·session 비율이 아님** |
| Policy-blocked loaders | `ad_delivery.bootstrap.blocked` 합계 | enforce 정책/adapter 억제로 실제 미제공된 loader 수 |
| Policy block rate | `blocked / opportunities` | 구현 누락을 빼고 정책 차단만 본 비율 |
| NORMAL/ELEVATED/SUSPICIOUS/SEVERE | `engine_level`별 response 수 | 아래 실제 threshold와 비교; Action과 같지 않을 수 있음 |
| MONITOR_DENY | `action=MONITOR_DENY` 수 | monitor에서 “현재 정책이면 막았을” 응답. 실제 차단 아님 |
| Degraded | `degraded=true` 수 | 1건이라도 engine/storage 문제를 우선 조사 |
| Unique visitors* | 비어 있지 않은 전체 `visitor_hmac` distinct 수 | first-party identity 추정. cookie 삭제는 새 방문자; 첫 요청/쿠키 없음은 누락 가능 |
| Unique IPs* | 전체 `ip_hmac` distinct 수 | 사람 수가 아님. NAT는 여러 사람을 1개로 합침 |
| Top risk reasons | raw `reasons`의 `:` 앞 signal prefix 상위 8개 | 전체 이유 문장이 아니라 signal trigger 횟수 |
| Top paths without ads | `ads_served=false` path 상위 8개 | 공격 경로가 아니라 광고 미제공 집중 경로 |
| UA families | coarse `ua_family` 상위 8개 | 전체 UA가 아니며 같은 family=같은 사용자가 아님 |

운영 비율은 데이터 단위를 명시해 별도로 계산한다.

```text
SUSPICIOUS+ rate (response)
  = (SUSPICIOUS + SEVERE) / Total requests (day)

Would-block rate (response, monitor)
  = MONITOR_DENY / Total requests (day)

No-bootstrap rate (Viewer의 Block rate)
  = Ads not served / Total requests (day)

Enforced DENY rate (response)
  = action=DENY 행 / Total requests (day)
```

세션 비율은 Viewer 상단 숫자만으로 정확히 만들 수 없다. `HIGH requests=1,000`이 visitor HMAC 한 개의 반복일 수 있으므로 response와 visitor/session을 혼용하지 않는다. 전체 사이트 세션 분모는 별도 analytics/access log가 필요하다.

### 14.2.1 광고 위치별 제공·차단

로그 스키마 v2는 응답에서 서버 렌더링된 수동 광고 `<ins>`를 찾아 `페이지 경로 + data-ad-slot + 같은 슬롯의 DOM 순번`으로 집계한다. 같은 슬롯 ID를 상단과 하단에 반복한 경우 `1111111111#1`, `1111111111#2`처럼 구분된다.

| 상태 | 정확한 의미 |
|---|---|
| opportunities | 해당 응답에서 광고 기회가 발견됨 |
| provided | 최종 HTML에 실행 가능한 공통 loader가 남음 |
| blocked | enforce 정책 또는 adapter 연동으로 loader를 제거/미출력 |
| missing | 기회는 있으나 정책 차단 사유 없이 loader가 없음. 템플릿·배포 오류 점검 대상 |

수동 슬롯의 `provided`는 그 슬롯의 실제 Google 요청, fill, viewable impression을 뜻하지 않는다. 페이지 공통 loader 제공 여부를 슬롯에 투영한 **서버 제공 가능 상태**다. Auto Ads 개별 위치는 브라우저에서 Google이 동적으로 생성하므로 서버는 `AdSense 공통 로더(Auto Ads 포함)`만 집계한다. 스키마 v1 로그는 공통 loader 합계에는 포함되지만 슬롯 정보는 복원하지 않는다.

### 14.2.2 IP·Visitor별 광고 가능 응답 밀도

Viewer의 두 표는 가명 식별자별 당일 광고 감지 응답, loader 제공/미제공, rolling 최대 10분·1시간 응답 수, 고위험 응답 수, 최고 score와 최근 시각을 보여준다. 이는 실제 Google 광고 요청·노출·클릭 횟수가 아니다. IP 표는 CGNAT·회사·학교 공유망 때문에 여러 사람을 합칠 수 있으므로 자동 차단 근거로 단독 사용하지 않는다. Visitor 표와 함께 보고 같은 Visitor의 반복, IP 변경, path·UA·signal 조합을 조사한다.

### 14.3 행 컬럼과 Raw JSONL Mapping

| Viewer 컬럼 | Raw JSONL | 의미/주의 |
|---|---|---|
| Time (KST) | UTC `timestamp`의 한국시간 변환값 | 날짜는 선택값. 연속 행의 초 단위 밀집을 보되 고정 간격만으로 bot 판정 금지 |
| Path | `path` | query 제외. 자동 refresh/AJAX/polling/redirect 구조도 집중 원인 가능 |
| Level | `engine_level` | combined score band. 광고 조치 자체는 아님 |
| Score | `score` | 현재 요청의 verdict. 최근 fixed-window 상태를 포함하지만 평생 누적 session score는 아님 |
| Action | `action` | ALLOW, DENY, MONITOR_DENY만 존재 |
| Ads served | `ads_served` | 최종 bootstrap 잔존 여부. `removed N`/외부 suppression 이유를 옆에 표시 |
| Policy reason | `policy_reason` | level/hard signal/degraded 중 어떤 정책이 조치를 만들었는지 |
| Signals | `signals.*.triggered/score` | trigger된 신호를 `name=raw score`로 축약. combined score와 단순 합산하지 않음 |
| UA | `ua_family` | coarse family. 전체 UA는 저장·표시하지 않음 |
| IP* | `ip_hmac` 앞 12자 | 같은 표시면 같은 HMAC 후보일 뿐 사람/공격자 확정 아님 |
| Visitor* | `visitor_hmac` 앞 12자 | PHP session이 아니라 `__rek_id` first-party cookie의 HMAC |
| Ad placements | `ad_delivery.manual_units[]` | `slot#순번:provided/blocked/missing`; 실제 impression 상태가 아님 |

Raw에는 있으나 표에 직접 보이지 않는 값:

- `request_id`, `method`, `referrer_host`, `country`, `cf_ray`.
- boolean `ads_allowed`, `adsense_detected`, `degraded`.
- 전체 `reasons` 문장과 비-trigger signal.
- `sample_rate`, 전체 64자 `ip_hmac`/`visitor_hmac`.

민감도와 화면 복잡도를 줄이기 위한 선택이지만, 전체 reason·method·referrer가 필요한 사건은 SSH에서 원본 JSONL을 읽어야 한다. 원본에도 raw IP/cookie/full UA/query/body/Authorization은 없다.

### 14.4 Risk Score와 Level

실제 band:

| Viewer Level | Score | 기본 실제 광고 정책 | 운영 해석 |
|---|---:|---|---|
| NORMAL | 0~24 | level만으로 차단 안 함 | 일반 범위. trigger가 전혀 없다는 뜻은 아님 |
| ELEVATED | 25~49 | level만으로 차단 안 함 | 관찰. hard-deny signal이면 예외적으로 DENY 가능 |
| SUSPICIOUS | 50~74 | 기본 정책상 deny | monitor면 MONITOR_DENY/실제 허용, enforce면 DENY |
| SEVERE | 75~100 | 기본 정책상 deny | 집중 조사. 여전히 공격자 확정은 아님 |

Score는 signal raw score의 단순 합이 아니다.

1. 각 raw score에 config weight를 적용한다.
2. 가장 큰 weighted score를 base로 한다.
3. 추가 trigger signal마다 weighted score의 25%를 bonus로 더한다.
4. 0~100으로 제한한다.
5. UA 없음+Accept 없음은 최소 SUSPICIOUS override가 있다.

따라서 Viewer의 `Signals: rate_limit=100`은 raw 100이고 weight 0.45라 combined contribution 최대 45다. 반대로 `visitor_rate=50`은 weight 1이라 단독 combined 50이 될 수 있다.

Threshold margin:

```text
NORMAL 23 → ELEVATED(25)까지 2점
ELEVATED 25 → SUSPICIOUS(50)까지 25점
ELEVATED 49 → SUSPICIOUS까지 1점
SUSPICIOUS 50 → 막 차단 band에 진입
SUSPICIOUS 74 → SEVERE(75)까지 1점
```

같은 ELEVATED라도 25와 49는 다르다. 정상으로 보이는 visitor가 반복해서 45~49까지 올라오면 현재 차단되지는 않아도 threshold margin이 위험하다. 정상 visitor별 max score의 p95/p99와 50 사이 거리를 본다. 반대로 isolated normal test의 max 0은 margin 50이었지만, 수초 내 합성 tab/navigation에서는 100까지 올라간 실제 사례가 있으므로 한 테스트만으로 안전을 선언하지 않는다.

Risk는 연속 감쇠가 아니라 10/60/300/600초 fixed window가 만료된 다음 요청에서 해당 count가 reset된다. 장기 previous-high 가중치는 없다.

### 14.5 Signals/Top risk reasons 해석

Viewer 행의 Signals와 상단 Top risk reasons는 현재 네 signal만 사용한다. `rapid_request`, `repeat_url`, `history` 같은 필드는 현재 구현에 없다.

| Viewer signal/reason | 실제 trigger | raw score | 정상에서도 가능? | 해석 |
|---|---|---:|---|---|
| `user_agent` | UA 없음 +40; automation marker +40; Accept 없음 +15; Language 없음 +10; Encoding 없음 +5 | 합계, max 100 | 가능. proxy/header 제거, CLI, crawler | raw 40 이상은 hard deny. 일반 Chrome family만으로 trigger되지 않음 |
| `rate_limit` | 한 IP가 10초 120 또는 300초 1200 초과 | `(초과/허용)*100`, max 100 | 가능. 회사/학교/CGNAT/공용 Wi-Fi | weight 0.45, 단독 block 불가. 같은 IP의 다른 visitors 확인 |
| `visitor_rate` | 같은 `__rek_id`가 10초 8, 60초 30, 600초 120 초과 | `(초과/허용)*100`, max 100 | 가능. F5, 다중 탭, 자동 navigation | weight 1. score 50부터 level block, raw 100 hard deny |
| `session_churn` | 한 IP에서 600초 내 발급된 새 identity가 40 초과 | 40부터 초과 비율에 따라 100 | 가능. CGNAT, cookie 삭제/차단 | weight 0.45, 단독 block 불가. 여러 visitors가 정상인지 확인 |

`policy_reason` 해석:

| 값 | 의미 |
|---|---|
| `risk_below_deny_threshold` | 차단 level/hard deny/degraded 조건 없음 |
| `blocked_engine_level` | SUSPICIOUS 또는 SEVERE band |
| `hard_deny_signal:user_agent` | UA raw score가 기본 40 이상 |
| `hard_deny_signal:visitor_rate` | visitor raw score가 기본 100 이상 |
| `risk_engine_degraded_fail_closed` | provider/signal 오류로 광고 fail-closed 정책 |
| `risk_storage_degraded_fail_closed` | state storage 오류로 광고 fail-closed 정책 |

Reason 하나로 공격을 확정하지 않는다. 여러 독립 signal이 함께 trigger되면 combiner bonus가 붙으므로 단일 신호보다 의심 강도가 높지만, `rate_limit + session_churn`은 둘 다 같은 공유 IP 현상에서 함께 오를 수도 있다. “독립 증거”인지 공통 원인인지 확인한다.

### 14.6 Action, Ads served, Shadow의 차이

| Action | monitor/enforce | 실제 의미 |
|---|---|---|
| ALLOW | 둘 다 | 정책도 허용. 단, 외부 adapter가 별도 이유로 bootstrap을 안 낼 수 있음 |
| MONITOR_DENY | monitor 전용 | 정책상 deny였지만 `ads_allowed=true`; 실제로는 막지 않음 |
| DENY | enforce 전용 | 정책상 deny이고 `ads_allowed=false` |

Viewer에는 Raw `ads_allowed`가 직접 보이지 않는다. 실제 bootstrap 결과는 `Ads served`를 본다.

```text
MONITOR_DENY + Ads served=yes
  = Shadow block 후보, 실제 광고 loader 제공

DENY + Ads served=no
  = 실제 광고 loader 미제공

ALLOW + Ads served=no + external suppression 표시
  = Guard는 허용했지만 site adapter/preview 등 다른 이유로 미제공

DENY + Ads served=no + removed 1
  = 응답에 있던 bootstrap을 Guard가 제거

DENY + Ads served=no + external suppression
  = adapter가 Guard 결과를 보고 애초에 bootstrap을 출력하지 않음
```

`ads_allowed=false`/DENY는 bootstrap policy를 의미하지 특정 slot 하나만 차단한다는 뜻이 아니다. 다만 Viewer만으로 브라우저가 실제 Google 요청을 전혀 하지 않았다고 최종 증명할 수는 없다. 배포/회귀 시 Network에서 `adsbygoogle`, `googlesyndication`, `googleads`, `doubleclick`을 확인한다. `Ads served`도 실제 impression/fill/click을 뜻하지 않는다.

### 14.7 IP, Visitor, UA, URL, 시간 읽기

**같은 IP + 여러 Visitor:** NAT/CGNAT/회사/학교/카페/VPN exit 가능성이 크다. 여러 visitor가 같은 초에 같은 path/pattern을 반복하는지 보되 IP만으로 차단하지 않는다.

**같은 IP + 같은 Visitor:** 같은 브라우저 identity의 반복일 가능성이 상대적으로 높다. Visitor filter로 시간순 path와 score 상승·reset을 본다. cookie 복제/삭제 가능성 때문에 사람 신원은 아니다.

**다른 IP + 같은 Visitor:** 네트워크 이동, 모바일 IP 변경, VPN 변경일 수 있다. HMAC visitor가 지속되면 한 browser profile의 이동 가능성이 있다.

**UA family:** `chrome`, `safari`, `edge`, `firefox`는 수많은 사용자가 공유한다. 같은 UA family만으로 연결하지 않는다. `curl`, `python`, `headless`, `missing`은 자동화 가능성이 높지만 시간/path/visitor/action과 함께 본다.

**Path 집중:** 공격 외에도 페이지의 redirect, meta/JS refresh, 여러 server render, 자동 navigation, 광고 include 중복이 원인일 수 있다. query는 로그에 없으므로 같은 path의 서로 다른 query는 구분되지 않는다. AJAX가 JSON이고 광고가 없으면 애초에 Viewer에 안 들어온다.

**Timestamp:** 표는 선택한 한국시간 날짜의 시:분:초다. 한 identifier를 필터한 뒤 최신순 행을 아래에서 위로 읽거나 raw를 시간순 정렬한다. `14:31:01 → :02 → :02 → :03` 같은 밀집은 속도 신호와 맞춰 보지만 browser redirect/새 탭도 확인한다. Request Interval 컬럼은 없으므로 인접 timestamp 차이를 수동 계산해야 한다.

### 14.8 필터 사용법

| 필터 | 실제 동작 | 사용 시점 |
|---|---|---|
| Date (KST) | 해당 한국 일자와 겹치는 UTC 파일 1~2개 | 오늘/어제 비교. 최근 24시간 rolling 아님 |
| Level | exact NORMAL/ELEVATED/SUSPICIOUS/SEVERE | 위험 band 행 표본 |
| Action | exact ALLOW/DENY/MONITOR_DENY | shadow 후보 또는 실제 deny 조사 |
| Ads served | 실제 bootstrap yes/no | 실제 no-ad 응답과 path 조사 |
| Path contains | case-insensitive substring | 특정 페이지 집중 조사 |
| Identifier prefix | IP 또는 visitor HMAC prefix exact-start | 표시된 12자를 복사해 동일 후보 흐름 조사 |

필터는 AND 조건이다. Summary는 바뀌지 않고 `matching rows`만 변한다. 이유 필터/시간 필터/visitor와 IP의 분리 선택은 없다. Identifier가 둘 중 어느 쪽과 일치했는지는 표 컬럼으로 확인한다.

상황별 절차:

**SUSPICIOUS/SEVERE가 늘었다**

1. Date 선택.
2. Level=SUSPICIOUS, 다시 SEVERE.
3. Action과 Ads served를 확인해 shadow/실차단 구분.
4. 상단 Top reasons와 행 Signals 비교.
5. Visitor 12자를 Identifier에 넣어 앞뒤 흐름 확인.
6. 동일 IP 12자로 다시 검색해 공유 visitor 수 확인.

**특정 visitor 조사**

1. Visitor* 12자를 Identifier에 복사.
2. Level/Action은 all로 풀어 이전 NORMAL부터 본다.
3. 시간, path, score, signal 변화를 읽는다.
4. 같은 IP의 다른 visitor와 비교한다.

**특정 페이지 문제**

1. Path contains에 `/topic.php`처럼 입력.
2. Level/Action/served를 순차 조합.
3. 여러 visitor/UA에서도 같은 상승이면 페이지 구조나 threshold 문제를 의심한다.

**실제 미제공 조사**

1. Ads served=not served.
2. Action=DENY와 ALLOW를 각각 본다.
3. `removed N`, external suppression, policy reason을 구분한다.
4. 브라우저 Network와 AdSense report로 확인한다.

### 14.9 SUSPICIOUS/SEVERE 한 행 분석 순서

```text
행의 Level/Score
  → 다음 threshold까지 margin
  → Signals raw score
  → Policy reason
  → Action과 Ads served (shadow/실차단)
  → Visitor prefix로 전체 흐름
  → 같은 IP의 다른 Visitors
  → Path/UA/한국시간 밀집
  → 전일·동요일 baseline
  → 필요한 경우 Raw 전체 reason
  → 정상 / 의심 / 판단불가로 분류
```

현재 Viewer에는 장기 previous-high history가 없다. “과거 Risk History”는 보관된 여러 UTC 원본 JSONL을 한국시간 기준으로 별도 aggregate해야 한다.

### 14.10 실제 운영 해석 사례

#### CASE 1 — 일반 브라우저 정상

```text
Observed: NORMAL 0, ALLOW, Ads served=yes, Signals=-, chrome
Interpretation: 현재 네 signal이 trigger되지 않은 광고 응답.
Additional check: 같은 날 전체 distribution과 console/AdSense 상태.
Action: 조치 없음.
```

2026-08-31 `/topic.php` 실제 로컬 browser log와 일치한다.

#### CASE 2 — ELEVATED 25와 38

```text
Observed: visitor_rate=25(10 requests/10s) 또는 =38(11/10s), ALLOW.
Interpretation: 둘 다 ELEVATED지만 38이 SUSPICIOUS 50에 더 가까움.
Additional check: visitor 시간순 rows, reload/tab/redirect 여부.
Action: 단일 표본은 차단하지 않음. 정상 표본에서 반복되면 threshold 검토.
```

#### CASE 3 — Shadow SUSPICIOUS

```text
Observed: SUSPICIOUS 50, visitor_rate=50(12/10s),
          MONITOR_DENY, Ads served=yes.
Interpretation: 현재 정책이면 차단이지만 monitor이므로 실제 광고는 제공됨.
Additional check: 같은 visitor의 0→25→38→50 상승과 page navigation.
Action: 정상/자동화/판단불가 표본 분류. 실제 공격으로 즉시 확정하지 않음.
```

#### CASE 4 — 정상 행동을 흉내 낸 합성 stress가 SEVERE

```text
Observed: 다중 tab/back-forward/navigation을 수초에 집중,
          visitor_rate 75→88→100, MONITOR_DENY 7건.
Interpretation: 엔진은 의도대로 한 identity 폭주를 잡았지만 실제 사람이
                정상 UI에서 같은 request 수를 만들 수 있다면 오탐.
Additional check: 페이지 중복 navigation, 사용자 행동 재현, p95/p99.
Action: enforce 전환 보류. 원인이 정상 구조면 visitor windows 완화 후 재-monitor.
```

#### CASE 5 — IP 기반 signal 급증

```text
Observed: rate_limit 또는 session_churn이 Top reason 1위,
          같은 IP*에 서로 다른 Visitor* 다수.
Interpretation: 공격일 수도 있으나 CGNAT/회사/학교/공용 Wi-Fi 가능성 큼.
Additional check: unique visitors, 시간/path 공통성, proxy REMOTE_ADDR.
Action: IP를 공격자로 등록하지 않음. 실제 IP 복원과 shared-IP bias 먼저 점검.
```

#### CASE 6 — DENY인데 combined level은 ELEVATED

```text
Observed: ELEVATED 40, policy_reason=hard_deny_signal:user_agent,
          DENY 또는 MONITOR_DENY.
Interpretation: level band가 아니라 raw UA 40 hard-deny 규칙이 작동.
Additional check: UA family curl/python/headless/missing, proxy header 손실.
Action: 자동화 증거를 검토. 정상 browser header가 손실됐다면 rule보다 인프라 수정.
```

#### CASE 7 — Ads not served지만 공격 차단이 아님

```text
Observed: Ads served=no, removed=0, external suppression 표시.
Interpretation: site adapter/preview가 bootstrap을 미출력했을 수 있음.
Additional check: Action, Policy reason, preview/custom provider, Network.
Action: Viewer Block rate를 공격 차단률로 보고하지 않음.
```

### 14.11 정상/주의/위험 판단 원칙

초기에는 고정적인 “정상 비율”을 정하지 않는다.

- **정상 후보:** NORMAL 중심, score margin 충분, 동일 visitor에 지속 상승 없음, degraded 0.
- **주의:** ELEVATED가 threshold 50 근처, 특정 signal/path가 정상 visitors 전반에서 반복, MONITOR_DENY 증가.
- **우선 조사:** SUSPICIOUS/SEVERE rate 급증, 같은 visitor의 지속 고득점, 여러 IP에서 동일 시간/path/자동화 UA, degraded 발생.
- **긴급 운영 위험:** enforce에서 no-bootstrap/DENY rate와 수익 하락이 동시 급증, proxy 때문에 unique IP 급감, log/disk 장애.

다음은 무조건 공격으로 보지 않는다.

- VPN/모바일 IP 변경.
- 같은 IP의 여러 visitors.
- 1회 빠른 이동/F5/여러 탭.
- 일반 Chrome/Safari family 반복.
- 특정 path의 위험 증가만 있는 경우.
- 단일 SUSPICIOUS/SEVERE row.

Rule/Threshold 문제를 의심할 조건:

- 재현 가능한 정상 browser가 자주 SUSPICIOUS/SEVERE.
- 한 signal이 고위험 행 대부분을 만들고 다른 증거가 없음.
- 동일 IP의 서로 다른 정상 visitors가 함께 상승.
- 정상 visitor p95/p99 max가 50에 지나치게 가까움.
- fixed window 만료 후 새 요청에서도 예상과 달리 score가 reset되지 않음.
- 페이지 수정 후 특정 path의 response 수와 visitor_rate가 함께 급증.

### 14.12 Viewer 기반 Morning/Weekly 절차

**Morning Check**

1. Viewer를 허용 IP에서 열고 최신 한국시간 Date 선택.
2. Total이 전일과 비슷한지 확인하되 전체 traffic이 아님을 기록.
3. level distribution과 SUSPICIOUS+ response rate 계산.
4. monitor이면 MONITOR_DENY rate, enforce이면 DENY rate와 Block rate를 구분.
5. Degraded를 확인.
6. Top reasons/paths/UA 변화 확인.
7. SUSPICIOUS/SEVERE 행에서 visitor 표본을 뽑아 전체 흐름 확인.
8. 같은 IP의 다른 visitors로 NAT 가능성 확인.
9. 전일/동요일과 비교하고 이상만 사건 조사로 넘김.

Viewer는 한국시간 하루를 보기 위해 겹치는 UTC 파일 1~2개를 자동으로 읽는다. 단, “최근 24시간” rolling 선택 기능은 없다.

**Weekly Review**

1. 각 날짜의 Total/level/action/served/degraded를 외부 표에 기록.
2. 이번 주와 지난 주의 비율을 동일 요일/시간대로 비교.
3. Top reason/path 변화와 false-positive 사례를 정리.
4. visitor별 max score distribution/p95/p99와 threshold margin 분석.
5. shared IP와 proxy 문제 확인.
6. raw log bytes/rows, 20MB cap, viewer 응답시간 확인.
7. ASN/Network 분석은 Viewer 미지원이므로 필요 시 별도 privacy-reviewed aggregate 사용.
8. threshold 변경은 한 rule씩 제안하고 monitor 재검증.

### 14.13 Viewer와 AdSense Report

```text
Viewer = 우리 서버가 계산한 위험/loader 제공 여부
AdSense = Google이 관측한 광고 요청·노출·수익·정책 결과
```

두 데이터는 1:1로 일치하지 않는다. Viewer `Ads served=yes`는 loader가 HTML에 있었다는 뜻이지 광고가 채워졌다는 뜻이 아니다. AdSense invalid traffic 판정/클릭은 Viewer에 없다. Viewer와 분석기는 `Asia/Seoul`로 맞추고, response와 impression, sampling, ad blocker, fill 차이를 고려해 시간대 추세만 비교한다.

### 14.14 한눈에 보는 Cheat Sheet

| 화면에서 보이는 것 | 먼저 생각할 것 | 바로 공격 판정? |
|---|---|---:|
| NORMAL 0 | trigger 없음 | NO |
| ELEVATED 25 | 관찰 시작점, 50까지 margin 25 | NO |
| ELEVATED 49 | 차단 band까지 1점 | NO, 정상 표본 집중 확인 |
| SUSPICIOUS/SEVERE | 이유·visitor 흐름·Action | NO |
| MONITOR_DENY | would-block, 실제 허용 | NO |
| DENY | 정책상 실제 차단 | 공격자 확정은 NO |
| Ads served=no | bootstrap 미제공/제거 | 원인 확인 전 NO |
| Block rate 상승 | no-bootstrap response 증가 | DENY rate와 구분 |
| `visitor_rate` | 한 cookie identity의 속도 | refresh/tab 가능 |
| `rate_limit` | 한 IP 전체 속도 | NAT 가능 |
| `session_churn` | 한 IP의 새 identities | CGNAT/cookie 변화 가능 |
| `user_agent` | header 누락/자동화 marker | 인프라 header 손실 확인 |
| 같은 IP 다수 | 공유망 가능 | NO |
| 같은 Visitor 지속 상승 | 한 browser 반복 가능성 | 추가 조사 |
| 특정 Path 집중 | 공격 또는 페이지 구조 | NO |
| Degraded > 0 | 엔진/storage 장애 | 공격보다 운영 장애 우선 |

더 짧은 현장용 표는 `ADSENSE-RISK-VIEWER-CHEATSHEET.md`에 분리했다.

설치·파일·호출 흐름·브라우저 증거·Q1~Q20은 이 통합 문서의 Part I를 참조한다.

## 15. AdSense 집계 보고서·리다이렉트·공격 후보 상관분석

### 15.1 가능한 결론과 불가능한 결론

Google AdSense 집계에는 클릭별 IP/Visitor ID가 없다. 따라서 이 패키지는 `ip_hmac=X가 10회 클릭`같은 결론을 생성하지 않는다. 대신 다음 사실을 독립적으로 계산한다.

1. 해당 날짜·사이트·경로그룹의 AdSense CTR이 과거 중앙값 대비 급증했는가.
2. 같은 범위의 로컬 고위험 요청 비율이 함께 급증했는가.
3. 그 구간에 자동화 UA/강한 Visitor 속도 등 직접 로컬 근거를 보인 Visitor HMAC가 있는가.
4. 같은 Visitor HMAC가 여러 IP HMAC에서 유지되어 IP 회전 정황이 있는가.

이 네 가지가 같이 나와도 클릭자 확정이 아니라 **조사 우선순위**다.

### 15.2 안정적인 사이트/경로 설정

```php
// adguard/config/guard.php
'analytics' => array(
    'site_id' => 'example-site',
    'site_domains' => array('www.example.com', '*.example.com'),
    'reporting_timezone' => 'Asia/Seoul',
    'route_groups' => array(
        'topic' => array('/topic.php', '/topic/*', '/new-topic.php'),
    ),
),
```

도메인/경로가 바뀌어도 같은 사업적 페이지면 같은 `site_id`/`route_group`에 넣는다. 정확한 목적지 URL이나 query는 로그하지 않는다. 서버가 안정적인 리다이렉트 규칙 ID를 알면 최종 광고 페이지에서:

```php
ad_guard_set_analytics_context(array(
    'redirect_rule_id' => 'campaign-main',
    'route_group' => 'topic',
));
```

중간 301/302에 광고가 없으면 AdGuard 위험 로그는 생성되지 않는다. 리다이렉트 이벤트 자체는 별도 서버 감사 로그에 `redirect_rule_id`, 성공/실패, 목적지 host 그룹만 남기고 위험 점수에 합산하지 않는다.

### 15.3 보고서 가져오기

AdSense UI CSV는 가능하면 영문 header로 `DATE`, `PAGE_URL`, `CLICKS`, `IMPRESSIONS`, `PAGE_VIEWS`, `ESTIMATED_EARNINGS`를 포함한다. 최소 14일 범위를 사용해야 baseline이 생긴다.

```bash
php adguard/tools/import-adsense-report.php /secure/path/adsense.csv
```

API 자동 수집은 `adsense.readonly` OAuth를 사용하고 secret/token은 환경변수에만 둔다.

```bash
export ADSENSE_ACCOUNT='accounts/pub-...'
export ADSENSE_CLIENT_ID='...'
export ADSENSE_CLIENT_SECRET='...'
export ADSENSE_REFRESH_TOKEN='...'
php adguard/tools/fetch-adsense-report.php 2026-08-01 2026-08-31
```

수집물은 `adguard/storage/adsense/`, 분석물은 `adguard/storage/analysis/`에 0600으로 저장되며 웹 차단 파일이 함께 생성된다. nginx는 기존처럼 `/adguard/storage/` 전체를 차단해야 한다.

### 15.4 비교 실행과 해석

```bash
php adguard/tools/analyze-adsense-risk.php 2026-08-31
php adguard/tools/analyze-adsense-risk.php 2026-08-31 --adsense=/secure/snapshot.json
php adguard/tools/analyze-adsense-risk.php 2026-08-31 --json
```

| 출력 | 의미 | 자동 IP 차단 근거? |
|---|---|---:|
| `CORRELATED_AGGREGATE_ANOMALY` | CTR과 로컬 risk rate 동시 급증 | NO |
| `ADSENSE_ANOMALY_ONLY` | 클릭 집계만 급증; 레이아웃/유입구성/채널 조사 | NO |
| `LOCAL_RISK_ANOMALY_ONLY` | 위험 요청만 급증; 사전 방어가 클릭을 줄였을 수 있음 | NO |
| `STRONG_LOCAL_BEHAVIOR` | 자동화 UA 또는 visitor_rate raw 100 등 | 반복·정책 검토 후만 |
| `ROTATING_IP` | 같은 Visitor HMAC의 복수 IP | NO; VPN/이동망 가능 |
| `SHARED_IP_CAUTION` | 한 IP의 복수 Visitor | NO; NAT/CGNAT 가능 |
| `DISTRIBUTED_BEHAVIOR_PATTERN` | 같은 유입그룹·국가·UA 계열·신호조합이 복수 IP/Visitor에서 반복 | NO; 소스/WAF/경로 정책 조사 후보 |

자동 차단은 AdSense 집계 이상이 아니라, 현재 정책처럼 요청 즉시 관찰된 직접/단일 Visitor 행동 근거로만 결정한다. IP/network HMAC는 조사용 보조 정보다.
