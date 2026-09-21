<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Smking\Laravel\Delivery\DeliveryCapacity;
use Smking\Laravel\Delivery\DeliveryLocalStore;
use Smking\Laravel\Delivery\DeliveryReconciliation;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;

class DeliveryReconciliationRecoveryTest extends TestCase
{
    private int $now;
    private float $monotonic = 0;
    private string $cacheDirectory;
    private Repository $cache;
    private \Closure $respond;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.timezone', 'Asia/Taipei');
        config()->set('smking.cache.enabled', true);
        config()->set('smking.delivery.mode', 'on_demand');
        config()->set('smking.delivery.notifications_enabled', false);
        $this->now = $this->time('2026-09-18 10:00:00');
        $this->cacheDirectory = sys_get_temp_dir().'/smking-reconcile-recovery-'.bin2hex(random_bytes(8));
        mkdir($this->cacheDirectory, 0700, true);
        $this->cache = new Repository(new FileStore(new Filesystem(), $this->cacheDirectory));
        Http::preventStrayRequests();
        $this->respond = fn ($request) => Http::response($this->payload($request['slug']), 200);
        Http::fake(fn ($request) => ($this->respond)($request));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->cacheDirectory);
        parent::tearDown();
    }

    public function test_time_budget_stops_the_slice_and_next_slice_resumes_the_tail(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 4);
        $this->night($reconciliation);
        $this->respond = function ($request) {
            $this->monotonic += 100;
            return Http::response($this->payload($request['slug']), 200);
        };
        $first = $reconciliation->runBatch($delivery, 10, 100);
        $this->assertSame(1, $first['checked']);
        $this->assertSame(3, $first['pending']);
        $this->assertFalse($first['complete']);
        $second = $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertSame(3, $second['checked']);
        $this->assertTrue($second['complete']);
        $this->assertSame(4, $reconciliation->status()['checked']);
        Http::assertSentCount(8);
    }

    public function test_budget_exhausted_while_claiming_does_not_consume_an_unattempted_item(): void
    {
        $calls = 0;
        $exhaust = true;
        $reconciliation = $this->reconciliation(clock: function () use (&$calls, &$exhaust): float {
            if ($exhaust && ++$calls === 3) $this->monotonic += 100;
            return $this->monotonic;
        });
        $delivery = $this->prepareContent($reconciliation, 1);
        $this->night($reconciliation);
        $result = $reconciliation->runBatch($delivery, 10, 100);
        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['pending']);
        $this->assertFalse($reconciliation->status()['in_flight']);
        Http::assertSentCount(1);
        $exhaust = false;
        $this->assertTrue($reconciliation->runBatch($delivery, 10, 100)['complete']);
        Http::assertSentCount(2);
    }

    public function test_unattempted_tail_has_priority_on_the_next_day(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 4);
        $this->night($reconciliation);
        $this->assertSame(2, $reconciliation->runBatch($delivery, 2, 5000)['pending']);
        $this->now += 86400000;
        $slugs = [];
        $this->respond = function ($request) use (&$slugs) {
            $slugs[] = $request['slug'];
            return Http::response($this->payload($request['slug']), 200);
        };
        $this->assertSame(2, $reconciliation->runBatch($delivery, 2, 5000)['pending']);
        $this->assertSame(['article-2', 'article-3'], $slugs);
        $this->assertSame('2026-09-19', $reconciliation->status()['last_incomplete_on']);
        $this->assertNull($reconciliation->status()['last_completed_on']);
    }

    public function test_local_timezone_window_closes_without_starting_another_request(): void
    {
        config()->set('app.timezone', 'America/New_York');
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 2);
        $this->now = $this->time('2026-09-19 02:59:00');
        $this->assertSame('outside_window', $reconciliation->runBatch($delivery, 10, 5000)['error']);
        $this->now = $this->time('2026-09-19 03:59:59');
        $this->respond = function ($request) {
            $this->now += 2000;
            return Http::response($this->payload($request['slug']), 200);
        };
        $result = $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['pending']);
        $this->assertSame('window_incomplete', $result['error']);
        $this->assertSame('outside_window', $reconciliation->runBatch($delivery, 10, 5000)['error']);
        $this->assertSame('2026-09-19', $reconciliation->status()['round_date']);
        Http::assertSentCount(3);
    }

    public function test_concurrent_runner_is_rejected_even_when_cache_is_flushed(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 1);
        $this->night($reconciliation);
        $this->respond = function ($request) {
            $this->cache->flush();
            $other = $this->reconciliation();
            $this->assertSame('runner_busy', $other->runBatch($this->delivery($other), 10, 5000)['error']);
            $this->assertSame('article-0', $this->delivery($other)->peek('cms-page', 'slug:article-0')->snapshot?->payload['page']['slug']);
            return Http::response($this->payload($request['slug']), 200);
        };
        $this->assertTrue($reconciliation->runBatch($delivery, 10, 5000)['complete']);
        Http::assertSentCount(2);
    }

    public function test_interrupted_claim_is_visible_and_not_repeated_that_day(): void
    {
        $calls = 0;
        $reconciliation = $this->reconciliation(clock: function () use (&$calls): float {
            if (++$calls === 3) throw new RuntimeException('simulated process interruption after durable claim');
            return $this->monotonic;
        });
        $delivery = $this->prepareContent($reconciliation, 3);
        $this->night($reconciliation);
        $this->assertSame('reconciliation_unavailable', $reconciliation->runBatch($delivery, 10, 5000)['error']);
        $this->assertSame('attempt_incomplete', $reconciliation->status()['error']);
        Http::assertSentCount(3);
        $restarted = $this->reconciliation();
        $result = $restarted->runBatch($this->delivery($restarted), 10, 5000);
        $this->assertSame(2, $result['checked']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame('refresh_failed', $result['error']);
        $this->assertFalse($result['complete']);
        $this->assertSame(1, $restarted->status()['failed']);
        $this->assertSame(0, $restarted->runBatch($delivery, 10, 5000)['checked']);
        Http::assertSentCount(5);
    }

    public function test_failed_attempt_is_not_a_completed_round_and_is_not_retried_that_day(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 1);
        $this->night($reconciliation);
        $this->respond = fn () => Http::response('unavailable', 503);
        $first = $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertSame(1, $first['failed']);
        $this->assertSame(0, $first['pending']);
        $this->assertFalse($first['complete']);
        $this->assertSame('refresh_failed', $first['error']);
        $this->assertSame(0, $reconciliation->runBatch($delivery, 10, 5000)['checked']);
        $this->assertSame('Saved article-0', $delivery->peek('cms-page', 'slug:article-0')->snapshot?->payload['page']['title']);
        $this->assertNull($reconciliation->status()['last_completed_on']);
        Http::assertSentCount(2);
    }

    public function test_busy_capacity_does_not_consume_the_daily_attempt(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 1);
        $this->night($reconciliation);
        config()->set('smking.delivery.capacity', 1);
        (new DeliveryCapacity($this->cache, config()))->run('cms-page', 'slug:occupied', function () use ($reconciliation, $delivery): void {
            $result = $reconciliation->runBatch($delivery, 10, 5000);
            $this->assertSame(0, $result['checked']);
            $this->assertSame(1, $result['pending']);
            $this->assertSame('capacity', $result['error']);
        });
        $this->assertTrue($reconciliation->runBatch($delivery, 10, 5000)['complete']);
        Http::assertSentCount(2);
    }

    public function test_circuit_backoff_leaves_unattempted_tail_for_the_next_slice(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 3);
        $this->night($reconciliation);
        $this->respond = fn () => Http::response('unavailable', 503);
        $result = $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(2, $result['pending']);
        $this->now += 31000;
        $slugs = [];
        $this->respond = function ($request) use (&$slugs) {
            $slugs[] = $request['slug'];
            return Http::response($this->payload($request['slug']), 200);
        };
        $this->assertSame(2, $reconciliation->runBatch($delivery, 10, 5000)['checked']);
        $this->assertSame(['article-1', 'article-2'], $slugs);
        $this->assertFalse($reconciliation->status()['complete']);
        Http::assertSentCount(6);
    }

    public function test_missing_index_blocks_readiness_and_does_not_delete_existing_content(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 1);
        $this->seedLegacyIndex(['article-0']);
        unlink(config('smking.delivery.local_store_path').'/'.hash('sha256', $this->key()).'.json');
        $this->night($reconciliation);
        $this->assertSame('registry_missing', $reconciliation->status()['error']);
        $this->assertSame('registry_missing', $reconciliation->runBatch($delivery, 10, 5000)['error']);
        $this->assertNotNull($delivery->read('cms-page', 'slug:article-0', new WaitBudget(500))->snapshot);
        config()->set('smking.delivery.mode', 'legacy');
        $this->assertSame('registry_missing', $reconciliation->importLegacy($delivery)['error']);
        Http::assertSentCount(1);
    }

    public function test_invalid_index_never_becomes_an_empty_completed_round(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 1);
        $local = new DeliveryLocalStore(config('smking.delivery.local_store_path'));
        $record = $local->read($this->key());
        $record['round']['checked'] = 1; // Structurally valid envelope, inconsistent outcome counts.
        $local->write($this->key(), $record);
        $this->night($reconciliation);
        $this->assertSame('reconciliation_unavailable', $reconciliation->status()['error']);
        $this->assertFalse($reconciliation->runBatch($delivery, 10, 5000)['complete']);
        Http::assertSentCount(1);
    }

    public function test_full_index_rejects_unregistered_content_and_explicit_preparation_can_recover(): void
    {
        $reconciliation = $this->reconciliation(maxItems: 1);
        $delivery = $this->prepareContent($reconciliation, 1);
        $this->assertSame('registry_unavailable', $delivery->refresh('cms-page', 'slug:article-1', new WaitBudget(500))->error);
        $this->assertNull($delivery->peek('cms-page', 'slug:article-1')->snapshot);
        $this->assertNotNull($delivery->peek('cms-page', 'slug:article-0')->snapshot);
        $this->assertSame('registry_full', $reconciliation->status()['error']);
        $larger = $this->reconciliation(maxItems: 2);
        $this->assertNotNull($this->delivery($larger)->refresh('cms-page', 'slug:article-1', new WaitBudget(500))->snapshot);
        $this->assertNull($larger->status()['error']);
        $this->assertSame(2, $larger->status()['known']);
    }

    public function test_index_lock_contention_does_not_replace_successful_content(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 1);
        $this->now += 86400000;
        $this->respond = fn ($request) => Http::response($this->payload($request['slug'], 'New'), 200);
        $local = new DeliveryLocalStore(config('smking.delivery.local_store_path'));
        $local->locked($this->key(), function () use ($delivery): void {
            $this->assertSame('registry_unavailable', $delivery->refresh('cms-page', 'slug:article-0', new WaitBudget(500))->error);
            $this->assertSame('Saved article-0', $delivery->peek('cms-page', 'slug:article-0')->snapshot?->payload['page']['title']);
        });
        $this->assertSame('New', $delivery->refresh('cms-page', 'slug:article-0', new WaitBudget(500))->snapshot?->payload['page']['title']);
    }

    public function test_explicit_legacy_index_import_is_local_only_durable_and_not_a_new_remote_check(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent(null, 2);
        $this->seedLegacyIndex(['article-0', 'article-1']);
        $this->night($reconciliation);
        config()->set('smking.delivery.mode', 'legacy');
        $this->app->instance(DeliveryReconciliation::class, $reconciliation);
        $this->app->instance(OnDemandDelivery::class, $delivery);
        $this->assertSame(0, Artisan::call('smking:delivery:reconcile', ['--import-index' => true]));
        $this->assertSame(2, json_decode(Artisan::output(), true)['imported']);
        $this->assertSame(2, $reconciliation->status()['pending']);
        $this->assertNotNull($this->cache->get($this->key()));
        Http::assertSentCount(2);
        $this->cache->flush();
        $this->assertSame(2, $this->reconciliation()->status()['known']);
        config()->set('smking.delivery.mode', 'on_demand');
        $this->assertTrue($reconciliation->runBatch($this->delivery($reconciliation), 10, 5000)['complete']);
    }

    public function test_partial_import_remains_blocked_until_missing_local_content_is_prepared(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent(null, 1);
        $this->seedLegacyIndex(['article-0', 'article-1']);
        config()->set('smking.delivery.mode', 'legacy');
        $this->assertSame('legacy_content_not_ready', $reconciliation->importLegacy($delivery)['error']);
        $this->assertSame('legacy_content_not_ready', $this->reconciliation()->status()['error']);
        $this->assertNotNull($delivery->refresh('cms-page', 'slug:article-1', new WaitBudget(500))->snapshot);
        $this->assertNull($reconciliation->importLegacy($delivery)['error']);
        $this->assertSame(2, $reconciliation->status()['known']);
        Http::assertSentCount(2);
    }

    public function test_status_is_read_only_and_uninitialized_round_cannot_pass(): void
    {
        $reconciliation = $this->reconciliation();
        $this->app->instance(DeliveryReconciliation::class, $reconciliation);
        $this->assertSame(1, Artisan::call('smking:delivery:reconcile', ['--status' => true]));
        $this->assertSame('registry_uninitialized', json_decode(Artisan::output(), true)['error']);
        $this->assertDirectoryDoesNotExist(config('smking.delivery.local_store_path'));
        $this->night($reconciliation);
        $this->assertSame('registry_uninitialized', $reconciliation->runBatch($this->delivery($reconciliation), 10, 5000)['error']);
        $this->assertFalse($reconciliation->status()['complete']);
        Http::assertNothingSent();
    }

    public function test_registry_is_isolated_by_source_and_key(): void
    {
        $this->prepareContent($this->reconciliation(), 1);
        config()->set('smking.base_url', 'https://different.test');
        $this->assertSame('registry_uninitialized', $this->reconciliation()->status()['error']);
        config()->set('smking.base_url', 'https://api.test');
        config()->set('smking.api_key', 'pk_different_key');
        $this->assertSame(0, $this->reconciliation()->status()['known']);
        config()->set('smking.api_key', 'pk_test_key');
        $this->assertSame(1, $this->reconciliation()->status()['known']);
        Http::assertSentCount(1);
    }

    public function test_confirmed_missing_content_leaves_the_index_and_fresh_missing_is_not_enrolled(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 1);
        $this->night($reconciliation);
        $this->respond = function ($request) {
            $payload = $this->payload($request['slug']);
            $payload['status'] = 'not_found';
            $until = gmdate('Y-m-d\TH:i:s', intdiv($this->now, 1000) + 60).'.000Z';
            $payload['delivery']['fresh_until'] = $until;
            $payload['delivery']['usable_until'] = $until;
            unset($payload['page']);
            return Http::response($payload, 404);
        };
        $this->assertTrue($reconciliation->runBatch($delivery, 10, 5000)['complete']);
        $this->assertSame(0, $reconciliation->status()['known']);
        $again = $delivery->refresh('cms-page', 'slug:article-0', new WaitBudget(500));
        $this->assertNull($again->error);
        $this->assertSame(404, $again->httpStatus);
        Http::assertSentCount(2);
    }

    public function test_doctor_distinguishes_partial_successful_and_failed_rounds(): void
    {
        $reconciliation = $this->reconciliation();
        $delivery = $this->prepareContent($reconciliation, 2);
        $this->night($reconciliation);
        $this->app->instance(DeliveryReconciliation::class, $reconciliation);
        $unavailable = false;
        $this->respond = function ($request) use (&$unavailable) {
            if (str_contains($request->url(), '/api/v1/')) return Http::response([], 400);
            return $unavailable ? Http::response('unavailable', 503) : Http::response($this->payload($request['slug']), 200);
        };
        $check = function (): array {
            Artisan::call('smking:doctor', ['--json' => true]);
            return array_values(array_filter(json_decode(Artisan::output(), true)['checks'],
                static fn ($check) => $check['label'] === 'Daily delivery reconciliation'))[0];
        };
        $reconciliation->runBatch($delivery, 1, 5000);
        $this->assertSame('info', $check()['status']);
        $this->assertStringContainsString('pending=1', $check()['detail']);
        $reconciliation->runBatch($delivery, 1, 5000);
        $this->assertSame('pass', $check()['status']);
        $this->assertStringContainsString('last_completed=2026-09-19', $check()['detail']);
        $this->now += 86400000;
        $unavailable = true;
        $reconciliation->runBatch($delivery, 10, 5000);
        $this->assertSame('info', $check()['status']);
        $this->assertStringContainsString('failed=1', $check()['detail']);
        $this->assertStringContainsString('error=refresh_failed', $check()['detail']);
        $this->assertStringContainsString('last_completed=2026-09-19', $check()['detail']);
    }

    private function reconciliation(int $maxItems = 500, ?\Closure $clock = null): DeliveryReconciliation
    {
        return new DeliveryReconciliation($this->cache, config(), fn () => $this->now, $maxItems,
            monotonicClock: $clock ?? fn () => $this->monotonic);
    }

    private function delivery(?DeliveryReconciliation $reconciliation): OnDemandDelivery
    {
        return new OnDemandDelivery($this->cache, $this->app->make(Factory::class), config(),
            clock: fn () => $this->now, reconciliation: $reconciliation);
    }

    private function prepareContent(?DeliveryReconciliation $reconciliation, int $count): OnDemandDelivery
    {
        $delivery = $this->delivery($reconciliation);
        for ($i = 0; $i < $count; $i++) {
            $this->assertNotNull($delivery->refresh('cms-page', 'slug:article-'.$i, new WaitBudget(500))->snapshot);
        }
        return $delivery;
    }

    private function night(DeliveryReconciliation $reconciliation): void
    {
        $this->now = $this->time(sprintf('2026-09-19 03:%02d:00', $reconciliation->scheduledMinute()));
    }

    private function time(string $time): int
    {
        return (new DateTimeImmutable($time, new DateTimeZone(config('app.timezone'))))->getTimestamp() * 1000;
    }

    private function key(): string
    {
        return 'smking:delivery:v2:reconciliation:'.substr(hash('sha256', config('smking.api_key').'|'.rtrim(config('smking.base_url'), '/')), 0, 24);
    }

    private function seedLegacyIndex(array $slugs): void
    {
        $items = [];
        foreach ($slugs as $slug) {
            $items[hash('sha256', 'cms-page|slug:'.$slug)] = ['resource' => 'cms-page', 'identifier' => 'slug:'.$slug,
                'last_checked_on' => '2026-09-18', 'last_success_at' => $this->now, 'last_attempt_at' => $this->now];
        }
        $this->cache->forever($this->key(), ['format' => 2, 'last_run_on' => null, 'last_run_at' => null, 'items' => $items]);
    }

    private function payload(string $slug, ?string $title = null): array
    {
        $iso = static fn (int $ms): string => gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)).'.000Z';
        return ['status' => 'ready', 'page' => ['slug' => $slug, 'title' => $title ?? 'Saved '.$slug, 'bodyHtml' => '<main>Saved</main>'],
            'delivery' => ['contract' => '2', 'content_version' => 'sha256:'.hash('sha256', $slug.'|'.$title),
                'validated_at' => $iso($this->now), 'fresh_until' => $iso($this->now + 300000), 'usable_until' => $iso($this->now + 3600000)]];
    }
}
