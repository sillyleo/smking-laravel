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

    public function test_purge_canonicalizes_trailing_slash_to_match_middleware(): void
    {
        // Codex round-2 finding: middleware writes cache under canonical
        // path (no trailing slash), but pre-fix purge command used the
        // raw CLI argument. Operator running `smking:cache:purge /x/`
        // would "succeed" without clearing the real `/x` entry —
        // disastrous during outage recovery (24hr server_error TTL).
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
        ]);

        $client = $this->app->make(AeoClient::class);

        // Prime cache via canonical path (what middleware writes).
        $client->forPath('/products/widget');

        $store = $this->app->make(CacheRepository::class);
        $prefixes = $client->cacheKeyPrefixes();
        $aeoKey = $prefixes['aeo'].http_build_query(['path' => '/products/widget']);

        $this->assertTrue($store->has($aeoKey), 'cache must be primed under canonical path');

        // Operator runs purge with the visible URL (trailing slash).
        // Pre-fix this would target a different cache key and miss.
        $this->artisan('smking:cache:purge', ['path' => '/products/widget/'])
            ->assertExitCode(0);

        $this->assertFalse($store->has($aeoKey), 'canonical-path entry MUST be purged when input has trailing slash');
    }

    public function test_purge_by_product_id_clears_correct_cache_key(): void
    {
        // Round-3: forProductId() uses a different cache key than path-based
        // forPath() — pre-fix the only recovery for product_id caches was
        // `cache:clear` (whole-app blast radius). New --product-id option
        // targets just the product entry.
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forProductId(42);

        $store = $this->app->make(CacheRepository::class);
        $prefixes = $client->cacheKeyPrefixes();
        $aeoKey = $prefixes['aeo'].http_build_query(['product_id' => 42]);

        $this->assertTrue($store->has($aeoKey), 'product_id cache must be primed');

        $this->artisan('smking:cache:purge', ['--product-id' => 42])
            ->assertExitCode(0);

        $this->assertFalse($store->has($aeoKey), 'product_id cache must be purged');
    }

    public function test_purge_rejects_both_path_and_product_id(): void
    {
        $this->artisan('smking:cache:purge', [
            'path' => '/x',
            '--product-id' => 99,
        ])
            ->assertExitCode(2); // INVALID
    }

    public function test_purge_rejects_zero_product_id(): void
    {
        $this->artisan('smking:cache:purge', ['--product-id' => 0])
            ->assertExitCode(2);
    }

    public function test_purge_by_path_clears_both_surface_circuit_keys(): void
    {
        // Round-4 (medium finding): the documented "force re-attempt"
        // behavior for outage recovery requires breaker reset. Without
        // it, `cache:purge` clears the per-path entries but the
        // per-surface breakers keep short-circuiting until their TTL
        // expires — operator runs purge, tells the customer it's
        // resolved, but next request still gets server_error.
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response('upstream broken', 503),
            'api.test/api/v1/public/md*' => Http::response('upstream broken', 503),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/products/widget');     // trips aeo breaker
        $client->getMarkdown('/products/widget'); // trips md breaker

        $store = $this->app->make(CacheRepository::class);
        $prefixes = $client->cacheKeyPrefixes();

        $this->assertTrue($store->has($prefixes['circuit_aeo']), 'aeo breaker must be primed');
        $this->assertTrue($store->has($prefixes['circuit_md']), 'markdown breaker must be primed');

        $this->artisan('smking:cache:purge', ['path' => '/products/widget'])
            ->assertExitCode(0);

        $this->assertFalse(
            $store->has($prefixes['circuit_aeo']),
            'aeo breaker MUST be cleared by purge so next request actually retries'
        );
        $this->assertFalse(
            $store->has($prefixes['circuit_md']),
            'markdown breaker MUST be cleared by purge — purged path may be hit by getMarkdown next'
        );
    }

    public function test_purge_by_product_id_clears_aeo_circuit_key(): void
    {
        // Round-4: --product-id purge clears the AEO surface breaker.
        // Markdown surface is left alone because product_id keys never
        // flow through the markdown surface (which uses path keys only).
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response('upstream broken', 503),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forProductId(42); // trips aeo breaker

        $store = $this->app->make(CacheRepository::class);
        $prefixes = $client->cacheKeyPrefixes();

        $this->assertTrue($store->has($prefixes['circuit_aeo']), 'aeo breaker must be primed');

        $this->artisan('smking:cache:purge', ['--product-id' => 42])
            ->assertExitCode(0);

        $this->assertFalse(
            $store->has($prefixes['circuit_aeo']),
            'aeo breaker MUST be cleared by --product-id purge'
        );
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
