<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Cache;

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

    public function test_invalid_signature_returns_401(): void
    {
        $response = $this->signedPost(
            'test_secret_abc',
            [
                'kind' => 'cms_page',
                'slugs' => ['hello'],
                'deliveredAt' => '2026-05-15T10:00:00Z',
            ],
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
        $response = $this->signedPost('test_secret_abc', [
            'slugs' => ['hello'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('note', 'no_action_taken');
    }

    public function test_unknown_kind_returns_200_no_action(): void
    {
        $response = $this->signedPost('test_secret_abc', [
            'kind' => 'future_widget_config',
            'paths' => ['/widgets/abc'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('kind', 'future_widget_config');
        $response->assertJsonPath('evicted', 0);
    }

    public function test_aeo_payload_ack_without_eviction(): void
    {
        $response = $this->signedPost('test_secret_abc', [
            'kind' => 'aeo',
            'paths' => ['/products/foo', '/products/bar'],
            'deliveredAt' => '2026-05-15T10:00:00Z',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('kind', 'aeo');
        // AEO Laravel SDK has no push-invalidate cache namespace today.
        $response->assertJsonPath('evicted', 0);
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

        $response = $this->signedPost('test_secret_abc', [
            'kind' => 'cms_page',
            'slugs' => ['hello', 'about'],
            'deliveredAt' => '2026-05-15T10:00:00Z',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('kind', 'cms_page');
        $response->assertJsonPath('evicted', 2);
        $this->assertNull(Cache::get($helloKey));
        $this->assertNull(Cache::get($aboutKey));
    }
}
