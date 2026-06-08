<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Route;

/**
 * CMS draft preview entry. The SDK auto-mounts `/smking-preview`, which
 * redirects to the (relative) page carrying the short-lived token as a
 * `?smking_preview=` query param, where `<x-smking-cms>` then renders
 * draftBlocks. No cookie — sidesteps the customer's EncryptCookies middleware
 * and keeps the preview URL visibly distinct from the live page.
 */
class PreviewControllerTest extends TestCase
{
    public function test_preview_route_is_auto_mounted_by_default(): void
    {
        $route = Route::getRoutes()->getByName('smking.preview');

        $this->assertNotNull(
            $route,
            'smking.preview route must be registered by SmkingServiceProvider so '.
            'customer composer install alone yields a working /smking-preview.',
        );
        $this->assertSame('GET', $route->methods()[0]);
        $this->assertSame('smking-preview', $route->uri());
    }

    public function test_route_uses_array_callable_form_in_source(): void
    {
        // Same legacy-namespace-prefix defence as routes/takeover.php — bare
        // ::class shorthand 500s on customer apps with a RouteServiceProvider
        // $namespace prefix. Source-level assertion (Laravel normalizes both
        // forms at runtime, so we can't tell them apart via the route API).
        $source = file_get_contents(__DIR__.'/../routes/preview.php');

        $this->assertStringContainsString(
            "[PreviewController::class, '__invoke']",
            $source,
            'routes/preview.php must declare preview as array-callable',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Route::get\([^,]+,\s*PreviewController::class\s*\)/',
            $source,
            'preview route must not use bare ::class shorthand (legacy namespace prefix bug)',
        );
    }

    public function test_appends_token_as_query_param_and_redirects(): void
    {
        $response = $this->get('/smking-preview?token=TOK&path=/blog/x');

        // Token rides on the redirect target as a query param — no cookie, and
        // the URL is visibly a preview (vs the clean published page URL).
        $response->assertRedirect('/blog/x?smking_preview=TOK');
    }

    public function test_appends_with_ampersand_when_path_already_has_query(): void
    {
        $response = $this->get('/smking-preview?token=TOK&path=/blog/x?foo=1');

        $response->assertRedirect('/blog/x?foo=1&smking_preview=TOK');
    }

    public function test_open_redirect_guard_protocol_relative_path(): void
    {
        // path coerced to "/" (never evil.com); token still appended.
        $response = $this->get('/smking-preview?token=TOK&path=//evil.com');
        $response->assertRedirect('/?smking_preview=TOK');
    }

    public function test_open_redirect_guard_absolute_url_path(): void
    {
        $response = $this->get('/smking-preview?token=TOK&path=https://evil.com');
        $response->assertRedirect('/?smking_preview=TOK');
    }

    public function test_no_token_redirects_without_preview_param(): void
    {
        $response = $this->get('/smking-preview?path=/blog/x');

        // Fail-safe: no token → plain redirect to the live page, no param.
        $response->assertRedirect('/blog/x');
    }
}
