# AdGuard v2 Phase 0 — v1 Baseline Report

- 조사/측정: 2026-09-10, 최종 검사 및 기록: 2026-09-11 (Asia/Seoul).
- 대상: 현재 AdGuard v1 폴더. 프로덕션 동작 및 설계 원문 변경 0.
- 원본 기준 커밋: `24eeb2b5517eb3bf6c406708fd06aafa5a586f58`.
- 원본 파일 목록/해시: `phase0/original-files.json` (88개). Phase 0 이전 파일을 원문 그대로 커밋함.
- 검증 결과: `phase0/checks.json`, 최초 결과: `phase0/checks-initial.json`, HTTP 표본/집계: `phase0/http-baseline.json`.
- 정적 확인, 실행 관측, 미검증 항목을 아래에서 구분한다. v1 결함을 v2의 허용된 설계 변경으로 간주하지 않는다.

## 1. 검증 결과 및 재현 명령

작업 폴더에서 PowerShell 7 및 PATH의 PHP/curl을 사용한다. HTTP 검사는 로컬 loopback 접근과 자식 프로세스 실행이 가능한 환경에서 실행해야 한다.

```powershell
& .\tools\run-phase0-checks.ps1
php tests/phase0-baseline-test.php
& .\tools\phase0-benchmark.ps1 -Samples 200 -Warmup 20
git diff --check
```

| 검사 | 실제 결과 |
|---|---|
| 기존 PHP 테스트 스크립트 19개 | 18 PASS, 1 SKIP, 0 FAIL |
| 신규 Phase 0 회귀 테스트 | 19 assertions, 0 failures |
| engine boundary 검사 | PASS |
| PHP 5.6 검사기 self-test | PASS; 알려진 비호환 fixture 검출 및 정상 fixture 판별 |
| PHP 5.6 정적 검사 | 70개 파일, post-5.6 syntax/function/class/constant 검출 0 |
| PHP lint | 74개 파일 PASS (실행 바이너리 PHP 8.5.5) |
| 최종 검사 집계 | 23 PASS, 0 FAIL, 1 SKIP. 스크립트/검사 단위이며 assertion 개수와 다름 |
| 실제 HTTP 관측 | 아래 정상 응답/실패 주입/접근제어 표 참조 |

SKIP은 `tests/ad-defense-bridge-test.php`다. 저장소 밖 `include/ad-defense.php`가 없어 해당 호스트 통합에만 필요한 검사가 적용되지 않는다. 누락된 어댑터를 임의로 만들거나 PASS로 합산하지 않았다.

PHP 5.6 바이너리는 현재 환경에서 확인되지 않았다. **PHP 5.6 실제 런타임 통과를 주장하지 않는다.** 정적 검사는 entry/config/src/engine/tests와 운영 CLI 도구를 포함하고, 의도적으로 최신 문법을 넣은 `tools/fixtures/php56-known-bad.php` 및 검사기 자체를 제외한다. 전체 PHP lint에는 해당 fixture도 포함한다.

처음 제한 환경에서는 기존 cookie HTTP 테스트가 대기해 중단했다. 동일 코드를 제한 밖에서 실행하니 7개 HTTP assertion이 통과했다. 최종 실행기도 테스트별 30초 timeout과 자기 자식 프로세스 종료를 사용한다. 이것은 프로덕션 수정이 아니라 검사 실행 환경 처리다.

## 2. 파일 구조와 호출관계

```text
adguard.php / auto-prepend.php        공개 boot/decision API
config/guard.php                      monitor, logger, viewer 설정
config/engine.php.example             선택적 engine 설정의 예시; 실제 engine.php 없음
src/Guard.php                         output buffering, 광고 탐지, 정책, logger 호출
src/AdsenseDetector.php               bootstrap 탐지/제거, ad delivery inventory
src/DecisionLogger.php                flat schema 3 JSONL, 원본 IP/UA, health log
src/AnalyticsContext.php              site/host/route/referrer/network 분류
src/LogReader.php + viewer.php        로컬 필터/집계/페이지, peer IP 접근제어
src/AdsenseReport.php                 AdSense 집계 보고서 읽기
src/RiskCorrelationAnalyzer.php       기존 로그/AdSense 사후 상관 분석
engine/risk-engine.php                engine build/evaluate/inspect
engine/src/Engine.php                 확률적 GC, 신호 평가, 점수 결합
engine/src/Identity/IpResolver.php    공용 IP 해석 함수
engine/src/Signals/                   IP/visitor rate, session churn, UA
engine/src/Storage/FileStorage.php    key별 JSON, shard, lock, GC
engine/src/KnownCrawlers/              UA claim, 로컬 range cache/검증
storage/                             기본 runtime 위치; 현재 접근 차단 파일만 존재
tools/                               CLI refresh/report/import/fetch/benchmark/check
tests/ + engine/tests/                독립 PHP 테스트, HTTP/동시성 검사
docs/adguard/                         v2 Source of Truth 및 Phase 0 결과
```

