<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Smking\Laravel\Delivery\DeliveryWorklist;
use Smking\Laravel\Delivery\OnDemandDelivery;

/** One bounded worker tick; it does not install or start a scheduler. */
final class DeliveryWorkCommand extends Command
{
    protected $signature = 'smking:delivery:work
        {--prepare : Prepare local worker health without enabling visitor mode}
        {--status : Read local worker health without renewing it}';

    protected $description = 'Process one bounded batch of smking content refresh work';

    public function handle(DeliveryWorklist $worklist, OnDemandDelivery $delivery): int
    {
        if ($this->option('prepare') && $this->option('status')) {
            $this->error('--prepare 與 --status 不可同時使用。');

            return self::INVALID;
        }
        if ($this->option('status')) {
            $status = $worklist->status();
            $this->line(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $status['attention_required'] ? self::FAILURE : self::SUCCESS;
        }
        if ($this->option('prepare')) {
            return $worklist->prepare() ? self::SUCCESS : self::FAILURE;
        }
        if (config('smking.delivery.mode', 'legacy') !== 'on_demand') {
            $this->error('按需背景處理尚未啟用。');

            return self::FAILURE;
        }

        $maxJobs = $this->integerConfig('work_max_jobs', 10, 1, 20);
        $budget = $this->integerConfig('work_budget_ms', 5000, 1, 10_000);
        if ($maxJobs === null || $budget === null) {
            return self::FAILURE;
        }
        $summary = $worklist->runBatch($delivery, $maxJobs, $budget);
        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $summary['error'] === null ? self::SUCCESS : self::FAILURE;
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
