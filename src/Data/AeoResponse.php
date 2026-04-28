<?php

declare(strict_types=1);

namespace Smking\Laravel\Data;

/**
 * Typed wrapper around a /api/v1/public/aeo response.
 *
 * The API returns one of three statuses:
 *   - ready: full payload with jsonLd, faq, summary, etc.
 *   - pending: page was just registered, crawl in flight
 *   - not_found: no content for this identifier
 *
 * Consumers should check {@see isReady()} before reading payload fields.
 */
final class AeoResponse
{
    public const STATUS_READY = 'ready';
    public const STATUS_PENDING = 'pending';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_SERVER_ERROR = 'server_error';

    /**
     * @param  array<string, mixed>|null  $jsonLd
     * @param  list<array{q: string, a: string}>  $faq
     * @param  array{chatgpt: string, perplexity: string, google: string}|null  $chatLinks
     */
    public function __construct(
        public readonly string $status,
        public readonly ?array $jsonLd = null,
        public readonly array $faq = [],
        public readonly string $summary = '',
        public readonly string $metaDescription = '',
        public readonly string $faqHtml = '',
        public readonly string $summaryHtml = '',
        public readonly ?array $chatLinks = null,
        public readonly ?SeoMeta $seo = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $status = (string) ($payload['status'] ?? self::STATUS_NOT_FOUND);

        $faq = [];
        if (isset($payload['faq']) && is_array($payload['faq'])) {
            foreach ($payload['faq'] as $item) {
                if (is_array($item) && isset($item['q'], $item['a'])) {
                    $faq[] = ['q' => (string) $item['q'], 'a' => (string) $item['a']];
                }
            }
        }

        $chatLinks = null;
        if (isset($payload['chatLinks']) && is_array($payload['chatLinks'])) {
            $chatLinks = [
                'chatgpt' => (string) ($payload['chatLinks']['chatgpt'] ?? ''),
                'perplexity' => (string) ($payload['chatLinks']['perplexity'] ?? ''),
                'google' => (string) ($payload['chatLinks']['google'] ?? ''),
            ];
        }

        $seo = null;
        if (isset($payload['seo']) && is_array($payload['seo'])) {
            $seo = SeoMeta::fromArray($payload['seo']);
        }

        return new self(
            status: $status,
            jsonLd: isset($payload['jsonLd']) && is_array($payload['jsonLd']) ? $payload['jsonLd'] : null,
            faq: $faq,
            summary: (string) ($payload['summary'] ?? ''),
            metaDescription: (string) ($payload['metaDescription'] ?? ''),
            faqHtml: (string) ($payload['faqHtml'] ?? ''),
            summaryHtml: (string) ($payload['summaryHtml'] ?? ''),
            chatLinks: $chatLinks,
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

    /**
     * SaaS unreachable (DNS / TCP / timeout / 5xx) — middleware behaves
     * identically to not_found (no injection) but cache TTL is much longer
     * (default 24hr) so a million-PV site doesn't keep retrying a dead
     * upstream. Customer recovery via `php artisan smking:cache:purge`.
     */
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
            'jsonLd' => $this->jsonLd,
            'faq' => $this->faq,
            'summary' => $this->summary,
            'metaDescription' => $this->metaDescription,
            'faqHtml' => $this->faqHtml,
            'summaryHtml' => $this->summaryHtml,
            'chatLinks' => $this->chatLinks,
            'seo' => $this->seo?->toArray(),
        ];
    }
}
