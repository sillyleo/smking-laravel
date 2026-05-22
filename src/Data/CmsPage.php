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
 * v0.14.0+ — `blocks` (Block[] from substrate v2) is the canonical
 * shape; `bodyHtml` is the legacy path retained for pre-pivot SaaS
 * deployments. Exactly one of the two is populated for a ready page.
 *
 * - `blocks` — Block[] shape `{component, id, props}`. cms.blade.php
 *   dispatches each block to its rendered partial. Article blocks
 *   carry pre-rendered HTML in `props.html` (server-rendered by Plate
 *   `serializeHtml` on the SaaS side, so SDK ships zero editor deps).
 *   Other components (hero / nav-* / carousel) get markup composed
 *   on the SDK side from the block's props.
 *
 * - `bodyHtml` — server-rendered HTML produced by tiptap-php
 *   (EditorFactory). Already escaped, safe to print with `{!!` in
 *   Blade. Trust boundary is the smking SaaS output; if you point
 *   `SMKING_BASE_URL` at a custom origin, audit that origin's escape
 *   behaviour before deploying.
 */
final class CmsPage
{
    public const STATUS_READY = 'ready';
    public const STATUS_PENDING = 'pending';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_SERVER_ERROR = 'server_error';

    /**
     * @param  ?list<array<string, mixed>>  $blocks
     *   v0.14.0+ — substrate v2 Block[] shape `{component, id, props}`.
     *   Populated when SaaS returns `page.blocks`. Mutually exclusive
     *   with `bodyHtml` in practice (a single SaaS deployment returns
     *   one or the other, not both).
     *
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
        public readonly ?array $blocks = null,
        public readonly ?string $publishedAt = null,
        public readonly ?array $seo = null,
    ) {
    }

    /**
     * Build a ready CmsPage from the SaaS payload. Exactly one of
     * `$bodyHtml` (legacy Tiptap path) or `$blocks` (substrate v2 path)
     * should be passed.
     *
     * @param  array<string, mixed>  $payload  shape: { status, page: { slug, title, body|blocks, publishedAt }, seo? }
     * @param  ?list<array<string, mixed>>  $blocks
     */
    public static function fromArray(
        array $payload,
        ?string $bodyHtml = null,
        ?array $blocks = null,
    ): self {
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
            blocks: $blocks,
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
            'blocks' => $this->blocks,
            'publishedAt' => $this->publishedAt,
            'seo' => $this->seo,
        ];
    }
}
