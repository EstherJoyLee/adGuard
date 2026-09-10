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
- Current phase: Phase 0 — Baseline Freeze & Audit 완료. Phase 1은 미시작.
- Last completed phase: Phase 0 (2026-09-11). 측정은 2026-09-10, 최종 전체 검사는 2026-09-11에 수행.
- Last verified commit: `af8ef336199e2ed7d1e5049afcf48e2c4ee9ec45` (Phase 0 결과). 원본 v1 baseline은 `24eeb2b5517eb3bf6c406708fd06aafa5a586f58`. D1은 이후 문서 변경이며 현재 실행 코드의 schema는 여전히 3이다.
- Current branch/worktree: `main`, `C:/Users/User/Desktop/Template/AdGuard-Template/adguard`. 작성자는 사용자 제공 `EstherJoyLee <bonjourjj3@gmail.com>`으로 이 저장소에만 설정.
- Git remote: `origin = https://github.com/EstherJoyLee/adGuard.git`. 공개 전환 후 fetch 성공. 이번 단계는 로컬 커밋만 수행하며 push/upstream 설정은 없음.
- Scope: Phase 0 감사/검증 완료 후 사용자 승인 D1을 문서에 반영. 신규 schema 4와 legacy 호환은 구현 계약이며 실행 코드 변경/Phase 1 착수는 아님.
- Phase 0 report: `docs/adguard/PHASE_0_BASELINE_REPORT.md`; 실행 결과는 `docs/adguard/phase0/`.

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
- RequestTelemetry: 전용 v2 컴포넌트 없음. `adguard.php`의 boot는 `src/Guard.php`의 output buffer를 시작하며, 자동 HTML 경로는 광고 감지 뒤 decision/log를 실행함. 명시적 decision API 호출은 별도 경로임.
- IdentityResolver: `engine/src/Identity/IpResolver.php`를 engine RequestContext와 DecisionLogger가 공유하지만 각각 engine/guard 설정을 전달함. viewer 접근제어는 REMOTE_ADDR를 직접 사용함.
- RiskEngine: `engine/risk-engine.php`, `engine/src/Engine.php`, `engine/src/Scoring/ScoreCombiner.php` 및 rate_limit/visitor_rate/session_churn/user_agent 신호.
- Bot Verification: `engine/src/KnownCrawlers/CrawlerVerifier.php`의 UA claim + 로컬 IP range 분류. `tools/refresh-crawler-ranges.php`가 별도 갱신 도구이며 v2 FCrDNS/RFC 9421 계층은 없음.
- LocalEventStore: `src/DecisionLogger.php` JSONL과 `engine/src/Storage/FileStorage.php` 파일 상태. 전용 v2 LocalEventStore는 없음.
- Viewer: `viewer.php`와 `src/LogReader.php`. 원본 IP 표시, 기간/필터/페이지 처리 및 actor bucket 상한이 이미 존재함. v2 전체 forensic UI로 간주하지 않음.
- Export: v2 `export.php` 없음. 기존 AdSense 보고서용 tools와 향후 중앙 telemetry export를 혼동하지 않음.
- Central Collector: 현재 작업 폴더의 파일 tree에서 구현을 찾지 못함.
- Dashboard: 현재 작업 폴더의 파일 tree에서 중앙 Dashboard 구현을 찾지 못함.
- Tree 확인: 루트 `adguard.php`, `auto-prepend.php`, `viewer.php`, `README.md`; `config/`, `src/`, `engine/`, `storage/`, `tests/`, `tools/`, 기존 `docs/`와 신규 `docs/adguard/`. 적용할 AGENTS.md는 확인되지 않음.

## Current Schema Versions
- event schema (실제 구현): 기존 decision JSONL은 `schema_version = 3` (`src/DecisionLogger.php:152`), logger health는 별도 record 종류의 `1` (`src/DecisionLogger.php:337`). 이번 문서 변경으로 writer가 바뀐 것은 아님.
- event schema (승인된 목표 D1): AdGuard v2 신규 request event는 중첩 schema `4`. 기존 schema 3 원형 보존, 공통 읽기 변환, 단일 신규 기록. 설계 예시의 `2`는 `4`로 정정됨. 상세 계약은 Design §9.3.
- rules: 현재 decision event에 별도 rule_version 없음. v2에서 추가 예정.
- agent: 현재 decision event에 별도 agent version 없음. v2에서 추가 예정.

