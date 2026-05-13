<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Support\PathNormalizer;

/**
 * `php artisan smking:cache:purge <path>` — invalidate one cached AEO entry.
 * `php artisan smking:cache:purge --product-id=N` — purge a WC product.
 *
 * Common reasons to run this:
 *   - **Just generated content** for `/products/widget`. The SDK is
 *     serving 15min-cached `not_found` (or older `ready`); purge to
 *     re-fetch immediately.
 *   - **SaaS came back online** after an outage. Paths cached as
 *     `server_error` (24hr TTL) won't retry until they expire — purge
 *     each affected path to force re-attempt.
 *   - **WC product re-pushed** — `forProductId(123)` caches under a
 *     different key than path-based, so `--product-id=123` is the only
 *     way to invalidate that surface short of `cache:clear`.
 *
 * Bulk-purge of the whole smking namespace isn't supported here because
 * Laravel's Cache facade doesn't expose driver-level key enumeration in
 * a portable way; if you need to wipe everything, run `php artisan
 * cache:clear` (clears the whole app cache, smking included).
 */
class CachePurgeCommand extends Command
{
    protected $signature = 'smking:cache:purge
        {path? : URL path to purge (e.g. /products/widget). Mutually exclusive with --product-id.}
        {--product-id= : WC product ID — purges the cache key written by Smking::forProductId() / forProductId().}';

    protected $description = 'Forget cached AEO + markdown responses for a specific path or product ID';

    public function handle(AeoClient $client): int
    {
        $rawPath = (string) $this->argument('path');
        $productId = $this->option('product-id');

        if ($rawPath !== '' && $productId !== null) {
            $this->error('Provide either <path> or --product-id, not both.');

            return self::INVALID;
        }

        if ($productId !== null) {
            return $this->purgeByProductId($client, (int) $productId);
        }

        if ($rawPath === '') {
            $this->error('Provide a path argument or --product-id=N.');

            return self::INVALID;
        }

        return $this->purgeByPath($client, $rawPath);
    }

    private function purgeByPath(AeoClient $client, string $rawPath): int
    {
        // Canonicalize identically to InjectAeo middleware — without this,
        // `smking:cache:purge /x/` would build the cache key for `/x/`,
        // but the middleware writes under `/x`, so purge says "success"
        // while the real entry survives the full server_error TTL.
        $path = PathNormalizer::canonical($rawPath);

        $store = $client->cacheStore();
        $prefixes = $client->cacheKeyPrefixes();

        $aeoKey = $prefixes['aeo'].http_build_query(['path' => $path]);
        $mdKey = $prefixes['markdown'].http_build_query(['path' => $path]);

        $store->forget($aeoKey);
        $store->forget($mdKey);

        // v0.10.0: clear the adaptive-backoff failure counters too. Without
        // this, purge would reset the cache value but leave the counter at
        // (say) 3, so the next upstream failure would jump straight to the
        // 30-minute backoff step instead of restarting at 30s.
        $store->forget($aeoKey.':fc');
        $store->forget($mdKey.':fc');

        // v0.7.0 round-4: also clear the per-surface circuit breakers.
        // purge is a manual recovery action — operator is explicitly
        // saying "retry now". Without this, "next request re-fetches"
        // (advertised in the docstring above) is false during the
        // breaker TTL window and the operator just has to wait.
        // Both surfaces are touched: a purged path can be hit by either
        // forPath() or getMarkdown(), so clearing both gives deterministic
        // recovery regardless of which surface the next request lands on.
        $store->forget($prefixes['circuit_aeo']);
        $store->forget($prefixes['circuit_md']);

        if ($rawPath !== $path) {
            $this->line("Input path canonicalized: {$rawPath} → {$path}");
        }
        $this->info("Purged smking cache for path: {$path}");
        $this->line("  aeo     → {$aeoKey}");
        $this->line("  md      → {$mdKey}");
        $this->line('  circuit → cleared (aeo + md surfaces)');

        return self::SUCCESS;
    }

    private function purgeByProductId(AeoClient $client, int $productId): int
    {
        if ($productId <= 0) {
            $this->error('--product-id must be a positive integer.');

            return self::INVALID;
        }

        $store = $client->cacheStore();
        $prefixes = $client->cacheKeyPrefixes();

        // forProductId() and the markdown path-API endpoint have different
        // backends; markdown rendering uses path keys, AEO uses product_id.
        // Purge the AEO surface only — markdown for product_id flows
        // through the path surface and would already be covered by a
        // separate path-based purge.
        $aeoKey = $prefixes['aeo'].http_build_query(['product_id' => $productId]);
        $store->forget($aeoKey);

        // v0.10.0: clear the adaptive-backoff failure counter too.
        $store->forget($aeoKey.':fc');

        // v0.7.0 round-4: clear the AEO surface breaker so the next call
        // for this product (or any path) actually retries instead of
        // serving short-circuit server_error responses for the remaining
        // breaker TTL. Markdown breaker is left alone — product_id never
        // touches markdown surface.
        $store->forget($prefixes['circuit_aeo']);

        $this->info("Purged smking cache for product_id: {$productId}");
        $this->line("  aeo     → {$aeoKey}");
        $this->line('  circuit → cleared (aeo surface)');

        return self::SUCCESS;
    }
}
