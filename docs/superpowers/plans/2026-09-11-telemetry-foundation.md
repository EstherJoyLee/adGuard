# AdGuard v2 Telemetry Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record one bounded schema 4 request event for every active, non-excluded PHP request, independent of AdSense detection, while preserving application output and legacy schema 3 readability.

**Architecture:** `Guard` starts request telemetry and invokes the existing risk provider once at boot, then completes the same event from its final output callback. Focused telemetry value objects build the allowlisted event, `DecisionLogger` coordinates compatibility, and `LocalEventStore` owns bounded non-blocking JSONL I/O plus health outcomes. A shared `EventNormalizer` maps schema 4 to the existing flat read model without changing stored legacy records.

**Tech Stack:** PHP 5.6-compatible PHP, append-only JSONL, existing PowerShell/PHP test harness; no Composer, external network, or central DB.

**Spec:** `docs/adguard/ADGUARD_V2_DESIGN.md` §1.1, §4, §6–9.3, §22, §24; `docs/adguard/ADGUARD_V2_IMPLEMENTATION_PLAN.md` Phase 1; `docs/adguard/IMPLEMENTATION_STATE.md` D1.

## Global Constraints

- Existing project behavior and HTML output remain unchanged.
- PHP 5.6+ syntax compatibility.
- Normal request path performs zero external DNS, HTTP, central DB, or collector calls.
- Telemetry and storage failure are fail-open and never cause an application 500.
- Every event, line, header, query-key list, lock wait, daily file, and health file has a fixed bound.
- Authorization, Cookie/Set-Cookie, password/token values, query values, POST/upload body, and generic header dumps are forbidden.
- Schema 4 is used once per new request; schema 3 files remain byte-for-byte unchanged and readable.
- Missing legacy evidence remains `null`; no fabricated status 200, score 0, verification PASS, or FCrDNS result.
- Existing crawler `verified` never becomes FCrDNS PASS or strict allowlist evidence.
- Phase 2 identity unification, Phase 3 risk policy changes, Phase 4 bot verification, Phase 5 UI, and Phase 6 export are outside scope.

---

### Task 1: Bounded schema 4 model and local store

**Files:**
- Create: `src/Telemetry/RequestTelemetry.php`
- Create: `src/Telemetry/ResponseTelemetry.php`
- Create: `src/Telemetry/TelemetryEvent.php`
- Create: `src/Storage/LocalEventStore.php`
- Modify: `src/Config.php`
- Modify: `config/guard.php`
- Create: `tests/telemetry-components-test.php`
- Create: `docs/adguard/SCHEMA_4_FIELD_MAP.md`

**Interfaces:**
- Produces: `RequestTelemetry(Config $config, $server = null, $cookies = null, $startedAt = null)` with `toArray()` and start-time accessors.
- Produces: `ResponseTelemetry($decision, $meta, $status, $durationMs, $bytes, $guardDurationMs)` with `toArray()`.
- Produces: `TelemetryEvent(RequestTelemetry $request, $projectId, $eventId = null, $requestId = null)` with `complete(ResponseTelemetry $response, $agentVersion, $ruleVersion)` and `toArray()`.
- Produces: `LocalEventStore(Config $config)` with `append($event)` returning `array('written', 'dropped', 'reason', 'duration_ms')` and `getHealthMetrics()`.
- Event fields follow Design §9/§9.3. Phase 2–4 values remain `null`, while legacy crawler evidence is isolated under `bot.legacy`.

- [x] **Step 1: Write component tests that catch unsafe collection, unbounded input, fabricated missing fields, unstable IDs, event overflow, write/open/lock failure, and blocking health writes.**

