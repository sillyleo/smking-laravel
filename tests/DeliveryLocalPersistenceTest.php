<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Smking\Laravel\Delivery\DeliveryTargetState;
use Smking\Laravel\Delivery\DeliverySnapshot;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;

class DeliveryLocalPersistenceTest extends TestCase
{
    private string $directory;

    private Repository $cache;

    private int $now = 1789171200000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/smking-local-persistence-'.bin2hex(random_bytes(8));
        $this->cache = new Repository(new FileStore(new Filesystem(), $this->directory.'/cache'));
        config()->set('smking.cache.enabled', true);
        config()->set('cache.stores.local_test', ['driver' => 'file', 'path' => $this->directory.'/cache']);
        config()->set('smking.cache.store', 'local_test');
        config()->set('smking.delivery.local_store_path', $this->directory.'/content');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_all_content_survives_cache_flush_and_new_reader_after_thirty_days(): void
    {
        $resources = ['cms-page' => 'slug:article', 'aeo' => 'path:/article', 'markdown' => 'path:/article', 'site-file' => 'kind:sitemap'];
        Http::fake(fn ($request) => Http::response(
            $this->payload(basename(parse_url($request->url(), PHP_URL_PATH))),
            200, ['Content-Type' => 'application/json'],
        ));
        foreach ($resources as $resource => $identifier) {
            $this->assertNotNull($this->delivery()->refresh($resource, $identifier, new WaitBudget(500))->snapshot);
        }
        $this->assertTrue($this->cache->flush());
        $this->now += 30 * 86_400_000;
        $reader = $this->delivery();
        foreach ($resources as $resource => $identifier) {
            $result = $reader->read($resource, $identifier, new WaitBudget(500));
            $this->assertSame(200, $result->httpStatus, $resource.': '.$result->error);
            $this->assertSame('ready', $result->snapshot?->payload['status']);
        }
        Http::assertSentCount(4);
    }

    #[DataProvider('contentResources')]
    public function test_valid_missing_state_survives_cache_flush_and_time(string $resource, string $identifier): void
    {
        Http::fake(fn () => Http::response(
            $this->missingPayload($resource),
            404,
            ['Content-Type' => 'application/json'],
        ));

        $delivery = $this->delivery();
        $first = $delivery->refresh($resource, $identifier, new WaitBudget(500));
        $this->assertSame(404, $first->httpStatus);
        $this->assertSame('not_found', $first->snapshot?->payload['status']);

        $this->assertTrue($this->cache->flush());
        $this->now += 31 * 86_400_000;
        $persisted = $this->delivery()->peek($resource, $identifier);
        $this->assertSame(404, $persisted->httpStatus, $resource.': '.$persisted->error);
        $this->assertSame('not_found', $persisted->snapshot?->payload['status']);
        Http::assertSentCount(1);
    }

    public static function contentResources(): array
    {
        return [
            ['cms-page', 'slug:article'],
            ['aeo', 'path:/article'],
            ['markdown', 'path:/article'],
            ['site-file', 'kind:sitemap'],
        ];
    }

    public function test_withdrawal_and_version_fence_survive_cache_flush(): void
    {
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('a', 64));
        Http::fake(fn () => Http::response($this->payload('cms-page'), 200, ['Content-Type' => 'application/json']));
        $this->assertNotNull($this->delivery()->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $target = [
            'resource' => 'cms-page', 'identifier' => 'slug:article', 'action' => 'withdraw',
            'revision' => 2, 'generation' => 0, 'withdrawalRevision' => 2, 'contentVersion' => null,
        ];
        $this->assertSame('applied', $this->targets()->apply($target)['status']);
        $this->cache->flush();
        $this->assertSame('withdrawn', $this->delivery()->peek('cms-page', 'slug:article')->error);
        $target['action'] = 'update';
        $target['revision'] = 1;
        $target['generation'] = 1;
        $target['withdrawalRevision'] = 0;
        $target['contentVersion'] = 'sha256:'.str_repeat('a', 64);
        $this->assertSame('obsolete', $this->targets()->apply($target)['status']);
        config()->set('smking.delivery.notifications_enabled', false);
        $this->assertSame('withdrawn', $this->delivery()->peek('cms-page', 'slug:article')->error);
        Http::assertSentCount(1);
    }

