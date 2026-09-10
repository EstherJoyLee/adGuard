# Risk Engine 디버깅 가이드

## 대상 독자

"왜 나(또는 특정 방문자)한테 광고가 안 뜨는지" 알아내야 하는 사이트 운영자. `ad-preview.php`의 **Risk Engine 진단** 패널과 `?debug=json`을 사용한다.

## 용어 대응표

`adguard/engine/`은 이 프로젝트를 몰라야 하므로 자기만의 어휘를 쓴다. `include/ad-defense.php`의 `ad_defense_risk_from_engine()`이 이 표대로 변환한다.

| risk-engine 등급 | 점수대(기본값) | 이 프로젝트 등급 | ads_allowed |
|---|---|---|---|
| NORMAL | 0–24 | LOW | true |
| ELEVATED | 25–49 | MEDIUM | 보통 true, 강한 단일 신호면 false |
| SUSPICIOUS | 50–74 | HIGH | false |
| SEVERE | 75–100 | CRITICAL | false |

점수 구간은 `adguard/config/engine.php`(없으면 `src/Config.php`의 기본값)에서 조정 가능. 실제 광고 허용 여부는 `adguard/` 정책이 최종 결정한다. 기본 정책은 `SUSPICIOUS`/`SEVERE`를 차단하고, 자동화 UA·요청 속도·방문자 속도·세션 churn의 강한 단일 신호도 등급과 별개로 차단한다.

## 진단 패널 필드 설명

`ad-preview.php`의 "Risk Engine 진단" 섹션:

- **Engine level / ad-defense level**: 위 표의 원본 값과 변환된 값을 나란히 보여준다.
- **Score**: 0–100. `RiskEngine\Scoring\ScoreCombiner`가 신호들을 합친 최종 점수.
- **Inspected IP**: 비어 있으면 현재 요청 자신, 값이 있으면 그 IP를 조회 중.
- **신호별 표**: `user_agent` / `rate_limit` / `visitor_rate` / `session_churn` 각각의 triggered 여부, 기여 점수, 사유 텍스트, 원시 지표.
  - `user_agent.metrics`: 실제로 감지된 User-Agent 문자열과 Accept 계열 헤더 유무.
  - `rate_limit.metrics`: 설정된 각 윈도우(`window_10s`, `window_300s` 등)의 현재 카운트/허용치/만료 여부.
  - `visitor_rate.metrics`: `__rek_id` 방문자 식별자 기준의 각 요청 윈도우. 공유 IP 환경에서 특정 방문자의 과속만 분리한다.
  - `session_churn.metrics`: 최근 윈도우 내 "새 신원" 이벤트 수, 임계값, 이번 요청에 기존 identity 쿠키가 있었는지.

## "Inspect" vs "Evaluate now" — 왜 나눴는가

- **Inspect (read-only)**: `risk_engine_inspect()`. 카운터를 절대 증가시키지 않는다. 페이지를 그냥 열거나 새로고침해도 이 값은 안 변한다. IP 조회창에 다른 IP를 넣어도 마찬가지로 읽기 전용.
- **Evaluate now**: `risk_engine_evaluate()`를 실제로 호출한다. rate_limit/session_churn 카운터가 **진짜로 증가**한다. 이 버튼을 몇 번 누르면 자기 자신이 실제로 SUSPICIOUS/SEVERE로 넘어가는 걸 관찰할 수 있지만, 그만큼 자기 자신의 카운터를 오염시킨다.

**진단 패널은 override의 영향을 받지 않는다.** 위쪽에서 LOW/MEDIUM/HIGH/CRITICAL 버튼으로 override를 켜도, 진단 패널은 항상 override를 무시하고 real engine이 실제로 무엇을 보고 있는지 보여준다. 이건 버그가 아니라 의도된 분리다 — override가 real risk 데이터를 오염시키지 않는다는 걸(FIX-13) 진단 패널 스스로 증명하는 구조. 직접 확인하려면: override를 HIGH로 설정한 채로 페이지 상단 상태(HIGH/BLOCK)와 진단 패널의 Engine level(대개 NORMAL/LOW, 트래픽이 없었다면)이 서로 다르게 나오는 걸 보면 된다.

## 자기 자신의 테스트 상태를 초기화하는 법

이 문서를 쓰며 테스트하는 동안, 반복적으로 페이지를 새로고침하거나 서로 다른 쿠키 저장소로 여러 번 접속하면 **자기 자신이 실제로 session_churn을 유발**한다 — 매번 새 `__rek_id` 쿠키를 받는 클라이언트는 진짜 신원 남발 패턴과 구분이 안 되기 때문이다(의도된 설계). 로컬에서 개발/테스트하다가 자기 자신이 계속 SUSPICIOUS로 뜨면:

