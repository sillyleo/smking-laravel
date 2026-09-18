<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\AeoClient;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Delivery\DeliveryReportOutbox;
use Smking\Laravel\Delivery\DeliveryReportTransport;
use Smking\Laravel\Http\Middleware\TrackCrawlerHit;
use Symfony\Component\HttpFoundation\Response;

class DeliveryReportOutboxTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/smking-report-outbox-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        config()->set('cache.stores.delivery_test', ['driver' => 'file', 'path' => $this->directory]);
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.store', 'delivery_test');
        config()->set('smking.delivery.mode', 'on_demand');
        config()->set('smking.delivery.heartbeat_seconds', 180);
        config()->set('smking.delivery.work_max_jobs', 10);
        config()->set('smking.delivery.work_budget_ms', 5000);
        config()->set('app.url', 'https://shop.example.test');
        config()->set('app.env', 'testing');
        config()->set('smking.webhook_secret', 'fixture-secret');
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_terminate_only_captures_and_independent_command_sends_signed_private_free_report(): void
    {
        Http::fake(function ($request) {
            $body = $request->body();
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame(
                'sha256='.hash_hmac('sha256', "smking-sdk-report-v2\n".$body, 'fixture-secret'),
                $request->header('X-Smking-Report-Signature')[0] ?? null,
            );
            $this->assertStringNotContainsString('private', $body);
            $this->assertSame('/products/article', $payload['crawler_hits'][0]['path']);
            $this->assertArrayNotHasKey('user_agent', $payload['crawler_hits'][0]);
            $this->assertArrayNotHasKey('referer', $payload['crawler_hits'][0]);

            return Http::response([
                'status' => 'accepted',
                'report_id' => $payload['report_id'],
                'state_applied' => true,
                'accepted_event_ids' => [$payload['crawler_hits'][0]['event_id']],
                'accepted_observation_ids' => [],
            ], 200, ['Content-Type' => 'application/json']);
        });
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
        Http::assertNothingSent();

        $request = Request::create('/products/article?private=secret', 'GET', server: [
            'HTTP_USER_AGENT' => 'GPTBot/1.0 private-agent-details',
            'HTTP_REFERER' => 'https://chatgpt.com/?private=secret',
        ]);
        $this->app->make(TrackCrawlerHit::class)->terminate($request, new Response('ok', 200));
        Http::assertNothingSent();

        $outbox = $this->app->make(DeliveryReportOutbox::class);
        $this->assertSame(1, $outbox->status()['pending_events']);

        $exit = $this->artisan('smking:delivery:report')->run();
        $this->assertSame(0, $exit, json_encode($outbox->status(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $outbox->status()['pending_events']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/sdk/report'));
    }

    public function test_failed_report_is_retained_without_visitor_or_legacy_fallback(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
        $request = Request::create('/products/article', 'GET', server: ['HTTP_USER_AGENT' => 'GPTBot/1.0']);
        $this->app->make(TrackCrawlerHit::class)->terminate($request, new Response('ok', 200));
        Http::assertNothingSent();

        $this->artisan('smking:delivery:report')->assertExitCode(1);

        $status = $this->app->make(DeliveryReportOutbox::class)->status();
        $this->assertSame(1, $status['pending_events']);
        $this->assertSame('http_503', $status['last_error']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v1/'));
    }

    public function test_missing_sender_and_overflow_are_visible_but_never_send(): void
    {
        Http::fake();
        $outbox = $this->app->make(DeliveryReportOutbox::class);
        $request = Request::create('/products/article', 'GET', server: ['HTTP_USER_AGENT' => 'GPTBot/1.0']);

        $this->app->make(TrackCrawlerHit::class)->terminate($request, new Response('ok', 200));

        $status = $outbox->status();
        $this->assertFalse($status['heartbeat_recent']);
        $this->assertSame(1, $status['losses']['background_unavailable']);
        $this->assertSame(0, $status['pending_events']);
        Http::assertNothingSent();
    }

    public function test_report_retries_exact_bytes_three_times_then_stops_with_visible_loss(): void
    {
        $now = (int) floor(microtime(true) * 1000);
        $cache = $this->app->make(\Illuminate\Contracts\Cache\Factory::class)->store('delivery_test');
        $outbox = new DeliveryReportOutbox(
            cache: $cache,
            config: config(),
            clock: static function () use (&$now): int {
                return $now;
            },
            eventCapacity: 1,
        );
        $transport = new DeliveryReportTransport(
            http: $this->app->make(\Illuminate\Http\Client\Factory::class),
            config: config(),
            clock: static function () use (&$now): int {
                return $now;
            },
        );
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $this->assertTrue($outbox->prepare());
        $classification = ['bot' => 'GPTBot', 'bot_category' => 'training', 'purpose' => 'training'];
        $this->assertTrue($outbox->capture($classification, '/products/first', 200));
        $this->assertFalse($outbox->capture($classification, '/products/overflow', 200));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = $outbox->runOnce($transport);
            $this->assertSame('http_503', $result['error']);
            $now += 60_000;
        }
        $stopped = $outbox->runOnce($transport);
        $this->assertSame('retry_exhausted', $stopped['error']);

        $bodies = Http::recorded()->map(fn ($pair): string => $pair[0]->body())->all();
        $this->assertCount(3, $bodies);
        $this->assertCount(1, array_unique($bodies));
        $status = $outbox->status();
        $this->assertSame(0, $status['pending_events']);
        $this->assertSame(1, $status['losses']['overflow']);
        $this->assertSame(1, $status['losses']['retry_exhausted']);
    }

    public function test_legacy_tracking_remains_v1_but_unknown_mode_fails_closed(): void
    {
        Http::fake();
        config()->set('smking.delivery.mode', 'legacy');
        $request = Request::create('/products/article', 'GET', server: ['HTTP_USER_AGENT' => 'GPTBot/1.0']);
        $middleware = $this->app->make(TrackCrawlerHit::class);
        $middleware->terminate($request, new Response('ok', 200));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/crawler-hit'));

        Http::fake();
        config()->set('smking.delivery.mode', 'unexpected');
        $middleware->terminate($request, new Response('ok', 200));
        $this->assertFalse($this->app->make(AeoClient::class)->forPath('/products/article')->isReady());
        $this->assertSame('server_error', $this->app->make(CmsClient::class)->forSlug('article')->status);
        Http::assertNothingSent();
    }

    public function test_report_metadata_fails_closed_when_shared_cache_is_disabled(): void
    {
        config()->set('smking.cache.enabled', false);

        $this->assertNull($this->app->make(DeliveryReportTransport::class)->metadata());
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(1);
        Http::assertNothingSent();
    }
}
