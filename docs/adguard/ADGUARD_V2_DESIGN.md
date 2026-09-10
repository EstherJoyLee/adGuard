# AdGuard v2 설계 문서

- 작성일: 2026-09-10
- 대상: 현재 1차 `adguard` PHP 모듈의 2차 재설계
- 상태: 구현 기준(Source of Truth)
- 호환 목표: 기존 프로젝트 동작 보존, PHP 5.6+ 문법 호환 유지
- 최우선 제약: **AdGuard 때문에 원래 서비스에 장애·지연·사용자 불편이 발생해서는 안 된다.**

---

## 0. 이 문서의 권한

이 문서는 AdGuard v2 구현의 기준 문서다.

구현 에이전트(Codex 포함)는 다음 우선순위를 따른다.

1. 이 설계 문서
2. `IMPLEMENTATION_STATE.md`의 현재 구현 상태
3. 현재 저장소의 실제 코드와 테스트
4. 구현 에이전트의 추정

서로 충돌하면 1번이 우선이다.  
설계 변경이 필요하면 임의 수정하지 말고 `DECISION_NEEDED`로 기록한다.

---

# 1. 현재 1차 버전에서 확인된 문제

현재 1차 버전의 장점은 유지한다.

- PHP 폴더형 모듈
- AdSense HTML 감지/게이트 구조
- monitor/enforce 개념
- JSONL 로그
- viewer.php
- 파일 기반 상태
- 기본 risk-engine
- crawler IP range 검증 골격
- 프로젝트별 독립 동작

그러나 다음은 v2에서 반드시 개선한다.

### 1.1 전체 요청 텔레메트리가 아님

현재는 최종 HTML에서 AdSense가 감지된 뒤 Risk Engine이 실행되는 흐름이 있어
광고가 없는 요청은 rate/session 통계에서 빠질 수 있다.

v2에서는:

> RequestTelemetry는 광고 탐지와 무관하게 PHP 요청 시작 직후 실행한다.

### 1.2 서버 신호의 상세 metrics가 로그에서 유실됨

엔진 내부에서 계산한 window count, limit 등은 존재하더라도
DecisionLogger에서 일부 score만 남기는 구조가 있다.

v2에서는 허용된 상세 metrics를 bounded schema로 남긴다.

### 1.3 UA 기반 hard deny가 너무 강함

`curl`, `wget`, `python-requests` 등 User-Agent 문자열만으로 높은 점수를 주고
hard deny까지 연결할 수 있는 정책은 폐기한다.

UA는 **신원 증명 수단이 아니다.**

### 1.4 IP identity 설정이 여러 곳으로 나뉨

`guard.php`, `engine.php` 등에서 trusted proxy 구성이 이원화될 가능성을 제거한다.

v2에서는 하나의 `IdentityResolver`만 사용한다.

### 1.5 crawler 검증 부족

공식 IP range 검증뿐 아니라 엄격한 FCrDNS 및 확장 가능한
RFC 9421 HTTP Message Signatures 검증 인터페이스를 추가한다.

### 1.6 문서와 실제 개인정보 저장 상태가 달라질 수 있음

v2에서는 실제 저장 schema가 문서와 일치하도록 테스트한다.

---

# 2. 목표

## 2.1 프로젝트 Agent

각 프로젝트에서 다음을 수행한다.

- PHP 요청의 서버 관측 신호 수집
- 요청 속도/행동/session/network 분석
- bot/crawler/AI agent claim 기록
- strict verification 결과 적용
- Risk Score 계산
- AdSense 제공 정책 결정
- 로컬 JSONL 증적 저장
- 프로젝트별 `viewer.php` 제공
- 중앙 Collector가 안전하게 가져갈 수 있는 export endpoint 제공

## 2.2 Parent Dashboard

여러 프로젝트를 중앙에서 관제한다.

- 전체 프로젝트 상태
- 프로젝트별 트래픽/위험도
- 비정상 IP/URL/행동 패턴
- verified bot / spoof suspected 현황
- Agent health
- collector 상태
- 프로젝트별 viewer 직접 링크
- 사건 조사용 forensic export

## 2.3 조사 증적

추후 보안업체 조사에 사용할 수 있도록 다음을 보존한다.

- 원본 IP(복구 불가능한 masking 금지)
- peer IP
- 최종 resolved client IP
- proxy chain 근거
- 정확한 서버 timestamp
- method/path/protocol/status
- 선택된 HTTP headers
- session/visitor 행동
- request rate
- risk signal과 계산 근거
- bot verification 근거
- agent/schema/rule version

---

# 3. 현재 범위에서 제외

다음은 v2 초기 구현에 넣지 않는다.

