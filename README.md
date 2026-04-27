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

The middleware never overrides tags your layout already writes — it only fills gaps. So Yoast / your existing meta-writing tooling stays the source of truth.

## Install verification

After `composer require` + `vendor:publish` + `.env` setup, run:

```bash
php artisan smking:doctor
```

If everything is green, the install is complete. The doctor command checks the service provider is registered, the config is published, your API key + base URL are valid, the middleware is in the HTTP kernel, and the smking API is reachable.

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
| `inject.*` | all `true` | Toggle json_ld / meta_description / faq / summary / seo_title / og_title / og_description / og_image / canonical |
| `cache.ttl` | `3600` | Seconds; `0` disables |
| `timeout` | `3` | HTTP timeout in seconds |

## How it works

1. Middleware runs after your response is built.
2. For each HTML `GET` 200, it calls `POST /api/v1/public/aeo` with the request path.
3. If smking has ready content, structured data + SEO meta go into `<head>`; FAQ + summary go before `</body>`.
4. **Conflict detection**: every SEO tag (`<title>`, `og:*`, `canonical`, `meta description`) is only written when the host page hasn't already written it — so Yoast-equivalent tooling, custom Blade layouts, or hand-written meta tags stay untouched.
5. Unknown paths are registered for background crawling — next request will serve content.
6. Responses are cached per path in Laravel's cache. Pending/error states fail open.

## Requirements

- PHP 8.1+
- Laravel 10 / 11 / 12

## License

MIT
