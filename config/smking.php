<?php

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
    | The smking API origin. Only change this for self-hosted deployments.
    |
    */
    'base_url' => env('SMKING_BASE_URL', 'https://app.smking.io'),

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

    'except' => [
        'api/*',
        'livewire/*',
        'telescope*',
        'horizon*',
        '_ignition*',
        'admin*',
    ],

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

        // SEO meta tags. All injectors detect existing tags first and skip
        // when the host page already writes them — set false to disable
        // entirely (e.g. when you render meta yourself via <x-smking-meta />
        // or the Smking::metaFor() facade in your Blade layout).
        'seo_title' => true,
        'og_title' => true,
        'og_description' => true,
        'og_image' => true,
        'canonical' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Cache
    |--------------------------------------------------------------------------
    |
    | AEO responses are cached via Laravel's cache repository to avoid hitting
    | the API on every request. Set ttl to 0 to disable caching.
    |
    */
    'cache' => [
        'enabled' => true,
        'store' => env('SMKING_CACHE_STORE'),
        'ttl' => env('SMKING_CACHE_TTL', 3600),
        'prefix' => 'smking:aeo:',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    |
    | Timeout (seconds) for the API call. Kept intentionally low so a slow
    | upstream never blocks a page render for users.
    |
    */
    'timeout' => env('SMKING_HTTP_TIMEOUT', 3),
];