- 미완성 광고 클릭 카운터 연동
- 광고 클릭을 가로채는 JS
- 투명 masking layer
- AdSense iframe 조작
- Canvas fingerprint
- Audio fingerprint
- WebGL fingerprint
- 설치 font fingerprint
- hardwareConcurrency 기반 차단
- CAPTCHA 기본 적용
- 중앙 대시보드의 SSH/root 서버 제어
- request path에서 외부 위협정보 API 호출

광고 클릭 카운터는 추후 `AdInteractionSignalProvider` 인터페이스로 별도 연결한다.

---

# 4. 절대 불변 원칙

## P1. Fail-open

AdGuard 내부 오류가 원래 프로젝트의 500 에러로 번지면 안 된다.

Telemetry 실패:
- 원래 서비스 계속

로그 실패:
- 원래 서비스 계속

중앙 Dashboard 장애:
- 원래 서비스 계속

DNS 장애:
- 원래 서비스 계속

bot verification 장애:
- `UNKNOWN/PENDING` 처리
- 원래 서비스 계속

## P2. 사용자 요청 경로에서 외부 네트워크 I/O 금지

일반 사용자 request 처리 중 다음을 하지 않는다.

- 중앙 API 호출
- 중앙 DB 연결
- 실시간 FCrDNS lookup
- Google/OpenAI 공식 IP 목록 다운로드
- ASN/WHOIS 외부 조회
- 보안업체 API 조회
- 임의 외부 HTTP 요청

## P3. 정상 사용자 UX 우선

기본 정책:

- NORMAL → 사이트/광고 정상
- OBSERVE → 사이트/광고 정상 + 상세 기록
- HIGH_RISK → 사이트 정상 + 광고 제공 억제 가능
- SEVERE → 사이트 정상 + 일정 시간 광고 제공 억제
- 명백한 HTTP abuse만 별도 rate limiting 고려

페이지 전체 차단은 광고 방어와 분리한다.

## P4. AdSense 코드 자체를 임의 조작하지 않음

위험 사용자의 광고를 제한해야 할 경우:

> 광고가 생성된 뒤 투명 레이어로 덮는 방식이 아니라,
> 가능하면 광고 bootstrap/slot을 출력하기 전에 정책을 결정한다.

## P5. bounded resource

모든 파일, state, cache, event, counter에는 상한이 있어야 한다.

무한 배열/무한 로그/무한 세션 state를 금지한다.

## P6. 원본 IP 증적 보존

보안업체 조사 목적으로 원본 IP가 필요하다.

따라서 raw IP를 복구 불가능한 값으로 대체하지 않는다.

중앙 DB에서는:
- `raw_ip_enc`: 복호화 가능한 암호화 원본
- `ip_lookup_hash`: 빠른 검색용 보조값

을 함께 사용할 수 있다.

## P7. 민감 인증정보는 수집하지 않음

절대 저장 금지:

- Authorization
- Cookie 원문
- Set-Cookie
- password
- access token
- refresh token
- 전체 POST body
- 파일 upload body
- 민감한 query parameter 값

---

# 5. 전체 아키텍처

```text
                         CENTRAL CONTROL CENTER
                 ┌──────────────────────────────────┐
                 │ Parent Dashboard                 │
                 │ Project Detail                   │
                 │ Investigation / Export           │
                 └────────────────┬─────────────────┘
                                  │
                                  ▼
                 ┌──────────────────────────────────┐
                 │ Central DB                       │
                 │ projects                         │
                 │ guard_events                     │
                 │ guard_hourly_stats               │
                 │ guard_ingest_health              │
                 │ investigation_cases(optional)    │
                 └────────────────▲─────────────────┘
                                  │
                           Central Collector
                                  │
                  authenticated bounded PULL
                                  │
       ┌──────────────────────────┼──────────────────────────┐
       ▼                          ▼                          ▼
  PROJECT A                  PROJECT B                  PROJECT C
  ─────────                  ─────────                  ─────────
  adguard/                   adguard/                   adguard/
      │                          │                          │
      ├ RequestTelemetry         ├ RequestTelemetry         ├ ...
      ├ IdentityResolver         ├ IdentityResolver
      ├ RiskEngine               ├ RiskEngine
      ├ Local JSONL              ├ Local JSONL
      ├ export.php               ├ export.php
      └ viewer.php               └ viewer.php
```

중앙 Dashboard는 각 서버의 root/SSH/DB password를 저장하지 않는다.

---

# 6. 프로젝트 요청 처리 흐름

