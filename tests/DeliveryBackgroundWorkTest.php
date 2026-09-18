<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\AeoClient;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Delivery\DeliveryReportOutbox;
use Smking\Laravel\Delivery\DeliveryWorklist;

class DeliveryBackgroundWorkTest extends TestCase
{
    private int $now = 1789344000000;

    private string $directory;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/smking-background-work-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->cache = new Repository(new FileStore(new Filesystem(), $this->directory));
        config()->set('cache.stores.delivery_test', ['driver' => 'file', 'path' => $this->directory]);
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.store', 'delivery_test');
        config()->set('smking.delivery.mode', 'on_demand');
        config()->set('smking.delivery.work_items', 100);
        config()->set('smking.delivery.work_max_jobs', 10);
        config()->set('smking.delivery.work_budget_ms', 5000);
        config()->set('smking.delivery.heartbeat_seconds', 180);
        config()->set('smking.webhook_secret', 'fixture-secret');
        config()->set('app.url', 'https://shop.example.test');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_worklist_requires_a_recent_worker_and_bounds_deduplicated_work(): void
    {
        Http::fake();
        $work = new DeliveryWorklist(
            cache: $this->cache,
            config: config(),
            clock: fn (): int => $this->now,
            maxItems: 2,
            heartbeatSeconds: 60,
        );

        $this->assertFalse($work->schedule('aeo', 'path:/first'));
        $this->assertFalse($work->status()['heartbeat_recent']);
        $this->assertSame(1, $work->status()['dropped']['background_unavailable']);

        $this->assertTrue($work->prepare());
        $this->assertTrue($work->schedule('aeo', 'path:/first'));
        $this->assertTrue($work->schedule('aeo', 'path:/first'));
        $this->assertTrue($work->schedule('cms-page', 'slug:article'));
        $this->assertFalse($work->schedule('markdown', 'path:/third'));

        $status = $work->status();
        $this->assertSame(2, $status['counts']['pending']);
        $this->assertSame(1, $status['dropped']['overflow']);

        $this->now += 60_001;
        $this->assertFalse($work->schedule('site-file', 'kind:robots'));
        $this->assertFalse($work->status()['heartbeat_recent']);
        Http::assertNothingSent();
    }

    public function test_cold_aeo_is_local_until_one_bounded_cli_worker_tick(): void
    {
        Http::fake([
            '*' => Http::response($this->aeoPayload('/products/article'), 200, ['Content-Type' => 'application/json']),
        ]);
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
        Http::assertNothingSent();

        $aeo = $this->app->make(AeoClient::class)->forPath('/products/article');
        $this->assertFalse($aeo->isReady());
        Http::assertNothingSent();

        $work = $this->app->make(DeliveryWorklist::class);
        $this->assertSame(1, $work->status()['counts']['pending']);
        $this->assertSame(1, $this->app->make(\Smking\Laravel\Delivery\DeliveryReportOutbox::class)->status()['pending_observations']);

        $exit = $this->artisan('smking:delivery:work')->run();
        $this->assertSame(0, $exit, json_encode($work->status(), JSON_UNESCAPED_UNICODE));

        $this->assertSame(0, $work->status()['counts']['pending']);
        $this->assertTrue($this->app->make(AeoClient::class)->forPath('/products/article')->isReady());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/public/aeo'));
    }

    public function test_failed_cold_cms_is_reported_and_deferred_without_legacy_retry(): void
    {
        Http::fake(['*' => Http::response('unavailable', 503, ['Content-Type' => 'text/plain'])]);
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);

        $page = $this->app->make(CmsClient::class)->forSlug('article');

        $this->assertSame('server_error', $page->status);
        $this->assertSame(1, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
        $this->assertSame(1, $this->app->make(DeliveryReportOutbox::class)->status()['pending_failures']['upstream']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/public/cms-page'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v1/'));
    }

    /** @return array<string, mixed> */
    private function aeoPayload(string $path): array
    {
        $now = (int) floor(microtime(true) * 1000);
        $iso = static fn (int $milliseconds): string => gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);

        return [
            'status' => 'ready',
            'jsonLd' => ['@type' => 'Product'],
            'summary' => 'Background ready',
            'delivery' => [
                'contract' => '2',
                'content_version' => 'sha256:'.hash('sha256', $path),
                'validated_at' => $iso($now),
                'fresh_until' => $iso($now + 300000),
                'usable_until' => $iso($now + 3600000),
            ],
        ];
    }
}