```bash
# adguard/storage/engine/ 안의 저장된 카운터를 전부 지운다 (안전 -- 자동 재생성됨)
rm -rf adguard/storage/engine/*
```

또는 `adguard/engine/tests/` 안의 자체 테스트들은 전부 `sys_get_temp_dir()` 아래 별도 경로를 쓰므로, 실제 `adguard/storage/engine/`를 건드리지 않는다 — 테스트를 아무리 돌려도 이 문제와는 무관하다.

## 로컬에서 특정 등급을 재현하는 방법

**세션 override 사용(권장, 실제 신호와 무관하게 즉시 원하는 등급을 강제):**

`ad-preview.php`의 LOW/MEDIUM/HIGH/CRITICAL 버튼. 세션에만 저장되고(`$_SESSION['ad_defense_preview_override']`), TTL 900초 후 자동 해제. RESET OVERRIDE로 즉시 해제.

**실제 엔진 신호로 재현(진단 패널이 왜 그런 값을 보여주는지 이해하고 싶을 때):**

- `user_agent` SUSPICIOUS 재현: `curl -A "curl/8.4.0" http://localhost/ad-preview.php` — curl의 기본 User-Agent 자체가 자동화 클라이언트 목록에 있음.
- `rate_limit` 재현: 같은 IP에서 설정 파일의 가장 짧은 IP 윈도우 허용치를 넘겨 요청한다. 기본값은 `adguard/engine/src/Config.php`에서 확인한다.
- `visitor_rate` 재현: 같은 `__rek_id` 쿠키를 유지한 채 가장 짧은 방문자 윈도우 허용치를 넘겨 요청한다.
- `session_churn` 재현: 매번 새 쿠키 저장소로 접속을 반복 (`curl -c /tmp/c$i.txt ...` 를 다른 `$i`로 여러 번) — 이게 바로 이 문서 앞부분에서 "자기 자신을 오염시킨다"고 경고한 그 패턴이다. 단, 이 신호는 **호스트 페이지가 쿠키를 설정할 수 있을 때만** 카운트한다(아래 참고).

### session_churn 이 아무것도 안 잡을 때

`cookie_issue_degraded=true` 로 표시되면, 호스트 페이지가 엔진을 호출하기 전에 이미 출력을 시작해서(`headers_sent()`) identity 쿠키를 발급하지 못한 상태다. 이 경우 churn 카운팅은 **의도적으로 비활성화**된다 — 발급하지도 못한 신원을 "새 신원"으로 세면 매 요청마다 카운트가 올라 정상 방문자가 몇 페이지 만에 차단되기 때문이다. 해결하려면 호스트 페이지에서 출력 시작 전에 엔진을 호출하거나 `output_buffering` 을 켠다.

이 재현은 **자기 자신의 로컬/스테이징 환경**에서만 하고, 절대 운영 도메인이나 광고 클릭으로 하지 않는다.

## 런북: "실제 방문자가 광고가 안 보인다고 신고했다"

1. 서버 로그(또는 리버스 프록시 로그)에서 그 방문자의 IP를 찾는다.
2. `ad-preview.php`의 진단 패널 하단 "IP 조회" 입력창에 그 IP를 넣고 "Inspect this IP"를 누른다. (읽기 전용 — 카운터 안 건드림)
3. `reasons[]`를 읽는다. 어느 신호가 왜 걸렸는지 사람이 읽을 수 있는 문장으로 나온다.
4. false positive라고 판단되면 `adguard/config/engine.php`(`.dist`를 복사해서 생성)에서 해당 신호의 `weight`를 낮추거나 `enabled`를 false로 끈다. **`adguard/engine/` 안의 코드 자체는 건드리지 않는다** — 튜닝은 항상 config 레벨에서.
5. `?debug=json`으로 스크립트에서 반복 조회하며 튜닝 전후를 비교할 수 있다:
   ```bash
   curl -s "http://your-domain/ad-preview.php?debug=json&lookup_ip=203.0.113.9"
   ```

## JSON 모드

```
GET /ad-preview.php?debug=json                    -> 현재 요청 IP 조회 (읽기 전용)
GET /ad-preview.php?debug=json&lookup_ip=203.0.113.4   -> 특정 IP 조회 (읽기 전용)
```

`ad-preview.php`와 동일한 접근 제어(선택적 IP allowlist)가 적용된다. 카운터를 증가시키지 않는다.

## 안전 수칙 재확인

- 실제 광고 클릭 금지, 자동화 클릭 금지.
- 이 디버깅 도구로 재현 테스트를 할 때도 반복 새로고침으로 실제 도메인에 트래픽을 만들지 않는다 — 재현은 로컬/스테이징에서만.
- `Evaluate now` 버튼은 실제 카운터를 증가시키므로 운영 도메인에서 습관적으로 누르지 않는다.
