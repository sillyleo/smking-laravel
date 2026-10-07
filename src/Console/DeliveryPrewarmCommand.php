<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Smking\Laravel\Delivery\DeliveryIdentifier;
use Smking\Laravel\Delivery\DeliveryPreparationPlan;
use Smking\Laravel\Delivery\DeliveryReportOutbox;
use Smking\Laravel\Delivery\DeliveryReconciliation;
use Smking\Laravel\Delivery\DeliveryTargetState;
use Smking\Laravel\Delivery\DeliveryWorklist;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;
use Throwable;

/** Explicit bounded preparation of trusted published identifiers; no discovery. */
final class DeliveryPrewarmCommand extends Command
{
    protected $signature = 'smking:delivery:prewarm
        {--slug=* : Explicit published CMS slugs; an empty value selects the root}
        {--resource= : cms-page, aeo, markdown or site-file; use with --identifier}
        {--identifier=* : Explicit published identifiers; do not combine with --slug}
        {--plan= : Absolute path to a trusted finite release checklist with expected publications}
        {--offset= : Start a bounded preparation batch at this plan index; not valid with --check}
        {--check : Check local content, index and background readiness without HTTP or writes}';

    protected $description = 'Prepare or check one bounded batch of published local content';

    public function handle(
        ConfigRepository $config,
        OnDemandDelivery $delivery,
        DeliveryWorklist $worklist,
        DeliveryReportOutbox $reports,
        DeliveryReconciliation $reconciliation,
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
            if ($this->option('plan') !== null) {
                return $this->preparePlan($config, $delivery, $worklist, $reports, $reconciliation, $targets);
            }
            $selection = $this->selection();
            $maxJobs = $this->integer($config, 'work_max_jobs', 10, 1, 20);
            $budgetMs = $this->integer($config, 'work_budget_ms', 5000, 1, 10_000);
            if ($this->option('offset') !== null || $selection === null
                || $maxJobs === null
                || $budgetMs === null
                || count($selection['identifiers']) > $maxJobs
            ) {
                $summary['error'] = 'invalid_input';

                return $this->finish($summary);
            }
            $resource = $selection['resource'];
            $identifiers = array_values(array_unique($selection['identifiers'], SORT_STRING));
            $summary['requested'] = count($identifiers);

            if (! in_array($config->get('smking.delivery.mode', 'legacy'), ['legacy', 'on_demand'], true)) {
                $summary['error'] = 'configuration';
                return $this->finish($summary);
            }

            if (! $this->backgroundReady($worklist, $reports, recoverExhausted: ! $this->option('check'))) {
                $summary['error'] = 'background_unavailable';

                return $this->finish($summary);
            }
            if (! $this->option('check')) {
                $budget = new WaitBudget($budgetMs);
                $deadline = hrtime(true) + $budgetMs * 1_000_000;
                foreach ($identifiers as $identifier) {
                    if (hrtime(true) >= $deadline) {
                        $summary['error'] = 'budget_exhausted';
                        break;
                    }
                    $result = $delivery->prepare($resource, $identifier, $budget);
                    $summary['processed']++;
                    if ($result->snapshot === null
                        || $result->httpStatus !== 200
                        || $result->error !== null
                    ) {
                        $summary['error'] = $result->error ?? 'content_not_ready';
                        break;
                    }
                }
            }

            foreach ($identifiers as $identifier) {
                $result = $delivery->peek($resource, $identifier);
                $snapshot = $result->snapshot;
                $registered = $reconciliation->registered($resource, $identifier);
                $ready = $snapshot !== null
                    && $result->httpStatus === 200
                    && $result->error === null
                    && $registered;
                if ($ready) {
                    $summary['ready']++;
                }
                $summary['items'][] = [
                    ...($resource === 'cms-page' ? ['slug' => substr($identifier, 5)] : []),
                    'resource' => $resource,
                    'identifier' => $identifier,
                    'state' => $ready ? 'ready' : ($result->error ?? ($registered ? 'unavailable' : 'registry_not_ready')),
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

    private function preparePlan(
        ConfigRepository $config,
        OnDemandDelivery $delivery,
        DeliveryWorklist $worklist,
        DeliveryReportOutbox $reports,
        DeliveryReconciliation $reconciliation,
        DeliveryTargetState $targets,
    ): int {
        $summary = ['requested' => 0, 'processed' => 0, 'ready' => 0, 'items' => [],
            'verification' => $this->option('check') ? 'local_only' : 'source_batch_and_local_list',
            'plan_hash' => null, 'next_offset' => null, 'complete' => false, 'error' => null];
        try {
            $maxJobs = $this->integer($config, 'work_max_jobs', 10, 1, 20);
            $budgetMs = $this->integer($config, 'work_budget_ms', 5000, 1, 10_000);
            $offset = $this->option('offset');
            if ($this->option('slug') !== [] || $this->option('resource') !== null || $this->option('identifier') !== []
                || $maxJobs === null || $budgetMs === null
                || ($offset !== null && ($this->option('check') || ! is_string($offset) || ! ctype_digit($offset) || strlen($offset) > 4))
            ) throw new \RuntimeException('invalid_preparation_plan');
            $plan = DeliveryPreparationPlan::load($this->option('plan'), $config);
            $offset = (int) ($offset ?? 0);
            if ($offset >= count($plan->targets)) throw new \RuntimeException('invalid_preparation_plan');
            $summary['plan_hash'] = $plan->hash;
            $summary['requested'] = count($plan->targets);
            if (! in_array($config->get('smking.delivery.mode', 'legacy'), ['legacy', 'on_demand'], true)) {
                $summary['error'] = 'configuration';
                return $this->finish($summary);
            }
            if (! $this->backgroundReady($worklist, $reports)) {
                $summary['error'] = 'background_unavailable';
                return $this->finish($summary);
            }
            if (! $this->option('check')) {
                $budget = new WaitBudget($budgetMs);
                $deadline = hrtime(true) + $budgetMs * 1_000_000;
                foreach (array_slice($plan->targets, $offset, $maxJobs) as $expected) {
                    if (hrtime(true) >= $deadline) {
                        $summary['error'] ??= 'budget_exhausted';
                        break;
                    }
                    $result = $delivery->prepare($expected['resource'], $expected['identifier'], $budget, $expected);
                    $summary['processed']++;
                    if ($result->error !== null && ! ($expected['action'] === 'withdraw' && $result->error === 'withdrawn')) {
                        $summary['error'] ??= $result->error;
                    }
                }
                $next = $offset + $summary['processed'];
                $summary['next_offset'] = $next < count($plan->targets) ? $next : null;
            }
            // Always inspect the entire supplied list, never just this download batch.
            foreach ($plan->targets as $expected) {
                $resource = $expected['resource'];
                $identifier = $expected['identifier'];
                $result = $delivery->peek($resource, $identifier);
                $current = $targets->read($identifier, $resource)['target'] ?? null;
                $publication = $result->snapshot?->payload['delivery']['publication'] ?? null;
                $ready = $current === $expected && ($expected['action'] === 'withdraw'
                    ? $result->httpStatus === 404 && $result->error === 'withdrawn'
                    : $result->httpStatus === 200 && $result->error === null
                        && is_array($publication) && DeliveryTargetState::normalize($publication) === $expected
                        && $reconciliation->registered($resource, $identifier));
                $summary['ready'] += (int) $ready;
                $summary['items'][] = ['resource' => $resource, 'identifier' => $identifier,
                    'state' => $ready ? ($expected['action'] === 'withdraw' ? 'withdrawn' : 'ready') : ($result->error ?? 'publication_mismatch'),
                    'ready' => $ready, 'expected' => $expected, 'publication' => $current];
            }
            $summary['complete'] = $summary['error'] === null && $summary['ready'] === $summary['requested'];
            if (! $summary['complete']) $summary['error'] ??= 'release_not_ready';
        } catch (Throwable) {
            $summary['error'] = 'invalid_plan_or_storage';
        }
        return $this->finish($summary);
    }

    /** @return array{resource:string, identifiers:list<string>}|null */
    private function selection(): ?array
    {
        $slugs = $this->option('slug');
        $resource = $this->option('resource');
        $identifiers = $this->option('identifier');
        if (! is_array($slugs) || ! is_array($identifiers)) return null;
        if ($slugs !== []) {
            if ($resource !== null || $identifiers !== []) return null;
            $resource = 'cms-page';
            foreach ($slugs as $slug) {
                if (! is_string($slug)) return null;
                $identifiers[] = 'slug:'.$slug;
            }
        }
        if (! is_string($resource) || $identifiers === []) return null;
        foreach ($identifiers as $identifier) {
            if (! is_string($identifier)
                || ! mb_check_encoding($identifier, 'UTF-8')
                || DeliveryIdentifier::parameters($resource, $identifier) === null
            ) {
                return null;
            }
        }
        return ['resource' => $resource, 'identifiers' => $identifiers];
    }

    private function backgroundReady(DeliveryWorklist $worklist, DeliveryReportOutbox $reports, bool $recoverExhausted = false): bool
    {
        $work = $worklist->status();
        $report = $reports->status();

        return $work['error'] === null
            && $work['heartbeat_recent'] === true
            && ($work['attention_required'] === false
                || ($recoverExhausted && $work['counts']['expired'] === 0 && array_sum($work['dropped']) === 0))
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
}
