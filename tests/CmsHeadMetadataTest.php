<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Data\CmsPage;

class CmsHeadMetadataTest extends TestCase
{
    private function mount(string $path, CmsPage $cms): void
    {
        Route::get($path, static fn () => response(Blade::render(
            '<html lang="zh-TW"><head><title>網站預設</title>'
            .'<meta name="description" content="舊描述">'
            .'<meta name="twitter:title" content="舊標題">'
            .'<script type="application/ld+json">{"@type":"Organization","name":"眠豆腐"}</script>'
            .'</head><body>@include("smking::cms", ["cms" => $cms])</body></html>',
            ['cms' => $cms],
        ), 200, ['Content-Type' => 'text/html']));
    }

    private function page(): CmsPage
    {
        return new CmsPage(
            status: 'ready', slug: 'nested/duvet', title: '文章標題',
            bodyHtml: '<h1>文章標題</h1><img src="https://cdn.test/duvet.webp" alt="棉被材質表">',
            publishedAt: '2026-10-29T03:00:00Z',
            seo: ['title' => '人工 SEO 標題', 'metaDescription' => '人工 SEO 描述',
                'ogTitle' => '人工分享標題', 'ogDescription' => '人工分享描述',
                'ogImageUrl' => 'https://cdn.test/duvet.webp', 'canonicalUrl' => null],
            contentType: 'article',
        );
    }

    public function test_existing_direct_include_gets_metadata_with_auto_aeo_disabled_and_no_extra_http(): void
    {
        config()->set('smking.auto_inject', false);
        Http::preventStrayRequests();
        $this->mount('/blog/nested/duvet', $this->page());
        $response = $this->get('/blog/nested/duvet?utm_source=test')->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<title'));
        $this->assertSame(1, substr_count($html, 'name="description"'));
        $this->assertSame(1, substr_count($html, 'name="twitter:title"'));
        $this->assertStringContainsString('content="人工分享標題"', $html);
        $this->assertStringContainsString('property="og:site_name" content="眠豆腐"', $html);
        $this->assertStringContainsString('property="og:locale" content="zh_TW"', $html);
        $this->assertStringContainsString('property="og:type" content="article"', $html);
        $this->assertStringContainsString('name="twitter:image" content="https://cdn.test/duvet.webp"', $html);
        $this->assertStringContainsString('property="og:image:alt" content="棉被材質表"', $html);
        $this->assertStringNotContainsString('utm_source', $html);
        $this->assertStringNotContainsString('og:image:width', $html);
        $this->assertStringContainsString('人工 SEO 描述', $html);
        Http::assertNothingSent();
    }

