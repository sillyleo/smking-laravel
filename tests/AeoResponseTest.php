<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Smking\Laravel\Data\AeoResponse;

class AeoResponseTest extends TestCase
{
    public function test_parses_ready_payload(): void
    {
        $response = AeoResponse::fromArray([
            'status' => 'ready',
            'jsonLd' => ['@type' => 'Product', 'name' => 'Widget'],
            'faq' => [['q' => 'Is it blue?', 'a' => 'Yes.']],
            'summary' => 'A nice widget.',
            'metaDescription' => 'Buy the widget.',
            'faqHtml' => '<section class="smking-faq">...</section>',
            'summaryHtml' => '<section class="smking-summary">...</section>',
            'chatLinks' => [
                'chatgpt' => 'https://chat.openai.com/?q=x',
                'perplexity' => 'https://www.perplexity.ai/search?q=x',
                'google' => 'https://gemini.google.com/app?q=x',
            ],
        ]);

        $this->assertTrue($response->isReady());
        $this->assertSame('Widget', $response->jsonLd['name'] ?? null);
        $this->assertCount(1, $response->faq);
        $this->assertSame('Is it blue?', $response->faq[0]['q']);
        $this->assertSame('Buy the widget.', $response->metaDescription);
        $this->assertNotNull($response->chatLinks);
    }

    public function test_treats_missing_status_as_not_found(): void
    {
        $response = AeoResponse::fromArray([]);

        $this->assertSame(AeoResponse::STATUS_NOT_FOUND, $response->status);
        $this->assertFalse($response->isReady());
    }

    public function test_parses_seo_payload(): void
    {
        $response = AeoResponse::fromArray([
            'status' => 'ready',
            'seo' => [
                'title' => 'Blue Widget · Acme',
                'ogTitle' => 'The Blue Widget You Want',
                'ogDescription' => 'Cured by sun. Carried by hand.',
                'ogImageUrl' => 'https://example.com/widget.png',
                'canonicalUrl' => 'https://example.com/products/blue-widget',
            ],
        ]);

        $this->assertNotNull($response->seo);
        $this->assertSame('Blue Widget · Acme', $response->seo->title);
        $this->assertSame('https://example.com/products/blue-widget', $response->seo->canonicalUrl);
    }

    public function test_seo_absent_keeps_field_null(): void
    {
        $response = AeoResponse::fromArray([
            'status' => 'ready',
            'jsonLd' => ['@type' => 'Product'],
        ]);

        $this->assertNull($response->seo);
    }

    public function test_drops_malformed_faq_entries(): void
    {
        $response = AeoResponse::fromArray([
            'status' => 'ready',
            'faq' => [
                ['q' => 'Valid', 'a' => 'Yes'],
                ['only_q' => 'broken'],
                'not-an-array',
            ],
        ]);

        $this->assertCount(1, $response->faq);
        $this->assertSame('Valid', $response->faq[0]['q']);
    }
}
