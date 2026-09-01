<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Smking\Laravel\Support\AeoCacheInvalidator;

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

    public function handle(AeoCacheInvalidator $invalidator): int
    {
        $rawPath = (string) $this->argument('path');
        $productId = $this->option('product-id');

        if ($rawPath !== '' && $productId !== null) {
            $this->error('Provide either <path> or --product-id, not both.');

            return self::INVALID;
        }

        if ($productId !== null) {
            return $this->purgeByProductId($invalidator, (int) $productId);
        }

        if ($rawPath === '') {
            $this->error('Provide a path argument or --product-id=N.');

            return self::INVALID;
        }

        return $this->purgeByPath($invalidator, $rawPath);
    }

    private function purgeByPath(AeoCacheInvalidator $invalidator, string $rawPath): int
    {
        $result = $invalidator->purgePath($rawPath);
        $path = $result['path'];

        if ($rawPath !== $path) {
            $this->line("Input path canonicalized: {$rawPath} → {$path}");
        }
        $this->info("Purged smking cache for path: {$path}");
        $this->line("  aeo     → {$result['aeo_key']}");
        $this->line("  md      → {$result['markdown_key']}");
        $this->line('  circuit → cleared (aeo + md surfaces)');

        return self::SUCCESS;
    }

    private function purgeByProductId(AeoCacheInvalidator $invalidator, int $productId): int
    {
        if ($productId <= 0) {
            $this->error('--product-id must be a positive integer.');

            return self::INVALID;
        }

        $result = $invalidator->purgeProductId($productId);

        $this->info("Purged smking cache for product_id: {$productId}");
        $this->line("  aeo     → {$result['aeo_key']}");
        $this->line('  circuit → cleared (aeo surface)');

        return self::SUCCESS;
    }
}
