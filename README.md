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

Set your key:

```dotenv
SMKING_API_KEY=pk_...
```

That's it — the middleware auto-registers. Every HTML GET response now picks up JSON-LD, a meta description, and FAQ/summary blocks from the smking API.

## Manual usage

Disable auto-injection and render where you want:

```php
// config/smking.php
'auto_inject' => false,
```

```blade
{{-- Blade component --}}
<x-smking-aeo path="/products/{{ $product->slug }}" />

{{-- Facade --}}
@php($aeo = \Smking::forPath('/products/'.$product->slug))
@if ($aeo->isReady())
    <script type="application/ld+json">{!! json_encode($aeo->jsonLd) !!}</script>
@endif
```

## Config (config/smking.php)

| Key | Default | Notes |
|-----|---------|-------|
| `api_key` | `env('SMKING_API_KEY')` | Publishable key from the dashboard |
| `base_url` | `https://app.smking.io` | Override for self-hosted |
| `auto_inject` | `true` | Register middleware globally |
| `only` / `except` | see file | Path filters (Laravel wildcard) |
| `inject.*` | all `true` | Toggle json_ld / meta / faq / summary |
| `cache.ttl` | `3600` | Seconds; `0` disables |
| `timeout` | `3` | HTTP timeout in seconds |

## How it works

1. Middleware runs after your response is built.
2. For each HTML `GET` 200, it calls `POST /api/v1/public/aeo` with the request path.
3. If smking has ready content, JSON-LD + meta go into `<head>`; FAQ + summary go before `</body>`.
4. Unknown paths are registered for background crawling — next request will serve content.
5. Responses are cached per path in Laravel's cache. Pending/error states fail open.

## Requirements

- PHP 8.1+
- Laravel 10 / 11 / 12

## License

MIT
