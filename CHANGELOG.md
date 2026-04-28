# Changelog

## v0.7.2 — config inline doc for `only` whitelist

Pure-doc patch. The `'only'` whitelist feature has been around since v0.1, but customers asked "where do I configure path whitelisting?" enough times that it became clear `config/smking.php` wasn't surfacing it well — the published config had a 4-line stub with two commented examples and no explanation, so customers reading it cold either missed the feature or had to dig into the package README to understand what the array meant.

v0.7.2 rewrites the `only` block's inline comments to match the existing `except` block's tighter style — short `//` line comments, one paragraph for "what it does", one paragraph for "when you'd use it", no Phpdoc-style header bloat. Customer publishing config and reading it cold now gets the feature in ~5 seconds without leaving the file. Worked examples (soft-launch, A/B comparison, full rollout) stay in README §"Gradual rollout / A/B comparison" — config doc points there for depth.

Also adds a closing line to the SaaS-side install agent prompt mentioning the whitelist option after the verification checklist passes — a one-sentence heads-up, not a tutorial. Agents are explicitly told not to walk customers through it unless asked.

No SDK code or test changes. Customers running `^0.7` who already published config get the new comments next time they re-publish (or by hand-editing).

## v0.7.1 — circuit breaker observability

Operators reported (rightly) that v0.7.0's circuit breaker was silently effective: when AEO content stopped appearing in production, there was no signal whether the SDK was short-circuiting or whether the upstream was returning empty responses. The only "tool" was `cache:purge`, which has the side-effect of resetting the breaker — so just *checking* state forced you to also reset it. Bad ergonomics.

v0.7.1 adds three zero-side-effect observability paths covering the full breaker lifecycle.

### feat: trip log at `warning` level (rate-limited per outage)

When the breaker trips, `AeoClient::tripCircuit()` now emits one `warning` log via the configured `LoggerInterface`:

```
[warning] smking: circuit breaker tripped for aeo surface
  context: {"surface":"aeo","ttl_seconds":60,"key":"smking:circuit:aeo:abc123"}
```

The log is rate-limited at the source: only the *first* trip of an outage window logs. Subsequent failures while the breaker is already open re-`put()` the cache flag (extending TTL) but do **not** re-log. A million-request outage produces one log line, not a million.

### feat: close log at `info` level (half-open recovery)

When the breaker auto-recovers and the next upstream call succeeds, the SDK emits a matching close log:

```
[info] smking: circuit closed for aeo surface
  context: {"surface":"aeo"}
```

Mechanism: every trip plants a *tombstone* cache key (TTL = 5× breaker TTL, default 300s) alongside the breaker key. After breaker TTL expiry, the next ready upstream response atomically `pull()`s the tombstone — the winner of that pull (across concurrent recovery requests) emits the close log; losers see `null` and skip. At-most-once per recovery cycle, no extra hot-path overhead — only successful upstream calls trigger the check.

Edge: if an outage extends past 5× breaker TTL without a recovery request arriving (extreme low-traffic site), the tombstone expires silently and that recovery doesn't log. The next trip (when traffic resumes) refreshes the tombstone, so this only loses one logical event in a degenerate scenario.

### feat: `php artisan smking:circuit:status` — read-only state inspector

```bash
php artisan smking:circuit:status
```

Reports per-surface state without resetting anything. Output includes the underlying cache key so ops can spot-check the store directly (`redis-cli get smking:circuit:aeo:...`) when triaging unexpected behavior.

The configured TTL is shown for reference only — Laravel's Cache contract has no portable `ttl($key)` method, and the breaker auto-resets to full TTL on every re-trip during a continuing outage, so a printed "remaining" countdown would be misleading. Re-run the command in a few seconds to confirm whether the breaker actually cleared.

Exit code is script-friendly: `0` if all surfaces closed (or breaker disabled by config), `1` if any surface open. Drop into a healthcheck:

```bash
php artisan smking:circuit:status > /dev/null || alert "smking AEO degraded"
```

### What this does NOT do (intentional)

- **No new event class** (`CircuitTripped` etc.). The log line *is* the event surface — every metrics tool already speaks log scraping. Introducing a Laravel event would mean another DI dependency and an extension point we can't easily remove later. Skipped on YAGNI grounds.
- **No `--reset-circuit` flag** on `cache:purge`. Codex round-4's adversarial review proposed this to "separate eviction from breaker reset"; we've explicitly chosen the opposite default — purge means "retry now" which includes outage protection. Operators who want to peek without resetting now have `circuit:status`.

### Tests added (11 new)

- `test_trip_circuit_logs_warning_on_first_trip` — log fires with correct surface + TTL context
- `test_trip_circuit_only_logs_once_per_outage_window` — re-trip rate limit
- `test_trip_circuit_does_not_log_when_breaker_disabled` — opt-out is silent
- `test_circuit_close_logs_after_half_open_recovery` — recovery info log fires when tombstone is alive
- `test_circuit_close_log_only_fires_once_per_recovery` — atomic tombstone pull
- `test_circuit_close_log_does_not_fire_when_no_prior_trip` — no spam on healthy upstream
- `test_status_shows_closed_state_when_no_breakers_tripped`
- `test_status_shows_open_state_after_aeo_breaker_trips`
- `test_status_returns_failure_when_any_surface_open`
- `test_status_shows_disabled_when_breaker_off`
- `test_status_includes_cache_key_for_ops_debugging`

130 tests total (was 119).

### Internal

- `AeoClient::tripCircuit()` — adds `$alreadyOpen` check + tombstone write + `$this->logger?->warning()` call. Public API unchanged.
- `AeoClient::maybeLogCircuitClosed()` — new private helper, atomic tombstone pull → info log. Called from singleFlight writer callback on `STATUS_READY` (HTML AEO) and from `singleFlightMarkdown` writer callback on body present (markdown).
- `AeoClient::circuitTombstoneKey()` — new private helper, derives `{circuit_key}:tombstone` so cache-purge / namespace rotation reaches it the same way as the breaker key.
- `Smking\Laravel\Console\CircuitStatusCommand` — new, registered in `SmkingServiceProvider::boot()`.

