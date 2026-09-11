# AdGuard v2 Schema 4 Field Map

This contract implements Design §9.3 decision D1. New request records use schema 4; existing schema 3 JSONL is never rewritten. All string/list sizes below are hard upper bounds and all absent Phase 2–4 evidence remains `null`.

| Schema 4 field | Type / bound | Source | Legacy schema 3 mapping |
|---|---|---|---|
| `schema_version` | integer, fixed `4` | agent | `schema_version` remains `3` in legacy files |
| `event_type` | string, fixed `request` | agent | inferred only for supported request records |
| `event_id` | string, max 128 bytes | generated once at request start | absent → `null` when reading legacy |
| `project_id` | string, 64 bytes | analytics site ID / validated host | `site_id` |
| `request_id` | string, max 128 bytes | generated once at request start | `request_id` |
| `occurred_at_utc` | UTC string with milliseconds | server clock at guard start | `timestamp` |
| `request.method` | string, 12 bytes | `REQUEST_METHOD` | `method` |
| `request.host` | string, 253 bytes | validated host | `host` |
| `request.path` | string, 512 bytes | URI path only | `path` |
| `request.protocol` | string, 24 bytes | `SERVER_PROTOCOL` | absent → `null` |
| `request.query_keys` | unique list, 32 × 64 bytes | query names only | absent → `null` |
| `request.route_group` | string, 80 bytes | AnalyticsContext / explicit server label | `route_group` |
| `request.redirect_rule_id` | string, 80 bytes | explicit server label | `redirect_rule_id` |
| `request.request_type` / `is_document` | bounded enum / boolean | path classification | same names |
| `request.referrer_host` / `referrer_group` | 255 / 80 bytes | parsed Referer / AnalyticsContext | same names |
| `request.country` / `cf_ray` | 8 / 64 bytes | existing Cloudflare metadata | same names |
| `network.peer_ip` | string, 128 bytes | `REMOTE_ADDR` | absent → `null` |
| `network.client_ip` | string, 128 bytes | current guard-side IpResolver | `ip_canonical` |
| `network.raw_ip` | string, 128 bytes | current selected raw IP | `raw_ip` |
| `network.ip_source` / `resolution_status` | 32 / 48 bytes | current guard-side IpResolver | `ip_source` / `ip_resolution_status` |
| `network.proxy_trusted` / `forwarded_chain` | nullable boolean / nullable list | Phase 2 | absent → `null` |
| `network.ip_hmac` / `network_hmac` / `visitor_hmac` | SHA-256 HMAC or empty | configured/local HMAC key | same names |
| `headers.*` | fixed allowlist, each ≤ `max_header_bytes` (64–4096) | named server headers | UA maps from `user_agent`; others absent → `null` |
| `behavior.*` | nullable bounded number | Phase 3 | absent → `null` |
| `bot.claimed_identity` through `rfc9421` | nullable | Phase 4 | absent → `null` |
| `bot.legacy.*` | nullable strings, 40 bytes | existing user-agent signal metadata | `crawler_status/vendor/group`; never promoted to FCrDNS PASS |
| `risk.score` | nullable integer 0–100 | existing decision | `score` |
| `risk.level` / `action` | nullable strings, 24 bytes | existing decision, original v1 meaning | `engine_level` / `action` |
| `risk.policy_reason` | nullable string, 128 bytes | existing decision | `policy_reason` |
| `risk.reasons` | list, 8 × 256 bytes | existing decision | `reasons` |
| `risk.signals` | map, 16 signals; allowlisted metrics only | existing decision | `signals` |
| `risk.ads_allowed` / `degraded` | nullable booleans | existing decision | same names |
| `response.status` | nullable integer 100–599 | final PHP response status | absent → `null`, never assumed 200 |
| `response.duration_ms` | nullable float 0–86,400,000 | guard start to final response callback | absent → `null` |
| `response.bytes` | nullable integer 0–2,147,483,647 | final emitted body length | absent → `null` |
| `advertising.*` | bounded object; at most 24 manual units | Guard detector/policy result | flat ad fields and `ad_delivery` |
| `agent.version` / `rule_version` | strings, 40 bytes | local configuration | absent → `null` |
| `agent.guard_duration_ms` | nullable bounded float | measured AdGuard work only | absent → `null` |
| `agent.telemetry_write_duration_ms` | `null` in the request line | measured by LocalEventStore result/health event | absent → `null` |
| `agent.dropped_event_count` / `storage_error_count` | `null` in the request line | LocalEventStore request health result | absent → `null` |
| `agent.state_error_count` | nullable | later bounded state integration | absent → `null` |

Security notes: request query values are never decoded into the event; Referer is reduced to scheme/host/port/path; Origin is reduced to scheme/host/port. Authorization, Cookie/Set-Cookie, password/token fields, bodies, uploads, and unlisted headers have no schema location. Unknown schema versions and health records are not request events.
