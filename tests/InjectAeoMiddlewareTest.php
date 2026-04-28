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

    public function test_overrides_existing_title_and_canonical(): void
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
        // v0.3.0 — always override. Host's <title> and <link rel="canonical">
        // get stripped before smking injects its own. smking is the source of
        // truth; customers install us precisely to replace placeholder /
        // hardcoded SEO output.
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head><title>Host Title</title><link rel="canonical" href="/host-canonical"></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $html = (string) $response->getContent();

        // Host tags stripped, API tags injected.
        $this->assertStringNotContainsString('<title>Host Title</title>', $html);
        $this->assertStringContainsString('<title data-smking="seo">API Title</title>', $html);
        $this->assertStringNotContainsString('/host-canonical', $html);
        $this->assertStringContainsString('href="https://example.com/api-canonical"', $html);
        // og:title injected normally.
        $this->assertStringContainsString('property="og:title" content="API OG Title"', $html);
        // Document only ever has one <title> after strip+inject.
        $this->assertSame(1, substr_count($html, '<title'));
        $this->assertSame(1, preg_match_all('/rel="canonical"/i', $html));
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

    public function test_overrides_existing_meta_description_with_reversed_attribute_order(): void
    {
        // Host layouts often write `<meta content="..." name="description">`
        // (content first, name second) — valid HTML. v0.3.0 strips that tag
        // (attribute-order-insensitive) before injecting smking's version,
        // so the host's hardcoded fallback gets replaced by the API's
        // generated description.
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'metaDescription' => 'API description from smking',
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
        $this->assertStringNotContainsString('Host description', $html);
        $this->assertStringContainsString('API description from smking', $html);
        // Only one <meta name="description"> after strip+inject.
        $this->assertSame(1, preg_match_all('/name="description"/i', $html));
    }

    public function test_overrides_existing_og_title_with_reversed_attribute_order(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'seo' => ['ogTitle' => 'API OG from smking'],
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
        $this->assertStringNotContainsString('Host OG', $html);
        $this->assertStringContainsString('API OG from smking', $html);
        $this->assertSame(1, preg_match_all('/property="og:title"/i', $html));
    }

    public function test_overrides_existing_canonical_with_reversed_attribute_order(): void
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
        $this->assertStringNotContainsString('https://host.example.com/host', $html);
        $this->assertStringContainsString('https://api.example.com/api', $html);
        $this->assertSame(1, preg_match_all('/rel="canonical"/i', $html));
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

    // ── Markdown for Agents (v0.4.0) ──────────────────────────────

    public function test_serves_markdown_when_agent_sends_accept_text_markdown(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
            '*api/v1/public/md*' => Http::response("# Widget\n\n## Summary\n\nGreat product.\n", 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET', server: ['HTTP_ACCEPT' => 'text/markdown']);
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head><title>X</title></head><body><h1>Product</h1></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $body = (string) $response->getContent();

        $this->assertStringContainsString('# Widget', $body);
        $this->assertStringContainsString('## Summary', $body);
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
        $this->assertSame('ready', $response->headers->get('X-Smking-Status'));
    }

    public function test_falls_back_to_html_when_markdown_api_returns_404(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready', 'jsonLd' => ['@type' => 'Product', 'name' => 'W']], 200),
            '*api/v1/public/md*' => Http::response('Not found', 404),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET', server: ['HTTP_ACCEPT' => 'text/markdown']);
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head><title>X</title></head><body><h1>Product</h1></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $body = (string) $response->getContent();

        $this->assertStringContainsString('<html', $body);
        $this->assertStringContainsString('application/ld+json', $body);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }

    public function test_serves_html_when_accept_prefers_html_over_markdown(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
            '*api/v1/public/md*' => Http::response("# Should not appear\n", 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        // text/html;q=0.9 ranks above text/markdown;q=0.8
        $request = Request::create('/products/widget', 'GET', server: [
            'HTTP_ACCEPT' => 'text/html;q=0.9, text/markdown;q=0.8',
        ]);
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $body = (string) $response->getContent();

        $this->assertStringContainsString('<html', $body);
        $this->assertStringNotContainsString('Should not appear', $body);
        $this->assertStringNotContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/api/v1/public/md');
        });
    }

    public function test_serves_markdown_when_higher_q_than_html(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
            '*api/v1/public/md*' => Http::response("# Markdown wins\n", 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET', server: [
            'HTTP_ACCEPT' => 'text/markdown;q=0.9, text/html;q=0.8',
        ]);
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $this->assertStringContainsString('Markdown wins', (string) $response->getContent());
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
    }

    public function test_browser_default_accept_does_not_trigger_markdown(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
            '*api/v1/public/md*' => Http::response("# Should not appear\n", 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        // Typical Chrome / Firefox default
        $request = Request::create('/products/widget', 'GET', server: [
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $body = (string) $response->getContent();

        $this->assertStringContainsString('<html', $body);
        $this->assertStringNotContainsString('Should not appear', $body);
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/api/v1/public/md');
        });
    }

    public function test_inject_markdown_false_disables_negotiation(): void
    {
        config()->set('smking.inject.markdown', false);

        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
            '*api/v1/public/md*' => Http::response("# Should not appear\n", 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET', server: ['HTTP_ACCEPT' => 'text/markdown']);
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $body = (string) $response->getContent();

        $this->assertStringContainsString('<html', $body);
        $this->assertStringNotContainsString('Should not appear', $body);
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/api/v1/public/md');
        });
    }

    public function test_markdown_skipped_when_auto_inject_false(): void
    {
        config()->set('smking.auto_inject', false);

        Http::fake([
            '*api/v1/public/md*' => Http::response("# Should not appear\n", 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET', server: ['HTTP_ACCEPT' => 'text/markdown']);
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        // auto_inject=false short-circuits BEFORE the markdown branch — body
        // unchanged, X-Smking-Status: disabled per existing v0.2.0 contract.
        $this->assertStringContainsString('<html', (string) $response->getContent());
        $this->assertSame('disabled', $response->headers->get('X-Smking-Status'));
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/api/v1/public/md');
        });
    }

    public function test_markdown_request_calls_md_endpoint_with_key_and_path(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
            '*api/v1/public/md*' => Http::response("# OK\n", 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET', server: ['HTTP_ACCEPT' => 'text/markdown']);
        $middleware->handle($request, function () {
            return new Response('<html><head></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        Http::assertSent(function ($request) {
            $url = $request->url();
            return str_contains($url, '/api/v1/public/md')
                && str_contains($url, 'key=pk_test_key')
                && str_contains($url, 'path=%2Fproducts%2Fwidget');
        });
    }

    public function test_markdown_branch_runs_before_html_fragment_guard(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
            '*api/v1/public/md*' => Http::response("# Markdown wins\n", 200),
        ]);

        // HTMX-style fragment served as text/html. The fragment guard only
        // protects the HTML rewrite path from corrupting partial chunks.
        // For Accept: text/markdown the agent doesn't want any HTML at all,
        // so markdown should take over regardless of whether the upstream
        // body is a fragment or a full document.
        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/widget-fragment', 'GET', server: ['HTTP_ACCEPT' => 'text/markdown']);
        $response = $middleware->handle($request, function () {
            return new Response('<div>just a fragment</div>', 200, ['Content-Type' => 'text/html']);
        });

        $this->assertStringContainsString('Markdown wins', (string) $response->getContent());
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
    }

    // ── Markdown alternate Link header (v0.5.0) ───────────────────

    public function test_emits_markdown_alternate_link_header_on_html_response(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('https://shop.example/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $link = (string) $response->headers->get('Link');

        $this->assertStringContainsString('rel="alternate"', $link);
        $this->assertStringContainsString('type="text/markdown"', $link);
        $this->assertStringContainsString('https://shop.example/products/widget', $link);
    }

    public function test_link_header_appends_to_customer_existing_link(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('https://shop.example/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            $r = new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
            $r->headers->set('Link', '<https://shop.example/products/next>; rel="next"');

            return $r;
        });

        $allLink = $response->headers->all('Link');

        // Customer's rel="next" must survive untouched, smking's alternate appended
        $combined = implode(' || ', $allLink);
        $this->assertStringContainsString('rel="next"', $combined);
        $this->assertStringContainsString('rel="alternate"', $combined);
        $this->assertStringContainsString('text/markdown', $combined);
    }

    public function test_link_header_skipped_when_inject_markdown_false(): void
    {
        config()->set('smking.inject.markdown', false);

        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('https://shop.example/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });

        $link = (string) $response->headers->get('Link', '');

        $this->assertStringNotContainsString('text/markdown', $link);
    }

    public function test_link_header_not_duplicated_when_customer_already_advertises_markdown(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('https://shop.example/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            $r = new Response(
                '<html><head></head><body>x</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
            // Customer already wired their own markdown alternate.
            $r->headers->set('Link', '<https://shop.example/products/widget.md>; rel="alternate"; type="text/markdown"');

            return $r;
        });

        $allLink = $response->headers->all('Link');
        $count = 0;
        foreach ($allLink as $h) {
            if (stripos($h, 'rel="alternate"') !== false && stripos($h, 'text/markdown') !== false) {
                $count++;
            }
        }

        $this->assertSame(1, $count, 'Should not duplicate markdown alternate when customer already advertises one');
    }

    // ── Body-fragment visibility (v0.6.0) ─────────────────────────

    public function test_body_fragments_default_to_sr_only_wrapper(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'summaryHtml' => '<section class="smking-summary">SUM</section>',
                'faqHtml' => '<section class="smking-faq">FAQ</section>',
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('<html><head></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $html = (string) $response->getContent();

        // Wrapped in inline-style visually-hidden div
        $this->assertStringContainsString('data-smking="aeo"', $html);
        $this->assertStringContainsString('clip:rect(0,0,0,0)', $html);
        $this->assertStringContainsString('position:absolute', $html);
        // Inner microdata still present for Googlebot
        $this->assertStringContainsString('smking-summary', $html);
        $this->assertStringContainsString('smking-faq', $html);
        // Wrapper sits inside body before </body>
        $this->assertMatchesRegularExpression('/<div data-smking="aeo".+<\/div>\s*<\/body>/s', $html);
    }

    public function test_visibility_visible_emits_raw_fragments_without_wrapper(): void
    {
        config()->set('smking.inject.visibility', 'visible');

        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'summaryHtml' => '<section class="smking-summary">SUM</section>',
                'faqHtml' => '<section class="smking-faq">FAQ</section>',
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('<html><head></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $html = (string) $response->getContent();

        // No wrapper around the fragments themselves
        $this->assertStringNotContainsString('clip:rect(0,0,0,0)', $html);
        // smking-summary / smking-faq sit directly in body
        $this->assertStringContainsString('<section class="smking-summary">SUM</section>', $html);
        $this->assertStringContainsString('<section class="smking-faq">FAQ</section>', $html);
    }

    public function test_visibility_noscript_wraps_fragments_in_noscript_tag(): void
    {
        config()->set('smking.inject.visibility', 'noscript');

        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'summaryHtml' => '<section class="smking-summary">SUM</section>',
                'faqHtml' => '<section class="smking-faq">FAQ</section>',
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('<html><head></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $html = (string) $response->getContent();

        $this->assertMatchesRegularExpression('/<noscript[^>]*data-smking="aeo"[^>]*>.*<\/noscript>/s', $html);
        $this->assertStringContainsString('smking-summary', $html);
    }

    public function test_unknown_visibility_value_falls_back_to_sr_only(): void
    {
        config()->set('smking.inject.visibility', 'totally-bogus');

        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'summaryHtml' => '<section>SUM</section>',
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/x', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('<html><head></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        // Unknown value MUST NOT leak raw fragments — the safe default wins.
        $this->assertStringContainsString('clip:rect(0,0,0,0)', (string) $response->getContent());
    }

    public function test_no_wrapper_emitted_when_no_body_fragments(): void
    {
        // ready response with only json_ld + meta (no summaryHtml / faqHtml)
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'jsonLd' => ['@type' => 'Product', 'name' => 'X'],
                'metaDescription' => 'Buy X.',
            ], 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('/x', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('<html><head></head><body>real content</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $html = (string) $response->getContent();

        // No empty visually-hidden div polluting the body
        $this->assertStringNotContainsString('clip:rect(0,0,0,0)', $html);
        $this->assertStringNotContainsString('<noscript data-smking', $html);
        // But head injection still happened
        $this->assertStringContainsString('application/ld+json', $html);
    }

    public function test_link_header_skipped_when_api_key_missing(): void
    {
        // v0.6.1: don't advertise markdown alternate when env isn't
        // configured — the md API call would fail and the agent would
        // get HTML, so the Link header is a lie.
        config()->set('smking.api_key', '');

        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('https://shop.example/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('<html><head></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $this->assertStringNotContainsString('text/markdown', (string) $response->headers->get('Link', ''));
    }

    public function test_link_header_skipped_when_base_url_missing(): void
    {
        config()->set('smking.base_url', '');

        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('https://shop.example/products/widget', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('<html><head></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $this->assertStringNotContainsString('text/markdown', (string) $response->headers->get('Link', ''));
    }

    public function test_link_header_not_emitted_on_markdown_response(): void
    {
        Http::fake([
            '*api/v1/public/aeo*' => Http::response(['status' => 'ready'], 200),
            '*api/v1/public/md*' => Http::response("# OK\n", 200),
        ]);

        $middleware = $this->app->make(InjectAeo::class);

        $request = Request::create('https://shop.example/products/widget', 'GET', server: [
            'HTTP_ACCEPT' => 'text/markdown',
        ]);
        $response = $middleware->handle($request, function () {
            return new Response('<html><head></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        // The response body is already markdown — pointing the agent to a
        // markdown alternate of itself is meaningless. respondWithMarkdown
        // returns before the Link emission line.
        $link = (string) $response->headers->get('Link', '');

        $this->assertSame('', $link);
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
    }
}
