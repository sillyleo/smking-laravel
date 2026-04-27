# Changelog

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
