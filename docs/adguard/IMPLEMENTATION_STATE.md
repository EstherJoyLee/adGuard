# AdGuard v2 IMPLEMENTATION_STATE

> Codex는 모든 단계 시작 전에 이 파일과 `ADGUARD_V2_DESIGN.md`를 읽는다.
> 한 단계가 끝날 때 반드시 이 파일을 갱신한다.
> 과거 항목을 지우지 말고 Phase History에 누적한다.

## Source of Truth
- Design: `docs/adguard/ADGUARD_V2_DESIGN.md`
- Design version: 2026-09-10 작성본 + 2026-09-11 사용자 승인 D1 (§9.3: 신규 schema 4/legacy 읽기 호환). 최초 설치 원문은 baseline 커밋에 보존.
- Implementation plan: `docs/adguard/ADGUARD_V2_IMPLEMENTATION_PLAN.md`
- Phase prompts: `docs/adguard/ADGUARD_V2_CODEX_PHASE_PROMPTS.md`
- State template: 상위 `../docs/IMPLEMENTATION_STATE_TEMPLATE.md`를 복사하여 이 파일을 초기화함.
- Document source: `C:/Users/User/Desktop/Template/AdGuard-Template/docs/`. 최초 첨부 경로의 하위 폴더는 현재 없으며, 같은 이름의 제공 문서들이 이 위치에 있음.
- Priority: 설계 문서 → 이 파일의 현재 구현 상태 → 실제 코드/테스트 → 에이전트 추정. 설계 변경이 필요하면 `DECISION_NEEDED`로 기록하고 임의로 변경하지 않음.
- Current phase: Phase 1 — Telemetry Foundation 완료.
- Last completed phase: Phase 1 (2026-09-11).
- Last verified implementation/test commit: `f47bfc6` (`test: cover read-only telemetry storage`). Phase 1 실행 결과/상태 문서는 이 커밋 뒤에 추가하며 최종 commit은 `git log`로 확인한다. 원본 v1 baseline은 `24eeb2b5517eb3bf6c406708fd06aafa5a586f58`.
- Current branch/worktree: `phase1-telemetry`, `C:/Users/User/Desktop/Template/AdGuard-Template/adguard/.worktrees/phase1-telemetry`. 작성자는 사용자 제공 `EstherJoyLee <bonjourjj3@gmail.com>`으로 이 저장소에만 설정.
- Git remote: `origin = https://github.com/EstherJoyLee/adGuard.git`. 공개 전환 후 fetch 성공. 이번 단계는 로컬 커밋만 수행하며 push/upstream 설정은 없음.
- Scope: Phase 1 telemetry 기반, schema 4 writer, schema 3/4/versionless 공통 read 변환 및 기존 reader/analyzer/report 최소 호환까지 구현. Phase 2 identity, Phase 3 behavior/risk, Phase 4 bot, Phase 5 UI, Phase 6 export는 선행하지 않음.
- Phase 0 report: `docs/adguard/PHASE_0_BASELINE_REPORT.md`; 실행 결과는 `docs/adguard/phase0/`.
- Phase 1 plan/results: `docs/superpowers/plans/2026-09-11-telemetry-foundation.md`; 실행 결과는 `docs/adguard/phase1/`.

