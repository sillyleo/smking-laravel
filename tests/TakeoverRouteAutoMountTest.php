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