```text
HTTP Request
    │
    ▼
ad_guard_boot()
    │
    ├─ 시작시간 기록
    ├─ IdentityResolver
    ├─ local bounded counters
    ├─ verification cache lookup
    ├─ RiskEngine
    │
    ▼
Application PHP
    │
    ▼
Ad opportunity?
    │
    ├─ normal → 광고 정상
    └─ no-ads policy → 광고 opportunity 생성 안 함
    │
    ▼
Response
    │
    ├─ status
    ├─ duration
    ├─ bytes(가능한 범위)
    │
    ▼
bounded JSONL append
```

User request path에는 외부 DNS/HTTP/DB가 없다.

---

# 7. 신규/정리할 핵심 컴포넌트

## 7.1 RequestTelemetry

책임:
- 요청 시작시간
- method
- host
- path
- protocol
- peer IP
- resolved client IP
- proxy resolution metadata
- server-issued visitor/session reference
- bounded rate counters

## 7.2 ResponseTelemetry

책임:
- HTTP response status
- duration
- response byte estimate
- final risk/action
- ad opportunity / ad policy

## 7.3 IdentityResolver

IP 관련 판단의 단일 진실 공급원.

입력:
- REMOTE_ADDR
- trusted proxy configuration
- forwarded headers

출력:
- `peer_ip`
- `client_ip`
- `ip_source`
- `proxy_trusted`
- `forwarded_chain`
- `resolution_status`

규칙:
- trusted proxy로 확인되지 않은 peer가 보낸 X-Forwarded-For를 client IP로 믿지 않는다.

## 7.4 TelemetryEvent

모든 로깅/중앙 전송은 하나의 normalized event schema를 사용한다.

## 7.5 LocalEventStore

- JSONL append
- bounded lock
- 실패 시 drop + health counter
- 서비스에는 예외 전파 금지

## 7.6 BotIdentityVerifier

여러 verification provider를 조합한다.

- UA claim parser
- FCrDNS result
- official IP range
- official domain suffix
- ASN/provider consistency(optional enrichment)
- RFC 9421 verifier
- internal-agent verifier

## 7.7 RiskEngine

서버 신호 중심.

UA 단독 hard-deny 금지.

## 7.8 viewer.php

해당 프로젝트의 실시간/원본 조사 화면.

## 7.9 export.php

Central Collector 전용 bounded export endpoint.

일반 사용자에게 공개 로그를 제공하지 않는다.

---

# 8. 신뢰 모델

| 신호 | 등급 | 비고 |
|---|---:|---|
| server timestamp | A | 서버 생성 |
| peer IP | A- | proxy/VPN으로 출발점 변경 가능 |
| server-issued signed state | A | 위조 난이도 높음 |
| request rate observed by server | A | 행동으로 회피 가능 |
| path sequence | A- | 행동 기반 |
| response status/duration | A | 서버 관측 |
| trusted proxy metadata | A- | peer 신뢰 검증 필수 |
| verified FCrDNS | B+ | 공식 suffix + forward match 필수 |
| official IP range | B+ | 최신 cache 필요 |
| RFC 9421 valid signature | A급 후보 | provider identity까지 별도 검증 |
| Sec-Fetch-* | C | raw client는 위조 가능 |
| User-Agent | D | self-claimed |
| JS fingerprint | D | v2 제외 |

---

# 9. Event Schema

모든 event는 `schema_version`을 가진다.

예시:

```json
{
  "schema_version": 2,
  "event_id": "uuid-or-safe-id",
  "project_id": "project_x",
  "request_id": "req_x",

  "occurred_at_utc": "2026-09-10T06:10:22.123Z",

  "request": {
    "method": "GET",
    "host": "example.com",
    "path": "/event.php",
    "protocol": "HTTP/1.1",
    "query_keys": ["page"]
  },

  "network": {
    "peer_ip": "173.x.x.x",
    "client_ip": "211.248.110.124",
    "ip_source": "trusted_proxy_header",
    "proxy_trusted": true,
    "forwarded_chain": ["211.248.110.124", "173.x.x.x"]
  },

  "headers": {
    "user_agent": "...",
    "accept": "...",
    "accept_language": "...",
    "referer": "...",
    "origin": "...",
    "sec_fetch_site": "...",
    "sec_fetch_mode": "...",
    "sec_fetch_dest": "...",
    "sec_fetch_user": "..."
  },

  "behavior": {
    "ip_rate_10s": 4,
    "ip_rate_60s": 16,
    "ip_rate_600s": 91,
    "visitor_rate_10s": 2,
    "visitor_rate_60s": 7,
    "session_age_sec": 934,
    "session_churn": 0
  },

  "bot": {
    "claimed_identity": null,
    "provider": null,
    "verification_status": "NOT_CLAIMED",
    "verification_level": "V0",
    "fcrdns": null,
    "official_ip_range": null,
    "rfc9421": null
  },

  "risk": {
    "score": 0,
    "level": "NORMAL",
    "action": "ALLOW",
    "signals": {}
  },

  "response": {
    "status": 200,
    "duration_ms": 41.2,
    "bytes": 37128
  },

  "agent": {
    "version": "2.x",
    "rule_version": "2.x"
  }
}
```