```php
$request = new RequestTelemetry($config, $serverWithSecretsAndLongHeaders, array('secret_cookie' => 'never-store'), 1000.25);
$event = new TelemetryEvent($request, 'project-x', str_repeat('a', 32), str_repeat('b', 24));
$event->complete(new ResponseTelemetry($decision, $adMeta, null, 12.5, 321, 0.7), '2.0.0', 'legacy-v1');
$record = $event->toArray();
telemetry_assert($failures, 'schema 4 and ids remain stable', $record['schema_version'] === 4 && $event->toArray()['event_id'] === str_repeat('a', 32));
telemetry_assert($failures, 'secrets and query values are absent', strpos(json_encode($record), 'never-store') === false && $record['request']['query_keys'] === array('page'));
telemetry_assert($failures, 'unknown response status remains null', $record['response']['status'] === null);
```

- [x] **Step 2: Run the focused test and confirm RED because the four component classes do not exist.**

Run: `C:/php-8.5.5/php.exe tests/telemetry-components-test.php`
Expected: non-zero exit caused by missing production classes.

- [x] **Step 3: Implement only the bounded value objects, schema contract, configuration defaults, and local append/health behavior required by the test.**

```php
$result = $store->append($event->toArray());
// Result contract is always bounded and non-throwing:
// array('written' => bool, 'dropped' => bool, 'reason' => string, 'duration_ms' => float)
```

- [x] **Step 4: Run the focused test and the PHP 5.6 static checker.**

Run: `C:/php-8.5.5/php.exe tests/telemetry-components-test.php`
Run: `C:/php-8.5.5/php.exe tools/php56-check.php src/Telemetry src/Storage/LocalEventStore.php tests/telemetry-components-test.php`
Expected: both exit 0 with no warnings.

- [x] **Step 5: Commit the task.**

```text
feat: add bounded schema 4 telemetry components
```

---

### Task 2: Request lifecycle and one-event logging

**Files:**
- Modify: `adguard.php`
- Modify: `src/Guard.php`
- Modify: `src/DecisionLogger.php`
- Create: `tests/telemetry-foundation-test.php`
- Create: `tests/fixtures/telemetry-page.php`
- Modify: `tests/phase0-baseline-test.php`
- Modify: `tests/raw-ip-schema-test.php`
- Modify: `tests/adsense-correlation-test.php`

**Interfaces:**
- Consumes Task 1 value objects and `LocalEventStore::append()` result.
- Produces: `DecisionLogger::beginRequest()` returning one request-scoped `TelemetryEvent` and `DecisionLogger::completeRequest($event, $decision, $meta, $status, $durationMs, $bytes, $guardDurationMs)`.
- `Guard::start()` captures telemetry before invoking the provider exactly once; `Guard::filterOutput()` completes logging once after final HTML processing.
- Existing `DecisionLogger::log($decision, $meta)` remains a bounded compatibility entry point but writes schema 4.

- [x] **Step 1: Write lifecycle/failure tests that catch no-ad omission, duplicate provider/counter evaluation, duplicate events, changed HTML, missing response metrics, and storage failures escaping into HTTP 500.**

```php
$guard->start();
echo '<!doctype html><p>no ads</p>';
ob_end_flush();
$records = telemetry_read_request_records($logPath);
telemetry_assert($failures, 'no-ad request records exactly once', count($records) === 1 && $providerCalls === 1);
telemetry_assert($failures, 'original body is unchanged', $capturedBody === '<!doctype html><p>no ads</p>');
```

- [x] **Step 2: Run the focused test and confirm RED because boot does not log no-ad requests and schema 4 coordination is absent.**

Run: `C:/php-8.5.5/php.exe tests/telemetry-foundation-test.php`
Expected: non-zero exit on the no-ad/schema assertions, with the fixture process still returning its original body.

- [x] **Step 3: Integrate start/final telemetry with minimal Guard and DecisionLogger changes; preserve detector and ad enforcement output behavior.**

```php
$this->telemetryEvent = $this->logger->beginRequest();
$decision = $this->getDecision(); // one mutating provider evaluation at request start
// At FINAL: process HTML, capture response, complete this same event once.
```

- [x] **Step 4: Run focused lifecycle and existing advertising regression tests.**

