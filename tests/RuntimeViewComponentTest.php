<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Blade;

/**
 * `<x-smking-runtime>` is a static Blade — no HTTP. It just emits
 * a `<link>` and `<script async>` pointing at the saas runtime
 * endpoints, using `config('smking.base_url')` (or an override prop).
 */
class RuntimeViewComponentTest extends TestCase
{
    public function test_emits_link_and_script_tags_from_config(): void
    {
        config()->set('smking.base_url', 'https://smk.test');

        $rendered = Blade::render('<x-smking-runtime />');

        $this->assertStringContainsString('rel="stylesheet"', $rendered);
        $this->assertStringContainsString(
            'href="https://smk.test/api/v1/public/runtime.css"',
            $rendered,
        );
        $this->assertStringContainsString(
            'src="https://smk.test/api/v1/public/runtime.js"',
            $rendered,
        );
        $this->assertMatchesRegularExpression('/<script[^>]*\basync\b/', $rendered);
    }

    public function test_base_url_prop_overrides_config(): void
    {
        config()->set('smking.base_url', 'https://config.test');

        $rendered = Blade::render(
            '<x-smking-runtime :base-url="$url" />',
            ['url' => 'https://override.test'],
        );

        $this->assertStringContainsString(
            'https://override.test/api/v1/public/runtime.css',
            $rendered,
        );
        $this->assertStringNotContainsString('https://config.test', $rendered);
    }

    public function test_trims_trailing_slash(): void
    {
        config()->set('smking.base_url', 'https://smk.test/');

        $rendered = Blade::render('<x-smking-runtime />');

        $this->assertStringContainsString(
            'https://smk.test/api/v1/public/runtime.css',
            $rendered,
        );
        $this->assertStringNotContainsString(
            'https://smk.test//api/v1/public/runtime.css',
            $rendered,
        );
    }
}
