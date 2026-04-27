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
        $this->assertSame('ready', $response->headers->get('X-Smking-Status'));
        $this->assertSame('/products/widget', $response->headers->get('X-Smking-Path'));
    }

    public function test_does_not_mark_non_html_responses(): void
    {
        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/api/data', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']);
        });

        $this->assertSame('{"ok":true}', $response->getContent());
        $this->assertNull($response->headers->get('X-Smking-Status'));
        $this->assertNull($response->headers->get('X-Smking-Path'));
        Http::assertNothingSent();
    }

    public function test_does_not_mark_non_200_responses(): void
    {
        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/missing', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('<html><body>404</body></html>', 404, ['Content-Type' => 'text/html']);
        });

        $this->assertStringNotContainsString('data-smking-injected', (string) $response->getContent());
        $this->assertNull($response->headers->get('X-Smking-Status'));
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
        $this->assertNull($response->headers->get('X-Smking-Status'));
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
        $this->assertSame('ready', $response->headers->get('X-Smking-Status'));
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

    public function test_marks_html_even_when_aeo_not_ready(): void
    {
        // Disable debug HTML comment for this test — the comment is exercised
        // separately. Here we focus on the mark + header decoupling contract.
        config()->set('smking.debug', false);

        Http::fake([
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/new', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>new</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();

        // Mark is present even though no fragments could be injected — this is
        // the v0.2.0 install-verification contract.
        $this->assertStringContainsString('data-smking-injected="1"', $html);
        // No actual content fragments because backend hasn't crawled.
        $this->assertStringNotContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString('smking-faq', $html);
        // Headers always present once shouldInject passes.
        $this->assertSame('not_found', $response->headers->get('X-Smking-Status'));
        $this->assertSame('/products/new', $response->headers->get('X-Smking-Path'));
    }

    public function test_emits_headers_on_head_request(): void
    {
        // `curl -I` (HEAD) is the canonical install-verification command,
        // and HEAD must surface the same X-Smking-* headers as GET.
        config()->set('smking.debug', false);

        Http::fake([
            '*' => Http::response(['status' => 'ready', 'jsonLd' => ['@type' => 'Product']], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'HEAD');
        $response = $middleware->handle($request, function () {
            // Symfony Response strips the body on HEAD; simulate that.
            return new Response('', 200, ['Content-Type' => 'text/html']);
        });

        $this->assertSame('ready', $response->headers->get('X-Smking-Status'));
        $this->assertSame('/products/widget', $response->headers->get('X-Smking-Path'));
    }

    public function test_emits_pending_status_header(): void
    {
        config()->set('smking.debug', false);

        Http::fake([
            '*' => Http::response(['status' => 'pending'], 202),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/queued', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>queued</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $this->assertStringContainsString('data-smking-injected="1"', (string) $response->getContent());
        $this->assertSame('pending', $response->headers->get('X-Smking-Status'));
    }

    public function test_emits_disabled_header_when_auto_inject_off(): void
    {
        config()->set('smking.auto_inject', false);
        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);

        $original = '<html><head></head><body>x</body></html>';
        $request = Request::create('/some-path', 'GET');
        $response = $middleware->handle($request, function () use ($original) {
            return new Response($original, 200, ['Content-Type' => 'text/html']);
        });

        // HTML untouched in disabled mode — no mark.
        $this->assertSame($original, $response->getContent());
        // But headers still emit so doctor / curl -I can verify the SDK.
        $this->assertSame('disabled', $response->headers->get('X-Smking-Status'));
        $this->assertSame('/some-path', $response->headers->get('X-Smking-Path'));
        // No API call when disabled — the middleware short-circuits.
        Http::assertNothingSent();
    }

    public function test_injects_html_comment_when_debug_enabled(): void
    {
        config()->set('smking.debug', true);

        Http::fake([
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/local', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>local-test</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();

        $this->assertStringContainsString('<!-- smking:', $html);
        $this->assertStringContainsString('path=/products/local', $html);
        $this->assertStringContainsString('status=not_found', $html);
        // Comment goes before </body>.
        $this->assertMatchesRegularExpression('/<!-- smking:[^>]*-->\s*<\/body>/', $html);
    }

    public function test_does_not_rewrite_gzipped_response_body(): void
    {
        // PHP output_compression / webserver-level gzip middleware can hand
        // us a binary body with Content-Encoding: gzip. preg_replace on
        // gzipped bytes corrupts the payload, so the middleware must skip
        // the rewrite and only emit the verification headers.
        config()->set('smking.debug', false);

        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'jsonLd' => ['@type' => 'Product'],
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        // Pretend the body is already gzipped — content unchanged.
        $original = "\x1f\x8b\x08\x00fake-gzipped-bytes";
        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () use ($original) {
            return new Response($original, 200, [
                'Content-Type' => 'text/html',
                'Content-Encoding' => 'gzip',
            ]);
        });

        $this->assertSame($original, $response->getContent());
        // Headers still emitted — verification works without rewriting.
        $this->assertSame('ready', $response->headers->get('X-Smking-Status'));
        $this->assertSame('/products/widget', $response->headers->get('X-Smking-Path'));
    }

    public function test_skips_attachment_responses(): void
    {
        // text/html download — must not inject AEO into a file the user is
        // saving to disk.
        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);

        $original = '<html><body>report</body></html>';
        $request = Request::create('/reports/q4.html', 'GET');
        $response = $middleware->handle($request, function () use ($original) {
            return new Response($original, 200, [
                'Content-Type' => 'text/html',
                'Content-Disposition' => 'attachment; filename="q4.html"',
            ]);
        });

        $this->assertSame($original, $response->getContent());
        $this->assertNull($response->headers->get('X-Smking-Status'));
        Http::assertNothingSent();
    }

    public function test_emits_disabled_header_for_head_method(): void
    {
        // Combination of v0.2.2 (HEAD support) + v0.2.0 (auto_inject=false
        // emits disabled header) — this slipped through both releases'
        // tests.
        config()->set('smking.auto_inject', false);
        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/some-path', 'HEAD');
        $response = $middleware->handle($request, function () {
            return new Response('', 200, ['Content-Type' => 'text/html']);
        });

        $this->assertSame('disabled', $response->headers->get('X-Smking-Status'));
        $this->assertSame('/some-path', $response->headers->get('X-Smking-Path'));
        Http::assertNothingSent();
    }

    public function test_skips_partial_html_fragment_response(): void
    {
        // HTMX / Turbo / fragment endpoints respond with chunks like
        // `<div>...</div>` served as text/html. Appending JSON-LD / FAQ
        // sections to them would corrupt the fragment. The middleware
        // should still emit verification headers but leave the body alone.
        config()->set('smking.debug', false);

        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'jsonLd' => ['@type' => 'Product'],
                'summary' => 'should not appear',
                'faqHtml' => '<section class="smking-faq">should not appear</section>',
                'summaryHtml' => '<section class="smking-summary">should not appear</section>',
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $original = '<div class="product-card"><h3>Widget</h3></div>';
        $request = Request::create('/htmx/products/widget', 'GET');
        $response = $middleware->handle($request, function () use ($original) {
            return new Response($original, 200, ['Content-Type' => 'text/html']);
        });

        $this->assertSame($original, $response->getContent());
        $this->assertStringNotContainsString('application/ld+json', (string) $response->getContent());
        $this->assertStringNotContainsString('smking-faq', (string) $response->getContent());
        // Headers still emitted so the operator can see the SDK ran.
        $this->assertSame('ready', $response->headers->get('X-Smking-Status'));
    }

    public function test_skips_html_fragment_with_html_tag_but_no_head_or_body(): void
    {
        // Edge case: someone returns `<html>...</html>` as a fragment with
        // no <head> or <body>. Without a closing structural tag, we can't
        // safely position injection — emit headers, leave body alone.
        config()->set('smking.debug', false);

        Http::fake([
            '*' => Http::response(['status' => 'ready', 'jsonLd' => ['@type' => 'Product']], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $original = '<html>just text, no head or body</html>';
        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () use ($original) {
            return new Response($original, 200, ['Content-Type' => 'text/html']);
        });

        $this->assertSame($original, $response->getContent());
        $this->assertSame('ready', $response->headers->get('X-Smking-Status'));
    }

    public function test_respects_existing_meta_description_with_reversed_attribute_order(): void
    {
        // Host layouts often write `<meta content="..." name="description">`
        // (content first, name second) — valid HTML. v0.2.3 and earlier
        // missed this and overrode it; v0.2.4 detects it.
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'metaDescription' => 'API description (should not be injected)',
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head><meta content="Host description" name="description"></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();
        $this->assertStringContainsString('Host description', $html);
        $this->assertStringNotContainsString('API description', $html);
    }

    public function test_respects_existing_og_title_with_reversed_attribute_order(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'seo' => ['ogTitle' => 'API OG (should not be injected)'],
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head><meta content="Host OG" property="og:title"></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();
        $this->assertStringContainsString('Host OG', $html);
        $this->assertStringNotContainsString('API OG', $html);
    }

    public function test_respects_existing_canonical_with_reversed_attribute_order(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'seo' => ['canonicalUrl' => 'https://api.example.com/api'],
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head><link href="https://host.example.com/host" rel="canonical"></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();
        $this->assertStringContainsString('https://host.example.com/host', $html);
        $this->assertStringNotContainsString('https://api.example.com/api', $html);
    }

    public function test_does_not_inject_html_comment_when_debug_disabled(): void
    {
        config()->set('smking.debug', false);

        Http::fake([
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/prod', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>prod-test</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('<!-- smking:', $html);
        // Mark + header still appear — only the comment is suppressed.
        $this->assertStringContainsString('data-smking-injected="1"', $html);
        $this->assertSame('not_found', $response->headers->get('X-Smking-Status'));
    }
}