## 9.1 Header allowlist

저장 허용 기본값:

- User-Agent
- Accept
- Accept-Language
- Referer
- Origin
- Sec-Fetch-Site
- Sec-Fetch-Mode
- Sec-Fetch-Dest
- Sec-Fetch-User
- X-Forwarded-For (proxy forensic 용도)
- provider-specific client IP header (trusted proxy 환경에서만 의미 부여)

각 값은 최대 길이를 제한한다.

## 9.2 Query

기본:
- path 저장
- query parameter **key 이름만 저장**
- value 저장 금지

명시적으로 안전하다고 승인한 query key만 별도 allowlist로 값을 수집할 수 있다.

---

# 10. Strict Bot / AI Agent Verification

## 10.1 기본 철학

`User-Agent: Googlebot` 같은 문자열만으로 절대 allowlist 하지 않는다.

모든 bot/crawler/AI agent claim은 verification pipeline을 거친다.

## 10.2 FCrDNS는 모든 claim에 시도

모든 claimed bot identity는 out-of-band에서 FCrDNS 검증 대상으로 등록한다.

절차:

1. resolved client IP 확보
2. reverse DNS(PTR)
3. hostname normalization
4. provider의 허용된 공식 suffix와 정확한 DNS label boundary 비교
5. hostname forward A/AAAA lookup
6. 원래 client IP가 결과에 포함되는지 확인
7. 결과 cache

예:
- `googlebot.com.attacker.test` → 실패
- 단순 substring 비교 금지

## 10.3 공식 IP range

provider가 공식 IP/CIDR을 제공하면 FCrDNS와 함께 교차검증한다.

공식 range 데이터 refresh는 사용자 request에서 절대 수행하지 않는다.

- last-known-good cache
- atomic replace
- refresh 실패 시 기존 cache 유지

## 10.4 RFC 9421

`Rfc9421Verifier` 인터페이스를 둔다.

검증 상태:

- NOT_PRESENT
- VALID
- INVALID
- UNSUPPORTED_ALGORITHM
- UNSUPPORTED_PROFILE
- KEY_UNAVAILABLE
- EXPIRED
- REPLAY_SUSPECTED

주의:

> RFC 9421 signature가 VALID라는 사실만으로 Google/OpenAI 같은 provider identity가
> 신뢰된다는 뜻은 아니다.

다음이 따로 검증되어야 한다.

- trusted provider registry
- trusted key identity
- signature profile
- signed components
- created/expires
- replay protection
- authority/method/path scope

PHP 5.6 서버가 지원하지 않는 최신 crypto algorithm은
`UNSUPPORTED_ALGORITHM`으로 기록한다.

외부 dependency를 억지로 설치하거나 request path에서 원격 key lookup을 하지 않는다.

## 10.5 Strict verification level

| Level | 조건 | 자동 Allowlist |
|---|---|---|
| V0 | UA claim only | 불가 |
| V1 | 일부 network 증거 | 불가 |
| V2 | FCrDNS + 공식 suffix + forward match | 기본 불가/관리정책 |
| V3 | V2 + 공식 IP range 일치 | 가능 |
| V4 | V3 + provider verified signal | 가능 |
| V5 | RFC 9421 등 cryptographic identity + provider trust + FCrDNS/network consistency | 최상위 |

사용자의 strict 정책에 따라:

> 자동 bot allowlist는 기본적으로 FCrDNS PASS를 필수로 한다.

FCrDNS를 공식적으로 검증할 근거가 없는 provider는
`PARTIALLY_VERIFIED` 또는 `UNVERIFIED`로 남기고 자동 allowlist 하지 않는다.

## 10.6 Failure와 Unsupported 분리

- 검증 정보가 명백히 모순됨 → `SPOOF_SUSPECTED`
- 검증 방법을 provider가 제공하지 않음 → `UNSUPPORTED/UNVERIFIED`
- DNS 일시 장애 → `PENDING/UNKNOWN`

DNS 장애 자체로 spoof 판정을 하지 않는다.

---

# 11. Verification을 request path 밖으로 빼는 방법

FCrDNS는 DNS I/O가 있으므로 사용자 request에서 동기 실행하지 않는다.

기본 전략:

1. 요청에서 bot claim + IP를 이벤트에 기록
2. cache hit가 있으면 cached verification 사용
3. cache miss면 `PENDING`
4. Central Collector/Verifier가 out-of-band 검증
5. 중앙 Dashboard에서 최종 verification 표시
6. 프로젝트 로컬 정책은 검증되지 않은 claim 하나만으로 페이지를 block하지 않음

