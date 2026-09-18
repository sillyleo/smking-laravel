<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

/** The only content identifiers allowed into HTTP queries or local work. */
final class DeliveryIdentifier
{
    /** @return array<string, string|int>|null */
    public static function parameters(string $resource, string $identifier): ?array
    {
        if ($resource === 'cms-page' && str_starts_with($identifier, 'slug:')) {
            $slug = substr($identifier, 5);
            if (strlen($slug) > 200
                || str_starts_with($slug, '/')
                || str_ends_with($slug, '/')
                || preg_match('/[?#\\\\\x00-\x1f\x7f]/', $slug)
                || in_array('.', explode('/', $slug), true)
                || in_array('..', explode('/', $slug), true)
            ) {
                return null;
            }

            return ['slug' => $slug];
        }

        if ($resource === 'site-file' && in_array($identifier, ['kind:sitemap', 'kind:robots', 'kind:llms_txt'], true)) {
            return ['kind' => substr($identifier, 5)];
        }

        if (in_array($resource, ['aeo', 'markdown'], true) && str_starts_with($identifier, 'path:/')) {
            $path = substr($identifier, 5);
            if (strlen($path) > 500
                || str_starts_with($path, '//')
                || preg_match('/[?#\\\\\x00-\x1f\x7f]/', $path)
            ) {
                return null;
            }

            return ['path' => $path];
        }

        if (in_array($resource, ['aeo', 'markdown'], true)
            && preg_match('/^product_id:([1-9][0-9]*)$/D', $identifier, $matches) === 1
            && (float) $matches[1] <= 2_147_483_647
        ) {
            return ['product_id' => (int) $matches[1]];
        }

        return null;
    }
}
