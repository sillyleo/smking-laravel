<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Smking\Laravel\SmkingServiceProvider;

abstract class TestCase extends Orchestra
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
        // v0.7.3: customer-side default is to short-circuit middleware in
        // test env. Our own SDK suite genuinely tests the middleware path,
        // so flip the opt-in on for the duration of these tests.
        $app['config']->set('smking.inject_in_tests', true);
    }
}
