<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Console\DeliveryPrewarmCommand;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\DeliveryReconciliation;
use Smking\Laravel\Delivery\DeliveryReportOutbox;
use Smking\Laravel\Delivery\DeliveryTargetState;
use Smking\Laravel\Delivery\DeliveryWorklist;
use Smking\Laravel\Delivery\WaitBudget;
use Symfony\Component\Console\Tester\CommandTester;

class DeliveryPrewarmTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/smking-prewarm-'.bin2hex(random_bytes(8));
        config()->set('cache.stores.prewarm_test', ['driver' => 'file', 'path' => $this->directory]);
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.store', 'prewarm_test');
        config()->set('smking.delivery.mode', 'legacy');
        config()->set('smking.delivery.aeo_enabled', false);
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('d', 64));
        config()->set('smking.delivery.work_max_jobs', 3);
        config()->set('smking.delivery.work_budget_ms', 5000);
        config()->set('smking.webhook_secret', 'prewarm-secret');
        config()->set('app.url', 'https://shop.example.test');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_prewarm_uses_the_real_v2_cache_without_switching_mode_or_removing_legacy_content(): void
    {
        Http::fake(fn ($request) => Http::response($this->payload((string) $request['slug']), 200, [
            'Content-Type' => 'application/json',
        ]));

        $client = $this->app->make(CmsClient::class);
        $legacy = $client->forSlug('article');
        $this->assertTrue($legacy->isReady());
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);

        [$exitCode] = $this->prewarm(['--slug' => ['', 'article']]);
        $this->assertSame(0, $exitCode);
        foreach (['slug:', 'slug:article'] as $identifier) {
            $this->assertNotNull($this->app->make(OnDemandDelivery::class)
                ->peek('cms-page', $identifier)->snapshot);
        }
        $this->assertSame('legacy', config('smking.delivery.mode'));

        config()->set('smking.delivery.mode', 'on_demand');
        $this->assertTrue($client->forSlug('')->isReady());
        $this->assertSame($legacy->bodyHtml, $client->forSlug('article')->bodyHtml);
        Http::assertSentCount(3);

        config()->set('smking.delivery.mode', 'legacy');
        $this->assertSame($legacy->bodyHtml, $client->forSlug('article')->bodyHtml);
        Http::assertSentCount(3);
        [$exitCode] = $this->prewarm(['--slug' => ['', 'article'], '--check' => true]);
        $this->assertSame(0, $exitCode);
        Http::assertSentCount(3);
    }

    public function test_check_is_read_only_and_refuses_missing_background_health_or_cache(): void
    {
        Http::fake();

        [$exitCode, $summary] = $this->prewarm([
            '--slug' => ['article'],
            '--check' => true,
        ]);
        $this->assertSame(1, $exitCode);
        $this->assertSame('background_unavailable', $summary['error']);
        $this->assertSame(0, $summary['processed']);
        Http::assertNothingSent();
    }

    public function test_invalid_or_oversized_batch_is_rejected_before_http(): void
    {
        Http::fake();

        foreach ([['../private'], ['article?token=secret'], ['one', 'two', 'three', 'four']] as $slugs) {
            [$exitCode, $summary] = $this->prewarm(['--slug' => $slugs]);
            $this->assertSame(1, $exitCode);
            $this->assertSame('invalid_input', $summary['error']);
        }
        Http::assertNothingSent();
    }

    public function test_failed_prewarm_keeps_legacy_content_and_mode(): void
    {
        $available = true;
        Http::fake(function () use (&$available) {
            return $available
                ? Http::response($this->payload('article'), 200, ['Content-Type' => 'application/json'])
                : Http::response(['error' => 'unavailable'], 503, ['Content-Type' => 'application/json']);
        });
        $client = $this->app->make(CmsClient::class);
        $legacy = $client->forSlug('article');
        $this->assertTrue($legacy->isReady());
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);

        $available = false;
        [$exitCode] = $this->prewarm(['--slug' => ['article']]);

        $this->assertSame(1, $exitCode);
        $this->assertNull($this->app->make(OnDemandDelivery::class)
            ->peek('cms-page', 'slug:article')->snapshot);
        $this->assertSame('legacy', config('smking.delivery.mode'));
        $this->assertSame($legacy->bodyHtml, $client->forSlug('article')->bodyHtml);
        Http::assertSentCount(2);
    }

    public function test_explicit_prewarm_can_prepare_new_content_after_mode_switch_without_notifications(): void
    {
        config()->set('smking.delivery.mode', 'on_demand');
        config()->set('smking.delivery.notifications_enabled', false);
        config()->set('smking.delivery.notifications_scope', null);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response($this->payload($request['slug']), 200));
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);

        [$exit, $summary] = $this->prewarm(['--slug' => ['new-article']]);
        $this->assertSame(0, $exit, json_encode($summary));
        $this->assertTrue($this->app->make(CmsClient::class)->forSlug('new-article')->isReady());
        $this->assertSame('on_demand', config('smking.delivery.mode'));
        Http::assertSentCount(1);
    }

    public function test_local_readiness_does_not_expire_a_valid_last_known_good_copy(): void
    {
        $past = (int) floor(microtime(true) * 1000) - 30 * 86400000;
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response($this->payload('article', $past), 200));
        $delivery = new OnDemandDelivery($this->app->make('cache')->store('prewarm_test'),
            $this->app->make(\Illuminate\Http\Client\Factory::class), config(), clock: fn () => $past,
            reconciliation: $this->app->make(DeliveryReconciliation::class));
        $this->assertNotNull($delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);

        [$exit, $summary] = $this->prewarm(['--slug' => ['article'], '--check' => true]);
        $this->assertSame(0, $exit, json_encode($summary));
        Http::assertSentCount(1);
    }

    public function test_explicit_preparation_rechecks_source_even_when_restored_content_is_fresh(): void
    {
        $available = true;
        Http::preventStrayRequests();
        Http::fake(function () use (&$available) {
            return $available ? Http::response($this->payload('article'), 200) : Http::response(['error' => 'unavailable'], 503);
        });
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
        $this->assertSame(0, $this->prewarm(['--slug' => ['article']])[0]);
        $available = false;

        [$exit, $summary] = $this->prewarm(['--slug' => ['article']]);
        $this->assertSame(1, $exit, json_encode($summary));
        Http::assertSentCount(2);
        $this->assertNotNull($this->app->make(OnDemandDelivery::class)->peek('cms-page', 'slug:article')->snapshot);
    }

    public function test_readiness_does_not_claim_the_new_version_is_ready_while_old_content_is_served(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response($this->payload('article'), 200));
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
        $this->assertSame(0, $this->prewarm(['--slug' => ['article']])[0]);
        $this->app->make(DeliveryTargetState::class)->apply([
            'resource' => 'cms-page', 'identifier' => 'slug:article', 'action' => 'update',
            'revision' => 2, 'generation' => 2, 'withdrawalRevision' => 0,
            'contentVersion' => 'sha256:'.hash('sha256', 'new version'),
        ]);
        [$exit, $summary] = $this->prewarm(['--slug' => ['article'], '--check' => true]);
        $this->assertSame(1, $exit, json_encode($summary));
        $this->assertSame(0, $summary['ready']);
        Http::assertSentCount(1);
    }

    public function test_blank_install_prepares_all_four_resources_without_visitors_or_notifications(): void
    {
        config()->set('smking.delivery.aeo_enabled', true);
        config()->set('smking.delivery.notifications_enabled', false);
        config()->set('smking.delivery.notifications_scope', null);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $resource = basename(parse_url($request->url(), PHP_URL_PATH));
            $payload = $this->payload($request['slug'] ?? '');
            if ($resource !== 'cms-page') unset($payload['page']);
            $payload += match ($resource) {
                'cms-page' => [],
                'aeo' => ['jsonLd' => ['@type' => 'Product']],
                'markdown' => ['document' => ['path' => $request['path'], 'body' => '# Prepared', 'content_type' => 'text/markdown; charset=utf-8']],
                'site-file' => ['document' => ['kind' => $request['kind'], 'body' => 'Prepared', 'content_type' => 'text/plain; charset=utf-8']],
            };
            return Http::response($payload, 200);
        });

        $this->assertDirectoryDoesNotExist($this->directory);
        $this->assertDirectoryDoesNotExist(config('smking.delivery.local_store_path'));
        $this->assertSame('registry_uninitialized', $this->app->make(DeliveryReconciliation::class)->status()['error']);
        Http::assertNothingSent();

        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
        $this->assertTrue($this->app->make(DeliveryWorklist::class)->status()['heartbeat_recent']);
        $this->assertTrue($this->app->make(DeliveryReportOutbox::class)->status()['heartbeat_recent']);
        Http::assertNothingSent();

        $selection = ['cms-page' => 'slug:article', 'aeo' => 'path:/article', 'markdown' => 'path:/article', 'site-file' => 'kind:robots'];
        foreach ($selection as $resource => $identifier) {
            [$exit, $summary] = $this->prewarm(['--resource' => $resource, '--identifier' => [$identifier]]);
            $this->assertSame(0, $exit, json_encode($summary));
            $this->assertSame(1, $summary['ready']);
            $this->assertSame($resource, $summary['items'][0]['resource']);
            $this->assertSame($identifier, $summary['items'][0]['identifier']);
        }
        $before = $this->persistentHashes();
        foreach ($selection as $resource => $identifier) {
            [$exit, $summary] = $this->prewarm(['--resource' => $resource, '--identifier' => [$identifier], '--check' => true]);
            $this->assertSame(0, $exit);
            $this->assertSame(0, $summary['processed']);
        }
        $this->assertSame($before, $this->persistentHashes());
        $this->assertSame(4, $this->app->make(DeliveryReconciliation::class)->status()['known']);

        config()->set('smking.delivery.mode', 'on_demand');
        $this->app->make('cache')->store('prewarm_test')->flush();
        $this->app->forgetInstance(OnDemandDelivery::class);
        $this->app->forgetInstance(DeliveryReconciliation::class);
        $delivery = $this->app->make(OnDemandDelivery::class);
        foreach ($selection as $resource => $identifier) {
            $result = $delivery->peek($resource, $identifier);
            $this->assertSame(200, $result->httpStatus);
            $this->assertNull($result->error);
            $this->assertNotNull($result->snapshot);
        }
        $this->assertSame(4, $this->app->make(DeliveryReconciliation::class)->status()['known']);
        $this->assertSame($before, $this->persistentHashes());
        $this->assertSame('on_demand', config('smking.delivery.mode'));
        Http::assertSentCount(4);
    }

    public function test_ambiguous_unknown_or_invalid_identifier_selection_is_rejected_before_io(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        foreach ([
            ['--slug' => ['article'], '--resource' => 'cms-page', '--identifier' => ['slug:article']],
            ['--resource' => 'unknown', '--identifier' => ['slug:article']],
            ['--resource' => 'aeo', '--identifier' => ['path://foreign.test/private']],
            ['--resource' => 'cms-page', '--identifier' => ['slug:article', 'slug:../draft']],
            ['--resource' => 'site-file', '--identifier' => ['kind:unsupported']],
            ['--resource' => 'cms-page', '--identifier' => ['slug:1', 'slug:2', 'slug:3', 'slug:4']],
        ] as $parameters) {
            [$exit, $summary] = $this->prewarm($parameters);
            $this->assertSame(1, $exit);
            $this->assertSame('invalid_input', $summary['error']);
        }
        $this->assertDirectoryDoesNotExist(config('smking.delivery.local_store_path'));
        Http::assertNothingSent();
    }

    public function test_readiness_refuses_a_missing_index_even_when_content_is_readable(): void
    {
        Http::fake(fn () => Http::response($this->payload('article'), 200));
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
        $this->assertSame(0, $this->prewarm(['--slug' => ['article']])[0]);
        $key = 'smking:delivery:v2:reconciliation:'.substr(hash('sha256', 'pk_test_key|https://api.test'), 0, 24);
        unlink(config('smking.delivery.local_store_path').'/'.hash('sha256', $key).'.json');
        $before = $this->persistentHashes();
        [$exit, $summary] = $this->prewarm(['--slug' => ['article'], '--check' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('registry_not_ready', $summary['items'][0]['state']);
        $this->assertSame($before, $this->persistentHashes());
        $this->assertNotNull($this->app->make(OnDemandDelivery::class)->peek('cms-page', 'slug:article')->snapshot);
        Http::assertSentCount(1);
    }

    public function test_readiness_refuses_draft_response_and_does_not_create_published_content(): void
    {
        Http::fake(fn () => Http::response($this->payload('article') + ['preview' => true], 200));
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
        [$exit, $summary] = $this->prewarm(['--slug' => ['article']]);
        $this->assertSame(1, $exit);
        $this->assertSame(0, $summary['ready']);
        $this->assertNull($this->app->make(OnDemandDelivery::class)->peek('cms-page', 'slug:article')->snapshot);
        Http::assertSentCount(1);
    }

    public function test_paused_aeo_cannot_be_reported_prepared_while_blog_still_can(): void
    {
        Http::fake(fn () => Http::response($this->payload('article'), 200));
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
        foreach (['aeo' => 'path:/article', 'markdown' => 'path:/article', 'site-file' => 'kind:robots'] as $resource => $identifier) {
            [$exit, $summary] = $this->prewarm(['--resource' => $resource, '--identifier' => [$identifier]]);
            $this->assertSame(1, $exit);
            $this->assertSame('disabled', $summary['error']);
        }
        $this->assertSame(0, $this->prewarm(['--slug' => ['article']])[0]);
        Http::assertSentCount(1);
    }

    private function persistentHashes(): array
    {
        $hashes = [];
        foreach ((new Filesystem())->allFiles(config('smking.delivery.local_store_path')) as $file) {
            $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes);
        return $hashes;
    }

    /** @return array{int, ?array<string, mixed>} */
    private function prewarm(array $parameters): array
    {
        // Laravel 10 leaves PendingCommand's mocked OutputStyle binding behind.
        // CommandTester must receive the real output, not that earlier mock.
        $this->app->offsetUnset(\Illuminate\Console\OutputStyle::class);
        $command = new DeliveryPrewarmCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($parameters);

        $output = trim($tester->getDisplay());

        return [$exitCode, $output === '' ? null : json_decode($output, true, flags: JSON_THROW_ON_ERROR)];
    }

    /** @return array<string, mixed> */
    private function payload(string $slug, ?int $now = null): array
    {
        $now ??= (int) floor(microtime(true) * 1000);
        $iso = static fn (int $milliseconds): string => gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000))
            .sprintf('.%03dZ', $milliseconds % 1000);

        return [
            'status' => 'ready',
            'page' => [
                'slug' => $slug,
                'title' => 'Published '.$slug,
                'bodyHtml' => '<main>Published '.$slug.'</main>',
            ],
            'delivery' => [
                'contract' => '2',
                'content_version' => 'sha256:'.hash('sha256', $slug),
                'validated_at' => $iso($now),
                'fresh_until' => $iso($now + 300_000),
                'usable_until' => $iso($now + 3_600_000),
            ],
        ];
    }
}
