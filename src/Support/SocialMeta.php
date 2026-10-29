<?php

declare(strict_types=1);

namespace Smking\Laravel\Support;

/** Builds escaped-by-caller tags from published facts, without HTTP or AI. */
final class SocialMeta
{
    public static function locale(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^([a-z]{2,3})(?:[-_][a-z]{4})?[-_]([a-z]{2}|[0-9]{3})$/iD', trim($value), $matches)) {
            return null;
        }

        return strtolower($matches[1]).'_'.strtoupper($matches[2]);
    }

    /** Reuse existing document/schema facts. Never fetch or resize an image. */
    public static function enrich(array $seo, string $html, ?array $jsonLd = null, ?string $type = null): array
    {
        $meta = [];
        $head = preg_match('/<head\b[^>]*>.*?<\/head\s*>/is', $html, $documentHead) ? $documentHead[0] : '';
        preg_match_all('/(?:<script\b[^>]*>.*?<\/script>|<!--.*?-->)(*SKIP)(*F)|<meta\b[^>]*>/is', $head, $matches);
        foreach ($matches[0] as $tag) {
            $key = self::attribute($tag, 'property') ?? self::attribute($tag, 'name');
            if ($key !== null) {
                $meta[strtolower($key)] ??= self::attribute($tag, 'content');
            }
        }
        $documents = $jsonLd ? [$jsonLd] : [];
        preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $html, $scripts);
        foreach ($scripts[0] as $script) {
            if (self::attribute($script, 'type') === 'application/ld+json') {
                $body = preg_replace('/^<script\b[^>]*>|<\/script>$/is', '', $script);
                $data = json_decode($body ?? '', true);
                if (is_array($data)) {
                    $documents[] = $data;
                }
            }
        }
        $nodes = [];
        foreach ($documents as $document) {
            $entries = $document['@graph'] ?? (array_is_list($document) ? $document : [$document]);
            foreach (array_slice(is_array($entries) ? $entries : [], 0, 200) as $node) {
                if (is_array($node)) {
                    $nodes[] = $node;
                }
            }
        }
        $image = $seo['ogImageUrl'] ?? null;
        $sameImage = $image !== null && $image === ($meta['og:image'] ?? null);
        $imageNode = null;
        $brand = null;
        $website = null;
        foreach ($nodes as $node) {
            $types = (array) ($node['@type'] ?? []);
            if (in_array('WebSite', $types, true) && is_string($node['name'] ?? null)) {
                $website ??= $node['name'];
            }
            if (array_intersect(['Organization', 'Corporation', 'LocalBusiness', 'Store', 'OnlineStore'], $types) && is_string($node['name'] ?? null)) {
                $brand ??= $node['name'];
            }
            if (array_intersect(['Article', 'BlogPosting', 'NewsArticle', 'TechArticle'], $types)) {
                $type ??= 'article';
            }
            $images = in_array('ImageObject', $types, true) ? [$node] : ($node['image'] ?? []);
            if (is_array($images) && ! array_is_list($images)) {
                $images = [$images];
            }
            foreach (is_array($images) ? $images : [] as $candidate) {
                if (is_array($candidate) && $image !== null && ($candidate['contentUrl'] ?? $candidate['url'] ?? null) === $image) {
                    $imageNode ??= $candidate;
                }
            }
        }
        $seo['ogSiteName'] = $meta['og:site_name'] ?? $website ?? $brand;
        $seo['ogType'] = $type ?? $meta['og:type'] ?? 'website';
        $lang = preg_match('/<html\b[^>]*>/i', $html, $element) ? self::attribute($element[0], 'lang') : null;
        $seo['ogLocale'] = $meta['og:locale'] ?? self::locale($lang);
        $seo['twitterSite'] = $meta['twitter:site'] ?? null;
        if ($image !== null) {
            $alt = $sameImage ? ($meta['og:image:alt'] ?? null) : null;
            if (! $alt) {
                preg_match_all('/<img\b[^>]*>/i', $html, $images);
                foreach ($images[0] as $tag) {
                    if (self::attribute($tag, 'src') === $image) {
                        $alt = self::attribute($tag, 'alt');
                        if ($alt) {
                            break;
                        }
                    }
                }
            }
            $seo['ogImageAlt'] = $alt ?: ($imageNode['description'] ?? $imageNode['caption'] ?? $seo['ogTitle'] ?? $seo['title'] ?? null);
            $width = $sameImage ? ($meta['og:image:width'] ?? null) : null;
            $height = $sameImage ? ($meta['og:image:height'] ?? null) : null;
            $width ??= $imageNode['width'] ?? null;
            $height ??= $imageNode['height'] ?? null;
            if (filter_var($width, FILTER_VALIDATE_INT) && filter_var($height, FILTER_VALIDATE_INT) && $width > 0 && $height > 0) {
                $seo['ogImageWidth'] = (int) $width;
                $seo['ogImageHeight'] = (int) $height;
            }
            $seo['ogImageType'] = $sameImage ? ($meta['og:image:type'] ?? null) : null;
            $seo['ogImageType'] ??= $imageNode['encodingFormat'] ?? null;
        }

        return $seo;
    }

    private static function attribute(string $tag, string $name): ?string
    {
        if (! preg_match('/(?:^|\s)'.preg_quote($name, '/').'\s*=\s*([\x22\x27])(.*?)\1/is', $tag, $match)) {
            return null;
        }

        $value = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($value) !== '' ? $value : null;
    }

    /** @return list<array{attribute: string, name: string, content: string, flag: string}> */
    public static function tags(array $seo, ?string $description = null, ?string $locale = null): array
    {
        $title = $seo['ogTitle'] ?? $seo['title'] ?? null;
        $description = $seo['ogDescription'] ?? $description;
        $image = $seo['ogImageUrl'] ?? null;
        $tags = [];
        $add = static function (string $attribute, string $name, mixed $value, string $flag) use (&$tags): void {
            if ((is_string($value) && trim($value) !== '') || (is_int($value) && $value > 0)) {
                $tags[] = ['attribute' => $attribute, 'name' => $name, 'content' => (string) $value, 'flag' => $flag];
            }
        };
        $add('property', 'og:title', $title, 'og_title');
        $add('property', 'og:description', $description, 'og_description');
        $add('property', 'og:url', $seo['canonicalUrl'] ?? null, 'canonical');
        $add('property', 'og:type', $seo['ogType'] ?? null, 'social_meta');
        $add('property', 'og:site_name', $seo['ogSiteName'] ?? null, 'social_meta');
        $add('property', 'og:locale', self::locale($seo['ogLocale'] ?? $locale), 'social_meta');
        if ($image) {
            $add('property', 'og:image', $image, 'og_image');
            if (str_starts_with($image, 'https://')) {
                $add('property', 'og:image:secure_url', $image, 'og_image');
            }
            $add('property', 'og:image:type', $seo['ogImageType'] ?? null, 'og_image');
            $add('property', 'og:image:width', $seo['ogImageWidth'] ?? null, 'og_image');
            $add('property', 'og:image:height', $seo['ogImageHeight'] ?? null, 'og_image');
            $add('property', 'og:image:alt', $seo['ogImageAlt'] ?? $title, 'og_image');
        }
        $add('name', 'twitter:card', $seo['twitterCard'] ?? ($image ? 'summary_large_image' : 'summary'), 'social_meta');
        $add('name', 'twitter:title', $title, 'og_title');
        $add('name', 'twitter:description', $description, 'og_description');
        $add('name', 'twitter:site', $seo['twitterSite'] ?? null, 'social_meta');
        if ($image) {
            $add('name', 'twitter:image', $image, 'og_image');
            $add('name', 'twitter:image:alt', $seo['ogImageAlt'] ?? $title, 'og_image');
        }
        if (($seo['ogType'] ?? null) === 'article') {
            $add('property', 'article:published_time', $seo['articlePublishedTime'] ?? null, 'social_meta');
            $add('property', 'article:modified_time', $seo['articleModifiedTime'] ?? null, 'social_meta');
        }

        return $tags;
    }
}
