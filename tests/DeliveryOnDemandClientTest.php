<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Smking\Laravel\AeoClient;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;

class DeliveryOnDemandClientTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/smking-on-demand-client-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        config()->set('cache.stores.delivery_test', [
            'driver' => 'file',
            'path' => $this->directory,
        ]);
        config()->set('smking.cache.store', 'delivery_test');
        config()->set('smking.cache.enabled', true);
        config()->set('smking.delivery.mode', 'on_demand');
        config()->set('smking.delivery.page_budget_ms', 500);
        config()->set('smking.delivery.capacity', 1);
        config()->set('smking.delivery.lease_seconds', 15);
        config()->set('smking.delivery.circuit_seconds', 30);
        config()->set('smking.delivery.connect_timeout', 0.5);
        $this->app->forgetInstance(OnDemandDelivery::class);
        $this->app->forgetInstance(AeoClient::class);
        $this->app->forgetInstance(CmsClient::class);
        $this->app->forgetInstance(WaitBudget::class);
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_clients_use_v2_only_when_on_demand_mode_is_explicit(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/v2/public/cms-page')) {
                return Http::response($this->payload('cms-page', 'slug:article'), 200, ['Content-Type' => 'application/json']);
            }
            if (str_contains($request->url(), '/api/v2/public/aeo')) {
                return Http::response($this->payload('aeo', 'path:/products/article'), 200, ['Content-Type' => 'application/json']);
            }

            return Http::response(['error' => 'legacy_or_unexpected'], 500);
        });

        $delivery = $this->app->make(OnDemandDelivery::class);
        $delivery->refresh('aeo', 'path:/products/article', new WaitBudget(500));

        $aeo = $this->app->make(AeoClient::class)->forPath('/products/article');
        $cms = $this->app->make(CmsClient::class)->forSlug('article');

        $this->assertTrue($aeo->isReady());
        $this->assertSame('按需摘要', $aeo->summary);
        $this->assertTrue($cms->isReady());
        $this->assertSame('按需文章', $cms->title);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v1/'));
    }

    public function test_disabling_aeo_in_new_mode_does_not_disable_cms(): void
    {
        config()->set('smking.delivery.aeo_enabled', false);
        Http::fake([
            '*' => Http::response($this->payload('cms-page', 'slug:article'), 200, ['Content-Type' => 'application/json']),
        ]);

        $aeo = $this->app->make(AeoClient::class)->forPath('/products/article');
        $markdown = $this->app->make(AeoClient::class)->getMarkdown('/products/article');
        $publicFile = $this->app->make(AeoClient::class)->fetchPublicFile('robots');
        $cms = $this->app->make(CmsClient::class)->forSlug('article');

        $this->assertFalse($aeo->isReady());
        $this->assertNull($markdown);
        $this->assertNull($publicFile);
        $this->assertTrue($cms->isReady());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/public/cms-page'));
    }

    public function test_on_demand_clients_cover_all_read_surfaces_without_legacy_fallback(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/v2/public/aeo')) {
                return Http::response($this->payload('aeo', 'product_id:130'), 200, ['Content-Type' => 'application/json']);
            }
            if (str_contains($request->url(), '/api/v2/public/markdown')) {
                return Http::response($this->payload('markdown', 'path:/products/article'), 200, ['Content-Type' => 'application/json']);
            }
            if (str_contains($request->url(), '/api/v2/public/site-file')) {
                return Http::response($this->payload('site-file', 'kind:robots'), 200, ['Content-Type' => 'application/json']);
            }

            return Http::response(['error' => 'legacy_or_unexpected'], 500);
        });

        $delivery = $this->app->make(OnDemandDelivery::class);
        $delivery->refresh('aeo', 'product_id:130', new WaitBudget(500));
        $delivery->refresh('markdown', 'path:/products/article', new WaitBudget(500));

        $client = $this->app->make(AeoClient::class);
        $this->assertTrue($client->forProductId(130)->isReady());
        $this->assertSame('# Product', $client->getMarkdown('/products/article'));
        $this->assertSame(
            ['body' => "User-agent: *\n", 'contentType' => 'text/plain; charset=utf-8'],
            $client->fetchPublicFile('robots'),
        );
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v1/'));
    }

    public function test_preview_stays_on_uncached_legacy_endpoint_in_on_demand_mode(): void
    {
        $this->app->instance('request', \Illuminate\Http\Request::create(
            '/blog/article',
            'GET',
            ['smking_preview' => 'TOK'],
        ));
        Http::fake([
            '*' => Http::response([
                'status' => 'preview',
                'page' => [
                    'slug' => 'article',
                    'title' => 'Draft',
                    'bodyHtml' => '<p>Draft</p>',
                ],
            ], 200),
        ]);

        $page = $this->app->make(CmsClient::class)->forSlug('article');

        $this->assertTrue($page->isPreview());
        $this->assertSame('<p>Draft</p>', $page->bodyHtml);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/public/page')
            && $request['preview_token'] === 'TOK');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $resource, string $identifier): array
    {
        $now = (int) floor(microtime(true) * 1000);
        $iso = static fn (int $milliseconds): string => gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);
        $payload = [
            'status' => 'ready',
            'delivery' => [
                'contract' => '2',
                'content_version' => 'sha256:'.hash('sha256', $resource.'|'.$identifier),
                'validated_at' => $iso($now),
                'fresh_until' => $iso($now + 300000),
                'usable_until' => $iso($now + 3600000),
            ],
        ];

        if ($resource === 'cms-page') {
            $payload['page'] = [
                'slug' => substr($identifier, 5),
                'title' => '按需文章',
                'bodyHtml' => '<h1>按需文章</h1>',
                'publishedAt' => '2026-09-01T00:00:00Z',
            ];
        } elseif ($resource === 'aeo') {
            $payload['jsonLd'] = ['@type' => 'Product'];
            $payload['summary'] = '按需摘要';
        } else {
            $payload['document'] = [
                ...(str_starts_with($identifier, 'path:')
                    ? ['path' => substr($identifier, 5)]
                    : ['kind' => substr($identifier, 5)]),
                'body' => $resource === 'markdown' ? '# Product' : "User-agent: *\n",
                'content_type' => $resource === 'markdown'
                    ? 'text/markdown; charset=utf-8'
                    : 'text/plain; charset=utf-8',
            ];
        }

        return $payload;
    }
}
