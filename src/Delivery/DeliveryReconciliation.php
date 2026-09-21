<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Throwable;

/** A durable, bounded list of known content, not a remote discovery protocol. */
final class DeliveryReconciliation
{
    private const FORMAT = 3;
    private readonly Closure $clock;
    private readonly Closure $monotonicClock;
    private readonly DeliveryNotificationHealth $notifications;
    private readonly DeliveryLocalStore $local;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        ?Closure $clock = null,
        private readonly int $maxItems = 500,
        ?DeliveryNotificationHealth $notifications = null,
        ?DeliveryLocalStore $local = null,
        ?Closure $monotonicClock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
        $this->monotonicClock = $monotonicClock ?? static fn (): float => hrtime(true) / 1_000_000;
        $this->notifications = $notifications ?? new DeliveryNotificationHealth($cache, $config, $this->clock);
        $this->local = $local ?? new DeliveryLocalStore((string) $config->get('smking.delivery.local_store_path', storage_path('app/smking-delivery')));
    }

    public function scheduledMinute(): int
    {
        // Spread starts, but retain at least 50 minutes for bounded continuations.
        return (int) (hexdec(substr(hash('sha256', $this->site()), 0, 8)) % 10);
    }

    /** Enrol validated content before committing it, so a failed enrolment is visible. */
    public function remember(DeliverySnapshot $snapshot, bool $imported = false): bool
    {
        if (($snapshot->payload['status'] ?? null) !== 'ready'
            || DeliveryIdentifier::parameters($snapshot->resource, $snapshot->identifier) === null
            || ! $this->supported()
        ) return false;
        try {
            return $this->locked(function () use ($snapshot, $imported): bool {
                $record = $this->load();
                $key = $this->itemKey($snapshot->resource, $snapshot->identifier);
                if (isset($record['items'][$key])) return true;
                if (count($record['items']) >= $this->maxItems) {
                    $record['overflowed_at'] = ($this->clock)();
                    $this->save($record);
                    return false;
                }
                $record['items'][$key] = [
                    'resource' => $snapshot->resource, 'identifier' => $snapshot->identifier,
                    // Importing bytes is not a new remote check.
                    'last_checked_on' => $this->localTime($imported ? $snapshot->validatedAtMs : ($this->clock)())->format('Y-m-d'),
                    'last_success_at' => $snapshot->validatedAtMs,
                    'last_attempt_at' => ($this->clock)(),
                ];
                $record['overflowed_at'] = null;
                $this->save($record);
                return true;
            }) === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function forget(string $resource, string $identifier): bool
    {
        if (DeliveryIdentifier::parameters($resource, $identifier) === null || ! $this->supported()) return false;
        try {
            return $this->locked(function () use ($resource, $identifier): bool {
                $record = $this->load();
                if (! $record['initialized']) return true;
                unset($record['items'][$this->itemKey($resource, $identifier)]);
                $this->save($record);
                return true;
            }) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Local-only per-identifier readiness; a completed sweep is not required. */
    public function registered(string $resource, string $identifier): bool
    {
        if (! $this->supported() || DeliveryIdentifier::parameters($resource, $identifier) === null) return false;
        try {
            $record = $this->load();
            return $record['initialized'] && ! $record['upgrade_incomplete']
                && $record['overflowed_at'] === null && count($record['items']) <= $this->maxItems
                && isset($record['items'][$this->itemKey($resource, $identifier)]);
        } catch (Throwable) {
            return false;
        }
    }

    /** Explicit bounded upgrade from the old cache index; no HTTP or cache deletion. */
    public function importLegacy(OnDemandDelivery $delivery): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'error' => null];
        if ($this->config->get('smking.delivery.mode', 'legacy') !== 'legacy' || ! $this->supported()) {
            return array_replace($result, ['error' => 'configuration']);
        }
        try {
            $this->load(); // Never replace a lost durable index with a stale backup.
            $legacy = $this->cache->get($this->key());
            if (! is_array($legacy) || ($legacy['format'] ?? null) !== 2 || ! is_array($legacy['items'] ?? null)
                || count($legacy['items']) > $this->maxItems || count($legacy['items']) > 1000
            ) throw new RuntimeException('legacy_index_unavailable');
            foreach ($legacy['items'] as $key => $item) {
                if (! $this->validItem($key, $item)) throw new RuntimeException('legacy_index_invalid');
            }
            $this->mutate(function (array &$record): void { $record['upgrade_incomplete'] = true; });
            foreach ($legacy['items'] as $item) {
                $snapshot = $delivery->peek($item['resource'], $item['identifier'])->snapshot;
                if ($snapshot === null || ($snapshot->payload['status'] ?? null) !== 'ready') {
                    $result['skipped']++;
                    continue;
                }
                if (! $this->remember($snapshot, imported: true)) throw new RuntimeException('registry_write_failed');
                $result['imported']++;
            }
            if ($result['skipped'] > 0) $result['error'] = 'legacy_content_not_ready';
            if ($result['error'] === null) {
                $this->mutate(function (array &$record): void { $record['upgrade_incomplete'] = false; });
            }
        } catch (Throwable $error) {
            $result['error'] = in_array($error->getMessage(), ['legacy_index_unavailable', 'legacy_index_invalid', 'registry_missing'], true)
                ? $error->getMessage() : 'registry_unavailable';
        }
        return $result;
    }

    /** Local-only observation; neither initializes state nor grants completion. */
    public function status(): array
    {
        $base = ['available' => false, 'known' => null, 'eligible' => null, 'pending' => null,
            'checked' => 0, 'refreshed' => 0, 'failed' => 0, 'in_flight' => false, 'complete' => false,
            'scheduled_minute' => $this->scheduledMinute(), 'last_run_at' => null, 'round_date' => null,
            'last_completed_at' => null, 'last_completed_on' => null, 'last_incomplete_on' => null];
        try {
            if (! $this->supported()) throw new RuntimeException('configuration_or_storage');
            return $this->describe($this->load());
        } catch (Throwable $error) {
            return $base + ['error' => $error->getMessage() === 'registry_missing' ? 'registry_missing' : 'reconciliation_unavailable'];
        }
    }

    /** One serialized, time-bounded slice of the same local-calendar-day round. */
    public function runBatch(OnDemandDelivery $delivery, int $maxJobs, int $budgetMs): array
    {
        $summary = ['eligible' => false, 'checked' => 0, 'refreshed' => 0, 'failed' => 0, 'pending' => null, 'complete' => false, 'error' => null];
        if ($this->config->get('smking.delivery.mode', 'legacy') !== 'on_demand') return array_replace($summary, ['error' => 'mode_disabled']);
        if ($maxJobs < 1 || $maxJobs > 20 || $budgetMs < 1 || $budgetMs > 10_000 || ! $this->supported()) {
            return array_replace($summary, ['error' => 'configuration_or_storage']);
        }
        try {
            if (! $this->inWindow()) return array_replace($summary, ['error' => 'outside_window']);
            $summary['eligible'] = true;
            // Separate from short registry/content locks. Held through bounded
            // HTTP; flock releases after process death, even after cache:clear.
            $result = $this->local->locked($this->key().':runner', function () use ($delivery, $maxJobs, $budgetMs, &$summary): array {
                $deadline = ($this->monotonicClock)() + $budgetMs;
                $date = $this->localNow()->format('Y-m-d');
                $this->mutate(function (array &$record) use ($date): void {
                    if (! $record['initialized']) throw new RuntimeException('registry_uninitialized');
                    // A dead process's attempt has unknown outcome: record failure,
                    // never repeat the same identifier on the same day.
                    if ($record['round']['in_flight'] !== null) {
                        $record['round']['failed']++;
                        $record['round']['in_flight'] = null;
                        $record['round']['complete'] = false;
                    }
                    if ($record['last_run_on'] !== $date) {
                        if ($record['last_run_on'] !== null && ! $record['round']['complete']) $record['last_incomplete_on'] = $record['last_run_on'];
                        $record['last_run_on'] = $date;
                        $record['round'] = $this->emptyRound();
                    }
                    $record['last_run_at'] = ($this->clock)();
                });
                for ($index = 0; $index < $maxJobs; $index++) {
                    $remaining = (int) floor($deadline - ($this->monotonicClock)());
                    if ($remaining < 1 || ! $this->inWindow() || $this->localNow()->format('Y-m-d') !== $date) break;
                    $item = $this->claim($date);
                    if ($item === null) break;
                    $remaining = (int) floor($deadline - ($this->monotonicClock)());
                    if ($remaining < 1 || ! $this->inWindow() || $this->localNow()->format('Y-m-d') !== $date) {
                        $this->releaseUnstarted($item);
                        break;
                    }
                    $result = $delivery->refresh($item['resource'], $item['identifier'], new WaitBudget($remaining, $this->monotonicClock));
                    if (in_array($result->error, ['capacity', 'backoff', 'budget_exhausted'], true)) {
                        // These outcomes prove no HTTP started. Retain this
                        // identifier for the next bounded scheduler slice.
                        $this->releaseUnstarted($item);
                        $summary['error'] = $result->error;
                        break;
                    }
                    $summary['checked']++;
                    $ok = $result->error === null && $result->snapshot !== null;
                    $summary[$ok ? 'refreshed' : 'failed']++;
                    $this->mutate(function (array &$record) use ($ok, $item): void {
                        if ($record['round']['in_flight'] !== $item['token']) throw new RuntimeException('reconciliation_superseded');
                        $record['round'][$ok ? 'refreshed' : 'failed']++;
                        $record['round']['in_flight'] = null;
                        $key = $this->itemKey($item['resource'], $item['identifier']);
                        if ($ok && isset($record['items'][$key])) $record['items'][$key]['last_success_at'] = ($this->clock)();
                    });
                }
                $this->mutate(function (array &$record) use ($date): void {
                    $state = $this->describe($record);
                    $previouslyComplete = $record['round']['complete'];
                    $record['round']['complete'] = $state['pending'] === 0 && $record['round']['failed'] === 0
                        && $record['round']['in_flight'] === null && $record['overflowed_at'] === null
                        && ! $record['upgrade_incomplete'] && count($record['items']) <= $this->maxItems;
                    if ($record['round']['complete'] && ! $previouslyComplete) {
                        $record['last_completed_at'] = ($this->clock)();
                        $record['last_completed_on'] = $date;
                    }
                });
                $state = $this->status();
                $summary['pending'] = $state['pending'];
                $summary['complete'] = $state['complete'];
                $summary['error'] = $state['error'] ?? $summary['error'];
                if ($summary['checked'] === 0 && $summary['complete']) $summary['error'] = 'already_ran';
                return $summary;
            });
            return $result ?? array_replace($summary, ['error' => 'runner_busy']);
        } catch (Throwable $error) {
            return array_replace($summary, ['error' => in_array($error->getMessage(), ['registry_missing', 'registry_uninitialized', 'registry_busy'], true)
                ? $error->getMessage() : 'reconciliation_unavailable']);
        }
    }

    private function claim(string $date): ?array
    {
        $claimed = null;
        $this->mutate(function (array &$record) use ($date, &$claimed): void {
            $items = $record['items'];
            uasort($items, static fn ($left, $right) => [$left['last_checked_on'], $left['last_attempt_at']]
                <=> [$right['last_checked_on'], $right['last_attempt_at']]);
            foreach ($items as $key => $item) {
                if ($item['last_checked_on'] >= $date || ! $this->eligible($item)) continue;
                $token = bin2hex(random_bytes(16));
                $record['items'][$key]['last_checked_on'] = $date;
                $record['items'][$key]['last_attempt_at'] = ($this->clock)();
                $record['round']['checked']++;
                $record['round']['in_flight'] = $token;
                $record['round']['complete'] = false;
                $claimed = $item + ['token' => $token];
                break;
            }
        });
        return $claimed;
    }

    private function releaseUnstarted(array $item): void
    {
        $this->mutate(function (array &$record) use ($item): void {
            if ($record['round']['in_flight'] !== $item['token']) throw new RuntimeException('reconciliation_superseded');
            $key = $this->itemKey($item['resource'], $item['identifier']);
            if (isset($record['items'][$key])) {
                $record['items'][$key]['last_checked_on'] = $item['last_checked_on'];
                $record['items'][$key]['last_attempt_at'] = $item['last_attempt_at'];
            }
            $record['round']['checked']--;
            $record['round']['in_flight'] = null;
        });
    }

    private function eligible(array $item): bool
    {
        return ($item['resource'] === 'cms-page' || $this->config->get('smking.delivery.aeo_enabled', true) === true)
            && ! $this->notifications->status($item['resource'])['available'];
    }

    private function describe(array $record): array
    {
        $date = $this->localNow()->format('Y-m-d');
        $eligible = $pending = 0;
        foreach ($record['items'] as $item) {
            if (! $this->eligible($item)) continue;
            $eligible++;
            if ($item['last_checked_on'] < $date) $pending++;
        }
        $complete = $record['initialized'] && $record['last_run_on'] === $date && $record['round']['complete']
            && $pending === 0 && $record['overflowed_at'] === null && ! $record['upgrade_incomplete'] && count($record['items']) <= $this->maxItems;
        $error = match (true) {
            ! $record['initialized'] => 'registry_uninitialized',
            $record['upgrade_incomplete'] => 'legacy_content_not_ready',
            $record['overflowed_at'] !== null || count($record['items']) > $this->maxItems => 'registry_full',
            $record['round']['in_flight'] !== null => 'attempt_incomplete',
            $record['last_run_on'] === $date && $record['round']['failed'] > 0 => 'refresh_failed',
            $pending > 0 && (int) $this->localNow()->format('H') >= 4 => 'window_incomplete',
            default => null,
        };
        return ['available' => $record['initialized'], 'known' => count($record['items']), 'eligible' => $eligible,
            'pending' => $pending, 'checked' => $record['round']['checked'], 'refreshed' => $record['round']['refreshed'],
            'failed' => $record['round']['failed'], 'in_flight' => $record['round']['in_flight'] !== null, 'complete' => $complete,
            'scheduled_minute' => $this->scheduledMinute(), 'last_run_at' => $record['last_run_at'],
            'round_date' => $record['last_run_on'], 'last_completed_at' => $record['last_completed_at'],
            'last_completed_on' => $record['last_completed_on'], 'last_incomplete_on' => $record['last_incomplete_on'], 'error' => $error];
    }

    private function emptyRound(): array
    {
        return ['checked' => 0, 'refreshed' => 0, 'failed' => 0, 'in_flight' => null, 'complete' => false];
    }

    private function load(): array
    {
        $record = $this->local->read($this->key());
        if ($record === null) {
            if ($this->local->read($this->key().':initialized') !== null) throw new RuntimeException('registry_missing');
            return ['format' => self::FORMAT, 'initialized' => false, 'last_run_at' => null, 'last_run_on' => null,
                'last_completed_at' => null, 'last_completed_on' => null, 'last_incomplete_on' => null,
                'overflowed_at' => null, 'upgrade_incomplete' => false, 'round' => $this->emptyRound(), 'items' => []];
        }
        if (($record['format'] ?? null) !== self::FORMAT || ($record['initialized'] ?? null) !== true
            || ! is_bool($record['upgrade_incomplete'] ?? null)
            || ! is_array($record['items'] ?? null) || count($record['items']) > 1000 || ! is_array($record['round'] ?? null)
        ) throw new RuntimeException('reconciliation_invalid');
        foreach (['last_run_at', 'last_completed_at', 'overflowed_at'] as $field) {
            if (! array_key_exists($field, $record) || ($record[$field] !== null && (! is_int($record[$field]) || $record[$field] < 0))) throw new RuntimeException('reconciliation_invalid');
        }
        foreach (['last_run_on', 'last_completed_on', 'last_incomplete_on'] as $field) {
            if (! array_key_exists($field, $record) || ($record[$field] !== null && ! $this->validDate($record[$field]))) throw new RuntimeException('reconciliation_invalid');
        }
        foreach (['checked', 'refreshed', 'failed'] as $field) {
            if (! is_int($record['round'][$field] ?? null) || $record['round'][$field] < 0) throw new RuntimeException('reconciliation_invalid');
        }
        if (! is_bool($record['round']['complete'] ?? null) || ! array_key_exists('in_flight', $record['round'])
            || ($record['round']['in_flight'] !== null && (! is_string($record['round']['in_flight']) || preg_match('/^[a-f0-9]{32}$/D', $record['round']['in_flight']) !== 1))
        ) throw new RuntimeException('reconciliation_invalid');
        if ($record['round']['checked'] !== $record['round']['refreshed'] + $record['round']['failed'] + ($record['round']['in_flight'] === null ? 0 : 1)) {
            throw new RuntimeException('reconciliation_invalid');
        }
        foreach ($record['items'] as $key => $item) {
            if (! $this->validItem($key, $item)) throw new RuntimeException('reconciliation_invalid');
        }
        return $record;
    }

    private function validItem(mixed $key, mixed $item): bool
    {
        return is_array($item) && is_string($item['resource'] ?? null) && is_string($item['identifier'] ?? null)
            && $key === $this->itemKey($item['resource'], $item['identifier'])
            && DeliveryIdentifier::parameters($item['resource'], $item['identifier']) !== null
            && $this->validDate($item['last_checked_on'] ?? null)
            && is_int($item['last_success_at'] ?? null) && $item['last_success_at'] >= 0
            && is_int($item['last_attempt_at'] ?? null) && $item['last_attempt_at'] >= 0;
    }

    private function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1;
    }

    private function save(array $record): void
    {
        $record['initialized'] = true;
        if (! $this->local->write($this->key(), $record)) throw new RuntimeException('reconciliation_write_failed');
        if ($this->local->read($this->key().':initialized') === null
            && ! $this->local->write($this->key().':initialized', ['format' => 1])
        ) throw new RuntimeException('reconciliation_write_failed');
    }

    private function mutate(callable $operation): void
    {
        if ($this->locked(function () use ($operation): bool {
            $record = $this->load();
            $operation($record);
            $this->save($record);
            return true;
        }) !== true) throw new RuntimeException('registry_busy');
    }

    private function inWindow(): bool
    {
        $now = $this->localNow();
        return $now->format('H') === '03' && (int) $now->format('i') >= $this->scheduledMinute();
    }

    private function localNow(): DateTimeImmutable
    {
        return $this->localTime(($this->clock)());
    }

    private function localTime(int $milliseconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))
            ->setTimezone(new DateTimeZone((string) $this->config->get('app.timezone', 'UTC')));
    }

    private function site(): string
    {
        return (string) $this->config->get('smking.api_key').'|'.rtrim((string) $this->config->get('smking.base_url'), '/');
    }

    private function key(): string
    {
        return 'smking:delivery:v2:reconciliation:'.substr(hash('sha256', $this->site()), 0, 24);
    }

    private function itemKey(string $resource, string $identifier): string
    {
        return hash('sha256', $resource.'|'.$identifier);
    }

    private function supported(): bool
    {
        return $this->config->get('smking.cache.enabled', true) === true && $this->maxItems >= 1 && $this->maxItems <= 1000;
    }

    private function locked(callable $operation): mixed
    {
        return $this->local->locked($this->key(), $operation);
    }
}
