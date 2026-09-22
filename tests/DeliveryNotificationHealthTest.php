<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Smking\Laravel\Delivery\DeliveryNotificationHealth;

class DeliveryNotificationHealthTest extends TestCase
{
    public function test_receipts_are_per_resource_and_expire_without_visitor_activity(): void
    {
        $now = 1789800000000;
        $this->configure();
        // Use an explicit shared clock reference, not real sleeps.
        $health = new DeliveryNotificationHealth(new Repository(new ArrayStore()), config(), function () use (&$now) { return $now; });
        $this->assertTrue($health->record('cms-page', true));
        $this->assertTrue($health->status('cms-page')['available']);
        foreach (['aeo', 'markdown', 'site-file'] as $resource) {
            $this->assertFalse($health->status($resource)['available']);
            $this->assertTrue($health->record($resource, true));
            $this->assertTrue($health->status($resource)['available']);
        }
        $this->assertTrue($health->record('aeo', false));
        $this->assertSame('processing_failed', $health->status('aeo')['reason']);
        $now += 86400000;
        foreach (['cms-page', 'markdown', 'site-file'] as $resource) {
            $this->assertSame('expired', $health->status($resource)['reason']);
            $this->assertFalse($health->status($resource)['available']);
        }
    }

    public function test_binding_changes_switch_off_and_cache_clear_restore_fallback(): void
    {
        $this->configure();
        $cache = new Repository(new ArrayStore());
        $health = new DeliveryNotificationHealth($cache, config());
        foreach (['smking.base_url' => 'https://other.test', 'smking.api_key' => 'pk_other',
            'smking.webhook_secret' => 'other-secret', 'smking.delivery.notifications_scope' => str_repeat('e', 64),
            'smking.delivery.notifications_enabled' => false] as $key => $value) {
            $this->configure();
            $this->assertTrue($health->record('cms-page', true));
            config()->set($key, $value);
            $this->assertFalse($health->status('cms-page')['available']);
        }
        $this->configure();
        $this->assertTrue($health->record('cms-page', true));
        $cache->flush();
        $this->assertSame('unconfirmed', $health->status('cms-page')['reason']);
    }

    private function configure(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('d', 64));
        config()->set('smking.webhook_secret', 'notification-secret');
        config()->set('smking.base_url', 'https://api.test');
        config()->set('smking.api_key', 'pk_test_key');
    }
}
