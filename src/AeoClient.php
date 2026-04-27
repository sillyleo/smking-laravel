<?php

declare(strict_types=1);

namespace Smking\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;
use Smking\Laravel\Data\AeoResponse;
use Throwable;

/**
 * Thin client over the smking Public AEO API.
 *
 * Wraps Laravel's HTTP client with caching + graceful failure: a slow upstream
 * or network error must never break a page render, so every error path returns
 * a not_found response and logs for diagnostics.
 */
class AeoClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Discover AEO content for a path. Uses POST so the server registers the
     * path for background crawling when it hasn't been seen before.
     */
    public function forPath(string $path, ?string $url = null): AeoResponse
    {
        return $this->remember(['path' => $path], function () use ($path, $url) {
            return $this->discover(['path' => $path, 'url' => $url]);
        });
    }

    public function forProductId(int $productId): AeoResponse
    {
        return $this->remember(['product_id' => $productId], function () use ($productId) {
            return $this->discover(['product_id' => $productId]);
        });
    }

    /**
     * @param  array{path?: string, url?: ?string, product_id?: int}  $body
     */
    private function discover(array $body): AeoResponse
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return AeoResponse::notFound();
        }

        $payload = array_filter(
            array_merge(['key' => $apiKey], $body),
            static fn ($value) => $value !== null && $value !== '',
        );

        try {
            $response = $this->http
                ->timeout((int) $this->config->get('smking.timeout', 3))
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint('/api/v1/public/aeo'), $payload);
        } catch (Throwable $e) {
            $this->logger?->warning('smking: AEO discovery failed', [
                'message' => $e->getMessage(),
                'body' => $body,
            ]);

            return AeoResponse::notFound();
        }

        if ($response->status() === 202) {
            return AeoResponse::pending();
        }

        if (! $response->successful()) {
            return AeoResponse::notFound();
        }

        $json = $response->json();
        if (! is_array($json)) {
            return AeoResponse::notFound();
        }

        return AeoResponse::fromArray($json);
    }

    /**
     * @param  array<string, scalar>  $keyParts
     * @param  callable(): AeoResponse  $resolver
     */
    private function remember(array $keyParts, callable $resolver): AeoResponse
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        $enabled = (bool) ($cacheConfig['enabled'] ?? true);
        $ttl = (int) ($cacheConfig['ttl'] ?? 3600);

        if (! $enabled || $ttl <= 0) {
            return $resolver();
        }

        $store = $cacheConfig['store'] ?? null;
        $repository = $store ? $this->cache->store($store) : $this->cache->store();
        $cacheKey = ($cacheConfig['prefix'] ?? 'smking:aeo:').http_build_query($keyParts);

        $cached = $repository->get($cacheKey);
        if ($cached instanceof AeoResponse) {
            return $cached;
        }

        $response = $resolver();

        // Don't cache pending — we want to recheck on the next request so
        // users get fresh content as soon as the crawler finishes.
        if ($response->status !== AeoResponse::STATUS_PENDING) {
            $repository->put($cacheKey, $response, $ttl);
        }

        return $response;
    }

    private function apiKey(): ?string
    {
        $key = $this->config->get('smking.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function endpoint(string $path): string
    {
        $base = rtrim((string) $this->config->get('smking.base_url', 'https://app.smking.io'), '/');

        return $base.$path;
    }
}
