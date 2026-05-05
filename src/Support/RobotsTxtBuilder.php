<?php

declare(strict_types=1);

namespace Smking\Laravel\Support;

/**
 * Idempotent merge helper for `public/robots.txt`. The customer's existing
 * file (often Disallow rules for /admin/, /cart/, etc.) stays intact; we
 * only manage a clearly-marked block at the bottom containing AI bot
 * directives + Content-Signal. Re-running the publish command replaces
 * that block in place rather than appending duplicates.
 *
 * Marker format is versioned so a future block layout change can be
 * detected by parsing the marker line — but in v1 we just match the
 * outer fence.
 */
class RobotsTxtBuilder
{
    public const BLOCK_START = '# {smking-aeo-block-start v1}';

    public const BLOCK_END = '# {smking-aeo-block-end}';

    /**
     * @param  array<string, string>  $bots  ['GPTBot' => 'allow', ...]
     */
    public static function merge(string $existing, array $bots, ?string $contentSignal): string
    {
        $block = self::buildBlock($bots, $contentSignal);

        if (self::hasManagedBlock($existing)) {
            // Pattern eats the trailing newline so replacement (which already
            // ends with "\n") doesn't double up. Without this, re-merging an
            // already-merged file would grow by one "\n" each pass and
            // break idempotency.
            $pattern = '/'.preg_quote(self::BLOCK_START, '/').'.*?'.preg_quote(self::BLOCK_END, '/').'\n?/s';

            return (string) preg_replace($pattern, $block, $existing, 1);
        }

        $base = rtrim($existing, "\n");

        return $base === ''
            ? $block
            : $base."\n\n".$block;
    }

    public static function hasManagedBlock(string $content): bool
    {
        return str_contains($content, self::BLOCK_START) && str_contains($content, self::BLOCK_END);
    }

    /**
     * @param  array<string, string>  $bots
     */
    private static function buildBlock(array $bots, ?string $contentSignal): string
    {
        $lines = [self::BLOCK_START];

        foreach ($bots as $name => $rule) {
            $directive = strtolower($rule) === 'disallow' ? 'Disallow: /' : 'Allow: /';
            $lines[] = '';
            $lines[] = 'User-agent: '.$name;
            $lines[] = $directive;
        }

        if ($contentSignal !== null && $contentSignal !== '') {
            $lines[] = '';
            $lines[] = 'User-agent: *';
            $lines[] = 'Content-Signal: '.$contentSignal;
        }

        $lines[] = self::BLOCK_END;
        $lines[] = '';

        return implode("\n", $lines);
    }
}
