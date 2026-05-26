<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;
use Smking\Laravel\SmkingServiceProvider;

/**
 * Verifies that customers who set `smking.takeover.*=false` get a clean
 * skip — the SDK's `routes/takeover.php` reads each flag and conditionally
 * registers only the routes the customer wants smking to own. Independent
 * test class because Orchestra Testbench takes config overrides via
 * `defineEnvironment`, which runs before the service provider boots; doing
 * it via `$app['config']->set()` mid-test is too late (boot already ran).
 *
 * One file per disabled-flag scenario so each test gets a fresh app with
 * the right pre-boot config.
 */
class TakeoverRouteDisabledTest extends Orchestra
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
        $app['config']->set('smking.inject_in_tests', true);
        // BOTH flags off in this test — each assertion confirms its own
        // path is skipped without affecting the other.
        $app['config']->set('smking.takeover.sitemap', false);
        $app['config']->set('smking.takeover.llms_txt', false);
    }

    public function test_sitemap_route_skipped_when_takeover_sitemap_false(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('smking.sitemap'),
            'smking.takeover.sitemap=false must skip Route::get for customers '.
            'who own their own /sitemap.xml in routes/web.php.',
        );
    }

    public function test_llms_txt_route_skipped_when_takeover_llms_txt_false(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('smking.llms_txt'),
            'smking.takeover.llms_txt=false must skip Route::get for customers '.
            'who own their own /llms.txt in routes/web.php.',
        );
    }
}
