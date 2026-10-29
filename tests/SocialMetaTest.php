<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Smking\Laravel\Support\SocialMeta;

class SocialMetaTest extends TestCase
{
    public function test_reuses_the_same_image_and_existing_facts_without_inventing_sizes(): void
    {
        $seo = ['title' => '手動標題', 'ogDescription' => '手動描述', 'ogImageUrl' => 'https://cdn.test/image.jpg'];
        $html = '<html lang="zh-TW"><head><script type="application/ld+json">'
            .' {"@type":"Organization","name":"眠豆腐"}</script></head>'
            .'<body><img src="https://cdn.test/image.jpg" alt="四季棉被材質比較表"></body></html>';
        $facts = SocialMeta::enrich($seo, $html, null, 'article');
        $tags = collect(SocialMeta::tags($facts))->keyBy('name');

        $this->assertSame('眠豆腐', $tags['og:site_name']['content']);
        $this->assertSame('zh_TW', $tags['og:locale']['content']);
        $this->assertSame('article', $tags['og:type']['content']);
        $this->assertSame('手動標題', $tags['twitter:title']['content']);
        $this->assertSame('手動描述', $tags['twitter:description']['content']);
        $this->assertSame($tags['og:image']['content'], $tags['twitter:image']['content']);
        $this->assertSame('四季棉被材質比較表', $tags['twitter:image:alt']['content']);
        $this->assertArrayNotHasKey('og:image:width', $tags->all());
        $this->assertArrayNotHasKey('twitter:site', $tags->all());
    }

    public function test_only_uses_dimensions_for_the_selected_image(): void
    {
        $html = '<html lang="en-AU"><head><meta property="og:image" content="https://cdn.test/old.png">'
            .'<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"></head></html>';
        $seo = ['title' => 'New image', 'ogImageUrl' => 'https://cdn.test/new.png'];
        $facts = SocialMeta::enrich($seo, $html, ['@graph' => [
            ['@type' => 'ImageObject', 'url' => 'https://cdn.test/new.png', 'width' => 900, 'height' => 900, 'encodingFormat' => 'image/png'],
        ]]);
        $tags = collect(SocialMeta::tags($facts))->keyBy('name');
        $this->assertSame('900', $tags['og:image:width']['content']);
        $this->assertSame('900', $tags['og:image:height']['content']);
        $this->assertSame('image/png', $tags['og:image:type']['content']);
        $this->assertSame('en_AU', $tags['og:locale']['content']);

        $withoutFacts = SocialMeta::enrich($seo, $html);
        $this->assertArrayNotHasKey('ogImageWidth', $withoutFacts);
        $this->assertArrayNotHasKey('ogImageHeight', $withoutFacts);
    }

    public function test_does_not_guess_a_country_from_a_language_or_emit_tags_for_no_image(): void
    {
        $seo = SocialMeta::enrich(['title' => '文章', 'ogImageUrl' => null], '<html lang="zh-Hant"><head></head></html>');
        $names = array_column(SocialMeta::tags($seo), 'name');
        $this->assertNotContains('og:locale', $names);
        $this->assertNotContains('og:image', $names);
        $this->assertNotContains('twitter:image', $names);
        $this->assertSame('summary', collect(SocialMeta::tags($seo))->keyBy('name')['twitter:card']['content']);
    }
}
