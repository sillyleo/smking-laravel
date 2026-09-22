<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Closure;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Smking\Laravel\Delivery\DeliveryTargetState;
use Smking\Laravel\Delivery\DeliveryWorklist;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;

class DeliveryPublicationRefreshTest extends TestCase
{
    private string $directory;
    private Repository $cache;
    private int $now = 1789171200000;
    private int $revision = 2;
    private int $withdrawalRevision = 0;
    private bool $missing = false;
    private ?Closure $duringRequest = null;
    private ?Closure $alterPayload = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/smking-publication-'.bin2hex(random_bytes(8));
        $this->cache = new Repository(new FileStore(new Filesystem(), $this->directory));
        config()->set('smking.cache.enabled', true);
        config()->set('smking.delivery.mode', 'on_demand');
        config()->set('smking.delivery.notifications_enabled', false);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public static function resources(): array
    {
        return [['cms-page', 'slug:article'], ['aeo', 'path:/article'],
            ['markdown', 'path:/article'], ['site-file', 'kind:sitemap']];
    }

    #[DataProvider('resources')]
    public function test_ordered_get_advances_and_rejects_old_cdn_content_without_webhooks(string $resource, string $identifier): void
    {
        $this->fake($resource, $identifier);
        $delivery = $this->delivery();
        $this->assertSame(200, $delivery->refresh($resource, $identifier, new WaitBudget(500))->httpStatus);
        $this->revision = 3;
        $this->now += 86_400_000;
        $this->assertSame(3, $delivery->refresh($resource, $identifier, new WaitBudget(500))->snapshot?->payload['delivery']['publication']['revision']);
        $this->assertSame(3, $this->targets()->read($identifier, $resource)['target']['revision']);
        // A later HTTP validation time does not make a lower revision newer.
        $this->revision = 2;
        $this->now += 86_400_000;
        $this->assertSame('target_mismatch', $delivery->refresh($resource, $identifier, new WaitBudget(500))->error);
        $this->cache->flush();
        $this->assertSame(3, $this->delivery()->peek($resource, $identifier)->snapshot?->payload['delivery']['publication']['revision']);
        Http::assertSentCount(3);
    }

    #[DataProvider('resources')]
    public function test_missed_withdrawal_is_persisted_and_cannot_be_revived(string $resource, string $identifier): void
    {
        $this->fake($resource, $identifier);
        $delivery = $this->delivery();
        $this->assertNotNull($delivery->refresh($resource, $identifier, new WaitBudget(500))->snapshot);
        $this->revision = $this->withdrawalRevision = 3;
        $this->missing = true;
        $this->now += 86_400_000;
        $this->assertSame(404, $delivery->refresh($resource, $identifier, new WaitBudget(500))->httpStatus);
        $this->cache->flush();
        $this->missing = false;
        $this->revision = 2;
        $this->withdrawalRevision = 0;
        $this->assertSame('withdrawn', $this->delivery()->peek($resource, $identifier)->error);
        $this->assertSame('withdrawn', $this->delivery()->refresh($resource, $identifier, new WaitBudget(500))->error);
        Http::assertSentCount(2);
    }

