<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;

class MetaComponentTest extends TestCase
{
    public function test_renders_seo_tags_from_api(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ready',
                'seo' => [
                    'title' => 'Blue Widget',
                    'ogTitle' => 'Blue Widget OG',
                    'ogDescription' => 'Hand-cured.',
                    'ogImageUrl' => 'https://example.com/w.png',
                    'canonicalUrl' => 'https://example.com/p/blue-widget',
                ],
            ], 200),
        ]);

        $rendered = Blade::render('<x-smking-meta path="/p/blue-widget" />');

        $this->assertStringContainsString('<title data-smking="seo">Blue Widget</title>', $rendered);
        $this->assertStringContainsString('property="og:title" content="Blue Widget OG"', $rendered);
        $this->assertStringContainsString('property="og:description" content="Hand-cured."', $rendered);
        $this->assertStringContainsString('property="og:image"', $rendered);
        $this->assertStringContainsString('rel="canonical" href="https://example.com/p/blue-widget"', $rendered);
        // Twitter mirror tags.
        $this->assertStringContainsString('name="twitter:image"', $rendered);
    }

    public function test_falls_back_to_props_when_api_has_no_seo(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ready'], 200),
        ]);

        $rendered = Blade::render(
            '<x-smking-meta path="/p/widget" fallback-title="Host Page" fallback-og-description="Host desc" />',
        );

        $this->assertStringContainsString('<title data-smking="seo">Host Page</title>', $rendered);
        // og:title falls back through title chain when API doesn't return it.
        $this->assertStringContainsString('property="og:title" content="Host Page"', $rendered);
        $this->assertStringContainsString('content="Host desc"', $rendered);
    }

    public function test_omits_tags_when_no_value_and_no_fallback(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ready'], 200),
        ]);

        $rendered = Blade::render('<x-smking-meta path="/unknown" />');

        $this->assertStringNotContainsString('<title', $rendered);
        $this->assertStringNotContainsString('property="og:', $rendered);
        $this->assertStringNotContainsString('rel="canonical"', $rendered);
    }

    public function test_pending_response_falls_through_to_fallback(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'pending'], 202),
        ]);

        $rendered = Blade::render(
            '<x-smking-meta path="/new" fallback-title="Just Loaded" />',
        );

        // pending → seo is null → fallback wins.
        $this->assertStringContainsString('<title data-smking="seo">Just Loaded</title>', $rendered);
    }
}
