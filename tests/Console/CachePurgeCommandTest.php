<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests\Console;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Tests\TestCase;

class CachePurgeCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Re-enable cache for these tests — the package TestCase disables it.
        $app['config']->set('smking.cache.enabled', true);
    }

    public function test_purge_removes_aeo_and_markdown_keys_for_path(): void
    {
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
            'api.test/api/v1/public/md*' => Http::response("# md\n", 200),
        ]);

        $client = $this->app->make(AeoClient::class);

        // Prime both caches by calling the public methods.
        $client->forPath('/products/widget');
        $client->getMarkdown('/products/widget');

        $store = $this->app->make(CacheRepository::class);
        $prefixes = $client->cacheKeyPrefixes();
        $aeoKey = $prefixes['aeo'].http_build_query(['path' => '/products/widget']);
        $mdKey = $prefixes['markdown'].http_build_query(['path' => '/products/widget']);

        $this->assertTrue($store->has($aeoKey), 'aeo cache should be primed');
        $this->assertTrue($store->has($mdKey), 'markdown cache should be primed');

        $this->artisan('smking:cache:purge', ['path' => '/products/widget'])
            ->assertExitCode(0);

        $this->assertFalse($store->has($aeoKey), 'aeo cache should be purged');
        $this->assertFalse($store->has($mdKey), 'markdown cache should be purged');
    }

    public function test_purge_only_clears_current_namespace_after_key_rotation(): void
    {
        // Prime cache with one api_key
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
        ]);
        $client = $this->app->make(AeoClient::class);
        $client->forPath('/products/widget');
        $namespaceBefore = $client->cacheNamespace();

        // Rotate api_key — namespace changes
        config()->set('smking.api_key', 'pk_rotated_key');
        $clientRotated = $this->app->forgetInstance(AeoClient::class);
        $clientRotated = $this->app->make(AeoClient::class);
        $namespaceAfter = $clientRotated->cacheNamespace();

        $this->assertNotSame($namespaceBefore, $namespaceAfter, 'rotating api_key must change cache namespace');

        // Purge under new namespace doesn't touch old namespace's keys
        $this->artisan('smking:cache:purge', ['path' => '/products/widget'])
            ->assertExitCode(0);

        $store = $this->app->make(CacheRepository::class);
        $oldAeoKey = ($this->app['config']->get('smking.cache.prefix', 'smking:aeo:')).$namespaceBefore.':'.http_build_query(['path' => '/products/widget']);
        $this->assertTrue($store->has($oldAeoKey), 'old-namespace cache should NOT be touched by purge under new key');
    }
}
