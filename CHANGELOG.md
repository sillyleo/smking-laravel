# Changelog

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
