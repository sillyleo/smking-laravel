<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Smking\Laravel\Defaults;

class DefaultsTest extends TestCase
{
    public function test_default_except_includes_ecommerce_and_auth_routes(): void
    {
        $expected = [
            'cart',
            'cart/*',
            'checkout',
            'checkout/*',
            'account/*',
            'login',
            'register',
            'password/*',
        ];

        foreach ($expected as $pattern) {
            $this->assertContains($pattern, Defaults::EXCEPT_PATTERNS, "EXCEPT_PATTERNS missing `{$pattern}`");
        }
    }

    public function test_default_except_still_covers_legacy_categories(): void
    {
        // Regression — the v0.6.x baseline must survive the v0.7.0 expansion.
        $legacy = [
            'api/*',
            'livewire/*',
            'telescope*',
            'horizon*',
            'admin*',
            'up',
        ];

        foreach ($legacy as $pattern) {
            $this->assertContains($pattern, Defaults::EXCEPT_PATTERNS, "EXCEPT_PATTERNS lost legacy `{$pattern}`");
        }
    }

    public function test_config_uses_defaults_const(): void
    {
        // The published config/smking.php must reference Defaults::EXCEPT_PATTERNS,
        // not a hand-maintained copy — otherwise drift would let the two go
        // out of sync, which is exactly the bug we're trying to prevent.
        $config = require __DIR__.'/../config/smking.php';

        $this->assertSame(Defaults::EXCEPT_PATTERNS, $config['except']);
    }
}
