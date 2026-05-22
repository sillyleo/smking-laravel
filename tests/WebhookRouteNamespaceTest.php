<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Route;
use Smking\Laravel\Http\Controllers\WebhookController;

/**
 * Regression — Laravel 7-style RouteServiceProvider wraps `routes/api.php`
 * with `->namespace('App\\Http\\Controllers')`. When the smking-wizard
 * appends `require base_path('vendor/smking/laravel/routes/webhook.php')`
 * to that file, the wrapped namespace would (pre-v0.13.1) prepend itself
 * to our `WebhookController::class` string, producing the bogus
 * `App\Http\Controllers\Smking\Laravel\Http\Controllers\WebhookController`
 * and breaking every artisan command with `UnexpectedValueException`.
 *
 * webhook.php defends with `Route::namespace(null)->group(...)`; this
 * test reproduces the wrapped-require scenario and asserts:
 *  1. Route registers without throwing on `route:list`.
 *  2. Action FQCN resolves to the absolute namespace (no `App\…` prefix).
 *
 * @group regression
 */
class WebhookRouteNamespaceTest extends TestCase
{
    public function test_webhook_route_resolves_under_legacy_namespace_wrapper(): void
    {
        // Simulate `RouteServiceProvider::map()` Laravel-7-style wrap.
        // Same shape as Laravel 7 scaffolding:
        //   Route::middleware('api')->namespace('App\\Http\\Controllers')->group(...)
        Route::middleware('api')
            ->namespace('App\\Http\\Controllers')
            ->group(function (): void {
                require __DIR__.'/../routes/webhook.php';
            });

        // Pre-fix: this artisan call would throw `UnexpectedValueException`
        // because the bogus class doesn't exist. Post-fix: clean exit.
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->call('route:list', ['--name' => 'smking.webhook']);

        // Find the registered route and confirm controller FQCN is the
        // package's, not the App\Http\Controllers\… prefixed bogus one.
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($r) => $r->getName() === 'smking.webhook',
        );

        $this->assertNotNull($route, 'smking.webhook route was not registered');
        $action = $route->getAction('controller');
        $this->assertIsString($action);
        $this->assertStringStartsWith(
            WebhookController::class,
            $action,
            "Controller FQCN must resolve to the package namespace; "
            ."got '{$action}'. Did the Route::namespace(null) defence get "
            ."removed from routes/webhook.php?",
        );
        $this->assertStringNotContainsString(
            'App\\Http\\Controllers\\Smking\\Laravel',
            $action,
            'Legacy RouteServiceProvider namespace prefix leaked into '
            .'controller FQCN — webhook.php Route::namespace(null) guard is broken.',
        );
    }
}
