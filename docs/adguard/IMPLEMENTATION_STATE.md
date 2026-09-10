# AdGuard v2 IMPLEMENTATION_STATE

> Codex는 모든 단계 시작 전에 이 파일과 `ADGUARD_V2_DESIGN.md`를 읽는다.
> 한 단계가 끝날 때 반드시 이 파일을 갱신한다.
> 과거 항목을 지우지 말고 Phase History에 누적한다.

## Source of Truth
- Design: `docs/adguard/ADGUARD_V2_DESIGN.md`
- Design version: 2026-09-10 작성본. 제공된 원문을 수정 없이 설치함.
- Implementation plan: `docs/adguard/ADGUARD_V2_IMPLEMENTATION_PLAN.md`
- Phase prompts: `docs/adguard/ADGUARD_V2_CODEX_PHASE_PROMPTS.md`
- State template: 상위 `../docs/IMPLEMENTATION_STATE_TEMPLATE.md`를 복사하여 이 파일을 초기화함.
- Document source: `C:/Users/User/Desktop/Template/AdGuard-Template/docs/`. 최초 첨부 경로의 하위 폴더는 현재 없으며, 같은 이름의 제공 문서들이 이 위치에 있음.
- Priority: 설계 문서 → 이 파일의 현재 구현 상태 → 실제 코드/테스트 → 에이전트 추정. 설계 변경이 필요하면 `DECISION_NEEDED`로 기록하고 임의로 변경하지 않음.
- Current phase: BOOTSTRAP 완료 / Phase 0 별도 사용자 프롬프트 대기.
- Last completed phase: BOOTSTRAP (2026-09-10). Phase 0~9는 시작하지 않음.
- Last verified commit: 없음. 부트스트랩 이후 로컬 Git을 초기화함. 공개 전환 후 원격 접근 및 fetch 성공; 원격에 커밋/브랜치 참조가 없는 빈 저장소임.
- Current branch/worktree: `main` (첫 커밋 없음), `C:/Users/User/Desktop/Template/AdGuard-Template/adguard`. 후속 사용자 요청으로 Git 초기화 및 origin 등록 완료.
- Git remote: `origin = https://github.com/EstherJoyLee/adGuard.git`. 공개 전환 후 인증 없이 `git -c credential.helper= fetch origin` 성공. 전체 `ls-remote origin` 결과도 빈 출력(exit 0). 원격 연결 완료; 최초 commit/push 및 upstream 설정은 아직 없음.
- Scope: 문서 4개 배치 및 상태 기록만 수행. 프로덕션 코드 수정, 설계 변경, Phase 0 실행 없음.

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
- event schema: 기존 decision JSONL은 `schema_version = 3` (`src/DecisionLogger.php:152`), logger health는 `1` (`src/DecisionLogger.php:337`). 설계 §9 예시는 중첩 schema `2`이므로 제품 v2와 기존 schema 번호를 혼동하지 말 것.
- rules: 이번 부트스트랩에서 명시적인 rule version을 확인하지 못함. Phase 0에서 확인.
- agent: 이번 부트스트랩에서 명시적인 agent version을 확인하지 못함. Phase 0에서 확인.

## Tests / Verification
- PHP lint: 미실행. Phase 0 별도 지시 이후 수행 대상.
- unit/integration tests: 미실행. 기존 테스트 파일과 관련 assertion을 정적으로 확인했으며 통과 여부는 판단하지 않음.
- failure injection: 미실행.
- performance baseline: 미측정. Phase 0을 시작하지 않음.
- current performance: 미측정.
- Git: 부트스트랩 당시 저장소 인식 실패. 후속 요청에서 로컬 main/origin 설정 및 공개 원격 fetch 성공. 원격은 비어 있으며 첫 커밋과 upstream은 없음. 아래 Phase History 참조.
- 문서 검증: 제공된 설계/계획/프롬프트 3개와 설치본 SHA-256 일치. 설치된 문서 4개 전체 읽기 완료.
- 변경 범위 검증: 작업 전 기존 파일 84개 SHA-256을 메모리에 확보하여 작업 후 비교. 기존 파일 수정/삭제 0개, 신규 파일은 `docs/adguard/`의 요청된 문서 4개만 허용.