## Immutable Constraints
- PHP 5.6+ syntax compatibility
- normal request path external DNS = 0
- normal request path external HTTP = 0
- normal request path central DB connection = 0
- fail-open
- no UA-only hard deny/allowlist
- every claimed bot enters FCrDNS verification workflow
- raw IP must remain recoverable for authorized forensic investigation
- never collect Authorization/Cookie/password/full body
- no click-counter integration yet
- no transparent masking UI
- no unrelated refactor
- 위 항목은 v2 구현의 필수 제약이며, 현재 v1이 전부 충족한다는 의미가 아님. 확인된 차이는 아래에 기록함.
- 기존 정상 페이지/광고 UX 보존. 초기 운영은 monitor-only이며 실데이터 검증 전 임의 threshold로 광고를 차단하지 않음.
- AdGuard 내부 오류/스토리지 실패/중앙 장애/DNS 장애가 application 500 또는 요청 지연으로 전파되어서는 안 됨. 검증 장애는 UNKNOWN/PENDING으로 처리하고 spoof로 단정하지 않음.
- 모든 claimed bot의 FCrDNS는 요청 경로 밖에서 수행. 자동 allowlist는 기본적으로 FCrDNS PASS와 strict verification 기준을 충족해야 함. UA claim, 공식 range 단독, signature VALID 단독을 provider 신뢰로 간주하지 않음.
- 요청 시작 직후 광고 유무와 무관하게 telemetry를 수집하고, IP 판정은 단일 IdentityResolver와 trusted proxy 정책을 사용함.
- 모든 event/state/cache/counter/log/export에 크기·개수·TTL·처리 budget 상한 적용. 짧은 lock, 실패 시 drop 및 health 기록. 한 요청에서 전체 state scan 금지.
- 원본 client/peer IP와 proxy 해석 근거 보존. 중앙에서는 복호화 가능한 raw IP와 검색용 hash를 병행할 수 있으며 masking-only 저장 금지.
- Authorization, Cookie 원문, Set-Cookie, password, access/refresh token, 전체 POST/upload body, 민감 query 값 저장 금지. 헤더는 길이가 제한된 allowlist, query는 기본적으로 key만 저장.
- 프로젝트의 일반 요청에서 중앙 호출 금지. 중앙 수집은 인증된 bounded Pull. 90일 중앙 retention 및 대규모 CSV/XLSX export는 Central 책임.
- AdSense iframe 조작, 클릭 가로채기 JS, fingerprint 수집, 기본 CAPTCHA, 중앙의 SSH/root 제어 기능을 추가하지 않음.
- 한 번에 한 Phase만 수행. Phase 0은 별도 사용자 프롬프트 이후에만 시작하며, 현재 Phase 밖의 기능을 선행 구현하지 않음.

## Current Known Architecture
- RequestTelemetry: `src/Telemetry/RequestTelemetry.php`가 활성 요청 시작 시 timestamp, 요청/네트워크/allowlist header/query key를 bounded capture. `src/Guard.php`가 광고 유무와 무관하게 capture 후 provider를 정확히 1회 평가하고 final output에서 같은 event를 완료함.
- IdentityResolver: `engine/src/Identity/IpResolver.php`를 engine RequestContext와 DecisionLogger가 공유하지만 각각 engine/guard 설정을 전달함. viewer 접근제어는 REMOTE_ADDR를 직접 사용함.
- RiskEngine: `engine/risk-engine.php`, `engine/src/Engine.php`, `engine/src/Scoring/ScoreCombiner.php` 및 rate_limit/visitor_rate/session_churn/user_agent 신호.
- Bot Verification: `engine/src/KnownCrawlers/CrawlerVerifier.php`의 UA claim + 로컬 IP range 분류. `tools/refresh-crawler-ranges.php`가 별도 갱신 도구이며 v2 FCrDNS/RFC 9421 계층은 없음.
- LocalEventStore: `src/Storage/LocalEventStore.php`가 schema 4 JSONL append, event/daily/health 크기 상한, 최대 2ms nonblocking lock, drop/health 결과, 요청당 최대 256 entry retention 검사를 담당. engine state는 기존 `engine/src/Storage/FileStorage.php` 유지.
- Reader compatibility: `src/Telemetry/EventNormalizer.php`가 schema 3/schema 4/지원되는 versionless를 원본 변경 없이 기존 flat read model로 변환. provenance와 null을 보존하고 health/malformed/unsupported/invalid를 구분함. LogReader, RiskCorrelationAnalyzer, CLI report가 이를 공유함.
- Viewer: `viewer.php`와 `src/LogReader.php`. Phase 1은 schema 호환만 연결했으며 v2 전체 forensic UI로 간주하지 않음.
- Export: v2 `export.php` 없음. 기존 AdSense 보고서용 tools와 향후 중앙 telemetry export를 혼동하지 않음.
- Central Collector: 현재 작업 폴더의 파일 tree에서 구현을 찾지 못함.
- Dashboard: 현재 작업 폴더의 파일 tree에서 중앙 Dashboard 구현을 찾지 못함.
- Tree 확인: 루트 `adguard.php`, `auto-prepend.php`, `viewer.php`, `README.md`; `config/`, `src/`, `engine/`, `storage/`, `tests/`, `tools/`, 기존 `docs/`와 신규 `docs/adguard/`. 적용할 AGENTS.md는 확인되지 않음.

