<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Delivery\DeliveryReconciliation;
use Smking\Laravel\Delivery\DeliveryNotificationHealth;
use Smking\Laravel\Delivery\DeliveryTargetState;
use Smking\Laravel\Delivery\DeliveryWorklist;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;

class DeliveryNotificationUpdateTest extends TestCase
{
    private string $directory;

    private string $version = 'a';

    private ?array $failure = null;

    private ?Closure $duringRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/smking-notify-update-'.bin2hex(random_bytes(8));
        config()->set('cache.stores.notification_test', ['driver' => 'file', 'path' => $this->directory]);
        config()->set('smking.cache.store', 'notification_test');
        config()->set('smking.cache.enabled', true);
        config()->set('smking.delivery.mode', 'on_demand');
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('d', 64));
        config()->set('smking.webhook_secret', 'notification-secret');
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $hook = $this->duringRequest;
            $this->duringRequest = null;
            $hook?->__invoke();
            $endpoint = basename(parse_url($request->url(), PHP_URL_PATH));
            $resource = match ($endpoint) {
                'md' => 'markdown',
                'sitemap.xml' => 'site-file',
                default => $endpoint,
            };
            return Http::response(
                $this->failure['body'] ?? $this->payload($resource, $this->version),
                $this->failure['status'] ?? 200,
                ['Content-Type' => $this->failure['type'] ?? 'application/json'],
            );
        });
        $this->assertTrue($this->app->make(DeliveryWorklist::class)->prepare());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_legacy_cms_update_preserves_local_body_and_schedules_background_work(): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        $before = $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot;
        $this->assertNotNull($before);
        $this->notify(['kind' => 'cms_page', 'slugs' => ['article']])->assertOk();
        $this->assertSame(1, $this->app->make(DeliveryReconciliation::class)->status()['known']);
        $this->assertSame($before->payload, $delivery->peek('cms-page', 'slug:article')->snapshot?->payload);
        $this->assertSame(1, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
        Http::assertSentCount(1);
    }

    public function test_legacy_aeo_update_preserves_aeo_and_markdown_bodies(): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        $before = [];
        foreach (['aeo', 'markdown'] as $resource) {
            $before[$resource] = $delivery->refresh($resource, 'path:/article', new WaitBudget(500))->snapshot;
            $this->assertNotNull($before[$resource]);
        }
        $this->notify(['kind' => 'aeo', 'paths' => ['/article']])->assertOk();
        $this->assertSame(2, $this->app->make(DeliveryReconciliation::class)->status()['known']);
        foreach ($before as $resource => $snapshot) {
            $this->assertSame($snapshot->payload, $delivery->peek($resource, 'path:/article')->snapshot?->payload);
        }
        $this->assertSame(2, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
        Http::assertSentCount(2);
    }

    public function test_pending_versioned_targets_do_not_hide_legacy_aeo_markdown_or_site_file_cache(): void
    {
        config()->set('smking.delivery.mode', 'legacy');
        $client = $this->app->make(AeoClient::class);

        $this->assertTrue($client->forPath('/article')->isReady());
        $markdown = $client->getMarkdown('/article');
        $siteFile = $client->fetchPublicFile('sitemap');
        $this->assertNotNull($markdown);
        $this->assertNotNull($siteFile);
        Http::assertSentCount(3);

        $this->notify($this->envelope([
            $this->target('aeo', 'path:/article'),
            $this->target('markdown', 'path:/article'),
            $this->target('site-file', 'kind:sitemap'),
        ]))->assertOk();

        $this->assertTrue($client->forPath('/article')->isReady());
        $this->assertSame($markdown, $client->getMarkdown('/article'));
        $this->assertSame($siteFile, $client->fetchPublicFile('sitemap'));
        Http::assertSentCount(3);
    }

    public function test_legacy_hint_forces_fresh_content_to_be_checked_by_worker_only(): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        $delivery->refresh('cms-page', 'slug:article', new WaitBudget(500));
        $this->notify(['kind' => 'cms_page', 'slugs' => ['article']])->assertOk();
        $this->version = 'b';
        $this->assertSame('sha256:'.str_repeat('a', 64), $delivery->read('cms-page', 'slug:article', new WaitBudget(500))->snapshot?->payload['delivery']['content_version']);
        Http::assertSentCount(1);
        $summary = $this->app->make(DeliveryWorklist::class)->runBatch($delivery, 10, 1000);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame('sha256:'.str_repeat('b', 64), $delivery->peek('cms-page', 'slug:article')->snapshot?->payload['delivery']['content_version']);
        Http::assertSentCount(2);
    }

    public static function replacementOutcomes(): array
    {
        return ['failed preparation' => [false], 'prepared replacement' => [true]];
    }

    #[DataProvider('replacementOutcomes')]
    public function test_legacy_republication_keeps_withdrawn_content_hidden_until_replacement_is_prepared(bool $preparedReplacement): void
    {
        $this->test_pending_versioned_targets_do_not_hide_legacy_aeo_markdown_or_site_file_cache();
        $client = $this->app->make(AeoClient::class);
        $delivery = $this->app->make(OnDemandDelivery::class);
        $targets = [];
        foreach (['aeo' => 'path:/article', 'markdown' => 'path:/article', 'site-file' => 'kind:sitemap'] as $resource => $identifier) {
            $targets[] = $this->target($resource, $identifier, 'withdraw', 3);
        }
        $read = fn (): array => [
            $client->forPath('/article')->isReady(),
            $client->getMarkdown('/article') !== null,
            $client->fetchPublicFile('sitemap') !== null,
        ];
        $this->notify($this->envelope($targets))->assertOk();
        $this->assertSame([false, false, false], $read());
        foreach ($targets as &$target) {
            $target['action'] = 'update';
            $target['revision'] = $target['generation'] = 4;
            $target['contentVersion'] = 'sha256:'.str_repeat('c', 64);
        }
        unset($target);
        $this->notify($this->envelope($targets))->assertOk();
        $this->assertSame([false, false, false], $read());
        Http::assertSentCount(3);

        foreach ($targets as $target) {
            $payload = $this->payload($target['resource'], 'c');
            $payload['delivery']['publication'] = $target;
            $this->failure = $preparedReplacement
                ? ['body' => $payload, 'status' => 200]
                : ['body' => ['status' => 'unavailable'], 'status' => 503];
            $prepared = $delivery->prepare($target['resource'], $target['identifier'], new WaitBudget(500));
            $this->assertSame($preparedReplacement, $prepared->snapshot !== null);
        }
        if (! $preparedReplacement) {
            // A failed refresh cannot reveal the withdrawn v1 cache.
            $this->assertSame([false, false, false], $read());
            Http::assertSentCount(6);
            return;
        }
        $this->assertSame([true, true, true], $read());
        $this->assertSame('Saved c', $client->forPath('/article')->jsonLd['name']);
        $this->assertSame('# Saved c', $client->getMarkdown('/article'));
        $this->assertStringContainsString('<!-- c -->', $client->fetchPublicFile('sitemap')['body']);
        Http::assertSentCount(6);
    }

    public function test_all_resources_keep_old_content_until_worker_validates_target(): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        $before = [];
        $targets = [];
        foreach ($this->identifiers() as $resource => $identifier) {
            $before[$resource] = $delivery->refresh($resource, $identifier, new WaitBudget(500))->snapshot;
            $this->assertNotNull($before[$resource]);
            $targets[] = $this->target($resource, $identifier);
        }
        $payload = $this->envelope($targets);
        $this->notify($payload)->assertOk()->assertJsonPath('kind', 'content_delivery_v2');
        $this->notify($payload)->assertOk();
        $this->assertSame(4, $this->app->make(DeliveryReconciliation::class)->status()['known']);
        $this->assertSame(4, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
        foreach ($this->identifiers() as $resource => $identifier) {
            $this->assertSame($before[$resource]->payload, $delivery->read($resource, $identifier, new WaitBudget(500))->snapshot?->payload);
        }
        Http::assertSentCount(4);
        $this->version = 'b';
        $this->assertSame(4, $this->app->make(DeliveryWorklist::class)->runBatch($delivery, 10, 1000)['completed']);
        foreach ($this->identifiers() as $resource => $identifier) {
            $this->assertSame('sha256:'.str_repeat('b', 64), $delivery->peek($resource, $identifier)->snapshot?->payload['delivery']['content_version']);
            $this->notify($this->envelope([$this->target($resource, $identifier, revision: 1)]))
                ->assertOk()->assertJsonPath('results.0.status', 'obsolete');
            $conflict = array_replace($this->target($resource, $identifier), ['contentVersion' => 'sha256:'.str_repeat('a', 64)]);
            $this->notify($this->envelope([$conflict]))->assertStatus(409);
            $this->assertSame('sha256:'.str_repeat('b', 64), $delivery->peek($resource, $identifier)->snapshot?->payload['delivery']['content_version']);
        }
        Http::assertSentCount(8);
    }

    public function test_old_responses_do_not_replace_last_success(): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        foreach ($this->identifiers() as $resource => $identifier) {
            $before = $delivery->refresh($resource, $identifier, new WaitBudget(500))->snapshot;
            $this->notify($this->envelope([$this->target($resource, $identifier)]))->assertOk();
            $this->assertSame('target_mismatch', $delivery->refresh($resource, $identifier, new WaitBudget(500))->error);
            $this->assertSame($before?->payload, $delivery->peek($resource, $identifier)->snapshot?->payload);
        }
    }

    #[DataProvider('failedDownloads')]
    public function test_failed_background_download_keeps_body_and_daily_index(string $resource, string $identifier, array $failure): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        $before = $delivery->refresh($resource, $identifier, new WaitBudget(500))->snapshot;
        $this->assertNotNull($before);
        $this->notify($this->envelope([$this->target($resource, $identifier)]))->assertOk();
        $this->failure = $failure;
        $summary = $this->app->make(DeliveryWorklist::class)->runBatch($delivery, 10, 1000);
        $this->assertSame(0, $summary['completed']);
        $this->assertSame(1, $summary['deferred']);
        $this->assertSame('processing_failed', $this->app->make(DeliveryNotificationHealth::class)->status($resource)['reason']);
        $this->assertSame(1, $this->app->make(DeliveryReconciliation::class)->status()['known']);
        $this->assertSame($before->payload, $delivery->read($resource, $identifier, new WaitBudget(500))->snapshot?->payload);
        Http::assertSentCount(2);
    }

    public static function failedDownloads(): array
    {
        $cases = [];
        foreach (self::identifiers() as $resource => $identifier) {
            foreach ([
                '5xx' => ['body' => [], 'status' => 503],
                '429' => ['body' => [], 'status' => 429],
                'empty200' => ['body' => '', 'status' => 200],
                'html404' => ['body' => '<h1>Proxy error</h1>', 'status' => 404, 'type' => 'text/html'],
                'malformed' => ['body' => '{', 'status' => 200],
            ] as $label => $failure) {
                $cases[$resource.' '.$label] = [$resource, $identifier, $failure];
            }
        }
        return $cases;
    }

    public function test_withdrawal_during_download_prevents_late_commit_for_every_resource(): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        foreach ($this->identifiers() as $resource => $identifier) {
            $delivery->refresh($resource, $identifier, new WaitBudget(500));
            $this->notify($this->envelope([$this->target($resource, $identifier)]))->assertOk();
            $this->version = 'b';
            $this->duringRequest = function () use ($resource, $identifier): void {
                $this->notify($this->envelope([$this->target($resource, $identifier, 'withdraw', 3)]))->assertOk();
            };
            $this->assertSame('superseded', $delivery->refresh($resource, $identifier, new WaitBudget(500))->error);
            $this->assertSame('withdrawn', $delivery->peek($resource, $identifier)->error);
            $this->version = 'a';
        }
    }

    public function test_new_hint_during_download_keeps_old_body_and_remains_pending(): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        $before = $delivery->refresh('aeo', 'path:/article', new WaitBudget(500))->snapshot;
        $this->notify(['kind' => 'aeo', 'paths' => ['/article']])->assertOk();
        $this->duringRequest = function (): void {
            $this->notify(['kind' => 'aeo', 'paths' => ['/article']])->assertOk();
        };
        $this->version = 'b';
        $this->assertSame('superseded', $delivery->refresh('aeo', 'path:/article', new WaitBudget(500))->error);
        $this->assertSame($before?->payload, $delivery->peek('aeo', 'path:/article')->snapshot?->payload);
        $this->assertSame(2, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
        $this->assertSame('sha256:'.str_repeat('b', 64), $delivery->refresh('aeo', 'path:/article', new WaitBudget(500))->snapshot?->payload['delivery']['content_version']);
    }

    public function test_unavailable_queue_is_not_acknowledged_as_registered_and_retains_body(): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        $before = $delivery->refresh('aeo', 'path:/article', new WaitBudget(500))->snapshot;
        $this->app->make('cache')->store('notification_test')->flush();
        $this->notify($this->envelope([$this->target('aeo', 'path:/article')]))
            ->assertStatus(503)->assertJsonPath('results.0.status', 'unavailable');
        $this->assertSame($before?->payload, $delivery->peek('aeo', 'path:/article')->snapshot?->payload);
        $this->assertTrue($this->app->make(DeliveryWorklist::class)->prepare());
        $this->notify($this->envelope([$this->target('aeo', 'path:/article')]))
            ->assertOk()->assertJsonPath('results.0.status', 'registered');
        Http::assertSentCount(1);
    }

    public function test_registration_during_active_work_is_not_removed_by_its_completion(): void
    {
        $worklist = $this->app->make(DeliveryWorklist::class);
        $delivery = $this->app->make(OnDemandDelivery::class);
        $this->assertTrue($worklist->schedule('aeo', 'path:/article'));
        $this->duringRequest = function () use ($worklist): void {
            $this->assertTrue($worklist->schedule('aeo', 'path:/article'));
        };
        $this->assertSame(1, $worklist->runBatch($delivery, 1, 1000)['completed']);
        $this->assertSame(1, $worklist->status()['counts']['pending']);
        $this->assertSame(1, $worklist->runBatch($delivery, 1, 1000)['completed']);
        $this->assertSame(0, $worklist->status()['counts']['pending']);
        Http::assertSentCount(1);
    }

    public function test_withdrawals_fence_all_resources_and_survive_legacy_mode(): void
    {
        $delivery = $this->app->make(OnDemandDelivery::class);
        foreach ($this->identifiers() as $resource => $identifier) {
            $delivery->refresh($resource, $identifier, new WaitBudget(500));
            $target = $this->target($resource, $identifier, 'withdraw');
            $this->notify($this->envelope([$target]))->assertOk()->assertJsonPath('results.0.status', 'withdrawn');
            $this->notify($this->envelope([$this->target($resource, $identifier, revision: 1)]))
                ->assertOk()->assertJsonPath('results.0.status', 'obsolete');
            $this->assertSame('withdrawn', $delivery->read($resource, $identifier, new WaitBudget(500))->error);
        }
        config()->set('smking.delivery.mode', 'legacy');
        config()->set('smking.delivery.notifications_enabled', false);
        $aeo = $this->app->make(AeoClient::class);
        $this->assertFalse($aeo->forPath('/article')->isReady());
        $this->assertNull($aeo->getMarkdown('/article'));
        $this->assertNull($aeo->fetchPublicFile('sitemap'));
        Http::assertSentCount(4);
    }

    public function test_envelope_scope_resource_validation_and_legacy_contract_stay_strict(): void
    {
        $targets = [$this->target('aeo', 'path:/article')];
        $this->notify($this->envelope($targets, 'cms_delivery_v2'))->assertStatus(400);
        $this->notify(array_replace($this->envelope($targets), ['scope' => str_repeat('e', 64)]))->assertStatus(401);
        $this->notify($this->envelope([$targets[0], $targets[0]]))->assertStatus(400);
        $this->notify($this->envelope([$this->target('aeo', 'slug:article')]))->assertStatus(400);
        $this->assertNull($this->app->make(DeliveryTargetState::class)->read('path:/article', 'aeo'));
        Http::assertNothingSent();
    }

    public function test_probe_is_scoped_and_does_not_grant_health_or_download_content(): void
    {
        $payload = $this->envelope([]);
        unset($payload['targets']);
        $payload['kind'] = 'content_delivery_probe_v2';
        $payload['resources'] = DeliveryNotificationHealth::RESOURCES;
        $this->notify($payload)->assertOk()->assertJsonPath('resources', DeliveryNotificationHealth::RESOURCES);
        $health = $this->app->make(DeliveryNotificationHealth::class);
        foreach (DeliveryNotificationHealth::RESOURCES as $resource) {
            $this->assertSame('unconfirmed', $health->status($resource)['reason']);
        }
        $this->notify(array_replace($payload, ['scope' => str_repeat('e', 64)]))->assertStatus(401);
        $this->notify(array_replace($payload, ['resources' => ['aeo', 'aeo']]))->assertStatus(400);
        $this->notify(array_replace($payload, ['resources' => ['unknown']]))->assertStatus(400);
        $this->assertSame(0, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
        Http::assertNothingSent();
    }

    public function test_success_and_failure_only_change_the_processed_resource_health(): void
    {
        $health = $this->app->make(DeliveryNotificationHealth::class);
        $targets = [];
        foreach ($this->identifiers() as $resource => $identifier) {
            $targets[] = $this->target($resource, $identifier);
        }
        $this->notify($this->envelope($targets))->assertOk();
        foreach (DeliveryNotificationHealth::RESOURCES as $resource) {
            $this->assertTrue($health->status($resource)['available']);
        }
        $conflict = array_replace($this->target('aeo', 'path:/article'), ['contentVersion' => 'sha256:'.str_repeat('c', 64)]);
        $this->notify($this->envelope([$conflict]))->assertStatus(409);
        $this->assertSame('processing_failed', $health->status('aeo')['reason']);
        $this->assertTrue($health->status('markdown')['available']);
        Http::assertNothingSent();
    }

    public function test_obsolete_notification_does_not_restore_health_after_failed_worker(): void
    {
        $health = $this->app->make(DeliveryNotificationHealth::class);
        $worklist = $this->app->make(DeliveryWorklist::class);
        $delivery = $this->app->make(OnDemandDelivery::class);

        $this->notify($this->envelope([
            $this->target('aeo', 'path:/article', revision: 2),
        ]))->assertOk()->assertJsonPath('results.0.status', 'registered');
        $this->assertTrue($health->status('aeo')['available']);

        $this->failure = ['body' => ['status' => 'unavailable'], 'status' => 503];
        $this->assertSame(1, $worklist->runBatch($delivery, 1, 1000)['deferred']);
        $this->assertSame('processing_failed', $health->status('aeo')['reason']);

        $this->notify($this->envelope([
            $this->target('aeo', 'path:/article', revision: 1),
        ]))->assertOk()->assertJsonPath('results.0.status', 'obsolete');
        $this->assertSame('processing_failed', $health->status('aeo')['reason']);
    }

    public function test_legacy_download_is_rechecked_when_withdrawal_arrives_mid_request(): void
    {
        config()->set('smking.delivery.mode', 'legacy');
        $aeo = $this->app->make(AeoClient::class);
        foreach ([
            ['aeo', 'path:/article', fn () => $aeo->forPath('/article')->isReady()],
            ['aeo', 'product_id:12', fn () => $aeo->forProductId(12)->isReady()],
            ['markdown', 'path:/article', fn () => $aeo->getMarkdown('/article') !== null],
            ['site-file', 'kind:sitemap', fn () => $aeo->fetchPublicFile('sitemap') !== null],
        ] as [$resource, $identifier, $read]) {
            $this->duringRequest = function () use ($resource, $identifier): void {
                $this->notify($this->envelope([$this->target($resource, $identifier, 'withdraw')]))->assertOk();
            };
            $this->assertFalse($read());
            $this->assertFalse($read());
        }
        Http::assertSentCount(4);
    }

    private static function identifiers(): array
    {
        return ['cms-page' => 'slug:article', 'aeo' => 'path:/article', 'markdown' => 'path:/article', 'site-file' => 'kind:sitemap'];
    }

    private function target(string $resource, string $identifier, string $action = 'update', int $revision = 2): array
    {
        return ['resource' => $resource, 'identifier' => $identifier, 'action' => $action,
            'revision' => $revision, 'generation' => $revision,
            'withdrawalRevision' => $action === 'withdraw' ? $revision : 0,
            'contentVersion' => $action === 'withdraw' ? null : 'sha256:'.str_repeat('b', 64)];
    }

    private function envelope(array $targets, string $kind = 'content_delivery_v2'): array
    {
        return ['kind' => $kind, 'contract' => '2', 'sourceUrl' => 'https://api.test',
            'scope' => str_repeat('d', 64), 'keyFingerprint' => hash('sha256', 'pk_test_key'),
            'deliveredAt' => gmdate('Y-m-d\TH:i:s').'.000Z', 'deliveryId' => 'f3333333-3333-4333-8333-333333333333',
            'targets' => $targets];
    }

    private function notify(array $payload)
    {
        $body = json_encode($payload + ['deliveredAt' => gmdate('Y-m-d\TH:i:s\Z'), 'deliveryId' => bin2hex(random_bytes(16))]);
        return $this->call('POST', '/api/smking/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SMKING_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'notification-secret'),
        ], $body);
    }

    private function payload(string $resource, string $version = 'a'): array
    {
        $now = time();
        $iso = static fn (int $seconds): string => gmdate('Y-m-d\TH:i:s', $seconds).'.000Z';
        return ['status' => 'ready', 'delivery' => [
            'contract' => '2', 'content_version' => 'sha256:'.str_repeat($version, 64),
            'validated_at' => $iso($now), 'fresh_until' => $iso($now + 300), 'usable_until' => $iso($now + 3600),
        ]] + match ($resource) {
            'cms-page' => ['page' => ['slug' => 'article', 'bodyHtml' => '<main>Saved '.$version.'</main>']],
            'aeo' => ['jsonLd' => ['@type' => 'Product', 'name' => 'Saved '.$version]],
            'markdown' => ['document' => ['path' => '/article', 'body' => '# Saved '.$version, 'content_type' => 'text/markdown; charset=utf-8']],
            'site-file' => ['document' => ['kind' => 'sitemap', 'body' => '<urlset><!-- '.$version.' --></urlset>', 'content_type' => 'application/xml; charset=utf-8']],
        };
    }
}