## Current Risks / Open Issues
- 아래는 정적 코드 근거로 확인한 설계와의 차이 또는 충돌 가능성이다. 이번 단계에서는 코드를 고치지 않았으며 runtime 재현/성능 검증 결과가 아니다.
- C1 — 광고 없는 요청 수집 누락 가능성: `src/Guard.php:175`의 자동 HTML 처리에서 비HTML 또는 광고 미감지이면 decision/log 전에 반환함. 설계 §1.1/§6/§26은 광고 유무와 무관한 요청 시작 telemetry를 요구함. 별도 decision API를 호출하는 통합은 Phase 0에서 구분해야 함.
- C2 — UA 단독 광고 거부: `engine/src/Signals/UserAgentAnomalySignal.php`는 curl/wget 등의 UA에 40점을 부여하고 `src/Config.php:57`은 user_agent hard-deny 기준을 40으로 둠. `src/Guard.php:135`에서 연결됨. `tests/ad-guard-test.php:106`은 이 동작을 기대함. 설계 §12와 충돌하며, 현재 기본 monitor에서는 실제 광고 제거 대신 MONITOR_DENY가 기록되는 구조임.
- C3 — identity 설정/사용 불일치: `src/Config.php:33`과 `engine/src/Config.php:24`에 trusted_proxies가 따로 있고 logger는 guard 설정을, engine은 engine 설정을 사용함. `viewer.php:24`는 REMOTE_ADDR 직접 비교. 설계 §7.3/Phase 2의 단일 identity 적용 시 기존 접근제어와 호환성을 검토해야 함. resolver 구현 자체는 이미 공유하므로 중복 구현으로 단정하지 않음.
- C4 — schema/증적 차이: `src/DecisionLogger.php:83`은 signal의 score/triggered/storage_degraded만 저장하여 상세 count/window/limit metrics가 빠짐. 현재 flat schema 3에는 설계 §9의 event_id, 별도 peer/client/forwarded chain, response status/duration 및 agent/rule version 구조가 없음. raw_ip와 bounded user_agent는 이미 저장하므로 masking-only 상태라고 기록하지 않음. `tests/raw-ip-schema-test.php:81`은 schema 3을 기대함. `DECISION_NEEDED`: 향후 schema 번호/기존 reader 호환 전략을 확정해야 하며 설계 예시를 근거로 기존 번호를 임의로 2로 낮추지 않음.
- C5 — crawler verified 의미 차이: `engine/src/KnownCrawlers/CrawlerVerifier.php:197`은 claim과 로컬 공식 range 일치만으로 verified를 반환함. 설계 §10은 모든 claim의 out-of-band FCrDNS와 strict levels를 요구함. 기존 verified는 곧바로 v2 자동 allowlist 자격이 되지 않음. 현재 분류 metadata가 실제 자동 allowlist를 수행한다고 단정하지 않음.
- C6 — 요청 자원 상한: `engine/src/Storage/FileStorage.php:96`의 gc는 재귀 순회이며 `engine/src/Engine.php:63`에서 요청 중 확률적으로 호출됨. 순회 개수/시간 budget이 보이지 않음. `src/DecisionLogger.php:317`도 로그 glob으로 retention 정리. 설계 §22의 bounded cleanup과 차이가 있음. 기존 TTL/일일 로그 크기 제한이 없다는 의미는 아님.
- C7 — health 로그 lock 대기: 주 decision append는 non-blocking lock이지만 `src/DecisionLogger.php:343`의 health append는 `FILE_APPEND | LOCK_EX`를 사용하고 별도 timeout이 없음. lock 실패 보고 경로가 설계 §22의 짧은 lock/drop 원칙을 충족하는지 후속 검증 필요.
- C8 — 장애 시 광고 정책: `src/Config.php:91`의 fail_closed/fail_closed_on_storage_degraded와 `src/Guard.php:125`는 enforce에서 엔진/스토리지 장애만으로 광고를 억제할 수 있음. `tests/ad-guard-test.php:120` 및 `:128`도 이 동작을 기대함. 설계 §4/§13의 fail-open·정상 UX 우선과 적용 범위를 확인해야 함. 페이지 전체 차단이나 500 발생을 확인한 것은 아님. `DECISION_NEEDED`: 광고 정책의 장애 처리 범위는 구현 단계 전에 명확히 하고 현재 동작은 보존.
- C9 — 위험 등급 이름: `engine/src/Verdict.php:13`과 `src/LogReader.php:468`은 NORMAL/ELEVATED/SUSPICIOUS/SEVERE를 사용함. 설계 §12의 NORMAL/OBSERVE/HIGH_RISK/SEVERE로 전환 시 기존 로그/필터/정책 호환성 검토 필요.
- C10 — 예상 경로 차이: 설계 §25의 `engine/src/CrawlerVerifier.php` 실제 경로는 `engine/src/KnownCrawlers/CrawlerVerifier.php`이며 `config/engine.php` 대신 `config/engine.php.example`만 존재함. 설계가 요구하는 실제 tree 재확인에 따라 향후 수정 경로를 확정할 것. 원문은 수정하지 않음.
- 환경 상태 — 기존 원격 접근 문제는 공개 전환 후 해결됨. 로컬 Git/origin 및 fetch 정상, 원격 저장소는 비어 있음. Phase 0의 commit 기반 검증 기준점은 최초 커밋 전까지 없음. Git 연결 요청만으로 파일을 commit/push하지 않음.

## Approved Design Deviations
- None
- 승인된 설계 변경 없음. 위 기존 v1 차이는 승인된 deviation 또는 이번 단계의 변경이 아님.

## Files Changed In Current Phase
- `docs/adguard/ADGUARD_V2_DESIGN.md` — 신규, 제공 원본과 동일.
- `docs/adguard/ADGUARD_V2_IMPLEMENTATION_PLAN.md` — 신규, 제공 원본과 동일.
- `docs/adguard/ADGUARD_V2_CODEX_PHASE_PROMPTS.md` — 신규, 제공 원본과 동일.
- `docs/adguard/IMPLEMENTATION_STATE.md` — 신규, 제공 template 복사 후 초기화.
- Production code changed: NO.

## Next Phase Preconditions
- Ready for Phase 0: YES — 문서 고정 및 부트스트랩 완료 기준. Phase 0 baseline 테스트/커밋이 완료되었다는 의미가 아님.
- 사용자에게 Phase 0 별도 프롬프트를 받은 후에만 시작.
- 먼저 설계와 이 상태 파일 전체, 계획의 Phase 0, 프롬프트의 Context Lock/Phase 0을 읽기.
- Git checkout/기준점 처리 방침을 확인하고, Git 기반 완료 검증이 불가능하면 그 한계를 명시.
- 기록된 기존 v1 차이를 baseline 감사 항목으로 유지. 부트스트랩 및 Phase 0에서 기능을 고치거나 설계를 임의 변경하지 않음.
- Phase 0에서 기존 테스트, PHP 문법, baseline latency/memory 및 상세 호출관계를 조사/검증. 다음 구현 Phase 전 관련 DECISION_NEEDED를 해소.

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
