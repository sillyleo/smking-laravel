<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Smking\Laravel\Http\Controllers\PreviewController;

/**
 * SDK-provided CMS draft preview entry. Auto-mounted by
 * {@see \Smking\Laravel\SmkingServiceProvider::registerRoutes()} for the
 * unconfigured case; for `route:cache`, the customer requires this file from
 * routes/web.php (same dual-mount pattern as webhook/takeover).
 *
 * Array-callable form `[PreviewController::class, '__invoke']` is required —
 * the bare `::class` shorthand 500s on legacy customer Laravel apps with a
 * RouteServiceProvider `$namespace` prefix (see routes/takeover.php for the
 * full write-up; reproduced on www.sleepytofu.com, dr_000d44af8eb2).
 *
 * Opt-out via `config/smking.php` `preview.enabled` (default true).
 */
if ((bool) config('smking.preview.enabled', true)) {
    Route::get('/smking-preview', [PreviewController::class, '__invoke'])
        ->name('smking.preview');
}