## Current Schema Versions
- event schema (실제 구현): 신규 request JSONL은 중첩 `schema_version = 4`; logger health는 별도 `schema_version = 1`, `event_type = telemetry_health`. schema 3 파일은 다시 쓰지 않고 공통 변환기로 계속 읽음.
- event identity: 요청마다 안정적인 `event_id`와 `request_id`. dual-write/counter duplicate 없음.
- rules: schema 4 `agent.rule_version = legacy-v1`. Phase 3에서 risk rule 전환 전까지 현재 의미를 보존.
- agent: schema 4 `agent.version = 2.0.0-phase1`.

## Tests / Verification
- PHP lint: PHP 8.5.5에서 83개 PHP 파일 PASS. PHP 5.6 정적 검사 79개 파일에서 비호환 검출 0; checker self-test PASS. PHP 5.6 실제 런타임 미검증.
- unit/integration tests: `tools/run-phase0-checks.ps1 -OutputPath docs/adguard/phase1/checks.json -Php C:/php-8.5.5/php.exe` 최종 26 PASS / 0 FAIL / 1 SKIP. SKIP은 저장소 외부 호스트 adapter fixture 부재이며 기존과 동일.
- failure injection: malformed JSONL, oversized event/UA/header/HMAC key, read-only stream, directory unavailable, daily log fopen 실패, busy lock, daily byte cap, health byte cap, expired retention, state storage 실패를 검증. `tools/phase1-http-failure-test.ps1`에서 telemetry 경로를 파일로 막아도 HTTP 200 및 원문 body 유지. 예외가 application 응답으로 전파되지 않음.
- performance baseline: `phase0/http-baseline.json`; PHP 8.5.5/Windows 내장 HTTP 서버, warmup 20 + 측정 200회/시나리오. 최소 페이지/비광고 guard/광고 markup/정상 광고 guard 비교. 모든 정상 표본 200·HTML 동일.
- current performance: `phase1/http-benchmark.json`, 동일한 PHP 8.5.5/Windows loopback 조건, warmup 20 + 200회. guard-no-ads PHP p50 2.154827ms/p95 5.038977ms/p99 5.879879ms, guard-ads p50 2.196074ms/p95 4.727840ms/p99 5.918980ms. guard-ads baseline p50 2.087116ms/p95 5.529881ms와 동등 범위이나, no-ad baseline p50 0.092983ms/p95 0.251055ms 대비 의도된 provider+telemetry 비용이 추가됨. HTML equality/200/failure probes PASS. 운영 서버 성능 보증은 아님.
- Git: 원본 88개 파일을 `24eeb2b`로 고정. 원본 최초 staged diff에는 기존 공백/CRLF 경고 615개가 있어 `phase0/original-whitespace.json`에 기록. 원본 재포맷 없이 Phase 0 변경분은 별도로 diff 검사.
- 문서 검증: 설계/계획/상태 문서 재독 완료. 2026-09-11 문서 폴더 소실 확인 후 Git 내부 snapshot으로 복원했으며 복원 직후 최초 88개 SHA-256이 전부 일치함. 소실 원인은 미확인.
- 변경 범위 검증: Phase 1 telemetry/reader와 관련 테스트·측정·문서만 변경. identity/bot/central/export/UI 구현 없음.
- 독립 리뷰: Task 1, Task 2, Task 3 및 retention 보완을 별도 reviewer가 검사. health cap lock race, unbounded HMAC key read, retention 누락, 비정상 날짜 삭제 가능성을 수정한 뒤 모두 Approved.

