<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Smking\Laravel\Http\Controllers\LlmsTxtController;
use Smking\Laravel\Http\Controllers\SitemapController;

/**
 * Standalone takeover route file — extracted from {@see \Smking\Laravel\SmkingServiceProvider}
 * so smking-wizard can `require base_path('vendor/smking/laravel/routes/takeover.php')`
 * directly from customer `routes/web.php` and survive `php artisan route:cache`.
 *
 * Why dual-mount: Laravel's `route:cache` command captures user-defined routes
 * (the ones in `routes/web.php` / `routes/api.php`) into a single compiled
 * file. Routes registered inside a service provider's boot() are skipped
 * silently when the cache is present — so smking takeover paths would 404 in
 * production. By requiring this file from customer's route files,
 * `route:cache` picks it up like any other customer route.
 *
 * The {@see \Smking\Laravel\SmkingServiceProvider} still auto-mounts these
 * same routes for the unconfigured case (fresh install, no wizard run, no
 * route:cache) — see SmkingServiceProvider::registerRoutes(). Double-
 * registration is idempotent because Laravel's route collection dedupes by
 * name + method + URI.
 *
 * Per-path opt-out via `config/smking.php` `takeover.*` (defaults to true).
 * Customers who own `/sitemap.xml` or `/llms.txt` should flip the matching
 * flag to `false` instead of trying to register a conflicting route.
 *
 * Robots.txt takeover is NOT here — robots is handled by
 * `php artisan smking:publish-robots` which writes directives directly into
 * the customer's `public/robots.txt` so static file serving still works.
 */

/*
 * Namespace defence — array-callable form is required (v0.18.2 fix).
 *
 * `SitemapController::class` returns the string `Smking\Laravel\Http\Controllers\SitemapController`
 * WITHOUT a leading backslash. Customers running Laravel ≤7-style scaffold
 * (still common — framework upgrades don't rewrite RouteServiceProvider)
 * have `protected $namespace = 'App\Http\Controllers'` + `->namespace($this->namespace)`
 * on the web group, which concatenates that prefix onto the unprefixed
 * string and resolves to the bogus
 * `App\Http\Controllers\Smking\Laravel\Http\Controllers\SitemapController`.
 * Every `php artisan` invocation then throws `UnexpectedValueException:
 * Invalid route action` and the entire routes/web.php file 500s.
 *
 * The `[Class::class, '__invoke']` array-callable form resolves through
 * Laravel's callable pipeline which skips the namespace-prefix step
 * entirely. Same defence already shipped in routes/webhook.php since
 * v0.13.0 — this file inherited the bug because I forgot to apply the
 * same pattern when adding auto-mount in v0.18.1. Reproduced on
 * www.sleepytofu.com 2026-05-26 (doctor ticket dr_000d44af8eb2).
 */

if ((bool) config('smking.takeover.sitemap', true)) {
    Route::get('/sitemap.xml', [SitemapController::class, '__invoke'])
        ->name('smking.sitemap');
}

if ((bool) config('smking.takeover.llms_txt', true)) {
    Route::get('/llms.txt', [LlmsTxtController::class, '__invoke'])
        ->name('smking.llms_txt');
}
