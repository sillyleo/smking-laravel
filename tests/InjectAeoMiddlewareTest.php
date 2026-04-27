<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\Http\Middleware\InjectAeo;

class InjectAeoMiddlewareTest extends TestCase
{
    public function test_injects_json_ld_and_summary_into_html_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'jsonLd' => ['@type' => 'Product', 'name' => 'Widget'],
                'summary' => 'Nice.',
                'metaDescription' => 'Buy widget.',
                'faqHtml' => '<section class="smking-faq">FAQ</section>',
                'summaryHtml' => '<section class="smking-summary">SUM</section>',
            ], 200),
        ]);

        /** @var InjectAeo $middleware */
        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head><title>X</title></head><body><h1>Product</h1></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = $response->getContent();

        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('"name":"Widget"', $html);
        $this->assertStringContainsString('<meta name="description" content="Buy widget."', $html);
        $this->assertStringContainsString('smking-summary', $html);
        $this->assertStringContainsString('smking-faq', $html);
        $this->assertStringContainsString('data-smking-injected="1"', $html);
    }

    public function test_skips_non_html_responses(): void
    {
        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/api/data', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']);
        });

        $this->assertSame('{"ok":true}', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_skips_when_path_is_excluded(): void
    {
        config()->set('smking.except', ['admin*']);
        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/admin/dashboard', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('<html><body>admin</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $this->assertStringNotContainsString('smking', (string) $response->getContent());
        Http::assertNothingSent();
    }

    public function test_injects_seo_meta_when_host_has_no_existing_tags(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'seo' => [
                    'title' => 'Blue Widget · Acme',
                    'ogTitle' => 'The Best Blue Widget',
                    'ogDescription' => 'Hand-cured benefits.',
                    'ogImageUrl' => 'https://example.com/widget.png',
                    'canonicalUrl' => 'https://example.com/products/blue-widget',
                ],
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/blue-widget', 'GET');
        // Note: no <title>, no og:*, no canonical in the host HTML.
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head><meta charset="utf-8"></head><body><h1>Widget</h1></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();

        $this->assertStringContainsString('<title data-smking="seo">Blue Widget · Acme</title>', $html);
        $this->assertStringContainsString('property="og:title" content="The Best Blue Widget"', $html);
        $this->assertStringContainsString('property="og:description" content="Hand-cured benefits."', $html);
        $this->assertStringContainsString('property="og:image" content="https://example.com/widget.png"', $html);
        $this->assertStringContainsString('rel="canonical" href="https://example.com/products/blue-widget"', $html);
    }

    public function test_does_not_override_existing_title_or_canonical(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'seo' => [
                    'title' => 'API Title',
                    'ogTitle' => 'API OG Title',
                    'canonicalUrl' => 'https://example.com/api-canonical',
                ],
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET');
        // Host already wrote <title> and canonical — they MUST be preserved
        // (mirrors WP filter coexistence + Next.js mergeMetadata strategy:
        // we fill gaps, never override).
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head><title>Host Title</title><link rel="canonical" href="/host-canonical"></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();

        // Host tags untouched.
        $this->assertStringContainsString('<title>Host Title</title>', $html);
        $this->assertStringNotContainsString('API Title', $html);
        $this->assertStringContainsString('href="/host-canonical"', $html);
        $this->assertStringNotContainsString('api-canonical', $html);
        // og:title still injected because host didn't write it.
        $this->assertStringContainsString('property="og:title" content="API OG Title"', $html);
    }

    public function test_seo_flags_disable_individual_tags(): void
    {
        config()->set('smking.inject.seo_title', false);
        config()->set('smking.inject.canonical', false);

        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'seo' => [
                    'title' => 'API Title',
                    'ogTitle' => 'API OG Title',
                    'canonicalUrl' => 'https://example.com/c',
                ],
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('API Title', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        // og:title still on (default true).
        $this->assertStringContainsString('property="og:title"', $html);
    }

    public function test_skips_when_api_returns_not_ready(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'pending'], 202),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/new', 'GET');
        $original = '<html><head></head><body>new</body></html>';
        $response = $middleware->handle($request, function () use ($original) {
            return new Response($original, 200, ['Content-Type' => 'text/html']);
        });

        $this->assertSame($original, $response->getContent());
    }
}