일반 자동 처리 경로:

```text
auto-prepend.php (웹 요청 또는 CLI opt-in)
  → ad_guard_boot() → ad_guard_instance() → Config::load()
  → Guard::start() → ob_start(filterOutput)
  → application HTML 출력
  → FINAL output buffer: Guard::filterOutput() → processHtml()
      ├ 비HTML 또는 광고 미감지: 원문 반환, engine/log 미실행
      └ 광고 감지 또는 명시적 opportunity:
          → AdsenseDetector::inventory()
          → Guard::getDecision() (요청 내 cached)
          → provider → risk_engine_evaluate()
          → risk_engine_build() (요청 내 cached)
          → RequestContext(engine identity 설정)
          → Engine::run(mutate=true) → maybeGc() → enabled signals
          → ScoreCombiner → Guard 광고 정책(mode/enforce/hard deny/degraded)
          → enforce DENY일 때 bootstrap 제거
          → DecisionLogger::log() (Guard 객체당 1회)
          → 최종 HTML
```

`ad_guard_decision()`을 명시적으로 호출하면 출력 종료 전 engine이 실행될 수 있지만 opportunity를 표시하지는 않는다. `ad_guard_ads_allowed()`는 opportunity도 표시하므로 최종 HTML에 광고가 없어도 logger가 실행될 수 있다. `risk_engine_inspect()`는 mutate=false이며 별도 읽기용이다. 따라서 “광고 없는 모든 요청이 무조건 누락”으로 일반화하지 않고 **자동 boot 경로에서 누락**됨을 구분한다.

## 3. 요청된 핵심 7개 항목

| 항목 | 관측/근거 | 결론 |
|---|---|---|
| 광고 없는 요청의 rate/event | 신규 테스트: public boot에서 원문 동일, state 디렉터리 미생성, event 0 | 자동 경로는 포함하지 않음. 명시적 광고 API 예외 존재 |
| UA 단독 hard deny | 실제 signal/engine: curl UA=40, rate/visitor/churn=0; enforce policy reason=`hard_deny_signal:user_agent` | 가능. monitor는 MONITOR_DENY 기록 후 HTML 보존 |
| guard/engine IP 설정 분리 | guard만 proxy 신뢰: 로그 IP=198.51.100.8, engine bucket=203.0.113.10 | 동일 resolver를 쓰더라도 설정이 달라 identity가 갈라짐 |
| viewer REMOTE_ADDR 사용 | `viewer.php:24`; HTTP probe에서 허용된 XFF client와 trusted peer를 보내도 404 | viewer는 peer를 직접 allowlist 비교 |
| raw IP/full UA 저장 | schema 3 기록과 1,400바이트 UA 입력을 검증 | 원본 선택 IP 저장, UA는 최대 1,024바이트. query/Authorization/cookie 원문 미저장 |
| web-root storage 노출 | 임시 docroot에 기존 `.htaccess`/`web.config`와 합성 JSONL만 배치; HTTP 200/내용 표시 | 차단 설정을 해석하지 않는 서버에서는 파일이 노출됨. 실제 운영 서버는 미검증 |
| PHP 5.6 문법 | 기존 self-test 및 70개 정적 검사 PASS | 발견된 비호환 없음. PHP 5.6 실제 실행은 미검증 |

## 4. 현재 로그 schema

Decision event는 `src/DecisionLogger.php:151`의 flat `schema_version=3`; health event는 `schema_version=1`이다. timestamp는 UTC `gmdate('c')`로 초 단위이며 request_id는 logger 시점에 생성한다.

