# AdGuard v2 — Codex 단계별 프롬프트 팩

## 사용법

아래 프롬프트를 **한 번에 전부 주지 않는다.**

반드시:

1. Phase 0 프롬프트 전달
2. Codex 결과/테스트/커밋 확인
3. `IMPLEMENTATION_STATE.md` 갱신 확인
4. 다음 Phase 프롬프트 전달

순서로 진행한다.

각 단계의 핵심은 이전 채팅 기억이 아니라 저장소 안의 문서를 다시 읽게 하는 것이다.

권장 저장 파일:

```text
docs/adguard/
  ADGUARD_V2_DESIGN.md
  ADGUARD_V2_IMPLEMENTATION_PLAN.md
  IMPLEMENTATION_STATE.md
```

---

# 공통 Context Lock

**아래 블록을 매 Phase 프롬프트 맨 앞에 그대로 붙인다.**

```text
[CONTEXT LOCK — 반드시 먼저 수행]

이 작업은 AdGuard v2의 단계별 구현이다.
이 채팅의 과거 기억을 신뢰하지 말고 저장소 문서를 Source of Truth로 사용해.

작업 시작 전 반드시:
1. docs/adguard/ADGUARD_V2_DESIGN.md 전체 읽기
2. docs/adguard/ADGUARD_V2_IMPLEMENTATION_PLAN.md에서 현재 Phase 읽기
3. docs/adguard/IMPLEMENTATION_STATE.md 전체 읽기
4. git status / git diff 확인
5. 현재 adguard 파일 구조와 기존 테스트 확인

그 후 코드 수정 전에 반드시 짧게 다음을 출력:
- Current Phase
- 이전 Phase 완료 상태
- 이번 Phase에서 수정할 파일
- 이번 Phase에서 절대 건드리지 않을 범위
- 설계상 불변 조건 10개
- 현재 발견된 설계/코드 충돌 여부

충돌이 있으면 임의로 설계를 바꾸지 말고 DECISION_NEEDED로 보고하고 구현을 멈춰.

절대 불변:
- PHP 5.6+ syntax compatibility
- 정상 사용자 request path에서 외부 DNS 0회
- 정상 사용자 request path에서 외부 HTTP 0회
- 정상 사용자 request path에서 중앙 DB 연결 0회
- fail-open
- AdGuard 오류가 application 500으로 전파되면 실패
- UA 단독 hard deny/allowlist 금지
- claimed bot은 모두 FCrDNS verification workflow 대상
- raw IP는 forensic 목적상 복구 가능하게 보존
- Authorization/Cookie/password/full request body 저장 금지
- 클릭 카운터 기능은 이번 v2 범위 밖
- transparent masking UI 구현 금지
- 관련 없는 refactor 금지
- 현재 Phase 외 기능 선행 구현 금지

구현은 테스트 우선으로 진행해.
한 번에 여러 독립 문제를 수정하지 마.
각 실패 원인을 확인한 뒤 최소 변경으로 수정해.

Phase 종료 전 반드시:
1. 해당 Phase 테스트
2. 기존 전체 테스트
3. PHP lint
4. git diff --check
5. 성능/실패 주입이 해당 Phase에 관련되면 실행
6. docs/adguard/IMPLEMENTATION_STATE.md 갱신

완료 보고에는:
- 변경 파일
- 핵심 변경점
- 테스트 명령과 실제 결과
- 성능 수치(측정한 경우)
- 남은 위험
- 설계 deviation 여부
- 다음 Phase prerequisite
를 정확히 적어.

테스트를 실행하지 않았으면 '통과'라고 말하지 마.
```

---

# PHASE 0 PROMPT — Baseline Freeze