## Current Risks / Open Issues
- C1~C10은 부트스트랩에서 기록한 정적 근거이며 보존한다. Phase 0의 실제 재현/측정 결과는 위 Tests / Verification과 `PHASE_0_BASELINE_REPORT.md`에 추가했고, 확인된 v1 동작은 수정하지 않았다.
- C1 — RESOLVED IN PHASE 1: `Guard::start()`가 활성 요청 capture 후 provider를 한 번 평가하고 final output에서 같은 schema 4 event를 한 번 완료함. 광고 없는 HTML·비HTML도 request rate/telemetry 대상. `mode=off`와 excluded path는 기존 bypass 의미를 유지.
- C2 — UA 단독 광고 거부: `engine/src/Signals/UserAgentAnomalySignal.php`는 curl/wget 등의 UA에 40점을 부여하고 `src/Config.php:57`은 user_agent hard-deny 기준을 40으로 둠. `src/Guard.php:354`의 `hardDenySignal()`에서 연결됨. `tests/ad-guard-test.php:106`은 이 동작을 기대함. 설계 §12와 충돌하며, 현재 기본 monitor에서는 실제 광고 제거 대신 MONITOR_DENY가 기록되는 구조임.
- C3 — identity 설정/사용 불일치: `src/Config.php:33`과 `engine/src/Config.php:24`에 trusted_proxies가 따로 있고 logger는 guard 설정을, engine은 engine 설정을 사용함. `viewer.php:24`는 REMOTE_ADDR 직접 비교. 설계 §7.3/Phase 2의 단일 identity 적용 시 기존 접근제어와 호환성을 검토해야 함. resolver 구현 자체는 이미 공유하므로 중복 구현으로 단정하지 않음.
- C4 — RESOLVED FOR PHASE 1 CONTRACT: schema 4에 event/request identity, request/network/header, risk 상세 metrics, response status/duration/bytes, advertising, agent/rule/guard duration을 기록. schema 3/4/versionless 공통 read 호환과 provenance/null 의미를 검증. Phase 2 identity chain과 Phase 3 behavior, Phase 4 strict bot 값은 설계대로 아직 null.
- C5 — crawler verified 의미 차이: `engine/src/KnownCrawlers/CrawlerVerifier.php:197`은 claim과 로컬 공식 range 일치만으로 verified를 반환함. 설계 §10은 모든 claim의 out-of-band FCrDNS와 strict levels를 요구함. 기존 verified는 곧바로 v2 자동 allowlist 자격이 되지 않음. 현재 분류 metadata가 실제 자동 allowlist를 수행한다고 단정하지 않음.
- C6 — PARTIALLY RESOLVED: LocalEventStore는 event/daily/health byte cap, lock timeout, 최대 512 entry retention scan 상한을 가짐. `engine/src/Storage/FileStorage.php:96`의 request-time 재귀 GC에는 여전히 개수/시간 budget이 없어 Phase 3 state 작업에서 해소 필요.
- C7 — RESOLVED IN PHASE 1: 주 event는 최대 2ms nonblocking retry, exceptional health는 즉시 nonblocking lock. 실패는 bounded counter/result 후 drop하며 응답으로 전파하지 않음. health 파일 크기도 lock 안에서 다시 확인.
- C8 — 장애 시 광고 정책: `src/Config.php:91`의 fail_closed/fail_closed_on_storage_degraded와 `src/Guard.php:142`는 enforce에서 엔진/스토리지 장애만으로 광고를 억제할 수 있음. `tests/ad-guard-test.php:120` 및 `:128`도 이 동작을 기대함. 설계 §4/§13의 fail-open·정상 UX 우선과 적용 범위를 확인해야 함. 페이지 전체 차단이나 500 발생을 확인한 것은 아님. `DECISION_NEEDED`: 광고 정책의 장애 처리 범위는 구현 단계 전에 명확히 하고 현재 동작은 보존.
- C9 — 위험 등급 이름: `engine/src/Verdict.php:13`과 `src/LogReader.php:490`은 NORMAL/ELEVATED/SUSPICIOUS/SEVERE를 사용함. 설계 §12의 NORMAL/OBSERVE/HIGH_RISK/SEVERE로 전환 시 기존 로그/필터/정책 호환성 검토 필요.
- C10 — 예상 경로 차이: 설계 §25의 `engine/src/CrawlerVerifier.php` 실제 경로는 `engine/src/KnownCrawlers/CrawlerVerifier.php`이며 `config/engine.php` 대신 `config/engine.php.example`만 존재함. 설계가 요구하는 실제 tree 재확인에 따라 향후 수정 경로를 확정할 것. 원문은 수정하지 않음.
- C11 — README 개인정보 설명 불일치 확정: `README.md:170`은 raw IP/full UA 미저장을 설명하지만 schema 3 실제 기록은 raw IP와 최대 1024-byte UA를 보존함. 원문은 이번 단계에서 수정하지 않음.
- C12 — storage 접근 차단의 서버 의존성: 기존 Apache/IIS 규칙과 합성 JSONL을 PHP 내장 서버 docroot에 배치하면 HTTP 200으로 보임. 운영 서버의 실제 보호 여부는 미검증이며 별도 설정 확인 필요.
- C13 — Phase 1 no-ad overhead: 동일 합성 loopback에서 no-ad p95가 0.251055ms에서 5.038977ms로 증가. 광고와 무관한 provider/counter/event 생성이라는 승인된 요구의 직접 비용이며, 실제 운영 traffic/파일시스템에서 monitor-only 관측 후 Phase 3/8 성능 작업의 입력으로 사용해야 함. 신규 5xx나 HTML 차이는 측정되지 않음.
- 환경 상태 — 실제 PHP 5.6 런타임 검증은 남아 있으며 호스트 어댑터는 저장소 밖이라 해당 통합 테스트가 SKIP됨. 원격 push는 수행하지 않음.