## v0.7.0 — pre-release adversarial review fixes (round 4)

Fourth adversarial review (codex, requested against the explicit recommendation to stop at round 3) caught three real issues that the round-3 cuts didn't cover: cross-surface coupling, recovery-flow false advertising, and a first-launch regression on the new miss TTL default. All folded into v0.7.0 before tagging.

### [high] Per-surface circuit breaker — markdown failure no longer suppresses HTML AEO

The round-3 breaker was a single `smking:circuit:{ns}` flag shared by `forPath()` (HTML AEO injection, every page render) and `getMarkdown()` (agent-only optional surface for `Accept: text/markdown` clients). A markdown 5xx would short-circuit HTML injection for the full breaker TTL — wrong trust boundary: an outage on an optional agent endpoint should not affect the customer-facing HTML path.

Round-4 splits the breaker key per upstream surface:

- HTML AEO uses `smking:circuit:aeo:{ns}`
- Markdown uses `smking:circuit:md:{ns}`

Each surface trips and recovers independently. Both still rotate with `(api_key, base_url)` and respect the same `SMKING_CIRCUIT_BREAKER` / `SMKING_CIRCUIT_BREAKER_TTL` knobs.

### [medium] `smking:cache:purge` now actually forces a retry while the breaker is open

The round-3 docstring told operators that `cache:purge` is the recovery path after an outage and that "next request re-fetches". In reality the command only forgot per-path AEO + markdown entries — it never touched the namespace breaker, so a purge issued during the breaker TTL window left subsequent requests short-circuiting `server_error` until the breaker expired.

Round-4 makes purge clear the per-surface breaker keys alongside the path/product-id entries:

- `cache:purge <path>` clears `circuit_aeo` + `circuit_md` (path may be hit by either surface next).
- `cache:purge --product-id=N` clears `circuit_aeo` (product_id never flows through markdown).

Auto-recovery still rate-limits via the breaker; purge is the explicit manual override that says "retry now".

### [medium] Restored short `not_found_ttl` default — first-launch products become visible within ~1 minute

Round-1..3 raised the `not_found_ttl` default from 30s to 900s (15 minutes) to absorb worker-pool stampede on million-PV sites. But `forPath()` uses POST specifically to register unseen paths for background crawling — the typical lifecycle is "first request → register → ready in 1-2 minutes". A 900s default cached the first miss for 15 minutes, masking the ready transition; the SDK kept returning `no content` long after the crawl/generate job finished, breaking fresh product launches.

Round-4 restores the default to 60s. Stampede protection is already covered by:

- `pending_ttl` (15s) when the SaaS sends an explicit 202 in-progress signal,
- `circuit_breaker` (60s) for namespace-wide outage short-circuiting.

`not_found` is a 4xx — the SaaS explicitly says "no content for this path", not an outage signal. 60s gives 1-minute recovery on first-launch products and still caps worker stampede to one upstream call per minute per cold path. Customers who want a longer cushion can still set `SMKING_NOT_FOUND_TTL` higher.

### Tests added (5 new in this round)

- `test_markdown_failure_does_not_trip_html_aeo_circuit` — proves surface isolation forward direction
- `test_html_aeo_failure_does_not_trip_markdown_circuit` — proves surface isolation reverse direction
- `test_circuit_breaker_keys_are_isolated_per_surface_in_cache` — direct cache-key assertion that AEO failures only set the AEO breaker
- `test_purge_by_path_clears_both_surface_circuit_keys` — purge actually unblocks recovery
- `test_purge_by_product_id_clears_aeo_circuit_key` — product-id purge clears the relevant breaker

119 tests total (was 114).

### Internal

- `AeoClient::circuitKey()` / `circuitOpen()` / `tripCircuit()` now take a `'aeo' | 'md'` surface argument.
- `AeoClient::cacheKeyPrefixes()` returns `circuit_aeo` and `circuit_md` keys for the cache-purge command.
- `CachePurgeCommand` output now includes a `circuit → cleared` line so the operator sees the breaker state was reset.

## v0.7.0 — pre-release adversarial review fixes (round 3)

Third adversarial review caught the missing pieces between per-path protection and namespace-wide protection. All folded into v0.7.0 before tagging.

### [high] Namespace-wide circuit breaker — protects against high-cardinality outages

Per-path 24hr `server_error` cache only protects keys we've already failed. A high-cardinality outage (catalog spray, full-site crawler, sitemap fetch when SaaS is down) would still consume one full timeout per distinct URL — the second URL in the burst doesn't know the first one just failed.

v0.7.0 round-3 adds a namespace-wide circuit breaker keyed by `(api_key, base_url)`:

- Any path's first 5xx / transport failure trips a `smking:circuit:*` cache flag (default 60s TTL).
- While the flag is present, ALL `forPath()` / `getMarkdown()` calls short-circuit with `server_error` WITHOUT touching the upstream.
- Auto half-open: when the flag expires, the next request hits upstream — success keeps the breaker closed; another failure trips it again.

Two new env knobs (default sensible):

```dotenv
SMKING_CIRCUIT_BREAKER=true       # set false to disable
SMKING_CIRCUIT_BREAKER_TTL=60     # seconds
```

Combined with per-path `server_error` cache and single-flight: under a million-PV outage scenario, **only the first request to any path** in a 60-second window touches the upstream. Per-path cache then protects already-failed paths for 24 hours.

### [high] Cache `pending` status (default 15s) — kills hot-URL polling

`pending` (202 from SaaS, backlog still crawling) was previously not cached at all — single-flight only suppressed concurrent overlap, but as soon as one request returned `pending` the next request immediately tried upstream. A newly launched URL with even modest traffic could generate hundreds of redundant calls per minute against the crawler queue.

v0.7.0 round-3 adds `cache.pending_ttl` (default 15s, configurable via `SMKING_PENDING_TTL`). Pending now joins the four-tier TTL match alongside ready / not_found / server_error.