```text
[위 Context Lock 붙이기]

이번에는 Phase 0만 수행해.

목표:
현재 adguard v1의 실제 동작과 구조를 고정하고,
v2 구현 전 regression baseline을 만든다.

기능 변경은 금지한다.

반드시 조사:
- ad_guard_boot() entry point
- Guard 호출 흐름
- 광고 HTML 감지 시점
- Risk Engine 실행 시점
- DecisionLogger
- JSONL schema
- storage/state
- viewer.php
- trusted proxy/IP resolution
- UserAgentAnomalySignal
- crawler verifier
- crawler range refresh
- 현재 tests
- README와 실제 코드 불일치

특히 검증:
1. 광고 없는 PHP 요청이 현재 rate/event에 포함되는지
2. UA 단독 hard deny 가능 여부
3. guard와 engine이 서로 다른 IP 설정을 사용할 수 있는지
4. viewer가 IdentityResolver 대신 REMOTE_ADDR를 직접 사용하는지
5. raw IP/full UA 실제 저장 여부
6. storage가 web root 안일 때 노출 위험
7. PHP 5.6 호환 위반 문법 존재 여부

가능하면 기존 동작을 나타내는 regression test를 먼저 추가하되,
production behavior는 바꾸지 마.

baseline 성능도 남겨:
- guard 없는 최소 페이지
- guard 있는 정상 페이지
- 가능한 범위의 p50/p95 또는 반복 실행 평균

최종 결과를 IMPLEMENTATION_STATE.md에 기록하고,
Phase 1에 필요한 정확한 파일 목록을 남겨.
```

---

# PHASE 1 PROMPT — Telemetry Foundation

```text
[위 Context Lock 붙이기]

Phase 0이 완료됐는지 IMPLEMENTATION_STATE.md로 확인한 후
Phase 1만 구현해.

목표:
AdSense 존재 여부와 무관하게 PHP 요청에서
서버 관측 telemetry를 생성하는 기반을 만든다.

필수 결과:
- RequestTelemetry
- ResponseTelemetry
- normalized TelemetryEvent
- LocalEventStore
- schema_version
- event_id/request_id
- guard_duration_ms
- telemetry write health metrics

중요:
기존 Guard의 광고 제어 책임과 telemetry 수집 책임을 분리해.
광고가 없는 페이지도 request rate/telemetry에 포함되어야 해.

저장 필드는 DESIGN의 allowlist를 정확히 따라.
Authorization/Cookie/body를 generic header dump로 저장하면 실패야.

파일 I/O:
- bounded event size
- short/bounded lock
- write 실패는 drop
- exception/error가 원래 application 응답에 전파되지 않음

필수 실패 주입 테스트:
- storage read-only
- log file open failure
- malformed existing JSONL
- oversized UA/header
- 광고 없는 페이지

기존 광고 출력 결과가 바뀌지 않았는지도 regression test해.

Phase 2 기능(IP refactor/bot verification)은 선행 구현하지 마.
```

---

# PHASE 2 PROMPT — Identity Resolver

```text
[위 Context Lock 붙이기]

Phase 1 green 확인 후 Phase 2만 구현해.

목표:
프로젝트 전체에서 IP 판단을 하나의 IdentityResolver로 통일하고
forensic 조사 가능한 network evidence를 남긴다.

반드시 분리:
- peer_ip = 실제 PHP connection peer
- client_ip = trusted proxy policy 적용 후 최종 client
- ip_source
- proxy_trusted
- forwarded_chain
- resolution_status

절대:
신뢰되지 않은 REMOTE_ADDR에서 온 X-Forwarded-For를 client IP로 채택하지 마.

기존 config/guard.php, config/engine.php에
trusted proxy 정보가 중복되면 하나의 source로 통합해.
단, 기존 설정과의 backward compatibility를 검토해.

테스트:
- direct IPv4
- direct IPv6
- spoofed XFF from untrusted peer
- trusted proxy + single XFF
- trusted proxy chain
- malformed forwarded IP
- private/public address 혼합
- viewer/logger/risk engine이 동일 client_ip 사용

raw IP는 masking하지 않는다.
중앙 암호화는 중앙 Phase에서 처리한다.
```

---

# PHASE 3 PROMPT — Server Behavior Risk

