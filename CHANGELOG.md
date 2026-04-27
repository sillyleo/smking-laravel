# Changelog

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