향후 필요하면 인증된 정책/cache sync endpoint를 추가할 수 있으나
v2 초기 범위에서는 YAGNI 원칙을 따른다.

---

# 12. Risk Engine

## 12.1 서버 중심 신호

초기 우선순위:

- IP request rate
- visitor request rate
- burst
- session churn
- route repetition
- route traversal pattern
- HTTP error pattern
- session age
- bot spoof evidence
- trusted/untrusted proxy inconsistency

## 12.2 UA

UA는:
- 로깅
- 모순 탐지
- bot claim 시작점

으로만 쓴다.

UA 단독 hard deny 금지.

## 12.3 기본 상태

```text
NORMAL
OBSERVE
HIGH_RISK
SEVERE
```

정확한 score threshold는 monitor-only 실데이터 수집 후 보정한다.

초기 배포에서 임의 threshold로 광고를 막지 않는다.

---

# 13. 광고 정책

v2 초기 운영:

```text
NORMAL      → ALLOW_ADS
OBSERVE     → ALLOW_ADS + enhanced log
HIGH_RISK   → MONITOR ONLY (초기)
SEVERE      → MONITOR ONLY (초기)
```

실데이터 검증 뒤 enforce phase에서:

```text
HIGH_RISK   → NO_ADS 일정 TTL
SEVERE      → NO_ADS 더 긴 TTL
```

페이지 자체는 정상 제공한다.

AdSense 위 transparent overlay로 클릭을 막지 않는다.

---

# 14. Local viewer.php UI

## 역할

> 특정 프로젝트의 원본 이벤트와 Risk 계산을 깊게 조사하는 화면.

## 상단

- Project
- Environment
- Agent Version
- Agent Health
- Last Event
- Local Store status
- Central collection 상태(알 수 있는 범위)
- 현재 필터
- Parent Dashboard 링크(optional)

## 필터

필수:

- 시간
- 원본 IP
- peer IP
- Request ID
- visitor/session
- Risk Level
- Risk Score min/max
- Action
- Path
- Method
- HTTP status
- claimed bot
- provider
- verification status
- FCrDNS result
- official IP range result
- RFC 9421 result
- IP source
- proxy trusted 여부

UX:

- 값 클릭 → Filter In
- 값 클릭 → Filter Out
- Clear filters
- URL에 민감한 raw credential을 넣지 않음

## 이벤트 테이블

기본 컬럼:

- Time
- Client IP
- Method
- Path
- Status
- Risk
- Action
- Traffic Type
- Bot Verification

## Event Detail Flyout

Tabs:

1. Overview
2. Network
3. Request
4. Behavior
5. Bot Verification
6. Risk Calculation
7. Response
8. Raw Evidence

Raw Evidence는 저장된 allowlisted 필드만 보여준다.

---

# 15. Parent Dashboard UI

## 15.1 Overview

KPI:
- 프로젝트 수
- Healthy / delayed / offline Agent
- 총 요청 수
- suspicious/severe
- 광고 정책 상태
- spoof suspected bot
- collector 오류

그래프:
- request timeline
- risk timeline
- bot/AI crawler timeline

Top:
- suspicious client IP
- suspicious path
- spoofed identity
- project by risk rate

## 15.2 Projects Table

컬럼:

- Project
- Domain
- Environment
- Server Label
- AWS Name
- Agent Health
- Last Seen
- Requests
- Suspicious
- Severe
- Spoof Suspected
- Viewer

`Viewer`는 프로젝트의 인증된 viewer URL을 새 탭으로 연다.

## 15.3 Project Detail

- 요청/Risk 시간 추이
- risk distribution
- response status
- top suspicious IP
- top suspicious path
- bot/AI traffic
- crawler verification
- agent health
- recent suspicious events
- `원본 Viewer 열기` 고정 버튼

## 15.4 Dashboard 필터

- 기간
- Project
- Environment
- Risk level
- score range
- Action
- client IP
- provider
- traffic type
- bot verification status
- path
- method
- response status
- agent version
- agent health
- collector status

---


# 15.5 Dashboard Data Analysis / Visualization

Parent Dashboard는 단순 모니터링 화면이 아니라 90일 중앙 데이터를 탐색할 수 있는
경량 분석 도구를 제공한다.

## 기본 분석 UI

### Analysis Lab

사용자가 다음을 조합할 수 있게 한다.

Dimensions:
- Project
- Environment
- client IP
- peer IP
- path / route group
- method
- HTTP status
- risk level
- action
- traffic type
- bot provider
- claimed bot identity
- verification status
- FCrDNS status
- official IP range status
- RFC 9421 status
- agent/rule version
- time bucket