```text
[위 Context Lock 붙이기]

Phase 3만 구현해.

목표:
User-Agent가 아니라 서버가 관측한 행동을 Risk Engine의 본체로 만든다.

구현 대상:
- IP 10s/60s/600s bounded counters
- visitor 10s/60s/600s counters
- burst
- session age
- session churn
- route repetition
- response error pattern(수집 가능한 범위)
- 상세 metrics logging

state는 반드시:
- TTL
- max entries
- bounded cleanup
을 가진다.

UserAgentAnomalySignal:
- UA 자체는 기록/모순 탐지용
- curl/wget/python 문자열 하나만으로 hard deny 금지
- UA-only allowlist도 금지

테스트 시나리오:
- 같은 NAT IP의 여러 정상 visitor
- 한 visitor burst
- IP rotation
- curl UA지만 정상 속도
- Chrome UA지만 비정상 속도
- state corruption
- state size limit
- concurrent requests

threshold는 아직 production enforce 기준으로 확정하지 마.
monitor/shadow 분석을 위한 score 근거를 명확하게 남겨.
```

---

# PHASE 4 PROMPT — Strict Bot Verification

```text
[위 Context Lock 붙이기]

Phase 4만 구현해.

목표:
Googlebot/Bingbot/AI crawler/agent 등 모든 claimed bot을
UA가 아니라 검증 증거로 분류한다.

반드시 구현할 모델:
- claimed_identity
- provider
- verification_status
- verification_level V0~V5
- FCrDNS result
- official IP range result
- RFC9421 result
- allowlist policy result

FCrDNS:
모든 claimed bot은 FCrDNS verification workflow 대상이다.
하지만 사용자 request 중 DNS lookup은 절대 하지 마.
cache miss는 PENDING으로 처리해.

FCrDNS PASS 조건:
1. PTR 성공
2. hostname canonicalization
3. provider official suffix의 정확한 DNS label boundary
4. forward A/AAAA
5. original client IP 포함

`googlebot.com.attacker.com` 같은 값을 반드시 거부하는 test를 만들어.

공식 IP range:
- provider가 공식 목록을 제공하는 경우 교차 검증
- last-known-good cache
- refresh 실패 시 cache를 비우지 않음
- refresh는 request path 밖

RFC 9421:
지금 단계에서 architecture/interface가 중요하다.
현재 PHP 환경에서 실제 지원 가능한 crypto만 capability detection하여 검증해.
지원 불가 알고리즘을 성공으로 간주하면 안 된다.

상태 예:
NOT_PRESENT
VALID
INVALID
UNSUPPORTED_ALGORITHM
KEY_UNAVAILABLE
EXPIRED
REPLAY_SUSPECTED

signature VALID만으로 trusted provider라고 판단하지 마.
provider/key identity도 검증되어야 해.

자동 allowlist:
UA만으로 절대 금지.
strict policy상 기본적으로 FCrDNS PASS가 필요하다.

DNS timeout:
SPOOF가 아니라 UNKNOWN/PENDING.

테스트:
- fake Googlebot UA
- malicious suffix
- forward mismatch
- official range mismatch
- DNS failure
- stale cache
- fake signature
- expired signature
- unsupported crypto
- unknown signed agent
```

---

# PHASE 5 PROMPT — Forensic Viewer

```text
[위 Context Lock 붙이기]

Phase 5만 구현해.

목표:
viewer.php를 프로젝트 로컬 forensic 조사 화면으로 만든다.

UI 요구:
상단:
- Project
- Agent health
- version
- last event
- local storage status

필터:
- time
- client IP
- peer IP
- request id
- visitor/session
- risk level
- score range
- action
- path
- method
- status
- bot claim/provider
- verification
- FCrDNS
- official range
- RFC9421
- IP source
- trusted proxy

Interaction:
- Filter In
- Filter Out
- Clear
- pagination

Table:
Time / Client IP / Method / Path / Status / Risk / Action / Traffic Type / Bot Verification

Detail flyout:
Overview
Network
Request
Behavior
Bot Verification
Risk Calculation
Response
Raw Evidence

보안:
- 출력값 HTML escape
- raw IP를 URL query에 불필요하게 노출하지 않음
- viewer 접근제어 기존 정책을 분석한 뒤 강화
- malformed event 한 줄 때문에 전체 viewer crash 금지

성능:
90일 전체 로그를 메모리로 한 번에 읽는 구현 금지.
bounded read/index/cursor 방식을 사용해.
```

