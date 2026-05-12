<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\Http\Middleware\InjectAeo;

/**
 * v0.9.0 path-takeover: middleware intercepts /sitemap.xml, /robots.txt,
 * /llms.txt when the customer's app would 404, fetches the SaaS-served
 * version, and returns it with an X-Smking-Takeover header. Customer's
 * own 200 response is never overridden.
 */
class InjectAeoTakeoverTest extends TestCase
{
    public function test_takes_over_sitemap_when_app_returns_404(): void
    {
        Http::fake([
            '*api/v1/public/sitemap.xml*' => Http::response(
                '<?xml version="1.0"?><urlset></urlset>',
                200,
                ['Content-Type' => 'application/xml; charset=utf-8'],
            ),
        ]);

        $middleware = $this->app->make(InjectAeo::class);
        $request = Request::create('/sitemap.xml', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('Not Found', 404);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('sitemap', $response->headers->get('X-Smking-Takeover'));
        $this->assertStringContainsString('<urlset>', (string) $response->getContent());
        $this->assertStringContainsString('xml', (string) $response->headers->get('Content-Type'));
    }

    public function test_takes_over_robots_txt_when_app_returns_404(): void
    {
        Http::fake([
            '*api/v1/public/robots.txt*' => Http::response(
                "User-agent: *\nAllow: /\n",
                200,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            ),
        ]);

        $middleware = $this->app->make(InjectAeo::class);
        $request = Request::create('/robots.txt', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('Not Found', 404);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('robots', $response->headers->get('X-Smking-Takeover'));
        $this->assertStringContainsString('User-agent: *', (string) $response->getContent());
    }

    public function test_takes_over_llms_txt_when_app_returns_404(): void
    {
        Http::fake([
            '*api/v1/public/llms-txt*' => Http::response(
                "# example.com\n\n> AI-friendly content index.\n",
                200,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            ),
        ]);

        $middleware = $this->app->make(InjectAeo::class);
        $request = Request::create('/llms.txt', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('Not Found', 404);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('llms_txt', $response->headers->get('X-Smking-Takeover'));
    }

    public function test_does_not_override_customer_200_sitemap(): void
    {
        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);
        $request = Request::create('/sitemap.xml', 'GET');
        $customerBody = '<?xml version="1.0"?><urlset><url><loc>https://custom/x</loc></url></urlset>';
        $response = $middleware->handle($request, function () use ($customerBody) {
            return new Response($customerBody, 200, ['Content-Type' => 'application/xml']);
        });

        $this->assertNull($response->headers->get('X-Smking-Takeover'));
        $this->assertSame($customerBody, (string) $response->getContent());
        Http::assertNothingSent();
    }

    public function test_does_not_take_over_when_config_flag_disabled(): void
    {
        Http::fake();
        config(['smking.takeover.sitemap' => false]);

        $middleware = $this->app->make(InjectAeo::class);
        $request = Request::create('/sitemap.xml', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('Not Found', 404);
        });

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($response->headers->get('X-Smking-Takeover'));
        Http::assertNothingSent();
    }

    public function test_falls_back_to_404_when_saas_unreachable(): void
    {
        Http::fake([
            '*api/v1/public/sitemap.xml*' => Http::response('', 500),
        ]);

        $middleware = $this->app->make(InjectAeo::class);
        $request = Request::create('/sitemap.xml', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('Not Found', 404);
        });

        // Original customer 404 preserved — don't break the site when SaaS
        // is having a bad day.
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($response->headers->get('X-Smking-Takeover'));
    }

    public function test_takes_over_empty_200_body(): void
    {
        Http::fake([
            '*api/v1/public/sitemap.xml*' => Http::response(
                '<?xml version="1.0"?><urlset></urlset>',
                200,
                ['Content-Type' => 'application/xml; charset=utf-8'],
            ),
        ]);

        $middleware = $this->app->make(InjectAeo::class);
        $request = Request::create('/sitemap.xml', 'GET');
        $response = $middleware->handle($request, function () {
            // Customer route returns 200 but with empty body — common with
            // broken sitemap generators. Treat as takeover candidate.
            return new Response('', 200, ['Content-Type' => 'application/xml']);
        });

        $this->assertSame('sitemap', $response->headers->get('X-Smking-Takeover'));
    }

    public function test_ignores_unmanaged_paths(): void
    {
        Http::fake();

        $middleware = $this->app->make(InjectAeo::class);
        $request = Request::create('/some-other-path.xml', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('Not Found', 404);
        });

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($response->headers->get('X-Smking-Takeover'));
        Http::assertNothingSent();
    }
}
