<?php

use Smking\Laravel\Defaults;

return [
    /*
    |--------------------------------------------------------------------------
    | Publishable API Key
    |--------------------------------------------------------------------------
    |
    | Your smking publishable key (starts with "pk_"). Create one per site in
    | the smking dashboard. This key is safe to expose on the server but MUST
    | NOT be committed — set it in your .env file.
    |
    */
    'api_key' => env('SMKING_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The smking API origin. Required — set SMKING_BASE_URL in your .env so
    | the package knows where to send API requests. There is no hardcoded
    | default; the middleware fails open (does not inject) when this is empty.
    |
    */
    'base_url' => env('SMKING_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Auto Injection
    |--------------------------------------------------------------------------
    |
    | When enabled, the InjectAeo middleware rewrites HTML responses to add
    | JSON-LD, FAQ HTML, summary HTML and a meta description automatically.
    | Disable this if you prefer to render content manually with the Smking
    | facade or the <x-smking-aeo/> Blade component.
    |
    */
    'auto_inject' => env('SMKING_AUTO_INJECT', true),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When true, the middleware emits an HTML comment in non-ready responses
    | explaining why content wasn't injected (e.g. backend hasn't crawled the
    | URL yet, or the URL is on a private TLD like .test). Useful for install
    | verification and local development.
    |
    | `null` (default) auto-detects: enabled in local / testing / development
    | environments, off in production / staging. Set true / false in your env
    | to force.
    |
    */
    'debug' => env('SMKING_DEBUG', null),

    /*
    |--------------------------------------------------------------------------
    | Path Filters
    |--------------------------------------------------------------------------
    |
    | Restrict auto-injection to specific URL patterns. Use Laravel's standard
    | wildcard syntax ("products/*"). When "only" is empty, every HTML
    | response is eligible (subject to "except").
    |
    */
    'only' => [
        // 'products/*',
        // 'shop/*',
    ],

    // Default to the package's recommended baseline (api/* + livewire/* +
    // health/dev tooling/admin/* + e-commerce/auth flows). Customers can
    // append or replace by spreading the const:
    //
    //   'except' => [
    //       ...Defaults::EXCEPT_PATTERNS,
    //       'my/custom/path',
    //   ],
    //
    // Or replace entirely with their own array if they want a different baseline.
    'except' => Defaults::EXCEPT_PATTERNS,

    /*
    |--------------------------------------------------------------------------
    | Injection Targets
    |--------------------------------------------------------------------------
    |
    | Fine-grained control over which pieces of content are injected. Turning
    | off FAQ/summary HTML is useful if you want the structured data only
    | (crawlers still see JSON-LD) while keeping your own visual design.
    |
    */
    'inject' => [
        'json_ld' => true,
        'meta_description' => true,
        'faq_html' => true,
        'summary_html' => true,

        // SEO meta tags. v0.3.0+: every enabled tag is ALWAYS written —
        // smking is the source of truth. Any existing host markup for the
        // same tag (`<meta name="description">`, `<title>`, og:*, canonical)
        // is stripped first, then ours is injected. Set false on individual
        // tags to disable entirely (e.g. when you render that meta yourself
        // via <x-smking-meta /> or the Smking::metaFor() facade in your
        // Blade layout).
        'seo_title' => true,
        'og_title' => true,
        'og_description' => true,
        'og_image' => true,
        'canonical' => true,

        // Markdown for Agents (v0.4.0+). When an autonomous agent / browser
        // agent / MCP client sends `Accept: text/markdown`, the middleware
        // serves a structured markdown rendition of the path's AEO content
        // (FAQ + summary + meta) instead of the HTML page. Set false to
        // always serve HTML regardless of Accept — useful if you want to
        // wire your own content negotiation in a controller.
        'markdown' => true,

        // Body-fragment visibility (v0.6.0+). Auto-injected summary / FAQ
        // HTML lands before </body>, which on SPA layouts (Vue / React /
        // Inertia with #app mount target) ends up *outside* the framework's
        // root, rendering as visible unstyled text both during FOUC and
        // permanently after mount. sr_only wraps the fragments in an
        // inline-style visually-hidden container so the microdata stays
        // in the DOM (Googlebot reads it) while users see nothing extra.
        // JSON-LD in <head> is unaffected and remains the primary AEO
        // signal for AI crawlers.
        //
        //   'sr_only'  — visually hidden via inline style (default; SPA-safe)
        //   'visible'  — raw fragments (v0.5.x behavior; SSR / article sites)
        //   'noscript' — wrap in <noscript> (provided but not recommended;
        //                GPTBot skips noscript, PerplexityBot inconsistent)
        //
        // The <x-smking-aeo /> Blade component is unaffected — when you
        // place it explicitly in your layout, the assumption is you want
        // it visible.
        'visibility' => env('SMKING_INJECT_VISIBILITY', 'sr_only'),

        // Image tag injection (v0.6.2+). Inject a real `<img>` in body so
        // raw HTML has a product image — fixes SPA-site auditors (and AI
        // crawlers that grep `<img>` tags) reporting imageCount=0 when
        // the framework hasn't hydrated yet. Same source as og:image. The
        // tag is wrapped by `inject.visibility` (default sr_only), so it's
        // not visible to users on top of whatever the SPA renders.
        'image_html' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Cache (three-tier TTL since v0.7.0)
    |--------------------------------------------------------------------------
    |
    | AEO responses are cached via Laravel's cache repository to avoid hitting
    | the API on every request. v0.7.0 splits negative-cache TTL into two
    | tiers based on whether the SaaS rejected the request (4xx, treated as
    | "not found") or is unreachable / broken (5xx + connection errors,
    | treated as "server_error"). The longer server_error TTL kills the
    | retry loop against a dead upstream — critical for million-PV sites
    | where a hanging upstream can saturate the PHP-FPM worker pool.
    |
    | Customers force a re-try via `php artisan smking:cache:purge`.
    |
    */
    'cache' => [
        'enabled' => true,
        'store' => env('SMKING_CACHE_STORE'),
        'ttl' => env('SMKING_CACHE_TTL', 3600),

        // 4xx / "path not crawled yet" — short TTL lets backend audit
        // catch up. v0.7.0: 30s → 900s (15min). Customer can `cache:purge`
        // for instant re-fetch after audit/generate.
        'not_found_ttl' => env('SMKING_NOT_FOUND_TTL', 900),

        // 5xx / DNS / TCP / read timeout — long TTL since SaaS is broken.
        // v0.7.0 (new): 24hr default. Customer recovery: `cache:purge`.
        'server_error_ttl' => env('SMKING_SERVER_ERROR_TTL', 86400),

        'prefix' => 'smking:aeo:',
        'markdown_prefix' => 'smking:md:',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client (connect / read split since v0.7.0)
    |--------------------------------------------------------------------------
    |
    | v0.7.0 splits the previous single 3s `timeout` into two knobs to bound
    | the worst case for high-traffic sites:
    |
    |   connect_timeout  — TCP handshake + DNS upper bound. Defends against
    |                      DNS-resolver hangs (which Laravel's read timeout
    |                      doesn't cover).
    |   timeout          — Total response time after the connection's open.
    |
    | Default (1s + 1.5s = 2.5s worst case per cache miss) trades 60-second
    | self-heal for FPM-pool protection. Million-PV sites can drop further:
    |
    |   SMKING_HTTP_TIMEOUT=1
    |   SMKING_CONNECT_TIMEOUT=0.5
    |
    | Combined with the three-tier cache (server_error → 24hr) a single
    | timeout barely matters — first request fails fast, then 24hr cache
    | kicks in.
    |
    */
    'connect_timeout' => env('SMKING_CONNECT_TIMEOUT', 1.0),
    'timeout' => env('SMKING_HTTP_TIMEOUT', 1.5),
];
