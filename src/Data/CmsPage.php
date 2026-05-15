<?php

declare(strict_types=1);

namespace Smking\Laravel\Data;

/**
 * Single CMS page payload, mirrors AeoResponse status taxonomy so
 * existing fail-open patterns transfer.
 *
 * Built from the SaaS public read endpoint:
 *   GET /api/v1/public/page?key=&slug=
 *
 * `bodyHtml` is server-rendered HTML produced by tiptap-php (see
 * EditorFactory) — already escaped, safe to print with `{!!` in Blade.
 * The trust boundary is the smking SaaS output; if you point
 * `SMKING_BASE_URL` at a custom origin, audit that origin's escape
 * behaviour before deploying.
 */
final class CmsPage
{
    public const STATUS_READY = 'ready';
    public const STATUS_PENDING = 'pending';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_SERVER_ERROR = 'server_error';

    /**
     * @param  ?array{title: ?string, metaDescription: ?string, ogTitle: ?string, ogDescription: ?string, ogImageUrl: ?string, canonicalUrl: ?string}  $seo
     *   v0.11.0+ — server-resolved SEO meta for the published page.
     *   Same shape as AeoResponse->seo (SeoMeta), so the customer can
     *   reuse the same Blade <x-smking-meta> emitter for both AEO product
     *   pages and CMS body pages without branching.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $slug = null,
        public readonly ?string $title = null,
        public readonly ?string $bodyHtml = null,
        public readonly ?string $publishedAt = null,
        public readonly ?array $seo = null,
    ) {
    }

    /**
     * Build a ready CmsPage from the SaaS payload + already-rendered HTML.
     *
     * @param  array<string, mixed>  $payload  shape: { status, page: { slug, title, body, publishedAt } }
     */
    public static function fromArray(array $payload, string $bodyHtml): self
    {
        $status = (string) ($payload['status'] ?? self::STATUS_NOT_FOUND);
        $page = $payload['page'] ?? null;

        if ($status !== self::STATUS_READY || ! is_array($page)) {
            return new self(status: $status);
        }

        $seo = null;
        $seoRaw = $payload['seo'] ?? null;
        if (is_array($seoRaw)) {
            $seo = [
                'title' => isset($seoRaw['title']) && is_string($seoRaw['title']) ? $seoRaw['title'] : null,
                'metaDescription' => isset($seoRaw['metaDescription']) && is_string($seoRaw['metaDescription']) ? $seoRaw['metaDescription'] : null,
                'ogTitle' => isset($seoRaw['ogTitle']) && is_string($seoRaw['ogTitle']) ? $seoRaw['ogTitle'] : null,
                'ogDescription' => isset($seoRaw['ogDescription']) && is_string($seoRaw['ogDescription']) ? $seoRaw['ogDescription'] : null,
                'ogImageUrl' => isset($seoRaw['ogImageUrl']) && is_string($seoRaw['ogImageUrl']) ? $seoRaw['ogImageUrl'] : null,
                'canonicalUrl' => isset($seoRaw['canonicalUrl']) && is_string($seoRaw['canonicalUrl']) ? $seoRaw['canonicalUrl'] : null,
            ];
        }

        return new self(
            status: self::STATUS_READY,
            slug: isset($page['slug']) ? (string) $page['slug'] : null,
            title: isset($page['title']) ? (string) $page['title'] : null,
            bodyHtml: $bodyHtml,
            publishedAt: isset($page['publishedAt']) && is_string($page['publishedAt'])
                ? $page['publishedAt']
                : null,
            seo: $seo,
        );
    }

    public static function notFound(): self
    {
        return new self(status: self::STATUS_NOT_FOUND);
    }

    public static function pending(): self
    {
        return new self(status: self::STATUS_PENDING);
    }

    public static function serverError(): self
    {
        return new self(status: self::STATUS_SERVER_ERROR);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'slug' => $this->slug,
            'title' => $this->title,
            'bodyHtml' => $this->bodyHtml,
            'publishedAt' => $this->publishedAt,
            'seo' => $this->seo,
        ];
    }
}