## Approved Design Deviations
- D1 — 2026-09-11 사용자 승인: 설계 §9 예시의 schema 2를 신규 schema 4로 정정. 제품은 AdGuard v2 유지. 기존 schema 3 파일/의미를 보존하며 공통 읽기 변환 계층을 사용한다.
- Phase 1에 기존 LogReader/RiskCorrelationAnalyzer/tools/report의 최소 읽기 호환을 명시적으로 포함한다. Phase 5 전체 forensic UI 및 이후 export는 선행하지 않는다.
- 미수집 null, 원본 출처/광고 증적 보존, 기존 verified의 FCrDNS PASS 승격 금지, 신규 request 단일 기록을 계약으로 확정. 기존 v1의 다른 설계 차이는 승인된 deviation으로 간주하지 않는다.

## Files Changed In Current Phase
- 신규 telemetry/store: `src/Telemetry/RequestTelemetry.php`, `ResponseTelemetry.php`, `TelemetryEvent.php`, `EventNormalizer.php`, `src/Storage/LocalEventStore.php`.
- lifecycle/writer: `src/Guard.php`, `src/DecisionLogger.php`, `src/Config.php`, `config/guard.php`.
- schema read compatibility: `src/LogReader.php`, `src/RiskCorrelationAnalyzer.php`, `tools/report.php`.
- 신규 tests/fixture: `tests/telemetry-components-test.php`, `tests/telemetry-foundation-test.php`, `tests/schema-compatibility-test.php`, `tests/fixtures/telemetry-page.php`, `tools/phase1-http-failure-test.ps1`.
- 갱신 regression tests: `tests/phase0-baseline-test.php`, `tests/raw-ip-schema-test.php`, `tests/adsense-correlation-test.php`.
- plan/schema/results: `docs/superpowers/plans/2026-09-11-telemetry-foundation.md`, `docs/adguard/SCHEMA_4_FIELD_MAP.md`, `docs/adguard/phase1/checks.json`, `docs/adguard/phase1/http-benchmark.json`, 이 상태 문서.
- measurement utility: `tools/phase0-benchmark.ps1`에 문서화된 `-Php` 인자를 추가. 측정 로직/시나리오는 동일.

## Next Phase Preconditions
- Phase 1 완료 및 D1 구현 완료. 다음 작업은 별도 Phase 2 프롬프트 이후에만 시작.
- 먼저 DESIGN 전체, IMPLEMENTATION_PLAN의 Phase 2, 이 파일 전체, `SCHEMA_4_FIELD_MAP.md`, Phase 1 checks/benchmark를 읽고 git status/diff와 현재 테스트를 확인.
- Phase 2 목표는 one IdentityResolver, trusted proxy 처리, peer/client IP 분리, forwarded chain, resolution reason, guard/engine identity config 통합. viewer/logger/risk engine이 같은 client identity를 사용해야 함.
- Phase 1 schema 4 writer와 schema 3 read 의미, event/request ID, null/provenance, no-ad 1회 기록, fail-open storage behavior를 회귀로 고정.
- Phase 3 behavior/risk와 UA hard deny 제거, Phase 4 FCrDNS/RFC 9421, Phase 5 UI, Phase 6 export를 선행하지 않음. C8 장애 시 광고 정책은 관련 Phase 전에 DECISION_NEEDED 상태 유지.
- PHP 5.6 실제 런타임, 실제 배포 서버 storage 차단 및 운영 latency는 후속 검증 대상.

---

# Phase History