---

# PHASE 6 PROMPT — Export + Central Collector

```text
[위 Context Lock 붙이기]

Phase 6만 구현해.

목표:
원래 Project 서비스와 중앙 수집 실패를 완전히 분리한다.

Project export:
- cursor
- limit
- max_bytes
- project_id
- timestamp
- nonce
- HMAC signature
- replay defense
- bounded response

Central collector:
- project registry
- authenticated pull
- cursor persistence
- event_id dedupe
- ingest health
- retry/backoff

절대:
Project의 일반 사용자 request가 central collector를 호출하면 안 된다.

Project 등록에 root/SSH/DB root password를 요구하지 마.

failure tests:
- collector offline
- DB offline
- bad HMAC
- stale timestamp
- replay nonce
- export oversized request
- corrupt event
- duplicate event
- slow collector
- project page latency baseline comparison

Central 장애 상태에서도 프로젝트 정상 page가 그대로 동작함을
실제 테스트로 증명해.
```

---

# PHASE 7 PROMPT — Central DB + Dashboard

```text
[위 Context Lock 붙이기]

Phase 7만 구현해.

목표:
여러 프로젝트의 상태를 중앙 Dashboard에서 관제한다.

DB:
- guard_projects
- guard_events
- guard_hourly_stats
- guard_ingest_health

raw IP:
복구 불가능한 masking 금지.
DB에는 원본 복호화 가능한 encrypted raw IP와 검색용 lookup hash 구조를 사용해.
키 관리가 아직 프로젝트 표준으로 정해지지 않았다면 자체 crypto를 발명하지 말고
기존 secret/encryption mechanism을 조사해 DECISION_NEEDED로 보고해.

Dashboard Overview:
- project health
- requests
- risk
- spoof suspected
- collector failures
- top suspicious IP/path
- bot/AI traffic

Projects:
- project metadata
- last seen
- risk
- viewer link

Project Detail:
- request/risk timeline
- risk distribution
- status distribution
- top IP
- top path
- crawler verification
- agent health
- recent suspicious events
- fixed/open viewer action

필터는 DESIGN.md 기준.

viewer URL 클릭은 raw credential을 URL에 포함하지 마.

Analysis Lab:
- dimensions / metrics를 분리
- group by
- filter in/out
- top N
- project compare
- period compare
- hourly/daily aggregation

Chart.js:
- raw event 전체를 브라우저로 전달하지 마
- 중앙 DB/API에서 먼저 집계
- point 수 상한
- 큰 line dataset은 decimation 또는 사전 aggregation
- chart click → 동일 filter context의 event drill-down
- 대형 dataset animation 비활성화를 검토

Chart 계산 결과를 client-side 방어 판정 근거로 사용하지 마.
```

---

# PHASE 8 PROMPT — 90-day Retention + Forensics

