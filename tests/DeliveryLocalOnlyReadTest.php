<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Smking\Laravel\AeoClient;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Delivery\DeliveryWorklist;
use Smking\Laravel\Delivery\DeliveryTargetState;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;
use Smking\Laravel\Http\Controllers\CmsPageController;
use Smking\Laravel\Http\Controllers\SitemapController;
use Smking\Laravel\Http\Controllers\LlmsTxtController;
use Smking\Laravel\Http\Middleware\InjectAeo;

class DeliveryLocalOnlyReadTest extends TestCase
{
    private string $directory;
    private \Closure $respond;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/smking-local-read-'.bin2hex(random_bytes(8));
        config()->set('cache.stores.local_read_test', ['driver' => 'file', 'path' => $this->directory]);
        config()->set('smking.cache.store', 'local_read_test');
        config()->set('smking.cache.enabled', true);
        config()->set('smking.delivery.mode', 'on_demand');
        Http::preventStrayRequests();
        $this->respond = fn () => Http::response('unavailable', 503);
        Http::fake(fn ($request) => ($this->respond)($request));
        $this->assertTrue($this->app->make(DeliveryWorklist::class)->prepare());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_cold_cms_visitor_never_fetches_or_schedules_content(): void
    {
        $this->assertSame('server_error', $this->app->make(CmsClient::class)->forSlug('unknown')->status);
        Http::assertNothingSent();
        $this->assertSame(0, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
    }

    public function test_cold_aeo_visitor_never_fetches_or_schedules_content(): void
    {
        $this->assertFalse($this->app->make(AeoClient::class)->forPath('/products/unknown')->isReady());
        Http::assertNothingSent();
        $this->assertSame(0, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
    }

    public function test_repeated_and_fifty_different_cold_paths_do_not_create_content_work(): void
    {
        $cms = $this->app->make(CmsClient::class);
        $aeo = $this->app->make(AeoClient::class);
        for ($i = 0; $i < 50; $i++) {
            foreach (['same', 'article-'.$i] as $slug) {
                $this->assertSame('server_error', $cms->forSlug($slug)->status);
                $this->assertFalse($aeo->forPath('/'.$slug)->isReady());
                $this->assertNull($aeo->getMarkdown('/'.$slug));
            }
            $this->assertFalse($aeo->forProductId($i + 1)->isReady());
        }
        foreach (['sitemap', 'robots', 'llms_txt'] as $kind) $this->assertNull($aeo->fetchPublicFile($kind));
        Http::assertNothingSent();
        $this->assertSame(0, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
    }

    public function test_public_routes_keep_original_product_html_and_return_503_for_unprepared_blog_and_files(): void
    {
        Route::get('/blog/{path}', CmsPageController::class);
        Route::get('/sitemap.xml', SitemapController::class);
        Route::get('/llms.txt', LlmsTxtController::class);
        Route::get('/products/article', fn () => response('<html><head></head><body>Original product</body></html>'))
            ->middleware(InjectAeo::class);
        $this->get('/blog/article')->assertStatus(503);
        $this->get('/sitemap.xml')->assertStatus(503);
        $this->get('/llms.txt')->assertStatus(503);
        $this->get('/products/article')->assertOk()->assertSee('Original product');
        $this->get('/products/article', ['Accept' => 'text/markdown'])->assertOk()->assertSee('Original product');
        $this->head('/products/article')->assertOk();
        Http::assertNothingSent();
        $this->assertSame(0, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
    }

    public function test_public_file_routes_return_404_for_confirmed_missing_files(): void
    {
        $this->respond = fn () => Http::response(
            $this->missingPayload(),
            404,
            ['Content-Type' => 'application/json'],
        );
        $delivery = $this->app->make(OnDemandDelivery::class);
        $this->assertSame(404, $delivery->refresh('site-file', 'kind:sitemap', new WaitBudget(500))->httpStatus);
        $this->assertSame(404, $delivery->refresh('site-file', 'kind:llms_txt', new WaitBudget(500))->httpStatus);

        Route::get('/sitemap.xml', SitemapController::class);
        Route::get('/llms.txt', LlmsTxtController::class);
        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/llms.txt')->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_public_file_routes_return_503_when_confirmed_missing_has_a_pending_update(): void
    {
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('a', 64));
        $this->respond = fn () => Http::response(
            $this->missingPayload(),
            404,
            ['Content-Type' => 'application/json'],
        );
        $delivery = $this->app->make(OnDemandDelivery::class);
        $targets = $this->app->make(DeliveryTargetState::class);
        foreach (['sitemap', 'llms_txt'] as $kind) {
            $identifier = 'kind:'.$kind;
            $this->assertSame(404, $delivery->refresh('site-file', $identifier, new WaitBudget(500))->httpStatus);
            $this->assertSame('applied', $targets->apply([
                'resource' => 'site-file', 'identifier' => $identifier, 'action' => 'update',
                'revision' => 1, 'generation' => 1, 'withdrawalRevision' => 0,
                'contentVersion' => 'sha256:'.str_repeat('b', 64),
            ])['status']);
            $this->assertSame('target_pending', $delivery->peek('site-file', $identifier)->error);
        }

        Route::get('/sitemap.xml', SitemapController::class);
        Route::get('/llms.txt', LlmsTxtController::class);
        $this->get('/sitemap.xml')->assertStatus(503);
        $this->get('/llms.txt')->assertStatus(503);
        Http::assertSentCount(2);
    }

    public function test_warm_content_does_not_depend_on_a_page_network_budget_or_worker_health(): void
    {
        $this->respond = fn () => Http::response($this->payload(), 200);
        $delivery = $this->app->make(OnDemandDelivery::class);
        $this->assertNotNull($delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        config()->set('smking.delivery.page_budget_ms', 'invalid-no-network-needed');
        $this->app->make('cache')->store('local_read_test')->flush();
        $this->assertTrue($this->app->make(CmsClient::class)->forSlug('article')->isReady());
        Http::assertSentCount(1);
        $this->assertSame(0, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
    }

    public function test_known_pending_version_never_causes_visitor_work_registration(): void
    {
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('a', 64));
        $targets = $this->app->make(DeliveryTargetState::class);
        foreach (['cms-page' => 'slug:article', 'aeo' => 'path:/article', 'markdown' => 'path:/article', 'site-file' => 'kind:sitemap'] as $resource => $identifier) {
            $this->assertSame('applied', $targets->apply([
                'resource' => $resource, 'identifier' => $identifier, 'action' => 'update',
                'revision' => 1, 'generation' => 1, 'withdrawalRevision' => 0,
                'contentVersion' => 'sha256:'.str_repeat('b', 64),
            ])['status']);
            $this->assertSame('target_pending', $this->app->make(OnDemandDelivery::class)->read($resource, $identifier)->error);
        }
        Http::assertNothingSent();
        $this->assertSame(0, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
    }

    public function test_lost_or_corrupt_local_body_fails_without_visitor_repair(): void
    {
        $this->respond = fn () => Http::response($this->payload(), 200);
        $delivery = $this->app->make(OnDemandDelivery::class);
        $this->assertNotNull($delivery->refresh('cms-page', 'slug:article', new WaitBudget(500))->snapshot);
        $key = 'smking:delivery:v2:c1:'.substr(hash('sha256', 'pk_test_key|https://api.test'), 0, 24).':state:'.hash('sha256', 'cms-page|slug:article');
        $store = new \Smking\Laravel\Delivery\DeliveryLocalStore(config('smking.delivery.local_store_path'));
        $store->write($key, ['corrupt' => true]);
        $this->assertSame('server_error', $this->app->make(CmsClient::class)->forSlug('article')->status);
        unlink(config('smking.delivery.local_store_path').'/'.hash('sha256', $key).'.json');
        $this->assertSame('server_error', $this->app->make(CmsClient::class)->forSlug('article')->status);
        Http::assertSentCount(1);
        $this->assertSame(0, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
    }

    public function test_confirmed_withdrawal_is_a_local_404_not_a_download_trigger(): void
    {
        config()->set('smking.delivery.notifications_enabled', true);
        config()->set('smking.delivery.notifications_scope', str_repeat('a', 64));
        $this->app->make(DeliveryTargetState::class)->apply([
            'resource' => 'cms-page', 'identifier' => 'slug:article', 'action' => 'withdraw',
            'revision' => 1, 'generation' => 0, 'withdrawalRevision' => 1, 'contentVersion' => null,
        ]);
        Route::get('/blog/{path}', CmsPageController::class);
        $this->get('/blog/article')->assertNotFound();
        Http::assertNothingSent();
        $this->assertSame(0, $this->app->make(DeliveryWorklist::class)->status()['counts']['pending']);
    }

    private function payload(): array
    {
        $now = time();
        $iso = static fn (int $seconds): string => gmdate('Y-m-d\TH:i:s', $seconds).'.000Z';
        return ['status' => 'ready', 'page' => ['slug' => 'article', 'title' => 'Local', 'bodyHtml' => '<main>Local</main>'],
            'delivery' => ['contract' => '2', 'content_version' => 'sha256:'.str_repeat('b', 64),
                'validated_at' => $iso($now), 'fresh_until' => $iso($now + 300), 'usable_until' => $iso($now + 3600)]];
    }

    private function missingPayload(): array
    {
        $now = time();
        $iso = static fn (int $seconds): string => gmdate('Y-m-d\TH:i:s', $seconds).'.000Z';

        return ['status' => 'not_found', 'delivery' => [
            'contract' => '2', 'content_version' => 'sha256:'.str_repeat('c', 64),
            'validated_at' => $iso($now), 'fresh_until' => $iso($now + 60), 'usable_until' => $iso($now + 60),
        ]];
    }
}