## BOOTSTRAP — 2026-09-10
- Goal: AdGuard v2 제공 문서를 작업 폴더 내부 Source of Truth로 고정하고 상태 문서를 초기화.
- Commit: 없음. 현재 폴더가 Git 저장소로 인식되지 않음.
- Files: 위 신규 문서 4개.
- Tests: 문서 원본/설치본 SHA-256 비교, 기존 84개 파일 변경 여부 및 신규 파일 범위 검증. 코드 테스트/lint/benchmark는 실행하지 않음.
- Result: 문서 설치/전체 읽기, 파일 tree 및 Git 상태 확인, 설계/코드 충돌 가능성 기록, Source of Truth/Immutable Constraints 초기화. 프로덕션 코드 변경 없음.
- Performance: 미측정. Phase 0 범위.
- Remaining risk: 위 C1~C10 및 Git 환경 제약. 설계 deviation 없음.
- Next handoff: Phase 0 별도 사용자 프롬프트 대기. Phase 0 또는 이후 기능을 선행 구현하지 말 것.

## Git 연결 — 2026-09-10
- 사용자 요청: 현재 폴더를 `https://github.com/EstherJoyLee/adGuard.git`에 연결.
- 수행: `git init -b main`, `git remote add origin https://github.com/EstherJoyLee/adGuard.git`.
- 원격 확인: 네트워크 접근 허용 후 `git ls-remote --symref ... HEAD` 실행 결과 `Repository not found` (exit 1). 저장소 부재와 비공개 저장소 인증 권한 부족을 현재 결과만으로 구분할 수 없음.
- 상태: 로컬 Git 및 origin 설정 완료. commit/fetch/push/upstream 설정 없음. 기존 파일은 보존하고 이 상태 문서만 후속 상황에 맞게 갱신함.
- Next handoff: 원격 주소와 현재 계정의 접근 권한 확인. Phase 0은 별도 프롬프트 대기 유지.

## 공개 전환 후 Git 연결 확인 — 2026-09-10
- 사용자 요청: 저장소를 public으로 변경했으므로 다시 연결.
- 검증: `git -c credential.helper= ls-remote --symref https://github.com/EstherJoyLee/adGuard.git HEAD`, `git -c credential.helper= fetch origin`, `git -c credential.helper= ls-remote origin` 모두 exit 0. ls-remote 출력은 비어 있음.
- Result: origin 접근/연결 완료. 원격에 가져올 브랜치/커밋 참조가 없음. credential.helper 우회는 위 명령에만 적용하며 저장된 Git 설정이나 계정을 변경하지 않음.
- 상태: 로컬 main은 첫 커밋 전 상태. 프로덕션 파일 변경, commit/push, upstream 설정 없음. 상태 문서만 갱신.
- Next handoff: 최초 커밋/업로드 또는 Phase 0에 대한 별도 사용자 지시 대기.

## Phase 0 — 2026-09-11
- Goal: 현재 v1 구조/정책/로그를 변경 없이 고정하고 regression/performance baseline 확보.
- Commit: 원본 baseline `24eeb2b5517eb3bf6c406708fd06aafa5a586f58`; Phase 0 결과는 이 커밋을 부모로 하는 `test: record Phase 0 audit and performance baseline` 커밋에 저장. 자기 커밋 해시는 문서에 순환 기록하지 않고 `git log`로 확인.
- Design version: 2026-09-10, 변경 없음. Event schema: decision=3, logger health=1.
- Files: 위 Files Changed In Current Phase 목록. 프로덕션 변경 0.
- Tests: 최종 23 PASS/0 FAIL/1 SKIP, 신규 19 assertions, lint 74 files, PHP 5.6 static 70 files. `phase0/checks.json`에 명령/출력/exit 보존.
- Performance: 정상 광고 guard 계측 p50 2.087116ms/p95 5.529881ms; 자세한 4개 시나리오 및 memory는 보고서와 JSON 참조.
- Result: 자동 비광고 수집 누락, UA 단독 광고 거부, proxy 설정 분리, raw IP/UA 저장, viewer peer 사용을 실제 확인. monitor write failure에서 200/HTML 보존. 코드 수정 없이 기록.
- Known risks: C1~C12, PHP 5.6 실제 런타임/배포 설정 미검증, 독립 리뷰 미실행, 기존 공백 경고 보존.
- Design deviations: NONE. 기존 v1 차이는 이번 단계의 승인된 설계 변경이 아님.
- Files next phase must read first: DESIGN, IMPLEMENTATION_STATE, PHASE_0_BASELINE_REPORT, IMPLEMENTATION_PLAN의 Phase 1.
- Next phase: Phase 1 별도 프롬프트 및 schema 결정 대기.
- Do NOT do next: 승인되지 않은 schema 재번호화, identity/bot/central 선행 구현, 클릭 카운터/masking, 임의 refactor 또는 원격 push.