Metrics:
- request count
- unique client IP count
- unique visitor count
- suspicious count
- severe count
- spoof-suspected count
- error count
- average / p95 risk score
- average / p95 response duration
- average / p95 guard duration
- telemetry drop count
- collector ingest lag

Operations:
- Group By
- Filter In / Filter Out
- Sort
- Top N
- Compare Projects
- Compare Periods
- hourly / daily aggregation
- saved analysis preset(optional)

대규모 raw event를 브라우저에 전부 내려보내서 계산하지 않는다.
집계/필터는 중앙 DB 또는 서버 분석 API에서 수행한다.

## Chart.js

Dashboard visualization은 Chart.js 사용 가능.

권장 chart:
- Line: requests/risk/spoof/latency time series
- Stacked Bar: project별 risk level 구성
- Horizontal Bar: Top suspicious IP/path/provider
- Doughnut/Pie: 제한된 범주의 traffic type / verification status
- Scatter: request rate vs risk score, guard duration vs request volume 등

성능 원칙:
- raw 수십만/수백만 event를 Chart.js에 직접 전달 금지
- 시간 그래프는 server-side hourly/daily aggregation 사용
- chart point 수에 upper bound 적용
- 큰 line dataset은 Chart.js decimation 또는 사전 집계 사용
- animation은 대형 데이터에서 비활성화 가능
- chart API에도 time range / max points 제한 적용

Chart drill-down:
차트의 특정 point/bar를 클릭하면 동일 시간/Project/filter를 유지해
Events 또는 Project Detail로 이동한다.

## 분석과 보안 판정 분리

Dashboard 분석 결과는 운영자가 탐색하는 도구이다.
사용자 화면 차단/NO_ADS 판정을 브라우저 Chart.js 계산 결과에 의존하지 않는다.
Risk 판단은 Project Agent의 서버 로직 또는 승인된 중앙 정책에서 수행한다.


# 16. 프로젝트 등록 정보

Parent Dashboard에는 다음을 저장할 수 있다.

- project_id
- project_name
- domain
- redirect_domains
- environment
- server_label
- server_ip(관리 메타데이터)
- aws_name
- viewer_url
- export_url
- agent_version
- last_seen_at

저장 금지:

- root password
- SSH password
- 프로젝트 DB root password

Central Pull용 project secret은
애플리케이션 일반 DB password와 분리된 전용 secret을 사용하며,
중앙에서는 암호화하여 보관한다.

---

# 17. Central Pull

## 왜 Pull인가

프로젝트 request path에서 중앙 통신을 없애
중앙 장애가 프로젝트 장애로 전파되는 것을 막기 위함이다.

## Export 인증

예:

- project_id
- timestamp
- nonce
- request path
- HMAC signature

검증:
- timestamp 허용 오차
- nonce replay 방지
- constant-time signature compare
- request rate 제한
- 최대 batch 크기 제한

## Export 방식

cursor 기반 incremental export.

금지:
- 매 poll마다 90일 JSONL 전체 scan
- 무제한 response
- export 때문에 긴 file lock

예:
- `after_cursor`
- `limit`
- `max_bytes`

---

# 18. Central DB

## guard_projects

프로젝트 메타/상태.

## guard_events

90일 상세 event.

주요 컬럼:
- event_id UNIQUE
- project_id
- occurred_at
- received_at
- raw_ip_enc
- ip_lookup_hash
- peer_ip_enc(optional)
- path
- method
- protocol
- response_status
- risk_level
- risk_score
- action
- traffic_type
- claimed_identity
- bot_provider
- bot_verification_status
- fcrdns_status
- official_range_status
- rfc9421_status
- event_payload_json
- schema_version
- agent_version

## guard_hourly_stats

Dashboard 속도를 위한 프로젝트/시간 단위 집계.

## guard_ingest_health

- last_pull
- last_success
- ingest_lag
- last_cursor
- events_received
- duplicate_events
- error_count
- last_error

---

# 19. 90일 Retention

중앙 `guard_events` 기본 보관:
- 90일

삭제:
- Central scheduler에서 수행
- 프로젝트 서버 cron 필요 없음

삭제 실패:
- Dashboard health alert
- 프로젝트 서비스 영향 없음

선택 기능:
- 조사중 사건에 대한 `investigation_hold`
- 회사 개인정보/보안정책 승인 후 활성화

로컬 JSONL은 서버 디스크 보호를 위해 중앙보다 짧게 둘 수 있다.
기본값은 운영 데이터량을 보고 확정하되 반드시 bounded retention을 가진다.

---

# 20. Forensic / 보안업체 전달

## Investigation Export

필터:
- Project
- IP
- 기간
- risk level
- path
- bot identity

출력 예:

