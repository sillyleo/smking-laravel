<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\Console\DeliveryPrewarmCommand;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;
use Symfony\Component\Console\Tester\CommandTester;

class DeliveryPreparationPlanTest extends TestCase
{
    private string $directory;
    private array $publications = [];
    private bool $available = true;
    private bool $omitPublication = false;
    private bool $denied = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/smking-preparation-plan-'.bin2hex(random_bytes(8));
        (new Filesystem())->makeDirectory($this->directory);
        config()->set('cache.stores.preparation_test', ['driver' => 'file', 'path' => $this->directory.'/cache']);
        config()->set('smking.cache.store', 'preparation_test');
        config()->set('smking.cache.enabled', true);
        config()->set('smking.delivery.mode', 'on_demand');
        config()->set('smking.delivery.aeo_enabled', true);
        config()->set('smking.delivery.notifications_enabled', false);
        config()->set('smking.delivery.work_max_jobs', 10);
        config()->set('smking.webhook_secret', 'preparation-secret');
        config()->set('app.url', 'https://shop.example.test');
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->denied) return Http::response(
                ['status' => 'unavailable', 'error' => 'invalid_key'],
                401,
                ['Content-Type' => 'application/json'],
            );
            if (! $this->available) return Http::response(['error' => 'unavailable'], 503);
            $resource = basename(parse_url($request->url(), PHP_URL_PATH));
            $identifier = match ($resource) {
                'cms-page' => 'slug:'.$request['slug'], 'site-file' => 'kind:'.$request['kind'],
                default => 'path:'.$request['path'],
            };
            $target = $this->publications[$resource.'|'.$identifier];
            $payload = $this->payload($target);
            if ($this->omitPublication) unset($payload['delivery']['publication']);
            return Http::response($payload, $target['action'] === 'withdraw' ? 404 : 200);
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_entire_fifty_item_release_is_checked_across_five_explicit_batches(): void
    {
        $targets = [];
        for ($i = 0; $i < 50; $i++) {
            $resource = ['cms-page', 'aeo', 'markdown'][$i % 3];
            $targets[] = $this->target($resource, ($resource === 'cms-page' ? 'slug:' : 'path:/').'article-'.$i);
        }
        $targets[49] = $this->target('site-file', 'kind:sitemap');
        $path = $this->plan($targets);
        $this->background();
        for ($offset = 0; $offset < 50; $offset += 10) {
            [$exit, $summary] = $this->runPlan($path, ['--offset' => (string) $offset]);
            $this->assertSame($offset === 40 ? 0 : 1, $exit, json_encode(array_diff_key($summary, ['items' => true])));
            $this->assertSame(50, $summary['requested']);
            $this->assertSame(10, $summary['processed']);
            $this->assertSame($offset + 10, $summary['ready']);
            $this->assertSame($offset === 40, $summary['complete']);
            $this->assertSame($offset === 40 ? null : $offset + 10, $summary['next_offset']);
        }
        $before = $this->hashes();
        [$exit, $summary] = $this->runPlan($path, ['--check' => true]);
        $this->assertSame(0, $exit, json_encode($summary));
        $this->assertSame('local_only', $summary['verification']);
        $this->assertSame(0, $summary['processed']);
        $this->assertSame($before, $this->hashes());
        Http::assertSentCount(50);
        $this->assertSame('on_demand', config('smking.delivery.mode'));
    }

    public function test_restored_old_copy_does_not_satisfy_a_new_release_and_stale_cdn_cannot_replace_it(): void
    {
        $old = $this->target();
        $path = $this->plan([$old]);
        $this->background();
        $this->assertSame(0, $this->runPlan($path)[0]);
        $new = $this->target(revision: 2);
        $path = $this->plan([$new], installSource: false);
        [$exit, $summary] = $this->runPlan($path, ['--check' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame(0, $summary['ready']);
        Http::assertSentCount(1);
        [$exit, $summary] = $this->runPlan($path);
        $this->assertSame(1, $exit);
        $this->assertSame('publication_mismatch', $summary['error']);
        $this->assertSame($old['contentVersion'], $this->app->make(OnDemandDelivery::class)->read('cms-page', 'slug:article')->snapshot->payload['delivery']['content_version']);
        $this->publications['cms-page|slug:article'] = $new;
        $this->assertSame(0, $this->runPlan($path)[0]);
        Http::assertSentCount(3);
    }

    public function test_failed_source_check_is_not_complete_even_if_local_versions_match(): void
    {
        $path = $this->plan([$this->target()]);
        $this->background();
        $this->assertSame(0, $this->runPlan($path)[0]);
        $this->available = false;
        [$exit, $summary] = $this->runPlan($path);
        $this->assertSame(1, $exit);
        $this->assertFalse($summary['complete']);
        $this->assertSame('upstream', $summary['error']);
        $this->assertNotNull($this->app->make(OnDemandDelivery::class)->peek('cms-page', 'slug:article')->snapshot);
        Http::assertSentCount(2);
    }

    public function test_newer_withdrawal_discovered_while_preparing_an_old_plan_blocks_old_content(): void
    {
        $old = $this->target();
        $path = $this->plan([$old]);
        $this->background();
        $this->assertSame(0, $this->runPlan($path)[0]);
        $withdraw = $this->target(revision: 2, action: 'withdraw');
        $this->publications['cms-page|slug:article'] = $withdraw;
        [$exit, $summary] = $this->runPlan($path);
        $this->assertSame(1, $exit);
        $this->assertFalse($summary['complete']);
        $this->assertSame('withdrawn', $this->app->make(OnDemandDelivery::class)->read('cms-page', 'slug:article')->error);
        $path = $this->plan([$withdraw]);
        [$exit, $summary] = $this->runPlan($path, ['--check' => true]);
        $this->assertSame(0, $exit, json_encode($summary));
        $this->assertSame('withdrawn', $summary['items'][0]['state']);
        $this->assertSame('local_only', $summary['verification']);
        Http::assertSentCount(2);
    }

    public function test_no_publication_evidence_never_satisfies_a_release_even_with_the_same_hash(): void
    {
        // Seed legacy-compatible v2 content without source ordering evidence.
        $target = $this->target();
        $payload = $this->payload($target);
        unset($payload['delivery']['publication']);
        $factory = new \Illuminate\Http\Client\Factory();
        $factory->preventStrayRequests()->fake(fn () => Http::response($payload, 200));
        $delivery = new OnDemandDelivery($this->app->make('cache')->store('preparation_test'), $factory, config());
        $this->assertNotNull($delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $path = $this->plan([$target]);
        $this->background();
        [$exit, $summary] = $this->runPlan($path, ['--check' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame(0, $summary['ready']);
        Http::assertNothingSent();
    }

    public function test_input_rejects_wrong_scope_duplicates_oversize_and_mixed_selection_before_http(): void
    {
        $target = $this->target();
        $path = $this->plan([$target]);
        $valid = json_decode(file_get_contents($path), true);
        foreach ([
            array_replace($valid, ['source' => 'https://foreign.test']),
            array_replace($valid, ['keyFingerprint' => str_repeat('a', 64)]),
            array_replace($valid, ['targets' => [$target, $target]]),
            array_replace($valid, ['targets' => []]),
            array_replace($valid, ['targets' => array_fill(0, 1001, $target)]),
            array_replace($valid, ['targets' => [array_replace($target, ['identifier' => 'slug:../private'])]]),
            array_replace($valid, ['targets' => [array_replace($target, ['generation' => 2])]]),
        ] as $input) {
            file_put_contents($path, json_encode($input));
            $this->assertSame(1, $this->runPlan($path)[0]);
        }
        file_put_contents($path, str_repeat(' ', 1_048_577));
        $this->assertSame(1, $this->runPlan($path)[0]);
        file_put_contents($path, json_encode($valid));
        foreach ([['--slug' => ['article']], ['--offset' => '-1'], ['--offset' => '1'],
            ['--offset' => '0', '--check' => true], ['--resource' => 'cms-page']] as $options) {
            $this->assertSame(1, $this->runPlan($path, $options)[0]);
        }
        $this->assertSame(1, $this->runPlan('https://foreign.test/plan.json')[0]);
        Http::assertNothingSent();
        $this->assertDirectoryDoesNotExist(config('smking.delivery.local_store_path'));
    }

    public function test_download_without_expected_publication_is_not_saved_as_a_prepared_release(): void
    {
        $path = $this->plan([$this->target()]);
        $this->background();
        $this->omitPublication = true;
        [$exit, $summary] = $this->runPlan($path);
        $this->assertSame(1, $exit);
        $this->assertSame('publication_mismatch', $summary['error']);
        $this->assertNull($this->app->make(OnDemandDelivery::class)->peek('cms-page', 'slug:article')->snapshot);
        Http::assertSentCount(1);
    }

    public function test_old_backup_cannot_pass_current_withdrawal_check_and_repreparation_restores_the_fence(): void
    {
        $path = $this->plan([$this->target()]);
        $this->background();
        $this->assertSame(0, $this->runPlan($path)[0]);
        $store = config('smking.delivery.local_store_path');
        $files = new Filesystem();
        $this->assertTrue($files->copyDirectory($store, $this->directory.'/backup'));
        $withdraw = $this->target(revision: 2, action: 'withdraw');
        $path = $this->plan([$withdraw]);
        $this->assertSame(0, $this->runPlan($path)[0]);
        $this->assertSame('withdrawn', $this->app->make(OnDemandDelivery::class)->read('cms-page', 'slug:article')->error);

        // Restored files are quarantined operationally: no visitors until source verification.
        $this->assertTrue($files->copyDirectory($this->directory.'/backup', $store));
        $this->assertNotNull($this->app->make(OnDemandDelivery::class)->peek('cms-page', 'slug:article')->snapshot);
        [$exit, $summary] = $this->runPlan($path, ['--check' => true]);
        $this->assertSame(1, $exit);
        $this->assertFalse($summary['complete']);
        Http::assertSentCount(2);
        $this->assertSame(0, $this->runPlan($path)[0]);
        $this->assertSame('withdrawn', $this->app->make(OnDemandDelivery::class)->read('cms-page', 'slug:article')->error);
        Http::assertSentCount(3);
    }

    public function test_source_denial_after_restore_blocks_preparation_and_public_read(): void
    {
        $path = $this->plan([$this->target()]);
        $this->background();
        $this->assertSame(0, $this->runPlan($path)[0]);
        $this->denied = true;
        [$exit, $summary] = $this->runPlan($path);
        $this->assertSame(1, $exit);
        $this->assertFalse($summary['complete']);
        $this->assertSame('access_denied', $summary['error']);
        $this->assertSame('access_denied', $this->app->make(OnDemandDelivery::class)->read('cms-page', 'slug:article')->error);
        Http::assertSentCount(2);
    }

    public function test_explicit_republication_without_webhook_can_advance_a_withdrawal_only_with_new_source_evidence(): void
    {
        $this->background();
        $withdraw = $this->target(revision: 2, action: 'withdraw');
        $this->assertSame(0, $this->runPlan($this->plan([$withdraw]))[0]);
        $new = array_replace($this->target(revision: 3), ['withdrawalRevision' => 2]);
        $path = $this->plan([$new], installSource: false);
        [$exit, $summary] = $this->runPlan($path);
        $this->assertSame(1, $exit);
        $this->assertSame('withdrawn', $this->app->make(OnDemandDelivery::class)->read('cms-page', 'slug:article')->error);
        $this->publications['cms-page|slug:article'] = $new;
        [$exit, $summary] = $this->runPlan($path);
        $this->assertSame(0, $exit, json_encode($summary));
        $this->assertSame($new['contentVersion'], $this->app->make(OnDemandDelivery::class)->read('cms-page', 'slug:article')->snapshot->payload['delivery']['content_version']);
        Http::assertSentCount(3);
    }

    private function background(): void
    {
        $this->artisan('smking:delivery:work', ['--prepare' => true])->assertExitCode(0);
        $this->artisan('smking:delivery:report', ['--prepare' => true])->assertExitCode(0);
    }

    private function runPlan(string $path, array $options = []): array
    {
        $this->app->offsetUnset(\Illuminate\Console\OutputStyle::class);
        $command = new DeliveryPrewarmCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--plan' => $path] + $options);
        return [$exit, json_decode(trim($tester->getDisplay()), true)];
    }

    private function plan(array $targets, bool $installSource = true): string
    {
        if ($installSource) foreach ($targets as $target) $this->publications[$target['resource'].'|'.$target['identifier']] = $target;
        $path = $this->directory.'/release.json';
        file_put_contents($path, json_encode(['format' => 1, 'source' => 'https://api.test',
            'keyFingerprint' => hash('sha256', 'pk_test_key'), 'targets' => $targets]));
        return $path;
    }

    private function target(string $resource = 'cms-page', string $identifier = 'slug:article', int $revision = 1, string $action = 'update'): array
    {
        return ['resource' => $resource, 'identifier' => $identifier, 'action' => $action,
            'revision' => $revision, 'generation' => $revision, 'withdrawalRevision' => $action === 'withdraw' ? $revision : 0,
            'contentVersion' => $action === 'withdraw' ? null : 'sha256:'.hash('sha256', $resource.'|'.$identifier.'|'.$revision)];
    }

    private function payload(array $target): array
    {
        $now = time();
        $iso = fn (int $seconds): string => gmdate('Y-m-d\TH:i:s', $seconds).'.000Z';
        $missing = $target['action'] === 'withdraw';
        $body = $missing ? [] : match ($target['resource']) {
            'cms-page' => ['page' => ['slug' => substr($target['identifier'], 5), 'title' => 'Published', 'bodyHtml' => '<main>Published</main>']],
            'aeo' => ['jsonLd' => ['@type' => 'Product']],
            'markdown' => ['document' => ['path' => substr($target['identifier'], 5), 'body' => '# Published', 'content_type' => 'text/markdown; charset=utf-8']],
            'site-file' => ['document' => ['kind' => substr($target['identifier'], 5), 'body' => 'Published',
                'content_type' => $target['identifier'] === 'kind:sitemap' ? 'application/xml; charset=utf-8' : 'text/plain; charset=utf-8']],
        };
        return ['status' => $missing ? 'not_found' : 'ready', 'delivery' => [
            'contract' => '2', 'content_version' => $target['contentVersion'] ?? 'sha256:'.str_repeat('0', 64),
            'validated_at' => $iso($now), 'fresh_until' => $iso($now + ($missing ? 60 : 300)),
            'usable_until' => $iso($now + ($missing ? 60 : 3600)), 'publication' => $target,
        ]] + $body;
    }

    private function hashes(): array
    {
        $hashes = [];
        foreach ((new Filesystem())->allFiles(config('smking.delivery.local_store_path')) as $file) {
            $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes);
        return $hashes;
    }
}
