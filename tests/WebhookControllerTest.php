<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Data\AeoResponse;

class WebhookControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('smking.cache.enabled', true);
        $app['config']->set('smking.webhook_secret', 'test_secret_abc');
    }

    private function signedPost(string $secret, array $payload, ?string $sigOverride = null)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig = $sigOverride ?? ('sha256='.hash_hmac('sha256', $body, $secret));

        return $this->call(
            'POST',
            '/api/smking/webhook',
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SMKING_SIGNATURE' => $sig,
            ],
            $body,
        );
    }

    private function freshPayload(array $overrides = []): array
    {
        return array_merge([
            'deliveredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'deliveryId' => bin2hex(random_bytes(16)),
        ], $overrides);
    }

    public function test_invalid_signature_returns_401(): void
    {
        $response = $this->signedPost(
            'test_secret_abc',
            $this->freshPayload([
                'kind' => 'cms_page',
                'slugs' => ['hello'],
            ]),
            'sha256=00deadbeef'.str_repeat('0', 56),
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'invalid_signature');
    }

    public function test_missing_secret_returns_503(): void
    {
        config()->set('smking.webhook_secret', null);
        $response = $this->signedPost('whatever', [
            'kind' => 'cms_page',
            'slugs' => ['hello'],
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('error', 'webhook_secret_missing');
    }

    public function test_no_kind_returns_200_no_action(): void
    {
        $response = $this->signedPost('test_secret_abc', $this->freshPayload([
            'slugs' => ['hello'],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('note', 'no_action_taken');
    }

    public function test_unknown_kind_returns_200_no_action(): void
    {
        $response = $this->signedPost('test_secret_abc', $this->freshPayload([
            'kind' => 'future_widget_config',
            'paths' => ['/widgets/abc'],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('kind', 'future_widget_config');
        $response->assertJsonPath('evicted', 0);
    }

    public function test_aeo_payload_evicts_aeo_and_markdown_cache(): void
    {
        /** @var AeoClient $client */
        $client = $this->app->make(AeoClient::class);
        $store = $client->cacheStore();
        $prefixes = $client->cacheKeyPrefixes();
        $path = '/products/foo';
        $aeoKey = $prefixes['aeo'].http_build_query(['path' => $path]);
        $markdownKey = $prefixes['markdown'].http_build_query(['path' => $path]);
        $rootKey = $prefixes['aeo'].http_build_query(['path' => '/']);

        $store->put($aeoKey, new AeoResponse(
            status: AeoResponse::STATUS_READY,
            jsonLd: ['@type' => 'WebPage'],
        ), 300);
        $store->put($markdownKey, 'stale_markdown', 300);
        $store->put($aeoKey.':fc', 3, 300);
        $store->put($markdownKey.':fc', 3, 300);
        $store->put($prefixes['circuit_aeo'], true, 300);
        $store->put($prefixes['circuit_md'], true, 300);
        $store->put($rootKey, 'untouched_root', 300);

        $response = $this->signedPost('test_secret_abc', $this->freshPayload([
            'kind' => 'aeo',
            'paths' => [$path.'/', $path, '   '],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('kind', 'aeo');
        $response->assertJsonPath('evicted', 1);
        $this->assertFalse($store->has($aeoKey));
        $this->assertFalse($store->has($markdownKey));
        $this->assertFalse($store->has($aeoKey.':fc'));
        $this->assertFalse($store->has($markdownKey.':fc'));
        $this->assertFalse($store->has($prefixes['circuit_aeo']));
        $this->assertFalse($store->has($prefixes['circuit_md']));
        $this->assertSame('untouched_root', $store->get($rootKey));

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([
                'status' => 'ready',
                'jsonLd' => ['@type' => 'Product'],
            ], 200),
        ]);

        $fresh = $client->forPath($path, 'https://shop.test/products/foo');

        $this->assertSame('Product', $fresh->jsonLd['@type'] ?? null);
        Http::assertSentCount(1);
    }

    public function test_cms_page_payload_evicts_cms_cache(): void
    {
        $apiKey = config('smking.api_key');
        $baseUrl = rtrim(config('smking.base_url'), '/');
        $namespace = substr(hash('sha256', $apiKey.'|'.$baseUrl), 0, 12);
        $helloKey = 'smking:cms:'.$namespace.':hello';
        $aboutKey = 'smking:cms:'.$namespace.':about';

        Cache::put($helloKey, 'stale_hello', 300);
        Cache::put($aboutKey, 'stale_about', 300);

        $response = $this->signedPost('test_secret_abc', $this->freshPayload([
            'kind' => 'cms_page',
            'slugs' => ['hello', 'about'],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('kind', 'cms_page');
        $response->assertJsonPath('evicted', 2);
        $this->assertNull(Cache::get($helloKey));
        $this->assertNull(Cache::get($aboutKey));
    }

    public function test_replayed_delivery_id_returns_401(): void
    {
        $payload = $this->freshPayload([
            'kind' => 'cms_page',
            'slugs' => ['hello'],
        ]);

        $first = $this->signedPost('test_secret_abc', $payload);
        $first->assertStatus(200);

        // Identical payload + signature — the intercepted-and-replayed case.
        $replay = $this->signedPost('test_secret_abc', $payload);
        $replay->assertStatus(401);
        $replay->assertJsonPath('error', 'duplicate_delivery');
    }

    public function test_stale_delivered_at_returns_401(): void
    {
        $response = $this->signedPost('test_secret_abc', $this->freshPayload([
            'kind' => 'cms_page',
            'slugs' => ['hello'],
            'deliveredAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 360),
        ]));

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'stale_delivery');
    }

    public function test_future_delivered_at_beyond_window_returns_401(): void
    {
        $response = $this->signedPost('test_secret_abc', $this->freshPayload([
            'kind' => 'cms_page',
            'slugs' => ['hello'],
            'deliveredAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 360),
        ]));

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'stale_delivery');
    }

    public function test_missing_delivered_at_returns_401(): void
    {
        $response = $this->signedPost('test_secret_abc', [
            'kind' => 'cms_page',
            'slugs' => ['hello'],
            'deliveryId' => bin2hex(random_bytes(16)),
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'stale_delivery');
    }

    public function test_missing_delivery_id_tolerated_window_only(): void
    {
        // Old SaaS payload shape (pre-deliveryId) — must keep working;
        // the deliveredAt window is the only replay defense for these.
        $payload = [
            'kind' => 'cms_page',
            'slugs' => ['hello'],
            'deliveredAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $first = $this->signedPost('test_secret_abc', $payload);
        $first->assertStatus(200);

        $again = $this->signedPost('test_secret_abc', $payload);
        $again->assertStatus(200);
    }
}