    #[DataProvider('resources')]
    public function test_notification_during_get_wins_even_over_a_newer_response(string $resource, string $identifier): void
    {
        $this->fake($resource, $identifier);
        $delivery = $this->delivery();
        $this->assertNotNull($delivery->refresh($resource, $identifier, new WaitBudget(500))->snapshot);
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('d', 64));
        $this->revision = 4;
        $this->now += 86_400_000;
        $this->duringRequest = function () use ($resource, $identifier): void {
            $this->assertSame('applied', $this->targets()->apply([
                'resource' => $resource, 'identifier' => $identifier, 'action' => 'withdraw',
                'revision' => 3, 'generation' => 3, 'withdrawalRevision' => 3, 'contentVersion' => null,
            ])['status']);
        };
        $this->assertSame('superseded', $delivery->refresh($resource, $identifier, new WaitBudget(500))->error);
        $this->assertSame('withdrawn', $delivery->peek($resource, $identifier)->error);
        Http::assertSentCount(2);
    }

    public static function invalidEvidence(): array
    {
        return [
            'missing proof' => ['missing', 'target_mismatch'],
            'wrong identity' => ['identifier', 'invalid_response'],
            'wrong resource' => ['resource', 'invalid_response'],
            'wrong hash' => ['hash', 'invalid_response'],
            'invalid order' => ['order', 'invalid_response'],
            'overflow' => ['overflow', 'invalid_response'],
            'unexpected null' => ['null', 'invalid_response'],
            'same revision conflict' => ['conflict', 'target_mismatch'],
            'not found without proof' => ['missing404', 'target_mismatch'],
        ];
    }

    #[DataProvider('invalidEvidence')]
    public function test_unproven_change_never_replaces_body_or_fence(string $case, string $error): void
    {
        $this->fake('cms-page', 'slug:article');
        $delivery = $this->delivery();
        $before = $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot;
        $target = $this->targets()->read('slug:article');
        $this->revision = 3;
        $this->now += 86_400_000;
        $this->missing = $case === 'missing404';
        $this->alterPayload = static function (array $payload) use ($case): array {
            $proof = &$payload['delivery']['publication'];
            switch ($case) {
                case 'identifier': $proof['identifier'] = 'slug:another'; break;
                case 'resource': $proof['resource'] = 'aeo'; break;
                case 'hash': $proof['contentVersion'] = 'sha256:'.str_repeat('f', 64); break;
                case 'order': $proof['withdrawalRevision'] = 3; break;
                case 'overflow': $proof['revision'] = $proof['generation'] = 2_147_483_648; break;
                case 'null': $proof = null; break;
                case 'conflict': $proof['revision'] = $proof['generation'] = 2; break;
                default: unset($payload['delivery']['publication']);
            }
            return $payload;
        };
        $this->assertSame($error, $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->error);
        $this->assertSame($before?->payload, $delivery->peek('cms-page', 'slug:article')->snapshot?->payload);
        $this->assertSame($target, $this->targets()->read('slug:article'));
    }

    public function test_new_revision_cannot_lower_a_known_withdrawal_barrier(): void
    {
        $this->withdrawalRevision = 1;
        $this->fake('cms-page', 'slug:article');
        $delivery = $this->delivery();
        $this->assertNotNull($delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $this->revision = 3;
        $this->withdrawalRevision = 0;
        $this->now += 86_400_000;
        $this->assertSame('target_mismatch', $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->error);
        $this->assertSame(1, $this->targets()->read('slug:article')['target']['withdrawalRevision']);
    }

    public static function interruptedUpdates(): array
    {
        return ['ordinary update' => [0], 'intermediate withdrawal' => [3]];
    }

    #[DataProvider('interruptedUpdates')]
    public function test_superseded_commit_preserves_only_a_safe_old_body(int $withdrawalRevision): void
    {
        $this->fake('cms-page', 'slug:article');
        $delivery = $this->delivery();
        $this->assertNotNull($delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $this->revision = 4;
        $this->withdrawalRevision = $withdrawalRevision;
        $this->now += 86_400_000;
        $delivery = $this->delivery();
        // A newer unversioned hint invalidates the in-flight download generation.
        $this->duringRequest = fn () => $this->assertTrue($delivery->requestRefresh('cms-page', 'slug:article'));
        $this->assertSame('superseded', $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->error);
        if ($withdrawalRevision > 0) {
            $this->assertNull($delivery->peek('cms-page', 'slug:article')->snapshot);
        } else {
            $this->assertSame(2, $delivery->peek('cms-page', 'slug:article')->snapshot?->payload['delivery']['publication']['revision']);
        }
        $this->assertSame('target_pending', $delivery->peek('cms-page', 'slug:article')->error);
        $this->assertSame(4, $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot?->payload['delivery']['publication']['revision']);
    }

    private function fake(string $resource, string $identifier): void
    {
        Http::fake(function () use ($resource, $identifier) {
            $hook = $this->duringRequest;
            $this->duringRequest = null;
            $hook?->__invoke();
            $payload = $this->payload($resource, $identifier);
            if ($this->alterPayload !== null) $payload = ($this->alterPayload)($payload);
            return Http::response($payload, $this->missing ? 404 : 200);
        });
    }

    private function payload(string $resource, string $identifier): array
    {
        $body = match ($resource) {
            'cms-page' => ['page' => ['slug' => 'article', 'title' => 'Version '.$this->revision, 'bodyHtml' => '<h1>Content</h1>']],
            'aeo' => ['jsonLd' => ['@type' => 'Article'], 'summary' => 'Version '.$this->revision],
            'markdown' => ['document' => ['path' => '/article', 'body' => '# Content', 'content_type' => 'text/markdown; charset=utf-8']],
            'site-file' => ['document' => ['kind' => 'sitemap', 'body' => '<urlset/>', 'content_type' => 'application/xml; charset=utf-8']],
        };
        $version = 'sha256:'.hash('sha256', $resource.'|'.$this->revision);
        $iso = static fn (int $ms): string => gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)).'.000Z';
        return ['status' => $this->missing ? 'not_found' : 'ready'] + ($this->missing ? [] : $body) + [
            'delivery' => ['contract' => '2', 'content_version' => $version,
                'validated_at' => $iso($this->now), 'fresh_until' => $iso($this->now + 60_000),
                'usable_until' => $iso($this->now + 60_000),
                'publication' => ['resource' => $resource, 'identifier' => $identifier,
                    'action' => $this->missing ? 'withdraw' : 'update',
                    'revision' => $this->revision, 'generation' => $this->revision,
                    'withdrawalRevision' => $this->withdrawalRevision,
                    'contentVersion' => $this->missing ? null : $version]],
        ];
    }

    private function delivery(): OnDemandDelivery
    {
        $worklist = new DeliveryWorklist($this->cache, config(), fn () => $this->now);
        $this->assertTrue($worklist->prepare());
        return new OnDemandDelivery($this->cache, $this->app->make(Factory::class), config(), fn () => $this->now, worklist: $worklist);
    }

    private function targets(): DeliveryTargetState
    {
        return new DeliveryTargetState($this->cache, config(), fn () => $this->now);
    }
}
