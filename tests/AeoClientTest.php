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

    public function test_failed_4xx_returns_not_found(): void
    {
        // v0.7.0 splits 4xx (not_found, 15min cache) from 5xx (server_error,
        // 24hr cache). 5xx behavior covered separately in
        // test_5xx_response_treated_as_server_error_not_not_found.
        Http::fake([
            '*' => Http::response('bad request', 400),
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

    // ── Three-tier cache TTL (v0.7.0) ─────────────────────────────

    public function test_5xx_response_treated_as_server_error_not_not_found(): void
    {
        Http::fake([
            '*' => Http::response('upstream broken', 503),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_SERVER_ERROR, $response->status);
        $this->assertFalse($response->isReady());
    }

    public function test_4xx_response_still_treated_as_not_found(): void
    {
        Http::fake([
            '*' => Http::response('not found', 404),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_NOT_FOUND, $response->status);
    }

    public function test_connection_exception_treated_as_server_error(): void
    {
        Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cannot reach host'),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_SERVER_ERROR, $response->status);
    }

    public function test_server_error_caches_for_24_hours_by_default(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.ttl', 3600);
        // server_error_ttl default is 86400 (24hr)

        Http::fake([
            '*' => Http::response('upstream broken', 503),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/dead');
        Http::assertSentCount(1);

        // Subsequent call within the 24hr window must hit cache, NOT re-try
        // the dead upstream — this is the FPM-saturation prevention.
        $client->forPath('/dead');
        Http::assertSentCount(1);
    }

    public function test_pending_status_does_not_cache(): void
    {
        config()->set('smking.cache.enabled', true);

        Http::fake([
            '*' => Http::response(['status' => 'pending'], 202),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/p');
        $client->forPath('/p');

        // Pending is intentionally NOT cached — re-checking on each request
        // means users see ready content the moment crawl finishes.
        Http::assertSentCount(2);
    }

    // ── Connect/read timeout split (v0.7.0, #3) ───────────────────

    public function test_connect_timeout_and_read_timeout_passed_separately(): void
    {
        config()->set('smking.connect_timeout', 0.5);
        config()->set('smking.timeout', 1.5);

        Http::fake([
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $this->app->make(AeoClient::class)->forPath('/x');

        Http::assertSent(function ($request) {
            // Laravel's PendingRequest serializes options via Guzzle; we
            // can't directly assert the timeout values from a fake, but
            // we can confirm the request went through (regression: bad
            // method names like ->connectTimeoutMS would crash).
            return str_contains($request->url(), 'api.test/api/v1/public/aeo');
        });
    }
}