```text
incident_YYYYMMDD/
  summary.json
  events.ndjson
  timeline.csv
  environment.json
  integrity.sha256
```

보존 정보:
- 정확한 raw IP
- peer/client IP 관계
- trusted proxy 판단
- request timestamps
- URL path
- method/status
- selected headers
- request rate
- visitor/session behavior
- bot verification evidence
- risk calculation
- agent/rule/schema version

저장하지 않았던 Cookie/Authorization/body를 사후 export에서 생성하거나 추정하지 않는다.

---


# 20.1 Analysis Export (Excel / CSV)

보안업체용 Investigation Export와 데이터 분석용 Export는 목적을 분리한다.

## Export Scope

사용자는 Parent Dashboard에서 다음 범위를 선택할 수 있다.

- 현재 필터 결과
- 특정 Project
- 여러 Project
- 전체 Project
- 특정 IP
- 특정 path
- 특정 Risk Level
- 특정 bot/provider
- 원하는 기간(최대 90일 정책 범위)

## Export Format

### CSV
대규모 raw event 분석의 기본 형식.

장점:
- streaming 가능
- memory 사용량 낮음
- Python/Pandas/SQL/BI 도구 연계 용이
- Excel에서도 열 수 있음

### XLSX
사람이 직접 Excel에서 확인하는 분석용 형식.

워크북 권장 구성:

- `Summary`
- `Hourly_Stats`
- `Events`
- `IPs`
- `Paths`
- `Bots`
- `Agent_Health`
- `Export_Metadata`

`Events`가 Excel 한 시트의 행 한계를 넘거나 파일 크기/메모리 budget을 넘으면:
- 기간/Project별 여러 파일로 split하거나
- CSV export를 권장/강제한다.

XLSX 생성은 프로젝트 Agent 서버에서 하지 않는다.
Central Dashboard/Export Worker에서만 생성한다.

## Export Columns

Events 기본:
- occurred_at
- received_at
- project_id / project_name
- domain
- client_ip (권한 필요)
- peer_ip (권한 필요)
- ip_source
- proxy_trusted
- method
- host
- path
- protocol
- response_status
- response_duration_ms
- guard_duration_ms
- risk_level
- risk_score
- action
- traffic_type
- claimed_identity
- bot_provider
- bot_verification_status
- fcrdns_status
- official_range_status
- rfc9421_status
- ip_rate_10s / 60s / 600s
- visitor_rate_10s / 60s / 600s
- session_age_sec
- session_churn
- agent_version
- rule_version
- schema_version

민감 header는 기본 분석 Export에서 제외한다.
필요한 forensic evidence는 Investigation Export에서 다룬다.

## Export Security

- raw IP가 들어간 export는 별도 권한
- export 실행 audit
- export filter/time range 기록
- 생성 파일 TTL
- 다운로드 횟수/만료 정책 가능
- Authorization/Cookie/password/token/body는 절대 export 금지

## 서버 안정성

대용량 Export는 일반 Dashboard HTTP 요청에서
거대한 XLSX를 메모리에 한 번에 생성하지 않는다.

Central 측에서:
- streaming CSV
- bounded DB cursor
- chunked XLSX writer 또는 background export worker
- query timeout
- row/file size budget
- concurrency limit
를 사용한다.

Export 실패/지연은 Project 서비스에 어떤 영향도 주지 않는다.

Local viewer.php에서는:
- 현재 필터된 소량 결과 CSV
- 또는 bounded event range export
만 허용한다.

90일 전체/전체 Project Export는 Parent Dashboard 전용이다.


# 21. 개인정보/보안

원본 IP가 필요하므로 권한 통제가 필수다.

권장:
- 중앙 raw IP encryption at rest
- RBAC
- raw IP 조회 audit log
- forensic export audit log
- viewer 별도 인증 또는 강한 allowlist
- export endpoint HMAC 인증
- secret은 소스코드에 hard-code 금지

---

# 22. 서버 장애 방지 요구사항

## 22.1 외부 의존성

user request:
- 외부 DNS 0회
- 외부 HTTP 0회
- Central DB 0회
- Central Collector 0회

## 22.2 Logging

- append-only
- 최대 event 크기
- 짧은 lock timeout
- lock 실패 시 event drop
- disk 부족 시 telemetry 중단
- application은 정상 진행

## 22.3 State

- TTL
- max entries
- stale cleanup budget
- 한 요청에서 전체 state scan 금지

## 22.4 Agent self-observability

반드시 측정:

- guard_duration_ms
- telemetry_write_duration_ms
- dropped_event_count
- storage_error_count
- state_error_count

Dashboard health:
- Agent P50/P95/P99
- 5xx 변화
- Collector lag
- dropped events
- disk/store 상태

## 22.5 Release Blocker