| 그룹 | 저장 필드 |
|---|---|
| 식별/route | schema_version, timestamp, request_id, site_id, host, path, route_group, redirect_rule_id, method |
| 유입 | referrer_host, referrer_group, country, cf_ray |
| network/visitor | raw_ip, ip_canonical, ip_source, ip_resolution_status, ip_hmac, network_hmac, visitor_hmac |
| UA/crawler | user_agent, ua_family, crawler_status, crawler_vendor, crawler_group |
| 요청/정책 | request_type, is_document, ad_opportunity, engine_level, score, action, policy_reason, ads_allowed, degraded |
| 광고 전달 | adsense_detected, bootstrap_removed, ads_served, external_suppression, ad_delivery |
| 근거 | sample_rate, reasons, signals |

`ad_delivery`는 loader opportunity/provided/blocked/missing와 수가 제한된 manual placement inventory다. 클릭수나 실제 impression 증거가 아니다. `signals`에는 score/triggered/storage_degraded만 남고, engine의 count/window/allowed 상세 metrics는 유실된다. 원본 IP와 HMAC은 병행 저장되며 HMAC이 원본 IP를 대체하지 않는다.

별도 peer IP, 전체 forwarded chain, response status/duration/bytes, event_id, agent/rule version은 현재 schema에 없다. `path`만 저장하고 query 값을 버리며 Referer는 host만 남긴다. 일반 헤더 전체 또는 POST body dump는 없다. secret 배제 테스트는 합성 입력과 기본 내장 경로를 대상으로 하며 임의 외부 provider가 reason에 secret을 넣는 경우까지 보증하지 않는다.

## 5. 신호/상태/검증기

| 신호 | 기본 설정과 동작 |
|---|---|
| IP rate | fixed windows 10s/120회, 300s/1,200회; weight 0.45; `rate:<canonical IP>` |
| visitor rate | 10s/8회, 60s/30회, 600s/120회; weight 1; cookie hash별 key; cookie 없으면 neutral |
| session churn | IP별 600s, threshold 40, weight 0.45; 실제 cookie 발급에 성공한 새 방문만 count |
| UA | 누락/automation marker 40, Accept 누락 15, Language 10, Encoding 5; 일반 crawler self-claim 자체는 가점 0 |
| 결합 | 최대 weighted score + 나머지 triggered weighted score 합의 25%; cap 100. UA/Accept 동시 누락은 SUSPICIOUS 하한 |
| 등급 | NORMAL/ELEVATED/SUSPICIOUS/SEVERE; threshold 25/50/75 |

Guard는 blocked_levels=SUSPICIOUS/SEVERE, user_agent≥40 또는 visitor_rate≥100일 때 광고 거부 정책을 만들 수 있다. 기본 mode는 template와 built-in 모두 monitor다. enforce에서는 provider/signal/storage 장애에도 광고를 억제하는 fail_closed 규칙이 존재한다. 페이지 전체 차단과는 별개다.

FileStorage는 md5(key) 기반 2자리 shard와 JSON 파일, 최대 20×10ms non-blocking lock 재시도를 사용한다. key당 window 상태는 고정 크기이지만 전체 key 수에 hard cap은 없고, 기본 1% 요청에서 2일 이전 state를 재귀 순회한다. 고유 key 폭증 시 전체 처리 상한이 보장되지는 않는다. 동시성 테스트는 8 worker와 저장 실패·GC·접근 차단 파일 생성 검사를 통과했다.

Logger는 event 8,192바이트, 일일 20MiB, health 일일 1MiB, retention 90일 기본값이다. primary append는 non-blocking lock이나 health append는 `LOCK_EX`이며 명시 timeout이 없다. 크기 검사는 lock 전 수행하므로 동시 쓰기의 엄밀한 총량 cap으로 단정하지 않는다. retention은 요청 중 glob 순회다.

CrawlerVerifier는 고정 claim token 목록과 로컬 CIDR만 검사한다. 공식 range에 일치하면 `verified`지만 이는 v2 strict allowlist 자격이 아니다. Google claim별 common/special/fetchers group 구분은 존재한다. FCrDNS/RFC 9421/work queue는 없다. cache는 14일 후 stale이며 기본 정상 브라우저에서는 lazy loading으로 읽지 않는다.

`tools/refresh-crawler-ranges.php`는 CLI에서 Google 세 목록을 내려받거나 `--offline` 데이터를 사용한다. redirect, timeout, JSON/CIDR 검증, partial failure 시 기존 cache 보존, 임시 파일→rename 교체를 구현한다. 이번 단계에서는 원격 목록을 다운로드하지 않았다. 정상 boot/engine/logger 경로를 정적으로 추적했을 때 외부 DNS/HTTP/중앙 DB 호출은 찾지 못했으며, 네트워크 syscall 계측으로 증명한 결과는 아니다.

