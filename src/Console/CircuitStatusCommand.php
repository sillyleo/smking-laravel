<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Smking\Laravel\AeoClient;

/**
 * `php artisan smking:circuit:status` — inspect the per-surface circuit
 * breaker state for ops / scripted health checks.
 *
 * Why this exists: v0.7.0 added a circuit breaker that short-circuits all
 * upstream calls for `circuit_breaker_ttl` seconds after a failure. The
 * breaker is invisible to operators — when traffic drops or AEO content
 * stops appearing, there's no way to tell whether the SDK is short-
 * circuiting or whether the upstream is genuinely returning empty
 * responses. This command answers "is the breaker open right now?" without
 * forcing a `cache:purge` (which has the side effect of resetting the
 * breaker on top of forgetting cached entries).
 *
 * Two surfaces (since v0.7.0 round-4) recover independently:
 *   - aeo  — HTML AEO injection, every page render
 *   - md   — markdown-for-agents (Accept: text/markdown), agent-only
 *
 * Exit codes (script-friendly):
 *   0 — all surfaces closed (or breaker disabled by config)
 *   1 — any surface open
 */
class CircuitStatusCommand extends Command
{
    protected $signature = 'smking:circuit:status';

    protected $description = 'Show current state of the per-surface AEO circuit breakers';

    public function handle(AeoClient $client): int
    {
        $cacheConfig = (array) config('smking.cache', []);

        if (! ($cacheConfig['circuit_breaker'] ?? true)) {
            $this->line('smking circuit breaker: DISABLED (set SMKING_CIRCUIT_BREAKER=true to enable)');

            return self::SUCCESS;
        }

        $store = $client->cacheStore();
        $prefixes = $client->cacheKeyPrefixes();
        $configuredTtl = (int) ($cacheConfig['circuit_breaker_ttl'] ?? 60);

        $surfaces = [
            'aeo' => ['label' => 'HTML AEO injection', 'key' => $prefixes['circuit_aeo']],
            'md' => ['label' => 'markdown for agents', 'key' => $prefixes['circuit_md']],
        ];

        $anyOpen = false;
        $this->line('smking circuit breaker status:');
        $this->line('');

        foreach ($surfaces as $surface => $info) {
            $isOpen = $store->has($info['key']);
            if ($isOpen) {
                $anyOpen = true;
                // Configured TTL is shown for context, NOT remaining TTL —
                // Laravel's Cache contract has no portable `ttl($key)`
                // method, and the breaker auto-resets to 60s on every
                // re-trip during a continuing outage, so "remaining" is
                // a moving target anyway. Re-run this command in a few
                // seconds to confirm whether the breaker actually
                // cleared, instead of relying on a static countdown.
                $this->line(sprintf(
                    '  %-3s (%s): <fg=red>OPEN</> (configured TTL %ds; re-run to confirm recovery)',
                    $surface,
                    $info['label'],
                    $configuredTtl,
                ));
            } else {
                $this->line(sprintf(
                    '  %-3s (%s): <fg=green>closed</>',
                    $surface,
                    $info['label'],
                ));
            }
            $this->line(sprintf('       key: %s', $info['key']));
        }

        $this->line('');
        if ($anyOpen) {
            $this->line('Recovery: wait for TTL, or `php artisan smking:cache:purge <path>` / `--product-id=N` to force-clear.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