### [medium] `cache:purge --product-id=N` — recovery for WC product surface

`forProductId()` (used by `Smking::forProductId()` facade and the legacy WC flow) caches under `product_id=N` keys, completely separate from `path=...` keys. Pre-round-3 the only way to invalidate those entries was `php artisan cache:clear` — too large a blast radius.

```bash
# Path-based recovery (existing)
php artisan smking:cache:purge /products/widget

# Product-id recovery (new in round-3)
php artisan smking:cache:purge --product-id=42
```

Mutually exclusive with `<path>` argument; rejects zero / non-positive IDs.

### Tests added (5 new in this round, 1 obsolete removed)

- `test_circuit_breaker_trips_after_server_error_and_short_circuits_other_paths` — proves cross-path namespace protection
- `test_circuit_breaker_can_be_disabled` — opt-out works
- `test_pending_response_caches_for_short_window` — pending now hits cache on second call
- `test_purge_by_product_id_clears_correct_cache_key`
- `test_purge_rejects_both_path_and_product_id`
- `test_purge_rejects_zero_product_id`
- (removed obsolete `test_pending_status_does_not_cache` — behavior reversed)

114 tests total (was 109).

### Internal

- `AeoClient::circuitOpen()` / `tripCircuit()` / `circuitKey()` — three new private helpers
- `CachePurgeCommand::purgeByPath()` / `purgeByProductId()` — split handler

## v0.7.0 — pre-release adversarial review fixes (round 2)

A second adversarial review (codex) caught three real defects in the round-1 fixes. Folded into v0.7.0 before tagging.

### [critical] Single-flight lock detection was inert

`supportsLock` previously used `method_exists($repository, 'lock')`. Laravel's `Repository` doesn't declare `lock()` — it forwards via `__call` to the underlying store. Result: the check was always false and `singleFlight()` / `singleFlightMarkdown()` silently fell back to plain fetch+write on EVERY driver, including redis/memcached/database/array — exactly the production drivers the protection was supposed to cover.

Fix: detect via `$repository->getStore() instanceof Illuminate\Contracts\Cache\LockProvider`. Verified with regression test using `ArrayStore` (which IS a `LockProvider`) — pre-acquire the lock, then assert `forPath()` returns `notFound` without sending any HTTP request, proving the contention path actually fires.

### [high] `smking:cache:purge` could miss the real cache key

Middleware canonicalizes paths (strips trailing slashes from non-root URLs) before writing cache. The purge command used the raw CLI argument. So an operator running `smking:cache:purge /products/widget/` during an outage would get a "success" message but leave the actual `/products/widget` cache entry stuck for the full 24hr `server_error` TTL.

Fix: extracted `Smking\Laravel\Support\PathNormalizer::canonical()` static helper. Both middleware and purge command now share it. Purge command also surfaces canonicalized path in output when input differed. Regression test: prime cache via `forPath('/products/widget')`, run `cache:purge /products/widget/` (trailing slash), assert canonical entry is gone.

### [medium] `admin*` regression in default `except`

Removing `admin*` from `EXCEPT_PATTERNS` (round-1 fix) was an over-correction. `admin*` is a strong Laravel convention (>90% of installs use exactly that path; Laravel docs and tutorials use it as the canonical example). Unlike business URLs (`/cart` vs `/購物車`), admin path is essentially a framework norm. Removing it for v0.7.0 default would silently expand middleware blast radius into authenticated staff surfaces for every install relying on defaults.

Fix: restored `admin*` in `Defaults::EXCEPT_PATTERNS`. `cart`/`checkout`/`account`/`login`/etc. (truly business-specific) stay in `SUGGESTED_BUSINESS_EXCEPT` opt-in template.

### feat: gradual rollout / A/B documentation

Added README "Gradual rollout / A/B comparison" section walking through `config('smking.only')` whitelist patterns — soft-launch one URL, expand to a section, run an A/B over 2-3 paths against a control. The feature already existed (`only` is checked before `except` in `shouldInject()`), but wasn't documented as a rollout tool.

### Tests added (12 new in this round)

- `test_lock_acquired_on_arraystore_lockprovider` — proves single-flight contention path actually fires
- `test_default_except_keeps_admin_convention` — regression
- `test_purge_canonicalizes_trailing_slash_to_match_middleware` — outage-recovery reliability
- `Tests\Support\PathNormalizerTest` — 9 data-provider cases covering canonical edge cases

109 tests total (was 97).

### Internal

- `AeoClient::supportsLock()` — new private helper using `LockProvider` instanceof
- `Smking\Laravel\Support\PathNormalizer` — new shared canonicalizer

## v0.7.0

**Major outage hardening + behavior changes**. Customers must bump composer constraint `^0.6` → `^0.7`.

### feat(concurrency): single-flight cache lock — million-PV thundering-herd protection

Adversarial review (codex) caught the missing piece: under a cold key + concurrent requests, every PHP-FPM worker would otherwise enter the upstream call simultaneously before any cache write lands — the 24hr `server_error` TTL only takes effect AFTER a write, so the first wave still saturates the worker pool.

v0.7.0 wraps both `remember()` (forPath) and `rememberMarkdown()` with a cache lock (`Cache::lock()`):

- One worker acquires the lock, calls upstream, writes cache, releases.
- Concurrent workers find lock held, fail open immediately — `forPath` returns `notFound`, `getMarkdown` returns `null`. They DO NOT block on the upstream call.
- After the lock-holder writes cache, all subsequent requests hit cache directly (no lock needed).

Lock TTL: `connect_timeout + read_timeout + 2s` slack (default ~5s) — short enough that a crashed worker doesn't permanently block the key. Falls back to plain fetch+write if the cache driver lacks lock support (regression-safe).

Production behavior: instead of N concurrent workers each holding a worker for 1.5s on a cold path, ONE worker holds for 1.5s and the rest fail open in microseconds. **Real fix for million-PV cold-start** — not just steady-state.

### feat(outage): three-tier cache TTL — million-PV protection

`AeoClient` now distinguishes three negative-cache outcomes:

| Status | TTL | When |
|---|---|---|
| `ready` | full ttl (default 1hr, unchanged) | Successful response with content |
| `not_found` | **15 min** (was 30s) | 4xx — SaaS rejected the request (bad key, unaudited path) |
| `server_error` (new) | **24 hr** | 5xx, DNS failure, TCP refused, read timeout — SaaS unreachable |

The 24hr `server_error` TTL is the headline change: a hung upstream on a million-PV-per-day site can saturate the PHP-FPM worker pool in seconds when every cache miss holds a worker for `timeout` seconds. Caching the failure for 24 hours means each path retries at most once per day — outage becomes invisible to traffic after the first wave fails over.

Customer recovery: `php artisan smking:cache:purge <path>` forgets the cached failure. See **Outage Runbook** in README.

`AeoResponse::STATUS_SERVER_ERROR` + `AeoResponse::serverError()` factory added. `isReady()` returns `false` for both `not_found` and `server_error` (middleware behavior unchanged — both result in zero injection).

### feat(timeout): connect/read split — `connect_timeout` 1s, `timeout` 1.5s

Single `timeout=3s` was too generous for high-traffic sites:

- 50-worker PHP-FPM pool × 3s timeout → saturation point ~17 RPS
- 50-worker × (1s connect + 1.5s read) → saturation point ~20 RPS

Split exposed via two config keys + env vars:

```dotenv
SMKING_CONNECT_TIMEOUT=1.0
SMKING_HTTP_TIMEOUT=1.5
```

Million-PV sites can drop further (`SMKING_HTTP_TIMEOUT=1`, `SMKING_CONNECT_TIMEOUT=0.5`). Combined with the 24hr `server_error` cache, a single timeout barely matters — first request fails fast, then 24hr cache absorbs everything.

### feat(except): two-layer Defaults — technical-only by default, business routes opt-in

Adversarial review pushed back on a design mistake: shipping `cart`, `checkout`, `account`, `login`, etc. as defaults assumes customer URL conventions the SDK has no way to know. Different sites use `/cart` vs `/購物車` vs `/shopping-bag`; `/login` vs `/sign-in` vs `/auth/login`. SDK can't make those calls for the customer.

v0.7.0 ships **two** const arrays:

- `Defaults::EXCEPT_PATTERNS` — **technical-only**, used by the published config:
  - Laravel API conventions (`api/*`, `v1/*`, `oauth/*`, `webhooks/*`, …)
  - Realtime / SPA endpoints (`livewire/*`)
  - HTTP health check standards (`up`, `health`, `healthz`, `ping`)
  - Dev tooling (`telescope*`, `horizon*`, `_debugbar*`, `_ignition*`)
  - Admin packages (`nova*`, `filament*`)
  - **Removed from the default**: `admin*` (customer-specific path), all
    cart/checkout/account/login/etc. patterns

- `Defaults::SUGGESTED_BUSINESS_EXCEPT` — **template, not enabled by default**. Lists common e-commerce + auth patterns (root + wildcard variants of `cart`, `checkout`, `account`, `profile`, `dashboard`, `login`, `sign-in`, `register`, `password`, etc.) for customers to copy-paste after reviewing their actual `php artisan route:list` output:

```php
// config/smking.php — opt-in spread
'except' => [
    ...\Smking\Laravel\Defaults::EXCEPT_PATTERNS,
    ...\Smking\Laravel\Defaults::SUGGESTED_BUSINESS_EXCEPT,
    'my/store-specific/path',
],
```

