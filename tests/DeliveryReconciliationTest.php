<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\Delivery\DeliveryReconciliation;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;

class DeliveryReconciliationTest extends TestCase
{
    private int $now;

    private string $directory;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = $this->time('2026-09-18 10:00:00');
        $this->directory = sys_get_temp_dir().'/smking-reconciliation-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->cache = new Repository(new FileStore(new Filesystem(), $this->directory));
        config()->set('app.timezone', 'Asia/Taipei');
        config()->set('smking.cache.enabled', true);
        config()->set('smking.delivery.mode', 'on_demand');
        config()->set('smking.delivery.notifications_enabled', false);
        config()->set('smking.delivery.capacity', 1);
        config()->set('smking.delivery.lease_seconds', 15);
        config()->set('smking.delivery.circuit_seconds', 30);
        config()->set('smking.delivery.connect_timeout', 0.5);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_daily_window_refreshes_known_successes_once_and_failure_preserves_them(): void
    {
        $revision = 1;
        $unavailable = false;
        Http::fake(function ($request) use (&$revision, &$unavailable) {
            if ($unavailable) {
                return Http::response('unavailable', 503, ['Content-Type' => 'text/plain']);
            }

            return str_contains($request->url(), '/cms-page')
                ? Http::response($this->cmsPayload('article', 'CMS '.$revision), 200, ['Content-Type' => 'application/json'])
                : Http::response($this->aeoPayload('/products/article', 'AEO '.$revision), 200, ['Content-Type' => 'application/json']);
        });

        $reconciliation = $this->reconciliation();
        $delivery = $this->delivery($reconciliation);
        $this->assertSame('CMS 1', $delivery->read('cms-page', 'slug:article', new WaitBudget(500))->snapshot?->payload['page']['title']);
        $this->assertSame('AEO 1', $delivery->refresh('aeo', 'path:/products/article', new WaitBudget(500))->snapshot?->payload['summary']);
        Http::assertSentCount(2);

        $minute = $reconciliation->scheduledMinute();
        $this->now = $minute === 0
            ? $this->time('2026-09-19 02:59:00')
            : $this->time(sprintf('2026-09-19 03:%02d:00', $minute - 1));
        $early = $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertFalse($early['eligible']);
        Http::assertSentCount(2);

        $revision = 2;
        $this->now = $this->time(sprintf('2026-09-19 03:%02d:00', $minute));
        $first = $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertTrue($first['eligible']);
        $this->assertSame(2, $first['checked']);
        $this->assertSame(2, $first['refreshed']);
        $this->assertSame(0, $first['failed']);
        Http::assertSentCount(4);
        $this->assertSame('CMS 2', $delivery->peek('cms-page', 'slug:article')->snapshot?->payload['page']['title']);
        $this->assertSame('AEO 2', $delivery->peek('aeo', 'path:/products/article')->snapshot?->payload['summary']);

        $again = $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertSame(0, $again['checked']);
        $this->assertSame('already_ran', $again['error']);
        Http::assertSentCount(4);

        $unavailable = true;
        $this->now = $this->time(sprintf('2026-09-20 03:%02d:00', $minute));
        $failed = $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertSame(2, $failed['checked']);
        $this->assertSame(0, $failed['refreshed']);
        $this->assertSame(2, $failed['failed']);
        Http::assertSentCount(6);
        $this->assertSame('CMS 2', $delivery->peek('cms-page', 'slug:article')->snapshot?->payload['page']['title']);
        $this->assertSame('AEO 2', $delivery->peek('aeo', 'path:/products/article')->snapshot?->payload['summary']);
    }

