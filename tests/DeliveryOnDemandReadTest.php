<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\Data\CmsPage;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;

class DeliveryOnDemandReadTest extends TestCase
{
    private const NOW = 1789171200000;

    private int $now = self::NOW;

    private string $directory;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/smking-on-demand-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->cache = new Repository(new FileStore(new Filesystem(), $this->directory));
        config()->set('smking.cache.enabled', true);
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

    public function test_cache_format_isolated_from_legacy_objects_and_future_formats(): void
    {
        $legacyKey = 'smking:cms:'.substr(hash('sha256', 'pk_test_key|https://api.test'), 0, 12).':article';
        $this->cache->put($legacyKey, new CmsPage('ready', bodyHtml: '<p>Legacy</p>'), 300);

        Http::fake([
            '*' => Http::sequence()
                ->push($this->cmsPayload('article', 'Format one'), 200, ['Content-Type' => 'application/json'])
                ->push($this->cmsPayload('article', 'Format two'), 200, ['Content-Type' => 'application/json']),
        ]);

        $formatOne = $this->delivery(cacheFormat: 1);
        $first = $formatOne->read('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('Format one', $first->snapshot?->payload['page']['title']);
        $this->assertSame('Format one', $formatOne->read('cms-page', 'slug:article', new WaitBudget(500))->snapshot?->payload['page']['title']);

        $formatTwo = $this->delivery(cacheFormat: 2);
        $second = $formatTwo->read('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('Format two', $second->snapshot?->payload['page']['title']);
        $this->assertSame('Format two', $formatTwo->read('cms-page', 'slug:article', new WaitBudget(500))->snapshot?->payload['page']['title']);
        Http::assertSentCount(2);
    }

    public function test_stale_success_survives_failed_refresh_without_extending_origin_deadlines(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->cmsPayload('article', 'Stable body'), 200, ['Content-Type' => 'application/json'])
                ->push('unavailable', 503, ['Content-Type' => 'text/plain']),
        ]);

