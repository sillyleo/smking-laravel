<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Smking\Laravel\Data\SeoMeta;

class SeoMetaTest extends TestCase
{
    public function test_from_array_null_returns_null(): void
    {
        $this->assertNull(SeoMeta::fromArray(null));
    }

    public function test_from_array_all_null_returns_null(): void
    {
        // SaaS Public API explicitly returns null for fields with no signal.
        // We collapse "every field null" back to null so consumer "if ($seo)"
        // checks remain meaningful.
        $this->assertNull(SeoMeta::fromArray([
            'title' => null,
            'ogTitle' => null,
            'ogDescription' => null,
            'ogImageUrl' => null,
            'canonicalUrl' => null,
        ]));
    }

    public function test_from_array_partial_fields(): void
    {
        $seo = SeoMeta::fromArray([
            'title' => 'Blue Widget — Acme',
            'ogTitle' => null,
            'ogDescription' => 'A buyable benefit.',
        ]);

        $this->assertNotNull($seo);
        $this->assertSame('Blue Widget — Acme', $seo->title);
        $this->assertNull($seo->ogTitle);
        $this->assertSame('A buyable benefit.', $seo->ogDescription);
        $this->assertNull($seo->ogImageUrl);
        $this->assertNull($seo->canonicalUrl);
    }

    public function test_from_array_treats_empty_string_as_null(): void
    {
        $seo = SeoMeta::fromArray([
            'title' => '',
            'ogTitle' => 'Real',
        ]);

        $this->assertNotNull($seo);
        $this->assertNull($seo->title);
        $this->assertSame('Real', $seo->ogTitle);
    }

    public function test_to_array_round_trip(): void
    {
        $seo = SeoMeta::fromArray([
            'title' => 'T',
            'ogTitle' => 'OT',
            'ogDescription' => 'OD',
            'ogImageUrl' => 'https://example.com/i.png',
            'canonicalUrl' => 'https://example.com/p',
        ]);

        $this->assertSame([
            'title' => 'T',
            'ogTitle' => 'OT',
            'ogDescription' => 'OD',
            'ogImageUrl' => 'https://example.com/i.png',
            'canonicalUrl' => 'https://example.com/p',
        ], $seo?->toArray());
    }

    public function test_is_empty(): void
    {
        $this->assertTrue((new SeoMeta())->isEmpty());
        $this->assertFalse((new SeoMeta(title: 'X'))->isEmpty());
    }
}