    public function test_cms_notification_only_skips_cms_daily_reconciliation(): void
    {
        $revision = 1;
        Http::fake(function ($request) use (&$revision) {
            return str_contains($request->url(), '/cms-page')
                ? Http::response($this->cmsPayload('article', 'CMS '.$revision), 200, ['Content-Type' => 'application/json'])
                : Http::response($this->aeoPayload('/products/article', 'AEO '.$revision), 200, ['Content-Type' => 'application/json']);
        });

        $reconciliation = $this->reconciliation();
        $delivery = $this->delivery($reconciliation);
        $this->assertNotNull($delivery->read('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $this->assertNotNull($delivery->refresh('aeo', 'path:/products/article', new WaitBudget(500))->snapshot);

        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('a', 64));
        $revision = 2;
        $minute = $reconciliation->scheduledMinute();
        $this->now = $this->time(sprintf('2026-09-19 03:%02d:00', $minute));

        $summary = $reconciliation->runBatch($delivery, 10, 5000);

        $this->assertTrue($summary['eligible']);
        $this->assertSame(1, $summary['checked']);
        $this->assertSame(1, $summary['refreshed']);
        $this->assertNull($summary['error']);
        Http::assertSentCount(3);
        $this->assertSame('CMS 1', $delivery->peek('cms-page', 'slug:article')->snapshot?->payload['page']['title']);
        $this->assertSame('AEO 2', $delivery->peek('aeo', 'path:/products/article')->snapshot?->payload['summary']);
    }

    public function test_cached_success_from_before_upgrade_is_registered_on_read(): void
    {
        Http::fake(fn () => Http::response(
            $this->cmsPayload('article', 'Existing CMS'),
            200,
            ['Content-Type' => 'application/json'],
        ));

        $this->assertNotNull($this->delivery()->read(
            'cms-page',
            'slug:article',
            new WaitBudget(500),
        )->snapshot);
        Http::assertSentCount(1);

        $reconciliation = $this->reconciliation();
        $delivery = $this->delivery($reconciliation);
        $this->assertNotNull($delivery->read('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $this->assertSame(1, $reconciliation->status()['known']);
        Http::assertSentCount(1);

        $minute = $reconciliation->scheduledMinute();
        $this->now = $this->time(sprintf('2026-09-19 03:%02d:00', $minute));
        $summary = $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertSame(1, $summary['checked']);
        Http::assertSentCount(2);
    }

    public function test_enabled_notification_without_valid_scope_does_not_disable_cms_check(): void
    {
        Http::fake(fn () => Http::response(
            $this->cmsPayload('article', 'CMS'),
            200,
            ['Content-Type' => 'application/json'],
        ));
        $reconciliation = $this->reconciliation();
        $delivery = $this->delivery($reconciliation);
        $this->assertNotNull($delivery->read('cms-page', 'slug:article', new WaitBudget(500))->snapshot);

        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', null);
        $minute = $reconciliation->scheduledMinute();
        $this->now = $this->time(sprintf('2026-09-19 03:%02d:00', $minute));

        $summary = $reconciliation->runBatch($delivery, 10, 5000);

        $this->assertSame(1, $summary['checked']);
        Http::assertSentCount(2);
    }

    public function test_package_registers_reconciliation_in_the_customer_timezone_window(): void
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'smking:delivery:reconcile'),
        ));

        $this->assertCount(1, $events);
        $minute = $this->app->make(DeliveryReconciliation::class)->scheduledMinute();
        $this->assertSame($minute.' 3 * * *', $events[0]->expression);
        $this->assertSame('Asia/Taipei', $events[0]->timezone);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    private function reconciliation(): DeliveryReconciliation
    {
        return new DeliveryReconciliation(
            cache: $this->cache,
            config: config(),
            clock: fn (): int => $this->now,
            maxItems: 100,
        );
    }

    private function delivery(?DeliveryReconciliation $reconciliation = null): OnDemandDelivery
    {
        return new OnDemandDelivery(
            cache: $this->cache,
            http: $this->app->make(\Illuminate\Http\Client\Factory::class),
            config: config(),
            clock: fn (): int => $this->now,
            reconciliation: $reconciliation,
        );
    }

    private function time(string $local): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone('Asia/Taipei')))->getTimestamp() * 1000;
    }

    /** @return array<string, mixed> */
    private function cmsPayload(string $slug, string $title): array
    {
        return [
            'status' => 'ready',
            'page' => [
                'slug' => $slug,
                'title' => $title,
                'bodyHtml' => '<h1>'.$title.'</h1>',
                'publishedAt' => '2026-09-01T00:00:00Z',
            ],
            'delivery' => $this->deliveryMetadata($slug.'|'.$title),
        ];
    }

    /** @return array<string, mixed> */
    private function aeoPayload(string $path, string $summary): array
    {
        return [
            'status' => 'ready',
            'jsonLd' => ['@type' => 'Product'],
            'summary' => $summary,
            'delivery' => $this->deliveryMetadata($path.'|'.$summary),
        ];
    }

    /** @return array<string, string> */
    private function deliveryMetadata(string $version): array
    {
        $iso = static fn (int $milliseconds): string => gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);

        return [
            'contract' => '2',
            'content_version' => 'sha256:'.hash('sha256', $version),
            'validated_at' => $iso($this->now),
            'fresh_until' => $iso($this->now + 300000),
            'usable_until' => $iso($this->now + 3600000),
        ];
    }
}
