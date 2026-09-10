# AdGuard v2 Implementation Plan

> **For Codex:** 각 Phase는 독립 검수 단위다. 한 번에 두 Phase를 구현하지 않는다.
> 반드시 `ADGUARD_V2_DESIGN.md`와 `IMPLEMENTATION_STATE.md`를 먼저 읽는다.

**Goal:** 기존 1차 adguard를 무장애 우선의 서버 관측형 방어 Agent와 중앙 관제 시스템으로 진화시킨다.

**Architecture:** 프로젝트 request path는 로컬/빠른 연산만 수행하고 외부 네트워크 의존성을 갖지 않는다. 프로젝트는 원본 JSONL과 viewer를 제공하며, 중앙 시스템이 인증된 Pull 방식으로 bounded event를 수집해 90일 보관·집계·조사한다.

**Tech Stack:** 기존 PHP 5.6+ 호환 코드, JSONL/file state, 중앙 DB는 기존 조직 표준 DB를 사용하되 프로젝트 Agent와 분리.

**Spec:** `docs/adguard/ADGUARD_V2_DESIGN.md`

---

## Global Constraints

- 기존 프로젝트 동작 보존
- PHP 5.6+ syntax
- user request path 외부 DNS/HTTP/central DB 0
- fail-open
- bounded resource
- UA-only hard deny/allowlist 금지
- raw IP forensic preservation
- FCrDNS workflow for every claimed bot
- strict verification before automatic bot allowlist
- no click counter integration
- no masking UI
- monitor-only first

---

## Phase 0 — Baseline Freeze & Audit

목적:
- 현재 v1 동작을 변경하지 않고 baseline을 고정한다.

작업:
- 전체 파일 구조/호출관계 map
- `ad_guard_boot()`부터 response까지 call graph
- 현재 log schema
- current rate/session/crawler signals
- existing tests
- PHP syntax compatibility check
- baseline request latency benchmark
- baseline memory
- known privacy/doc mismatch 확인

완료 기준:
- 기능 변경 0
- baseline test report
- 기존 테스트 green
- `IMPLEMENTATION_STATE.md` 생성

---

## Phase 1 — Telemetry Foundation

목적:
- 광고 여부와 무관한 normalized server telemetry.

구현:
- RequestTelemetry
- ResponseTelemetry
- TelemetryEvent
- LocalEventStore
- event size cap
- error/drop health counters
- guard self duration
- schema 4 신규 writer와 legacy schema 3 원본 보존 (Design §9.3, 승인 D1)
- 공통 읽기 변환 계층 및 기존 LogReader/RiskCorrelationAnalyzer/tools/report 호환 연결
- 필드 매핑표: 타입/단위/상한/nullable, 원래 schema와 의미, 광고 증적/HMAC/analytics 보존 위치
- 신규 request 단일 기록 및 event_id 유지; health record는 별도 종류로 처리

범위 경계:
- 기존 조회/집계가 신규 event를 읽기 위한 최소 호환은 Phase 1에 포함한다.
- Phase 2 identity, Phase 3 behavior/risk, Phase 4 bot 기능은 선행하지 않는다. 미수집 값은 null이며 관측값으로 추정하지 않는다.
- Phase 5 forensic UI와 Phase 6 export 구현은 해당 Phase에 남긴다.

검수:
- 광고 없는 페이지도 event/counter 반영
- storage read-only / disk-write failure simulation에서 page 200 유지
- sensitive header 제외 테스트
- schema 3/4 단독·혼합 로그의 기존 조회/집계 누락 및 중복 0
- legacy 원본/광고 증적 보존, 미수집 값과 실제 0/false 구분, legacy verified의 FCrDNS PASS 승격 금지
- health/malformed/미지원 schema 분리와 bounded 오류 처리; event 크기 상한

---

## Phase 2 — Identity Resolver & Raw IP Evidence

목적:
- IP 판단 단일화 및 forensic evidence 보존.

구현:
- one IdentityResolver
- trusted proxy handling
- peer/client IP 분리
- forwarded chain
- resolution reason
- 기존 guard/engine identity config 통합

검수:
- untrusted XFF spoof test
- trusted proxy test
- direct connection test
- IPv4/IPv6
- viewer/logger/risk engine이 동일 client identity 사용

---

