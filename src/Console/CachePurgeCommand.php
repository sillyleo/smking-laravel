<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Support\PathNormalizer;

/**
 * `php artisan smking:cache:purge <path>` — invalidate one cached AEO entry.
 *
 * Common reasons to run this:
 *   - **Just generated content** for `/products/widget`. The SDK is
 *     serving 15min-cached `not_found` (or older `ready`); purge to
 *     re-fetch immediately.
 *   - **SaaS came back online** after an outage. Paths cached as
 *     `server_error` (24hr TTL) won't retry until they expire — purge
 *     each affected path to force re-attempt.
 *
 * The command takes a **specific path**. Bulk-purge of the whole smking
 * namespace isn't supported here because Laravel's Cache facade doesn't
 * expose driver-level key enumeration in a portable way; if you need to
 * wipe everything, run `php artisan cache:clear` (clears the whole app
 * cache, smking included).
 */
class CachePurgeCommand extends Command
{
    protected $signature = 'smking:cache:purge {path : Path to purge (e.g. /products/widget). The exact namespaced key for both AEO and markdown caches is computed and forgotten.}';

    protected $description = 'Forget cached AEO + markdown responses for a specific path';

    public function handle(AeoClient $client): int
    {
        $rawPath = (string) $this->argument('path');
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

        if ($rawPath !== $path) {
            $this->line("Input path canonicalized: {$rawPath} → {$path}");
        }
        $this->info("Purged smking cache for path: {$path}");
        $this->line("  aeo  → {$aeoKey}");
        $this->line("  md   → {$mdKey}");

        return self::SUCCESS;
    }
}
