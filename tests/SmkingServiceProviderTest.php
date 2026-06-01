<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use ReflectionClass;
use Smking\Laravel\SmkingServiceProvider;

/**
 * Integration guard for the real cms.base_path bug: the provider must
 * DEEP-merge package config over a customer's published config. A customer
 * `config/smking.php` whose `cms` section predates `base_path` must still
 * resolve `cms.base_path` to the package default — otherwise the heartbeat
 * reports `cms_base_path: null` and CMS nav cards lose their mount prefix.
 */
class SmkingServiceProviderTest extends TestCase
{
    public function test_partial_customer_cms_config_keeps_package_base_path(): void
    {
        // Simulate the customer's stale published config: a `cms` section
        // that only carries inline_styles (base_path was added to the
        // package later and they never re-published vendor config).
        config()->set('smking', [
            'cms' => ['inline_styles' => false],
        ]);

        // Re-run the package merge exactly as register() does.
        $provider = new SmkingServiceProvider($this->app);
        $merge = (new ReflectionClass($provider))
            ->getMethod('mergeConfigRecursivelyFrom');
        $merge->setAccessible(true);
        $merge->invoke($provider, dirname(__DIR__).'/config/smking.php', 'smking');

        // Package default survives the partial customer section…
        $this->assertSame('/blog', config('smking.cms.base_path'));
        // …and the customer-set leaf still wins.
        $this->assertFalse(config('smking.cms.inline_styles'));
    }
}
