<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Smking\Laravel\Delivery\DeliveryIdentifier;
use Smking\Laravel\Delivery\DeliveryReportOutbox;
use Smking\Laravel\Delivery\DeliveryTargetState;
use Smking\Laravel\Delivery\DeliveryWorklist;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;
use Throwable;

/** Explicit bounded Blog preparation; never discovers pages or changes mode. */
final class DeliveryPrewarmCommand extends Command
{
    protected $signature = 'smking:delivery:prewarm
        {--slug=* : Explicit published CMS slugs; an empty value selects the root}
        {--check : Check cache and background readiness without HTTP or writes}';

    protected $description = 'Prepare one bounded batch of on-demand Blog cache entries';

    public function handle(
        ConfigRepository $config,
        OnDemandDelivery $delivery,
        DeliveryWorklist $worklist,
        DeliveryReportOutbox $reports,
        DeliveryTargetState $targets,
    ): int {
        $summary = [
            'requested' => 0,
            'processed' => 0,
            'ready' => 0,
            'items' => [],
            'error' => null,
        ];

        try {
            $slugs = $this->option('slug');
            $maxJobs = $this->integer($config, 'work_max_jobs', 10, 1, 20);
            $budgetMs = $this->integer($config, 'work_budget_ms', 5000, 1, 10_000);
            if (! is_array($slugs)
                || $slugs === []
                || $maxJobs === null
                || $budgetMs === null
                || count($slugs) > $maxJobs
                || ! $this->validSlugs($slugs)
            ) {
                $summary['error'] = 'invalid_input';

                return $this->finish($summary);
            }
            $slugs = array_values(array_unique($slugs, SORT_STRING));
            $summary['requested'] = count($slugs);

            if (! $targets->available() || ! $this->backgroundReady($worklist, $reports)) {
                $summary['error'] = 'background_unavailable';

                return $this->finish($summary);
            }
            if (! $this->option('check')) {
                if ($config->get('smking.delivery.mode', 'legacy') !== 'legacy') {
                    $summary['error'] = 'mode_not_legacy';

                    return $this->finish($summary);
                }
                $budget = new WaitBudget($budgetMs);
                $deadline = hrtime(true) + $budgetMs * 1_000_000;
                foreach ($slugs as $slug) {
                    if (hrtime(true) >= $deadline) {
                        $summary['error'] = 'budget_exhausted';
                        break;
                    }
                    $result = $delivery->refresh('cms-page', 'slug:'.$slug, $budget);
                    $summary['processed']++;
                    if ($result->snapshot === null
                        || $result->httpStatus !== 200
                        || ! $result->snapshot->isFresh($this->now())
                    ) {
                        $summary['error'] = $result->error ?? 'content_not_ready';
                        break;
                    }
                }
            }

            foreach ($slugs as $slug) {
                $result = $delivery->peek('cms-page', 'slug:'.$slug);
                $snapshot = $result->snapshot;
                $ready = $snapshot !== null
                    && $result->httpStatus === 200
                    && $snapshot->isFresh($this->now());
                if ($ready) {
                    $summary['ready']++;
                }
                $summary['items'][] = [
                    'slug' => $slug,
                    'state' => $ready ? 'ready' : ($result->error ?? 'unavailable'),
                    'content_version' => $snapshot?->payload['delivery']['content_version'] ?? null,
                    'fresh_until' => $snapshot?->freshUntilMs,
                    'usable_until' => $snapshot?->usableUntilMs,
                ];
            }
            if ($summary['ready'] !== $summary['requested']) {
                $summary['error'] ??= 'cache_not_ready';
            }
        } catch (Throwable) {
            $summary['error'] = 'configuration_or_storage';
        }

        return $this->finish($summary);
    }

    /** @param list<mixed> $slugs */
    private function validSlugs(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if (! is_string($slug)
                || ! mb_check_encoding($slug, 'UTF-8')
                || DeliveryIdentifier::parameters('cms-page', 'slug:'.$slug) === null
            ) {
                return false;
            }
        }

        return true;
    }

    private function backgroundReady(DeliveryWorklist $worklist, DeliveryReportOutbox $reports): bool
    {
        $work = $worklist->status();
        $report = $reports->status();

        return $work['error'] === null
            && $work['heartbeat_recent'] === true
            && $work['attention_required'] === false
            && $report['error'] === null
            && $report['heartbeat_recent'] === true
            && $report['last_error'] === null
            && array_sum($report['losses']) === 0;
    }

    private function integer(
        ConfigRepository $config,
        string $name,
        int $default,
        int $minimum,
        int $maximum,
    ): ?int {
        $value = $config->get('smking.delivery.'.$name, $default);
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $minimum && $value <= $maximum ? $value : null;
    }

    /** @param array<string, mixed> $summary */
    private function finish(array $summary): int
    {
        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $summary['error'] === null ? self::SUCCESS : self::FAILURE;
    }

    private function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
