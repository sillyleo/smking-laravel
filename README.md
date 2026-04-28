# smking/laravel

AI-native SEO (AEO) for Laravel. Auto-inject JSON-LD, FAQ, and AI summaries into your pages so ChatGPT, Perplexity, and Google AI can cite them.

## Install

```bash
composer require smking/laravel
```

Publish the config:

```bash
php artisan vendor:publish --tag=smking-config
```

Configure your `.env`:

```dotenv
SMKING_API_KEY=pk_...
SMKING_BASE_URL=https://your-smking-instance.example
```

Both values are required. `SMKING_BASE_URL` must point at your smking deployment — the package ships with no default so it never silently talks to the wrong host.

That's it — the middleware auto-registers. Every HTML GET response now picks up:

- **AEO** — JSON-LD, FAQ/summary blocks (for ChatGPT, Perplexity, Google AI)
- **SEO** — `<title>`, `og:*`, `twitter:*`, `<link rel="canonical">` (for Google snippet + social shares)
- **Markdown for Agents** (v0.4.0+) — autonomous agents requesting `Accept: text/markdown` get a structured markdown rendition of the page (title + summary + meta + FAQ) instead of HTML. Boosts your Cloudflare Agent Readiness score.
- **Markdown alternate Link header** (v0.5.0+) — every HTML response carries `Link: <{url}>; rel="alternate"; type="text/markdown"` so agents that don't speculatively send `Accept: text/markdown` can still discover the markdown rendition.

smking is the source of truth for SEO/AEO meta. Any existing `<title>`, `<meta name="description">`, `og:*`, or `<link rel="canonical">` in your layout is stripped and replaced with smking's version (v0.3.0+). To keep a tag under your control, disable it via `config('smking.inject.{tag}', false)` or render it yourself with the `<x-smking-meta />` Blade component.

## Install verification

After `composer require` + `vendor:publish` + `.env` setup, run:

```bash
php artisan smking:doctor
```

If everything is green, the install is complete. The doctor command runs six checks: config publish status (informational — defaults are merged automatically, publishing is only needed when you want to override `only`/`except`/`inject.*`), API key (must be set and start with `pk_`), base URL (must be set and a valid URL), middleware is in the HTTP kernel (reflection check), API reachable (POSTs an empty body to `{base_url}/api/v1/public/aeo` and expects 400/401/422 — confirms the endpoint exists rather than just any live host), and AEO status for a probe path (informational — defaults to a synthetic `__smking-doctor` so doctor runs don't pollute the audit queue with real URLs).

For HTTP-level verification, hit any HTML page and look at the response headers:

```bash
curl -I http://your-app.test/
```

You should see two headers — these confirm the middleware ran, regardless of whether the smking backend has audited your URL yet:

```
X-Smking-Status: ready | pending | not_found | disabled
X-Smking-Path: /<your-path>
```

The `data-smking-injected="1"` HTML attribute also appears on every page where middleware ran (HTML 200 GET, not in `except` patterns). Content injection (JSON-LD, FAQ, SEO meta) only appears once status reaches `ready` — which requires the URL to be reachable from the public internet so the backend can crawl it.

> **Local dev with `.test` / `.local` TLDs**: the backend can't reach your machine, so status stays at `not_found` until you deploy. The middleware mark and the `X-Smking-*` headers still verify the install — `php artisan smking:doctor` is the authoritative install signal. In `local` / `testing` / `development` environments you'll also see an HTML comment near `</body>` explaining why content wasn't injected.

## Manual usage

Disable auto-injection and render where you want:

```php
// config/smking.php
'auto_inject' => false,
```

```blade
{{-- 1. Body content (JSON-LD + FAQ + summary) --}}
<x-smking-aeo path="/products/{{ $product->slug }}" />

{{-- 2. SEO meta inside <head> with fallback to your own page data --}}
<head>
    <x-smking-meta
        :path="request()->path()"
        :fallback-title="$product->name"
        :fallback-og-description="$product->short_description"
    />
</head>

{{-- 3. Facade for full control --}}
@php($aeo = \Smking::forPath('/products/'.$product->slug))
@if ($aeo->isReady())
    <script type="application/ld+json">{!! json_encode($aeo->jsonLd) !!}</script>
    <title>{{ $aeo->seo?->title ?? $product->name }}</title>
@endif
```

The `<x-smking-meta />` component mirrors `getSmkingMetadata()` from `@smking/next` — call it inside `<head>` and it emits exactly the SEO tags the API has values for, falling back to the `fallback-*` props otherwise. Use it when you want SEO meta in your Blade layout but body injection from the middleware.

## Config (config/smking.php)

| Key | Default | Notes |
|-----|---------|-------|
| `api_key` | `env('SMKING_API_KEY')` | Publishable key from the dashboard |
| `base_url` | _(required, no default)_ | Set `SMKING_BASE_URL` to your smking deployment origin |
| `auto_inject` | `true` | Register middleware globally |
| `only` / `except` | see file | Path filters (Laravel wildcard) |
| `inject.*` | all `true` | Toggle json_ld / meta_description / faq / summary / seo_title / og_title / og_description / og_image / canonical / markdown |
| `inject.visibility` | `sr_only` | Body-fragment visibility: `sr_only` (default, visually hidden), `visible` (raw, v0.5.x behavior), `noscript` |
| `cache.ttl` | `3600` | Seconds; `0` disables |
| `timeout` | `3` | HTTP timeout in seconds |

## How it works

1. Middleware runs after your response is built.
2. For each HTML `GET` 200, it calls `POST /api/v1/public/aeo` with the request path.
3. If smking has ready content, structured data + SEO meta go into `<head>`; FAQ + summary go before `</body>`.
4. **Always override** (v0.3.0+): every enabled SEO tag (`<title>`, `og:*`, `canonical`, `meta description`) gets written by smking. Any matching host markup is stripped first (attribute-order-insensitive) so the document only ever has one of each. To keep a tag under your control, set `config('smking.inject.{tag}', false)` or render it yourself.
5. Unknown paths are registered for background crawling — next request will serve content.
6. Responses are cached per path in Laravel's cache. Pending/error states fail open.
7. **Agent content negotiation** (v0.4.0+): when `Accept: text/markdown` is preferred over `text/html` (q-value-aware), the middleware fetches `/api/v1/public/md` and replaces the body with markdown. `Vary: Accept` is added so caches stay consistent. First-time misses fall through to HTML and trigger the same background crawl.
8. **Agent discovery** (v0.5.0+): every HTML response advertises the markdown alternate via `Link: <{url}>; rel="alternate"; type="text/markdown"` (RFC 8288). Appended to any existing Link headers; idempotent if you already wired your own.
9. **Visually-hidden body fragments by default** (v0.6.0+): auto-injected `summaryHtml` / `faqHtml` are wrapped in an inline-style sr-only `<div>` so they don't pollute SPA layouts where `</body>` injection lands outside `#app`. Microdata stays in the DOM (Googlebot reads it); JSON-LD in `<head>` is the primary AEO signal. Switch with `SMKING_INJECT_VISIBILITY=visible` if you want the v0.5.x behavior. The `<x-smking-aeo />` Blade component is unaffected — explicit placement is always rendered as you wrote it.

## Requirements

- PHP 8.1+
- Laravel 10 / 11 / 12

## License

MIT
