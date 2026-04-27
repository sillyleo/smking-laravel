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
}
