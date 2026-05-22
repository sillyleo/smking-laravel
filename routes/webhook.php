<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Smking\Laravel\Http\Controllers\WebhookController;

/**
 * Standalone webhook route file — extracted from SmkingServiceProvider so the
 * smking-wizard can `require base_path('vendor/smking/laravel/routes/webhook.php')`
 * directly from customer `routes/api.php`.
 *
 * Why this matters: Laravel's `route:cache` command captures user-defined
 * routes (the ones in `routes/web.php` / `routes/api.php`) into a single
 * compiled file. Routes registered inside a service provider's boot() are
 * skipped silently when the cache is present — so smking webhook would 404
 * in production. By requiring this file from customer's route files,
 * `route:cache` picks it up like any other customer route.
 *
 * The SmkingServiceProvider still auto-mounts this same route for the
 * unconfigured case (fresh install, no wizard run, no route:cache) — see
 * SmkingServiceProvider::registerRoutes(). Double-registration is idempotent
 * because Laravel's route collection dedupes by name + method + URI.
 *
 * --- Namespace defence (v0.13.0 fix) ---
 *
 * Older Laravel apps (≤7-style scaffold) keep a `RouteServiceProvider` that
 * still wraps `routes/api.php` with `->namespace('App\Http\Controllers')`.
 * Without protection here, requiring this file from customer's
 * `routes/api.php` would make Laravel prepend that namespace to our
 * `WebhookController` string, producing the bogus
 * `App\Http\Controllers\Smking\Laravel\Http\Controllers\WebhookController`
 * and breaking every artisan command with `UnexpectedValueException`
 * (audit handoff #9 — boss reproduced 2026-05-22).
 *
 * The defence is the `[Class::class, '__invoke']` array syntax (instead of
 * the previous `WebhookController::class` invokable shorthand). Laravel's
 * router resolves array-callable actions through the callable pipeline,
 * which bypasses `Illuminate\Routing\RouteAction::makeInvokable()` —
 * the spot where a string controller picks up the surrounding group's
 * namespace prefix. Works identically on Laravel 7 / 8+ / 9+ / 10+ /
 * 11+ / 12+ (the array syntax has been canonical since 8.x and is
 * still supported in 12 + 13).
 */
Route::post(
    config('smking.webhook.path', '/api/smking/webhook'),
    [WebhookController::class, '__invoke'],
)->name('smking.webhook')->withoutMiddleware(['web', 'auth']);
