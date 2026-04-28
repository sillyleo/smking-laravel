<?php

declare(strict_types=1);

namespace Smking\Laravel\Support;

/**
 * Single source of truth for how the SDK canonicalizes URL paths into
 * cache keys. Both the InjectAeo middleware and the smking:cache:purge
 * command MUST use this so they agree on the cache key for a given URL —
 * if they diverge, an outage-recovery purge can "succeed" against a key
 * the middleware never wrote, leaving the real entry stuck.
 *
 * Rules:
 *   - prepend leading slash if missing
 *   - root path stays as `/`
 *   - everything else has trailing slash stripped
 *   - whitespace trimmed
 *
 * Examples:
 *   ''                  → '/'
 *   '/'                 → '/'
 *   '/products'         → '/products'
 *   '/products/'        → '/products'
 *   'products/widget'   → '/products/widget'
 *   '/products/widget/' → '/products/widget'
 */
final class PathNormalizer
{
    public static function canonical(string $path): string
    {
        $path = trim($path);
        $path = '/'.ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
