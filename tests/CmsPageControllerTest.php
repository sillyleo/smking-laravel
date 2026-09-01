<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Smking\Laravel\Http\Controllers\CmsPageController;

class CmsPageControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        View::addLocation(__DIR__.'/fixtures/views');
        Route::get('/blog/{path?}', [CmsPageController::class, '__invoke'])
            ->where('path', '.*');
    }

    public function test_ready_page_renders_with_http_200_and_one_cms_fetch(): void
    {
        Http::fake([
            'api.test/api/v1/public/page*' => Http::response([
                'status' => 'ready',
                'page' => [
                    'slug' => 'hello',
                    'title' => 'Hello',
                    'bodyHtml' => '<article>Rendered once</article>',
                    'publishedAt' => '2026-09-01T00:00:00Z',
                ],
            ]),
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $this->get('/blog/hello')
            ->assertOk()
            ->assertSee('Rendered once', false);

        $cmsRequests = collect(Http::recorded())
            ->filter(fn (array $record): bool => str_contains(
                $record[0]->url(),
                '/api/v1/public/page',
            ));
        $this->assertCount(1, $cmsRequests);
    }

    public function test_missing_page_returns_http_404(): void
    {
        Http::fake([
            'api.test/api/v1/public/page*' => Http::response([
                'status' => 'not_found',
            ]),
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $this->get('/blog/missing')->assertNotFound();
    }

    public function test_pending_page_returns_http_503(): void
    {
        Http::fake([
            'api.test/api/v1/public/page*' => Http::response([
                'status' => 'pending',
            ]),
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $this->get('/blog/pending')->assertStatus(503);
    }

    public function test_upstream_failure_returns_http_503(): void
    {
        Http::fake([
            'api.test/api/v1/public/page*' => Http::response('unavailable', 503),
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $this->get('/blog/unavailable')->assertStatus(503);
    }
}
