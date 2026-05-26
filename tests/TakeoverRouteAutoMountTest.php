<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * Service-provider auto-mount for sitemap + llms.txt routes (v0.18.1+).
 *
 * Pre-v0.18.1 these had to be hand-registered by smking-wizard into
 * customer routes/web.php — unreliable because wizard might stop early.
 * Now the provider auto-mounts via routes/takeover.php; wizard's role is
 * reduced to inserting a `require` for `route:cache` mode (same pattern
 * as the webhook route).
 *
 * Config-driven opt-out (`smking.takeover.sitemap=false` /
 * `smking.takeover.llms_txt=false`) is covered separately in
 * {@see TakeoverRouteDisabledTest} — that test class needs a different
 * defineEnvironment to override the default-true config before the
 * service provider boots.
 */
class TakeoverRouteAutoMountTest extends TestCase
{
    public function test_sitemap_route_is_auto_mounted_by_default(): void
    {
        $sitemapRoute = Route::getRoutes()->getByName('smking.sitemap');

        $this->assertNotNull(
            $sitemapRoute,
            'smking.sitemap route must be registered by SmkingServiceProvider so '.
            'customer composer install alone (no wizard run) yields a working /sitemap.xml.',
        );
        $this->assertSame('GET', $sitemapRoute->methods()[0]);
        $this->assertSame('sitemap.xml', $sitemapRoute->uri());
    }

    public function test_llms_txt_route_is_auto_mounted_by_default(): void
    {
        $llmsRoute = Route::getRoutes()->getByName('smking.llms_txt');

        $this->assertNotNull(
            $llmsRoute,
            'smking.llms_txt route must be registered by SmkingServiceProvider so '.
            'customer composer install alone (no wizard run) yields a working /llms.txt.',
        );
        $this->assertSame('GET', $llmsRoute->methods()[0]);
        $this->assertSame('llms.txt', $llmsRoute->uri());
    }

    public function test_takeover_routes_use_array_callable_form_in_source(): void
    {
        // v0.18.2 regression test: customer Laravel apps with legacy
        // RouteServiceProvider (protected $namespace = 'App\Http\Controllers'
        // + ->namespace($this->namespace) on the web group) would prefix
        // string controller refs and produce
        // `App\Http\Controllers\Smking\Laravel\Http\Controllers\SitemapController`.
        // The array-callable [Class::class, '__invoke'] form skips that path.
        // Reproduced on www.sleepytofu.com 2026-05-26 (doctor ticket
        // dr_000d44af8eb2). If this test fails because someone reverted to
        // the bare ::class shorthand in routes/takeover.php, the entire
        // routes/web.php will 500 on legacy customer Laravel apps.
        //
        // Source-level assertion because Laravel normalizes both forms
        // (`Class::class` and `[Class::class, '__invoke']`) into the same
        // 'Class@method' string in getAction()['uses'] — only the parsing
        // path differs (the namespace-prefix step is only on the string-
        // shorthand path), so we can't tell them apart at runtime via the
        // route action API. Reading the source guarantees the dispatch path
        // stays on the array-callable form.
        $source = file_get_contents(__DIR__.'/../routes/takeover.php');

        $this->assertStringContainsString(
            "[SitemapController::class, '__invoke']",
            $source,
            'routes/takeover.php must declare sitemap as array-callable',
        );
        $this->assertStringContainsString(
            "[LlmsTxtController::class, '__invoke']",
            $source,
            'routes/takeover.php must declare llms.txt as array-callable',
        );

        // Anti-assertion: bare `SitemapController::class` (single-class
        // shorthand) must NOT appear as a Route::get second-arg. We
        // intentionally allow the bare form INSIDE the array literal
        // ([SitemapController::class, ...]) — that's the correct form —
        // so we look for the specific shape that would re-introduce the
        // bug: "Route::get('/sitemap.xml', SitemapController::class)".
        $this->assertDoesNotMatchRegularExpression(
            '/Route::get\([^,]+,\s*SitemapController::class\s*\)/',
            $source,
            'sitemap route must not use bare ::class shorthand (legacy namespace prefix bug)',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Route::get\([^,]+,\s*LlmsTxtController::class\s*\)/',
            $source,
            'llms.txt route must not use bare ::class shorthand (legacy namespace prefix bug)',
        );
    }

    public function test_sitemap_route_serves_saas_body_when_upstream_responds(): void
    {
        Http::fake([
            'api.test/api/v1/public/sitemap.xml*' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><urlset/>',
                200,
                ['content-type' => 'application/xml; charset=utf-8'],
            ),
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->assertStringContainsString('<urlset', $response->getContent());
    }
}
