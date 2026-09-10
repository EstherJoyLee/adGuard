# AdSense Risk Viewer 운영자 Cheat Sheet

> `adguard/viewer.php`는 공격자 목록이 아니라 **광고가 있는 응답의 위험 판정 관측 도구**다.  
> 한 IP·한 signal·한 고위험 행만 보고 공격자로 단정하지 않는다.

## 1. 가장 먼저 구분할 것

| Viewer 값 | 정확한 뜻 |
|---|---|
| Ad-bearing responses | 선택한 한국시간 날짜의 **로그된 광고 감지 응답 수**. 전체 사이트 traffic/session 아님 |
| No-loader rate | `No-loader responses / Ad-bearing responses`. DENY 비율이나 session 차단률이 아님 |
| Policy block rate | `정책으로 차단된 loader / loader 제공 기회`. 구현 누락과 구분 |
| MONITOR_DENY | 현재 rule이면 막았을 응답. `monitor`라 실제 광고는 허용 |
| DENY | `enforce`에서 정책상 실제 bootstrap 차단 |
| Loader provided | 최종 HTML에 loader가 있었음. 실제 impression/fill/click을 뜻하지 않음 |
| No loader | loader가 없었음. Guard deny 외에 preview/adapter 억제나 구현 누락도 가능 |
| Unique visitors/IPs | HMAC 가명 식별자 추정치. 확인된 사람 수가 아님 |

상단 Summary는 Level/Action/Path 등 행 필터를 적용해도 **하루 전체 값으로 유지**된다. 필터 결과는 표 위 `matching rows`를 본다.

## 2. 광고 위치·식별자 집계

### 광고 위치별 제공·차단 현황

| 항목 | 의미 |
|---|---|
| 제공 기회 | 해당 응답에 공통 loader 또는 수동 광고 `<ins>`가 존재했던 횟수 |
| 로더 제공 | 최종 HTML에 실행 가능한 공통 loader가 남은 횟수 |
| 정책 차단 | enforce 정책 또는 adapter 연동으로 loader가 나가지 않은 횟수 |
| 구현 누락 | 광고 기회는 표시됐지만 정책 차단 사유 없이 loader가 없었던 횟수. 배포·템플릿 오류 점검 대상 |
| `슬롯ID#1`, `#2` | 한 페이지에서 같은 `data-ad-slot`을 반복할 때 DOM 등장 순서로 나눈 위치 |

수동 광고는 `페이지 경로 + data-ad-slot + 순번`으로 집계한다. Auto Ads 개별 위치는 Google이 브라우저에서 동적으로 만들기 때문에 **AdSense 공통 로더(Auto Ads 포함)** 행으로만 센다. 슬롯 행의 “로더 제공”도 그 광고가 실제 화면에 채워지거나 노출됐다는 뜻이 아니다.

기존 `schema_version=1` 로그에는 슬롯 정보가 없다. 새 코드 배포 뒤 생성된 `schema_version=2` 로그부터 슬롯 표가 채워진다.

### IP·방문자별 광고 가능 응답 빈도

- `응답`: 그 가명 식별자에서 광고가 감지된 서버 응답 수.
- `로더 제공/미제공`: 해당 응답에 실행 가능한 AdSense loader가 남았는지 여부.
- `최대 10분/1시간`: 선택한 한국시간 날짜 안에서 가장 밀집한 rolling 구간의 광고 감지 응답 수.
- `고위험`: SUSPICIOUS/SEVERE 또는 DENY/MONITOR_DENY 응답 수.
- `최고점`: 그 식별자의 당일 최대 risk score.

이 값은 **Google 광고 요청·노출·클릭 횟수가 아니다.** 특히 IP는 CGNAT·회사·학교에서 여러 사람을 합칠 수 있으므로 순위만으로 공격자 판정이나 자동 차단을 하지 않는다. IP와 Visitor 표를 함께 보고 같은 Visitor의 반복, IP 변경, path·UA·signal 조합을 확인한다.

## 3. Level과 Threshold Margin

| Level | Score | 기본 정책 | 읽는 법 |
|---|---:|---|---|
| NORMAL | 0~24 | level 차단 없음 | 23이면 ELEVATED까지 2점 |
| ELEVATED | 25~49 | level 차단 없음 | 25와 49는 위험 margin이 전혀 다름 |
| SUSPICIOUS | 50~74 | deny 대상 | monitor면 실제 차단 아님 |
| SEVERE | 75~100 | deny 대상 | 집중 조사하되 공격자 확정 아님 |

ELEVATED라도 hard signal 때문에 조치될 수 있다. 반드시 `Policy reason`을 함께 본다.

## 4. Signals

| Signal | 실제 조건 | 정상 가능성 |
|---|---|---|
| `user_agent` | header 누락 또는 curl/python/headless/bot marker | proxy header 손실, crawler 가능 |
| `rate_limit` | IP 기준 10초 120 / 300초 1200 초과 | NAT/회사/학교/모바일 공유망 가능 |
| `visitor_rate` | 같은 `__rek_id` 기준 10초 8 / 60초 30 / 600초 120 초과 | F5, 여러 탭, 자동 navigation 가능 |
| `session_churn` | IP 하나에서 600초 새 identities 40 초과 | CGNAT, cookie 삭제/차단 가능 |

행의 `signal=숫자`는 raw score다. combined Score는 weight와 signal 조합 bonus를 적용하므로 단순 합산하지 않는다.

## 5. Shadow와 실제 차단