## Tests / Verification
- PHP lint: PHP 8.5.5에서 74개 PHP 파일 PASS. PHP 5.6 정적 검사 70개 파일에서 비호환 검출 0; checker self-test PASS. PHP 5.6 실제 런타임 미검증.
- unit/integration tests: `tools/run-phase0-checks.ps1` 최종 23 PASS / 0 FAIL / 1 SKIP. 기존 테스트 18 PASS + 호스트 어댑터 미존재 1 SKIP, 신규 회귀 테스트 19 assertions PASS 및 boundary/static/lint/self-test 포함.
- failure injection: 기존 동시성·state 실패/GC 검사 PASS. 실제 HTTP에서 state 경로를 파일로 막았을 때 monitor 응답 200·degraded=1·HTML 동일. 모든 failure matrix를 완료했다는 의미는 아님.
- performance baseline: `phase0/http-baseline.json`; PHP 8.5.5/Windows 내장 HTTP 서버, warmup 20 + 측정 200회/시나리오. 최소 페이지/비광고 guard/광고 markup/정상 광고 guard 비교. 모든 정상 표본 200·HTML 동일.
- current performance: 정상 광고 guard PHP 계측 p50 2.087116ms, p95 5.529881ms, 평균 2.680072ms. HTTP 왕복 p50 3.2212ms/p95 6.9063ms. 최소 페이지 PHP 평균 0.002860ms. guard 광고 peak used memory p50 496256 bytes, allocator peak 2MiB. 운영 서버의 성능 보증은 아님.
- Git: 원본 88개 파일을 `24eeb2b`로 고정. 원본 최초 staged diff에는 기존 공백/CRLF 경고 615개가 있어 `phase0/original-whitespace.json`에 기록. 원본 재포맷 없이 Phase 0 변경분은 별도로 diff 검사.
- 문서 검증: 설계/계획/상태 문서 재독 완료. 2026-09-11 문서 폴더 소실 확인 후 Git 내부 snapshot으로 복원했으며 복원 직후 최초 88개 SHA-256이 전부 일치함. 소실 원인은 미확인.
- 변경 범위 검증: `phase0/original-files.json` 기준 기존 84개 코드/테스트/설정/문서와 설계 원문 3개를 보존. 기존 파일 중 상태 문서만 갱신; 신규 테스트/fixture/검사·측정 도구/보고서 추가.
- 독립 리뷰: 사용량 제한으로 실행 실패. 주 에이전트 직접 검토 및 실행 검증으로 마무리하며 독립 리뷰 완료를 주장하지 않음.