        $delivery = $this->delivery();
        $fresh = $delivery->read('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('Stable body', $fresh->snapshot?->payload['page']['title']);
        $originalUsableUntil = $fresh->snapshot?->usableUntilMs;

        $this->now += 300000;

        $stale = $delivery->read('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertTrue($stale->refreshRequired);
        $this->assertSame('Stable body', $stale->snapshot?->payload['page']['title']);
        Http::assertSentCount(1);

        $failed = $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('upstream', $failed->error);

        $preserved = $delivery->read('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('Stable body', $preserved->snapshot?->payload['page']['title']);
        $this->assertSame($originalUsableUntil, $preserved->snapshot?->usableUntilMs);

        $blocked = $delivery->read('cms-page', 'slug:other', new WaitBudget(500));
        $this->assertSame('backoff', $blocked->error);
        Http::assertSentCount(2);
    }

    public function test_multiple_cold_reads_share_one_fractional_wait_budget(): void
    {
        $elapsed = 0.0;
        $timeouts = [];
        Http::fake(function ($request, $options) use (&$elapsed, &$timeouts) {
            $timeouts[] = $options['timeout'];
            $elapsed += 750;

            return Http::response(
                $this->cmsPayload((string) $request['slug'], 'Fetched'),
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        $budget = new WaitBudget(1000, static function () use (&$elapsed): float {
            return $elapsed;
        });
        $delivery = $this->delivery();

        $this->assertNotNull($delivery->read('cms-page', 'slug:first', $budget)->snapshot);
        $this->assertNotNull($delivery->read('cms-page', 'slug:second', $budget)->snapshot);
        $this->assertSame('budget_exhausted', $delivery->read('cms-page', 'slug:third', $budget)->error);
        $this->assertSame([1.0, 0.25], $timeouts);
    }

    public function test_same_page_and_cross_page_capacity_never_wait_or_start_extra_http(): void
    {
        $nested = [];
        $delivery = $this->delivery();
        $otherProcess = $this->delivery();

        Http::fake(function ($request) use ($otherProcess, &$nested) {
            $nested['same'] = $otherProcess->read('cms-page', 'slug:outer', new WaitBudget(500))->error;
            $nested['other'] = $otherProcess->read('cms-page', 'slug:other', new WaitBudget(500))->error;

            return Http::response(
                $this->cmsPayload((string) $request['slug'], 'Outer'),
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        $outer = $delivery->read('cms-page', 'slug:outer', new WaitBudget(500));

        $this->assertSame('Outer', $outer->snapshot?->payload['page']['title']);
        $this->assertSame(['same' => 'capacity', 'other' => 'capacity'], $nested);
        Http::assertSentCount(1);
    }

    public function test_invalid_response_never_enters_the_success_cache(): void
    {
        $invalid = $this->cmsPayload('wrong-slug', 'Wrong');
        Http::fake([
            '*' => Http::sequence()
                ->push($invalid, 200, ['Content-Type' => 'application/json'])
                ->push($this->cmsPayload('article', 'Recovered'), 200, ['Content-Type' => 'application/json']),
        ]);

        $delivery = $this->delivery();
        $first = $delivery->read('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('invalid_response', $first->error);

        $this->now += 30000;
        $second = $delivery->read('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('Recovered', $second->snapshot?->payload['page']['title']);
        Http::assertSentCount(2);
    }

    public function test_credential_and_resource_denials_have_separate_scope(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/cms-page')) {
                return Http::response(['status' => 'unavailable'], 403, ['Content-Type' => 'application/json']);
            }
            if (str_contains($request->url(), '/aeo')) {
                return Http::response($this->aeoPayload('/products/article'), 200, ['Content-Type' => 'application/json']);
            }

            return Http::response(['status' => 'unavailable'], 401, ['Content-Type' => 'application/json']);
        });

        $delivery = $this->delivery();
        $cms = $delivery->read('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame(403, $cms->httpStatus);
        $this->assertSame('access_denied', $cms->error);

        $aeo = $delivery->refresh('aeo', 'path:/products/article', new WaitBudget(500));
        $this->assertNotNull($aeo->snapshot);
        $this->assertSame('access_denied', $delivery->read('cms-page', 'slug:other', new WaitBudget(500))->error);

        $file = $delivery->read('site-file', 'kind:robots', new WaitBudget(500));
        $this->assertSame(401, $file->httpStatus);
        $this->assertSame('access_denied', $file->error);
        $this->assertSame('access_denied', $delivery->refresh('aeo', 'path:/other', new WaitBudget(500))->error);
        Http::assertSentCount(3);
    }

    public function test_explicit_aeo_denial_stops_related_surfaces_but_not_cms(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/aeo')) {
                return Http::response([
                    'status' => 'unavailable',
                    'error' => 'aeo_disabled',
                    'denial' => ['contract' => '2', 'scope' => 'aeo'],
                ], 403, ['Content-Type' => 'application/json']);
            }

            return Http::response($this->cmsPayload('article', 'Visible'), 200, ['Content-Type' => 'application/json']);
        });

        $delivery = $this->delivery();
        $denied = $delivery->refresh('aeo', 'path:/products/article', new WaitBudget(500));
        $this->assertSame('aeo', $denied->denialScope);
        $this->assertSame('access_denied', $delivery->refresh('markdown', 'path:/products/article', new WaitBudget(500))->error);
        $this->assertSame('access_denied', $delivery->read('site-file', 'kind:robots', new WaitBudget(500))->error);

        $cms = $delivery->read('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('Visible', $cms->snapshot?->payload['page']['title']);
        Http::assertSentCount(2);
    }

    public function test_unsupported_process_local_cache_fails_closed_without_http(): void
    {
        Http::fake();
        $delivery = new OnDemandDelivery(
            cache: $this->app->make('cache')->store('array'),
            http: $this->app->make(\Illuminate\Http\Client\Factory::class),
            config: config(),
            clock: fn (): int => $this->now,
        );

        $result = $delivery->read('cms-page', 'slug:article', new WaitBudget(500));

        $this->assertSame('capacity', $result->error);
        Http::assertNothingSent();
    }

    public function test_disabled_cache_fails_closed_without_http(): void
    {
        config()->set('smking.cache.enabled', false);
        Http::fake();

        $result = $this->delivery()->read('cms-page', 'slug:article', new WaitBudget(500));

        $this->assertSame('configuration', $result->error);
        Http::assertNothingSent();
    }

    private function delivery(int $cacheFormat = 1): OnDemandDelivery
    {
        return new OnDemandDelivery(
            cache: $this->cache,
            http: $this->app->make(\Illuminate\Http\Client\Factory::class),
            config: config(),
            clock: fn (): int => $this->now,
            cacheFormat: $cacheFormat,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cmsPayload(string $slug, string $title): array
    {
        $iso = static fn (int $milliseconds): string => gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);

        return [
            'status' => 'ready',
            'page' => [
                'slug' => $slug,
                'title' => $title,
                'bodyHtml' => '<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>',
                'publishedAt' => '2026-09-01T00:00:00Z',
            ],
            'delivery' => [
                'contract' => '2',
                'content_version' => 'sha256:'.hash('sha256', $slug.'|'.$title),
                'validated_at' => $iso(self::NOW),
                'fresh_until' => $iso(self::NOW + 300000),
                'usable_until' => $iso(self::NOW + 3600000),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aeoPayload(string $path): array
    {
        $iso = static fn (int $milliseconds): string => gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);

        return [
            'status' => 'ready',
            'jsonLd' => ['@type' => 'Product'],
            'summary' => 'Ready',
            'delivery' => [
                'contract' => '2',
                'content_version' => 'sha256:'.hash('sha256', $path),
                'validated_at' => $iso($this->now),
                'fresh_until' => $iso($this->now + 300000),
                'usable_until' => $iso($this->now + 3600000),
            ],
        ];
    }
}
