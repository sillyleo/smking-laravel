<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Smking\Laravel\Delivery\DeliveryReconciliation;
use Smking\Laravel\Delivery\OnDemandDelivery;

/** One bounded slice of the daily round, or an explicit local index upgrade. */
final class DeliveryReconcileCommand extends Command
{
    protected $signature = 'smking:delivery:reconcile {--status : Read reconciliation state without writes or HTTP} {--import-index : Import the old cache index after local content has been imported, without HTTP}';

    protected $description = 'Revalidate known smking content once per day without visitor traffic';

    public function handle(DeliveryReconciliation $reconciliation, OnDemandDelivery $delivery): int
    {
        if ($this->option('status') && $this->option('import-index')) return self::FAILURE;
        if ($this->option('import-index')) {
            $result = $reconciliation->importLegacy($delivery);
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return $result['error'] === null ? self::SUCCESS : self::FAILURE;
        }
        if ($this->option('status')) {
            $status = $reconciliation->status();
            $this->line(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $status['error'] === null ? self::SUCCESS : self::FAILURE;
        }

        $maxJobs = $this->integerConfig('work_max_jobs', 10, 1, 20);
        $budget = $this->integerConfig('work_budget_ms', 5000, 1, 10_000);
        if ($maxJobs === null || $budget === null) {
            return self::FAILURE;
        }
        $summary = $reconciliation->runBatch($delivery, $maxJobs, $budget);
        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return in_array($summary['error'], [null, 'already_ran', 'mode_disabled', 'outside_window'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function integerConfig(string $name, int $default, int $minimum, int $maximum): ?int
    {
        $value = config('smking.delivery.'.$name, $default);
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $minimum && $value <= $maximum ? $value : null;
    }
}