Both root (`account`) and wildcard (`account/*`) patterns are included — Laravel's `Request::is('account/*')` does NOT match `/account`, so you need both to cover dashboard root + sub-routes.

### feat: `php artisan smking:cache:purge <path>`

Per-path cache invalidation for both AEO and markdown surfaces. Supports the outage-recovery + content-refresh workflows described in README. Bulk purge requires driver-level key enumeration (not exposed by the Cache facade) — for full reset, `php artisan cache:clear`.

`AeoClient` exposes `cacheNamespace()` + `cacheKeyPrefixes()` + `cacheStore()` as `@internal` API for the command to reconstruct keys without reaching into private state.

### docs: README "Outage Runbook"

New section walks through the three-tier cache, timeout knobs, `SMKING_AUTO_INJECT=false` kill switch, and `cache:purge` recovery path. Read this before you go to prod.

### Tests added (21 new)

- `test_5xx_response_treated_as_server_error_not_not_found`
- `test_4xx_response_still_treated_as_not_found`
- `test_connection_exception_treated_as_server_error`
- `test_server_error_caches_for_24_hours_by_default`
- `test_pending_status_does_not_cache`
- `test_connect_timeout_and_read_timeout_passed_separately`
- `test_default_except_includes_ecommerce_and_auth_routes`
- `test_default_except_still_covers_legacy_categories`
- `test_config_uses_defaults_const`
- `test_purge_removes_aeo_and_markdown_keys_for_path`
- `test_purge_only_clears_current_namespace_after_key_rotation`
- `test_single_flight_lock_lifecycle_does_not_leak`
- `test_single_flight_falls_back_when_driver_lacks_lock`
- `test_single_flight_re_checks_cache_after_lock_acquired`
- `test_get_markdown_without_env_does_not_leak_status_across_calls`
- `test_default_except_does_NOT_include_business_assumptions`
- `test_suggested_business_except_includes_root_and_wildcard_variants`
- (+ regression rename `test_failed_request_returns_not_found` → `test_failed_4xx_returns_not_found`)

97 tests total (was 80).

### Internal

- `AeoClient::connectTimeout()`, `AeoClient::readTimeout()` — new private helpers
- `AeoClient::cacheNamespace()`, `AeoClient::cacheKeyPrefixes()`, `AeoClient::cacheStore()` — new `@internal` public methods (for the cache-purge command)
- `AeoClient::singleFlight()` + `AeoClient::singleFlightMarkdown()` — new private cache-lock wrappers
- `AeoClient::fetchMarkdown()` now returns `array{body: ?string, status: string}` (was `?string` + mutable `$lastMarkdownStatus` instance prop, removed in adversarial-review fix)
- `Smking\Laravel\Defaults` — new public const class with two layers (`EXCEPT_PATTERNS` technical-only + `SUGGESTED_BUSINESS_EXCEPT` opt-in template)
- `Smking\Laravel\Console\CachePurgeCommand` — new artisan command

### Migration

Customers must bump composer constraint:

```bash
# composer.json
"smking/laravel": "^0.7"
```

Then `composer update smking/laravel`. If you customized `except[]` in your published `config/smking.php`, run `php artisan smking:doctor` (the v0.6.3 schema-drift check shows you what to merge in). Or re-publish with `--force` to start from the new baseline.

## v0.6.3

**Patch**: docs + diagnostics. No behavior changes.

### docs: README "Upgrading" section

New section explaining v0.x caret semantics (`^0.6` = `>=0.6.0 <0.7.0`, minor bumps treated as breaking under Composer pre-1.0 convention) plus `composer install` vs `composer update` guidance for production deploys. Links to CHANGELOG so users can read what changed before bumping minor.

### chore(doctor): config schema drift check

`php artisan smking:doctor` now includes a 7th check that compares the customer's published `config/smking.php` against the package's bundled default. Reports keys present in the package but missing from the user's file — happens after a package upgrade where `vendor:publish` SKIPPED the existing file.

The check is **info-only** (never fails the doctor exit code). Three branches:
- Config not published → `drift check skipped`
- Published in sync with package → `in sync with package defaults`
- Published with stale schema → lists missing keys + re-publish hint

`mergeConfigFrom()` in the service provider already overlays defaults at runtime so missing keys aren't a runtime bug — the check just surfaces "you may want to see what's new".

### Tests added

- `test_doctor_reports_missing_keys_when_published_config_lags`
- `test_doctor_reports_in_sync_when_published_config_matches_package`
- `test_doctor_drift_check_is_info_only_never_fails`
- `test_doctor_drift_check_skips_when_config_not_published`

80 tests total (was 76).

### Internal

- `DoctorCommand::checkConfigSchemaDrift()` + `collectMissingKeys()` + `isAssociative()` — three new private helpers.

## v0.6.2

**New feature**: middleware now injects a real `<img>` tag in body so SPA-rendered pages have a server-side product image in raw HTML.

### Why

Audits / AI crawlers that grep `<img>` count run against raw HTML before JS hydration. SPA layouts (Vue / React / Inertia) keep product images inside the framework's component tree, so raw HTML often has zero `<img>` until hydration completes — auditUrl reports `imageCount=0` even though the rendered page is image-rich. We were already injecting og:image in `<head>` and `Product.image` in JSON-LD; emitting the corresponding `<img>` in body closes the last gap with zero customer code changes.

### Behavior

- Middleware reads `seo.ogImageUrl` (same source as the `<head>` og:image tag) and emits `<img src="..." alt="..." loading="lazy" data-smking="aeo">` in body.
- Alt-text fallback chain: `seo.ogTitle` → `seo.title` → `jsonLd.name` → empty.
- Wrapped by `inject.visibility` (default `sr_only`) so users don't see a duplicate image on top of whatever the SPA renders. The DOM still has the tag — `cheerio.find('img')` and AI bots that grep `<img>` both pick it up.
- New flag `inject.image_html` (default `true`) — set `false` to disable just the body img while keeping og:image in `<head>`.

### Tests added

- `test_injects_img_tag_when_og_image_present`
- `test_img_alt_falls_back_through_seo_title_then_jsonld_name`
- `test_no_img_when_og_image_missing`
- `test_inject_image_html_false_disables`
- `test_img_visible_when_visibility_visible`

76 tests total (was 71).

### Internal

- `InjectAeo::resolveImageAlt()` — new private helper.

## v0.6.1

**Patch**: gate the markdown alternate `Link` header (v0.5.0+) on env presence.

When a customer pushes the SDK to production without setting `SMKING_API_KEY` + `SMKING_BASE_URL` yet, the middleware previously still emitted `Link: <{url}>; rel="alternate"; type="text/markdown"`. Agents that followed the link sent `Accept: text/markdown`, the markdown API call fell open to HTML, and the agent received HTML — a misleading discovery signal that also hurts Cloudflare's `servesMarkdown` audit check.

v0.6.1: the Link header is skipped when either `api_key` or `base_url` is empty. The rest of fail-open behavior is unchanged — middleware still emits `X-Smking-Status` headers so doctor + `curl -I` install verification work whether env is configured or not.

### Tests added

- `test_link_header_skipped_when_api_key_missing`
- `test_link_header_skipped_when_base_url_missing`

71 tests total (was 69).

### Internal

- `InjectAeo::isConfigured()` — new private helper.

## v0.6.0

**Visual change**: middleware-injected `summaryHtml` / `faqHtml` body fragments default to **visually hidden** (sr-only inline-style wrapper). The DOM still contains the microdata; the user no longer sees an unstyled section at the bottom of the page.

### Why

Reported in [issue #1](https://github.com/sillyleo/smking-laravel/issues/1) — on Laravel + Vue / React / Inertia SPA layouts, the `</body>`-prefixed body fragments land **outside** the framework's mount target (`#app`). Result:

1. **FOUC**: first paint shows only the unstyled `<section class="smking-summary">` block, because the SPA hasn't compiled `#app` yet.
2. **Permanent visible markup**: even after mount, the section sits permanently in the DOM at body-end as plain unstyled text — duplicating content the customer already designed in their Vue / React FAQ component.
3. **Conflicts with "no-touch" install philosophy**: middleware silently inserting visible markup into the customer's page is functionally equivalent to editing their Blade.

JSON-LD in `<head>` already covers all major AI crawlers (ChatGPT, Perplexity, Claude, Google AI Mode) — the body microdata is only needed as a Googlebot backup signal, where visibility is irrelevant for parsing.

### Behavior

`config('smking.inject.visibility')` (new, default `'sr_only'`):

| Mode | Wrapper | When to use |
|------|---------|-------------|
| `sr_only` (default) | inline-style visually-hidden `<div>` (W3C clip-rect pattern) | SPA / customers with their own FAQ design — DOM has microdata, screen invisible |
| `visible` | none — raw fragments (v0.5.x behavior) | SSR-only article sites that genuinely want a no-JS user fallback |
| `noscript` | `<noscript data-smking="aeo">` | Only if you specifically want the fragments visible **only** when JS is disabled. **Not recommended**: GPTBot skips `<noscript>` trees, PerplexityBot is inconsistent. |

Unknown values fall back to `sr_only` — defensive, never leaks raw fragments.

The `<x-smking-aeo />` Blade component is **unaffected**. When you place that component explicitly in your layout, the assumption is you want it visible — visibility config only governs the auto-inject path.

### Migration

- **Most installs need no change.** v0.5 behavior already had the bug this fixes; the default switch is the fix.
- If you genuinely depended on the visible body fragments (rare — pure SSR article site that wanted SDK-rendered FAQ visible), set `SMKING_INJECT_VISIBILITY=visible` in `.env`.
- Composer constraint bump required: `^0.5` → `^0.6` (caret 0.x treats minor as breaking).

### Tests added

- `test_body_fragments_default_to_sr_only_wrapper` — default wraps in inline-style div with microdata preserved
- `test_visibility_visible_emits_raw_fragments_without_wrapper` — opt-in legacy behavior
- `test_visibility_noscript_wraps_fragments_in_noscript_tag` — opt-in noscript mode
- `test_unknown_visibility_value_falls_back_to_sr_only` — defensive default
- `test_no_wrapper_emitted_when_no_body_fragments` — empty fragments don't leave an empty wrapper div

69 tests total (was 64).

### Internal

- `InjectAeo::wrapByVisibility()` — new private helper (PHP `match` over the three modes).

## v0.5.0

**New feature**: `Link: rel="alternate"; type="text/markdown"` response header — agent discovery for the v0.4.0 markdown rendition.

### Why

v0.4.0 made the middleware serve markdown when an agent sends `Accept: text/markdown`, but discovery was passive: the agent had to *speculatively* try a markdown Accept header to find out. RFC 8288 Link headers are the standard way to advertise an alternate representation, and Cloudflare's `isitagentready.com` page-level audit explicitly checks for this — sites that emit it score higher on Agent Readiness without any extra work.

### Behavior

Every HTML 200 GET response from the middleware now carries:

```
Link: <{request-url}>; rel="alternate"; type="text/markdown"
```

Notes:

- Appended (not replaced) so customers' existing Link headers (`rel="next"` / `rel="prev"` / pagination headers / canonical headers) survive untouched.
- Skipped on markdown responses themselves — pointing an already-markdown body at a markdown alternate of itself is meaningless. (The respondWithMarkdown branch returns before the Link emission line.)
- Skipped when `inject.markdown=false` — the same opt-out flag that disables Accept: text/markdown serving also turns off Link header advertising. Consistent semantics: if SDK isn't serving markdown, it shouldn't advertise it.
- Idempotent — if a customer (or a previous middleware run) already advertises a markdown alternate, we don't add another. Detection matches the audit-side regex (`/rel="?alternate"?/i` && `/type="?text\/markdown"?/i`).

### Audit score interaction

`auditUrl` already reads this Link header via `hasMarkdownAlternate` (5 points) and verifies content negotiation actually serves markdown via `servesMarkdown` (5 points). v0.5.0 makes both auto-`true` for SDK-installed sites — combined +10 points on the page-level Agent Readiness signals, install-only.

### Tests added

- `test_emits_markdown_alternate_link_header_on_html_response` — basic emit
- `test_link_header_appends_to_customer_existing_link` — customer's `rel="next"` survives
- `test_link_header_skipped_when_inject_markdown_false` — opt-out
- `test_link_header_not_duplicated_when_customer_already_advertises_markdown` — idempotency
- `test_link_header_not_emitted_on_markdown_response` — meaningless on md body

64 tests total (was 59).

### Internal

- `InjectAeo::addMarkdownAlternateLink()` — new private helper.

## v0.4.0

**New feature**: Markdown for Agents — content negotiation for autonomous agent clients.

### Why

When an autonomous agent (browser agent, MCP client, ChatGPT-style buyer) hits a product page, parsing through a full HTML document with nav, footer, scripts, and visual chrome is wasteful — agents only need the decision-relevant facts. The Cloudflare `isitagentready.com` audit explicitly checks for this content negotiation, scoring sites higher when they serve a markdown rendition for `Accept: text/markdown`. Without SDK support this was a per-controller manual fix; v0.4.0 makes it middleware-level so install-only customers get it for free.

### Behavior

The middleware now negotiates on `Accept` before the HTML rewrite path runs:

- Browser request (`Accept: text/html, ...`) — unchanged. HTML rewrite as v0.3.0.
- Agent request (`Accept: text/markdown`, or `text/markdown` with q-value ≥ html / *\/*) — middleware fetches `/api/v1/public/md?key=...&path=...` from your smking deployment and replaces the response body with the markdown rendition. `Content-Type` becomes `text/markdown; charset=utf-8`. `Vary: Accept` is added so shared caches don't cross-pollinate.

Markdown body is the AEO content already in your `product_content` table — title, AI summary, meta description, FAQ — assembled by the SaaS `/api/v1/public/md` route. No extra crawl happens.

### First-request fallback

If the agent hits a path the SaaS hasn't crawled yet (`/api/v1/public/md` returns 404), the middleware falls through to HTML so the agent gets *something* this turn. The same request also triggers the existing `forPath()` background crawl (no extra API call), so the second agent request typically gets the markdown.

### Quality-aware Accept parsing

`wantsMarkdown()` parses Accept properly — q-values, multiple media types, listing order for ties. Browser default `Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8` does NOT trigger markdown (HTML wins by being first at q=1.0). Explicit `Accept: text/markdown` does.

### Config

New `inject.markdown` flag (default `true`):

```php
// config/smking.php
'inject' => [
    // ...existing flags...
    'markdown' => true, // set false to always serve HTML
],
```

Set `false` if you want to wire your own content negotiation in a controller — middleware will then ignore `Accept: text/markdown` and serve HTML as in v0.3.x.

### Cache

Markdown responses cache under their own key prefix (`smking:md:`) so they never collide with `forPath()`'s cached `AeoResponse` (different value types, different TTL semantics). Cache namespace still rotates on (api_key, base_url) change, same as v0.2.3+. Negative cache hits (404 from md API) use the short `not_found_ttl` so a freshly-crawled path becomes available within ~30 seconds.

### Tests added

- `test_serves_markdown_when_agent_sends_accept_text_markdown` — happy path
- `test_falls_back_to_html_when_markdown_api_returns_404` — first-time miss
- `test_serves_html_when_accept_prefers_html_over_markdown` — q-value precedence
- `test_serves_markdown_when_higher_q_than_html` — markdown q-value wins
- `test_browser_default_accept_does_not_trigger_markdown` — Chrome / Firefox safe
- `test_inject_markdown_false_disables_negotiation` — opt-out flag
- `test_markdown_skipped_when_auto_inject_false` — auto_inject short-circuit precedence
- `test_markdown_request_calls_md_endpoint_with_key_and_path` — request shape
- `test_markdown_branch_runs_before_html_fragment_guard` — agent gets md even on fragment routes

59 tests total (was 50).

### Internal

- `AeoClient::getMarkdown()` — new public method.
- `InjectAeo::wantsMarkdown()` / `respondWithMarkdown()` / `mergeVary()` — new private helpers.

## v0.3.0

**Breaking change**: SEO injection switches from "fill gaps" to "always override".

### Why

v0.2.x detected existing host markup (`<title>`, `<meta name="description">`, og:*, canonical) and skipped injection to coexist with Yoast / Rank Math / hand-written meta. In practice smking customers install us because we are their SEO/AEO solution — Yoast equivalents are competitors, not coexisting partners. The "fill gaps" rule had a worse failure mode than expected: starter Blade templates almost universally write `<meta name="description" content="{{ $foo ?? 'placeholder' }}" />` fallbacks, which v0.2.x mistook for real host content and refused to override. Result: customers ran `composer require smking/laravel`, generated AEO content via the SaaS dashboard, audited their site, and saw no improvement — the SDK was working perfectly but invisibly blocked by their own template fallback.

### Behavior change

For every enabled tag, the middleware now **strips** any matching host markup (attribute-order-insensitive) before injecting smking's version. The document ends up with exactly one of each:

- `<meta name="description">` — strip + inject
- `<title>` — strip + inject (limit 1, won't eat stray titles inside `<noscript>` / `<template>`)
- `<meta property="og:title">` — strip + inject
- `<meta property="og:description">` — strip + inject
- `<meta property="og:image">` — strip + inject
- `<link rel="canonical">` — strip + inject

Per-tag opt-out is unchanged: `config('smking.inject.{tag}', false)` still disables individual tags entirely (e.g. when you render meta yourself via `<x-smking-meta />` or the `Smking::metaFor()` facade).

### Migration

No code changes required for typical installs. If you intentionally relied on v0.2.x preserving your host meta:

- **Render meta yourself**: set `config('smking.inject.{tag}', false)` for each tag you control, or use the `<x-smking-meta />` Blade component.
- **Disable injection entirely**: set `SMKING_AUTO_INJECT=false` (verification headers still emit so doctor + `curl -I` work).

### Tests changed

- Removed: `test_does_not_override_existing_title_or_canonical`, three `test_respects_existing_*_with_reversed_attribute_order` tests
- Added (inverse coverage): `test_overrides_existing_title_and_canonical`, three `test_overrides_existing_*_with_reversed_attribute_order` tests
- Each new test asserts `substr_count($html, '<title') === 1` / `preg_match_all('/rel="canonical"/i') === 1` so the document never ends up with duplicates after strip+inject.

50 tests total (unchanged).

### Internal

- `tagHasAttribute()` removed from `InjectAeo` middleware.
- New helpers: `stripVoidTag()` (meta / link), `stripTitle()` (paired title element).

## v0.2.4

External audit (`docs/audit-smking-laravel.md`) caught two **High** severity correctness bugs that all four prior releases (v0.2.0 → v0.2.3) shipped. Fixing both.

### High: SEO conflict detection now attribute-order-insensitive

The previous regex (`<meta\s+name=["\']description["\']`) only matched when the identifying attribute came first. Common host markup like `<meta content="…" name="description">` (content first, name second — valid HTML) bypassed the check, and the middleware injected a duplicate description tag. Same bug class applied to `og:title`, `og:description`, `og:image`, and `<link rel="canonical">`.

v0.2.4 scans every `<meta>` / `<link>` tag and looks for the identifying attribute pair anywhere in the tag, regardless of position.

### High: Full-document guard prevents partial HTML corruption

`injectBefore()` had a fallback that appended the fragment to the end of the string when `</head>` / `</body>` weren't found. HTMX, Turbo, and custom fragment endpoints often respond with raw chunks like `<div>…</div>` served as `text/html` — for which the middleware would happily append JSON-LD `<script>` and FAQ `<section>` to the fragment, corrupting it.

v0.2.4 adds an `isFullHtmlDocument()` guard: requires both `<html` and at least one of `</head>` / `</body>` before any rewrite happens. Partial responses still get the verification headers but their body is left untouched.

### Tests added

- `test_skips_partial_html_fragment_response` — HTMX-style `<div>` chunk untouched, headers still emitted
- `test_skips_html_fragment_with_html_tag_but_no_head_or_body` — half-document edge case
- `test_respects_existing_meta_description_with_reversed_attribute_order` — `<meta content="…" name="description">` not overridden
- `test_respects_existing_og_title_with_reversed_attribute_order`
- `test_respects_existing_canonical_with_reversed_attribute_order`

50 tests total (was 45).

## v0.2.3

Full audit of v0.2.2 surfaced four critical issues and several smaller ones. This release fixes them in one batch.

### Critical fixes

- **Doctor's "config/smking.php published" check stops being a false ❌.** The service provider already loads defaults via `mergeConfigFrom`, so publishing is optional. v0.2.2 reported ❌ when the file was absent and exited 1, telling customers their install was broken when it wasn't. Now an `info` row.
- **Doctor's "API reachable" check actually checks reachability now.** v0.2.2 sent GET to `base_url` root and called any `<500` response a pass — typo'd domains, `https://google.com`, or 404 landing pages all passed. v0.2.3 sends POST to `{base_url}/api/v1/public/aeo` with an empty body and expects 400/401/422 (real validation rejection from the smking handler). 404 / 5xx / unexpected 2xx now fail with a precise reason.
- **`Content-Encoding: gzip` responses no longer get rewritten.** Customers running PHP `output_compression` or webserver-level gzip middleware previously had their gzipped HTML body mangled by `preg_replace`/`setContent` — corrupted pages downstream. Middleware now emits headers and returns when any `Content-Encoding` is set.
- **Cache key namespace now includes `(api_key, base_url)`.** Rotating the publishable key or switching `SMKING_BASE_URL` between staging and production used to leave stale `not_found` / `ready` entries in cache for up to an hour. v0.2.3 derives a 12-char SHA-256 namespace from those two values, so rotation invalidates automatically.

### Important fixes

- **`Content-Disposition: attachment` HTML downloads are skipped.** AEO content was being injected into HTML files customers were exporting for download.
- **`not_found` cache TTL is short by default (30s, configurable via `SMKING_NOT_FOUND_TTL`).** Long not_found cache used to mask the moment backend audit transitioned to ready, leaving customers waiting up to a full hour for content to surface.
- **Doctor's default `--path` is now `__smking-doctor`** instead of `/`, so running doctor doesn't register a meaningless `/` path in the backend audit queue.
- **Doctor drops the "ServiceProvider registered" check.** It was always ✅ — the doctor command can only run if the provider already loaded — so it added nothing.
- **Default `except` patterns expanded.** Added `v1/*`, `v2/*`, `graphql`, `webhooks/*`, `oauth/*`, `up` (Laravel 11 health), `health`, `healthz`, `ping`, `_debugbar*`, `nova*`, `filament*` so middleware doesn't waste API calls on routes that obviously aren't HTML pages.

### Tests added

- HEAD method + `auto_inject=false` (gap that let v0.2.0 → v0.2.2's HEAD bug slip)
- `Content-Encoding: gzip` response stays untouched
- `Content-Disposition: attachment` skipped entirely
- Cache isolation across `api_key` rotation and `base_url` switch
- `not_found` cache hits a second call but new HTTP fires after eviction
- Doctor's `config not published` returns 0 (no longer a hard fail)
- Doctor's `API endpoint 404` returns 1 (caught the v0.2.2 false-pass case)

## v0.2.2

### Fixed
- `curl -I` (HEAD requests) now receive the `X-Smking-Status` and `X-Smking-Path` headers. Previously `shouldInject()` only accepted GET, so HEAD requests bypassed the middleware entirely — install verification via `curl -I` silently returned no smking headers. HEAD is GET-without-body in HTTP semantics; the middleware now treats both methods identically for header emission and skips body rewrite when the response carries no content.

## v0.2.1

### Fixed
- `php artisan smking:doctor` now correctly reports ✅ for "Middleware in HTTP kernel". Service provider's `registerMiddleware()` previously skipped registration in console contexts, leaving the HTTP kernel's middleware array empty when doctor reflected into it. The `runningInConsole()` short-circuit was over-defensive — `pushMiddleware()` only mutates the HTTP kernel's protected `$middleware` array, and console requests run on a separate `Console\Kernel` instance with its own stack, so registering on console has no runtime side-effect. Service provider now registers unconditionally with an idempotency guard.

## v0.2.0

### Added
- `php artisan smking:doctor [--path=/]` — install verification command. Walks 7 checks (provider registered, config published, API key, base URL, middleware in kernel, API reachable, path status) and exits 0 / 1 accordingly.
- `X-Smking-Status` and `X-Smking-Path` response headers on every middleware-handled HTML 200 GET. Status values: `ready`, `pending`, `not_found`, `disabled`. Lets `curl -I` verify the SDK without depending on backend audit completion.
- Dev-environment HTML comment in non-ready responses (`<!-- smking: middleware-ran path=… status=… url=… reason=… -->`) before `</body>`. Off in production unless `SMKING_DEBUG=true`.
- `config('smking.debug')` — `null` (default, auto-detect by environment), `true` / `false` to force.

### Changed
- **`data-smking-injected="1"` is now decoupled from content readiness.** It appears on every middleware-handled response (HTML 200 GET, not in `except`), regardless of whether the smking backend has audited the URL. Previously it only appeared when fragments were actually injected, which broke local-dev install verification on `.test` / `.local` TLDs the backend can't reach.
- `auto_inject=false` no longer skips middleware registration. The middleware still runs but emits `X-Smking-Status: disabled` and leaves the HTML untouched. This keeps the install-verification path working for sites that prefer manual `<x-smking-aeo />` / facade rendering.

### Unchanged (no breaking)
- `ready` paths inject identically to v0.1.1 (JSON-LD, FAQ, summary, SEO meta).
- SEO conflict detection — host-written `<title>` / `og:*` / canonical preserved.
- Fail-open on API errors / timeouts.
- Service provider auto-discovery.
- composer constraint (PHP 8.1+, Laravel 10/11/12).

## v0.1.1

- `SMKING_BASE_URL` is required (no hardcoded default). The package ships with no fallback so it never silently talks to the wrong host.
- `AeoClient` short-circuits to `notFound()` when base URL is unset and logs a warning.

## v0.1.0

- Initial release: middleware auto-inject, `<x-smking-aeo />` Blade component, `Smking::forPath()` facade.
