<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Closure;

/** A single request's shared remote-wait allowance. */
final class WaitBudget
{
    private float $spentMilliseconds = 0;

    private readonly Closure $clock;

    public function __construct(
        private readonly int $milliseconds,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000;
    }

    /**
     * The callback receives the remaining allowance in fractional seconds.
     * A null result means the operation was not started.
     */
    public function run(callable $operation): mixed
    {
        $remaining = max(0, $this->milliseconds - $this->spentMilliseconds);
        if ($remaining < 1) {
            return null;
        }

        $started = ($this->clock)();
        try {
            return $operation($remaining / 1000);
        } finally {
            $this->spentMilliseconds += max(0, ($this->clock)() - $started);
        }
    }
}