## Current Risks / Open Issues
- C1~C10은 부트스트랩에서 기록한 정적 근거이며 보존한다. Phase 0의 실제 재현/측정 결과는 위 Tests / Verification과 `PHASE_0_BASELINE_REPORT.md`에 추가했고, 확인된 v1 동작은 수정하지 않았다.
- C1 — 광고 없는 요청 수집 누락 가능성: `src/Guard.php:175`의 자동 HTML 처리에서 비HTML 또는 광고 미감지이면 decision/log 전에 반환함. 설계 §1.1/§6/§26은 광고 유무와 무관한 요청 시작 telemetry를 요구함. 별도 decision API를 호출하는 통합은 Phase 0에서 구분해야 함.
- C2 — UA 단독 광고 거부: `engine/src/Signals/UserAgentAnomalySignal.php`는 curl/wget 등의 UA에 40점을 부여하고 `src/Config.php:57`은 user_agent hard-deny 기준을 40으로 둠. `src/Guard.php:135`에서 연결됨. `tests/ad-guard-test.php:106`은 이 동작을 기대함. 설계 §12와 충돌하며, 현재 기본 monitor에서는 실제 광고 제거 대신 MONITOR_DENY가 기록되는 구조임.
- C3 — identity 설정/사용 불일치: `src/Config.php:33`과 `engine/src/Config.php:24`에 trusted_proxies가 따로 있고 logger는 guard 설정을, engine은 engine 설정을 사용함. `viewer.php:24`는 REMOTE_ADDR 직접 비교. 설계 §7.3/Phase 2의 단일 identity 적용 시 기존 접근제어와 호환성을 검토해야 함. resolver 구현 자체는 이미 공유하므로 중복 구현으로 단정하지 않음.
- C4 — schema/증적 차이: `src/DecisionLogger.php:83`은 signal의 score/triggered/storage_degraded만 저장하여 상세 count/window/limit metrics가 빠짐. 현재 flat schema 3에는 설계 §9의 event_id, 별도 peer/client/forwarded chain, response status/duration 및 agent/rule version 구조가 없음. raw_ip와 bounded user_agent는 이미 저장하므로 masking-only 상태라고 기록하지 않음. `tests/raw-ip-schema-test.php:81`은 schema 3을 기대함. schema 번호/reader 전략 결정은 D1으로 해소됐으며 실제 구현 차이는 Phase 1 이후 순차 해소한다. 기존 assertion은 legacy fixture로 보존하고 신규 schema 4 검증을 추가한다.
- C5 — crawler verified 의미 차이: `engine/src/KnownCrawlers/CrawlerVerifier.php:197`은 claim과 로컬 공식 range 일치만으로 verified를 반환함. 설계 §10은 모든 claim의 out-of-band FCrDNS와 strict levels를 요구함. 기존 verified는 곧바로 v2 자동 allowlist 자격이 되지 않음. 현재 분류 metadata가 실제 자동 allowlist를 수행한다고 단정하지 않음.
- C6 — 요청 자원 상한: `engine/src/Storage/FileStorage.php:96`의 gc는 재귀 순회이며 `engine/src/Engine.php:63`에서 요청 중 확률적으로 호출됨. 순회 개수/시간 budget이 보이지 않음. `src/DecisionLogger.php:317`도 로그 glob으로 retention 정리. 설계 §22의 bounded cleanup과 차이가 있음. 기존 TTL/일일 로그 크기 제한이 없다는 의미는 아님.
- C7 — health 로그 lock 대기: 주 decision append는 non-blocking lock이지만 `src/DecisionLogger.php:343`의 health append는 `FILE_APPEND | LOCK_EX`를 사용하고 별도 timeout이 없음. lock 실패 보고 경로가 설계 §22의 짧은 lock/drop 원칙을 충족하는지 후속 검증 필요.
- C8 — 장애 시 광고 정책: `src/Config.php:91`의 fail_closed/fail_closed_on_storage_degraded와 `src/Guard.php:125`는 enforce에서 엔진/스토리지 장애만으로 광고를 억제할 수 있음. `tests/ad-guard-test.php:120` 및 `:128`도 이 동작을 기대함. 설계 §4/§13의 fail-open·정상 UX 우선과 적용 범위를 확인해야 함. 페이지 전체 차단이나 500 발생을 확인한 것은 아님. `DECISION_NEEDED`: 광고 정책의 장애 처리 범위는 구현 단계 전에 명확히 하고 현재 동작은 보존.
- C9 — 위험 등급 이름: `engine/src/Verdict.php:13`과 `src/LogReader.php:468`은 NORMAL/ELEVATED/SUSPICIOUS/SEVERE를 사용함. 설계 §12의 NORMAL/OBSERVE/HIGH_RISK/SEVERE로 전환 시 기존 로그/필터/정책 호환성 검토 필요.
- C10 — 예상 경로 차이: 설계 §25의 `engine/src/CrawlerVerifier.php` 실제 경로는 `engine/src/KnownCrawlers/CrawlerVerifier.php`이며 `config/engine.php` 대신 `config/engine.php.example`만 존재함. 설계가 요구하는 실제 tree 재확인에 따라 향후 수정 경로를 확정할 것. 원문은 수정하지 않음.
- C11 — README 개인정보 설명 불일치 확정: `README.md:170`은 raw IP/full UA 미저장을 설명하지만 schema 3 실제 기록은 raw IP와 최대 1024-byte UA를 보존함. 원문은 이번 단계에서 수정하지 않음.
- C12 — storage 접근 차단의 서버 의존성: 기존 Apache/IIS 규칙과 합성 JSONL을 PHP 내장 서버 docroot에 배치하면 HTTP 200으로 보임. 운영 서버의 실제 보호 여부는 미검증이며 별도 설정 확인 필요.
- 환경 상태 — 로컬 baseline commit 생성 완료. 실제 PHP 5.6 검증은 남아 있으며 호스트 어댑터는 저장소 밖이라 해당 통합 테스트가 SKIP됨. 원격 push는 수행하지 않음.