## Phase 3 — Server Behavior Risk Engine

목적:
- UA 중심에서 server-observed behavior 중심으로 이동.

구현:
- bounded IP rate
- visitor rate
- burst
- session age/churn
- route repetition
- status pattern
- 상세 metrics logging
- UA hard deny 제거

검수:
- NAT-like shared IP false-positive scenario
- distributed visitors
- curl UA alone does not block
- bounded state test
- state corruption fail-open

---

## Phase 4 — Strict Bot Verification & Allowlist

목적:
- bot/crawler/AI-agent spoof 방어.

구현:
- ProviderRegistry
- bot claim parser
- FCrDNS result model
- official suffix exact-boundary validation
- official IP range cross-check
- RFC 9421 verifier interface
- verification cache model
- strict levels V0~V5
- Allowlist policy state

중요:
- request path DNS 금지
- cache miss = PENDING
- 모든 claimed bot은 FCrDNS workflow 대상
- automatic allowlist는 strict criteria 통과만
- DNS error != spoof
- unsupported crypto != valid

검수:
- googlebot.com.attacker.example 거부
- forward mismatch 거부
- official range mismatch
- DNS timeout degradation
- fake RFC9421 signature
- unsupported algorithm
- UA-only Googlebot cannot whitelist

---

## Phase 5 — Forensic Viewer

목적:
- 프로젝트 단위 상세 조사 UI.

구현:
- filters
- Filter In / Filter Out
- event table
- detail flyout
- network/request/behavior/bot/risk/response/raw evidence tabs
- agent health
- raw IP authorized display
- bounded log reading/pagination

검수:
- 대형 로그에서도 전체 파일 무조건 로드 금지
- malformed JSONL 한 줄 때문에 viewer 전체 장애 금지
- viewer auth/allowlist
- XSS-safe escaping

---

## Phase 6 — Secure Export & Central Collector

목적:
- project service와 중앙 수집을 완전히 격리.

Project:
- export endpoint
- cursor
- limit/max_bytes
- HMAC
- timestamp
- nonce/replay defense

Central:
- project registry
- collector
- dedupe by event_id
- ingest health

검수:
- invalid signature
- replay
- expired request
- oversized batch
- central down
- collector retry
- project page latency unaffected

---

## Phase 7 — Central DB & Parent Dashboard

목적:
- 전체 프로젝트 관제.

구현:
- guard_projects
- guard_events
- guard_hourly_stats
- guard_ingest_health
- overview
- project detail
- viewer link
- dashboard filters
- bot/spoof views
- agent health views
- Analysis Lab (dimension/metric/group-by/filter/top-N)
- Chart.js 기반 bounded/aggregated visualization
- chart click → filtered event drill-down

검수:
- project isolation
- raw IP access permission
- dashboard query performance
- stale/offline project detection

---

## Phase 8 — Retention & Forensic Export

목적:
- 90일 삭제와 보안업체 전달용 증적.

구현:
- 90-day cleanup
- cleanup health
- optional investigation hold
- incident export
- events.ndjson
- timeline.csv
- summary/environment
- integrity hash
- export audit
- project/full filtered CSV export
- project/full filtered XLSX export
- XLSX Summary/Hourly_Stats/Events/IPs/Paths/Bots/Agent_Health sheets
- large export row/file/concurrency budget
- central-only heavy export; viewer는 bounded CSV만

검수:
- 90-day boundary
- hold exclusion
- deletion failure alert
- no secrets in export
- raw IP is recoverable

---

## Phase 9 — Failure / Load / Rollout Gate

목적:
- 실제 enforce 전에 무장애 요구 검증.

테스트:
- storage permission failure
- full/near-full disk simulation
- corrupted state
- lock contention
- malformed requests
- high request rate
- central offline
- DNS offline
- huge UA/header input
- crawler burst
- shared NAT
- IPv6
- viewer large dataset

측정:
- baseline vs v2
- p50/p95/p99 guard time
- throughput
- application 5xx
- dropped telemetry

릴리즈:
1. monitor-only
2. shadow policy
3. limited NO_ADS
4. tune

enforce 금지 조건:
- 새로운 app 5xx
- 사용자 latency 유의미 악화
- 외부 장애 전파
- UA-only decision 존재
- unbounded storage/state 존재
