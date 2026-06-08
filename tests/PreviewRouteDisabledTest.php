<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;
use Smking\Laravel\SmkingServiceProvider;

/**
 * Customers who set `smking.preview.enabled=false` (e.g. they own
 * `/smking-preview`) must get a clean skip. Separate test class because
 * Orchestra takes config overrides via defineEnvironment, which runs before
 * the provider boots and registers routes.
 */
class PreviewRouteDisabledTest extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SmkingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('smking.api_key', 'pk_test_key');
        $app['config']->set('smking.base_url', 'https://api.test');
        $app['config']->set('smking.cache.enabled', false);
        $app['config']->set('smking.preview.enabled', false);
    }

    public function test_preview_route_skipped_when_disabled(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('smking.preview'),
            'smking.preview.enabled=false must skip Route::get.',
        );
    }
}
