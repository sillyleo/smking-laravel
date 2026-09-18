<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Data\CmsPage;
use Smking\Laravel\Delivery\DeliveryTargetState;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;

class DeliveryUpgradeSafetyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/smking-upgrade-'.bin2hex(random_bytes(8));
        config()->set('cache.stores.upgrade_test', ['driver' => 'file', 'path' => $this->directory]);
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.store', 'upgrade_test');
        config()->set('smking.delivery.mode', 'legacy');
        config()->set('smking.delivery.aeo_enabled', false);
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('d', 64));
        config()->set('smking.webhook_secret', 'upgrade-secret');
        config()->set('app.url', 'https://shop.example.test');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_legacy_notification_invalidates_both_cache_formats(): void
    {
        $available = true;
        Http::fake(function () use (&$available) {
            return $available
                ? Http::response($this->payload('a'), 200, ['Content-Type' => 'application/json'])
                : Http::response([], 503, ['Content-Type' => 'application/json']);
        });
        $client = $this->app->make(CmsClient::class);
        $this->assertTrue($client->forSlug('article')->isReady());
        $this->assertNotNull($this->delivery()->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $this->assertTrue($this->delivery()->hasState('cms-page', 'slug:article'));
        Http::assertSentCount(2);

        $this->notifyLegacy('article')->assertOk();
        $this->assertSame('cache_miss', $this->delivery()->peek('cms-page', 'slug:article')->error);
        $available = false;
        config()->set('smking.delivery.mode', 'on_demand');
        $this->assertSame(CmsPage::STATUS_SERVER_ERROR, $client->forSlug('article')->status);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/public/cms-page'));
    }

    public function test_legacy_download_finishing_after_notification_cannot_repopulate_content(): void
    {
        Http::fake(function () {
            $this->notifyLegacy('article')->assertOk();

            return Http::response($this->payload('a'), 200, ['Content-Type' => 'application/json']);
        });

        $this->assertSame(CmsPage::STATUS_SERVER_ERROR, $this->app->make(CmsClient::class)->forSlug('article')->status);
        Http::assertSentCount(1);
    }

    public function test_versioned_withdrawal_blocks_on_demand_and_current_legacy_rollback(): void
    {
        $this->assertTrue($this->app->make(DeliveryTargetState::class)->available(), json_encode([
            'enabled' => config('smking.delivery.notifications_enabled'),
            'scope' => config('smking.delivery.notifications_scope'),
            'store' => get_class($this->app->make(\Illuminate\Contracts\Cache\Factory::class)->store('upgrade_test')->getStore()),
        ]));
        Http::fake(fn () => Http::response($this->payload('a'), 200, ['Content-Type' => 'application/json']));
        $client = $this->app->make(CmsClient::class);
        $this->assertTrue($client->forSlug('article')->isReady());
        $this->assertNotNull($this->delivery()->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);

        $withdraw = $this->target(action: 'withdraw', revision: 2, generation: 0, withdrawalRevision: 2, version: null);
        $response = $this->notifyVersioned($withdraw);
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('contract', '2')->assertJsonPath('results.0.status', 'withdrawn');

        foreach (['on_demand', 'legacy'] as $mode) {
            config()->set('smking.delivery.mode', $mode);
            $this->assertSame(CmsPage::STATUS_NOT_FOUND, $client->forSlug('article')->status);
        }

        $this->notifyVersioned($this->target())->assertOk()->assertJsonPath('results.0.status', 'obsolete');
        $this->assertSame(CmsPage::STATUS_NOT_FOUND, $client->forSlug('article')->status);
        Http::assertSentCount(2);
    }

    public function test_versioned_update_keeps_old_content_until_worker_commits_the_target(): void
    {
        $version = 'a';
        Http::fake(function () use (&$version) {
            return Http::response($this->payload($version), 200, ['Content-Type' => 'application/json']);
        });

        $client = $this->app->make(CmsClient::class);
        $this->assertSame('<main>Version a</main>', $client->forSlug('article')->bodyHtml);
        $this->assertNotNull($this->delivery()->refresh(
            'cms-page',
            'slug:article',
            new WaitBudget(500),
        )->snapshot);
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);

        $version = 'b';
        $response = $this->notifyVersioned($this->target());
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('results.0.status', 'registered');
        Http::assertSentCount(2);

        foreach (['legacy', 'on_demand'] as $mode) {
            config()->set('smking.delivery.mode', $mode);
            $this->assertSame('<main>Version a</main>', $client->forSlug('article')->bodyHtml);
        }
        Http::assertSentCount(2);

        config()->set('smking.delivery.mode', 'on_demand');
        $this->artisan('smking:delivery:work')->assertExitCode(0);
        foreach (['on_demand', 'legacy'] as $mode) {
            config()->set('smking.delivery.mode', $mode);
            $this->assertSame('<main>Version b</main>', $client->forSlug('article')->bodyHtml);
        }
        Http::assertSentCount(3);
    }

    public function test_versioned_update_keeps_an_existing_legacy_page_without_a_visitor_fetch(): void
    {
        $version = 'a';
        Http::fake(function () use (&$version) {
            return Http::response($this->payload($version), 200, ['Content-Type' => 'application/json']);
        });

        $client = $this->app->make(CmsClient::class);
        $this->assertSame('<main>Version a</main>', $client->forSlug('article')->bodyHtml);
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);

        $version = 'b';
        $this->notifyVersioned($this->target())
            ->assertOk()
            ->assertJsonPath('results.0.status', 'registered');

        $this->assertSame('<main>Version a</main>', $client->forSlug('article')->bodyHtml);
        Http::assertSentCount(1);

        config()->set('smking.delivery.mode', 'on_demand');
        $this->artisan('smking:delivery:work')->assertExitCode(0);
        config()->set('smking.delivery.mode', 'legacy');
        $this->assertSame('<main>Version b</main>', $client->forSlug('article')->bodyHtml);
        Http::assertSentCount(2);
    }

    public function test_access_denial_blocks_current_legacy_rollback_from_serving_old_content(): void
    {
        Http::fakeSequence()
            ->push($this->payload('a'), 200, ['Content-Type' => 'application/json'])
            ->push(['status' => 'unavailable'], 401, ['Content-Type' => 'application/json']);

        $client = $this->app->make(CmsClient::class);
        $this->assertTrue($client->forSlug('article')->isReady());

        config()->set('smking.delivery.mode', 'on_demand');
        $this->assertTrue($this->delivery()->invalidate('cms-page', 'slug:article'));
        $denied = $this->delivery()->refresh('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('access_denied', $denied->error);

        config()->set('smking.delivery.mode', 'legacy');
        $this->assertSame(CmsPage::STATUS_SERVER_ERROR, $client->forSlug('article')->status);
        Http::assertSentCount(2);
    }

    public function test_withdrawal_during_versioned_download_cannot_commit_superseded_content(): void
    {
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->notifyVersioned($this->target())->assertOk();
        Http::fake(function () {
            $this->notifyVersioned($this->target(
                action: 'withdraw',
                revision: 2,
                generation: 1,
                withdrawalRevision: 2,
                version: null,
            ))->assertOk();

            return Http::response($this->payload('b'), 200, ['Content-Type' => 'application/json']);
        });

        config()->set('smking.delivery.mode', 'on_demand');
        $this->artisan('smking:delivery:work')->assertExitCode(1);
        $this->assertSame('withdrawn', $this->delivery()->peek('cms-page', 'slug:article')->error);

        config()->set('smking.delivery.mode', 'legacy');
        $this->assertSame(CmsPage::STATUS_NOT_FOUND, $this->app->make(CmsClient::class)->forSlug('article')->status);
        Http::assertSentCount(1);
    }

    public function test_scope_mismatch_is_rejected_before_target_or_cache_mutation(): void
    {
        $target = $this->target();
        $payload = [
            'kind' => 'cms_delivery_v2',
            'contract' => '2',
            'deliveryId' => '12345678-1234-4123-8123-123456789abc',
            'deliveredAt' => gmdate('Y-m-d\TH:i:s').'.000Z',
            'sourceUrl' => 'https://api.test',
            'scope' => str_repeat('e', 64),
            'keyFingerprint' => hash('sha256', 'pk_test_key'),
            'targets' => [$target],
        ];

        $this->signed($payload)
            ->assertStatus(401)
            ->assertJsonPath('error', 'notification_scope_mismatch');
        $this->assertNull($this->app->make(DeliveryTargetState::class)->read('slug:article'));
        $this->assertSame('cache_miss', $this->delivery()->peek('cms-page', 'slug:article')->error);
    }

    public function test_legacy_aeo_notification_invalidates_both_versioned_path_formats(): void
    {
        config()->set('smking.delivery.aeo_enabled', true);
        Http::fake(function ($request) {
            $resource = str_contains($request->url(), '/markdown') ? 'markdown' : 'aeo';

            return Http::response($this->aeoPayload($resource), 200, ['Content-Type' => 'application/json']);
        });
        foreach (['aeo', 'markdown'] as $resource) {
            $this->assertNotNull($this->delivery()->refresh(
                $resource,
                'path:/products/article',
                new WaitBudget(500),
            )->snapshot);
        }

        $this->signed([
            'kind' => 'aeo',
            'paths' => ['/products/article'],
            'deliveryId' => bin2hex(random_bytes(16)),
            'deliveredAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ])->assertOk();

        foreach (['aeo', 'markdown'] as $resource) {
            $this->assertSame(
                'cache_miss',
                $this->delivery()->peek($resource, 'path:/products/article')->error,
            );
        }
        Http::assertSentCount(2);
    }

    public function test_older_success_cannot_clear_a_concurrent_cms_access_denial(): void
    {
        config()->set('smking.delivery.capacity', 2);
        $delivery = $this->delivery();
        $denied = null;
        Http::fake(function ($request) use ($delivery, &$denied) {
            if (($request['slug'] ?? null) === 'blocked') {
                return Http::response([
                    'status' => 'unavailable',
                    'error' => 'cms_disabled',
                    'denial' => ['contract' => '2', 'scope' => 'cms'],
                ], 403, ['Content-Type' => 'application/json']);
            }
            $denied = $delivery->refresh('cms-page', 'slug:blocked', new WaitBudget(500));

            return Http::response($this->payload('a'), 200, ['Content-Type' => 'application/json']);
        });

        $olderSuccess = $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500));

        $this->assertSame('access_denied', $denied?->error);
        $this->assertNotNull($olderSuccess->snapshot);
        $this->assertSame('access_denied', $delivery->peek('cms-page', 'slug:article')->error);
        Http::assertSentCount(2);
    }

    public function test_current_legacy_and_on_demand_modes_share_cold_read_capacity(): void
    {
        config()->set('smking.delivery.capacity', 1);
        config()->set('smking.delivery.mode', 'on_demand');
        $delivery = $this->delivery();
        $client = $this->app->make(CmsClient::class);
        $legacyDuringRefresh = null;
        Http::fake(function ($request) use ($client, &$legacyDuringRefresh) {
            if (str_contains($request->url(), '/api/v2/public/cms-page')) {
                config()->set('smking.delivery.mode', 'legacy');
                $legacyDuringRefresh = $client->forSlug('other');
                config()->set('smking.delivery.mode', 'on_demand');

                return Http::response($this->payload('a'), 200, ['Content-Type' => 'application/json']);
            }

            return Http::response([
                'status' => 'ready',
                'page' => ['slug' => 'other', 'bodyHtml' => '<main>Legacy other</main>'],
            ], 200, ['Content-Type' => 'application/json']);
        });

        $result = $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500));

        $this->assertNotNull($result->snapshot);
        $this->assertSame(CmsPage::STATUS_SERVER_ERROR, $legacyDuringRefresh?->status);
        Http::assertSentCount(1);
    }

    private function delivery(): OnDemandDelivery
    {
        return $this->app->make(OnDemandDelivery::class);
    }

    private function notifyLegacy(string $slug)
    {
        return $this->signed([
            'kind' => 'cms_page',
            'slugs' => [$slug],
            'deliveryId' => bin2hex(random_bytes(16)),
            'deliveredAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @param array<string, mixed> $target */
    private function notifyVersioned(array $target)
    {
        return $this->signed([
            'kind' => 'cms_delivery_v2',
            'contract' => '2',
            'deliveryId' => '12345678-1234-4123-8123-123456789abc',
            'deliveredAt' => gmdate('Y-m-d\TH:i:s').'.000Z',
            'sourceUrl' => 'https://api.test',
            'scope' => str_repeat('d', 64),
            'keyFingerprint' => hash('sha256', 'pk_test_key'),
            'targets' => [$target],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function signed(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/smking/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SMKING_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'upgrade-secret'),
        ], $body);
    }

    /** @return array<string, mixed> */
    private function target(
        string $action = 'update',
        int $revision = 1,
        int $generation = 1,
        int $withdrawalRevision = 0,
        ?string $version = 'b',
    ): array {
        return [
            'resource' => 'cms-page',
            'identifier' => 'slug:article',
            'action' => $action,
            'revision' => $revision,
            'generation' => $generation,
            'withdrawalRevision' => $withdrawalRevision,
            'contentVersion' => $version === null ? null : 'sha256:'.str_repeat($version, 64),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(string $version): array
    {
        $now = (int) floor(microtime(true) * 1000);
        $iso = static fn (int $milliseconds): string => gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000))
            .sprintf('.%03dZ', $milliseconds % 1000);

        return [
            'status' => 'ready',
            'page' => ['slug' => 'article', 'bodyHtml' => '<main>Version '.$version.'</main>'],
            'delivery' => [
                'contract' => '2',
                'content_version' => 'sha256:'.str_repeat($version, 64),
                'validated_at' => $iso($now),
                'fresh_until' => $iso($now + 300_000),
                'usable_until' => $iso($now + 3_600_000),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function aeoPayload(string $resource): array
    {
        $payload = $this->payload($resource);
        unset($payload['page']);
        $payload['delivery']['content_version'] = 'sha256:'.hash('sha256', $resource);
        if ($resource === 'aeo') {
            $payload['jsonLd'] = ['@type' => 'Product'];
            $payload['summary'] = 'Current';
        } else {
            $payload['document'] = [
                'path' => '/products/article',
                'body' => '# Current',
                'content_type' => 'text/markdown; charset=utf-8',
            ];
        }

        return $payload;
    }
}
