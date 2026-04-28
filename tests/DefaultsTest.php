<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Smking\Laravel\Defaults;

class DefaultsTest extends TestCase
{
    public function test_default_except_covers_technical_routes(): void
    {
        // EXCEPT_PATTERNS only includes routes the framework / known
        // packages own — paths every Laravel site has in the same place.
        $expected = [
            'api/*',
            'livewire/*',
            'telescope*',
            'horizon*',
            'up', // Laravel 11 health route
            'nova*',
            'filament*',
        ];

        foreach ($expected as $pattern) {
            $this->assertContains($pattern, Defaults::EXCEPT_PATTERNS, "EXCEPT_PATTERNS missing technical pattern `{$pattern}`");
        }
    }

    public function test_default_except_does_NOT_include_business_assumptions(): void
    {
        // SDK must not assume customer URL conventions for business pages.
        // `/cart` / `/account` / `/login` naming varies per site.
        // These belong in SUGGESTED_BUSINESS_EXCEPT for opt-in spread.
        // (admin* IS retained — see test_default_except_keeps_admin_convention.)
        $businessAssumptions = [
            'cart',
            'checkout',
            'account',
            'account/*',
            'login',
            'register',
        ];

        foreach ($businessAssumptions as $pattern) {
            $this->assertNotContains($pattern, Defaults::EXCEPT_PATTERNS, "EXCEPT_PATTERNS leaked business assumption `{$pattern}` — move to SUGGESTED_BUSINESS_EXCEPT");
        }
    }

    public function test_default_except_keeps_admin_convention(): void
    {
        // admin* was in the v0.6.x baseline. Removing it is a regression
        // for >90% of Laravel installs (admin is a strong convention,
        // unlike business-specific cart/checkout naming).
        $this->assertContains('admin*', Defaults::EXCEPT_PATTERNS);
    }

    public function test_suggested_business_except_includes_root_and_wildcard_variants(): void
    {
        // Laravel's `Request::is('account/*')` does NOT match `/account` —
        // need both patterns. SUGGESTED_BUSINESS_EXCEPT must cover both.
        $rootAndWildcardPairs = [
            ['cart', 'cart/*'],
            ['account', 'account/*'],
            ['password', 'password/*'],
            ['checkout', 'checkout/*'],
        ];

        foreach ($rootAndWildcardPairs as [$root, $wildcard]) {
            $this->assertContains($root, Defaults::SUGGESTED_BUSINESS_EXCEPT, "missing root pattern `{$root}`");
            $this->assertContains($wildcard, Defaults::SUGGESTED_BUSINESS_EXCEPT, "missing wildcard pattern `{$wildcard}`");
        }
    }

    public function test_suggested_business_except_includes_common_naming_variants(): void
    {
        // Different sites use different conventions — include the common
        // ones so customers can pick what matches their actual routes.
        $variants = [
            'cart', 'basket',                        // shopping container
            'login', 'sign-in',                      // auth entry
            'register', 'sign-up',                   // auth signup
            'account', 'profile', 'dashboard',       // user area roots
        ];

        foreach ($variants as $pattern) {
            $this->assertContains($pattern, Defaults::SUGGESTED_BUSINESS_EXCEPT, "missing common naming variant `{$pattern}`");
        }
    }

    public function test_config_uses_only_technical_defaults(): void
    {
        // The published `config/smking.php` must reference EXCEPT_PATTERNS
        // (technical-only), NOT SUGGESTED_BUSINESS_EXCEPT — customers must
        // explicitly opt-in to business-route exclusion.
        $config = require __DIR__.'/../config/smking.php';

        $this->assertSame(Defaults::EXCEPT_PATTERNS, $config['except']);
    }
}
