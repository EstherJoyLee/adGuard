# Phase 0 Baseline Freeze & Audit Plan

**Goal:** 현재 v1 프로덕션 파일을 그대로 보존하고 실제 테스트, 요청 비용, 구조 및 설계 차이를 재현 가능한 기준점으로 기록한다.

**Architecture:** 기존 테스트를 실행하고, 독립 임시 storage를 사용하는 PHP 회귀 테스트와 HTTP 측정 fixture를 추가한다. 측정은 로컬 loopback에서만 수행하며 외부 서비스에는 요청하지 않는다.

**Tech Stack:** Windows PowerShell, 현재 설치된 PHP 8.5.5 CLI/내장 HTTP 서버, 기존 PHP 5.6 정적 검사기. PHP 5.6 실제 실행 여부와 정적 검사 결과를 구분한다.

**Spec:** `docs/adguard/ADGUARD_V2_DESIGN.md`, `ADGUARD_V2_IMPLEMENTATION_PLAN.md`의 Phase 0 및 사용자 Phase 0 프롬프트.

## Global Constraints

- 기능 변경 0, 기존 production code와 설계 원문 수정 금지.
- 기존 v1 차이는 보존·재현하고 DECISION_NEEDED를 기록. Phase 1 기능 구현 금지.
- 테스트/성능 수치는 실제 실행 결과만 기록. skip과 실패를 통과로 합산하지 않음.
- PHP 5.6+ 문법, 정상 요청 경로 외부 DNS/HTTP/central DB 0, fail-open, bounded resource, raw IP 증적 및 민감정보 제외는 v2 제약으로 유지.
- 클릭 카운터, masking UI, 관련 없는 refactor, 원격 push 없음.

## Execution

- [x] 설계/계획/상태 문서 재독, Git/tree/테스트 및 PHP 환경 확인, 원본 88개 파일 SHA-256 확보.
- [x] `tools/run-phase0-checks.ps1`로 기존 `tests/*-test.php`, `engine/tests/*-test.php`, boundary 검사, PHP 5.6 검사기 self-test와 정적 검사, 전체 PHP lint 실행. 각 exit/skip/output을 `docs/adguard/phase0/checks.json`에 보존.
- [x] `tests/phase0-baseline-test.php`에서 광고 유무와 counter/event 연결, 실제 UA signal→광고 정책, 서로 다른 proxy 설정, bounded raw UA 및 민감정보 배제, 상세 metrics 생략을 기존 동작으로 고정. 테스트 임시 파일만 사용.
- [x] `tests/fixtures/phase0-page.php`와 `tools/phase0-benchmark.ps1`로 별도 HTTP 프로세스의 최소 페이지/guard 비광고/guard 정상 광고 경로를 측정. warmup, 반복수, p50/p95/mean, PHP peak memory, 응답 동일성을 기록. 임시 서버는 Hidden으로 실행하고 자기 프로세스만 종료.
- [x] entry→response 호출관계, normalized 이전 JSONL 필드, state/crawler/refresh/viewer 및 README 차이를 `docs/adguard/PHASE_0_BASELINE_REPORT.md`에 기록. storage web-root 노출은 로컬 내장 서버의 실제 접근 결과와 배포 서버 설정 의존성을 구분.
- [x] 최초 실행 실패가 있으면 원인만 규명. 기능 변경 금지를 우선하며 production 수정 없이 해소 가능한 실행 환경 문제만 처리.
- [x] 신규 검사 실행, 기존 파일 hash 보존 검증, `git diff --check`, 상태 문서 누적. 작성자 정보가 제공되면 로컬 baseline 커밋을 생성하고 검증. 제공되지 않으면 커밋 미완료로 명시.

## Phase 1 Handoff

`IMPLEMENTATION_STATE.md`에 실제 검증 결과/남은 결정/정확한 변경 예상 파일을 기재한다. Phase 1은 별도 프롬프트 및 선행 결정 후 시작한다.
