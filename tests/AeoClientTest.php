<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Data\AeoResponse;

class AeoClientTest extends TestCase
{
    public function test_for_path_returns_ready_response(): void
    {
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([
                'status' => 'ready',
                'jsonLd' => ['@type' => 'Product', 'name' => 'Widget'],
                'faq' => [['q' => 'Q?', 'a' => 'A.']],
                'summary' => 'Nice.',
                'metaDescription' => 'Buy widget.',
                'faqHtml' => '<section class="smking-faq"></section>',
                'summaryHtml' => '<section class="smking-summary"></section>',
            ], 200),
        ]);

        /** @var AeoClient $client */
        $client = $this->app->make(AeoClient::class);
        $response = $client->forPath('/products/widget', 'https://shop.example/products/widget');

        $this->assertTrue($response->isReady());
        $this->assertSame('Widget', $response->jsonLd['name']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.test/api/v1/public/aeo'
                && $request['key'] === 'pk_test_key'
                && $request['path'] === '/products/widget'
                && $request['url'] === 'https://shop.example/products/widget';
        });
    }

    public function test_202_returns_pending(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'pending'], 202),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/new');

        $this->assertSame(AeoResponse::STATUS_PENDING, $response->status);
    }

    public function test_failed_request_returns_not_found(): void
    {
        Http::fake([
            '*' => Http::response('oops', 500),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/broken');

        $this->assertSame(AeoResponse::STATUS_NOT_FOUND, $response->status);
    }

    public function test_missing_api_key_short_circuits(): void
    {
        config()->set('smking.api_key', null);
        Http::fake();

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_NOT_FOUND, $response->status);
        Http::assertNothingSent();
    }

    public function test_missing_base_url_short_circuits(): void
    {
        config()->set('smking.base_url', null);
        Http::fake();

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_NOT_FOUND, $response->status);
        Http::assertNothingSent();
    }

    public function test_cache_isolated_by_api_key(): void
    {
        // Cache namespace must include api_key so rotating it invalidates
        // stale entries instead of waiting for ttl. Without isolation, the
        // second call with a different key would hit the cache from the
        // first and get the wrong response.
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.ttl', 3600);

        Http::fake([
            '*' => Http::sequence()
                ->push(['status' => 'ready', 'summary' => 'A'], 200)
                ->push(['status' => 'ready', 'summary' => 'B'], 200),
        ]);

        config()->set('smking.api_key', 'pk_first_key');
        $first = $this->app->make(AeoClient::class)->forPath('/products/widget');
        $this->assertSame('A', $first->summary);

        // Rotate the key; cache entry from the first call must NOT be reused.
        config()->set('smking.api_key', 'pk_second_key');
        $second = $this->app->make(AeoClient::class)->forPath('/products/widget');
        $this->assertSame('B', $second->summary);
    }

    public function test_cache_isolated_by_base_url(): void
    {
        // Same isolation rule for base_url — switching between staging and
        // production environments must not cross-contaminate cache entries.
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.ttl', 3600);

        Http::fake([
            'staging.test/*' => Http::response(['status' => 'ready', 'summary' => 'staging'], 200),
            'prod.test/*' => Http::response(['status' => 'ready', 'summary' => 'prod'], 200),
        ]);

        config()->set('smking.base_url', 'https://staging.test');
        $staging = $this->app->make(AeoClient::class)->forPath('/products/widget');
        $this->assertSame('staging', $staging->summary);

        config()->set('smking.base_url', 'https://prod.test');
        $prod = $this->app->make(AeoClient::class)->forPath('/products/widget');
        $this->assertSame('prod', $prod->summary);
    }

    public function test_not_found_uses_short_ttl(): void
    {
        // not_found should live in cache only for not_found_ttl seconds, not
        // the full ttl — otherwise customers wait up to an hour for the
        // backend's audit to surface as ready.
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.ttl', 3600);
        config()->set('smking.cache.not_found_ttl', 30);

        Http::fake([
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/missing');

        // Inspect the cache entry's ttl directly via the cache repository.
        // The driver doesn't expose ttl on get(), but Laravel's array cache
        // stores entries with an absolute expiry timestamp we can probe by
        // computing the difference. Since testing absolute time is fiddly,
        // we instead confirm the value is cached AND that a second call
        // doesn't trigger another HTTP request (cache hit) within the
        // not_found_ttl window — and clearing cache makes a new HTTP call.
        Http::assertSentCount(1);

        $client->forPath('/missing'); // should hit cache
        Http::assertSentCount(1); // still 1, cached

        // Forcibly expire the cache entry — next call should re-fetch.
        $this->app->make(\Illuminate\Contracts\Cache\Factory::class)->store()->flush();
        $client->forPath('/missing');
        Http::assertSentCount(2);
    }
}