## 6. viewer와 README 불일치

viewer는 기본 빈 allowlist로 접근 거부, HTML escape 및 제한된 query parameter 입력을 사용한다. LogReader는 기간에 해당하는 파일을 한 번씩 열고 JSONL을 줄 단위로 읽으며 malformed JSON을 건너뛴다. summary는 해당 기간 전체를 계산하고 행은 page offset+size tail을 유지한다. page size는 10~500, actor bucket 기본 10,000이지만 큰 page offset/단일 긴 JSONL line/전체 기간 scan에는 별도 총량 budget이 없다. v2의 엄격한 bounded reader 완료로 간주하지 않는다.

- **확정 불일치:** `README.md:170`은 raw IP와 전체 UA를 기록하지 않는다고 설명하지만 schema 3은 raw IP와 bounded full UA를 저장한다. `viewer.php`와 `src/LogReader.php`의 “truncated pseudonyms” 중심 주석도 raw IP 표시를 충분히 설명하지 않는다.
- `engine/README.md:79`의 `risk-engine/storage/`는 현재 기본 `adguard/storage/engine/`과 다르다. standalone 예제와 패키지 경로를 구분해야 한다.
- `config/guard.php:17`은 built-in default=enforce라고 설명하지만 `src/Config.php:28`과 테스트는 monitor임을 확인한다.
- `ScoreCombiner` class 주석은 weighted average라고 표현하지만 실제 메서드는 maximum+bonus 방식이다.
- `RangeCache::load()`는 PHP cache를 include한다. 잘못된 배열 shape와 PHP 문법 자체가 깨진 cache를 구분해야 하며, 현재 catch(Exception)만으로 모든 최신 PHP Error/ParseError까지 막는다고 보증할 수 없다. malformed PHP cache의 HTTP 실패 주입은 이번 단계에서 실행하지 않았다.

문서와 production 주석은 감사 결과만 기록하고 이번 단계에서 수정하지 않았다.

## 7. HTTP 성능 기준값

2026-09-10 PHP 8.5.5 ZTS/Windows 내장 서버, loopback, single worker, `opcache.enable_cli=0`, 시나리오당 warmup 20회 + 측정 200회, 순서를 순환 배치했다. 별도 임시 state/log를 사용했고 default rate/GC 정책 아래 64개 합성 direct client/visitor를 순환시켜 모든 guard 광고 요청이 NORMAL·비degraded 상태였음을 검사했다. 실제 광고 URL은 문자열이며 HTTP client는 JS를 실행하지 않는다.

| 시나리오 | HTTP 왕복 p50 / p95 (ms) | PHP 계측 p50 / p95 (ms) | PHP 계측 평균 (ms) | PHP peak 사용량 p50 (bytes) |
|---|---:|---:|---:|---:|
| guard 없는 최소 페이지 | 1.0440 / 1.6806 | 0.002861 / 0.005007 | 0.002860 | 442120 |
| guard 있는 비광고 페이지 | 1.1119 / 1.9214 | 0.092983 / 0.251055 | 0.125618 | 464696 |
| guard 없는 광고 마크업 | 0.9435 / 1.4709 | 0.001907 / 0.004053 | 0.002353 | 442392 |
| guard 있는 정상 광고 페이지 | 3.2212 / 6.9063 | 2.087116 / 5.529881 | 2.680072 | 496256 |

PHP 계측은 fixture 설정/HTML 문자열 준비 뒤부터 boot/include/출력 필터/logger 완료까지의 구간이다. 전체 PHP 프로세스 비용이나 네트워크 시간과 같지 않다. 4개 시나리오의 allocator peak는 모두 2MiB였으며 실제 used peak와 구분해 JSON에 기록했다. 정상 광고 처리 구간 p99는 6.422043ms다. guard 없는 광고 대비 평균 계측 차이는 약 2.678ms이지만 개별 요청 차이의 분위수는 계산하지 않았다.

