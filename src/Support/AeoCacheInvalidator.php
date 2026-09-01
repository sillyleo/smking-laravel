<?php

declare(strict_types=1);

namespace Smking\Laravel\Support;

use Smking\Laravel\AeoClient;

/**
 * Single owner of customer-side AEO cache invalidation.
 *
 * Both the operator CLI and the signed publish webhook call this service so
 * they cannot drift on path normalization, cache-key shape, failure counters,
 * or circuit-breaker recovery.
 */
final class AeoCacheInvalidator
{
    public function __construct(
        private readonly AeoClient $client,
    ) {
    }

    /**
     * @param  list<string>  $paths
     * @return list<string> Unique canonical paths that were invalidated.
     */
    public function purgePaths(array $paths): array
    {
        $canonicalPaths = [];
        foreach ($paths as $path) {
            if (trim($path) === '') {
                continue;
            }
            $canonicalPaths[PathNormalizer::canonical($path)] = true;
        }

        $canonicalPaths = array_keys($canonicalPaths);
        foreach ($canonicalPaths as $path) {
            $this->purgePath($path);
        }

        return $canonicalPaths;
    }

    /**
     * @return array{path: string, aeo_key: string, markdown_key: string}
     */
    public function purgePath(string $rawPath): array
    {
        $path = PathNormalizer::canonical($rawPath);
        $store = $this->client->cacheStore();
        $prefixes = $this->client->cacheKeyPrefixes();
        $aeoKey = $prefixes['aeo'].http_build_query(['path' => $path]);
        $markdownKey = $prefixes['markdown'].http_build_query(['path' => $path]);

        $store->forget($aeoKey);
        $store->forget($markdownKey);
        $store->forget($aeoKey.':fc');
        $store->forget($markdownKey.':fc');
        $store->forget($prefixes['circuit_aeo']);
        $store->forget($prefixes['circuit_md']);

        return [
            'path' => $path,
            'aeo_key' => $aeoKey,
            'markdown_key' => $markdownKey,
        ];
    }

    /**
     * @return array{product_id: int, aeo_key: string}
     */
    public function purgeProductId(int $productId): array
    {
        $store = $this->client->cacheStore();
        $prefixes = $this->client->cacheKeyPrefixes();
        $aeoKey = $prefixes['aeo'].http_build_query(['product_id' => $productId]);

        $store->forget($aeoKey);
        $store->forget($aeoKey.':fc');
        $store->forget($prefixes['circuit_aeo']);

        return [
            'product_id' => $productId,
            'aeo_key' => $aeoKey,
        ];
    }
}