    public function test_credential_denial_survives_cache_flush(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->payload('cms-page'), 200, ['Content-Type' => 'application/json'])
            ->push(['status' => 'unavailable', 'error' => 'invalid_key'], 401, ['Content-Type' => 'application/json'])]);
        $this->assertNotNull($this->delivery()->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $this->assertSame('access_denied', $this->delivery()->refresh('aeo', 'path:/article', new WaitBudget(500))->error);
        $this->cache->flush();
        $this->assertSame('access_denied', $this->delivery()->peek('cms-page', 'slug:article')->error);
        Http::assertSentCount(2);
    }

    #[DataProvider('unverifiedDenials')]
    public function test_unverified_denial_never_hides_persisted_content(
        int $status,
        string|array $body,
        string $contentType,
    ): void {
        $requests = 0;
        Http::fake(function () use (&$requests, $status, $body, $contentType) {
            $requests++;

            return $requests === 2
                ? Http::response($body, $status, ['Content-Type' => $contentType])
                : Http::response($this->payload('cms-page'), 200, ['Content-Type' => 'application/json']);
        });

        $delivery = $this->delivery();
        $this->assertNotNull($delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);

        $this->now += 400_000;
        $blocked = $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertSame('upstream', $blocked->error);

        $this->cache->flush();
        $this->now += 31 * 86_400_000;
        $this->assertSame('<main>Saved</main>', $delivery->peek('cms-page', 'slug:article')->snapshot?->payload['page']['bodyHtml']);
        $recovered = $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500));
        $this->assertNotNull($recovered->snapshot, (string) $recovered->error);
        Http::assertSentCount(3);
    }

    public static function unverifiedDenials(): array
    {
        return [
            'proxy html 401' => [401, '<html><title>Proxy challenge</title></html>', 'text/html'],
            'proxy html 403' => [403, '<html><title>Proxy challenge</title></html>', 'text/html'],
            'incomplete json 401' => [401, ['status' => 'unavailable'], 'application/json'],
            'incomplete json 403' => [403, ['status' => 'unavailable', 'error' => 'cms_disabled'], 'application/json'],
        ];
    }

    public function test_failed_replacement_keeps_last_good_body(): void
    {
        Http::fake(function () {
            return Http::response($this->payload('cms-page'), 200, ['Content-Type' => 'application/json']);
        });
        $this->assertNotNull($this->delivery()->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $this->now += 400000;
        Http::fake(function () {
            chmod($this->directory.'/content', 0500);
            return Http::response($this->payload('cms-page'), 200, ['Content-Type' => 'application/json']);
        });
        try {
            $this->assertSame('cache_unavailable', $this->delivery()->refresh('cms-page', 'slug:article', new WaitBudget(500))->error);
            $this->assertSame('<main>Saved</main>', $this->delivery()->peek('cms-page', 'slug:article')->snapshot?->payload['page']['bodyHtml']);
        } finally {
            chmod($this->directory.'/content', 0700);
        }
        $this->assertSame([], glob($this->directory.'/content/*.tmp'));
    }

    public function test_cache_unavailable_does_not_block_saved_content(): void
    {
        Http::fake(fn () => Http::response($this->payload('cms-page'), 200, ['Content-Type' => 'application/json']));
        $this->assertNotNull($this->delivery()->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $unavailable = new class($this->cache->getStore()) extends Repository {
            public function get($key, $default = null): mixed
            {
                throw new \RuntimeException('cache offline');
            }
        };
        $reader = new OnDemandDelivery($unavailable, $this->app->make(Factory::class), config(), fn (): int => $this->now);
        $this->assertSame(200, $reader->read('cms-page', 'slug:article', new WaitBudget(500))->httpStatus);
        Http::assertSentCount(1);
    }

    public function test_explicit_import_preserves_old_content_without_http_or_overwriting_a_tombstone(): void
    {
        Http::fake();
        $state = $this->seedOldCache();
        $this->assertSame('cache_miss', $this->delivery()->peek('cms-page', 'slug:article')->error);
        $this->assertNotNull($this->delivery()->importCached('cms-page', 'slug:article')->snapshot);
        $this->assertSame($state, $this->cache->get($this->oldStateKey()));
        $this->cache->flush();
        $this->assertNotNull($this->delivery()->peek('cms-page', 'slug:article')->snapshot);
        $this->assertTrue($this->delivery()->invalidate('cms-page', 'slug:article'));
        // A stale cache backup must not undo a durable invalidation.
        $this->cache->forever($this->oldStateKey(), $state);
        $this->assertSame('cache_miss', $this->delivery()->importCached('cms-page', 'slug:article')->error);
        Http::assertNothingSent();
    }

    public function test_import_moves_cached_withdrawal_before_content(): void
    {
        Http::fake();
        $this->seedOldCache();
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('a', 64));
        $this->cache->forever('smking:delivery:v2:target:'.$this->site().':'.hash('sha256', 'slug:article'), [
            'format' => 1, 'token' => str_repeat('b', 32), 'updated_at' => $this->now,
            'target' => ['resource' => 'cms-page', 'identifier' => 'slug:article', 'action' => 'withdraw',
                'revision' => 2, 'generation' => 0, 'withdrawalRevision' => 2, 'contentVersion' => null],
        ]);
        $this->assertSame('withdrawn', $this->delivery()->importCached('cms-page', 'slug:article')->error);
        $this->cache->flush();
        $this->assertSame('withdrawn', $this->delivery()->peek('cms-page', 'slug:article')->error);
        $direct = new OnDemandDelivery($this->cache, $this->app->make(Factory::class), config(), fn (): int => $this->now);
        $this->assertSame('withdrawn', $direct->peek('cms-page', 'slug:article')->error);
        Http::assertNothingSent();
    }

    public function test_import_moves_cached_denial_before_content_and_rejects_incompatible_payloads(): void
    {
        Http::fake();
        $this->seedOldCache();
        $this->cache->forever('smking:delivery:v2:credential:'.$this->site(), ['format' => 1, 'denied' => true, 'status' => 401]);
        $this->assertSame('access_denied', $this->delivery()->importCached('cms-page', 'slug:article')->error);
        $this->cache->flush();
        $this->assertSame('access_denied', $this->delivery()->peek('cms-page', 'slug:article')->error);

        config()->set('smking.api_key', 'pk_another');
        $state = $this->seedOldCache();
        $state['success']['payload']['preview'] = true;
        $this->cache->forever($this->oldStateKey(), $state);
        $this->assertSame('cache_unavailable', $this->delivery()->importCached('cms-page', 'slug:article')->error);
        $this->assertFalse($this->delivery()->hasState('cms-page', 'slug:article'));
        Http::assertNothingSent();
    }

    public function test_cli_import_validates_entire_bounded_input_and_never_changes_mode(): void
    {
        Http::fake();
        $this->seedOldCache();
        $this->artisan('smking:delivery:import', ['--resource' => 'cms-page', '--identifier' => ['slug:article', 'slug:../bad']])->assertExitCode(1);
        $this->assertFalse($this->delivery()->hasState('cms-page', 'slug:article'));
        $this->artisan('smking:delivery:import', ['--resource' => 'cms-page', '--identifier' => array_fill(0, 21, 'slug:article')])->assertExitCode(1);
        $this->artisan('smking:delivery:import', ['--resource' => 'cms-page', '--identifier' => ['slug:article']])->assertExitCode(0);
        $this->assertSame('legacy', config('smking.delivery.mode'));
        config()->set('smking.delivery.mode', 'on_demand');
        $this->assertSame('configuration', $this->delivery()->importCached('cms-page', 'slug:article')->error);
        Http::assertNothingSent();
    }

    public function test_resource_denial_and_site_isolation_survive_recreation(): void
    {
        Http::fake(fn () => Http::response(['status' => 'unavailable', 'error' => 'aeo_disabled',
            'denial' => ['contract' => '2', 'scope' => 'aeo']], 403, ['Content-Type' => 'application/json']));
        $this->assertSame('access_denied', $this->delivery()->refresh('aeo', 'path:/article', new WaitBudget(500))->error);
        $this->cache->flush();
        foreach (['aeo' => 'path:/article', 'markdown' => 'path:/article', 'site-file' => 'kind:sitemap'] as $resource => $identifier) {
            $this->assertSame('access_denied', $this->delivery()->peek($resource, $identifier)->error);
        }
        $this->assertSame('cache_miss', $this->delivery()->peek('cms-page', 'slug:article')->error);
        config()->set('smking.base_url', 'https://another.test');
        $this->assertSame('cache_miss', $this->delivery()->peek('aeo', 'path:/article')->error);
        Http::assertSentCount(1);
    }

    public function test_cache_flush_during_inflight_invalidation_cannot_repopulate_content(): void
    {
        Http::fake(function () {
            $this->assertTrue($this->delivery()->invalidate('cms-page', 'slug:article'));
            $this->cache->flush();
            return Http::response($this->payload('cms-page'), 200, ['Content-Type' => 'application/json']);
        });
        $this->assertSame('superseded', $this->delivery()->refresh('cms-page', 'slug:article', new WaitBudget(500))->error);
        $this->assertSame('cache_miss', $this->delivery()->peek('cms-page', 'slug:article')->error);
        Http::assertSentCount(1);
    }

    public function test_corrupt_local_content_does_not_fall_back_to_old_cache(): void
    {
        Http::fake();
        $this->seedOldCache();
        $this->assertNotNull($this->delivery()->importCached('cms-page', 'slug:article')->snapshot);
        file_put_contents($this->directory.'/content/'.hash('sha256', $this->oldStateKey()).'.json', '{');
        $this->assertSame('cache_unavailable', $this->delivery()->peek('cms-page', 'slug:article')->error);
        $this->assertSame('cache_unavailable', $this->delivery()->importCached('cms-page', 'slug:article')->error);
        Http::assertNothingSent();
    }

    private function seedOldCache(): array
    {
        $snapshot = DeliverySnapshot::fromResponse('cms-page', 'slug:article', 200, $this->payload('cms-page'), $this->now);
        $state = ['format' => 1, 'resource' => 'cms-page', 'identifier' => 'slug:article',
            'generation' => str_repeat('c', 32), 'success' => $snapshot->toCache(), 'missing' => null];
        $this->cache->forever($this->oldStateKey(), $state);
        return $state;
    }

    private function missingPayload(string $resource): array
    {
        $payload = $this->payload($resource);
        $iso = static fn (int $milliseconds): string => gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);

        return [
            'status' => 'not_found',
            'delivery' => array_replace($payload['delivery'], [
                'fresh_until' => $iso($this->now + 60_000),
                'usable_until' => $iso($this->now + 60_000),
            ]),
        ];
    }

    private function oldStateKey(): string
    {
        return 'smking:delivery:v2:c1:'.$this->site().':state:'.hash('sha256', 'cms-page|slug:article');
    }

    private function site(): string
    {
        return substr(hash('sha256', config('smking.api_key').'|'.rtrim(config('smking.base_url'), '/')), 0, 24);
    }

    private function targets(): DeliveryTargetState
    {
        return new DeliveryTargetState($this->cache, config(), fn (): int => $this->now);
    }

    private function delivery(): OnDemandDelivery
    {
        return new OnDemandDelivery(
            cache: $this->cache, http: $this->app->make(Factory::class), config: config(),
            clock: fn (): int => $this->now, targets: $this->targets(),
        );
    }

    private function payload(string $resource): array
    {
        $iso = static fn (int $ms): string => gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)).'.000Z';
        return [
            'status' => 'ready',
            'delivery' => [
                'contract' => '2', 'content_version' => 'sha256:'.str_repeat('a', 64),
                'validated_at' => $iso($this->now), 'fresh_until' => $iso($this->now + 300000),
                'usable_until' => $iso($this->now + 3600000),
            ],
        ] + match ($resource) {
            'cms-page' => ['page' => ['slug' => 'article', 'bodyHtml' => '<main>Saved</main>']],
            'aeo' => ['jsonLd' => ['@type' => 'Product']],
            'markdown' => ['document' => ['path' => '/article', 'body' => '# Saved', 'content_type' => 'text/markdown; charset=utf-8']],
            'site-file' => ['document' => ['kind' => 'sitemap', 'body' => '<urlset/>', 'content_type' => 'application/xml; charset=utf-8']],
        };
    }
}