다음 중 하나면 enforce 배포 금지:

- AdGuard 때문에 새로운 5xx 발생
- request P95가 유의미하게 악화
- file lock contention
- storage 무한 증가
- DNS/central 장애가 page latency에 전파
- 정상 브라우저가 page block
- UA 단독으로 hard deny
- trusted proxy 설정 불일치

---

# 23. 배포

## Phase A: Monitor Only

최소 2주 또는 충분한 트래픽 표본 확보.

- 아무 광고/페이지도 자동 차단하지 않음
- score distribution 분석
- 정상/봇/QA/VPN/NAT 환경 분석

## Phase B: Shadow Policy

실제 차단은 하지 않되:
- would've_no_ads
- would've_rate_limit

기록.

## Phase C: Limited Enforce

가장 명확한 Severe 조건부터 NO_ADS 적용.

## Phase D: Tune

오탐/미탐/성능 데이터 기반 조정.

---

# 24. PHP 5.6 호환성

기존 호환 목표를 유지한다.

구현에서 주의:
- scalar type declaration 금지
- return type 금지
- nullable type 금지
- `??` 금지
- `<=>` 금지
- typed property 금지
- arrow function 금지
- `Throwable` 전제 금지

환경별 crypto 기능은 capability detection한다.

---

# 25. 파일 변경 방향

실제 저장소를 먼저 재확인한 뒤 정확한 경로를 확정한다.

기존에서 유지/수정 예상:

- `adguard.php` → boot orchestration 수정
- `src/Guard.php` → 광고 책임으로 축소
- `src/DecisionLogger.php` → normalized event writer로 정리 또는 분리
- `viewer.php` → forensic viewer로 확장
- `config/guard.php` → identity/telemetry 중앙 설정
- `config/engine.php` → 중복 identity config 제거
- `engine/src/Signals/UserAgentAnomalySignal.php` → UA hard deny 제거
- `engine/src/CrawlerVerifier.php` → 새 BotIdentityVerifier 계층으로 이전/래핑
- `tools/refresh-crawler-ranges.php` → out-of-band cache refresh 원칙 유지

신규 예상:

- `src/Telemetry/RequestTelemetry.php`
- `src/Telemetry/ResponseTelemetry.php`
- `src/Telemetry/TelemetryEvent.php`
- `src/Identity/IdentityResolver.php`
- `src/Storage/LocalEventStore.php`
- `src/Bot/BotIdentityVerifier.php`
- `src/Bot/FcrdnsVerifier.php`
- `src/Bot/Rfc9421Verifier.php`
- `src/Bot/ProviderRegistry.php`
- `src/Export/ExportController.php`
- `export.php`

단, 기존 프로젝트 naming convention이 다르면 기존 convention을 우선한다.

---

# 26. Acceptance Criteria

v2 완료 조건:

1. 광고 없는 PHP 요청도 서버 telemetry에 반영된다.
2. 기존 정상 페이지 출력이 변경되지 않는다.
3. AdGuard 내부 storage 오류를 강제로 발생시켜도 원래 페이지는 정상 응답한다.
4. Central 서버가 완전히 죽어도 프로젝트 page latency/응답에 영향이 없다.
5. UA 문자열 하나만으로 allowlist/hard deny하지 않는다.
6. claimed bot은 모두 FCrDNS 검증 대상으로 기록된다.
7. 자동 bot allowlist는 strict verification 기준을 충족해야 한다.
8. viewer에서 raw IP, network resolution, server metrics, bot verification 근거를 볼 수 있다.
9. Dashboard에서 project → viewer로 drill-down 가능하다.
10. 중앙 DB에 raw IP를 복구 가능한 형태로 보존한다.
11. Authorization/Cookie/password/body는 저장되지 않는다.
12. 90일 retention이 중앙에서 자동 수행된다.
13. export는 인증/nonce/replay protection/bounded batch를 가진다.
14. 로컬 state/log는 무한 증가하지 않는다.
15. Agent 자체 P50/P95/P99 overhead를 측정할 수 있다.
16. monitor-only → shadow → limited enforce 순서로 배포 가능하다.
17. 클릭 카운터/masking 기능은 구현되지 않는다.
18. Dashboard Analysis Lab에서 Project/기간/Risk/IP/path/bot 기준 집계 분석이 가능하다.
19. Chart.js에는 bounded/aggregated dataset만 전달하며 raw 대용량 event를 직접 렌더링하지 않는다.
20. Project별/전체 필터 결과를 CSV/XLSX로 export할 수 있다.
21. 대용량 export는 Central에서 수행하며 Project Agent의 CPU/메모리/latency에 영향을 주지 않는다.
22. raw IP export는 권한 및 audit를 거치며 원본 복원 가능성이 유지된다.
