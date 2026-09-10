> **이 폴더는 `adguard/` 패키지의 일부입니다.**
> 설치·설정·운영은 상위 `adguard/README.md`를 보세요.
> 설정 파일은 `adguard/config/engine.php`, 저장소는 `adguard/storage/engine/` 입니다.
> 아래 내용은 이 엔진 자체의 설계 문서이며, 광고와 무관한 다른 용도로
> 재사용할 때 참고합니다.

# risk-engine

A portable, framework-agnostic PHP request/session risk-scoring module.

## What this is

`risk-engine/` answers one question: **how suspicious does this request/session look?** It knows nothing about your site, your ad network, your login form, or any specific attack you're worried about. You feed it nothing but the current HTTP request; it hands back a verdict. What you do with that verdict is entirely up to you.

## Install

Copy this whole `risk-engine/` folder into your PHP project. That's it — no Composer, no database setup, no external account, no build step. Requires PHP 5.6 or newer.

## Use

```php
require_once __DIR__ . '/risk-engine/risk-engine.php';

$verdict = risk_engine_evaluate();

if ($verdict['level'] === 'SUSPICIOUS' || $verdict['level'] === 'SEVERE') {
    // Do whatever your site needs to do here -- block a script load,
    // throttle a form, log it, whatever. The engine does not know or care.
}
```

`$verdict` is always a plain array:

```php
array(
    'level'   => 'NORMAL' | 'ELEVATED' | 'SUSPICIOUS' | 'SEVERE',
    'score'   => 0,   // 0-100
    'reasons' => array('user_agent: User-Agent matches known automation client ("curl")', ...),
    'signals' => array(
        'user_agent' => array('score' => 40, 'triggered' => true, 'reason' => '...', 'metrics' => array(...)),
        // ...
    ),
)
```

Call `risk_engine_evaluate()` once per real request you want the engine to observe — it may increment internal counters (rate-limit windows, session-churn counters). For read-only inspection that never mutates anything (e.g. an admin debug panel), call `risk_engine_inspect()` instead:

```php
$snapshot = risk_engine_inspect();                             // inspect current request, read-only
$snapshot = risk_engine_inspect(array('ip' => '203.0.113.9'));  // inspect an arbitrary IP instead
```

## Why an engine-owned vocabulary

The verdict uses `NORMAL/ELEVATED/SUSPICIOUS/SEVERE`, deliberately not whatever terms your own site uses (e.g. `LOW/MEDIUM/HIGH/CRITICAL`). This keeps the translation between "what the engine says" and "what your site does about it" explicit in your own integration code, instead of the two accidentally becoming coupled by sharing enum values.

## What it checks

- **User-Agent / header anomalies** (stateless, v1): missing or automation-tool User-Agent, missing Accept/Accept-Language/Accept-Encoding.
- **Request rate per IP**: short-burst and sustained-rate windows.
- **Request rate per persistent visitor**: tighter windows that do not punish every person behind one shared carrier/office IP.
- **Session/identity churn per IP**: how often a "new" client identity shows up from the same IP in a short window, via the engine's own first-party cookie — not your site's PHP session (see below).

Deliberately **not** included: IP-type/datacenter classification, VPN/proxy detection. Both would require external accounts, paid APIs, or bundled/regularly-updated data files, which breaks "copy the folder and it just works."

## Configuration (optional)

The engine works with zero configuration. In the AdGuard package, copy
`adguard/config/engine.php.example` to `adguard/config/engine.php` and edit
only what you need — see `src/Config.php` for every default value.

`identity.trusted_proxies` is empty by default, so the engine uses
`REMOTE_ADDR` and ignores forwarded headers. Add only known proxy IPs/CIDRs;
the resolver then selects the first valid untrusted hop from the right of
`X-Forwarded-For` and keeps a canonical IPv4/IPv6 identity for buckets.

## Storage

State is stored as small JSON files under `risk-engine/storage/`, created automatically on first use. No database, no Redis, no cron job. If your host allows it, moving this folder outside your web root (via the `storage.path` config override) is more reliable defense-in-depth than an in-place access guard alone.

If you need a real datastore (Redis, a database) for high traffic, implement `RiskEngine\Storage\StorageInterface` and pass your own instance in — see `src/Storage/FileStorage.php` for the contract. No signal code needs to change either way.

## Why an engine-owned cookie for session-churn

A genuinely portable module can't assume the host site calls `session_start()`, or that it never regenerates the session ID for unrelated reasons (e.g. login). So this signal (when wired in) issues its own small, first-party, `HttpOnly` cookie and tracks how often a "new" one shows up per IP — independent of whatever the host does with `$_SESSION`. Toggle it off in config if you don't want any cookie written.

## Reliability

No signal, no storage call, and no internal engine error can crash the calling page. Any internal failure degrades the affected signal to a neutral (zero-score) result with a `reason` or `metrics.storage_degraded` marker describing the failure — `risk_engine_evaluate()`/`risk_engine_inspect()` always return a well-formed verdict array, never throw. An integrating policy can therefore remain fail-open for ordinary content while choosing to fail closed for a sensitive downstream action such as advertising.

## Compatibility

Written to run on PHP 5.6+. No PHP 7/8-only syntax or functions.

## Testing this module itself

```bash
php risk-engine/tests/boundary-check.php          # verifies zero site-specific knowledge
php risk-engine/tests/user-agent-signal-test.php  # per-signal self-test
```
