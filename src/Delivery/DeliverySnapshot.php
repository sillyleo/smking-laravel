<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use DateTimeImmutable;
use DateTimeZone;

/** Validated public v2 content. Cache records are plain arrays, never SDK objects. */
final class DeliverySnapshot
{
    public const CACHE_FORMAT = 1;

    private function __construct(
        public readonly string $resource,
        public readonly string $identifier,
        public readonly array $payload,
        public readonly int $validatedAtMs,
        public readonly int $freshUntilMs,
        public readonly int $usableUntilMs,
    ) {
    }

    public static function fromResponse(
        string $resource,
        string $identifier,
        int $httpStatus,
        mixed $payload,
        int $nowMs,
    ): ?self {
        if (! is_array($payload) || array_key_exists('preview', $payload)) {
            return null;
        }

        $status = $payload['status'] ?? null;
        if (!(($httpStatus === 200 && $status === 'ready') || ($httpStatus === 404 && $status === 'not_found'))) {
            return null;
        }

        $delivery = $payload['delivery'] ?? null;
        if (! is_array($delivery)
            || ($delivery['contract'] ?? null) !== '2'
            || ! is_string($delivery['content_version'] ?? null)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $delivery['content_version']) !== 1
        ) {
            return null;
        }

        $validatedAt = self::timestamp($delivery['validated_at'] ?? null);
        $freshUntil = self::timestamp($delivery['fresh_until'] ?? null);
        $usableUntil = self::timestamp($delivery['usable_until'] ?? null);
        $maxFresh = $status === 'ready' ? 300_000 : 60_000;
        $maxUsable = $status === 'ready' ? 3_600_000 : 60_000;

        if ($validatedAt === null || $freshUntil === null || $usableUntil === null
            || $validatedAt > $nowMs + 30_000
            || $freshUntil < $validatedAt
            || $usableUntil < $freshUntil
            || $freshUntil - $validatedAt > $maxFresh
            || $usableUntil - $validatedAt > $maxUsable
            || $usableUntil <= $nowMs
        ) {
            return null;
        }

        if ($status === 'ready' && ! self::hasValidBody($resource, $identifier, $payload)) {
            return null;
        }

        return new self(
            resource: $resource,
            identifier: $identifier,
            payload: $payload,
            validatedAtMs: $validatedAt,
            freshUntilMs: $freshUntil,
            usableUntilMs: $usableUntil,
        );
    }

    public static function fromCache(
        string $resource,
        string $identifier,
        mixed $record,
        int $nowMs,
        int $format = self::CACHE_FORMAT,
    ): ?self {
        if (! is_array($record)
            || ($record['format'] ?? null) !== $format
            || ($record['resource'] ?? null) !== $resource
            || ($record['identifier'] ?? null) !== $identifier
        ) {
            return null;
        }

        $payload = $record['payload'] ?? null;
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        $httpStatus = $status === 'not_found' ? 404 : 200;

        return self::fromResponse($resource, $identifier, $httpStatus, $payload, $nowMs);
    }

    /**
     * @return array{format: int, resource: string, identifier: string, payload: array<string, mixed>}
     */
    public function toCache(int $format = self::CACHE_FORMAT): array
    {
        return [
            'format' => $format,
            'resource' => $this->resource,
            'identifier' => $this->identifier,
            'payload' => $this->payload,
        ];
    }

    public function isFresh(int $nowMs): bool
    {
        return $nowMs < $this->freshUntilMs;
    }

    public function isUsable(int $nowMs): bool
    {
        return $nowMs < $this->usableUntilMs;
    }

    private static function timestamp(mixed $value): ?int
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1
        ) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.v\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            return null;
        }

        return ((int) $parsed->format('U')) * 1000 + (int) $parsed->format('v');
    }

    private static function hasValidBody(string $resource, string $identifier, array $payload): bool
    {
        if ($resource === 'cms-page' && str_starts_with($identifier, 'slug:')) {
            $page = $payload['page'] ?? null;
            if (! is_array($page) || ($page['slug'] ?? null) !== substr($identifier, 5)) {
                return false;
            }
            foreach (['title', 'contentType', 'bodyHtml', 'excerpt', 'featuredImageUrl', 'publishedAt'] as $field) {
                if (isset($page[$field]) && ! is_string($page[$field])) {
                    return false;
                }
            }
            if (isset($page['blocks']) && (! is_array($page['blocks']) || ! array_is_list($page['blocks']))) {
                return false;
            }
            if (isset($page['jsonLd']) && ! is_array($page['jsonLd'])) {
                return false;
            }
            if (! self::hasValidNullableStringMap($payload['seo'] ?? null, [
                'title',
                'metaDescription',
                'ogTitle',
                'ogDescription',
                'ogImageUrl',
                'canonicalUrl',
            ])) {
                return false;
            }

            return (is_string($page['bodyHtml'] ?? null) && $page['bodyHtml'] !== '')
                || (is_array($page['blocks'] ?? null) && array_is_list($page['blocks']));
        }

        if ($resource === 'aeo') {
            if (! is_array($payload['jsonLd'] ?? null)) {
                return false;
            }
            foreach (['summary', 'metaDescription', 'faqHtml', 'summaryHtml'] as $field) {
                if (isset($payload[$field]) && ! is_string($payload[$field])) {
                    return false;
                }
            }
            if (isset($payload['faq'])) {
                if (! is_array($payload['faq']) || ! array_is_list($payload['faq'])) {
                    return false;
                }
                foreach ($payload['faq'] as $item) {
                    if (! is_array($item)
                        || ! is_string($item['q'] ?? null)
                        || ! is_string($item['a'] ?? null)
                    ) {
                        return false;
                    }
                }
            }
            if (! self::hasValidNullableStringMap($payload['chatLinks'] ?? null, ['chatgpt', 'perplexity', 'google'])
                || ! self::hasValidNullableStringMap($payload['seo'] ?? null, [
                    'title',
                    'metaDescription',
                    'ogTitle',
                    'ogDescription',
                    'ogImageUrl',
                    'canonicalUrl',
                ])
            ) {
                return false;
            }

            return true;
        }

        if (in_array($resource, ['markdown', 'site-file'], true)) {
            $document = $payload['document'] ?? null;
            if (! is_array($document)
                || ! is_string($document['body'] ?? null)
                || $document['body'] === ''
            ) {
                return false;
            }

            if ($resource === 'site-file') {
                $kind = substr($identifier, 5);
                $contentType = $kind === 'sitemap'
                    ? 'application/xml; charset=utf-8'
                    : 'text/plain; charset=utf-8';

                return ($document['kind'] ?? null) === $kind
                    && ($document['content_type'] ?? null) === $contentType;
            }

            $field = str_starts_with($identifier, 'path:') ? 'path' : 'product_id';
            $expected = $field === 'path' ? substr($identifier, 5) : (int) substr($identifier, 11);

            return ($document[$field] ?? null) === $expected
                && ($document['content_type'] ?? null) === 'text/markdown; charset=utf-8';
        }

        return false;
    }

    /**
     * @param  list<string>  $fields
     */
    private static function hasValidNullableStringMap(mixed $value, array $fields): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($fields as $field) {
            if (isset($value[$field]) && ! is_string($value[$field])) {
                return false;
            }
        }

        return true;
    }
}
