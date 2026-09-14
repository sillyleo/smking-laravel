<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Console\DeliveryPrewarmCommand;
use Smking\Laravel\Delivery\OnDemandDelivery;
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

    /** @return array{int, ?array<string, mixed>} */
    private function prewarm(array $parameters): array
    {
        $command = new DeliveryPrewarmCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($parameters);

        $output = trim($tester->getDisplay());

        return [$exitCode, $output === '' ? null : json_decode($output, true, flags: JSON_THROW_ON_ERROR)];
    }

    /** @return array<string, mixed> */
    private function payload(string $slug): array
    {
        $now = (int) floor(microtime(true) * 1000);
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
