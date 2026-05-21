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
 */
Route::post(
    config('smking.webhook.path', '/api/smking/webhook'),
    WebhookController::class,
)->name('smking.webhook')->withoutMiddleware(['web', 'auth']);
