<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Cache;

class WebhookControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Webhook tests need cache enabled so we can prove cache eviction.
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
                'HTTP_X_SMKING_EVENT' => $payload['event'] ?? 'unknown',
            ],
            $body,
        );
    }

    public function test_invalid_signature_returns_401(): void
    {
        $response = $this->signedPost('test_secret_abc', [
            'event' => 'cms.page.published',
            'siteId' => 'uuid',
            'slug' => 'hello',
            'publishedAt' => '2026-05-14T10:00:00Z',
            'deliveredAt' => '2026-05-14T10:00:01Z',
        ], 'sha256=00deadbeef'.str_repeat('0', 56));

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'invalid_signature');
    }

    public function test_missing_secret_returns_503(): void
    {
        config()->set('smking.webhook_secret', null);
        $response = $this->signedPost('whatever', [
            'event' => 'cms.page.published',
            'slug' => 'hello',
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('error', 'webhook_secret_missing');
    }

    public function test_unknown_event_returns_200_no_action(): void
    {
        $response = $this->signedPost('test_secret_abc', [
            'event' => 'cms.page.unknown',
            'slug' => 'hello',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('note', 'no_action_taken');
    }

    public function test_missing_slug_returns_200_no_action(): void
    {
        $response = $this->signedPost('test_secret_abc', [
            'event' => 'cms.page.published',
            // no slug
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('note', 'no_action_taken');
    }

    public function test_valid_signature_evicts_cms_cache(): void
    {
        // Pre-seed the cache key shape CmsClient uses so we can assert
        // it gets forgotten. Cache key prefix + namespace + slug.
        $apiKey = config('smking.api_key');
        $baseUrl = rtrim(config('smking.base_url'), '/');
        $namespace = substr(hash('sha256', $apiKey.'|'.$baseUrl), 0, 12);
        $cacheKey = 'smking:cms:'.$namespace.':hello';

        Cache::put($cacheKey, 'stale_value', 300);
        $this->assertSame('stale_value', Cache::get($cacheKey));

        $response = $this->signedPost('test_secret_abc', [
            'event' => 'cms.page.published',
            'siteId' => 'site-uuid',
            'slug' => 'hello',
            'publishedAt' => '2026-05-14T10:00:00Z',
            'deliveredAt' => '2026-05-14T10:00:01Z',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('evicted', 'hello');
        $this->assertNull(Cache::get($cacheKey), 'cache should be evicted');
    }
}