| Action | Ads served | 해석 |
|---|---|---|
| ALLOW | yes | 정책 허용, loader 제공 |
| MONITOR_DENY | yes | Shadow BLOCK 후보; 실제 광고는 제공 |
| DENY | no | 실제 loader 미제공 |
| ALLOW | no | 외부 adapter/preview 등 다른 억제 원인 확인 |

`removed N`은 Guard가 응답에서 loader를 제거한 수다. `external suppression`은 adapter가 애초에 미출력한 이유다. 최종 회귀 검증은 Browser Network의 `adsbygoogle`, `googlesyndication`, `googleads`, `doubleclick`로 한다.

## 6. 고위험 행 조사 순서

```text
Score와 다음 threshold margin
→ Signals
→ Policy reason
→ Action + Ads served
→ Visitor* 12자로 Identifier 검색
→ 같은 IP*의 다른 Visitors 검색
→ Path / UA / 한국시간 밀집
→ 전일·동요일 baseline
→ 필요 시 SSH Raw reasons
→ 정상 / 의심 / 판단불가
```

Visitor는 PHP session ID가 아니라 first-party `__rek_id` cookie의 HMAC이다. IP와 Visitor는 각각 검색해 공유망인지 같은 browser 반복인지 구분한다.

## 7. 필터 사용

| 상황 | 필터 |
|---|---|
| 고위험 증가 | Date + SUSPICIOUS, 다시 SEVERE + Action |
| Shadow 후보 | Action=MONITOR_DENY |
| 실제 deny | Action=DENY + Ads served=not served |
| 광고 미제공 원인 | Ads served=not served, Action을 ALLOW/DENY로 나눔 |
| 특정 visitor/IP | 표시된 12자를 Identifier prefix에 복사 |
| 특정 페이지 | Path contains + Level/Action/served |

필터는 AND 조건이다. Viewer에는 time range, reason, ASN, request interval 필터가 없다. Date는 `Asia/Seoul` 하루 단위다.

## 8. 매일 볼 것

1. 최신 한국시간 날짜와 로그 증가 여부.
2. Total 및 NORMAL/ELEVATED/SUSPICIOUS/SEVERE 분포.
3. `SUSPICIOUS+ rate = (SUSPICIOUS+SEVERE)/Total`.
4. monitor이면 `MONITOR_DENY/Total`, enforce이면 `DENY/Total`.
5. Viewer Block rate는 no-bootstrap rate로 별도 기록.
6. Degraded.
7. Top reasons / paths without ads / UA families.
8. 고위험 visitor 표본과 같은 IP의 다른 visitors.
9. 전일·동요일 baseline 및 AdSense 수익 추세.

초기에는 “고위험 1%가 정상/비정상” 같은 고정 기준을 만들지 않는다.

## 9. 바로 공격으로 보면 안 되는 것

- VPN IP, 모바일 IP 변경.
- 같은 IP의 여러 Visitors.
- 1회 빠른 탐색, refresh, 여러 탭.
- Chrome/Safari 같은 UA family 반복.
- 특정 Path에 고위험이 집중된 사실 하나.
- 단일 SUSPICIOUS/SEVERE 행.

## 10. Rule/Threshold를 점검할 때

- 재현 가능한 정상 browser가 자주 50 이상.
- 정상 visitor p95/p99 max가 50에 가까움.
- 한 signal이 고위험 대부분을 만듦.
- 여러 정상 Visitors가 같은 IP에서 함께 상승.
- 페이지 변경 후 response 수와 `visitor_rate`가 함께 급증.
- fixed window 만료 뒤에도 예상과 달리 score가 reset되지 않음.

한 번에 하나의 window/weight/threshold만 바꾸고 정책 테스트 후 다시 `monitor`한다.

## 11. Viewer만으로 알 수 없는 것

- 실제 사람 신원과 공격 의도.
- 실제 AdSense 클릭, fill, impression, 수익.
- Google의 invalid-traffic 판정.
- VPN/프록시 뒤 origin IP.
- 광고 없는 전체 사이트 요청과 정확한 session 수.
- ASN/장기 분산 공격/low-and-slow history.

상세 해석과 실제 사례는 `ADSENSE-RISK-ENGINE-GUIDE.md` Part II의 “`adguard/viewer.php` 사용 및 로그 해석 가이드” 장을 따른다.

## 12. AdSense 집계 상관분석

```bash
php adguard/tools/analyze-adsense-risk.php YYYY-MM-DD
```

| 표시 | 운영 해석 |
|---|---|
| `CORRELATED_AGGREGATE_ANOMALY` | CTR과 로컬 risk rate가 함께 급증. 조사 시작점이지 클릭 IP 증거는 아님 |
| `STRONG_LOCAL_BEHAVIOR` | 자동화 UA/강한 단일 Visitor 속도 등 로컬 근거 |
| `ROTATING_IP` | 같은 Visitor HMAC에서 복수 IP 관찰; VPN/이동망도 가능 |
| `SHARED_IP_CAUTION` | 한 IP에 복수 Visitor; NAT/CGNAT 가능 |
| `DISTRIBUTED_BEHAVIOR_PATTERN` | IP/cookie가 달라도 유입그룹·국가·UA·신호 조합이 반복; 조사 후보 |

AdSense 보고서는 클릭별 IP를 주지 않는다. 후보 HMAC는 같은 날짜·사이트·경로그룹에 활동했다는 뜻일 뿐, 광고 클릭자라는 뜻이 아니다.
