<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Smking\Laravel\AeoClient;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Http\Controllers\CmsPageController;

class DeliveryLegacyAndBlogBaselineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        View::addLocation(__DIR__.'/fixtures/views');
        Route::get('/delivery-baseline-blog/{path?}', CmsPageController::class)
            ->where('path', '.*');

        // Simulate a customer's published or cached config from before the
        // delivery settings exist. Installing a newer SDK must not opt in.
        config()->offsetUnset('smking.delivery');
    }

    public function test_missing_delivery_settings_preserve_legacy_aeo_and_cms_contracts(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.test/api/v1/public/aeo') {
                return Http::response([
                    'status' => 'ready',
                    'summary' => '既有 AEO 摘要',
                ]);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.test/api/v1/public/page?')) {
                return Http::response($this->publishedBlog('existing'));
            }

            return Http::response(['error' => 'unexpected_request'], 500);
        });

        $aeo = $this->app->make(AeoClient::class)->forPath(
            '/products/existing',
            'https://shop.test/products/existing',
        );
        $cms = $this->app->make(CmsClient::class)->forSlug('existing');

        $this->assertTrue($aeo->isReady());
        $this->assertSame('既有 AEO 摘要', $aeo->summary);
        $this->assertTrue($cms->isReady());
        $this->assertStringContainsString('眠豆腐文章', (string) $cms->bodyHtml);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/'));
    }

    public function test_disabling_aeo_keeps_visible_blog_content_and_assets_without_aeo_request(): void
    {
        config()->set('smking.auto_inject', false);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.test/api/v1/public/page?')) {
                return Http::response($this->publishedBlog('existing'));
            }

            return Http::response(['error' => 'unexpected_request'], 500);
        });

        $response = $this->get('/delivery-baseline-blog/existing');

        $response->assertOk()
            ->assertHeader('X-Smking-Status', 'disabled')
            ->assertSee('<title>眠豆腐文章</title>', false)
            ->assertSee('<h1 class="article-title">眠豆腐文章</h1>', false)
            ->assertSee('<img class="article-cover" src="/images/tofu.jpg" alt="眠豆腐">', false)
            ->assertSee('<ul class="article-list">', false)
            ->assertSee('href="/blog/category/news"', false)
            ->assertSee('href="/blog/next"', false)
            ->assertSee('/api/v1/public/runtime.css', false)
            ->assertSee('/api/v1/public/theme.css?key=pk_test_key', false)
            ->assertSee('/api/v1/public/runtime.js', false)
            ->assertDontSee('data-smking-injected', false);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.test/api/v1/public/page?')
            && $request['slug'] === 'existing');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/public/aeo'));
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedBlog(string $slug): array
    {
        return [
            'status' => 'ready',
            'page' => [
                'slug' => $slug,
                'title' => '眠豆腐文章',
                'bodyHtml' => '<article class="article-body">'
                    .'<h1 class="article-title">眠豆腐文章</h1>'
                    .'<img class="article-cover" src="/images/tofu.jpg" alt="眠豆腐">'
                    .'<ul class="article-list"><li>保留本文</li></ul>'
                    .'<a href="/blog/category/news">最新消息</a>'
                    .'<a href="/blog/next">下一篇</a>'
                    .'</article>',
                'publishedAt' => '2026-09-01T00:00:00Z',
            ],
        ];
    }
}
