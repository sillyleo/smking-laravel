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