```text
[위 Context Lock 붙이기]

Phase 8만 구현해.

목표:
90일 보관정책과 보안업체 전달용 evidence export를 구현한다.

Retention:
- guard_events 90일
- 중앙 scheduler
- project cron 의존 없음
- cleanup failure health alert
- cleanup batch로 DB lock/부하 제한

optional investigation_hold는
기존 회사 정책/요구가 불명확하면 기능 flag 뒤에 두고
기본 OFF로 구현해.

Investigation export:
필터:
- project
- raw IP
- 기간
- risk
- path
- bot identity

산출:
- summary.json
- events.ndjson
- timeline.csv
- environment.json
- integrity.sha256

반드시 포함:
- raw client IP
- peer IP
- proxy resolution
- timestamps
- path/method/status
- selected safe headers
- behavior
- bot verification
- risk calculation
- agent/rule/schema version

반드시 제외:
- Authorization
- Cookie
- password
- token
- request body

Export 수행 자체를 audit log에 남겨.

Analysis Export:
- 보안업체 Investigation Export와 별도 기능으로 설계
- 현재 필터 / 특정 Project / 여러 Project / 전체 Project 지원
- CSV + XLSX
- CSV는 대규모 raw event용 streaming export 우선
- XLSX는 사람이 분석하기 쉬운 workbook 구성

XLSX sheets:
- Summary
- Hourly_Stats
- Events
- IPs
- Paths
- Bots
- Agent_Health
- Export_Metadata

대용량 export:
- Project Agent에서 생성 금지
- Central에서만 수행
- bounded DB cursor/chunk
- query timeout
- max rows / max bytes
- export concurrency limit
- 필요 시 기간/Project별 split
- Excel row/file budget 초과 시 CSV 사용

viewer.php:
현재 필터의 bounded CSV export만 허용.

raw IP:
권한 있는 분석 export에는 원본 IP 포함 가능.
반드시 export audit를 남겨.
```

---

# PHASE 9 PROMPT — Failure / Performance / Release Gate

```text
[위 Context Lock 붙이기]

이 Phase에서는 기능을 추가하지 마.
성능/장애 검증과 필요한 최소 수정만 수행해.

반드시 failure injection:
- storage permission denied
- corrupted state
- malformed JSONL
- lock contention
- near-full disk 대응 가능한 범위
- central down
- DNS unavailable
- crawler burst
- huge headers
- IPv6
- shared NAT traffic

반드시 측정:
- v1/baseline page latency
- v2 page latency
- guard_duration p50/p95/p99
- throughput
- PHP/application 5xx
- telemetry dropped
- storage errors

Release blocker:
- 새로운 5xx
- request path 외부 DNS/HTTP 발견
- central 장애 latency 전파
- unbounded state
- unbounded viewer load
- UA-only hard deny/allowlist
- raw secret logging
- normal user page blocking

마지막으로 운영 모드를 확인:
1. MONITOR_ONLY
2. SHADOW_POLICY
3. LIMITED_ENFORCE
4. TUNED_ENFORCE

현재는 MONITOR_ONLY를 기본으로 유지해.

최종 완료 보고에서
'문제가 없어 보인다'가 아니라 테스트 결과와 수치로 증명해.
```

---

# Codex가 맥락을 잃었는지 감지하는 질문

각 Phase 시작 시 아래 질문에 답하지 못하면 코딩을 시작하지 않게 한다.

```text
1. 왜 FCrDNS를 사용자 request 안에서 실행하면 안 되는가?
2. raw IP는 왜 masking-only 저장하면 안 되는가?
3. UA가 Googlebot이라고 할 때 바로 allowlist하면 안 되는 이유는?
4. Project가 Central DB에 직접 연결하지 않는 이유는?
5. viewer와 Parent Dashboard의 역할 차이는?
6. AdGuard 내부 오류가 발생하면 application은 어떻게 되어야 하는가?
7. 지금 클릭 카운터 기능을 구현해도 되는가?
8. 현재 Phase 밖의 refactor를 해도 되는가?
9. Authorization/Cookie/body를 수집해도 되는가?
10. automatic bot allowlist의 strict 기준은 무엇인가?
```

정답이 설계 문서와 다르면 먼저 문서를 다시 읽게 한다.

---

# 단계 종료 Handoff 문구

매 단계 마지막에 Codex에게 아래 형식으로 작성시키면
다음 채팅에서도 맥락이 잘 이어진다.

```text
PHASE HANDOFF

Completed Phase:
Commit:
Design version:
Event schema version:

What changed:
-

Verified by:
-

Performance:
-

Known risks:
-

Design deviations:
NONE / exact details

Files the next phase must read first:
-

Next phase:
-

Do NOT do next:
-
```

이 내용을 `IMPLEMENTATION_STATE.md`에도 그대로 누적한다.
