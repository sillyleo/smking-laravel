<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;

class DeliveryLongTimelineTest extends TestCase
{
    private const NOW = 1789171200000;

    private string $directory;

    private Repository $cache;

    private int $now = self::NOW;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/smking-long-timeline-'.bin2hex(random_bytes(8));
        $this->cache = new Repository(new FileStore(new Filesystem(), $this->directory.'/cache'));
        config()->set('smking.cache.enabled', true);
        config()->set('smking.delivery.local_store_path', $this->directory.'/content');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('failureTimeline')]
    public function test_last_known_good_survives_long_timeline_source_failures(
        string $resource,
        string $identifier,
        int $days,
        string $failure,
        string $expectedError,
    ): void {
        $requests = 0;
        Http::fake(function () use (&$requests, $resource, $identifier, $failure) {
            $requests++;
            if ($requests === 1) {
                return Http::response(
                    $this->payload($resource, $identifier),
                    200,
                    ['Content-Type' => 'application/json'],
                );
            }

            return match ($failure) {
                'disconnected' => throw new ConnectionException('source disconnected'),
                'timeout' => throw new ConnectionException('source timed out'),
                'rate_limited' => Http::response(['error' => 'rate_limited'], 429, ['Content-Type' => 'application/json']),
                'server_error' => Http::response(['error' => 'unavailable'], 503, ['Content-Type' => 'application/json']),
                'invalid_format' => Http::response(['status' => 'ready'], 200, ['Content-Type' => 'application/json']),
            };
        });

        $delivery = $this->delivery();
        $initial = $delivery->prepare($resource, $identifier, new WaitBudget(500));
        $this->assertNotNull($initial->snapshot, (string) $initial->error);
        $savedPayload = $initial->snapshot->payload;
        $savedVersion = $savedPayload['delivery']['content_version'];

        $this->now += $days * 86_400_000;
        $failed = $delivery->prepare($resource, $identifier, new WaitBudget(500));
        $this->assertSame($expectedError, $failed->error);

        $preserved = $delivery->read($resource, $identifier, new WaitBudget(500));
        $this->assertSame(200, $preserved->httpStatus);
        $this->assertNull($preserved->error);
        $this->assertTrue($preserved->refreshRequired);
        $this->assertSame($savedVersion, $preserved->snapshot?->payload['delivery']['content_version']);
        $this->assertSame($savedPayload, $preserved->snapshot?->payload);
        $this->assertSame(2, $requests);
    }

    public static function failureTimeline(): array
    {
        return [
            'day 1 CMS source disconnected' => ['cms-page', 'slug:article', 1, 'disconnected', 'transport'],
            'day 8 AEO source timeout' => ['aeo', 'path:/article', 8, 'timeout', 'transport'],
            'day 8 Markdown rate limited' => ['markdown', 'path:/article', 8, 'rate_limited', 'upstream'],
            'day 30 sitemap source 5xx' => ['site-file', 'kind:sitemap', 30, 'server_error', 'upstream'],
            'day 30 llms source format error' => ['site-file', 'kind:llms_txt', 30, 'invalid_format', 'invalid_response'],
        ];
    }

    private function delivery(): OnDemandDelivery
    {
        return new OnDemandDelivery(
            cache: $this->cache,
            http: $this->app->make(Factory::class),
            config: config(),
            clock: fn (): int => $this->now,
        );
    }

    private function payload(string $resource, string $identifier): array
    {
        $iso = static fn (int $milliseconds): string => gmdate(
            'Y-m-d\TH:i:s',
            intdiv($milliseconds, 1000),
        ).sprintf('.%03dZ', $milliseconds % 1000);
        $contentVersion = 'sha256:'.hash('sha256', $resource.'|'.$identifier.'|stable');

        return [
            'status' => 'ready',
            'delivery' => [
                'contract' => '2',
                'content_version' => $contentVersion,
                'validated_at' => $iso($this->now),
                'fresh_until' => $iso($this->now + 300_000),
                'usable_until' => $iso($this->now + 3_600_000),
            ],
        ] + match ($resource) {
            'cms-page' => [
                'page' => ['slug' => substr($identifier, 5), 'bodyHtml' => '<main>Stable CMS</main>'],
            ],
            'aeo' => [
                'jsonLd' => ['@type' => 'Product'],
                'summary' => 'Stable AEO',
            ],
            'markdown' => [
                'document' => [
                    'path' => substr($identifier, 5),
                    'body' => '# Stable Markdown',
                    'content_type' => 'text/markdown; charset=utf-8',
                ],
            ],
            'site-file' => [
                'document' => [
                    'kind' => substr($identifier, 5),
                    'body' => $identifier === 'kind:sitemap' ? '<urlset/>' : '# Stable llms.txt',
                    'content_type' => $identifier === 'kind:sitemap'
                        ? 'application/xml; charset=utf-8'
                        : 'text/plain; charset=utf-8',
                ],
            ],
        };
    }
}
