<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Route;

/**
 * CMS draft preview entry (v0.21.0+). The SDK auto-mounts `/smking-preview`
 * which sets a short-lived `smking_preview_token` cookie + redirects to the
 * (relative) page, where `<x-smking-cms>` then renders draftBlocks.
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

    public function test_sets_cookie_and_redirects_to_path(): void
    {
        $response = $this->get('/smking-preview?token=TOK&path=/blog/x');

        $response->assertRedirect('/blog/x');

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'smking_preview_token');
        $this->assertNotNull($cookie, 'preview cookie must be set on the redirect');
        $this->assertSame('TOK', $cookie->getValue());
        // 15-min TTL (cookie expiry is a unix timestamp in the future).
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
    }

    public function test_open_redirect_guard_protocol_relative_path(): void
    {
        $response = $this->get('/smking-preview?token=TOK&path=//evil.com');
        $response->assertRedirect('/');
    }

    public function test_open_redirect_guard_absolute_url_path(): void
    {
        $response = $this->get('/smking-preview?token=TOK&path=https://evil.com');
        $response->assertRedirect('/');
    }

    public function test_no_token_redirects_without_setting_cookie(): void
    {
        $response = $this->get('/smking-preview?path=/blog/x');

        $response->assertRedirect('/blog/x');
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'smking_preview_token');
        $this->assertNull($cookie, 'no token → no cookie (fail-safe)');
    }

    public function test_preview_cookie_is_exempt_from_encryption(): void
    {
        // The cookie is set by the bare /smking-preview route but read on the
        // customer's web-group CMS page. The provider globally exempts it from
        // EncryptCookies so the customer's middleware doesn't strip the
        // plaintext cookie as "tampered" on the inbound request. Read the
        // global static directly (no container resolve — EncryptCookies needs
        // an encrypter that Testbench doesn't bind without an app key).
        $prop = new \ReflectionProperty(
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            'neverEncrypt',
        );
        $prop->setAccessible(true);
        $this->assertContains(
            'smking_preview_token',
            $prop->getValue(),
            'smking_preview_token must be globally exempt from EncryptCookies.',
        );
    }
}