## D1 — Schema 계약 문서 개정 — 2026-09-11
- 사용자 승인: schema 4 신규 저장 + schema 3 원형 보존/읽기 호환 제안에 “응응 그렇게 수정하자”.
- 결정: Design §9.3. 제품/agent/rule/schema version 분리, 단일 신규 기록, 증적 의미 보존, 미수집 null, 최소 reader 호환을 Phase 1에 포함.
- Files: `ADGUARD_V2_DESIGN.md`, `ADGUARD_V2_IMPLEMENTATION_PLAN.md`, `ADGUARD_V2_CODEX_PHASE_PROMPTS.md`, `IMPLEMENTATION_STATE.md`, `PHASE_0_BASELINE_REPORT.md` (모두 `docs/adguard/` 아래).
- Phase 0 결과/측정 원자료와 실행 코드/테스트는 보존. 보고서에는 후속 결정 주석만 추가.
- Verification: 문서 간 D1/schema 4/Phase 경계 일치와 `git diff --check` 확인. 문서만 변경하여 PHP 테스트/lint 및 성능 측정은 재실행하지 않음. 기존 Phase 0 검사 결과를 이번 실행 결과로 주장하지 않음.
- Remaining risk: schema 4/호환 계층은 아직 미구현. C8 fail_closed 정책 범위와 PHP 5.6 실제 런타임 등 기존 미결 항목 유지.
- Next handoff: 별도 Phase 1 프롬프트. schema 결정은 완료됐으므로 같은 승인을 다시 묻지 않음.

## Phase 1 — Telemetry Foundation — 2026-09-11
- Goal: 광고 유무와 관계없는 active PHP request telemetry, schema 4 단일 writer, bounded fail-open LocalEventStore, schema 3/4/versionless read compatibility 구축.
- Commits: `b22a194` 실행계획, `9b6163e` telemetry components, `b73bce6` health/key bounds, `5368249` request lifecycle, `65766da` shared reader, `eeebde1` bounded retention, `485e084` strict retention date validation, `f47bfc6` read-only injection. 최종 상태/결과 commit은 `git log`로 확인.
- Result: RequestTelemetry/ResponseTelemetry/TelemetryEvent/LocalEventStore/EventNormalizer 구현. 활성 no-ad 요청도 provider/counter/event 각 1회. 광고 HTML 회귀 동일. schema 3 원본 무변경, schema 4 신규 저장, legacy verified를 strict bot/FCRDNS로 승격하지 않음.
- Tests: 최종 전체 검증 기준 26 PASS/0 FAIL/1 SKIP, PHP lint 83 files, PHP 5.6 static 79 files. focused lifecycle/schema/failure tests 모두 PASS. 결과는 `phase1/checks.json`.
- Failure behavior: directory/open/lock/size/health/retention/malformed 실패는 bounded drop. 별도 실제 HTTP telemetry 저장 경로 실패 주입에서 200 및 원문 body 유지.
- Performance: 200회 loopback에서 guard-no-ads PHP p50/p95/p99 2.154827/5.038977/5.879879ms, guard-ads 2.196074/4.727840/5.918980ms. 결과는 `phase1/http-benchmark.json`; no-ad 비용 증가 C13 기록.
- Reviews: 네 구현 묶음 모두 독립 reviewer 최종 Approved. 발견된 health cap race, HMAC unbounded read, retention 누락, invalid date deletion을 수정 후 재검토.
- Design deviations: D1 그대로 구현. 그 외 신규 deviation 없음. 기존 C2/C3/C5/C8/C9/C10/C11/C12 및 C6 engine GC 부분은 후속 Phase 위험으로 보존.
- Next handoff: Phase 2 별도 프롬프트. identity만 구현하고 behavior/risk/bot/UI/export를 선행하지 말 것.
