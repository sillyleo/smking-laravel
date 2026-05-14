<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Data\CmsPage;

class CmsClientTest extends TestCase
{
    public function test_for_slug_returns_ready_with_rendered_html(): void
    {
        Http::fake([
            'api.test/api/v1/public/page*' => Http::response([
                'status' => 'ready',
                'page' => [
                    'slug' => 'hello',
                    'title' => 'Hello world',
                    'body' => [
                        'type' => 'doc',
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [[
                                'type' => 'text',
                                'text' => 'Example.',
                            ]],
                        ]],
                    ],
                    'publishedAt' => '2026-05-14T10:00:00Z',
                ],
            ], 200),
        ]);

        $page = $this->app->make(CmsClient::class)->forSlug('hello');

        $this->assertTrue($page->isReady());
        $this->assertSame('hello', $page->slug);
        $this->assertSame('Hello world', $page->title);
        $this->assertSame('2026-05-14T10:00:00Z', $page->publishedAt);
        $this->assertNotNull($page->bodyHtml);
        // tiptap-php renders ProseMirror paragraph → <p>...</p>
        $this->assertStringContainsString('<p>Example.</p>', $page->bodyHtml);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.test/api/v1/public/page')
                && $request['key'] === 'pk_test_key'
                && $request['slug'] === 'hello';
        });
    }

    public function test_404_returns_not_found(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        $page = $this->app->make(CmsClient::class)->forSlug('missing');

        $this->assertSame(CmsPage::STATUS_NOT_FOUND, $page->status);
        $this->assertNull($page->bodyHtml);
    }

    public function test_5xx_returns_server_error(): void
    {
        Http::fake(['*' => Http::response('boom', 503)]);

        $page = $this->app->make(CmsClient::class)->forSlug('hello');

        $this->assertSame(CmsPage::STATUS_SERVER_ERROR, $page->status);
    }

    public function test_payload_status_not_ready_passes_through(): void
    {
        // SaaS may return 200 + status=not_found if slug doesn't exist
        Http::fake([
            '*' => Http::response(['status' => 'not_found'], 200),
        ]);

        $page = $this->app->make(CmsClient::class)->forSlug('hello');

        $this->assertSame(CmsPage::STATUS_NOT_FOUND, $page->status);
    }

    public function test_missing_api_key_short_circuits(): void
    {
        config()->set('smking.api_key', null);
        Http::fake();

        $page = $this->app->make(CmsClient::class)->forSlug('hello');

        $this->assertSame(CmsPage::STATUS_NOT_FOUND, $page->status);
        Http::assertNothingSent();
    }

    public function test_missing_base_url_short_circuits(): void
    {
        config()->set('smking.base_url', null);
        Http::fake();

        $page = $this->app->make(CmsClient::class)->forSlug('hello');

        $this->assertSame(CmsPage::STATUS_NOT_FOUND, $page->status);
        Http::assertNothingSent();
    }

    public function test_propagates_seo_block_to_CmsPage(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'page' => [
                    'slug' => 'hello',
                    'title' => 'Hello world',
                    'body' => ['type' => 'doc', 'content' => []],
                    'publishedAt' => '2026-05-14T10:00:00Z',
                ],
                'seo' => [
                    'title' => 'Hello world | SmKing',
                    'metaDescription' => 'A friendly hello article.',
                    'ogTitle' => 'Say hi',
                    'ogDescription' => 'Friendly intro for sharing',
                    'ogImageUrl' => 'https://imagedelivery.net/x/cover/public',
                    'canonicalUrl' => 'https://example.com/hello',
                ],
            ], 200),
        ]);

        $page = $this->app->make(CmsClient::class)->forSlug('hello');

        $this->assertTrue($page->isReady());
        $this->assertIsArray($page->seo);
        $this->assertSame('Hello world | SmKing', $page->seo['title']);
        $this->assertSame('A friendly hello article.', $page->seo['metaDescription']);
        $this->assertSame('Say hi', $page->seo['ogTitle']);
        $this->assertSame(
            'https://imagedelivery.net/x/cover/public',
            $page->seo['ogImageUrl'],
        );
        $this->assertSame('https://example.com/hello', $page->seo['canonicalUrl']);
    }

    public function test_missing_seo_block_resolves_to_null(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'page' => [
                    'slug' => 'hello',
                    'title' => '',
                    'body' => ['type' => 'doc'],
                    'publishedAt' => null,
                ],
                // no seo key
            ], 200),
        ]);

        $page = $this->app->make(CmsClient::class)->forSlug('hello');

        $this->assertTrue($page->isReady());
        $this->assertNull($page->seo);
    }

    public function test_renders_gallery_node_via_php_extension(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'page' => [
                    'slug' => 'hello',
                    'title' => '',
                    'body' => [
                        'type' => 'doc',
                        'content' => [[
                            'type' => 'gallery',
                            'attrs' => [
                                'images' => [
                                    ['url' => 'https://imagedelivery.net/x/1/public', 'alt' => 'one'],
                                    ['url' => 'https://imagedelivery.net/x/2/public', 'alt' => 'two'],
                                ],
                                'layout' => 'grid',
                                'columns' => 3,
                            ],
                        ]],
                    ],
                    'publishedAt' => null,
                ],
            ], 200),
        ]);

        $page = $this->app->make(CmsClient::class)->forSlug('hello');

        $this->assertTrue($page->isReady());
        $this->assertNotNull($page->bodyHtml);
        // Markup contract — must match SaaS gallery-node-extension.ts +
        // smking-next gallery-node.tsx output. Don't change without
        // updating the other two SDK renderers in lockstep.
        $this->assertStringContainsString('data-type="gallery"', $page->bodyHtml);
        $this->assertStringContainsString('class="smk-gallery smk-gallery--grid"', $page->bodyHtml);
        $this->assertStringContainsString('<figure class="smk-gallery__item">', $page->bodyHtml);
        $this->assertStringContainsString('src="https://imagedelivery.net/x/1/public"', $page->bodyHtml);
        $this->assertStringContainsString('alt="one"', $page->bodyHtml);
        $this->assertStringContainsString('loading="lazy"', $page->bodyHtml);
    }
}
