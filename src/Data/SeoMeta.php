<?php

declare(strict_types=1);

namespace Smking\Laravel\Data;

/**
 * Server-resolved SEO metadata for a page (title / OG / canonical).
 *
 * The smking Public API does the fallback chain server-side
 * (ogTitle → seoTitle, ogDescription → metaDescription,
 *  ogImageUrl → imageUrl, canonicalUrl → pageUrl), so each field is
 * either a usable string or null. Consumers should treat null as
 * "no signal — defer to the host application's own meta tags".
 *
 * Mirrors `getSmkingMetadata()` in @smking/next so SEO behavior is
 * identical across delivery layers.
 */
final class SeoMeta
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $ogTitle = null,
        public readonly ?string $ogDescription = null,
        public readonly ?string $ogImageUrl = null,
        public readonly ?string $canonicalUrl = null,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromArray(?array $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        $self = new self(
            title: self::nullableString($payload, 'title'),
            ogTitle: self::nullableString($payload, 'ogTitle'),
            ogDescription: self::nullableString($payload, 'ogDescription'),
            ogImageUrl: self::nullableString($payload, 'ogImageUrl'),
            canonicalUrl: self::nullableString($payload, 'canonicalUrl'),
        );

        // Discard the wrapper if the API returned the key but every field is
        // null — keeps consumer "if ($seo)" checks meaningful.
        return $self->isEmpty() ? null : $self;
    }

    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->ogTitle === null
            && $this->ogDescription === null
            && $this->ogImageUrl === null
            && $this->canonicalUrl === null;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'ogTitle' => $this->ogTitle,
            'ogDescription' => $this->ogDescription,
            'ogImageUrl' => $this->ogImageUrl,
            'canonicalUrl' => $this->canonicalUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function nullableString(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }
        $value = $payload[$key];
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
