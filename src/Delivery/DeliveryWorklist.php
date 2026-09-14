<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Closure;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Throwable;

/** A bounded local refresh list processed only by one-shot CLI ticks. */
final class DeliveryWorklist
{
    private const FORMAT = 1;

    private const MAX_ATTEMPTS = 3;

    private const ITEM_LIFETIME_MS = 3_600_000;

    private const CLAIM_LIFETIME_MS = 30_000;

    private const RETENTION_SECONDS = 604_800;

    private readonly Closure $clock;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        ?Closure $clock = null,
        private readonly int $maxItems = 100,
        private readonly int $heartbeatSeconds = 180,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    public function prepare(): bool
    {
        if (! $this->supported()) {
            return false;
        }

        try {
            return $this->locked(function (): bool {
                $record = $this->load();
                $record['heartbeat'] = ($this->clock)();
                $this->save($record);

                return true;
            }) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Deduplication never renews an existing item's lifetime or retries. */
    public function schedule(string $resource, string $identifier): bool
    {
        if (DeliveryIdentifier::parameters($resource, $identifier) === null || ! $this->supported()) {
            return false;
        }

        try {
            return $this->locked(function () use ($resource, $identifier): bool {
                $record = $this->load();
                $now = ($this->clock)();
                $this->expire($record, $now);
                if (! $this->heartbeatRecent($record, $now)) {
                    if (($record['dropped']['background_unavailable'] ?? 0) === 0) {
                        $this->drop($record, 'background_unavailable');
                        $this->save($record);
                    }

                    return false;
                }

                $key = hash('sha256', $resource.'|'.$identifier);
                if (isset($record['items'][$key])) {
                    return $record['items'][$key]['attempts'] < self::MAX_ATTEMPTS;
                }
                if (count($record['items']) >= $this->maxItems) {
                    $this->drop($record, 'overflow');
                    $this->save($record);

                    return false;
                }

                $record['items'][$key] = [
                    'id' => bin2hex(random_bytes(16)),
                    'resource' => $resource,
                    'identifier' => $identifier,
                    'attempts' => 0,
                    'created_at' => $now,
                    'expires_at' => $now + self::ITEM_LIFETIME_MS,
                    'next_at' => $now,
                    'claim' => null,
                    'lease_until' => 0,
                    'last_error' => null,
                ];
                $this->save($record);

                return true;
            }) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{claimed:int,completed:int,deferred:int,error:?string} */
    public function runBatch(OnDemandDelivery $delivery, int $maxJobs, int $budgetMs): array
    {
        $summary = ['claimed' => 0, 'completed' => 0, 'deferred' => 0, 'error' => null];
        if (PHP_SAPI !== 'cli'
            || $maxJobs < 1
            || $maxJobs > 20
            || $budgetMs < 1
            || $budgetMs > 10_000
            || ! $this->prepare()
        ) {
            $summary['error'] = 'configuration_or_storage';

            return $summary;
        }

        $budget = new WaitBudget($budgetMs);
        $deadline = hrtime(true) + $budgetMs * 1_000_000;
        try {
            for ($index = 0; $index < $maxJobs && hrtime(true) < $deadline; $index++) {
                $item = $this->claim();
                if ($item === false) {
                    break;
                }
                if ($item === null) {
                    throw new RuntimeException('delivery_worklist_busy');
                }
                $summary['claimed']++;
                $result = $delivery->refresh($item['resource'], $item['identifier'], $budget);
                $complete = $result->snapshot !== null
                    || in_array($result->error, ['access_denied', 'disabled', 'invalid_identifier', 'withdrawn'], true);
                if (! $this->finish($item, $complete ? null : ($result->error ?? 'unknown'))) {
                    throw new RuntimeException('delivery_work_ack_failed');
                }
                $summary[$complete ? 'completed' : 'deferred']++;
                if ($result->error === 'budget_exhausted') {
                    break;
                }
            }
            if ($summary['deferred'] > 0) {
                $summary['error'] = 'work_deferred';
            }
        } catch (Throwable) {
            $summary['error'] = 'worklist_unavailable';
        }

        return $summary;
    }

    /** Read-only health; it never creates state or renews a heartbeat. */
    public function status(): array
    {
        try {
            if (! $this->supported()) {
                throw new RuntimeException('unsupported');
            }
            $record = $this->load();
            $now = ($this->clock)();
            $counts = ['pending' => 0, 'waiting' => 0, 'active' => 0, 'exhausted' => 0, 'expired' => 0];
            foreach ($record['items'] as $item) {
                $state = $item['expires_at'] <= $now
                    ? 'expired'
                    : ($item['lease_until'] > $now
                        ? 'active'
                        : ($item['attempts'] >= self::MAX_ATTEMPTS
                            ? 'exhausted'
                            : ($item['next_at'] > $now ? 'waiting' : 'pending')));
                $counts[$state]++;
            }

            return [
                'heartbeat_recent' => $this->heartbeatRecent($record, $now),
                'counts' => $counts,
                'dropped' => $record['dropped'],
                'attention_required' => ! $this->heartbeatRecent($record, $now)
                    || $counts['exhausted'] > 0
                    || $counts['expired'] > 0
                    || array_sum($record['dropped']) > 0,
                'error' => null,
            ];
        } catch (Throwable) {
            return [
                'heartbeat_recent' => false,
                'counts' => null,
                'dropped' => [],
                'attention_required' => true,
                'error' => 'worklist_unavailable',
            ];
        }
    }

    private function claim(): array|false|null
    {
        return $this->locked(function (): array|false {
            $record = $this->load();
            $now = ($this->clock)();
            $this->expire($record, $now);
            foreach ($record['items'] as $key => $item) {
                if ($item['attempts'] >= self::MAX_ATTEMPTS
                    || $item['next_at'] > $now
                    || $item['lease_until'] > $now
                ) {
                    continue;
                }
                $item['attempts']++;
                $item['claim'] = bin2hex(random_bytes(16));
                $item['lease_until'] = $now + self::CLAIM_LIFETIME_MS;
                $record['items'][$key] = $item;
                $this->save($record);

                return $item;
            }
            $this->save($record);

            return false;
        });
    }

    private function finish(array $claimed, ?string $error): bool
    {
        return $this->locked(function () use ($claimed, $error): bool {
            $record = $this->load();
            $key = hash('sha256', $claimed['resource'].'|'.$claimed['identifier']);
            $item = $record['items'][$key] ?? null;
            if (! is_array($item)
                || $item['id'] !== $claimed['id']
                || $item['claim'] !== $claimed['claim']
            ) {
                return true;
            }
            if ($error === null) {
                unset($record['items'][$key]);
            } else {
                $item['claim'] = null;
                $item['lease_until'] = 0;
                $item['last_error'] = $error;
                $delay = min(300, 30 * (2 ** max(0, $item['attempts'] - 1)));
                $item['next_at'] = ($this->clock)() + $delay * 1000;
                $record['items'][$key] = $item;
            }
            $this->save($record);

            return true;
        }) === true;
    }

    private function expire(array &$record, int $now): void
    {
        foreach ($record['items'] as $key => $item) {
            if ($item['expires_at'] <= $now) {
                unset($record['items'][$key]);
                $this->drop($record, 'expired');
            }
        }
    }

    private function heartbeatRecent(array $record, int $now): bool
    {
        return $record['heartbeat'] <= $now + 30_000
            && $record['heartbeat'] > $now - $this->heartbeatSeconds * 1000;
    }

    private function drop(array &$record, string $reason): void
    {
        $record['dropped'][$reason] = min(1_000_000, ($record['dropped'][$reason] ?? 0) + 1);
    }

    private function load(): array
    {
        $record = $this->cache->get($this->key());
        if ($record === null) {
            return ['format' => self::FORMAT, 'heartbeat' => 0, 'items' => [], 'dropped' => []];
        }
        if (! is_array($record)
            || ($record['format'] ?? null) !== self::FORMAT
            || ! is_int($record['heartbeat'] ?? null)
            || ! is_array($record['items'] ?? null)
            || count($record['items']) > $this->maxItems
            || ! is_array($record['dropped'] ?? null)
        ) {
            throw new RuntimeException('delivery_worklist_invalid');
        }
        foreach ($record['items'] as $key => $item) {
            if (! is_array($item)
                || $key !== hash('sha256', ($item['resource'] ?? '').'|'.($item['identifier'] ?? ''))
                || DeliveryIdentifier::parameters($item['resource'] ?? '', $item['identifier'] ?? '') === null
                || preg_match('/^[a-f0-9]{32}$/D', $item['id'] ?? '') !== 1
                || ! is_int($item['attempts'] ?? null)
                || $item['attempts'] < 0
                || $item['attempts'] > self::MAX_ATTEMPTS
                || ! is_int($item['created_at'] ?? null)
                || ! is_int($item['expires_at'] ?? null)
                || ! is_int($item['next_at'] ?? null)
                || ! is_int($item['lease_until'] ?? null)
                || ! array_key_exists('claim', $item)
                || ($item['claim'] !== null && preg_match('/^[a-f0-9]{32}$/D', $item['claim']) !== 1)
                || ! array_key_exists('last_error', $item)
                || ($item['last_error'] !== null && ! is_string($item['last_error']))
            ) {
                throw new RuntimeException('delivery_worklist_item_invalid');
            }
        }
        foreach ($record['dropped'] as $reason => $count) {
            if (! is_string($reason) || ! is_int($count) || $count < 0 || $count > 1_000_000) {
                throw new RuntimeException('delivery_worklist_diagnostics_invalid');
            }
        }

        return $record;
    }

    private function save(array $record): void
    {
        if ($this->cache->put($this->key(), $record, self::RETENTION_SECONDS) !== true) {
            throw new RuntimeException('delivery_worklist_write_failed');
        }
    }

    private function key(): string
    {
        return 'smking:delivery:v2:work:'.substr(hash('sha256',
            (string) $this->config->get('smking.api_key').'|'.(string) $this->config->get('smking.base_url')
        ), 0, 24);
    }

    private function supported(): bool
    {
        if ($this->config->get('smking.cache.enabled', true) !== true
            || $this->maxItems < 1
            || $this->maxItems > 100
            || $this->heartbeatSeconds < 30
            || $this->heartbeatSeconds > 900
            || ! method_exists($this->cache, 'getStore')
        ) {
            return false;
        }
        $store = $this->cache->getStore();

        return $store instanceof LockProvider
            && ($store instanceof FileStore || $store instanceof RedisStore);
    }

    private function locked(callable $operation): mixed
    {
        $lock = $this->cache->lock($this->key().':mutex', 5);
        if (! $lock->get()) {
            return null;
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