Run: `C:/php-8.5.5/php.exe tests/telemetry-foundation-test.php`
Run: `C:/php-8.5.5/php.exe tests/ad-guard-test.php`
Run: `C:/php-8.5.5/php.exe tests/auto-prepend-integration-test.php`
Run: `C:/php-8.5.5/php.exe tests/raw-ip-schema-test.php`
Run: `C:/php-8.5.5/php.exe tests/adsense-correlation-test.php`
Expected: all exit 0; no-ad and ad responses are byte-identical to fixtures.

- [x] **Step 5: Commit the task.**

```text
feat: record telemetry for every active request
```

---

### Task 3: Shared schema 3/4 read compatibility

**Files:**
- Create: `src/Telemetry/EventNormalizer.php`
- Modify: `src/LogReader.php`
- Modify: `src/RiskCorrelationAnalyzer.php`
- Modify: `tools/report.php`
- Create: `tests/schema-compatibility-test.php`
- Modify: `tests/viewer-date-range-test.php`
- Modify: `tests/behavior-analysis-test.php`

**Interfaces:**
- Produces: `EventNormalizer::normalize($record, &$reason)` returning the established flat read model for schema 3, schema 4, and supported versionless legacy records; returns `null` for health, malformed-shape, or unsupported records with a bounded reason code.
- Consumers count skipped health/malformed/unsupported records separately and never include them in request totals.
- Conversion adds source provenance but never rewrites source JSONL or upgrades legacy bot verification semantics.

- [x] **Step 1: Write mixed-log tests with literal schema 3, schema 4, versionless, health, malformed, unsupported, null-evidence, legacy verified, and advertising records.**

```php
$result = $reader->read(array('date_mode' => 'all'), 1, 100);
compat_assert($failures, 'only supported request records are counted once', $result['total'] === 3);
compat_assert($failures, 'read diagnostics separate record types', $result['read_health']['health'] === 1 && $result['read_health']['unsupported'] === 1);
compat_assert($failures, 'legacy verified is not FCrDNS pass', $schema3Row['fcrdns_status'] === null);
```

- [x] **Step 2: Run the focused test and confirm RED because readers consume only the flat layout and do not dispatch schemas.**

Run: `C:/php-8.5.5/php.exe tests/schema-compatibility-test.php`
Expected: non-zero exit because schema 4 is skipped or misread and diagnostics are absent.

- [x] **Step 3: Add the shared normalizer and connect only the existing reader/analyzer/report paths needed for schema 4 parity.**

```php
$reason = '';
$normalized = EventNormalizer::normalize($record, $reason);
if ($normalized === null) {
    // Increment one bounded diagnostic counter and continue.
    continue;
}
```

- [x] **Step 4: Run compatibility tests plus all existing reader/analyzer tests.**

Run: `C:/php-8.5.5/php.exe tests/schema-compatibility-test.php`
Run: `C:/php-8.5.5/php.exe tests/viewer-date-range-test.php`
Run: `C:/php-8.5.5/php.exe tests/viewer-timezone-test.php`
Run: `C:/php-8.5.5/php.exe tests/viewer-actor-cap-test.php`
Run: `C:/php-8.5.5/php.exe tests/behavior-analysis-test.php`
Run: `C:/php-8.5.5/php.exe tests/adsense-correlation-test.php`
Expected: all exit 0 with exact totals and no schema-based omissions or duplicates.

- [x] **Step 5: Run full verification, benchmark relevant Phase 1 paths, update state, and commit.**

Run: `./tools/run-phase0-checks.ps1 -OutputPath docs/adguard/phase1/checks.json -Php C:/php-8.5.5/php.exe`
Run: `./tools/phase0-benchmark.ps1 -OutputPath docs/adguard/phase1/http-benchmark.json -Php C:/php-8.5.5/php.exe`
Run: `git diff --check`
Expected: 0 failures; one documented host-adapter skip is allowed; benchmark produces four scenario distributions and failure probes.

```text
feat: read legacy and schema 4 telemetry together
```
