<?php

declare(strict_types=1);

namespace Smking\Laravel\Support;

/**
 * Recursive config merge for the service provider.
 *
 * Laravel's `ServiceProvider::mergeConfigFrom` only `array_merge`s the TOP
 * level of a config tree, so a customer's published `config/smking.php`
 * that predates a newly-added nested default (e.g. `cms.base_path`, added
 * long after they first ran `vendor:publish`) silently drops that default:
 * their partial `cms` array replaces ours wholesale and the new leaf comes
 * back `null`. That is exactly how `cms_base_path` reached the dashboard as
 * null — the heartbeat read `config('smking.cms.base_path')`, got null, and
 * nav cards lost their mount prefix and 404'd.
 *
 * deep() walks nested ASSOCIATIVE arrays so package defaults survive unless
 * the customer explicitly set that exact leaf. LIST arrays (crawler pattern
 * lists, except-patterns, …) are taken from the customer verbatim — never
 * concatenated, which would duplicate or reorder an ordered list.
 */
final class ConfigMerge
{
    /**
     * Deep-merge $custom over $default.
     *
     * @param  array<string, mixed>  $default  package defaults (lower precedence)
     * @param  array<string, mixed>  $custom   customer config (higher precedence)
     * @return array<string, mixed>
     */
    public static function deep(array $default, array $custom): array
    {
        $merged = $default;

        foreach ($custom as $key => $value) {
            $existing = $merged[$key] ?? null;

            if (
                is_array($value)
                && is_array($existing)
                && ! array_is_list($value)
                && ! array_is_list($existing)
            ) {
                // Both sides are associative arrays → recurse, so a leaf the
                // customer didn't mention falls back to the package default.
                $merged[$key] = self::deep($existing, $value);
            } else {
                // Scalar, list array, or type mismatch → customer wins
                // verbatim. Lists are replaced (not concatenated) on purpose.
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
