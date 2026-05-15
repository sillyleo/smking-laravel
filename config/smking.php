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
    | Webhook (v0.11.0+ — push-invalidate customer cache on SaaS publish)
    |--------------------------------------------------------------------------
    |
    | SmKing SaaS POSTs a signed payload to `/api/smking/webhook` (the route
    | auto-mounts in this package) whenever a CMS page is published. The
    | handler verifies the HMAC sig against `webhook_secret` then evicts
    | the matching CmsClient cache entry — so customer visitors see the
    | new version on the next request instead of waiting up to 5min for
    | the cache TTL.
    |
    | Grab the secret from your smking dashboard's site settings. If
    | unset, the webhook handler returns 503 — set both these to enable.
    | If you'd rather not run the webhook (e.g. air-gapped deploy with
    | no inbound HTTP from SaaS allowed), set `webhook.enabled = false`
    | and rely on TTL-based cache expiry (5min default).
    |
    */
    'webhook_secret' => env('SMKING_WEBHOOK_SECRET'),
    'webhook' => [
        'enabled' => env('SMKING_WEBHOOK_ENABLED', true),
        'path' => env('SMKING_WEBHOOK_PATH', '/api/smking/webhook'),
    ],

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

    // v0.7.3: under `php artisan test` / Pest / phpunit, the middleware
    // short-circuits before calling the smking backend so feature tests
    // don't time out against an unreachable upstream and don't leak test-
    // run URLs into production analytics. Flip to true in `.env.testing`
    // if you want integration tests that actually exercise the full
    // SDK → backend → cache pipeline.
    'inject_in_tests' => env('SMKING_INJECT_IN_TESTS', false),

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

    // Path filters — both `only` (whitelist, below) and `except`
    // (blacklist, next block) accept Laravel `Request::is()` patterns
    // (`products/*`, `admin*`, `/checkout`). Order: `only` is checked
    // first; `except` is applied to whatever survived. Empty `only`
    // means "every HTML response is eligible" (subject to `except`).

    // Whitelist for auto-injection. Empty array (default) = full site.
    // Populate to scope smking to a subset of paths — useful for
    // soft-launching one URL, opening one section at a time, or
    // running an A/B with smking on chosen variants and a control
    // path left untouched. README §"Gradual rollout / A/B comparison"
    // has worked examples.
    'only' => [
        // 'products/*',
        // 'shop/*',
    ],

    // Two-layer default:
    //
    //   EXCEPT_PATTERNS         — technical-only (api/*, livewire/*,
    //                             telescope*, horizon*, health checks, etc.).
    //                             Safe across all Laravel sites because the
    //                             framework / packages own those paths.
    //
    //   SUGGESTED_BUSINESS_EXCEPT — common e-commerce + auth patterns
    //                             (cart, checkout, account, login, …).
    //                             **NOT enabled by default** — URL naming
    //                             varies per site (`/cart` vs `/購物車`).
    //                             Run `php artisan route:list` to identify
    //                             your session-bound / no-public-content
    //                             routes, then either spread the const or
    //                             write your own list.
    //
    // Typical customer config after review:
    //
    //   'except' => [
    //       ...Defaults::EXCEPT_PATTERNS,
    //       ...Defaults::SUGGESTED_BUSINESS_EXCEPT,
    //       'my/store-specific/path',
    //   ],
    //
    // v0.7.4: `class_exists()` guard so this config loads even when the
    // SDK vendor folder is missing (e.g. `composer.lock` out of sync with
    // `composer.json` on first deploy — `composer install` skips the
    // package and Laravel boots a published config without the source
    // class). Without the guard, `Defaults::EXCEPT_PATTERNS` triggers a
    // hard `Class not found` at framework boot and the entire app
    // crashes. Empty fallback keeps host app alive; SDK simply does
    // nothing until the vendor is restored.
    'except' => class_exists(Defaults::class) ? Defaults::EXCEPT_PATTERNS : [],

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

        // 4xx / "path not crawled yet" — short TTL lets the backend audit
        // catch up. v0.7.0 round-4: 60s default (down from a brief 900s in
        // the round-1..3 cuts). The `forPath()` POST registers the path
        // for background crawling — a fresh product launch typically
        // becomes ready within 1-2 minutes, so a 60s miss TTL means the
        // very next request after crawl/generate finishes already serves
        // ready content. The 900s default would have masked the ready
        // transition for up to 15 minutes. Customers running extreme PV
        // who want a longer miss cushion (worker-pool stampede protection
        // is already covered by `pending_ttl` + `circuit_breaker`) can
        // set `SMKING_NOT_FOUND_TTL` to a higher value.
        'not_found_ttl' => env('SMKING_NOT_FOUND_TTL', 60),

        // 5xx / DNS / TCP / read timeout — long TTL since SaaS is broken.
        // v0.10.0: adaptive backoff replaces flat 24hr — see
        // `server_error_backoff` below. `server_error_ttl` is now the
        // fallback TTL once backoff steps are exhausted (4th+ consecutive
        // failure on a key).
        'server_error_ttl' => env('SMKING_SERVER_ERROR_TTL', 86400),

        // v0.10.0: adaptive backoff for server_error cache TTL. Per-key
        // counter (alongside the cache entry, auto-clears on `ready` /
        // `cache:purge` / namespace rotation) drives this lookup so each
        // path's backoff progresses independently.
        //
        //   1st failure → 30s     — typical install typo / firewall fix
        //                           recovers within a minute
        //   2nd failure → 5min    — coffee-break-scale fix window
        //   3rd failure → 30min   — operator-response window
        //   4th+        → falls through to server_error_ttl (24hr)
        //
        // Customize for tighter / looser recovery cadence. Set to `[]` or
        // `false` to disable adaptive backoff entirely and use the
        // pre-v0.10.0 flat `server_error_ttl` behavior.
        'server_error_backoff' => [30, 300, 1800],

        // Pending (202 from SaaS — backlog still crawling). v0.7.0 round-3:
        // cache for short window so a hot-launch URL doesn't hammer the
        // crawler queue every request. After this TTL the next request
        // checks again — by then the crawl usually completed (ready).
        'pending_ttl' => env('SMKING_PENDING_TTL', 15),

        // Namespace-wide circuit breaker. v0.7.0 round-3: when ANY path
        // hits a 5xx / transport error, set a flag for circuit_breaker_ttl
        // seconds. While the flag is present, all forPath() / getMarkdown()
        // calls short-circuit with server_error WITHOUT touching the
        // upstream. Critical for outage protection on high-cardinality
        // sites — per-path 24hr cache only protects keys we've seen fail.
        // Auto half-open: when the flag expires, the next request hits
        // upstream; success keeps it closed, failure trips again.
        'circuit_breaker' => env('SMKING_CIRCUIT_BREAKER', true),
        'circuit_breaker_ttl' => env('SMKING_CIRCUIT_BREAKER_TTL', 60),

        'prefix' => 'smking:aeo:',
        'markdown_prefix' => 'smking:md:',
        'circuit_prefix' => 'smking:circuit:',

        // CMS surface (v0.11.0+) — separate cache namespace from AEO so a
        // CMS publish doesn't invalidate AEO and vice versa. Shorter TTL
        // because CMS edits are user-driven (more frequent than the
        // SaaS-driven AEO crawl output).
        'cms_ttl' => env('SMKING_CMS_TTL', 300),
        'cms_prefix' => 'smking:cms:',

        // CMS failure TTLs are deliberately SHORTER than AEO defaults
        // (60s for AEO). CMS is cold-path: most failures are Vercel
        // first-hit cold-start timeouts that resolve on the very next
        // attempt. 15s lets the SDK retry quickly without hammering
        // upstream during a real outage.
        'cms_not_found_ttl' => env('SMKING_CMS_NOT_FOUND_TTL', 15),
        'cms_server_error_ttl' => env('SMKING_CMS_SERVER_ERROR_TTL', 15),
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

    /*
    |--------------------------------------------------------------------------
    | CMS HTTP timeouts (separate from AEO since v0.11.0)
    |--------------------------------------------------------------------------
    |
    | CMS surface uses generous defaults vs AEO. AEO is hot-path
    | (auto-injected per page → Vercel functions stay warm → 1.5s read
    | covers steady-state). CMS is cold-path (only fired when a customer
    | page renders <x-smking-cms>, cached 5min) so Vercel cold starts
    | dominate first-hit timing — easily 3-5s.
    |
    | If you've tuned Vercel keep-warm or run smking self-hosted with
    | always-on workers, drop these back to AEO levels for tighter
    | latency budgets.
    |
    */
    'cms_connect_timeout' => env('SMKING_CMS_CONNECT_TIMEOUT', 3.0),
    'cms_timeout' => env('SMKING_CMS_TIMEOUT', 10.0),

    /*
    |--------------------------------------------------------------------------
    | robots.txt augmentation (v0.8.0)
    |--------------------------------------------------------------------------
    |
    | Run `php artisan smking:publish-robots` to write these directives into
    | public/robots.txt. The command merges into the customer's existing
    | robots.txt — rules above the smking-managed block survive untouched,
    | and re-runs replace just the managed block (fenced by versioned
    | marker comments) instead of duplicating.
    |
    | Why a publish command and not auto-injection: nginx / Apache serve
    | public/robots.txt directly without invoking PHP, so middleware can
    | never influence that response. The only reliable surface is the
    | static file itself, and silently mutating the customer's public/
    | directory on package install is rude. Explicit command, opt-in.
    |
    | Default policy: allow major search + AI crawlers (GPTBot, ClaudeBot,
    | PerplexityBot, Google-Extended, Bingbot, Applebot-Extended) for
    | discovery, and emit `Content-Signal: search=yes, ai-input=no,
    | ai-train=no` so AI services can index for retrieval but won't use
    | the content for training. Override per-bot or globally in the
    | published config.
    |
    */
    'robots' => [
        'bots' => [
            'GPTBot' => 'allow',
            'ChatGPT-User' => 'allow',
            'ClaudeBot' => 'allow',
            'PerplexityBot' => 'allow',
            'Google-Extended' => 'allow',
            'Bingbot' => 'allow',
            'Applebot-Extended' => 'allow',
        ],
        'content_signal' => 'search=yes, ai-input=no, ai-train=no',
    ],

    /*
    |--------------------------------------------------------------------------
    | Path-Takeover (v0.9.0)
    |--------------------------------------------------------------------------
    |
    | When the customer's app would 404 on one of the canonical AEO/SEO file
    | paths (/sitemap.xml, /robots.txt, /llms.txt), the InjectAeo middleware
    | auto-serves the smking SaaS-generated version on their behalf. Default
    | ON for all three — installing the SDK is the customer's consent to
    | smking managing these files. Customer's own 200 response (their own
    | valid file) is NEVER overridden.
    |
    | Per-file opt-out: set the corresponding key to false to leave that
    | path entirely to the customer's own routing.
    |
    */
    'takeover' => [
        'sitemap' => true,
        'robots' => true,
        'llms_txt' => true,
    ],
];