    public function test_cms_manual_metadata_wins_over_an_older_aeo_response(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ready', 'metaDescription' => '舊生成描述',
            'jsonLd' => ['@type' => 'WebPage'],
            'seo' => ['title' => '舊生成標題', 'ogTitle' => '舊分享標題', 'ogImageUrl' => 'https://cdn.test/old.jpg']], 200)]);
        $this->mount('/blog/nested/duvet', $this->page());
        $html = $this->get('/blog/nested/duvet')->assertOk()->getContent();
        $this->assertStringContainsString('人工 SEO 標題', $html);
        $this->assertStringContainsString('人工分享標題', $html);
        $this->assertStringNotContainsString('舊生成標題', $html);
        $head = explode('</head>', $html, 2)[0];
        $this->assertStringNotContainsString('舊分享標題', $head);
        $this->assertSame(1, substr_count($html, 'property="og:image"'));
        $this->assertSame(1, substr_count($html, 'name="twitter:image"'));
    }

    public function test_embedding_a_different_cms_page_does_not_replace_the_host_head(): void
    {
        config()->set('smking.auto_inject', false);
        Http::preventStrayRequests();
        $this->mount('/contact', $this->page());
        $html = $this->get('/contact')->assertOk()->getContent();
        $this->assertStringContainsString('<title>網站預設</title>', $html);
        $this->assertStringNotContainsString('人工 SEO 標題', $html);
        Http::assertNothingSent();
    }

    public function test_manual_dollars_and_html_are_preserved_as_text(): void
    {
        config()->set('smking.auto_inject', false);
        Http::preventStrayRequests();
        $title = 'Price $1 </title><script>alert(1)</script>';
        $cms = new CmsPage(status: 'ready', slug: 'literal', title: $title,
            bodyHtml: '<p>Original body</p>', seo: ['title' => $title, 'metaDescription' => 'Price $1 & "value"']);
        $this->mount('/blog/literal', $cms);
        $html = $this->get('/blog/literal')->assertOk()->getContent();
        $this->assertStringContainsString('Price $1 &lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('content="Price $1 &amp; &quot;value&quot;"', $html);
        $this->assertSame(1, substr_count($html, '<title'));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        Http::assertNothingSent();
    }

    public function test_upgrading_keeps_serialized_v0231_pages_usable_without_clearing_cache(): void
    {
        config()->set('smking.auto_inject', false);
        config()->set('smking.delivery.mode', 'legacy');
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.store', 'file');
        config()->set('cache.stores.file.path', config('smking.delivery.local_store_path').'/legacy-cache');
        Http::preventStrayRequests();
        // Generated using the actual parent commit's CmsPage class.
        $old = unserialize(file_get_contents(__DIR__.'/fixtures/cms-page-v0.23.1.serialized'), ['allowed_classes' => [CmsPage::class]]);
        $this->assertInstanceOf(CmsPage::class, $old);
        $namespace = substr(hash('sha256', config('smking.api_key').'|'.config('smking.base_url')), 0, 12);
        Cache::store('file')->put('smking:cms:'.$namespace.':nested/duvet', $old, 3600);
        $clients = [app(CmsClient::class), new CmsClient(
            app(\Illuminate\Http\Client\Factory::class), app(\Illuminate\Contracts\Cache\Factory::class),
            app(\Illuminate\Contracts\Config\Repository::class), app(\Smking\Laravel\Tiptap\EditorFactory::class),
        )];
        foreach ($clients as $client) {
            $cached = $client->forSlug('nested/duvet');
            $this->assertSame('Cached CMS title', $cached->title);
            $this->assertFalse((new \ReflectionProperty(CmsPage::class, 'jsonLd'))->isInitialized($cached));
            $this->assertFalse((new \ReflectionProperty(CmsPage::class, 'contentType'))->isInitialized($cached));
            $this->mount('/blog/nested/duvet', $cached);
            $html = $this->get('/blog/nested/duvet')->assertOk()->getContent();
            $this->assertStringContainsString('Cached SEO title', $html);
            $this->assertStringContainsString('Cached editorial description', $html);
            $this->assertStringContainsString('Cached editorial body', $html);
        }
        Http::assertNothingSent();
    }

    public function test_head_comments_and_script_examples_are_not_interpreted_as_tags(): void
    {
        config()->set('smking.auto_inject', false);
        Http::preventStrayRequests();
        $comment = '<!-- <title>Example title</title><meta property="og:site_name" content="Example site"> -->';
        $script = '<script>const example = \'<meta name="twitter:title" content="Example tag">\';</script>';
        $cms = $this->page();
        Route::get('/blog/nested/duvet', static fn () => response(Blade::render(
            '<html><head>'.$comment.$script.'<title>Old one</title><title>Old two</title>'
            .'</head><body>@include("smking::cms", ["cms" => $cms])</body></html>',
            ['cms' => $cms],
        ), 200, ['Content-Type' => 'text/html']));
        $html = $this->get('/blog/nested/duvet')->assertOk()->getContent();
        $this->assertStringContainsString($comment, $html);
        $this->assertStringContainsString($script, $html);
        $this->assertStringNotContainsString('<title>Old one</title>', $html);
        $this->assertStringNotContainsString('<title>Old two</title>', $html);
        $this->assertSame(1, substr_count($html, '<title data-smking="cms-seo">人工 SEO 標題</title>'));
        $this->assertSame(1, substr_count($html, 'property="og:site_name"'));
        Http::assertNothingSent();
    }

    public function test_head_rewriting_preserves_svg_titles_and_metadata_examples_in_the_body(): void
    {
        config()->set('smking.auto_inject', false);
        Http::preventStrayRequests();
        $body = '<svg><title>Accessible diagram title</title></svg>'
            .'<template><meta name="description" content="Example description"></template>'
            .'<script>const example = \'<meta name="twitter:title" content="Example tag">\';</script>';
        $cms = new CmsPage(status: 'ready', slug: 'diagram', title: 'Manual article', bodyHtml: $body,
            seo: ['title' => 'Manual article', 'metaDescription' => 'Manual description']);
        Route::get('/blog/diagram', static fn () => response(Blade::render(
            '<html><head><meta charset="utf-8"></head><body>@include("smking::cms", ["cms" => $cms])</body></html>',
            ['cms' => $cms],
        ), 200, ['Content-Type' => 'text/html']));
        $html = $this->get('/blog/diagram')->assertOk()->getContent();
        $this->assertStringContainsString($body, $html);
        $this->assertStringContainsString('<title data-smking="cms-seo">Manual article</title>', $html);
        Http::assertNothingSent();

        config()->set('smking.auto_inject', true);
        Http::fake(['*' => Http::response(['status' => 'ready', 'metaDescription' => 'Generated description',
            'jsonLd' => ['@type' => 'WebPage'], 'seo' => ['title' => 'Generated title', 'ogTitle' => 'Generated share title']], 200)]);
        $withAeo = $this->get('/blog/diagram')->assertOk()->getContent();
        $this->assertStringContainsString($body, $withAeo);
        $this->assertStringContainsString('<title data-smking="cms-seo">Manual article</title>', $withAeo);
    }
}