## Approved Design Deviations
- D1 — 2026-09-11 사용자 승인: 설계 §9 예시의 schema 2를 신규 schema 4로 정정. 제품은 AdGuard v2 유지. 기존 schema 3 파일/의미를 보존하며 공통 읽기 변환 계층을 사용한다.
- Phase 1에 기존 LogReader/RiskCorrelationAnalyzer/tools/report의 최소 읽기 호환을 명시적으로 포함한다. Phase 5 전체 forensic UI 및 이후 export는 선행하지 않는다.
- 미수집 null, 원본 출처/광고 증적 보존, 기존 verified의 FCrDNS PASS 승격 금지, 신규 request 단일 기록을 계약으로 확정. 기존 v1의 다른 설계 차이는 승인된 deviation으로 간주하지 않는다.

## Files Changed In Current Phase
- `tests/phase0-baseline-test.php` — v1 public boot/counter/event/UA/proxy/schema/민감정보 회귀 검증.
- `tests/fixtures/phase0-page.php` — loopback/env opt-in HTTP 측정·실패 probe fixture.
- `tools/run-phase0-checks.ps1` — 전체 검사/30초 timeout/결과 JSON.
- `tools/phase0-benchmark.ps1` — 임시 서버/합성 client/latency·memory 및 HTTP probe.
- `docs/adguard/PHASE_0_AUDIT_PLAN.md`, `docs/adguard/PHASE_0_BASELINE_REPORT.md` — 실행 계획 및 감사 보고서.
- `docs/adguard/phase0/original-files.json`, `checks-initial.json`, `checks.json`, `http-baseline.json`, `original-whitespace.json` — 기준 해시/실행 결과/측정 원자료/기존 공백 경고.
- `docs/adguard/IMPLEMENTATION_STATE.md` — 현재 상태와 Phase History 갱신.
- Production code changed: NO.

## Next Phase Preconditions
- Phase 0 완료, schema/legacy 호환 결정 D1 승인 및 문서 반영. Phase 1 구현은 별도 프롬프트 이후 시작.
- 먼저 설계, 이 파일, `PHASE_0_BASELINE_REPORT.md`, 계획의 Phase 1 및 공통 Context Lock을 읽기.
- schema 번호/reader 전략에 대한 재승인은 필요 없음. Phase 1에서 Design §9.3에 따라 필드 매핑/타입/상한/nullable과 fixture를 고정한다. fail_closed 정책 해석은 관련 정책 구현 전에 결정하며 이번 D1으로 해소됐다고 간주하지 않는다.
- Phase 1 신규 예상: `src/Telemetry/RequestTelemetry.php`, `src/Telemetry/ResponseTelemetry.php`, `src/Telemetry/TelemetryEvent.php`, `src/Storage/LocalEventStore.php`, `tests/telemetry-foundation-test.php`.
- Phase 1 수정 후보: `adguard.php`, `src/Guard.php`, `src/DecisionLogger.php`, `src/Config.php`, `config/guard.php`. 상세 테스트 영향 목록은 보고서 §8 참조.
- D1 추가 범위: 공통 읽기 변환 계층(정확한 신규 파일명은 Phase 1에서 기존 convention에 맞춰 확정), `src/LogReader.php`, `src/RiskCorrelationAnalyzer.php`, `tools/report.php`, 관련 reader/report 테스트 및 schema 3/4 혼합 fixture. 신규 event 조회 누락/중복 방지에 필요한 호환만 구현.
- v1 결함을 고정한 회귀 assertion은 의도된 Phase 변경만 반영하고 HTML 보존 등 정상 동작 검증은 유지. identity/bot/중앙 export/UI 선행 구현 금지.
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