| 실제 HTTP probe | 결과 |
|---|---|
| 정상 페이지 | 측정 800응답 모두 200, guard 유무별 HTML 일치 |
| state 경로를 디렉터리 대신 파일로 막음 | monitor HTTP 200, degraded=1, 광고 포함 원문 유지 |
| trusted proxy 뒤 viewer 허용 client | peer allowlist에 없으므로 404 |
| 차단 설정 파일과 합성 JSONL을 내장 서버 docroot에 둠 | HTTP 200, 합성 marker 표시; 해당 서버가 차단 설정을 해석하지 않음 |

실제 배포 서버/FPM/PHP 5.6/OPcache 운영 구성, 장시간 부하, near-full disk, DNS/중앙 장애, 큰 로그, 5xx 전체 실패 matrix는 측정하지 않았다. 위 수치는 Phase 1 이후 같은 harness로 비교할 로컬 기준이며 서비스 성능 보증 또는 enforce 배포 승인이 아니다.

## 8. 변경/검증 한계 및 다음 단계

2026-09-11 재개 시 `docs/adguard/`가 없어져 Git 내부 작업 snapshot blob에서 문서와 결과를 복원했다. 복원 직후 최초 88개 파일의 SHA-256이 전부 일치했다. 소실 원인은 확인하지 못했다. 원본 보존 기준 커밋의 whitespace 검사에는 기존 공백/CRLF 경고 615개가 있었고 `phase0/original-whitespace.json`에 보존했다. 이를 없애려고 production 또는 설계 원문을 재포맷하지 않았다. Phase 0 변경분의 diff 검사는 별도로 수행한다.

독립 리뷰는 사용량 제한으로 실행되지 않았다. 주 에이전트가 test isolation, 합성 입력, 측정 경계, timeout, 자기 임시 경로/프로세스 정리 및 결과를 직접 확인했다.

**DECISION_NEEDED (Phase 1 전):** 현재 schema 3과 설계 예시 2의 version/legacy reader 호환 전략. 제품 v2라는 이름만으로 schema 번호를 임의로 낮추지 않는다. **후속 정책 단계 전:** storage/provider 장애 시 광고 fail_closed와 v2 fail-open의 적용 범위. 이번 Phase 0은 관측만 했으며 설계를 변경하지 않았다.

> 후속 결정 D1 (2026-09-11 사용자 승인): 위 schema 미결 항목은 해소됐다. 신규 event는 schema 4, 기존 schema 3은 원형 보존하며 공통 읽기 변환을 적용한다. 현재 기준은 DESIGN §9.3 및 IMPLEMENTATION_STATE를 따른다. Phase 1 파일 범위에는 공통 변환 계층과 `src/LogReader.php`, `src/RiskCorrelationAnalyzer.php`, `tools/report.php`의 최소 호환 및 관련 fixture가 추가된다. 아래 목록과 Phase 0 측정 결과는 당시 baseline 기록으로 보존한다. 광고 fail_closed 정책 항목은 이번 결정 대상이 아니다.

Phase 1에 예상되는 정확한 파일 범위:

- 신규: `src/Telemetry/RequestTelemetry.php`, `src/Telemetry/ResponseTelemetry.php`, `src/Telemetry/TelemetryEvent.php`, `src/Storage/LocalEventStore.php`, `tests/telemetry-foundation-test.php`.
- 수정 후보: `adguard.php`(요청 시작 orchestration), `src/Guard.php`(광고 책임과 telemetry 책임 분리), `src/DecisionLogger.php`(event writer 연결/호환), `src/Config.php`, `config/guard.php`(event cap/health 설정).
- 회귀 검토: `tests/phase0-baseline-test.php`, `tests/ad-guard-test.php`, `tests/auto-prepend-integration-test.php`, `tests/raw-ip-schema-test.php`, `tests/adsense-correlation-test.php`. 현재 v1의 누락 동작을 고정한 assertion은 의도된 Phase 1 변경 부분만 승인된 v2 요구에 맞춰 갱신하고, HTML 보존 assertion은 유지한다.
- `engine/risk-engine.php`, `engine/src/Engine.php`, `engine/src/RequestContext.php`는 기존 counter/decision 재사용 및 중복 평가 방지를 위해 먼저 읽는다. identity refactor, bot 검증, 중앙 export/UI는 Phase 1에서 선행 구현하지 않는다.
- `docs/adguard/IMPLEMENTATION_STATE.md`와 이 보고서를 함께 읽고 미결 항목 해소 후 별도 Phase 1 프롬프트로 시작한다.
